@extends('clients.layouts.master')

@php
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $addressText = $settings->address ?? 'Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam';
    $hotlineDisplay = $settings->hotline ?? '0866.555.212';
    $hotlineRaw = preg_replace('/[^0-9]/', '', $settings->phone ?? ($settings->hotline ?? '0866555212'));
    $emailDisplay = $settings->email ?? 'kinhdoanhhpt@haiphongtech.vn';
    $openingHours = $settings->opening_hours ?? '8:00 - 18:00 (Thứ 2 - Thứ 7)';
    $zaloHref = $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212');

    $fields = [
        [
            'title' => 'Lắp đặt máy móc – thiết bị công nghiệp',
            'image' => asset('storage/clients/imgs/about/linh-vuc-1-lap-dat-may-moc.jpg'),
        ],
        [
            'title' => 'Xây lắp nhà xưởng – hệ thống điện',
            'image' => asset('storage/clients/imgs/about/linh-vuc-2-xay-lap-nha-xuong.jpg'),
        ],
        [
            'title' => 'Hệ thống tự động – điều khiển – giám sát',
            'image' => asset('storage/clients/imgs/about/linh-vuc-3-he-thong-tu-dong.jpg'),
        ],
        [
            'title' => 'Chế tạo máy tự động hóa trong công nghiệp',
            'image' => asset('storage/clients/imgs/about/linh-vuc-4-che-tao-may.jpg'),
        ],
        [
            'title' => 'Cung cấp vật tư điện công nghiệp, tự động hóa',
            'image' => asset('storage/clients/imgs/about/linh-vuc-5-vat-tu-dien.jpg'),
        ],
        [
            'title' => 'Lập trình PLC, Robot',
            'image' => asset('storage/clients/imgs/about/linh-vuc-6-lap-trinh-plc-robot.jpg'),
        ],
    ];

    $latestPosts = \App\Models\Post::where('status', 'published')
        ->latest('published_at')
        ->take(4)
        ->get();
@endphp

@section('title', 'Giới thiệu - ' . $companyName)
@section('meta_description', 'Về Hải Phòng Tech — Sứ mệnh, tầm nhìn, chiến lược và các lĩnh vực hoạt động trong ngành điện công nghiệp và tự động hóa tại Hải Phòng và toàn quốc.')

