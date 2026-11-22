<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

class StagingBackupService
{
    /**
     * Databases that MUST NEVER be written to, truncated, or restored over.
     */
    public const PROTECTED_DATABASES = [
        'misutech',
        'misutech.vn',
        'haiphongtech_prod',
        'haiphongtech_production',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
    ];

    /**
     * Assert current environment and DB connection are strictly isolated staging/local.
     */
    public function assertSafeStagingTarget(?string $targetDb = null): void
    {
        $env = strtolower((string) config('app.env'));
        if ($env === 'production' || $env === 'prod') {
            throw new RuntimeException('ABORTED: Refusing to execute migration/backup write in APP_ENV=production.');
        }

        $connName = (string) config('database.default');
        $host = strtolower((string) config("database.connections.{$connName}.host"));
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException("ABORTED: Refusing non-local database host [{$host}].");
        }

        $dbName = $targetDb ?? (string) config("database.connections.{$connName}.database");
        if (in_array(strtolower($dbName), self::PROTECTED_DATABASES, true)) {
            throw new RuntimeException("ABORTED: Database [{$dbName}] is protected (shared/source Misutech or system DB).");
        }
    }

    /**
     * Initialize isolated `haiphongtech_staging` MySQL schema without touching `misutech`.
     * Also backs up local `.env` before switching `DB_DATABASE` to `haiphongtech_staging`.
     */
    public function initializeIsolatedStagingDatabase(string $stagingDbName = 'haiphongtech_staging'): array
    {
        if (in_array(strtolower($stagingDbName), self::PROTECTED_DATABASES, true)) {
            throw new RuntimeException("Invalid staging database name: {$stagingDbName}");
        }

        $connName = (string) config('database.default');
        $host = (string) config("database.connections.{$connName}.host", '127.0.0.1');
        $port = (string) config("database.connections.{$connName}.port", '3306');
        $user = (string) config("database.connections.{$connName}.username", 'root');
        $pass = (string) config("database.connections.{$connName}.password", '');

        if (!in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException("ABORTED: Refusing non-local MySQL host [{$host}].");
        }

        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $safeDbIdentifier = preg_replace('/[^a-zA-Z0-9_]/', '', $stagingDbName);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbIdentifier}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $backupRoot = storage_path('app/private/backups');
        if (!File::isDirectory($backupRoot)) {
            File::makeDirectory($backupRoot, 0755, true);
        }

        $envPath = base_path('.env');
        $envBackupPath = null;
        $envSha256 = null;
        if (File::exists($envPath)) {
            $ts = date('Ymd_His');
            $envBackupPath = "{$backupRoot}/env_pre_staging_{$ts}.bak";
            File::copy($envPath, $envBackupPath);
            $envSha256 = hash_file('sha256', $envBackupPath);

            $envContent = File::get($envPath);
            $envContent = preg_replace('/^DB_DATABASE=.*$/m', "DB_DATABASE={$safeDbIdentifier}", $envContent);
            $envContent = preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=staging', $envContent);
            $envContent = preg_replace('/^APP_NAME=.*$/m', 'APP_NAME="Hải Phòng Tech (Staging)"', $envContent);
            File::put($envPath, $envContent);
        }

        return [
            'staging_database' => $safeDbIdentifier,
            'env_backup_path'  => $envBackupPath,
            'env_backup_sha256'=> $envSha256,
            'created_at'       => gmdate('c'),
        ];
    }

    /**
     * Create a full schema + data + config snapshot backup of the current staging database.
     */
    public function createBackup(string $tag = 'checkpoint'): array
    {
        $this->assertSafeStagingTarget();

        $connName = (string) config('database.default');
        $dbName = (string) config("database.connections.{$connName}.database");
        $backupId = 'bkp_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $tag);
        $backupDir = storage_path("app/private/backups/{$backupId}");
        $tablesDir = "{$backupDir}/tables";

        File::makeDirectory($tablesDir, 0755, true);

        $tables = DB::select('SHOW TABLES');
        $tableSummaries = [];
        $schemaSql = "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $tObj) {
            $tableName = array_values((array) $tObj)[0];
            $createRow = (array) DB::selectOne("SHOW CREATE TABLE `{$tableName}`");
            $createStmt = $createRow['Create Table'] ?? array_values($createRow)[1];

            $schemaSql .= "DROP TABLE IF EXISTS `{$tableName}`;\n{$createStmt};\n\n";

            $jsonlPath = "{$tablesDir}/{$tableName}.jsonl";
            $fp = fopen($jsonlPath, 'w');
            $rowCount = 0;
            foreach (DB::table($tableName)->cursor() as $row) {
                fwrite($fp, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $rowCount++;
            }
            fclose($fp);

            $tableSummaries[$tableName] = [
                'rows'   => $rowCount,
                'sha256' => hash_file('sha256', $jsonlPath),
            ];
        }

        $schemaSql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $schemaPath = "{$backupDir}/schema.sql";
        File::put($schemaPath, $schemaSql);

        // Snapshot critical public web files
        $filesDir = "{$backupDir}/files";
        File::makeDirectory($filesDir, 0755, true);
        $fileChecksums = [];
        foreach (['public/.htaccess', 'public/robots.txt', 'public/sitemap.xml'] as $relFile) {
            $abs = base_path($relFile);
            if (File::exists($abs)) {
                $dest = "{$filesDir}/" . str_replace('/', '__', $relFile);
                File::copy($abs, $dest);
                $fileChecksums[$relFile] = hash_file('sha256', $dest);
            }
        }

        $manifest = [
            'backup_id'      => $backupId,
            'database'       => $dbName,
            'environment'    => config('app.env'),
            'created_at'     => gmdate('c'),
            'schema_sha256'  => hash_file('sha256', $schemaPath),
            'tables_count'   => count($tableSummaries),
            'tables'         => $tableSummaries,
            'files'          => $fileChecksums,
        ];

        File::put(
            "{$backupDir}/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $manifest;
    }

    /**
     * Restore a backup into a target database (either an isolated verification DB or `haiphongtech_staging`).
     */
    public function restoreBackup(string $backupId, ?string $overrideTargetDb = null, bool $restorePublicFiles = false): array
    {
        $connName = (string) config('database.default');
        $targetDb = $overrideTargetDb ?? (string) config("database.connections.{$connName}.database");
        $this->assertSafeStagingTarget($targetDb);

        $backupDir = storage_path("app/private/backups/{$backupId}");
        $manifestPath = "{$backupDir}/manifest.json";
        if (!File::exists($manifestPath)) {
            throw new RuntimeException("Backup manifest not found: {$manifestPath}");
        }

        $manifest = json_decode(File::get($manifestPath), true);
        $schemaPath = "{$backupDir}/schema.sql";
        if (hash_file('sha256', $schemaPath) !== ($manifest['schema_sha256'] ?? '')) {
            throw new RuntimeException("Schema checksum mismatch for backup {$backupId}!");
        }

        $host = (string) config("database.connections.{$connName}.host", '127.0.0.1');
        $port = (string) config("database.connections.{$connName}.port", '3306');
        $user = (string) config("database.connections.{$connName}.username", 'root');
        $pass = (string) config("database.connections.{$connName}.password", '');

        $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $targetDb);
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$safeDb}`");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        // Drop any extra tables created after the backup checkpoint
        $existingStmt = $pdo->query('SHOW TABLES');
        $existingTables = $existingStmt ? $existingStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($existingTables as $extTable) {
            if (!isset($manifest['tables'][$extTable])) {
                $pdo->exec("DROP TABLE IF EXISTS `{$extTable}`");
            }
        }

        // Execute schema DDL statements
        $ddlRaw = File::get($schemaPath);
        $pdo->exec($ddlRaw);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $restoredCounts = [];
        foreach ($manifest['tables'] as $tableName => $meta) {
            $jsonlPath = "{$backupDir}/tables/{$tableName}.jsonl";
            if (hash_file('sha256', $jsonlPath) !== $meta['sha256']) {
                throw new RuntimeException("Checksum mismatch on table dump {$tableName}!");
            }
            $fp = fopen($jsonlPath, 'r');
            $batch = [];
            $count = 0;
            while (($line = fgets($fp)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') continue;
                $batch[] = json_decode($trimmed, true);
                if (count($batch) >= 200) {
                    $this->insertBatchPdo($pdo, $tableName, $batch);
                    $count += count($batch);
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                $this->insertBatchPdo($pdo, $tableName, $batch);
                $count += count($batch);
            }
            fclose($fp);
            $restoredCounts[$tableName] = $count;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        if ($restorePublicFiles && !empty($manifest['files'])) {
            foreach ($manifest['files'] as $relFile => $expectedSha) {
                $src = "{$backupDir}/files/" . str_replace('/', '__', $relFile);
                if (File::exists($src) && hash_file('sha256', $src) === $expectedSha) {
                    File::copy($src, base_path($relFile));
                }
            }
        }

        return [
            'backup_id'       => $backupId,
            'restored_to_db'  => $safeDb,
            'restored_at'     => gmdate('c'),
            'tables_restored' => count($restoredCounts),
            'row_counts'      => $restoredCounts,
        ];
    }

    /**
     * Drill restore into an isolated temporary DB (`haiphongtech_restore_verify`), verify 100% row parity, then drop temp DB.
     */
    public function verifyRestoreDrill(string $backupId): array
    {
        $tempDb = 'haiphongtech_restore_verify';
        $result = $this->restoreBackup($backupId, $tempDb, false);

        $manifest = json_decode(File::get(storage_path("app/private/backups/{$backupId}/manifest.json")), true);
        $mismatches = [];
        foreach ($manifest['tables'] as $table => $meta) {
            $actual = $result['row_counts'][$table] ?? -1;
            if ($actual !== (int) $meta['rows']) {
                $mismatches[$table] = ['expected' => $meta['rows'], 'actual' => $actual];
            }
        }

        // Clean up temp verification database
        $connName = (string) config('database.default');
        $host = (string) config("database.connections.{$connName}.host", '127.0.0.1');
        $port = (string) config("database.connections.{$connName}.port", '3306');
        $user = (string) config("database.connections.{$connName}.username", 'root');
        $pass = (string) config("database.connections.{$connName}.password", '');
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$tempDb}`");

        $proof = [
            'backup_id'    => $backupId,
            'temp_db_used' => $tempDb,
            'verified_at'  => gmdate('c'),
            'passed'       => empty($mismatches),
            'tables_checked' => count($manifest['tables']),
            'mismatches'   => $mismatches,
        ];

        File::put(
            storage_path("app/private/backups/{$backupId}/restore_proof.json"),
            json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $proof;
    }

    private function insertBatchPdo(PDO $pdo, string $table, array $rows): void
    {
        if (empty($rows)) return;
        $cols = array_keys($rows[0]);
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $rowPlaceholders = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $rowPlaceholders));

        $bindings = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $bindings[] = $r[$c];
            }
        }

        $stmt = $pdo->prepare("INSERT INTO `{$table}` ({$colList}) VALUES {$allPlaceholders}");
        $stmt->execute($bindings);
    }
}
