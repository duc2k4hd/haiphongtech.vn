@php
    $footerLogoUrl = !empty($settings->site_logo)
        ? (Str::startsWith($settings->site_logo, ['http://', 'https://', '/'])
            ? $settings->site_logo
            : asset('storage/clients/imgs/settings/' . $settings->site_logo))
        : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
    $faviconSmall = !empty($settings->site_favicon)
        ? (Str::startsWith($settings->site_favicon, ['http://', 'https://', '/'])
            ? $settings->site_favicon
            : asset('storage/clients/imgs/settings/' . $settings->site_favicon))
        : '/wp-content/uploads/2025/01/cropped-logo-nho-hien-thi-tren-gg.jpg';
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $addressText = $settings->address ?? 'Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam';
    $hotlineDisplay = $settings->hotline ?? '0866.555.212';
    $hotlineRaw = preg_replace('/[^0-9]/', '', $settings->phone ?? ($settings->hotline ?? '0866555212'));
    $emailDisplay = $settings->email ?? 'kinhdoanhhpt@haiphongtech.vn';
    $openingHours = $settings->opening_hours ?? '8:00 - 18:00 (T2 - T7)';
    $fbHref = $settings->facebook ?? ($settings->facebook_url ?? 'https://www.facebook.com/HaiPhongTech.vn');
    $zaloHref = $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212');
@endphp

