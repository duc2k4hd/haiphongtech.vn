<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Str;

/**
 * Dịch vụ ánh xạ chuỗi danh mục CSV (cấu trúc Hãng > Thiết bị Hãng > Dòng)
 * về đúng cây danh mục thiết bị trong bảng `categories`.
 *
 * Thiết kế tối ưu tốc độ: nạp toàn bộ bảng tra cứu 1 lần vào RAM,
 * không thực hiện thêm bất kỳ truy vấn DB nào trong quá trình khớp.
 */
class CategoryMatcherService
{
    /** @var array<string, int> slug => category_id */
    protected array $idBySlug = [];

    /** @var array<string, int> normalized lowercase name => category_id */
    protected array $idByLowerName = [];

    /** @var array<int, int> category_id => parent_id (0 nếu là gốc) */
    protected array $parentById = [];

    /** @var array<int, int> category_id => độ sâu trong cây DB (0 = gốc, 1 = con, 2 = cháu) */
    protected array $depthById = [];

    /** @var array<int, string> category_id => tên gốc để gỡ lỗi / kiểm chứng */
    protected array $nameById = [];

    /**
     * Danh sách quy tắc từ khóa đã biên dịch, sắp xếp theo độ ưu tiên & độ dài giảm dần.
     * Mỗi phần tử: ['keyword' => string, 'cat_id' => int, 'word_boundary' => bool, 'len' => int]
     *
     * @var array<int, array{keyword: string, cat_id: int, word_boundary: bool, len: int}>
     */
    protected array $keywordRules = [];

    /** @var array<string, array{0: int|null, 1: array<int, int>}> Cache kết quả trong RAM theo chuỗi đầu vào */
    protected array $memoCache = [];

    public function __construct()
    {
        $this->preload();
    }

    /**
     * Nạp toàn bộ danh mục sản phẩm đang hoạt động và biên dịch bảng quy tắc khớp trong RAM.
     */
    public function preload(): void
    {
        $this->idBySlug = [];
        $this->idByLowerName = [];
        $this->parentById = [];
        $this->depthById = [];
        $this->nameById = [];
        $this->keywordRules = [];
        $this->memoCache = [];

        $categories = Category::where('type', 'product')
            ->where('status', 'active')
            ->select(['id', 'name', 'slug', 'parent_id'])
            ->get();

        foreach ($categories as $cat) {
            $id = (int) $cat->id;
            $slug = strtolower(trim((string) $cat->slug));
            $name = trim((string) $cat->name);

            $this->parentById[$id] = (int) ($cat->parent_id ?? 0);
            $this->nameById[$id] = $name;

            if ($slug !== '') {
                $this->idBySlug[$slug] = $id;
            }
            if ($name !== '') {
                $this->idByLowerName[mb_strtolower($name, 'UTF-8')] = $id;
            }
        }

        // Tính độ sâu cây danh mục trong DB (0 = gốc, 1 = con, 2 = cháu...)
        foreach (array_keys($this->parentById) as $id) {
            $depth = 0;
            $cursor = $this->parentById[$id];
            $guard = 0;
            while ($cursor > 0 && isset($this->parentById[$cursor]) && $guard < 10) {
                $depth++;
                $cursor = $this->parentById[$cursor];
                $guard++;
            }
            $this->depthById[$id] = $depth;
        }

        $this->compileKeywordRules();
    }

