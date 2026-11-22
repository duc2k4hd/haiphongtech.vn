<?php

namespace Tests\Feature\Migration;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class Phase2ParityTest extends TestCase
{
    public function test_shop_and_product_categories_render_200_and_enforce_trailing_slash(): void
    {
        // 1. Shop root & trailing-slash enforcement
        $unslashedShop = $this->get('/shop');
        $unslashedShop->assertStatus(301);
        $this->assertSame('http://localhost:8000/shop/', $unslashedShop->headers->get('Location'));

        $slashedShop = $this->get('/shop/');
        $slashedShop->assertStatus(200);
        $shopHtml = $slashedShop->getContent();
        $this->assertStringContainsString('Shop - Hải Phòng Tech', $shopHtml);
        $this->assertStringContainsString('Cửa hàng', $shopHtml);

        // 2. Root category /abb/
        $unslashedCat = $this->get('/abb');
        $unslashedCat->assertStatus(301);
        $this->assertSame('http://localhost:8000/abb/', $unslashedCat->headers->get('Location'));

        $slashedCat = $this->get('/abb/');
        $slashedCat->assertStatus(200);

        // 3. Child category /acb-abb/ (flat root URL + ancestor breadcrumb)
        $childCat = $this->get('/acb-abb/');
        $childCat->assertStatus(200);
        $childHtml = $childCat->getContent();
        $this->assertStringContainsString('ACB ABB Chính Hãng', $childHtml);
        $this->assertStringContainsString('Aptomat ABB', $childHtml);
    }

    public function test_stratified_pilot_counts_media_tags_prices_and_multi_category_union_filters(): void
    {
        $this->assertSame(133036, Product::published()->count(), 'Expected all 133,036 published products from SQL dump');
        $this->assertSame(750, Category::where('type', 'product')->count());
        $this->assertSame(48, Brand::count());
        $this->assertSame(255276, ProductTag::count());

        $mediaLinks = DB::table('product_media')->count();
        $this->assertGreaterThanOrEqual(190000, $mediaLinks, 'Expected full thumbnail, gallery and catalog links populated');

        // Verify shared catalogs are deduplicated (62k+ links point to ~5,481 unique media rows) and zero Google Drive links remain
        $catalogPivotCount = DB::table('product_media')->where('role', 'catalog')->count();
        $distinctCatalogMedia = DB::table('product_media')->where('role', 'catalog')->distinct()->count('media_id');
        $this->assertGreaterThan(60000, $catalogPivotCount);
        $this->assertLessThan(7000, $distinctCatalogMedia, 'Catalog files must be deduplicated when shared across products');
        $this->assertSame(0, DB::table('media')->where('wp_source_url', 'like', '%drive.google%')->count());

        // Verify HTML sanitizer stripped classes/styles/junk attrs while keeping semantic tables, <a href>, and <img src alt>
        $cleanedSample = (string) Product::where('slug', 'e3t-fd11-2m')->value('content');
        $this->assertNotSame('', $cleanedSample);
        $this->assertStringContainsString('<table>', $cleanedSample);
        $this->assertDoesNotMatchRegularExpression('/\b(?:class|style|border|cellspacing|cellpadding)\s*=/i', $cleanedSample);

        // Verify brand assignment across direct thuong_hieu + unambiguous category fallback (SICK, Mitsubishi, etc.)
        $eeProd = Product::where('slug', 'ee-sx674')->with('brand')->first();
        $this->assertSame('omron', $eeProd?->brand?->slug);

        $pricedCount = Product::published()->where('price', '>', 1)->count();
        $rfqCount = Product::published()->where('price', 1)->count();
        $this->assertGreaterThan(75000, $pricedCount);
        $this->assertGreaterThan(50000, $rfqCount);

        // Multi-category N:N query: ate1-3s-110vac has categories autonics, ro-le-autonics, ro-le-thoi-gian-autonics
        $shopHtml = $this->get('/shop/')->assertStatus(200)->getContent();
        $this->assertStringContainsString('/wp-content/uploads/', $shopHtml);

        $multiCatSecondary = $this->get('/ro-le-thoi-gian-autonics/');
        $multiCatSecondary->assertStatus(200);
        $this->assertStringContainsString('/product/ate1-3s-110vac/', $multiCatSecondary->getContent());

        $multiCatParent = $this->get('/ro-le-autonics/');
        $multiCatParent->assertStatus(200);
        $this->assertStringContainsString('/product/ate1-3s-110vac/', $multiCatParent->getContent());
    }

