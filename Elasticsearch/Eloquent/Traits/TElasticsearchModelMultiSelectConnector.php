<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 01/03/2018
 * Time: 01:18
 */

namespace App\Elasticsearch\Eloquent\Traits;

use App\Elasticsearch\Eloquent\Query\ElasticsearchMultiIndexQueryBuilder;

/**
 * Trait TElasticsearchModelMultiSelectConnector
 * @package App\Elasticsearch\Eloquent\Traits
 */
trait TElasticsearchModelMultiSelectConnector
{
    /**
     * Uses multi index search "msearch"
     * @return ElasticsearchMultiIndexQueryBuilder
     */
    public static function msearch()
    {
        return new ElasticsearchMultiIndexQueryBuilder();
    }
}