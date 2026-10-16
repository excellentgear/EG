<?php
/**
 * 管理審查會議（2-GM-05 管理審查管理程序）的條文檢查：6.1.4 管理審查輸入、6.1.5 管理審查輸出。
 *
 * 條號與文字「即時」取自 AS 文件線上版目前的內容（程序書改版、條號變動時自動跟著變，不在程式裡抄一份）；
 * 程式只負責「這一條要看什麼（解說）」「系統裡有哪些對應資料（自動提示）」「怎麼判定會議有沒有涵蓋（關鍵字）」，
 * 以條文文字裡的關鍵字對應，條號變了也對得上；程序書新增、程式沒登記的條文會以通用說明列出，不會漏掉。
 * 唯一使用端：views/ADM/meeting_record.php（編輯管理審查會議時）。
 */

/** 程序書目前線上版的文字行：[proc 資訊, 行陣列] */
function mr_proc_lines(PDO $db): array
{
    $proc = ['doc_no' => '2-GM-05', 'name' => '管理審查管理程序', 'version' => ''];
    $lines = [];
    try {
        $r = $db->query("SELECT c.content_html, v.version, d.doc_name FROM as_document d JOIN as_doc_content c ON c.doc_id=d.id JOIN as_document_version v ON v.id=c.version_id
                         WHERE d.doc_no='2-GM-05' ORDER BY c.version_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $proc['version'] = (string)$r['version']; $proc['name'] = (string)$r['doc_name'];
            $t = html_entity_decode(strip_tags(preg_replace('/<\/(p|div|tr|h\d|li)>|<br\s*\/?>/i', "\n", (string)$r['content_html'])), ENT_QUOTES, 'UTF-8');
            foreach (preg_split('/\R/u', $t) as $l) { $l = trim(preg_replace('/[ \t\x{3000}]+/u', ' ', $l)); if ($l !== '') $lines[] = $l; }
        }
    } catch (Throwable $e) { @error_log('[mr_clause] ' . $e->getMessage()); }
    return [$proc, $lines];
}

