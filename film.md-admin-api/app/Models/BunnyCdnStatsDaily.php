<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'pull_zone_id',
    'date',
    'bandwidth_bytes',
    'origin_bandwidth_bytes',
    'requests_served',
    'cache_hit_rate',
    'avg_response_time_ms',
    'geo_breakdown',
    'synced_at',
])]
class BunnyCdnStatsDaily extends Model
{
    protected $connection = 'analytics';

    protected $table = 'bunny_cdn_stats_daily';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'synced_at' => 'datetime',
            'geo_breakdown' => 'array',
            'cache_hit_rate' => 'float',
        ];
    }
}
