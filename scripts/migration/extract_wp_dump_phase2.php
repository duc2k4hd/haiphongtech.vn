<?php
declare(strict_types=1);

/**
 * Hai Phong Tech — Track A Offline Streaming WordPress/WooCommerce SQL & NDJSON Dump Extractor
 *
 * Parses sanitized read-only WordPress database exports (MySQL SQL INSERT dumps OR JSONL/NDJSON exports)
 * covering up to 133,036 products + 255,276 product tags + 750 product categories + 48 brands
 * without issuing HTTP load to production and WITH strict PII table exclusion.
 *
 * PII & Security Enforcement:
 *  - Refuses or skips wp_users, wp_usermeta, wp_comments, wp_commentmeta, wp_woocommerce_order_*,
 *    wp_wc_customer_lookup, wp_sessions, or any private secret/token rows in wp_options.
 *
 * Output directory (ready for `php artisan wp:import-phase2 --source-dir=... --products-file=products.jsonl`):
 *  - product_categories.jsonl
 *  - product_brands.jsonl
 *  - product_tags.jsonl
 *  - products.jsonl (includes RankMath _meta_title / _meta_description / _meta_robots / _canonical_url + sitemap_chunk 1..67)
 *  - track_a_extraction_summary.json
 *
 * Usage:
 *   php scripts/migration/extract_wp_dump_phase2.php --input-dir=path/to/ndjson_or_sql --out-dir=docs/migration/data/phase2_track_a --chunk-size=2000 [--verify-only]
 */

$options = getopt('', [
    'input-sql::',
    'input-dir::',
    'out-dir::',
    'chunk-size::',
    'table-prefix::',
    'site-url::',
    'verify-only',
]);

$inputSql = isset($options['input-sql']) ? (string) $options['input-sql'] : '';
$inputDir = isset($options['input-dir']) ? (string) $options['input-dir'] : '';
$outDir = (string) ($options['out-dir'] ?? (dirname(__DIR__, 2) . '/docs/migration/data/phase2_track_a'));
$sitemapChunkSize = max(100, (int) ($options['chunk-size'] ?? 2000));
$prefix = (string) ($options['table-prefix'] ?? 'wp_');
$siteUrl = rtrim((string) ($options['site-url'] ?? 'https://haiphongtech.vn'), '/');
$verifyOnly = isset($options['verify-only']);

if ($inputSql === '' && $inputDir === '') {
    fwrite(STDERR, "Usage: php extract_wp_dump_phase2.php --input-sql=dump.sql OR --input-dir=exports/ [--out-dir=...] [--chunk-size=2000] [--verify-only]\n");
    exit(2);
}

$forbiddenPiiPatterns = [
    'users',
    'usermeta',
    'comments',
    'commentmeta',
    'woocommerce_order',
    'wc_order',
    'wc_customer',
    'sessions',
];

function isForbiddenPiiTable(string $tableName, array $forbiddenPatterns): bool
{
    $low = strtolower($tableName);
    foreach ($forbiddenPatterns as $pat) {
        if (str_contains($low, $pat)) {
            return true;
        }
    }
    return false;
}

/**
 * Parse a SQL tuple list `(v1, 'str', NULL), (v2, 'str2', 1)` safely handling escaped quotes.
 *
 * @return array<int, array<int, string|null>>
 */
