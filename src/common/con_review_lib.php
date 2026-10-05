<?php
/**
 * 合約訂單審查表（AS 2-SM-01-06）—— 共用庫
 * 建立：2026-10-05（使用者交辦）
 *
 * ══════════════════════════════════════════════════════════════════════
 * 為什麼是獨立模組，不是審核表單（review_form.php）或產品開發評估表（td_dev_eval.php）本身
 * ══════════════════════════════════════════════════════════════════════
 * 使用者一開始問「要不要跟 review_form 做一起」，查證比較後改用這個架構——理由：
 *   ・review_form.php 是通用模板引擎，硬塞「一張訂單一份、業務日期鎖接單日」這種特殊規則，
 *     會變成只有這一張表單用得到的特例路徑，對其餘 4 張既有表單是純粹的複雜度負擔。
 *   ・這張表單的形狀（固定人機料法環項目、各課自填自己負責的項、最後由業務課決行、
 *     總經理核准）跟 td_dev_eval.php（產品開發評估表 2-TD-02-01）幾乎一致，照它的架構
 *     蓋一個獨立模組最省工。
 *   ・但兩者是 AS9100 裡不同的文件（開發評估＝新料號一次性能力確認／合約審查＝每張訂單
 *     能不能接），不合併成分頁——td_dev_eval 第15項本身就寫著要被引用：
 *     「參照「產品開發評估表」中的產品安全項目，做為訂單及合約之產品安全審查依據」，
 *     制度上兩者是互相參照的獨立文件，不是同一份。
 *
 * ══════════════════════════════════════════════════════════════════════
 * 跟 td_dev_eval.php 的關鍵差異（不是照抄，三個地方刻意不同）
 * ══════════════════════════════════════════════════════════════════════
 * 1) td_dev_eval 的 32 項是寫死的 PHP 常數（TD_DEV_EVAL_TEMPLATE），這裡的項目是
 *    **DB 存的可編輯範本**（con_review_tpl_item）——使用者明確要求「項目要先從產品開發
 *    那邊複製過來，管理員再刪減修改，兩邊的項目不可連動」：cnrv_tpl_seed_from_dev_eval()
 *    是一次性複製，複製完就與 TD_DEV_EVAL_TEMPLATE 完全脫鉤。
 * 2) td_dev_eval 的六個部門是寫死常數（TD_DEV_EVAL_DEPT_SLOTS，各自對應固定 org_role_lib
 *    角色鍵）；這裡的部門是**逐項目指定** department.id（admin 編輯範本時直接挑部門），
 *    一張單要哪些部門簽，是「這張單的項目實際用到哪些部門」動態算出來的（cnrv_doc_dept_ids()），
 *    不是固定六個——範本項目增減，牽涉的部門自然跟著變，不必同步改一份部門清單。
 * 3) td_dev_eval 的答案是固定 是/否/N-A 三選一；這裡每一項可以自己設**預設回覆選項**
 *    （使用者要求：「各項的選單要改成管理員預設的回覆可以下拉選擇或是自行填寫」），
 *    前端用 input+datalist（可選可自己打字），後端存的是文字值不是固定列舉。
 *
 * ══════════════════════════════════════════════════════════════════════
 * 一張訂單一份、業務日期鎖接單日、單頭欄位是「建立當下的快照」
 * ══════════════════════════════════════════════════════════════════════
 * 「這張訂單要不要審查」＝稽核製程標籤 kind='process'（齒研、插齒這種 AS 認證範圍內的製程，
 * 判定借 order_as_tag_lib.php 的定義，不在這裡另列一份製程清單——鐵律4）。
 * 客戶／料號／數量／交期／接單日期在**建立當下拍照存進表頭**，不是即時 JOIN order_track：
 * 訂單事後若真的要改規格/數量/交期，走的是另一份既有文件「訂單修改審查記錄表」
 * （2-SM-01-03，AS 文件已登記、程式尚未實作）——合約審查審的是「接單那一刻的樣子」，
 * 不該因為訂單後來被改了而回頭跟著變，兩種關注點本來就該分開。
 *
 * ══════════════════════════════════════════════════════════════════════
 * 簽核結構：動態內容部門（逐項自填自簽）＋固定二關（業務課決行／總經理核准）
 * ══════════════════════════════════════════════════════════════════════
 * draft（建立者可編修表頭）→ submit 鎖表頭、通知各內容部門 → 各部門各自填寫自己負責的項目
 * 並按「本課確認」簽核（不限順序，可平行）→ 全部內容部門簽完才能「業務課決行」（可接單／
 * 可接單但需客戶確認條件／不可接單，業務課是 AS9100 7.2 合約審查的責任部門，走 org_role_lib
 * 的 sales_dept，不寫死人名）→ 決行完才能「總經理核准」（top_approver + 代理解析）→ 核准後
 * status=closed。總經理核准是固定關卡、與內容部門是否剛好包含業務課無關（兩種不同性質的
 * 「業務課動作」：內容部門簽核是確認資料、決行是做決定）。
 *
 * ══════════════════════════════════════════════════════════════════════
 * 權限
 * ══════════════════════════════════════════════════════════════════════
 * module='con_review'：con_review_view（檢視）／con_review_create（建立）／
 * con_review_admin（範本維護＋補資料）。內容部門的填寫/簽核權限**不吃這三個角色**，
 * 是「人在那個部門裡」就能填自己部門的項目（cnrv_can_fill_dept()，與 td_dev_eval 同一種
 * 設計：部門成員身分本身就是權限，不必額外指派模組角色）。
 */

