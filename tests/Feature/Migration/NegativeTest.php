<?php

namespace Tests\Feature\Migration;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NegativeTest extends TestCase
{
    public function test_non_existent_urls_return_honest_404_without_redirect_loop_or_fake_200(): void
    {
        $unknownUrls = [
            '/duong-dan-khong-ton-tai-abc-999999/',
            '/duong-dan-khong-ton-tai-abc-999999',
            '/tag/the-khong-ton-tai-abc-999999/',
            '/tin-blog/page/999/',
            '/tu-dong-hoa/page/999/',
            '/wp-content/uploads/2099/12/nonexistent-image.webp',
        ];

        foreach ($unknownUrls as $url) {
            $res = $this->get($url);
            $res->assertStatus(404);
            $body = $res->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $body);
            $this->assertStringNotContainsString('DB_PASSWORD', $body);
        }
    }

    public function test_wp_uploads_blocks_path_traversal_and_disallowed_extensions(): void
    {
        $this->get('/wp-content/uploads/../../.env')->assertStatus(404);
        $this->get('/wp-content/uploads/2025/08/shell.php')->assertStatus(404);
        $this->get('/wp-content/uploads/2025/08/config.sql')->assertStatus(404);
    }

    public function test_contact_form_validates_inputs_and_sanitizes_xss_payloads(): void
    {
        DB::beginTransaction();
        try {
            $validTimeToken = Crypt::encryptString((string) (time() - 5));

            // 1. Without CSRF token in staging environment, POST must be rejected with 419
            $csrfBlocked = $this->postJson('/lien-he', [
                '_form_time_token' => $validTimeToken,
                'fullname'         => 'Nguyễn Văn A',
                'phone'            => '0912345678',
                'message'          => 'Test CSRF protection',
            ]);
            $csrfBlocked->assertStatus(419);

            // 2. With valid session CSRF token, invalid phone must fail validation (422)
            $csrfToken = 'test-staging-csrf-token';
            $invalidRes = $this->withSession(['_token' => $csrfToken])->postJson('/lien-he', [
                '_token'           => $csrfToken,
                '_form_time_token' => $validTimeToken,
                'fullname'         => 'Nguyễn Văn A',
                'phone'            => '12345',
                'message'          => 'Cần báo giá PLC Omron chính hãng',
            ]);
            $invalidRes->assertStatus(422);

            // 3. XSS tags inside message must be stripped before DB insert
            $validRes = $this->withSession(['_token' => $csrfToken])->postJson('/lien-he', [
                '_token'           => $csrfToken,
                '_form_time_token' => $validTimeToken,
                'fullname'         => 'Trần Thị B',
                'phone'            => '0912345678',
                'email'            => 'khachhang@example.com',
                'message'          => '<script>alert(1)</script>Yêu cầu tư vấn biến tần ABB',
            ]);
            $validRes->assertStatus(200);

            $saved = DB::table('contacts')->orderByDesc('id')->first();
            $this->assertNotNull($saved);
            $this->assertStringNotContainsString('<script>', $saved->message);
            $this->assertStringNotContainsString('misutech', strtolower($saved->email));
        } finally {
            DB::rollBack();
        }
    }
}
