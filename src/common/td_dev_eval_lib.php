<?php
/**
 * 產品開發評估表（AS 2-TD-02-01）—— 共用庫
 * 固定模板：確認項目及結果 32 項（人/機/料/法/發展/產品安全/仿冒零件的防制/其他），逐項是/否/N-A；
 * APQP 小組簽認 6 部門（技術/業務/管理/生產/品保/資材課）各自意見+簽章；生產課另有可行自製/委外/
 * 再評估/中止決行；總經理最終決行。簽核人資格：綁定部門任一主管皆可簽（org_role_lib，2026-08-12
 * 使用者拍板），部門內找不到有職級的主管時退回該部門職稱 sort_order 最高者；總經理欄走
 * org_role_lib 的 top_approver + delegate_lib 代理解析（ai-rules/18）。
 * 補資料用：具「操作確認密碼」資格者可一次輸入密碼把全部尚未簽的欄位補齊，但仍需逐格指定原簽核人
 * （2026-08-12 使用者明確要求，非直接蓋成自己名字）。
 *
 * 送出流程（2026-08-12 新增，使用者明確拍板）：draft(草稿，僅建立者/管理員可編輯表頭與32項) →
 * 送出「submit」後鎖定表頭，狀態轉 submitted，通知六部門；32項確認結果改由各部門在自己的簽核關卡
 * 自行填寫本部門負責的項目（TD_DEV_EVAL_TEMPLATE 第3欄與 TD_DEV_EVAL_SLOTS 標籤字串一致比對歸屬）
 * +意見(非必填)後簽核；六部門不限順序皆可平行簽，但需六部門全部簽完才能簽「生產課決行」，決行完才能
 * 簽「總經理決行」；總經理簽完自動 status=closed 並記錄 closed_at。各階段完成會通知下一階段的合格簽核池。
 * 超級管理員（isAdmin，非僅td_dev_eval_admin模組角色）另有「32項快速設定」與「全部自動簽核(指定日期)」
 * 兩個補舊資料專用動作，不受上述送出/簽核狀態限制；自動簽核時間比照 ai-rules/21：業務日期與精確時間戳
 * 分離存放、時間跟送出時刻隨機錯開5~30分鐘、不可跨天。
 */

/** 固定模板：32 項確認項目（項次=>[區分, 評估項目, 評估單位]），AS9100 表單本身格式固定，內容(答案)才是使用者填的 */
if (!defined('TD_DEV_EVAL_TEMPLATE')) define('TD_DEV_EVAL_TEMPLATE', [
    1  => ['人', '生產線人員配置是否足夠', '生產課'],
    2  => ['機', '設備產能是否足夠', '生產課'],
    3  => ['機', '設備精度是否可滿足', '生產課'],
    4  => ['機', '量測設備及檢測量具是否足夠', '品保課'],
    5  => ['料', '有無特殊或不易取得之材料、採購件', '資材課'],
    6  => ['料', '材料、採購件取得時程是否可滿足', '資材課'],
    7  => ['料', '材料、採購件是否需合格認證廠商', '資材課'],
    8  => ['料', '中間製程是否有適合的外包商承製', '資材課'],
    9  => ['料', '物料是否滿足目前政府法規(RoHS、IMDS)', '資材課'],
    10 => ['法', '是否需要特殊刀具、工具或輔助器具', '技術課'],
    11 => ['法', '是否能符合客戶要求之管制重點尺寸', '技術課'],
    12 => ['法', '所提供圖面、尺寸、工程規格、測試規範是否完整', '技術課'],
    13 => ['發展', '是否需要客戶提供樣品', '技術課'],
    14 => ['產品安全', '客戶提供產品安全相關資料的核對', '業務課'],
    15 => ['產品安全', '參照「產品開發評估表」中的產品安全項目，做為訂單及合約之產品安全審查依據', '業務課'],
    16 => ['產品安全', '需將影響安全事件之結果通知客戶', '業務課'],
    17 => ['產品安全', '產品安全的鑽模治夾具設計', '資材課'],
    18 => ['產品安全', '產品安全的的加工製程設備規劃', '技術課'],
    19 => ['產品安全', '參照「產品開發評估表」中的產品安全的項目，做為採購之產品安全審查依據', '資材課'],
    20 => ['產品安全', '人員教育訓練', '管理課'],
    21 => ['產品安全', '參照「產品開發評估表」中的仿冒零件的防制的項目，做為生產之產品安全審查依據', '生產課'],
    22 => ['仿冒零件的防制', '客戶提供工程相關資料的版別等清單核對', '業務課'],
    23 => ['仿冒零件的防制', '客戶提供材料及零組件等清單核對', '業務課'],
    24 => ['仿冒零件的防制', '將疑似或已查出之仿冒零件的檢驗及分析報告回報客戶', '業務課'],
    25 => ['仿冒零件的防制', '客戶提供工程文件的識別', '技術課'],
    26 => ['仿冒零件的防制', '製造工作程序單版別的設計', '技術課'],
    27 => ['仿冒零件的防制', '核對客戶指定之原材料證明', '資材課'],
    28 => ['仿冒零件的防制', '參照「產品開發評估表」中的仿冒零件的防制的項目，做為採購之仿冒零件的防制的審查依據', '資材課'],
    29 => ['仿冒零件的防制', '人員教育訓練', '管理課'],
    30 => ['仿冒零件的防制', '核對生管提供之原材料證明', '資材課'],
    31 => ['其他', '是否有開發來不及風險(含客戶縮短開發期風險)', '技術課'],
    32 => ['其他', '其他', '業務課'],
]);

