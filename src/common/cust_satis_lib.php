<?php
/**
 * cust_satis_lib.php — 客戶滿意度（2-SM-02-03 統計資料表／2-SM-02-04 監控表）唯一實作
 * 建立：2026-09-18
 *
 * 【這個模組解決什麼】
 * 紙本的兩張表過去都是人工彙整：統計資料表要把每一家客戶的五項評分抄成一張表、
 * 監控表要把「準交率」「客戶開立異常處理單件數」這些數字一家一家查出來填。
 * 這些數字站上本來就有，所以本庫把**能算的一律自動算**，人只需要填「問卷才問得到的那幾項」。
 *
 * 【哪些自動、哪些一定要人填 —— 很重要，不要把不能算的硬算出來】
 *   交期分 ← 準交率（order_track 交期 vs 綁到它的出貨單最早出貨日）        ＝自動
 *   品質分 ← 退貨率／退貨件數（ir_track）＋客戶開立的異常處理單（car_order）＝自動
 *   技術分／服務分／價格分                                                ＝**只能由客戶問卷來**，
 *       系統沒有任何資料可以推導這三項，硬編一個分數出來就是假資料，稽核一問就破。
 *       所以這三欄留白讓業務照 2-SM-02-02 問卷回收結果填，畫面上也明講原因。
 *
 * 【準交率為什麼要跟 KPI 用同一套判定】
 * KPI 的「準時出貨率」預設口徑（undone_mode=bind）＝訂單追蹤的訂單 ＋ 綁到它的出貨單，
 * 見 kpi_as_lib.php 的 kpi_as_order_bind_rows()。這裡逐客戶算的必須是同一套規則，
 * 否則同一家客戶在 KPI 頁與滿意度頁會看到兩個不一樣的準交率，誰都不知道要信哪一個。
 * 兩邊唯一的差別是：KPI 按「月」算、這裡按「期間」算並且 GROUP BY 客戶。
 *
 * 【評分換算】分數不是拍腦袋來的，換算級距存在 system_parameters，管理員可調（鐵律4，不寫死）。
 */