<footer class="misutech_home_footer">
    <div class="misutech_home_footer_top">
        <div class="misutech_home_container">
            <div class="misutech_home_footer_top_grid">
                {{-- Cột 1: Thương hiệu & Liên hệ --}}
                <div class="misutech_home_footer_col misutech_home_footer_brand">
                    <a href="/" class="misutech_home_footer_logo_link" aria-label="Trang chủ {{ $companyName }}">
                        <img src="{{ $footerLogoUrl }}" alt="{{ $brandName }}" class="misutech_home_footer_logo_img" width="160" height="44" loading="lazy" decoding="async">
                    </a>
                    <p class="misutech_home_footer_company">{{ $companyName }}</p>
                    <p class="misutech_home_footer_desc">
                        Nhà phân phối thiết bị điện &amp; tự động hóa công nghiệp chính hãng: PLC, HMI, Cảm biến, Biến tần, Relay, Nguồn tổ ong.
                    </p>

                    <ul class="misutech_home_footer_contact_list">
                        <li>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span>{{ $addressText }}</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                            <a href="tel:{{ $hotlineRaw }}" class="misutech_home_footer_hotline_link">{{ $hotlineDisplay }}</a>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                            <a href="mailto:{{ $emailDisplay }}">{{ $emailDisplay }}</a>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            <span>Giờ làm việc: {{ $openingHours }}</span>
                        </li>
                    </ul>
                </div>

                {{-- Cột 2: Tiện ích & Tra cứu nhanh --}}
                <div class="misutech_home_footer_col">
                    <h3 class="misutech_home_footer_col_title">TIỆN ÍCH &amp; TRA CỨU</h3>
                    <ul class="misutech_home_footer_col_list">
                        <li><a href="{{ \App\Support\WpUrl::shopUrl() }}">Tất cả sản phẩm</a></li>
                        <li><a href="{{ route('brands.index') }}">Thương hiệu đối tác</a></li>
                        <li><a href="{{ route('series.index') }}">Tra cứu dòng sản phẩm (Series)</a></li>
                        <li><a href="{{ route('documents.index') }}">Kho tài liệu &amp; Catalog PDF</a></li>
                        <li><a href="{{ route('quote.index') }}">Tạo phiếu báo giá online</a></li>
                        <li><a href="{{ route('cart.alias') }}">Giỏ hàng của bạn</a></li>
                        <li><a href="/tu-dong-hoa/">Tự động hóa &amp; Kiến thức kỹ thuật</a></li>
                        <li><a href="/lien-he/">Gửi yêu cầu liên hệ</a></li>
                    </ul>
                </div>

                {{-- Cột 3: Chính sách & Điều khoản --}}
                <div class="misutech_home_footer_col">
                    <h3 class="misutech_home_footer_col_title">CHÍNH SÁCH &amp; QUY ĐỊNH</h3>
                    <ul class="misutech_home_footer_col_list">
                        @if(isset($footerPolicyItems) && $footerPolicyItems->isNotEmpty())
                            @foreach($footerPolicyItems as $pItem)
                                <li><a href="{{ $pItem->url }}">{{ $pItem->title }}</a></li>
                            @endforeach
                        @else
                            <li><a href="/chinh-sach-bao-hanh-thiet-bi-cong-nghiep-chinh-hang-warranty-policy/">Chính sách bảo hành</a></li>
                            <li><a href="/chinh-sach-van-chuyen-nhanh-an-toan-giao-toan-quoc/">Chính sách vận chuyển</a></li>
                            <li><a href="/chinh-sach-doi-tra-san-pham-return-refund-policy/">Chính sách đổi trả sản phẩm</a></li>
                            <li><a href="/chinh-sach-thanh-toan-an-toan-da-dang-cod-chuyen-khoan/">Hướng dẫn thanh toán</a></li>
                            <li><a href="/chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy/">Bảo mật thông tin khách hàng</a></li>
                            <li><a href="/dieu-khoan-su-dung-website-quy-dinh-trach-nhiem/">Điều khoản sử dụng</a></li>
                        @endif
                    </ul>
                </div>

                {{-- Cột 4: Hỗ trợ nhanh & Nhận bảng giá --}}
                <div class="misutech_home_footer_col misutech_home_footer_support">
                    <h3 class="misutech_home_footer_col_title">HỖ TRỢ &amp; NHẬN BÁO GIÁ</h3>
                    <div class="misutech_home_footer_quick_box">
                        <div class="misutech_home_footer_quick_actions">
                            <a href="tel:{{ $hotlineRaw }}" class="misutech_footer_cta_primary">
                                Gọi ngay: {{ $hotlineDisplay }}
                            </a>
                            <a href="{{ $zaloHref }}" target="_blank" rel="noopener noreferrer" class="misutech_footer_cta_outline">
                                Chat Zalo kỹ thuật
                            </a>
                        </div>

                        <p class="misutech_footer_newsletter_label">Đăng ký nhận bảng giá &amp; tài liệu mới:</p>
                        <form class="misutech_home_newsletter misutech_footer_newsletter_form" action="#" method="POST">
                            <input type="email" name="email" placeholder="Nhập email của bạn..." required aria-label="Email nhận báo giá">
                            <button type="submit">Đăng ký</button>
                        </form>
                    </div>

                    <div class="misutech_home_footer_socials">
                        <span class="misutech_footer_social_label">Kết nối:</span>
                        <a href="{{ $fbHref }}" target="_blank" rel="noopener noreferrer" class="misutech_home_footer_social_btn" aria-label="Facebook Hải Phòng Tech" title="Facebook">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="currentColor" d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z"/></svg>
                        </a>
                        <a href="{{ $zaloHref }}" target="_blank" rel="noopener noreferrer" class="misutech_home_footer_social_btn misutech_footer_social_zalo" aria-label="Zalo Hải Phòng Tech" title="Zalo">
                            <span>Zalo</span>
                        </a>
                        <a href="/lien-he/" class="misutech_home_footer_social_btn" aria-label="Liên hệ Hải Phòng Tech" title="Liên hệ">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="misutech_home_footer_bottom">
        <div class="misutech_home_container misutech_home_footer_bottom_wrap">
            <div class="misutech_home_footer_copy">
                <img width="24" height="24" src="{{ $faviconSmall }}" alt="{{ $brandName }}" loading="lazy" decoding="async">
                <span>{{ $settings->copyright ?? ('© ' . date('Y') . ' ' . $companyName . '. Bảo lưu mọi quyền.') }}</span>
            </div>
            <div class="misutech_home_footer_trust">
                <a href="/sitemap_index.xml">Sitemap</a>
                @if(!empty($settings->dmca_url))
                    <span>·</span>
                    <a href="{{ $settings->dmca_url }}" target="_blank" rel="noopener noreferrer">DMCA Protected</a>
                @endif
                @if(!empty($settings->moit_gov_url))
                    <span>·</span>
                    <a href="{{ $settings->moit_gov_url }}" target="_blank" rel="noopener noreferrer">Đã thông báo Bộ Công Thương</a>
                @endif
            </div>
        </div>
    </div>
</footer>
