@extends('clients.layouts.master')

@php
    $catTitle = $seoCategory['title'] ?? ($category->meta_title ?: (($category->name ?? 'Danh mục') . ' - ' . ($settings->name ?? 'Hải Phòng Tech')));
    $catUrl = $seoCategory['current_url'] ?? \App\Support\WpUrl::categoryUrl($category->slug);
    $catCanonical = $seoCategory['canonical'] ?? \App\Support\WpUrl::categoryUrl($category->slug);
    $catRobots = $seoCategory['robots'] ?? ($category->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large');
    $catDesc = $seoCategory['description'] ?? ($category->meta_description ?: (!empty($category->content) ? Str::limit(strip_tags($category->content), 160) : ''));
    $catKeywords = $category->name . ', thiết bị ' . $category->name . ', ' . $category->name . ' chính hãng, tự động hóa, ' . ($settings->name ?? 'Hải Phòng Tech');
    $catImage = !empty($category->icon)
        ? \App\Support\WpUrl::resolveCategoryImage($category->icon)
        : (!empty($settings->og_image)
            ? \App\Support\WpUrl::absolute($settings->og_image)
            : asset('wp-content/uploads/2025/03/logo-hai-phong-tech.webp'));
    $logoUrl = \App\Support\WpUrl::absolute($settings->site_logo ?? '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp');
    $companyName = $settings->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $brandName = $settings->name ?? 'Hải Phòng Tech';
    $hotline = $settings->hotline ?? ($settings->phone ?? '0866555212');
    $email = $settings->email ?? 'kinhdoanhhpt@haiphongtech.vn';
    $address = $settings->address ?? 'Số 250 Đường Đại Thắng, Phường Dương Kinh, TP. Hải Phòng, Việt Nam';
    $fbUrl = $settings->facebook ?? ($settings->facebook_url ?? 'https://www.facebook.com/HaiPhongTech.vn');
    $zaloUrl = $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212');

    $crumbItems = [
        ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => \App\Support\WpUrl::absolute('/'), 'name' => 'Trang chủ']],
        ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => \App\Support\WpUrl::shopUrl(), 'name' => 'Cửa hàng']],
    ];
    $cPos = 3;
    foreach (($breadcrumbs ?? [$category]) as $bcCat) {
        $crumbItems[] = [
            '@type'    => 'ListItem',
            'position' => $cPos++,
            'item'     => ['@id' => \App\Support\WpUrl::categoryUrl($bcCat->slug), 'name' => $bcCat->name],
        ];
    }

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

    $adaptedSchema = $seoCategory['schema_json'] ?? \App\Support\WpUrl::adaptSchemaJson($category->schema_json);
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
                '@id'   => $catUrl . '#webpage',
                'url'   => $catUrl,
                'name'  => $catTitle,
                'inLanguage' => 'vi',
                'breadcrumb' => ['@id' => $catUrl . '#breadcrumb'],
            ],
            [
                '@type' => 'BreadcrumbList',
                '@id'   => $catUrl . '#breadcrumb',
                'itemListElement' => $crumbItems,
            ],
            [
                '@type' => 'ItemList',
                '@id'   => $catUrl . '#itemlist',
                'url'   => $catUrl,
                'name'  => 'Danh sách sản phẩm ' . $category->name,
                'numberOfItems' => count($itemList),
                'itemListElement' => $itemList,
            ],
        ],
    ];
@endphp

@section('title', $catTitle)

