<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 01/03/2018
 * Time: 00:50
 */

namespace App\Elasticsearch\Eloquent\Traits;

/**
 * Dynamic index name and type name
 * Trait TElasticsearchDynamicIndexType
 * @property $index_name
 * @property $type_name
 * @package App\Elasticsearch\Eloquent\Traits
 */
trait TElasticsearchDynamicIndexType
{
    private $index_name;
    private $type_name;

    /**
     * @param $name
     */
    public function setIndexName($name)
    {
        $this->index_name = $name;
    }

    /**
     * @param $name
     */
    public function setTypeName($name)
    {
        $this->type_name = $name;
    }

    /**
     * @return mixed
     */
    public function index()
    {
        return $this->index_name;
    }

    /**
     * @return mixed
     */
    public function type()
    {
        return $this->type_name;
    }
}