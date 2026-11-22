<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Cron Job import sản phẩm từ CSV chạy nền ngày đêm (nhẹ nhàng, chống quá tải web nguồn):
 * - Mỗi 5 phút chạy 1 lượt, lấy tối đa 15 sản phẩm mới (có thể chỉnh trong .env)
 * - Bắt buộc --cron --only-new: TUYỆT ĐỐI không sửa, không ghi đè SKU đã có
 * - Tự giãn nhịp 1.2s/request tải ảnh-tài liệu và 0.8s/sản phẩm, chống chạy chồng
 */
Schedule::command('products:import-csv', [
    env('HPT_PRODUCTS_CSV_PATH', 'D:\File\Full sản phẩm HPT.csv'),
    '--cron',
    '--only-new',
    '--limit' => (int) env('HPT_IMPORT_CRON_LIMIT', 15),
    '--sleep-ms' => (int) env('HPT_IMPORT_CRON_SLEEP_MS', 1200),
    '--delay-product-ms' => (int) env('HPT_IMPORT_CRON_DELAY_MS', 800),
])
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/products-import-cron.log'));