/**
 * APQP 小組簽認 + 決行欄位：slot_key => [顯示名稱, org_role_lib 角色key(null=走top_approver特例), 是否為單一決行人(非部門池)]
 * 部門角色一律重用 org_role_setting.php 既有的全站共用部門綁定（技術/業務/管理/生產/品保部門本來就設定過對應的
 * 資料庫部門ID，不建獨立一份重複設定；2026-08-12 使用者明確要求），僅「資材課」原本沒有對應的部門綁定，
 * 新增 org_role_lib 的 material_dept 補上。
 */
if (!defined('TD_DEV_EVAL_SLOTS')) define('TD_DEV_EVAL_SLOTS', [
    'tech'          => ['技術課', 'rd_dept', false],
    'sales'         => ['業務課', 'sales_dept', false],
    'mgmt'          => ['管理課', 'hr_dept', false],
    'prod'          => ['生產課', 'prod_dept', false],
    'qa'            => ['品保課', 'qc_dept', false],
    'material'      => ['資材課', 'material_dept', false],
    'prod_decision' => ['生產課決行', 'prod_dept', false],
    'gm'            => ['總經理決行', 'top_approver', true],
]);
if (!defined('TD_DEV_EVAL_DECISIONS')) define('TD_DEV_EVAL_DECISIONS', ['make'=>'可行自製', 'outsource'=>'可行委外', 'reeval'=>'再評估', 'stop'=>'中止']);
/** 六個部門類簽認欄位（不含生產課決行/總經理決行），簽核順序不限但需六個都簽完才能進下一階段 */
if (!defined('TD_DEV_EVAL_DEPT_SLOTS')) define('TD_DEV_EVAL_DEPT_SLOTS', ['tech','sales','mgmt','prod','qa','material']);

