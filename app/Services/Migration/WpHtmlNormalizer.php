<?php

namespace App\Services\Migration;

class WpHtmlNormalizer
{
    /**
     * Known WordPress plugin shortcodes that are inactive in Laravel Phase 1.
     */
    private const PLUGIN_SHORTCODE_NOTICES = [
        'yith_wcwl_wishlist'     => '<div class="hpt_plugin_placeholder" data-shortcode="yith_wcwl_wishlist">Danh sách sản phẩm yêu thích đang được đồng bộ từ hệ thống cửa hàng.</div>',
        'wpum_password_recovery' => '<div class="hpt_plugin_placeholder" data-shortcode="wpum_password_recovery">Chức năng khôi phục mật khẩu tài khoản khách hàng đang được bảo trì nâng cấp. Vui lòng liên hệ hỗ trợ nếu cần cấp lại thông tin.</div>',
        'yith_wcan_filters'      => '<div class="hpt_plugin_placeholder" data-shortcode="yith_wcan_filters">Bộ lọc hồ sơ sản phẩm đang được nâng cấp.</div>',
    ];

    /**
     * Normalize WordPress rendered HTML safely for staging/production rendering
     * while preserving semantic structure, headings, tables, downloads, and media paths.
     */
    public function normalize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $out = $html;

