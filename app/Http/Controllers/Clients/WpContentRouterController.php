<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductTag;
use App\Models\SourceUrlRoute;
use App\Models\Tag;
use App\Support\WpUrl;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class WpContentRouterController extends Controller
{
    public const POSTS_PER_PAGE = 12;

    /**
     * Blog archive index: /tin-blog/ and /tin-blog/page/{page}/
     */
    public function blogIndex(Request $request, int $page = 1): Response
    {
        $expectedPath = $page <= 1 ? '/tin-blog/' : "/tin-blog/page/{$page}/";
        if ($page === 1 && str_contains(WpUrl::rawRequestPath($request), '/page/1')) {
            return $this->redirectPreservingQuery($request, '/tin-blog/', 301);
        }
        if ($redirect = $this->enforceTrailingSlash($request, $expectedPath)) {
            return $redirect;
        }

        $search = trim((string) $request->input('q', ''));
        $blogPage = Page::where('slug', 'tin-blog')->first();

        $query = Post::where('status', 'published')
            ->select([
                'id', 'title', 'slug', 'summary', 'published_at', 'source_modified_at',
                'category_id', 'author_id', 'author_display_name', 'views_count', 'created_at'
            ])
            ->with(['category:id,name,slug', 'thumbnailMedia', 'categories:id,name,slug']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('summary', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $posts = $this->paginateWithWpPaths(
            $query->orderByDesc('published_at')->orderByDesc('id'),
            self::POSTS_PER_PAGE,
            $page,
            '/tin-blog/',
            $request->query()
        );

        if ($page > 1 && $posts->isEmpty()) {
            abort(404);
        }

        $totalPages = max(1, $posts->lastPage());
        $seoMeta = [
            'title'       => $page > 1
                ? "Tin Blog - Trang {$page} trên {$totalPages} - Hải Phòng Tech"
                : ($blogPage?->meta_title ?: 'Tin Blog - Hải Phòng Tech'),
            'description' => $blogPage?->meta_description ?: '',
            'robots'      => $blogPage?->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large',
            'canonical'   => WpUrl::absolute('/tin-blog/'),
            'og_type'     => 'website',
            'schema_json' => WpUrl::adaptSchemaJson($blogPage?->schema_json),
        ];

        $categories = BlogController::getSidebarCategories();
        $popularPosts = BlogController::getPopularPosts();
        $featuredPost = null;
        $catSlug = null;
        $selectedCategory = null;
        $selectedTag = null;

        return response()->view('clients.pages.blogs.index', compact(
            'posts',
            'featuredPost',
            'categories',
            'popularPosts',
            'search',
            'catSlug',
            'selectedCategory',
            'selectedTag',
            'seoMeta'
        ));
    }

    /**
     * Tag archive: /tag/{slug}/ and /tag/{slug}/page/{page}/
     */
    public function tagArchive(Request $request, string $slug, int $page = 1): Response
    {
        $decodedLower = mb_strtolower(rawurldecode($slug), 'UTF-8');
        $wpEncodedLower = strtolower(rawurlencode($decodedLower));
        $tag = Tag::whereIn('slug', array_values(array_unique([$slug, strtolower($slug), $decodedLower, $wpEncodedLower])))
            ->where('status', 'active')
            ->first();
        if (!$tag) {
            abort(404);
        }

        $expectedPath = $page <= 1 ? "/tag/{$tag->slug}/" : "/tag/{$tag->slug}/page/{$page}/";
        if ($page === 1 && str_contains(WpUrl::rawRequestPath($request), '/page/1')) {
            return $this->redirectPreservingQuery($request, "/tag/{$tag->slug}/", 301);
        }
        if ($redirect = $this->enforceTrailingSlash($request, $expectedPath)) {
            return $redirect;
        }

        $query = $tag->posts()
            ->where('posts.status', 'published')
            ->select([
                'posts.id', 'posts.title', 'posts.slug', 'posts.summary', 'posts.published_at',
                'posts.category_id', 'posts.author_id', 'posts.author_display_name', 'posts.views_count', 'posts.created_at'
            ])
            ->with(['category:id,name,slug', 'thumbnailMedia']);

        $posts = $this->paginateWithWpPaths(
            $query->orderByDesc('posts.published_at')->orderByDesc('posts.id'),
            self::POSTS_PER_PAGE,
            $page,
            "/tag/{$tag->slug}/",
            $request->query()
        );

        if ($page > 1 && $posts->isEmpty()) {
            abort(404);
        }

        $seoMeta = [
            'title'       => $tag->meta_title ?: ($tag->name . ' Archives - Hải Phòng Tech'),
            'description' => $tag->meta_description ?: '',
            'robots'      => $tag->meta_robots ?: 'noindex, follow',
            'canonical'   => '', // Intentional: RankMath omits canonical on noindex tag archives
            'og_type'     => 'object',
            'schema_json' => WpUrl::adaptSchemaJson($tag->schema_json),
        ];

        $categories = BlogController::getSidebarCategories();
        $popularPosts = BlogController::getPopularPosts();
        $featuredPost = null;
        $search = '';
        $catSlug = null;
        $selectedCategory = null;
        $selectedTag = $tag;

        return response()->view('clients.pages.blogs.index', compact(
            'posts',
            'featuredPost',
            'categories',
            'popularPosts',
            'search',
            'catSlug',
            'selectedCategory',
            'selectedTag',
            'seoMeta'
        ));
    }

    /**
     * Paginated Root Category Archive: /{slug}/page/{page}/
     * Also catches /shop/page/{page}/ as reserved Phase 2 boundary.
     */
    public function rootPaginatedArchive(Request $request, string $slug, int $page): Response
    {
        $cleanSlug = mb_strtolower(rawurldecode($slug), 'UTF-8');
        if ($cleanSlug === 'shop') {
            return $this->shopShow($request, $page);
        }

        // Check if slug is a product category
        $normRoot = SourceUrlRoute::normalizePath($cleanSlug);
        $routeEntry = SourceUrlRoute::where('path_hash', hash('sha256', $normRoot))->first();
        if ($routeEntry && $routeEntry->entity_type === 'product_category') {
            if ($page <= 1 && str_contains(WpUrl::rawRequestPath($request), '/page/1')) {
                return $this->redirectPreservingQuery($request, "/{$cleanSlug}/", 301);
            }
            if ($redirect = $this->enforceTrailingSlash($request, "/{$cleanSlug}/page/{$page}/")) {
                return $redirect;
            }

            return response(app(CategoryController::class)->show($request, $cleanSlug, $page));
        }
        if ($routeEntry && str_starts_with($routeEntry->entity_type, 'reserved_')) {
            return $this->reservedBoundaryForExpectedPath($request, "/{$cleanSlug}/page/{$page}/");
        }

        $category = Category::where('type', 'post')
            ->where('status', 'active')
            ->where('slug', $cleanSlug)
            ->first();

        if (!$category) {
            abort(404);
        }

        return $this->renderPostCategoryArchive($request, $category, $page);
    }

    /**
     * Phase 2 Product Single Page handler with trailing-slash enforcement.
     */
    public function productShow(Request $request, string $slug): Response
    {
        $cleanSlug = trim(mb_strtolower(rawurldecode($slug), 'UTF-8'), '/');
        $expectedPath = "/product/{$cleanSlug}/";
        if ($redirect = $this->enforceTrailingSlash($request, $expectedPath)) {
            return $redirect;
        }

        $product = Product::where('slug', $cleanSlug)->where('status', 'active')->first();
        if ($product) {
            return response(app(ProductController::class)->show($request, $cleanSlug));
        }

        return $this->reservedBoundaryForExpectedPath($request, $expectedPath);
    }

    /**
     * Phase 2 Shop Page handler with trailing-slash and pagination enforcement.
     */
    public function shopShow(Request $request, int $page = 1): Response
    {
        $expectedPath = $page <= 1 ? '/shop/' : "/shop/page/{$page}/";
        if ($page === 1 && str_contains(WpUrl::rawRequestPath($request), '/page/1')) {
            return $this->redirectPreservingQuery($request, '/shop/', 301);
        }
        if ($redirect = $this->enforceTrailingSlash($request, $expectedPath)) {
            return $redirect;
        }

        if (Product::where('status', 'active')->exists()) {
            return response(app(ShopController::class)->index($request, $page));
        }

        return $this->reservedBoundaryForExpectedPath($request, $expectedPath);
    }

    /**
     * Phase 2 Brand Archive handler with trailing-slash enforcement, pagination & brand SEO.
     */
    public function brandShow(Request $request, string $slug, int $page = 1): Response
    {
        $cleanSlug = trim(mb_strtolower(rawurldecode($slug), 'UTF-8'), '/');
        $expectedPath = $page <= 1 ? "/thuong_hieu/{$cleanSlug}/" : "/thuong_hieu/{$cleanSlug}/page/{$page}/";
        if ($page === 1 && str_contains(WpUrl::rawRequestPath($request), '/page/1')) {
            return $this->redirectPreservingQuery($request, "/thuong_hieu/{$cleanSlug}/", 301);
        }
        if ($redirect = $this->enforceTrailingSlash($request, $expectedPath)) {
            return $redirect;
        }

        $brand = Brand::where('slug', $cleanSlug)->where('status', 'active')->first();
        if ($brand) {
            return response(app(ShopController::class)->index($request, $page, activeBrand: $brand));
        }

        return $this->reservedBoundaryForExpectedPath($request, $expectedPath);
    }

    /**
     * Phase 2 Product Tag Archive (/tag-san-pham/{slug}/ & /tag-san-pham/{slug}/page/{page}/)
     * Serves migrated active ProductTags with noindex,follow + omitted canonical; otherwise holds 404 cutover blocker.
     */
    public function productTagShow(Request $request, string $slug, int $page = 1): Response
    {
        $decodedLower = mb_strtolower(rawurldecode($slug), 'UTF-8');
        $wpEncodedLower = strtolower(rawurlencode($decodedLower));
        $candidates = array_values(array_unique([trim($slug, '/'), strtolower(trim($slug, '/')), $decodedLower, $wpEncodedLower]));

        $tag = ProductTag::whereIn('slug', $candidates)
            ->where('status', 'active')
            ->first();

        $activeSlug = $tag ? $tag->slug : trim($slug, '/');
        $expectedPath = $page <= 1 ? "/tag-san-pham/{$activeSlug}/" : "/tag-san-pham/{$activeSlug}/page/{$page}/";

        if ($page === 1 && str_contains(WpUrl::rawRequestPath($request), '/page/1')) {
            return $this->redirectPreservingQuery($request, "/tag-san-pham/{$activeSlug}/", 301);
        }
        if ($redirect = $this->enforceTrailingSlash($request, $expectedPath)) {
            return $redirect;
        }

        if ($tag) {
            return response(app(ShopController::class)->index($request, $page, activeProductTag: $tag));
        }

        return $this->reservedBoundaryForExpectedPath($request, $expectedPath);
    }

    /**
     * Reserved Phase 2 Product / Shop / Brand boundary handler (safe for routes with {slug} or {page} params).
     */
    public function reservedProductBoundary(Request $request): Response
    {
        $rawPath = WpUrl::rawRequestPath($request);
        $expected = SourceUrlRoute::normalizePath($rawPath);
        return $this->reservedBoundaryForExpectedPath($request, $expected);
    }

    private function reservedBoundaryForExpectedPath(Request $request, string $expectedSlashedPath): Response
    {
        if ($redirect = $this->enforceTrailingSlash($request, $expectedSlashedPath)) {
            return $redirect;
        }

        return response()
            ->view('errors.404', [], 404)
            ->header('X-Migration-Route-Status', 'RESERVED_PHASE2_PRODUCT_BLOCKER')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Exact Known URL Resolver for /{path} (Pages, Posts, Post Categories, Redirects, Reserved Product Cats).
     */
    public function resolvePath(Request $request, string $path): Response
    {
        $normPath = SourceUrlRoute::normalizePath($path);
        $pathHash = hash('sha256', $normPath);

        $routeEntry = SourceUrlRoute::where('path_hash', $pathHash)->first();
        if (!$routeEntry) {
            abort(404);
        }

        // 1. Source Redirects (301 / 302)
        if ($routeEntry->entity_type === 'redirect' && !empty($routeEntry->redirect_target)) {
            return new \Symfony\Component\HttpFoundation\RedirectResponse(
                WpUrl::absolute($routeEntry->redirect_target),
                (int) $routeEntry->http_status
            );
        }

        // 2. Canonical trailing-slash normalization (301 /abc -> /abc/)
        if ($routeEntry->trailing_slash_policy === 'require_slash') {
            if ($redirect = $this->enforceTrailingSlash($request, $routeEntry->normalized_path)) {
                return $redirect;
            }
        }

        // 3. Reserved Phase 2 Product / Shop / Brand boundaries (including the 6 colliding product_cat slugs!)
        if (in_array($routeEntry->entity_type, [
            'reserved_product_cat',
            'reserved_product_brand',
            'reserved_product',
            'reserved_shop',
        ], true)) {
            return response()
                ->view('errors.404', [], 404)
                ->header('X-Migration-Route-Status', 'RESERVED_PHASE2_PRODUCT_BLOCKER')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        // 4. Blog Index
        if ($routeEntry->entity_type === 'blog_index') {
            return $this->blogIndex($request, 1);
        }

        // 5. WordPress Page
        if ($routeEntry->entity_type === 'page') {
            $page = $routeEntry->entity_id
                ? Page::find($routeEntry->entity_id)
                : Page::where('full_path_hash', $pathHash)->first();

            if (!$page || $page->status !== 'published') {
                abort(404);
            }

            if ($page->slug === 'lien-he') {
                $formToken = Crypt::encryptString((string) time());
                return response()->view('clients.pages.contact.index', compact('page', 'formToken'));
            }

            return response()->view('clients.pages.static.show', compact('page'));
        }

        // 6. Post Category Archive
        if ($routeEntry->entity_type === 'post_category') {
            $category = $routeEntry->entity_id
                ? Category::find($routeEntry->entity_id)
                : Category::where('type', 'post')->where('slug', trim($normPath, '/'))->first();

            if (!$category || $category->status !== 'active') {
                abort(404);
            }

            return $this->renderPostCategoryArchive($request, $category, 1);
        }

        // 7. Blog Post Detail
        if ($routeEntry->entity_type === 'post') {
            $post = $routeEntry->entity_id
                ? Post::with(['category:id,name,slug,parent_id', 'categories:id,name,slug', 'tags:id,name,slug', 'thumbnailMedia', 'author:id,name'])->find($routeEntry->entity_id)
                : Post::with(['category:id,name,slug,parent_id', 'categories:id,name,slug', 'tags:id,name,slug', 'thumbnailMedia', 'author:id,name'])->where('slug', trim($normPath, '/'))->first();

            if (!$post || $post->status !== 'published') {
                abort(404);
            }

            return $this->renderPostDetail($post);
        }

        // 8. Product Category Archive (Phase 2)
        if ($routeEntry->entity_type === 'product_category') {
            $catSlug = trim($normPath, '/');
            return response(app(CategoryController::class)->show($request, $catSlug));
        }

        // 9. Product Brand Archive (Phase 2)
        if ($routeEntry->entity_type === 'product_brand') {
            $brandSlug = trim(preg_replace('#^/?thuong_hieu/#', '', $normPath), '/');
            return $this->brandShow($request, $brandSlug, 1);
        }

        // 9b. Product Tag Archive (Phase 2)
        if ($routeEntry->entity_type === 'product_tag') {
            $tagSlug = trim(preg_replace('#^/?tag-san-pham/#', '', $normPath), '/');
            return $this->productTagShow($request, $tagSlug, 1);
        }

        // 10. Single Product (Phase 2)
        if ($routeEntry->entity_type === 'product') {
            $prodSlug = trim(preg_replace('#^/?product/#', '', $normPath), '/');
            return $this->productShow($request, $prodSlug);
        }

        // 11. Shop Index (Phase 2)
        if ($routeEntry->entity_type === 'shop_index') {
            return $this->shopShow($request, 1);
        }

        abort(404);
    }

    private function renderPostCategoryArchive(Request $request, Category $category, int $page = 1): Response
    {
        $expectedPath = $page <= 1 ? "/{$category->slug}/" : "/{$category->slug}/page/{$page}/";
        if ($page === 1 && str_contains(WpUrl::rawRequestPath($request), '/page/1')) {
            return $this->redirectPreservingQuery($request, "/{$category->slug}/", 301);
        }
        if ($redirect = $this->enforceTrailingSlash($request, $expectedPath)) {
            return $redirect;
        }

        $descIds = $category->getAllChildrenIds();
        $query = Post::where('status', 'published')
            ->where(function ($q) use ($descIds) {
                $q->whereIn('category_id', $descIds)
                  ->orWhereHas('categories', fn($cq) => $cq->whereIn('categories.id', $descIds));
            })
            ->select([
                'id', 'title', 'slug', 'summary', 'published_at',
                'category_id', 'author_id', 'author_display_name', 'views_count', 'created_at'
            ])
            ->with(['category:id,name,slug', 'thumbnailMedia']);

        $posts = $this->paginateWithWpPaths(
            $query->orderByDesc('published_at')->orderByDesc('id'),
            self::POSTS_PER_PAGE,
            $page,
            "/{$category->slug}/",
            $request->query()
        );

        if ($page > 1 && $posts->isEmpty()) {
            abort(404);
        }

        $totalPages = max(1, $posts->lastPage());
        $baseTitle = $category->meta_title ?: ($category->name . ' - Hải Phòng Tech');
        $seoMeta = [
            'title'       => $page > 1 ? "{$category->name} - Trang {$page} trên {$totalPages} - Hải Phòng Tech" : $baseTitle,
            'description' => $category->meta_description ?: ($category->description ?: ''),
            'robots'      => $category->meta_robots ?: 'follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large',
            'canonical'   => !empty($category->canonical_url) ? WpUrl::absolute($category->canonical_url) : WpUrl::absolute("/{$category->slug}/"),
            'og_type'     => 'article',
            'schema_json' => WpUrl::adaptSchemaJson($category->schema_json),
        ];

        $categories = BlogController::getSidebarCategories();
        $popularPosts = BlogController::getPopularPosts();
        $featuredPost = null;
        $search = '';
        $catSlug = $category->slug;
        $selectedCategory = $category;
        $selectedTag = null;

        return response()->view('clients.pages.blogs.index', compact(
            'posts',
            'featuredPost',
            'categories',
            'popularPosts',
            'search',
            'catSlug',
            'selectedCategory',
            'selectedTag',
            'seoMeta'
        ));
    }

    private function renderPostDetail(Post $post): Response
    {
        $relatedCategoryIds = $post->categories->pluck('id')->toArray();
        if (empty($relatedCategoryIds) && $post->category_id) {
            $relatedCategoryIds = [$post->category_id];
        }

        $relatedPosts = Post::where('status', 'published')
            ->select(BlogController::POST_CARD_COLUMNS)
            ->where('id', '!=', $post->id)
            ->when(!empty($relatedCategoryIds), function ($q) use ($relatedCategoryIds) {
                $q->whereIn('category_id', $relatedCategoryIds);
            })
            ->with(['thumbnailMedia', 'category:id,name,slug'])
            ->latest('published_at')
            ->limit(4)
            ->get();

        if ($relatedPosts->count() < 4) {
            $needed = 4 - $relatedPosts->count();
            $excludeIds = $relatedPosts->pluck('id')->push($post->id)->toArray();
            $fallback = Post::where('status', 'published')
                ->select(BlogController::POST_CARD_COLUMNS)
                ->whereNotIn('id', $excludeIds)
                ->with(['thumbnailMedia', 'category:id,name,slug'])
                ->latest('published_at')
                ->limit($needed)
                ->get();
            $relatedPosts = $relatedPosts->concat($fallback);
        }

        $recentPosts = BlogController::getRecentPosts($post->id);
        $categories = BlogController::getSidebarCategories();

        return response()->view('clients.pages.blogs.show', compact(
            'post',
            'relatedPosts',
            'recentPosts',
            'categories'
        ));
    }

    private function enforceTrailingSlash(Request $request, string $expectedSlashedPath): ?Response
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return null;
        }
        $rawPath = rawurldecode(WpUrl::rawRequestPath($request));
        if ($rawPath !== '/' && !str_ends_with($rawPath, '/')) {
            return $this->redirectPreservingQuery($request, $expectedSlashedPath, 301);
        }
        return null;
    }

    private function redirectPreservingQuery(Request $request, string $targetPath, int $status = 301): Response
    {
        $qs = $request->getQueryString();
        $absTarget = WpUrl::absolute($targetPath);
        $dest = $qs ? "{$absTarget}?{$qs}" : $absTarget;
        return new \Symfony\Component\HttpFoundation\RedirectResponse($dest, $status);
    }

    /**
     * Build a Paginator whose page URLs follow WordPress /.../page/{n}/ format instead of ?page={n}.
     */
    private function paginateWithWpPaths(
        $builder,
        int $perPage,
        int $currentPage,
        string $baseSlashedPath,
        array $queryParams = []
    ): LengthAwarePaginator {
        $total = (clone $builder)->count();
        $items = $builder->forPage($currentPage, $perPage)->get();

        unset($queryParams['page']);
        $paginator = new \App\Support\WpLengthAwarePaginator($items, $total, $perPage, $currentPage, [
            'path'  => rtrim($baseSlashedPath, '/'),
            'query' => $queryParams,
        ]);
        $paginator->setWpBaseSlashedPath($baseSlashedPath);

        return $paginator;
    }
}
