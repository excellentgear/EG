<?php
/**
 * asdoc_schedule_lib.php — AS 文件週期排程的推導（唯一實作，2026-09-30 使用者交辦）
 * ══════════════════════════════════════════════════════════════════════════════
 * 使用者的問題：「有沒有可能做到全自動提醒 AS 相關文件的建置，例如幾月要做什麼」。
 *
 * 【為什麼是「推導」不是「存一張排程表」】
 * AS 文件管理（2026-09-07）已經把「更新頻率」登記在 as_document.freq_type/freq_n/freq_months，
 * 負責課室登記在 as_doc_owner_dept；但 AS_Document_API.php 的註解自己寫著那是
 * 「主要要記錄用」——**登記了卻沒有任何程式讀它**，這支庫補的就是這一段。
 * 排程一律由「文件頻率 ＋ 各模組實際完成紀錄」即時算出來，**不另存一張排程表**：
 * 存下來就會有兩份真相（改了文件頻率、排程表還是舊的，而且完全不報錯＝鐵律4 的老問題），
 * 而且「逾期幾天」每天都在變，不是能存下來的東西。
 *
 * 【與行事曆的關係】
 * 刻意**不把排程寫成 evenement 列**。evenement 是人工畫的事件、一筆常掛好幾個人
 * （請假系統那次已經定調「不去動使用者自己畫的那筆事件」）；AS 排程是算出來的唯讀圖層。
 * 行事曆頁面把本庫的結果當成另一個 eventSource 疊上去，既有的 events.php 一行都不必動。
 * 反向例外：排程真的變成一場會議或某天去某部門稽核時，由該模組自己建 evenement
 * （內稽模組本來就會建 meeting_record 草稿），走既有路徑，不在這裡建。
 *
 * 【三個必須先知道的現實限制（畫面上一定要講出來，不可假裝有資料）】
 *  ①「幾月」多半不知道：33 份有固定週期的文件裡只有 6 份填了 freq_months。其餘只知道
 *    「一年一次」，故本庫用「過去實際完成的月份」自動推（月份來源標 infer），推不出來的
 *    一律留白成 month_src='none'，**不可預設成 1 月**——猜一個月份出來，逾期天數就是假的。
 *  ② 10 份文件沒有對應的系統頁面（天車保養表、量測室溫濕度記錄表、三份績效評核表…），
 *    系統查不到做了沒，只能靠紙本上傳紀錄或人工登記（as_sched_done）。
 *  ③ 46 份文件還沒設頻率、8 份沒設負責課室——不補就不會被提醒，而且不會報錯，
 *    所以 asched_gaps() 要把這些缺口主動列出來。
 *
 * 【每日型一定要排除在日曆之外】
 * 3-QA-01-06 量測室溫、濕度記錄表是 day×1＝一年 365 個排程點，畫進日曆會把整片蓋掉。
 * 它是「每天都要做」的常態工作，不是「幾月要做什麼」的排程，故 asched_is_calendar_freq()
 * 把 day/week 型排除；它們仍會出現在「常態工作」清單裡，只是不進日曆也不算逾期。
 */

require_once __DIR__ . '/asdoc_lib.php';        // freq_* 解析（asFreqMonthPlan/asFreqParseMonths/asFreqUnits）
require_once __DIR__ . '/org_role_lib.php';     // eg_dept_subtree_ids()（部門樹的正版實作）