        // 1. Strip executable <script> tags and inline event handlers (on*) to prevent XSS
        $out = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $out) ?? $out;
        $out = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $out) ?? $out;

        // 2. Sanitize iframes: only allow trusted embed hosts (Google Maps, YouTube)
        $out = preg_replace_callback('#<iframe\b[^>]*>.*?</iframe>#is', function (array $m) {
            $tag = $m[0];
            if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $sm)) {
                $host = strtolower((string) parse_url($sm[1], PHP_URL_HOST));
                $allowed = ['www.google.com', 'maps.google.com', 'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com'];
                if (in_array($host, $allowed, true)) {
                    return $tag;
                }
            }
            return '';
        }, $out) ?? $out;

        // 3. Promote Flatsome / LiteSpeed lazy-loaded images (data-src / data-lazy-src) to real src
        $out = preg_replace_callback('#<img\b[^>]*>#is', function (array $m) {
            $imgTag = $m[0];
            $realSrc = null;
            if (preg_match('/\bdata-(?:lazy-)?src\s*=\s*["\']([^"\']+)["\']/i', $imgTag, $dsm)) {
                $realSrc = $dsm[1];
            }
            if ($realSrc && preg_match('/\bsrc\s*=\s*["\']data:image\/[^"\']*["\']/i', $imgTag)) {
                $imgTag = preg_replace(
                    '/\bsrc\s*=\s*["\']data:image\/[^"\']*["\']/i',
                    'src="' . htmlspecialchars($realSrc, ENT_QUOTES, 'UTF-8') . '"',
                    $imgTag
                ) ?? $imgTag;
            }

            if (preg_match('/\bdata-(?:lazy-)?srcset\s*=\s*["\']([^"\']+)["\']/i', $imgTag, $dssm)) {
                if (!preg_match('/\b(?<!data-)srcset\s*=/i', $imgTag)) {
                    $imgTag = rtrim(substr($imgTag, 0, -1)) . ' srcset="' . htmlspecialchars($dssm[1], ENT_QUOTES, 'UTF-8') . '">';
                }
            }

            return $imgTag;
        }, $out) ?? $out;

        // 4. Replace inactive WordPress plugin shortcodes with clean semantic placeholders
        foreach (self::PLUGIN_SHORTCODE_NOTICES as $sc => $replacement) {
            $out = preg_replace('/\[' . preg_quote($sc, '/') . '(?:\s[^\]]*)?\]/i', $replacement, $out) ?? $out;
        }

        // 5. Convert absolute https://haiphongtech.vn/... URLs in href/src/srcset to root-relative /...
        //    so staging and production serve identical paths without leaking staging hostnames.
        $out = preg_replace(
            '#https?://(?:www\.)?haiphongtech\.vn(/[^"\'\s<>)\],]*)#i',
            '$1',
            $out
        ) ?? $out;
        $out = preg_replace(
            '#(["\'])https?://(?:www\.)?haiphongtech\.vn(["\'])#i',
            '$1/$2',
            $out
        ) ?? $out;

        // 6. Owner-approved Phase 1 source anomaly remediations (raw_content remains untouched in DB):
        // 6a. Fix malformed email href typo in legal policy page#341002
        $out = str_ireplace(
            ['href="http://kinhdoanhhpt@haiphongtech,vn"', "href='http://kinhdoanhhpt@haiphongtech,vn'", 'haiphongtech,vn'],
            ['href="mailto:kinhdoanhhpt@haiphongtech.vn"', "href='mailto:kinhdoanhhpt@haiphongtech.vn'", 'haiphongtech.vn'],
            $out
        );

        // 6b. Unwrap dead author localhost link (http://127.0.0.1:8000/plc) in post#431392 while keeping anchor text intact
        $out = preg_replace(
            '#<a\b[^>]*\bhref\s*=\s*["\']https?://127\.0\.0\.1(?::\d+)?[^"\']*["\'][^>]*>(.*?)</a>#is',
            '$1',
            $out
        ) ?? $out;

        // 6c. Neutralize legacy Misutech banner link on homepage block
        $out = preg_replace('#(["\'])https?://(?:www\.)?misutech\.vn[^"\']*(["\'])#i', '$1#$2', $out) ?? $out;

        // 7. Owner-approved enterprise contact unification to Footer canonical standard
        //    (Hotline: 0866 555 212 / tel:0866555212 | Email: kinhdoanhhpt@haiphongtech.vn)
        $out = preg_replace('/\btel:(?:\+?84|0)(?:382941465|386836466)\b/i', 'tel:0866555212', $out) ?? $out;
        $out = preg_replace('/\b038[\s\.]*2941[\s\.]*465\b/u', '0866 555 212', $out) ?? $out;
        $out = preg_replace('/\b038[\s\.]*683[\s\.]*6466\b/u', '0866 555 212', $out) ?? $out;

        $out = str_ireplace(
            [
                'mailto:doduchieu.hp@gmail.com',
                'doduchieu.hp@gmail.com',
                'mailto:info@haiphongtech.vn',
                'info@haiphongtech.vn',
            ],
            [
                'mailto:kinhdoanhhpt@haiphongtech.vn',
                'kinhdoanhhpt@haiphongtech.vn',
                'mailto:kinhdoanhhpt@haiphongtech.vn',
                'kinhdoanhhpt@haiphongtech.vn',
            ],
            $out
        );

        return $out;
    }

    /**
     * Strictly sanitize product main HTML description:
     * - Strips scripts, styles, forms, comments, wpautop <br> noise inside tables/lists
     * - Strips ALL classes, inline styles, ids, widths, heights, data-* and junk attributes
     * - Keeps ONLY href on <a> (normalizing internal domain to /...)
     * - Keeps ONLY src and alt on <img> (promoting lazy data-src/data-lazy-src, dropping inline base64 spacers)
     */
    public function cleanProductContentHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Pre-clean comments, executable tags, legacy contact strings & internal absolute URLs
        $pre = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $pre = preg_replace('#<(script|style|noscript|iframe|form|button|textarea|select|svg)\b[^>]*>.*?</\1>#is', '', $pre) ?? $pre;
        $pre = preg_replace('#<(input|link|meta)\b[^>]*>#is', '', $pre) ?? $pre;
        $pre = $this->applyDomainAndContactNormalizations($pre);

        // Remove wpautop stray <br> directly inside structural HTML tables/lists
        $pre = preg_replace('#<(table|thead|tbody|tfoot|tr|ul|ol)\b[^>]*>(?:\s|<br\s*/?>)+#is', '<$1>', $pre) ?? $pre;
        $pre = preg_replace('#(?:\s|<br\s*/?>)+</(table|thead|tbody|tfoot|tr|td|th|ul|ol|li)>#is', '</$1>', $pre) ?? $pre;
        $pre = preg_replace('#</(tr|td|th|thead|tbody|tfoot|li)>(?:\s|<br\s*/?>)+#is', '</$1>', $pre) ?? $pre;

        $allowedTags = [
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'p', 'div', 'section', 'article', 'blockquote', 'pre', 'code',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
            'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            'figure', 'figcaption', 'details', 'summary',
            'strong', 'b', 'em', 'i', 'u', 'sub', 'sup', 's', 'del', 'mark',
            'br', 'hr', 'a', 'img',
        ];
        $unwrapTags = ['span', 'font', 'label', 'center'];

        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="__hpt_root">' . $pre . '</div></body></html>';

        $prevErr = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);

        if (!$loaded) {
            return trim(strip_tags($pre, '<p><h2><h3><h4><ul><ol><li><table><thead><tbody><tfoot><tr><th><td><strong><em><b><i><br><a><img>'));
        }

        $root = $dom->getElementById('__hpt_root');
        if (!$root) {
            return '';
        }

        $this->sanitizeDomChildren($dom, $root, $allowedTags, $unwrapTags);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        // Collapse excessive whitespace and empty structural tags
        $out = preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#iu', '', $out) ?? $out;
        $out = preg_replace('#<div>(?:\s|&nbsp;|<br\s*/?>)*</div>#iu', '', $out) ?? $out;
        $out = preg_replace('/(?:<br\s*\/?>\s*){3,}/i', '<br><br>', $out) ?? $out;
        $out = preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;

        return trim($out);
    }

    /**
     * Clean WooCommerce short_description (post_excerpt): removes wrapper divs/styles while preserving summary & links.
     */
    public function cleanProductShortDescription(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $pre = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $pre = $this->applyDomainAndContactNormalizations($pre);
        $pre = preg_replace('#<(div|span|font)\b[^>]*>#i', '', $pre) ?? $pre;
        $pre = preg_replace('#</(div|span|font)>#i', '<br>', $pre) ?? $pre;

        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="__hpt_short">' . $pre . '</div></body></html>';
        $prevErr = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErr);

        if (!$loaded) {
            return trim(strip_tags($pre, '<p><br><strong><b><em><i><a><ul><ol><li>'));
        }

        $root = $dom->getElementById('__hpt_short');
        if (!$root) {
            return '';
        }

        $this->sanitizeDomChildren(
            $dom,
            $root,
            ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a'],
            ['div', 'span', 'font']
        );

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        $out = preg_replace('/(?:<br\s*\/?>\s*){3,}/i', '<br>', $out) ?? $out;
        $out = preg_replace('/^(?:\s|<br\s*\/?>)+|(?:\s|<br\s*\/?>)+$/i', '', $out) ?? $out;

        return trim($out);
    }

    /**
     * Extract all /wp-content/uploads/... relative image paths inside cleaned HTML.
     *
     * @return array<int, string>
     */
    public function extractContentWpUploadPaths(string $html): array
    {
        $paths = [];
        if ($html !== '' && preg_match_all('#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $m)) {
            foreach ($m[1] as $src) {
                $path = (string) parse_url($src, PHP_URL_PATH);
                if (preg_match('#^/?wp-content/uploads/(.+)$#i', $path, $pm)) {
                    $paths[] = ltrim($pm[1], '/');
                }
            }
        }
        return array_values(array_unique($paths));
    }

    private function applyDomainAndContactNormalizations(string $out): string
    {
        $out = preg_replace('#https?://(?:www\.)?haiphongtech\.vn(/[^"\'\s<>)\],]*)#i', '$1', $out) ?? $out;
        $out = preg_replace('#(["\'])https?://(?:www\.)?haiphongtech\.vn(["\'])#i', '$1/$2', $out) ?? $out;
        $out = preg_replace('#(["\'])https?://(?:www\.)?misutech\.vn[^"\']*(["\'])#i', '$1#$2', $out) ?? $out;
        $out = preg_replace('/\btel:(?:\+?84|0)(?:382941465|386836466)\b/i', 'tel:0866555212', $out) ?? $out;
        $out = preg_replace('/\b038[\s\.]*2941[\s\.]*465\b/u', '0866 555 212', $out) ?? $out;
        $out = preg_replace('/\b038[\s\.]*683[\s\.]*6466\b/u', '0866 555 212', $out) ?? $out;
        $out = str_ireplace(
            ['mailto:doduchieu.hp@gmail.com', 'doduchieu.hp@gmail.com', 'mailto:info@haiphongtech.vn', 'info@haiphongtech.vn'],
            ['mailto:kinhdoanhhpt@haiphongtech.vn', 'kinhdoanhhpt@haiphongtech.vn', 'mailto:kinhdoanhhpt@haiphongtech.vn', 'kinhdoanhhpt@haiphongtech.vn'],
            $out
        );
        return $out;
    }

    private function sanitizeDomChildren(\DOMDocument $dom, \DOMNode $parent, array $allowedTags, array $unwrapTags): void
    {
        $children = [];
        foreach ($parent->childNodes as $c) {
            $children[] = $c;
        }

        foreach ($children as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            /** @var \DOMElement $node */
            $tag = strtolower($node->tagName);

            if (in_array($tag, $unwrapTags, true)) {
                $this->sanitizeDomChildren($dom, $node, $allowedTags, $unwrapTags);
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            if (!in_array($tag, $allowedTags, true)) {
                if (in_array($tag, ['script', 'style', 'noscript', 'iframe', 'input', 'button', 'form', 'textarea', 'select', 'svg'], true)) {
                    $parent->removeChild($node);
                } else {
                    $this->sanitizeDomChildren($dom, $node, $allowedTags, $unwrapTags);
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                continue;
            }

            if ($tag === 'img') {
                $src = trim((string) ($node->getAttribute('data-lazy-src') ?: $node->getAttribute('data-src') ?: $node->getAttribute('src')));
                $origSrc = trim((string) $node->getAttribute('src'));
                if ($origSrc !== '' && !str_starts_with(strtolower($origSrc), 'data:') && !str_starts_with(strtolower($origSrc), 'image/')) {
                    $src = $origSrc;
                    $lazy = trim((string) ($node->getAttribute('data-lazy-src') ?: $node->getAttribute('data-src')));
                    if ($lazy !== '' && !str_starts_with(strtolower($lazy), 'data:')) {
                        $src = $lazy;
                    }
                }
                // Drop tiny inline base64 spacer placeholders or empty images
                if ($src === '' || str_starts_with(strtolower($src), 'data:image/') || str_starts_with(strtolower($src), 'image/png;base64')) {
                    $parent->removeChild($node);
                    continue;
                }
                $src = preg_replace('#^https?://(?:www\.)?haiphongtech\.vn(/wp-content/uploads/.+)$#i', '$1', $src) ?? $src;
                $alt = trim((string) $node->getAttribute('alt'));

                while ($node->attributes->length > 0) {
                    $node->removeAttributeNode($node->attributes->item(0));
                }
                $node->setAttribute('src', $src);
                if ($alt !== '') {
                    $node->setAttribute('alt', $alt);
                }
                continue;
            }

            if ($tag === 'a') {
                $href = trim((string) $node->getAttribute('href'));
                $this->sanitizeDomChildren($dom, $node, $allowedTags, $unwrapTags);
                if ($href === '' || preg_match('#^\s*javascript:#i', $href)) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    continue;
                }
                $href = preg_replace('#^https?://(?:www\.)?haiphongtech\.vn(/.*)$#i', '$1', $href) ?? $href;
                $href = preg_replace('#^https?://(?:www\.)?haiphongtech\.vn$#i', '/', $href) ?? $href;

                while ($node->attributes->length > 0) {
                    $node->removeAttributeNode($node->attributes->item(0));
                }
                $node->setAttribute('href', $href);
                continue;
            }

            // Strip ALL attributes on every other allowed HTML tag
            while ($node->attributes->length > 0) {
                $node->removeAttributeNode($node->attributes->item(0));
            }

            $this->sanitizeDomChildren($dom, $node, $allowedTags, $unwrapTags);
        }
    }
}
