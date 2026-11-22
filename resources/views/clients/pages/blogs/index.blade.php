@extends('clients.layouts.master')

@php
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $blogTitle = $seoMeta['title'] ?? (
        isset($selectedTag) && $selectedTag
            ? ($selectedTag->name . ' Archives - ' . $brandName)
            : ($selectedCategory ? ($selectedCategory->name . ' - ' . $brandName) : ('Tin Blog - ' . $brandName))
    );
    $blogDesc = $seoMeta['description'] ?? '';
    $blogRobots = $seoMeta['robots'] ?? 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
    $blogCanonical = $seoMeta['canonical'] ?? '';
    $blogOgType = $seoMeta['og_type'] ?? 'website';
    $blogSchemaJson = $seoMeta['schema_json'] ?? null;
    $blogImage = \App\Support\WpUrl::absolute($settings->og_image ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp');
    $currentUrl = \App\Support\WpUrl::absolute(\App\Support\WpUrl::rawRequestPath(request()));
    $hotlineRaw = preg_replace('/[^0-9]/', '', $settings->phone ?? ($settings->hotline ?? '0866555212'));
    $hotlineDisplay = $settings->hotline ?? '0866.555.212';
@endphp

@section('title', $blogTitle)

@push('meta')
    @if($blogDesc !== '')
        <meta name="description" content="{{ $blogDesc }}">
    @endif
    <meta name="robots" content="{{ $blogRobots }}">

    @if($blogCanonical !== '')
        <link rel="canonical" href="{{ $blogCanonical }}">
    @endif

    <meta property="og:locale" content="vi_VN">
    <meta property="og:type" content="{{ $blogOgType }}">
    <meta property="og:title" content="{{ $blogTitle }}">
    @if($blogDesc !== '')
        <meta property="og:description" content="{{ $blogDesc }}">
    @endif
    <meta property="og:url" content="{{ $blogCanonical !== '' ? $blogCanonical : $currentUrl }}">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:image" content="{{ $blogImage }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $blogTitle }}">
    @if($blogDesc !== '')
        <meta name="twitter:description" content="{{ $blogDesc }}">
    @endif
    <meta name="twitter:image" content="{{ $blogImage }}">

    @if(!empty($blogSchemaJson))
        <script type="application/ld+json">{!! $blogSchemaJson !!}</script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/blogs.css') }}?v={{ file_exists(public_path('clients/css/blogs.css')) ? filemtime(public_path('clients/css/blogs.css')) : '1.0' }}">
@endpush

