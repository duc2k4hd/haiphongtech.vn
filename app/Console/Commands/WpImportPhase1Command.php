<?php

namespace App\Console\Commands;

use App\Services\Migration\WpPhase1Importer;
use Illuminate\Console\Command;

class WpImportPhase1Command extends Command
{
    protected $signature = 'wp:import-phase1
        {--dry-run : Default safe simulation mode (rolls back all DB writes)}
        {--apply : Commit changes to isolated staging database (requires --approved-plan)}
        {--source-snapshot=snap_20261118_phase1 : Frozen source snapshot ID}
        {--approved-plan= : Approval token required when --apply is set}
        {--force-overwrite : Allow overwriting pre-existing records without import_batch_id}';

    protected $description = 'Import WordPress Phase 1 Content, Taxonomies, Media, Menus, Settings & URL Route Registry (default: --dry-run)';

    public function handle(WpPhase1Importer $importer): int
    {
        $apply = (bool) $this->option('apply');
        $snapshotId = (string) $this->option('source-snapshot');
        $approvedPlan = (string) ($this->option('approved-plan') ?? '');
        $forceOverwrite = (bool) $this->option('force-overwrite');

        $this->info('=== HAI PHONG TECH PHASE 1 IMPORTER ===');
        $this->line('Mode            : ' . ($apply ? 'STAGING APPLY' : 'DRY-RUN (0 persistent writes)'));
        $this->line('Source Snapshot : ' . $snapshotId);
        $this->line('Environment     : ' . config('app.env'));

        $report = $importer->run($snapshotId, $apply, $approvedPlan, $forceOverwrite);

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

        if (!empty($report['conflicts'])) {
            $this->warn('Conflicts / Shadowed items recorded: ' . count($report['conflicts']));
        }

        // Save latest run report JSON to storage/app/private/wp_snapshots/<snapshot>/last_<mode>.json
        $outDir = storage_path("app/private/wp_snapshots/{$snapshotId}");
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }
        file_put_contents(
            "{$outDir}/last_{$report['mode']}_report.json",
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return self::SUCCESS;
    }
}
