<?php

namespace App\Services\Migration;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Media;
use App\Models\MigrationRun;
use App\Models\NavigationMenu;
use App\Models\NavigationMenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SourceUrlRoute;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class WpPhase1Importer
{
    public const APPROVED_PLAN_TOKEN = 'HAIPHONGTECH_PHASE1_SAFE';

    private const POLICY_SLUGS = [
        'chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001',
        'chinh-sach-bao-mat-thong-tin-ca-nhan-information-privacy',
        'chinh-sach-thanh-toan-an-toan-da-dang-cod-chuyen-khoan',
        'chinh-sach-bao-hanh-thiet-bi-cong-nghiep-chinh-hang-warranty-policy',
        'chinh-sach-van-chuyen-nhanh-an-toan-giao-toan-quoc',
        'chinh-sach-doi-tra-san-pham-return-refund-policy',
        'dieu-khoan-su-dung-website-quy-dinh-trach-nhiem',
        'chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy',
    ];

    public function __construct(
        private readonly StagingBackupService $backupService,
        private readonly WpHtmlNormalizer $normalizer
    ) {
    }

    /**
     * Execute Phase 1 import in either dry-run (default, rolls back 100% of writes) or staging apply mode.
     */
    public function run(
        string $snapshotId,
        bool $apply = false,
        string $approvedPlan = '',
        bool $forceOverwrite = false
    ): array {
        // 1. Mandatory environment & target database preflight
        $this->backupService->assertSafeStagingTarget();

        $dataDir = base_path('docs/migration/data');
        $summaryPath = "{$dataDir}/snapshot_summary.json";
        $manifestPath = "{$dataDir}/content_manifest.jsonl";
        $urlsPath = "{$dataDir}/source_urls.csv";
        $mediaPath = "{$dataDir}/media_manifest.csv";
        $redirectsPath = "{$dataDir}/redirects.csv";
        $collisionsPath = "{$dataDir}/url_collisions.csv";

        foreach ([$summaryPath, $manifestPath, $urlsPath, $mediaPath, $redirectsPath, $collisionsPath] as $reqFile) {
            if (!File::exists($reqFile)) {
                throw new RuntimeException("Preflight FAILED: Missing required inventory file [{$reqFile}].");
            }
        }

        $summary = json_decode(File::get($summaryPath), true);
        if (($summary['snapshot_id'] ?? '') !== $snapshotId) {
            throw new RuntimeException("Preflight FAILED: Snapshot ID mismatch. Expected [{$snapshotId}], found [" . ($summary['snapshot_id'] ?? 'null') . '].');
        }

        $actualManifestSha = hash_file('sha256', $manifestPath);
        if ($actualManifestSha !== ($summary['checksums']['content_manifest_sha256'] ?? '')) {
            throw new RuntimeException('Preflight FAILED: content_manifest.jsonl SHA-256 checksum mismatch!');
        }

        if ($apply) {
            if (strtolower((string) config('app.env')) !== 'staging') {
                throw new RuntimeException('Preflight FAILED: --apply requires APP_ENV=staging.');
            }
            if ($approvedPlan !== self::APPROVED_PLAN_TOKEN) {
                throw new RuntimeException('Preflight FAILED: --apply requires --approved-plan=' . self::APPROVED_PLAN_TOKEN);
            }
        }

        $runId = 'run_' . date('Ymd_His') . '_' . ($apply ? 'apply' : 'dryrun');
        $connName = (string) config('database.default');
        $dbName = (string) config("database.connections.{$connName}.database");
        $backupId = null;

        if ($apply) {
            $bkp = $this->backupService->createBackup('pre_' . $runId);
            $backupId = $bkp['backup_id'];
        }

        $counters = [
            'settings'     => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'banners'      => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'media'        => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'pages'        => ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => 0],
            'categories'   => ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => 0],
            'tags'         => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'posts'        => ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => 0],
            'menus'        => ['inserts' => 0, 'updates' => 0, 'skips' => 0],
            'url_routes'   => ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => 0],
        ];
        $conflicts = [];
        $errors = [];
        $startedAt = Carbon::now('UTC');

        // Load collisions & redirects maps first
        $exactCollisionPaths = $this->loadExactCollisionPaths($collisionsPath);
        $redirectsMap = $this->loadRedirectsMap($redirectsPath);
        $sourceUrlsInfo = $this->loadSourceUrlsMap($urlsPath);

        DB::beginTransaction();
        try {
            // Step 1: Enterprise Branding & Settings (Hai Phong Tech)
            $this->importSettings($runId, $counters['settings']);
            $this->importDefaultStagingBanners($runId, $counters['banners']);

            // Step 2: Media Assets (preserving exact /wp-content/uploads/... paths)
            $mediaUrlToId = $this->importMediaManifest($mediaPath, $runId, $counters['media']);

            // Parse content_manifest.jsonl into buckets
            $manifestBuckets = ['page' => [], 'post' => [], 'post_category' => [], 'post_tag' => []];
            $fp = fopen($manifestPath, 'r');
            while (($line = fgets($fp)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') continue;
                $row = json_decode($trimmed, true);
                $type = $row['source_type'] ?? '';
                if (isset($manifestBuckets[$type])) {
                    $manifestBuckets[$type][] = $row;
                }
            }
            fclose($fp);

            // Step 3: Import Pages (parent-first order)
            $wpPageIdToLocal = $this->importPages(
                $manifestBuckets['page'],
                $mediaUrlToId,
                $redirectsMap,
                $runId,
                $forceOverwrite,
                $counters['pages'],
                $conflicts
            );

            // Step 4: Import Post Categories & Post Tags
            $wpCatIdToLocal = $this->importPostCategories(
                $manifestBuckets['post_category'],
                $runId,
                $forceOverwrite,
                $counters['categories'],
                $conflicts
            );
            $wpTagIdToLocal = $this->importPostTags(
                $manifestBuckets['post_tag'],
                $runId,
                $counters['tags']
            );

            // Step 5: Import Posts (with category_post, post_tag, post_media, redirect & collision flags)
            $wpPostIdToLocal = $this->importPosts(
                $manifestBuckets['post'],
                $wpCatIdToLocal,
                $wpTagIdToLocal,
                $mediaUrlToId,
                $exactCollisionPaths,
                $redirectsMap,
                $runId,
                $forceOverwrite,
                $counters['posts'],
                $conflicts
            );

            // Step 6: Import Header & Footer Navigation Menus
            $this->importNavigationMenus($wpPageIdToLocal, $wpCatIdToLocal, $runId, $counters['menus']);

            // Step 7: Build Exact Source URL Route Registry (2,071+ URLs)
            $this->importSourceUrlRoutes(
                $sourceUrlsInfo,
                $redirectsMap,
                $exactCollisionPaths,
                $wpPageIdToLocal,
                $wpPostIdToLocal,
                $wpCatIdToLocal,
                $wpTagIdToLocal,
                $snapshotId,
                $runId,
                $counters['url_routes'],
                $conflicts
            );

            if ($apply) {
                DB::commit();
                Cache::flush();
            } else {
                // Dry-run: Rollback 100% of mutations!
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $errors[] = $e->getMessage();
            throw $e;
        }

        $completedAt = Carbon::now('UTC');

        // Aggregate totals
        $totals = ['inserts' => 0, 'updates' => 0, 'skips' => 0, 'conflicts' => count($conflicts), 'errors' => count($errors)];
        foreach ($counters as $grp) {
            $totals['inserts'] += $grp['inserts'] ?? 0;
            $totals['updates'] += $grp['updates'] ?? 0;
            $totals['skips']   += $grp['skips'] ?? 0;
        }

        // Record audit run in migration_runs (even dry-run is logged safely as an audit entry!)
        MigrationRun::create([
            'run_id'               => $runId,
            'snapshot_id'          => $snapshotId,
            'snapshot_checksum'    => $actualManifestSha,
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
            'snapshot_checksum'    => $actualManifestSha,
            'environment'          => (string) config('app.env'),
            'database'             => $dbName,
            'backup_checkpoint_id' => $backupId,
            'totals'               => $totals,
            'by_group'             => $counters,
            'conflicts'            => $conflicts,
            'errors'               => $errors,
        ];
    }

    private function importSettings(string $runId, array &$c): void
    {
        $desired = [
            'name'             => ['value' => 'Hải Phòng Tech', 'type' => 'string'],
            'company'          => ['value' => 'CÔNG TY TNHH HẢI PHÒNG TECH', 'type' => 'string'],
            'tagline'          => ['value' => 'Make believe in our values', 'type' => 'string'],
            'address'          => ['value' => 'Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam', 'type' => 'textarea'],
            'hotline'          => ['value' => '0866555212', 'type' => 'string'],
            'phone'            => ['value' => '0866555212', 'type' => 'string'],
            'zalo'             => ['value' => 'https://zalo.me/0866555212', 'type' => 'url'],
            'zalo_url'         => ['value' => 'https://zalo.me/0866555212', 'type' => 'url'],
            'email'            => ['value' => 'kinhdoanhhpt@haiphongtech.vn', 'type' => 'email'],
            'contact_email_secondary' => ['value' => 'kinhdoanhhpt@haiphongtech.vn', 'type' => 'email'],
            'opening_hours'    => ['value' => '8:00AM - 6:00PM', 'type' => 'string'],
            'url'              => ['value' => 'https://haiphongtech.vn', 'type' => 'url'],
            'site_logo'        => ['value' => '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp', 'type' => 'image'],
            'site_favicon'     => ['value' => '/wp-content/uploads/2025/01/cropped-logo-nho-hien-thi-tren-gg.jpg', 'type' => 'image'],
            'og_image'         => ['value' => '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp', 'type' => 'image'],
            'facebook'         => ['value' => 'https://www.facebook.com/HaiPhongTech.vn', 'type' => 'url'],
            'facebook_url'     => ['value' => 'https://www.facebook.com/HaiPhongTech.vn', 'type' => 'url'],
            'dmca_url'         => ['value' => 'https://www.dmca.com/Protection/Status.aspx?ID=9fe8b5bd-3e44-4ddf-bc87-6c65f513adbc', 'type' => 'url'],
            'moit_gov_url'     => ['value' => 'http://online.gov.vn/nen-tang/304e1e8f-ae49-467d-a282-44cc52875a64', 'type' => 'url'],
            'meta_title'       => ['value' => 'Hải Phòng Tech – Cung Cấp PLC, Cảm Biến, HMI Chính Hãng', 'type' => 'string'],
            'meta_description' => ['value' => 'Hải Phòng Tech chuyên cung cấp thiết bị điện, tự động hóa tại Hải Phòng. Đầy đủ PLC, HMI, cảm biến, biến tần, relay chính hãng. Tư vấn kỹ thuật miễn phí, giá tốt nhất.', 'type' => 'textarea'],
            'copyright'        => ['value' => '© ' . date('Y') . ' CÔNG TY TNHH HẢI PHÒNG TECH. Bảo lưu mọi quyền.', 'type' => 'textarea'],
        ];

        foreach ($desired as $key => $spec) {
            $existing = Setting::where('key', $key)->first();
            if (!$existing) {
                Setting::create(['key' => $key, 'value' => $spec['value'], 'type' => $spec['type']]);
                $c['inserts']++;
            } elseif ($existing->value !== $spec['value'] || $existing->type !== $spec['type']) {
                $existing->update(['value' => $spec['value'], 'type' => $spec['type']]);
                $c['updates']++;
            } else {
                $c['skips']++;
            }
        }
    }

    private function importDefaultStagingBanners(string $runId, array &$c): void
    {
        $heroTitle = 'Cung Cấp PLC, Cảm Biến, HMI & Thiết Bị Tự Động Hóa Chính Hãng';
        $existing = Banner::where('position', 1)->where('title', $heroTitle)->first();
        if (!$existing) {
            Banner::create([
                'title'    => $heroTitle,
                'image'    => '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp',
                'link'     => '/gioi-thieu/',
                'position' => 1,
                'status'   => 'active',
            ]);
            $c['inserts']++;
        } else {
            $c['skips']++;
        }
    }

    private function importMediaManifest(string $mediaCsvPath, string $runId, array &$c): array
    {
        $urlToId = [];
        $existingAll = Media::where('disk', 'wp_uploads')->get()->keyBy('wp_relative_path');

        $fp = fopen($mediaCsvPath, 'r');
        $header = fgetcsv($fp);
        while (($r = fgetcsv($fp)) !== false) {
            $url = trim((string) ($r[0] ?? ''));
            $relPath = trim((string) ($r[1] ?? ''));
            $mime = trim((string) ($r[3] ?? 'application/octet-stream'));
            $wpId = !empty($r[4]) ? (int) $r[4] : null;
            $width = !empty($r[5]) ? (int) $r[5] : null;
            $height = !empty($r[6]) ? (int) $r[6] : null;
            $alt = mb_substr(trim((string) ($r[7] ?? '')), 0, 255);
            $title = mb_substr(trim((string) ($r[8] ?? '')), 0, 255);

            if ($relPath === '') {
                $pathPart = (string) parse_url($url, PHP_URL_PATH);
                if (preg_match('#/wp-content/uploads/(.+)$#i', $pathPart, $m)) {
                    $relPath = $m[1];
                } else {
                    continue;
                }
            }

            $dirPart = dirname($relPath);
            $folder = 'wp-content/uploads' . ($dirPart !== '.' && $dirPart !== '' ? '/' . str_replace('\\', '/', $dirPart) : '');
            $filename = basename($relPath);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (isset($existingAll[$relPath])) {
                $media = $existingAll[$relPath];
                $needsUpdate = false;
                $updates = [];
                if ($wpId && !$media->wp_attachment_id) {
                    $updates['wp_attachment_id'] = $wpId;
                    $needsUpdate = true;
                }
                if ($alt !== '' && $media->alt !== $alt) {
                    $updates['alt'] = $alt;
                    $needsUpdate = true;
                }
                if ($title !== '' && $media->title !== $title) {
                    $updates['title'] = $title;
                    $needsUpdate = true;
                }
                if ($needsUpdate) {
                    $updates['import_batch_id'] = $runId;
                    $media->update($updates);
                    $c['updates']++;
                } else {
                    $c['skips']++;
                }
                $urlToId[$url] = $media->id;
                $urlToId['/wp-content/uploads/' . ltrim($relPath, '/')] = $media->id;
                continue;
            }

            // Also check uniqueness on (folder, filename)
            $media = Media::where('folder', $folder)->where('filename', $filename)->first();
            if ($media) {
                $c['skips']++;
                $urlToId[$url] = $media->id;
                $urlToId['/wp-content/uploads/' . ltrim($relPath, '/')] = $media->id;
                continue;
            }

            $created = Media::create([
                'wp_attachment_id' => $wpId,
                'disk'             => 'wp_uploads',
                'folder'           => $folder,
                'filename'         => $filename,
                'wp_source_url'    => $url,
                'wp_relative_path' => $relPath,
                'original_name'    => $filename,
                'extension'        => $ext ?: null,
                'mime_type'        => $mime,
                'size'             => 0,
                'width'            => $width,
                'height'           => $height,
                'title'            => $title ?: null,
                'alt'              => $alt ?: null,
                'status'           => 'active',
                'import_batch_id'  => $runId,
            ]);
            $existingAll[$relPath] = $created;
            $urlToId[$url] = $created->id;
            $urlToId['/wp-content/uploads/' . ltrim($relPath, '/')] = $created->id;
            $c['inserts']++;
        }
        fclose($fp);

        return $urlToId;
    }

    private function importPages(
        array $wpPages,
        array $mediaUrlToId,
        array $redirectsMap,
        string $runId,
        bool $forceOverwrite,
        array &$c,
        array &$conflicts
    ): array {
        // Sort so parent_wp_id === 0 comes first
        usort($wpPages, fn($a, $b) => ((int) ($a['parent_wp_id'] ?? 0)) <=> ((int) ($b['parent_wp_id'] ?? 0)));

        $wpIdToLocal = [];
        foreach ($wpPages as $row) {
            $wpId = (int) $row['source_wp_id'];
            $slug = (string) $row['source_slug'];
            $permalink = (string) $row['source_permalink'];
            $parsedPath = (string) parse_url($permalink, PHP_URL_PATH);
            $fullPath = SourceUrlRoute::normalizePath($parsedPath);
            $fullPathHash = hash('sha256', $fullPath);

            $role = match (true) {
                $fullPath === '/'                    => 'home',
                $slug === 'tin-blog'                 => 'blog_index',
                $slug === 'shop'                     => 'shop_reserved',
                $slug === 'lien-he'                  => 'contact',
                in_array($slug, self::POLICY_SLUGS, true) => 'policy',
                in_array($slug, ['cart', 'checkout', 'my-account', 'wishlist', 'profile', 'password-reset', 'password-reset-2'], true) => 'utility',
                default                              => 'standard',
            };

            $status = isset($redirectsMap[$permalink]) ? 'redirected' : ($role === 'shop_reserved' ? 'reserved_phase2' : 'published');
            $seo = $row['seo'] ?? [];
            $rawHtml = (string) ($row['rendered_content'] ?? '');
            $normalizedHtml = $this->normalizer->normalize($rawHtml);
            // Strip pre-existing Misutech banner link on homepage raw UX block so zero Misutech links exist in Page content
            if ($role === 'home') {
                $normalizedHtml = str_ireplace('https://misutech.vn', '#', $normalizedHtml);
            }

            $featUrl = (string) ($row['featured_media_url'] ?? '');
            $featMediaId = $featUrl !== '' ? ($mediaUrlToId[$featUrl] ?? null) : null;
            $parentWpId = (int) ($row['parent_wp_id'] ?? 0);
            $parentLocalId = $parentWpId > 0 ? ($wpIdToLocal[$parentWpId] ?? null) : null;

            $payload = [
                'parent_id'                => $parentLocalId,
                'title'                    => (string) ($row['title'] ?? ''),
                'slug'                     => $slug,
                'full_path_hash'           => $fullPathHash,
                'full_path'                => $fullPath,
                'page_role'                => $role,
                'excerpt'                  => trim(strip_tags((string) ($row['excerpt_html'] ?? ''))) ?: null,
                'content'                  => $normalizedHtml,
                'raw_content'              => $rawHtml,
                'template'                 => ($row['template'] ?? '') ?: null,
                'menu_order'               => (int) ($row['menu_order'] ?? 0),
                'author_display_name'      => (string) ($row['author_display_name'] ?? 'Hải Phòng Tech'),
                'featured_media_id'        => $featMediaId,
                'meta_title'               => ($seo['title'] ?? '') ?: null,
                'meta_description'         => ($seo['description'] ?? '') ?: null,
                'meta_robots'              => ($seo['robots'] ?? '') ?: null,
                'canonical_url'            => $this->toRelativePathIfInternal((string) ($seo['canonical'] ?? '')),
                'og_title'                 => ($seo['og_title'] ?? '') ?: null,
                'og_description'           => ($seo['og_description'] ?? '') ?: null,
                'og_image'                 => $this->toRelativePathIfInternal((string) ($seo['og_image'] ?? '')),
                'og_type'                  => ($seo['og_type'] ?? '') ?: null,
                'schema_json'              => !empty($seo['schema_raw']) ? json_encode($seo['schema_raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'source_wp_id'             => $wpId,
                'source_permalink'         => $permalink,
                'content_hash'             => (string) $row['content_hash'],
                'has_placeholder_flag'     => !empty($row['placeholders_flags']),
                'has_unrendered_shortcode' => !empty($row['shortcodes_detected']),
                'in_sitemap'               => (bool) ($row['in_sitemap'] ?? false),
                'status'                   => $status,
                'published_at'             => !empty($row['published_at_local']) ? Carbon::parse($row['published_at_local'], 'Asia/Ho_Chi_Minh') : null,
                'source_modified_at'       => !empty($row['modified_at_local']) ? Carbon::parse($row['modified_at_local'], 'Asia/Ho_Chi_Minh') : null,
            ];

            $existing = Page::withTrashed()->where('source_wp_id', $wpId)->first();
            if (!$existing) {
                $payload['import_batch_id'] = $runId;
                $created = Page::create($payload);
                $wpIdToLocal[$wpId] = $created->id;
                $c['inserts']++;
            } else {
                $wpIdToLocal[$wpId] = $existing->id;
                if ($existing->content_hash === $payload['content_hash']
                    && $existing->content === $payload['content']
                    && $existing->meta_title === $payload['meta_title']
                    && $existing->meta_robots === $payload['meta_robots']
                    && $existing->status === $payload['status']
                ) {
                    $c['skips']++;
                } elseif (!$forceOverwrite && $existing->content_hash !== $payload['content_hash'] && $existing->import_batch_id === null) {
                    $c['conflicts']++;
                    $conflicts[] = [
                        'type'   => 'page_manual_edit_protected',
                        'wp_id'  => $wpId,
                        'slug'   => $slug,
                        'reason' => 'Existing page record without import_batch_id differs in content_hash',
                    ];
                } else {
                    $payload['import_batch_id'] = $runId;
                    $existing->update($payload);
                    $c['updates']++;
                }
            }
        }

        return $wpIdToLocal;
    }

    private function importPostCategories(
        array $wpCategories,
        string $runId,
        bool $forceOverwrite,
        array &$c,
        array &$conflicts
    ): array {
        usort($wpCategories, fn($a, $b) => ((int) ($a['parent_wp_id'] ?? 0)) <=> ((int) ($b['parent_wp_id'] ?? 0)));

        $wpIdToLocal = [];
        foreach ($wpCategories as $idx => $row) {
            $wpId = (int) $row['source_wp_id'];
            $slug = (string) $row['source_slug'];
            $seo = $row['seo'] ?? [];
            $parentWpId = (int) ($row['parent_wp_id'] ?? 0);
            $parentLocalId = $parentWpId > 0 ? ($wpIdToLocal[$parentWpId] ?? null) : null;

            $payload = [
                'name'             => (string) $row['name'],
                'description'      => ($row['description'] ?? '') ?: null,
                'slug'             => $slug,
                'parent_id'        => $parentLocalId,
                'type'             => 'post',
                'position'         => $idx + 1,
                'status'           => 'active',
                'meta_title'       => ($seo['title'] ?? '') ?: null,
                'meta_description' => ($seo['description'] ?? '') ?: null,
                'meta_robots'      => ($seo['robots'] ?? '') ?: null,
                'canonical_url'    => $this->toRelativePathIfInternal((string) ($seo['canonical'] ?? '')),
                'og_title'         => ($seo['og_title'] ?? '') ?: null,
                'og_description'   => ($seo['og_description'] ?? '') ?: null,
                'og_image'         => $this->toRelativePathIfInternal((string) ($seo['og_image'] ?? '')),
                'schema_json'      => !empty($seo['schema_raw']) ? json_encode($seo['schema_raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'source_wp_id'     => $wpId,
                'source_taxonomy'  => 'category',
                'source_permalink' => (string) $row['source_permalink'],
                'in_sitemap'       => (bool) ($row['in_sitemap'] ?? false),
            ];

            $existing = Category::withTrashed()
                ->where('source_taxonomy', 'category')
                ->where('source_wp_id', $wpId)
                ->first();

            if (!$existing) {
                // Check if same (type=post, slug) exists
                $slugCollision = Category::withTrashed()->where('type', 'post')->where('slug', $slug)->first();
                if ($slugCollision && !$forceOverwrite) {
                    $c['conflicts']++;
                    $conflicts[] = [
                        'type'   => 'post_category_slug_conflict',
                        'wp_id'  => $wpId,
                        'slug'   => $slug,
                        'reason' => 'Pre-existing post category shares slug without matching source_wp_id',
                    ];
                    $wpIdToLocal[$wpId] = $slugCollision->id;
                    continue;
                }
                $payload['import_batch_id'] = $runId;
                $created = Category::create($payload);
                $wpIdToLocal[$wpId] = $created->id;
                $c['inserts']++;
            } else {
                $wpIdToLocal[$wpId] = $existing->id;
                if ($existing->name === $payload['name']
                    && $existing->slug === $payload['slug']
                    && $existing->meta_title === $payload['meta_title']
                    && $existing->meta_robots === $payload['meta_robots']
                ) {
                    $c['skips']++;
                } else {
                    $payload['import_batch_id'] = $runId;
                    $existing->update($payload);
                    $c['updates']++;
                }
            }
        }

        return $wpIdToLocal;
    }

    private function importPostTags(array $wpTags, string $runId, array &$c): array
    {
        $wpIdToLocal = [];
        $existingByWpId = Tag::all()->keyBy('source_wp_id');

        foreach ($wpTags as $row) {
            $wpId = (int) $row['source_wp_id'];
            $slug = (string) $row['source_slug'];
            $name = (string) $row['name'];
            $seo = $row['seo'] ?? [];

            $metaTitle = ($seo['title'] ?? '') ?: ($name . ' Archives - Hải Phòng Tech');
            $metaRobots = ($seo['robots'] ?? '') ?: 'noindex, follow';

            if (isset($existingByWpId[$wpId])) {
                $tag = $existingByWpId[$wpId];
                $wpIdToLocal[$wpId] = $tag->id;
                if ($tag->name === $name && $tag->slug === $slug && $tag->meta_robots === $metaRobots) {
                    $c['skips']++;
                } else {
                    $tag->update([
                        'name'               => $name,
                        'slug'               => $slug,
                        'description'        => ($row['description'] ?? '') ?: null,
                        'posts_count_source' => (int) ($row['count'] ?? 0),
                        'meta_title'         => $metaTitle,
                        'meta_robots'        => $metaRobots,
                        'import_batch_id'    => $runId,
                    ]);
                    $c['updates']++;
                }
                continue;
            }

            $created = Tag::create([
                'name'               => $name,
                'slug'               => $slug,
                'description'        => ($row['description'] ?? '') ?: null,
                'posts_count_source' => (int) ($row['count'] ?? 0),
                'meta_title'         => $metaTitle,
                'meta_description'   => ($seo['description'] ?? '') ?: null,
                'meta_robots'        => $metaRobots,
                'canonical_url'      => null, // Intentional: RankMath omits canonical on noindex tag archives
                'source_wp_id'       => $wpId,
                'source_permalink'   => (string) $row['source_permalink'],
                'in_sitemap'         => false,
                'import_batch_id'    => $runId,
                'status'             => 'active',
            ]);
            $wpIdToLocal[$wpId] = $created->id;
            $c['inserts']++;
        }

        return $wpIdToLocal;
    }

    private function importPosts(
        array $wpPosts,
        array $wpCatIdToLocal,
        array $wpTagIdToLocal,
        array $mediaUrlToId,
        array $exactCollisionPaths,
        array $redirectsMap,
        string $runId,
        bool $forceOverwrite,
        array &$c,
        array &$conflicts
    ): array {
        $wpIdToLocal = [];

        foreach ($wpPosts as $row) {
            $wpId = (int) $row['source_wp_id'];
            $slug = (string) $row['source_slug'];
            $permalink = (string) $row['source_permalink'];
            $normPath = SourceUrlRoute::normalizePath((string) parse_url($permalink, PHP_URL_PATH));

            $isRedirected = isset($redirectsMap[$permalink]);
            $isShadowedByProdCat = isset($exactCollisionPaths[$normPath]);

            $status = 'published';
            $conflictStatus = null;
            if ($isRedirected) {
                $status = 'redirected';
                $conflictStatus = 'redirected_301_in_source';
            } elseif ($isShadowedByProdCat) {
                // Keep post record stored for audit, but DO NOT publish as active blog post on colliding product_cat URL!
                $status = 'shadowed';
                $conflictStatus = 'shadowed_by_product_cat';
                $conflicts[] = [
                    'type'       => 'post_shadowed_by_product_cat',
                    'wp_id'      => $wpId,
                    'slug'       => $slug,
                    'path'       => $normPath,
                    'resolution' => 'Root URL reserved for WooCommerce product_cat per live HTTP verification; post stored with status=shadowed',
                ];
            }

            $catWpIds = array_map('intval', $row['category_wp_ids'] ?? []);
            $localCatIds = [];
            foreach ($catWpIds as $cwp) {
                if (isset($wpCatIdToLocal[$cwp])) {
                    $localCatIds[] = $wpCatIdToLocal[$cwp];
                }
            }
            $primaryCatId = $localCatIds[0] ?? null;

            $tagWpIds = array_map('intval', $row['tag_wp_ids'] ?? []);
            $localTagIds = [];
            foreach ($tagWpIds as $twp) {
                if (isset($wpTagIdToLocal[$twp])) {
                    $localTagIds[] = $wpTagIdToLocal[$twp];
                }
            }

            $seo = $row['seo'] ?? [];
            $rawHtml = (string) ($row['rendered_content'] ?? '');
            $normalizedHtml = $this->normalizer->normalize($rawHtml);
            $summaryText = trim( html_entity_decode(strip_tags((string) ($row['excerpt_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8') );

            // Only mark in_sitemap=true if truly in post-sitemap.xml (exclude the 6 product_cat-sitemap hits!)
            $trulyInPostSitemap = ((bool) ($row['in_sitemap'] ?? false)) && !$isShadowedByProdCat && !$isRedirected;

            $payload = [
                'title'               => (string) ($row['title'] ?? ''),
                'slug'                => $slug,
                'summary'             => $summaryText ?: null,
                'content'             => $normalizedHtml,
                'raw_content'         => $rawHtml,
                'author_display_name' => (string) ($row['author_display_name'] ?? 'Hải Phòng Tech'),
                'category_id'         => $primaryCatId,
                'meta_title'          => ($seo['title'] ?? '') ?: null,
                'meta_description'    => ($seo['description'] ?? '') ?: null,
                'meta_robots'         => ($seo['robots'] ?? '') ?: null,
                'canonical_url'       => $this->toRelativePathIfInternal((string) ($seo['canonical'] ?? '')),
                'og_title'            => ($seo['og_title'] ?? '') ?: null,
                'og_description'      => ($seo['og_description'] ?? '') ?: null,
                'og_image'            => $this->toRelativePathIfInternal((string) ($seo['og_image'] ?? '')),
                'og_type'             => ($seo['og_type'] ?? 'article') ?: 'article',
                'schema_json'         => !empty($seo['schema_raw']) ? json_encode($seo['schema_raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'source_wp_id'        => $wpId,
                'source_permalink'    => $permalink,
                'content_hash'        => (string) $row['content_hash'],
                'in_sitemap'          => $trulyInPostSitemap,
                'conflict_status'     => $conflictStatus,
                'status'              => $status,
                'published_at'        => !empty($row['published_at_local']) ? Carbon::parse($row['published_at_local'], 'Asia/Ho_Chi_Minh') : null,
                'source_modified_at'  => !empty($row['modified_at_local']) ? Carbon::parse($row['modified_at_local'], 'Asia/Ho_Chi_Minh') : null,
            ];

            $existing = Post::withTrashed()->where('source_wp_id', $wpId)->first();
            if (!$existing) {
                $slugCollision = Post::withTrashed()->where('slug', $slug)->first();
                if ($slugCollision && !$forceOverwrite) {
                    $c['conflicts']++;
                    $conflicts[] = [
                        'type'   => 'post_slug_conflict',
                        'wp_id'  => $wpId,
                        'slug'   => $slug,
                        'reason' => 'Existing post shares slug without matching source_wp_id',
                    ];
                    $wpIdToLocal[$wpId] = $slugCollision->id;
                    continue;
                }
                $payload['import_batch_id'] = $runId;
                $post = Post::create($payload);
                $wpIdToLocal[$wpId] = $post->id;
                $c['inserts']++;
            } else {
                $post = $existing;
                $wpIdToLocal[$wpId] = $post->id;
                if ($post->content_hash === $payload['content_hash']
                    && $post->content === $payload['content']
                    && $post->meta_title === $payload['meta_title']
                    && $post->status === $payload['status']
                    && $post->category_id === $payload['category_id']
                ) {
                    $c['skips']++;
                } else {
                    $payload['import_batch_id'] = $runId;
                    $post->update($payload);
                    $c['updates']++;
                }
            }

            // Sync category_post pivot idempotently
            $catPivotData = [];
            foreach ($localCatIds as $idx => $cid) {
                $catPivotData[$cid] = ['is_primary' => $idx === 0];
            }
            $post->categories()->sync($catPivotData);

            // Sync post_tag pivot idempotently
            $post->tags()->sync($localTagIds);

            // Sync featured thumbnail media idempotently
            $featUrl = (string) ($row['featured_media_url'] ?? '');
            $featMediaId = $featUrl !== '' ? ($mediaUrlToId[$featUrl] ?? null) : null;
            if ($featMediaId) {
                $currentThumbIds = $post->thumbnailMedia()->pluck('media.id')->toArray();
                if ($currentThumbIds !== [$featMediaId]) {
                    $post->thumbnailMedia()->detach();
                    $post->media()->attach($featMediaId, ['role' => 'thumbnail', 'position' => 0]);
                }
            }
        }

        return $wpIdToLocal;
    }

    private function importNavigationMenus(
        array $wpPageIdToLocal,
        array $wpCatIdToLocal,
        string $runId,
        array &$c
    ): void {
        $menusSpec = [
            'header_main' => [
                'name'     => 'Menu Chính Hải Phòng Tech',
                'location' => 'header_primary',
                'items'    => [
                    ['title' => 'Trang chủ', 'url' => '/', 'entity_type' => 'page_home'],
                    ['title' => 'Giới thiệu', 'url' => '/gioi-thieu/', 'entity_type' => 'page'],
                    ['title' => 'Tự động hóa', 'url' => '/tu-dong-hoa/', 'entity_type' => 'post_category'],
                    ['title' => 'Tin Blog', 'url' => '/tin-blog/', 'entity_type' => 'blog_index'],
                    ['title' => 'Tài liệu kĩ thuật', 'url' => '/tai-lieu-ki-thuat/', 'entity_type' => 'page'],
                    ['title' => 'Thiết bị điện', 'url' => '/thiet-bi-dien/', 'entity_type' => 'page'],
                    ['title' => 'Hướng dẫn', 'url' => '/huong-dan/', 'entity_type' => 'page'],
                    ['title' => 'Liên hệ', 'url' => '/lien-he/', 'entity_type' => 'page'],
                ],
            ],
            'footer_policies' => [
                'name'     => 'Chính Sách Và Điều Khoản',
                'location' => 'footer_col_policies',
                'items'    => [
                    ['title' => 'Chính Sách Bảo Mật Dữ Liệu', 'url' => '/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/', 'entity_type' => 'page'],
                    ['title' => 'Chính Sách Bảo Mật Thông Tin Cá Nhân', 'url' => '/chinh-sach-bao-mat-thong-tin-ca-nhan-information-privacy/', 'entity_type' => 'page'],
                    ['title' => 'Chính Sách Thanh Toán An Toàn', 'url' => '/chinh-sach-thanh-toan-an-toan-da-dang-cod-chuyen-khoan/', 'entity_type' => 'page'],
                    ['title' => 'Chính Sách Bảo Hành', 'url' => '/chinh-sach-bao-hanh-thiet-bi-cong-nghiep-chinh-hang-warranty-policy/', 'entity_type' => 'page'],
                    ['title' => 'Chính Sách Vận Chuyển', 'url' => '/chinh-sach-van-chuyen-nhanh-an-toan-giao-toan-quoc/', 'entity_type' => 'page'],
                    ['title' => 'Chính Sách Đổi Trả Sản Phẩm', 'url' => '/chinh-sach-doi-tra-san-pham-return-refund-policy/', 'entity_type' => 'page'],
                    ['title' => 'Điều Khoản Sử Dụng Website', 'url' => '/dieu-khoan-su-dung-website-quy-dinh-trach-nhiem/', 'entity_type' => 'page'],
                    ['title' => 'Chính Sách Bảo Mật Thông Tin Khách Hàng', 'url' => '/chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy/', 'entity_type' => 'page'],
                ],
            ],
            'footer_company' => [
                'name'     => 'Thông Tin & Chuyên Mục',
                'location' => 'footer_col_company',
                'items'    => [
                    ['title' => 'Giới thiệu Hải Phòng Tech', 'url' => '/gioi-thieu/', 'entity_type' => 'page'],
                    ['title' => 'Tin Blog Tự Động Hóa', 'url' => '/tin-blog/', 'entity_type' => 'blog_index'],
                    ['title' => 'Chuyên mục Tự động hóa', 'url' => '/tu-dong-hoa/', 'entity_type' => 'post_category'],
                    ['title' => 'Tài liệu kỹ thuật (Bài viết)', 'url' => '/tai-lieu-ky-thuat/', 'entity_type' => 'post_category'],
                    ['title' => 'Bảng giá sản phẩm', 'url' => '/bang-gia-san-pham/', 'entity_type' => 'post_category'],
                    ['title' => 'Hướng dẫn mua hàng', 'url' => '/huong-dan/', 'entity_type' => 'page'],
                    ['title' => 'Liên hệ với chúng tôi', 'url' => '/lien-he/', 'entity_type' => 'page'],
                    ['title' => 'Sơ đồ trang web (Sitemap)', 'url' => '/sitemap_index.xml', 'entity_type' => 'sitemap'],
                ],
            ],
        ];

        foreach ($menusSpec as $code => $spec) {
            $menu = NavigationMenu::where('code', $code)->first();
            if (!$menu) {
                $menu = NavigationMenu::create([
                    'code'            => $code,
                    'name'            => $spec['name'],
                    'location'        => $spec['location'],
                    'status'          => 'active',
                    'import_batch_id' => $runId,
                ]);
                $c['inserts']++;
            } else {
                $c['skips']++;
            }

            foreach ($spec['items'] as $idx => $itemSpec) {
                $existingItem = NavigationMenuItem::where('menu_id', $menu->id)
                    ->where('url', $itemSpec['url'])
                    ->first();

                if (!$existingItem) {
                    NavigationMenuItem::create([
                        'menu_id'     => $menu->id,
                        'title'       => $itemSpec['title'],
                        'url'         => $itemSpec['url'],
                        'position'    => $idx + 1,
                        'entity_type' => $itemSpec['entity_type'],
                        'is_active'   => true,
                    ]);
                    $c['inserts']++;
                } elseif ($existingItem->title !== $itemSpec['title'] || $existingItem->position !== ($idx + 1)) {
                    $existingItem->update([
                        'title'    => $itemSpec['title'],
                        'position' => $idx + 1,
                    ]);
                    $c['updates']++;
                } else {
                    $c['skips']++;
                }
            }
        }
    }

    private function importSourceUrlRoutes(
        array $sourceUrlsInfo,
        array $redirectsMap,
        array $exactCollisionPaths,
        array $wpPageIdToLocal,
        array $wpPostIdToLocal,
        array $wpCatIdToLocal,
        array $wpTagIdToLocal,
        string $snapshotId,
        string $runId,
        array &$c,
        array &$conflicts
    ): void {
        $existingRoutes = SourceUrlRoute::all()->keyBy('path_hash');

        $boundarySlugs = [];
        foreach ($sourceUrlsInfo as $u => $inf) {
            if (($inf['type'] ?? '') === 'product_single_boundary') {
                $p = trim((string) parse_url($u, PHP_URL_PATH), '/');
                if (str_starts_with($p, 'product/')) {
                    $boundarySlugs[] = substr($p, strlen('product/'));
                }
            }
        }
        $activeBoundaryProductSlugs = !empty($boundarySlugs)
            ? Product::whereIn('slug', $boundarySlugs)->where('status', 'active')->pluck('id', 'slug')->all()
            : [];

        foreach ($sourceUrlsInfo as $url => $info) {
            $srcType = $info['type'];
            if ($srcType === 'trailing_slash_probe' || $srcType === 'blog_pagination' || $srcType === 'product_shop_pagination_boundary') {
                continue; // Handled dynamically by trailing-slash middleware & /{slug}/page/{page}/ route
            }

            $rawPath = (string) parse_url($url, PHP_URL_PATH);
            $normPath = SourceUrlRoute::normalizePath($rawPath);
            $unslashed = SourceUrlRoute::unslashedPath($rawPath);
            $pathHash = hash('sha256', $normPath);
            $unslashedHash = hash('sha256', $unslashed);

            $wpId = (string) ($info['source_id'] ?? '');
            $wpIdInt = is_numeric($wpId) ? (int) $wpId : 0;

            $entityType = 'unknown';
            $entityId = null;
            $httpStatus = 200;
            $redirectTarget = null;
            $isCutoverReady = true;
            $conflictNote = null;

            // Precedence 1: Explicit source redirects (301 / 302)
            if (isset($redirectsMap[$url])) {
                $entityType = 'redirect';
                $httpStatus = (int) $redirectsMap[$url]['status_code'];
                $redirectTarget = $this->toRelativePathIfInternal($redirectsMap[$url]['target_url']);
                if ($redirectTarget === '') {
                    $redirectTarget = '/';
                }
            }
            // Precedence 2: Exact collision paths where WooCommerce product_cat beat WP post on live!
            elseif (isset($exactCollisionPaths[$normPath])) {
                $entityType = 'reserved_product_cat';
                $httpStatus = 200;
                $isCutoverReady = false;
                $conflictNote = $exactCollisionPaths[$normPath];
            }
            // Precedence 3: Product / Shop / Brand boundaries (Reserved Phase 2 -> blocks cutover!)
            elseif (in_array($srcType, ['reserved_product_cat', 'product_cat_boundary'], true)) {
                $entityType = 'reserved_product_cat';
                $isCutoverReady = false;
            } elseif ($srcType === 'reserved_product_brand') {
                $entityType = 'reserved_product_brand';
                $isCutoverReady = false;
            } elseif ($srcType === 'product_single_boundary') {
                $prodSlug = trim(substr($normPath, strlen('/product/')), '/');
                if ($prodSlug !== '' && isset($activeBoundaryProductSlugs[$prodSlug])) {
                    $c['skips']++;
                    continue;
                }
                $entityType = 'reserved_product';
                $isCutoverReady = false;
            } elseif ($srcType === 'product_shop_boundary' || ($srcType === 'page' && $normPath === '/shop/')) {
                $entityType = 'reserved_shop';
                $entityId = $wpIdInt ? ($wpPageIdToLocal[$wpIdInt] ?? null) : null;
                $isCutoverReady = false;
            }
            // Precedence 4: Homepage & Blog Index & Standard Pages
            elseif ($srcType === 'page_home' || $normPath === '/') {
                $entityType = 'page_home';
                $entityId = $wpIdInt ? ($wpPageIdToLocal[$wpIdInt] ?? null) : null;
            } elseif ($normPath === '/tin-blog/') {
                $entityType = 'blog_index';
                $entityId = $wpIdInt ? ($wpPageIdToLocal[$wpIdInt] ?? null) : Page::where('slug', 'tin-blog')->value('id');
            } elseif ($srcType === 'page') {
                $entityType = 'page';
                $entityId = $wpIdInt ? ($wpPageIdToLocal[$wpIdInt] ?? null) : null;
            }
            // Precedence 5: Post Categories
            elseif ($srcType === 'post_category') {
                $entityType = 'post_category';
                $entityId = $wpIdInt ? ($wpCatIdToLocal[$wpIdInt] ?? null) : null;
            }
            // Precedence 6: Posts
            elseif ($srcType === 'post') {
                $entityType = 'post';
                $entityId = $wpIdInt ? ($wpPostIdToLocal[$wpIdInt] ?? null) : null;
            }
            // Precedence 7: Post Tags
            elseif (in_array($srcType, ['post_tag', 'post_tag_sampled'], true)) {
                $entityType = 'post_tag';
                $entityId = $wpIdInt ? ($wpTagIdToLocal[$wpIdInt] ?? null) : null;
            }

            $canonicalRel = $this->toRelativePathIfInternal((string) ($info['canonical'] ?? ''));

            $payload = [
                'path_hash'             => $pathHash,
                'unslashed_hash'        => $unslashedHash,
                'normalized_path'       => $normPath,
                'unslashed_path'        => $unslashed,
                'entity_type'           => $entityType,
                'entity_id'             => $entityId,
                'source_wp_id'          => $wpId ?: null,
                'http_status'           => $httpStatus,
                'redirect_target'       => $redirectTarget,
                'trailing_slash_policy' => 'require_slash',
                'canonical_url'         => $canonicalRel,
                'in_sitemap'            => ($info['in_sitemap'] ?? 'no') ?: 'no',
                'is_cutover_ready'      => $isCutoverReady,
                'conflict_note'         => $conflictNote,
                'source_snapshot_id'    => $snapshotId,
            ];

            if (!isset($existingRoutes[$pathHash])) {
                $payload['import_batch_id'] = $runId;
                $created = SourceUrlRoute::create($payload);
                $existingRoutes[$pathHash] = $created;
                $c['inserts']++;
            } else {
                $ex = $existingRoutes[$pathHash];
                // Guard: Do not downgrade routes claimed and activated by Phase 2 back to reserved placeholders
                if (in_array($ex->entity_type, ['product_category', 'product_brand', 'product_tag', 'product', 'shop_index', 'brand', 'shop'], true)
                    && in_array($payload['entity_type'], ['reserved_product_cat', 'reserved_product', 'reserved_product_brand', 'reserved_product_tag', 'reserved_shop', 'unknown'], true)
                ) {
                    $c['skips']++;
                    continue;
                }

                if ($ex->entity_type === $payload['entity_type']
                    && (int) $ex->entity_id === (int) $payload['entity_id']
                    && (int) $ex->http_status === (int) $payload['http_status']
                    && (string) $ex->redirect_target === (string) $payload['redirect_target']
                ) {
                    $c['skips']++;
                } else {
                    $payload['import_batch_id'] = $runId;
                    $ex->update($payload);
                    $c['updates']++;
                }
            }
        }
    }

    private function loadExactCollisionPaths(string $csvPath): array
    {
        $map = [];
        $fp = fopen($csvPath, 'r');
        $h = fgetcsv($fp);
        while (($r = fgetcsv($fp)) !== false) {
            if (($r[0] ?? '') === 'EXACT_URL_PATH') {
                $norm = SourceUrlRoute::normalizePath((string) $r[1]);
                $map[$norm] = (string) ($r[2] ?? '');
            }
        }
        fclose($fp);
        return $map;
    }

    private function loadRedirectsMap(string $csvPath): array
    {
        $map = [];
        $fp = fopen($csvPath, 'r');
        $h = fgetcsv($fp);
        while (($r = fgetcsv($fp)) !== false) {
            $src = trim((string) ($r[0] ?? ''));
            $reason = (string) ($r[4] ?? '');
            if ($src !== '' && !str_contains($reason, 'trailing-slash')) {
                $map[$src] = [
                    'target_url'  => trim((string) ($r[1] ?? '')),
                    'status_code' => (int) ($r[2] ?? 301),
                ];
            }
        }
        fclose($fp);
        return $map;
    }

    private function loadSourceUrlsMap(string $csvPath): array
    {
        $map = [];
        $fp = fopen($csvPath, 'r');
        $h = fgetcsv($fp);
        while (($r = fgetcsv($fp)) !== false) {
            $u = trim((string) ($r[0] ?? ''));
            if ($u === '') continue;
            // Prefer specific WP REST page/post rows over generic home row if duplicate
            if (isset($map[$u]) && $map[$u]['type'] !== 'home') {
                continue;
            }
            $map[$u] = [
                'type'            => $r[1] ?? '',
                'source_id'       => $r[2] ?? '',
                'slug'            => $r[3] ?? '',
                'original_status' => $r[6] ?? '',
                'final_status'    => $r[7] ?? '',
                'final_url'       => $r[8] ?? '',
                'canonical'       => $r[10] ?? '',
                'in_sitemap'      => $r[15] ?? 'no',
            ];
        }
        fclose($fp);
        return $map;
    }

    private function toRelativePathIfInternal(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }
        $host = strtolower((string) parse_url($trimmed, PHP_URL_HOST));
        if (in_array($host, ['haiphongtech.vn', 'www.haiphongtech.vn'], true)) {
            $path = (string) parse_url($trimmed, PHP_URL_PATH);
            $query = (string) parse_url($trimmed, PHP_URL_QUERY);
            $rel = $path !== '' ? $path : '/';
            return $query !== '' ? "{$rel}?{$query}" : $rel;
        }
        return $trimmed;
    }
}
