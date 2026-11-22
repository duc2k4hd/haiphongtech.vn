<?php

namespace App\Support;

class WpUrl
{
    /**
     * Build an absolute URL preserving exact trailing slash (unlike Laravel's url() which trims '/').
     */
    public static function absolute(string $pathOrUrl): string
    {
        $trimmed = trim($pathOrUrl);
        if ($trimmed === '') {
            return '';
        }
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        $root = rtrim(request()->getSchemeAndHttpHost() ?: (string) config('app.url', 'http://localhost:8000'), '/');
        if ($trimmed === '/') {
            return $root . '/';
        }

        return $root . '/' . ltrim($trimmed, '/');
    }

    /**
     * Rewrite domain references inside decoded JSON-LD schema from https://haiphongtech.vn to active host
     * while preserving exact paths and trailing slashes.
     */
    public static function adaptSchemaJson(?string $schemaJson): ?string
    {
        if (empty($schemaJson)) {
            return null;
        }
        $activeOrigin = rtrim(request()->getSchemeAndHttpHost() ?: (string) config('app.url', 'http://localhost:8000'), '/');
        $adapted = str_replace(
            ['https://haiphongtech.vn', 'http://haiphongtech.vn'],
            $activeOrigin,
            $schemaJson
        );
        return $adapted;
    }

    /**
     * Extract raw request path BEFORE query string, preserving trailing slash status.
     */
    public static function rawRequestPath(\Illuminate\Http\Request $request): string
    {
        $uri = (string) ($request->server('REQUEST_URI') ?? $request->getRequestUri());
        $qPos = strpos($uri, '?');
        $path = $qPos !== false ? substr($uri, 0, $qPos) : $uri;
        return $path !== '' ? $path : '/';
    }

    public static function shopUrl(int $page = 1): string
    {
        return self::absolute($page > 1 ? "/shop/page/{$page}/" : '/shop/');
    }

    public static function categoryUrl(string $slug, int $page = 1): string
    {
        $clean = trim($slug, '/');
        return self::absolute($page > 1 ? "/{$clean}/page/{$page}" : "/{$clean}");
    }

    public static function productUrl(string $slug): string
    {
        $clean = trim($slug, '/');
        return self::absolute("/product/{$clean}/");
    }

    public static function brandUrl(string $slug, int $page = 1): string
    {
        $clean = trim($slug, '/');
        return self::absolute($page > 1 ? "/{$clean}/page/{$page}" : "/{$clean}");
    }

    public static function productTagUrl(string $slug, int $page = 1): string
    {
        $clean = trim($slug, '/');
        return self::absolute($page > 1 ? "/tag-san-pham/{$clean}/page/{$page}/" : "/tag-san-pham/{$clean}/");
    }

    public static function resolveCategoryImage(?string $icon, string $fallbackAsset = 'storage/clients/imgs/categories/no-image.png'): string
    {
        $trimmed = trim((string) $icon);
        if ($trimmed === '') {
            return asset($fallbackAsset);
        }
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }
        if (str_starts_with($trimmed, '/')) {
            return url($trimmed);
        }
        if (str_starts_with($trimmed, 'wp-content/')) {
            return url('/' . $trimmed);
        }
        return asset('storage/clients/imgs/categories/' . $trimmed);
    }
}
