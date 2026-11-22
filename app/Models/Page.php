<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'parent_id',
        'title',
        'slug',
        'full_path_hash',
        'full_path',
        'page_role',
        'excerpt',
        'content',
        'raw_content',
        'template',
        'menu_order',
        'author_display_name',
        'featured_media_id',
        'meta_title',
        'meta_description',
        'meta_robots',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'og_type',
        'schema_json',
        'source_wp_id',
        'source_permalink',
        'content_hash',
        'import_batch_id',
        'has_placeholder_flag',
        'has_unrendered_shortcode',
        'in_sitemap',
        'status',
        'published_at',
        'source_modified_at',
    ];

    protected $casts = [
        'published_at'             => 'datetime',
        'source_modified_at'       => 'datetime',
        'has_placeholder_flag'     => 'boolean',
        'has_unrendered_shortcode' => 'boolean',
        'in_sitemap'               => 'boolean',
        'menu_order'               => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Page::class, 'parent_id')->orderBy('menu_order');
    }

    public function featuredMedia()
    {
        return $this->belongsTo(Media::class, 'featured_media_id');
    }
}