@push('meta')
    @if(!empty($catCanonical) && !str_contains(strtolower($catRobots), 'noindex'))
        <link rel="canonical" href="{{ $catCanonical }}">
        <link rel="alternate" hreflang="vi" href="{{ $catCanonical }}">
        <link rel="alternate" hreflang="x-default" href="{{ $catCanonical }}">
    @endif

    <meta name="keywords" content="{{ $catKeywords }}">
    @if($catDesc !== '')
        <meta name="description" content="{{ $catDesc }}">
    @endif
    <meta name="robots" content="{{ $catRobots }}">

    <meta property="og:title" content="{{ $catTitle }}">
    @if($catDesc !== '')
        <meta property="og:description" content="{{ $catDesc }}">
    @endif
    <meta property="og:url" content="{{ $catCanonical ?: $catUrl }}">
    <meta property="og:image" content="{{ $catImage }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brandName }}">
    <meta property="og:locale" content="vi_VN">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $catTitle }}">
    @if($catDesc !== '')
        <meta name="twitter:description" content="{{ $catDesc }}">
    @endif
    <meta name="twitter:image" content="{{ $catImage }}">

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
    <script>
        window.currentCategorySlug = "{{ $category->slug ?? '' }}";
    </script>
    <script src="{{ asset('clients/js/category.js') }}?v={{ file_exists(public_path('clients/js/category.js')) ? filemtime(public_path('clients/js/category.js')) : time() }}"></script>
@endpush

