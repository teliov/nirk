<?php
/**
 * Created by PhpStorm.
 * User: teliov
 * Date: 8/2/16
 * Time: 1:02 PM
 */

namespace Nirk\Database;


use Illuminate\Database\Capsule\Manager;
use League\Event\EmitterInterface;
use Nirk\Contracts\Formatting\ArrayInterface;
use Nirk\Contracts\Formatting\JsonInterface;

class BaseModel implements JsonInterface, ArrayInterface, \JsonSerializable, \ArrayAccess
{
	/**
	 * @var EmitterInterface
	 */
	protected static $emitter;

	/**
	 * @var array
	 * columns in a row in the database
	 */
	protected $attributes = [];

	/**
	 * @var array
	 * a copy of the attributes when this class was first instantiated
	 */
	protected $original = [];

	/**
	 * @var array
	 * a list of attributes not to be exposed in Array or JSON outputs
	 */
	protected $protectedAttributes = [];

	/**
	 * @var bool
	 * boolean flag indicating whether the object has been persisted in the database or not
	 */
	protected $exists = false;

	/**
	 * @var string
	 * Database Tablename
	 */
	protected static $tableName;

	/**
	 * @var Manager
	 */
	protected static $manager;

	/**
	 * @var string | null
	 */
	protected static $primaryKey;

	/**
	 * @var bool
	 */
	protected $incrementing = true;

		/**
	 * @var string
	 */
	protected static $CREATED_AT = "created_at";

	/**
	 * @var string
	 */
	protected static $UPDATED_AT = "updated_at";

	/**
	 * @var int
	 * for collection, the limit of object per page
	 */
	protected static $limit = 50;

	/**
	 * @var int
	 * default page for collection
	 */
	protected static $page = 1;

	/**
	 * @var string
	 */
	protected static $DATE_FORMAT = \DateTime::ISO8601;

	public function __construct(array $attributes, $exists=true)
	{
		foreach ($attributes as $name => $value) {
			$this->setAttribute($name, $value);
		}

		$this->exists = $exists;

		$this->original = $this->attributes;
	}

	public function toArray()
	{
		$arr = [];
		foreach ($this->attributes as $key=>$value) {
			if (!in_array($key, $this->protectedAttributes)){
				$arr[$key] = $value;
			}
		}
		return $arr;
	}

	public function toJSON()
	{
		return json_encode($this->toArray());
	}

	public function getAttribute($name)
	{
		return isset($this->attributes[$name]) ? $this->attributes[$name] : null;
	}

	public function setAttribute($attr, $value=null)
	{
		if (!is_array($attr)) {
			if ($value === null) throw new \InvalidArgumentException("value parameter cannot be null");
			$attr = [$attr => $value];
		}

		foreach ($attr as $name => $value) {
			if ($this->hasTransformer($name)){
				$method = 'set'.studly_case($name)."Attribute";
				$this->{$method}($value);
			} else {
				$this->attributes[$name] = $value;
			}
		}
	}

	public function getMutatedAttributes()
	{
		$mutated = [];

		foreach ($this->attributes as $item => $value) {
			if (!isset($this->original[$item]) || $this->original[$item] !== $value) {
				$mutated[$item] = $value;
			}
		}

		return $mutated;
	}

