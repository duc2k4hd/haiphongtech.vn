<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\WpUrl;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Cột sản phẩm thẻ gọn nhẹ cho Sản phẩm liên quan (tránh nạp content nặng vào RAM)
     */
    const RELATED_CARD_COLUMNS = [
        'id', 'name', 'slug', 'price', 'sale_price',
        'category_id', 'series_id'
    ];

    public function show(Request $request, $slug)
    {
        $product = Product::published()->with([
            'category:id,name,slug,parent_id',
            'categories:id,name,slug,parent_id',
            'productTags:id,name,slug',
            'brand:id,name,slug',
            'series:id,name,slug',
            'thumbnailMedia',
            'galleryMedia',
            'catalogMedia',
        ])->where('slug', $slug)->firstOrFail();

        // Build full category ancestor breadcrumb chain for current product
        $categoryBreadcrumbs = [];
        $cursorCat = $product->category;
        $visitedCats = [];
        while ($cursorCat && !isset($visitedCats[$cursorCat->id])) {
            $visitedCats[$cursorCat->id] = true;
            array_unshift($categoryBreadcrumbs, $cursorCat);
            $cursorCat = $cursorCat->parent_id ? Category::select(['id', 'name', 'slug', 'parent_id'])->find($cursorCat->parent_id) : null;
        }

        // Tăng lượt xem (chỉ đếm 1 lần trong phiên session để tối ưu tốc độ DB và chống F5 ảo)
        $viewKey = 'viewed_prod_' . $product->id;
        if (!session()->has($viewKey)) {
            session()->put($viewKey, true);
            \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)->increment('views_count');
        }

        // Lưu lịch sử xem sản phẩm cho người dùng
        $viewedList = session()->get('viewed_products', []);
        if (($k = array_search($product->id, $viewedList)) !== false) {
            unset($viewedList[$k]);
        }
        array_unshift($viewedList, $product->id);
        session()->put('viewed_products', array_slice($viewedList, 0, 10));

        // Danh sách Model trong cùng Series (Tối ưu cực nhanh ngay từ truy vấn gốc)
        $seriesProducts = collect();
        $embeddedSeriesModels = [];

        if ($product->series_id) {
            $seriesModels = Product::published()
                ->where('series_id', $product->series_id)
                ->with('thumbnailMedia')
                ->orderBy('sku', 'asc')
                ->orderBy('name', 'asc')
                ->get([
                    'id', 'name', 'slug', 'sku', 'price', 'sale_price',
                    'status', 'meta_title', 'meta_description', 'series_id'
                ]);

            $embedded = [];
            $list = [];
            foreach ($seriesModels as $m) {
                $discount = 0;
                if ($m->sale_price && $m->sale_price < $m->price && $m->price > 0) {
                    $discount = round((1 - $m->sale_price / $m->price) * 100);
                }

                $itemData = [
                    'id' => $m->id,
                    'name' => $m->name,
                    'slug' => $m->slug,
                    'sku' => $m->sku,
                    'price' => (float)$m->price,
                    'price_formatted' => number_format($m->price, 0, ',', '.') . 'đ',
                    'sale_price' => $m->sale_price ? (float)$m->sale_price : null,
                    'sale_price_formatted' => $m->sale_price ? number_format($m->sale_price, 0, ',', '.') . 'đ' : null,
                    'has_sale' => (bool)($m->sale_price && $m->sale_price < $m->price),
                    'discount_percent' => $discount,
                    'status' => $m->status,
                    'is_active' => $m->status === 'active',
                    'thumbnail_url' => $m->thumbnailMedia->first()?->url ?? asset('storage/clients/imgs/products/no-image.png'),
                    'meta_title' => $m->meta_title ?: ($m->name . ' - Hải Phòng Tech'),
                    'meta_description' => $m->meta_description ?? '',
                    'url' => WpUrl::productUrl($m->slug),
                    'is_full_data' => false,
                ];

                // Nếu là sản phẩm hiện tại, nhúng sẵn toàn bộ full data (content, gallery, catalog) để 0ms switch
                if ($m->id === $product->id) {
                    $itemData['content'] = $product->content;
                    $itemData['short_description'] = $product->short_description;
                    $itemData['gallery'] = $product->galleryMedia->map(fn($g) => [
                        'id' => $g->id,
                        'url' => $g->url,
                        'alt' => $g->alt ?? $product->name,
                    ])->values()->toArray();
                    $itemData['catalogs'] = $product->catalogMedia->map(fn($c) => [
                        'id' => $c->id,
                        'url' => $c->url,
                        'filename' => $c->filename ?? 'Tài liệu sản phẩm',
                        'download_url' => route('documents.download', $c->id),
                    ])->values()->toArray();
                    $itemData['is_full_data'] = true;
                }

                $embedded[$m->slug] = $itemData;
                $list[] = $itemData;
            }

            $embeddedSeriesModels = $embedded;
            $seriesProducts = collect($list)->map(fn($i) => (object)$i);
        }

        // Lấy chính xác 10 sản phẩm liên quan (5 trước, 5 sau cùng danh mục nhỏ nhất, ưu tiên cùng series, bù trừ và mở rộng lên danh mục cha)
        $relatedProducts = $this->getRelatedProducts($product);

        // Nếu là AJAX request (chuyển model tức thì không reload trang)
        if ($request->ajax() || $request->wantsJson() || $request->query('ajax')) {
            $gallery = $product->galleryMedia->map(fn($m) => [
                'id' => $m->id,
                'url' => $m->url,
                'alt' => $m->alt ?? $product->name,
            ]);

            $catalogs = $product->catalogMedia->map(fn($m) => [
                'id' => $m->id,
                'url' => $m->url,
                'filename' => $m->filename ?? 'Tài liệu sản phẩm',
                'download_url' => route('documents.download', $m->id),
            ]);

            $discountPercent = 0;
            if ($product->sale_price && $product->sale_price < $product->price && $product->price > 0) {
                $discountPercent = round((1 - $product->sale_price / $product->price) * 100);
            }

            return response()->json([
                'success' => true,
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'price' => (float)$product->price,
                'price_formatted' => number_format($product->price, 0, ',', '.') . 'đ',
                'sale_price' => $product->sale_price ? (float)$product->sale_price : null,
                'sale_price_formatted' => $product->sale_price ? number_format($product->sale_price, 0, ',', '.') . 'đ' : null,
                'has_sale' => (bool)($product->sale_price && $product->sale_price < $product->price),
                'discount_percent' => $discountPercent,
                'short_description' => $product->short_description,
                'content' => $product->content,
                'status' => $product->status,
                'is_active' => $product->status === 'active',
                'thumbnail_url' => $product->thumbnailMedia->first()?->url ?? asset('storage/clients/imgs/products/no-image.png'),
                'gallery' => $gallery,
                'catalogs' => $catalogs,
                'meta_title' => $product->meta_title ?: ($product->name . ' - Hải Phòng Tech'),
                'meta_description' => $product->meta_description ?? '',
                'url' => WpUrl::productUrl($product->slug),
                'is_full_data' => true,
            ]);
        }

        return view('clients.pages.product.index', compact('product', 'categoryBreadcrumbs', 'seriesProducts', 'embeddedSeriesModels', 'relatedProducts'));
    }

    /**
     * Lấy chính xác 10 sản phẩm liên quan (5 trước, 5 sau cùng danh mục nhỏ nhất, ưu tiên cùng series, tự bù trừ và mở rộng lên danh mục cha nếu thiếu).
     * Tối ưu hóa cực nhanh (<1ms), chỉ select các cột thẻ cần thiết và eager-load thumbnailMedia.
     */
    protected function getRelatedProducts($product)
    {
        if (!$product->category_id) {
            return collect();
        }

        $targetCount = 10;
        $halfTarget = 5;
        $catId = $product->category_id;
        $currentId = $product->id;
        $columns = self::RELATED_CARD_COLUMNS;

        // 1. Lấy sản phẩm trước (id < currentId) và sản phẩm sau (id > currentId) trong cùng danh mục nhỏ nhất
        $beforeProds = Product::published()
            ->where('category_id', $catId)
            ->where('id', '<', $currentId)
            ->select($columns)
            ->with('thumbnailMedia')
            ->orderBy('id', 'desc')
            ->take($targetCount)
            ->get();

        $afterProds = Product::published()
            ->where('category_id', $catId)
            ->where('id', '>', $currentId)
            ->select($columns)
            ->with('thumbnailMedia')
            ->orderBy('id', 'asc')
            ->take($targetCount)
            ->get();

        $countBefore = $beforeProds->count();
        $countAfter = $afterProds->count();

        // 2. Thuật toán bù trừ: Cân bằng 5 trước + 5 sau, nếu một bên thiếu thì bên kia bù vào để đủ 10
        $takeBefore = $halfTarget;
        $takeAfter = $halfTarget;

        if ($countBefore < $halfTarget) {
            $takeBefore = $countBefore;
            $takeAfter = min($targetCount - $takeBefore, $countAfter);
        } elseif ($countAfter < $halfTarget) {
            $takeAfter = $countAfter;
            $takeBefore = min($targetCount - $takeAfter, $countBefore);
        }

        $selectedBefore = $beforeProds->take($takeBefore)->reverse()->values();
        $selectedAfter = $afterProds->take($takeAfter)->values();

        $collected = $selectedBefore->concat($selectedAfter);

        // Ưu tiên đưa sản phẩm cùng series lên đầu danh sách nếu có
        if ($product->series_id && $collected->isNotEmpty()) {
            $collected = $collected->sortByDesc(function ($p) use ($product) {
                return $p->series_id == $product->series_id ? 1 : 0;
            })->values();
        }

        // 3. Nếu danh mục nhỏ nhất không đủ 10 sản phẩm -> Mở rộng lên các danh mục cha cấp trên (từ cha trực tiếp đến gốc)
        if ($collected->count() < $targetCount) {
            $alreadyIds = $collected->pluck('id')->push($currentId)->toArray();
            $needed = $targetCount - $collected->count();

            $currentCategory = $product->category;
            while ($currentCategory && $currentCategory->parent_id && $needed > 0) {
                $parentCategory = Category::find($currentCategory->parent_id);
                if (!$parentCategory) break;

                // Lấy tất cả ID con cháu của danh mục cha (đã được cache in-memory 0 query)
                $parentChildCatIds = $parentCategory->getAllChildrenIds();

                $parentProducts = Product::published()
                    ->whereIn('category_id', $parentChildCatIds)
                    ->whereNotIn('id', $alreadyIds)
                    ->select($columns)
                    ->with('thumbnailMedia')
                    ->latest('id')
                    ->take($needed)
                    ->get();

                if ($parentProducts->isNotEmpty()) {
                    $collected = $collected->concat($parentProducts);
                    $alreadyIds = array_merge($alreadyIds, $parentProducts->pluck('id')->toArray());
                    $needed = $targetCount - $collected->count();
                }

                $currentCategory = $parentCategory;
            }
        }

        return $collected->take($targetCount);
    }
}
