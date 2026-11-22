<?php

namespace App\Services\Migration;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\MigrationRun;
use App\Models\Product;
use App\Models\ProductTag;
use App\Models\SourceUrlRoute;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

class WpSqlProductImportService
{
    private const ALLOWED_DOC_EXTENSIONS = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];

    private const ALLOWED_TAXONOMIES = [
        'product_cat' => true,
        'thuong_hieu' => true,
        'product_tag' => true,
    ];

    private const NEEDED_POST_META = [
        '_wp_attached_file'             => true,
        '_wp_attachment_image_alt'      => true,
        '_thumbnail_id'                 => true,
        '_product_image_gallery'        => true,
        '_sku'                          => true,
        '_price'                        => true,
        '_regular_price'                => true,
        '_sale_price'                   => true,
        '_stock_status'                 => true,
        'link_pdf_product'              => true,
        'file_tai_lieu'                 => true,
        'rank_math_title'               => true,
        'rank_math_description'         => true,
        'rank_math_robots'              => true,
        'rank_math_canonical_url'       => true,
        'rank_math_primary_product_cat' => true,
    ];

    private const NEEDED_TERM_META = [
        'thumbnail_id'           => true,
        'avatar'                 => true,
        'danh_muc_con'           => true,
        'rank_math_title'        => true,
        'rank_math_description'  => true,
        'rank_math_robots'       => true,
        'rank_math_canonical_url'=> true,
    ];

    private const BRAND_CATEGORY_SLUG_ALIASES = [
        'phan-phoi-mitsubishi-electric-vietnam' => 'mitsubishi',
    ];

    public function __construct(
        private readonly StagingBackupService $backupService,
        private readonly WpHtmlNormalizer $normalizer
    ) {
    }

    /**
     * Stream WordPress SQL dump into a compact indexed SQLite staging database,
     * stripping all junk tables, post types, revisions, orders, and bloated meta keys.
     */
    public function buildStageSqlite(string $sqlPath, string $sqlitePath, bool $forceRebuild = false, ?callable $log = null): array
    {
        if (!is_file($sqlPath)) {
            throw new RuntimeException("SQL file not found: {$sqlPath}");
        }

        File::ensureDirectoryExists(dirname($sqlitePath));

        $metaFile = $sqlitePath . '.meta.json';
        $sig = filesize($sqlPath) . ':' . filemtime($sqlPath);
        if (!$forceRebuild && is_file($sqlitePath) && is_file($metaFile)) {
            $cached = json_decode((string) file_get_contents($metaFile), true);
            if (($cached['source_sig'] ?? null) === $sig) {
                $log && $log("Reusing cached SQLite stage index: {$sqlitePath}");
                return $cached;
            }
        }

        @unlink($sqlitePath);

        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA temp_store=MEMORY; PRAGMA cache_size=-131072;');

        $pdo->exec('
            CREATE TABLE wp_products (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                post_content TEXT,
                post_excerpt TEXT,
                date_gmt TEXT
            );
            CREATE TABLE wp_attachments (
                id INTEGER PRIMARY KEY,
                title TEXT,
                guid TEXT,
                mime_type TEXT,
                attached_file TEXT,
                alt TEXT
            );
            CREATE TABLE wp_postmeta (
                post_id INTEGER NOT NULL,
                k TEXT NOT NULL,
                v TEXT
            );
            CREATE TABLE wp_terms (
                term_id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT NOT NULL
            );
            CREATE TABLE wp_term_taxonomy (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER NOT NULL,
                taxonomy TEXT NOT NULL,
                description TEXT,
                parent INTEGER NOT NULL DEFAULT 0,
                count INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE wp_termmeta (
                term_id INTEGER NOT NULL,
                k TEXT NOT NULL,
                v TEXT
            );
            CREATE TABLE wp_term_relationships (
                object_id INTEGER NOT NULL,
                term_taxonomy_id INTEGER NOT NULL
            );
            CREATE TABLE wp_wc_lookup (
                product_id INTEGER PRIMARY KEY,
                sku TEXT,
                min_price REAL,
                max_price REAL,
                stock_status TEXT
            );
            CREATE TABLE wp_ddl (
                tbl TEXT PRIMARY KEY,
                ddl TEXT NOT NULL
            );
        ');

        $stmtDdl = $pdo->prepare('INSERT OR REPLACE INTO wp_ddl VALUES (?, ?)');
        $stmtProd = $pdo->prepare('INSERT OR REPLACE INTO wp_products VALUES (?, ?, ?, ?, ?, ?)');
        $stmtAtt = $pdo->prepare('INSERT OR REPLACE INTO wp_attachments VALUES (?, ?, ?, ?, NULL, NULL)');
        $stmtPm = $pdo->prepare('INSERT INTO wp_postmeta VALUES (?, ?, ?)');
        $stmtTerm = $pdo->prepare('INSERT OR REPLACE INTO wp_terms VALUES (?, ?, ?)');
        $stmtTt = $pdo->prepare('INSERT OR REPLACE INTO wp_term_taxonomy VALUES (?, ?, ?, ?, ?, ?)');
        $stmtTm = $pdo->prepare('INSERT INTO wp_termmeta VALUES (?, ?, ?)');
        $stmtTr = $pdo->prepare('INSERT INTO wp_term_relationships VALUES (?, ?)');
        $stmtWc = $pdo->prepare('INSERT OR REPLACE INTO wp_wc_lookup VALUES (?, ?, ?, ?, ?)');

        $stats = [
            'source_sig'                => $sig,
            'source_bytes'              => filesize($sqlPath),
            'products_publish'          => 0,
            'attachments'               => 0,
            'postmeta_kept'             => 0,
            'postmeta_skipped'          => 0,
            'terms'                     => 0,
            'term_taxonomies_kept'      => 0,
            'term_relationships_raw'    => 0,
            'wc_lookup'                 => 0,
        ];

        $metaKeyRegex = '/^\(\d+,\s*(\d+),\s*\'([^\']+)\'/';
        $termMetaKeyRegex = '/^\(\d+,\s*(\d+),\s*\'([^\']+)\'/';

        $fp = fopen($sqlPath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Unable to open {$sqlPath}");
        }

        $pdo->beginTransaction();
        $txOps = 0;
        $flushTx = function () use ($pdo, &$txOps) {
            if ($txOps >= 20000) {
                $pdo->commit();
                $pdo->beginTransaction();
                $txOps = 0;
            }
        };

        $curTable = '';
        $inCreate = false;
        $createBuf = '';
        $lineCount = 0;

        while (($line = fgets($fp)) !== false) {
            $lineCount++;
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '-' || $trim[0] === '/' || str_starts_with($trim, 'ALTER ') || str_starts_with($trim, 'SET ') || str_starts_with($trim, 'START ') || str_starts_with($trim, 'COMMIT')) {
                continue;
            }

            if (preg_match('/^CREATE TABLE\s+`([^`]+)`/i', $trim, $m)) {
                $curTable = $m[1];
                $inCreate = true;
                $createBuf = $line;
                continue;
            }
            if ($inCreate) {
                $createBuf .= $line;
                if (str_starts_with($trim, ')')) {
                    $stmtDdl->execute([$curTable, $createBuf]);
                    $inCreate = false;
                    $curTable = '';
                    $createBuf = '';
                }
                continue;
            }

            if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`([^`]+)`/i', $trim, $m)) {
                $curTable = $m[1];
                $pos = stripos($trim, 'VALUES');
                $rest = $pos !== false ? trim(substr($trim, $pos + 6)) : '';
                if ($rest !== '' && $rest[0] === '(') {
                    $line = $rest;
                    $trim = $rest;
                } else {
                    continue;
                }
            }

            if ($curTable === '' || $trim === '' || $trim[0] !== '(') {
                continue;
            }

            if ($curTable === 'wp_postmeta' && !str_contains($trim, '),(')) {
                if (preg_match($metaKeyRegex, $trim, $km)) {
                    if (!isset(self::NEEDED_POST_META[$km[2]])) {
                        $stats['postmeta_skipped']++;
                        continue;
                    }
                }
            }

            $tuples = $this->parseSqlTuplesOnLine($line);
            foreach ($tuples as $row) {
                if ($curTable === 'wp_postmeta') {
                    // Fast single-tuple check was skipped if multi-tuple; check meta key directly
                    $mk = (string) ($row[2] ?? '');
                    if (!isset(self::NEEDED_POST_META[$mk])) {
                        $stats['postmeta_skipped']++;
                        continue;
                    }
                    $mv = (string) ($row[3] ?? '');
                    if ($mv === '') {
                        $stats['postmeta_skipped']++;
                        continue;
                    }
                    $stmtPm->execute([(int) $row[1], $mk, $mv]);
                    $stats['postmeta_kept']++;
                    $txOps++;
                } elseif ($curTable === 'wp_posts') {
                    if (count($row) < 22) continue;
                    $pt = (string) $row[20];
                    $ps = (string) $row[7];
                    if ($pt === 'product' && $ps === 'publish') {
                        $stmtProd->execute([
                            (int) $row[0],
                            (string) $row[11],
                            $this->decodeText((string) $row[5]),
                            (string) $row[4],
                            (string) $row[6],
                            (string) $row[3],
                        ]);
                        $stats['products_publish']++;
                        $txOps++;
                    } elseif ($pt === 'attachment') {
                        $stmtAtt->execute([
                            (int) $row[0],
                            $this->decodeText((string) $row[5]),
                            (string) $row[18],
                            (string) $row[21],
                        ]);
                        $stats['attachments']++;
                        $txOps++;
                    }
                } elseif ($curTable === 'wp_terms') {
                    if (count($row) < 3) continue;
                    $stmtTerm->execute([(int) $row[0], $this->decodeText((string) $row[1]), (string) $row[2]]);
                    $stats['terms']++;
                    $txOps++;
                } elseif ($curTable === 'wp_term_taxonomy') {
                    if (count($row) < 6) continue;
                    $tax = (string) $row[2];
                    if (!isset(self::ALLOWED_TAXONOMIES[$tax])) continue;
                    $stmtTt->execute([
                        (int) $row[0],
                        (int) $row[1],
                        $tax,
                        (string) $row[3],
                        (int) $row[4],
                        (int) $row[5],
                    ]);
                    $stats['term_taxonomies_kept']++;
                    $txOps++;
                } elseif ($curTable === 'wp_termmeta') {
                    if (count($row) < 4) continue;
                    $mk = (string) ($row[2] ?? '');
                    if (!isset(self::NEEDED_TERM_META[$mk])) continue;
                    $mv = (string) ($row[3] ?? '');
                    if ($mv === '') continue;
                    $stmtTm->execute([(int) $row[1], $mk, $mv]);
                    $txOps++;
                } elseif ($curTable === 'wp_term_relationships') {
                    if (count($row) < 2) continue;
                    $stmtTr->execute([(int) $row[0], (int) $row[1]]);
                    $stats['term_relationships_raw']++;
                    $txOps++;
                } elseif ($curTable === 'wp_wc_product_meta_lookup') {
                    if (count($row) < 9) continue;
                    $stmtWc->execute([
                        (int) $row[0],
                        (string) ($row[1] ?? ''),
                        $row[4] !== null ? (float) $row[4] : null,
                        $row[5] !== null ? (float) $row[5] : null,
                        (string) ($row[8] ?? 'instock'),
                    ]);
                    $stats['wc_lookup']++;
                    $txOps++;
                }
            }

            $flushTx();
            if ($log && ($lineCount % 1000000 === 0)) {
                $log("  Parsed " . number_format($lineCount) . " SQL lines...");
            }
        }
        fclose($fp);
        $pdo->commit();

        // Optimize SQLite indexes & prune orphan rows inside SQLite
        $log && $log('Indexing & pruning orphan metadata inside SQLite stage...');
        $pdo->exec('CREATE INDEX idx_pm_k_pid ON wp_postmeta(k, post_id);');

        // Merge attachment file + alt directly onto wp_attachments for O(1) joins
        $pdo->exec("
            UPDATE wp_attachments
               SET attached_file = (
                   SELECT v FROM wp_postmeta
                   WHERE wp_postmeta.post_id = wp_attachments.id AND wp_postmeta.k = '_wp_attached_file'
                   LIMIT 1
               ),
               alt = (
                   SELECT v FROM wp_postmeta
                   WHERE wp_postmeta.post_id = wp_attachments.id AND wp_postmeta.k = '_wp_attachment_image_alt'
                   LIMIT 1
               );
        ");

        // Drop postmeta rows NOT belonging to published products (including attachment meta now merged)
        $pdo->exec('DELETE FROM wp_postmeta WHERE post_id NOT IN (SELECT id FROM wp_products);');
        $pdo->exec('DROP INDEX idx_pm_k_pid;');
        $pdo->exec('CREATE INDEX idx_pm_pid ON wp_postmeta(post_id);');

        // Prune term relationships not linking published products or kept taxonomies
        $pdo->exec('DELETE FROM wp_term_relationships WHERE object_id NOT IN (SELECT id FROM wp_products);');
        $pdo->exec('DELETE FROM wp_term_relationships WHERE term_taxonomy_id NOT IN (SELECT term_taxonomy_id FROM wp_term_taxonomy);');
        $pdo->exec('CREATE INDEX idx_tr_obj ON wp_term_relationships(object_id);');
        $pdo->exec('CREATE INDEX idx_tm_tid ON wp_termmeta(term_id);');
        $pdo->exec('CREATE INDEX idx_tt_tax ON wp_term_taxonomy(taxonomy, term_id);');

        // Prune non-product terms (keeping only terms belonging to kept taxonomies)
        $pdo->exec('DELETE FROM wp_terms WHERE term_id NOT IN (SELECT term_id FROM wp_term_taxonomy);');
        $pdo->exec('DELETE FROM wp_termmeta WHERE term_id NOT IN (SELECT term_id FROM wp_term_taxonomy);');

        $stats['postmeta_final_products'] = (int) $pdo->query('SELECT COUNT(*) FROM wp_postmeta')->fetchColumn();
        $stats['term_relationships_final'] = (int) $pdo->query('SELECT COUNT(*) FROM wp_term_relationships')->fetchColumn();
        $stats['built_at'] = Carbon::now('UTC')->toIso8601String();

        File::put($metaFile, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $stats;
    }

    /**
     * Write a clean lightweight SQL dump containing only essential product/taxonomy/attachment data.
     */
    public function exportPrunedSql(string $sqlitePath, string $outSqlPath, ?callable $log = null): array
    {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        File::ensureDirectoryExists(dirname($outSqlPath));

        $fp = fopen($outSqlPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Cannot write pruned SQL to {$outSqlPath}");
        }

        fwrite($fp, "-- Pruned Hai Phong Tech WooCommerce Product Dump\n");
        fwrite($fp, "-- Generated: " . Carbon::now('UTC')->toIso8601String() . "\n");
        fwrite($fp, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n\n");

        $counts = [];
        $quote = static function (mixed $v) use ($pdo): string {
            if ($v === null) return 'NULL';
            if (is_int($v) || is_float($v)) return (string) $v;
            return $pdo->quote((string) $v);
        };

        $ddls = $pdo->query('SELECT tbl, ddl FROM wp_ddl')->fetchAll(PDO::FETCH_KEY_PAIR);

        // 1. Terms
        if (!empty($ddls['wp_terms'])) fwrite($fp, $ddls['wp_terms'] . "\n");
        $counts['wp_terms'] = $this->streamSqliteToSqlInsert($fp, $pdo, 'SELECT term_id, name, slug, 0 as term_group FROM wp_terms ORDER BY term_id', 'wp_terms', ['term_id','name','slug','term_group'], $quote);

        // 2. Term Taxonomy
        if (!empty($ddls['wp_term_taxonomy'])) fwrite($fp, $ddls['wp_term_taxonomy'] . "\n");
        $counts['wp_term_taxonomy'] = $this->streamSqliteToSqlInsert($fp, $pdo, 'SELECT term_taxonomy_id, term_id, taxonomy, description, parent, count FROM wp_term_taxonomy ORDER BY term_taxonomy_id', 'wp_term_taxonomy', ['term_taxonomy_id','term_id','taxonomy','description','parent','count'], $quote);

        // 3. Term Meta
        if (!empty($ddls['wp_termmeta'])) fwrite($fp, $ddls['wp_termmeta'] . "\n");
        $counts['wp_termmeta'] = $this->streamSqliteToSqlInsert($fp, $pdo, 'SELECT rowid as meta_id, term_id, k as meta_key, v as meta_value FROM wp_termmeta', 'wp_termmeta', ['meta_id','term_id','meta_key','meta_value'], $quote);

        // 4. Clean Products (cleaned HTML content & filtered attachments only!)
        fwrite($fp, "CREATE TABLE IF NOT EXISTS `wp_posts` (`ID` bigint(20) unsigned NOT NULL PRIMARY KEY, `post_date_gmt` datetime NOT NULL, `post_content` longtext NOT NULL, `post_title` text NOT NULL, `post_excerpt` text NOT NULL, `post_status` varchar(20) NOT NULL, `post_name` varchar(200) NOT NULL, `guid` varchar(255) NOT NULL, `post_type` varchar(20) NOT NULL, `post_mime_type` varchar(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n");
        $stmtP = $pdo->query("SELECT id, date_gmt, post_content, title, post_excerpt, slug FROM wp_products ORDER BY id");
        $batch = [];
        $prodOut = 0;
        while ($row = $stmtP->fetch(PDO::FETCH_ASSOC)) {
            $cleanedContent = $this->normalizer->cleanProductContentHtml((string) ($row['post_content'] ?? ''));
            $cleanedExcerpt = $this->normalizer->cleanProductShortDescription((string) ($row['post_excerpt'] ?? ''));
            $batch[] = '(' . implode(',', [
                (int) $row['id'],
                $quote($row['date_gmt']),
                $quote($cleanedContent),
                $quote($row['title']),
                $quote($cleanedExcerpt),
                "'publish'",
                $quote($row['slug']),
                $quote('https://haiphongtech.vn/product/' . $row['slug'] . '/'),
                "'product'",
                "''",
            ]) . ')';
            $prodOut++;
            if (count($batch) >= 200) {
                fwrite($fp, "INSERT INTO `wp_posts` (`ID`,`post_date_gmt`,`post_content`,`post_title`,`post_excerpt`,`post_status`,`post_name`,`guid`,`post_type`,`post_mime_type`) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if (!empty($batch)) {
            fwrite($fp, "INSERT INTO `wp_posts` (`ID`,`post_date_gmt`,`post_content`,`post_title`,`post_excerpt`,`post_status`,`post_name`,`guid`,`post_type`,`post_mime_type`) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $counts['wp_posts_clean_products'] = $prodOut;

        // 5. Post Meta (excluding Google Drive links)
        if (!empty($ddls['wp_postmeta'])) fwrite($fp, $ddls['wp_postmeta'] . "\n");
        $counts['wp_postmeta'] = $this->streamSqliteToSqlInsert(
            $fp,
            $pdo,
            "SELECT rowid as meta_id, post_id, k as meta_key, v as meta_value FROM wp_postmeta WHERE NOT (k = 'link_pdf_product' AND (LOWER(v) LIKE '%drive.google.com%' OR LOWER(v) LIKE '%docs.google.com%'))",
            'wp_postmeta',
            ['meta_id','post_id','meta_key','meta_value'],
            $quote
        );

        // 6. Term Relationships & Lookup
        if (!empty($ddls['wp_term_relationships'])) fwrite($fp, $ddls['wp_term_relationships'] . "\n");
        $counts['wp_term_relationships'] = $this->streamSqliteToSqlInsert($fp, $pdo, 'SELECT object_id, term_taxonomy_id, 0 as term_order FROM wp_term_relationships', 'wp_term_relationships', ['object_id','term_taxonomy_id','term_order'], $quote);

        if (!empty($ddls['wp_wc_product_meta_lookup'])) fwrite($fp, $ddls['wp_wc_product_meta_lookup'] . "\n");
        $counts['wp_wc_product_meta_lookup'] = $this->streamSqliteToSqlInsert($fp, $pdo, 'SELECT product_id, sku, min_price, max_price, stock_status FROM wp_wc_lookup', 'wp_wc_product_meta_lookup', ['product_id','sku','min_price','max_price','stock_status'], $quote);

        fwrite($fp, "\nCOMMIT;\n");
        fclose($fp);

        $log && $log("Pruned SQL written -> {$outSqlPath} (" . round(filesize($outSqlPath) / 1048576, 2) . ' MB)');
        return $counts;
    }

    /**
     * Full Staging Execution:
     * 1) Assert safe staging target & create pre-purge backup checkpoint
     * 2) Optionally purge old products & product-only media files/records (preserving Phase 1 Blogs/Pages/Logos)
     * 3) Import 48 Brands, 750 Product Categories, 255,276 Tags, 133,036 Cleaned Products, Images & Deduped Catalogs
     */
    public function import(
        string $sqlPath,
        bool $apply = false,
        bool $purge = false,
        bool $rebuildIndex = false,
        int $chunkSize = 1000,
        ?callable $log = null
    ): array {
        $this->backupService->assertSafeStagingTarget();
        DB::connection()->disableQueryLog();
        $startedAt = Carbon::now('UTC');
        $dbName = (string) config('database.connections.' . config('database.default') . '.database');
        $runId = 'run_' . Carbon::now('UTC')->format('Ymd_His') . ($apply ? '_full_sql_apply' : '_full_sql_dryrun');

        $sqlitePath = storage_path('app/migration/hptech_products_stage.sqlite');
        $stageStats = $this->buildStageSqlite($sqlPath, $sqlitePath, $rebuildIndex, $log);

        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $backupId = null;
        if ($apply) {
            $bkp = $this->backupService->createBackup('pre_' . $runId);
            $backupId = $bkp['backup_id'] ?? null;
            $log && $log("Checkpoint backup created: {$backupId}");
        }

        $counters = [
            'purged_products'      => 0,
            'purged_media_rows'    => 0,
            'purged_media_files'   => 0,
            'brands'               => 0,
            'categories'           => 0,
            'product_tags'         => 0,
            'products'             => 0,
            'products_brand_direct'=> 0,
            'products_brand_cat'   => 0,
            'products_unbranded'   => 0,
            'category_links'       => 0,
            'tag_links'            => 0,
            'media_images'         => 0,
            'media_catalogs_dedup' => 0,
            'catalog_links'        => 0,
            'catalog_drive_dropped'=> 0,
        ];

        $seoBaseline = $this->loadSeoBaselineMap();

        if (!$apply) {
            $counters['products'] = (int) $stageStats['products_publish'];
            $counters['brands'] = (int) $pdo->query("SELECT COUNT(*) FROM wp_term_taxonomy WHERE taxonomy='thuong_hieu'")->fetchColumn();
            $counters['categories'] = (int) $pdo->query("SELECT COUNT(*) FROM wp_term_taxonomy WHERE taxonomy='product_cat'")->fetchColumn();
            $counters['product_tags'] = (int) $pdo->query("SELECT COUNT(*) FROM wp_term_taxonomy WHERE taxonomy='product_tag'")->fetchColumn();
            $counters['catalog_drive_dropped'] = (int) $pdo->query("SELECT COUNT(*) FROM wp_postmeta WHERE k='link_pdf_product' AND (LOWER(v) LIKE '%drive.google.com%' OR LOWER(v) LIKE '%docs.google.com%')")->fetchColumn();

            return [
                'run_id'    => $runId,
                'mode'      => 'dry-run',
                'database'  => $dbName,
                'backup_id' => null,
                'stage'     => $stageStats,
                'counters'  => $counters,
            ];
        }

        if ($purge) {
            $purgeRes = $this->purgeProductsAndProductMedia($log);
            $counters['purged_products'] = $purgeRes['products'];
            $counters['purged_media_rows'] = $purgeRes['media_rows'];
            $counters['purged_media_files'] = $purgeRes['media_files'];
        }

        // Cache attachment map [id => ['rel' => ..., 'alt' => ..., 'title' => ..., 'mime' => ...]]
        $log && $log('Loading attachment registry into memory...');
        $attMap = [];
        $attStmt = $pdo->query("SELECT id, title, guid, mime_type, attached_file, alt FROM wp_attachments WHERE attached_file IS NOT NULL AND attached_file != ''");
        while ($ar = $attStmt->fetch(PDO::FETCH_ASSOC)) {
            $rel = ltrim(str_replace('\\', '/', (string) $ar['attached_file']), '/');
            $attMap[(int) $ar['id']] = [
                'rel'   => $rel,
                'title' => (string) ($ar['title'] ?? ''),
                'alt'   => (string) ($ar['alt'] ?? ''),
                'mime'  => (string) ($ar['mime_type'] ?? ''),
            ];
        }

        // Load terms + taxonomies + termmeta cleanly
        $terms = $pdo->query('SELECT term_id, name, slug FROM wp_terms')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $termMetaRows = $pdo->query('SELECT term_id, k, v FROM wp_termmeta')->fetchAll(PDO::FETCH_ASSOC);
        $termMeta = [];
        foreach ($termMetaRows as $tmr) {
            $termMeta[(int) $tmr['term_id']][$tmr['k']] = (string) $tmr['v'];
        }

        // Preload existing wp_uploads media map [strtolower(wp_relative_path) => media.id]
        $mediaRelToId = [];
        foreach (DB::table('media')->where('disk', 'wp_uploads')->whereNotNull('wp_relative_path')->get(['id', 'wp_relative_path']) as $mRow) {
            $mediaRelToId[strtolower((string) $mRow->wp_relative_path)] = (int) $mRow->id;
        }

        // 1. Import Brands (48 thuong_hieu terms)
        $log && $log('Importing 48 brands (thuong_hieu)...');
        [$brandTtToLocal, $brandSlugToLocal, $danhMucConCatWpToBrandId] = $this->importBrands(
            $pdo,
            $terms,
            $termMeta,
            $attMap,
            $seoBaseline,
            $mediaRelToId,
            $runId,
            $counters
        );

        // 2. Import Product Categories (750 product_cat terms)
        $log && $log('Importing 750 product categories (product_cat)...');
        [$catTtToLocal, $catWpToLocal, $catDepthMap, $catAncestorBrands] = $this->importCategories(
            $pdo,
            $terms,
            $termMeta,
            $attMap,
            $seoBaseline,
            $brandSlugToLocal,
            $danhMucConCatWpToBrandId,
            $mediaRelToId,
            $runId,
            $counters
        );

        // 3. Bulk Upsert Product Tags (255,276 product_tag terms)
        $log && $log('Bulk upserting 255,276 product tags (product_tag)...');
        $tagTtToLocal = $this->importProductTags($pdo, $terms, $termMeta, $seoBaseline, $runId, $counters);

        // Free big term meta/terms arrays not needed in product loop
        unset($terms, $termMetaRows, $termMeta);

        // 4. Bulk Stream & Import 133,036 Products in Bounded Chunks
        $log && $log("Streaming and importing {$stageStats['products_publish']} products (chunk={$chunkSize})...");
        $this->importProductsChunked(
            $pdo,
            $attMap,
            $seoBaseline,
            $brandTtToLocal,
            $catTtToLocal,
            $catWpToLocal,
            $catDepthMap,
            $catAncestorBrands,
            $tagTtToLocal,
            $mediaRelToId,
            $chunkSize,
            $runId,
            $counters,
            $log
        );

        try {
            DB::statement('ANALYZE TABLE products, categories, brands, product_tags, category_product, product_product_tag, media, product_media');
        } catch (\Throwable) {
        }

        Cache::flush();

        $completedAt = Carbon::now('UTC');
        MigrationRun::create([
            'run_id'               => $runId,
            'snapshot_id'          => 'full_sql_duljqgdz_hptechnew',
            'snapshot_checksum'    => hash('sha256', (string) $stageStats['source_sig']),
            'environment'          => (string) config('app.env'),
            'database_name'        => $dbName,
            'mode'                 => 'apply',
            'status'               => 'completed',
            'backup_checkpoint_id' => $backupId,
            'counters_json'        => json_encode($counters, JSON_UNESCAPED_UNICODE),
            'conflicts_json'       => '[]',
            'errors_json'          => '[]',
            'started_at'           => $startedAt,
            'completed_at'         => $completedAt,
        ]);

        return [
            'run_id'    => $runId,
            'mode'      => 'apply',
            'database'  => $dbName,
            'backup_id' => $backupId,
            'stage'     => $stageStats,
            'counters'  => $counters,
        ];
    }

    /**
     * Safely delete all existing products, product pivots, product tags, and product-only media files/records,
     * strictly protecting media used by Phase 1 Posts, Pages, Banners, Settings, and Post Categories.
     */
    public function purgeProductsAndProductMedia(?callable $log = null): array
    {
        $log && $log('Identifying Phase 1 protected media before purging product data...');
        $protectedIds = [];
        $protectedRels = [];

        // Protect post_media links
        foreach (DB::table('post_media')->pluck('media_id') as $mid) {
            $protectedIds[(int) $mid] = true;
        }

        // Protect any /wp-content/uploads/ paths embedded in Phase 1 tables & media_manifest.csv
        $scanHtmlPaths = function (?string $html) use (&$protectedRels) {
            if (!$html) return;
            if (preg_match_all('#/wp-content/uploads/([^\s"\'<>)\],]+)#i', $html, $m)) {
                foreach ($m[1] as $p) {
                    $protectedRels[ltrim($p, '/')] = true;
                }
            }
        };

        foreach (DB::table('pages')->select(['content', 'raw_content', 'og_image'])->cursor() as $pg) {
            $scanHtmlPaths($pg->content);
            $scanHtmlPaths($pg->raw_content);
            $scanHtmlPaths($pg->og_image);
        }
        foreach (DB::table('posts')->select(['content', 'raw_content', 'og_image'])->cursor() as $po) {
            $scanHtmlPaths($po->content);
            $scanHtmlPaths($po->raw_content);
            $scanHtmlPaths($po->og_image);
        }
        foreach (DB::table('categories')->where('type', 'post')->get(['icon', 'banner', 'content']) as $pc) {
            $scanHtmlPaths($pc->icon);
            $scanHtmlPaths($pc->banner);
            $scanHtmlPaths($pc->content);
        }
        foreach (DB::table('banners')->get() as $bn) {
            foreach ((array) $bn as $v) {
                if (is_string($v)) $scanHtmlPaths($v);
            }
        }
        foreach (DB::table('settings')->get(['value']) as $st) {
            $scanHtmlPaths($st->value);
        }

        $manifestCsv = base_path('docs/migration/data/media_manifest.csv');
        if (is_file($manifestCsv)) {
            $fp = fopen($manifestCsv, 'r');
            fgetcsv($fp);
            while (($r = fgetcsv($fp)) !== false) {
                $rel = trim((string) ($r[1] ?? ''));
                if ($rel !== '') {
                    $protectedRels[$rel] = true;
                }
            }
            fclose($fp);
        }

        if (!empty($protectedRels)) {
            foreach (array_chunk(array_keys($protectedRels), 500) as $chunk) {
                foreach (DB::table('media')->whereIn('wp_relative_path', $chunk)->pluck('id') as $mid) {
                    $protectedIds[(int) $mid] = true;
                }
            }
        }

        // Purge product entities & pivots
        $deletedProducts = DB::table('products')->count();
        DB::table('product_media')->delete();
        DB::table('category_product')->delete();
        DB::table('product_product_tag')->delete();
        DB::table('reviews')->whereNotNull('product_id')->delete();
        DB::table('source_url_routes')
            ->whereIn('entity_type', ['product', 'product_tag', 'reserved_product', 'reserved_product_tag'])
            ->delete();
        DB::table('products')->delete();
        DB::table('product_tags')->delete();
        DB::table('series')->delete();

        // Clear product category icons and brand logos so fresh SQL dump relinks cleanly
        DB::table('categories')->where('type', 'product')->update(['icon' => null, 'banner' => null]);
        DB::table('brands')->update(['logo' => null]);

        // Delete unprotected product-only media rows and their physical files
        $deletedMediaRows = 0;
        $deletedFiles = 0;
        $toDeleteIds = [];

        foreach (DB::table('media')->select(['id', 'disk', 'wp_relative_path', 'folder', 'filename'])->orderBy('id')->cursor() as $m) {
            $mid = (int) $m->id;
            if (isset($protectedIds[$mid])) {
                continue;
            }
            $rel = trim((string) ($m->wp_relative_path ?? ''), '/');
            if ($rel !== '' && isset($protectedRels[$rel])) {
                continue;
            }
            if ($m->disk === 'wp_uploads' && $rel !== '') {
                $abs = public_path('wp-content/uploads/' . $rel);
                if (is_file($abs) && @unlink($abs)) {
                    $deletedFiles++;
                }
            }
            $toDeleteIds[] = $mid;
            if (count($toDeleteIds) >= 1000) {
                DB::table('media')->whereIn('id', $toDeleteIds)->delete();
                $deletedMediaRows += count($toDeleteIds);
                $toDeleteIds = [];
            }
        }
        if (!empty($toDeleteIds)) {
            DB::table('media')->whereIn('id', $toDeleteIds)->delete();
            $deletedMediaRows += count($toDeleteIds);
        }

        $log && $log("Purged products={$deletedProducts}, product-media rows={$deletedMediaRows}, disk files={$deletedFiles} (protected Phase 1 media preserved).");

        return [
            'products'    => $deletedProducts,
            'media_rows'  => $deletedMediaRows,
            'media_files' => $deletedFiles,
        ];
    }

    private function importBrands(
        PDO $pdo,
        array $terms,
        array $termMeta,
        array $attMap,
        array $seoBaseline,
        array &$mediaRelToId,
        string $runId,
        array &$counters
    ): array {
        $rows = $pdo->query("SELECT term_taxonomy_id, term_id, description FROM wp_term_taxonomy WHERE taxonomy='thuong_hieu' ORDER BY term_id")->fetchAll(PDO::FETCH_ASSOC);

        $ttToLocal = [];
        $slugToLocal = [];
        $danhMucConToBrandId = [];
        $routeBatch = [];

        foreach ($rows as $r) {
            $ttId = (int) $r['term_taxonomy_id'];
            $tid = (int) $r['term_id'];
            $t = $terms[$tid] ?? null;
            if (!$t) continue;

            $slug = mb_substr(strtolower(trim((string) $t['slug'])), 0, 191);
            $name = mb_substr($t['name'], 0, 500);
            $normPath = "/thuong_hieu/{$slug}/";
            $tm = $termMeta[$tid] ?? [];

            $logoRel = null;
            $avatarId = (int) ($tm['avatar'] ?? 0);
            if ($avatarId > 0 && isset($attMap[$avatarId])) {
                $rel = $attMap[$avatarId]['rel'];
                $this->ensureSingleMediaRow($rel, $avatarId, $attMap[$avatarId]['alt'] ?: $name, $name, $mediaRelToId, $runId, $counters['media_images']);
                $logoRel = '/wp-content/uploads/' . $rel;
            }

            $baseSeo = $seoBaseline[$normPath] ?? [];
            $metaTitle = $baseSeo['meta_title']
                ?? $this->expandRankMath($tm['rank_math_title'] ?? null, $name, $r['description'] ?? '', '')
                ?? ($name . ' - Hải Phòng Tech');
            $metaDesc = $baseSeo['meta_description']
                ?? $this->expandRankMath($tm['rank_math_description'] ?? null, $name, $r['description'] ?? '', '');
            $metaRobots = $baseSeo['meta_robots']
                ?? $this->parseWpRobots($tm['rank_math_robots'] ?? null)
                ?? 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
            $canonical = !empty($baseSeo['canonical_url'])
                ? $this->toRelativePath($baseSeo['canonical_url'])
                : (!empty($tm['rank_math_canonical_url']) ? $this->toRelativePath($tm['rank_math_canonical_url']) : $normPath);

            $payload = [
                'source_wp_id'     => $tid,
                'source_taxonomy'  => 'thuong_hieu',
                'name'             => $name,
                'slug'             => $slug,
                'source_permalink' => "https://haiphongtech.vn{$normPath}",
                'logo'             => $logoRel ? mb_substr($logoRel, 0, 191) : null,
                'content'          => ($r['description'] ?? '') ?: null,
                'meta_title'       => mb_substr($metaTitle, 0, 500),
                'meta_description' => $metaDesc,
                'meta_robots'      => mb_substr($metaRobots, 0, 191),
                'canonical_url'    => mb_substr($canonical, 0, 500),
                'schema_json'      => $baseSeo['schema_json'] ?? null,
                'status'           => 'active',
                'in_sitemap'       => true,
                'import_batch_id'  => $runId,
            ];

            $brand = Brand::where('slug', $slug)->orWhere('source_wp_id', $tid)->first();
            if ($brand) {
                $brand->update($payload);
            } else {
                $brand = Brand::create($payload);
            }

            $ttToLocal[$ttId] = $brand->id;
            $slugToLocal[$slug] = $brand->id;
            $counters['brands']++;

            // Parse PHP-serialized `danh_muc_con` array from wp_termmeta for safe fallback brand verification
            if (!empty($tm['danh_muc_con']) && str_starts_with($tm['danh_muc_con'], 'a:')) {
                $parsedIds = @unserialize($tm['danh_muc_con'], ['allowed_classes' => false]);
                if (is_array($parsedIds)) {
                    foreach ($parsedIds as $cWpId) {
                        $cInt = (int) $cWpId;
                        if ($cInt > 0) {
                            $danhMucConToBrandId[$cInt][$brand->id] = true;
                        }
                    }
                }
            }

            $routeBatch[] = [
                'normalized_path' => $normPath,
                'unslashed_path'  => rtrim($normPath, '/'),
                'entity_type'     => 'product_brand',
                'entity_id'       => $brand->id,
                'source_wp_id'    => $tid,
                'canonical_url'   => $canonical,
                'in_sitemap'      => '1',
                'conflict_note'   => null,
            ];
        }

        // Only keep unambiguous category -> brand mappings from danh_muc_con (assigned to 1 unique brand)
        $cleanDanhMucCon = [];
        foreach ($danhMucConToBrandId as $catWpId => $brandSet) {
            if (count($brandSet) === 1) {
                $cleanDanhMucCon[$catWpId] = array_key_first($brandSet);
            }
        }

        $this->upsertSourceUrlRouteBatch($routeBatch, 'full_sql_duljqgdz_hptechnew', $runId);
        return [$ttToLocal, $slugToLocal, $cleanDanhMucCon];
    }

    private function importCategories(
        PDO $pdo,
        array $terms,
        array $termMeta,
        array $attMap,
        array $seoBaseline,
        array $brandSlugToLocal,
        array $danhMucConCatWpToBrandId,
        array &$mediaRelToId,
        string $runId,
        array &$counters
    ): array {
        $rows = $pdo->query("SELECT term_taxonomy_id, term_id, description, parent FROM wp_term_taxonomy WHERE taxonomy='product_cat'")->fetchAll(PDO::FETCH_ASSOC);

        // Sort topologically from root parents down to children
        $byTermId = [];
        foreach ($rows as $r) {
            $byTermId[(int) $r['term_id']] = $r;
        }
        $depthCache = [];
        $computeDepth = function (int $tid) use (&$byTermId, &$depthCache, &$computeDepth): int {
            if (isset($depthCache[$tid])) return $depthCache[$tid];
            $seen = [];
            $d = 0;
            $cur = $tid;
            while (isset($byTermId[$cur]) && (int) $byTermId[$cur]['parent'] > 0 && !isset($seen[$cur])) {
                $seen[$cur] = true;
                $cur = (int) $byTermId[$cur]['parent'];
                $d++;
            }
            return $depthCache[$tid] = $d;
        };
        usort($rows, fn($a, $b) => $computeDepth((int) $a['term_id']) <=> $computeDepth((int) $b['term_id']));

        $ttToLocal = [];
        $wpToLocal = [];
        $parentLocalMap = [];
        $localDepthMap = [];
        $catSlugMap = [];
        $routeBatch = [];

        foreach ($rows as $r) {
            $ttId = (int) $r['term_taxonomy_id'];
            $tid = (int) $r['term_id'];
            $t = $terms[$tid] ?? null;
            if (!$t) continue;

            $slug = mb_substr(strtolower(trim((string) $t['slug'])), 0, 191);
            $name = mb_substr($t['name'], 0, 500);
            $parentWp = (int) $r['parent'];
            $parentLocalId = $parentWp > 0 ? ($wpToLocal[$parentWp] ?? null) : null;
            $normPath = "/{$slug}/";
            $tm = $termMeta[$tid] ?? [];

            $iconRel = null;
            $thumbId = (int) ($tm['thumbnail_id'] ?? 0);
            if ($thumbId > 0 && isset($attMap[$thumbId])) {
                $rel = $attMap[$thumbId]['rel'];
                $this->ensureSingleMediaRow($rel, $thumbId, $attMap[$thumbId]['alt'] ?: $name, $name, $mediaRelToId, $runId, $counters['media_images']);
                $iconRel = mb_substr('/wp-content/uploads/' . $rel, 0, 191);
            }

            $baseSeo = $seoBaseline[$normPath] ?? [];
            $metaTitle = $baseSeo['meta_title']
                ?? $this->expandRankMath($tm['rank_math_title'] ?? null, $name, $r['description'] ?? '', '')
                ?? ($name . ' - Hải Phòng Tech');
            $metaDesc = $baseSeo['meta_description']
                ?? $this->expandRankMath($tm['rank_math_description'] ?? null, $name, $r['description'] ?? '', '');
            $metaRobots = $baseSeo['meta_robots']
                ?? $this->parseWpRobots($tm['rank_math_robots'] ?? null)
                ?? 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
            $canonical = !empty($baseSeo['canonical_url'])
                ? $this->toRelativePath($baseSeo['canonical_url'])
                : (!empty($tm['rank_math_canonical_url']) ? $this->toRelativePath($tm['rank_math_canonical_url']) : $normPath);

            $payload = [
                'name'             => $name,
                'slug'             => $slug,
                'description'      => ($r['description'] ?? '') ?: null,
                'icon'             => $iconRel,
                'parent_id'        => $parentLocalId,
                'type'             => 'product',
                'status'           => 'active',
                'meta_title'       => mb_substr($metaTitle, 0, 500),
                'meta_description' => $metaDesc,
                'meta_robots'      => mb_substr($metaRobots, 0, 255),
                'canonical_url'    => mb_substr($canonical, 0, 500),
                'schema_json'      => $baseSeo['schema_json'] ?? null,
                'source_wp_id'     => $tid,
                'source_taxonomy'  => 'product_cat',
                'source_permalink' => "https://haiphongtech.vn{$normPath}",
                'in_sitemap'       => true,
                'import_batch_id'  => $runId,
            ];

            $cat = Category::where('type', 'product')->where(function ($q) use ($slug, $tid) {
                $q->where('slug', $slug)->orWhere('source_wp_id', $tid);
            })->first();

            if ($cat) {
                $cat->update($payload);
            } else {
                $cat = Category::create($payload);
            }

            $ttToLocal[$ttId] = $cat->id;
            $wpToLocal[$tid] = $cat->id;
            $parentLocalMap[$cat->id] = $parentLocalId;
            $localDepthMap[$cat->id] = $computeDepth($tid);
            $catSlugMap[$cat->id] = $slug;
            $counters['categories']++;

            $routeBatch[] = [
                'normalized_path' => $normPath,
                'unslashed_path'  => rtrim($normPath, '/'),
                'entity_type'     => 'product_category',
                'entity_id'       => $cat->id,
                'source_wp_id'    => $tid,
                'canonical_url'   => $canonical,
                'in_sitemap'      => '1',
                'conflict_note'   => 'Activated for full WooCommerce product category',
            ];
        }

        // Precompute root/ancestor brand resolution per local category id (exact root slug / alias / danh_muc_con)
        $wpByLocal = array_flip($wpToLocal);
        $catAncestorBrandMap = [];
        foreach ($catSlugMap as $cid => $cSlug) {
            $cursor = $cid;
            $seen = [];
            $matchedBrandId = null;
            while ($cursor && !isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $s = $catSlugMap[$cursor] ?? '';
                $mappedSlug = self::BRAND_CATEGORY_SLUG_ALIASES[$s] ?? $s;
                if (isset($brandSlugToLocal[$mappedSlug])) {
                    $matchedBrandId = $brandSlugToLocal[$mappedSlug];
                    break;
                }
                $cursorWp = $wpByLocal[$cursor] ?? null;
                if ($cursorWp && isset($danhMucConCatWpToBrandId[$cursorWp])) {
                    $matchedBrandId = $danhMucConCatWpToBrandId[$cursorWp];
                    break;
                }
                $cursor = $parentLocalMap[$cursor] ?? null;
            }
            if ($matchedBrandId !== null) {
                $catAncestorBrandMap[$cid] = $matchedBrandId;
            }
        }

        $this->upsertSourceUrlRouteBatch($routeBatch, 'full_sql_duljqgdz_hptechnew', $runId);
        return [$ttToLocal, $wpToLocal, $localDepthMap, $catAncestorBrandMap];
    }

    private function importProductTags(
        PDO $pdo,
        array $terms,
        array $termMeta,
        array $seoBaseline,
        string $runId,
        array &$counters
    ): array {
        $stmt = $pdo->query("SELECT term_taxonomy_id, term_id, description, count FROM wp_term_taxonomy WHERE taxonomy='product_tag' ORDER BY term_id");
        $nowStr = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $ttToLocal = [];
        $batch = [];
        $batchTt = [];

        $flush = function () use (&$batch, &$batchTt, &$ttToLocal, &$counters) {
            if (empty($batch)) return;
            DB::table('product_tags')->upsert(
                $batch,
                ['slug'],
                ['name', 'description', 'products_count_source', 'meta_title', 'meta_description', 'meta_robots', 'source_wp_id', 'source_permalink', 'in_sitemap', 'status', 'import_batch_id', 'updated_at']
            );
            $slugs = array_keys($batchTt);
            $idBySlug = DB::table('product_tags')->whereIn('slug', $slugs)->pluck('id', 'slug')->all();
            foreach ($batchTt as $slug => $ttId) {
                if (isset($idBySlug[$slug])) {
                    $ttToLocal[$ttId] = (int) $idBySlug[$slug];
                }
            }
            $counters['product_tags'] += count($batch);
            $batch = [];
            $batchTt = [];
        };

        $seenSlugs = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ttId = (int) $r['term_taxonomy_id'];
            $tid = (int) $r['term_id'];
            $t = $terms[$tid] ?? null;
            if (!$t) continue;

            $rawSlug = strtolower(trim((string) $t['slug']));
            if ($rawSlug === '') continue;
            $slug = mb_substr($rawSlug, 0, 191);
            if (isset($seenSlugs[$slug])) {
                $slug = mb_substr($rawSlug, 0, 178) . '-' . $tid;
            }
            $seenSlugs[$slug] = true;

            $name = mb_substr($t['name'], 0, 191);
            $normPath = "/tag-san-pham/{$slug}/";
            $baseSeo = $seoBaseline[$normPath] ?? [];
            $tm = $termMeta[$tid] ?? [];
            $metaTitle = $baseSeo['meta_title']
                ?? $this->expandRankMath($tm['rank_math_title'] ?? null, $name, '', ' Archives - Hải Phòng Tech')
                ?? ($name . ' Archives - Hải Phòng Tech');

            $batch[] = [
                'name'                  => $name,
                'slug'                  => $slug,
                'description'           => ($r['description'] ?? '') ?: null,
                'products_count_source' => (int) ($r['count'] ?? 0),
                'meta_title'            => mb_substr($metaTitle, 0, 191),
                'meta_description'      => $baseSeo['meta_description'] ?? ($tm['rank_math_description'] ?? null),
                'meta_robots'           => 'noindex, follow',
                'canonical_url'         => null,
                'source_wp_id'          => $tid,
                'source_permalink'      => mb_substr($normPath, 0, 191),
                'in_sitemap'            => 0,
                'status'                => 'active',
                'import_batch_id'       => $runId,
                'created_at'            => $nowStr,
                'updated_at'            => $nowStr,
            ];
            $batchTt[$slug] = $ttId;

            if (count($batch) >= 2500) {
                $flush();
            }
        }
        $flush();

        return $ttToLocal;
    }

    private function importProductsChunked(
        PDO $pdo,
        array $attMap,
        array $seoBaseline,
        array $brandTtToLocal,
        array $catTtToLocal,
        array $catWpToLocal,
        array $catDepthMap,
        array $catAncestorBrands,
        array $tagTtToLocal,
        array &$mediaRelToId,
        int $chunkSize,
        string $runId,
        array &$counters,
        ?callable $log
    ): void {
        $sitemapShardSize = max(100, (int) config('migration.phase2.sitemap_chunk_size', 2000));
        $nowUtc = Carbon::now('UTC')->format('Y-m-d H:i:s');

        $totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM wp_products')->fetchColumn();
        $lastId = 0;
        $globalIdx = 0;
        $seenProductSlugs = [];

        $stmtProd = $pdo->prepare('SELECT id, slug, title, post_content, post_excerpt, date_gmt FROM wp_products WHERE id > ? ORDER BY id ASC LIMIT ?');

        while (true) {
            $stmtProd->bindValue(1, $lastId, PDO::PARAM_INT);
            $stmtProd->bindValue(2, $chunkSize, PDO::PARAM_INT);
            $stmtProd->execute();
            $rows = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                break;
            }

            $ids = array_map(fn($r) => (int) $r['id'], $rows);
            $lastId = end($ids);
            $inList = implode(',', $ids);

            // Load postmeta, relationships, and wc_lookup for just this batch of 1,000 IDs
            $metaByPid = [];
            foreach ($pdo->query("SELECT post_id, k, v FROM wp_postmeta WHERE post_id IN ({$inList})") as $mr) {
                $metaByPid[(int) $mr['post_id']][$mr['k']] = (string) $mr['v'];
            }
            $lookupByPid = [];
            foreach ($pdo->query("SELECT product_id, sku, min_price, stock_status FROM wp_wc_lookup WHERE product_id IN ({$inList})") as $lr) {
                $lookupByPid[(int) $lr['product_id']] = $lr;
            }
            $relsByPid = [];
            foreach ($pdo->query("SELECT object_id, term_taxonomy_id FROM wp_term_relationships WHERE object_id IN ({$inList})") as $rr) {
                $relsByPid[(int) $rr['object_id']][] = (int) $rr['term_taxonomy_id'];
            }

            // Collect all media to register cleanly in batch first
            $neededMediaQueue = []; // relPath => ['wp_attachment_id' => ..., 'alt' => ..., 'title' => ..., 'ext' => ..., 'mime' => ..., 'url' => ..., 'is_catalog' => bool]
            $parsedChunk = [];

            foreach ($rows as $row) {
                $pid = (int) $row['id'];
                $rawSlug = strtolower(trim((string) $row['slug']));
                if ($rawSlug === '') {
                    $rawSlug = 'product-' . $pid;
                }
                $slug = mb_substr($rawSlug, 0, 191);
                if (isset($seenProductSlugs[$slug])) {
                    $slug = mb_substr($rawSlug, 0, 178) . '-' . $pid;
                }
                $seenProductSlugs[$slug] = true;

                $name = mb_substr(trim((string) $row['title']), 0, 500);
                $pm = $metaByPid[$pid] ?? [];
                $lk = $lookupByPid[$pid] ?? [];
                $ttIds = $relsByPid[$pid] ?? [];

                // Taxonomies
                $catIds = [];
                $directBrandId = null;
                $tagIds = [];
                foreach ($ttIds as $ttId) {
                    if (isset($catTtToLocal[$ttId])) {
                        $catIds[] = $catTtToLocal[$ttId];
                    } elseif (isset($brandTtToLocal[$ttId]) && $directBrandId === null) {
                        $directBrandId = $brandTtToLocal[$ttId];
                    } elseif (isset($tagTtToLocal[$ttId])) {
                        $tagIds[] = $tagTtToLocal[$ttId];
                    }
                }
                $catIds = array_values(array_unique($catIds));
                $tagIds = array_values(array_unique($tagIds));

                // Primary category resolution: RankMath primary_product_cat > deepest leaf assigned category
                $primaryCatId = null;
                $rmPrimaryWp = (int) ($pm['rank_math_primary_product_cat'] ?? 0);
                if ($rmPrimaryWp > 0 && isset($catWpToLocal[$rmPrimaryWp])) {
                    $candidate = $catWpToLocal[$rmPrimaryWp];
                    if (in_array($candidate, $catIds, true) || empty($catIds)) {
                        $primaryCatId = $candidate;
                        if (empty($catIds)) $catIds[] = $candidate;
                    }
                }
                if ($primaryCatId === null && !empty($catIds)) {
                    $bestDepth = -1;
                    foreach ($catIds as $cid) {
                        $d = $catDepthMap[$cid] ?? 0;
                        if ($d > $bestDepth) {
                            $bestDepth = $d;
                            $primaryCatId = $cid;
                        }
                    }
                }

                // Exact Brand verification: thuong_hieu first; else unambiguous single root category brand
                $brandId = $directBrandId;
                if ($brandId !== null) {
                    $counters['products_brand_direct']++;
                } elseif (!empty($catIds)) {
                    $inferredBrands = [];
                    foreach ($catIds as $cid) {
                        if (isset($catAncestorBrands[$cid])) {
                            $inferredBrands[$catAncestorBrands[$cid]] = true;
                        }
                    }
                    if (count($inferredBrands) === 1) {
                        $brandId = array_key_first($inferredBrands);
                        $counters['products_brand_cat']++;
                    } else {
                        $counters['products_unbranded']++;
                    }
                } else {
                    $counters['products_unbranded']++;
                }

                // Price & SKU (clamped safely for decimal(15,2))
                $skuRaw = trim((string) ($pm['_sku'] ?? $lk['sku'] ?? ''));
                $sku = $skuRaw !== '' ? mb_substr($skuRaw, 0, 191, 'UTF-8') : null;

                $rawPrice = isset($pm['_price']) && is_numeric($pm['_price'])
                    ? (float) $pm['_price']
                    : (isset($lk['min_price']) && $lk['min_price'] !== null ? (float) $lk['min_price'] : (isset($pm['_regular_price']) && is_numeric($pm['_regular_price']) ? (float) $pm['_regular_price'] : 0.0));
                $price = max(0.0, min(999999999999.99, round($rawPrice, 2)));
                $salePrice = null;
                if (isset($pm['_sale_price']) && is_numeric($pm['_sale_price'])) {
                    $sp = round((float) $pm['_sale_price'], 2);
                    if ($sp > 0 && $sp < $price && $sp <= 999999999999.99) {
                        $salePrice = $sp;
                    }
                }

                $stockRaw = strtolower(trim((string) ($pm['_stock_status'] ?? $lk['stock_status'] ?? 'instock')));
                $stockStatus = $stockRaw === 'outofstock' ? 'outofstock' : 'instock';

                // Clean HTML content & short description per strict user rules
                $cleanContent = $this->normalizer->cleanProductContentHtml((string) ($row['post_content'] ?? ''));
                $cleanExcerpt = $this->normalizer->cleanProductShortDescription((string) ($row['post_excerpt'] ?? ''));

                // Inline content wp-content/uploads images queued for asset downloader
                foreach ($this->normalizer->extractContentWpUploadPaths($cleanContent) as $contentImgRel) {
                    $cKey = strtolower($contentImgRel);
                    if (!isset($mediaRelToId[$cKey]) && !isset($neededMediaQueue[$cKey])) {
                        $neededMediaQueue[$cKey] = [
                            'rel'        => $contentImgRel,
                            'att_id'     => null,
                            'alt'        => $name,
                            'title'      => $name,
                            'url'        => 'https://haiphongtech.vn/wp-content/uploads/' . $contentImgRel,
                            'is_catalog' => false,
                        ];
                    }
                }

                // Product images: thumbnail + gallery
                $productImageRefs = []; // [['key' => strtolower(rel), 'role' => 'thumbnail'|'gallery', 'pos' => int]]
                $thumbId = (int) ($pm['_thumbnail_id'] ?? 0);
                if ($thumbId > 0 && isset($attMap[$thumbId])) {
                    $att = $attMap[$thumbId];
                    $rel = $att['rel'];
                    $rKey = strtolower($rel);
                    $productImageRefs[] = ['key' => $rKey, 'role' => 'thumbnail', 'pos' => 0];
                    if (!isset($mediaRelToId[$rKey]) && !isset($neededMediaQueue[$rKey])) {
                        $neededMediaQueue[$rKey] = [
                            'rel'        => $rel,
                            'att_id'     => $thumbId,
                            'alt'        => $att['alt'] ?: $name,
                            'title'      => $att['title'] ?: $name,
                            'url'        => 'https://haiphongtech.vn/wp-content/uploads/' . $rel,
                            'is_catalog' => false,
                        ];
                    }
                }
                if (!empty($pm['_product_image_gallery'])) {
                    $gPos = 1;
                    foreach (explode(',', $pm['_product_image_gallery']) as $gIdStr) {
                        $gId = (int) trim($gIdStr);
                        if ($gId > 0 && $gId !== $thumbId && isset($attMap[$gId])) {
                            $att = $attMap[$gId];
                            $rel = $att['rel'];
                            $rKey = strtolower($rel);
                            $productImageRefs[] = ['key' => $rKey, 'role' => 'gallery', 'pos' => $gPos++];
                            if (!isset($mediaRelToId[$rKey]) && !isset($neededMediaQueue[$rKey])) {
                                $neededMediaQueue[$rKey] = [
                                    'rel'        => $rel,
                                    'att_id'     => $gId,
                                    'alt'        => $att['alt'] ?: $name,
                                    'title'      => $att['title'] ?: $name,
                                    'url'        => 'https://haiphongtech.vn/wp-content/uploads/' . $rel,
                                    'is_catalog' => false,
                                ];
                            }
                        }
                    }
                }

                // Catalog PDF / technical document (Google Drive links DROPPED; shared URLs deduplicated!)
                $catalogKey = null;
                $rawCatalogUrl = trim((string) ($pm['link_pdf_product'] ?? ''));
                if ($rawCatalogUrl !== '') {
                    if ($this->isGoogleDriveUrl($rawCatalogUrl)) {
                        $counters['catalog_drive_dropped']++;
                    } elseif ($validCat = $this->normalizeCatalogUploadRelPath($rawCatalogUrl)) {
                        $catalogRel = $validCat['rel'];
                        $catalogKey = strtolower($catalogRel);
                        if (!isset($mediaRelToId[$catalogKey]) && !isset($neededMediaQueue[$catalogKey])) {
                            $neededMediaQueue[$catalogKey] = [
                                'rel'        => $catalogRel,
                                'att_id'     => null,
                                'alt'        => $name,
                                'title'      => pathinfo($catalogRel, PATHINFO_FILENAME),
                                'url'        => $validCat['source_url'],
                                'is_catalog' => true,
                            ];
                        }
                    }
                }
                // Fallback to file_tai_lieu ONLY if no link_pdf_product and attachment is an actual document file (never random .jpg)
                if ($catalogKey === null && !empty($pm['file_tai_lieu']) && is_numeric($pm['file_tai_lieu'])) {
                    $docAttId = (int) $pm['file_tai_lieu'];
                    if (isset($attMap[$docAttId])) {
                        $rel = $attMap[$docAttId]['rel'];
                        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                        if (in_array($ext, ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx'], true)) {
                            $catalogKey = strtolower($rel);
                            if (!isset($mediaRelToId[$catalogKey]) && !isset($neededMediaQueue[$catalogKey])) {
                                $neededMediaQueue[$catalogKey] = [
                                    'rel'        => $rel,
                                    'att_id'     => $docAttId,
                                    'alt'        => $name,
                                    'title'      => $attMap[$docAttId]['title'] ?: pathinfo($rel, PATHINFO_FILENAME),
                                    'url'        => 'https://haiphongtech.vn/wp-content/uploads/' . $rel,
                                    'is_catalog' => true,
                                ];
                            }
                        }
                    }
                }

                // SEO fields (expand %var%, preserve scraped baselines if present)
                $normPath = "/product/{$slug}/";
                $baseSeo = $seoBaseline[$normPath] ?? [];
                $metaTitle = $baseSeo['meta_title']
                    ?? $this->expandRankMath($pm['rank_math_title'] ?? null, $name, $cleanExcerpt, ' - Hải Phòng Tech');
                $metaDesc = $baseSeo['meta_description']
                    ?? $this->expandRankMath($pm['rank_math_description'] ?? null, $name, $cleanExcerpt, '');
                $metaRobots = $baseSeo['meta_robots']
                    ?? $this->parseWpRobots($pm['rank_math_robots'] ?? null)
                    ?? 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
                $canonical = !empty($baseSeo['canonical_url'])
                    ? $this->toRelativePath($baseSeo['canonical_url'])
                    : (!empty($pm['rank_math_canonical_url']) ? $this->toRelativePath($pm['rank_math_canonical_url']) : $normPath);

                $sitemapChunk = (int) floor($globalIdx / $sitemapShardSize) + 1;
                $globalIdx++;

                $publishedAt = !empty($row['date_gmt']) && !str_starts_with($row['date_gmt'], '0000-')
                    ? Carbon::parse($row['date_gmt'], 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s')
                    : Carbon::now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');

                $productRecord = [
                    'source_wp_id'          => $pid,
                    'name'                  => $name,
                    'slug'                  => $slug,
                    'source_permalink'      => "https://haiphongtech.vn{$normPath}",
                    'sku'                   => $sku,
                    'product_type'          => 'simple',
                    'stock_status'          => $stockStatus,
                    'price'                 => number_format($price, 2, '.', ''),
                    'sale_price'            => $salePrice !== null ? number_format($salePrice, 2, '.', '') : null,
                    'short_description'     => $cleanExcerpt !== '' ? mb_substr($cleanExcerpt, 0, 60000) : null,
                    'content'               => $cleanContent !== '' ? $cleanContent : null,
                    'raw_content'           => null,
                    'raw_short_description' => null,
                    'content_hash'          => sha1($slug . '|' . $name . '|' . $price . '|' . $sku),
                    'category_id'           => $primaryCatId,
                    'brand_id'              => $brandId,
                    'status'                => 'active',
                    'published_at'          => $publishedAt,
                    'meta_title'            => $metaTitle ? mb_substr($metaTitle, 0, 500) : null,
                    'meta_description'      => $metaDesc,
                    'meta_robots'           => mb_substr($metaRobots, 0, 191),
                    'canonical_url'         => mb_substr($canonical, 0, 500),
                    'schema_json'           => $baseSeo['schema_json'] ?? null,
                    'in_sitemap'            => 1,
                    'sitemap_chunk'         => $sitemapChunk,
                    'import_batch_id'       => $runId,
                    'created_at'            => $nowUtc,
                    'updated_at'            => $nowUtc,
                ];

                $parsedChunk[] = [
                    'wp_id'        => $pid,
                    'slug'         => $slug,
                    'record'       => $productRecord,
                    'cat_ids'      => $catIds,
                    'primary_cat'  => $primaryCatId,
                    'tag_ids'      => $tagIds,
                    'images'       => $productImageRefs,
                    'catalog_key'  => $catalogKey,
                ];
            }

            // Bulk transaction per 1,000 products
            DB::beginTransaction();
            try {
                // Register all new unique media in this batch cleanly (deduplicated by wp_relative_path)
                if (!empty($neededMediaQueue)) {
                    $this->bulkUpsertMediaQueue($neededMediaQueue, $mediaRelToId, $runId, $nowUtc, $counters);
                }

                // Bulk insert/upsert products
                $productPayloads = array_map(fn($x) => $x['record'], $parsedChunk);
                DB::table('products')->upsert(
                    $productPayloads,
                    ['source_wp_id'],
                    ['name','slug','source_permalink','sku','product_type','stock_status','price','sale_price','short_description','content','content_hash','category_id','brand_id','status','published_at','meta_title','meta_description','meta_robots','canonical_url','schema_json','in_sitemap','sitemap_chunk','import_batch_id','updated_at']
                );

                // Fetch inserted local product IDs by source_wp_id
                $wpIdsBatch = array_map(fn($x) => $x['wp_id'], $parsedChunk);
                $prodIdMap = DB::table('products')->whereIn('source_wp_id', $wpIdsBatch)->pluck('id', 'source_wp_id')->all();

                $catPivotBatch = [];
                $tagPivotBatch = [];
                $mediaPivotBatch = [];

                foreach ($parsedChunk as $item) {
                    $localProdId = (int) ($prodIdMap[$item['wp_id']] ?? 0);
                    if ($localProdId <= 0) continue;

                    foreach ($item['cat_ids'] as $cid) {
                        $catPivotBatch[] = [
                            'product_id'  => $localProdId,
                            'category_id' => $cid,
                            'is_primary'  => ($cid === $item['primary_cat']) ? 1 : 0,
                            'created_at'  => $nowUtc,
                            'updated_at'  => $nowUtc,
                        ];
                    }

                    foreach ($item['tag_ids'] as $tid) {
                        $tagPivotBatch[] = [
                            'product_id'     => $localProdId,
                            'product_tag_id' => $tid,
                            'created_at'     => $nowUtc,
                            'updated_at'     => $nowUtc,
                        ];
                    }

                    $seenProdMedia = [];
                    foreach ($item['images'] as $imgRef) {
                        $mid = $mediaRelToId[$imgRef['key']] ?? null;
                        if (!$mid) continue;
                        $key = "{$mid}|{$imgRef['role']}";
                        if (isset($seenProdMedia[$key])) continue;
                        $seenProdMedia[$key] = true;
                        $mediaPivotBatch[] = [
                            'product_id' => $localProdId,
                            'media_id'   => $mid,
                            'role'       => $imgRef['role'],
                            'position'   => $imgRef['pos'],
                            'created_at' => $nowUtc,
                            'updated_at' => $nowUtc,
                        ];
                    }

                    if ($item['catalog_key'] !== null && isset($mediaRelToId[$item['catalog_key']])) {
                        $catMid = $mediaRelToId[$item['catalog_key']];
                        $mediaPivotBatch[] = [
                            'product_id' => $localProdId,
                            'media_id'   => $catMid,
                            'role'       => 'catalog',
                            'position'   => 0,
                            'created_at' => $nowUtc,
                            'updated_at' => $nowUtc,
                        ];
                        $counters['catalog_links']++;
                    }
                }

                foreach (array_chunk($catPivotBatch, 2500) as $ch) {
                    DB::table('category_product')->insertOrIgnore($ch);
                    $counters['category_links'] += count($ch);
                }
                foreach (array_chunk($tagPivotBatch, 2500) as $ch) {
                    DB::table('product_product_tag')->insertOrIgnore($ch);
                    $counters['tag_links'] += count($ch);
                }
                foreach (array_chunk($mediaPivotBatch, 2500) as $ch) {
                    DB::table('product_media')->insertOrIgnore($ch);
                }

                DB::commit();
                $counters['products'] += count($parsedChunk);

                if ($log && ($counters['products'] % 10000 === 0 || $counters['products'] === $totalProducts)) {
                    $log("  Imported products: " . number_format($counters['products']) . ' / ' . number_format($totalProducts)
                        . " | images={$counters['media_images']} | dedup_catalogs={$counters['media_catalogs_dedup']} (links={$counters['catalog_links']})");
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }
    }

    private function bulkUpsertMediaQueue(array $queue, array &$mediaRelToId, string $runId, string $nowUtc, array &$counters): void
    {
        $rels = array_map(fn($it) => $it['rel'], $queue);
        foreach (array_chunk($rels, 1000) as $relChunk) {
            foreach (DB::table('media')->whereIn('wp_relative_path', $relChunk)->get(['id', 'wp_relative_path']) as $ex) {
                $mediaRelToId[strtolower((string) $ex->wp_relative_path)] = (int) $ex->id;
            }
        }

        $insertRows = [];
        $newRels = [];
        $seenFolderFiles = [];
        foreach ($queue as $rKey => $it) {
            $rel = $it['rel'];
            $lowKey = strtolower($rel);
            if (isset($mediaRelToId[$lowKey])) continue;

            $dirPart = dirname($rel);
            $folder = mb_substr('wp-content/uploads' . ($dirPart !== '.' && $dirPart !== '' ? '/' . str_replace('\\', '/', $dirPart) : ''), 0, 500);
            $filename = mb_substr(basename($rel), 0, 255);
            $ffKey = strtolower($folder . '|' . $filename);
            if (isset($seenFolderFiles[$ffKey])) continue;
            $seenFolderFiles[$ffKey] = true;

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'webp' => 'image/webp',
                'png'  => 'image/png',
                'avif' => 'image/avif',
                'gif'  => 'image/gif',
                'svg'  => 'image/svg+xml',
                'pdf'  => 'application/pdf',
                'zip'  => 'application/zip',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                default => 'image/jpeg',
            };

            $insertRows[] = [
                'wp_attachment_id' => $it['att_id'],
                'disk'             => 'wp_uploads',
                'folder'           => $folder,
                'filename'         => $filename,
                'wp_source_url'    => mb_substr((string) $it['url'], 0, 500),
                'wp_relative_path' => mb_substr($rel, 0, 500),
                'original_name'    => $filename,
                'extension'        => $ext ? mb_substr($ext, 0, 20) : null,
                'mime_type'        => $mime,
                'size'             => 0,
                'title'            => $it['title'] !== '' ? mb_substr($it['title'], 0, 255) : null,
                'alt'              => $it['alt'] !== '' ? mb_substr($it['alt'], 0, 255) : null,
                'status'           => 'active',
                'import_batch_id'  => $runId,
                'created_at'       => $nowUtc,
                'updated_at'       => $nowUtc,
            ];
            $newRels[] = mb_substr($rel, 0, 500);
            if ($it['is_catalog']) {
                $counters['media_catalogs_dedup']++;
            } else {
                $counters['media_images']++;
            }
        }

        foreach (array_chunk($insertRows, 500) as $ch) {
            DB::table('media')->insertOrIgnore($ch);
        }
        foreach (array_chunk($newRels, 1000) as $rkChunk) {
            foreach (DB::table('media')->whereIn('wp_relative_path', $rkChunk)->get(['id', 'wp_relative_path']) as $created) {
                $mediaRelToId[strtolower((string) $created->wp_relative_path)] = (int) $created->id;
            }
        }
        foreach ($queue as $it) {
            $rel = $it['rel'];
            $lowKey = strtolower($rel);
            if (isset($mediaRelToId[$lowKey])) continue;
            $dirPart = dirname($rel);
            $folder = mb_substr('wp-content/uploads' . ($dirPart !== '.' && $dirPart !== '' ? '/' . str_replace('\\', '/', $dirPart) : ''), 0, 500);
            $filename = mb_substr(basename($rel), 0, 255);
            $fallbackId = DB::table('media')->where('folder', $folder)->where('filename', $filename)->value('id');
            if ($fallbackId) {
                $mediaRelToId[$lowKey] = (int) $fallbackId;
            }
        }
    }

    private function ensureSingleMediaRow(string $rel, ?int $attId, string $alt, string $title, array &$mediaRelToId, string $runId, int &$counter): void
    {
        $lowKey = strtolower($rel);
        if (isset($mediaRelToId[$lowKey])) return;
        $existing = DB::table('media')->where('wp_relative_path', $rel)->value('id');
        if ($existing) {
            $mediaRelToId[$lowKey] = (int) $existing;
            return;
        }
        $nowUtc = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $q = [$lowKey => ['rel' => $rel, 'att_id' => $attId, 'alt' => $alt, 'title' => $title, 'url' => 'https://haiphongtech.vn/wp-content/uploads/' . $rel, 'is_catalog' => false]];
        $dummy = ['media_images' => 0, 'media_catalogs_dedup' => 0];
        $this->bulkUpsertMediaQueue($q, $mediaRelToId, $runId, $nowUtc, $dummy);
        $counter += $dummy['media_images'];
    }

    private function isGoogleDriveUrl(string $url): bool
    {
        $low = strtolower($url);
        return str_contains($low, 'drive.google.com')
            || str_contains($low, 'docs.google.com')
            || str_contains($low, 'googleusercontent.com/drive');
    }

    private function normalizeCatalogUploadRelPath(string $url): ?array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($host === '' || $path === '' || str_contains($host, ' ')) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_DOC_EXTENSIONS, true)) {
            return null;
        }
        if (str_contains($host, 'haiphongtech.vn') && preg_match('#^/wp-content/uploads/(.+)$#i', $path, $m)) {
            $decodedRel = rawurldecode(ltrim($m[1], '/'));
            return [
                'rel'        => str_replace('\\', '/', $decodedRel),
                'source_url' => $url,
            ];
        }
        return [
            'rel'        => 'catalogs/' . $host . '/' . rawurldecode(ltrim($path, '/')),
            'source_url' => $url,
        ];
    }

    private function loadSeoBaselineMap(): array
    {
        $map = [];
        $path = base_path('docs/migration/data/phase2/phase2_seo_baseline.jsonl');
        if (!is_file($path)) {
            return $map;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true);
            if (!empty($row['normalized_path'])) {
                $map[$row['normalized_path']] = $row;
                if (!empty($row['canonical_url'])) {
                    $cPath = (string) parse_url((string) $row['canonical_url'], PHP_URL_PATH);
                    if ($cPath !== '' && $cPath !== '/') {
                        $cNorm = '/' . trim($cPath, '/') . '/';
                        if (!isset($map[$cNorm])) {
                            $map[$cNorm] = $row;
                        }
                    }
                }
            }
        }
        return $map;
    }

    private function expandRankMath(?string $tpl, string $name, string $excerptHtml, string $fallbackSuffix): ?string
    {
        if ($tpl === null || trim($tpl) === '') {
            return null;
        }
        $plainExcerpt = trim(preg_replace('/\s+/', ' ', strip_tags($excerptHtml)) ?? '');
        $replaced = str_ireplace(
            ['%title%', '%term%', '%sep%', '%sitename%', '%currentyear%', '%page%', '%excerpt%', '%term_description%'],
            [$name, $name, '-', 'Hải Phòng Tech', '2026', '', $plainExcerpt, $plainExcerpt],
            $this->decodeText($tpl)
        );
        $replaced = trim(preg_replace('/\s+/', ' ', $replaced) ?? '');
        $replaced = trim(preg_replace('/-\s*-/', '-', $replaced) ?? '');
        if ($replaced === '' || preg_match('/%[a-z0-9_]+%/i', $replaced)) {
            return null;
        }
        return $replaced;
    }

    private function parseWpRobots(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        if (str_contains(strtolower($raw), 'noindex')) {
            return 'noindex, follow';
        }
        return 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
    }

    private function toRelativePath(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return $path !== '' ? '/' . ltrim($path, '/') : '/';
    }

    private function decodeText(string $val): string
    {
        return trim(html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function upsertSourceUrlRouteBatch(array $rows, string $snapshotId, string $runId): void
    {
        if (empty($rows)) return;
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $payloads = [];
        foreach ($rows as $r) {
            $norm = $r['normalized_path'];
            $unslashed = $r['unslashed_path'];
            $payloads[] = [
                'path_hash'             => hash('sha256', $norm),
                'unslashed_hash'        => hash('sha256', $unslashed),
                'normalized_path'       => $norm,
                'unslashed_path'        => $unslashed,
                'entity_type'           => $r['entity_type'],
                'entity_id'             => $r['entity_id'],
                'source_wp_id'          => $r['source_wp_id'],
                'http_status'           => 200,
                'redirect_target'       => null,
                'trailing_slash_policy' => 'require_slash',
                'canonical_url'         => $r['canonical_url'],
                'in_sitemap'            => $r['in_sitemap'],
                'is_cutover_ready'      => true,
                'conflict_note'         => $r['conflict_note'],
                'source_snapshot_id'    => $snapshotId,
                'import_batch_id'       => $runId,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }
        foreach (array_chunk($payloads, 500) as $ch) {
            DB::table('source_url_routes')->upsert(
                $ch,
                ['path_hash'],
                ['unslashed_hash','normalized_path','unslashed_path','entity_type','entity_id','source_wp_id','http_status','trailing_slash_policy','canonical_url','in_sitemap','is_cutover_ready','conflict_note','source_snapshot_id','import_batch_id','updated_at']
            );
        }
    }

    private function streamSqliteToSqlInsert($fp, PDO $pdo, string $sql, string $tableName, array $cols, callable $quote): int
    {
        $stmt = $pdo->query($sql);
        $colList = '`' . implode('`,`', $cols) . '`';
        $batch = [];
        $count = 0;
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map($quote, $row)) . ')';
            $count++;
            if (count($batch) >= 500) {
                fwrite($fp, "INSERT INTO `{$tableName}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if (!empty($batch)) {
            fwrite($fp, "INSERT INTO `{$tableName}` ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        return $count;
    }

    /**
     * Parse one or multiple SQL value tuples on a line cleanly preserving escaped strings & newlines.
     *
     * @return array<int, array<int, string|null>>
     */
    private function parseSqlTuplesOnLine(string $line): array
    {
        $rows = [];
        $len = strlen($line);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && $line[$i] !== '(') {
                $i++;
            }
            if ($i >= $len) break;
            $i++; // skip '('

            $row = [];
            while ($i < $len) {
                while ($i < $len && ($line[$i] === ' ' || $line[$i] === "\t" || $line[$i] === "\r" || $line[$i] === "\n")) {
                    $i++;
                }
                if ($i >= $len) break;
                if ($line[$i] === ')') {
                    $i++;
                    break;
                }

                if ($line[$i] === "'") {
                    $i++;
                    $buf = '';
                    while ($i < $len) {
                        $ch = $line[$i];
                        if ($ch === '\\' && $i + 1 < $len) {
                            $nxt = $line[$i + 1];
                            $buf .= match ($nxt) {
                                'n'  => "\n",
                                'r'  => "\r",
                                't'  => "\t",
                                '0'  => "\0",
                                '\\' => '\\',
                                "'"  => "'",
                                '"'  => '"',
                                default => $nxt,
                            };
                            $i += 2;
                            continue;
                        }
                        if ($ch === "'") {
                            if ($i + 1 < $len && $line[$i + 1] === "'") {
                                $buf .= "'";
                                $i += 2;
                                continue;
                            }
                            $i++;
                            break;
                        }
                        $buf .= $ch;
                        $i++;
                    }
                    $row[] = $buf;
                } else {
                    $start = $i;
                    while ($i < $len && $line[$i] !== ',' && $line[$i] !== ')') {
                        $i++;
                    }
                    $tok = trim(substr($line, $start, $i - $start));
                    $row[] = strtoupper($tok) === 'NULL' ? null : $tok;
                }

                while ($i < $len && ($line[$i] === ' ' || $line[$i] === "\t")) {
                    $i++;
                }
                if ($i < $len && $line[$i] === ',') {
                    $i++;
                    continue;
                }
                if ($i < $len && $line[$i] === ')') {
                    $i++;
                    break;
                }
            }
            if (!empty($row)) {
                $rows[] = $row;
            }
            while ($i < $len && ($line[$i] === ',' || $line[$i] === ';' || $line[$i] === ' ' || $line[$i] === "\r" || $line[$i] === "\n")) {
                $i++;
            }
        }

        return $rows;
    }
}