if (!function_exists('asched_ensure')) {

/* ══════════════════════════ 資料表 ══════════════════════════ */

/** 建表（可重複執行）。只有三張：人工完成登記、通知對象設定、發送記錄。排程本身不存表。 */
function asched_ensure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        /* 人工登記「這個週期做了」——給那 10 份沒有系統頁面的文件用。
           period_key 是週期鍵（見 asched_period_key()），同一份文件同一週期只會有一筆。 */
        $db->exec("CREATE TABLE IF NOT EXISTS as_sched_done (
            doc_id       INT NOT NULL,
            period_key   VARCHAR(20)  NOT NULL COMMENT '週期鍵，如 2026-M03／2026-Y',
            done_date    DATE         NOT NULL COMMENT '實際完成日（業務日期，不是登記當天）',
            note         VARCHAR(500) NULL,
            done_by      INT          NULL,
            done_by_name VARCHAR(50)  NULL,
            created_at   DATETIME     NOT NULL,
            PRIMARY KEY (doc_id, period_key),
            KEY idx_asd_date (done_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS排程的人工完成登記（系統查不到完成紀錄的文件用）'");

        /* 通知對象：逐部門設定「收通知的職位（可複選）＋指定人員（可複選）」。
           使用者拍板的做法——刻意沿用既有 position／user 而不另建一張人名對照表。 */
        $db->exec("CREATE TABLE IF NOT EXISTS as_sched_notify_cfg (
            dept_id INT NOT NULL,
            kind    VARCHAR(10) NOT NULL COMMENT 'position=該部門的這個職位／user=指定人員',
            ref_id  INT NOT NULL COMMENT 'kind=position→position.id／kind=user→user.id',
            PRIMARY KEY (dept_id, kind, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS排程提醒的收件對象（逐部門設定職位與指定人員）'");

        /* 已發送記錄：同一份文件的同一個週期、同一個階段只發一次。 */
        $db->exec("CREATE TABLE IF NOT EXISTS as_sched_notify_log (
            doc_id     INT NOT NULL,
            period_key VARCHAR(20) NOT NULL,
            phase      VARCHAR(10) NOT NULL COMMENT 'lead=到期前提醒／over=逾期提醒',
            sent_at    DATETIME NOT NULL,
            sent_round SMALLINT NOT NULL DEFAULT 1 COMMENT '逾期提醒的第幾輪',
            PRIMARY KEY (doc_id, period_key, phase)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS排程提醒的發送記錄（防重複發送）'");
    } catch (Throwable $e) {
        error_log('[asched] ensure failed: ' . $e->getMessage());
    }
}

/* ══════════════════════════ 設定 ══════════════════════════ */

/** 預設值（唯一定義處；asched_settings() 與設定頁共用） */
function asched_defaults(): array {
    return [
        'lead_days'      => 30,   // 到期前幾天開始提醒
        'overdue_repeat' => 14,   // 逾期後每幾天再提醒一次（0＝逾期只提醒一次）
        'infer_month'    => 1,    // 沒指定月份時，要不要用過去實際完成的月份自動推
        'notify_enabled' => 1,    // 提醒總開關
    ];
}

/** 上下限夾範圍（讀取與存檔都走這裡正規化——不可「存檔前重新讀一次 DB 再合併」，
 *  PHP 的 `+` 陣列運算子是左邊鍵優先，那樣寫會用舊值蓋掉剛要存的新值，訂單分析踩過一次）。 */
function asched_clamp(array $s): array {
    $d = asched_defaults();
    $s['lead_days']      = max(0, min(365, (int)($s['lead_days']      ?? $d['lead_days'])));
    $s['overdue_repeat'] = max(0, min(180, (int)($s['overdue_repeat'] ?? $d['overdue_repeat'])));
    $s['infer_month']    = !empty($s['infer_month'])    ? 1 : 0;
    $s['notify_enabled'] = !empty($s['notify_enabled']) ? 1 : 0;
    return $s;
}

function asched_settings(PDO $db): array {
    $cur = asched_defaults();
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='AS_SCHEDULE' AND param_key='settings'");
        $st->execute();
        $raw = $st->fetchColumn();
        if ($raw) { $j = json_decode((string)$raw, true); if (is_array($j)) $cur = array_merge($cur, $j); }
    } catch (Throwable $e) { /* 沒設過＝用預設 */ }
    return asched_clamp($cur);
}

function asched_settings_save(PDO $db, array $in): array {
    $cur = asched_clamp(array_merge(asched_settings($db), $in));
    $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value)
                  VALUES ('AS_SCHEDULE','settings',?)
                  ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)")
       ->execute([json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    return $cur;
}

/* ══════════════════════════ 頻率 → 週期 ══════════════════════════ */

/** 這個頻率要不要畫進日曆／納入逾期提醒？
 *  day/week 是「每天／每週都要做」的常態工作，一年 52~365 個點會把日曆整片蓋掉
 *  （3-QA-01-06 量測室溫濕度記錄表就是 day×1＝365 點），故排除。
 *  irregular（不定時）是事件驅動、算不出未來，也排除。 */
function asched_is_calendar_freq(?string $type): bool {
    return in_array((string)$type, ['month', 'quarter', 'year'], true);
}

/** 週期鍵：同一份文件在「同一個週期」內只算一次，也是 as_sched_done 的鍵。
 *  有指定月份／推導出月份時用 2026-M03；只知道「今年要做一次」時用 2026-Y。 */
function asched_period_key(int $year, ?int $month): string {
    return $month ? sprintf('%04d-M%02d', $year, $month) : sprintf('%04d-Y', $year);
}

/** 頻率顯示文字（與 AS 文件管理的 asFreqText() 同一組定義） */
function asched_freq_label(?string $type, $n): string {
    if ($type === 'irregular') return '不定時';
    if ($type === null || $type === '') return '未設定';
    $u = asFreqUnits();
    if (!isset($u[$type])) return (string)$type;
    $n = max(1, (int)$n);
    return $n === 1 ? ('每' . $u[$type]) : ('每 ' . $n . ' ' . $u[$type]);
}

/**
 * 某文件在某年度的排程月份清單。
 * @return array{months:int[], src:string}  src: fixed=管理員指定／infer=由過去紀錄推／none=推不出來
 *
 * 推導規則（使用者拍板「自動推＋可覆寫」）：
 *  ①freq_months 有值＝管理員指定，最優先（fixed）
 *  ②否則用「過去實際完成的月份」推（infer）——取最近一次完成的月份，
 *    半年／季型再依 asFreqMonthPlan() 的間隔補齊其餘月份
 *  ③都沒有＝none，**留白不排定月份**，畫面標「今年度應執行一次（月份未定）」。
 */
function asched_months_for(array $doc, array $pastMonths, bool $inferEnabled): array {
    $type = (string)($doc['freq_type'] ?? '');
    $n    = (int)($doc['freq_n'] ?? 1);
    if (!asched_is_calendar_freq($type)) return ['months' => [], 'src' => 'none'];

    $fixed = asFreqParseMonths($doc['freq_months'] ?? '');
    if ($fixed) return ['months' => $fixed, 'src' => 'fixed'];

    $plan = asFreqMonthPlan($type, $n);
    // 每月型：每個月都要做，沒有「哪幾個月」可談
    if ($plan === null) return ['months' => range(1, 12), 'src' => 'fixed'];

    if ($inferEnabled && $pastMonths) {
        $base = (int)$pastMonths[0];                 // 最近一次完成的月份
        if ($base >= 1 && $base <= 12) {
            $out = [$base];
            if ((int)($plan['slots'] ?? 1) > 1) {
                $iv = (int)$plan['interval'];
                for ($i = 1; $i < (int)$plan['slots']; $i++) {
                    $out[] = ((($base - 1) + $iv * $i) % 12) + 1;
                }
            }
            $out = array_values(array_unique($out));
            sort($out);
            return ['months' => $out, 'src' => 'infer'];
        }
    }
    return ['months' => [], 'src' => 'none'];
}

/* ══════════════════════════ 完成紀錄來源 ══════════════════════════ */

/**
 * 「這份 AS 文件最近一次是什麼時候做的」怎麼查——來源登記表（唯一登記處）。
 *
 * 為什麼要有這張表：33 份週期文件裡 23 份有自己的專屬模組（內稽 7 份、供應商稽核 5 份、
 * 教育訓練 3 份、客戶滿意度 2 份…），而 eg_asdoc_fill_rows()（asdoc_record_lib.php）
 * 只涵蓋四種通用來源（紙本上傳／表單簽核／審核表單／聯絡單），查不到那些專屬模組。
 * 這裡逐份登記「去哪張表、看哪個日期欄」，統一組成 SELECT <date> FROM <table> WHERE <cond>。
 *
 * ★加一列就多接一個模組，不必改其他程式。沒登記的文件自動退回：
 *   紙本／通用表單紀錄（eg_asdoc_fill_rows）→ 人工登記（as_sched_done）→ 顯示「查不到」。
 * ★日期欄一律取**業務日期**（稽核日、核准日、統計日），不是 created_at
 *   （那是建檔時間，補歷史紙本時會差很多，本專案已踩過多次）。
 * ★表名與欄位運算式一律寫死在程式碼裡，不吃任何外部輸入（無注入風險）。
 */
function asched_sources(): array {
    return [
        // ── 內部稽核（2-GM-06-xx）──
        '2-GM-06-01' => ['t' => 'ia_plan',   'd' => "COALESCE(approved_date, submit_date)", 'w' => "is_deleted=0 AND status IN ('submitted','approved')", 'label' => '內部稽核'],
        '2-GM-06-02' => ['t' => 'ia_case',   'd' => "audit_from", 'w' => "is_deleted=0", 'label' => '內部稽核'],
        '2-GM-06-03' => ['t' => 'ia_check',  'd' => "check_date", 'w' => "is_deleted=0 AND kind='kpi'",    'label' => '內部稽核'],
        '2-GM-06-04' => ['t' => 'ia_check',  'd' => "check_date", 'w' => "is_deleted=0 AND kind='as'",     'label' => '內部稽核'],
        '2-GM-06-06' => ['t' => 'ia_check',  'd' => "check_date", 'w' => "is_deleted=0 AND kind='system'", 'label' => '內部稽核'],
        '2-GM-06-07' => ['t' => 'ia_nc',     'd' => "audit_date", 'w' => "is_deleted=0", 'label' => '內部稽核'],
        '2-GM-06-08' => ['t' => 'ia_report', 'd' => "COALESCE(approver_date, submit_date, maker_date)", 'w' => "is_deleted=0", 'label' => '內部稽核'],
        // ── 供應商稽核（2-PH-01-xx）──
        '2-PH-01-02' => ['t' => 'vendor_audit_target',    'd' => "audit_date", 'w' => "audit_date IS NOT NULL", 'label' => '供應商稽核'],
        '2-PH-01-03' => ['t' => 'vendor_audit_target',    'd' => "audit_date", 'w' => "audit_date IS NOT NULL", 'label' => '供應商稽核'],
        '2-PH-01-06' => ['t' => 'vendor_audit_plan_lock', 'd' => "COALESCE(approved_date, submit_date)", 'w' => "submit_date IS NOT NULL", 'label' => '供應商稽核'],
        /* ★2-PH-01-05 供應商定期評核表「刻意不接自動來源」，別再接一次（2026-09-30 查證）：
           它是 month×6（半年一次），但定期評核的分數是 vendor_kpi_lib 的 vkPeriodRows()
           **即時算出來的、完全不落庫**——系統裡沒有「這半年的評核做完了」這個事件可查。
           第一版曾接 vendor_audit_plan_lock（年度稽核計劃的送出日），實測後發現那是錯的：
           該表一年只有一筆（2026 是 01-05），於是上半年判 done、**下半年永遠判逾期**，
           每年都會自動產生一次假逾期。CLAUDE.md 2026-08-17 說的「比照同年度稽核計劃的
           submit_date」是**列印時的業務日期**口徑，不是完成紀錄，兩者不可混用。
           vendor_audit_round 雖有 year+half，但那只是稽核輪次的容器（建了不等於評核完成），
           且只有 created_at 沒有業務日期。故本份一律走人工登記，畫面誠實標「無法判定」。 */
        // ── 客戶滿意度（2-SM-02-xx）──
        '2-SM-02-03' => ['t' => 'cs_summary', 'd' => "stat_date",    'w' => "stat_date IS NOT NULL",    'label' => '客戶滿意度'],
        '2-SM-02-04' => ['t' => 'cs_monitor', 'd' => "monitor_date", 'w' => "monitor_date IS NOT NULL", 'label' => '客戶滿意度'],
    ];
}

/**
 * 取各文件「過去的完成日期」清單（新→舊，最多 24 筆）。
 * 先走來源登記表，沒登記的退回通用表單紀錄，最後併入人工登記 as_sched_done。
 * 三種來源都併，一律標明是哪一種（src）。
 *
 * @return array doc_id => [ ['date'=>'YYYY-MM-DD','src'=>'module|paper|manual'], ... ]
 */
function asched_done_history(PDO $db, array $docs): array {
    $out = [];
    $srcMap = asched_sources();

    // ── ①專屬模組（來源登記表）──
    foreach ($docs as $d) {
        $docId = (int)$d['id'];
        $out[$docId] = [];
        $no = (string)$d['doc_no'];
        if (!isset($srcMap[$no])) continue;
        $s = $srcMap[$no];
        try {
            $sql = "SELECT DISTINCT {$s['d']} AS dt FROM {$s['t']}
                    WHERE ({$s['w']}) AND {$s['d']} IS NOT NULL
                    ORDER BY dt DESC LIMIT 24";
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $dt) {
                if ($dt) $out[$docId][] = ['date' => substr((string)$dt, 0, 10), 'src' => 'module'];
            }
        } catch (Throwable $e) {
            error_log('[asched] source query failed for ' . $no . ': ' . $e->getMessage());
        }
    }

    // ── ②通用表單紀錄（只對「來源登記表沒登記」的文件查，省掉多餘查詢）──
    foreach ($docs as $d) {
        $docId = (int)$d['id'];
        if (!empty($out[$docId])) continue;
        try {
            require_once __DIR__ . '/asdoc_record_lib.php';
            foreach (eg_asdoc_fill_rows($db, $docId) as $r) {
                $dt = substr((string)($r['rec_date'] ?? ''), 0, 10);
                if ($dt) $out[$docId][] = ['date' => $dt, 'src' => 'paper'];
            }
        } catch (Throwable $e) { /* 查不到就當沒有紀錄 */ }
    }

    // ── ③人工登記（所有文件都併：系統查得到的文件也可能被人工補登過去的紙本）──
    try {
        $ids = array_map(fn($d) => (int)$d['id'], $docs);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT doc_id, done_date FROM as_sched_done WHERE doc_id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['doc_id']][] = ['date' => substr((string)$r['done_date'], 0, 10), 'src' => 'manual'];
            }
        }
    } catch (Throwable $e) { /* 表還沒建＝沒有人工登記 */ }

    foreach ($out as $k => $rows) {
        usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));
        $out[$k] = $rows;
    }
    return $out;
}

