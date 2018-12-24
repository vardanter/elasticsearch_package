<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 27/02/2018
 * Time: 17:30
 */

namespace App\Elasticsearch\Eloquent\Query;

use App\Elasticsearch\Elasticsearch;
use App\Elasticsearch\Eloquent\Interfaces\Builder;
use App\Elasticsearch\Eloquent\Model;

/**
 * Class ElasticsearchQueryBuilder
 * @package App\Elasticsearch\Eloquent\Query
 */
class ElasticsearchQueryBuilder implements Builder
{
    /**
     * MAXIMUM LIMIT FOR QUERY
     */
    const MAX_SIZE = 10000;

    /**
     * Parent model
     * @var null
     */
    private $parent = null;

    /**
     * Current query position in Query array
     * @var null
     */
    private $query_position = null;

    /**
     * @var array
     */
    private $envs_qeue = [];

    /**
     * @var array
     */
    private $query_positions_qeue = [];

    /**
     * @var string
     */
    private $current_env = 'query|bool';

    /**
     * @var string
     */
    private $default_env = 'query|bool';

    /**
     * default query pattern
     * @var array
     */
    private static $query_pattern = [
        'from' => 0,
        'size' => 18,
        'sort' => [],
        '_source' => ['*'],
        'query' => [
            'bool' => [
                'must' => [],
                'must_not' => [],
                'should' => [],
                'filter' => [],
                'minimum_should_match' => 0
            ]
        ]
    ];

    /**
     * Main query
     * @var array
     */
    protected $query = [];

    /**
     * ElasticsearchQueryBuilder constructor.
     * @param null $parent
     */
    public function __construct($parent = null)
    {
        $this->parent = $parent;
    }

    /**
     * @return Elasticsearch
     */
    public static function query($query)
    {
        return Elasticsearch::getConnection()->search($query);
    }

    /**
     * @return array
     */
    public static function getQueryPattern()
    {
        return static::$query_pattern;
    }

    /**
     * @return array
     */
    public static function getBoolPattern()
    {
        return [
            'bool' => [
                'must' => [],
                'should' => [],
                'must_not' => [],
                'filter' => [],
                'minimum_should_match' => 0
            ]
        ];
    }

    /**
     * @param $parent_type
     * @param $function
     * @param $score_mode
     * @return array
     */
    public static function getHasParentPattern($parent_type, $function, $score_mode)
    {
        $query = [
            'has_parent' => [
                'parent_type' => $parent_type,
                'score' => $score_mode,
                'query' => [

                ]
            ]
        ];

        if ($function != false) {
            $query['has_parent']['query'] = [
                "function_score" => [
                    "script_score" => [
                        "script" => $function
                    ]
                ]
            ];
        } else {
            $query['has_parent']['query'] = static::getBoolPattern();
        }

        return $query;
    }

    /**
     * @param $child_type
     * @param $inner_hits
     * @param $function
     * @param $score_mode
     * @param $min
     * @param $max
     * @return array
     */
    public static function getHasChildPattern($child_type, $inner_hits, $function, $score_mode, $min, $max)
    {
        $query = [
            'has_child' => [
                'type' => $child_type,
                'query' => [

                ]
            ]
        ];
        if ($score_mode) {
            $query['has_child']['score_mode'] = $score_mode;
        }
        if ($min) {
            $query['has_child']['min_children'] = $min;
        }
        if ($max) {
            $query['has_child']['max_children'] = $max;
        }
        if ($inner_hits !== false) {
            $query['has_child']['inner_hits'] = empty($inner_hits) ? ['_source'=> ['*']] : ['_source' => $inner_hits];
        }

        if ($function != false) {
            $query['has_child']['query'] = [
                "function_score" => [
                    "script_score" => [
                        "script" => $function
                    ]
                ]
            ];
        } else {
            $query['has_child']['query'] = static::getBoolPattern();
        }

        return $query;
    }

    /**
     * Set default query Template
     */
    private function setQuery()
    {
        if ($this->query == null) {
            $this->query = static::getQueryPattern();
        }
    }

