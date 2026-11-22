<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend media table with WordPress attachment traceability
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'wp_attachment_id')) {
                $table->unsignedBigInteger('wp_attachment_id')->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('media', 'wp_source_url')) {
                $table->string('wp_source_url', 500)->nullable()->after('filename');
            }
            if (!Schema::hasColumn('media', 'wp_relative_path')) {
                $table->string('wp_relative_path', 500)->nullable()->after('wp_source_url');
            }
            if (!Schema::hasColumn('media', 'sha256')) {
                $table->char('sha256', 64)->nullable()->after('checksum')->index();
            }
            if (!Schema::hasColumn('media', 'caption')) {
                $table->text('caption')->nullable()->after('alt');
            }
            if (!Schema::hasColumn('media', 'import_batch_id')) {
                $table->string('import_batch_id', 80)->nullable()->after('status')->index();
            }
        });

        // 2. Create pages table (WordPress post_type=page)
        if (!Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->string('title', 500)->default('');
                $table->string('slug', 191)->index();
                $table->char('full_path_hash', 64)->unique();
                $table->string('full_path', 500);
                $table->string('page_role', 40)->default('standard')->index();
                $table->text('excerpt')->nullable();
                $table->longText('content')->nullable();
                $table->longText('raw_content')->nullable();
                $table->string('template', 191)->nullable();
                $table->integer('menu_order')->default(0);
                $table->string('author_display_name', 191)->nullable();
                $table->foreignId('featured_media_id')->nullable()->constrained('media')->nullOnDelete();

                // SEO & OpenGraph
                $table->string('meta_title', 500)->nullable();
                $table->text('meta_description')->nullable();
                $table->string('meta_robots', 255)->nullable();
                $table->string('canonical_url', 500)->nullable();
                $table->string('og_title', 500)->nullable();
                $table->text('og_description')->nullable();
                $table->string('og_image', 500)->nullable();
                $table->string('og_type', 60)->nullable();
                $table->longText('schema_json')->nullable();

                // Source traceability
                $table->unsignedBigInteger('source_wp_id')->nullable()->unique();
                $table->string('source_permalink', 500)->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->string('import_batch_id', 80)->nullable()->index();
                $table->boolean('has_placeholder_flag')->default(false);
                $table->boolean('has_unrendered_shortcode')->default(false);
                $table->boolean('in_sitemap')->default(true)->index();

                $table->string('status', 30)->default('published')->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamp('source_modified_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Extend categories table (composite unique type+slug + SEO + WP traceability)
        Schema::table('categories', function (Blueprint $table) {
            try {
                $table->dropUnique('categories_slug_unique');
            } catch (\Throwable $e) {
                // Already dropped or different driver
            }
            $table->unique(['type', 'slug'], 'uq_categories_type_slug');

            if (!Schema::hasColumn('categories', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (!Schema::hasColumn('categories', 'meta_title')) {
                $table->string('meta_title', 500)->nullable()->after('content');
                $table->text('meta_description')->nullable()->after('meta_title');
                $table->string('meta_robots', 255)->nullable()->after('meta_description');
                $table->string('canonical_url', 500)->nullable()->after('meta_robots');
                $table->string('og_title', 500)->nullable()->after('canonical_url');
                $table->text('og_description')->nullable()->after('og_title');
                $table->string('og_image', 500)->nullable()->after('og_description');
                $table->longText('schema_json')->nullable()->after('og_image');
            }
            if (!Schema::hasColumn('categories', 'source_wp_id')) {
                $table->unsignedBigInteger('source_wp_id')->nullable()->index();
                $table->string('source_taxonomy', 50)->nullable()->index();
                $table->string('source_permalink', 500)->nullable();
                $table->boolean('in_sitemap')->default(true)->index();
                $table->string('import_batch_id', 80)->nullable()->index();
                $table->unique(['source_taxonomy', 'source_wp_id'], 'uq_categories_wp_tax_id');
            }
        });

        // 4. Extend posts table (flexible status + full RankMath SEO + WP traceability)
        Schema::table('posts', function (Blueprint $table) {
            $table->string('status', 30)->default('published')->change();

            if (!Schema::hasColumn('posts', 'raw_content')) {
                $table->longText('raw_content')->nullable()->after('content');
            }
            if (!Schema::hasColumn('posts', 'author_display_name')) {
                $table->string('author_display_name', 191)->nullable()->after('author_id');
            }
            if (!Schema::hasColumn('posts', 'meta_robots')) {
                $table->string('meta_robots', 255)->nullable()->after('meta_description');
                $table->string('canonical_url', 500)->nullable()->after('meta_robots');
                $table->string('og_title', 500)->nullable()->after('canonical_url');
                $table->text('og_description')->nullable()->after('og_title');
                $table->string('og_image', 500)->nullable()->after('og_description');
                $table->string('og_type', 60)->nullable()->after('og_image');
                $table->longText('schema_json')->nullable()->after('og_type');
            }
            if (!Schema::hasColumn('posts', 'source_wp_id')) {
                $table->unsignedBigInteger('source_wp_id')->nullable()->unique();
                $table->string('source_permalink', 500)->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->boolean('in_sitemap')->default(true)->index();
                $table->string('conflict_status', 60)->nullable()->index();
                $table->string('import_batch_id', 80)->nullable()->index();
                $table->timestamp('source_modified_at')->nullable();
            }
        });

        // 5. Many-to-many post <-> category pivot
        if (!Schema::hasTable('category_post')) {
            Schema::create('category_post', function (Blueprint $table) {
                $table->id();
                $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->unique(['post_id', 'category_id'], 'uq_category_post');
            });
        }

        // 6. Tags & post_tag pivot
        if (!Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('slug', 191)->unique();
                $table->text('description')->nullable();
                $table->unsignedInteger('posts_count_source')->default(0);
                $table->string('meta_title', 500)->nullable();
                $table->text('meta_description')->nullable();
                $table->string('meta_robots', 255)->default('noindex, follow');
                $table->string('canonical_url', 500)->nullable();
                $table->longText('schema_json')->nullable();
                $table->unsignedBigInteger('source_wp_id')->nullable()->unique();
                $table->string('source_permalink', 500)->nullable();
                $table->boolean('in_sitemap')->default(false)->index();
                $table->string('import_batch_id', 80)->nullable()->index();
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('post_tag')) {
            Schema::create('post_tag', function (Blueprint $table) {
                $table->id();
                $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
                $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['post_id', 'tag_id'], 'uq_post_tag');
            });
        }

        // 7. Navigation menus & menu items (Header / Footer)
        if (!Schema::hasTable('navigation_menus')) {
            Schema::create('navigation_menus', function (Blueprint $table) {
                $table->id();
                $table->string('code', 60)->unique();
                $table->string('name', 191);
                $table->string('location', 60)->index();
                $table->string('status', 30)->default('active');
                $table->string('import_batch_id', 80)->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('navigation_menu_items')) {
            Schema::create('navigation_menu_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('menu_id')->constrained('navigation_menus')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('navigation_menu_items')->nullOnDelete();
                $table->string('title', 255);
                $table->string('url', 500);
                $table->string('target', 20)->default('_self');
                $table->string('rel', 100)->nullable();
                $table->integer('position')->default(0)->index();
                $table->string('entity_type', 40)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        // 8. Exact Source URL Route Registry
        if (!Schema::hasTable('source_url_routes')) {
            Schema::create('source_url_routes', function (Blueprint $table) {
                $table->id();
                $table->char('path_hash', 64)->unique();
                $table->char('unslashed_hash', 64)->index();
                $table->string('normalized_path', 500);
                $table->string('unslashed_path', 500);
                $table->string('entity_type', 50)->index();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->string('source_wp_id', 80)->nullable()->index();
                $table->unsignedSmallInteger('http_status')->default(200)->index();
                $table->string('redirect_target', 500)->nullable();
                $table->string('trailing_slash_policy', 25)->default('require_slash');
                $table->string('canonical_url', 500)->nullable();
                $table->string('in_sitemap', 60)->nullable()->index();
                $table->boolean('is_cutover_ready')->default(true)->index();
                $table->string('conflict_note', 500)->nullable();
                $table->string('source_snapshot_id', 80)->index();
                $table->string('import_batch_id', 80)->nullable()->index();
                $table->timestamps();
            });
        }

        // 9. Migration Runs Audit Log
        if (!Schema::hasTable('migration_runs')) {
            Schema::create('migration_runs', function (Blueprint $table) {
                $table->id();
                $table->string('run_id', 80)->unique();
                $table->string('snapshot_id', 80)->index();
                $table->char('snapshot_checksum', 64)->nullable();
                $table->string('environment', 40);
                $table->string('database_name', 80);
                $table->string('mode', 20);
                $table->string('status', 30)->index();
                $table->string('backup_checkpoint_id', 100)->nullable();
                $table->longText('counters_json')->nullable();
                $table->longText('conflicts_json')->nullable();
                $table->longText('errors_json')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_runs');
        Schema::dropIfExists('source_url_routes');
        Schema::dropIfExists('navigation_menu_items');
        Schema::dropIfExists('navigation_menus');
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('category_post');
        Schema::dropIfExists('pages');

        Schema::table('posts', function (Blueprint $table) {
            foreach ([
                'raw_content', 'author_display_name', 'meta_robots', 'canonical_url',
                'og_title', 'og_description', 'og_image', 'og_type', 'schema_json',
                'source_wp_id', 'source_permalink', 'content_hash', 'in_sitemap',
                'conflict_status', 'import_batch_id', 'source_modified_at'
            ] as $col) {
                if (Schema::hasColumn('posts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            try {
                $table->dropUnique('uq_categories_wp_tax_id');
                $table->dropUnique('uq_categories_type_slug');
            } catch (\Throwable $e) {
            }
            foreach ([
                'description', 'meta_title', 'meta_description', 'meta_robots',
                'canonical_url', 'og_title', 'og_description', 'og_image', 'schema_json',
                'source_wp_id', 'source_taxonomy', 'source_permalink', 'in_sitemap', 'import_batch_id'
            ] as $col) {
                if (Schema::hasColumn('categories', $col)) {
                    $table->dropColumn($col);
                }
            }
            $table->unique('slug', 'categories_slug_unique');
        });

        Schema::table('media', function (Blueprint $table) {
            foreach (['wp_attachment_id', 'wp_source_url', 'wp_relative_path', 'sha256', 'caption', 'import_batch_id'] as $col) {
                if (Schema::hasColumn('media', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
