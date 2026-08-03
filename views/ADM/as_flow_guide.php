<?php
/**
 * AS 流程說明手冊 —— 各課室 AS9100 流程／表單說明（讀取 MD 檔即時渲染）
 *
 * 資料來源：FOR CODEING 說明文件/AS9100(各組維護版)/AS流程-*.md
 * 鐵律5：路徑一律即時組（__DIR__ 相對），不寫死絕對路徑、不存 DB。
 * 本頁唯讀，不寫任何資料表。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/as_flow_guide.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
$conn = (new DBConnection())->getPDO();

$stmt = $conn->prepare("SELECT id FROM user WHERE user_uname = ?");
$stmt->execute([$_SESSION['userName']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) { header("Location:../../index.php"); exit; }
$uid = $currentUser['id'];

// ── 頁面權限（同 as_document_management.php 慣例；本頁唯讀，有 A 或 R 即可看）──
$deptPerm = null;
try {
    $sqlPage = "SELECT smp.page_id, smp.group_id FROM system_module_pages smp
        WHERE (:s LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
           OR (:s LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
        LIMIT 1";
    $st = $conn->prepare($sqlPage);
    $st->execute([':s' => $_SERVER['PHP_SELF']]);
    if ($pi = $st->fetch(PDO::FETCH_ASSOC)) {
        $st2 = $conn->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $st2->execute([$uid, $pi['page_id']]);
        $found = $st2->fetchAll(PDO::FETCH_COLUMN);
        if (empty($found) && !empty($pi['group_id'])) {
            $st3 = $conn->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $st3->execute([$pi['group_id']]);
            if ($gm = $st3->fetchColumn()) {
                $st4 = $conn->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $st4->execute([$uid, $gm]);
                $found = $st4->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($found as $p) { $chars = array_merge($chars, str_split($p)); }
        $chars = array_unique($chars);
        $deptPerm = in_array('A', $chars, true) ? 'A' : implode('', $chars);
    }
} catch (Exception $e) { error_log('as_flow_guide perm: ' . $e->getMessage()); }

include_once '../../src/common/role_features_helper.php';
$asFeatures = function_exists('rf_load_user_features_override')
    ? rf_load_user_features_override($conn, $uid, 'as_doc') : [];
$isRoleAdmin = in_array('all', $asFeatures, true);
$pp = $deptPerm ?: '';
$canView = $isRoleAdmin || strpos($pp, 'A') !== false || strpos($pp, 'R') !== false
        || in_array('asdoc_view', $asFeatures, true);
$roleLabel = $isRoleAdmin ? '管理者' : ($canView ? '檢閱' : '無權限');

// ── MD 檔白名單（key => [顯示名稱, 檔名, 圖示]）——只允許這幾支，杜絕路徑穿越 ──
$MD_DIR = __DIR__ . '/../../FOR CODEING 說明文件/AS9100(各組維護版)/';
$DOCS = [
    'overview' => ['總覽（全廠地圖）', 'AS流程-總覽.md',     'fa-sitemap'],
    'gm'       => ['總經理室',         'AS流程-總經理室.md', 'fa-star'],
    'mm'       => ['管理課',           'AS流程-管理課.md',   'fa-users'],
    'qa'       => ['品保課',           'AS流程-品保課.md',   'fa-check-square-o'],
    'td'       => ['技術課',           'AS流程-技術課.md',   'fa-wrench'],
    'sm'       => ['業務課',           'AS流程-業務課.md',   'fa-handshake-o'],
    'pm'       => ['生產課',           'AS流程-生產課.md',   'fa-cogs'],
    'dc'       => ['文管中心',         'AS流程-文管中心.md', 'fa-folder-open-o'],
    'zc'       => ['資材課',           'AS流程-資材課.md',   'fa-truck'],
];

// ── 原始 MD 檔輸出（?raw=key 純文字檢視 / ?dl=key 下載）──
$rawKey = $_GET['raw'] ?? $_GET['dl'] ?? '';
if ($rawKey !== '') {
    if (!$canView) { http_response_code(403); exit('無權限'); }
    if (!isset($DOCS[$rawKey])) { http_response_code(404); exit('查無此文件'); }
    $f = $MD_DIR . $DOCS[$rawKey][1];
    if (!is_file($f)) { http_response_code(404); exit('檔案不存在'); }
    if (isset($_GET['dl'])) {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($DOCS[$rawKey][1]) . '"');
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }
    readfile($f);
    exit;
}

// ══════════════ 文件／表單索引：讓 MD 內的文件編號變成可點（線上預覽＋線上表單）══════════════
// 「有沒有線上表單」三種來源，缺一不可：
//   ① as_form_template.form_doc_id          — AS 線上表單設計器做出來的表單
//   ② as_document.linked_module             — 既有電子化模組（car／qa_abnormal）
//   ③ 各模組自己的「AS 文件綁定設定」        — 表單已由某個既有頁面實作（ai-rules/16：編號一律走 as_document 綁定）
//      綁定值散在 system_settings 與 system_parameters，故用下表集中登記。
//      **新增頁面綁定時，記得回來補一列，否則此頁會漏判成「尚未建立」。**
$PAGE_BINDS = [
    // [來源, 鍵（sp 用 群組|鍵）, 該頁對此文件的用途, 頁面網址（相對本頁）]
    ['ss', 'vendor_audit_as_doc_id',  '供應商稽核管理 · 稽核查檢表',        '../pm/vendor_audit.php'],
    ['ss', 'vendor_record_as_doc_id', '供應商稽核管理 · 品質系統評鑑記錄表', '../pm/vendor_audit.php'],
    ['ss', 'vendor_roster_as_doc_id', '供應商稽核管理 · 合格供應商清冊',    '../pm/vendor_audit.php'],
    ['ss', 'vendor_eval_as_doc_id',   '供應商稽核管理 · 定期評核表',        '../pm/vendor_audit.php'],
    ['sp', 'EXTERNAL_DOC|as_doc_id',  '外來文件清單',                       '../Sales/external_doc_list.php'],
    ['sp', 'QUOTATION|as_doc_id',     '報價單',                             '../Sales/quotation_list_NEW.php'],
];
$PGBIND = [];   // as_document.id => ['name'=>用途, 'url'=>頁面]
foreach ($PAGE_BINDS as $b) {
    try {
        if ($b[0] === 'ss') {
            $q = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
            $q->execute([$b[1]]);
            $val = (string)$q->fetchColumn();
        } else {
            [$grp, $key] = explode('|', $b[1]);
            $q = $conn->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
            $q->execute([$grp, $key]);
            $raw = (string)$q->fetchColumn();
            $val = (string)(json_decode($raw, true) ?? $raw);   // system_parameters 存的是 JSON
        }
        $did = (int)$val;
        if ($did > 0) { $PGBIND[$did] = ['name' => $b[2], 'url' => $b[3]]; }
    } catch (Exception $e) { error_log('as_flow_guide pagebind: ' . $e->getMessage()); }
}

$DOCMAP = [];
try {
    $sqlMap = "SELECT d.id, d.doc_no, d.doc_name, d.doc_level, d.doc_type, d.current_version,
                      d.current_version_id, d.linked_module, p.doc_no AS parent_no,
                      dept.name AS dept_name, v.file_name,
                      t.id AS tpl_id, t.status AS tpl_status, t.published_version
               FROM as_document d
               LEFT JOIN department dept        ON dept.id = d.department_id
               LEFT JOIN as_document p          ON p.id    = d.parent_doc_id
               LEFT JOIN as_document_version v  ON v.id    = d.current_version_id
               LEFT JOIN as_form_template t     ON t.form_doc_id = d.id AND t.is_deleted = 0
               WHERE d.is_deleted = 0
               ORDER BY d.doc_no, t.id DESC";
    foreach ($conn->query($sqlMap, PDO::FETCH_ASSOC) as $r) {
        $no = strtoupper(trim((string)$r['doc_no']));
        if ($no === '' || isset($DOCMAP[$no])) { continue; }   // 同文件多模板：ORDER 已把最新排前，取第一筆
        $mod = (string)($r['linked_module'] ?? '');
        $DOCMAP[$no] = [
            'id'   => (int)$r['id'],
            'name' => (string)$r['doc_name'],
            'lv'   => (string)($r['doc_level'] ?? ''),
            'ty'   => (string)($r['doc_type'] ?? ''),
            'dept' => (string)($r['dept_name'] ?? '') ?: '跨部門',
            'par'  => (string)($r['parent_no'] ?? ''),
            'ver'  => (string)($r['current_version'] ?? ''),
            'vid'  => (int)($r['current_version_id'] ?? 0),
            'file' => !empty($r['file_name']) ? 1 : 0,
            'tpl'  => (int)($r['tpl_id'] ?? 0),
            'tst'  => (string)($r['tpl_status'] ?? ''),
            'pv'   => (int)($r['published_version'] ?? 0),
            'mod'  => $mod,
            'mn'   => $mod === 'car' ? '異常矯正處理單(CAR)' : ($mod === 'qa_abnormal' ? '品質異常處理單' : ''),
            'mu'   => $mod === 'car' ? '../QA/correction_order.php' : ($mod === 'qa_abnormal' ? '../QA/qa_abnormal_view.php' : ''),
            'pgn'  => $PGBIND[(int)$r['id']]['name'] ?? '',   // ③ 已綁定既有頁面
            'pgu'  => $PGBIND[(int)$r['id']]['url']  ?? '',
        ];
    }
} catch (Exception $e) { error_log('as_flow_guide docmap: ' . $e->getMessage()); }

/* ══════════════════ 極簡 Markdown → HTML（自製，不依賴外部套件） ══════════════════
   支援：# ~ #### 標題、表格、- / 1. 清單（含縮排）、> 引用（可含表格）、``` 區塊、
        --- 分隔線、**粗體**、`行內碼`、~~刪除線~~、[文字](連結)                        */
