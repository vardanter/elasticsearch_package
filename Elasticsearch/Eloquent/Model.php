<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 27/02/2018
 * Time: 16:41
 */

namespace App\Elasticsearch\Eloquent;

use App\Elasticsearch\Elasticsearch;
use App\Elasticsearch\Eloquent\Interfaces\ElasticsearchDataType;
use App\Elasticsearch\Eloquent\Interfaces\WithRelation;
use App\Elasticsearch\Eloquent\Interfaces\WithRouting;
use App\Elasticsearch\Eloquent\Query\ElasticsearchQueryBuilder;
use App\Elasticsearch\Eloquent\Response\ElasticsearchSearchResponse;
use App\Elasticsearch\Eloquent\Traits\TElasticsearchModelMultiSelectConnector;
use Illuminate\Support\Collection;

/**
 * Class Model
 * @package App\Elasticsearch\Eloquent
 */
abstract class Model implements \JsonSerializable, \ArrayAccess
{
    use TElasticsearchModelMultiSelectConnector;

    public $_id = '';

    protected $_score = null;

    protected $fillable = [];

    protected $attributes = [];

    protected $extra = [];

    protected $old_attriubtes = [];

    protected static $primary_key = '_id';

    public $is_new_record = true;

    private $query = null;

    /**
     * Model constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->setAttributes($attributes);
        $this->boot();
    }

    /**
     * Boot object, after construct
     */
    private function boot()
    {
        if ($this instanceof ElasticsearchDataType) {
            $this->data_type = $this->getDataType();
        }
        return;
    }

    /**
     * Called before save function
     * @return mixed
     */
    public function beforeSave()
    {
        return true;
    }

    /**
     * Called after save function
     * @return mixed
     */
    public function afterSave()
    {
        return true;
    }

    /**
     * Create if not exists else update
     * @return bool
     */
    public function save()
    {
        if (!$this->beforeSave()) {
            return false;
        }

        $params = [
            'index' => static::getPrefix() . $this->index(),
            'type' => $this->type(),
            'id' => $this->getPrimaryKey(),
            'body' => $this->getAttributes()
        ];

        $this->addExtraFields($params);

        $response = Elasticsearch::getConnection()->index($params);

        if (!isset($response['result']) || ($response['result'] != 'updated' && $response['result'] != 'created')) {
            return false;
        }

        if ($this->afterSave()) {
            return true;
        }
        return false;
    }

    /**
     * Add Implemented fields
     * @param $params
     */
    private function addExtraFields(&$params)
    {
        if ($this instanceof WithRelation) {
            $params['body'][$this->getRelationKey()] = $this->getRelation();
        }
        if ($this instanceof WithRouting) {
            $params['routing'] = $this->routing();
        }
    }

    /**
     * Delete doc without model
     * @param $id
     * @return bool
     */
    public static function destroy($id)
    {
        $model = new Static();
        $model->setPrimaryKey($id);
        return $model->delete();
    }

    /**
     * Delete model
     * @return bool
     */
    public function delete()
    {
        $params = [
            'index' => static::getPrefix() . $this->index(),
            'type' => $this->type()
        ];

        $params['id'] = $this->getPrimaryKey();

        $response = Elasticsearch::getConnection()->delete($params);

        if (isset($response['result']) && $response['result'] == 'deleted') {
            return true;
        }
        return false;
    }

    /**
     * @return ElasticsearchQueryBuilder
     */
    public static function createQueryBuilder()
    {
        return new ElasticsearchQueryBuilder(static::class);
    }

    /**
     * @param $model
     * @param $query
     * @return mixed
     */
    public static function setRelations($model, $query)
    {
        if (method_exists($model, 'getRelationName')) {
            return $query->setRelations($model->getRelationKey(), $model->getRelationName());
        }
        return $query;
    }

    /**
     * Find all records where id in list $ids
     * @param $ids
     * @return Collection
     */
    public static function findAll($ids)
    {
        if (empty($ids)) {
            return Collection::make([]);
        }
        try {
            $results = static::createQueryBuilder()->size(count($ids))->whereIn('id', $ids)->all();
        } catch (\Exception $e) {
            $results = Collection::make([]);
        }

        return $results;
    }

    /**
     * @param bool $id
     * @return ElasticsearchQueryBuilder|static
     */
    public static function find($id = false)
    {
        $query = static::createQueryBuilder();
        if ($id) {
            return $query->where(static::$primary_key, $id)->first();
        }

        return $query;
    }

    /**
     * Find and return model if exists or create new Model
     * @param $id
     * @return ElasticsearchQueryBuilder|static
     */
    public static function findOrNew($id)
    {
        $result = static::find($id);

        return empty($result) ? new static() : $result;
    }

    /**
     * Get results count
     * @param bool $query
     * @return int
     */
    public static function count($query = false)
    {
        $model = new Static();

        if (!$query) {
            $query = static::createQueryBuilder();
        }

        $query = static::setRelations($model, $query);

        $params = [
            'index' => static::getPrefix() . $model->index(),
            'type' => $model->type(),
            'body' => $query->getCountQuery()
        ];

        $response = Elasticsearch::getConnection()->count($params);

        return isset($response['count']) ? $response['count'] : 0;
    }

    /**
     * FOR LARAVELISTS
     * @param $query
     * @return Model|mixed
     */
    public static function all($query = false)
    {
        if (!$query) {
            $query = static::createQueryBuilder();
            try {
                $query->size(ElasticsearchQueryBuilder::MAX_SIZE);
            } catch (\Exception $e) {
                //Just ignore
            }
        }
        return static::get($query);
    }

