@extends('clients.layouts.master')

@php
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $homeTitle = $homePage?->meta_title ?: ($settings->meta_title ?? 'Hải Phòng Tech – Cung Cấp PLC, Cảm Biến, HMI Chính Hãng');
@endphp

@section('title', $homeTitle)

@push('meta')
    @php
        $siteTitle = $homeTitle;
        $siteDesc = $homePage?->meta_description ?: ($settings->meta_description ?? 'Hải Phòng Tech chuyên cung cấp thiết bị điện, tự động hóa tại Hải Phòng. Đầy đủ PLC, HMI, cảm biến, biến tần, relay chính hãng. Tư vấn kỹ thuật miễn phí, giá tốt nhất.');
        $siteRobots = $homePage?->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
        $siteUrl = \App\Support\WpUrl::absolute('/');
        $ogImageUrl = !empty($homePage?->og_image)
            ? \App\Support\WpUrl::absolute($homePage->og_image)
            : (\App\Support\WpUrl::absolute($settings->og_image ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp'));
        $logoUrl = \App\Support\WpUrl::absolute($settings->site_logo ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp');
        $hotline = $settings->hotline ?? ($settings->phone ?? '0866555212');
        $email = $settings->email ?? 'kinhdoanhhpt@haiphongtech.vn';
        $address = $settings->address ?? 'Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam';
        $firstHeroBanner = $banners->get(1, collect())->first();
        $firstHeroBannerUrl = $firstHeroBanner ? (Str::startsWith($firstHeroBanner->image, ['http://', 'https://', '/']) ? $firstHeroBanner->image : asset('storage/clients/imgs/banners/' . $firstHeroBanner->image)) : null;
        $adaptedHomeSchema = \App\Support\WpUrl::adaptSchemaJson($homePage?->schema_json);
    @endphp

    @if($firstHeroBannerUrl)
        <link rel="preload" as="image" href="{{ $firstHeroBannerUrl }}" fetchpriority="high">
    @endif

    <link rel="canonical" href="{{ $siteUrl }}">
    <meta name="robots" content="{{ $siteRobots }}">
    <meta name="description" content="{{ $siteDesc }}">

    <meta property="og:title" content="{{ $homePage?->og_title ?: $siteTitle }}">
    <meta property="og:description" content="{{ $homePage?->og_description ?: $siteDesc }}">
    <meta property="og:url" content="{{ $siteUrl }}">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:image:alt" content="{{ $brandName }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:locale" content="vi_VN">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $homePage?->og_title ?: $siteTitle }}">
    <meta name="twitter:description" content="{{ $siteDesc }}">
    <meta name="twitter:image" content="{{ $ogImageUrl }}">

    @if(!empty($adaptedHomeSchema))
        <script type="application/ld+json">{!! $adaptedHomeSchema !!}</script>
    @else
        @php
            $fallbackSchema = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'Organization',
                        '@id' => $siteUrl . '#organization',
                        'name' => $companyName,
                        'url' => $siteUrl,
                        'logo' => $logoUrl,
                        'email' => $email,
                        'telephone' => $hotline,
                    ],
                    [
                        '@type' => 'WebSite',
                        '@id' => $siteUrl . '#website',
                        'url' => $siteUrl,
                        'name' => $brandName,
                    ],
                ],
            ];
        @endphp
        <script type="application/ld+json">{!! json_encode($fallbackSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/home.css') }}?v={{ file_exists(public_path('clients/css/home.css')) ? filemtime(public_path('clients/css/home.css')) : '1.0' }}">
@endpush

@push('scripts')
    <script src="{{ asset('clients/js/home.js') }}?v={{ file_exists(public_path('clients/js/home.js')) ? filemtime(public_path('clients/js/home.js')) : '1.0' }}" defer></script>
@endpush

@section('content')
    <section class="misutech_home_hero">
        <div class="misutech_home_container misutech_home_hero_grid">
            <ul class="misutech_home_category_menu">
                @foreach ($mainCategories as $category)
                    <li class="misutech_home_category_item">
                        <a href="{{ \App\Support\WpUrl::categoryUrl($category->slug) }}" class="misutech_home_category_label">
                            <span class="misutech_home_category_name_text">{{ $category->name }}</span>
                            @if ($category->children->count() > 0)
                                <span class="misutech_home_category_arrow_icon">›</span>
                            @endif
                        </a>

                        @if ($category->children->count() > 0)
                            <div class="misutech_home_mega_menu">
                                <div class="misutech_home_mega_grid">
                                    @foreach ($category->children as $child)
                                        <div class="misutech_home_mega_column {{ $child->children->count() > 0 ? 'has-subitems' : 'no-subitems' }}">
                                            <h4 class="misutech_home_mega_title">
                                                <a href="{{ \App\Support\WpUrl::categoryUrl($child->slug) }}">
                                                    <span class="misutech_mega_title_text">{{ $child->name }}</span>
                                                    <span class="misutech_mega_title_arrow">›</span>
                                                </a>
                                            </h4>
                                            @if ($child->children->count() > 0)
                                                <ul class="misutech_home_mega_list">
                                                    @foreach ($child->children as $grandchild)
                                                        <li><a href="{{ \App\Support\WpUrl::categoryUrl($grandchild->slug) }}">{{ $grandchild->name }}</a></li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach

                <li class="misutech_home_category_item">
                    <a href="/thiet-bi-dien/" class="misutech_home_category_label misutech_home_category_all_link">
                        <span class="misutech_home_category_name_text">+ Thiết bị điện &amp; Tự động hóa</span>
                        <span class="misutech_home_category_arrow_icon">›</span>
                    </a>

                    <div class="misutech_home_mega_menu">
                        <div class="misutech_home_mega_grid">
                            @foreach ($allCategories as $allCat)
                                <div class="misutech_home_mega_column {{ $allCat->children->count() > 0 ? 'has-subitems' : 'no-subitems' }}">
                                    <h4 class="misutech_home_mega_title">
                                        <a href="{{ \App\Support\WpUrl::categoryUrl($allCat->slug) }}">
                                            <span class="misutech_mega_title_text">{{ $allCat->name }}</span>
                                            <span class="misutech_mega_title_arrow">›</span>
                                        </a>
                                    </h4>
                                    @if ($allCat->children->count() > 0)
                                        <ul class="misutech_home_mega_list">
                                            @foreach ($allCat->children as $child)
                                                <li><a href="{{ \App\Support\WpUrl::categoryUrl($child->slug) }}">{{ $child->name }}</a></li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </li>
            </ul>

            <div class="misutech_home_hero_center">
                <div class="misutech_home_hero_main" style="position: relative; overflow: hidden;">
                    @foreach ($banners->get(1, collect()) as $index => $banner)
                        <div class="misutech_home_hero_slide"
                            style="opacity: {{ $index === 0 ? '1' : '0' }}; transition: opacity 0.6s ease-in-out, transform 0.6s ease-in-out; transform: {{ $index === 0 ? 'scale(1)' : 'scale(1.05)' }}; pointer-events: {{ $index === 0 ? 'auto' : 'none' }}; z-index: {{ $index === 0 ? '1' : '0' }}; width: 100%; height: 100%; position: absolute; top: 0; left: 0;">
                            <img src="{{ Str::startsWith($banner->image, ['http://', 'https://', '/']) ? $banner->image : asset('storage/clients/imgs/banners/' . $banner->image) }}"
                                alt="{{ $banner->title }}"
                                width="790"
                                height="380"
                                @if($index === 0) fetchpriority="high" loading="eager" decoding="async" @else loading="lazy" decoding="async" @endif
                                style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;" />
                            <div class="misutech_home_hero_overlay">
                                <div class="misutech_home_hero_copy">
                                    <p class="misutech_home_hero_eyebrow">
                                        {{ $banner->subtitle }}
                                    </p>
                                    <h1 class="misutech_home_hero_title">
                                        {!! nl2br(e($banner->title)) !!}
                                    </h1>
                                    <p class="misutech_home_hero_desc">
                                        {{ $banner->description }}
                                    </p>
                                    <a class="misutech_home_primary_button"
                                        href="{{ $banner->link ?? '#' }}">{{ $banner->button_text ?? 'MUA NGAY' }}</a>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <div class="misutech_home_hero_dots" aria-label="Chọn banner"
                        style="position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 10;">
                        @foreach ($banners->get(1, collect()) as $index => $banner)
                            <button class="misutech_home_hero_dot" type="button"
                                aria-current="{{ $index === 0 ? 'true' : 'false' }}"
                                aria-label="Banner {{ $index + 1 }}"></button>
                        @endforeach
                    </div>
                </div>
                <div class="misutech_home_hero_strip">
                    <svg class="misutech_home_sprite" viewBox="94 96 198 31" role="img"
                        aria-label="Dải chương trình ưu đãi">
                        <use href="#misutech_home_sprite_source"></use>
                    </svg>
                </div>
            </div>

            <aside class="misutech_home_hero_side" aria-label="Khuyến mại nổi bật">
                @php $sideBanner1 = $banners->get(2, collect())->first(); @endphp
                @if ($sideBanner1)
                    <a class="misutech_home_side_banner" href="{{ $sideBanner1->link ?? '#' }}"
                        style="position: relative; border-radius: 4px; overflow: hidden; display: block;">
                        <img src="{{ Str::startsWith($sideBanner1->image, ['http://', 'https://']) ? $sideBanner1->image : asset('storage/clients/imgs/banners/' . $sideBanner1->image) }}"
                            alt="{{ $sideBanner1->title }}"
                            width="290"
                            height="185"
                            loading="lazy"
                            decoding="async"
                            style="width: 100%; height: 100%; object-fit: cover;" />
                        <span class="misutech_home_side_banner_label">{{ $sideBanner1->title }}</span>
                    </a>
                @endif

                @php $sideBanner2 = $banners->get(3, collect())->first(); @endphp
                @if ($sideBanner2)
                    <a class="misutech_home_side_banner" href="{{ $sideBanner2->link ?? '#' }}"
                        style="position: relative; border-radius: 4px; overflow: hidden; display: block;">
                        <img src="{{ Str::startsWith($sideBanner2->image, ['http://', 'https://']) ? $sideBanner2->image : asset('storage/clients/imgs/banners/' . $sideBanner2->image) }}"
                            alt="{{ $sideBanner2->title }}"
                            width="290"
                            height="185"
                            loading="lazy"
                            decoding="async"
                            style="width: 100%; height: 100%; object-fit: cover;" />
                        <span class="misutech_home_side_banner_label">{{ $sideBanner2->title }}</span>
                    </a>
                @endif
            </aside>
        </div>
    </section>

    @foreach ($categorySections as $index => $section)
        <section class="misutech_home_catalog_section" id="misutech_home_catalog_{{ $index + 1 }}">
            <div class="misutech_home_container">
                <header class="misutech_home_section_header">
                    <h2 class="misutech_home_section_title">
                        <span class="misutech_home_section_title_mark"></span>{{ $section->category->name }}
                    </h2>
                    <div class="misutech_home_section_tabs" role="tablist"
                        aria-label="Bộ lọc {{ $section->category->name }}">
                        <a class="misutech_home_view_all"
                            href="{{ \App\Support\WpUrl::categoryUrl($section->category->slug ?? $section->category->id) }}">Xem
                            tất cả ›</a>
                    </div>
                </header>
                <div class="misutech_home_catalog_grid">
                    @foreach ($section->products as $product)
                        <article class="misutech_home_product_card" data-product-name="{{ strtolower($product->name) }}">
                            <button class="misutech_home_product_favorite" type="button"
                                aria-label="Thêm {{ $product->name }} vào yêu thích">
                                ♡
                            </button>
                            @if ($product->sale_price && $product->price > 0)
                                <span
                                    class="misutech_home_product_badge">-{{ round((($product->price - $product->sale_price) / $product->price) * 100) }}%</span>
                            @endif
                            <div class="misutech_home_product_image">
                                <a href="{{ \App\Support\WpUrl::productUrl($product->slug) }}">
                                    <img src="{{ $product->thumbnailMedia->first()?->url ?? asset('storage/clients/imgs/products/no-image.png') }}"
                                        alt="{{ $product->name ?? 'Sản phẩm' }}"
                                        width="260"
                                        height="260"
                                        loading="lazy"
                                        decoding="async" />
                                </a>
                            </div>
                            <h3 class="misutech_home_product_name">
                                <a href="{{ \App\Support\WpUrl::productUrl($product->slug) }}"
                                    style="color:inherit;text-decoration:none;">{{ $product->name }}</a>
                            </h3>
                            <div class="misutech_home_product_price_row">
                                <div>
                                    <strong
                                        class="misutech_home_product_price">{{ number_format($product->sale_price ?? $product->price, 0, ',', '.') }}
                                        VNĐ</strong>
                                    @if ($product->sale_price)
                                        <del class="misutech_home_product_old_price">{{ number_format($product->price, 0, ',', '.') }}
                                            VNĐ</del>
                                    @endif
                                </div>
                                <button class="misutech_home_add_cart" type="button" data-cart
                                    data-product-id="{{ $product->id }}"
                                    data-product="{{ $product->name }}"
                                    aria-label="Thêm {{ $product->name }} vào giỏ hàng">
                                    +
                                </button>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    @if(isset($partnerBrands) && $partnerBrands->isNotEmpty())
    <section class="misutech_home_partners" aria-labelledby="misutech_home_partners_title">
        <div class="misutech_home_container">
            <header class="misutech_home_partners_header">
                <h2 class="misutech_home_partners_title" id="misutech_home_partners_title">
                    CÁC ĐỐI TÁC CỦA CHÚNG TÔI
                </h2>
                <span class="misutech_home_partners_divider" aria-hidden="true"></span>
            </header>

            <div class="misutech_home_partners_slider" data-partners-slider>
                <button class="misutech_home_partners_nav misutech_home_partners_prev" type="button"
                    aria-label="Xem đối tác trước" data-partners-prev>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>

                <div class="misutech_home_partners_viewport" data-partners-viewport>
                    <div class="misutech_home_partners_track" data-partners-track>
                        @foreach ($partnerBrands->chunk(12) as $pageIndex => $brandChunk)
                            <div class="misutech_home_partners_page" data-partners-page="{{ $pageIndex }}">
                                @foreach ($brandChunk as $brand)
                                    @php
                                        $brandLogoUrl = null;
                                        if (!empty($brand->logo)) {
                                            $brandLogoUrl = Str::startsWith($brand->logo, ['http://', 'https://', '/'])
                                                ? $brand->logo
                                                : asset('storage/clients/imgs/brands/' . ltrim($brand->logo, '/'));
                                        }
                                    @endphp
                                    <a href="{{ \App\Support\WpUrl::brandUrl($brand->slug) }}"
                                        class="misutech_home_partner_card"
                                        title="Thiết bị {{ $brand->name }} chính hãng">
                                        @if ($brandLogoUrl)
                                            <img src="{{ $brandLogoUrl }}"
                                                alt="{{ $brand->name }}"
                                                width="140"
                                                height="56"
                                                loading="lazy"
                                                decoding="async"
                                                class="misutech_home_partner_logo">
                                        @else
                                            <span class="misutech_home_partner_wordmark">
                                                <span class="misutech_home_partner_name">{{ $brand->name }}</span>
                                                <span class="misutech_home_partner_tag">CHÍNH HÃNG</span>
                                            </span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                <button class="misutech_home_partners_nav misutech_home_partners_next" type="button"
                    aria-label="Xem đối tác tiếp theo" data-partners-next>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>

            <div class="misutech_home_partners_footer">
                @if ($partnerBrands->count() > 12)
                    <div class="misutech_home_partners_dots" data-partners-dots aria-label="Chọn trang đối tác">
                        @foreach ($partnerBrands->chunk(12) as $dotIndex => $chunk)
                            <button type="button"
                                class="misutech_home_partners_dot"
                                data-partners-dot="{{ $dotIndex }}"
                                aria-current="{{ $dotIndex === 0 ? 'true' : 'false' }}"
                                aria-label="Trang đối tác {{ $dotIndex + 1 }}"></button>
                        @endforeach
                    </div>
                @endif
                <a class="misutech_home_partners_all_link" href="{{ route('brands.index') }}">
                    Xem tất cả {{ $partnerBrands->count() }} thương hiệu đối tác ›
                </a>
            </div>
        </div>
    </section>
    @endif

    @if(isset($blogSections) && $blogSections->isNotEmpty())
        <section class="misutech_home_content_area" aria-label="Tin tức và cẩm nang kỹ thuật">
            <div class="misutech_home_container misutech_home_content_grid">
                @foreach($blogSections as $blogCat)
                    <div class="misutech_home_content_column">
                        <h2 class="misutech_home_content_heading">
                            <span>{{ $blogCat->name }}</span>
                            <a class="misutech_home_content_more" href="/{{ $blogCat->slug }}/">Xem thêm ›</a>
                        </h2>
                        <div class="misutech_home_article_list">
                            @foreach($blogCat->posts as $post)
                                @php
                                    $pThumb = $post->thumbnailMedia->first();
                                    $pThumbUrl = $pThumb ? $pThumb->url : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
                                @endphp
                                <article class="misutech_home_article">
                                    <a href="/{{ $post->slug }}/" class="misutech_home_article_image" title="{{ $post->title }}">
                                        <img src="{{ $pThumbUrl }}" alt="{{ $post->title }}" width="120" height="80" loading="lazy" decoding="async" style="width: 100%; height: 100%; object-fit: cover; aspect-ratio: 3/2;">
                                    </a>
                                    <div class="misutech_home_article_body">
                                        <h3 class="misutech_home_article_title">
                                            <a href="/{{ $post->slug }}/" title="{{ $post->title }}">
                                                {{ $post->title }}
                                            </a>
                                        </h3>
                                        @if($post->summary)
                                            <p class="misutech_home_article_excerpt">
                                                {{ Str::limit($post->summary, 85) }}
                                            </p>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="misutech_home_service_bar" aria-label="Cam kết dịch vụ">
        <div class="misutech_home_container misutech_home_service_grid">
            <div class="misutech_home_service">
                <span class="misutech_home_service_icon">▣</span><span class="misutech_home_service_copy"><strong>Giao
                        hàng toàn quốc</strong><span>Đóng gói cẩn thận, theo dõi dễ dàng</span></span>
            </div>
            <div class="misutech_home_service">
                <span class="misutech_home_service_icon">↺</span><span class="misutech_home_service_copy"><strong>Đổi
                        trả linh hoạt</strong><span>Hỗ trợ nhanh trong thời hạn quy định</span></span>
            </div>
            <div class="misutech_home_service">
                <span class="misutech_home_service_icon">✓</span><span class="misutech_home_service_copy"><strong>Sản
                        phẩm chính hãng</strong><span>Nguồn gốc rõ ràng, bảo hành an tâm</span></span>
            </div>
            <div class="misutech_home_service">
                <span class="misutech_home_service_icon">☎</span><span class="misutech_home_service_copy"><strong>Hỗ trợ
                        tận tâm</strong><span>Tư vấn lựa chọn sản phẩm phù hợp</span></span>
            </div>
        </div>
    </section>
@endsection