if (!function_exists('cs_ensure_schema')) {

define('CS_PARAM_GROUP', 'CUST_SATIS');
/** AS 文件綁定模組代碼（走 asdoc_lib，見 ai-rules/16 第一之三節） */
define('CS_ASDOC_STAT',    'cs_stat');      // 2-SM-02-03 統計資料表
define('CS_ASDOC_MONITOR', 'cs_monitor');   // 2-SM-02-04 監控表
define('CS_ASDOC_SURVEY',  'cs_survey');    // 2-SM-02-02 客戶滿意度調查問卷

/* ============================================================
 * Schema（可重複執行）
 * ============================================================ */
function cs_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS cs_score (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year SMALLINT NOT NULL,
        quarter TINYINT NOT NULL DEFAULT 0 COMMENT '0=整年度，1~4=季',
        customer_id VARCHAR(20) NOT NULL COMMENT 'customer_list.customer_id',
        customer_name VARCHAR(100) NOT NULL COMMENT '當時的客戶簡稱（客戶改名時舊表仍印得出原名）',
        score_quality  DECIMAL(4,1) NULL COMMENT '品質（可由系統建議分帶入後人工調整）',
        score_delivery DECIMAL(4,1) NULL COMMENT '交期（同上）',
        score_tech     DECIMAL(4,1) NULL COMMENT '技術（只能由問卷來）',
        score_service  DECIMAL(4,1) NULL COMMENT '服務（只能由問卷來）',
        score_price    DECIMAL(4,1) NULL COMMENT '價格（只能由問卷來）',
        remark VARCHAR(500) NULL,
        metrics_json TEXT NULL COMMENT '建立當下的自動指標快照（準交率/退貨率…），供事後追溯當時憑什麼給這個分數',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        updated_at DATETIME NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_cs (year, quarter, customer_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='客戶滿意度統計資料表(2-SM-02-03) 逐客戶評分'");

    $db->exec("CREATE TABLE IF NOT EXISTS cs_summary (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year SMALLINT NOT NULL,
        quarter TINYINT NOT NULL DEFAULT 0,
        analysis_text TEXT NULL COMMENT '綜合分析（紙本左下角那一大格）',
        stat_date DATE NULL COMMENT '表單上的日期＝業務日期，版次依它回推',
        updated_at DATETIME NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_cssum (year, quarter)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='客戶滿意度統計資料表 綜合分析'");

    $db->exec("CREATE TABLE IF NOT EXISTS cs_monitor (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year SMALLINT NOT NULL,
        quarter TINYINT NOT NULL DEFAULT 0,
        customer_id VARCHAR(20) NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        sort_order SMALLINT NOT NULL DEFAULT 0,
        item_name VARCHAR(100) NOT NULL COMMENT '調查項目',
        target_text VARCHAR(100) NULL COMMENT '績效指標（例：90分、2件/季、95%）',
        auto_key VARCHAR(30) NULL COMMENT '對應哪個自動指標：satis_score/car_count/ontime_rate/return_rate；NULL=純人工填',
        result_text VARCHAR(100) NULL COMMENT '調查結果（有 auto_key 時由系統帶入）',
        customer_suggestion VARCHAR(500) NULL,
        action_plan VARCHAR(500) NULL,
        effect_followup VARCHAR(500) NULL,
        car_no VARCHAR(40) NULL COMMENT '未達標時開立的異常處理單單號',
        monitor_date DATE NULL COMMENT '表單上的日期＝業務日期',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        KEY idx_csm (year, quarter, customer_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='客戶滿意度監控表(2-SM-02-04)'");

    /* 受調查名單：滿意度調查**不是每家都做**（每年 11 月由業務挑幾家寄問卷），
       所以「這一年要調查誰」必須存起來——沒列入的客戶不該出現在逐客戶評分表上被當成漏填。 */
    $db->exec("CREATE TABLE IF NOT EXISTS cs_survey_target (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year SMALLINT NOT NULL,
        quarter TINYINT NOT NULL DEFAULT 0,
        customer_id VARCHAR(20) NOT NULL COMMENT '同 cs_score：主檔查不到的用 #簡稱（cs_score_cid）',
        customer_name VARCHAR(100) NOT NULL,
        pick_reason VARCHAR(50) NULL COMMENT '怎麼挑上的：manual/random_ship_times/random_top_amount',
        sent_date DATE NULL COMMENT '問卷寄出日期（列印問卷時記）',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_cst (year, quarter, customer_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='客戶滿意度 本年度受調查名單'");

    /* 回收的問卷：逐題等第存 answers_json，事後要查「當初為什麼是 8.5 分」查得到。
       mode＝item 逐題勾選／direct 直接填五項／total 只填一個總分。 */
    $db->exec("CREATE TABLE IF NOT EXISTS cs_survey (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year SMALLINT NOT NULL,
        quarter TINYINT NOT NULL DEFAULT 0,
        customer_id VARCHAR(20) NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        mode VARCHAR(10) NOT NULL DEFAULT 'item' COMMENT 'item/direct/total',
        answers_json TEXT NULL COMMENT '逐題等第：{題號:等第索引}',
        total_score DECIMAL(5,1) NULL COMMENT 'mode=total 時客戶回的總分（滿分 100）',
        respondent VARCHAR(50) NULL COMMENT '問卷填寫者',
        respondent_title VARCHAR(50) NULL COMMENT '職稱',
        reply_date DATE NULL COMMENT '客戶填表日期',
        comment_text TEXT NULL COMMENT '客戶的抱怨／建言（問卷下半部那一大格）',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        updated_at DATETIME NULL, updated_by INT NULL, updated_by_name VARCHAR(50) NULL,
        UNIQUE KEY uk_csv (year, quarter, customer_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='客戶滿意度 回收問卷（2-SM-02-02）'");

    /* 客戶填好寄回來的問卷掃描檔。一次可以傳很多份，傳完再逐份指定是哪一家，
       所以 customer_id 允許空白（還沒指定）。檔案位置依鐵律5 即時組，DB 只存檔名。 */
    $db->exec("CREATE TABLE IF NOT EXISTS cs_survey_file (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year SMALLINT NOT NULL,
        quarter TINYINT NOT NULL DEFAULT 0,
        customer_id VARCHAR(20) NULL,
        customer_name VARCHAR(100) NULL,
        file_name VARCHAR(190) NOT NULL COMMENT '實際落地檔名（不存絕對路徑）',
        orig_name VARCHAR(190) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL, created_by_name VARCHAR(50) NULL,
        KEY idx_csf (year, quarter, customer_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='客戶滿意度 回收問卷附件'");
}

/* ============================================================
 * 設定（評分換算級距／預設績效指標）—— 一律存 DB 可調，不寫死
 * ============================================================ */
function cs_param_get(PDO $db, string $key, $default) {
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([CS_PARAM_GROUP, $key]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') return $default;
        $d = json_decode((string)$v, true);
        return $d === null ? $default : $d;
    } catch (Throwable $e) { return $default; }
}
function cs_param_save(PDO $db, string $key, $val, string $by = ''): void {
    $json = json_encode($val, JSON_UNESCAPED_UNICODE);
    try {
        $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute([CS_PARAM_GROUP, $key]);
        $rid = $st->fetchColumn();
        if ($rid) $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $by, $rid]);
        else $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by) VALUES (?,?,?,?,?)")
                ->execute([CS_PARAM_GROUP, $key, $json, '客戶滿意度：'.$key, $by]);
    } catch (Throwable $e) {}
}

/** 交期分換算級距（準交率 % → 10 分制）。由高到低比對，第一個命中的就是分數。 */
function cs_grade_delivery(PDO $db): array {
    return cs_param_get($db, 'grade_delivery', [
        ['min' => 98, 'score' => 10], ['min' => 95, 'score' => 9], ['min' => 90, 'score' => 8],
        ['min' => 85, 'score' => 7],  ['min' => 80, 'score' => 6], ['min' => 0,  'score' => 5],
    ]);
}
/** 品質分換算級距（退貨率 % → 10 分制）。退貨率越低分數越高，所以比對的是「不超過」。 */
function cs_grade_quality(PDO $db): array {
    return cs_param_get($db, 'grade_quality', [
        ['max' => 0,   'score' => 10], ['max' => 0.5, 'score' => 9], ['max' => 1,  'score' => 8],
        ['max' => 2,   'score' => 7],  ['max' => 3,   'score' => 6], ['max' => 999,'score' => 5],
    ]);
}
/** 監控表的預設調查項目（紙本上那三列）。管理員可增減，auto_key 決定「調查結果」要不要自動帶。 */
function cs_monitor_default_items(PDO $db): array {
    return cs_param_get($db, 'monitor_items', [
        ['item_name' => '客戶滿意度調查', 'target_text' => '80分',  'auto_key' => 'satis_score'],
        ['item_name' => '客戶開立異常處理單', 'target_text' => '2件/季', 'auto_key' => 'car_count'],
        ['item_name' => '<航太>準交率',   'target_text' => '95%',   'auto_key' => 'ontime_rate'],
    ]);
}

/* ============================================================
 * 問卷（2-SM-02-02 客戶滿意度調查問卷）
 * ============================================================ */

/**
 * 問卷題目：完全照紙本 2-SM-02-02 的十題與五個大項。
 * **大項對應的就是統計資料表上那五欄**（cat＝score_* 的後綴），所以問卷一填完分數就進得去。
 * 題目可在設定改（題目會隨程序書改版），但 cat 只能是這五個。
 */
function cs_survey_questions(PDO $db): array {
    return cs_param_get($db, 'survey_questions', [
        ['no'=>1,  'cat'=>'quality',  'text'=>'產品合格率水準評價'],
        ['no'=>2,  'cat'=>'quality',  'text'=>'產品品質檢驗標準與貴公司要求是否滿意'],
        ['no'=>3,  'cat'=>'quality',  'text'=>'對品質觀念與整體制度評價'],
        ['no'=>4,  'cat'=>'quality',  'text'=>'不良品反應處理方式與配合度評價'],
        ['no'=>5,  'cat'=>'delivery', 'text'=>'交期準確是否滿意'],
        ['no'=>6,  'cat'=>'service',  'text'=>'服務人員態度評價'],
        ['no'=>7,  'cat'=>'service',  'text'=>'客訴問題改善對策有效性是否滿意'],
        ['no'=>8,  'cat'=>'service',  'text'=>'運送管理與售後服務評價'],
        ['no'=>9,  'cat'=>'price',    'text'=>'所提供價格與貴公司期待需求評價'],
        ['no'=>10, 'cat'=>'tech',     'text'=>'專業能力評價'],
    ]);
}

/** 五個大項的顯示名稱與在紙本上的順序（品質→交期→服務→價格→技術，同紙本） */
function cs_survey_cats(): array {
    return ['quality'=>'品質', 'delivery'=>'交期', 'service'=>'服務', 'price'=>'價格', 'tech'=>'技術'];
}

/**
 * 五個等第與對應分數（使用者 2026-09-18 定：非常滿意10／很滿意9／滿意8／普通6／不滿意4）。
 * 存成設定可改——等第的分數是業務判斷不是程式常數。
 */
function cs_survey_levels(PDO $db): array {
    $v = cs_param_get($db, 'survey_levels', [
        ['label'=>'非常滿意', 'score'=>10], ['label'=>'很滿意', 'score'=>9],
        ['label'=>'滿 意',   'score'=>8],  ['label'=>'普 通', 'score'=>6],
        ['label'=>'不滿意',   'score'=>4],
    ]);
    return is_array($v) && $v ? $v : [];
}

/**
 * 把一份問卷換算成五欄分數。
 *   item   逐題勾選 → **先算每個大項自己的平均**（品質四題平均、服務三題平均），
 *          再由 cs_avg() 拿五個大項去平均（使用者指定的算法）。沒勾的題目不列入平均。
 *   direct 直接填五項分數（客戶只回總評或口頭回覆時用）
 *   total  只填一個總分（滿分 100）→ 平均分配成五項相同分數
 * @return array ['score_quality'=>…, …]（算不出來的一律 null＝尚未填，不可以給 0）
 */
function cs_survey_scores(PDO $db, array $sv): array {
    $out = ['score_quality'=>null, 'score_delivery'=>null, 'score_tech'=>null,
            'score_service'=>null, 'score_price'=>null];
    $mode = (string)($sv['mode'] ?? 'item');

    if ($mode === 'total') {
        $t = $sv['total_score'];
        if ($t === null || $t === '') return $out;
        $s = round(max(0, min(100, (float)$t)) / 10, 1);
        foreach ($out as $k => $_) $out[$k] = $s;
        return $out;
    }
    if ($mode === 'direct') {
        foreach (array_keys($out) as $k) {
            $v = $sv[$k] ?? null;
            $out[$k] = ($v === null || $v === '') ? null : round(max(0, min(10, (float)$v)), 1);
        }
        return $out;
    }

    $ans = $sv['answers'] ?? [];
    if (is_string($ans)) $ans = json_decode($ans, true) ?: [];
    $levels = cs_survey_levels($db);
    $sum = []; $cnt = [];
    foreach (cs_survey_questions($db) as $q) {
        $i = $ans[(string)$q['no']] ?? null;
        if ($i === null || $i === '') continue;
        $i = (int)$i;
        if (!isset($levels[$i])) continue;
        $c = (string)$q['cat'];
        $sum[$c] = ($sum[$c] ?? 0) + (float)$levels[$i]['score'];
        $cnt[$c] = ($cnt[$c] ?? 0) + 1;
    }
    foreach (cs_survey_cats() as $c => $_) {
        if (!empty($cnt[$c])) $out['score_' . $c] = round($sum[$c] / $cnt[$c], 1);
    }
    return $out;
}

/** 期間 → [起日, 迄日]。quarter=0 代表整年度。 */
function cs_period_range(int $year, int $quarter): array {
    if ($quarter >= 1 && $quarter <= 4) {
        $m1 = ($quarter - 1) * 3 + 1;
        return [sprintf('%04d-%02d-01', $year, $m1), date('Y-m-t', mktime(0, 0, 0, $m1 + 2, 1, $year))];
    }
    return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];
}
function cs_period_label(int $year, int $quarter): string {
    return $quarter >= 1 && $quarter <= 4 ? ($year . ' 年 第 ' . $quarter . ' 季') : ($year . ' 年度');
}

/* ============================================================
 * 自動指標
 * ============================================================ */

/**
 * 這個年度的「未交判定方式」—— **直接沿用 KPI 準時出貨率的設定，本模組不另外開一個開關**。
 *
 * 為什麼：KPI 頁的準時出貨率是逐年度可設的（bind／erp_ship／erp，見 CLAUDE.md 2026-09-18），
 * 如果這裡自己再設一次，同一家客戶在 KPI 頁與滿意度頁就會出現兩個不一樣的準交率，
 * 而且兩邊都「看起來是對的」，沒有人查得出哪一個才算數。設定入口只留 KPI 設定頁一個。
 * 查不到設定時退回 KPI 自己的預設值 bind。
 */
function cs_undone_mode(PDO $db, int $year): string {
    try {
        $st = $db->prepare("SELECT params_json FROM kpi_as_indicator_year
                            WHERE calculator_key='order_ontime' AND year=? LIMIT 1");
        $st->execute([$year]);
        $raw = $st->fetchColumn();
        if ($raw) {
            $p = json_decode((string)$raw, true);
            $v = $p['undone_mode']['v'] ?? ($p['undone_mode'] ?? null);
            if (is_string($v) && in_array($v, ['bind', 'erp_ship', 'erp'], true)) return $v;
        }
    } catch (Throwable $e) {}
    return 'bind';
}

/** 未交判定方式的中文說明（畫面與列印都要寫出來，否則沒人知道這個準交率是怎麼算的） */
function cs_undone_mode_label(string $mode): string {
    switch ($mode) {
        case 'erp_ship': return 'ERP 未交清單（查得到同客戶同料號的出貨單就不算未交）';
        case 'erp':      return 'ERP 未交清單（舊制）';
        default:         return '出貨單綁定（訂單有綁到出貨單且最早出貨日不晚於交期）';
    }
}

/* ============================================================
 * 客戶歸戶：ERP 上的出貨／訂單／退貨簡稱 → 客戶主檔
 * ============================================================ */

/**
 * ERP 簡稱 → 客戶主檔（**含別名**）。唯一實作在會計模組的 `acc_customer_by_name()`，
 * 這裡只是轉呼叫——**不要再自己 SELECT 一份 customer_list**（鐵律4）：
 * ERP 寫「高鋒工業」、主檔簡稱是「高鋒」，別名對照表 `acc_customer_alias` 早就記著這一組，
 * 自己查主檔就會變成「同一家客戶在這一頁裂成兩列、而且其中一列沒有客戶ID」。
 * 別名是在對帳頁或本頁綁定的，綁一次全站共用。
 *
 * @return array ['id'=>客戶代號（查不到＝空字串）, 'name'=>主檔簡稱（查不到＝原字串）, 'bound'=>有沒有對到主檔]
 */
function cs_canon(PDO $db, string $name): array {
    static $map = null;
    if ($map === null) {
        require_once __DIR__ . '/acc_lib.php';
        $map = acc_customer_by_name($db);
    }
    $n = trim($name);
    if (isset($map[$n]))
        return ['id' => trim((string)$map[$n]['customer_id']),
                'name' => trim((string)$map[$n]['customer']) ?: $n, 'bound' => true];
    return ['id' => '', 'name' => $n, 'bound' => false];
}

/** 只要正規化後的名稱（統計歸戶用的鍵） */
function cs_canon_name(PDO $db, string $name): string { return cs_canon($db, $name)['name']; }

/** 這家客戶在 ERP 上可能出現的所有寫法（主檔簡稱＋全部別名）。
 *  查「同一家客戶的出貨」時一定要用這份，只用退貨單上的那個寫法會漏掉別名下的出貨。 */
function cs_canon_variants(PDO $db, string $canonName): array {
    static $rev = null;
    if ($rev === null) {
        require_once __DIR__ . '/acc_lib.php';
        $rev = [];
        foreach (acc_customer_by_name($db) as $raw => $c) {
            $k = trim((string)$c['customer']) ?: (string)$raw;
            $rev[$k][] = (string)$raw;
        }
    }
    $n = trim($canonName);
    $v = $rev[$n] ?? [];
    if (!in_array($n, $v, true)) $v[] = $n;
    return $v;
}

/** 把「以 ERP 簡稱為鍵」的統計陣列併成「以客戶主檔為鍵」；$merge 決定兩筆怎麼相加 */
function cs_canon_merge(PDO $db, array $byName, callable $merge): array {
    $out = [];
    foreach ($byName as $nm => $v) {
        $k = cs_canon_name($db, (string)$nm);
        $out[$k] = isset($out[$k]) ? $merge($out[$k], $v) : $v;
    }
    return $out;
}

/**
 * 逐客戶準交率（判定口徑與 KPI「準時出貨率」完全相同，見 cs_undone_mode()）。
 * ZZZ 與 -jg/-jh/-hg 結尾的料號一律排除（與 KPI 同一組條件，不要在這裡自己放寬）。
 *
 * bind：訂單追蹤的訂單 ＋ 綁到它的出貨單（is_list.Order_id 與 shipment_order_map 兩個來源都要看）。
 * erp／erp_ship：分母一樣取訂單追蹤，未交量取 ERP 未交清單；erp_ship 另外把「查得到同客戶同料號
 *   出貨單」的那幾筆從未交扣掉。**這兩張表是各自獨立的資料**（order_track 客戶存中文名、
 *   order_list 客戶存代號），所以要 JOIN customer_list 才對得起來——這也是 KPI 那邊的做法。
 *
 * @return array customer_name => ['den'=>訂單數, 'num'=>準時數, 'rate'=>%|null]
 */
function cs_ontime_by_customer(PDO $db, string $from, string $to, string $mode = 'bind'): array {
    $BASE = " UPPER(ot.d_id)<>'ZZZ' AND LOWER(ot.d_id) NOT REGEXP '-(jg|jh|hg)$'";
    $out = [];

    if ($mode === 'bind') {
        $sql = "SELECT TRIM(ot.Client_name) cn,
                       COUNT(*) AS den,
                       SUM(CASE WHEN LEAST(COALESCE(d1.d,'9999-12-31'), COALESCE(d2.d,'9999-12-31')) <= DATE(ot.Delivery_date)
                                THEN 1 ELSE 0 END) AS num
                FROM order_track ot
                LEFT JOIN (SELECT Order_id, MIN(DATE(Order_date)) d FROM is_list
                            WHERE Order_id IS NOT NULL AND Order_id>0 GROUP BY Order_id) d1
                       ON d1.Order_id=ot.Order_id
                LEFT JOIN (SELECT som.Order_id, MIN(DATE(il.Order_date)) d
                             FROM shipment_order_map som JOIN is_list il ON il.IS_id=som.IS_id
                            GROUP BY som.Order_id) d2
                       ON d2.Order_id=ot.Order_id
                WHERE" . $BASE . " AND DATE(ot.Delivery_date) BETWEEN ? AND ?
                GROUP BY TRIM(ot.Client_name)";
        try {
            $st = $db->prepare($sql); $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $den = (int)$r['den']; $num = (int)$r['num'];
                $out[(string)$r['cn']] = ['den'=>$den, 'num'=>$num, 'undone'=>$den - $num,
                                          'undone_over'=>0, 'rate'=>$den > 0 ? round($num/$den*100, 1) : null];
            }
        } catch (Throwable $e) {}
        return $out;
    }

    // erp／erp_ship：逐月算再加總（kpi_as_oo_ship_hint() 是按月設計的，直接沿用不另寫一份）
    require_once __DIR__ . '/kpi_as_lib.php';
    $m0 = (int)substr($from, 5, 2); $y0 = (int)substr($from, 0, 4);
    $m1 = (int)substr($to,   5, 2); $y1 = (int)substr($to,   0, 4);
    for ($y = $y0, $m = $m0; $y < $y1 || ($y === $y1 && $m <= $m1); $m++) {
        if ($m > 12) { $m = 1; $y++; if ($y > $y1) break; }
        $ym = sprintf('%04d-%02d', $y, $m);
        try {
            $st = $db->prepare("SELECT TRIM(ot.Client_name) cn, COUNT(*) c FROM order_track ot
                                WHERE" . $BASE . " AND DATE_FORMAT(ot.Delivery_date,'%Y-%m')=?
                                GROUP BY TRIM(ot.Client_name)");
            $st->execute([$ym]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cn = (string)$r['cn'];
                if (!isset($out[$cn])) $out[$cn] = ['den'=>0, 'num'=>0, 'rate'=>null, '_undone'=>0];
                $out[$cn]['den'] += (int)$r['c'];
            }
            $st = $db->prepare("SELECT ot.Order_id, TRIM(COALESCE(cl.customer, ot.Client_name)) AS cname, ot.d_id, ot.Delivery_date
                                FROM order_list ot
                                LEFT JOIN customer_list cl ON cl.customer_id=ot.Client_name
                                WHERE" . $BASE . " AND DATE_FORMAT(ot.Delivery_date,'%Y-%m')=?
                                  AND ot.Qty=ot.Open_Qty AND ot.Order_status IS NULL");
            $st->execute([$ym]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $hit  = ($mode === 'erp_ship' && $rows) ? kpi_as_oo_ship_hint($db, $rows, $y, $m) : [];
            foreach ($rows as $r) {
                $cn = (string)$r['cname'];
                if ($mode === 'erp_ship') {
                    $k = trim((string)$r['cname']) . "\x00" . trim((string)$r['d_id']);
                    if (isset($hit[$k])) continue;   // 查得到出貨單＝不算未交
                }
                if (!isset($out[$cn])) $out[$cn] = ['den'=>0, 'num'=>0, 'rate'=>null, '_undone'=>0];
                $out[$cn]['_undone'] = ($out[$cn]['_undone'] ?? 0) + 1;
            }
        } catch (Throwable $e) {}
    }
    /* 【逐客戶視角會攤開一個既有的資料問題，一定要標示出來不可以安靜吃掉】
       分母取訂單追蹤（order_track，我們自己建的）、未交取 ERP 未交清單（order_list），
       兩張表各自獨立，所以會出現「某客戶在訂單追蹤裡 0 張訂單，ERP 卻說它有 42 筆未交」
       （2026 實測：盟英 0 vs 42、吉輔 60 vs 96、傳仕 11 vs 37，全年超出合計上百筆）。
       全公司一起算的時候這些超出會被 max(0,…) 吸收掉看不出來；逐客戶算就會現形。
       這裡的處理：num 仍然夾在 0 以上（準時數不可能是負的），但**把超出量回傳成 undone_over**，
       畫面與列印標「ERP 未交筆數多於訂單筆數，準交率僅供參考」——
       這是兩張表對不起來的既有結構問題，不是本模組算錯，也不該由本模組偷偷修掉。 */
    foreach ($out as $cn => $v) {
        $den = (int)$v['den']; $un = (int)($v['_undone'] ?? 0);
        $num = max(0, $den - $un);
        $out[$cn] = ['den'=>$den, 'num'=>$num, 'undone'=>$un,
                     'undone_over' => max(0, $un - $den),
                     'rate' => $den > 0 ? round($num/$den*100, 1) : null];
    }
    return $out;
}

/**
 * 逐客戶退貨（ir_track＝退貨追蹤）。
 *
 * 口徑（2026-09-18 依使用者指正改）：退貨率＝「本期間交出去的貨，有多少被退回來」，
 * 分母＝本期間出貨量、分子＝本期間出貨中被退回的量，**結構上不可能超過 100%**。
 *
 * 不可以直接拿「本期間發生的退貨量 ÷ 本期間出貨量」——**退貨的月份跟出貨的月份常常不是同一個**：
 * 實測伍宏 2026 年出 245 支、退 484 支，舊算法得出 197.55%，而那 484 支裡有 242 支是 2025 年 6 月出的貨。
 * 因此每一張退貨單依「同客戶＋同料號主檔（d_setting_id）、出貨日不晚於退貨日」往回沖銷，
 * **由最近的一次出貨開始**（剛交過去的那批才是被退回來的），沖完為止；沖到哪一期的出貨，
 * 就算在哪一期的分子上。退貨單依發生順序處理，同一批出貨不會被重複沖掉。
 *
 * 對不到任何出貨的退貨（找不到同客戶同料號、或出貨早到不在 is_list 裡）**不可以就這樣消失**：
 * 一律算進「退貨日所在期間」的分子，並另外回報筆數讓畫面標示出來。
 *
 * @return array customer_name => [
 *     'cnt'  => 算進本期間的退貨筆數,  'qty'  => 算進本期間的退貨量（分子）,
 *     'ship_qty' => 本期間出貨量（分母）, 'rate' => %|null（沒有出貨就不算率，不是 0%）,
 *     'erp_cnt'/'erp_qty'           => 退貨日落在本期間的原始筆數與數量（ERP 口徑，供對帳說明）,
 *     'cross_cnt'/'cross_qty'       => 其中「對應的出貨不在本期間」而歸去別期的部分,
 *     'unmatched_cnt'/'unmatched_qty'=> 對不到任何出貨的部分（仍計入分子）]
 */
function cs_return_by_customer(PDO $db, string $from, string $to): array {
    $blank = ['cnt'=>0, 'qty'=>0.0, 'ship_qty'=>0.0, 'rate'=>null,
              'erp_cnt'=>0, 'erp_qty'=>0.0, 'cross_cnt'=>0, 'cross_qty'=>0.0,
              'unmatched_cnt'=>0, 'unmatched_qty'=>0.0];
    $out = [];
    $touch = function (string $c) use (&$out, $blank) { if (!isset($out[$c])) $out[$c] = $blank; };

    /* ① 分母：本期間出貨量 */
    try {
        $st = $db->prepare("SELECT TRIM(Client_name) c, COALESCE(SUM(Qty),0) q
                            FROM is_list WHERE DATE(Order_date) BETWEEN ? AND ? GROUP BY TRIM(Client_name)");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = cs_canon_name($db, (string)$r['c']); $touch($c);
            $out[$c]['ship_qty'] += (float)$r['q'];   // 別名合併後同一家可能有好幾個 ERP 簡稱，要相加
        }
    } catch (Throwable $e) {}

    /* ② 全部退貨單，依發生順序（沖銷一定要照時間先後，不然後來的退貨會先把貨吃掉） */
    $rets = [];
    try {
        $st = $db->query("SELECT IR_id, TRIM(Client_name) c, d_setting_id ds, Qty q, DATE(IR_date) d
                          FROM ir_track WHERE Qty>0 ORDER BY IR_date, IR_id");
        $rets = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rets = []; }
    if (!$rets) { foreach ($out as $c => $v) $out[$c]['rate'] = $v['ship_qty'] > 0 ? 0.0 : null; return $out; }

    /* ③ 只撈「退貨單用得到」的那些客戶×料號的出貨（含更早期間，沖銷要往回找） */
    $dsIds = []; $names = [];
    foreach ($rets as $r) {
        $dsIds[(int)$r['ds']] = 1;
        // 退貨掛「高鋒工業」、出貨掛「高鋒」是常態，所以要把這家客戶的每一種寫法都撈進來
        foreach (cs_canon_variants($db, cs_canon_name($db, (string)$r['c'])) as $v) $names[$v] = 1;
    }
    $dsIds = array_keys($dsIds); $names = array_keys($names);
    $ship = [];   // "客戶|料號主檔" => [['d'=>出貨日, 'left'=>還沒被退掉的量], …]（日期由舊到新）
    if ($dsIds && $names) {
        try {
            $q1 = implode(',', array_fill(0, count($dsIds), '?'));
            $q2 = implode(',', array_fill(0, count($names), '?'));
            $st = $db->prepare("SELECT TRIM(Client_name) c, d_setting_id ds, Qty q, DATE(Order_date) d
                                FROM is_list
                                WHERE d_setting_id IN ($q1) AND TRIM(Client_name) IN ($q2) AND Qty>0
                                ORDER BY Order_date, IS_id");
            $st->execute(array_merge($dsIds, $names));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s)
                $ship[cs_canon_name($db, (string)$s['c']) . '|' . (int)$s['ds']][]
                    = ['d'=>(string)$s['d'], 'left'=>(float)$s['q']];
        } catch (Throwable $e) {}
    }

    /* ④ 逐張退貨往回沖銷 */
    foreach ($rets as $r) {
        $c = cs_canon_name($db, (string)$r['c']); $touch($c);
        $rd = (string)$r['d'];
        $inPeriodRet = ($rd >= $from && $rd <= $to);          // 退貨日落在本期間
        if ($inPeriodRet) { $out[$c]['erp_cnt']++; $out[$c]['erp_qty'] += (float)$r['q']; }

        $left = (float)$r['q']; $hitP = 0.0; $hitOther = 0.0;
        $key  = $c . '|' . (int)$r['ds'];
        if (isset($ship[$key])) {
            $lst = &$ship[$key];
            for ($i = count($lst) - 1; $i >= 0 && $left > 0; $i--) {   // 由最近的一次出貨往回沖
                if ($lst[$i]['left'] <= 0 || $lst[$i]['d'] > $rd) continue;
                $take = min($left, $lst[$i]['left']);
                $lst[$i]['left'] -= $take; $left -= $take;
                if ($lst[$i]['d'] >= $from && $lst[$i]['d'] <= $to) $hitP += $take; else $hitOther += $take;
            }
            unset($lst);
        }
        if ($hitP > 0) { $out[$c]['cnt']++; $out[$c]['qty'] += $hitP; }
        if ($inPeriodRet && $hitOther > 0) { $out[$c]['cross_cnt']++; $out[$c]['cross_qty'] += $hitOther; }
        if ($left > 0 && $inPeriodRet) {   // 對不到出貨的：算進本期間，但要標示出來
            $out[$c]['unmatched_cnt']++; $out[$c]['unmatched_qty'] += $left;
            if ($hitP <= 0) $out[$c]['cnt']++;
            $out[$c]['qty'] += $left;
        }
    }

    /* ⑤ 退貨率：沒有出貨就不算率（null，不是 0%）；上限 100%（分子本來就是分母的一部分，
          只有「對不到出貨的退貨」有可能把它推過頭，那種情況一律以 100% 表示） */
    foreach ($out as $c => $v) {
        $out[$c]['rate'] = $v['ship_qty'] > 0 ? min(100.0, round($v['qty'] / $v['ship_qty'] * 100, 2)) : null;
    }
    return $out;
}

/**
 * 逐客戶「客戶開立的異常處理單」件數。
 * car_order.counterparty_type='customer' ＝對方是客戶那一種；以 fill_date 歸期間。
 * @return array customer_id => 件數
 */
function cs_car_by_customer(PDO $db, string $from, string $to): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT customer_id, COUNT(*) c FROM car_order
                            WHERE counterparty_type='customer' AND customer_id IS NOT NULL AND customer_id<>''
                              AND DATE(fill_date) BETWEEN ? AND ? GROUP BY customer_id");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['customer_id']] = (int)$r['c'];
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 一次算好某期間每一家客戶的自動指標，並附上建議分數。
 *
 * 「有往來」的定義（2026-09-18 依使用者指正收斂）＝**該期間真的有出貨**（is_list），
 * 另加「有退貨算進本期間」的（那也是實際往來，只是出貨在更早期間）。
 * **只有訂單、沒有出貨的不列**——客戶滿意度評的是交出去的貨，訂單那一側常有測試用或代號沒建主檔的
 * 假客戶（例：`NA` 只在 order_track 有 1 張訂單，客戶主檔查無此人，卻被列成一整列要人評分）。
 * 實測 2026 年：舊定義 250 家 → 新定義 171 家。整份客戶主檔（900 多家）當然更不可以全列。
 *
 * @return array 依客戶簡稱排序的列，每列含 name/id/ontime/return/car/suggest_*
 */
function cs_auto_metrics(PDO $db, int $year, int $quarter): array {
    list($from, $to) = cs_period_range($year, $quarter);
    // 準交率是依 ERP 簡稱分組回來的，這裡併成「一家客戶一列」（高鋒＋高鋒工業＝同一家）
    $ontime = cs_canon_merge($db, cs_ontime_by_customer($db, $from, $to, cs_undone_mode($db, $year)),
        function ($a, $b) {
            $den = (int)$a['den'] + (int)$b['den']; $num = (int)$a['num'] + (int)$b['num'];
            return ['den'=>$den, 'num'=>$num,
                    'undone'      => (int)($a['undone'] ?? 0)      + (int)($b['undone'] ?? 0),
                    'undone_over' => (int)($a['undone_over'] ?? 0) + (int)($b['undone_over'] ?? 0),
                    'rate'        => $den > 0 ? round($num / $den * 100, 1) : null];
        });
    $ret = cs_return_by_customer($db, $from, $to);   // 這支內部已經以客戶主檔為鍵
    $car = cs_car_by_customer($db, $from, $to);

    // 只列「本期間有出貨」或「有退貨算在本期間」的客戶（$ret 兩種數字都在裡面）
    $names = [];
    foreach ($ret as $nm => $v) {
        if ((float)$v['ship_qty'] > 0 || (int)$v['cnt'] > 0) $names[] = $nm;
    }
    $names = array_values(array_unique(array_filter($names, 'strlen')));
    sort($names, SORT_FLAG_CASE | SORT_STRING);

    $gd = cs_grade_delivery($db); $gq = cs_grade_quality($db);
    $rows = [];
    foreach ($names as $nm) {
        $canon = cs_canon($db, $nm);
        $cid   = $canon['id'];
        $o   = $ontime[$nm] ?? ['den'=>0, 'num'=>0, 'rate'=>null, 'undone'=>0, 'undone_over'=>0];
        $r   = $ret[$nm]    ?? ['cnt'=>0, 'qty'=>0.0, 'ship_qty'=>0.0, 'rate'=>null];
        $cc  = $cid !== '' ? (int)($car[$cid] ?? 0) : 0;
        $rows[] = [
            'customer_id'   => $cid,
            'customer_name' => $nm,
            // 對不到客戶主檔（連別名都沒有）＝ERP 上的寫法還沒綁定，畫面要標出來讓人去綁
            'unbound'       => $canon['bound'] ? 0 : 1,
            'ontime_rate'   => $o['rate'], 'ontime_num' => $o['num'], 'ontime_den' => $o['den'],
            'ontime_over'   => (int)($o['undone_over'] ?? 0),   // >0＝ERP 未交比訂單還多，準交率僅供參考
            'return_cnt'    => $r['cnt'],  'return_qty' => $r['qty'],
            'ship_qty'      => $r['ship_qty'], 'return_rate' => $r['rate'],
            // 退貨怎麼算出來的（畫面滑鼠提示用）：ERP 原始筆數／歸去別期的／查不到出貨的
            'return_erp_cnt'       => (int)($r['erp_cnt'] ?? 0),
            'return_erp_qty'       => (float)($r['erp_qty'] ?? 0),
            'return_cross_cnt'     => (int)($r['cross_cnt'] ?? 0),
            'return_cross_qty'     => (float)($r['cross_qty'] ?? 0),
            'return_unmatched_cnt' => (int)($r['unmatched_cnt'] ?? 0),
            'return_unmatched_qty' => (float)($r['unmatched_qty'] ?? 0),
            'car_count'     => $cc,
            // 建議分：算不出來時一律 null（留白讓人填），**不可以給 0 分**——
            // 0 分代表「很差」，跟「沒有資料」是完全不同的意思，印在稽核表上會冤枉客戶關係。
            'suggest_delivery' => cs_score_from_rate($o['rate'], $gd, 'min'),
            'suggest_quality'  => cs_score_from_rate($r['rate'], $gq, 'max'),
        ];
    }
    return $rows;
}

/** 依級距把比率換成分數；$rate 為 null（沒有資料）一律回 null。 */
function cs_score_from_rate($rate, array $grades, string $mode) {
    if ($rate === null || $rate === '') return null;
    $rate = (float)$rate;
    foreach ($grades as $g) {
        if ($mode === 'min' && $rate >= (float)($g['min'] ?? 0))  return (float)$g['score'];
        if ($mode === 'max' && $rate <= (float)($g['max'] ?? 0))  return (float)$g['score'];
    }
    return null;
}

/** 分數正規化：DECIMAL(4,1) 讀回來是字串 "9.0"，畫面上要顯示「9」（UI 規則：小數尾 0 省略）。
 *  null／空字串一律保持 null＝尚未填，**不可以轉成 0**（0 分與沒填是完全不同的意思）。 */
function cs_score_norm($v) {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) return null;
    $f = (float)$v;
    return ($f === floor($f)) ? (int)$f : round($f, 1);
}

/** 五項平均（只平均「有填的」項目；一項都沒有回 null） */
function cs_avg(array $row) {
    $v = [];
    foreach (['score_quality','score_delivery','score_tech','score_service','score_price'] as $k) {
        if ($row[$k] !== null && $row[$k] !== '') $v[] = (float)$row[$k];
    }
    return $v ? round(array_sum($v) / count($v), 1) : null;
}

/* ============================================================
 * 統計資料表（2-SM-02-03）
 * ============================================================ */

/** 某期間的統計資料表內容＝自動指標 ∪ 已存的評分（左外聯，沒存過的也要列出來讓人填） */
/**
 * 存 cs_score／cs_monitor 時用的客戶鍵。
 * ERP 的出貨簡稱常常在客戶主檔查不到（實測 2026 年 54 家），那些客戶 `customer_id` 是空的，
 * 唯一鍵 (year,quarter,customer_id) 會讓它們全部擠在同一列，所以空代號一律補成 `#簡稱`。
 * **這個規則只寫在這裡一處**——寫入端補了、讀取端沒補，就會變成「分數存得進去卻讀不回來」：
 * 統計表會同時長出一列空白的（自動指標那列）與一列「本期無出貨」的（存起來那列），
 * 而監控表則是存了之後再打開完全空白，兩種症狀都不會報錯。
 */
function cs_score_cid(string $cid, string $name): string {
    $cid = trim($cid);
    return $cid !== '' ? $cid : '#' . mb_substr(trim($name), 0, 18);
}

/* ── 受調查名單／回收問卷／問卷附件 ───────────────────────── */

/** 本期受調查名單（key＝cs_score_cid） */
function cs_targets(PDO $db, int $year, int $quarter): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT * FROM cs_survey_target WHERE year=? AND quarter=? ORDER BY customer_name");
        $st->execute([$year, $quarter]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['customer_id']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** 本期已回收的問卷（key＝cs_score_cid） */
function cs_surveys(PDO $db, int $year, int $quarter): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT * FROM cs_survey WHERE year=? AND quarter=?");
        $st->execute([$year, $quarter]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['customer_id']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** 本期的問卷附件（未指定客戶的 customer_id 是 NULL，一律回在 '' 這個鍵底下） */
function cs_survey_files(PDO $db, int $year, int $quarter): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT * FROM cs_survey_file WHERE year=? AND quarter=? ORDER BY id DESC");
        $st->execute([$year, $quarter]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** 問卷附件的存放資料夾（鐵律5：DB 只存檔名，路徑讀取當下才組） */
function cs_attach_dir(PDO $db): string {
    require_once __DIR__ . '/attach_lib.php';
    $dir = eg_attach_dir($db, 'cs_attach_dir', '客戶滿意度');
    eg_attach_ensure_dir($dir);
    return $dir;
}

/**
 * 隨機篩選的候選：本期間每一家客戶的出貨次數（出貨單張數）與出貨金額。
 * 客戶一律經 cs_canon 歸戶，跟統計表同一套（不然「高鋒工業」會被當成另一家）。
 * @return array 依金額由大到小，每列 ['id','name','times','amount']
 */
function cs_ship_stats(PDO $db, int $year, int $quarter): array {
    list($from, $to) = cs_period_range($year, $quarter);
    $acc = [];
    try {
        $st = $db->prepare("SELECT TRIM(Client_name) c, IS_number,
                                   COALESCE(SUM(Qty*Unit_price),0) amt
                            FROM is_list WHERE DATE(Order_date) BETWEEN ? AND ?
                            GROUP BY TRIM(Client_name), IS_number");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $canon = cs_canon($db, (string)$r['c']);
            $k = cs_score_cid($canon['id'], $canon['name']);
            if (!isset($acc[$k])) $acc[$k] = ['id'=>$canon['id'], 'name'=>$canon['name'], 'times'=>0, 'amount'=>0.0];
            $acc[$k]['times']++;                       // 一張出貨單算一次
            $acc[$k]['amount'] += (float)$r['amt'];
        }
    } catch (Throwable $e) {}
    $rows = array_values($acc);
    usort($rows, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
    return $rows;
}

function cs_stat_rows(PDO $db, int $year, int $quarter): array {
    $auto = cs_auto_metrics($db, $year, $quarter);
    $saved = [];
    try {
        $st = $db->prepare("SELECT * FROM cs_score WHERE year=? AND quarter=?");
        $st->execute([$year, $quarter]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $saved[(string)$r['customer_id'] . '|' . (string)$r['customer_name']] = $r;
    } catch (Throwable $e) {}
    // 客戶代號可能是空的（主檔沒這家），所以 key 用「代號|簡稱」兩段，避免不同家被併在一起
    $out = [];
    foreach ($auto as $a) {
        $k = cs_score_cid((string)$a['customer_id'], (string)$a['customer_name']) . '|' . $a['customer_name'];
        $s = $saved[$k] ?? null;
        $row = $a + [
            'score_quality'  => $s ? cs_score_norm($s['score_quality'])  : null,
            'score_delivery' => $s ? cs_score_norm($s['score_delivery']) : null,
            'score_tech'     => $s ? cs_score_norm($s['score_tech'])     : null,
            'score_service'  => $s ? cs_score_norm($s['score_service'])  : null,
            'score_price'    => $s ? cs_score_norm($s['score_price'])    : null,
            'remark'         => $s ? $s['remark']         : null,
            'saved'          => $s ? 1 : 0,
        ];
        $row['avg_score'] = cs_avg($row);
        $out[] = $row;
        unset($saved[$k]);
    }
    // 已存過但這期間查不到往來紀錄的（例如客戶改名、或資料被刪），仍要列出來不可以憑空消失
    foreach ($saved as $s) {
        $row = [
            'customer_id'=>(string)$s['customer_id'], 'customer_name'=>(string)$s['customer_name'],
            'ontime_rate'=>null,'ontime_num'=>0,'ontime_den'=>0,'ontime_over'=>0,
            'return_cnt'=>0,'return_qty'=>0,'ship_qty'=>0,'return_rate'=>null,'car_count'=>0,
            'suggest_delivery'=>null,'suggest_quality'=>null,
            'score_quality'=>cs_score_norm($s['score_quality']),'score_delivery'=>cs_score_norm($s['score_delivery']),
            'score_tech'=>cs_score_norm($s['score_tech']),'score_service'=>cs_score_norm($s['score_service']),
            'score_price'=>cs_score_norm($s['score_price']),
            'remark'=>$s['remark'],'saved'=>1,'no_activity'=>1,
        ];
        $row['avg_score'] = cs_avg($row);
        $out[] = $row;
    }

    /* 受調查名單：滿意度調查不是每家都做（每年 11 月由業務挑幾家），
       **有建名單時就只列名單內的客戶**——沒被挑到的列出來只會變成一整排永遠填不了的空白。
       名單還沒建（或舊年度沒有名單）時維持列出全部，並由呼叫端提示「尚未建立名單」。 */
    $targets = cs_targets($db, $year, $quarter);
    $svs     = cs_surveys($db, $year, $quarter);
    $fileCnt = [];
    foreach (cs_survey_files($db, $year, $quarter) as $f) {
        $c = (string)($f['customer_id'] ?? '');
        if ($c !== '') $fileCnt[$c] = ($fileCnt[$c] ?? 0) + 1;
    }
    foreach ($out as &$r) {
        $k = cs_score_cid((string)$r['customer_id'], (string)$r['customer_name']);
        $r['in_target']   = isset($targets[$k]) ? 1 : 0;
        $r['survey_mode'] = isset($svs[$k]) ? (string)$svs[$k]['mode'] : '';
        $r['survey_done'] = isset($svs[$k]) ? 1 : 0;
        $r['file_count']  = (int)($fileCnt[$k] ?? 0);
        $r['sent_date']   = isset($targets[$k]) ? ($targets[$k]['sent_date'] ?? null) : null;
    }
    unset($r);
    if ($targets) {
        $out = array_values(array_filter($out, function ($r) { return !empty($r['in_target']); }));
    } else {
        /* **還沒挑客戶就不可以把全部客戶列出來**（2026-09-18 使用者指正）：
           一整排客戶擺在「逐客戶評分」底下，看起來就像今年的受調查客戶已經選好了。
           這裡只留「先前真的評過分」的那幾列（不可以讓已填的分數憑空不見），
           一列都沒有就回空陣列，由畫面顯示「請先到問卷作業挑客戶」。 */
        $out = array_values(array_filter($out, function ($r) { return !empty($r['saved']); }));
    }
    return $out;
}

function cs_summary_get(PDO $db, int $year, int $quarter): array {
    try {
        $st = $db->prepare("SELECT * FROM cs_summary WHERE year=? AND quarter=? LIMIT 1");
        $st->execute([$year, $quarter]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    } catch (Throwable $e) {}
    return ['year'=>$year, 'quarter'=>$quarter, 'analysis_text'=>'', 'stat_date'=>null];
}

/* ============================================================
 * 監控表（2-SM-02-04）
 * ============================================================ */

/** 自動指標 → 監控表「調查結果」的顯示字串＋有沒有達標 */
function cs_monitor_auto_value(string $autoKey, array $m, $satisAvg): array {
    switch ($autoKey) {
        case 'satis_score':
            return ['text' => $satisAvg === null ? '' : (string)round($satisAvg * 10, 1) . '分',
                    'raw'  => $satisAvg === null ? null : $satisAvg * 10];
        case 'car_count':
            return ['text' => $m['car_count'] . '件', 'raw' => (float)$m['car_count']];
        case 'ontime_rate':
            return ['text' => $m['ontime_rate'] === null ? '' : $m['ontime_rate'] . '%',
                    'raw'  => $m['ontime_rate'] === null ? null : (float)$m['ontime_rate']];
        case 'return_rate':
            return ['text' => $m['return_rate'] === null ? '' : $m['return_rate'] . '%',
                    'raw'  => $m['return_rate'] === null ? null : (float)$m['return_rate']];
    }
    return ['text' => '', 'raw' => null];
}

/**
 * 有沒有達到績效指標。
 * 指標是自由文字（「80分」「2件/季」「95%」），所以只取出數字比大小；
 * 「件數」類是越少越好、其餘是越大越好。看不懂的指標一律回 null＝不判定（不要亂標紅字）。
 */
function cs_monitor_meet(string $autoKey, $raw, ?string $target): ?bool {
    if ($raw === null || $target === null || trim((string)$target) === '') return null;
    if (!preg_match('/-?\d+(\.\d+)?/', $target, $mm)) return null;
    $t = (float)$mm[0];
    $lowerIsBetter = in_array($autoKey, ['car_count', 'return_rate'], true);
    return $lowerIsBetter ? ((float)$raw <= $t) : ((float)$raw >= $t);
}

/** 某客戶某期間的監控表列；沒建過就依預設調查項目即時組出來（不落庫，按儲存才存） */
function cs_monitor_rows(PDO $db, int $year, int $quarter, string $customerId, string $customerName): array {
    // 存檔時空代號會被補成 `#簡稱`，讀取端一定要用同一支函式補一次，否則存了打開卻是空白
    $key = cs_score_cid($customerId, $customerName);
    $saved = [];
    try {
        $st = $db->prepare("SELECT * FROM cs_monitor WHERE year=? AND quarter=? AND customer_id=? ORDER BY sort_order, id");
        $st->execute([$year, $quarter, $key]);
        $saved = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // 這家客戶的自動指標
    $m = null;
    foreach (cs_auto_metrics($db, $year, $quarter) as $a) {
        if ((string)$a['customer_id'] === $customerId || $a['customer_name'] === $customerName) { $m = $a; break; }
    }
    if (!$m) $m = ['ontime_rate'=>null,'return_rate'=>null,'car_count'=>0,'return_cnt'=>0];

    // 滿意度平均分（統計資料表那邊填的）
    $satisAvg = null;
    try {
        $st = $db->prepare("SELECT * FROM cs_score WHERE year=? AND quarter=? AND customer_id=? LIMIT 1");
        $st->execute([$year, $quarter, $key]);
        $sc = $st->fetch(PDO::FETCH_ASSOC);
        if ($sc) $satisAvg = cs_avg($sc);
    } catch (Throwable $e) {}

    if (!$saved) {
        $i = 0;
        foreach (cs_monitor_default_items($db) as $d) {
            $saved[] = ['id'=>0, 'sort_order'=>$i++, 'item_name'=>$d['item_name'],
                        'target_text'=>$d['target_text'], 'auto_key'=>$d['auto_key'],
                        'result_text'=>null, 'customer_suggestion'=>null, 'action_plan'=>null,
                        'effect_followup'=>null, 'car_no'=>null];
        }
    }
    foreach ($saved as &$r) {
        $ak = (string)($r['auto_key'] ?? '');
        if ($ak !== '') {
            // 調查結果一律以「現在算出來的」為準，不採用存檔當下的舊值——
            // 資料會持續進來（補綁出貨單、補開退貨單），存起來就永遠停在存檔那天的數字
            $v = cs_monitor_auto_value($ak, $m, $satisAvg);
            $r['result_text'] = $v['text'];
            $r['meet'] = cs_monitor_meet($ak, $v['raw'], $r['target_text'] ?? null);
        } else {
            $r['meet'] = null;   // 純人工填的項目不判定
        }
    }
    unset($r);
    return $saved;
}

/* ============================================================
 * 權限（roles module='cust_satis'，比照 comm_mgmt 的寫法）
 *   cs_admin 客戶滿意度管理員：填分數、填綜合分析、維護監控表、改設定
 *   cs_view  檢閱：唯讀看全部（含列印）
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 * ============================================================ */
function cs_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_uname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function cs_has_role(PDO $db, int $uid, array $codes): bool {
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='cust_satis' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}
function cs_perms(PDO $db, ?array $u): array {
    $none = ['isAdmin'=>false, 'canAdmin'=>false, 'canView'=>false, 'uid'=>0, 'name'=>''];
    if (!$u) return $none;
    $uid = (int)$u['id'];
    // 離職／特殊帳號一律 fail-closed
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return $none;
    $isAdmin = false;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (!$isAdmin && $uid === 1) $isAdmin = true;
    $canAdmin = $isAdmin || cs_has_role($db, $uid, ['cs_admin']);
    $canView  = $canAdmin || cs_has_role($db, $uid, ['cs_view']);
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canView'=>$canView,
            'uid'=>$uid, 'name'=>(string)($u['user_cname'] ?: $u['user_uname'])];
}

/* ============================================================
 * 製表圖章（ai-rules/18）：只存 stamp_template.id，不存名稱字串
 * ============================================================ */
function cs_stamp_tpl_id(PDO $db): int { return (int)cs_param_get($db, 'stamp_tpl_id', 0); }
function cs_stamp_tpl(PDO $db): ?array {
    $id = cs_stamp_tpl_id($db);
    if (!$id) return null;
    try {
        $st = $db->prepare("SELECT id, tpl_name, schema_json FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id'=>(int)$r['id'], 'tpl_name'=>$r['tpl_name'], 'schema'=>json_decode((string)$r['schema_json'], true)] : null;
    } catch (Throwable $e) { return null; }
}
function cs_stamp_tpl_options(PDO $db): array {
    try {
        return $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p
                           LEFT JOIN stamp_type t ON t.id=p.type_id
                           WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 本公司那一列客戶主檔（公司全名／電話／傳真都從這裡來，一律禁寫死） */
function cs_own_company(PDO $db): array {
    try {
        $r = $db->query("SELECT customer, customer_full, customer_tel, customer_fax, customer_address
                         FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    } catch (Throwable $e) {}
    return [];
}

/** 公司全名（列印大標題，唯一來源 customer_list.is_own_company=1，禁寫死） */
function cs_company_name(PDO $db): string {
    try {
        $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($r) return trim((string)($r['customer_full'] ?: $r['customer']));
    } catch (Throwable $e) {}
    return '';
}

}
