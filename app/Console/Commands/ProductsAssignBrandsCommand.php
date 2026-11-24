<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductsAssignBrandsCommand extends Command
{
    protected $signature = 'products:assign-brands
        {file? : Đường dẫn file CSV sản phẩm để đồng bộ hãng (ví dụ: public/Full sản phẩm HPT.csv)}
        {--from-name : Nhận diện và gán hãng trực tiếp từ tên sản phẩm trong DB (không cần file CSV)}
        {--limit=0 : Giới hạn số lượng sản phẩm xử lý (để test số lượng ít, 0 = không giới hạn)}
        {--only-empty : Chỉ xử lý sản phẩm chưa có hãng (brand_id IS NULL) (mặc định)}
        {--force : Gán lại cho tất cả sản phẩm, kể cả đã có brand_id}
        {--dry-run : Chạy thử kiểm tra, không lưu vào database}';

    protected $description = 'Gắn lại thương hiệu (brand_id) cho sản phẩm từ file CSV hoặc tự động nhận diện từ tên sản phẩm.';

    /** @var array<string, int> */
    protected array $brandByLowerName = [];

    /** @var array<string, int> */
    protected array $brandBySlug = [];

    /** @var array<int, array{id: int, name: string, pattern: string}> */
    protected array $sortedBrands = [];

    public function handle(): int
    {
        $filePath = (string) ($this->argument('file') ?? '');
        $fromName = (bool) $this->option('from-name');
        $limit = max(0, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $this->loadBrands();

        if (empty($this->brandBySlug)) {
            $this->error('Không tìm thấy thương hiệu nào trong bảng brands! Vui lòng chạy lệnh php artisan brands:import trước.');

            return self::FAILURE;
        }

        $this->info(sprintf('Đã tải %d thương hiệu từ bảng brands.', count($this->brandBySlug)));

        // Nếu người dùng không chỉ định --from-name nhưng có truyền file CSV (hoặc có file mặc định)
        if (! $fromName && $filePath !== '') {
            if (! file_exists($filePath)) {
                $this->error("Không tìm thấy file CSV: {$filePath}");

                return self::FAILURE;
            }

            return $this->assignFromCsv($filePath, $limit, $force, $dryRun);
        }

        // Tự động tìm file CSV nếu có ở public/Full sản phẩm HPT.csv khi không bật --from-name
        $defaultCsv = base_path('public/Full sản phẩm HPT.csv');
        if (! $fromName && file_exists($defaultCsv)) {
            $this->info("Tự động phát hiện file CSV: {$defaultCsv}");

            return $this->assignFromCsv($defaultCsv, $limit, $force, $dryRun);
        }

        // Mặc định hoặc khi bật --from-name: Nhận diện trực tiếp từ tên sản phẩm
        $this->info("Bắt đầu nhận diện và gắn hãng trực tiếp từ tên sản phẩm trong database...");

        return $this->assignFromName($limit, $force, $dryRun);
    }

    /**
     * Nạp dữ liệu thương hiệu vào bộ nhớ
     */
    protected function loadBrands(): void
    {
        $brands = Brand::select(['id', 'name', 'slug'])->get();

        foreach ($brands as $b) {
            $lower = mb_strtolower(trim($b->name), 'UTF-8');
            if ($lower !== '') {
                $this->brandByLowerName[$lower] = (int) $b->id;
            }
            if (! empty($b->slug)) {
                $this->brandBySlug[strtolower((string) $b->slug)] = (int) $b->id;
            }
        }

        // Sắp xếp các brand theo độ dài tên giảm dần (để match từ dài trước, từ ngắn sau)
        $brandList = $brands->map(function ($b) {
            return [
                'id' => (int) $b->id,
                'name' => trim($b->name),
                'len' => mb_strlen(trim($b->name), 'UTF-8'),
            ];
        })->sortByDesc('len')->values()->all();

        $this->sortedBrands = [];
        foreach ($brandList as $item) {
            $escaped = preg_quote($item['name'], '/');
            $this->sortedBrands[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'pattern' => '/(?:\b|_)' . $escaped . '(?:\b|_)/iu',
            ];
        }
    }

    /**
     * Đồng bộ hãng từ file CSV (chính xác 100% theo cột 'Thương hiệu' của file gốc)
     */
    protected function assignFromCsv(string $filePath, int $limit, bool $force, bool $dryRun): int
    {
        $this->info("Đang đọc file CSV: {$filePath}" . ($dryRun ? ' [CHẾ ĐỘ DRY-RUN]' : ''));

        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            $this->error("Không thể mở file CSV: {$filePath}");

            return self::FAILURE;
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $header = fgetcsv($fh);
        if (! is_array($header) || count($header) < 3) {
            fclose($fh);
            $this->error("Header file CSV không hợp lệ.");

            return self::FAILURE;
        }

        $colMap = [
            'id' => null,
            'sku' => null,
            'slug' => null,
            'name' => null,
            'brand' => null,
        ];

        foreach ($header as $idx => $rawCol) {
            $norm = mb_strtolower(trim((string) $rawCol), 'UTF-8');
            $norm = preg_replace('/^\x{FEFF}/u', '', $norm);
            match (true) {
                $norm === 'id' || $norm === 'post_id' || $norm === 'wp_id' => $colMap['id'] = $idx,
                $norm === 'mã sản phẩm' || $norm === 'ma san pham' || $norm === 'sku' => $colMap['sku'] = $idx,
                $norm === 'đường dẫn' || $norm === 'duong dan' || $norm === 'slug' || $norm === 'post_name' => $colMap['slug'] = $idx,
                $norm === 'tên sản phẩm' || $norm === 'ten san pham' || $norm === 'tên' || $norm === 'name' || $norm === 'post_title' => $colMap['name'] = $idx,
                $norm === 'thương hiệu' || $norm === 'thuong hieu' || $norm === 'brand' || $norm === 'brands' => $colMap['brand'] = $idx,
                default => null,
            };
        }

        if ($colMap['brand'] === null) {
            fclose($fh);
            $this->error("Không tìm thấy cột 'Thương hiệu' hoặc 'brand' trong file CSV.");

            return self::FAILURE;
        }

        // Lập bản đồ tra cứu sản phẩm trong DB (id, brand_id)
        $byWpId = [];
        $bySlug = [];
        $bySku = [];
        $productQuery = Product::select(['id', 'source_wp_id', 'slug', 'sku', 'brand_id', 'name']);
        if (! $force) {
            $productQuery->whereNull('brand_id');
        }

        foreach ($productQuery->cursor() as $p) {
            $pid = (int) $p->id;
            if (! empty($p->source_wp_id)) {
                $byWpId[(int) $p->source_wp_id] = $p;
            }
            if (! empty($p->slug)) {
                $bySlug[strtolower((string) $p->slug)] = $p;
            }
            if (! empty($p->sku)) {
                $bySku[strtoupper((string) $p->sku)] = $p;
            }
        }

        $totalFound = count($byWpId) + count($bySlug);
        $this->info("Số sản phẩm trong DB cần xử lý: {$totalFound}");

        $updated = 0;
        $unmatchedBrand = 0;
        $skipped = 0;
        $tableSample = [];

        while (($row = fgetcsv($fh)) !== false) {
            $rawBrand = trim((string) ($row[$colMap['brand']] ?? ''));
            if ($rawBrand === '') {
                $skipped++;
                continue;
            }

            $brandId = $this->resolveBrandId($rawBrand);
            if ($brandId === null) {
                $unmatchedBrand++;
                continue;
            }

            $wpId = (int) ($row[$colMap['id']] ?? 0);
            $slug = strtolower(trim((string) ($row[$colMap['slug']] ?? '')));
            $sku = strtoupper(trim((string) ($row[$colMap['sku']] ?? '')));

            /** @var Product|null $product */
            $product = null;
            if ($wpId > 0 && isset($byWpId[$wpId])) {
                $product = $byWpId[$wpId];
            } elseif ($slug !== '' && isset($bySlug[$slug])) {
                $product = $bySlug[$slug];
            } elseif ($sku !== '' && isset($bySku[$sku])) {
                $product = $bySku[$sku];
            }

            if (! $product) {
                continue;
            }

            if (! $dryRun) {
                DB::table('products')->where('id', $product->id)->update(['brand_id' => $brandId]);
            }

            // Loại bỏ khỏi map để tránh update trùng nếu có nhiều dòng
            unset($byWpId[$wpId], $bySlug[$slug], $bySku[$sku]);

            $brandName = array_search($brandId, $this->brandBySlug, true) ?: (string) $brandId;

            if (count($tableSample) < 15) {
                $tableSample[] = [
                    $product->id,
                    $product->sku ?: '-',
                    Str::limit($product->name, 40),
                    $rawBrand,
                    "ID #{$brandId}",
                    $dryRun ? 'Sẽ cập nhật' : 'Đã cập nhật',
                ];
            }

            $updated++;

            if ($limit > 0 && $updated >= $limit) {
                $this->warn("Đã đạt giới hạn --limit={$limit} sản phẩm kiểm thử.");
                break;
            }
        }

        fclose($fh);

        if (! empty($tableSample)) {
            $this->table(['SP ID', 'SKU', 'Tên sản phẩm', 'Hãng trong CSV', 'Brand ID', 'Trạng thái'], $tableSample);
        }

        if (! $dryRun && $updated > 0) {
            $this->clearCaches();
        }

        $this->newLine();
        $this->info("=== HOÀN TẤT ĐỒNG BỘ HÃNG TỪ CSV ===");
        $this->line(" - Đã cập nhật hãng cho: {$updated} sản phẩm");
        $this->line(" - Hãng không khớp trong DB: {$unmatchedBrand}");
        $this->line(" - Dòng CSV không có hãng: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Tự động quét và nhận diện hãng từ tên sản phẩm trong DB
     */
    protected function assignFromName(int $limit, bool $force, bool $dryRun): int
    {
        $query = Product::select(['id', 'name', 'slug', 'sku', 'brand_id']);
        if (! $force) {
            $query->whereNull('brand_id');
        }

        $totalCandidates = $query->count();
        $this->info("Tìm thấy {$totalCandidates} sản phẩm cần kiểm tra tên để nhận diện hãng.");

        if ($totalCandidates === 0) {
            $this->info("Không có sản phẩm nào cần gán hãng.");

            return self::SUCCESS;
        }

        $updated = 0;
        $unmatched = 0;
        $tableSample = [];

        foreach ($query->cursor() as $product) {
            $matchedBrand = $this->matchBrandFromName((string) $product->name);

            if ($matchedBrand === null) {
                $unmatched++;
                continue;
            }

            if (! $dryRun) {
                DB::table('products')->where('id', $product->id)->update(['brand_id' => $matchedBrand['id']]);
            }

            if (count($tableSample) < 15) {
                $tableSample[] = [
                    $product->id,
                    $product->sku ?: '-',
                    Str::limit($product->name, 45),
                    $matchedBrand['name'],
                    "ID #{$matchedBrand['id']}",
                    $dryRun ? 'Sẽ gán' : 'Đã gán',
                ];
            }

            $updated++;

            if ($limit > 0 && $updated >= $limit) {
                $this->warn("Đã đạt giới hạn --limit={$limit} sản phẩm kiểm thử.");
                break;
            }
        }

        if (! empty($tableSample)) {
            $this->table(['SP ID', 'SKU', 'Tên sản phẩm', 'Hãng phát hiện', 'Brand ID', 'Trạng thái'], $tableSample);
        }

        if (! $dryRun && $updated > 0) {
            $this->clearCaches();
        }

        $this->newLine();
        $this->info("=== HOÀN TẤT NHẬN DIỆN HÃNG TỪ TÊN SẢN PHẨM ===");
        $this->line(" - Đã gán hãng: {$updated} sản phẩm");
        $this->line(" - Không nhận diện được hãng: {$unmatched} sản phẩm");

        return self::SUCCESS;
    }

    /**
     * Match tên hãng từ chuỗi tên sản phẩm
     *
     * @return array{id: int, name: string}|null
     */
    protected function matchBrandFromName(string $name): ?array
    {
        if (trim($name) === '') {
            return null;
        }

        foreach ($this->sortedBrands as $b) {
            if (preg_match($b['pattern'], $name)) {
                return [
                    'id' => $b['id'],
                    'name' => $b['name'],
                ];
            }
        }

        return null;
    }

    /**
     * Resolve brand ID từ chuỗi thương hiệu trong CSV
     */
    protected function resolveBrandId(string $rawBrand): ?int
    {
        $clean = trim($rawBrand);
        if ($clean === '') {
            return null;
        }

        $candidates = array_unique(array_filter([
            $clean,
            trim(explode(',', $clean)[0] ?? ''),
            trim(explode(';', $clean)[0] ?? ''),
            trim(explode('|', $clean)[0] ?? ''),
        ]));

        foreach ($candidates as $cand) {
            $lower = mb_strtolower($cand, 'UTF-8');
            if (isset($this->brandByLowerName[$lower])) {
                return $this->brandByLowerName[$lower];
            }
            $slug = Str::slug($cand);
            if (isset($this->brandBySlug[$slug])) {
                return $this->brandBySlug[$slug];
            }
        }

        return null;
    }

    protected function clearCaches(): void
    {
        foreach ([
            'home_category_sections_map',
            'home_category_sections_map_v2',
            'catalog_category_product_counts_v1',
            'shop_sidebar_categories',
            'shop_filter_brands',
            'shop_filter_brands_nonempty_v1',
            'all_brands_page',
            'document_filter_brands',
            'global_shared_view_data_v2',
        ] as $key) {
            Cache::forget($key);
        }
    }
}
