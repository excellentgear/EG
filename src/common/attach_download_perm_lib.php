<?php
/**
 * 附件下載權限（2D／3D × 客戶提供／公司內部）—— 全系統唯一實作
 *
 * 背景（2026-10-08 使用者交辦）：目前「下載/另存」附件這個動作，從前端按鈕到後端 API，
 * 完全沒有任何角色/權限控管——只要已登入且在職，誰都能下載任何一筆附件。使用者要求：
 * ①新增一個角色，可分別設定「能不能下載 2D客供／2D內部／3D客供／3D內部」四種組合
 * ②附件標籤可由管理員標記「這個標籤是客戶提供的」，未標記的標籤一律視為公司內部
 * ③已上傳附件的客供/內部分類一旦確立就鎖定，不可被人惡意從「不可下載的內部標籤」
 *   改成「可下載的客供標籤」（或反過來），要鎖定、要由管理員解鎖才能改
 *
 * ── 四大設計決定 ──────────────────────────────────────────────────────────
 * 1. 2D／3D 一律由「副檔名」判定，不是標籤——與 bom_viewer.php／master_data_management.php
 *    既有的 `_d3Exts`／`_d3NeedConvertExts` 用同一份清單（見 EG_ADP_3D_EXTS），不要另外
 *    發明一套 2D/3D 標籤分類，否則同一份檔案的「是不是 3D」會因為頁面不同而答案不一致。
 * 2. 客戶／內部一律由「標籤」判定——`quotation_file_categories.is_customer_tag`＝1 的
 *    標籤才算客供，一個檔案只要掛著任何一個客供標籤就整份視為客供，未掛任何客供標籤
 *    （含完全沒有標籤）一律視為公司內部，對應使用者原話「未設定為客戶的標籤內的檔案
 *    就認定是公司內部」。
 * 3. 鎖定的是「客供／內部這個分類本身」，不是標籤清單整體——一份附件上傳當下先自由
 *    決定一次分類（`dl_is_customer`），之後任何一次改標籤，只要改完重新計算出來的分類
 *    跟原本鎖住的值不一樣，就擋下整次存檔並要求先解鎖；分類沒有被牽動的改標籤（只是
 *    增減其他一般標籤）完全不受影響，照常存檔。
 * 4. 下載權限檢查**只擋真正的「另存/下載」動作（?dl=1），不擋內嵌預覽**——bom_viewer.php／
 *    master_data_management.php 平常顯示圖片/PDF/3D模型都是用同一支 API 的「inline」模式
 *    （`eg_attach_send_disposition()` 依 `$_GET['dl']` 分流），平常看圖面是工作本來就要做
 *    的事、不受此權限限制，只有真的要把檔案存到自己電腦才需要權限（AS9100 常見的
 *    「可以看、不可以帶走」概念）。
 *
 * ── 權限判定：2026-10-12 起改為 fail-close（使用者拍板）──────────────────
 * 新角色 module='attach_dl'，四個功能碼 dl_2d_customer/dl_2d_internal/dl_3d_customer/
 * dl_3d_internal。**沒有被指派到對應功能碼的人一律不能下載**（管理員＝RBAC 'all' 或呼叫端
 * 傳入的 $isPageAdmin 固定全部放行）。
 *
 * 2026-10-08 第一版刻意做成 fail-open（「沒被指派過 attach_dl 任何角色＝視為尚未啟用限制，
 * 照舊全部放行」），因為當時一個角色都還沒指派、使用者要求「絕對不可影響現有使用者」。
 * 2026-10-12 使用者指派完角色後交辦「人員沒有設定任何下載權限角色時，應該自動隱藏相關
 * 2D 跟 3D 檔案的下載按鈕」，確認要收緊，故改為 fail-close。**這是全公司範圍的行為改變**：
 * 當下在職 38 人、被指派者只有 4 位（設計 3 位＋業務主管 1 位），其餘人不再能下載任何附件。
 * 要再放寬請改這裡一處，不要在各呼叫端各自放行。
 *
 * fail-close 之下有兩個必須注意的點（都已處理，動這支程式時不要拿掉）：
 *  ‧uid 一定要先過 eg_adp_resolve_uid()——有些入口的 session 只有 userName 沒有 id，
 *    uid=0 在 fail-open 時會被「沒角色＝放行」接住，fail-close 之下會把登入正常的人全擋掉。
 *  ‧前端要同步隱藏按鈕（bom_viewer.php 的 can_dl / syncDlBtns），否則使用者只會看到
 *    「按了沒反應／跳出一串英數字的錯誤」而不知道是權限問題。
 *
 * ── 鎖定解除機制（比照訂單追蹤「解鎖客戶欄」ot_client_unlock_* 同一套做法）────
 * 解鎖是「本頁管理員（master_data 的 A 或 all）或全站系統管理員」的動作，走全站共用的
 * 操作確認密碼（confirm_password_lib.php），**不是另外做一套密碼系統**；本頁管理員原本
 * 不在操作確認密碼的授權名單裡，故 eg_adp_auto_grant_page_admin() 會在他第一次要解鎖時
 * 自動授權（不需超管再一個個白名單加）。解鎖有效期 10 分鐘、存在 session（per 使用者，
 * 不分哪一筆附件——附件橫跨料號/訂單/報價三張表，沒有像訂單那種單一主鍵可以掛，索性
 * 做成「這個人在接下來 10 分鐘內，改分類都先放行」，超過時間要重新輸入密碼）。
 */

