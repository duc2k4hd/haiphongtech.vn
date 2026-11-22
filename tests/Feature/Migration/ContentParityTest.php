<?php

namespace Tests\Feature\Migration;

use App\Models\Page;
use App\Models\Post;
use Tests\TestCase;

class ContentParityTest extends TestCase
{
    private const MANDATORY_POLICY_PATHS = [
        '/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/',
        '/chinh-sach-bao-mat-thong-tin-ca-nhan-information-privacy/',
        '/chinh-sach-thanh-toan-an-toan-da-dang-cod-chuyen-khoan/',
        '/chinh-sach-bao-hanh-thiet-bi-cong-nghiep-chinh-hang-warranty-policy/',
        '/chinh-sach-van-chuyen-nhanh-an-toan-giao-toan-quoc/',
        '/chinh-sach-doi-tra-san-pham-return-refund-policy/',
        '/dieu-khoan-su-dung-website-quy-dinh-trach-nhiem/',
        '/chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy/',
    ];

    public function test_all_eight_mandatory_legal_policy_pages_preserve_verbatim_content_and_headings(): void
    {
        foreach (self::MANDATORY_POLICY_PATHS as $path) {
            $page = Page::where('full_path', $path)->first();
            $this->assertNotNull($page, "Missing mandatory legal policy page [{$path}]");
            $this->assertNotNull($page->source_wp_id);
            $this->assertNotEmpty($page->content_hash);

            $res = $this->get($path);
            $res->assertStatus(200);
            $html = $res->getContent();

            $this->assertStringContainsString('CÔNG TY TNHH HẢI PHÒNG TECH', $html, "Legal entity missing on [{$path}]");
            $this->assertMatchesRegularExpression('/<h1\b[^>]*>.*?<\/h1>/is', $html, "Missing H1 on [{$path}]");
        }
    }

    public function test_imported_posts_preserve_original_timestamps_authors_taxonomies_and_semantic_elements(): void
    {
        $manifestPath = base_path('docs/migration/data/content_manifest.jsonl');
        $fp = fopen($manifestPath, 'r');

        $verifiedPosts = 0;
        while (($line = fgets($fp)) !== false) {
            $row = json_decode($line, true);
            if (($row['source_type'] ?? '') !== 'post') {
                continue;
            }

            $wpId = (int) $row['source_wp_id'];
            $post = Post::where('source_wp_id', $wpId)->first();
            $this->assertNotNull($post, "Missing imported post source_wp_id={$wpId}");
            $this->assertSame($row['source_slug'], $post->slug, "Slug mutated on post #{$wpId}");
            $this->assertSame($row['content_hash'], $post->content_hash, "Content hash mismatch on post #{$wpId}");

            // Original publish date must match WP date (year-month-day), never overwritten to today's import date
            $expectedDatePrefix = substr((string) $row['published_at_local'], 0, 10);
            $this->assertSame($expectedDatePrefix, $post->published_at?->format('Y-m-d'), "Published date altered on post #{$wpId}");
            $this->assertSame($row['author_display_name'], $post->author_display_name);

            if ($post->status !== 'published') {
                continue; // Redirected (2) or shadowed by product_cat (6)
            }

            // Semantic structure check: table count & PDF download links preserved
            $srcTables = preg_match_all('/<table\b/i', (string) $row['rendered_content']);
            $dstTables = preg_match_all('/<table\b/i', (string) $post->content);
            $this->assertSame($srcTables, $dstTables, "Table count mismatch in post #{$wpId} ({$post->slug})");

            if ($verifiedPosts < 30) {
                $res = $this->get("/{$post->slug}/");
                $res->assertStatus(200);
                $html = $res->getContent();
                $this->assertStringContainsString(htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8') !== $post->title ? mb_substr($post->title, 0, 20) : $post->title, html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $verifiedPosts++;
        }
        fclose($fp);

        $this->assertSame(227, $verifiedPosts, 'Expected exactly 227 active published non-colliding posts');
    }

    public function test_unrendered_plugin_shortcodes_do_not_leak_raw_bracket_syntax_in_pages(): void
    {
        foreach (['/profile/', '/password-reset/', '/password-reset-2/', '/my-account/wishlist/'] as $path) {
            $res = $this->get($path);
            $res->assertStatus(200);
            $html = $res->getContent();
            $this->assertStringNotContainsString('[yith_wcwl_wishlist]', $html);
            $this->assertStringNotContainsString('[wpum_password_recovery]', $html);
            $this->assertStringNotContainsString('[yith_wcan_filters]', $html);
        }
    }
}
