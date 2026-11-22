# HẢI PHÒNG TECH — KẾ HOẠCH DI CHUYỂN NỘI DUNG GIAI ĐOẠN 1 (AN TOÀN SEO)

> **Tình trạng tài liệu:** Đặc tả để AI/nhóm kỹ thuật khảo sát, lập trình và nghiệm thu theo từng cổng phê duyệt. **KHÔNG phải lệnh cho phép triển khai production.**
>
> **Nguồn chính:** `https://haiphongtech.vn/` (WordPress/WooCommerce hiện tại).
>
> **Đích:** mã nguồn dự án `haiphongtech.vn` đã sao chép từ dự án `misutech.vn`, **chưa chắc cùng cấu trúc dữ liệu và backend**.
>
> **Phạm vi đợt này:** trang tĩnh (Page), bài viết (Post), trang liệt kê tin/nhãn/chuyên mục, nội dung SEO, menu/footer, ảnh/tệp liên quan, cấu hình nhận diện Hải Phòng Tech. **CHƯA nhập/chỉnh sửa dữ liệu sản phẩm WooCommerce**.
>
> **Ngày khảo sát công khai:** 18/11/2026. Dữ liệu nhìn thấy trên website có thể thay đổi; chỉ bản xuất dữ liệu có timestamp và biên bản đối chiếu mới là mốc kiểm chứng đầy đủ.

---

## 0. NGUYÊN TẮC BẤT KHẢ XÂM PHẠM — ĐỌC TRƯỚC MỌI THAO TÁC

1. **Không động vào production**: không chạy migration ghi, không sửa DNS, vhost, `.env`, robots.txt, sitemap, database, media, cache, WordPress/plugin hiện tại; không đổi permalink, URL, canonical hay redirect trên `https://haiphongtech.vn/` khi chưa có phê duyệt bằng văn bản.
2. **Không tự động ghi đè dữ liệu Misutech hoặc xóa bất kỳ bản ghi nào.** Dữ liệu Misutech hiện trong bản copy được xem là dữ liệu nhiễm nguồn, phải cô lập, kiểm kê và xử lý **chỉ trên môi trường đích không phải production**, có backup + mapping cụ thể; không làm thay đổi dữ liệu của website misutech.vn thật.
3. **Bảo toàn địa chỉ URL cũ theo inventory thực tế**. Mục tiêu là khớp URL gốc ở cấp protocol + hostname + path + quy tắc query/redirect/canonical. Giữ nguyên slug, phân cấp và cấu trúc đã được công khai, không tự slugify lại từ tiêu đề. Nếu URL đã ở trạng thái 301/302/404/410 thì phải lưu baseline và xử lý theo quyết định có lý do; tuyệt đối không tự biến 404 thành 200 giả.
4. **Không triển khai Phase 1 lên domain chính khi sản phẩm chưa có đường phục vụ tương đương.** Các URL `/product/.../`, danh mục, `/shop/`, phân trang, hình ảnh, tài liệu liên quan sản phẩm và sitemap hiện hữu không được phép mất hoạt động vì “sản phẩm tính sau”. Nếu chưa có chiến lược kế thừa sản phẩm đã kiểm chứng thì **chỉ hoàn tất staging, tuyệt đối không cutover**.
5. **Không hứa không có 404/500 tuyệt đối** chỉ vì đã viết code. Chỉ được báo “đạt” sau khi có baseline, chạy test tự động, đối chiếu toàn bộ URL đã kiểm kê, log, xác nhận vận hành và người chịu trách nhiệm ký duyệt. Mỗi URL thiếu chứng cứ = **chưa xác minh**, không coi là thành công.
6. **Ưu tiên giữ nguyên thông tin thực tế**: không tự bịa nội dung, thông số, ngày tháng, địa chỉ, điện thoại, giá, cam kết pháp lý, thẻ meta, slug hay từ khóa; không biến đổi ý nghĩa các chính sách. Nếu nội dung cũ có lỗi hoặc chỗ giữ chỗ (placeholder), báo để chủ sở hữu quyết định, không âm thầm sửa trong lúc chuyển dữ liệu.
7. **Backup có thể phục hồi**: phải có full backup mã nguồn, DB, media, WordPress configuration, webserver rules, SEO redirects, sitemap, secrets (lưu trong kho bảo mật, không trong git), công cụ thử phục hồi trên môi trường cô lập, checksum và thời gian snapshot. Backup không được coi là hợp lệ nếu chưa thử khôi phục.
8. **Bảo mật và pháp lý**: không tải tài khoản người dùng, đơn hàng, PII hay nội dung riêng tư lên AI/API hoặc đưa vào repo/log. Chỉ xuất trường cần thiết theo quyền truy cập đã được doanh nghiệp cấp. Quyết định xuất bản, thay đổi nội dung chính sách và cutover do người được ủy quyền phê duyệt.
9. **Mọi script import phải idempotent**, có `dry-run`, có rollback, báo số bản ghi dự kiến ảnh hưởng, transactional khi có thể, khóa ngoại và schema validation, chống trùng, snapshot trước ghi, chạy giới hạn lô, checkpoint và log an toàn.
10. **Không tự triển khai dù AI tự tin**. Khi phát hiện thiếu nguồn, mâu thuẫn slug, lỗi 5xx, khác biệt robots/canonical, thiếu tệp hoặc nguy cơ phá dữ liệu: **DỪNG**, ghi issue và yêu cầu con người xử lý.

## 1. MỤC TIÊU VÀ PHẠM VI

### 1.1 Kết quả kỳ vọng