	/**
	 * @param $attributes
	 * @return static
	 * @throws \Exception
	 */
	public static function create($attributes)
	{
		$instance = new static($attributes);
		$instance->save();
		return $instance;
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function save()
	{
		if (!$this->exists) {
			if (static::$CREATED_AT) {
				$this->attributes[static::$CREATED_AT] = (new \DateTime())->format(static::$DATE_FORMAT);
			}

			if ($this->incrementing && static::$primaryKey) {
				$this->attributes[static::$primaryKey] = static::$manager->table(static::$tableName)
					->insertGetId($this->attributes);
			} else {
				static::$manager->table(static::$tableName)->insert($this->attributes);
			}

			$this->exists = true;

			static::trigger("created", $this);
		} else {
			if (empty($mutated = $this->getMutatedAttributes())) return true;

			if (static::$UPDATED_AT) {
				$this->attributes[static::$UPDATED_AT] = (new \DateTime())->format(static::$DATE_FORMAT);
				$mutated[static::$UPDATED_AT] = $this->{static::$UPDATED_AT};
			}

			if (!static::$primaryKey) {
				throw new \Exception("A Model without a primary key cannot be updated. Implement the save method to handle this");
			}

			static::$manager->table(static::$tableName)->where(static::$primaryKey, "=", $this->{static::$primaryKey})
				->update($mutated);

			static::trigger("updated", $this);
		}

		$this->original = $this->attributes;
		return true;
	}

	/**
	 * @param array $attributes
	 * @return bool
	 * @throws \Exception
	 */
	public function update(array $attributes)
	{
		$this->attributes = array_merge($this->attributes, $attributes);
		return $this->save();
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function delete()
	{
		if (!static::$primaryKey) {
			throw new \Exception("A Model without a primary key cannot be updated. Implement the save method to handle this");
		}

		static::$manager->table(static::$tableName)
			->where(static::$primaryKey, "=", $this->{static::$primaryKey})
			->delete();

		$this->exists = false;
		static::trigger("deleted", $this);
		return true;
	}

	/**
	 * @return string
	 */
	public static function getTableName()
	{
		return static::$tableName;
	}

	/**
	 * @return null|string
	 */
	public static function getPrimaryKey()
	{
		return static::$primaryKey;
	}

	/**
	 * @return Manager
	 */
	public static function getDatabaseManager()
	{
		return static::$manager;
	}

	public static function setDatabaseManager(Manager $manager)
	{
		static::$manager = $manager;
	}

	/**
	 * @param $name
	 * @return bool
	 */
	public function hasTransformer($name)
	{
		return method_exists($this, 'set'.studly_case($name).'Attribute');
	}

	/**
	 * @return array
	 */
	public function jsonSerialize()
	{
		return $this->toArray();
	}

	/**
	 * @param $eventName
	 * @param $arguments
	 * @return bool|\League\Event\EventInterface
	 */
	public static function trigger($eventName, $arguments)
	{
		if (!static::$emitter) return null;

		$className = explode('\\', static::class);
		$className = strtolower($className[count($className) -1]);
		$eventName = $className.".".$eventName;

		if (!is_array($arguments)) $arguments = [$arguments];
		static::$emitter->emit($eventName, $arguments);
	}

	/**
	 * @param mixed $offset
	 * @return bool
	 */
	public function offsetExists($offset)
	{
		$parts = explode(".", $offset);
		if (count($parts) === 1) return isset($this->attributes[$offset]);

		$obj = $this->attributes;

		foreach ($parts as $part) {
			if (!isset($obj[$part])) return false;

			$obj = $obj[$part];
		}

		return true;
	}

	/**
	 * @param mixed $offset
	 * @return mixed
	 *
	 * Allows for getting a value from the model like so $model[offset]
	 * and also for nested variables = $model[offset.nested]
	 */
	public function offsetGet($offset)
	{
		$parts = explode(".", $offset);
		if (count($parts) === 1) return $this->__get($offset);

		$obj = $this->attributes;

		foreach ($parts as $part) {
			$obj = isset($obj[$part]) ? $obj[$part] : null;

			if ($obj == null) return $obj;
		}

		return $obj;
	}

	/**
	 * @param mixed $offset
	 * @param mixed $value
	 *
	 * Allows setting a value on the model like so $model[offset] = $value
	 * for nested attributes you can do $model[offset.nested] = $value
	 */
	public function offsetSet($offset, $value)
	{
		$parts = explode(".", $offset);
		if (count($parts) === 1) $this->setAttribute($offset, $value);
		$obj = &$this->attributes;

		foreach ($parts as $part) {
			if (!isset($obj[$part])) {
				$obj[$part] = "";
			}
			$obj = &$obj[$part];
		}
		$obj = $value;
	}

	/**
	 * @param mixed $offset
	 * @throws \Exception
	 */
	public function offsetUnset($offset)
	{
		throw new \Exception("Unimplemented Method");
	}

	/**
	 * @return string
	 */
	public static function getCalledClass()
	{
		return get_called_class();
	}

	/**
	 * @return EmitterInterface
	 */
	public static function getEmitter()
	{
		return static::$emitter;
	}

	/**
	 * @param EmitterInterface $emitter
	 * Set the Emitter for the model
	 */
	public static function setEmitter(EmitterInterface $emitter)
	{
		static::$emitter = $emitter;
	}
}