/* ══════════════════════════ 主推導 ══════════════════════════ */

/** 有週期設定的 AS 文件（含負責課室）。$all=true 時連「不定時／未設定」也回（給缺口盤點用）。 */
function asched_docs(PDO $db, bool $all = false): array {
    $where = "d.is_deleted=0 AND d.is_obsolete=0";
    if (!$all) $where .= " AND d.freq_type IN ('day','week','month','quarter','year')";
    $rows = $db->query("SELECT d.id, d.doc_no, d.doc_name, d.doc_level, d.department_id,
                               d.freq_type, d.freq_n, d.freq_note, d.freq_months
                        FROM as_document d
                        WHERE $where
                        ORDER BY d.doc_no")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    // 負責課室（一次撈完，不逐份查＝避免 N+1）
    $depts = [];
    try {
        $st = $db->query("SELECT o.doc_id, o.department_id, dp.name
                          FROM as_doc_owner_dept o
                          JOIN department dp ON dp.id = o.department_id");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $depts[(int)$r['doc_id']][] = ['id' => (int)$r['department_id'], 'name' => (string)$r['name']];
        }
    } catch (Throwable $e) { /* 表不存在＝都沒設負責課室 */ }

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['depts'] = $depts[$r['id']] ?? [];
        $r['freq_label'] = asched_freq_label($r['freq_type'], $r['freq_n']);
    }
    unset($r);
    return $rows;
}

