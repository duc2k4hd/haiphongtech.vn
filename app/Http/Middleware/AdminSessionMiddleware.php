<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tách biệt hoàn toàn phiên làm việc (session cookie & remember cookie)
 * giữa khu vực quản trị (/admin) và khu vực khách hàng (client).
 *
 * Middleware này phải chạy TRƯỚC StartSession để SessionManager khởi tạo
 * đúng tên cookie và đường dẫn cookie riêng cho admin.
 */
class AdminSessionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $baseCookie = (string) config('session.cookie', 'laravel-session');
        if (! str_ends_with($baseCookie, '-admin')) {
            $adminCookie = $baseCookie . '-admin';
        } else {
            $adminCookie = $baseCookie;
        }

        config([
            'session.cookie' => $adminCookie,
            'session.path' => '/admin',
        ]);

        // Đồng bộ đường dẫn cookie mặc định cho CookieJar để cookie ghi nhớ (remember_admin_...) chỉ áp dụng trong /admin
        app('cookie')->setDefaultPathAndDomain(
            '/admin',
            config('session.domain'),
            (bool) config('session.secure'),
            config('session.same_site', 'lax')
        );

        return $next($request);
    }
}
