<?php

namespace App\Console\Commands;

use App\Services\Migration\WpSqlProductImportService;
use Illuminate\Console\Command;

class WpImportProductsSqlCommand extends Command
{
    protected $signature = 'wp:import-products-sql
        {file=D:\File\duljqgdz_hptechnew.sql : Đường dẫn tệp SQL WordPress đầy đủ}
        {--apply : Ghi dữ liệu thật vào CSDL staging (mặc định dry-run)}
        {--purge : Xóa toàn bộ sản phẩm cũ và ảnh/tài liệu sản phẩm cũ trước khi nạp}
        {--chunk-size=1000 : Số lượng sản phẩm nạp mỗi batch}
        {--rebuild-index : Ép phân tích lại tệp SQL thay vì dùng chỉ mục SQLite có sẵn}
        {--prune-sql-out= : Tùy chọn xuất thêm file SQL sạch gọn không rác}';

    protected $description = 'Xóa sản phẩm/ảnh cũ và nạp chính xác toàn bộ sản phẩm, hãng, danh mục, thẻ, ảnh & tài liệu không trùng lặp từ WordPress SQL dump';

    public function handle(WpSqlProductImportService $service): int
    {
        $file = (string) $this->argument('file');
        $apply = (bool) $this->option('apply');
        $purge = (bool) $this->option('purge');
        $chunkSize = max(100, (int) $this->option('chunk-size'));
        $rebuild = (bool) $this->option('rebuild-index');
        $pruneSqlOut = $this->option('prune-sql-out') ? (string) $this->option('prune-sql-out') : null;

        $this->info('=== HAI PHONG TECH FULL SQL PRODUCT IMPORTER ===');
        $this->line('SQL Source  : ' . $file);
        $this->line('Mode        : ' . ($apply ? 'STAGING APPLY' : 'DRY-RUN (0 DB writes)'));
        $this->line('Purge First : ' . ($purge ? 'YES (Products + Product Media)' : 'NO'));
        $this->line('Environment : ' . config('app.env'));

        $report = $service->import(
            $file,
            $apply,
            $purge,
            $rebuild,
            $chunkSize,
            fn(string $msg) => $this->line($msg)
        );

        if ($pruneSqlOut !== null) {
            $sqlitePath = storage_path('app/migration/hptech_products_stage.sqlite');
            $service->exportPrunedSql($sqlitePath, $pruneSqlOut, fn(string $msg) => $this->info($msg));
        }

        $this->newLine();
        $this->info("Run ID: {$report['run_id']} | DB: {$report['database']} | Backup: " . ($report['backup_id'] ?? 'N/A'));
        $this->table(
            ['Metric', 'Count'],
            collect($report['counters'])->map(fn($v, $k) => [$k, number_format((int) $v)])->values()->toArray()
        );

        return self::SUCCESS;
    }
}