/** KPI 逐月資料摘要：[['name','n','miss','last_month','last_ok']…]（只列有資料的指標） */
function mr_kpi_summary(PDO $db, int $year): array
{
    $out = [];
    try {
        $inds = $db->prepare("SELECT y.indicator_id, i.name, y.target_direction td, y.target_value tv FROM kpi_as_indicator_year y JOIN kpi_as_indicator i ON i.indicator_id=y.indicator_id
                              WHERE y.year=? AND y.is_active=1 AND y.target_value IS NOT NULL ORDER BY i.item_no");
        $inds->execute([$year]);
        foreach ($inds->fetchAll(PDO::FETCH_ASSOC) as $ind) {
            $mv = $db->prepare("SELECT month, COALESCE(override_value, manual_value, auto_value) v FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? ORDER BY month");
            $mv->execute([(int)$ind['indicator_id'], $year]);
            $rows = array_values(array_filter($mv->fetchAll(PDO::FETCH_ASSOC), function ($r) { return $r['v'] !== null; }));
            if (!$rows) continue;
            $gte = $ind['td'] === 'gte'; $tv = (float)$ind['tv'];
            $ok = function ($v) use ($gte, $tv) { return $gte ? (float)$v >= $tv : (float)$v <= $tv; };
            $miss = 0; foreach ($rows as $r) if (!$ok($r['v'])) $miss++;
            $last = $rows[count($rows) - 1];
            $out[] = ['name' => (string)$ind['name'], 'n' => count($rows), 'miss' => $miss, 'last_month' => (int)$last['month'], 'last_ok' => $ok($last['v'])];
        }
    } catch (Throwable $e) { @error_log('[mr_clause] ' . $e->getMessage()); }
    return $out;
}
function mr_kpi_line(array $sum, string $kw): string
{
    $o = [];
    foreach ($sum as $k) if (mb_strpos($k['name'], $kw) !== false)
        $o[] = $k['name'] . '：' . $k['n'] . ' 個月有資料、' . ($k['miss'] ? $k['miss'] . ' 個月未達標' . ($k['last_ok'] ? '' : '（截至 ' . $k['last_month'] . ' 月仍未達標）') : '全部達標');
    return implode('；', $o);
}

/**
 * 條文清單。$meetingDate＝會議日期：1～3 月的管理審查審查的是前一年度的資料（年初當年資料還很少），其餘月份用當年。
 * @return array ['proc'=>…, 'data_year'=>int, 'clauses'=>[ ['key','no','text','type'(in/out),'explain','duty','akw','fact','draft'] … ]]
 */
function mr_clause_catalog(PDO $db, string $meetingDate): array
{
    [$proc, $lines] = mr_proc_lines($db);
    $my = (int)substr($meetingDate, 0, 4); if ($my < 2000) $my = (int)date('Y');
    $dy = ((int)substr($meetingDate, 5, 2) <= 3) ? $my - 1 : $my;

    // 條文＝6.1.4.x（輸入）、6.1.5.x（輸出）；有子條文的上層（例：6.1.4.2 客戶回饋資料）不單獨列
    $all = [];
    foreach ($lines as $l) if (preg_match('/^(6\.1\.[45](?:\.\d+)+)\s*(.*)$/u', $l, $m)) $all[$m[1]] = $m[2];
    $nos = array_keys($all);
    // 配合後面的對照表用的設定：關鍵字＝條文文字裡的字；akw＝判定「會議有沒有涵蓋」的關鍵字；dkw＝找「權責」那一行的關鍵字
    $cfg = [
        ['kw' => '稽核結果', 'key' => 'audit', 'dkw' => '稽核結果', 'akw' => ['稽核結果', '稽核報告', '不符合', '內稽', '外稽', '驗證機構'],   // 單獨的「稽核」太寬（「利於稽核及管理」不算報告了稽核結果）
         'explain' => '報告內部與外部（驗證機構）稽核的結果：不符合事項件數與嚴重程度、是否如期改善、內部稽核年度計畫的完成度。'],
        ['kw' => '客訴統計', 'key' => 'complaint', 'dkw' => '客訴', 'akw' => ['客訴', '抱怨', '退貨'],
         'explain' => '報告客訴件數、主要原因分類、處理時效與趨勢（KPI「客訴頻率」）；有退貨一併說明。'],
        ['kw' => '客戶滿意度', 'key' => 'satisfaction', 'dkw' => '滿意度', 'akw' => ['滿意度'],
         'explain' => '報告客戶滿意度調查的分數與趨勢（品質、交期、服務、價格），分數偏低的項目要有因應。'],
        ['kw' => '供應商的績效', 'key' => 'supplier', 'dkw' => '供應商', 'akw' => ['供應商', '外包', '委外', '廠商', '評鑑'],
         'explain' => '報告供應商（含委外廠商）的準時交貨、品質與評鑑結果，單一來源或績效不佳的廠商要有因應。'],
        ['kw' => '品質目標達成', 'key' => 'objectives', 'dkw' => '異常統計', 'akw' => ['KPI', '品質目標', '目標達成', '達成率'],
         'explain' => '各部門品質目標（KPI）逐月達成狀況，尤其是製造過程的能力（不良率、產能績效）；未達標的要說明原因與改善。'],
        ['kw' => '預防和矯正措施', 'key' => 'corrective', 'dkw' => '異常統計', 'akw' => ['矯正', '預防', '異常', '不合格'],
         'explain' => '報告異常矯正處理單與預防措施的開立、結案與成效；成效確認為「無效」的項目要檢討。'],
        ['kw' => '品質政策', 'key' => 'policy', 'dkw' => '', 'akw' => ['品質政策', '年度目標', '目標執行'],
         'explain' => '檢視品質政策是否仍適切，以及年度目標的整體執行狀況。'],
        ['kw' => '環境審查表', 'key' => 'env', 'dkw' => '', 'akw' => ['環境審查'],
         'explain' => '檢視「環境審查表」（環境管理程序 2-MM-03）的結果。系統內沒有這份資料，請依現場審查結果填寫。'],
        ['kw' => '防護審核表', 'key' => 'protect', 'dkw' => '', 'akw' => ['防護審核', '防護'],
         'explain' => '檢視「防護審核表」（工安管理程序 2-MM-05）的結果。系統內沒有這份資料，請依現場審核結果填寫。'],
        ['kw' => '前次管理審查', 'key' => 'followup', 'dkw' => '決議事項', 'akw' => ['前次', '上次', '追蹤事項', '決議事項'],
         'explain' => '逐項說明前次管理審查決議事項的執行與完成情形，逾期未完成的要說明原因。'],
        ['kw' => '要求變化', 'key' => 'market', 'dkw' => '', 'akw' => ['要求變化', '市場', '國際', '氣候', '環境議題', '風險及其影響', '客戶集中'],
         'explain' => '客戶／市場的要求變化對本廠的風險與影響，要涵蓋「品質、安全、環境」（另外本公司要求必列國際局勢）：資料來源是「組織處境分析表」與「風險與機會因應措施管制表」，並說明與前一年度的議題變更。'],
        ['kw' => '有效性的改善', 'key' => 'improve', 'dkw' => '', 'akw' => ['有效性', '過程改善', '系統改善'],
         'explain' => '決議品質管理系統及其過程如何改善（流程簡化、表單整合、系統化管理等），並指定負責部門與期限。'],
        ['kw' => '顧客要求關於產品服務', 'key' => 'cust_improve', 'dkw' => '', 'akw' => ['顧客要求', '客戶要求', '產品服務'],
         'explain' => '決議針對客戶要求在產品與服務上要做的改善（客訴、滿意度、新客戶詢價的回應等）。'],
        ['kw' => '資源的需求', 'key' => 'resource', 'dkw' => '', 'akw' => ['資源', '人力需求', '設備需求', '人員需求', '設施'],
         'explain' => '決議資源需求：人員、設施、工作環境、方法。風險與機會因應措施管制表每一項都有「所需資源」欄，可直接引用。'],
        ['kw' => '風險識別', 'key' => 'risk_id', 'dkw' => '', 'akw' => ['風險識別', '風險與機會', '風險表'],
         'explain' => '決議本年度要識別、處理的風險與機會（風險與機會因應措施管制表就是這項輸出），需因應措施者要有編號與負責部門。'],
        ['kw' => '次年度目標', 'key' => 'next_goal', 'dkw' => '', 'akw' => ['次年度', '下年度', '目標制定', '目標修訂'],
         'explain' => '決議次年度品質目標（KPI）要訂多少、哪些要修訂；長期未達標或長期超標的指標要檢視目標值是否合理。'],
    ];
    $kpi = mr_kpi_summary($db, $dy);
    $facts = mr_clause_facts($db, $dy, $meetingDate, $kpi);
    $out = [];
    foreach ($nos as $no) {
        $hasChild = false; foreach ($nos as $o) if ($o !== $no && strpos($o, $no . '.') === 0) { $hasChild = true; break; }
        if ($hasChild) continue;
        $text = preg_replace('/[。：:]+$/u', '', $all[$no]);
        $c = null; foreach ($cfg as $x) if (mb_strpos($text, $x['kw']) !== false) { $c = $x; break; }
        $key = $c ? $c['key'] : 'c_' . str_replace('.', '_', $no);
        $duty = '';
        if ($c && $c['dkw'] !== '') foreach ($lines as $l) if (preg_match('/^3\.\d/u', $l) && mb_strpos($l, $c['dkw']) !== false) { $duty = $l; break; }
        $fact = $facts[$key] ?? '';
        $out[] = ['key' => $key, 'no' => $no, 'text' => $text, 'type' => strpos($no, '6.1.4') === 0 ? 'in' : 'out',
                  'explain' => $c ? $c['explain'] : '程序書新增的條文，系統沒有預設說明：請在會議要項寫明這一條的審查結果與決議。',
                  'duty' => $duty, 'akw' => $c ? $c['akw'] : [], 'fact' => $fact,
                  'draft' => '【' . $no . ' ' . $text . '】' . ($fact !== '' ? $fact . '。' : '') . '審查結論與決議：（請補充）'];
    }
    return ['proc' => $proc, 'data_year' => $dy, 'clauses' => $out];
}

/** 各條文在系統裡查得到的對應資料（自動提示）；查不到的條文給空字串 */
function mr_clause_facts(PDO $db, int $dy, string $meetingDate, array $kpi): array
{
    $f = []; $q1 = function (string $sql, array $a = []) use ($db) { try { $st = $db->prepare($sql); $st->execute($a); return $st->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; } };
    // 稽核
    $nc = $q1("SELECT COUNT(*) n, SUM(stage<>'closed') op FROM ia_nc WHERE is_deleted=0 AND year=?", [$dy]);
    $cs = $q1("SELECT COUNT(*) n, SUM(executed=1) ex FROM ia_case WHERE year=?", [$dy]);
    $p = [];
    if (!empty($cs['n'])) $p[] = $dy . ' 年內部稽核案件 ' . (int)$cs['n'] . ' 件（已執行 ' . (int)$cs['ex'] . ' 件）';
    if (!empty($nc['n'])) $p[] = '不符合通知單 ' . (int)$nc['n'] . ' 張，尚未結案 ' . (int)$nc['op'] . ' 張';
    $f['audit'] = implode('；', $p);
    // 客訴
    $ir = $q1("SELECT COUNT(*) n FROM ir_track WHERE YEAR(IR_date)=?", [$dy]);
    $qa = $q1("SELECT COUNT(*) n FROM qa_abnormal_order WHERE deleted_at IS NULL AND YEAR(created_at)=?", [$dy]);
    $p = []; $kl = mr_kpi_line($kpi, '客訴'); if ($kl) $p[] = $kl;
    if (isset($ir['n'])) $p[] = $dy . ' 年客戶退貨 ' . (int)$ir['n'] . ' 筆';
    if (isset($qa['n'])) $p[] = '品質異常處理單 ' . (int)$qa['n'] . ' 張';
    $f['complaint'] = implode('；', $p);
    $f['satisfaction'] = mr_kpi_line($kpi, '滿意度');
    $p = []; foreach (['廠商準時', '廠商稽核'] as $k) { $l = mr_kpi_line($kpi, $k); if ($l) $p[] = $l; }
    $f['supplier'] = implode('；', $p);
    // KPI 總覽
    $miss = array_values(array_filter($kpi, function ($k) { return $k['miss'] > 0; }));
    $sumTxt = $kpi ? $dy . ' 年 KPI 有資料 ' . count($kpi) . ' 項，其中 ' . count($miss) . ' 項有月份未達標' . ($miss ? '（' . implode('、', array_map(function ($k) { return $k['name']; }, array_slice($miss, 0, 6))) . (count($miss) > 6 ? ' 等' : '') . '）' : '') : '';
    $f['objectives'] = $sumTxt; $f['policy'] = $sumTxt;
    $car = $q1("SELECT COUNT(*) n FROM car_order WHERE YEAR(fill_date)=?", [$dy]);
    $p = []; if (isset($car['n'])) $p[] = $dy . ' 年異常矯正處理單 ' . (int)$car['n'] . ' 張'; if (isset($qa['n'])) $p[] = '品質異常處理單 ' . (int)$qa['n'] . ' 張';
    $f['corrective'] = implode('；', $p);
    $f['env'] = ''; $f['protect'] = '';
    // 前次管理審查與措施追蹤
    $pm = $q1("SELECT meeting_id, meeting_date FROM meeting_record WHERE subject LIKE '%管理審查%' AND subject NOT LIKE '【假資料】%' AND meeting_date<? AND status<>'deleted' ORDER BY meeting_date DESC LIMIT 1", [$meetingDate ?: date('Y-m-d')]);
    $p = [];
    if (!empty($pm['meeting_id'])) {
        $pi = $q1("SELECT COUNT(*) n FROM meeting_item WHERE meeting_id=? AND kind<>'announce'", [(int)$pm['meeting_id']]);
        $p[] = '前次管理審查（' . $pm['meeting_date'] . '）決議／要項 ' . (int)$pi['n'] . ' 項';
    }
    $tr = $q1("SELECT COUNT(*) n, SUM(due_date<CURDATE()) od FROM comm_track WHERE is_deleted=0 AND is_closed=0 AND is_draft=0");
    if (isset($tr['n'])) $p[] = '溝通管理措施追蹤未結案 ' . (int)$tr['n'] . ' 筆' . ((int)$tr['od'] ? '（逾期 ' . (int)$tr['od'] . ' 筆）' : '');
    $f['followup'] = implode('；', $p);
    // 風險與機會管理（組織處境分析表／風險表）
    $rk = $q1("SELECT doc_id, doc_no FROM rm_doc WHERE kind='risk' AND is_deleted=0 AND (remark IS NULL OR remark NOT LIKE '【假資料】%') AND YEAR(fill_date)=? ORDER BY doc_id DESC LIMIT 1", [$dy]);
    $cx = $q1("SELECT doc_id, fill_date FROM rm_doc WHERE kind='context' AND is_deleted=0 AND (remark IS NULL OR remark NOT LIKE '【假資料】%') AND YEAR(fill_date)=? ORDER BY doc_id DESC LIMIT 1", [$dy]);
    $p = [];
    if (!empty($cx['doc_id']) && function_exists('rm_ctx_compare')) {
        $cp = rm_ctx_compare($db, (int)$cx['doc_id'], (string)$cx['fill_date']);
        if (!empty($cp['prev_year'])) $p[] = $dy . ' 年組織處境分析表與 ' . $cp['prev_year'] . ' 年比較：新增 ' . $cp['counts']['new'] . '、變更 ' . $cp['counts']['changed'] . '、沿用 ' . $cp['counts']['same'] . '、刪除 ' . $cp['counts']['removed'] . ' 項議題';
    }
    $risk = []; $resN = 0; $actN = 0; $rN = 0; $oN = 0; $safe = 0; $bad = 0;
    if (!empty($rk['doc_id'])) {
        $st = $db->prepare("SELECT ro_kind, issue, confirm_no, resources, effect_status FROM rm_item WHERE doc_id=?"); $st->execute([(int)$rk['doc_id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['ro_kind'] === 'opportunity') $oN++; else $rN++;
            if ((string)$r['confirm_no'] !== '') $actN++;
            if ((string)$r['resources'] !== '') $resN++;
            if (mb_strpos((string)$r['issue'], '安全') !== false || mb_strpos((string)$r['issue'], '職災') !== false) $safe++;
            if ($r['effect_status'] === 'ineffective') $bad++;
        }
        $p[] = $dy . ' 年風險與機會因應措施管制表（' . $rk['doc_no'] . '）：風險 ' . $rN . ' 項、機會 ' . $oN . ' 項，其中 ' . $actN . ' 項需因應措施；與安全相關 ' . $safe . ' 項';
    }
    $f['market'] = implode('；', $p);
    $f['risk_id'] = !empty($rk['doc_id']) ? $dy . ' 年風險 ' . $rN . ' 項、機會 ' . $oN . ' 項，其中 ' . $actN . ' 項需因應措施（有編號可追蹤）' : '';
    $f['resource'] = !empty($rk['doc_id']) ? '風險與機會因應措施管制表所需資源（人員、設施、工作環境、方法）已列 ' . $resN . ' 項' : '';
    $f['improve'] = '內部稽核不符合 ' . (int)($nc['n'] ?? 0) . ' 張、異常矯正處理單 ' . (int)($car['n'] ?? 0) . ' 張' . ($bad ? '、風險表成效無效 ' . $bad . ' 項' : '');
    $f['cust_improve'] = $f['complaint'] !== '' ? $f['complaint'] . ($oN ? '；風險表機會 ' . $oN . ' 項（新客戶、擴單等）' : '') : '';
    $f['next_goal'] = $miss ? '本年度 ' . count($miss) . ' 項 KPI 有月份未達標，次年度目標是否調整請於會議決議' : '';
    return $f;
}
