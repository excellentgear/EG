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

/** 組成一份附件的 HTML（A4；公司全名大標題、頁碼左下、AS 文件編號右下＝ai-rules/16；$asNo 空字串＝沒有對應表單不印編號） */
function mr_attach_html(PDO $db, string $title, string $sub, string $body, bool $landscape = false, string $asNo = ''): string {
    $co = htmlspecialchars(eg_company_full_name($db), ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); $s = htmlspecialchars($sub, ENT_QUOTES, 'UTF-8');
    $size = $landscape ? 'A4 landscape' : 'A4';
    $right = $asNo !== '' ? '@bottom-right{content:"' . str_replace('"', '', $asNo) . '";font-size:10px;color:#555}' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $t . '</title><style>'
        . '@page{size:' . $size . ';margin:14mm 12mm 16mm;@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:10px;color:#555}' . $right . '}'
        . 'body{font-family:"Microsoft JhengHei","PMingLiU",sans-serif;font-size:12px;color:#222;margin:0}'
        . 'h1{font-size:20px;text-align:center;margin:0 0 4px}h2{font-size:15px;text-align:center;margin:0 0 4px}'
        . '.sub{text-align:center;font-size:11px;color:#555;margin-bottom:10px}'
        . 'pre{white-space:pre-wrap;word-break:break-all;font-family:inherit;font-size:12.5px;line-height:1.8;margin:0}'
        . 'table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #777;padding:3px 4px;font-size:11px;text-align:center;word-break:break-all}'
        . 'thead{display:table-header-group}tr{page-break-inside:avoid}th{background:#F7E0BD}td.n{text-align:left}.bad{background:#FBE3DC;color:#B0341E;font-weight:bold}'
        . '</style></head><body><h1>' . $co . '</h1><h2>' . $t . '</h2><div class="sub">' . $s . '</div>' . $body . '</body></html>';
}