/** 把文字中的 AS 文件／表單編號（2-QA-01、2-QA-01-01、2-PM-01-02-01、2-PH-01-04A…）
 *  換成可點的 chip；資料庫查得到才換，查不到原樣保留（避免把範例格式也變成連結）。
 *  有線上表單者加上閃電圖示。 */
function egmd_docno($s) {
    global $DOCMAP;
    if (!$DOCMAP) { return $s; }
    return preg_replace_callback(
        '/(?<![0-9A-Za-z\-])(\d-[A-Z]{2}-\d{2}(?:-\d{2}){0,2})([A-Z]?)(?![0-9A-Za-z\-])/u',
        function ($m) use ($DOCMAP) {
            $key = $m[1];
            if (!isset($DOCMAP[$key])) { return $m[0]; }
            $d  = $DOCMAP[$key];
            $on = ($d['tpl'] || $d['mod'] || $d['pgn']);
            return '<a href="#" class="docchip' . ($on ? ' has-online' : '') . '" data-no="' . $key . '"'
                 . ' title="' . htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8')
                 . ($on ? '（已有線上表單，點擊預覽）' : '（點擊線上預覽）') . '">'
                 . $m[0] . ($on ? '<i class="fa fa-bolt"></i>' : '') . '</a>';
        }, $s);
}
/** 待處理問題的「文件／位置」欄：把文件編號變成可點 → 另開 AS 文件管理並自動篩選到該筆 */
function eg_doclink($txt) {
    global $DOCMAP;
    $s = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
    return preg_replace_callback(
        '/(?<![0-9A-Za-z\-])(\d-[A-Z]{2}-\d{2}(?:-\d{2}){0,2})([A-Z]?)(?![0-9A-Za-z\-])/u',
        function ($m) use ($DOCMAP) {
            $known = isset($DOCMAP[$m[1]]);
            return '<a class="doclink" target="_blank" rel="noopener"'
                 . ' href="as_document_management.php?kw=' . urlencode($m[1]) . '"'
                 . ' title="' . ($known ? htmlspecialchars($DOCMAP[$m[1]]['name'], ENT_QUOTES, 'UTF-8') . '｜' : '')
                 . '另開 AS 文件管理並篩選到此筆">' . $m[0] . '<i class="fa fa-external-link"></i></a>';
        }, $s);
}
function egmd_inline($s) {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    // 行內碼先抽出佔位，避免內部被其他規則吃掉
    $codes = [];
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = $m[1];
        return "\x01" . (count($codes) - 1) . "\x02";
    }, $s);
    // 文件／表單編號 → 可點（此步必須在連結轉換之前，否則會產生巢狀 <a>）
    $s = egmd_docno($s);
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/~~(.+?)~~/u', '<del>$1</del>', $s);
    // 連結：內部 .md 轉成本頁分頁切換，其餘照常
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/u', function ($m) {
        $txt = $m[1]; $url = $m[2];
        if (preg_match('/^AS流程-(.+)\.md$/u', $url)) {
            return '<a href="#" class="md-jump" data-name="' . $url . '">' . $txt . '</a>';
        }
        if (preg_match('/^(https?:)?\/\//i', $url)) {
            return '<a href="' . $url . '" target="_blank" rel="noopener">' . $txt . '</a>';
        }
        return '<a href="' . $url . '">' . $txt . '</a>';
    }, $s);
    $s = preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($codes) {
        return '<code>' . $codes[(int)$m[1]] . '</code>';
    }, $s);
    return $s;
}
function egmd_table($rows) {
    // $rows[0]=表頭、$rows[1]=分隔、其餘=內容
    $out = '<div class="md-tablewrap"><table class="md-table"><thead><tr>';
    foreach (egmd_cells($rows[0]) as $c) { $out .= '<th>' . egmd_inline($c) . '</th>'; }
    $out .= '</tr></thead><tbody>';
    for ($i = 2; $i < count($rows); $i++) {
        $out .= '<tr>';
        foreach (egmd_cells($rows[$i]) as $c) { $out .= '<td>' . egmd_inline($c) . '</td>'; }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div>';
}
function egmd_cells($line) {
    $line = trim($line);
    $line = preg_replace('/^\|/', '', $line);
    $line = preg_replace('/\|$/', '', $line);
    return array_map('trim', explode('|', $line));
}
function egmd_is_table_sep($line) { return (bool)preg_match('/^\s*\|[\s:\-\|]+\|\s*$/', $line); }

function egmd_render($md) {
    $md   = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $n = count($lines);
    $out = '';
    $i = 0;
    while ($i < $n) {
        $line = $lines[$i];

        // ``` 程式/流程區塊
        if (preg_match('/^\s*```/', $line)) {
            $buf = [];
            $i++;
            while ($i < $n && !preg_match('/^\s*```/', $lines[$i])) { $buf[] = $lines[$i]; $i++; }
            $i++;
            $out .= '<pre class="md-pre">' . htmlspecialchars(implode("\n", $buf), ENT_QUOTES, 'UTF-8') . '</pre>';
            continue;
        }
        // > 引用（整段抓出來遞迴渲染，故引用內可含表格/清單）
        if (preg_match('/^\s*>\s?/', $line)) {
            $buf = [];
            while ($i < $n && (preg_match('/^\s*>\s?/', $lines[$i]) || trim($lines[$i]) === '')) {
                if (trim($lines[$i]) === '') {
                    // 空行：後面若不再是引用就結束
                    if ($i + 1 < $n && !preg_match('/^\s*>\s?/', $lines[$i + 1])) { break; }
                    $buf[] = '';
                } else {
                    $buf[] = preg_replace('/^\s*>\s?/', '', $lines[$i]);
                }
                $i++;
            }
            $out .= '<blockquote class="md-quote">' . egmd_render(implode("\n", $buf)) . '</blockquote>';
            continue;
        }
        // 表格
        if (strpos(ltrim($line), '|') === 0 && $i + 1 < $n && egmd_is_table_sep($lines[$i + 1])) {
            $rows = [];
            while ($i < $n && strpos(ltrim($lines[$i]), '|') === 0) { $rows[] = $lines[$i]; $i++; }
            $out .= egmd_table($rows);
            continue;
        }
        // 標題
        if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
            $lv = strlen($m[1]) + 1;               // # → h2（h1 留給頁面標題）
            if ($lv > 5) { $lv = 5; }
            $out .= "<h{$lv} class=\"md-h md-h{$lv}\">" . egmd_inline($m[2]) . "</h{$lv}>";
            $i++;
            continue;
        }
        // 分隔線
        if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) { $out .= '<hr class="md-hr">'; $i++; continue; }
        // 清單（-、數字），以縮排決定層級
        if (preg_match('/^(\s*)([-*]|\d+[.)])\s+(.*)$/', $line, $m)) {
            $ordered = !preg_match('/^[-*]$/', $m[2]);
            $tag = $ordered ? 'ol' : 'ul';
            $baseIndent = strlen(str_replace("\t", '    ', $m[1]));
            $out .= "<{$tag} class=\"md-list\">";
            $depth = 0;
            while ($i < $n && preg_match('/^(\s*)([-*]|\d+[.)])\s+(.*)$/', $lines[$i], $mm)) {
                $ind = strlen(str_replace("\t", '    ', $mm[1]));
                $want = (int)floor(max(0, $ind - $baseIndent) / 2);
                while ($want > $depth) { $out .= "<{$tag} class=\"md-list\">"; $depth++; }
                while ($want < $depth) { $out .= "</{$tag}>"; $depth--; }
                $body = $mm[3];
                // 續行（下一行有縮排且非清單）併入本項
                while ($i + 1 < $n && trim($lines[$i + 1]) !== ''
                       && !preg_match('/^(\s*)([-*]|\d+[.)])\s+/', $lines[$i + 1])
                       && preg_match('/^\s{2,}\S/', $lines[$i + 1])
                       && strpos(ltrim($lines[$i + 1]), '|') !== 0
                       && !preg_match('/^\s*>/', $lines[$i + 1])) {
                    $i++;
                    $body .= ' ' . trim($lines[$i]);
                }
                $out .= '<li>' . egmd_inline($body) . '</li>';
                $i++;
            }
            while ($depth-- > 0) { $out .= "</{$tag}>"; }
            $out .= "</{$tag}>";
            continue;
        }
        // 空行
        if (trim($line) === '') { $i++; continue; }
        // 一般段落
        $buf = [];
        while ($i < $n && trim($lines[$i]) !== ''
               && !preg_match('/^(#{1,4})\s|^\s*>|^\s*```|^\s*(-{3,}|\*{3,})\s*$/', $lines[$i])
               && !preg_match('/^(\s*)([-*]|\d+[.)])\s+/', $lines[$i])
               && strpos(ltrim($lines[$i]), '|') !== 0) {
            $buf[] = $lines[$i];
            $i++;
        }
        if ($buf) { $out .= '<p class="md-p">' . egmd_inline(implode(' ', $buf)) . '</p>'; }
        else { $i++; }
    }
    return $out;
}

