<?php

namespace Tests\Feature\Migration;

use App\Models\Page;
use App\Models\Post;
use Tests\TestCase;

class InternalLinksTest extends TestCase
{
    public function test_zero_misutech_brand_or_domain_leaks_on_frontend_pages_and_posts(): void
    {
        $pathsToAudit = [
            '/',
            '/gioi-thieu/',
            '/lien-he/',
            '/tin-blog/',
            '/tin-blog/page/2/',
            '/tu-dong-hoa/',
            '/tai-lieu-ki-thuat/',
            '/tai-lieu-ky-thuat/',
            '/bang-gia-san-pham/',
            '/thiet-bi-dien/',
            '/huong-dan/',
            '/tag/cam-bien/',
            '/cam-bien-tu-la-gi-cau-tao-nguyen-ly-va-ung-dung/',
            '/download-cx-designer-omron/',
            '/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/',
        ];

        foreach ($pathsToAudit as $path) {
            $res = $this->get($path);
            $res->assertStatus(200);
            $html = $res->getContent();

            // Strip CSS class attributes / IDs (internal stylesheet selectors misutech_*) before checking visible text/links/meta
            $strippedSelectors = preg_replace('/\b(?:class|id)\s*=\s*["\'][^"\']*["\']/i', '', $html) ?? $html;
            $strippedAssets = preg_replace('#/clients/(?:css|js)/[^\s"\'<>]+#i', '', $strippedSelectors) ?? $strippedSelectors;
            // Also strip SVG symbol reference #misutech_home_sprite_source
            $strippedSvgRef = str_ireplace('#misutech_home_sprite_source', '', $strippedAssets);

            $this->assertStringNotContainsStringIgnoringCase(
                'misutech.vn',
                $strippedSvgRef,
                "Domain leak 'misutech.vn' detected on [{$path}]"
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'Công ty cổ phần Misutech',
                $strippedSvgRef,
                "Company name leak 'Công ty cổ phần Misutech' detected on [{$path}]"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bMISUTECH\b/i',
                $strippedSvgRef,
                "Brand text leak 'MISUTECH' detected on [{$path}]"
            );
        }
    }

    public function test_internal_links_in_header_footer_and_content_do_not_produce_500_or_unexpected_404(): void
    {
        $res = $this->get('/');
        $res->assertStatus(200);
        $html = $res->getContent();

        preg_match_all('/\bhref\s*=\s*["\'](\/[^"\'#?]*)["\']/i', $html, $m);
        $internalPaths = array_values(array_unique($m[1] ?? []));
        $this->assertNotEmpty($internalPaths);

        foreach ($internalPaths as $hrefPath) {
            $linkRes = $this->get($hrefPath);
            $status = $linkRes->getStatusCode();
            $this->assertLessThan(500, $status, "Internal link [{$hrefPath}] on homepage returned 5xx!");
            $this->assertIn($status, [200, 301, 302], "Broken internal link [{$hrefPath}] on homepage (HTTP {$status})");
        }

        // Sample internal content links across 25 posts + all pages
        $tested = [];
        $entries = Page::where('status', 'published')->pluck('content')
            ->concat(Post::where('status', 'published')->limit(25)->pluck('content'));

        foreach ($entries as $content) {
            if (!preg_match_all('/\bhref\s*=\s*["\'](\/[^"\'#?]*)["\']/i', (string) $content, $cm)) {
                continue;
            }
            foreach ($cm[1] as $p) {
                if (isset($tested[$p]) || str_starts_with($p, '/wp-content/uploads/')) {
                    continue;
                }
                $tested[$p] = true;
                $r = $this->get($p);
                $st = $r->getStatusCode();
                $this->assertLessThan(500, $st, "Content internal link [{$p}] caused HTTP {$st}");
            }
        }
    }

