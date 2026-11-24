<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

class PostsImportCommand extends Command
{
    protected $signature = 'posts:import
        {file=D:\File\File dùng 1 lần\xuat-bai-viet-20261124-020439.xlsx : Đường dẫn file Excel (.xlsx) hoặc CSV bài viết}
        {--limit=0 : Giới hạn số bài viết xử lý (0 = toàn bộ, dùng để test số lượng ít)}
        {--offset=0 : Bỏ qua N bài viết đầu tiên}
        {--skip-existing : Bỏ qua bài viết nếu đã tồn tại theo slug hoặc ID WordPress}
        {--download-images : Tải ảnh đại diện về server lưu trữ local (mặc định lưu URL link ngoài để import siêu tốc)}
        {--dry-run : Chạy thử nghiệm kiểm tra dữ liệu, không ghi vào database}';

    protected $description = 'Import bài viết từ file Excel (.xlsx) hoặc CSV vào bảng posts, tự động map chuyên mục, thẻ tag, Rank Math SEO và ảnh đại diện.';

    /** @var array<string, int> */
    protected array $categoriesByLowerName = [];

    /** @var array<string, int> */
    protected array $categoriesBySlug = [];

    /** @var array<string, int> */
    protected array $tagsByLowerName = [];

    /** @var array<string, int> */
    protected array $tagsBySlug = [];

    public function handle(): int
    {
        $filePath = (string) $this->argument('file');
        $limit = max(0, (int) $this->option('limit'));
        $offset = max(0, (int) $this->option('offset'));
        $skipExisting = (bool) $this->option('skip-existing');
        $downloadImages = (bool) $this->option('download-images');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($filePath)) {
            $this->error("Không tìm thấy file: {$filePath}");

            return self::FAILURE;
        }

        $this->info("Đang đọc dữ liệu bài viết từ: {$filePath}" . ($dryRun ? ' [CHẾ ĐỘ DRY-RUN]' : ''));

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rows = [];

        if ($ext === 'xlsx') {
            $rows = $this->parseXlsx($filePath);
        } elseif ($ext === 'csv') {
            $rows = $this->parseCsv($filePath);
        } else {
            $this->error("Định dạng file .{$ext} không được hỗ trợ. Vui lòng sử dụng file .xlsx hoặc .csv");

            return self::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('Không tìm thấy dòng bài viết nào trong file.');

            return self::FAILURE;
        }

        $totalInFile = count($rows);
        $this->info("Đã tìm thấy {$totalInFile} bài viết trong file.");

        // Bỏ qua offset nếu có
        if ($offset > 0) {
            $rows = array_slice($rows, $offset);
            $this->info("Đã bỏ qua {$offset} dòng đầu, còn lại " . count($rows) . " dòng cần xử lý.");
        }

