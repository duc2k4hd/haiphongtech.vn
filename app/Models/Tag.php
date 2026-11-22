<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'posts_count_source',
        'meta_title',
        'meta_description',
        'meta_robots',
        'canonical_url',
        'schema_json',
        'source_wp_id',
        'source_permalink',
        'in_sitemap',
        'import_batch_id',
        'status',
    ];

    protected $casts = [
        'posts_count_source' => 'integer',
        'in_sitemap'         => 'boolean',
    ];

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'post_tag')->withTimestamps();
    }
}
