@php
    $headerLogoUrl = !empty($settings->site_logo)
        ? (Str::startsWith($settings->site_logo, ['http://', 'https://', '/'])
            ? $settings->site_logo
            : asset('storage/clients/imgs/settings/' . $settings->site_logo))
        : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $hotlineRaw = preg_replace('/[^0-9]/', '', $settings->phone ?? ($settings->hotline ?? '0866555212'));
    $hotlineDisplay = $settings->hotline ?? '0866.555.212';
    $emailDisplay = $settings->email ?? 'kinhdoanhhpt@haiphongtech.vn';
    $fbHref = $settings->facebook ?? ($settings->facebook_url ?? 'https://www.facebook.com/HaiPhongTech.vn');
    $zaloHref = $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212');
@endphp

<div class="misutech_home_topbar">
    <div class="misutech_home_container misutech_home_topbar_inner">
        <div class="misutech_home_topbar_left">
            <a class="misutech_home_topbar_item" href="mailto:{{ $emailDisplay }}">
                <svg class="misutech_home_topbar_icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
                    <path fill="currentColor"
                        d="M.05 3.555A2 2 0 0 1 2 2h12a2 2 0 0 1 1.95 1.555L8 8.414.05 3.555zM0 4.697v7.104l5.803-3.558L0 4.697zM6.761 8.83l-6.57 4.027A2 2 0 0 0 2 14h12a2 2 0 0 0 1.808-1.144l-6.57-4.027L8 9.586l-1.239-.757zm3.436-.586L16 11.801V4.697l-5.803 3.546z" />
                </svg>
                Email : {{ $emailDisplay }}
            </a>
            <a class="misutech_home_topbar_item" href="tel:{{ $hotlineRaw }}">
                <svg class="misutech_home_topbar_icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
                    <path fill="currentColor"
                        d="M3.654 1.328a.678.678 0 0 0-1.015-.063L1.605 2.3c-.483.484-.661 1.169-.45 1.77a17.568 17.568 0 0 0 4.168 6.608 17.569 17.569 0 0 0 6.608 4.168c.601.211 1.286.033 1.77-.45l1.034-1.034a.678.678 0 0 0-.063-1.015l-2.307-1.794a.678.678 0 0 0-.58-.122l-2.19.547a1.745 1.745 0 0 1-1.657-.459L5.482 8.062a1.745 1.745 0 0 1-.46-1.657l.548-2.19a.678.678 0 0 0-.122-.58L3.654 1.328z" />
                </svg>
                Hotline : {{ $hotlineDisplay }}
            </a>
            <span class="misutech_home_topbar_item">
                Giờ mở cửa : {{ $settings->opening_hours ?? '8:00AM - 6:00PM' }}
            </span>
        </div>
        <div class="misutech_home_topbar_right">
            <a class="misutech_home_topbar_social" href="{{ $fbHref }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook Hải Phòng Tech">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
                    <path fill="currentColor"
                        d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z" />
                </svg>
            </a>
            <a class="misutech_home_topbar_social" href="{{ $zaloHref }}" target="_blank" rel="noopener noreferrer" aria-label="Zalo Hải Phòng Tech">
                <span>Zalo</span>
            </a>
        </div>
    </div>
</div>

