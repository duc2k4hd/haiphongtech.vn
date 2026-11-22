<?php
declare(strict_types=1);

/**
 * Hai Phong Tech — Phase 1 Read-Only WordPress Source Inventory Collector
 *
 * Usage:
 *   php scripts/migration/collect_wp_inventory.php [--snapshot-id=snap_20261118_phase1] [--delay-ms=180] [--from-cache] [--wxr-file=path.xml]
 *
 * Strictly READ-ONLY against source (GET requests only, sequential, rate-limited, cached)
 * and ZERO writes to any database.
 */

$rootDir = dirname(__DIR__, 2);
$options = getopt('', ['snapshot-id::', 'delay-ms::', 'from-cache', 'wxr-file::']);

$snapshotId = $options['snapshot-id'] ?? 'snap_20261118_phase1';
$delayMs    = max(100, (int)($options['delay-ms'] ?? 150));
$fromCache  = array_key_exists('from-cache', $options);
$wxrFile    = $options['wxr-file'] ?? null;

$baseUrl     = 'https://haiphongtech.vn';
$userAgent   = 'HaiPhongTechMigrationAuditor/1.0 (+https://haiphongtech.vn; read-only-inventory)';
$snapshotDir = $rootDir . '/storage/app/private/wp_snapshots/' . $snapshotId;
$htmlCacheDir = $snapshotDir . '/html';
$restCacheDir = $snapshotDir . '/rest';
$xmlCacheDir  = $snapshotDir . '/xml';
$dataOutDir   = $rootDir . '/docs/migration/data';

foreach ([$snapshotDir, $htmlCacheDir, $restCacheDir, $xmlCacheDir, $dataOutDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$stats = [
    'network_requests' => 0,
    'cache_hits'       => 0,
    'errors'           => [],
    'started_at'       => gmdate('c'),
];

/**
 * Perform a single non-redirect-following HTTP GET (or read from snapshot cache).
 */
function httpFetchCached(
    string $url,
    string $cachePath,
    int $delayMs,
    bool $fromCache,
    string $userAgent,
    array &$stats
): array {
    if (is_file($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached) && isset($cached['status'])) {
            $stats['cache_hits']++;
            return $cached;
        }
    }

    if ($fromCache) {
        return [
            'url'          => $url,
            'status'       => 0,
            'headers'      => [],
            'body'         => '',
            'error'        => 'MISSING_IN_CACHE_ONLY_MODE',
            'fetched_at'   => gmdate('c'),
        ];
    }

    $attempt = 0;
    $maxAttempts = 3;
    while ($attempt < $maxAttempts) {
        $attempt++;
        if ($stats['network_requests'] > 0 && $delayMs > 0) {
            usleep($delayMs * 1000);
        }

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_ENCODING       => '', // accept gzip/deflate
        ];
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            $curlOpts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $curlOpts);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $stats['network_requests']++;

        if ($raw !== false && $code > 0 && !in_array($code, [429, 502, 503, 504], true)) {
            $rawHeaders = substr($raw, 0, $hSize);
            $body       = substr($raw, $hSize);
            $headers    = [];
            foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) as $line) {
                if (str_contains($line, ':')) {
                    [$hk, $hv] = explode(':', $line, 2);
                    $hkLower = strtolower(trim($hk));
                    $headers[$hkLower] = trim($hv);
                }
            }

            $record = [
                'url'        => $url,
                'status'     => $code,
                'headers'    => $headers,
                'body'       => $body,
                'error'      => '',
                'fetched_at' => gmdate('c'),
            ];
            file_put_contents($cachePath, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $record;
        }

        // Backoff on transient errors
        usleep($attempt * 800 * 1000);
    }

    $errRecord = [
        'url'        => $url,
        'status'     => $code ?? 0,
        'headers'    => [],
        'body'       => '',
        'error'      => $err ?: "HTTP_{$code}",
        'fetched_at' => gmdate('c'),
    ];
    $stats['errors'][] = "{$url} => {$errRecord['error']}";
    return $errRecord;
}

/**
 * Follow redirects manually up to 5 hops so we capture full redirect_chain, original_status, and final_url.
 */
function fetchWithRedirectTrace(
    string $url,
    string $cacheDir,
    int $delayMs,
    bool $fromCache,
    string $userAgent,
    array &$stats
): array {
    $currentUrl = $url;
    $chain = [];
    $firstStatus = 0;
    $lastResp = null;

    for ($hop = 0; $hop < 5; $hop++) {
        $cacheKey = sha1($currentUrl) . '.json';
        $resp = httpFetchCached($currentUrl, $cacheDir . '/' . $cacheKey, $delayMs, $fromCache, $userAgent, $stats);
        if ($hop === 0) {
            $firstStatus = (int)$resp['status'];
        }
        $lastResp = $resp;
        $status = (int)$resp['status'];

        if (in_array($status, [301, 302, 303, 307, 308], true) && !empty($resp['headers']['location'])) {
            $nextUrl = $resp['headers']['location'];
            if (str_starts_with($nextUrl, '/')) {
                $parts = parse_url($currentUrl);
                $nextUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'haiphongtech.vn') . $nextUrl;
            }
            $chain[] = "{$status}:{$nextUrl}";
            if ($nextUrl === $currentUrl) {
                $chain[] = 'LOOP_DETECTED';
                break;
            }
            $currentUrl = $nextUrl;
            continue;
        }
        break;
    }

    return [
        'requested_url'   => $url,
        'original_status' => $firstStatus,
        'final_status'    => (int)($lastResp['status'] ?? 0),
        'final_url'       => $currentUrl,
        'redirect_chain'  => implode(' -> ', $chain),
        'headers'         => $lastResp['headers'] ?? [],
        'body'            => $lastResp['body'] ?? '',
        'error'           => $lastResp['error'] ?? '',
        'fetched_at'      => $lastResp['fetched_at'] ?? gmdate('c'),
    ];
}

/**
 * Fetch all pages of a WordPress REST collection.
 */
function fetchWpRestCollection(
    string $endpointUrl,
    string $prefix,
    string $restCacheDir,
    int $delayMs,
    bool $fromCache,
    string $userAgent,
    array &$stats
): array {
    $all = [];
    $page = 1;
    $totalPages = 1;

    while ($page <= $totalPages) {
        $sep = str_contains($endpointUrl, '?') ? '&' : '?';
        $url = "{$endpointUrl}{$sep}per_page=100&page={$page}";
        $cachePath = "{$restCacheDir}/{$prefix}_p{$page}.json";
        $resp = httpFetchCached($url, $cachePath, $delayMs, $fromCache, $userAgent, $stats);

        if ((int)$resp['status'] !== 200) {
            break;
        }
        if ($page === 1 && isset($resp['headers']['x-wp-totalpages'])) {
            $totalPages = (int)$resp['headers']['x-wp-totalpages'];
        }
        $items = json_decode($resp['body'], true);
        if (!is_array($items) || empty($items)) {
            break;
        }
        foreach ($items as $it) {
            $all[] = $it;
        }
        $page++;
    }
    return $all;
}

