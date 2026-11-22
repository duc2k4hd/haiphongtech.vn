<?php

namespace Tests\Feature\Migration;

use App\Models\Tag;
use Tests\TestCase;

class UrlParityTest extends TestCase
{
    public function test_all_active_phase1_urls_match_expected_http_status_and_redirects(): void
    {
        $csvPath = base_path('docs/migration/data/source_urls.csv');
        $this->assertFileExists($csvPath);

        $fp = fopen($csvPath, 'r');
        $header = fgetcsv($fp);

        $checkedCount = 0;
        while (($row = fgetcsv($fp)) !== false) {
            $sourceUrl = $row[0];
            $type = $row[1];
            $origStatus = $row[6] !== '' ? (int) $row[6] : null;
            $finalUrl = $row[8];

            // Skip Phase-2 unprobed bulk REST entries here (tested in RouteCollisionTest & WpParityAuditCommand)
            if (in_array($type, ['post_tag', 'reserved_product_cat', 'reserved_product_brand'], true)) {
                continue;
            }

            $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
            $query = (string) parse_url($sourceUrl, PHP_URL_QUERY);
            $requestUri = ($path !== '' ? $path : '/') . ($query !== '' ? "?{$query}" : '');

            $response = $this->get($requestUri);
            $status = $response->getStatusCode();

            // Never allow 5xx server error on any URL
            $this->assertLessThan(500, $status, "Server 5xx error on [{$requestUri}] ({$type})");

            if ($type === 'trailing_slash_probe') {
                $this->assertSame(301, $status, "Expected 301 trailing-slash redirect on [{$requestUri}]");
                $location = (string) $response->headers->get('Location');
                $this->assertTrue(str_ends_with($location, '/'), "Expected Location [{$location}] to end with '/' for [{$requestUri}]");
                $checkedCount++;
                continue;
            }

            if (in_array($type, [
                'product_shop_boundary',
                'product_shop_pagination_boundary',
                'product_cat_boundary',
                'product_single_boundary',
            ], true) || ($type === 'page' && $path === '/shop/')) {
                if ($type === 'product_single_boundary') {
                    $prodSlug = trim(substr($path, strlen('/product/')), '/');
                    if ($prodSlug !== '' && \App\Models\Product::published()->where('slug', $prodSlug)->exists()) {
                        $this->assertSame(200, $status, "Expected 200 OK on imported Phase 2 product [{$requestUri}]");
                        $checkedCount++;
                        continue;
                    }
                } else {
                    $isPhase2Active = \App\Models\Category::where('type', 'product')->exists();
                    if ($isPhase2Active) {
                        $this->assertSame(200, $status, "Expected 200 OK on active Phase 2 boundary [{$requestUri}]");
                        $checkedCount++;
                        continue;
                    }
                }

                $this->assertSame(404, $status, "Reserved Phase 2 boundary [{$requestUri}] must not fake 200");
                $this->assertSame(
                    'RESERVED_PHASE2_PRODUCT_BLOCKER',
                    $response->headers->get('X-Migration-Route-Status'),
                    "Missing RESERVED_PHASE2_PRODUCT_BLOCKER header on [{$requestUri}]"
                );
                $checkedCount++;
                continue;
            }

            // Check if this post is one of the 6 colliding product_cat slugs
            $collidingSlugs = [
                '/cam-bien-quang-autonics/',
                '/cam-bien-omron/',
                '/cam-bien-laser-keyence/',
                '/cam-bien-keyence/',
                '/cam-bien-quang-omron/',
                '/cam-bien-vung-autonics/',
            ];
            if ($type === 'post' && in_array($path, $collidingSlugs, true)) {
                $this->assertSame(200, $status);
                $checkedCount++;
                continue;
            }

            if ($type === 'post' && $response->headers->get('X-Migration-Route-Status') === 'RESERVED_PHASE2_PRODUCT_BLOCKER') {
                $this->assertSame(404, $status);
                $checkedCount++;
                continue;
            }

            if (in_array($origStatus, [301, 302], true)) {
                $this->assertSame($origStatus, $status, "Expected redirect {$origStatus} on [{$requestUri}]");
                $expectedTargetPath = (string) parse_url($finalUrl, PHP_URL_PATH) ?: '/';
                $actualLocation = (string) $response->headers->get('Location');
                $actualLocPath = (string) parse_url($actualLocation, PHP_URL_PATH) ?: '/';
                $this->assertSame($expectedTargetPath, $actualLocPath, "Redirect target mismatch on [{$requestUri}]");
                $checkedCount++;
                continue;
            }

            $this->assertSame(200, $status, "Expected 200 OK on Phase 1 URL [{$requestUri}] ({$type})");
            $body = $response->getContent();
            $this->assertNotEmpty($body, "Empty body (soft-404 risk) on [{$requestUri}]");
            $checkedCount++;
        }
        fclose($fp);

        $this->assertGreaterThanOrEqual(275, $checkedCount);
    }

    public function test_stratified_sample_of_post_tags_returns_200_and_trailing_slash(): void
    {
        $tags = Tag::orderByDesc('posts_count_source')->limit(25)->get();
        $this->assertNotEmpty($tags);

        foreach ($tags as $tag) {
            $res = $this->get("/tag/{$tag->slug}/");
            $res->assertStatus(200);
        }
    }
}
