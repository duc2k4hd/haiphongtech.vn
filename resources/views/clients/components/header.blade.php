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

            <form class="misutech_home_search" role="search" action="/shop" method="GET">
                <span class="misutech_home_search_lead_icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="7.5"></circle>
                        <line x1="20.5" y1="20.5" x2="16.35" y2="16.35"></line>
                    </svg>
                </span>
                <input class="misutech_home_search_input" name="q" type="search" placeholder="Tìm kiếm sản phẩm, mã SKU, tài liệu kỹ thuật..."
                    aria-label="Tìm kiếm" value="{{ request('q') }}" />
                <button class="misutech_home_search_button" type="submit" aria-label="Tìm kiếm">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7.5"></circle>
                        <line x1="20.5" y1="20.5" x2="16.35" y2="16.35"></line>
                    </svg>
                    <span class="misutech_home_search_btn_text">Tìm kiếm</span>
                </button>
            </form>

            <div class="misutech_home_header_actions">
                <a href="tel:{{ $hotlineRaw }}" class="misutech_home_header_action">
                    <span class="misutech_home_header_action_icon">☎</span>
                    <span class="misutech_home_header_action_copy">Tư vấn miễn phí<strong>{{ $hotlineDisplay }}</strong></span>
                </a>
                <a href="{{ route('quote.index') }}" class="misutech_home_header_action">
                    <span class="misutech_home_header_action_icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                        </svg>
                    </span>
                    <span class="misutech_home_header_action_copy">Yêu cầu<strong>Báo giá nhanh</strong></span>
                </a>
                <a href="{{ route('cart.alias') }}" class="misutech_home_header_action">
                    <span class="misutech_home_header_action_icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="9" cy="21" r="1"></circle>
                            <circle cx="20" cy="21" r="1"></circle>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                        </svg>
                        <span class="misutech_home_cart_count">{{ session('cart_count', 0) }}</span>
                    </span>
                    <span class="misutech_home_header_action_copy">Giỏ hàng<strong id="misutechCartHeaderLabel">{{ session('cart_count', 0) }} sản phẩm</strong></span>
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
                    <a class="misutech_home_nav_link {{ request()->is('/') ? 'active' : '' }}" href="/">Trang chủ</a>
                    <a class="misutech_home_nav_link {{ request()->is('shop*') ? 'active' : '' }}" href="/shop/">Cửa hàng</a>
                    <a class="misutech_home_nav_link {{ request()->is('tai-lieu*') ? 'active' : '' }}" href="/tai-lieu-ki-thuat/">Tài liệu</a>
                    <a class="misutech_home_nav_link {{ request()->is('bao-gia*') ? 'active' : '' }}" href="/bao-gia">Báo giá</a>
                    <a class="misutech_home_nav_link {{ request()->is('gioi-thieu*') ? 'active' : '' }}" href="/gioi-thieu/">Giới thiệu</a>
                    <a class="misutech_home_nav_link {{ request()->is('lien-he*') ? 'active' : '' }}" href="/lien-he/">Liên hệ</a>
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
        <form class="misutech_drawer_search" role="search" action="/shop" method="GET">
            <input class="misutech_drawer_search_input" name="q" type="search" placeholder="Tìm kiếm sản phẩm, mã SKU..."
                aria-label="Tìm kiếm" value="{{ request('q') }}" />
            <button class="misutech_drawer_search_btn" type="submit" aria-label="Tìm kiếm">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7.5"></circle>
                    <line x1="20.5" y1="20.5" x2="16.35" y2="16.35"></line>
                </svg>
            </button>
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
                <li><a href="/shop/" class="misutech_drawer_navitem"><span>Cửa hàng</span></a></li>
                <li><a href="/tai-lieu-ki-thuat/" class="misutech_drawer_navitem"><span>Tài liệu</span></a></li>
                <li><a href="/bao-gia" class="misutech_drawer_navitem"><span>Báo giá</span></a></li>
                <li><a href="/gioi-thieu/" class="misutech_drawer_navitem"><span>Giới thiệu</span></a></li>
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
