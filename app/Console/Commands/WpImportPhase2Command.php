<?php

namespace App\Console\Commands;

use App\Services\Migration\WpPhase2Importer;
use Illuminate\Console\Command;

class WpImportPhase2Command extends Command
{
    protected $signature = 'wp:import-phase2
        {--dry-run : Default safe simulation mode (rolls back all DB writes)}
        {--apply : Commit changes to isolated staging database (requires --approved-plan)}
        {--source-snapshot=snap_phase2_pilot : Frozen source snapshot ID}
        {--source-dir= : Optional custom Phase 2 JSONL directory (default: docs/migration/data/phase2)}
        {--products-file=pilot_products.jsonl : Product JSONL filename inside source-dir (e.g. products.jsonl for Track A)}
        {--chunk-size=500 : Batch size for scalable chunked ingestion}
        {--approved-plan= : Approval token required when --apply is set}
        {--force-overwrite : Allow overwriting pre-existing unbatched records}';

    protected $description = 'Import WordPress Phase 2 WooCommerce Categories, Brands, Tags, Products, Media & Activate Routes (default: --dry-run)';

    public function handle(WpPhase2Importer $importer): int
    {
        $apply = (bool) $this->option('apply');
        $snapshotId = (string) $this->option('source-snapshot');
        $sourceDir = $this->option('source-dir') ? (string) $this->option('source-dir') : null;
        $productsFile = (string) ($this->option('products-file') ?: 'pilot_products.jsonl');
        $chunkSize = max(50, (int) $this->option('chunk-size'));
        $approvedPlan = (string) ($this->option('approved-plan') ?? '');
        $forceOverwrite = (bool) $this->option('force-overwrite');

        $this->info('=== HAI PHONG TECH PHASE 2 IMPORTER (WOOCOMMERCE) ===');
        $this->line('Mode            : ' . ($apply ? 'STAGING APPLY' : 'DRY-RUN (0 persistent writes)'));
        $this->line('Source Snapshot : ' . $snapshotId);
        $this->line('Products File   : ' . $productsFile . " (chunk={$chunkSize})");
        $this->line('Environment     : ' . config('app.env'));

        $report = $importer->run(
            $snapshotId,
            $apply,
            $approvedPlan,
            $forceOverwrite,
            $sourceDir,
            $productsFile,
            $chunkSize
        );

        $this->newLine();
        $this->info("Run ID: {$report['run_id']} | DB: {$report['database']} | Backup: " . ($report['backup_checkpoint_id'] ?? 'N/A (dry-run)'));

        $rows = [];
        foreach ($report['by_group'] as $group => $stats) {
            $rows[] = [
                $group,
                $stats['inserts'] ?? 0,
                $stats['updates'] ?? 0,
                $stats['skips'] ?? 0,
                $stats['conflicts'] ?? 0,
            ];
        }
        $rows[] = [
            'TOTAL',
            $report['totals']['inserts'],
            $report['totals']['updates'],
            $report['totals']['skips'],
            $report['totals']['conflicts'],
        ];

        $this->table(['Group', 'Inserts', 'Updates', 'Skips', 'Conflicts'], $rows);

        return self::SUCCESS;
    }
}
