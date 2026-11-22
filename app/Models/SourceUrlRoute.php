<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceUrlRoute extends Model
{
    protected $fillable = [
        'path_hash',
        'unslashed_hash',
        'normalized_path',
        'unslashed_path',
        'entity_type',
        'entity_id',
        'source_wp_id',
        'http_status',
        'redirect_target',
        'trailing_slash_policy',
        'canonical_url',
        'in_sitemap',
        'is_cutover_ready',
        'conflict_note',
        'source_snapshot_id',
        'import_batch_id',
    ];

    protected $casts = [
        'http_status'      => 'integer',
        'is_cutover_ready' => 'boolean',
    ];

    public static function normalizePath(string $rawPath): string
    {
        $decoded = rawurldecode($rawPath);
        $trimmed = trim($decoded, '/');
        if ($trimmed === '') {
            return '/';
        }
        return '/' . mb_strtolower($trimmed, 'UTF-8') . '/';
    }

    public static function unslashedPath(string $rawPath): string
    {
        $decoded = rawurldecode($rawPath);
        $trimmed = trim($decoded, '/');
        if ($trimmed === '') {
            return '/';
        }
        return '/' . mb_strtolower($trimmed, 'UTF-8');
    }
}
