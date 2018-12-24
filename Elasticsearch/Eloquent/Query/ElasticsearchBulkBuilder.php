<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 09/03/2018
 * Time: 08:42
 */

namespace App\Elasticsearch\Eloquent\Query;
use App\Elasticsearch\Elasticsearch;
use App\Elasticsearch\Eloquent\Interfaces\Builder;

/**
 * Class ElasticsearchBulkBuilder
 * @package App\Elasticsearch\Eloquent\Query
 */
class ElasticsearchBulkBuilder implements Builder
{

    /**
     * full bulk query
     * @var array
     */
    private $query = [];

    /**
     * Current query type
     * @var null
     */
    private $current_type = null;

    /**
     * Current count
     * @var int
     */
    private $count = 0;

    /**
     * Default bulks count is 1000
     * @var int
     */
    private $bulkCount = 1000;

    /**
     * Bulk request responses
     * @var array
     */
    private $responses = [];

    /**
     * ElasticsearchBulkBuilder constructor.
     */
    public function __construct()
    {
    }

    /**
     * Add update type query
     * @param $params
     * @return ElasticsearchBulkBuilder
     */
    public function addUpdate($params)
    {
        if ($this->current_type == null) {
            $this->query['body'][$this->count] = ['update' => $params];
            $this->updateState('update');
        }
        return $this;
    }

    /**
     * Add create type query
     * @param $params
     * @return ElasticsearchBulkBuilder
     */
    public function addCreate($params)
    {
        if ($this->current_type == null) {
            $this->query['body'][$this->count] = ['create' => $params];
            $this->updateState('create');
        }
        return $this;
    }

    /**
     * Add delete type query
     * @param $params
     * @return ElasticsearchBulkBuilder
     */
    public function addDelete($params)
    {
        if ($this->current_type == null) {
            $this->query['body'][$this->count] = ['delete' => $params];
            $this->updateState(null);
        }
        return $this;
    }

    /**
     * Add index type query
     * @param $params
     * @return ElasticsearchBulkBuilder
     */
    public function addIndex($params)
    {
        if ($this->current_type == null) {
            $this->query['body'][$this->count] = ['index' => $params];
            $this->updateState('index');
        }
        return $this;
    }

    /**
     * Add document
     * @param $params
     * @return ElasticsearchBulkBuilder
     */
    public function addDoc($params)
    {
        if ($this->current_type != null) {
            if ($this->current_type == 'update') {
                $this->query['body'][$this->count] = ['doc' => $params];
            } elseif ($this->current_type != 'delete') {
                $this->query['body'][$this->count] = $params;
            }
            $this->updateState(null);
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        if ($this->count == 0 && empty($this->query)) {
            return true;
        }
        return false;
    }

    /**
     * Reset counters and query
     */
    public function reset()
    {
        $this->count = 0;
        $this->query = [];
    }

    /**
     * Set count to run bulk automatically
     * @param $count
     * @return ElasticsearchBulkBuilder
     */
    public function setBulkCount($count)
    {
        $this->bulkCount = $count;
        return $this;
    }

    /**
     * Run current query , reset and return last responses
     * @return array
     */
    public function runBulk()
    {
        if ($this->isEmpty()) {
            return $this->getResponses();
        }
        $this->responses = Elasticsearch::getConnection()->bulk($this->query);

        $this->reset();

        return $this->getResponses();
    }

    /**
     * Returns last responses
     * @return array
     */
    public function getResponses()
    {
        return $this->responses;
    }

    /**
     * Update current type ,counter and check if time to run bulk
     * @param $type
     */
    private function updateState($type)
    {
        $this->count++;
        $this->current_type = $type;
        $this->checkTime();
    }

    /**
     * If autoBulk count is set this will check it's time or not yet
     */
    private function checkTime()
    {
        if (is_numeric($this->bulkCount) && $this->count == $this->bulkCount) {
            $this->runBulk();
        }
    }
}