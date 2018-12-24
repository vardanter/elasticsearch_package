<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 10/03/2018
 * Time: 08:54
 */

namespace App\Elasticsearch\Commands\Crons;


use App\Channel;
use App\Elasticsearch\Eloquent\Model;
use App\Elasticsearch\Eloquent\Query\ElasticsearchBulkBuilder;
use App\Elasticsearch\Eloquent\Query\ElasticsearchQueryBuilder;
use App\LikeDislikeVideo;
use App\Models\Childs\PlaylistVideo;
use App\Models\Childs\VideoRelevance;
use App\Models\Playlist;
use App\Models\Video;
use App\UserChannelSubscribe;
use App\UserRating;
use App\VideoTape;
use App\VideoWatchCounter;

class Relevance extends ParentCron
{

    protected $signature = 'relevance {--type= : video,ad_video,channel or playlist}';

    protected $description = 'Update video channel playlist and ad_video relevance child docs with bulk';

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
                $this->log_type = static::VIDEO_RELEVANCE;
                $this->videoRelevance();
                break;
            case 'ad_video':
                $this->index = 'ad_videos';
                $this->type = 'ad_video';
                $this->log_type = static::AD_VIDEO_RELEVANCE;
                $this->adVideoRelevance();
                break;
            case 'channel':
                $this->index = 'channels';
                $this->type = 'channel';
                $this->log_type = static::CHANNEL_RELEVANCE;
                $this->getChannelRelevance();
                break;
            case 'playlist':
                $this->index = 'playlists';
                $this->type = 'playlist';
                $this->log_type = static::PLAYLIST_RELEVANCE;
                $this->getPlaylistRelevance();
                break;
            default:
                $this->error('--type can be video,ad_video,channel or playlist');
                break;
        }
    }

    private function getVideoViews($ids)
    {
        $results = [];
        foreach (array_chunk($ids, 1000) as $n_ids) {
            $video_watch_counters = VideoWatchCounter::whereIn('video_id', $n_ids)
                ->select('video_id as id')
                ->addSelect(\DB::raw('count(video_id) as watch_count'))
                ->groupBy('video_id')
                ->get();

            foreach ($video_watch_counters as $video_watch_counter) {
                $results[$video_watch_counter->id] = $video_watch_counter->watch_count;
            }
        }
        return $results;
    }

    private function getVideoLoggedViews($ids)
    {
        $results = [];
        foreach (array_chunk($ids, 1000) as $n_ids) {
            $video_watch_counters = VideoWatchCounter::whereIn('video_id', $n_ids)
                ->select('video_id as id')
                ->addSelect(\DB::raw('count(video_id) as watch_count'))
                ->where('user_id', '>', 0)
                ->groupBy('video_id')
                ->get();

            foreach ($video_watch_counters as $video_watch_counter) {
                $results[$video_watch_counter->id] = $video_watch_counter->watch_count;
            }
        }

        return $results;
    }

    private function getVideoLikes($ids)
    {
        $results = [];
        foreach (array_chunk($ids, 1000) as $n_ids) {
            $video_like_dislikes = LikeDislikeVideo::whereIn('video_tape_id', $n_ids)
                ->select('video_tape_id as id')
                ->addSelect(\DB::raw('SUM(like_status) as likes'))
                ->addSelect(\DB::raw('SUM(dislike_status) as dislikes'))
                ->groupBy('video_tape_id')
                ->get();

            foreach ($video_like_dislikes as $video_like_dislike) {
                $results[$video_like_dislike->id] = $video_like_dislike->likes - $video_like_dislike->dislikes;
            }
        }

        return $results;
    }

    private function getVideoComments($ids)
    {
        $results = [];
        foreach (array_chunk($ids, 1000) as $n_ids) {
            $video_comment_counts = UserRating::whereIn('video_tape_id', $n_ids)
                ->select('video_tape_id as id')
                ->addSelect(\DB::raw('count(id) as comment_count'))
                ->groupBy('video_tape_id', 'user_id')
                ->get();

            foreach ($video_comment_counts as $video_comment_count) {
                $results[$video_comment_count->id] = $video_comment_count->comment_count;
            }
        }
        return $results;
    }

    private function getChannelIds($ids)
    {
        $full_ids = [];

        foreach (array_chunk($ids, 1000) as $in_ids) {
            $channel_ids = Channel::leftJoin('video_tapes','video_tapes.channel_id', '=', 'channels.id')
                ->whereIn('video_tapes.id', $in_ids)
                ->select('video_tapes.channel_id')
                ->groupBy('video_tapes.channel_id')
                ->get()
                ->map(function($model) {
                    return $model->channel_id;
                })->toArray();

            if (!empty($channel_ids)) {
                $full_ids = array_unique(array_merge($full_ids, $channel_ids));
            }
        }

        return $full_ids;
    }

    private function getVideoIds($ids, $type = 'video')
    {
        $full_ids = [];

        foreach (array_chunk($ids, 1000) as $in_ids) {
            $new_ids = VideoTape::whereIn('id', $in_ids);
            if ($type == 'video') {
                $new_ids = $new_ids->where('type', '!=', VideoTape::AD_VIDEO_TYPE)
                    ->where('type', '!=', VideoTape::PREAD_VIDEO_TYPE);
            } elseif ($type == 'ad_video') {
                $new_ids = $new_ids->where('type', VideoTape::AD_VIDEO_TYPE);
            } else {
                $new_ids = $new_ids->where('type', '!=', VideoTape::PREAD_VIDEO_TYPE);
            }

            $new_ids = $new_ids->get()->map(function ($model) {
                return $model->id;
            })->toArray();
            $full_ids = array_merge($full_ids, $new_ids);
        }

        return $full_ids;
    }

    private function getChannelsRelevances($all_channel_ids)
    {
        $full_results = [];

        foreach ($all_channel_ids as $channel_id) {
            $all_videos = Video::filter('channel_id', $channel_id)
                ->hasChild('relevance', true)
                ->eq()
                ->addBool('must')
                ->range('created_at', 'lte', date('Y-m-d H:i:s', time() - (3 * 86400)), 'should')
                ->hasChild('relevance', false, false, false, false, false, 'should')
                ->range('relevance', 'gt', 0, 'must')
                ->eq()
                ->onlyActuals()
                ->size(ElasticsearchQueryBuilder::MAX_SIZE)
                ->get();

            $count = count($all_videos);
            $relevance = 0;
            foreach ($all_videos as $video) {
                $relevance += $video->child('relevance', 'relevance');
            }
            if ($count > 0) {
                $full_results[$channel_id] = $relevance / $count;
            }
        }

        return $full_results;
    }


    private function videoRelevance()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids_1 = VideoWatchCounter::where('video_watch_counters.created_at', '>', $last_time)
            ->select('video_id as id')
            ->groupBy('video_id')
            ->get()->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_1 = $this->getVideoIds($video_ids_1);

        $video_ids_2 = LikeDislikeVideo::where('like_dislike_videos.created_at', '>', $last_time)
            ->select('like_dislike_videos.video_tape_id as id')
            ->groupBy('like_dislike_videos.video_tape_id')
            ->get()
            ->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_2 = $this->getVideoIds($video_ids_2);

        $video_ids_3 = UserRating::where('user_ratings.created_at', '>', $last_time)
            ->select('video_tape_id as id')
            ->groupBy('video_tape_id')
            ->get()
            ->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_3 = $this->getVideoIds($video_ids_3);

        $all_ids = array_unique(array_merge($video_ids_1, $video_ids_2, $video_ids_3));
        $video_views = $this->getVideoViews($video_ids_1);
        $video_logged_views = $this->getVideoLoggedViews($video_ids_1);
        $video_comments = $this->getVideoComments($video_ids_2);
        $video_likes = $this->getVideoLikes($video_ids_2);

        $this->createBulk($all_ids, $video_views, $video_comments, $video_likes, $video_logged_views);
    }

    private function adVideoRelevance()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids_1 = VideoWatchCounter::where('video_watch_counters.created_at', '>', $last_time)
            ->select('video_id as id')
            ->groupBy('video_id')
            ->get()->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_1 = $this->getVideoIds($video_ids_1, 'ad_video');

        $video_ids_2 = LikeDislikeVideo::where('like_dislike_videos.created_at', '>', $last_time)
            ->select('like_dislike_videos.video_tape_id as id')
            ->groupBy('like_dislike_videos.video_tape_id')
            ->get()
            ->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_2 = $this->getVideoIds($video_ids_2, 'ad_video');

        $video_ids_3 = UserRating::where('user_ratings.created_at', '>', $last_time)
            ->select('video_tape_id as id')
            ->groupBy('video_tape_id')
            ->get()
            ->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_3 = $this->getVideoIds($video_ids_3, 'ad_video');

        $all_ids = array_unique(array_merge($video_ids_1, $video_ids_2, $video_ids_3));
        $video_views = $this->getVideoViews($video_ids_1);
        $video_logged_views = $this->getVideoLoggedViews($video_ids_1);
        $video_comments = $this->getVideoComments($video_ids_2);
        $video_likes = $this->getVideoLikes($video_ids_2);

        $this->createBulk($all_ids, $video_views, $video_comments, $video_likes, $video_logged_views);
    }

    private function getChannelRelevance()
    {
        $this->log_obj = $this->getLogObj();

        $last_time = $this->getLastTime();

        $video_ids_1 = VideoWatchCounter::where('video_watch_counters.created_at', '>', $last_time)
            ->select('video_id as id')
            ->groupBy('video_id')
            ->get()->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_1 = $this->getVideoIds($video_ids_1, 'all');

        $video_ids_2 = LikeDislikeVideo::where('like_dislike_videos.created_at', '>', $last_time)
            ->select('like_dislike_videos.video_tape_id as id')
            ->groupBy('like_dislike_videos.video_tape_id')
            ->get()
            ->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_2 = $this->getVideoIds($video_ids_2, 'all');

        $video_ids_3 = UserRating::where('user_ratings.created_at', '>', $last_time)
            ->select('video_tape_id as id')
            ->groupBy('video_tape_id')
            ->get()
            ->map(function ($model) {
                return $model->id;
            })->toArray();
        $video_ids_3 = $this->getVideoIds($video_ids_3, 'ad_video');

        $channel_ids_1 = UserChannelSubscribe::where('created_at', '>', $last_time)
            ->select('channel_id')
            ->groupBy('channel_id')
            ->get()
            ->map(function ($model) {
                return $model->channel_id;
            })->toArray();

        $all_video_ids = array_unique(array_merge($video_ids_1, $video_ids_2, $video_ids_3));
        $all_channel_ids = array_unique(array_merge($channel_ids_1, $this->getChannelIds($all_video_ids)));

        $all = $this->getChannelsRelevances($all_channel_ids);

        $this->createChannelsBulk($all);
    }

    private function getPlaylistRelevance()
    {
        $this->log_obj = $this->getLogObj();

        $this->getLastTime();

        $size = 1000;
        $last_id = null;
        $results = [];
        while(true) {
            $playlist_videos = Playlist::size($size)->sort('_id');

            if ($last_id != null) {
                $playlist_videos = $playlist_videos->searchAfter($last_id);
            }
            $playlist_videos = $playlist_videos->get();

            if ($playlist_videos->isEmpty()) {
                break;
            }

            $full_ids = [];
            foreach ($playlist_videos as $playlist_video) {
                $full_ids [$playlist_video->id] = $playlist_video->video_ids;
            }
            $last_id = array_keys($full_ids)[count($full_ids) - 1];

            foreach ($full_ids  as $playlist_id => $ids) {
                $video_relevances = VideoRelevance::whereIn('id', $ids)->size(ElasticsearchQueryBuilder::MAX_SIZE)->get()->map(function($model) {
                    return $model->relevance;
                })->toArray();
                $count = count($video_relevances);
                if ($count < 2) {
                    continue;
                }
                $full_relevance = array_reduce($video_relevances, function($cur, $next) {
                    return $cur + $next;
                });
                $results[$playlist_id] = $full_relevance / $count;
            }
        }

        $this->createPlaylistsBulk($results);
    }

    private function createPlaylistsBulk($all)
    {
        $bulk = new ElasticsearchBulkBuilder();
        $bulk->setBulkCount(3000);

        $count = 0;

        foreach ($all as $id => $relevance) {
            $this->error($relevance);
            $this->error('Playlist_id : ' . $id);

            $index = [
                '_index' => Model::getPrefix() . $this->index,
                '_type' => $this->type,
                '_routing' => $id,
                '_id' => 'relevance_' . $id
            ];

            $new_data = ['relevance' => $relevance];

            $bulk = $bulk->addUpdate($index)->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }

    private function createChannelsBulk($all)
    {
        $bulk = new ElasticsearchBulkBuilder();
        $bulk->setBulkCount(3000);

        $count = 0;

        foreach ($all as $id => $relevance) {
            $this->error($relevance);
            $this->error('Channel_id : ' . $id);
            $index = [
                '_index' => Model::getPrefix() . $this->index,
                '_type' => $this->type,
                '_routing' => $id,
                '_id' => 'relevance_' . $id
            ];

            $new_data = ['relevance' => $relevance];

            $bulk = $bulk->addUpdate($index)->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }

    private function createBulk($all_ids, $video_views, $video_comments, $video_likes, $video_logged_views)
    {
        $bulk = new ElasticsearchBulkBuilder();
        $bulk->setBulkCount(3000);

        $count = 0;

        foreach ($all_ids as $id) {
            $c_comment = isset($video_comments[$id]) ? $video_comments[$id] : 0;
            $c_views = isset($video_views[$id]) ? $video_views[$id] : 0;
            $c_likes = isset($video_likes[$id]) ? $video_likes[$id] : 0;

            if ($c_views < 100 || $c_views == 0) {
                $this->error("CONTINUE");
                $relevance = 0;
                continue;
            } else {
                if ($c_comment + $c_likes > ($c_views * 60) / 100 || $c_views < 50) {
                    $relevance = 0;
                } else {
                    $relevance = ($c_comment + $c_likes) * 100 / $c_views;
                }

                //ETE vide-eri tiv@ meca 5000-ic uremn amen 5000 view-i hamar talisenq 1 relevance
                if ($relevance > 0 && $c_views > 5000) {
                    $relevance += ($c_views / 5000);
                }

                //ETE VIEW-er@ mecen 2000-ic uremn amen 10 like+comment-i hamar talisenq 1 relevance
                if ($relevance > 0 && $c_views > 2000) {
                    $relevance += ($c_comment + $c_likes) / 10;
                }
            }

            $index = [
                '_index' => Model::getPrefix() . $this->index,
                '_type' => $this->type,
                '_routing' => $id,
                '_id' => 'relevance_' . $id
            ];

            $new_data = ['relevance' => $relevance];

            $bulk = $bulk->addUpdate($index)->addDoc($new_data);
            $count++;
        }

        $bulk->runBulk();

        $this->info('Updated ' . $count . ' documents');

        $this->updateLog();
    }
}