require_once __DIR__ . '/td_dev_eval_lib.php';   // 借 TD_DEV_EVAL_TEMPLATE 常數做一次性複製（唯讀借用，不改它）
require_once __DIR__ . '/order_as_tag_lib.php';  // 借「這張訂單要不要審查」的唯一判定
require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/delegate_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/asdoc_lib.php';

if (!defined('CNRV_AS_MODULE')) define('CNRV_AS_MODULE', 'con_review');
if (!defined('CNRV_DECISIONS')) define('CNRV_DECISIONS', [
    'accept'      => '可接單',
    'conditional' => '可接單（需客戶確認條件）',
    'reject'      => '不可接單',
]);

/* ============================================================ 資料表 ============================================================ */

function cnrv_ensure_schema(PDO $db): void {
    static $done = null;
    if ($done !== null) return;           // 同一個請求只確認一次，避免每次呼叫都重跑 CREATE/ALTER
    if ($db->inTransaction()) { $done = false; return; }   // DDL 在交易中會隱式 commit（本專案已踩兩次），交易中一律先跳過

    $db->exec("CREATE TABLE IF NOT EXISTS con_review_tpl_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sort_order INT NOT NULL DEFAULT 0,
        group_label VARCHAR(20) NOT NULL DEFAULT '' COMMENT '區分：人/機/料/法/環…，純顯示分組',
        item_text VARCHAR(300) NOT NULL,
        dept_id INT NULL COMMENT '負責部門 department.id，留空＝建立表單時才由管理員指定',
        preset_options TEXT NULL COMMENT '預設回覆選項，JSON字串陣列，填寫時可選也可自己打字',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL, created_by_name VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by INT NULL, updated_by_name VARCHAR(50) NULL, updated_at DATETIME NULL,
        KEY idx_sort (sort_order)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='合約訂單審查表-範本項目(管理員維護,admin可刪減修改,與產品開發評估表複製後脫鉤)'");

    $db->exec("CREATE TABLE IF NOT EXISTS con_review_doc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL COMMENT 'order_track.Order_id，一張訂單只能建一份(UNIQUE，排除已作廢)',
        doc_no VARCHAR(20) NOT NULL COMMENT '表單編號(業務日期YYYYMMDD+3位流水號)',
        business_date DATE NOT NULL COMMENT '業務日期＝建立當下的訂單接單日期(快照，不隨訂單異動)',
        order_oo VARCHAR(30) NULL COMMENT '訂單編號快照',
        client_name VARCHAR(100) NULL COMMENT '客戶名稱快照',
        part_no_text VARCHAR(60) NULL COMMENT '料號快照',
        qty INT NULL COMMENT '數量快照',
        delivery_date DATE NULL COMMENT '交期快照',
        tag_label VARCHAR(60) NULL COMMENT 'AS認定(稽核製程標籤)快照',
        status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/closed/void',
        submit_date DATE NULL, submitted_at DATETIME NULL, submitted_by INT NULL, submitted_by_name VARCHAR(50) NULL,
        decision VARCHAR(20) NULL COMMENT 'accept/conditional/reject',
        decision_note VARCHAR(500) NULL,
        sales_decided_by INT NULL, sales_decided_by_name VARCHAR(50) NULL, sales_decided_at DATETIME NULL,
        sales_is_deputy TINYINT(1) NOT NULL DEFAULT 0,
        gm_approved_by INT NULL, gm_approved_by_name VARCHAR(50) NULL, gm_approved_at DATETIME NULL,
        gm_is_deputy TINYINT(1) NOT NULL DEFAULT 0,
        closed_at DATETIME NULL,
        created_by INT NULL, created_by_name VARCHAR(50) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_doc_no (doc_no),
        KEY idx_order (order_id),
        KEY idx_status (status)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='合約訂單審查表(2-SM-01-06)-表頭'");

    $db->exec("CREATE TABLE IF NOT EXISTS con_review_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        tpl_item_id INT NULL COMMENT '來源範本項目id(僅供追溯，範本之後異動不影響此列)',
        sort_order INT NOT NULL DEFAULT 0,
        group_label VARCHAR(20) NOT NULL DEFAULT '',
        item_text VARCHAR(300) NOT NULL,
        dept_id INT NULL,
        preset_options TEXT NULL COMMENT '建立當下的範本選項快照',
        answer_value VARCHAR(200) NULL COMMENT '填寫結果(可為預設選項之一，亦可自行輸入文字)',
        note VARCHAR(300) NULL,
        filled_by INT NULL, filled_by_name VARCHAR(50) NULL, filled_at DATETIME NULL,
        KEY idx_doc (doc_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='合約訂單審查表-逐項內容(建立當下由範本快照而來)'");

    $db->exec("CREATE TABLE IF NOT EXISTS con_review_dept_sign (
        id INT AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        dept_id INT NOT NULL,
        note VARCHAR(300) NULL COMMENT '本課意見',
        signed_by INT NULL, signed_by_name VARCHAR(50) NULL, signed_at DATETIME NULL,
        is_backfill TINYINT(1) NOT NULL DEFAULT 0 COMMENT '超管操作確認密碼補登(非本人即時簽核)',
        backfill_by_name VARCHAR(50) NULL,
        UNIQUE KEY uq_doc_dept (doc_id, dept_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='合約訂單審查表-內容部門簽核'");

    // 角色自動建立（module='con_review'，比照 equip_list_lib.php 同一套寫法；加好之後會自動出現在
    // user_permissions.php 的動態角色區塊，不必改設定頁程式）。canView 是進本模組 API 的最低門檻
    // （比照 td_dev_eval 的既有慣例），內容部門的填寫/簽核權限在這之上另有「人在那個部門」的
    // 細粒度檢查（cnrv_can_fill_dept），**兩層都要過**——所以要讓某部門的人能參與審查，
    // 管理員要先把 con_review_view 指派給他們（或指派給全公司共用的一個角色），
    // 細粒度檢查才有機會生效，兩者缺一都用不了。
    foreach ([['con_review_view','合約訂單審查-檢視/填寫'],['con_review_create','合約訂單審查-建立表單'],['con_review_admin','合約訂單審查-範本維護與管理']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='" . CNRV_AS_MODULE . "' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, '" . CNRV_AS_MODULE . "')")
               ->execute([$r[0], $r[1]]);
        }
    }

    $done = true;
}

/* ============================================================ CSRF（module-local token，與 review_form 同一套做法，
   已修正「session 沒 token 時空字串會通過」的坑，見 review_form_lib.php 2026-10-02 修正記錄） ============================================================ */

function cnrv_csrf_token(): string {
    if (empty($_SESSION['cnrv_csrf'])) $_SESSION['cnrv_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['cnrv_csrf'];
}
function cnrv_csrf_ok(?string $t): bool {
    $sess = (string)($_SESSION['cnrv_csrf'] ?? '');
    if ($sess === '' || $t === null || $t === '') return false;
    return hash_equals($sess, (string)$t);
}
function cnrv_need_csrf(): void {
    if (!cnrv_csrf_ok($_POST['csrf'] ?? null)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'error'=>'CSRF token 驗證失敗，請重新整理頁面']);
        exit;
    }
}

/* ============================================================ 使用者 / 權限 ============================================================ */

function cnrv_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cnrv_has_role(PDO $db, int $uid, array $codes): bool {
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='" . CNRV_AS_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function cnrv_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canCreate'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true) || $uid === 1;
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin  = $isAdmin || cnrv_has_role($db, $uid, ['con_review_admin']);
    $canCreate = $canAdmin || cnrv_has_role($db, $uid, ['con_review_create']);
    $canView   = $canCreate || cnrv_has_role($db, $uid, ['con_review_view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canCreate'=>$canCreate,'canView'=>$canView];
}

/* ============================================================ 範本項目（管理員維護） ============================================================ */

/** @return array 每項 ['id','sort_order','group_label','item_text','dept_id','dept_name','options'=>array,'is_active'] */
function cnrv_tpl_items_get(PDO $db, bool $activeOnly = false): array {
    cnrv_ensure_schema($db);
    $sql = "SELECT t.*, d.name AS dept_name FROM con_review_tpl_item t
            LEFT JOIN department d ON d.id=t.dept_id" . ($activeOnly ? " WHERE t.is_active=1" : "") . "
            ORDER BY t.sort_order, t.id";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['options'] = json_decode((string)$r['preset_options'], true) ?: [];
        $r['dept_id'] = $r['dept_id'] !== null ? (int)$r['dept_id'] : null;
    }
    return $rows;
}

/** $id=0 新增。$data: sort_order,group_label,item_text,dept_id,options(array),is_active */
function cnrv_tpl_item_save(PDO $db, int $id, array $data, int $uid, string $uname): int {
    cnrv_ensure_schema($db);
    $text = trim((string)($data['item_text'] ?? ''));
    if ($text === '') throw new Exception('項目內容不可空白');
    $group = trim((string)($data['group_label'] ?? ''));
    $deptId = (int)($data['dept_id'] ?? 0) ?: null;
    $opts = array_values(array_filter(array_map('trim', (array)($data['options'] ?? [])), fn($s) => $s !== ''));
    $optsJson = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
    $isActive = !empty($data['is_active']) ? 1 : 0;
    if ($id) {
        $db->prepare("UPDATE con_review_tpl_item SET sort_order=?,group_label=?,item_text=?,dept_id=?,preset_options=?,is_active=?,
                      updated_by=?,updated_by_name=?,updated_at=NOW() WHERE id=?")
           ->execute([(int)($data['sort_order'] ?? 0), $group, $text, $deptId, $optsJson, $isActive, $uid, $uname, $id]);
        return $id;
    }
    $db->prepare("INSERT INTO con_review_tpl_item (sort_order,group_label,item_text,dept_id,preset_options,is_active,created_by,created_by_name)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([(int)($data['sort_order'] ?? 0), $group, $text, $deptId, $optsJson, $isActive, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function cnrv_tpl_item_delete(PDO $db, int $id): void {
    cnrv_ensure_schema($db);
    $db->prepare("DELETE FROM con_review_tpl_item WHERE id=?")->execute([$id]);
}

/**
 * 從產品開發評估表（2-TD-02-01）的固定 32 項**複製一份**成本模組的範本項目。
 * 刻意是「複製」不是「連動」（2026-10-05 使用者明確要求「兩邊的項目不可連動」）：
 * 寫進 con_review_tpl_item 之後就與 TD_DEV_EVAL_TEMPLATE 完全脫鉤，日後任一邊改題目
 * 都不會動到另一邊。$append=false 時只在範本目前是空的才整批覆蓋寫入；已經有項目時
 * 一律只能 append（接在最後面），不會覆蓋管理員已經調整過的內容。
 * 「品保課」要對照成部門主檔現名「品管課」（供應商稽核 2026-08-03 踩過同一個坑）。
 * @return int 本次新增的項目數
 */
function cnrv_tpl_seed_from_dev_eval(PDO $db, int $uid, string $uname): int {
    cnrv_ensure_schema($db);
    $deptIds = [];
    foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $deptIds[trim((string)$d['name'])] = (int)$d['id'];
    }
    foreach (['品保課' => '品管課', '品保部' => '品管課'] as $old => $now) {
        if (!isset($deptIds[$old]) && isset($deptIds[$now])) $deptIds[$old] = $deptIds[$now];
    }
    $maxSort = (int)($db->query("SELECT COALESCE(MAX(sort_order),0) FROM con_review_tpl_item")->fetchColumn());
    $ins = $db->prepare("INSERT INTO con_review_tpl_item (sort_order,group_label,item_text,dept_id,created_by,created_by_name)
                          VALUES (?,?,?,?,?,?)");
    $n = 0;
    foreach (TD_DEV_EVAL_TEMPLATE as $t) {
        [$group, $text, $deptName] = [$t[0], $t[1], $t[2] ?? ''];
        $maxSort += 10;
        $ins->execute([$maxSort, $group, $text, isset($deptIds[$deptName]) ? $deptIds[$deptName] : null, $uid, $uname]);
        $n++;
    }
    return $n;
}

/* ============================================================ 訂單 / 建立審查 ============================================================ */

/** 取訂單詳情（含客戶、稽核製程標籤）。查不到或已取消一律回 null。 */
function cnrv_order_get(PDO $db, int $orderId): ?array {
    if ($orderId <= 0) return null;
    $st = $db->prepare("SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Qty, ot.Order_date, ot.Delivery_date,
                               ot.Client_name, ot.Order_status, ot.as_tag_id, ot.as_tag_scope,
                               cl.customer AS client_name_txt, t.kind AS tag_kind, t.proc_name AS tag_proc_name
                        FROM order_track ot
                        LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
                        LEFT JOIN ot_as_proc_tag t ON t.tag_id = ot.as_tag_id
                        WHERE ot.Order_id=? AND (ot.Order_status IS NULL OR ot.Order_status<>6)");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    $o['tag_label'] = $o['tag_proc_name'] ? (($o['as_tag_scope']==='full' ? '全製含' : '單製') . $o['tag_proc_name']) : '';
    return $o;
}

/** 這張訂單在 AS 認定上需不需要做合約訂單審查＝標籤是「稽核製程」(kind=process)。 */
function cnrv_need_review(?array $order): bool {
    return $order && (string)($order['tag_kind'] ?? '') === 'process';
}

/** 這張訂單已經建過的審查表單 id（0＝還沒建）。作廢(void)的不算，可以重新建一張。 */
function cnrv_doc_by_order(PDO $db, int $orderId): int {
    cnrv_ensure_schema($db);
    $st = $db->prepare("SELECT id FROM con_review_doc WHERE order_id=? AND status<>'void' ORDER BY id DESC LIMIT 1");
    $st->execute([$orderId]);
    return (int)($st->fetchColumn() ?: 0);
}

/** 批次版：給訂單追蹤清單一次撈一頁訂單的審查狀態用（避免逐列各查一次＝N+1）。
 *  @return array [Order_id => ['id'=>表單id,'status'=>狀態]] */
function cnrv_doc_status_map(PDO $db, array $orderIds): array {
    cnrv_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($n) => $n > 0)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT order_id, id, status FROM con_review_doc WHERE status<>'void' AND order_id IN ($in) ORDER BY id");
    $st->execute($ids);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['order_id']] = ['id'=>(int)$r['id'], 'status'=>(string)$r['status']];
    return $map;
}

function cnrv_next_doc_no(PDO $db, string $bizDate): string {
    $ymd = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $bizDate, $m) ? ($m[1].$m[2].$m[3]) : (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y%m%d')")->fetchColumn();
    $st = $db->prepare("SELECT doc_no FROM con_review_doc WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1");
    $st->execute([$ymd . '%']);
    $last = $st->fetchColumn();
    $seq = $last ? ((int)substr((string)$last, 8, 3) + 1) : 1;
    return $ymd . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/** 建立審查表單：訂單必須存在、需要審查、尚未建過；表頭欄位全部拍照快照，項目由目前的啟用範本複製。 */
function cnrv_create(PDO $db, int $orderId, int $uid, string $uname): int {
    cnrv_ensure_schema($db);
    $order = cnrv_order_get($db, $orderId);
    if (!$order) throw new Exception('找不到此訂單或該訂單已取消');
    if (!cnrv_need_review($order)) throw new Exception('這張訂單的 AS 認定不需要做合約訂單審查');
    if (cnrv_doc_by_order($db, $orderId)) throw new Exception('這張訂單已經建立過審查表單，請直接開啟原本那一張');
    $bizDate = (string)$order['Order_date'];
    if ($bizDate === '' || $bizDate === '0000-00-00') throw new Exception('這張訂單沒有接單日期，請先到訂單追蹤補上');

    $db->beginTransaction();
    try {
        $docNo = cnrv_next_doc_no($db, $bizDate);
        $db->prepare("INSERT INTO con_review_doc
                      (order_id,doc_no,business_date,order_oo,client_name,part_no_text,qty,delivery_date,tag_label,status,created_by,created_by_name)
                      VALUES (?,?,?,?,?,?,?,?,?,'draft',?,?)")
           ->execute([$orderId, $docNo, $bizDate, $order['Order_oo'], ($order['client_name_txt'] ?: $order['Client_name']),
                      $order['d_id'], (int)$order['Qty'], ($order['Delivery_date'] ?: null), $order['tag_label'], $uid, $uname]);
        $docId = (int)$db->lastInsertId();

        $ins = $db->prepare("INSERT INTO con_review_item (doc_id,tpl_item_id,sort_order,group_label,item_text,dept_id,preset_options)
                              VALUES (?,?,?,?,?,?,?)");
        foreach (cnrv_tpl_items_get($db, true) as $t) {
            $ins->execute([$docId, $t['id'], $t['sort_order'], $t['group_label'], $t['item_text'], $t['dept_id'],
                            $t['options'] ? json_encode($t['options'], JSON_UNESCAPED_UNICODE) : null]);
        }
        $db->commit();
        return $docId;
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

/* ============================================================ 讀取 ============================================================ */

function cnrv_get(PDO $db, int $id): ?array {
    cnrv_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM con_review_doc WHERE id=? AND is_deleted=0");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return array 每項 ['id','sort_order','group_label','item_text','dept_id','dept_name','options','answer_value','note','filled_by_name','filled_at'] */
function cnrv_items_get(PDO $db, int $docId): array {
    $st = $db->prepare("SELECT i.*, d.name AS dept_name FROM con_review_item i
                        LEFT JOIN department d ON d.id=i.dept_id
                        WHERE i.doc_id=? ORDER BY i.sort_order, i.id");
    $st->execute([$docId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['options'] = json_decode((string)$r['preset_options'], true) ?: [];
    return $rows;
}

/** 這張單的「內容部門」清單＝項目實際用到的 DISTINCT 部門（動態，不是固定清單）。 */
function cnrv_doc_dept_ids(PDO $db, int $docId): array {
    $st = $db->prepare("SELECT DISTINCT dept_id FROM con_review_item WHERE doc_id=? AND dept_id IS NOT NULL ORDER BY dept_id");
    $st->execute([$docId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** 內容部門的簽核狀態 [dept_id => signoff列或null]（dept_id 也含「有項目但尚未簽」） */
function cnrv_dept_sign_map(PDO $db, int $docId): array {
    $deptIds = cnrv_doc_dept_ids($db, $docId);
    $map = [];
    foreach ($deptIds as $d) $map[$d] = null;
    if ($deptIds) {
        $st = $db->prepare("SELECT * FROM con_review_dept_sign WHERE doc_id=?");
        $st->execute([$docId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['dept_id']] = $r;
    }
    return $map;
}

function cnrv_dept_signed(PDO $db, int $docId, int $deptId): bool {
    $st = $db->prepare("SELECT 1 FROM con_review_dept_sign WHERE doc_id=? AND dept_id=? AND signed_by IS NOT NULL");
    $st->execute([$docId, $deptId]);
    return (bool)$st->fetchColumn();
}

function cnrv_all_depts_signed(PDO $db, int $docId): bool {
    $deptIds = cnrv_doc_dept_ids($db, $docId);
    if (!$deptIds) return true;    // 這張單的項目全部沒指定部門（範本沒設負責課），不卡簽核
    foreach ($deptIds as $d) if (!cnrv_dept_signed($db, $docId, $d)) return false;
    return true;
}

/* ============================================================ 部門人員池（直接用 department.id，不走 org_role_lib 的語意角色鍵） ============================================================ */

/** 某部門目前能簽核的人員池：優先該部門主管，沒有主管才退回部門內任一人（與 td_dev_eval_resolve_pool 同一套後備規則）。 */
function cnrv_dept_pool(PDO $db, int $deptId): array {
    if ($deptId <= 0) return [];
    $managers = eg_org_dept_managers($db, [$deptId]);
    if ($managers) return array_map(fn($m) => ['id'=>(int)$m['id'], 'user_cname'=>$m['user_cname']], $managers);
    $people = eg_people_list($db, ['dept_ids'=>[$deptId]]);
    return $people ? [['id'=>(int)$people[0]['id'], 'user_cname'=>$people[0]['user_cname']]] : [];
}

/** 這個人可不可以填寫/簽核某部門的項目：人在池子裡，或是系統管理員（補資料）。 */
function cnrv_can_fill_dept(PDO $db, int $uid, int $deptId, bool $isAdmin): bool {
    if ($isAdmin) return true;
    foreach (cnrv_dept_pool($db, $deptId) as $p) if ((int)$p['id'] === $uid) return true;
    return false;
}

/** 業務課決行人員池（固定走 org_role_lib 的 sales_dept，禁寫死人名）。 */
function cnrv_sales_pool(PDO $db): array {
    $deptIds = eg_org_dept_ids($db, 'sales_dept');
    if (!$deptIds) return [];
    $managers = eg_org_dept_managers($db, $deptIds);
    if ($managers) return array_map(fn($m) => ['id'=>(int)$m['id'], 'user_cname'=>$m['user_cname']], $managers);
    $people = eg_people_list($db, ['dept_ids'=>$deptIds]);
    return $people ? [['id'=>(int)$people[0]['id'], 'user_cname'=>$people[0]['user_cname']]] : [];
}

function cnrv_can_decide(PDO $db, int $uid, bool $isAdmin): bool {
    if ($isAdmin) return true;
    foreach (cnrv_sales_pool($db) as $p) if ((int)$p['id'] === $uid) return true;
    return false;
}

/** 總經理核准：單一人（含代理解析），比照 td_dev_eval 的 'gm' 欄同一套做法。 */
function cnrv_gm_signer(PDO $db, int $docId): ?array {
    $u = eg_org_user($db, 'top_approver');
    if (!$u) return null;
    $resolved = eg_resolve_signer($db, (int)$u['id'], ['flow_key'=>'con_review_gm', 'doc_id'=>$docId, 'log'=>false]);
    $signerId = (int)$resolved['signer_id'];
    if ($signerId === (int)$u['id']) return ['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname'], 'is_deputy'=>false];
    $st = $db->prepare("SELECT id, user_cname FROM user WHERE id=?");
    $st->execute([$signerId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    return $d ? ['id'=>(int)$d['id'], 'user_cname'=>$d['user_cname'], 'is_deputy'=>true] : ['id'=>(int)$u['id'], 'user_cname'=>$u['user_cname'], 'is_deputy'=>false];
}

/* ============================================================ 寫入 ============================================================ */

/**
 * 單筆項目內容存檔（鐵律8：後端再驗一次誰能動這一列）。
 * 狀態不是 submitted 一律擋（草稿/已結案都不給改內容）；該部門已簽核完一律擋（簽完才算確認過）；
 * 不在該部門人員池內且非管理員一律擋。
 */
function cnrv_item_save(PDO $db, int $docId, int $itemId, int $uid, bool $isAdmin, ?string $value, ?string $note, string $uname): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if (!$isAdmin && $doc['status'] !== 'submitted') throw new Exception('表單尚未送出或已結案，不可編輯內容');
    $st = $db->prepare("SELECT * FROM con_review_item WHERE id=? AND doc_id=?");
    $st->execute([$itemId, $docId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new Exception('找不到此項目');
    $deptId = (int)($item['dept_id'] ?? 0);
    if (!$isAdmin) {
        if ($deptId <= 0) throw new Exception('此項目尚未指定負責部門，請聯絡管理員');
        if (cnrv_dept_signed($db, $docId, $deptId)) throw new Exception('此項目所屬部門已簽核，不可再修改');
        if (!cnrv_can_fill_dept($db, $uid, $deptId, false)) throw new Exception('您不在此項目的負責部門範圍內');
    }
    $db->prepare("UPDATE con_review_item SET answer_value=?,note=?,filled_by=?,filled_by_name=?,filled_at=NOW() WHERE id=?")
       ->execute([$value, $note, $uid, $uname, $itemId]);
}

/** 本課確認（部門簽核）：該部門的項目一律要先填完才能簽（不留白給之後回頭補——使用者沒有明確要求，
 *  但「簽了卻還有空白項目」在稽核上說不過去，故在此把關；補資料模式〈isAdmin〉不受限）。 */
function cnrv_dept_sign(PDO $db, int $docId, int $deptId, int $uid, string $uname, ?string $note, bool $isAdmin, bool $isBackfill = false, ?string $backfillByName = null): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if (!$isAdmin && $doc['status'] !== 'submitted') throw new Exception('表單尚未送出或已結案');
    if (!$isAdmin && !cnrv_can_fill_dept($db, $uid, $deptId, false)) throw new Exception('您不在此部門的簽核範圍內');
    if (cnrv_dept_signed($db, $docId, $deptId)) throw new Exception('此部門已簽核過，不可重複簽核');
    if (!$isAdmin) {
        $st = $db->prepare("SELECT COUNT(*) c, SUM(answer_value IS NOT NULL AND answer_value<>'') filled
                            FROM con_review_item WHERE doc_id=? AND dept_id=?");
        $st->execute([$docId, $deptId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$r['c'] > (int)$r['filled']) throw new Exception('本部門負責的項目尚未全部填寫完成');
    }
    $db->prepare("INSERT INTO con_review_dept_sign (doc_id,dept_id,note,signed_by,signed_by_name,signed_at,is_backfill,backfill_by_name)
                  VALUES (?,?,?,?,?,NOW(),?,?)
                  ON DUPLICATE KEY UPDATE note=VALUES(note),signed_by=VALUES(signed_by),signed_by_name=VALUES(signed_by_name),
                      signed_at=VALUES(signed_at),is_backfill=VALUES(is_backfill),backfill_by_name=VALUES(backfill_by_name)")
       ->execute([$docId, $deptId, $note, $uid, $uname, $isBackfill?1:0, $backfillByName]);

    $toUids = array_map(fn($p) => (int)$p['id'], cnrv_sales_pool($db));
    if ($toUids) cnrv_notify($db, $docId, $toUids, '合約訂單審查待決行',
        "訂單 {$doc['order_oo']}（{$doc['client_name']}）的合約訂單審查，內容部門已全部簽核，請前往決行。", $uid);
}

/** draft → submitted：鎖表頭（本模組表頭本來就是建立當下拍照、create 之後不可編輯，這裡主要是狀態轉換與通知）。 */
function cnrv_submit(PDO $db, int $docId, int $uid, string $uname): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if ($doc['status'] !== 'draft') throw new Exception('此表單已送出，不可重複送出');
    $db->prepare("UPDATE con_review_doc SET status='submitted',submit_date=CURDATE(),submitted_at=NOW(),submitted_by=?,submitted_by_name=? WHERE id=?")
       ->execute([$uid, $uname, $docId]);
    $toUids = [];
    foreach (cnrv_doc_dept_ids($db, $docId) as $d) foreach (cnrv_dept_pool($db, $d) as $p) $toUids[(int)$p['id']] = true;
    $toUids = array_keys($toUids);
    if ($toUids) cnrv_notify($db, $docId, $toUids, '合約訂單審查待填寫',
        "訂單 {$doc['order_oo']}（{$doc['client_name']}）的合約訂單審查已送出，請填寫並簽核您負責的項目。", $uid);
}

/** 業務課決行：全部內容部門簽完才能決行。 */
function cnrv_decide(PDO $db, int $docId, int $uid, string $uname, string $decision, ?string $note, bool $isAdmin): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if ($doc['status'] !== 'submitted') throw new Exception('表單狀態不正確，不可決行');
    if (!array_key_exists($decision, CNRV_DECISIONS)) throw new Exception('決行結果不合法');
    if (!$isAdmin && !cnrv_can_decide($db, $uid, false)) throw new Exception('您沒有業務課決行的權限');
    if (!cnrv_all_depts_signed($db, $docId)) throw new Exception('尚有內容部門未完成簽核，不可決行');
    $db->prepare("UPDATE con_review_doc SET decision=?,decision_note=?,sales_decided_by=?,sales_decided_by_name=?,sales_decided_at=NOW() WHERE id=?")
       ->execute([$decision, $note, $uid, $uname, $docId]);
    $gm = cnrv_gm_signer($db, $docId);
    if ($gm) cnrv_notify($db, $docId, [(int)$gm['id']], '合約訂單審查待核准',
        "訂單 {$doc['order_oo']}（{$doc['client_name']}）的合約訂單審查已決行為「" . CNRV_DECISIONS[$decision] . "」，請核准。", $uid);
}

/** 總經理核准：核准後 status=closed。 */
function cnrv_approve(PDO $db, int $docId, int $uid, string $uname, bool $isAdmin, bool $isDeputy = false): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if ($doc['status'] !== 'submitted' || $doc['decision'] === null) throw new Exception('尚未完成業務課決行，不可核准');
    if (!$isAdmin) {
        $gm = cnrv_gm_signer($db, $docId);
        if (!$gm || (int)$gm['id'] !== $uid) throw new Exception('您沒有核准此表單的權限');
        $isDeputy = !empty($gm['is_deputy']);
    }
    $db->prepare("UPDATE con_review_doc SET status='closed',gm_approved_by=?,gm_approved_by_name=?,gm_approved_at=NOW(),gm_is_deputy=?,closed_at=NOW() WHERE id=?")
       ->execute([$uid, $uname, $isDeputy?1:0, $docId]);
}

/** 作廢（僅管理員；作廢後該訂單可以重新建立一張）。 */
function cnrv_void(PDO $db, int $docId): void {
    $db->prepare("UPDATE con_review_doc SET status='void' WHERE id=?")->execute([$docId]);
}

/* ============================================================ 清單 ============================================================ */

/** @param array $f 篩選：status,keyword,limit,offset */
function cnrv_list(PDO $db, array $f = []): array {
    cnrv_ensure_schema($db);
    $where = ['is_deleted=0']; $params = [];
    if (!empty($f['status'])) { $where[] = 'status=?'; $params[] = $f['status']; }
    if (!empty($f['keyword'])) {
        $where[] = '(order_oo LIKE ? OR client_name LIKE ? OR part_no_text LIKE ? OR doc_no LIKE ?)';
        $kw = '%' . $f['keyword'] . '%'; array_push($params, $kw, $kw, $kw, $kw);
    }
    $sql = "SELECT * FROM con_review_doc WHERE " . implode(' AND ', $where) . " ORDER BY id DESC";
    $limit = max(1, min(200, (int)($f['limit'] ?? 50)));
    $offset = max(0, (int)($f['offset'] ?? 0));
    $sql .= " LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $cnt = $db->prepare("SELECT COUNT(*) FROM con_review_doc WHERE " . implode(' AND ', $where));
    $cnt->execute($params);
    return ['rows'=>$rows, 'total'=>(int)$cnt->fetchColumn()];
}

/* ============================================================ 通知 ============================================================ */

function cnrv_notify(PDO $db, int $docId, array $toUids, string $title, string $content, int $fromUid): int {
    $toUids = array_values(array_unique(array_map('intval', $toUids)));
    if (!$toUids) return 0;
    try {
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '合約訂單審查', 1, 'CON_REVIEW', ?)")
           ->execute([$title, $content, $fromUid, $docId]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'sign')");
        foreach ($toUids as $tuid) $ins->execute([$eid, $tuid]);
        try {
            require_once __DIR__ . '/../push/push_send.php';
            eg_push_send_to_users($db, eg_push_event_recipients($db, $eid), ['title'=>$title, 'body'=>mb_substr($content, 0, 480)]);
        } catch (Throwable $e) {}
        return $eid;
    } catch (Throwable $e) { return 0; }
}
