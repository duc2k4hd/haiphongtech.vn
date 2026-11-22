<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Media;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'sku', 'price', 'sale_price', 'short_description', 'content',
        'category_id', 'brand_id', 'series_id', 'views_count', 'rating_average',
        'reviews_count', 'meta_title', 'meta_description', 'status', 'published_at',
        'source_wp_id', 'source_permalink', 'content_hash', 'raw_content',
        'raw_short_description', 'product_type', 'stock_status', 'attributes_json',
        'meta_robots', 'canonical_url', 'og_title', 'og_description', 'og_image',
        'schema_json', 'in_sitemap', 'sitemap_chunk', 'import_batch_id',
    ];

    protected $casts = [
        'published_at'    => 'datetime',
        'in_sitemap'      => 'boolean',
        'attributes_json' => 'array',
        'price'           => 'decimal:2',
        'sale_price'      => 'decimal:2',
    ];

    public function category() { return $this->belongsTo(Category::class); }
    public function brand() { return $this->belongsTo(Brand::class); }
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withPivot('is_primary')
            ->withTimestamps();
    }
    public function productTags()
    {
        return $this->belongsToMany(ProductTag::class, 'product_product_tag')
            ->withTimestamps();
    }

    /**
     * Dòng/Series mà sản phẩm này thuộc về.
     */
    public function series()
    {
        return $this->belongsTo(Series::class);
    }

    /**
     * Get the thumbnail media for the product.
     */
    public function thumbnailMedia()
    {
        return $this->belongsToMany(Media::class, 'product_media')
            ->wherePivot('role', 'thumbnail')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * Get the gallery media for the product.
     */
    public function galleryMedia()
    {
        return $this->belongsToMany(Media::class, 'product_media')
            ->wherePivot('role', 'gallery')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * Get the catalog media for the product.
     */
    public function catalogMedia()
    {
        return $this->belongsToMany(Media::class, 'product_media')
            ->wherePivot('role', 'catalog')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * Backward compatibility or generic all media getter.
     */
    public function images()
    {
        return $this->belongsToMany(Media::class, 'product_media')
            ->withPivot('role', 'position')
            ->orderByPivot('position');
    }

    /**
     * Scope query lấy các sản phẩm đã xuất bản (active & published_at <= now theo giờ Việt Nam).
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', \Carbon\Carbon::now('Asia/Ho_Chi_Minh'));
    }

    /**
     * Giới hạn an toàn tối đa 500 ký tự theo độ dài cột database VARCHAR(500).
     */
    public function setNameAttribute($value): void
    {
        $this->attributes['name'] = $value !== null ? mb_substr(trim((string)$value), 0, 500) : null;
    }
}