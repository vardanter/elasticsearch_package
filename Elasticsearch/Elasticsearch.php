<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 26/02/2018
 * Time: 08:03
 */
namespace App\Elasticsearch;

use Elasticsearch\ClientBuilder;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Mockery\Exception;

/**
 * MAIN Class Elasticsearch injects elasticsearch.php configs into ClientBuilder
 * Class Elasticsearch
 * @package App\Elasticsearch
 */
class Elasticsearch
{
    private static $client = null;

    private static function createClient()
    {
        $client_builder = ClientBuilder::create();
        $client_builder->setHosts(config('elasticsearch.hosts'));
        static::$client = $client_builder->build();
    }

    public static function getConnection()
    {
        if (static::$client == null) {
            static::createClient();
        }
        return static::$client;
    }
}