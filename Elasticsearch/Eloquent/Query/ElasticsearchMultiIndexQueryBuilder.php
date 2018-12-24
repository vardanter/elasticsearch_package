<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 28/02/2018
 * Time: 23:46
 */

namespace App\Elasticsearch\Eloquent\Query;

use App\Elasticsearch\Eloquent\ElasticsearchEmptyModel;
use App\Elasticsearch\Eloquent\Interfaces\Builder;
use App\Elasticsearch\Eloquent\Model;
use App\Elasticsearch\Eloquent\Response\ElasticsearchSearchResponse;

/**
 * Class ElasticsearchMultiIndexQueryBuilder
 * @package App\Elasticsearch\Eloquent\Query
 */
class ElasticsearchMultiIndexQueryBuilder implements Builder
{
    /**
     * Can be
     *  msearch
     *  search
     */
    private $queryType;

    private $queries = [];

    private $current_num = 0;

    private $classes = [];

    private $current = null;

    private static $instance;

    /**
     * ElasticsearchMultiIndexQueryBuilder constructor.
     * @param string $queryType
     */
    public function __construct($queryType = 'msearch')
    {
        $this->queryType = $queryType != false ? $queryType : 'msearch';
        static::$instance = $this;
    }

    /**
     * @param bool $queryType
     * @return ElasticsearchMultiIndexQueryBuilder
     */
    private static function getInstance($queryType = false)
    {
        if (static::$instance == null) {
            static::$instance = new Static($queryType);
        }
        return static::$instance;
    }

    /**
     * @param $queryType
     * @return ElasticsearchMultiIndexQueryBuilder
     */
    public static function queryType($queryType)
    {
        $instance = static::getInstance($queryType);

        return $instance;
    }

    /**
     *
     * @example
     *
     *      $class = 'relations' => [
     *                    'videos'=>[
     *                        'relations' => [
     *                            'video' => Video::class
     *                        ]
     *                    ],
     *                    'channels' =>[
     *                        'relations' => [
     *                            'channel' => \App\Models\Channel::class,
     *                            'watch_count' => ElasticsearchEmptyModel::class
     *                        ]
     *                    ],
     *                ];
     *      OR
     *      $class = ElasticsearchEmptyModel::class;
     *      OR
     *      $class = ["channels" => \App\Models\Channel::class, "videos" => \App\Models\Video::class];
     *
     * @param mixed $index_type
     * @param string $class
     * @uses ElasticsearchQueryBuilder
     * @return ElasticsearchMultiIndexQueryBuilder | ElasticsearchQueryBuilder
     */
    public static function index($index_type, $class = ElasticsearchEmptyModel::class)
    {
        $instance = static::getInstance();

        $instance->addQuery();

        $instance->setCurrentNum($class);

        $index_type_query = is_array($index_type) ? $index_type : ['index' => static::setMultiIndexes($index_type)];

        $instance->queries[] = $index_type_query;
        $instance->current = new ElasticsearchQueryBuilder($class);

        return $instance;
    }

    /**
     * Get results in models
     * @return mixed
     */
    public function get()
    {
        $response = $this->getResponse();

        $objects = $response->parseResponse($this->classes);

        return $objects;
    }

    /**
     * Get elasticseearch query clean response
     * @return ElasticsearchSearchResponse
     */
    public function getResponse()
    {
        $this->addQuery();

        if ($this->queryType == 'msearch') {
            $params = [
                'body' => $this->queries
            ];
        } else {
            $params = [
                'index' => $this->queries[0],
                'body' => $this->queries[1]
            ];
        }

        $response = new ElasticsearchSearchResponse($params, $this->queryType);

        return $response;
    }

    /**
     * Set model class name for current query results injection
     * @param $class
     */
    private function setCurrentNum($class)
    {
        if (is_array($class)) {
            $this->classes = $class;
            $this->current_num = count($class);
        } else {
            $this->classes[$this->current_num] = $class;
            $this->current_num += 1;
        }
    }

    /**
     * ADD current query to queries array
     */
    private function addQuery()
    {
        if ($this->current !== null) {
            $this->queries[] = $this->current->getQuery();
        }
    }

    /**
     * Return current query
     * @return null
     */
    public function getCurrentQuery()
    {
        return $this->current;
    }

    /**
     * Helper function for setting indexes
     * @param $index_type
     * @return string
     */
    private static function setMultiIndexes($index_type)
    {
        if (strpos($index_type, ',') === false) {
            return $index_type;
        }
        $indexes = explode(',', $index_type);
        foreach ($indexes as &$index) {
            $index = Model::getPrefix() . $index;
        }
        $indexes = implode(',', $indexes);

        return $indexes;
    }

    /**
     * @param $name
     * @param $arguments
     * @return $this|bool|mixed
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            return call_user_func_array([$this, $name], $arguments);
        } elseif ($this->current != null) {
            $this->current = call_user_func_array([$this->current, $name], $arguments);
            return $this;
        }
        return false;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return @json_encode($this->queries);
    }
}