/**
 * Parse XML Sitemap <url> or <sitemap> entries including <image:loc>.
 */
function parseXmlSitemap(string $xml): array {
    if (trim($xml) === '') {
        return ['type' => 'empty', 'items' => []];
    }
    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml);
    if ($sx === false) {
        return ['type' => 'invalid', 'items' => []];
    }

    $rootName = $sx->getName();
    $items = [];
    if ($rootName === 'sitemapindex') {
        foreach ($sx->sitemap as $sm) {
            $items[] = [
                'loc'     => trim((string)$sm->loc),
                'lastmod' => trim((string)$sm->lastmod),
            ];
        }
        return ['type' => 'sitemapindex', 'items' => $items];
    }

    $namespaces = $sx->getNamespaces(true);
    foreach ($sx->url as $u) {
        $images = [];
        if (isset($namespaces['image'])) {
            $imgNodes = $u->children($namespaces['image']);
            if ($imgNodes && isset($imgNodes->image)) {
                foreach ($imgNodes->image as $img) {
                    $imgLoc = trim((string)$img->loc);
                    if ($imgLoc !== '') {
                        $images[] = $imgLoc;
                    }
                }
            }
        }
        $items[] = [
            'loc'     => trim((string)$u->loc),
            'lastmod' => trim((string)$u->lastmod),
            'images'  => $images,
        ];
    }
    return ['type' => 'urlset', 'items' => $items];
}

/**
 * Parse SEO & structural signals from an HTML document.
 */
