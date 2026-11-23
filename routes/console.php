<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Cron Job import sản phẩm từ CSV tốc độ cao (mục tiêu > 130.000 sản phẩm / ngày):
 * - Chạy liên tục mỗi phút (withoutOverlapping tự chống chạy chồng giữa các lượt)
 * - Mỗi lượt xử lý lô lớn 500 sản phẩm mới, tải ảnh/catalog song song 12 kết nối đồng thời
 * - Bắt buộc --cron --only-new: TUYỆT ĐỐI không sửa, không ghi đè SKU đã có
 */
Schedule::command('products:import-csv', [
    env('HPT_PRODUCTS_CSV_PATH', 'public/Full sản phẩm HPT.csv'),
    '--cron',
    '--only-new',
    '--limit' => (int) env('HPT_IMPORT_CRON_LIMIT', 500),
    '--concurrency' => (int) env('HPT_IMPORT_CRON_CONCURRENCY', 12),
    '--sleep-ms' => (int) env('HPT_IMPORT_CRON_SLEEP_MS', 0),
    '--delay-product-ms' => (int) env('HPT_IMPORT_CRON_DELAY_MS', 0),
])
    ->everyMinute()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/products-import-cron.log'));
