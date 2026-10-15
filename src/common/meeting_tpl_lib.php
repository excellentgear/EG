<?php
/**
 * 會議範本（2026-10-15 使用者交辦：管理審查會議要能預先設好制式內容與需填入的項目）
 *
 * 範本 meeting_tpl：主題／地點／時間＋會議通知單的「會議要項」文字與應出席單位。
 *   src_doc_id／src_prefixes：範本的項目條文取自哪一份「AS 文件線上版」（管理審查＝2-GM-05 的 6.1.4／6.1.5），
 *   線上版更動時偵測並提醒、可一鍵依線上版更新（meeting_tpl_sync_status／meeting_tpl_sync_apply）。
 * 範本項目 meeting_tpl_item：套用後會長成會議記錄裡的項目（kind＝directive/general/announce），
 *   content 是制式文字，可含「【待填：提示】」標記＝需要記錄人填入的內容（送出前一律擋下沒填的）；
 *   clause／title_text：對應線上版的條文編號與當時的條文文字（用來偵測線上版是否更動）；
 *   as_docs：這個項目對應的 AS 表單（可多選，as_document.id 逗號分隔）——有登記系統資料的表單會自動帶資料，
 *            其餘表單帶「這份表單期間內的填寫紀錄份數」；
 *   src_json：額外指定的系統資料 [{"k":"kpi","ids":[5]},{"k":"ir"}…]（kpi 可指定到某幾項指標）。
 * 系統資料來源一律登記在 meeting_tpl_sources()／meeting_tpl_source_meta()，前端清單與後端計算都從這裡取（鐵律4）。
 */

if (!defined('MEETING_TPL_BLANK_RE')) {
    // 沒填的待填標記：【待填：xxx】（全形括號）
    define('MEETING_TPL_BLANK_RE', '/【待填[：:][^】]*】/u');
}

