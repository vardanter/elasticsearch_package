<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 28/02/2018
 * Time: 18:22
 */

namespace App\Elasticsearch\Commands;


use App\Channel;
use App\Elasticsearch\Elasticsearch;
use App\Elasticsearch\Eloquent\Model;
use App\UserChannelSubscribe;
use App\VideoTape;
use Illuminate\Console\Command;

class ElasticsearchBulkChannels extends Command
{

    private static $from = 0;

    private static $step = 5000;

    protected $signature = "elasticsearch:bulk:channels";

    protected $description = "Bulk insert all Channels into elasticsearch channels index";

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $channels = $this->getChannels();

        if (empty($channels) || count($channels) == 0) {
            return;
        }

        $bulks = [];

        $count = 0;

        foreach ($channels as $channel) {
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'channels',
                    '_type' => 'channel',
                    '_id' => $channel->id,
                    '_routing' => $channel->id,
                ]
            ];
            $data = $channel->getAttributes();

            $data['booster'] = [];
            $data['relevance'] = 0;
            $data['relations'] = 'channel';

            $count++;
            $bulks['body'][$count] = $data;


            //WATCH_COUNT START
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'channels',
                    '_type' => 'channel',
                    '_id' => 'wc_' . $channel->id,
                    '_routing' => $channel->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $channel->id;
            $data_child['watch_count'] = $channel->watch_count;
            $data_child['relations'] = [
                'name' => 'watch_count',
                'parent' => $channel->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //WATCH_COUNT END

            //RELEVANCE START
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'channels',
                    '_type' => 'channel',
                    '_id' => 'relevance_' . $channel->id,
                    '_routing' => $channel->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $channel->id;
            $data_child['relevance'] = $data['relevance'];
            $data_child['relations'] = [
                'name' => 'relevance',
                'parent' => $channel->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //RELEVANCE END


            //SUBSCRIBERS START
            $count++;
            $bulks['body'][$count] = [
                'index' => [
                    '_index' => Model::getPrefix() . 'channels',
                    '_type' => 'channel',
                    '_id' => 'sub_' . $channel->id,
                    '_routing' => $channel->id,
                ]
            ];
            $data_child = [];
            $data_child['id'] = $channel->id;
            $data_child['subscribers'] = $data['subscribers'];
            $data_child['relations'] = [
                'name' => 'subscribers',
                'parent' => $channel->id
            ];

            $count++;
            $bulks['body'][$count] = $data_child;
            //SUBSCRIBERS END

            $count++;
        }

        $responses = Elasticsearch::getConnection()->bulk($bulks);

        unset($data);
        unset($bulks);
        unset($responses);
        unset($channels);

        $this->error("FROM : " . static::$from);
        $this->error("TO   : " . static::$step);
        static::$from += static::$step;
        $this->line( "MEMORY USAGE : " . memory_get_usage(true) . PHP_EOL);
        $this->handle();
    }

    private function getChannels()
    {
        $channels = Channel::leftJoin('video_tapes','video_tapes.channel_id','=','channels.id')
            ->select('channels.*')
            ->addSelect(\DB::raw('SUM(video_tapes.watch_count) as watch_count'))
            ->groupBy('channels.id')
            ->orderBy('watch_count','desc')
            ->skip(static::$from)
            ->limit(static::$step)
            ->get();

        $channel_ids = [];
        foreach ($channels as $channel) {
            $channel_ids[] = $channel->id;
        }

        $channel_subscribers = UserChannelSubscribe::select(\DB::raw('user_channel_subscribes.channel_id as id, count(user_channel_subscribes.id) as count, users.status as user_status'))
            ->leftJoin('users','users.id','=','user_channel_subscribes.user_id')
            ->where('users.status',1)
            ->whereIn('channel_id',$channel_ids)
            ->groupBy('channel_id')
            ->get();

        $subscribers = [];
        foreach($channel_subscribers as $channel_subscriber) {
            $subscribers[$channel_subscriber->id]['subscribers'] = $channel_subscriber->count;
            $subscribers[$channel_subscriber->id]['user_status'] = $channel_subscriber->user_status;
        }

        foreach($channels as &$channel) {
            $channel->user_status = isset($subscribers[$channel->id]['user_status']) ? $subscribers[$channel->id]['user_status'] : 0;
            $channel->subscribers = isset($subscribers[$channel->id]['subscribers']) ? $subscribers[$channel->id]['subscribers'] : 0;
        }


        return $channels;
    }
}