<?php

namespace App\Console\Commands;

use App\Models\Brand;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SimpleXMLElement;
use ZipArchive;

class BrandsImportCommand extends Command
{
    protected $signature = 'brands:import
        {file=D:\File\File dùng 1 lần\thuong-hieu-20261124-011829.xlsx : Đường dẫn file Excel (.xlsx) hoặc CSV chứa danh sách thương hiệu}
        {--dry-run : Chạy thử không ghi vào cơ sở dữ liệu}';

    protected $description = 'Import danh sách thương hiệu / hãng từ file Excel (.xlsx) hoặc CSV vào bảng brands.';

    public function handle(): int
    {
        $filePath = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($filePath)) {
            $this->error("Không tìm thấy file: {$filePath}");

            return self::FAILURE;
        }

        $this->info("Đang đọc dữ liệu thương hiệu từ: {$filePath}" . ($dryRun ? ' [CHẾ ĐỘ DRY-RUN]' : ''));

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rows = [];

        if ($ext === 'xlsx') {
            $rows = $this->parseXlsx($filePath);
        } elseif ($ext === 'csv') {
            $rows = $this->parseCsv($filePath);
        } else {
            $this->error("Định dạng file không được hỗ trợ (.{$ext}). Vui lòng dùng file .xlsx hoặc .csv");

            return self::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('Không tìm thấy dữ liệu thương hiệu nào trong file.');

            return self::FAILURE;
        }

        $this->info(sprintf('Tìm thấy %d thương hiệu cần xử lý.', count($rows)));

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $batchId = 'brand_import_' . $now->format('Ymd_His');

        $headers = ['STT', 'ID', 'Tên hãng', 'Slug', 'Trạng thái', 'Hành động'];
        $tableData = [];

        foreach ($rows as $index => $item) {
            $sourceWpId = ! empty($item['id']) ? (int) $item['id'] : null;
            $name = trim($item['name'] ?? '');
            $slug = Str::slug(trim($item['slug'] ?? ''));

            if ($slug === '' && $name !== '') {
                $slug = Str::slug($name);
            }

            if ($name === '' || $slug === '') {
                $skipped++;
                continue;
            }

            $content = trim($item['content'] ?? '');
            $metaTitle = "{$name} - Phân phối chính hãng Hải Phòng Tech";
            $metaDesc = $content !== ''
                ? Str::limit(strip_tags($content), 158)
                : "Phân phối thiết bị tự động hóa {$name} chính hãng tại Việt Nam, giá tốt, bảo hành chính hãng.";

            $attributes = [
                'name' => $name,
                'content' => $content !== '' ? $content : null,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'status' => 'active',
                'in_sitemap' => true,
                'source_taxonomy' => 'thuong_hieu',
                'import_batch_id' => $batchId,
            ];

            if ($sourceWpId !== null) {
                $attributes['source_wp_id'] = $sourceWpId;
            }

            $action = 'dry-run';
            if (! $dryRun) {
                $brand = Brand::where('slug', $slug)
                    ->orWhere(function ($q) use ($sourceWpId) {
                        if ($sourceWpId !== null) {
                            $q->where('source_wp_id', $sourceWpId);
                        }
                    })
                    ->first();

                if ($brand) {
                    $brand->update($attributes);
                    $updated++;
                    $action = 'Cập nhật';
                } else {
                    $attributes['slug'] = $slug;
                    Brand::create($attributes);
                    $created++;
                    $action = 'Tạo mới';
                }
            } else {
                $exists = Brand::where('slug', $slug)->exists();
                $action = $exists ? 'Sẽ cập nhật' : 'Sẽ tạo mới';
                if ($exists) {
                    $updated++;
                } else {
                    $created++;
                }
            }

            if (count($tableData) < 20 || $index >= count($rows) - 5) {
                $tableData[] = [
                    $index + 1,
                    $sourceWpId ?: '-',
                    $name,
                    $slug,
                    'active',
                    $action,
                ];
            }
        }

        $this->table($headers, $tableData);

        if (! $dryRun) {
            $this->clearBrandCaches();
        }

        $this->newLine();
        $this->info("=== HOÀN TẤT IMPORT THƯƠNG HIỆU ===");
        $this->line(" - Tạo mới: {$created}");
        $this->line(" - Cập nhật: {$updated}");
        if ($skipped > 0) {
            $this->line(" - Bỏ qua (thiếu tên): {$skipped}");
        }
        $this->line(" - Tổng số hãng hiện có trong DB: " . Brand::count());