function meeting_tpl_ensure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_tpl (
        tpl_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL COMMENT '範本名稱',
        subject VARCHAR(100) NOT NULL COMMENT '帶入的會議主題',
        location VARCHAR(100) NULL,
        start_time VARCHAR(5) NULL,
        end_time VARCHAR(5) NULL,
        notice_items TEXT NULL COMMENT '會議通知單的會議要項文字',
        notice_depts VARCHAR(300) NULL COMMENT '應出席單位 department.id 逗號分隔',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議範本'");
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_tpl_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tpl_id INT NOT NULL,
        kind VARCHAR(15) NOT NULL DEFAULT 'general' COMMENT 'directive/general/announce',
        sort_order INT NOT NULL DEFAULT 0,
        content TEXT NOT NULL,
        owner_depts VARCHAR(200) NULL COMMENT '預設負責部門 department.id 逗號分隔',
        remark VARCHAR(200) NULL,
        data_src VARCHAR(30) NULL COMMENT '（舊）單一系統資料來源；新版改用 src_json',
        KEY idx_tpl (tpl_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議範本項目'");
    // 2026-10-15 新增欄位（先查再加；MySQL 的 ADD COLUMN IF NOT EXISTS 不是每個版本都有）
    $need = [
        ['meeting_tpl', 'src_doc_id',   "INT NULL COMMENT '項目條文取自哪份 AS 文件（as_document.id）'"],
        ['meeting_tpl', 'src_prefixes', "VARCHAR(200) NULL COMMENT '取哪些條文：前綴:kind，逗號分隔，例 6.1.4:general,6.1.5:directive'"],
        ['meeting_tpl', 'src_ver_id',   "INT NULL COMMENT '最近一次依線上版同步時的版次 as_document_version.id'"],
        ['meeting_tpl', 'src_synced_at', "DATETIME NULL"],
        ['meeting_tpl_item', 'clause',     "VARCHAR(40) NULL COMMENT '對應線上版的條文編號'"],
        ['meeting_tpl_item', 'title_text', "VARCHAR(600) NULL COMMENT '同步當時的條文文字（用來偵測線上版是否更動）'"],
        ['meeting_tpl_item', 'as_docs',    "VARCHAR(300) NULL COMMENT '對應 AS 表單 as_document.id 逗號分隔（可多選）'"],
        ['meeting_tpl_item', 'src_json',   "TEXT NULL COMMENT '系統資料 [{k,ids}]'"],
    ];
    foreach ($need as [$t, $c, $def]) {
        try {
            $st = $db->prepare("SHOW COLUMNS FROM `$t` LIKE ?");
            $st->execute([$c]);
            if ($st->fetchColumn() === false) $db->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        } catch (Throwable $e) { error_log('[meeting_tpl_ensure] ' . $e->getMessage()); }
    }
}

/** 可帶入的系統資料來源：代碼 => 顯示名稱（唯一登記處） */
function meeting_tpl_sources(): array {
    return [
        'kpi'    => '關鍵績效指標（KPI）達成狀況',
        'ir'     => '客戶退貨（抱怨）統計',
        'vendor' => '供應商定期評核結果',
        'mfg'    => '製造過程能力（報工不良率／QC 檢驗不良）',
        'car'    => '異常矯正處理單（矯正單）統計',
        'ia'     => '內部稽核不符合事項統計',
        'qa'     => '品質異常處理單統計（含不良品成本）',
    ];
}

/**
 * 來源的附加資訊：param＝需要再指定的參數（kpi＝挑指標）；docs＝這些 AS 文件編號掛上去就自動帶這個來源的資料。
 * 沒登記在這裡的 AS 表單，一律只帶「期間內填寫紀錄份數」（見 meeting_tpl_doc_fill_text）。
 */
function meeting_tpl_source_meta(): array {
    return [
        'kpi'    => ['param' => 'kpi', 'docs' => ['2-GM-04-01']],
        'ir'     => ['param' => null,  'docs' => []],
        'vendor' => ['param' => null,  'docs' => ['2-PH-01-05', '2-PH-01-04']],
        'mfg'    => ['param' => null,  'docs' => ['2-QA-01-02', '2-QA-01-06', '2-QA-01-09']],
        'car'    => ['param' => null,  'docs' => ['2-QA-01-04']],
        'ia'     => ['param' => null,  'docs' => ['2-GM-06-01', '2-GM-06-02', '2-GM-06-03', '2-GM-06-04', '2-GM-06-06', '2-GM-06-07', '2-GM-06-08']],
        'qa'     => ['param' => null,  'docs' => ['2-QA-01-01', '2-QA-01-03']],
    ];
}

/** doc_no => 來源代碼（給前端標示「這份表單會自動帶資料」） */
function meeting_tpl_doc_map(): array {
    $m = [];
    foreach (meeting_tpl_source_meta() as $k => $meta) foreach ($meta['docs'] as $no) $m[$no] = $k;
    return $m;
}

/** 供項目挑選的 AS 文件（只列四階表單與其他有編號的文件，依編號排序） */
function meeting_tpl_doc_options(PDO $db): array {
    $rows = $db->query("SELECT id, doc_no, doc_name FROM as_document WHERE doc_no REGEXP '^[0-9]-[A-Z]+-[0-9]+-[0-9]+' ORDER BY doc_no, id")->fetchAll(PDO::FETCH_ASSOC);
    $seen = []; $out = [];
    foreach ($rows as $r) { $k = $r['doc_no'] . '|' . $r['id']; if (isset($seen[$k])) continue; $seen[$k] = 1; $out[] = ['id'=>(int)$r['id'], 'doc_no'=>$r['doc_no'], 'doc_name'=>$r['doc_name']]; }
    return $out;
}

/**
 * 會議要審查的期間：每年 1～2 月召開的（程序書 6.1.1：每年 1 月）審查的是「上一個完整年度」，
 * 其餘月份＝本年度 1/1 到會議日。回傳 [年, 起日, 迄日, 截至月]。
 */
function meeting_tpl_period(string $meetingDate): array {
    $ts = strtotime($meetingDate) ?: time();
    $y = (int)date('Y', $ts); $mo = (int)date('n', $ts);
    if ($mo <= 2) { $y--; return [$y, sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y), 12]; }
    return [$y, sprintf('%04d-01-01', $y), date('Y-m-d', $ts), $mo];
}