function parseSqlValuesList(string $valuesSegment): array
{
    $rows = [];
    $len = strlen($valuesSegment);
    $i = 0;

    while ($i < $len) {
        // Find opening '('
        while ($i < $len && $valuesSegment[$i] !== '(') {
            $i++;
        }
        if ($i >= $len) {
            break;
        }
        $i++; // skip '('

        $row = [];
        $current = '';
        $inString = false;
        $hasToken = false;

        while ($i < $len) {
            $ch = $valuesSegment[$i];
            if ($inString) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $next = $valuesSegment[$i + 1];
                    $current .= match ($next) {
                        'n'  => "\n",
                        'r'  => "\r",
                        't'  => "\t",
                        '0'  => "\0",
                        default => $next,
                    };
                    $i += 2;
                    continue;
                }
                if ($ch === "'" && $i + 1 < $len && $valuesSegment[$i + 1] === "'") {
                    $current .= "'";
                    $i += 2;
                    continue;
                }
                if ($ch === "'") {
                    $inString = false;
                    $i++;
                    continue;
                }
                $current .= $ch;
                $i++;
                continue;
            }

            if ($ch === "'") {
                $inString = true;
                $hasToken = true;
                $i++;
                continue;
            }
            if ($ch === ',' || $ch === ')') {
                $val = $hasToken ? $current : trim($current);
                $row[] = (!$hasToken && strtoupper($val) === 'NULL') ? null : $val;
                $current = '';
                $hasToken = false;
                $i++;
                if ($ch === ')') {
                    $rows[] = $row;
                    break;
                }
                continue;
            }
            if ($ch !== ' ' && $ch !== "\t" && $ch !== "\r" && $ch !== "\n") {
                $current .= $ch;
            }
            $i++;
        }
    }

    return $rows;
}

function unserializePhpSafe(?string $raw): mixed
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!preg_match('/^(?:a|O|s|i|d|b|N)[:;]/', $raw)) {
        return $raw;
    }
    return @unserialize($raw, ['allowed_classes' => false]);
}

$stats = [
    'skipped_pii_tables' => [],
    'attachments'        => 0,
    'product_categories' => 0,
    'product_brands'     => 0,
    'product_tags'       => 0,
    'products'           => 0,
    'sitemap_chunks'     => 0,
];

$terms = [];          // term_id => ['name'=>..., 'slug'=>...]
$termTaxonomies = []; // tt_id => ['term_id'=>..., 'taxonomy'=>..., 'description'=>..., 'parent'=>..., 'count'=>...]
$termMeta = [];       // term_id => [meta_key => meta_value]
$termRels = [];       // object_id => [tt_id, ...]
$attachments = [];    // ID => guid / attached_file path
$products = [];       // ID => post row
$postMeta = [];       // post_id => [meta_key => meta_value]
$wcLookup = [];       // product_id => ['sku'=>..., 'min_price'=>..., 'stock_status'=>...]

$allowedPostMetaKeys = [
    '_sku'                   => true,
    '_price'                 => true,
    '_regular_price'         => true,
    '_sale_price'            => true,
    '_stock_status'          => true,
    '_thumbnail_id'          => true,
    '_product_image_gallery' => true,
    '_product_attributes'    => true,
    '_wp_attached_file'      => true,
    'rank_math_title'        => true,
    'rank_math_description'  => true,
    'rank_math_robots'       => true,
    'rank_math_canonical_url'=> true,
    'rank_math_focus_keyword'=> true,
];

$allowedTermMetaKeys = [
    'thumbnail_id'           => true,
    'rank_math_title'        => true,
    'rank_math_description'  => true,
    'rank_math_robots'       => true,
    'rank_math_canonical_url'=> true,
];