- Site `haiphongtech.vn` mới thể hiện **đúng nội dung Hải Phòng Tech**, không sót branding/text/data/secrets từ Misutech.
- Trang chủ, trang liên hệ, giới thiệu, chính sách, bài viết, trang chuyên mục/thẻ, menu, footer, media và metadata SEO của **đúng bản nguồn** được phục dựng trên theme/layout dự án mới.
- URL công khai từ hệ cũ không bị đổi vì thay hệ thống mới; 100% URL được phân loại và có kết quả kiểm thử/đối chiếu hoặc trạng thái block được công khai minh bạch.
- Chỉ tác động staging; giữ nguyên production cho đến một dự án cutover riêng có sản phẩm và rollback.
- Mỗi bản ghi có `source_wp_id` hoặc nguồn tương ứng, `source_type`, `source_slug`, `source_permalink`, timestamp nguồn, `content_hash`, `import_batch_id`, và mapping media liên quan để trace và nhập lại có kiểm soát.

### 1.2 Trong phạm vi

- WordPress Pages: `post_type=page`, published và các trang chức năng công khai; trang chủ được dựng từ đúng dữ liệu/khối đang sử dụng, không chỉ sao chép một page ID.
- WordPress Posts: `post_type=post`, bài viết, ngày đăng/ngày sửa, tác giả hiển thị, chuyên mục, tag, excerpt, featured image, nội dung HTML, Gutenberg blocks/page builder nếu có, liên kết/nút download, tài liệu đính kèm, nội dung nhúng.
- Archives: blog, chuyên mục bài viết, trang thẻ, phân trang, breadcrumb, bài liên quan và pagination URLs đúng như nguồn.
- Footer/header/menu, logo, favicon, liên kết xã hội, contact info, trang chính sách (cần người phụ trách phê duyệt nội dung chính thức).
- SEO: meta title, meta description, canonical, robots, open graph/Twitter nếu có, structured data hợp lệ, các heading, link nội bộ, sitemap phần nội dung, các redirect cũ, URL hình ảnh, thông tin tác giả, lastmod (nếu nguồn có).
- Tính năng người đọc: ô tìm kiếm, form liên hệ (nếu có), link tải file, bài liên quan, breadcrumbs, pagination; các hành vi chính cần parity với nguồn trong phạm vi content.

### 1.3 Ngoài phạm vi của đợt import dữ liệu

- Không import, cập nhật hay xóa sản phẩm, SKU, biến thể, danh mục sản phẩm, tồn kho, đơn hàng, giá, hình sản phẩm, review hay account WooCommerce.
- Không thay đổi SEO của sản phẩm. Tuy nhiên **kiểm kê sản phẩm và các URL liên quan vẫn bắt buộc để đánh giá an toàn cutover**.
- Không tự làm lại hợp đồng/chính sách kinh doanh, không viết thêm bài mới, không thay URL “cho đẹp”, không xóa bài cũ hoặc nội dung trùng lặp nếu chưa được duyệt.

## 2. BẰNG CHỨNG KHẢO SÁT CÔNG KHAI (SEED, KHÔNG PHẢI KIỂM KÊ ĐẦY ĐỦ)

Các đường dẫn sau đã được kiểm tra bằng trang công khai ngày 18/11/2026; chúng **là ví dụ baseline thực**, không phải toàn bộ hệ thống. AI phải xuất danh sách đầy đủ từ nguồn có thẩm quyền (DB/WordPress/WP-CLI/sitemap và log) trước khi viết importer.

| Nhóm | Đường dẫn gốc cần giữ | Quan sát |
|---|---|---|
| Trang chủ | `/` | Trang giới thiệu sản phẩm, menu/footer |
| Trang tĩnh | `/gioi-thieu/` | Sứ mệnh/tầm nhìn/chiến lược, ảnh, bài mới |
| Trang tĩnh | `/lien-he/` | Thông tin liên hệ doanh nghiệp |
| Trang chức năng | `/tai-lieu-ki-thuat/` | Trang hiển thị danh sách tài liệu (đang báo chưa có tài liệu lúc khảo sát) |
| Blog | `/tin-blog/` | Trang tổng hợp bài viết |
| Trang blog | `/tin-blog/page/2/` | Phân trang đã thấy hoạt động |
| Chuyên mục bài | `/tu-dong-hoa/` | Archive chuyên mục, **không nằm dưới `/category/` trong ví dụ này** |
| Bài viết | `/cam-bien-tu-la-gi-cau-tao-nguyen-ly-va-ung-dung/` | Bài viết URL ngay root, có ngày/tác giả/thẻ |
| Bài viết | `/download-cx-designer-omron/` | Bài có link phần mềm/tải tài liệu |
| Tag | `/tag/cam-bien/` | Archive thẻ bài viết |
| Tag | `/tag/cam-bien-tu/` | Archive thẻ bài viết |
| Chính sách | `/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/` | Nội dung bảo mật |
| Chính sách | `/chinh-sach-bao-mat-thong-tin-ca-nhan-information-privacy/` | Nội dung bảo mật thông tin cá nhân |
| Chính sách | `/chinh-sach-thanh-toan-an-toan-da-dang-cod-chuyen-khoan/` | Nội dung thanh toán |
| Chính sách | `/chinh-sach-bao-hanh-thiet-bi-cong-nghiep-chinh-hang-warranty-policy/` | Nội dung bảo hành |
| Chính sách | `/chinh-sach-van-chuyen-nhanh-an-toan-giao-toan-quoc/` | Nội dung vận chuyển |
| Chính sách | `/chinh-sach-doi-tra-san-pham-return-refund-policy/` | Nội dung đổi trả |
| Chính sách | `/dieu-khoan-su-dung-website-quy-dinh-trach-nhiem/` | Điều khoản |
| Chính sách | `/chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy/` | Nội dung bảo mật khách hàng |
| Ranh giới sản phẩm | `/shop/` | Shop public, **chưa được mất** |
| Ranh giới sản phẩm | `/shop/page/2/` | Pagination sản phẩm, **chưa được mất** |
| Ranh giới sản phẩm | `/autonics/` | Archive hãng/danh mục trực tiếp root |
| Ranh giới sản phẩm | `/cam-bien-autonics/` | Archive danh mục trực tiếp root |
| Ranh giới sản phẩm | `/cam-bien-vi-tri-autonics/` | Archive danh mục trực tiếp root |
| Ranh giới sản phẩm | `/product/ee-sx674/` | URL sản phẩm có prefix `product` |
| Ranh giới sản phẩm | `/product/ca-lh4/` | URL sản phẩm có prefix `product` |
| Tài sản | `/wp-content/uploads/2025/09/` + tên tệp | Có tài liệu PDF theo đường dẫn gốc |
| Tài sản | `/wp-content/uploads/2025/08/` + tên tệp | Có ảnh WebP theo đường dẫn gốc |
| Điểm endpoint | `/wp-json/` | Có endpoint JSON công khai, **không suy ra mọi API đều truy cập được** |
| Sitemap | `/sitemap_index.xml` | Có link từ footer; công cụ khảo sát không đọc được XML, **chưa kiểm kê** |

