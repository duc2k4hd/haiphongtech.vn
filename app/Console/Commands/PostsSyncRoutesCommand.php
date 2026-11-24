<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\SourceUrlRoute;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PostsSyncRoutesCommand extends Command
{
    protected $signature = 'posts:sync-routes
        {--force : Đồng bộ tất cả bài viết kể cả trạng thái nháp}';

    protected $description = 'Đồng bộ toàn bộ bài viết trong bảng posts vào bảng source_url_routes (URL Registry) để đảm bảo không bị 404.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $query = Post::query();
        if (! $force) {
            $query->whereIn('status', ['published', 'active']);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn('Không có bài viết nào cần đồng bộ route.');

            return self::SUCCESS;
        }

        $this->info("Bắt đầu đồng bộ {$total} bài viết vào bảng source_url_routes...");
        $batchId = 'posts_sync_' . Carbon::now('Asia/Ho_Chi_Minh')->format('Ymd_His');
        $count = 0;

        $query->chunk(100, function ($posts) use (&$count, $batchId) {
            foreach ($posts as $post) {
                if (empty($post->slug)) {
                    continue;
                }

                $slug = trim($post->slug, '/');
                $normPath = '/' . $slug . '/';
                $unslashedPath = '/' . $slug;

                SourceUrlRoute::updateOrCreate(
                    ['path_hash' => hash('sha256', $normPath)],
                    [
                        'unslashed_hash' => hash('sha256', $unslashedPath),
                        'normalized_path' => $normPath,
                        'unslashed_path' => $unslashedPath,
                        'entity_type' => 'post',
                        'entity_id' => $post->id,
                        'source_wp_id' => $post->source_wp_id,
                        'http_status' => 200,
                        'trailing_slash_policy' => 'require_slash',
                        'canonical_url' => $post->canonical_url ?: $normPath,
                        'in_sitemap' => true,
                        'is_cutover_ready' => true,
                        'source_snapshot_id' => 'posts_sync',
                        'import_batch_id' => $batchId,
                    ]
                );
                $count++;
            }
        });

        $this->info("Đã đồng bộ thành công {$count} bài viết vào source_url_routes!");

        return self::SUCCESS;
    }
}
