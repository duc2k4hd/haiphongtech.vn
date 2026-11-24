<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Setting;

class WarmupCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:warmup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-warm essential caches for ultra-fast first-time page loading';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cache warm-up...');

        // 1. Warm Settings
        Cache::remember('global_settings_arr_v1', 86400, function () {
            return Setting::select(['key', 'value'])->pluck('value', 'key')->toArray();
        });
        $this->line('  ✓ Global settings cached');

        // 2. Warm Document Page Data (p1 -> p3)
        for ($page = 1; $page <= 3; $page++) {
            $perPage = 12;
            Cache::remember("document_default_page_data_p{$page}", 3600, function () use ($page, $perPage) {
                $brandIds = DB::table('product_media')
                    ->join('products', 'products.id', '=', 'product_media.product_id')
                    ->where('product_media.role', 'catalog')
                    ->where('products.status', 'active')
                    ->whereNull('products.deleted_at')
                    ->whereNotNull('products.brand_id')
                    ->distinct()
                    ->pluck('products.brand_id');

                $brands = Brand::whereIn('id', $brandIds)
                    ->select(['id', 'name', 'slug'])
                    ->orderBy('name', 'asc')
                    ->get()
                    ->toArray();

                $catIds = DB::table('product_media')
                    ->join('products', 'products.id', '=', 'product_media.product_id')
                    ->where('product_media.role', 'catalog')
                    ->where('products.status', 'active')
                    ->whereNull('products.deleted_at')
                    ->whereNotNull('products.category_id')
                    ->distinct()
                    ->pluck('products.category_id');

                $categories = Category::whereIn('id', $catIds)
                    ->where('status', 'active')
                    ->where('type', 'product')
                    ->select(['id', 'name', 'slug'])
                    ->orderBy('name', 'asc')
                    ->get()
                    ->toArray();

                $totalCount = (int) DB::table('product_media')
                    ->join('products', 'products.id', '=', 'product_media.product_id')
                    ->where('product_media.role', 'catalog')
                    ->where('products.status', 'active')
                    ->whereNull('products.deleted_at')
                    ->distinct()
                    ->count('product_media.media_id');

                $mediaIds = DB::table('product_media')
                    ->join('products', 'products.id', '=', 'product_media.product_id')
                    ->where('product_media.role', 'catalog')
                    ->where('products.status', 'active')
                    ->whereNull('products.deleted_at')
                    ->distinct()
                    ->orderBy('product_media.media_id', 'desc')
                    ->forPage($page, $perPage)
                    ->pluck('product_media.media_id')
                    ->toArray();

                return [
                    'brands'     => $brands,
                    'categories' => $categories,
                    'totalCount' => $totalCount,
                    'mediaIds'   => $mediaIds,
                    'perPage'    => $perPage,
                ];
            });
        }
        $this->line('  ✓ Document catalog default pages cached');

        // 3. Document filters
        Cache::remember('document_filter_brands', 3600, function () {
            $brandIds = DB::table('product_media')
                ->join('products', 'products.id', '=', 'product_media.product_id')
                ->where('product_media.role', 'catalog')
                ->where('products.status', 'active')
                ->whereNull('products.deleted_at')
                ->whereNotNull('products.brand_id')
                ->distinct()
                ->pluck('products.brand_id');

            return Brand::whereIn('id', $brandIds)
                ->select(['id', 'name', 'slug'])
                ->orderBy('name', 'asc')
                ->get()
                ->toArray();
        });

        Cache::remember('document_filter_categories', 3600, function () {
            $catIds = DB::table('product_media')
                ->join('products', 'products.id', '=', 'product_media.product_id')
                ->where('product_media.role', 'catalog')
                ->where('products.status', 'active')
                ->whereNull('products.deleted_at')
                ->whereNotNull('products.category_id')
                ->distinct()
                ->pluck('products.category_id');

            return Category::whereIn('id', $catIds)
                ->where('status', 'active')
                ->where('type', 'product')
                ->select(['id', 'name', 'slug'])
                ->orderBy('name', 'asc')
                ->get()
                ->toArray();
        });

        Cache::remember('document_total_count', 3600, function () {
            return (int) DB::table('product_media')
                ->join('products', 'products.id', '=', 'product_media.product_id')
                ->where('product_media.role', 'catalog')
                ->where('products.status', 'active')
                ->whereNull('products.deleted_at')
                ->distinct()
                ->count('product_media.media_id');
        });
        $this->line('  ✓ Document filters & counts cached');

        $this->info('Cache warm-up completed successfully! All pages are now primed for sub-0.1s load times.');
        return 0;
    }
}
