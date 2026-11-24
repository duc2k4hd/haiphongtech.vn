<?php

/**
 * ============================================================================
 * HƯỚNG DẪN CHẠY LỆNH IMPORT SẢN PHẨM TỪ FILE CSV (products:import-csv)
 * ============================================================================
 *
 * 1. Chạy import TOÀN BỘ file CSV (bỏ qua sản phẩm đã có để chạy tiếp nếu ngắt):
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --skip-existing
 *
 * 2. Chạy import TOÀN BỘ và GHI ĐÈ / CẬP NHẬT lại sản phẩm đã có:
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv"
 *
 * 3. Chạy thử 1 sản phẩm đầu tiên để kiểm tra nhanh:
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --limit=1
 *
 * 4. Chạy theo từng đợt (ví dụ bỏ qua 1000 dòng đầu, import tiếp 2000 sản phẩm):
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --offset=1000 --limit=2000 --skip-existing
 *
 * 5. Chỉ import / cập nhật riêng 1 mã SKU cụ thể:
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --sku="S8FS-C35024"
 *
 * 6. Chạy thử chế độ Dry-Run (không ghi DB, không tải ảnh/tài liệu):
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --limit=10 --dry-run
 *
 * 7. Tăng timeout tải ảnh/catalog nếu mạng chậm (mặc định 20 giây):
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --skip-existing --timeout=45
 *
 * 8. Chạy CHẾ ĐỘ CRON JOB tự động ngày đêm (an toàn tuyệt đối cho web nguồn):
 *    - Tự động bật --only-new (TUYỆT ĐỐI không sửa/không đè SKU đã có, chỉ tạo sản phẩm mới)
 *    - Tự lưu vị trí dòng đã chạy (byte-offset) để lần sau chạy tiếp trong 0.001s
 *    - Tự khóa chống chạy chồng (file lock) & tự nghỉ nhẹ giữa mỗi request tránh làm sập web nguồn
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --cron --only-new --limit=15 --sleep-ms=1200 --delay-product-ms=800
 *
 * 9. Chạy Laravel Scheduler liên tục ngày đêm trên máy local (Windows):
 *    php artisan schedule:work
 *
 * 10. Đặt lại vị trí dòng cron về đầu file (vẫn giữ --only-new, không đè SKU cũ):
 *    php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --cron --reset-cursor --limit=15
 *
 * 11. Cron Linux / cPanel (chạy mỗi phút, Laravel tự điều phối mỗi 5 phút):
 *    * * * * * cd /duong-dan-du-an && php artisan schedule:run >> /dev/null 2>&1
 *
 * Ghi chú:
 * - Đường dẫn file mặc định là "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" (có thể bỏ qua tham số file).
 * - Ảnh sản phẩm & ảnh trong mô tả được lưu tại: storage/app/public/clients/imgs/products/ (đặt tên theo SKU).
 * - Tài liệu catalog được lưu tại: storage/app/public/clients/imgs/catalogs/ (tự bỏ qua Google Drive & chống trùng file).
 * - Log chạy cron lưu tại: storage/logs/products-import-cron.log
 * ============================================================================
 * 
 * - Đồng bộ lại danh mục nhanh từ file CSV
 *   php artisan products:import-csv "E:\Project\Laravel\haiphongtech.vn\Full sản phẩm HPT.csv" --sync-categories-only
 */

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Services\Catalog\CategoryMatcherService;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductsImportCsvCommand extends Command
{
    protected $signature = 'products:import-csv
        {file=public/Full sản phẩm HPT.csv : Đường dẫn file CSV sản phẩm}
        {--limit=0 : Giới hạn số sản phẩm mới xử lý mỗi lượt (0 = toàn bộ; chế độ --cron mặc định 500)}
        {--offset=0 : Bỏ qua N dòng dữ liệu đầu tiên}
        {--sku= : Chỉ import đúng 1 mã SKU cụ thể}
        {--skip-existing : Bỏ qua sản phẩm đã tồn tại trong DB theo slug/SKU}
        {--only-new : Chỉ tạo sản phẩm mới chưa có (tuyệt đối không sửa, không ghi đè SKU đã có)}
        {--cron : Chế độ chạy Cron Job tự động ngày đêm (tự bật --only-new, lưu checkpoint, chống chạy chồng)}
        {--concurrency=12 : Số kết nối tải ảnh/tài liệu song song đồng thời (mặc định 12)}
        {--sleep-ms=0 : Nghỉ N ms giữa mỗi lần tải ảnh/tài liệu (mặc định 0ms để đạt tốc độ cao nhất)}
        {--delay-product-ms=0 : Nghỉ N ms giữa mỗi sản phẩm (mặc định 0ms)}
        {--cursor-file= : Đường dẫn file lưu checkpoint dòng đang chạy cho --cron}
        {--reset-cursor : Đặt lại checkpoint cron về đầu file CSV}
        {--sync-categories-only : Chỉ đồng bộ lại danh mục (category_id & category_product) cho các sản phẩm đã có trong DB, không tải ảnh/catalog}
        {--sync-brands-only : Chỉ đồng bộ lại thương hiệu (brand_id) cho các sản phẩm đã có trong DB từ file CSV, không tải ảnh/catalog}
        {--dry-run : Chạy thử không ghi DB và không tải file}
        {--timeout=12 : Timeout tải ảnh/tài liệu tính bằng giây}';

    protected $description = 'Import sản phẩm từ file CSV HPT: tải ảnh đặt tên theo SKU, làm sạch & gộp mô tả/kích thước/sơ đồ đấu nối (chuẩn figure/figcaption), tải & chống trùng catalog (bỏ qua Google Drive).';

    /** @var array<string, int> */
    protected array $brandByLowerName = [];

    /** @var array<string, int> */
    protected array $brandBySlug = [];

    /** @var array<string, int> key: "{parentId}|{lowerName}" */
    protected array $categoryByParentAndName = [];

    /** @var array<string, int> key: lowerName */
    protected array $categoryByLowerName = [];

    /** @var array<string, int> key: slug */
    protected array $categoryBySlug = [];

    /** @var array<string, int> key: normalized catalog URL => media_id */
    protected array $catalogUrlCache = [];

    /** @var array<string, int> key: sha256 => media_id */
    protected array $catalogHashCache = [];

    /** @var array<string, string> key: source image URL => local public URL (/storage/...) */
    protected array $inlineImageCache = [];

    /** @var array<string, bool> Tập SKU đã tồn tại để tránh trùng khi tự sinh HPT + 6 số */
    protected array $usedSkus = [];

    /** @var array<string, bool> Tập slug sản phẩm đã có trong DB (O(1) lookup cho --only-new) */
    protected array $existingSlugs = [];

    /** @var array<int, bool> Tập source_wp_id đã có trong DB (O(1) lookup cho --only-new) */
    protected array $existingWpIds = [];

    protected string $productsImgDir;

    protected string $catalogsDir;

    protected int $timeout = 12;

    protected int $concurrency = 12;

    protected int $sleepMs = 0;

    protected int $delayProductMs = 0;

    protected bool $dryRun = false;

    protected string $batchId = '';

    protected ?CategoryMatcherService $categoryMatcher = null;

    /** @var array<string, array{body: string, content_type: string}|null> Bộ đệm tải song song cho từng lô */
    protected array $prefetchedResponses = [];

    public function handle(): int
    {
        $filePath = (string) $this->argument('file');
        $syncCategoriesOnly = (bool) $this->option('sync-categories-only');
        $syncBrandsOnly = (bool) $this->option('sync-brands-only');
        $isCron = (bool) $this->option('cron');
        $onlyNew = (bool) $this->option('only-new') || (bool) $this->option('skip-existing') || $isCron;
        $limit = max(0, (int) $this->option('limit'));
        $offset = max(0, (int) $this->option('offset'));
        $filterSku = trim((string) ($this->option('sku') ?? ''));
        $resetCursor = (bool) $this->option('reset-cursor');
        $this->dryRun = (bool) $this->option('dry-run');
        $this->timeout = max(5, (int) $this->option('timeout'));
        $this->concurrency = max(1, min(32, (int) $this->option('concurrency')));
        $this->sleepMs = max(0, (int) $this->option('sleep-ms'));
        $this->delayProductMs = max(0, (int) $this->option('delay-product-ms'));
        $this->batchId = ($isCron ? 'cron_csv_' : 'csv_') . Carbon::now('Asia/Ho_Chi_Minh')->format('Ymd_His');

        // Trong chế độ --cron: mặc định xử lý lô lớn 500 SP/lượt để đạt > 130.000 SP/ngày
        if ($isCron && $limit === 0) {
            $limit = 500;
        }

        if (! is_file($filePath)) {
            $this->error("Không tìm thấy file CSV: {$filePath}");

            return self::FAILURE;
        }

        if ($syncCategoriesOnly) {
            return $this->runSyncCategoriesOnly($filePath);
        }

        if ($syncBrandsOnly) {
            return $this->runSyncBrandsOnly($filePath, $limit);
        }

        // Khóa chống chạy chồng khi chạy Cron Job
        $lockHandle = null;
        if ($isCron && ! $this->dryRun) {
            $lockPath = storage_path('app/products_import_csv.lock');
            $lockHandle = @fopen($lockPath, 'c+');
            if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
                $this->line('[' . Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString() . '] Một tiến trình import cron khác đang chạy, bỏ qua lượt này.');
                if (is_resource($lockHandle)) {
                    fclose($lockHandle);
                }

                return self::SUCCESS;
            }
        }

        try {
            return $this->runImportLoop(
                $filePath,
                $isCron,
                $onlyNew,
                $limit,
                $offset,
                $filterSku,
                $resetCursor
            );
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    protected function runImportLoop(
        string $filePath,
        bool $isCron,
        bool $onlyNew,
        int $limit,
        int $offset,
        string $filterSku,
        bool $resetCursor
    ): int {
        $cursorPath = trim((string) ($this->option('cursor-file') ?? ''));
        if ($cursorPath === '') {
            $cursorPath = storage_path('app/products_import_csv_cursor.json');
        }

        $fileSize = (int) filesize($filePath);
        $fileMtime = (int) filemtime($filePath);
        $cursorByteOffset = 0;
        $cursorRowIndex = 0;

        if ($isCron && ! $resetCursor && is_file($cursorPath)) {
            $cursorData = json_decode((string) file_get_contents($cursorPath), true);
            if (is_array($cursorData)
                && ($cursorData['file'] ?? '') === $filePath
                && (int) ($cursorData['file_size'] ?? 0) === $fileSize
                && (int) ($cursorData['file_mtime'] ?? 0) === $fileMtime
            ) {
                if (! empty($cursorData['completed'])) {
                    $this->info('[' . Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString() . '] Cron đã quét hết toàn bộ file CSV. Dùng --reset-cursor nếu muốn quét lại.');

                    return self::SUCCESS;
                }
                $cursorByteOffset = max(0, (int) ($cursorData['byte_offset'] ?? 0));
                $cursorRowIndex = max(0, (int) ($cursorData['row_index'] ?? 0));
            }
        }

        $this->productsImgDir = storage_path('app/public/clients/imgs/products');
        $this->catalogsDir = storage_path('app/public/clients/imgs/catalogs');

        if (! $this->dryRun) {
            if (! is_dir($this->productsImgDir)) {
                mkdir($this->productsImgDir, 0755, true);
            }
            if (! is_dir($this->catalogsDir)) {
                mkdir($this->catalogsDir, 0755, true);
            }
        }

        $this->preloadLookups();

        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            $this->error("Không thể mở file: {$filePath}");

            return self::FAILURE;
        }

        // Bỏ qua UTF-8 BOM nếu có
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $headers = fgetcsv($fh);
        if (! is_array($headers) || count($headers) < 5) {
            fclose($fh);
            $this->error('Header file CSV không hợp lệ.');

            return self::FAILURE;
        }

        $colMap = $this->buildColumnMap($headers);

        // Nhảy thẳng tới byte_offset đã lưu ở lượt cron trước (tức thì O(1))
        $rowIndex = 0;
        if ($isCron && $cursorByteOffset > 0 && $cursorByteOffset < $fileSize) {
            fseek($fh, $cursorByteOffset);
            $rowIndex = $cursorRowIndex;
        }

        $startedAt = microtime(true);
        $this->info("Bắt đầu import từ: {$filePath}");
        $this->line(sprintf(
            'Batch: %s | Mode: %s | OnlyNew: %s | Limit: %s | Concurrency: %d | StartRow: %d | Sleep: %dms/%dms | Brands: %d',
            $this->batchId,
            $isCron ? 'CRON' : 'MANUAL',
            $onlyNew ? 'YES' : 'NO',
            $limit > 0 ? (string) $limit : 'ALL',
            $this->concurrency,
            $isCron ? $rowIndex : $offset,
            $this->sleepMs,
            $this->delayProductMs,
            count($this->brandBySlug)
        ));

        $processed = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $imagesDownloaded = 0;
        $catalogsDownloaded = 0;
        $catalogsReused = 0;
        $catalogsDriveSkipped = 0;
        $reachedEof = false;

        // Gom theo từng lô nhỏ (mặc định 20 SP) để tải song song toàn bộ ảnh & catalog bằng Http::pool()
        $chunkSize = $this->concurrency > 1 && $this->sleepMs === 0 ? max(10, min(25, $this->concurrency * 2)) : 1;

        $saveCheckpoint = function (int $curRow, int $curByteOffset, bool $isDone) use ($isCron, $cursorPath, $filePath, $fileSize, $fileMtime, &$created, &$skipped): void {
            if (! $isCron || $this->dryRun) {
                return;
            }
            file_put_contents($cursorPath, json_encode([
                'file' => $filePath,
                'file_size' => $fileSize,
                'file_mtime' => $fileMtime,
                'row_index' => $curRow,
                'byte_offset' => $curByteOffset,
                'completed' => $isDone,
                'updated_at' => Carbon::now('Asia/Ho_Chi_Minh')->toDateTimeString(),
                'last_batch_created' => $created,
                'last_batch_skipped' => $skipped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        };

        $processChunk = function (array $batchRows) use (
            $colMap,
            $onlyNew,
            $limit,
            &$processed,
            &$created,
            &$updated,
            &$skipped,
            &$failed,
            &$imagesDownloaded,
            &$catalogsDownloaded,
            &$catalogsReused,
            &$catalogsDriveSkipped
        ): void {
            if (empty($batchRows)) {
                return;
            }

            // 1. Tải song song toàn bộ ảnh & catalog của cả lô bằng Http::pool() (giảm 90% thời gian chờ mạng)
            if (! $this->dryRun && $this->concurrency > 1 && $this->sleepMs === 0) {
                $this->prefetchBatchMedia($batchRows, $colMap);
            }

            // 2. Ghi DB không kích hoạt Eloquent Cache::forget events (tránh hàng triệu câu DELETE cache)
            Product::withoutEvents(function () use (
                $batchRows,
                $colMap,
                $onlyNew,
                $limit,
                &$processed,
                &$created,
                &$updated,
                &$skipped,
                &$failed,
                &$imagesDownloaded,
                &$catalogsDownloaded,
                &$catalogsReused,
                &$catalogsDriveSkipped
            ) {
                Media::withoutEvents(function () use (
                    $batchRows,
                    $colMap,
                    $onlyNew,
                    $limit,
                    &$processed,
                    &$created,
                    &$updated,
                    &$skipped,
                    &$failed,
                    &$imagesDownloaded,
                    &$catalogsDownloaded,
                    &$catalogsReused,
                    &$catalogsDriveSkipped
                ) {
                    foreach ($batchRows as $item) {
                        $row = $item['row'];
                        $itemRowIndex = $item['row_index'];
                        $sku = $this->getCol($row, $colMap, 'sku');

                        try {
                            $result = $this->importSingleRow($row, $colMap, $onlyNew);

                            if ($result['action'] === 'created') {
                                $created++;
                                $processed++;
                            } elseif ($result['action'] === 'updated') {
                                $updated++;
                                $processed++;
                            } else {
                                $skipped++;
                            }

                            $imagesDownloaded += $result['images_downloaded'];
                            $catalogsDownloaded += $result['catalog_downloaded'];
                            $catalogsReused += $result['catalog_reused'];
                            $catalogsDriveSkipped += $result['catalog_drive_skipped'];

                            if ($limit > 0 && $limit <= 30 && $result['action'] !== 'skipped') {
                                $this->line(sprintf(
                                    '  [%d | Row %d] %s | SKU=%s | Brand=%s | Cats=%d | Imgs=%d | Catalog=%s',
                                    $processed,
                                    $itemRowIndex,
                                    strtoupper($result['action']),
                                    $result['sku'] ?? ($sku ?: '(trống)'),
                                    $result['brand_id'] ? '#' . $result['brand_id'] : 'none',
                                    $result['categories_count'],
                                    $result['images_count'],
                                    $result['catalog_status']
                                ));
                            } elseif ($processed > 0 && $processed % 100 === 0) {
                                $this->line(sprintf(
                                    '  Đã xử lý %d SP (Row %d | Created: %d, Updated: %d, Skipped: %d, Failed: %d | Imgs: %d | Catalogs new/reused/drive-skip: %d/%d/%d)',
                                    $processed,
                                    $itemRowIndex,
                                    $created,
                                    $updated,
                                    $skipped,
                                    $failed,
                                    $imagesDownloaded,
                                    $catalogsDownloaded,
                                    $catalogsReused,
                                    $catalogsDriveSkipped
                                ));
                            }

                            if ($this->delayProductMs > 0 && ! $this->dryRun && $result['action'] !== 'skipped') {
                                usleep($this->delayProductMs * 1000);
                            }
                        } catch (\Throwable $e) {
                            $failed++;
                            $this->warn("  [Row {$itemRowIndex} | SKU={$sku}] Lỗi: {$e->getMessage()}");
                        }
                    }
                });
            });

            // Giải phóng RAM bộ đệm ảnh của lô vừa xong
            $this->prefetchedResponses = [];
        };

        $batchRows = [];
        $queuedCount = 0;

        while (true) {
            $row = fgetcsv($fh);
            if ($row === false) {
                $reachedEof = true;
                break;
            }

            $rowIndex++;
            if (! $isCron && $rowIndex <= $offset) {
                continue;
            }

            $sku = $this->getCol($row, $colMap, 'sku');
            $name = $this->getCol($row, $colMap, 'name');
            $slug = $this->getCol($row, $colMap, 'slug');
            $sourceWpId = (int) $this->getCol($row, $colMap, 'id') ?: null;

            if ($name === '' && $sku === '') {
                $skipped++;
                continue;
            }

            if ($filterSku !== '' && strcasecmp($sku, $filterSku) !== 0) {
                continue;
            }

            // Kiểm tra siêu tốc O(1) trong RAM cho chế độ --only-new / --cron:
            // Tuyệt đối KHÔNG tải ảnh/catalog, KHÔNG sửa hay ghi đè SKU đã tồn tại!
            if ($onlyNew && $this->isAlreadyExistingFast($sku, $slug, $sourceWpId)) {
                $skipped++;
                continue;
            }

            $batchRows[] = ['row' => $row, 'row_index' => $rowIndex];
            $queuedCount++;

            $hitLimit = $limit > 0 && ($processed + count($batchRows)) >= $limit;
            if (count($batchRows) >= $chunkSize || $hitLimit) {
                $processChunk($batchRows);
                $batchRows = [];

                // Lưu checkpoint sau mỗi lô để an toàn nếu tiến trình bị dừng đột ngột
                $saveCheckpoint($rowIndex, (int) ftell($fh), false);

                if ($limit > 0 && $processed >= $limit) {
                    break;
                }
            }
        }

        if (! empty($batchRows)) {
            $processChunk($batchRows);
            $batchRows = [];
        }

        $finalByteOffset = (int) ftell($fh);
        fclose($fh);

        $saveCheckpoint($rowIndex, $finalByteOffset, $reachedEof);

        if ($processed > 0 && ! $this->dryRun) {
            $this->clearCatalogCaches();
        }

        $elapsed = max(0.01, microtime(true) - $startedAt);
        $speed = $processed / $elapsed;

        $this->newLine();
        $this->info('=== KẾT QUẢ IMPORT SẢN PHẨM CSV ===');
        $this->table(
            ['Chỉ số', 'Giá trị'],
            [
                ['Dòng CSV hiện tại', $rowIndex . ($reachedEof ? ' (HOÀN TẤT FILE)' : '')],
                ['Sản phẩm ghi mới/cập nhật', $processed],
                ['Tạo mới (Created)', $created],
                ['Cập nhật (Updated)', $updated],
                ['Bỏ qua vì đã có / trống (Skipped)', $skipped],
                ['Lỗi (Failed)', $failed],
                ['Ảnh tải mới', $imagesDownloaded],
                ['Catalog tải mới', $catalogsDownloaded],
                ['Catalog dùng chung (tái sử dụng)', $catalogsReused],
                ['Catalog Google Drive bỏ qua', $catalogsDriveSkipped],
                ['Thời gian / Tốc độ', sprintf('%.2fs (%.2f SP/giây ≈ %s SP/ngày)', $elapsed, $speed, number_format((int) round($speed * 86400)))],
            ]
        );

        return $failed > 0 && $processed === 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function isAlreadyExistingFast(string $sku, string $slug, ?int $sourceWpId): bool
    {
        if ($sku !== '' && isset($this->usedSkus[strtoupper($sku)])) {
            return true;
        }
        if ($slug !== '' && isset($this->existingSlugs[strtolower($slug)])) {
            return true;
        }
        if ($sourceWpId !== null && isset($this->existingWpIds[$sourceWpId])) {
            return true;
        }

        return false;
    }

    protected function preloadLookups(): void
    {
        // Dùng Query Builder thuần (không hydrate hàng trăm nghìn Eloquent Model) để preload < 0.3s
        foreach (DB::table('brands')->select(['id', 'name', 'slug'])->cursor() as $brand) {
            $lower = mb_strtolower(trim((string) $brand->name), 'UTF-8');
            if ($lower !== '') {
                $this->brandByLowerName[$lower] = (int) $brand->id;
            }
            if (! empty($brand->slug)) {
                $this->brandBySlug[strtolower((string) $brand->slug)] = (int) $brand->id;
            }
        }

        foreach (DB::table('media')->where('folder', 'clients/imgs/catalogs')->select(['id', 'wp_source_url', 'sha256'])->cursor() as $media) {
            if (! empty($media->wp_source_url)) {
                $this->catalogUrlCache[$this->normalizeUrl((string) $media->wp_source_url)] = (int) $media->id;
            }
            if (! empty($media->sha256)) {
                $this->catalogHashCache[(string) $media->sha256] = (int) $media->id;
            }
        }

        foreach (DB::table('products')->select(['sku', 'slug', 'source_wp_id'])->cursor() as $existingProd) {
            if (! empty($existingProd->sku)) {
                $this->usedSkus[strtoupper((string) $existingProd->sku)] = true;
            }
            if (! empty($existingProd->slug)) {
                $this->existingSlugs[strtolower((string) $existingProd->slug)] = true;
            }
            if (! empty($existingProd->source_wp_id)) {
                $this->existingWpIds[(int) $existingProd->source_wp_id] = true;
            }
        }

        $this->categoryMatcher = new CategoryMatcherService();
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    protected function buildColumnMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $idx => $rawHeader) {
            $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $rawHeader)), 'UTF-8');
            match (true) {
                $norm === 'id' => $map['id'] = $idx,
                $norm === 'sku' => $map['sku'] = $idx,
                $norm === 'tên' || $norm === 'ten' => $map['name'] = $idx,
                str_contains($norm, 'meta description') => $map['meta_description'] = $idx,
                $norm === 'mô tả' || $norm === 'mo ta' => $map['description'] = $idx,
                str_contains($norm, 'giá thông thường') || $norm === 'giá' => $map['price'] = $idx,
                $norm === 'danh mục' || $norm === 'danh muc' => $map['categories'] = $idx,
                $norm === 'hình ảnh' || $norm === 'hinh anh' => $map['images'] = $idx,
                $norm === 'thương hiệu' || $norm === 'thuong hieu' => $map['brand'] = $idx,
                str_contains($norm, 'tài liệu') || str_contains($norm, 'catalog') => $map['catalog'] = $idx,
                str_contains($norm, 'kích thước') => $map['dimensions'] = $idx,
                str_contains($norm, 'sơ đồ') => $map['wiring'] = $idx,
                str_contains($norm, 'slug') => $map['slug'] = $idx,
                str_contains($norm, 'đường dẫn') => $map['permalink'] = $idx,
                default => null,
            };
        }

        return $map;
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $colMap
     */
    protected function getCol(array $row, array $colMap, string $key): string
    {
        if (! isset($colMap[$key])) {
            return '';
        }

        return trim((string) ($row[$colMap[$key]] ?? ''));
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $colMap
     * @return array<string, mixed>
     */
    protected function importSingleRow(array $row, array $colMap, bool $skipExisting): array
    {
        $sourceWpId = (int) $this->getCol($row, $colMap, 'id') ?: null;
        $sku = $this->getCol($row, $colMap, 'sku');
        $name = mb_substr($this->getCol($row, $colMap, 'name'), 0, 500, 'UTF-8');
        $metaDescription = $this->getCol($row, $colMap, 'meta_description');
        $rawDesc = $this->getCol($row, $colMap, 'description');
        $rawDimensions = $this->getCol($row, $colMap, 'dimensions');
        $rawWiring = $this->getCol($row, $colMap, 'wiring');
        $rawPrice = $this->getCol($row, $colMap, 'price');
        $rawCategories = $this->getCol($row, $colMap, 'categories');
        $rawImages = $this->getCol($row, $colMap, 'images');
        $rawBrand = $this->getCol($row, $colMap, 'brand');
        $rawCatalog = $this->getCol($row, $colMap, 'catalog');
        $slug = $this->getCol($row, $colMap, 'slug');
        $permalink = $this->getCol($row, $colMap, 'permalink');

        if ($slug === '') {
            $slug = Str::slug($sku !== '' ? $sku : $name);
        }

        // Trong chế độ --only-new: kiểm tra lại O(1) trong RAM (tránh query DB thừa và chống trùng ngay trong cùng 1 lô CSV)
        if ($skipExisting) {
            if ($this->isAlreadyExistingFast($sku, $slug, $sourceWpId)) {
                return [
                    'action' => 'skipped',
                    'brand_id' => null,
                    'categories_count' => 0,
                    'images_count' => 0,
                    'images_downloaded' => 0,
                    'catalog_downloaded' => 0,
                    'catalog_reused' => 0,
                    'catalog_drive_skipped' => 0,
                    'catalog_status' => 'skipped',
                ];
            }
            $existing = null;
        } else {
            $existing = Product::withTrashed()
                ->where(function ($q) use ($slug, $sku, $sourceWpId) {
                    $q->where('slug', $slug);
                    if ($sku !== '') {
                        $q->orWhere('sku', $sku);
                    }
                    if ($sourceWpId !== null) {
                        $q->orWhere('source_wp_id', $sourceWpId);
                    }
                })
                ->first();
        }

        // Nếu CSV không có mã sản phẩm (SKU): giữ nguyên SKU cũ nếu đã có, hoặc tự sinh HPT + 6 số ngẫu nhiên không trùng
        if ($sku === '') {
            if ($existing && ! empty($existing->sku)) {
                $sku = (string) $existing->sku;
            } else {
                $sku = $this->generateUniqueHptSku();
            }
        }
        $this->usedSkus[strtoupper($sku)] = true;
        $this->existingSlugs[strtolower($slug)] = true;
        if ($sourceWpId !== null) {
            $this->existingWpIds[$sourceWpId] = true;
        }

        if ($slug === '') {
            $slug = Str::slug($name !== '' ? $name : $sku);
        }

        $price = $this->parsePrice($rawPrice);
        $brandId = $this->resolveBrandId($rawBrand);
        [$primaryCategoryId, $categoryIds] = $this->resolveCategories($rawCategories, $name);
        $canonicalUrl = $this->extractCanonicalPath($permalink, $slug);

        // Làm sạch & gộp 3 phần: Mô tả + Kích thước sản phẩm + Sơ đồ đấu nối điện
        $skuBase = $this->sanitizeSkuForFilename($sku !== '' ? $sku : $slug);
        $combinedContent = $this->buildCombinedCleanContent(
            $rawDesc,
            $rawDimensions,
            $rawWiring,
            $name,
            $skuBase
        );

        // Xử lý ảnh sản phẩm (đặt tên theo SKU)
        $imageUrls = $this->parseCommaSeparatedUrls($rawImages);
        $mediaAttachments = [];
        $imagesDownloaded = 0;
        $ogImage = null;

        foreach ($imageUrls as $idx => $imgUrl) {
            $role = $idx === 0 ? 'thumbnail' : 'gallery';
            $suffix = $idx === 0 ? '' : '-' . ($idx + 1);
            $targetBase = $skuBase . $suffix;

            if ($this->dryRun) {
                $mediaAttachments[] = ['id' => 0, 'role' => $role, 'position' => $idx];
                continue;
            }

            $dl = $this->downloadProductImage($imgUrl, $targetBase, $name);
            if ($dl !== null) {
                $mediaAttachments[] = ['id' => $dl['media']->id, 'role' => $role, 'position' => $idx];
                if ($dl['downloaded']) {
                    $imagesDownloaded++;
                }
                if ($role === 'thumbnail') {
                    $ogImage = '/storage/' . $dl['media']->relative_path;
                }
            }
        }

        // Xử lý tài liệu Catalog (bỏ qua link Google Drive, dùng chung 1 file nếu trùng)
        $catalogDownloaded = 0;
        $catalogReused = 0;
        $catalogDriveSkipped = 0;
        $catalogStatus = 'none';
        $catalogMediaId = null;

        if ($rawCatalog !== '') {
            if ($this->isGoogleDriveUrl($rawCatalog)) {
                $catalogDriveSkipped = 1;
                $catalogStatus = 'drive-skipped';
            } elseif ($this->dryRun) {
                $catalogStatus = 'dry-run';
            } else {
                $catResult = $this->downloadOrReuseCatalog($rawCatalog, $name);
                if ($catResult !== null) {
                    $catalogMediaId = $catResult['media']->id;
                    if ($catResult['reused']) {
                        $catalogReused = 1;
                        $catalogStatus = 'reused:' . $catResult['media']->filename;
                    } else {
                        $catalogDownloaded = 1;
                        $catalogStatus = 'new:' . $catResult['media']->filename;
                    }
                } else {
                    $catalogStatus = 'download-failed';
                }
            }
        }

        if ($this->dryRun) {
            return [
                'action' => $existing ? 'updated' : 'created',
                'brand_id' => $brandId,
                'categories_count' => count($categoryIds),
                'images_count' => count($imageUrls),
                'images_downloaded' => 0,
                'catalog_downloaded' => 0,
                'catalog_reused' => 0,
                'catalog_drive_skipped' => $catalogDriveSkipped,
                'catalog_status' => $catalogStatus,
            ];
        }

        $payload = [
            'source_wp_id' => $sourceWpId,
            'sku' => $sku !== '' ? $sku : null,
            'name' => $name,
            'slug' => $slug,
            'price' => $price,
            'content' => $combinedContent,
            'content_hash' => hash('sha256', $combinedContent),
            'category_id' => $primaryCategoryId,
            'brand_id' => $brandId,
            'meta_title' => mb_substr($name . ' - Hải Phòng Tech', 0, 255, 'UTF-8'),
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'og_title' => mb_substr($name . ' - Hải Phòng Tech', 0, 255, 'UTF-8'),
            'og_description' => $metaDescription !== '' ? $metaDescription : null,
            'og_image' => $ogImage,
            'source_permalink' => $permalink !== '' ? $permalink : null,
            'canonical_url' => $canonicalUrl,
            'meta_robots' => 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large',
            'product_type' => 'simple',
            'stock_status' => 'instock',
            'status' => 'active',
            'in_sitemap' => true,
            'import_batch_id' => $this->batchId,
        ];

        $action = 'created';
        DB::transaction(function () use ($existing, $payload, $categoryIds, $primaryCategoryId, $mediaAttachments, $catalogMediaId, &$action) {
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                if ($existing->published_at === null) {
                    $payload['published_at'] = Carbon::now('Asia/Ho_Chi_Minh');
                }
                $existing->fill($payload);
                $existing->save();
                $product = $existing;
                $action = 'updated';
            } else {
                $payload['published_at'] = Carbon::now('Asia/Ho_Chi_Minh');
                $product = Product::create($payload);
                $action = 'created';
            }

            // Đồng bộ danh mục (category_product) — insert trực tiếp khi tạo mới để bỏ 2 query thừa của sync()
            $now = Carbon::now('Asia/Ho_Chi_Minh');
            if (! empty($categoryIds)) {
                if ($existing) {
                    DB::table('category_product')->where('product_id', $product->id)->delete();
                }
                $catPivots = [];
                foreach ($categoryIds as $cid) {
                    $catPivots[] = [
                        'category_id' => $cid,
                        'product_id' => $product->id,
                        'is_primary' => $cid === $primaryCategoryId ? 1 : 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('category_product')->insert($catPivots);
            }

            // Đồng bộ ảnh (thumbnail & gallery) và catalog trong product_media
            if ($existing) {
                DB::table('product_media')->where('product_id', $product->id)->delete();
            }
            $pivots = [];

            foreach ($mediaAttachments as $att) {
                $pivots[] = [
                    'product_id' => $product->id,
                    'media_id' => $att['id'],
                    'role' => $att['role'],
                    'position' => $att['position'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($catalogMediaId !== null) {
                $pivots[] = [
                    'product_id' => $product->id,
                    'media_id' => $catalogMediaId,
                    'role' => 'catalog',
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($pivots)) {
                DB::table('product_media')->insert($pivots);
            }
        });

        return [
            'action' => $action,
            'sku' => $sku,
            'brand_id' => $brandId,
            'categories_count' => count($categoryIds),
            'images_count' => count($mediaAttachments),
            'images_downloaded' => $imagesDownloaded,
            'catalog_downloaded' => $catalogDownloaded,
            'catalog_reused' => $catalogReused,
            'catalog_drive_skipped' => $catalogDriveSkipped,
            'catalog_status' => $catalogStatus,
        ];
    }

    /**
     * Gộp 3 phần (Mô tả + Kích thước sản phẩm + Sơ đồ đấu nối điện) sau khi đã làm sạch hoàn toàn.
     */
    protected function buildCombinedCleanContent(
        string $rawDesc,
        string $rawDimensions,
        string $rawWiring,
        string $productName,
        string $skuBase
    ): string {
        $parts = [];

        $cleanDesc = $this->cleanHtmlFragment($rawDesc, $productName, $skuBase, 'mo-ta');
        if ($cleanDesc !== '') {
            $parts[] = $cleanDesc;
        }

        $cleanDimensions = $this->cleanHtmlFragment($rawDimensions, $productName . ' - Kích thước sản phẩm', $skuBase, 'kich-thuoc');
        if ($cleanDimensions !== '') {
            if (! preg_match('/<h[2-4]\b[^>]*>[^<]*kích thước/ui', $cleanDimensions)) {
                $cleanDimensions = "<h2>Kích thước sản phẩm</h2>\n" . $cleanDimensions;
            }
            $parts[] = $cleanDimensions;
        }

        $cleanWiring = $this->cleanHtmlFragment($rawWiring, $productName . ' - Sơ đồ đấu nối điện', $skuBase, 'so-do-dau-noi');
        if ($cleanWiring !== '') {
            if (! preg_match('/<h[2-4]\b[^>]*>[^<]*sơ đồ/ui', $cleanWiring)) {
                $cleanWiring = "<h2>Sơ đồ đấu nối điện</h2>\n" . $cleanWiring;
            }
            $parts[] = $cleanWiring;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Làm sạch HTML:
     * - Loại bỏ toàn bộ class và thuộc tính rác trên mọi thẻ, CHỈ giữ lại:
     *   + <a>: giữ href
     *   + <img>: giữ src và alt (đồng thời tải ảnh nội dung về local đặt tên theo SKU)
     * - Đảm bảo mọi thẻ <img> đều nằm trong <figure> và có đủ <figcaption>.
     */
    protected function cleanHtmlFragment(string $html, string $defaultAlt, string $skuBase, string $sectionSlug): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Gỡ comment HTML, script, style, noscript trước khi parse
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/<(script|style|noscript|iframe)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;

        $dom = new DOMDocument('1.0', 'UTF-8');
        $prevUseErrors = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="hpt-clean-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        $root = $dom->getElementById('hpt-clean-root');
        if (! $root) {
            return '';
        }

        $inlineImgIndex = 0;

        // 1. Duyệt toàn bộ thẻ để làm sạch thuộc tính & xử lý <a>, <img>
        $elements = [];
        foreach ($root->getElementsByTagName('*') as $node) {
            $elements[] = $node;
        }

        foreach ($elements as $el) {
            if (! $el instanceof DOMElement || ! $el->parentNode) {
                continue;
            }

            $tag = strtolower($el->tagName);

            if ($tag === 'a') {
                $href = trim($el->getAttribute('href'));
                $this->stripAllAttributes($el);
                if ($href !== '' && ! preg_match('/^\s*javascript:/i', $href)) {
                    $el->setAttribute('href', $href);
                } else {
                    $this->unwrapNode($el);
                }

                continue;
            }

            if ($tag === 'img') {
                $src = trim($el->getAttribute('src'));
                if ($src === '') {
                    $src = trim($el->getAttribute('data-src'));
                }
                if ($src === '') {
                    $el->parentNode->removeChild($el);
                    continue;
                }

                $alt = trim($el->getAttribute('alt'));
                if ($alt === '') {
                    $alt = $defaultAlt;
                }

                // Tải ảnh trong nội dung về local (nếu không phải dry-run)
                if (! $this->dryRun && preg_match('/^https?:\/\//i', $src)) {
                    $inlineImgIndex++;
                    $localUrl = $this->downloadInlineImage($src, "{$skuBase}-{$sectionSlug}-{$inlineImgIndex}");
                    if ($localUrl !== null) {
                        $src = $localUrl;
                    }
                }

                $this->stripAllAttributes($el);
                $el->setAttribute('src', $src);
                $el->setAttribute('alt', $alt);

                continue;
            }

            // Gỡ thẻ <span> hoặc <div> bọc rỗng thuộc tính rác? Giữ cấu trúc ngữ nghĩa, chỉ xóa hết attribute
            $this->stripAllAttributes($el);
        }

        // 2. Đảm bảo mọi <img> đều có <figure> và <figcaption> đầy đủ
        $imgs = [];
        foreach ($root->getElementsByTagName('img') as $img) {
            $imgs[] = $img;
        }

        foreach ($imgs as $img) {
            if (! $img instanceof DOMElement || ! $img->parentNode) {
                continue;
            }

            $alt = trim($img->getAttribute('alt')) ?: $defaultAlt;
            $parent = $img->parentNode;

            // Tìm <figure> gần nhất chứa <img>
            $figure = null;
            $cursor = $parent;
            while ($cursor && $cursor !== $root) {
                if ($cursor instanceof DOMElement && strtolower($cursor->tagName) === 'figure') {
                    $figure = $cursor;
                    break;
                }
                $cursor = $cursor->parentNode;
            }

            if ($figure instanceof DOMElement) {
                // Đã có <figure> → kiểm tra xem đã có <figcaption> có nội dung chưa
                $captions = $figure->getElementsByTagName('figcaption');
                if ($captions->length === 0) {
                    $figcaption = $dom->createElement('figcaption');
                    $figcaption->appendChild($dom->createTextNode($alt));
                    $figure->appendChild($figcaption);
                } else {
                    $firstCap = $captions->item(0);
                    if ($firstCap && trim($firstCap->textContent) === '') {
                        $firstCap->appendChild($dom->createTextNode($alt));
                    }
                }
            } else {
                // Chưa có <figure> → bọc <figure><img ...><figcaption>...</figcaption></figure>
                $newFigure = $dom->createElement('figure');
                $clonedImg = $img->cloneNode(true);
                $newFigure->appendChild($clonedImg);

                $figcaption = $dom->createElement('figcaption');
                $figcaption->appendChild($dom->createTextNode($alt));
                $newFigure->appendChild($figcaption);

                // Nếu <img> nằm đơn lẻ bên trong <p>, thay luôn <p> bằng <figure> để HTML chuẩn W3C
                if (
                    $parent instanceof DOMElement
                    && strtolower($parent->tagName) === 'p'
                    && trim($parent->textContent) === ''
                    && $parent->getElementsByTagName('img')->length === 1
                    && $parent->parentNode
                ) {
                    $parent->parentNode->replaceChild($newFigure, $parent);
                } else {
                    $parent->replaceChild($newFigure, $img);
                }
            }
        }

        // 3. Loại bỏ các thẻ <p> rỗng sau khi dọn
        $paragraphs = [];
        foreach ($root->getElementsByTagName('p') as $p) {
            $paragraphs[] = $p;
        }
        foreach ($paragraphs as $p) {
            if (
                $p->parentNode
                && trim(str_replace("\xC2\xA0", '', $p->textContent)) === ''
                && $p->getElementsByTagName('img')->length === 0
                && $p->getElementsByTagName('figure')->length === 0
            ) {
                $p->parentNode->removeChild($p);
            }
        }

        // Xuất HTML sạch bên trong #hpt-clean-root
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        $output = preg_replace("/\n{3,}/", "\n\n", trim($output)) ?? trim($output);

        return $output;
    }

    protected function stripAllAttributes(DOMElement $el): void
    {
        while ($el->attributes->length > 0) {
            $el->removeAttributeNode($el->attributes->item(0));
        }
    }

    protected function unwrapNode(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    /**
     * Tải song song toàn bộ ảnh (thumbnail, gallery, inline HTML) và catalog của 1 lô sản phẩm
     * bằng Http::pool() để tăng tốc gấp 10-12 lần so với tải tuần tự từng request.
     *
     * @param  array<int, array{row: array<int, string|null>, row_index: int}>  $batchRows
     * @param  array<string, int>  $colMap
     */
    protected function prefetchBatchMedia(array $batchRows, array $colMap): void
    {
        $urlSet = [];

        foreach ($batchRows as $item) {
            $row = $item['row'];

            // 1. Ảnh chính & gallery
            $rawImages = $this->getCol($row, $colMap, 'images');
            foreach ($this->parseCommaSeparatedUrls($rawImages) as $u) {
                $norm = $this->normalizeUrl($u);
                if ($this->isValidHttpUrl($norm) && ! isset($this->prefetchedResponses[$norm])) {
                    $urlSet[$norm] = $norm;
                }
            }

            // 2. Ảnh inline bên trong HTML mô tả / kích thước / sơ đồ đấu nối
            foreach (['description', 'dimensions', 'wiring'] as $htmlCol) {
                $html = $this->getCol($row, $colMap, $htmlCol);
                if ($html !== '' && stripos($html, '<img') !== false) {
                    if (preg_match_all('/(?:src|data-src)\s*=\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $matches)) {
                        foreach ($matches[1] as $inlineUrl) {
                            $norm = $this->normalizeUrl(html_entity_decode($inlineUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                            if ($this->isValidHttpUrl($norm) && ! isset($this->inlineImageCache[$norm]) && ! isset($this->prefetchedResponses[$norm])) {
                                $urlSet[$norm] = $norm;
                            }
                        }
                    }
                }
            }

            // 3. Tài liệu catalog (bỏ qua Google Drive và catalog đã có trong cache)
            $rawCatalog = $this->getCol($row, $colMap, 'catalog');
            if ($rawCatalog !== '' && ! $this->isGoogleDriveUrl($rawCatalog) && preg_match('/^https?:\/\//i', $rawCatalog)) {
                $norm = $this->normalizeUrl($rawCatalog);
                if ($this->isValidHttpUrl($norm) && ! isset($this->catalogUrlCache[$norm]) && ! isset($this->prefetchedResponses[$norm])) {
                    $urlSet[$norm] = $norm;
                }
            }
        }

        if (empty($urlSet)) {
            return;
        }

        $urls = array_values($urlSet);
        $chunks = array_chunk($urls, $this->concurrency);

        foreach ($chunks as $chunkUrls) {
            try {
                $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($chunkUrls) {
                    $reqs = [];
                    foreach ($chunkUrls as $idx => $url) {
                        if (! $this->isValidHttpUrl($url)) {
                            continue;
                        }
                        $reqs[$idx] = $pool->as((string) $idx)
                            ->withoutVerifying()
                            ->connectTimeout(5)
                            ->timeout($this->timeout)
                            ->withHeaders([
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            ])
                            ->get($url);
                    }

                    return $reqs;
                });
            } catch (\Throwable) {
                foreach ($chunkUrls as $url) {
                    $this->prefetchedResponses[$url] = null;
                }
                continue;
            }

            foreach ($chunkUrls as $idx => $url) {
                $resp = $responses[(string) $idx] ?? null;
                if ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) {
                    $body = $resp->body();
                    if ($body !== '') {
                        $this->prefetchedResponses[$url] = [
                            'body' => $body,
                            'content_type' => (string) $resp->header('Content-Type'),
                        ];
                        continue;
                    }
                }
                $this->prefetchedResponses[$url] = null;
            }
        }
    }

    /**
     * Lấy nội dung URL từ bộ đệm song song (0ms) hoặc tải trực tiếp nếu chưa có trong bộ đệm.
     *
     * @return array{body: string, content_type: string}|null
     */
    protected function fetchBinary(string $url): ?array
    {
        $normUrl = $this->normalizeUrl($url);
        if (! $this->isValidHttpUrl($normUrl)) {
            return null;
        }
        if (array_key_exists($normUrl, $this->prefetchedResponses)) {
            return $this->prefetchedResponses[$normUrl];
        }

        try {
            $resp = $this->httpGet($url);
            if (! $resp->successful()) {
                return null;
            }
            $body = $resp->body();
            if ($body === '') {
                return null;
            }

            return [
                'body' => $body,
                'content_type' => (string) $resp->header('Content-Type'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function httpGet(string $url): \Illuminate\Http\Client\Response
    {
        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }

        return Http::withoutVerifying()
            ->connectTimeout(5)
            ->timeout($this->timeout)
            ->retry(1, 200, throw: false)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])
            ->get($url);
    }

    protected function downloadInlineImage(string $url, string $baseFilename): ?string
    {
        $normUrl = $this->normalizeUrl($url);
        if (isset($this->inlineImageCache[$normUrl])) {
            return $this->inlineImageCache[$normUrl];
        }

        try {
            $fetched = $this->fetchBinary($url);
            if ($fetched === null) {
                return null;
            }

            $body = $fetched['body'];
            $ext = $this->resolveExtension($url, $fetched['content_type'], 'webp');
            $converted = $this->ensureWebpImage($body, $ext);
            $body = $converted['body'];
            $ext = $converted['ext'];

            $filename = $baseFilename . '.' . $ext;
            $absPath = $this->productsImgDir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($absPath, $body);

            $publicUrl = '/storage/clients/imgs/products/' . $filename;
            $this->inlineImageCache[$normUrl] = $publicUrl;

            return $publicUrl;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tải ảnh sản phẩm chính/gallery và đặt tên theo SKU.
     *
     * @return array{media: Media, downloaded: bool}|null
     */
    protected function downloadProductImage(string $url, string $targetBaseName, string $altTitle): ?array
    {
        $normUrl = $this->normalizeUrl($url);

        try {
            $fetched = $this->fetchBinary($url);
            if ($fetched === null) {
                return null;
            }

            $body = $fetched['body'];
            $contentType = $fetched['content_type'];
            $ext = $this->resolveExtension($url, $contentType, 'webp');
            $converted = $this->ensureWebpImage($body, $ext);
            $body = $converted['body'];
            $ext = $converted['ext'];

            $filename = $targetBaseName . '.' . $ext;
            $absPath = $this->productsImgDir . DIRECTORY_SEPARATOR . $filename;

            file_put_contents($absPath, $body);

            $size = strlen($body);
            $sha256 = hash('sha256', $body);
            $imgInfo = @getimagesize($absPath);
            $width = is_array($imgInfo) ? ($imgInfo[0] ?? null) : null;
            $height = is_array($imgInfo) ? ($imgInfo[1] ?? null) : null;
            $mime = $converted['mime']
                ?? (is_array($imgInfo) && ! empty($imgInfo['mime'])
                    ? $imgInfo['mime']
                    : ($contentType !== '' ? explode(';', $contentType)[0] : 'image/' . $ext));

            $media = Media::updateOrCreate(
                [
                    'disk' => 'public',
                    'folder' => 'clients/imgs/products',
                    'filename' => $filename,
                ],
                [
                    'wp_source_url' => $normUrl,
                    'original_name' => basename((string) parse_url($url, PHP_URL_PATH)) ?: $filename,
                    'extension' => $ext,
                    'mime_type' => $mime,
                    'size' => $size,
                    'width' => $width,
                    'height' => $height,
                    'sha256' => $sha256,
                    'title' => mb_substr($altTitle, 0, 255, 'UTF-8'),
                    'alt' => mb_substr($altTitle, 0, 255, 'UTF-8'),
                    'status' => 'active',
                    'import_batch_id' => $this->batchId,
                ]
            );

            return ['media' => $media, 'downloaded' => true];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tải tài liệu catalog và chống trùng theo cả URL lẫn SHA-256 hash.
     *
     * @return array{media: Media, reused: bool}|null
     */
    protected function downloadOrReuseCatalog(string $url, string $productName): ?array
    {
        $normUrl = $this->normalizeUrl($url);

        // 1. Kiểm tra cache URL trong bộ nhớ / DB
        if (isset($this->catalogUrlCache[$normUrl])) {
            $media = Media::find($this->catalogUrlCache[$normUrl]);
            if ($media) {
                return ['media' => $media, 'reused' => true];
            }
        }

        try {
            $fetched = $this->fetchBinary($url);
            if ($fetched === null) {
                return null;
            }

            $body = $fetched['body'];
            $contentType = $fetched['content_type'];
            $sha256 = hash('sha256', $body);

            // 2. Kiểm tra trùng nội dung qua SHA-256 (nhiều URL khác nhau nhưng cùng 1 file catalog)
            if (isset($this->catalogHashCache[$sha256])) {
                $media = Media::find($this->catalogHashCache[$sha256]);
                if ($media) {
                    $this->catalogUrlCache[$normUrl] = (int) $media->id;

                    return ['media' => $media, 'reused' => true];
                }
            }

            $ext = $this->resolveExtension($url, $contentType, 'pdf');
            $rawBasename = urldecode(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
            $safeName = $this->sanitizeSkuForFilename($rawBasename);
            if ($safeName === '' || $safeName === 'item') {
                $safeName = 'catalog-' . substr($sha256, 0, 12);
            }

            $filename = $safeName . '.' . $ext;
            $absPath = $this->catalogsDir . DIRECTORY_SEPARATOR . $filename;

            // Tránh ghi đè nếu 2 file khác nội dung trùng tên
            if (file_exists($absPath) && hash_file('sha256', $absPath) !== $sha256) {
                $filename = $safeName . '-' . substr($sha256, 0, 8) . '.' . $ext;
                $absPath = $this->catalogsDir . DIRECTORY_SEPARATOR . $filename;
            }

            file_put_contents($absPath, $body);

            $mime = $contentType !== '' ? trim(explode(';', $contentType)[0]) : 'application/pdf';

            $media = Media::updateOrCreate(
                [
                    'disk' => 'public',
                    'folder' => 'clients/imgs/catalogs',
                    'filename' => $filename,
                ],
                [
                    'wp_source_url' => $normUrl,
                    'original_name' => basename((string) parse_url($url, PHP_URL_PATH)) ?: $filename,
                    'extension' => $ext,
                    'mime_type' => $mime,
                    'size' => strlen($body),
                    'sha256' => $sha256,
                    'title' => mb_substr($rawBasename ?: $productName, 0, 255, 'UTF-8'),
                    'status' => 'active',
                    'import_batch_id' => $this->batchId,
                ]
            );

            $this->catalogUrlCache[$normUrl] = (int) $media->id;
            $this->catalogHashCache[$sha256] = (int) $media->id;

            return ['media' => $media, 'reused' => false];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveBrandId(string $rawBrand): ?int
    {
        $clean = trim($rawBrand);
        if ($clean === '') {
            return null;
        }

        // Nếu nhiều thương hiệu phân tách bằng dấu phẩy, lấy hãng đầu tiên khớp
        $candidates = array_filter(array_map('trim', explode(',', $clean)));
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

    /**
     * Khớp danh mục từ chuỗi CSV (cấu trúc Hãng > Thiết bị) về đúng cây danh mục thiết bị trong DB.
     *
     * @return array{0: int|null, 1: array<int, int>} [primaryCategoryId, allMatchedCategoryIds]
     */
    protected function resolveCategories(string $rawCategories, string $productName = ''): array
    {
        if ($this->categoryMatcher === null) {
            $this->categoryMatcher = new CategoryMatcherService();
        }

        return $this->categoryMatcher->resolve($rawCategories, $productName);
    }

    /**
     * Chỉ đồng bộ lại danh mục (category_id và bảng category_product) cho toàn bộ sản phẩm đã có trong DB.
     * Không tải ảnh/catalog, không ghi đè mô tả hay SKU.
     */
    protected function runSyncCategoriesOnly(string $filePath): int
    {
        $matcher = new CategoryMatcherService();

        $byWpId = [];
        $bySlug = [];
        $bySku = [];
        foreach (Product::select(['id', 'source_wp_id', 'slug', 'sku'])->cursor() as $p) {
            $pid = (int) $p->id;
            if (! empty($p->source_wp_id)) {
                $byWpId[(int) $p->source_wp_id] = $pid;
            }
            if (! empty($p->slug)) {
                $bySlug[strtolower((string) $p->slug)] = $pid;
            }
            if (! empty($p->sku)) {
                $bySku[strtoupper((string) $p->sku)] = $pid;
            }
        }

        if (empty($byWpId) && empty($bySlug) && empty($bySku)) {
            $this->warn('Không có sản phẩm nào trong cơ sở dữ liệu để đồng bộ danh mục.');

            return self::SUCCESS;
        }

        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            $this->error("Không thể mở file: {$filePath}");

            return self::FAILURE;
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $headers = fgetcsv($fh);
        if (! is_array($headers) || count($headers) < 5) {
            fclose($fh);
            $this->error('Header file CSV không hợp lệ.');

            return self::FAILURE;
        }

        $colMap = $this->buildColumnMap($headers);
        $updated = 0;
        $unmatched = 0;
        $processedIds = [];
        $now = Carbon::now('Asia/Ho_Chi_Minh');

        while (($row = fgetcsv($fh)) !== false) {
            $sourceWpId = (int) $this->getCol($row, $colMap, 'id') ?: null;
            $slug = strtolower($this->getCol($row, $colMap, 'slug'));
            $sku = strtoupper($this->getCol($row, $colMap, 'sku'));

            $productId = null;
            if ($sourceWpId !== null && isset($byWpId[$sourceWpId])) {
                $productId = $byWpId[$sourceWpId];
            } elseif ($slug !== '' && isset($bySlug[$slug])) {
                $productId = $bySlug[$slug];
            } elseif ($sku !== '' && isset($bySku[$sku])) {
                $productId = $bySku[$sku];
            }

            if ($productId === null || isset($processedIds[$productId])) {
                continue;
            }
            $processedIds[$productId] = true;

            $name = $this->getCol($row, $colMap, 'name');
            $rawCategories = $this->getCol($row, $colMap, 'categories');
            [$primaryCategoryId, $categoryIds] = $matcher->resolve($rawCategories, $name);

            if ($primaryCategoryId === null) {
                $unmatched++;
                continue;
            }

            if (! $this->dryRun) {
                DB::transaction(function () use ($productId, $primaryCategoryId, $categoryIds, $now) {
                    DB::table('products')
                        ->where('id', $productId)
                        ->update(['category_id' => $primaryCategoryId]);

                    DB::table('category_product')->where('product_id', $productId)->delete();
                    $pivots = [];
                    foreach ($categoryIds as $cid) {
                        $pivots[] = [
                            'category_id' => $cid,
                            'product_id' => $productId,
                            'is_primary' => $cid === $primaryCategoryId ? 1 : 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if (! empty($pivots)) {
                        DB::table('category_product')->insert($pivots);
                    }
                });
            }

            $updated++;
        }

        fclose($fh);

        if ($updated > 0 && ! $this->dryRun) {
            $this->clearCatalogCaches();
        }

        $this->info("Đồng bộ danh mục hoàn tất: Đã cập nhật {$updated} sản phẩm | Chưa khớp {$unmatched} sản phẩm.");

        return self::SUCCESS;
    }

    protected function runSyncBrandsOnly(string $filePath, int $limit = 0): int
    {
        $this->preloadLookupData();

        $byWpId = [];
        $bySlug = [];
        $bySku = [];
        foreach (Product::select(['id', 'source_wp_id', 'slug', 'sku', 'brand_id'])->cursor() as $p) {
            $pid = (int) $p->id;
            if (! empty($p->source_wp_id)) {
                $byWpId[(int) $p->source_wp_id] = $pid;
            }
            if (! empty($p->slug)) {
                $bySlug[strtolower((string) $p->slug)] = $pid;
            }
            if (! empty($p->sku)) {
                $bySku[strtoupper((string) $p->sku)] = $pid;
            }
        }

        if (empty($byWpId) && empty($bySlug) && empty($bySku)) {
            $this->warn('Không có sản phẩm nào trong cơ sở dữ liệu để đồng bộ thương hiệu.');

            return self::SUCCESS;
        }

        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            $this->error("Không thể mở file: {$filePath}");

            return self::FAILURE;
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $headers = fgetcsv($fh);
        if (! is_array($headers) || count($headers) < 3) {
            fclose($fh);
            $this->error('Header file CSV không hợp lệ.');

            return self::FAILURE;
        }

        $colMap = $this->buildColumnMap($headers);
        if ($colMap['brand'] === null) {
            fclose($fh);
            $this->error("Không tìm thấy cột 'Thương hiệu' hoặc 'brand' trong file CSV.");

            return self::FAILURE;
        }

        $updated = 0;
        $unmatched = 0;
        $processedIds = [];

        while (($row = fgetcsv($fh)) !== false) {
            $sourceWpId = (int) $this->getCol($row, $colMap, 'id') ?: null;
            $slug = strtolower($this->getCol($row, $colMap, 'slug'));
            $sku = strtoupper($this->getCol($row, $colMap, 'sku'));

            $productId = null;
            if ($sourceWpId !== null && isset($byWpId[$sourceWpId])) {
                $productId = $byWpId[$sourceWpId];
            } elseif ($slug !== '' && isset($bySlug[$slug])) {
                $productId = $bySlug[$slug];
            } elseif ($sku !== '' && isset($bySku[$sku])) {
                $productId = $bySku[$sku];
            }

            if ($productId === null || isset($processedIds[$productId])) {
                continue;
            }

            $rawBrand = $this->getCol($row, $colMap, 'brand');
            $brandId = $this->resolveBrandId($rawBrand);

            if ($brandId === null) {
                $unmatched++;
                continue;
            }

            $processedIds[$productId] = true;

            if (! $this->dryRun) {
                DB::table('products')->where('id', $productId)->update(['brand_id' => $brandId]);
            }

            $updated++;

            if ($limit > 0 && $updated >= $limit) {
                $this->warn("Đã đạt giới hạn --limit={$limit} sản phẩm kiểm thử.");
                break;
            }
        }

        fclose($fh);

        if ($updated > 0 && ! $this->dryRun) {
            $this->clearCatalogCaches();
        }

        $this->info("Đồng bộ thương hiệu hoàn tất: Đã cập nhật hãng cho {$updated} sản phẩm | Chưa khớp hãng {$unmatched} sản phẩm.");

        return self::SUCCESS;
    }

    /**
     * Xóa bộ nhớ đệm danh mục/trang chủ/cửa hàng để sản phẩm mới hiển thị ngay lập tức.
     */
    protected function clearCatalogCaches(): void
    {
        foreach ([
            'home_category_sections_map',
            'catalog_category_product_counts_v1',
            'shop_sidebar_categories',
            'shop_filter_brands_nonempty_v1',
            'shop_max_price',
            'all_brands_page',
        ] as $key) {
            Cache::forget($key);
        }
    }

    protected function parsePrice(string $raw): float
    {
        $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $raw)) ?? '';

        return $clean !== '' ? (float) $clean : 0.0;
    }

    /**
     * @return array<int, string>
     */
    protected function parseCommaSeparatedUrls(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $items = array_map('trim', explode(',', $raw));
        $urls = [];
        foreach ($items as $item) {
            if ($item !== '' && preg_match('/^https?:\/\//i', $item)) {
                $urls[] = $item;
            }
        }

        return array_values(array_unique($urls));
    }

    protected function isGoogleDriveUrl(string $url): bool
    {
        $lower = strtolower($url);

        return str_contains($lower, 'drive.google.com')
            || str_contains($lower, 'docs.google.com')
            || str_contains($lower, 'drive.usercontent.google.com');
    }

    protected function extractCanonicalPath(string $permalink, string $slug): string
    {
        if ($permalink !== '') {
            $path = (string) parse_url($permalink, PHP_URL_PATH);
            if ($path !== '') {
                return '/' . trim($path, '/') . '/';
            }
        }

        return '/product/' . trim($slug, '/') . '/';
    }

    protected function generateUniqueHptSku(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = 'HPT' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $upper = strtoupper($candidate);
            if (! isset($this->usedSkus[$upper])) {
                $this->usedSkus[$upper] = true;

                return $candidate;
            }
        }

        $fallback = 'HPT' . substr((string) hrtime(true), -6);
        $this->usedSkus[strtoupper($fallback)] = true;

        return $fallback;
    }

    protected function sanitizeSkuForFilename(string $raw): string
    {
        $clean = trim($raw);
        $clean = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $clean) ?? '';
        $clean = trim($clean, '-_');

        return $clean !== '' ? mb_substr($clean, 0, 120, 'UTF-8') : 'item';
    }

    protected function normalizeUrl(string $url): string
    {
        return trim($url);
    }

    /**
     * Kiểm tra URL HTTP/HTTPS hợp lệ trước khi đưa vào Guzzle/Http::pool
     * để tránh MalformedUriException với các URL lỗi (host chứa khoảng trắng, tiếng Việt, ký tự lạ...).
     */
    protected function isValidHttpUrl(string $url): bool
    {
        if ($url === '' || ! preg_match('/^https?:\/\//i', $url)) {
            return false;
        }

        $parts = @parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        // Loại bỏ host chứa khoảng trắng, ký tự non-ASCII hoặc không giống tên miền/IP hợp lệ
        if (preg_match('/[\s\x80-\xFF]/', $host) || ! str_contains($host, '.')) {
            return false;
        }

        try {
            new \GuzzleHttp\Psr7\Uri($url);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Đảm bảo ảnh đầu ra ở định dạng WebP:
     * - Nếu dữ liệu ảnh đã là WebP thực sự (kiểm tra chữ ký nhị phân RIFF...WEBP): giữ nguyên, không nén lại.
     * - Nếu không phải WebP (JPG, PNG, GIF, BMP, ...): chuyển đổi sang WebP ngay trong RAM bằng GD, giữ kênh alpha.
     * - Bỏ qua chuyển đổi đối với ảnh vector SVG hoặc khi không giải mã được (giữ nguyên an toàn).
     *
     * @return array{body: string, ext: string, mime: string|null}
     */
    protected function ensureWebpImage(string $body, string $ext, int $quality = 85): array
    {
        $extLower = strtolower($ext);

        // Kiểm tra chữ ký nhị phân thực tế của định dạng WebP (RIFF....WEBP)
        if (strlen($body) >= 12 && substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') {
            return [
                'body' => $body,
                'ext' => 'webp',
                'mime' => 'image/webp',
            ];
        }

        // Ảnh vector SVG hoặc môi trường không có GD imagewebp -> giữ nguyên định dạng gốc
        if ($extLower === 'svg' || ! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return [
                'body' => $body,
                'ext' => $extLower,
                'mime' => null,
            ];
        }

        $img = @imagecreatefromstring($body);
        if ($img === false) {
            return [
                'body' => $body,
                'ext' => $extLower,
                'mime' => null,
            ];
        }

        try {
            // Chuyển ảnh bảng màu (PNG-8 / GIF) sang truecolor vì GD imagewebp yêu cầu truecolor
            if (! imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }
            imagealphablending($img, true);
            imagesavealpha($img, true);

            ob_start();
            $ok = @imagewebp($img, null, $quality);
            $webpBody = (string) ob_get_clean();

            if ($ok && $webpBody !== '' && strlen($webpBody) >= 12 && substr($webpBody, 0, 4) === 'RIFF' && substr($webpBody, 8, 4) === 'WEBP') {
                return [
                    'body' => $webpBody,
                    'ext' => 'webp',
                    'mime' => 'image/webp',
                ];
            }
        } finally {
            unset($img);
        }

        return [
            'body' => $body,
            'ext' => $extLower,
            'mime' => null,
        ];
    }

    protected function resolveExtension(string $url, string $contentType, string $fallback): string
    {
        $pathExt = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
        if ($pathExt !== '' && in_array($pathExt, $allowed, true)) {
            return $pathExt === 'jpeg' ? 'jpg' : $pathExt;
        }

        $mime = strtolower(trim(explode(';', $contentType)[0]));

        return match ($mime) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            default => $fallback,
        };
    }
}