/** 這個週期做了沒：月份型看「同年同月」，年度型看「同年」。回完成日或空字串。 */
function asched_period_done(array $hist, int $year, ?int $month): string {
    foreach ($hist as $h) {
        if ((int)substr($h['date'], 0, 4) !== $year) continue;
        if ($month === null || $month === 0) return $h['date'];
        if ((int)substr($h['date'], 5, 2) === (int)$month) return $h['date'];
    }
    return '';
}

/**
 * 某年度的完整排程（本庫的主要出口；頁面、列印、日曆圖層、提醒全部走這一支）。
 *
 * @param array $opt  ['calendar_only'=>bool 只回有排定日期的（排除月份未定與常態工作）,
 *                     'dept_ids'=>int[] 只看這幾個課室（含子部門）]
 * @return array{year:int, rows:array, routine:array, settings:array}
 *   rows    ＝ 可排定週期的排程點（一份文件一個週期一列）
 *   routine ＝ 每日／每週型的常態工作（不進日曆、不算逾期，只列出來讓人知道它存在）
 */
function asched_plan(PDO $db, int $year, array $opt = []): array {
    asched_ensure($db);
    $set   = asched_settings($db);
    $today = date('Y-m-d');
    $docs  = asched_docs($db);

    // 依課室篩選（選部門＝含子部門，組織是樹狀的）
    $filterDepts = [];
    foreach ((array)($opt['dept_ids'] ?? []) as $did) {
        foreach (eg_dept_subtree_ids($db, (int)$did) as $x) $filterDepts[(int)$x] = 1;
    }
    if ($filterDepts) {
        $docs = array_values(array_filter($docs, function ($d) use ($filterDepts) {
            foreach ($d['depts'] as $dp) if (isset($filterDepts[$dp['id']])) return true;
            return false;
        }));
    }

    $hist    = asched_done_history($db, $docs);
    $srcMap  = asched_sources();
    $pageMap = [];
    try {
        require_once __DIR__ . '/asdoc_page_lib.php';
        $pageMap = eg_asdoc_page_map($db);
    } catch (Throwable $e) { /* 取不到網頁對照＝該欄留白 */ }

    $rows = [];
    $routine = [];
    foreach ($docs as $d) {
        $docId = (int)$d['id'];
        $h     = $hist[$docId] ?? [];
        $page  = $pageMap[$docId] ?? null;

        /* 這份文件的完成紀錄「查得到嗎」——畫面要據此提示是自動判定還是要人工登記。
           ★auto 與 auto_empty 一定要分開：來源登記表接上了模組，不代表那個模組裡真的有資料
             （實測 2-SM-02-04 客戶滿意度監控表接的是 cs_monitor，而該表目前 0 筆）。
             混成一種的話，畫面會說「系統自動判定」卻永遠判不出東西，看起來像功能壞掉。
           ★none＝四種來源全都查不到。**這種一律不可以判逾期**（見下方 state 判定）：
             系統不知道≠沒做，把「不知道」印成「逾期 242 天」是假資料，使用者看一次就不信了。 */
        $srcKind = isset($srcMap[$d['doc_no']])
                 ? ($h ? 'auto' : 'auto_empty')
                 : ($h ? ($h[0]['src'] === 'manual' ? 'manual' : 'paper') : 'none');
        $canJudge = ($srcKind !== 'none');   // 判不判得出「沒做」

        $base = [
            'doc_id'     => $docId,
            'doc_no'     => (string)$d['doc_no'],
            'doc_name'   => (string)$d['doc_name'],
            'doc_level'  => (string)$d['doc_level'],
            'freq_type'  => (string)$d['freq_type'],
            'freq_n'     => (int)$d['freq_n'],
            'freq_label' => (string)$d['freq_label'],
            'freq_note'  => (string)($d['freq_note'] ?? ''),
            'depts'      => $d['depts'],
            'src_kind'   => $srcKind,
            'src_label'  => $srcMap[$d['doc_no']]['label'] ?? '',
            'page_url'   => $page['url'] ?? '',
            'page_label' => $page['label'] ?? '',
            'last_done'  => $h[0]['date'] ?? '',
            'last_src'   => $h[0]['src'] ?? '',
        ];

        // ── 每日／每週型：常態工作，不排月份、不算逾期 ──
        if (!asched_is_calendar_freq($d['freq_type'])) {
            $unit = asFreqUnits()[$d['freq_type']] ?? '';
            $routine[] = $base + ['period_key' => '',
                'reason' => '每' . $unit . '型屬常態工作，不列入月份排程與逾期提醒'];
            continue;
        }

        // ── 排程月份 ──
        $pastMonths = [];
        foreach ($h as $x) $pastMonths[] = (int)substr($x['date'], 5, 2);
        $mi     = asched_months_for($d, $pastMonths, (bool)$set['infer_month']);
        $months = $mi['months'];

        // 月份推不出來：仍要列一列「今年度應執行一次（月份未定）」，不可整份消失
        if (!$months) {
            $doneDate = asched_period_done($h, $year, null);
            $rows[] = $base + [
                'period_key' => asched_period_key($year, null),
                'due_month'  => 0, 'due_date' => '', 'month_src' => 'none',
                'done_date'  => $doneDate, 'done' => $doneDate !== '',
                // 查不到任何完成紀錄的，問題比「月份未定」更根本，優先顯示 unknown
                'state'      => $doneDate !== '' ? 'done' : ($canJudge ? 'nomonth' : 'unknown'),
                'days'       => null,
            ];
            continue;
        }

        foreach ($months as $m) {
            $m   = (int)$m;
            $due = date('Y-m-t', mktime(0, 0, 0, $m, 1, $year));   // 該月最後一天
            $doneDate = asched_period_done($h, $year, $m);
            $days = (int)floor((strtotime($due) - strtotime($today)) / 86400);

            /* ★state 判定的關鍵一條：查不到完成紀錄（src_kind='none'）時一律不判逾期。
               系統查不到≠這件事沒做——那 10 份沒有系統頁面的文件（天車保養表、量測室溫濕度、
               三份績效評核表…）多半是現場做在紙上、只是沒上傳。把「不知道」印成「逾期 242 天」
               會讓畫面出現一整排假逾期，使用者看一次就不信這個功能了。
               這種一律標 unknown，由缺口盤點要求「人工登記」或「補接來源」來解決。 */
            if ($doneDate !== '')                    $state = 'done';
            elseif (!$canJudge)                      $state = 'unknown';
            elseif ($days < 0)                       $state = 'overdue';
            elseif ($days <= (int)$set['lead_days']) $state = 'due';
            else                                     $state = 'upcoming';

            $rows[] = $base + [
                'period_key' => asched_period_key($year, $m),
                'due_month'  => $m, 'due_date' => $due, 'month_src' => $mi['src'],
                'done_date'  => $doneDate, 'done' => $doneDate !== '',
                'state'      => $state, 'days' => $days,
            ];
        }
    }

    // 逾期最前、再依到期日；unknown 排在「還沒到期」之後（它是設定缺口不是急事）
    $ord = ['overdue' => 0, 'due' => 1, 'upcoming' => 2, 'nomonth' => 3, 'unknown' => 4, 'done' => 5];
    usort($rows, function ($a, $b) use ($ord) {
        $c = ($ord[$a['state']] ?? 9) <=> ($ord[$b['state']] ?? 9);
        if ($c) return $c;
        $c = strcmp((string)$a['due_date'], (string)$b['due_date']);
        return $c ?: strcmp($a['doc_no'], $b['doc_no']);
    });

    if (!empty($opt['calendar_only'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['due_date'] !== ''));
    }

    return ['year' => $year, 'rows' => $rows, 'routine' => $routine, 'settings' => $set];
}

