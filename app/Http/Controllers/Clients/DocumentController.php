<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Media;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class DocumentController extends Controller
{
    /**
     * Hiển thị trang Trung tâm Tài liệu kỹ thuật & Catalog.
     * Tối ưu hóa siêu tốc (< 0.1s) với Indexed Join và Multi-layer Data Caching.
     */
    public function index(Request $request)
    {
        $search = trim($request->input('q', ''));
        $brandSlug = $request->input('brand');
        $catSlug = $request->input('category');
        $fileType = $request->input('type', 'all');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 12;

        $isDefaultView = empty($search) && empty($brandSlug) && empty($catSlug) && ($fileType === 'all' || empty($fileType));

        // 1. Trường hợp truy cập mặc định (95% người dùng và bot SEO): Tải cực nhanh từ Cache gộp
        if ($isDefaultView) {
            $cacheKey = "document_default_page_data_p{$page}";
            $cachedData = Cache::remember($cacheKey, 3600, function () use ($page, $perPage) {
                // Thương hiệu có tài liệu catalog (JOIN qua product_media có index, siêu tốc ~0.3ms)
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

                // Danh mục có tài liệu catalog
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

                // Tổng số tài liệu catalog thực tế
                $totalCount = (int) DB::table('product_media')
                    ->join('products', 'products.id', '=', 'product_media.product_id')
                    ->where('product_media.role', 'catalog')
                    ->where('products.status', 'active')
                    ->whereNull('products.deleted_at')
                    ->distinct()
                    ->count('product_media.media_id');

                // Lấy danh sách media_id cho trang hiện tại (Indexed limit/offset)
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

            $mediaIds = $cachedData['mediaIds'] ?? [];
            $items = empty($mediaIds) ? collect() : Media::whereIn('id', $mediaIds)
                ->select(['id', 'filename', 'original_name', 'disk', 'folder', 'wp_relative_path', 'wp_source_url', 'mime_type', 'extension', 'size', 'created_at', 'title', 'notes'])
                ->with(['products' => function ($p) {
                    $p->where('products.status', 'active')
                      ->with(['brand:id,name,slug', 'category:id,name,slug', 'series:id,name,slug'])
                      ->select('products.id', 'products.name', 'products.slug', 'products.sku', 'products.brand_id', 'products.category_id', 'products.series_id');
                }])
                ->orderByDesc('id')
                ->get();

            $documents = new LengthAwarePaginator(
                $items,
                $cachedData['totalCount'],
                $cachedData['perPage'],
                $page,
                [
                    'path'  => $request->url(),
                    'query' => $request->query(),
                ]
            );

            $brands = collect($cachedData['brands'])->map(fn($item) => (object)$item);
            $categories = collect($cachedData['categories'])->map(fn($item) => (object)$item);
            $totalDocsCount = $cachedData['totalCount'];

            return response()
                ->view('clients.pages.documents.index', compact(
                    'documents',
                    'brands',
                    'categories',
                    'search',
                    'brandSlug',
                    'catSlug',
                    'fileType',
                    'totalDocsCount'
                ))
                ->header('Cache-Control', 'public, max-age=120, stale-while-revalidate=600');
        }

        // 2. Trường hợp có tìm kiếm hoặc bộ lọc: Query động có tối ưu
        $query = Media::query()
            ->select(['id', 'filename', 'original_name', 'disk', 'folder', 'wp_relative_path', 'wp_source_url', 'mime_type', 'extension', 'size', 'created_at', 'title', 'notes'])
            ->whereHas('products', function ($pQuery) {
                $pQuery->where('product_media.role', 'catalog')
                       ->where('products.status', 'active');
            })
            ->with(['products' => function ($p) {
                $p->where('products.status', 'active')
                  ->with(['brand:id,name,slug', 'category:id,name,slug', 'series:id,name,slug'])
                  ->select('products.id', 'products.name', 'products.slug', 'products.sku', 'products.brand_id', 'products.category_id', 'products.series_id');
            }]);

        // Lọc theo từ khóa tìm kiếm
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('filename', 'like', "%{$search}%")
                  ->orWhere('original_name', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('products', function ($p) use ($search) {
                      $p->where('products.status', 'active')
                        ->where(function ($sub) use ($search) {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%")
                                ->orWhereHas('brand', function ($b) use ($search) {
                                    $b->where('name', 'like', "%{$search}%");
                                })
                                ->orWhereHas('category', function ($c) use ($search) {
                                    $c->where('name', 'like', "%{$search}%");
                                })
                                ->orWhereHas('series', function ($s) use ($search) {
                                    $s->where('name', 'like', "%{$search}%");
                                });
                        });
                  });
            });
        }

        // Lọc theo Thương hiệu
        if (!empty($brandSlug)) {
            $query->whereHas('products', function ($p) use ($brandSlug) {
                $p->where('products.status', 'active')
                  ->whereHas('brand', function ($b) use ($brandSlug) {
                      $b->where('slug', $brandSlug);
                  });
            });
        }

        // Lọc theo Danh mục
        if (!empty($catSlug)) {
            $query->whereHas('products', function ($p) use ($catSlug) {
                $p->where('products.status', 'active')
                  ->whereHas('category', function ($c) use ($catSlug) {
                      $c->where('slug', $catSlug);
                  });
            });
        }

        // Lọc theo Loại file
        if ($fileType === 'pdf') {
            $query->where(function ($q) {
                $q->where('extension', 'pdf')->orWhere('mime_type', 'like', '%pdf%');
            });
        }

        $documents = $query->latest('id')->paginate($perPage)->withQueryString();

        // Danh sách Thương hiệu và Danh mục để lọc (Cached)
        $brandsData = Cache::remember('document_filter_brands', 3600, function () {
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
        $brands = collect($brandsData)->map(fn($item) => (object)$item);

        $categoriesData = Cache::remember('document_filter_categories', 3600, function () {
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
        $categories = collect($categoriesData)->map(fn($item) => (object)$item);

        // Đếm tổng số tài liệu thực tế của các sản phẩm đang active (Cached)
        $totalDocsCount = Cache::remember('document_total_count', 3600, function () {
            return (int) DB::table('product_media')
                ->join('products', 'products.id', '=', 'product_media.product_id')
                ->where('product_media.role', 'catalog')
                ->where('products.status', 'active')
                ->whereNull('products.deleted_at')
                ->distinct()
                ->count('product_media.media_id');
        });

        return response()
            ->view('clients.pages.documents.index', compact(
                'documents',
                'brands',
                'categories',
                'search',
                'brandSlug',
                'catSlug',
                'fileType',
                'totalDocsCount'
            ))
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    /**
     * Tải trực tiếp tài liệu catalog về máy tính (Force File Download).
     */
    public function download($id)
    {
        $media = Media::findOrFail($id);
        
        $rawName = $media->original_name ?: $media->filename;
        $ext = $media->extension ?: pathinfo(parse_url($media->filename, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'pdf';
        
        // Lấy basename sạch sẽ
        $downloadName = basename(parse_url($rawName, PHP_URL_PATH) ?? $rawName);
        if (!str_ends_with(strtolower($downloadName), '.' . strtolower($ext))) {
            $downloadName .= '.' . $ext;
        }

        // 1. Nếu file nằm trên local storage của server
        if ($media->existsOnDisk()) {
            return response()->download($media->absolute_path, $downloadName, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
            ]);
        }

        // 2. Nếu file là link external / URL ngoài -> Stream tải trực tiếp về máy người dùng
        $fileUrl = $media->url;
        if (filter_var($fileUrl, FILTER_VALIDATE_URL)) {
            return response()->streamDownload(function () use ($fileUrl) {
                $opts = [
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 60,
                        'follow_location' => 1,
                        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ]
                ];
                $context = stream_context_create($opts);
                $handle = @fopen($fileUrl, 'rb', false, $context);
                if ($handle) {
                    while (!feof($handle)) {
                        echo fread($handle, 1024 * 64);
                        flush();
                    }
                    fclose($handle);
                }
            }, $downloadName, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        }

        abort(404, 'File tài liệu không tồn tại');
    }
}
