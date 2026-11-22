<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo giá #{{ $quote->quote_code }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f6fa; font-family:Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color:#111827;">
    @php
        $companyName = $company->company ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
        $address = $company->address ?? 'Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam';
        $hotline = $company->hotline ?? '0866.555.212';
        $email = $company->email ?? 'kinhdoanhhpt@haiphongtech.vn';
        $website = rtrim(config('app.url', 'https://haiphongtech.vn'), '/');
        $logoUrl = !empty($company->site_logo)
            ? (\Illuminate\Support\Str::startsWith($company->site_logo, ['http://', 'https://']) ? $company->site_logo : $website . '/' . ltrim($company->site_logo, '/'))
            : $website . '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
        $vatPercent = (float) ($quote->vat_percent ?? 10);
        $subtotal = (float) ($quote->subtotal ?? 0);
        $vatAmount = round($subtotal * ($vatPercent / 100));
        $totalAmount = (float) ($quote->total_amount ?? ($subtotal + $vatAmount));
        $validityDays = (int) ($quote->meta_data['validity_days'] ?? 30);
    @endphp

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6fa; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="680" cellpadding="0" cellspacing="0" style="max-width:680px; width:100%; background:#ffffff; border:1px solid #d8e5f1; border-radius:6px; overflow:hidden;">
                    {{-- Top Accent --}}
                    <tr>
                        <td style="height:4px; background:#1e73be; font-size:0; line-height:0;">&nbsp;</td>
                    </tr>

                    {{-- Header --}}
                    <tr>
                        <td style="padding:22px 28px 16px; border-bottom:1px solid #d8e5f1;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td valign="top" style="padding-right:16px;">
                                        <div style="font-size:15px; font-weight:800; color:#1e73be; letter-spacing:0.2px; margin-bottom:5px;">
                                            {{ $companyName }}
                                        </div>
                                        <div style="font-size:12px; color:#475569; line-height:1.55;">
                                            <strong>Địa chỉ:</strong> {{ $address }}<br>
                                            <strong>Hotline:</strong> {{ $hotline }} &nbsp;|&nbsp; <strong>Email:</strong> {{ $email }}<br>
                                            <strong>Website:</strong> <a href="{{ $website }}" style="color:#1e73be; text-decoration:none;">{{ $website }}</a>
                                        </div>
                                    </td>
                                    <td valign="top" align="right" style="width:150px;">
                                        <img src="{{ $logoUrl }}" alt="Hải Phòng Tech" style="max-width:145px; max-height:48px; display:block;">
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Title --}}
                    <tr>
                        <td align="center" style="padding:20px 28px 12px;">
                            <div style="font-size:20px; font-weight:800; color:#111827; letter-spacing:0.4px; margin-bottom:6px;">
                                BẢNG BÁO GIÁ THIẾT BỊ TỰ ĐỘNG HÓA
                            </div>
                            <div style="font-size:12px; color:#475569;">
                                <strong>Số báo giá:</strong> {{ $quote->quote_code }}
                                &nbsp;&bull;&nbsp;
                                <strong>Ngày lập:</strong> {{ optional($quote->created_at)->format('d/m/Y') ?? date('d/m/Y') }}
                                &nbsp;&bull;&nbsp;
                                <strong>Hiệu lực:</strong> {{ $validityDays }} ngày
                            </div>
                        </td>
                    </tr>

                    @if(!empty($staffNote))
                    <tr>
                        <td style="padding:0 28px 14px;">
                            <div style="background:#f4f8fc; border-left:3px solid #1e73be; padding:10px 14px; font-size:13px; color:#1e293b; line-height:1.55;">
                                {!! nl2br(e($staffNote)) !!}
                            </div>
                        </td>
                    </tr>
                    @endif

                    {{-- Customer Info --}}
                    <tr>
                        <td style="padding:0 28px 16px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fbfe; border:1px solid #e2ecf5; border-radius:4px;">
                                <tr>
                                    <td style="padding:12px 16px; font-size:12.5px; color:#1e293b; line-height:1.7;" width="52%" valign="top">
                                        <div><span style="color:#1e73be; font-weight:700;">Kính gửi:</span> {{ $quote->customer_name ?: 'Quý Khách Hàng' }}</div>
                                        @if(!empty($quote->customer_company))
                                            <div><span style="color:#1e73be; font-weight:700;">Đơn vị:</span> {{ $quote->customer_company }}</div>
                                        @endif
                                    </td>
                                    <td style="padding:12px 16px; font-size:12.5px; color:#1e293b; line-height:1.7;" width="48%" valign="top">
                                        <div><span style="color:#1e73be; font-weight:700;">Điện thoại:</span> {{ $quote->customer_phone ?: '---' }}</div>
                                        <div><span style="color:#1e73be; font-weight:700;">Email:</span> {{ $quote->customer_email ?: '---' }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- 5 Essential Columns Table --}}
                    <tr>
                        <td style="padding:0 28px 18px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; border:1px solid #d8e5f1;">
                                <thead>
                                    <tr style="background:#1e73be; color:#ffffff;">
                                        <th align="center" style="padding:10px 8px; font-size:12px; font-weight:700; width:38px; border-right:1px solid rgba(255,255,255,0.2);">STT</th>
                                        <th align="left" style="padding:10px 10px; font-size:12px; font-weight:700; border-right:1px solid rgba(255,255,255,0.2);">Sản phẩm / Mã SP</th>
                                        <th align="center" style="padding:10px 8px; font-size:12px; font-weight:700; width:68px; border-right:1px solid rgba(255,255,255,0.2);">SL</th>
                                        <th align="right" style="padding:10px 10px; font-size:12px; font-weight:700; width:115px; border-right:1px solid rgba(255,255,255,0.2);">Đơn giá</th>
                                        <th align="right" style="padding:10px 10px; font-size:12px; font-weight:700; width:125px;">Thành tiền</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($quote->items as $idx => $item)
                                        <tr style="background:{{ $idx % 2 === 1 ? '#f8fbfe' : '#ffffff' }}; border-bottom:1px solid #e5edf5;">
                                            <td align="center" style="padding:10px 8px; font-size:12.5px; color:#475569;">{{ $idx + 1 }}</td>
                                            <td align="left" style="padding:10px 10px; font-size:12.5px; color:#111827;">
                                                <div style="font-weight:700; color:#111827;">{{ $item->product_name }}</div>
                                                @if(!empty($item->product_sku) || !empty($item->brand_name))
                                                    <div style="font-size:11.5px; color:#475569; margin-top:2px;">
                                                        @if(!empty($item->product_sku)) Mã SP: <strong>{{ $item->product_sku }}</strong> @endif
                                                        @if(!empty($item->product_sku) && !empty($item->brand_name)) &nbsp;|&nbsp; @endif
                                                        @if(!empty($item->brand_name)) Hãng: {{ $item->brand_name }} @endif
                                                    </div>
                                                @endif
                                            </td>
                                            <td align="center" style="padding:10px 8px; font-size:12.5px; color:#111827; white-space:nowrap;">
                                                {{ (int) $item->quantity }} {{ $item->unit ?: 'Cái' }}
                                            </td>
                                            <td align="right" style="padding:10px 10px; font-size:12.5px; color:#1e293b; white-space:nowrap;">
                                                {{ number_format((float) $item->unit_price, 0, ',', '.') }} đ
                                            </td>
                                            <td align="right" style="padding:10px 10px; font-size:12.5px; font-weight:700; color:#1e73be; white-space:nowrap;">
                                                {{ number_format((float) $item->total_price, 0, ',', '.') }} đ
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>

                    {{-- Terms & Totals --}}
                    <tr>
                        <td style="padding:0 28px 22px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td valign="top" width="54%" style="padding-right:14px;">
                                        <div style="background:#f8fbfe; border:1px solid #e2ecf5; border-radius:4px; padding:12px 14px; font-size:12px; color:#334155; line-height:1.6;">
                                            <div style="font-weight:800; color:#1e73be; margin-bottom:6px; font-size:12px;">ĐIỀU KHOẢN THƯƠNG MẠI</div>
                                            <div>&bull; <strong>Chất lượng:</strong> Chính hãng, mới 100%, đầy đủ CO/CQ.</div>
                                            <div>&bull; <strong>Bảo hành:</strong> 12 tháng theo tiêu chuẩn nhà sản xuất.</div>
                                            <div>&bull; <strong>Chứng từ:</strong> Hóa đơn VAT đầy đủ.</div>
                                            <div>&bull; <strong>Giao hàng:</strong> Giao nhanh toàn quốc.</div>
                                        </div>
                                    </td>
                                    <td valign="top" width="46%">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d8e5f1; border-radius:4px; background:#ffffff;">
                                            <tr>
                                                <td style="padding:8px 12px; font-size:12.5px; color:#475569;">Tạm tính:</td>
                                                <td align="right" style="padding:8px 12px; font-size:12.5px; font-weight:700; color:#111827;">{{ number_format($subtotal, 0, ',', '.') }} đ</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:6px 12px; font-size:12.5px; color:#475569; border-top:1px solid #edf2f7;">Thuế VAT ({{ rtrim(rtrim(number_format($vatPercent, 1), '0'), '.') }}%):</td>
                                                <td align="right" style="padding:6px 12px; font-size:12.5px; font-weight:700; color:#111827; border-top:1px solid #edf2f7;">{{ number_format($vatAmount, 0, ',', '.') }} đ</td>
                                            </tr>
                                            <tr style="background:#f4f8fc;">
                                                <td style="padding:10px 12px; font-size:13.5px; font-weight:800; color:#114574; border-top:1px solid #d8e5f1;">TỔNG CỘNG:</td>
                                                <td align="right" style="padding:10px 12px; font-size:15px; font-weight:800; color:#1e73be; border-top:1px solid #d8e5f1;">{{ number_format($totalAmount, 0, ',', '.') }} đ</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background:#f4f8fc; border-top:1px solid #d8e5f1; padding:14px 28px; font-size:12px; color:#475569; text-align:center;">
                            Mọi thắc mắc hoặc xác nhận đặt hàng, Quý khách vui lòng phản hồi trực tiếp email này hoặc gọi Hotline <strong style="color:#1e73be;">{{ $hotline }}</strong>.<br>
                            Trân trọng cảm ơn Quý khách đã tin tưởng {{ $companyName }}!
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