/** 狀態顯示文字（畫面、列印、通知共用同一組說法，不各自寫一份） */
function asched_state_text(string $state, $days = null): string {
    switch ($state) {
        case 'done':     return '已完成';
        case 'overdue':  return '逾期 ' . abs((int)$days) . ' 天';
        case 'due':      return (int)$days === 0 ? '今天到期' : ('還有 ' . (int)$days . ' 天');
        case 'upcoming': return '尚未到期';
        case 'nomonth':  return '月份未定';
        case 'unknown':  return '無法判定';
    }
    return $state;
}

/** 月份來源顯示文字 */
function asched_month_src_text(string $src): string {
    switch ($src) {
        case 'fixed': return '管理員指定';
        case 'infer': return '由過去紀錄推估';
        case 'none':  return '未指定';
    }
    return $src;
}

/** 完成紀錄來源顯示文字 */
function asched_src_kind_text(string $k): string {
    switch ($k) {
        case 'auto':       return '系統自動判定';
        case 'auto_empty': return '已接模組，該模組尚無資料';
        case 'paper':      return '紙本／通用表單紀錄';
        case 'manual':     return '人工登記';
        case 'none':       return '查不到（需人工登記）';
    }
    return $k;
}

/** 彙總統計（畫面上方的數字卡） */
function asched_summary(array $plan): array {
    $s = ['total' => 0, 'done' => 0, 'overdue' => 0, 'due' => 0, 'upcoming' => 0,
          'nomonth' => 0, 'unknown' => 0,
          'routine' => count($plan['routine'] ?? []), 'no_source' => 0];
    $seenNo = [];
    foreach ($plan['rows'] as $r) {
        $s['total']++;
        if (isset($s[$r['state']])) $s[$r['state']]++;
        if ($r['src_kind'] === 'none' && !isset($seenNo[$r['doc_no']])) { $seenNo[$r['doc_no']] = 1; $s['no_source']++; }
    }
    return $s;
}