@section('content')
    <div class="misutech_blog_page">
        {{-- Breadcrumb --}}
        <nav class="misutech_blog_breadcrumb" aria-label="Breadcrumb">
            <div class="misutech_home_container">
                <ol class="misutech_blog_breadcrumb_list">
                    <li><a href="/">Trang chủ</a></li>
                    <li class="separator">»</li>
                    @if(isset($selectedTag) && $selectedTag)
                        <li><a href="/tin-blog/">Tin Blog</a></li>
                        <li class="separator">»</li>
                        <li class="current" aria-current="page">Thẻ: {{ $selectedTag->name }}</li>
                    @elseif($selectedCategory)
                        <li class="current" aria-current="page">{{ $selectedCategory->name }}</li>
                    @else
                        <li class="current" aria-current="page">Tin Blog</li>
                    @endif
                </ol>
            </div>
        </nav>

        <div class="misutech_home_container misutech_blog_layout">
            <main class="misutech_blog_main">
                <div class="misutech_blog_header_card">
                    <span class="blog_header_badge">{{ strtoupper($brandName) }} — KIẾN THỨC &amp; TỰ ĐỘNG HÓA</span>
                    <h1 class="blog_header_title">
                        @if(isset($selectedTag) && $selectedTag)
                            {{ $selectedTag->name }}
                        @elseif($selectedCategory)
                            {{ $selectedCategory->name }}
                        @elseif(!empty($search))
                            Kết quả tìm kiếm: "{{ $search }}"
                        @else
                            Tin Blog
                        @endif
                    </h1>
                    @if($selectedCategory && !empty($selectedCategory->description))
                        <p class="blog_header_desc">{{ $selectedCategory->description }}</p>
                    @elseif(isset($selectedTag) && $selectedTag && !empty($selectedTag->description))
                        <p class="blog_header_desc">{{ $selectedTag->description }}</p>
                    @else
                        <p class="blog_header_desc">
                            Tổng hợp kiến thức tự động hóa công nghiệp, tài liệu kỹ thuật, hướng dẫn lập trình PLC, HMI, Biến tần và Cảm biến chính hãng tại {{ $brandName }}.
                        </p>
                    @endif
                </div>

                @if($posts->isEmpty())
                    <div class="misutech_blog_empty">
                        <span class="empty_icon">📰</span>
                        <h3>Chưa có bài viết trong mục này</h3>
                        <p>Vui lòng thử lại với từ khóa khác hoặc xem danh sách tất cả bài viết.</p>
                        <a href="/tin-blog/" class="btn_reset_blog">Xem tất cả bài viết</a>
                    </div>
                @else
                    <div class="misutech_blog_grid">
                        @foreach($posts as $p)
                            @php
                                $thumb = $p->thumbnailMedia->first();
                                $thumbUrl = $thumb ? $thumb->url : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
                                $postHref = '/' . trim($p->slug, '/') . '/';
                            @endphp
                            <article class="misutech_blog_card">
                                <a href="{{ $postHref }}" class="blog_card_thumb">
                                    <img src="{{ $thumbUrl }}" alt="{{ $p->title }}" loading="lazy" decoding="async">
                                </a>
                                <div class="blog_card_body">
                                    <div class="blog_card_meta">
                                        @if($p->category)
                                            <a href="/{{ $p->category->slug }}/" class="blog_card_category">
                                                {{ $p->category->name }}
                                            </a>
                                        @endif
                                        <span class="blog_card_date">{{ $p->published_at ? $p->published_at->format('d/m/Y') : '' }}</span>
                                    </div>
                                    <h2 class="blog_card_title">
                                        <a href="{{ $postHref }}" title="{{ $p->title }}">
                                            {{ $p->title }}
                                        </a>
                                    </h2>
                                    @if($p->summary)
                                        <p class="blog_card_summary">
                                            {{ $p->summary }}
                                        </p>
                                    @endif
                                    <div class="blog_card_footer">
                                        <a href="{{ $postHref }}" class="btn_card_read">
                                            Chi tiết →
                                        </a>
                                        @if(!empty($p->author_display_name))
                                            <span class="blog_views_count">{{ $p->author_display_name }}</span>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @if($posts->hasPages())
                        <div class="misutech_blog_pagination">
                            {{ $posts->links('clients.components.pagination') }}
                        </div>
                    @endif
                @endif
            </main>

            <aside class="misutech_blog_sidebar">
                <div class="sidebar_widget">
                    <h3 class="widget_title">Tìm kiếm bài viết</h3>
                    <form action="/tin-blog/" method="GET" class="sidebar_search_form">
                        <input type="text" name="q" value="{{ $search }}" placeholder="Nhập từ khóa tìm kiếm...">
                        <button type="submit" aria-label="Tìm kiếm">🔍</button>
                    </form>
                </div>

                <div class="sidebar_widget">
                    <h3 class="widget_title">Chuyên mục bài viết</h3>
                    <ul class="sidebar_cat_list">
                        <li>
                            <a href="/tin-blog/" class="{{ empty($catSlug) && empty($selectedTag) ? 'active' : '' }}">
                                <span>Tất cả bài viết</span>
                            </a>
                        </li>
                        @foreach($categories as $cat)
                            <li>
                                <a href="/{{ $cat->slug }}/" class="{{ $catSlug === $cat->slug ? 'active' : '' }}">
                                    <span>{{ $cat->name }}</span>
                                    <span class="cat_count">{{ $cat->posts_count }}</span>
                                </a>
                                @if($cat->children && $cat->children->count() > 0)
                                    <ul class="sidebar_subcat_list">
                                        @foreach($cat->children as $sub)
                                            <li>
                                                <a href="/{{ $sub->slug }}/" class="{{ $catSlug === $sub->slug ? 'active' : '' }}">
                                                    <span>- {{ $sub->name }}</span>
                                                    <span class="cat_count">{{ $sub->posts_count }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if($popularPosts->isNotEmpty())
                    <div class="sidebar_widget">
                        <h3 class="widget_title">Bài viết nổi bật</h3>
                        <div class="sidebar_popular_list">
                            @foreach($popularPosts as $pop)
                                @php
                                    $pThumb = $pop->thumbnailMedia->first();
                                    $pThumbUrl = $pThumb ? $pThumb->url : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
                                    $popHref = '/' . trim($pop->slug, '/') . '/';
                                @endphp
                                <article class="sidebar_popular_item">
                                    <a href="{{ $popHref }}" class="pop_thumb">
                                        <img src="{{ $pThumbUrl }}" alt="{{ $pop->title }}" loading="lazy" decoding="async">
                                    </a>
                                    <div class="pop_info">
                                        <h4 class="pop_title">
                                            <a href="{{ $popHref }}">{{ $pop->title }}</a>
                                        </h4>
                                        <span class="pop_date">{{ $pop->published_at ? $pop->published_at->format('d/m/Y') : '' }}</span>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="sidebar_widget sidebar_cta_box">
                    <span class="cta_icon">⚡</span>
                    <h4>Tư vấn giải pháp kỹ thuật</h4>
                    <p>Đội ngũ kỹ sư tự động hóa {{ $brandName }} sẵn sàng tư vấn lựa chọn thiết bị chính hãng cho nhà máy.</p>
                    <a href="tel:{{ $hotlineRaw }}" class="btn_sidebar_call">Hotline: {{ $hotlineDisplay }}</a>
                </div>
            </aside>
        </div>
    </div>
@endsection