// ── 待處理問題清單（依各課說明檔「程序書與現況不一致」彙整）──
$ISSUES = [
    // 高：影響 AS 稽核可追溯性
    ['高','技術課','3-TD-02 製造製程說明書','缺母程序書檔案、且未登錄系統 AS 文件管理，卻被 2-QA-01、2-QA-03、2-TD-02、2-PD-01 四份程序引用','補建 3-TD-02 文件並登錄 AS 文件管理（三張表單 3-TD-02-01/02/03 一併登錄）'],
    ['高','品保課','2-QA-04 §6.2 / §9','仍引用已作廢的「巡迴檢驗表(2-QA-01-05)」與舊名「現場檢驗記錄表」','改為現行「製程巡查表(2-QA-01-09)」'],
    ['高','管理課 / 生產課','2-MM-02 §6.3.1 / §9','仍列已於 2-PM-01 1.3 版刪除的「機台每季年保養表(2-PM-01-02-03)」與舊名「機台日保養表」','同步 2-PM-01 最新版：改為「機台保養卡(2-PM-01-02-01)」並刪除已廢表單'],
    ['高','資材課(生管組)','2-PH-01 §6.2.2 / §6.3.3','新供應商評鑑 75 分通過門檻與「<75 分但提改善對策可登錄」例外只寫在懶人包 txt；稽核對象挑選門檻程序書寫 C 級(≦85分)、現場寫 <75 分；稽核頻率程序書未載明（現場每年兩次）','門檻與頻率正式寫入程序書。註：定期評核（資格門，全部都要評核）與稽核（合格廠中抽驗）為兩套不同機制，不可混寫'],
    ['高','資材課(生管組)','2-PH-03 §9.4 / §9.5','引用「航太合格供應商清冊(2-PH-01-04A)」「非航太合格供應商清冊(2-PH-01-04B)」，但 2-PH-01 與實體檔案僅有單一「合格供應商清冊(2-PH-01-04)」','確認是否分冊；不分冊則修正 2-PH-03 引用編號'],
    ['高','生產課','2-PM-01-02-01 機台保養卡','資料夾內僅為捷徑(.lnk)無實體檔；DB 表單編號(2-PM-01-02 登錄為「天車保養表」)與實體檔編號不一致','補實體檔並統一 DB 與實體的表單編號'],
    // 中：編號誤植
    ['中','文管中心','2-DC-04 §9.1','品質異常處理單編號誤標為 (2-QA-05-01)','應為 (2-QA-01-01)'],
    ['中','業務課','2-SM-02 §9.7','異常矯正處理單編號誤標為 (2-QA-01-02)（該號是製程管制卡）','應為 (2-QA-01-04)'],
    ['中','資材課(倉管組)','2-WH-01 §9.7','訂單領料單編號誤標為 (2-SM-01-07)','應為 (2-SM-01-04)'],
    ['中','資材課(倉管組)','3-SM-01 §7.1','出貨標籤編號誤標為 (2-WH-01-07)（該號是入庫單）；且第 1 頁與第 3 頁兩份使用表單清單不一致','應為 (2-WH-01-03)，並統一兩處清單'],
    ['中','品保課','3-QA-03 §8.2','檢驗與測試管理程序編號誤標為 (2-QA-05)','應為 (2-QA-04)'],
    ['中','管理課','2-MM-01 §8.2','績效考核辦法編號誤標為 (2-MM-02)','應為 (3-MM-02)'],
    ['中','管理課','3-MM-01 §6.1','人力資源管理程序編號誤標為 (2-MM-02)','應為 (2-MM-01)'],
    ['中','管理課','2-MM-01 §9.4','表單名稱寫「員工試用期滿通知書」，該表已作廢','現行為「員工薪資調整通知書(2-MM-01-03)」'],
    ['中','管理課','2-MM-02 §6.3.3','文件名稱寫「檢測儀器校驗管理程序」，查無此文件','應為「量規儀器校正管理辦法(3-QA-01)」'],
    // 低：清單漏列 / 名稱不一致 / 版面
    ['低','總經理室','2-GM-05 §9','使用表單未列「會議通知單(2-GM-05-03)」（檔案與 DB 皆有）','補列'],
    ['低','管理課','3-MM-02 §7','應用表單未列「獎懲單(3-MM-02-06)」，但 §5.3 獎懲加減分需用此單','補列'],
    ['低','技術課','2-TD-05 §7','相關表單未列「夾具清單(2-TD-05-02)」（檔案與 DB 皆有）','補列'],
    ['低','業務課','2-SM-01 §9','使用表單未列「訂單修改審查記錄表(2-SM-01-03)」與「訂單領料單(2-SM-01-04)」，但內文與他程序有使用','補列'],
    ['低','總經理室','2-GM-04 §3.2 vs §6.1/§6.8','KPI 監控頻率兩處不一致：一處寫「每月監控」、一處寫「逐季統計檢討／逐季監控」','擇一統一'],
    ['低','資材課(倉管組)','2-WH-01 文件名稱','封面與 DB 為「倉儲出貨管理程序」，內頁與檔名為「倉儲出入貨管理程序」，他程序引用時兩種並用','統一名稱'],
    ['低','技術課','2-TD-05 文件名稱','標題頁寫「夾治具管理辦法」、文件類別標「程序書(2)」、DB 登錄「夾治具管理程序」','統一名稱與類別'],
    ['低','資材課 / 生管組','2-PD-01 第4頁、2-PH-01 第2頁','頁首誤植「和昕精密科技有限公司」（非本公司名稱，疑為範本殘留）','刪除'],
    ['低','品保課','2-QA-04','修訂紀錄已列至 1.4 版，但內頁「頁版別」仍標 1.2／1.3','內頁版別同步更新'],
    ['低','總經理室','2-GM-03 §3 / §4','權責章節內編號寫成 4.1～4.4，定義章節寫成 3.1～3.6（兩章節編號互換）','修正章節編號'],
    ['低','資材課(生管組)','2-PH-01-02 供應商評鑑稽核查檢表','資料夾內同時存在「新版」與「新版2026」兩個檔案','明確標示現行版本，舊版移入舊版資料夾'],
    ['低','文管中心','2-DC-05 圖章管理程序','程序書狀態標「尚未開始」，但 4 張表單已建檔且已登錄 DB','確認啟用時機（系統已有圖章管理模組可對接）'],
    ['低','文管中心 / 技術課','2-DC-03、2-DC-04','文件編號掛 DC(文管中心)，制修訂部門卻是技術課','釐清維護權責；改版時需會辦技術課'],
    ['低','管理課 / 總經理室','3-MM-01 知識管理辦法','制修訂部門為總經理室，DB 部門歸屬為管理部','統一歸屬'],
    ['低','總經理室 / 董事長室','GM 系列 DB 歸屬','1-GM-01、2-GM-01、2-GM-01-02、2-GM-06 系列登錄「董事長室」；其餘 GM 文件登錄「總經理室」；實體資料夾全在總經理室','統一歸屬或明確定義兩者分工'],
    ['低','總經理室','1-GM-01 §2.1 管理代表派令','派令內文載明管理代表姓名，人員異動即須改版整本品質手冊','考慮改為職務指派方式或改列附件'],
    ['低','管理課','管理課資料夾','存在無 AS 編號的檔案：「主管人員考核表.docx」「間接人員考核表.odt」','確認是否納編給號或移除'],
    ['低','總經理室','2-GM-02 §6.7.1','引用製程開發作業程序定義專案生命週期，但與 2-TD-02 五階段的整合方式未具體定義','補充對應關係'],
];
// 四階表單清單（線上表單對照分頁用）：doc_no 有 3 段以上者＝表單
$FORMS = [];
foreach ($DOCMAP as $no => $d) {
    if (substr_count($no, '-') >= 3) { $FORMS[$no] = $d; }
}
$onlineCnt = count(array_filter($FORMS, fn($d) => $d['tpl'] || $d['mod'] || $d['pgn']));