<div class="misutech_header_sticky_group" id="misutechHeaderStickyGroup">
    <header class="misutech_home_header">
        <div class="misutech_home_container misutech_home_header_inner">
            <button class="misutech_mobile_hamburger" id="btnOpenMobileDrawer" type="button" aria-label="Mở menu điều hướng di động">
                <span class="hamburger_line"></span>
                <span class="hamburger_line"></span>
                <span class="hamburger_line"></span>
            </button>

            <a class="misutech_home_logo" href="/" aria-label="Trang chủ {{ $brandName }}">
                <img src="{{ $headerLogoUrl }}" alt="{{ $brandName }}" width="200" height="50" fetchpriority="high">
            </a>

            <form class="misutech_home_search" role="search" action="/tin-blog/" method="GET">
                <input class="misutech_home_search_input" name="q" type="search" placeholder="Tìm kiếm bài viết, tài liệu kỹ thuật..."
                    aria-label="Tìm kiếm bài viết" value="{{ request('q') }}" />
                <button class="misutech_home_search_button" type="submit" aria-label="Tìm kiếm">
                    ⌕
                </button>
            </form>

            <div class="misutech_home_header_actions">
                <a href="tel:{{ $hotlineRaw }}" class="misutech_home_header_action">
                    <span class="misutech_home_header_action_icon">☎</span>
                    <span class="misutech_home_header_action_copy">Tư vấn miễn phí<strong>{{ $hotlineDisplay }}</strong></span>
                </a>
                <a href="/my-account/" class="misutech_home_header_action">
                    <span class="misutech_home_header_action_icon">♙</span>
                    <span class="misutech_home_header_action_copy">Tài khoản<strong>Khách hàng</strong></span>
                </a>
                <a href="/cart/" class="misutech_home_header_action">
                    <span class="misutech_home_header_action_icon">▣<span class="misutech_home_cart_count">{{ session('cart_count', 0) }}</span></span>
                    <span class="misutech_home_header_action_copy">Giỏ hàng<strong>{{ session('cart_count', 0) }} sản phẩm</strong></span>
                </a>
            </div>
        </div>
    </header>

    <nav class="misutech_home_nav" aria-label="Điều hướng chính">
        <div class="misutech_home_container misutech_home_nav_inner">
            <div class="misutech_home_nav_links">
                @if(isset($headerMenuItems) && $headerMenuItems->isNotEmpty())
                    @foreach($headerMenuItems as $navItem)
                        <a class="misutech_home_nav_link {{ request()->getPathInfo() === rtrim($navItem->url, '/') || (rtrim($navItem->url, '/') === '' && request()->is('/')) ? 'active' : '' }}"
                           href="{{ $navItem->url }}">{{ $navItem->title }}</a>
                    @endforeach
                @else
                    <a class="misutech_home_nav_link" href="/">Trang chủ</a>
                    <a class="misutech_home_nav_link" href="/gioi-thieu/">Giới thiệu</a>
                    <a class="misutech_home_nav_link" href="/tu-dong-hoa/">Tự động hóa</a>
                    <a class="misutech_home_nav_link" href="/tin-blog/">Tin Blog</a>
                    <a class="misutech_home_nav_link" href="/tai-lieu-ki-thuat/">Tài liệu kĩ thuật</a>
                    <a class="misutech_home_nav_link" href="/lien-he/">Liên hệ</a>
                @endif
            </div>
        </div>
    </nav>
</div>

<div class="misutech_menu_backdrop" id="menuBackdrop"></div>
<div class="misutech_drawer_backdrop" id="mobileDrawerBackdrop" aria-hidden="true"></div>

<aside class="misutech_mobile_drawer" id="mobileDrawer" aria-label="Menu điều hướng di động" aria-hidden="true">
    <div class="misutech_drawer_header">
        <a href="/" class="misutech_drawer_logo" aria-label="Trang chủ {{ $brandName }}">
            <img src="{{ $headerLogoUrl }}" alt="{{ $brandName }}">
        </a>
        <button type="button" class="misutech_drawer_close" id="btnCloseMobileDrawer" aria-label="Đóng menu">✕</button>
    </div>

    <div class="misutech_drawer_search_wrap">
        <form class="misutech_drawer_search" role="search" action="/tin-blog/" method="GET">
            <input class="misutech_drawer_search_input" name="q" type="search" placeholder="Tìm kiếm bài viết..."
                aria-label="Tìm kiếm bài viết" value="{{ request('q') }}" />
            <button class="misutech_drawer_search_btn" type="submit" aria-label="Tìm kiếm">⌕</button>
        </form>
    </div>

    <div class="misutech_drawer_panel is-active" id="drawerPanelMenu">
        <ul class="misutech_drawer_navlist">
            @if(isset($headerMenuItems) && $headerMenuItems->isNotEmpty())
                @foreach($headerMenuItems as $navItem)
                    <li>
                        <a href="{{ $navItem->url }}" class="misutech_drawer_navitem">
                            <span>{{ $navItem->title }}</span>
                        </a>
                    </li>
                @endforeach
            @else
                <li><a href="/" class="misutech_drawer_navitem"><span>Trang chủ</span></a></li>
                <li><a href="/gioi-thieu/" class="misutech_drawer_navitem"><span>Giới thiệu</span></a></li>
                <li><a href="/tu-dong-hoa/" class="misutech_drawer_navitem"><span>Tự động hóa</span></a></li>
                <li><a href="/tin-blog/" class="misutech_drawer_navitem"><span>Tin Blog</span></a></li>
                <li><a href="/tai-lieu-ki-thuat/" class="misutech_drawer_navitem"><span>Tài liệu kĩ thuật</span></a></li>
                <li><a href="/lien-he/" class="misutech_drawer_navitem"><span>Liên hệ</span></a></li>
            @endif
        </ul>
    </div>

    <div class="misutech_drawer_footer">
        <div class="misutech_drawer_hotline_box">
            <div class="misutech_drawer_hotline_label">Hotline & Tư vấn</div>
            <a href="tel:{{ $hotlineRaw }}" class="misutech_drawer_hotline_btn">
                <span>☎ {{ $hotlineDisplay }}</span>
            </a>
        </div>
        <div class="misutech_drawer_email">
            <span>✉ {{ $emailDisplay }}</span>
        </div>
    </div>
</aside>
