<?php

namespace Tests\Feature\Migration;

use Tests\TestCase;

class AccessibilityAndUXTest extends TestCase
{
    public function test_html_lang_viewport_single_h1_landmarks_and_form_labels_on_key_pages(): void
    {
        $pages = [
            '/',
            '/tin-blog/',
            '/tu-dong-hoa/',
            '/lien-he/',
            '/cam-bien-tu-la-gi-cau-tao-nguyen-ly-va-ung-dung/',
            '/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/',
        ];

        foreach ($pages as $path) {
            $res = $this->get($path);
            $res->assertStatus(200);
            $html = $res->getContent();

            $this->assertMatchesRegularExpression('/<html[^>]*\blang=["\']vi["\']/i', $html, "Missing lang=vi on [{$path}]");
            $this->assertMatchesRegularExpression('/<meta[^>]*\bname=["\']viewport["\']/i', $html, "Missing viewport meta on [{$path}]");

            $h1Count = preg_match_all('/<h1\b[^>]*>.*?<\/h1>/is', $html);
            $this->assertSame(1, $h1Count, "Expected exactly 1 <h1> on [{$path}], found {$h1Count}");

            $this->assertMatchesRegularExpression('/<nav\b[^>]*aria-label=/i', $html, "Missing accessible <nav aria-label> on [{$path}]");
            $this->assertMatchesRegularExpression('/<footer\b/i', $html, "Missing <footer> landmark on [{$path}]");
        }

        // Contact form input labels check
        $contactHtml = $this->get('/lien-he/')->getContent();
        $this->assertStringContainsString('for="fullname"', $contactHtml);
        $this->assertStringContainsString('for="phone"', $contactHtml);
        $this->assertStringContainsString('for="message"', $contactHtml);
    }
}
