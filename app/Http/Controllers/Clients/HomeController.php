<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Post;
use App\Services\Catalog\ProductCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;

class HomeController extends Controller
{
    /**
     * Cột sản phẩm cần thiết cho hiển thị thẻ sản phẩm (tránh load cột content nặng vào RAM)
     */
    const PRODUCT_CARD_COLUMNS = [
        'id', 'name', 'slug', 'sku', 'price', 'sale_price',
        'published_at', 'category_id', 'brand_id', 'status'
    ];

    public function index()
    {
        // 1. Banners: Query nhanh nhóm theo vị trí (chỉ select cột cần thiết, siêu tốc 0.1ms)
        $banners = Banner::where('status', 'active')
            ->select(['id', 'title', 'image', 'link', 'position', 'status'])
            ->orderBy('position', 'asc')
            ->get()
            ->groupBy('position');

        // 2. Category Sections: Tối ưu triệt để: chỉ query 10 IDs sản phẩm mới nhất cho 5 danh mục hàng đầu
        $categorySectionsData = Cache::remember('home_category_sections_map_v2', 3600, function () {
            $allCats = Category::where('status', 'active')
                ->where('type', 'product')
                ->select(['id', 'name', 'slug', 'parent_id', 'icon', 'banner', 'position'])
                ->orderBy('position', 'asc')
                ->get();

            if ($allCats->isEmpty()) {
                return [];
            }

            // Xây dựng bản đồ con cháu in-memory (0 query DB)
            $childrenMap = [];
            foreach ($allCats as $c) {
                $pId = $c->parent_id ?: 0;
                $childrenMap[$pId][] = $c->id;
            }

            $getDescendantIds = function ($parentId) use (&$getDescendantIds, &$childrenMap) {
                $ids = [$parentId];
                if (isset($childrenMap[$parentId])) {
                    foreach ($childrenMap[$parentId] as $childId) {
                        $ids = array_merge($ids, $getDescendantIds($childId));
                    }
                }
                return $ids;
            };

            // Đếm số sản phẩm từng danh mục (bao gồm cả danh mục chính & category_product pivot)
            $productCounts = ProductCatalogService::getProductCountsByCategory();

            $candidates = [];
            foreach ($allCats as $cat) {
                $allCatIds = $getDescendantIds($cat->id);
                $pCount = 0;
                foreach ($allCatIds as $cid) {
                    $pCount += $productCounts[$cid] ?? 0;
                }

                if ($pCount > 0) {
                    $candidates[] = [
                        'category' => [
                            'id' => $cat->id,
                            'name' => $cat->name,
                            'slug' => $cat->slug,
                            'icon' => $cat->icon,
                            'banner' => $cat->banner,
                        ],
                        'category_id' => $cat->id,
                        'cat_ids' => $allCatIds,
                        'count' => $pCount,
                        'is_child' => !is_null($cat->parent_id),
                    ];
                }
            }

            usort($candidates, function ($a, $b) {
                if ($a['is_child'] === $b['is_child']) {
                    return $b['count'] <=> $a['count'];
                }
                return $a['is_child'] ? -1 : 1;
            });

            $selected = array_slice($candidates, 0, 5);
            if (empty($selected)) {
                return [];
            }

            $result = [];
            foreach ($selected as $item) {
                $catIds = $item['cat_ids'];

                // Query trực tiếp 10 ID mới nhất có LIMIT, tận dụng index thay vì nạp toàn bộ sản phẩm vào RAM
                $pIds = Product::published()
                    ->where(function ($q) use ($catIds) {
                        $q->whereIn('category_id', $catIds)
                          ->orWhereExists(function ($sub) use ($catIds) {
                              $sub->selectRaw('1')
                                  ->from('category_product')
                                  ->whereColumn('category_product.product_id', 'products.id')
                                  ->whereIn('category_product.category_id', $catIds);
                          });
                    })
                    ->latest('published_at')
                    ->limit(10)
                    ->pluck('id')
                    ->toArray();

                if (!empty($pIds)) {
                    $result[] = [
                        'category_id' => $item['category_id'],
                        'category' => $item['category'],
                        'product_ids' => $pIds
                    ];
                }
            }

            return $result;
        });

        // 3. GOM TOÀN BỘ SẢN PHẨM DANH MỤC TRANG CHỦ VÀO 1 CÂU QUERY DUY NHẤT (Batch Fetching)
        $allNeededProductIds = [];
        if (!empty($categorySectionsData)) {
            foreach ($categorySectionsData as $sec) {
                if (!empty($sec['product_ids'])) {
                    $allNeededProductIds = array_merge($allNeededProductIds, $sec['product_ids']);
                }
            }
        }
        $allNeededProductIds = array_values(array_unique($allNeededProductIds));

        $allProductsKeyed = !empty($allNeededProductIds)
            ? Product::published()
                ->select(self::PRODUCT_CARD_COLUMNS)
                ->with('thumbnailMedia')
                ->whereIn('id', $allNeededProductIds)
                ->get()
                ->keyBy('id')
            : collect();

        // Gán Category Sections (0 query vào bảng categories!)
        $categorySections = collect();
        if (!empty($categorySectionsData)) {
            foreach ($categorySectionsData as $sec) {
                $secProducts = collect();
                $pIds = $sec['product_ids'] ?? [];
                foreach ($pIds as $pid) {
                    if (isset($allProductsKeyed[$pid])) {
                        $secProducts->push($allProductsKeyed[$pid]);
                    }
                }
                if ($secProducts->isNotEmpty()) {
                    $cData = is_array($sec['category']) ? $sec['category'] : (array)$sec['category'];
                    $categorySections->push((object)[
                        'category' => (object)$cData,
                        'products' => $secProducts
                    ]);
                }
            }
        }

        // 6. Blog Sections: Cache map ID & category meta
        $blogSectionsData = Cache::remember('home_blog_sections_map', 3600, function () {
            $postCats = Category::where('type', 'post')
                ->where('status', 'active')
                ->select(['id', 'name', 'slug'])
                ->take(4)
                ->get();

            $result = [];
            foreach ($postCats as $cat) {
                $postIds = Post::where('status', 'published')
                    ->where(function ($q) use ($cat) {
                        $q->where('category_id', $cat->id)
                          ->orWhereHas('categories', fn($cq) => $cq->where('categories.id', $cat->id));
                    })
                    ->latest('published_at')
                    ->take(3)
                    ->pluck('id')
                    ->toArray();

                if (!empty($postIds)) {
                    $result[] = [
                        'category' => [
                            'id' => $cat->id,
                            'name' => $cat->name,
                            'slug' => $cat->slug
                        ],
                        'post_ids' => $postIds
                    ];
                }
            }
            return $result;
        });

        $blogSections = collect();
        if (!empty($blogSectionsData)) {
            $allPostIds = [];
            foreach ($blogSectionsData as $bSec) {
                if (!empty($bSec['post_ids'])) {
                    $allPostIds = array_merge($allPostIds, $bSec['post_ids']);
                }
            }
            $allPostIds = array_values(array_unique($allPostIds));

            $allPosts = !empty($allPostIds)
                ? Post::whereIn('id', $allPostIds)
                    ->select(['id', 'title', 'slug', 'summary', 'published_at', 'category_id', 'created_at'])
                    ->with('thumbnailMedia')
                    ->get()
                    ->keyBy('id')
                : collect();

            foreach ($blogSectionsData as $bSec) {
                $catPosts = collect();
                $pIds = $bSec['post_ids'] ?? [];
                foreach ($pIds as $pid) {
                    if (isset($allPosts[$pid])) {
                        $catPosts->push($allPosts[$pid]);
                    }
                }
                if ($catPosts->isNotEmpty()) {
                    $catData = is_array($bSec['category']) ? $bSec['category'] : (array)$bSec['category'];
                    $blogSections->push((object)[
                        'id' => $catData['id'] ?? 0,
                        'name' => $catData['name'] ?? '',
                        'slug' => $catData['slug'] ?? '',
                        'posts' => $catPosts
                    ]);
                }
            }
        }

        // 5. Partner Brands (Đối tác hiển thị trên trang chủ - cache mảng thuần chuẩn Laravel 13)
        $partnerBrandsData = Cache::remember('home_partner_brands', 3600, function () {
            $prioritySlugs = [
                'autonics', 'omron', 'panasonic', 'pro-face', 'keyence', 'mitsubishi',
                'cognex', 'schneider', 'siemens', 'honeywell', 'delta', 'abb',
                'fuji', 'ls', 'smc', 'airtac', 'sick', 'festo',
            ];

            $brands = Brand::where('status', 'active')
                ->select(['id', 'name', 'slug', 'logo'])
                ->orderBy('name', 'asc')
                ->get();

            return $brands->sortBy(function ($brand) use ($prioritySlugs) {
                $idx = array_search(strtolower((string) $brand->slug), $prioritySlugs, true);
                return $idx === false ? 999 . '_' . strtolower((string) $brand->name) : sprintf('%03d', $idx);
            })->values()->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'logo' => $brand->logo,
                ];
            })->all();
        });

        $partnerBrands = collect(is_array($partnerBrandsData) ? $partnerBrandsData : [])
            ->map(fn ($item) => (object) (is_array($item) ? $item : (array) $item));

        $cachedHomePage = Cache::remember('home_page_role_data', 3600, function () {
            try {
                return Page::where('page_role', 'home')->first() ?? false;
            } catch (\Throwable $e) {
                return false;
            }
        });
        $homePage = $cachedHomePage === false ? null : $cachedHomePage;

        return view('clients.pages.home.index', compact(
            'homePage',
            'banners',
            'categorySections',
            'partnerBrands',
            'blogSections'
        ));
    }
}