Ghi nhận tại thời điểm truy cập: `/shop/` hiển thị khoảng **133.035 kết quả** và `/tin-blog/page/2/` ghi “trang 2 trên 20”; hai con số chỉ để đánh giá quy mô, **không là tổng chính thức để nghiệm thu**. Không thể tuyên bố đã đọc toàn bộ nội dung web bằng khảo sát công khai này.

Nguồn tham chiếu chính:

- https://haiphongtech.vn/
- https://haiphongtech.vn/gioi-thieu/
- https://haiphongtech.vn/lien-he/
- https://haiphongtech.vn/tin-blog/
- https://haiphongtech.vn/tin-blog/page/2/
- https://haiphongtech.vn/tu-dong-hoa/
- https://haiphongtech.vn/cam-bien-tu-la-gi-cau-tao-nguyen-ly-va-ung-dung/
- https://haiphongtech.vn/download-cx-designer-omron/
- https://haiphongtech.vn/tag/cam-bien/
- https://haiphongtech.vn/autonics/
- https://haiphongtech.vn/shop/
- https://haiphongtech.vn/product/ee-sx674/

**Lưu ý quan trọng:** một số nội dung trang hiện tại có thể chứa placeholder như “Add Any content here”, “Lorem ipsum”. Không sao chép chúng thành lời khẳng định thật hoặc tự biên tập lại; cần báo trạng thái để người quản trị quyết định.

## 3. GIAI ĐOẠN A — ĐIỀU TRA DỰ ÁN ĐÍCH (READ-ONLY)

Trước khi chạm dữ liệu, AI phải đọc mã nguồn dự án `haiphongtech.vn` trong workspace:

1. Xác định framework, phiên bản, runtime, hệ DB, ORM, migration, route, middleware, controller, template, dashboard, filesystem, asset storage, queue/cache, test suite, SEO schema, deployment và cấu trúc `docs/`.
2. Chỉ ra các phần còn sót **Misutech**: hardcoded brand, logos/favicon, tên công ty, email, hotline, domain, social link, canonical, metadata, schema Organization, config/mail, static pages/posts, demo data, uploads, seeders, footer/menu, sitemap, robot rules, route aliases, API URL, analytics và content import; đánh dấu đường dẫn file tương ứng. Không in nội dung `.env` hoặc secrets vào báo cáo.
3. Phân loại dữ liệu trong DB đích: `[HPT mới]/[MISUTECH có nguồn]/[không rõ nguồn]/[hệ thống]`; xác định record dependencies, khóa ngoại, uniqueness, bút toán người dùng. **Không gọi `truncate`, `migrate:fresh`, `db:wipe`, `DROP`, `DELETE` hoặc xóa hàng loạt**.
4. Khảo sát tất cả route hiện tại, thứ tự ưu tiên route động/catch-all; đảm bảo tránh nuốt đường dẫn `/product/`, `/wp-content/`, `/wp-json/`, `/tag/`, các file `.xml` và các slug root có thể đụng nhau.
5. Tạo `docs/migration/00_TARGET_RECON.md`: sơ đồ dữ liệu, mapping module có sẵn, rủi ro và câu hỏi chưa rõ. Nếu không có code/DB/API nguồn đủ để xác minh, dừng ở báo cáo; **không suy đoán cấu trúc**.

**Cổng A (PASS/FAIL):** tồn tại báo cáo kiến trúc cụ thể, danh sách file sẽ thay đổi, phương án import không phá dữ liệu và kế hoạch test. Không PASS nếu chưa đọc được source/schema đích.

## 4. GIAI ĐOẠN B — THU THẬP NGUỒN VÀ ĐÓNG BĂNG BASELINE (READ-ONLY)

### 4.1 Thứ tự ưu tiên nguồn

1. **Ưu tiên cao nhất**: bản backup/snapshot và export WordPress có quyền hợp pháp, DB + `wp-content/uploads` + SEO plugin settings + rewrite rules + media + danh sách redirects. DB source gốc vẫn read-only.
2. `wp post list`, `wp term list`, `wp option get` với quyền đã được cấp và không làm thay đổi dữ liệu; WP-CLI truy vấn trên **bản sao DB được phục hồi** nếu tài nguyên sản xuất nhạy cảm.
3. WordPress REST API công khai/đã xác thực (chỉ khi được cấp quyền) để lấy pages/posts/terms/media theo phân trang; xác minh các trường custom không lộ qua REST.
4. `sitemap_index.xml`, page/post/category/tag/product sitemap, sitemap hình và nội dung liên kết để mở rộng URL inventory.
5. HTTP crawl rate-limited, access log 30–90 ngày (nếu có quyền), analytics/Search Console (nếu có quyền) để tìm URL từng nhận traffic nhưng không nằm sitemap.

