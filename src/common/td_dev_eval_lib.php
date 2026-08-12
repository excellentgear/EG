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

    foreach ([['td_dev_eval_view','評估表檢閱'],['td_dev_eval_edit','評估表登錄'],['td_dev_eval_admin','評估表管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='td_dev_eval' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'td_dev_eval')")
               ->execute([$r[0], $r[1]]);
        }
    }
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

/** 某簽核欄位目前可簽核的人員池（gm 欄回單一人：top_approver 或其代理人） */
function td_dev_eval_slot_pool(PDO $db, string $slotKey, int $docId = 0): array {
    if (!isset(TD_DEV_EVAL_SLOTS[$slotKey])) return [];
    [$label, $roleKey, $isSingle] = TD_DEV_EVAL_SLOTS[$slotKey];
    if (!$isSingle) return td_dev_eval_resolve_pool($db, $roleKey);
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
