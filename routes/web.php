<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Clients\HomeController;
use App\Http\Controllers\Clients\CategoryController;
use App\Http\Controllers\Clients\ShopController;
use App\Http\Controllers\Clients\ProductController;
use App\Http\Controllers\Clients\SeriesController;
use App\Http\Controllers\Clients\DocumentController;
use App\Http\Controllers\Clients\BlogController;
use App\Http\Controllers\Clients\ContactController;
use App\Http\Controllers\Clients\QuoteController;
use App\Http\Controllers\Clients\WpContentRouterController;
use App\Http\Controllers\Clients\WpMediaController;
use App\Http\Controllers\Clients\ContentSitemapController;

// 1. Homepage
Route::get('/', [HomeController::class, 'index'])->name('home.index');

// 2. RankMath-compatible XML Sitemaps
Route::get('/sitemap_index.xml', [ContentSitemapController::class, 'sitemapIndex'])->name('sitemap.index');
Route::get('/page-sitemap.xml', [ContentSitemapController::class, 'pageSitemap'])->name('sitemap.pages');
Route::get('/post-sitemap.xml', [ContentSitemapController::class, 'postSitemap'])->name('sitemap.posts');
Route::get('/category-sitemap.xml', [ContentSitemapController::class, 'categorySitemap'])->name('sitemap.categories');
Route::get('/product_cat-sitemap.xml', [ContentSitemapController::class, 'productCatSitemap'])->name('sitemap.product_cat');
Route::get('/product-sitemap{num}.xml', [ContentSitemapController::class, 'productSitemapChunk'])
    ->where('num', '[0-9]*')
    ->name('sitemap.products.chunk');

// 3. Original WordPress Media Uploads (/wp-content/uploads/...)
Route::get('/wp-content/uploads/{path}', [WpMediaController::class, 'serve'])
    ->where('path', '.*')
    ->name('wp.uploads.serve');

// 4. WordPress Blog Index & Tag Archives (preserving exact trailing-slash URLs)
Route::get('/tin-blog', [WpContentRouterController::class, 'blogIndex'])->name('blogs.index');
Route::get('/tin-blog/page/{page}', [WpContentRouterController::class, 'blogIndex'])
    ->whereNumber('page')
    ->name('blogs.index.paged');

Route::get('/tag/{slug}', [WpContentRouterController::class, 'tagArchive'])->name('blogs.tag');
Route::get('/tag/{slug}/page/{page}', [WpContentRouterController::class, 'tagArchive'])
    ->whereNumber('page')
    ->name('blogs.tag.paged');

// 5. WooCommerce Product / Shop / Brand Boundaries (Phase 2 with safe unmigrated boundary fallback)
Route::get('/shop', [WpContentRouterController::class, 'shopShow'])->name('shop.index');
Route::get('/shop/page/{page}', [WpContentRouterController::class, 'shopShow'])
    ->whereNumber('page')
    ->name('shop.index.paged');
Route::get('/product/{slug}', [WpContentRouterController::class, 'productShow'])->name('product.show');
Route::post('/product/{slug}/danh-gia', [ProductController::class, 'storeReview'])->name('product.review.store');
Route::get('/thuong_hieu/{slug}', [WpContentRouterController::class, 'brandShow'])->name('brands.wp.show');
Route::get('/thuong_hieu/{slug}/page/{page}', [WpContentRouterController::class, 'brandShow'])
    ->whereNumber('page')
    ->name('brands.wp.show.paged');
Route::get('/tag-san-pham/{slug}', [WpContentRouterController::class, 'productTagShow'])->name('products.wp.tag');
Route::get('/tag-san-pham/{slug}/page/{page}', [WpContentRouterController::class, 'productTagShow'])
    ->whereNumber('page')
    ->name('products.wp.tag.paged');

// 6. Contact form submission (GET /lien-he/ is resolved via exact WP Page route with formToken)
Route::post('/lien-he', [ContactController::class, 'submit'])->name('contact.submit');

// 7. Auxiliary application tools (preserved for backward compatibility)
Route::get('/thuong-hieu', [ShopController::class, 'brands'])->name('brands.index');
Route::get('/danh-muc/{slug}', [CategoryController::class, 'show'])->name('categories.show');
Route::get('/series', [SeriesController::class, 'index'])->name('series.index');
Route::get('/series/{slug}', [SeriesController::class, 'show'])->name('series.show');
Route::get('/gio-hang', [ShopController::class, 'cart'])->name('cart.index');
Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
Route::get('/documents/{id}/download', [DocumentController::class, 'download'])->name('documents.download');
Route::get('/bao-gia', [QuoteController::class, 'index'])->name('quote.index');
Route::get('/api/quote/search-products', [QuoteController::class, 'searchProducts'])->name('quote.api.search');
Route::post('/quote/track', [QuoteController::class, 'saveAndTrack'])->name('quote.track');

// Named route helpers pointing to root WP permalinks
Route::get('/{slug}/page/{page}', [WpContentRouterController::class, 'rootPaginatedArchive'])
    ->where('slug', '[a-zA-Z0-9_\-%]+')
    ->whereNumber('page')
    ->name('wp.category.paged');

// 8. Exact Known URL Registry Resolver (Pages, Root Posts, Root Post Categories, Redirects, Reserved Product Cats)
Route::get('/{path}', [WpContentRouterController::class, 'resolvePath'])
    ->where('path', '^(?!admin(?:/|$)|api(?:/|$)|up$|storage/|sitemaps/|clients/|admins/|build/|wp-json(?:/|$)).+')
    ->name('wp.resolve');