/** AS 文件（依編號）：[id, 名稱, 依會議日期回推當時版次的完整編號]；查不到回 null */
function mr_attach_doc(PDO $db, string $docNo, string $date): ?array {
    $st = $db->prepare("SELECT id, doc_name FROM as_document WHERE doc_no=? AND is_deleted=0 ORDER BY id LIMIT 1");
    $st->execute([$docNo]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return ['id' => (int)$r['id'], 'no' => $docNo, 'name' => (string)$r['doc_name'], 'full' => eg_asdoc_no_asof_id($db, (int)$r['id'], $date) ?: $docNo];
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

/** 系統資料來源的對應：來源代碼 => [主表單編號（標題與右下編號）, 無主表單時的標題, 橫式?]；來源所屬的其他表單取自 meeting_tpl_source_meta()（唯一登記處） */
function mr_attach_src_defs(): array {
    return [
        'kpi'    => ['2-GM-04-01', '', true],
        'ir'     => [null, '客戶退貨（客訴）統計', false],
        'vendor' => ['2-PH-01-05', '', false],
        'mfg'    => [null, '製造過程能力彙整', false],
        'car'    => ['2-QA-01-04', '', false],
        'ia'     => ['2-GM-06-08', '', false],
        'qa'     => ['2-QA-01-01', '', false],
        'risk'   => ['2-GM-03-01', '', false],
        'prev'   => [null, '前次管理審查決議事項', false],
    ];
}

/**
 * 依「管理審查會議範本」自動決定要附哪些資料（範本的項目掛了哪些系統資料來源、哪些 AS 表單，就附那些）：
 *   ①項目掛的系統資料來源（kpi／ir／mfg…）→ 各一份彙整（對應 mr_attach_src_defs）
 *   ②項目掛的 AS 表單：屬於某個系統資料來源的表單併入該來源那一份（例：稽核報告表→內稽統計）；
 *     其餘表單一律列出「該表單期間內的填寫紀錄」（需已做成系統頁面；沒有網頁的表單略過並說明）
 * 範本日後新增項目／換掛表單，附件就自動跟著變，不必改程式。
 * @return array [['type'=>'src'|'doc','key'=>來源代碼或 as_document.id]…]（依範本出現順序、不重複）
 */
function mr_attach_units(PDO $db): array {
    $tpl = null;
    foreach (meeting_tpl_list($db) as $t) if ($t['name'] === '管理審查會議') { $tpl = $t; break; }
    if (!$tpl) return [];
    $docSrc = [];   // 表單編號 => 來源代碼
    foreach (meeting_tpl_source_meta() as $k => $meta) foreach ($meta['docs'] as $no) $docSrc[$no] = $k;
    $byId = [];
    foreach ($db->query("SELECT id, doc_no FROM as_document WHERE is_deleted=0")->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['id']] = $r['doc_no'];
    $units = []; $seen = [];
    $add = function (string $type, $key) use (&$units, &$seen) { $k = $type . ':' . $key; if (isset($seen[$k])) return; $seen[$k] = 1; $units[] = ['type' => $type, 'key' => $key]; };
    foreach ($tpl['items'] as $it) {
        foreach (meeting_tpl_item_srcs($it) as $sr) if (isset(mr_attach_src_defs()[$sr['k']])) $add('src', $sr['k']);
        foreach (array_filter(array_map('intval', explode(',', (string)($it['as_docs'] ?? '')))) as $did) {
            $no = $byId[$did] ?? null; if ($no === null) continue;
            if (isset($docSrc[$no]) && isset(mr_attach_src_defs()[$docSrc[$no]])) $add('src', $docSrc[$no]); else $add('doc', $did);
        }
    }
    return $units;
}

/** 表單在期間內的填寫紀錄表（沒有專屬彙整的表單用）：回 [HTML, 筆數] */
function mr_attach_fill_table(PDO $db, int $docId, string $from, string $to): array {
    require_once __DIR__ . '/asdoc_record_lib.php';
    $rows = array_values(array_filter(eg_asdoc_fill_rows($db, $docId), function ($r) use ($from, $to) { $d = substr((string)($r['rec_date'] ?? ''), 0, 10); return $d !== '' && $d >= $from && $d <= $to; }));
    usort($rows, function ($a, $b) { return strcmp((string)$a['rec_date'], (string)$b['rec_date']); });
    if (!$rows) return ['', 0];
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $h = '<table><thead><tr><th style="width:80px">日期</th><th>名稱／單號</th><th style="width:70px">人員</th><th style="width:70px">狀態</th><th style="width:70px">來源</th></tr></thead><tbody>';
    foreach ($rows as $r) $h .= '<tr><td>' . $e(eg_fmt_date(substr((string)$r['rec_date'], 0, 10))) . '</td><td class="n">' . $e($r['title'] ?? '') . '</td><td>' . $e($r['person'] ?? '') . '</td><td>' . $e($r['status'] ?? '') . '</td><td>' . $e($r['src_name'] ?? '') . '</td></tr>';
    return [$h . '</tbody></table>', count($rows)];
}

/**
 * 這場會議要產生的附件清單：[[檔名, HTML]…]，另回報略過的項目（沒有資料不產生空白附件）。
 * 每一份對應 AS 表單：標題＝表單名稱、右下角印該表單依「會議日期」回推當時版次的編號（ai-rules/16）；
 * 沒有專屬表單的彙整（客戶退貨、製造過程能力…）不印編號，副標題寫明「對應表單：編號 名稱」。
 * @return array ['list'=>[[檔名,HTML]], 'skipped'=>[說明]]
 */
function mr_attach_build(PDO $db, string $date): array {
    require_once __DIR__ . '/asdoc_page_lib.php';
    [$y, $from, $to, $mo] = meeting_tpl_period($date);
    $base = $y . ' 年度資料（' . eg_fmt_date($from) . '～' . eg_fmt_date($to) . '）　會議日期 ' . eg_fmt_date($date);
    $src = function (string $k) use ($db, $date) { return meeting_tpl_source_text($db, $k, $date, ['full' => true]); };
    $empty = '/尚未建立|尚未設定 KPI|沒有納管且有進貨|系統內查無前次/u';
    $meta = meeting_tpl_source_meta(); $defs = mr_attach_src_defs();
    $pageMap = eg_asdoc_page_map($db);
    $list = []; $skipped = []; $n = 0;
    $push = function (string $docNo, string $name, array $rel, string $body, bool $land, string $asNo, bool $withYear) use (&$list, &$n, $db, $base, $y) {
        $n++;
        $sub = $base . ($rel ? '　對應表單：' . implode('、', $rel) : '') . '　（管理審查資料彙整）';
        $file = '管審附件_' . sprintf('%02d', $n) . '_' . ($asNo !== '' ? $asNo . ' ' : '') . $name . ($withYear ? '_' . $y . '年度' : '');
        $list[] = [$file, mr_attach_html($db, $name, $sub, $body, $land, $asNo)];
    };
    foreach (mr_attach_units($db) as $u) {
        if ($u['type'] === 'src') {
            $k = $u['key']; [$primary, $title, $land] = $defs[$k];
            $doc = $primary ? mr_attach_doc($db, $primary, $date) : null;
            $name = $doc ? $doc['name'] : $title;
            $body = $k === 'kpi' ? mr_attach_kpi_table($db, $y, $mo) : mr_attach_pre($src($k));
            if (in_array($k, ['vendor', 'risk', 'prev'], true) && preg_match($empty, strip_tags($body))) { $skipped[] = ($doc ? $doc['no'] . ' ' : '') . $name . '（期間內沒有資料）'; continue; }
            $rel = [];
            foreach (array_merge($primary ? [] : [], $meta[$k]['docs'] ?? []) as $rn) {
                if ($rn === $primary) continue;
                $d = mr_attach_doc($db, $rn, $date); if ($d && isset($pageMap[$d['id']])) $rel[] = $d['full'] . ' ' . $d['name'];
            }
            if ($k === 'prev') { $d = mr_attach_doc($db, '2-GM-05-01', $date); if ($d) $rel[] = $d['full'] . ' ' . $d['name']; }
            $push($primary ?? '', $name, $rel, $body, (bool)$land, $doc ? $doc['full'] : '', $k !== 'prev');
            continue;
        }
        // 單一 AS 表單（沒有專屬彙整）：需有系統頁面才附；客戶滿意度統計資料表有專屬內容
        $did = (int)$u['key'];
        $st = $db->prepare("SELECT doc_no FROM as_document WHERE id=?"); $st->execute([$did]);
        $no = (string)$st->fetchColumn(); $doc = $no !== '' ? mr_attach_doc($db, $no, $date) : null;
        if (!$doc) continue;
        if (!isset($pageMap[$did])) { $skipped[] = $doc['no'] . ' ' . $doc['name'] . '（尚未做成系統頁面，無資料可彙整）'; continue; }
        if ($no === '2-SM-02-03') { $body = mr_attach_pre(mr_attach_satis_text($db, $y, $mo, $date)); $cnt = preg_match('/尚未建立/u', $body) ? 0 : 1; }
        else [$body, $cnt] = mr_attach_fill_table($db, $did, $from, $to);
        if (!$cnt) { $skipped[] = $doc['no'] . ' ' . $doc['name'] . '（期間內沒有資料）'; continue; }
        $push($no, $doc['name'], [], $body, false, $doc['full'], true);
    }
    return ['list' => $list, 'skipped' => $skipped];
}

/** 客戶滿意度統計資料表（2-SM-02-03）：KPI「客戶滿意度調查」逐月分數＋期間內填寫紀錄份數；都沒有資料回說明字串 */
function mr_attach_satis_text(PDO $db, int $y, int $mo, string $date): string {
    $rows = array_values(array_filter(meeting_tpl_kpi_rows($db, $y, $mo), function ($r) { return mb_strpos($r['name'], '滿意度') !== false && $r['months']; }));
    $d = mr_attach_doc($db, '2-SM-02-03', $date);
    $fill = $d ? meeting_tpl_doc_fill_text($db, $d['id'], $date) : '';
    $none = !$rows && (!$fill || preg_match('/共 0 份/u', $fill));
    if ($none) return '尚未建立：期間內沒有客戶滿意度資料。';
    $L = [$y . ' 年度客戶滿意度：'];
    foreach ($rows as $r) { $p = []; foreach ($r['months'] as $m => $v) $p[] = "{$m}月 " . (float)$v . $r['unit']; $L[] = "・KPI「{$r['name']}」（目標 {$r['tg']}）：" . implode('、', $p); }
    if ($fill) $L[] = '・' . preg_replace('/^【系統資料】/u', '', $fill);
    return implode("\n", $L);
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
        $bld = mr_attach_build($db, (string)$m['meeting_date']);
        foreach ($bld['list'] as [$name, $html]) {
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
        return ['ok' => true, 'files' => array_map(function ($x) { return $x[0] . '.pdf'; }, $made), 'failed' => $failed, 'skipped' => $bld['skipped'], 'removed' => count($oldRows)];
    } finally {
        eg_att_rrmdir($tmp);
    }
}
