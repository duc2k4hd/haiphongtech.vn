<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Series;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportService
{
    /**
     * Danh sách các cột mặc định khi Xuất CSV.
     */
    const COLUMNS = [
        'sku',
        'name',
        'slug',
        'price',
        'sale_price',
        'short_description',
        'content',
        'category',
        'brand',
        'series',
        'status',
        'published_at',
        'meta_title',
        'meta_description',
    ];

    /**
     * Các cột ảo dành cho liên kết hình ảnh và tài liệu.
     */
    const MEDIA_COLUMNS = ['image', 'gallery', 'catalog'];

    /**
     * Toàn bộ các cột hợp lệ được phép chọn khi Xuất CSV (theo thứ tự chuẩn).
     */
    const EXPORTABLE_COLUMNS = [
        'id',
        'sku',
        'name',
        'slug',
        'price',
        'sale_price',
        'short_description',
        'content',
        'category',
        'brand',
        'series',
        'status',
        'stock_status',
        'product_type',
        'published_at',
        'image',
        'gallery',
        'catalog',
        'meta_title',
        'meta_description',
        'meta_robots',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'in_sitemap',
        'views_count',
        'url',
    ];

    /**
     * Xuất danh sách sản phẩm ra file CSV với danh sách cột do người dùng tùy chọn.
     * Chỉ SELECT và Eager-Load đúng các cột/quan hệ được chọn để đạt tốc độ cao nhất.
     *
     * @param  array<int, string>|null  $columns
     */
    public function exportCsv(string $type = 'all', ?array $ids = null, $categoryId = null, ?array $columns = null): StreamedResponse
    {
        // Lọc và giữ đúng thứ tự các cột hợp lệ; nếu không truyền thì dùng bộ mặc định cũ
        $selectedColumns = [];
        if (!empty($columns)) {
            $allowed = array_flip(self::EXPORTABLE_COLUMNS);
            foreach ($columns as $col) {
                $colKey = trim((string) $col);
                if (isset($allowed[$colKey]) && !in_array($colKey, $selectedColumns, true)) {
                    $selectedColumns[] = $colKey;
                }
            }
        }
        if (empty($selectedColumns)) {
            $selectedColumns = array_merge(self::COLUMNS, self::MEDIA_COLUMNS);
        }

        $response = new StreamedResponse(function () use ($type, $ids, $categoryId, $selectedColumns) {
            $handle = fopen('php://output', 'w');

            // Ghi BOM UTF-8 để mở tiếng Việt có dấu chuẩn đẹp trên Microsoft Excel
            fputs($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Ghi dòng tiêu đề chính xác theo cột người dùng chọn
            fputcsv($handle, $selectedColumns);

            $needCategory = in_array('category', $selectedColumns, true);
            $needBrand    = in_array('brand', $selectedColumns, true);
            $needSeries   = in_array('series', $selectedColumns, true);
            $needMedia    = count(array_intersect($selectedColumns, self::MEDIA_COLUMNS)) > 0;

            // Chỉ SELECT đúng cột DB cần thiết (tránh load cột content nặng khi không chọn)
            $directDbMap = [
                'id'                => 'id',
                'sku'               => 'sku',
                'name'              => 'name',
                'slug'              => 'slug',
                'price'             => 'price',
                'sale_price'        => 'sale_price',
                'short_description' => 'short_description',
                'content'           => 'content',
                'status'            => 'status',
                'stock_status'      => 'stock_status',
                'product_type'      => 'product_type',
                'published_at'      => 'published_at',
                'meta_title'        => 'meta_title',
                'meta_description'  => 'meta_description',
                'meta_robots'       => 'meta_robots',
                'canonical_url'     => 'canonical_url',
                'og_title'          => 'og_title',
                'og_description'    => 'og_description',
                'og_image'          => 'og_image',
                'in_sitemap'        => 'in_sitemap',
                'views_count'       => 'views_count',
            ];

            $selectCols = ['id'];
            foreach ($selectedColumns as $col) {
                if (isset($directDbMap[$col])) {
                    $selectCols[] = $directDbMap[$col];
                }
            }
            if (in_array('url', $selectedColumns, true)) {
                $selectCols[] = 'slug';
            }
            if ($needCategory) {
                $selectCols[] = 'category_id';
            }
            if ($needBrand) {
                $selectCols[] = 'brand_id';
            }
            if ($needSeries) {
                $selectCols[] = 'series_id';
            }
            $selectCols = array_values(array_unique($selectCols));

            $withRelations = [];
            if ($needCategory) {
                $withRelations[] = 'category:id,name';
            }
            if ($needBrand) {
                $withRelations[] = 'brand:id,name';
            }
            if ($needSeries) {
                $withRelations[] = 'series:id,name';
            }
            if ($needMedia) {
                $withRelations[] = 'images';
            }

            $query = Product::query()->select($selectCols);
            if (!empty($withRelations)) {
                $query->with($withRelations);
            }

            if ($type === 'selected' && !empty($ids)) {
                $query->whereIn('id', $ids);
            } elseif ($type === 'category' && !empty($categoryId)) {
                $query->where('category_id', $categoryId);
            }

            // ChunkById 1000 dòng nhanh gấp nhiều lần OFFSET chunk trên bảng 133k+ bản ghi
            $query->chunkById(1000, function ($products) use ($handle, $selectedColumns, $needMedia) {
                foreach ($products as $product) {
                    $media = $needMedia ? $product->images : null;
                    $row = [];

                    foreach ($selectedColumns as $col) {
                        $row[] = match ($col) {
                            'id'                => $product->id,
                            'sku'               => $product->sku ?? '',
                            'name'              => $product->name ?? '',
                            'slug'              => $product->slug ?? '',
                            'price'             => $product->price !== null ? (float) $product->price : '',
                            'sale_price'        => $product->sale_price !== null ? (float) $product->sale_price : '',
                            'short_description' => $product->short_description ?? '',
                            'content'           => $product->content ?? '',
                            'category'          => $product->category->name ?? '',
                            'brand'             => $product->brand->name ?? '',
                            'series'            => $product->series->name ?? '',
                            'status'            => $product->status ?? 'active',
                            'stock_status'      => $product->stock_status ?? 'instock',
                            'product_type'      => $product->product_type ?? 'simple',
                            'published_at'      => $product->published_at ? $product->published_at->format('Y-m-d H:i:s') : '',
                            'meta_title'        => $product->meta_title ?? '',
                            'meta_description'  => $product->meta_description ?? '',
                            'meta_robots'       => $product->meta_robots ?? '',
                            'canonical_url'     => $product->canonical_url ?? '',
                            'og_title'          => $product->og_title ?? '',
                            'og_description'    => $product->og_description ?? '',
                            'og_image'          => $product->og_image ?? '',
                            'in_sitemap'        => $product->in_sitemap ? '1' : '0',
                            'views_count'       => (int) ($product->views_count ?? 0),
                            'url'               => $product->slug ? \App\Support\WpUrl::productUrl($product->slug) : '',
                            'image'             => $media ? ($media->where('pivot.role', 'thumbnail')->first()?->filename ?? '') : '',
                            'gallery'           => $media ? implode(', ', $media->where('pivot.role', 'gallery')->sortBy('pivot.position')->pluck('filename')->toArray()) : '',
                            'catalog'           => $media ? implode(', ', $media->where('pivot.role', 'catalog')->sortBy('pivot.position')->pluck('filename')->toArray()) : '',
                            default             => '',
                        };
                    }

                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="products_export_' . date('Y_m_d_His') . '.csv"');

        return $response;
    }

    /**
     * Nhập dữ liệu thông minh (Smart Deep Diffing & Upsert).
     * - Tự động tải ảnh từ link ngoài (HTTP/HTTPS) về thư mục products và đổi tên file theo slug sản phẩm.
     * - So sánh sâu: Nếu dữ liệu trong CSV GIỐNG HỆT 100% trong database -> BỎ QUA HOÀN TOÀN để tiết kiệm RAM/CPU.
     * - Chỉ cập nhật khi thực sự có thay đổi về thông số hoặc media.
     */
    public function importBatch(array $rows, string $mode = 'upsert'): array
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $skus = array_column($rows, 'sku');
        if (empty($skus)) {
            return ['updated' => 0, 'inserted' => 0, 'skipped' => 0];
        }

        $passedKeys = array_keys($rows[0] ?? []);

        // Xác định các cột được người dùng ánh xạ
        $hasCategoryCol = in_array('category', $passedKeys) || in_array('category_id', $passedKeys);
        $hasBrandCol    = in_array('brand', $passedKeys) || in_array('brand_id', $passedKeys);
        $hasSeriesCol   = in_array('series', $passedKeys) || in_array('series_id', $passedKeys);

        $hasImageCol    = in_array('image', $passedKeys);
        $hasGalleryCol  = in_array('gallery', $passedKeys);
        $hasCatalogCol  = in_array('catalog', $passedKeys);

        // 1. Tải trước Map dữ liệu Category, Brand, Series (Bao gồm cả slug và soft-deleted)
        $categoryMap = [];
        $brandMap    = [];
        $seriesMap   = [];

        if ($hasCategoryCol) {
            $categories = Category::withTrashed()->get(['id', 'name', 'slug', 'deleted_at']);
            foreach ($categories as $c) {
                $categoryMap[$c->id] = $c->id;
                $categoryMap[mb_strtolower(trim($c->name))] = $c->id;
                $categoryMap[Str::slug($c->name)] = $c->id;
                $categoryMap[$c->slug] = $c->id;
            }
        }

        if ($hasBrandCol) {
            $brands = Brand::all(['id', 'name', 'slug']);
            foreach ($brands as $b) {
                $brandMap[$b->id] = $b->id;
                $brandMap[mb_strtolower(trim($b->name))] = $b->id;
                $brandMap[Str::slug($b->name)] = $b->id;
                $brandMap[$b->slug] = $b->id;
            }
        }

        if ($hasSeriesCol) {
            $seriesList = Series::withTrashed()->get(['id', 'name', 'slug', 'deleted_at']);
            foreach ($seriesList as $s) {
                $seriesMap[$s->id] = $s->id;
                $seriesMap[mb_strtolower(trim($s->name))] = $s->id;
                $seriesMap[Str::slug($s->name)] = $s->id;
                $seriesMap[$s->slug] = $s->id;
            }
        }

        // 2. Xử lý Media Files & Download ảnh ngoài (External Image URLs)
        $localFilenames = [];
        $perRowMediaMap = []; // [$sku => ['thumbnail' => media_id, 'gallery' => [media_id, ...], 'catalog' => [media_id, ...]]]

        foreach ($rows as $r) {
            $rowSku = trim($r['sku'] ?? '');
            if (empty($rowSku)) continue;

            $rowSlug = Str::slug($r['slug'] ?? ($r['name'] ?? $rowSku));

            // A. Cột Image (Thumbnail)
            if ($hasImageCol && !empty($r['image'])) {
                $imgVal = trim($r['image']);
                if ($this->isExternalUrl($imgVal)) {
                    $mId = $this->downloadAndStoreExternalProductImage($imgVal, $rowSlug, 'thumbnail');
                    if ($mId) {
                        $perRowMediaMap[$rowSku]['thumbnail'] = $mId;
                    }
                } else {
                    $localFilenames[] = $imgVal;
                }
            }

            // B. Cột Gallery
            if ($hasGalleryCol && !empty($r['gallery'])) {
                $galleries = array_values(array_filter(array_map('trim', explode(',', $r['gallery']))));
                $gIndex = 1;
                foreach ($galleries as $gVal) {
                    if ($this->isExternalUrl($gVal)) {
                        $mId = $this->downloadAndStoreExternalProductImage($gVal, $rowSlug, 'gallery', $gIndex++);
                        if ($mId) {
                            $perRowMediaMap[$rowSku]['gallery'][] = $mId;
                        }
                    } else {
                        $localFilenames[] = $gVal;
                        $gIndex++;
                    }
                }
            }

            // C. Cột Catalog
            if ($hasCatalogCol && !empty($r['catalog'])) {
                $catalogs = array_values(array_filter(array_map('trim', explode(',', $r['catalog']))));
                foreach ($catalogs as $cVal) {
                    $localFilenames[] = $cVal;
                }
            }
        }

        // Phân giải các file local
        $localFilenames = array_unique(array_filter($localFilenames));
        $mediaMap = $this->resolveMediaIds($localFilenames, 'clients/imgs/products');

        if ($hasCatalogCol) {
            $catalogMap = $this->resolveMediaIds($localFilenames, 'clients/imgs/catalogs');
            $mediaMap = array_merge($mediaMap, $catalogMap);
        }

        // 3. Tìm sản phẩm hiện có kèm quan hệ images để so sánh sâu (Deep Diffing)
        $existingProducts = Product::whereIn('sku', $skus)->with('images')->get()->keyBy('sku');

        $toUpsert = [];
        $rowsToSyncMedia = [];
        $now = now()->toDateTimeString();

        $stats = [
            'updated' => 0,
            'inserted' => 0,
            'skipped' => 0,
        ];

        foreach ($rows as $row) {
            if (empty($row['sku'])) {
                continue;
            }

            $sku = mb_substr(trim($row['sku']), 0, 191);
            $isNew = !$existingProducts->has($sku);

            $cleanRow = ['sku' => $sku];

            // Tên: tự động cắt nếu dài hơn 500 ký tự (đồng bộ với DB varchar(500))
            if (array_key_exists('name', $row)) {
                $cleanRow['name'] = mb_substr(trim((string)$row['name']), 0, 500);
            }

            // Slug
            if (array_key_exists('slug', $row) && !empty($row['slug'])) {
                $cleanRow['slug'] = mb_substr(Str::slug($row['slug']), 0, 191);
            } elseif (isset($cleanRow['name'])) {
                $cleanRow['slug'] = mb_substr(Str::slug($cleanRow['name']), 0, 191);
            }

            // Giá
            if (array_key_exists('price', $row)) {
                $cleanRow['price'] = $this->cleanPrice($row['price']);
            }
            if (array_key_exists('sale_price', $row)) {
                $cleanRow['sale_price'] = $this->cleanPrice($row['sale_price'], true);
            }

            // Mô tả & Nội dung
            if (array_key_exists('short_description', $row)) {
                $cleanRow['short_description'] = $row['short_description'] !== '' ? $row['short_description'] : null;
            }
            if (array_key_exists('content', $row)) {
                $cleanRow['content'] = $row['content'] !== '' ? $row['content'] : null;
            }

            // SEO Meta & Nâng cao
            if (array_key_exists('meta_title', $row)) {
                $cleanRow['meta_title'] = $row['meta_title'] !== '' ? mb_substr(trim((string)$row['meta_title']), 0, 500) : null;
            }
            if (array_key_exists('meta_description', $row)) {
                $cleanRow['meta_description'] = $row['meta_description'] !== '' ? trim((string)$row['meta_description']) : null;
            }
            if (array_key_exists('meta_robots', $row)) {
                $cleanRow['meta_robots'] = $row['meta_robots'] !== '' ? mb_substr(trim((string)$row['meta_robots']), 0, 191) : null;
            }
            if (array_key_exists('canonical_url', $row)) {
                $cleanRow['canonical_url'] = $row['canonical_url'] !== '' ? mb_substr(trim((string)$row['canonical_url']), 0, 500) : null;
            }
            if (array_key_exists('og_title', $row)) {
                $cleanRow['og_title'] = $row['og_title'] !== '' ? mb_substr(trim((string)$row['og_title']), 0, 255) : null;
            }
            if (array_key_exists('og_description', $row)) {
                $cleanRow['og_description'] = $row['og_description'] !== '' ? trim((string)$row['og_description']) : null;
            }
            if (array_key_exists('og_image', $row)) {
                $cleanRow['og_image'] = $row['og_image'] !== '' ? mb_substr(trim((string)$row['og_image']), 0, 500) : null;
            }
            if (array_key_exists('in_sitemap', $row)) {
                $smVal = strtolower(trim((string)$row['in_sitemap']));
                $cleanRow['in_sitemap'] = in_array($smVal, ['1', 'true', 'yes', 'có', 'co'], true) ? 1 : 0;
            }
            if (array_key_exists('stock_status', $row)) {
                $stk = strtolower(trim((string)$row['stock_status']));
                $cleanRow['stock_status'] = match (true) {
                    in_array($stk, ['outofstock', 'out_of_stock', 'hết hàng', 'het hang', '0'], true) => 'outofstock',
                    in_array($stk, ['onbackorder', 'backorder', 'đặt hàng', 'dat hang'], true) => 'onbackorder',
                    default => 'instock',
                };
            }
            if (array_key_exists('product_type', $row)) {
                $pt = strtolower(trim((string)$row['product_type']));
                $cleanRow['product_type'] = in_array($pt, ['simple', 'variable', 'grouped', 'external'], true) ? $pt : 'simple';
            }

            // Trạng thái
            if (array_key_exists('status', $row)) {
                $st = strtolower(trim((string)$row['status']));
                $cleanRow['status'] = in_array($st, ['active', '1', 'true', 'hiển thị', 'đang bán'], true) ? 'active' : 'draft';
            }

            // Ngày giờ xuất bản (published_at / publish / ngay_xuat_ban)
            $pubVal = $row['published_at'] ?? $row['publish'] ?? $row['ngay_xuat_ban'] ?? null;
            if (!empty($pubVal)) {
                try {
                    $pubValStr = trim((string)$pubVal);
                    if (is_numeric($pubValStr)) {
                        if ((float)$pubValStr > 2000000000) {
                            $cleanRow['published_at'] = \Carbon\Carbon::createFromTimestampMs((float)$pubValStr, 'Asia/Ho_Chi_Minh')->toDateTimeString();
                        } elseif ((float)$pubValStr > 100000000) {
                            $cleanRow['published_at'] = \Carbon\Carbon::createFromTimestamp((int)$pubValStr, 'Asia/Ho_Chi_Minh')->toDateTimeString();
                        } else {
                            // Hỗ trợ định dạng số ngày serial của Excel (VD: 45678.5)
                            $cleanRow['published_at'] = \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$pubValStr))->timezone('Asia/Ho_Chi_Minh')->toDateTimeString();
                        }
                    } else {
                        // Thử parse các định dạng thông dụng: d/m/Y H:i:s, d/m/Y H:i, d/m/Y, Y-m-d H:i:s...
                        $cleanRow['published_at'] = \Carbon\Carbon::parse($pubValStr, 'Asia/Ho_Chi_Minh')->toDateTimeString();
                    }
                } catch (\Exception $e) {
                    $cleanRow['published_at'] = \Carbon\Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString();
                }
            } elseif ($isNew && !array_key_exists('published_at', $cleanRow)) {
                $cleanRow['published_at'] = \Carbon\Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString();
            }

            // Danh mục
            if ($hasCategoryCol) {
                $catVal = $row['category'] ?? $row['category_id'] ?? null;
                if (!empty($catVal)) {
                    $catName = trim((string)$catVal);
                    $catSlug = Str::slug($catName);
                    $lookup = is_numeric($catVal) ? (int)$catVal : mb_strtolower($catName);

                    if (isset($categoryMap[$lookup])) {
                        $cleanRow['category_id'] = $categoryMap[$lookup];
                    } elseif (isset($categoryMap[$catSlug])) {
                        $cleanRow['category_id'] = $categoryMap[$catSlug];
                    } elseif (!is_numeric($catVal)) {
                        // Tìm trong DB (kể cả đã bị soft-deleted hoặc lệch Unicode normalization)
                        $existingCat = Category::withTrashed()
                            ->where('slug', $catSlug)
                            ->orWhere('name', $catName)
                            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($catName)])
                            ->first();

                        if ($existingCat) {
                            if ($existingCat->trashed()) {
                                $existingCat->restore();
                            }
                            $catId = $existingCat->id;
                        } else {
                            try {
                                $newCat = Category::create([
                                    'name'   => $catName,
                                    'slug'   => $catSlug,
                                    'status' => 'active',
                                ]);
                                $catId = $newCat->id;
                            } catch (\Exception $e) {
                                // Nếu bị lỗi trùng slug do race condition hoặc alias
                                $catId = Category::withTrashed()->where('slug', $catSlug)->value('id');
                            }
                        }

                        if ($catId) {
                            $categoryMap[$catId] = $catId;
                            $categoryMap[$lookup] = $catId;
                            $categoryMap[$catSlug] = $catId;
                            $categoryMap[mb_strtolower($catName)] = $catId;
                            $cleanRow['category_id'] = $catId;
                        } else {
                            $cleanRow['category_id'] = null;
                        }
                    } else {
                        $cleanRow['category_id'] = null;
                    }
                } else {
                    $cleanRow['category_id'] = null;
                }
            }

            // Thương hiệu (Hãng sản xuất)
            if ($hasBrandCol) {
                $brandVal = $row['brand'] ?? $row['brand_id'] ?? null;
                if (!empty($brandVal)) {
                    $brandName = trim((string)$brandVal);
                    $brandSlug = Str::slug($brandName);
                    $lookup = is_numeric($brandVal) ? (int)$brandVal : mb_strtolower($brandName);

                    if (isset($brandMap[$lookup])) {
                        $cleanRow['brand_id'] = $brandMap[$lookup];
                    } elseif (isset($brandMap[$brandSlug])) {
                        $cleanRow['brand_id'] = $brandMap[$brandSlug];
                    } elseif (!is_numeric($brandVal)) {
                        // Tìm trong DB
                        $existingBrand = Brand::where('slug', $brandSlug)
                            ->orWhere('name', $brandName)
                            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($brandName)])
                            ->first();

                        if ($existingBrand) {
                            $brandId = $existingBrand->id;
                        } else {
                            try {
                                $newBrand = Brand::create([
                                    'name' => $brandName,
                                    'slug' => $brandSlug,
                                ]);
                                $brandId = $newBrand->id;
                            } catch (\Exception $e) {
                                $brandId = Brand::where('slug', $brandSlug)->value('id');
                            }
                        }

                        if ($brandId) {
                            $brandMap[$brandId] = $brandId;
                            $brandMap[$lookup] = $brandId;
                            $brandMap[$brandSlug] = $brandId;
                            $brandMap[mb_strtolower($brandName)] = $brandId;
                            $cleanRow['brand_id'] = $brandId;
                        } else {
                            $cleanRow['brand_id'] = null;
                        }
                    } else {
                        $cleanRow['brand_id'] = null;
                    }
                } else {
                    $cleanRow['brand_id'] = null;
                }
            }

            // Dòng sản phẩm (Series)
            if ($hasSeriesCol) {
                $seriesVal = $row['series'] ?? $row['series_id'] ?? null;
                if (!empty($seriesVal)) {
                    $seriesName = trim((string)$seriesVal);
                    $seriesSlug = Str::slug($seriesName);
                    $lookup = is_numeric($seriesVal) ? (int)$seriesVal : mb_strtolower($seriesName);

                    if (isset($seriesMap[$lookup])) {
                        $cleanRow['series_id'] = $seriesMap[$lookup];
                    } elseif (isset($seriesMap[$seriesSlug])) {
                        $cleanRow['series_id'] = $seriesMap[$seriesSlug];
                    } elseif (!is_numeric($seriesVal)) {
                        // Tìm trong DB
                        $existingSeries = Series::withTrashed()
                            ->where('slug', $seriesSlug)
                            ->orWhere('name', $seriesName)
                            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($seriesName)])
                            ->first();

                        if ($existingSeries) {
                            if ($existingSeries->trashed()) {
                                $existingSeries->restore();
                            }
                            $seriesId = $existingSeries->id;
                        } else {
                            try {
                                $newSeries = Series::create([
                                    'name'        => $seriesName,
                                    'slug'        => $seriesSlug,
                                    'status'      => 'active',
                                    'brand_id'    => $cleanRow['brand_id'] ?? null,
                                    'category_id' => $cleanRow['category_id'] ?? null,
                                ]);
                                $seriesId = $newSeries->id;
                            } catch (\Exception $e) {
                                $seriesId = Series::withTrashed()->where('slug', $seriesSlug)->value('id');
                            }
                        }

                        if ($seriesId) {
                            $seriesMap[$seriesId] = $seriesId;
                            $seriesMap[$lookup] = $seriesId;
                            $seriesMap[$seriesSlug] = $seriesId;
                            $seriesMap[mb_strtolower($seriesName)] = $seriesId;
                            $cleanRow['series_id'] = $seriesId;
                        } else {
                            $cleanRow['series_id'] = null;
                        }
                    } else {
                        $cleanRow['series_id'] = null;
                    }
                } else {
                    $cleanRow['series_id'] = null;
                }
            }

            // Đảm bảo các trường bắt buộc của database luôn có giá trị
            if (!array_key_exists('price', $cleanRow) || $cleanRow['price'] === null) {
                if (!$isNew) {
                    $cleanRow['price'] = (float)$existingProducts->get($sku)->price;
                } else {
                    $cleanRow['price'] = 0.00;
                }
            }

            // === SO SÁNH SÂU (DEEP DIFFING) ===
            if (!$isNew) {
                if ($mode === 'insert_ignore') {
                    $stats['skipped']++;
                    continue;
                }

                $existing = $existingProducts->get($sku);
                $isDirty = false;
                $mediaDirty = false;

                // 1. So sánh các trường thông tin cơ bản
                foreach ($cleanRow as $k => $newVal) {
                    if ($k === 'sku') continue;

                    if (!in_array($k, $passedKeys) && !in_array($k, ['category_id', 'brand_id', 'series_id'])) {
                        continue;
                    }
                    if ($k === 'category_id' && !$hasCategoryCol) continue;
                    if ($k === 'brand_id' && !$hasBrandCol) continue;
                    if ($k === 'series_id' && !$hasSeriesCol) continue;

                    $oldVal = $existing->{$k};

                    if ($k === 'price' || $k === 'sale_price') {
                        $oldNum = is_null($oldVal) ? null : round((float)$oldVal, 2);
                        $newNum = is_null($newVal) ? null : round((float)$newVal, 2);
                        if ($oldNum !== $newNum) {
                            $isDirty = true;
                            break;
                        }
                    } elseif ($k === 'category_id' || $k === 'brand_id' || $k === 'series_id') {
                        $oldId = is_null($oldVal) ? null : (int)$oldVal;
                        $newId = is_null($newVal) ? null : (int)$newVal;
                        if ($oldId !== $newId) {
                            $isDirty = true;
                            break;
                        }
                    } elseif ($k === 'in_sitemap') {
                        if ((bool) $oldVal !== (bool) $newVal) {
                            $isDirty = true;
                            break;
                        }
                    } elseif ($k === 'published_at') {
                        $oldDt = $oldVal ? \Carbon\Carbon::parse($oldVal)->toDateTimeString() : null;
                        $newDt = $newVal ? \Carbon\Carbon::parse($newVal)->toDateTimeString() : null;
                        if ($oldDt !== $newDt) {
                            $isDirty = true;
                            break;
                        }
                    } else {
                        if (trim((string)($oldVal ?? '')) !== trim((string)($newVal ?? ''))) {
                            $isDirty = true;
                            break;
                        }
                    }
                }

                // 2. So sánh Media (image, gallery, catalog)
                if ($hasImageCol || $hasGalleryCol || $hasCatalogCol) {
                    $existingMedia = $existing->images;

                    if ($hasImageCol) {
                        $oldThumb = $existingMedia->where('pivot.role', 'thumbnail')->first()?->filename ?? '';
                        $newThumbInput = trim($row['image'] ?? '');
                        
                        // Nếu có download link ngoài -> so sánh media_id
                        if (isset($perRowMediaMap[$sku]['thumbnail'])) {
                            $oldThumbId = $existingMedia->where('pivot.role', 'thumbnail')->first()?->id;
                            if ($oldThumbId !== $perRowMediaMap[$sku]['thumbnail']) {
                                $mediaDirty = true;
                            }
                        } elseif ($oldThumb !== $newThumbInput) {
                            $mediaDirty = true;
                        }
                    }

                    if ($hasGalleryCol) {
                        $oldGalleries = $existingMedia->where('pivot.role', 'gallery')->sortBy('pivot.position')->pluck('filename')->values()->all();
                        $newGalleries = array_values(array_filter(array_map('trim', explode(',', $row['gallery'] ?? ''))));
                        
                        if (isset($perRowMediaMap[$sku]['gallery'])) {
                            $oldGalleryIds = $existingMedia->where('pivot.role', 'gallery')->sortBy('pivot.position')->pluck('id')->values()->all();
                            if ($oldGalleryIds !== $perRowMediaMap[$sku]['gallery']) {
                                $mediaDirty = true;
                            }
                        } elseif ($oldGalleries !== $newGalleries) {
                            $mediaDirty = true;
                        }
                    }

                    if ($hasCatalogCol) {
                        $oldCatalogs = $existingMedia->where('pivot.role', 'catalog')->sortBy('pivot.position')->pluck('filename')->values()->all();
                        $newCatalogs = array_values(array_filter(array_map('trim', explode(',', $row['catalog'] ?? ''))));
                        if ($oldCatalogs !== $newCatalogs) {
                            $mediaDirty = true;
                        }
                    }
                }

                // 3. Phân loại kết quả
                if ($isDirty || $mediaDirty) {
                    if ($isDirty) {
                        // Đảm bảo các cột NOT NULL (name, slug) luôn có mặt cho câu INSERT ... ON DUPLICATE KEY UPDATE (MySQL Strict Mode)
                        if (!array_key_exists('name', $cleanRow)) {
                            $cleanRow['name'] = (string) ($existing->name ?? $sku);
                        }
                        if (!array_key_exists('slug', $cleanRow)) {
                            $cleanRow['slug'] = (string) ($existing->slug ?? Str::slug($cleanRow['name']));
                        }
                        $cleanRow['updated_at'] = $now;
                        $cleanRow['created_at'] = $existing->created_at ? $existing->created_at->toDateTimeString() : $now;
                        $toUpsert[] = $cleanRow;
                    }
                    if ($mediaDirty) {
                        $rowsToSyncMedia[] = $row;
                    }
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                // Thêm mới sản phẩm
                $cleanRow['created_at'] = $now;
                $cleanRow['updated_at'] = $now;
                if (!isset($cleanRow['status'])) $cleanRow['status'] = 'active';
                if (!isset($cleanRow['slug'])) $cleanRow['slug'] = Str::slug($cleanRow['name'] ?? $sku);

                $toUpsert[] = $cleanRow;
                if ($hasImageCol || $hasGalleryCol || $hasCatalogCol) {
                    $rowsToSyncMedia[] = $row;
                }
                $stats['inserted']++;
            }
        }

        // 4. Bulk Upsert
        if (!empty($toUpsert)) {
            $allKeys = [];
            foreach ($toUpsert as $r) {
                foreach (array_keys($r) as $k) {
                    $allKeys[$k] = true;
                }
            }
            $finalKeys = array_keys($allKeys);

            $normalizedUpsert = [];
            foreach ($toUpsert as $r) {
                $norm = [];
                foreach ($finalKeys as $k) {
                    $norm[$k] = array_key_exists($k, $r) ? $r[$k] : null;
                }
                $normalizedUpsert[] = $norm;
            }

            $chunks = array_chunk($normalizedUpsert, 1000);

            if ($mode === 'insert_ignore') {
                foreach ($chunks as $chunk) {
                    Product::insert($chunk);
                }
            } else {
                // Chỉ cập nhật đúng các cột thực sự được người dùng ánh xạ (không ghi đè cột bổ trợ name/slug/price)
                $mappedDbKeys = $passedKeys;
                if ($hasCategoryCol) $mappedDbKeys[] = 'category_id';
                if ($hasBrandCol)    $mappedDbKeys[] = 'brand_id';
                if ($hasSeriesCol)   $mappedDbKeys[] = 'series_id';
                if (in_array('publish', $passedKeys, true) || in_array('ngay_xuat_ban', $passedKeys, true)) {
                    $mappedDbKeys[] = 'published_at';
                }
                if (in_array('name', $passedKeys, true) && !in_array('slug', $passedKeys, true)) {
                    $mappedDbKeys[] = 'slug';
                }

                $updateColumns = array_values(array_intersect(
                    array_diff($finalKeys, ['sku', 'created_at']),
                    $mappedDbKeys
                ));
                if (!in_array('updated_at', $updateColumns, true)) {
                    $updateColumns[] = 'updated_at';
                }

                foreach ($chunks as $chunk) {
                    Product::upsert(
                        $chunk,
                        ['sku'],
                        $updateColumns
                    );
                }
            }

            // Đồng bộ bảng trung gian category_product nếu có cập nhật cột danh mục
            if ($hasCategoryCol) {
                $upsertedSkus = array_values(array_unique(array_column($toUpsert, 'sku')));
                $syncedCatProds = DB::table('products')
                    ->whereIn('sku', $upsertedSkus)
                    ->whereNotNull('category_id')
                    ->get(['id', 'category_id']);

                if ($syncedCatProds->isNotEmpty()) {
                    $prodIds = $syncedCatProds->pluck('id')->all();
                    DB::table('category_product')->whereIn('product_id', $prodIds)->delete();
                    $catPivotRows = [];
                    foreach ($syncedCatProds as $cp) {
                        $catPivotRows[] = [
                            'category_id' => (int) $cp->category_id,
                            'product_id'  => (int) $cp->id,
                            'is_primary'  => 1,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ];
                    }
                    foreach (array_chunk($catPivotRows, 1000) as $pivotChunk) {
                        DB::table('category_product')->insert($pivotChunk);
                    }
                }
            }
        }

        // 5. Đồng bộ Media
        if (!empty($rowsToSyncMedia)) {
            $syncSkus = array_column($rowsToSyncMedia, 'sku');
            $syncedProducts = Product::whereIn('sku', $syncSkus)->with('images')->get()->keyBy('sku');

            foreach ($rowsToSyncMedia as $row) {
                $sku = trim($row['sku'] ?? '');
                if (empty($sku) || !$syncedProducts->has($sku)) continue;

                $product = $syncedProducts->get($sku);
                $existingMedia = $product->images;
                $syncData = [];

                // 1. Thumbnail
                if ($hasImageCol) {
                    if (isset($perRowMediaMap[$sku]['thumbnail'])) {
                        $syncData[$perRowMediaMap[$sku]['thumbnail']] = ['role' => 'thumbnail', 'position' => 0];
                    } else {
                        $imgName = trim($row['image'] ?? '');
                        if ($imgName && isset($mediaMap[$imgName])) {
                            $syncData[$mediaMap[$imgName]] = ['role' => 'thumbnail', 'position' => 0];
                        }
                    }
                } else {
                    foreach ($existingMedia->where('pivot.role', 'thumbnail') as $m) {
                        $syncData[$m->id] = ['role' => 'thumbnail', 'position' => $m->pivot->position];
                    }
                }

                // 2. Gallery
                if ($hasGalleryCol) {
                    $pos = 0;
                    if (isset($perRowMediaMap[$sku]['gallery']) && !empty($perRowMediaMap[$sku]['gallery'])) {
                        foreach ($perRowMediaMap[$sku]['gallery'] as $gId) {
                            if (!isset($syncData[$gId])) {
                                $syncData[$gId] = ['role' => 'gallery', 'position' => $pos++];
                            }
                        }
                    } else {
                        $galleryNames = array_filter(array_map('trim', explode(',', $row['gallery'] ?? '')));
                        foreach ($galleryNames as $gn) {
                            if (isset($mediaMap[$gn]) && !isset($syncData[$mediaMap[$gn]])) {
                                $syncData[$mediaMap[$gn]] = ['role' => 'gallery', 'position' => $pos++];
                            }
                        }
                    }
                } else {
                    foreach ($existingMedia->where('pivot.role', 'gallery') as $m) {
                        $syncData[$m->id] = ['role' => 'gallery', 'position' => $m->pivot->position];
                    }
                }

                // 3. Catalog
                if ($hasCatalogCol) {
                    $catalogNames = array_filter(array_map('trim', explode(',', $row['catalog'] ?? '')));
                    $pos = 0;
                    foreach ($catalogNames as $cn) {
                        if (isset($mediaMap[$cn]) && !isset($syncData[$mediaMap[$cn]])) {
                            $syncData[$mediaMap[$cn]] = ['role' => 'catalog', 'position' => $pos++];
                        }
                    }
                } else {
                    foreach ($existingMedia->where('pivot.role', 'catalog') as $m) {
                        $syncData[$m->id] = ['role' => 'catalog', 'position' => $m->pivot->position];
                    }
                }

                $product->images()->sync($syncData);
            }
        }

        return $stats;
    }

    /**
     * Kiểm tra xem chuỗi có phải là link ngoài HTTP/HTTPS hay không.
     */
    private function isExternalUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    /**
     * Tải ảnh từ URL bên ngoài, lưu vào thư mục products và tạo/cập nhật Media với tên file là {slug}.{ext} hoặc {slug}-{index}.{ext}
     *
     * @param string $url URL ảnh bên ngoài
     * @param string $slug Slug của sản phẩm
     * @param string $role 'thumbnail' hoặc 'gallery'
     * @param int $index Số thứ tự (dành cho gallery: 1, 2, 3...)
     * @return int|null Media ID
     */
    private function downloadAndStoreExternalProductImage(string $url, string $slug, string $role = 'thumbnail', int $index = 1): ?int
    {
        $url = trim($url);
        if (!$this->isExternalUrl($url)) {
            return null;
        }

        $folder = 'clients/imgs/products';

        try {
            // Tải nội dung ảnh qua HTTP (tối đa 5s kết nối, 10s tải để tránh nghẽn)
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();
            if (empty($body)) {
                return null;
            }

            // 1. Xác định đuôi mở rộng file ảnh (extension)
            $urlPath = parse_url($url, PHP_URL_PATH) ?: '';
            $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));

            $validExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'];
            if (!in_array($ext, $validExtensions)) {
                $contentType = strtolower($response->header('Content-Type') ?? '');
                if (str_contains($contentType, 'image/png')) {
                    $ext = 'png';
                } elseif (str_contains($contentType, 'image/webp')) {
                    $ext = 'webp';
                } elseif (str_contains($contentType, 'image/gif')) {
                    $ext = 'gif';
                } elseif (str_contains($contentType, 'image/svg')) {
                    $ext = 'svg';
                } elseif (str_contains($contentType, 'image/avif')) {
                    $ext = 'avif';
                } else {
                    $ext = 'jpg';
                }
            }

            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }

            // 2. Đặt tên file theo chuẩn yêu cầu:
            // - Thumbnail: {slug}.{ext}
            // - Gallery: {slug}-{index}.{ext}
            if ($role === 'gallery') {
                $baseNameWithoutExt = $slug . '-' . $index;
                $filename = $baseNameWithoutExt . '.' . $ext;
            } else {
                $baseNameWithoutExt = $slug;
                $filename = $baseNameWithoutExt . '.' . $ext;
            }

            $relativePath = $folder . '/' . $filename;

            // 3. Cơ chế REPLACE: Xóa các file ảnh cũ cùng tên/khác đuôi trên disk và DB trước khi ghi đè
            $possibleExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'];
            foreach ($possibleExtensions as $oldExt) {
                $oldRelPath = $folder . '/' . $baseNameWithoutExt . '.' . $oldExt;
                if ($oldRelPath !== $relativePath && Storage::disk('public')->exists($oldRelPath)) {
                    Storage::disk('public')->delete($oldRelPath);
                }
            }

            // Ghi đè trực tiếp nội dung ảnh mới lên ổ đĩa
            Storage::disk('public')->put($relativePath, $body);

            $size = strlen($body);
            $mime = ($ext === 'svg') ? 'image/svg+xml' : ('image/' . ($ext === 'jpg' ? 'jpeg' : $ext));
            $now = now();

            // 4. Cập nhật hoặc Lưu mới bản ghi Media trong Database
            // Xóa các bản ghi Media cũ cùng baseName nhưng khác extension để tránh trùng lặp
            Media::where('folder', $folder)
                ->where('filename', '!=', $filename)
                ->where(function($q) use ($baseNameWithoutExt, $possibleExtensions) {
                    foreach ($possibleExtensions as $pExt) {
                        $q->orWhere('filename', $baseNameWithoutExt . '.' . $pExt);
                    }
                })
                ->delete();

            $existingMedia = Media::where('folder', $folder)
                ->where('filename', $filename)
                ->first();

            if ($existingMedia) {
                $existingMedia->update([
                    'disk'          => 'public',
                    'original_name' => $filename,
                    'extension'     => $ext,
                    'mime_type'     => $mime,
                    'size'          => $size,
                    'status'        => 'active',
                    'updated_at'    => $now,
                ]);
                return $existingMedia->id;
            } else {
                return Media::insertGetId([
                    'disk'          => 'public',
                    'folder'        => $folder,
                    'filename'      => $filename,
                    'original_name' => $filename,
                    'extension'     => $ext,
                    'mime_type'     => $mime,
                    'size'          => $size,
                    'status'        => 'active',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Làm sạch và chuẩn hóa giá tiền.
     */
    private function cleanPrice($val, bool $nullable = false): ?float
    {
        if (is_null($val) || $val === '') {
            return $nullable ? null : 0.0;
        }

        if (is_numeric($val)) {
            return (float)$val;
        }

        $clean = preg_replace('/[^\d.,]/', '', (string)$val);
        if (strpos($clean, '.') !== false && strpos($clean, ',') !== false) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (substr_count($clean, '.') > 1) {
            $clean = str_replace('.', '', $clean);
        } elseif (substr_count($clean, ',') > 1) {
            $clean = str_replace(',', '', $clean);
        } elseif (strpos($clean, ',') !== false) {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float)$clean : ($nullable ? null : 0.0);
    }

    /**
     * Tra cứu ID Media hoặc tự động tạo nếu là link ngoài (External URL) hoặc đã có file trên disk.
     */
    private function resolveMediaIds(array $filenames, string $folder): array
    {
        if (empty($filenames)) return [];

        $map = [];

        // 1. Tìm các media đã có sẵn trong database theo filename
        $existingMedia = Media::whereIn('filename', $filenames)->get();
        foreach ($existingMedia as $m) {
            $map[$m->filename] = $m->id;
        }

        $missing = array_diff($filenames, array_keys($map));
        if (!empty($missing)) {
            $now = now();

            foreach ($missing as $filename) {
                $filename = trim($filename);
                if (empty($filename)) continue;

                // Nếu là đường dẫn URL catalog ngoài (Ví dụ: https://.../BW-Series.pdf)
                if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
                    $urlPath = parse_url($filename, PHP_URL_PATH) ?: $filename;
                    $origName = basename($urlPath) ?: 'Tai_lieu_catalog';
                    $ext = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION)) ?: 'pdf';
                    $mime = ($ext === 'pdf') ? 'application/pdf' : 'application/octet-stream';

                    $mediaId = Media::insertGetId([
                        'disk'          => 'external',
                        'folder'        => 'external',
                        'filename'      => $filename,
                        'original_name' => $origName,
                        'extension'     => $ext,
                        'mime_type'     => $mime,
                        'size'          => 0,
                        'status'        => 'active',
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                    $map[$filename] = $mediaId;
                    continue;
                }

                // Nếu là file local trong storage
                $relativePath = $folder . '/' . $filename;

                if (Storage::disk('public')->exists($relativePath)) {
                    $mime = Storage::disk('public')->mimeType($relativePath);
                    $size = Storage::disk('public')->size($relativePath);
                    $ext  = pathinfo($filename, PATHINFO_EXTENSION);

                    $mediaId = Media::insertGetId([
                        'disk'          => 'public',
                        'folder'        => $folder,
                        'filename'      => $filename,
                        'original_name' => $filename,
                        'extension'     => $ext,
                        'mime_type'     => $mime,
                        'size'          => $size,
                        'status'        => 'active',
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                    $map[$filename] = $mediaId;
                }
            }
        }

        return $map;
    }
}
