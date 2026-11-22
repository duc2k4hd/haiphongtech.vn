<?php

namespace Tests\Feature\Migration;

use Tests\TestCase;

class RouteCollisionTest extends TestCase
{
    public function test_all_six_exact_root_collisions_reserve_url_for_product_cat_and_do_not_leak_shadowed_post(): void
    {
        $collidingPaths = [
            '/cam-bien-quang-autonics/',
            '/cam-bien-omron/',
            '/cam-bien-laser-keyence/',
            '/cam-bien-keyence/',
            '/cam-bien-quang-omron/',
            '/cam-bien-vung-autonics/',
        ];

        foreach ($collidingPaths as $path) {
            $res = $this->get($path);
            if (\App\Models\Category::where('type', 'product')->count() > 0) {
                $res->assertStatus(200);
                // Must render category archive, never shadowed post template or single blog
                $this->assertStringNotContainsString('misutech_single_blog', $res->getContent());
            } else {
                $res->assertStatus(404);
                $this->assertSame(
                    'RESERVED_PHASE2_PRODUCT_BLOCKER',
                    $res->headers->get('X-Migration-Route-Status'),
                    "Colliding root path [{$path}] must be reserved for WooCommerce product_cat, NOT hijacked by shadowed post!"
                );
            }
        }
    }

    public function test_cross_namespace_slug_pairs_resolve_independently_without_collision(): void
    {
        // Post at root vs Post Tag under /tag/
        $postRes = $this->get('/cam-bien-tu-la-gi/');
        $postRes->assertStatus(200);
        $this->assertStringNotContainsString('noindex', $this->extractMetaRobots($postRes->getContent()));

        $tagRes = $this->get('/tag/cam-bien-tu-la-gi/');
        $tagRes->assertStatus(200);
        $this->assertStringContainsString('noindex, follow', $this->extractMetaRobots($tagRes->getContent()));

        // Near-identical slugs: Page /tai-lieu-ki-thuat/ vs Post Category /tai-lieu-ky-thuat/
        $pageRes = $this->get('/tai-lieu-ki-thuat/');
        $pageRes->assertStatus(200);
        $this->assertStringContainsString('Tài liệu kĩ thuật - Hải Phòng Tech', $pageRes->getContent());

        $catRes = $this->get('/tai-lieu-ky-thuat/');
        $catRes->assertStatus(200);
        $this->assertStringContainsString('Tài liệu kỹ thuật - Hải Phòng Tech', $catRes->getContent());
    }

    public function test_product_shop_brand_pagination_querystrings_and_trailing_slashes_behave_accurately(): void
    {
        // Unslashed -> 301 slashed preserving query string
        $unslashed = $this->get('/tin-blog?q=Omron');
        $unslashed->assertStatus(301);
        $this->assertSame('http://localhost:8000/tin-blog/?q=Omron', $unslashed->headers->get('Location'));

        $slashedWithQuery = $this->get('/tin-blog/?q=Omron');
        $slashedWithQuery->assertStatus(200);

        // /tin-blog/page/1/ -> 301 /tin-blog/
        $page1 = $this->get('/tin-blog/page/1/');
        $page1->assertStatus(301);
        $this->assertSame('http://localhost:8000/tin-blog/', $page1->headers->get('Location'));

        // /tin-blog/page/2/ -> 200
        $page2 = $this->get('/tin-blog/page/2/');
        $page2->assertStatus(200);

        // Page 1 canonical redirects -> 301 root archive (preserving query string)
        $this->get('/shop/page/1/')->assertStatus(301);
        $this->assertSame('http://localhost:8000/shop/', $this->get('/shop/page/1/')->headers->get('Location'));
        $this->get('/autonics/page/1/')->assertStatus(301);
        $this->assertSame('http://localhost:8000/autonics/', $this->get('/autonics/page/1/')->headers->get('Location'));
        $this->get('/thuong_hieu/abb/page/1/')->assertStatus(301);
        $this->assertSame('http://localhost:8000/thuong_hieu/abb/', $this->get('/thuong_hieu/abb/page/1/')->headers->get('Location'));
        $this->get('/tag-san-pham/autonics/page/1/')->assertStatus(301);
        $this->assertSame('http://localhost:8000/tag-san-pham/autonics/', $this->get('/tag-san-pham/autonics/page/1/')->headers->get('Location'));
        $this->get('/tag-san-pham/autonics')->assertStatus(301);
        $this->assertSame('http://localhost:8000/tag-san-pham/autonics/', $this->get('/tag-san-pham/autonics')->headers->get('Location'));

        // Active Phase 2 categories, shop, brands, and product tags return 200 with paginated non-overlapping slices
        $archivePairs = [
            ['/shop/', '/shop/page/2/'],
            ['/autonics/', '/autonics/page/2/'],
            ['/thuong_hieu/abb/', '/thuong_hieu/abb/page/2/'],
            ['/tag-san-pham/autonics/', '/tag-san-pham/autonics/page/2/'],
        ];
        foreach ($archivePairs as [$p1Path, $p2Path]) {
            $r1 = $this->get($p1Path);
            $r2 = $this->get($p2Path);
            $r1->assertStatus(200);
            $r2->assertStatus(200);

            $cleanHtml1 = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $r1->getContent()) ?? $r1->getContent();
            $cleanHtml2 = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $r2->getContent()) ?? $r2->getContent();

