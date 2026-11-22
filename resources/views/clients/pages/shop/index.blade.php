@extends('clients.layouts.master')

@php
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $shopTitle = $seoArchive['title'] ?? ('Shop - ' . $brandName);
    $shopUrl = $seoArchive['current_url'] ?? \App\Support\WpUrl::shopUrl();
    $shopCanonical = $seoArchive['canonical'] ?? '';
    $shopRobots = $seoArchive['robots'] ?? 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large';
    $shopDesc = $seoArchive['description'] ?? ('Khám phá tất cả các thiết bị tự động hóa công nghiệp chính hãng tại ' . $brandName . '.');
    $shopKeywords = 'cửa hàng tự động hóa, biến tần, PLC, cảm biến, HMI, servo, thiết bị điện công nghiệp, ' . $companyName;
    $shopImage = !empty($settings->og_image)
        ? \App\Support\WpUrl::absolute($settings->og_image)
        : asset('wp-content/uploads/2025/03/logo-hai-phong-tech.webp');
    $logoUrl = \App\Support\WpUrl::absolute($settings->site_logo ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp');
    $hotline = $settings->hotline ?? ($settings->phone ?? '0866555212');
    $email = $settings->email ?? 'kinhdoanhhpt@haiphongtech.vn';
    $fbUrl = $settings->facebook ?? ($settings->facebook_url ?? 'https://www.facebook.com/HaiPhongTech.vn');
    $zaloUrl = $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212');

    $pageH1 = $seoArchive['h1'] ?? 'Cửa hàng';
    $breadcrumbName = $seoArchive['breadcrumb_name'] ?? 'Cửa hàng';
    $introHtml = $seoArchive['intro_content'] ?? null;

    $itemList = [];
    if (isset($products) && $products->isNotEmpty()) {
        foreach ($products as $idx => $p) {
            $itemList[] = [
                '@type'    => 'ListItem',
                'position' => $idx + 1,
                'url'      => \App\Support\WpUrl::productUrl($p->slug),
                'name'     => $p->name,
            ];
        }
    }

    $adaptedSchema = $seoArchive['schema_json'] ?? null;
    $fallbackSchema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id'   => \App\Support\WpUrl::absolute('/') . '#organization',
                'name'  => $companyName,
                'url'   => \App\Support\WpUrl::absolute('/'),
                'logo'  => ['@type' => 'ImageObject', 'url' => $logoUrl],
                'email' => $email,
                'telephone' => $hotline,
                'sameAs' => array_values(array_filter([$fbUrl, $zaloUrl])),
            ],
            [
                '@type' => 'WebPage',
                '@id'   => $shopUrl . '#webpage',
                'url'   => $shopUrl,
                'name'  => $shopTitle,
                'inLanguage' => 'vi',
            ],
            [
                '@type' => 'ItemList',
                '@id'   => $shopUrl . '#itemlist',
                'url'   => $shopUrl,
                'name'  => $pageH1,
                'numberOfItems' => count($itemList),
                'itemListElement' => $itemList,
            ],
        ],
    ];
@endphp

@section('title', $shopTitle)

