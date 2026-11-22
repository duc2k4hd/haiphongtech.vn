@extends('clients.layouts.master')

@php
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $contactTitle = isset($page) && $page?->meta_title
        ? $page->meta_title
        : ('Liên hệ - ' . $brandName);
    $contactDesc = isset($page) && $page?->meta_description !== null
        ? $page->meta_description
        : 'Email: kinhdoanhhpt@haiphongtech.vn';
    $contactRobots = isset($page) && $page?->meta_robots
        ? $page->meta_robots
        : 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
    $contactUrl = \App\Support\WpUrl::absolute('/lien-he/');
    $contactImage = isset($page) && !empty($page?->og_image)
        ? \App\Support\WpUrl::absolute($page->og_image)
        : \App\Support\WpUrl::absolute($settings->og_image ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp');
    $adaptedSchema = isset($page) ? \App\Support\WpUrl::adaptSchemaJson($page?->schema_json) : null;
    $hotlineRaw = preg_replace('/[^0-9]/', '', $settings->phone ?? ($settings->hotline ?? '0866555212'));
    $hotlineDisplay = $settings->hotline ?? '0866.555.212';
    $emailDisplay = $settings->email ?? 'kinhdoanhhpt@haiphongtech.vn';
    $addressDisplay = $settings->address ?? 'Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam';
@endphp

@section('title', $contactTitle)

@push('meta')
    @if($contactDesc !== '')
        <meta name="description" content="{{ $contactDesc }}">
    @endif
    <meta name="robots" content="{{ $contactRobots }}">
    <link rel="canonical" href="{{ $contactUrl }}">

    <meta property="og:locale" content="vi_VN">
    <meta property="og:type" content="{{ (isset($page) && $page?->og_type) ? $page->og_type : 'article' }}">
    <meta property="og:title" content="{{ (isset($page) && $page?->og_title) ? $page->og_title : $contactTitle }}">
    @if($contactDesc !== '')
        <meta property="og:description" content="{{ $contactDesc }}">
    @endif
    <meta property="og:url" content="{{ $contactUrl }}">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:image" content="{{ $contactImage }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $contactTitle }}">
    @if($contactDesc !== '')
        <meta name="twitter:description" content="{{ $contactDesc }}">
    @endif
    <meta name="twitter:image" content="{{ $contactImage }}">

    @if(!empty($adaptedSchema))
        <script type="application/ld+json">{!! $adaptedSchema !!}</script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/contact.css') }}?v={{ file_exists(public_path('clients/css/contact.css')) ? filemtime(public_path('clients/css/contact.css')) : '1.0' }}">
    <link rel="stylesheet" href="{{ asset('clients/css/wp-content.css') }}?v={{ file_exists(public_path('clients/css/wp-content.css')) ? filemtime(public_path('clients/css/wp-content.css')) : '1.0' }}">
@endpush

