<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'content_id',
    'content_format_id',
    'bunny_library_id',
    'bunny_video_id',
    'date',
    'views',
    'watch_time_seconds',
    'plays',
    'finishes',
    'bandwidth_bytes',
    'country_breakdown',
    'synced_at',
])]
class BunnyStreamStatsDaily extends Model
{
    protected $connection = 'analytics';

    protected $table = 'bunny_stream_stats_daily';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'synced_at' => 'datetime',
            'country_breakdown' => 'array',
        ];
    }
}
