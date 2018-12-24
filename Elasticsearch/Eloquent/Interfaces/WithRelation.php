<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 27/02/2018
 * Time: 17:08
 */
namespace App\Elasticsearch\Eloquent\Interfaces;

interface WithRelation {
    /**
     * Get model's relations field
     * @return array
     */
    public function getRelation();

    /**
     * @return mixed
     */
    public function getRelationName();

    /**
     * @return mixed
     */
    public function getRelationKey();

    /**
     * @return void
     */
    public function createChilds();
}