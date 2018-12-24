<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 28/02/2018
 * Time: 23:47
 */

namespace App\Elasticsearch\Eloquent\Response;

use App\Elasticsearch\Elasticsearch;
use App\Elasticsearch\Eloquent\ElasticsearchEmptyModel;
use Illuminate\Support\Collection;

/**
 * Class ElasticsearchResponse
 * @package App\Elasticsearch\Eloquent\Response
 */
class ElasticsearchSearchResponse
{
    private $method;

    private $params;

    private $response = [];

    /**
     * ElasticsearchSearchResponse constructor.
     * @param $params
     * @param string $method
     */
    public function __construct($params, $method = 'search')
    {
        if (!empty($params)) {
            $this->method = $method;
            $this->params = $params;
            $this->response = Elasticsearch::getConnection()->$method($params);
        }
    }

    /**
     * @param $response
     * @return static
     */
    public static function setResponse($response)
    {
        $response = new Static();
        $response->response = $response;

        return $response;
    }

    /**
     * @return array
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param $class
     * @return Collection
     */
    public function parseResponse($class)
    {
        $this->log();
        if ($this->method == 'msearch' && isset($this->response['responses'])) {
            return Collection::make($this->parseMultiResponses($class));
        } else {
            return Collection::make($this->parseOneResponse($this->response, $class));
        }
    }

    /**
     * @param $classes
     * @return array
     */
    private function parseMultiResponses($classes)
    {
        $models = [];
        $count = 0;

        foreach ($this->response['responses'] as $response) {
            if (empty($response['hits']['hits'])) {
                $count++;
                continue;
            }
            $one_result = $this->parseOneResponse($response, $classes[$count]);
            $models = array_merge($one_result, $models);
            $count++;
        }

        return $models;
    }

    /**
     * @param $response
     * @param $class
     * @return array
     */
    private function parseOneResponse($response, $class)
    {
        if (empty($response['hits']['hits']))
            return [];

        $models = [];
        $hits = $response['hits']['hits'];

        foreach ($hits as $hit) {
            $model = $this->getClassByType($hit, $class);
            $model->is_new_record = false;
            $model->setAttributes($hit['_source']);
            $model->setPrimaryKey($hit['_id']);
            $model->setScore($hit['_score']);
            if (isset($hit['inner_hits'])) {
                $model->setInnerHits($hit['inner_hits']);
            }
            if (method_exists($model, 'setIndexName')) {
                $model->setIndexName($hit['_index']);
            }
            if (method_exists($model, 'setTypeName')) {
                $model->setTypeName($hit['_type']);
            }
            $models[] = $model;
        }

        return $models;
    }

    /**
     * @param $hit
     * @param $class
     * @return ElasticsearchEmptyModel
     */
    private function getClassByType($hit, $class)
    {
        if (is_array($class)) {
            if (isset($class['relations'])) {
                $key = !empty(config('elasticsearch.prefix')) ? str_replace(config('elasticsearch.prefix'), '', $hit['_index']) : $hit['_index'];
                $relations_key_class = $class['relations'][$key];
                $relation_field_name = array_keys($relations_key_class)[0];

                if (isset($hit['_source'][$relation_field_name])) {
                    foreach ($relations_key_class[$relation_field_name] as $rel => $class) {
                        if (isset($hit['_source'][$relation_field_name]['name']) && $hit['_source'][$relation_field_name]['name'] == $rel) {
                            return new $class;
                        }
                        if ($hit['_source'][$relation_field_name] == $rel) {
                            return new $class;
                        }
                    }
                }

                return new ElasticsearchEmptyModel();
            }

            $key = !empty(config('elasticsearch.prefix')) ? str_replace(config('elasticsearch.prefix'), '', $hit['_index']) : $hit['_index'];
            return isset($class[$key]) ? new $class[$key]() : new ElasticsearchEmptyModel();
        } else {
            return new $class();
        }
    }

    /**
     * Log query and took time
     */
    private function log()
    {
        $log_path  = config('elasticsearch.log_file');
        $query     = !empty($this->params) ? $this->params : 'empty';
        $time      = isset($this->response['took']) ? $this->response['took'] : null;

        if (!empty($time) && !empty($log_path)) {
            $log_file_name = $log_path . date('Y-m-d', time()) . '.txt';

            $log_file = fopen($log_file_name, 'a+');
            $log_string = 'TIME ' . date('Y-m-d H:i:s', time()) . PHP_EOL;
            $log_string .= 'Took : ' . $time . PHP_EOL;
            $log_string .= 'Query : ' . (is_array($query) ? json_encode($query) : $query) . PHP_EOL;
            $log_string .= '_______________________________________________________________________' . PHP_EOL;

            fwrite($log_file, $log_string);
            fclose($log_file);
        }
    }
}