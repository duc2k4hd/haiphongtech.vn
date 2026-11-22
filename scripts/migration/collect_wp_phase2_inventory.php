<?php
declare(strict_types=1);

/**
 * Hai Phong Tech — Phase 2 Read-Only WooCommerce Inventory & Pilot Collector (Track B)
 *
 * Collects safely with rate-limiting (concurrency=1, configurable delay):
 *  - All 750 WooCommerce product categories (`wp/v2/product_cat` + `wc/store/v1/products/categories`)
 *  - All 48 custom brands (`wp/v2/thuong_hieu`)
 *  - Sitemap shard manifest (`product_cat-sitemap.xml` + `product-sitemap1..67.xml` URL counts)
 *  - Optional configurable pilot sample of products (`--pilot-products=200`, default 100) merging
 *    `wc/store/v1/products` (SKU, prices, images, attributes, stock) + `wp/v2/product` (`thuong_hieu`, `product_cat`).
 *
 * Usage:
 *   php scripts/migration/collect_wp_phase2_inventory.php --snapshot-id=snap_phase2_pilot --pilot-products=100 --delay-ms=200
 *
 * Zero writes to source or target database. Outputs JSONL/CSV manifests to docs/migration/data/phase2/.
 */

$options = getopt('', [
    'snapshot-id::',
    'pilot-products::',
    'delay-ms::',
    'include-sitemap-urls',
    'stratified',
    'collect-seo',
    'include-boundary-products',
]);

$snapshotId = (string) ($options['snapshot-id'] ?? ('snap_phase2_' . date('Ymd')));
$pilotLimit = max(0, (int) ($options['pilot-products'] ?? 100));
$delayMs = max(100, (int) ($options['delay-ms'] ?? 200));
$includeSitemapUrls = isset($options['include-sitemap-urls']);
$stratified = isset($options['stratified']);
$collectSeo = isset($options['collect-seo']);
$keepBoundaryReserved = !isset($options['include-boundary-products']);
$reservedBoundarySlugs = ['ee-sx674', 'ca-lh4'];

$rootDir = dirname(__DIR__, 2);
$cacheDir = "{$rootDir}/storage/app/private/wp_snapshots/{$snapshotId}";
$outDir = "{$rootDir}/docs/migration/data/phase2";

foreach ([$cacheDir, $outDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create directory: {$dir}\n");
        exit(1);
    }
}

function httpGetCached(string $url, string $cacheDir, int $delayMs): array
{
    $cacheKey = sha1($url);
    $cacheFile = "{$cacheDir}/{$cacheKey}.json";
    if (file_exists($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['status'] ?? 0) === 200) {
            return $cached;
        }
    }

    usleep($delayMs * 1000);
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'HaiPhongTech-Phase2-Inventory/1.0 (ReadOnly; RateLimited)',
        CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NATIVE_CA,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$headers): int {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ]);

    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $payload = [
        'url'        => $url,
        'status'     => $status,
        'headers'    => $headers,
        'body'       => $body,
        'error'      => $err,
        'fetched_at' => gmdate('c'),
    ];

    if ($status === 200 && $body !== '') {
        file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return $payload;
}

function fetchAllPaginatedJson(string $baseUrl, string $cacheDir, int $delayMs, int $perPage = 100, int $maxItems = 0): array
{
    $all = [];
    $page = 1;
    $totalPages = 1;

    while ($page <= $totalPages) {
        $sep = str_contains($baseUrl, '?') ? '&' : '?';
        $url = "{$baseUrl}{$sep}per_page={$perPage}&page={$page}";
        $res = httpGetCached($url, $cacheDir, $delayMs);
        if ($res['status'] !== 200) {
            fwrite(STDERR, "[WARN] HTTP {$res['status']} on {$url}\n");
            break;
        }
        if ($page === 1 && isset($res['headers']['x-wp-totalpages'])) {
            $totalPages = max(1, (int) $res['headers']['x-wp-totalpages']);
        }
        $rows = json_decode((string) $res['body'], true);
        if (!is_array($rows) || empty($rows)) {
            break;
        }
        foreach ($rows as $r) {
            $all[] = $r;
            if ($maxItems > 0 && count($all) >= $maxItems) {
                return $all;
            }
        }
        $page++;
    }

    return $all;
}

