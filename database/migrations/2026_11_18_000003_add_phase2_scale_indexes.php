<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['status', 'brand_id', 'published_at', 'id'], 'idx_products_status_brand_pub_id');
            $table->index(['status', 'category_id', 'id'], 'idx_products_status_cat_id');
            $table->index(['in_sitemap', 'status', 'sitemap_chunk', 'id'], 'idx_products_sitemap_shard');
        });

        Schema::table('product_tags', function (Blueprint $table) {
            $table->index(['status', 'products_count_source'], 'idx_product_tags_status_count');
        });
    }

    public function down(): void
    {
        Schema::table('product_tags', function (Blueprint $table) {
            $table->dropIndex('idx_product_tags_status_count');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_sitemap_shard');
            $table->dropIndex('idx_products_status_cat_id');
            $table->dropIndex('idx_products_status_brand_pub_id');
        });
    }
};