    /**
     * @param $query
     * @return Model|mixed
     */
    public static function get($query = false)
    {
        $model = new Static();

        if (!$query) {
            $query = static::createQueryBuilder();
        }

        $query = static::setRelations($model, $query);

        $params = [
            'index' => static::getPrefix() . $model->index(),
            'type' => $model->type(),
            'body' => $query->getQuery()
        ];

        $response = new ElasticsearchSearchResponse($params);
        $models = $response->parseResponse(static::class);

        return $models;
    }

    /**
     * FOR LARAVELISTS
     * @param bool $query
     * @return Model|mixed
     * @throws \Exception
     */
    public static function first($query = false)
    {
        $model = new Static();

        if ($query == false) {
            $query = static::createQueryBuilder();
        }

        $query->limit(1);
        $query = static::setRelations($model, $query);

        $params = [
            'index' => static::getPrefix() . $model->index(),
            'type' => $model->type(),
            'body' => $query->getQuery()
        ];

        $response = new ElasticsearchSearchResponse($params);
        $model = $response->parseResponse(static::class);

        return count($model) > 0 ? $model[0] : null;
    }

    /**
     * Get unique key for model
     * @return mixed|null
     */
    public function getPrimaryKey()
    {
        if (!$this->_id) {
            $this->_id = uniqid();
        }
        return $this->_id;
    }

    /**
     * Set field _id unique for elasticsearch
     * @param $id
     */
    public function setPrimaryKey($id)
    {
        $this->_id = $id;
    }

    /**
     * Get hit _score
     * @return null
     */
    public function getScore()
    {
        return $this->_score;
    }

    /**
     * Set hit _score
     * @param $score
     */
    public function setScore($score)
    {
        $this->_score = $score;
    }

    /**
     * Get Attribute by name
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Get all attributes
     * @param $name
     * @return mixed|null
     */
    public function getAttribute($name)
    {
        if (isset($this->attributes[$name])) {
            return $this->attributes[$name];
        } elseif (isset($this->extra[$name])) {
            return $this->extra[$name];
        }
        return null;
    }

    /**
     * Set old attributes after find
     * @param array $attributes
     */
    private function setOldAttributes(array $attributes = [])
    {
        $this->is_new_record = false;
        foreach ($attributes as $field => $value) {
            $this->setOldAttribute($field, $value);
        }
    }

    /**
     * Set old attribute after find
     * @param $field
     * @param $value
     */
    private function setOldAttribute($field, $value)
    {
        if (in_array($field, $this->fillable)) {
            $this->old_attriubtes[$field] = $value;
        }
    }

    /**
     * FOR LARAVELISTS
     * @param array $attributes
     */
    public function fill(array $attributes = [])
    {
        $this->setAttributes($attributes);
    }

    /**
     * Set multiple Attributes
     * @param array $attributes
     */
    public function setAttributes(array $attributes = [])
    {
        foreach ($attributes as $field => $value) {
            $this->setAttribute($field, $value);
        }
    }

    /**
     * Set Attribute by name if attribute isset in $fillable array
     * @param $field
     * @param $value
     */
    public function setAttribute($field, $value)
    {
        if (in_array($field, $this->fillable)) {
            $this->attributes[$field] = $value;
        } else {
            $this->extra[$field] = $value;
        }
    }

    /**
     * Set inner hits after has_child inner_hits query
     * @param $inner_hits
     */
    public function setInnerHits($inner_hits)
    {
        foreach ($inner_hits as $key => $inner_hit) {
            $nkey = 'child_' . $key;
            if (!empty($inner_hit['hits']['hits'])) {
                foreach ($inner_hit['hits']['hits'] as $hit) {
                    $this->$nkey = new ElasticsearchEmptyModel($hit['_source']);
                }
            }
        }
    }

    /**
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        if (property_exists($this, $name)) {
            $this->$name = $value;
        } else {
            $this->setAttribute($name, $value);
        }
    }

    /**
     * @param $name
     * @return mixed|null
     */
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        } else {
            return $this->getAttribute($name);
        }
    }

    /**
     * @param $name
     * @return bool
     */
    public function __isset($name)
    {
        if (property_exists($this, $name)) {
            return isset($this->$name);
        }
        return isset($this->attributes[$name]) || isset($this->extra[$name]);
    }

    /**
     * DELEGATE METHODS TO ElasticsearchQueryBuilder class
     */

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    private function runQueryCommand($name, $arguments)
    {
        if (!($this->query instanceof ElasticsearchQueryBuilder)) {
            $this->query = static::createQueryBuilder();
        }

        return $this->query->$name($arguments);
    }

    /**
     * Get prfix for indexes
     * @return mixed|string
     */
    public static function getPrefix()
    {
        return config('elasticsearch.prefix') ? config('elasticsearch.prefix') : '';
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            return call_user_func_array([$this, $name], $arguments);
        } else {
            return $this->runQueryCommand($name, $arguments);
        }
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        $instance = new static;
        if (method_exists($instance, $name)) {
            return call_user_func_array([$instance, $name], $arguments);
        } else {
            $query = static::createQueryBuilder();
            return call_user_func_array([$query, $name], $arguments);
        }
    }

    /**
     * Serialize this object
     * @return array|mixed
     */
    public function jsonSerialize()
    {
        return array_merge($this->getAttributes(), $this->extra);
    }

    /**
     * Model index name
     * @return string
     */
    abstract public function index();

    /**
     * Model type name
     * @return mixed
     */
    abstract public function type();

    /**
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists($offset)
    {
        return isset($this->$offset);
    }

    /**
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        return $this->$offset;
    }

    /**
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        return;
    }
}