function parseHtmlSeo(string $html): array {
    $res = [
        'title'          => '',
        'description'    => '',
        'robots'         => '',
        'canonical'      => '',
        'og_title'       => '',
        'og_description' => '',
        'og_url'         => '',
        'og_image'       => '',
        'og_type'        => '',
        'og_site_name'   => '',
        'twitter_card'   => '',
        'twitter_title'  => '',
        'twitter_desc'   => '',
        'twitter_image'  => '',
        'h1'             => '',
        'h1_count'       => 0,
        'schema_types'   => [],
        'schema_raw'     => [],
    ];

    if (trim($html) === '') {
        return $res;
    }

    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $res['title'] = html_entity_decode(trim(preg_replace('/\s+/', ' ', $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Extract meta tags in <head>
    $headHtml = $html;
    if (preg_match('/<head[^>]*>(.*?)<\/head>/is', $html, $hm)) {
        $headHtml = $hm[1];
    }

    if (preg_match_all('/<meta\b[^>]*>/is', $headHtml, $metaMatches)) {
        foreach ($metaMatches[0] as $tag) {
            $name = '';
            $prop = '';
            $content = '';
            if (preg_match('/\bname\s*=\s*["\']([^"\']+)["\']/i', $tag, $nm)) {
                $name = strtolower(trim($nm[1]));
            }
            if (preg_match('/\bproperty\s*=\s*["\']([^"\']+)["\']/i', $tag, $pm)) {
                $prop = strtolower(trim($pm[1]));
            }
            if (preg_match('/\bcontent\s*=\s*["\']([^"\']*)["\']/i', $tag, $cm)) {
                $content = html_entity_decode($cm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            if ($name === 'description' && $res['description'] === '') $res['description'] = $content;
            if ($name === 'robots' && $res['robots'] === '')           $res['robots'] = $content;
            if ($name === 'twitter:card')                              $res['twitter_card'] = $content;
            if ($name === 'twitter:title')                             $res['twitter_title'] = $content;
            if ($name === 'twitter:description')                       $res['twitter_desc'] = $content;
            if ($name === 'twitter:image')                             $res['twitter_image'] = $content;

            if ($prop === 'og:title')       $res['og_title'] = $content;
            if ($prop === 'og:description') $res['og_description'] = $content;
            if ($prop === 'og:url')         $res['og_url'] = $content;
            if ($prop === 'og:image' && $res['og_image'] === '') $res['og_image'] = $content;
            if ($prop === 'og:type')        $res['og_type'] = $content;
            if ($prop === 'og:site_name')   $res['og_site_name'] = $content;
        }
    }

    if (preg_match_all('/<link\b[^>]*>/is', $headHtml, $linkMatches)) {
        foreach ($linkMatches[0] as $ltag) {
            if (preg_match('/\brel\s*=\s*["\']canonical["\']/i', $ltag) && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $ltag, $hm)) {
                $res['canonical'] = html_entity_decode(trim($hm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                break;
            }
        }
    }

    if (preg_match_all('/<script\b[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ldMatches)) {
        foreach ($ldMatches[1] as $rawJson) {
            $trimmed = trim($rawJson);
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $res['schema_raw'][] = $decoded;
                if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    foreach ($decoded['@graph'] as $node) {
                        if (isset($node['@type'])) {
                            $types = (array)$node['@type'];
                            foreach ($types as $tp) $res['schema_types'][] = (string)$tp;
                        }
                    }
                } elseif (isset($decoded['@type'])) {
                    foreach ((array)$decoded['@type'] as $tp) $res['schema_types'][] = (string)$tp;
                }
            }
        }
    }
    $res['schema_types'] = array_values(array_unique($res['schema_types']));

    if (preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $h1m)) {
        $res['h1_count'] = count($h1m[1]);
        $res['h1'] = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($h1m[1][0]))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return $res;
}

/**
 * Extract sitewide header menu, footer links, logos, phones, emails, and addresses from Homepage/Contact HTML.
 */
function extractSitewideChrome(string $homeHtml, string $contactHtml): array {
    $chrome = [
        'logos'    => [],
        'menus'    => [],
        'footer_links' => [],
        'emails_found' => [],
        'phones_found' => [],
    ];

    foreach (['home' => $homeHtml, 'contact' => $contactHtml] as $src => $html) {
        if ($html === '') continue;
        if (preg_match_all('/[a-zA-Z0-9._%+\-]+@haiphongtech\.vn/i', $html, $em)) {
            foreach ($em[0] as $e) {
                $chrome['emails_found'][strtolower($e)][] = $src;
            }
        }
        if (preg_match_all('/tel:([^"\'\s>]+)/i', $html, $tm)) {
            foreach ($tm[1] as $t) {
                $chrome['phones_found'][trim($t)][] = $src;
            }
        }
    }
    foreach ($chrome['emails_found'] as $k => $v) $chrome['emails_found'][$k] = array_values(array_unique($v));
    foreach ($chrome['phones_found'] as $k => $v) $chrome['phones_found'][$k] = array_values(array_unique($v));

    if ($homeHtml !== '') {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $homeHtml);
        $xpath = new DOMXPath($dom);

        // Logos in header
        foreach ($xpath->query('//header//img') as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('data-lazy-src') ?: ($img->getAttribute('data-src') ?: $img->getAttribute('src'));
            if ($src && !str_starts_with($src, 'data:')) {
                $chrome['logos'][] = [
                    'src' => $src,
                    'alt' => $img->getAttribute('alt'),
                ];
            }
        }

        // Header nav links
        foreach ($xpath->query('//header//nav//a | //header//ul[contains(@class,"nav")]//a') as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            $text = trim(preg_replace('/\s+/', ' ', $a->textContent));
            if ($href !== '' && $text !== '') {
                $chrome['menus'][] = ['text' => $text, 'href' => $href];
            }
        }

        // Footer links
        foreach ($xpath->query('//footer//a') as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            $text = trim(preg_replace('/\s+/', ' ', $a->textContent));
            if ($href !== '' || $text !== '') {
                $chrome['footer_links'][] = ['text' => $text, 'href' => $href];
            }
        }
    }

    return $chrome;
}

/**
 * Extract all wp-content/uploads URLs and shortcodes from HTML content.
 */
function analyzeContentAssetsAndShortcodes(string $html): array {
    $uploads = [];
    if (preg_match_all('/https?:\/\/(?:www\.)?haiphongtech\.vn\/wp-content\/uploads\/[^\s"\'<>)\]]+/i', $html, $um)) {
        foreach ($um[0] as $u) {
            $uploads[] = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    if (preg_match_all('/(?<![a-zA-Z0-9])\/wp-content\/uploads\/[^\s"\'<>)\]]+/i', $html, $rum)) {
        foreach ($rum[0] as $ru) {
            $uploads[] = 'https://haiphongtech.vn' . html_entity_decode($ru, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    // Detect WP attachment IDs in classes like wp-image-12345
    $attachmentIds = [];
    if (preg_match_all('/\bwp-image-(\d+)\b/', $html, $im)) {
        foreach ($im[1] as $id) {
            $attachmentIds[] = (int)$id;
        }
    }

    // Detect unrendered shortcodes like [contact-form-7 ...]
    $shortcodes = [];
    $stripped = preg_replace('/<(script|style|code|pre)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
    if (preg_match_all('/\[([a-zA-Z_][a-zA-Z0-9_\-]{2,})(?:\s[^\]]*)?\]/', $stripped, $sm)) {
        foreach ($sm[1] as $sc) {
            if (!in_array(strtolower($sc), ['ẩn', 'hiện', '1', '2', '3'], true)) {
                $shortcodes[] = $sc;
            }
        }
    }

    // Detect placeholders
    $placeholders = [];
    foreach (['Lorem ipsum', 'Add Any content here', 'Sample Page', 'Hello world'] as $ph) {
        if (stripos($html, $ph) !== false) {
            $placeholders[] = $ph;
        }
    }

    return [
        'uploads'        => array_values(array_unique($uploads)),
        'attachment_ids' => array_values(array_unique($attachmentIds)),
        'shortcodes'     => array_values(array_unique($shortcodes)),
        'placeholders'   => array_values(array_unique($placeholders)),
    ];
}

echo "[1/7] Fetching robots.txt & XML Sitemaps...\n";
$robotsResp = httpFetchCached("{$baseUrl}/robots.txt", "{$xmlCacheDir}/robots.txt.json", $delayMs, $fromCache, $userAgent, $stats);
$sitemapIdxResp = httpFetchCached("{$baseUrl}/sitemap_index.xml", "{$xmlCacheDir}/sitemap_index.xml.json", $delayMs, $fromCache, $userAgent, $stats);
$sitemapIndexParsed = parseXmlSitemap($sitemapIdxResp['body']);

$subSitemapsToFetch = [
    'page-sitemap.xml',
    'post-sitemap.xml',
    'category-sitemap.xml',
    'product_cat-sitemap.xml',
    'product-sitemap1.xml',
    'product-sitemap67.xml',
];

$sitemapUrlsMap = []; // url => [sitemap_name, lastmod, images]
$sitemapSummary = [];
$productSitemapsCount = 0;
foreach ($sitemapIndexParsed['items'] as $smItem) {
    if (str_contains($smItem['loc'], 'product-sitemap')) {
        $productSitemapsCount++;
    }
}

foreach ($subSitemapsToFetch as $smFile) {
    $resp = httpFetchCached("{$baseUrl}/{$smFile}", "{$xmlCacheDir}/{$smFile}.json", $delayMs, $fromCache, $userAgent, $stats);
    $parsed = parseXmlSitemap($resp['body']);
    $sitemapSummary[$smFile] = [
        'status' => $resp['status'],
        'count'  => count($parsed['items']),
    ];
    if (!str_starts_with($smFile, 'product-sitemap')) {
        foreach ($parsed['items'] as $uItem) {
            $sitemapUrlsMap[$uItem['loc']] = [
                'sitemap' => $smFile,
                'lastmod' => $uItem['lastmod'],
                'images'  => $uItem['images'] ?? [],
            ];
        }
    }
}

$estimatedProductsInSitemap = 0;
if ($productSitemapsCount >= 2 && isset($sitemapSummary['product-sitemap1.xml'], $sitemapSummary['product-sitemap67.xml'])) {
    $estimatedProductsInSitemap = (($productSitemapsCount - 1) * $sitemapSummary['product-sitemap1.xml']['count'])
        + $sitemapSummary['product-sitemap67.xml']['count'];
}

echo "[2/7] Fetching WordPress REST API Collections (Pages, Posts, Categories, Tags, Product Taxonomies)...\n";
$wpRootResp = httpFetchCached("{$baseUrl}/wp-json/", "{$restCacheDir}/wp_root.json", $delayMs, $fromCache, $userAgent, $stats);
$wpRoot = json_decode($wpRootResp['body'], true) ?: [];

$wpPages      = fetchWpRestCollection("{$baseUrl}/wp-json/wp/v2/pages?_embed=1", 'pages', $restCacheDir, $delayMs, $fromCache, $userAgent, $stats);
$wpPosts      = fetchWpRestCollection("{$baseUrl}/wp-json/wp/v2/posts?_embed=1", 'posts', $restCacheDir, $delayMs, $fromCache, $userAgent, $stats);
$wpCategories = fetchWpRestCollection("{$baseUrl}/wp-json/wp/v2/categories", 'categories', $restCacheDir, $delayMs, $fromCache, $userAgent, $stats);
$wpTags       = fetchWpRestCollection("{$baseUrl}/wp-json/wp/v2/tags", 'tags', $restCacheDir, $delayMs, $fromCache, $userAgent, $stats);
$wpProdCats   = fetchWpRestCollection("{$baseUrl}/wp-json/wp/v2/product_cat", 'product_cat', $restCacheDir, $delayMs, $fromCache, $userAgent, $stats);
$wpBrands     = fetchWpRestCollection("{$baseUrl}/wp-json/wp/v2/thuong_hieu", 'thuong_hieu', $restCacheDir, $delayMs, $fromCache, $userAgent, $stats);

echo sprintf(
    "  REST Totals => pages=%d, posts=%d, post_categories=%d, tags=%d, product_cat=%d, thuong_hieu=%d\n",
    count($wpPages), count($wpPosts), count($wpCategories), count($wpTags), count($wpProdCats), count($wpBrands)
);

echo "[3/7] Collecting Referenced Media Metadata via WP REST API...\n";
$neededMediaIds = [];
if (!empty($wpRoot['site_icon'])) $neededMediaIds[] = (int)$wpRoot['site_icon'];
if (!empty($wpRoot['site_logo'])) $neededMediaIds[] = (int)$wpRoot['site_logo'];

foreach (array_merge($wpPages, $wpPosts) as $entry) {
    if (!empty($entry['featured_media'])) {
        $neededMediaIds[] = (int)$entry['featured_media'];
    }
    $analysis = analyzeContentAssetsAndShortcodes($entry['content']['rendered'] ?? '');
    foreach ($analysis['attachment_ids'] as $aid) {
        $neededMediaIds[] = $aid;
    }
}
$neededMediaIds = array_values(array_unique(array_filter($neededMediaIds)));
sort($neededMediaIds);

$mediaById = [];
foreach (array_chunk($neededMediaIds, 100) as $chunkIdx => $idChunk) {
    $includeStr = implode(',', $idChunk);
    $mUrl = "{$baseUrl}/wp-json/wp/v2/media?include={$includeStr}&per_page=100";
    $mResp = httpFetchCached($mUrl, "{$restCacheDir}/media_batch_{$chunkIdx}.json", $delayMs, $fromCache, $userAgent, $stats);
    if ((int)$mResp['status'] === 200) {
        $mItems = json_decode($mResp['body'], true);
        if (is_array($mItems)) {
            foreach ($mItems as $mi) {
                if (isset($mi['id'])) {
                    $mediaById[(int)$mi['id']] = $mi;
                }
            }
        }
    }
}
echo "  Media REST records resolved: " . count($mediaById) . " / " . count($neededMediaIds) . "\n";

echo "[4/7] Fetching Live HTML Baseline (SEO Meta, Canonicals, Robots, Redirects, Sitewide Chrome)...\n";
$urlsToInspect = [];

// 1. Homepage & Core Blog Archives
$urlsToInspect[$baseUrl . '/'] = ['type' => 'home', 'source_id' => 'home', 'slug' => '', 'parent_id' => 0, 'publish_state' => 'publish', 'discovered_from' => 'seed+rest'];
$urlsToInspect[$baseUrl . '/tin-blog/'] = ['type' => 'blog_index', 'source_id' => 'blog_index', 'slug' => 'tin-blog', 'parent_id' => 0, 'publish_state' => 'publish', 'discovered_from' => 'seed'];
$urlsToInspect[$baseUrl . '/tin-blog/page/2/'] = ['type' => 'blog_pagination', 'source_id' => 'blog_page_2', 'slug' => 'tin-blog/page/2', 'parent_id' => 0, 'publish_state' => 'publish', 'discovered_from' => 'seed'];

// 2. All 23 Pages
foreach ($wpPages as $pg) {
    $link = $pg['link'];
    // Check if this page IS the homepage (`link === https://haiphongtech.vn/`)
    if (rtrim($link, '/') === rtrim($baseUrl, '/')) {
        $urlsToInspect[$baseUrl . '/'] = [
            'type'            => 'page_home',
            'source_id'       => (string)$pg['id'],
            'slug'            => (string)$pg['slug'],
            'parent_id'       => (int)($pg['parent'] ?? 0),
            'publish_state'   => (string)$pg['status'],
            'discovered_from' => 'wp_rest_pages',
        ];
    } else {
        $urlsToInspect[$link] = [
            'type'            => 'page',
            'source_id'       => (string)$pg['id'],
            'slug'            => (string)$pg['slug'],
            'parent_id'       => (int)($pg['parent'] ?? 0),
            'publish_state'   => (string)$pg['status'],
            'discovered_from' => 'wp_rest_pages',
        ];
    }
}

// 3. All 4 Post Categories
foreach ($wpCategories as $cat) {
    $urlsToInspect[$cat['link']] = [
        'type'            => 'post_category',
        'source_id'       => (string)$cat['id'],
        'slug'            => (string)$cat['slug'],
        'parent_id'       => (int)($cat['parent'] ?? 0),
        'publish_state'   => 'publish',
        'discovered_from' => 'wp_rest_categories',
    ];
}

// 4. All 235 Posts
foreach ($wpPosts as $pst) {
    $urlsToInspect[$pst['link']] = [
        'type'            => 'post',
        'source_id'       => (string)$pst['id'],
        'slug'            => (string)$pst['slug'],
        'parent_id'       => 0,
        'publish_state'   => (string)$pst['status'],
        'discovered_from' => 'wp_rest_posts',
    ];
}

// 5. Any URL in page-sitemap, post-sitemap, category-sitemap not yet in $urlsToInspect
foreach ($sitemapUrlsMap as $smLoc => $smMeta) {
    if ($smMeta['sitemap'] === 'product_cat-sitemap.xml') {
        continue;
    }
    if (!isset($urlsToInspect[$smLoc])) {
        $urlsToInspect[$smLoc] = [
            'type'            => 'sitemap_only_' . str_replace('-sitemap.xml', '', $smMeta['sitemap']),
            'source_id'       => '',
            'slug'            => trim((string)parse_url($smLoc, PHP_URL_PATH), '/'),
            'parent_id'       => 0,
            'publish_state'   => 'unknown',
            'discovered_from' => $smMeta['sitemap'],
        ];
    }
}

// 6. Representative Tags (seed tags + top tags by count + 1 zero-count tag)
$tagsSorted = $wpTags;
usort($tagsSorted, fn($a, $b) => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
$sampleTagSlugs = ['cam-bien', 'cam-bien-tu'];
foreach (array_slice($tagsSorted, 0, 8) as $tg) {
    $sampleTagSlugs[] = $tg['slug'];
}
foreach (array_reverse($tagsSorted) as $tg) {
    if ((int)($tg['count'] ?? 0) === 0) {
        $sampleTagSlugs[] = $tg['slug'];
        break;
    }
}
$sampleTagSlugs = array_values(array_unique($sampleTagSlugs));
$tagsBySlug = [];
foreach ($wpTags as $tg) {
    $tagsBySlug[$tg['slug']] = $tg;
}
foreach ($sampleTagSlugs as $tslug) {
    if (isset($tagsBySlug[$tslug])) {
        $tg = $tagsBySlug[$tslug];
        $urlsToInspect[$tg['link']] = [
            'type'            => 'post_tag_sampled',
            'source_id'       => (string)$tg['id'],
            'slug'            => (string)$tg['slug'],
            'parent_id'       => 0,
            'publish_state'   => 'publish',
            'discovered_from' => 'wp_rest_tags_sampled',
        ];
    }
}

// 7. Product Boundary Sample URLs + Unslashed Trailing-Slash Probes
$boundaryUrls = [
    "{$baseUrl}/shop/"                       => ['type' => 'product_shop_boundary', 'source_id' => 'wc_shop', 'slug' => 'shop'],
    "{$baseUrl}/shop/page/2/"                => ['type' => 'product_shop_pagination_boundary', 'source_id' => 'wc_shop_p2', 'slug' => 'shop/page/2'],
    "{$baseUrl}/autonics/"                   => ['type' => 'product_cat_boundary', 'source_id' => 'wc_cat_autonics', 'slug' => 'autonics'],
    "{$baseUrl}/cam-bien-autonics/"          => ['type' => 'product_cat_boundary', 'source_id' => 'wc_cat_cam_bien_autonics', 'slug' => 'cam-bien-autonics'],
    "{$baseUrl}/cam-bien-vi-tri-autonics/"   => ['type' => 'product_cat_boundary', 'source_id' => 'wc_cat_cam_bien_vi_tri', 'slug' => 'cam-bien-vi-tri-autonics'],
    "{$baseUrl}/product/ee-sx674/"           => ['type' => 'product_single_boundary', 'source_id' => 'wc_prod_ee_sx674', 'slug' => 'ee-sx674'],
    "{$baseUrl}/product/ca-lh4/"             => ['type' => 'product_single_boundary', 'source_id' => 'wc_prod_ca_lh4', 'slug' => 'ca-lh4'],
    "{$baseUrl}/gioi-thieu"                  => ['type' => 'trailing_slash_probe', 'source_id' => 'probe_gioi_thieu', 'slug' => 'gioi-thieu'],
    "{$baseUrl}/lien-he"                     => ['type' => 'trailing_slash_probe', 'source_id' => 'probe_lien_he', 'slug' => 'lien-he'],
    "{$baseUrl}/tin-blog"                    => ['type' => 'trailing_slash_probe', 'source_id' => 'probe_tin_blog', 'slug' => 'tin-blog'],
    "{$baseUrl}/cam-bien-tu-la-gi-cau-tao-nguyen-ly-va-ung-dung" => ['type' => 'trailing_slash_probe', 'source_id' => 'probe_post_root', 'slug' => 'cam-bien-tu-la-gi-cau-tao-nguyen-ly-va-ung-dung'],
    "{$baseUrl}/tag/cam-bien"                => ['type' => 'trailing_slash_probe', 'source_id' => 'probe_tag', 'slug' => 'tag/cam-bien'],
    "{$baseUrl}/product/ee-sx674"            => ['type' => 'trailing_slash_probe', 'source_id' => 'probe_product', 'slug' => 'product/ee-sx674'],
];
foreach ($boundaryUrls as $bUrl => $bMeta) {
    if (!isset($urlsToInspect[$bUrl])) {
        $urlsToInspect[$bUrl] = [
            'type'            => $bMeta['type'],
            'source_id'       => $bMeta['source_id'],
            'slug'            => $bMeta['slug'],
            'parent_id'       => 0,
            'publish_state'   => 'publish',
            'discovered_from' => 'seed_boundary_probe',
        ];
    }
}

$totalInspect = count($urlsToInspect);
echo "  Inspecting {$totalInspect} live URLs sequentially (rate-limited {$delayMs}ms)...\n";

$htmlResults = [];
$idx = 0;
foreach ($urlsToInspect as $u => $meta) {
    $idx++;
    if ($idx % 50 === 0 || $idx === $totalInspect) {
        echo "    Progress: {$idx}/{$totalInspect}\n";
    }
    $trace = fetchWithRedirectTrace($u, $htmlCacheDir, $delayMs, $fromCache, $userAgent, $stats);
    $seo   = parseHtmlSeo($trace['body']);
    $htmlResults[$u] = [
        'trace' => $trace,
        'seo'   => $seo,
        'meta'  => $meta,
    ];
}

// Extract Sitewide Chrome from Homepage and Contact page
$homeHtmlBody    = $htmlResults[$baseUrl . '/']['trace']['body'] ?? '';
$contactHtmlBody = $htmlResults[$baseUrl . '/lien-he/']['trace']['body'] ?? '';
$sitewideChrome  = extractSitewideChrome($homeHtmlBody, $contactHtmlBody);
file_put_contents(
    "{$dataOutDir}/sitewide_chrome.json",
    json_encode($sitewideChrome, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "[5/7] Building URL Inventory, Redirects, Collisions & SEO Baseline CSVs...\n";

// 5.1 source_urls.csv + seo_baseline.csv + redirects.csv
$fpSourceUrls = fopen("{$dataOutDir}/source_urls.csv", 'w');
fputcsv($fpSourceUrls, [
    'source_url', 'type', 'source_id', 'slug', 'parent_id', 'publish_state',
    'original_status', 'final_status', 'final_url', 'redirect_chain',
    'canonical', 'title', 'meta_robots', 'x_robots_tag', 'lastmod',
    'in_sitemap', 'discovered_from', 'verification_state', 'source_snapshot_id'
]);

$fpSeoBaseline = fopen("{$dataOutDir}/seo_baseline.csv", 'w');
fputcsv($fpSeoBaseline, [
    'source_url', 'type', 'source_id', 'final_status', 'title', 'meta_description',
    'canonical', 'meta_robots', 'og_title', 'og_description', 'og_image', 'og_type',
    'h1', 'h1_count', 'schema_types', 'source_snapshot_id'
]);

$fpRedirects = fopen("{$dataOutDir}/redirects.csv", 'w');
fputcsv($fpRedirects, [
    'source_url', 'target_url', 'status_code', 'redirect_chain', 'reason', 'source_snapshot_id'
]);

// Write inspected URLs first
foreach ($htmlResults as $u => $row) {
    $tr   = $row['trace'];
    $seo  = $row['seo'];
    $meta = $row['meta'];
    $inSm = isset($sitemapUrlsMap[$u]) ? $sitemapUrlsMap[$u]['sitemap'] : 'no';
    $lastmod = $sitemapUrlsMap[$u]['lastmod'] ?? '';

    fputcsv($fpSourceUrls, [
        $u,
        $meta['type'],
        $meta['source_id'],
        $meta['slug'],
        $meta['parent_id'],
        $meta['publish_state'],
        $tr['original_status'],
        $tr['final_status'],
        $tr['final_url'],
        $tr['redirect_chain'],
        $seo['canonical'],
        $seo['title'],
        $seo['robots'],
        $tr['headers']['x-robots-tag'] ?? '',
        $lastmod,
        $inSm,
        $meta['discovered_from'],
        $tr['final_status'] > 0 ? 'VERIFIED_HTTP' : 'UNKNOWN',
        $snapshotId,
    ]);

    if ($meta['type'] !== 'trailing_slash_probe') {
        fputcsv($fpSeoBaseline, [
            $u,
            $meta['type'],
            $meta['source_id'],
            $tr['final_status'],
            $seo['title'],
            $seo['description'],
            $seo['canonical'],
            $seo['robots'],
            $seo['og_title'],
            $seo['og_description'],
            $seo['og_image'],
            $seo['og_type'],
            $seo['h1'],
            $seo['h1_count'],
            implode('|', $seo['schema_types']),
            $snapshotId,
        ]);
    }

    if (in_array($tr['original_status'], [301, 302, 303, 307, 308], true)) {
        $reason = $meta['type'] === 'trailing_slash_probe'
            ? 'WordPress canonical trailing-slash normalization'
            : 'Source WordPress/RankMath redirect';
        fputcsv($fpRedirects, [
            $u,
            $tr['final_url'],
            $tr['original_status'],
            $tr['redirect_chain'],
            $reason,
            $snapshotId,
        ]);
    }
}

// Append remaining un-probed Tags (out of 1,288) and all 750 Product Categories + 48 Brands to source_urls.csv
foreach ($wpTags as $tg) {
    $link = $tg['link'];
    if (isset($htmlResults[$link])) continue;
    fputcsv($fpSourceUrls, [
        $link,
        'post_tag',
        (string)$tg['id'],
        (string)$tg['slug'],
        0,
        'publish',
        '',
        '',
        $link,
        '',
        '',
        (string)$tg['name'],
        '',
        '',
        '',
        isset($sitemapUrlsMap[$link]) ? $sitemapUrlsMap[$link]['sitemap'] : 'no',
        'wp_rest_tags',
        'VERIFIED_REST_ONLY',
        $snapshotId,
    ]);
}

foreach ($wpProdCats as $pcat) {
    $link = $pcat['link'];
    if (isset($htmlResults[$link])) continue;
    fputcsv($fpSourceUrls, [
        $link,
        'reserved_product_cat',
        (string)$pcat['id'],
        (string)$pcat['slug'],
        (int)($pcat['parent'] ?? 0),
        'publish',
        '',
        '',
        $link,
        '',
        '',
        (string)$pcat['name'],
        '',
        '',
        $sitemapUrlsMap[$link]['lastmod'] ?? '',
        isset($sitemapUrlsMap[$link]) ? $sitemapUrlsMap[$link]['sitemap'] : 'no',
        'wp_rest_product_cat',
        'VERIFIED_REST_RESERVED_PHASE2',
        $snapshotId,
    ]);
}

foreach ($wpBrands as $br) {
    $link = $br['link'];
    if (isset($htmlResults[$link])) continue;
    fputcsv($fpSourceUrls, [
        $link,
        'reserved_product_brand',
        (string)$br['id'],
        (string)$br['slug'],
        (int)($br['parent'] ?? 0),
        'publish',
        '',
        '',
        $link,
        '',
        '',
        (string)$br['name'],
        '',
        '',
        '',
        'no',
        'wp_rest_thuong_hieu',
        'VERIFIED_REST_RESERVED_PHASE2',
        $snapshotId,
    ]);
}

fclose($fpSourceUrls);
fclose($fpSeoBaseline);
fclose($fpRedirects);

// 5.2 Detect ALL URL & Slug Collisions across Pages, Posts, Post Categories, Tags, Product Categories, Brands!
$pathBuckets = []; // normalized_path => list of entities
$slugBuckets = []; // raw slug => list of entities across all namespaces

$registerCollisionCandidate = function (string $entityType, string $id, string $slug, string $link) use (&$pathBuckets, &$slugBuckets) {
    $path = '/' . trim((string)parse_url($link, PHP_URL_PATH), '/') . '/';
    if ($path === '//') $path = '/';
    $entry = ['type' => $entityType, 'id' => $id, 'slug' => $slug, 'link' => $link, 'path' => $path];
    $pathBuckets[$path][] = $entry;
    if ($slug !== '') {
        $slugBuckets[$slug][] = $entry;
    }
};

foreach ($wpPages as $pg)      $registerCollisionCandidate('page', (string)$pg['id'], (string)$pg['slug'], (string)$pg['link']);
foreach ($wpPosts as $pst)     $registerCollisionCandidate('post', (string)$pst['id'], (string)$pst['slug'], (string)$pst['link']);
foreach ($wpCategories as $c)  $registerCollisionCandidate('post_category', (string)$c['id'], (string)$c['slug'], (string)$c['link']);
foreach ($wpTags as $tg)       $registerCollisionCandidate('post_tag', (string)$tg['id'], (string)$tg['slug'], (string)$tg['link']);
foreach ($wpProdCats as $pc)   $registerCollisionCandidate('product_cat', (string)$pc['id'], (string)$pc['slug'], (string)$pc['link']);
foreach ($wpBrands as $br)     $registerCollisionCandidate('thuong_hieu', (string)$br['id'], (string)$br['slug'], (string)$br['link']);

$fpCollisions = fopen("{$dataOutDir}/url_collisions.csv", 'w');
fputcsv($fpCollisions, [
    'collision_scope', 'key', 'entities_involved', 'severity', 'resolution_policy', 'source_snapshot_id'
]);

$exactPathCollisions = 0;
$crossNamespaceSlugCollisions = 0;

foreach ($pathBuckets as $path => $entries) {
    if (count($entries) > 1) {
        $exactPathCollisions++;
        $desc = implode(' | ', array_map(fn($e) => "{$e['type']}#{$e['id']}({$e['link']})", $entries));
        fputcsv($fpCollisions, [
            'EXACT_URL_PATH',
            $path,
            $desc,
            'P0_CRITICAL',
            'Inspect live HTTP response & WordPress rewrite precedence before routing; block ambiguous overwrite',
            $snapshotId,
        ]);
    }
}

foreach ($slugBuckets as $slug => $entries) {
    if (count($entries) > 1) {
        // Check if already reported as exact path collision
        $uniquePaths = array_unique(array_column($entries, 'path'));
        if (count($uniquePaths) > 1) {
            $crossNamespaceSlugCollisions++;
            $desc = implode(' | ', array_map(fn($e) => "{$e['type']}#{$e['id']}({$e['path']})", $entries));
            fputcsv($fpCollisions, [
                'CROSS_NAMESPACE_SLUG',
                $slug,
                $desc,
                'P1_NAMESPACE_COLLISION',
                'Never use bare slug as global unique key; route by full path (/tag/{slug}/, /thuong_hieu/{slug}/, /{slug}/) and composite unique(type, slug)',
                $snapshotId,
            ]);
        }
    }
}
fclose($fpCollisions);

echo "[6/7] Generating content_manifest.jsonl & media_manifest.csv...\n";

$fpManifest = fopen("{$dataOutDir}/content_manifest.jsonl", 'w');
$mediaRegistry = []; // url => ['url', 'rel_path', 'mime', 'wp_id', 'alt', 'title', 'caption', 'width', 'height', 'referenced_by' => []]

$registerMediaUrl = function (string $url, string $refBy, ?int $wpId = null) use (&$mediaRegistry, $mediaById) {
    $cleanUrl = trim($url);
    if ($cleanUrl === '' || str_starts_with($cleanUrl, 'data:')) return;
    $pathPart = (string)parse_url($cleanUrl, PHP_URL_PATH);
    $relPath = '';
    if (preg_match('#/wp-content/uploads/(.+)$#i', $pathPart, $m)) {
        $relPath = $m[1];
    }
    $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
    $mimeGuess = match ($ext) {
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'avif' => 'image/avif',
        'pdf'  => 'application/pdf',
        'zip'  => 'application/zip',
        'rar'  => 'application/vnd.rar',
        default => 'application/octet-stream',
    };

    if (!isset($mediaRegistry[$cleanUrl])) {
        $mMeta = ($wpId && isset($mediaById[$wpId])) ? $mediaById[$wpId] : null;
        $mediaRegistry[$cleanUrl] = [
            'url'           => $cleanUrl,
            'relative_path' => $relPath,
            'mime'          => $mMeta['mime_type'] ?? $mimeGuess,
            'source_id'     => $wpId ? (string)$wpId : ($mMeta['id'] ?? ''),
            'alt'           => $mMeta['alt_text'] ?? '',
            'title'         => isset($mMeta['title']['rendered']) ? html_entity_decode(strip_tags($mMeta['title']['rendered']), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
            'caption'       => isset($mMeta['caption']['rendered']) ? trim(strip_tags($mMeta['caption']['rendered'])) : '',
            'width'         => $mMeta['media_details']['width'] ?? '',
            'height'        => $mMeta['media_details']['height'] ?? '',
            'referenced_by' => [],
        ];
    } elseif ($wpId && empty($mediaRegistry[$cleanUrl]['source_id'])) {
        $mediaRegistry[$cleanUrl]['source_id'] = (string)$wpId;
    }
    $mediaRegistry[$cleanUrl]['referenced_by'][] = $refBy;
};

// Register site icon & header logos
if (!empty($wpRoot['site_icon_url'])) {
    $registerMediaUrl((string)$wpRoot['site_icon_url'], 'site_icon', (int)($wpRoot['site_icon'] ?? 0));
}
foreach ($sitewideChrome['logos'] as $lg) {
    $registerMediaUrl($lg['src'], 'header_logo');
}

// Helper to write Page/Post manifest lines
$shortcodesFound = [];
$placeholdersFound = [];

foreach (['page' => $wpPages, 'post' => $wpPosts] as $kind => $collection) {
    foreach ($collection as $item) {
        $wpId = (int)$item['id'];
        $link = (string)$item['link'];
        $lookupUrl = (rtrim($link, '/') === rtrim($baseUrl, '/')) ? ($baseUrl . '/') : $link;
        $htmlInfo = $htmlResults[$lookupUrl] ?? null;
        $seo = $htmlInfo['seo'] ?? [];
        $trace = $htmlInfo['trace'] ?? [];

        $renderedHtml = (string)($item['content']['rendered'] ?? '');
        $analysis = analyzeContentAssetsAndShortcodes($renderedHtml);

        if (!empty($analysis['shortcodes'])) {
            $shortcodesFound["{$kind}#{$wpId}({$link})"] = $analysis['shortcodes'];
        }
        if (!empty($analysis['placeholders'])) {
            $placeholdersFound["{$kind}#{$wpId}({$link})"] = $analysis['placeholders'];
        }

        $featMediaId = (int)($item['featured_media'] ?? 0);
        $featMediaUrl = '';
        if ($featMediaId && isset($mediaById[$featMediaId]['source_url'])) {
            $featMediaUrl = (string)$mediaById[$featMediaId]['source_url'];
            $registerMediaUrl($featMediaUrl, "{$kind}:{$wpId}:featured", $featMediaId);
        } elseif (!empty($item['_embedded']['wp:featuredmedia'][0]['source_url'])) {
            $featMediaUrl = (string)$item['_embedded']['wp:featuredmedia'][0]['source_url'];
            $registerMediaUrl($featMediaUrl, "{$kind}:{$wpId}:featured", $featMediaId);
        }

        if (!empty($seo['og_image'])) {
            $registerMediaUrl($seo['og_image'], "{$kind}:{$wpId}:og_image");
        }
        foreach ($analysis['uploads'] as $uUrl) {
            $registerMediaUrl($uUrl, "{$kind}:{$wpId}:body");
        }

        // Author display name (PUBLIC display name only, never private email/login)
        $authorDisplayName = 'Hải Phòng Tech';
        if (!empty($item['_embedded']['author'][0]['name'])) {
            $authorDisplayName = (string)$item['_embedded']['author'][0]['name'];
        }

        $manifestRow = [
            'source_wp_id'        => $wpId,
            'source_type'         => $kind,
            'source_slug'         => (string)$item['slug'],
            'source_permalink'    => $lookupUrl,
            'parent_wp_id'        => (int)($item['parent'] ?? 0),
            'menu_order'          => (int)($item['menu_order'] ?? 0),
            'template'            => (string)($item['template'] ?? ''),
            'status'              => (string)$item['status'],
            'title'               => html_entity_decode((string)($item['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'excerpt_html'        => (string)($item['excerpt']['rendered'] ?? ''),
            'rendered_content'    => $renderedHtml,
            'raw_content_state'   => 'UNKNOWN_WITHOUT_DB_EXPORT',
            'content_hash'        => hash('sha256', $renderedHtml),
            'published_at_local'  => (string)($item['date'] ?? ''),
            'published_at_gmt'    => (string)($item['date_gmt'] ?? ''),
            'modified_at_local'   => (string)($item['modified'] ?? ''),
            'modified_at_gmt'     => (string)($item['modified_gmt'] ?? ''),
            'author_display_name' => $authorDisplayName,
            'category_wp_ids'     => $item['categories'] ?? [],
            'tag_wp_ids'          => $item['tags'] ?? [],
            'featured_media_id'   => $featMediaId,
            'featured_media_url'  => $featMediaUrl,
            'media_urls'          => $analysis['uploads'],
            'shortcodes_detected' => $analysis['shortcodes'],
            'placeholders_flags'  => $analysis['placeholders'],
            'http_status'         => $trace['final_status'] ?? 0,
            'in_sitemap'          => isset($sitemapUrlsMap[$lookupUrl]),
            'seo'                 => $seo,
            'source_snapshot_id'  => $snapshotId,
        ];

        fwrite($fpManifest, json_encode($manifestRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}

// Also write taxonomy manifest lines (post_category & post_tag)
foreach ($wpCategories as $cat) {
    $link = (string)$cat['link'];
    $seo = $htmlResults[$link]['seo'] ?? [];
    fwrite($fpManifest, json_encode([
        'source_wp_id'       => (int)$cat['id'],
        'source_type'        => 'post_category',
        'source_slug'        => (string)$cat['slug'],
        'source_permalink'   => $link,
        'parent_wp_id'       => (int)($cat['parent'] ?? 0),
        'name'               => html_entity_decode((string)$cat['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'description'        => (string)($cat['description'] ?? ''),
        'count'              => (int)($cat['count'] ?? 0),
        'in_sitemap'         => isset($sitemapUrlsMap[$link]),
        'seo'                => $seo,
        'source_snapshot_id' => $snapshotId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

foreach ($wpTags as $tg) {
    $link = (string)$tg['link'];
    $seo = $htmlResults[$link]['seo'] ?? [];
    fwrite($fpManifest, json_encode([
        'source_wp_id'       => (int)$tg['id'],
        'source_type'        => 'post_tag',
        'source_slug'        => (string)$tg['slug'],
        'source_permalink'   => $link,
        'parent_wp_id'       => 0,
        'name'               => html_entity_decode((string)$tg['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'description'        => (string)($tg['description'] ?? ''),
        'count'              => (int)($tg['count'] ?? 0),
        'in_sitemap'         => isset($sitemapUrlsMap[$link]),
        'seo'                => $seo,
        'source_snapshot_id' => $snapshotId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
fclose($fpManifest);

// Write media_manifest.csv
$fpMedia = fopen("{$dataOutDir}/media_manifest.csv", 'w');
fputcsv($fpMedia, [
    'url', 'relative_path', 'sha256', 'mime', 'source_id', 'width', 'height', 'alt', 'title', 'referenced_by', 'source_snapshot_id'
]);
ksort($mediaRegistry);
foreach ($mediaRegistry as $mRow) {
    $refs = implode('|', array_values(array_unique($mRow['referenced_by'])));
    fputcsv($fpMedia, [
        $mRow['url'],
        $mRow['relative_path'],
        'PENDING_DOWNLOAD_VERIFY', // SHA256 computed upon binary download in Gate D
        $mRow['mime'],
        $mRow['source_id'],
        $mRow['width'],
        $mRow['height'],
        $mRow['alt'],
        $mRow['title'],
        $refs,
        $snapshotId,
    ]);
}
fclose($fpMedia);

echo "[7/7] Writing Snapshot Checksums & Summary Metadata...\n";
$stats['completed_at'] = gmdate('c');
$manifestChecksum = hash_file('sha256', "{$dataOutDir}/content_manifest.jsonl");
$urlsChecksum     = hash_file('sha256', "{$dataOutDir}/source_urls.csv");

$summaryReport = [
    'snapshot_id'                     => $snapshotId,
    'started_at'                      => $stats['started_at'],
    'completed_at'                    => $stats['completed_at'],
    'user_agent'                      => $userAgent,
    'network_requests'                => $stats['network_requests'],
    'cache_hits'                      => $stats['cache_hits'],
    'errors'                          => $stats['errors'],
    'wp_site_info'                    => [
        'name'          => $wpRoot['name'] ?? '',
        'description'   => $wpRoot['description'] ?? '',
        'url'           => $wpRoot['url'] ?? '',
        'home'          => $wpRoot['home'] ?? '',
        'gmt_offset'    => $wpRoot['gmt_offset'] ?? '',
        'site_icon_url' => $wpRoot['site_icon_url'] ?? '',
    ],
    'counts'                          => [
        'pages_rest'                  => count($wpPages),
        'pages_sitemap'               => $sitemapSummary['page-sitemap.xml']['count'] ?? 0,
        'posts_rest'                  => count($wpPosts),
        'posts_sitemap'               => $sitemapSummary['post-sitemap.xml']['count'] ?? 0,
        'post_categories_rest'        => count($wpCategories),
        'post_categories_sitemap'     => $sitemapSummary['category-sitemap.xml']['count'] ?? 0,
        'post_tags_rest'              => count($wpTags),
        'product_cat_rest'            => count($wpProdCats),
        'product_cat_sitemap'         => $sitemapSummary['product_cat-sitemap.xml']['count'] ?? 0,
        'product_brands_rest'         => count($wpBrands),
        'product_sitemaps_files'      => $productSitemapsCount,
        'products_in_sitemap_est'     => $estimatedProductsInSitemap,
        'referenced_media_assets'     => count($mediaRegistry),
        'exact_url_collisions'        => $exactPathCollisions,
        'cross_namespace_collisions'  => $crossNamespaceSlugCollisions,
    ],
    'shortcodes_detected'             => $shortcodesFound,
    'placeholders_detected'           => $placeholdersFound,
    'checksums'                       => [
        'content_manifest_sha256'     => $manifestChecksum,
        'source_urls_sha256'          => $urlsChecksum,
        'media_manifest_sha256'       => hash_file('sha256', "{$dataOutDir}/media_manifest.csv"),
        'seo_baseline_sha256'         => hash_file('sha256', "{$dataOutDir}/seo_baseline.csv"),
        'url_collisions_sha256'       => hash_file('sha256', "{$dataOutDir}/url_collisions.csv"),
        'redirects_sha256'            => hash_file('sha256', "{$dataOutDir}/redirects.csv"),
    ],
];

file_put_contents(
    "{$snapshotDir}/snapshot_summary.json",
    json_encode($summaryReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
file_put_contents(
    "{$dataOutDir}/snapshot_summary.json",
    json_encode($summaryReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "DONE! Snapshot summary saved to docs/migration/data/snapshot_summary.json\n";
print_r($summaryReport['counts']);
