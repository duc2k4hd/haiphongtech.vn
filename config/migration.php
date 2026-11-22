<?php

return [
    'phase2' => [
        // Production cutover stays strictly blocked until full WooCommerce parity (~133,036 products) is achieved.
        'full_product_cutover_min_count' => (int) env('HPT_PHASE2_MIN_CUTOVER_PRODUCTS', 130000),
        'sitemap_chunk_size'             => 2000,
        'expected_sitemap_chunks'        => 67,
        'per_page'                       => 24,
        'reserved_boundary_product_slugs'=> ['ee-sx674', 'ca-lh4'],
        'colliding_root_slugs'           => [
            'cam-bien-quang-autonics',
            'cam-bien-omron',
            'cam-bien-laser-keyence',
            'cam-bien-keyence',
            'cam-bien-quang-omron',
            'cam-bien-vung-autonics',
        ],
    ],
];
