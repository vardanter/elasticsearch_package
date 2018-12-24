<?php
/**
 * Created by PhpStorm.
 * User: NUM-11
 * Date: 10/03/2018
 * Time: 01:14
 */

namespace App\Elasticsearch\Commands\Crons;


use App\ElasticsearchBulkCron;
use Illuminate\Console\Command;

class ParentCron extends Command
{
    const VIDEO_WATCH_COUNT         = 1;
    const AD_VIDEO_WATCH_COUNT      = 2;
    const CHANNEL_WATCH_COUNT       = 3;
    const CHANNEL_SUBSCRIBERS       = 4;
    const VIDEO_COMMENT_COUNT       = 5;
    const AD_VIDEO_COMMENT_COUNT    = 6;
    const VIDEO_LIKE_DISLIKE        = 7;
    const AD_VIDEO_LIKE_DISLIKE     = 8;
    const VIDEO_RELEVANCE           = 9;
    const AD_VIDEO_RELEVANCE        = 10;
    const AD_VIDEO_AMOUNTS          = 11;
    const CHANNEL_RELEVANCE         = 12;
    const PLAYLIST_RELEVANCE        = 13;
    const PLAYLIST_WATCH_COUNT      = 14;

    protected $log_obj = null;

    protected $log_type = null;

    protected $index = null;

    protected $type = null;

    protected function createLog()
    {
        $this->log_obj = new ElasticsearchBulkCron();
        $this->log_obj->type = $this->log_type;
    }

    protected function updateLog()
    {
        $this->log_obj->updated_at = date('Y-m-d H:i:s', time());
        $this->log_obj->save();
    }

    protected function getLastTime()
    {
        if (empty($this->log_obj)) {
            $this->createLog();
            $last_time = date('Y-m-d H:i:s', strtotime('0000-00-00 00:00:00'));
        } else {
            $last_time = $this->log_obj->updated_at;
        }
        return $last_time;
    }

    protected function getLogObj()
    {
        return ElasticsearchBulkCron::where('type', $this->log_type)->first();
    }
}