    /**
     * Khớp chuỗi `Danh mục` trong CSV (kèm tinh chỉnh xuống danh mục con theo tên sản phẩm
     * và dự phòng theo tên sản phẩm khi cột danh mục chỉ có tên hãng).
     *
     * Lưu ý: Chỉ trả về danh mục lá/cụ thể nhất cho `category_product` vì HomeController,
     * ShopController và CategoryController đều đã tự cộng dồn danh mục con cháu; không chèn
     * danh mục cha để tránh đếm trùng số lượng sản phẩm.
     *
     * @return array{0: int|null, 1: array<int, int>} [primaryCategoryId, matchedCategoryIds]
     */
    public function resolve(string $rawCategories, string $productName = ''): array
    {
        $rawCategories = trim($rawCategories);
        $productName = trim($productName);

        $primaryId = null;

        if ($rawCategories !== '') {
            $primaryId = $this->matchFromCategoryPaths($rawCategories, $productName);
        }

        // Tinh chỉnh: nếu cột Danh mục chỉ khớp danh mục rộng (vd: PLC, Đầu nối, Bộ nguồn)
        // nhưng tên sản phẩm chỉ rõ danh mục con/cụ thể hơn (vd: Đế PLC, Chân pin, Bộ nguồn tổ ong),
        // ưu tiên danh mục cụ thể hơn đó nếu cùng nhánh gốc trong cây DB.
        if ($primaryId !== null && $productName !== '') {
            $nameMatchedId = $this->matchSegment($productName);
            if ($nameMatchedId !== null && $nameMatchedId !== $primaryId) {
                $primaryDepth = $this->depthById[$primaryId] ?? 0;
                $nameDepth = $this->depthById[$nameMatchedId] ?? 0;

                if ($this->isDescendantOf($nameMatchedId, $primaryId)
                    || ($nameDepth >= $primaryDepth && $this->getRootId($nameMatchedId) === $this->getRootId($primaryId) && $this->isSpecificRefinement($nameMatchedId))
                ) {
                    $primaryId = $nameMatchedId;
                }
            }
        }

        // Dự phòng: khi cột Danh mục trống hoặc chỉ ghi tên hãng (vd: "MITSUBISHI", "PROFACE", "OMRON")
        if ($primaryId === null && $productName !== '') {
            $primaryId = $this->matchSegment($productName);
        }

        if ($primaryId === null) {
            return [null, []];
        }

        return [$primaryId, [$primaryId]];
    }

    protected function isDescendantOf(int $candidateId, int $ancestorId): bool
    {
        $cursor = $this->parentById[$candidateId] ?? 0;
        $guard = 0;
        while ($cursor > 0 && $guard < 10) {
            if ($cursor === $ancestorId) {
                return true;
            }
            $cursor = $this->parentById[$cursor] ?? 0;
            $guard++;
        }

        return false;
    }

    protected function getRootId(int $categoryId): int
    {
        $cursor = $categoryId;
        $guard = 0;
        while (($this->parentById[$cursor] ?? 0) > 0 && $guard < 10) {
            $cursor = $this->parentById[$cursor];
            $guard++;
        }

        return $cursor;
    }

    protected function isSpecificRefinement(int $categoryId): bool
    {
        $slug = array_search($categoryId, $this->idBySlug, true);

        return in_array($slug, [
            'chan-pin',
            'giac-cam',
            'de-plc',
            'cap-lap-trinh-plc',
            'de-ro-le',
            'bo-nguon-to-ong',
            'bo-chuyen-nguon',
        ], true);
    }

    public function getCategoryName(int $id): string
    {
        return $this->nameById[$id] ?? ('#' . $id);
    }