**Không xuất/import private orders, users, emails, mật khẩu, API tokens, session keys, liên hệ khách hàng và dữ liệu cá nhân** vào kho triển khai content.

### 4.2 Metadata/tư liệu cần chụp

- WordPress `permalink_structure`, `home`, `siteurl`, cấu trúc `category_base`, `tag_base`, `product` permalink, rewrite rules custom, trailing slash behavior, www/non-www, http→https, chữ hoa/thường/Unicode, URL percent-encoding.
- Page/Post: ID, type, status, parent, slug **gốc**, permalink **đầy đủ**, title, excerpt, HTML raw/rendered, block/page builder data nếu có, publish/modified timestamps + timezone, author display, featured media, attached files, menu order, category/tag, seo meta/robots/canonical, redirects.
- Media: attachment ID, URL gốc, path gốc, MIME, alt, caption, description, caption/credit/licence, image dimensions, responsive variants, file byte size + SHA256; hạn chế dedup gây thay tên file.
- Cấu hình site-wide: menu tree, links, contact, branding, footer, static page selection, archive configuration, form destination, SMTP (chỉ tham số cần thiết, KHÔNG lấy secrets), sitemap generator, schema generator, canonical/OG rules, cache/CDN and HSTS constraints.
- Nếu shortcode đặc thù (ví dụ `[dimension]`, `[diagram]`, `[contact_form]`) hoặc Elementor/Classic Editor/Gutenberg/HTML builder tồn tại trong content: liệt kê và quy định renderer có kiểm thử, không để raw shortcode trong trang cuối trừ khi có chủ đích.

### 4.3 Inventory là điều kiện tiên quyết

Sinh các file **bằng công cụ tự động từ nguồn, không tự viết danh sách tay**:

- `docs/migration/data/source_urls.csv` — URL nguồn, type, source_id, slug, parent_id, publish state, original_status, final_url, redirect_chain, canonical, title, meta robots, lastmod, in_sitemap, discovered_from, source_snapshot_id.
- `docs/migration/data/content_manifest.jsonl` — nội dung, taxonomy refs, hash, media refs, SEO fields; file chứa dữ liệu nhạy cảm phải lưu tại thư mục bảo mật ngoài git và chỉ đưa bản đã lọc vào `docs/`.
- `docs/migration/data/media_manifest.csv` — URL, relative path, SHA256, MIME, source ID, referenced-by.
- `docs/migration/data/redirects.csv` — nguồn → đích, status code, lý do.
- `docs/migration/data/url_collisions.csv` — slug trùng giữa Page/Post/Category/Brand/Product, route priority cần giữ.
- `docs/migration/data/seo_baseline.csv` — title/description/canonical/robots/H1/schema (khi kiểm tra được).
- `docs/migration/01_SOURCE_INVENTORY.md` — tổng lượng theo nhóm, lỗi nguồn, giới hạn thu thập, snapshot date, checksum, chênh lệch sitemap/DB.

**Không nên commit bản dump DB chứa dữ liệu cá nhân hoặc tài liệu mật lên Git.** Cấu hình `.gitignore` và mã hóa/lưu bản backup vào kho thích hợp; repo chỉ chứa mã nguồn, schema, docs và dữ liệu đã được duyệt.

### 4.4 Khả năng tái kiểm chứng / kiểm soát rủi ro

- Crawl production có delay/backoff, rate limit và concurrency thấp; ưu tiên dữ liệu export thay vì gửi hàng trăm nghìn HTTP requests gây quá tải nguồn.
- Ghi HTTP `status`, `Location`, `content-type`, `canonical`, `x-robots-tag`, `etag`/`last-modified` nếu có; lưu cấu hình User-Agent, timestamp, snapshot ID.
- Ghi rõ khi một nguồn không thể truy cập (ví dụ XML bị công cụ đọc chặn) là **UNKNOWN**, không đánh dấu file sitemap rỗng.
- Không thay URL bằng redirect map “suy đoán”; phân giải từ DB permalink và quy tắc rewrite được kiểm chứng.
- Ở mỗi bản ghi cần phân biệt `raw_content`, `rendered_content`, `source_url` và `assets` để đối chiếu được nguồn thực.

**Cổng B:** inventory đầy đủ dựa trên export và nguồn crawl, URL collisions được phân loại, backup đã test restore; có báo cáo phần còn UNKNOWN. Không PASS nếu mới có dữ liệu từ các trang ví dụ ở mục 2.

## 5. GIAI ĐOẠN C — THIẾT KẾ CƠ CHẾ IMPORT (CHỈ STAGING)

### 5.1 Mô hình dữ liệu đề xuất (áp dụng tương thích kiến trúc thực tế)

Không buộc tạo bảng mới nếu dự án đã có bảng tương đương; sử dụng kiến trúc hiện hữu nhưng phải biểu đạt được:

- `content_pages`: source ID, type, immutable original path/permalink, parent tree, slug, title, HTML/body blocks, excerpt, status, publish/modified timestamps, template key, SEO fields.
- `content_posts`: tương tự pages + author display + category/tag many-to-many + cover image + publishedAt.
- `content_terms`: source taxonomy/type, slug, name, description, parent, original URL, SEO meta.
- `content_media`: original URL/path, local path hoặc object store URL, mime, alt, caption, hash, references.
- `source_url_routes`: `normalized_lookup_key` **và** `original_requested_path`/trailing-slash metadata, `content_kind`, `source_id`, resolution policy, original status/redirect, canonical, source snapshot ID.
- `migration_runs`: run_id, source snapshot hash, environment, schema version, imported/updated/skipped/conflict/error counters, start/end, operator, report, rollback checkpoint.

Bất kỳ schema nào được chọn cũng phải có unique constraints phù hợp, tránh nhập trùng và lưu source identity qua nhiều đợt import. **Không dùng `slug` đơn lẻ làm định danh**, vì bài viết, page, tag, category, product archive có thể trùng slug khác namespace.

