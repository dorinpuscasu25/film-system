<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'storage_zone_name',
    'date',
    'used_bytes',
    'files_count',
    'synced_at',
])]
class BunnyStorageSnapshot extends Model
{
    protected $connection = 'analytics';

    protected $table = 'bunny_storage_snapshots';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'synced_at' => 'datetime',
        ];
    }
}