function td_dev_eval_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS td_dev_eval (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_no VARCHAR(20) NOT NULL COMMENT '表單編號(YYYYMMDD+3位流水號)',
        customer_name VARCHAR(60) NULL COMMENT '客戶名稱',
        part_d_id INT NULL COMMENT '產品件號(料號)，對應d_setting.d_id',
        part_no_text VARCHAR(60) NULL COMMENT '料號顯示字串快照(選定當下)，未建料號時可手動輸入',
        product_name VARCHAR(100) NULL COMMENT '產品名稱',
        est_qty INT NULL COMMENT '預估需求量(PCS/月)',
        fill_date DATE NULL COMMENT '填表日期',
        sample_time VARCHAR(60) NULL COMMENT '送樣時間',
        decision VARCHAR(20) NULL COMMENT 'make/outsource/reeval/stop',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        updated_at TIMESTAMP NULL,
        updated_by INT NULL,
        updated_by_name VARCHAR(50) NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_doc_no (doc_no),
        KEY idx_part (part_d_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='產品開發評估表(2-TD-02-01)-表頭'");

    foreach ([
        "ALTER TABLE td_dev_eval ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT '狀態:draft/submitted/closed' AFTER decision",
        "ALTER TABLE td_dev_eval ADD COLUMN submit_date DATE NULL COMMENT '送出日(業務日期，僅超管可回改)' AFTER status",
        "ALTER TABLE td_dev_eval ADD COLUMN submitted_at DATETIME NULL COMMENT '送出精確時間戳' AFTER submit_date",
        "ALTER TABLE td_dev_eval ADD COLUMN submitted_by INT NULL AFTER submitted_at",
        "ALTER TABLE td_dev_eval ADD COLUMN submitted_by_name VARCHAR(50) NULL AFTER submitted_by",
        "ALTER TABLE td_dev_eval ADD COLUMN closed_at DATETIME NULL COMMENT '六部門+生產課決行+總經理決行全部簽完的時間' AFTER submitted_by_name",
    ] as $ddl) { try { $db->exec($ddl); } catch (Throwable $e) {} }

    $db->exec("CREATE TABLE IF NOT EXISTS td_dev_eval_answer (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        item_no TINYINT NOT NULL COMMENT '對應TD_DEV_EVAL_TEMPLATE項次1-32',
        result VARCHAR(4) NULL COMMENT 'yes/no/na',
        UNIQUE KEY uq_doc_item (doc_id, item_no)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='產品開發評估表-逐項確認結果'");

    $db->exec("CREATE TABLE IF NOT EXISTS td_dev_eval_signoff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        slot_key VARCHAR(20) NOT NULL COMMENT '見TD_DEV_EVAL_SLOTS',
        note VARCHAR(500) NULL COMMENT '意見',
        signed_by INT NULL,
        signed_by_name VARCHAR(50) NULL,
        signed_at DATETIME NULL,
        is_deputy TINYINT(1) NOT NULL DEFAULT 0 COMMENT '代理人代簽(僅gm欄可能)',
        is_backfill TINYINT(1) NOT NULL DEFAULT 0 COMMENT '超管操作確認密碼補登(非本人即時簽核)',
        backfill_by_name VARCHAR(50) NULL COMMENT '執行補登的管理員',
        UNIQUE KEY uq_doc_slot (doc_id, slot_key)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='產品開發評估表-APQP小組簽認+決行'");

    // 產品名稱預設值：2026-08-12版誤做成「綁定特定料號」，2026-08-13使用者更正為「全部產品通用的單一預設值」，
    // 非特定料號；舊table保留既有資料但不再寫入，改用system_parameters存一個全域值。首次切換時若舊table已有
    // 使用者設定過的值，直接帶過來當全域預設值，不必使用者重新輸入一次。
    $db->exec("CREATE TABLE IF NOT EXISTS td_dev_eval_part_name (
        part_d_id INT NOT NULL PRIMARY KEY COMMENT '對應d_setting.d_id(舊版依料號綁定,已停用僅供資料遷移用)',
        product_name VARCHAR(100) NOT NULL,
        updated_by INT NULL,
        updated_by_name VARCHAR(50) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COMMENT='(已停用，見td_dev_eval_default_product_name_get)產品開發評估表-舊版料號固定顯示名稱設定'");
    try {
        $st = $db->prepare("SELECT 1 FROM system_parameters WHERE param_group='TD_DEV_EVAL' AND param_key='default_product_name' LIMIT 1");
        $st->execute();
        if (!$st->fetchColumn()) {
            $old = $db->query("SELECT product_name FROM td_dev_eval_part_name ORDER BY updated_at DESC LIMIT 1")->fetchColumn();
            if ($old) {
                // param_value 欄位是 JSON 型別，存字串要先 json_encode，不能直接存純文字（MySQL會報3140 Invalid JSON text）
                $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description)
                              VALUES ('TD_DEV_EVAL','default_product_name',?,'產品開發評估表-產品名稱預設值(全部產品通用，非特定料號)')")
                   ->execute([json_encode($old, JSON_UNESCAPED_UNICODE)]);
            }
        }
    } catch (Throwable $e) {}

    // 32項確認結果的預設值：超級管理員「全部自動簽核」時可選是否套用，只補未填的項次，不覆蓋已有答案（2026-08-12使用者明確要求）
    $db->exec("CREATE TABLE IF NOT EXISTS td_dev_eval_answer_default (
        item_no TINYINT NOT NULL PRIMARY KEY COMMENT '對應TD_DEV_EVAL_TEMPLATE項次1-32',
        result VARCHAR(4) NOT NULL COMMENT 'yes/no/na',
        updated_by INT NULL,
        updated_by_name VARCHAR(50) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COMMENT='產品開發評估表-確認項目及結果預設值(補舊資料自動簽核用)'");

    foreach ([['td_dev_eval_view','評估表檢閱'],['td_dev_eval_edit','評估表登錄'],['td_dev_eval_admin','評估表管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='td_dev_eval' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'td_dev_eval')")
               ->execute([$r[0], $r[1]]);
        }
    }

    // 送出流程為 2026-08-12 新增，既有(此功能上線前建立)的舊資料沒有 submit/status 概念；
    // 一律回填：已有任何簽核紀錄的舊資料視同已送出（submit_date/submitted_at 用建立時間回推），
    // 六部門+決行+總經理欄全簽完的視同已結案，避免舊資料在新版介面顯示成「草稿」而無法檢視既有簽核。
    try {
        $db->exec("UPDATE td_dev_eval h SET h.status='submitted',
                        h.submit_date=COALESCE(h.fill_date, DATE(h.created_at)), h.submitted_at=h.created_at,
                        h.submitted_by=h.created_by, h.submitted_by_name=h.created_by_name
                    WHERE h.status='draft' AND h.is_deleted=0
                      AND EXISTS (SELECT 1 FROM td_dev_eval_signoff s WHERE s.doc_id=h.id AND s.signed_by IS NOT NULL)");
        $totalSlots = count(TD_DEV_EVAL_SLOTS);
        $st = $db->query("SELECT h.id, MAX(s.signed_at) AS last_signed, COUNT(*) AS c
                           FROM td_dev_eval h JOIN td_dev_eval_signoff s ON s.doc_id=h.id AND s.signed_by IS NOT NULL
                           WHERE h.status='submitted' AND h.is_deleted=0 GROUP BY h.id HAVING c>=$totalSlots");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $db->prepare("UPDATE td_dev_eval SET status='closed', closed_at=? WHERE id=?")->execute([$r['last_signed'], $r['id']]);
        }
    } catch (Throwable $e) {}
}

function td_dev_eval_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function td_dev_eval_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='td_dev_eval' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='td_dev_eval' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function td_dev_eval_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canEdit'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true) || $uid === 1;
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin || td_dev_eval_has_role($db, $uid, ['td_dev_eval_admin']);
    $canEdit  = $canAdmin || td_dev_eval_has_role($db, $uid, ['td_dev_eval_edit']);
    $canView  = $canEdit  || td_dev_eval_has_role($db, $uid, ['td_dev_eval_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canEdit'=>$canEdit,'canView'=>$canView];
}

/** 產生本表文件編號：YYYYMMDD + 3位流水號（以 DB 日期為準） */
function td_dev_eval_next_doc_no(PDO $db): string {
    $today = $db->query("SELECT DATE_FORMAT(CURDATE(),'%Y%m%d')")->fetchColumn();
    $like = $today . '%';
    $st = $db->prepare("SELECT doc_no FROM td_dev_eval WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1");
    $st->execute([$like]);
    $last = $st->fetchColumn();
    $seq = $last ? ((int)substr((string)$last, 8, 3) + 1) : 1;
    return $today . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/**
 * 預估需求量 (PCS/月) 自動試算（2026-08-13 使用者明確要求，僅供帶入預設值，欄位仍可手動改）。
 * 以填表日期為錨點，試三種一年區間（往前一年、往後一年、前後各半年置中），每個區間分別算訂單數量
 * 總和與BOM數量總和，兩者取較大者當該區間的分數；三個區間再取分數最高者，除以12取月平均，無條件進位。
 * 查無任何資料（三個區間分數都是0）回 null，讓使用者自行手動填寫。
 */
function td_dev_eval_estimate_qty(PDO $db, int $partDId, string $fillDate): ?int {
    if (!$partDId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fillDate)) return null;
    try { $anchor = new DateTime($fillDate); } catch (Throwable $e) { return null; }
    $windows = [
        [(clone $anchor)->modify('-1 year'), clone $anchor],                          // 往前一年
        [clone $anchor, (clone $anchor)->modify('+1 year')],                          // 往後一年
        [(clone $anchor)->modify('-6 months'), (clone $anchor)->modify('+6 months')], // 填表日期置中，前後各半年
    ];
    $best = 0;
    foreach ($windows as [$from, $to]) {
        $fromS = $from->format('Y-m-d'); $toS = $to->format('Y-m-d');
        $st = $db->prepare("SELECT COALESCE(SUM(Qty),0) FROM order_track WHERE d_id_ID=? AND Order_date BETWEEN ? AND ?");
        $st->execute([$partDId, $fromS, $toS]);
        $orderQty = (int)$st->fetchColumn();

        $st = $db->prepare("SELECT COALESCE(SUM(sqty),0) FROM bom WHERE d_setting_id=? AND Created_At BETWEEN ? AND ?");
        $st->execute([$partDId, $fromS.' 00:00:00', $toS.' 23:59:59']);
        $bomQty = (int)$st->fetchColumn();

        $best = max($best, $orderQty, $bomQty);
    }
    if ($best <= 0) return null;
    return (int)ceil($best / 12);
}

/**
 * 某部門類角色key的可簽核人員池：部門內任一主管(有職級者)皆可簽；找不到有職級的主管時，
 * 退回該部門(含子部門)依職稱sort_order排序後職位最高的一人（2026-08-12使用者明確拍板的規則）。
 */
function td_dev_eval_resolve_pool(PDO $db, string $roleKey): array {
    $deptIds = eg_org_dept_ids($db, $roleKey);
    if (!$deptIds) return [];
    $managers = eg_org_dept_managers($db, $deptIds);
    if ($managers) {
        return array_map(function($m){ return ['id'=>(int)$m['id'], 'user_cname'=>$m['user_cname']]; }, $managers);
    }
    $people = eg_people_list($db, ['dept_ids'=>$deptIds]);
    if ($people) return [['id'=>(int)$people[0]['id'], 'user_cname'=>$people[0]['user_cname']]];
    return [];
}

/**
 * 部門簽核人指定覆蓋（2026-08-13使用者明確要求，同日二次修正支援複選/排除主管模式）：某些部門的組織圖
 * 主管其實是總經理兼任（例如技術課沒有專職課長），但總經理實務上不會回覆這張表單、只回覆「技術課審核」
 * 那類表單——組織角色綁定(org_role_lib)的部門是全站共用、不能為了這張表單去改共用綁定（會影響其他真的
 * 要總經理簽的表單）。此處提供本模組自己的覆蓋，每個部門欄位可選三種模式：
 *   auto（預設，不存在覆蓋設定）＝沿用部門主管自動解析（org_role_lib既有邏輯，不變）
 *   people＝指定一至多位人員皆可簽（例：技術課兩位工程師都要能簽，不是只能挑一人）
 *   exclude_manager＝該部門「主管以外」的其他成員皆可簽（自動解析出目前的主管、從部門人員名單中排除掉）
 * 存 system_parameters 一個 slot_key=>{mode, user_ids} 的JSON物件，只允許六個部門類欄位。
 */
function td_dev_eval_slot_overrides_get(PDO $db): array {
    $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TD_DEV_EVAL' AND param_key='slot_signer_override' LIMIT 1");
    $st->execute();
    $v = $st->fetchColumn();
    if ($v === false) return [];
    $decoded = json_decode((string)$v, true);
    return is_array($decoded) ? $decoded : [];
}
function td_dev_eval_slot_overrides_save(PDO $db, array $map, int $uid, string $uname): void {
    $clean = [];
    foreach (TD_DEV_EVAL_DEPT_SLOTS as $slotKey) {
        $ov = $map[$slotKey] ?? null;
        if (!is_array($ov) || empty($ov['mode'])) continue;
        $mode = (string)$ov['mode'];
        if ($mode === 'people') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ov['user_ids'] ?? []))));
            if ($ids) $clean[$slotKey] = ['mode'=>'people', 'user_ids'=>$ids];
        } elseif ($mode === 'exclude_manager') {
            $clean[$slotKey] = ['mode'=>'exclude_manager'];
        }
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group='TD_DEV_EVAL' AND param_key='slot_signer_override' LIMIT 1");
    $st->execute();
    $id = $st->fetchColumn();
    if ($id) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $uid, $id]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by)
                      VALUES ('TD_DEV_EVAL','slot_signer_override',?,'產品開發評估表-部門簽核人指定覆蓋(slot_key=>{mode,user_ids}，不設=沿用部門主管自動解析)',?)")
           ->execute([$json, $uid]);
    }
}