/* ══════════════════════════ 缺口盤點 ══════════════════════════ */

/**
 * 主動列出「不補就永遠不會被提醒，而且不會報錯」的缺口：
 *  ①沒設更新頻率的文件 ②有週期卻沒設負責課室的
 *  ③系統查不到完成紀錄的 ④有負責課室卻沒設通知對象的部門
 */
function asched_gaps(PDO $db, int $year, ?array $plan = null): array {
    asched_ensure($db);
    $out = ['no_freq' => [], 'no_dept' => [], 'no_source' => [], 'no_notify' => []];

    // doc_id 一定要帶：缺口清單上的文件要能直接點開「更新頻率／負責課室」就地設定
    foreach (asched_docs($db, true) as $d) {
        $t = (string)($d['freq_type'] ?? '');
        $ref = ['doc_id' => (int)$d['id'], 'doc_no' => $d['doc_no'], 'doc_name' => $d['doc_name']];
        if ($t === '') { $out['no_freq'][] = $ref; continue; }
        if (!asched_is_calendar_freq($t)) continue;
        if (!$d['depts']) $out['no_dept'][] = $ref;
    }

    if ($plan === null) $plan = asched_plan($db, $year);
    $seen = [];
    foreach ($plan['rows'] as $r) {
        if ($r['src_kind'] === 'none' && !isset($seen[$r['doc_no']])) {
            $seen[$r['doc_no']] = 1;
            $out['no_source'][] = ['doc_id' => (int)$r['doc_id'], 'doc_no' => $r['doc_no'], 'doc_name' => $r['doc_name']];
        }
    }

    // 有負責課室但該部門沒設通知對象
    try {
        $cfg = [];
        foreach ($db->query("SELECT DISTINCT dept_id FROM as_sched_notify_cfg")->fetchAll(PDO::FETCH_COLUMN) as $x) $cfg[(int)$x] = 1;
        $dseen = [];
        foreach ($plan['rows'] as $r) {
            foreach ($r['depts'] as $dp) {
                if (isset($cfg[$dp['id']]) || isset($dseen[$dp['id']])) continue;
                $dseen[$dp['id']] = 1;
                $out['no_notify'][] = ['dept_id' => $dp['id'], 'dept_name' => $dp['name']];
            }
        }
    } catch (Throwable $e) { /* 表不存在＝全部都沒設 */ }

    return $out;
}