### 5.2 Importer an toàn

- Lệnh CLI mặc định `--dry-run` và **từ chối** host/database production; chế độ ghi chỉ khi `--env=staging --apply --source-snapshot=... --approved-plan=...` và preflight thành công. Không tự động chạy khi `git pull`, deploy hay boot app.
- Thực hiện theo lô nhỏ, log những trường thay đổi, chống ghi đè thủ công ở đích nếu bản ghi đã thay đổi (optimistic lock / conflict queue), không đổi source immutable slug.
- Normalize HTML an toàn để tránh XSS nhưng bảo toàn cấu trúc ngữ nghĩa, heading, tables, anchors, list, image alt, links, code, embeds, download URLs. Không strip iframe/script indiscriminately nếu chức năng hợp lệ; mọi mã nhúng cần allowlist và test bảo mật.
- Giữ đúng ảnh bìa, ảnh trong bài, attachment, hotlink nội bộ. Mọi thay đổi asset path phải giữ đường dẫn cũ phục vụ được (rewrite/proxy/CDN), không tự ý đổi basename/lowercase/đổi dấu.
- External links, `mailto:`, `tel:`, Zalo/Facebook, anchor `#...` phải được kiểm tra và không biến thành domain Misutech.
- Giữ ngày giờ gốc, không chuyển tất cả thành thời điểm import; lưu timezone. Có audit trail cho nội dung chỉnh sửa.
- Không import dữ liệu Misutech dưới danh Hải Phòng Tech. Cô lập `misutech` data trong bản copy sau backup với clear namespace và chính sách không xóa vội.

### 5.3 SEO và URL router

- Luôn giải quyết **exact known URL** trước route chung; ưu tiên `original_permalinks`/routing manifest sinh từ nguồn, sau đó mới xử lý định tuyến theo taxonomy. Không cho catch-all page `/{slug}` nuốt route sản phẩm, ảnh, tag hoặc endpoint hệ thống.
- Với một URL nguồn có HTTP 200 thì URL đích (khi cùng hostname) phải trả nội dung tương ứng 200 và canonical đúng, trừ khi chủ sở hữu duyệt một thay đổi cụ thể. URL redirect đang có cần bảo toàn đích/code hoặc thay đổi được duyệt.
- Trailing slash: chụp cách nguồn phản hồi `/abc` và `/abc/` trước khi quy định; tránh redirect chain/mixed-case và vòng lặp. Test `%xx` encoding/unicode/space/plus/query/pagination.
- Đừng thêm các URL đẹp mới rồi 301 hàng loạt từ URL cũ. Không cấp canonical mặc định về trang chủ hay `/tin-blog/` cho toàn bộ pages.
- SEO fields: `title`, `description`, `canonical`, `robots`, OG title/description/image, `hreflang` nếu có thực, schema phù hợp, `og:url`, breadcrumbs, author/published/modified dates. Một nội dung không được đồng thời có canonical khác `noindex` bất ngờ.
- Home/footer/contact: nhận diện đúng Công ty TNHH Hải Phòng Tech; contact details có thể khác nhau ở nhiều vị trí nguồn — **không chọn bừa một số, cần lập bảng đối chiếu và xin xác nhận**.
- Tránh index staging: bảo vệ staging qua auth/VPN/IP allowlist, thêm `X-Robots-Tag: noindex, nofollow` phù hợp; không đặt chính sách `Disallow: /` vào production và tưởng đó tương đương `noindex`.
- Tách sitemap content vs sản phẩm, sau đó hợp nhất theo **dữ liệu thật của toàn website**. **Không xuất sitemap mới chỉ gồm Pages/Posts lên production** nếu đang có sitemap sản phẩm.

### 5.4 Bảo toàn các URL chưa triển khai sản phẩm

**Mặc định an toàn:** Phase 1 chỉ chạy ở staging, production WordPress tiếp tục phục vụ mọi URL cũ.

Chỉ được nghiên cứu cutover khi đã chọn một trong các phương án và kiểm thử đầy đủ:

1. **Di chuyển cả sản phẩm ở giai đoạn tiếp theo rồi mới cutover** (ưu tiên khi khối lượng cho phép).
2. **Hybrid routing**: lớp reverse proxy tiếp tục phục vụ `/product/.../`, `/shop/.../`, taxonomy sản phẩm, media, API/tương tác WooCommerce từ hệ legacy đã được giữ nguyên, trong khi website mới phục vụ Pages/Posts. Phương án này cực nhạy cảm: phải giải quyết URL root collisions, Host header, canonical, SSL, session/cart, auth, cache, cookies, WebP, querystring, SEO metadata, database routing, robots, sitemap, redirect loops, 5xx và origin fallback. **Không được chọn nếu không chứng minh được mọi URL legacy còn hoạt động**.

Không dùng một redirect mù từ `/product/...` tới domain phụ hay trang chủ để “giải quyết” tạm. Không tự động bảo toàn 100% đường dẫn chỉ bằng `301` đến chỗ khác.

**Cổng C:** importer dry-run thành công không có dữ liệu Misutech dính vào bản Hải Phòng Tech, có route registry đầy đủ, schema/index ổn định, không có lỗi trùng khóa hoặc xung đột chưa xử lý.

## 6. GIAI ĐOẠN D — TRIỂN KHAI NỘI DUNG Ở STAGING

### 6.1 Thứ tự thực hiện

