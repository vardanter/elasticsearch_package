<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 27/02/2018
 * Time: 17:24
 */
namespace App\Elasticsearch\Eloquent\Interfaces;

interface WithRouting {
    /**
     * realize this function to have dynamic routing
     * example
     * return $this->_id
     *
     * @return mixed
     */
    public function routing();
}