            preg_match_all('#/product/([^/"\'\?\#]+)/#i', $cleanHtml1, $m1);
            preg_match_all('#/product/([^/"\'\?\#]+)/#i', $cleanHtml2, $m2);
            $slugs1 = array_values(array_unique($m1[1] ?? []));
            $slugs2 = array_values(array_unique($m2[1] ?? []));

            $this->assertNotEmpty($slugs1, "Page 1 [{$p1Path}] should list products");
            $this->assertNotEmpty($slugs2, "Page 2 [{$p2Path}] should list products");
            $this->assertSame([], array_values(array_intersect($slugs1, $slugs2)), "Page 1 and Page 2 on [{$p1Path}] must return disjoint product slices");

            // Paginated pages must emit noindex, follow and omit canonical per RankMath
            $this->assertSame('noindex, follow', $this->extractMetaRobots($r2->getContent()), "Missing noindex, follow on [{$p2Path}]");
            $this->assertDoesNotMatchRegularExpression('/<link\s+rel=["\']canonical["\']/i', $r2->getContent(), "Paginated archive [{$p2Path}] must omit canonical");
        }

        // product_tag page 1 must also be noindex, follow without canonical
        $tagP1Html = $this->get('/tag-san-pham/autonics/')->getContent();
        $this->assertSame('noindex, follow', $this->extractMetaRobots($tagP1Html));
        $this->assertDoesNotMatchRegularExpression('/<link\s+rel=["\']canonical["\']/i', $tagP1Html);

        // Out-of-range pagination on active Phase 2 archives -> 404
        foreach (['/shop/page/9999/', '/thuong_hieu/abb/page/999/', '/autonics/page/999/'] as $oobPath) {
            $this->get($oobPath)->assertStatus(404);
        }

        // Previously-reserved product boundary slugs are now active (200 OK) after full 133k SQL import
        foreach (['/product/ee-sx674/', '/product/ca-lh4/'] as $activeBoundaryProd) {
            $this->get($activeBoundaryProd)->assertStatus(200);
        }

        // Unmigrated nonexistent product / product_tag boundaries keep 404 blocker
        foreach ([
            '/product/chua-import-999999/',
            '/tag-san-pham/chua-import-999999/',
            '/tag-san-pham/chua-import-999999/page/2/',
        ] as $reserved) {
            $r = $this->get($reserved);
            $r->assertStatus(404);
            $this->assertSame('RESERVED_PHASE2_PRODUCT_BLOCKER', $r->headers->get('X-Migration-Route-Status'));
        }

        // Unicode & percent-encoded WordPress slugs (cosφ = cos%cf%86, 2.5mm² = 2-5mm%c2%b2)
        $this->get('/tag/cos%cf%86/')->assertStatus(200);
        $this->get('/day-dien-cadivi-2-5-cv-2-5mm%c2%b2/')->assertStatus(200);
    }

    private function extractMetaRobots(string $html): string
    {
        if (preg_match('/<meta\s+name=["\']robots["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }
        return '';
    }
}
