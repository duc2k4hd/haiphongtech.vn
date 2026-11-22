<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductTag;
use App\Services\Catalog\ProductCatalogService;
use App\Support\WpUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ShopController extends Controller
{
    public const PRODUCT_CARD_COLUMNS = ProductCatalogService::PRODUCT_CARD_COLUMNS;

    public function __construct(
        private readonly ProductCatalogService $catalogService
    ) {
    }

    /**
     * Lấy danh sách danh mục cha bên Sidebar kèm số lượng sản phẩm (In-memory & Cached)
     */
    public static function getSidebarCategories()
    {
        $data = Cache::remember('shop_sidebar_categories', 3600, function () {
            $allCats = Category::where('type', 'product')
                ->where('status', 'active')
                ->select(['id', 'name', 'slug', 'parent_id', 'icon', 'position'])
                ->orderBy('position', 'asc')
                ->get();

            if ($allCats->isEmpty()) {
                return [];
            }

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

            $productCounts = ProductCatalogService::getProductCountsByCategory();

            $parents = $allCats->whereNull('parent_id');
            $result = [];
            foreach ($parents as $parent) {
                $allIds = $getDescendantIds($parent->id);
                $pCount = 0;
                foreach ($allIds as $cid) {
                    $pCount += $productCounts[$cid] ?? 0;
                }
                $result[] = [
                    'id'             => $parent->id,
                    'name'           => $parent->name,
                    'slug'           => $parent->slug,
                    'icon'           => $parent->icon,
                    'products_count' => $pCount,
                ];
            }
            return $result;
        });

        return collect($data)->map(fn($item) => (object) $item);
    }

    /**
     * Lấy danh sách thương hiệu kèm số lượng sản phẩm (Cached)
     */
    public static function getFilterBrands()
    {
        return ProductCatalogService::getFilterBrandsWithCounts('shop_filter_brands');
    }

    /**
     * Lấy mức giá cao nhất của sản phẩm (Cached)
     */
    public static function getMaxPrice()
    {
        return Cache::remember('shop_max_price', 3600, function () {
            return Product::published()->max('price') ?? 0;
        });
    }

    public function countProducts(Request $request)
    {
        $query = $this->catalogService->buildQuery($request);
        $count = $query->count('products.id');
        return response()->json(['count' => $count]);
    }

    public function index(
        Request $request,
        int $page = 1,
        ?Brand $activeBrand = null,
        ?ProductTag $activeProductTag = null
    ) {
        $parentCategories = self::getSidebarCategories();
        $brands = self::getFilterBrands();
        $maxPrice = self::getMaxPrice();

        $forcedBrandId = $activeBrand?->id;
        $forcedTagId = $activeProductTag?->id;

        if ($activeBrand) {
            $basePath = "/{$activeBrand->slug}/";
        } elseif ($activeProductTag) {
            $basePath = "/tag-san-pham/{$activeProductTag->slug}/";
        } else {
            $basePath = '/shop/';
        }

        $baseQuery = $this->catalogService->buildQuery(
            $request,
            forcedCategoryIds: null,
            forcedBrandId: $forcedBrandId,
            forcedProductTagId: $forcedTagId
        );

        $products = $this->catalogService->paginateDeferred(
            $baseQuery,
            max(1, $page),
            ProductCatalogService::PER_PAGE,
            $basePath,
            $request->query()
        );

        if ($page > 1 && $products->isEmpty()) {
            abort(404);
        }

        $totalProducts = $products->total();
        $seoArchive = $this->buildArchiveSeo($page, max(1, $products->lastPage()), $activeBrand, $activeProductTag);

        $viewedProductIds = session()->get('viewed_products', []);
        $viewedProducts = [];
        if (!empty($viewedProductIds)) {
            $placeholders = implode(',', array_fill(0, count($viewedProductIds), '?'));
            $viewedProducts = Product::published()
                ->select(self::PRODUCT_CARD_COLUMNS)
                ->with(['category:id,name,slug', 'brand:id,name,slug', 'thumbnailMedia'])
                ->whereIn('id', $viewedProductIds)
                ->orderByRaw("FIELD(id, {$placeholders})", array_map('intval', $viewedProductIds))
                ->take(8)
                ->get();
        }

        return view('clients.pages.shop.index', compact(
            'parentCategories',
            'brands',
            'products',
            'totalProducts',
            'maxPrice',
            'viewedProducts',
            'activeBrand',
            'activeProductTag',
            'seoArchive'
        ));
    }

    public function loadMore(Request $request)
    {
        $offset = (int) $request->input('offset', 0);
        $limit = $offset === 0 ? ProductCatalogService::PER_PAGE : 8;

        $baseQuery = $this->catalogService->buildQuery($request);
        $slice = $this->catalogService->sliceDeferred($baseQuery, $offset, $limit);
        $products = $slice['items'];

        $html = '';
        foreach ($products as $product) {
            $html .= view('clients.pages.shop.partials.product_card', compact('product'))->render();
        }

        return response()->json([
            'html'  => $html,
            'count' => $products->count(),
            'total' => $slice['total'],
        ]);
    }

    public function brands()
    {
        $brandsData = Cache::remember('all_brands_page', 3600, function () {
            return Brand::select(['id', 'name', 'slug', 'logo'])
                ->withCount(['products' => fn($q) => $q->published()])
                ->orderBy('name')
                ->get()
                ->toArray();
        });
        $brands = collect($brandsData)->map(fn($item) => (object) $item);

        return view('clients.pages.brands.index', compact('brands'));
    }

    public function cart()
    {
        $cartItems = session('cart_items', []);
        return view('clients.pages.cart.index', compact('cartItems'));
    }

    public function addCart(Request $request)
    {
        $productId = (int) $request->input('product_id', 0);
        $productSlug = trim((string) $request->input('product_slug', ''));
        $productName = trim((string) $request->input('product_name', ''));
        $quantity = max(1, min(1000, (int) $request->input('quantity', 1)));

        $product = null;
        if ($productId > 0) {
            $product = Product::published()->with('thumbnailMedia')->find($productId);
        }
        if (!$product && $productSlug !== '') {
            $product = Product::published()->with('thumbnailMedia')->where('slug', $productSlug)->first();
        }
        if (!$product && $productName !== '') {
            $product = Product::published()->with('thumbnailMedia')->where('name', $productName)->first();
        }

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy sản phẩm để thêm vào giỏ hàng.',
            ], 404);
        }

        $cartItems = session('cart_items', []);
        $effectivePrice = (float) ($product->sale_price > 0 ? $product->sale_price : ($product->price ?? 0));

        if (isset($cartItems[$product->id])) {
            $cartItems[$product->id]['quantity'] = min(1000, $cartItems[$product->id]['quantity'] + $quantity);
            $cartItems[$product->id]['price'] = $effectivePrice;
            $cartItems[$product->id]['sku'] = $product->sku ?? ($cartItems[$product->id]['sku'] ?? '');
        } else {
            $cartItems[$product->id] = [
                'id'            => $product->id,
                'name'          => $product->name,
                'sku'           => $product->sku ?? '',
                'slug'          => $product->slug,
                'price'         => $effectivePrice,
                'thumbnail_url' => $product->thumbnailMedia->first()?->url ?? asset('storage/clients/imgs/products/no-image.png'),
                'quantity'      => $quantity,
            ];
        }

        session(['cart_items' => $cartItems]);

        $cartCount = array_sum(array_column($cartItems, 'quantity'));
        session(['cart_count' => $cartCount]);

        $totalPrice = 0;
        foreach ($cartItems as $item) {
            $totalPrice += ((float) ($item['price'] ?? 0)) * ((int) ($item['quantity'] ?? 0));
        }

        return response()->json([
            'success'     => true,
            'message'     => 'Đã thêm “' . $product->name . '” vào giỏ hàng!',
            'cart_count'  => $cartCount,
            'total_price' => $totalPrice,
            'item'        => $cartItems[$product->id],
            'cart_items'  => array_values($cartItems),
        ]);
    }

    public function updateCart(Request $request)
    {
        $productId = $request->input('product_id');
        $quantity = (int) $request->input('quantity', 1);

        if ($quantity < 0) $quantity = 0;
        if ($quantity > 1000) $quantity = 1000;

        $cartItems = session('cart_items', []);

        if (isset($cartItems[$productId])) {
            if ($quantity === 0) {
                unset($cartItems[$productId]);
            } else {
                $cartItems[$productId]['quantity'] = $quantity;
            }
            session(['cart_items' => $cartItems]);
        }

        $cartCount = array_sum(array_column($cartItems, 'quantity'));
        session(['cart_count' => $cartCount]);

        $totalPrice = 0;
        foreach ($cartItems as $item) {
            $totalPrice += $item['price'] * $item['quantity'];
        }

        return response()->json([
            'success'     => true,
            'cart_count'  => $cartCount,
            'total_price' => $totalPrice,
            'item_total'  => isset($cartItems[$productId]) ? $cartItems[$productId]['price'] * $cartItems[$productId]['quantity'] : 0,
            'cart_items'  => array_values($cartItems),
        ]);
    }

    public function clearCart()
    {
        session()->forget(['cart_items', 'cart_count']);
        return response()->json([
            'success' => true,
            'message' => 'Đã xóa toàn bộ giỏ hàng',
        ]);
    }

    private function buildArchiveSeo(int $page, int $lastPage, ?Brand $brand, ?ProductTag $tag): array
    {
        if ($tag) {
            $baseTitle = $tag->meta_title ?: ($tag->name . ' Archives - Hải Phòng Tech');
            return [
                'h1'              => $tag->name,
                'breadcrumb_name' => 'Thẻ sản phẩm: ' . $tag->name,
                'title'           => $page > 1 ? "{$tag->name} Archives - Trang {$page} trên {$lastPage} - Hải Phòng Tech" : $baseTitle,
                'description'     => $tag->meta_description ?: '',
                'robots'          => $tag->meta_robots ?: 'noindex, follow',
                'canonical'       => '', // Intentional: noindex product_tag archives omit canonical per RankMath
                'current_url'     => WpUrl::productTagUrl($tag->slug, $page),
                'schema_json'     => null,
                'intro_content'   => $tag->description,
            ];
        }

        if ($brand) {
            $baseTitle = $brand->meta_title ?: ($brand->name . ' - Hải Phòng Tech');
            $rawCanon = trim((string) ($brand->canonical_url ?? ''));
            $canon = ($rawCanon !== '' && !str_contains($rawCanon, '/thuong-hieu/'))
                ? WpUrl::absolute($rawCanon)
                : WpUrl::brandUrl($brand->slug);
            return [
                'h1'              => $brand->name,
                'breadcrumb_name' => 'Thương hiệu: ' . $brand->name,
                'title'           => $page > 1 ? "{$brand->name} - Trang {$page} trên {$lastPage} - Hải Phòng Tech" : $baseTitle,
                'description'     => $brand->meta_description ?: "Thiết bị tự động hóa chính hãng thương hiệu {$brand->name} tại Hải Phòng Tech.",
                'robots'          => $page > 1 ? 'noindex, follow' : ($brand->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large'),
                'canonical'       => $page > 1 ? '' : $canon,
                'current_url'     => WpUrl::brandUrl($brand->slug, $page),
                'schema_json'     => $page > 1 ? null : WpUrl::adaptSchemaJson($brand->schema_json),
                'intro_content'   => $brand->content,
            ];
        }

        $shopPage = Page::where('slug', 'shop')->first();
        $baseTitle = $shopPage?->meta_title ?: 'Shop - Hải Phòng Tech';
        return [
            'h1'              => 'Cửa hàng',
            'breadcrumb_name' => 'Cửa hàng',
            'title'           => $page > 1 ? "Shop - Trang {$page} trên {$lastPage} - Hải Phòng Tech" : $baseTitle,
            'description'     => $shopPage?->meta_description ?: 'Cửa hàng thiết bị điện & tự động hóa công nghiệp chính hãng tại Hải Phòng Tech.',
            'robots'          => $page > 1 ? 'noindex, follow' : ($shopPage?->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large'),
            'canonical'       => $page > 1 ? '' : WpUrl::shopUrl(1),
            'current_url'     => WpUrl::shopUrl($page),
            'schema_json'     => WpUrl::adaptSchemaJson($shopPage?->schema_json),
            'intro_content'   => null,
        ];
    }
}
