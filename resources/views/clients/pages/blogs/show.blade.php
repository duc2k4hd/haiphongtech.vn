@extends('clients.layouts.master')

@php
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $finalPostTitle = $post->meta_title ?: ($post->title . ' - ' . $brandName);
    $pageDesc = $post->meta_description ?: ($post->summary ?: Str::limit(trim(strip_tags((string) $post->content)), 160));
    $postRobots = $post->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
    $isNoIndex = str_contains(strtolower($postRobots), 'noindex');
    $postPath = '/' . trim($post->slug, '/') . '/';
    $postCanonical = !$isNoIndex
        ? \App\Support\WpUrl::absolute($post->canonical_url ?: $postPath)
        : '';
    $thumb = $post->thumbnailMedia->first();
    $thumbUrl = !empty($post->og_image)
        ? \App\Support\WpUrl::absolute($post->og_image)
        : ($thumb ? \App\Support\WpUrl::absolute($thumb->url) : \App\Support\WpUrl::absolute($settings->og_image ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp'));
    $authorName = $post->author_display_name ?: ($post->author->name ?? $brandName);
    $publishedTime = $post->published_at ? $post->published_at->toIso8601String() : optional($post->created_at)->toIso8601String();
    $modifiedTime = ($post->source_modified_at ?: $post->updated_at ?: $post->published_at)?->toIso8601String() ?? $publishedTime;
    $adaptedSchema = \App\Support\WpUrl::adaptSchemaJson($post->schema_json);
    $hotlineRaw = preg_replace('/[^0-9]/', '', $settings->phone ?? ($settings->hotline ?? '0866555212'));
    $hotlineDisplay = $settings->hotline ?? '0866.555.212';
@endphp

@section('title', $finalPostTitle)

@push('meta')
    @if($pageDesc !== '')
        <meta name="description" content="{{ $pageDesc }}">
    @endif
    <meta name="robots" content="{{ $postRobots }}">

    @if($postCanonical !== '')
        <link rel="canonical" href="{{ $postCanonical }}">
    @endif

    <meta property="og:locale" content="vi_VN">
    <meta property="og:type" content="{{ $post->og_type ?: 'article' }}">
    <meta property="og:title" content="{{ $post->og_title ?: $finalPostTitle }}">
    @if($pageDesc !== '' || !empty($post->og_description))
        <meta property="og:description" content="{{ $post->og_description ?: $pageDesc }}">
    @endif
    <meta property="og:url" content="{{ \App\Support\WpUrl::absolute($postPath) }}">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:image" content="{{ $thumbUrl }}">
    <meta property="og:image:alt" content="{{ $post->title }}">
    @if($publishedTime)
        <meta property="article:published_time" content="{{ $publishedTime }}">
    @endif
    @if($modifiedTime)
        <meta property="article:modified_time" content="{{ $modifiedTime }}">
    @endif
    @if($post->category)
        <meta property="article:section" content="{{ $post->category->name }}">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $post->og_title ?: $finalPostTitle }}">
    @if($pageDesc !== '')
        <meta name="twitter:description" content="{{ $pageDesc }}">
    @endif
    <meta name="twitter:image" content="{{ $thumbUrl }}">

    @if(!empty($adaptedSchema))
        <script type="application/ld+json">{!! $adaptedSchema !!}</script>
    @else
        @php
            $fallbackPostSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $post->title,
                'description' => $pageDesc,
                'url' => \App\Support\WpUrl::absolute($postPath),
                'datePublished' => $publishedTime,
                'dateModified' => $modifiedTime,
                'author' => ['@type' => 'Person', 'name' => $authorName],
                'publisher' => ['@type' => 'Organization', 'name' => $companyName],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($fallbackPostSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/blogs.css') }}?v={{ file_exists(public_path('clients/css/blogs.css')) ? filemtime(public_path('clients/css/blogs.css')) : '1.0' }}">
    <link rel="stylesheet" href="{{ asset('clients/css/wp-content.css') }}?v={{ file_exists(public_path('clients/css/wp-content.css')) ? filemtime(public_path('clients/css/wp-content.css')) : '1.0' }}">
@endpush

@push('scripts')
    <script src="{{ asset('clients/js/wp-content.js') }}?v={{ file_exists(public_path('clients/js/wp-content.js')) ? filemtime(public_path('clients/js/wp-content.js')) : '1.0' }}" defer></script>
@endpush

@section('content')
    <div class="misutech_blog_page">
        <nav class="misutech_blog_breadcrumb" aria-label="Breadcrumb">
            <div class="misutech_home_container">
                <ol class="misutech_blog_breadcrumb_list">
                    <li><a href="/">Trang chủ</a></li>
                    <li class="separator">»</li>
                    <li><a href="/tu-dong-hoa/">Tự động hóa</a></li>
                    @if($post->category)
                        <li class="separator">»</li>
                        <li><a href="/{{ $post->category->slug }}/">{{ $post->category->name }}</a></li>
                    @endif
                    <li class="separator">»</li>
                    <li class="current" aria-current="page">{{ Str::limit($post->title, 55) }}</li>
                </ol>
            </div>
        </nav>

        <div class="misutech_home_container misutech_blog_layout">
            <main class="misutech_blog_main">
                <article class="misutech_blog_detail_card">
                    <header class="blog_detail_header">
                        @if($post->categories && $post->categories->isNotEmpty())
                            @foreach($post->categories as $pCat)
                                <a href="/{{ $pCat->slug }}/" class="blog_detail_tag">{{ $pCat->name }}</a>
                            @endforeach
                        @elseif($post->category)
                            <a href="/{{ $post->category->slug }}/" class="blog_detail_tag">{{ $post->category->name }}</a>
                        @endif

                        <h1 class="blog_detail_title">{{ $post->title }}</h1>

                        <div class="blog_detail_meta">
                            <span class="meta_item author">
                                {{ $authorName }}
                            </span>
                            <span class="meta_dot">•</span>
                            <span class="meta_item date">
                                {{ $post->published_at ? $post->published_at->format('d/m/Y') : '' }}
                            </span>
                        </div>
                    </header>

                    @if($thumb)
                        <div class="blog_detail_featured_image">
                            <img src="{{ $thumb->url }}" alt="{{ $thumb->alt ?: $post->title }}" loading="lazy">
                        </div>
                    @endif

                    @php
                        $cleanedContent = (string) $post->content;
                        // Thay thế shortcode [hptech_products ...] bằng hộp giới thiệu sản phẩm liên quan đẹp mắt
                        $cleanedContent = preg_replace_callback('/\[hptech_products(?:\s+category="([^"]*)")?(?:\s+limit="(\d*)")?[^\]]*\]/i', function($matches) {
                            $catSlug = trim($matches[1] ?? '');
                            $shopUrl = $catSlug ? "/{$catSlug}/" : "/cua-hang/";
                            return '<div class="hpt_content_product_callout">
                                <div class="hpt_callout_icon">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                                </div>
                                <div class="hpt_callout_body">
                                    <div class="hpt_callout_title">Sản phẩm liên quan tại Hải Phòng Tech</div>
                                    <p class="hpt_callout_desc">Xem ngay các dòng thiết bị và phụ kiện công nghiệp có sẵn tại kho với giá ưu đãi & CO/CQ đầy đủ.</p>
                                </div>
                                <a href="' . e($shopUrl) . '" class="hpt_callout_btn">Xem sản phẩm →</a>
                            </div>';
                        }, $cleanedContent);

                        // Làm sạch các thẻ shortcode WordPress chưa render nếu có
                        $cleanedContent = preg_replace('/\[\/?(?:caption|gallery|audio|video)[^\]]*\]/i', '', $cleanedContent);
                    @endphp

                    <div class="misutech_blog_content hpt_wp_entry_content" id="blogContent">
                        {!! $cleanedContent !!}
                    </div>

                    @if($post->tags && $post->tags->isNotEmpty())
                        <div class="hpt_post_tags_bar" aria-label="Thẻ bài viết">
                            <span class="hpt_post_tags_label">Thẻ:</span>
                            @foreach($post->tags as $tg)
                                <a href="/tag/{{ $tg->slug }}/" class="hpt_post_tag_chip" rel="tag">{{ $tg->name }}</a>
                            @endforeach
                        </div>
                    @endif

                    <div class="blog_detail_share_bar">
                        <span class="share_lbl">Chia sẻ bài viết:</span>
                        <div class="share_buttons">
                            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(\App\Support\WpUrl::absolute($postPath)) }}" target="_blank" rel="noopener noreferrer" class="btn_share fb">
                                Facebook
                            </a>
                            <a href="https://zalo.me/share?url={{ urlencode(\App\Support\WpUrl::absolute($postPath)) }}" target="_blank" rel="noopener noreferrer" class="btn_share zalo">
                                Zalo
                            </a>
                        </div>
                    </div>
                </article>

                @if($relatedPosts->isNotEmpty())
                    <section class="misutech_related_posts_section">
                        <h3 class="related_section_title">Bài viết liên quan cùng chuyên mục</h3>
                        <div class="related_posts_grid">
                            @foreach($relatedPosts as $rel)
                                @php
                                    $relThumb = $rel->thumbnailMedia->first();
                                    $relThumbUrl = $relThumb ? $relThumb->url : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
                                    $relHref = '/' . trim($rel->slug, '/') . '/';
                                @endphp
                                <article class="related_post_card">
                                    <a href="{{ $relHref }}" class="rel_thumb">
                                        <img src="{{ $relThumbUrl }}" alt="{{ $rel->title }}" loading="lazy" decoding="async">
                                    </a>
                                    <div class="rel_info">
                                        <span class="rel_date">{{ $rel->published_at ? $rel->published_at->format('d/m/Y') : '' }}</span>
                                        <h4 class="rel_title">
                                            <a href="{{ $relHref }}" title="{{ $rel->title }}">{{ $rel->title }}</a>
                                        </h4>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif
            </main>

            <aside class="misutech_blog_sidebar">
                <div class="sidebar_widget">
                    <h3 class="widget_title">Tìm kiếm bài viết</h3>
                    <form action="/tu-dong-hoa/" method="GET" class="sidebar_search_form">
                        <input type="text" name="q" placeholder="Nhập từ khóa tìm kiếm...">
                        <button type="submit" aria-label="Tìm kiếm">🔍</button>
                    </form>
                </div>

                <div class="sidebar_widget">
                    <h3 class="widget_title">Chuyên mục bài viết</h3>
                    <ul class="sidebar_cat_list">
                        <li>
                            <a href="/tu-dong-hoa/"><span>Tất cả bài viết</span></a>
                        </li>
                        @foreach($categories as $cat)
                            <li>
                                <a href="/{{ $cat->slug }}/" class="{{ ($post->category && $post->category->id === $cat->id) ? 'active' : '' }}">
                                    <span>{{ $cat->name }}</span>
                                    <span class="cat_count">{{ $cat->posts_count }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if($recentPosts->isNotEmpty())
                    <div class="sidebar_widget">
                        <h3 class="widget_title">Bài viết mới nhất</h3>
                        <div class="sidebar_recent_list">
                            @foreach($recentPosts->take(5) as $rec)
                                @php
                                    $rThumb = $rec->thumbnailMedia->first();
                                    $rThumbUrl = $rThumb ? $rThumb->url : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
                                    $recHref = '/' . trim($rec->slug, '/') . '/';
                                @endphp
                                <article class="sidebar_recent_item">
                                    <a href="{{ $recHref }}" class="recent_thumb">
                                        <img src="{{ $rThumbUrl }}" alt="{{ $rec->title }}" loading="lazy" decoding="async">
                                    </a>
                                    <div class="recent_info">
                                        <h4 class="recent_title">
                                            <a href="{{ $recHref }}">{{ $rec->title }}</a>
                                        </h4>
                                        <span class="recent_date">{{ $rec->published_at ? $rec->published_at->format('d/m/Y') : '' }}</span>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="sidebar_widget sidebar_cta_box">
                    <span class="cta_icon">⚡</span>
                    <h4>Tư vấn giải pháp kỹ thuật</h4>
                    <p>Đội ngũ kỹ sư tự động hóa {{ $brandName }} sẵn sàng hỗ trợ kỹ thuật và giải pháp tối ưu cho nhà máy.</p>
                    <a href="tel:{{ $hotlineRaw }}" class="btn_sidebar_call">Hotline: {{ $hotlineDisplay }}</a>
                </div>
            </aside>
        </div>
    </div>
@endsection