@push('meta')
    @if(!empty($shopCanonical) && !str_contains(strtolower($shopRobots), 'noindex'))
        <link rel="canonical" href="{{ $shopCanonical }}">
        <link rel="alternate" hreflang="vi" href="{{ $shopCanonical }}">
        <link rel="alternate" hreflang="x-default" href="{{ $shopCanonical }}">
    @endif

    <meta name="keywords" content="{{ $shopKeywords }}">
    @if($shopDesc !== '')
        <meta name="description" content="{{ $shopDesc }}">
    @endif
    <meta name="robots" content="{{ $shopRobots }}">

    <meta property="og:title" content="{{ $shopTitle }}">
    @if($shopDesc !== '')
        <meta property="og:description" content="{{ $shopDesc }}">
    @endif
    <meta property="og:url" content="{{ $shopCanonical ?: $shopUrl }}">
    <meta property="og:image" content="{{ $shopImage }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:locale" content="vi_VN">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $shopTitle }}">
    @if($shopDesc !== '')
        <meta name="twitter:description" content="{{ $shopDesc }}">
    @endif
    <meta name="twitter:image" content="{{ $shopImage }}">

    @if(!empty($adaptedSchema))
        <script type="application/ld+json">{!! $adaptedSchema !!}</script>
    @else
        <script type="application/ld+json">{!! json_encode($fallbackSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/category.css') }}?v={{ file_exists(public_path('clients/css/category.css')) ? filemtime(public_path('clients/css/category.css')) : time() }}">
@endpush

@push('scripts')
    <script src="{{ asset('clients/js/category.js') }}?v={{ file_exists(public_path('clients/js/category.js')) ? filemtime(public_path('clients/js/category.js')) : time() }}"></script>
@endpush

@section('content')
    <div class="hpt_catalog_header">
        <div class="misutech_catalog_container">
            <nav class="hpt_catalog_breadcrumb" aria-label="Breadcrumb">
                <a href="{{ \App\Support\WpUrl::absolute('/') }}">Trang chủ</a>
                <span>›</span>
                @if(isset($activeBrand) && $activeBrand || isset($activeProductTag) && $activeProductTag)
                    <a href="{{ \App\Support\WpUrl::shopUrl() }}">Cửa hàng</a>
                    <span>›</span>
                @endif
                <span>{{ $breadcrumbName }}</span>
            </nav>
            <h1 class="hpt_catalog_title">{{ $pageH1 }}</h1>
        </div>
    </div>

    <section class="misutech_home_catalog" id="misutech_home_products">
        <div class="misutech_catalog_container misutech_home_catalog_layout">
            @include('clients.components.product_filters')

            <div class="misutech_home_product_area">
                @include('clients.components.product_filter_bar')

                <div class="misutech_home_product_toolbar">
                    <label class="misutech_home_sort_label">Sắp xếp theo
                        <select class="misutech_home_sort_select" aria-label="Sắp xếp sản phẩm">
                            <option value="featured">Nổi bật</option>
                            <option value="price-asc">Giá, thấp đến cao</option>
                            <option value="price-desc">Giá, cao đến thấp</option>
                            <option value="name">Theo thứ tự A-Z</option>
                        </select>
                    </label>
                    <div class="misutech_home_view_modes" role="group" aria-label="Product view">
                        <button class="misutech_home_view_button" type="button" data-columns="4" aria-pressed="true"
                            aria-label="Lưới 4 cột hoặc 2 cột trên di động">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M1 1h6v6H1V1zm8 0h6v6H9V1zm-8 8h6v6H1V9zm8 0h6v6H9V9z"/>
                            </svg>
                        </button>
                        <button class="misutech_home_view_button" type="button" data-columns="3" aria-pressed="false"
                            aria-label="1 cột sản phẩm trên di động hoặc 3 cột trên máy tính">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M1 1h4.2v14H1V1zm4.9 0h4.2v14H5.9V1zm4.9 0H15v14h-4.2V1z"/>
                            </svg>
                        </button>
                        <button class="misutech_home_view_button" type="button" data-columns="1" aria-pressed="false"
                            aria-label="Dạng danh sách nằm ngang">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M1 2h14v2.5H1V2zm0 4.75h14v2.5H1v-2.5zm0 4.75h14V14H1v-2.5z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="misutech_home_product_grid" data-grid-columns="4">
                    @forelse($products as $product)
                        @include('clients.pages.shop.partials.product_card', ['product' => $product])
                    @empty
                        <div class="misutech_home_no_products">Không có sản phẩm nào.</div>
                    @endforelse
                </div>

                <div class="misutech_home_no_results" hidden>Không có sản phẩm nào phù hợp với bộ lọc.</div>

                @if(method_exists($products, 'hasPages') && $products->hasPages())
                    <div class="misutech_pagination_wrap" style="margin-top: 24px;">
                        {{ $products->links('clients.components.pagination') }}
                    </div>
                @endif

                <div class="misutech_home_load_area" @if ($totalProducts == 0) style="display: none;" @endif>
                    @php
                        $shownUpTo = method_exists($products, 'currentPage')
                            ? min($totalProducts, (($products->currentPage() - 1) * $products->perPage()) + $products->count())
                            : $products->count();
                    @endphp
                    <p class="misutech_home_result_count">Hiển thị <strong
                            id="visible-count">{{ $shownUpTo }}</strong> trên <span
                            id="total-count">{{ $totalProducts }}</span> sản phẩm.</p>
                    <div class="misutech_home_progress"><span class="misutech_home_progress_value" id="progress-bar"
                            style="width: {{ $totalProducts > 0 ? ($shownUpTo / $totalProducts) * 100 : 0 }}%;"></span>
                    </div>
                    @if ($shownUpTo < $totalProducts)
                        <button class="misutech_home_load_more" type="button" id="load-more-btn"
                            data-offset="{{ $shownUpTo }}">TẢI THÊM SẢN PHẨM</button>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="misutech_home_description" id="misutech_home_description">
        <div class="misutech_home_container" style="display: grid; grid-template-columns: 1fr 2fr; gap: 60px;">
            <div class="misutech_home_tags_wrapper">
                <h2 class="misutech_home_tags_title"
                    style="margin-bottom: 20px; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                        <line x1="7" y1="7" x2="7.01" y2="7"></line>
                    </svg>
                    TỪ KHÓA PHỔ BIẾN
                </h2>
                <div class="misutech_home_tags_cloud">
                    @foreach ($parentCategories->take(8) as $pCatItem)
                        <a href="{{ \App\Support\WpUrl::categoryUrl($pCatItem->slug) }}"
                            class="misutech_home_tag_item">{{ $pCatItem->name }}</a>
                    @endforeach
                </div>
            </div>

            <article class="misutech_home_description_article" style="max-width: none;">
                @if(!empty($introHtml))
                    <h2 class="misutech_home_description_title"
                        style="font-size: 24px; margin-bottom: 20px; color: var(--misutech_home_primary);">{{ $pageH1 }}</h2>
                    <div class="misutech_home_category_content" style="font-size: 15px; line-height: 1.8;">
                        {!! $introHtml !!}
                    </div>
                @else
                    <h2 class="misutech_home_description_title"
                        style="font-size: 24px; margin-bottom: 20px; color: var(--misutech_home_primary);">GIẢI PHÁP TỰ
                        ĐỘNG HOÁ TOÀN DIỆN</h2>
                    <p class="misutech_home_description_text" style="font-size: 15px; line-height: 1.8;">Hải Phòng Tech là đơn
                        vị chuyên cung cấp các linh kiện, thiết bị và giải pháp tự động hoá công nghiệp hàng đầu.
                        Chúng tôi cam kết mang lại hiệu suất tối đa và độ tin cậy tuyệt đối cho hệ thống dây chuyền sản xuất
                        của doanh nghiệp.</p>
                @endif
            </article>
        </div>
    </section>

    <section class="misutech_home_benefits" aria-label="Shopping benefits"
        style="background: var(--misutech_home_white); padding: 20px 0 0;">
        <div class="misutech_home_benefit_grid" style="gap: 40px;">
            <article class="misutech_home_benefit"
                style="align-items: center; padding: 30px; border-radius: 12px; background: #f8f9fa; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                <div class="misutech_home_benefit_icon_wrapper"
                    style="width: 50px; height: 50px; background: var(--misutech_home_white); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--misutech_home_primary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="3" width="15" height="13"></rect>
                        <polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon>
                        <circle cx="5.5" cy="18.5" r="2.5"></circle>
                        <circle cx="18.5" cy="18.5" r="2.5"></circle>
                    </svg>
                </div>
                <div>
                    <h2 class="misutech_home_benefit_title" style="font-size: 14px; margin-bottom: 8px; color: var(--misutech_home_ink);">GIAO HÀNG MIỄN PHÍ</h2>
                    <p class="misutech_home_benefit_text" style="font-size: 13px; line-height: 1.5; color: #666;">Hỗ trợ giao hàng nhanh chóng theo chính sách đơn hàng dự án.</p>
                </div>
            </article>

            <article class="misutech_home_benefit"
                style="align-items: center; padding: 30px; border-radius: 12px; background: #f8f9fa; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                <div class="misutech_home_benefit_icon_wrapper"
                    style="width: 50px; height: 50px; background: var(--misutech_home_white); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--misutech_home_primary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                    </svg>
                </div>
                <div>
                    <h2 class="misutech_home_benefit_title" style="font-size: 14px; margin-bottom: 8px; color: var(--misutech_home_ink);">GIAO HÀNG TOÀN QUỐC</h2>
                    <p class="misutech_home_benefit_text" style="font-size: 13px; line-height: 1.5; color: #666;">Hợp tác cùng đối tác uy tín, đưa sản phẩm an toàn đến mọi tỉnh thành.</p>
                </div>
            </article>

            <article class="misutech_home_benefit"
                style="align-items: center; padding: 30px; border-radius: 12px; background: #f8f9fa; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                <div class="misutech_home_benefit_icon_wrapper"
                    style="width: 50px; height: 50px; background: var(--misutech_home_white); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--misutech_home_primary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div>
                    <h2 class="misutech_home_benefit_title" style="font-size: 14px; margin-bottom: 8px; color: var(--misutech_home_ink);">BẢO HÀNH CHÍNH HÃNG</h2>
                    <p class="misutech_home_benefit_text" style="font-size: 13px; line-height: 1.5; color: #666;">Đầy đủ CO/CQ, hóa đơn VAT và bảo hành chính hãng 12 tháng.</p>
                </div>
            </article>
        </div>
    </section>

    @if (!empty($viewedProducts) && count($viewedProducts) > 0)
        <section class="misutech_home_recently_viewed">
            <div class="misutech_home_container">
                <div class="misutech_home_recent_header">
                    <h2 class="misutech_home_recent_title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        Sản Phẩm Đã Xem
                    </h2>
                </div>
                <div class="misutech_recently_viewed_grid">
                    @foreach ($viewedProducts as $viewedProduct)
                        @include('clients.pages.shop.partials.product_card', ['product' => $viewedProduct])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
