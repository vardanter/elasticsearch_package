<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 01/03/2018
 * Time: 00:49
 */

namespace App\Elasticsearch\Eloquent;

use App\Elasticsearch\Eloquent\Traits\TElasticsearchDynamicIndexType;

/**
 * This class used for setting data without it's model
 * Class ElasticsearchEmptyModel
 * @package App\Elasticsearch\Eloquent
 */
class ElasticsearchEmptyModel extends Model
{
    use TElasticsearchDynamicIndexType;
}