if (!defined('EG_ADP_UNLOCK_TTL')) define('EG_ADP_UNLOCK_TTL', 600); // 10 分鐘
if (!defined('EG_ADP_CONFIRM_ACTION_KEY')) define('EG_ADP_CONFIRM_ACTION_KEY', 'attach_dl_unlock');
if (!defined('EG_ADP_RBAC_MODULE')) define('EG_ADP_RBAC_MODULE', 'attach_dl');

// 3D 模型副檔名：與 bom_viewer.php／master_data_management.php 的 _d3Exts + _d3NeedConvertExts
// 完全同一份清單（唯一來源在這裡，上述兩頁的 JS 清單是另外獨立的前端顯示判斷，含義不同
// 不必互相 include，但異動其中一邊務必同步檢查另一邊，避免「這頁說是3D、那頁說是2D」）。
if (!defined('EG_ADP_3D_EXTS')) define('EG_ADP_3D_EXTS', ['stp','step','stl','obj','igs','iges','ipt','x_t']);

if (!function_exists('eg_adp_ensure_schema')) {
    function eg_adp_ensure_schema(PDO $db): void {
        try { $db->exec("ALTER TABLE quotation_file_categories ADD COLUMN is_customer_tag TINYINT(1) NOT NULL DEFAULT 0 COMMENT '客戶提供的標籤(下載權限分類用，未勾=公司內部)'"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE part_attachments ADD COLUMN dl_is_customer TINYINT(1) NULL DEFAULT NULL COMMENT '下載權限分類快照：1=客供/0=內部，上傳當下決定後鎖定，NULL=尚未分類(舊資料)'"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE order_attachments ADD COLUMN dl_is_customer TINYINT(1) NULL DEFAULT NULL COMMENT '下載權限分類快照：1=客供/0=內部，上傳當下決定後鎖定，NULL=尚未分類(舊資料)'"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE quotation_attachments ADD COLUMN dl_is_customer TINYINT(1) NULL DEFAULT NULL COMMENT '下載權限分類快照：1=客供/0=內部，上傳當下決定後鎖定，NULL=尚未分類(舊資料)'"); } catch (Throwable $e) {}
        // 舊資料一次性回填：尚未分類(NULL)的，依目前標籤即時算一次當作初始分類（之後就鎖定）。
        // 只補 NULL，絕不覆蓋已經算過一次的值——否則管理員事後新增客供標籤時，會把舊檔案
        // 的既有分類悄悄改掉，變成繞過鎖定的後門。
        try {
            $custIds = $db->query("SELECT id FROM quotation_file_categories WHERE is_customer_tag=1")->fetchAll(PDO::FETCH_COLUMN);
            foreach (['part_attachments', 'order_attachments', 'quotation_attachments'] as $t) {
                if ($custIds) {
                    $conds = implode(' OR ', array_map(fn($id) => "FIND_IN_SET($id, category_ids)", $custIds));
                    $db->exec("UPDATE $t SET dl_is_customer=1 WHERE dl_is_customer IS NULL AND category_ids IS NOT NULL AND ($conds)");
                }
                $db->exec("UPDATE $t SET dl_is_customer=0 WHERE dl_is_customer IS NULL");
            }
        } catch (Throwable $e) {}
    }
}

/** 副檔名判定是不是 3D 模型（不分大小寫，不含開頭的點） */
if (!function_exists('eg_adp_is_3d_ext')) {
    function eg_adp_is_3d_ext(string $filenameOrExt): bool {
        $ext = strtolower(pathinfo($filenameOrExt, PATHINFO_EXTENSION) ?: $filenameOrExt);
        return in_array($ext, EG_ADP_3D_EXTS, true);
    }
}

/**
 * 目前被標記為「客戶提供」的標籤 id 集合（請求內快取）。
 * 檔案清單一次要判幾十筆附件，每筆各查一次 DB＝N+1；標籤設定在同一個請求內不會變，
 * 查一次放記憶體即可。呼叫端不必自己快取。
 */
if (!function_exists('eg_adp_customer_cat_ids')) {
    function eg_adp_customer_cat_ids(PDO $db, bool $reset = false): array {
        static $cache = null;
        if ($reset) { $cache = null; return []; }
        if ($cache !== null) return $cache;
        try {
            $cache = array_map('intval', $db->query("SELECT id FROM quotation_file_categories WHERE is_customer_tag=1")->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { $cache = []; }
        return $cache;
    }
}

/** 由逗號分隔的 category_ids 字串，即時算出「是不是客供」（任一標籤有 is_customer_tag=1 即為客供） */
if (!function_exists('eg_adp_compute_is_customer')) {
    function eg_adp_compute_is_customer(PDO $db, ?string $catIdsStr): bool {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$catIdsStr))));
        if (!$ids) return false;
        return (bool)array_intersect($ids, eg_adp_customer_cat_ids($db));
    }
}