@section('content')
    <div class="hpt_catalog_header">
        <div class="misutech_catalog_container">
            <nav class="hpt_catalog_breadcrumb" aria-label="Breadcrumb">
                <a href="{{ \App\Support\WpUrl::absolute('/') }}">Trang chủ</a>
                <span>›</span>
                <a href="{{ \App\Support\WpUrl::shopUrl() }}">Cửa hàng</a>
                @foreach(($breadcrumbs ?? [$category]) as $bcCat)
                    <span>›</span>
                    @if($loop->last)
                        <span>{{ $bcCat->name }}</span>
                    @else
                        <a href="{{ \App\Support\WpUrl::categoryUrl($bcCat->slug) }}">{{ $bcCat->name }}</a>
                    @endif
                @endforeach
            </nav>
        </div>
    </div>

    <h1 class="misutech_catalog_container hpt_catalog_title">{{ $category->name }}</h1>

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
        <div class="misutech_home_container misutech_home_description_grid">
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
                @php
                    $catDescHtml = !empty($category->content) ? $category->content : $category->description;
                @endphp
                @if (!empty($catDescHtml))
                    <h2 class="misutech_home_description_title"
                        style="font-size: 24px; margin-bottom: 20px; color: var(--misutech_home_primary);">
                        {{ $category->name }}</h2>
                    <div class="misutech_home_category_content"
                        style="font-size: 15px; line-height: 1.8; position: relative;">
                        <div class="misutech_home_category_content_inner"
                            style="max-height: 150px; overflow: hidden; transition: max-height 0.3s ease;">
                            {!! $catDescHtml !!}
                        </div>
                        <div class="misutech_home_category_content_overlay"
                            style="position: absolute; bottom: 0; left: 0; width: 100%; height: 50px; background: linear-gradient(to bottom, rgba(255,255,255,0), rgba(255,255,255,1));">
                        </div>
                    </div>
                    <button class="misutech_home_see_more_html" type="button" aria-expanded="false"
                        style="display: inline-flex; align-items: center; gap: 8px; margin-top: 15px; font-size: 13px; color: var(--misutech_home_primary); background: transparent; padding: 0; font-weight: 700; border: none; cursor: pointer;">
                        <span>ĐỌC THÊM</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="misutech_home_see_more_icon" style="transition: transform 0.3s ease;">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>

                    <script>
                        document.addEventListener("DOMContentLoaded", function() {
                            const btn = document.querySelector('.misutech_home_see_more_html');
                            const inner = document.querySelector('.misutech_home_category_content_inner');
                            const overlay = document.querySelector('.misutech_home_category_content_overlay');

                            if (btn && inner && overlay) {
                                if (inner.scrollHeight <= 150) {
                                    btn.style.display = 'none';
                                    overlay.style.display = 'none';
                                    inner.style.maxHeight = 'none';
                                }

                                btn.addEventListener('click', function() {
                                    const icon = this.querySelector('svg');
                                    const span = this.querySelector('span');

                                    if (this.getAttribute('aria-expanded') === 'false') {
                                        inner.style.maxHeight = inner.scrollHeight + 'px';
                                        overlay.style.display = 'none';
                                        this.setAttribute('aria-expanded', 'true');
                                        span.innerText = 'THU GỌN';
                                        icon.style.transform = 'rotate(180deg)';
                                    } else {
                                        inner.style.maxHeight = '150px';
                                        overlay.style.display = 'block';
                                        this.setAttribute('aria-expanded', 'false');
                                        span.innerText = 'ĐỌC THÊM';
                                        icon.style.transform = 'rotate(0deg)';
                                    }
                                });
                            }
                        });
                    </script>
                @endif
            </article>
        </div>
    </section>

    <section class="misutech_home_benefits" aria-label="Shopping benefits"
        style="background: var(--misutech_home_white); padding: 20px 0 0;">
        <div class="misutech_home_benefit_grid" style="gap: 20px;">
            <article class="misutech_home_benefit"
                style="align-items: center; padding: 30px; border-radius: 12px; background: #f8f9fa; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                <div class="misutech_home_benefit_icon_wrapper"
                    style="width: 50px; height: 50px; background: var(--misutech_home_white); aspect-ratio: 1 / 1; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.05); color: var(--misutech_home_primary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="3" width="15" height="13"></rect>
                        <polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon>
                        <circle cx="5.5" cy="18.5" r="2.5"></circle>
                        <circle cx="18.5" cy="18.5" r="2.5"></circle>
                    </svg>
                </div>
                <div>
                    <h2 class="misutech_home_benefit_title"
                        style="font-size: 14px; margin-bottom: 8px; color: var(--misutech_home_ink);">GIAO HÀNG MIỄN PHÍ
                    </h2>
                    <p class="misutech_home_benefit_text" style="font-size: 13px; line-height: 1.5; color: #666;">Không lo
                        phí vận chuyển. Bạn chỉ thanh toán đúng giá niêm yết của sản phẩm.</p>
                </div>
            </article>

            <article class="misutech_home_benefit"
                style="align-items: center; padding: 30px; border-radius: 12px; background: #f8f9fa; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                <div class="misutech_home_benefit_icon_wrapper"
                    style="width: 50px; height: 50px; background: var(--misutech_home_white); aspect-ratio: 1 / 1; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.05); color: var(--misutech_home_primary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <path
                            d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z">
                        </path>
                    </svg>
                </div>
                <div>
                    <h2 class="misutech_home_benefit_title"
                        style="font-size: 14px; margin-bottom: 8px; color: var(--misutech_home_ink);">GIAO HÀNG TOÀN QUỐC
                    </h2>
                    <p class="misutech_home_benefit_text" style="font-size: 13px; line-height: 1.5; color: #666;">Hợp tác
                        cùng đối tác uy tín, đưa sản phẩm an toàn đến mọi miền đất nước.</p>
                </div>
            </article>

            <article class="misutech_home_benefit"
                style="align-items: center; padding: 30px; border-radius: 12px; background: #f8f9fa; transition: transform 0.3s ease, box-shadow 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                <div class="misutech_home_benefit_icon_wrapper"
                    style="width: 50px; height: 50px; background: var(--misutech_home_white); aspect-ratio: 1 / 1; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.05); color: var(--misutech_home_primary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <div>
                    <h2 class="misutech_home_benefit_title"
                        style="font-size: 14px; margin-bottom: 8px; color: var(--misutech_home_ink);">BẢO HÀNH CHÍNH HÃNG
                    </h2>
                    <p class="misutech_home_benefit_text" style="font-size: 13px; line-height: 1.5; color: #666;">An tâm
                        tuyệt đối với chính sách bảo hành 1 đổi 1 và hoàn tiền trong 30 ngày.</p>
                </div>
            </article>
        </div>
    </section>

    @if (!empty($viewedProducts) && count($viewedProducts) > 0)
        <section class="misutech_home_recently_viewed">
            <div class="misutech_home_container">
                <div class="misutech_home_recent_header">
                    <h2 class="misutech_home_recent_title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