/** 某簽核欄位目前可簽核的人員池（gm 欄回單一人：top_approver 或其代理人） */
function td_dev_eval_slot_pool(PDO $db, string $slotKey, int $docId = 0): array {
    if (!isset(TD_DEV_EVAL_SLOTS[$slotKey])) return [];
    [$label, $roleKey, $isSingle] = TD_DEV_EVAL_SLOTS[$slotKey];
    if (!$isSingle) {
        $overrides = td_dev_eval_slot_overrides_get($db);
        $ov = $overrides[$slotKey] ?? null;
        if (is_array($ov) && !empty($ov['mode'])) {
            if ($ov['mode'] === 'people' && !empty($ov['user_ids'])) {
                $ids = array_map('intval', $ov['user_ids']);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("SELECT id, user_cname FROM user WHERE id IN ($in) AND COALESCE(state,1) NOT IN (0,90)");
                $st->execute($ids);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) return array_map(function($u){ return ['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname']]; }, $rows);
                // 指定的人全部離職或查無此人，退回部門主管自動解析，避免這一欄變成永遠沒人能簽
            } elseif ($ov['mode'] === 'exclude_manager') {
                $deptIds = eg_org_dept_ids($db, $roleKey);
                if ($deptIds) {
                    $managerIds = array_map(function($m){ return (int)$m['id']; }, eg_org_dept_managers($db, $deptIds));
                    $people = eg_people_list($db, ['dept_ids'=>$deptIds]);
                    $filtered = array_values(array_filter($people, function($p) use ($managerIds){ return !in_array((int)$p['id'], $managerIds, true); }));
                    if ($filtered) return array_map(function($p){ return ['id'=>(int)$p['id'], 'user_cname'=>$p['user_cname']]; }, $filtered);
                    // 排除主管後部門內沒有其他人了，退回部門主管自動解析，避免這一欄變成永遠沒人能簽
                }
            }
        }
        return td_dev_eval_resolve_pool($db, $roleKey);
    }
    $u = eg_org_user($db, $roleKey);
    if (!$u) return [];
    $resolved = eg_resolve_signer($db, (int)$u['id'], ['flow_key'=>'td_dev_eval_'.$slotKey, 'doc_id'=>$docId, 'log'=>false]);
    $signerId = $resolved['signer_id'];
    if ($signerId === (int)$u['id']) return [['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname'], 'is_deputy'=>false]];
    $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=?");
    $st->execute([$signerId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    return $d ? [['id'=>(int)$d['id'], 'user_cname'=>$d['user_cname'], 'is_deputy'=>true]] : [['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname'], 'is_deputy'=>false]];
}

