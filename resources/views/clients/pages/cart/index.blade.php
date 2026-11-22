@extends('clients.layouts.master')

@php
    $hotlineRaw = preg_replace('/[^0-9]/', '', $settings->phone ?? ($settings->hotline ?? '0866555212'));
    $hotlineDisplay = $settings->hotline ?? '0866.555.212';
    $zaloHref = $settings->zalo ?? ($settings->zalo_url ?? 'https://zalo.me/0866555212');
    $totalPrice = 0;
    $totalQty = 0;
    $hasContactPrice = false;
    foreach ($cartItems as $it) {
        $p = (float) ($it['price'] ?? 0);
        $q = (int) ($it['quantity'] ?? 0);
        $totalQty += $q;
        if ($p > 0) {
            $totalPrice += $p * $q;
        } else {
            $hasContactPrice = true;
        }
    }
@endphp

@section('title', 'Giỏ Hàng - ' . ($settings->company ?? ($settings->name ?? 'CÔNG TY TNHH HẢI PHÒNG TECH')))
@section('meta_description', 'Danh sách sản phẩm bạn đã chọn tại Hải Phòng Tech. Liên hệ trực tiếp qua Hotline, Zalo hoặc form liên hệ để nhận báo giá tốt nhất.')

@push('styles')
    <link rel="stylesheet" href="{{ asset('clients/css/cart.css') }}?v={{ file_exists(public_path('clients/css/cart.css')) ? filemtime(public_path('clients/css/cart.css')) : '1.0' }}">
@endpush

