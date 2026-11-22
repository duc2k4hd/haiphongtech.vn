<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WpMediaController extends Controller
{
    private const ALLOWED_EXTENSIONS = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'pdf'  => 'application/pdf',
        'zip'  => 'application/zip',
    ];

    /**
     * Serve original WordPress media paths (/wp-content/uploads/{path}) with strict path-traversal safety.
     */
    public function serve(Request $request, string $path): Response
    {
        $cleanRel = ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
        if ($cleanRel === '' || str_contains($cleanRel, '..') || str_contains($cleanRel, "\0")) {
            abort(404);
        }

        $ext = strtolower(pathinfo($cleanRel, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_EXTENSIONS[$ext])) {
            abort(404);
        }

        $absPath = public_path('wp-content/uploads/' . $cleanRel);
        if (is_file($absPath) && filesize($absPath) > 0) {
            return response()->file($absPath, [
                'Content-Type'  => self::ALLOWED_EXTENSIONS[$ext],
                'Cache-Control' => 'public, max-age=31536000',
            ]);
        }

        // On non-production staging, allow safe read-only fetch & cache for registered or responsive WP upload variants
        if (strtolower((string) config('app.env')) !== 'production' && preg_match('#^\d{4}/\d{2}/[^\\<>:"|?*\x00-\x1f]+$#u', $cleanRel)) {
            $media = Media::where('disk', 'wp_uploads')->where('wp_relative_path', $cleanRel)->first();
            if ($media && $media->status === 'missing') {
                abort(404);
            }

            $remoteUrl = 'https://haiphongtech.vn/wp-content/uploads/' . implode('/', array_map('rawurlencode', explode('/', $cleanRel)));
            $ch = curl_init($remoteUrl);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 2,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_USERAGENT      => 'HaiPhongTechMigrationStaging/1.0',
            ];
            if (defined('CURLSSLOPT_NATIVE_CA')) {
                $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
            }
            curl_setopt_array($ch, $opts);
            $bytes = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($bytes !== false && $code === 200 && strlen($bytes) > 0) {
                $dir = dirname($absPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($absPath, $bytes);

                if ($media) {
                    $media->update([
                        'sha256'   => hash('sha256', $bytes),
                        'checksum' => md5($bytes),
                        'size'     => strlen($bytes),
                        'status'   => 'active',
                    ]);
                }

                return response($bytes, 200, [
                    'Content-Type'  => self::ALLOWED_EXTENSIONS[$ext],
                    'Cache-Control' => 'public, max-age=31536000',
                ]);
            }

            if ($media) {
                $media->update(['status' => 'missing', 'notes' => "SOURCE_HTTP_{$code}"]);
            }
        }

        abort(404);
    }
}