        // Cắt theo limit nếu có
        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $this->warn("Áp dụng --limit={$limit}: Chỉ xử lý {$limit} bài viết để kiểm thử.");
        }

        $this->preloadTaxonomies();

        $batchId = 'posts_import_' . Carbon::now('Asia/Ho_Chi_Minh')->format('Ymd_His');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $tableSample = [];

        foreach ($rows as $index => $item) {
            $wpId = ! empty($item['wp_id']) ? (int) $item['wp_id'] : null;
            $title = trim($item['title'] ?? '');
            $rawSlug = trim($item['slug'] ?? '');
            if (str_starts_with($rawSlug, 'http://') || str_starts_with($rawSlug, 'https://')) {
                $rawSlug = trim((string) parse_url($rawSlug, PHP_URL_PATH), '/');
            }
            $slug = Str::slug($rawSlug);

            if ($slug === '' && $title !== '') {
                $slug = Str::slug($title);
            }

            if ($title === '' || $slug === '') {
                $skipped++;
                continue;
            }

            // Kiểm tra bài viết đã tồn tại
            $existing = Post::where('slug', $slug)
                ->orWhere(function ($q) use ($wpId) {
                    if ($wpId !== null) {
                        $q->where('source_wp_id', $wpId);
                    }
                })
                ->first();

            if ($existing && $skipExisting) {
                $skipped++;
                continue;
            }

            $content = $item['content'] ?? '';
            $summary = trim($item['summary'] ?? '');
            if ($summary === '' && $content !== '') {
                $summary = Str::limit(strip_tags($content), 180);
            }

            // Xử lý SEO Rank Math placeholders
            $metaTitle = trim($item['seo_title'] ?? '');
            if ($metaTitle !== '') {
                $metaTitle = str_replace(
                    ['%currentyear%', '%sep%', '%sitename%'],
                    ['2026', '-', 'Hải Phòng Tech'],
                    $metaTitle
                );
            } else {
                $metaTitle = "{$title} - Hải Phòng Tech";
            }

            $metaDesc = trim($item['seo_description'] ?? '');
            if ($metaDesc === '') {
                $metaDesc = $summary;
            }

            $statusRaw = strtolower(trim($item['status'] ?? 'publish'));
            $status = in_array($statusRaw, ['publish', 'published', 'active'], true) ? 'published' : 'draft';

            $publishedAt = null;
            if (! empty($item['published_at'])) {
                try {
                    $publishedAt = Carbon::parse($item['published_at'], 'Asia/Ho_Chi_Minh');
                } catch (\Throwable) {
                    $publishedAt = Carbon::now('Asia/Ho_Chi_Minh');
                }
            } else {
                $publishedAt = Carbon::now('Asia/Ho_Chi_Minh');
            }

            $canonicalUrl = null;
            if (! empty($item['permalink'])) {
                $parsedPath = parse_url($item['permalink'], PHP_URL_PATH);
                if ($parsedPath) {
                    $canonicalUrl = '/' . trim($parsedPath, '/') . '/';
                }
            }
            if (! $canonicalUrl) {
                $canonicalUrl = '/' . trim($slug, '/') . '/';
            }

            // Xử lý Chuyên mục
            [$primaryCatId, $catIds] = $this->resolveCategories($item['categories'] ?? '', $dryRun);

            $attributes = [
                'title' => $title,
                'summary' => $summary !== '' ? $summary : null,
                'content' => $content,
                'raw_content' => $content,
                'author_display_name' => 'Hải Phòng Tech',
                'category_id' => $primaryCatId,
                'meta_title' => mb_substr($metaTitle, 0, 255, 'UTF-8'),
                'meta_description' => mb_substr($metaDesc, 0, 500, 'UTF-8'),
                'meta_robots' => 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large',
                'canonical_url' => $canonicalUrl,
                'source_permalink' => $item['permalink'] ?? null,
                'status' => $status,
                'published_at' => $publishedAt,
                'in_sitemap' => true,
                'import_batch_id' => $batchId,
            ];

            if ($wpId !== null) {
                $attributes['source_wp_id'] = $wpId;
            }

            $action = 'dry-run';
            if (! $dryRun) {
                /** @var Post $post */
                if ($existing) {
                    $existing->update($attributes);
                    $post = $existing;
                    $updated++;
                    $action = 'Cập nhật';
                } else {
                    $attributes['slug'] = $slug;
                    $post = Post::create($attributes);
                    $created++;
                    $action = 'Tạo mới';
                }

                // Gắn chuyên mục pivot category_post
                if (! empty($catIds)) {
                    DB::table('category_post')->where('post_id', $post->id)->delete();
                    $pivots = [];
                    foreach ($catIds as $cid) {
                        $pivots[] = [
                            'post_id' => $post->id,
                            'category_id' => $cid,
                            'is_primary' => $cid === $primaryCatId ? 1 : 0,
                            'created_at' => $publishedAt,
                            'updated_at' => $publishedAt,
                        ];
                    }
                    DB::table('category_post')->insert($pivots);
                }

                // Gắn thẻ tags
                if (! empty($item['tags'])) {
                    $tagIds = $this->resolveTags($item['tags']);
                    $post->tags()->sync($tagIds);
                }

                // Gắn ảnh đại diện thumbnail
                if (! empty($item['image_url'])) {
                    $this->assignThumbnail($post, $item['image_url'], $downloadImages);
                }
            } else {
                $action = $existing ? 'Sẽ cập nhật' : 'Sẽ tạo mới';
                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }
            }

            $tableSample[] = [
                $index + 1,
                $wpId ?: '-',
                Str::limit($title, 40),
                $slug,
                $primaryCatId ? "Cat #{$primaryCatId}" : 'none',
                $status,
                $action,
            ];
        }

        $this->table(['STT', 'WP ID', 'Tiêu đề', 'Slug', 'Chuyên mục', 'Trạng thái', 'Hành động'], $tableSample);

        if (! $dryRun) {
            $this->clearPostCaches();
        }

        $this->newLine();
        $this->info("=== HOÀN TẤT IMPORT BÀI VIẾT ===");
        $this->line(" - Tạo mới: {$created}");
        $this->line(" - Cập nhật: {$updated}");
        if ($skipped > 0) {
            $this->line(" - Bỏ qua: {$skipped}");
        }
        $this->line(" - Tổng số bài viết hiện có trong DB: " . Post::count());

        return self::SUCCESS;
    }

    /**
     * Nạp chuyên mục và thẻ tag vào RAM
     */
    protected function preloadTaxonomies(): void
    {
        $categories = Category::where('type', 'post')->select(['id', 'name', 'slug'])->get();
        foreach ($categories as $c) {
            $this->categoriesByLowerName[mb_strtolower(trim($c->name), 'UTF-8')] = (int) $c->id;
            $this->categoriesBySlug[strtolower((string) $c->slug)] = (int) $c->id;
        }

        $tags = Tag::select(['id', 'name', 'slug'])->get();
        foreach ($tags as $t) {
            $this->tagsByLowerName[mb_strtolower(trim($t->name), 'UTF-8')] = (int) $t->id;
            $this->tagsBySlug[strtolower((string) $t->slug)] = (int) $t->id;
        }
    }

    /**
     * Parse chuyên mục bài viết
     *
     * @return array{0: int|null, 1: array<int>}
     */
    protected function resolveCategories(string $rawCategories, bool $dryRun): array
    {
        if (trim($rawCategories) === '') {
            return [null, []];
        }

        $names = array_filter(array_map('trim', explode(',', $rawCategories)));
        $catIds = [];

        foreach ($names as $name) {
            $lower = mb_strtolower($name, 'UTF-8');
            $slug = Str::slug($name);

            if (isset($this->categoriesByLowerName[$lower])) {
                $catIds[] = $this->categoriesByLowerName[$lower];
            } elseif (isset($this->categoriesBySlug[$slug])) {
                $catIds[] = $this->categoriesBySlug[$slug];
            } elseif (! $dryRun) {
                // Tạo mới nếu chưa có
                $newCat = Category::create([
                    'name' => $name,
                    'slug' => $slug,
                    'type' => 'post',
                    'status' => 'active',
                ]);
                $this->categoriesByLowerName[$lower] = (int) $newCat->id;
                $this->categoriesBySlug[$slug] = (int) $newCat->id;
                $catIds[] = (int) $newCat->id;
            }
        }

        $catIds = array_values(array_unique($catIds));
        $primaryId = $catIds[0] ?? null;

        return [$primaryId, $catIds];
    }

    /**
     * Parse thẻ bài viết
     *
     * @return array<int>
     */
    protected function resolveTags(string $rawTags): array
    {
        if (trim($rawTags) === '') {
            return [];
        }

        $names = array_filter(array_map('trim', explode(',', $rawTags)));
        $tagIds = [];

        foreach ($names as $name) {
            $lower = mb_strtolower($name, 'UTF-8');
            $slug = Str::slug($name);

            if (isset($this->tagsByLowerName[$lower])) {
                $tagIds[] = $this->tagsByLowerName[$lower];
            } elseif (isset($this->tagsBySlug[$slug])) {
                $tagIds[] = $this->tagsBySlug[$slug];
            } else {
                $newTag = Tag::create([
                    'name' => $name,
                    'slug' => $slug,
                    'status' => 'active',
                ]);
                $this->tagsByLowerName[$lower] = (int) $newTag->id;
                $this->tagsBySlug[$slug] = (int) $newTag->id;
                $tagIds[] = (int) $newTag->id;
            }
        }

        return array_values(array_unique($tagIds));
    }

    /**
     * Gắn ảnh đại diện cho bài viết
     */
    protected function assignThumbnail(Post $post, string $imageUrl, bool $download): void
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '') {
            return;
        }

        $cleanUrl = explode('?', $imageUrl)[0];
        $filename = basename($cleanUrl);
        $ext = strtolower(pathinfo($cleanUrl, PATHINFO_EXTENSION)) ?: 'jpg';

        $mediaId = null;

        if ($download && preg_match('/^https?:\/\//i', $imageUrl)) {
            try {
                $resp = Http::timeout(10)->get($imageUrl);
                if ($resp->successful()) {
                    $body = $resp->body();
                    $dir = storage_path('app/public/clients/imgs/posts');
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    $safeName = $post->slug . '-' . substr(md5($imageUrl), 0, 8) . '.' . $ext;
                    file_put_contents($dir . '/' . $safeName, $body);

                    $media = Media::create([
                        'disk' => 'public',
                        'folder' => 'clients/imgs/posts',
                        'filename' => $safeName,
                        'original_name' => $filename,
                        'extension' => $ext,
                        'mime_type' => $resp->header('Content-Type') ?? 'image/' . $ext,
                        'size' => strlen($body),
                        'title' => $post->title,
                        'alt' => $post->title,
                        'status' => 'active',
                    ]);

                    $mediaId = $media->id;
                }
            } catch (\Throwable) {
                // Fallback sang link ngoài nếu tải lỗi
            }
        }

        // Nếu không tải hoặc tải lỗi: Lưu URL trực tiếp vào Media (disk = 'external')
        if (! $mediaId) {
            $media = Media::firstOrCreate(
                ['filename' => $imageUrl, 'disk' => 'external'],
                [
                    'folder' => '',
                    'original_name' => $filename,
                    'extension' => $ext,
                    'mime_type' => 'image/' . $ext,
                    'size' => 0,
                    'title' => $post->title,
                    'alt' => $post->title,
                    'status' => 'active',
                ]
            );
            $mediaId = $media->id;
        }

        if ($mediaId) {
            DB::table('post_media')->where('post_id', $post->id)->where('role', 'thumbnail')->delete();
            DB::table('post_media')->insert([
                'post_id' => $post->id,
                'media_id' => $mediaId,
                'role' => 'thumbnail',
                'position' => 0,
                'created_at' => Carbon::now('Asia/Ho_Chi_Minh'),
                'updated_at' => Carbon::now('Asia/Ho_Chi_Minh'),
            ]);
        }
    }

    /**
     * Parse file XLSX bài viết
     *
     * @return array<int, array<string, string>>
     */
    protected function parseXlsx(string $filePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $this->error("Không thể giải nén file XLSX: {$filePath}");

            return [];
        }

        $sharedStrings = [];
        $ssContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssContent !== false) {
            $xmlSS = @simplexml_load_string($ssContent);
            if ($xmlSS && isset($xmlSS->si)) {
                foreach ($xmlSS->si as $val) {
                    if (isset($val->t)) {
                        $sharedStrings[] = (string) $val->t;
                    } elseif (isset($val->r)) {
                        $t = '';
                        foreach ($val->r as $r) {
                            $t .= (string) $r->t;
                        }
                        $sharedStrings[] = $t;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetContent === false) {
            $this->error("Không tìm thấy trang tính (sheet1.xml) trong file XLSX.");

            return [];
        }

        $xml = @simplexml_load_string($sheetContent);
        if (! $xml || ! isset($xml->sheetData->row)) {
            $this->error("Cấu trúc sheet1.xml không hợp lệ.");

            return [];
        }

        $colMap = [];
        $rows = [];

        foreach ($xml->sheetData->row as $r) {
            $cellValues = [];
            foreach ($r->c as $c) {
                $coord = (string) $c['r'];
                $colLetter = preg_replace('/[0-9]/', '', $coord);
                $val = '';
                $type = (string) $c['t'];

                if ($type === 'inlineStr' && isset($c->is->t)) {
                    $val = (string) $c->is->t;
                } elseif ($type === 's' && isset($c->v) && isset($sharedStrings[(int) $c->v])) {
                    $val = $sharedStrings[(int) $c->v];
                } elseif (isset($c->v)) {
                    $val = (string) $c->v;
                }

                $cellValues[$colLetter] = trim($val);
            }

            // Header map
            if (empty($colMap)) {
                foreach ($cellValues as $letter => $val) {
                    $norm = mb_strtolower(trim($val), 'UTF-8');
                    match (true) {
                        str_contains($norm, 'công khai') || str_contains($norm, 'permalink') || $norm === 'url' => $colMap['permalink'] = $letter,
                        str_contains($norm, 'seo description') => $colMap['seo_description'] = $letter,
                        str_contains($norm, 'seo title') => $colMap['seo_title'] = $letter,
                        str_contains($norm, 'id') => $colMap['wp_id'] = $letter,
                        str_contains($norm, 'tiêu đề') || str_contains($norm, 'tieu de') || str_contains($norm, 'title') => $colMap['title'] = $letter,
                        str_contains($norm, 'slug') || $norm === 'đường dẫn' || $norm === 'duong dan' => $colMap['slug'] = $letter,
                        str_contains($norm, 'nội dung') || str_contains($norm, 'noi dung') || str_contains($norm, 'content') => $colMap['content'] = $letter,
                        str_contains($norm, 'tóm tắt') || str_contains($norm, 'tom tat') || str_contains($norm, 'mô tả ngắn') || str_contains($norm, 'summary') => $colMap['summary'] = $letter,
                        str_contains($norm, 'chuyên mục') || str_contains($norm, 'chuyen muc') || str_contains($norm, 'danh mục') || str_contains($norm, 'category') => $colMap['categories'] = $letter,
                        str_contains($norm, 'thẻ') || str_contains($norm, 'the') || str_contains($norm, 'tag') => $colMap['tags'] = $letter,
                        str_contains($norm, 'ảnh đại diện') || str_contains($norm, 'anh dai dien') || str_contains($norm, 'thumbnail') || str_contains($norm, 'image') => $colMap['image_url'] = $letter,
                        str_contains($norm, 'trạng thái') || str_contains($norm, 'trang thai') || str_contains($norm, 'status') => $colMap['status'] = $letter,
                        str_contains($norm, 'ngày đăng') || str_contains($norm, 'ngay dang') || str_contains($norm, 'date') => $colMap['published_at'] = $letter,
                        default => null,
                    };
                }

                $colMap['wp_id'] = $colMap['wp_id'] ?? 'A';
                $colMap['title'] = $colMap['title'] ?? 'B';
                $colMap['slug'] = $colMap['slug'] ?? 'C';
                $colMap['content'] = $colMap['content'] ?? 'D';
                $colMap['summary'] = $colMap['summary'] ?? 'E';
                $colMap['categories'] = $colMap['categories'] ?? 'F';
                $colMap['tags'] = $colMap['tags'] ?? 'G';
                $colMap['image_url'] = $colMap['image_url'] ?? 'H';
                $colMap['status'] = $colMap['status'] ?? 'I';
                $colMap['published_at'] = $colMap['published_at'] ?? 'J';
                $colMap['seo_title'] = $colMap['seo_title'] ?? 'K';
                $colMap['seo_description'] = $colMap['seo_description'] ?? 'L';
                $colMap['permalink'] = $colMap['permalink'] ?? 'N';
                continue;
            }

            $title = $cellValues[$colMap['title']] ?? '';
            if ($title === '') {
                continue;
            }

            $rows[] = [
                'wp_id' => $cellValues[$colMap['wp_id']] ?? '',
                'title' => $title,
                'slug' => $cellValues[$colMap['slug']] ?? '',
                'content' => $cellValues[$colMap['content']] ?? '',
                'summary' => $cellValues[$colMap['summary']] ?? '',
                'categories' => $cellValues[$colMap['categories']] ?? '',
                'tags' => $cellValues[$colMap['tags']] ?? '',
                'image_url' => $cellValues[$colMap['image_url']] ?? '',
                'status' => $cellValues[$colMap['status']] ?? '',
                'published_at' => $cellValues[$colMap['published_at']] ?? '',
                'seo_title' => $cellValues[$colMap['seo_title']] ?? '',
                'seo_description' => $cellValues[$colMap['seo_description']] ?? '',
                'permalink' => $cellValues[$colMap['permalink']] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Parse file CSV bài viết
     *
     * @return array<int, array<string, string>>
     */
    protected function parseCsv(string $filePath): array
    {
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            return [];
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $header = fgetcsv($fh);
        if (! is_array($header)) {
            fclose($fh);

            return [];
        }

        $colMap = [
            'wp_id' => 0,
            'title' => 1,
            'slug' => 2,
            'content' => 3,
            'summary' => 4,
            'categories' => 5,
            'tags' => 6,
            'image_url' => 7,
            'status' => 8,
            'published_at' => 9,
            'seo_title' => 10,
            'seo_description' => 11,
            'permalink' => 13,
        ];

        foreach ($header as $idx => $val) {
            $norm = mb_strtolower(trim((string) $val), 'UTF-8');
            match (true) {
                str_contains($norm, 'công khai') || str_contains($norm, 'permalink') || $norm === 'url' => $colMap['permalink'] = $idx,
                str_contains($norm, 'seo description') => $colMap['seo_description'] = $idx,
                str_contains($norm, 'seo title') => $colMap['seo_title'] = $idx,
                str_contains($norm, 'id') => $colMap['wp_id'] = $idx,
                str_contains($norm, 'tiêu đề') || str_contains($norm, 'tieu de') || str_contains($norm, 'title') => $colMap['title'] = $idx,
                str_contains($norm, 'slug') || $norm === 'đường dẫn' || $norm === 'duong dan' => $colMap['slug'] = $idx,
                str_contains($norm, 'nội dung') || str_contains($norm, 'noi dung') || str_contains($norm, 'content') => $colMap['content'] = $idx,
                str_contains($norm, 'tóm tắt') || str_contains($norm, 'tom tat') || str_contains($norm, 'summary') => $colMap['summary'] = $idx,
                str_contains($norm, 'chuyên mục') || str_contains($norm, 'chuyen muc') || str_contains($norm, 'category') => $colMap['categories'] = $idx,
                str_contains($norm, 'thẻ') || str_contains($norm, 'the') || str_contains($norm, 'tag') => $colMap['tags'] = $idx,
                str_contains($norm, 'ảnh') || str_contains($norm, 'anh') || str_contains($norm, 'thumbnail') => $colMap['image_url'] = $idx,
                str_contains($norm, 'trạng thái') || str_contains($norm, 'trang thai') || str_contains($norm, 'status') => $colMap['status'] = $idx,
                str_contains($norm, 'ngày đăng') || str_contains($norm, 'ngay dang') || str_contains($norm, 'date') => $colMap['published_at'] = $idx,
                default => null,
            };
        }

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $title = trim((string) ($row[$colMap['title']] ?? ''));
            if ($title === '') {
                continue;
            }

            $rows[] = [
                'wp_id' => trim((string) ($row[$colMap['wp_id']] ?? '')),
                'title' => $title,
                'slug' => trim((string) ($row[$colMap['slug']] ?? '')),
                'content' => trim((string) ($row[$colMap['content']] ?? '')),
                'summary' => trim((string) ($row[$colMap['summary']] ?? '')),
                'categories' => trim((string) ($row[$colMap['categories']] ?? '')),
                'tags' => trim((string) ($row[$colMap['tags']] ?? '')),
                'image_url' => trim((string) ($row[$colMap['image_url']] ?? '')),
                'status' => trim((string) ($row[$colMap['status']] ?? '')),
                'published_at' => trim((string) ($row[$colMap['published_at']] ?? '')),
                'seo_title' => trim((string) ($row[$colMap['seo_title']] ?? '')),
                'seo_description' => trim((string) ($row[$colMap['seo_description']] ?? '')),
                'permalink' => trim((string) ($row[$colMap['permalink']] ?? '')),
            ];
        }

        fclose($fh);

        return $rows;
    }

    protected function clearPostCaches(): void
    {
        foreach ([
            'home_blog_sections_map',
            'blog_sidebar_categories',
            'blog_popular_post_ids',
            'blog_recent_post_ids',
            'global_shared_view_data_v2',
        ] as $key) {
            Cache::forget($key);
        }
    }
}
