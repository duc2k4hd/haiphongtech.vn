<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'title', 'slug', 'summary', 'content', 'raw_content', 'author_id', 'author_display_name',
        'category_id', 'views_count', 'meta_title', 'meta_description', 'meta_robots',
        'canonical_url', 'og_title', 'og_description', 'og_image', 'og_type', 'schema_json',
        'source_wp_id', 'source_permalink', 'content_hash', 'in_sitemap', 'conflict_status',
        'import_batch_id', 'status', 'published_at', 'source_modified_at'
    ];

    protected $casts = [
        'published_at'       => 'datetime',
        'source_modified_at' => 'datetime',
        'in_sitemap'         => 'boolean',
    ];

    public function category() { return $this->belongsTo(Category::class); }
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_post')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tag')->withTimestamps();
    }
    public function author() { return $this->belongsTo(User::class, 'author_id'); }

    /**
     * Get all media associated with the post.
     */
    public function media()
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->withPivot('role', 'position')
            ->withTimestamps();
    }

    /**
     * Get just the thumbnail media.
     */
    public function thumbnailMedia()
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->wherePivot('role', 'thumbnail')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * Get content/gallery media.
     */
    public function contentMedia()
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->wherePivot('role', 'content_image')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position', 'asc');
    }
}