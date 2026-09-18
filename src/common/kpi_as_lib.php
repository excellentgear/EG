<?php
/**
 * AS9100 關鍵績效指標 (2-GM-04-01) 共用函式庫
 * 架構：指標主檔 + 年度版本(目標/公式/擔當者逐年獨立) + 每月快照(含手動覆寫) + 佐證附件 + 權限規則
 * - 快照鎖定：數值定案存 kpi_as_monthly_value；年度結束次月(2/1)起僅管理者可重算
 * - 附件遵守 ai-rules/07：DB 只存檔名，完整路徑讀取當下用 system_settings 設定值即時組
 * - 工作日一律用 evenement 行事曆（car_lib.php），不可用 calendar_workday（該表有誤）
 */
require_once __DIR__ . '/car_lib.php'; // car_holiday_sets / car_working_days_between
require_once __DIR__ . '/delegate_lib.php'; // eg_resolve_signer（擔當者請假代理判定，見 ai-rules/11）

/* ============================================================
 * Schema（依專案慣例：CREATE TABLE IF NOT EXISTS + 首次自動 seed）
 * ============================================================ */
function kpi_as_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_indicator (
        indicator_id INT AUTO_INCREMENT PRIMARY KEY,
        item_no INT NOT NULL COMMENT '項次(對照2-GM-04-01)',
        name VARCHAR(100) NOT NULL COMMENT '指標內容',
        clause VARCHAR(200) NULL COMMENT '對應條文',
        stat_desc VARCHAR(200) NULL COMMENT '統計方式(文字說明)',
        freq ENUM('monthly','quarterly','halfyear','yearly') NOT NULL DEFAULT 'monthly' COMMENT '頻率',
        value_type ENUM('percent','count','score','rate','yesno') NOT NULL DEFAULT 'percent' COMMENT '數值型態',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By VARCHAR(30) NULL,
        Modified_At DATETIME NULL,
        UNIQUE KEY uk_item (item_no)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='AS9100關鍵績效指標主檔(2-GM-04-01)'");

    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_indicator_year (
        iy_id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_id INT NOT NULL,
        year SMALLINT NOT NULL,
        owner_user_id INT NULL COMMENT '擔當者 user.id',
        owner_display VARCHAR(50) NULL COMMENT '擔當者顯示文字(人/課)',
        source_mode ENUM('auto','manual') NOT NULL DEFAULT 'manual' COMMENT 'auto=系統計算 manual=擔當者填寫',
        calculator_key VARCHAR(40) NULL COMMENT '計算模組代號(kpi_as_registry)',
        params_json TEXT NULL COMMENT '計算參數JSON {key:{v:值,fe:0|1開放前端試算}}',
        target_direction ENUM('gte','lte','yes') NOT NULL DEFAULT 'gte' COMMENT '判定方向 gte=大於等於達標 lte=小於等於達標',
        target_value DECIMAL(12,2) NULL COMMENT '判定門檻值',
        target_unit VARCHAR(20) NULL COMMENT '單位(%,件,分,顆/小時)',
        target_text VARCHAR(60) NULL COMMENT '判定目標原文',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        Created_By VARCHAR(30) NULL,
        Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        Modified_By VARCHAR(30) NULL,
        Modified_At DATETIME NULL,
        UNIQUE KEY uk_ind_year (indicator_id, year)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI指標年度版本(目標/公式/擔當者逐年獨立)'");

    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_monthly_value (
        mv_id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_id INT NOT NULL,
        year SMALLINT NOT NULL,
        month TINYINT NOT NULL,
        auto_value DECIMAL(14,4) NULL COMMENT '自動計算值(快照)',
        numerator DECIMAL(14,4) NULL COMMENT '分子(可追溯)',
        denominator DECIMAL(14,4) NULL COMMENT '分母(可追溯)',
        manual_value DECIMAL(14,4) NULL COMMENT '手動填寫值(manual模式)',
        override_value DECIMAL(14,4) NULL COMMENT '覆寫值(顯示優先序最高)',
        override_by INT NULL,
        override_by_name VARCHAR(30) NULL,
        override_at DATETIME NULL,
        override_reason VARCHAR(200) NULL COMMENT '覆寫原因(必填)',
        filled_by INT NULL,
        filled_by_name VARCHAR(30) NULL,
        filled_at DATETIME NULL,
        computed_at DATETIME NULL COMMENT '自動結算時間',
        note VARCHAR(200) NULL,
        UNIQUE KEY uk_cell (indicator_id, year, month)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI每月快照(定案值,含手動覆寫,AS9100可追溯)'");

    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_attachment (
        attach_id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_id INT NOT NULL,
        year SMALLINT NOT NULL,
        month TINYINT NOT NULL,
        file_name VARCHAR(255) NOT NULL COMMENT 'NAS實際檔名(不含路徑,子資料夾=年度即時組)',
        original_name VARCHAR(255) NULL COMMENT '上傳原始檔名',
        file_size INT NULL,
        note VARCHAR(200) NULL COMMENT '附件說明',
        uploaded_by INT NULL,
        uploaded_by_name VARCHAR(30) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cell (indicator_id, year, month)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI佐證附件(DB只存檔名,路徑即時組)'");

    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_perm_rule (
        rule_id INT AUTO_INCREMENT PRIMARY KEY,
        perm_type ENUM('view','fill','admin') NOT NULL COMMENT '授權能力',
        rule_type ENUM('dept_level','user') NOT NULL COMMENT 'dept_level=部門+主管階級 user=指定人員',
        dept_id INT NULL COMMENT '部門id(department.id) NULL=不限部門',
        min_level INT NULL COMMENT '主管階級門檻 position_level.level<=此值(1=一階最高)',
        user_id INT NULL COMMENT 'rule_type=user 時指定人員 user.id',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        Created_By VARCHAR(30) NULL,
        Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI權限規則(部門×主管階級/指定人員),與roles(module=kpi)個人/職稱指派聯集'");

    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_change_log (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_id INT NULL,
        year SMALLINT NULL,
        month TINYINT NULL,
        action VARCHAR(30) NOT NULL COMMENT 'setting/override/fill/recalc/perm/attach...',
        field VARCHAR(60) NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        note VARCHAR(255) NULL,
        changed_by INT NULL,
        changed_by_name VARCHAR(30) NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ind (indicator_id, year)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI設定/數值變更歷史(AS9100可追溯)'");

    // ── 資料來源目錄（no-code builder）：IT 一次性把資料表用中文登記為白名單 ──
    $db->exec("CREATE TABLE IF NOT EXISTS kpi_ds_catalog (
        ds_id INT AUTO_INCREMENT PRIMARY KEY,
        ds_label VARCHAR(60) NOT NULL COMMENT '中文名稱(管理員看到的,例:出貨單)',
        table_name VARCHAR(64) NOT NULL COMMENT '實際資料表名(白名單,只有登記的表可被查)',
        date_column VARCHAR(64) NOT NULL COMMENT '月份歸屬用的日期欄',
        note VARCHAR(200) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        Created_By VARCHAR(30) NULL,
        Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_table (table_name)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI資料來源目錄(白名單資料表,IT登記)'");

    $db->exec("CREATE TABLE IF NOT EXISTS kpi_ds_field (
        field_id INT AUTO_INCREMENT PRIMARY KEY,
        ds_id INT NOT NULL,
        field_label VARCHAR(60) NOT NULL COMMENT '中文欄位名(例:客戶名稱)',
        column_name VARCHAR(64) NOT NULL COMMENT '實際欄位名',
        role ENUM('filter','measure') NOT NULL DEFAULT 'filter' COMMENT 'filter=可作篩選 measure=可加總的數值欄',
        data_type ENUM('text','number','date') NOT NULL DEFAULT 'text',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        KEY idx_ds (ds_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI資料來源目錄-欄位(可篩選/可加總)'");

    // 擔當者改存「部門+職位+人員」三者（兼任者才不會顯示錯誤）；舊表自動補欄
    try { $db->exec("ALTER TABLE kpi_as_indicator_year ADD COLUMN owner_dept_id INT NULL COMMENT '擔當者部門 department.id（兼任者用以精準還原）' AFTER owner_user_id"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE kpi_as_indicator_year ADD COLUMN owner_position_id INT NULL COMMENT '擔當者職位 position.id' AFTER owner_dept_id"); } catch (Throwable $e) {}

    // 「調整」＝把某幾筆來源資料排除在這一格的計算之外（不動真實資料）。
    // 只用於「不可改真實資料」的指標（改真資料會連動帳務月份的那幾項）。
    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_adjust (
        adj_id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_id INT NOT NULL,
        year SMALLINT NOT NULL,
        month TINYINT NOT NULL,
        calculator_key VARCHAR(40) NOT NULL COMMENT '計算模組代號(排除鍵的意義由它決定)',
        row_key VARCHAR(100) NOT NULL COMMENT '來源列識別(如 bom_ing_fid、order_track.Order_id)',
        row_label VARCHAR(255) NULL COMMENT '排除當下的摘要(內部畫面用)',
        row_json TEXT NULL COMMENT '排除當下的內容快照(內部備查，不列印)',
        reason VARCHAR(255) NOT NULL COMMENT '排除原因(必填)',
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cell_row (indicator_id, year, month, row_key),
        KEY idx_cell (indicator_id, year, month)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI計算調整-排除指定來源列(不修改真實資料)'");

    // 「排除規則」＝依維度（客戶／料號／製程／廠商／機台／設計者…）整批排除，
    // 與 kpi_as_adjust（逐筆排除某一個月的某一列）互補：規則一旦建立，該年度 12 個月一體適用。
    // 一樣**只影響 KPI 計算，不動任何一筆真實資料**。
    $db->exec("CREATE TABLE IF NOT EXISTS kpi_as_excl_rule (
        rule_id INT AUTO_INCREMENT PRIMARY KEY,
        indicator_id INT NOT NULL,
        year SMALLINT NOT NULL,
        dim VARCHAR(20) NOT NULL COMMENT '維度代號 client/part/proc/maker/machine/designer/unit',
        val VARCHAR(190) NOT NULL COMMENT '要排除的值(顯示文字，與明細列上的值相同)',
        reason VARCHAR(255) NULL COMMENT '排除原因',
        created_by INT NULL,
        created_by_name VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_rule (indicator_id, year, dim, val),
        KEY idx_iy (indicator_id, year)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='KPI計算調整-依維度整批排除(不修改真實資料)'");

    // 排除規則的適用範圍（使用者要求 2026-09-18）：year=僅該年度 / all=所有年度。
    // all 的那一列 year 一律寫 0，這樣 uk_rule(indicator_id,year,dim,val) 天然保證
    // 「同一個指標的同一個值只會有一條全年度規則」，也不會跟某一年的規則撞鍵。
    try { $db->exec("ALTER TABLE kpi_as_excl_rule ADD COLUMN scope ENUM('year','all') NOT NULL DEFAULT 'year' COMMENT '適用範圍 year=僅該年度 all=所有年度(year 寫 0)' AFTER year"); } catch (Throwable $e) {}

    // 這個指標的來源資料可不可以直接改：suggest=依系統建議 / allow=可改 / deny=只能用排除
    try { $db->exec("ALTER TABLE kpi_as_indicator ADD COLUMN src_edit_mode ENUM('suggest','allow','deny') NOT NULL DEFAULT 'suggest' COMMENT '來源資料可否直接修改 suggest=依系統建議' AFTER is_active"); } catch (Throwable $e) {}

    // 角色 seed（module='kpi'，固定 role_code，供 user_permissions.php 指派）
    foreach ([['kpi_view','KPI檢閱'],['kpi_fill','KPI填報'],['kpi_admin','KPI管理員']] as $r) {
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code=? AND module='kpi' LIMIT 1");
        $st->execute([$r[0]]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES (?,?, 'kpi')")
               ->execute([$r[0], $r[1]]);
        }
    }

    // 指標 seed（僅首次；之後一律由設定頁維護）
    $n = (int)$db->query("SELECT COUNT(*) FROM kpi_as_indicator")->fetchColumn();
    if ($n === 0) kpi_as_seed_indicators($db);

    // 資料來源目錄 seed（僅首次；給非IT管理員現成可組的積木＋示範）
    $dn = (int)$db->query("SELECT COUNT(*) FROM kpi_ds_catalog")->fetchColumn();
    if ($dn === 0) kpi_as_seed_catalog($db);
}

/** 資料來源目錄初始積木（IT 之後可於設定頁增修） */
function kpi_as_seed_catalog(PDO $db): void {
    // [label, table, date_col, note, [ [field_label, column, role, data_type], ... ]]
    $cat = [
        ['出貨單', 'is_list', 'Order_date', '每筆出貨明細（可算筆數／數量加總）', [
            ['客戶名稱','Client_name','filter','text'],
            ['出貨性質','sale_type','filter','number'],
            ['數量','Qty','measure','number'],
            ['料號','Product_id','filter','text'],
        ]],
        ['退貨單', 'ir_track', 'IR_date', '客戶退貨（客訴來源）', [
            ['客戶名稱','Client_name','filter','text'],
            ['退貨性質','return_type_id','filter','number'],
            ['數量','Qty','measure','number'],
        ]],
        ['訂單', 'order_track', 'Delivery_date', '訂單（依交期歸屬月份）', [
            ['客戶名稱','Client_name','filter','text'],
            ['數量','Qty','measure','number'],
            ['料號','d_id','filter','text'],
        ]],
        ['報價單', 'quotation_list', 'quote_date', '報價單', [
            ['客戶名稱','client_name','filter','text'],
            ['草稿','is_draft','filter','number'],
        ]],
        ['出貨檢驗', 'qc_packing_inspection', 'inspection_date', '成品出貨檢驗（可算NG數／全檢數加總）', [
            ['判定','judgement','filter','text'],
            ['NG總數','ng_qty','measure','number'],
            ['實際全檢數','inspected_qty','measure','number'],
            ['合格數','ok_qty','measure','number'],
        ]],
    ];
    $insC = $db->prepare("INSERT INTO kpi_ds_catalog (ds_label, table_name, date_column, note, sort_order, Created_By) VALUES (?,?,?,?,?, 'system-seed')");
    $insF = $db->prepare("INSERT INTO kpi_ds_field (ds_id, field_label, column_name, role, data_type, sort_order) VALUES (?,?,?,?,?,?)");
    $i = 0;
    foreach ($cat as $c) {
        $insC->execute([$c[0], $c[1], $c[2], $c[3], ++$i]);
        $dsId = (int)$db->lastInsertId();
        $j = 0;
        foreach ($c[4] as $f) $insF->execute([$dsId, $f[0], $f[1], $f[2], $f[3], ++$j]);
    }
}

/** 21 項指標初始資料（來源：2-GM-04-01-關鍵績效指標 2025.xlsx） */
function kpi_as_seed_indicators(PDO $db): void {
    // [item_no, name, clause, stat_desc, freq, value_type, dir, target, unit, target_text,
    //  owner_user_id, owner_display, source_mode, calculator_key, params]
    $seed = [
        [1,'客訴頻率','客戶服務管理程序','客訴數量(單)/總出貨(單)','monthly','percent','lte',5,'%','小於5％',111030101,'吳仁隆/業務課','auto','complaint_rate',['exclude_return_types'=>['v'=>[],'fe'=>0]]],
        [2,'月份受訂目標達成金額','合約訂單審查管理程序','訂單金額/月受訂目標金額','monthly','percent','gte',85,'%','達成率大於85％',111030101,'吳仁隆/業務課','auto','order_target_amount',['monthly_targets'=>['v'=>new stdClass(),'fe'=>0]]],
        [3,'月銷貨額達成率','合約訂單審查管理程序','出貨金額/月銷貨目標金額','monthly','percent','gte',85,'%','達成率大於85％',111030101,'吳仁隆/業務課','auto','shipping_target_amount',['monthly_targets'=>['v'=>new stdClass(),'fe'=>0]]],
        [4,'報價單接單率','合約訂單審查管理程序','報價轉訂單數/報價單總數','monthly','percent','gte',70,'%','大於70％',111030101,'吳仁隆/業務課','auto','quote_to_order',['exclude_draft'=>['v'=>1,'fe'=>0]]],
        [5,'客戶滿意度調查','客戶服務管理程序','客戶滿意度調查平均分數','yearly','score','gte',8,'分','大於8分',111030101,'吳仁隆/業務課','manual',null,[]],
        [6,'廠商稽核按時執行率','供應商管理程序','實際稽核廠商數/當月應稽核廠商總數','halfyear','percent','gte',70,'%','達成率大於70％',109110201,'何沐桐/生管組','auto','vendor_audit_ontime',[]],
        [7,'廠商準時交貨率','供應商管理程序 採購管理辦法','每月達交工單筆數/每月工單應交總筆數','monthly','percent','gte',70,'%','達成率大於70％',109110201,'何沐桐/生管組','auto','vendor_ontime',['default_days'=>['v'=>7,'fe'=>1],'days_by_process_type'=>['v'=>new stdClass(),'fe'=>0]]],
        [8,'準時出貨率','客戶服務管理程序 生產管理程序','及時交貨訂單筆數/總訂單出貨筆數','monthly','percent','gte',80,'%','達成率大於80％',109110201,'何沐桐/生管組','auto','order_ontime',['exclude_clients'=>['v'=>['寶嘉誠','泳建'],'fe'=>1]]],
        [9,'發料錯誤件數','生產管理程序 採購管理辦法','當月發料錯誤件數','monthly','count','lte',5,'件','少於5件',111050101,'陳彦驊/倉管組','manual',null,[]],
        [10,'庫存數量正確性','倉儲出貨管理程序','庫存出錯件數/抽盤總件數','quarterly','percent','gte',80,'%','達成率大於80％',111050101,'陳彦驊/倉管組','auto','stock_accuracy',[]],
        [11,'出圖準時率','製程開發作業程序 夾治具管理程序','準時出圖數量/應出圖數量','monthly','percent','gte',85,'%','0.85',109110201,'何沐桐/技術課','auto','drawing_ontime',['threshold_days'=>['v'=>4,'fe'=>1],'exclude_clients'=>['v'=>['中森'],'fe'=>1],'designer_ids'=>['v'=>['109110201','112020603'],'fe'=>0]]],
        [12,'出圖正確性','圖面管理辦法','當月出圖出錯件數','monthly','count','lte',5,'件','少於5件',109110201,'何沐桐/技術課','manual',null,[]],
        [13,'產能績效-創成','生產管理程序','總完成數/機器總工時','monthly','rate','gte',15,'顆/小時','大於15顆/小時',110041901,'林鴻銘/生產課','auto','capacity_rate',['machine_type_ids'=>['v'=>[4],'fe'=>0],'machine_ids'=>['v'=>[],'fe'=>0]]],
        [14,'產能績效-成型','生產管理程序','總完成數/機器總工時','monthly','rate','gte',3,'顆/小時','大於3顆/小時',110041901,'林鴻銘/生產課','auto','capacity_rate',['machine_type_ids'=>['v'=>[5],'fe'=>0],'machine_ids'=>['v'=>[],'fe'=>0]]],
        [15,'齒研製程不良率','不合格品管理程序 檢驗與測試管理程序','總不良數/工件完成總數','monthly','percent','lte',5,'%','不良率小於5％',110041901,'林鴻銘/生產課','auto','process_ng_rate',['process_type_ids'=>['v'=>[12],'fe'=>0]]],
        [16,'進料檢驗不良率','不合格品管理程序 檢驗與測試管理程序','進料檢驗不良數/進料總數(同生管計算)','monthly','percent','lte',5,'%','不良率小於5％',111050101,'陳彦驊/品保課','auto','incoming_ng_rate',['ng_statuses'=>['v'=>['ng'],'fe'=>0]]],
        [17,'成品出貨不良率','不合格品管理程序 檢驗與測試管理程序','出貨檢驗不良數/出貨總數','monthly','percent','lte',5,'%','不良率小於5％',111050101,'陳彦驊/品保課','auto','packing_ng_rate',[]],
        [18,'量測儀器按時校驗率','量測儀器校正管理辦法 量規儀器內校作業標準','校驗完成件數/當月應校驗件數','monthly','percent','gte',95,'%','達成率大於95％',111050101,'陳彦驊/品保課','auto','calibration_ontime',['grace_days'=>['v'=>0,'fe'=>1]]],
        [19,'人員教育訓練達成率','人力資源管理程序','有上課次數/總課程次數','monthly','percent','gte',95,'%','達成率大於95％',105030102,'林雅婷/管理課','auto','training_completion',['include_cancelled'=>['v'=>0,'fe'=>1]]],
        [20,'應收帳款(票據)未收件數','N/A','當月應收但延遲收件數','monthly','count','lte',5,'件','少於5件',109110202,'林郁婷/管理課','manual',null,[]],
        [21,'明細分類帳(損益表)於期限內完成','N/A','每月月底前應完成','monthly','yesno','yes',1,'','Yes/No',109110202,'林郁婷/管理課','manual',null,[]],
    ];
    $insInd = $db->prepare("INSERT INTO kpi_as_indicator (item_no,name,clause,stat_desc,freq,value_type,sort_order) VALUES (?,?,?,?,?,?,?)");
    $insYr  = $db->prepare("INSERT INTO kpi_as_indicator_year
        (indicator_id,year,owner_user_id,owner_display,source_mode,calculator_key,params_json,target_direction,target_value,target_unit,target_text,Created_By)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'system-seed')");
    $baseYear = 2025;
    $curYear  = (int)date('Y');
    foreach ($seed as $s) {
        $insInd->execute([$s[0],$s[1],$s[2],$s[3],$s[4],$s[5],$s[0]]);
        $iid = (int)$db->lastInsertId();
        $pj  = json_encode($s[14], JSON_UNESCAPED_UNICODE);
        for ($y = $baseYear; $y <= $curYear; $y++) {
            $insYr->execute([$iid,$y,$s[10],$s[11],$s[12],$s[13],$pj,$s[6],$s[7],$s[8],$s[9]]);
        }
    }
}

/** 確保某年度有年度版本列（無則從最近的舊年度複製，達成「逐年版本、預設沿用」） */
function kpi_as_ensure_year(PDO $db, int $year): void {
    // 只補「已經存在的年度」缺漏的指標列；要開一個全新的年度請走 kpi_as_year_create()
    // （否則使用者隨手在網址列打一個年度就會安靜地生出一整年設定）
    if (!kpi_as_year_ok($db, $year)) return;
    $rows = $db->query("SELECT i.indicator_id FROM kpi_as_indicator i
                        WHERE i.is_active=1 AND NOT EXISTS
                        (SELECT 1 FROM kpi_as_indicator_year y WHERE y.indicator_id=i.indicator_id AND y.year={$year})")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) return;
    $cp = $db->prepare("INSERT INTO kpi_as_indicator_year
        (indicator_id,year,owner_user_id,owner_display,source_mode,calculator_key,params_json,target_direction,target_value,target_unit,target_text,Created_By)
        SELECT indicator_id, ?, owner_user_id, owner_display, source_mode, calculator_key, params_json,
               target_direction, target_value, target_unit, target_text, 'auto-copy'
        FROM kpi_as_indicator_year WHERE indicator_id=? AND year<? ORDER BY year DESC LIMIT 1");
    foreach ($rows as $iid) $cp->execute([$year, (int)$iid, $year]);
}

/* ============================================================
 * 權限（roles module='kpi' 個人/職稱指派 ∪ 部門×主管階級/指定人員規則）
 * ============================================================ */
function kpi_as_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function kpi_as_has_role(PDO $db, int $uid, array $codes): bool {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                        WHERE ur.user_id=? AND r.module='kpi' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id=m.position_id AND (pr.department_id=0 OR pr.department_id=m.department_id)
                        JOIN roles r ON r.role_id=pr.role_id
                        WHERE m.user_id=? AND r.module='kpi' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

/** 規則授權：指定人員 / 部門×主管階級(position_level.level<=min_level，1=一階最高) */
function kpi_as_rule_perms(PDO $db, int $uid): array {
    $perms = [];
    $st = $db->prepare("SELECT DISTINCT perm_type FROM kpi_as_perm_rule
                        WHERE is_active=1 AND rule_type='user' AND user_id=?");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $perms[$p] = true;
    $st = $db->prepare("SELECT DISTINCT r.perm_type
                        FROM kpi_as_perm_rule r
                        JOIN user_department_position_map m ON (r.dept_id IS NULL OR r.dept_id=m.department_id)
                        JOIN position_level pl ON pl.position_id=m.position_id
                        WHERE r.is_active=1 AND r.rule_type='dept_level' AND m.user_id=?
                          AND pl.level IS NOT NULL AND pl.level <= r.min_level");
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $perms[$p] = true;
    return $perms;
}

/** 回傳 ['isAdmin','canAdmin','canFill','canView'] 能力階層(admin⊃fill⊃view)，fail-closed */
function kpi_as_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canFill'=>false,'canView'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $rule = kpi_as_rule_perms($db, $uid);
    $canAdmin = $isAdmin || kpi_as_has_role($db, $uid, ['kpi_admin']) || !empty($rule['admin']);
    $canFill  = $canAdmin || kpi_as_has_role($db, $uid, ['kpi_fill']) || !empty($rule['fill']);
    $canView  = $canFill  || kpi_as_has_role($db, $uid, ['kpi_view']) || !empty($rule['view']);
    return ['isAdmin'=>$isAdmin,'canAdmin'=>$canAdmin,'canFill'=>$canFill,'canView'=>$canView];
}

/** 舊年度重算鎖：年度結束次月(隔年2/1)起僅管理者可重算/覆寫/補填 */
function kpi_as_year_locked(int $year): bool {
    return time() >= strtotime(($year + 1) . '-02-01');
}
function kpi_as_can_modify(int $year, array $perms, bool $isOwner): bool {
    if ($perms['canAdmin']) return true;
    if (kpi_as_year_locked($year)) return false;
    return $isOwner || $perms['canFill'];
}

/** $uid 是否為 $ownerId 今天請假時的代理人（沿用 delegate_lib 標準解析，ai-rules/11）。 */
function kpi_as_is_delegate_of_owner(PDO $db, int $ownerId, int $uid): bool {
    if ($ownerId <= 0 || $uid <= 0 || $ownerId === $uid) return false;
    if (!function_exists('eg_resolve_signer')) return false;
    $res = eg_resolve_signer($db, $ownerId, ['auto_sign' => true, 'log' => false]);
    return (int)$res['signer_id'] === $uid;
}
/**
 * 手動覆寫／清除覆寫授權：只有擔當者本人、或擔當者今天請假時解析出的代理人可操作；
 * 系統管理者固定全權（RBAC 鐵律）。年度鎖定後（隔年2/1起）僅 KPI 管理員可操作，不受擔當者限制。
 */
function kpi_as_can_override(PDO $db, int $year, array $perms, int $ownerId, int $uid): bool {
    if (kpi_as_year_locked($year)) return !empty($perms['canAdmin']);
    if (!empty($perms['isAdmin'])) return true;
    if ($ownerId === $uid) return true;
    return kpi_as_is_delegate_of_owner($db, $ownerId, $uid);
}

/* ============================================================
 * 附件（路徑即時組：system_settings kpi_attach_base + /年度/檔名）
 * ============================================================ */
function kpi_as_setting(PDO $db, string $key, string $default = ''): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([$key]);
        $v = trim((string)$st->fetchColumn());
        return $v !== '' ? $v : $default;
    } catch (Throwable $e) { return $default; }
}
function kpi_as_attach_base(PDO $db): string {
    $v = kpi_as_setting($db, 'kpi_attach_base');
    if ($v !== '') return rtrim($v, '\\/');
    return realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kpi_attach';
}
function kpi_as_attach_max(PDO $db): int {
    $v = (int)kpi_as_setting($db, 'kpi_attach_max', '5');
    return $v > 0 ? $v : 5;
}
/** 集中路徑解析：所有讀/刪一律經此，防目錄穿越；檔案不存在回 null */
function kpi_as_attach_path(PDO $db, array $att): ?string {
    $fn = basename((string)($att['file_name'] ?? ''));
    if ($fn === '') return null;
    $p = kpi_as_attach_base($db) . DIRECTORY_SEPARATOR . (int)$att['year'] . DIRECTORY_SEPARATOR . $fn;
    return is_file($p) ? $p : null;
}

/* ============================================================
 * 變更歷史
 * ============================================================ */
function kpi_as_log(PDO $db, ?int $iid, ?int $year, ?int $month, string $action, ?string $field,
                    $old, $new, ?string $note, array $u): void {
    $st = $db->prepare("INSERT INTO kpi_as_change_log
        (indicator_id,year,month,action,field,old_value,new_value,note,changed_by,changed_by_name)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$iid, $year, $month, $action, $field,
        is_scalar($old) || $old === null ? $old : json_encode($old, JSON_UNESCAPED_UNICODE),
        is_scalar($new) || $new === null ? $new : json_encode($new, JSON_UNESCAPED_UNICODE),
        $note, (int)$u['id'], (string)$u['user_cname']]);
}

/* ============================================================
 * 資料來源目錄（計算模組 registry：中文名稱/對應頁面/參數 schema）
 * 參數型態：int/num/textlist/intlist/statuslist/months_map/typedays_map/
 *          process_type_ids/machine_type_ids/machine_ids/bool
 * ============================================================ */
function kpi_as_registry(): array {
    return [
        'complaint_rate' => [
            'name' => '客訴頻率(客退單/出貨單)',
            'page' => '退貨單管理 views/Sales/ir.php ＋ 出貨 is_list',
            'tables' => ['is_list','ir_track'],
            'links' => [['label'=>'退貨追蹤（客退單）','url'=>'/EGsystem/views/Sales/IR_Track.php'], ['label'=>'快速出貨(新版)（出貨單）','url'=>'/EGsystem/views/Sales/Shipping_Quick.php']],
            'desc' => '分子=當月客戶退貨單筆數(ir_track)；分母=當月出貨單筆數(is_list)',
            'params' => [
                ['key'=>'exclude_return_types','label'=>'排除退貨性質id(逗號分隔)','type'=>'intlist','fe'=>1],
            ]],
        'order_target_amount' => [
            'name' => '受訂目標達成率(同出貨分析頁)',
            'page' => '出貨分析 views/Sales/Shipping_Analysis_new.php',
            'tables' => ['order_track','kpi_monthly_targets','system_parameters'],
            'links' => [['label'=>'訂單進度追蹤（訂單交期／數量／單價）','url'=>'/EGsystem/src/store/_cleanOrder_Track_ate_only.php'], ['label'=>'KPI 設定（各月受訂目標金額）','url'=>'/EGsystem/views/news/KPI_setting.php'], ['label'=>'出貨分析（全域月目標）','url'=>'/EGsystem/views/Sales/Shipping_Analysis_new.php']],
            'desc' => '帳款月窗口接單金額(order_track 交期歸屬, Qty×單價, 排除狀態9/無單價)÷月受訂目標(kpi_monthly_targets/system_parameters KPI_TARGET)',
            'params' => [
                ['key'=>'monthly_targets','label'=>'各月目標金額(留空=用出貨分析頁全域目標)','type'=>'months_map','fe'=>0],
            ]],
        'shipping_target_amount' => [
            'name' => '銷貨額達成率(出貨金額/月目標)',
            'page' => '出貨管理 is_list',
            'tables' => ['is_list'],
            'links' => [['label'=>'快速出貨(新版)（出貨金額）','url'=>'/EGsystem/views/Sales/Shipping_Quick.php'], ['label'=>'KPI 設定（各月銷貨目標金額）','url'=>'/EGsystem/views/news/KPI_setting.php']],
            'desc' => '分子=當月出貨金額 Σ(Qty×單價)；分母=設定的各月銷貨目標金額',
            'params' => [
                ['key'=>'monthly_targets','label'=>'各月銷貨目標金額(必填才能算)','type'=>'months_map','fe'=>0],
            ]],
        'quote_to_order' => [
            'name' => '報價單接單率',
            'page' => '報價單管理 quotation_list ＋ 訂單 order_track',
            'tables' => ['quotation_list','order_track'],
            'links' => [['label'=>'報價單（報價單筆數／狀態）','url'=>'/EGsystem/views/Sales/quotation_list_NEW.php'], ['label'=>'訂單進度追蹤（訂單引用的報價單號）','url'=>'/EGsystem/src/store/_cleanOrder_Track_ate_only.php']],
            'desc' => '分母=當月報價單數；分子=其中報價單號已被訂單引用(order_track.quote_no)',
            'params' => [
                ['key'=>'exclude_draft','label'=>'排除草稿報價單(1=是)','type'=>'bool','fe'=>1],
            ]],
        'vendor_ontime' => [
            'name' => '廠商準時交貨率(與外包廠商績效頁同一套判定)',
            'page' => '發包管理 bom_ing(發包日/回廠日)',
            'tables' => ['bom_ing','process_no'],
            'links' => [['label'=>'BOM 總表（發包日／回廠日）','url'=>'/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php'], ['label'=>'外包廠商績效（唯一實作：容忍天數／例外廠商／例外製程都在這裡設定）','url'=>'/EGsystem/views/pages/vendor_kpi.php']],
            'desc' => '判定與設定一律吃「外包廠商績效」頁（唯一實作）：容忍天數、廠商／製程特殊天數、例外廠商、例外製程都在那裡設定，兩頁完全一致；分母=本月發包且已到容忍期的筆數，分子=回廠日≤截止日',
            'params' => []],
        'order_ontime' => [
            'name' => '訂單準時出貨率(準交率)',
            'page' => '訂單管理 order_track/order_list',
            'tables' => ['order_track','order_list'],
            'links' => [['label'=>'訂單進度追蹤（交期／狀態）','url'=>'/EGsystem/src/store/_cleanOrder_Track_ate_only.php'], ['label'=>'未交訂單（未交數量）','url'=>'/EGsystem/src/store/_cleanNewOrder_Track.php']],
            'desc' => '分母=當月交期訂單筆數；「未交」怎麼算由「未交判定方式」決定（預設＝以網頁上的出貨綁定為準）；沿用原KPI頁排除規則(d_id ZZZ、-jg/-jh/-hg)',
            'params' => [
                ['key'=>'exclude_clients','label'=>'排除客戶(可填客戶ID或簡稱)','type'=>'client_list','fe'=>1],
                ['key'=>'grace_days','label'=>'寬限工作天數(交期後幾個工作天內交貨仍算準時，0=不寬限)','type'=>'int','fe'=>1],
                ['key'=>'undone_mode','label'=>'未交判定方式(逐年度可分開設定)','type'=>'choice','fe'=>0,
                 'opts'=>[
                    'ship'     => 'D｜以出貨單反推（用料號主檔對應，不必綁定、不必 ERP 未交清單）＝目前唯一資料是新的',
                    'bind'     => 'C｜以網頁上的出貨綁定為準（最精準，但要先把出貨單綁到訂單）',
                    'erp_ship' => 'B｜以 ERP 未交清單為準（該清單自 2026-03-12 起就沒有再匯入，涵蓋不到的月份會顯示無資料）',
                    'erp'      => '舊制｜ERP 未交清單直接相減（同上且會被夾成 0%，不建議）',
                 ]],
            ]],
        'stock_accuracy' => [
            'name' => '庫存正確率(盤點差異)',
            'page' => '庫存盤點 stock_count_sessions/details',
            'tables' => ['stock_count_details','stock_count_sessions'],
            'links' => [['label'=>'庫存管理（盤點作業）','url'=>'/EGsystem/views/pages/stock.php']],
            'desc' => '每季：分母=該季已完成盤點明細筆數；分子=無差異筆數(正確率)',
            'params' => []],
        'drawing_ontime' => [
            'name' => '出圖準時率(業務→設計→生管)',
            'page' => '訂單追蹤 order_track(ateGet/pmGet)',
            'tables' => ['order_track','evenement'],
            'links' => [['label'=>'訂單追蹤（接單移轉設計／設計移轉生管）','url'=>'/EGsystem/views/Sales/NewOrder_Track.php','perm_url'=>'/EGsystem/src/store/_cleanOrder_Track_ate_only.php'], ['label'=>'業務待辦追蹤','url'=>'/EGsystem/views/Sales/Sales_Track.php'], ['label'=>'行事曆管理（工作日認定）','url'=>'/EGsystem/views/pages/calendar.php']],
            'desc' => '接單移轉設計到設計移轉生管 ≤N 工作日(evenement行事曆)為準時',
            'params' => [
                ['key'=>'threshold_days','label'=>'準時門檻(工作日,含起訖日)','type'=>'int','fe'=>1],
                ['key'=>'exclude_clients','label'=>'排除客戶(可填客戶ID或簡稱)','type'=>'client_list','fe'=>1],
                ['key'=>'designer_ids','label'=>'設計者user.id(逗號分隔)','type'=>'textlist','fe'=>0],
            ]],
        'capacity_rate' => [
            'name' => '產能績效(完成數/機器工時)',
            'page' => '現場報工 pm_process_daily_report',
            'tables' => ['pm_process_daily_report','machine_list'],
            'links' => [['label'=>'待加工排程（現場報工登錄）','url'=>'/EGsystem/views/pm/process_schedule_NOW.php'], ['label'=>'報工紀錄查詢','url'=>'/EGsystem/views/pm/process_report_query.php'], ['label'=>'KPI 生產效率分析（機台資產設定）','url'=>'/EGsystem/views/pm/kpi_main.php']],
            'desc' => '分子=Σ本日完成數量；分母=Σ生產起訖工時(小時)；機台範圍=機台種類或指定機台',
            'params' => [
                ['key'=>'machine_type_ids','label'=>'機台種類(製程類別id,逗號分隔)','type'=>'machine_type_ids','fe'=>0],
                ['key'=>'machine_ids','label'=>'指定機台id(逗號分隔,可留空)','type'=>'machine_ids','fe'=>0],
            ]],
        'process_ng_rate' => [
            'name' => '製程不良率(報工NG/完成數)',
            'page' => '現場報工NG pm_process_daily_ng',
            'tables' => ['pm_process_daily_ng','pm_process_daily_report'],
            'links' => [['label'=>'待加工排程（報工NG數登錄）','url'=>'/EGsystem/views/pm/process_schedule_NOW.php'], ['label'=>'報工紀錄查詢','url'=>'/EGsystem/views/pm/process_report_query.php']],
            'desc' => '分子=Σ當月NG數；分母=Σ當月完成數；限指定製程類別(如齒研=12)',
            'params' => [
                ['key'=>'process_type_ids','label'=>'製程類別id(逗號分隔)','type'=>'process_type_ids','fe'=>0],
            ]],
        'incoming_ng_rate' => [
            'name' => '進料檢驗不良率(發包回廠QC)',
            'page' => '發包回廠檢驗 bom_ing(QC_check)',
            'tables' => ['bom_ing'],
            'links' => [['label'=>'QC待驗（回廠檢驗判定）','url'=>'/EGsystem/views/QC/QC_check_list.php'], ['label'=>'BOM 總表（回廠日／檢驗數量）','url'=>'/EGsystem/views/pm/OreadyReply_ForPm_BaseOfTime.php']],
            'desc' => '分母=當月QC檢驗筆數；分子=判定為不良的筆數(預設ng=驗退)',
            'params' => [
                ['key'=>'ng_statuses','label'=>'算不良的判定(ng/QQ/AOD 逗號分隔)','type'=>'statuslist','fe'=>1],
            ]],
        'packing_ng_rate' => [
            'name' => '成品出貨不良率(出貨檢驗)',
            'page' => '出貨檢驗 qc_packing_inspection',
            'tables' => ['qc_packing_inspection'],
            'links' => [['label'=>'包裝檢驗表（全檢數／NG數）','url'=>'/EGsystem/views/QC/packaging_inspection_entry.php']],
            'desc' => '分子=ΣNG總數；分母=Σ實際全檢數量',
            'params' => []],
        'calibration_ontime' => [
            'name' => '量測儀器按時校驗率',
            'page' => '量測儀器校驗管理 views/QC/tool_calibration.php',
            'tables' => ['qc_tool_calibration','qc_tool','qc_tool_list'],
            'links' => [['label'=>'量測儀器校驗（校驗日／到期日）','url'=>'/EGsystem/views/QC/tool_calibration.php']],
            'desc' => '分母=當月應校驗量具數(已完成紀錄到期日在當月＋尚待完成的到期)；分子=其中準時完成(校驗日≤到期日+寬限)者',
            'params' => [
                ['key'=>'grace_days','label'=>'準時寬限天數(0=須到期日前完成)','type'=>'int','fe'=>1],
            ]],
        'training_completion' => [
            'name' => '人員教育訓練達成率',
            'page' => '教育訓練管理 views/ADM/training_record.php',
            'tables' => ['training_session'],
            'links' => [['label'=>'教育訓練管理（計畫月份／完成狀態）','url'=>'/EGsystem/views/ADM/training_record.php']],
            'desc' => '分母=當月計畫訓練場次(排除取消)；分子=其中已完成場次',
            'params' => [
                ['key'=>'include_cancelled','label'=>'取消場次是否計入分母(1=是)','type'=>'bool','fe'=>1],
            ]],
        'vendor_audit_ontime' => [
            'name' => '廠商稽核按時執行率',
            'page' => '供應商稽核管理 views/pm/vendor_audit.php',
            'tables' => ['vendor_audit_target','maker_list'],
            'links' => [['label'=>'供應商稽核（稽核對象／稽核日）','url'=>'/EGsystem/views/pm/vendor_audit.php']],
            'desc' => '半年批次(6=上半年/12=下半年)：分母=該期稽核對象數(排除停用廠商)；分子=其中已完成稽核(有稽核日)者',
            'params' => []],
    ];
}

/* ============================================================
 * 頻率 → 適用月份
 * ============================================================ */
function kpi_as_months(string $freq): array {
    switch ($freq) {
        case 'quarterly': return [3, 6, 9, 12];
        case 'halfyear':  return [6, 12];
        case 'yearly':    return [12];
        default:          return [1,2,3,4,5,6,7,8,9,10,11,12];
    }
}
/**
 * 手動填寫指標的「有效可填月份」：自動指標維持只有bucket月(該期間結束月)有資料；
 * 手動指標(問卷/人工統計等)整個期間內任何一個月份都可以是實際填寫的月份(使用者自行選擇填在哪個月)，
 * 故開放1~12月都是有效月份，實際「只能擇一月份填寫」的限制另由 kpi_as_period_group() 的期間互斥檢查把關。
 */
function kpi_as_valid_months(array $iy): array {
    if (($iy['source_mode'] ?? '') === 'manual') return [1,2,3,4,5,6,7,8,9,10,11,12];
    return kpi_as_months($iy['freq']);
}
/**
 * 月份 $m 所屬的「期間」內所有月份(quarterly=同季3個月／halfyear=同半年6個月／yearly=全年12個月／monthly=僅自己)。
 * 手動填寫一個期間只能擇一月份填寫資料，此函式用來找出「同期間的其他月份」做互斥檢查（見 case 'fill' 的期間互斥擋）。
 */
function kpi_as_period_group(string $freq, int $m): array {
    switch ($freq) {
        case 'quarterly': $s = intdiv($m - 1, 3) * 3 + 1; return range($s, $s + 2);
        case 'halfyear':  return $m <= 6 ? range(1, 6) : range(7, 12);
        case 'yearly':    return range(1, 12);
        default:          return [$m];
    }
}

/* ============================================================
 * 工作日輔助（evenement 行事曆）
 * ============================================================ */
/** 兩日期間工作日數(含起訖日)；同日=1 */
function kpi_as_workdays_inclusive(PDO $db, string $from, string $to): int {
    $f = strtotime(substr($from, 0, 10));
    $t = strtotime(substr($to, 0, 10));
    if ($f === false || $t === false || $t < $f) return 0;
    $sets = car_holiday_sets($db);
    $count = 0; $cur = $f; $guard = 0;
    while ($cur <= $t && $guard++ < 4000) {
        $key = date('Y-m-d', $cur);
        $dow = (int)date('w', $cur);
        $isWeekend = ($dow === 0 || $dow === 6);
        if (isset($sets['makeups'][$key]) || (!$isWeekend && !isset($sets['holidays'][$key]))) $count++;
        $cur = strtotime('+1 day', $cur);
    }
    return max($count, 1);
}
/** 起日+N個工作日 → 應交日(Y-m-d)。N<=0 回起日 */
function kpi_as_add_workdays(PDO $db, string $from, int $days): string {
    $cur = strtotime(substr($from, 0, 10));
    if ($cur === false) return substr($from, 0, 10);
    if ($days <= 0) return date('Y-m-d', $cur);
    $sets = car_holiday_sets($db);
    $count = 0; $guard = 0;
    while ($count < $days && $guard++ < 4000) {
        $cur = strtotime('+1 day', $cur);
        $key = date('Y-m-d', $cur);
        $dow = (int)date('w', $cur);
        $isWeekend = ($dow === 0 || $dow === 6);
        if (isset($sets['makeups'][$key]) || (!$isWeekend && !isset($sets['holidays'][$key]))) $count++;
    }
    return date('Y-m-d', $cur);
}

/* ============================================================
 * 參數處理
 * ============================================================ */
function kpi_as_params(?string $json): array {
    $p = json_decode((string)$json, true);
    return is_array($p) ? $p : [];
}
function kpi_as_pv(array $params, string $key, $default = null) {
    if (!isset($params[$key])) return $default;
    $v = $params[$key];
    if (is_array($v) && array_key_exists('v', $v)) return $v['v'];
    return $v;
}
/** 逗號/換行分隔字串 → 陣列(去空白) */
function kpi_as_list($v): array {
    if (is_array($v)) return array_values(array_filter(array_map('trim', array_map('strval', $v)), 'strlen'));
    $parts = preg_split('/[,，\n]+/u', (string)$v);
    return array_values(array_filter(array_map('trim', $parts), 'strlen'));
}

require_once __DIR__ . '/date_fmt_lib.php';   // 顯示用日期一律 YYYY.MM.DD（ai-rules/20）

/* ============================================================
 * 排除維度（使用者要求 2026-09-17：排除要能指定「特定客戶／製程／廠商／料號」）
 * --------------------------------------------------------------
 * 與 kpi_as_adjust（逐筆排除某一個月的某一列）互補：
 *   kpi_as_adjust     ＝這一格的這一筆不算（一次性）
 *   kpi_as_excl_rule  ＝這個指標整年度，只要是這個客戶／製程／廠商／料號就都不算（常設規則）
 * 兩者都**只影響 KPI 計算，不修改任何一筆真實資料**。
 * 畫面上要提供哪些維度，一律看「明細列身上真的有哪些維度值」（使用者原話：
 * 看資料內有哪些資料就提供那些設定），所以每一列都要帶 dims；
 * 這裡的 kpi_as_calc_dims() 只是後端驗證用的白名單，避免前端亂送維度代號。
 * ============================================================ */

/**
 * 名稱 → 代號 對照（客戶、廠商）。
 * 明細列上顯示與比對用的一律是「名稱」（來源表存的就是名稱），
 * 但使用者手邊常常只有代號（客戶 C2005、廠商編號），所以候選清單要一併帶上代號可供搜尋。
 * 靜態快取：同一個請求裡不論幾個指標、幾個月份都只查一次。
 */
function kpi_as_client_id_map(PDO $db): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        foreach ($db->query("SELECT customer_id, customer FROM customer_list") as $r) {
            $n = trim((string)$r['customer']);
            if ($n !== '' && !isset($m[$n])) $m[$n] = (string)$r['customer_id'];
        }
    } catch (Throwable $e) {}
    return $m;
}
function kpi_as_maker_id_map(PDO $db): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        foreach ($db->query("SELECT maker_id_no, maker_id FROM maker_list") as $r) {
            $n = trim((string)$r['maker_id']);
            if ($n !== '' && !isset($m[$n])) $m[$n] = (string)$r['maker_id_no'];
        }
    } catch (Throwable $e) {}
    return $m;
}

/**
 * 排除規則的候選查詢：直接查「主檔」，不是只查這個月的明細。
 * --------------------------------------------------------------
 * 使用者要求 2026-09-18：輸入一部分客戶代號要列出清單讓人挑是哪一家。
 * 為什麼一定要查主檔：排除規則比對的是**名稱**（來源表 bom／is_list／order_track 存的就是名稱），
 * 使用者手邊卻常常只有代號；如果只拿「這個月明細裡出現過的值」當候選，
 * 這個月沒出貨的客戶就完全挑不到，而直接把打進去的代號存成規則值**永遠不會命中**
 * （規則存 C2005、資料是「和大」），等於設了一條沒有作用的規則。
 * 所以這裡一律回傳 ['v'=>正式名稱, 'id'=>代號]，存進規則的永遠是 v。
 *
 * 表名／欄名一律取自這份程式碼，不吃前端輸入（維度代號另由 kpi_as_calc_dims() 白名單把關）。
 */
function kpi_as_dim_lookup(PDO $db, string $dim, string $q, int $limit = 50): array {
    $q = trim($q);
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $map = [
        // dim => [表, 名稱欄(存進規則的值), 代號欄(可為 null)]
        'client'   => ['customer_list', 'customer',    'customer_id'],
        'maker'    => ['maker_list',    'maker_id',    'maker_id_no'],
        'proc'     => ['process_no',    'ProcessName', 'ProcessNo'],
        'machine'  => ['machine_list',  'machine',     'machine_id'],
        'part'     => ['d_setting',     'D_Setting_Id', null],
        'designer' => ['user',          'user_cname',  'id'],
    ];
    if (!isset($map[$dim])) return [];
    list($tbl, $nameCol, $idCol) = $map[$dim];
    $sql = "SELECT `$nameCol` AS v, " . ($idCol ? "MIN(`$idCol`)" : "''") . " AS id FROM `$tbl` WHERE ";
    $bind = [];
    if ($q === '') {
        $sql .= "`$nameCol` IS NOT NULL AND `$nameCol`<>''";
    } else {
        $sql .= "(`$nameCol` LIKE ?" . ($idCol ? " OR `$idCol` LIKE ?" : '') . ")";
        $bind[] = $like;
        if ($idCol) $bind[] = $like;
    }
    // 離職者不該出現在「設計者」候選；停用廠商仍要列（舊資料排除得用得到）
    if ($dim === 'designer') $sql .= " AND state=1";
    $sql .= " GROUP BY `$nameCol` ORDER BY `$nameCol` LIMIT " . (int)$limit;
    $out = [];
    try {
        $st = $db->prepare($sql);
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = trim((string)$r['v']);
            if ($v === '') continue;
            $out[] = ['v' => $v, 'id' => trim((string)$r['id'])];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 每個計算模組的「來源查詢」：從哪張表、用哪個日期欄歸屬年度、每個維度的值與代號取自哪個欄位。
 * 用途：排除規則的候選清單**預設只列這個年度真的有的值**（使用者要求 2026-09-18），
 * 這個年度沒有的要打關鍵字才從主檔搜出來。
 * 這裡的欄位表達式與 kpi_as_rules_sql() 的 colmap 刻意一致，兩邊對不起來的話
 * 會變成「清單挑得到、規則卻比不中」。
 * 回傳 ['from'=>FROM/JOIN, 'date'=>歸屬年度用的日期欄, 'cols'=>[dim => [值欄, 代號欄或'']]]
 */
function kpi_as_calc_source(?string $calc): array {
    $bomFrom = "bom_ing bi
                LEFT JOIN bom b ON b.bom=bi.bom
                LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                LEFT JOIN maker_list mk ON mk.maker_id_no=bi.maker_id_no";
    $bomCols = ['client'=>['b.Client_Name', ''], 'part'=>['b.d_id', ''],
                'proc'=>["COALESCE(NULLIF(pn.ProcessName,''), bi.process_no)", 'bi.process_no'],
                'maker'=>["COALESCE(mk.maker_id, bi.maker_id)", 'bi.maker_id_no']];
    $rptFrom = "pm_process_daily_report r
                LEFT JOIN process_no pn ON pn.ProcessNo=r.process_no
                LEFT JOIN machine_list ml ON ml.machine_id=r.machine_id
                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid
                LEFT JOIN bom b ON b.bom=bi.bom";
    $rptCols = ['client'=>['b.Client_Name', ''], 'part'=>['b.d_id', ''],
                'proc'=>["COALESCE(NULLIF(pn.ProcessName,''), r.process_no)", 'r.process_no'],
                'machine'=>['ml.machine', 'ml.machine_id']];
    switch ((string)$calc) {
        case 'vendor_ontime':
            return ['from'=>$bomFrom, 'date'=>'bi.outsource_date', 'cols'=>$bomCols];
        case 'incoming_ng_rate':
            return ['from'=>$bomFrom, 'date'=>'bi.QC_check_date', 'cols'=>$bomCols];
        case 'capacity_rate':
        case 'process_ng_rate':
            return ['from'=>$rptFrom, 'date'=>'r.report_date', 'cols'=>$rptCols];
        case 'order_ontime':
            return ['from'=>"order_list ol LEFT JOIN customer_list cl ON cl.customer_id=ol.Client_name",
                    'date'=>'ol.Delivery_date',
                    'cols'=>['client'=>["COALESCE(cl.customer, ol.Client_name)", 'ol.Client_name'],
                             'part'=>['ol.d_id', '']]];
        case 'order_target_amount':
            return ['from'=>'order_track ot', 'date'=>'ot.Delivery_date',
                    'cols'=>['client'=>['ot.Client_name', 'ot.Client_name_ID'], 'part'=>['ot.d_id', '']]];
        case 'shipping_target_amount':
            return ['from'=>'is_list il', 'date'=>'il.Order_date',
                    'cols'=>['client'=>['il.Client_name', ''], 'part'=>['il.Product_id', '']]];
        case 'quote_to_order':
            return ['from'=>'quotation_list q', 'date'=>'q.quote_date',
                    'cols'=>['client'=>['q.client_name', 'q.client_id']]];
        case 'drawing_ontime':
            return ['from'=>"order_track ot LEFT JOIN user us ON us.id=ot.ate", 'date'=>'ot.ateGet',
                    'cols'=>['client'=>['ot.Client_name', ''], 'part'=>['ot.d_id', ''],
                             'designer'=>["COALESCE(us.user_cname, ot.ate)", 'ot.ate']]];
        case 'training_completion':
            return ['from'=>'training_session ts', 'date'=>'', 'year_col'=>'ts.year',
                    'cols'=>['unit'=>['ts.org_unit', '']]];
    }
    return [];
}

/** 這個指標這個年度的來源資料裡，某個維度實際出現過哪些值（候選清單預設就列這些） */
function kpi_as_dim_year_values(PDO $db, ?string $calc, string $dim, int $year, int $limit = 300): array {
    $src = kpi_as_calc_source($calc);
    if (!$src || !isset($src['cols'][$dim])) return [];
    list($vExpr, $iExpr) = $src['cols'][$dim];
    $bind = [];
    if (!empty($src['year_col'])) {
        $where = $src['year_col'] . '=?';
        $bind[] = $year;
    } else {
        $where = $src['date'] . '>=? AND ' . $src['date'] . '<?';
        $bind[] = sprintf('%04d-01-01', $year);
        $bind[] = sprintf('%04d-01-01', $year + 1);
    }
    $sql = "SELECT $vExpr AS v, " . ($iExpr !== '' ? "MIN($iExpr)" : "''") . " AS id
            FROM " . $src['from'] . "
            WHERE $where AND $vExpr IS NOT NULL AND $vExpr<>''
            GROUP BY v ORDER BY v LIMIT " . (int)$limit;
    $out = [];
    try {
        $st = $db->prepare($sql);
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = trim((string)$r['v']);
            if ($v === '') continue;
            $out[] = ['v'=>$v, 'id'=>trim((string)$r['id'])];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 客戶下拉選項（設定頁的「排除客戶」用，綁客戶ID避免打錯字） */
function kpi_as_client_options(PDO $db): array {
    $out = [];
    try {
        foreach ($db->query("SELECT customer_id, customer FROM customer_list
                             WHERE customer IS NOT NULL AND customer<>'' ORDER BY customer") as $r) {
            $out[] = ['id'=>(string)$r['customer_id'], 'name'=>(string)$r['customer']];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 這個年度所有指標的排除規則（設定頁一覽用） → [indicator_id => [列...]] */
function kpi_as_excl_rules_all(PDO $db, int $year): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT indicator_id, rule_id, dim, val, scope, year, created_by_name, created_at
                            FROM kpi_as_excl_rule WHERE year=? OR scope='all'
                            ORDER BY indicator_id, scope DESC, dim, val");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['indicator_id']][] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** 維度代號 → 顯示名稱 */
function kpi_as_dim_labels(): array {
    return ['client'=>'客戶', 'part'=>'料號', 'proc'=>'製程', 'maker'=>'廠商',
            'machine'=>'機台', 'designer'=>'設計者', 'unit'=>'受訓單位'];
}

/** 這個計算模組的明細列會帶哪些維度（後端驗證白名單） */
function kpi_as_calc_dims(?string $calc): array {
    switch ((string)$calc) {
        case 'vendor_ontime':
        case 'incoming_ng_rate':   return ['client','part','proc','maker'];
        case 'order_ontime':
        case 'shipping_target_amount':
        case 'order_target_amount': return ['client','part'];
        case 'drawing_ontime':     return ['client','part','designer'];
        case 'quote_to_order':     return ['client'];
        case 'capacity_rate':
        case 'process_ng_rate':    return ['client','part','proc','machine'];
        case 'training_completion': return ['unit'];
    }
    return [];
}

/**
 * 指標「設定」裡本來就有的排除（params_json，例：準時出貨率的 exclude_clients）。
 * 這些排除是寫在 SQL 條件裡的，所以那幾筆資料**根本不會出現在明細上**——
 * 畫面上看不到就會有人再去建一條一模一樣的排除規則（使用者回報 2026-09-18），
 * 所以一律回傳給前端當唯讀標示。要改這些請到 KPI 設定頁改參數，不在明細這裡改。
 * 回傳 [['dim'=>'client','val'=>'寶嘉誠'], ...]
 */
function kpi_as_param_excl(?string $calc, array $params, ?PDO $db = null): array {
    $out = [];
    $res = function ($vals) use ($db) {
        return $db ? kpi_as_resolve_clients($db, $vals) : array_map('strval', $vals);
    };
    switch ((string)$calc) {
        case 'order_ontime':
            foreach ($res(kpi_as_list(kpi_as_pv($params, 'exclude_clients', ['寶嘉誠','泳建']))) as $v)
                $out[] = ['dim'=>'client', 'val'=>(string)$v];
            break;
        case 'drawing_ontime':
            foreach ($res(kpi_as_list(kpi_as_pv($params, 'exclude_clients', []))) as $v)
                $out[] = ['dim'=>'client', 'val'=>(string)$v];
            break;
    }
    return $out;
}

/** 這個指標這一年度的排除規則 → ['client'=>['甲','乙'], 'proc'=>[...]] */
function kpi_as_excl_rules(PDO $db, int $iid, int $year): array {
    $out = [];
    try {
        // 這一年度自己的規則 ＋ 標成「所有年度」的規則（year=0）
        $st = $db->prepare("SELECT dim, val FROM kpi_as_excl_rule
                            WHERE indicator_id=? AND (year=? OR scope='all')");
        $st->execute([$iid, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (string)$r['dim']; $v = (string)$r['val'];
            if (!isset($out[$d]) || !in_array($v, $out[$d], true)) $out[$d][] = $v;
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 排除規則明細（畫面用，含適用範圍與建立者） */
function kpi_as_excl_rule_rows(PDO $db, int $iid, int $year): array {
    try {
        $st = $db->prepare("SELECT rule_id,dim,val,scope,year,reason,created_by_name,created_at
                            FROM kpi_as_excl_rule WHERE indicator_id=? AND (year=? OR scope='all')
                            ORDER BY scope DESC, dim, val");
        $st->execute([$iid, $year]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** 這一列有沒有被規則排除掉？回傳命中的維度代號（沒命中回 ''） */
function kpi_as_dims_hit(array $dims, array $rules): string {
    foreach ($rules as $dim => $vals) {
        if (!isset($dims[$dim])) continue;
        $v = trim((string)$dims[$dim]);
        if ($v === '') continue;
        foreach ($vals as $x) { if ((string)$x === $v) return (string)$dim; }
    }
    return '';
}

/**
 * 規則 → SQL 片段。$colmap = ['client'=>'ot.Client_name', ...]
 * 回傳 [' AND ...', [bind...]]；沒有規則就回 ['', []]。
 * NULL／空字串的列一律保留（規則排的是「這個值」，不是「沒有值」）。
 */
function kpi_as_rules_sql(array $rules, array $colmap): array {
    $sql = ''; $bind = [];
    foreach ($colmap as $dim => $col) {
        $vals = $rules[$dim] ?? [];
        if (!$vals) continue;
        $sql .= " AND ($col IS NULL OR $col NOT IN (" . implode(',', array_fill(0, count($vals), '?')) . '))';
        foreach ($vals as $v) $bind[] = (string)$v;
    }
    return [$sql, $bind];
}

/* ============================================================
 * 準時出貨率的「未交」判定（使用者拍板 2026-09-18：逐年度可由管理員選 B 或 C，預設 C）
 * --------------------------------------------------------------
 * 為什麼要分三種：這個指標原本只吃 ERP 匯入的 `order_list`（Qty=Open_Qty 視為未交），
 * 而 `order_list` **只有 ERP 匯入那支程式會寫**（views/pm/_upload_For_List.php），
 * 所以使用者在「快速出貨」把出貨單綁到訂單，這個指標一點反應都沒有（使用者實測回報）。
 *   bind     (C，預設)：完全用我們自己的資料——order_track 的訂單 ＋ 綁到它的出貨單，
 *                        綁一張就少一張未交，當下重算就看得到。
 *   erp_ship (B)      ：沿用 ERP 未交清單，但查得到同客戶同料號的出貨單就不算未交
 *                        （ERP 重複轉出／沒帶訂單編號還沒修好之前的過渡）。
 *   erp               ：舊制，完全以 ERP 未交清單為準。
 * 三種都共用同一組排除規則與排除客戶設定。
 * ============================================================ */
/**
 * 這些「ERP 說未交」的列，有沒有查得到同客戶＋同料號、沒有綁訂單的出貨單？
 * 回傳 ["客戶\x00料號" => ['no'=>出貨單號,'d'=>出貨日,'qty'=>數量]]
 * compute（erp_ship 模式）與明細的「疑似已出貨」欄共用同一份，兩邊各寫一次一定會走鐘。
 */
function kpi_as_oo_ship_hint(PDO $db, array $rows, int $year, int $month): array {
    $ms = sprintf('%04d-%02d-01', $year, $month);
    $me = date('Y-m-t', strtotime($ms));
    $cks = [];
    foreach ($rows as $r) {
        $c = trim((string)($r['cname'] ?? $r['Client_name'] ?? ''));
        $p = trim((string)$r['d_id']);
        if ($c !== '' && $p !== '') $cks[$c . "\x00" . $p] = 1;
    }
    if (!$cks) return [];
    $out = [];
    try {
        $q = $db->prepare("SELECT Client_name, Product_id, IS_number, DATE(Order_date) d, Qty
                           FROM is_list
                           WHERE (Order_id IS NULL OR Order_id=0) AND Order_date BETWEEN ? AND ?
                             -- 同一張出貨單號有兩個以上不同出貨日的＝ERP 重複轉出，不拿它當出貨證據
                             AND IS_number NOT IN (SELECT IS_number FROM (
                                   SELECT IS_number FROM is_list
                                    GROUP BY IS_number HAVING COUNT(DISTINCT DATE(Order_date))>1) dup)
                           ORDER BY Order_date");
        $q->execute([date('Y-m-d', strtotime($ms . ' -60 days')), date('Y-m-d', strtotime($me . ' +60 days'))]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $k = trim((string)$x['Client_name']) . "\x00" . trim((string)$x['Product_id']);
            // 依出貨日排序，第一筆就是最早的一次出貨（重複的完全相同資料不影響最早日期）
            if (!isset($cks[$k]) || isset($out[$k])) continue;
            $out[$k] = ['no'=>(string)$x['IS_number'], 'd'=>(string)$x['d'], 'qty'=>(string)(0 + $x['Qty'])];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * ERP 未交清單（order_list）最後一次匯入的日期。
 * 使用者指出 2026-09-18：「已經沒有匯入所謂的 ERP 未交訂單」——實測最後一批是 2026-03-12，
 * 之後半年沒有再匯過。這張表是**快照**不是全量（每一列都是 Qty=Open_Qty），
 * 所以匯入日之後才成立的訂單它根本沒有，拿它判定會得到「全部都已交」這種假的高分
 * （實測 2026-05~09 因此被算成 98~99%）。
 * 凡是吃這張表的模式，都要先用這支確認資料還新不新，不新就不要生出數字。
 */
function kpi_as_order_list_asof(PDO $db): ?string {
    static $v = false;
    if ($v !== false) return $v;
    $v = null;
    try {
        $d = $db->query("SELECT MAX(Created_At) FROM order_list")->fetchColumn();
        if ($d) $v = substr((string)$d, 0, 10);
    } catch (Throwable $e) {}
    return $v;
}

/** 這個月份還能不能用 ERP 未交清單判定？（匯入日要涵蓋到該月月底之後） */
function kpi_as_order_list_covers(PDO $db, int $year, int $month): bool {
    $asof = kpi_as_order_list_asof($db);
    if (!$asof) return false;
    return $asof >= date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
}

function kpi_as_undone_mode(array $params): string {
    // 預設 D：B 吃的 ERP 未交清單已經半年沒匯入、C 需要先做出貨綁定，
    // 目前只有 D 的來源（出貨單 is_list）是新的（2026-09-17 仍在匯入）
    $m = (string)kpi_as_pv($params, 'undone_mode', 'ship');
    return in_array($m, ['ship', 'bind', 'erp_ship', 'erp'], true) ? $m : 'ship';
}

/**
 * 排除客戶的值一律解析成「客戶簡稱」。
 * 使用者要求 2026-09-18：設定頁的排除客戶要能綁客戶ID，避免打錯字造成計算有誤。
 * 這裡**兩種都收**——填客戶ID（C2005）就回查 customer_list 換成簡稱（和大），
 * 填簡稱就原樣保留（既有設定不受影響）。比對一律用簡稱，因為
 * order_track／bom／is_list 這些來源表存的就是簡稱。
 */
function kpi_as_resolve_clients(PDO $db, array $list): array {
    $list = array_values(array_filter(array_map('trim', array_map('strval', $list)), 'strlen'));
    if (!$list) return [];
    $byId = [];
    try {
        $in = implode(',', array_fill(0, count($list), '?'));
        $st = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE customer_id IN ($in)");
        $st->execute($list);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = trim((string)$r['customer']);
            if ($n !== '') $byId[(string)$r['customer_id']] = $n;
        }
    } catch (Throwable $e) {}
    $out = [];
    foreach ($list as $v) $out[] = $byId[$v] ?? $v;
    return array_values(array_unique($out));
}

/** 準時出貨率的排除客戶（參數設定 ∪ 排除規則的 client 維度），值一律是客戶簡稱 */
function kpi_as_oo_excl_clients(PDO $db, array $params, array $rules): array {
    $ex = kpi_as_resolve_clients($db, kpi_as_list(kpi_as_pv($params, 'exclude_clients', ['寶嘉誠','泳建'])));
    return array_values(array_unique(array_merge($ex, array_map('strval', $rules['client'] ?? []))));
}

/**
 * bind 模式的來源列：order_track 的訂單 ＋ 綁到它的最早出貨日。
 * 綁定有兩個來源，兩個都要看（見 ship_order_bind_lib）：
 *   ① shipment_order_map（拆分綁定）② is_list.Order_id（舊式直接綁定）
 */
/**
 * 準時出貨率的寬限工作天數（使用者要求 2026-09-18）：
 * 交期之後再給幾個**工作天**，這段期間內交貨仍算準時。0＝不寬限。
 * 用工作天而不是日曆天，是因為交期落在連假前時，日曆天會把假日也算進寬限而失真。
 */
function kpi_as_oo_grace_days(array $params): int {
    return max(0, min(60, (int)kpi_as_pv($params, 'grace_days', 0)));
}

function kpi_as_order_bind_rows(PDO $db, int $year, int $month, array $params, array $rules,
                                string $mode = 'bind'): array {
    $ym = sprintf('%04d-%02d', $year, $month);
    $grace = kpi_as_oo_grace_days($params);
    $dlCache = [];   // 同一個交期只算一次工作天（一個月裡交期重複度很高）
    $exCli = kpi_as_oo_excl_clients($db, $params, $rules);
    $bind = [$ym];
    $sql = "SELECT ot.Order_id, ot.Order_oo, ot.Client_name, ot.Client_name_ID, ot.d_id, ot.d_id_ID, ot.Qty,
                   ot.Delivery_date, ot.Order_status,
                   d1.d AS ship1, d2.d AS ship2
            FROM order_track ot
            LEFT JOIN (SELECT Order_id, MIN(DATE(Order_date)) d FROM is_list
                        WHERE Order_id IS NOT NULL AND Order_id>0 GROUP BY Order_id) d1
                   ON d1.Order_id=ot.Order_id
            LEFT JOIN (SELECT som.Order_id, MIN(DATE(il.Order_date)) d
                         FROM shipment_order_map som JOIN is_list il ON il.IS_id=som.IS_id
                        GROUP BY som.Order_id) d2
                   ON d2.Order_id=ot.Order_id
            WHERE UPPER(ot.d_id)<>'ZZZ' AND LOWER(ot.d_id) NOT REGEXP '-(jg|jh|hg)$'
              AND (ot.Order_status IS NULL OR ot.Order_status<>9)
              AND DATE_FORMAT(ot.Delivery_date,'%Y-%m')=?";
    if ($exCli) {
        $sql .= " AND ot.Client_name NOT IN (" . implode(',', array_fill(0, count($exCli), '?')) . ")";
        $bind = array_merge($bind, $exCli);
    }
    $rPart = array_map('strval', $rules['part'] ?? []);
    if ($rPart) {
        $sql .= " AND ot.d_id NOT IN (" . implode(',', array_fill(0, count($rPart), '?')) . ")";
        $bind = array_merge($bind, $rPart);
    }
    $sql .= " ORDER BY ot.Delivery_date, ot.Order_id";
    $rows = [];
    try {
        $st = $db->prepare($sql);
        $st->execute($bind);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ds = array_values(array_filter([(string)$r['ship1'], (string)$r['ship2']], 'strlen'));
            $r['ship_date'] = $ds ? min($ds) : '';
            $r['ship_src']  = $ds ? 'bind' : '';
            $r['due']       = substr((string)$r['Delivery_date'], 0, 10);
            // 判定用的截止日＝交期＋寬限工作天（寬限 0 時就是交期本身）
            if (!isset($dlCache[$r['due']]))
                $dlCache[$r['due']] = $grace > 0 ? kpi_as_add_workdays($db, $r['due'], $grace) : $r['due'];
            $r['deadline']  = $dlCache[$r['due']];
            $r['grace']     = $grace;
            $r['ontime']    = ($r['ship_date'] !== '' && $r['ship_date'] <= $r['deadline']);
            $rows[] = $r;
        }
    } catch (Throwable $e) {}

    /* D 模式（使用者 2026-09-18 指示：走「料號＋客戶ID 對照」這條路）
       --------------------------------------------------------------
       不必做出貨綁定、也不必等 ERP 未交清單——直接用**料號主檔 id** 把訂單對到出貨單：
         order_track.d_id_ID  ↔  is_list.d_setting_id
       為什麼用這個鍵而不是客戶名稱＋料號文字：實測 2026 年
         order_track.d_id_ID  有值 3031/3065（98.9%）
         is_list.d_setting_id 有值 5049/5049（100%）
         is_list.Client_id    只有 292/5049（5.8%，幾乎全空）
       而且**料號主檔 id 本身就綁定了客戶**（同一個料號文字在 d_setting 常有好幾筆、分屬不同客戶），
       對到主檔 id 就等於同時對到客戶，不必再比客戶名稱——舊訂單的客戶名稱寫法跟出貨單常常不一樣。
       準時＝最早出貨日 ≤ 交期；查不到任何出貨紀錄＝未交。 */
    if ($mode === 'ship' && $rows) {
        $ms = sprintf('%04d-%02d-01', $year, $month);   // 這支函式本來沒有 $ms，漏了會讓回溯窗變成「從今天往前 120 天」
        $ids = [];
        foreach ($rows as $r) if ((int)$r['d_id_ID'] > 0) $ids[(int)$r['d_id_ID']] = 1;
        $shipBy = [];
        if ($ids) {
            $from = date('Y-m-d', strtotime($ms . ' -120 days'));   // 交期當月起往前 120 天
            foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                try {
                    $q = $db->prepare("SELECT d_setting_id, IS_number, DATE(Order_date) d, Qty
                                       FROM is_list
                                       WHERE d_setting_id IN ($in) AND Order_date >= ?
                                       ORDER BY Order_date");
                    $q->execute(array_merge($chunk, [$from]));
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
                        $k = (int)$x['d_setting_id'];
                        if (isset($shipBy[$k])) continue;      // 已排序，第一筆就是最早的
                        $shipBy[$k] = ['no'=>(string)$x['IS_number'], 'd'=>(string)$x['d'],
                                       'qty'=>(string)(0 + $x['Qty'])];
                    }
                } catch (Throwable $e) {}
            }
        }
        foreach ($rows as &$r) {
            if ($r['ship_date'] !== '') { $r['ontime'] = ($r['ship_date'] <= $r['deadline']); continue; }
            $h = $shipBy[(int)$r['d_id_ID']] ?? null;
            if (!$h) { $r['ontime'] = false; continue; }
            $r['ship_date'] = $h['d'];
            $r['ship_src']  = 'dsetting';
            $r['hint']      = $h;
            $r['ontime']    = ($h['d'] <= $r['deadline']);
        }
        unset($r);
    }

    /* B 模式（使用者 2026-09-18 指示：ERP 還沒修好、也不要做出貨綁定，因為修好後會清掉出貨單重載）
       --------------------------------------------------------------
       關鍵事實：`order_list` 是 ERP 的「未交清單快照」——實測 2026 年該表**每一列都是 Qty=Open_Qty**，
       也就是說裡面只會有「還完全沒出貨」的訂單，已交的根本不在裡面。
       所以「有沒有出貨」唯一能問的就是它；而**它沒有出貨日期**，B 模式判不了「是不是在交期前交的」，
       只能判「到期了還完全沒出」。要判準不準時就得用 C（需要出貨綁定）。

       舊制是把兩張表的「筆數」直接相減，但 order_track 與 order_list 是各自獨立的資料
       （2026-01：訂單追蹤 260 張、ERP 未交 364 列），相減會變成負數被夾成 0%。
       改成**逐筆對回去**（同客戶簡稱＋同料號，一張未交只佔用一張訂單），
       這樣「未交數」永遠不會超過訂單數，1~3 月就不會再被夾成 0%。 */
    if ($mode === 'erp_ship' && $rows) {
        $ym = sprintf('%04d-%02d', $year, $month);
        $idx = [];
        foreach ($rows as $i => $r) {
            $k = trim((string)$r['Client_name']) . "\x00" . trim((string)$r['d_id']);
            $idx[$k][] = $i;
        }
        $open = [];
        try {
            $q = $db->prepare("SELECT COALESCE(cl.customer, ol.Client_name) cn, ol.d_id, ol.Order_oo,
                                      ol.Qty, ol.Open_Qty
                               FROM order_list ol
                               LEFT JOIN customer_list cl ON cl.customer_id=ol.Client_name
                               WHERE UPPER(ol.d_id)<>'ZZZ' AND LOWER(ol.d_id) NOT REGEXP '-(jg|jh|hg)$'
                                 AND ol.Qty=ol.Open_Qty AND ol.Order_status IS NULL
                                 AND DATE_FORMAT(ol.Delivery_date,'%Y-%m')=?");
            $q->execute([$ym]);
            $open = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        foreach ($open as $u) {
            $k = trim((string)$u['cn']) . "\x00" . trim((string)$u['d_id']);
            if (empty($idx[$k])) continue;                 // 對不回訂單追蹤（那張訂單不在這裡）
            $i = array_shift($idx[$k]);                    // 一張未交只佔用一張訂單
            $rows[$i]['erp_open'] = $u;
        }
        // ERP 說還完全沒出＝不準時；沒被標到的，ERP 認為已交（但沒有出貨日，無法再判準不準時）
        foreach ($rows as &$r) {
            $r['ontime'] = empty($r['erp_open']);
            if (!$r['ontime']) { $r['ship_date'] = ''; $r['ship_src'] = ''; }
        }
        unset($r);
        // 沒出貨的那幾筆，再查「同客戶同料號、沒綁訂單的出貨單」當提示（只是提示，不改判定）
        $need = [];
        foreach ($rows as $r) if (!empty($r['erp_open'])) $need[] = ['cname'=>$r['Client_name'], 'd_id'=>$r['d_id']];
        $hint = $need ? kpi_as_oo_ship_hint($db, $need, $year, $month) : [];
        if ($hint) {
            foreach ($rows as &$r) {
                if (empty($r['erp_open'])) continue;
                $h = $hint[trim((string)$r['Client_name']) . "\x00" . trim((string)$r['d_id'])] ?? null;
                if ($h) $r['hint'] = $h;
            }
            unset($r);
        }
    }
    return $rows;
}

/* ============================================================
 * 廠商準時交貨率：來源列組裝（compute 與「不符合標準的明細」共用同一份）
 * --------------------------------------------------------------
 * 兩邊各寫一次 SQL 遲早走鐘（出圖準時率就踩過：明細筆數與 den-num 對不起來），
 * 所以這一支是唯一實作，compute 只是把它加總。
 *
 * 【回廠日的認定】使用者要求 2026-09-17：
 *   bom_ing.return_date 沒登錄時，依序往下找「這批貨其實已經回來了」的證據——
 *     ① 下一製程的發包日（下一站都發出去了，表示這一站已經回廠）
 *     ② 本站的 QC 檢驗日（檢驗一定是回廠後才驗）
 *     ③ 該製令所屬訂單的出貨日（都出貨了當然回來了）
 *     ④ 製令結案日
 *   四個都找不到才算真的「未登錄回廠」。
 *   任何推估日期都必須 >= 發包日，否則視為不合理不採用。
 * ============================================================ */
function kpi_as_vendor_back_sources(): array {
    return ['transfer'=>'製程移轉憑單', 'next_out'=>'下一製程發包日', 'qc'=>'QC檢驗日',
            'ship'=>'出貨日', 'closed'=>'製令結案日'];
}

/**
 * 製程移轉憑單（`bom_ing_transfer_log`，ERP 匯入，見 views/pm/Transfer_Log_Analysis.php）
 * 的「單號日期」——貨從這個廠商移轉出去的那一天，就是它回廠的那一天，是最直接的證據。
 *
 * **日期一定要從單號解析，不可以用 `transfer_date`**（使用者指正 2026-09-18）：
 * `transfer_date` 會為了帳款月份被人工改過（2026 年 6,369 筆裡有 60 筆與單號日期不同，最多差 49 天），
 * 單號 `J-1150821029` ＝ J-＋民國年3碼(115)＋MM(08)＋DD(21)＋序號，是開單當下就固定的。
 *
 * 實測（拿 2026 年已經有登錄回廠日的 3,346 筆對照）：憑單日期與實際回廠日
 * 完全相同 44%、差 3 天以內 93%、中位數差 -1 天；而 2026 沒有回廠日的 5,062 筆裡有 2,261 筆（45%）查得到憑單。
 *
 * @return array "bom\x00bom_sn\x00maker_id_no" => [日期由小到大]
 */
function kpi_as_transfer_dates(PDO $db, array $boms): array {
    $boms = array_values(array_filter(array_unique(array_map('strval', $boms)), 'strlen'));
    if (!$boms) return [];
    $d = "STR_TO_DATE(CONCAT(SUBSTRING(transfer_no,3,3)+1911, SUBSTRING(transfer_no,6,4)),'%Y%m%d')";
    $out = [];
    foreach (array_chunk($boms, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $st = $db->prepare("SELECT bom, bom_sn, maker_from, $d AS d
                                FROM bom_ing_transfer_log
                                WHERE bom IN ($in)
                                  AND transfer_no REGEXP '^[A-Za-z]-[0-9]{10}$'
                                  AND maker_from IS NOT NULL AND maker_from<>''");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (empty($r['d'])) continue;
                $k = (string)$r['bom'] . "\x00" . (string)$r['bom_sn'] . "\x00" . (string)$r['maker_from'];
                $out[$k][] = substr((string)$r['d'], 0, 10);
            }
        } catch (Throwable $e) {}
    }
    foreach ($out as &$v) { sort($v); }
    unset($v);
    return $out;
}

function kpi_as_vendor_rows(PDO $db, int $year, int $month, array $params): array {
    // 【唯一實作在 vendor_kpi_lib.php】使用者要求 2026-09-18：
    // 外包廠商績效頁（views/pages/vendor_kpi.php）是這個指標的最終資料來源，
    // 兩邊的判定與設定（容忍天數、廠商／製程特殊天數、例外廠商、例外製程）必須完全一致。
    // 原本 KPI 頁自己另寫一套（期間用應交日、天數用約定工作天、排除用 kpi_as_excl_rule），
    // 2026-08 一邊算出 81.9%、一邊 70.1%，對不起來。現在一律由 vkPeriodRows() 算。
    require_once __DIR__ . '/vendor_kpi_lib.php';
    $srcName = kpi_as_vendor_back_sources();
    $res = vkPeriodRows($db, 'month', sprintf('%04d-%02d', $year, $month));
    $rows = [];
    foreach ($res['rows'] as $r) {
        $proc  = (string)($r['ProcessName'] !== null && $r['ProcessName'] !== '' ? $r['ProcessName'] : $r['process_no']);
        $maker = (string)$r['maker_name'];
        $dims  = ['client'=>(string)$r['client_name'], 'part'=>(string)$r['part_no'],
                  'proc'=>$proc, 'maker'=>$maker];
        // 回廠日來源：return＝生管登錄／transfer(_earlier)＝憑單／qc(_earlier)＝QC檢驗日
        // （_earlier＝有登錄回廠日，但這個來源的日期更早而採用它）
        $src = (string)$r['rd_src'];
        $srcKey = ($src === '' || $src === 'return') ? $src
                : (strpos($src, 'qc') === 0 ? 'qc' : 'transfer');
        $rows[] = [
            'fid'=>(string)$r['bom_ing_fid'], 'bom'=>(string)$r['bom'], 'sn'=>(int)$r['bom_sn'],
            'qty'=>(string)$r['sqty'], 'out'=>(string)$r['od'], 'due'=>(string)$r['deadline'],
            'days'=>(int)$r['tol'], 'proc'=>$proc, 'maker'=>$maker,
            'part'=>$dims['part'], 'client'=>$dims['client'], 'dims'=>$dims,
            'dim_ids'=>['client'=>'', 'part'=>'', 'proc'=>(string)$r['process_no'],
                        'maker'=>(string)$r['maker_id_no']],
            'back'=>(string)($r['rd'] ?? ''),
            'back_src'=>$srcKey,
            'back_earlier'=>(substr($src, -8) === '_earlier'),
            'status'=>(string)$r['status'],
        ];
    }
    // 客戶代號要另外補（vendor_kpi 的查詢沒有帶，這裡用名稱回查）
    $cmap = kpi_as_client_id_map($db);
    foreach ($rows as &$r) $r['dim_ids']['client'] = $cmap[trim($r['client'])] ?? '';
    unset($r);
    return $rows;
}

/* ============================================================
 * 計算模組（回傳 ['num'=>分子,'den'=>分母,'value'=>值] 或 null=無法計算）
 * ============================================================ */
/**
 * @param array $exclRows 這一格被「排除」的來源列 row_key（見 kpi_as_adjust）。
 *        注意：個別 case 內另有同名的 $excl 區域變數（參數設定的排除客戶／排除退貨性質），兩者不同。
 *                    只有 kpi_as_detail_supported() 為真的計算模組才吃得到，
 *                    其餘模組不開放排除功能（UI 也不會給按鈕），避免排了卻沒作用。
 * @param array $rules 依維度整批排除的規則（見 kpi_as_excl_rule）：
 *        ['client'=>['甲','乙'], 'proc'=>['熱處理'], 'maker'=>[...], 'part'=>[...]]。
 *        同樣只影響計算，不動真實資料；分子分母都要一起排掉，否則比率會被灌水。
 */
function kpi_as_compute(PDO $db, string $key, int $year, int $month, array $params,
                       array $exclRows = [], array $rules = []): ?array {
    $ym = sprintf('%04d-%02d', $year, $month);
    $ms = sprintf('%04d-%02d-01', $year, $month);
    $me = date('Y-m-t', strtotime($ms));

    // 自訂（資料來源目錄 no-code builder）：params_json 本身即 spec
    if ($key === '__builder__') return kpi_as_builder_compute($db, $year, $month, $params);

    switch ($key) {

        case 'complaint_rate': {
            $st = $db->prepare("SELECT COUNT(*) FROM is_list WHERE DATE_FORMAT(Order_date,'%Y-%m')=?");
            $st->execute([$ym]);
            $den = (int)$st->fetchColumn();
            $excl = array_map('intval', kpi_as_list(kpi_as_pv($params, 'exclude_return_types', [])));
            $sql = "SELECT COUNT(*) FROM ir_track WHERE DATE_FORMAT(IR_date,'%Y-%m')=?";
            $bind = [$ym];
            if ($excl) {
                $sql .= " AND (return_type_id IS NULL OR return_type_id NOT IN (" . implode(',', array_fill(0, count($excl), '?')) . "))";
                $bind = array_merge($bind, $excl);
            }
            $st = $db->prepare($sql);
            $st->execute($bind);
            $num = (int)$st->fetchColumn();
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'order_target_amount': {
            // 帳款月窗口：上月 start_day ~ 當月 (start_day-1)；同 Shipping_Analysis_new.php
            $st = $db->prepare("SELECT start_day FROM kpi_monthly_targets WHERE year=? AND month=? LIMIT 1");
            $st->execute([$year, $month]);
            $sd = (int)$st->fetchColumn();
            if ($sd < 1 || $sd > 28) $sd = 1;
            if ($sd > 1) {
                $ws = date('Y-m-d', mktime(0,0,0,$month-1,$sd,$year));
                $we = date('Y-m-d', mktime(0,0,0,$month,$sd-1,$year));
            } else { $ws = $ms; $we = $me; }
            // 排除規則（客戶／料號）與逐筆排除都直接從接單金額裡扣掉
            list($rSql, $rBind) = kpi_as_rules_sql($rules, ['client'=>'Client_name', 'part'=>'d_id']);
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            $exSql = $exIds ? (" AND Order_id NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")") : '';
            $st = $db->prepare("SELECT COALESCE(SUM(Qty*unit_price),0) FROM order_track
                                WHERE Delivery_date BETWEEN ? AND ?
                                  AND (Order_status IS NULL OR Order_status<>9)
                                  AND unit_price IS NOT NULL AND unit_price>0" . $rSql . $exSql);
            $st->execute(array_merge([$ws, $we], $rBind, $exIds));
            $amount = (float)$st->fetchColumn();
            $tmap = kpi_as_pv($params, 'monthly_targets', []);
            $target = is_array($tmap) && isset($tmap[(string)$month]) ? (float)$tmap[(string)$month]
                    : (is_array($tmap) && isset($tmap[$month]) ? (float)$tmap[$month] : 0.0);
            if ($target <= 0) {
                $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='SHIPPING_ANALYSIS' AND param_key='KPI_TARGET' LIMIT 1");
                $st->execute();
                $pv = json_decode((string)$st->fetchColumn(), true);
                if (is_numeric($pv)) $target = (float)$pv;
                elseif (is_array($pv) && isset($pv[(string)$month]) && is_numeric($pv[(string)$month])) $target = (float)$pv[(string)$month];
            }
            return ['num'=>$amount, 'den'=>$target, 'value'=>$target > 0 ? $amount / $target * 100 : null];
        }

        case 'shipping_target_amount': {
            list($rSql, $rBind) = kpi_as_rules_sql($rules, ['client'=>'Client_name', 'part'=>'Product_id']);
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            $exSql = $exIds ? (" AND IS_id NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")") : '';
            $st = $db->prepare("SELECT COALESCE(SUM(Qty*Unit_price),0) FROM is_list
                                WHERE DATE_FORMAT(Order_date,'%Y-%m')=?" . $rSql . $exSql);
            $st->execute(array_merge([$ym], $rBind, $exIds));
            $amount = (float)$st->fetchColumn();
            $tmap = kpi_as_pv($params, 'monthly_targets', []);
            $target = is_array($tmap) && isset($tmap[(string)$month]) ? (float)$tmap[(string)$month]
                    : (is_array($tmap) && isset($tmap[$month]) ? (float)$tmap[$month] : 0.0);
            return ['num'=>$amount, 'den'=>$target, 'value'=>$target > 0 ? $amount / $target * 100 : null];
        }

        case 'quote_to_order': {
            // pending_review=1 ＝「報價單快速轉移」頁尚待確認補件的匯入舊資料，還不是正式報價單；
            // 算進分母會憑空把報價轉訂單率壓下來
            $cond = "DATE_FORMAT(q.quote_date,'%Y-%m')=? AND q.pending_review=0";
            if ((int)kpi_as_pv($params, 'exclude_draft', 1) === 1) $cond .= " AND q.is_draft=0";
            list($rSql, $rBind) = kpi_as_rules_sql($rules, ['client'=>'q.client_name']);
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            $exSql = $exIds ? (" AND q.quote_id NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")") : '';
            $cond .= $rSql . $exSql;
            $bind = array_merge([$ym], $rBind, $exIds);
            $st = $db->prepare("SELECT COUNT(*) FROM quotation_list q WHERE $cond");
            $st->execute($bind);
            $den = (int)$st->fetchColumn();
            $st = $db->prepare("SELECT COUNT(*) FROM quotation_list q WHERE $cond
                                AND EXISTS (SELECT 1 FROM order_track ot WHERE ot.quote_no=q.quote_no)");
            $st->execute($bind);
            $num = (int)$st->fetchColumn();
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'vendor_ontime': {
            // 來源列一律走共用組裝（含「未登錄回廠日時依序推估回廠日」與維度排除規則）
            $exSet = $exclRows ? array_flip(array_map('strval', $exclRows)) : [];
            $num = 0; $den = 0;
            foreach (kpi_as_vendor_rows($db, $year, $month, $params) as $r) {
                if ($exSet && isset($exSet[$r['fid']])) continue;   // 已排除的不進分子也不進分母
                if (kpi_as_dims_hit($r['dims'], $rules) !== '') continue;   // 命中排除規則＝分子分母都不算
                $den++;
                if ($r['status'] === 'ontime') $num++;              // 準時與否由 vendor_kpi 唯一實作判定
            }
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'order_ontime': {
            $mode = kpi_as_undone_mode($params);

            // B／舊制吃的是 ERP 未交清單，它已經半年沒有匯入了（見 kpi_as_order_list_asof）。
            // 匯入日之後的月份它根本沒有那些訂單，硬算會得到「幾乎都已交」的假高分——
            // 寧可回「無資料」也不要給一個看起來很漂亮但是錯的數字。
            if (($mode === 'erp_ship' || $mode === 'erp') && !kpi_as_order_list_covers($db, $year, $month)) {
                return null;
            }
            if ($mode === 'bind' || $mode === 'erp_ship' || $mode === 'ship') {
                $exIds = array_values(array_filter(array_map('intval', $exclRows)));
                $exSet = $exIds ? array_flip($exIds) : [];
                $num = 0; $den = 0;
                foreach (kpi_as_order_bind_rows($db, $year, $month, $params, $rules, $mode) as $r) {
                    if ($exSet && isset($exSet[(int)$r['Order_id']])) continue;
                    $den++;
                    if ($r['ontime']) $num++;
                }
                return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
            }

            $exCli = kpi_as_oo_excl_clients($db, $params, $rules);
            $rPart = array_map('strval', $rules['part'] ?? []);
            $notIn = $exCli ? (" AND ot.Client_name NOT IN (" . implode(',', array_fill(0, count($exCli), '?')) . ")") : '';
            $notPart = $rPart ? (" AND ot.d_id NOT IN (" . implode(',', array_fill(0, count($rPart), '?')) . ")") : '';
            // 逐筆排除只套在 order_list（未交清單）那一邊，**不可以**套到 order_track：
            // 兩張表是各自獨立的資料（order_track＝自建訂單追蹤、客戶存中文名，Order_id 1~9643；
            // order_list＝ERP 未交訂單、客戶存代號如 CJ002，Order_id 34232~67249），
            // 2026-08 兩邊用 Order_id／Order_oo+料號 都一筆都對不起來。
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            $exSql = $exIds ? (" AND ot.Order_id NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")") : '';
            $base = " UPPER(ot.d_id)<>'ZZZ' AND LOWER(ot.d_id) NOT REGEXP '-(jg|jh|hg)$'"
                  . " AND DATE_FORMAT(ot.Delivery_date,'%Y-%m')=?" . $notPart;
            $st = $db->prepare("SELECT COUNT(*) FROM order_track ot WHERE" . $base . $notIn);
            $st->execute(array_merge([$ym], $rPart, $exCli));
            $den = (int)$st->fetchColumn();
            // order_list 的客戶欄是代號，要 JOIN customer_list 換成中文簡稱才對得上排除規則
            $lCli = $exCli ? (" AND COALESCE(cl.customer, ot.Client_name) NOT IN ("
                              . implode(',', array_fill(0, count($exCli), '?')) . ")") : '';
            $st = $db->prepare("SELECT ot.Order_id, COALESCE(cl.customer, ot.Client_name) AS cname, ot.d_id,
                                       ot.Delivery_date
                                FROM order_list ot
                                LEFT JOIN customer_list cl ON cl.customer_id=ot.Client_name
                                WHERE" . $base . $lCli . $exSql
                              . " AND ot.Qty=ot.Open_Qty AND ot.Order_status IS NULL");
            $st->execute(array_merge([$ym], $rPart, $exCli, $exIds));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $undone = count($rows);
            // B：ERP 說未交，但查得到同客戶同料號的出貨單就不算未交
            if ($mode === 'erp_ship' && $rows) {
                $hit = kpi_as_oo_ship_hint($db, $rows, $year, $month);
                foreach ($rows as $r) {
                    $k = trim((string)$r['cname']) . "\x00" . trim((string)$r['d_id']);
                    if (isset($hit[$k])) $undone--;
                }
            }
            $num = max(0, $den - $undone);
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'stock_accuracy': {
            $qs = date('Y-m-d', mktime(0,0,0,$month-2,1,$year));  // 季起始月1日
            $st = $db->prepare("SELECT COUNT(*) total,
                                       SUM(CASE WHEN d.diff_qty IS NOT NULL AND d.diff_qty<>0 THEN 1 ELSE 0 END) wrong
                                FROM stock_count_details d
                                JOIN stock_count_sessions s ON s.session_id=d.session_id
                                WHERE s.count_date BETWEEN ? AND ?
                                  AND s.status IN ('completed','closed')
                                  AND d.counted_qty IS NOT NULL");
            $st->execute([$qs, $me]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $den = (int)($r['total'] ?? 0);
            $num = $den - (int)($r['wrong'] ?? 0);
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'drawing_ontime': {
            $threshold = max(1, (int)kpi_as_pv($params, 'threshold_days', 4));
            $designers = kpi_as_list(kpi_as_pv($params, 'designer_ids', ['109110201','112020603']));
            if (!$designers) return null;
            $excl = kpi_as_resolve_clients($db, kpi_as_list(kpi_as_pv($params, 'exclude_clients', [])));
            $excl = array_values(array_unique(array_merge($excl, array_map('strval', $rules['client'] ?? []))));
            $sql = "SELECT ot.ateGet, ot.pmGet FROM order_track ot
                    LEFT JOIN user us ON us.id=ot.ate
                    WHERE ot.ate IN (" . implode(',', array_fill(0, count($designers), '?')) . ")
                      AND DATE_FORMAT(ot.ateGet,'%Y-%m')=?";
            $bind = array_merge($designers, [$ym]);
            if ($excl) {
                $sql .= " AND ot.Client_name NOT IN (" . implode(',', array_fill(0, count($excl), '?')) . ")";
                $bind = array_merge($bind, $excl);
            }
            foreach ([['part','ot.d_id'], ['designer','COALESCE(us.user_cname, ot.ate)']] as $rr) {
                $vals = $rules[$rr[0]] ?? [];
                if (!$vals) continue;
                $sql .= " AND (" . $rr[1] . " IS NULL OR " . $rr[1] . " NOT IN ("
                      . implode(',', array_fill(0, count($vals), '?')) . "))";
                $bind = array_merge($bind, array_map('strval', $vals));
            }
            // 逐筆排除（kpi_as_adjust 的 row_key＝order_track.Order_id）
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            if ($exIds) {
                $sql .= " AND ot.Order_id NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")";
                $bind = array_merge($bind, $exIds);
            }
            $st = $db->prepare($sql);
            $st->execute($bind);
            $num = 0; $den = 0;
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $den++;
                if (empty($r['pmGet']) || $r['pmGet'] === '0000-00-00 00:00:00') { $num++; continue; } // 未移轉視為進行中(沿用原口徑days=0)
                $d1 = substr((string)$r['ateGet'], 0, 10);
                $d2 = substr((string)$r['pmGet'], 0, 10);
                $days = ($d1 === $d2) ? 1 : kpi_as_workdays_inclusive($db, $d1, $d2);
                if ($days <= $threshold) $num++;
            }
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'capacity_rate': {
            $types = array_map('intval', kpi_as_list(kpi_as_pv($params, 'machine_type_ids', [])));
            $machines = array_map('intval', kpi_as_list(kpi_as_pv($params, 'machine_ids', [])));
            if (!$types && !$machines) return null;
            // 指定機台優先：有勾指定機台就只算那些機台；否則才用機台種類（不可用 OR，否則兩者都設時會退化成整個種類）
            $bind = [$ms, $me];
            if ($machines) {
                $cond = "r.machine_id IN (" . implode(',', array_fill(0, count($machines), '?')) . ")";
                $bind = array_merge($bind, $machines);
            } else {
                $cond = "ml.machine_type_id IN (" . implode(',', array_fill(0, count($types), '?')) . ")";
                $bind = array_merge($bind, $types);
            }
            list($rSql, $rBind) = kpi_as_rules_sql($rules, [
                'machine' => 'ml.machine', 'client' => 'b.Client_Name', 'part' => 'b.d_id',
                'proc'    => "COALESCE(NULLIF(pn.ProcessName,''), r.process_no)"]);
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            $exSql = $exIds ? (" AND r.report_id NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")") : '';
            $st = $db->prepare("SELECT r.produced_qty, r.production_start_time, r.production_end_time
                                FROM pm_process_daily_report r
                                JOIN machine_list ml ON ml.machine_id=r.machine_id
                                LEFT JOIN process_no pn ON pn.ProcessNo=r.process_no
                                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid
                                LEFT JOIN bom b ON b.bom=bi.bom
                                WHERE r.report_date BETWEEN ? AND ? AND (" . $cond . ")" . $rSql . $exSql);
            $st->execute(array_merge($bind, $rBind, $exIds));
            $qty = 0; $hours = 0.0;
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $qty += (int)$r['produced_qty'];
                if (!empty($r['production_start_time']) && !empty($r['production_end_time'])) {
                    $sec = strtotime((string)$r['production_end_time']) - strtotime((string)$r['production_start_time']);
                    if ($sec > 0) $hours += $sec / 3600;
                }
            }
            return ['num'=>$qty, 'den'=>round($hours, 2), 'value'=>$hours > 0 ? $qty / $hours : null];
        }

        case 'process_ng_rate': {
            $types = array_map('intval', kpi_as_list(kpi_as_pv($params, 'process_type_ids', [12])));
            if (!$types) return null;
            $in = implode(',', array_fill(0, count($types), '?'));
            list($rSql, $rBind) = kpi_as_rules_sql($rules, [
                'machine' => 'ml.machine', 'client' => 'b.Client_Name', 'part' => 'b.d_id',
                'proc'    => "COALESCE(NULLIF(pn.ProcessName,''), r.process_no)"]);
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            $exSql = $exIds ? (" AND r.report_id NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")") : '';
            $join = " JOIN process_no pn ON pn.ProcessNo=r.process_no
                      LEFT JOIN machine_list ml ON ml.machine_id=r.machine_id
                      LEFT JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid
                      LEFT JOIN bom b ON b.bom=bi.bom
                      WHERE pn.process_type_id IN ($in) AND r.report_date BETWEEN ? AND ?";
            $st = $db->prepare("SELECT COALESCE(SUM(r.produced_qty),0) FROM pm_process_daily_report r"
                               . $join . $rSql . $exSql);
            $st->execute(array_merge($types, [$ms, $me], $rBind, $exIds));
            $den = (float)$st->fetchColumn();
            $st = $db->prepare("SELECT COALESCE(SUM(g.ng_qty),0) FROM pm_process_daily_ng g
                                JOIN pm_process_daily_report r ON r.report_id=g.report_id"
                               . $join . $rSql . $exSql);
            $st->execute(array_merge($types, [$ms, $me], $rBind, $exIds));
            $num = (float)$st->fetchColumn();
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'incoming_ng_rate': {
            $ngs = kpi_as_list(kpi_as_pv($params, 'ng_statuses', ['ng']));
            if (!$ngs) $ngs = ['ng'];
            // 排除規則（客戶／料號／製程／廠商）與逐筆排除都要同時套在分子與分母，否則不良率會被灌水
            list($rSql, $rBind) = kpi_as_rules_sql($rules, [
                'client' => 'b.Client_Name', 'part' => 'b.d_id',
                'proc'   => "COALESCE(NULLIF(pn.ProcessName,''), bi.process_no)",
                'maker'  => 'COALESCE(mk.maker_id, bi.maker_id)']);
            $exIds = array_values(array_filter(array_map('intval', $exclRows)));
            $exSql = $exIds ? (" AND bi.bom_ing_fid NOT IN (" . implode(',', array_fill(0, count($exIds), '?')) . ")") : '';
            $from = " FROM bom_ing bi
                      LEFT JOIN bom b ON b.bom=bi.bom
                      LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                      LEFT JOIN maker_list mk ON mk.maker_id_no=bi.maker_id_no
                      WHERE bi.QC_check_date IS NOT NULL AND DATE_FORMAT(bi.QC_check_date,'%Y-%m')=?";
            $st = $db->prepare("SELECT COUNT(*)" . $from
                               . " AND bi.QC_check IS NOT NULL AND bi.QC_check<>''" . $rSql . $exSql);
            $st->execute(array_merge([$ym], $rBind, $exIds));
            $den = (int)$st->fetchColumn();
            $st = $db->prepare("SELECT COUNT(*)" . $from
                               . " AND bi.QC_check IN (" . implode(',', array_fill(0, count($ngs), '?')) . ")"
                               . $rSql . $exSql);
            $st->execute(array_merge([$ym], $ngs, $rBind, $exIds));
            $num = (int)$st->fetchColumn();
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'packing_ng_rate': {
            $st = $db->prepare("SELECT COALESCE(SUM(ng_qty),0) n, COALESCE(SUM(inspected_qty),0) d
                                FROM qc_packing_inspection WHERE inspection_date BETWEEN ? AND ?");
            $st->execute([$ms, $me]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $num = (float)($r['n'] ?? 0); $den = (float)($r['d'] ?? 0);
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'calibration_ontime': {
            require_once __DIR__ . '/tool_calib_lib.php';
            return tool_calib_kpi_compute($db, $year, $month, $params);
        }

        case 'training_completion': {
            require_once __DIR__ . '/training_lib.php';
            return training_kpi_compute($db, $year, $month, $params, $exclRows, $rules);
        }

        case 'vendor_audit_ontime': {
            require_once __DIR__ . '/vendor_audit_lib.php';
            return vendor_audit_kpi_compute($db, $year, $month, $params);
        }
    }
    return null;
}

/* ============================================================
 * 快照結算 / 矩陣組裝
 * ============================================================ */
/**
 * 某一格的計算（含「逐筆排除」與「維度排除規則」）。
 * **所有算這一格的地方都要走這一支**——少帶排除條件，畫面上就會出現
 * 「排除了卻沒有變」或「即時試算跟結算值對不起來」這種查不出來的落差。
 * @param array|null $paramsOverride 試算用：改過的參數（不傳＝用該年度設定）
 */
function kpi_as_compute_iy(PDO $db, array $iy, int $year, int $month, ?array $paramsOverride = null): ?array {
    if ($iy['source_mode'] !== 'auto' || empty($iy['calculator_key'])) return null;
    $iid = (int)$iy['indicator_id'];
    return kpi_as_compute($db, (string)$iy['calculator_key'], $year, $month,
                          $paramsOverride === null ? kpi_as_params($iy['params_json']) : $paramsOverride,
                          kpi_as_adjust_keys($db, $iid, $year, $month),
                          kpi_as_excl_rules($db, $iid, $year));
}

/** 計算並寫入某格快照（僅 auto 模式）。回傳計算結果或 null */
function kpi_as_settle(PDO $db, array $iy, int $year, int $month, array $u): ?array {
    if ($iy['source_mode'] !== 'auto' || empty($iy['calculator_key'])) return null;
    $res = kpi_as_compute_iy($db, $iy, $year, $month);
    $val = $res ? $res['value'] : null;
    $st = $db->prepare("INSERT INTO kpi_as_monthly_value (indicator_id,year,month,auto_value,numerator,denominator,computed_at)
                        VALUES (?,?,?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE auto_value=VALUES(auto_value), numerator=VALUES(numerator),
                                                denominator=VALUES(denominator), computed_at=NOW()");
    $st->execute([(int)$iy['indicator_id'], $year, $month,
                  $val === null ? null : round($val, 4),
                  $res['num'] ?? null, $res['den'] ?? null]);
    return $res;
}

/** 顯示值優先序：覆寫 > 手動 > 自動 */
function kpi_as_display_value(?array $mv): ?float {
    if (!$mv) return null;
    if ($mv['override_value'] !== null) return (float)$mv['override_value'];
    if ($mv['manual_value'] !== null)   return (float)$mv['manual_value'];
    if ($mv['auto_value'] !== null)     return (float)$mv['auto_value'];
    return null;
}

/** 未達標判定 */
function kpi_as_below_target(?float $v, array $iy): bool {
    if ($v === null || $iy['target_value'] === null) return false;
    $t = (float)$iy['target_value'];
    switch ($iy['target_direction']) {
        case 'lte': return $v > $t;
        case 'yes': return $v < 1;
        default:    return $v < $t;
    }
}

/* ============================================================
 * 資料來源目錄 no-code builder（安全通用查詢器）
 * spec = { num:{side}, den:{side}|null, multiply_100:bool }
 * side = { ds_id, agg:'count|sum', measure_field_id, filters:[{field_id,op,values[]}] }
 * 安全：表名/欄名一律取自目錄白名單(kpi_ds_catalog/kpi_ds_field)且過識別字正規；值一律綁定
 * ============================================================ */
function kpi_as_ident_ok($s): bool {
    return is_string($s) && $s !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s);
}

/** 允許的篩選運算子 */
function kpi_as_builder_ops(): array {
    return ['in'=>'屬於','not_in'=>'不屬於','eq'=>'等於','ne'=>'不等於',
            'gt'=>'大於','lt'=>'小於','ge'=>'大於等於','le'=>'小於等於',
            'isnull'=>'空值','notnull'=>'非空值'];
}

/** 計算 builder 一側（分子或分母），回傳數值或 null(無法計算) */
function kpi_as_builder_side(PDO $db, int $year, int $month, ?array $side): ?float {
    if (!$side || empty($side['ds_id'])) return null;
    $st = $db->prepare("SELECT table_name, date_column FROM kpi_ds_catalog WHERE ds_id=? AND is_active=1");
    $st->execute([(int)$side['ds_id']]);
    $ds = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ds) return null;
    $table = $ds['table_name']; $dateCol = $ds['date_column'];
    if (!kpi_as_ident_ok($table) || !kpi_as_ident_ok($dateCol)) return null;

    $fst = $db->prepare("SELECT field_id, column_name, role FROM kpi_ds_field WHERE ds_id=? AND is_active=1");
    $fst->execute([(int)$side['ds_id']]);
    $fields = [];
    foreach ($fst->fetchAll(PDO::FETCH_ASSOC) as $f) $fields[(int)$f['field_id']] = $f;

    $agg = strtolower((string)($side['agg'] ?? 'count'));
    $aggSql = 'COUNT(*)';
    if ($agg === 'sum') {
        $mf = $fields[(int)($side['measure_field_id'] ?? 0)] ?? null;
        if (!$mf || $mf['role'] !== 'measure' || !kpi_as_ident_ok($mf['column_name'])) return null;
        $aggSql = 'COALESCE(SUM(`' . $mf['column_name'] . '`),0)';
    }

    $ym = sprintf('%04d-%02d', $year, $month);
    $where = ["DATE_FORMAT(`$dateCol`,'%Y-%m')=?"];
    $bind = [$ym];
    $ops = kpi_as_builder_ops();
    foreach (($side['filters'] ?? []) as $flt) {
        $f = $fields[(int)($flt['field_id'] ?? 0)] ?? null;
        if (!$f || !kpi_as_ident_ok($f['column_name'])) continue;
        $op = (string)($flt['op'] ?? 'in');
        if (!isset($ops[$op])) continue;
        $col = '`' . $f['column_name'] . '`';
        $vals = $flt['values'] ?? [];
        if (!is_array($vals)) $vals = [$vals];
        $vals = array_values(array_filter(array_map('strval', $vals), function ($x) { return $x !== ''; }));
        switch ($op) {
            case 'in':     if ($vals) { $where[] = "$col IN (" . implode(',', array_fill(0, count($vals), '?')) . ")"; $bind = array_merge($bind, $vals); } break;
            case 'not_in': if ($vals) { $where[] = "$col NOT IN (" . implode(',', array_fill(0, count($vals), '?')) . ")"; $bind = array_merge($bind, $vals); } break;
            case 'eq': if ($vals !== []) { $where[] = "$col = ?";  $bind[] = $vals[0]; } break;
            case 'ne': if ($vals !== []) { $where[] = "$col <> ?"; $bind[] = $vals[0]; } break;
            case 'gt': if ($vals !== []) { $where[] = "$col > ?";  $bind[] = $vals[0]; } break;
            case 'lt': if ($vals !== []) { $where[] = "$col < ?";  $bind[] = $vals[0]; } break;
            case 'ge': if ($vals !== []) { $where[] = "$col >= ?"; $bind[] = $vals[0]; } break;
            case 'le': if ($vals !== []) { $where[] = "$col <= ?"; $bind[] = $vals[0]; } break;
            case 'isnull':  $where[] = "($col IS NULL OR $col='')"; break;
            case 'notnull': $where[] = "($col IS NOT NULL AND $col<>'')"; break;
        }
    }
    $sql = "SELECT $aggSql FROM `$table` WHERE " . implode(' AND ', $where);
    try {
        $st = $db->prepare($sql);
        $st->execute($bind);
        return (float)$st->fetchColumn();
    } catch (Throwable $e) { return null; }
}

/** builder 整體計算：回傳 ['num','den','value'] 或 null */
function kpi_as_builder_compute(PDO $db, int $year, int $month, array $spec): ?array {
    $num = kpi_as_builder_side($db, $year, $month, $spec['num'] ?? null);
    if ($num === null) return null;
    $hasDen = !empty($spec['den']) && !empty($spec['den']['ds_id']);
    if (!$hasDen) return ['num' => $num, 'den' => null, 'value' => $num]; // 純計數/加總
    $den = kpi_as_builder_side($db, $year, $month, $spec['den']);
    if ($den === null) return ['num' => $num, 'den' => null, 'value' => null];
    $mult = !empty($spec['multiply_100']) ? 100 : 1;
    return ['num' => $num, 'den' => $den, 'value' => $den > 0 ? $num / $den * $mult : null];
}

/** builder spec 轉人話摘要（給明細顯示）：例「退貨單 筆數 ÷ 出貨單 筆數 ×100」 */
function kpi_as_builder_summary(PDO $db, array $spec): string {
    $cats = [];
    foreach (kpi_as_catalog($db) as $c) {
        $cats[(int)$c['ds_id']] = $c;
    }
    $sideTxt = function ($side) use ($cats) {
        if (!$side || empty($side['ds_id']) || !isset($cats[(int)$side['ds_id']])) return '—';
        $ds = $cats[(int)$side['ds_id']];
        $t = $ds['ds_label'];
        if (($side['agg'] ?? 'count') === 'sum') {
            $fl = '數值';
            foreach ($ds['fields'] as $f) if ((int)$f['field_id'] === (int)($side['measure_field_id'] ?? 0)) $fl = $f['field_label'];
            $t .= ' ' . $fl . '加總';
        } else { $t .= ' 筆數'; }
        $flt = [];
        foreach (($side['filters'] ?? []) as $ft) {
            foreach ($ds['fields'] as $f) if ((int)$f['field_id'] === (int)($ft['field_id'] ?? 0)) {
                $ops = kpi_as_builder_ops();
                $flt[] = $f['field_label'] . ' ' . ($ops[$ft['op'] ?? 'in'] ?? '') . ' ' . implode('/', (array)($ft['values'] ?? []));
            }
        }
        if ($flt) $t .= '（' . implode('，', $flt) . '）';
        return $t;
    };
    $s = $sideTxt($spec['num'] ?? null);
    if (!empty($spec['den']) && !empty($spec['den']['ds_id'])) {
        $s .= ' ÷ ' . $sideTxt($spec['den']);
        if (!empty($spec['multiply_100'])) $s .= ' ×100';
    }
    return $s;
}

/** 指標資料來源說明（給明細彈窗）：['label','page','desc','links']
 *  links＝「要去哪一頁改真正的資料」，帶 $uid 才會做權限判定（見 kpi_as_source_links） */
function kpi_as_source_info(PDO $db, string $mode, ?string $calc, array $params, int $uid = 0): array {
    if ($mode === 'manual') return ['label'=>'手動填寫', 'page'=>'由擔當者每期填報', 'desc'=>'', 'links'=>[]];
    if ($calc === '__builder__') {
        $ln = [];
        if ($uid > 0) {
            if (!function_exists('eg_asdoc_page_can_open')) {
                $f = __DIR__ . '/asdoc_page_lib.php';
                if (is_file($f)) require_once $f;
            }
            $u2 = '/EGsystem/views/news/KPI_setting.php';
            $can = function_exists('eg_asdoc_page_can_open') ? eg_asdoc_page_can_open($db, $uid, $u2) : false;
            $ln[] = ['label'=>'KPI 設定（資料來源目錄／自訂公式）', 'url'=>$can ? $u2 : '', 'can'=>$can ? 1 : 0];
        }
        return ['label'=>'自訂公式', 'page'=>'資料來源目錄', 'desc'=>kpi_as_builder_summary($db, $params), 'links'=>$ln];
    }
    $reg = kpi_as_registry();
    if ($calc && isset($reg[$calc])) return ['label'=>$reg[$calc]['name'], 'page'=>$reg[$calc]['page'],
        'desc'=>$reg[$calc]['desc'], 'links'=>$uid > 0 ? kpi_as_source_links($db, $uid, $calc) : []];
    return ['label'=>'—', 'page'=>'', 'desc'=>'', 'links'=>[]];
}

/** 目錄（含欄位）供設定頁使用 */
function kpi_as_catalog(PDO $db): array {
    $cats = $db->query("SELECT ds_id, ds_label, table_name, date_column, note, is_active, sort_order
                        FROM kpi_ds_catalog ORDER BY sort_order, ds_id")->fetchAll(PDO::FETCH_ASSOC);
    $fmap = [];
    foreach ($db->query("SELECT field_id, ds_id, field_label, column_name, role, data_type, is_active, sort_order
                         FROM kpi_ds_field ORDER BY sort_order, field_id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $fmap[(int)$f['ds_id']][] = $f;
    }
    foreach ($cats as &$c) $c['fields'] = $fmap[(int)$c['ds_id']] ?? [];
    return $cats;
}

/* ============================================================
 * 快照過期偵測（使用者要求 2026-09-14）
 * 背景：自動指標的值是寫進 kpi_as_monthly_value 的「快照」，原本只有「完全沒有快照」
 *       時才會現場結算一次；快照寫下去之後，來源資料再怎麼補登都不會重算也不會提示
 *       （實際案例：4~7月的教育訓練場次是 8/13 之後才補建的，快照停在 8/11 的 0/0，
 *         畫面就一直顯示「?」）。
 * 判定：快照 computed_at < 來源資料表最後異動時間 → 這一格算過期，要重算並標示。
 * 取「來源最後異動時間」一律走 information_schema.tables.UPDATE_TIME
 *       （新增/修改/刪除都算得到，一次把整個資料庫的表撈回來約 2ms；
 *         預設統計值會快取 24 小時，所以要先把 information_schema_stats_expiry 設為 0）。
 *       該值為 NULL（引擎不提供／重啟後未異動）時，退回該表時間戳欄位的 MAX()。
 * ============================================================ */

/** 這個計算模組會讀到哪些資料表（只用於過期偵測，不影響計算本身） */
function kpi_as_source_tables(PDO $db, ?string $calc, array $params): array {
    if ($calc === '__builder__') {
        $ids = [];
        foreach (['num', 'den'] as $side) {
            if (!empty($params[$side]['ds_id'])) $ids[] = (int)$params[$side]['ds_id'];
        }
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $db->prepare("SELECT table_name FROM kpi_ds_catalog WHERE ds_id IN ($in)");
            $st->execute($ids);
            return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) { return []; }
    }
    $reg = kpi_as_registry();
    if ($calc && isset($reg[$calc]['tables']) && is_array($reg[$calc]['tables'])) return $reg[$calc]['tables'];
    return [];
}

/** 單一資料表的時間戳欄位最大值（UPDATE_TIME 取不到時的退路） */
function kpi_as_table_ts_max(PDO $db, string $table): ?string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return null;
    try { $cols = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return null; }
    $pick = [];
    foreach ($cols as $c) {
        if (preg_match('/^(modified_at|updated_at|created_at|update_time)$/i', (string)$c['Field'])
            && preg_match('/^(timestamp|datetime)/i', (string)$c['Type'])) {
            $pick[] = $c['Field'];
        }
    }
    if (!$pick) return null;
    $sel = [];
    foreach ($pick as $p) $sel[] = "MAX(`$p`)";
    try { $row = $db->query("SELECT " . implode(',', $sel) . " FROM `$table`")->fetch(PDO::FETCH_NUM); }
    catch (Throwable $e) { return null; }
    $max = null;
    foreach (($row ?: []) as $v) { if ($v !== null && ($max === null || $v > $max)) $max = $v; }
    return $max;
}

/** 一組資料表的「最後異動時間」（取最大值）；全部取不到回 null＝不做過期判定 */
function kpi_as_tables_mtime(PDO $db, array $tables): ?string {
    static $ut = null, $fb = [];
    $tables = array_values(array_unique(array_filter(array_map('strval', $tables), 'strlen')));
    if (!$tables) return null;
    if ($ut === null) {
        $ut = [];
        try { $db->exec("SET SESSION information_schema_stats_expiry=0"); } catch (Throwable $e) {}
        try {
            $st = $db->query("SELECT TABLE_NAME, UPDATE_TIME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ut[strtolower((string)$r['TABLE_NAME'])] = $r['UPDATE_TIME'] ?: null;
            }
        } catch (Throwable $e) { $ut = []; }
    }
    $max = null;
    foreach ($tables as $t) {
        $k = strtolower($t);
        $v = $ut[$k] ?? null;
        if ($v === null) {
            if (!array_key_exists($k, $fb)) $fb[$k] = kpi_as_table_ts_max($db, $t);
            $v = $fb[$k];
        }
        if ($v !== null && ($max === null || $v > $max)) $max = $v;
    }
    return $max;
}

/** 快照是否已過期（來源資料在快照之後又動過） */
function kpi_as_snapshot_stale(?string $computedAt, ?string $srcMtime): bool {
    if (!$computedAt || !$srcMtime) return false;
    return strtotime($computedAt) < strtotime($srcMtime);
}

/**
 * 指標的「資料來源頁面」清單（要去哪一頁改真正的資料）。
 * 權限比照左側選單（eg_asdoc_page_can_open）：沒權限者只回標籤、不回網址（鐵律8：不外洩入口）。
 * 沒有登記進選單的頁面（如訂單追蹤）用 perm_url 指定一個「權限參考頁」。
 */
function kpi_as_source_links(PDO $db, int $uid, ?string $calc): array {
    $reg = kpi_as_registry();
    $links = ($calc && isset($reg[$calc]['links']) && is_array($reg[$calc]['links'])) ? $reg[$calc]['links'] : [];
    if (!$links) return [];
    if (!function_exists('eg_asdoc_page_can_open')) {
        $f = __DIR__ . '/asdoc_page_lib.php';
        if (is_file($f)) require_once $f;
    }
    $out = [];
    foreach ($links as $ln) {
        $url  = (string)($ln['url'] ?? '');
        $perm = (string)($ln['perm_url'] ?? $url);
        $can  = ($url !== '' && function_exists('eg_asdoc_page_can_open'))
                ? eg_asdoc_page_can_open($db, $uid, $perm) : false;
        $out[] = ['label' => (string)($ln['label'] ?? ''), 'url' => $can ? $url : '', 'can' => $can ? 1 : 0];
    }
    return $out;
}

/* ============================================================
 * 不符合標準的明細 ＋ 修改建議（使用者要求 2026-09-14）
 * 「先判斷哪些超過規定，只顯示超過規定的提供修改，並附上修改建議」
 * ——不是把整格的來源資料全倒出來。
 * 兩種處置：
 *   allow（可改真實資料）＝列出違規列＋建議，到來源頁面修正
 *   deny （不可改真實資料）＝只能「排除」這一筆（寫 kpi_as_adjust，真實資料一個字都不動）
 * 哪些不可改由 kpi_as_edit_mode_suggest() 給系統建議，管理員可在 KPI 設定頁覆寫。
 * ============================================================ */

/** 系統建議：來源資料可不可以直接改。deny=不可改(會連動帳務) allow=可改 na=不適用 */
function kpi_as_edit_mode_suggest(?string $calc): array {
    switch ((string)$calc) {
        case 'complaint_rate':
            return ['deny', '分母是出貨單、分子是客退單，兩者都直接連動應收帳款月份與發票'];
        case 'order_target_amount':
            return ['deny', '訂單金額與交期歸屬直接影響帳款月份'];
        case 'shipping_target_amount':
            return ['deny', '出貨金額直接連動應收帳款月份與發票'];
        case 'vendor_ontime':
            return ['deny', '發包日／回廠日會影響加工費的應付帳款月份'];
        case 'order_ontime':
            return ['deny', '訂單交期與未交量會影響出貨與應收帳款月份'];
        case 'quote_to_order':
            return ['deny', '報價單是對外文件，內容與核准狀態不在 KPI 頁修改'];
        case 'capacity_rate':
            return ['allow', '報工的完成數與生產起訖時間可在報工紀錄查詢頁修正，不影響帳務'];
        case 'process_ng_rate':
            return ['allow', '報工的 NG 數與不良原因可在報工紀錄查詢頁修正，不影響帳務'];
        case 'packing_ng_rate':
        case 'calibration_ontime':
            return ['na', '目前來源尚未連動，實務上以手動覆寫填值'];
        case '':
            return ['na', '手動填寫指標'];
        default:
            return ['allow', '不影響帳務，可直接修正來源資料'];
    }
}

/** 這個指標實際採用的模式（管理員設定優先於系統建議） */
function kpi_as_edit_mode(PDO $db, int $iid, ?string $calc): array {
    $sg  = kpi_as_edit_mode_suggest($calc);
    $sug = $sg[0]; $why = $sg[1];
    $set = 'suggest';
    try {
        $st = $db->prepare("SELECT src_edit_mode FROM kpi_as_indicator WHERE indicator_id=?");
        $st->execute([$iid]);
        $v = $st->fetchColumn();
        if ($v) $set = (string)$v;
    } catch (Throwable $e) {}
    $eff = ($set === 'suggest') ? $sug : $set;
    return ['mode' => $eff, 'setting' => $set, 'suggest' => $sug, 'why' => $why];
}

/** 這個計算模組有沒有做「違規明細」（沒做的一律不給排除，避免排了卻不影響計算） */
function kpi_as_detail_supported(?string $calc): bool {
    return in_array((string)$calc, ['vendor_ontime', 'order_ontime',
                                    'training_completion', 'drawing_ontime', 'incoming_ng_rate',
                                    // 2026-09-17 使用者要求補做（即使來源資料不開放直接改，也要看得到明細）
                                    'quote_to_order', 'shipping_target_amount', 'order_target_amount',
                                    'capacity_rate', 'process_ng_rate'], true);
}

/** 某一格已排除的來源列（完整資料，內部畫面用） */
function kpi_as_adjust_rows(PDO $db, int $iid, int $year, int $month): array {
    try {
        $st = $db->prepare("SELECT * FROM kpi_as_adjust WHERE indicator_id=? AND year=? AND month=? ORDER BY adj_id");
        $st->execute([$iid, $year, $month]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** 某一格已排除的 row_key（計算時用） */
function kpi_as_adjust_keys(PDO $db, int $iid, int $year, int $month): array {
    try {
        $st = $db->prepare("SELECT row_key FROM kpi_as_adjust WHERE indicator_id=? AND year=? AND month=?");
        $st->execute([$iid, $year, $month]);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 不符合標準的明細。
 * 回傳 ['cols'=>[['k','t']], 'rows'=>[['key','vals','why','fix']], 'total'=>n, 'note'=>'']
 * rows 只含「超過規定」的那幾筆；已排除的由呼叫端用 kpi_as_adjust_rows() 疊上去。
 */
function kpi_as_detail(PDO $db, ?string $calc, int $year, int $month, array $params,
                      array $rules = [], array $iy = []): array {
    return kpi_as_detail_finish(kpi_as_detail_raw($db, $calc, $year, $month, $params, $iy), $rules);
}

/**
 * 明細列的共同收尾：標記被「排除規則」命中的列、統計筆數、整理出可篩選／可設規則的維度清單。
 * 維度選項一律**從實際列出來的資料收集**（使用者原話：看資料內有哪些資料就提供那些設定），
 * 不預先寫死一份客戶／製程清單，否則資料一變說明就對不上（鐵律4）。
 */
function kpi_as_detail_finish(array $out, array $rules): array {
    $opts = []; $bad = 0; $ruleEx = 0;
    foreach ($out['rows'] as &$r) {
        $dims = isset($r['dims']) && is_array($r['dims']) ? $r['dims'] : [];
        $dids = isset($r['dim_ids']) && is_array($r['dim_ids']) ? $r['dim_ids'] : [];
        foreach ($dims as $dk => $dv) {
            $dv = trim((string)$dv);
            if ($dv === '') continue;
            // 同一個值只留一筆；代號有就記起來（候選清單要讓人用代號搜尋）
            if (!isset($opts[$dk][$dv]) || $opts[$dk][$dv] === '') {
                $opts[$dk][$dv] = trim((string)($dids[$dk] ?? ''));
            }
        }
        $kind = (string)($r['kind'] ?? (!empty($r['warn']) ? 'warn' : 'bad'));
        $hit  = kpi_as_dims_hit($dims, $rules);
        $r['kind']    = $kind;
        $r['warn']    = ($kind === 'bad') ? 0 : 1;
        $r['rule_ex'] = $hit;
        if ($hit !== '') $ruleEx++;
        elseif ($kind === 'bad') $bad++;
    }
    unset($r);
    $labels = kpi_as_dim_labels();
    $dims = [];
    foreach ($opts as $dk => $vals) {
        ksort($vals, SORT_NATURAL | SORT_FLAG_CASE);
        $os = [];
        foreach ($vals as $v => $id) $os[] = ['v'=>(string)$v, 'id'=>(string)$id];
        $dims[] = ['k'=>$dk, 't'=>($labels[$dk] ?? $dk), 'opts'=>$os];
    }
    $out['total']     = $bad;
    $out['rule_ex']   = $ruleEx;
    $out['dims']      = $dims;
    $out['note_excl']  = (string)($out['note_excl'] ?? '');   // 排除相關的說明：畫面顯示、列印一律不印
    // 列印版（要給稽核老師看）只印這一句正式的表格說明；畫面上的 note 講的是判定方法與推估來源，
    // 那是內部作業說明，不可以出現在正式清單上（使用者要求 2026-09-18）
    $out['note_print'] = (string)($out['note_print'] ?? '');
    return $out;
}

function kpi_as_detail_raw(PDO $db, ?string $calc, int $year, int $month, array $params, array $iy = []): array {
    $ms = sprintf('%04d-%02d-01', $year, $month);
    $me = date('Y-m-t', strtotime($ms));
    $ym = sprintf('%04d-%02d', $year, $month);
    $out = ['cols' => [], 'rows' => [], 'total' => 0, 'note' => ''];

    switch ((string)$calc) {

        case 'vendor_ontime': {
            $rows = kpi_as_vendor_rows($db, $year, $month, $params);
            $out['cols'] = [['k'=>'bom','t'=>'製令'], ['k'=>'client','t'=>'客戶'], ['k'=>'part','t'=>'料號'],
                            ['k'=>'proc','t'=>'製程'], ['k'=>'maker','t'=>'廠商'],
                            ['k'=>'qty','t'=>'數量'], ['k'=>'out','t'=>'發包日'], ['k'=>'due','t'=>'應交日'],
                            ['k'=>'back','t'=>'回廠日']];
            $today = date('Y-m-d');
            $srcName = kpi_as_vendor_back_sources();
            $guessN = 0;
            foreach ($rows as $r) {
                if ($r['back'] !== '' && $r['back'] <= $r['due']) continue;   // 準時＝不是違規列
                $days = $r['days'];
                if ($r['back'] === '') {
                    $late = (int)round((strtotime($today) - strtotime($r['due'])) / 86400);
                    $why  = '未登錄回廠日' . ($late > 0 ? ('（已逾應交日 ' . $late . ' 天）') : '');
                    $fix  = '系統已經找過「下一製程發包日／QC檢驗日／出貨日／製令結案日」都沒有資料，'
                          . '所以這一筆看起來是真的還沒回廠。若貨其實已回，請到 BOM 總表補登「回廠日」。';
                    $backTxt = '—';
                } else {
                    $late = (int)round((strtotime($r['back']) - strtotime($r['due'])) / 86400);
                    $backTxt = eg_fmt_date($r['back']);
                    if ($r['back_src'] !== 'return') {
                        $guessN++;
                        $srcTxt = $srcName[$r['back_src']] ?? $r['back_src'];
                        $backTxt .= '（推估：' . $srcTxt . '）';
                        // back_earlier＝回廠日有登錄，但這個來源的日期更早，所以採用較早的那一天
                        $why = !empty($r['back_earlier'])
                             ? ('登錄的回廠日晚於「' . $srcTxt . '」的 ' . eg_fmt_date($r['back'])
                                . '，依較早者判定，仍晚於應交日 ' . $late . ' 天')
                             : ('回廠日沒有登錄，依「' . $srcTxt . '」推估為 ' . eg_fmt_date($r['back'])
                                . '，仍晚於應交日 ' . $late . ' 天');
                        $fix = '推估日期只用來判定準不準時，不會寫回任何一筆資料。'
                             . '請到 BOM 總表補登真正的回廠日，判定才會精準。';
                    } else {
                        $why = '回廠日晚於應交日 ' . $late . ' 天';
                        $fix = '確認回廠日是否登錄錯誤（BOM 總表可改）；若這個製程本來就需要 '
                             . ($days + $late) . ' 個工作天以上，請到 KPI 設定把「' . $r['proc']
                             . '」的容忍天數由 ' . $days . ' 天往上調整（在外包廠商績效頁的 KPI 標準設定）。';
                    }
                }
                $out['rows'][] = [
                    'key'  => $r['fid'],
                    'vals' => ['bom'=>$r['bom'], 'client'=>$r['client'], 'part'=>$r['part'],
                               'proc'=>$r['proc'], 'maker'=>$r['maker'], 'qty'=>$r['qty'],
                               'out'=>eg_fmt_date($r['out']), 'due'=>eg_fmt_date($r['due']), 'back'=>$backTxt],
                    // 列印用：回廠日只印日期，不印「（推估：○○）」（正式清單不寫內部判定過程）
                    'pvals' => ['back'=>($r['back'] !== '' ? eg_fmt_date($r['back']) : '—')],
                    'dims' => $r['dims'], 'dim_ids' => $r['dim_ids'],
                    'kind' => 'bad', 'why' => $why, 'fix' => $fix,
                ];
            }
            usort($out['rows'], function ($a, $b) { return strcmp($a['vals']['due'], $b['vals']['due']); });
            $out['note_print'] = '本表為應交日落在本月、未於應交日前回廠之委外加工明細。';
            $out['note'] = '判定與設定一律取自「外包廠商績效」頁（容忍天數、廠商／製程特殊天數、例外廠商、例外製程），兩頁數字完全一致。'
                         . '截止日＝發包日＋容忍天數（依行事曆工作日）。只列「本月發包、已到容忍期、卻沒有準時回廠」的發包。'
                         . '沒登錄回廠日時，系統會依序用「製程移轉憑單（單號日期）→下一製程發包日→QC檢驗日→出貨日→製令結案日」'
                         . '推估回廠日（只用於判定，不寫回資料；憑單日期取自單號而非 transfer_date，避免帳款月份調整的影響）'
                         . ($guessN ? ('，本月有 ' . $guessN . ' 筆是這樣推估出來的') : '') . '。';
            return $out;
        }

        case 'order_ontime': {
            $mode = kpi_as_undone_mode($params);
            if (($mode === 'erp_ship' || $mode === 'erp') && !kpi_as_order_list_covers($db, $year, $month)) {
                $asof = kpi_as_order_list_asof($db);
                $out['note'] = '這個年度的「未交判定方式」設定為以 ERP 未交清單（order_list）為準，'
                             . '但那張表最後一次匯入是 ' . ($asof ? eg_fmt_date($asof) : '（查不到）')
                             . '，涵蓋不到 ' . $year . ' 年 ' . $month . ' 月——匯入日之後成立的訂單它根本沒有，'
                             . '硬算會得到「幾乎都已交」的假數字，所以這一格不產生數值。'
                             . '請改用 C（以網頁上的出貨綁定為準），或先把 ERP 未交清單重新匯入一次。';
                $out['note_print'] = '本月無可用資料。';
                return $out;
            }
            // 明細這裡只用「參數設定的排除客戶」過濾；排除規則命中的列仍然要列出來並標示，
            // 由 kpi_as_detail_finish() 統一標記（排掉就看不到、也解不開）
            $exCli = kpi_as_resolve_clients($db, kpi_as_list(kpi_as_pv($params, 'exclude_clients', ['寶嘉誠','泳建'])));

            /* ---- C（預設）／B：都以「出貨日 vs 交期」判定 ---- */
            if ($mode === 'bind' || $mode === 'erp_ship' || $mode === 'ship') {
                $rows = kpi_as_order_bind_rows($db, $year, $month, $params, [], $mode);
                $cmapB = kpi_as_client_id_map($db);
                $grace = kpi_as_oo_grace_days($params);
                $out['cols'] = [['k'=>'oo','t'=>'訂單編號'], ['k'=>'client','t'=>'客戶'], ['k'=>'d_id','t'=>'料號'],
                                ['k'=>'qty','t'=>'訂單量'], ['k'=>'dd','t'=>'交期']];
                if ($grace > 0) $out['cols'][] = ['k'=>'dl','t'=>'寬限後截止日'];
                // C 才有出貨日（來自綁定）；B 讀的是 ERP 未交清單，沒有出貨日期
                if ($mode === 'bind' || $mode === 'ship') $out['cols'][] = ['k'=>'sd','t'=>'出貨日'];
                $out['cols'][] = ['k'=>'ship','t'=>'疑似已出貨(未綁)', 'p'=>0];
                $noShip = [];
                foreach ($rows as $r) if ($r['ship_date'] === '') $noShip[] = ['cname'=>$r['Client_name'], 'd_id'=>$r['d_id']];
                // C 模式才需要另外查「疑似已出貨」當提示；B 模式已經把它算進出貨日了
                $hint = ($mode === 'bind' && $noShip) ? kpi_as_oo_ship_hint($db, $noShip, $year, $month) : [];
                $hintN = 0; $lateN = 0; $noneN = 0;
                foreach ($rows as $r) {
                    if ($r['ontime']) continue;                       // 準時＝不是違規列
                    $dims = ['client'=>(string)$r['Client_name'], 'part'=>(string)$r['d_id']];
                    $hk = trim($dims['client']) . "\x00" . trim($dims['part']);
                    $hv = ($mode === 'erp_ship' || $mode === 'ship') ? ($r['hint'] ?? null)
                        : (($r['ship_date'] === '') ? ($hint[$hk] ?? null) : null);
                    if ($hv) $hintN++;
                    if ($r['ship_date'] === '') $noneN++; else $lateN++;
                    if ($mode === 'ship') {
                        if ($r['ship_date'] !== '') {
                            $late = (int)round((strtotime($r['ship_date']) - strtotime($r['deadline'])) / 86400);
                            $why = '這個料號最早的出貨日 ' . eg_fmt_date($r['ship_date'])
                                 . ($grace > 0
                                    ? ('，晚於寬限後截止日 ' . eg_fmt_date($r['deadline']) . ' ' . $late . ' 天'
                                       . '（交期 ' . eg_fmt_date($r['due']) . '＋寬限 ' . $grace . ' 個工作天）')
                                    : ('，晚於交期 ' . $late . ' 天'));
                            $fix = '確認交期是否已與客戶談妥延後（可到訂單追蹤更新交期）；'
                                 . '若這一張其實對到的是別批出貨，請到快速出貨把出貨單綁到訂單，'
                                 . '綁定之後判定就會以那一張為準。';
                        } else {
                            $why = '查不到這個料號的任何出貨紀錄（交期前後 120 天內）';
                            $fix = '若已出貨請確認出貨單有沒有匯入；若客戶要求延後交期，'
                                 . '請到訂單追蹤更新交期，這一筆就會改算到新的月份。';
                        }
                    } elseif ($mode === 'erp_ship') {
                        // B 模式讀的是 ERP 未交清單，它沒有出貨日期，所以只講「還完全沒出貨」
                        $why = '交期已到，ERP 未交清單上這一張還是「完全沒出貨」'
                             . ($hv ? '——但查到同客戶同料號有一張沒綁訂單的出貨單，很可能其實已經出貨了' : '');
                        $fix = $hv
                             ? ($hv['d'] . ' 有一張出貨單 ' . $hv['no'] . '（數量 ' . $hv['qty'] . '）沒有綁到任何訂單。'
                                . '等 ERP 修好、出貨單重新載入之後這一筆應該就會從未交清單消失；'
                                . '若確定不會，請確認 ERP 那邊這張訂單有沒有結掉。')
                             : ('若實際已出貨，請確認 ERP 的未交清單有沒有更新（這個模式讀的就是它）；'
                                . '若客戶要求延後交期，請到訂單追蹤更新交期，這一筆就會改算到新的月份。');
                    } elseif ($r['ship_date'] !== '') {
                        $late = (int)round((strtotime($r['ship_date']) - strtotime($r['deadline'])) / 86400);
                        $why = '出貨日 ' . eg_fmt_date($r['ship_date'])
                             . ($grace > 0
                                ? ('，晚於寬限後截止日 ' . eg_fmt_date($r['deadline']) . ' ' . $late . ' 天'
                                   . '（交期 ' . eg_fmt_date($r['due']) . '＋寬限 ' . $grace . ' 個工作天）')
                                : ('，晚於交期 ' . $late . ' 天'));
                        $fix = '確認出貨日或交期是否登錄錯誤；若客戶同意延後，請到訂單追蹤更新交期，'
                             . '這一筆就會改算到新的月份；確實遲交的屬真實逾期，不必修改。';
                    } elseif ($hv) {
                        $why = '這張訂單查不到綁定的出貨單——但同客戶同料號有一張沒綁訂單的出貨單，很可能就是它';
                        $fix = $hv['d'] . ' 有一張出貨單 ' . $hv['no'] . '（數量 ' . $hv['qty'] . '）沒有綁到任何訂單。'
                             . '請到「快速出貨」把它綁到這張訂單，綁好之後重算這一格就會變成準時（或依實際出貨日判定）。';
                    } else {
                        $why = '這張訂單查不到任何綁定的出貨單';
                        $fix = '若已經出貨，請到「快速出貨」把出貨單綁到這張訂單；'
                             . '若客戶要求延後交期，請到訂單追蹤更新交期；確實還沒出貨的屬真實未交。';
                    }
                    $out['rows'][] = [
                        'key'  => (string)$r['Order_id'],
                        'vals' => ['oo'=>(string)$r['Order_oo'], 'client'=>$dims['client'], 'd_id'=>$dims['part'],
                                   'qty'=>(string)(0 + $r['Qty']), 'dd'=>eg_fmt_date($r['due']),
                                   'dl'=>($grace > 0 ? eg_fmt_date($r['deadline']) : ''),
                                   'sd'=>($r['ship_date'] !== '' ? eg_fmt_date($r['ship_date']) : '—'),
                                   'ship'=>($hv ? ($hv['no'] . '（' . eg_fmt_date($hv['d']) . '）') : '')],
                        'dims' => $dims,
                        'dim_ids' => ['client'=>((string)$r['Client_name_ID'] !== ''
                                                 ? (string)$r['Client_name_ID']
                                                 : ($cmapB[trim($dims['client'])] ?? '')), 'part'=>''],
                        'kind' => 'bad', 'why' => $why, 'fix' => $fix,
                    ];
                }
                if ($mode === 'ship') {
                    $graceTxt = $grace > 0
                        ? ('寬限 ' . $grace . ' 個工作天（交期之後 ' . $grace . ' 個工作天內交貨仍算準時）。')
                        : '未設寬限（要在交期當天或之前交貨才算準時，可在 KPI 設定頁的「寬限工作天數」調整）。';
                    $out['note'] = $graceTxt
                                 . '未交判定方式：D｜以出貨單反推（用料號主檔 id 對應 order_track.d_id_ID ↔ is_list.d_setting_id，'
                                 . '不必做出貨綁定、也不必 ERP 未交清單）。料號主檔 id 本身就綁定客戶，所以不必再比客戶名稱'
                                 . '（舊訂單的客戶名稱寫法常跟出貨單不一樣）。'
                                 . '分母＝本月交期的訂單（訂單追蹤，已排除取消的訂單），分子＝該料號最早出貨日不晚於'
                                 . ($grace > 0 ? '寬限後截止日' : '交期') . '。'
                                 . '有做出貨綁定的那幾張一律以綁定的為準。'
                                 . '本月未準時 ' . ($lateN + $noneN) . ' 筆：晚於交期 ' . $lateN . ' 筆、查不到出貨紀錄 ' . $noneN . ' 筆。';
                    $out['note_print'] = '本表為本月交期、未於交期前出貨之訂單明細。';
                    $out['note_excl'] = $exCli ? ('排除客戶：' . implode('、', $exCli) . '。') : '';
                    return $out;
                }
                $out['note'] = ($mode === 'bind'
                                ? (($grace > 0 ? ('寬限 ' . $grace . ' 個工作天。') : '')
                                   . '未交判定方式：C｜以網頁上的出貨綁定為準。分母＝本月交期的訂單'
                                   . '（訂單追蹤，已排除取消的訂單），分子＝有綁到出貨單且出貨日不晚於'
                                   . ($grace > 0 ? '寬限後截止日' : '交期') . '。')
                                : ('未交判定方式：B｜以 ERP 未交清單為準，逐筆對回訂單（同客戶＋同料號，一張未交只佔用一張訂單）。'
                                   . '分母＝本月交期的訂單（訂單追蹤，已排除取消的訂單），'
                                   . '分子＝ERP 未交清單上沒有的（＝ERP 認為已交）。'
                                   . 'ERP 的未交清單沒有出貨日期，所以這個模式只判得出「到期了還完全沒出」，'
                                   . '寬限工作天數在這個模式下沒有作用（沒有出貨日就無從寬限），'
                                   . '判不出「是不是在交期前交的」——要判那個請改用 C（但需要先把出貨單綁到訂單）。'))
                             . '本月未準時 ' . ($lateN + $noneN) . ' 筆'
                             . ($mode === 'bind' ? ('：遲交 ' . $lateN . ' 筆、查不到出貨單 ' . $noneN . ' 筆') : '')
                             . ($hintN ? ('（其中 ' . $hintN . ' 筆查到同客戶同料號有沒綁訂單的出貨單）') : '') . '。';
                $out['note_excl'] = $exCli ? ('排除客戶：' . implode('、', $exCli) . '。') : '';
                $out['note_print'] = ($mode === 'bind')
                    ? '本表為本月交期、未於交期前出貨之訂單明細。'
                    : '本表為本月交期、到期仍未出貨之訂單明細。';
                return $out;
            }

            /* ---- B／舊制：沿用 ERP 未交清單 ---- */
            $notIn = $exCli ? (" AND COALESCE(cl.customer, ol.Client_name) NOT IN ("
                               . implode(',', array_fill(0, count($exCli), '?')) . ")") : '';
            // order_list 的客戶欄存的是代號(C2005)，畫面上要看的是中文簡稱，故對回 customer_list
            $sql = "SELECT ol.Order_id, ol.Order_oo, ol.d_id, ol.Qty, ol.Open_Qty, ol.Delivery_date,
                           ol.Client_name AS client_code,
                           COALESCE(cl.customer, ol.Client_name) AS Client_name
                    FROM order_list ol
                    LEFT JOIN customer_list cl ON cl.customer_id=ol.Client_name
                    WHERE UPPER(ol.d_id)<>'ZZZ' AND LOWER(ol.d_id) NOT REGEXP '-(jg|jh|hg)$'
                      AND DATE_FORMAT(ol.Delivery_date,'%Y-%m')=?" . $notIn . "
                      AND ol.Qty=ol.Open_Qty AND ol.Order_status IS NULL
                    ORDER BY ol.Delivery_date, ol.Order_id";
            $st = $db->prepare($sql);
            $st->execute(array_merge([$ym], $exCli));
            $orows = $st->fetchAll(PDO::FETCH_ASSOC);
            // 未準時的一大原因是「其實出貨了，只是出貨單沒有跟訂單綁起來」（ERP 匯入的出貨多半沒帶訂單編號）
            $shipHint = $orows ? kpi_as_oo_ship_hint($db, array_map(function ($r) {
                return ['cname'=>$r['Client_name'], 'd_id'=>$r['d_id']];
            }, $orows), $year, $month) : [];
            $hintN = 0;
            $out['cols'] = [['k'=>'oo','t'=>'訂單編號'], ['k'=>'client','t'=>'客戶'], ['k'=>'d_id','t'=>'料號'],
                            ['k'=>'qty','t'=>'訂單量'], ['k'=>'open','t'=>'未交量'], ['k'=>'dd','t'=>'交期'],
                            ['k'=>'ship','t'=>'疑似已出貨', 'p'=>0]];
            foreach ($orows as $r) {
                $hint = $shipHint[trim((string)$r['Client_name']) . "\x00" . trim((string)$r['d_id'])] ?? null;
                if ($hint) $hintN++;
                // B 模式：查得到出貨單的就不算未交，所以標成「提醒」不計入不符合筆數
                $isBad = !($mode === 'erp_ship' && $hint);
                $out['rows'][] = [
                    'key'  => (string)$r['Order_id'],
                    'vals' => ['oo'=>(string)$r['Order_oo'], 'client'=>(string)$r['Client_name'],
                               'd_id'=>(string)$r['d_id'], 'qty'=>(string)(0 + $r['Qty']),
                               'open'=>(string)(0 + $r['Open_Qty']), 'dd'=>eg_fmt_date($r['Delivery_date']),
                               'ship'=>($hint ? ($hint['no'] . '（' . eg_fmt_date($hint['d']) . '）') : '')],
                    'dims' => ['client'=>(string)$r['Client_name'], 'part'=>(string)$r['d_id']],
                    'dim_ids' => ['client'=>(string)$r['client_code'], 'part'=>''],
                    'kind' => ($isBad ? 'bad' : 'warn'),
                    'why'  => $isBad
                            ? ('交期已到本月，但未交量＝訂單量（完全沒出貨）'
                               . ($hint ? '——但查到同客戶同料號有一張沒有綁訂單的出貨單，很可能其實已經出貨了' : ''))
                            : '（已不計入）ERP 未交清單上還掛著，但查到同客戶同料號有出貨單，依本年度設定不算未交',
                    'fix'  => $hint
                            ? ($hint['d'] . ' 有一張出貨單 ' . $hint['no'] . '（數量 ' . $hint['qty'] . '）沒有綁到任何訂單。'
                               . '請到「快速出貨」把出貨單與這張訂單綁起來；'
                               . '把本年度的「未交判定方式」改成 C 之後，綁好當下重算就會反映。')
                            : ('若實際已出貨，請確認出貨單有沒有帶到這張訂單（未交量沒被沖銷）；'
                               . '若客戶要求延後交期，請到訂單追蹤更新交期，這一筆就會改算到新的月份。'),
                ];
            }
            // 分母來自 order_track（自建訂單追蹤）、未交量來自 order_list（ERP 未交清單），
            // 兩張表是各自獨立的資料，舊月份常出現「未交筆數比訂單筆數還多」＝準時率被算成 0。
            $denTrack = 0;
            try {
                $q = $db->prepare("SELECT COUNT(*) FROM order_track
                                   WHERE UPPER(d_id)<>'ZZZ' AND LOWER(d_id) NOT REGEXP '-(jg|jh|hg)$'
                                     AND DATE_FORMAT(Delivery_date,'%Y-%m')=?"
                                  . ($exCli ? (" AND Client_name NOT IN ("
                                      . implode(',', array_fill(0, count($exCli), '?')) . ")") : ''));
                $q->execute(array_merge([$ym], $exCli));
                $denTrack = (int)$q->fetchColumn();
            } catch (Throwable $e) {}
            $out['note'] = '未交判定方式：' . ($mode === 'erp_ship'
                            ? 'B｜ERP 未交清單，但查得到同客戶同料號的出貨單就不算未交'
                            : '舊制｜完全以 ERP 未交清單為準（網頁上的出貨綁定不影響這個數字）')
                         . '。判定沿用本指標既有規則：料號 ZZZ 與 -jg/-jh/-hg 結尾不計。'
                         . '本月訂單筆數（分母，取自訂單追蹤）' . $denTrack . ' 筆，'
                         . '未交筆數（取自 ERP 未交清單）' . count($out['rows']) . ' 筆'
                         . (count($out['rows']) > $denTrack
                            ? '——未交筆數比訂單筆數還多，是因為 ERP 未交清單會累積更早月份還沒結清的訂單，'
                              . '這種月份準時率會被算成 0%，請以明細逐筆確認。'
                            : '。')
                         . ($hintN ? ('其中 ' . $hintN . ' 筆查到同客戶同料號有「沒有綁訂單」的出貨單，'
                                      . '很可能其實已經出貨、只是出貨單沒跟訂單綁起來（見「疑似已出貨」欄）。') : '');
            $out['note_excl'] = $exCli ? ('排除客戶：' . implode('、', $exCli) . '。') : '';
            $out['note_print'] = '本表為本月交期、尚未出貨之訂單明細。';
            return $out;
        }

        /* ---- #19 人員教育訓練達成率：當月計畫卻沒完成的場次 ---- */
        case 'training_completion': {
            $inc = kpi_as_pv($params, 'include_cancelled', 0);
            $inc = ((int)$inc === 1);
            $sql = "SELECT session_id, course_name, train_type, org_unit, status, done_date,
                           target_headcount, actual_headcount, plan_month
                    FROM training_session WHERE year=? AND plan_month=? AND status<>'done'";
            if (!$inc) $sql .= " AND status<>'cancelled'";
            $sql .= " ORDER BY session_id";
            $st = $db->prepare($sql);
            $st->execute([$year, $month]);
            $stName = ['planned'=>'計畫中', 'scheduled'=>'已排定', 'cancelled'=>'取消', 'done'=>'已完成'];
            $out['cols'] = [['k'=>'course','t'=>'課程名稱'], ['k'=>'type','t'=>'類型'], ['k'=>'unit','t'=>'受訓單位'],
                            ['k'=>'st','t'=>'目前狀態'], ['k'=>'people','t'=>'預定人數']];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['total']++;
                $stv = (string)$r['status'];
                $out['rows'][] = [
                    'key'  => (string)$r['session_id'],
                    'vals' => ['course'=>(string)$r['course_name'],
                               'type'=>((string)$r['train_type'] === 'out' ? '外訓' : '內訓'),
                               'unit'=>(string)$r['org_unit'], 'st'=>($stName[$stv] ?? $stv),
                               'people'=>(string)(0 + $r['target_headcount'])],
                    'dims' => ['unit'=>(string)$r['org_unit']], 'dim_ids' => ['unit'=>''], 'kind' => 'bad',
                    'why'  => '列在 ' . $month . ' 月的計畫，但還沒登錄完成（狀態：' . ($stName[$stv] ?? $stv) . '）',
                    'fix'  => '若這場已經辦完了，請到教育訓練管理登錄完成（要有簽到與評鑑，所以不在這裡改）；'
                            . '若改到別的月份舉辦，直接把「計畫月份」改成實際月份，這一筆就會改算到那個月；'
                            . '若確定不辦了，狀態改成「取消」就不會列入分母。',
                ];
            }
            $out['note_print'] = '本表為本月計畫、尚未完成之教育訓練場次明細。';
            $out['note'] = '達成率＝當月已完成場次 ÷ 當月計畫場次'
                         . ($inc ? '（取消的場次有列入分母）' : '（取消的場次不列入分母）') . '。';
            return $out;
        }

        /* ---- #11 出圖準時率：接單移轉設計之後，超過門檻工作日才移轉生管（或還沒移轉） ---- */
        case 'drawing_ontime': {
            $threshold = max(1, (int)kpi_as_pv($params, 'threshold_days', 4));
            $designers = kpi_as_list(kpi_as_pv($params, 'designer_ids', ['109110201','112020603']));
            if (!$designers) { $out['note'] = '尚未設定設計者，無法判定。'; return $out; }
            $exCli = kpi_as_list(kpi_as_pv($params, 'exclude_clients', []));
            $sql = "SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.Client_name, ot.ate, ot.ateGet, ot.pmGet,
                           us.user_cname AS designer
                    FROM order_track ot
                    LEFT JOIN user us ON us.id=ot.ate
                    WHERE ot.ate IN (" . implode(',', array_fill(0, count($designers), '?')) . ")
                      AND DATE_FORMAT(ot.ateGet,'%Y-%m')=?";
            $bind = array_merge($designers, [$ym]);
            if ($exCli) {
                $sql .= " AND ot.Client_name NOT IN (" . implode(',', array_fill(0, count($exCli), '?')) . ")";
                $bind = array_merge($bind, $exCli);
            }
            $sql .= " ORDER BY ot.ateGet, ot.Order_id";
            $st = $db->prepare($sql);
            $st->execute($bind);
            $cmapD = kpi_as_client_id_map($db);
            $warnN = 0;
            $out['cols'] = [['k'=>'oo','t'=>'訂單編號'], ['k'=>'client','t'=>'客戶'], ['k'=>'d_id','t'=>'料號'],
                            ['k'=>'designer','t'=>'設計者'], ['k'=>'ate','t'=>'接單移轉設計'],
                            ['k'=>'pm','t'=>'設計移轉生管'], ['k'=>'days','t'=>'工作日']];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $noPm = (empty($r['pmGet']) || $r['pmGet'] === '0000-00-00 00:00:00');
                $d1 = substr((string)$r['ateGet'], 0, 10);
                $d2 = $noPm ? '' : substr((string)$r['pmGet'], 0, 10);
                $days = null;
                if (!$noPm) $days = ($d1 === $d2) ? 1 : kpi_as_workdays_inclusive($db, $d1, $d2);
                if (!$noPm && $days <= $threshold) continue;      // 準時＝不是違規列
                // 注意：**現行計算把「還沒登錄移轉生管」當成進行中、算成準時**（見 compute 的
                // 「未移轉視為進行中」那一行）。所以這種列不能算進違規數，否則明細筆數會跟
                // den-num 對不起來（實測 2026-08：真正違規 47 筆、未移轉 14 筆）。
                // 但它多半是「圖交了卻沒登錄」，是最值得補的資料，所以照樣列出來標成提醒。
                $warn = $noPm ? 1 : 0;
                if (!$warn) $out['total']++;
                if ($noPm) {
                    $why = '（提醒）尚未登錄「設計移轉生管」——現行口徑視為進行中，仍算準時';
                    $fix = '若圖已經交給生管，請把移轉日期補上（可直接在這裡改），補上之後才會真正納入準時判定；'
                         . '還在設計中的就維持空白。';
                } else {
                    $why = '接單到移轉生管共 ' . $days . ' 個工作日，超過門檻 ' . $threshold . ' 天';
                    $fix = '確認兩個日期是否登錄錯誤（可直接在這裡改）；若確實花了這麼久，屬真實逾期，不必修改。';
                }
                $out['rows'][] = [
                    'key'  => (string)$r['Order_id'],
                    'vals' => ['oo'=>(string)$r['Order_oo'], 'client'=>(string)$r['Client_name'],
                               'd_id'=>(string)$r['d_id'], 'designer'=>(string)($r['designer'] ?: $r['ate']),
                               'ate'=>eg_fmt_date($d1), 'pm'=>($d2 !== '' ? eg_fmt_date($d2) : '—'),
                               'days'=>($days === null ? '—' : (string)$days)],
                    'dims' => ['client'=>(string)$r['Client_name'], 'part'=>(string)$r['d_id'],
                               'designer'=>(string)($r['designer'] ?: $r['ate'])],
                    'dim_ids' => ['client'=>($cmapD[trim((string)$r['Client_name'])] ?? ''),
                                  'part'=>'', 'designer'=>(string)$r['ate']],
                    'kind' => ($warn ? 'warn' : 'bad'),
                    'why'  => $why, 'fix' => $fix, 'warn' => $warn,
                ];
                if ($warn) $warnN++;
            }
            $out['warn'] = $warnN;
            $out['note_print'] = '本表為本月接單移轉設計、未於門檻工作日內移轉生管之訂單明細。';
            $out['note'] = '準時＝接單移轉設計到設計移轉生管在 ' . $threshold . ' 個工作日內（含起訖日，依行事曆工作日）。'
                         . ($warnN ? ('另有 ' . $warnN . ' 筆還沒登錄移轉生管，依現行口徑算成準時，已一併列出（標「提醒」）。') : '');
            return $out;
        }

        /* ---- #16 進料檢驗不良率：當月判定為不良的那幾筆 ---- */
        case 'incoming_ng_rate': {
            $ngs = kpi_as_list(kpi_as_pv($params, 'ng_statuses', ['ng']));
            if (!$ngs) $ngs = ['ng'];
            $st = $db->prepare("SELECT bi.bom_ing_fid, bi.bom, bi.sqty, bi.QC_check, bi.QC_check_date, bi.QC_ps,
                                       pn.ProcessName, bi.process_no,
                                       COALESCE(mk.maker_id, bi.maker_id) AS maker_name, bi.maker_id_no,
                                       b.d_id AS part_no, b.Client_Name AS client_name
                                FROM bom_ing bi
                                LEFT JOIN bom b ON b.bom=bi.bom
                                LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                                LEFT JOIN maker_list mk ON mk.maker_id_no=bi.maker_id_no
                                WHERE bi.QC_check_date IS NOT NULL AND DATE_FORMAT(bi.QC_check_date,'%Y-%m')=?
                                  AND bi.QC_check IN (" . implode(',', array_fill(0, count($ngs), '?')) . ")
                                ORDER BY bi.QC_check_date, bi.bom_ing_fid");
            $st->execute(array_merge([$ym], $ngs));
            $cmapI = kpi_as_client_id_map($db);
            $ckName = ['ok'=>'允收', 'ng'=>'驗退', 'QQ'=>'特採', 'AOD'=>'特採(AOD)'];
            $out['cols'] = [['k'=>'bom','t'=>'製令'], ['k'=>'client','t'=>'客戶'], ['k'=>'part','t'=>'料號'],
                            ['k'=>'proc','t'=>'製程'], ['k'=>'maker','t'=>'廠商'],
                            ['k'=>'qty','t'=>'數量'], ['k'=>'ck','t'=>'判定'], ['k'=>'ckd','t'=>'檢驗日'],
                            ['k'=>'ps','t'=>'檢驗備註']];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cv = (string)$r['QC_check'];
                $dims = ['client'=>(string)$r['client_name'], 'part'=>(string)$r['part_no'],
                         'proc'=>(string)($r['ProcessName'] ? $r['ProcessName'] : $r['process_no']),
                         'maker'=>(string)$r['maker_name']];
                $out['rows'][] = [
                    'key'  => (string)$r['bom_ing_fid'],
                    'vals' => ['bom'=>(string)$r['bom'], 'client'=>$dims['client'], 'part'=>$dims['part'],
                               'proc'=>$dims['proc'],
                               'maker'=>$dims['maker'], 'qty'=>(string)$r['sqty'],
                               'ck'=>(($ckName[$cv] ?? $cv) . '（' . $cv . '）'),
                               'ckd'=>eg_fmt_date($r['QC_check_date']),
                               'ps'=>mb_substr((string)$r['QC_ps'], 0, 40)],
                    'dims' => $dims,
                    'dim_ids' => ['client'=>($cmapI[trim((string)$r['client_name'])] ?? ''), 'part'=>'',
                                  'proc'=>(string)$r['process_no'], 'maker'=>(string)$r['maker_id_no']],
                    'kind' => 'bad',
                    'why'  => '檢驗判定為「' . ($ckName[$cv] ?? $cv) . '」，計入不良',
                    'fix'  => '只有「判定登錄錯誤」才在這裡改判定；確實不良請維持原判定（屬真實不良率）。'
                            . '若檢驗日期打錯月份，改日期即可讓這一筆算到正確的月份。',
                ];
            }
            $out['note_print'] = '本表為本月進料檢驗判定不良之明細。';
            $out['note'] = '不良率＝當月判定為 ' . implode('／', $ngs) . ' 的筆數 ÷ 當月檢驗總筆數。';
            return $out;
        }

        /* ---- #4 報價單接單率：當月報出去、到現在還沒有任何訂單引用的那幾張 ---- */
        case 'quote_to_order': {
            $cond = "DATE_FORMAT(q.quote_date,'%Y-%m')=? AND q.pending_review=0";
            if ((int)kpi_as_pv($params, 'exclude_draft', 1) === 1) $cond .= " AND q.is_draft=0";
            $st = $db->prepare("SELECT q.quote_id, q.quote_no, q.client_name, q.client_id, q.quote_date,
                                       q.total_amount, q.is_draft, q.currency
                                FROM quotation_list q WHERE $cond
                                ORDER BY q.quote_date, q.quote_id");
            $st->execute([$ym]);
            $qrows = $st->fetchAll(PDO::FETCH_ASSOC);
            // 「有沒有訂單引用」用一支 GROUP BY 一次查回來：
            // 寫成每一列一個相關子查詢，247 張報價單就要 1 秒（實測），而且會隨資料量愈來愈慢
            $usedMap = [];
            $qnos = array_values(array_filter(array_map(function ($r) { return (string)$r['quote_no']; }, $qrows), 'strlen'));
            foreach (array_chunk(array_unique($qnos), 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $q2 = $db->prepare("SELECT quote_no, COUNT(*) c FROM order_track
                                    WHERE quote_no IN ($in) GROUP BY quote_no");
                $q2->execute($chunk);
                foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $x) $usedMap[(string)$x['quote_no']] = (int)$x['c'];
            }
            $out['cols'] = [['k'=>'no','t'=>'報價單號'], ['k'=>'client','t'=>'客戶'],
                            ['k'=>'qd','t'=>'報價日'], ['k'=>'amt','t'=>'報價金額'], ['k'=>'st','t'=>'狀態']];
            foreach ($qrows as $r) {
                $used = $usedMap[(string)$r['quote_no']] ?? 0;
                $dims = ['client'=>(string)$r['client_name']];
                $out['rows'][] = [
                    'key'  => (string)$r['quote_id'],
                    'vals' => ['no'=>(string)$r['quote_no'], 'client'=>$dims['client'],
                               'qd'=>eg_fmt_date($r['quote_date']),
                               'amt'=>number_format((float)$r['total_amount']),
                               'st'=>($used ? ('已接單（' . $used . ' 張訂單）') : '尚未接單')],
                    'dims' => $dims, 'dim_ids' => ['client'=>(string)$r['client_id']],
                    'kind' => ($used ? 'info' : 'bad'),
                    'why'  => $used ? ('這一張已經被 ' . $used . ' 張訂單引用，計入接單（列出來只是方便對帳）')
                                    : '這一張報價單到目前為止沒有任何訂單引用它的報價單號',
                    'fix'  => $used ? '不必處理。'
                                    : '若客戶其實已經下單，多半是訂單上沒帶到報價單號——請到訂單追蹤把「報價單號」補上，'
                                    . '這一張就會計入接單率；若客戶確實沒有下單，屬真實未成交，不必修改。',
                ];
            }
            $out['note_print'] = '本表為本月報價單明細。';
            $out['note'] = '接單率＝當月報價單中「已被訂單引用報價單號」的張數 ÷ 當月報價單張數'
                         . '（不含尚待確認補件的匯入舊單）。未接單的排在清單裡標成不符合標準，已接單的標成「參考」。';
            return $out;
        }

        /* ---- #3 月銷貨額達成率：當月每一張出貨單的金額組成 ---- */
        case 'shipping_target_amount': {
            $tmap = kpi_as_pv($params, 'monthly_targets', []);
            $target = 0.0;
            if (is_array($tmap)) {
                if (isset($tmap[(string)$month])) $target = (float)$tmap[(string)$month];
                elseif (isset($tmap[$month]))     $target = (float)$tmap[$month];
            }
            $st = $db->prepare("SELECT IS_id, IS_number, Client_name, Product_id, Specification,
                                       Qty, Unit_price, Order_date
                                FROM is_list WHERE DATE_FORMAT(Order_date,'%Y-%m')=?
                                ORDER BY (Qty*COALESCE(Unit_price,0)) DESC, IS_id");
            $st->execute([$ym]);
            $srows = $st->fetchAll(PDO::FETCH_ASSOC);
            // 【ERP 重複轉出的判定】使用者回報 2026-09-18 並更正 2026-09-18：
            // **不可以**用「同一張出貨單號＋同一個料號＋同樣數量出現一次以上」當判定——
            // 一張出貨單本來就常有好幾筆相同料號、相同數量、同一天的明細（實測那幾筆的 IS_id 連號、
            // Created_At 完全相同，是同一次匯入的正常明細），這樣判會把正常出貨整批誤標成重複。
            // 真正的重複轉出長這樣：**同一張出貨單號被匯入兩次，而且兩次的出貨日期不一樣**
            // （例 IS1150825009 一次記 2026-08-25、一次記 2026-09-01，等於同一張單被算進兩個月份）。
            // 全庫只有 4 張單有這個特徵，判定很精準，不會誤傷正常出貨。
            $dupNo = []; $dupN = 0; $dupAmt = 0.0;
            if ($srows) {
                $nos = array_values(array_unique(array_map(function ($r) { return (string)$r['IS_number']; }, $srows)));
                foreach (array_chunk(array_filter($nos, 'strlen'), 500) as $chunk) {
                    $in = implode(',', array_fill(0, count($chunk), '?'));
                    try {
                        $q = $db->prepare("SELECT IS_number, GROUP_CONCAT(DISTINCT DATE(Order_date)
                                                  ORDER BY Order_date SEPARATOR '、') ds
                                           FROM is_list WHERE IS_number IN ($in)
                                           GROUP BY IS_number HAVING COUNT(DISTINCT DATE(Order_date)) > 1");
                        $q->execute($chunk);
                        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) $dupNo[(string)$x['IS_number']] = (string)$x['ds'];
                    } catch (Throwable $e) {}
                }
            }
            $cmapS = kpi_as_client_id_map($db);   // is_list.Client_id 全表是空的，只能用名稱回查代號
            $out['cols'] = [['k'=>'no','t'=>'出貨單號'], ['k'=>'client','t'=>'客戶'], ['k'=>'part','t'=>'料號'],
                            ['k'=>'qty','t'=>'數量'], ['k'=>'up','t'=>'單價'], ['k'=>'amt','t'=>'金額'],
                            ['k'=>'d','t'=>'出貨日']];
            $sum = 0.0; $noPrice = 0;
            foreach ($srows as $r) {
                $up  = $r['Unit_price'] === null ? null : (float)$r['Unit_price'];
                $amt = (float)$r['Qty'] * (float)($up ?? 0);
                $sum += $amt;
                $dupDs = $dupNo[(string)$r['IS_number']] ?? '';
                $dup = ($dupDs !== '');
                if ($dup) { $dupN++; $dupAmt += $amt; }
                $bad = ($up === null || $up <= 0);
                if ($bad) $noPrice++;
                $dims = ['client'=>(string)$r['Client_name'], 'part'=>(string)$r['Product_id']];
                $out['rows'][] = [
                    'key'  => (string)$r['IS_id'],
                    'vals' => ['no'=>(string)$r['IS_number'], 'client'=>$dims['client'], 'part'=>$dims['part'],
                               'qty'=>(string)(0 + $r['Qty']), 'up'=>($up === null ? '—' : (string)(0 + $up)),
                               'amt'=>number_format($amt), 'd'=>eg_fmt_date($r['Order_date'])],
                    'dims' => $dims,
                    'dim_ids' => ['client'=>($cmapS[trim((string)$r['Client_name'])] ?? ''), 'part'=>''],
                    'kind' => (($bad || $dup) ? 'bad' : 'info'),
                    'why'  => $dup ? ('這張出貨單號在系統裡有兩個以上不同的出貨日期（' . $dupDs
                                      . '），等於同一張單被算進不同月份——疑似 ERP 重複轉出')
                            : ($bad ? '沒有單價（或單價為 0），這一筆的金額算成 0，會把達成率往下拉'
                                    : '正常計入本月銷貨金額（列出來是方便逐筆核對）'),
                    'fix'  => $dup ? ('請到快速出貨查這張單號（' . (string)$r['IS_number'] . '）：'
                                      . '同一張單出現在 ' . $dupDs . ' 兩個日期，確認哪一個才是真正的出貨日，'
                                      . '把多轉出來的那一份刪掉；在刪掉之前可以先用下方「排除」把這一筆排除，金額就會回到正確值。')
                            : ($bad ? '請到快速出貨把這一筆的單價補上；若這一筆本來就不該算業績（樣品、補件、免費更換），'
                                    . '請用下方「排除」把它排掉，或用排除規則整批排除該客戶／料號。'
                                    : '不必處理。'),
                ];
            }
            $out['note_print'] = '本表為本月出貨金額明細。';
            $out['note'] = '達成率＝當月出貨金額 Σ(數量×單價) ÷ 本月銷貨目標。'
                         . '本月出貨金額 ' . number_format($sum) . '，目標 '
                         . ($target > 0 ? number_format($target) : '尚未設定')
                         . ($target > 0 ? ('，差額 ' . number_format($sum - $target)) : '')
                         . ($noPrice ? ('。其中 ' . $noPrice . ' 筆沒有單價（金額算 0）') : '')
                         . ($dupN ? ('。另有 ' . $dupN . ' 筆的出貨單號在系統裡有兩個以上不同的出貨日期'
                                     . '（疑似 ERP 重複轉出，共 ' . number_format($dupAmt) . '），請逐筆確認'
                                     . '——同一張單號出現好幾筆相同料號、相同數量是正常的，不算重複') : '') . '。';
            return $out;
        }

        /* ---- #2 月份受訂目標達成金額：帳款月窗口內每一張訂單的金額組成 ---- */
        case 'order_target_amount': {
            $st = $db->prepare("SELECT start_day FROM kpi_monthly_targets WHERE year=? AND month=? LIMIT 1");
            $st->execute([$year, $month]);
            $sd = (int)$st->fetchColumn();
            if ($sd < 1 || $sd > 28) $sd = 1;
            if ($sd > 1) {
                $ws = date('Y-m-d', mktime(0, 0, 0, $month - 1, $sd, $year));
                $we = date('Y-m-d', mktime(0, 0, 0, $month, $sd - 1, $year));
            } else { $ws = $ms; $we = $me; }
            $tmap = kpi_as_pv($params, 'monthly_targets', []);
            $target = 0.0;
            if (is_array($tmap)) {
                if (isset($tmap[(string)$month])) $target = (float)$tmap[(string)$month];
                elseif (isset($tmap[$month]))     $target = (float)$tmap[$month];
            }
            $st = $db->prepare("SELECT Order_id, Order_oo, Client_name, Client_name_ID, d_id, Qty, unit_price,
                                       Delivery_date, Order_status
                                FROM order_track WHERE Delivery_date BETWEEN ? AND ?
                                ORDER BY (Qty*COALESCE(unit_price,0)) DESC, Order_id");
            $st->execute([$ws, $we]);
            $cmapO = kpi_as_client_id_map($db);
            $out['cols'] = [['k'=>'oo','t'=>'訂單編號'], ['k'=>'client','t'=>'客戶'], ['k'=>'part','t'=>'料號'],
                            ['k'=>'qty','t'=>'數量'], ['k'=>'up','t'=>'單價'], ['k'=>'amt','t'=>'金額'],
                            ['k'=>'dd','t'=>'交期'], ['k'=>'st','t'=>'狀態']];
            $sum = 0.0; $badN = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $up   = $r['unit_price'] === null ? null : (float)$r['unit_price'];
                $void = ((string)$r['Order_status'] === '9');
                $counted = (!$void && $up !== null && $up > 0);
                $amt = (float)$r['Qty'] * (float)($up ?? 0);
                if ($counted) $sum += $amt;
                if (!$counted) $badN++;
                $dims = ['client'=>(string)$r['Client_name'], 'part'=>(string)$r['d_id']];
                $out['rows'][] = [
                    'key'  => (string)$r['Order_id'],
                    'vals' => ['oo'=>(string)$r['Order_oo'], 'client'=>$dims['client'], 'part'=>$dims['part'],
                               'qty'=>(string)(0 + $r['Qty']), 'up'=>($up === null ? '—' : (string)(0 + $up)),
                               'amt'=>($counted ? number_format($amt) : '不計入'),
                               'dd'=>eg_fmt_date($r['Delivery_date']),
                               'st'=>($void ? '已取消(9)' : '有效')],
                    'dims' => $dims,
                    'dim_ids' => ['client'=>((string)$r['Client_name_ID'] !== ''
                                             ? (string)$r['Client_name_ID']
                                             : ($cmapO[trim((string)$r['Client_name'])] ?? '')), 'part'=>''],
                    'kind' => ($counted ? 'info' : 'bad'),
                    'why'  => $counted ? '正常計入本月接單金額（列出來是方便逐筆核對）'
                                       : ($void ? '訂單狀態是 9（已取消），依現行口徑不計入接單金額'
                                                : '沒有單價（或單價為 0），這一筆整張都沒有被算進接單金額'),
                    'fix'  => $counted ? '不必處理。'
                                       : ($void ? '若這張其實沒取消，請到訂單追蹤把狀態改回來。'
                                                : '請到訂單追蹤把單價補上，這一筆才會計入接單金額；'
                                                . '若這張本來就不該算業績，請用排除功能排掉。'),
                ];
            }
            $out['note_print'] = '本表為本月接單金額明細（依交期歸屬帳款月）。';
            $out['note'] = '接單金額以「交期」歸屬帳款月窗口（' . eg_fmt_date($ws) . ' ~ ' . eg_fmt_date($we) . '）計算。'
                         . '本月接單金額 ' . number_format($sum) . '，目標 '
                         . ($target > 0 ? number_format($target) : '取出貨分析頁全域目標')
                         . ($badN ? ('。其中 ' . $badN . ' 筆沒有計入（無單價或已取消）') : '') . '。';
            return $out;
        }

        /* ---- #13/#14 產能績效：拉低每小時產出的那幾筆報工 ---- */
        case 'capacity_rate': {
            $types    = array_map('intval', kpi_as_list(kpi_as_pv($params, 'machine_type_ids', [])));
            $machines = array_map('intval', kpi_as_list(kpi_as_pv($params, 'machine_ids', [])));
            if (!$types && !$machines) { $out['note'] = '尚未設定機台種類或指定機台，無法列出明細。'; return $out; }
            $bind = [$ms, $me];
            if ($machines) {
                $cond = "r.machine_id IN (" . implode(',', array_fill(0, count($machines), '?')) . ")";
                $bind = array_merge($bind, $machines);
            } else {
                $cond = "ml.machine_type_id IN (" . implode(',', array_fill(0, count($types), '?')) . ")";
                $bind = array_merge($bind, $types);
            }
            $st = $db->prepare("SELECT r.report_id, r.report_date, r.produced_qty, r.machine_id,
                                       r.production_start_time, r.production_end_time,
                                       ml.machine AS machine_name, pn.ProcessName, r.process_no,
                                       bi.bom, b.d_id AS part_no, b.Client_Name AS client_name
                                FROM pm_process_daily_report r
                                JOIN machine_list ml ON ml.machine_id=r.machine_id
                                LEFT JOIN process_no pn ON pn.ProcessNo=r.process_no
                                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid
                                LEFT JOIN bom b ON b.bom=bi.bom
                                WHERE r.report_date BETWEEN ? AND ? AND (" . $cond . ")
                                ORDER BY r.report_date, r.report_id");
            $st->execute($bind);
            $cmapC = kpi_as_client_id_map($db);
            $rowsC = $st->fetchAll(PDO::FETCH_ASSOC);
            // 判定門檻＝這個指標自己的目標值（大於 N 顆/小時）
            $tgt = (isset($iy['target_value']) && $iy['target_value'] !== null) ? (float)$iy['target_value'] : 0.0;
            $out['cols'] = [['k'=>'d','t'=>'報工日'], ['k'=>'machine','t'=>'機台'], ['k'=>'bom','t'=>'製令'],
                            ['k'=>'client','t'=>'客戶'], ['k'=>'part','t'=>'料號'], ['k'=>'proc','t'=>'製程'],
                            ['k'=>'qty','t'=>'完成數'], ['k'=>'hr','t'=>'生產工時'], ['k'=>'rate','t'=>'顆/小時']];
            $noTime = 0;
            foreach ($rowsC as $r) {
                $hrs = null;
                if (!empty($r['production_start_time']) && !empty($r['production_end_time'])) {
                    $sec = strtotime((string)$r['production_end_time']) - strtotime((string)$r['production_start_time']);
                    if ($sec > 0) $hrs = $sec / 3600;
                }
                $qty  = (int)$r['produced_qty'];
                $rate = ($hrs !== null && $hrs > 0) ? ($qty / $hrs) : null;
                $dims = ['machine'=>(string)$r['machine_name'], 'client'=>(string)$r['client_name'],
                         'part'=>(string)$r['part_no'],
                         'proc'=>(string)($r['ProcessName'] ? $r['ProcessName'] : $r['process_no'])];
                if ($hrs === null) {
                    $noTime++;
                    $kind = 'bad';
                    $why  = '沒有生產起訖時間，工時算不出來——完成數卻照樣進了分子，等於把「顆/小時」灌高';
                    $fix  = '請到報工紀錄查詢補上這一筆的生產開始／結束時間；'
                          . '若這一筆本來就不是生產（試車、調機），請用排除功能排掉。';
                } elseif ($tgt > 0 && $rate !== null && $rate < $tgt) {
                    $kind = 'bad';
                    $why  = '這一筆的產出 ' . round($rate, 1) . ' 顆/小時，低於目標 ' . (0 + $tgt) . ' 顆/小時';
                    $fix  = '確認完成數與生產起訖時間有沒有登錄錯誤（報工紀錄查詢可改）；'
                          . '若確實是難加工件或首件試做，屬真實產能，不必修改。';
                } else {
                    $kind = 'info';
                    $why  = '達標（列出來是方便逐筆核對）';
                    $fix  = '不必處理。';
                }
                $out['rows'][] = [
                    'key'  => (string)$r['report_id'],
                    'vals' => ['d'=>eg_fmt_date($r['report_date']), 'machine'=>$dims['machine'],
                               'bom'=>(string)$r['bom'], 'client'=>$dims['client'], 'part'=>$dims['part'],
                               'proc'=>$dims['proc'], 'qty'=>(string)$qty,
                               'hr'=>($hrs === null ? '—' : (string)round($hrs, 2)),
                               'rate'=>($rate === null ? '—' : (string)round($rate, 1))],
                    'dims' => $dims,
                    'dim_ids' => ['machine'=>(string)$r['machine_id'],
                                  'client'=>($cmapC[trim((string)$r['client_name'])] ?? ''),
                                  'part'=>'', 'proc'=>(string)$r['process_no']],
                    'kind' => $kind, 'why' => $why, 'fix' => $fix,
                ];
            }
            $out['note_print'] = '本表為本月生產報工明細。';
            $out['note'] = '產能績效＝Σ完成數 ÷ Σ生產工時（小時）。'
                         . ($tgt > 0 ? ('目標 ' . (0 + $tgt) . ' 顆/小時，低於目標的那幾筆標成不符合標準。') : '')
                         . ($noTime ? ('本月有 ' . $noTime . ' 筆沒有生產起訖時間，工時算不出來。') : '');
            return $out;
        }

        /* ---- #15 齒研製程不良率：當月有登錄 NG 的那幾筆報工 ---- */
        case 'process_ng_rate': {
            $types = array_map('intval', kpi_as_list(kpi_as_pv($params, 'process_type_ids', [12])));
            if (!$types) { $out['note'] = '尚未設定製程類別，無法列出明細。'; return $out; }
            $in = implode(',', array_fill(0, count($types), '?'));
            $st = $db->prepare("SELECT r.report_id, r.report_date, r.produced_qty, r.machine_id,
                                       ml.machine AS machine_name, pn.ProcessName, r.process_no,
                                       bi.bom, b.d_id AS part_no, b.Client_Name AS client_name,
                                       COALESCE(SUM(g.ng_qty),0) AS ng_sum,
                                       GROUP_CONCAT(DISTINCT CONCAT(COALESCE(nt.ng_txt,''),
                                           CASE WHEN g.ng_remark IS NULL OR g.ng_remark='' THEN ''
                                                ELSE CONCAT('(', g.ng_remark, ')') END)
                                           SEPARATOR '、') AS ng_txt
                                FROM pm_process_daily_report r
                                JOIN process_no pn ON pn.ProcessNo=r.process_no
                                JOIN pm_process_daily_ng g ON g.report_id=r.report_id
                                LEFT JOIN ng_txt nt ON nt.ng_id=g.ng_id
                                LEFT JOIN machine_list ml ON ml.machine_id=r.machine_id
                                LEFT JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid
                                LEFT JOIN bom b ON b.bom=bi.bom
                                WHERE pn.process_type_id IN ($in) AND r.report_date BETWEEN ? AND ?
                                GROUP BY r.report_id
                                HAVING ng_sum > 0
                                ORDER BY ng_sum DESC, r.report_date");
            $st->execute(array_merge($types, [$ms, $me]));
            $cmapN = kpi_as_client_id_map($db);
            $out['cols'] = [['k'=>'d','t'=>'報工日'], ['k'=>'bom','t'=>'製令'], ['k'=>'client','t'=>'客戶'],
                            ['k'=>'part','t'=>'料號'], ['k'=>'proc','t'=>'製程'], ['k'=>'machine','t'=>'機台'],
                            ['k'=>'qty','t'=>'完成數'], ['k'=>'ng','t'=>'NG數'], ['k'=>'reason','t'=>'不良原因']];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $qty = (int)$r['produced_qty']; $ng = (float)$r['ng_sum'];
                $dims = ['client'=>(string)$r['client_name'], 'part'=>(string)$r['part_no'],
                         'proc'=>(string)($r['ProcessName'] ? $r['ProcessName'] : $r['process_no']),
                         'machine'=>(string)$r['machine_name']];
                $out['rows'][] = [
                    'key'  => (string)$r['report_id'],
                    'vals' => ['d'=>eg_fmt_date($r['report_date']), 'bom'=>(string)$r['bom'],
                               'client'=>$dims['client'], 'part'=>$dims['part'], 'proc'=>$dims['proc'],
                               'machine'=>$dims['machine'], 'qty'=>(string)$qty,
                               'ng'=>(string)(0 + $ng), 'reason'=>(string)$r['ng_txt']],
                    'dims' => $dims,
                    'dim_ids' => ['client'=>($cmapN[trim((string)$r['client_name'])] ?? ''), 'part'=>'',
                                  'proc'=>(string)$r['process_no'], 'machine'=>(string)$r['machine_id']],
                    'kind' => 'bad',
                    'why'  => 'NG ' . (0 + $ng) . ' 顆'
                            . ($qty > 0 ? ('（完成 ' . $qty . ' 顆，佔 ' . round($ng / $qty * 100, 1) . '%）') : ''),
                    'fix'  => '若 NG 數或不良原因登錄錯誤，請到報工紀錄查詢修正；確實不良請維持原樣（屬真實不良率）。'
                            . '若這一筆不該算進本月（例如重工後已補回），請用排除功能排掉。',
                ];
            }
            $out['note_print'] = '本表為本月報工不良明細。';
            $out['note'] = '不良率＝Σ當月 NG 數 ÷ Σ當月完成數（限設定的製程類別）。這裡只列有登錄 NG 的報工。';
            return $out;
        }
    }
    return $out;
}

/* ============================================================
 * 可直接修改真實資料的指標：違規明細的可編輯欄位白名單
 * 只有這裡列出來的資料表／主鍵／欄位／可選值才改得動；請求端只送 row_key 與欄位代號，
 * 表名欄名一律取自這份程式碼（不吃使用者輸入），且寫入前一定要先確認那一筆
 * 真的出現在「這一格的違規清單」裡（見 API src_edit）。
 * ============================================================ */
function kpi_as_detail_edit_spec(?string $calc): array {
    switch ((string)$calc) {
        case 'training_completion':
            $mon = [];
            for ($i = 1; $i <= 12; $i++) $mon[(string)$i] = $i . '月';
            return [
                'table' => 'training_session', 'pk' => 'session_id',
                'stamp' => null,                       // 這張表沒有 Modified_* 欄位
                'fields' => [
                    ['k'=>'plan_month', 't'=>'計畫月份', 'type'=>'select', 'opts'=>$mon, 'remonth'=>1,
                     'hint'=>'改成實際舉辦的月份，這一筆就會改算到那個月'],
                    ['k'=>'status', 't'=>'狀態', 'type'=>'select',
                     'opts'=>['planned'=>'計畫中', 'scheduled'=>'已排定', 'cancelled'=>'取消（不列入分母）'],
                     'hint'=>'「已完成」要有簽到與評鑑，請到教育訓練管理登錄'],
                ]];
        case 'drawing_ontime':
            return [
                // 刻意不寫 order_track 的 Modified_By/Modified_At：那是訂單變更比對的基準，
                // 在這裡蓋掉會讓訂單變更誤判（沿用 2026-09-03 設計備註那次的決定）。
                'table' => 'order_track', 'pk' => 'Order_id', 'stamp' => null,
                'fields' => [
                    ['k'=>'pmGet', 't'=>'設計移轉生管', 'type'=>'date', 'nullable'=>1,
                     'hint'=>'圖已交生管卻沒登錄的，補上實際日期'],
                    ['k'=>'ateGet', 't'=>'接單移轉設計', 'type'=>'date', 'remonth'=>1,
                     'hint'=>'這一欄決定這筆算在哪一個月'],
                ]];
        case 'incoming_ng_rate':
            return [
                'table' => 'bom_ing', 'pk' => 'bom_ing_fid',
                'stamp' => ['by'=>'Modified_By', 'at'=>'Modified_At'],
                'fields' => [
                    ['k'=>'QC_check', 't'=>'檢驗判定', 'type'=>'select',
                     'opts'=>['ok'=>'允收 ok', 'ng'=>'驗退 ng', 'QQ'=>'特採 QQ'],
                     'hint'=>'只有登錄錯誤才改；確實不良請維持原判定'],
                    ['k'=>'QC_check_date', 't'=>'檢驗日期', 'type'=>'date', 'remonth'=>1,
                     'hint'=>'這一欄決定這筆算在哪一個月'],
                ]];
    }
    return [];
}

/** 修改後這一筆會落在哪一個年月（remonth 欄位用）；回 [year, month] 或 null */
function kpi_as_edit_target_ym(?string $calc, string $field, string $value, int $curYear): ?array {
    if ($value === '') return null;
    if ($calc === 'training_completion' && $field === 'plan_month') {
        $m = (int)$value;
        return ($m >= 1 && $m <= 12) ? [$curYear, $m] : null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-\d{2}/', $value, $m2)) return [(int)$m2[1], (int)$m2[2]];
    return null;
}

/* ============================================================
 * 年度清單（使用者要求 2026-09-15：要能補 2024、也要能先開 2027）
 * 原本全站寫死 range(2025, 今年)，所以 2024 補不了、2027 也開不了。
 * 改成「資料庫裡已經有設定的年度 ∪ 2025~今年」，新增年度走 kpi_as_year_create()。
 * ============================================================ */
const KPI_AS_YEAR_MIN = 2015;          // 再舊就不合理（公司 ERP 資料起點之前）
const KPI_AS_YEAR_AHEAD = 3;           // 最多預先開未來 3 年

function kpi_as_years(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cur = (int)date('Y');
    $ys = [];
    for ($y = 2025; $y <= $cur; $y++) $ys[$y] = 1;   // 舊有預設範圍一律保留（不會讓既有畫面變少）
    try {
        foreach ($db->query("SELECT DISTINCT year FROM kpi_as_indicator_year")->fetchAll(PDO::FETCH_COLUMN) as $y) {
            $y = (int)$y;
            if ($y >= KPI_AS_YEAR_MIN && $y <= $cur + KPI_AS_YEAR_AHEAD) $ys[$y] = 1;
        }
    } catch (Throwable $e) {}
    $cache = array_keys($ys);
    sort($cache);
    return $cache;
}

/** 這個年度可不可以用（清單內才算） */
function kpi_as_year_ok(PDO $db, int $y): bool {
    return in_array($y, kpi_as_years($db), true);
}

/** 把請求帶進來的年度收斂成合法年度（不合法就用今年） */
function kpi_as_year_pick(PDO $db, $raw): int {
    $y = (int)$raw;
    return kpi_as_year_ok($db, $y) ? $y : (int)date('Y');
}

/** 可以新增哪些年度（還沒建、且在允許範圍內） */
function kpi_as_years_addable(PDO $db): array {
    $cur = (int)date('Y');
    $have = kpi_as_years($db);
    $out = [];
    for ($y = KPI_AS_YEAR_MIN; $y <= $cur + KPI_AS_YEAR_AHEAD; $y++) {
        if (!in_array($y, $have, true)) $out[] = $y;
    }
    return $out;
}

/**
 * 新增一個年度：把來源年度的指標設定整批複製過去（只補缺漏，不覆蓋既有）。
 * 回傳建立的指標筆數；$from 留 0＝自動挑最接近的已存在年度。
 */
function kpi_as_year_create(PDO $db, int $to, int $from, array $u): int {
    $cur = (int)date('Y');
    if ($to < KPI_AS_YEAR_MIN || $to > $cur + KPI_AS_YEAR_AHEAD) throw new RuntimeException('年度超出允許範圍');
    $have = kpi_as_years($db);
    if (!$from) {
        $best = null;
        foreach ($have as $y) {
            if ($y === $to) continue;
            if ($best === null || abs($y - $to) < abs($best - $to)) $best = $y;
        }
        $from = (int)$best;
    }
    if (!$from) throw new RuntimeException('找不到可以複製設定的年度');
    $st = $db->prepare("INSERT INTO kpi_as_indicator_year
        (indicator_id,year,owner_user_id,owner_dept_id,owner_position_id,owner_display,source_mode,calculator_key,
         params_json,target_direction,target_value,target_unit,target_text,is_active,Created_By)
        SELECT s.indicator_id, ?, s.owner_user_id, s.owner_dept_id, s.owner_position_id, s.owner_display,
               s.source_mode, s.calculator_key, s.params_json, s.target_direction, s.target_value,
               s.target_unit, s.target_text, s.is_active, ?
        FROM kpi_as_indicator_year s
        WHERE s.year=? AND NOT EXISTS
          (SELECT 1 FROM kpi_as_indicator_year t WHERE t.indicator_id=s.indicator_id AND t.year=?)");
    $st->execute([$to, (string)$u['user_cname'], $from, $to]);
    $n = $st->rowCount();
    kpi_as_log($db, null, $to, null, 'setting', 'year_add', $from, $to,
               "新增 {$to} 年度（設定複製自 {$from} 年，共 {$n} 項）", $u);
    return $n;
}