/* ══════════════════════════ 通知對象 ══════════════════════════ */

/** 逐部門的通知對象設定（原樣回傳供設定頁顯示） */
function asched_notify_cfg(PDO $db): array {
    asched_ensure($db);
    $out = [];
    try {
        foreach ($db->query("SELECT dept_id, kind, ref_id FROM as_sched_notify_cfg")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['dept_id']][(string)$r['kind']][] = (int)$r['ref_id'];
        }
    } catch (Throwable $e) { /* 沒設過 */ }
    return $out;
}

/** 寫入某部門的通知對象（唯一寫入點；只收真實存在的 position／user） */
function asched_notify_cfg_save(PDO $db, int $deptId, array $positionIds, array $userIds): void {
    asched_ensure($db);
    if ($deptId <= 0) throw new Exception('部門不正確');
    $chk = $db->prepare("SELECT 1 FROM department WHERE id=?");
    $chk->execute([$deptId]);
    if (!$chk->fetchColumn()) throw new Exception('部門不存在');

    $pos = array_values(array_unique(array_filter(array_map('intval', $positionIds), fn($v) => $v > 0)));
    $usr = array_values(array_unique(array_filter(array_map('intval', $userIds),     fn($v) => $v > 0)));
    if ($pos) {
        $ph = implode(',', array_fill(0, count($pos), '?'));
        $st = $db->prepare("SELECT id FROM position WHERE id IN ($ph)"); $st->execute($pos);
        $pos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($usr) {
        $ph = implode(',', array_fill(0, count($usr), '?'));
        $st = $db->prepare("SELECT id FROM `user` WHERE id IN ($ph)"); $st->execute($usr);
        $usr = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM as_sched_notify_cfg WHERE dept_id=?")->execute([$deptId]);
        $ins = $db->prepare("INSERT IGNORE INTO as_sched_notify_cfg (dept_id, kind, ref_id) VALUES (?,?,?)");
        foreach ($pos as $p) $ins->execute([$deptId, 'position', $p]);
        foreach ($usr as $u) $ins->execute([$deptId, 'user', $u]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 解析「某幾個負責課室的排程，提醒要發給誰」。
 *  ①該部門（含子部門）中職位在設定清單內的在職人員
 *  ②設定裡指定的人員（不限部門——代理人、管理代表這種情況本來就跨部門）
 *  ③該部門完全沒設定時，退回該單位最高主管（eg_unit_supervisor()，ai-rules/24）
 *    ——不可以「沒設定就不發」，那等於這份文件永遠沒人知道該做。
 */
function asched_notify_targets(PDO $db, array $deptIds): array {
    asched_ensure($db);
    $cfg  = asched_notify_cfg($db);
    $uids = [];

    foreach ($deptIds as $did) {
        $did = (int)$did;
        if ($did <= 0) continue;
        $c = $cfg[$did] ?? null;

        if ($c) {
            $sub    = eg_dept_subtree_ids($db, $did);
            $posIds = $c['position'] ?? [];
            if ($posIds && $sub) {
                $ph1 = implode(',', array_fill(0, count($sub), '?'));
                $ph2 = implode(',', array_fill(0, count($posIds), '?'));
                try {
                    $st = $db->prepare("SELECT DISTINCT m.user_id
                                        FROM user_department_position_map m
                                        JOIN `user` u ON u.id = m.user_id
                                        WHERE m.department_id IN ($ph1) AND m.position_id IN ($ph2)
                                          AND u.state NOT IN (0, 90)");
                    $st->execute(array_merge(array_map('intval', $sub), array_map('intval', $posIds)));
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $uids[(int)$u] = 1;
                } catch (Throwable $e) { error_log('[asched] notify pos failed: ' . $e->getMessage()); }
            }
            if (!empty($c['user'])) {
                $ph = implode(',', array_fill(0, count($c['user']), '?'));
                try {
                    $st = $db->prepare("SELECT id FROM `user` WHERE id IN ($ph) AND state NOT IN (0,90)");
                    $st->execute(array_map('intval', $c['user']));
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $uids[(int)$u] = 1;
                } catch (Throwable $e) { error_log('[asched] notify user failed: ' . $e->getMessage()); }
            }
            continue;
        }

        /* 沒設定：退回「這個單位的最高主管」。
           ★一定要用 eg_unit_dept_head()（某單位的最高主管），不是 eg_unit_supervisor()——
             後者第二個參數是 **userId 不是 deptId**（簽名是 eg_unit_supervisor($db,$userId,$deptId,…)），
             而且語意是「某個人的單位主管」會把本人排除、往上一層找。這裡沒有「某個人」可談，
             要的就是該課室掛最高職級的那一位。第一版誤把 dept_id 當 userId 傳進去，
             結果解析永遠回不到人、preview 的 targets 一直是空的（php -l 抓不到，只有真的跑資料才看得見）。 */
        try {
            require_once __DIR__ . '/unit_supervisor_lib.php';
            $head = eg_unit_dept_head($db, $did, date('Y-m-d'), 0);
            if (!empty($head['id'])) $uids[(int)$head['id']] = 1;
        } catch (Throwable $e) { error_log('[asched] fallback dept head failed: ' . $e->getMessage()); }
    }

    return array_map('intval', array_keys($uids));
}

/* ══════════════════════════ 權限 ══════════════════════════ */

/** 目前登入者（session 鍵是 userName，見記憶 session_userid_key） */
function asched_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_uname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function asched_has_role(PDO $db, int $uid, array $codes): bool {
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='as_schedule' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 權限：
 *   isAdmin  系統管理者（固定全權）
 *   canAdmin AS排程管理員：改提醒設定、通知對象、代所有課室登記完成
 *   canView  檢閱：看得到全部課室的排程（唯讀）
 *   canMark  可人工登記完成：管理員，或「該文件負責課室」的人（逐列再判）
 * ★AS 文件檢閱權（eg_asdoc_user_can）視同可檢視——這份排程講的就是 AS 文件，
 *   看得到文件卻看不到它的排程沒有意義，也省掉管理員要在兩個地方各指派一次。
 */
function asched_perms(PDO $db, ?array $u): array {
    $none = ['isAdmin'=>false,'canAdmin'=>false,'canView'=>false,'uid'=>0,'name'=>''];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    // 離職／特殊帳號 fail-closed
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return $none;

    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;

    $canAdmin = $isAdmin || asched_has_role($db, $uid, ['asched_admin']);
    $canView  = $canAdmin || asched_has_role($db, $uid, ['asched_view']);
    if (!$canView) {
        try { $canView = eg_asdoc_user_can($db, $uid, 'view'); } catch (Throwable $e) {}
    }
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canView'=>$canView,
            'uid'=>$uid, 'name'=>(string)$u['user_cname']];
}

function asched_role_label(array $p): string {
    if ($p['isAdmin'])  return '管理者';
    if ($p['canAdmin']) return 'AS排程管理員';
    if ($p['canView'])  return 'AS排程檢閱';
    return '無權限';
}

/** 這個人是不是某份文件負責課室的成員（含子部門）——決定能不能人工登記該文件的完成。 */
function asched_in_owner_dept(PDO $db, int $uid, array $depts): bool {
    if ($uid <= 0 || !$depts) return false;
    $ids = [];
    foreach ($depts as $dp) foreach (eg_dept_subtree_ids($db, (int)$dp['id']) as $x) $ids[(int)$x] = 1;
    if (!$ids) return false;
    $ids = array_keys($ids);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_department_position_map
                            WHERE user_id=? AND department_id IN ($ph) LIMIT 1");
        $st->execute(array_merge([$uid], $ids));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/* ══════════════════════════ 人工完成登記 ══════════════════════════ */

/** 人工登記「這個週期做了」。同一份文件同一週期覆寫（不累加第二筆）。 */
function asched_mark_done(PDO $db, int $docId, string $periodKey, string $doneDate, string $note, int $byId, string $byName): void {
    asched_ensure($db);
    if ($docId <= 0) throw new Exception('文件不正確');
    if (!preg_match('~^\d{4}-(M(0[1-9]|1[0-2])|Y)$~', $periodKey)) throw new Exception('週期格式不正確');
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $doneDate)) throw new Exception('請填寫完成日期');
    if ($doneDate > date('Y-m-d')) throw new Exception('完成日期不可以是未來的日期');
    $chk = $db->prepare("SELECT 1 FROM as_document WHERE id=? AND is_deleted=0");
    $chk->execute([$docId]);
    if (!$chk->fetchColumn()) throw new Exception('文件不存在');
    // 完成日期的年份必須與週期一致，否則「這一期做了沒」會永遠對不起來
    if (substr($periodKey, 0, 4) !== substr($doneDate, 0, 4)) throw new Exception('完成日期的年份與所登記的週期不符');

    $db->prepare("INSERT INTO as_sched_done (doc_id, period_key, done_date, note, done_by, done_by_name, created_at)
                  VALUES (?,?,?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE done_date=VALUES(done_date), note=VALUES(note),
                        done_by=VALUES(done_by), done_by_name=VALUES(done_by_name), created_at=NOW()")
       ->execute([$docId, $periodKey, $doneDate, mb_substr($note, 0, 500), $byId ?: null, $byName ?: null]);
}

/** 取消人工登記 */
function asched_unmark_done(PDO $db, int $docId, string $periodKey): void {
    asched_ensure($db);
    $db->prepare("DELETE FROM as_sched_done WHERE doc_id=? AND period_key=?")->execute([$docId, $periodKey]);
}

}
