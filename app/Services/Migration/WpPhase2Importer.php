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
use RuntimeException;

class WpPhase2Importer
{
    public const APPROVED_PLAN_TOKEN = 'HAIPHONGTECH_PHASE2_SAFE';

    public function __construct(
        private readonly StagingBackupService $backupService,
        private readonly WpHtmlNormalizer $normalizer
    ) {
    }

    /**
     * Execute Phase 2 import in dry-run (default, transactional rollback) or staging apply mode.
     * Designed with constant-memory chunked lookups so both Track B Pilot (hundreds) and Track A Bulk (133k+) scale safely.
     */
    public function run(
        string $snapshotId = 'snap_phase2_pilot',
        bool $apply = false,
        string $approvedPlan = '',
        bool $forceOverwrite = false,
        ?string $customSourceDir = null,
        string $productsFilename = 'pilot_products.jsonl',
        int $chunkSize = 500
    ): array {
        $this->backupService->assertSafeStagingTarget();

        $dataDir = $customSourceDir ?: base_path('docs/migration/data/phase2');
        $catsPath = "{$dataDir}/product_categories.jsonl";
        $brandsPath = "{$dataDir}/product_brands.jsonl";
        $tagsPath = "{$dataDir}/product_tags.jsonl";
        $productsPath = "{$dataDir}/{$productsFilename}";
        $seoBaselinePath = "{$dataDir}/phase2_seo_baseline.jsonl";

        foreach ([$catsPath, $brandsPath, $productsPath] as $reqFile) {
            if (!File::exists($reqFile)) {
                throw new RuntimeException("Preflight FAILED: Missing required Phase 2 inventory file [{$reqFile}].");
            }
        }

        if ($apply) {
            if (strtolower((string) config('app.env')) !== 'staging') {
                throw new RuntimeException('Preflight FAILED: --apply requires APP_ENV=staging.');
            }
            if ($approvedPlan !== self::APPROVED_PLAN_TOKEN) {
                throw new RuntimeException('Preflight FAILED: --apply requires --approved-plan=' . self::APPROVED_PLAN_TOKEN);
            }
        }

        $seoBaseline = File::exists($seoBaselinePath) ? $this->loadSeoBaseline($seoBaselinePath) : [];
        $runId = 'run_' . date('Ymd_His') . '_phase2_' . ($apply ? 'apply' : 'dryrun');
        $connName = (string) config('database.default');
        $dbName = (string) config("database.connections.{$connName}.database");
        $backupId = null;

        if ($apply) {
            $bkp = $this->backupService->createBackup('pre_' . $runId);
            $backupId = $bkp['backup_id'];
        }

        $counters = [
            'media'        => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'brands'       => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'categories'   => ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => 0],
            'product_tags' => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'products'     => ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => 0],
            'url_routes'   => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
        ];
        $conflicts = [];
        $errors = [];
        $startedAt = Carbon::now('UTC');

        $chunkSize = max(50, $chunkSize);
        $usePerChunkCommit = $apply && ($this->countLines($productsPath) > 5000);

        if (!$usePerChunkCommit) {
            DB::beginTransaction();
        }

        try {
            // Step 1: Import Brands (custom taxonomy thuong_hieu)
            $brandImport = $this->importBrands(
                $brandsPath,
                $seoBaseline,
                $runId,
                $forceOverwrite,
                $counters['brands'],
                $conflicts
            );
            $brandWpIdToLocal = $brandImport['wp_to_local'];
            $brandRouteRecords = $brandImport['routes'];

            // Step 2: Import Product Categories & their thumbnail images into media
            $catImport = $this->importProductCategories(
                $catsPath,
                $seoBaseline,
                $runId,
                $forceOverwrite,
                $counters['categories'],
                $counters['media'],
                $conflicts
            );
            $catWpIdToLocal = $catImport['wp_to_local'];
            $catRouteRecords = $catImport['routes'];

            // Step 3: Stream-import Product Tags in chunks
            $tagWpIdToLocal = [];
            $tagRouteRecords = [];
            if (File::exists($tagsPath)) {
                $tagImport = $this->importProductTags(
                    $tagsPath,
                    $seoBaseline,
                    $runId,
                    $chunkSize,
                    $counters['product_tags']
                );
                $tagWpIdToLocal = $tagImport['wp_to_local'];
                $tagRouteRecords = $tagImport['routes'];
            }

            // Activate Shop + Brand + Category + Tag routes in batched chunks
            $staticRoutes = array_merge(
                [[
                    'normalized_path' => '/shop/',
                    'unslashed_path'  => '/shop',
                    'entity_type'     => 'shop_index',
                    'entity_id'       => null,
                    'source_wp_id'    => null,
                    'canonical_url'   => '/shop/',
                    'conflict_note'   => 'Activated in Phase 2 for WooCommerce Shop archive',
                ]],
                $catRouteRecords,
                $brandRouteRecords,
                $tagRouteRecords
            );
            foreach (array_chunk($staticRoutes, $chunkSize) as $routeBatch) {
                $this->upsertSourceUrlRouteBatch($routeBatch, $snapshotId, $runId, $counters['url_routes']);
            }

            // Step 4: Stream-import Products (with N:N categories, product_tags, media gallery) & Product routes per chunk
            $this->streamImportProductsAndRoutes(
                $productsPath,
                $catWpIdToLocal,
                $brandWpIdToLocal,
                $tagWpIdToLocal,
                $seoBaseline,
                $snapshotId,
                $runId,
                $chunkSize,
                $forceOverwrite,
                $usePerChunkCommit,
                $counters,
                $conflicts
            );

            if (!$usePerChunkCommit) {
                if ($apply) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }
            }

            if ($apply) {
                Cache::flush();
            }
        } catch (\Throwable $e) {
            if (!$usePerChunkCommit && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $errors[] = $e->getMessage();
            throw $e;
        }

        $completedAt = Carbon::now('UTC');

        $totals = ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => count($conflicts), 'errors' => count($errors)];
        foreach ($counters as $grp) {
            $totals['inserts'] += $grp['inserts'] ?? 0;
            $totals['updates'] += $grp['updates'] ?? 0;
            $totals['skips']   += $grp['skips'] ?? 0;
        }

        MigrationRun::create([
            'run_id'               => $runId,
            'snapshot_id'          => $snapshotId,
            'snapshot_checksum'    => hash_file('sha256', $productsPath),
            'environment'          => (string) config('app.env'),
            'database_name'        => $dbName,
            'mode'                 => $apply ? 'apply' : 'dry-run',
            'status'               => empty($errors) ? 'completed' : 'failed',
            'backup_checkpoint_id' => $backupId,
            'counters_json'        => json_encode(['totals' => $totals, 'by_group' => $counters], JSON_UNESCAPED_UNICODE),
            'conflicts_json'       => json_encode($conflicts, JSON_UNESCAPED_UNICODE),
            'errors_json'          => json_encode($errors, JSON_UNESCAPED_UNICODE),
            'started_at'           => $startedAt,
            'completed_at'         => $completedAt,
        ]);

        return [
            'run_id'               => $runId,
            'mode'                 => $apply ? 'apply' : 'dry-run',
            'snapshot_id'          => $snapshotId,
            'environment'          => (string) config('app.env'),
            'database'             => $dbName,
            'backup_checkpoint_id' => $backupId,
            'totals'               => $totals,
            'by_group'             => $counters,
            'conflicts'            => $conflicts,
            'errors'               => $errors,
        ];
    }

    private function loadSeoBaseline(string $seoBaselinePath): array
    {
        $map = [];
        $fp = fopen($seoBaselinePath, 'r');
        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            $row = json_decode($trimmed, true);
            if (!empty($row['normalized_path'])) {
                $map[$row['normalized_path']] = $row;
                if (!empty($row['canonical_url'])) {
                    $canonPath = (string) parse_url((string) $row['canonical_url'], PHP_URL_PATH);
                    if ($canonPath !== '' && $canonPath !== '/' && !isset($map[$canonPath])) {
                        $canonPathNorm = '/' . trim($canonPath, '/') . '/';
                        $map[$canonPathNorm] = $row;
                    }
                }
            }
        }
        fclose($fp);
        return $map;
    }

    private function importBrands(
        string $brandsPath,
        array $seoBaseline,
        string $runId,
        bool $forceOverwrite,
        array &$c,
        array &$conflicts
    ): array {
        $wpIdToLocal = [];
        $existing = Brand::all()->keyBy('slug');
        $routes = [];

        $fp = fopen($brandsPath, 'r');
        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            $row = json_decode($trimmed, true);
            $wpId = (int) $row['source_wp_id'];
            $slug = (string) $row['slug'];
            $name = (string) $row['name'];
            $normPath = "/thuong_hieu/{$slug}/";
            $seo = $row['seo'] ?? ($seoBaseline[$normPath] ?? []);

            $metaTitle = $seo['meta_title'] ?? null;
            $metaDesc = $seo['meta_description'] ?? null;
            $metaRobots = $seo['meta_robots'] ?? null;
            $canonical = !empty($seo['canonical_url']) ? $this->toRelativePathIfInternal($seo['canonical_url']) : $normPath;
            $schemaJson = $seo['schema_json'] ?? null;

            $payload = [
                'name'              => $name,
                'slug'              => $slug,
                'content'           => ($row['description_html'] ?? '') ?: null,
                'meta_title'        => $metaTitle,
                'meta_description'  => $metaDesc,
                'source_wp_id'      => $wpId,
                'source_taxonomy'   => 'thuong_hieu',
                'source_permalink'  => (string) $row['source_permalink'],
                'meta_robots'       => $metaRobots,
                'canonical_url'     => $canonical,
                'schema_json'       => $schemaJson,
                'status'            => 'active',
                'in_sitemap'        => true,
                'import_batch_id'   => $runId,
            ];

            if (!isset($existing[$slug])) {
                $b = Brand::create($payload);
                $existing[$slug] = $b;
                $c['inserts']++;
            } else {
                $b = $existing[$slug];
                if ($b->import_batch_id === null && $b->source_wp_id === null && !$forceOverwrite) {
                    $conflicts[] = ['type' => 'brand', 'slug' => $slug, 'reason' => 'Pre-existing unbatched record protected'];
                    continue;
                }
                if ((int) $b->source_wp_id === $wpId
                    && $b->name === $name
                    && (string) $b->meta_title === (string) $metaTitle
                    && (string) $b->meta_description === (string) $metaDesc
                    && (string) $b->canonical_url === (string) $canonical
                    && (string) $b->meta_robots === (string) $metaRobots
                ) {
                    $c['skips']++;
                } else {
                    $b->update($payload);
                    $c['updates']++;
                }
            }

            $wpIdToLocal[$wpId] = $b->id;
            $routes[] = [
                'normalized_path' => $normPath,
                'unslashed_path'  => rtrim($normPath, '/'),
                'entity_type'     => 'product_brand',
                'entity_id'       => $b->id,
                'source_wp_id'    => $wpId,
                'canonical_url'   => $canonical,
                'conflict_note'   => null,
            ];
        }
        fclose($fp);

        return [
            'wp_to_local' => $wpIdToLocal,
            'routes'      => $routes,
        ];
    }

    private function importProductCategories(
        string $catsPath,
        array $seoBaseline,
        string $runId,
        bool $forceOverwrite,
        array &$c,
        array &$mediaCounter,
        array &$conflicts
    ): array {
        $rows = [];
        $imageUrls = [];
        $fp = fopen($catsPath, 'r');
        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            $row = json_decode($trimmed, true);
            $rows[] = $row;
            if (!empty($row['image_url'])) {
                $imageUrls[] = ['url' => (string) $row['image_url'], 'alt' => (string) ($row['name'] ?? '')];
            }
        }
        fclose($fp);

        // Register all category images in batches cleanly
        $mediaMap = $this->upsertWpUploadMediaBatch($imageUrls, $runId, $mediaCounter);

        // Parents first so parent_id links reliably
        usort($rows, fn($a, $b) => ((int) ($a['parent_wp_id'] ?? 0)) <=> ((int) ($b['parent_wp_id'] ?? 0)));

        $wpIdToLocal = [];
        $existing = Category::where('type', 'product')->get()->keyBy('slug');
        $routes = [];

        foreach ($rows as $row) {
            $wpId = (int) $row['source_wp_id'];
            $slug = (string) $row['slug'];
            $name = (string) $row['name'];
            $parentWpId = (int) ($row['parent_wp_id'] ?? 0);
            $parentLocalId = $parentWpId > 0 ? ($wpIdToLocal[$parentWpId] ?? null) : null;
            $normPath = "/{$slug}/";

            $seo = $row['seo'] ?? ($seoBaseline[$normPath] ?? []);
            $metaTitle = $seo['meta_title'] ?? null;
            $metaDesc = $seo['meta_description'] ?? null;
            $metaRobots = $seo['meta_robots'] ?? null;
            $canonical = !empty($seo['canonical_url']) ? $this->toRelativePathIfInternal($seo['canonical_url']) : $normPath;
            $schemaJson = $seo['schema_json'] ?? null;

            $iconRelPath = null;
            if (!empty($row['image_url'])) {
                $parsed = (string) parse_url((string) $row['image_url'], PHP_URL_PATH);
                if (preg_match('#^(/wp-content/uploads/.+)$#i', $parsed, $m)) {
                    $iconRelPath = $m[1];
                }
            }

            $payload = [
                'name'             => $name,
                'slug'             => $slug,
                'description'      => ($row['description_html'] ?? '') ?: null,
                'icon'             => $iconRelPath,
                'parent_id'        => $parentLocalId,
                'type'             => 'product',
                'status'           => 'active',
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDesc,
                'meta_robots'      => $metaRobots,
                'canonical_url'    => $canonical,
                'schema_json'      => $schemaJson,
                'source_wp_id'     => $wpId,
                'source_taxonomy'  => 'product_cat',
                'source_permalink' => (string) $row['source_permalink'],
                'in_sitemap'       => true,
                'import_batch_id'  => $runId,
            ];

            if (!isset($existing[$slug])) {
                $cat = Category::create($payload);
                $existing[$slug] = $cat;
                $c['inserts']++;
            } else {
                $cat = $existing[$slug];
                if ($cat->import_batch_id === null && $cat->source_wp_id === null && !$forceOverwrite) {
                    $wpIdToLocal[$wpId] = $cat->id;
                    $conflicts[] = ['type' => 'product_cat', 'slug' => $slug, 'reason' => 'Pre-existing unbatched record protected'];
                    continue;
                }
                if ((int) $cat->source_wp_id === $wpId
                    && (int) $cat->parent_id === (int) $parentLocalId
                    && $cat->name === $name
                    && (string) $cat->icon === (string) $iconRelPath
                    && (string) $cat->meta_title === (string) $metaTitle
                    && (string) $cat->meta_description === (string) $metaDesc
                    && (string) $cat->canonical_url === (string) $canonical
                    && (string) $cat->meta_robots === (string) $metaRobots
                ) {
                    $c['skips']++;
                } else {
                    $cat->update($payload);
                    $c['updates']++;
                }
            }

            $wpIdToLocal[$wpId] = $cat->id;
            $routes[] = [
                'normalized_path' => $normPath,
                'unslashed_path'  => rtrim($normPath, '/'),
                'entity_type'     => 'product_category',
                'entity_id'       => $cat->id,
                'source_wp_id'    => $wpId,
                'canonical_url'   => $canonical,
                'conflict_note'   => 'Activated in Phase 2 for WooCommerce product category',
            ];
        }

        return [
            'wp_to_local' => $wpIdToLocal,
            'routes'      => $routes,
        ];
    }

    private function importProductTags(
        string $tagsPath,
        array $seoBaseline,
        string $runId,
        int $chunkSize,
        array &$c
    ): array {
        $wpIdToLocal = [];
        $routes = [];

        $fp = fopen($tagsPath, 'r');
        $batch = [];
        $flushTagBatch = function (array $rows) use (&$wpIdToLocal, &$routes, $seoBaseline, $runId, &$c) {
            if (empty($rows)) return;
            $slugs = array_map(fn($r) => (string) $r['slug'], $rows);
            $existing = ProductTag::whereIn('slug', $slugs)->get()->keyBy('slug');

            foreach ($rows as $row) {
                $wpId = (int) $row['source_wp_id'];
                $slug = (string) $row['slug'];
                $name = (string) $row['name'];
                $normPath = "/tag-san-pham/{$slug}/";
                $seo = $row['seo'] ?? ($seoBaseline[$normPath] ?? []);

                $payload = [
                    'name'                  => $name,
                    'slug'                  => $slug,
                    'description'           => ($row['description'] ?? '') ?: null,
                    'products_count_source' => (int) ($row['products_count_source'] ?? 0),
                    'meta_title'            => $seo['meta_title'] ?? null,
                    'meta_description'      => $seo['meta_description'] ?? null,
                    'meta_robots'           => $seo['meta_robots'] ?? 'noindex, follow',
                    'canonical_url'         => null,
                    'source_wp_id'          => $wpId,
                    'source_permalink'      => (string) ($row['source_permalink'] ?? $normPath),
                    'in_sitemap'            => false,
                    'status'                => 'active',
                    'import_batch_id'       => $runId,
                ];

                if (!isset($existing[$slug])) {
                    $tag = ProductTag::create($payload);
                    $existing[$slug] = $tag;
                    $c['inserts']++;
                } else {
                    $tag = $existing[$slug];
                    if ((int) $tag->source_wp_id === $wpId
                        && $tag->name === $name
                        && (int) $tag->products_count_source === (int) $payload['products_count_source']
                        && (string) $tag->meta_title === (string) $payload['meta_title']
                    ) {
                        $c['skips']++;
                    } else {
                        $tag->update($payload);
                        $c['updates']++;
                    }
                }

                $wpIdToLocal[$wpId] = $tag->id;
                $routes[] = [
                    'normalized_path' => $normPath,
                    'unslashed_path'  => rtrim($normPath, '/'),
                    'entity_type'     => 'product_tag',
                    'entity_id'       => $tag->id,
                    'source_wp_id'    => $wpId,
                    'canonical_url'   => null,
                    'in_sitemap'      => false,
                    'conflict_note'   => null,
                ];
            }
        };

        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            $batch[] = json_decode($trimmed, true);
            if (count($batch) >= $chunkSize) {
                $flushTagBatch($batch);
                $batch = [];
            }
        }
        $flushTagBatch($batch);
        fclose($fp);

        return [
            'wp_to_local' => $wpIdToLocal,
            'routes'      => $routes,
        ];
    }

    private function streamImportProductsAndRoutes(
        string $productsPath,
        array $catWpIdToLocal,
        array $brandWpIdToLocal,
        array $tagWpIdToLocal,
        array $seoBaseline,
        string $snapshotId,
        string $runId,
        int $chunkSize,
        bool $forceOverwrite,
        bool $usePerChunkCommit,
        array &$counters,
        array &$conflicts
    ): void {
        $chunkSizeUrlsPerSitemap = (int) config('migration.phase2.sitemap_chunk_size', 2000);
        $globalRowIndex = 0;

        $fp = fopen($productsPath, 'r');
        $batch = [];

        $processBatch = function (array $rows) use (
            &$globalRowIndex,
            $chunkSizeUrlsPerSitemap,
            $catWpIdToLocal,
            $brandWpIdToLocal,
            $tagWpIdToLocal,
            $seoBaseline,
            $snapshotId,
            $runId,
            $forceOverwrite,
            $usePerChunkCommit,
            &$counters,
            &$conflicts
        ) {
            if (empty($rows)) return;

            if ($usePerChunkCommit) {
                DB::beginTransaction();
            }

            try {
                // 1. Batch-register all image media for this chunk
                $mediaItems = [];
                foreach ($rows as $row) {
                    foreach (($row['images'] ?? []) as $img) {
                        if (!empty($img['src'])) {
                            $mediaItems[] = [
                                'url'              => (string) $img['src'],
                                'wp_attachment_id' => !empty($img['id']) ? (int) $img['id'] : null,
                                'alt'              => (string) ($img['alt'] ?? ($img['name'] ?? $row['name'] ?? '')),
                                'title'            => (string) ($img['name'] ?? $row['name'] ?? ''),
                            ];
                        }
                    }
                }
                $mediaIdByRelPath = $this->upsertWpUploadMediaBatch($mediaItems, $runId, $counters['media']);

                // 2. Scoped lookup of existing products in this batch (O(batch), never full-table!)
                $batchSlugs = array_map(fn($r) => (string) $r['slug'], $rows);
                $existingBySlug = Product::whereIn('slug', $batchSlugs)
                    ->with(['categories:id', 'productTags:id'])
                    ->get()
                    ->keyBy('slug');

                $routeBatch = [];

                foreach ($rows as $row) {
                    $idxPos = $globalRowIndex++;
                    $wpId = (int) $row['source_wp_id'];
                    $slug = (string) $row['slug'];
                    $name = (string) $row['name'];
                    $sku = !empty($row['sku']) ? (string) $row['sku'] : null;
                    $normPath = "/product/{$slug}/";

                    $catWpIds = $row['product_cat_wp_ids'] ?? [];
                    $primaryCatId = null;
                    $localCatIds = [];
                    foreach ($catWpIds as $cw) {
                        if (isset($catWpIdToLocal[(int) $cw])) {
                            $lid = $catWpIdToLocal[(int) $cw];
                            $localCatIds[] = $lid;
                            if ($primaryCatId === null) {
                                $primaryCatId = $lid;
                            }
                        }
                    }
                    $localCatIds = array_values(array_unique($localCatIds));

                    $brandId = null;
                    foreach (($row['thuong_hieu_wp_ids'] ?? []) as $bw) {
                        if (isset($brandWpIdToLocal[(int) $bw])) {
                            $brandId = $brandWpIdToLocal[(int) $bw];
                            break;
                        }
                    }

                    $localTagIds = [];
                    foreach (($row['product_tag_wp_ids'] ?? []) as $tw) {
                        if (isset($tagWpIdToLocal[(int) $tw])) {
                            $localTagIds[] = $tagWpIdToLocal[(int) $tw];
                        }
                    }
                    $localTagIds = array_values(array_unique($localTagIds));

                    $resolvedMedia = [];
                    foreach (($row['images'] ?? []) as $pos => $img) {
                        $rel = $this->extractWpUploadRelPath((string) ($img['src'] ?? ''));
                        if ($rel !== null && isset($mediaIdByRelPath[$rel])) {
                            $resolvedMedia[] = [
                                'media_id' => $mediaIdByRelPath[$rel],
                                'role'     => $pos === 0 ? 'thumbnail' : 'gallery',
                                'position' => $pos,
                            ];
                        }
                    }

                    $rawContent = (string) ($row['content_raw'] ?? '');
                    $normalizedContent = $this->normalizer->normalize($rawContent);
                    $rawShort = (string) ($row['short_description_raw'] ?? '');
                    $normalizedShort = $this->normalizer->normalize($rawShort);

                    $seo = $row['seo'] ?? ($seoBaseline[$normPath] ?? []);
                    $metaTitle = $seo['meta_title'] ?? null;
                    $metaDesc = $seo['meta_description'] ?? null;
                    $metaRobots = $seo['meta_robots'] ?? 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
                    $canonical = !empty($seo['canonical_url']) ? $this->toRelativePathIfInternal($seo['canonical_url']) : $normPath;
                    $schemaJson = $seo['schema_json'] ?? null;

                    $price = (float) ($row['price'] ?? 0);
                    $salePrice = isset($row['sale_price']) && $row['sale_price'] !== null ? (float) $row['sale_price'] : null;
                    $sitemapChunk = isset($row['sitemap_chunk'])
                        ? (int) $row['sitemap_chunk']
                        : (int) floor($idxPos / max(1, $chunkSizeUrlsPerSitemap)) + 1;

                    $contentHash = sha1(json_encode([
                        $rawContent,
                        $rawShort,
                        $sku,
                        $price,
                        $salePrice,
                        $primaryCatId,
                        $localCatIds,
                        $brandId,
                        $localTagIds,
                        $resolvedMedia,
                        $metaTitle,
                        $metaDesc,
                        $canonical,
                        $sitemapChunk,
                    ]));

                    $publishedAt = !empty($row['published_at_gmt'])
                        ? Carbon::parse($row['published_at_gmt'], 'UTC')->setTimezone('Asia/Ho_Chi_Minh')
                        : Carbon::now('Asia/Ho_Chi_Minh');

                    $payload = [
                        'name'                  => $name,
                        'slug'                  => $slug,
                        'sku'                   => $sku,
                        'price'                 => $price,
                        'sale_price'            => $salePrice,
                        'short_description'     => $normalizedShort ?: null,
                        'content'               => $normalizedContent ?: null,
                        'raw_content'           => $rawContent ?: null,
                        'raw_short_description' => $rawShort ?: null,
                        'content_hash'          => $contentHash,
                        'category_id'           => $primaryCatId,
                        'brand_id'              => $brandId,
                        'product_type'          => $row['product_type'] ?? 'simple',
                        'stock_status'          => ($row['is_in_stock'] ?? true) ? 'instock' : 'outofstock',
                        'attributes_json'       => !empty($row['attributes']) ? $row['attributes'] : null,
                        'status'                => 'active',
                        'published_at'          => $publishedAt,
                        'source_wp_id'          => $wpId,
                        'source_permalink'      => (string) $row['source_permalink'],
                        'meta_title'            => $metaTitle,
                        'meta_description'      => $metaDesc,
                        'meta_robots'           => $metaRobots,
                        'canonical_url'         => $canonical,
                        'schema_json'           => $schemaJson,
                        'in_sitemap'            => true,
                        'sitemap_chunk'         => $sitemapChunk,
                        'import_batch_id'       => $runId,
                    ];

                    $wasMutated = false;
                    if (!isset($existingBySlug[$slug])) {
                        $prod = Product::create($payload);
                        $existingBySlug[$slug] = $prod;
                        $counters['products']['inserts']++;
                        $wasMutated = true;
                    } else {
                        $prod = $existingBySlug[$slug];
                        if ($prod->import_batch_id === null && $prod->source_wp_id === null && !$forceOverwrite) {
                            $conflicts[] = ['type' => 'product', 'slug' => $slug, 'reason' => 'Pre-existing unbatched record protected'];
                            continue;
                        }
                        if ((int) $prod->source_wp_id === $wpId && $prod->content_hash === $contentHash) {
                            $counters['products']['skips']++;
                        } else {
                            $prod->update($payload);
                            $counters['products']['updates']++;
                            $wasMutated = true;
                        }
                    }

                    if ($wasMutated) {
                        $syncCats = [];
                        foreach ($localCatIds as $cIdx => $cid) {
                            $syncCats[$cid] = ['is_primary' => $cIdx === 0];
                        }
                        if (!empty($syncCats)) {
                            $prod->categories()->sync($syncCats);
                        }

                        if (!empty($localTagIds)) {
                            $prod->productTags()->sync($localTagIds);
                        }

                        // Sync product_media (thumbnail + gallery)
                        DB::table('product_media')->where('product_id', $prod->id)->whereIn('role', ['thumbnail', 'gallery'])->delete();
                        foreach ($resolvedMedia as $rm) {
                            DB::table('product_media')->insert([
                                'product_id' => $prod->id,
                                'media_id'   => $rm['media_id'],
                                'role'       => $rm['role'],
                                'position'   => $rm['position'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    $routeBatch[] = [
                        'normalized_path' => $normPath,
                        'unslashed_path'  => rtrim($normPath, '/'),
                        'entity_type'     => 'product',
                        'entity_id'       => $prod->id,
                        'source_wp_id'    => $wpId,
                        'canonical_url'   => $canonical,
                        'conflict_note'   => null,
                    ];
                }

                $this->upsertSourceUrlRouteBatch($routeBatch, $snapshotId, $runId, $counters['url_routes']);

                if ($usePerChunkCommit) {
                    DB::commit();
                }
            } catch (\Throwable $e) {
                if ($usePerChunkCommit) {
                    DB::rollBack();
                }
                throw $e;
            }
        };

        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            $batch[] = json_decode($trimmed, true);
            if (count($batch) >= $chunkSize) {
                $processBatch($batch);
                $batch = [];
            }
        }
        $processBatch($batch);
        fclose($fp);
    }

    /**
     * Scoped batch registration for /wp-content/uploads/ media (bounded RAM even at 500k images).
     *
     * @return array<string, int> Map of wp_relative_path => media.id
     */
    private function upsertWpUploadMediaBatch(array $items, string $runId, array &$c): array
    {
        $byRel = [];
        $dedup = [];
        foreach ($items as $it) {
            $rel = $this->extractWpUploadRelPath((string) ($it['url'] ?? ''));
            if ($rel === null) continue;
            if (!isset($dedup[$rel])) {
                $dedup[$rel] = $it;
            }
        }
        if (empty($dedup)) {
            return [];
        }

        $relPaths = array_keys($dedup);
        $existingByRel = Media::whereIn('wp_relative_path', $relPaths)->get()->keyBy('wp_relative_path');

        // Also match pre-existing records by unique (folder, filename) pair cleanly
        foreach ($dedup as $rel => $it) {
            if (isset($existingByRel[$rel])) {
                $m = $existingByRel[$rel];
                $upd = [];
                if (!empty($it['wp_attachment_id']) && !$m->wp_attachment_id) {
                    $upd['wp_attachment_id'] = (int) $it['wp_attachment_id'];
                }
                if (!empty($it['alt']) && empty($m->alt)) {
                    $upd['alt'] = mb_substr($it['alt'], 0, 255);
                }
                if (!empty($upd)) {
                    $upd['import_batch_id'] = $runId;
                    $m->update($upd);
                    $c['updates']++;
                } else {
                    $c['skips']++;
                }
                $byRel[$rel] = $m->id;
                continue;
            }

            $dirPart = dirname($rel);
            $folder = 'wp-content/uploads' . ($dirPart !== '.' && $dirPart !== '' ? '/' . str_replace('\\', '/', $dirPart) : '');
            $filename = basename($rel);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            $fallback = Media::where('folder', $folder)->where('filename', $filename)->first();
            if ($fallback) {
                $c['skips']++;
                $byRel[$rel] = $fallback->id;
                continue;
            }

            $mime = match ($ext) {
                'webp' => 'image/webp',
                'png'  => 'image/png',
                'avif' => 'image/avif',
                'gif'  => 'image/gif',
                'svg'  => 'image/svg+xml',
                'pdf'  => 'application/pdf',
                default => 'image/jpeg',
            };

            $created = Media::create([
                'wp_attachment_id' => !empty($it['wp_attachment_id']) ? (int) $it['wp_attachment_id'] : null,
                'disk'             => 'wp_uploads',
                'folder'           => $folder,
                'filename'         => $filename,
                'wp_source_url'    => (string) $it['url'],
                'wp_relative_path' => $rel,
                'original_name'    => $filename,
                'extension'        => $ext ?: null,
                'mime_type'        => $mime,
                'size'             => 0,
                'title'            => !empty($it['title']) ? mb_substr((string) $it['title'], 0, 255) : null,
                'alt'              => !empty($it['alt']) ? mb_substr((string) $it['alt'], 0, 255) : null,
                'status'           => 'active',
                'import_batch_id'  => $runId,
            ]);
            $existingByRel[$rel] = $created;
            $byRel[$rel] = $created->id;
            $c['inserts']++;
        }

        return $byRel;
    }

    /**
     * Scoped batch upsert of source_url_routes comparing fields so unchanged re-runs report exact skips.
     */
    private function upsertSourceUrlRouteBatch(array $records, string $snapshotId, string $runId, array &$c): void
    {
        if (empty($records)) return;

        $hashes = [];
        foreach ($records as $r) {
            $hashes[] = hash('sha256', $r['normalized_path']);
        }
        $existing = SourceUrlRoute::whereIn('path_hash', array_values(array_unique($hashes)))->get()->keyBy('path_hash');

        foreach ($records as $r) {
            $norm = $r['normalized_path'];
            $unslashed = $r['unslashed_path'];
            $h = hash('sha256', $norm);

            $payload = [
                'path_hash'             => $h,
                'unslashed_hash'        => hash('sha256', $unslashed),
                'normalized_path'       => $norm,
                'unslashed_path'        => $unslashed,
                'entity_type'           => $r['entity_type'],
                'entity_id'             => $r['entity_id'],
                'source_wp_id'          => $r['source_wp_id'],
                'http_status'           => 200,
                'trailing_slash_policy' => 'require_slash',
                'canonical_url'         => $r['canonical_url'] ?? $norm,
                'in_sitemap'            => $r['in_sitemap'] ?? true,
                'is_cutover_ready'      => true,
                'conflict_note'         => $r['conflict_note'] ?? null,
                'source_snapshot_id'    => $snapshotId,
                'import_batch_id'       => $runId,
            ];

            if (!isset($existing[$h])) {
                $created = SourceUrlRoute::create($payload);
                $existing[$h] = $created;
                $c['inserts']++;
            } else {
                $ex = $existing[$h];
                if ($ex->entity_type === $payload['entity_type']
                    && (int) $ex->entity_id === (int) $payload['entity_id']
                    && (int) $ex->source_wp_id === (int) $payload['source_wp_id']
                    && (int) $ex->http_status === (int) $payload['http_status']
                    && (string) $ex->canonical_url === (string) $payload['canonical_url']
                    && (bool) $ex->in_sitemap === (bool) $payload['in_sitemap']
                    && (bool) $ex->is_cutover_ready === (bool) $payload['is_cutover_ready']
                ) {
                    $c['skips']++;
                } else {
                    $ex->update($payload);
                    $c['updates']++;
                }
            }
        }
    }

    private function extractWpUploadRelPath(string $url): ?string
    {
        if ($url === '') return null;
        $parsed = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('#/wp-content/uploads/(.+)$#i', $parsed, $m)) {
            return rawurldecode($m[1]);
        }
        return null;
    }

    private function toRelativePathIfInternal(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || str_contains($host, 'haiphongtech.vn')) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            return $path !== '' ? $path : '/';
        }
        return $url;
    }

    private function countLines(string $path): int
    {
        $count = 0;
        $fp = fopen($path, 'r');
        while (fgets($fp) !== false) {
            $count++;
        }
        fclose($fp);
        return $count;
    }
}
