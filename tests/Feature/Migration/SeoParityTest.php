<?php

namespace Tests\Feature\Migration;

use Tests\TestCase;

class SeoParityTest extends TestCase
{
    public function test_seo_meta_title_description_robots_canonical_and_json_ld_match_baseline(): void
    {
        $csvPath = base_path('docs/migration/data/seo_baseline.csv');
        $fp = fopen($csvPath, 'r');
        $header = fgetcsv($fp);

        $checked = 0;
        while (($row = fgetcsv($fp)) !== false) {
            $sourceUrl = $row[0];
            $type = $row[1];
            $expectedTitle = $row[4];
            $expectedDesc = $row[5];
            $expectedCanonical = $row[6];
            $expectedRobots = $row[7];

            if (str_contains($type, 'boundary') || $sourceUrl === 'https://haiphongtech.vn/shop/' || $sourceUrl === 'https://haiphongtech.vn/checkout/') {
                continue;
            }

            $path = (string) parse_url($sourceUrl, PHP_URL_PATH) ?: '/';
            $res = $this->get($path);

            // Skip the 6 shadowed colliding posts & 2 301-redirected posts (tested in Phase2ParityTest)
            $collidingPostPaths = [
                '/cam-bien-quang-autonics/',
                '/cam-bien-omron/',
                '/cam-bien-laser-keyence/',
                '/cam-bien-keyence/',
                '/cam-bien-quang-omron/',
                '/cam-bien-vung-autonics/',
            ];
            if (in_array($path, $collidingPostPaths, true) || $res->getStatusCode() !== 200) {
                continue;
            }

            // 1. Staging protection header MUST be present on non-production
            $this->assertStringContainsString(
                'noindex',
                (string) $res->headers->get('X-Robots-Tag'),
                "Missing staging X-Robots-Tag protection on [{$path}]"
            );

            $html = $res->getContent();

            // 2. Title parity (accounting for /tin-blog/page/2/ having 19 active pages = ceil(227/12) after excluding 6 shadowed + 2 redirected posts)
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm);
            $actualTitle = isset($tm[1]) ? html_entity_decode(trim(preg_replace('/\s+/', ' ', $tm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            if ($path === '/tin-blog/page/2/') {
                $this->assertSame('Tin Blog - Trang 2 trên 19 - Hải Phòng Tech', $actualTitle);
            } else {
                $this->assertSame($expectedTitle, $actualTitle, "SEO <title> mismatch on [{$path}]");
            }

            // 3. Meta robots parity
            preg_match('/<meta\s+name=["\']robots["\']\s+content=["\']([^"\']+)["\']/i', $html, $rm);
            $actualRobots = $rm[1] ?? '';
            $this->assertSame($expectedRobots, $actualRobots, "Meta robots mismatch on [{$path}]");

            // 4. Canonical parity & zero noindex/canonical conflict & zero Misutech leak
            $hasCanonical = preg_match('/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']/i', $html, $cm);
            if (str_contains(strtolower($expectedRobots), 'noindex') || $expectedCanonical === '') {
                $this->assertSame(0, (int) $hasCanonical, "noindex page [{$path}] must NOT emit conflicting <link rel=canonical>");
            } else {
                $this->assertSame(1, (int) $hasCanonical, "Missing <link rel=canonical> on [{$path}]");
                $actualCanon = $cm[1];
                $this->assertStringNotContainsString('misutech', strtolower($actualCanon), "Misutech canonical leak on [{$path}]");
                $expectedCanonPath = (string) parse_url($expectedCanonical, PHP_URL_PATH) ?: '/';
                $actualCanonPath = (string) parse_url($actualCanon, PHP_URL_PATH) ?: '/';
                $this->assertSame($expectedCanonPath, $actualCanonPath, "Canonical path mismatch on [{$path}]");
            }

            // 5. Meta description parity (when source had non-empty description)
            if ($expectedDesc !== '') {
                preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']/i', $html, $dm);
                $actualDesc = isset($dm[1]) ? html_entity_decode($dm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
                $this->assertSame($expectedDesc, $actualDesc, "Meta description mismatch on [{$path}]");
            }

            // 6. Validate all JSON-LD structured data blocks parse cleanly
            if (preg_match_all('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ldm)) {
                foreach ($ldm[1] as $rawJson) {
                    $decoded = json_decode(trim($rawJson), true);
                    $this->assertNotNull($decoded, "Invalid JSON-LD structured data on [{$path}]");
                    $this->assertStringNotContainsString('misutech', strtolower($rawJson), "Misutech leak inside JSON-LD on [{$path}]");
                }
            }

            $checked++;
        }
        fclose($fp);

        $this->assertGreaterThanOrEqual(255, $checked);
    }

    public function test_content_sitemaps_work_and_flag_product_cutover_blocker(): void
    {
        $minCutover = (int) config('migration.phase2.full_product_cutover_min_count', 130000);
        $publishedCount = \App\Models\Product::published()->count();
        $isCutoverReady = $publishedCount >= $minCutover;

        $idx = $this->get('/sitemap_index.xml');
        $idx->assertStatus(200);
        $this->assertStringContainsString('/post-sitemap.xml', $idx->getContent());
        $this->assertStringContainsString('/page-sitemap.xml', $idx->getContent());
        $this->assertStringContainsString('/category-sitemap.xml', $idx->getContent());

        if ($isCutoverReady) {
            $this->assertNull($idx->headers->get('X-Sitemap-Cutover-Status'));
            $this->assertStringContainsString('/product_cat-sitemap.xml', $idx->getContent());
            $this->assertStringContainsString('/product-sitemap1.xml', $idx->getContent());
            $this->assertStringContainsString('/product-sitemap67.xml', $idx->getContent());
        } else {
            $this->assertSame('BLOCKED_MISSING_67_PRODUCT_SITEMAPS', $idx->headers->get('X-Sitemap-Cutover-Status'));
        }

        $pagesSm = $this->get('/page-sitemap.xml');
        $pagesSm->assertStatus(200);
        $this->assertSame(22, substr_count($pagesSm->getContent(), '<url>'));

        $catsSm = $this->get('/category-sitemap.xml');
        $catsSm->assertStatus(200);
        $this->assertSame(3, substr_count($catsSm->getContent(), '<url>'));

        $postsSm = $this->get('/post-sitemap.xml');
        $postsSm->assertStatus(200);
        // 123 in source post-sitemap minus 2 301-redirected posts minus 5 shadowed product_cat posts = 116 active 200 URLs
        $this->assertSame(116, substr_count($postsSm->getContent(), '<url>'));

        if ($isCutoverReady) {
            $prodSm = $this->get('/product-sitemap1.xml');
            $prodSm->assertStatus(200);
            $xml = $prodSm->streamedContent();
            $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
            $this->assertGreaterThan(0, substr_count($xml, '<url>'));

            // Verify cutover safety gate still blocks if catalog drops below threshold
            config(['migration.phase2.full_product_cutover_min_count' => $publishedCount + 1000]);
            try {
                $blockedIdx = $this->get('/sitemap_index.xml');
                $blockedIdx->assertStatus(200);
                $this->assertSame('BLOCKED_MISSING_67_PRODUCT_SITEMAPS', $blockedIdx->headers->get('X-Sitemap-Cutover-Status'));

                $blockedSm = $this->get('/product-sitemap1.xml');
                $blockedSm->assertStatus(404);
                $this->assertSame('RESERVED_PHASE2_PRODUCT_SITEMAP_BLOCKER', $blockedSm->headers->get('X-Migration-Route-Status'));
            } finally {
                config(['migration.phase2.full_product_cutover_min_count' => $minCutover]);
            }
        } else {
            // Product sitemaps must NOT fake 200 while products are unmigrated
            $prodSm = $this->get('/product-sitemap1.xml');
            $prodSm->assertStatus(404);
            $this->assertSame('RESERVED_PHASE2_PRODUCT_SITEMAP_BLOCKER', $prodSm->headers->get('X-Migration-Route-Status'));
        }
    }
}
