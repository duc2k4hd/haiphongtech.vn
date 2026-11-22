<?php

namespace Tests\Feature\Migration;

use App\Models\Category;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\SourceUrlRoute;
use App\Models\Tag;
use App\Services\Migration\WpPhase1Importer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetryIdempotencyTest extends TestCase
{
    public function test_second_import_run_produces_zero_duplicates_and_exact_skip_counts(): void
    {
        $beforeCounts = [
            'pages'      => Page::count(),
            'posts'      => Post::count(),
            'categories' => Category::where('type', 'post')->count(),
            'tags'       => Tag::count(),
            'media'      => Media::where('disk', 'wp_uploads')->count(),
            'routes'     => SourceUrlRoute::count(),
        ];

        $importer = app(WpPhase1Importer::class);
        $report = $importer->run('snap_20261118_phase1', false);

        $this->assertSame(0, $report['totals']['inserts'], 'Re-run must insert 0 rows');
        $this->assertSame(0, $report['totals']['updates'], 'Re-run must update 0 rows');
        $this->assertSame(9661, $report['totals']['skips'], 'Re-run must skip all 9,661 unchanged records');
        $this->assertSame(0, $report['totals']['errors']);

        $afterCounts = [
            'pages'      => Page::count(),
            'posts'      => Post::count(),
            'categories' => Category::where('type', 'post')->count(),
            'tags'       => Tag::count(),
            'media'      => Media::where('disk', 'wp_uploads')->count(),
            'routes'     => SourceUrlRoute::count(),
        ];

        $this->assertSame($beforeCounts, $afterCounts);
    }

    public function test_optimistic_conflict_guard_protects_manually_edited_page_without_batch_id(): void
    {
        DB::beginTransaction();
        try {
            $page = Page::where('slug', 'gioi-thieu')->firstOrFail();
            $page->update([
                'content_hash'    => str_repeat('a', 64),
                'import_batch_id' => null,
            ]);

            $importer = app(WpPhase1Importer::class);
            $report = $importer->run('snap_20261118_phase1', false, '', false);

            $this->assertGreaterThanOrEqual(1, $report['by_group']['pages']['conflicts']);
        } finally {
            DB::rollBack();
        }
    }
}
