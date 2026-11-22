<?php

namespace Tests\Feature\Migration;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    public function test_critical_pages_execute_with_bounded_query_count_and_no_n_plus_one(): void
    {
        // Warm shared caches once
        $this->get('/');

        $criticalPaths = [
            '/'                                                  => 20,
            '/gioi-thieu/'                                       => 12,
            '/lien-he/'                                          => 12,
            '/tin-blog/'                                         => 15,
            '/tin-blog/page/2/'                                  => 15,
            '/tu-dong-hoa/'                                      => 15,
            '/tag/cam-bien/'                                     => 15,
            '/cam-bien-tu-la-gi-cau-tao-nguyen-ly-va-ung-dung/'  => 18,
            '/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/'  => 12,
            '/shop/'                                             => 25,
            '/shop/page/2/'                                      => 25,
            '/autonics/'                                         => 25,
            '/thuong_hieu/abb/'                                  => 25,
            '/tag-san-pham/autonics/'                            => 25,
            '/product/mhmf082c1v1m/'                             => 25,
        ];

        foreach ($criticalPaths as $path => $maxQueries) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $start = microtime(true);
            $res = $this->get($path);
            $elapsedMs = (microtime(true) - $start) * 1000;

            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $res->assertStatus(200);
            $this->assertLessThanOrEqual(
                $maxQueries,
                count($queries),
                "Excessive DB queries (" . count($queries) . " > {$maxQueries}) on [{$path}]"
            );
            $this->assertLessThan(
                2000,
                $elapsedMs,
                "Slow response ({$elapsedMs}ms) on [{$path}]"
            );
        }
    }
}