/** 分類 → 角色功能碼 */
if (!function_exists('eg_adp_class_code')) {
    function eg_adp_class_code(bool $is3d, bool $isCustomer): string {
        return 'dl_' . ($is3d ? '3d' : '2d') . '_' . ($isCustomer ? 'customer' : 'internal');
    }
}
/** 分類 → 畫面顯示用中文（例："3D·客戶提供"） */
if (!function_exists('eg_adp_class_label')) {
    function eg_adp_class_label(bool $is3d, bool $isCustomer): string {
        return ($is3d ? '3D' : '2D') . '·' . ($isCustomer ? '客戶提供' : '公司內部');
    }
}

/**
 * 把呼叫端給的 uid 補齊（2026-10-12 改 fail-close 後必備）。
 * 站上有些入口的 session 只有 userName 沒有 id（見記憶 session_userid_key 與 bom_viewer.php
 * 既有的回查邏輯）——以前 fail-open 時 uid=0 會被「沒角色＝放行」接住，現在 fail-close 之下
 * uid=0 等於沒有任何功能碼，**會把登入正常的人整個擋掉**。故一律先回查補上。
 */
if (!function_exists('eg_adp_resolve_uid')) {
    function eg_adp_resolve_uid(PDO $db, int $uid): int {
        if ($uid > 0) return $uid;
        $uname = $_SESSION['userName'] ?? '';
        if ($uname === '') return 0;
        try {
            $st = $db->prepare("SELECT id FROM user WHERE user_uname = ? AND state != 0 LIMIT 1");
            $st->execute([$uname]);
            return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) { return 0; }
    }
}