/** 某部門簽認欄位負責填寫的項次清單（比對 TEMPLATE 第3欄的評估單位字串是否等於該 slot 的顯示名稱） */
function td_dev_eval_slot_item_nos(string $slotKey): array {
    if (!isset(TD_DEV_EVAL_SLOTS[$slotKey])) return [];
    $label = TD_DEV_EVAL_SLOTS[$slotKey][0];
    $out = [];
    foreach (TD_DEV_EVAL_TEMPLATE as $no => $t) if ($t[2] === $label) $out[] = $no;
    return $out;
}

/** 通知（比照 review_form 的 rvf_notify 同一套 live_event + push 寫法） */
function td_dev_eval_notify(PDO $db, int $docId, array $toUids, string $title, string $content, int $fromUid, string $mode = 'sign'): int {
    $toUids = array_values(array_unique(array_map('intval', $toUids)));
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '產品開發評估表', 1, 'TD_DEV_EVAL', ?)")
           ->execute([$title, $content, $fromUid, $docId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, ?)");
        foreach ($toUids as $tuid) $ins->execute([$eid, $tuid, $mode]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}

/** 六個部門類簽認欄位是否全部簽完 */
function td_dev_eval_dept_slots_done(PDO $db, int $docId): bool {
    $in = implode(',', array_fill(0, count(TD_DEV_EVAL_DEPT_SLOTS), '?'));
    $st = $db->prepare("SELECT COUNT(DISTINCT slot_key) FROM td_dev_eval_signoff
                         WHERE doc_id=? AND signed_by IS NOT NULL AND slot_key IN ($in)");
    $st->execute(array_merge([$docId], TD_DEV_EVAL_DEPT_SLOTS));
    return (int)$st->fetchColumn() >= count(TD_DEV_EVAL_DEPT_SLOTS);
}

/** 某簽核欄位是否已簽 */
function td_dev_eval_slot_signed(PDO $db, int $docId, string $slotKey): bool {
    $st = $db->prepare("SELECT 1 FROM td_dev_eval_signoff WHERE doc_id=? AND slot_key=? AND signed_by IS NOT NULL");
    $st->execute([$docId, $slotKey]);
    return (bool)$st->fetchColumn();
}

/**
 * 剛簽完 $slotKey 之後：判斷是否可以/該通知下一階段，或全部簽完自動結案。
 * 六部門(任一順序)簽完才能生產課決行；生產課決行完才能總經理決行；總經理簽完自動結案。
 */
function td_dev_eval_advance_after_sign(PDO $db, int $docId, string $slotKey, int $fromUid, string $fromName): void {
    $st = $db->prepare("SELECT doc_no, product_name FROM td_dev_eval WHERE id=?");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC) ?: ['doc_no'=>'', 'product_name'=>''];
    $docLabel = '「'.$doc['doc_no'].' '.$doc['product_name'].'」';

    if (in_array($slotKey, TD_DEV_EVAL_DEPT_SLOTS, true)) {
        if (td_dev_eval_dept_slots_done($db, $docId)) {
            $pool = td_dev_eval_slot_pool($db, 'prod_decision', $docId);
            if ($pool) td_dev_eval_notify($db, $docId, array_column($pool, 'id'),
                '產品開發評估表待您決行', $docLabel.'六部門已全部簽認完成，請進行生產課決行。', $fromUid);
        }
        return;
    }
    if ($slotKey === 'prod_decision') {
        $pool = td_dev_eval_slot_pool($db, 'gm', $docId);
        if ($pool) td_dev_eval_notify($db, $docId, array_column($pool, 'id'),
            '產品開發評估表待您決行', $docLabel.'生產課已決行，請進行總經理決行。', $fromUid);
        return;
    }
    if ($slotKey === 'gm') {
        $st = $db->prepare("UPDATE td_dev_eval SET status='closed', closed_at=NOW() WHERE id=?");
        $st->execute([$docId]);
        $st = $db->prepare("SELECT submitted_by FROM td_dev_eval WHERE id=?");
        $st->execute([$docId]);
        $creator = (int)$st->fetchColumn();
        if ($creator) td_dev_eval_notify($db, $docId, [$creator],
            '產品開發評估表已結案', $docLabel.'總經理已決行完成，本表單已結案。', $fromUid, 'read');
    }
}

/** 送出：草稿→送出，鎖定表頭，通知六部門開始簽核（2026-08-12新增，比照ai-rules/21業務日期/精確時間戳分離） */
function td_dev_eval_submit(PDO $db, int $docId, int $uid, string $uname): array {
    $st = $db->prepare("SELECT * FROM td_dev_eval WHERE id=? AND is_deleted=0");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) return ['ok'=>false, 'msg'=>'找不到該筆'];
    if ($doc['status'] !== 'draft') return ['ok'=>false, 'msg'=>'此表單已送出，不可重複送出'];
    if ($doc['customer_name'] === null || $doc['customer_name'] === '') return ['ok'=>false, 'msg'=>'請先填寫客戶名稱'];
    if ($doc['product_name'] === null || $doc['product_name'] === '') return ['ok'=>false, 'msg'=>'請先填寫產品名稱'];

    $submitDate = date('Y-m-d');
    $db->prepare("UPDATE td_dev_eval SET status='submitted', submit_date=?, submitted_at=NOW(),
                  submitted_by=?, submitted_by_name=? WHERE id=?")->execute([$submitDate, $uid, $uname, $docId]);

    $docLabel = '「'.$doc['doc_no'].' '.$doc['product_name'].'」';
    foreach (TD_DEV_EVAL_DEPT_SLOTS as $slotKey) {
        $pool = td_dev_eval_slot_pool($db, $slotKey, $docId);
        if (!$pool) continue;
        $label = TD_DEV_EVAL_SLOTS[$slotKey][0];
        td_dev_eval_notify($db, $docId, array_column($pool, 'id'), '產品開發評估表待您簽核',
            $docLabel.'已送出，請填寫「'.$label.'」負責的確認項目結果與意見後簽核。', $uid);
    }
    return ['ok'=>true];
}

/**
 * 產品名稱預設值：全部產品通用的單一值（不是特定料號），新建立表單時自動帶入，仍可手動改；
 * 僅評估表管理員/系統管理員可設定（2026-08-13使用者明確要求，修正2026-08-12版誤做成綁定特定料號的設計）。
 */
function td_dev_eval_default_product_name_get(PDO $db): ?string {
    // param_value 欄位是 JSON 型別，讀出來要 json_decode（不是直接存純文字，否則MySQL存入時就會報錯）
    $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TD_DEV_EVAL' AND param_key='default_product_name' LIMIT 1");
    $st->execute();
    $v = $st->fetchColumn();
    if ($v === false) return null;
    $decoded = json_decode((string)$v, true);
    return ($decoded !== null && $decoded !== '') ? (string)$decoded : null;
}
function td_dev_eval_default_product_name_save(PDO $db, string $name, int $uid, string $uname): void {
    $json = json_encode($name, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group='TD_DEV_EVAL' AND param_key='default_product_name' LIMIT 1");
    $st->execute();
    $id = $st->fetchColumn();
    if ($id) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $uid, $id]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by)
                      VALUES ('TD_DEV_EVAL','default_product_name',?,'產品開發評估表-產品名稱預設值(全部產品通用，非特定料號)',?)")
           ->execute([$json, $uid]);
    }
}