    public function test_all_six_previously_colliding_root_urls_now_render_product_categories_accurately(): void
    {
        $collidingSlugs = [
            'cam-bien-quang-autonics' => 'Cảm biến quang Autonics chính hãng, Bảng giá 2027 - Hải Phòng Tech',
            'cam-bien-omron'          => 'Cảm Biến Omron Chính Hãng, Bảng giá 2027 - Hải Phòng Tech',
            'cam-bien-laser-keyence'  => 'Cảm biến Laser | Keyence Laser Sensor chính hãng, Giá tốt - Hải Phòng Tech',
            'cam-bien-keyence'        => 'Top cảm biến Keyence bán chạy số 1 VN | Bảng giá 2027 - Hải Phòng Tech',
            'cam-bien-quang-omron'    => 'Cảm biến quang Omron chính hãng | Bảng giá mới nhất 2027 - Hải Phòng Tech',
            'cam-bien-vung-autonics'  => 'Cảm Biến Vùng Autonics Chính Hãng, Bảng Giá & Hướng Dẫn Chọn Mua - Hải Phòng Tech',
        ];

        foreach ($collidingSlugs as $slug => $expectedSeoTitle) {
            $res = $this->get("/{$slug}/");
            $res->assertStatus(200);
            $html = $res->getContent();
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm);
            $actualTitle = html_entity_decode(trim($tm[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame($expectedSeoTitle, $actualTitle, "Colliding path [/{$slug}/] must serve scraped WP product_cat SEO title");
            $this->assertMatchesRegularExpression(
                '#<link\s+rel=["\']canonical["\']\s+href=["\'][^"\']*/' . preg_quote($slug, '#') . '/["\']#i',
                $html
            );
        }
    }

    public function test_scraped_phase2_seo_baselines_and_breadcrumbs_preserved(): void
    {
        $cases = [
            '/autonics/' => [
                'title'     => 'Nhà cung cấp Autonics tại Hải Phòng | Bảng giá 2027 - Hải Phòng Tech',
                'canonical' => 'http://localhost:8000/autonics/',
                'index'     => true,
            ],
            '/abb/' => [
                'title'     => 'Thiết bị ABB chính hãng, Hỗ trợ kỹ thuật, Bảng giá 2027 - Hải Phòng Tech',
                'canonical' => 'http://localhost:8000/abb/',
                'index'     => true,
            ],
            '/acb-abb/' => [
                'title'     => 'ACB ABB Chính Hãng — Phân Loại Emax2, Bảng Giá & Hướng Dẫn Chọn - Hải Phòng Tech',
                'canonical' => 'http://localhost:8000/acb-abb/',
                'index'     => true,
            ],
            '/phan-phoi-mitsubishi-electric-vietnam/' => [
                'title'     => 'Nhà cung cấp Mitsubishi Electric Chính Hãng Tại Việt Nam - Hải Phòng Tech',
                'canonical' => 'http://localhost:8000/phan-phoi-mitsubishi-electric-vietnam/',
                'index'     => true,
            ],
            '/thuong_hieu/abb/' => [
                'title'     => 'ABB - Hải Phòng Tech',
                'canonical' => 'http://localhost:8000/thuong_hieu/abb/',
                'index'     => true,
            ],
            '/tag-san-pham/autonics/' => [
                'title'     => 'Autonics Archives - Hải Phòng Tech',
                'canonical' => null,
                'index'     => false,
            ],
        ];

        foreach ($cases as $path => $expected) {
            $res = $this->get($path);
            $res->assertStatus(200);
            $html = $res->getContent();
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm);
            $actualTitle = html_entity_decode(trim($tm[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->assertSame($expected['title'], $actualTitle, "Missing exact WP SEO title on [{$path}]");
            if ($expected['index']) {
                $this->assertMatchesRegularExpression('/<meta\s+name=["\']robots["\']\s+content=["\'](?=.*\bindex\b)(?=.*\bfollow\b)[^"\']*["\']/i', $html);
                $this->assertMatchesRegularExpression(
                    '#<link\s+rel=["\']canonical["\']\s+href=["\']' . preg_quote((string) $expected['canonical'], '#') . '["\']#i',
                    $html
                );
            } else {
                $this->assertMatchesRegularExpression('/<meta\s+name=["\']robots["\']\s+content=["\']noindex,\s*follow["\']/i', $html);
                $this->assertDoesNotMatchRegularExpression('/<link\s+rel=["\']canonical["\']/i', $html);
            }
        }
    }

    public function test_imported_products_render_valid_html_seo_media_and_schema(): void
    {
        $prod = Product::where('slug', 'mhmf082c1v1m')->with('thumbnailMedia')->first();
        $this->assertNotNull($prod, 'Pilot product mhmf082c1v1m missing in database');
        $this->assertNotEmpty($prod->thumbnailMedia->first()?->wp_relative_path);

        // Unslashed -> 301
        $unslashed = $this->get('/product/mhmf082c1v1m');
        $unslashed->assertStatus(301);
        $this->assertSame('http://localhost:8000/product/mhmf082c1v1m/', $unslashed->headers->get('Location'));

        // Slashed -> 200
        $res = $this->get('/product/mhmf082c1v1m/');
        $res->assertStatus(200);
        $html = $res->getContent();

        // SEO, real media & Schema.org Product checks
        $this->assertStringContainsString('Động cơ AC Servo Panasonic MHMF082C1V1M IP67 - Hải Phòng Tech', $html);
        $this->assertStringContainsString('/wp-content/uploads/2026/11/MHMF082C1V1M.webp', $html);
        $this->assertMatchesRegularExpression('/<link\s+rel=["\']canonical["\']\s+href=["\']http:\/\/localhost:8000\/product\/mhmf082c1v1m\/["\']/i', $html);
        $this->assertMatchesRegularExpression('/<meta\s+name=["\']robots["\']\s+content=["\'](?=.*\bindex\b)(?=.*\bfollow\b)[^"\']*["\']/i', $html);
        $this->assertStringContainsString('"@type": "Product"', $html);
        $this->assertStringContainsString('"sku": "MHMF082C1V1M"', $html);
    }

    public function test_brand_archives_render_and_filter(): void
    {
        $res = $this->get('/thuong_hieu/abb/');
        $res->assertStatus(200);
        $html = $res->getContent();
        $this->assertStringContainsString('ABB - Hải Phòng Tech', $html);
        $this->assertStringContainsString('/product/', $html);
    }

    public function test_product_sitemap_cutover_blocker_and_streaming_sharding(): void
    {
        // With 133,036 products (>= 130,000), sitemap_index opens all 67 sharded product sitemaps cleanly
        $openIndex = $this->get('/sitemap_index.xml');
        $openIndex->assertStatus(200);
        $this->assertNull($openIndex->headers->get('X-Sitemap-Cutover-Status'));
        $this->assertStringContainsString('http://localhost:8000/product-sitemap1.xml', $openIndex->getContent());
        $this->assertStringContainsString('http://localhost:8000/product-sitemap67.xml', $openIndex->getContent());

        $chunk1 = $this->get('/product-sitemap1.xml');
        $chunk1->assertStatus(200);
        $chunkXml = $chunk1->streamedContent() ?: $chunk1->getContent();
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $chunkXml);
        $this->assertStringContainsString('<loc>http://localhost:8000/product/', $chunkXml);

        $chunk67 = $this->get('/product-sitemap67.xml');
        $chunk67->assertStatus(200);

        $missingChunk = $this->get('/product-sitemap999.xml');
        $missingChunk->assertStatus(404);

        // Also verify cutover safety guard activates whenever threshold exceeds published count
        $origMin = config('migration.phase2.full_product_cutover_min_count');
        try {
            config(['migration.phase2.full_product_cutover_min_count' => 999999]);

            $blockedIndex = $this->get('/sitemap_index.xml');
            $blockedIndex->assertStatus(200);
            $this->assertSame('BLOCKED_MISSING_67_PRODUCT_SITEMAPS', $blockedIndex->headers->get('X-Sitemap-Cutover-Status'));
            $this->assertStringNotContainsString('/product-sitemap1.xml', $blockedIndex->getContent());

            $blockedChunk = $this->get('/product-sitemap1.xml');
            $blockedChunk->assertStatus(404);
            $this->assertSame('RESERVED_PHASE2_PRODUCT_SITEMAP_BLOCKER', $blockedChunk->headers->get('X-Migration-Route-Status'));
        } finally {
            config(['migration.phase2.full_product_cutover_min_count' => $origMin]);
        }
    }

    public function test_track_a_dump_extractor_parses_seo_media_and_strips_pii_tables(): void
    {
        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hpt_track_a_test_' . uniqid();
        $sqlPath = $tmpBase . DIRECTORY_SEPARATOR . 'fixture.sql';
        $outDir = $tmpBase . DIRECTORY_SEPARATOR . 'out';
        File::ensureDirectoryExists($tmpBase);

        $sql = <<<'SQL'
INSERT INTO `wp_users` (`ID`, `user_login`, `user_pass`, `user_email`) VALUES (1, 'admin_secret', '$P$Bfakehash', 'admin_secret_pii@example.com');
INSERT INTO `wp_usermeta` (`umeta_id`, `user_id`, `meta_key`, `meta_value`) VALUES (1, 1, 'billing_phone', '0999888777_PII');
INSERT INTO `wp_woocommerce_order_items` (`order_item_id`, `order_item_name`, `order_id`) VALUES (1, 'ORDER_PII_SECRET_999', 500);
INSERT INTO `wp_terms` (`term_id`, `name`, `slug`) VALUES (10, 'Cam bien fixture', 'cam-bien-fixture'), (11, 'Autonics Brand', 'autonics-brand'), (12, 'Tag Fixture', 'tag-fixture');
INSERT INTO `wp_term_taxonomy` (`term_taxonomy_id`, `term_id`, `taxonomy`, `description`, `parent`, `count`) VALUES (100, 10, 'product_cat', 'Cat desc', 0, 1), (101, 11, 'thuong_hieu', '', 0, 1), (102, 12, 'product_tag', '', 0, 1);
INSERT INTO `wp_termmeta` (`meta_id`, `term_id`, `meta_key`, `meta_value`) VALUES (1, 10, 'rank_math_title', 'Cam bien Fixture SEO - Hai Phong Tech');
INSERT INTO `wp_posts` (`ID`, `post_date_gmt`, `post_content`, `post_title`, `post_excerpt`, `post_status`, `post_name`, `post_modified_gmt`, `guid`, `post_type`) VALUES (2001, '2026-10-01 00:00:00', '<p>Body fixture</p>', 'Fixture Sensor X1', 'Short desc', 'publish', 'fixture-sensor-x1', '2026-10-02 00:00:00', 'https://haiphongtech.vn/?post_type=product&p=2001', 'product'), (2002, '2026-10-01 00:00:00', '', 'Attachment', '', 'inherit', 'fixture-img', '2026-10-01 00:00:00', 'https://haiphongtech.vn/wp-content/uploads/2026/10/fixture-x1.webp', 'attachment');
INSERT INTO `wp_postmeta` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES (10, 2001, '_sku', 'FIX-X1'), (11, 2001, '_price', '250000'), (12, 2001, '_regular_price', '300000'), (13, 2001, '_thumbnail_id', '2002'), (14, 2001, 'rank_math_title', 'Fixture Sensor X1 - Hai Phong Tech'), (15, 2001, 'rank_math_description', 'Mo ta fixture'), (16, 2001, 'rank_math_robots', 'index,follow');
INSERT INTO `wp_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES (2001, 100, 0), (2001, 101, 0), (2001, 102, 0);
SQL;
        File::put($sqlPath, $sql);

        try {
            $scriptPath = base_path('scripts/migration/extract_wp_dump_phase2.php');
            $cmd = 'php ' . escapeshellarg($scriptPath)
                . ' --input-sql=' . escapeshellarg($sqlPath)
                . ' --out-dir=' . escapeshellarg($outDir)
                . ' --chunk-size=100';
            exec($cmd, $outputLines, $exitCode);

            $this->assertSame(0, $exitCode, 'Extractor failed: ' . implode("\n", $outputLines));
            $this->assertFileExists($outDir . DIRECTORY_SEPARATOR . 'products.jsonl');
            $this->assertFileExists($outDir . DIRECTORY_SEPARATOR . 'product_categories.jsonl');
            $this->assertFileExists($outDir . DIRECTORY_SEPARATOR . 'product_brands.jsonl');
            $this->assertFileExists($outDir . DIRECTORY_SEPARATOR . 'product_tags.jsonl');
            $this->assertFileExists($outDir . DIRECTORY_SEPARATOR . 'track_a_extraction_summary.json');

            foreach (['products.jsonl', 'product_categories.jsonl', 'product_brands.jsonl', 'product_tags.jsonl', 'track_a_extraction_summary.json'] as $fname) {
                $content = File::get($outDir . DIRECTORY_SEPARATOR . $fname);
                $this->assertStringNotContainsString('admin_secret_pii@example.com', $content, "PII leaked into {$fname}");
                $this->assertStringNotContainsString('ORDER_PII_SECRET_999', $content, "Order PII leaked into {$fname}");
                $this->assertStringNotContainsString('0999888777_PII', $content, "Usermeta PII leaked into {$fname}");
            }

            $prodRow = json_decode(trim(File::get($outDir . DIRECTORY_SEPARATOR . 'products.jsonl')), true);
            $this->assertSame('fixture-sensor-x1', $prodRow['slug']);
            $this->assertSame('FIX-X1', $prodRow['sku']);
            $this->assertEquals(250000.0, $prodRow['price']);
            $this->assertSame(1, $prodRow['sitemap_chunk']);
            $this->assertSame('https://haiphongtech.vn/wp-content/uploads/2026/10/fixture-x1.webp', $prodRow['images'][0]['src'] ?? null);
            $this->assertSame('Fixture Sensor X1 - Hai Phong Tech', $prodRow['seo']['meta_title'] ?? null);
        } finally {
            File::deleteDirectory($tmpBase);
        }
    }

    public function test_zero_misutech_brand_leaks_on_phase2_views(): void
    {
        $urls = ['/shop/', '/abb/', '/acb-abb/', '/product/mhmf082c1v1m/', '/thuong_hieu/abb/', '/tag-san-pham/autonics/'];
        foreach ($urls as $url) {
            $res = $this->get($url);
            $res->assertStatus(200);
            $html = $res->getContent();

            $this->assertStringNotContainsString('MISU-', $html, "Leak MISU- on {$url}");
            $this->assertStringNotContainsString('/danh-muc/', $html, "Leak /danh-muc/ on {$url}");

            // Strip internal stylesheet selectors and SVG symbol IDs
            $stripped = preg_replace('/\b(?:class|id)\s*=\s*["\'][^"\']*["\']/i', '', $html) ?? $html;
            $strippedAssets = preg_replace('#/clients/(?:css|js)/[^\s"\'<>]+#i', '', $stripped) ?? $stripped;
            $strippedSvgRef = str_ireplace('#misutech_home_sprite_source', '', $strippedAssets);

            $this->assertStringNotContainsStringIgnoringCase('misutech.vn', $strippedSvgRef, "Leak misutech.vn on {$url}");
            $this->assertStringNotContainsStringIgnoringCase('Công ty cổ phần Misutech', $strippedSvgRef, "Leak company name on {$url}");
            $this->assertDoesNotMatchRegularExpression('/\bMISUTECH\b/i', $strippedSvgRef, "Leak brand MISUTECH on {$url}");
        }
    }

    public function test_unmigrated_product_boundary_returns_404_blocker(): void
    {
        $res = $this->get('/product/chua-co-trong-pilot-999999/');
        $res->assertStatus(404);
        $this->assertSame('RESERVED_PHASE2_PRODUCT_BLOCKER', $res->headers->get('X-Migration-Route-Status'));
    }
}
