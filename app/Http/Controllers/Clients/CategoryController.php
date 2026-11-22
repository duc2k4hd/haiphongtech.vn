<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\ProductCatalogService;
use App\Support\WpUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function __construct(
        private readonly ProductCatalogService $catalogService
    ) {
    }

    public function show(Request $request, $slug, int $page = 1)
    {
        $category = Category::where('type', 'product')
            ->where('slug', $slug)
            ->first()
            ?? Category::where('slug', $slug)->firstOrFail();

        $allCatIds = $category->getAllChildrenIds();

        // Preserve request context for client-side category.js filters without clobbering user params
        $request->merge(['category' => $category->slug]);

        $parentCategories = ShopController::getSidebarCategories();

        $brands = ProductCatalogService::getFilterBrandsWithCounts(
            "category_brands_{$category->slug}",
            $allCatIds
        );

        $baseQuery = $this->catalogService->buildQuery(
            $request,
            forcedCategoryIds: $allCatIds,
            includeSearchKeywordParam: true
        );

        $products = $this->catalogService->paginateDeferred(
            $baseQuery,
            max(1, $page),
            ProductCatalogService::PER_PAGE,
            "/{$category->slug}/",
            $request->query()
        );

        if ($page > 1 && $products->isEmpty()) {
            abort(404);
        }

        $totalProducts = $products->total();
        $maxPrice = ShopController::getMaxPrice();

        // Build full ancestor breadcrumb trail from root down to current category
        $breadcrumbs = [];
        $cursor = $category;
        $visited = [];
        while ($cursor && !isset($visited[$cursor->id])) {
            $visited[$cursor->id] = true;
            array_unshift($breadcrumbs, $cursor);
            $cursor = $cursor->parent_id ? Category::select(['id', 'name', 'slug', 'parent_id'])->find($cursor->parent_id) : null;
        }

        $lastPage = max(1, $products->lastPage());
        $baseTitle = $category->meta_title ?: ($category->name . ' - Hải Phòng Tech');
        $canonical = !empty($category->canonical_url)
            ? WpUrl::absolute($category->canonical_url)
            : WpUrl::categoryUrl($category->slug);

        $seoCategory = [
            'title'       => $page > 1 ? "{$category->name} - Trang {$page} trên {$lastPage} - Hải Phòng Tech" : $baseTitle,
            'description' => $category->meta_description ?: (!empty($category->content) ? \Illuminate\Support\Str::limit(strip_tags($category->content), 160) : (!empty($category->description) ? \Illuminate\Support\Str::limit(strip_tags($category->description), 160) : '')),
            'robots'      => $page > 1 ? 'noindex, follow' : ($category->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large'),
            'canonical'   => $page > 1 ? '' : $canonical,
            'current_url' => WpUrl::categoryUrl($category->slug, $page),
            'schema_json' => $page > 1 ? null : WpUrl::adaptSchemaJson($category->schema_json),
        ];

        $viewedProductIds = session()->get('viewed_products', []);
        $viewedProducts = [];
        if (!empty($viewedProductIds)) {
            $placeholders = implode(',', array_fill(0, count($viewedProductIds), '?'));
            $viewedProducts = Product::published()
                ->select(ShopController::PRODUCT_CARD_COLUMNS)
                ->with(['category:id,name,slug', 'brand:id,name,slug', 'thumbnailMedia'])
                ->whereIn('id', $viewedProductIds)
                ->orderByRaw("FIELD(id, {$placeholders})", array_map('intval', $viewedProductIds))
                ->take(8)
                ->get();
        }

        return view('clients.pages.categories.show', compact(
            'category',
            'breadcrumbs',
            'seoCategory',
            'parentCategories',
            'brands',
            'products',
            'totalProducts',
            'maxPrice',
            'viewedProducts'
        ));
    }
}