    /**
     * @param $to
     * @param $query
     * @param bool $inject_into
     */
    private function injectQuery(&$to, $query, $inject_into = false)
    {
        $current_env = explode('|', $this->current_env);

        foreach ($current_env as $env) {
            $to = &$to[$env];
        }

        if ($inject_into != false) {
            if (is_array($to[$inject_into])) {
                $to[$inject_into][] = $query;
            } else {
                $to[$inject_into] = $query;
            }
            $count = is_array($to[$inject_into]) || is_object($to[$inject_into]) ? count($to[$inject_into]) : 0;
            $this->setQueryPosition($query, $count - 1);
        } else {
            $to[] = $query;
        }
    }

    /**
     * QUERIES wich change query's env
     * @param $query
     * @param $number
     */
    private function setQueryPosition($query, $number)
    {
        if (is_array($query) && (array_key_exists('bool', $query) || array_key_exists('has_parent', $query) || array_key_exists('has_child', $query))) {
            $this->query_position = $number;
            $this->query_positions_qeue[] = $this->query_position;
        }
    }

    /**
     * @param $query
     * @param bool $inject_into
     * @param $to
     */
    private function addQuery($query, $inject_into = false, &$to)
    {
        $this->injectQuery($to, $query, $inject_into);
    }

    /**
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function addBool($inject_into = 'must')
    {
        $this->setQuery();

        $bool_query = static::getBoolPattern();

        $this->addQuery($bool_query, $inject_into, $this->query);
        if ($this->current_env == $this->default_env) {
            $this->current_env = 'query|bool|' . $inject_into . '|' . $this->query_position . '|bool';
        } else {
            $this->current_env = $this->current_env . '|' . $inject_into . '|' . $this->query_position . '|bool';
        }

        $this->envs_qeue[] = $this->current_env;

        return $this;
    }

    /**
     * Pointer back
     * @param bool $steps
     * @return ElasticsearchQueryBuilder
     */
    public function eq($steps = false)
    {
        if ($steps == false) {
            $this->current_env = $this->default_env;
            $this->query_position = null;
        } else {
            $qeue_count = count($this->envs_qeue) - 1;
            if ($qeue_count == 0) {
                return $this->eq();
            }
            $this->current_env = $this->envs_qeue[$qeue_count - 1];
            $this->query_position = $this->query_positions_qeue[$qeue_count - 1];
            array_splice($this->query_positions_qeue, -1);
            array_splice($this->envs_qeue, -1);
        }
        return $this;
    }

    /**
     * @param $parent_type
     * @param bool $function
     * @param bool $score_mode
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function hasParent($parent_type, $function = false, $score_mode = false, $inject_into = 'must')
    {
        $this->setQuery();

        $has_parent_query = static::getHasParentPattern($parent_type, $function, $score_mode);
        $this->addQuery($has_parent_query, $inject_into, $this->query);
        if ($this->current_env == $this->default_env) {
            $this->current_env = 'query|bool|' . $inject_into . '|' . $this->query_position . '|has_parent|query';
        } else {
            $this->current_env = $this->current_env . '|' . $inject_into . '|' . $this->query_position . '|has_parent|query';
        }

        if (!$function) {
            $this->current_env .= '|bool';
        } else {
            $this->eq();
        }

        $this->envs_qeue[] = $this->current_env;

        return $this;
    }

    /**
     * @param $child_type
     * @param bool $inner_hits
     * @param bool $function
     * @param bool $score_mode
     * @param bool $min
     * @param bool $max
     * @param string $inject_into
     * @return $this
     */
    public function hasChild($child_type, $inner_hits = false, $function = false, $score_mode = false, $min = false, $max = false, $inject_into = 'must')
    {
        $this->setQuery();

        $has_parent_query = static::getHasChildPattern($child_type, $inner_hits, $function, $score_mode, $min, $max);

        $this->addQuery($has_parent_query, $inject_into, $this->query);
        if ($this->current_env == $this->default_env) {
            $this->current_env = 'query|bool|' . $inject_into . '|' . $this->query_position . '|has_child|query';
        } else {
            $this->current_env = $this->current_env . '|' . $inject_into . '|' . $this->query_position . '|has_child|query';
        }

        if (!$function) {
            $this->current_env .= '|bool';
        } else {
            $this->eq();
        }

        $this->envs_qeue[] = $this->current_env;

        return $this;
    }