@section('content')
    <div class="misutech_contact_page">
        <nav class="misutech_contact_breadcrumb" aria-label="Breadcrumb">
            <div class="misutech_home_container">
                <ol class="misutech_contact_breadcrumb_list">
                    <li><a href="/">Trang chủ</a></li>
                    <li class="separator">»</li>
                    <li class="current" aria-current="page">{{ isset($page) && $page->title ? $page->title : 'Liên hệ' }}</li>
                </ol>
            </div>
        </nav>

        <div class="misutech_home_container">
            @php $contactBodyHasH1 = isset($page) && preg_match('/<h1\b/i', (string) $page->content); @endphp
            <div class="misutech_contact_header_card">
                <span class="contact_header_badge">{{ $companyName }}</span>
                @if($contactBodyHasH1)
                    <h2 class="contact_header_title">{{ isset($page) && $page->title ? $page->title : 'Liên hệ' }}</h2>
                @else
                    <h1 class="contact_header_title">{{ isset($page) && $page->title ? $page->title : 'Liên hệ' }}</h1>
                @endif
                <p class="contact_header_desc">
                    Quý khách hàng cần tư vấn kỹ thuật, yêu cầu báo giá thiết bị tự động hóa hoặc hỗ trợ giải pháp nhà máy? Vui lòng liên hệ với {{ $brandName }} qua thông tin dưới đây.
                </p>
            </div>

            @if(isset($page) && !empty($page->content))
                <div class="hpt_static_card hpt_contact_wp_intro">
                    <div class="hpt_wp_entry_content">
                        {!! $page->content !!}
                    </div>
                </div>
            @endif

            <div class="misutech_contact_grid">
                <div class="misutech_contact_info_box">
                    <h2 class="misutech_contact_info_title">THÔNG TIN LIÊN HỆ HẢI PHÒNG TECH</h2>

                    <div class="misutech_contact_list">
                        <div class="misutech_contact_item">
                            <div class="contact_item_icon">🏢</div>
                            <div class="contact_item_content">
                                <h4>Địa điểm ĐKKD</h4>
                                <p>{{ $addressDisplay }}</p>
                            </div>
                        </div>

                        <div class="misutech_contact_item">
                            <div class="contact_item_icon">📞</div>
                            <div class="contact_item_content">
                                <h4>Hotline Tư vấn &amp; Báo giá</h4>
                                <p><a href="tel:{{ $hotlineRaw }}">{{ $hotlineDisplay }}</a></p>
                            </div>
                        </div>

                        <div class="misutech_contact_item">
                            <div class="contact_item_icon">✉</div>
                            <div class="contact_item_content">
                                <h4>Email Liên hệ</h4>
                                <p>
                                    <a href="mailto:{{ $emailDisplay }}">{{ $emailDisplay }}</a>
                                </p>
                            </div>
                        </div>

                        <div class="misutech_contact_item">
                            <div class="contact_item_icon">⏰</div>
                            <div class="contact_item_content">
                                <h4>Giờ mở cửa</h4>
                                <p>{{ $settings->opening_hours ?? '8:00AM - 6:00PM' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="misutech_contact_form_box">
                    <h2 class="misutech_contact_form_title">GỬI YÊU CẦU TƯ VẤN / BÁO GIÁ</h2>
                    <p class="misutech_contact_form_subtitle">Điền thông tin yêu cầu của bạn, đội ngũ kỹ sư {{ $brandName }} sẽ phản hồi sớm nhất.</p>

                    @if(session('success'))
                        <div class="contact_alert contact_alert_success">
                            <span>✔</span>
                            <div>{{ session('success') }}</div>
                        </div>
                    @endif

                    @if(isset($errors) && $errors->any())
                        <div class="contact_alert contact_alert_error">
                            <span>✘</span>
                            <div>
                                @foreach($errors->all() as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <form action="{{ route('contact.submit') }}" method="POST" id="contactForm">
                        @csrf
                        <input type="hidden" name="_form_time_token" value="{{ $formToken ?? '' }}">

                        <div class="misutech_honeypot_field" hidden aria-hidden="true">
                            <label for="company_hp_url">Do not fill this field</label>
                            <input type="text" id="company_hp_url" name="company_hp_url" tabindex="-1" autocomplete="off" value="">
                        </div>

                        <div class="contact_form_row">
                            <div class="contact_form_group">
                                <label for="fullname">Họ và tên <span class="required">*</span></label>
                                <input type="text" id="fullname" name="fullname" class="contact_form_control" placeholder="Ví dụ: Nguyễn Văn An" value="{{ old('fullname') }}" required maxlength="80">
                            </div>

                            <div class="contact_form_group">
                                <label for="phone">Số điện thoại / Zalo <span class="required">*</span></label>
                                <input type="tel" id="phone" name="phone" class="contact_form_control" placeholder="Ví dụ: 0866555212" value="{{ old('phone') }}" required pattern="^(0|\+84)(3|5|7|8|9)[0-9]{8}$">
                            </div>
                        </div>

                        <div class="contact_form_row">
                            <div class="contact_form_group">
                                <label for="email">Địa chỉ Email (Tùy chọn)</label>
                                <input type="email" id="email" name="email" class="contact_form_control" placeholder="Ví dụ: example@company.com" value="{{ old('email') }}" maxlength="90">
                            </div>

                            <div class="contact_form_group">
                                <label for="subject">Chủ đề cần hỗ trợ</label>
                                <select id="subject" name="subject" class="contact_form_control">
                                    <option value="Báo giá thiết bị tự động hóa">Yêu cầu báo giá thiết bị</option>
                                    <option value="Tư vấn giải pháp kỹ thuật">Tư vấn giải pháp kỹ thuật / Lập trình</option>
                                    <option value="Hỗ trợ bảo hành / Sửa chữa">Hỗ trợ bảo hành / Sửa chữa</option>
                                    <option value="Hợp tác cung ứng dự án">Hợp tác cung ứng dự án</option>
                                </select>
                            </div>
                        </div>

                        <div class="contact_form_group">
                            <label for="message">Nội dung chi tiết yêu cầu <span class="required">*</span></label>
                            <textarea id="message" name="message" class="contact_form_control" rows="4" placeholder="Nhập mã sản phẩm, số lượng cần báo giá hoặc mô tả vấn đề kỹ thuật cần hỗ trợ..." required maxlength="1500">{{ old('message') }}</textarea>
                        </div>

                        <button type="submit" class="contact_submit_btn" id="submitBtn">
                            <span class="btn_text">Gửi Yêu Cầu Cho {{ $brandName }} ›</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
