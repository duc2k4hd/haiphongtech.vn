# PROMPT GIAO CHO AI CODING AGENT TRONG DỰ ÁN `haiphongtech.vn`

Bạn đang đóng vai **Tech Lead Migration + WordPress/WooCommerce Data Engineer + SEO Technical Auditor + QA Lead**. Đây là tác vụ **rủi ro cao** trên một website doanh nghiệp đang hoạt động. Dự án hiện tại `haiphongtech.vn` là bản copy mã nguồn từ `misutech.vn`, có thể còn nguyên data/branding/route của Misutech. Nguồn dữ liệu chuẩn là `https://haiphongtech.vn/` WordPress hiện hữu.

**Đọc toàn bộ** `docs/HAIPHONGTECH_MIGRATION_PHASE1_SAFE.md` trước khi làm bất cứ thao tác nào và áp dụng tất cả các điều kiện chặn (gates) trong tài liệu đó. Không xem ví dụ URL trong docs là inventory hoàn chỉnh.

## Nhiệm vụ

1. **Giai đoạn 1 chỉ chuyển Pages, Posts/blog, archives/taxonomies bài viết, chính sách, media liên quan, menu/footer, nhận diện doanh nghiệp và SEO**, giữ nguyên đường dẫn/permalink cũ. Chưa import/chỉnh sửa sản phẩm WooCommerce. Tuy nhiên, tuyệt đối không được làm website chính mất các URL sản phẩm, danh mục, shop, ảnh và sitemap. Không cutover production.
2. **Khảo sát repo trước**: đọc cấu trúc mã, schema DB, migration, routes, controllers, models, views, layouts, SEO, config, storage, jobs, seeders, tests và docs hiện có. Rà soát mọi dấu vết Misutech, ghi file path và risk. Không in secret. Ghi kết quả vào `docs/migration/00_TARGET_RECON.md`.
3. **Xây bộ inventory nguồn có thể kiểm chứng**, ưu tiên export/backup WordPress hoặc REST/API có quyền được cấp, kết hợp sitemap và log; các phần không truy cập được phải đánh dấu `UNKNOWN`. Không cào hàng trăm nghìn URL production một cách thiếu kiểm soát. Không suy diễn URL từ tên bài/slug.
4. Viết mapping bằng `source_wp_id` / source permalink / type / parent / taxonomy / SEO metadata, tìm slug collisions giữa Page, Post, Category, Tag, Product Category, Brand, WooCommerce Product. Giữ nguyên status/redirect/canonical/trailing-slash theo dữ liệu nguồn. Tạo manifest và báo cáo xung đột.
5. **Chỉ sau khi preflight và backup pass**, viết code importer **an toàn cho staging**: dry-run mặc định, batched, idempotent, rollback/checkpoint, không chạy trên production, không xóa dữ liệu tùy tiện. Phục dựng đúng nội dung HTML, SEO, media, dates, authors, breadcrumbs, taxonomy, menu/footer. Không dùng dữ liệu Misutech. Không tự sửa nội dung nguồn.
6. Viết và chạy tests: URL status parity, SEO parity, content/media integrity, internal links, sitemap scope, collision/routing, no-Misutech leakage, regression, negative cases, retry/import twice. Xuất báo cáo PASS/FAIL/UNKNOWN theo URL và theo nhóm.
7. Nếu thiếu credential/source dump/schema, không đoán. Hoàn thành tối đa phần khảo sát, công cụ đọc dữ liệu, schema design/test scaffold mà không làm thay đổi dữ liệu; ghi blockers cụ thể và hướng dẫn lấy nguồn bằng cách an toàn.
8. **Dừng hoàn toàn trước production deployment**. Không thay DNS, không đổi `.env` live, không ghi DB live, không chạy `migrate:fresh`, `db:wipe`, `truncate`, `DROP`, `DELETE` diện rộng, không tự 301 về trang chủ. Không để sản phẩm bị 404 chỉ vì giai đoạn này chưa chuyển sản phẩm.

## Quy trình phản hồi bắt buộc

- Trước khi sửa: trình bày ngắn gọn hiểu biết về repo thật và các nhóm rủi ro P0/P1.
- Triển khai tuần tự theo Gates A → B → C → D → E trong docs. Không ghi nhận PASS nếu chưa có bằng chứng từ code/export/tests.
- Mỗi bước: nêu chính xác file sửa, lệnh đã chạy, môi trường thực thi, bảng/dữ liệu bị ảnh hưởng, kết quả và rollback.
- Sau cùng tạo các file tài liệu đã yêu cầu trong `docs/migration/`, kèm `URL parity` CSV, media & SEO reports, blockers và trạng thái cụ thể `BLOCKED` hoặc `STAGING READY`.
- Nếu một gate thất bại, **dừng tại gate đó**, báo nguyên nhân và dữ liệu tối thiểu cần bổ sung. Không tiếp tục bằng giả định.
- **Tuyệt đối không tự deploy lên `https://haiphongtech.vn/`.** Kết luận Phase 1 không đồng nghĩa được phép cutover. Phải bảo toàn được cả đường dẫn sản phẩm và legacy trước khi đề xuất go-live.

Bắt đầu bằng cách **đọc file docs và khảo sát repo hiện tại ngay bây giờ**. Không sửa dữ liệu source, không làm việc trên production. Nếu đầy đủ nguồn và cổng an toàn đạt thì thực hiện phần staging đã được mô tả; nếu chưa đủ, giao các công cụ read-only, tài liệu khảo sát và blocker rõ ràng, không bịa thành công.