1. Đổi branding/config *trên staging* đúng doanh nghiệp theo nguồn đã xác nhận; không đụng secrets/production.
2. Import media cần cho Pages/Posts, lưu mapping và bản kiểm tra file/hash/alt.
3. Import Pages (bao gồm chính sách, giới thiệu, liên hệ) cùng parent tree, original URL và SEO; bảo toàn nội dung pháp lý nguyên văn cho đến khi duyệt.
4. Import Categories/Tags cho **Posts** và metadata/taxonomy archives, tránh nhầm WooCommerce product categories.
5. Import Posts, relationships, featured images, SEO, tên tác giả hiển thị, dates, internal links, link downloads.
6. Dựng `/tin-blog/`, `/tu-dong-hoa/`, `/tag/.../`, archives và paginations từ dữ liệu thực.
7. Kết nối menu/footer, liên hệ, social, tìm kiếm, breadcrumb và các tính năng content khác.
8. Kiểm thử hồi quy, sửa lỗi mapping/dữ liệu, chạy lại importer dry-run: lần thứ hai **không tự tạo bản ghi trùng**.

### 6.2 Danh sách chính sách công khai tối thiểu phải đối chiếu

- `/chinh-sach-bao-mat-du-lieu-chuan-gdpr-iso-27001/`
- `/chinh-sach-bao-mat-thong-tin-ca-nhan-information-privacy/`
- `/chinh-sach-thanh-toan-an-toan-da-dang-cod-chuyen-khoan/`
- `/chinh-sach-bao-hanh-thiet-bi-cong-nghiep-chinh-hang-warranty-policy/`
- `/chinh-sach-van-chuyen-nhanh-an-toan-giao-toan-quoc/`
- `/chinh-sach-doi-tra-san-pham-return-refund-policy/`
- `/dieu-khoan-su-dung-website-quy-dinh-trach-nhiem/`
- `/chinh-sach-bao-mat-thong-tin-khach-hang-privacy-policy/`

Đây là **tập seed kiểm thử**, không phải danh sách pháp lý đầy đủ. Mỗi văn bản cần kiểm tra URL, tiêu đề, nội dung, headings, bảng, email/số điện thoại, metadata và các liên kết đến chính sách khác.

### 6.3 Khác biệt giao diện được phép

Được phép thay layout, CSS, responsive, font, tốc độ, component markup, HTML wrapper **nếu không làm mất** nội dung, ý nghĩa, URL, SEO meta, media, link và khả năng truy cập. Không cần HTML byte-identical vì theme đã thay đổi; **cần content fidelity** (đủ đoạn/bảng/list/heading/CTA/ảnh, cùng nội dung gốc). Mọi chủ ý sửa nội dung phải có ticket/approval riêng.

**Cổng D:** nội dung trong phạm vi có đối chiếu 1:1, ảnh và link hoạt động, không còn dấu Misutech trên UI/meta/schema, staging an toàn noindex, không có lỗi import.

## 7. GIAI ĐOẠN E — HỆ THỐNG TEST VÀ BÁO CÁO BẮT BUỘC

### 7.1 Automated tests cần viết

- `UrlParityTest`: duyệt **100% URL đã kiểm kê** (chạy trên staging, giới hạn tải production), so sánh expected status/redirect target, không 404 bất ngờ, không 5xx, không soft-404.
- `ContentParityTest`: so sánh source IDs, slugs/permalinks, content semantic fingerprint (không so byte HTML), H1, heading order, number of tables/images/downloads, excerpt, publish/modified, author và taxonomy; ngoại lệ có lý do.
- `SeoParityTest`: title/description/canonical/robots, Open Graph, breadcrumbs, schema JSON-LD parse, sitemap membership, noindex/duplicate and hreflang nếu có.
- `MediaIntegrityTest`: 100% media references trên những Pages/Posts nhập, kiểm tra HTTP 200, MIME đúng, không zero-byte, hash khi đồng bộ bản gốc, alt/caption và file tải được.
- `InternalLinksTest`: 100% liên kết nội bộ trong Pages/Posts không lỗi 404/500, không trỏ domain misutech.vn hoặc staging trong output dùng cho production.
- `RouteCollisionTest`: root Pages/Posts/brand/category/Product/Tag routes, `/product/{sku}/`, `/shop/page/{n}/`, `/tag/{slug}/`, querystrings, URL Unicode, trailing slashes, percent-encoded paths, HTTP→HTTPS và www canonical.
- `NegativeTest`: URL không tồn tại vẫn trả 404 (không 200 giả); input form được kiểm tra/rate-limit, XSS/HTML sanitized, không lộ traceback, DB info, env/secrets.
- `PerformanceTest`: critical pages trên staging trong điều kiện tải representative, DB index, pagination, timeout, memory limits, cache invalidation, concurrent requests, thundering herd.
- `AccessibilityAndUXTest`: heading hierarchy, responsive, navigation, forms, keyboard, image loading; báo cả lỗi nguồn đã tồn tại để phân biệt với regression.
- `RetryIdempotencyTest`: chạy import lần 2 và phục hồi sau partial failure không nhân đôi/ghi sai dữ liệu.

### 7.2 Mức nghiệm thu

- URL inventory: **100% được phân loại**; không có URL chưa ánh xạ mà vẫn tuyên bố dự án hoàn tất. Khác biệt được duyệt phải nằm trong `approved_exceptions.csv` ghi rõ reason/approver/date.
- URL Pages/Posts/archives trong phạm vi: **100% expected active URLs** phải có đúng route, status và nội dung; **0 lỗi 5xx**, **0 lỗi 404 mới** trên tập kiểm thử. Không tính thành công các 404/410 lịch sử là lỗi mới.
- Media được reference bởi Pages/Posts: **100% được xác minh** hoặc có exception/phương án được duyệt.
- SEO: **0 canonical về Misutech/staging sai**, **0 noindex production ngoài ý muốn**, **0 sitemap bị thay thiếu sản phẩm**, không có redirect loop.
- Dữ liệu: **0 bản ghi Misutech rò sang frontend**, **0 thao tác ghi production trái phép**, **0 secret/PII bị commit**.
- Baseline source rộng: thống kê từ DB/sitemap, test HTTP chọn mẫu được phân tầng và throttle; không chạy vô tội vạ 100k request lên nguồn live.
- Các ngưỡng performance, error rate, monitoring và rollback thời gian thực phải do người vận hành thiết lập theo baseline hạ tầng thực tế; **không tự đặt SLO số học tùy tiện**.