/** 確認項目及結果的預設值（item_no=>yes/no/na），超級管理員設定，供「全部自動簽核」可選套用 */
function td_dev_eval_answer_defaults_get(PDO $db): array {
    $out = [];
    foreach ($db->query("SELECT item_no, result FROM td_dev_eval_answer_default")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $out[(int)$r['item_no']] = $r['result'];
    return $out;
}
function td_dev_eval_answer_defaults_save(PDO $db, array $map, int $uid, string $uname): void {
    $ins = $db->prepare("INSERT INTO td_dev_eval_answer_default (item_no, result, updated_by, updated_by_name)
                          VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE result=VALUES(result), updated_by=VALUES(updated_by), updated_by_name=VALUES(updated_by_name)");
    $del = $db->prepare("DELETE FROM td_dev_eval_answer_default WHERE item_no=?");
    foreach (array_keys(TD_DEV_EVAL_TEMPLATE) as $no) {
        $result = $map[$no] ?? $map[(string)$no] ?? null;
        if (in_array($result, ['yes','no','na'], true)) $ins->execute([$no, $result, $uid, $uname]);
        else $del->execute([$no]);
    }
}

/**
 * 超級管理員專用：全部欄位自動簽核（補舊資料用，指定業務日期，不受送出/簽核狀態與分階段卡關限制）。
 * 若尚未送出會一併補上送出紀錄；每欄簽核人取該欄目前解析池的第一位；時間比照 ai-rules/21——
 * 跟當天固定基準時刻隨機錯開5~30分鐘、不跨天。$applyDefaults=true 時，尚未填的項次套用管理員設定的
 * 預設值（只補未填，不覆蓋既有答案）。$decision 由快速設定面板自己的下拉選單提供（不是表單內生產課決行
 * 那組選項——那組一樣要照分階段卡關走，不因為是管理員就跳過），沒帶入時退回既有已存檔的值。
 */
function td_dev_eval_admin_auto_sign_all(PDO $db, int $docId, string $bizDate, int $adminUid, string $adminName, bool $applyDefaults = false, ?string $decision = null): array {
    $st = $db->prepare("SELECT * FROM td_dev_eval WHERE id=? AND is_deleted=0");
    $st->execute([$docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) return ['ok'=>false, 'msg'=>'找不到該筆'];
    $decision = $decision !== null && $decision !== '' ? $decision : (string)($doc['decision'] ?? '');
    if (!isset(TD_DEV_EVAL_DECISIONS[$decision])) return ['ok'=>false, 'msg'=>'請先選擇決行結果（可行自製/可行委外/再評估/中止）再自動簽核'];

    $db->beginTransaction();
    try {
        if ($doc['status'] === 'draft') {
            $db->prepare("UPDATE td_dev_eval SET status='submitted', submit_date=?, submitted_at=CONCAT(?,' 08:30:00'),
                          submitted_by=?, submitted_by_name=? WHERE id=?")
               ->execute([$bizDate, $bizDate, $adminUid, $adminName, $docId]);
        }
        $db->prepare("UPDATE td_dev_eval SET decision=? WHERE id=?")->execute([$decision, $docId]);
        if ($applyDefaults) {
            $defaults = td_dev_eval_answer_defaults_get($db);
            if ($defaults) {
                $st = $db->prepare("SELECT item_no FROM td_dev_eval_answer WHERE doc_id=? AND result IS NOT NULL");
                $st->execute([$docId]);
                $already = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $ins = $db->prepare("INSERT INTO td_dev_eval_answer (doc_id, item_no, result) VALUES (?,?,?)
                                      ON DUPLICATE KEY UPDATE result=VALUES(result)");
                foreach ($defaults as $no => $result) {
                    if (in_array($no, $already, true)) continue;
                    $ins->execute([$docId, $no, $result]);
                }
            }
        }
        $lastSignedAt = null;
        foreach (array_keys(TD_DEV_EVAL_SLOTS) as $slotKey) {
            if (td_dev_eval_slot_signed($db, $docId, $slotKey)) continue;
            $pool = td_dev_eval_slot_pool($db, $slotKey, $docId);
            $signerId = $pool ? (int)$pool[0]['id'] : null;
            $signerName = $pool ? $pool[0]['user_cname'] : null;
            if (!$signerName) { $signerId = $adminUid; $signerName = $adminName; }
            $offsetMin = random_int(5, 30);
            $st = $db->prepare("INSERT INTO td_dev_eval_signoff (doc_id, slot_key, signed_by, signed_by_name, signed_at, is_backfill, backfill_by_name)
                VALUES (?,?,?,?, LEAST(DATE_ADD(CONCAT(?,' 08:30:00'), INTERVAL ? MINUTE), CONCAT(?,' 23:59:59')), 1, ?)
                ON DUPLICATE KEY UPDATE signed_by=VALUES(signed_by), signed_by_name=VALUES(signed_by_name),
                    signed_at=VALUES(signed_at), is_backfill=1, backfill_by_name=VALUES(backfill_by_name)");
            $st->execute([$docId, $slotKey, $signerId, $signerName, $bizDate, $offsetMin, $bizDate, $adminName]);
            $lastSignedAt = $bizDate;
        }
        $db->prepare("UPDATE td_dev_eval SET status='closed', closed_at=CONCAT(?,' 23:59:00') WHERE id=?")
           ->execute([$bizDate, $docId]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); return ['ok'=>false, 'msg'=>'自動簽核失敗：'.$e->getMessage()]; }
    return ['ok'=>true];
}