    public function test_owner_approved_anomalies_and_unified_contacts_are_clean_across_staging(): void
    {
        // 1. Privacy policy typo fixed to valid mailto
        $policyHtml = $this->get('/chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy/')->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('haiphongtech,vn', $policyHtml);
        $this->assertStringContainsString('href="mailto:kinhdoanhhpt@haiphongtech.vn"', $policyHtml);

        // 2. Localhost 127.0.0.1:8000/plc link unwrapped while keeping 'PLC' text
        $postHtml = $this->get('/so-do-chan-ro-le-trung-gian-8-chan/')->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('127.0.0.1:8000', $postHtml);
        $this->assertStringContainsString('PLC', $postHtml);

        // 3. Contact phone/email unified in visible <body> content to 0866 555 212 & kinhdoanhhpt@haiphongtech.vn
        //    (<head> meta_description remains frozen to seo_baseline.csv for strict RankMath SEO parity)
        $tbdFull = $this->get('/thiet-bi-dien/')->assertStatus(200)->getContent();
        $tbdBody = preg_match('#<body\b[^>]*>(.*)</body>#is', $tbdFull, $bm1) ? $bm1[1] : $tbdFull;
        $this->assertStringNotContainsString('doduchieu.hp@gmail.com', $tbdBody);
        $this->assertStringNotContainsString('038 2941 465', $tbdBody);
        $this->assertStringNotContainsString('0382941465', $tbdBody);
        $this->assertStringContainsString('0866 555 212', $tbdBody);
        $this->assertStringContainsString('kinhdoanhhpt@haiphongtech.vn', $tbdBody);

        $contactFull = $this->get('/lien-he/')->assertStatus(200)->getContent();
        $contactBody = preg_match('#<body\b[^>]*>(.*)</body>#is', $contactFull, $bm2) ? $bm2[1] : $contactFull;
        $this->assertStringNotContainsString('038 683 6466', $contactBody);
        $this->assertStringNotContainsString('info@haiphongtech.vn', $contactBody);
        $this->assertStringContainsString('0866 555 212', $contactBody);
        $this->assertStringContainsString('kinhdoanhhpt@haiphongtech.vn', $contactBody);

        // 4. Error layout de-branded from misutech_error_page to hpt_error_page
        $notFoundHtml = $this->get('/duong-dan-khong-ton-tai-xyz-999/')->assertStatus(404)->getContent();
        $this->assertStringContainsString('hpt_error_page', $notFoundHtml);
        $this->assertStringNotContainsString('misutech_error_page', $notFoundHtml);
    }

    public function test_zero_misutech_leaks_and_zero_danh_muc_links_on_phase2_and_secondary_pages(): void
    {
        $paths = [
            '/shop/',
            '/autonics/',
            '/acb-abb/',
            '/thuong_hieu/abb/',
            '/tag-san-pham/autonics/',
            '/product/mhmf082c1v1m/',
            '/thuong-hieu',
            '/series',
            '/gio-hang',
            '/documents',
            '/bao-gia',
        ];

        foreach ($paths as $path) {
            $res = $this->get($path);
            $res->assertStatus(200);
            $html = $res->getContent();

            $this->assertStringNotContainsString('MISU-', $html, "Legacy SKU prefix MISU- leaked on [{$path}]");
            $this->assertStringNotContainsString('/danh-muc/', $html, "Legacy /danh-muc/ URL leaked on [{$path}]");

            $strippedSelectors = preg_replace('/\b(?:class|id)\s*=\s*["\'][^"\']*["\']/i', '', $html) ?? $html;
            $strippedAssets = preg_replace('#/clients/(?:css|js)/[^\s"\'<>]+#i', '', $strippedSelectors) ?? $strippedSelectors;
            $strippedSvgRef = str_ireplace('#misutech_home_sprite_source', '', $strippedAssets);

            $this->assertStringNotContainsStringIgnoringCase('misutech.vn', $strippedSvgRef, "Domain leak on [{$path}]");
            $this->assertStringNotContainsStringIgnoringCase('Công ty cổ phần Misutech', $strippedSvgRef, "Company leak on [{$path}]");
            $this->assertDoesNotMatchRegularExpression('/\bMISUTECH\b/i', $strippedSvgRef, "Brand leak on [{$path}]");
        }
    }

    private function assertIn(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertTrue(in_array($needle, $haystack, true), $message);
    }
}
