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
        order_id INT NOT NULL COMMENT 'order_track.Order_id，一張訂單只能建一份(排除已刪除的)',
        doc_no VARCHAR(20) NOT NULL COMMENT '表單編號(業務日期YYYYMMDD+3位流水號)',
        business_date DATE NOT NULL COMMENT '業務日期＝建立當下的訂單接單日期(快照，不隨訂單異動)',
        order_oo VARCHAR(30) NULL COMMENT '訂單編號快照',
        client_name VARCHAR(100) NULL COMMENT '客戶名稱快照',
        part_no_text VARCHAR(60) NULL COMMENT '料號快照',
        qty INT NULL COMMENT '數量快照',
        delivery_date DATE NULL COMMENT '交期快照',
        tag_label VARCHAR(60) NULL COMMENT 'AS認定(稽核製程標籤)快照',
        status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/submitted/closed',
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
        deleted_by INT NULL, deleted_by_name VARCHAR(50) NULL, deleted_at DATETIME NULL,
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

    // 2026-10-05：管理員「自動填寫並簽核」要用的設定與標記。
    // 實測這個 MySQL（9.4.0）的 `ADD COLUMN IF NOT EXISTS` 語法會直接 1064 語法錯誤
    // （不是本專案少數別處記過「MySQL 的 ADD COLUMN 支援 IF NOT EXISTS」那種情況——
    // 兩者矛盾，以這次實測為準，別再假設這語法能用），一律改用「先查
    // information_schema.COLUMNS 再決定要不要 ALTER」這個最保守、全版本都通用的寫法。
    $hasCol = function (string $tbl, string $col) use ($db): bool {
        $st = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$tbl, $col]);
        return (bool)$st->fetchColumn();
    };
    if (!$hasCol('con_review_tpl_item', 'default_value')) {
        try { $db->exec("ALTER TABLE con_review_tpl_item ADD COLUMN default_value VARCHAR(200) NULL
                         COMMENT '預設「是/否/N-A」，供管理員「自動填寫並簽核」使用' AFTER preset_options"); } catch (Throwable $e) {}
    }
    // 2026-10-05（使用者更正）：預設值要拆成兩個「各自獨立」的設定，不是合併成一個三選一／N選一——
    // 一個是固定三選項「是/否/N-A」的預設(default_value)，一個是額外選項的預設(default_extra)，
    // 兩者可以同時設、也可以只設其中一個；自動填寫時把兩個有值的部分合併成最終答案文字
    // （見 cnrv_admin_auto_fill_sign() 的組字邏輯）。
    if (!$hasCol('con_review_tpl_item', 'default_extra')) {
        try { $db->exec("ALTER TABLE con_review_tpl_item ADD COLUMN default_extra VARCHAR(200) NULL
                         COMMENT '預設的額外選項(與default_value各自獨立，皆可為空)，供管理員「自動填寫並簽核」使用' AFTER default_value"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_item', 'default_value')) {
        try { $db->exec("ALTER TABLE con_review_item ADD COLUMN default_value VARCHAR(200) NULL
                         COMMENT '建立當下的範本預設值快照(是/否/N-A部分)' AFTER preset_options"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_item', 'default_extra')) {
        try { $db->exec("ALTER TABLE con_review_item ADD COLUMN default_extra VARCHAR(200) NULL
                         COMMENT '建立當下的範本預設值快照(額外選項部分)' AFTER default_value"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_dept_sign', 'is_auto_sign')) {
        try { $db->exec("ALTER TABLE con_review_dept_sign ADD COLUMN is_auto_sign TINYINT(1) NOT NULL DEFAULT 0
                         COMMENT '由管理員「自動填寫並簽核」寫入(與is_backfill不同：那是逐格指定原簽核人補登，這是整批自動帶入)' AFTER backfill_by_name"); } catch (Throwable $e) {}
    }
    // 2026-10-05：清單「管理員代簽」欄要顯示「是哪位管理員、什麼時候」做的自動帶入——is_auto_sign
    // 本身只是個旗標，沒有留住「誰按的」，補兩欄記錄（is_backfill 已經有 backfill_by_name，這裡
    // 補的是 is_auto_sign 那條路徑一直缺的對應欄位）。
    if (!$hasCol('con_review_dept_sign', 'auto_sign_by')) {
        try { $db->exec("ALTER TABLE con_review_dept_sign ADD COLUMN auto_sign_by INT NULL
                         COMMENT '執行「自動填寫並簽核」的管理員帳號' AFTER is_auto_sign"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_dept_sign', 'auto_sign_by_name')) {
        try { $db->exec("ALTER TABLE con_review_dept_sign ADD COLUMN auto_sign_by_name VARCHAR(50) NULL AFTER auto_sign_by"); } catch (Throwable $e) {}
    }
    // 2026-10-05：管理員刪除功能（使用者要求「可刪除未審核的」），走既有的 is_deleted 軟刪除
    // （cnrv_get()／cnrv_list() 本來就已經過濾 is_deleted=0，只是一直沒有寫入端——補上）。
    if (!$hasCol('con_review_doc', 'deleted_by')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN deleted_by INT NULL AFTER is_deleted"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_doc', 'deleted_by_name')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN deleted_by_name VARCHAR(50) NULL AFTER deleted_by"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_doc', 'deleted_at')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN deleted_at DATETIME NULL AFTER deleted_by_name"); } catch (Throwable $e) {}
    }
    // 2026-10-05（使用者交辦）：決行／核准若實際是管理員代為操作（本人不是業務課決行池或總經理核准人選），
    // 一律改記「真正該簽這一欄的人」，管理員本人只留在 proxy 欄位供 LOG 查核（畫面一律顯示真人，不顯示管理員）。
    if (!$hasCol('con_review_doc', 'sales_decided_is_proxy')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN sales_decided_is_proxy TINYINT(1) NOT NULL DEFAULT 0
                         COMMENT '決行人非業務課真正人選、由管理員代操作' AFTER sales_is_deputy"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_doc', 'sales_decided_proxy_uid')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN sales_decided_proxy_uid INT NULL
                         COMMENT '實際操作的管理員帳號(僅供管理員查看的LOG，前端一律顯示sales_decided_by_name)' AFTER sales_decided_is_proxy"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_doc', 'sales_decided_proxy_name')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN sales_decided_proxy_name VARCHAR(50) NULL AFTER sales_decided_proxy_uid"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_doc', 'gm_approved_is_proxy')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN gm_approved_is_proxy TINYINT(1) NOT NULL DEFAULT 0
                         COMMENT '核准人非總經理真正人選、由管理員代操作' AFTER gm_is_deputy"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_doc', 'gm_approved_proxy_uid')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN gm_approved_proxy_uid INT NULL AFTER gm_approved_is_proxy"); } catch (Throwable $e) {}
    }
    if (!$hasCol('con_review_doc', 'gm_approved_proxy_name')) {
        try { $db->exec("ALTER TABLE con_review_doc ADD COLUMN gm_approved_proxy_name VARCHAR(50) NULL AFTER gm_approved_proxy_uid"); } catch (Throwable $e) {}
    }

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

/** 這個 uid 是不是全站超級管理員，不吃 session（供下面批次檢查「其他人」用，不能只查目前登入者）。 */
function cnrv_uid_is_super_admin(PDO $db, int $uid): bool {
    if ($uid === 1) return true;
    $st = $db->prepare("SELECT user_status FROM user WHERE id=?");
    $st->execute([$uid]);
    $status = $st->fetchColumn();
    if ($status !== false && in_array((int)$status, [9, 90], true)) return true;
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}

/** 這個 uid 進不進得了本模組（canView 以上），不吃 session——給「簽核人員權限檢查」查任意
 *  一個人（不是目前登入者）夠不夠資格使用本頁。 */
function cnrv_user_can_view(PDO $db, int $uid): bool {
    if (cnrv_uid_is_super_admin($db, $uid)) return true;
    return cnrv_has_role($db, $uid, ['con_review_view', 'con_review_create', 'con_review_admin']);
}

/**
 * 簽核人員權限自查（2026-10-05 使用者提問「要回簽的人員也需要本頁面權限嗎」拍板要做）：
 * 內容部門填寫/簽核、業務課決行、總經理核准都是兩層把關——人要先在這個人員池裡（部門主管／
 * 業務課主管／組織角色綁定的最高核准人），**還要**另外被指派 con_review_view 以上的角色才能
 * 真的打得開本頁/API（cnrv_perms() 的 canView）。部門主管換人、組織角色改綁定時，新人選
 * 很可能完全沒有這個角色指派，「系統解析出來的人」跟「真的能用的人」兩邊會對不起來而且
 * 不會主動報錯（那個人點開通知只會看到 403）——這支函式就是抓出這個落差，供管理員一眼看到
 * 要去「使用者權限設定」補哪些人。範圍＝目前範本有效項目用到的部門（之後新建的表單就是解析
 * 這些部門）；業務課決行池、總經理核准也一併查。
 * @return array ['depts'=>[{dept_id,dept_name,people:[{id,user_cname,can_view}]}],
 *                'sales'=>[{id,user_cname,can_view}], 'gm'=>?array, 'gaps'=>[{id,user_cname,scopes}]]
 */
function cnrv_perm_check(PDO $db): array {
    $deptIds = array_values(array_unique(array_map('intval',
        $db->query("SELECT DISTINCT dept_id FROM con_review_tpl_item WHERE is_active=1 AND dept_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)
    )));
    $deptNames = [];
    foreach ($db->query("SELECT id,name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $deptNames[(int)$d['id']] = $d['name'];

    $annotate = function (array $people) use ($db): array {
        foreach ($people as &$p) $p['can_view'] = cnrv_user_can_view($db, (int)$p['id']);
        unset($p);
        return $people;
    };

    $depts = [];
    foreach ($deptIds as $d) {
        $depts[] = ['dept_id'=>$d, 'dept_name'=>$deptNames[$d] ?? ('#'.$d), 'people'=>$annotate(cnrv_dept_pool($db, $d))];
    }
    $sales = $annotate(cnrv_sales_pool($db));
    // 總經理核准查「組織角色綁定的最高核准人員」本人（不經代理解析）——代理是「今天誰代簽」，
    // 這裡要查的是「這個固定人選本身有沒有被指派角色」，兩者是不同層次的問題。
    $gmUser = eg_org_user($db, 'top_approver');
    $gm = $gmUser ? ['id'=>(int)$gmUser['id'], 'user_cname'=>$gmUser['user_cname'], 'can_view'=>cnrv_user_can_view($db, (int)$gmUser['id'])] : null;

    // 缺口彙總：同一個人可能同時出現在好幾個範圍（例如同時是某部門主管又是業務課主管），
    // 併成一筆、scopes 列出「哪些範圍都需要他」，不要重複列好幾行。
    $gapMap = [];
    $addGap = function (array $p, string $scope) use (&$gapMap) {
        if (!empty($p['can_view'])) return;
        $uid = (int)$p['id'];
        if (!isset($gapMap[$uid])) $gapMap[$uid] = ['id'=>$uid, 'user_cname'=>$p['user_cname'], 'scopes'=>[]];
        $gapMap[$uid]['scopes'][] = $scope;
    };
    foreach ($depts as $dp) foreach ($dp['people'] as $p) $addGap($p, $dp['dept_name']);
    foreach ($sales as $p) $addGap($p, '業務課決行');
    if ($gm) $addGap($gm, '總經理核准');

    return ['depts'=>$depts, 'sales'=>$sales, 'gm'=>$gm, 'gaps'=>array_values($gapMap)];
}

/** 一鍵補齊：把 con_review_view 角色指派給傳入清單裡「目前真的缺權限」的每一個人（僅管理員可用，
 *  呼叫端自行驗證權限）。已經有角色的人（含更高的 create/admin）一律跳過不重複寫入。
 *  @return int 實際補上的人數 */
function cnrv_perm_fix(PDO $db, array $uids): int {
    $st = $db->prepare("SELECT role_id FROM roles WHERE module='" . CNRV_AS_MODULE . "' AND role_code='con_review_view' LIMIT 1");
    $st->execute();
    $roleId = (int)$st->fetchColumn();
    if (!$roleId) throw new Exception('找不到 con_review_view 角色，請先確認模組角色已建立');
    $n = 0;
    $ins = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
    foreach (array_unique(array_map('intval', $uids)) as $uid) {
        if ($uid <= 0 || cnrv_user_can_view($db, $uid)) continue;
        $ins->execute([$uid, $roleId]);
        if ($ins->rowCount() > 0) $n++;
    }
    return $n;
}

/* ============================================================ 範本項目（管理員維護） ============================================================ */

/** 每個項目的結果一律是「是／否／N/A」三者之一，外加範本上設定的預設回覆選項之一
 *  （2026-10-05 使用者明確要求）。這三個是固定的基礎選項，不給管理員改名或刪除——
 *  範本的「預設回覆選項」只是在這三個之外**追加**的額外選擇，不是取代。 */
if (!defined('CNRV_BASE_OPTIONS')) define('CNRV_BASE_OPTIONS', ['是', '否', 'N/A']);

/** 合併基礎三選項與範本自訂選項（去重，基礎選項固定排前面）。$custom 可為陣列或 JSON 字串。
 *  僅供「範本維護」畫面使用（管理員要看得到含基礎三選項的完整值域）；**填寫一份已建立的審查表單**
 *  不要用這支，見下面 cnrv_fill_options()（2026-10-05 使用者更正：回簽時不再提供是/否/NA）。 */
function cnrv_merge_options($custom): array {
    $arr = is_array($custom) ? $custom : (json_decode((string)$custom, true) ?: []);
    $arr = array_values(array_filter(array_map('trim', (array)$arr), fn($s) => $s !== '' && !in_array($s, CNRV_BASE_OPTIONS, true)));
    return array_merge(CNRV_BASE_OPTIONS, $arr);
}

/** 填寫表單時真正可選的清單：只有這個項目自己的額外選項，不含「是/否/N-A」三個基礎選項
 *  （2026-10-05 使用者明確要求「回簽時也不提供是/否/NA選項，直接提供額外選項選項，但一樣可以
 *  手動自行輸入」）——datalist 只是建議清單，<input type=text> 本來就還是能自由輸入任何文字，
 *  這支只是不再把是/否/N-A 加進建議清單裡。範本維護畫面仍走 cnrv_merge_options()，不受影響。 */
function cnrv_fill_options($custom): array {
    $arr = is_array($custom) ? $custom : (json_decode((string)$custom, true) ?: []);
    return array_values(array_filter(array_map('trim', (array)$arr), fn($s) => $s !== '' && !in_array($s, CNRV_BASE_OPTIONS, true)));
}

/** @return array 每項 ['id','sort_order','group_label','item_text','dept_id','dept_name','options'=>array(含基礎三選項),'default_value','is_active'] */
function cnrv_tpl_items_get(PDO $db, bool $activeOnly = false): array {
    cnrv_ensure_schema($db);
    $sql = "SELECT t.*, d.name AS dept_name FROM con_review_tpl_item t
            LEFT JOIN department d ON d.id=t.dept_id" . ($activeOnly ? " WHERE t.is_active=1" : "") . "
            ORDER BY t.sort_order, t.id";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['custom_options'] = json_decode((string)$r['preset_options'], true) ?: [];   // 畫面編輯欄只顯示「額外加的」
        $r['options'] = cnrv_merge_options($r['custom_options']);                       // 填寫時真正可選的完整清單
        $r['dept_id'] = $r['dept_id'] !== null ? (int)$r['dept_id'] : null;
    }
    return $rows;
}

/** $id=0 新增。$data: group_label,item_text,dept_id,options(array，額外選項不含基礎三選項),
 *  default_value（是/否/N-A 其一，與 default_extra 各自獨立、可同時設或只設一個），
 *  default_extra（額外選項其一），is_active。
 *  **sort_order 不收在這裡**——順序一律由 cnrv_tpl_reorder()（拖曳排序）或新增時的自動
 *  給號決定，使用者不必也不應該自己填數字（2026-10-05 使用者要求「順序請自動給」）。 */
function cnrv_tpl_item_save(PDO $db, int $id, array $data, int $uid, string $uname): int {
    cnrv_ensure_schema($db);
    $text = trim((string)($data['item_text'] ?? ''));
    if ($text === '') throw new Exception('項目內容不可空白');
    $group = trim((string)($data['group_label'] ?? ''));
    $deptId = (int)($data['dept_id'] ?? 0) ?: null;
    $opts = array_values(array_filter(array_map('trim', (array)($data['options'] ?? [])), fn($s) => $s !== '' && !in_array($s, CNRV_BASE_OPTIONS, true)));
    $optsJson = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
    $isActive = !empty($data['is_active']) ? 1 : 0;

    // 兩個預設值各自獨立驗證、各自對應自己的值域（使用者明確要求「這兩個是分開設定，
    // 不是只能從裡面選一個」）：default_value 只能是固定三選項之一，default_extra 只能是
    // 這個項目自己設定的額外選項之一；兩者互不影響，可以同時有值、也可以只有一個有值。
    $defVal = trim((string)($data['default_value'] ?? ''));
    if ($defVal !== '' && !in_array($defVal, CNRV_BASE_OPTIONS, true)) {
        throw new Exception('「是/否/N-A 預設值」必須是「是」「否」「N/A」三者之一');
    }
    $defVal = $defVal !== '' ? $defVal : null;
    $defExtra = trim((string)($data['default_extra'] ?? ''));
    if ($defExtra !== '' && !in_array($defExtra, $opts, true)) {
        throw new Exception('「額外選項預設值」必須是這個項目目前設定的額外選項之一');
    }
    $defExtra = $defExtra !== '' ? $defExtra : null;

    if ($id) {
        $db->prepare("UPDATE con_review_tpl_item SET group_label=?,item_text=?,dept_id=?,preset_options=?,default_value=?,default_extra=?,is_active=?,
                      updated_by=?,updated_by_name=?,updated_at=NOW() WHERE id=?")
           ->execute([$group, $text, $deptId, $optsJson, $defVal, $defExtra, $isActive, $uid, $uname, $id]);
        return $id;
    }
    // 新增：順序自動接在最後面（現有最大值 +10），不必使用者自己指定。
    $nextSort = (int)($db->query("SELECT COALESCE(MAX(sort_order),0) FROM con_review_tpl_item")->fetchColumn()) + 10;
    $db->prepare("INSERT INTO con_review_tpl_item (sort_order,group_label,item_text,dept_id,preset_options,default_value,default_extra,is_active,created_by,created_by_name)
                  VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$nextSort, $group, $text, $deptId, $optsJson, $defVal, $defExtra, $isActive, $uid, $uname]);
    return (int)$db->lastInsertId();
}

function cnrv_tpl_item_delete(PDO $db, int $id): void {
    cnrv_ensure_schema($db);
    $db->prepare("DELETE FROM con_review_tpl_item WHERE id=?")->execute([$id]);
}

/** 拖曳排序：$orderedIds 是畫面上拖完之後、由上到下的 id 清單，依序重新編號 10,20,30...
 *  （留間隔不是緊鄰整數，方便日後要插在兩項中間時不必整批重編——雖然目前排序只能靠拖曳，
 *  這個習慣沿用全站其他拖曳排序頁面的既有做法）。 */
function cnrv_tpl_reorder(PDO $db, array $orderedIds): void {
    cnrv_ensure_schema($db);
    $upd = $db->prepare("UPDATE con_review_tpl_item SET sort_order=? WHERE id=?");
    $sort = 0;
    foreach ($orderedIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $sort += 10;
        $upd->execute([$sort, $id]);
    }
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

/** 這張訂單已經建過的審查表單 id（0＝還沒建）。已刪除的不算，可以重新建一張。 */
function cnrv_doc_by_order(PDO $db, int $orderId): int {
    cnrv_ensure_schema($db);
    $st = $db->prepare("SELECT id FROM con_review_doc WHERE order_id=? AND is_deleted=0 ORDER BY id DESC LIMIT 1");
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
    $st = $db->prepare("SELECT order_id, id, status FROM con_review_doc WHERE is_deleted=0 AND order_id IN ($in) ORDER BY id");
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

        // preset_options 存的是「額外選項」（custom_options），不是合併後的完整清單——
        // 完整清單（含固定的是/否/N-A）一律由 cnrv_merge_options() 在讀取時現算，
        // 存成合併後的結果會讓舊單據的欄位跟著基礎選項以後若有調整而過期。
        $ins = $db->prepare("INSERT INTO con_review_item (doc_id,tpl_item_id,sort_order,group_label,item_text,dept_id,preset_options,default_value,default_extra)
                              VALUES (?,?,?,?,?,?,?,?,?)");
        foreach (cnrv_tpl_items_get($db, true) as $t) {
            $ins->execute([$docId, $t['id'], $t['sort_order'], $t['group_label'], $t['item_text'], $t['dept_id'],
                            $t['custom_options'] ? json_encode($t['custom_options'], JSON_UNESCAPED_UNICODE) : null,
                            $t['default_value'], $t['default_extra']]);
        }
        $db->commit();
        return $docId;
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}

/**
 * 建議建立清單：找出「需要審查、但還沒建過審查表單」的訂單（2026-10-05 使用者要求）。
 * 舊資料不強制補（CLAUDE.md 既有口徑），所以這裡只是「列出來讓管理員自己決定要不要一鍵建」，
 * 不會自動建立、也不會因為沒建就擋下任何訂單操作。預設只看最近 N 天的訂單（$days），
 * 避免一次把上千張舊訂單全部列出來嚇到人；$days=0 代表不限天數（列出全部待建議的）。
 */
function cnrv_suggest_list(PDO $db, int $days = 30, int $limit = 300): array {
    // client_id：前端「輸入客戶部份ID或部份名稱即時篩選」要靠它（2026-10-05 使用者要求）。
    $sql = "SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Qty, ot.Order_date, ot.Delivery_date,
                   ot.Client_name, cl.customer AS client_name_txt, cl.customer_id AS client_id,
                   t.proc_name AS tag_proc_name, ot.as_tag_scope
            FROM order_track ot
            LEFT JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
            JOIN ot_as_proc_tag t ON t.tag_id = ot.as_tag_id AND t.kind='process'
            WHERE (ot.Order_status IS NULL OR ot.Order_status<>6)
              AND NOT EXISTS (SELECT 1 FROM con_review_doc d WHERE d.order_id=ot.Order_id AND d.is_deleted=0)";
    $params = [];
    if ($days > 0) { $sql .= " AND ot.Order_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"; $params[] = $days; }
    $sql .= " ORDER BY ot.Order_date DESC, ot.Order_id DESC LIMIT " . max(1, min(1000, $limit));
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['tag_label'] = $r['tag_proc_name'] ? (($r['as_tag_scope']==='full' ? '全製含' : '單製') . $r['tag_proc_name']) : '';
    // 總數（不受 limit 影響，讓畫面知道「還有多少沒列出來」）
    $cntSql = "SELECT COUNT(*) FROM order_track ot JOIN ot_as_proc_tag t ON t.tag_id=ot.as_tag_id AND t.kind='process'
               WHERE (ot.Order_status IS NULL OR ot.Order_status<>6)
                 AND NOT EXISTS (SELECT 1 FROM con_review_doc d WHERE d.order_id=ot.Order_id AND d.is_deleted=0)"
              . ($days > 0 ? " AND ot.Order_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)" : "");
    $cst = $db->prepare($cntSql);
    $cst->execute($days > 0 ? [$days] : []);
    return ['rows'=>$rows, 'total'=>(int)$cst->fetchColumn()];
}

/**
 * 批次建立：逐筆呼叫 cnrv_create()，個別成功/失敗互不影響（某張訂單缺接單日期等原因失敗，
 * 不應該讓整批都建不成）。回傳 ['created'=>[訂單id=>表單id], 'failed'=>[訂單id=>原因]]。
 */
function cnrv_batch_create(PDO $db, array $orderIds, int $uid, string $uname): array {
    $created = []; $failed = [];
    foreach (array_unique(array_map('intval', $orderIds)) as $oid) {
        if ($oid <= 0) continue;
        try { $created[$oid] = cnrv_create($db, $oid, $uid, $uname); }
        catch (Throwable $e) { $failed[$oid] = $e->getMessage(); }
    }
    return ['created'=>$created, 'failed'=>$failed];
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
    foreach ($rows as &$r) $r['options'] = cnrv_fill_options($r['preset_options']);
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

/* ============================================================ 列印設定（簽章圖章模板，ai-rules/18） ============================================================
 * 比照 stock.php 領料需求單的既有做法（鐵律4：全站只有一套「模組→圖章模板」存法）。
 * 值存 system_parameters('CON_REVIEW','stamp_tpl_id')，0＝未指定、消費端退回 eg_stamp.js 預設回墨印。
 */

/** 本模組要套用的圖章模板 id（0＝未設定）。 */
function cnrv_stamp_tpl_id(PDO $db): int {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='CON_REVIEW' AND param_key='stamp_tpl_id' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v === false) return 0;
        $d = json_decode((string)$v, true);
        return (int)(is_numeric($d) ? $d : (is_numeric($v) ? $v : 0));
    } catch (Throwable $e) { return 0; }
}

/** 圖章模板內容（停用或查無回 null）。 */
function cnrv_stamp_tpl(PDO $db, int $tplId): ?array {
    if (!$tplId) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$tplId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}

/** 圖章模板下拉清單（設定跳窗用）。 */
function cnrv_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p
                           LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 存檔：僅管理員（呼叫端自行驗證），$tplId=0 代表改回系統預設回墨印。 */
function cnrv_stamp_tpl_save(PDO $db, int $tplId, string $by): void {
    if ($tplId) {
        $chk = $db->prepare("SELECT id FROM stamp_template WHERE id=? AND is_active=1");
        $chk->execute([$tplId]);
        if (!$chk->fetchColumn()) throw new Exception('選擇的圖章模板不存在或已停用');
    }
    $ex = $db->prepare("SELECT id FROM system_parameters WHERE param_group='CON_REVIEW' AND param_key='stamp_tpl_id' LIMIT 1");
    $ex->execute();
    $rid = $ex->fetchColumn();
    if ($rid) $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([(string)$tplId, $by, $rid]);
    else $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by) VALUES ('CON_REVIEW','stamp_tpl_id',?,?,?)")
             ->execute([(string)$tplId, '合約訂單審查表列印/畫面：簽章圖章模板 id（0=用系統預設印章）', $by]);
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

/* ============================================================ 管理員：自動填寫並簽核（2026-10-05 使用者交辦） ============================================================
 * 「管理員可以不需送出自動全部填寫...自動帶入預設結果後自動簽核」——給例行、低風險訂單一鍵快速
 * 走完內容部門這一段（送出＋逐項帶入範本預設值＋內容部門自動簽核），業務課決行／總經理核准
 * 仍是個別的人工動作（那是實質的業務判斷，不該被「預設值」代勞）。
 *
 * 簽核日期規則：預設＝接單日期（業務日期）當天；管理員可改晚於當天的日期，但一定要是工作日
 * （借用 leave_lib.php 既有的 eg_leave_is_workday()，不重寫一套假日判斷）。
 *
 * 簽核人一定要是「那天真的有上班」的人：借用 meeting_lib.php 已經驗證過的
 * meeting_notice_absent_reason()（在職狀態asof＋整天請假／整天公出，2026-09-29 踩過
 * 「公出單有填時間、allday旗標卻是0」這個坑才修好的那一套，這裡直接重用不重寫）。
 * 逐一嘗試部門候選名單裡的每個人，找到第一個「那天真的在」的人才簽；整個候選名單都不在，
 * 這個部門就不自動簽、把原因列出來讓管理員自己決定（換日期，或自己手動簽），
 * **絕不可以明知道對方那天不在還是把章蓋上去**——那正是使用者原話要避免的情況。
 */

/** 某部門在指定日期「找得到誰可以簽」：回傳 ['signer'=>人員陣列或null, 'warnings'=>[跳過原因...]]。 */
function cnrv_dept_pool_available(PDO $db, int $deptId, string $date): array {
    require_once __DIR__ . '/meeting_lib.php';
    $warnings = [];
    foreach (cnrv_dept_pool($db, $deptId) as $p) {
        $why = meeting_notice_absent_reason($db, (int)$p['id'], $date);
        if ($why === '') return ['signer'=>$p, 'warnings'=>$warnings];
        $warnings[] = $p['user_cname'] . '：' . $why;
    }
    return ['signer'=>null, 'warnings'=>$warnings];
}

/**
 * @return array ['sign_date','filled'=>N,'skipped_no_default'=>N,
 *                'signed_depts'=>[dept_id=>['dept_name','signer_name','note_warnings'=>[]]],
 *                'unsigned_depts'=>[dept_id=>['dept_name','reason']]]
 */
function cnrv_admin_auto_fill_sign(PDO $db, int $docId, string $signDate, int $adminUid, string $adminName): array {
    require_once __DIR__ . '/leave_lib.php';
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if (!in_array($doc['status'], ['draft', 'submitted'], true)) throw new Exception('此表單狀態不可使用自動填寫並簽核');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signDate)) throw new Exception('簽核日期格式不正確');
    if ($signDate < (string)$doc['business_date']) throw new Exception('簽核日期不可早於接單日期（' . $doc['business_date'] . '）');
    if (!eg_leave_is_workday($db, $signDate)) throw new Exception('簽核日期 ' . $signDate . ' 不是工作日，請改選工作日');

    $deptNames = [];
    foreach ($db->query("SELECT id,name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $deptNames[(int)$d['id']] = $d['name'];

    $db->beginTransaction();
    try {
        if ($doc['status'] === 'draft') {
            $db->prepare("UPDATE con_review_doc SET status='submitted',submit_date=?,submitted_at=?,submitted_by=?,submitted_by_name=? WHERE id=?")
               ->execute([$signDate, $signDate . ' 09:00:00', $adminUid, $adminName, $docId]);
        }

        // 每個內容部門「這天可以簽的人」只算一次——項目填寫人與最後的部門簽核要是同一個人，
        // 不可以項目填寫人是 A、部門簽核卻換成 B（2026-10-05 使用者要求「填寫人要是各單位對應到的
        // 人員」，不可以顯示成執行這次操作的「超級管理員」）。已簽過的部門（含人工先簽）不重算。
        $deptAvail = [];
        foreach (cnrv_doc_dept_ids($db, $docId) as $deptId) {
            $deptAvail[$deptId] = cnrv_dept_signed($db, $docId, $deptId)
                ? ['signer'=>null, 'warnings'=>[]]
                : cnrv_dept_pool_available($db, $deptId, $signDate);
        }

        // 逐項帶入範本預設值：只補還沒填的，已有答案的一律不動（不可覆蓋別人已填的內容）。
        // 2026-10-05 使用者更正：答案一律只用「額外選項」的預設值（default_extra），
        // 不再把「是/否/N-A」（default_value）接進答案文字——使用者原話「前端與列印結果隱藏
        // 是/否/NA 改成直接顯示額外選項」，回填時自然也比照辦理；default_value 仍留在範本
        // 設定供管理員參考（不刪除這個設定欄位），只是不再組進自動帶入的答案裡。
        // 沒有設定額外選項預設值的項目（只設了是/否/N-A 或兩個都沒設）視為「沒有預設值」略過。
        // 填寫人＝該項目所屬部門這天解析出來的真人（$deptAvail 的 signer）；當天查無可用人員時
        // 退回該部門人員池的第一人（他仍是真實的部門成員、只是那天不在無法簽核，部門因此留在
        // 「未簽核」狀態由管理員自行處理，但項目內容的填寫人依然要是一個真人不是管理員）；
        // 部門完全沒有任何人員（池本身是空的）才不得已退回管理員本人。
        $filled = 0; $skippedNoDefault = 0;
        $itemSt = $db->prepare("SELECT id, dept_id, answer_value, default_extra FROM con_review_item WHERE doc_id=?");
        $itemSt->execute([$docId]);
        $updSt = $db->prepare("UPDATE con_review_item SET answer_value=?,filled_by=?,filled_by_name=?,filled_at=? WHERE id=?");
        foreach ($itemSt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            if ($it['answer_value'] !== null && $it['answer_value'] !== '') continue;
            $ans = trim((string)($it['default_extra'] ?? ''));
            if ($ans === '') { $skippedNoDefault++; continue; }
            $deptId = (int)($it['dept_id'] ?? 0);
            $filler = $deptAvail[$deptId]['signer'] ?? null;
            if (!$filler) { $pool = cnrv_dept_pool($db, $deptId); $filler = $pool[0] ?? null; }
            $fillerUid = $filler ? (int)$filler['id'] : $adminUid;
            $fillerName = $filler ? $filler['user_cname'] : $adminName;
            $updSt->execute([$ans, $fillerUid, $fillerName, $signDate . ' 09:00:00', $it['id']]);
            $filled++;
        }

        // 逐內容部門嘗試自動簽核（沿用上面已經算好的 $deptAvail，不重新查一次候選名單）
        $signedDepts = []; $unsignedDepts = [];
        foreach (cnrv_doc_dept_ids($db, $docId) as $deptId) {
            $dName = $deptNames[$deptId] ?? ('#' . $deptId);
            if (cnrv_dept_signed($db, $docId, $deptId)) continue;   // 已簽過的（含人工先簽的）不動
            $chk = $db->prepare("SELECT COUNT(*) c, SUM(answer_value IS NOT NULL AND answer_value<>'') filled
                                 FROM con_review_item WHERE doc_id=? AND dept_id=?");
            $chk->execute([$docId, $deptId]);
            $r = $chk->fetch(PDO::FETCH_ASSOC);
            if ((int)$r['c'] > (int)$r['filled']) {
                $unsignedDepts[$deptId] = ['dept_name'=>$dName, 'reason'=>'本部門仍有項目沒有設定預設值，無法自動填完整'];
                continue;
            }
            $avail = $deptAvail[$deptId] ?? ['signer'=>null, 'warnings'=>[]];
            if (!$avail['signer']) {
                $unsignedDepts[$deptId] = ['dept_name'=>$dName, 'reason'=>'這天本部門候選簽核人都不在：' . ($avail['warnings'] ? implode('；', $avail['warnings']) : '查無候選人員，請先設定部門主管或人員')];
                continue;
            }
            $db->prepare("INSERT INTO con_review_dept_sign (doc_id,dept_id,signed_by,signed_by_name,signed_at,is_auto_sign,auto_sign_by,auto_sign_by_name)
                          VALUES (?,?,?,?,?,1,?,?)")
               ->execute([$docId, $deptId, (int)$avail['signer']['id'], $avail['signer']['user_cname'], $signDate . ' 09:30:00', $adminUid, $adminName]);
            $signedDepts[$deptId] = ['dept_name'=>$dName, 'signer_name'=>$avail['signer']['user_cname'], 'note_warnings'=>$avail['warnings']];
        }
        $db->commit();
        if ($signedDepts) {
            $toUids = array_map(fn($p) => (int)$p['id'], cnrv_sales_pool($db));
            if ($toUids && cnrv_all_depts_signed($db, $docId)) cnrv_notify($db, $docId, $toUids, '合約訂單審查待決行',
                "訂單 {$doc['order_oo']}（{$doc['client_name']}）的合約訂單審查，內容部門已全部簽核，請前往決行。", $adminUid);
        }
        return ['sign_date'=>$signDate, 'filled'=>$filled, 'skipped_no_default'=>$skippedNoDefault,
                'signed_depts'=>$signedDepts, 'unsigned_depts'=>$unsignedDepts];
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
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

/**
 * 業務課決行：全部內容部門簽完才能決行。
 * 2026-10-05 使用者交辦：「決行與核准也要是對應部門人員決行，絕對不可以是代簽的管理員（除非
 * 管理原本就應該簽那一欄）」——操作者本人若真的在業務課決行人員池內（含管理員本人剛好就是
 * 業務課主管的情況），照常記本人；不在池內（典型是管理員代為操作）則改記業務課真正的人選，
 * 操作者本人只留進 proxy 欄位供「只有管理員看得到的 LOG」查核，一般畫面與列印一律只顯示真人。
 */
function cnrv_decide(PDO $db, int $docId, int $uid, string $uname, string $decision, ?string $note, bool $isAdmin): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if ($doc['status'] !== 'submitted') throw new Exception('表單狀態不正確，不可決行');
    if (!array_key_exists($decision, CNRV_DECISIONS)) throw new Exception('決行結果不合法');
    if (!$isAdmin && !cnrv_can_decide($db, $uid, false)) throw new Exception('您沒有業務課決行的權限');
    if (!cnrv_all_depts_signed($db, $docId)) throw new Exception('尚有內容部門未完成簽核，不可決行');

    $signerId = $uid; $signerName = $uname; $isProxy = 0; $proxyUid = null; $proxyName = null;
    if (!cnrv_can_decide($db, $uid, false)) {
        $pool = cnrv_sales_pool($db);
        if ($pool) {
            $signerId = (int)$pool[0]['id']; $signerName = $pool[0]['user_cname'];
            $isProxy = 1; $proxyUid = $uid; $proxyName = $uname;
        }
        // 業務課完全沒設定任何人員（池是空的）時沒有真人可代，只能仍記操作者本人。
    }
    $db->prepare("UPDATE con_review_doc SET decision=?,decision_note=?,sales_decided_by=?,sales_decided_by_name=?,sales_decided_at=NOW(),
                  sales_decided_is_proxy=?,sales_decided_proxy_uid=?,sales_decided_proxy_name=? WHERE id=?")
       ->execute([$decision, $note, $signerId, $signerName, $isProxy, $proxyUid, $proxyName, $docId]);
    $gm = cnrv_gm_signer($db, $docId);
    if ($gm) cnrv_notify($db, $docId, [(int)$gm['id']], '合約訂單審查待核准',
        "訂單 {$doc['order_oo']}（{$doc['client_name']}）的合約訂單審查已決行為「" . CNRV_DECISIONS[$decision] . "」，請核准。", $uid);
}

/** 總經理核准：核准後 status=closed。同一套「代簽記真人、管理員本人只留 LOG」規則（見 cnrv_decide() 說明）。 */
function cnrv_approve(PDO $db, int $docId, int $uid, string $uname, bool $isAdmin, bool $isDeputy = false): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    if ($doc['status'] !== 'submitted' || $doc['decision'] === null) throw new Exception('尚未完成業務課決行，不可核准');

    $gm = cnrv_gm_signer($db, $docId);
    $signerId = $uid; $signerName = $uname; $isProxy = 0; $proxyUid = null; $proxyName = null;
    if (!$isAdmin) {
        if (!$gm || (int)$gm['id'] !== $uid) throw new Exception('您沒有核准此表單的權限');
        $isDeputy = !empty($gm['is_deputy']);
    } elseif ($gm && (int)$gm['id'] !== $uid) {
        // 管理員本人不是真正的總經理核准人（含代理解析後的結果）→ 代簽，記錄真正的核准人，
        // 管理員本人只留進 proxy 欄位。
        $signerId = (int)$gm['id']; $signerName = $gm['user_cname'];
        $isDeputy = !empty($gm['is_deputy']);
        $isProxy = 1; $proxyUid = $uid; $proxyName = $uname;
    }
    // $gm 查無資料（完全沒設定 top_approver）時沒有真人可代，只能仍記操作者本人（可能是管理員）。
    $db->prepare("UPDATE con_review_doc SET status='closed',gm_approved_by=?,gm_approved_by_name=?,gm_approved_at=NOW(),gm_is_deputy=?,
                  gm_approved_is_proxy=?,gm_approved_proxy_uid=?,gm_approved_proxy_name=?,closed_at=NOW() WHERE id=?")
       ->execute([$signerId, $signerName, $isDeputy?1:0, $isProxy, $proxyUid, $proxyName, $docId]);
}

/** 管理員刪除（2026-10-05 使用者交辦「可刪除未審核的」，同日再交辦「也要能刪除舊有已決行
 *  （含已結案）的表單」，原本只准刪 draft/submitted 的限制已解除）：軟刪除（is_deleted=1，
 *  留 deleted_by/_at），不是真的 DELETE——訂單審查紀錄本身屬於品質紀錄的一種，刪了也要留
 *  痕跡供日後追查「誰在什麼時候刪掉了這一筆」，不能憑空消失；刪除後該訂單的名額釋放出來，
 *  可以重新建立一張新的審查表單（cnrv_doc_by_order() 等查詢一律只看 is_deleted=0 的列）。 */
function cnrv_delete(PDO $db, int $docId, int $uid, string $uname): void {
    $doc = cnrv_get($db, $docId);
    if (!$doc) throw new Exception('找不到此表單');
    $db->prepare("UPDATE con_review_doc SET is_deleted=1,deleted_by=?,deleted_by_name=?,deleted_at=NOW() WHERE id=?")
       ->execute([$uid, $uname, $docId]);
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
    // has_admin_sign：這張單有沒有任何一段是管理員自動／代為簽核的（內容部門自動帶入、補登，
    // 或決行／核准由管理員代真人簽），只給清單「顯示管理員代簽標記」開關（僅管理員看得到）用；
    // 一般欄位（簽核人姓名）本來就已經是真人，這欄只是額外的管理員查核用途。
    $sql = "SELECT d.*,
                   EXISTS(SELECT 1 FROM con_review_dept_sign s WHERE s.doc_id=d.id AND (s.is_auto_sign=1 OR s.is_backfill=1)) AS has_dept_admin_sign
            FROM con_review_doc d WHERE " . implode(' AND ', $where) . " ORDER BY d.id DESC";
    $limit = max(1, min(200, (int)($f['limit'] ?? 50)));
    $offset = max(0, (int)($f['offset'] ?? 0));
    $sql .= " LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // 2026-10-05 使用者要求：「管理員代簽」欄要顯示代簽管理員的姓名與日期時間（名稱換行後接日期時間），
    // 不能只是個靜態「管理員代簽」標籤。彙整三種來源：部門自動填寫並簽核（auto_sign_by_name）、
    // 部門逐格補登（backfill_by_name）、決行/核准由管理員代真人簽（*_proxy_name）——批次一次查，
    // 避免每一列各查一次（N+1）。
    $docIds = array_map(fn($r) => (int)$r['id'], $rows);
    $deptEvents = [];
    if ($docIds) {
        $ph = implode(',', array_fill(0, count($docIds), '?'));
        $dst = $db->prepare("SELECT doc_id, is_auto_sign, is_backfill, auto_sign_by_name, backfill_by_name, signed_at
                              FROM con_review_dept_sign
                              WHERE doc_id IN ($ph) AND (is_auto_sign=1 OR is_backfill=1)");
        $dst->execute($docIds);
        foreach ($dst->fetchAll(PDO::FETCH_ASSOC) as $dr) {
            $name = $dr['is_auto_sign'] ? ($dr['auto_sign_by_name'] ?: null) : ($dr['backfill_by_name'] ?: null);
            if (!$name) continue;
            $deptEvents[(int)$dr['doc_id']][] = ['name'=>$name, 'at'=>$dr['signed_at']];
        }
    }
    foreach ($rows as &$r) {
        $events = $deptEvents[(int)$r['id']] ?? [];
        if (!empty($r['sales_decided_is_proxy']) && $r['sales_decided_proxy_name']) {
            $events[] = ['name'=>$r['sales_decided_proxy_name'], 'at'=>$r['sales_decided_at']];
        }
        if (!empty($r['gm_approved_is_proxy']) && $r['gm_approved_proxy_name']) {
            $events[] = ['name'=>$r['gm_approved_proxy_name'], 'at'=>$r['gm_approved_at']];
        }
        $r['has_admin_sign'] = !empty($r['has_dept_admin_sign']) || !empty($r['sales_decided_is_proxy']) || !empty($r['gm_approved_is_proxy']);
        $r['admin_sign_events'] = $events;
        unset($r['has_dept_admin_sign']);
    }
    unset($r);
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
