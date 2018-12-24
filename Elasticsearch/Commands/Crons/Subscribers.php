<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 10/03/2018
 * Time: 01:12
 */

namespace App\Elasticsearch\Commands\Crons;

use App\Elasticsearch\Eloquent\Model;
use App\Elasticsearch\Eloquent\Query\ElasticsearchBulkBuilder;
use App\ElasticsearchBulkCron;
use App\UserChannelSubscribe;

class Subscribers extends ParentCron
{

    protected $signature = 'subscribers';

    protected $description = 'Update Channel Subscribers child docs with bulk';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->index = 'channels';
        $this->type = 'channel';
        $this->log_type = static::CHANNEL_SUBSCRIBERS;

        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $channel_ids = UserChannelSubscribe::where('created_at', '>', $last_time)
            ->select('channel_id as id')
            ->groupBy('channel_id')
            ->get()
            ->map(function($model) {
                return $model->id;
            })->toArray();

        foreach (array_chunk($channel_ids, 1000) as $ids) {
            $channel_subscribers = UserChannelSubscribe::whereIn('channel_id', $ids)
                ->select('channel_id as id')
                ->addSelect(\DB::raw('count(id) as subscribers'))
                ->groupBy('channel_id')
                ->get();

            $this->createBulk($channel_subscribers);
        }
    }

    private function createBulk($data)
    {
        $bulk = new ElasticsearchBulkBuilder();
        $bulk->setBulkCount(3000);

        $count = 0;
        foreach ($data as $datum) {
            $index = [
                '_index' => Model::getPrefix() .$this->index,
                '_type' => $this->type,
                '_routing' => $datum->id,
                '_id' => 'sub_' . $datum->id
            ];
            $bulk->addUpdate($index);
            $new_data = ['subscribers' => $datum->subscribers];
            $bulk->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }
}