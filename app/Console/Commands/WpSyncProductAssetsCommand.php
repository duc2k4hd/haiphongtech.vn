<?php

namespace App\Console\Commands;

use App\Services\Migration\StagingBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WpSyncProductAssetsCommand extends Command
{
    protected $signature = 'wp:sync-product-assets
        {--type=catalogs : Loại tài nguyên tải: catalogs (tài liệu PDF/ZIP dùng chung chỉ 1 file) | taxonomy (icon danh mục & logo hãng) | images (ảnh SP/mô tả/danh mục/hãng) | all}
        {--concurrency=12 : Số luồng tải đồng thời (curl_multi)}
        {--limit=0 : Giới hạn số tệp tải mỗi lượt (0 = không giới hạn)}
        {--dry-run : Chỉ thống kê số tệp cần tải mà không download}';

    protected $description = 'Tải đồng thời ảnh sản phẩm và tài liệu catalog (đã chống trùng lặp & bỏ link Google Drive) về staging disk';

    public function handle(StagingBackupService $backupService): int
    {
        $backupService->assertSafeStagingTarget();

        $type = strtolower((string) $this->option('type'));
        $concurrency = max(1, min(32, (int) $this->option('concurrency')));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('media')
            ->where('disk', 'wp_uploads')
            ->where('status', 'active')
            ->whereNotNull('wp_relative_path')
            ->select(['id', 'wp_relative_path', 'wp_source_url', 'mime_type', 'sha256', 'size']);

        if ($type === 'catalogs') {
            $query->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('product_media')
                    ->whereColumn('product_media.media_id', 'media.id')
                    ->where('product_media.role', 'catalog');
            });
        } elseif ($type === 'taxonomy') {
            $rels = collect(DB::table('categories')->where('type', 'product')->whereNotNull('icon')->pluck('icon'))
                ->merge(DB::table('brands')->whereNotNull('logo')->pluck('logo'))
                ->map(static fn ($p) => ltrim((string) preg_replace('#^/?wp-content/uploads/#', '', (string) $p), '/'))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if (empty($rels)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('wp_relative_path', $rels);
            }
        } elseif ($type === 'images') {
            $query->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('product_media')
                    ->whereColumn('product_media.media_id', 'media.id')
                    ->where('product_media.role', 'catalog');
            });
        }

        $totalRows = 0;
        $alreadyOnDisk = 0;
        $queue = [];

        foreach ($query->orderBy('id')->cursor() as $row) {
            $totalRows++;
            $rel = ltrim((string) $row->wp_relative_path, '/');
            if ($rel === '') continue;

            $dest = public_path('wp-content/uploads/' . $rel);
            if (is_file($dest) && filesize($dest) > 0) {
                $alreadyOnDisk++;
                continue;
            }

            $url = !empty($row->wp_source_url)
                ? (string) $row->wp_source_url
                : ('https://haiphongtech.vn/wp-content/uploads/' . implode('/', array_map('rawurlencode', explode('/', $rel))));

            $queue[] = [
                'id'   => (int) $row->id,
                'rel'  => $rel,
                'dest' => $dest,
                'url'  => $this->encodeUrlPath($url),
            ];
            if ($limit > 0 && count($queue) >= $limit) {
                break;
            }
        }

        $queuedCount = count($queue);
        $this->info("Type={$type} | Total={$totalRows} | Already on disk={$alreadyOnDisk} | Queued={$queuedCount}");

        if ($dryRun || $queuedCount === 0) {
            return self::SUCCESS;
        }

        $downloaded = 0;
        $missing404 = 0;
        $failed = 0;
        $processed = 0;

        foreach (array_chunk($queue, 200) as $batch) {
            $results = $this->downloadConcurrentBatch($batch, $concurrency);

            foreach ($results as $res) {
                $processed++;
                if ($res['ok']) {
                    $downloaded++;
                    DB::table('media')->where('id', $res['id'])->update([
                        'sha256'    => $res['sha256'],
                        'checksum'  => $res['md5'],
                        'size'      => $res['size'],
                        'mime_type' => $res['mime'] ?: DB::raw('mime_type'),
                        'status'    => 'active',
                        'updated_at'=> now('UTC'),
                    ]);
                } elseif ($res['code'] === 404 || $res['code'] === 410) {
                    $missing404++;
                    DB::table('media')->where('id', $res['id'])->update([
                        'status'     => 'missing',
                        'notes'      => 'SOURCE_HTTP_' . $res['code'],
                        'updated_at' => now('UTC'),
                    ]);
                } else {
                    $failed++;
                }
            }

            $this->line("  Progress: {$processed}/{$queuedCount} | downloaded={$downloaded}, 404={$missing404}, err={$failed}");
        }

        $this->info("Sync completed: downloaded={$downloaded}, 404_marked_missing={$missing404}, errors={$failed}, already_on_disk={$alreadyOnDisk}");
        return self::SUCCESS;
    }

    private function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $path = $parts['path'] ?? '';
        $segs = array_map(fn($s) => rawurlencode(rawurldecode($s)), explode('/', $path));
        return $parts['scheme'] . '://' . $parts['host']
            . (!empty($parts['port']) ? ':' . $parts['port'] : '')
            . implode('/', $segs)
            . (!empty($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function downloadConcurrentBatch(array $items, int $concurrency): array
    {
        $mh = curl_multi_init();
        $activeHandles = [];
        $idx = 0;
        $total = count($items);
        $out = [];

        $addHandle = function (array $item) use ($mh, &$activeHandles) {
            $dir = dirname($item['dest']);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $ch = curl_init($item['url']);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_USERAGENT      => 'HaiPhongTechMigrationSync/2.0 (+https://haiphongtech.vn)',
            ];
            if (defined('CURLSSLOPT_NATIVE_CA')) {
                $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $activeHandles[spl_object_id($ch)] = $item;
        };

        while ($idx < $total && count($activeHandles) < $concurrency) {
            $addHandle($items[$idx++]);
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.25);
            }

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $hid = spl_object_id($ch);
                $item = $activeHandles[$hid];
                unset($activeHandles[$hid]);

                $bytes = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $ct = trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($bytes !== false && $code === 200 && strlen($bytes) > 0) {
                    $tmp = $item['dest'] . '.part';
                    file_put_contents($tmp, $bytes);
                    @rename($tmp, $item['dest']);
                    $out[] = [
                        'id'     => $item['id'],
                        'ok'     => true,
                        'code'   => 200,
                        'sha256' => hash('sha256', $bytes),
                        'md5'    => md5($bytes),
                        'size'   => strlen($bytes),
                        'mime'   => $ct ?: null,
                    ];
                } else {
                    $out[] = [
                        'id'   => $item['id'],
                        'ok'   => false,
                        'code' => $code,
                    ];
                }

                if ($idx < $total) {
                    $addHandle($items[$idx++]);
                }
            }
        } while ($running > 0 || !empty($activeHandles));

        curl_multi_close($mh);
        return $out;
    }
}
