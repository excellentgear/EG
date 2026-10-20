<?php
/**
 * 管理審查會議「匯入管審附件資料」（2026-10-20 使用者要求）：
 * 套用範本後，把相關系統資料（KPI 逐月達成、客訴退貨、供應商評核、製造過程能力、不符合與異常統計、風險與機會、前次決議）
 * 各自轉成 PDF，自動成為這場會議的附件。項目內的系統資料只留「未達標／重點」摘要，完整明細放在這些 PDF。
 * 資料口徑與項目內文字完全相同（同一批 meeting_tpl_* 函式，full=true 取完整版），不另算一次（鐵律4）。
 * 轉 PDF 走 attachment_lib 的 eg_att_browser_pdf()（無頭瀏覽器，已在站上用於 Office 轉檔備援）。
 * 重複匯入＝先刪掉上一次系統產生的附件（attach_type 相同者）再重建，人工上傳的附件一律不動。
 */
require_once __DIR__ . '/meeting_tpl_lib.php';
require_once __DIR__ . '/attachment_lib.php';
require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';

const MR_ATTACH_TYPE = '管審附件（系統產生）';

/** 組成一份附件的 HTML（A4；頁碼左下，公司全名大標題＝ai-rules/16） */
function mr_attach_html(PDO $db, string $title, string $sub, string $body, bool $landscape = false): string {
    $co = htmlspecialchars(eg_company_full_name($db), ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); $s = htmlspecialchars($sub, ENT_QUOTES, 'UTF-8');
    $size = $landscape ? 'A4 landscape' : 'A4';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $t . '</title><style>'
        . '@page{size:' . $size . ';margin:14mm 12mm 16mm;@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:10px;color:#555}}'
        . 'body{font-family:"Microsoft JhengHei","PMingLiU",sans-serif;font-size:12px;color:#222;margin:0}'
        . 'h1{font-size:20px;text-align:center;margin:0 0 4px}h2{font-size:15px;text-align:center;margin:0 0 4px}'
        . '.sub{text-align:center;font-size:11px;color:#555;margin-bottom:10px}'
        . 'pre{white-space:pre-wrap;word-break:break-all;font-family:inherit;font-size:12.5px;line-height:1.8;margin:0}'
        . 'table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #777;padding:3px 4px;font-size:11px;text-align:center;word-break:break-all}'
        . 'thead{display:table-header-group}tr{page-break-inside:avoid}th{background:#F7E0BD}td.n{text-align:left}.bad{background:#FBE3DC;color:#B0341E;font-weight:bold}'
        . '</style></head><body><h1>' . $co . '</h1><h2>' . $t . '</h2><div class="sub">' . $s . '</div>' . $body . '</body></html>';
}

/** 純文字 → 版面（保留換行） */
function mr_attach_pre(string $txt): string {
    return '<pre>' . htmlspecialchars(trim(preg_replace('/^【系統資料】/mu', '', $txt)), ENT_QUOTES, 'UTF-8') . '</pre>';
}

/** KPI 逐月達成表：未達標月份標紅 */
function mr_attach_kpi_table(PDO $db, int $y, int $mo): string {
    $rows = meeting_tpl_kpi_rows($db, $y, $mo);
    if (!$rows) return '<pre>' . $y . ' 年度尚未設定 KPI 指標。</pre>';
    $h = '<table><thead><tr><th style="width:150px">指標</th><th style="width:70px">目標</th>';
    for ($m = 1; $m <= 12; $m++) $h .= '<th>' . $m . '月</th>';
    $h .= '<th style="width:50px">未達標月數</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td class="n">' . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($r['tg'], ENT_QUOTES, 'UTF-8') . '</td>';
        for ($m = 1; $m <= 12; $m++) {
            if (!isset($r['months'][$m])) { $h .= '<td></td>'; continue; }
            $h .= '<td' . (isset($r['miss'][$m]) ? ' class="bad"' : '') . '>' . (float)$r['months'][$m] . '</td>';
        }
        $h .= '<td>' . ($r['miss'] ? count($r['miss']) : ($r['months'] ? '0' : '—')) . '</td></tr>';
    }
    return $h . '</tbody></table><div class="sub" style="margin-top:6px;text-align:left">紅底＝該月未達標；空白＝尚無數據。單位依各指標目標。</div>';
}