@push('styles')
<style>
    .hpt_about_page {
        background: #ffffff;
        color: #1e293b;
        font-family: 'Inter', sans-serif;
    }

    /* 1. Full-width Hero Banner */
    .hpt_about_banner {
        position: relative;
        min-height: 360px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        background-image: url("{{ asset('storage/clients/imgs/about/banner-about.png') }}");
        background-size: cover;
        background-position: 60% 55%;
        overflow: hidden;
    }
    .hpt_about_banner::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(14, 42, 74, 0.72), rgba(30, 115, 190, 0.56));
    }
    .hpt_about_banner_inner {
        position: relative;
        z-index: 1;
        padding: 48px 16px;
        max-width: 820px;
        margin: 0 auto;
    }
    .hpt_about_banner_breadcrumb {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: rgba(255, 255, 255, 0.88);
        margin-bottom: 12px;
    }
    .hpt_about_banner_breadcrumb a {
        color: #ffffff;
        font-weight: 600;
        text-decoration: none;
    }
    .hpt_about_banner_breadcrumb a:hover {
        text-decoration: underline;
    }
    .hpt_about_banner_title {
        margin: 0 0 12px;
        font-size: 36px;
        font-weight: 800;
        letter-spacing: 0.6px;
        color: #ffffff;
        text-transform: uppercase;
        line-height: 1.25;
    }
    .hpt_about_banner_sub {
        margin: 0;
        font-size: 15px;
        color: rgba(255, 255, 255, 0.94);
        line-height: 1.65;
    }

    /* 2. Pillars: Sứ mệnh - Tầm nhìn - Chiến lược */
    .hpt_about_pillars_sec {
        padding: 44px 0 48px;
        background: #ffffff;
    }
    .hpt_about_pillars_grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 24px;
    }
    .hpt_about_pillar_card {
        border: 1px solid #e2ebf3;
        border-top: 3px solid #1e73be;
        border-radius: 4px;
        background: #ffffff;
        padding: 28px 24px 26px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }
    .hpt_about_pillar_card:hover {
        box-shadow: 0 8px 24px rgba(30, 115, 190, 0.08);
        transform: translateY(-2px);
    }
    .hpt_about_pillar_icon {
        width: 72px;
        height: 72px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
    }
    .hpt_about_pillar_icon img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        display: block;
    }
    .hpt_about_pillar_title {
        margin: 0 0 12px;
        font-size: 18px;
        font-weight: 800;
        color: #1e73be;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .hpt_about_pillar_text {
        margin: 0;
        font-size: 14px;
        color: #334155;
        line-height: 1.7;
        text-align: justify;
    }
    .hpt_about_pillar_list {
        margin: 0;
        padding: 0;
        list-style: none;
        text-align: left;
        width: 100%;
        font-size: 14px;
        color: #334155;
        line-height: 1.7;
    }
    .hpt_about_pillar_list li {
        position: relative;
        padding-left: 16px;
        margin-bottom: 8px;
    }
    .hpt_about_pillar_list li:last-child {
        margin-bottom: 0;
    }
    .hpt_about_pillar_list li::before {
        content: "–";
        position: absolute;
        left: 0;
        top: 0;
        color: #1e73be;
        font-weight: 700;
    }

    /* 3. Lĩnh vực hoạt động */
    .hpt_about_fields_sec {
        padding: 52px 0 56px;
        background: #f5f8fb;
        border-top: 1px solid #e6eef5;
        border-bottom: 1px solid #e6eef5;
    }
    .hpt_about_sec_head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 24px;
        margin-bottom: 28px;
        flex-wrap: wrap;
    }
    .hpt_about_sec_head_text {
        max-width: 720px;
    }
    .hpt_about_sec_title {
        margin: 0 0 10px;
        font-size: 24px;
        font-weight: 800;
        color: #1e73be;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        position: relative;
        padding-bottom: 10px;
    }
    .hpt_about_sec_title::after {
        content: "";
        position: absolute;
        left: 0;
        bottom: 0;
        width: 56px;
        height: 3px;
        background: #1e73be;
    }
    .hpt_about_sec_desc {
        margin: 0;
        font-size: 14.5px;
        color: #334155;
        line-height: 1.7;
    }
    .hpt_about_fields_grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 22px;
    }
    .hpt_about_field_card {
        background: #ffffff;
        border: 1px solid #e2ebf3;
        border-radius: 4px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: box-shadow 0.2s ease, border-color 0.2s ease;
    }
    .hpt_about_field_card:hover {
        border-color: #1e73be;
        box-shadow: 0 8px 22px rgba(30, 115, 190, 0.1);
    }
    .hpt_about_field_img_wrap {
        width: 100%;
        aspect-ratio: 3 / 2;
        overflow: hidden;
        background: #e9f0f7;
    }
    .hpt_about_field_img_wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.35s ease;
    }
    .hpt_about_field_card:hover .hpt_about_field_img_wrap img {
        transform: scale(1.04);
    }
    .hpt_about_field_body {
        padding: 16px 18px;
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    .hpt_about_field_title {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        color: #111827;
        line-height: 1.45;
    }

    /* 4. Liên hệ & Năng lực cung ứng */
    .hpt_about_cta_sec {
        padding: 48px 0;
        background: #ffffff;
    }
    .hpt_about_cta_box {
        border: 1px solid #d9e6f2;
        border-left: 4px solid #1e73be;
        border-radius: 4px;
        background: #f8fbfe;
        padding: 28px 30px;
        display: grid;
        grid-template-columns: 1.25fr 0.75fr;
        gap: 28px;
        align-items: center;
    }
    .hpt_about_cta_title {
        margin: 0 0 10px;
        font-size: 20px;
        font-weight: 800;
        color: #111827;
    }
    .hpt_about_cta_list {
        margin: 0;
        padding: 0;
        list-style: none;
        font-size: 14px;
        color: #334155;
        line-height: 1.8;
    }
    .hpt_about_cta_list strong {
        color: #0f3d68;
    }
    .hpt_about_cta_list a {
        color: #1e73be;
        font-weight: 700;
        text-decoration: none;
    }
    .hpt_about_cta_list a:hover {
        text-decoration: underline;
    }
    .hpt_about_cta_actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .hpt_about_btn_primary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 11px 20px;
        border-radius: 4px;
        background: #1e73be;
        color: #ffffff !important;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
        transition: background 0.16s ease;
    }
    .hpt_about_btn_primary:hover {
        background: #165a96;
    }
    .hpt_about_btn_outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 20px;
        border-radius: 4px;
        border: 1px solid #1e73be;
        background: #ffffff;
        color: #1e73be !important;
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
        transition: background 0.16s ease;
    }
    .hpt_about_btn_outline:hover {
        background: #e8f2fb;
    }

    /* 5. Tin tức mới nhất */
    .hpt_about_news_sec {
        padding: 48px 0 56px;
        background: #f5f8fb;
        border-top: 1px solid #e6eef5;
    }
    .hpt_about_news_grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 20px;
    }
    .hpt_about_news_card {
        background: #ffffff;
        border: 1px solid #e2ebf3;
        border-radius: 4px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        text-decoration: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .hpt_about_news_card:hover {
        border-color: #1e73be;
        box-shadow: 0 6px 18px rgba(30, 115, 190, 0.08);
    }
    .hpt_about_news_thumb {
        width: 100%;
        aspect-ratio: 16 / 10;
        overflow: hidden;
        background: #e9f0f7;
    }
    .hpt_about_news_thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .hpt_about_news_body {
        padding: 14px 16px 16px;
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .hpt_about_news_title {
        margin: 0 0 8px;
        font-size: 14.5px;
        font-weight: 700;
        color: #111827;
        line-height: 1.45;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .hpt_about_news_card:hover .hpt_about_news_title {
        color: #1e73be;
    }
    .hpt_about_news_excerpt {
        margin: 0;
        font-size: 13px;
        color: #475569;
        line-height: 1.55;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    @media (max-width: 1024px) {
        .hpt_about_news_grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 900px) {
        .hpt_about_banner {
            min-height: 280px;
        }
        .hpt_about_banner_title {
            font-size: 28px;
        }
        .hpt_about_pillars_grid {
            grid-template-columns: 1fr;
        }
        .hpt_about_fields_grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .hpt_about_cta_box {
            grid-template-columns: 1fr;
            padding: 22px 20px;
        }
    }
    @media (max-width: 600px) {
        .hpt_about_banner {
            min-height: 220px;
        }
        .hpt_about_banner_title {
            font-size: 22px;
        }
        .hpt_about_banner_sub {
            font-size: 13.5px;
        }
        .hpt_about_sec_title {
            font-size: 20px;
        }
        .hpt_about_fields_grid,
        .hpt_about_news_grid {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

@section('content')
<div class="hpt_about_page">
    {{-- 1. Hero Banner --}}
    <section class="hpt_about_banner" aria-label="Về Hải Phòng Tech">
        <div class="hpt_about_banner_inner">
            <nav class="hpt_about_banner_breadcrumb" aria-label="Breadcrumb">
                <a href="/">Trang chủ</a>
                <span>›</span>
                <span>Giới thiệu</span>
            </nav>
            <h1 class="hpt_about_banner_title">VỀ HẢI PHÒNG TECH</h1>
            <p class="hpt_about_banner_sub">
                {{ $companyName }} — Giải pháp thiết bị điện công nghiệp &amp; tự động hóa tin cậy cho nhà máy và doanh nghiệp trên toàn quốc.
            </p>
        </div>
    </section>

    {{-- 2. Sứ mệnh - Tầm nhìn - Chiến lược --}}
    <section class="hpt_about_pillars_sec" aria-label="Sứ mệnh, tầm nhìn và chiến lược">
        <div class="misutech_home_container">
            <div class="hpt_about_pillars_grid">
                <article class="hpt_about_pillar_card">
                    <div class="hpt_about_pillar_icon">
                        <img src="{{ asset('storage/clients/imgs/about/icon-su-menh.png') }}" alt="Sứ mệnh Hải Phòng Tech" loading="lazy">
                    </div>
                    <h2 class="hpt_about_pillar_title">Sứ mệnh</h2>
                    <p class="hpt_about_pillar_text">
                        “Sứ mệnh của chúng tôi là trở thành một công ty trực tiếp sản xuất và phân phối những sản phẩm cho ngành công nghiệp điện năng và tự động hóa hàng đầu Việt Nam. Phục vụ tốt nhất, đem lại sự hài lòng cao nhất cho những khách hàng của mình.”
                    </p>
                </article>

                <article class="hpt_about_pillar_card">
                    <div class="hpt_about_pillar_icon">
                        <img src="{{ asset('storage/clients/imgs/about/icon-tam-nhin.avif') }}" alt="Tầm nhìn Hải Phòng Tech" loading="lazy">
                    </div>
                    <h2 class="hpt_about_pillar_title">Tầm nhìn</h2>
                    <p class="hpt_about_pillar_text">
                        Chúng tôi luôn lắng nghe, phân tích và thấu hiểu nhu cầu của từng khách hàng, từ đó áp dụng kinh nghiệm, kỹ năng, tư duy và sự sáng tạo của mình để giải quyết tối ưu những nhu cầu đó. Với thái độ, trách nhiệm làm việc và phục vụ cao nhất, chúng tôi cam kết sẽ đem lại sự hài lòng vượt mong đợi cho từng khách hàng.
                    </p>
                </article>

                <article class="hpt_about_pillar_card">
                    <div class="hpt_about_pillar_icon">
                        <img src="{{ asset('storage/clients/imgs/about/icon-chien-luoc.png') }}" alt="Chiến lược Hải Phòng Tech" loading="lazy">
                    </div>
                    <h2 class="hpt_about_pillar_title">Chiến lược</h2>
                    <ul class="hpt_about_pillar_list">
                        <li>Phát triển và quảng bá thương hiệu dịch vụ bán hàng, thiết kế và lắp đặt uy tín nhất khu vực miền Bắc.</li>
                        <li>Chuyên nghiệp hóa đội ngũ kinh doanh, tiếp thị, tư vấn, thiết kế và lắp đặt.</li>
                        <li>Chuẩn hóa hệ thống quản lý của Công ty.</li>
                    </ul>
                </article>
            </div>
        </div>
    </section>

    {{-- 3. Lĩnh vực hoạt động --}}
    <section class="hpt_about_fields_sec" aria-label="Lĩnh vực hoạt động">
        <div class="misutech_home_container">
            <div class="hpt_about_sec_head">
                <div class="hpt_about_sec_head_text">
                    <h2 class="hpt_about_sec_title">Lĩnh vực hoạt động</h2>
                    <p class="hpt_about_sec_desc">
                        Hải Phòng Tech sở hữu đội ngũ kỹ thuật viên giàu kinh nghiệm, luôn tận tâm cung cấp giải pháp tự động hóa hiệu quả. Chúng tôi cam kết chăm sóc khách hàng chu đáo, hỗ trợ kịp thời để đạt được sự phát triển bền vững.
                    </p>
                </div>
                <div>
                    <a href="{{ \App\Support\WpUrl::shopUrl() }}" class="hpt_about_btn_primary">Xem danh mục sản phẩm</a>
                </div>
            </div>

            <div class="hpt_about_fields_grid">
                @foreach($fields as $field)
                    <article class="hpt_about_field_card">
                        <div class="hpt_about_field_img_wrap">
                            <img src="{{ $field['image'] }}" alt="{{ $field['title'] }}" loading="lazy">
                        </div>
                        <div class="hpt_about_field_body">
                            <h3 class="hpt_about_field_title">{{ $field['title'] }}</h3>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- 4. Thông tin liên hệ & Tư vấn dự án --}}
    <section class="hpt_about_cta_sec" aria-label="Thông tin liên hệ">
        <div class="misutech_home_container">
            <div class="hpt_about_cta_box">
                <div>
                    <h2 class="hpt_about_cta_title">Kết nối cùng {{ $companyName }}</h2>
                    <ul class="hpt_about_cta_list">
                        <li><strong>Địa chỉ:</strong> {{ $addressText }}</li>
                        <li><strong>Hotline tư vấn:</strong> <a href="tel:{{ $hotlineRaw }}">{{ $hotlineDisplay }}</a></li>
                        <li><strong>Email:</strong> <a href="mailto:{{ $emailDisplay }}">{{ $emailDisplay }}</a></li>
                        <li><strong>Giờ làm việc:</strong> {{ $openingHours }}</li>
                    </ul>
                </div>
                <div class="hpt_about_cta_actions">
                    <a href="{{ route('quote.index') }}" class="hpt_about_btn_primary">Yêu cầu báo giá nhanh</a>
                    <a href="/lien-he/" class="hpt_about_btn_outline">Gửi thông tin liên hệ</a>
                    <a href="{{ $zaloHref }}" target="_blank" rel="noopener noreferrer" class="hpt_about_btn_outline">Chat Zalo kỹ sư</a>
                </div>
            </div>
        </div>
    </section>

    {{-- 5. Tin tức mới nhất (tự động hiển thị khi có bài viết) --}}
    @if($latestPosts->isNotEmpty())
        <section class="hpt_about_news_sec" aria-label="Tin tức mới nhất">
            <div class="misutech_home_container">
                <div class="hpt_about_sec_head">
                    <div class="hpt_about_sec_head_text">
                        <h2 class="hpt_about_sec_title">Tin tức &amp; Kiến thức tự động hóa</h2>
                        <p class="hpt_about_sec_desc">
                            Cập nhật bài viết chuyên ngành, hướng dẫn kỹ thuật và phần mềm tự động hóa mới nhất từ {{ $brandName }}.
                        </p>
                    </div>
                    <div>
                        <a href="/tu-dong-hoa/" class="hpt_about_btn_outline">Xem tất cả bài viết</a>
                    </div>
                </div>

                <div class="hpt_about_news_grid">
                    @foreach($latestPosts as $post)
                        @php
                            $thumb = $post->thumbnail
                                ? (str_starts_with($post->thumbnail, 'http') ? $post->thumbnail : asset('storage/' . ltrim($post->thumbnail, '/')))
                                : asset('storage/clients/imgs/about/banner-about.png');
                        @endphp
                        <a href="/{{ ltrim($post->slug, '/') }}/" class="hpt_about_news_card">
                            <div class="hpt_about_news_thumb">
                                <img src="{{ $thumb }}" alt="{{ $post->title }}" loading="lazy">
                            </div>
                            <div class="hpt_about_news_body">
                                <h3 class="hpt_about_news_title">{{ $post->title }}</h3>
                                @if(!empty($post->excerpt))
                                    <p class="hpt_about_news_excerpt">{{ \Illuminate\Support\Str::limit(strip_tags($post->excerpt), 110) }}</p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