    /**
     * @param $field
     * @param string $type
     * @param $value
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function range($field, $type = 'gte', $value, $inject_into = 'must')
    {
        $query = [
            'range' => [
                $field => [
                    $type => $value
                ]
            ]
        ];

        $this->addQuery($query, $inject_into, $this->query);

        return $this;
    }

    /**
     * @param $fields
     * @return ElasticsearchQueryBuilder
     */
    public function select($fields)
    {
        if ($fields === true) {
            $fields = true;
        } else {
            $fields = is_array($fields) ? $fields : [$fields];
        }

        $this->query['_source'] = $fields;

        return $this;
    }

    /**
     * FOR LARAVELISTS
     * @param $fields
     * @return ElasticsearchQueryBuilder
     */
    public function addSelect($fields)
    {
        return $this->select(array_merge(is_array($fields) ? $fields : [$fields], $this->query['_source']));
    }

    /**
     * Add Script query
     * @param $script
     * @param string $lang
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function script($script, $lang = 'painless', $inject_into = 'must')
    {
        $query = [
            'script' => [
                'script' => [
                    'source' => $script,
                    'lang' => $lang
                ]
            ]
        ];

        $this->addQuery($query, $inject_into, $this->query);

        return $this;
    }

    /**
     * @param $fields
     * @param $query
     * @param bool $type
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function multiMatch($query, $fields, $type = false, $inject_into = 'must')
    {
        $query = [
            [
                'multi_match' => [
                    'query'     => $query,
                    'fields'    => $fields
                ]
            ]
        ];

        if ($type !== false) {
            $query['multi_match']['type'] = $type;
        }

        $this->addQuery($query, $inject_into, $this->query);

        return $this;
    }

    /**
     * @param $script
     * @param array $params
     * @param string $lang
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function functionScore($script, $params = [], $lang = 'painless', $inject_into = 'must')
    {
        $query = [
            'function_score' => [
                'script_score' => [
                    'script' => [
                        'source' => $script,
                        'lang'   => $lang
                    ]
                ]
            ]
        ];

        if (!empty($params)) {
            $query['function_score']['script_score']['script']['params'] = $params;
        }


        $this->addQuery($query, $inject_into, $this->query);

        return $this;
    }

    /**
     * @param $field
     * @param $value
     * @param string $query_type
     * @param string $inject_into
     * @param bool|array $extra
     * @return ElasticsearchQueryBuilder
     */
    public function where($field, $value, $query_type = 'term', $inject_into = 'must', $extra = false)
    {
        $this->setQuery();

        if ($value == null && ($query_type == 'exists' || $query_type == 'missing')) {
            $query = [
                $query_type => [
                    'field' => $field
                ]
            ];
        } elseif ($extra) {
            $query = [
                $query_type => [
                    $field => [
                        'query' => $value,
                        array_keys($extra)[0] => array_values($extra)[0]
                    ]
                ]
            ];
        } else {
            $query = [
                $query_type => [
                    $field => $value
                ]
            ];
        }

        $this->addQuery($query, $inject_into, $this->query);

        return $this;
    }

    /**
     * @param $field
     * @param $value
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function whereIn($field, $value, $inject_into = 'must')
    {
        return $this->where($field, $value, 'terms', $inject_into);
    }

    /**
     * @param $field
     * @param $value
     * @return ElasticsearchQueryBuilder
     */
    public function whereNotIn($field, $value)
    {
        return $this->where($field, $value, 'terms', 'must_not');
    }

    /**
     * @param $field
     * @param $value
     * @param string $query_type
     * @param bool $extra
     * @return ElasticsearchQueryBuilder
     */
    public function filter($field, $value, $query_type = 'term', $extra = false)
    {
        return $this->where($field, $value, $query_type, 'filter', $extra);
    }

    /**
     * @param $field
     * @param $value
     * @param string $query_type
     * @param bool $extra
     * @return ElasticsearchQueryBuilder
     */
    public function should($field, $value, $query_type = 'term', $extra = false)
    {
        return $this->where($field, $value, $query_type, 'should', $extra);
    }

    /**
     * @param $field
     * @param $value
     * @param string $query_type
     * @param bool $extra
     * @return ElasticsearchQueryBuilder
     */
    public function must($field, $value, $query_type = 'term', $extra = false)
    {
        return $this->where($field, $value, $query_type, 'must', $extra);
    }

