@extends('clients.layouts.master')

@php
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $seoTitle = $page->meta_title ?: ($page->title !== '' ? ($page->title . ' - Hải Phòng Tech') : 'Hải Phòng Tech');
    $cleanMetaText = static fn (?string $txt): string => trim(preg_replace('/\[(?:yith_wcwl_wishlist|wpum_password_recovery|yith_wcan_filters)(?:\s[^\]]*)?\]/i', '', (string) $txt) ?? '');
    $seoDesc = $cleanMetaText($page->meta_description);
    $ogDesc = $cleanMetaText($page->og_description) ?: $seoDesc;
    $seoRobots = $page->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
    $isNoIndex = str_contains(strtolower($seoRobots), 'noindex');
    $canonicalHref = (!$isNoIndex && !empty($page->canonical_url))
        ? \App\Support\WpUrl::absolute($page->canonical_url)
        : (!$isNoIndex ? \App\Support\WpUrl::absolute($page->full_path) : '');
    $ogImage = !empty($page->og_image)
        ? \App\Support\WpUrl::absolute($page->og_image)
        : (\App\Support\WpUrl::absolute($settings->og_image ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp'));
    $adaptedSchema = \App\Support\WpUrl::adaptSchemaJson($page->schema_json);
    $contentHasH1 = (bool) preg_match('/<h1\b/i', (string) $page->content);
@endphp

@section('title', $seoTitle)

@push('meta')
    @if($seoDesc !== '')
        <meta name="description" content="{{ $seoDesc }}">
    @endif
    <meta name="robots" content="{{ $seoRobots }}">

    @if($canonicalHref !== '')
        <link rel="canonical" href="{{ $canonicalHref }}">
    @endif

    <meta property="og:locale" content="vi_VN">
    <meta property="og:type" content="{{ $page->og_type ?: 'article' }}">
    <meta property="og:title" content="{{ $page->og_title ?: $seoTitle }}">
    @if($ogDesc !== '')
        <meta property="og:description" content="{{ $ogDesc }}">
    @endif
    <meta property="og:url" content="{{ \App\Support\WpUrl::absolute($page->full_path) }}">
    <meta property="og:site_name" content="Hải Phòng Tech">
    <meta property="og:image" content="{{ $ogImage }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $page->og_title ?: $seoTitle }}">
    @if($seoDesc !== '')
        <meta name="twitter:description" content="{{ $seoDesc }}">
    @endif
    <meta name="twitter:image" content="{{ $ogImage }}">

    @if(!empty($adaptedSchema))
        <script type="application/ld+json">{!! $adaptedSchema !!}</script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/wp-content.css') }}?v={{ file_exists(public_path('clients/css/wp-content.css')) ? filemtime(public_path('clients/css/wp-content.css')) : '1.0' }}">
@endpush

@push('scripts')
    <script src="{{ asset('clients/js/wp-content.js') }}?v={{ file_exists(public_path('clients/js/wp-content.js')) ? filemtime(public_path('clients/js/wp-content.js')) : '1.0' }}" defer></script>
@endpush

@section('content')
    <div class="hpt_static_page_wrap">
        <nav class="hpt_breadcrumb_bar" aria-label="Breadcrumb">
            <div class="misutech_home_container">
                <ol class="hpt_breadcrumb_list">
                    <li><a href="/">Trang chủ</a></li>
                    @if($page->parent)
                        <li class="hpt_breadcrumb_sep">»</li>
                        <li><a href="{{ $page->parent->full_path }}">{{ $page->parent->title ?: $page->parent->slug }}</a></li>
                    @endif
                    <li class="hpt_breadcrumb_sep">»</li>
                    <li class="hpt_breadcrumb_current" aria-current="page">{{ $page->title !== '' ? $page->title : $seoTitle }}</li>
                </ol>
            </div>
        </nav>

        <div class="misutech_home_container">
            <article class="hpt_static_card">
                @if(!$contentHasH1 && $page->title !== '')
                    <header class="hpt_static_header">
                        <h1 class="hpt_static_page_title">{{ $page->title }}</h1>
                        @if($page->source_modified_at || $page->published_at)
                            <div class="hpt_static_meta">
                                Cập nhật: {{ ($page->source_modified_at ?: $page->published_at)->format('d/m/Y') }}
                            </div>
                        @endif
                    </header>
                @endif

                <div class="hpt_wp_entry_content">
                    @if(trim(strip_tags((string) $page->content)) === '' && $page->slug === 'tai-lieu-ki-thuat')
                        <p>Không có tài liệu kỹ thuật nào.</p>
                    @else
                        {!! $page->content !!}
                    @endif
                </div>
            </article>
        </div>
    </div>
@endsection
