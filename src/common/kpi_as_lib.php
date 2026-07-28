<?php
/**
 * AS9100 關鍵績效指標 (2-GM-04-01) 共用函式庫
 * 架構：指標主檔 + 年度版本(目標/公式/擔當者逐年獨立) + 每月快照(含手動覆寫) + 佐證附件 + 權限規則
 * - 快照鎖定：數值定案存 kpi_as_monthly_value；年度結束次月(2/1)起僅管理者可重算
 * - 附件遵守 ai-rules/07：DB 只存檔名，完整路徑讀取當下用 system_settings 設定值即時組
 * - 工作日一律用 evenement 行事曆（car_lib.php），不可用 calendar_workday（該表有誤）
 */
require_once __DIR__ . '/car_lib.php'; // car_holiday_sets / car_working_days_between

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
        [6,'廠商稽核按時執行率','供應商管理程序','實際稽核廠商數/當月應稽核廠商總數','halfyear','percent','gte',70,'%','達成率大於70％',109110201,'何沐桐/生管組','manual',null,[]],
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
        [18,'量測儀器按時校驗率','量測儀器校正管理辦法 量規儀器內校作業標準','校驗完成件數/當月應校驗件數','monthly','percent','gte',95,'%','達成率大於95％',111050101,'陳彦驊/品保課','manual',null,[]],
        [19,'人員教育訓練達成率','人力資源管理程序','有上課次數/總課程次數','monthly','percent','gte',95,'%','達成率大於95％',105030102,'林雅婷/管理課','manual',null,[]],
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
    if ($year < 2025 || $year > (int)date('Y')) return;
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
                        JOIN position_roles pr ON pr.position_id=m.position_id
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
            'desc' => '分子=當月客戶退貨單筆數(ir_track)；分母=當月出貨單筆數(is_list)',
            'params' => [
                ['key'=>'exclude_return_types','label'=>'排除退貨性質id(逗號分隔)','type'=>'intlist','fe'=>1],
            ]],
        'order_target_amount' => [
            'name' => '受訂目標達成率(同出貨分析頁)',
            'page' => '出貨分析 views/Sales/Shipping_Analysis_new.php',
            'desc' => '帳款月窗口接單金額(order_track 交期歸屬, Qty×單價, 排除狀態9/無單價)÷月受訂目標(kpi_monthly_targets/system_parameters KPI_TARGET)',
            'params' => [
                ['key'=>'monthly_targets','label'=>'各月目標金額(留空=用出貨分析頁全域目標)','type'=>'months_map','fe'=>0],
            ]],
        'shipping_target_amount' => [
            'name' => '銷貨額達成率(出貨金額/月目標)',
            'page' => '出貨管理 is_list',
            'desc' => '分子=當月出貨金額 Σ(Qty×單價)；分母=設定的各月銷貨目標金額',
            'params' => [
                ['key'=>'monthly_targets','label'=>'各月銷貨目標金額(必填才能算)','type'=>'months_map','fe'=>0],
            ]],
        'quote_to_order' => [
            'name' => '報價單接單率',
            'page' => '報價單管理 quotation_list ＋ 訂單 order_track',
            'desc' => '分母=當月報價單數；分子=其中報價單號已被訂單引用(order_track.quote_no)',
            'params' => [
                ['key'=>'exclude_draft','label'=>'排除草稿報價單(1=是)','type'=>'bool','fe'=>1],
            ]],
        'vendor_ontime' => [
            'name' => '廠商準時交貨率(發包日+約定工作天)',
            'page' => '發包管理 bom_ing(發包日/回廠日)',
            'desc' => '應交日=發包日+約定工作天數(可按製程類別分別設定)；分母=應交日落在當月的發包筆數；分子=回廠日≤應交日',
            'params' => [
                ['key'=>'default_days','label'=>'預設約定工作天數','type'=>'int','fe'=>1],
                ['key'=>'days_by_process_type','label'=>'各製程類別天數(格式 製程類別id:天數 一行一筆)','type'=>'typedays_map','fe'=>0],
            ]],
        'order_ontime' => [
            'name' => '訂單準時出貨率(準交率)',
            'page' => '訂單管理 order_track/order_list',
            'desc' => '分母=當月交期訂單筆數；分子=非未交(order_list Qty=Open_Qty 且進行中=未交)；沿用原KPI頁排除規則(d_id ZZZ、-jg/-jh/-hg)',
            'params' => [
                ['key'=>'exclude_clients','label'=>'排除客戶(逗號分隔)','type'=>'textlist','fe'=>1],
            ]],
        'stock_accuracy' => [
            'name' => '庫存正確率(盤點差異)',
            'page' => '庫存盤點 stock_count_sessions/details',
            'desc' => '每季：分母=該季已完成盤點明細筆數；分子=無差異筆數(正確率)',
            'params' => []],
        'drawing_ontime' => [
            'name' => '出圖準時率(業務→設計→生管)',
            'page' => '訂單追蹤 order_track(ateGet/pmGet)',
            'desc' => '接單移轉設計到設計移轉生管 ≤N 工作日(evenement行事曆)為準時',
            'params' => [
                ['key'=>'threshold_days','label'=>'準時門檻(工作日,含起訖日)','type'=>'int','fe'=>1],
                ['key'=>'exclude_clients','label'=>'排除客戶(逗號分隔)','type'=>'textlist','fe'=>1],
                ['key'=>'designer_ids','label'=>'設計者user.id(逗號分隔)','type'=>'textlist','fe'=>0],
            ]],
        'capacity_rate' => [
            'name' => '產能績效(完成數/機器工時)',
            'page' => '現場報工 pm_process_daily_report',
            'desc' => '分子=Σ本日完成數量；分母=Σ生產起訖工時(小時)；機台範圍=機台種類或指定機台',
            'params' => [
                ['key'=>'machine_type_ids','label'=>'機台種類(製程類別id,逗號分隔)','type'=>'machine_type_ids','fe'=>0],
                ['key'=>'machine_ids','label'=>'指定機台id(逗號分隔,可留空)','type'=>'machine_ids','fe'=>0],
            ]],
        'process_ng_rate' => [
            'name' => '製程不良率(報工NG/完成數)',
            'page' => '現場報工NG pm_process_daily_ng',
            'desc' => '分子=Σ當月NG數；分母=Σ當月完成數；限指定製程類別(如齒研=12)',
            'params' => [
                ['key'=>'process_type_ids','label'=>'製程類別id(逗號分隔)','type'=>'process_type_ids','fe'=>0],
            ]],
        'incoming_ng_rate' => [
            'name' => '進料檢驗不良率(發包回廠QC)',
            'page' => '發包回廠檢驗 bom_ing(QC_check)',
            'desc' => '分母=當月QC檢驗筆數；分子=判定為不良的筆數(預設ng=驗退)',
            'params' => [
                ['key'=>'ng_statuses','label'=>'算不良的判定(ng/QQ/AOD 逗號分隔)','type'=>'statuslist','fe'=>1],
            ]],
        'packing_ng_rate' => [
            'name' => '成品出貨不良率(出貨檢驗)',
            'page' => '出貨檢驗 qc_packing_inspection',
            'desc' => '分子=ΣNG總數；分母=Σ實際全檢數量',
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

/* ============================================================
 * 計算模組（回傳 ['num'=>分子,'den'=>分母,'value'=>值] 或 null=無法計算）
 * ============================================================ */
function kpi_as_compute(PDO $db, string $key, int $year, int $month, array $params): ?array {
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
            $st = $db->prepare("SELECT COALESCE(SUM(Qty*unit_price),0) FROM order_track
                                WHERE Delivery_date BETWEEN ? AND ?
                                  AND (Order_status IS NULL OR Order_status<>9)
                                  AND unit_price IS NOT NULL AND unit_price>0");
            $st->execute([$ws, $we]);
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
            $st = $db->prepare("SELECT COALESCE(SUM(Qty*Unit_price),0) FROM is_list WHERE DATE_FORMAT(Order_date,'%Y-%m')=?");
            $st->execute([$ym]);
            $amount = (float)$st->fetchColumn();
            $tmap = kpi_as_pv($params, 'monthly_targets', []);
            $target = is_array($tmap) && isset($tmap[(string)$month]) ? (float)$tmap[(string)$month]
                    : (is_array($tmap) && isset($tmap[$month]) ? (float)$tmap[$month] : 0.0);
            return ['num'=>$amount, 'den'=>$target, 'value'=>$target > 0 ? $amount / $target * 100 : null];
        }

        case 'quote_to_order': {
            $cond = "DATE_FORMAT(q.quote_date,'%Y-%m')=?";
            if ((int)kpi_as_pv($params, 'exclude_draft', 1) === 1) $cond .= " AND q.is_draft=0";
            $st = $db->prepare("SELECT COUNT(*) FROM quotation_list q WHERE $cond");
            $st->execute([$ym]);
            $den = (int)$st->fetchColumn();
            $st = $db->prepare("SELECT COUNT(*) FROM quotation_list q WHERE $cond
                                AND EXISTS (SELECT 1 FROM order_track ot WHERE ot.quote_no=q.quote_no)");
            $st->execute([$ym]);
            $num = (int)$st->fetchColumn();
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'vendor_ontime': {
            $defDays = max(0, (int)kpi_as_pv($params, 'default_days', 7));
            $dmapRaw = kpi_as_pv($params, 'days_by_process_type', []);
            $dmap = [];
            if (is_array($dmapRaw)) {
                foreach ($dmapRaw as $k => $v) { if (is_numeric($v)) $dmap[(int)$k] = (int)$v; }
            } else {
                foreach (kpi_as_list($dmapRaw) as $line) {
                    if (preg_match('/^(\d+)\s*[:：]\s*(\d+)$/u', $line, $m2)) $dmap[(int)$m2[1]] = (int)$m2[2];
                }
            }
            $winStart = date('Y-m-d', strtotime($ms . ' -120 days'));
            $st = $db->prepare("SELECT bi.outsource_date, bi.return_date, pn.process_type_id
                                FROM bom_ing bi
                                LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                                WHERE bi.outsource_date IS NOT NULL
                                  AND bi.maker_id_no IS NOT NULL AND bi.maker_id_no<>''
                                  AND DATE(bi.outsource_date) BETWEEN ? AND ?");
            $st->execute([$winStart, $me]);
            $num = 0; $den = 0;
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $days = isset($dmap[(int)$r['process_type_id']]) ? $dmap[(int)$r['process_type_id']] : $defDays;
                $due = kpi_as_add_workdays($db, (string)$r['outsource_date'], $days);
                if ($due < $ms || $due > $me) continue;   // 應交日不在當月
                $den++;
                if (!empty($r['return_date']) && substr((string)$r['return_date'], 0, 10) <= $due) $num++;
            }
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'order_ontime': {
            $excl = kpi_as_list(kpi_as_pv($params, 'exclude_clients', ['寶嘉誠','泳建']));
            $notIn = $excl ? (" AND Client_name NOT IN (" . implode(',', array_fill(0, count($excl), '?')) . ")") : '';
            $base = " UPPER(d_id)<>'ZZZ' AND LOWER(d_id) NOT REGEXP '-(jg|jh|hg)$' AND DATE_FORMAT(Delivery_date,'%Y-%m')=?" . $notIn;
            $st = $db->prepare("SELECT COUNT(*) FROM order_track WHERE" . $base);
            $st->execute(array_merge([$ym], $excl));
            $den = (int)$st->fetchColumn();
            $st = $db->prepare("SELECT COUNT(*) FROM order_list WHERE" . $base . " AND Qty=Open_Qty AND Order_status IS NULL");
            $st->execute(array_merge([$ym], $excl));
            $undone = (int)$st->fetchColumn();
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
            $excl = kpi_as_list(kpi_as_pv($params, 'exclude_clients', []));
            $sql = "SELECT ateGet, pmGet FROM order_track
                    WHERE ate IN (" . implode(',', array_fill(0, count($designers), '?')) . ")
                      AND DATE_FORMAT(ateGet,'%Y-%m')=?";
            $bind = array_merge($designers, [$ym]);
            if ($excl) {
                $sql .= " AND Client_name NOT IN (" . implode(',', array_fill(0, count($excl), '?')) . ")";
                $bind = array_merge($bind, $excl);
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
            $st = $db->prepare("SELECT r.produced_qty, r.production_start_time, r.production_end_time
                                FROM pm_process_daily_report r
                                JOIN machine_list ml ON ml.machine_id=r.machine_id
                                WHERE r.report_date BETWEEN ? AND ? AND (" . $cond . ")");
            $st->execute($bind);
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
            $st = $db->prepare("SELECT COALESCE(SUM(r.produced_qty),0) FROM pm_process_daily_report r
                                JOIN process_no pn ON pn.ProcessNo=r.process_no
                                WHERE pn.process_type_id IN ($in) AND r.report_date BETWEEN ? AND ?");
            $st->execute(array_merge($types, [$ms, $me]));
            $den = (float)$st->fetchColumn();
            $st = $db->prepare("SELECT COALESCE(SUM(g.ng_qty),0) FROM pm_process_daily_ng g
                                JOIN pm_process_daily_report r ON r.report_id=g.report_id
                                JOIN process_no pn ON pn.ProcessNo=r.process_no
                                WHERE pn.process_type_id IN ($in) AND r.report_date BETWEEN ? AND ?");
            $st->execute(array_merge($types, [$ms, $me]));
            $num = (float)$st->fetchColumn();
            return ['num'=>$num, 'den'=>$den, 'value'=>$den > 0 ? $num / $den * 100 : null];
        }

        case 'incoming_ng_rate': {
            $ngs = kpi_as_list(kpi_as_pv($params, 'ng_statuses', ['ng']));
            if (!$ngs) $ngs = ['ng'];
            $st = $db->prepare("SELECT COUNT(*) FROM bom_ing
                                WHERE QC_check_date IS NOT NULL AND DATE_FORMAT(QC_check_date,'%Y-%m')=?
                                  AND QC_check IS NOT NULL AND QC_check<>''");
            $st->execute([$ym]);
            $den = (int)$st->fetchColumn();
            $st = $db->prepare("SELECT COUNT(*) FROM bom_ing
                                WHERE QC_check_date IS NOT NULL AND DATE_FORMAT(QC_check_date,'%Y-%m')=?
                                  AND QC_check IN (" . implode(',', array_fill(0, count($ngs), '?')) . ")");
            $st->execute(array_merge([$ym], $ngs));
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
    }
    return null;
}

/* ============================================================
 * 快照結算 / 矩陣組裝
 * ============================================================ */
/** 計算並寫入某格快照（僅 auto 模式）。回傳計算結果或 null */
function kpi_as_settle(PDO $db, array $iy, int $year, int $month, array $u): ?array {
    if ($iy['source_mode'] !== 'auto' || empty($iy['calculator_key'])) return null;
    $res = kpi_as_compute($db, $iy['calculator_key'], $year, $month, kpi_as_params($iy['params_json']));
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

/** 指標資料來源說明（給明細彈窗）：['label','page','desc'] */
function kpi_as_source_info(PDO $db, string $mode, ?string $calc, array $params): array {
    if ($mode === 'manual') return ['label'=>'手動填寫', 'page'=>'由擔當者每期填報', 'desc'=>''];
    if ($calc === '__builder__') return ['label'=>'自訂公式', 'page'=>'資料來源目錄', 'desc'=>kpi_as_builder_summary($db, $params)];
    $reg = kpi_as_registry();
    if ($calc && isset($reg[$calc])) return ['label'=>$reg[$calc]['name'], 'page'=>$reg[$calc]['page'], 'desc'=>$reg[$calc]['desc']];
    return ['label'=>'—', 'page'=>'', 'desc'=>''];
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
