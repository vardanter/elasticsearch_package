<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 31/03/2018
 * Time: 21:54
 */

namespace App\Elasticsearch\Eloquent\Traits;

/**
 * Trait TElasticsearchParent
 * @package App\Elasticsearch\Eloquent\Traits
 */
trait TElasticsearchParent
{
    /**
     * Get child object
     * @param $child_name
     * @param bool $attribute
     * @return object
     */
    public function child($child_name, $attribute = false)
    {
        $element = $this->{'child_' . $child_name};

        return $attribute ? $element->$attribute : $element;
    }
}