$cntHigh = count(array_filter($ISSUES, fn($r) => $r[0] === '高'));
$cntMid  = count(array_filter($ISSUES, fn($r) => $r[0] === '中'));
$cntLow  = count(array_filter($ISSUES, fn($r) => $r[0] === '低'));

// 預設顯示的文件
$cur = $_GET['doc'] ?? 'overview';
if (!isset($DOCS[$cur])) { $cur = 'overview'; }
$mdPath  = $MD_DIR . $DOCS[$cur][1];
$mdExist = is_file($mdPath);
$mdHtml  = $mdExist ? egmd_render(file_get_contents($mdPath)) : '';
$mdTime  = $mdExist ? date('Y-m-d H:i', filemtime($mdPath)) : '';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AS 流程說明手冊</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 側欄先隱藏，載入完成後由 JS 顯示（必須連 JS 一起，見 CLAUDE.md 鐵律6） */
#sidebar-menu { visibility: hidden; }
html { overflow-x: hidden; }
.right_col { background:#FBF6EE; font-family:"Microsoft JhengHei","微軟正黑體",Arial,sans-serif; color:#3F2E1B; padding:14px; min-height:100vh; }

/* 【Gentelella 陷阱】.top_nav 高度為 0（其子 .nav_menu 是 float:left;width:100% 溢出），
   所以 .right_col 的「第一個子元素」若自成 BFC（overflow:hidden / display:flex / float），
   會因為「BFC 盒不可與浮動重疊」被壓成寬度 0（標題被擠成一字一行的直條、後面元素被推下 260px）。
   解法：第一個子元素一律加 clear:both，先清掉那個溢出的浮動再排版。 */
.fg-title { clear:both; display:flex; align-items:center; justify-content:space-between; gap:10px; margin:0 0 10px; }
.fg-title h2 { margin:0; font-size:20px; color:#7A4E17; }
.fg-role { flex:0 0 auto; font-size:13px; color:#5B3A1E; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
.fg-role .fa-question-circle { cursor:pointer; color:#B5762A; margin-left:5px; }

.fg-tabs { display:flex; gap:4px; margin-bottom:10px; border-bottom:2px solid #E8D5B5; clear:both; }
.fg-tab { border:1px solid #E8D5B5; border-bottom:none; background:#FBF3E5; color:#8A6D45; cursor:pointer;
          padding:7px 18px; font-size:14px; border-radius:6px 6px 0 0; margin-bottom:-2px; }
.fg-tab.active { background:#fff; color:#5B3A1E; font-weight:bold; border-bottom:2px solid #fff; }
.fg-tab .badge-warm { background:#DD5138; color:#fff; border-radius:9px; padding:0 7px; font-size:11px; margin-left:5px; }

.fg-wrap { display:flex; gap:12px; align-items:flex-start; }
.fg-side { flex:0 0 190px; background:#fff; border:1px solid #E0CBA0; border-radius:8px; padding:8px; box-shadow:0 2px 8px rgba(90,61,30,.10); }
.fg-side .sd-h { font-size:12px; color:#8A6D45; padding:2px 6px 6px; border-bottom:1px solid #F0E3CB; margin-bottom:5px; }
.fg-side a.sd-item { display:block; padding:7px 10px; font-size:13.5px; color:#5B3A1E; border-radius:5px; text-decoration:none; margin-bottom:2px; }
.fg-side a.sd-item:hover { background:#F7E0BD; }
.fg-side a.sd-item.on { background:#F0A24B; color:#fff; font-weight:bold; }
.fg-side a.sd-item i { width:16px; margin-right:5px; }
.fg-side .sd-tools { border-top:1px solid #F0E3CB; margin-top:6px; padding-top:6px; }
.fg-side .sd-tools a { display:block; font-size:12px; color:#8A5A2B; padding:4px 10px; text-decoration:none; }
.fg-side .sd-tools a:hover { text-decoration:underline; }

.fg-main { flex:1 1 auto; min-width:0; background:#fff; border:1px solid #E0CBA0; border-radius:8px;
           padding:6px 22px 26px; box-shadow:0 2px 8px rgba(90,61,30,.10); }
.fg-bar { position:sticky; top:0; z-index:20; background:#fff; border-bottom:1px solid #F0E3CB;
          padding:8px 0 7px; margin-bottom:10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.fg-bar input[type=text] { height:30px; font-size:13px; padding:0 10px; border:1px solid #D8BE93; border-radius:4px; width:230px; }
.fg-bar button, .fg-bar a.btnlink { height:30px; line-height:28px; font-size:13px; padding:0 12px; border:1px solid #D8BE93;
          border-radius:4px; background:#fff; color:#5B3A1E; cursor:pointer; text-decoration:none; display:inline-block; }
.fg-bar button:hover, .fg-bar a.btnlink:hover { background:#F7E0BD; }
.fg-bar .btn-warm { background:#F0A24B; color:#fff; border-color:#D98A33; }
.fg-bar .btn-warm:hover { background:#D98A33; }
.fg-bar .fg-file { margin-left:auto; font-size:12px; color:#8A6D45; }

/* ── Markdown 渲染樣式（暖色系，見 ai-rules/10） ── */
.md-body { font-size:14px; line-height:1.85; color:#3F2E1B; }
.md-h { font-family:"Microsoft JhengHei",sans-serif; }
.md-h2 { font-size:21px; color:#7A4E17; border-bottom:3px solid #F0A24B; padding-bottom:6px; margin:4px 0 14px; }
.md-h3 { font-size:17.5px; color:#8A5A2B; border-left:5px solid #F0A24B; padding-left:10px; margin:26px 0 10px; }
.md-h4 { font-size:15.5px; color:#8A5A2B; margin:18px 0 7px; border-bottom:1px dashed #E8D5B5; padding-bottom:4px; }
.md-h5 { font-size:14.5px; color:#9A6B33; margin:14px 0 6px; }
.md-p { margin:7px 0; }
.md-list { margin:6px 0 10px; padding-left:24px; }
.md-list li { margin:3px 0; }
.md-hr { border:0; border-top:2px dashed #E8D5B5; margin:22px 0; }
.md-body strong { color:#8A5A2B; }
.md-body code { background:#FDF3E3; color:#8A4B12; border:1px solid #F0E0C4; border-radius:3px; padding:1px 5px; font-size:12.5px; }
.md-pre { background:#FDF8EF; border:1px solid #E8D5B5; border-left:5px solid #F0A24B; border-radius:6px;
          padding:12px 14px; font-size:12.5px; line-height:1.6; color:#5B3A1E; overflow-x:auto; white-space:pre; }
.md-quote { background:#FFF7E8; border-left:5px solid #F0A24B; border-radius:0 6px 6px 0; padding:8px 14px; margin:12px 0; color:#5B3A1E; }
.md-quote > *:first-child { margin-top:0; }
.md-quote > *:last-child { margin-bottom:0; }
.md-tablewrap { overflow-x:auto; margin:10px 0 16px; }   /* 寬表格自己捲，頁面不橫捲 */
.md-table { border-collapse:collapse; font-size:13px; min-width:100%; }
.md-table th { background:#F7E0BD; color:#5A3D1E; padding:7px 10px; border:1px solid #E0CBA0; text-align:left; white-space:nowrap; }
.md-table td { padding:6px 10px; border:1px solid #E8D9B8; vertical-align:top; }
.md-table tbody tr:nth-child(even) { background:#FDF8EF; }
.md-table tbody tr:hover { background:#F7E0BD; }
.md-body a { color:#B5762A; }
.md-body del { color:#A08b70; }
mark.hit { background:#F0A24B; color:#fff; border-radius:2px; padding:0 2px; }

/* ── 文件／表單編號 chip ── */
a.docchip { display:inline-block; border-bottom:1px dotted #C89B5A; color:#8A5A2B; text-decoration:none;
            padding:0 1px; border-radius:3px; }
a.docchip:hover { background:#F7E0BD; color:#5A3D1E; text-decoration:none; }
a.docchip.has-online { color:#B24A12; font-weight:bold; border-bottom:1px solid #F0A24B; }
a.docchip.has-online i { color:#E08427; margin-left:3px; font-size:11px; }
a.docchip.has-online:hover { background:#F0A24B; color:#fff; }
a.docchip.has-online:hover i { color:#fff; }
.chip-legend { font-size:12px; color:#8A6D45; background:#FFF7E8; border:1px dashed #F0A24B;
               border-radius:6px; padding:5px 10px; margin:0 0 10px; }

/* ── 線上表單對照 ── */
.of-table { width:100%; border-collapse:collapse; font-size:13px; }
.of-table th { background:#F7E0BD; color:#5A3D1E; padding:7px 9px; border:1px solid #E0CBA0; text-align:left; }
.of-table td { padding:6px 9px; border:1px solid #E8D9B8; vertical-align:middle; }
.of-table tbody tr:nth-child(even) { background:#FDF8EF; }
.of-table tbody tr.has-on { background:#FFF3E0; }
.of-table tbody tr:hover { background:#F7E0BD; }
.on-yes { display:inline-block; background:#F0A24B; color:#4A2C0A; border-radius:9px; padding:1px 9px; font-size:11.5px; font-weight:bold; white-space:nowrap; }
.on-no  { display:inline-block; background:#EFE7DA; color:#8A6D45; border-radius:9px; padding:1px 9px; font-size:11.5px; white-space:nowrap; }
.btn-mini { display:inline-block; height:24px; line-height:22px; font-size:12px; padding:0 8px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5B3A1E; cursor:pointer; text-decoration:none; margin-right:3px; }
.btn-mini:hover { background:#F7E0BD; color:#5B3A1E; text-decoration:none; }
.btn-mini.warm { background:#F0A24B; color:#fff; border-color:#D98A33; }
.btn-mini.warm:hover { background:#D98A33; color:#fff; }

/* ── 預覽跳窗 ── */
#pvMask .box { max-width:1180px; width:94vw; padding:0; }
.pv-head { background:#F7E0BD; border-radius:8px 8px 0 0; padding:10px 16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.pv-head h4 { margin:0; border:none; font-size:16px; color:#7A4E17; }
.pv-head .pv-meta { font-size:12px; color:#8A6D45; }
.pv-head .pv-close { margin-left:auto; background:none; border:none; font-size:22px; color:#8A5A2B; cursor:pointer; line-height:1; }
.pv-acts { padding:9px 16px; border-bottom:1px solid #F0E3CB; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.pv-frame { width:100%; height:62vh; border:none; background:#FBF6EE; display:block; }
.pv-empty { padding:34px 16px; text-align:center; color:#8A6D45; font-size:13.5px; }

/* ── 問題清單 ── */
.iss-sum { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.iss-card { flex:1 1 150px; border-radius:8px; padding:10px 14px; color:#fff; }
.iss-card b { font-size:26px; display:block; line-height:1.2; }
.iss-card span { font-size:12.5px; }
.c-high { background:#DD5138; } .c-mid { background:#F0A24B; } .c-low { background:#F7E0BD; color:#5A3D1E !important; }
.iss-table { width:100%; border-collapse:collapse; font-size:13px; }
.iss-table th { background:#F7E0BD; color:#5A3D1E; padding:7px 9px; border:1px solid #E0CBA0; text-align:left; }
.iss-table td { padding:7px 9px; border:1px solid #E8D9B8; vertical-align:top; }
.iss-table tbody tr:nth-child(even) { background:#FDF8EF; }
.lv { display:inline-block; padding:1px 9px; border-radius:9px; font-size:11.5px; font-weight:bold; white-space:nowrap; }
.lv-高 { background:#DD5138; color:#fff; } .lv-中 { background:#F0A24B; color:#4A2C0A; } .lv-低 { background:#F7E0BD; color:#5A3D1E; }
a.doclink { color:#B24A12; text-decoration:none; border-bottom:1px solid #F0A24B; }
a.doclink:hover { background:#F0A24B; color:#fff; text-decoration:none; }
a.doclink i { font-size:10px; margin-left:3px; opacity:.65; }

.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
                 background:#F0A24B; color:#fff; cursor:pointer; }
.page-help-btn:hover { background:#d98a33; }
@media print { .page-help-btn { display:none !important; } }
.help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
.help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
.help-doc h4:first-child { margin-top:0; }
.help-doc b { color:#8A5A2B; }
.help-doc ul { margin:4px 0 8px; padding-left:20px; }
.help-doc li { margin:2px 0; }
.help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }

.fg-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9999; }
.fg-mask .box { background:#fff; max-width:720px; margin:6vh auto; border-radius:8px; padding:18px 22px; max-height:84vh; overflow:auto; }
.fg-mask h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:5px; margin-top:0; }
#fgTop { display:none; position:fixed; right:22px; bottom:26px; z-index:60; background:#F0A24B; color:#fff;
         border:none; border-radius:50%; width:42px; height:42px; font-size:17px; cursor:pointer; box-shadow:0 2px 8px rgba(90,61,30,.3); }
@media print { .fg-side, .fg-tabs, .fg-bar, #fgTop, .fg-role { display:none !important; } .fg-main { border:none; box-shadow:none; } }
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">

<div class="fg-title">
  <h2><i class="fa fa-book"></i> AS 流程說明手冊</h2>
  <div style="display:flex;align-items:center;gap:8px;">
    <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
    <span class="fg-role">目前角色：<?= htmlspecialchars($roleLabel) ?><i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
  </div>
</div>

<?php if (!$canView): ?>
  <div class="fg-main" style="clear:both;">
    <p style="color:#DD5138;font-size:15px;padding:30px 0;text-align:center;">
      <i class="fa fa-lock"></i> 您沒有檢視本頁的權限，請洽系統管理員開通「AS 流程說明手冊」檢閱權限。</p>
  </div>
<?php else: ?>

<div class="fg-tabs">
  <div class="fg-tab active" data-tab="doc"><i class="fa fa-file-text-o"></i> 課室說明文件</div>
  <div class="fg-tab" data-tab="iss"><i class="fa fa-exclamation-triangle"></i> 待處理問題
    <span class="badge-warm"><?= count($ISSUES) ?></span></div>
  <div class="fg-tab" data-tab="onl"><i class="fa fa-bolt"></i> 線上表單對照
    <span class="badge-warm"><?= $onlineCnt ?>/<?= count($FORMS) ?></span></div>
</div>

<!-- ═════════ 分頁：課室說明文件 ═════════ -->
<div id="tabDoc">
<div class="fg-wrap">
  <div class="fg-side">
    <div class="sd-h">課室</div>
    <?php foreach ($DOCS as $k => $d): ?>
      <a class="sd-item <?= $k === $cur ? 'on' : '' ?>" href="?doc=<?= $k ?>">
        <i class="fa <?= $d[2] ?>"></i><?= htmlspecialchars($d[0]) ?></a>
    <?php endforeach; ?>
    <div class="sd-tools">
      <a href="as_document_management.php"><i class="fa fa-external-link"></i> 前往 AS 文件管理</a>
      <a href="as_form_list.php"><i class="fa fa-external-link"></i> 前往線上表單</a>
    </div>
  </div>

  <div class="fg-main">
    <div class="fg-bar">
      <input type="text" id="kw" placeholder="在本篇中搜尋…（Enter 下一筆）">
      <button id="btnFind" class="btn-warm"><i class="fa fa-search"></i> 搜尋</button>
      <button id="btnClear"><i class="fa fa-eraser"></i> 清除</button>
      <a class="btnlink" href="?raw=<?= $cur ?>" target="_blank" rel="noopener"><i class="fa fa-file-code-o"></i> 原始 MD</a>
      <a class="btnlink" href="?dl=<?= $cur ?>"><i class="fa fa-download"></i> 下載 MD</a>
      <button id="btnPrint"><i class="fa fa-print"></i> 列印</button>
      <span class="fg-file"><?= htmlspecialchars($DOCS[$cur][1]) ?><?= $mdTime ? '｜更新 ' . $mdTime : '' ?></span>
    </div>
    <div class="chip-legend">
      <i class="fa fa-hand-pointer-o"></i> 文中的<strong>文件／表單編號</strong>可直接點擊 → 開啟<strong>線上預覽</strong>（Office 檔自動轉 PDF）；
      標成 <a href="#" class="docchip has-online" onclick="return false;">橘色粗體<i class="fa fa-bolt"></i></a> 者<strong>已有線上表單</strong>，可一鍵另開分頁填寫。
      完整對照見上方「<i class="fa fa-bolt"></i> 線上表單對照」分頁。
    </div>
    <div class="md-body" id="mdBody">
      <?php if ($mdExist): ?><?= $mdHtml ?>
      <?php else: ?>
        <p style="color:#DD5138;padding:24px 0;"><i class="fa fa-exclamation-circle"></i>
          找不到說明檔 <code><?= htmlspecialchars($DOCS[$cur][1]) ?></code>，
          請確認檔案仍在 <code>FOR CODEING 說明文件/AS9100(各組維護版)/</code> 內。</p>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<!-- ═════════ 分頁：待處理問題 ═════════ -->
<div id="tabIss" style="display:none;">
  <div class="fg-main">
    <div class="fg-bar">
      <select id="lvFilter" class="form-control input-sm" style="width:150px;height:30px;display:inline-block;">
        <option value="">全部優先度</option><option value="高">高（稽核風險）</option>
        <option value="中">中（編號誤植）</option><option value="低">低（清單/名稱）</option>
      </select>
      <select id="deptFilter" class="form-control input-sm" style="width:180px;height:30px;display:inline-block;">
        <option value="">全部課室</option>
        <?php foreach (array_unique(array_column($ISSUES, 1)) as $dp): ?>
          <option value="<?= htmlspecialchars($dp) ?>"><?= htmlspecialchars($dp) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" id="issKw" placeholder="搜尋文件編號／問題內容…">
      <button id="btnIssClear"><i class="fa fa-eraser"></i> 清除</button>
      <span class="fg-file">共 <span id="issCount"><?= count($ISSUES) ?></span> 筆</span>
    </div>

    <div class="iss-sum">
      <div class="iss-card c-high"><b><?= $cntHigh ?></b><span>高：影響 AS 稽核可追溯性，建議優先處理</span></div>
      <div class="iss-card c-mid"><b><?= $cntMid ?></b><span>中：表單編號誤植，下次改版一併修正</span></div>
      <div class="iss-card c-low"><b><?= $cntLow ?></b><span>低：清單漏列／名稱不一致／版面殘留</span></div>
    </div>

    <p style="font-size:12.5px;color:#8A6D45;margin:0 0 10px;">
      來源：比對「AS9100(各組維護版)」內 47 份現行版程序書、實體表單檔案、系統 AS 文件管理（<code>as_document</code>）三方交叉檢查。
      各課詳細說明見左側「課室說明文件」各篇末節。</p>

    <table class="iss-table" id="issTable">
      <thead><tr>
        <th style="width:58px;">優先</th><th style="width:130px;">課室</th><th style="width:190px;">文件／位置</th>
        <th>問題</th><th style="width:270px;">建議處理</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ISSUES as $r): ?>
        <tr data-lv="<?= $r[0] ?>" data-dept="<?= htmlspecialchars($r[1]) ?>">
          <td><span class="lv lv-<?= $r[0] ?>"><?= $r[0] ?></span></td>
          <td><?= htmlspecialchars($r[1]) ?></td>
          <td><strong><?= eg_doclink($r[2]) ?></strong></td>
          <td><?= htmlspecialchars($r[3]) ?></td>
          <td style="color:#7A4E17;"><?= htmlspecialchars($r[4]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═════════ 分頁：線上表單對照 ═════════ -->
<div id="tabOnl" style="display:none;">
  <div class="fg-main">
    <div class="fg-bar">
      <select id="onFilter" class="form-control input-sm" style="width:190px;height:30px;display:inline-block;">
        <option value="">全部（<?= count($FORMS) ?>）</option>
        <option value="1">已有線上表單（<?= $onlineCnt ?>）</option>
        <option value="0">尚未建立（<?= count($FORMS) - $onlineCnt ?>）</option>
      </select>
      <select id="onDept" class="form-control input-sm" style="width:170px;height:30px;display:inline-block;">
        <option value="">全部課室</option>
        <?php foreach (array_unique(array_column($FORMS, 'dept')) as $dp): ?>
          <option value="<?= htmlspecialchars($dp) ?>"><?= htmlspecialchars($dp) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" id="onKw" placeholder="搜尋表單編號／名稱…">
      <button id="btnOnClear"><i class="fa fa-eraser"></i> 清除</button>
      <a class="btnlink" href="as_form_list.php" target="_blank" rel="noopener"><i class="fa fa-plus"></i> 去建立線上表單</a>
      <span class="fg-file">顯示 <span id="onCount"><?= count($FORMS) ?></span> 筆</span>
    </div>

    <div class="iss-sum">
      <div class="iss-card c-mid"><b><?= $onlineCnt ?></b><span>已有線上表單，可直接點開填寫</span></div>
      <div class="iss-card c-low"><b><?= count($FORMS) - $onlineCnt ?></b><span>尚未建立線上表單（仍為紙本／Office 檔）</span></div>
      <?php $noFile = count(array_filter($FORMS, fn($d) => !$d['vid'] || !$d['file'])); ?>
      <div class="iss-card <?= $noFile ? 'c-high' : 'c-low' ?>"><b><?= $noFile ?></b><span>系統內尚未上傳文件檔、無法線上預覽<?= $noFile ? '' : '（全數可預覽）' ?></span></div>
    </div>

    <p style="font-size:12.5px;color:#8A6D45;margin:0 0 10px;">
      「已有線上表單」三種判定，缺一不可：
      ① AS 線上表單設計器已建立並綁定此文件（<code>as_form_template.form_doc_id</code>）；
      ② 已連結既有電子化模組（<code>as_document.linked_module</code>，如 CAR／品質異常單）；
      ③ <strong>此表單已由某個既有頁面實作並做了 AS 文件綁定</strong>（如供應商稽核管理的「AS文件綁定設定」、外來文件清單、報價單；
      綁定值存在 <code>system_settings</code> / <code>system_parameters</code>，登記表在本頁原始碼 <code>$PAGE_BINDS</code>）。
      <strong>預覽</strong>＝開啟系統內該文件現行版檔案（Office 自動轉 PDF）；右側按鈕＝另開分頁進入該線上表單／頁面。</p>

    <table class="of-table" id="onTable">
      <thead><tr>
        <th style="width:120px;">表單編號</th><th>表單名稱</th><th style="width:110px;">課室</th>
        <th style="width:110px;">母文件</th><th style="width:70px;">版次</th>
        <th style="width:150px;">線上表單</th><th style="width:250px;">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($FORMS as $no => $d):
            $on = ($d['tpl'] || $d['mod'] || $d['pgn']); ?>
        <tr class="<?= $on ? 'has-on' : '' ?>" data-on="<?= $on ? 1 : 0 ?>" data-dept="<?= htmlspecialchars($d['dept']) ?>">
          <td><strong><?= htmlspecialchars($no) ?></strong></td>
          <td><?= htmlspecialchars($d['name']) ?></td>
          <td><?= htmlspecialchars($d['dept']) ?></td>
          <td style="color:#8A6D45;"><?= htmlspecialchars($d['par'] ?: '—') ?></td>
          <td><?= htmlspecialchars($d['ver'] ?: '—') ?></td>
          <td><?php if ($d['tpl']): ?>
                <span class="on-yes"><i class="fa fa-bolt"></i> 線上表單<?= $d['tst'] === 'published' ? '（已發布）' : '（草稿）' ?></span>
              <?php elseif ($d['mod']): ?>
                <span class="on-yes"><i class="fa fa-cube"></i> <?= htmlspecialchars($d['mn']) ?></span>
              <?php elseif ($d['pgn']): ?>
                <span class="on-yes"><i class="fa fa-window-maximize"></i> 已綁定頁面</span>
                <div style="font-size:11px;color:#8A5A2B;margin-top:2px;"><?= htmlspecialchars($d['pgn']) ?></div>
              <?php else: ?><span class="on-no">尚未建立</span><?php endif; ?></td>
          <td>
            <button class="btn-mini pv-open" data-no="<?= htmlspecialchars($no) ?>"><i class="fa fa-eye"></i> 預覽</button>
            <a class="btn-mini" target="_blank" rel="noopener" href="as_document_management.php?kw=<?= urlencode($no) ?>" title="到 AS 文件管理定位此文件"><i class="fa fa-folder-open-o"></i> 文件管理</a>
            <?php if ($d['tpl']): ?>
              <a class="btn-mini warm" target="_blank" rel="noopener" href="as_form_render.php?template_id=<?= $d['tpl'] ?>"><i class="fa fa-external-link"></i> 開線上表單</a>
              <a class="btn-mini" target="_blank" rel="noopener" href="as_form_fill.php?template_id=<?= $d['tpl'] ?>"><i class="fa fa-pencil"></i> 新填一張</a>
            <?php elseif ($d['mod']): ?>
              <a class="btn-mini warm" target="_blank" rel="noopener" href="<?= htmlspecialchars($d['mu']) ?>"><i class="fa fa-external-link"></i> 前往模組頁</a>
            <?php elseif ($d['pgn']): ?>
              <a class="btn-mini warm" target="_blank" rel="noopener" href="<?= htmlspecialchars($d['pgu']) ?>"><i class="fa fa-external-link"></i> 開啟該頁面</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>
</div><!-- right_col -->
</div></div>

<!-- 角色權限說明 -->
<div class="fg-mask" id="roleMask"><div class="box">
  <h4>角色權限說明</h4>
  <table class="iss-table">
    <thead><tr><th style="width:110px;">角色</th><th>可做的事</th></tr></thead>
    <tbody>
      <tr><td><strong>管理者</strong></td><td>檢視全部課室說明文件、待處理問題清單、下載原始 MD 檔（固定擁有全部權限）</td></tr>
      <tr><td><strong>檢閱</strong></td><td>同上。本頁為唯讀說明頁，不提供線上編輯</td></tr>
      <tr><td>無權限</td><td>無法開啟本頁內容</td></tr>
    </tbody>
  </table>
  <p style="font-size:12.5px;color:#8A6D45;margin-top:10px;">
    本頁權限沿用「AS 文件管理」的 <code>as_doc</code> 模組角色與本頁 ACRUD 設定（有 A 或 R 即可檢視）。
    說明內容要修改，請直接編修 <code>FOR CODEING 說明文件/AS9100(各組維護版)/AS流程-*.md</code>，本頁即時反映。</p>
  <div style="text-align:right;"><button class="btn btn-sm btn-default" onclick="document.getElementById('roleMask').style.display='none'">關閉</button></div>
</div></div>

<!-- 使用說明（鐵律7：全站統一右上角按鈕＋跳窗） -->
<div class="fg-mask" id="helpUseMask"><div class="box">
  <h4><i class="fa fa-question-circle"></i> 使用說明 — AS 流程說明手冊</h4>
  <div class="help-doc">
    <h4>這頁是做什麼的</h4>
    <p>把各課室的 AS9100 <b>程序書流程、使用表單、跨課室協作點</b>整理成可線上閱讀的說明手冊，
       並標出<b>程序書與現況不一致的待處理問題</b>，以及每張表單<b>有沒有線上表單可用</b>。
       內容來源是 <code>FOR CODEING 說明文件\AS9100(各組維護版)\AS流程-*.md</code>，<b>改了 MD 檔本頁立刻反映</b>，不需要動程式。</p>

    <h4>三個分頁怎麼用</h4>
    <ul>
      <li><b>課室說明文件</b>：左側選課室，右側閱讀。可在本篇搜尋（Enter 逐筆跳）、看原始 MD、下載 MD、列印。</li>
      <li><b>待處理問題</b>：比對程序書／實體表單檔／系統文件三方交叉檢查出的不一致，分高中低三級，可依優先度、課室、關鍵字篩選。
          <b>點欄位裡的文件編號</b>會另開 AS 文件管理並自動篩選到該筆。</li>
      <li><b>線上表單對照</b>：全部四階表單一覽，顯示是否已有線上表單，可直接開啟或新填一張。</li>
    </ul>

    <h4>點編號會發生什麼事</h4>
    <ul>
      <li>內文中的文件／表單編號 → 開<b>線上預覽跳窗</b>（Office 檔自動轉 PDF），跳窗內還可另開分頁、到文件管理定位。</li>
      <li>標成<b>橘色粗體＋閃電</b>者代表<b>已有線上表單</b>，跳窗會直接給「另開線上表單／新填一張／開啟該頁面」。</li>
      <li>待處理問題表格內的編號 → 直接另開 <b>AS 文件管理</b>並篩選到該筆（不開預覽）。</li>
    </ul>

    <h4>「已有線上表單」是怎麼判定的</h4>
    <div class="tip">三種來源缺一不可：① AS 線上表單設計器已綁定此文件 ② 已連結電子化模組（CAR／品質異常單）
      ③ <b>此表單已由既有頁面實作並做了 AS 文件綁定</b>（如供應商稽核管理、外來文件清單、報價單）。
      第③類的登記表在本頁原始碼 <code>$PAGE_BINDS</code>——<b>日後有新的頁面綁定，要回來補一列</b>，否則會被誤判成「尚未建立」。</div>

    <h4>權限</h4>
    <p>沿用 AS 文件管理的 <code>as_doc</code> 模組角色與本頁 ACRUD：有 <b>A</b>／<b>R</b>／<code>asdoc_view</code> 即可檢視；管理者固定可看。本頁唯讀，不提供線上編輯。</p>
  </div>
  <div style="text-align:right;margin-top:10px;"><button class="btn btn-sm btn-default" onclick="document.getElementById('helpUseMask').style.display='none'">關閉</button></div>
</div></div>

<!-- 文件／表單 線上預覽跳窗 -->
<div class="fg-mask" id="pvMask"><div class="box">
  <div class="pv-head">
    <h4 id="pvTitle"></h4>
    <span class="pv-meta" id="pvMeta"></span>
    <button class="pv-close" id="pvClose" title="關閉">&times;</button>
  </div>
  <div class="pv-acts" id="pvActs"></div>
  <div id="pvBody"></div>
</div></div>

<button id="fgTop" title="回到頂端"><i class="fa fa-arrow-up"></i></button>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function () {
    $('#sidebar-menu').css('visibility', 'visible');   // 側欄恢復（CSS 隱藏必須配這行）

    // 分頁切換
    $('.fg-tab').on('click', function () {
        $('.fg-tab').removeClass('active'); $(this).addClass('active');
        var t = $(this).data('tab');
        $('#tabDoc').toggle(t === 'doc');
        $('#tabIss').toggle(t === 'iss');
        $('#tabOnl').toggle(t === 'onl');
    });

    // ══ 文件／表單 線上預覽 ══
    var DOCMAP = <?= json_encode($DOCMAP, JSON_UNESCAPED_UNICODE) ?>;
    var DOC_API = '../../src/store/AS_Document_API.php';
    function pvUrl(d) { return DOC_API + '?action=download&version_id=' + d.vid + '&inline=1'; }
    function esc(s) { return $('<i>').text(s == null ? '' : s).html(); }

    function openPreview(no) {
        var d = DOCMAP[no];
        if (!d) { alert('系統內查無此文件編號：' + no); return; }
        $('#pvTitle').html('<i class="fa fa-file-text-o"></i> ' + esc(no) + '　' + esc(d.name));
        $('#pvMeta').text([d.lv, d.ty, d.dept, d.ver ? '版次 ' + d.ver : '', d.par ? '母文件 ' + d.par : '']
                          .filter(Boolean).join('｜'));

        // 操作列：先放線上表單（有才顯示），再放文件預覽相關
        var a = [];
        if (d.tpl) {
            a.push('<span class="on-yes"><i class="fa fa-bolt"></i> 已有線上表單'
                 + (d.tst === 'published' ? '（已發布 v' + d.pv + '）' : '（草稿）') + '</span>');
            a.push('<a class="btn-mini warm" target="_blank" rel="noopener" href="as_form_render.php?template_id='
                 + d.tpl + '"><i class="fa fa-external-link"></i> 另開線上表單</a>');
            a.push('<a class="btn-mini" target="_blank" rel="noopener" href="as_form_fill.php?template_id='
                 + d.tpl + '"><i class="fa fa-pencil"></i> 新填一張</a>');
        } else if (d.mod) {
            a.push('<span class="on-yes"><i class="fa fa-cube"></i> 已電子化：' + esc(d.mn) + '</span>');
            a.push('<a class="btn-mini warm" target="_blank" rel="noopener" href="' + d.mu
                 + '"><i class="fa fa-external-link"></i> 前往模組頁</a>');
        } else if (d.pgn) {
            a.push('<span class="on-yes"><i class="fa fa-window-maximize"></i> 已綁定頁面：' + esc(d.pgn) + '</span>');
            a.push('<a class="btn-mini warm" target="_blank" rel="noopener" href="' + d.pgu
                 + '"><i class="fa fa-external-link"></i> 開啟該頁面</a>');
        } else {
            a.push('<span class="on-no">尚未建立線上表單</span>');
            a.push('<a class="btn-mini" target="_blank" rel="noopener" href="as_form_list.php"><i class="fa fa-plus"></i> 去建立</a>');
        }
        if (d.vid && d.file) {
            a.push('<a class="btn-mini" target="_blank" rel="noopener" href="' + pvUrl(d)
                 + '"><i class="fa fa-external-link"></i> 預覽另開分頁</a>');
        }
        a.push('<a class="btn-mini" target="_blank" rel="noopener" href="as_document_management.php?kw='
             + encodeURIComponent(no) + '"><i class="fa fa-folder-open-o"></i> 到 AS 文件管理（定位此文件）</a>');
        $('#pvActs').html(a.join(''));

        // 內容：有現行版檔案就 iframe 線上預覽（Office 由 API 轉 PDF）
        if (d.vid && d.file) {
            $('#pvBody').html('<iframe class="pv-frame" src="' + pvUrl(d) + '"></iframe>');
        } else {
            $('#pvBody').html('<div class="pv-empty"><i class="fa fa-file-o fa-2x"></i><br><br>'
                + '此文件在系統內<strong>尚未上傳現行版檔案</strong>，無法線上預覽。<br>'
                + '可到「AS 文件管理」以「改版／補檔」上傳後即可預覽。'
                + (d.tpl || d.mod ? '<br><br>（此表單已有線上表單，可用上方按鈕直接開啟）' : '') + '</div>');
        }
        $('#pvMask').show();
    }
    $(document).on('click', 'a.docchip', function (e) { e.preventDefault(); openPreview($(this).data('no')); });
    $(document).on('click', '.pv-open', function () { openPreview($(this).data('no')); });
    $('#pvClose').on('click', function () { $('#pvMask').hide(); $('#pvBody').empty(); });
    $('#pvMask').on('click', function (e) { if (e.target === this) { $(this).hide(); $('#pvBody').empty(); } });
    $(document).on('keydown', function (e) { if (e.which === 27) { $('#pvMask').hide(); $('#pvBody').empty(); } });

    // ── 線上表單對照篩選 ──
    function onFilterRows() {
        var on = $('#onFilter').val(), dp = $('#onDept').val(),
            kw = $.trim($('#onKw').val()).toLowerCase(), n = 0;
        $('#onTable tbody tr').each(function () {
            var $t = $(this),
                ok = (on === '' || String($t.data('on')) === on)
                  && (!dp || String($t.data('dept')) === dp)
                  && (!kw || $t.text().toLowerCase().indexOf(kw) >= 0);
            $t.toggle(ok); if (ok) { n++; }
        });
        $('#onCount').text(n);
    }
    $('#onFilter,#onDept').on('change', onFilterRows);
    $('#onKw').on('input', onFilterRows);
    $('#btnOnClear').on('click', function () { $('#onFilter,#onDept').val(''); $('#onKw').val(''); onFilterRows(); });

    // 內部 MD 連結 → 切換課室
    var NAME2KEY = <?= json_encode(array_combine(array_column($DOCS,1), array_keys($DOCS)), JSON_UNESCAPED_UNICODE) ?>;
    $(document).on('click', 'a.md-jump', function (e) {
        e.preventDefault();
        var k = NAME2KEY[$(this).data('name')];
        if (k) { location.href = '?doc=' + k; }
    });

    // ── 本篇搜尋（標記＋逐筆跳） ──
    var hits = [], hitIdx = -1;
    function clearHits() {
        $('#mdBody mark.hit').each(function () {
            var p = this.parentNode;
            $(this).replaceWith(document.createTextNode(this.textContent));
            p.normalize();
        });
        hits = []; hitIdx = -1;
    }
    function doFind() {
        var kw = $.trim($('#kw').val());
        clearHits();
        if (!kw) { return; }
        var re = new RegExp(kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
        // 只處理文字節點，避免破壞標籤
        var walker = document.createTreeWalker($('#mdBody')[0], NodeFilter.SHOW_TEXT, null, false);
        var targets = [], node;
        while ((node = walker.nextNode())) {
            if (node.nodeValue && re.test(node.nodeValue)) { targets.push(node); }
            re.lastIndex = 0;
        }
        targets.forEach(function (n) {
            var span = document.createElement('span');
            span.innerHTML = n.nodeValue.replace(/[&<>]/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];
            }).replace(re, '<mark class="hit">$&</mark>');
            n.parentNode.replaceChild(span, n);
        });
        hits = $('#mdBody mark.hit').toArray();
        if (!hits.length) { alert('本篇找不到「' + kw + '」'); return; }
        hitIdx = -1; nextHit();
    }
    function nextHit() {
        if (!hits.length) { return; }
        hitIdx = (hitIdx + 1) % hits.length;
        var el = hits[hitIdx];
        $('html,body').animate({ scrollTop: $(el).offset().top - 90 }, 200);
    }
    $('#btnFind').on('click', function () { hits.length ? nextHit() : doFind(); });
    $('#kw').on('keydown', function (e) {
        if (e.which === 13) { e.preventDefault(); hits.length ? nextHit() : doFind(); }
    }).on('input', function () { if (hits.length) { clearHits(); } });
    $('#btnClear').on('click', function () { $('#kw').val(''); clearHits(); });
    $('#btnPrint').on('click', function () { window.print(); });

    // ── 問題清單篩選 ──
    function issFilter() {
        var lv = $('#lvFilter').val(), dp = $('#deptFilter').val(),
            kw = $.trim($('#issKw').val()).toLowerCase(), n = 0;
        $('#issTable tbody tr').each(function () {
            var $t = $(this),
                ok = (!lv || $t.data('lv') === lv)
                  && (!dp || String($t.data('dept')) === dp)
                  && (!kw || $t.text().toLowerCase().indexOf(kw) >= 0);
            $t.toggle(ok); if (ok) { n++; }
        });
        $('#issCount').text(n);
    }
    $('#lvFilter,#deptFilter').on('change', issFilter);
    $('#issKw').on('input', issFilter);
    $('#btnIssClear').on('click', function () {
        $('#lvFilter,#deptFilter').val(''); $('#issKw').val(''); issFilter();
    });

    $('#btnRoleHelp').on('click', function () { $('#roleMask').show(); });
    $('#btnPageHelp').on('click', function () { $('#helpUseMask').show(); });
    $('.fg-mask').on('click', function (e) { if (e.target === this) { this.style.display = 'none'; } });

    $(window).on('scroll', function () { $('#fgTop').toggle($(window).scrollTop() > 250); });
    $('#fgTop').on('click', function () { $('html,body').animate({ scrollTop: 0 }, 250); });
});
</script>
</body>
</html>