echo "=== HAI PHONG TECH PHASE 2 READ-ONLY INVENTORY COLLECTOR ===\n";
echo "Snapshot ID : {$snapshotId}\n";
echo "Pilot Limit : {$pilotLimit} products\n";
echo "Delay       : {$delayMs} ms\n\n";

// 1. Collect all WP product_cat (750) + WC Store categories (for images/counts)
echo "[1/4] Collecting product_cat taxonomies...\n";
$wpProductCats = fetchAllPaginatedJson('https://haiphongtech.vn/wp-json/wp/v2/product_cat', $cacheDir, $delayMs, 100);
$wcStoreCats = fetchAllPaginatedJson('https://haiphongtech.vn/wp-json/wc/store/v1/products/categories', $cacheDir, $delayMs, 100);
$storeCatById = [];
foreach ($wcStoreCats as $sc) {
    $storeCatById[(int) $sc['id']] = $sc;
}

$catFp = fopen("{$outDir}/product_categories.jsonl", 'w');
foreach ($wpProductCats as $c) {
    $id = (int) $c['id'];
    $storeExtra = $storeCatById[$id] ?? [];
    $record = [
        'source_wp_id'     => $id,
        'source_taxonomy'  => 'product_cat',
        'slug'             => $c['slug'],
        'name'             => html_entity_decode((string) $c['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'parent_wp_id'     => (int) ($c['parent'] ?? 0),
        'count'            => (int) ($c['count'] ?? 0),
        'source_permalink' => $c['link'],
        'description_html' => $c['description'] ?? '',
        'image_url'        => $storeExtra['image']['src'] ?? null,
    ];
    fwrite($catFp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
fclose($catFp);
echo "  -> Saved " . count($wpProductCats) . " product_cat records.\n";

// 2. Collect all custom brands (`thuong_hieu`, 48)
echo "[2/4] Collecting thuong_hieu (brands)...\n";
$wpBrands = fetchAllPaginatedJson('https://haiphongtech.vn/wp-json/wp/v2/thuong_hieu', $cacheDir, $delayMs, 100);
$brandFp = fopen("{$outDir}/product_brands.jsonl", 'w');
foreach ($wpBrands as $b) {
    $record = [
        'source_wp_id'     => (int) $b['id'],
        'source_taxonomy'  => 'thuong_hieu',
        'slug'             => $b['slug'],
        'name'             => html_entity_decode((string) $b['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'parent_wp_id'     => (int) ($b['parent'] ?? 0),
        'count'            => (int) ($b['count'] ?? 0),
        'source_permalink' => $b['link'],
        'description_html' => $b['description'] ?? '',
    ];
    fwrite($brandFp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
fclose($brandFp);
echo "  -> Saved " . count($wpBrands) . " thuong_hieu records.\n";

// 3. Collect Sitemap Index shard manifest
echo "[3/4] Inspecting sitemap_index.xml product shards...\n";
$idxRes = httpGetCached('https://haiphongtech.vn/sitemap_index.xml', $cacheDir, $delayMs);
$productSitemaps = [];
if (preg_match_all('#<loc>([^<]+product[^<]*\.xml)</loc>#i', (string) $idxRes['body'], $sm)) {
    $productSitemaps = array_values(array_unique($sm[1]));
}

$sitemapSummary = [
    'total_product_sitemaps' => count($productSitemaps),
    'sitemaps'               => $productSitemaps,
    'sampled_urls_count'     => 0,
];

if ($includeSitemapUrls && !empty($productSitemaps)) {
    $smCsv = fopen("{$outDir}/product_sitemap_urls.csv", 'w');
    fputcsv($smCsv, ['sitemap_url', 'loc']);
    foreach ($productSitemaps as $smUrl) {
        $smRes = httpGetCached($smUrl, $cacheDir, $delayMs);
        if (preg_match_all('#<loc>([^<]+)</loc>#i', (string) $smRes['body'], $um)) {
            foreach ($um[1] as $u) {
                if (preg_match('#\.(?:jpg|jpeg|png|webp|gif|svg)$#i', (string) parse_url($u, PHP_URL_PATH))) {
                    continue;
                }
                fputcsv($smCsv, [$smUrl, $u]);
                $sitemapSummary['sampled_urls_count']++;
            }
        }
    }
    fclose($smCsv);
}

// 4. Collect Pilot Sample of Products (merging WC Store API + WP REST API)
$pilotCount = 0;
$collectedProductTags = [];
$mergedProductsList = [];

if ($pilotLimit > 0 || $stratified) {
    echo "[4/6] Collecting pilot products (newest={$pilotLimit}" . ($stratified ? ', stratified=ON' : '') . ")...\n";
    $storeById = [];

    if ($pilotLimit > 0) {
        $baseStore = fetchAllPaginatedJson('https://haiphongtech.vn/wp-json/wc/store/v1/products', $cacheDir, $delayMs, min(100, $pilotLimit), $pilotLimit);
        foreach ($baseStore as $sp) {
            $storeById[(int) $sp['id']] = $sp;
        }
    }

    if ($stratified) {
        // Map product category slug -> WP ID so showcase categories always have real pilot products
        $catIdBySlug = [];
        foreach ($wpProductCats as $c) {
            $catIdBySlug[(string) $c['slug']] = (int) $c['id'];
        }
        $targetCatSlugs = [
            'autonics',
            'abb',
            'acb-abb',
            'omron',
            'keyence',
            'panasonic',
            'cam-bien-quang-autonics',
            'cam-bien-omron',
            'cam-bien-laser-keyence',
            'cam-bien-keyence',
            'cam-bien-quang-omron',
            'cam-bien-vung-autonics',
        ];
        foreach ($targetCatSlugs as $cSlug) {
            if (!isset($catIdBySlug[$cSlug])) {
                continue;
            }
            $cid = $catIdBySlug[$cSlug];
            $catUrl = "https://haiphongtech.vn/wp-json/wc/store/v1/products?category={$cid}&per_page=8";
            $res = httpGetCached($catUrl, $cacheDir, $delayMs);
            $rows = json_decode((string) $res['body'], true);
            if (is_array($rows)) {
                foreach ($rows as $sp) {
                    $storeById[(int) $sp['id']] = $sp;
                }
            }
        }

        // Showcase ABB brand via thuong_hieu taxonomy filter (ensuring /thuong_hieu/abb/ has rich sample products, incl. pagination)
        foreach ($wpBrands as $b) {
            if (in_array((string) $b['slug'], ['abb', 'autonics', 'omron', 'keyence'], true)) {
                $bUrl = 'https://haiphongtech.vn/wp-json/wp/v2/product?thuong_hieu=' . (int) $b['id'] . '&per_page=28';
                $bRes = httpGetCached($bUrl, $cacheDir, $delayMs);
                $bRows = json_decode((string) $bRes['body'], true);
                if (is_array($bRows) && !empty($bRows)) {
                    $bIds = array_map(static fn($r) => (int) $r['id'], $bRows);
                    foreach (array_chunk($bIds, 25) as $chunk) {
                        $sUrl = 'https://haiphongtech.vn/wp-json/wc/store/v1/products?include=' . implode(',', $chunk) . '&per_page=' . count($chunk);
                        $sRes = httpGetCached($sUrl, $cacheDir, $delayMs);
                        $sRows = json_decode((string) $sRes['body'], true);
                        if (is_array($sRows)) {
                            foreach ($sRows as $sp) {
                                $storeById[(int) $sp['id']] = $sp;
                            }
                        }
                    }
                }
            }
        }

        // Highest-priced products (real B2B pricing > 1 VND)
        $priceUrl = 'https://haiphongtech.vn/wp-json/wc/store/v1/products?orderby=price&order=desc&per_page=25';
        $pRes = httpGetCached($priceUrl, $cacheDir, $delayMs);
        $pRows = json_decode((string) $pRes['body'], true);
        if (is_array($pRows)) {
            foreach ($pRows as $sp) {
                $storeById[(int) $sp['id']] = $sp;
            }
        }

        // Top product_tags + sample products carrying them
        $topTagsUrl = 'https://haiphongtech.vn/wp-json/wp/v2/product_tag?per_page=20&orderby=count&order=desc';
        $tRes = httpGetCached($topTagsUrl, $cacheDir, $delayMs);
        $tRows = json_decode((string) $tRes['body'], true);
        if (is_array($tRows)) {
            foreach ($tRows as $tr) {
                $tid = (int) $tr['id'];
                $collectedProductTags[$tid] = [
                    'source_wp_id'          => $tid,
                    'slug'                  => $tr['slug'],
                    'name'                  => html_entity_decode((string) $tr['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'description'           => $tr['description'] ?? '',
                    'products_count_source' => (int) ($tr['count'] ?? 0),
                    'source_permalink'      => $tr['link'],
                ];
            }
            foreach (array_slice($tRows, 0, 5) as $tr) {
                $tpUrl = 'https://haiphongtech.vn/wp-json/wp/v2/product?product_tag=' . (int) $tr['id'] . '&per_page=6';
                $tpRes = httpGetCached($tpUrl, $cacheDir, $delayMs);
                $tpRows = json_decode((string) $tpRes['body'], true);
                if (is_array($tpRows) && !empty($tpRows)) {
                    $tpIds = array_map(static fn($r) => (int) $r['id'], $tpRows);
                    $sUrl = 'https://haiphongtech.vn/wp-json/wc/store/v1/products?include=' . implode(',', $tpIds) . '&per_page=' . count($tpIds);
                    $sRes = httpGetCached($sUrl, $cacheDir, $delayMs);
                    $sRows = json_decode((string) $sRes['body'], true);
                    if (is_array($sRows)) {
                        foreach ($sRows as $sp) {
                            $storeById[(int) $sp['id']] = $sp;
                        }
                    }
                }
            }
        }
    }

    if ($keepBoundaryReserved) {
        foreach ($storeById as $pid => $sp) {
            if (in_array((string) ($sp['slug'] ?? ''), $reservedBoundarySlugs, true)) {
                unset($storeById[$pid]);
            }
        }
    }

    $ids = array_keys($storeById);
    $wpById = [];
    foreach (array_chunk($ids, 50) as $idChunk) {
        $wpUrl = 'https://haiphongtech.vn/wp-json/wp/v2/product?include=' . implode(',', $idChunk) . '&per_page=' . count($idChunk);
        $wpRes = httpGetCached($wpUrl, $cacheDir, $delayMs);
        $wpRows = json_decode((string) $wpRes['body'], true) ?: [];
        foreach ($wpRows as $wr) {
            $wpById[(int) $wr['id']] = $wr;
        }
    }

    // Also fetch definitions for any product_tag IDs present on collected products
    $referencedTagIds = [];
    foreach ($wpById as $wr) {
        foreach (($wr['product_tag'] ?? []) as $tid) {
            $tidInt = (int) $tid;
            if (!isset($collectedProductTags[$tidInt])) {
                $referencedTagIds[$tidInt] = true;
            }
        }
    }
    if (!empty($referencedTagIds)) {
        foreach (array_chunk(array_keys($referencedTagIds), 50) as $tChunk) {
            $tgUrl = 'https://haiphongtech.vn/wp-json/wp/v2/product_tag?include=' . implode(',', $tChunk) . '&per_page=' . count($tChunk);
            $tgRes = httpGetCached($tgUrl, $cacheDir, $delayMs);
            $tgRows = json_decode((string) $tgRes['body'], true) ?: [];
            foreach ($tgRows as $tr) {
                $tid = (int) $tr['id'];
                $collectedProductTags[$tid] = [
                    'source_wp_id'          => $tid,
                    'slug'                  => $tr['slug'],
                    'name'                  => html_entity_decode((string) $tr['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'description'           => $tr['description'] ?? '',
                    'products_count_source' => (int) ($tr['count'] ?? 0),
                    'source_permalink'      => $tr['link'],
                ];
            }
        }
    }

    $prodFp = fopen("{$outDir}/pilot_products.jsonl", 'w');
    foreach ($storeById as $pid => $sp) {
        $wpExtra = $wpById[$pid] ?? [];
        $merged = [
            'source_wp_id'          => $pid,
            'slug'                  => $sp['slug'],
            'name'                  => html_entity_decode((string) $sp['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'sku'                   => ($sp['sku'] ?? '') !== '' ? $sp['sku'] : null,
            'product_type'          => $sp['type'] ?? 'simple',
            'source_permalink'      => $sp['permalink'],
            'price'                 => isset($sp['prices']['price']) && $sp['prices']['price'] !== '' ? (float) $sp['prices']['price'] : 0.0,
            'regular_price'         => isset($sp['prices']['regular_price']) && $sp['prices']['regular_price'] !== '' ? (float) $sp['prices']['regular_price'] : 0.0,
            'sale_price'            => isset($sp['prices']['sale_price']) && $sp['prices']['sale_price'] !== '' ? (float) $sp['prices']['sale_price'] : null,
            'is_in_stock'           => (bool) ($sp['is_in_stock'] ?? true),
            'short_description_raw' => $sp['short_description'] ?? '',
            'content_raw'           => $wpExtra['content']['rendered'] ?? ($sp['description'] ?? ''),
            'product_cat_wp_ids'    => $wpExtra['product_cat'] ?? array_map(static fn($c) => (int) $c['id'], $sp['categories'] ?? []),
            'thuong_hieu_wp_ids'    => $wpExtra['thuong_hieu'] ?? [],
            'product_tag_wp_ids'    => $wpExtra['product_tag'] ?? [],
            'images'                => $sp['images'] ?? [],
            'attributes'            => $sp['attributes'] ?? [],
            'published_at_gmt'      => $wpExtra['date_gmt'] ?? null,
            'modified_at_gmt'       => $wpExtra['modified_gmt'] ?? null,
        ];
        $mergedProductsList[] = $merged;
        fwrite($prodFp, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $pilotCount++;
    }
    fclose($prodFp);
    echo "  -> Saved {$pilotCount} stratified pilot products.\n";
}

// 5. Save collected product_tags.jsonl
echo "[5/6] Writing product_tags.jsonl (" . count($collectedProductTags) . " tags)...\n";
$tagFp = fopen("{$outDir}/product_tags.jsonl", 'w');
foreach ($collectedProductTags as $tagRec) {
    fwrite($tagFp, json_encode($tagRec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
fclose($tagFp);

// 6. Optional Verified HTML <head> SEO Baseline Capture (zero synthetic guessing)
$seoBaselineCount = 0;
if ($collectSeo) {
    echo "[6/6] Collecting verified live HTML <head> SEO baselines (rate-limited)...\n";
    $seoUrls = [
        'https://haiphongtech.vn/shop/',
        'https://haiphongtech.vn/shop/page/2/',
    ];

    $priorityCatSlugs = [
        'autonics', 'abb', 'acb-abb', 'omron', 'keyence', 'panasonic', 'schneider', 'mitsubishi',
        'cam-bien-autonics', 'cam-bien-vi-tri-autonics',
        'cam-bien-quang-autonics', 'cam-bien-omron', 'cam-bien-laser-keyence',
        'cam-bien-keyence', 'cam-bien-quang-omron', 'cam-bien-vung-autonics',
    ];
    foreach ($priorityCatSlugs as $cs) {
        $seoUrls[] = "https://haiphongtech.vn/{$cs}/";
    }
    foreach (['abb', 'autonics', 'panasonic', 'leadshine', 'omron', 'keyence', 'schneider', 'mitsubishi'] as $bs) {
        $seoUrls[] = "https://haiphongtech.vn/thuong_hieu/{$bs}/";
    }
    foreach (array_slice(array_values($collectedProductTags), 0, 5) as $tg) {
        $seoUrls[] = (string) $tg['source_permalink'];
    }
    // Sample diverse products: high price, colliding cat products, Abb products, Panasonic, Leadshine
    $sampledProdUrls = [];
    foreach ($mergedProductsList as $mp) {
        if ((float) ($mp['price'] ?? 0) > 1 || !empty($mp['thuong_hieu_wp_ids']) || !empty($mp['product_tag_wp_ids'])) {
            $sampledProdUrls[] = (string) $mp['source_permalink'];
        }
        if (count($sampledProdUrls) >= 25) {
            break;
        }
    }
    foreach (array_slice($mergedProductsList, 0, 10) as $mp) {
        $sampledProdUrls[] = (string) $mp['source_permalink'];
    }
    $seoUrls = array_values(array_unique(array_merge($seoUrls, $sampledProdUrls)));

    $seoFp = fopen("{$outDir}/phase2_seo_baseline.jsonl", 'w');
    foreach ($seoUrls as $targetUrl) {
        $res = httpGetCached($targetUrl, $cacheDir, $delayMs);
        if (($res['status'] ?? 0) !== 200 || empty($res['body'])) {
            continue;
        }
        $html = (string) $res['body'];
        $normPath = '/' . ltrim((string) parse_url($targetUrl, PHP_URL_PATH), '/');
        if ($normPath !== '/' && !str_ends_with($normPath, '/')) {
            $normPath .= '/';
        }

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm)) {
            $title = html_entity_decode(trim(preg_replace('/\s+/', ' ', $tm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $desc = '';
        if (preg_match('/<meta\s[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/is', $html, $dm)
            || preg_match('/<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/is', $html, $dm)) {
            $desc = html_entity_decode(trim($dm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $robots = '';
        if (preg_match('/<meta\s[^>]*name=["\']robots["\'][^>]*content=["\']([^"\']*)["\']/is', $html, $rm)
            || preg_match('/<meta\s[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']robots["\']/is', $html, $rm)) {
            $robots = trim($rm[1]);
        }
        $canonical = '';
        if (preg_match('/<link\s[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']*)["\']/is', $html, $cm)
            || preg_match('/<link\s[^>]*href=["\']([^"\']*)["\'][^>]*rel=["\']canonical["\']/is', $html, $cm)) {
            $canonical = trim($cm[1]);
        }
        $schemaJson = null;
        if (preg_match('/<script\s+type=["\']application\/ld\+json["\'][^>]*class=["\'][^"\']*rank-math-schema[^"\']*["\'][^>]*>(.*?)<\/script>/is', $html, $sm)
            || preg_match('/<script\s+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $sm)) {
            $schemaJson = trim($sm[1]);
        }

        $seoRow = [
            'normalized_path' => $normPath,
            'source_url'      => $targetUrl,
            'http_status'     => (int) $res['status'],
            'meta_title'      => $title !== '' ? $title : null,
            'meta_description'=> $desc !== '' ? $desc : null,
            'meta_robots'     => $robots !== '' ? $robots : null,
            'canonical_url'   => $canonical !== '' ? $canonical : null,
            'schema_json'     => $schemaJson,
            'fetched_at'      => $res['fetched_at'] ?? gmdate('c'),
        ];
        fwrite($seoFp, json_encode($seoRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $seoBaselineCount++;
    }
    fclose($seoFp);
    echo "  -> Captured {$seoBaselineCount} verified SEO HTML baselines.\n";
}

$summary = [
    'snapshot_id'            => $snapshotId,
    'generated_at'           => gmdate('c'),
    'product_categories'     => count($wpProductCats),
    'product_brands'         => count($wpBrands),
    'product_tags_saved'     => count($collectedProductTags),
    'product_sitemaps_count' => count($productSitemaps),
    'pilot_products_saved'   => $pilotCount,
    'seo_baselines_verified' => $seoBaselineCount,
];
file_put_contents("{$outDir}/phase2_inventory_summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "\nPhase 2 inventory collection complete -> {$outDir}/phase2_inventory_summary.json\n";
