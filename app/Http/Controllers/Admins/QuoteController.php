<?php

namespace App\Http\Controllers\Admins;

use App\Http\Controllers\Controller;
use App\Mail\QuoteMail;
use App\Models\Contact;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Support\MailConfigHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class QuoteController extends Controller
{
    /**
     * Hiển thị trang quản lý Báo giá dự án & sản phẩm.
     */
    public function index()
    {
        return view('admins.pages.quotes.index');
    }

    public function apiList(Request $request)
    {
        try {
            $query = Quote::with(['items' => function($q) {
                $q->orderBy('id', 'asc');
            }]);

            if ($request->filled('keyword')) {
                $kw = trim($request->keyword);
                $query->where(function($q) use ($kw) {
                    $q->where('quote_code', 'like', "%{$kw}%")
                      ->orWhere('customer_name', 'like', "%{$kw}%")
                      ->orWhere('customer_phone', 'like', "%{$kw}%")
                      ->orWhere('customer_company', 'like', "%{$kw}%")
                      ->orWhere('customer_email', 'like', "%{$kw}%")
                      ->orWhereHas('items', function($iq) use ($kw) {
                          $iq->where('product_name', 'like', "%{$kw}%")
                             ->orWhere('product_sku', 'like', "%{$kw}%");
                      });
                });
            }

            if ($request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            $sort = $request->input('sort', 'latest');
            if ($sort === 'oldest') {
                $query->orderBy('created_at', 'asc');
            } elseif ($sort === 'amount_desc') {
                $query->orderBy('total_amount', 'desc');
            } elseif ($sort === 'amount_asc') {
                $query->orderBy('total_amount', 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $quotes = $query->get();

            // Thống kê nhanh và đếm từng trạng thái
            $stats = [
                'total'          => Quote::count(),
                'total_amount'   => (float) Quote::sum('total_amount'),
                'draft'          => Quote::where('status', 'draft')->count(),
                'sent'           => Quote::where('status', 'sent')->count(),
                'confirmed'      => Quote::where('status', 'confirmed')->count(),
                'completed'      => Quote::where('status', 'completed')->count(),
                'cancelled'      => Quote::where('status', 'cancelled')->count(),
            ];

            return response()->json([
                'data'  => array_values($quotes->toArray()),
                'stats' => $stats
            ]);
        } catch (\Throwable $e) {
            \Log::error('Quote apiList error: ' . $e->getMessage());
            return response()->json([
                'data'  => [],
                'stats' => ['total' => 0, 'total_amount' => 0, 'draft' => 0, 'sent' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0],
                'error' => $e->getMessage()
            ], 200);
        }
    }

    /**
     * API Chi tiết một báo giá kèm danh sách sản phẩm quote_items.
     */
    public function apiShow($id)
    {
        $quote = Quote::with('items.product')->findOrFail($id);
        return response()->json(['data' => $quote]);
    }

    /**
     * API Cập nhật trạng thái và ghi chú báo giá.
     */
    public function apiUpdateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:draft,sent,confirmed,completed,cancelled',
            'notes'  => 'nullable|string|max:1000',
        ]);

        $quote = Quote::findOrFail($id);
        $quote->status = $request->status;
        if ($request->has('notes')) {
            $quote->notes = $request->notes;
        }
        $quote->save();

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật trạng thái báo giá thành công!',
            'data'    => $quote
        ]);
    }

    /**
     * API Xóa báo giá.
     */
    public function apiDestroy($id)
    {
        $quote = Quote::findOrFail($id);
        $quote->items()->delete();
        $quote->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa bản ghi báo giá thành công!'
        ]);
    }

    /**
     * API Lập/Cập nhật báo giá & gửi trực tiếp về email khách hàng (kèm ảnh báo giá PNG).
     */
    public function apiSendEmail(Request $request)
    {
        $validated = $request->validate([
            'quote_id'             => 'nullable|integer|exists:quotes,id',
            'contact_id'           => 'nullable|integer|exists:contacts,id',
            'quote_code'           => 'nullable|string|max:60',
            'customer_name'        => 'required|string|max:120',
            'customer_email'       => 'required|email|max:160',
            'customer_phone'       => 'nullable|string|max:30',
            'customer_company'     => 'nullable|string|max:180',
            'vat_percent'          => 'nullable|numeric|min:0|max:30',
            'validity_days'        => 'nullable|integer|min:1|max:365',
            'staff_note'           => 'nullable|string|max:2000',
            'quote_image_base64'   => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'nullable|integer',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_sku'  => 'nullable|string|max:100',
            'items.*.brand_name'   => 'nullable|string|max:120',
            'items.*.unit'         => 'nullable|string|max:40',
            'items.*.quantity'     => 'required|integer|min:1|max:100000',
            'items.*.unit_price'   => 'required|numeric|min:0',
        ], [
            'customer_name.required'  => 'Vui lòng nhập tên khách hàng.',
            'customer_email.required' => 'Vui lòng nhập email khách hàng.',
            'customer_email.email'    => 'Địa chỉ email khách hàng không hợp lệ.',
            'items.required'          => 'Vui lòng thêm ít nhất 1 sản phẩm báo giá.',
        ]);

        $vatPercent = (float) ($validated['vat_percent'] ?? 10);
        $validityDays = (int) ($validated['validity_days'] ?? 30);
        $subtotal = 0;
        $preparedItems = [];

        foreach ($validated['items'] as $item) {
            $qty = max(1, (int) $item['quantity']);
            $unitPrice = max(0, (float) $item['unit_price']);
            $lineTotal = $qty * $unitPrice;
            $subtotal += $lineTotal;

            $preparedItems[] = [
                'product_id'   => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                'product_name' => trim((string) $item['product_name']),
                'product_sku'  => trim((string) ($item['product_sku'] ?? '')),
                'brand_name'   => trim((string) ($item['brand_name'] ?? '')),
                'unit'         => trim((string) ($item['unit'] ?? 'Cái')) ?: 'Cái',
                'quantity'     => $qty,
                'unit_price'   => $unitPrice,
                'total_price'  => $lineTotal,
            ];
        }

        $totalAmount = round($subtotal * (1 + $vatPercent / 100));

        $quote = DB::transaction(function () use ($validated, $preparedItems, $subtotal, $vatPercent, $totalAmount, $validityDays) {
            $quote = !empty($validated['quote_id']) ? Quote::find($validated['quote_id']) : null;
            if (!$quote) {
                $quote = new Quote();
                $code = trim((string) ($validated['quote_code'] ?? ''));
                if ($code === '' || Quote::where('quote_code', $code)->exists()) {
                    $code = 'BG-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
                }
                $quote->quote_code = $code;
            }

            $meta = is_array($quote->meta_data) ? $quote->meta_data : [];
            $meta['validity_days'] = $validityDays;
            $meta['last_emailed_at'] = now()->toDateTimeString();
            if (!empty($validated['contact_id'])) {
                $meta['contact_id'] = (int) $validated['contact_id'];
            }

            $quote->fill([
                'customer_name'    => trim((string) $validated['customer_name']),
                'customer_email'   => trim((string) $validated['customer_email']),
                'customer_phone'   => trim((string) ($validated['customer_phone'] ?? '')),
                'customer_company' => trim((string) ($validated['customer_company'] ?? '')),
                'subtotal'         => $subtotal,
                'vat_percent'      => $vatPercent,
                'total_amount'     => $totalAmount,
                'items_count'      => count($preparedItems),
                'notes'            => $validated['staff_note'] ?? $quote->notes,
                'action_type'      => 'admin_email_quote',
                'status'           => 'sent',
                'meta_data'        => $meta,
            ]);
            $quote->save();

            $quote->items()->delete();
            $quote->items()->createMany($preparedItems);

            if (!empty($validated['contact_id'])) {
                Contact::where('id', (int) $validated['contact_id'])
                    ->where('status', 'pending')
                    ->update(['status' => 'contacted']);
            }

            return $quote->load('items');
        });

        // Decode optional PNG attachment
        $pngBinary = null;
        if (!empty($validated['quote_image_base64']) && str_starts_with($validated['quote_image_base64'], 'data:image/png;base64,')) {
            $raw = base64_decode(substr($validated['quote_image_base64'], 22), true);
            if ($raw !== false && strlen($raw) < 6 * 1024 * 1024) {
                $pngBinary = $raw;
            }
        }

        $settings = (object) DB::table('settings')->pluck('value', 'key')->toArray();
        MailConfigHelper::applyFromSettings();

        try {
            Mail::to($quote->customer_email)->send(new QuoteMail(
                $quote,
                $settings,
                $pngBinary,
                $validated['staff_note'] ?? null
            ));
        } catch (\Throwable $e) {
            \Log::error('QuoteMail send failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Đã lưu báo giá #' . $quote->quote_code . ' nhưng gửi email thất bại: ' . $e->getMessage(),
                'quote_id' => $quote->id,
                'quote_code' => $quote->quote_code,
            ], 500);
        }

        return response()->json([
            'success'    => true,
            'message'    => 'Đã gửi báo giá #' . $quote->quote_code . ' thành công tới ' . $quote->customer_email . '!',
            'quote_id'   => $quote->id,
            'quote_code' => $quote->quote_code,
        ]);
    }

    /**
     * API Xóa hàng loạt báo giá.
     */
    public function apiBulkDestroy(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'exists:quotes,id'
        ]);

        QuoteItem::whereIn('quote_id', $request->ids)->delete();
        Quote::whereIn('id', $request->ids)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đã xóa các bản ghi báo giá được chọn!'
        ]);
    }
}