/** 依代碼與會議日期算出一段文字（失敗時回說明，不丟例外，免得整個套用失敗）。$opt['ids']＝kpi 指定的指標 id */
function meeting_tpl_source_text(PDO $db, string $src, string $meetingDate, array $opt = []): string {
    [$y, $from, $to, $mo] = meeting_tpl_period($meetingDate);
    $hdr = "{$y}年度（{$from}～{$to}）";
    try {
        switch ($src) {
            case 'kpi': return meeting_tpl_text_kpi($db, $y, $mo, array_map('intval', (array)($opt['ids'] ?? [])));
            case 'ir':
                include_once __DIR__ . '/ir_stats_lib.php';
                return irs_text($db, $from, $to);
            case 'vendor': return meeting_tpl_text_vendor($db, $y);
            case 'mfg':    return meeting_tpl_text_mfg($db, $y, $from, $to, $mo);
            case 'car':
                $r = $db->prepare("SELECT status, COUNT(*) n FROM car_order WHERE fill_date BETWEEN ? AND ? GROUP BY status");
                $r->execute([$from, $to]);
                $m = $r->fetchAll(PDO::FETCH_KEY_PAIR);
                $tot = array_sum($m); $cl = (int)($m['closed'] ?? 0);
                return "【系統資料】{$hdr}：矯正單共 {$tot} 件，已結案 {$cl} 件，未結案 " . ($tot - $cl) . ' 件。';
            case 'ia':
                $r = $db->prepare("SELECT stage, COUNT(*) n FROM ia_nc WHERE is_deleted=0 AND audit_date BETWEEN ? AND ? GROUP BY stage");
                $r->execute([$from, $to]);
                $m = $r->fetchAll(PDO::FETCH_KEY_PAIR);
                $tot = array_sum($m); $cl = (int)($m['closed'] ?? 0);
                return "【系統資料】{$hdr}：內部稽核不符合事項共 {$tot} 件，已結案 {$cl} 件，未結案 " . ($tot - $cl) . ' 件。';
            case 'qa':
                $r = $db->prepare("SELECT COUNT(*) n, SUM(is_closed=1) c FROM qa_abnormal_order WHERE deleted_at IS NULL AND occurrence_date BETWEEN ? AND ?");
                $r->execute([$from, $to]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                $tot = (int)($row['n'] ?? 0); $cl = (int)($row['c'] ?? 0);
                $s = "【系統資料】{$hdr}：品質異常處理單共 {$tot} 件，已結案 {$cl} 件，未結案 " . ($tot - $cl) . ' 件。';
                // 不良品成本（COPQ）：已人工確認採用的扣款金額（qa_abnormal_deduct.included=1），與品質異常分析頁同一口徑
                try {
                    $c = $db->prepare("SELECT COALESCE(SUM(d.amount),0) FROM qa_abnormal_deduct d JOIN qa_abnormal_order o ON o.id=d.order_id
                                       WHERE o.deleted_at IS NULL AND d.included=1 AND o.occurrence_date BETWEEN ? AND ?");
                    $c->execute([$from, $to]);
                    $s .= "\n・不良品成本（已確認採用的扣款金額合計）：" . number_format((float)$c->fetchColumn()) . ' 元。';
                } catch (Throwable $e) {}
                return $s;
        }
    } catch (Throwable $e) {
        error_log('[meeting_tpl_source_text ' . $src . '] ' . $e->getMessage());
        return '【系統資料】（' . (meeting_tpl_sources()[$src] ?? $src) . '取得失敗，請手動填入）';
    }
    return '';
}

function meeting_tpl_text_kpi(PDO $db, int $y, int $mo, array $ids = []): string {
    include_once __DIR__ . '/kpi_as_lib.php';
    $sql = "SELECT i.indicator_id, i.name, y.target_direction, y.target_value, y.target_unit, y.target_text
              FROM kpi_as_indicator i JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
             WHERE i.is_active=1 AND y.is_active=1";
    $args = [$y];
    if ($ids) { $sql .= ' AND i.indicator_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $args = array_merge($args, $ids); }
    $st = $db->prepare($sql . ' ORDER BY i.sort_order, i.indicator_id');
    $st->execute($args);
    $inds = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$inds) return "【系統資料】{$y}年度尚未設定 KPI 指標" . ($ids ? '（或所選指標未啟用）' : '') . '。';
    $mq = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month<=? ORDER BY month DESC");
    $lines = []; $bad = 0;
    foreach ($inds as $iy) {
        $mq->execute([(int)$iy['indicator_id'], $y, $mo]);
        $all = $mq->fetchAll(PDO::FETCH_ASSOC);
        $val = null; $mm = null; $trend = [];
        foreach ($all as $mv) {
            $v = kpi_as_display_value($mv);
            if ($v === null) continue;
            if ($val === null) { $val = $v; $mm = (int)$mv['month']; }
            $trend[(int)$mv['month']] = $v;
        }
        $dir = ['lte'=>'≤', 'yes'=>'', 'gte'=>'≥'][$iy['target_direction']] ?? '≥';
        $unit = (string)($iy['target_unit'] ?? '');
        $tg = $iy['target_value'] !== null ? ($dir . (float)$iy['target_value'] . $unit) : (string)($iy['target_text'] ?? '');
        if ($val === null) { $lines[] = "・{$iy['name']}：尚無數據（目標 {$tg}）"; continue; }
        $below = kpi_as_below_target($val, $iy);
        if ($below) $bad++;
        $line = "・{$iy['name']}：{$mm}月 " . (float)$val . $unit . "（目標 {$tg}）" . ($below ? '【未達標】' : '達標');
        if ($ids && $trend) {   // 只挑了特定指標時，順便列出各月走勢
            ksort($trend); $p = [];
            foreach ($trend as $m => $v) $p[] = "{$m}月 " . (float)$v . $unit;
            $line .= "\n　各月：" . implode('、', $p);
        }
        $lines[] = $line;
    }
    return "【系統資料】{$y}年度 KPI 截至 {$mo} 月（未達標 {$bad} 項）：\n" . implode("\n", $lines);
}

/** 供應商定期評核（與供應商稽核頁的定期評核同一支 vendor_periodic_eval，不另算） */
function meeting_tpl_text_vendor(PDO $db, int $y): string {
    include_once __DIR__ . '/vendor_audit_lib.php';
    $lines = []; $tot = 0; $fail = 0;
    foreach (['outsource', 'purchase'] as $sc) {
        $set = vendor_eval_settings($db, $sc);
        $mk = $db->query("SELECT maker_id_no, maker_id FROM maker_list m
                           WHERE (m.status IS NULL OR m.status<>'" . VENDOR_AUDIT_DISABLED . "') AND m.audit_managed=1
                             AND " . vendor_audit_scope_sql_cond($sc, 'm') . " ORDER BY m.maker_id")->fetchAll(PDO::FETCH_ASSOC);
        $n = 0; $f = []; $okc = 0;
        foreach ($mk as $m) {
            $res = vendor_periodic_eval($db, $m['maker_id_no'], $y, $set);
            if (($res['halves'][1]['in_qty'] + $res['halves'][2]['in_qty']) === 0) continue;   // 整年無進貨→略過
            $n++;
            if (($res['full']['judge'] ?? '') === 'fail') { $f[] = $m['maker_id'] . ' ' . ($res['full']['score'] ?? '?') . '分' . ($res['full']['grade'] ?? ''); }
            else $okc++;
        }
        if ($n === 0) continue;
        $tot += $n; $fail += count($f);
        $lines[] = '・' . vendor_audit_scope_label($sc) . "：評核 {$n} 家，合格 {$okc} 家，不合格 " . count($f) . ' 家' . ($f ? '（' . implode('、', $f) . '）' : '');
    }
    if (!$lines) return "【系統資料】{$y}年度供應商定期評核：沒有納管且有進貨紀錄的廠商。";
    return "【系統資料】{$y}年度供應商定期評核（上下半年總分平均）：共評核 {$tot} 家、不合格 {$fail} 家。\n" . implode("\n", $lines);
}

/** 製造過程能力：製程報工不良率（依製程類別）＋回廠 QC 檢驗不良＋成品出貨不良（取 KPI 月值） */
function meeting_tpl_text_mfg(PDO $db, int $y, string $from, string $to, int $mo): string {
    $L = ["【系統資料】{$y}年度（{$from}～{$to}）製造過程能力："];
    // ① 製程報工不良率
    $st = $db->prepare("SELECT COALESCE(pt.process_type, '（未分類）') t, SUM(r.produced_qty) q, COALESCE(SUM(g.ng),0) ng
                          FROM pm_process_daily_report r
                          JOIN process_no pn ON pn.ProcessNo = r.process_no
                          LEFT JOIN process_type pt ON pt.process_type_id = pn.process_type_id
                          LEFT JOIN (SELECT report_id, SUM(ng_qty) ng FROM pm_process_daily_ng GROUP BY report_id) g ON g.report_id = r.report_id
                         WHERE r.report_date BETWEEN ? AND ?
                         GROUP BY t HAVING q > 0 ORDER BY (COALESCE(SUM(g.ng),0)/SUM(r.produced_qty)) DESC");
    $st->execute([$from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $tq = 0; $tn = 0; $p = [];
        foreach ($rows as $r) { $tq += (float)$r['q']; $tn += (float)$r['ng']; $p[] = "{$r['t']} " . (0 + $r['ng']) . '/' . (0 + $r['q']) . '＝' . round(100 * $r['ng'] / $r['q'], 2) . '%'; }
        $L[] = '・製程報工不良率：全廠 ' . (0 + $tn) . '/' . (0 + $tq) . '＝' . round(100 * $tn / max(1, $tq), 2) . '%（不良數/完成數）';
        $L[] = '　各製程類別（由高至低）：' . implode('、', array_slice($p, 0, 8));
        // 不良原因前 5
        $rs = $db->prepare("SELECT COALESCE(nt.ng_txt,'（未填原因）') c, SUM(g.ng_qty) n
                              FROM pm_process_daily_ng g JOIN pm_process_daily_report r ON r.report_id=g.report_id
                              LEFT JOIN ng_txt nt ON nt.ng_id=g.ng_id
                             WHERE r.report_date BETWEEN ? AND ? GROUP BY c ORDER BY n DESC LIMIT 5");
        $rs->execute([$from, $to]);
        $pp = []; foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) $pp[] = $r['c'] . ' ' . (0 + $r['n']);
        if ($pp) $L[] = '　主要不良原因（前5）：' . implode('、', $pp);
    } else $L[] = '・製程報工不良率：期間內沒有報工資料。';
    // ② 回廠 QC 檢驗
    $q = $db->prepare("SELECT QC_check, COUNT(*) n FROM bom_ing WHERE QC_check_date BETWEEN ? AND ? AND QC_check IS NOT NULL AND QC_check<>'' GROUP BY QC_check");
    $q->execute([$from, $to]);
    $m = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $tot = array_sum($m);
    if ($tot > 0) {
        $ng = (int)($m['ng'] ?? 0); $qq = (int)($m['QQ'] ?? 0); $aod = (int)($m['AOD'] ?? 0);
        $L[] = "・回廠 QC 檢驗：共 {$tot} 筆，驗退 {$ng} 筆（" . round(100 * $ng / $tot, 2) . "%）" . ($qq ? "、部分不良 {$qq} 筆" : '') . ($aod ? "、特採 {$aod} 筆" : '') . '。';
    } else $L[] = '・回廠 QC 檢驗：期間內沒有檢驗資料。';
    // ③ KPI 上已設定的不良率指標（目標對照）
    include_once __DIR__ . '/kpi_as_lib.php';
    $ks = $db->prepare("SELECT i.indicator_id, i.name, y.target_direction, y.target_value, y.target_unit
                          FROM kpi_as_indicator i JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                         WHERE i.is_active=1 AND y.is_active=1 AND y.calculator_key IN ('process_ng_rate','incoming_ng_rate','packing_ng_rate')
                         ORDER BY i.sort_order");
    $ks->execute([$y]);
    $mq = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month<=? ORDER BY month");
    foreach ($ks->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $mq->execute([(int)$k['indicator_id'], $y, $mo]);
        $num = 0; $den = 0; $p = [];
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $mv) {
            $v = kpi_as_display_value($mv);
            if ($mv['numerator'] !== null) $num += (float)$mv['numerator'];
            if ($mv['denominator'] !== null) $den += (float)$mv['denominator'];
            if ($v !== null) $p[] = (int)$mv['month'] . '月 ' . (float)$v . $k['target_unit'];
        }
        $dir = ['lte'=>'≤', 'gte'=>'≥'][$k['target_direction']] ?? '';
        $L[] = "・KPI「{$k['name']}」（目標 {$dir}" . (float)$k['target_value'] . $k['target_unit'] . '）：'
             . ($den > 0 ? '累計 ' . (0 + $num) . '/' . (0 + $den) . '＝' . round(100 * $num / $den, 2) . '%；' : '') . implode('、', $p);
    }
    return implode("\n", $L);
}

/** 一份 AS 表單在審查期間內的填寫紀錄（沒有專屬系統資料的表單用；只報份數，不假裝是統計分析） */
function meeting_tpl_doc_fill_text(PDO $db, int $docId, string $meetingDate): string {
    include_once __DIR__ . '/asdoc_record_lib.php';
    $d = $db->prepare("SELECT doc_no, doc_name FROM as_document WHERE id=?");
    $d->execute([$docId]);
    $doc = $d->fetch(PDO::FETCH_ASSOC);
    if (!$doc) return '';
    [$y, $from, $to] = meeting_tpl_period($meetingDate);
    $rows = eg_asdoc_fill_rows($db, $docId);
    $in = array_values(array_filter($rows, function ($r) use ($from, $to) { $dt = substr((string)($r['rec_date'] ?? ''), 0, 10); return $dt !== '' && $dt >= $from && $dt <= $to; }));
    $s = "【系統資料】{$doc['doc_no']} {$doc['doc_name']}：{$from}～{$to} 共 " . count($in) . ' 份填寫紀錄';
    if ($in) {
        usort($in, function ($a, $b) { return strcmp((string)$b['rec_date'], (string)$a['rec_date']); });
        $p = []; foreach (array_slice($in, 0, 3) as $r) $p[] = substr((string)$r['rec_date'], 0, 10) . ' ' . $r['title'];
        $s .= '（最近：' . implode('；', $p) . '）';
    }
    return $s . '。';
}

/** 解開項目的系統資料設定（相容舊的單一 data_src） */
function meeting_tpl_item_srcs(array $it): array {
    $j = json_decode((string)($it['src_json'] ?? ''), true);
    $out = [];
    if (is_array($j)) foreach ($j as $s) { if (!empty($s['k'])) $out[] = ['k' => (string)$s['k'], 'ids' => array_map('intval', (array)($s['ids'] ?? []))]; }
    if (!$out && !empty($it['data_src'])) $out[] = ['k' => (string)$it['data_src'], 'ids' => []];
    return $out;
}

/** 一個項目套用時要附上的系統資料文字（指定的系統資料＋對應 AS 表單自動帶出的資料，同一來源只帶一次） */
function meeting_tpl_item_text(PDO $db, array $it, string $meetingDate): string {
    $srcs = meeting_tpl_item_srcs($it);
    $have = []; foreach ($srcs as $s) $have[$s['k']] = true;
    $docMap = meeting_tpl_doc_map();
    $docIds = array_values(array_filter(array_map('intval', explode(',', (string)($it['as_docs'] ?? '')))));
    $generic = [];
    if ($docIds) {
        $q = $db->prepare("SELECT id, doc_no FROM as_document WHERE id IN (" . implode(',', array_fill(0, count($docIds), '?')) . ")");
        $q->execute($docIds);
        $byId = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($docIds as $id) {
            $no = $byId[$id] ?? null; if ($no === null) continue;
            if (isset($docMap[$no])) { if (empty($have[$docMap[$no]])) { $srcs[] = ['k' => $docMap[$no], 'ids' => []]; $have[$docMap[$no]] = true; } }
            else $generic[] = $id;
        }
    }
    $parts = [];
    foreach ($srcs as $s) { $t = meeting_tpl_source_text($db, $s['k'], $meetingDate, ['ids' => $s['ids']]); if ($t !== '') $parts[] = $t; }
    foreach ($generic as $id) { $t = meeting_tpl_doc_fill_text($db, $id, $meetingDate); if ($t !== '') $parts[] = $t; }
    return implode("\n", $parts);
}

function meeting_tpl_list(PDO $db, bool $withItems = true): array {
    meeting_tpl_ensure($db);
    $tpls = $db->query("SELECT * FROM meeting_tpl WHERE is_active=1 ORDER BY sort_order, tpl_id")->fetchAll(PDO::FETCH_ASSOC);
    if ($withItems) {
        $iq = $db->prepare("SELECT kind, content, owner_depts, remark, data_src, clause, title_text, as_docs, src_json FROM meeting_tpl_item WHERE tpl_id=? ORDER BY sort_order, id");
        foreach ($tpls as &$t) {
            $iq->execute([(int)$t['tpl_id']]);
            $t['items'] = $iq->fetchAll(PDO::FETCH_ASSOC);
            foreach ($t['items'] as &$it) $it['srcs'] = meeting_tpl_item_srcs($it);
            unset($it);
        }
        unset($t);
    }
    return $tpls;
}

/** 內容裡是否還有沒填的【待填：…】 */
function meeting_tpl_has_blank(string $content): bool {
    return (bool)preg_match(MEETING_TPL_BLANK_RE, $content);
}

/* ====================================================================
 * 與 AS 文件線上版同步（管理審查會議的項目＝2-GM-05 的 6.1.4 審查輸入／6.1.5 審查輸出）
 * ==================================================================== */

/** 線上版目前的條文：回 ['ver'=>[...], 'clauses'=>[[clause,text,leaf,kind]]]；沒有線上版內容回 null */
function meeting_tpl_doc_clauses(PDO $db, int $docId, string $prefixesSpec): ?array {
    $st = $db->prepare("SELECT c.version_id, c.content_html, c.updated_at, v.version, v.revised_date
                          FROM as_doc_content c JOIN as_document_version v ON v.id=c.version_id
                         WHERE c.doc_id=? AND c.content_html IS NOT NULL AND c.content_html<>''
                         ORDER BY v.revised_date DESC, v.id DESC LIMIT 1");
    $st->execute([$docId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $kinds = [];
    foreach (array_filter(array_map('trim', explode(',', $prefixesSpec))) as $sp) {
        [$pre, $kind] = array_pad(explode(':', $sp, 2), 2, 'general');
        $kinds[$pre] = $kind;
    }
    $all = [];
    if (preg_match_all('~<p\b[^>]*>(.*?)</p>~is', (string)$r['content_html'], $m)) {
        foreach ($m[1] as $h) {
            $tx = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8')));
            if (!preg_match('/^(\d+(?:\.\d+)+)\s*(.*)$/u', $tx, $mm)) continue;
            $cl = $mm[1]; $kind = null;
            foreach ($kinds as $pre => $k) { if ($cl === $pre || strpos($cl, $pre . '.') === 0) { $kind = $k; break; } }
            if ($kind === null) continue;
            if (isset($kinds[$cl])) continue;   // 6.1.4 / 6.1.5 本身是標題，不是項目
            $all[$cl] = ['clause' => $cl, 'text' => trim($mm[2]), 'kind' => $kind];
        }
    }
    $keys = array_keys($all);
    $out = [];
    foreach ($all as $cl => $a) {
        $leaf = true;
        foreach ($keys as $k2) if (strpos($k2, $cl . '.') === 0) { $leaf = false; break; }
        $a['leaf'] = $leaf;
        if ($leaf) $out[] = $a;
    }
    return ['ver' => ['version_id' => (int)$r['version_id'], 'version' => $r['version'], 'revised_date' => $r['revised_date'], 'updated_at' => $r['updated_at']],
            'clauses' => $out];
}

/** 範本與線上版的差異：changed（文字不同）／added（線上版新增、範本沒有）／removed（範本有、線上版已無） */
function meeting_tpl_sync_status(PDO $db, array $tpl): ?array {
    $docId = (int)($tpl['src_doc_id'] ?? 0);
    if ($docId <= 0) return null;
    $doc = meeting_tpl_doc_clauses($db, $docId, (string)($tpl['src_prefixes'] ?? ''));
    if (!$doc) return ['has_doc' => false, 'dirty' => false, 'changed' => [], 'added' => [], 'removed' => []];
    $items = $tpl['items'] ?? null;
    if ($items === null) {
        $q = $db->prepare("SELECT clause, title_text, content FROM meeting_tpl_item WHERE tpl_id=? ORDER BY sort_order, id");
        $q->execute([(int)$tpl['tpl_id']]);
        $items = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $byClause = []; foreach ($items as $it) if (!empty($it['clause'])) $byClause[$it['clause']] = $it;
    $changed = []; $added = []; $seen = [];
    foreach ($doc['clauses'] as $c) {
        $seen[$c['clause']] = true;
        if (!isset($byClause[$c['clause']])) { $added[] = ['clause' => $c['clause'], 'text' => $c['text']]; continue; }
        $old = (string)($byClause[$c['clause']]['title_text'] ?? '');
        if ($old !== $c['text']) $changed[] = ['clause' => $c['clause'], 'old' => $old, 'new' => $c['text']];
    }
    $removed = [];
    foreach ($byClause as $cl => $it) if (empty($seen[$cl])) $removed[] = $cl;
    return ['has_doc' => true, 'ver' => $doc['ver'], 'dirty' => (bool)($changed || $added || $removed),
            'changed' => $changed, 'added' => $added, 'removed' => $removed];
}

/** 依線上版更新：改條文文字（只換第一行，後面的待填提示與系統資料設定不動）、補上新增的條文；已被線上版刪除的只回報不刪 */
function meeting_tpl_sync_apply(PDO $db, int $tplId): array {
    meeting_tpl_ensure($db);
    $t = $db->prepare("SELECT * FROM meeting_tpl WHERE tpl_id=?"); $t->execute([$tplId]);
    $tpl = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tpl || !$tpl['src_doc_id']) return ['ok' => false, 'error' => '這個範本沒有設定對應的 AS 文件'];
    $doc = meeting_tpl_doc_clauses($db, (int)$tpl['src_doc_id'], (string)$tpl['src_prefixes']);
    if (!$doc) return ['ok' => false, 'error' => '找不到該 AS 文件的線上版內容'];
    $iq = $db->prepare("SELECT id, clause, content, title_text, sort_order FROM meeting_tpl_item WHERE tpl_id=? ORDER BY sort_order, id");
    $iq->execute([$tplId]);
    $items = $iq->fetchAll(PDO::FETCH_ASSOC);
    $by = []; foreach ($items as $it) if ($it['clause']) $by[$it['clause']] = $it;
    $nChanged = 0; $nAdded = 0;
    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE meeting_tpl_item SET content=?, title_text=? WHERE id=?");
        $ins = $db->prepare("INSERT INTO meeting_tpl_item (tpl_id, kind, sort_order, content, clause, title_text) VALUES (?,?,?,?,?,?)");
        $seen = [];
        foreach ($doc['clauses'] as $c) {
            $seen[$c['clause']] = true;
            $line1 = $c['clause'] . ' ' . $c['text'];
            if (isset($by[$c['clause']])) {
                $it = $by[$c['clause']];
                if ((string)$it['title_text'] === $c['text']) continue;
                $rest = (strpos((string)$it['content'], "\n") !== false) ? substr((string)$it['content'], strpos((string)$it['content'], "\n")) : '';
                $up->execute([$line1 . $rest, $c['text'], (int)$it['id']]);
                $nChanged++;
            } else {
                $ins->execute([$tplId, $c['kind'], 9999, $line1 . "\n" . ($c['kind'] === 'directive' ? '決議：【待填：決議內容】' : '說明：【待填：說明】'), $c['clause'], $c['text']]);
                $nAdded++;
            }
        }
        // 依線上版的條文順序重排有 clause 的項目（沒有 clause 的項目維持在最後）
        $order = array_flip(array_map(function ($c) { return $c['clause']; }, $doc['clauses']));
        $iq->execute([$tplId]);
        $all = $iq->fetchAll(PDO::FETCH_ASSOC);
        usort($all, function ($a, $b) use ($order) {
            $ka = ($a['clause'] !== null && $a['clause'] !== '' && isset($order[$a['clause']])) ? $order[$a['clause']] : 100000 + (int)$a['sort_order'];
            $kb = ($b['clause'] !== null && $b['clause'] !== '' && isset($order[$b['clause']])) ? $order[$b['clause']] : 100000 + (int)$b['sort_order'];
            return $ka <=> $kb ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        $so = $db->prepare("UPDATE meeting_tpl_item SET sort_order=? WHERE id=?");
        foreach ($all as $i => $it) $so->execute([$i, (int)$it['id']]);
        $db->prepare("UPDATE meeting_tpl SET src_ver_id=?, src_synced_at=NOW() WHERE tpl_id=?")->execute([$doc['ver']['version_id'], $tplId]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return ['ok' => false, 'error' => $e->getMessage()]; }
    $removed = []; foreach ($by as $cl => $_) if (empty($seen[$cl])) $removed[] = $cl;
    return ['ok' => true, 'changed' => $nChanged, 'added' => $nAdded, 'removed' => $removed];
}
