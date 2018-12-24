<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 09/03/2018
 * Time: 07:52
 */

namespace App\Elasticsearch\Commands\Crons;

use App\Elasticsearch\Eloquent\Model;
use App\Elasticsearch\Eloquent\Query\ElasticsearchBulkBuilder;
use App\Elasticsearch\Eloquent\Query\ElasticsearchQueryBuilder;
use App\ElasticsearchBulkCron;
use App\Models\Childs\VideoWatchCount;
use App\Models\Playlist;
use App\VideoTape;
use App\VideoWatchCounter;

class WatchCount extends ParentCron
{

    protected $signature = 'watch_count {--type= : video, ad_video, channel or playlist}';

    protected $description = 'Update watch_count child docs with bulk';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $type = $this->option('type');

        switch ($type) {
            case 'video':
                $this->index = 'videos';
                $this->type = 'video';
                $this->log_type = static::VIDEO_WATCH_COUNT;
                $this->videoWatchCounts();
                break;
            case 'ad_video':
                $this->index = 'ad_videos';
                $this->type = 'ad_video';
                $this->log_type = static::AD_VIDEO_WATCH_COUNT;
                $this->adVideoWatchCounts();
                break;
            case 'channel':
                $this->index = 'channels';
                $this->type = 'channel';
                $this->log_type = static::CHANNEL_WATCH_COUNT;
                $this->channelWatchCounts();
                break;
            case 'playlist':
                $this->index = 'playlists';
                $this->type = 'playlist';
                $this->log_type = static::PLAYLIST_WATCH_COUNT;
                $this->playlistWatchCounts();
                break;
            default:
                $this->error('--type can be video, ad_video or channel');
                break;
        }

    }

    private function videoWatchCounts()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids = VideoWatchCounter::leftJoin('video_tapes','video_tapes.id','=','video_watch_counters.video_id')
            ->where('video_tapes.type', '!=', VideoTape::PREAD_VIDEO_TYPE)
            ->where('video_tapes.type', '!=', VideoTape::AD_VIDEO_TYPE)
            ->where('video_watch_counters.created_at', '>', $last_time)
            ->select('video_id as id')
            ->groupBy('video_id')
            ->get()->map(function($model) {
                return $model->id;
            })->toArray();

        foreach (array_chunk($video_ids, 1000) as $ids) {
            $video_watch_counters = VideoWatchCounter::whereIn('video_id', $ids)
                ->select('video_id as id')
                ->addSelect(\DB::raw('count(video_id) as watch_count'))
                ->groupBy('video_id')
                ->get();

            $this->createBulk($video_watch_counters);
        }
    }

    private function adVideoWatchCounts()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids = VideoWatchCounter::leftJoin('video_tapes','video_tapes.id','=','video_watch_counters.video_id')
            ->where('video_tapes.type', '=', VideoTape::AD_VIDEO_TYPE)
            ->where('video_watch_counters.created_at', '>', $last_time)
            ->select('video_id as id')
            ->groupBy('video_id')
            ->get()->map(function($model) {
                return $model->id;
            })->toArray();

        foreach (array_chunk($video_ids, 1000) as $ids) {
            $video_watch_counters = VideoWatchCounter::whereIn('video_id', $ids)
                ->select('video_id as id')
                ->addSelect(\DB::raw('count(video_id) as watch_count'))
                ->groupBy('video_id')
                ->get();

            $this->createBulk($video_watch_counters);
        }
    }

    private function channelWatchCounts()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $channel_ids = VideoWatchCounter::leftJoin('video_tapes', 'video_tapes.id', '=', 'video_watch_counters.video_id')
            ->leftJoin('channels', 'channels.id', '=', 'video_tapes.channel_id')
            ->where('video_tapes.type', '!=', VideoTape::PREAD_VIDEO_TYPE)
            ->where('video_watch_counters.created_at', '>', $last_time)
            ->select('channels.id as id')
            ->groupBy('channels.id')
            ->get()->map(function($model){
                return $model->id;
            })->toArray();

        foreach (array_chunk($channel_ids, 1000) as $ids) {
            $channel_watch_counters = VideoTape::whereIn('channel_id', $ids)
                ->select(\DB::raw('SUM(watch_count) as watch_count'))
                ->addSelect('channel_id as id')
                ->groupBy('channel_id')
                ->get();


            $this->createBulk($channel_watch_counters);
        }
    }

    private function playlistWatchCounts()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $playlist_ids = VideoWatchCounter::leftJoin('video_tapes','video_tapes.id','=','video_watch_counters.video_id')
            ->where('video_watch_counters.created_at', '>', $last_time)
            ->whereNotNull('playlist_id')
            ->select('playlist_id as id')
            ->groupBy('playlist_id')
            ->get()->map(function($model) {
                return $model->id;
            })->toArray();
        
        foreach (array_chunk($playlist_ids, 1000) as $ids) {
            $playlist_watch_counts = VideoWatchCounter::whereIn('playlist_id', $ids)
                ->whereNotNull('playlist_id')
                ->select('playlist_id as id')
                ->addSelect(\DB::raw('count(playlist_id) as watch_count'))
                ->groupBy('playlist_id')
                ->get();

            $this->createBulk($playlist_watch_counts);
        }
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
                '_id' => 'wc_' . $datum->id
            ];
            $bulk->addUpdate($index);
            $new_data = ['watch_count' => $datum->watch_count];
            $bulk->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }

}