@section('content')
    <div class="misutech_home_container" style="margin-top: 28px; margin-bottom: 52px;">
        <h1 class="misutech_cart_title">Giỏ Hàng Của Bạn</h1>

        <div class="misutech_cart_wrapper">
            <!-- Left: Cart Items -->
            <div class="misutech_cart_items">
                <div class="misutech_cart_header">
                    <div class="misutech_cart_header_col misutech_col_product">Sản Phẩm</div>
                    <div class="misutech_cart_header_col misutech_col_price">Đơn Giá</div>
                    <div class="misutech_cart_header_col misutech_col_qty">Số Lượng</div>
                    <div class="misutech_cart_header_col misutech_col_total">Thành Tiền</div>
                    <div class="misutech_cart_header_col misutech_col_action"></div>
                </div>

                @forelse($cartItems as $item)
                    @php
                        $unitPrice = (float) ($item['price'] ?? 0);
                        $qty = (int) ($item['quantity'] ?? 1);
                        $itemTotal = $unitPrice * $qty;
                    @endphp
                    <div class="misutech_cart_item" data-id="{{ $item['id'] }}" data-price="{{ $unitPrice }}">
                        <div class="misutech_cart_item_info">
                            <img src="{{ $item['thumbnail_url'] ?? asset('storage/clients/imgs/products/no-image.png') }}"
                                alt="{{ $item['name'] }}"
                                class="misutech_cart_item_img"
                                onerror="this.src='{{ asset('storage/clients/imgs/products/no-image.png') }}'">
                            <div class="misutech_cart_item_details">
                                <a href="{{ !empty($item['slug']) ? \App\Support\WpUrl::productUrl($item['slug']) : '#' }}"
                                    class="misutech_cart_item_name">{{ $item['name'] }}</a>
                                @if(!empty($item['sku']))
                                    <span class="misutech_cart_item_attr">Mã SKU: <strong>{{ $item['sku'] }}</strong></span>
                                @endif
                            </div>
                        </div>
                        <div class="misutech_cart_item_price_wrapper">
                            @if($unitPrice > 0)
                                <span class="misutech_cart_current_price">{{ number_format($unitPrice, 0, ',', '.') }}đ</span>
                            @else
                                <span class="misutech_cart_contact_price">Liên hệ</span>
                            @endif
                        </div>
                        <div class="misutech_cart_item_qty_wrapper">
                            <div class="misutech_cart_qty_controls">
                                <button type="button" class="misutech_qty_btn" aria-label="Giảm số lượng">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                </button>
                                <input type="number" class="misutech_qty_input" value="{{ $qty }}" min="1" max="1000">
                                <button type="button" class="misutech_qty_btn" aria-label="Tăng số lượng">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                </button>
                            </div>
                        </div>
                        <div class="misutech_cart_item_total_price">
                            {{ $unitPrice > 0 ? number_format($itemTotal, 0, ',', '.') . 'đ' : 'Liên hệ' }}
                        </div>
                        <div class="misutech_cart_item_action_wrapper">
                            <button type="button" class="misutech_cart_remove_btn" aria-label="Xóa sản phẩm">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="misutech_cart_empty_state">
                        <p>Giỏ hàng của bạn đang trống.</p>
                        <a href="{{ \App\Support\WpUrl::shopUrl() }}" class="misutech_cart_empty_btn">Khám phá sản phẩm ngay</a>
                    </div>
                @endforelse

                <div class="misutech_cart_bottom_actions">
                    <a href="{{ \App\Support\WpUrl::shopUrl() }}" class="misutech_cart_continue_btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Tiếp Tục Xem Sản Phẩm
                    </a>
                    @if(!empty($cartItems))
                        <button type="button" class="misutech_cart_clear_btn" id="misutechCartClearBtn">Xóa Toàn Bộ Giỏ Hàng</button>
                    @endif
                </div>
            </div>

            <!-- Right: Contact & Quote Summary (No Online Checkout) -->
            <div class="misutech_cart_summary">
                <h3 class="misutech_cart_summary_title">Thông Tin Giỏ Hàng</h3>

                <div class="misutech_cart_summary_list">
                    <div class="misutech_cart_summary_row">
                        <span class="misutech_cart_summary_label">Tổng số sản phẩm</span>
                        <span class="misutech_cart_summary_value" id="misutechCartTotalQty">{{ $totalQty }} sản phẩm</span>
                    </div>
                    <div class="misutech_cart_summary_row">
                        <span class="misutech_cart_summary_label">Hình thức đặt hàng</span>
                        <span class="misutech_cart_summary_value misutech_free_shipping">Liên hệ / Báo giá</span>
                    </div>
                </div>

                <div class="misutech_cart_summary_total_row">
                    <span class="misutech_cart_summary_total_label">Tạm tính</span>
                    <div class="misutech_cart_summary_total_price_box">
                        <span class="misutech_cart_summary_total_price" id="misutechCartTotalPrice">
                            @if($totalPrice > 0)
                                {{ number_format($totalPrice, 0, ',', '.') }}đ
                            @else
                                Liên hệ báo giá
                            @endif
                        </span>
                        <small>{{ $hasContactPrice && $totalPrice > 0 ? '(Chưa gồm SP giá liên hệ)' : '(Chưa bao gồm VAT)' }}</small>
                    </div>
                </div>

                <div class="misutech_cart_contact_notice">
                    Website chỉ hỗ trợ <strong>xem giỏ hàng và đặt hàng qua liên hệ trực tiếp</strong> (không thanh toán trực tuyến). Vui lòng liên hệ để được kỹ sư tư vấn và nhận báo giá tốt nhất.
                </div>

                <div class="misutech_cart_checkout_wrapper">
                    <a href="/lien-he/" class="misutech_cart_checkout_btn">
                        Gửi Yêu Cầu Liên Hệ / Báo Giá
                    </a>
                    <a href="tel:{{ $hotlineRaw }}" class="misutech_cart_secondary_btn">
                        ☎ Gọi Hotline: {{ $hotlineDisplay }}
                    </a>
                    <a href="{{ $zaloHref }}" target="_blank" rel="noopener noreferrer" class="misutech_cart_outline_btn">
                        Chat Zalo Nhận Báo Giá Nhanh
                    </a>

                    <div class="misutech_cart_trust_info">
                        <div class="misutech_cart_trust_item">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            Hàng chính hãng 100%, đầy đủ CO/CQ
                        </div>
                        <div class="misutech_cart_trust_item">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                            Hỗ trợ kỹ thuật & giao hàng toàn quốc
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
        const totalQtyEl = document.getElementById("misutechCartTotalQty");
        const totalPriceEl = document.getElementById("misutechCartTotalPrice");
        const headerCartLabel = document.getElementById("misutechCartHeaderLabel");

        function formatMoney(amount) {
            return new Intl.NumberFormat("vi-VN").format(amount) + "đ";
        }

        function recalcSummary() {
            let sumQty = 0;
            let sumPrice = 0;
            document.querySelectorAll(".misutech_cart_item").forEach((it) => {
                const p = parseFloat(it.dataset.price) || 0;
                const q = parseInt(it.querySelector(".misutech_qty_input")?.value, 10) || 0;
                sumQty += q;
                if (p > 0) sumPrice += p * q;
            });
            if (totalQtyEl) totalQtyEl.textContent = sumQty + " sản phẩm";
            if (totalPriceEl) totalPriceEl.textContent = sumPrice > 0 ? formatMoney(sumPrice) : "Liên hệ báo giá";
            document.querySelectorAll(".misutech_home_cart_count").forEach((el) => {
                el.textContent = String(sumQty);
            });
            if (headerCartLabel) headerCartLabel.textContent = sumQty + " sản phẩm";
        }

        document.querySelectorAll(".misutech_cart_item").forEach((item) => {
            const input = item.querySelector(".misutech_qty_input");
            const btns = item.querySelectorAll(".misutech_qty_btn");
            const productId = item.dataset.id;
            const price = parseFloat(item.dataset.price) || 0;
            const totalEl = item.querySelector(".misutech_cart_item_total_price");
            let updateTimer;

            function scheduleUpdate(newQty) {
                if (isNaN(newQty) || newQty < 0) newQty = 0;
                if (newQty > 1000) newQty = 1000;
                input.value = newQty;
                if (totalEl) {
                    totalEl.textContent = price > 0 ? formatMoney(price * newQty) : "Liên hệ";
                }
                recalcSummary();

                clearTimeout(updateTimer);
                updateTimer = setTimeout(() => {
                    fetch("/api/v1/cart/update", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "Accept": "application/json",
                            "X-CSRF-TOKEN": csrfToken,
                        },
                        body: JSON.stringify({ product_id: productId, quantity: newQty }),
                    })
                        .then((res) => res.json())
                        .then((data) => {
                            if (data.success && newQty === 0) {
                                item.remove();
                                if (document.querySelectorAll(".misutech_cart_item").length === 0) {
                                    location.reload();
                                }
                            }
                        })
                        .catch((err) => console.error(err));
                }, 400);
            }

            if (btns.length >= 2) {
                btns[0].addEventListener("click", () => scheduleUpdate((parseInt(input.value, 10) || 1) - 1));
                btns[1].addEventListener("click", () => scheduleUpdate((parseInt(input.value, 10) || 1) + 1));
            }
            input.addEventListener("change", () => scheduleUpdate(parseInt(input.value, 10)));
        });

        document.querySelectorAll(".misutech_cart_remove_btn").forEach((btn) => {
            btn.addEventListener("click", function() {
                const item = this.closest(".misutech_cart_item");
                const productId = item?.dataset.id;
                if (!productId) return;
                if (confirm("Bạn có chắc chắn muốn xóa sản phẩm này khỏi giỏ hàng?")) {
                    fetch("/api/v1/cart/update", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "Accept": "application/json",
                            "X-CSRF-TOKEN": csrfToken,
                        },
                        body: JSON.stringify({ product_id: productId, quantity: 0 }),
                    }).then(() => location.reload());
                }
            });
        });

        document.getElementById("misutechCartClearBtn")?.addEventListener("click", () => {
            if (confirm("Bạn có chắc chắn muốn xóa toàn bộ giỏ hàng?")) {
                fetch("/api/v1/cart/clear", {
                    method: "POST",
                    headers: {
                        "Accept": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                    },
                }).then(() => location.reload());
            }
        });
    });
</script>
@endpush