    /**
     * @param $field
     * @param $value
     * @param string $query_type
     * @param bool $extra
     * @return ElasticsearchQueryBuilder
     */
    public function must_not($field, $value, $query_type = 'term', $extra = false)
    {
        return $this->where($field, $value, $query_type, 'must_not', $extra);
    }

    /**
     * Minimum count for matching in current query block
     * @param $count
     * @return $this
     */
    public function shouldMinimumMatch($count)
    {
        $this->setQuery();

        $this->addQuery($count, 'minimum_should_match', $this->query);

        return $this;
    }

    /**
     * Field exists
     * @param $field
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function exists($field, $inject_into = 'must')
    {
        return $this->where($field, null, 'exists', $inject_into);
    }

    /**
     * Field not exists or is null
     * @param $field
     * @param string $inject_into
     * @return ElasticsearchQueryBuilder
     */
    public function missing($field, $inject_into = 'must')
    {
        $this->addBool($inject_into);
        $this->exists($field, 'must_not');
        $this->eq(true);

        return $this;
    }

    /**
     * FOR LARAVELISTS
     * @param $field
     * @param string $type
     * @param bool $mode
     * @return ElasticsearchQueryBuilder
     */
    public function orderBy($field, $type = 'DESC', $mode = false)
    {
        return $this->sort($field, $type, $mode);
    }

    /**
     * Set sorting
     * @param $field
     * @param string $type
     * @param bool $mode
     * @return ElasticsearchQueryBuilder
     */
    public function sort($field, $type = 'DESC', $mode = false)
    {
        $this->setQuery();

        $sort = [
            $field => [
                'order' => $type
            ]
        ];
        if ($mode) {
            $sort[$field]['mode'] = $mode;
        }
        $this->query['sort'][] = $sort;

        return $this;
    }

    /**
     * Search after query for getting big datas
     * @param $values
     * @return $this
     */
    public function searchAfter($values)
    {
        $this->query['search_after'] = is_array($values) ? $values : [$values];

        return $this;
    }

    /**
     * Set limit
     * @param $count
     * @return ElasticsearchQueryBuilder
     * @throws \Exception
     */
    public function size($count)
    {
        if ($count > static::MAX_SIZE) {
            throw new \Exception('Maximum limit for query is ' . static::MAX_SIZE);
        }

        $this->setQuery();

        $this->query['size'] = $count >= 10000 ? 10000 : $count;

        return $this;
    }

    /**
     * Set limit
     * @param $count
     * @return ElasticsearchQueryBuilder
     * @throws \Exception
     */
    public function limit($count)
    {
        return $this->size($count);
    }

    /**
     * FOR LARAVELISTS
     * Set limit
     * @param $count
     * @return ElasticsearchQueryBuilder
     * @throws \Exception
     */
    public function take($count)
    {
        return $this->size($count);
    }

    /**
     * Set from
     * @param $count
     * @return ElasticsearchQueryBuilder
     */
    public function offset($count)
    {
        if ($this->query == []) {
            $this->query = static::$query_pattern;
        }
        $this->query['from'] = $count;

        return $this;
    }

    /**
     * Set from
     * @param $count
     * @return ElasticsearchQueryBuilder
     */
    public function skip($count)
    {
        return $this->offset($count);
    }

    /**
     * Set from
     * @param $count
     * @return ElasticsearchQueryBuilder
     */
    public function from($count)
    {
        return $this->offset($count);
    }

    /**
     * @param $relation_key
     * @param $relation_name
     * @return ElasticsearchQueryBuilder
     */
    public function setRelations($relation_key, $relation_name)
    {
        $this->eq();
        return $this->where($relation_key, $relation_name);
    }

    /**
     * @return array
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @return array
     */
    public function getCountQuery()
    {
        unset($this->query['from']);
        unset($this->query['size']);
        unset($this->query['sort']);
        unset($this->query['_source']);

        return $this->query;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return json_encode($this->query, 1);
    }

    /**
     * @param $name
     * @param $arguments
     * @return bool|mixed|Model
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this, $name)) {
            return call_user_func_array([$this, $name], $arguments);
        } elseif (method_exists($this->parent, $name)) {
            $arguments[] = $this;
            return call_user_func_array([$this->parent, $name], $arguments);
        }
        return false;
    }
}