### 7.3 Cấu trúc báo cáo kiểm toán

- `docs/migration/02_MAPPING_AND_CONFLICTS.md` — mapping schema + slug conflicts + decisions.
- `docs/migration/03_IMPORT_DRY_RUN.md` — input hash, totals, inserts/updates/conflicts, no-op validation.
- `docs/migration/04_PARITY_REPORT.md` — bảng PASS/FAIL/UNKNOWN, theo loại content, sitewide, URLs, assets, SEO.
- `docs/migration/05_RELEASE_BLOCKERS.md` — issues P0/P1; không xóa/cắt qua được nếu chưa xử lý.
- `docs/migration/06_ROLLBACK_RUNBOOK.md` — cách khôi phục đã **diễn tập trên staging**, dependency/host/cache/DNS/DB.
- `docs/migration/07_SIGNOFF.md` — tên/vai trò người phê duyệt, checklist có dấu thời gian, snapshot build commit, kết quả smoke test.
- `docs/migration/data/url_parity_results.csv` — `source_url,expected_status,expected_final_url,target_status,target_final_url,content_match,seo_match,media_match,result,reason`.
- `docs/migration/data/approved_exceptions.csv` — phê duyệt các khác biệt có chủ đích.

**Cổng E:** test đạt đầy đủ cho phần content và sản phẩm/route cũ **nếu có dự định cutover**; lưu chứng cứ và danh sách bất thường. Nếu chưa hoàn thiện các sản phẩm thì kết luận chỉ có thể là: **“Phase 1 staging complete; production cutover BLOCKED.”**

## 8. GIAI ĐOẠN F — CUTOVER (TÀI LIỆU CHỈ ĐỂ CHUẨN BỊ, KHÔNG ĐƯỢC TỰ THỰC HIỆN)

1. Chỉ được bắt đầu sau khi công ty cấp quyền, ký duyệt release checklist, có đủ module sản phẩm hoặc hybrid routing chứng minh parity toàn bộ URL, backup restore đã diễn tập, có cửa sổ bảo trì và đội trực.
2. Đóng băng cập nhật nội dung có thời hạn; chạy delta-import từ snapshot mới nhất, reconfirm URL/SEO/sitemap, kiểm kê mọi thay đổi sau baseline và tránh mất bài mới.
3. Chuẩn bị staging có HTTPS/TLS hợp lệ, CDN/cache/headers/reverse proxy settings; config canonical/robots cho production theo host. Test qua hosts override hoặc hệ thống preview an toàn mà không để staging index.
4. Tách cơ sở dữ liệu cũ và mới theo chiến lược đã phê duyệt, xử lý writes đồng thời và rollback data; đảm bảo legacy còn phục vụ hoặc có snapshot khôi phục. Không đoán rằng rollback DNS là đủ cho dữ liệu.
5. Chỉ một người được ủy quyền thực hiện switch theo runbook, các bên giám sát HTTP errors, logs, CDN, crawl, sitemap, Search Console, index, uptime, DB, PHP errors, slow queries, memory và tài nguyên host.
6. **Rollback triggers**: bất kỳ 5xx bất thường tăng, URL quan trọng 404/missing, lỗi product pages, sai canonical/noindex, sitemap biến mất, dữ liệu sai, không gửi được form quan trọng, lỗi auth/session/checkout (nếu đang hoạt động), dấu hiệu mất nội dung. Có ngưỡng và vai trò người quyết định đã chốt trước cutover.
7. Rollback phải khôi phục routing, TLS, cached content, sitemap, SEO, DB state nếu phát sinh writes, kiểm tra lại từ ngoài mạng. **Không xóa legacy** cho đến khi nghiệm thu ổn định theo lịch đã duyệt.
8. Sau cutover quan sát log URL 404/500 và Search Console, đối chiếu những trang có traffic/Google indexed, tìm redirect/canonical/sitemap regressions, sửa có ticket. Việc hệ thống online không đồng nghĩa index và SEO đã ổn định.

**Điều kiện mặc định hiện nay:** không có giấy phép cutover trong yêu cầu này; AI chỉ viết code cho **staging** và báo cáo, không deploy.

## 9. QUY TẮC KHI PHÁT HIỆN MÂU THUẪN

| Tình huống | Hành động bắt buộc |
|---|---|
| Cùng slug root giữa Blog và Product Category/Page/Brand | Báo conflict, truy xuất URL thực + rewrite priority từ nguồn, không random chọn route |
| WP export không chứa custom fields/pagebuilder | Dừng importer cho trường thiếu, yêu cầu export đầy đủ hoặc renderer phù hợp |
| Không truy cập được sitemap/XML | Ghi UNKNOWN; lấy inventory từ DB/backup và xác minh sitemap theo đường khác, không coi là rỗng |
| Tệp media thiếu hoặc đổi đường dẫn | Tìm source file/backup; giữ nguyên URL legacy hoặc phương án rewrite có test |
| URL cũ 301 tới slug khác | Giữ baseline redirect policy, không import thành duplicate 200 ở cả hai URL tùy tiện |
| Nội dung cũ có placeholder/khẳng định chưa kiểm chứng | Gắn cờ, không tự sáng tác hoặc xóa |
| Contact info cũ không nhất quán | Báo người phụ trách quyết định nguồn sự thật, không hợp nhất tự phát |
| Import tạo trùng bản ghi | Rollback lô, sửa mapping/idempotency rồi chạy lại dry-run |
| Đích có content từ Misutech | Chặn publish; chỉ sửa theo mapping/backup ở staging |
| Product route/sitemap/ảnh không có trên site mới | **BLOCK CUTOVER** dù pages đã xong |
| Lỗi 500 ở source hoặc target | Ghi status và stack trace an toàn (redacted), đánh giá riêng lỗi đã có ở source so với regression |
| Một page chạy nhưng canonical/noindex sai | FAIL; không được nghiệm thu chỉ bằng HTTP 200 |

