<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Alter products table
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_sku_unique');
            $table->string('sku')->nullable()->index()->change();

            $table->unsignedBigInteger('source_wp_id')->nullable()->unique()->after('id');
            $table->text('source_permalink')->nullable()->after('slug');
            $table->string('content_hash', 64)->nullable()->after('content');
            $table->longText('raw_content')->nullable()->after('content_hash');
            $table->text('raw_short_description')->nullable()->after('raw_content');
            $table->string('product_type', 50)->default('simple')->after('sku');
            $table->string('stock_status', 50)->default('instock')->after('product_type');
            $table->longText('attributes_json')->nullable()->after('stock_status');
            $table->string('meta_robots')->nullable()->after('meta_description');
            $table->string('canonical_url', 500)->nullable()->after('meta_robots');
            $table->string('og_title', 500)->nullable()->after('canonical_url');
            $table->text('og_description')->nullable()->after('og_title');
            $table->string('og_image', 500)->nullable()->after('og_description');
            $table->longText('schema_json')->nullable()->after('og_image');
            $table->boolean('in_sitemap')->default(true)->index()->after('schema_json');
            $table->unsignedSmallInteger('sitemap_chunk')->default(1)->index()->after('in_sitemap');
            $table->string('import_batch_id', 100)->nullable()->index()->after('sitemap_chunk');

            $table->index(['status', 'published_at', 'id'], 'idx_products_status_pub_id');
        });

        // 2. Alter brands table
        Schema::table('brands', function (Blueprint $table) {
            $table->unsignedBigInteger('source_wp_id')->nullable()->unique()->after('id');
            $table->string('source_taxonomy', 50)->default('thuong_hieu')->after('source_wp_id');
            $table->text('source_permalink')->nullable()->after('slug');
            $table->string('meta_robots')->nullable()->after('meta_description');
            $table->string('canonical_url', 500)->nullable()->after('meta_robots');
            $table->string('og_title', 500)->nullable()->after('canonical_url');
            $table->text('og_description')->nullable()->after('og_title');
            $table->string('og_image', 500)->nullable()->after('og_description');
            $table->longText('schema_json')->nullable()->after('og_image');
            $table->string('status', 20)->default('active')->index()->after('schema_json');
            $table->boolean('in_sitemap')->default(true)->index()->after('status');
            $table->string('import_batch_id', 100)->nullable()->index()->after('in_sitemap');
        });

        // 3. Create category_product table (N:N relationship)
        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['product_id', 'category_id']);
            $table->index(['category_id', 'product_id'], 'idx_cat_prod_lookup');
        });

        // 4. Create product_tags table
        Schema::create('product_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('products_count_source')->default(0);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_robots')->nullable();
            $table->string('canonical_url')->nullable();
            $table->unsignedBigInteger('source_wp_id')->nullable()->unique();
            $table->string('source_permalink')->nullable();
            $table->boolean('in_sitemap')->default(false);
            $table->string('status', 20)->default('active');
            $table->string('import_batch_id', 100)->nullable()->index();
            $table->timestamps();
        });

        // 5. Create product_product_tag pivot table
        Schema::create('product_product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_tag_id')->constrained('product_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['product_id', 'product_tag_id'], 'pk_prod_prod_tag');
            $table->index(['product_tag_id', 'product_id'], 'idx_prod_tag_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_product_tag');
        Schema::dropIfExists('product_tags');
        Schema::dropIfExists('category_product');

        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['in_sitemap']);
            $table->dropIndex(['import_batch_id']);
            $table->dropColumn([
                'source_wp_id',
                'source_taxonomy',
                'source_permalink',
                'meta_robots',
                'canonical_url',
                'og_title',
                'og_description',
                'og_image',
                'schema_json',
                'status',
                'in_sitemap',
                'import_batch_id',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_status_pub_id');
            $table->dropIndex(['sku']);
            $table->dropIndex(['in_sitemap']);
            $table->dropIndex(['sitemap_chunk']);
            $table->dropIndex(['import_batch_id']);
            $table->dropColumn([
                'source_wp_id',
                'source_permalink',
                'content_hash',
                'raw_content',
                'raw_short_description',
                'product_type',
                'stock_status',
                'attributes_json',
                'meta_robots',
                'canonical_url',
                'og_title',
                'og_description',
                'og_image',
                'schema_json',
                'in_sitemap',
                'sitemap_chunk',
                'import_batch_id',
            ]);
            $table->string('sku')->unique()->change();
        });
    }
};
