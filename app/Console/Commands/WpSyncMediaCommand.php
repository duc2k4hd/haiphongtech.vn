<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\Migration\StagingBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class WpSyncMediaCommand extends Command
{
    protected $signature = 'wp:sync-media
        {--scope=core : Sync scope: core (logos, favicons, featured images, PDFs) | originals | phase2 | all}
        {--limit=500 : Maximum files to download in this batch}
        {--delay-ms=80 : Delay in milliseconds between network downloads}
        {--dry-run : Report how many files would be synced without downloading}';

    protected $description = 'Download and verify SHA-256 checksums for referenced /wp-content/uploads/ media onto staging disk';

    public function handle(StagingBackupService $backupService): int
    {
        $backupService->assertSafeStagingTarget();

        $scope = (string) $this->option('scope');
        $limit = max(1, (int) $this->option('limit'));
        $delayMs = max(20, (int) $this->option('delay-ms'));
        $dryRun = (bool) $this->option('dry-run');

        $manifestCsv = base_path('docs/migration/data/media_manifest.csv');
        $coreRelPaths = [];
        if (File::exists($manifestCsv)) {
            $fp = fopen($manifestCsv, 'r');
            $h = fgetcsv($fp);
            while (($r = fgetcsv($fp)) !== false) {
                $rel = trim((string) ($r[1] ?? ''));
                $mime = trim((string) ($r[3] ?? ''));
                $refs = (string) ($r[9] ?? '');
                if ($rel === '') continue;

                if ($scope === 'core') {
                    if ($mime === 'application/pdf'
                        || str_contains($refs, 'featured')
                        || str_contains($refs, 'site_icon')
                        || str_contains($refs, 'header_logo')
                        || str_contains($refs, 'og_image')
                    ) {
                        $coreRelPaths[$rel] = true;
                    }
                } elseif ($scope === 'originals') {
                    if (!preg_match('/-\d+x\d+\.[a-z0-9]+$/i', $rel)) {
                        $coreRelPaths[$rel] = true;
                    }
                } else {
                    $coreRelPaths[$rel] = true;
                }
            }
            fclose($fp);
        }

        $query = Media::where('disk', 'wp_uploads')->whereNotNull('wp_relative_path');
        if ($scope === 'phase2') {
            $catRelPaths = \Illuminate\Support\Facades\DB::table('categories')
                ->where('type', 'product')
                ->whereNotNull('icon')
                ->where('icon', 'like', '/wp-content/uploads/%')
                ->pluck('icon')
                ->map(fn($p) => ltrim(substr($p, strlen('/wp-content/uploads/')), '/'))
                ->all();

            $query->where(function ($q) use ($catRelPaths) {
                $q->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('product_media')
                        ->whereColumn('product_media.media_id', 'media.id');
                });
                if (!empty($catRelPaths)) {
                    $q->orWhereIn('wp_relative_path', $catRelPaths);
                }
            });
        } elseif ($scope !== 'all') {
            $query->whereIn('wp_relative_path', array_keys($coreRelPaths));
        }

        $items = $query->orderBy('id')->get();
        $toDownload = [];
        $alreadySynced = 0;

        foreach ($items as $media) {
            $dest = public_path('wp-content/uploads/' . ltrim($media->wp_relative_path, '/'));
            if (is_file($dest) && filesize($dest) > 0) {
                if (empty($media->sha256) && !$dryRun) {
                    $sha = hash_file('sha256', $dest);
                    $media->update([
                        'sha256'   => $sha,
                        'checksum' => md5_file($dest),
                        'size'     => filesize($dest),
                    ]);
                }
                $alreadySynced++;
                continue;
            }
            $toDownload[] = $media;
            if (count($toDownload) >= $limit) {
                break;
            }
        }

        $this->info("Scope={$scope} | Total matched={$items->count()} | Already on disk={$alreadySynced} | Queued=" . count($toDownload));
        if ($dryRun || empty($toDownload)) {
            return self::SUCCESS;
        }

        $downloaded = 0;
        $failed = 0;
        foreach ($toDownload as $idx => $media) {
            if ($idx > 0) {
                usleep($delayMs * 1000);
            }
            $url = $media->wp_source_url ?: ('https://haiphongtech.vn/wp-content/uploads/' . ltrim($media->wp_relative_path, '/'));
            $dest = public_path('wp-content/uploads/' . ltrim($media->wp_relative_path, '/'));
            $dir = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => 'HaiPhongTechMigrationAuditor/1.0 (+https://haiphongtech.vn)',
            ];
            if (defined('CURLSSLOPT_NATIVE_CA')) {
                $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
            }
            curl_setopt_array($ch, $opts);
            $bytes = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($bytes !== false && $code === 200 && strlen($bytes) > 0) {
                file_put_contents($dest, $bytes);
                $sha = hash('sha256', $bytes);
                $mimeClean = trim(explode(';', $ct)[0]);
                $media->update([
                    'sha256'    => $sha,
                    'checksum'  => md5($bytes),
                    'size'      => strlen($bytes),
                    'mime_type' => $mimeClean ?: $media->mime_type,
                    'status'    => 'active',
                ]);
                $downloaded++;
            } else {
                $failed++;
                $media->update([
                    'status' => 'missing',
                    'notes'  => "SOURCE_HTTP_{$code}",
                ]);
                $this->warn("Failed HTTP {$code}: {$url}");
            }

            if (($idx + 1) % 50 === 0) {
                $this->line("  Progress: " . ($idx + 1) . '/' . count($toDownload));
            }
        }

        $this->info("Media sync finished: downloaded={$downloaded}, failed={$failed}, previously_synced={$alreadySynced}");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
