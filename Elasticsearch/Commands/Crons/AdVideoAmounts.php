<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 23/03/2018
 * Time: 09:45
 */

namespace App\Elasticsearch\Commands\Crons;


use App\Elasticsearch\Eloquent\Model;
use App\Elasticsearch\Eloquent\Query\ElasticsearchBulkBuilder;
use App\VideoTape;

class AdVideoAmounts extends ParentCron
{

    protected $signature = 'ad_video_amounts';

    protected $description = '';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->index = 'ad_videos';
        $this->type  = 'ad_video';
        $this->log_type = static::AD_VIDEO_AMOUNTS;

        $this->log_obj = $this->getLogObj();
        $last_time = $this->getLastTime();

        $videos = VideoTape::select('video_tapes.*')
            ->leftJoin('channels', 'channels.id', '=', 'video_tapes.channel_id')
            ->leftJoin('users', 'users.id', '=', 'video_tapes.user_id')
            ->where('type', VideoTape::AD_VIDEO_TYPE)
            ->where('updated_at', '>', $last_time)
            ->where('is_approved', DEFAULT_TRUE)
            ->where('finished', DEFAULT_TRUE)
            ->where('users.status', DEFAULT_TRUE)
            ->where('channels.is_apporved', DEFAULT_TRUE);

        $this->createBulk($videos);
    }

    private function createBulk($data)
    {
        $bulk = new ElasticsearchBulkBuilder();
        $bulk->setBulkCount(3000);

        $count = 0;
        foreach ($data as $datum) {
            $index = [
                '_index' => Model::getPrefix() . $this->index,
                '_type' => $this->type,
                '_routing' => $datum->id,
                '_id' => $datum->id
            ];
            $bulk->addUpdate($index);

            $new_data = [
                'ad_count'          => $datum->ad_count,
                'ad_show_count'     => $datum->ad_show_count,
                'ad_watch_count'    => $datum->ad_watch_count
            ];

            $bulk->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }
}