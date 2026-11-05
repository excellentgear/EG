<?php
/**
 * ir_track_lib.php — 退貨追蹤（IR Track）共用判定
 * 建立：2026-09-21
 *
 * 頁面（views/Sales/IR_Track.php）與 API（src/store/store_IR_Track_API.php）共用同一份：
 * 管理員判定、年度清單、清單欄位／索引確保、異常單綁定的「單號備援」。
 * 兩邊各寫一份規則遲早走鐘，故收斂在這裡（鐵律4）。
 */
/* ─────────────────────────────────────────────────────────────
   退貨清單：欄位／索引確保、年度、管理員判定、異常單綁定的單號備援
   ───────────────────────────────────────────────────────────── */

/** 清單用得到的欄位與索引（可重複執行；原本散在 get_ir_list 裡，年度下拉與自動結案也要用） */
function irEnsureListSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ir_return_type` (
        `type_id` tinyint NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `type_name` varchar(30) NOT NULL,
        `is_note` tinyint(1) NOT NULL DEFAULT 0 COMMENT '備註模式',
        `allow_ncr` tinyint(1) NOT NULL DEFAULT 1 COMMENT '允許開立異常單',
        `sort_order` int NOT NULL DEFAULT 0,
        `is_active` tinyint(1) NOT NULL DEFAULT 1,
        `description` varchar(100) NULL
    ) DEFAULT CHARSET=utf8mb4 COMMENT='退貨性質設定表'");
    try { $pdo->query("SELECT return_type_id FROM ir_track LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE ir_track ADD COLUMN `return_type_id` tinyint NULL COMMENT '退貨性質 FK→ir_return_type.type_id'"); }
    try { $pdo->query("SELECT sale_assignee FROM ir_track LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE ir_track ADD COLUMN `sale_assignee` int NULL COMMENT '負責業務 FK→user.id'"); }

    // 年度篩選與清單排序都吃 IR_date，原本一個索引都沒有
    try {
        $has = false;
        foreach ($pdo->query("SHOW INDEX FROM ir_track")->fetchAll(PDO::FETCH_ASSOC) as $ix) {
            if ($ix['Key_name'] === 'idx_ir_track_date') { $has = true; break; }
        }
        if (!$has) $pdo->exec("ALTER TABLE ir_track ADD INDEX `idx_ir_track_date` (`IR_date`, `IR_id`)");
    } catch (Exception $e) { /* 建不起來就算了，3 千筆全掃也還好 */ }
}

/** 年度下拉：只列真的有資料的年度（由新到舊） */
function irYears(PDO $pdo): array {
    $ys = [];
    foreach ($pdo->query("SELECT DISTINCT YEAR(IR_date) y FROM ir_track WHERE IR_date IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN) as $y) {
        if ((int)$y > 0) $ys[] = (int)$y;
    }
    return $ys;
}

/**
 * 「期間自動結案」是會一次改掉幾千筆的動作，限系統管理者。
 * 本頁目前沒有自己的 RBAC 模組，所以先認系統管理者；另外留一個 `ir_track_admin`
 * 功能碼，日後要授權給業務主管時在角色設定加一個功能碼即可，不必再動程式。
 */
function irIsAdmin(PDO $pdo, int $uid): bool {
    if ($uid <= 0) return false;
    if ($uid === 1) return true;
    try {
        $st = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                             WHERE ur.user_id = ? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        if ($st->fetchColumn()) return true;
        $st = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id
                             WHERE ur.user_id = ? AND rf.feature_code = 'ir_track_admin' LIMIT 1");
        $st->execute([$uid]);
        if ($st->fetchColumn()) return true;
    } catch (Exception $e) { /* 表不在就只認 id=1 */ }
    return false;
}

/**
 * 「孤兒」異常單：ir_id/source_id 指到的那一列客退單已經不存在了
 * （ERP 重新匯入客退單會整批換一組 IR_id，2026-09-21 使用者回報就是這個情形）。
 * 回傳 [IR_no => [單, 單…]]，讓清單能以單號把綁定顯示回來。
 * 實際上只會有很少幾筆，所以一次全撈起來比在 SQL 裡硬接一個 JOIN 乾淨得多。
 */
function irOrphanQaByIrNo(PDO $pdo): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    try {
        $rows = $pdo->query("SELECT o.id, o.abnormal_order_no, o.ir_no, o.part_no, o.part_d_id
                             FROM qa_abnormal_order o
                             WHERE o.deleted_at IS NULL AND o.ir_no IS NOT NULL AND o.ir_no <> ''
                               AND NOT EXISTS (
                                   SELECT 1 FROM ir_track i
                                   WHERE i.IR_id = COALESCE(NULLIF(o.ir_id,0), IF(o.source_type='IR', o.source_id, NULL)))
                             ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $map[(string)$r['ir_no']][] = $r;

        /* 先問清楚「這張異常單的料號，在這個單號底下對不對得到某一筆明細」。
           對得到就只掛在那一筆；對不到（或根本沒填料號）才整個單號都掛上去並標成推測。
           不先算這一步的話，對得到的那一筆之外的明細也會一起長出同一張單。 */
        $q = $pdo->prepare("SELECT t.d_id, t.d_setting_id, ds.D_Setting_Id
                            FROM ir_track t
                            LEFT JOIN d_setting ds ON ds.d_id = COALESCE(t.d_setting_id, IF(t.d_id REGEXP '^[0-9]+$', CAST(t.d_id AS UNSIGNED), NULL))
                            WHERE t.IR_no = ?");
        foreach ($map as $irNo => $cands) {
            $q->execute([$irNo]);
            $keys = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
                foreach ([$t['d_id'], $t['d_setting_id'], $t['D_Setting_Id']] as $k) {
                    $k = trim((string)$k);
                    if ($k !== '') $keys[$k] = true;
                }
            }
            foreach ($map[$irNo] as $i => $c) {
                $p  = trim((string)($c['part_no'] ?? ''));
                $pd = trim((string)($c['part_d_id'] ?? ''));
                $map[$irNo][$i]['_part_hit'] = (($p !== '' && isset($keys[$p])) || ($pd !== '' && isset($keys[$pd]))) ? 1 : 0;
            }
        }
    } catch (Exception $e) { $map = []; }
    return $map;
}

/**
 * 同一個 IR 單號常常有好幾筆明細，所以先用料號挑出「就是這一筆」；
 * 料號對得上才是準的（回傳 _exact=1），完全比不到時才退回「這個單號的第一張」，
 * 前端會標明那是用單號推測的。
 */
function irPickOrphanQa(array $cands, array $row): ?array {
    $keys = [];
    foreach ([$row['raw_d_id'] ?? null, $row['raw_d_setting_id'] ?? null, $row['d_id'] ?? null] as $k) {
        $k = trim((string)$k);
        if ($k !== '') $keys[$k] = true;
    }
    foreach ($cands as $c) {
        $p  = trim((string)($c['part_no'] ?? ''));
        $pd = trim((string)($c['part_d_id'] ?? ''));
        if (($p !== '' && isset($keys[$p])) || ($pd !== '' && isset($keys[$pd]))) {
            $c['_exact'] = 1;
            return $c;
        }
    }
    /* 料號對不上這一筆明細。它的料號在這個單號底下有對到別筆（_part_hit=1）的就別再掛過來，
       那是別一筆的異常單；完全對不到任何一筆的才整個單號掛上去並標成推測。 */
    $loose = array_values(array_filter($cands, function ($c) { return empty($c['_part_hit']); }));
    if (count($loose) === 1) { $loose[0]['_exact'] = 0; return $loose[0]; }
    return null;
}


/* ─────────────────────────────────────────────────────────────
   角色與功能（2026-11-05）：module = 'ir_track'
   頁面進入檢查、API 動作守門、角色設定畫面共用同一份（鐵律4／8）。
   ───────────────────────────────────────────────────────────── */

/** 模組代碼（roles.module） */
const IR_ROLE_MODULE = 'ir_track';

/** 本頁功能碼（唯一登記處）：code => [名稱, 說明] */
function irFeatures(): array {
    return [
        'ir_track_view'     => ['檢閱', '進入頁面、看退貨清單／明細／統計／進度回覆與附件（唯讀）'],
        'ir_track_edit'     => ['新增與修改退貨單', '新增退貨單、改狀態／負責業務／退貨性質、進度回覆、上傳與刪除附件'],
        'ir_track_ncr'      => ['開立與處理異常單', '舊版異常單流程（開立、編輯、送交、重送）；新版品質異常處理單的開單權限仍看該模組自己的角色'],
        'ir_track_settings' => ['設定', '退貨性質、異常種類、異常原因分類、流程與附件路徑設定'],
        'ir_track_admin'    => ['管理（期間自動結案）', '一次結案某一段期間的退貨單，每次套用都留稽核紀錄'],
    ];
}

/**
 * 預設角色（第一次載入頁面時建立；之後管理員可在「設定→角色與功能」自行增修，不會被覆蓋）。
 * 只在「這個模組完全沒有任何角色」時才建；管理員自己建立或改過的角色一律不動。
 */
function irEnsureRoles(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM roles WHERE module = '" . IR_ROLE_MODULE . "'")->fetchColumn();
        if ($n > 0) return;
        $defs = [
            ['ir_track_role_view',  '退貨追蹤檢閱',   ['ir_track_view']],
            ['ir_track_role_edit',  '退貨追蹤編輯',   ['ir_track_view', 'ir_track_edit', 'ir_track_ncr']],
            ['ir_track_role_admin', '退貨追蹤管理員', array_keys(irFeatures())],
        ];
        $insR = $pdo->prepare("INSERT INTO roles (role_code, role_name, is_system, module) VALUES (?,?,0,?)");
        $insF = $pdo->prepare("INSERT IGNORE INTO role_features (role_id, feature_code) VALUES (?,?)");
        foreach ($defs as [$code, $name, $feats]) {
            $insR->execute([$code, $name, IR_ROLE_MODULE]);
            $rid = (int)$pdo->lastInsertId();
            foreach ($feats as $f) $insF->execute([$rid, $f]);
        }
    } catch (Throwable $e) { /* roles 表不在等情況：靜默，perms 會走過渡期放行 */ }
}

/**
 * 過渡期判定：還沒有任何人（個人指派或部門職稱指派）被指派本模組的角色 → 維持原本「人人可用」，
 * 避免一上線就把現場鎖在門外。第一個角色被指派之後就改以角色為準。
 */
function irRolesInUse(PDO $pdo): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id WHERE r.module = ? LIMIT 1");
        $st->execute([IR_ROLE_MODULE]);
        if ($st->fetchColumn()) return true;
        $st = $pdo->prepare("SELECT 1 FROM position_roles pr JOIN roles r ON r.role_id = pr.role_id WHERE r.module = ? LIMIT 1");
        $st->execute([IR_ROLE_MODULE]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 這個人在退貨追蹤能做什麼。
 *   isAdmin    ＝全站管理員（id=1／系統管理員角色）
 *   canAdmin   ＝本頁管理員（有 ir_track_admin 功能碼，或全站管理員）
 *   legacy     ＝過渡期（尚未有人被指派本模組角色）
 */
function irPerms(PDO $pdo, int $uid): array {
    irEnsureRoles($pdo);
    $none = ['isAdmin'=>false,'canAdmin'=>false,'canView'=>false,'canEdit'=>false,'canNcr'=>false,'canSettings'=>false,'legacy'=>false,'features'=>[]];
    if ($uid <= 0) return $none;
    // 全站管理員（id=1／系統管理員角色）：連「角色與功能」設定都能改；
    // 本頁管理員（有 ir_track_admin 功能碼）：本頁全部功能，但不能改角色（Roles_API 只認全站管理員）
    $isSuper = ($uid === 1);
    if (!$isSuper) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = ? AND r.role_code IN ('admin','superadmin') LIMIT 1");
            $st->execute([$uid]);
            $isSuper = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($isSuper) {
        return ['isAdmin'=>true,'canAdmin'=>true,'canView'=>true,'canEdit'=>true,'canNcr'=>true,'canSettings'=>true,'legacy'=>false,'features'=>['all']];
    }
    if (irIsAdmin($pdo, $uid)) {
        return ['isAdmin'=>false,'canAdmin'=>true,'canView'=>true,'canEdit'=>true,'canNcr'=>true,'canSettings'=>true,'legacy'=>false,'features'=>['ir_track_admin']];
    }
    if (!irRolesInUse($pdo)) {
        // 過渡期：維持上線前的行為（人人可進、可操作；期間自動結案本來就限管理員）
        return ['isAdmin'=>false,'canAdmin'=>false,'canView'=>true,'canEdit'=>true,'canNcr'=>true,'canSettings'=>true,'legacy'=>true,'features'=>[]];
    }
    $feats = [];
    try {
        require_once __DIR__ . '/role_features_helper.php';
        $feats = rf_load_user_features($pdo, $uid);
    } catch (Throwable $e) {}
    $has = function (string $c) use ($feats) { return in_array('all', $feats, true) || in_array($c, $feats, true); };
    $admin = $has('ir_track_admin');
    return [
        'isAdmin'     => false,
        'canAdmin'    => $admin,
        'canView'     => $admin || $has('ir_track_view')     || $has('ir_track_edit') || $has('ir_track_ncr') || $has('ir_track_settings'),
        'canEdit'     => $admin || $has('ir_track_edit'),
        'canNcr'      => $admin || $has('ir_track_ncr'),
        'canSettings' => $admin || $has('ir_track_settings'),
        'legacy'      => false,
        'features'    => $feats,
    ];
}

/**
 * API 動作所需的權限（唯一登記處；沒登記的動作一律要求「檢閱」，寧可嚴不要漏）。
 * 回傳 view／edit／ncr／settings／admin。
 */
function irActionNeed(string $action, string $sub = ''): string {
    static $map = [
        // 新增、修改退貨單與業務進度
        'save_ir'=>'edit','update_ir_status'=>'edit','update_ir_assignee'=>'edit','update_progress'=>'edit',
        'update_ir_return_type'=>'edit','save_progress_note'=>'edit','delete_progress_note'=>'edit',
        'upload_ir_attachment'=>'edit','upload_note_attachment'=>'edit','delete_ir_attachment'=>'edit',
        // 異常單（舊流程）
        'create_ncr'=>'ncr','update_ncr_full'=>'ncr','update_ncr_flow'=>'ncr','resend_ncr_flow'=>'ncr',
        // 設定
        'save_flow_config'=>'settings','save_config'=>'settings','ir_cause_save'=>'settings',
        'save_ir_return_type'=>'settings','delete_ir_return_type'=>'settings',
        // 期間自動結案
        'auto_close_preview'=>'admin','auto_close_apply'=>'admin',
    ];
    if ($action === 'manage_abnormal_type') return ($sub === 'get_all') ? 'view' : 'settings';
    return $map[$action] ?? 'view';
}

/** 依 irActionNeed 的結果判斷這個人行不行 */
function irCan(array $perms, string $need): bool {
    switch ($need) {
        case 'admin':    return !empty($perms['canAdmin']);
        case 'settings': return !empty($perms['canSettings']);
        case 'ncr':      return !empty($perms['canNcr']);
        case 'edit':     return !empty($perms['canEdit']);
        default:         return !empty($perms['canView']);
    }
}