$ingestTableRow = static function (string $shortTable, array $cols, array $vals) use (
    $forbiddenPiiPatterns,
    $siteUrl,
    $allowedPostMetaKeys,
    $allowedTermMetaKeys,
    &$stats,
    &$terms,
    &$termTaxonomies,
    &$termMeta,
    &$termRels,
    &$attachments,
    &$products,
    &$postMeta,
    &$wcLookup
): void {
    if (isForbiddenPiiTable($shortTable, $forbiddenPiiPatterns)) {
        $stats['skipped_pii_tables'][$shortTable] = true;
        return;
    }

    $assoc = (!empty($cols) && count($cols) === count($vals)) ? array_combine($cols, $vals) : null;

    if ($shortTable === 'terms') {
        $tid = (int) ($assoc ? $assoc['term_id'] : ($vals[0] ?? 0));
        if ($tid > 0) {
            $terms[$tid] = [
                'name' => html_entity_decode((string) ($assoc ? $assoc['name'] : ($vals[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'slug' => (string) ($assoc ? $assoc['slug'] : ($vals[2] ?? '')),
            ];
        }
    } elseif ($shortTable === 'term_taxonomy') {
        $ttId = (int) ($assoc ? $assoc['term_taxonomy_id'] : ($vals[0] ?? 0));
        $tax = (string) ($assoc ? $assoc['taxonomy'] : ($vals[2] ?? ''));
        if ($ttId > 0 && in_array($tax, ['product_cat', 'thuong_hieu', 'product_tag', 'product_type'], true)) {
            $termTaxonomies[$ttId] = [
                'term_id'     => (int) ($assoc ? $assoc['term_id'] : ($vals[1] ?? 0)),
                'taxonomy'    => $tax,
                'description' => (string) ($assoc ? $assoc['description'] : ($vals[3] ?? '')),
                'parent'      => (int) ($assoc ? $assoc['parent'] : ($vals[4] ?? 0)),
                'count'       => (int) ($assoc ? $assoc['count'] : ($vals[5] ?? 0)),
            ];
        }
    } elseif ($shortTable === 'termmeta') {
        $tid = (int) ($assoc ? $assoc['term_id'] : ($vals[1] ?? 0));
        $k = (string) ($assoc ? $assoc['meta_key'] : ($vals[2] ?? ''));
        if ($tid > 0 && isset($allowedTermMetaKeys[$k])) {
            $termMeta[$tid][$k] = $assoc ? $assoc['meta_value'] : ($vals[3] ?? null);
        }
    } elseif ($shortTable === 'term_relationships') {
        $oid = (int) ($assoc ? $assoc['object_id'] : ($vals[0] ?? 0));
        $ttId = (int) ($assoc ? $assoc['term_taxonomy_id'] : ($vals[1] ?? 0));
        if ($oid > 0 && $ttId > 0) {
            $termRels[$oid][] = $ttId;
        }
    } elseif ($shortTable === 'posts') {
        $pid = (int) ($assoc ? $assoc['ID'] : ($vals[0] ?? 0));
        $postType = (string) ($assoc ? $assoc['post_type'] : ($vals[20] ?? ''));
        $postStatus = (string) ($assoc ? $assoc['post_status'] : ($vals[7] ?? ''));
        if ($pid <= 0) return;

        if ($postType === 'attachment') {
            $guid = (string) ($assoc ? $assoc['guid'] : ($vals[18] ?? ''));
            $attachments[$pid] = $guid;
            $stats['attachments']++;
        } elseif ($postType === 'product' && $postStatus === 'publish') {
            $slug = (string) ($assoc ? $assoc['post_name'] : ($vals[11] ?? ''));
            $products[$pid] = [
                'ID'            => $pid,
                'slug'          => $slug,
                'title'         => html_entity_decode((string) ($assoc ? $assoc['post_title'] : ($vals[5] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'content'       => (string) ($assoc ? $assoc['post_content'] : ($vals[4] ?? '')),
                'excerpt'       => (string) ($assoc ? $assoc['post_excerpt'] : ($vals[6] ?? '')),
                'date_gmt'      => (string) ($assoc ? $assoc['post_date_gmt'] : ($vals[3] ?? '')),
                'modified_gmt'  => (string) ($assoc ? $assoc['post_modified_gmt'] : ($vals[15] ?? '')),
                'permalink'     => "{$siteUrl}/product/{$slug}/",
            ];
        }
    } elseif ($shortTable === 'postmeta') {
        $pid = (int) ($assoc ? $assoc['post_id'] : ($vals[1] ?? 0));
        $k = (string) ($assoc ? $assoc['meta_key'] : ($vals[2] ?? ''));
        if ($pid > 0 && isset($allowedPostMetaKeys[$k])) {
            $v = $assoc ? $assoc['meta_value'] : ($vals[3] ?? null);
            $postMeta[$pid][$k] = $v;
            if ($k === '_wp_attached_file' && is_string($v) && $v !== '') {
                $attachments[$pid] = "{$siteUrl}/wp-content/uploads/" . ltrim($v, '/');
            }
        }
    } elseif ($shortTable === 'wc_product_meta_lookup') {
        $pid = (int) ($assoc ? $assoc['product_id'] : ($vals[0] ?? 0));
        if ($pid > 0) {
            $wcLookup[$pid] = [
                'sku'          => $assoc ? ($assoc['sku'] ?? null) : ($vals[1] ?? null),
                'min_price'    => $assoc ? ($assoc['min_price'] ?? null) : ($vals[3] ?? null),
                'stock_status' => $assoc ? ($assoc['stock_status'] ?? 'instock') : ($vals[7] ?? 'instock'),
            ];
        }
    }
};

// Stream SQL dump if --input-sql is set
if ($inputSql !== '') {
    if (!is_file($inputSql)) {
        fwrite(STDERR, "Input SQL file not found: {$inputSql}\n");
        exit(1);
    }
    $fp = fopen($inputSql, 'r');
    $buffer = '';
    while (($line = fgets($fp)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $buffer .= $line;
        if (!str_ends_with(rtrim($trimmed), ';')) {
            continue;
        }

        if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-zA-Z0-9_]+)`?\s*(?:\(([^)]+)\))?\s*VALUES\s*(.+);$/is', trim($buffer), $m)) {
            $tableFull = $m[1];
            $shortTable = str_starts_with($tableFull, $prefix) ? substr($tableFull, strlen($prefix)) : $tableFull;
            $cols = [];
            if (!empty($m[2])) {
                foreach (explode(',', $m[2]) as $colRaw) {
                    $cols[] = trim($colRaw, " \t\n\r`\"");
                }
            }
            $rows = parseSqlValuesList($m[3]);
            foreach ($rows as $rowVals) {
                $ingestTableRow($shortTable, $cols, $rowVals);
            }
        }
        $buffer = '';
    }
    fclose($fp);
}

// Stream NDJSON directory if --input-dir is set
if ($inputDir !== '') {
    if (!is_dir($inputDir)) {
        fwrite(STDERR, "Input directory not found: {$inputDir}\n");
        exit(1);
    }
    foreach (glob($inputDir . '/*.jsonl') as $file) {
        $shortTable = basename($file, '.jsonl');
        $shortTable = str_starts_with($shortTable, $prefix) ? substr($shortTable, strlen($prefix)) : $shortTable;
        $fp = fopen($file, 'r');
        while (($line = fgets($fp)) !== false) {
            $tr = trim($line);
            if ($tr === '') continue;
            $rec = json_decode($tr, true);
            if (is_array($rec)) {
                $ingestTableRow($shortTable, array_keys($rec), array_values($rec));
            }
        }
        fclose($fp);
    }
}

$termByTaxTermId = [];
foreach ($termTaxonomies as $ttId => $tt) {
    $tid = $tt['term_id'];
    if (!isset($terms[$tid])) continue;
    $tInfo = $terms[$tid];
    $tm = $termMeta[$tid] ?? [];
    $robotsRaw = unserializePhpSafe($tm['rank_math_robots'] ?? null);
    $robotsStr = is_array($robotsRaw) ? implode(', ', $robotsRaw) : (is_string($robotsRaw) ? $robotsRaw : null);

    $normTermPath = match ($tt['taxonomy']) {
        'product_cat' => '/' . $tInfo['slug'] . '/',
        'thuong_hieu' => '/thuong_hieu/' . $tInfo['slug'] . '/',
        'product_tag' => '/tag-san-pham/' . $tInfo['slug'] . '/',
        default       => '/' . $tInfo['slug'] . '/',
    };

    $seoPayload = [
        'meta_title'       => !empty($tm['rank_math_title']) ? (string) $tm['rank_math_title'] : null,
        'meta_description' => !empty($tm['rank_math_description']) ? (string) $tm['rank_math_description'] : null,
        'meta_robots'      => $robotsStr ?: ($tt['taxonomy'] === 'product_tag' ? 'noindex, follow' : null),
        'canonical_url'    => !empty($tm['rank_math_canonical_url']) ? (string) $tm['rank_math_canonical_url'] : ($tt['taxonomy'] === 'product_tag' ? null : ($siteUrl . $normTermPath)),
    ];

    $termByTaxTermId[$ttId] = [
        'tt_id'            => $ttId,
        'source_wp_id'     => $tid,
        'source_taxonomy'  => $tt['taxonomy'],
        'slug'             => $tInfo['slug'],
        'name'             => $tInfo['name'],
        'parent_wp_id'     => $tt['parent'],
        'count'            => $tt['count'],
        'source_permalink' => $siteUrl . $normTermPath,
        'description_html' => $tt['description'],
        'thumbnail_wp_id'  => !empty($tm['thumbnail_id']) ? (int) $tm['thumbnail_id'] : 0,
        'seo'              => $seoPayload,
    ];
}

// Map parent term_taxonomy_id -> parent term_id for product categories cleanly
$parentTermLookupByTtId = [];
foreach ($termByTaxTermId as $ttId => $item) {
    $parentTermLookupByTtId[$item['source_wp_id']] = true;
}

if (!$verifyOnly && !is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output dir: {$outDir}\n");
    exit(1);
}

$catFp = !$verifyOnly ? fopen("{$outDir}/product_categories.jsonl", 'w') : null;
$brandFp = !$verifyOnly ? fopen("{$outDir}/product_brands.jsonl", 'w') : null;
$tagFp = !$verifyOnly ? fopen("{$outDir}/product_tags.jsonl", 'w') : null;
$prodFp = !$verifyOnly ? fopen("{$outDir}/products.jsonl", 'w') : null;

foreach ($termByTaxTermId as $item) {
    $thumbUrl = ($item['thumbnail_wp_id'] > 0 && isset($attachments[$item['thumbnail_wp_id']]))
        ? $attachments[$item['thumbnail_wp_id']]
        : null;

    if ($item['source_taxonomy'] === 'product_cat') {
        $stats['product_categories']++;
        if ($catFp) {
            fwrite($catFp, json_encode([
                'source_wp_id'     => $item['source_wp_id'],
                'source_taxonomy'  => 'product_cat',
                'slug'             => $item['slug'],
                'name'             => $item['name'],
                'parent_wp_id'     => $item['parent_wp_id'],
                'count'            => $item['count'],
                'source_permalink' => $item['source_permalink'],
                'description_html' => $item['description_html'],
                'image_url'        => $thumbUrl,
                'seo'              => $item['seo'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
    } elseif ($item['source_taxonomy'] === 'thuong_hieu') {
        $stats['product_brands']++;
        if ($brandFp) {
            fwrite($brandFp, json_encode([
                'source_wp_id'     => $item['source_wp_id'],
                'source_taxonomy'  => 'thuong_hieu',
                'slug'             => $item['slug'],
                'name'             => $item['name'],
                'parent_wp_id'     => $item['parent_wp_id'],
                'count'            => $item['count'],
                'source_permalink' => $item['source_permalink'],
                'description_html' => $item['description_html'],
                'seo'              => $item['seo'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
    } elseif ($item['source_taxonomy'] === 'product_tag') {
        $stats['product_tags']++;
        if ($tagFp) {
            fwrite($tagFp, json_encode([
                'source_wp_id'          => $item['source_wp_id'],
                'slug'                  => $item['slug'],
                'name'                  => $item['name'],
                'description'           => $item['description_html'],
                'products_count_source' => $item['count'],
                'source_permalink'      => $item['source_permalink'],
                'seo'                   => $item['seo'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
}

ksort($products);
$prodIdx = 0;
foreach ($products as $pid => $pRow) {
    $pm = $postMeta[$pid] ?? [];
    $lkp = $wcLookup[$pid] ?? [];

    $catIds = [];
    $brandIds = [];
    $tagIds = [];
    $productType = 'simple';

    foreach (($termRels[$pid] ?? []) as $ttId) {
        if (!isset($termTaxonomies[$ttId])) continue;
        $tt = $termTaxonomies[$ttId];
        $tid = $tt['term_id'];
        if ($tt['taxonomy'] === 'product_cat') {
            $catIds[] = $tid;
        } elseif ($tt['taxonomy'] === 'thuong_hieu') {
            $brandIds[] = $tid;
        } elseif ($tt['taxonomy'] === 'product_tag') {
            $tagIds[] = $tid;
        } elseif ($tt['taxonomy'] === 'product_type' && isset($terms[$tid]['slug'])) {
            $productType = $terms[$tid]['slug'];
        }
    }

    $images = [];
    $thumbId = !empty($pm['_thumbnail_id']) ? (int) $pm['_thumbnail_id'] : 0;
    if ($thumbId > 0 && isset($attachments[$thumbId])) {
        $images[] = ['id' => $thumbId, 'src' => $attachments[$thumbId], 'name' => $pRow['title'], 'alt' => $pRow['title']];
    }
    if (!empty($pm['_product_image_gallery'])) {
        foreach (array_filter(explode(',', (string) $pm['_product_image_gallery'])) as $galIdRaw) {
            $galId = (int) trim($galIdRaw);
            if ($galId > 0 && $galId !== $thumbId && isset($attachments[$galId])) {
                $images[] = ['id' => $galId, 'src' => $attachments[$galId], 'name' => $pRow['title'], 'alt' => $pRow['title']];
            }
        }
    }

    $regPrice = isset($pm['_regular_price']) && $pm['_regular_price'] !== '' ? (float) $pm['_regular_price'] : null;
    $salePrice = isset($pm['_sale_price']) && $pm['_sale_price'] !== '' ? (float) $pm['_sale_price'] : null;
    $price = isset($pm['_price']) && $pm['_price'] !== ''
        ? (float) $pm['_price']
        : ($lkp['min_price'] !== null ? (float) $lkp['min_price'] : ($regPrice ?? 0.0));

    $robotsRaw = unserializePhpSafe($pm['rank_math_robots'] ?? null);
    $robotsStr = is_array($robotsRaw) ? implode(', ', $robotsRaw) : (is_string($robotsRaw) ? $robotsRaw : null);
    $attrsRaw = unserializePhpSafe($pm['_product_attributes'] ?? null);

    $chunkNum = (int) floor($prodIdx / $sitemapChunkSize) + 1;
    $stats['sitemap_chunks'] = max($stats['sitemap_chunks'], $chunkNum);
    $stats['products']++;
    $prodIdx++;

    if ($prodFp) {
        fwrite($prodFp, json_encode([
            'source_wp_id'          => $pid,
            'slug'                  => $pRow['slug'],
            'name'                  => $pRow['title'],
            'sku'                   => !empty($pm['_sku']) ? (string) $pm['_sku'] : (!empty($lkp['sku']) ? (string) $lkp['sku'] : null),
            'product_type'          => $productType,
            'source_permalink'      => $pRow['permalink'],
            'price'                 => $price,
            'regular_price'         => $regPrice ?? $price,
            'sale_price'            => $salePrice,
            'is_in_stock'           => (($pm['_stock_status'] ?? $lkp['stock_status'] ?? 'instock') !== 'outofstock'),
            'short_description_raw' => $pRow['excerpt'],
            'content_raw'           => $pRow['content'],
            'product_cat_wp_ids'    => array_values(array_unique($catIds)),
            'thuong_hieu_wp_ids'    => array_values(array_unique($brandIds)),
            'product_tag_wp_ids'    => array_values(array_unique($tagIds)),
            'images'                => $images,
            'attributes'            => is_array($attrsRaw) ? $attrsRaw : [],
            'sitemap_chunk'         => $chunkNum,
            'published_at_gmt'      => $pRow['date_gmt'],
            'modified_at_gmt'       => $pRow['modified_gmt'],
            'seo'                   => [
                'meta_title'       => !empty($pm['rank_math_title']) ? (string) $pm['rank_math_title'] : null,
                'meta_description' => !empty($pm['rank_math_description']) ? (string) $pm['rank_math_description'] : null,
                'meta_robots'      => $robotsStr,
                'canonical_url'    => !empty($pm['rank_math_canonical_url']) ? (string) $pm['rank_math_canonical_url'] : $pRow['permalink'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}

foreach ([$catFp, $brandFp, $tagFp, $prodFp] as $handle) {
    if ($handle) fclose($handle);
}

$stats['skipped_pii_tables'] = array_keys($stats['skipped_pii_tables']);
if (!$verifyOnly) {
    file_put_contents("{$outDir}/track_a_extraction_summary.json", json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