/** 這個人在 module='attach_dl' 底下，有沒有被指派過任何角色（不論角色內容是什麼） */
if (!function_exists('eg_adp_user_has_any_role')) {
    function eg_adp_user_has_any_role(PDO $db, int $uid): bool {
        try {
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module=? LIMIT 1");
            $st->execute([$uid, EG_ADP_RBAC_MODULE]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

/**
 * 下載權限主判定：$isPageAdmin（本頁等效全權，如 master_data 的 $is_admin||$_mdRbacAll，
 * 或全站系統管理員）一律放行；否則 fail-open——這個人完全沒被指派過 attach_dl 模組任何
 * 角色時放行（過渡期相容，見檔頭說明）；已被指派過的人才嚴格依四個功能碼勾選與否判斷。
 */
if (!function_exists('eg_adp_user_can')) {
    function eg_adp_user_can(PDO $db, int $uid, bool $isPageAdmin, bool $is3d, bool $isCustomer): bool {
        $m = eg_adp_user_matrix($db, $uid, $isPageAdmin);
        if (empty($m['enforced'])) return true;
        return !empty($m['codes'][eg_adp_class_code($is3d, $isCustomer)]);
    }
}

/**
 * 一次算完某使用者的下載權限矩陣（給「一個畫面要判一整批附件」的呼叫端用）。
 * eg_adp_user_can() 每呼叫一次就查一次 RBAC，檔案清單動輒幾十筆，逐筆呼叫＝N+1。
 * 回傳 ['enforced'=>bool, 'codes'=>[功能碼=>true]]：enforced=false 代表這個人完全不受限
 * （管理員，或從未被指派過 attach_dl 角色的 fail-open 過渡期對象），codes 此時沒有意義。
 * 判定規則與 eg_adp_user_can() 必須一致——這裡是它的批次版，不是另一套規則（鐵律4）。
 */
if (!function_exists('eg_adp_user_matrix')) {
    function eg_adp_user_matrix(PDO $db, int $uid, bool $isPageAdmin): array {
        if ($isPageAdmin) return ['enforced' => false, 'codes' => []];
        $uid = eg_adp_resolve_uid($db, $uid);
        require_once __DIR__ . '/rbac.php';
        $feats = [];
        try { $feats = rbac_user_features($db, $uid); } catch (Throwable $e) {}
        if (in_array('all', $feats, true)) return ['enforced' => false, 'codes' => []];
        $codes = [];
        foreach (['dl_2d_customer','dl_2d_internal','dl_3d_customer','dl_3d_internal'] as $c) {
            if (in_array($c, $feats, true)) $codes[$c] = true;
        }
        return ['enforced' => true, 'codes' => $codes];
    }
}

/**
 * 用上面算好的矩陣判單一檔案能不能下載（純運算，不再查 DB）。
 * $isCustomer 傳 null＝呼叫端拿不到標籤（例如 BOM 掃描圖那種沒有附件紀錄的檔案），
 * 一律當公司內部——與 eg_adp_compute_is_customer() 對「沒有任何客供標籤」的結論相同。
 */
if (!function_exists('eg_adp_matrix_allow')) {
    function eg_adp_matrix_allow(array $matrix, string $filename, ?bool $isCustomer): bool {
        if (empty($matrix['enforced'])) return true;
        return !empty($matrix['codes'][eg_adp_class_code(eg_adp_is_3d_ext($filename), (bool)$isCustomer)]);
    }
}

/**
 * 下載動作的一次性完整檢查（給三支附件 API 的 download 分支呼叫）。
 * 只有在真的要「另存/下載」（呼叫端已自行判斷 $_GET['dl'] 為真）時才需要呼叫本函式；
 * 一般內嵌預覽完全不要呼叫。回傳 ['ok'=>bool,'msg'=>string,'class_label'=>string]。
 */
if (!function_exists('eg_adp_gate_download')) {
    function eg_adp_gate_download(PDO $db, int $uid, bool $isPageAdmin, string $filename, ?string $catIdsStr): array {
        eg_adp_ensure_schema($db);
        $is3d = eg_adp_is_3d_ext($filename);
        $isCustomer = eg_adp_compute_is_customer($db, $catIdsStr);
        $label = eg_adp_class_label($is3d, $isCustomer);
        if (!eg_adp_user_can($db, $uid, $isPageAdmin, $is3d, $isCustomer)) {
            return ['ok' => false, 'msg' => "您沒有下載「{$label}」檔案的權限，請洽主檔管理頁管理員指派權限", 'class_label' => $label];
        }
        return ['ok' => true, 'msg' => '', 'class_label' => $label];
    }
}

/* ══════════════════════════════════════════════════════════════════════════
 * 分類鎖定：改標籤時若牽動「客供／內部」這個分類本身，要先解鎖才准存檔
 * ══════════════════════════════════════════════════════════════════════════ */

/**
 * 上傳當下決定初始分類（沒有鎖定問題，因為這是這份檔案第一次分類，沒有「原本的值」可違反）。
 * @return int 0 或 1，直接存進新表的 dl_is_customer 欄位
 */
if (!function_exists('eg_adp_initial_is_customer')) {
    function eg_adp_initial_is_customer(PDO $db, ?string $catIdsStr): int {
        eg_adp_ensure_schema($db);
        return eg_adp_compute_is_customer($db, $catIdsStr) ? 1 : 0;
    }
}

/**
 * 改標籤（update_meta/update_attachment）時呼叫：比較「改完之後」算出來的分類跟目前鎖住
 * 的 $curIsCustomer 是否相同；不同就必須本人已解鎖才放行，放行後回傳新的分類值供呼叫端
 * UPDATE 回 dl_is_customer（等於重新鎖定在新值上，下一次要再改又要重新解鎖）。
 * $curIsCustomer 傳 null 代表這筆資料還沒分類過（舊資料剛好這次被改到）——直接按新值
 * 分類、不視為「變更」，不需要解鎖（跟上傳時一樣是「第一次決定」）。
 *
 * @return array ['ok'=>bool,'msg'=>string,'new_is_customer'=>int|null，'changed'=>bool]
 *   $ok=false 時 $new_is_customer 為 null，呼叫端應整個擋下這次存檔（不要只擋分類那一截、
 *   其餘標籤照存——鎖定的意義就是「這次異動完全不算數」，才不會被拆成兩步繞過）。
 */
if (!function_exists('eg_adp_check_classification_lock')) {
    function eg_adp_check_classification_lock(PDO $db, int $uid, ?int $curIsCustomer, ?string $newCatIdsStr): array {
        eg_adp_ensure_schema($db);
        $newVal = eg_adp_compute_is_customer($db, $newCatIdsStr) ? 1 : 0;
        if ($curIsCustomer === null) {
            return ['ok' => true, 'msg' => '', 'new_is_customer' => $newVal, 'changed' => false];
        }
        if ($newVal === $curIsCustomer) {
            return ['ok' => true, 'msg' => '', 'new_is_customer' => $newVal, 'changed' => false];
        }
        if (!eg_adp_unlock_valid($uid)) {
            $fromLbl = $curIsCustomer ? '客戶提供' : '公司內部';
            $toLbl   = $newVal ? '客戶提供' : '公司內部';
            return ['ok' => false, 'msg' => "此附件的下載分類（{$fromLbl}）已鎖定，您正在把它改成「{$toLbl}」——請先點「解鎖下載分類」並輸入本頁管理員的確認密碼才能變更（10分鐘內有效）", 'new_is_customer' => null, 'changed' => true];
        }
        return ['ok' => true, 'msg' => '', 'new_is_customer' => $newVal, 'changed' => true];
    }
}

/* ── 解鎖狀態（session，per 使用者，10 分鐘，比照 order_track_perm_lib 的 ot_client_unlock_*）── */

if (!function_exists('eg_adp_unlock_mark')) {
    function eg_adp_unlock_mark(int $uid): void {
        if (!isset($_SESSION['adp_unlock']) || !is_array($_SESSION['adp_unlock'])) $_SESSION['adp_unlock'] = [];
        $_SESSION['adp_unlock'][$uid] = time();
    }
}
if (!function_exists('eg_adp_unlock_valid')) {
    function eg_adp_unlock_valid(int $uid): bool {
        $t = $_SESSION['adp_unlock'][$uid] ?? 0;
        if (!$t) return false;
        if (time() - (int)$t > EG_ADP_UNLOCK_TTL) { unset($_SESSION['adp_unlock'][$uid]); return false; }
        return true;
    }
}
if (!function_exists('eg_adp_unlock_remaining')) {
    /** 還剩幾秒，0＝未解鎖或已過期 */
    function eg_adp_unlock_remaining(int $uid): int {
        $t = $_SESSION['adp_unlock'][$uid] ?? 0;
        if (!$t) return 0;
        $left = EG_ADP_UNLOCK_TTL - (time() - (int)$t);
        if ($left <= 0) { unset($_SESSION['adp_unlock'][$uid]); return 0; }
        return $left;
    }
}
if (!function_exists('eg_adp_unlock_clear')) {
    function eg_adp_unlock_clear(int $uid): void { unset($_SESSION['adp_unlock'][$uid]); }
}

/**
 * 本頁管理員（master_data 的 $is_admin||$_mdRbacAll，或全站系統管理員）第一次要解鎖時，
 * 自動取得操作確認密碼的使用資格（不需超管再一個個手動白名單加）——這是使用者本輪
 * 明確要求的行為：「目前各頁面的本頁管理員沒有設定操作密碼的權限，應該要求各頁面的
 * 本頁管理員設定確認密碼」。只授權資格、不代填密碼，該管理員仍要自己去設一次密碼。
 */
if (!function_exists('eg_adp_auto_grant_page_admin')) {
    function eg_adp_auto_grant_page_admin(PDO $db, int $uid, string $byLabel): void {
        require_once __DIR__ . '/confirm_password_lib.php';
        if (!eg_confirm_password_allowed($db, $uid)) {
            eg_confirm_password_grant($db, $uid, $byLabel);
        }
    }
}
