<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 10/03/2018
 * Time: 02:13
 */

namespace App\Elasticsearch\Commands\Crons;


use App\Elasticsearch\Eloquent\Model;
use App\Elasticsearch\Eloquent\Query\ElasticsearchBulkBuilder;
use App\LikeDislikeVideo;
use App\VideoTape;

class LikeDislike extends ParentCron
{

    protected $signature = 'like_dislike {--type= : video or ad_video}';

    protected $description = 'Update like_dislike child docs with bulk';

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
                $this->log_type = static::VIDEO_LIKE_DISLIKE;
                $this->videoLikeDislike();
                break;
            case 'ad_video':
                $this->index = 'ad_videos';
                $this->type = 'ad_video';
                $this->log_type = static::AD_VIDEO_LIKE_DISLIKE;
                $this->adVideoLikeDislike();
                break;
            default:
                $this->error('--type can be video or ad_video');
                break;
        }
    }

    private function videoLikeDislike()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids = LikeDislikeVideo::leftJoin('video_tapes','video_tapes.id','=','like_dislike_videos.video_tape_id')
            ->where('video_tapes.type', '!=', VideoTape::PREAD_VIDEO_TYPE)
            ->where('video_tapes.type', '!=', VideoTape::AD_VIDEO_TYPE)
            ->where('like_dislike_videos.created_at', '>', $last_time)
            ->select('like_dislike_videos.video_tape_id as id')
            ->groupBy('like_dislike_videos.video_tape_id')
            ->get()
            ->map(function($model) {
                return $model->id;
            })->toArray();

        foreach (array_chunk($video_ids, 1000) as $ids) {
            $video_like_dislikes = LikeDislikeVideo::whereIn('video_tape_id', $ids)
                ->select('video_tape_id as id')
                ->addSelect(\DB::raw('SUM(like_status) as likes'))
                ->addSelect(\DB::raw('SUM(dislike_status) as dislikes'))
                ->groupBy('video_tape_id')
                ->get();

            $this->createBulk($video_like_dislikes);
        }
    }

    private function adVideoLikeDislike()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids = LikeDislikeVideo::leftJoin('video_tapes','video_tapes.id','=','like_dislike_videos.video_tape_id')
            ->where('video_tapes.type', '=', VideoTape::AD_VIDEO_TYPE)
            ->where('like_dislike_videos.created_at', '>', $last_time)
            ->select('like_dislike_videos.video_tape_id as id')
            ->groupBy('like_dislike_videos.video_tape_id')
            ->get()
            ->map(function($model) {
                return $model->id;
            })->toArray();

        foreach (array_chunk($video_ids, 1000) as $ids) {
            $video_like_dislikes = LikeDislikeVideo::whereIn('video_tape_id', $ids)
                ->select('video_tape_id as id')
                ->addSelect(\DB::raw('SUM(like_status) as likes'))
                ->addSelect(\DB::raw('SUM(dislike_status) as dislikes'))
                ->groupBy('video_tape_id')
                ->get();

            $this->createBulk($video_like_dislikes);
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
                '_id' => 'ld_' . $datum->id
            ];
            $bulk->addUpdate($index);
            $new_data = [
                'likes' => $datum->likes,
                'dislikes' => $datum->dislikes,
                'likesdiff' => $datum->likes - $datum->dislikes
            ];
            $bulk->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }
}