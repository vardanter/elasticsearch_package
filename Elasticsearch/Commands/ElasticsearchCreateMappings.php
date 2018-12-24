<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 26/02/2018
 * Time: 08:55
 */
namespace App\Elasticsearch\Commands;

use App\Elasticsearch\Elasticsearch;
use App\Elasticsearch\Eloquent\Model;
use Illuminate\Console\Command;

class ElasticsearchCreateMappings extends Command
{
    private $mappings_path;

    protected $signature = "elasticsearch:mappings
                            {--force : force mapping remap existing indexes}
    ";

    protected $description = "Creating mappings from ./mappings/ folder";

    public function __construct()
    {
        $this->mappings_path = __DIR__. DIRECTORY_SEPARATOR . 'mappings' . DIRECTORY_SEPARATOR;
        parent::__construct();
    }

    public function handle()
    {
        $mapping_files = scandir($this->mappings_path);

        $mappings = $this->getMappings($mapping_files);

        $this->setMappings($mappings);
    }

    private function getMappings($mapping_files)
    {
        $mappings = [];
        foreach ($mapping_files as $file_name) {
            $params = [];
            if ($file_name == '.' || $file_name == '..') {
                continue;
            }
            $mappings[$file_name] = @json_decode(file_get_contents($this->mappings_path . $file_name), 1);
            $params['index'] = Model::getPrefix() . $file_name;
            $exists = Elasticsearch::getConnection()->indices()->exists($params);
            if ($exists && !$this->option('force')) {
                unset($mappings[$file_name]);
            }
        }

        return $mappings;
    }

    private function setMappings($mappings)
    {
        $count = 0;
        $indices = [];
        foreach ($mappings as $index => $mapping) {
            $count++;
            $indices[] = Model::getPrefix() . $index;

            $params['index'] = Model::getPrefix() . $index;
            $params['body'] = $mapping;
            Elasticsearch::getConnection()->indices()->create($params);
        }
        $this->info('Created ' . $count . ' indices');
        $this->info('Indices : ' . implode(' , ', $indices));
    }
}