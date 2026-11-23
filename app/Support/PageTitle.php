<?php

namespace App\Support;

class PageTitle
{
    /**
     * Chuẩn hóa tiêu đề trang theo cấu trúc thống nhất: "{title} | {Brand Name}".
     * Tự động gỡ hậu tố thương hiệu cũ (- Brand, – Brand, | Brand, ...) để không bị lặp.
     */
    public static function format(?string $rawTitle, ?object $settings = null, string $fallbackTitle = ''): string
    {
        $brandName = trim((string) ($settings->name ?? ''));
        if ($brandName === '') {
            $brandName = 'Hải Phòng Tech';
        }

        $companyName = trim((string) ($settings->company ?? ''));

        $title = trim(html_entity_decode((string) $rawTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title === '') {
            $title = trim(html_entity_decode($fallbackTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if ($title === '') {
            return $brandName;
        }

        // Danh sách hậu tố thương hiệu cũ cần gỡ ở cuối tiêu đề để tránh bị lặp
        $suffixCandidates = array_values(array_unique(array_filter([
            $brandName . ' Admin',
            $brandName,
            $companyName,
            'Hải Phòng Tech Admin',
            'Hải Phòng Tech',
            'CÔNG TY TNHH HẢI PHÒNG TECH',
        ])));

        usort($suffixCandidates, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        $escaped = array_map(static fn (string $item): string => preg_quote($item, '/'), $suffixCandidates);
        $pattern = '/\s*[-–—|]\s*(?:' . implode('|', $escaped) . ')\s*$/ui';

        $cleanTitle = trim((string) preg_replace($pattern, '', $title));
        if ($cleanTitle === '') {
            $cleanTitle = $title;
        }

        if (mb_strtolower($cleanTitle, 'UTF-8') === mb_strtolower($brandName, 'UTF-8')) {
            return $brandName;
        }

        return $cleanTitle . ' | ' . $brandName;
    }
}
