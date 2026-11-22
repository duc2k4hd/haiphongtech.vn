<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Support\WpUrl;
use Symfony\Component\HttpFoundation\Response;

class ContentSitemapController extends Controller
{
    /**
     * Serve RankMath-compatible /sitemap_index.xml
     * Flags cutover block header when WooCommerce products are not yet migrated.
     */
    public function sitemapIndex(): Response
    {
        $entries = [
            [
                'loc'     => WpUrl::absolute('/post-sitemap.xml'),
                'lastmod' => optional(Post::where('status', 'published')->max('source_modified_at') ? \Carbon\Carbon::parse(Post::where('status', 'published')->max('source_modified_at')) : now())->toAtomString(),
            ],
            [
                'loc'     => WpUrl::absolute('/page-sitemap.xml'),
                'lastmod' => optional(Page::where('status', 'published')->max('source_modified_at') ? \Carbon\Carbon::parse(Page::where('status', 'published')->max('source_modified_at')) : now())->toAtomString(),
            ],
            [
                'loc'     => WpUrl::absolute('/category-sitemap.xml'),
                'lastmod' => now()->toAtomString(),
            ],
        ];

        if (Category::where('type', 'product')->where('status', 'active')->count() > 0) {
            $entries[] = [
                'loc'     => WpUrl::absolute('/product_cat-sitemap.xml'),
                'lastmod' => optional(Category::where('type', 'product')->max('updated_at') ? \Carbon\Carbon::parse(Category::where('type', 'product')->max('updated_at')) : now())->toAtomString(),
            ];
        }

        $minCutoverCount = (int) config('migration.phase2.full_product_cutover_min_count', 130000);
        $publishedProductsCount = Product::published()->count();
        $cutoverReady = $publishedProductsCount >= $minCutoverCount;

        if ($cutoverReady) {
            $chunks = Product::published()
                ->where('in_sitemap', true)
                ->selectRaw('sitemap_chunk, MAX(updated_at) as last_mod')
                ->groupBy('sitemap_chunk')
                ->orderBy('sitemap_chunk')
                ->get();

            foreach ($chunks as $chunkRow) {
                $chunkNum = (int) $chunkRow->sitemap_chunk;
                if ($chunkNum < 1) continue;
                $entries[] = [
                    'loc'     => WpUrl::absolute("/product-sitemap{$chunkNum}.xml"),
                    'lastmod' => ($chunkRow->last_mod ? \Carbon\Carbon::parse($chunkRow->last_mod) : now())->toAtomString(),
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $item) {
            $loc = htmlspecialchars($item['loc'], ENT_QUOTES, 'UTF-8');
            $mod = htmlspecialchars($item['lastmod'], ENT_QUOTES, 'UTF-8');
            $xml .= "  <sitemap>\n    <loc>{$loc}</loc>\n    <lastmod>{$mod}</lastmod>\n  </sitemap>\n";
        }
        $xml .= "</sitemapindex>\n";

        $headers = [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ];

        // Block cutover until full 133,036 products and 67 product-sitemaps are migrated
        if (!$cutoverReady) {
            $headers['X-Sitemap-Cutover-Status'] = 'BLOCKED_MISSING_67_PRODUCT_SITEMAPS';
        }

        return response($xml, 200, $headers);
    }

    public function pageSitemap(): Response
    {
        $pages = Page::where('in_sitemap', true)
            ->whereIn('status', ['published', 'redirected', 'reserved_phase2'])
            ->where('slug', '!=', 'tin-blog')
            ->orderBy('id')
            ->get(['full_path', 'source_modified_at', 'published_at', 'updated_at']);

        $items = [];
        foreach ($pages as $pg) {
            $dt = $pg->source_modified_at ?: ($pg->published_at ?: $pg->updated_at ?: now());
            $items[] = [
                'loc'     => WpUrl::absolute($pg->full_path),
                'lastmod' => $dt->toAtomString(),
            ];
        }

        return $this->renderUrlset($items);
    }

    public function postSitemap(): Response
    {
        $items = [];

        $blogPage = Page::where('slug', 'tin-blog')->first();
        if ($blogPage) {
            $dt = $blogPage->source_modified_at ?: ($blogPage->published_at ?: now());
            $items[] = [
                'loc'     => WpUrl::absolute('/tin-blog/'),
                'lastmod' => $dt->toAtomString(),
            ];
        }

        $posts = Post::where('status', 'published')
            ->where('in_sitemap', true)
            ->orderByDesc('published_at')
            ->get(['slug', 'source_modified_at', 'published_at', 'updated_at']);

        foreach ($posts as $post) {
            $dt = $post->source_modified_at ?: ($post->published_at ?: $post->updated_at ?: now());
            $items[] = [
                'loc'     => WpUrl::absolute('/' . trim($post->slug, '/') . '/'),
                'lastmod' => $dt->toAtomString(),
            ];
        }

        return $this->renderUrlset($items);
    }

    public function categorySitemap(): Response
    {
        $cats = Category::where('type', 'post')
            ->where('status', 'active')
            ->where('in_sitemap', true)
            ->orderBy('id')
            ->get(['slug', 'updated_at']);

        $items = [];
        foreach ($cats as $cat) {
            $items[] = [
                'loc'     => WpUrl::absolute('/' . trim($cat->slug, '/') . '/'),
                'lastmod' => ($cat->updated_at ?: now())->toAtomString(),
            ];
        }

        return $this->renderUrlset($items);
    }

    public function productCatSitemap(): Response
    {
        $cats = Category::where('type', 'product')
            ->where('status', 'active')
            ->where('in_sitemap', true)
            ->orderBy('id')
            ->get(['slug', 'updated_at']);

        $items = [];
        foreach ($cats as $cat) {
            $items[] = [
                'loc'     => WpUrl::absolute('/' . trim($cat->slug, '/') . '/'),
                'lastmod' => ($cat->updated_at ?: now())->toAtomString(),
            ];
        }

        return $this->renderUrlset($items);
    }

    /**
     * Serve sharded WooCommerce product sitemaps (/product-sitemap1..67.xml) using streaming cursor.
     * Strictly holds the 404 cutover blocker whenever full 133k product catalog parity has not been reached.
     */
    public function productSitemapChunk(string $num = ''): Response
    {
        $minCutoverCount = (int) config('migration.phase2.full_product_cutover_min_count', 130000);
        if (Product::published()->count() < $minCutoverCount) {
            return $this->reservedProductSitemap();
        }

        $chunk = $num === '' ? 1 : (int) $num;
        if ($chunk < 1) {
            abort(404);
        }

        $exists = Product::published()
            ->where('in_sitemap', true)
            ->where('sitemap_chunk', $chunk)
            ->exists();

        if (!$exists) {
            return $this->reservedProductSitemap();
        }

        return response()->stream(function () use ($chunk) {
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

            Product::published()
                ->where('in_sitemap', true)
                ->where('sitemap_chunk', $chunk)
                ->orderBy('id')
                ->select(['slug', 'published_at', 'updated_at'])
                ->cursor()
                ->each(function (Product $p) {
                    $loc = htmlspecialchars(WpUrl::productUrl($p->slug), ENT_QUOTES, 'UTF-8');
                    $mod = htmlspecialchars(($p->updated_at ?: $p->published_at ?: now())->toAtomString(), ENT_QUOTES, 'UTF-8');
                    echo "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$mod}</lastmod>\n  </url>\n";
                });

            echo "</urlset>\n";
        }, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Reserved Phase 2 Product & Product-Category sitemaps:
     * Never fake empty 200 XML — return 404 with explicit cutover blocker header.
     */
    public function reservedProductSitemap(): Response
    {
        return response('Product sitemaps are reserved for Phase 2 migration. Cutover is blocked.', 404, [
            'Content-Type'             => 'text/plain; charset=UTF-8',
            'X-Migration-Route-Status' => 'RESERVED_PHASE2_PRODUCT_SITEMAP_BLOCKER',
            'X-Robots-Tag'             => 'noindex, nofollow',
        ]);
    }

    private function renderUrlset(array $items): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($items as $it) {
            $loc = htmlspecialchars($it['loc'], ENT_QUOTES, 'UTF-8');
            $mod = htmlspecialchars($it['lastmod'], ENT_QUOTES, 'UTF-8');
            $xml .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$mod}</lastmod>\n  </url>\n";
        }
        $xml .= "</urlset>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
