@php
    $qSettings = \Illuminate\Support\Facades\DB::table('settings')->pluck('value', 'key');
    $qCompanyName = $qSettings['company'] ?? 'CÔNG TY TNHH HẢI PHÒNG TECH';
    $qAddress = $qSettings['address'] ?? 'Số 250 Đường Đại Thắng, Phường Dương Kinh, Thành phố Hải Phòng, Việt Nam';
    $qHotline = $qSettings['hotline'] ?? ($qSettings['phone'] ?? '0866.555.212');
    $qEmail = $qSettings['email'] ?? 'kinhdoanhhpt@haiphongtech.vn';
    $qLogo = !empty($qSettings['site_logo'])
        ? (\Illuminate\Support\Str::startsWith($qSettings['site_logo'], ['http://', 'https://', '/']) ? $qSettings['site_logo'] : asset('storage/clients/imgs/settings/' . $qSettings['site_logo']))
        : '/wp-content/uploads/2025/03/logo-hai-phong-tech.webp';
@endphp

<!-- Modal Lập & Gửi Báo Giá Nhanh Qua Email -->
<div class="modal fade" id="adminSendQuoteModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 1180px;" role="document">
        <div class="modal-content border-0" style="border-radius: 8px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.2);">
            <div class="modal-header text-white px-4 py-3" style="background: #1e73be;">
                <h5 class="modal-title font-weight-bold mb-0 d-flex align-items-center" style="font-size: 16px;">
                    <i class="fa fa-paper-plane mr-2"></i> Lập &amp; Gửi Báo Giá Qua Email Cho Khách Hàng
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" style="opacity: .9;">&times;</button>
            </div>

            <div class="modal-body p-0" style="background: #f4f7fa;">
                <div class="row no-gutters">
                    {{-- Cột trái: Form nhập nhanh cho nhân viên --}}
                    <div class="col-lg-5 p-3 p-md-4 bg-white" style="border-right: 1px solid #e2e8f0; max-height: 78vh; overflow-y: auto;">
                        <input type="hidden" id="sq_quote_id" value="">
                        <input type="hidden" id="sq_contact_id" value="">

                        <div class="mb-3">
                            <h6 class="font-weight-bold text-uppercase mb-2" style="font-size: 12px; color: #1e73be; letter-spacing: .4px;">
                                1. Thông tin khách nhận báo giá
                            </h6>
                            <div class="form-row">
                                <div class="form-group col-md-6 mb-2">
                                    <label class="small font-weight-bold mb-1">Tên khách hàng <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="sq_customer_name" placeholder="Anh Tuấn / Công ty ABC" oninput="sqRenderPreview()">
                                </div>
                                <div class="form-group col-md-6 mb-2">
                                    <label class="small font-weight-bold mb-1">Email nhận báo giá <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control form-control-sm" id="sq_customer_email" placeholder="khachhang@company.com" oninput="sqRenderPreview()">
                                </div>
                                <div class="form-group col-md-6 mb-2">
                                    <label class="small font-weight-bold mb-1">Số điện thoại</label>
                                    <input type="text" class="form-control form-control-sm" id="sq_customer_phone" placeholder="09xx xxx xxx" oninput="sqRenderPreview()">
                                </div>
                                <div class="form-group col-md-6 mb-2">
                                    <label class="small font-weight-bold mb-1">Công ty / Đơn vị</label>
                                    <input type="text" class="form-control form-control-sm" id="sq_customer_company" placeholder="Tên công ty (tùy chọn)" oninput="sqRenderPreview()">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="font-weight-bold text-uppercase mb-0" style="font-size: 12px; color: #1e73be; letter-spacing: .4px;">
                                    2. Danh sách sản phẩm báo giá
                                </h6>
                                <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold" onclick="sqAddEmptyItem()">
                                    <i class="fa fa-plus"></i> Thêm dòng
                                </button>
                            </div>

                            {{-- Tìm nhanh sản phẩm từ DB --}}
                            <div class="position-relative mb-2">
                                <input type="text" class="form-control form-control-sm" id="sq_product_search" placeholder="Gõ tên hoặc mã SKU để chọn nhanh sản phẩm từ kho...">
                                <div id="sq_product_dropdown" class="list-group position-absolute w-100 shadow-sm" style="z-index: 50; display:none; max-height: 200px; overflow-y: auto; top: 100%;"></div>
                            </div>

                            <div id="sq_items_editor" class="d-flex flex-column" style="gap: 8px;"></div>
                        </div>

                        <div class="mb-2">
                            <h6 class="font-weight-bold text-uppercase mb-2" style="font-size: 12px; color: #1e73be; letter-spacing: .4px;">
                                3. Tùy chọn thuế &amp; lời nhắn
                            </h6>
                            <div class="form-row">
                                <div class="form-group col-6 mb-2">
                                    <label class="small font-weight-bold mb-1">Thuế VAT (%)</label>
                                    <select class="form-control form-control-sm" id="sq_vat_percent" onchange="sqRenderPreview()">
                                        <option value="10" selected>VAT 10%</option>
                                        <option value="8">VAT 8%</option>
                                        <option value="0">Không VAT (0%)</option>
                                    </select>
                                </div>
                                <div class="form-group col-6 mb-2">
                                    <label class="small font-weight-bold mb-1">Hiệu lực (ngày)</label>
                                    <input type="number" class="form-control form-control-sm" id="sq_validity_days" value="30" min="1" max="365" oninput="sqRenderPreview()">
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="small font-weight-bold mb-1">Lời nhắn kèm email (tùy chọn)</label>
                                <textarea class="form-control form-control-sm" id="sq_staff_note" rows="2" placeholder="Ví dụ: Hải Phòng Tech kính gửi anh/chị bảng báo giá chi tiết theo yêu cầu..."></textarea>
                            </div>
                        </div>
                    </div>

                    {{-- Cột phải: Xem trước trực tiếp ảnh Báo Giá (5 cột gọn đẹp) --}}
                    <div class="col-lg-7 p-3 p-md-4 d-flex flex-column align-items-center" style="max-height: 78vh; overflow-y: auto;">
                        <div class="w-100 d-flex justify-content-between align-items-center mb-2">
                            <span class="small font-weight-bold text-muted">
                                <i class="fa fa-eye mr-1 text-primary"></i> Xem trước ảnh báo giá đính kèm Email (5 cột gọn đẹp)
                            </span>
                            <button type="button" class="btn btn-xs btn-outline-secondary font-weight-bold" onclick="sqDownloadPng()">
                                <i class="fa fa-download mr-1"></i> Tải ảnh (.PNG)
                            </button>
                        </div>

                        <div id="sq_capture_sheet" style="width: 100%; max-width: 650px; background: #ffffff; border: 1px solid #d8e5f1; border-radius: 6px; overflow: hidden; color: #111827; font-family: Inter, Arial, sans-serif; box-shadow: 0 6px 20px rgba(17, 69, 116, 0.08);">
                            <div style="height: 4px; background: #1e73be;"></div>
                            <div style="padding: 20px 24px 14px; border-bottom: 1px solid #d8e5f1; display: flex; justify-content: space-between; align-items: flex-start; gap: 14px;">
                                <div>
                                    <div style="font-size: 14px; font-weight: 800; color: #1e73be; margin-bottom: 4px;">{{ $qCompanyName }}</div>
                                    <div style="font-size: 11px; color: #475569; line-height: 1.55;">
                                        <strong>Địa chỉ:</strong> {{ $qAddress }}<br>
                                        <strong>Hotline:</strong> {{ $qHotline }} &nbsp;|&nbsp; <strong>Email:</strong> {{ $qEmail }}
                                    </div>
                                </div>
                                <img src="{{ $qLogo }}" alt="Hải Phòng Tech" crossorigin="anonymous" style="max-width: 135px; max-height: 44px; object-fit: contain;">
                            </div>

                            <div style="padding: 16px 24px 10px; text-align: center;">
                                <div style="font-size: 18px; font-weight: 800; color: #111827; letter-spacing: 0.3px;">BẢNG BÁO GIÁ THIẾT BỊ TỰ ĐỘNG HÓA</div>
                                <div style="font-size: 11px; color: #475569; margin-top: 4px;">
                                    <strong>Số:</strong> <span id="sq_pv_code">BG-{{ date('Ymd') }}-01</span>
                                    &nbsp;&bull;&nbsp; <strong>Ngày lập:</strong> {{ date('d/m/Y') }}
                                    &nbsp;&bull;&nbsp; <strong>Hiệu lực:</strong> <span id="sq_pv_validity">30</span> ngày
                                </div>
                            </div>

                            <div style="padding: 0 24px 14px;">
                                <div style="background: #f8fbfe; border: 1px solid #e2ecf5; border-radius: 4px; padding: 10px 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; font-size: 11.5px; color: #1e293b;">
                                    <div><strong style="color:#1e73be;">Kính gửi:</strong> <span id="sq_pv_name">Quý Khách Hàng</span></div>
                                    <div><strong style="color:#1e73be;">Điện thoại:</strong> <span id="sq_pv_phone">---</span></div>
                                    <div><strong style="color:#1e73be;">Đơn vị:</strong> <span id="sq_pv_company">---</span></div>
                                    <div><strong style="color:#1e73be;">Email:</strong> <span id="sq_pv_email">---</span></div>
                                </div>
                            </div>

                            <div style="padding: 0 24px 14px;">
                                <table style="width: 100%; border-collapse: collapse; border: 1px solid #d8e5f1; font-size: 11.5px;">
                                    <thead>
                                        <tr style="background: #1e73be; color: #ffffff;">
                                            <th style="padding: 8px 6px; text-align: center; width: 34px; font-weight: 700;">STT</th>
                                            <th style="padding: 8px 8px; text-align: left; font-weight: 700;">Sản phẩm / Mã SP</th>
                                            <th style="padding: 8px 6px; text-align: center; width: 62px; font-weight: 700;">SL</th>
                                            <th style="padding: 8px 8px; text-align: right; width: 96px; font-weight: 700;">Đơn giá</th>
                                            <th style="padding: 8px 8px; text-align: right; width: 108px; font-weight: 700;">Thành tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sq_pv_tbody"></tbody>
                                </table>
                            </div>

                            <div style="padding: 0 24px 18px; display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 12px; align-items: start;">
                                <div style="background: #f8fbfe; border: 1px solid #e2ecf5; border-radius: 4px; padding: 10px 12px; font-size: 10.5px; color: #334155; line-height: 1.55;">
                                    <div style="font-weight: 800; color: #1e73be; margin-bottom: 4px;">ĐIỀU KHOẢN THƯƠNG MẠI</div>
                                    <div>• <strong>Chất lượng:</strong> Chính hãng, mới 100%, CO/CQ.</div>
                                    <div>• <strong>Bảo hành:</strong> 12 tháng theo tiêu chuẩn hãng.</div>
                                    <div>• <strong>Chứng từ:</strong> Hóa đơn VAT đầy đủ.</div>
                                    <div>• <strong>Giao hàng:</strong> Hỗ trợ giao nhanh toàn quốc.</div>
                                </div>

                                <div style="border: 1px solid #d8e5f1; border-radius: 4px; overflow: hidden; font-size: 11.5px;">
                                    <div style="display:flex; justify-content:space-between; padding:7px 10px; border-bottom:1px solid #edf2f7;">
                                        <span style="color:#475569;">Tạm tính:</span>
                                        <strong id="sq_pv_subtotal">0 đ</strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; padding:7px 10px; border-bottom:1px solid #d8e5f1;">
                                        <span style="color:#475569;">VAT (<span id="sq_pv_vat_label">10</span>%):</span>
                                        <strong id="sq_pv_vat_amount">0 đ</strong>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; padding:9px 10px; background:#f4f8fc;">
                                        <strong style="color:#114574;">TỔNG CỘNG:</strong>
                                        <strong id="sq_pv_total" style="color:#1e73be; font-size: 13px;">0 đ</strong>
                                    </div>
                                </div>
                            </div>

                            <div style="padding: 10px 24px 16px; display: flex; justify-content: space-between; text-align: center; font-size: 11px; color: #475569;">
                                <div style="width: 45%;">
                                    <div style="font-weight: 700; color: #114574;">ĐẠI DIỆN KHÁCH HÀNG</div>
                                    <div style="font-size: 10px; margin-top: 2px;">(Ký, ghi rõ họ tên)</div>
                                </div>
                                <div style="width: 45%;">
                                    <div style="font-weight: 700; color: #1e73be;">{{ $qCompanyName }}</div>
                                    <div style="font-size: 10px; margin-top: 2px;">Phòng Kinh Doanh &amp; Dự Án</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-white px-4 py-3 d-flex justify-content-between align-items-center" style="border-top: 1px solid #e2e8f0;">
                <span class="small text-muted" id="sq_status_hint">
                    <i class="fa fa-info-circle text-primary mr-1"></i> Email sẽ đính kèm ảnh báo giá (.PNG) + bảng báo giá HTML đầy đủ.
                </span>
                <div>
                    <button type="button" class="btn btn-light btn-sm px-3 mr-2" data-dismiss="modal">Đóng</button>
                    <button type="button" class="btn btn-primary btn-sm px-4 font-weight-bold" id="sq_btn_send_email" style="background: #1e73be; border-color: #1e73be;" onclick="sqSubmitAndSendEmail()">
                        <i class="fa fa-paper-plane mr-1"></i> Gửi Báo Giá Về Email
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js" defer></script>
<script>
(function () {
    let sqItems = [];
    let sqSearchTimer = null;

    function sqFmtMoney(val) {
        return new Intl.NumberFormat('vi-VN').format(Math.round(Number(val) || 0)) + ' đ';
    }

    function sqEsc(str) {
        return String(str || '').replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
    }

    window.openAdminSendQuoteModal = function (preset) {
        preset = preset || {};
        $('#sq_quote_id').val(preset.quote_id || '');
        $('#sq_contact_id').val(preset.contact_id || '');
        $('#sq_customer_name').val(preset.customer_name || '');
        $('#sq_customer_email').val(preset.customer_email || '');
        $('#sq_customer_phone').val(preset.customer_phone || '');
        $('#sq_customer_company').val(preset.customer_company || '');
        $('#sq_vat_percent').val(preset.vat_percent !== undefined ? String(preset.vat_percent) : '10');
        $('#sq_validity_days').val(preset.validity_days || 30);
        $('#sq_staff_note').val(preset.staff_note || '');
        $('#sq_pv_code').text(preset.quote_code || ('BG-' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '-' + String(Math.floor(100 + Math.random() * 900))));

        if (Array.isArray(preset.items) && preset.items.length > 0) {
            sqItems = preset.items.map(it => ({
                product_id: it.product_id || null,
                product_name: it.product_name || '',
                product_sku: it.product_sku || '',
                brand_name: it.brand_name || '',
                unit: it.unit || 'Cái',
                quantity: Math.max(1, parseInt(it.quantity, 10) || 1),
                unit_price: Math.max(0, parseFloat(it.unit_price) || 0),
            }));
        } else {
            sqItems = [{
                product_id: null,
                product_name: preset.default_product_name || '',
                product_sku: preset.default_product_sku || '',
                brand_name: '',
                unit: 'Cái',
                quantity: 1,
                unit_price: 0,
            }];
        }

        sqRenderEditor();
        sqRenderPreview();
        $('#adminSendQuoteModal').modal('show');
    };

    window.sqAddEmptyItem = function () {
        sqItems.push({ product_id: null, product_name: '', product_sku: '', brand_name: '', unit: 'Cái', quantity: 1, unit_price: 0 });
        sqRenderEditor();
        sqRenderPreview();
    };

    window.sqRemoveItem = function (idx) {
        sqItems.splice(idx, 1);
        if (sqItems.length === 0) sqAddEmptyItem();
        else { sqRenderEditor(); sqRenderPreview(); }
    };

    window.sqUpdateItemField = function (idx, field, value) {
        if (!sqItems[idx]) return;
        if (field === 'quantity') sqItems[idx][field] = Math.max(1, parseInt(value, 10) || 1);
        else if (field === 'unit_price') sqItems[idx][field] = Math.max(0, parseFloat(value) || 0);
        else sqItems[idx][field] = value;
        sqRenderPreview();
    };

    function sqRenderEditor() {
        const container = $('#sq_items_editor');
        container.empty();
        sqItems.forEach((it, idx) => {
            container.append(`
                <div class="p-2 border rounded" style="background: #f8fbfe; border-color: #d8e5f1 !important;">
                    <div class="d-flex align-items-center mb-1" style="gap: 6px;">
                        <span class="badge badge-primary" style="background:#1e73be;">#${idx + 1}</span>
                        <input type="text" class="form-control form-control-sm" placeholder="Tên sản phẩm *" value="${sqEsc(it.product_name)}" oninput="sqUpdateItemField(${idx}, 'product_name', this.value)">
                        <button type="button" class="btn btn-xs btn-outline-danger" onclick="sqRemoveItem(${idx})" title="Xóa dòng">&times;</button>
                    </div>
                    <div class="form-row">
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" placeholder="Mã SP / SKU" value="${sqEsc(it.product_sku)}" oninput="sqUpdateItemField(${idx}, 'product_sku', this.value)">
                        </div>
                        <div class="col-2">
                            <input type="number" class="form-control form-control-sm" placeholder="SL" min="1" value="${it.quantity}" oninput="sqUpdateItemField(${idx}, 'quantity', this.value)">
                        </div>
                        <div class="col-2">
                            <input type="text" class="form-control form-control-sm" placeholder="ĐVT" value="${sqEsc(it.unit)}" oninput="sqUpdateItemField(${idx}, 'unit', this.value)">
                        </div>
                        <div class="col-4">
                            <input type="number" class="form-control form-control-sm" placeholder="Đơn giá (VNĐ)" min="0" step="1000" value="${it.unit_price}" oninput="sqUpdateItemField(${idx}, 'unit_price', this.value)">
                        </div>
                    </div>
                </div>
            `);
        });
    }

    window.sqRenderPreview = function () {
        $('#sq_pv_name').text($('#sq_customer_name').val().trim() || 'Quý Khách Hàng');
        $('#sq_pv_phone').text($('#sq_customer_phone').val().trim() || '---');
        $('#sq_pv_company').text($('#sq_customer_company').val().trim() || '---');
        $('#sq_pv_email').text($('#sq_customer_email').val().trim() || '---');
        $('#sq_pv_validity').text($('#sq_validity_days').val() || 30);

        const vatPct = parseFloat($('#sq_vat_percent').val()) || 0;
        $('#sq_pv_vat_label').text(vatPct);

        let subtotal = 0;
        let rowsHtml = '';
        const validItems = sqItems.filter(it => (it.product_name || '').trim() !== '');

        if (validItems.length === 0) {
            rowsHtml = `<tr><td colspan="5" style="padding:18px; text-align:center; color:#64748b;">Chưa có sản phẩm nào. Vui lòng chọn hoặc nhập sản phẩm bên trái.</td></tr>`;
        } else {
            validItems.forEach((it, idx) => {
                const lineTotal = (Number(it.quantity) || 1) * (Number(it.unit_price) || 0);
                subtotal += lineTotal;
                const subMeta = [it.product_sku ? ('Mã: ' + sqEsc(it.product_sku)) : '', it.brand_name ? ('Hãng: ' + sqEsc(it.brand_name)) : ''].filter(Boolean).join(' | ');
                rowsHtml += `
                    <tr style="background:${idx % 2 === 1 ? '#f8fbfe' : '#ffffff'}; border-bottom:1px solid #e5edf5;">
                        <td style="padding:8px 6px; text-align:center; color:#475569;">${idx + 1}</td>
                        <td style="padding:8px 8px;">
                            <div style="font-weight:700; color:#111827;">${sqEsc(it.product_name)}</div>
                            ${subMeta ? `<div style="font-size:10px; color:#475569; margin-top:2px;">${subMeta}</div>` : ''}
                        </td>
                        <td style="padding:8px 6px; text-align:center; white-space:nowrap;">${it.quantity} ${sqEsc(it.unit || 'Cái')}</td>
                        <td style="padding:8px 8px; text-align:right; white-space:nowrap;">${sqFmtMoney(it.unit_price)}</td>
                        <td style="padding:8px 8px; text-align:right; font-weight:700; color:#1e73be; white-space:nowrap;">${sqFmtMoney(lineTotal)}</td>
                    </tr>
                `;
            });
        }

        const vatAmt = Math.round(subtotal * (vatPct / 100));
        $('#sq_pv_tbody').html(rowsHtml);
        $('#sq_pv_subtotal').text(sqFmtMoney(subtotal));
        $('#sq_pv_vat_amount').text(sqFmtMoney(vatAmt));
        $('#sq_pv_total').text(sqFmtMoney(subtotal + vatAmt));
    };

    // Search products from DB
    $(document).on('input', '#sq_product_search', function () {
        const q = $(this).val().trim();
        const dd = $('#sq_product_dropdown');
        clearTimeout(sqSearchTimer);
        if (q.length < 2) { dd.hide().empty(); return; }

        sqSearchTimer = setTimeout(() => {
            $.get('/api/quote/search-products', { q: q }, function (res) {
                const list = Array.isArray(res) ? res : (res.data || []);
                if (!list.length) { dd.hide().empty(); return; }
                let html = '';
                list.slice(0, 8).forEach(p => {
                    const price = Number(p.sale_price) > 0 ? Number(p.sale_price) : (Number(p.price) || 0);
                    html += `<button type="button" class="list-group-item list-group-item-action py-2 px-3 small sq-pick-prod"
                        data-id="${p.id}" data-name="${sqEsc(p.name)}" data-sku="${sqEsc(p.sku || '')}"
                        data-brand="${sqEsc(p.brand_name || (p.brand ? p.brand.name : ''))}" data-price="${price}">
                        <strong>${sqEsc(p.name)}</strong>
                        <div class="text-muted" style="font-size:11px;">SKU: ${sqEsc(p.sku || '---')} • Giá: ${sqFmtMoney(price)}</div>
                    </button>`;
                });
                dd.html(html).show();
            });
        }, 280);
    });

    $(document).on('click', '.sq-pick-prod', function () {
        const picked = {
            product_id: $(this).data('id') || null,
            product_name: $(this).data('name') || '',
            product_sku: $(this).data('sku') || '',
            brand_name: $(this).data('brand') || '',
            unit: 'Cái',
            quantity: 1,
            unit_price: Number($(this).data('price')) || 0,
        };
        if (sqItems.length === 1 && !sqItems[0].product_name.trim()) {
            sqItems[0] = picked;
        } else {
            sqItems.push(picked);
        }
        $('#sq_product_search').val('');
        $('#sq_product_dropdown').hide().empty();
        sqRenderEditor();
        sqRenderPreview();
    });

    async function sqCaptureBase64() {
        if (typeof html2canvas === 'undefined') return null;
        try {
            const el = document.getElementById('sq_capture_sheet');
            const canvas = await html2canvas(el, { scale: 2, useCORS: true, backgroundColor: '#ffffff' });
            return canvas.toDataURL('image/png');
        } catch (e) {
            console.warn('html2canvas fallback:', e);
            return null;
        }
    }

    window.sqDownloadPng = async function () {
        const dataUrl = await sqCaptureBase64();
        if (!dataUrl) { alert('Không thể tạo ảnh báo giá, vui lòng thử lại.'); return; }
        const a = document.createElement('a');
        a.href = dataUrl;
        a.download = 'Bao-gia-' + ($('#sq_pv_code').text().trim() || 'HPT') + '.png';
        a.click();
    };

    window.sqSubmitAndSendEmail = async function () {
        const customerName = $('#sq_customer_name').val().trim();
        const customerEmail = $('#sq_customer_email').val().trim();
        const validItems = sqItems.filter(it => (it.product_name || '').trim() !== '');

        if (!customerName) { alert('Vui lòng nhập tên khách hàng!'); return; }
        if (!customerEmail || !customerEmail.includes('@')) { alert('Vui lòng nhập email khách hàng hợp lệ!'); return; }
        if (validItems.length === 0) { alert('Vui lòng thêm ít nhất 1 sản phẩm vào báo giá!'); return; }

        const btn = $('#sq_btn_send_email');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-1"></i> Đang tạo ảnh &amp; gửi email...');

        try {
            const imageBase64 = await sqCaptureBase64();
            const payload = {
                _token: $('meta[name="csrf-token"]').attr('content'),
                quote_id: $('#sq_quote_id').val() || null,
                contact_id: $('#sq_contact_id').val() || null,
                quote_code: $('#sq_pv_code').text().trim(),
                customer_name: customerName,
                customer_email: customerEmail,
                customer_phone: $('#sq_customer_phone').val().trim(),
                customer_company: $('#sq_customer_company').val().trim(),
                vat_percent: parseFloat($('#sq_vat_percent').val()) || 0,
                validity_days: parseInt($('#sq_validity_days').val(), 10) || 30,
                staff_note: $('#sq_staff_note').val().trim(),
                items: validItems,
                quote_image_base64: imageBase64,
            };

            const res = await $.ajax({
                url: '{{ route("admin.api.quotes.send_email") }}',
                method: 'POST',
                contentType: 'application/json',
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                data: JSON.stringify(payload),
            });

            if (res && res.success) {
                $('#adminSendQuoteModal').modal('hide');
                if (typeof toastr !== 'undefined') toastr.success(res.message);
                else alert(res.message);
                if (typeof loadQuotes === 'function') loadQuotes();
                if (typeof loadContacts === 'function') loadContacts();
            } else {
                alert((res && res.message) || 'Không thể gửi email báo giá.');
            }
        } catch (xhr) {
            const msg = xhr.responseJSON?.message || 'Có lỗi xảy ra khi gửi email báo giá (kiểm tra cấu hình SMTP trong .env).';
            alert(msg);
        } finally {
            btn.prop('disabled', false).html('<i class="fa fa-paper-plane mr-1"></i> Gửi Báo Giá Về Email');
        }
    };
})();
</script>
