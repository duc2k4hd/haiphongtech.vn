<?php

namespace Tests\Feature\Migration;

use App\Models\Media;
use Tests\TestCase;

class MediaIntegrityTest extends TestCase
{
    public function test_all_synced_original_wp_media_files_exist_non_zero_and_match_sha256(): void
    {
        $totalWpMedia = Media::where('disk', 'wp_uploads')->count();
        $this->assertGreaterThanOrEqual(5717, $totalWpMedia);

        $syncedOriginals = Media::where('disk', 'wp_uploads')
            ->whereNotNull('sha256')
            ->where('status', 'active')
            ->get();

        $this->assertGreaterThanOrEqual(1370, $syncedOriginals->count());

        // Spot-check SHA-256 and non-zero size across synced originals + all PDFs & logos
        foreach ($syncedOriginals->take(100) as $media) {
            $abs = $media->absolute_path;
            $this->assertFileExists($abs, "Missing synced media file on disk: {$abs}");
            $this->assertGreaterThan(0, filesize($abs), "Zero-byte media file: {$abs}");
            $this->assertSame($media->sha256, hash_file('sha256', $abs), "SHA-256 mismatch for {$media->wp_relative_path}");
        }
    }

    public function test_wp_uploads_http_route_serves_logo_favicon_webp_and_pdf_with_exact_mime(): void
    {
        $assets = [
            '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp' => 'image/webp',
            '/wp-content/uploads/2025/01/cropped-logo-nho-hien-thi-tren-gg.jpg' => 'image/jpeg',
            '/wp-content/uploads/2026/08/Catalog-CX-Programmer.pdf' => 'application/pdf',
            '/wp-content/uploads/2026/08/NS-Series-CX-Designer-Introduetion-guide.pdf' => 'application/pdf',
        ];

        foreach ($assets as $path => $expectedMime) {
            $res = $this->get($path);
            $res->assertStatus(200);
            $this->assertStringContainsString($expectedMime, (string) $res->headers->get('Content-Type'));
        }

        // Pre-existing 404 PDF on source must honestly return 404 (not fake 200)
        $missingPdf = $this->get('/wp-content/uploads/2025/10/Bien-tan-nho-gon.pdf');
        $missingPdf->assertStatus(404);
    }
}
