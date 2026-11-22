<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationRun extends Model
{
    protected $fillable = [
        'run_id',
        'snapshot_id',
        'snapshot_checksum',
        'environment',
        'database_name',
        'mode',
        'status',
        'backup_checkpoint_id',
        'counters_json',
        'conflicts_json',
        'errors_json',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];
}
