<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 10/03/2018
 * Time: 01:39
 */

namespace App\Elasticsearch\Commands\Crons;


use App\Elasticsearch\Eloquent\Model;
use App\Elasticsearch\Eloquent\Query\ElasticsearchBulkBuilder;
use App\UserRating;
use App\VideoTape;

class CommentCount extends ParentCron
{

    protected $signature = 'comment_count {--type= : video or ad_video}';

    protected $description = 'Update comment_count child docs with bulk';

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
                $this->log_type = static::VIDEO_COMMENT_COUNT;
                $this->videoCommentCounts();
                break;
            case 'ad_video':
                $this->index = 'ad_videos';
                $this->type = 'ad_video';
                $this->log_type = static::AD_VIDEO_COMMENT_COUNT;
                $this->adVideoCommentCounts();
                break;
            default:
                $this->error('--type can be video or ad_video');
                break;
        }
    }

    private function videoCommentCounts()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids = UserRating::leftJoin('video_tapes', 'video_tapes.id', '=', 'user_ratings.video_tape_id')
            ->where('video_tapes.type', '!=', VideoTape::PREAD_VIDEO_TYPE)
            ->where('video_tapes.type', '!=', VideoTape::AD_VIDEO_TYPE)
            ->where('user_ratings.created_at', '>', $last_time)
            ->select('video_tape_id as id')
            ->groupBy('video_tape_id')
            ->get()
            ->map(function($model) {
                return $model->id;
            })->toArray();

        foreach(array_chunk($video_ids, 1000) as $ids) {
            $video_comment_counts = UserRating::whereIn('video_tape_id', $ids)
                ->select('video_tape_id as id')
                ->addSelect(\DB::raw('count(id) as comment_count'))
                ->groupBy('video_tape_id')
                ->get();

            $this->createBulk($video_comment_counts);
        }
    }

    private function adVideoCommentCounts()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids = UserRating::leftJoin('video_tapes', 'video_tapes.id', '=', 'user_ratings.video_tape_id')
            ->where('video_tapes.type', '=', VideoTape::AD_VIDEO_TYPE)
            ->where('user_ratings.created_at', '>', $last_time)
            ->select('video_tape_id as id')
            ->groupBy('video_tape_id')
            ->get()
            ->map(function($model) {
                return $model->id;
            })->toArray();

        foreach(array_chunk($video_ids, 1000) as $ids) {
            $video_comment_counts = UserRating::whereIn('video_tape_id', $ids)
                ->select('video_tape_id as id')
                ->addSelect(\DB::raw('count(id) as comment_count'))
                ->groupBy('video_tape_id')
                ->get();

            $this->createBulk($video_comment_counts);
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
                '_id' => 'com_' . $datum->id
            ];
            $bulk->addUpdate($index);
            $new_data = ['comment_count' => $datum->comment_count];
            $bulk->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }
}