<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\WpLengthAwarePaginator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductCatalogService
{
    public const PER_PAGE = 24;

    public const PRODUCT_CARD_COLUMNS = [
        'id', 'name', 'slug', 'sku', 'price', 'sale_price',
        'category_id', 'brand_id',
        'short_description', 'published_at', 'status',
    ];

    /**
     * Build a filtered + sorted base Product query supporting Shop, Category (via primary OR category_product pivot),
     * Brand, and ProductTag archives cleanly in a single code path.
     */
    public function buildQuery(
        Request $request,
        ?array $forcedCategoryIds = null,
        ?int $forcedBrandId = null,
        ?int $forcedProductTagId = null,
        bool $includeSearchKeywordParam = true
    ): Builder {
        $query = Product::published();

        // Base taxonomy constraints (from archive route context)
        if ($forcedCategoryIds !== null) {
            $this->applyCategoryFilter($query, $forcedCategoryIds);
        } elseif ($request->filled('category')) {
            $catSlug = (string) $request->input('category');
            $descMap = Category::getDescendantsMap('product');
            $catId = $descMap['slug_to_id'][$catSlug] ?? null;
            if ($catId && isset($descMap['descendants'][$catId])) {
                $this->applyCategoryFilter($query, $descMap['descendants'][$catId]);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($forcedBrandId !== null) {
            $query->where('products.brand_id', $forcedBrandId);
        }

        if ($forcedProductTagId !== null) {
            $query->whereExists(function ($sub) use ($forcedProductTagId) {
                $sub->selectRaw('1')
                    ->from('product_product_tag')
                    ->whereColumn('product_product_tag.product_id', 'products.id')
                    ->where('product_product_tag.product_tag_id', $forcedProductTagId);
            });
        }

        // 1. Search term ('tim-kiem' or 'q')
        $searchInput = '';
        if ($includeSearchKeywordParam && $request->filled('tim-kiem')) {
            $searchInput = trim((string) $request->input('tim-kiem'));
        } elseif ($request->filled('q')) {
            $searchInput = trim((string) $request->input('q'));
        }

        $hasSearch = $searchInput !== '';
        if ($hasSearch) {
            $words = array_values(array_filter(explode(' ', $searchInput)));
            $query->where(function ($q) use ($searchInput, $words) {
                $q->where('products.name', 'like', '%' . $searchInput . '%')
                    ->orWhere('products.sku', 'like', $searchInput . '%');

                if (count($words) > 1) {
                    $q->orWhere(function ($subQ) use ($words) {
                        foreach ($words as $word) {
                            $subQ->orWhere('products.name', 'like', '%' . $word . '%');
                        }
                    });
                }
            });
        }

        // 2. Brands query filter (supports CSV string or array, merged safely with optional forced brand)
        if ($forcedBrandId === null && $request->filled('brands')) {
            $rawBrands = $request->input('brands');
            $brands = is_array($rawBrands) ? $rawBrands : array_filter(explode(',', (string) $rawBrands));
            if (!empty($brands)) {
                $query->whereHas('brand', function ($q) use ($brands) {
                    $q->whereIn('slug', $brands);
                });
            }
        }

        // 3. Price ranges / min & max price
        if ($request->filled('price_ranges')) {
            $rawRanges = $request->input('price_ranges');
            $ranges = is_array($rawRanges) ? $rawRanges : array_filter(explode(',', (string) $rawRanges));
            if (!empty($ranges)) {
                $query->where(function ($q) use ($ranges) {
                    foreach ($ranges as $range) {
                        $parts = explode('-', (string) $range);
                        if (count($parts) === 2) {
                            $min = (float) $parts[0];
                            $max = (float) $parts[1];
                            $q->orWhere(function ($subQ) use ($min, $max) {
                                $subQ->whereRaw('COALESCE(products.sale_price, products.price) >= ?', [$min]);
                                if ($max > 0 && $max < 999999999) {
                                    $subQ->whereRaw('COALESCE(products.sale_price, products.price) <= ?', [$max]);
                                }
                            });
                        }
                    }
                });
            }
        } else {
            if ($request->filled('min_price')) {
                $query->whereRaw('COALESCE(products.sale_price, products.price) >= ?', [(float) $request->input('min_price')]);
            }
            if ($request->filled('max_price')) {
                $query->whereRaw('COALESCE(products.sale_price, products.price) <= ?', [(float) $request->input('max_price')]);
            }
        }

        // 4. Sorting
        $sort = (string) $request->input('sort', 'featured');
        if ($hasSearch && $sort === 'featured') {
            $query->orderByRaw(
                'CASE WHEN products.name = ? THEN 1 WHEN products.name LIKE ? THEN 2 WHEN products.name LIKE ? THEN 3 ELSE 4 END ASC, products.id DESC',
                [$searchInput, $searchInput . '%', '%' . $searchInput . '%']
            );
        } else {
            switch ($sort) {
                case 'price-asc':
                    $query->orderByRaw('COALESCE(products.sale_price, products.price) ASC')->orderBy('products.id', 'DESC');
                    break;
                case 'price-desc':
                    $query->orderByRaw('COALESCE(products.sale_price, products.price) DESC')->orderBy('products.id', 'DESC');
                    break;
                case 'newest':
                    $query->orderBy('products.published_at', 'desc')->orderBy('products.id', 'desc');
                    break;
                case 'discount':
                    $query->orderByRaw('(CASE WHEN products.sale_price > 0 AND products.sale_price < products.price THEN (products.price - products.sale_price) ELSE 0 END) DESC')
                        ->orderBy('products.id', 'DESC');
                    break;
                case 'bestseller':
                    $query->orderBy('products.views_count', 'desc')->orderBy('products.id', 'desc');
                    break;
                case 'name':
                    $query->orderBy('products.name', 'asc')->orderBy('products.id', 'desc');
                    break;
                case 'featured':
                default:
                    $query->orderBy('products.id', 'desc');
                    break;
            }
        }

        return $query;
    }

    /**
     * Execute scalable Deferred Join Pagination for up to 133k+ WooCommerce products.
     */
    public function paginateDeferred(
        Builder $baseQuery,
        int $page,
        int $perPage,
        string $baseSlashedPath,
        array $queryParams = []
    ): WpLengthAwarePaginator {
        $total = (clone $baseQuery)->count('products.id');

        $idSlice = $total > 0
            ? (clone $baseQuery)->select('products.id')->forPage($page, $perPage)->pluck('id')->all()
            : [];

        $items = $this->hydrateProductCardsByOrderedIds($idSlice);

        unset($queryParams['page']);
        $paginator = new WpLengthAwarePaginator($items, $total, $perPage, $page, [
            'path'  => rtrim($baseSlashedPath, '/') ?: '/',
            'query' => $queryParams,
        ]);
        $paginator->setWpBaseSlashedPath($baseSlashedPath);

        return $paginator;
    }

    /**
     * Fetch slice by arbitrary OFFSET + LIMIT (used cleanly by AJAX Load More endpoint).
     *
     * @return array{items: Collection, total: int}
     */
    public function sliceDeferred(Builder $baseQuery, int $offset, int $limit): array
    {
        $total = (clone $baseQuery)->count('products.id');
        $idSlice = $total > 0
            ? (clone $baseQuery)->select('products.id')->skip(max(0, $offset))->take(max(1, $limit))->pluck('id')->all()
            : [];

        return [
            'items' => $this->hydrateProductCardsByOrderedIds($idSlice),
            'total' => $total,
        ];
    }

    /**
     * Compute deduplicated published product counts per category ID combining `products.category_id` and `category_product`.
     *
     * @return array<int, int>
     */
    public static function getProductCountsByCategory(): array
    {
        return Cache::remember('catalog_category_product_counts_v1', 1800, function () {
            $now = Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString();

            $sql = <<<'SQL'
                SELECT category_id, COUNT(*) AS aggregate
                FROM (
                    SELECT p.category_id AS category_id, p.id AS product_id
                    FROM products p
                    WHERE p.deleted_at IS NULL
                      AND p.status = 'active'
                      AND p.published_at IS NOT NULL
                      AND p.published_at <= ?
                      AND p.category_id IS NOT NULL
                    UNION
                    SELECT cp.category_id AS category_id, cp.product_id AS product_id
                    FROM category_product cp
                    INNER JOIN products p2 ON p2.id = cp.product_id
                    WHERE p2.deleted_at IS NULL
                      AND p2.status = 'active'
                      AND p2.published_at IS NOT NULL
                      AND p2.published_at <= ?
                ) AS cat_prod_union
                GROUP BY category_id
            SQL;

            $rows = DB::select($sql, [$now, $now]);
            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row->category_id] = (int) $row->aggregate;
            }
            return $map;
        });
    }

    /**
     * Compute deduplicated published product counts per brand ID (optionally scoped to category IDs via UNION).
     *
     * @param  array<int>|null  $categoryIds
     * @return array<int, int>
     */
    public static function getBrandCounts(?array $categoryIds = null): array
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString();

        if ($categoryIds === null) {
            $rows = DB::select(
                "SELECT brand_id, COUNT(*) AS aggregate
                 FROM products
                 WHERE deleted_at IS NULL
                   AND status = 'active'
                   AND published_at IS NOT NULL
                   AND published_at <= ?
                   AND brand_id IS NOT NULL
                 GROUP BY brand_id",
                [$now]
            );
        } else {
            $cleanIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), fn (int $id) => $id > 0)));
            if (empty($cleanIds)) {
                return [];
            }

            $inList = implode(',', $cleanIds);
            $sql = <<<SQL
                SELECT brand_id, COUNT(*) AS aggregate
                FROM (
                    SELECT p.brand_id AS brand_id, p.id AS product_id
                    FROM products p
                    WHERE p.deleted_at IS NULL
                      AND p.status = 'active'
                      AND p.published_at IS NOT NULL
                      AND p.published_at <= ?
                      AND p.brand_id IS NOT NULL
                      AND p.category_id IN ({$inList})
                    UNION
                    SELECT p2.brand_id AS brand_id, p2.id AS product_id
                    FROM category_product cp
                    INNER JOIN products p2 ON p2.id = cp.product_id
                    WHERE cp.category_id IN ({$inList})
                      AND p2.deleted_at IS NULL
                      AND p2.status = 'active'
                      AND p2.published_at IS NOT NULL
                      AND p2.published_at <= ?
                      AND p2.brand_id IS NOT NULL
                ) AS cat_brand_union
                GROUP BY brand_id
            SQL;

            $rows = DB::select($sql, [$now, $now]);
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->brand_id] = (int) $row->aggregate;
        }

        return $map;
    }

    /**
     * Fetch cached filter brands with fast aggregated product counts (eliminates 48 correlated Brand::withCount subqueries).
     */
    public static function getFilterBrandsWithCounts(string $cacheKey, ?array $categoryIds = null, int $ttl = 3600): Collection
    {
        $data = Cache::remember($cacheKey . '_nonempty_v1', $ttl, function () use ($categoryIds) {
            $counts = array_filter(self::getBrandCounts($categoryIds), static fn (int $cnt): bool => $cnt >= 1);
            if (empty($counts)) {
                return [];
            }

            return Brand::select(['id', 'name', 'slug', 'logo'])
                ->whereIn('id', array_keys($counts))
                ->orderBy('name', 'asc')
                ->get()
                ->map(static fn (Brand $brand): array => [
                    'id'             => $brand->id,
                    'name'           => $brand->name,
                    'slug'           => $brand->slug,
                    'logo'           => $brand->logo,
                    'products_count' => $counts[$brand->id] ?? 0,
                ])
                ->all();
        });

        return collect($data)->map(static fn (array $item): object => (object) $item);
    }

    private function applyCategoryFilter(Builder $query, array $catIds): void
    {
        $cleanIds = array_values(array_unique(array_map('intval', $catIds)));
        if (empty($cleanIds)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($q) use ($cleanIds) {
            $q->whereIn('products.category_id', $cleanIds)
                ->orWhereExists(function ($sub) use ($cleanIds) {
                    $sub->selectRaw('1')
                        ->from('category_product')
                        ->whereColumn('category_product.product_id', 'products.id')
                        ->whereIn('category_product.category_id', $cleanIds);
                });
        });
    }

    private function hydrateProductCardsByOrderedIds(array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        $cleanIds = array_map('intval', $ids);
        $placeholders = implode(',', $cleanIds);

        return Product::whereIn('id', $cleanIds)
            ->select(self::PRODUCT_CARD_COLUMNS)
            ->with(['category:id,name,slug', 'brand:id,name,slug', 'thumbnailMedia'])
            ->orderByRaw("FIELD(id, {$placeholders})")
            ->get();
    }
}
