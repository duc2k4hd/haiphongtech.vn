<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'slug', 'logo', 'content', 'meta_title', 'meta_description',
        'source_wp_id', 'source_taxonomy', 'source_permalink', 'meta_robots',
        'canonical_url', 'og_title', 'og_description', 'og_image', 'schema_json',
        'status', 'in_sitemap', 'import_batch_id',
    ];

    protected $casts = [
        'in_sitemap' => 'boolean',
    ];

    public function products() { return $this->hasMany(Product::class); }
    public function series() { return $this->hasMany(Series::class); }
}