        return self::SUCCESS;
    }

    /**
     * Đọc file XLSX bằng ZipArchive & SimpleXMLElement thuần PHP (0 dependency, siêu nhẹ)
     *
     * @return array<int, array{id: string, name: string, slug: string, content: string}>
     */
    protected function parseXlsx(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $this->error("Không thể giải nén file XLSX: {$filePath}");

            return [];
        }

        // 1. Đọc sharedStrings nếu có
        $sharedStrings = [];
        $ssContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssContent !== false) {
            $xmlSS = @simplexml_load_string($ssContent);
            if ($xmlSS && isset($xmlSS->si)) {
                foreach ($xmlSS->si as $val) {
                    if (isset($val->t)) {
                        $sharedStrings[] = (string) $val->t;
                    } elseif (isset($val->r)) {
                        $t = '';
                        foreach ($val->r as $r) {
                            $t .= (string) $r->t;
                        }
                        $sharedStrings[] = $t;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Đọc sheet1.xml
        $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetContent === false) {
            $this->error("Không tìm thấy dữ liệu trang tính (sheet1.xml) trong file XLSX.");

            return [];
        }

        $xml = @simplexml_load_string($sheetContent);
        if (! $xml || ! isset($xml->sheetData->row)) {
            $this->error("Cấu trúc sheet1.xml không hợp lệ.");

            return [];
        }

        $colMap = [];
        $rows = [];

        foreach ($xml->sheetData->row as $rIdx => $r) {
            $cellValues = [];
            foreach ($r->c as $c) {
                $coord = (string) $c['r'];
                $colLetter = preg_replace('/[0-9]/', '', $coord);
                $val = '';
                $type = (string) $c['t'];

                if ($type === 'inlineStr' && isset($c->is->t)) {
                    $val = (string) $c->is->t;
                } elseif ($type === 's' && isset($c->v) && isset($sharedStrings[(int) $c->v])) {
                    $val = $sharedStrings[(int) $c->v];
                } elseif (isset($c->v)) {
                    $val = (string) $c->v;
                }

                $cellValues[$colLetter] = trim($val);
            }

            // Dòng đầu tiên là tiêu đề (Header)
            if (empty($colMap)) {
                foreach ($cellValues as $letter => $val) {
                    $norm = mb_strtolower(trim($val), 'UTF-8');
                    match (true) {
                        $norm === 'id' => $colMap['id'] = $letter,
                        $norm === 'tên' || $norm === 'ten' || $norm === 'name' => $colMap['name'] = $letter,
                        $norm === 'slug' => $colMap['slug'] = $letter,
                        $norm === 'mô tả' || $norm === 'mo ta' || $norm === 'description' => $colMap['content'] = $letter,
                        default => null,
                    };
                }

                // Fallback nếu không có tên cột khớp
                $colMap['id'] = $colMap['id'] ?? 'A';
                $colMap['name'] = $colMap['name'] ?? 'B';
                $colMap['slug'] = $colMap['slug'] ?? 'C';
                $colMap['content'] = $colMap['content'] ?? 'D';
                continue;
            }

            $name = $cellValues[$colMap['name']] ?? '';
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'id' => $cellValues[$colMap['id']] ?? '',
                'name' => $name,
                'slug' => $cellValues[$colMap['slug']] ?? '',
                'content' => $cellValues[$colMap['content']] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Đọc file CSV
     *
     * @return array<int, array{id: string, name: string, slug: string, content: string}>
     */
    protected function parseCsv(string $filePath): array
    {
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            return [];
        }

        // Bỏ qua BOM nếu có
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $header = fgetcsv($fh);
        if (! is_array($header)) {
            fclose($fh);

            return [];
        }

        $colMap = [
            'id' => 0,
            'name' => 1,
            'slug' => 2,
            'content' => 3,
        ];

        foreach ($header as $idx => $val) {
            $norm = mb_strtolower(trim((string) $val), 'UTF-8');
            match (true) {
                $norm === 'id' => $colMap['id'] = $idx,
                $norm === 'tên' || $norm === 'ten' || $norm === 'name' => $colMap['name'] = $idx,
                $norm === 'slug' => $colMap['slug'] = $idx,
                $norm === 'mô tả' || $norm === 'mo ta' || $norm === 'description' => $colMap['content'] = $idx,
                default => null,
            };
        }

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $name = trim((string) ($row[$colMap['name']] ?? ''));
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'id' => trim((string) ($row[$colMap['id']] ?? '')),
                'name' => $name,
                'slug' => trim((string) ($row[$colMap['slug']] ?? '')),
                'content' => trim((string) ($row[$colMap['content']] ?? '')),
            ];
        }

        fclose($fh);

        return $rows;
    }

    protected function clearBrandCaches(): void
    {
        foreach ([
            'home_partner_brands',
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
