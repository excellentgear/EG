<?php
// quotation_list_NEW.php — 報價單管理（快速版）
session_start();
if (!isset($_SESSION['userName'])) { header("Location:../../index.php"); exit; }
include '../../src/common/DBConnection.php';
$conn = new DBConnection();
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// ── RBAC 初始化 ──────────────────────────────────────────────────────────
$_pdo     = $conn->getPDO();
$_user_id = intval($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);

try {
    // 建立全域 RBAC 資料表（若不存在）
    $_pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        role_id   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        role_code VARCHAR(30) NOT NULL UNIQUE,
        role_name VARCHAR(50) NOT NULL,
        is_system TINYINT NOT NULL DEFAULT 0,
        note      VARCHAR(200),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_pdo->exec("CREATE TABLE IF NOT EXISTS role_features (
        role_id      INT NOT NULL,
        feature_code VARCHAR(60) NOT NULL,
        PRIMARY KEY (role_id, feature_code),
        INDEX idx_rf_role (role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        PRIMARY KEY (user_id, role_id),
        INDEX idx_ur_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $_pdo->exec("CREATE TABLE IF NOT EXISTS quotation_print_log (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        quote_no VARCHAR(30) NOT NULL,
        printed_by INT NOT NULL,
        printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_qid (quote_id),
        INDEX idx_printed_at (printed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 植入管理員角色（若不存在）
    $_pdo->exec("INSERT IGNORE INTO roles (role_code,role_name,is_system) VALUES ('admin','管理員',1)");
    $_adminRId = $_pdo->query("SELECT role_id FROM roles WHERE role_code='admin' LIMIT 1")->fetchColumn();
    if ($_adminRId) {
        $_pdo->prepare("INSERT IGNORE INTO role_features (role_id,feature_code) VALUES (?,?)")->execute([$_adminRId,'all']);
    }
} catch(Exception $_e) {}

// ── 取得使用者的 features ─────────────────────────────────────────────────
$_features   = [];
$_my_roles   = [];
$_has_roles  = false; // 此使用者是否已在新系統中指派角色

try {
    // 先確認使用者是否有指派任何角色
    $_chkRole = $_pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? LIMIT 1");
    $_chkRole->execute([$_user_id]);
    $_has_roles = (bool)$_chkRole->fetchColumn();

    if ($_has_roles) {
        // 已指派角色 → 讀取角色對應的 features（若角色無勾選任何功能，$_features 維持空陣列）
        $stmtUR = $_pdo->prepare("
            SELECT DISTINCT r.role_name, rf.feature_code
            FROM user_roles ur
            JOIN roles r ON r.role_id=ur.role_id
            JOIN role_features rf ON rf.role_id=ur.role_id
            WHERE ur.user_id=?
        ");
        $stmtUR->execute([$_user_id]);
        foreach ($stmtUR->fetchAll(PDO::FETCH_ASSOC) as $_row) {
            $_features[] = $_row['feature_code'];
            // 只把「對本頁(報價單)有權限」的角色列為本頁角色，避免把其他模組的角色(如批圖使用者/一般使用者)也顯示進權限說明
            if ($_row['feature_code']==='all' || strpos((string)$_row['feature_code'],'quotation_')===0) {
                $_my_roles[] = $_row['role_name'];
            }
        }
        $_features = array_unique($_features);
        $_my_roles = array_unique($_my_roles);
        // 後備：若上面沒抓到任何「本頁相關」角色，退而取仍具本頁權限的角色名（仍限報價單，避免帶入他頁角色）
        if (empty($_my_roles)) {
            $stmtRN = $_pdo->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id JOIN role_features rf ON rf.role_id=ur.role_id WHERE ur.user_id=? AND (rf.feature_code='all' OR rf.feature_code LIKE 'quotation%')");
            $stmtRN->execute([$_user_id]);
            $_my_roles = $stmtRN->fetchAll(PDO::FETCH_COLUMN);
        }
    }
} catch(Exception $_e) {}

// Fallback：只有「完全未指派角色」才走舊系統 / 預設全權
// 已指派角色但無功能 → 維持空 features（受限訪問），不觸發 fallback
if (!$_has_roles) {
    try {
        $_sp2 = $_pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND module_code='quotation_list' LIMIT 1");
        $_sp2->execute([$_user_id]);
        $_p2 = $_sp2->fetchColumn();
        if ($_p2) {
            if (strpos($_p2,'A')!==false) { $_features=['all']; }
            else {
                if (strpos($_p2,'R')!==false) { $_features[]='quotation_view'; $_features[]='quotation_print'; }
                if (strpos($_p2,'C')!==false) { $_features[]='quotation_create'; $_features[]='quotation_clone'; $_features[]='quotation_batch_add'; }
                if (strpos($_p2,'U')!==false) { $_features[]='quotation_edit'; $_features[]='quotation_clone'; $_features[]='quotation_view_history'; }
                if (strpos($_p2,'D')!==false) { $_features[]='quotation_delete'; $_features[]='quotation_view_deleted'; }
            }
        }
    } catch(Exception $_e2) {}

    if (empty($_features)) $_features = ['all']; // 完全無設定（新舊系統皆無）→ 全權避免鎖死
}

function _hasF(string $f): bool {
    global $_features;
    return in_array('all',$_features,true) || in_array($f,$_features,true);
}

$CAN_VIEW         = _hasF('quotation_view');
$CAN_CREATE       = _hasF('quotation_create');
$CAN_EDIT         = _hasF('quotation_edit');
$CAN_DELETE       = _hasF('quotation_delete');
$CAN_PRINT        = _hasF('quotation_print');
$CAN_CLONE        = _hasF('quotation_clone');
$CAN_BATCH_ADD    = _hasF('quotation_batch_add');
$CAN_SIGN         = _hasF('quotation_sign');
$CAN_VIEW_DELETED = _hasF('quotation_view_deleted');
$CAN_RESTORE      = _hasF('quotation_restore');
$CAN_VIEW_HISTORY = _hasF('quotation_view_history');
$CAN_SETTINGS     = _hasF('quotation_settings');
$CAN_SORT_RULE    = _hasF('quotation_sort_rule');
$CAN_CHG_CUSTOMER = _hasF('quotation_change_customer');   // 整張單變更客戶（含料號綁定的客戶）
$IS_ADMIN         = _hasF('all');
$_perm            = $IS_ADMIN ? 'A（管理員）' : (empty($_my_roles) ? '（未指派角色）' : implode('、',$_my_roles));

// 具「簽核報價單」權限者即使沒有一般檢視權限，也必須能進本頁處理待簽核通知（比照CAR當事人例外）
if (!$CAN_VIEW && !$CAN_SIGN) { header('HTTP/1.1 403 Forbidden'); echo '您沒有瀏覽此頁面的權限。'; exit; }

// ── 本頁功能清單（供設定頁籤使用）────────────────────────────────────────
$PAGE_FEATURES = [
    ['group'=>'基本操作', 'code'=>'quotation_view',         'label'=>'檢視報價單'],
    ['group'=>'基本操作', 'code'=>'quotation_create',       'label'=>'新增報價單'],
    ['group'=>'基本操作', 'code'=>'quotation_edit',         'label'=>'編輯報價單'],
    ['group'=>'基本操作', 'code'=>'quotation_delete',       'label'=>'刪除報價單'],
    ['group'=>'基本操作', 'code'=>'quotation_print',        'label'=>'列印'],
    ['group'=>'基本操作', 'code'=>'quotation_clone',        'label'=>'複製報價單'],
    ['group'=>'基本操作', 'code'=>'quotation_batch_add',    'label'=>'批次新增料號'],
    ['group'=>'基本操作', 'code'=>'quotation_sign',         'label'=>'簽核報價單'],
    ['group'=>'進階功能', 'code'=>'quotation_view_deleted', 'label'=>'查看已刪除紀錄'],
    ['group'=>'進階功能', 'code'=>'quotation_restore',      'label'=>'還原已刪除報價單'],
    ['group'=>'進階功能', 'code'=>'quotation_view_history', 'label'=>'查看修改紀錄'],
    ['group'=>'進階功能', 'code'=>'quotation_settings',     'label'=>'報價單設定'],
    ['group'=>'進階功能', 'code'=>'quotation_sort_rule',    'label'=>'調整項目自動排序規則（全體適用）'],
    ['group'=>'進階功能', 'code'=>'quotation_change_customer', 'label'=>'整張單變更客戶（含料號綁定的客戶、連動OP轉出的訂單/BOM）'],
];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>報價單管理（快速版）</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
:root {
    --primary: #2A3F54; --accent: #1ABB9C; --bg: #F4F7FC;
    --card: #FFFFFF;    --text: #495057;   --border: #E6E9ED;
}
/* ── 去掉 number input 上下箭頭 ── */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
input[type=number] { -moz-appearance:textfield; appearance:textfield; }

body { background:var(--bg); }
.right_col { background:var(--bg) !important; padding:0 !important; }

/* ── 標題列 ── */
.page-title-bar {
    padding:10px 18px; border-bottom:1px solid var(--border);
    background:var(--card); display:flex; align-items:center;
    justify-content:space-between; flex-shrink:0;
}
.page-title-bar h3 { margin:0; font-size:17px; color:var(--primary); font-weight:700; }

/* ── 分割版面（高度由 JS adjustLayout 動態設定，消除外層滾動條）── */
.split-wrap { display:flex; overflow:hidden; min-height:400px; }

/* ── 左側列表 ── */
.list-panel {
    width:310px; min-width:260px; max-width:340px;
    border-right:1px solid var(--border); display:flex;
    flex-direction:column; background:var(--card); overflow:hidden;
    flex-shrink:0;
}
.list-stats {
    display:flex; gap:14px; padding:8px 12px;
    border-bottom:1px solid var(--border); background:#f8f9fa;
    flex-shrink:0;
}
.stat-chip { text-align:center; }
.stat-chip-val { font-size:17px; font-weight:700; color:var(--primary); line-height:1.2; display:block; }
.stat-chip-lbl { font-size:10px; color:#aaa; }
.list-toolbar {
    padding:8px 10px; border-bottom:1px solid var(--border);
    background:#fafafa; flex-shrink:0; display:flex; gap:6px; align-items:center;
}
.list-search { padding:7px 10px; border-bottom:1px solid var(--border); flex-shrink:0; }
#quoteListBody { flex:1; overflow-y:auto; }

/* ── 報價單卡片 ── */
.qli-card {
    padding:9px 12px; border-bottom:1px solid var(--border);
    cursor:pointer; transition:background .1s;
}
.qli-card:hover { background:#f0f7ff; }
.qli-card.active { background:#e8f0ff; border-left:3px solid var(--primary); padding-left:9px; }
.qli-no { font-weight:700; font-size:13px; color:var(--primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.qli-client { font-size:11px; color:#666; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.qli-foot { display:flex; justify-content:space-between; margin-top:3px; font-size:11px; }
.qli-date { color:#bbb; }

/* ── 客戶分組標頭 ── */
.qli-group-hdr {
    padding:5px 12px 3px;
    background:#f0f4f8;
    font-size:11px; font-weight:700; color:var(--primary);
    border-bottom:1px solid var(--border);
    border-top:1px solid var(--border);
    letter-spacing:.3px;
    cursor:pointer;
    display:flex; justify-content:space-between; align-items:center;
    user-select:none;
}
.qli-group-hdr:hover { background:#e5edf5; }
.qli-group-hdr .qg-toggle { font-size:10px; color:#aaa; }
/* 收合後隱藏卡片 */
.qli-group-body.collapsed { display:none; }
.qli-amt  { color:var(--accent); font-weight:600; }

/* ── 右側編輯區 ── */
.editor-wrap {
    flex:1; overflow:hidden; display:flex; flex-direction:column;
}
.editor-panel { flex:1; overflow:hidden; padding:0; display:none; flex-direction:column; }
.editor-empty {
    flex:1; display:flex; flex-direction:column;
    align-items:center; justify-content:center; color:#ccc;
}
.editor-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 22px 10px; border-bottom:2px solid var(--accent);
    background:#fff; flex-shrink:0;
    position:sticky; top:0; z-index:20;
}
.editor-header h4 { margin:0; font-size:15px; color:var(--primary); font-weight:700; }
.editor-client-tag { margin-left:10px; font-size:12px; color:#666; font-weight:400; }

/* ── 附件區 ── */
.file-section {
    background:#f8f9fa; border:1px solid var(--border); border-radius:6px;
    padding:10px 14px; margin-bottom:14px;
}
.file-section-title {
    font-size:12px; font-weight:700; color:var(--primary);
    margin-bottom:7px; display:flex; align-items:center; gap:6px;
}
.file-drop-zone {
    border:2px dashed #ccc; border-radius:5px; padding:14px;
    text-align:center; cursor:pointer; background:#fff;
    transition:border-color .2s, background .2s; font-size:12px; color:#aaa;
}
.file-drop-zone:hover, .file-drop-zone.drag-over {
    border-color:var(--accent); background:#f0fef9; color:var(--accent);
}
.file-item-wrap {
    border-bottom:1px solid #f0f0f0; margin-bottom:1px;
}
.file-item-wrap:last-child { border-bottom:none; }
.file-item {
    display:flex; align-items:center; gap:7px;
    padding:4px 2px; font-size:12px;
}
.file-item-name { flex:1; color:#333; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; cursor:pointer; }
.file-item-name:hover { color:var(--accent); text-decoration:underline; }
.file-item-size { color:#bbb; white-space:nowrap; font-size:11px; }
.file-item-time { color:#bbb; white-space:nowrap; font-size:11px; }

/* ── 類別標籤整合在 🏷 按鈕內 ── */
.file-tag-toggle-btn.has-cat {
    background:#e8f0ff !important; color:#2A3F54 !important;
    border-color:#b0c4f0 !important; font-weight:600;
}

/* ── 展開標籤面板 ── */
.file-tag-panel {
    padding:7px 10px 8px 26px;
    background:#f8f9fa; border-top:1px solid var(--border);
    font-size:12px;
}
.ftp-row { display:flex; align-items:flex-start; gap:6px; margin-bottom:5px; flex-wrap:wrap; }
.ftp-row:last-child { margin-bottom:0; }
.ftp-label { color:#888; font-size:11px; white-space:nowrap; padding-top:2px; min-width:52px; }
.ftp-btns  { display:flex; flex-wrap:wrap; gap:4px; }
.file-cat-btn  { font-size:11px; padding:1px 8px; }
.file-cat-btn.active  { background:var(--primary); color:#fff; border-color:var(--primary); }
.file-part-btn { font-size:11px; padding:1px 8px; }
.file-part-btn.active { background:#1ABB9C; color:#fff; border-color:#1ABB9C; }
.file-parts-all { font-size:11px; padding:1px 8px; }
.file-parts-all.active { background:#27ae60; color:#fff; border-color:#27ae60; }

/* ── 行內製程選擇 ── */
.proc-tags { display:flex; flex-wrap:wrap; gap:3px; min-height:22px; align-items:flex-start; padding:2px 0; }
.proc-tag {
    display:inline-flex; align-items:center; gap:2px;
    background:#dff0ea; color:#155724; border:1px solid #a9dfbf;
    border-radius:3px; padding:1px 5px; font-size:11px; line-height:1.4;
}
.proc-tag-x { cursor:pointer; color:#999; font-size:13px; line-height:1; margin-left:2px; }
.proc-tag-x:hover { color:#c0392b; }
.process-cell { vertical-align:top; padding-top:5px !important; }
/* ── 直接標籤導覽（新）── */
.proc-direct-l1 { display:flex; flex-wrap:wrap; gap:3px; margin-bottom:3px; }
.proc-direct-l2 { display:flex; flex-wrap:wrap; gap:3px; margin-bottom:3px; }
.proc-l1-btn {
    font-size:11px; padding:1px 7px; border:1px solid #ccc;
    border-radius:3px; background:#f8f9fa; cursor:pointer; white-space:nowrap;
}
.proc-l1-btn:hover { background:#e8f0ff; border-color:#aac; }
.proc-l1-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.proc-l2-btn {
    font-size:11px; padding:1px 7px; border:1px solid #b2cce0;
    border-radius:10px; background:#eaf3fb; color:#1a6496; cursor:pointer; white-space:nowrap;
}
.proc-l2-btn:hover { background:#c7e2f4; }
.proc-l2-btn.active { background:var(--accent); color:#fff; border-color:var(--accent); }
.proc-selected-chips { display:flex; flex-wrap:wrap; gap:3px; margin-top:2px; min-height:20px; }
.note-tmpl-btn.nt-applied { background:#d4edda !important; border-color:#28a745 !important; color:#155724 !important; }

/* 設定頁製程標籤三欄 */
.pt-group-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:4px 6px; margin-bottom:3px; border-radius:4px;
    background:#f8f9fa; border:1px solid #e0e0e0; font-size:12px; cursor:pointer;
}
.pt-group-item:hover, .pt-group-item.active { background:#e8f0ff; border-color:#aac; }
.pt-group-item .pt-del  { color:#e74c3c; cursor:pointer; padding:0 3px; visibility:hidden; }
.pt-group-item .pt-edit { color:#337ab7; cursor:pointer; padding:0 3px; visibility:hidden; }
.pt-group-item:hover .pt-del, .pt-group-item:hover .pt-edit { visibility:visible; }
.pt-drag-handle { color:#bbb; cursor:grab; padding:0 4px; font-size:13px; }
.pt-drag-handle:active { cursor:grabbing; }
.ui-sortable-helper { box-shadow:0 4px 12px rgba(0,0,0,.2); opacity:.95; }
.ui-sortable-placeholder { background:#eaf3fb; border:1px dashed #aac; border-radius:4px; visibility:visible !important; }
.pt-proc-check { display:block; padding:2px 4px; font-size:12px; font-weight:normal; cursor:pointer; }
.pt-proc-check:hover { color:var(--accent); }

/* ── 項目表格 ── */
.items-hdr {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:8px;
}
.items-hdr h4 { margin:0; font-size:14px; color:var(--primary); }
#quoteItemsTable > tbody > tr.item-row > td {
    vertical-align:top; padding-top:6px !important; position:relative;
}
.item-row input { font-size:13px; height:30px; }

/* ── 階梯樣式（沿用原版）── */
.tier-section {
    background:#EAF6F4; border-left:3px solid var(--accent);
    border-radius:0 0 6px 6px; padding:8px 10px 6px; margin-top:4px;
}
.tier-table { width:100%; border-collapse:collapse; font-size:12px; }
.tier-table th {
    background:#d4ede9; color:var(--primary); padding:4px 6px;
    text-align:center; white-space:nowrap; font-weight:600;
}
.tier-table td { padding:3px 4px; vertical-align:middle; }
.tier-table input, .tier-table select { font-size:12px; height:26px; padding:2px 5px; }
.tier-table .tier-amount {
    color:var(--accent); font-weight:600; background:transparent;
    border:none; text-align:right; width:100%;
}
.btn-add-tier  { font-size:11px; padding:2px 8px; margin-top:5px; }
.btn-del-tier  { padding:1px 5px; }
.tier-toggle-btn { font-size:11px; padding:2px 7px; }
.tier-toggle-btn.active { background:var(--accent); color:white; border-color:var(--accent); }

/* ── 合計列 ── */
.total-bar {
    display:flex; justify-content:flex-end; align-items:center; gap:12px;
    margin-top:14px; padding:10px 14px; background:#f8f9fa; border-radius:6px;
}
.total-val { font-size:24px; font-weight:700; color:var(--accent); }
.total-cur { font-size:14px; color:#888; }

/* ── autocomplete ── */
.part-suggestions, .autocomplete-suggestions {
    position:fixed !important; z-index:9999 !important;
    background:#fff; border:1px solid var(--border); border-radius:4px;
    max-height:200px; overflow-y:auto;
    box-shadow:0 8px 16px rgba(42,63,84,.15);
}
.suggestion-item {
    padding:7px 12px; cursor:pointer; border-bottom:1px solid #f8f9fa;
    color:#495057; font-size:13px;
}
.suggestion-item:hover { background:var(--bg); color:var(--accent); font-weight:bold; }

/* ── 歷史快帶入 ── */
.hq-row td { padding:2px 0 5px 32px !important; background:transparent; }
.hq-wrap { display:flex; flex-wrap:wrap; gap:5px; align-items:center; }
.hq-chip {
    background:#f0f8ff; border:1px solid #cce; border-radius:4px;
    padding:2px 8px; font-size:11px; cursor:pointer; transition:background .12s;
    display:inline-block;
}
.hq-chip:hover { background:#ddf; border-color:#88c; }
.hq-chip b { color:var(--primary); }

/* ── 議價 badge ── */
.nego-badge {
    display:inline-block; font-size:10px; padding:1px 7px;
    background:#e8f8f0; color:#1e8449; border:1px solid #a9dfbf;
    border-radius:10px; margin-left:5px; vertical-align:middle;
    font-weight:600; white-space:nowrap;
}
/* ── 草稿 badge（必備附件缺漏仍執意儲存）── */
.draft-badge {
    display:inline-block; font-size:10px; padding:1px 7px;
    background:#fdf3e6; color:#c87f0a; border:1px solid #f0c987;
    border-radius:10px; margin-left:5px; vertical-align:middle;
    font-weight:600; white-space:nowrap;
}
/* ── 暫存 badge（內容尚未填完，先存起來續填）── */
.tempsave-badge {
    display:inline-block; font-size:10px; padding:1px 7px;
    background:#F7E0BD; color:#7a4a00; border:1px solid #e8c98f;
    border-radius:10px; margin-left:5px; vertical-align:middle;
    font-weight:600; white-space:nowrap;
}
.source-badge {
    display:inline-block; font-size:10px; padding:1px 5px;
    background:#e0e0e0; color:#888; border-radius:3px;
    margin-left:4px; vertical-align:middle;
}

.form-group { margin-bottom:10px; }
.form-group label { font-size:12px; color:#666; margin-bottom:3px; }

/* ── 建立/修改資訊列 ── */
.history-bar {
    font-size:11px; color:#888; padding:3px 22px;
    background:#fafafa; border-bottom:1px solid var(--border);
    display:flex; gap:18px; flex-wrap:wrap;
}
.history-bar i { margin-right:3px; }

/* ── SweetAlert2 內容可選取 ── */
.swal2-html-container { user-select:text !important; -webkit-user-select:text !important; }

/* ── 檢視模式 ── */
.view-panel { flex:1; overflow:hidden; display:none; flex-direction:column; }
.view-mode-badge {
    padding:4px 22px; background:#d1ecf1; border-bottom:1px solid #bee5eb;
    font-size:12px; color:#0c5460; font-weight:600; flex-shrink:0;
}
.view-item-table th { background:#f8f9fa; font-size:12px; }
.view-item-table td { font-size:13px; vertical-align:middle; }
@media print { .no-print { display:none !important; } }
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">

        <!-- 標題列 -->
        <div class="page-title-bar">
            <h3><i class="fa fa-file-text-o" style="color:var(--accent);margin-right:7px;"></i>
                報價單管理 <small style="font-size:12px;color:#aaa;font-weight:400;">快速版</small>
            </h3>
            <div style="display:flex;gap:14px;align-items:center;">
                <?php if ($CAN_CREATE || $CAN_EDIT || $CAN_SIGN): /* 唯讀(僅檢視)使用者不顯示待處理篩選，避免看到/使用無意義的操作鈕 */ ?>
                <button class="btn btn-sm" id="pendingDocBtn" onclick="applyPendingFilter()" title="只顯示待審核或被駁回的報價單" style="position:relative;font-weight:600;background:#F7E0BD;color:#7a4a00;border:1px solid #e8c98f;">
                    <i class="fa fa-inbox"></i> 待處理單據
                    <span id="pendingDocBadge" style="display:none;position:absolute;top:-7px;right:-7px;background:#b5651d;color:#fff;border-radius:10px;font-size:10px;padding:0 5px;font-weight:700;">0</span>
                </button>
                <button class="btn btn-sm" id="tempSaveDocBtn" onclick="applyTempSaveFilter()" title="只顯示我暫存中尚未完成的報價單" style="position:relative;font-weight:600;background:#F7E0BD;color:#7a4a00;border:1px solid #e8c98f;">
                    <i class="fa fa-clock-o"></i> 暫存未完成
                    <span id="tempSaveDocBadge" style="display:none;position:absolute;top:-7px;right:-7px;background:#b5651d;color:#fff;border-radius:10px;font-size:10px;padding:0 5px;font-weight:700;">0</span>
                </button>
                <button class="btn btn-default btn-sm" id="showAllDocBtn" onclick="clearDocFilters()" title="取消篩選，顯示全部報價單" style="display:none;">
                    <i class="fa fa-list"></i> 顯示全部
                </button>
                <?php endif; ?>
                <?php if ($CAN_CREATE): ?>
                <button class="btn btn-success btn-sm" id="newQuoteBtn" onclick="openNewEditor()">
                    <i class="fa fa-plus"></i> 新增報價單
                </button>
                <?php endif; ?>
                <?php if ($CAN_VIEW_DELETED): ?>
                <button class="btn btn-default btn-sm" onclick="openDeleteLog()" title="歷史紀錄">
                    <i class="fa fa-history"></i>
                </button>
                <?php endif; ?>
                <?php if ($CAN_SETTINGS): ?>
                <button class="btn btn-default btn-sm" onclick="openUploadSettings()" title="設定附件儲存路徑">
                    <i class="fa fa-cog"></i>
                </button>
                <?php endif; ?>
                <?php if ($CAN_SIGN): ?>
                <button class="btn btn-default btn-sm" id="suppReviewBtn" onclick="openSupplementReview()" title="補件待審" style="position:relative;">
                    <i class="fa fa-hourglass-half"></i> 補件待審
                    <span id="suppReviewBadge" style="display:none;position:absolute;top:-7px;right:-7px;background:#DD5138;color:#fff;border-radius:10px;font-size:10px;padding:0 5px;font-weight:700;">0</span>
                </button>
                <?php endif; ?>
                <button class="btn btn-default btn-sm" onclick="openPermHelp()" title="權限說明"
                    style="border-radius:50%;width:28px;height:28px;padding:0;font-weight:700;">
                    ?
                </button>
            </div>
        </div>

        <!-- 分割版面 -->
        <div class="split-wrap">

            <!-- ★ 左側：報價單列表 -->
            <div class="list-panel">
                <!-- 統計 -->
                <div class="list-stats">
                    <div class="stat-chip">
                        <span class="stat-chip-val" id="stat-count">0</span>
                        <span class="stat-chip-lbl">報價單數</span>
                    </div>
                    <div class="stat-chip">
                        <span class="stat-chip-val" id="stat-amount" style="font-size:13px;">0</span>
                        <span class="stat-chip-lbl">總金額</span>
                    </div>
                </div>
                <!-- 工具列：年份 + 全部年份 -->
                <div class="list-toolbar">
                    <form method="GET" style="margin:0;" id="yearForm">
                        <select name="year" class="form-control input-sm" style="width:75px;display:inline-block;"
                            onchange="document.getElementById('yearForm').submit()">
                            <?php for($y=date('Y');$y>=2024;$y--): ?>
                            <option value="<?=$y?>" <?=$y==$selectedYear?'selected':''?>><?=$y?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                    <span style="font-size:11px;color:#aaa;">年</span>
                    <button class="btn btn-link btn-xs" style="font-size:11px;padding:1px 4px;" onclick="loadAllYears()" title="搜尋全部年份">
                        全部年份
                    </button>
                    <div id="allYearsIndicator" style="display:none;font-size:10px;color:var(--accent);padding:1px 5px;border:1px solid var(--accent);border-radius:3px;">ALL</div>
                </div>
                <!-- 客戶篩選下拉（上方模糊搜尋框可用客戶代碼或名稱縮小下拉選項） -->
                <div style="padding:6px 10px;border-bottom:1px solid var(--border);background:#fafafa;flex-shrink:0;">
                    <div style="display:flex;gap:5px;margin-bottom:4px;">
                        <div style="position:relative;flex:1;min-width:0;">
                            <i class="fa fa-filter" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:#c9a074;font-size:11px;"></i>
                            <input type="text" id="clientFilterSearch" class="form-control input-sm"
                                placeholder="模糊篩選客戶代碼／名稱…" autocomplete="off"
                                title="輸入客戶代碼或名稱片段即可縮小下方客戶清單；可用空白分隔多個關鍵字；雙擊清空"
                                style="font-size:12px;padding-left:24px;">
                        </div>
                        <button type="button" id="clearAllFilterBtn" class="btn btn-sm"
                            onclick="clearAllListFilters()"
                            title="一次清除：客戶模糊篩選字、客戶篩選下拉、下方單號/備註搜尋"
                            style="font-size:11px;padding:3px 8px;white-space:nowrap;background:#F7E0BD;border:1px solid #e0c193;color:#7a4b12;">
                            <i class="fa fa-eraser"></i> 取消篩選
                        </button>
                    </div>
                    <select id="clientFilterSel" class="form-control input-sm"
                        style="font-size:12px;"
                        onchange="renderQuoteList(allQuotes, $('#listSearch').val().trim())">
                        <option value="">全部客戶</option>
                    </select>
                    <div id="clientFilterHint" style="display:none;font-size:10px;color:#96601f;margin-top:3px;"></div>
                </div>
                <!-- 搜尋 -->
                <div class="list-search">
                    <input type="text" id="listSearch" class="form-control input-sm"
                        placeholder="搜尋單號、備註...">
                </div>
                <!-- 列表 -->
                <div id="quoteListBody">
                    <div class="text-center text-muted" style="padding:30px;">
                        <i class="fa fa-spinner fa-spin fa-lg"></i><br><small>載入中...</small>
                    </div>
                </div>
            </div>

            <!-- ★ 右側：檢視/編輯/空白 -->
            <div class="editor-wrap">

                <!-- ★ 檢視面板 -->
                <div class="view-panel" id="viewPanel">
                    <div class="editor-header no-print">
                        <h4 id="viewTitle" style="display:flex;align-items:center;">
                            <i class="fa fa-eye" style="color:var(--accent);margin-right:6px;"></i>
                            <span id="viewTitleText">報價單</span>
                            <span id="viewClientTag" class="editor-client-tag"></span>
                        </h4>
                        <div style="display:flex;gap:6px;">
                            <?php if ($CAN_VIEW_HISTORY): ?>
                            <button class="btn btn-default btn-sm" id="viewChangeLogBtn" onclick="openChangeLog()" title="修改紀錄">
                                <i class="fa fa-history"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($CAN_CHG_CUSTOMER): ?>
                            <button class="btn btn-sm" id="chgCustomerBtn" onclick="openChgCustomer()"
                                style="background:#F0A24B;color:#fff;font-weight:600;"
                                title="一次把整張單（含各料號綁定的客戶）改成另一家客戶，並連動由本張OP轉出的訂單與BOM">
                                <i class="fa fa-exchange"></i> 變更客戶
                            </button>
                            <?php endif; ?>
                            <?php if ($CAN_EDIT): ?>
                            <button class="btn btn-warning btn-sm" onclick="openEditorFromView()">
                                <i class="fa fa-pencil"></i> 編輯
                            </button>
                            <?php endif; ?>
                            <?php if ($CAN_PRINT): ?>
                            <button class="btn btn-info btn-sm" id="printQuoteBtn" onclick="printQuote()" title="">
                                <i class="fa fa-print"></i> 列印
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-default btn-sm" onclick="closeViewPanel()">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <!-- 檢視畫面標識（列印時隱藏）-->
                    <div class="view-mode-badge no-print">
                        <i class="fa fa-eye" style="margin-right:5px;"></i>檢視畫面 — 此標識列印時不顯示
                    </div>
                    <!-- 歷史列 -->
                    <div class="history-bar no-print" id="viewHistoryBar" style="display:none;">
                        <span id="viewHistCreated"></span>
                        <span id="viewHistUpdated" style="display:none;"></span>
                    </div>
                    <!-- 主管簽核狀態列（意見/駁回原因/核准駁回按鈕；列印時不顯示） -->
                    <div class="no-print" id="viewApprovalBar" style="display:none;margin:0 22px 8px;padding:8px 12px;border-radius:5px;font-size:12px;"></div>
                    <div id="viewBody" style="flex:1;overflow-y:auto;padding:14px 22px 16px;"></div>
                </div>

                <!-- ★ 編輯面板 -->
                <div class="editor-panel" id="editorPanel" style="display:none;flex-direction:column;">
                    <div class="editor-header">
                        <h4 id="editorTitle" style="display:flex;align-items:center;"><i class="fa fa-pencil" style="margin-right:6px;"></i>新增報價單<span id="editorClientNameTag" class="editor-client-tag"></span></h4>
                        <div style="display:flex;gap:6px;">
                            <?php if ($CAN_VIEW_HISTORY): ?>
                            <button class="btn btn-default btn-sm" id="changeLogBtn" style="display:none;" onclick="openChangeLog()" title="修改紀錄">
                                <i class="fa fa-history"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($CAN_DELETE): ?>
                            <button class="btn btn-danger btn-sm" id="delQuoteBtn" style="display:none;" onclick="deleteQuote()" title="刪除">
                                <i class="fa fa-trash"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($CAN_CLONE): ?>
                            <button class="btn btn-warning btn-sm" id="cloneQuoteBtn" style="display:none;" onclick="cloneQuote()">
                                <i class="fa fa-copy"></i> 複製
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-default btn-sm" onclick="closeEditor()">
                                <i class="fa fa-times"></i>
                            </button>
                            <?php if ($CAN_CREATE || $CAN_EDIT): ?>
                            <button class="btn btn-default btn-sm" id="tempSaveQuoteBtn" onclick="tempSaveQuote()" title="內容還沒填完也能先存起來，之後再繼續填寫；不會列印、不會送審">
                                <i class="fa fa-clock-o"></i> 暫存
                            </button>
                            <button class="btn btn-primary btn-sm" id="saveQuoteBtn" onclick="saveQuote()">
                                <i class="fa fa-save"></i> 儲存
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 鎖定警告列 -->
                    <div id="lockWarningBar" style="display:none;padding:4px 22px;background:#fff3cd;border-bottom:1px solid #ffc107;font-size:12px;color:#856404;">
                        <i class="fa fa-lock" style="margin-right:5px;"></i>
                        <span id="lockWarningMsg"></span>
                    </div>

                    <!-- 建立 / 修改資訊列 -->
                    <div class="history-bar" id="historyBar" style="display:none;">
                        <span id="histCreated"></span>
                        <span id="histUpdated" style="display:none;"></span>
                        <span id="histPrint" style="display:none;color:var(--accent);"></span>
                    </div>

                    <form id="quoteForm" onsubmit="return false;" style="flex:1;overflow-y:auto;padding:14px 22px 16px;">
                        <input type="hidden" id="quote_id">
                        <input type="hidden" id="source_quote_id">
                        <input type="hidden" id="last_updated_at">

                        <!-- Row 1：單號 / 日期 / 有效日期 / 幣別 / 匯率 -->
                        <div class="row">
                            <div class="col-sm-3 form-group">
                                <label>報價單號 <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-addon" id="quote_no_prefix"
                                        style="font-family:monospace;letter-spacing:1px;background:#eef2f7;color:#2A3F54;font-weight:600;font-size:13px;white-space:nowrap;"></span>
                                    <input type="text" class="form-control" id="quote_seq" maxlength="3"
                                        placeholder="001" title="流水號（後3碼）"
                                        style="font-family:monospace;text-align:center;min-width:52px;">
                                </div>
                                <input type="hidden" id="quote_no">
                            </div>
                            <div class="col-sm-2 form-group">
                                <label>報價日期 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="quote_date" required>
                            </div>
                            <div class="col-sm-2 form-group">
                                <label>有效日期</label>
                                <input type="date" class="form-control" id="valid_until">
                            </div>
                            <div class="col-sm-2 form-group">
                                <label>幣別</label>
                                <select class="form-control" id="currency">
                                    <option value="TWD">NTD</option><option>USD</option>
                                    <option>JPY</option><option>EUR</option><option>RMB</option>
                                </select>
                            </div>
                            <div class="col-sm-2 form-group">
                                <label>匯率</label>
                                <input type="number" class="form-control" id="exchange_rate" value="1" step="0.000001">
                            </div>
                        </div>

                        <!-- Row 2：詢價單號 / 客戶 / 備註 / 議價 -->
                        <div class="row">
                            <div class="col-sm-2 form-group">
                                <label>客戶詢價編號</label>
                                <input type="text" class="form-control" id="inquiry_no"
                                    placeholder="非必填" maxlength="50">
                                <div id="client-contact-row" style="margin-top:4px;display:none;">
                                    <label style="font-size:11px;color:#666;margin-bottom:2px;">聯絡人</label>
                                    <select class="form-control input-sm" id="contact_select" style="font-size:12px;" onchange="$('#contact_id').val(this.value)">
                                    </select>
                                </div>
                                <div style="margin-top:6px;display:flex;align-items:center;gap:4px;">
                                    <button type="button" class="btn btn-warning btn-sm" id="btnAutoSortItems" onclick="autoSortQuoteItems()"
                                        style="font-weight:600;font-size:13px;padding:6px 14px;"
                                        title="依排序規則重新排列報價項目（先預覽確認才寫入資料庫排序號碼）；列印一律依存檔的順序">
                                        <i class="fa fa-sort-amount-asc"></i> 項目自動排序
                                    </button>
                                    <?php if ($CAN_SORT_RULE || $IS_ADMIN): ?>
                                    <button type="button" class="btn btn-link btn-xs" onclick="openSortRuleSetting()"
                                        style="padding:2px 4px;color:#b5722a;" title="調整自動排序規則（全體適用）">
                                        <i class="fa fa-cog"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-sm-3 form-group has-feedback">
                                <label>客戶名稱 <i class="fa fa-check-circle" id="client-bound-check" style="color:#27ae60;display:none;font-size:13px;" title="已綁定客戶資料"></i></label>
                                <input type="text" class="form-control" id="client_name"
                                    placeholder="輸入客戶代碼或名稱..." autocomplete="off"
                                    ondblclick="this.value='';$('#client_id').val('');updateClientBoundCheck();">
                                <span class="fa fa-cog form-control-feedback right"
                                    style="cursor:pointer;color:#337ab7;pointer-events:auto;top:24px;z-index:10;"
                                    onclick="openCustomerGear()" title="管理客戶"></span>
                                <div id="client-suggestions" class="autocomplete-suggestions"></div>
                                <input type="hidden" id="client_id">
                                <input type="hidden" id="contact_id">
                            </div>
                            <div class="col-sm-5 form-group">
                                <label style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
                                    <span>備註</span>
                                    <button type="button" class="btn btn-xs btn-default" onclick="applyProcTypeNotes()"
                                        style="font-size:11px;" title="依訂單內製程類型自動帶入備註">
                                        <i class="fa fa-magic"></i> 依製程帶入
                                    </button>
                                    <small id="note-char-count" class="text-muted" style="margin-left:auto;font-size:11px;">0/200</small>
                                </label>
                                <textarea class="form-control" id="note" rows="1" maxlength="200"
                                    style="resize:none;overflow:hidden;min-height:34px;line-height:1.5;padding:6px 10px;"></textarea>
                                <div id="note-tmpl-btns" style="margin-top:4px;line-height:1.9;"></div>
                            </div>
                            <div class="col-sm-2 form-group" style="padding-top:24px;">
                                <label style="font-weight:normal;cursor:pointer;margin:0;display:flex;align-items:center;gap:6px;">
                                    <input type="checkbox" id="is_negotiation" style="width:18px;height:18px;cursor:pointer;flex-shrink:0;">
                                    <span style="color:#1e8449;font-weight:600;font-size:13px;">議價單</span>
                                </label>
                            </div>
                        </div>

                        <!-- ★ 附件上傳 -->
                        <div class="file-section">
                            <div class="file-section-title">
                                <i class="fa fa-paperclip"></i> 附件
                                <small style="font-weight:normal;color:#888;font-size:11px;">
                                    儲存路徑：<span id="uploadPathDisplay" style="color:var(--accent);">未設定</span>
                                    <?php if ($CAN_SETTINGS): ?>
                                    <button type="button" class="btn btn-link" style="font-size:10px;padding:0 3px;vertical-align:baseline;" onclick="openUploadSettings()">
                                        <i class="fa fa-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="file-drop-zone" id="fileDropZone"
                                onclick="document.getElementById('fileInput').click()">
                                <i class="fa fa-cloud-upload"></i>
                                拖曳或點擊選擇檔案上傳（所有格式）
                            </div>
                            <input type="file" id="fileInput" style="display:none;" multiple>
                            <div id="uploadedFilesList" style="margin-top:7px;"></div>
                        </div>

                        <hr style="margin:12px 0;">

                        <!-- ★ 項目區 -->
                        <div class="items-hdr">
                            <h4><i class="fa fa-list" style="color:var(--accent);margin-right:5px;"></i>報價項目</h4>
                            <div style="display:flex;align-items:center;gap:7px;">
                                <small class="text-muted" style="font-size:11px;">
                                    容差預設：<span id="defaultTolDisplay" style="color:var(--accent);font-weight:600;">載入中...</span>
                                </small>
                                <button type="button" class="btn btn-default btn-xs" onclick="applyDefaultTolerance()">
                                    <i class="fa fa-magic"></i> 套用容差
                                </button>
                                <button type="button" class="btn btn-link btn-xs" onclick="openTolSettingModal()" style="padding:0 4px;">
                                    <i class="fa fa-cog"></i>
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table id="quoteItemsTable" class="table table-condensed" style="table-layout:fixed;font-size:13px;">
                                <colgroup>
                                    <!-- 2026-07-31 料號欄加寬 15→20%（長料號被省略遮蔽），由製程欄 33→28% 挪出；
                                         總和維持 100%，table-layout:fixed 不會出現左右捲軸 -->
                                    <col style="width:4%">   <!-- 刪除 -->
                                    <col style="width:20%">  <!-- 料號 -->
                                    <col style="width:28%">  <!-- 製程+製程分類 -->
                                    <col style="width:11%">  <!-- 料號備註 -->
                                    <col style="width:6%">   <!-- 數量 -->
                                    <col style="width:7%">   <!-- 單位 -->
                                    <col style="width:9%">   <!-- 單價 -->
                                    <col style="width:9%">   <!-- 金額 -->
                                    <col style="width:4%">   <!-- 階梯 -->
                                    <col style="width:2%">   <!-- 佔位 -->
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>料號</th>
                                        <th>製程 <small style="color:#aaa;font-weight:400;">/ 製程分類</small></th>
                                        <th>料號備註</th>
                                        <th>數量</th>
                                        <th>單位</th>
                                        <th>單價</th>
                                        <th>金額</th>
                                        <th title="切換階梯報價模式"><i class="fa fa-list-ol"></i></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-info btn-sm" onclick="addItemRow()">
                            <i class="fa fa-plus"></i> 新增項目
                        </button>
                        <button type="button" class="btn btn-default btn-sm" onclick="openBatchAddModal()" style="margin-left:6px;">
                            <i class="fa fa-list-ul"></i> 批次新增
                        </button>

                        <!-- 合計 -->
                        <div class="total-bar">
                            <small class="text-muted">階梯報價以各區間「最低門檻量 × 單價」計算小計</small>
                            <div>
                                <span id="totalAmountDisplay" class="total-val">0</span>
                                <span id="currencyDisplay" class="total-cur">NTD</span>
                            </div>
                        </div>
                    </form>
                </div><!-- /editor-panel -->

                <!-- 空白提示 -->
                <div class="editor-empty" id="editorEmpty">
                    <i class="fa fa-file-text-o fa-4x"></i>
                    <div style="margin-top:14px;font-size:14px;">點選左側報價單開始檢視</div>
                    <?php if ($CAN_CREATE): ?>
                    <div style="margin-top:10px;">
                        <button class="btn btn-success" onclick="openNewEditor()">
                            <i class="fa fa-plus"></i> 新增報價單
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /editor-wrap -->
        </div><!-- /split-wrap -->

    </div><!-- /right_col -->
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- ══════════════════════════════════════════════════════
     報價單設定 Modal（路徑 / 附件類別 / 製程標籤）
     ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="quoteSettingsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" style="width:1020px;max-width:96vw;" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--primary);color:#fff;padding:12px 18px;">
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
        <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-cog" style="margin-right:7px;"></i>報價單設定</h4>
      </div>
      <div class="modal-body" style="padding:0;">
        <!-- Tab 導覽 -->
        <ul class="nav nav-tabs" style="padding:0 16px;margin:0;background:#f8f9fa;border-bottom:1px solid #ddd;">
          <li class="active"><a href="#qs-tab-path"       data-toggle="tab"><i class="fa fa-folder-open-o"></i> 儲存路徑</a></li>
          <li>              <a href="#qs-tab-categories"  data-toggle="tab"><i class="fa fa-tag"></i> 附件類別</a></li>
          <li>              <a href="#qs-tab-proc-tags"   data-toggle="tab"><i class="fa fa-sitemap"></i> 製程標籤</a></li>
          <li>              <a href="#qs-tab-note-tmpl"  data-toggle="tab"><i class="fa fa-comment-o"></i> 備註模板</a></li>
          <?php if ($IS_ADMIN): ?>
          <li>              <a href="#qs-tab-permissions" data-toggle="tab" onclick="loadPermissionsTab()"><i class="fa fa-key"></i> 權限設定</a></li>
          <?php endif; ?>
        </ul>
        <div class="tab-content" style="padding:16px 18px;">

          <!-- ── Tab 0（管理員）：角色權限設定 ── -->
          <?php if ($IS_ADMIN): ?>
          <div id="qs-tab-permissions" class="tab-pane">
            <div style="display:flex;gap:0;height:400px;font-size:13px;">

              <!-- 左：角色清單 -->
              <div style="width:200px;min-width:200px;border-right:1px solid #ddd;display:flex;flex-direction:column;">
                <div style="padding:8px 10px;background:#f8f9fa;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
                  <span style="font-weight:600;font-size:12px;">角色清單</span>
                  <button class="btn btn-xs btn-success" onclick="addRole()"><i class="fa fa-plus"></i> 新增</button>
                </div>
                <div id="roles-list" style="flex:1;overflow-y:auto;padding:4px 0;">
                  <div class="text-center text-muted" style="padding:20px;font-size:12px;">載入中...</div>
                </div>
              </div>

              <!-- 右：功能勾選 -->
              <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;">
                <div id="role-feat-header" style="padding:8px 14px;background:#f8f9fa;border-bottom:1px solid #ddd;font-weight:600;font-size:12px;color:#555;">
                  ← 請選擇角色
                </div>
                <div id="role-feat-body" style="flex:1;overflow-y:auto;padding:10px 14px;">
                  <?php
                  $groups = [];
                  foreach ($PAGE_FEATURES as $f) $groups[$f['group']][] = $f;
                  foreach ($groups as $grpName => $items): ?>
                  <div style="margin-bottom:12px;">
                    <div style="font-weight:600;color:#555;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;">
                      <?= htmlspecialchars($grpName) ?>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px 24px;">
                    <?php foreach ($items as $feat): ?>
                      <label style="font-weight:normal;cursor:pointer;display:flex;align-items:center;gap:5px;">
                        <input type="checkbox" class="role-feat-cb"
                          value="<?= htmlspecialchars($feat['code']) ?>"
                          data-label="<?= htmlspecialchars($feat['label']) ?>">
                        <?= htmlspecialchars($feat['label']) ?>
                      </label>
                    <?php endforeach; ?>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <div id="role-feat-footer" style="padding:8px 14px;border-top:1px solid #ddd;background:#f8f9fa;display:none;">
                  <small class="text-muted" id="role-feat-note" style="float:left;line-height:28px;"></small>
                  <div style="display:flex;gap:6px;justify-content:flex-end;">
                    <button class="btn btn-default btn-sm" id="btn-check-all" onclick="toggleAllFeatures(true)">
                      <i class="fa fa-check-square-o"></i> 全選
                    </button>
                    <button class="btn btn-default btn-sm" id="btn-uncheck-all" onclick="toggleAllFeatures(false)">
                      <i class="fa fa-square-o"></i> 取消全選
                    </button>
                    <button class="btn btn-primary btn-sm" id="btn-save-role-feat" onclick="saveRoleFeatures()">
                      <i class="fa fa-save"></i> 儲存角色設定
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <p style="font-size:11px;color:#aaa;padding:6px 10px 4px;border-top:1px solid #eee;margin:0;">
              <i class="fa fa-info-circle"></i> 使用者指派角色請至 <strong>管理設定 → 使用者權限</strong> 頁面操作
            </p>
          </div>
          <?php endif; ?>

          <!-- ── Tab 1：儲存路徑 ── -->
          <div id="qs-tab-path" class="tab-pane active">
            <div class="form-group">
              <label style="font-size:13px;">Z 槽附件儲存根目錄</label>
              <div class="input-group">
                <input type="text" id="qs-upload-path" class="form-control"
                  placeholder="例：Z:\業務\報價資料">
                <span class="input-group-btn">
                  <button class="btn btn-primary" type="button" onclick="saveUploadPath()">
                    <i class="fa fa-save"></i> 儲存
                  </button>
                </span>
              </div>
              <small class="text-muted">每張報價單的附件存入 <code>[根目錄\報價單號\]</code> 子資料夾</small>
            </div>
            <div class="form-group" style="margin-top:16px;">
              <label style="font-size:13px;">綁定 AS 文件編號</label>
              <div class="input-group">
                <select id="qs-as-doc" class="form-control" style="font-size:12px;"></select>
                <span class="input-group-btn">
                  <button class="btn btn-primary" type="button" onclick="saveAsDocBinding()">
                    <i class="fa fa-save"></i> 儲存
                  </button>
                </span>
              </div>
              <small class="text-muted">綁定後列印時文件編號顯示於<b>每頁頁尾右下角</b>（列印文件標準）；異動 AS 文件編號時此處自動跟著變</small>
            </div>
            <div class="form-group" style="margin-top:12px;">
              <label style="font-size:13px;">列印表單編號（未綁定 AS 文件時的後備）</label>
              <div class="input-group">
                <input type="text" id="qs-form-number" class="form-control"
                  placeholder="例：2-SM-01-02">
                <span class="input-group-btn">
                  <button class="btn btn-primary" type="button" onclick="saveFormNumber()">
                    <i class="fa fa-save"></i> 儲存
                  </button>
                </span>
              </div>
              <small class="text-muted">僅在未綁定 AS 文件時使用；建議改用上方綁定</small>
            </div>
            <div class="form-group" style="margin-top:12px;">
              <label style="font-size:13px;">報價有效期天數</label>
              <div class="input-group" style="max-width:200px;">
                <input type="text" inputmode="numeric" id="qs-valid-days" class="form-control"
                  placeholder="例：30">
                <span class="input-group-addon">天</span>
                <span class="input-group-btn">
                  <button class="btn btn-primary" type="button" onclick="saveValidDays()">
                    <i class="fa fa-save"></i>
                  </button>
                </span>
              </div>
              <small class="text-muted">新增報價單時自動帶入有效日期（報價日 + N 天）</small>
            </div>
            <div class="form-group" style="margin-top:12px;">
              <label style="font-size:13px;">列印管制</label>
              <div class="checkbox" style="margin:2px 0 4px;">
                <label style="font-size:13px;font-weight:normal;">
                  <input type="checkbox" id="qs-print-need-approval" onchange="savePrintNeedApproval(this.checked)">
                  需主管審核通過才能列印
                </label>
              </div>
              <small class="text-muted">勾選＝正式報價單須核准後才可列印（預設）；取消勾選＝存成正式報價單即可列印，不需等審核。草稿一律不能列印。</small>
            </div>
            <div class="form-group" style="margin-top:12px;">
              <label style="font-size:13px;">附件自動清除天數</label>
              <div style="display:flex;gap:14px;flex-wrap:wrap;">
                <div class="input-group" style="max-width:230px;">
                  <span class="input-group-addon" style="font-size:12px;">未存檔暫存</span>
                  <input type="text" inputmode="numeric" id="qs-temp-days" class="form-control" placeholder="2">
                  <span class="input-group-addon">天</span>
                </div>
                <div class="input-group" style="max-width:230px;">
                  <span class="input-group-addon" style="font-size:12px;">補件被否決</span>
                  <input type="text" inputmode="numeric" id="qs-trash-days" class="form-control" placeholder="7">
                  <span class="input-group-addon">天</span>
                  <span class="input-group-btn">
                    <button class="btn btn-primary" type="button" onclick="saveAttachDays()"><i class="fa fa-save"></i></button>
                  </span>
                </div>
              </div>
              <small class="text-muted">附件上傳後未存檔＝暫存，逾「未存檔暫存」天數自動刪除；補件被否決的附件先進暫存區，逾「補件被否決」天數自動刪除（預設 2 天／7 天）。</small>
            </div>
          </div>

          <!-- ── Tab 2：附件類別 ── -->
          <div id="qs-tab-categories" class="tab-pane">
            <div class="row" style="margin-bottom:0;">
              <div class="col-sm-6">
                <div class="panel panel-default" style="margin-bottom:12px;">
                  <div class="panel-heading" style="font-size:13px;"><b><i class="fa fa-filter"></i> 本頁適用的附件類別與排序</b></div>
                  <div class="panel-body" style="padding:10px;">
                    <div id="qs-page-cats" style="max-height:220px;overflow-y:auto;"></div>
                    <button class="btn btn-primary btn-sm" onclick="savePageAttachCats()" style="margin-top:6px;">
                      <i class="fa fa-save"></i> 儲存本頁類別設定
                    </button>
                    <small class="text-muted" style="display:block;margin-top:4px;">
                      勾選＝此頁顯示該類別；拖曳 ☰ 調整此頁顯示順序（不影響其他頁面）。全部未勾選＝顯示全部類別。
                    </small>
                  </div>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="panel panel-default" style="margin-bottom:12px;">
                  <div class="panel-heading" style="font-size:13px;"><b><i class="fa fa-asterisk text-danger"></i> 每個料號必備的附件類別</b></div>
                  <div class="panel-body" style="padding:10px;">
                    <div id="qs-required-cats" style="line-height:2.4;"></div>
                    <button class="btn btn-primary btn-sm" onclick="saveRequiredCats()" style="margin-top:6px;">
                      <i class="fa fa-save"></i> 儲存必備設定
                    </button>
                    <small class="text-muted" style="display:block;margin-top:4px;">
                      儲存報價單時檢查每個料號是否具備勾選的類別；必備類別附件必須連結單一料號；<b>議價單豁免</b>。
                    </small>
                  </div>
                </div>
              </div>
            </div>
            <div class="row">
              <!-- 左：新增/修改表單 -->
              <div class="col-sm-5">
                <div class="panel panel-default">
                  <div class="panel-heading" style="font-size:13px;"><b id="cat-form-title">新增類別</b></div>
                  <div class="panel-body">
                    <input type="hidden" id="cat-edit-id">
                    <div class="form-group">
                      <label>類別名稱 *</label>
                      <input type="text" class="form-control input-sm" id="cat-name-input" placeholder="例：圖面">
                    </div>
                    <div class="form-group">
                      <label>排序（數字越小越前）</label>
                      <input type="number" class="form-control input-sm" id="cat-order-input" value="0">
                    </div>
                    <div class="form-group" style="margin-bottom:6px;">
                      <div class="checkbox" style="margin:0;">
                        <label><input type="checkbox" id="cat-extdoc-chk" data-eg-skip
                                      onchange="$('#cat-extdoc-name-group').toggle(this.checked)">
                          <b>列入外來文件清單</b>（AS9100 外來文件管制）</label>
                      </div>
                    </div>
                    <div class="form-group" id="cat-extdoc-name-group" style="display:none;">
                      <label>外來文件類別名稱<small class="text-muted">（清單/列印顯示用；留空＝直接用標籤名稱）</small></label>
                      <input type="text" class="form-control input-sm" id="cat-extdoc-name" placeholder="例：客戶圖面">
                    </div>
                    <button class="btn btn-success btn-sm" onclick="saveCategorySettings()">
                      <i class="fa fa-save"></i> 儲存
                    </button>
                    <button class="btn btn-default btn-sm" onclick="resetCategoryForm()">
                      <i class="fa fa-refresh"></i> 清除
                    </button>
                  </div>
                </div>
              </div>
              <!-- 右：類別列表 -->
              <div class="col-sm-7">
                <table class="table table-condensed table-bordered table-striped" style="font-size:13px;">
                  <thead><tr><th style="width:24px;"></th><th>類別名稱</th><th>操作</th></tr></thead>
                  <tbody id="cat-table-body"></tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- ── Tab 3：製程標籤（三層：群組→子標籤→製程）── -->
          <div id="qs-tab-proc-tags" class="tab-pane">
            <div class="row" style="margin:0;">

              <!-- 群組欄 -->
              <div class="col-sm-4" style="padding-right:8px;border-right:1px solid #e0e0e0;">
                <div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:6px;">
                  <i class="fa fa-folder-open"></i> 標籤群組
                </div>
                <div id="pt-group-list" style="min-height:80px;margin-bottom:8px;"></div>
                <div class="input-group input-group-sm">
                  <input type="text" id="pt-new-group" class="form-control" placeholder="新群組名稱">
                  <span class="input-group-btn">
                    <button class="btn btn-success" type="button" onclick="addPtGroup()">
                      <i class="fa fa-plus"></i>
                    </button>
                  </span>
                </div>
              </div>

              <!-- 子標籤欄 -->
              <div class="col-sm-4" style="padding:0 8px;border-right:1px solid #e0e0e0;">
                <div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:6px;">
                  <i class="fa fa-tag"></i> 子標籤
                  <small id="pt-sub-group-label" style="color:#aaa;font-weight:400;"></small>
                </div>
                <div id="pt-sub-list" style="min-height:80px;margin-bottom:8px;">
                  <div class="text-muted" style="font-size:11px;">← 先選擇群組</div>
                </div>
                <div class="input-group input-group-sm" id="pt-sub-form" style="display:none;">
                  <input type="text" id="pt-new-sub" class="form-control" placeholder="新子標籤">
                  <span class="input-group-btn">
                    <button class="btn btn-success" type="button" onclick="addPtSubTag()">
                      <i class="fa fa-plus"></i>
                    </button>
                  </span>
                </div>
              </div>

              <!-- 製程連結欄 -->
              <div class="col-sm-4" style="padding-left:8px;">
                <div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:5px;">
                  <i class="fa fa-cogs"></i> 連結製程
                  <small id="pt-proc-sub-label" style="color:#aaa;font-weight:400;"></small>
                </div>
                <!-- 已選製程 chips -->
                <div id="pt-linked-chips" style="min-height:28px;padding:3px 0 5px;border-bottom:1px solid #eee;margin-bottom:5px;line-height:1.8;display:none;">
                  <small style="color:#888;font-size:10px;">已連結：</small>
                </div>
                <div id="pt-proc-search-wrap" style="display:none;margin-bottom:5px;">
                  <input type="text" id="pt-proc-search" class="form-control input-sm" placeholder="搜尋製程...">
                </div>
                <div id="pt-proc-list" style="max-height:190px;overflow-y:auto;font-size:12px;">
                  <div class="text-muted" style="font-size:11px;">← 先選擇子標籤</div>
                </div>
                <div id="pt-proc-save-wrap" style="display:none;margin-top:6px;">
                  <button class="btn btn-primary btn-sm" onclick="savePtProcesses()">
                    <i class="fa fa-save"></i> 儲存製程連結
                  </button>
                  <small class="text-muted" style="margin-left:6px;font-size:11px;">勾選後按儲存才會寫入資料庫</small>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Tab 4：備註模板 ── -->
          <div id="qs-tab-note-tmpl" class="tab-pane">
            <div class="row">
              <!-- 左：新增/修改表單 -->
              <div class="col-sm-5">
                <div class="panel panel-default">
                  <div class="panel-heading" style="font-size:13px;"><b id="ntmpl-form-title">新增備註模板</b></div>
                  <div class="panel-body">
                    <input type="hidden" id="ntmpl-edit-id">
                    <div class="form-group">
                      <label>按鈕標籤 *</label>
                      <input type="text" class="form-control input-sm" id="ntmpl-label-input" placeholder="例：付款條件" maxlength="30">
                    </div>
                    <div class="form-group">
                      <label>備註文字 *</label>
                      <textarea class="form-control input-sm" id="ntmpl-text-input" rows="3" placeholder="例：調質HRC {下限}~{上限} 之間，報告另附" maxlength="500"></textarea>
                      <small class="text-muted">用 <code>{變數名}</code> 作為佔位符，點按鈕時會要求填入</small>
                    </div>
                    <div class="form-group">
                      <label>變數定義</label>
                      <div id="ntmpl-vars-list" style="margin-bottom:5px;"></div>
                      <button type="button" class="btn btn-xs btn-default" onclick="addNtmplVar()">
                        <i class="fa fa-plus"></i> 新增變數
                      </button>
                      <small class="text-muted" style="margin-left:6px;">依備註文字中 {} 的順序新增</small>
                    </div>
                    <div class="form-group">
                      <label>自動帶入（依製程類型）</label>
                      <div>
                        <label style="font-weight:normal;margin-right:14px;font-size:12px;">
                          <input type="checkbox" id="ntmpl-auto-full" style="margin-right:4px;">全製程時自動帶入
                        </label>
                        <label style="font-weight:normal;font-size:12px;">
                          <input type="checkbox" id="ntmpl-auto-single" style="margin-right:4px;">單一製程時自動帶入
                        </label>
                      </div>
                    </div>
                    <div class="form-group">
                      <label>排序（數字越小越前）</label>
                      <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control input-sm" id="ntmpl-order-input" value="0">
                    </div>
                    <button class="btn btn-success btn-sm" onclick="saveNoteTemplate()">
                      <i class="fa fa-save"></i> 儲存
                    </button>
                    <button class="btn btn-default btn-sm" onclick="resetNoteTemplateForm()">
                      <i class="fa fa-refresh"></i> 清除
                    </button>
                  </div>
                </div>
              </div>
              <!-- 右：列表 -->
              <div class="col-sm-7">
                <table class="table table-condensed table-bordered table-striped" style="font-size:13px;">
                  <thead><tr><th style="width:24px;"></th><th>按鈕標籤</th><th>備註文字</th><th>自動</th><th>狀態</th><th>操作</th></tr></thead>
                  <tbody id="ntmpl-table-body"></tbody>
                </table>
              </div>
            </div>
          </div>

        </div><!-- /tab-content -->
      </div>
      <div class="modal-footer" style="padding:8px 14px;">
        <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ 客戶管理 Modal ══ -->
<div class="modal fade" id="customerModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      <h4 class="modal-title">客戶資料管理</h4>
    </div>
    <div class="modal-body">
      <div class="panel panel-default"><div class="panel-heading"><b id="customerFormTitle">新增客戶</b></div>
        <div class="panel-body">
          <input type="hidden" id="customer_id_modal">
          <div class="row">
            <div class="col-md-3 form-group"><label>客戶代碼 <span class="text-danger">*</span></label><input type="text" class="form-control" id="customer_id_new" placeholder="新增時必填"></div>
            <div class="col-md-5 form-group"><label>客戶名稱 <span class="text-danger">*</span></label><input type="text" class="form-control" id="customer_name_modal"></div>
            <div class="col-md-4 form-group"><label>電話</label><input type="text" class="form-control" id="customer_tel_modal"></div>
          </div>
          <div class="row">
            <div class="col-md-8 form-group"><label>地址</label><input type="text" class="form-control" id="customer_address_modal"></div>
            <div class="col-md-4 form-group"><label>傳真</label><input type="text" class="form-control" id="customer_fax_modal"></div>
          </div>
          <div class="row">
            <div class="col-md-4 form-group"><label>統一編號</label><input type="text" class="form-control" id="customer_taxid_modal"></div>
            <div class="col-md-4 form-group"><label>聯絡人</label><input type="text" class="form-control" id="customer_contact_modal"></div>
          </div>
          <button type="button" class="btn btn-success btn-sm" onclick="saveCustomer()"><i class="fa fa-save"></i> 儲存</button>
          <button type="button" class="btn btn-default btn-sm" onclick="resetCustomerForm()"><i class="fa fa-refresh"></i> 清除</button>
        </div>
      </div>
      <div id="customerMgmtListSection">
        <table id="customerMgmtTable" class="table table-striped table-bordered table-condensed" style="width:100%;font-size:13px;">
          <thead><tr><th>代碼</th><th>客戶名稱</th><th>電話</th><th>地址</th><th>操作</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
  </div></div>
</div>

<!-- ══ 料號管理 Modal ══ -->
<div class="modal fade" id="partModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      <h4 class="modal-title" id="partFormTitle">新增料號</h4>
    </div>
    <div class="modal-body">
      <form id="part-form-main" onsubmit="return false;">
        <input type="hidden" id="part_d_id_modal">
        <div class="row">
          <div class="col-md-6 form-group">
            <label>料號 <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="part_no_modal" placeholder="料號" required>
          </div>
          <div class="col-md-6 form-group">
            <label>工件種類</label>
            <select class="form-control" id="part_type_modal">
              <option value="N">一般 (General)</option>
              <option value="G">齒輪 (Gear)</option>
              <option value="H">滾刀 (Hob)</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>客戶</label>
          <div class="input-group">
            <input type="text" id="part_client_search_modal" class="form-control" placeholder="輸入代碼或名稱搜尋...">
            <input type="hidden" id="part_customer_id_modal">
            <span class="input-group-btn">
              <button class="btn btn-default" type="button" onclick="$('#part_client_search_modal').trigger('input')"><i class="fa fa-search"></i></button>
            </span>
          </div>
          <div id="part-customer-results" style="position:absolute;z-index:1060;background:white;border:1px solid #ccc;width:90%;max-height:150px;overflow-y:auto;display:none;border-radius:0 0 4px 4px;box-shadow:0 4px 8px rgba(0,0,0,.1);"></div>
        </div>
        <div class="row">
          <div class="col-md-6 form-group">
            <label>版次</label>
            <input type="text" class="form-control" id="part_revision_modal">
          </div>
          <div class="col-md-6 form-group">
            <label>發行日期</label>
            <input type="date" class="form-control" id="part_issue_date_modal">
          </div>
        </div>
        <div class="form-group">
          <label>備註</label>
          <textarea class="form-control" id="part_remark_modal" rows="2"></textarea>
        </div>
        <div id="part-gear-section" style="display:none;border-top:2px solid var(--accent);padding-top:12px;margin-top:8px;">
          <h5 style="color:var(--primary);font-weight:700;margin-bottom:10px;">
            <i class="fa fa-cog"></i> 齒輪詳細資料
            <button type="button" class="btn btn-xs btn-success" id="part-btn-add-gear" style="margin-left:10px;"><i class="fa fa-plus"></i> 新增齒輪</button>
          </h5>
          <div id="part-gear-rows-container"></div>
        </div>
        <div class="ln_solid" style="margin:15px 0;"></div>
        <div style="display:flex;gap:8px;">
          <button type="button" class="btn btn-danger" id="part-btn-delete" style="display:none;" onclick="deletePart()"><i class="fa fa-trash"></i> 刪除</button>
          <button type="button" class="btn btn-default" onclick="resetPartForm()"><i class="fa fa-refresh"></i> 清除/新增</button>
          <button type="button" class="btn btn-primary" onclick="savePart()"><i class="fa fa-save"></i> 儲存</button>
        </div>
      </form>
      <div id="partMgmtBrowseSection" style="display:none;">
        <hr>
        <div class="input-group" style="margin-bottom:8px;max-width:320px;">
          <input type="text" class="form-control input-sm" id="partMgmtSearch" placeholder="搜尋料號、客戶或版次...">
          <span class="input-group-btn"><button class="btn btn-default btn-sm" onclick="searchPartMgmt()"><i class="fa fa-search"></i></button></span>
        </div>
        <div style="max-height:260px;overflow-y:auto;">
          <table class="table table-striped table-bordered table-condensed" style="width:100%;font-size:13px;">
            <thead><tr><th>料號</th><th>客戶</th><th>版次</th><th>工件種類</th><th>操作</th></tr></thead>
            <tbody id="partMgmtTbody"></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
  </div></div>
</div>

<!-- ══ 歷史紀錄 Modal（已刪除 + 列印紀錄 分頁）══ -->
<div class="modal fade" id="historyLogModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:880px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:var(--primary);color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-history" style="margin-right:7px;"></i>歷史紀錄</h4>
    </div>
    <div class="modal-body" style="padding:0;">
      <ul class="nav nav-tabs" id="historyLogTabs" style="padding:0 16px;margin:0;background:#f8f9fa;border-bottom:1px solid #ddd;">
        <li class="active" id="tab-deleted-li">
          <a href="#hlog-deleted" data-toggle="tab" onclick="loadDeletedLog()">
            <i class="fa fa-trash-o"></i> 已刪除報價單
          </a>
        </li>
        <li id="tab-print-li">
          <a href="#hlog-print" data-toggle="tab" onclick="loadPrintLog()">
            <i class="fa fa-print"></i> 列印紀錄
          </a>
        </li>
      </ul>
      <div class="tab-content" style="padding:0;">
        <!-- ── 已刪除分頁 ── -->
        <div class="tab-pane active" id="hlog-deleted">
          <div style="max-height:480px;overflow-y:auto;">
            <table class="table table-condensed table-striped table-bordered" style="font-size:13px;margin:0;">
              <thead style="position:sticky;top:0;background:#f8f9fa;z-index:1;">
                <tr>
                  <th style="width:120px;">報價單號</th>
                  <th style="width:75px;">日期</th>
                  <th style="width:90px;">客戶</th>
                  <th style="width:80px;text-align:right;">金額</th>
                  <th>刪除原因</th>
                  <th style="width:70px;">刪除者</th>
                  <th style="width:130px;">刪除時間</th>
                  <th style="width:90px;"></th>
                </tr>
              </thead>
              <tbody id="deleteLogTbody">
                <tr><td colspan="7" class="text-center text-muted" style="padding:20px;">載入中...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <!-- ── 列印紀錄分頁 ── -->
        <div class="tab-pane" id="hlog-print">
          <div style="max-height:480px;overflow-y:auto;">
            <table class="table table-condensed table-striped" style="font-size:13px;margin:0;">
              <thead style="position:sticky;top:0;background:#f8f9fa;z-index:1;">
                <tr>
                  <th style="width:140px;">報價單號</th>
                  <th>客戶</th>
                  <th style="width:110px;">列印者</th>
                  <th style="width:145px;">列印時間</th>
                </tr>
              </thead>
              <tbody id="printLogTbody">
                <tr><td colspan="4" class="text-center text-muted" style="padding:20px;">請切換至此頁籤載入</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer" style="padding:8px 14px;">
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
    </div>
  </div></div>
</div>

<!-- ══ 刪除快照檢視 Modal ══ -->
<div class="modal fade" id="snapshotModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:700px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:var(--primary);color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
      <h4 class="modal-title" id="snapshotTitle" style="font-size:15px;"><i class="fa fa-file-text-o" style="margin-right:7px;"></i>快照內容</h4>
    </div>
    <div class="modal-body" id="snapshotBody" style="font-size:13px;max-height:520px;overflow-y:auto;padding:14px 18px;"></div>
    <div class="modal-footer" style="padding:8px 14px;">
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
    </div>
  </div></div>
</div>

<!-- ══ 補件 Modal（功能2）══ -->
<div class="modal fade" id="supplementModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:640px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:#F0A24B;color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.85;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-plus" style="margin-right:7px;"></i>補件 — <span id="suppModalQno"></span></h4>
    </div>
    <div class="modal-body" style="font-size:13px;max-height:70vh;overflow-y:auto;padding:14px 18px;">
      <div style="font-size:12px;color:#7a4a00;background:#F7E0BD;border-radius:4px;padding:8px 10px;margin-bottom:10px;">
        追加的附件會先存為暫存，送出後由簽核者審核「是否允許放入此報價單」；通過才正式放入、否決則刪除並通知您。
      </div>
      <div id="suppDrop" style="border:2px dashed #F0A24B;border-radius:6px;padding:18px;text-align:center;color:#a86a1e;cursor:pointer;margin-bottom:10px;">
        <i class="fa fa-cloud-upload" style="font-size:22px;"></i><br>點此選擇檔案，或拖曳到這裡
        <input type="file" id="suppFileInput" multiple style="display:none;">
      </div>
      <div id="suppFileList"></div>
    </div>
    <div class="modal-footer" style="padding:8px 14px;">
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
      <button type="button" class="btn btn-sm" id="suppSubmitBtn" style="background:#F0A24B;color:#fff;font-weight:600;" onclick="submitSupplement()"><i class="fa fa-paper-plane"></i> 送出補件審核</button>
    </div>
  </div></div>
</div>

<!-- ══ 補件待審 Modal（簽核者，功能2）══ -->
<div class="modal fade" id="supplementReviewModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:760px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:var(--primary);color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.85;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-hourglass-half" style="margin-right:7px;"></i>補件待審</h4>
    </div>
    <div class="modal-body" id="suppReviewBody" style="font-size:13px;max-height:70vh;overflow-y:auto;padding:14px 18px;"></div>
    <div class="modal-footer" style="padding:8px 14px;">
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
    </div>
  </div></div>
</div>

<!-- ══ 進站提醒 Modal（被駁回 + 待審補件；點擊跳窗外自動關閉）══ -->
<div class="modal fade" id="pendingAlertModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" style="width:680px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:#F0A24B;color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.85;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-bell" style="margin-right:7px;"></i>待處理提醒</h4>
    </div>
    <div class="modal-body" id="pendingAlertBody" style="font-size:13px;max-height:70vh;overflow-y:auto;padding:14px 18px;"></div>
    <div class="modal-footer" style="padding:8px 14px;">
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
    </div>
  </div></div>
</div>

<!-- ══ 圖片檢視器 Modal（點開附件 → 檢視＋旋轉存檔）══ -->
<div class="modal fade" id="imgViewerModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" style="width:92vw;max-width:1100px;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:#5a4632;color:#fff;padding:8px 14px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.85;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:14px;"><i class="fa fa-image" style="margin-right:6px;"></i><span id="imgViewerName"></span></h4>
    </div>
    <div class="modal-body" style="text-align:center;background:#2b2b2b;padding:10px;max-height:78vh;overflow:auto;">
      <img id="imgViewerImg" src="" alt="" style="max-width:100%;max-height:74vh;background:#fff;">
      <div id="imgViewerLoading" style="display:none;color:#ddd;padding:30px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
    </div>
    <div class="modal-footer" style="padding:8px 14px;text-align:center;">
      <button type="button" class="btn btn-default btn-sm" id="imgViewerRotL"><i class="fa fa-undo"></i> 逆時針旋轉</button>
      <button type="button" class="btn btn-default btn-sm" id="imgViewerRotR"><i class="fa fa-repeat"></i> 順時針旋轉</button>
      <span id="imgViewerHint" style="font-size:11px;color:#999;margin:0 6px;">旋轉會直接存檔（永久生效）</span>
      <a class="btn btn-default btn-sm" id="imgViewerOpen" target="_blank" href="#" title="以原檔在新分頁開啟"><i class="fa fa-external-link"></i> 開新分頁</a>
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
    </div>
  </div></div>
</div>

<!-- ══ 修改紀錄 Modal ══ -->
<div class="modal fade" id="changeLogModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" style="width:640px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:var(--primary);color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-history" style="margin-right:7px;"></i>修改紀錄</h4>
    </div>
    <div class="modal-body" style="padding:0;max-height:520px;overflow-y:auto;">
      <table class="table table-condensed" style="font-size:12px;margin:0;">
        <thead style="position:sticky;top:0;background:#f8f9fa;z-index:1;">
          <tr><th style="width:130px;">時間</th><th style="width:90px;">修改者</th><th>變更欄位</th></tr>
        </thead>
        <tbody id="changeLogTbody">
          <tr><td colspan="3" class="text-center text-muted" style="padding:20px;">載入中...</td></tr>
        </tbody>
      </table>
    </div>
    <div class="modal-footer" style="padding:8px 14px;">
      <small class="text-muted" id="changeLogInfo" style="float:left;line-height:30px;"></small>
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
    </div>
  </div></div>
</div>

<!-- ══ 權限說明 Modal ══ -->
<div class="modal fade" id="permHelpModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" style="width:560px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:var(--primary);color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-key" style="margin-right:7px;"></i>報價單管理 — 權限說明</h4>
    </div>
    <div class="modal-body" style="padding:14px 18px;">
      <p style="font-size:13px;color:#666;margin-bottom:10px;">您目前的角色：<strong style="color:var(--primary);"><?= htmlspecialchars($_perm) ?></strong></p>
      <table class="table table-condensed table-bordered" style="font-size:12px;">
        <thead style="background:#f8f9fa;"><tr><th>可使用功能</th><th style="width:60px;text-align:center;">狀態</th></tr></thead>
        <tbody>
          <?php foreach ($PAGE_FEATURES as $_f): ?>
          <tr>
            <td><?= htmlspecialchars($_f['label']) ?></td>
            <td style="text-align:center;">
              <?php if (_hasF($_f['code'])): ?>
              <i class="fa fa-check" style="color:#27ae60;"></i>
              <?php else: ?>
              <i class="fa fa-times" style="color:#ccc;"></i>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($IS_ADMIN): ?>
      <p style="font-size:11px;color:#888;margin-top:6px;"><i class="fa fa-info-circle" style="color:var(--accent);"></i> 請至 <strong>報價單設定 → 權限設定</strong> 頁籤管理角色與功能。</p>
      <?php endif; ?>
    </div>
    <div class="modal-footer" style="padding:8px 14px;">
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
    </div>
  </div></div>
</div>


<!-- ══ 批次新增料號 Modal ══ -->
<div class="modal fade" id="batchAddModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" style="width:600px;max-width:96vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:var(--primary);color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;"><i class="fa fa-list-ul" style="margin-right:7px;"></i>批次新增料號</h4>
    </div>
    <div class="modal-body" style="padding:14px 18px;">
      <div class="input-group" style="margin-bottom:10px;">
        <input type="text" id="batchPartSearch" class="form-control" placeholder="搜尋料號（輸入至少1字）...">
        <span class="input-group-btn">
          <button class="btn btn-default" type="button" onclick="doBatchPartSearch()"><i class="fa fa-search"></i></button>
        </span>
      </div>
      <div id="batchPartResults" style="max-height:320px;overflow-y:auto;border:1px solid #e0e0e0;border-radius:4px;font-size:13px;">
        <div class="text-muted text-center" style="padding:20px;">請輸入關鍵字搜尋料號</div>
      </div>
      <div style="margin-top:8px;font-size:12px;color:#888;">
        已選取：<span id="batchSelectedCount" style="font-weight:700;color:var(--primary);">0</span> 筆
      </div>
    </div>
    <div class="modal-footer" style="padding:8px 14px;">
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
      <button type="button" class="btn btn-primary btn-sm" onclick="confirmBatchAdd()">
        <i class="fa fa-plus"></i> 新增所選料號
      </button>
    </div>
  </div></div>
</div>

<?php if ($CAN_CHG_CUSTOMER): ?>
<!-- ══ 整張報價單變更客戶（2026-08-28）══════════════════════════════════════
     用 A 客戶報價、接單後客戶要求改掛 B 客戶名稱時使用。改的範圍：報價單表頭 ＋ 各項目
     綁定料號的客戶 ＋ 由本張OP轉出的訂單與其 BOM。料號主檔是全站共用的，所以會先掃描
     「這個料號ID現在還被誰用著」再決定可不可以直接改（說明見跳窗內的灰色說明列）。 -->
<div class="modal fade" id="chgCustomerModal" tabindex="-1" role="dialog" data-backdrop="static">
  <div class="modal-dialog" style="width:940px;max-width:97vw;" role="document"><div class="modal-content">
    <div class="modal-header" style="background:#8a5a2b;color:#fff;padding:12px 18px;">
      <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8;"><span>&times;</span></button>
      <h4 class="modal-title" style="font-size:15px;">
        <i class="fa fa-exchange" style="margin-right:7px;"></i>整張報價單變更客戶
        <span id="chgCustQuoteNo" style="font-weight:400;opacity:.9;margin-left:8px;"></span>
      </h4>
    </div>
    <div class="modal-body" style="padding:0;max-height:72vh;overflow-y:auto;">

      <!-- 使用說明 -->
      <div style="padding:9px 18px;background:#FFF8ED;border-bottom:1px solid #E4D3BC;font-size:12px;color:#6b4a22;line-height:1.7;">
        <strong>這個功能會一次改動下列資料：</strong>
        ①報價單表頭的客戶　②本張單各項目<strong>綁定料號主檔</strong>的客戶　③由本張 OP 轉出／綁定的<strong>訂單</strong>與其 <strong>BOM</strong> 的客戶。<br>
        料號主檔是全站共用的，所以每個料號都會先掃描「這個<strong>料號ID</strong>目前還被哪些單據用著」：
        <span style="color:#1e8449;font-weight:700;">只有本張單在用</span>＝可直接改；
        <span style="color:#a06a1f;font-weight:700;">另有本張OP的訂單／BOM在用</span>＝需二次確認；
        <span style="color:#DD5138;font-weight:700;">被本張OP以外的單據用到</span>（別張報價單／別的訂單／別的BOM／已出貨／已退貨）＝
        <strong>禁止直接改</strong>，請改用下方的「建立新料號」。
      </div>

      <!-- 目標客戶 -->
      <div style="padding:12px 18px;border-bottom:1px solid #eee;">
        <div class="row">
          <div class="col-sm-5">
            <label style="font-size:12px;color:#888;margin-bottom:3px;">目前客戶</label>
            <div id="chgCustFrom" style="font-size:14px;font-weight:700;color:#555;padding:6px 0;">—</div>
          </div>
          <div class="col-sm-7">
            <label style="font-size:12px;color:#888;margin-bottom:3px;">變更為　<span style="color:#DD5138;">*</span></label>
            <input type="text" class="form-control" id="chgCustSearch" autocomplete="off"
                   placeholder="輸入客戶代碼或名稱篩選…" ondblclick="this.value='';$('#chgCustId').val('');chgCustRenderPick();">
            <input type="hidden" id="chgCustId">
            <div id="chgCustPick" style="max-height:170px;overflow-y:auto;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 4px 4px;font-size:13px;display:none;"></div>
            <div id="chgCustPicked" style="margin-top:6px;font-size:13px;display:none;"></div>
          </div>
        </div>
      </div>

      <!-- 掃描結果 -->
      <div id="chgCustScanWrap" style="padding:12px 18px;">
        <div class="text-muted text-center" style="padding:24px;">請先選擇要變更成哪一家客戶</div>
      </div>
    </div>
    <div class="modal-footer" style="padding:8px 14px;">
      <span id="chgCustHint" style="float:left;font-size:12px;color:#888;padding-top:6px;"></span>
      <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
      <button type="button" class="btn btn-sm" id="chgCustCloneBtn" style="display:none;background:#3498db;color:#fff;"
              onclick="chgCustDoClone()" title="本張OP單所有料號各建一筆掛新客戶的新料號，原料號完全不動">
        <i class="fa fa-files-o"></i> 建立新料號並改綁
      </button>
      <button type="button" class="btn btn-sm" id="chgCustApplyBtn" style="display:none;background:#F0A24B;color:#fff;font-weight:600;"
              onclick="chgCustDoApply()">
        <i class="fa fa-check"></i> 確認變更
      </button>
    </div>
  </div></div>
</div>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/jquery-ui-1.10.2.custom.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/eg_stamp.js'); ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?php echo @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js'); ?>"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── 權限常數（由 PHP 注入）──
const CAN_VIEW         = <?= json_encode($CAN_VIEW) ?>;
const CAN_CREATE       = <?= json_encode($CAN_CREATE) ?>;
const CAN_EDIT         = <?= json_encode($CAN_EDIT) ?>;
const CAN_DELETE       = <?= json_encode($CAN_DELETE) ?>;
const CAN_PRINT        = <?= json_encode($CAN_PRINT) ?>;
const CAN_SETTINGS     = <?= json_encode($CAN_SETTINGS) ?>;
const CAN_CLONE        = <?= json_encode($CAN_CLONE) ?>;
const CAN_SIGN         = <?= json_encode($CAN_SIGN) ?>;
const CAN_BATCH_ADD    = <?= json_encode($CAN_BATCH_ADD) ?>;
const CAN_VIEW_DELETED = <?= json_encode($CAN_VIEW_DELETED) ?>;
const CAN_RESTORE      = <?= json_encode($CAN_RESTORE) ?>;
const CAN_VIEW_HISTORY = <?= json_encode($CAN_VIEW_HISTORY) ?>;
const CAN_CHG_CUSTOMER = <?= json_encode($CAN_CHG_CUSTOMER) ?>;
const CURRENT_UID      = <?= json_encode((int)($_SESSION['id'] ?? 0)) ?>;
const IS_ADMIN         = <?= json_encode($IS_ADMIN) ?>;
const PERM_CODE        = <?= json_encode($_perm) ?>;
const MY_USER_ID       = <?= json_encode($_user_id) ?>;

const API_URL      = '../../src/store/Quotation_API.php';
const FILE_API_URL = '../../src/store/Quotation_File_API.php';
const ROLES_API_URL= '../../src/store/Roles_API.php';

let allQuotes      = [];        // 目前年份的報價單陣列
let fileCategories = [];        // 本頁適用的附件類別（依本頁排序；未設定時=全部啟用類別）
let allFileCategories = [];     // 全部啟用中的附件類別（跨頁共用主檔，供名稱查找）
let pageAttachCatIds = null;    // 本頁適用類別 ID 順序（QUOTATION/page_attach_cats；null=全部）
let requiredAttachCats = [];    // 每個料號必備的附件類別 ID 清單
let processTagTree = [];        // 製程標籤樹 [{group_id,group_name,sub_tags:[...]}]
let allYearsData   = null;      // 全年份快取
let isAllYearsMode = false;
let pendingFilterMode = false;  // 待處理單據篩選模式（每次進站預設 false＝全部顯示；篩選報價單本身待審/被駁回）
let tempSaveFilterMode = false; // 暫存未完成篩選模式（只顯示自己暫存中尚未完成的報價單）
let pendingAlertData  = { rejected: [], pending: [] }; // 進站提醒資料（補件被駁回/待審）
let allProcesses   = [];        // [{id, text}]
let allUnits         = [];        // [{unit_id, unit_name, unit_symbol}]
let currentEditId    = null;      // 目前編輯的 quote_id (null = 新增)
let _lockHeartbeat   = null;      // setInterval handle for lock heartbeat
let _editToken     = 0;         // 每次開啟編輯時遞增，捨棄過期的 async 回呼
let _tempUploadQno = null;      // 新建報價有上傳但未儲存的 quote_no
let defaultTolerance = { value: 5, unit: '%' };
let currentUploadPath = '';

// ══════════════════════════════════════════════════════
// 工具函式
// ══════════════════════════════════════════════════════
function formatNumber(num) {
    if (num === null || num === undefined || num === '') return '0';
    const n = Number(num);
    if (isNaN(n)) return '0';
    if (Number.isInteger(n)) return n.toLocaleString('en-US');
    return parseFloat(n.toFixed(10)).toLocaleString('en-US', { maximumFractionDigits: 10 });
}
function escapeHtml(t) {
    if (t === null || t === undefined) return '';
    return $('<div>').text(String(t)).html();
}
function todayStr() { return new Date().toISOString().slice(0, 10); }

// ── 由報價日期計算報價單號前綴（OP + 民國年3碼 + 月2碼 + 日2碼）──
function quoteNoPrefixFromDate(dateStr) {
    if (!dateStr) return '';
    const dt = new Date(dateStr + 'T00:00:00');
    const y  = String(dt.getFullYear() - 1911).padStart(3, '0');
    const m  = String(dt.getMonth() + 1).padStart(2, '0');
    const d  = String(dt.getDate()).padStart(2, '0');
    return 'OP' + y + m + d;
}
// ── 將前綴 + 流水號合併寫入 hidden #quote_no ──
function syncQuoteNo() {
    const prefix = $('#quote_no_prefix').text().trim();
    const seq    = $('#quote_seq').val().trim();
    if (prefix && seq) $('#quote_no').val(prefix + seq.padStart(3, '0'));
}

// ══════════════════════════════════════════════════════
// 初始化
// ══════════════════════════════════════════════════════
$(window).on('resize', adjustLayout);

// 頁面離開（重新整理 / 關閉分頁）時，自動刪除未儲存的臨時附件資料夾
window.addEventListener('beforeunload', function() {
    if (!_tempUploadQno || currentEditId) return; // 已儲存或非新建，不清理
    if (!navigator.sendBeacon) return;             // 舊版瀏覽器不支援，略過
    const fd = new FormData();
    fd.append('action', 'delete_folder');
    fd.append('quote_no', _tempUploadQno);
    navigator.sendBeacon(FILE_API_URL, fd);
});

$(document).ready(function () {
    adjustLayout();          // ★ 初始化高度，消除外層滾動條
    loadFileCategories();    // ★ 載入附件類別（同時自動建 DB 資料表）
    loadPageAttachCats();    // ★ 載入本頁適用附件類別與排序
    loadAutoSortKeys();      // ★ 載入項目自動排序規則（全域設定）
    loadRequiredAttachCats();// ★ 載入每個料號必備的附件類別設定
    initProcessTagTables();  // ★ 自動建立製程標籤三張資料表
    loadProcessTagTree();    // ★ 載入製程標籤樹
    initNoteTemplates();     // ★ 自動建立備註模板資料表並載入快選按鈕
    loadProcesses();
    loadUnits();
    loadQuoteList(<?= $selectedYear ?>);
    loadSupplementAlerts(true);   // 進站提醒：補件被駁回/待審（清單預設仍顯示全部）
    // 切回本分頁時：若正在待處理篩選，重讀報價單清單→他人已簽核/退回的單即時反映（不掛已處理）；提醒視窗開著也刷新
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) return;
        if (pendingFilterMode) { if (isAllYearsMode) { allYearsData = null; loadAllYears(); } else loadQuoteList(<?= $selectedYear ?>); }
        if ($('#pendingAlertModal').hasClass('in')) loadSupplementAlerts(false);
    });
    // 通知點擊深連結：?open_id=quote_id 直接開啟該張報價單檢視畫面（比照CAR/QA的open_id慣例）
    (function () {
        var openId = parseInt(new URLSearchParams(window.location.search).get('open_id'), 10);
        if (openId > 0) openViewMode(openId);
    })();
    // 簽核專屬頁(quotation_approval_view.php)以 window.open 開啟時，這裡是它的 opener——
    // 核准/駁回完成後該分頁會 postMessage 回來，這裡收到才主動重新整理清單/當前檢視畫面，
    // 避免使用者已開著的這頁清單/檢視在另一分頁簽核完後仍顯示舊的審核狀態
    window.addEventListener('message', function (ev) {
        if (!ev.data) return;
        // 補件審核頁(quotation_supplement_view.php)完成核准/駁回 → 更新補件待審徽章與清單、刷新檢視附件
        if (ev.data.type === 'quotation_supplement_done') {
            if (CAN_SIGN) refreshSuppReviewBadge();
            loadSupplementAlerts(false);   // 更新待處理徽章/篩選集合（不重複跳窗）
            if ($('#supplementReviewModal').hasClass('in')) openSupplementReview();
            if (currentEditId) openViewMode(currentEditId);
            return;
        }
        if (ev.data.type !== 'quotation_approval_done') return;
        if (isAllYearsMode) loadAllYears(); else loadQuoteList(<?= $selectedYear ?>);
        if (currentEditId && (!ev.data.quote_id || currentEditId == ev.data.quote_id)) openViewMode(currentEditId);
    });
    loadDefaultTolerance();
    loadUploadPath();
    loadValidDays();        // ★ 載入有效天數設定
    loadAttachDays();       // ★ 載入附件暫存/垃圾自動清除天數設定
    loadPrintApprovalSetting();  // ★ 載入「需審核通過才能列印」開關（列印閘門用）
    initFileUpload();

    // 備註欄自動展高 + 字數計數 + 標籤狀態同步
    $(document).on('input', '#note', function() {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
        $('#note-char-count').text(this.value.length + '/200');
        syncNoteTemplateBtnStates();
    });

    // 搜尋列表（雙擊清空＝同時解除此欄篩選）
    $('#listSearch')
        .on('input', function () { renderQuoteList(allQuotes, $(this).val().trim()); })
        .on('focus', function () { if (this.value) this.select(); })
        .on('dblclick', function () {
            if (!this.value) return;
            this.value = '';
            renderQuoteList(allQuotes, '');
        });

    // 客戶模糊篩選（代碼／名稱）→ 即時縮小客戶下拉選項
    $('#clientFilterSearch')
        .on('input', renderClientFilterOptions)
        .on('focus', function () { if (this.value) this.select(); })
        .on('dblclick', function () {              // 雙擊清空＝連同客戶篩選一起解除
            if (!this.value && !$('#clientFilterSel').val()) return;
            this.value = '';
            $('#clientFilterSel').val('');
            renderClientFilterOptions();
            renderQuoteList(allQuotes, $('#listSearch').val().trim());
        })
        .on('keydown', function (e) {
            if (e.key === 'Enter' || e.key === 'ArrowDown') {   // Enter/↓ 跳到客戶下拉
                e.preventDefault();
                $('#clientFilterSel').focus();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                clearClientFilterSearch();
            }
        });

    // 幣別變更 → 重算
    $('#currency').on('change', calculateTotal);

    // 報價項目列：Enter / 方向鍵跨欄 / 跨列導航（類 Excel）
    $(document).on('keydown', '#quoteItemsTable .item-row input:not([type=hidden])', function(e) {
        const key = e.key;
        if (!['Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(key)) return;

        const $row    = $(this).closest('tr.item-row');
        const $inputs = $row.find('input:not([type=hidden]):not([disabled]):visible');
        const idx     = $inputs.index(this);

        // 左右：只在游標位於邊界時才跨欄；Enter 等同右移
        if (key === 'Enter' || key === 'ArrowRight') {
            const atEnd = (key === 'Enter') || (this.selectionEnd === this.value.length);
            if (!atEnd) return;
            e.preventDefault();
            const $next = $inputs.eq(idx + 1);
            if ($next.length) { $next.focus().select(); return; }
        }
        if (key === 'ArrowLeft') {
            if (this.selectionStart !== 0) return;
            e.preventDefault();
            const $prev = $inputs.eq(idx - 1);
            if ($prev.length) { $prev.focus(); $prev[0].setSelectionRange($prev[0].value.length, $prev[0].value.length); return; }
        }

        // 上下：跨列，找同欄位置的 input
        if (key === 'ArrowUp' || key === 'ArrowDown') {
            e.preventDefault();
            const $rows   = $('#quoteItemsTable > tbody > tr.item-row:visible');
            const rowIdx  = $rows.index($row);
            const adjIdx  = key === 'ArrowUp' ? rowIdx - 1 : rowIdx + 1;

            // ★ 可增列表格鐵則：最末列按 ↓ 自動新增一列並跳到同欄
            if (key === 'ArrowDown' && adjIdx >= $rows.length) {
                if (addItemRow() === false) return;   // 已達上限時不新增
                const $newRow    = $('#quoteItemsTable > tbody > tr.item-row:visible').last();
                const $newInputs = $newRow.find('input:not([type=hidden]):not([disabled]):visible');
                const $t = $newInputs.eq(Math.min(idx, $newInputs.length - 1));
                if ($t.length) $t.focus();
                return;
            }
            // ★ 完全空白尚未輸入的列（料號/製程/料號備註/數量/單價/金額全空）按 ↑ 自動刪除該列並跳回上一列
            if (key === 'ArrowUp' && rowIdx > 0 && quoteRowIsBlank($row)) {
                removeItemRow(this);
                const $left   = $('#quoteItemsTable > tbody > tr.item-row:visible');
                const $pInputs = $left.eq(rowIdx - 1).find('input:not([type=hidden]):not([disabled]):visible');
                const $t = $pInputs.eq(Math.min(idx, $pInputs.length - 1));
                if ($t.length) $t.focus().select();
                return;
            }

            if (adjIdx < 0 || adjIdx >= $rows.length) return;
            const $adjRow    = $rows.eq(adjIdx);
            const $adjInputs = $adjRow.find('input:not([type=hidden]):not([disabled]):visible');
            const $target    = $adjInputs.eq(Math.min(idx, $adjInputs.length - 1));
            if ($target.length) $target.focus().select();
        }
    });

    // ── 階梯區間表：Enter 跳欄、↑↓ 跨階同欄、末階↓自動加一階、全空白階↑自動刪除（比照項目表可增列鐵則）──
    $(document).on('keydown', '.tier-tbody tr.tier-input-row input', function (e) {
        const key = e.key;
        if (!['Enter', 'ArrowUp', 'ArrowDown'].includes(key)) return;
        const $tr     = $(this).closest('tr.tier-input-row');
        const $tbody  = $tr.closest('.tier-tbody');
        const $inputs = $tr.find('input:not([readonly]):visible');
        const idx     = $inputs.index(this);

        if (key === 'ArrowUp' || key === 'ArrowDown') {
            e.preventDefault();
            const $rows  = $tbody.find('tr.tier-input-row');
            const rowIdx = $rows.index($tr);
            const adjIdx = key === 'ArrowUp' ? rowIdx - 1 : rowIdx + 1;

            // 末階按 ↓ → 自動新增一階並跳到同欄
            if (key === 'ArrowDown' && adjIdx >= $rows.length) {
                addTierRow(this);
                const $ni = $tbody.find('tr.tier-input-row').last().find('input:not([readonly]):visible');
                const $t  = $ni.eq(Math.min(idx, $ni.length - 1));
                if ($t.length) $t.focus();
                return;
            }
            // 完全空白階（最小量/最大量/單價/容差值/容差備註全空）按 ↑ → 自動刪除該階並跳回上一階
            if (key === 'ArrowUp' && rowIdx > 0 && tierRowIsBlank($tr)) {
                deleteTierRow($tr.find('.btn-del-tier')[0]);
                const $pi = $tbody.find('tr.tier-input-row').eq(rowIdx - 1).find('input:not([readonly]):visible');
                const $t  = $pi.eq(Math.min(idx, $pi.length - 1));
                if ($t.length) $t.focus().select();
                return;
            }
            if (adjIdx < 0 || adjIdx >= $rows.length) return;
            const $ai = $rows.eq(adjIdx).find('input:not([readonly]):visible');
            const $t  = $ai.eq(Math.min(idx, $ai.length - 1));
            if ($t.length) $t.focus().select();
            return;
        }

        // Enter：跳同階下一欄；最後一欄跳下一階第一欄
        e.preventDefault();
        const $next = $inputs.eq(idx + 1);
        if ($next.length) { $next.focus().select(); return; }
        const $rows  = $tbody.find('tr.tier-input-row');
        const rowIdx = $rows.index($tr);
        if (rowIdx < $rows.length - 1) {
            const $ni = $rows.eq(rowIdx + 1).find('input:not([readonly]):visible');
            if ($ni.length) $ni.first().focus().select();
        }
    });

    // ── 階梯排序≥2 的最小量：留空時聚焦自動帶入「上一階最大量＋1」（帶入後全選、可直接覆寫）。
    //    與容差不衝突——容差是交貨數量誤差（訂單對價時才擴大比對區間），不改變計價區間邊界。──
    $(document).on('focusin', '.tier-tbody tr.tier-input-row .tier-qty-min', function () {
        if (($(this).val() || '').trim() !== '') return;
        const $tr    = $(this).closest('tr.tier-input-row');
        const $rows  = $(this).closest('.tier-tbody').find('tr.tier-input-row');
        const rowIdx = $rows.index($tr);
        if (rowIdx <= 0) return;
        const prevMax = parseFloat($rows.eq(rowIdx - 1).find('.tier-qty-max').val());
        if (isNaN(prevMax) || prevMax <= 0) return;
        $(this).val(Math.round(prevMax) + 1).trigger('input');
        const el = this;
        setTimeout(() => { try { el.select(); } catch (err) {} }, 0);
    });

    // ── 流水號（後3碼）：只允許數字，blur 時補零 ──
    $(document).on('input', '#quote_seq', function() {
        this.value = this.value.replace(/\D/g, '').slice(0, 3);
        syncQuoteNo();
    });
    $(document).on('blur', '#quote_seq', function() {
        if (this.value) this.value = this.value.padStart(3, '0');
        syncQuoteNo();
    });

    // ── 報價日：變更後更新前綴並取新流水號 ──
    $('#quote_date').on('change', function() {
        autoFillValidUntil();
        const dateVal = this.value;
        if (!dateVal) return;
        const prefix = quoteNoPrefixFromDate(dateVal);
        $('#quote_no_prefix').text(prefix);
        $.get(API_URL, { action:'get_quote_no_for_date', date: dateVal }, res => {
            if (res.success) {
                $('#quote_seq').val(res.quote_no.slice(-3));
                syncQuoteNo();
            }
        });
    });

    // 階梯 qty/price 即時計算
    $(document).on('input', '.tier-qty-min, .tier-unit-price', function () {
        const $r  = $(this).closest('tr');
        const q   = Math.round(parseFloat($r.find('.tier-qty-min').val()) || 0);
        const p   = parseFloat($r.find('.tier-unit-price').val()) || 0;
        $r.find('.tier-amount').val(formatNumber(q * p));
        calculateTotal();
    });
    $(document).on('change', '.tier-qty-min', function () {
        autoFixTierOverlap($(this).closest('.tier-tbody'));
        calculateTotal();
    });

    // 傳統 qty/price 即時計算
    $(document).on('input', '.quantity, .unit-price', function () {
        const $row = $(this).closest('tr.item-row');
        if ($row.data('is-tiered')) return;
        const q = parseFloat($row.find('.quantity').val()) || 0;
        const p = parseFloat($row.find('.unit-price').val()) || 0;
        $row.find('.amount').val(formatNumber(q * p));
        calculateTotal();
    });

    // 點擊空白關閉 autocomplete
    $(document).on('click', function (e) {
        if (false) {} // 舊面板已移除
        if (!$(e.target).closest('#client_name, #client-suggestions').length) {
            $('#client-suggestions').hide().empty();
        }
        if (!$(e.target).closest('.part-search, .part-suggestions').length) {
            $('.part-suggestions').hide().empty();
        }
        if (!$(e.target).closest('#part_client_search_modal, #part-customer-results').length) {
            $('#part-customer-results').hide();
        }
    });

    // 客戶管理 btn-edit-customer 委派
    $(document).on('click', '.btn-edit-customer', function () {
        editCustomer($(this).data('customer'));
        $('html,body').animate({ scrollTop: $('#customerFormTitle').offset().top - 80 }, 200);
    });
    // 料號管理 btn-edit-part 委派
    $(document).on('click', '.btn-edit-part', function () {
        loadPartToModal($(this).data('part').d_id);
        $('html,body').animate({ scrollTop: $('#partFormTitle').offset().top - 80 }, 200);
    });
    // 工件種類切換
    $(document).on('change', '#part_type_modal', function () {
        if ($(this).val() === 'G') {
            $('#part-gear-section').slideDown();
            if (!$('#part-gear-rows-container').children().length) addPartGearRow();
        } else {
            $('#part-gear-section').slideUp();
        }
    });
    $(document).on('click', '#part-btn-add-gear', function () { addPartGearRow(); });
    // 客戶搜尋（料號 Modal 內）
    $(document).on('input', '#part_client_search_modal', function () {
        const kw = $(this).val().trim();
        if (kw.length < 1) { $('#part-customer-results').hide(); return; }
        $.get(API_URL, { action: 'search_data', type: 'customer', term: kw }, res => {
            if (res.success && res.data.length > 0) {
                let html = '';
                res.data.forEach(c => {
                    html += `<div class="part-customer-item" data-id="${escapeHtml(c.customer_id)}" data-name="${escapeHtml(c.customer)}"
                        style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #eee;">
                        <strong>${escapeHtml(c.customer_id)}</strong> ${escapeHtml(c.customer)}</div>`;
                });
                $('#part-customer-results').html(html).show();
            } else {
                $('#part-customer-results').hide();
            }
        });
    });
    $(document).on('click', '.part-customer-item', function () {
        $('#part_customer_id_modal').val($(this).data('id'));
        $('#part_client_search_modal').val($(this).data('name'));
        $('#part-customer-results').hide();
    });
    // 齒輪螺旋角模式切換
    $(document).on('click', '.btn-mode-dec', function () {
        const $g = $(this).closest('.helix-angle-group');
        $g.find('.mode-decimal').show(); $g.find('.mode-dms').hide();
        $(this).addClass('active').siblings().removeClass('active');
    });
    $(document).on('click', '.btn-mode-dms', function () {
        const $g = $(this).closest('.helix-angle-group');
        $g.find('.mode-decimal').hide(); $g.find('.mode-dms').css('display', 'flex');
        $(this).addClass('active').siblings().removeClass('active');
    });
    $(document).on('input', '.gear-helix-val', function () {
        const $g = $(this).closest('.helix-angle-group');
        $g.find('.hidden-helix-val').val($(this).val());
        $g.find('.hidden-helix-str').val($(this).val());
    });
    $(document).on('input', '.dms-d, .dms-m, .dms-s', function () {
        const $g = $(this).closest('.helix-angle-group');
        const d = parseFloat($g.find('.dms-d').val()) || 0;
        const m = parseFloat($g.find('.dms-m').val()) || 0;
        const s = parseFloat($g.find('.dms-s').val()) || 0;
        $g.find('.hidden-helix-val').val((d + m/60 + s/3600).toFixed(6));
        $g.find('.hidden-helix-str').val(`${d}°${m}'${s}"`);
    });
    $(document).on('blur', '.gear-module', function () {
        let v = $(this).val().trim().toUpperCase();
        if (v !== '' && !isNaN(v.charAt(0))) $(this).val('M' + v);
        else $(this).val(v);
    });
    $(document).on('change', '.gear-type', function () {
        const $row = $(this).closest('.part-gear-row');
        if ($(this).val() === '螺旋') $row.find('.helix-angle-group').slideDown();
        else $row.find('.helix-angle-group').slideUp();
    });
});

// ══════════════════════════════════════════════════════
// 製程載入
// ══════════════════════════════════════════════════════
function loadProcesses() {
    $.get(API_URL, { action: 'get_processes' }, res => {
        if (res.success) {
            allProcesses = res.data.map(p => ({
                id: p.ProcessNo,
                text: `${p.ProcessNo} - ${p.ProcessName}`
            }));
        }
    });
}
function loadUnits() {
    $.get(API_URL, { action: 'get_units' }, res => {
        if (res.success) allUnits = res.units || [];
    });
}
function buildUnitOptions(selectedUnit) {
    const sel = selectedUnit || 'PCS';
    let opts = '';
    allUnits.forEach(u => {
        const val = u.unit_symbol || u.unit_name;
        opts += `<option value="${escapeHtml(val)}" ${val === sel ? 'selected' : ''}>${escapeHtml(val)}</option>`;
    });
    // 如果 allUnits 尚未載入或沒有包含 sel，補一個選項
    if (!opts || !allUnits.some(u => (u.unit_symbol || u.unit_name) === sel)) {
        opts = `<option value="${escapeHtml(sel)}" selected>${escapeHtml(sel)}</option>` + opts;
    }
    return opts;
}

// ══════════════════════════════════════════════════════
// 列表載入 & 渲染
// ══════════════════════════════════════════════════════
// 動態計算 split-wrap 高度，消除外層滾動條
// ══════════════════════════════════════════════════════
// 載入附件類別（自動建表 + 初始資料）
// ══════════════════════════════════════════════════════
function loadFileCategories() {
    $.get(FILE_API_URL, { action: 'get_categories' }, res => {
        if (res.success) { allFileCategories = res.categories || []; applyPageCatScope(); }
    });
}

// ══════════════════════════════════════════════════════
// 製程標籤 — 建表 + 載入
// ══════════════════════════════════════════════════════
function initProcessTagTables() {
    $.post(API_URL, { action: 'init_process_tags' });
}
function loadProcessTagTree(cb) {
    $.get(API_URL, { action: 'get_process_tag_tree' }, res => {
        if (res.success) {
            // PHP PDO 預設以字串回傳數字欄位，統一轉整數確保 === 比對正確
            processTagTree = (res.tree || []).map(g => ({
                ...g,
                group_id:   parseInt(g.group_id),
                sort_order: parseInt(g.sort_order) || 0,
                sub_tags:   (g.sub_tags || []).map(st => ({
                    ...st,
                    sub_tag_id: parseInt(st.sub_tag_id),
                    group_id:   parseInt(st.group_id),
                    sort_order: parseInt(st.sort_order) || 0,
                    process_nos: (st.process_nos || []).map(Number)
                }))
            }));
        }
        if (cb) cb();
    });
}

// ══════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════
// ── 備註模板 CRUD（設定頁 Tab 4）──────────────────────
// ══════════════════════════════════════════════════════

// ─ 變數列管理 ─
// 料號標籤快取
let _partLabels = null;
function loadPartLabels(cb) {
    if (_partLabels) { cb(_partLabels); return; }
    $.get(API_URL, { action:'get_part_labels' }, res => {
        _partLabels = res.success ? res.labels : [];
        cb(_partLabels);
    });
}

function buildNtmplVarRow(v) {
    const key      = v ? escapeHtml(v.key||'') : '';
    const hint     = v ? escapeHtml(v.hint||'') : '';
    const vtype    = v ? (v.var_type||'text') : 'text';
    const labelId  = v ? (v.label_id||'') : '';
    const isLabel  = vtype === 'label_pick';
    // 標籤選擇器 options（同步建立時可能還沒有資料，改用 data-label-id 動態載入）
    return `<div class="ntmpl-var-row" style="display:flex;gap:4px;margin-bottom:4px;align-items:flex-start;flex-wrap:wrap;">
        <span style="color:#888;font-size:13px;padding-top:4px;">{</span>
        <input type="text" class="form-control input-sm ntmpl-var-key" style="width:55px;" placeholder="變數名" maxlength="20" value="${key}">
        <span style="color:#888;font-size:13px;padding-top:4px;">}</span>
        <select class="form-control input-sm ntmpl-var-type" style="width:100px;" onchange="onNtmplVarTypeChange(this)">
            <option value="text"       ${vtype==='text'       ?'selected':''}>文字輸入</option>
            <option value="label_pick" ${vtype==='label_pick' ?'selected':''}>料號標籤選</option>
        </select>
        <input type="text" class="form-control input-sm ntmpl-var-hint" style="flex:1;min-width:80px;${isLabel?'display:none;':''}" placeholder="提示文字" maxlength="30" value="${hint}">
        <select class="form-control input-sm ntmpl-var-label" style="flex:1;min-width:100px;${!isLabel?'display:none;':''}" data-selected="${labelId}">
            <option value="">載入中...</option>
        </select>
        <button type="button" class="btn btn-xs btn-danger" onclick="$(this).closest('.ntmpl-var-row').remove()">
            <i class="fa fa-times"></i>
        </button>
    </div>`;
}
function onNtmplVarTypeChange(sel) {
    const $row = $(sel).closest('.ntmpl-var-row');
    const isLabel = $(sel).val() === 'label_pick';
    $row.find('.ntmpl-var-hint').toggle(!isLabel);
    const $lsel = $row.find('.ntmpl-var-label');
    $lsel.toggle(isLabel);
    if (isLabel && $lsel.find('option').length <= 1) {
        loadPartLabels(labels => {
            let html = '<option value="">選擇標籤（選填）</option>';
            labels.forEach(l => html += `<option value="${l.label_id}">${escapeHtml(l.label_name)}</option>`);
            $lsel.html(html);
        });
    }
}
function addNtmplVar() {
    const $row = $(buildNtmplVarRow(null));
    $('#ntmpl-vars-list').append($row);
}
function getNtmplVars() {
    const vars = [];
    $('.ntmpl-var-row').each(function() {
        const key    = $(this).find('.ntmpl-var-key').val().trim();
        const vtype  = $(this).find('.ntmpl-var-type').val();
        const hint   = $(this).find('.ntmpl-var-hint').val().trim();
        const lid    = $(this).find('.ntmpl-var-label').val();
        if (key) vars.push({ key, hint, var_type: vtype || 'text', label_id: lid ? parseInt(lid) : null });
    });
    return vars;
}
function renderNtmplVarRows(vars) {
    $('#ntmpl-vars-list').empty();
    if (!vars || !vars.length) return;
    // 先載入標籤清單，再繪製
    const hasLabel = vars.some(v => v.var_type === 'label_pick');
    if (hasLabel) {
        loadPartLabels(labels => {
            vars.forEach(v => {
                const $row = $(buildNtmplVarRow(v));
                const isLabel = v.var_type === 'label_pick';
                if (isLabel) {
                    let html = '<option value="">選擇標籤（選填）</option>';
                    labels.forEach(l => {
                        const sel = l.label_id == v.label_id ? 'selected' : '';
                        html += `<option value="${l.label_id}" ${sel}>${escapeHtml(l.label_name)}</option>`;
                    });
                    $row.find('.ntmpl-var-label').html(html).show();
                    $row.find('.ntmpl-var-hint').hide();
                }
                $('#ntmpl-vars-list').append($row);
            });
        });
    } else {
        vars.forEach(v => $('#ntmpl-vars-list').append(buildNtmplVarRow(v)));
    }
}

// ─ 套用模板（含變數 Swal）─
function applyNoteTemplate(text, vars, onApplied) {
    if (!vars || !vars.length) {
        const cur = $('#note').val().trim();
        $('#note').val(cur ? cur + '　' + text : text).trigger('input');
        if (onApplied) onApplied();
        return;
    }
    // 先取得所有 label_pick 變數需要的子標籤，再建 Swal
    const labelFetches = vars.map((v, i) => {
        if (v.var_type !== 'label_pick') return Promise.resolve(null);
        return new Promise(resolve => {
            if (v.label_id) {
                $.get(API_URL, { action:'get_part_label_subs', label_id: v.label_id }, res => {
                    resolve(res.success ? res.subs : []);
                });
            } else {
                loadPartLabels(labels => resolve(labels.map(l => ({ sub_id: l.label_id, sub_name: l.label_name }))));
            }
        });
    });
    Promise.all(labelFetches).then(subLists => {
    let html = '<div style="text-align:left;padding:0 4px;">';
    vars.forEach((v, i) => {
        const prompt = v.hint ? `${v.hint}（{${escapeHtml(v.key)}}）` : `{${escapeHtml(v.key)}}`;
        html += `<div style="margin-bottom:10px;"><label style="font-size:13px;font-weight:600;margin-bottom:3px;">${prompt}</label>`;
        if (v.var_type === 'label_pick' && subLists[i]) {
            html += `<select id="swal-ntvar-${i}" class="swal2-input" style="margin:0;width:100%;height:34px;">
                <option value="">— 請選擇 —</option>`;
            subLists[i].forEach(s => {
                if (s.options && s.options.length) {
                    // 有孫子標籤：用 optgroup 分群
                    html += `<optgroup label="${escapeHtml(s.sub_name)}">`;
                    s.options.forEach(opt => { html += `<option value="${escapeHtml(opt)}">${escapeHtml(opt)}</option>`; });
                    html += `</optgroup>`;
                } else {
                    html += `<option value="${escapeHtml(s.sub_name)}">${escapeHtml(s.sub_name)}</option>`;
                }
            });
            html += `</select>`;
        } else {
            html += `<input id="swal-ntvar-${i}" class="swal2-input" style="margin:0;width:100%;" placeholder="請輸入 ${escapeHtml(v.hint || v.key)}">`;
        }
        html += `</div>`;
    });
    html += '</div>';
    Swal.fire({
        title: '請填入變數值',
        html,
        showCancelButton: true,
        confirmButtonText: '確定帶入',
        cancelButtonText: '取消',
        focusConfirm: false,
        didOpen: () => {
            vars.forEach((v, i) => {
                const el = document.getElementById('swal-ntvar-' + i);
                if (!el) return;
                if (v.var_type === 'label_pick') {
                    // 下拉選單：選完後自動跳下一個欄位
                    el.addEventListener('change', function() {
                        if (!this.value) return;
                        const next = document.getElementById('swal-ntvar-' + (i + 1));
                        if (next) next.focus(); else Swal.clickConfirm();
                    });
                } else {
                    el.addEventListener('keydown', function(e) {
                        if (e.key !== 'Enter') return;
                        e.preventDefault();
                        const next = document.getElementById('swal-ntvar-' + (i + 1));
                        if (next) next.focus(); else Swal.clickConfirm();
                    });
                }
            });
        },
        preConfirm: () => {
            const vals = {};
            for (let i = 0; i < vars.length; i++) {
                const v = document.getElementById('swal-ntvar-' + i).value.trim();
                if (!v) { Swal.showValidationMessage(`請填入 ${vars[i].hint || vars[i].key}`); return false; }
                vals[vars[i].key] = v;
            }
            return vals;
        }
    }).then(r => {
        if (!r.isConfirmed) return;
        let result = text;
        Object.entries(r.value).forEach(([k, v]) => {
            result = result.split('{' + k + '}').join(v);
        });
        const cur = $('#note').val().trim();
        $('#note').val(cur ? cur + '　' + result : result).trigger('input');
        if (onApplied) onApplied();
    });
    }); // end Promise.all.then
}

// ─ 模板資料 Map（避免引號衝突）─
let _ntmplMap = {};   // id → { label, note_text, sort_order, vars }

// ─ 載入快選按鈕 ─
function initNoteTemplates() {
    $.post(API_URL, { action: 'init_note_templates' }, res => {
        if (res.success) loadNoteTemplateBtns();
    });
}
function loadNoteTemplateBtns() {
    $.get(API_URL, { action: 'get_note_templates' }, res => {
        if (!res.success) return;
        const $wrap = $('#note-tmpl-btns');
        if (!res.templates.length) { $wrap.empty(); return; }
        let html = '';
        res.templates.forEach(t => {
            let vars = [];
            try { vars = JSON.parse(t.variables || '[]'); } catch(e) {}
            _ntmplMap[t.id] = {
                label: t.label, note_text: t.note_text, sort_order: parseInt(t.sort_order) || 0,
                vars, auto_for_full: !!+t.auto_for_full, auto_for_single: !!+t.auto_for_single
            };
            const icon = vars.length ? ' <i class="fa fa-pencil-square-o" style="font-size:10px;opacity:.55;"></i>' : '';
            const autoIcon = (t.auto_for_full || t.auto_for_single)
                ? ' <i class="fa fa-magic" style="font-size:9px;opacity:.5;" title="自動帶入"></i>' : '';
            html += `<button type="button" class="btn btn-xs btn-default note-tmpl-btn" style="margin:0 3px 3px 0;"
                data-ntid="${t.id}">${escapeHtml(t.label)}${icon}${autoIcon}</button>`;
        });
        $wrap.html(html);
        syncNoteTemplateBtnStates();
        $wrap.off('click', '.note-tmpl-btn').on('click', '.note-tmpl-btn', function() {
            const $btn = $(this);
            const tmpl = _ntmplMap[$btn.data('ntid')];
            if (tmpl) applyNoteTemplate(tmpl.note_text, tmpl.vars, () => $btn.addClass('nt-applied'));
        });
    });
}
// 掃描備註欄，已帶入的模板按鈕標綠
function syncNoteTemplateBtnStates() {
    const noteVal = $('#note').val();
    $('#note-tmpl-btns .note-tmpl-btn').each(function() {
        const tmpl = _ntmplMap[$(this).data('ntid')];
        if (!tmpl || tmpl.vars.length > 0) return; // 變數型模板跳過
        $(this).toggleClass('nt-applied', noteVal.includes(tmpl.note_text));
    });
}

// ─ 依製程類型自動帶入備註 ─
function applyProcTypeNotes() {
    let hasFull = false, hasSingle = false;
    $('.item-row').each(function() {
        const gtype  = $(this).find('.proc-group-type-hidden').val();
        const hasProc = !!$(this).find('.process-hidden').val().trim();
        if (!hasProc) return;
        if (gtype === 'full_process') hasFull = true;
        else hasSingle = true;
    });
    if (!hasFull && !hasSingle) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'訂單內無已選製程', showConfirmButton:false, timer:2000 });
        return;
    }
    // 收集符合條件的模板（依 sort_order 排序）
    const applicable = Object.values(_ntmplMap)
        .filter(t => (hasFull && t.auto_for_full) || (hasSingle && t.auto_for_single))
        .sort((a, b) => a.sort_order - b.sort_order);
    if (!applicable.length) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'無設定自動帶入的備註模板', showConfirmButton:false, timer:2000 });
        return;
    }
    const chinesePunct = /[。！？；，、…]/;
    const typeHint = [hasFull ? '全製程' : '', hasSingle ? '單一製程' : ''].filter(Boolean).join('＋');

    // 依序處理：有變數的模板逐一跳窗填值，全部完成後合併追加
    function appendSegment(text) {
        const cur = $('#note').val().trim();
        if (!cur) { $('#note').val(text).trigger('input'); return; }
        const lastChar = cur.slice(-1);
        $('#note').val(cur + (chinesePunct.test(lastChar) ? '' : '；') + text).trigger('input');
    }
    function processNext(idx, segments) {
        if (idx >= applicable.length) {
            // 全部完成，末尾補句號
            const v = $('#note').val();
            if (v && !chinesePunct.test(v.slice(-1))) $('#note').val(v + '。').trigger('input');
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:`已帶入 ${typeHint} 備註`, showConfirmButton:false, timer:2000 });
            return;
        }
        const t = applicable[idx];
        if (!t.vars || !t.vars.length) {
            // 無變數，直接追加
            appendSegment(t.note_text);
            processNext(idx + 1, segments);
        } else {
            // 有變數：彈窗填值
            let html = '<div style="text-align:left;padding:0 4px;">';
            t.vars.forEach((v, i) => {
                const prompt = v.hint ? `${v.hint}（{${escapeHtml(v.key)}}）` : `{${escapeHtml(v.key)}}`;
                html += `<div style="margin-bottom:10px;"><label style="font-size:13px;font-weight:600;">${prompt}</label>
                    <input id="swal-ntvar-${i}" class="swal2-input" style="margin:0;width:100%;" placeholder="${escapeHtml(v.hint||v.key)}"></div>`;
            });
            html += `</div><p style="font-size:11px;color:#888;margin-top:6px;">模板：${escapeHtml(t.label)}</p>`;
            Swal.fire({
                title:'請填入變數值',
                html,
                showCancelButton:true,
                confirmButtonText:'帶入',
                cancelButtonText:'跳過',
                focusConfirm:false,
                didOpen: () => {
                    t.vars.forEach((v, i) => {
                        const el = document.getElementById('swal-ntvar-'+i);
                        if (!el) return;
                        el.addEventListener('keydown', e => {
                            if (e.key !== 'Enter') return;
                            e.preventDefault();
                            const next = document.getElementById('swal-ntvar-'+(i+1));
                            if (next) next.focus(); else Swal.clickConfirm();
                        });
                    });
                    document.getElementById('swal-ntvar-0') && document.getElementById('swal-ntvar-0').focus();
                },
                preConfirm: () => {
                    const vals = {};
                    for (let i = 0; i < t.vars.length; i++) {
                        const v = document.getElementById('swal-ntvar-'+i).value.trim();
                        if (!v) { Swal.showValidationMessage(`請填入 ${t.vars[i].hint||t.vars[i].key}`); return false; }
                        vals[t.vars[i].key] = v;
                    }
                    return vals;
                }
            }).then(r => {
                if (r.isConfirmed) {
                    let text = t.note_text;
                    Object.entries(r.value).forEach(([k, v]) => { text = text.split('{'+k+'}').join(v); });
                    appendSegment(text);
                }
                // 無論確定或跳過都繼續下一個
                processNext(idx + 1, segments);
            });
        }
    }
    processNext(0, []);
}

// ─ 設定頁列表 ─
function loadAllNoteTemplates() {
    $.get(API_URL, { action: 'get_all_note_templates' }, res => {
        if (!res.success) return;
        res.templates.forEach(t => {
            let vars = [];
            try { vars = JSON.parse(t.variables || '[]'); } catch(e) {}
            _ntmplMap[t.id] = {
                label: t.label, note_text: t.note_text, sort_order: parseInt(t.sort_order) || 0,
                vars, auto_for_full: !!+t.auto_for_full, auto_for_single: !!+t.auto_for_single
            };
        });
        renderNoteTemplateTable(res.templates);
    });
}
function renderNoteTemplateTable(tmpls) {
    let html = '';
    tmpls.forEach(t => {
        let vars = [];
        try { vars = JSON.parse(t.variables || '[]'); } catch(e) {}
        const badge      = t.is_active == 1 ? '<span class="label label-success">啟用</span>' : '<span class="label label-default">停用</span>';
        const labelEsc   = escapeHtml(t.label);
        const textEsc    = escapeHtml(t.note_text);
        const varsBadge  = vars.length ? `<span class="label label-info" style="margin-left:3px;">${vars.length} 變數</span>` : '';
        const autoBadges = [
            +t.auto_for_full   ? '<span class="label label-warning" title="全製程時自動帶入">全</span>'   : '',
            +t.auto_for_single ? '<span class="label label-primary" title="單一製程時自動帶入">單</span>' : ''
        ].join('');
        html += `<tr data-ntid="${t.id}" draggable="false" style="white-space:nowrap;">
            <td style="width:20px;cursor:grab;color:#bbb;text-align:center;" class="ntmpl-drag-handle">&#9776;</td>
            <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;" title="${labelEsc}">${labelEsc}${varsBadge}</td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="${textEsc}">${textEsc}</td>
            <td style="text-align:center;">${autoBadges || '<span style="color:#ccc;">—</span>'}</td>
            <td style="text-align:center;">${badge}</td>
            <td>
                <button class="btn btn-xs btn-warning" data-ntid="${t.id}" onclick="editNoteTemplate($(this).data('ntid'))">
                    <i class="fa fa-pencil"></i>
                </button>
                <button class="btn btn-xs ${t.is_active==1?'btn-danger':'btn-default'}" onclick="toggleNoteTemplate(${t.id})" title="${t.is_active==1?'停用':'啟用'}">
                    <i class="fa fa-${t.is_active==1?'ban':'check'}"></i>
                </button>
            </td>
        </tr>`;
    });
    $('#ntmpl-table-body').html(html || '<tr><td colspan="6" class="text-center text-muted">無資料</td></tr>');
    initNtmplTableDrag();
}
function initNtmplTableDrag() {
    const tbody = document.querySelector('#ntmpl-table-body');
    if (!tbody) return;
    let dragging = null;
    tbody.querySelectorAll('tr').forEach(tr => {
        const handle = tr.querySelector('.ntmpl-drag-handle');
        if (!handle) return;
        handle.addEventListener('mousedown', () => tr.setAttribute('draggable','true'));
        tr.addEventListener('dragend',   () => { tr.setAttribute('draggable','false'); $(tr).css('opacity',''); dragging = null; });
        tr.addEventListener('dragstart', e => { dragging = tr; e.dataTransfer.effectAllowed='move'; setTimeout(()=>$(tr).css('opacity','0.4'),0); });
        tr.addEventListener('dragover',  e => {
            e.preventDefault();
            if (!dragging || dragging===tr) return;
            const rect = tr.getBoundingClientRect();
            tbody.insertBefore(dragging, e.clientY < rect.top+rect.height/2 ? tr : tr.nextSibling);
        });
        tr.addEventListener('drop', e => {
            e.preventDefault();
            $(dragging).css('opacity','');
            const ids = [...tbody.querySelectorAll('tr[data-ntid]')].map(r => r.dataset.ntid);
            $.post(API_URL, { action:'reorder_note_templates', ids: JSON.stringify(ids) }, () => {
                loadAllNoteTemplates();
                loadNoteTemplateBtns();
            });
        });
    });
}
function editNoteTemplate(id) {
    const t = _ntmplMap[id];
    if (!t) return;
    $('#ntmpl-edit-id').val(id);
    $('#ntmpl-label-input').val(t.label);
    $('#ntmpl-text-input').val(t.note_text);
    $('#ntmpl-order-input').val(t.sort_order);
    $('#ntmpl-auto-full').prop('checked', !!t.auto_for_full);
    $('#ntmpl-auto-single').prop('checked', !!t.auto_for_single);
    renderNtmplVarRows(t.vars);
    $('#ntmpl-form-title').text('修改備註模板');
    $('#qs-tab-note-tmpl .panel').get(0).scrollIntoView({ behavior: 'smooth' });
}
function resetNoteTemplateForm() {
    $('#ntmpl-edit-id').val('');
    $('#ntmpl-label-input').val('');
    $('#ntmpl-text-input').val('');
    $('#ntmpl-order-input').val(0);
    $('#ntmpl-auto-full').prop('checked', false);
    $('#ntmpl-auto-single').prop('checked', false);
    $('#ntmpl-vars-list').empty();
    $('#ntmpl-form-title').text('新增備註模板');
}
function saveNoteTemplate() {
    const id      = parseInt($('#ntmpl-edit-id').val()) || 0;
    const label   = $('#ntmpl-label-input').val().trim();
    const text    = $('#ntmpl-text-input').val().trim();
    const ord     = parseInt($('#ntmpl-order-input').val()) || 0;
    const vars    = getNtmplVars();
    const aFull   = $('#ntmpl-auto-full').is(':checked')   ? 1 : 0;
    const aSingle = $('#ntmpl-auto-single').is(':checked') ? 1 : 0;
    if (!label || !text) { Swal.fire('提示','請填寫按鈕標籤與備註文字','warning'); return; }
    const keys = vars.map(v => v.key);
    const missing = keys.filter(k => !text.includes('{' + k + '}'));
    if (missing.length) {
        Swal.fire('提示', `備註文字中找不到變數 {${missing[0]}} 的佔位符`, 'warning');
        return;
    }
    $.post(API_URL, {
        action:'save_note_template', tmpl_id:id, label, note_text:text,
        variables:JSON.stringify(vars), sort_order:ord,
        auto_for_full: aFull, auto_for_single: aSingle
    }, res => {
        if (res.success) {
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:res.message, showConfirmButton:false, timer:1800 });
            resetNoteTemplateForm();
            loadAllNoteTemplates();
            loadNoteTemplateBtns();
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}
function toggleNoteTemplate(id) {
    $.post(API_URL, { action:'toggle_note_template', tmpl_id:id }, res => {
        if (res.success) {
            loadAllNoteTemplates();
            loadNoteTemplateBtns();
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}

// ── HTML5 drag-and-drop 拖移排序（jQuery UI sortable 不可用時的替代）──
function initHtml5Drag(containerSel, handleSel, apiAction, dataKey) {
    const $c = $(containerSel);
    let draggingEl = null;
    $c.find('.pt-group-item').each(function() {
        this.setAttribute('draggable', 'false'); // 整列先關
        $(this).find(handleSel).each(function() {
            this.setAttribute('draggable', 'true');
            this.addEventListener('dragstart', function(e) {
                draggingEl = $(this).closest('.pt-group-item')[0];
                e.dataTransfer.effectAllowed = 'move';
                setTimeout(() => draggingEl && $(draggingEl).css('opacity','0.4'), 0);
            });
        });
        this.addEventListener('dragend', function() {
            $(this).css('opacity','');
            draggingEl = null;
        });
        this.addEventListener('dragover', function(e) {
            e.preventDefault();
            if (!draggingEl || draggingEl === this) return;
            const rect = this.getBoundingClientRect();
            const mid  = rect.top + rect.height / 2;
            if (e.clientY < mid) this.parentNode.insertBefore(draggingEl, this);
            else this.parentNode.insertBefore(draggingEl, this.nextSibling);
        });
        this.addEventListener('drop', function(e) {
            e.preventDefault();
            const ids = [];
            $(containerSel + ' .pt-group-item').each(function() { ids.push($(this).data(dataKey)); });
            $.post(API_URL, { action: apiAction, ids: JSON.stringify(ids) });
        });
    });
}

// ★ 設定 Modal
// ══════════════════════════════════════════════════════
function openSettingsModal() {
    // 填入路徑
    $('#qs-upload-path').val(currentUploadPath || '');
    // 載入附件類別
    loadSettingCategories();
    // 載入製程標籤
    loadProcessTagTree(() => renderPtGroupList());
    // 載入備註模板
    loadAllNoteTemplates();
    // 載入表單編號 + 有效天數 + 列印管制
    loadFormNumber();
    loadValidDays();
    loadPrintApprovalSetting();
    $('#quoteSettingsModal').modal('show');
}

// ── 路徑儲存 ──────────────────────────────────────────
function saveUploadPath() {
    const p = $('#qs-upload-path').val().trim();
    if (!p) { Swal.fire('提示','路徑不可為空','warning'); return; }
    $.post(API_URL, {
        action: 'save_param', param_group: 'QUOTATION', param_key: 'upload_path',
        param_value: JSON.stringify(p), description: '報價單附件儲存根目錄'
    }, res => {
        if (res.success) {
            currentUploadPath = p;
            $('#uploadPathDisplay').text(p);
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'路徑已儲存', showConfirmButton:false, timer:2000 });
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}

// ══════════════════════════════════════════════════════
// ── 附件類別 CRUD（設定頁 Tab 2）──────────────────────
// ══════════════════════════════════════════════════════
function loadSettingCategories(refreshPanels) {
    $.get(FILE_API_URL, { action: 'get_all_categories' }, res => {
        if (!res.success) return;
        allFileCategories = res.categories.filter(c => c.is_active == 1);
        applyPageCatScope();
        renderSettingCategoryTable(res.categories);
        renderRequiredCatsSetting();
        renderPageCatSetting();
        // 同步重繪已展開的附件標籤面板
        if (refreshPanels !== false) {
            $('.file-tag-panel:visible').each(function() {
                const $wrap = $(this).closest('.file-item-wrap');
                const quoteNo = $('#quote_no').val();
                const fid = $wrap.data('attach-id');
                const f = { category_id: $wrap.data('cat-id') || null, linked_parts: null };
                renderFileTagPanel($wrap, f, quoteNo);
            });
        }
    });
}
let settingCatsCache = [];   // 供 editCategorySettings 依 id 取回完整欄位（含外來文件設定）
function renderSettingCategoryTable(cats) {
    settingCatsCache = cats;
    let html = '';
    cats.forEach(c => {
        const badge = c.is_active == 1
            ? '<span class="label label-success">啟用</span>'
            : '<span class="label label-default">停用</span>';
        const extBadge = c.is_external_doc == 1
            ? ` <span class="label" style="background:#F0A24B;" title="列入外來文件清單${c.external_doc_name ? '：'+escapeHtml(c.external_doc_name) : ''}">外來文件</span>`
            : '';
        html += `<tr data-cat-id="${c.id}" draggable="false">
            <td style="width:24px;cursor:grab;color:#bbb;text-align:center;" class="cat-drag-handle">&#9776;</td>
            <td>${escapeHtml(c.category_name)} ${badge}${extBadge}</td>
            <td>
                <button class="btn btn-xs btn-warning" onclick="editCategorySettings(${c.id})">
                    <i class="fa fa-pencil"></i>
                </button>
                ${c.is_active == 1
                    ? `<button class="btn btn-xs btn-danger" onclick="deactivateCategorySettings(${c.id})" title="停用"><i class="fa fa-ban"></i></button>`
                    : `<button class="btn btn-xs btn-default" onclick="reactivateCategorySettings(${c.id})" title="重新啟用"><i class="fa fa-check"></i></button>`
                }
            </td>
        </tr>`;
    });
    $('#cat-table-body').html(html || '<tr><td colspan="3" class="text-center text-muted">無資料</td></tr>');
    initCatTableDrag();
}
function initCatTableDrag() {
    const tbody = document.querySelector('#cat-table-body');
    if (!tbody) return;
    let dragging = null;
    tbody.querySelectorAll('tr').forEach(tr => {
        const handle = tr.querySelector('.cat-drag-handle');
        if (!handle) return;
        handle.addEventListener('mousedown', () => { tr.setAttribute('draggable','true'); });
        tr.addEventListener('dragend',   () => { tr.setAttribute('draggable','false'); dragging = null; });
        tr.addEventListener('dragstart', e => { dragging = tr; e.dataTransfer.effectAllowed='move'; setTimeout(()=>$(tr).css('opacity','0.4'),0); });
        tr.addEventListener('dragover',  e => {
            e.preventDefault();
            if (!dragging || dragging === tr) return;
            const rect = tr.getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) tbody.insertBefore(dragging, tr);
            else tbody.insertBefore(dragging, tr.nextSibling);
        });
        tr.addEventListener('drop', e => {
            e.preventDefault();
            $(dragging).css('opacity','');
            const ids = [...tbody.querySelectorAll('tr[data-cat-id]')].map(r => r.dataset.catId);
            $.post(FILE_API_URL, { action:'reorder_categories', ids: JSON.stringify(ids) }, () => loadSettingCategories());
        });
    });
}
function saveCategorySettings() {
    const id   = parseInt($('#cat-edit-id').val()) || 0;
    const name = $('#cat-name-input').val().trim();
    const ord  = parseInt($('#cat-order-input').val()) || 0;
    if (!name) { Swal.fire('提示','請填寫類別名稱','warning'); return; }
    const isExt   = $('#cat-extdoc-chk').is(':checked') ? 1 : 0;
    const extName = $('#cat-extdoc-name').val().trim();
    $.post(FILE_API_URL, { action:'save_category', cat_id:id, category_name:name, sort_order:ord,
                           is_external_doc:isExt, external_doc_name:extName }, res => {
        if (res.success) {
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:res.message, showConfirmButton:false, timer:1800 });
            resetCategoryForm();
            loadSettingCategories();
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}
function editCategorySettings(id) {
    const c = settingCatsCache.find(x => x.id == id);
    if (!c) return;
    $('#cat-edit-id').val(c.id);
    $('#cat-name-input').val(c.category_name);
    $('#cat-order-input').val(c.sort_order);
    $('#cat-extdoc-chk').prop('checked', c.is_external_doc == 1);
    $('#cat-extdoc-name').val(c.external_doc_name || '');
    $('#cat-extdoc-name-group').toggle(c.is_external_doc == 1);
    $('#cat-form-title').text('修改類別');
}
function resetCategoryForm() {
    $('#cat-edit-id').val('');
    $('#cat-name-input').val('');
    $('#cat-order-input').val(0);
    $('#cat-extdoc-chk').prop('checked', false);
    $('#cat-extdoc-name').val('');
    $('#cat-extdoc-name-group').hide();
    $('#cat-form-title').text('新增類別');
}
function deactivateCategorySettings(id) {
    Swal.fire({ title:'確定停用此類別？', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33',
        confirmButtonText:'停用', cancelButtonText:'取消'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(FILE_API_URL, { action:'deactivate_category', cat_id:id }, res => {
            if (res.success) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已停用', showConfirmButton:false, timer:1800 });
                loadSettingCategories();
            } else { Swal.fire('錯誤', res.message, 'error'); }
        });
    });
}
function reactivateCategorySettings(id) {
    $.post(FILE_API_URL, { action:'save_category', cat_id:id, category_name:'', sort_order:0 }); // dummy, will fail
    // Actually use a re-enable action by calling save with is_active=1 via update:
    // Simpler: just UPDATE via save_category with existing name. But we don't have name here.
    // Use a workaround: fetch name first, then save
    $.get(FILE_API_URL, { action:'get_all_categories' }, res => {
        const cat = (res.categories || []).find(c => c.id == id);
        if (!cat) return;
        // Directly set is_active=1 - we'll add a quick SQL via a new action
        $.post(FILE_API_URL, { action:'save_category', cat_id:id, category_name:cat.category_name, sort_order:cat.sort_order, reactivate:1 }, res2 => {
            if (res2.success) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已重新啟用', showConfirmButton:false, timer:1800 });
                loadSettingCategories();
            }
        });
    });
}

// ══════════════════════════════════════════════════════
// ── 製程標籤 CRUD（設定頁 Tab 3）──────────────────────
// ══════════════════════════════════════════════════════
let ptSelectedGroupId  = null;
let ptSelectedSubTagId = null;
let ptCurrentChecked   = [];   // 獨立記錄已勾選製程 ID，不依賴 DOM 避免搜尋時丟失

function renderPtGroupList() {
    let html = '';
    processTagTree.forEach(g => {
        const active  = ptSelectedGroupId === g.group_id;
        const isFull  = g.group_type === 'full_process';
        html += `<div class="pt-group-item ${active?'active':''}" data-gid="${g.group_id}">
            <span class="pt-drag-handle" title="拖移排序">&#9776;</span>
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(g.group_name)}</span>
            <span style="display:flex;gap:2px;align-items:center;flex-shrink:0;">
                <button class="btn btn-xs ${isFull?'btn-warning':'btn-default'}" title="全製程"
                    onclick="event.stopPropagation();setPtGroupType(${g.group_id},'full_process')"
                    style="font-size:10px;padding:1px 5px;">全</button>
                <button class="btn btn-xs ${!isFull?'btn-primary':'btn-default'}" title="單一製程"
                    onclick="event.stopPropagation();setPtGroupType(${g.group_id},'single_process')"
                    style="font-size:10px;padding:1px 5px;">單</button>
                <span class="pt-edit" data-gid="${g.group_id}" onclick="event.stopPropagation();renamePtGroup($(this).data('gid'))" title="重新命名">
                    <i class="fa fa-pencil"></i>
                </span>
                <span class="pt-del" onclick="event.stopPropagation();deletePtGroup(${g.group_id})" title="刪除群組">
                    <i class="fa fa-times"></i>
                </span>
            </span>
        </div>`;
    });
    $('#pt-group-list').html(html || '<div class="text-muted" style="font-size:11px;">尚無群組</div>');
    // 事件委派取代 inline onclick（避免 jQuery UI sortable mousedown 攔截）
    $('#pt-group-list').off('click.ptgrp').on('click.ptgrp', '.pt-group-item', function(e) {
        if ($(e.target).closest('button, .pt-drag-handle, .pt-del, .pt-edit').length) return;
        selectPtGroup($(this).data('gid'));
    });
    if (processTagTree.length > 1) {
        if ($.fn.sortable) {
            $('#pt-group-list').sortable({
                handle: '.pt-drag-handle', distance: 5,
                placeholder: 'ui-sortable-placeholder', tolerance: 'pointer',
                stop: function() {
                    const ids = $('#pt-group-list .pt-group-item').map(function() { return $(this).data('gid'); }).get();
                    $.post(API_URL, { action:'reorder_process_tag_groups', ids: JSON.stringify(ids) }, res => {
                        if (res.success) loadProcessTagTree(() => renderPtGroupList());
                    });
                }
            }).disableSelection();
        } else {
            initHtml5Drag('#pt-group-list', '.pt-drag-handle', 'reorder_process_tag_groups', 'gid');
        }
    }
}
function setPtGroupType(gid, type) {
    const g = processTagTree.find(x => x.group_id === gid);
    if (!g) return;
    $.post(API_URL, { action:'save_process_tag_group', group_id:gid, group_name:g.group_name, sort_order:g.sort_order||0, group_type:type }, res => {
        if (res.success) loadProcessTagTree(() => renderPtGroupList());
        else Swal.fire('錯誤', res.message, 'error');
    });
}
function renamePtGroup(gid) {
    const g = processTagTree.find(x => x.group_id === gid);
    if (!g) return;
    Swal.fire({
        title: '重新命名群組',
        input: 'text',
        inputValue: g.group_name,
        inputAttributes: { maxlength: 50 },
        showCancelButton: true,
        confirmButtonText: '儲存',
        cancelButtonText: '取消',
        didOpen: () => { $(document).off('focusin.modal'); Swal.getInput().focus(); },
        preConfirm: name => {
            name = name.trim();
            if (!name) { Swal.showValidationMessage('名稱不可為空'); return false; }
            return name;
        }
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action:'save_process_tag_group', group_id:gid, group_name:r.value, sort_order:g.sort_order||0, group_type:g.group_type||'single_process' }, res => {
            if (res.success) {
                loadProcessTagTree(() => renderPtGroupList());
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已更新', showConfirmButton:false, timer:1500 });
            } else { Swal.fire('錯誤', res.message, 'error'); }
        });
    });
}
function addPtGroup() {
    const name = $('#pt-new-group').val().trim();
    if (!name) return;
    $.post(API_URL, { action:'save_process_tag_group', group_name:name, group_type:'single_process', sort_order: processTagTree.length }, res => {
        if (res.success) {
            $('#pt-new-group').val('');
            loadProcessTagTree(() => renderPtGroupList());
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已新增', showConfirmButton:false, timer:1500 });
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}
function deletePtGroup(gid) {
    Swal.fire({ title:'刪除此群組及其所有子標籤？', icon:'warning', showCancelButton:true,
        confirmButtonColor:'#d33', confirmButtonText:'刪除', cancelButtonText:'取消'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action:'delete_process_tag_group', group_id:gid }, res => {
            if (res.success) {
                if (ptSelectedGroupId === gid) { ptSelectedGroupId=null; ptSelectedSubTagId=null; }
                loadProcessTagTree(() => { renderPtGroupList(); renderPtSubTagList(); renderPtProcList(); });
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已刪除', showConfirmButton:false, timer:1500 });
            } else { Swal.fire('錯誤', res.message, 'error'); }
        });
    });
}
function selectPtGroup(gid) {
    ptSelectedGroupId  = gid;
    ptSelectedSubTagId = null;
    const g = processTagTree.find(x => x.group_id === gid);
    $('#pt-sub-group-label').text(' — ' + (g ? g.group_name : ''));
    $('#pt-sub-form').show();
    renderPtGroupList();
    renderPtSubTagList();
    renderPtProcList();
}
function renderPtSubTagList() {
    if (!ptSelectedGroupId) {
        $('#pt-sub-list').html('<div class="text-muted" style="font-size:11px;">← 先選擇群組</div>');
        return;
    }
    const g = processTagTree.find(x => x.group_id === ptSelectedGroupId);
    const subs = g ? g.sub_tags : [];
    let html = '';
    subs.forEach(st => {
        const active = ptSelectedSubTagId === st.sub_tag_id;
        html += `<div class="pt-group-item ${active?'active':''}" data-sid="${st.sub_tag_id}">
            <span class="pt-drag-handle" title="拖移排序">&#9776;</span>
            <span style="flex:1;text-align:left;">${escapeHtml(st.sub_tag_name)} <small style="color:#aaa;">(${(st.process_nos||[]).length})</small></span>
            <span style="display:flex;gap:2px;align-items:center;">
                <span class="pt-edit" data-sid="${st.sub_tag_id}" onclick="event.stopPropagation();renamePtSubTag($(this).data('sid'))" title="重新命名">
                    <i class="fa fa-pencil"></i>
                </span>
                <span class="pt-del" onclick="event.stopPropagation();deletePtSubTag(${st.sub_tag_id})" title="刪除">
                    <i class="fa fa-times"></i>
                </span>
            </span>
        </div>`;
    });
    $('#pt-sub-list').html(html || '<div class="text-muted" style="font-size:11px;">尚無子標籤</div>');
    // 事件委派取代 inline onclick
    $('#pt-sub-list').off('click.ptsub').on('click.ptsub', '.pt-group-item', function(e) {
        if ($(e.target).closest('.pt-drag-handle, .pt-del, .pt-edit').length) return;
        selectPtSubTag($(this).data('sid'));
    });
    if (subs.length > 1) {
        if ($.fn.sortable) {
            $('#pt-sub-list').sortable({
                handle: '.pt-drag-handle', distance: 5,
                placeholder: 'ui-sortable-placeholder', tolerance: 'pointer',
                stop: function() {
                    const ids = $('#pt-sub-list .pt-group-item').map(function() { return $(this).data('sid'); }).get();
                    $.post(API_URL, { action:'reorder_process_sub_tags', ids: JSON.stringify(ids) }, res => {
                        if (res.success) loadProcessTagTree(() => { renderPtGroupList(); renderPtSubTagList(); });
                    });
                }
            }).disableSelection();
        } else {
            initHtml5Drag('#pt-sub-list', '.pt-drag-handle', 'reorder_process_sub_tags', 'sid');
        }
    }
}
function addPtSubTag() {
    if (!ptSelectedGroupId) return;
    const name = $('#pt-new-sub').val().trim();
    if (!name) return;
    $.post(API_URL, { action:'save_process_sub_tag', group_id:ptSelectedGroupId, sub_tag_name:name, sort_order:99 }, res => {
        if (res.success) {
            $('#pt-new-sub').val('');
            loadProcessTagTree(() => { renderPtGroupList(); renderPtSubTagList(); });
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已新增', showConfirmButton:false, timer:1500 });
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}
function renamePtSubTag(sid) {
    const g  = processTagTree.find(x => x.group_id === ptSelectedGroupId);
    const st = g ? (g.sub_tags || []).find(x => x.sub_tag_id === sid) : null;
    const currentName = st ? st.sub_tag_name : '';
    Swal.fire({
        title: '重新命名子標籤',
        input: 'text',
        inputValue: currentName,
        inputAttributes: { maxlength: 50 },
        showCancelButton: true,
        confirmButtonText: '儲存',
        cancelButtonText: '取消',
        didOpen: () => { $(document).off('focusin.modal'); Swal.getInput().focus(); },
        preConfirm: name => {
            name = name.trim();
            if (!name) { Swal.showValidationMessage('名稱不可為空'); return false; }
            return name;
        }
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action:'save_process_sub_tag', sub_tag_id:sid, group_id:ptSelectedGroupId, sub_tag_name:r.value, sort_order:99 }, res => {
            if (res.success) {
                loadProcessTagTree(() => { renderPtGroupList(); renderPtSubTagList(); });
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已更新', showConfirmButton:false, timer:1500 });
            } else { Swal.fire('錯誤', res.message, 'error'); }
        });
    });
}
function deletePtSubTag(sid) {
    Swal.fire({ title:'刪除此子標籤及其製程連結？', icon:'warning', showCancelButton:true,
        confirmButtonColor:'#d33', confirmButtonText:'刪除', cancelButtonText:'取消'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action:'delete_process_sub_tag', sub_tag_id:sid }, res => {
            if (res.success) {
                if (ptSelectedSubTagId === sid) ptSelectedSubTagId = null;
                loadProcessTagTree(() => { renderPtGroupList(); renderPtSubTagList(); renderPtProcList(); });
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已刪除', showConfirmButton:false, timer:1500 });
            } else { Swal.fire('錯誤', res.message, 'error'); }
        });
    });
}
function selectPtSubTag(sid) {
    ptSelectedSubTagId = sid;
    const g   = processTagTree.find(x => x.group_id === ptSelectedGroupId);
    const st  = g ? (g.sub_tags || []).find(x => x.sub_tag_id === sid) : null;
    $('#pt-proc-sub-label').text(' — ' + (st ? st.sub_tag_name : ''));
    renderPtSubTagList();
    renderPtProcList();
}
function renderPtProcList() {
    if (!ptSelectedSubTagId) {
        $('#pt-proc-list').html('<div class="text-muted" style="font-size:11px;">← 先選擇子標籤</div>');
        $('#pt-proc-search-wrap, #pt-proc-save-wrap, #pt-linked-chips').hide();
        return;
    }
    const g      = processTagTree.find(x => x.group_id === ptSelectedGroupId);
    const st     = g ? (g.sub_tags || []).find(x => x.sub_tag_id === ptSelectedSubTagId) : null;
    const linked = (st ? (st.process_nos || []) : []).map(Number);

    // ★ 初始化獨立 state
    ptCurrentChecked = [...linked];

    $('#pt-proc-search-wrap, #pt-proc-save-wrap, #pt-linked-chips').show();
    $('#pt-proc-search').val('');

    renderPtLinkedChips(ptCurrentChecked);
    renderPtProcCheckList('');       // 用 ptCurrentChecked 渲染

    // 搜尋過濾：不重設 state，只重繪 DOM
    $('#pt-proc-search').off('input').on('input', function() {
        renderPtProcCheckList($(this).val());
    });
}

function renderPtLinkedChips(checked) {
    if (!checked.length) {
        $('#pt-linked-chips').html('<small style="color:#aaa;font-size:10px;">已連結：<span style="color:#ccc;">（尚未連結任何製程）</span></small>');
        return;
    }
    let html = '<small style="color:#888;font-size:10px;">已連結：</small> ';
    checked.forEach(pno => {
        const p = allProcesses.find(x => parseInt(x.id) === pno);
        const label = p ? p.text : String(pno);
        html += `<span style="display:inline-flex;align-items:center;gap:2px;background:#e8f0ff;border:1px solid #b0c4f0;border-radius:3px;padding:0 5px;font-size:11px;margin:1px 2px;line-height:1.7;">
            ${escapeHtml(label)}
            <span onclick="removePtChip(${pno})" style="cursor:pointer;color:#999;margin-left:2px;font-size:13px;line-height:1;">&times;</span>
        </span>`;
    });
    $('#pt-linked-chips').html(html);
}

// ★ 移除 chip：同步更新 state + DOM checkbox
function removePtChip(pno) {
    ptCurrentChecked = ptCurrentChecked.filter(x => x !== pno);
    // 若此 checkbox 目前可見，取消勾選
    $('#pt-proc-list input[value="' + pno + '"]').prop('checked', false);
    renderPtLinkedChips(ptCurrentChecked);
}

// ★ 以 ptCurrentChecked 為準渲染 checkboxes（搜尋時保留狀態）
function renderPtProcCheckList(filter) {
    const f = (filter || '').toLowerCase();
    let html = '';
    allProcesses.forEach(p => {
        if (f && p.text.toLowerCase().indexOf(f) === -1) return;
        const isChk = ptCurrentChecked.includes(parseInt(p.id));
        html += `<label class="pt-proc-check">
            <input type="checkbox" value="${p.id}" ${isChk ? 'checked' : ''} style="margin-right:5px;">
            ${escapeHtml(p.text)}
        </label>`;
    });
    $('#pt-proc-list').html(html || '<span class="text-muted" style="font-size:11px;">無符合製程</span>');

    // ★ 勾選/取消 → 更新獨立 state 和 chips
    $('#pt-proc-list').off('change.ptchip').on('change.ptchip', 'input[type="checkbox"]', function() {
        const pno = parseInt($(this).val());
        if ($(this).is(':checked')) {
            if (!ptCurrentChecked.includes(pno)) ptCurrentChecked.push(pno);
        } else {
            ptCurrentChecked = ptCurrentChecked.filter(x => x !== pno);
        }
        renderPtLinkedChips(ptCurrentChecked);
    });
}

// 保留供相容（不再用 DOM 讀取）
function getCurrentPtChecked() { return ptCurrentChecked; }

function savePtProcesses() {
    if (!ptSelectedSubTagId) return;
    const pnos = ptCurrentChecked;
    const $btn = $('#pt-proc-save-wrap .btn-primary').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中...');
    $.post(API_URL, { action:'save_process_tag_processes', sub_tag_id:ptSelectedSubTagId, process_nos:JSON.stringify(pnos) }, res => {
        $btn.prop('disabled', false).html('<i class="fa fa-save"></i> 儲存製程連結');
        if (res.success) {
            const savedGid = ptSelectedGroupId;
            const savedSid = ptSelectedSubTagId;
            loadProcessTagTree(() => {
                renderPtGroupList();
                ptSelectedSubTagId = savedSid;
                renderPtSubTagList();
                renderPtProcList();  // 重繪以反映儲存後狀態
            });
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'製程連結已儲存', showConfirmButton:false, timer:1800 });
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}

// ══════════════════════════════════════════════════════
function adjustLayout() {
    const $sw = $('.split-wrap');
    if (!$sw.length || !$sw.offset()) return;
    const h = $(window).height() - $sw.offset().top - 2;
    $sw.height(Math.max(h, 380));
}

// ══════════════════════════════════════════════════════
// 列表載入
// ══════════════════════════════════════════════════════
function loadQuoteList(year) {
    $('#quoteListBody').html('<div class="text-center text-muted" style="padding:30px;"><i class="fa fa-spinner fa-spin"></i></div>');
    $.get(API_URL, { action: 'get_list', year }, res => {
        if (res.success) {
            allQuotes = res.data;
            isAllYearsMode = false;
            $('#allYearsIndicator').hide();
            buildClientFilter(allQuotes);
            renderQuoteList(allQuotes, $('#listSearch').val().trim());
            adjustLayout();  // 資料載入後重算高度
        }
    });
}
function loadAllYears() {
    $('#quoteListBody').html('<div class="text-center text-muted" style="padding:30px;"><i class="fa fa-spinner fa-spin"></i></div>');
    if (allYearsData) {
        allQuotes = allYearsData;
        isAllYearsMode = true;
        $('#allYearsIndicator').show();
        buildClientFilter(allQuotes);
        renderQuoteList(allQuotes, $('#listSearch').val().trim());
        return;
    }
    $.get(API_URL, { action: 'get_list_all' }, res => {
        if (res.success) {
            allYearsData = res.data;
            allQuotes = allYearsData;
            isAllYearsMode = true;
            $('#allYearsIndicator').show();
            buildClientFilter(allQuotes);
            renderQuoteList(allQuotes, '');
        }
    });
}

// ══════════════════════════════════════════════════════
// ★ 客戶下拉選單（由載入的資料動態產生）
// ══════════════════════════════════════════════════════
// 目前資料裡出現過的客戶清單：{ name, id（同名多代碼以 / 併列）, count }
let clientOptionList = [];

function buildClientFilter(quotes) {
    const map = new Map();
    quotes.forEach(q => {
        const name = q.client_name || '';
        if (!name) return;
        if (!map.has(name)) map.set(name, { name, ids: new Set(), count: 0 });
        const o = map.get(name);
        if (q.client_id) o.ids.add(String(q.client_id));
        o.count++;
    });
    clientOptionList = [...map.values()]
        .map(o => ({ name: o.name, id: [...o.ids].sort().join('/'), count: o.count }))
        .sort((a, b) => a.name.localeCompare(b.name, 'zh-Hant'));
    renderClientFilterOptions();
}

// 子序列比對（關鍵字的字元依序出現即算命中），只在完全比不到時才啟用
function _clientSubseq(hay, needle) {
    let i = 0;
    for (const ch of hay) { if (ch === needle[i]) i++; if (i >= needle.length) return true; }
    return needle.length === 0;
}

// 模糊篩選：代碼＋名稱一起比對，空白可分隔多關鍵字（需全部命中）
function filterClientOptions(term) {
    const t = (term || '').trim().toLowerCase();
    if (!t) return clientOptionList;
    const toks = t.split(/\s+/).filter(Boolean);
    const hay  = o => ((o.id || '') + ' ' + o.name).toLowerCase();
    let list = clientOptionList.filter(o => toks.every(k => hay(o).includes(k)));
    // 一筆都比不到才放寬成子序列比對（例：打 "台電" 也能找到 "台灣電機"）
    if (!list.length) list = clientOptionList.filter(o => toks.every(k => _clientSubseq(hay(o), k)));
    return list;
}

// 依搜尋字重建下拉選項（維持目前選取的客戶；剛好只剩一家時自動選取）
function renderClientFilterOptions() {
    const term = $('#clientFilterSearch').val() || '';
    const cur  = $('#clientFilterSel').val() || '';
    const list = filterClientOptions(term);
    const lbl  = o => (o.id ? o.id + '－' : '') + o.name + ' (' + o.count + ')';

    let html = `<option value="">全部客戶${term.trim() ? `（符合 ${list.length} 家）` : ''}</option>`;
    list.forEach(o => { html += `<option value="${escapeHtml(o.name)}">${escapeHtml(lbl(o))}</option>`; });
    // 目前選取的客戶被篩選字排除時仍保留一列，避免選取被意外清掉
    const curOpt = cur ? clientOptionList.find(o => o.name === cur) : null;
    if (curOpt && !list.some(o => o.name === cur)) {
        html += `<option value="${escapeHtml(curOpt.name)}">${escapeHtml(lbl(curOpt))}（目前選取）</option>`;
    }
    $('#clientFilterSel').html(html);
    $('#clientFilterSel').val(curOpt ? cur : '');

    refreshClearFilterBtn();
    const $hint = $('#clientFilterHint');
    if (!term.trim())      $hint.hide();
    else if (!list.length) $hint.text('找不到符合的客戶').css('color', '#DD5138').show();
    else                   $hint.text(`符合 ${list.length} 家客戶`).css('color', '#96601f').show();

    // 只剩一家 → 直接選起來，省一次點擊
    if (term.trim() && list.length === 1 && $('#clientFilterSel').val() !== list[0].name) {
        $('#clientFilterSel').val(list[0].name).trigger('change');
    }
}

// 清空客戶模糊篩選字（不動已選取的客戶）
function clearClientFilterSearch() {
    $('#clientFilterSearch').val('');
    renderClientFilterOptions();
}

// 三個篩選欄任一有值時才讓「取消篩選」鈕亮起（沒東西可清時淡化）
function refreshClearFilterBtn() {
    const on = !!($('#clientFilterSearch').val() || $('#clientFilterSel').val() || $('#listSearch').val());
    $('#clearAllFilterBtn').css({ opacity: on ? 1 : .45, cursor: on ? 'pointer' : 'default' });
}

// 取消篩選：客戶模糊篩選字 + 客戶下拉 + 下方單號/備註搜尋一次全清
function clearAllListFilters() {
    $('#clientFilterSearch').val('');
    $('#clientFilterSel').val('');
    $('#listSearch').val('');
    renderClientFilterOptions();                 // 客戶下拉還原成完整清單
    renderQuoteList(allQuotes, '');
}

// ══════════════════════════════════════════════════════
// ★ 渲染清單（支援客戶篩選 + 依客戶分組）
// ══════════════════════════════════════════════════════
function renderQuoteList(quotes, filter) {
    const f       = filter.toLowerCase();
    const clientF = $('#clientFilterSel').val();
    refreshClearFilterBtn();

    let filtered = quotes;
    // 待處理單據＝報價單「本身」簽核狀態為待審核(pending)或被駁回(rejected)；已核准者不列入（補件待審另有專屬入口）
    if (pendingFilterMode) filtered = filtered.filter(q => q.approval_status === 'pending' || q.approval_status === 'rejected');
    // 暫存未完成＝自己建立、內容尚未填完仍先存檔的報價單（is_temp_save）
    if (tempSaveFilterMode) filtered = filtered.filter(q => q.is_temp_save == 1 && Number(q.created_by) === CURRENT_UID);
    if (clientF) filtered = filtered.filter(q => (q.client_name || '') === clientF);
    refreshPendingDocBadge();
    refreshTempSaveDocBadge();
    if (f) filtered = filtered.filter(q =>
        ((q.quote_no || '') + (q.note || '') + (q.search_keywords || '')).toLowerCase().includes(f)
    );

    let total = 0;
    filtered.forEach(q => total += parseFloat(q.total_amount) || 0);
    $('#stat-count').text(filtered.length);
    $('#stat-amount').text(formatNumber(total));

    if (!filtered.length) {
        $('#quoteListBody').html('<div class="text-center text-muted" style="padding:20px;font-size:12px;">無符合資料</div>');
        return;
    }

    // ── 依客戶分組（無客戶篩選時才分組，有篩選時同一客戶不需分組）
    let html = '';
    if (clientF) {
        // 單一客戶：直接列出不加分組標頭
        filtered.forEach(q => { html += buildQuoteCard(q); });
    } else {
        // 多客戶：按客戶名稱分組顯示
        const groups = {};
        filtered.forEach(q => {
            const key = q.client_name || '（無客戶）';
            if (!groups[key]) groups[key] = [];
            groups[key].push(q);
        });
        Object.entries(groups).forEach(([client, list]) => {
            const grpId = 'grp_' + client.replace(/\W/g, '_');
            const sum   = list.reduce((acc, q) => acc + (parseFloat(q.total_amount) || 0), 0);
            html += `<div class="qli-group-hdr" onclick="toggleGroup('${escapeHtml(grpId)}')">
                <span><i class="fa fa-building-o" style="margin-right:5px;opacity:.6;"></i>${escapeHtml(client)}
                    <span style="font-weight:400;color:#888;font-size:10px;margin-left:4px;">(${list.length})</span>
                </span>
                <span class="qg-toggle" id="toggle_${escapeHtml(grpId)}">▲</span>
            </div>
            <div class="qli-group-body" id="${escapeHtml(grpId)}">`;
            list.forEach(q => { html += buildQuoteCard(q); });
            html += `</div>`;
        });
    }

    $('#quoteListBody').html(html);
}

function buildQuoteCard(q) {
    const isActive  = currentEditId && currentEditId == q.quote_id;
    const negoBadge   = q.is_negotiation == 1 ? '<span class="nego-badge" style="font-size:9px;">議價</span>' : '';
    const draftBadge  = q.is_draft == 1 ? '<span class="draft-badge" style="font-size:9px;" title="必備附件缺漏，儲存為草稿">草稿</span>' : '';
    const tempBadge   = q.is_temp_save == 1 ? '<span class="tempsave-badge" style="font-size:9px;" title="內容尚未填完，先暫存續填">暫存</span>' : '';
    const srcBadge    = q.source_quote_id
        ? `<span class="source-badge" title="複製自 ${escapeHtml(q.source_quote_no||'')}" style="font-size:9px;"><i class="fa fa-copy"></i></span>`
        : '';
    const attachCount = parseInt(q.attach_count) || 0;
    const attachBadge = attachCount > 0
        ? `<span title="${attachCount} 個附件" style="display:inline-flex;align-items:center;gap:2px;font-size:10px;color:#888;margin-left:5px;vertical-align:middle;"><i class="fa fa-paperclip"></i>${attachCount}</span>`
        : '';
    const clientF = $('#clientFilterSel').val();
    // 有客戶篩選時不重複顯示客戶名稱
    const clientRow = clientF ? '' : `<div class="qli-client">${escapeHtml(q.client_name || '（無客戶）')}</div>`;
    return `<div class="qli-card ${isActive ? 'active' : ''}" onclick="openEditor(${q.quote_id})">
        <div class="qli-no">${escapeHtml(q.quote_no)}${negoBadge}${draftBadge}${tempBadge}${approvalBadgeHtml(q)}${srcBadge}${attachBadge}</div>
        ${clientRow}
        <div class="qli-foot">
            <span class="qli-date">${escapeHtml(String(q.quote_date||'').replace(/-/g,'.'))}</span>
            <span class="qli-amt">${formatNumber(q.total_amount)}</span>
        </div>
    </div>`;
}

// 折疊 / 展開客戶分組
function toggleGroup(grpId) {
    const $body = $('#' + grpId);
    const $icon = $('#toggle_' + grpId);
    $body.toggleClass('collapsed');
    $icon.text($body.hasClass('collapsed') ? '▼' : '▲');
}

// ══════════════════════════════════════════════════════
// 編輯器 開/關/重設
// ══════════════════════════════════════════════════════
function openNewEditor() {
    _pendingFilterHint();   // 待處理篩選畫面下提醒（3秒自動消失）
    // 若編輯器已開啟，提示是否先儲存
    if ($('#editorPanel').is(':visible')) {
        Swal.fire({
            title: '目前有報價單尚未關閉',
            text: '是否先儲存後再新增？',
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: '<i class="fa fa-save"></i> 儲存後新增',
            denyButtonText: '放棄儲存並新增',
            cancelButtonText: '取消',
            denyButtonColor: '#e67e22',
        }).then(r => {
            if (r.isConfirmed) saveQuote(_doOpenNewEditor);
            else if (r.isDenied) _doOpenNewEditor();
            // 取消：不做任何動作，留在目前報價單
        });
        return;
    }
    _doOpenNewEditor();
}
function _doOpenNewEditor() {
    ++_editToken; // 使任何進行中的 openEditor async 回呼失效
    // 釋放前一張報價單的編輯鎖 + 停止心跳
    if (currentEditId) {
        $.post(API_URL, { action: 'release_lock', quote_id: currentEditId });
    }
    stopLockHeartbeat();
    // 若前一張是未儲存新增且已上傳附件，先刪除臨時資料夾
    if (_tempUploadQno && !currentEditId) {
        $.post(FILE_API_URL, { action: 'delete_folder', quote_no: _tempUploadQno });
        _tempUploadQno = null;
    }
    resetEditor();
    currentEditId = null;
    $('#viewPanel').hide();
    $('#editorEmpty').hide();
    $('#editorTitle').html('<i class="fa fa-plus-circle" style="color:var(--accent);margin-right:6px;"></i>新增報價單');
    $('#changeLogBtn, #delQuoteBtn, #cloneQuoteBtn').hide();
    const today = todayStr();
    $('#quote_date').val(today);
    autoFillValidUntil();
    const prefix = quoteNoPrefixFromDate(today);
    $('#quote_no_prefix').text(prefix);
    $.get(API_URL, { action: 'get_new_quote_no' }, res => {
        if (res.success) {
            $('#quote_seq').val(res.quote_no.slice(-3));
            syncQuoteNo();
        }
    });
    addItemRow();
    showEditor();
    $('#uploadedFilesList').empty();
}
// 點選列表卡片 → 開啟檢視模式（不鎖定）
function openEditor(quote_id) {
    openViewMode(quote_id);
}

function openViewMode(quote_id) {
    const token = ++_editToken;
    stopLockHeartbeat();
    currentEditId = quote_id;
    renderQuoteList(allQuotes, $('#listSearch').val().trim());
    $('#viewPanel').css('display', 'flex');
    $('#editorPanel').hide();
    $('#editorEmpty').hide();
    $('#newQuoteBtn').show();  // 檢視畫面可新增
    $('#viewBody').html('<div class="text-center text-muted" style="padding:40px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
    $('#viewHistoryBar').hide();

    // 同時請求 get_print_data（含齒輪規格/規格號/聯絡人）和 get_detail（含修改者資訊）
    $.when(
        $.get(API_URL, { action: 'get_print_data', quote_id }),
        $.get(API_URL, { action: 'get_detail', quote_id })
    ).done((printR, detailR) => {
        if (token !== _editToken) return;
        const pr = printR[0], dr = detailR[0];
        if (!pr.success) { Swal.fire('錯誤', pr.message || '載入失敗', 'error'); return; }
        const { quote, contact } = pr;
        // 注入製程子標籤名稱（同列印邏輯）
        (quote.items || []).forEach(item => {
            const subIds = (item.process_notes || '').split(',')
                .map(s => parseInt(s.trim())).filter(x => x > 0);
            const names = [];
            subIds.forEach(sid => {
                processTagTree.forEach(g => (g.sub_tags || []).forEach(st => {
                    if (st.sub_tag_id === sid) names.push(st.sub_tag_name);
                }));
            });
            item.process_names = names.join('・');
        });
        renderViewPanel(quote, contact, dr.success ? dr.data : null);
    }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
}

// q = quote from get_print_data (gear_spec/spec_no/process_names injected)
// contact = contact obj; detail = get_detail data (has updated_by_name)
function renderViewPanel(q, contact, detail) {
    const neg = q.is_negotiation == 1 ? '<span class="nego-badge" style="font-size:9px;">議價</span>' : '';
    const draft = q.is_draft == 1 ? '<span class="draft-badge" style="font-size:9px;" title="必備附件缺漏，儲存為草稿">草稿</span>' : '';
    const temp  = q.is_temp_save == 1 ? '<span class="tempsave-badge" style="font-size:9px;" title="內容尚未填完，先暫存續填">暫存</span>' : '';
    $('#viewTitle').html(`<i class="fa fa-eye" style="color:var(--accent);margin-right:6px;"></i>${escapeHtml(q.quote_no||'')} ${neg}${draft}${temp}${approvalBadgeHtml(q)}`);
    $('#viewClientTag').text(q.client_name ? ' — ' + q.client_name : '');
    renderApprovalBar(q, detail);
    updatePrintGate(q);
    const fmt = s => s ? String(s).replace('T',' ').slice(0,16).replace(/^(\d{4})-(\d{2})-(\d{2})/,'$1.$2.$3') : '';
    const cname = (detail && detail.created_by_name) || q.created_by_name || '';
    if (cname) {
        $('#viewHistCreated').html(`<i class="fa fa-user-o"></i>建立：${escapeHtml(cname)}　${escapeHtml(fmt((detail||q).created_at))}`);
        const $upd = $('#viewHistUpdated');
        const uname = detail && detail.updated_by_name || '';
        const uat = detail && detail.updated_at || '';
        if (uname && uat) { $upd.html(`<i class="fa fa-pencil"></i>修改：${escapeHtml(uname)}　${escapeHtml(fmt(uat))}`).show(); }
        else { $upd.hide(); }
        $('#viewHistoryBar').show();
    }
    // 聯絡人字串
    let contactStr = '—';
    if (contact && contact.name) {
        const cp = [contact.name];
        if (contact.title) cp.push(contact.title);
        const tel = contact.phone_ext ? '分機 ' + contact.phone_ext : (contact.mobile || '');
        if (tel) cp.push(tel);
        contactStr = cp.join('・');
    }
    const esc = s => escapeHtml(String(s||''));
    const fmtNum = n => (parseFloat(n||0)).toLocaleString('zh-TW', {minimumFractionDigits:0, maximumFractionDigits:2});
    const negLabel = q.is_negotiation == 1 ? '<small style="color:#c0392b;font-weight:bold;margin-right:2px;">議價</small>' : '';
    let itemsHtml = '';
    (q.items || []).forEach((it, i) => {
        const isTiered = it.is_tiered == 1;
        // 品名規格欄：Spec_No+齒輪規格 / 製程 / 料號備註
        const leftSpec = [it.spec_no, it.gear_spec].filter(Boolean).join(' ');
        const desc = [leftSpec, it.process_names, it.specification].filter(Boolean).join(' / ');
        const descHtml = esc(desc);
        if (isTiered && it.tiers && it.tiers.length) {
            // 比照列印版：每階顯示「數量區間｜單位｜單價」，金額不顯示（依訂購量另計）
            const rangeTxt = t => {
                const mn = fmtNum(Math.round(Number(t.qty_min || 0)));
                return (t.qty_max === null || t.qty_max === undefined || t.qty_max === '')
                    ? `${mn}以上` : `${mn}~${fmtNum(Math.round(Number(t.qty_max)))}`;
            };
            const tolTxt = t => (t.tolerance_value === null || t.tolerance_value === undefined || t.tolerance_value === '')
                ? '' : `<div style="font-size:10px;color:#a06a1f;">容差±${fmtNum(t.tolerance_value)}${esc(t.tolerance_unit || '')}${t.tolerance_note ? '｜' + esc(t.tolerance_note) : ''}</div>`;
            it.tiers.forEach((t, ti) => {
                itemsHtml += `<tr>
                    ${ti===0 ? `<td rowspan="${it.tiers.length}" style="vertical-align:middle;text-align:center;">${i+1}</td>
                        <td rowspan="${it.tiers.length}" style="vertical-align:middle;font-size:12px;">${qlDrawingSpan(it.product_id, it.d_setting_d_id)}</td>
                        <td rowspan="${it.tiers.length}" style="vertical-align:middle;font-size:11px;">${descHtml}<div style="font-size:10px;color:#888;">（階梯報價，單價依訂購數量區間）</div></td>` : ''}
                    <td class="text-right" style="white-space:nowrap;">${rangeTxt(t)}${tolTxt(t)}</td>
                    <td class="text-center">${esc(it.unit||'PCS')}</td>
                    <td class="text-right">${negLabel}${fmtNum(t.unit_price)}</td>
                    <td></td>
                </tr>`;
            });
        } else {
            const amt = parseFloat(it.amount || 0);
            itemsHtml += `<tr>
                <td class="text-center">${i+1}</td>
                <td style="font-size:12px;">${qlDrawingSpan(it.product_id, it.d_setting_d_id)}</td>
                <td style="font-size:11px;">${descHtml}</td>
                <td class="text-right">${fmtNum(it.quantity)}</td>
                <td class="text-center">${esc(it.unit||'PCS')}</td>
                <td class="text-right">${negLabel}${fmtNum(it.unit_price)}</td>
                <td class="text-right">${fmtNum(amt)}</td>
            </tr>`;
        }
        // 組合件子件清單（勾選顯示才出現；畫面超過 2 件收合）
        if (it.show_bom == 1 && (it.bom_children || []).length) {
            const kids = it.bom_children;
            const mk = c => {
                const extra = [c.spec_no, c.Remark_Bom].filter(Boolean).join('・');
                return `<div><b style="color:#555;">${esc(c.part_no)}</b> <span style="color:#8e44ad;">${fmtBomQty(c.standard_qty)}PCS/組</span>${extra ? ` <span style="color:#aaa;">${esc(extra)}</span>` : ''}</div>`;
            };
            const head = kids.slice(0, 2).map(mk).join('');
            const rest = kids.slice(2).map(mk).join('');
            const printBadge = it.print_bom == 1
                ? `<span style="color:#c0392b;font-weight:normal;font-size:10px;">（列印時包含）</span>`
                : `<span style="color:#aaa;font-weight:normal;font-size:10px;">（僅畫面顯示，不列印）</span>`;
            itemsHtml += `<tr><td style="border-top:none;"></td><td colspan="6" style="font-size:11px;color:#666;background:#faf7fd;border-top:none;padding:4px 8px;">
                <div style="margin-bottom:2px;"><span style="color:#8e44ad;font-weight:700;"><i class="fa fa-sitemap"></i> 組合件子件清單</span> ${printBadge}</div>
                <div style="padding-left:6px;border-left:2px solid #d8c3ef;line-height:1.6;">
                ${head}
                ${rest ? `<div class="bom-more" style="display:none;">${rest}</div>
                <a href="#" onclick="$(this).prev('.bom-more').show();$(this).remove();return false;" style="font-size:10px;color:#8e44ad;">▼ 展開全部 ${kids.length} 件</a>` : ''}
                </div>
            </td></tr>`;
        }
    });
    const noteHtml = q.note
        ? q.note.split(/[；;]/).map(s => s.trim()).filter(Boolean).map(esc).join('<br>') : '';
    const html = `
    <div class="row" style="font-size:13px;margin-bottom:10px;">
        <div class="col-sm-6">
            <table class="table table-condensed" style="margin:0;">
                <tr><td style="width:80px;color:#888;white-space:nowrap;">客戶名稱</td><td>${esc(q.client_name)}</td></tr>
                <tr><td style="color:#888;">聯絡人</td><td>${esc(contactStr)}</td></tr>
                <tr><td style="color:#888;">詢價編號</td><td>${esc(q.inquiry_no||'—')}</td></tr>
                <tr><td style="color:#888;">幣別/匯率</td><td>${esc(q.currency==='TWD'?'NTD':(q.currency||'NTD'))} / ${esc(q.exchange_rate||1)}</td></tr>
            </table>
        </div>
        <div class="col-sm-6">
            <table class="table table-condensed" style="margin:0;">
                <tr><td style="width:80px;color:#888;white-space:nowrap;">報價日期</td><td>${esc(q.quote_date ? String(q.quote_date).replace(/-/g,'.') : '—')}</td></tr>
                <tr><td style="color:#888;">有效日期</td><td>${esc(q.valid_until ? String(q.valid_until).replace(/-/g,'.') : '—')}</td></tr>
                <tr><td style="color:#888;">業務人員</td><td>${esc(q.created_by_name||'')}</td></tr>
                <tr><td style="color:#888;">總金額</td><td><strong style="color:var(--accent);font-size:15px;">${fmtNum(q.total_amount)}</strong></td></tr>
            </table>
        </div>
    </div>
    ${noteHtml ? `<div style="font-size:13px;margin-bottom:10px;padding:8px 12px;background:#fafafa;border-left:3px solid var(--accent);border-radius:3px;"><strong>備註：</strong><br>${noteHtml}</div>` : ''}
    <table class="table table-condensed table-bordered view-item-table" style="margin-bottom:4px;">
        <thead><tr>
            <th style="width:4%;text-align:center;">#</th><th style="width:16%;">料號</th>
            <th>品名規格／加工項目 / 備註</th>
            <th style="width:7%;text-align:right;">數量</th><th style="width:6%;text-align:center;">單位</th>
            <th style="width:10%;text-align:right;">單價</th><th style="width:11%;text-align:right;">金額</th>
        </tr></thead>
        <tbody>${itemsHtml||'<tr><td colspan="7" class="text-center text-muted">無報價項目</td></tr>'}</tbody>
    </table>
    <div id="viewAttachSection" style="margin-top:10px;">
        <div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:6px;display:flex;align-items:center;gap:5px;">
            <i class="fa fa-paperclip"></i> 附件
        </div>
        <div id="viewAttachList"></div>
    </div>
    ${(q.approval_status==='approved' && (CAN_EDIT || (q.created_by!=null && Number(q.created_by)===CURRENT_UID))) ? `
    <div id="viewSupplementBar" style="margin-top:8px;">
        <button class="btn btn-xs" style="background:#F0A24B;color:#fff;font-weight:600;" onclick="openSupplementModal('${esc(q.quote_no)}')">
            <i class="fa fa-plus"></i> 補件（追加附件送審）
        </button>
        <span style="font-size:11px;color:#999;margin-left:6px;">已核准報價單追加附件，需經簽核者審核通過才會正式放入此報價單</span>
    </div>` : ''}`;
    $('#viewBody').html(html);
    // 記住目前檢視單的料號清單（product_id，與 linked_parts 儲存格式一致；供補件 modal 下拉使用）
    _viewQuoteParts = [...new Set((q.items || []).map(it => it.product_id).filter(Boolean))];
    loadFileList(q.quote_no, true);
}

// 主管簽核狀態徽章（放在單號旁）
function approvalBadgeHtml(q) {
    const st = q.approval_status || 'none';
    if (st === 'pending')  return ' <span style="display:inline-block;font-size:9px;padding:1px 6px;background:#fff3cd;color:#8a6d1a;border:1px solid #ffe08a;border-radius:10px;font-weight:600;">待審核</span>';
    if (st === 'approved') return ` <span style="display:inline-block;font-size:9px;padding:1px 6px;background:#e8f8f0;color:#1e8449;border:1px solid #a9dfbf;border-radius:10px;font-weight:600;" title="核准人：${escapeHtml(q.approved_by_name||'')}　${escapeHtml((q.approved_at||'').replace('T',' ').slice(0,16))}"><i class="fa fa-check-circle"></i> 已核准</span>`;
    if (st === 'rejected') return ' <span style="display:inline-block;font-size:9px;padding:1px 6px;background:#fdecea;color:#c0392b;border:1px solid #f5b7b1;border-radius:10px;font-weight:600;">待重新送審</span>';
    return '';
}

// 列印按鈕閘門：「正式報價單」才能列印；「需審核通過才能列印」開關開啟時另須已核准（設定頁可關）
function updatePrintGate(q) {
    window._lastPrintGateQuote = q;   // 記住最後閘門判斷的單，設定切換時能立即重套用
    const $btn = $('#printQuoteBtn');
    if (!$btn.length) return;
    const ok = q.is_draft != 1 && q.is_temp_save != 1 && (!printNeedApproval || q.approval_status === 'approved');
    $btn.prop('disabled', !ok);
    if (ok) {
        $btn.removeAttr('title').css({opacity:'', cursor:''});
    } else {
        const reason = q.is_draft == 1 ? '草稿不能列印，請先存為正式報價單'
            : (q.is_temp_save == 1 ? '暫存尚未完成，請先補齊內容並正式存檔' : '尚未通過主管審核，核准後才能列印');
        $btn.attr('title', reason).css({opacity:0.5, cursor:'not-allowed'});
    }
}

// 簽核狀態列：顯示意見/駁回原因、核准/駁回/重新送審按鈕（只在檢視/編輯畫面顯示，不列印）
function renderApprovalBar(q, detail) {
    const $bar = $('#viewApprovalBar');
    const st = q.approval_status || 'none';
    const la = (detail && detail.latest_approval) || null;
    if (st === 'none') { $bar.hide().empty(); return; }

    const fmtDT2 = s => s ? String(s).replace('T',' ').slice(0,16) : '';
    let html = '';
    let bg = '#f7f7f7', border = '#ddd';
    if (st === 'pending') {
        bg = '#fffbea'; border = '#ffe08a';
        html += `<div><i class="fa fa-clock-o"></i> 待主管審核`;
        if (la && la.submitted_by_name) html += `（${escapeHtml(la.submitted_by_name)} 送審 ${escapeHtml(fmtDT2(la.submitted_at))}）`;
        html += `</div>`;
        if (CAN_SIGN) {
            html += `<div style="margin-top:6px;display:flex;gap:6px;align-items:center;">
                <button type="button" class="btn btn-success btn-xs" onclick="decideQuoteApproval('approved')"><i class="fa fa-check"></i> 核准</button>
                <button type="button" class="btn btn-danger btn-xs" onclick="decideQuoteApproval('rejected')"><i class="fa fa-times"></i> 駁回</button>
            </div>`;
        }
    } else if (st === 'approved') {
        bg = '#eafaf1'; border = '#a9dfbf';
        html += `<div><i class="fa fa-check-circle" style="color:#1e8449;"></i> 已核准 — ${escapeHtml(q.approved_by_name||'')}　${escapeHtml(fmtDT2(q.approved_at))}</div>`;
        if (la && la.note) html += `<div style="margin-top:4px;color:#555;">審核意見：${escapeHtml(la.note)}</div>`;
    } else if (st === 'rejected') {
        bg = '#fdecea'; border = '#f5b7b1';
        html += `<div><i class="fa fa-times-circle" style="color:#c0392b;"></i> 已駁回`;
        if (la && la.approver_name) html += ` — ${escapeHtml(la.approver_name)}　${escapeHtml(fmtDT2(la.decided_at))}`;
        html += `</div>`;
        if (la && la.note) html += `<div style="margin-top:4px;color:#c0392b;">駁回原因：${escapeHtml(la.note)}</div>`;
        html += `<div style="margin-top:6px;font-size:11px;color:#888;">請修改內容後手動重新送出審核（不會因存檔自動重送）。</div>`;
        html += `<div style="margin-top:6px;">
            <button type="button" class="btn btn-warning btn-xs" onclick="resubmitQuoteApproval()"><i class="fa fa-paper-plane"></i> 重新送出審核</button>
        </div>`;
    }
    $bar.css({background:bg, border:'1px solid '+border}).html(html).show();
}

// 核准／駁回（駁回必填原因，核准意見選填）
function decideQuoteApproval(decision) {
    const quoteId = currentEditId;
    if (!quoteId) return;
    const doSubmit = (note) => {
        $.post(API_URL, { action:'quotation_approval_decide', quote_id: quoteId, decision, note: note||'' }, res => {
            if (res.success) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title: res.message, showConfirmButton:false, timer:2500 });
                openViewMode(quoteId);
                if (isAllYearsMode) loadAllYears(); else loadQuoteList(<?= $selectedYear ?>);
            } else {
                Swal.fire('無法處理', res.message || '請稍後再試', 'error');
            }
        }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
    };
    if (decision === 'rejected') {
        Swal.fire({
            title: '駁回報價單', icon: 'warning',
            html: `<textarea id="swal-reject-reason" class="swal2-textarea" placeholder="請說明駁回原因（必填）" style="height:80px;"></textarea>`,
            showCancelButton: true, confirmButtonText: '確認駁回', confirmButtonColor: '#c0392b', cancelButtonText: '取消',
            preConfirm: () => {
                const v = document.getElementById('swal-reject-reason').value.trim();
                if (!v) { Swal.showValidationMessage('請填寫駁回原因'); return false; }
                return v;
            }
        }).then(r => { if (r.isConfirmed) doSubmit(r.value); });
    } else {
        Swal.fire({
            title: '核准報價單', icon: 'question',
            html: `<textarea id="swal-approve-note" class="swal2-textarea" placeholder="審核意見（選填）" style="height:70px;"></textarea>`,
            showCancelButton: true, confirmButtonText: '確認核准', confirmButtonColor: '#27ae60', cancelButtonText: '取消',
            preConfirm: () => document.getElementById('swal-approve-note').value.trim()
        }).then(r => { if (r.isConfirmed) doSubmit(r.value); });
    }
}

// 駁回後手動重新送出審核
function resubmitQuoteApproval() {
    const quoteId = currentEditId;
    if (!quoteId) return;
    Swal.fire({ title: '重新送出審核？', icon: 'question', showCancelButton: true, confirmButtonText: '確認送出', cancelButtonText: '取消' }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'quotation_resubmit_approval', quote_id: quoteId }, res => {
            if (res.success) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title: res.message, showConfirmButton:false, timer:2500 });
                openViewMode(quoteId);
            } else {
                Swal.fire('無法送出', res.message || '請稍後再試', 'error');
            }
        }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
    });
}

function closeViewPanel() {
    currentEditId = null;
    $('#viewPanel').hide();
    $('#newQuoteBtn').show();
    $('#editorEmpty').css('display', 'flex');
    renderQuoteList(allQuotes, $('#listSearch').val().trim());
}

// 從檢視模式點擊「編輯」
function openEditorFromView() {
    if (!CAN_EDIT) { Swal.fire('權限不足', '您沒有編輯報價單的權限', 'error'); return; }
    const quote_id = currentEditId;
    if (!quote_id) return;
    const token = ++_editToken;
    $.post(API_URL, { action: 'acquire_lock', quote_id }, lockRes => {
        if (token !== _editToken) return;
        if (!lockRes.acquired) {
            Swal.fire({
                title: '報價單編輯中',
                html: `<b>${escapeHtml(lockRes.locked_name||'其他使用者')}</b> 正在編輯此報價單（${lockRes.elapsed_min||0} 分鐘前開始）<br><small>是否強制接管編輯？</small>`,
                icon: 'warning', showCancelButton: true,
                confirmButtonText: '強制接管', cancelButtonText: '取消',
            }).then(r => {
                if (!r.isConfirmed) return;
                $.post(API_URL, { action: 'acquire_lock', quote_id, force: 1 }, () => {
                    _doLoadEditor(quote_id, token, lockRes.locked_name);
                });
            });
            return;
        }
        _doLoadEditor(quote_id, token, null);
    }).fail(() => _doLoadEditor(quote_id, token, null));
}

function _doLoadEditor(quote_id, token, forcedFromUser) {
    // 啟動心跳（每 5 分鐘刷新鎖定）
    stopLockHeartbeat();
    _lockHeartbeat = setInterval(() => {
        if (currentEditId) $.post(API_URL, { action: 'heartbeat_lock', quote_id: currentEditId });
    }, 5 * 60 * 1000);

    // 顯示編輯面板，隱藏檢視面板；編輯中不可新增
    $('#viewPanel').hide();
    $('#editorEmpty').hide();
    $('#newQuoteBtn').hide();

    if (forcedFromUser) {
        $('#lockWarningMsg').text(`已強制接管 ${forcedFromUser} 的編輯`);
        $('#lockWarningBar').show();
    } else {
        $('#lockWarningBar').hide();
    }

    $.get(API_URL, { action: 'get_detail', quote_id }, res => {
        if (token !== _editToken) return;
        if (!res.success) { Swal.fire('錯誤', res.message || '載入失敗', 'error'); return; }
        const d = res.data;
        $('#quote_id').val(d.quote_id);
        $('#quote_no').val(d.quote_no);
        const _qno = d.quote_no || '';
        $('#quote_no_prefix').text(_qno.length >= 9 ? _qno.slice(0, 9) : quoteNoPrefixFromDate(d.quote_date));
        $('#quote_seq').val(_qno.length >= 9 ? _qno.slice(9) : '');
        $('#last_updated_at').val(d.updated_at || '');
        $('#quote_date').val(d.quote_date);
        $('#valid_until').val(d.valid_until || '');
        $('#client_name').val(d.client_name || '');
        $('#client_id').val(d.client_id || '');
        updateClientBoundCheck();
        $('#inquiry_no').val(d.inquiry_no || '');
        if (d.client_id) loadClientContacts(d.client_id, d.contact_id || null);
        $('#currency').val(d.currency || 'TWD');
        $('#exchange_rate').val(d.exchange_rate || 1);
        $('#note').val(d.note || '').trigger('input');
        $('#is_negotiation').prop('checked', d.is_negotiation == 1);
        (d.items || []).forEach(item => addItemRow(item));
        calculateTotal();
        $('#editorTitle').html(`<i class="fa fa-pencil" style="color:var(--accent);margin-right:6px;"></i>${escapeHtml(d.quote_no)}${approvalBadgeHtml(d)}`);
        if (CAN_VIEW_HISTORY) $('#changeLogBtn').show();
        if (CAN_DELETE)       $('#delQuoteBtn').show();
        if (CAN_CLONE)        $('#cloneQuoteBtn').show();
        showEditor();
        $('#note').trigger('input');
        updatePartSearchPlaceholders();
        updateEditorClientTag();
        loadFileList(d.quote_no);
        renderHistoryBar(d);
    }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
}

function stopLockHeartbeat() {
    if (_lockHeartbeat) { clearInterval(_lockHeartbeat); _lockHeartbeat = null; }
}
function closeEditor() {
    const isNewUnsaved = !$('#quote_id').val();          // 新增且尚未儲存
    const $files       = $('#uploadedFilesList .file-item[data-filename]');
    const fileCount    = $files.length;
    const qno          = getCurrentQuoteNo();

    if (isNewUnsaved && fileCount > 0 && qno) {
        // 有未儲存的附件 → 讓使用者決定
        Swal.fire({
            title: '已上傳附件尚未關聯',
            html: `報價單 <b>${escapeHtml(qno)}</b> 未儲存，<br>
                   已上傳 <b style="color:#e74c3c;">${fileCount}</b> 個附件仍保留在 Z 槽。<br>
                   <small style="color:#aaa;margin-top:4px;display:block;">確認關閉將刪除這些附件。</small>`,
            icon: 'warning',
            showCancelButton:   true,
            confirmButtonColor: '#d33',
            confirmButtonText:  '<i class="fa fa-trash"></i> 刪除附件並關閉',
            cancelButtonText:   '返回繼續編輯',
        }).then(r => {
            if (r.isConfirmed) {
                deleteAllUploadedFilesAndClose(qno);
            }
            // 取消 → 留在編輯頁
        });
    } else {
        doCloseEditor();
    }
}

function doCloseEditor() {
    const wasEditingId = $('#quote_id').val() ? currentEditId : null;
    // 若是新增但未儲存、且曾上傳附件，關閉時一律刪除臨時資料夾
    if (_tempUploadQno && !$('#quote_id').val()) {
        $.post(FILE_API_URL, { action: 'delete_folder', quote_no: _tempUploadQno });
    }
    // 釋放編輯鎖 + 停止心跳
    $.post(API_URL, { action: 'release_lock', quote_id: currentEditId });
    stopLockHeartbeat();
    $('#lockWarningBar').hide();
    _tempUploadQno = null;
    $('#editorPanel').hide();
    resetEditor();
    // 若是編輯現有報價單 → 返回檢視模式；若是新增 → 關閉回空白
    $('#newQuoteBtn').show();
    if (wasEditingId) {
        openViewMode(wasEditingId);
    } else {
        currentEditId = null;
        $('#editorEmpty').css('display', 'flex');
        renderQuoteList(allQuotes, $('#listSearch').val().trim());
    }
}

// 刪除整個報價單資料夾後關閉
function deleteAllUploadedFilesAndClose(quoteNo) {
    $.post(FILE_API_URL, { action: 'delete_folder', quote_no: quoteNo })
        .always(() => doCloseEditor());
}
function showEditor() {
    $('#editorPanel').css('display', 'flex'); // flex column 才能讓 header 凍結、form scroll
    $('#editorEmpty').hide();
    $('#quoteForm').scrollTop(0);             // 捲動區是 form，不是 panel
}
function resetEditor() {
    $('#quoteForm')[0].reset();
    // 顯式清除，確保 hidden field 不殘留上一張的值
    $('#client_id').val('');
    $('#client_name').val('');
    $('#contact_id').val('');
    $('#quote_id').val('');
    $('#source_quote_id').val('');
    $('#quote_no').val('');
    $('#quote_no_prefix').text('');
    $('#quote_seq').val('');
    $('#last_updated_at').val('');
    $('#quoteItemsTable > tbody').empty();
    $('#totalAmountDisplay').text('0');
    $('#currencyDisplay').text($('#currency').find('option:selected').text() || 'NTD');
    $('#uploadedFilesList').empty();
    $('#note-tmpl-btns .note-tmpl-btn').removeClass('nt-applied');
    $('#editorClientNameTag').text('');
    $('#historyBar').hide();
    updateClientBoundCheck();
    loadClientContacts(null); // 清除聯絡人下拉與隱藏列
}

// ══════════════════════════════════════════════════════
// 儲存
// ══════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════
// 項目自動排序：依全域排序規則（可由授權角色調整優先順序）
// 重排編輯畫面列，先跳窗預覽新順序，使用者按「確認排序」
// 才正式重排並把排序號碼直接寫入資料庫（quotation_item.sort_order）。
// 列印一律依存檔順序，不再於列印時自動排序。
// ══════════════════════════════════════════════════════
const AUTO_SORT_KEY_DEFS = [   // 全部可拖移調整優先順序；「建檔順序」固定為最終比較
    { key:'product_id',    label:'料號' },
    { key:'process_notes', label:'製程' },
    { key:'specification', label:'料號備註' },
    { key:'quantity',      label:'數量' },
];
let autoSortKeys = AUTO_SORT_KEY_DEFS.map(d => d.key); // 預設順序＝舊版列印排序規則

function loadAutoSortKeys() {
    $.get(API_URL, { action:'get_param', param_group:'QUOTATION', param_key:'auto_sort_keys' }, res => {
        if (res && res.success && Array.isArray(res.value)) {
            const valid = res.value.filter(k => AUTO_SORT_KEY_DEFS.some(d => d.key === k));
            // 設定裡缺漏的鍵補在最後，保證四個鍵都會比較到
            AUTO_SORT_KEY_DEFS.forEach(d => { if (!valid.includes(d.key)) valid.push(d.key); });
            autoSortKeys = valid;
        }
    }, 'json');
}
function autoSortRuleText() {
    return autoSortKeys.map(k => (AUTO_SORT_KEY_DEFS.find(d => d.key === k) || {}).label || k)
        .concat('建檔順序').join(' → ');
}
// 依 proc-subtags-hidden 的子標籤 ID 轉成可讀製程名稱（預覽用）
function procNamesFromSubTags(csv) {
    return (csv || '').split(',').map(s => parseInt(s.trim())).filter(x => x > 0).map(sid => {
        let name = String(sid);
        processTagTree.forEach(g => (g.sub_tags || []).forEach(st => { if (st.sub_tag_id === sid) name = st.sub_tag_name; }));
        return name;
    }).join('、');
}

function autoSortQuoteItems() {
    const $tbody = $('#quoteItemsTable > tbody');
    const groups = [];
    $tbody.find('> tr.item-row').each(function (idx) {
        const $row = $(this);
        const isTiered = parseInt($row.data('is-tiered')) === 1;
        groups.push({
            // item-row 連同其後的 tier-row / bom-row 一起搬動
            $rows:         $row.add($row.nextUntil('tr.item-row')),
            item_id:       parseInt($row.attr('data-item-id')) || Infinity, // 未存檔新列排最後
            product_id:    ($row.find('.product_id_hidden').val() || '').toUpperCase(),
            process_notes: ($row.find('.proc-subtags-hidden').val() || '').toUpperCase(),
            specification: ($row.find('input[name="specification"]').val() || '').toUpperCase(),
            quantity:      isTiered ? 0 : (parseFloat($row.find('.quantity').val()) || 0),
            procText:      procNamesFromSubTags($row.find('.proc-subtags-hidden').val()),
            specRaw:       $row.find('input[name="specification"]').val() || '',
            pidRaw:        $row.find('.product_id_hidden').val() || '',
            qtyRaw:        isTiered ? '（階梯）' : ($row.find('.quantity').val() || ''),
            idx
        });
    });
    if (groups.length < 2) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'項目不足兩筆，無需排序', showConfirmButton:false, timer:1800 });
        return;
    }
    const cmpStr = (a, b) => a < b ? -1 : (a > b ? 1 : 0);
    const sorted = groups.slice().sort((a, b) => {
        for (const k of autoSortKeys) {
            const r = (k === 'quantity') ? (a.quantity - b.quantity) : cmpStr(a[k], b[k]);
            if (r) return r;
        }
        return a.item_id === b.item_id ? (a.idx - b.idx) : (a.item_id - b.item_id);
    });

    if (sorted.every((g, i) => g.idx === i)) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'目前順序已符合排序規則，無需變更', showConfirmButton:false, timer:2200 });
        return;
    }

    // ── 預覽跳窗：確認才正式重排＋寫入 DB；取消/關閉＝不動 ──
    let bodyRows = '';
    sorted.forEach((g, i) => {
        const moved = g.idx !== i;
        bodyRows += `<tr style="${moved ? 'background:#fdf0dd;font-weight:600;' : ''}">
            <td style="text-align:center;">${i + 1}</td>
            <td style="text-align:center;color:${moved ? '#c0392b' : '#999'};">${g.idx + 1}${moved ? (g.idx > i ? ' ↑' : ' ↓') : ''}</td>
            <td style="text-align:left;">${escapeHtml(g.pidRaw)}</td>
            <td style="text-align:left;">${escapeHtml(g.procText)}</td>
            <td style="text-align:left;">${escapeHtml(g.specRaw)}</td>
            <td style="text-align:right;">${escapeHtml(String(g.qtyRaw))}</td>
        </tr>`;
    });
    Swal.fire({
        title: '自動排序預覽',
        width: 760,
        html: `
            <div style="text-align:left;font-size:12px;color:#8a6d3b;margin-bottom:6px;">
                排序規則：<b>${escapeHtml(autoSortRuleText())}</b>（橘底＝位置有變動）
            </div>
            <div style="max-height:340px;overflow-y:auto;border:1px solid #e8dcc8;border-radius:4px;">
            <table class="table table-condensed" style="font-size:12px;margin:0;">
                <thead><tr style="background:#faf3e8;">
                    <th style="text-align:center;width:50px;">新序</th><th style="text-align:center;width:60px;">原序</th>
                    <th style="text-align:left;">料號</th><th style="text-align:left;">製程</th>
                    <th style="text-align:left;">料號備註</th><th style="text-align:right;width:70px;">數量</th>
                </tr></thead>
                <tbody>${bodyRows}</tbody>
            </table></div>`,
        showCancelButton: true,
        confirmButtonText: '<i class="fa fa-check"></i> 確認排序',
        cancelButtonText: '取消',
        confirmButtonColor: '#F0A24B',
    }).then(r => {
        if (!r.isConfirmed) return; // 取消或關閉跳窗＝不排序
        sorted.forEach(g => $tbody.append(g.$rows));

        // 已存檔的報價單：把新順序直接寫入 DB 排序號碼；未存檔新列待儲存時依列順序補號
        const quoteId    = parseInt($('#quote_id').val()) || 0;
        const orderedIds = sorted.map(g => parseInt(g.$rows.first().attr('data-item-id')) || 0).filter(x => x > 0);
        if (quoteId && orderedIds.length) {
            $.post(API_URL, { action:'save_item_sort', quote_id: quoteId, item_ids: JSON.stringify(orderedIds) }, res => {
                if (res && res.success) {
                    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已自動排序並寫入資料庫', showConfirmButton:false, timer:2200 });
                } else {
                    Swal.fire('錯誤', (res && res.message) || '排序寫入資料庫失敗', 'error');
                }
            }, 'json').fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
        } else {
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已自動排序（儲存後寫入排序號碼）', showConfirmButton:false, timer:2200 });
        }
    });
}

// ══ 自動排序規則設定（角色限定；全體適用）：拖移調整比較優先順序 ══
function openSortRuleSetting() {
    const rows = autoSortKeys.map(k => {
        const d = AUTO_SORT_KEY_DEFS.find(x => x.key === k) || { label:k };
        return `<div class="sortrule-row" data-key="${k}"
            style="display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid #e8dcc8;border-radius:4px;margin-bottom:4px;background:#fffdf9;cursor:default;">
            <span class="sortrule-drag" style="cursor:grab;color:#b5722a;font-size:15px;" title="拖移調整順序">&#9776;</span>
            <span style="font-size:13px;">${escapeHtml(d.label)}</span>
        </div>`;
    }).join('');
    Swal.fire({
        title: '自動排序規則設定',
        width: 420,
        html: `
            <div style="text-align:left;font-size:12px;color:#8a6d3b;margin-bottom:8px;">
                拖移調整比較的優先順序（由上而下）。<b>此設定為全體適用</b>，儲存後所有人的「項目自動排序」都依此規則。
            </div>
            <div id="sortrule-box" style="text-align:left;">${rows}</div>
            <div style="display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px dashed #d5c9b5;border-radius:4px;background:#f6f2ea;color:#999;font-size:13px;">
                <span style="font-size:15px;">&#9776;</span> 建檔順序（固定最後，不可調整）
            </div>`,
        showCancelButton: true,
        confirmButtonText: '<i class="fa fa-save"></i> 儲存規則',
        cancelButtonText: '取消',
        confirmButtonColor: '#F0A24B',
        didOpen: () => {
            const box = document.getElementById('sortrule-box');
            let dragging = null;
            box.querySelectorAll('.sortrule-row').forEach(row => {
                const handle = row.querySelector('.sortrule-drag');
                handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));
                row.addEventListener('dragend',   () => { row.setAttribute('draggable', 'false'); row.style.opacity = ''; dragging = null; });
                row.addEventListener('dragstart', e => { dragging = row; e.dataTransfer.effectAllowed = 'move'; setTimeout(() => row.style.opacity = '0.4', 0); });
                row.addEventListener('dragover',  e => {
                    e.preventDefault();
                    if (!dragging || dragging === row) return;
                    const rect = row.getBoundingClientRect();
                    if (e.clientY < rect.top + rect.height / 2) box.insertBefore(dragging, row);
                    else box.insertBefore(dragging, row.nextSibling);
                });
                row.addEventListener('drop', e => e.preventDefault());
            });
        },
        preConfirm: () => Array.from(document.querySelectorAll('#sortrule-box .sortrule-row')).map(r => r.getAttribute('data-key')),
    }).then(r => {
        if (!r.isConfirmed || !Array.isArray(r.value) || r.value.length !== AUTO_SORT_KEY_DEFS.length) return;
        const newKeys = r.value;
        $.post(API_URL, {
            action:'save_param', param_group:'QUOTATION', param_key:'auto_sort_keys',
            param_value: JSON.stringify(newKeys), description:'報價項目自動排序規則優先順序(全體適用)'
        }, res => {
            if (res && res.success) {
                autoSortKeys = newKeys;
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'排序規則已儲存：' + autoSortRuleText(), showConfirmButton:false, timer:2600 });
            } else {
                Swal.fire('錯誤', (res && res.message) || '規則儲存失敗', 'error');
            }
        }, 'json').fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
    });
}

// 收集容差填寫不完整的階梯區間（容差值/容差單位任一未填即算不完整）
function collectIncompleteTolerance() {
    const miss = [];
    $('#quoteItemsTable > tbody > tr.item-row').each(function () {
        if (parseInt($(this).data('is-tiered')) !== 1) return;
        const pid = $(this).find('.product_id_hidden').val() || '(未填料號)';
        $(this).next('tr.tier-row').find('.tier-tbody tr.tier-input-row').each(function (i) {
            const v = ($(this).find('.tier-tol-value').val() || '').trim();
            const u = ($(this).find('.tier-tol-unit').val() || '').trim();
            if (v === '' || u === '') miss.push(`${pid} 第${i + 1}區間`);
        });
    });
    return miss;
}

function saveQuote(onSuccess) {
    if (!fixAllTiersBeforeSave()) {
        Swal.fire('錯誤', '階梯報價中有區間最小量未填，請補齊後再儲存', 'error'); return;
    }
    // 階梯容差填寫不完整 → 提醒（可仍要存檔）
    const tolMiss = collectIncompleteTolerance();
    if (tolMiss.length) {
        Swal.fire({
            title: '容差填寫不完整',
            html: '下列階梯區間的容差值／容差單位尚未填齊：<br><br>' +
                  tolMiss.map(s => '・' + escapeHtml(s)).join('<br>') +
                  '<br><br><small style="color:#888;">可用「套用容差」一鍵帶入預設容差。</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '仍要存檔',
            cancelButtonText: '返回補填',
            confirmButtonColor: '#F0A24B',
        }).then(r => { if (r.isConfirmed) _saveQuoteNoteGate(onSuccess); });
        return;
    }
    _saveQuoteNoteGate(onSuccess);
}
function _saveQuoteNoteGate(onSuccess) {
    // 備註為空時提示
    if (!$('#note').val().trim()) {
        Swal.fire({
            title: '尚未輸入備註',
            text: '備註欄位目前為空，是否仍要直接存檔？',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '直接存檔',
            cancelButtonText: '取消',
        }).then(r => { if (r.isConfirmed) _doSaveQuote(onSuccess); });
        return;
    }
    _doSaveQuote(onSuccess);
}
function _doSaveQuote(onSuccess) {
    const qd = {
        quote_id:      $('#quote_id').val(),
        quote_no:      $('#quote_no').val().trim(),
        quote_date:    $('#quote_date').val(),
        valid_until:   $('#valid_until').val(),
        client_name:   $('#client_name').val(),
        client_id:     $('#client_id').val(),
        contact_id:    $('#contact_id').val() || null,
        inquiry_no:    $('#inquiry_no').val().trim(),
        currency:      $('#currency').val(),
        exchange_rate: $('#exchange_rate').val(),
        note:          $('#note').val(),
        is_negotiation:  $('#is_negotiation').is(':checked') ? 1 : 0,
        total_amount:    parseFloat($('#totalAmountDisplay').text().replace(/,/g, '')) || 0,
        source_quote_id: $('#source_quote_id').val() || null,
        last_updated_at: $('#last_updated_at').val() || '',
        items: []
    };
    if (!qd.quote_no || !qd.quote_date) {
        Swal.fire('錯誤', '報價單號和報價日期為必填', 'error'); return;
    }
    if (!qd.client_name.trim()) {
        Swal.fire('錯誤', '客戶名稱為必填', 'error'); return;
    }
    if (!String(qd.client_id || '').trim()) {
        Swal.fire('錯誤', '客戶尚未綁定：請從建議清單選擇客戶，不可只輸入文字', 'error'); return;
    }
    let valid = true;
    let validMsg = '';
    $('#quoteItemsTable > tbody > tr.item-row').each(function () {
        const $row     = $(this);
        const isTiered = parseInt($row.data('is-tiered')) === 1;
        const pid      = $row.find('.product_id_hidden').val();
        if (!pid) { valid = false; validMsg = '請填寫所有項目的料號'; return false; }
        if (!$row.find('.d_setting_d_id_hidden').val().trim()) {
            valid = false;
            validMsg = `料號「${pid}」尚未綁定：請從建議清單選擇，或使用「建立新料號」快速建立綁定`;
            return false;
        }
        if (!$row.find('.proc-subtags-hidden').val().trim()) {
            valid = false; validMsg = '所有項目的製程為必選'; return false;
        }
        if (!isTiered) {
            if ($row.find('.quantity').val() === '') {
                valid = false; validMsg = '請填寫所有項目的數量'; return false;
            }
            if ($row.find('.unit-price').val() === '') {
                valid = false; validMsg = '請填寫所有項目的單價'; return false;
            }
        }
        const item = {
            item_id:            $row.data('item-id') || null,
            product_id:         pid,
            d_setting_d_id:     parseInt($row.find('.d_setting_d_id_hidden').val()) || null,
            specification:      $row.find('input[name="specification"]').val(),
            processes:          $row.find('.process-hidden').val(),
            quantity:           isTiered ? 0 : ($row.find('.quantity').val() || 0),
            unit:               $row.find('.item-unit').val() || 'PCS',
            unit_price:         isTiered ? 0 : ($row.find('.unit-price').val() || 0),
            amount:             $row.find('.amount').val().replace(/,/g, ''),
            process_group_type: $row.find('.proc-group-type-hidden').val() || 'single_process',
            process_notes:      $row.find('.proc-subtags-hidden').val(),
            is_tiered:          isTiered ? 1 : 0,
            show_bom:           $row.find('.show-bom-hidden').val() === '1' ? 1 : 0,
            print_bom:          $row.find('.print-bom-hidden').val() === '1' ? 1 : 0,
            tiers: []
        };
        if (isTiered) {
            $row.next('tr.tier-row').find('.tier-tbody tr.tier-input-row').each(function () {
                const $tr = $(this);
                const qmin = $tr.find('.tier-qty-min').val();
                if (!qmin) { valid = false; validMsg = '階梯報價中有區間最小量未填'; return false; }
                const rawQmax = $tr.find('.tier-qty-max').val().trim();
                item.tiers.push({
                    qty_min:         Math.round(parseFloat(qmin)) || 0,
                    qty_max:         rawQmax !== '' ? Math.round(parseFloat(rawQmax)) : '',
                    unit_price:      $tr.find('.tier-unit-price').val(),
                    tolerance_value: $tr.find('.tier-tol-value').val(),
                    tolerance_unit:  $tr.find('.tier-tol-unit').val(),
                    tolerance_note:  $tr.find('.tier-tol-note').val(),
                });
            });
        }
        qd.items.push(item);
    });
    if (!valid) { Swal.fire('錯誤', validMsg || '請檢查報價項目', 'error'); return; }
    if (qd.items.length > MAX_QUOTE_ITEMS) {
        Swal.fire('錯誤', `報價項目最多 ${MAX_QUOTE_ITEMS} 筆料號，目前 ${qd.items.length} 筆，請刪除多餘項目後再儲存`, 'error');
        return;
    }

    // ── 附件未設定類別 → 擋下（僅檢查有 DB 紀錄的附件）──
    const noCatFiles = collectAttachMeta().filter(f => f.attachId && !f.cats.length).map(f => f.name);
    if (noCatFiles.length) {
        Swal.fire({
            icon: 'error', title: '附件尚未設定類別',
            html: '下列附件請先點 <i class="fa fa-tag"></i> 設定類別：<br>' +
                  noCatFiles.map(escapeHtml).join('<br>')
        });
        return;
    }

    // ── 必備附件檢查（議價單豁免）──
    qd.is_draft = 0; // 預設非草稿；檢查通過或議價單皆存為正式單
    if (qd.is_negotiation !== 1) {
        // 含必備類別的附件必須連結「單一」料號（不可共用、不可多料號）
        const reqCats = effectiveRequiredCats();
        const badReqFiles = collectAttachMeta().filter(f =>
            f.attachId &&
            f.cats.some(c => reqCats.some(r => Number(r) === Number(c))) &&
            (!Array.isArray(f.parts) || f.parts.length !== 1)
        );
        if (badReqFiles.length) {
            Swal.fire({
                icon: 'error', title: '必備附件未連結料號',
                html: '下列附件含必備類別，必須連結<b>單一料號</b>：<br>' +
                      badReqFiles.map(f => escapeHtml(f.name)).join('<br>')
            });
            return;
        }
        // 各料號必備附件缺漏 → 明確請使用者選擇要存草稿還是存正式報價單（不再自動預設）
        const missing = getMissingRequiredAttach();
        if (missing.length) {
            Swal.fire({
                icon: 'warning', title: '必備附件缺漏',
                html: '下列料號缺少必備附件類別：<br><div style="text-align:left;display:inline-block;margin-top:6px;">' +
                      missing.map(m => `<b>${escapeHtml(m.pid)}</b>：${escapeHtml(m.names.join('、'))}`).join('<br>') +
                      '</div><p style="margin-top:12px;font-size:12px;color:#888;">' +
                      '<b>存草稿</b>：不能列印、不會送主管審核，等補齊附件後再存檔即可轉正式。<br>' +
                      '<b>存正式報價單</b>：忽略此提醒直接存為正式單，會依規則送出主管審核。</p>',
                showDenyButton: true,
                showCancelButton: true,
                confirmButtonText: '存正式報價單',
                confirmButtonColor: '#27ae60',
                denyButtonText: '存草稿',
                denyButtonColor: '#e67e22',
                cancelButtonText: '返回補齊',
                focusCancel: true,
            }).then(r => {
                if (r.isConfirmed) {
                    qd.is_draft = 0;
                    _postQuoteSave(qd, onSuccess);
                } else if (r.isDenied) {
                    qd.is_draft = 1;
                    _postQuoteSave(qd, onSuccess);
                }
            });
            return;
        }
    }

    _postQuoteSave(qd, onSuccess);
}

// 實際送出儲存（驗證通過後呼叫）
function _postQuoteSave(qd, onSuccess) {
    $.post(API_URL, { action: 'save', data: JSON.stringify(qd) }, res => {
        if (res.success) {
            _tempUploadQno = null;
            allYearsData = null;
            // 若流水號因衝突被自動調整，更新前端顯示
            if (res.quote_no && res.quote_no !== qd.quote_no) {
                $('#quote_no').val(res.quote_no);
                $('#quote_no_prefix').text(res.quote_no.slice(0, 9));
                $('#quote_seq').val(res.quote_no.slice(9));
                Swal.fire({ toast:true, position:'top-end', icon:'info',
                    title:`流水號衝突，已自動調整為 ${res.quote_no}`,
                    showConfirmButton:false, timer:4000 });
            } else if (res.forced_draft) {
                // 伺服器端重新驗證後發現附件仍不足，強制改存草稿（防止繞過/資料過期）
                Swal.fire({ toast:true, position:'top-end', icon:'warning',
                    title:'必備附件仍缺漏，已改存為草稿', showConfirmButton:false, timer:4000 });
            } else {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title: res.message, showConfirmButton:false, timer:2500 });
            }
            // 簽核狀態提示（草稿不進審核，不另外提示）
            if (res.approval_status === 'pending') {
                Swal.fire({ toast:true, position:'top-end', icon:'info',
                    title: printNeedApproval ? '已送出主管審核，待核准後才能列印' : '已送出主管審核',
                    showConfirmButton:false, timer:4000 });
            } else if (res.approval_status === 'approved') {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已自動核准（您本身具簽核權限）',
                    showConfirmButton:false, timer:3000 });
            } else if (res.approval_status === 'rejected') {
                Swal.fire({ toast:true, position:'top-end', icon:'warning', title:'狀態仍為「已駁回」，請至檢視畫面手動重新送出審核',
                    showConfirmButton:false, timer:4500 });
            }
            const savedId = res.new_id;
            if (isAllYearsMode) loadAllYears();
            else loadQuoteList(<?= $selectedYear ?>);
            if (onSuccess) {
                onSuccess();
            } else {
                // 儲存後釋放鎖定並返回檢視模式
                $.post(API_URL, { action: 'release_lock', quote_id: currentEditId });
                stopLockHeartbeat();
                $('#lockWarningBar').hide();
                _tempUploadQno = null;
                $('#editorPanel').hide();
                resetEditor();
                openViewMode(savedId);
            }
        } else if (res.code === 'CONFLICT') {
            // 資料衝突：顯示誰改了什麼，詢問是否強制覆蓋
            const modifier = res.modifier ? `<b>${escapeHtml(res.modifier)}</b> ` : '他人 ';
            const diffHtml = (res.diffs && res.diffs.length)
                ? '<ul style="text-align:left;margin:8px 0 0;padding-left:18px;font-size:12px;">' +
                  res.diffs.map(d => `<li>${escapeHtml(d)}</li>`).join('') + '</ul>'
                : '<small style="color:#888;">（項目欄位或其他欄位有變更）</small>';
            Swal.fire({
                title: '資料衝突',
                html: `${modifier}已修改此報價單，變更如下：${diffHtml}<br><small style="color:#888;margin-top:6px;display:block;">是否強制覆蓋他人的修改？</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '強制覆蓋',
                cancelButtonText: '取消',
            }).then(r => {
                if (!r.isConfirmed) return;
                $('#last_updated_at').val('');
                _doSaveQuote(onSuccess);
            });
        } else {
            Swal.fire('錯誤', res.message, 'error');
        }
    }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
}

// ══════════════════════════════════════════════════════
// ★ 暫存（內容尚未填完也能先存起來，續填後再正式存檔；不驗證必填欄位、不進簽核、不能列印）
// ══════════════════════════════════════════════════════
function tempSaveQuote() {
    const qd = {
        quote_id:      $('#quote_id').val(),
        quote_no:      $('#quote_no').val().trim(),
        quote_date:    $('#quote_date').val(),
        valid_until:   $('#valid_until').val(),
        client_name:   $('#client_name').val(),
        client_id:     $('#client_id').val(),
        contact_id:    $('#contact_id').val() || null,
        inquiry_no:    $('#inquiry_no').val().trim(),
        currency:      $('#currency').val(),
        exchange_rate: $('#exchange_rate').val(),
        note:          $('#note').val(),
        is_negotiation:  $('#is_negotiation').is(':checked') ? 1 : 0,
        total_amount:    parseFloat($('#totalAmountDisplay').text().replace(/,/g, '')) || 0,
        source_quote_id: $('#source_quote_id').val() || null,
        last_updated_at: $('#last_updated_at').val() || '',
        is_temp_save:    1,
        items: []
    };
    if (!qd.quote_no || !qd.quote_date) {
        Swal.fire('錯誤', '報價單號和報價日期為必填', 'error'); return;
    }
    $('#quoteItemsTable > tbody > tr.item-row').each(function () {
        const $row = $(this);
        const pid  = $row.find('.product_id_hidden').val();
        if (!pid) return; // 暫存允許料號未綁定的空白列，該列不存入明細
        const isTiered = parseInt($row.data('is-tiered')) === 1;
        const item = {
            item_id:            $row.data('item-id') || null,
            product_id:         pid,
            d_setting_d_id:     parseInt($row.find('.d_setting_d_id_hidden').val()) || null,
            specification:      $row.find('input[name="specification"]').val(),
            processes:          $row.find('.process-hidden').val(),
            quantity:            isTiered ? 0 : ($row.find('.quantity').val() || 0),
            unit:               $row.find('.item-unit').val() || 'PCS',
            unit_price:         isTiered ? 0 : ($row.find('.unit-price').val() || 0),
            amount:             ($row.find('.amount').val() || '0').replace(/,/g, ''),
            process_group_type: $row.find('.proc-group-type-hidden').val() || 'single_process',
            process_notes:      $row.find('.proc-subtags-hidden').val(),
            is_tiered:          isTiered ? 1 : 0,
            show_bom:           $row.find('.show-bom-hidden').val() === '1' ? 1 : 0,
            print_bom:          $row.find('.print-bom-hidden').val() === '1' ? 1 : 0,
            tiers: []
        };
        if (isTiered) {
            $row.next('tr.tier-row').find('.tier-tbody tr.tier-input-row').each(function () {
                const $tr = $(this);
                const rawQmax = ($tr.find('.tier-qty-max').val() || '').trim();
                item.tiers.push({
                    qty_min:         Math.round(parseFloat($tr.find('.tier-qty-min').val())) || 0,
                    qty_max:         rawQmax !== '' ? Math.round(parseFloat(rawQmax)) : '',
                    unit_price:      $tr.find('.tier-unit-price').val(),
                    tolerance_value: $tr.find('.tier-tol-value').val(),
                    tolerance_unit:  $tr.find('.tier-tol-unit').val(),
                    tolerance_note:  $tr.find('.tier-tol-note').val(),
                });
            });
        }
        qd.items.push(item);
    });
    const hasContent = !!(qd.client_name.trim() || qd.note.trim() || qd.inquiry_no || qd.items.length);
    if (!hasContent) {
        Swal.fire('提示', '目前尚無任何內容，無法暫存', 'warning'); return;
    }
    _postQuoteSave(qd);
}

// ══════════════════════════════════════════════════════
// 刪除
// ══════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════
// ★ 列印功能
// ══════════════════════════════════════════════════════
// 料號查閱圖面（頁內用；比照 NewOrder_Track.php，圖面對所有登入者開放）
// pk＝quotation_item.d_setting_d_id（＝d_setting.d_id）：同名料號可能有多筆主檔，不指名會混在一起
function qlDrawingSpan(pid, pk) {
    if (pid === undefined || pid === null || pid === '') return '';
    const arg = encodeURIComponent(String(pid));
    const q   = (parseInt(pk,10)||0) ? ('?pk=' + (parseInt(pk,10)||0)) : ('?d_id=' + arg);
    return `<span style="cursor:pointer;color:#8a5a00;text-decoration:underline dotted;" title="點擊查閱圖面" onclick="window.open('../pm/bom_viewer.php${q}','drawing_${arg}','width=1100,height=800,resizable=yes,scrollbars=yes')">${escapeHtml(pid)}</span>`;
}

// ══════════════════════════════════════════════════════════════
// 補件重審（功能2）前端：已核准報價單追加附件 → 送簽核者審核
// ══════════════════════════════════════════════════════════════
let _viewQuoteParts = [];   // 目前檢視報價單的料號(product_id)清單
let _suppQno = '';          // 補件中的報價單號
let _suppUploaded = [];     // 本次已上傳的暫存附件 [{attachment_id, filename, original_name}]

function openSupplementModal(quoteNo) {
    _suppQno = quoteNo;
    _suppUploaded = [];
    $('#suppModalQno').text(quoteNo);
    $('#suppFileList').empty();
    $('#suppFileInput').val('');
    $('#suppSubmitBtn').prop('disabled', true);   // 尚無附件／未選類別前不可送審
    $('#supplementModal').modal('show');
}

function _suppHandleFiles(fileList) {
    Array.from(fileList).forEach(file => {
        const fd = new FormData();
        fd.append('action', 'upload_file');
        fd.append('quote_no', _suppQno);
        fd.append('file', file);
        const rowId = 'supprow_' + Math.random().toString(36).slice(2);
        $('#suppFileList').append(`<div id="${rowId}" style="border:1px solid #eee;border-radius:4px;padding:8px;margin-bottom:6px;"><i class="fa fa-spinner fa-spin"></i> 上傳中：${escapeHtml(file.name)}</div>`);
        $.ajax({ url: FILE_API_URL, type:'POST', data:fd, processData:false, contentType:false, dataType:'json' })
          .done(res => {
            if (!res || !res.success) { $('#'+rowId).html(`<span class="text-danger">上傳失敗：${escapeHtml((res&&res.message)||'')}（${escapeHtml(file.name)}）</span>`); return; }
            _suppUploaded.push({ attachment_id: res.attachment_id, filename: res.filename, original_name: res.original_name });
            $('#'+rowId).html(_suppFileRowHtml(res.attachment_id, res.original_name || res.filename, res.filename));
            _suppValidate();
          })
          .fail(() => { $('#'+rowId).html(`<span class="text-danger">上傳失敗（${escapeHtml(file.name)}）</span>`); });
    });
}

function _suppFileRowHtml(attId, name, filename) {
    const reqCats = (typeof effectiveRequiredCats === 'function') ? effectiveRequiredCats() : [];
    const catOpts = fileCategories.length
        ? fileCategories.map(c => {
            const req = reqCats.some(r => Number(r) === Number(c.id));
            return `<label style="margin-right:8px;font-weight:400;"><input type="checkbox" class="supp-cat" value="${c.id}" data-req="${req?1:0}" onchange="_suppSyncPart(this);_suppValidate()"> ${escapeHtml(c.category_name)}${req ? ' <span style="color:#DD5138;" title="必備類別，需連結單一料號">*</span>' : ''}</label>`;
          }).join('')
        : '<span class="text-muted">尚無啟用類別</span>';
    const partOpts = ['<option value="all">共用（此報價單全部料號）</option>']
        .concat(_viewQuoteParts.map(pid => `<option value="${escapeHtml(pid)}">${escapeHtml(pid)}</option>`)).join('');
    // 檔名可點擊開啟：與正式附件相同的 download 端點（暫存檔也可讀取），供補件時確認內容
    const dlUrl = `${FILE_API_URL}?action=download&quote_no=${encodeURIComponent(_suppQno)}&filename=${encodeURIComponent(filename||'')}`;
    const nameHtml = filename
        ? `<a href="${dlUrl}" target="_blank" style="color:#a86a1e;text-decoration:underline;cursor:pointer;" title="點擊開啟檢視內容">${escapeHtml(name)}</a>`
        : escapeHtml(name);
    return `<div class="supp-file" data-att-id="${attId}">
        <div style="font-weight:600;color:#333;margin-bottom:4px;"><i class="fa fa-file-o"></i> ${nameHtml}
            <button class="btn btn-xs btn-link text-danger" style="float:right;padding:0;" onclick="_suppRemove(${attId}, this)"><i class="fa fa-trash"></i> 移除</button></div>
        <div style="font-size:12px;margin-bottom:4px;"><span style="color:#888;">類別（必選）：</span>${catOpts}
            ${reqCats.length ? '<span style="color:#DD5138;font-size:11px;margin-left:4px;">（* 必備類別，須連結單一料號）</span>' : ''}</div>
        <div style="font-size:12px;"><span style="color:#888;">連結料號：</span><select class="supp-part form-control input-sm" style="display:inline-block;width:auto;" onchange="_suppValidate()">${partOpts}</select></div>
    </div>`;
}
// 送出補件審核前的即時檢核：每個附件都必須選好類別（必備類別還需連結單一料號），
// 否則停用「送出補件審核」按鈕——即「附件都需點選完整標籤才能上傳送審」。
function _suppValidate() {
    const reqCats = (typeof effectiveRequiredCats === 'function') ? effectiveRequiredCats() : [];
    const $rows = $('#suppFileList .supp-file');
    let ok = $rows.length > 0;
    $rows.each(function () {
        const cats = $(this).find('.supp-cat:checked').map((i, el) => el.value).get();
        if (!cats.length) { ok = false; return; }
        const hasReq = cats.some(c => reqCats.some(r => Number(r) === Number(c)));
        if (hasReq && $(this).find('.supp-part').val() === 'all') ok = false;
    });
    $('#suppSubmitBtn').prop('disabled', !ok);
}
// 勾選到必備類別時，若目前料號為「共用」則自動改選第一個料號（沒有料號可選則維持，送出時擋下）
function _suppSyncPart(chk) {
    const $row = $(chk).closest('.supp-file');
    const anyReq = $row.find('.supp-cat:checked').filter((i,el)=>String($(el).data('req'))==='1').length > 0;
    const $part = $row.find('.supp-part');
    if (anyReq && $part.val() === 'all') {
        const $firstReal = $part.find('option').filter((i,o)=>o.value!=='all').first();
        if ($firstReal.length) $part.val($firstReal.val());
    }
}

function _suppRemove(attId, btn) {
    const rec = _suppUploaded.find(u => Number(u.attachment_id) === Number(attId));
    if (rec) $.post(FILE_API_URL, { action:'delete_file', quote_no:_suppQno, filename: rec.filename });
    _suppUploaded = _suppUploaded.filter(u => Number(u.attachment_id) !== Number(attId));
    $(btn).closest('.supp-file').remove();
    _suppValidate();
}

function submitSupplement() {
    const $rows = $('#suppFileList .supp-file');
    if (!$rows.length) { Swal.fire('提示','請先上傳要補的附件','info'); return; }
    const reqCats = (typeof effectiveRequiredCats === 'function') ? effectiveRequiredCats() : [];
    const plan = [];
    let noCat = false, needPart = false;
    $rows.each(function () {
        const attId = $(this).data('att-id');
        const cats  = $(this).find('.supp-cat:checked').map((i,el)=>el.value).get();
        const part  = $(this).find('.supp-part').val();
        if (!cats.length) { noCat = true; return; }
        const hasReq = cats.some(c => reqCats.some(r => Number(r) === Number(c)));
        if (hasReq && part === 'all') { needPart = true; }
        plan.push({ attId, cats, linked: (part === 'all') ? 'all' : JSON.stringify([part]) });
    });
    if (noCat)    { Swal.fire('請設定類別','每個補件附件都必須至少選一個類別','warning'); return; }
    if (needPart) { Swal.fire('必備類別需連結料號','所選類別含必備附件類別，必須連結單一料號（不可設為「共用」）','warning'); return; }
    const ids = plan.map(p => p.attId);
    const savers = plan.map(p => $.post(FILE_API_URL, { action:'update_attachment', attachment_id:p.attId, category_ids:p.cats.join(','), linked_parts:p.linked }));
    $('#suppSubmitBtn').prop('disabled', true);
    $.when.apply($, savers).always(() => {
        $.post(FILE_API_URL, { action:'submit_supplement', quote_no:_suppQno, attachment_ids: JSON.stringify(ids) }, res => {
            $('#suppSubmitBtn').prop('disabled', false);
            if (res && res.success) {
                _suppUploaded = [];   // 已送審者為 pending，勿在關閉 modal 時被當殘留刪除
                $('#supplementModal').modal('hide');
                let msg = res.message || '已送出補件審核';
                if (res.skipped && res.skipped.length) msg += '（略過：' + res.skipped.join('、') + '）';
                Swal.fire('已送出', msg, 'success');
                loadSupplementAlerts(false);   // 送審後更新待處理徽章/篩選集合
                if (typeof loadFileList === 'function') loadFileList(_suppQno, true);
            } else {
                Swal.fire('送出失敗', (res && res.message) || '請稍後再試', 'error');
            }
        }, 'json').fail(() => { $('#suppSubmitBtn').prop('disabled', false); Swal.fire('錯誤','與伺服器通訊失敗','error'); });
    });
}

// ── 簽核者：補件待審清單 ──
function openSupplementReview() {
    $('#suppReviewBody').html('<div class="text-center text-muted" style="padding:20px;"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#supplementReviewModal').modal('show');
    $.get(FILE_API_URL, { action:'list_pending_supplements' }, res => {
        if (!res || !res.success) { $('#suppReviewBody').html('<p class="text-danger">載入失敗</p>'); return; }
        if (!res.items.length) { $('#suppReviewBody').html('<p class="text-muted text-center" style="padding:20px;">目前沒有待審核的補件</p>'); refreshSuppReviewBadge(); return; }
        $('#suppReviewBody').html(res.items.map(_suppReviewRow).join(''));
        refreshSuppReviewBadge();
    }, 'json');
}

function _suppReviewRow(it) {
    const dlUrl = `${FILE_API_URL}?action=download&quote_no=${encodeURIComponent(it.quote_no)}&filename=${encodeURIComponent(it.filename)}`;
    return `<div class="supp-review-item" data-att-id="${it.id}" style="border:1px solid #eee;border-radius:5px;padding:10px 12px;margin-bottom:8px;">
        <div style="margin-bottom:4px;"><strong>${escapeHtml(it.quote_no)}</strong>
            <span style="color:#888;">${escapeHtml(it.client_name||'')}</span>
            <span style="float:right;color:#999;font-size:11px;">${escapeHtml(it.uploaded_at||'')} · ${escapeHtml(it.uploader_name||'')}</span></div>
        <div style="font-size:12px;margin-bottom:6px;">
            <i class="fa fa-file-o"></i> <a href="${dlUrl}" target="_blank">${escapeHtml(it.original_name||it.filename)}</a>
            <span style="margin-left:8px;color:#8a5a00;">類別：${escapeHtml(it.category_label||'—')}</span>
            <span style="margin-left:8px;color:#8e44ad;">料號：${escapeHtml(it.part_label||'—')}</span>
        </div>
        <div style="text-align:right;">
            <button class="btn btn-xs btn-default" onclick="window.open('quotation_supplement_view.php?att=${it.id}')" title="檢視報價單原內容/原附件後再審核"><i class="fa fa-eye"></i> 詳情</button>
            <button class="btn btn-xs btn-success" onclick="decideSupplement(${it.id}, 'approve', this)"><i class="fa fa-check"></i> 核准</button>
            <button class="btn btn-xs btn-danger" onclick="decideSupplement(${it.id}, 'reject', this)"><i class="fa fa-times"></i> 駁回</button>
        </div>
    </div>`;
}

function decideSupplement(attId, decision, btn) {
    const doPost = (note) => {
        $(btn).closest('.supp-review-item').find('button').prop('disabled', true);
        $.post(FILE_API_URL, { action:'decide_supplement', attachment_id:attId, decision, note: note||'' }, res => {
            if (res && res.success) {
                $(btn).closest('.supp-review-item').slideUp(150, function () {
                    $(this).remove();
                    if (!$('#suppReviewBody .supp-review-item').length) $('#suppReviewBody').html('<p class="text-muted text-center" style="padding:20px;">目前沒有待審核的補件</p>');
                });
                refreshSuppReviewBadge();
                loadSupplementAlerts(false);   // 決行後更新待處理徽章/篩選集合
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:res.message, showConfirmButton:false, timer:1800 });
            } else {
                $(btn).closest('.supp-review-item').find('button').prop('disabled', false);
                Swal.fire('處理失敗', (res && res.message) || '請稍後再試', 'error');
            }
        }, 'json').fail(() => { $(btn).closest('.supp-review-item').find('button').prop('disabled', false); Swal.fire('錯誤','與伺服器通訊失敗','error'); });
    };
    if (decision === 'reject') {
        Swal.fire({ title:'駁回原因（必填）', input:'textarea', inputPlaceholder:'請說明不通過原因，將通知上傳者', showCancelButton:true, confirmButtonText:'駁回', cancelButtonText:'取消',
            inputValidator: v => (!v || !v.trim()) ? '請填寫駁回原因' : undefined })
          .then(r => { if (r.isConfirmed) doPost(r.value.trim()); });
    } else {
        doPost('');
    }
}

function refreshSuppReviewBadge() {
    if (!CAN_SIGN) return;
    $.get(FILE_API_URL, { action:'list_pending_supplements' }, res => {
        const n = (res && res.success && res.items) ? res.items.length : 0;
        const $b = $('#suppReviewBadge');
        if (n > 0) $b.text(n).show(); else $b.hide();
    }, 'json');
}

// ══════════════════════════════════════════════════════════════
// 進站提醒跳窗（補件被駁回/待審）＋ 待處理單據篩選（報價單本身待審/被駁回）
// 兩者是不同維度：進站提醒＝補件層級；待處理單據按鈕/篩選＝報價單簽核層級。
// ══════════════════════════════════════════════════════════════
// showAlert：是否於載入後跳出進站提醒視窗（僅進站首次為 true）
// 補件被駁回/待審皆以伺服器 status 為權威；補件通知 OR-gate（有人審完即收回）由後端 status 變更自然反映。
function loadSupplementAlerts(showAlert) {
    $.get(FILE_API_URL, { action:'supplement_alerts' }, res => {
        if (!res || !res.success) return;
        pendingAlertData = { rejected: res.rejected || [], pending: res.pending || [] };
        // 若進站提醒視窗開著，同步刷新其內容（他人審核後即時反映；已無項目則關閉）
        const total = pendingAlertData.rejected.length + pendingAlertData.pending.length;
        if ($('#pendingAlertModal').hasClass('in')) {
            if (total > 0) renderPendingAlertBody(); else $('#pendingAlertModal').modal('hide');
        }
        // 進站跳窗（有資料才跳；點窗外自動關閉＝Bootstrap 預設 backdrop 行為）
        if (showAlert && total > 0) showPendingAlertModal();
    }, 'json');
}
// 「待處理單據」按鈕徽章＝報價單本身待審/被駁回的張數（依 allQuotes 即時算）
function refreshPendingDocBadge() {
    const n = allQuotes.filter(q => q.approval_status === 'pending' || q.approval_status === 'rejected').length;
    const $b = $('#pendingDocBadge');
    if (n > 0) $b.text(n).show(); else $b.hide();
}
// 「暫存未完成」按鈕徽章＝自己建立、內容尚未填完仍先存檔的張數
function refreshTempSaveDocBadge() {
    const n = allQuotes.filter(q => q.is_temp_save == 1 && Number(q.created_by) === CURRENT_UID).length;
    const $b = $('#tempSaveDocBadge');
    if (n > 0) $b.text(n).show(); else $b.hide();
}

function showPendingAlertModal() {
    renderPendingAlertBody();
    $('#pendingAlertModal').modal('show');  // 點窗外＝backdrop 預設可關閉
}
function renderPendingAlertBody() {
    const rej = pendingAlertData.rejected, pen = pendingAlertData.pending;
    let html = '';
    if (rej.length) {
        html += `<div style="font-weight:600;color:#DD5138;margin-bottom:6px;"><i class="fa fa-times-circle"></i> 您被駁回的補件（${rej.length}）</div>`;
        rej.forEach(r => {
            const dl = `${FILE_API_URL}?action=download&quote_no=${encodeURIComponent(r.quote_no)}&filename=${encodeURIComponent(r.filename)}`;
            html += `<div style="border:1px solid #f3d6cd;background:#fdf4f1;border-radius:5px;padding:8px 10px;margin-bottom:6px;">
                <div><strong>${escapeHtml(r.quote_no)}</strong> <span style="color:#888;">${escapeHtml(r.client_name||'')}</span></div>
                <div style="font-size:12px;margin:2px 0;"><i class="fa fa-file-o"></i> <a href="${dl}" target="_blank" style="color:#a86a1e;text-decoration:underline;">${escapeHtml(r.original_name||r.filename)}</a>
                    ${r.category_label?`<span style="margin-left:6px;color:#8a5a00;">類別：${escapeHtml(r.category_label)}</span>`:''}</div>
                <div style="font-size:12px;color:#c0392b;">駁回原因：${escapeHtml(r.trashed_reason||'—')}${r.expire_at?`　<span style="color:#999;">（${escapeHtml(r.expire_at)} 前可補救，逾期自動刪除）</span>`:''}</div>
            </div>`;
        });
    }
    if (pen.length) {
        html += `<div style="font-weight:600;color:#a86a1e;margin:${rej.length?'12px':'0'} 0 6px;"><i class="fa fa-hourglass-half"></i> 待您審核的補件（${pen.length}）</div>`;
        pen.forEach(p => {
            const dl = `${FILE_API_URL}?action=download&quote_no=${encodeURIComponent(p.quote_no)}&filename=${encodeURIComponent(p.filename)}`;
            html += `<div style="border:1px solid #f0e0c8;background:#fffaf2;border-radius:5px;padding:8px 10px;margin-bottom:6px;">
                <div><strong>${escapeHtml(p.quote_no)}</strong> <span style="color:#888;">${escapeHtml(p.client_name||'')}</span>
                    <span style="float:right;color:#999;font-size:11px;">${escapeHtml(p.uploaded_at||'')} · ${escapeHtml(p.uploader_name||'')}</span></div>
                <div style="font-size:12px;margin:2px 0;"><i class="fa fa-file-o"></i> <a href="${dl}" target="_blank" style="color:#a86a1e;text-decoration:underline;">${escapeHtml(p.original_name||p.filename)}</a>
                    ${p.category_label?`<span style="margin-left:6px;color:#8a5a00;">類別：${escapeHtml(p.category_label)}</span>`:''}</div>
            </div>`;
        });
    }
    if (!html) html = '<p class="text-muted text-center" style="padding:20px;">目前沒有待處理項目</p>';
    $('#pendingAlertBody').html(html);
}

// 篩選：只顯示報價單本身「待審核/被駁回」的單（已核准者不列入）
function applyPendingFilter() {
    const n = allQuotes.filter(q => q.approval_status === 'pending' || q.approval_status === 'rejected').length;
    if (n === 0) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'目前沒有待處理（待審核/被駁回）的報價單', showConfirmButton:false, timer:2500 });
        return;
    }
    tempSaveFilterMode = false;
    pendingFilterMode = true;
    $('#showAllDocBtn').show();   // 篩選中的狀態指示＝出現「顯示全部」鈕（按鈕本身樣式維持一致，不變色）
    renderQuoteList(allQuotes, $('#listSearch').val().trim());
    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已篩選待處理單據（報價單待審核/被駁回），點「顯示全部」可還原', showConfirmButton:false, timer:2800 });
}
// 篩選：只顯示自己暫存中尚未完成的報價單
function applyTempSaveFilter() {
    const n = allQuotes.filter(q => q.is_temp_save == 1 && Number(q.created_by) === CURRENT_UID).length;
    if (n === 0) {
        Swal.fire({ toast:true, position:'top-end', icon:'info', title:'目前沒有暫存未完成的報價單', showConfirmButton:false, timer:2500 });
        return;
    }
    pendingFilterMode = false;
    tempSaveFilterMode = true;
    $('#showAllDocBtn').show();
    renderQuoteList(allQuotes, $('#listSearch').val().trim());
    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已篩選您暫存未完成的報價單，點「顯示全部」可還原', showConfirmButton:false, timer:2800 });
}
// 取消篩選，還原全部
function clearDocFilters() {
    pendingFilterMode = false;
    tempSaveFilterMode = false;
    $('#showAllDocBtn').hide();
    renderQuoteList(allQuotes, $('#listSearch').val().trim());
}
// 篩選畫面下操作提醒（3 秒自動消失）
function _pendingFilterHint() {
    if (!pendingFilterMode && !tempSaveFilterMode) return;
    const title = pendingFilterMode ? '目前在「待處理單據」篩選畫面下' : '目前在「暫存未完成」篩選畫面下';
    Swal.fire({ toast:true, position:'top-end', icon:'info', title, showConfirmButton:false, timer:3000 });
}

// 補件 modal 上傳區事件綁定 + 待審徽章初始化
$(function () {
    // 注意：#suppFileInput 巢狀在 #suppDrop 內，input 的 click 會冒泡回 suppDrop，
    // 若無條件再 .click() 會無限遞迴（Maximum call stack）→ 只在點擊來源非 input 時才觸發
    $('#suppDrop').on('click', function (e) { if (e.target.id !== 'suppFileInput') $('#suppFileInput').click(); });
    $('#suppFileInput').on('change', function () { if (this.files.length) _suppHandleFiles(this.files); this.value=''; });
    $('#suppDrop')
        .on('dragover', e => { e.preventDefault(); $('#suppDrop').css('background','#fdf2e2'); })
        .on('dragleave', () => $('#suppDrop').css('background',''))
        .on('drop', e => { e.preventDefault(); $('#suppDrop').css('background',''); const dt = e.originalEvent.dataTransfer; if (dt && dt.files.length) _suppHandleFiles(dt.files); });
    // 補件 modal 關閉但未送審 → 刪除本次上傳的暫存檔，避免殘留（已送審者已從清單清空，不受影響）
    $('#supplementModal').on('hidden.bs.modal', function () {
        const leftover = _suppUploaded.slice();
        _suppUploaded = [];
        leftover.forEach(u => $.post(FILE_API_URL, { action:'delete_file', quote_no:_suppQno, filename:u.filename }));
    });
    if (CAN_SIGN) refreshSuppReviewBadge();
});

function printQuote() {
    _pendingFilterHint();   // 待處理篩選畫面下提醒（3秒自動消失）
    if (!currentEditId) { Swal.fire('提示','請先儲存報價單再列印','warning'); return; }
    $.get(API_URL, { action:'get_print_data', quote_id: currentEditId }, res => {
        if (!res.success) { Swal.fire('錯誤', res.message || '無法取得資料', 'error'); return; }
        const { quote, customer, contact, company, form_number, as_doc_no } = res;
        // 伺服器端資料為準的最後防線：草稿一律擋下；「需審核通過才能列印」開關開啟時未核准也擋（前端按鈕閘門可能被繞過）
        if (quote.is_draft == 1 || quote.is_temp_save == 1 || (printNeedApproval && quote.approval_status !== 'approved')) {
            const reason = quote.is_draft == 1 ? '草稿不能列印，請先存為正式報價單。'
                : (quote.is_temp_save == 1 ? '暫存尚未完成，請先補齊內容並正式存檔。' : '尚未通過主管審核，核准後才能列印。');
            Swal.fire('無法列印', reason, 'warning');
            return;
        }
        // 從記憶體的 processTagTree 把子標籤名稱注入 items
        (quote.items || []).forEach(item => {
            const subIds = (item.process_notes || '').split(',')
                .map(s => parseInt(s.trim())).filter(x => x > 0);
            const names = [];
            subIds.forEach(sid => {
                processTagTree.forEach(g => (g.sub_tags || []).forEach(st => {
                    if (st.sub_tag_id === sid) names.push(st.sub_tag_name);
                }));
            });
            item.process_names = names.join('・');
        });
        const win = window.open('', '_blank', 'width=900,height=700');
        win.document.write(buildPrintHtml(quote, customer, contact, company, as_doc_no || form_number));
        win.document.close();
        // window.print() 已改在 buildPrintHtml 產出的內嵌 script 完成動態分頁量測後自行呼叫，
        // 不再依賴 win.onload（曾因時機競速偶爾不會自動跳出列印視窗）
        // 記錄列印
        $.post(API_URL, { action:'log_print', quote_id: currentEditId, quote_no: quote.quote_no||'' });
    });
}

function buildPrintHtml(q, cust, contact, co, formNo) {
    const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const fmtNum = n => parseFloat(n||0).toLocaleString('zh-TW', {minimumFractionDigits:0, maximumFractionDigits:2});
    // 料號可點擊查閱圖面（比照 NewOrder_Track.php）。此為獨立 about:blank 列印視窗，需用絕對網址，
    // 圖面查閱對所有登入者開放故不設權限閘門（bom_viewer 自帶登入驗證）。
    const _bomViewerUrl = new URL('../pm/bom_viewer.php', location.href).href;
    // pk＝d_setting.d_id：同名料號可能有多筆主檔（不同客戶／版次），不指名會混在一起
    const pnCell = (pid, pk) => {
        if (pid === undefined || pid === null || pid === '') return '';
        const arg = encodeURIComponent(String(pid));
        const q   = (parseInt(pk,10)||0) ? ('?pk=' + (parseInt(pk,10)||0)) : ('?d_id=' + arg);
        return `<span class="pn-link" title="點擊查閱圖面" onclick="window.open('${_bomViewerUrl}${q}','drawing_${arg}','width=1100,height=800,resizable=yes,scrollbars=yes')">${esc(pid)}</span>`;
    };

    // 公司資料
    const coName    = co.customer_full || co.customer || '';
    const coAddr    = co.customer_address || '';
    // EGStamp（圖章SVG產生器）讀 window.__ownCompany 取公司全名，這裡直接用已取得的 co 資料設定，不必另外呼叫API
    window.__ownCompany = coName;
    const coTel     = co.customer_tel || '';
    const coFax     = co.customer_fax || '';

    // 客戶資料
    const custName  = cust ? (cust.customer_full || cust.customer || q.client_name) : q.client_name;
    const custTel   = cust ? (cust.customer_tel || '') : '';
    const custFax   = cust ? (cust.customer_fax || '') : '';
    const contactName = contact ? (() => {
        const parts = [contact.name];
        if (contact.title) parts.push(contact.title);
        const tel = contact.phone_ext ? '分機 ' + contact.phone_ext : (contact.mobile || '');
        if (tel) parts.push(tel);
        return parts.join('・');
    })() : '';

    // 日期顯示（ai-rules/20：西元年一律 YYYY.MM.DD，唯一實作 egFmtDate()，勿自寫）
    const dispDate = d => egFmtDate(d);

    // 明細列：不做任何 JS 分頁計算，全部明細放進單一連續表格，交給瀏覽器列印引擎自動分頁——
    // 只有引擎自己知道每張紙真正能印多少（字型渲染、列印縮放、各印表機可印範圍的差異它都會算進去），
    // 填滿才換頁；JS 在螢幕上量高度再自己切頁的作法，量測結果跟引擎實排永遠有落差，會提早換頁或內容溢出。
    const items = q.items || [];
    // 階梯報價項目集中排在最上面（彼此依存檔排序），一般數量報價排在下方，兩群之間以粗線分隔
    const _isTierFn   = it => it.is_tiered == 1 && (it.tiers || []).length > 0;
    const tieredItems = items.filter(_isTierFn);
    const normalItems = items.filter(it => !_isTierFn(it));
    const printItems  = tieredItems.concat(normalItems);
    let totalAmt = 0;
    let hasTiered = tieredItems.length > 0; // 有階梯報價項目時，合計加註「階梯項目依訂購量另計」
    // 整筆訂單所有階梯區間的容差完全相同 → 各階不重複印小字，改在底下備註統一註明（含對應項次）
    const _tolKey = t => {
        const v = (t.tolerance_value === null || t.tolerance_value === undefined || t.tolerance_value === '') ? '' : String(parseFloat(t.tolerance_value));
        return v === '' ? '' : v + '|' + (t.tolerance_unit || '') + '|' + (t.tolerance_note || '');
    };
    const _tolKeys   = [...new Set(tieredItems.flatMap(it => it.tiers.map(_tolKey)))];
    const uniformTol = hasTiered && _tolKeys.length === 1 && _tolKeys[0] !== '';
    const uniformTolSample = uniformTol ? tieredItems[0].tiers[0] : null;
    const itemChunks = []; // 每個元素＝{html, tiered}：一個主料號的所有列(含BOM子件續列)
    printItems.forEach((it, idx) => {
        const specNo   = it.spec_no       || '';
        const gearSpec = it.gear_spec     || '';
        const procStr  = it.process_names || '';
        const specRmk  = it.specification || '';
        // 料號規格+齒輪規格 / 製程 / 料號備註(報價單內)，無料號備註省略含分隔
        const leftSpec = [specNo, gearSpec].filter(Boolean).join(' ');
        const desc     = [leftSpec, procStr, specRmk].filter(Boolean).join(' / ');
        const isTieredIt = _isTierFn(it);
        // 階梯群與一般群交界的第一筆一般項目：上緣加粗線分隔
        const isGroupDivider = hasTiered && normalItems.length > 0 && idx === tieredItems.length;
        const qty    = it.quantity || 0;
        const price  = it.unit_price || 0;
        const amt    = parseFloat(it.amount || (qty * price));
        if (!isTieredIt) totalAmt += amt;   // 階梯項目金額依訂購量另計，不列入合計
        const unit = it.unit || 'PCS';
        const negoTag = q.is_negotiation == 1
            ? `<span style="font-size:8.5pt;color:#c0392b;font-weight:bold;margin-right:2px;">議價</span>` : '';
        const priceCell = negoTag + fmtNum(price);
        // 組合件子件清單（勾選「列印時包含」才印；併入品名規格儲存格，與母件同格顯示，全數列出、不收合）
        // 子件過多時分段成多列（每列禁止跨頁），續列產品編號重複標示「（續）」，數量/單價等不重複
        const BOM_PRINT_CHUNK = 12;
        let kidChunks = [];
        if (it.print_bom == 1 && (it.bom_children || []).length) {
            const lines = it.bom_children.map(c => {
                const extra = [c.spec_no, c.Remark_Bom].filter(Boolean).join('・');
                return `<div style="font-size:9pt;">└ ${esc(c.part_no)} ${fmtBomQty(c.standard_qty)}PCS/組${extra ? `（${esc(extra)}）` : ''}</div>`;
            });
            for (let i = 0; i < lines.length; i += BOM_PRINT_CHUNK) {
                kidChunks.push(lines.slice(i, i + BOM_PRINT_CHUNK).join(''));
            }
        }
        let rowHtml = '';
        if (isTieredIt) {
            // ── 階梯報價：料號/品名/單位/金額用 rowspan 合併儲存格，每階一列「數量區間｜單價」；
            //    金額欄不顯示（依訂購量另計）；區間右對齊讓各階數量對齊
            const n = it.tiers.length;
            const rangeTxt = t => {
                const mn = fmtNum(Math.round(Number(t.qty_min || 0)));
                return (t.qty_max === null || t.qty_max === undefined || t.qty_max === '')
                    ? `${mn}以上` : `${mn}~${fmtNum(Math.round(Number(t.qty_max)))}`;
            };
            const tolTxt = t => {
                if (t.tolerance_value === null || t.tolerance_value === undefined || t.tolerance_value === '') return '';
                const note = t.tolerance_note ? `<br>${esc(t.tolerance_note)}` : '';
                return `<div style="font-size:8pt;color:#333;">容差±${fmtNum(t.tolerance_value)}${esc(t.tolerance_unit || '')}${note}</div>`;
            };
            it.tiers.forEach((t, ti) => {
                rowHtml += '<tr>'
                    + (ti === 0 ? `
                    <td class="center" rowspan="${n}">${idx + 1}</td>
                    <td rowspan="${n}">${pnCell(it.product_id, it.d_setting_d_id)}</td>
                    <td rowspan="${n}">${esc(desc)}<div style="font-size:8.5pt;color:#333;">（階梯報價，單價依訂購數量區間）</div>${kidChunks[0] || ''}</td>` : '')
                    + `
                    <td class="right">${rangeTxt(t)}${uniformTol ? '' : tolTxt(t)}</td>
                    <td class="center">${esc(unit)}</td>
                    <td class="right">${negoTag}${fmtNum(t.unit_price || 0)}</td>
                    <td></td>
                </tr>`;
            });
        } else {
            const divStyle = isGroupDivider ? ' style="border-top:4px double #000;"' : '';
            rowHtml = `<tr>
            <td class="center"${divStyle}>${idx+1}</td>
            <td${divStyle}>${pnCell(it.product_id, it.d_setting_d_id)}</td>
            <td${divStyle}>${esc(desc)}${kidChunks[0] || ''}</td>
            <td class="right"${divStyle}>${fmtNum(qty)}</td>
            <td class="center"${divStyle}>${esc(unit)}</td>
            <td class="right"${divStyle}>${priceCell}</td>
            <td class="right"${divStyle}>${fmtNum(amt)}</td>
        </tr>`;
        }
        kidChunks.slice(1).forEach(chunk => {
            rowHtml += `<tr>
                <td></td>
                <td>${pnCell(it.product_id, it.d_setting_d_id)}（續）</td>
                <td>${chunk}</td>
                <td></td><td></td><td></td><td></td>
            </tr>`;
        });
        itemChunks.push({ html: rowHtml, tiered: isTieredIt });
    });

    // 合計 / 稅額（5%）/ 總額（階梯項目不列入；全部都是階梯時金額欄顯示「依訂購量計」）
    const tax   = Math.round(totalAmt * 0.05);
    const grand = totalAmt + tax;
    const allTiered = hasTiered && totalAmt === 0;
    const amtCell = v => allTiered ? '<span style="font-size:9pt;letter-spacing:1px;">依訂購量計</span>' : fmtNum(v);
    // 整單容差一致時統一在備註註明（含對應項次；階梯項目固定排最前，項次即 1..n）
    let uniformTolHtml = '';
    if (uniformTol && uniformTolSample) {
        const itemNos = tieredItems.map((_, i) => i + 1).join('、');
        const noteTxt = uniformTolSample.tolerance_note ? `（${esc(uniformTolSample.tolerance_note)}）` : '';
        uniformTolHtml = `<p style="margin:4px 0;font-size:9pt;"><strong>●數量容差：</strong>項次${itemNos} 各數量區間容差±${fmtNum(uniformTolSample.tolerance_value)}${esc(uniformTolSample.tolerance_unit || '')}${noteTxt}。</p>`;
    }
    const tierNoteHtml = (hasTiered
        ? `<p style="margin:4px 0;font-size:9pt;"><strong>●階梯報價：</strong>階梯項目金額依實際訂購數量與對應區間單價計算${allTiered ? '' : '，未列入合計'}。</p>`
        : '') + uniformTolHtml;
    const remark = q.note || '';
    const remarkHtml = remark.split(/[；;]/).map(s => esc(s.trim())).filter(Boolean).join('<br>');

    // 製單章（無條件顯示）：修改者優先，沒有修改過就用建立者；日期比照
    const fmtDot = s => s ? String(s).substring(0,10).replace(/-/g,'.') : '';
    const makerName = q.updated_by_name || q.created_by_name || '';
    const makerDate = fmtDot(q.updated_at || q.created_at);
    const makerStampHtml = makerName ? EGStamp.stamp(makerName, makerDate) : '';
    // 主管簽核章：只有已核准才蓋，未核准/待審核/駁回一律留白讓人工簽
    const approverStampHtml = (q.approval_status === 'approved' && q.approved_by_name)
        ? EGStamp.stamp(q.approved_by_name, fmtDot(q.approved_at))
        : '';

    // 跨頁識別列改用 @page 頁首 margin box（見下方 CSS）：第 2 頁起每頁頁首自動印「單號／客戶」，
    // 第 1 頁由 @page :first 蓋掉不印——舊作法把它放在 thead 會連第 1 頁也重複出現（表頭上方已有 meta 區）
    // 欄寬一律由 colgroup 決定（table-layout:fixed 取第一列儲存格算欄寬）
    const contInfoTxt = `單號：${String(q.quote_no || '').replace(/['\\]/g, '')}　客戶：${String(custName || '').replace(/['\\]/g, '')}`;
    const itemsColgroupHtml = `<colgroup>
        <col style="width:4%"><col style="width:20%"><col style="width:39%">
        <col style="width:12%"><col style="width:5%"><col style="width:9%"><col style="width:11%">
      </colgroup>`; // 數量欄 7→12%（放得下階梯區間如 1,001~10,000），由品名欄 44→39% 挪出
    const itemsTheadHtml = `<thead>
        <tr>
          <th>項次</th>
          <th>產品編號</th>
          <th>品名規格／加工項目 / 備註</th>
          <th>數量</th>
          <th>單位</th>
          <th>單價</th>
          <th>金額</th>
        </tr>
      </thead>`;
    const sigRowHtml = `<table class="footer-area">
        <colgroup>
          <col class="rem"><!-- 80%: 項次+料號+品名+數量+單位 -->
          <col class="lbl"><!-- 9%: 單價 -->
          <col class="val"><!-- 11%: 金額 -->
        </colgroup>
        <tr>
          <td class="remark-cell" rowspan="3"><p style="margin:4px 0;"><strong>●備註：</strong>${remarkHtml}</p>${tierNoteHtml}</td>
          <td class="total-lbl">合　計</td>
          <td class="total-val">${amtCell(totalAmt)}</td>
        </tr>
        <tr><td class="total-lbl">稅　額</td><td class="total-val">${amtCell(tax)}</td></tr>
        <tr><td class="total-lbl">總　額</td><td class="total-val">${amtCell(grand)}</td></tr>
      </table>
      <div class="page-footer">
        <div class="sig-row">
          <span>製單人員：${makerStampHtml}</span>
          <span>客戶簽收：</span>
          <span>主管審核：${approverStampHtml}</span>
        </div>
      </div>`;
    // AS 文件編號（ai-rules/16）：每頁頁尾右下角由 @page @bottom-right 印，不再只印在最後一頁；
    // 動態塞進 content 前先濾掉單引號與反斜線，避免撐破 CSS
    const docNoTxt = String(formNo || '').replace(/['\\]/g, '');

    // 完整表頭（公司/客戶/單號等 meta-grid）：放在表格前，自然只出現在第1頁；
    // 第2頁起由 thead 的 cont-info 列（單號/客戶名稱）自動重複標示。
    const fullHeaderHtml = `<h2 class="co-name">${esc(coName)}</h2>
      <p class="co-info">${esc(coAddr)}<br>電話：${esc(coTel)}　傳真：${esc(coFax)}</p>
      <div style="text-align:center;margin:8px 0;"><h3 class="title">客戶報價單</h3></div>
      <div class="meta-grid">
        <div><span class="label">客戶名稱：</span>${esc(custName)}</div>
        <div><span class="label">報價日期：</span>${esc(dispDate(q.quote_date))}</div>
        <div><span class="label">聯絡電話：</span>${esc(custTel)}</div>
        <div><span class="label">單　　號：</span>${esc(q.quote_no)}</div>
        <div><span class="label">傳真號碼：</span>${esc(custFax)}</div>
        <div><span class="label">業務人員：</span>${esc(q.created_by_name||q.created_by||'')}</div>
        ${contactName ? `<div><span class="label">聯絡人　：</span>${esc(contactName)}</div>` : '<div></div>'}
        <div><span class="label">幣　　別：</span>${esc(q.currency==='TWD'?'NTD':(q.currency||'NTD'))}</div>
        ${q.inquiry_no ? `<div><span class="label">詢價編號：</span>${esc(q.inquiry_no)}</div>` : '<div></div>'}
        <div><span class="label">有效日期：</span>${esc(dispDate(q.valid_until))}</div>
      </div>`;

    // 「以下空白」列：接在最後一筆明細後
    const blankLineHtml = `<tr><td colspan="7" class="center" style="color:#444;letter-spacing:6px;padding:4px;">─── 以下空白 ───</td></tr>`;

    return `<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>客戶報價單 - ${esc(q.quote_no)}</title>
<style>
  /* 下邊界 16mm：留給頁尾 margin box（頁碼左下、AS 文件編號右下，ai-rules/16 列印標準）
     頁首 @top-center：第 2 頁起印「單號／客戶」跨頁識別，第 1 頁由 :first 蓋掉（表頭 meta 區已有，不重複） */
  @page {
    size: A4; margin: 12mm 12mm 16mm;
    @top-center { content:'${contInfoTxt}'; font-family:'標楷體','DFKai-SB',serif; font-size:9pt; color:#333; }
    ${docNoTxt ? `@bottom-right { content:'${docNoTxt}'; font-family:'標楷體','DFKai-SB',serif; font-size:9pt; color:#333; }` : ''}
  }
  @page :first { @top-center { content: none; } }
  body { font-family:'標楷體','DFKai-SB',serif; font-size:11pt; width:186mm; margin:0 auto; color:#000; }
  h2.co-name { text-align:center; font-size:20pt; font-weight:bold; margin:0 0 3px; }
  .co-info   { text-align:center; font-size:10pt; margin:0 0 6px; }
  h3.title   { text-align:center; font-size:18pt; font-weight:bold; margin:8px 0;
               border-bottom:3px double #000; padding-bottom:4px; display:inline-block; }
  .meta-grid { display:grid; grid-template-columns:68% auto; gap:2px 8px; font-size:10pt; margin-bottom:6px; }
  .meta-grid .label { color:#333; white-space:nowrap; }
  table.items { width:97%; border-collapse:collapse; font-size:10pt; table-layout:fixed; }
  table.items th,table.items td { border:1px solid #000; padding:3px 5px; }
  table.items td { vertical-align: top; }
  table.items tr { page-break-inside: avoid; }
  table.items th { text-align:center; font-weight:bold; }
  /* 表格跨頁時 thead 欄位標題列每頁自動重複，瀏覽器列印引擎原生行為；
     單號/客戶跨頁識別改由 @page @top-center 印（第1頁不印，避免與 meta 區重複） */
  table.items thead { display: table-header-group; }
  .center { text-align:center; }
  .right  { text-align:right; }
  .footer-area { display:table; width:97%; border-collapse:collapse; margin-top:4px; font-size:11pt; }
  .footer-area colgroup col.rem  { width:80%; }
  .footer-area colgroup col.lbl  { width:9%; }
  .footer-area colgroup col.val  { width:11%; }
  .remark-cell { vertical-align:top; padding-right:10px; }
  .total-lbl { border:1px solid #000; padding:2px 5px; font-size:10pt; }
  .total-val { border:1px solid #000; padding:2px 5px; font-size:10pt; text-align:right; }
  .page-footer { margin-top:6px; background:#fff; padding-top:4px; page-break-inside:avoid; }
  .sig-row { display:flex; align-items:flex-end; margin-top:8px; font-size:10pt; border-top:1px solid #000; padding-top:4px; }
  .sig-row span { flex:1; text-align:left; }
  .sig-row span:not(:last-child) { margin-right:16px; }
  /* 合計/備註/簽章整塊不可被分頁切開：本頁放不下就整塊移到下一頁 */
  .sig-block { page-break-inside: avoid; }
  svg.car-stamp { width:91px !important; height:91px !important; }
  .sig-row .stamp-wrap { margin:0 0 0 4px; }
  .pn-link { cursor:pointer; color:#8a5a00; text-decoration:underline dotted; }
  @media print { body { -webkit-print-color-adjust:exact; } .pn-link { color:inherit; text-decoration:none; } }
</style>
</head><body>
${fullHeaderHtml}
<table class="items">${itemsColgroupHtml}${itemsTheadHtml}${itemChunks.map(c => `<tbody${c.tiered ? ' style="page-break-inside:avoid;"' : ''}>${c.html}</tbody>`).join('')}<tbody>${blankLineHtml}</tbody></table>
<div class="sig-block">${sigRowHtml}</div>
<script>
// ★★ 本文件「零 JS 版面計算」鐵則 ★★
// 列印對話框可以「即時切換印表機」：每台印表機記住的紙張大小/可印範圍/縮放都可能不同，
// 切換目的地時瀏覽器會用新紙面重新排版，但這段 JS 不會重新執行——所以任何用 JS 算好的
// 絕對高度（固定筆數分頁、量高度切頁、把頁尾推到頁底的 spacer）都必然在某台印表機上排錯、
// 多頁或亂換頁（同一台電腦 SHARP/RICOH 預覽不同就是這樣來的）。分頁 100% 交給列印引擎。
(function () {
    // 內容明顯超過一頁(以A4概算)才顯示頁碼——只影響頁碼顯示、完全不影響分頁；
    // counter(pages) 由列印引擎在列印當下算，換印表機頁數變了頁碼也自動正確
    var onePageA4 = (297 - 24) * 96 / 25.4;
    if (document.body.scrollHeight > onePageA4 * 0.92) {
        var st = document.createElement('style');
        st.textContent = "@page { @bottom-left { content: '第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-family:'標楷體','DFKai-SB',serif; font-size:9pt; color:#333; } }";
        document.head.appendChild(st);
    }
    // 版面組完才叫用瀏覽器列印，取代舊版靠 win.onload 觸發（曾有時機競速、偶爾不會自動跳出列印視窗的問題）
    window.print();
})();
<\/script>
</body></html>`;
}

function deleteQuote() {
    const quote_id = $('#quote_id').val();
    if (!quote_id) return;
    const qno = getCurrentQuoteNo() || String(quote_id);
    $.get(API_URL, { action: 'check_quote_orders', quote_id }, res => {
        if (!res.success) { Swal.fire('錯誤', res.message, 'error'); return; }
        const hasOrders = res.orders && res.orders.length > 0;
        let extraHtml = '';
        if (hasOrders) {
            // ── 有綁定訂單：禁止刪除，僅顯示清單 ──
            const rows = res.orders.map(o =>
                `<tr>
                    <td style="padding:3px 8px;">${escapeHtml(o.order_no||o.order_id)}</td>
                    <td style="padding:3px 8px;">${escapeHtml(o.customer_name||'-')}</td>
                    <td style="padding:3px 8px;">${escapeHtml(o.order_date||'-')}</td>
                    <td style="padding:3px 8px;">${escapeHtml(o.status||'-')}</td>
                </tr>`
            ).join('');
            Swal.fire({
                title: '無法刪除',
                icon: 'error',
                html: `<p style="color:#c0392b;margin-bottom:10px;font-size:14px;">
                           此報價單已被以下 <b>${res.orders.length}</b> 筆訂單綁定，<b>禁止刪除</b>。
                       </p>
                       <div style="max-height:200px;overflow-y:auto;">
                           <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:left;">
                               <thead>
                                   <tr style="background:#f5f5f5;">
                                       <th style="padding:4px 8px;border-bottom:1px solid #ddd;">訂單號</th>
                                       <th style="padding:4px 8px;border-bottom:1px solid #ddd;">客戶</th>
                                       <th style="padding:4px 8px;border-bottom:1px solid #ddd;">日期</th>
                                       <th style="padding:4px 8px;border-bottom:1px solid #ddd;">狀態</th>
                                   </tr>
                               </thead>
                               <tbody>${rows}</tbody>
                           </table>
                       </div>
                       <p style="margin-top:12px;font-size:12px;color:#888;">如需刪除此報價單，請先至訂單管理解除相關訂單的報價單綁定。</p>`,
                confirmButtonText: '關閉',
                confirmButtonColor: '#6c757d',
            });
            return;
        }
        // ── 無綁定訂單：顯示確認刪除對話框 ──
        Swal.fire({
            title: '刪除報價單',
            icon: 'warning',
            html: `<div style="text-align:left;margin-bottom:10px;">
                    <label style="font-size:13px;font-weight:600;color:#c0392b;">刪除原因（必填）</label>
                    <textarea id="swal-del-reason" class="swal2-input" rows="3"
                        style="height:70px;width:88%;font-size:13px;resize:vertical;"
                        placeholder="請說明刪除此報價單的原因..."></textarea>
                </div>
                <p style="margin:6px 0;">請輸入大寫 <b style="color:#c0392b;">Y</b> 確認刪除 <b>${escapeHtml(qno)}</b></p>
                <input id="swal-del-confirm" class="swal2-input" type="text" placeholder="請輸入大寫 Y" autocomplete="off" style="width:88%;">`,
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: '確認刪除',
            cancelButtonText: '取消',
            focusConfirm: false,
            didOpen: () => { $(document).off('focusin.modal'); document.getElementById('swal-del-reason').focus(); },
            preConfirm: () => {
                const reason  = (document.getElementById('swal-del-reason').value || '').trim();
                const confirm = (document.getElementById('swal-del-confirm').value || '');
                if (!reason)       { Swal.showValidationMessage('請填寫刪除原因'); return false; }
                if (confirm !== 'Y') { Swal.showValidationMessage('請輸入大寫 Y 確認刪除'); return false; }
                return { reason };
            }
        }).then(r => {
            if (r.isConfirmed) doDeleteQuote(quote_id, false, r.value.reason);
        });
    }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
}
function doDeleteQuote(quote_id, force, deleteReason) {
    const delQno = getCurrentQuoteNo();
    $.post(API_URL, { action: 'delete', quote_id, force_delete: force ? 1 : 0, delete_reason: deleteReason || '' }, res => {
        if (res.success) {
            allYearsData = null;
            if (delQno) $.post(FILE_API_URL, { action: 'delete_folder', quote_no: delQno });
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 3000 });
            // 刪除後完全關閉（不回檢視，因為報價單已不存在）
            $.post(API_URL, { action: 'release_lock', quote_id: currentEditId });
            stopLockHeartbeat();
            resetEditor();
            currentEditId = null;
            $('#editorPanel, #viewPanel').hide();
            $('#newQuoteBtn').show();
            $('#editorEmpty').css('display', 'flex');
            if (isAllYearsMode) loadAllYears();
            else loadQuoteList(<?= $selectedYear ?>);
        } else {
            Swal.fire('錯誤', res.message, 'error');
        }
    }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
}

// ══════════════════════════════════════════════════════
// 複製
// ══════════════════════════════════════════════════════
function cloneQuote() {
    const sourceId = currentEditId;
    if (!sourceId) return;
    Swal.fire({
        title: '複製為新報價單',
        text: '將複製所有項目（含階梯），產生新單號，日期設為今天，不複製附件。是否繼續？',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#E6A817', confirmButtonText: '確認複製', cancelButtonText: '取消'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.get(API_URL, { action: 'get_detail', quote_id: sourceId }, res => {
            if (!res.success) { Swal.fire('錯誤', res.message || '載入失敗', 'error'); return; }
            const d = res.data;
            ++_editToken;
            resetEditor();
            currentEditId = null;
            $('#editorTitle').html('<i class="fa fa-plus-circle" style="color:var(--accent);margin-right:6px;"></i>新增報價單');
            $('#changeLogBtn, #delQuoteBtn, #cloneQuoteBtn').hide();
            const _today = todayStr();
            $('#quote_date').val(_today);
            autoFillValidUntil();
            const _prefix = quoteNoPrefixFromDate(_today);
            $('#quote_no_prefix').text(_prefix);
            $.get(API_URL, { action: 'get_new_quote_no' }, qres => {
                if (qres.success) {
                    $('#quote_seq').val(qres.quote_no.slice(-3));
                    syncQuoteNo();
                }
            });
            $('#client_name').val(d.client_name || '');
            $('#client_id').val(d.client_id || '');
            updateClientBoundCheck();
            $('#inquiry_no').val(d.inquiry_no || '');
            if (d.client_id) loadClientContacts(d.client_id, d.contact_id || null);
            $('#currency').val(d.currency || 'TWD');
            $('#exchange_rate').val(d.exchange_rate || 1);
            $('#note').val(d.note || '').trigger('input');
            $('#is_negotiation').prop('checked', d.is_negotiation == 1);
            $('#source_quote_id').val(sourceId);
            const items = d.items || [];
            items.forEach(item => addItemRow({ ...item, item_id: null }));
            if (!items.length) addItemRow();
            calculateTotal();
            showEditor();
            $('#note').trigger('input');
            updatePartSearchPlaceholders();
            updateEditorClientTag();
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '已複製內容，請確認後儲存', showConfirmButton: false, timer: 3000 });
        }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
    });
}

// ══════════════════════════════════════════════════════
// 新增/移除項目列
// ══════════════════════════════════════════════════════
const MAX_QUOTE_ITEMS = 30; // 報價項目上限（後端 save 同步檢查）

function addItemRow(item = {}) {
    // 上限檢查：僅擋使用者新增（載入既有明細帶 item_id，不受限）
    if (!item.item_id) {
        const _rowCnt = $('#quoteItemsTable > tbody > tr.item-row').length;
        if (_rowCnt >= MAX_QUOTE_ITEMS) {
            Swal.fire('提示', `報價項目最多 ${MAX_QUOTE_ITEMS} 筆料號`, 'warning');
            return false;
        }
    }
    const isTiered = item.is_tiered == 1;
    const $tbody   = $('#quoteItemsTable > tbody');

    const selOpt = (val, match) => val === match ? 'selected' : '';
    const gt     = item.process_group_type || 'single_process';

    const _hasBound = item.d_setting_d_id ? '' : 'display:none;';
    const rowHtml = `
    <tr class="item-row" data-item-id="${item.item_id || ''}" data-is-tiered="${isTiered ? 1 : 0}">
        <td>
            <button type="button" class="btn btn-danger btn-xs" onclick="removeItemRow(this)" title="刪除此項目">
                <i class="fa fa-trash"></i>
            </button>
        </td>
        <td>
            <div style="position:relative;">
                <div class="input-group input-group-sm">
                    <span class="input-group-addon part-bound-check" style="${_hasBound}background:transparent;border-right:none;padding:0 3px;color:#27ae60;" title="已綁定料號資料">
                        <i class="fa fa-check-circle"></i>
                    </span>
                    <input type="text" class="form-control part-search"
                        placeholder="搜尋料號..." autocomplete="off"
                        value="${escapeHtml(item.product_id || '')}"
                        title="${escapeHtml(item.product_id || '')}"
                        style="text-overflow:ellipsis;">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default btn-xs part-cog-btn"
                            data-d-id="${escapeHtml(String(item.d_setting_d_id || ''))}"
                            onclick="openPartModalForRow(this)" title="開啟料號">
                            <i class="fa fa-cog"></i>
                        </button>
                    </span>
                </div>
                <div class="part-suggestions autocomplete-suggestions" style="display:none;"></div>
                <input type="hidden" class="product_id_hidden" value="${escapeHtml(item.product_id || '')}">
                <input type="hidden" class="d_setting_d_id_hidden" value="${escapeHtml(String(item.d_setting_d_id || ''))}">
                <div class="gear-spec-display" style="font-size:10px;color:#888;margin-top:3px;line-height:1.4;"></div>
                <div class="part-attach-badges" style="margin-top:2px;line-height:1.7;"></div>
                <input type="hidden" class="show-bom-hidden" value="${item.show_bom == 1 ? 1 : 0}">
                <input type="hidden" class="print-bom-hidden" value="${item.print_bom == 1 ? 1 : 0}">
                <div class="bom-info-area" style="margin-top:3px;"></div>
            </div>
        </td>
        <td class="process-cell">
            <div class="proc-direct-l1"></div>
            <div class="proc-direct-l2"></div>
            <div class="proc-selected-chips"></div>
            <input type="hidden" class="process-hidden" value="${escapeHtml(item.processes || '')}">
            <input type="hidden" class="proc-subtags-hidden" value="">
            <input type="hidden" class="proc-group-type-hidden" value="${escapeHtml(gt)}">
        </td>
        <td>
            <input type="text" class="form-control input-sm" name="specification"
                value="${escapeHtml(item.specification || '')}" placeholder="料號備註">
        </td>
        <td>
            <input type="number" class="form-control input-sm quantity"
                value="${item.quantity != null && item.quantity !== '' ? item.quantity : ''}" step="any" min="0" ${isTiered ? 'disabled' : ''}>
        </td>
        <td>
            <select class="form-control input-sm item-unit" style="font-size:12px;">
                ${buildUnitOptions(item.unit || 'PCS')}
            </select>
        </td>
        <td>
            <input type="number" class="form-control input-sm unit-price"
                value="${item.unit_price != null && item.unit_price !== '' ? item.unit_price : ''}" step="any" min="0" ${isTiered ? 'disabled' : ''}>
        </td>
        <td>
            <input type="text" class="form-control input-sm amount"
                value="${formatNumber(item.amount || 0)}" readonly>
        </td>
        <td style="text-align:center;padding-top:7px;">
            <button type="button" class="btn btn-default btn-xs tier-toggle-btn ${isTiered ? 'active' : ''}"
                onclick="toggleTierMode(this)" title="切換階梯報價">
                <i class="fa fa-list-ol"></i>
            </button>
        </td>
        <td></td>
    </tr>`;

    $tbody.append(rowHtml);
    const $newRow = $tbody.find('> tr.item-row:last');

    // 初始化製程標籤
    // 初始化製程直接標籤導覽
    const $procCell = $newRow.find('.process-cell');
    // 優先用 process_notes 存放的 sub_tag IDs（精確）；舊資料無此欄才退回推算
    const savedSubTagIds = (item.process_notes || '')
        .split(',').map(s => parseInt(s.trim())).filter(x => x > 0);
    const existingProcs  = (item.processes || '').split(',').map(s => s.trim()).filter(Boolean);
    const initSubTags    = savedSubTagIds.length
        ? savedSubTagIds
        : (existingProcs.length ? inferSubTagsFromProcessIds(existingProcs) : []);
    initProcCellNav($procCell, initSubTags);

    if (isTiered) renderTierSection($newRow, item.tiers || []);

    if (item.product_id) {
        loadItemHistory($newRow, item.product_id);
        const _gDid = item.d_setting_d_id ? parseInt(item.d_setting_d_id) : null;
        loadGearSpecs($newRow, _gDid ? null : item.product_id, _gDid);
        loadBomInfo($newRow, _gDid ? null : item.product_id, _gDid);
    }
}

function removeItemRow(btn) {
    const $row = $(btn).closest('tr.item-row');
    $row.next('tr.tier-row').remove();
    $row.next('tr.hq-row').remove();
    $row.nextUntil('tr.item-row', 'tr.bom-row').remove();
    $row.remove();
    calculateTotal();
}

// 項目列是否「完全空白尚未輸入」：料號、製程、料號備註、數量、單價、金額全空才算
// （金額為唯讀自動計算欄，空字串或 0 視為空白；階梯模式一律視為已輸入）
function quoteRowIsBlank($row) {
    if (parseInt($row.data('is-tiered')) === 1) return false;
    if (($row.find('.part-search').val() || '').trim() !== '') return false;              // 料號（輸入中文字）
    if (($row.find('.product_id_hidden').val() || '').trim() !== '') return false;        // 料號（已綁定）
    if (($row.find('.proc-subtags-hidden').val() || '').trim() !== '') return false;      // 製程
    if (($row.find('.process-hidden').val() || '').trim() !== '') return false;           // 製程（舊資料值）
    if (($row.find('input[name="specification"]').val() || '').trim() !== '') return false; // 料號備註
    if (($row.find('.quantity').val() || '').trim() !== '') return false;                 // 數量
    if (($row.find('.unit-price').val() || '').trim() !== '') return false;               // 單價
    const amt = ($row.find('.amount').val() || '').replace(/,/g, '').trim();              // 金額
    if (amt !== '' && parseFloat(amt) !== 0) return false;
    return true;
}

// 階梯區間列是否完全空白（最小量/最大量/單價/容差值/容差備註全空；容差單位下拉與唯讀門檻小計不列入）
function tierRowIsBlank($tr) {
    if (($tr.find('.tier-qty-min').val() || '').trim() !== '') return false;
    if (($tr.find('.tier-qty-max').val() || '').trim() !== '') return false;
    if (($tr.find('.tier-unit-price').val() || '').trim() !== '') return false;
    if (($tr.find('.tier-tol-value').val() || '').trim() !== '') return false;
    if (($tr.find('.tier-tol-note').val() || '').trim() !== '') return false;
    return true;
}

// ══════════════════════════════════════════════════════
// ★ 製程直接標籤導覽（直接顯示，不需面板）
// ══════════════════════════════════════════════════════

// 從製程 ID 陣列推算對應子標籤（載入舊資料用）
function inferSubTagsFromProcessIds(processIds) {
    const result = [];
    processTagTree.forEach(g => {
        (g.sub_tags || []).forEach(st => {
            const pnos = (st.process_nos || []).map(String);
            if (pnos.length > 0 && pnos.every(p => processIds.includes(p))) result.push(st.sub_tag_id);
        });
    });
    return result;
}

// 取得目前已選子標籤 ID 陣列
function getProcSelectedSubTags($cell) {
    const v = $cell.find('.proc-subtags-hidden').val();
    return v ? v.split(',').map(s => parseInt(s)).filter(x => !isNaN(x)) : [];
}

// 將已選子標籤的製程 ID 同步到 .process-hidden
function syncProcHiddenFromSubTags($cell) {
    const selected = getProcSelectedSubTags($cell);
    const procIds  = new Set();
    selected.forEach(sid => {
        processTagTree.forEach(g => {
            (g.sub_tags || []).forEach(st => {
                if (st.sub_tag_id === sid) (st.process_nos || []).forEach(p => procIds.add(String(p)));
            });
        });
    });
    $cell.find('.process-hidden').val([...procIds].join(','));
}

// 初始化 cell 的 L1+L2 導覽
function initProcCellNav($cell, selectedSubTagIds) {
    $cell.find('.proc-subtags-hidden').val(selectedSubTagIds.join(','));
    syncProcHiddenFromSubTags($cell);
    renderProcL1($cell);
    if (processTagTree.length && selectedSubTagIds.length) {
        // 展開含有已選子標籤的群組
        let activeGid = processTagTree[0].group_id;
        for (const g of processTagTree) {
            if ((g.sub_tags || []).some(st => selectedSubTagIds.includes(st.sub_tag_id))) {
                activeGid = g.group_id;
                break;
            }
        }
        renderProcL2($cell, activeGid, true);
    } else {
        // 無已選子標籤：不展開 L2，不預設 L1 底色
        $cell.find('.proc-direct-l2').empty();
    }
    renderProcSelectedChips($cell);
}

// 渲染 L1 群組按鈕
function renderProcL1($cell) {
    if (!processTagTree.length) { $cell.find('.proc-direct-l1').empty(); return; }
    let html = '';
    processTagTree.forEach(g => {
        html += `<button type="button" class="proc-l1-btn" data-gid="${g.group_id}"
            onclick="renderProcL2($(this).closest('.process-cell'),${g.group_id},true)">${escapeHtml(g.group_name)}</button>`;
    });
    $cell.find('.proc-direct-l1').html(html);
}

// 渲染 L2 子標籤按鈕（含選取狀態）
function renderProcL2($cell, gid, setActive) {
    if (setActive) {
        $cell.find('.proc-l1-btn').removeClass('active');
        $cell.find(`.proc-l1-btn[data-gid="${gid}"]`).addClass('active');
    }
    const g = processTagTree.find(x => x.group_id === gid);
    if (!g) { $cell.find('.proc-direct-l2').empty(); return; }
    const selected = getProcSelectedSubTags($cell);
    let html = '';
    (g.sub_tags || []).forEach(st => {
        const isOn = selected.includes(st.sub_tag_id);
        html += `<button type="button" class="proc-l2-btn ${isOn ? 'active' : ''}" data-sid="${st.sub_tag_id}" data-gid="${gid}"
            onclick="toggleProcSubTag($(this).closest('.process-cell'),${st.sub_tag_id},${gid})">${escapeHtml(st.sub_tag_name)}</button>`;
    });
    $cell.find('.proc-direct-l2').html(html || '<small class="text-muted" style="font-size:10px;">此群組尚無子標籤</small>');
}

// 點擊子標籤：toggle 選取
function toggleProcSubTag($cell, subTagId, groupId) {
    let selected = getProcSelectedSubTags($cell);
    if (selected.includes(subTagId)) {
        selected = selected.filter(x => x !== subTagId);
    } else {
        selected.push(subTagId);
    }
    $cell.find('.proc-subtags-hidden').val(selected.join(','));
    // 設定 group_type（依最後選取的群組）
    const g = processTagTree.find(x => x.group_id === groupId);
    $cell.find('.proc-group-type-hidden').val(selected.length && g ? (g.group_type || 'single_process') : 'single_process');
    syncProcHiddenFromSubTags($cell);
    // 重繪 L2 active 狀態
    const activeGid = parseInt($cell.find('.proc-l1-btn.active').data('gid')) || (processTagTree[0] && processTagTree[0].group_id);
    if (activeGid) renderProcL2($cell, activeGid, false);
    renderProcSelectedChips($cell);
}

// 渲染已選子標籤 chips
function renderProcSelectedChips($cell) {
    const selected = getProcSelectedSubTags($cell);
    if (!selected.length) { $cell.find('.proc-selected-chips').empty(); return; }
    let html = '';
    selected.forEach(sid => {
        let name = String(sid);
        processTagTree.forEach(g => {
            (g.sub_tags || []).forEach(st => { if (st.sub_tag_id === sid) name = st.sub_tag_name; });
        });
        html += `<span class="proc-tag" data-sid="${sid}">${escapeHtml(name)}<span class="proc-tag-x" onclick="removeProcSubTagChip(this)">&times;</span></span>`;
    });
    $cell.find('.proc-selected-chips').html(html);
}

// 移除子標籤 chip
function removeProcSubTagChip(el) {
    const $cell = $(el).closest('.process-cell');
    const sid   = parseInt($(el).closest('.proc-tag').data('sid'));
    let selected = getProcSelectedSubTags($cell);
    selected = selected.filter(x => x !== sid);
    $cell.find('.proc-subtags-hidden').val(selected.join(','));
    if (!selected.length) $cell.find('.proc-group-type-hidden').val('single_process');
    syncProcHiddenFromSubTags($cell);
    const activeGid = parseInt($cell.find('.proc-l1-btn.active').data('gid')) || (processTagTree[0] && processTagTree[0].group_id);
    if (activeGid) renderProcL2($cell, activeGid, false);
    renderProcSelectedChips($cell);
}

// ══════════════════════════════════════════════════════
// 歷史報價快帶入（選取料號後顯示）
// ══════════════════════════════════════════════════════
// ★ 齒輪規格自動帶入
// ══════════════════════════════════════════════════════
function loadGearSpecs($tr, partId, dId) {
    const params = { action: 'get_gear_specs' };
    if (dId) params.d_id = dId;
    else if (partId) params.part_id = partId;
    else return;
    $.get(API_URL, params, res => {
        if (!res.success || !res.gears.length) {
            $tr.find('.gear-spec-display').empty();
            return;
        }
        // 格式：M模數 T齒數 W齒寬 螺旋方向角度，多列齒型用 / 分隔
        const texts = res.gears.map(g => {
            const p = [];
            const mod = String(g.Module || '').trim();
            if (mod) p.push(/^m/i.test(mod) ? mod : 'M' + mod);
            if (Number(g.Teeth) > 0) p.push('T' + g.Teeth);
            if (Number(g.Face_Width) > 0) {
                const fw = parseFloat(g.Face_Width);
                p.push('W' + fw.toString().replace(/\.?0+$/, ''));
            }
            const hd = String(g.Helix_Direction || '').trim();
            if (hd && hd !== 'N/A') p.push(hd + (g.Helix_Angle_Str || ''));
            return p.join(' ');
        }).filter(Boolean);
        $tr.find('.gear-spec-display').html(
            texts.length ? `<span style="color:#888;font-size:10px;">${escapeHtml(texts.join(' / '))}</span>` : ''
        );
    });
}

// ══════════════════════════════════════════════════════
// ★ 組合件資訊：子件清單（勾選顯示/列印）+ 子件反查母件提醒
// ══════════════════════════════════════════════════════
const BOM_COLLAPSE_LIMIT = 2; // 畫面上子件超過此數自動收合

function loadBomInfo($tr, partId, dId) {
    const clearBom = () => { $tr.data('bom-info', null); renderBomArea($tr); };
    const params = { action: 'get_bom_info' };
    if (dId) params.d_id = dId;
    else if (partId) params.part_id = partId;
    else { clearBom(); return; }
    $.get(API_URL, params, res => {
        if (!res.success) { clearBom(); return; }
        $tr.data('bom-info', res);
        renderBomArea($tr);
    });
}

// 子件用量格式：小數尾 0 省略（3.50→3.5、1.00→1）
function fmtBomQty(q) {
    const n = parseFloat(q || 0);
    return isNaN(n) ? '' : String(n);
}

function renderBomArea($tr) {
    const info  = $tr.data('bom-info');
    const $cell = $tr.find('.bom-info-area');
    // 先移除此項目既有的組合件整列（重繪）
    $tr.nextUntil('tr.item-row', 'tr.bom-row').remove();
    if (!info) { $cell.empty(); return; }

    // 料號欄只放「屬於組合件」反查提醒（單行截斷，滑過看全文）
    $cell.html((info.parents || []).length
        ? `<div style="font-size:10px;color:#8e44ad;line-height:1.5;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
               title="屬於組合件：${escapeHtml(info.parents.join('、'))}">
             <i class="fa fa-level-up"></i> 屬於組合件：${info.parents.map(escapeHtml).join('、')}</div>`
        : '');

    if (info.is_assembly != 1) return;

    const children = info.children || [];
    const showBom  = $tr.find('.show-bom-hidden').val() === '1';
    const printBom = $tr.find('.print-bom-hidden').val() === '1';
    const colCount = $tr.find('td').length;
    const chkLabel = 'display:inline-flex;align-items:center;gap:4px;font-weight:normal;margin:0;cursor:pointer;white-space:nowrap;font-size:11px;';

    // 佔滿整列寬的橫幅：徽章(含子件數) + 勾選 + 子件籤片橫排
    let inner = `<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#faf7fd;border:1px solid #e4d7f5;border-radius:4px;padding:4px 10px;line-height:1.8;">
        <span style="background:#8e44ad;color:#fff;padding:1px 8px;border-radius:3px;font-size:10px;white-space:nowrap;"><i class="fa fa-sitemap"></i> 組合件・含 ${children.length} 件子件</span>
        <label style="${chkLabel}color:#555;">
            <input type="checkbox" class="bom-show-chk" ${showBom ? 'checked' : ''} style="margin:0;"> 顯示子件清單
        </label>`;
    if (showBom) {
        inner += `<label style="${chkLabel}color:#c0392b;" title="預設不列印，勾選後客戶報價單會印出子件清單">
            <input type="checkbox" class="bom-print-chk" ${printBom ? 'checked' : ''} style="margin:0;"> 列印時包含子件清單
        </label>`;
        if (!children.length) {
            inner += `<span style="font-size:10px;color:#aaa;">（料號主檔尚未建立子件清單）</span>`;
        } else {
            const expanded  = $tr.data('bom-expanded') === 1;
            const collapsed = children.length > BOM_COLLAPSE_LIMIT && !expanded;
            const list = collapsed ? children.slice(0, BOM_COLLAPSE_LIMIT) : children;
            list.forEach(c => {
                const extra = [c.spec_no, c.Remark_Bom].filter(Boolean).join('・');
                inner += `<span style="background:#fff;border:1px solid #e4d7f5;border-radius:3px;padding:1px 8px;font-size:10.5px;color:#555;white-space:nowrap;"
                    title="${escapeHtml(c.part_no)} ${fmtBomQty(c.standard_qty)}PCS/組${extra ? ' ' + escapeHtml(extra) : ''}">
                    <b>${escapeHtml(c.part_no)}</b> <span style="color:#8e44ad;">${fmtBomQty(c.standard_qty)}PCS/組</span>${extra ? ` <span style="color:#aaa;">${escapeHtml(extra)}</span>` : ''}
                </span>`;
            });
            if (collapsed) {
                inner += `<a href="#" class="bom-expand-link" style="font-size:10.5px;color:#8e44ad;white-space:nowrap;">＋還有 ${children.length - BOM_COLLAPSE_LIMIT} 件 ▼</a>`;
            } else if (children.length > BOM_COLLAPSE_LIMIT) {
                inner += `<a href="#" class="bom-collapse-link" style="font-size:10.5px;color:#8e44ad;white-space:nowrap;">收合 ▲</a>`;
            }
        }
    }
    inner += `</div>`;

    // 插在該項目所有輔助列（tier-row / hq-row）之後
    const $bomTr = $(`<tr class="bom-row"><td colspan="${colCount}" style="border-top:none;padding:2px 6px 6px;">${inner}</td></tr>`);
    const $helpers = $tr.nextUntil('tr.item-row');
    if ($helpers.length) $helpers.last().after($bomTr);
    else $tr.after($bomTr);

    // 事件（每次重繪後重綁）
    $bomTr.find('.bom-show-chk').on('change', function () {
        const on = this.checked ? 1 : 0;
        $tr.find('.show-bom-hidden').val(on);
        if (!on) $tr.find('.print-bom-hidden').val(0); // 階層式：取消顯示時一併取消列印
        renderBomArea($tr);
    });
    $bomTr.find('.bom-print-chk').on('change', function () {
        $tr.find('.print-bom-hidden').val(this.checked ? 1 : 0);
    });
    $bomTr.find('.bom-expand-link').on('click', function (e) {
        e.preventDefault(); $tr.data('bom-expanded', 1); renderBomArea($tr);
    });
    $bomTr.find('.bom-collapse-link').on('click', function (e) {
        e.preventDefault(); $tr.data('bom-expanded', 0); renderBomArea($tr);
    });
}

// ══════════════════════════════════════════════════════
function loadItemHistory($itemRow, productId) {
    const clientName = $('#client_name').val().trim();
    if (!clientName || !productId) return;

    // 移除舊的快帶列
    $itemRow.next('tr.hq-row').remove();
    const $tierRow = $itemRow.next('tr.tier-row');
    const colCount = $itemRow.find('td').length;

    $.get(API_URL, { action: 'get_price_history', client_name: clientName, product_id: productId }, res => {
        if (!res.success || !res.data || !res.data.length) return;
        const recent = res.data.slice(0, 5);
        let chips = recent.map(r => {
            let label, payload;
            if (r.is_tiered && r.tiers && r.tiers.length) {
                label = r.tiers.map(t => `${formatNumber(t.qty_min)}+: ${formatNumber(t.unit_price)}`).join(' / ');
                payload = '';
            } else {
                label = `${escapeHtml(String(r.quote_date||'').replace(/-/g,'.'))} @ <b>${formatNumber(r.unit_price)}</b>`;
                payload = r.unit_price;
            }
            const attr = payload !== '' ? `data-price="${payload}"` : '';
            return `<span class="hq-chip" ${attr} title="${escapeHtml(r.quote_no)}">${label}</span>`;
        }).join('');

        const $hqTr = $(`<tr class="hq-row"><td colspan="${colCount}" class="hq-row">
            <div class="hq-wrap" style="padding:3px 0 5px 0;">
                <small style="color:#aaa;font-size:10px;white-space:nowrap;"><i class="fa fa-history"></i> 歷史</small>
                ${chips}
            </div>
        </td></tr>`);

        // 插在 tier-row 後方（若有），否則在 item-row 後
        if ($tierRow.length) $tierRow.after($hqTr);
        else $itemRow.after($hqTr);

        // 點擊快帶入單價
        $hqTr.find('.hq-chip[data-price]').on('click', function () {
            const price = $(this).data('price');
            if (!price && price !== 0) return;
            const $isTiered = parseInt($itemRow.data('is-tiered'));
            if (!$isTiered) {
                $itemRow.find('.unit-price').val(price).trigger('input');
            }
        });
    });
}

// ══════════════════════════════════════════════════════
// 計算合計
// ══════════════════════════════════════════════════════
function calculateTotal() {
    let total = 0;
    $('#quoteItemsTable > tbody > tr.item-row').each(function () {
        const $row = $(this);
        if ($row.data('is-tiered')) {
            let sub = 0;
            $row.next('tr.tier-row').find('.tier-amount').each(function () {
                sub += parseFloat($(this).val().replace(/,/g, '')) || 0;
            });
            $row.find('.amount').val(formatNumber(sub));
            total += sub;
        } else {
            total += parseFloat($row.find('.amount').val().replace(/,/g, '')) || 0;
        }
    });
    $('#totalAmountDisplay').text(formatNumber(total));
    $('#currencyDisplay').text($('#currency').find('option:selected').text());
}

// ══════════════════════════════════════════════════════
// 階梯管理（邏輯與原版相同）
// ══════════════════════════════════════════════════════
function toggleTierMode(btn) {
    const $btn = $(btn);
    const $row = $btn.closest('tr.item-row');
    $btn.toggleClass('active');
    const now = $btn.hasClass('active');
    $row.data('is-tiered', now ? 1 : 0);
    $row.find('.quantity, .unit-price').prop('disabled', now);
    if (now) {
        renderTierSection($row, []);
        $row.find('.amount').val('0');
    } else {
        $row.next('tr.tier-row').remove();
        $row.find('.quantity, .unit-price').prop('disabled', false);
        const q = parseFloat($row.find('.quantity').val()) || 0;
        const p = parseFloat($row.find('.unit-price').val()) || 0;
        $row.find('.amount').val(formatNumber(q * p));
    }
    calculateTotal();
}
function renderTierSection($itemRow, tiers) {
    $itemRow.next('tr.tier-row').remove();
    if (tiers.length === 0) tiers = [{}];
    let tiersHtml = '';
    tiers.forEach((t, i) => { tiersHtml += buildTierInputRow(t, i); });
    const $tierTr = $(`
    <tr class="tier-row">
        <td colspan="9" style="padding:0 0 6px 40px;">
            <div class="tier-section">
                <small class="text-muted" style="font-size:11px;">
                    <i class="fa fa-info-circle"></i> 階梯模式：各區間以「最低門檻量 × 單價」計算小計
                </small>
                <table class="tier-table" style="margin-top:6px;">
                    <thead><tr>
                        <th style="width:7%">排序</th>
                        <th style="width:13%">最小量<span class="text-danger">*</span></th>
                        <th style="width:13%">最大量<br><small>空=無上限</small></th>
                        <th style="width:13%">單價<span class="text-danger">*</span></th>
                        <th style="width:13%">門檻小計</th>
                        <th style="width:8%">容差值</th>
                        <th style="width:8%">容差單位</th>
                        <th style="width:16%">容差備註</th>
                        <th style="width:9%">刪除</th>
                    </tr></thead>
                    <tbody class="tier-tbody">${tiersHtml}</tbody>
                </table>
                <button type="button" class="btn btn-success btn-add-tier" onclick="addTierRow(this)">
                    <i class="fa fa-plus"></i> 新增區間
                </button>
            </div>
        </td>
    </tr>`);
    $itemRow.after($tierTr);
}
function buildTierInputRow(t, idx) {
    const qmin  = (t.qty_min  !== undefined && t.qty_min  !== '') ? Math.round(Number(t.qty_min))  : '';
    const qmax  = (t.qty_max  !== undefined && t.qty_max  !== null && t.qty_max !== '') ? Math.round(Number(t.qty_max)) : '';
    const price  = (t.unit_price !== undefined && t.unit_price !== '') ? formatNumber(t.unit_price) : '';
    const amount = t.amount !== undefined ? formatNumber(t.amount) : '0';
    const tv = (t.tolerance_value !== undefined && t.tolerance_value !== null) ? t.tolerance_value : '';
    const tu = t.tolerance_unit || '';
    const tn = t.tolerance_note || '';
    return `<tr class="tier-input-row">
        <td style="text-align:center;color:#aaa;font-size:11px;">${idx+1}</td>
        <td><input type="number" class="form-control tier-qty-min" value="${qmin}" step="1" min="0" placeholder="例:300"></td>
        <td><input type="number" class="form-control tier-qty-max" value="${qmax}" step="1" min="0" placeholder="空=無上限"></td>
        <td><input type="number" class="form-control tier-unit-price" value="${t.unit_price !== undefined && t.unit_price !== '' ? Number(t.unit_price) : ''}" step="any" min="0" placeholder="0"></td>
        <td><input type="text" class="form-control tier-amount" value="${amount}" readonly></td>
        <td><input type="number" class="form-control tier-tol-value" value="${escapeHtml(String(tv))}" step="any" min="0" placeholder="5"></td>
        <td>
            <select class="form-control tier-tol-unit">
                <option value="" ${tu===''?'selected':''}>-</option>
                <option value="%" ${tu==='%'?'selected':''}>%</option>
                <option value="PCS" ${tu==='PCS'?'selected':''}>PCS</option>
            </select>
        </td>
        <td><input type="text" class="form-control tier-tol-note" value="${escapeHtml(tn)}" placeholder="容差說明..."></td>
        <td style="text-align:center;">
            <button type="button" class="btn btn-danger btn-xs btn-del-tier" onclick="deleteTierRow(this)">
                <i class="fa fa-times"></i>
            </button>
        </td>
    </tr>`;
}
function addTierRow(btn) {
    const $tbody = $(btn).closest('.tier-section').find('.tier-tbody');
    const $rows  = $tbody.find('tr.tier-input-row');
    const idx    = $rows.length;
    if ($rows.length > 0) {
        const $lastRow = $rows.last();
        if ($lastRow.find('.tier-qty-max').val().trim() === '') {
            $lastRow.find('.tier-qty-max').addClass('tier-qmax-pending')
                .css({ 'background-color': '#fff8e1', 'border-color': '#ffc107' })
                .attr('title', '新增下一區間後將自動補上限');
        }
    }
    $tbody.append(buildTierInputRow({}, idx));
    refreshTierSortOrder($tbody);
    $tbody.find('tr.tier-input-row:last .tier-qty-min').off('input.autofill').on('input.autofill', function () {
        const newMin   = parseFloat($(this).val());
        const $pending = $tbody.find('.tier-qty-max.tier-qmax-pending');
        if ($pending.length && !isNaN(newMin) && newMin > 0) {
            $pending.val(Math.round(newMin) - 1)
                    .removeClass('tier-qmax-pending')
                    .css({ 'background-color': '#e8f5e9', 'border-color': '#4caf50' })
                    .attr('title', '已自動補上限（可手動修改）');
        }
    });
}
function deleteTierRow(btn) {
    const $tbody = $(btn).closest('.tier-tbody');
    $(btn).closest('tr').remove();
    refreshTierSortOrder($tbody);
    autoFixTierOverlap($tbody);
    calculateTotal();
}
function refreshTierSortOrder($tbody) {
    $tbody.find('tr.tier-input-row').each((i, tr) => { $(tr).find('td:first').text(i + 1); });
}
function autoFixTierOverlap($tbody) {
    const $rows = $tbody.find('tr.tier-input-row');
    $rows.each(function (i) {
        if (i === $rows.length - 1) return;
        const $qmax    = $(this).find('.tier-qty-max');
        const nextMin  = parseFloat($rows.eq(i + 1).find('.tier-qty-min').val());
        const curMax   = $qmax.val().trim();
        if (curMax === '' && !isNaN(nextMin) && nextMin > 0) {
            $qmax.val(Math.round(nextMin) - 1)
                 .css({ 'background-color': '#e8f5e9', 'border-color': '#4caf50' })
                 .attr('title', '已自動補上限（可手動修改）');
        } else if (curMax !== '') {
            const cm = Math.round(parseFloat(curMax));
            const nm = Math.round(nextMin);
            if (!isNaN(nm) && cm >= nm) {
                $qmax.val(nm - 1)
                     .css({ 'background-color': '#fff3e0', 'border-color': '#ff9800' })
                     .attr('title', '上限已自動修正以避免與下一區間重疊');
            }
        }
    });
}
function fixAllTiersBeforeSave() {
    let ok = true;
    $('#quoteItemsTable > tbody > tr.item-row').each(function () {
        if (parseInt($(this).data('is-tiered')) !== 1) return;
        const $tbody = $(this).next('tr.tier-row').find('.tier-tbody');
        autoFixTierOverlap($tbody);
        const $rows = $tbody.find('tr.tier-input-row');
        $rows.each(function (i) {
            if (i === $rows.length - 1) return;
            if ($(this).find('.tier-qty-max').val().trim() === '' &&
                isNaN(parseFloat($rows.eq(i + 1).find('.tier-qty-min').val()))) {
                ok = false;
            }
        });
    });
    return ok;
}

// ══════════════════════════════════════════════════════
// 預設容差
// ══════════════════════════════════════════════════════
function loadDefaultTolerance() {
    $.get(API_URL, { action: 'get_param', param_group: 'QUOTATION', param_key: 'default_tolerance' }, res => {
        if (res.success && res.value) defaultTolerance = res.value;
        $('#defaultTolDisplay').text(`±${defaultTolerance.value} ${defaultTolerance.unit}`);
    });
}
function applyDefaultTolerance() {
    let count = 0;
    $('#quoteItemsTable tbody tr.tier-row .tier-tbody tr.tier-input-row').each(function () {
        if (!$(this).find('.tier-tol-value').val()) {
            $(this).find('.tier-tol-value').val(defaultTolerance.value);
            $(this).find('.tier-tol-unit').val(defaultTolerance.unit);
            count++;
        }
    });
    if (count === 0) {
        Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: '所有區間已有容差設定', showConfirmButton: false, timer: 2000 });
    } else {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `已套用至 ${count} 個區間`, showConfirmButton: false, timer: 2000 });
    }
}
function openTolSettingModal() {
    Swal.fire({
        title: '修改預設容差',
        html: `<div style="display:flex;gap:10px;align-items:center;justify-content:center;margin-top:10px;">
            <input type="number" id="swal-tol-val" class="swal2-input" style="width:100px;margin:0;"
                value="${defaultTolerance.value}" min="0" step="any">
            <select id="swal-tol-unit" class="swal2-select" style="width:90px;margin:0;">
                <option value="%" ${defaultTolerance.unit==='%'?'selected':''}>%</option>
                <option value="PCS" ${defaultTolerance.unit==='PCS'?'selected':''}>PCS</option>
            </select></div>`,
        showCancelButton: true, confirmButtonText: '儲存', cancelButtonText: '取消',
        preConfirm: () => {
            const val  = parseFloat(document.getElementById('swal-tol-val').value);
            const unit = document.getElementById('swal-tol-unit').value;
            if (isNaN(val) || val < 0) { Swal.showValidationMessage('請輸入有效數值'); return false; }
            return { value: val, unit };
        }
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, {
            action: 'save_param', param_group: 'QUOTATION', param_key: 'default_tolerance',
            param_value: JSON.stringify(r.value), description: '報價階梯預設容差'
        }, res => {
            if (res.success) {
                defaultTolerance = r.value;
                $('#defaultTolDisplay').text(`±${r.value.value} ${r.value.unit}`);
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '預設容差已更新', showConfirmButton: false, timer: 2000 });
            } else {
                Swal.fire('錯誤', res.message, 'error');
            }
        });
    });
}

// ══════════════════════════════════════════════════════
// ★ 附件上傳
// ══════════════════════════════════════════════════════
function loadUploadPath() {
    $.get(API_URL, { action: 'get_param', param_group: 'QUOTATION', param_key: 'upload_path' }, res => {
        const path = (res.success && res.value) ? (typeof res.value === 'string' ? res.value : JSON.stringify(res.value)) : '';
        currentUploadPath = path;
        $('#uploadPathDisplay').text(path || '未設定');
    });
}
let _validDays = 0; // 報價有效天數（0=不自動帶入）

function saveValidDays() {
    const v = parseInt($('#qs-valid-days').val()) || 0;
    $.post(API_URL, { action:'save_param', param_group:'QUOTATION', param_key:'valid_days',
        param_value: JSON.stringify(v), description:'報價有效期天數' }, res => {
        if (res.success) {
            _validDays = v;
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已儲存', showConfirmButton:false, timer:1800 });
        } else Swal.fire('錯誤', res.message, 'error');
    });
}
function loadValidDays() {
    $.get(API_URL, { action:'get_param', param_group:'QUOTATION', param_key:'valid_days' }, res => {
        if (res.success && res.value !== null) {
            _validDays = parseInt(res.value) || 0;
            $('#qs-valid-days').val(_validDays || '');
        }
    });
}
// 附件暫存/垃圾自動清除天數（後端 getQuotAttachDays 讀同名 param，預設 2/7）
function loadAttachDays() {
    $.get(API_URL, { action:'get_param', param_group:'QUOTATION', param_key:'temp_attach_days' }, res => {
        const v = (res.success && res.value !== null) ? parseInt(res.value) : 0;
        $('#qs-temp-days').val(v > 0 ? v : '');
    });
    $.get(API_URL, { action:'get_param', param_group:'QUOTATION', param_key:'trash_attach_days' }, res => {
        const v = (res.success && res.value !== null) ? parseInt(res.value) : 0;
        $('#qs-trash-days').val(v > 0 ? v : '');
    });
}
function saveAttachDays() {
    const t = parseInt($('#qs-temp-days').val()) || 2;
    const r = parseInt($('#qs-trash-days').val()) || 7;
    $.when(
        $.post(API_URL, { action:'save_param', param_group:'QUOTATION', param_key:'temp_attach_days',  param_value: JSON.stringify(t), description:'未存檔暫存附件自動刪除天數' }),
        $.post(API_URL, { action:'save_param', param_group:'QUOTATION', param_key:'trash_attach_days', param_value: JSON.stringify(r), description:'補件被否決附件自動刪除天數' })
    ).done(() => {
        $('#qs-temp-days').val(t); $('#qs-trash-days').val(r);
        Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已儲存', showConfirmButton:false, timer:1800 });
    }).fail(() => Swal.fire('錯誤','儲存失敗','error'));
}
function autoFillValidUntil() {
    if (!_validDays) return;
    const d = $('#quote_date').val();
    if (!d) return;
    const dt = new Date(d + 'T00:00:00');
    dt.setDate(dt.getDate() + _validDays);
    $('#valid_until').val(dt.toISOString().slice(0,10));
}

function saveFormNumber() {
    const v = $('#qs-form-number').val().trim();
    $.post(API_URL, { action:'save_param', param_group:'QUOTATION', param_key:'form_number',
        param_value: JSON.stringify(v), description:'列印表單編號' }, res => {
        if (res.success) Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已儲存', showConfirmButton:false, timer:1800 });
        else Swal.fire('錯誤', res.message, 'error');
    });
}
function loadFormNumber() {
    $.get(API_URL, { action:'get_param', param_group:'QUOTATION', param_key:'form_number' }, res => {
        if (res.success && res.value) $('#qs-form-number').val(typeof res.value === 'string' ? res.value : '');
    });
    loadAsDocBinding();
}
// ── 綁定 AS 文件編號（ai-rules/16：編號一律走 as_document 綁定，禁寫死；列印於每頁頁尾右下角）──
function loadAsDocBinding() {
    $.get(API_URL, { action:'get_as_documents' }, res => {
        if (!res || !res.success) return;
        const cur  = parseInt(res.bound_id) || 0;
        const opts = ['<option value="0">— 未綁定 —</option>'].concat(
            (res.docs || []).map(d =>
                `<option value="${d.id}" ${Number(d.id) === cur ? 'selected' : ''}>${escapeHtml(d.doc_no + '　' + (d.doc_name || ''))}</option>`));
        $('#qs-as-doc').html(opts.join(''));
    }, 'json');
}
function saveAsDocBinding() {
    const v = parseInt($('#qs-as-doc').val()) || 0;
    $.post(API_URL, { action:'save_param', param_group:'QUOTATION', param_key:'as_doc_id',
        param_value: JSON.stringify(v), description:'報價單列印綁定的AS文件id(0=未綁定)' }, res => {
        if (res.success) Swal.fire({ toast:true, position:'top-end', icon:'success', title: v ? '已綁定 AS 文件編號' : '已解除綁定', showConfirmButton:false, timer:2000 });
        else Swal.fire('錯誤', res.message, 'error');
    });
}

// ── 列印是否需審核通過（設定頁勾選，僅有設定權限者能開設定視窗；未設定過＝需要審核，維持原行為）──
let printNeedApproval = true;
function loadPrintApprovalSetting() {
    $.get(API_URL, { action:'get_param', param_group:'QUOTATION', param_key:'print_need_approval' }, res => {
        if (res.success && res.value !== null && res.value !== undefined && res.value !== '') {
            printNeedApproval = parseInt(res.value) !== 0;
        }
        $('#qs-print-need-approval').prop('checked', printNeedApproval);
    });
}
function savePrintNeedApproval(on) {
    // 值存字串 '1'/'0' 不存數字：API get_param 用 PHP 真值判斷($val?)，數字0存進去會被當空值回傳null
    $.post(API_URL, { action:'save_param', param_group:'QUOTATION', param_key:'print_need_approval',
        param_value: JSON.stringify(on ? '1' : '0'), description:'列印是否需主管審核通過' }, res => {
        if (res.success) {
            printNeedApproval = !!on;
            // 目前開著的檢視/編輯畫面立即套用新規則（不用重開）
            if (window._lastPrintGateQuote) updatePrintGate(window._lastPrintGateQuote);
            Swal.fire({ toast:true, position:'top-end', icon:'success',
                title: on ? '已設定：需審核通過才能列印' : '已設定：正式報價單不需審核即可列印',
                showConfirmButton:false, timer:2500 });
        } else {
            $('#qs-print-need-approval').prop('checked', printNeedApproval); // 存檔失敗還原勾選狀態
            Swal.fire('錯誤', res.message || '儲存失敗', 'error');
        }
    });
}
function openUploadSettings() {
    if (!CAN_SETTINGS) {
        Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: '您沒有報價單設定權限', showConfirmButton: false, timer: 2000 });
        return;
    }
    openSettingsModal();
}

// ══════════════════════════════════════════════════════
// 權限相關
// ══════════════════════════════════════════════════════
function openPermHelp() { $('#permHelpModal').modal('show'); }

// ══════════════════════════════════════════════════════
// 角色管理
// ══════════════════════════════════════════════════════
let _selectedRoleId   = null;
let _selectedRoleCode = null;

function loadPermissionsTab() {
    if (!IS_ADMIN) return;
    loadRolesPanel();
}

function loadRolesPanel() {
    $('#roles-list').html('<div class="text-center text-muted" style="padding:20px;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>');
    $.get(ROLES_API_URL, { action:'get_roles', module:'quotation' }, res => {
        if (!res.success) { $('#roles-list').html('<div class="text-danger" style="padding:10px;">載入失敗</div>'); return; }
        let html = '';
        res.data.forEach(r => {
            const isSystem = r.is_system == 1;
            const active = _selectedRoleId == r.role_id ? 'background:#e8f0fe;font-weight:600;' : '';
            html += `<div class="role-item" data-id="${r.role_id}" data-code="${escapeHtml(r.role_code)}"
                style="padding:7px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;${active}"
                onclick="selectRole(${r.role_id},'${escapeHtml(r.role_code)}','${escapeHtml(r.role_name)}',${isSystem})">
                <span style="flex:1;font-size:13px;">${escapeHtml(r.role_name)}${isSystem?'<span class="label label-warning" style="font-size:9px;margin-left:5px;vertical-align:middle;">系統</span>':''}</span>
                ${!isSystem ? `<button class="btn btn-xs btn-danger" style="opacity:.6;padding:1px 5px;"
                    onclick="event.stopPropagation();deleteRole(${r.role_id},'${escapeHtml(r.role_name)}')">
                    <i class="fa fa-times"></i></button>` : ''}
            </div>`;
        });
        $('#roles-list').html(html || '<div class="text-muted" style="padding:10px;font-size:12px;">尚無角色</div>');
        if (_selectedRoleId) selectRole(_selectedRoleId, _selectedRoleCode, null, null);
    });
}

function selectRole(roleId, roleCode, roleName, isSystem) {
    _selectedRoleId   = roleId;
    _selectedRoleCode = roleCode;
    // 更新列表高亮
    $('#roles-list .role-item').css('background','');
    $(`#roles-list .role-item[data-id="${roleId}"]`).css({'background':'#e8f0fe','font-weight':'600'});
    const displayName = roleName || $(`#roles-list .role-item[data-id="${roleId}"]`).find('span:first').text().replace('系統','').trim();
    $('#role-feat-header').text(`設定功能：${displayName}`);
    // 全部取消勾選
    $('.role-feat-cb').prop('checked', false).prop('disabled', isSystem);
    $('#role-feat-footer').show();
    if (isSystem) {
        // admin role → 全勾且 disabled
        $('.role-feat-cb').prop('checked', true);
        $('#role-feat-note').text('系統角色不可修改（擁有全部功能）');
        $('#role-feat-footer button').prop('disabled', true);
        $('#btn-check-all, #btn-uncheck-all').prop('disabled', true);
        return;
    }
    $('#role-feat-note').text('');
    $('#role-feat-footer button').prop('disabled', false);
    $('#btn-check-all, #btn-uncheck-all').prop('disabled', false);
    // 載入此角色的已有 features
    $.get(ROLES_API_URL, { action:'get_role_features', role_id: roleId }, res => {
        if (res.success && res.data) {
            res.data.forEach(code => {
                $(`.role-feat-cb[value="${code}"]`).prop('checked', true);
            });
        }
    });
}

function toggleAllFeatures(checked) {
    $('.role-feat-cb:not(:disabled)').prop('checked', checked);
}

function saveRoleFeatures() {
    if (!_selectedRoleId) return;
    const codes = [];
    $('.role-feat-cb:checked').each(function(){ codes.push($(this).val()); });
    $.post(ROLES_API_URL, { action:'save_role_features', role_id:_selectedRoleId, features: JSON.stringify(codes) }, res => {
        if (res.success) {
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'角色設定已儲存', showConfirmButton:false, timer:1800 });
        } else {
            Swal.fire('錯誤', res.message||'儲存失敗', 'error');
        }
    });
}

function addRole() {
    Swal.fire({
        title: '新增角色',
        input: 'text',
        inputLabel: '角色名稱',
        inputPlaceholder: '例：業務助理、部門主管',
        showCancelButton: true,
        confirmButtonText: '新增',
        cancelButtonText: '取消',
        didOpen: () => { $(document).off('focusin.modal'); Swal.getInput().focus(); },
        inputValidator: v => !v.trim() ? '請輸入角色名稱' : null
    }).then(r => {
        if (!r.isConfirmed || !r.value.trim()) return;
        $.post(ROLES_API_URL, { action:'save_role', role_name: r.value.trim(), module:'quotation' }, res => {
            if (!res.success) { Swal.fire('錯誤', res.message||'新增失敗', 'error'); return; }
            _selectedRoleId = res.role_id;
            loadRolesPanel();
        });
    });
}

function deleteRole(roleId, roleName) {
    Swal.fire({
        title: `刪除角色「${roleName}」？`,
        text: '此角色的功能設定將一併刪除，但不影響已套用此角色的使用者記錄。',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: '確認刪除',
        cancelButtonText: '取消'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(ROLES_API_URL, { action:'delete_role', role_id: roleId }, res => {
            if (!res.success) { Swal.fire('錯誤', res.message||'刪除失敗', 'error'); return; }
            if (_selectedRoleId == roleId) { _selectedRoleId = null; $('#role-feat-header').text('← 請選擇角色'); $('#role-feat-footer').hide(); }
            loadRolesPanel();
        });
    });
}

// ══════════════════════════════════════════════════════
// 列印紀錄
// ══════════════════════════════════════════════════════
let _printLogLoaded = false;
function loadPrintLog() {
    if (_printLogLoaded) return;
    _printLogLoaded = true;
    $('#printLogTbody').html('<tr><td colspan="4" class="text-center"><i class="fa fa-spinner fa-spin"></i></td></tr>');
    $.get(API_URL, { action:'get_print_log', limit:300 }, res => {
        if (!res.success || !res.data.length) {
            $('#printLogTbody').html('<tr><td colspan="4" class="text-center text-muted">無列印紀錄</td></tr>');
            return;
        }
        let html = '';
        res.data.forEach(r => {
            html += `<tr>
                <td style="font-weight:600;color:var(--primary);">${escapeHtml(r.quote_no)}</td>
                <td>${escapeHtml(r.client_name||'客戶不存在')}</td>
                <td>${escapeHtml(r.printed_by_name||'')}</td>
                <td style="color:#888;">${escapeHtml((r.printed_at||'').slice(0,16))}</td>
            </tr>`;
        });
        $('#printLogTbody').html(html);
    });
}
function openPrintLog() {
    _printLogLoaded = false;
    $('#historyLogModal').modal('show');
    $('#tab-print-li a').tab('show');
    loadPrintLog();
}

// ══════════════════════════════════════════════════════
// 還原已刪除報價單
// ══════════════════════════════════════════════════════
function restoreDeletedQuote(logId) {
    if (!CAN_RESTORE) {
        Swal.fire('權限不足', '您沒有還原已刪除報價單的權限', 'error');
        return;
    }
    Swal.fire({
        title: '還原報價單',
        text: '將以草稿方式開啟，您可修改單號後再儲存。',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '確認還原',
        cancelButtonText: '取消',
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action:'restore_deleted_quote', log_id:logId }, res => {
            if (!res.success) { Swal.fire('錯誤', res.message, 'error'); return; }
            const snap = res.data;
            $('#historyLogModal').modal('hide');
            // 開啟新增模式並帶入快照資料（同複製邏輯）
            ++_editToken;
            resetEditor();
            currentEditId = null;
            $('#viewPanel').hide();
            $('#editorEmpty').hide();
            $('#editorTitle').html('<i class="fa fa-undo" style="color:var(--accent);margin-right:6px;"></i>還原草稿');
            $('#changeLogBtn, #delQuoteBtn, #cloneQuoteBtn').hide();
            const today = todayStr();
            $('#quote_date').val(today);
            autoFillValidUntil();
            const prefix = quoteNoPrefixFromDate(today);
            $('#quote_no_prefix').text(prefix);
            $.get(API_URL, { action:'get_new_quote_no' }, qres => {
                if (qres.success) { $('#quote_seq').val(qres.quote_no.slice(-3)); syncQuoteNo(); }
            });
            $('#client_name').val(snap.client_name || '');
            $('#client_id').val(snap.client_id || '');
            $('#inquiry_no').val(snap.inquiry_no || '');
            if (snap.client_id) loadClientContacts(snap.client_id, null);
            $('#currency').val(snap.currency || 'TWD');
            $('#exchange_rate').val(snap.exchange_rate || 1);
            $('#note').val(snap.note || '').trigger('input');
            $('#is_negotiation').prop('checked', snap.is_negotiation == 1);
            const items = snap.items || [];
            items.forEach(item => addItemRow({ ...item, item_id: null }));
            if (!items.length) addItemRow();
            calculateTotal();
            showEditor();
            $('#note').trigger('input');
            updatePartSearchPlaceholders();
            updateEditorClientTag();
            Swal.fire({ toast:true, position:'top-end', icon:'success',
                title:`已還原 ${escapeHtml(snap.quote_no||'')} 的資料，請確認單號後儲存`,
                showConfirmButton:false, timer:4000 });
        });
    });
}

// ══════════════════════════════════════════════════════
// 建立 / 修改資訊列
// ══════════════════════════════════════════════════════
function renderHistoryBar(d) {
    if (!d || !d.created_by_name) { $('#historyBar').hide(); return; }
    const fmt = s => s ? s.replace('T',' ').slice(0,16).replace(/^(\d{4})-(\d{2})-(\d{2})/,'$1.$2.$3') : '';
    $('#histCreated').html(`<i class="fa fa-user-o"></i>建立：${escapeHtml(d.created_by_name)}　${escapeHtml(fmt(d.created_at))}`);
    const $upd = $('#histUpdated');
    if (d.updated_by_name && d.updated_at) {
        $upd.html(`<i class="fa fa-pencil"></i>修改：${escapeHtml(d.updated_by_name)}　${escapeHtml(fmt(d.updated_at))}`).show();
    } else {
        $upd.hide();
    }
    $('#histPrint').hide();
    $('#historyBar').show();
    // 非同步載入列印次數
    if (d.quote_id) {
        $.get(API_URL, { action:'get_print_log', quote_id: d.quote_id, limit:100 }, res => {
            if (res.success && res.data && res.data.length) {
                const cnt = res.data.length;
                const last = res.data[0];
                const lastInfo = `${escapeHtml(last.printed_by_name||'')} ${escapeHtml((last.printed_at||'').slice(0,16))}`;
                $('#histPrint').html(`<i class="fa fa-print"></i>已列印 ${cnt} 次　最後：${lastInfo}`).show();
            }
        });
    }
}

// ══════════════════════════════════════════════════════
// 已刪除報價單紀錄
// ══════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════
// 修改紀錄
// ══════════════════════════════════════════════════════
const _fieldNameMap = {
    quote_date:'報價日期', valid_until:'有效日期', client_name:'客戶名稱',
    client_id:'客戶代碼', inquiry_no:'詢價編號', currency:'幣別',
    exchange_rate:'匯率', total_amount:'總金額', note:'備註', is_negotiation:'議價'
};
function openChangeLog() {
    const qid = $('#quote_id').val();
    if (!qid) return;
    $('#changeLogModal').modal('show');
    $('#changeLogTbody').html('<tr><td colspan="3" class="text-center"><i class="fa fa-spinner fa-spin"></i></td></tr>');
    $.get(API_URL, { action: 'get_change_log', quote_id: qid, limit: 50 }, res => {
        if (!res.success || !res.data.length) {
            $('#changeLogTbody').html('<tr><td colspan="3" class="text-center text-muted">無修改紀錄</td></tr>');
            $('#changeLogInfo').text('');
            return;
        }
        let html = '';
        res.data.forEach(r => {
            let diffHtml = '';
            try {
                const diff = JSON.parse(r.diff_json || '{}');
                diffHtml = Object.entries(diff).map(([k, v]) => {
                    const label = _fieldNameMap[k] || k;
                    const from  = v.from !== undefined ? escapeHtml(String(v.from)) : '';
                    const to    = v.to   !== undefined ? escapeHtml(String(v.to))   : '';
                    return `<span style="margin-right:8px;">${escapeHtml(label)}：<span style="color:#c0392b;text-decoration:line-through;">${from}</span> → <span style="color:#27ae60;">${to}</span></span>`;
                }).join('');
            } catch(e) { diffHtml = escapeHtml(r.summary); }
            const dt = (r.changed_at || '').slice(0, 16);
            html += `<tr>
                <td style="color:#888;white-space:nowrap;">${escapeHtml(dt)}</td>
                <td>${escapeHtml(r.changed_by_name || '')}</td>
                <td style="font-size:11px;line-height:1.8;">${diffHtml || '—'}</td>
            </tr>`;
        });
        $('#changeLogTbody').html(html);
        $('#changeLogInfo').text(`顯示最近 ${res.data.length} 筆（最多保留 100 筆）`);
    }).fail(() => {
        $('#changeLogTbody').html('<tr><td colspan="3" class="text-center text-danger">載入失敗</td></tr>');
    });
}

let _deletedLogLoaded = false;
function loadDeletedLog() {
    if (_deletedLogLoaded) return;
    _deletedLogLoaded = true;
    $('#deleteLogTbody').html('<tr><td colspan="7" class="text-center"><i class="fa fa-spinner fa-spin"></i></td></tr>');
    $.get(API_URL, { action: 'get_delete_log', limit: 100 }, res => {
        if (!res.success) { $('#deleteLogTbody').html('<tr><td colspan="7" class="text-center text-danger">載入失敗</td></tr>'); return; }
        if (!res.data.length) { $('#deleteLogTbody').html('<tr><td colspan="8" class="text-center text-muted">無刪除紀錄</td></tr>'); return; }
        let html = '';
        res.data.forEach(r => {
            const delAt = (r.deleted_at || '').slice(0, 16);
            const reason = r.delete_reason ? escapeHtml(r.delete_reason) : '<span style="color:#ccc;">—</span>';
            html += `<tr>
                <td style="font-weight:600;color:var(--primary);">${escapeHtml(r.quote_no)}</td>
                <td>${escapeHtml(String(r.quote_date||'').replace(/-/g,'.'))}</td>
                <td>${escapeHtml(r.client_name || '—')}</td>
                <td style="text-align:right;">${formatNumber(r.total_amount)}</td>
                <td style="font-size:12px;color:#555;">${reason}</td>
                <td>${escapeHtml(r.deleted_by_name || String(r.deleted_by || ''))}</td>
                <td style="color:#888;">${escapeHtml(delAt)}</td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-xs btn-default" onclick="showSnapshot(${r.id},'${escapeHtml(r.quote_no)}')" title="查看快照"><i class="fa fa-eye"></i></button>
                    ${CAN_RESTORE ? `<button class="btn btn-xs btn-success" onclick="restoreDeletedQuote(${r.id})" title="還原為草稿"><i class="fa fa-undo"></i></button>` : ''}
                </td>
            </tr>`;
        });
        $('#deleteLogTbody').html(html);
    });
}
function openDeleteLog() {
    _deletedLogLoaded = false;
    _printLogLoaded = false;
    $('#historyLogModal').modal('show');
    // 預設顯示已刪除分頁
    $('#tab-deleted-li a').tab('show');
    loadDeletedLog();
}

let _snapshotCache = {};
function showSnapshot(logId, quoteNo) {
    $('#snapshotTitle').html(`<i class="fa fa-file-text-o" style="margin-right:7px;"></i>快照：${escapeHtml(quoteNo)}`);
    $('#snapshotModal').modal('show');
    // 從 deleteLogTbody 抓快照資料（需從 API 取，這裡用 get_delete_log 再過濾）
    $.get(API_URL, { action: 'get_delete_log', limit: 200 }, res => {
        if (!res.success) { $('#snapshotBody').html('<p class="text-danger">無法載入快照</p>'); return; }
        const row = (res.data || []).find(r => r.id == logId);
        if (!row || !row.snapshot) { $('#snapshotBody').html('<p class="text-muted">無快照資料</p>'); return; }
        let snap;
        try { snap = JSON.parse(row.snapshot); } catch(e) { $('#snapshotBody').html('<pre>' + escapeHtml(row.snapshot) + '</pre>'); return; }
        const fmt = s => s ? s.replace('T',' ').slice(0,16).replace(/^(\d{4})-(\d{2})-(\d{2})/,'$1.$2.$3') : '—';
        const fmtDate = s => s ? String(s).slice(0,10).replace(/-/g,'.') : '';
        let html = `<table class="table table-condensed" style="font-size:12px;">
            <tr><td style="width:100px;color:#888;">報價單號</td><td><strong>${escapeHtml(snap.quote_no||'')}</strong></td>
                <td style="width:80px;color:#888;">日期</td><td>${escapeHtml(fmtDate(snap.quote_date))}</td></tr>
            <tr><td style="color:#888;">客戶</td><td>${escapeHtml(snap.client_name||'')}</td>
                <td style="color:#888;">幣別</td><td>${escapeHtml(snap.currency||'')}</td></tr>
            <tr><td style="color:#888;">備註</td><td colspan="3">${escapeHtml(snap.note||'')}</td></tr>
            <tr><td style="color:#888;">建立者</td><td>${escapeHtml(snap.created_by_name||String(snap.created_by||''))}</td>
                <td style="color:#888;">建立時間</td><td>${escapeHtml(fmt(snap.created_at))}</td></tr>
        </table>
        <hr style="margin:8px 0;">
        <strong style="font-size:12px;">報價項目</strong>
        <table class="table table-condensed table-bordered" style="font-size:12px;margin-top:6px;">
          <thead><tr><th>#</th><th>料號</th><th>規格/製程</th><th>數量</th><th>單位</th><th>單價</th><th>金額</th></tr></thead>
          <tbody>`;
        (snap.items || []).forEach((it, i) => {
            html += `<tr>
                <td>${i+1}</td>
                <td>${qlDrawingSpan(it.product_id, it.d_setting_d_id)}</td>
                <td>${escapeHtml(it.specification||'')}</td>
                <td style="text-align:right;">${formatNumber(it.quantity)}</td>
                <td>${escapeHtml(it.unit||'')}</td>
                <td style="text-align:right;">${formatNumber(it.unit_price)}</td>
                <td style="text-align:right;">${formatNumber(it.amount)}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
        $('#snapshotBody').html(html);
    });
}
function initFileUpload() {
    const $zone = $('#fileDropZone');
    $zone.on('dragover', e => { e.preventDefault(); $zone.addClass('drag-over'); });
    $zone.on('dragleave drop', e => { e.preventDefault(); $zone.removeClass('drag-over'); });
    $zone.on('drop', function (e) {
        const files = e.originalEvent.dataTransfer.files;
        for (const f of files) handleFileUpload(f);
    });
    $('#fileInput').on('change', function () {
        for (const f of this.files) handleFileUpload(f);
        this.value = '';
    });
}
function getCurrentQuoteNo() {
    return $('#quote_no').val().trim();
}
function handleFileUpload(file) {
    const qno = getCurrentQuoteNo();
    if (!qno) { Swal.fire('提示', '請先填寫報價單號再上傳附件', 'info'); return; }
    if (!currentUploadPath) { Swal.fire('提示', '尚未設定儲存路徑，請點擊右上角 ⚙ 設定', 'info'); return; }

    const fd = new FormData();
    fd.append('action', 'upload_file');
    fd.append('quote_no', qno);
    fd.append('file', file);

    // 暫時顯示上傳中
    const $list = $('#uploadedFilesList');
    const tmpId = 'tmp_' + Date.now();
    $list.append(`<div id="${tmpId}" class="file-item">
        <i class="fa fa-spinner fa-spin text-muted"></i>
        <span class="file-item-name text-muted">${escapeHtml(file.name)}</span>
        <span class="file-item-size text-muted">上傳中...</span>
    </div>`);

    $.ajax({
        url: FILE_API_URL, type: 'POST', data: fd,
        processData: false, contentType: false,
        success: function (res) {
            $(`#${tmpId}`).remove();
            if (res.success) {
                const $wrap = appendFileItem(res, qno);
                // 上傳完立即展開標籤面板，當場設定類別＋連結料號
                renderFileTagPanel($wrap, res, qno);
                $wrap.find('.file-tag-panel').slideDown(150);
                refreshPartAttachBadges();
                // 新建報價（未儲存）才追蹤，用於頁面離開時清理
                if (!currentEditId) _tempUploadQno = qno;
            } else {
                Swal.fire('上傳失敗', res.message, 'error');
            }
        },
        error: function () {
            $(`#${tmpId}`).remove();
            Swal.fire('錯誤', '上傳時發生通訊錯誤', 'error');
        }
    });
}
function loadFileList(quoteNo, isViewMode) {
    if (!quoteNo) return;
    if (isViewMode) {
        $('#viewAttachList').html('<div class="text-muted" style="font-size:12px;padding:4px 0;"><i class="fa fa-spinner fa-spin"></i></div>');
        $.get(FILE_API_URL, { action: 'list_files', quote_no: quoteNo }, res => {
            $('#viewAttachList').empty();
            if (res.success && res.files.length > 0) {
                res.files.forEach(f => appendFileItemView(f, quoteNo));
            } else {
                $('#viewAttachSection').hide();
            }
        });
        return;
    }
    $('#uploadedFilesList').empty();
    $.get(FILE_API_URL, { action: 'list_files', quote_no: quoteNo }, res => {
        if (res.success) {
            res.files.forEach(f => appendFileItem(f, quoteNo));
        }
        refreshPartAttachBadges();
    });
}
// 含必備類別的附件：只有單一料號時不需要使用者手動選，直接自動綁定該料號（多料號仍必須手動選）。
// 會直接改寫 f.linked_parts；若有 attachment_id 一併存回 DB。回傳（可能更新過的）linked_parts JSON 字串或 null。
function autoBindSinglePartLinkedParts(f) {
    const catIds = (f.category_ids || (f.category_id ? String(f.category_id) : '')).split(',').map(s => s.trim()).filter(Boolean);
    const reqCats = effectiveRequiredCats();
    const hasReq = catIds.some(id => reqCats.some(r => Number(r) === Number(id)));
    if (!hasReq) return f.linked_parts || null;
    const quoteParts = getQuoteParts();
    if (quoteParts.length !== 1) return f.linked_parts || null;
    const cur = f.linked_parts ? JSON.parse(f.linked_parts) : null;
    if (cur && cur.length === 1 && String(cur[0]) === String(quoteParts[0])) return f.linked_parts;
    const newVal = JSON.stringify([quoteParts[0]]);
    f.linked_parts = newVal;
    if (f.attachment_id) saveAttachmentMeta(f.attachment_id, catIds.join(','), newVal);
    return newVal;
}
function appendFileItem(f, quoteNo) {
    autoBindSinglePartLinkedParts(f);
    const ext  = (f.filename.split('.').pop() || '').toLowerCase();
    const icon = ['pdf'].includes(ext) ? 'fa-file-pdf-o text-danger'
               : ['xls','xlsx'].includes(ext) ? 'fa-file-excel-o text-success'
               : ['doc','docx'].includes(ext) ? 'fa-file-word-o text-primary'
               : ['png','jpg','jpeg','gif','bmp'].includes(ext) ? 'fa-file-image-o text-warning'
               : 'fa-file-o text-muted';
    // 開檔網址帶 mtime 破快取（旋轉存檔後 mtime 變 → 重新載入不吃舊圖）
    const dlUrl    = `${FILE_API_URL}?action=download&quote_no=${encodeURIComponent(quoteNo)}&filename=${encodeURIComponent(f.filename)}&v=${encodeURIComponent(f.mtime||'')}`;
    const isImg    = ['png','jpg','jpeg','gif','bmp','webp'].includes(ext);
    const attachId = f.attachment_id || '';
    const dispName = escapeHtml(f.original_name || f.filename);
    // 解析多類別 IDs → 名稱
    const initCatIds = (f.category_ids || (f.category_id ? String(f.category_id) : ''))
        .split(',').map(s => s.trim()).filter(Boolean);
    f.category_ids = initCatIds.join(',');
    const catLabel  = initCatIds.map(id => {
        const c = getCatById(id);
        return c ? c.category_name : '';
    }).filter(Boolean).map(escapeHtml).join(', ');
    const btnHasCat = catLabel ? 'has-cat' : '';
    // 解析已連結料號 → 徽章文字（未設定/共用附件則不顯示徽章）
    const initLinkedParts = f.linked_parts ? JSON.parse(f.linked_parts) : null;
    const partBadgeHtml   = filePartBadgeHtml(initLinkedParts);
    // 狀態徽章：暫存(未存檔)/補件審核中。active 不顯示。
    const _st = f.status || 'active';
    const statusBadge = _st === 'temp'
        ? `<span class="file-status-badge" style="font-size:10px;padding:1px 6px;border-radius:3px;background:#F7E0BD;color:#7a4a00;margin-left:4px;white-space:nowrap;" title="尚未存檔，目前僅為暫存檔；存檔或存草稿後才正式上傳、才會出現在料號報價附件中。逾期未存檔會自動刪除。"><i class="fa fa-clock-o"></i> 暫存·未存檔</span>`
      : _st === 'pending'
        ? `<span class="file-status-badge" style="font-size:10px;padding:1px 6px;border-radius:3px;background:#F0A24B;color:#fff;margin-left:4px;white-space:nowrap;" title="補件審核中，通過後才正式放入此報價單"><i class="fa fa-hourglass-half"></i> 補件審核中</span>`
      : '';

    const $wrap = $(`<div class="file-item-wrap" data-filename="${escapeHtml(f.filename)}" data-attach-id="${escapeHtml(String(attachId))}" data-status="${escapeHtml(_st)}" data-cat-ids="${escapeHtml(f.category_ids)}">
        <div class="file-item" style="border-bottom:none;">
            <i class="fa ${icon}"></i>
            <button class="btn btn-xs btn-default file-tag-toggle-btn ${btnHasCat}" title="設定類別 / 料號連結" style="padding:1px 7px;">
                <i class="fa fa-tag"></i>
                <span class="file-cat-label" style="margin-left:3px;">${catLabel}</span>
            </button>
            <span class="file-part-badge-slot">${partBadgeHtml}</span>
            ${statusBadge}
            <span class="file-item-name" title="${escapeHtml(f.original_name||f.filename)}${isImg?'（點擊可檢視並旋轉）':''}" style="cursor:pointer;">${dispName}${isImg?' <i class="fa fa-search-plus" style="opacity:.5;font-size:10px;"></i>':''}</span>
            <span class="file-item-size">${escapeHtml(f.size)}</span>
            <span class="file-item-time">${escapeHtml(f.mtime)}</span>
            <button class="btn btn-xs btn-danger file-del-btn" style="padding:1px 5px;" title="刪除此附件">
                <i class="fa fa-trash"></i>
            </button>
        </div>
        <div class="file-tag-panel" style="display:none;"></div>
    </div>`);
    // 連結料號狀態（''=共用附件；JSON 陣列=指定料號）— 供徽章與儲存檢查使用
    $wrap.attr('data-linked-parts', f.linked_parts || '');

    // 展開/收合標籤面板
    $wrap.find('.file-tag-toggle-btn').on('click', function () {
        const $panel = $wrap.find('.file-tag-panel');
        if ($panel.is(':visible')) { $panel.slideUp(120); return; }
        renderFileTagPanel($wrap, f, quoteNo);
        $panel.slideDown(150);
    });

    // 刪除
    $wrap.find('.file-del-btn').on('click', function () {
        Swal.fire({
            title: '確定刪除此附件？', text: f.original_name || f.filename,
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#d33', confirmButtonText: '刪除', cancelButtonText: '取消'
        }).then(r => {
            if (!r.isConfirmed) return;
            $.post(FILE_API_URL, { action: 'delete_file', quote_no: quoteNo, filename: f.filename }, res => {
                if (res.success) {
                    $wrap.remove();
                    refreshPartAttachBadges();
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '已刪除', showConfirmButton: false, timer: 1500 });
                } else {
                    Swal.fire('錯誤', res.message, 'error');
                }
            });
        });
    });

    // 點檔名：圖片→開檢視器(可旋轉存檔)；其他檔→新分頁開原檔
    $wrap.find('.file-item-name').on('click', function () {
        if (isImg) openImageViewer(quoteNo, f.filename, f.original_name || f.filename, false);
        else window.open(dlUrl, '_blank');
    });

    $('#uploadedFilesList').append($wrap);
    return $wrap;
}

// ── 圖片檢視器（點開附件檢視，可即時旋轉並存檔）──────────────
// isViewMode：來源是唯讀檢視清單(true)或編輯清單(false)；旋轉後重載對應清單以更新 mtime/破快取
let _imgViewer = { quoteNo:'', filename:'', name:'', isViewMode:false };
function openImageViewer(quoteNo, filename, name, isViewMode) {
    _imgViewer = { quoteNo, filename, name, isViewMode:!!isViewMode };
    $('#imgViewerName').text(name || filename);
    // 旋轉存檔需編輯權限；無權者僅檢視
    const canRotate = (typeof CAN_EDIT !== 'undefined' && CAN_EDIT) || (typeof IS_ADMIN !== 'undefined' && IS_ADMIN);
    $('#imgViewerRotL, #imgViewerRotR, #imgViewerHint').toggle(!!canRotate);
    _imgViewerReload();
    $('#imgViewerModal').modal('show');
}
function _imgViewerReload() {
    const t = new Date().getTime();  // 破快取：旋轉存檔後吃到新圖
    const url = `${FILE_API_URL}?action=download&quote_no=${encodeURIComponent(_imgViewer.quoteNo)}&filename=${encodeURIComponent(_imgViewer.filename)}&_t=${t}`;
    $('#imgViewerImg').hide().attr('src', url);
    $('#imgViewerOpen').attr('href', url);
    $('#imgViewerLoading').show();
}
function _imgViewerRotate(deg) {
    const canRotate = (typeof CAN_EDIT !== 'undefined' && CAN_EDIT) || (typeof IS_ADMIN !== 'undefined' && IS_ADMIN);
    if (!canRotate) { Swal.fire('無權限','需編輯權限才能旋轉存檔','warning'); return; }
    $('#imgViewerRotL, #imgViewerRotR').prop('disabled', true);
    $.post(FILE_API_URL, { action:'rotate_file', quote_no:_imgViewer.quoteNo, filename:_imgViewer.filename, deg:deg }, res => {
        $('#imgViewerRotL, #imgViewerRotR').prop('disabled', false);
        if (res && res.success) {
            _imgViewerReload();                                   // 檢視器換上旋轉後的圖
            if (typeof loadFileList === 'function') loadFileList(_imgViewer.quoteNo, _imgViewer.isViewMode); // 背景清單同步更新
            Swal.fire({ toast:true, position:'top-end', icon:'success', title:'已旋轉並存檔', showConfirmButton:false, timer:1200 });
        } else {
            Swal.fire('無法旋轉', (res && res.message) || '請稍後再試', 'warning');
        }
    }, 'json').fail(() => { $('#imgViewerRotL, #imgViewerRotR').prop('disabled', false); Swal.fire('錯誤','與伺服器通訊失敗','error'); });
}
// 圖片載入完成 → 隱藏 loading 顯示圖；綁定旋轉鈕（一次即可）
$(function () {
    $('#imgViewerImg').on('load', function () { $('#imgViewerLoading').hide(); $(this).show(); })
                      .on('error', function () { $('#imgViewerLoading').hide(); });
    $('#imgViewerRotL').on('click', () => _imgViewerRotate(-90));
    $('#imgViewerRotR').on('click', () => _imgViewerRotate(90));
});

// ── 檢視模式附件項目（唯讀，可點開）────────────────────────
function appendFileItemView(f, quoteNo) {
    const ext  = (f.filename.split('.').pop() || '').toLowerCase();
    const icon = ['pdf'].includes(ext) ? 'fa-file-pdf-o text-danger'
               : ['xls','xlsx'].includes(ext) ? 'fa-file-excel-o text-success'
               : ['doc','docx'].includes(ext) ? 'fa-file-word-o text-primary'
               : ['png','jpg','jpeg','gif','bmp'].includes(ext) ? 'fa-file-image-o text-warning'
               : 'fa-file-o text-muted';
    const dlUrl    = `${FILE_API_URL}?action=download&quote_no=${encodeURIComponent(quoteNo)}&filename=${encodeURIComponent(f.filename)}`;
    const dispName = escapeHtml(f.original_name || f.filename);
    const initCatIds = (f.category_ids || (f.category_id ? String(f.category_id) : ''))
        .split(',').map(s => s.trim()).filter(Boolean);
    const catLabel  = initCatIds.map(id => {
        const c = getCatById(id);
        return c ? c.category_name : '';
    }).filter(Boolean).map(escapeHtml).join(', ');

    const catHtml = catLabel
        ? `<span style="font-size:11px;color:#666;background:#f0f0f0;border-radius:3px;padding:1px 6px;white-space:nowrap;"><i class="fa fa-tag"></i> ${catLabel}</span>`
        : '';
    const linkedParts = f.linked_parts ? JSON.parse(f.linked_parts) : null;
    const partHtml     = filePartBadgeHtml(linkedParts);
    const isImg        = ['png','jpg','jpeg','gif','bmp','webp'].includes(ext);

    const $item = $(`<div class="file-item-wrap">
        <div class="file-item">
            <i class="fa ${icon}"></i>
            ${catHtml}
            ${partHtml}
            <span class="file-item-name" title="${escapeHtml(f.original_name||f.filename)}${isImg?'（點擊可檢視並旋轉）':''}" style="cursor:pointer;">${dispName}${isImg?' <i class="fa fa-search-plus" style="opacity:.5;font-size:10px;"></i>':''}</span>
            <span class="file-item-size">${escapeHtml(f.size)}</span>
            <span class="file-item-time">${escapeHtml(f.mtime)}</span>
        </div>
    </div>`);
    $item.find('.file-item-name').on('click', function () {
        if (isImg) openImageViewer(quoteNo, f.filename, f.original_name || f.filename, true);
        else window.open(dlUrl, '_blank');
    });
    $('#viewAttachList').append($item);
}

// ── 渲染標籤面板（類別按鈕 + 料號連結）──────────────────────
function renderFileTagPanel($wrap, f, quoteNo) {
    const attachId     = $wrap.data('attach-id');
    let linkedParts    = f.linked_parts ? JSON.parse(f.linked_parts) : null;
    let allLinked      = !linkedParts;
    const quoteParts   = getQuoteParts();

    // 類別按鈕（多選）
    const curCatIds = (f.category_ids || (f.category_id ? String(f.category_id) : ''))
        .split(',').map(s => s.trim()).filter(Boolean);
    let catHtml = '';
    if (fileCategories.length) {
        catHtml = fileCategories.map(c => {
            const active = curCatIds.includes(String(c.id));
            return `<button type="button" class="btn btn-xs file-cat-btn ${active ? 'active' : 'btn-default'}"
                data-cat-id="${c.id}">${escapeHtml(c.category_name)}</button>`;
        }).join('');
        if (curCatIds.length) {
            catHtml += ` <button type="button" class="btn btn-xs btn-link file-cat-clear" style="font-size:10px;padding:1px 4px;">清除全部</button>`;
        }
    } else {
        catHtml = '<span class="text-muted" style="font-size:11px;">（類別載入中...）</span>';
    }

    // 含必備類別的附件：不可為「全部料號」，必須連結單一料號
    const reqCats    = effectiveRequiredCats();
    const hasReqCat  = curCatIds.some(id => reqCats.some(r => Number(r) === Number(id)));

    // 只有單一料號時不需要使用者手動選，直接自動綁定該料號（多料號情況仍必須手動選）
    const autoBound = autoBindSinglePartLinkedParts(f);
    if (autoBound !== (linkedParts ? JSON.stringify(linkedParts) : null)) {
        linkedParts = autoBound ? JSON.parse(autoBound) : null;
        allLinked = !linkedParts;
        $wrap.data('linked-parts', autoBound || '');
    }

    // 料號按鈕
    let partsHtml = '';
    if (hasReqCat) {
        partsHtml = `<span class="text-danger" style="font-size:11px;margin-right:6px;">
            <i class="fa fa-asterisk"></i> 含必備類別，須連結單一料號</span>`;
    } else {
        partsHtml = `<button type="button" class="btn btn-xs file-parts-all ${allLinked ? 'active' : 'btn-default'}">
            <i class="fa fa-check-square-o"></i> 全部料號
        </button>`;
    }
    quoteParts.forEach(pid => {
        const linked = !allLinked && linkedParts.map(String).includes(String(pid));
        partsHtml += ` <button type="button" class="btn btn-xs file-part-btn ${linked ? 'active' : 'btn-default'}"
            data-part-id="${escapeHtml(pid)}">${escapeHtml(pid)}</button>`;
    });
    if (!quoteParts.length) {
        partsHtml += ' <span class="text-muted" style="font-size:11px;">（尚未填寫料號）</span>';
    }

    $wrap.find('.file-tag-panel').html(`
        <div class="ftp-row">
            <span class="ftp-label">類別：</span>
            <div class="ftp-btns cat-btns">${catHtml}</div>
        </div>
        <div class="ftp-row">
            <span class="ftp-label">連結料號：</span>
            <div class="ftp-btns part-btns">${partsHtml}</div>
        </div>
    `);

    // ── 類別按鈕事件（多選 toggle）──
    $wrap.find('.file-cat-btn').off('click').on('click', function () {
        $(this).toggleClass('active btn-default');
        // 收集所有已選 ID
        const selIds = [];
        $wrap.find('.file-cat-btn.active').each(function() { selIds.push(String($(this).data('cat-id'))); });
        const idsStr = selIds.join(',');
        f.category_ids = idsStr;
        f.category_id  = selIds[0] ? parseInt(selIds[0]) : null;
        // 更新 badge
        const names = selIds.map(id => {
            const c = getCatById(id);
            return c ? c.category_name : '';
        }).filter(Boolean);
        updateFileCatBadge($wrap, names.join(', ') || null);
        $wrap.data('cat-ids', idsStr);
        // 顯示/隱藏清除按鈕
        if (selIds.length) {
            if (!$wrap.find('.file-cat-clear').length)
                $wrap.find('.cat-btns').append(` <button type="button" class="btn btn-xs btn-link file-cat-clear" style="font-size:10px;padding:1px 4px;">清除全部</button>`);
        } else {
            $wrap.find('.file-cat-clear').remove();
        }
        if (attachId) saveAttachmentMeta(attachId, idsStr, getLinkedPartsFromWrap($wrap));
        refreshPartAttachBadges();
        // 類別增減可能切換必備模式（全部料號選項顯示/隱藏），重繪面板
        renderFileTagPanel($wrap, f, quoteNo);
    });
    // 初始化清除按鈕事件（用事件委派，支援動態插入的按鈕）
    $wrap.find('.cat-btns').off('click', '.file-cat-clear').on('click', '.file-cat-clear', function () {
        $wrap.find('.file-cat-btn.active').removeClass('active').addClass('btn-default');
        f.category_ids = '';
        f.category_id  = null;
        $wrap.data('cat-ids', '');
        updateFileCatBadge($wrap, null);
        $wrap.find('.file-cat-clear').remove();
        if (attachId) saveAttachmentMeta(attachId, '', getLinkedPartsFromWrap($wrap));
        refreshPartAttachBadges();
        renderFileTagPanel($wrap, f, quoteNo);
    });

    // ── 全部料號（必備模式下無此按鈕）──
    $wrap.find('.file-parts-all').off('click').on('click', function () {
        $wrap.find('.file-part-btn').removeClass('active').addClass('btn-default');
        $(this).addClass('active').removeClass('btn-default');
        f.linked_parts = null;
        $wrap.data('linked-parts', '');
        updateFilePartBadge($wrap, null);
        if (attachId) saveAttachmentMeta(attachId, f.category_ids || '', 'all');
        refreshPartAttachBadges();
    });

    // ── 個別料號（必備模式＝單選）──
    $wrap.find('.file-part-btn').off('click').on('click', function () {
        $wrap.find('.file-parts-all').removeClass('active').addClass('btn-default');
        if (hasReqCat) {
            $wrap.find('.file-part-btn').not(this).removeClass('active').addClass('btn-default');
        }
        $(this).toggleClass('active btn-default');
        const parts = getLinkedPartsFromWrap($wrap);
        f.linked_parts = parts === 'all' ? null : parts;
        $wrap.data('linked-parts', parts === 'all' ? '' : parts);
        updateFilePartBadge($wrap, parts === 'all' ? null : JSON.parse(parts));
        if (attachId) saveAttachmentMeta(attachId, f.category_ids || '', parts);
        refreshPartAttachBadges();
    });
}

// 取得目前勾選的料號（回傳 JSON string 或 'all'）
function getLinkedPartsFromWrap($wrap) {
    if ($wrap.find('.file-parts-all').hasClass('active')) return 'all';
    const parts = [];
    // 用 attr 讀避免 jQuery 把純數字料號自動轉成 number（造成與字串料號比對不到）
    $wrap.find('.file-part-btn.active').each(function() { parts.push(String($(this).attr('data-part-id'))); });
    return parts.length ? JSON.stringify(parts) : 'all';
}

// 已連結料號徽章 HTML（parts=null/空陣列 → 共用附件，不顯示徽章）
function filePartBadgeHtml(parts) {
    if (!parts || !parts.length) return '';
    const label = parts.map(escapeHtml).join(', ');
    return `<span class="file-part-label" style="font-size:11px;color:#8e44ad;background:#f5eefc;border-radius:3px;padding:1px 6px;white-space:nowrap;" title="已連結料號"><i class="fa fa-cube"></i> ${label}</span>`;
}
// 更新附件項目上的已連結料號徽章
function updateFilePartBadge($wrap, parts) {
    $wrap.find('.file-part-badge-slot').html(filePartBadgeHtml(parts));
}

// 更新 🏷 按鈕的類別文字（只有一個地方）
function updateFileCatBadge($wrap, catName) {
    const $btn = $wrap.find('.file-tag-toggle-btn');
    $btn.find('.file-cat-label').text(catName || '');
    if (catName) {
        $btn.addClass('has-cat')
            .css({ background:'#e8f0ff', color:'#2A3F54', borderColor:'#b0c4f0' });
    } else {
        $btn.removeClass('has-cat')
            .css({ background:'', color:'', borderColor:'' });
    }
}

// 儲存類別（多選，逗號分隔 ID）+ 料號連結到 DB
function saveAttachmentMeta(attachId, categoryIds, linkedParts) {
    $.post(FILE_API_URL, {
        action: 'update_attachment',
        attachment_id: attachId,
        category_ids:  categoryIds,
        linked_parts:  linkedParts
    }).fail(() => {
        Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: '標籤儲存失敗', showConfirmButton: false, timer: 2000 });
    });
}

// 取得目前報價單內的料號列表
function getQuoteParts() {
    const parts = [];
    $('#quoteItemsTable > tbody > tr.item-row').each(function () {
        // 優先讀 hidden field，若空則 fallback 到 part-search 顯示值
        let pid = $(this).find('.product_id_hidden').val().trim();
        if (!pid) pid = $(this).find('.part-search').val().trim();
        if (pid) parts.push(pid);
    });
    return [...new Set(parts)].filter(Boolean);
}

// ══════════════════════════════════════════════════════
// 必備附件檢查 + 料號附件徽章
// ══════════════════════════════════════════════════════

// 收集目前附件清單的類別/連結料號狀態（parts=null 表示共用附件，適用所有料號）
function collectAttachMeta() {
    const files = [];
    $('#uploadedFilesList .file-item-wrap[data-filename]').each(function () {
        const $w   = $(this);
        const cats = String($w.data('cat-ids') || '').split(',').map(s => s.trim()).filter(Boolean).map(Number);
        let lp = $w.data('linked-parts');
        let parts = null;
        if (Array.isArray(lp)) parts = lp;
        else if (typeof lp === 'string' && lp.trim()) { try { parts = JSON.parse(lp); } catch (e) { parts = null; } }
        if (Array.isArray(parts)) parts = parts.map(String);   // 舊資料可能存成數字陣列，統一轉字串比對
        files.push({
            name: $w.find('.file-item-name').first().text(),
            attachId: String($w.data('attach-id') || ''),
            cats, parts
        });
    });
    return files;
}

// 計算某料號目前擁有的附件類別集合
// 規則：一般類別 → 明確連結或共用附件皆算；必備類別 → 只有明確連結該料號才算（共用不算）
function _catSetForPart(pid, files) {
    const reqCats = effectiveRequiredCats();
    const set = new Set();
    files.forEach(f => {
        const explicit = Array.isArray(f.parts) && f.parts.map(String).includes(String(pid));
        const shared   = !f.parts;
        f.cats.forEach(c => {
            const isReq = reqCats.some(r => Number(r) === Number(c));
            if (explicit || (shared && !isReq)) set.add(Number(c));
        });
    });
    return set;
}

// 重繪所有項目列的附件徽章（綠=已有、紅=必備缺、灰=其他已含類別）
function refreshPartAttachBadges() {
    const files   = collectAttachMeta();
    const reqCats = effectiveRequiredCats();
    const chip = (txt, type) => {
        const s = type === 'ok'   ? 'background:#e8f8f0;color:#1e8449;border:1px solid #a9dfbf;'
                : type === 'lack' ? 'background:#fdecea;color:#c0392b;border:1px solid #f5b7b1;'
                :                   'background:#f0f0f0;color:#666;border:1px solid #ddd;';
        return `<span style="display:inline-block;font-size:10px;padding:0 5px;border-radius:3px;margin:0 3px 2px 0;${s}">${txt}</span>`;
    };
    $('#quoteItemsTable > tbody > tr.item-row').each(function () {
        const $box = $(this).find('.part-attach-badges');
        if (!$box.length) return;
        let pid = $(this).find('.product_id_hidden').val().trim();
        if (!pid) pid = $(this).find('.part-search').val().trim();
        if (!pid || (!files.length && !reqCats.length)) { $box.empty(); return; }
        const catSet = _catSetForPart(pid, files);
        let html = '';
        reqCats.forEach(cid => {
            const c = getCatById(cid);
            if (!c) return;
            html += catSet.has(Number(cid))
                ? chip('✓ ' + escapeHtml(c.category_name), 'ok')
                : chip('✕ ' + escapeHtml(c.category_name), 'lack');
        });
        catSet.forEach(cid => {
            if (reqCats.some(r => Number(r) === Number(cid))) return;
            const c = getCatById(cid);
            if (c) html += chip(escapeHtml(c.category_name), 'other');
        });
        $box.html(html);
    });
}

// 取得各料號缺少的必備附件類別 [{pid, names:[...]}]
function getMissingRequiredAttach() {
    const reqCats = effectiveRequiredCats();
    if (!reqCats.length) return [];
    const files = collectAttachMeta();
    const missing = [];
    getQuoteParts().forEach(pid => {
        const catSet = _catSetForPart(pid, files);
        const lack = reqCats
            .filter(cid => !catSet.has(Number(cid)))
            .map(cid => (getCatById(cid) || {}).category_name)
            .filter(Boolean);
        if (lack.length) missing.push({ pid, names: lack });
    });
    return missing;
}

// 套用「本頁適用類別」設定：fileCategories = 依 pageAttachCatIds 篩選排序後的清單
function applyPageCatScope() {
    if (Array.isArray(pageAttachCatIds) && pageAttachCatIds.length) {
        fileCategories = pageAttachCatIds
            .map(id => allFileCategories.find(c => Number(c.id) === Number(id)))
            .filter(Boolean);
    } else {
        fileCategories = allFileCategories.slice();
    }
}
// 依 ID 查類別（名稱顯示用主檔查找，不受本頁範圍影響）
function getCatById(id) {
    return allFileCategories.find(c => Number(c.id) === Number(id))
        || fileCategories.find(c => Number(c.id) === Number(id)) || null;
}
// 有效必備類別 = 必備設定 ∩ 本頁適用類別（避免被移出本頁的類別卡住儲存）
function effectiveRequiredCats() {
    return requiredAttachCats.filter(cid => fileCategories.some(c => Number(c.id) === Number(cid)));
}
// 載入本頁適用類別設定
function loadPageAttachCats() {
    $.get(API_URL, { action: 'get_param', param_group: 'QUOTATION', param_key: 'page_attach_cats' }, res => {
        let v = res.success ? res.value : null;
        if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { v = null; } }
        pageAttachCatIds = (Array.isArray(v) && v.length) ? v.map(Number).filter(Boolean) : null;
        applyPageCatScope();
        refreshPartAttachBadges();
    });
}

// 載入必備附件類別設定
function loadRequiredAttachCats() {
    $.get(API_URL, { action: 'get_param', param_group: 'QUOTATION', param_key: 'required_attach_cats' }, res => {
        let v = res.success ? res.value : null;
        if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { v = null; } }
        requiredAttachCats = Array.isArray(v) ? v.map(Number).filter(Boolean) : [];
        refreshPartAttachBadges();
    });
}

// 設定頁：渲染必備類別切換按鈕
function renderRequiredCatsSetting() {
    const $box = $('#qs-required-cats');
    if (!$box.length) return;
    if (!fileCategories.length) { $box.html('<span class="text-muted" style="font-size:12px;">尚無啟用中的附件類別</span>'); return; }
    $box.html(fileCategories.map(c => {
        const active = requiredAttachCats.some(r => Number(r) === Number(c.id));
        return `<button type="button" class="btn btn-xs qs-req-cat-btn ${active ? 'btn-danger' : 'btn-default'}"
            data-cat-id="${c.id}" style="margin:0 4px 4px 0;">
            ${active ? '<i class="fa fa-asterisk"></i> ' : ''}${escapeHtml(c.category_name)}</button>`;
    }).join(''));
}
$(document).on('click', '.qs-req-cat-btn', function () {
    const id = Number($(this).data('cat-id'));
    if (requiredAttachCats.some(r => Number(r) === id)) {
        requiredAttachCats = requiredAttachCats.filter(r => Number(r) !== id);
    } else {
        requiredAttachCats.push(id);
    }
    renderRequiredCatsSetting();
});
// 設定頁：本頁適用類別與排序（勾選＋拖曳排序）
function renderPageCatSetting() {
    const $box = $('#qs-page-cats');
    if (!$box.length) return;
    if (!allFileCategories.length) {
        $box.html('<span class="text-muted" style="font-size:12px;">尚無啟用中的附件類別</span>');
        return;
    }
    const ordered = [];
    if (Array.isArray(pageAttachCatIds) && pageAttachCatIds.length) {
        pageAttachCatIds.forEach(id => {
            const c = allFileCategories.find(c => Number(c.id) === Number(id));
            if (c) ordered.push({ c, on: true });
        });
        allFileCategories.forEach(c => {
            if (!pageAttachCatIds.some(id => Number(id) === Number(c.id))) ordered.push({ c, on: false });
        });
    } else {
        allFileCategories.forEach(c => ordered.push({ c, on: true }));
    }
    $box.html(ordered.map(o => `
        <div class="qs-page-cat-row" data-cat-id="${o.c.id}" draggable="false"
            style="display:flex;align-items:center;gap:6px;padding:3px 8px;border:1px solid #eee;border-radius:3px;margin-bottom:3px;background:#fff;">
            <span class="qs-pc-drag" style="cursor:grab;color:#bbb;">&#9776;</span>
            <label style="margin:0;font-weight:400;cursor:pointer;flex:1;font-size:12px;">
                <input type="checkbox" class="qs-pc-check" ${o.on ? 'checked' : ''} style="margin-right:5px;">${escapeHtml(o.c.category_name)}
            </label>
        </div>`).join(''));
    initPageCatDrag();
}
function initPageCatDrag() {
    const box = document.getElementById('qs-page-cats');
    if (!box) return;
    let dragging = null;
    box.querySelectorAll('.qs-page-cat-row').forEach(row => {
        const handle = row.querySelector('.qs-pc-drag');
        if (!handle) return;
        handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));
        row.addEventListener('dragend',   () => { row.setAttribute('draggable', 'false'); $(row).css('opacity', ''); dragging = null; });
        row.addEventListener('dragstart', e => { dragging = row; e.dataTransfer.effectAllowed = 'move'; setTimeout(() => $(row).css('opacity', '0.4'), 0); });
        row.addEventListener('dragover',  e => {
            e.preventDefault();
            if (!dragging || dragging === row) return;
            const rect = row.getBoundingClientRect();
            if (e.clientY < rect.top + rect.height / 2) box.insertBefore(dragging, row);
            else box.insertBefore(dragging, row.nextSibling);
        });
        row.addEventListener('drop', e => e.preventDefault());
    });
}
function savePageAttachCats() {
    const ids = [];
    $('#qs-page-cats .qs-page-cat-row').each(function () {
        if ($(this).find('.qs-pc-check').is(':checked')) ids.push(Number($(this).data('cat-id')));
    });
    $.post(API_URL, {
        action: 'save_param', param_group: 'QUOTATION', param_key: 'page_attach_cats',
        param_value: JSON.stringify(ids), description: '報價單頁適用附件類別與排序'
    }, res => {
        if (res.success) {
            pageAttachCatIds = ids.length ? ids : null;
            applyPageCatScope();
            renderRequiredCatsSetting();
            renderPageCatSetting();
            refreshPartAttachBadges();
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '本頁類別設定已儲存', showConfirmButton: false, timer: 2000 });
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}
function saveRequiredCats() {
    $.post(API_URL, {
        action: 'save_param', param_group: 'QUOTATION', param_key: 'required_attach_cats',
        param_value: JSON.stringify(requiredAttachCats.map(Number)), description: '每個料號必備的附件類別ID'
    }, res => {
        if (res.success) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '必備附件設定已儲存', showConfirmButton: false, timer: 2000 });
            refreshPartAttachBadges();
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}

// ══════════════════════════════════════════════════════
// 客戶 autocomplete
// ══════════════════════════════════════════════════════
$(document).on('input', '#client_name', function () {
    const term = $(this).val();
    // 手動輸入時解除綁定標記，避免舊 client_id 與新輸入文字不一致
    $('#client_id').val('');
    updateClientBoundCheck();
    $('#client-contact-row').hide();
    if (term.length < 1) { $('#client-suggestions').hide().empty(); return; }
    $.get(API_URL, { action: 'search_data', type: 'customer', term }, res => {
        const createBtnHtml = `<div class="client-create-btn" data-term="${escapeHtml(term)}"
            style="padding:6px 12px;cursor:pointer;color:#2c7be5;font-size:12px;border-top:1px dashed #ddd;background:#f7faff;">
            <i class="fa fa-plus-circle"></i> 建立新客戶「${escapeHtml(term)}」</div>`;
        if (res.success && res.data.length > 0) {
            let html = '';
            res.data.forEach(c => {
                html += `<div class="suggestion-item" data-id="${escapeHtml(c.customer_id)}" data-name="${escapeHtml(c.customer)}">
                    ${escapeHtml(c.customer_id)} - ${escapeHtml(c.customer)}</div>`;
            });
            const hasExact = res.data.some(c =>
                String(c.customer_id).toLowerCase() === term.toLowerCase() ||
                String(c.customer).toLowerCase() === term.toLowerCase());
            if (!hasExact) html += createBtnHtml;
            $('#client-suggestions').html(html).show();
        } else {
            $('#client-suggestions').html(createBtnHtml).show();
        }
    });
});
$(document).on('click', '#client-suggestions .suggestion-item', function () {
    $('#client_name').val($(this).data('name'));
    $('#client_id').val($(this).data('id'));
    $('#client-suggestions').hide().empty();
    updatePartSearchPlaceholders();
    loadClientContacts($(this).data('id'));
    updateClientBoundCheck();
});
// ── 快速建立客戶並綁定回表頭 ──────────────────────
let _pendingClientBind = false;   // 從「建立新客戶」進入時記錄，儲存後自動綁定表頭
$(document).on('click', '#client-suggestions .client-create-btn', function () {
    const term = String($(this).data('term') || '');
    $('#client-suggestions').hide().empty();
    resetCustomerForm();
    $('#customer_name_modal').val(term);
    _pendingClientBind = true;
    $('#customerMgmtListSection').hide();
    $('#customerModal').modal('show');
});
$(document).on('hidden.bs.modal', '#customerModal', function () {
    _pendingClientBind = false;
    $('#customerMgmtListSection').show();
});

// ══════════════════════════════════════════════════════
// 料號 autocomplete（含客戶篩選）
// ══════════════════════════════════════════════════════

// 客戶變更時更新所有料號搜尋框的 placeholder
// 載入客戶聯絡人並渲染下拉（預設聯絡人排第一）
function loadClientContacts(customerId, selectedContactId) {
    if (!customerId) {
        $('#client-contact-row').hide();
        $('#contact_select').empty();
        $('#contact_id').val('');
        return;
    }
    $.get(API_URL, { action:'get_customer_contacts', customer_id:customerId }, res => {
        if (!res.success || !res.contacts.length) {
            $('#client-contact-row').hide();
            $('#contact_select').empty();
            $('#contact_id').val('');
            return;
        }
        let html = '';
        res.contacts.forEach(c => {
            const parts = [c.name];
            if (c.department) parts.push(c.department);
            if (c.title) parts.push(c.title);
            if (c.phone_ext) parts.push('分機 ' + c.phone_ext);
            html += `<option value="${c.contact_id}">${escapeHtml(parts.join('・'))}</option>`;
        });
        $('#contact_select').html(html);
        // 回填指定聯絡人
        if (selectedContactId) $('#contact_select').val(selectedContactId);
        else {
            const primary = res.contacts.find(c => c.is_primary == 1);
            if (primary) $('#contact_select').val(primary.contact_id);
        }
        $('#contact_id').val($('#contact_select').val());
        // 單一或多位聯絡人都顯示
        $('#client-contact-row').show();
        if (res.contacts.length === 1) {
            // 單一聯絡人：disable select（只顯示不可更改）
            $('#client-contact-row').show();
            $('#contact_id').val(res.contacts[0].contact_id);
            $('#contact_select').prop('disabled', true);
        } else {
            $('#contact_select').prop('disabled', false);
        }
    });
}

function updatePartSearchPlaceholders() {
    const name = $('#client_name').val().trim();
    const hint = name ? `搜尋料號（已篩選：${name}）` : '搜尋料號...';
    $('.part-search').attr('placeholder', hint);
}

// 客戶選定後，立即更新 placeholder 和標題客戶名稱
$(document).on('change input', '#client_name', function () {
    updatePartSearchPlaceholders();
    updateEditorClientTag();
});

function updateEditorClientTag() {
    const name = $('#client_name').val().trim();
    $('#editorClientNameTag').text(name ? '・' + name : '');
}

$(document).on('input', '.part-search', function () {
    const $input   = $(this);
    const $td      = $input.closest('td');
    const $sug     = $td.find('.part-suggestions');
    const term     = $input.val();
    // 手動輸入時解除綁定標記
    $td.find('.d_setting_d_id_hidden').val('');
    $td.find('.part-cog-btn').data('d-id', '');
    $td.find('.part-bound-check').hide();
    refreshPartAttachBadges();
    if (term.length < 1) { $sug.hide().empty(); return; }

    const params   = { action: 'search_data', type: 'part', term };
    const clientId = $('#client_id').val().trim();
    if (clientId) params.customer_id = clientId;   // ★ 有客戶時加篩選

    $.get(API_URL, params, res => {
        // 「建立新料號」快捷列（查無結果或無完全相符時出現）
        const createBtnHtml = `<div class="part-create-btn" data-term="${escapeHtml(term)}"
            style="padding:6px 12px;cursor:pointer;color:#2c7be5;font-size:12px;border-top:1px dashed #ddd;background:#f7faff;">
            <i class="fa fa-plus-circle"></i> 建立新料號「${escapeHtml(term)}」</div>`;
        const positionSug = () => {
            const r   = $input[0].getBoundingClientRect();
            const blw = window.innerHeight - r.bottom;
            $sug.css({
                left:   r.left + 'px',
                width:  Math.max(r.width, clientId ? 220 : 300) + 'px',
                top:    blw < 200 && r.top > 200 ? 'auto'                      : r.bottom + 'px',
                bottom: blw < 200 && r.top > 200 ? (window.innerHeight - r.top) + 'px' : 'auto'
            }).show();
        };
        if (res.success && res.data.length > 0) {
            let html = '';
            // 有客戶篩選時：header 提示 + 不重複顯示客戶名稱
            if (clientId) {
                html += `<div style="padding:4px 10px 4px;background:#e8f8f0;font-size:11px;color:#1e8449;border-bottom:1px solid #a9dfbf;">
                    <i class="fa fa-filter"></i> 篩選：${escapeHtml($('#client_name').val())}
                </div>`;
            }
            res.data.forEach(p => {
                // 有客戶篩選時只顯示料號+規格；無篩選時也顯示客戶名稱
                const label = clientId
                    ? `${escapeHtml(p.D_Setting_Id)}　<small style="color:#aaa;">${escapeHtml(p.Spec_No||'')}</small>`
                    : `${escapeHtml(p.D_Setting_Id)} <small style="color:#aaa;">(${escapeHtml(p.Spec_No||'N/A')})</small> — ${escapeHtml(p.Client_Name||'無客戶')}`;
                html += `<div class="suggestion-item"
                    data-part-id="${escapeHtml(p.D_Setting_Id)}"
                    data-d-id="${escapeHtml(p.d_id||'')}"
                    data-spec-no="${escapeHtml(p.Spec_No||'')}"
                    data-client-name="${escapeHtml(p.Client_Name||'')}"
                    data-customer-id="${escapeHtml(p.customer_id||'')}">
                    ${label}
                </div>`;
            });
            // 無完全相符時，底部提供快速建立
            const hasExact = res.data.some(p => String(p.D_Setting_Id).toLowerCase() === term.toLowerCase());
            if (!hasExact) html += createBtnHtml;
            $sug.html(html);
            positionSug();
        } else {
            const noResultMsg = (clientId
                ? `<div style="padding:8px 12px;color:#aaa;font-size:12px;">
                       <i class="fa fa-search"></i> 此客戶下找不到符合料號
                       <a href="#" style="margin-left:6px;font-size:11px;" onclick="clearClientFilter(event)">清除篩選</a>
                   </div>`
                : `<div style="padding:8px 12px;color:#aaa;font-size:12px;">
                       <i class="fa fa-search"></i> 查無此料號
                   </div>`) + createBtnHtml;
            $sug.html(noResultMsg);
            positionSug();
        }
    });
});

// ── 快速建立料號並綁定回原項目列 ──────────────────────
let _pendingBindRow = null;   // 待綁定的項目列（從「建立新料號」進入時記錄）
$(document).on('click', '.part-suggestions .part-create-btn', function () {
    const term = String($(this).data('term') || '');
    _pendingBindRow = $(this).closest('tr.item-row');
    $(this).closest('.part-suggestions').hide().empty();
    resetPartForm();
    $('#part_no_modal').val(term);
    // 表頭已綁定客戶時自動帶入
    const cid = $('#client_id').val().trim();
    if (cid) {
        $('#part_client_search_modal').val($('#client_name').val().trim());
        $('#part_customer_id_modal').val(cid);
    }
    $('#partModal').modal('show');
});
$(document).on('hidden.bs.modal', '#partModal', function () { _pendingBindRow = null; });

// 建立成功後綁定料號到項目列（等同從建議清單點選）
function bindPartToRow($tr, partId, dId) {
    if (!$tr || !$tr.length) return;
    $tr.find('.part-search').val(partId).attr('title', partId);
    $tr.find('.product_id_hidden').val(partId);
    $tr.find('.d_setting_d_id_hidden').val(dId);
    $tr.find('.part-cog-btn').data('d-id', dId);
    $tr.find('.part-bound-check').show();
    loadItemHistory($tr, partId);
    loadGearSpecs($tr, null, dId);
    loadBomInfo($tr, null, dId);
    refreshPartAttachBadges();
}

// 清除客戶篩選（從「找不到料號」的快捷連結觸發）
function clearClientFilter(e) {
    e.preventDefault();
    $('#client_name').val('');
    $('#client_id').val('');
    updatePartSearchPlaceholders();
    updateClientBoundCheck();
    $('.part-suggestions').hide().empty();
    // 重新觸發輸入以無篩選模式搜尋
    $('.part-search:focus').trigger('input');
}

$(document).on('click', '.part-suggestions .suggestion-item', function () {
    const $item  = $(this);
    const partId = $item.data('part-id');
    const $tr    = $item.closest('tr.item-row');
    $tr.find('.part-search').val(partId).attr('title', partId);
    $tr.find('.product_id_hidden').val(partId);
    const dId = $item.data('d-id') || '';
    $tr.find('.d_setting_d_id_hidden').val(dId);
    $tr.find('.part-cog-btn').data('d-id', dId);
    // 顯示/隱藏綁定標記
    if (dId) { $tr.find('.part-bound-check').show(); }
    else      { $tr.find('.part-bound-check').hide(); }

    // 客戶欄位空白時自動帶入此料號的預設客戶
    if (!$('#client_name').val().trim() && $item.data('client-name')) {
        $('#client_name').val($item.data('client-name'));
        $('#client_id').val($item.data('customer-id'));
        updatePartSearchPlaceholders();
        updateEditorClientTag();
        loadClientContacts($item.data('customer-id'));
        updateClientBoundCheck();
    }

    $item.closest('.part-suggestions').hide().empty();
    loadItemHistory($tr, partId);
    // 自動帶入齒輪規格
    loadGearSpecs($tr, dId ? null : partId, dId || null);
    // 更換料號時重置子件勾選再重新載入組合件資訊
    $tr.find('.show-bom-hidden').val(0);
    $tr.find('.print-bom-hidden').val(0);
    loadBomInfo($tr, dId ? null : partId, dId || null);
    refreshPartAttachBadges();
});

// ══════════════════════════════════════════════════════
// 客戶管理
// ══════════════════════════════════════════════════════
function updateClientBoundCheck() {
    if ($('#client_id').val().trim()) {
        $('#client-bound-check').show();
    } else {
        $('#client-bound-check').hide();
    }
}
function openCustomerGear() {
    const clientId = $('#client_id').val().trim();
    if (clientId) {
        // 帶 customer_search 讓主檔頁客戶分頁同步篩選此客戶
        const kw = $('#client_name').val().trim() || clientId;
        window.open('../pages/master_data_management.php?customer_search=' + encodeURIComponent(kw)
            + '#edit-customer-' + encodeURIComponent(clientId), '_blank');
    } else {
        window.open('../pages/master_data_management.php#open-customer', '_blank');
    }
}
function openCustomerModal() { window.open('../pages/master_data_management.php#open-customer', '_blank'); }
function loadCustomerList() {
    $.get(API_URL, { action: 'get_all_customers' }, res => {
        if (!res.success) return;
        let html = '';
        res.data.forEach(c => {
            html += `<tr><td>${escapeHtml(c.customer_id)}</td><td>${escapeHtml(c.customer)}</td>
            <td>${escapeHtml(c.customer_tel||'')}</td><td>${escapeHtml(c.customer_address||'')}</td>
            <td><button class="btn btn-xs btn-warning btn-edit-customer"
                data-customer='${JSON.stringify(c).replace(/'/g,"&#39;")}'>
                <i class="fa fa-pencil"></i></button></td></tr>`;
        });
        $('#customerMgmtTable tbody').html(html);
    });
}
function editCustomer(c) {
    $('#customerFormTitle').text('修改客戶');
    $('#customer_id_modal').val(c.customer_id);
    $('#customer_id_new').val(c.customer_id).prop('disabled', true);
    $('#customer_name_modal').val(c.customer);
    $('#customer_address_modal').val(c.customer_address||'');
    $('#customer_tel_modal').val(c.customer_tel||'');
    $('#customer_fax_modal').val(c.customer_fax||'');
    $('#customer_taxid_modal').val(c.tax_id||'');
    $('#customer_contact_modal').val('');
}
function resetCustomerForm() {
    $('#customerFormTitle').text('新增客戶');
    $('#customer_id_modal').val('');
    $('#customer_id_new').val('').prop('disabled', false);
    $('#customer_name_modal,#customer_address_modal,#customer_tel_modal,#customer_fax_modal,#customer_taxid_modal,#customer_contact_modal').val('');
}
function saveCustomer() {
    const name = $('#customer_name_modal').val().trim();
    if (!name) { Swal.fire('錯誤', '客戶名稱不可為空', 'error'); return; }
    if (!$('#customer_id_modal').val() && !$('#customer_id_new').val().trim()) {
        Swal.fire('錯誤', '客戶代碼不可為空', 'error'); return;
    }
    $.post(API_URL, {
        action:'save_customer',
        customer_id_modal:    $('#customer_id_modal').val(),
        customer_id_new:      $('#customer_id_new').val(),
        customer_name_modal:  name,
        customer_address_modal:$('#customer_address_modal').val(),
        customer_tel_modal:   $('#customer_tel_modal').val(),
        customer_fax_modal:   $('#customer_fax_modal').val(),
        customer_taxid_modal: $('#customer_taxid_modal').val(),
        customer_contact_modal:$('#customer_contact_modal').val()
    }, res => {
        if (res.success) {
            // 從「建立新客戶」進入：直接綁定回表頭並關閉跳窗
            if (_pendingClientBind && res.customer_id) {
                _pendingClientBind = false;
                $('#client_name').val(name);
                $('#client_id').val(res.customer_id);
                updatePartSearchPlaceholders();
                updateEditorClientTag();
                updateClientBoundCheck();
                loadClientContacts(res.customer_id);
                resetCustomerForm();
                $('#customerModal').modal('hide');
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `客戶 ${res.customer_id} 已建立並綁定`, showConfirmButton: false, timer: 2500 });
                return;
            }
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2000 });
            resetCustomerForm(); loadCustomerList();
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}

// ══════════════════════════════════════════════════════
// 料號管理
// ══════════════════════════════════════════════════════
function openPartModal() { window.open('../pages/master_data_management.php#open-part', '_blank'); }
function openPartModalForRow(btn) {
    const dId = $(btn).data('d-id');
    if (dId) {
        // 帶 part_search 讓主檔頁料號分頁同步篩選此料號
        const partNo = $(btn).closest('tr.item-row').find('.part-search').val().trim();
        const q = partNo ? '?part_search=' + encodeURIComponent(partNo) : '';
        window.open('../pages/master_data_management.php' + q + '#edit-part-' + encodeURIComponent(dId), '_blank');
    } else {
        window.open('../pages/master_data_management.php#open-part', '_blank');
    }
}

// ══════════════════════════════════════════════════════
// 批次新增料號
// ══════════════════════════════════════════════════════
let _batchSelected = {};  // { D_Setting_Id: partData }

function openBatchAddModal() {
    _batchSelected = {};
    $('#batchPartSearch').val('');
    $('#batchPartResults').html('<div class="text-muted text-center" style="padding:20px;">請輸入關鍵字搜尋料號</div>');
    $('#batchSelectedCount').text(0);
    $('#batchAddModal').modal('show');
    setTimeout(() => $('#batchPartSearch').focus(), 400);
}

function doBatchPartSearch() {
    const term = $('#batchPartSearch').val().trim();
    if (!term) return;
    const params = { action: 'search_data', type: 'part', term };
    const clientId = $('#client_id').val().trim();
    if (clientId) params.customer_id = clientId;
    $.get(API_URL, params, res => {
        if (!res.success || !res.data.length) {
            $('#batchPartResults').html('<div class="text-muted text-center" style="padding:20px;">查無符合料號</div>');
            return;
        }
        let html = '';
        res.data.forEach(p => {
            const isChecked = !!_batchSelected[p.D_Setting_Id];
            const label = clientId
                ? `${escapeHtml(p.D_Setting_Id)}　<small style="color:#aaa;">${escapeHtml(p.Spec_No||'')}</small>`
                : `${escapeHtml(p.D_Setting_Id)} <small style="color:#aaa;">(${escapeHtml(p.Spec_No||'N/A')})</small> — ${escapeHtml(p.Client_Name||'無客戶')}`;
            html += `<label style="display:flex;align-items:center;gap:8px;padding:6px 10px;border-bottom:1px solid #f0f0f0;cursor:pointer;margin:0;"
                class="batch-part-item ${isChecked ? 'bg-info' : ''}"
                data-part-id="${escapeHtml(p.D_Setting_Id)}"
                data-d-id="${escapeHtml(p.d_id||'')}"
                data-spec-no="${escapeHtml(p.Spec_No||'')}"
                data-client-name="${escapeHtml(p.Client_Name||'')}">
                <input type="checkbox" class="batch-part-chk" value="${escapeHtml(p.D_Setting_Id)}" ${isChecked ? 'checked' : ''}
                    style="width:15px;height:15px;flex-shrink:0;">
                <span>${label}</span>
            </label>`;
        });
        $('#batchPartResults').html(html);
        $('#batchPartResults').off('change.batch').on('change.batch', '.batch-part-chk', function() {
            const $lbl = $(this).closest('.batch-part-item');
            const pid  = $lbl.data('part-id');
            if ($(this).is(':checked')) {
                _batchSelected[pid] = {
                    product_id: pid,
                    d_id:       $lbl.data('d-id'),
                    spec_no:    $lbl.data('spec-no'),
                };
                $lbl.css('background', '#e8f0ff');
            } else {
                delete _batchSelected[pid];
                $lbl.css('background', '');
            }
            $('#batchSelectedCount').text(Object.keys(_batchSelected).length);
        });
    });
}

$(document).on('keydown', '#batchPartSearch', function(e) {
    if (e.key === 'Enter') doBatchPartSearch();
});

function confirmBatchAdd() {
    const parts = Object.values(_batchSelected);
    if (!parts.length) { Swal.fire({ toast:true, position:'top-end', icon:'warning', title:'尚未勾選任何料號', showConfirmButton:false, timer:2000 }); return; }
    // 上限控管：只加得下的筆數，超出部分提示未加入
    const room = MAX_QUOTE_ITEMS - $('#quoteItemsTable > tbody > tr.item-row').length;
    if (room <= 0) { Swal.fire('提示', `報價項目最多 ${MAX_QUOTE_ITEMS} 筆料號`, 'warning'); return; }
    const toAdd = parts.slice(0, room);
    toAdd.forEach(p => addItemRow({ product_id: p.product_id }));
    $('#batchAddModal').modal('hide');
    if (parts.length > toAdd.length) {
        Swal.fire('提示', `報價項目最多 ${MAX_QUOTE_ITEMS} 筆料號：已新增前 ${toAdd.length} 筆，其餘 ${parts.length - toAdd.length} 筆未加入`, 'warning');
    } else {
        Swal.fire({ toast:true, position:'top-end', icon:'success', title:`已新增 ${toAdd.length} 筆料號`, showConfirmButton:false, timer:2000 });
    }
}

function searchPartMgmt() {
    const term = $('#partMgmtSearch').val();
    $.get(API_URL, { action: 'search_data', type: 'part', term }, res => {
        if (!res.success) return;
        const typeMap = { N:'一般', G:'齒輪', H:'滾刀' };
        let html = '';
        res.data.forEach(p => {
            html += `<tr style="cursor:pointer;" onclick="loadPartToModal('${p.d_id}')">
                <td>${escapeHtml(p.D_Setting_Id)}</td><td>${escapeHtml(p.Client_Name||'')}</td>
                <td>${escapeHtml(p.Revision||'')}</td><td>${escapeHtml(typeMap[p.Type]||p.Type||'一般')}</td>
                <td><button class="btn btn-xs btn-warning btn-edit-part"
                    data-part='${JSON.stringify(p).replace(/'/g,"&#39;")}'>
                    <i class="fa fa-pencil"></i></button></td></tr>`;
        });
        $('#partMgmtTbody').html(html || '<tr><td colspan="5" class="text-center text-muted">無資料</td></tr>');
    });
}
function loadPartToModal(d_id) {
    $.get(API_URL, { action: 'get_part_detail', d_id }, res => {
        if (!res.success) { Swal.fire('錯誤', res.message, 'error'); return; }
        const p = res.data;
        $('#partFormTitle').text('修改料號');
        $('#part_d_id_modal').val(p.d_id);
        $('#part_no_modal').val(p.D_Setting_Id||'');
        $('#part_type_modal').val(p.Type||'N').trigger('change');
        $('#part_client_search_modal').val(p.Client_Name||'');
        $('#part_customer_id_modal').val(p.Customer_Id||'');
        $('#part_revision_modal').val(p.Revision||'');
        $('#part_issue_date_modal').val(p.Issue_Date||'');
        $('#part_remark_modal').val(p.Remark||'');
        $('#part-btn-delete').show();
        $('#part-gear-rows-container').empty();
        if (p.Type === 'G' && p.gears && p.gears.length > 0) p.gears.forEach(g => addPartGearRow(g));
        $('#partModal .modal-body').scrollTop(0);
    });
}
function editPart(d_id) { loadPartToModal(d_id); }
function resetPartForm() {
    $('#partFormTitle').text('新增料號');
    $('#part_d_id_modal,#part_no_modal,#part_revision_modal,#part_issue_date_modal,#part_remark_modal').val('');
    $('#part_type_modal').val('N').trigger('change');
    $('#part_client_search_modal').val('');
    $('#part_customer_id_modal').val('');
    $('#part-btn-delete').hide();
    $('#part-gear-rows-container').empty();
    $('#part-customer-results').hide();
}
function savePart() {
    const partNo = $('#part_no_modal').val().trim();
    if (!partNo) { Swal.fire('錯誤', '料號不可為空', 'error'); return; }
    if ($('#part_client_search_modal').val().trim() && !$('#part_customer_id_modal').val().trim()) {
        Swal.fire('錯誤', '請從建議列表選擇客戶，或清空客戶欄位', 'error'); return;
    }
    const gears = [];
    if ($('#part_type_modal').val() === 'G') {
        $('#part-gear-rows-container .part-gear-row').each(function () {
            gears.push({
                Gear_Type:        $(this).find('.gear-type').val(),
                Module:           $(this).find('.gear-module').val(),
                Teeth:            $(this).find('.gear-teeth').val(),
                Pressure_Angle:   $(this).find('.gear-pressure-angle').val(),
                Face_Width:       $(this).find('.gear-face-width').val(),
                Workpiece_Length: $(this).find('.gear-length').val(),
                Profile_Shift_X:  $(this).find('.gear-shift-x').val(),
                Helix_Angle:      $(this).find('.hidden-helix-val').val(),
                Helix_Angle_Str:  $(this).find('.hidden-helix-str').val(),
                Helix_Direction:  $(this).find('.gear-direction').val(),
                Remark_Gear:      $(this).find('.gear-remark').val()
            });
        });
    }
    $.post(API_URL, {
        action: 'save_part_info', d_id: $('#part_d_id_modal').val(), part_no: partNo,
        type: $('#part_type_modal').val(), customer_id: $('#part_customer_id_modal').val(),
        revision: $('#part_revision_modal').val(), issue_date: $('#part_issue_date_modal').val(),
        remark: $('#part_remark_modal').val(), gears: JSON.stringify(gears)
    }, res => {
        if (res.success) {
            // 從「建立新料號」進入：直接綁定回原項目列並關閉跳窗
            if (_pendingBindRow && res.d_id) {
                const $tr = _pendingBindRow;
                _pendingBindRow = null;
                bindPartToRow($tr, partNo, res.d_id);
                resetPartForm();
                $('#partModal').modal('hide');
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `料號 ${partNo} 已建立並綁定`, showConfirmButton: false, timer: 2500 });
                return;
            }
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2000 });
            resetPartForm(); searchPartMgmt();
        } else { Swal.fire('錯誤', res.message, 'error'); }
    });
}
function deletePart() {
    const d_id = $('#part_d_id_modal').val();
    if (!d_id) return;
    Swal.fire({ title: '確定要刪除此料號嗎？', icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#d33', confirmButtonText: '是的，刪除', cancelButtonText: '取消'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(API_URL, { action: 'delete_part', d_id }, res => {
            if (res.success) {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: '刪除成功', showConfirmButton: false, timer: 2000 });
                resetPartForm(); searchPartMgmt();
            } else { Swal.fire('錯誤', res.message, 'error'); }
        });
    });
}
function addPartGearRow(data = {}) {
    const gearType  = data.Gear_Type || '';
    const module    = data.Module || '';
    const teeth     = data.Teeth || '';
    const pa        = data.Pressure_Angle || '';
    const width     = data.Face_Width || '';
    const length    = data.Workpiece_Length || '';
    const remark    = data.Remark_Gear || '';
    const helixVal  = (data.Helix_Angle !== undefined && data.Helix_Angle !== null && data.Helix_Angle !== '') ? parseFloat(data.Helix_Angle) : '';
    const helixStr  = data.Helix_Angle_Str || '';
    const direction = data.Helix_Direction || '';
    const shiftX    = (data.Profile_Shift_X !== undefined && data.Profile_Shift_X !== null) ? parseFloat(data.Profile_Shift_X) : '';
    const showHelix = String(gearType).includes('螺旋');
    const html = `
    <div class="part-gear-row" style="padding:12px;border:1px solid #ddd;border-radius:5px;margin-bottom:10px;background:#f9f9f9;">
      <div class="row">
        <div class="col-md-3 form-group"><label>齒輪類型</label>
          <select class="form-control input-sm gear-type">
            <option value="" ${gearType===''?'selected':''}>請選擇</option>
            <option value="直齒" ${gearType==='直齒'?'selected':''}>直齒</option>
            <option value="螺旋" ${gearType==='螺旋'?'selected':''}>螺旋</option>
            <option value="傘齒" ${gearType==='傘齒'?'selected':''}>傘齒</option>
            <option value="蝸桿" ${gearType==='蝸桿'?'selected':''}>蝸桿</option>
            <option value="蝸輪" ${gearType==='蝸輪'?'selected':''}>蝸輪</option>
          </select>
        </div>
        <div class="col-md-3 form-group"><label>模數</label>
          <input type="text" class="form-control input-sm gear-module" value="${escapeHtml(String(module))}">
        </div>
        <div class="col-md-3 form-group"><label>齒數</label>
          <input type="number" class="form-control input-sm gear-teeth" value="${escapeHtml(String(teeth))}">
        </div>
        <div class="col-md-3 form-group helix-angle-group" style="display:${showHelix?'block':'none'};background:#e9ecef;padding:8px;border-radius:4px;">
          <label>螺旋角</label>
          <div style="display:flex;gap:5px;margin-bottom:5px;">
            <select class="form-control input-sm gear-direction" style="width:75px;">
              <option value="" ${direction===''?'selected':''}>旋向</option>
              <option value="RH" ${direction==='RH'?'selected':''}>RH(右)</option>
              <option value="LH" ${direction==='LH'?'selected':''}>LH(左)</option>
            </select>
            <div class="btn-group btn-group-xs">
              <button type="button" class="btn btn-default active btn-mode-dec">十進位</button>
              <button type="button" class="btn btn-default btn-mode-dms">度分秒</button>
            </div>
          </div>
          <div class="mode-decimal">
            <input type="number" step="any" class="form-control input-sm gear-helix-val" value="${helixVal}" placeholder="例如 15.5">
          </div>
          <div class="mode-dms" style="display:none;align-items:center;gap:2px;">
            <input type="number" class="form-control input-sm dms-d" placeholder="度" style="width:50px;">°
            <input type="number" class="form-control input-sm dms-m" placeholder="分" style="width:50px;">'
            <input type="number" class="form-control input-sm dms-s" placeholder="秒" style="width:50px;">"
          </div>
          <input type="hidden" class="hidden-helix-val" value="${helixVal}">
          <input type="hidden" class="hidden-helix-str" value="${escapeHtml(helixStr)}">
        </div>
      </div>
      <div class="row">
        <div class="col-md-3 form-group"><label>壓力角 (PA)</label>
          <input type="text" class="form-control input-sm gear-pressure-angle" value="${escapeHtml(String(pa))}">
        </div>
        <div class="col-md-3 form-group"><label>齒寬 (W) mm</label>
          <input type="number" step="0.01" class="form-control input-sm gear-face-width" value="${escapeHtml(String(width))}">
        </div>
        <div class="col-md-3 form-group"><label>工件總長 (L) mm</label>
          <input type="number" step="0.01" class="form-control input-sm gear-length" value="${escapeHtml(String(length))}">
        </div>
        <div class="col-md-3 form-group"><label>轉位係數 X</label>
          <input type="number" step="any" class="form-control input-sm gear-shift-x" value="${shiftX}">
        </div>
      </div>
      <div class="row">
        <div class="col-md-9 form-group"><label>備註</label>
          <input type="text" class="form-control input-sm gear-remark" value="${escapeHtml(String(remark))}">
        </div>
        <div class="col-md-3 form-group" style="text-align:right;padding-top:25px;">
          <button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest('.part-gear-row').remove()">
            <i class="fa fa-trash"></i> 刪除
          </button>
        </div>
      </div>
    </div>`;
    $('#part-gear-rows-container').append(html);
    if (helixStr && (helixStr.includes('°') || helixStr.includes("'"))) {
        const $lr = $('#part-gear-rows-container .part-gear-row').last();
        $lr.find('.btn-mode-dms').trigger('click');
        const d = helixStr.split('°')[0] || '';
        const m = (helixStr.split('°')[1] || '').split("'")[0];
        const s = (helixStr.split("'")[1] || '').split('"')[0];
        $lr.find('.dms-d').val(d); $lr.find('.dms-m').val(m); $lr.find('.dms-s').val(s);
    }
}

// ══════════════════════════════════════════════════════════════════════════
// 整張報價單變更客戶（2026-08-28）
//   後端唯一實作在 src/common/quote_customer_lib.php；本區只負責畫面與確認流程。
//   前端擋一次、後端同規則再擋一次（鐵律8）——這裡按不到的東西，直接打 API 一樣會被擋。
// ══════════════════════════════════════════════════════════════════════════
let _chgCustScan = null;   // 最近一次掃描結果

function openChgCustomer() {
    if (!CAN_CHG_CUSTOMER) { Swal.fire('權限不足', '您沒有「整張單變更客戶」的權限', 'error'); return; }
    if (!currentEditId)    { Swal.fire('提示', '請先開啟一張報價單', 'info'); return; }
    _chgCustScan = null;
    $('#chgCustId').val('');
    $('#chgCustSearch').val('');
    $('#chgCustPick').hide().empty();
    $('#chgCustPicked').hide().empty();
    $('#chgCustApplyBtn, #chgCustCloneBtn').hide();
    $('#chgCustHint').text('');
    $('#chgCustScanWrap').html('<div class="text-muted text-center" style="padding:24px;">請先選擇要變更成哪一家客戶</div>');
    const q = window._lastPrintGateQuote || {};
    $('#chgCustQuoteNo').text(q.quote_no ? ('（' + q.quote_no + '）') : '');
    $('#chgCustFrom').text(q.client_name || '（未設定）');
    $('#chgCustomerModal').modal('show');
    setTimeout(() => $('#chgCustSearch').focus(), 300);
}

// 客戶挑選（沿用本頁既有的 search_data type=customer，不另外做一份客戶清單）
let _chgCustTimer = null;
$(document).on('input', '#chgCustSearch', function () {
    $('#chgCustId').val('');
    $('#chgCustPicked').hide().empty();
    $('#chgCustApplyBtn, #chgCustCloneBtn').hide();
    clearTimeout(_chgCustTimer);
    const term = $(this).val().trim();
    if (!term) { $('#chgCustPick').hide().empty(); return; }
    _chgCustTimer = setTimeout(() => chgCustRenderPick(term), 200);
});
function chgCustRenderPick(term) {
    term = (term !== undefined) ? term : $('#chgCustSearch').val().trim();
    if (!term) { $('#chgCustPick').hide().empty(); return; }
    $.get(API_URL, { action: 'search_data', type: 'customer', term }, res => {
        if (!res.success || !res.data.length) {
            $('#chgCustPick').html('<div class="text-muted" style="padding:8px 12px;">查無客戶</div>').show();
            return;
        }
        $('#chgCustPick').html(res.data.map(c =>
            `<div class="suggestion-item chg-cust-opt" data-id="${escapeHtml(c.customer_id)}" data-name="${escapeHtml(c.customer)}"
                  style="padding:6px 12px;cursor:pointer;border-bottom:1px solid #f2f2f2;">
                <strong>${escapeHtml(c.customer)}</strong> <span style="color:#999;">（${escapeHtml(c.customer_id)}）</span>
             </div>`).join('')).show();
    });
}
$(document).on('click', '.chg-cust-opt', function () {
    const id = String($(this).data('id')), name = String($(this).data('name'));
    $('#chgCustId').val(id);
    $('#chgCustSearch').val(name);
    $('#chgCustPick').hide().empty();
    $('#chgCustPicked').html(`<i class="fa fa-check-circle" style="color:#1e8449;"></i> 已選：<strong>${escapeHtml(name)}</strong>（${escapeHtml(id)}）`).show();
    chgCustScan();
});

function chgCustScan() {
    const cid = $('#chgCustId').val().trim();
    if (!currentEditId || !cid) return;
    $('#chgCustScanWrap').html('<div class="text-center" style="padding:24px;"><i class="fa fa-spinner fa-spin fa-2x" style="color:#8a5a2b;"></i><div style="margin-top:8px;color:#888;font-size:12px;">正在掃描每個料號目前被哪些單據使用…</div></div>');
    $.get(API_URL, { action: 'qcc_scan', quote_id: currentEditId, customer_id: cid }, res => {
        if (!res.success) { Swal.fire('錯誤', res.message || '掃描失敗', 'error'); return; }
        _chgCustScan = res.data;
        chgCustRenderScan(res.data);
    }).fail(() => Swal.fire('錯誤', '與伺服器通訊失敗', 'error'));
}

function chgCustVerdictBadge(v) {
    if (v === 'block')    return '<span style="display:inline-block;font-size:10px;padding:1px 7px;border-radius:10px;background:#fdecea;color:#c0392b;border:1px solid #f5b7b1;font-weight:700;">禁止直接改</span>';
    if (v === 'confirm')  return '<span style="display:inline-block;font-size:10px;padding:1px 7px;border-radius:10px;background:#FFF3E2;color:#a06a1f;border:1px solid #E4D3BC;font-weight:700;">需二次確認</span>';
    if (v === 'unbound')  return '<span style="display:inline-block;font-size:10px;padding:1px 7px;border-radius:10px;background:#f2f2f2;color:#888;border:1px solid #ddd;">未綁料號ID</span>';
    return '<span style="display:inline-block;font-size:10px;padding:1px 7px;border-radius:10px;background:#e8f8f0;color:#1e8449;border:1px solid #a9dfbf;font-weight:700;">可直接改</span>';
}
const _chgCustKindName = { quote:'報價單', order:'訂單', bom:'BOM', shipment:'出貨', return:'退貨' };

function chgCustRenderScan(d) {
    const orders = d.orders || [];
    let html = '';

    html += `<div style="font-size:12px;color:#666;margin-bottom:8px;">
        本張OP轉出／綁定的訂單：<strong>${orders.length}</strong> 筆
        ${orders.length ? '（' + orders.map(o => escapeHtml(o.Order_oo || '')).filter((v,i,a) => a.indexOf(v)===i).join('、') + '）' : ''}
        ${d.unbound ? `　<span style="color:#a06a1f;">另有 ${d.unbound} 個項目尚未綁定料號ID（不會被變更）</span>` : ''}
    </div>`;

    html += `<table class="table table-condensed table-bordered" style="font-size:12px;margin-bottom:6px;">
        <thead><tr style="background:#f7f7f7;">
            <th style="width:22%;">料號</th><th style="width:14%;">目前料號客戶</th>
            <th style="width:16%;">判定</th><th>目前還被誰用著</th>
        </tr></thead><tbody>`;
    const seen = {};
    (d.items || []).forEach(it => {
        if (it.d_id > 0 && seen[it.d_id]) return;      // 同一料號在同張單出現多列（不同數量級距）只列一次
        if (it.d_id > 0) seen[it.d_id] = 1;
        const u = it.usage || {};
        let use = '';
        if (it.already_target) {
            use = '<span style="color:#1e8449;">此料號已經是目標客戶，不需變更</span>';
        } else if (it.verdict === 'unbound') {
            use = '<span style="color:#888;">此項目沒有綁料號ID，只會改報價單表頭</span>';
        } else {
            const out = (u.outside || []);
            if (out.length) {
                const shown = out.slice(0, 6).map(o =>
                    `<div><span style="color:#c0392b;font-weight:600;">${_chgCustKindName[o.kind] || o.kind}</span> ${escapeHtml(o.label)}
                     <span style="color:#999;">${escapeHtml(o.detail || '')}</span></div>`).join('');
                use = shown + (out.length > 6 ? `<div style="color:#999;">…等共 ${out.length} 筆</div>` : '');
            } else {
                const ins = u.inside || {};
                const bits = [];
                if ((ins.orders || []).length) bits.push(`本張OP的訂單 ${ins.orders.length} 筆`);
                if ((ins.boms   || []).length) bits.push(`該訂單的 BOM ${ins.boms.length} 筆`);
                use = bits.length ? `<span style="color:#a06a1f;">${bits.join('、')}</span>`
                                  : '<span style="color:#1e8449;">只有本張報價單在用</span>';
            }
            (u.info || []).forEach(t => { use += `<div style="color:#999;font-size:11px;">${escapeHtml(t)}</div>`; });
        }
        html += `<tr>
            <td><strong>${escapeHtml(it.part_no || it.product_id || '')}</strong>
                ${it.d_id ? `<span style="color:#bbb;font-size:10px;"> ID ${it.d_id}</span>` : ''}</td>
            <td>${escapeHtml(it.part_customer_name || '—')}</td>
            <td>${it.already_target ? chgCustVerdictBadge('ok') : chgCustVerdictBadge(it.verdict)}</td>
            <td>${use}</td></tr>`;
    });
    html += '</tbody></table>';

    if (d.verdict === 'block') {
        html += `<div style="padding:10px 12px;background:#fdecea;border:1px solid #f5b7b1;border-radius:4px;font-size:12px;color:#8e2b20;line-height:1.7;">
            <strong><i class="fa fa-ban"></i> 有料號已經被本張OP以外的單據使用，不可直接變更料號客戶。</strong><br>
            直接改會把<strong>別張報價單／別的訂單／已出貨紀錄</strong>底下的料號一起換掉客戶。
            請改按下方「<strong>建立新料號並改綁</strong>」：系統會把本張單的每個料號各建一筆<strong>同料號、掛新客戶</strong>的新料號
            （新客戶底下已經有同料號時直接沿用那一筆），把本張報價單與本張OP轉出的訂單改綁到新料號，
            <strong>原本的料號完全不動</strong>。新料號會自動複製齒輪規格／組合件結構／料號標籤，並登記舊料號為別名；
            <strong>圖面等實體附件不會複製</strong>，請另行至主檔管理上傳或搬移。
        </div>`;
    } else if (d.verdict === 'confirm') {
        html += `<div style="padding:10px 12px;background:#FFF8ED;border:1px solid #E4D3BC;border-radius:4px;font-size:12px;color:#6b4a22;line-height:1.7;">
            <strong><i class="fa fa-exclamation-triangle"></i> 這些料號已經有本張OP轉出的訂單／BOM在使用。</strong>
            按「確認變更」會一併把那些訂單與 BOM 的客戶改成新客戶（這正是接單後客戶改名要的效果）。
            若您其實想保留原客戶的那批單據，請改用「建立新料號並改綁」。
        </div>`;
    } else {
        html += `<div style="padding:10px 12px;background:#e8f8f0;border:1px solid #a9dfbf;border-radius:4px;font-size:12px;color:#1e6b42;line-height:1.7;">
            <i class="fa fa-check-circle"></i> 這些料號目前只有本張報價單在用，可以直接變更。
        </div>`;
    }

    $('#chgCustScanWrap').html(html);
    $('#chgCustApplyBtn').toggle(d.verdict !== 'block');
    $('#chgCustCloneBtn').toggle(d.verdict !== 'ok');
    $('#chgCustHint').text(d.verdict === 'block' ? '禁止直接變更，請改用「建立新料號並改綁」' : '');
}

// 變更完成後重新載入左側清單並重開檢視畫面（沿用本頁既有的清單重載寫法，含跨年度快取失效）
function chgCustReloadList() {
    allYearsData = null;
    if (isAllYearsMode) loadAllYears(); else loadQuoteList(<?= $selectedYear ?>);
    if (currentEditId) openViewMode(currentEditId);
}

function chgCustSummaryHtml(r) {
    const li = [];
    li.push(`報價單表頭客戶：<strong>${escapeHtml(r.from_client || '（無）')}</strong> → <strong>${escapeHtml(r.to_client)}</strong>`);
    if ((r.parts_created  || []).length) li.push(`新建料號 <strong>${r.parts_created.length}</strong> 筆`);
    if ((r.parts_reused   || []).length) li.push(`沿用新客戶既有料號 <strong>${r.parts_reused.length}</strong> 筆`);
    if (r.items_rebound)                 li.push(`報價項目改綁 <strong>${r.items_rebound}</strong> 列`);
    if ((r.parts_updated  || []).length) li.push(`料號主檔改客戶 <strong>${r.parts_updated.length}</strong> 筆`);
    if ((r.parts_skipped  || []).length) li.push(`已是該客戶而略過 <strong>${r.parts_skipped.length}</strong> 筆`);
    if (r.orders_updated)                li.push(`連動訂單 <strong>${r.orders_updated}</strong> 筆`);
    if (r.orders_repointed)              li.push(`訂單改綁新料號 <strong>${r.orders_repointed}</strong> 筆`);
    if (r.boms_updated)                  li.push(`連動 BOM <strong>${r.boms_updated}</strong> 筆`);
    return '<div style="text-align:left;font-size:13px;line-height:1.9;">' + li.map(t => '・' + t).join('<br>') + '</div>';
}

function chgCustDoApply() {
    const cid = $('#chgCustId').val().trim();
    if (!_chgCustScan || !cid) return;
    if (_chgCustScan.verdict === 'block') { Swal.fire('不可直接變更', '請改用「建立新料號並改綁」', 'error'); return; }
    const to = $('#chgCustSearch').val().trim();
    const needConfirm = _chgCustScan.verdict === 'confirm';
    Swal.fire({
        icon: needConfirm ? 'warning' : 'question',
        title: needConfirm ? '再確認一次' : '確認變更客戶？',
        html: `即將把 <strong>${escapeHtml(_chgCustScan.quote.quote_no)}</strong> 整張單的客戶改為 <strong>${escapeHtml(to)}</strong>。<br>`
            + (needConfirm
                ? `<span style="color:#c0392b;">本張OP轉出的 ${(_chgCustScan.orders || []).length} 筆訂單與其 BOM 的客戶也會一起改掉。</span>`
                : '報價單表頭與各料號主檔的客戶都會改。'),
        showCancelButton: true, confirmButtonText: '確定變更', cancelButtonText: '取消',
        confirmButtonColor: '#F0A24B'
    }).then(r => {
        if (!r.isConfirmed) return;
        $('#chgCustApplyBtn, #chgCustCloneBtn').prop('disabled', true);
        $.post(API_URL, { action: 'qcc_apply', quote_id: currentEditId, customer_id: cid, confirmed: needConfirm ? 1 : 0 }, res => {
            $('#chgCustApplyBtn, #chgCustCloneBtn').prop('disabled', false);
            if (!res.success) { Swal.fire('變更失敗', res.message || '請稍後再試', 'error'); return; }
            $('#chgCustomerModal').modal('hide');
            Swal.fire({ icon: 'success', title: '已完成變更', html: chgCustSummaryHtml(res.data) });
            chgCustReloadList();
        }, 'json').fail(() => {
            $('#chgCustApplyBtn, #chgCustCloneBtn').prop('disabled', false);
            Swal.fire('錯誤', '與伺服器通訊失敗', 'error');
        });
    });
}

function chgCustDoClone() {
    const cid = $('#chgCustId').val().trim();
    if (!_chgCustScan || !cid) return;
    const to = $('#chgCustSearch').val().trim();
    Swal.fire({
        icon: 'warning', title: '建立新料號並改綁？',
        html: `會把 <strong>${escapeHtml(_chgCustScan.quote.quote_no)}</strong> 內每一個已綁定的料號，`
            + `各建一筆<strong>同料號、客戶為 ${escapeHtml(to)}</strong> 的新料號`
            + `（該客戶底下已有同料號時直接沿用），並把本張報價單與本張OP轉出的訂單改綁過去。<br>`
            + `<span style="color:#1e8449;">原本的料號完全不動。</span><br>`
            + `<span style="color:#c0392b;">圖面等實體附件不會一起複製，需另行處理。</span>`,
        showCancelButton: true, confirmButtonText: '建立並改綁', cancelButtonText: '取消',
        confirmButtonColor: '#3498db'
    }).then(r => {
        if (!r.isConfirmed) return;
        $('#chgCustApplyBtn, #chgCustCloneBtn').prop('disabled', true);
        $.post(API_URL, { action: 'qcc_clone_parts', quote_id: currentEditId, customer_id: cid }, res => {
            $('#chgCustApplyBtn, #chgCustCloneBtn').prop('disabled', false);
            if (!res.success) { Swal.fire('建立失敗', res.message || '請稍後再試', 'error'); return; }
            $('#chgCustomerModal').modal('hide');
            Swal.fire({ icon: 'success', title: '已建立新料號並改綁', html: chgCustSummaryHtml(res.data) });
            chgCustReloadList();
        }, 'json').fail(() => {
            $('#chgCustApplyBtn, #chgCustCloneBtn').prop('disabled', false);
            Swal.fire('錯誤', '與伺服器通訊失敗', 'error');
        });
    });
}
</script>
</body>
</html>
