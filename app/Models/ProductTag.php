<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'products_count_source',
        'meta_title',
        'meta_description',
        'meta_robots',
        'canonical_url',
        'source_wp_id',
        'source_permalink',
        'in_sitemap',
        'status',
        'import_batch_id',
    ];

    protected $casts = [
        'in_sitemap' => 'boolean',
        'products_count_source' => 'integer',
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_product_tag')->withTimestamps();
    }
}
