<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 26/02/2018
 * Time: 09:21
 */

namespace App\Elasticsearch\Commands;

use App\Elasticsearch\Elasticsearch;
use App\Elasticsearch\Eloquent\Model;
use App\LikeDislikeVideo;
use App\Tags;
use App\UserRating;
use App\VideoLang;
use App\VideoTape;
use App\VideoWatchCounter;
use Illuminate\Console\Command;

class ElasticsearchBulkAdVideos extends Command
{
    private static $from = 0;

    private static $step = 1000;

    protected $signature = "elasticsearch:bulk:ad_videos";

    protected $description = "Bulk insert all ad_videos into elasticsearch ad_videos index";

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $videos = $this->getVideos();

        if (empty($videos) || count($videos) == 0) {
            return;
        }

        $bulks = [];

        $count = 0;

        foreach ($videos as $video) {
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'ad_videos',
                    '_type' => 'ad_video',
                    '_id' => $video->id,
                    '_routing' => $video->id,
                ]
            ];
            $data = $this->getNormalVideoFields($video->getAttributes());
            $data = $this->setTargetings($data);
            if (!isset($data['user_status'])) {
                $data['user_status'] = mt_rand(0,1);
                $data['channel_status'] = mt_rand(0,1);
            }
            if (!isset($data['categories'])) {
                $data['categories'] = 'Art';
            }
            $data['tags'] = static::getVideoTags($video->id);
            $data['duration'] = static::getDuration($video->duration);
            $data['likes'] = static::getVideoLikes($video->id);
            $data['dislikes'] = static::getVideoDislikes($video->id);
            $data['likesdiff'] = $data['likes'] - $data['dislikes'];
            $data['comment_count'] = static::getVideoComments($video->id);
            $data['relevance'] = 0;
            $data['langs'] = static::getVideoLangs($data);
            $data['rating'] = 0;
            $data['booster'] = [];

            $data['relations'] = 'video';
            if ($data['publish_time'] == '0000-00-00 00:00:00') {
                unset($data['publish_time']);
            }
            $watch_count = $data['watch_count'];

            $count++;
            $bulks['body'][$count] = $data;


            //WATCH_COUNT START
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'ad_videos',
                    '_type' => 'ad_video',
                    '_id' => 'wc_' . $video->id,
                    '_routing' => $video->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $video->id;
            $data_child['watch_count'] = $video->watch_count;
            $data_child['relations'] = [
                'name' => 'watch_count',
                'parent' => $video->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //WATCH_COUNT END


            //COMMENTS START
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'ad_videos',
                    '_type' => 'ad_video',
                    '_id' => 'com_' . $video->id,
                    '_routing' => $video->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $video->id;
            $data_child['comment_count'] = $data['comment_count'];
            $data_child['relations'] = [
                'name' => 'comment_count',
                'parent' => $video->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //COMMENTS START

            //LIKES DISLIKES START
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'ad_videos',
                    '_type' => 'ad_video',
                    '_id' => 'ld_' . $video->id,
                    '_routing' => $video->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $video->id;
            $data_child['likes'] = $data['likes'];
            $data_child['dislikes'] = $data['dislikes'];
            $data_child['likesdiff'] = $data['likesdiff'];
            $data_child['relations'] = [
                'name' => 'like_dislike',
                'parent' => $video->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //LIKES DISLIKES END


            //RELEVANCE END
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'ad_videos',
                    '_type' => 'ad_video',
                    '_id' => 'relevance_' . $video->id,
                    '_routing' => $video->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $video->id;
            $data_child['relevance'] = $data['relevance'];
            $data_child['relations'] = [
                'name' => 'relevance',
                'parent' => $video->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //RELEVANCE END


            //RATING START
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'ad_videos',
                    '_type' => 'ad_video',
                    '_id' => 'rating_' . $video->id,
                    '_routing' => $video->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $video->id;
            $data_child['rating'] = $data['rating'];
            $data_child['relations'] = [
                'name' => 'rating',
                'parent' => $video->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //RATING END


            $count++;
        }

        $responses = Elasticsearch::getConnection()->bulk($bulks);

        unset($data);
        unset($bulks);
        unset($responses);
        unset($videos);

        $this->error("FROM : " . static::$from);
        $this->error("TO   : " . static::$step);
        static::$from += static::$step;
        $this->line( "MEMORY USAGE : " . memory_get_usage(true) . PHP_EOL);
        $this->handle();
    }

    public function getDuration($str_time)
    {
        sscanf($str_time, "%d:%d:%d", $hours, $minutes, $seconds);

        $time_seconds = isset($seconds) ? $hours * 3600 + $minutes * 60 + $seconds : $hours * 60 + $minutes;

        return $time_seconds;
    }

    public function getVideoTags($id)
    {
        $tags = Tags::select("tags.*")
            ->leftJoin('video_tags', 'video_tags.tag_id', '=', 'tags.id')
            ->leftJoin('video_tapes', 'video_tapes.id', '=', 'video_tags.video_id')
            ->where('video_tapes.id', $id)
            ->get();

        $tags_ar = [];

        foreach ($tags as $tag) {
            $tags_ar[] = $tag->tag;
        }

        return $tags_ar;
    }

    public function getNormalVideoFields($fields)
    {
        return $fields;
    }

    private function getVideoLikes($id)
    {
        $like_count = LikeDislikeVideo::where('video_tape_id', $id)
            ->where('like_status', DEFAULT_TRUE)
            ->count();

        return $like_count >= 0 ? $like_count : 0;
    }

    private function getVideoDislikes($id)
    {
        $dislike_count = LikeDislikeVideo::where('video_tape_id', $id)
            ->where('dislike_status', DEFAULT_TRUE)
            ->count();

        return $dislike_count >= 0 ? $dislike_count : 0;
    }

    private function getVideoRelevance($data)
    {
        if ($data['watch_count'] == 0) {
            return 0;
        }

        $comment_count = static::getVideoComments($data['id'], true);
        $likesdiff = $data['likesdiff'];

        /**
         * TODO: ADD coef to Comment_count and likesdiff
         */
        $comments_and_likes = $comment_count + $likesdiff;

        if ($comments_and_likes > ($data['watch_count'] * 40) / 100) {
            return 0;
        }

        $percent = $comments_and_likes * 100;

        $logged_watches = static::getVideoWatchCounts($data['id']);

        $logged_watches = $data['watch_count'] / ($logged_watches > 0 ? $logged_watches : 1);

        $relevance = $percent / $data['watch_count'] / $logged_watches;

        return $relevance;
    }

    private function getVideoWatchCounts($id)
    {
        return VideoWatchCounter::where('video_id', $id)->where('user_id', '>', 0)->count();
    }

    private function getVideoComments($id, $unique = false)
    {

        $comments_count = UserRating::where('video_tape_id', $id);

        if ($unique) {
            $comments_count = $comments_count->groupBy('user_id');
        }

        $comments_count = $comments_count->count();

        return $comments_count >= 0 ? $comments_count : 0;
    }

    private function setTargetings($data)
    {
        $data['targeting_countries'] = !empty($data['targeting_countries']) ? explode(',', $data['targeting_countries']) : [];
        $data['targeting_categories'] = !empty($data['targeting_categories']) ? explode(',', $data['targeting_categories']) : [];
        $data['targeting_ages'] = !empty($data['targeting_ages']) ? explode(',', $data['targeting_ages']) : [];
        $data['targeting_sexes'] = !empty($data['targeting_countries']) ? explode(',', $data['targeting_sexes']) : [];

        return $data;
    }

    private function getVideoLangs($data)
    {
        $video_langs = VideoLang::where('video_id', $data['id'])->get()->map(function($model) {
            return $model->lang;
        })->toArray();

        return $video_langs;
    }

    private function getVideos()
    {
        $videos_normal = VideoTape::select('video_tapes.*')
            ->orWhere('video_tapes.type',2)
            ->leftJoin('users','users.id','=','video_tapes.user_id')
            ->leftJoin('user_interests','user_interests.id','=','video_tapes.category_id')
            ->leftJoin('channels','channels.id','=','video_tapes.channel_id')
            ->addSelect('users.status as user_status')
            ->addSelect('channels.is_approved as channel_status')
            ->addSelect('user_interests.title as categories')
            ->skip(static::$from)
            ->limit(static::$step);

        /**
         * ADD TARGETINGS
         */
        $videos_normal = $videos_normal->leftJoin('targetings','targetings.video_id','=','video_tapes.id')
            ->leftJoin('targeting_countries','targeting_countries.targeting_id','=','targetings.id')
            ->leftJoin('targeting_categories','targeting_categories.targeting_id','=','targetings.id')
            ->leftJoin('targeting_ages','targeting_ages.targeting_id','=','targetings.id')
            ->leftJoin('targeting_sexes','targeting_sexes.targeting_id','=','targetings.id')
            ->addSelect(\DB::raw('GROUP_CONCAT(DISTINCT targeting_countries.id) as targeting_countries'))
            ->addSelect(\DB::raw('GROUP_CONCAT(DISTINCT targeting_categories.id) as targeting_categories'))
            ->addSelect(\DB::raw('GROUP_CONCAT(DISTINCT targeting_ages.id) as targeting_ages'))
            ->addSelect(\DB::raw('GROUP_CONCAT(DISTINCT targeting_sexes.id) as targeting_sexes'))
            ->groupBy('video_tapes.id')
            ->orderBy('video_tapes.id', 'ASC')
            ->get();

        return $videos_normal;
    }
}