    /**
     * Lấy danh sách ID gồm chính danh mục đó và toàn bộ tổ tiên lên tới danh mục gốc.
     *
     * @return array<int, int>
     */
    public function expandWithAncestors(int $categoryId): array
    {
        $ids = [];
        $cursor = $categoryId;
        $guard = 0;

        while ($cursor > 0 && isset($this->parentById[$cursor]) && $guard < 10) {
            $ids[] = $cursor;
            $cursor = $this->parentById[$cursor];
            $guard++;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Khớp danh mục từ chuỗi nhiều đường dẫn phân tách bởi dấu phẩy (vd: "OMRON, OMRON > Bộ nguồn Omron > S8VK OMRON").
     * Duyệt ngược từ nhánh sâu nhất lên gốc để nhánh cụ thể hơn (như "Phụ kiện cảm biến", "Đế rơ le",
     * "Bộ khuếch đại cảm biến sợi quang") luôn thắng nhánh cha.
     */
    protected function matchFromCategoryPaths(string $rawCategories, string $productName): ?int
    {
        $paths = array_filter(array_map('trim', explode(',', $rawCategories)));
        $bestCatId = null;
        $bestScore = -1;

        foreach ($paths as $path) {
            $segments = array_values(array_filter(array_map('trim', explode('>', $path)), fn ($v) => $v !== ''));
            $count = count($segments);
            if ($count === 0) {
                continue;
            }

            // Duyệt ngược từ nhánh sâu nhất (index = count - 1) về gốc (index = 0)
            for ($idx = $count - 1; $idx >= 0; $idx--) {
                $seg = $segments[$idx];
                $matched = $this->matchSegmentDetailed($seg, $productName);
                if ($matched !== null) {
                    // Điểm ưu tiên: độ sâu nhánh CSV * 10000 + độ sâu cây DB * 1000 + độ dài từ khóa khớp
                    $catId = $matched['cat_id'];
                    $score = ($idx * 10000) + (($this->depthById[$catId] ?? 0) * 1000) + $matched['len'];
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestCatId = $catId;
                    }
                    // Đã tìm thấy khớp ở nhánh sâu nhất của đường dẫn này -> dừng đi ngược lên cha
                    break;
                }
            }
        }

        return $bestCatId;
    }

    protected function dependsOnProductName(string $rawCategories): bool
    {
        $lower = mb_strtolower($rawCategories, 'UTF-8');

        return str_contains($lower, 'servo') && ! str_contains($lower, 'driver') && ! str_contains($lower, 'motor') && ! str_contains($lower, 'cáp');
    }

    protected function matchSegment(string $text): ?int
    {
        $res = $this->matchSegmentDetailed($text, '');

        return $res['cat_id'] ?? null;
    }

    /**
     * @return array{cat_id: int, len: int}|null
     */
    protected function matchSegmentDetailed(string $text, string $productName): ?array
    {
        $clean = trim($text);
        if ($clean === '') {
            return null;
        }

        $lower = mb_strtolower(preg_replace('/\s+/u', ' ', $clean) ?? $clean, 'UTF-8');

        // 1. Khớp chính xác 100% theo tên hoặc slug trong DB
        if (isset($this->idByLowerName[$lower])) {
            return ['cat_id' => $this->idByLowerName[$lower], 'len' => mb_strlen($lower, 'UTF-8') + 50];
        }

        $slug = Str::slug($clean);
        if ($slug !== '' && isset($this->idBySlug[$slug])) {
            return ['cat_id' => $this->idBySlug[$slug], 'len' => mb_strlen($slug, 'UTF-8') + 50];
        }

        // 2. Xử lý trường hợp đặc biệt cho nhóm "Servo <Hãng>" chung chung (phân biệt Driver / Motor / Cáp theo tên sản phẩm)
        if (preg_match('/\bservo\b/ui', $lower) && ! str_contains($lower, 'driver') && ! str_contains($lower, 'bộ điều khiển') && ! str_contains($lower, 'động cơ') && ! str_contains($lower, 'motor') && ! str_contains($lower, 'mottor') && ! str_contains($lower, 'cáp') && ! str_contains($lower, 'cable')) {
            $nameLower = mb_strtolower($productName, 'UTF-8');
            if ($nameLower !== '') {
                if (str_contains($nameLower, 'cáp') || str_contains($nameLower, 'cable')) {
                    if (isset($this->idBySlug['cap-servo'])) {
                        return ['cat_id' => $this->idBySlug['cap-servo'], 'len' => 12];
                    }
                }
                if (str_contains($nameLower, 'động cơ') || str_contains($nameLower, 'motor')) {
                    if (isset($this->idBySlug['dong-co-servo'])) {
                        return ['cat_id' => $this->idBySlug['dong-co-servo'], 'len' => 12];
                    }
                }
            }
            if (isset($this->idBySlug['bo-dieu-khien-servo'])) {
                return ['cat_id' => $this->idBySlug['bo-dieu-khien-servo'], 'len' => 8];
            }
        }

        // 3. Quét bảng quy tắc từ khóa đã sắp xếp từ dài nhất / cụ thể nhất xuống ngắn nhất
        foreach ($this->keywordRules as $rule) {
            if ($rule['word_boundary']) {
                if (preg_match('/(?<![a-z0-9à-ỹ])' . preg_quote($rule['keyword'], '/') . '(?![a-z0-9à-ỹ])/ui', $lower)) {
                    return ['cat_id' => $rule['cat_id'], 'len' => $rule['len']];
                }
            } else {
                if (str_contains($lower, $rule['keyword'])) {
                    return ['cat_id' => $rule['cat_id'], 'len' => $rule['len']];
                }
            }
        }

        return null;
    }

    /**
     * Biên dịch toàn bộ quy tắc khớp: gồm tên chuẩn từ DB + từ đồng nghĩa ngành tự động hóa.
     */
    protected function compileKeywordRules(): void
    {
        $rules = [];

        $addRule = function (string $keyword, string $slug, bool $wordBoundary = false) use (&$rules): void {
            if (! isset($this->idBySlug[$slug])) {
                return;
            }
            $kw = mb_strtolower(trim($keyword), 'UTF-8');
            if ($kw === '') {
                return;
            }
            $catId = $this->idBySlug[$slug];
            $key = $kw . '|' . ($wordBoundary ? '1' : '0');
            if (! isset($rules[$key])) {
                $rules[$key] = [
                    'keyword' => $kw,
                    'cat_id' => $catId,
                    'word_boundary' => $wordBoundary,
                    'len' => mb_strlen($kw, 'UTF-8'),
                ];
            }
        };

        // 1. Tự động đưa toàn bộ tên danh mục trong DB (bỏ phần ngoặc đơn giải thích) vào quy tắc
        foreach ($this->idBySlug as $slug => $catId) {
            $name = $this->nameById[$catId] ?? '';
            $baseName = trim((string) preg_replace('/\s*\([^)]*\)/u', '', $name));
            if ($baseName !== '' && mb_strlen($baseName, 'UTF-8') >= 3) {
                $needsBoundary = mb_strlen($baseName, 'UTF-8') <= 5 && preg_match('/^[a-z0-9]+$/i', $baseName);
                $addRule($baseName, $slug, (bool) $needsBoundary);
            }
        }

        // 2. Bảng từ đồng nghĩa / biến thể phổ biến trong file CSV HPT (đã đối chiếu với 103 danh mục DB)
        $synonyms = [
            // Bộ khuếch đại cảm biến (phải khớp trước Cảm biến)
            ['bộ khuếch đại cảm biến sợi quang', 'bo-khuech-dai-cam-bien-soi-quang', false],
            ['khuếch đại cảm biến sợi quang', 'bo-khuech-dai-cam-bien-soi-quang', false],
            ['bộ khuếch đại sợi quang', 'bo-khuech-dai-cam-bien-soi-quang', false],
            ['bộ khuếch đại cảm biến quang', 'bo-khuech-dai-cam-bien-quang', false],
            ['bộ khuếch đại cảm biến tiệm cận', 'bo-khuech-dai-cam-bien-tiem-can', false],
            ['bộ khuếch đại cảm biến tiếp xúc', 'bo-khuech-dai-cam-bien-tiep-xuc', false],
            ['bộ khuếch đại cảm biến rò rỉ', 'bo-khuech-dai-cam-bien-ro-ri-chat-long', false],
            ['bộ khuếch đại cảm biến từ', 'bo-khuech-dai-cam-bien-tu', false],
            ['bộ khuếch đại cảm biến', 'bo-khuech-dai-cam-bien', false],
            ['bộ khuếch đại', 'bo-khuech-dai', false],

            // Phụ kiện cảm biến (phải khớp trước Cảm biến)
            ['gương phản xạ cảm biến', 'guong-phan-xa-cam-bien', false],
            ['gương phản xạ', 'guong-phan-xa-cam-bien', false],
            ['gá cảm biến', 'ga-cam-bien', false],
            ['giá đỡ cảm biến', 'ga-cam-bien', false],
            ['cáp nối cảm biến', 'cap-cam-bien', false],
            ['cáp cảm biến', 'cap-cam-bien', false],
            ['cáp kết nối cảm biến', 'cap-cam-bien', false],
            ['phụ kiện cảm biến', 'phu-kien-cam-bien', false],

            // Cảm biến
            ['cảm biến vùng an toàn', 'cam-bien-vung', false],
            ['cảm biến an toàn', 'cam-bien-vung', false],
            ['cảm biến vùng', 'cam-bien-vung', false],
            ['màn chắn sáng', 'cam-bien-vung', false],
            ['cảm biến sợi quang', 'cam-bien-soi-quang', false],
            ['đầu sợi quang', 'cam-bien-soi-quang', false],
            ['cảm biến quang', 'cam-bien-quang', false],
            ['cảm biến laser', 'cam-bien-quang', false],
            ['cảm biến tiệm cận', 'cam-bien-tiem-can', false],
            ['cảm biến áp suất', 'cam-bien-ap-suat', false],
            ['cảm biến nhiệt độ', 'cam-bien-nhiet-do', false],
            ['can nhiệt', 'cam-bien-nhiet-do', false],
            ['cảm biến mức', 'cam-bien-muc', false],
            ['cảm biến lưu lượng', 'cam-bien-luu-luong', false],
            ['cảm biến dòng điện', 'cam-bien-dong-dien', false],
            ['cảm biến hồng ngoại', 'cam-bien-hong-ngoai', false],
            ['cảm biến hình ảnh', 'cam-bien-hinh-anh', false],
            ['vision 3d', 'cam-bien-hinh-anh', false],
            ['vision 2d', 'cam-bien-hinh-anh', false],
            ['cảm biến thị giác', 'cam-bien-hinh-anh', false],
            ['cảm biến siêu âm', 'cam-bien-sieu-am', false],
            ['cảm biến dịch chuyển', 'cam-bien-dich-chuyen', false],
            ['cảm biến đo khoảng cách', 'cam-bien-dich-chuyen', false],
            ['cảm biến cửa', 'cam-bien-cua', false],
            ['cảm biến màu', 'cam-bien-mau', false],
            ['cảm biến vị trí', 'cam-bien-vi-tri', false],
            ['đầu cảm biến', 'cam-bien', false],
            ['cảm biến', 'cam-bien', false],

            // Servo & Điều khiển
            ['driver servo', 'bo-dieu-khien-servo', false],
            ['servo driver', 'bo-dieu-khien-servo', false],
            ['bộ điều khiển servo', 'bo-dieu-khien-servo', false],
            ['servo amp', 'bo-dieu-khien-servo', false],
            ['động cơ servo', 'dong-co-servo', false],
            ['servo motor', 'dong-co-servo', false],
            ['servo mottor', 'dong-co-servo', false],
            ['motor servo', 'dong-co-servo', false],
            ['bộ điều khiển nhiệt độ', 'bo-dieu-khien-nhiet-do', false],
            ['đồng hồ nhiệt độ', 'bo-dieu-khien-nhiet-do', false],
            ['đồng hồ nhiệt', 'bo-dieu-khien-nhiet-do', false],
            ['bộ điều khiển mức chất lỏng', 'bo-dieu-khien-muc', false],
            ['bộ điều khiển mức', 'bo-dieu-khien-muc', false],
            ['bộ điều khiển áp suất', 'bo-dieu-khien-ap-suat', false],
            ['bộ điều khiển động cơ', 'bo-dieu-khien-dong-co', false],
            ['động cơ bước', 'bo-dieu-khien-dong-co', false],
            ['bộ điều khiển pid', 'bo-dieu-khien-pid', false],
            ['bộ đặt thời gian', 'bo-dat-thoi-gian', false],
            ['bộ định thời', 'bo-dat-thoi-gian', false],
            ['timer', 'bo-dat-thoi-gian', true],
            ['bộ điều khiển thời gian', 'bo-dieu-khien-thoi-gian', false],
            ['bộ điều khiển an toàn', 'bo-dieu-khien-an-toan', false],
            ['bộ điều khiển dòng điện', 'bo-dieu-khien-dong-dien', false],
            ['bộ điều khiển cảm biến', 'bo-dieu-khien-cam-bien', false],
            ['bộ điều khiển chuyển động', 'bo-dieu-khien-chuyen-dong', false],
            ['bộ điều khiển hình ảnh', 'bo-dieu-khien-hinh-anh', false],
            ['bộ điều khiển camera', 'bo-dieu-khien-hinh-anh', false],
            ['bộ điều khiển nguồn', 'bo-dieu-khien', false],
            ['bộ điều khiển', 'bo-dieu-khien', false],

            // Dây điện - Cáp điện
            ['cáp lập trình plc', 'cap-lap-trinh-plc', false],
            ['cáp lập trình', 'cap-lap-trinh-plc', false],
            ['cáp servo', 'cap-servo', false],
            ['cáp encoder', 'cap-encoder', false],
            ['cáp io', 'cap-i-o', false],
            ['cáp i/o', 'cap-i-o', false],
            ['cáp nguồn', 'cap-nguon', false],
            ['cáp phanh', 'cap-phanh', false],
            ['cáp quang', 'cap-quang', false],
            ['cáp kết nối', 'day-dien', false],
            ['dây điện', 'day-dien', false],
            ['cáp điện', 'day-dien', false],

            // PLC
            ['đế plc', 'de-plc', false],
            ['base plc', 'de-plc', false],
            ['module truyền thông', 'module-truyen-thong', false],
            ['module giao tiếp', 'module-giao-tiep', false],
            ['module kỹ thuật số', 'module-ky-thuat-so', false],
            ['bộ lập trình plc', 'bo-lap-trinh-plc', false],
            ['plc', 'plc', true],

            // HMI
            ['màn hình hmi', 'hmi', false],
            ['màn hình cảm ứng', 'hmi', false],
            ['hmi', 'hmi', true],
            ['pfxsp5', 'hmi', false],
            ['sp5b', 'hmi', false],

            // Biến tần
            ['biến tần 1 pha', 'bien-tan-1-pha', false],
            ['biến tần 3 pha', 'bien-tan-3-pha', false],
            ['điện trở xả', 'dien-tro-xa', false],
            ['braking resistor', 'dien-tro-xa', false],
            ['biến tần', 'bien-tan', false],
            ['inverter', 'bien-tan', true],

            // Rơ le
            ['đế rơ le', 'de-ro-le', false],
            ['đế relay', 'de-ro-le', false],
            ['rơ le trung gian', 'ro-le-trung-gian', false],
            ['relay trung gian', 'ro-le-trung-gian', false],
            ['rơ le kiếng', 'ro-le-trung-gian', false],
            ['rơ le thời gian', 'ro-le-thoi-gian', false],
            ['relay thời gian', 'ro-le-thoi-gian', false],
            ['rơ le bán dẫn', 'ro-le-ban-dan', false],
            ['relay bán dẫn', 'ro-le-ban-dan', false],
            ['solid state relay', 'ro-le-ban-dan', false],
            ['rơ le an toàn', 'ro-le-an-toan', false],
            ['relay an toàn', 'ro-le-an-toan', false],
            ['rơ le nhiệt', 'ro-le-nhiet', false],
            ['relay nhiệt', 'ro-le-nhiet', false],
            ['rơ le bảo vệ pha', 'ro-le', false],
            ['rơ le', 'ro-le', false],
            ['relay', 'ro-le', true],

            // Bộ nguồn
            ['bộ nguồn tổ ong', 'bo-nguon-to-ong', false],
            ['nguồn tổ ong', 'bo-nguon-to-ong', false],
            ['bộ chuyển đổi nguồn', 'bo-chuyen-nguon', false],
            ['bộ chuyển nguồn', 'bo-chuyen-nguon', false],
            ['bộ nguồn', 'bo-nguon', false],
            ['nguồn xung', 'bo-nguon', false],
            ['power supply', 'bo-nguon', false],

            // Aptomat & Đóng cắt
            ['aptomat chống giật', 'aptomat-chong-giat', false],
            ['rcbo', 'aptomat-chong-giat', true],
            ['rccb', 'aptomat-chong-giat', true],
            ['elcb', 'aptomat-chong-giat', true],
            ['aptomat bảo vệ động cơ', 'mpcb', false],
            ['cb bảo vệ động cơ', 'mpcb', false],
            ['mpcb', 'mpcb', true],
            ['aptomat dc', 'aptomat-dc-solar', false],
            ['mccb', 'mccb', true],
            ['mcb', 'mcb', true],
            ['acb', 'acb', true],
            ['cầu chì', 'cau-chi', false],
            ['chống sét lan truyền', 'bo-chong-set', false],
            ['bộ chống sét', 'bo-chong-set', false],
            ['spd', 'bo-chong-set', true],
            ['cầu dao cách ly', 'aptomat', false],
            ['cầu dao', 'aptomat', false],
            ['aptomat', 'aptomat', false],

            // Contactor & Khởi động mềm
            ['khởi động từ', 'contactor', false],
            ['công tắc tơ', 'contactor', false],
            ['contactor', 'contactor', true],
            ['khởi động mềm', 'khoi-dong-mem', false],
            ['soft starter', 'khoi-dong-mem', false],

            // Công tắc, Nút nhấn, Đèn báo, Còi báo, Đầu nối
            ['công tắc hành trình', 'cong-tac-hanh-trinh', false],
            ['khóa an toàn', 'cong-tac-hanh-trinh', false],
            ['công tắc an toàn', 'cong-tac-hanh-trinh', false],
            ['nút dừng khẩn cấp', 'nut-nhan', false],
            ['nút nhấn', 'nut-nhan', false],
            ['đèn tháp', 'den-bao', false],
            ['đèn tín hiệu', 'den-bao', false],
            ['đèn cảnh báo', 'den-bao', false],
            ['đèn báo', 'den-bao', false],
            ['còi báo', 'coi-bao', false],
            ['còi điện tử', 'coi-bao', false],
            ['còi hụ', 'coi-bao', false],
            ['đầu nối', 'dau-noi', false],
            ['connector', 'dau-noi', true],
            ['giắc cắm', 'giac-cam', false],
            ['chân pin', 'chan-pin', false],
            ['ổ cắm', 'o-cam-phich-cam', false],
            ['phích cắm', 'o-cam-phich-cam', false],
            ['công tắc', 'cong-tac', false],

            // Khác
            ['encoder', 'encoder', true],
            ['bộ mã hóa vòng quay', 'encoder', false],
            ['biến dòng', 'bien-dong', false],
            ['bộ đếm', 'bo-dem', false],
            ['counter', 'bo-dem', true],
            ['đồng hồ đo', 'dong-ho', false],
            ['đồng hồ đa năng', 'dong-ho', false],
            ['đồng hồ', 'dong-ho', false],
            ['bộ lọc nhiễu', 'bo-loc-nhieu', false],
            ['cầu chia nguồn', 'cau-chia-nguon', false],
            ['cầu đấu điện', 'cau-dau-dien', false],
            ['cầu đấu', 'cau-dau-dien', false],
            ['terminal block', 'cau-dau-dien', false],
            ['máng điện nhựa', 'mang-dien-nhua', false],
            ['máng điện', 'mang-dien-nhua', false],
            ['hộp điện nhựa', 'hop-dien-nhua', false],
            ['hộp điện', 'hop-dien-nhua', false],
        ];

        foreach ($synonyms as [$kw, $slug, $wb]) {
            $addRule($kw, $slug, $wb);
        }

        // Sắp xếp theo độ dài từ khóa giảm dần, sau đó theo độ sâu cây DB giảm dần (ưu tiên danh mục con cụ thể)
        $list = array_values($rules);
        usort($list, function (array $a, array $b): int {
            if ($a['len'] !== $b['len']) {
                return $b['len'] <=> $a['len'];
            }

            return ($this->depthById[$b['cat_id']] ?? 0) <=> ($this->depthById[$a['cat_id']] ?? 0);
        });

        $this->keywordRules = $list;
    }
}