/** 這場會議要產生的附件清單：[[檔名, HTML]…]（尚未轉 PDF） */
function mr_attach_build(PDO $db, string $date): array {
    [$y, $from, $to, $mo] = meeting_tpl_period($date);
    $sub = $y . ' 年度資料（' . eg_fmt_date($from) . '～' . eg_fmt_date($to) . '）　會議日期 ' . eg_fmt_date($date) . '　系統產生日期 ' . eg_fmt_date(date('Y-m-d'));
    $src = function (string $k, array $o = []) use ($db, $date) { return meeting_tpl_source_text($db, $k, $date, $o + ['full' => true]); };
    $list = [];
    $list[] = ["管審附件_01_KPI逐月達成狀況_{$y}年度", mr_attach_html($db, 'KPI 逐月達成狀況', $sub, mr_attach_kpi_table($db, $y, $mo), true)];
    $list[] = ["管審附件_02_客戶退貨（客訴）統計_{$y}年度", mr_attach_html($db, '客戶退貨（客訴）統計', $sub, mr_attach_pre($src('ir')))];
    $list[] = ["管審附件_03_供應商定期評核_{$y}年度", mr_attach_html($db, '供應商定期評核結果', $sub, mr_attach_pre($src('vendor')))];
    $list[] = ["管審附件_04_製造過程能力_{$y}年度", mr_attach_html($db, '製造過程能力（報工不良率／QC 檢驗／不良率 KPI）', $sub, mr_attach_pre($src('mfg')))];
    $list[] = ["管審附件_05_不符合與異常統計_{$y}年度", mr_attach_html($db, '不符合事項與異常統計（內部稽核／矯正單／品質異常）', $sub,
        mr_attach_pre($src('ia') . "\n\n" . $src('car') . "\n\n" . $src('qa')))];
    $list[] = ["管審附件_06_風險與機會因應措施_{$y}年度", mr_attach_html($db, '風險與機會因應措施（措施與成效）', $sub, mr_attach_pre($src('risk')))];
    $list[] = ["管審附件_07_前次管理審查決議事項", mr_attach_html($db, '前次管理審查決議事項（執行情形追蹤）', $sub, mr_attach_pre($src('prev')))];
    return $list;
}

/**
 * 產生並登錄附件。$dir＝會議附件目錄（含結尾斜線，呼叫端用 meeting_attach_dir()）。
 * @return array ['ok'=>bool,'files'=>[檔名…],'failed'=>[檔名…],'removed'=>舊的系統附件數]
 */
function mr_attach_import(PDO $db, array $m, int $uid, string $uname, string $dir): array {
    @set_time_limit(300);
    $mid = (int)$m['meeting_id'];
    if (!eg_attach_ensure_dir($dir)) return ['ok' => false, 'error' => '無法建立附件目錄：' . $dir];
    $tmp = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'mrattach_' . bin2hex(random_bytes(5));
    @mkdir($tmp, 0777, true);
    $made = []; $failed = [];
    try {
        foreach (mr_attach_build($db, (string)$m['meeting_date']) as [$name, $html]) {
            $hp = $tmp . DIRECTORY_SEPARATOR . 'a' . count($made) . count($failed) . '.html';
            $pp = $tmp . DIRECTORY_SEPARATOR . 'a' . count($made) . count($failed) . '.pdf';
            file_put_contents($hp, $html);
            if (eg_att_browser_pdf($hp, $pp, 90) && is_file($pp)) $made[] = [$name, $pp]; else $failed[] = $name;
        }
        if (!$made) return ['ok' => false, 'error' => '轉 PDF 失敗（伺服器找不到可用的瀏覽器或轉檔逾時）', 'failed' => $failed];
        // 先刪上一次系統產生的附件（人工上傳的不動），再寫入新的（同一個交易，檔案最後才落地）
        $old = $db->prepare("SELECT attach_id, file_name FROM meeting_attach WHERE meeting_id=? AND attach_type=? AND status='active'");
        $old->execute([$mid, MR_ATTACH_TYPE]);
        $oldRows = $old->fetchAll(PDO::FETCH_ASSOC);
        $ins = $db->prepare("INSERT INTO meeting_attach (meeting_id, file_name, original_name, attach_type, file_size, status, created_by, created_by_name) VALUES (?,?,?,?,?,'active',?,?)");
        $newFiles = [];
        $db->beginTransaction();
        try {
            foreach ($oldRows as $o) $db->prepare("DELETE FROM meeting_attach WHERE attach_id=?")->execute([(int)$o['attach_id']]);
            foreach ($made as [$name, $pp]) {
                $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.pdf';
                if (!@copy($pp, $dir . $fname)) throw new RuntimeException('附件存檔失敗（請確認附件路徑權限）');
                $newFiles[] = $fname;
                $ins->execute([$mid, $fname, $name . '.pdf', MR_ATTACH_TYPE, (int)filesize($pp), $uid, $uname]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            foreach ($newFiles as $f) @unlink($dir . $f);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        foreach ($oldRows as $o) { $fp = $dir . basename((string)$o['file_name']); if (is_file($fp)) @unlink($fp); }
        return ['ok' => true, 'files' => array_map(function ($x) { return $x[0] . '.pdf'; }, $made), 'failed' => $failed, 'removed' => count($oldRows)];
    } finally {
        eg_att_rrmdir($tmp);
    }
}
