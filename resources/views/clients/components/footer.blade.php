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
    $fbHref = $settings->facebook ?? ($settings->facebook_url ?? 'https://www.facebook.com/HaiPhongTech.vn');
    $zaloHref = $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212');
@endphp

<footer class="misutech_home_footer">
    <div class="misutech_home_footer_top">
        <div class="misutech_home_container">
            <div class="misutech_home_footer_logo_wrap">
                <a href="/" aria-label="Trang chủ {{ $companyName }}">
                    <img src="{{ $footerLogoUrl }}" alt="{{ $brandName }}" class="misutech_home_footer_logo_img" width="180" height="45" loading="lazy" decoding="async">
                </a>
            </div>

            <div class="misutech_home_footer_top_grid">
                <div class="misutech_home_footer_col">
                    <h3 class="misutech_home_footer_col_title">CHÍNH SÁCH VÀ ĐIỀU KHOẢN</h3>
                    <ul class="misutech_home_footer_col_list">
                        @if(isset($footerPolicyItems) && $footerPolicyItems->isNotEmpty())
                            @foreach($footerPolicyItems as $pItem)
                                <li><a href="{{ $pItem->url }}">{{ $pItem->title }}</a></li>
                            @endforeach
                        @else
                            <li><a href="/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/">Chính Sách Bảo Mật Dữ Liệu</a></li>
                            <li><a href="/chinh-sach-bao-mat-thong-tin-ca-nhan-information-privacy/">Chính Sách Bảo Mật Thông Tin Cá Nhân</a></li>
                            <li><a href="/chinh-sach-thanh-toan-an-toan-da-dang-cod-chuyen-khoan/">Chính Sách Thanh Toán An Toàn</a></li>
                            <li><a href="/chinh-sach-bao-hanh-thiet-bi-cong-nghiep-chinh-hang-warranty-policy/">Chính Sách Bảo Hành</a></li>
                            <li><a href="/chinh-sach-van-chuyen-nhanh-an-toan-giao-toan-quoc/">Chính Sách Vận Chuyển</a></li>
                            <li><a href="/chinh-sach-doi-tra-san-pham-return-refund-policy/">Chính Sách Đổi Trả Sản Phẩm</a></li>
                            <li><a href="/dieu-khoan-su-dung-website-quy-dinh-trach-nhiem/">Điều Khoản Sử Dụng Website</a></li>
                            <li><a href="/chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy/">Chính Sách Bảo Mật Thông Tin Khách Hàng</a></li>
                        @endif
                    </ul>
                </div>

                <div class="misutech_home_footer_col">
                    <h3 class="misutech_home_footer_col_title">THÔNG TIN &amp; CHUYÊN MỤC</h3>
                    <ul class="misutech_home_footer_col_list">
                        @if(isset($footerCompanyItems) && $footerCompanyItems->isNotEmpty())
                            @foreach($footerCompanyItems as $cItem)
                                <li><a href="{{ $cItem->url }}">{{ $cItem->title }}</a></li>
                            @endforeach
                        @else
                            <li><a href="/gioi-thieu/">Giới thiệu Hải Phòng Tech</a></li>
                            <li><a href="/tin-blog/">Tin Blog Tự Động Hóa</a></li>
                            <li><a href="/tu-dong-hoa/">Chuyên mục Tự động hóa</a></li>
                            <li><a href="/tai-lieu-ky-thuat/">Tài liệu kỹ thuật (Bài viết)</a></li>
                            <li><a href="/bang-gia-san-pham/">Bảng giá sản phẩm</a></li>
                            <li><a href="/huong-dan/">Hướng dẫn mua hàng</a></li>
                            <li><a href="/lien-he/">Liên hệ với chúng tôi</a></li>
                            <li><a href="/sitemap_index.xml">Sơ đồ trang web (Sitemap)</a></li>
                        @endif
                    </ul>
                </div>

                <div class="misutech_home_footer_col misutech_home_footer_col_hours">
                    <h3 class="misutech_home_footer_col_title">CHĂM SÓC KHÁCH HÀNG</h3>
                    <p class="misutech_home_footer_text">
                        <strong>{{ $companyName }}</strong><br>
                        Chuyên cung cấp PLC, HMI, Cảm biến, Biến tần và thiết bị tự động hóa công nghiệp chính hãng.
                    </p>
                    <p class="misutech_home_footer_text">
                        <strong>Giờ mở cửa (Opentime):</strong> {{ $settings->opening_hours ?? '8:00AM - 6:00PM' }}<br>
                        <strong>Tài khoản:</strong> <a href="/my-account/">Quản lý tài khoản</a> | <a href="/cart/">Giỏ hàng</a>
                    </p>
                </div>

                <div class="misutech_home_footer_col misutech_home_footer_col_contact">
                    <h3 class="misutech_home_footer_col_title">LIÊN HỆ</h3>
                    <div class="misutech_home_footer_contact_item">
                        <span class="misutech_home_footer_contact_icon">🏠</span>
                        <div>
                            <strong>ĐỊA CHỈ:</strong> {{ $addressText }}
                        </div>
                    </div>
                    <div class="misutech_home_footer_contact_item">
                        <span class="misutech_home_footer_contact_icon">📞</span>
                        <div>
                            <strong>GỌI NGAY:</strong> <a href="tel:{{ $hotlineRaw }}">{{ $hotlineDisplay }}</a>
                        </div>
                    </div>
                    <div class="misutech_home_footer_contact_item">
                        <span class="misutech_home_footer_contact_icon">✉</span>
                        <div>
                            <strong>EMAIL:</strong> <a href="mailto:{{ $emailDisplay }}">{{ $emailDisplay }}</a>
                        </div>
                    </div>
                    <div class="misutech_home_footer_socials">
                        <a href="{{ $fbHref }}" target="_blank" rel="noopener noreferrer" class="misutech_home_footer_social_btn" aria-label="Fanpage Facebook Hải Phòng Tech">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path fill="currentColor" d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z"/></svg>
                        </a>
                        <a href="{{ $zaloHref }}" target="_blank" rel="noopener noreferrer" class="misutech_home_footer_social_btn" aria-label="Zalo Hải Phòng Tech">
                            <span>Zalo</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="misutech_home_footer_bottom">
        <div class="misutech_home_container misutech_home_footer_bottom_wrap">
            <div class="misutech_home_footer_copy">
                <img width="36" height="36" src="{{ $faviconSmall }}" alt="{{ $brandName }}" loading="lazy" decoding="async">
                <span>{{ $settings->copyright ?? ('© ' . date('Y') . ' ' . $companyName . '. Bảo lưu mọi quyền.') }}</span>
            </div>
            <div class="misutech_home_footer_trust">
                @if(!empty($settings->dmca_url))
                    <a href="{{ $settings->dmca_url }}" target="_blank" rel="noopener noreferrer">DMCA Protected</a>
                @endif
                @if(!empty($settings->moit_gov_url))
                    &nbsp;|&nbsp;
                    <a href="{{ $settings->moit_gov_url }}" target="_blank" rel="noopener noreferrer">Đã thông báo Bộ Công Thương</a>
                @endif
            </div>
        </div>
    </div>
</footer>