## 10. THỨ TỰ AI CẦN THỰC HIỆN TRONG IDE

**Bước 1 — Không sửa code:** lập inventory project, đọc file config/routes/models/views/tests/migrations và tài liệu đang có. Tạo `00_TARGET_RECON.md`, liệt kê khả năng/thiếu dữ liệu; báo trước rủi ro.

**Bước 2 — Không sửa code:** tạo bộ script/công cụ xuất inventory **chỉ đọc** từ export WP/backup/REST/sitemap theo điều kiện truy cập thực tế; nếu chưa có nguồn, tạo tool + chỉ dẫn người quản trị cấp bản xuất; không tự đoán hoặc crawl gây tải lớn.

**Bước 3 — Chỉ staging:** viết tests URL/source data/mapping trước, thiết kế schema trong repo đích có khả năng roll back. Không sửa `.env`/DB production.

**Bước 4 — Chỉ staging:** viết importer media → pages → post taxonomies → posts → menus/footer/SEO, import theo lô, chạy `dry-run` và review diff trước apply staging.

**Bước 5 — Chỉ staging:** khôi phục giao diện mới đúng thương hiệu và render đầy đủ content. Không thay URL vì “chuẩn SEO hơn”.

**Bước 6 — Chỉ staging:** test URL, HTML parity, SEO parity, media, sitemap scope, performance, security, negative cases; sinh báo cáo nêu rõ tất cả **PASS/FAIL/UNKNOWN**.

**Bước 7 — DỪNG trước production:** trình bày kết quả, blockers, link tài liệu, file đã sửa, commits, commands đã chạy, commands chưa chạy và lý do. **KHÔNG tự deploy/cutover**.

## 11. MẪU BIÊN BẢN TỔNG KẾT AI (BẮT BUỘC MỖI LẦN CHẠY)

```text
RUN ID:
GIT BRANCH / COMMIT:
SOURCE SNAPSHOT ID + TIME + CHECKSUM:
TARGET ENVIRONMENT: staging (production writes: 0)
SOURCE INVENTORY: pages=..., posts=..., post_categories=..., tags=..., product_routes_reserved=..., assets=..., unknown=...
IMPORT DRY-RUN: inserts=..., updates=..., skips=..., conflicts=..., errors=...
STAGING APPLY (IF APPROVED): inserts=..., updates=..., skips=...
URL PARITY: passed=..., failed=..., unknown=...
SEO PARITY: passed=..., failed=..., unknown=...
MEDIA PARITY: passed=..., failed=..., unknown=...
MISUTECH LEAKS: ...
OPEN P0/P1 ISSUES: ...
CHANGED FILES: ...
TEST COMMANDS AND RESULTS: ...
ROLLBACK PROOF: ...
PHASE 1 STATUS: BLOCKED | STAGING READY
PRODUCTION CUTOVER: BLOCKED (unless separate written authorization and full legacy product parity)
```

## 12. DANH SÁCH ĐIỀU TUYỆT ĐỐI CẤM ĐỂ “LÀM CHO NHANH”

- Không tự tạo hàng loạt redirect 301 tới `/` hoặc `/shop/` để giấu 404.
- Không dùng `migrate:fresh`, `db:wipe`, `DROP TABLE`, `TRUNCATE`, bulk delete trên môi trường đang có dữ liệu; không chỉnh SQL production.
- Không đổi `/product/{sku}/` thành `/san-pham/{slug}/` dù dự án Misutech đã có route đó.
- Không dùng sitemap vài trăm URL content để ghi đè sitemap hơn trăm nghìn URL của website hiện tại.
- Không tự thay WP media URLs, không bỏ tệp PDF/ảnh chỉ vì chưa thuộc nội dung Page/Post.
- Không tự đăng lại bài như bài mới, đổi published date/author/canonical/og:image.
- Không copy toàn bộ DB WordPress có PII vào repo hoặc public AI prompts; không log credentials.
- Không đặt `noindex` lên production hay chặn `robots.txt` một cách tùy tiện.
- Không cam kết “đảm bảo 100% SEO không thay đổi” khi chưa có baseline và quan sát sau cutover.
- Không tự xóa data Misutech trên **cơ sở dữ liệu gốc misutech.vn**, dù nằm cùng server.

---

## 13. CHỈ DẪN CHO NGƯỜI QUẢN TRỊ TRƯỚC KHI CẤP QUYỀN TRIỂN KHAI

Cần cung cấp cho AI/nhóm kỹ thuật trong môi trường bảo mật có kiểm soát:

- Quyền xem **mã nguồn đích** và **schema DB đích staging**.
- Bản **export WordPress/source backup đã được sàng lọc** hoặc quyền đọc có kiểm soát; quyền tải `wp-content/uploads` phù hợp.
- Cấu hình permalink, sitemap, SEO fields và redirects nguồn hiện tại.
- Bản sao sitemap cùng các báo cáo index/404 (nếu có), số lượng records thực tế.
- Người phê duyệt tính đúng đắn content/chính sách/doanh nghiệp; người phê duyệt kỹ thuật và người quản trị DNS/hosting.

**Kết luận:** hoàn thiện Pages/Posts trong staging là nhiệm vụ giai đoạn 1. Cutover ở `haiphongtech.vn` là một dự án triển khai riêng chỉ được phép thực hiện khi mọi URL và chức năng di sản — đặc biệt danh mục, sản phẩm và media — đã có phương án bảo toàn được chứng minh bằng kiểm thử. Nếu chưa đủ bằng chứng, giữ website cũ hoạt động và báo rõ blocker, không làm liều.
