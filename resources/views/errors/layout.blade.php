@extends('clients.layouts.master')

@push('meta')
    {{-- Chặn hoàn toàn bot tìm kiếm / crawl trên các trang lỗi --}}
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet">
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/errors.css') }}">
@endpush

@section('content')
    <div class="hpt_error_page">
        <div class="error_container">
            <div class="error_card">
                {{-- Error Code & Badge --}}
                <div class="error_header_badge @yield('badge_class', '')">
                    <span>@yield('badge_text', 'THÔNG BÁO HỆ THỐNG')</span>
                </div>

                <div class="error_code_display @yield('code_class', '')">
                    @yield('code', '404')
                </div>

                <h1 class="error_title">@yield('message_title', 'Không tìm thấy trang yêu cầu')</h1>
                <p class="error_desc">@yield('message_desc', 'Trang bạn đang truy cập có thể đã được đổi tên, chuyển sang đường dẫn mới hoặc tạm thời không khả dụng.')</p>

                {{-- Smart Search Bar to Guide Users --}}
                <div class="error_search_box">
                    <form action="/tin-blog/" method="GET" class="error_search_form">
                        <input type="text" name="q" class="error_search_input" placeholder="Tìm nhanh bài viết & tài liệu kỹ thuật..." required>
                        <button type="submit" class="error_search_btn">
                            <span>🔍 Tìm kiếm</span>
                        </button>
                    </form>
                </div>

                {{-- Key Action Buttons --}}
                <div class="error_actions">
                    <a href="/" class="btn_error_primary">
                        <span>🏠 Về Trang Chủ</span>
                    </a>
                    <a href="/gioi-thieu/" class="btn_error_secondary">
                        <span>🏢 Giới Thiệu</span>
                    </a>
                    <a href="/tin-blog/" class="btn_error_quote">
                        <span>📰 Tin Blog</span>
                    </a>
                    <a href="/tai-lieu-ki-thuat/" class="btn_error_secondary">
                        <span>📚 Tài Liệu Kĩ Thuật</span>
                    </a>
                    <a href="/lien-he/" class="btn_error_secondary">
                        <span>📞 Liên Hệ Hỗ Trợ</span>
                    </a>
                </div>

                {{-- Helpful Navigation Shortcuts --}}
                <div class="error_suggestions">
                    <h4 class="suggestions_header">
                        <span>⚡ CHUYÊN MỤC NỔI BẬT:</span>
                    </h4>
                    <div class="suggestions_grid">
                        <a href="/tu-dong-hoa/" class="suggestion_item">
                            <span class="suggestion_item_title">Tự Động Hóa</span>
                            <span class="suggestion_item_desc">Giải pháp & Thiết bị công nghiệp</span>
                        </a>
                        <a href="/tai-lieu-ky-thuat/" class="suggestion_item">
                            <span class="suggestion_item_title">Tài Liệu Kỹ Thuật</span>
                            <span class="suggestion_item_desc">Cẩm nang cài đặt & Hướng dẫn</span>
                        </a>
                        <a href="/bang-gia-san-pham/" class="suggestion_item">
                            <span class="suggestion_item_title">Bảng Giá Sản Phẩm</span>
                            <span class="suggestion_item_desc">Cập nhật báo giá thiết bị</span>
                        </a>
                        <a href="/thiet-bi-dien/" class="suggestion_item">
                            <span class="suggestion_item_title">Thiết Bị Điện</span>
                            <span class="suggestion_item_desc">PLC, HMI, Biến tần, Cảm biến</span>
                        </a>
                    </div>
                </div>

                {{-- Urgent Technical Support Bar --}}
                <div class="error_support_bar">
                    <div class="support_bar_text">
                        <strong>Cần hỗ trợ kỹ thuật hoặc báo giá khẩn cấp?</strong><br>
                        Đội ngũ kỹ sư tự động hóa Hải Phòng Tech luôn sẵn sàng hỗ trợ.
                    </div>
                    <div class="support_bar_actions">
                        <a href="tel:{{ $settings->hotline ?? ($settings->phone ?? '0866555212') }}" class="support_btn_call">
                            <span>📞 Gọi Hotline</span>
                        </a>
                        <a href="{{ $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212') }}" target="_blank" rel="noopener noreferrer" class="support_btn_zalo">
                            <span>💬 Chat Zalo Kỹ Sư</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
