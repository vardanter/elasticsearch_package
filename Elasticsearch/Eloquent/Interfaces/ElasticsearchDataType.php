<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 01/08/2018
 * Time: 18:56
 */

namespace App\Elasticsearch\Eloquent\Interfaces;


interface ElasticsearchDataType
{
    /**
     * Get Global Type of object
     * @return mixed
     */
    public function getDataType();
}