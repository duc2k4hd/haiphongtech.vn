<?php

namespace App\Console\Commands;

use App\Services\Migration\WpSqlProductImportService;
use Illuminate\Console\Command;

class WpPruneSqlCommand extends Command
{
    protected $signature = 'wp:prune-sql
        {input=D:\File\duljqgdz_hptechnew.sql : Đường dẫn tệp SQL WordPress nguồn}
        {--out= : Đường dẫn xuất tệp SQL đã dọn rác (mặc định storage/app/migration/duljqgdz_hptechnew_clean.sql)}
        {--rebuild-index : Bắt buộc đọc lại file SQL nguồn}';

    protected $description = 'Loại bỏ bảng/dữ liệu rác trong SQL dump WordPress, làm sạch HTML sản phẩm, bỏ link Google Drive catalog và xuất file SQL nhẹ';

    public function handle(WpSqlProductImportService $service): int
    {
        $input = (string) $this->argument('input');
        $out = (string) ($this->option('out') ?: storage_path('app/migration/duljqgdz_hptechnew_clean.sql'));
        $rebuild = (bool) $this->option('rebuild-index');
        $sqlitePath = storage_path('app/migration/hptech_products_stage.sqlite');

        $this->info('=== HAI PHONG TECH SQL DUMP PRUNER ===');
        $this->line("Input : {$input}");
        $this->line("Output: {$out}");

        $stageStats = $service->buildStageSqlite($input, $sqlitePath, $rebuild, fn(string $msg) => $this->line($msg));
        $this->line('SQLite stage stats: ' . json_encode($stageStats, JSON_UNESCAPED_UNICODE));

        $counts = $service->exportPrunedSql($sqlitePath, $out, fn(string $msg) => $this->info($msg));
        $this->table(
            ['Pruned Table', 'Rows Kept'],
            collect($counts)->map(fn($v, $k) => [$k, number_format($v)])->values()->toArray()
        );

        return self::SUCCESS;
    }
}
