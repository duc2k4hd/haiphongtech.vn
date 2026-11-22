<?php

namespace App\Console\Commands;

use App\Services\Migration\StagingBackupService;
use Illuminate\Console\Command;

class WpStagingBackupCommand extends Command
{
    protected $signature = 'wp:staging-backup
        {--init-staging-db : Create isolated haiphongtech_staging DB and switch local .env away from misutech}
        {--create : Create a full schema+data+files checkpoint backup}
        {--tag=checkpoint : Tag label for backup}
        {--verify-restore= : Run an isolated restore verification drill for a backup ID}
        {--restore= : Restore staging DB from a backup ID}
        {--restore-files : Also restore public/.htaccess, robots.txt, sitemap.xml when restoring}';

    protected $description = 'Manage isolated staging DB setup, checkpoint backups, restore verification drills, and rollbacks';

    public function handle(StagingBackupService $service): int
    {
        if ($this->option('init-staging-db')) {
            $info = $service->initializeIsolatedStagingDatabase('haiphongtech_staging');
            $this->info('Isolated staging database initialized: ' . $info['staging_database']);
            $this->line('Local .env backed up SHA-256: ' . ($info['env_backup_sha256'] ?? 'N/A'));
            return self::SUCCESS;
        }

        if ($this->option('create')) {
            $manifest = $service->createBackup((string) $this->option('tag'));
            $this->info('Backup created: ' . $manifest['backup_id']);
            $this->line('Tables backed up: ' . $manifest['tables_count'] . ' | Schema SHA-256: ' . $manifest['schema_sha256']);
            return self::SUCCESS;
        }

        if ($verifyId = $this->option('verify-restore')) {
            $proof = $service->verifyRestoreDrill((string) $verifyId);
            if ($proof['passed']) {
                $this->info("Restore verification PASSED for {$verifyId} ({$proof['tables_checked']} tables verified).");
                return self::SUCCESS;
            }
            $this->error('Restore verification FAILED: ' . json_encode($proof['mismatches']));
            return self::FAILURE;
        }

        if ($restoreId = $this->option('restore')) {
            $res = $service->restoreBackup((string) $restoreId, null, (bool) $this->option('restore-files'));
            $this->info("Restored {$res['backup_id']} to {$res['restored_to_db']} ({$res['tables_restored']} tables).");
            return self::SUCCESS;
        }

        $this->warn('Specify one of: --init-staging-db, --create, --verify-restore=<id>, or --restore=<id>');
        return self::INVALID;
    }
}
