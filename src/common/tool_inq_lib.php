<?php
/**
 * 刀具詢價（tool_inq）—— 併入「詢價單」頁（views/pm/inquiry_sheet.php）的「刀具詢價」頁內分頁（2026-11-18）
 *
 * 業務專屬格式：工件規格（外/內齒、模數、壓力角、齒數、螺旋角、齒頂徑…）＋刀具規格（鉋齒刀／滾刀二擇一）
 * ＋特殊修整要求。一張詢價可同時問多家廠商，廠商回覆後回填單價、報價單／刀圖附件、下訂日、交期並設定交期提醒。
 *
 * 設計重點（皆使用者 2026-11-18 逐項交辦）：
 *   - 客戶名稱**不存**：綁料號（d_setting.d_id）後一律即時由 d_setting.Customer_Id → customer_list 帶出（鐵律4）；
 *     沒綁料號才存手填的 customer_text。
 *   - 工件規格的計算欄（齒頂徑／齒底徑／全齒深／跨齒厚·跨齒數／跨銷值·跨銷徑／齒面寬）各自有狀態
 *     auto（跟著算）／confirmed（已確認）／manual（人工修改）／standard（標準）；計算在前端，後端只驗型別與範圍。
 *   - 精度／材質／鍍層選項由管理員維護：**存選項 id**，已被詢價單選用的選項不可刪除、但可改名。
 *   - 牙口數、槽數的上限（需 >0 且 <上限）由管理員設定。
 *   - 附件走鐵律5 的 temp/active 暫存：新增詢價時就能上傳；每份附件必須選類別（詢價附件／報價單／刀圖／其他）。
 *   - 交期提醒：交期前 N 天、以及逾期各發一次站內通知＋Web Push＋Telegram，對象＝建單人＋其單位主管（順路觸發）。
 * 權限沿用 inquiry_lib 的 inq_perms()（不另開角色）。
 */
if (!defined('EG_TOOL_INQ_LIB')) {
define('EG_TOOL_INQ_LIB', 1);

require_once __DIR__ . '/inquiry_lib.php';
require_once __DIR__ . '/attach_lib.php';
require_once __DIR__ . '/unit_supervisor_lib.php';

/** 下拉選項種類（管理員可維護） */
function ti_opt_kinds(): array { return ['accuracy' => '精度', 'material' => '材質', 'coating' => '鍍層']; }
/** 六格齒型（鉋齒刀、滾刀共用，擇一） */
function ti_profiles(): array {
    return ['full' => '標準齒型 Full depth tooth profile', 'protuberance' => '瘤頭齒型 Protuberance',
            'semi_topping' => '倒角齒型 Semi-topping', 'pre_shaving' => '放刮齒型 Pre-shaving',
            'topping' => '修頂 Topping', 'pre_grinding' => '放磨齒型 Pre-grinding'];
}
function ti_att_cats(): array { return ['inquiry' => '詢價附件', 'quote' => '廠商報價單', 'drawing' => '刀圖', 'other' => '其他']; }
function ti_currencies(): array { return ['NTD', 'USD', 'CNY', 'JPY', 'EUR']; }
/** 工件規格的計算欄 */
function ti_calc_keys(): array { return ['da', 'df', 'depth', 'wk', 'pin', 'fw']; }

function ti_attach_dir(PDO $db): string { return eg_attach_dir($db, 'tool_inq_nas_dir', '刀具詢價'); }

/** DB 時間（PHP date() 是 UTC、MySQL 是本地，混用會差 8 小時） */
function ti_now(PDO $db): array {
    $r = $db->query("SELECT NOW() n, CURDATE() d")->fetch(PDO::FETCH_ASSOC);
    return ['n' => $r['n'], 'd' => $r['d']];
}

/* ═══════════════ 資料表（DDL 會隱式 commit：先確認不存在、且不在交易中才下） ═══════════════ */
function ti_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done || $db->inTransaction()) return;
    $exists = function (string $t) use ($db): bool {
        $st = $db->prepare("SHOW TABLES LIKE ?"); $st->execute([$t]);
        return $st->fetchColumn() !== false;
    };
    if (!$exists('tool_inq')) {
        $db->exec("CREATE TABLE tool_inq (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inq_no VARCHAR(20) NOT NULL, inq_date DATE NOT NULL,
            part_id INT NULL COMMENT '料號主檔 d_setting.d_id；客戶名稱由它即時帶出、不存',
            part_no_text VARCHAR(100) NULL, customer_text VARCHAR(100) NULL COMMENT '沒綁料號時才用的手填客戶',
            qty INT NULL COMMENT '需求支數',
            gear_io VARCHAR(3) NULL COMMENT 'ext 外齒／int 內齒',
            spec_json MEDIUMTEXT NULL COMMENT '工件規格（含各計算欄狀態）',
            tool_type VARCHAR(10) NULL COMMENT 'shaper 鉋齒刀／hob 滾刀',
            tool_json TEXT NULL, special_json TEXT NULL,
            accuracy_id INT NULL, material_id INT NULL, coating_id INT NULL,
            remark TEXT NULL, is_closed TINYINT NOT NULL DEFAULT 0,
            created_by INT NULL, created_by_name VARCHAR(60) NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uk_no (inq_no), KEY idx_part (part_id), KEY idx_date (inq_date),
            KEY idx_acc (accuracy_id), KEY idx_mat (material_id), KEY idx_coat (coating_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='刀具詢價單'");
    }
    if (!$exists('tool_inq_vendor')) {
        $db->exec("CREATE TABLE tool_inq_vendor (
            vid INT AUTO_INCREMENT PRIMARY KEY, inq_id INT NOT NULL,
            vendor_id_no VARCHAR(40) NOT NULL, vendor_name VARCHAR(100) NULL,
            contact_person VARCHAR(60) NULL, contact_phone VARCHAR(40) NULL,
            reply_date DATE NULL, currency VARCHAR(5) NULL, unit_price DECIMAL(14,4) NULL,
            lead_days INT NULL, order_date DATE NULL, due_date DATE NULL, received_date DATE NULL,
            remind_days INT NOT NULL DEFAULT 3, remind_sent_for DATE NULL, overdue_sent_for DATE NULL,
            reply_note TEXT NULL, is_void TINYINT NOT NULL DEFAULT 0, updated_at DATETIME NULL,
            UNIQUE KEY uk_iv (inq_id, vendor_id_no), KEY idx_due (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='刀具詢價—各廠商回覆／下單／交期'");
    }
    if (!$exists('tool_inq_attach')) {
        $db->exec("CREATE TABLE tool_inq_attach (
            att_id INT AUTO_INCREMENT PRIMARY KEY,
            inq_id INT NULL COMMENT '新增中尚未存檔為 NULL，靠 temp_key 認領（鐵律5 temp/active）',
            vid INT NULL COMMENT '廠商層級附件（報價單／刀圖）；NULL＝詢價單層級',
            temp_key VARCHAR(40) NULL, category VARCHAR(10) NOT NULL DEFAULT 'inquiry',
            file_name VARCHAR(200) NOT NULL COMMENT '只存檔名，完整路徑讀取當下現場組',
            orig_name VARCHAR(200) NULL, file_size INT NULL, mime VARCHAR(100) NULL, note VARCHAR(200) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'temp',
            uploaded_by INT NULL, uploaded_by_name VARCHAR(60) NULL, uploaded_at DATETIME NULL,
            KEY idx_inq (inq_id), KEY idx_vid (vid), KEY idx_temp (temp_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='刀具詢價附件'");
    }
    // 交期天數的單位（2026-11-18 追加）：work＝上班日（依行事曆假日與補班）、cal＝日曆日。舊表補欄位前先確認，免得重複 ALTER。
    if ($exists('tool_inq_vendor')) {
        $cols = $db->query("SHOW COLUMNS FROM tool_inq_vendor LIKE 'lead_unit'")->fetchAll();
        if (!$cols) $db->exec("ALTER TABLE tool_inq_vendor ADD COLUMN lead_unit VARCHAR(4) NOT NULL DEFAULT 'work' COMMENT 'work 上班日／cal 日曆日' AFTER lead_days");
    }
    // 下訂後填的「刀號」與自動建立／綁定的刀具料號（主檔 d_setting Type=H）
    if ($exists('tool_inq_vendor')) {
        foreach (['tool_no' => "VARCHAR(100) NULL COMMENT '廠商的刀號（下訂後填）'", 'tool_part_id' => "INT NULL COMMENT '建立／綁定的刀具料號 d_setting.d_id'", 'recv_qty' => "INT NULL COMMENT '到貨數量（入庫＋出貨合計不可超過）'"] as $col => $def) {
            if (!$db->query("SHOW COLUMNS FROM tool_inq_vendor LIKE '{$col}'")->fetchAll()) $db->exec("ALTER TABLE tool_inq_vendor ADD COLUMN {$col} {$def}");
        }
        if (!$db->query("SHOW INDEX FROM tool_inq_vendor WHERE Key_name='idx_vendor_toolno'")->fetchAll()) $db->exec("ALTER TABLE tool_inq_vendor ADD KEY idx_vendor_toolno (vendor_id_no, tool_no)");
    }
    if (!$exists('tool_inq_alloc')) {
        $db->exec("CREATE TABLE tool_inq_alloc (
            alloc_id INT AUTO_INCREMENT PRIMARY KEY, vid INT NOT NULL,
            kind VARCHAR(6) NOT NULL COMMENT 'stock 入庫／ship 出貨', qty INT NOT NULL,
            location_id INT NULL, stock_item_id INT NULL, txn_id INT NULL,
            order_id INT NULL, is_number VARCHAR(30) NULL, alloc_date DATE NULL,
            created_by INT NULL, created_at DATETIME NULL, KEY idx_vid (vid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='刀具詢價—到貨後的入庫／出貨分配'");
    }
    if ($exists('tool_inq_setting')) {
        $c = $db->query("SHOW COLUMNS FROM tool_inq_setting LIKE 'sval'")->fetch(PDO::FETCH_ASSOC);
        if ($c && stripos((string)$c['Type'], 'varchar') !== false) $db->exec("ALTER TABLE tool_inq_setting MODIFY sval TEXT NULL");
    }
    $seedOpt = false;
    if (!$exists('tool_inq_opt')) {
        $db->exec("CREATE TABLE tool_inq_opt (
            opt_id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(10) NOT NULL, name VARCHAR(60) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0, is_active TINYINT NOT NULL DEFAULT 1,
            UNIQUE KEY uk_kn (kind, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='刀具詢價—精度／材質／鍍層選項（管理員維護）'");
        $seedOpt = true;
    }
    if (!$exists('tool_inq_setting')) {
        $db->exec("CREATE TABLE tool_inq_setting (skey VARCHAR(40) PRIMARY KEY, sval VARCHAR(100) NULL)
                   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='刀具詢價設定'");
    }
    // 角色「刀具詢價管理員」（顯示在使用者權限設定頁的詢價單區塊）：只能改刀具詢價的設定，不能改一般詢價的設定
    $db->exec("INSERT IGNORE INTO roles (role_code, role_name, module, is_system, note) VALUES ('tool_inquiry_admin','刀具詢價管理員','inquiry',0,'可更改刀具詢價的設定（選項、牙口數槽數上限、列印審核欄），不可更改一般詢價的設定')");
    if ($seedOpt) {   // 種子取自業務現行紙本（精度手寫 AA／A／B、材質 P5、鍍層「照工件需求」），管理員可再增修
        $ins = $db->prepare("INSERT IGNORE INTO tool_inq_opt (kind,name,sort_order) VALUES (?,?,?)");
        foreach ([['accuracy','AA',1],['accuracy','A',2],['accuracy','B',3],['material','P5',1],['coating','照工件需求',1]] as $r) $ins->execute($r);
    }
    $done = true;
}

/* ═══════════════ 設定與選項 ═══════════════ */
function ti_settings(PDO $db): array {
    ti_ensure_schema($db);
    // 牙口數／槽數：需 >0 且 <上限；print_review＝列印是否顯示「審核」欄；no_prefix＝單號前綴；
    // sub_hob／sub_shaper＝自動建立刀具料號時要自動選的「工件小類」id（0＝依名稱自動找：滾刀→滾齒刀、鉋齒刀→插齒刀；料號的「規格」欄也填小類名稱）；
    // dup_mode＝strict（所有條件都相同才詢問是否綁定，否則一律新建）／always_new（一律新建）；stock_locs＝入庫可選儲位（location_id 逗號清單）
    $o = ['starts_max' => 10, 'slots_max' => 50, 'print_review' => 0, 'no_prefix' => 'TI', 'sub_hob' => 0, 'sub_shaper' => 0, 'dup_mode' => 'strict', 'stock_locs' => ''];
    try { foreach ($db->query("SELECT skey,sval FROM tool_inq_setting") as $r) if (isset($o[$r['skey']])) $o[$r['skey']] = is_int($o[$r['skey']]) ? (int)$r['sval'] : (string)$r['sval']; } catch (Throwable $e) {}
    return $o;
}
/** 儲存設定；$in 沒帶的鍵維持原值。回 [ok, 訊息] */
function ti_settings_save(PDO $db, array $in): array {
    ti_ensure_schema($db);
    $cur = ti_settings($db); $new = $cur;
    foreach (['starts_max' => '牙口數', 'slots_max' => '槽數'] as $k => $lb) {
        if (!array_key_exists($k, $in)) continue;
        $n = (int)$in[$k];
        if ($n < 2 || $n > 999) return [false, $lb . '上限請填 2～999（需大於 1，因為' . $lb . '必須 >0 且 <上限）'];
        $new[$k] = $n;
    }
    if (array_key_exists('print_review', $in)) $new['print_review'] = !empty($in['print_review']) ? 1 : 0;
    if (array_key_exists('no_prefix', $in)) {
        $p = strtoupper(trim((string)$in['no_prefix']));
        if (!preg_match('/^[A-Z0-9]{1,8}$/', $p)) return [false, '單號前綴只能是英文字母或數字，1～8 碼'];
        $new['no_prefix'] = $p;
    }
    foreach (['sub_hob' => '滾刀', 'sub_shaper' => '鉋齒刀'] as $k => $lb) {
        if (!array_key_exists($k, $in)) continue;
        $n = (int)$in[$k];
        if ($n > 0) {
            $q = $db->prepare("SELECT COUNT(*) FROM dict_workpiece_sub_type WHERE sub_type_id=? AND type_code='H' AND is_active=1"); $q->execute([$n]);
            if (!(int)$q->fetchColumn()) return [false, $lb . '對應的工件小類不存在或已停用'];
        }
        $new[$k] = max(0, $n);
    }
    if (array_key_exists('dup_mode', $in)) {
        if (!in_array($in['dup_mode'], ['strict', 'always_new'], true)) return [false, '重複判定方式不正確'];
        $new['dup_mode'] = $in['dup_mode'];
    }
    if (array_key_exists('stock_locs', $in)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$in['stock_locs'])))));
        if ($ids) {   // 只收庫存頁裡真的存在、啟用的儲位
            $ok = array_map('intval', $db->query("SELECT location_id FROM stock_locations WHERE is_active=1 AND location_id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_COLUMN));
            $ids = array_values(array_intersect($ids, $ok));
        }
        sort($ids); $new['stock_locs'] = implode(',', $ids);
    }
    $st = $db->prepare("INSERT INTO tool_inq_setting (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)");
    foreach ($new as $k => $v) $st->execute([$k, (string)$v]);
    return [true, ''];
}
/** 該選項被幾張詢價單選用（欄位固定，不吃前端輸入） */
function ti_opt_usage(PDO $db, string $kind, int $optId): int {
    $col = ['accuracy' => 'accuracy_id', 'material' => 'material_id', 'coating' => 'coating_id'][$kind] ?? '';
    if ($col === '') return 0;
    $st = $db->prepare("SELECT COUNT(*) FROM tool_inq WHERE {$col}=?"); $st->execute([$optId]);
    return (int)$st->fetchColumn();
}
function ti_opts(PDO $db, bool $withUsage = false, bool $activeOnly = false): array {
    ti_ensure_schema($db);
    $out = ['accuracy' => [], 'material' => [], 'coating' => []];
    $rows = $db->query("SELECT opt_id,kind,name,sort_order,is_active FROM tool_inq_opt " . ($activeOnly ? "WHERE is_active=1 " : "") . "ORDER BY kind,sort_order,opt_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!isset($out[$r['kind']])) continue;
        $r['opt_id'] = (int)$r['opt_id']; $r['is_active'] = (int)$r['is_active'];
        if ($withUsage) $r['used'] = ti_opt_usage($db, $r['kind'], $r['opt_id']);
        $out[$r['kind']][] = $r;
    }
    return $out;
}
function ti_opt_save(PDO $db, int $optId, string $kind, string $name, int $active, int $sort): array {
    ti_ensure_schema($db);
    if (!isset(ti_opt_kinds()[$kind])) return [false, '選項種類不正確', 0];
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_strlen($name) > 60) return [false, '名稱請填 1～60 字', 0];
    $dup = $db->prepare("SELECT opt_id FROM tool_inq_opt WHERE kind=? AND name=? AND opt_id<>?");
    $dup->execute([$kind, $name, $optId]);
    if ($dup->fetchColumn()) return [false, '「' . $name . '」已存在，不可重複', 0];
    if ($optId > 0) {
        $cur = $db->prepare("SELECT kind FROM tool_inq_opt WHERE opt_id=?"); $cur->execute([$optId]);
        $k = $cur->fetchColumn();
        if ($k === false) return [false, '查無此選項', 0];
        // 已被選用者仍可改名（所有詢價單即時跟著顯示新名稱）；種類不可改，免得把「精度」的選項搬成「材質」
        $db->prepare("UPDATE tool_inq_opt SET name=?, is_active=?, sort_order=? WHERE opt_id=?")->execute([$name, $active ? 1 : 0, $sort, $optId]);
        return [true, '', $optId];
    }
    if ($sort <= 0) { $m = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM tool_inq_opt WHERE kind=?"); $m->execute([$kind]); $sort = (int)$m->fetchColumn(); }
    $db->prepare("INSERT INTO tool_inq_opt (kind,name,sort_order,is_active) VALUES (?,?,?,?)")->execute([$kind, $name, $sort, $active ? 1 : 0]);
    return [true, '', (int)$db->lastInsertId()];
}
function ti_opt_delete(PDO $db, int $optId): array {
    $st = $db->prepare("SELECT kind,name FROM tool_inq_opt WHERE opt_id=?"); $st->execute([$optId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return [false, '查無此選項'];
    $n = ti_opt_usage($db, $r['kind'], $optId);
    if ($n > 0) return [false, '「' . $r['name'] . '」已被 ' . $n . ' 張詢價單選用，不可刪除（可改名，或取消勾選「啟用」讓新單不再出現）'];
    $db->prepare("DELETE FROM tool_inq_opt WHERE opt_id=?")->execute([$optId]);
    return [true, ''];
}

/* ═══════════════ 權限 ═══════════════ */
function ti_can_edit(array $perm, array $inq): bool {
    if (!empty($perm['canToolAdmin'])) return true;
    if ((int)($inq['created_by'] ?? 0) === (int)$perm['uid']) return true;
    return in_array('sales', $perm['myDepts'] ?? [], true);   // 業務同事可互相接手
}

/* ═══════════════ 料號／客戶／廠商查詢 ═══════════════ */
function ti_part_search(PDO $db, string $kw, int $limit = 20): array {
    $kw = trim($kw);
    if ($kw === '') return [];
    $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, c.customer, c.customer_full
                        FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                        WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ?
                        ORDER BY d.d_id DESC LIMIT " . (int)$limit);
    $like = '%' . $kw . '%';
    $st->execute([$like, $like]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['part_id' => (int)$r['d_id'], 'part_no' => $r['D_Setting_Id'], 'drawing_no' => (string)$r['Drawing_No'],
                  'customer' => (string)($r['customer'] ?? ''), 'customer_full' => (string)($r['customer_full'] ?? '')];
    }
    return $out;
}
function ti_part_customer(PDO $db, int $partId): array {
    $st = $db->prepare("SELECT d.D_Setting_Id, c.customer, c.customer_full FROM d_setting d
                        LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id WHERE d.d_id=?");
    $st->execute([$partId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['part_no' => $r['D_Setting_Id'], 'customer' => (string)($r['customer'] ?? ''), 'customer_full' => (string)($r['customer_full'] ?? '')] : [];
}
/** 料號主檔的齒輪規格（可能有多個齒型列）：供詢價單「若有資料自動帶入、仍可手動修改」 */
function ti_part_gears(PDO $db, int $partId): array {
    require_once __DIR__ . '/gear_spec_lib.php';
    $st = $db->prepare("SELECT g.gear_id, g.gear_label, g.Module, g.module_input_type, g.module_display, g.Teeth, g.Face_Width,
                               g.Helix_Direction, g.Helix_Angle, g.Pressure_Angle, g.Profile_Shift_X, t.type_name
                        FROM d_setting_gear g LEFT JOIN dict_gear_type t ON t.gear_type_id=g.Gear_Type
                        WHERE g.d_setting_id=? ORDER BY g.gear_id");
    $st->execute([$partId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mit = strtoupper((string)($r['module_input_type'] ?: 'M'));
        if (!in_array($mit, ['M', 'CP', 'DP'], true)) $mit = 'M';
        $src = (string)($r['module_display'] ?: $r['Module']);   // DP 的歷史 Module 欄位帶 M 前綴，數值以顯示值為準
        $mv = preg_match('/[0-9]+(?:\.[0-9]+)?/', $src, $m) ? (float)$m[0] : null;
        $tn = (string)($r['type_name'] ?? '');
        $pa = preg_match('/[0-9]+(?:\.[0-9]+)?/', (string)$r['Pressure_Angle'], $m2) ? (float)$m2[0] : 20.0;
        $out[] = [
            'gear_id' => (int)$r['gear_id'], 'label' => trim(($r['gear_label'] ? $r['gear_label'] . ' ' : '') . $tn . ' ' . eg_gear_module_text($r)),
            'io' => (mb_strpos($tn, '內') !== false) ? 'int' : 'ext',
            'module_type' => $mit, 'module_val' => $mv, 'pa' => $pa,
            'teeth' => $r['Teeth'] !== null ? (int)$r['Teeth'] : null,
            'helix_deg' => $r['Helix_Angle'] !== null ? round((float)$r['Helix_Angle'], 6) : null,
            'helix_dir' => in_array($r['Helix_Direction'], ['RH', 'LH'], true) ? $r['Helix_Direction'] : '',
            'face_width' => $r['Face_Width'] !== null ? round((float)$r['Face_Width'], 2) : null,
            'x' => $r['Profile_Shift_X'] !== null ? round((float)$r['Profile_Shift_X'], 6) : null,
        ];
    }
    return $out;
}
/**
 * 料號主檔的刀具規格（d_setting_tool，主檔管理 工件種類=H/OH 的「刀具規格設定」）：
 * 詢價單綁到刀具料號時自動帶入刀具欄位（仍可手動修改）。對應：hob→滾刀、shaper→鉋齒刀（主檔稱插齒刀）；
 * 牙口數 RH／LH 擇有值者；外徑-長度「80-120」拆成外徑／長度；材質／塗層是主檔的自由文字，
 * 只在與管理員設定的選項**名稱相同**時帶入，對不上的回 hint 讓畫面提示，不擅自新增選項。
 * 類型（PGS／PGSP／PS／TOP／S-TOP／HP5）只對應意思明確的三種（PS 放刮、TOP 修頂、S-TOP 倒角），其餘不猜、只當提示。
 */
function ti_part_tool(PDO $db, int $partId): ?array {
    $st = $db->prepare("SELECT * FROM d_setting_tool WHERE d_setting_id=? ORDER BY tool_id LIMIT 1");
    $st->execute([$partId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $kind = $r['tool_kind'] === 'hob' ? 'hob' : ($r['tool_kind'] === 'shaper' ? 'shaper' : '');
    $num = function ($v) { return ($v !== null && $v !== '' && is_numeric($v) && (float)$v > 0) ? round((float)$v, 4) : null; };
    $od = null; $len = null;
    if (preg_match('/^\s*([0-9.]+)\s*[-~xX×*]\s*([0-9.]+)\s*$/u', (string)$r['od_length'], $m)) { $od = (float)$m[1]; $len = (float)$m[2]; }
    $t = ['tool_type' => $kind, 'od' => $kind === 'shaper' ? $num($r['outer_dia']) : $od, 'len' => $kind === 'shaper' ? null : $len,
          'bore' => $num($r['bore_dia']), 'teeth' => $kind === 'shaper' ? ($r['teeth'] ? (int)$r['teeth'] : null) : null,
          'starts' => null, 'hand' => '', 'profile' => '',
          'tool_remark' => (string)($r['remark'] ?? '')];
    if ($kind === 'hob') {
        if ((int)$r['starts_rh'] > 0) { $t['starts'] = (int)$r['starts_rh']; $t['hand'] = 'RH'; }
        elseif ((int)$r['starts_lh'] > 0) { $t['starts'] = (int)$r['starts_lh']; $t['hand'] = 'LH'; }
    }
    $map = ['PS' => 'pre_shaving', 'TOP' => 'topping', 'S-TOP' => 'semi_topping'];
    $ty = strtoupper(trim((string)$r['tool_type']));
    if (isset($map[$ty])) $t['profile'] = $map[$ty];
    $opts = ti_opts($db, false, true);
    $hint = [];
    foreach (['material' => '材質', 'coating' => '鍍層'] as $k => $lb) {
        $val = trim((string)($r[$k] ?? ''));
        if ($val === '') continue;
        $hit = null;
        foreach ($opts[$k] as $o) if (mb_strtolower($o['name']) === mb_strtolower($val)) { $hit = $o['opt_id']; break; }
        if ($hit) $t[$k . '_id'] = $hit; else $hint[] = '主檔' . $lb . '「' . $val . '」不在選項內，請自行選擇或請管理員新增';
    }
    if ($ty !== '' && !isset($map[$ty])) $hint[] = '主檔刀具類型「' . $ty . '」請自行對照選擇齒型';
    $t['hint'] = implode('；', $hint);
    return $t;
}
/** 廠商模糊搜尋：廠商編號或名稱（含加工項目說明），排除已停用 */
function ti_vendor_search(PDO $db, string $kw, int $limit = 30): array {
    $kw = trim($kw);
    if ($kw === '') return [];
    $st = $db->prepare("SELECT maker_id_no, maker_id, contact_person, m_tel, m_tel2, m_process_items FROM maker_list
                        WHERE (status IS NULL OR status<>'X') AND (maker_id_no LIKE ? OR maker_id LIKE ? OR m_process_items LIKE ?)
                        ORDER BY (maker_id_no LIKE ?) DESC, maker_id_no LIMIT " . (int)$limit);
    $like = '%' . $kw . '%';
    $st->execute([$like, $like, $like, $kw . '%']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['vendor_id' => $r['maker_id_no'], 'vendor_name' => (string)$r['maker_id'], 'contact' => (string)($r['contact_person'] ?? ''),
                  'phone' => (string)($r['m_tel'] ?: $r['m_tel2'] ?: ''), 'items' => mb_substr((string)($r['m_process_items'] ?? ''), 0, 60)];
    }
    return $out;
}

/* ═══════════════ 驗證與正規化（前端擋一次、後端同規則再擋一次） ═══════════════ */
function ti_num($v, ?float $min, ?float $max, string $label, bool $minExcl = false): array {   // [錯誤, 值]
    if ($v === null || $v === '') return ['', null];
    if (!is_numeric($v)) return [$label . '請填數字', null];
    $n = (float)$v;
    if ($min !== null && ($minExcl ? $n <= $min : $n < $min)) return [$label . '必須' . ($minExcl ? '大於 ' : '不可小於 ') . $min, null];
    if ($max !== null && $n > $max) return [$label . '不可大於 ' . $max, null];
    return ['', round($n, 6)];
}
function ti_int($v, int $min, ?int $max, string $label, ?int $exclMax = null): array {
    if ($v === null || $v === '') return ['', null];
    if (!preg_match('/^\d+$/', (string)$v)) return [$label . '只能填正整數（不可有小數、負號）', null];
    $n = (int)$v;
    if ($n < $min) return [$label . '必須大於 0', null];
    if ($max !== null && $n > $max) return [$label . '不可大於 ' . $max, null];
    if ($exclMax !== null && $n >= $exclMax) return [$label . '必須小於 ' . $exclMax . '（管理員設定的上限）', null];
    return ['', $n];
}
/** 下訂日＋交期天數＝交期。unit=work 只算上班日（行事曆假日、週末不算，補班日算），cal 直接加日曆日 */
function ti_add_days(PDO $db, string $from, int $days, string $unit): string {
    if ($unit !== 'work') return date('Y-m-d', strtotime($from . ' +' . $days . ' day'));
    require_once __DIR__ . '/leave_lib.php';
    $cur = strtotime($from); $n = 0; $guard = 0;
    while ($n < $days && $guard++ < 4000) {
        $cur = strtotime('+1 day', $cur);
        if (eg_leave_is_workday($db, date('Y-m-d', $cur))) $n++;
    }
    return date('Y-m-d', $cur);
}
function ti_date_ok($v): bool { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)); }

/** 工件規格 → [錯誤, 正規化陣列] */
function ti_clean_spec(array $s): array {
    $o = [];
    $o['module_type'] = in_array($s['module_type'] ?? 'M', ['M', 'CP', 'DP'], true) ? $s['module_type'] : 'M';
    foreach ([['module_val', '模數', 0.0, 1000.0, true], ['pa', '壓力角', 0.0, 89.99, true], ['helix_deg', '螺旋角', 0.0, 89.9999, false], ['x', '轉位係數', -5.0, 5.0, false]] as [$k, $lb, $mn, $mx, $ex]) {
        [$e, $v] = ti_num($s[$k] ?? null, $mn, $mx, $lb, $ex); if ($e) return [$e, null]; $o[$k] = $v;
    }
    [$e, $v] = ti_int($s['teeth'] ?? null, 1, 99999, '齒數'); if ($e) return [$e, null]; $o['teeth'] = $v;
    $o['helix_mode'] = (($s['helix_mode'] ?? 'dec') === 'dms') ? 'dms' : 'dec';
    $o['helix_dir']  = in_array($s['helix_dir'] ?? '', ['RH', 'LH'], true) ? $s['helix_dir'] : '';
    $o['meas'] = in_array($s['meas'] ?? '', ['wk', 'pin'], true) ? $s['meas'] : '';   // 跨齒厚／跨銷擇一
    $labels = ['da' => '齒頂徑', 'df' => '齒底徑', 'depth' => '全齒深', 'wk' => '跨齒厚／跨齒數', 'pin' => '跨銷值／跨銷徑', 'fw' => '齒面寬'];
    foreach (ti_calc_keys() as $k) {
        $c = is_array($s[$k] ?? null) ? $s[$k] : [];
        $st = $c['st'] ?? 'auto';
        $allowed = $k === 'fw' ? ['auto', 'confirmed', 'manual'] : ['auto', 'confirmed', 'manual', 'standard'];
        if (!in_array($st, $allowed, true)) return [$labels[$k] . '的狀態不正確', null];
        $row = ['st' => $st, 'v' => null, 'v2' => null];
        if ($st !== 'standard') {
            [$e, $row['v']]  = ti_num($c['v'] ?? null, 0.0, 100000.0, $labels[$k], false);  if ($e) return [$e, null];
            [$e, $row['v2']] = ti_num($c['v2'] ?? null, 0.0, 100000.0, $labels[$k], false); if ($e) return [$e, null];
        }
        if (in_array($k, ['wk', 'pin'], true) && $o['meas'] !== '' && $o['meas'] !== $k) $row = ['st' => 'auto', 'v' => null, 'v2' => null];   // 沒選的那一種不留資料
        $o[$k] = $row;
    }
    return ['', $o];
}

/** 刀具規格 → [錯誤, 刀型, 正規化陣列, 精度id, 材質id, 鍍層id]；鉋齒刀／滾刀各自只收自己的欄位 */
function ti_clean_tool(PDO $db, string $type, array $t, array $set): array {
    $fail = function ($m) { return [$m, null, null, null, null, null]; };
    if ($type === '') return ['', null, null, null, null, null];
    if (!in_array($type, ['shaper', 'hob'], true)) return $fail('刀具種類不正確（鉋齒刀或滾刀擇一）');
    $o = [];
    $o['profile'] = (string)($t['profile'] ?? '');
    if ($o['profile'] !== '' && !isset(ti_profiles()[$o['profile']])) return $fail('齒型不在可選範圍');
    foreach ([['od', '外徑'], ['len', '長度'], ['bore', '孔徑']] as [$k, $lb]) {
        [$e, $v] = ti_num($t[$k] ?? null, 0.0, 100000.0, $lb, true); if ($e) return $fail($e); $o[$k] = $v;
    }
    if ($type === 'shaper') {
        $o['form'] = in_array($t['form'] ?? '', ['disk', 'bell', 'shank'], true) ? $t['form'] : '';
        [$e, $v] = ti_int($t['teeth'] ?? null, 1, 99999, '齒數'); if ($e) return $fail($e); $o['teeth'] = $v;
    } else {
        [$e, $v] = ti_int($t['slots'] ?? null, 1, null, '槽數', $set['slots_max']);   if ($e) return $fail($e); $o['slots'] = $v;
        $sr = ($t['starts'] ?? '') === '' ? '1' : $t['starts'];   // 牙口數未填＝1
        [$e, $v] = ti_int($sr, 1, null, '牙口數', $set['starts_max']); if ($e) return $fail($e); $o['starts'] = $v;
        $o['hand'] = in_array($t['hand'] ?? '', ['LH', 'RH'], true) ? $t['hand'] : '';   // 不選＝無
        $o['helix_text'] = mb_substr(trim((string)($t['helix_text'] ?? '')), 0, 40);
    }
    $ids = [];
    foreach (['accuracy', 'material', 'coating'] as $k) {
        $v = (int)($t[$k . '_id'] ?? 0);
        if ($v > 0) {
            $st = $db->prepare("SELECT is_active FROM tool_inq_opt WHERE opt_id=? AND kind=?"); $st->execute([$v, $k]);
            $act = $st->fetchColumn();
            if ($act === false) return $fail(ti_opt_kinds()[$k] . '的選項不存在');
            // 停用的選項：新選不給，但這張單原本就選著的要放行（呼叫端比對舊值）
        }
        $ids[$k] = $v > 0 ? $v : null;
    }
    return ['', $type, $o, $ids['accuracy'], $ids['material'], $ids['coating']];
}

/* ═══════════════ 單號 ═══════════════ */
function ti_next_no(PDO $db, string $date): string {
    $pre = ti_settings($db)['no_prefix'] . date('ymd', strtotime($date));   // 前綴（管理員可設定，預設 TI）＋YYMMDD＋流水3碼
    $st = $db->prepare("SELECT MAX(inq_no) FROM tool_inq WHERE inq_no LIKE ?"); $st->execute([$pre . '%']);
    $last = (string)$st->fetchColumn();
    $n = $last !== '' ? ((int)substr($last, strlen($pre))) + 1 : 1;
    return $pre . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

/* ═══════════════ 讀取 ═══════════════ */
function ti_vendor_stage(array $v): string {
    if ((int)$v['is_void']) return 'void';
    if (!empty($v['received_date'])) return 'received';
    if (!empty($v['order_date'])) return 'ordered';
    if (!empty($v['reply_date']) || ($v['unit_price'] !== null && $v['unit_price'] !== '')) return 'replied';
    return 'waiting';
}
function ti_overall(array $inq, array $vendors): string {
    if ((int)$inq['is_closed']) return 'closed';
    $live = array_values(array_filter($vendors, function ($v) { return $v['stage'] !== 'void'; }));
    if (!$vendors) return 'none';
    if (!$live) return 'void';
    $rank = ['waiting' => 1, 'replied' => 2, 'ordered' => 3, 'received' => 4];
    $best = 'waiting';
    foreach ($live as $v) if ($rank[$v['stage']] > $rank[$best]) $best = $v['stage'];
    return $best;
}
function ti_attaches(PDO $db, int $inqId, string $tempKey = ''): array {
    if ($inqId > 0) { $st = $db->prepare("SELECT * FROM tool_inq_attach WHERE inq_id=? AND status='active' ORDER BY att_id"); $st->execute([$inqId]); }
    elseif ($tempKey !== '') { $st = $db->prepare("SELECT * FROM tool_inq_attach WHERE temp_key=? AND inq_id IS NULL ORDER BY att_id"); $st->execute([$tempKey]); }
    else return [];
    return array_map(function ($a) {
        return ['att_id' => (int)$a['att_id'], 'vid' => $a['vid'] !== null ? (int)$a['vid'] : null, 'category' => $a['category'],
                'name' => (string)($a['orig_name'] ?: $a['file_name']), 'note' => (string)($a['note'] ?? ''), 'size' => (int)$a['file_size'],
                'by' => (string)$a['uploaded_by_name'], 'at' => (string)$a['uploaded_at'], 'uid' => (int)$a['uploaded_by']];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}
/** 一張詢價單的完整內容（含即時帶出的客戶、廠商明細、選項名稱、附件） */
function ti_row(PDO $db, array $r, bool $full = true): array {
    $id = (int)$r['id'];
    $cust = ['customer' => (string)($r['customer_live'] ?? ''), 'customer_full' => (string)($r['customer_full_live'] ?? '')];
    $o = [
        'id' => $id, 'inq_no' => $r['inq_no'], 'inq_date' => $r['inq_date'], 'part_id' => $r['part_id'] !== null ? (int)$r['part_id'] : null,
        'part_no' => (string)($r['part_no_live'] ?: $r['part_no_text']), 'part_no_text' => (string)$r['part_no_text'],
        'customer' => $r['part_id'] ? $cust['customer'] : (string)$r['customer_text'], 'customer_full' => $r['part_id'] ? $cust['customer_full'] : '',
        'customer_text' => (string)$r['customer_text'], 'qty' => $r['qty'] !== null ? (int)$r['qty'] : null,
        'gear_io' => (string)$r['gear_io'], 'tool_type' => (string)$r['tool_type'], 'remark' => (string)$r['remark'],
        'is_closed' => (int)$r['is_closed'], 'created_by' => (int)$r['created_by'], 'created_by_name' => (string)$r['created_by_name'],
        'accuracy_id' => $r['accuracy_id'] !== null ? (int)$r['accuracy_id'] : null,
        'material_id' => $r['material_id'] !== null ? (int)$r['material_id'] : null,
        'coating_id'  => $r['coating_id']  !== null ? (int)$r['coating_id']  : null,
        'spec' => json_decode((string)$r['spec_json'], true) ?: new stdClass(),
        'tool' => json_decode((string)$r['tool_json'], true) ?: new stdClass(),
        'special' => json_decode((string)$r['special_json'], true) ?: new stdClass(),
    ];
    $names = [];
    foreach ($db->query("SELECT opt_id,name FROM tool_inq_opt") as $x) $names[(int)$x['opt_id']] = $x['name'];
    foreach (['accuracy', 'material', 'coating'] as $k) $o[$k . '_name'] = $o[$k . '_id'] ? ($names[$o[$k . '_id']] ?? '') : '';
    $vs = $db->prepare("SELECT * FROM tool_inq_vendor WHERE inq_id=? ORDER BY vid"); $vs->execute([$id]);
    $o['vendors'] = array_map(function ($v) {
        $v['vid'] = (int)$v['vid']; $v['is_void'] = (int)$v['is_void']; $v['remind_days'] = (int)$v['remind_days'];
        $v['lead_days'] = $v['lead_days'] !== null ? (int)$v['lead_days'] : null; $v['lead_unit'] = ($v['lead_unit'] ?? 'work') === 'cal' ? 'cal' : 'work'; $v['tool_part_id'] = !empty($v['tool_part_id']) ? (int)$v['tool_part_id'] : null; $v['recv_qty'] = $v['recv_qty'] !== null ? (int)$v['recv_qty'] : null; $v['tool_no'] = (string)($v['tool_no'] ?? '');
        $v['unit_price'] = $v['unit_price'] !== null ? (float)$v['unit_price'] : null;
        $v['stage'] = ti_vendor_stage($v);
        return $v;
    }, $vs->fetchAll(PDO::FETCH_ASSOC));
    $o['status'] = ti_overall($r, $o['vendors']);
    static $today = null; if ($today === null) $today = ti_now($db)['d'];
    $o['overdue'] = [];
    if (!(int)$r['is_closed']) foreach ($o['vendors'] as $v) {
        if (in_array($v['stage'], ['void', 'received'], true) || empty($v['due_date']) || $v['due_date'] >= $today) continue;
        $o['overdue'][] = ['vendor' => $v['vendor_name'], 'due' => $v['due_date'], 'days' => (int)floor((strtotime($today) - strtotime($v['due_date'])) / 86400)];
    }
    $ac = $db->prepare("SELECT category, vid, COUNT(*) c FROM tool_inq_attach WHERE inq_id=? AND status='active' GROUP BY category, vid"); $ac->execute([$id]);
    $o['att_count'] = array_sum(array_map(function ($x) { return (int)$x['c']; }, $ac->fetchAll(PDO::FETCH_ASSOC)));
    if ($full) {
        $o['attaches'] = ti_attaches($db, $id);
        foreach ($o['vendors'] as &$vv) $vv['alloc'] = ti_alloc_summary($db, $vv['vid'], $vv['recv_qty']);
        unset($vv);
    } else {   // 清單卡片上標「到貨尚未處理 N」「待出貨 N」，一眼看得到做完還沒出貨的
        $pq = $db->prepare("SELECT v.recv_qty, COALESCE(SUM(a.qty),0) done, COALESCE(SUM(CASE WHEN a.kind='hold' THEN a.qty END),0) hold
                            FROM tool_inq_vendor v LEFT JOIN tool_inq_alloc a ON a.vid=v.vid
                            WHERE v.inq_id=? AND v.received_date IS NOT NULL AND v.is_void=0 GROUP BY v.vid, v.recv_qty");
        $pq->execute([$id]);
        $pend = 0; $hold = 0;
        foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $x) { $pend += max(0, (int)$x['recv_qty'] - (int)$x['done']); $hold += (int)$x['hold']; }
        $o['pending_qty'] = $pend; $o['hold_qty'] = $hold;
    }
    return $o;
}
function ti_select_sql(): string { return "SELECT i.*, d.D_Setting_Id part_no_live, c.customer customer_live, c.customer_full customer_full_live
                   FROM tool_inq i LEFT JOIN d_setting d ON d.d_id=i.part_id LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id"; }
function ti_get(PDO $db, int $id): ?array {
    $st = $db->prepare(ti_select_sql() . " WHERE i.id=?"); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ti_row($db, $r, true) : null;
}
function ti_years(PDO $db): array {
    return array_map('intval', $db->query("SELECT DISTINCT YEAR(inq_date) y FROM tool_inq ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN) ?: []);
}
function ti_list(PDO $db, array $o): array {
    $w = []; $p = [];
    if (!empty($o['year']))   { $w[] = 'YEAR(i.inq_date)=?'; $p[] = (int)$o['year']; }
    $vx = function ($cond) { return "EXISTS (SELECT 1 FROM tool_inq_vendor v WHERE v.inq_id=i.id AND v.is_void=0 AND {$cond})"; };
    $recv = $vx('v.received_date IS NOT NULL'); $ord = $vx('v.order_date IS NOT NULL');
    $rep = $vx('(v.reply_date IS NOT NULL OR v.unit_price IS NOT NULL)'); $any = $vx('1=1');
    switch ($o['status'] ?? '') {
        case 'open':     $w[] = 'i.is_closed=0'; break;
        case 'closed':   $w[] = 'i.is_closed=1'; break;
        case 'received': $w[] = "i.is_closed=0 AND $recv"; break;                                   // 已到貨
        case 'pending':  $w[] = "i.is_closed=0 AND EXISTS (SELECT 1 FROM tool_inq_vendor v WHERE v.inq_id=i.id AND v.is_void=0 AND v.received_date IS NOT NULL
                                  AND COALESCE(v.recv_qty,0) > COALESCE((SELECT SUM(a.qty) FROM tool_inq_alloc a WHERE a.vid=v.vid AND a.kind<>'hold'),0))"; break;   // 到貨後還沒入庫／出貨（含預留待出貨）
        case 'ordered':  $w[] = "i.is_closed=0 AND NOT $recv AND $ord"; break;                      // 尚未到貨（已下訂）
        case 'replied':  $w[] = "i.is_closed=0 AND NOT $recv AND NOT $ord AND $rep"; break;         // 尚未下訂（已報價）
        case 'waiting':  $w[] = "i.is_closed=0 AND $any AND NOT $recv AND NOT $ord AND NOT $rep"; break;   // 等待報價
    }
    if (in_array($o['tool_type'] ?? '', ['shaper', 'hob'], true)) { $w[] = 'i.tool_type=?'; $p[] = $o['tool_type']; }
    // 客戶／料號／廠商／其他 四種篩選各自獨立（互為 AND）；每一欄內多個關鍵字也要全部命中
    $words = function ($v) { return array_values(array_filter(preg_split('/\s+/u', trim((string)$v)), 'strlen')); };
    foreach ($words($o['customer'] ?? '') as $k) {
        $like = '%' . $k . '%';
        $w[] = "(c.customer LIKE ? OR c.customer_full LIKE ? OR i.customer_text LIKE ?)"; array_push($p, $like, $like, $like);
    }
    foreach ($words($o['part'] ?? '') as $k) {
        $like = '%' . $k . '%';
        $w[] = "(i.part_no_text LIKE ? OR d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ?)"; array_push($p, $like, $like, $like);
    }
    foreach ($words($o['vendor'] ?? '') as $k) {
        $like = '%' . $k . '%';
        $w[] = "EXISTS (SELECT 1 FROM tool_inq_vendor v WHERE v.inq_id=i.id AND (v.vendor_name LIKE ? OR v.vendor_id_no LIKE ?))"; array_push($p, $like, $like);
    }
    foreach ($words($o['kw'] ?? '') as $k) {   // 其他：單號、建單人、備註
        $like = '%' . $k . '%';
        $w[] = "(i.inq_no LIKE ? OR i.created_by_name LIKE ? OR i.remark LIKE ?)"; array_push($p, $like, $like, $like);
    }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $cnt = $db->prepare("SELECT COUNT(*) FROM tool_inq i LEFT JOIN d_setting d ON d.d_id=i.part_id LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id" . $where);
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();
    $psize = in_array((int)($o['psize'] ?? 10), [5, 10, 20, 50], true) ? (int)$o['psize'] : 10;
    $pages = max(1, (int)ceil($total / $psize));
    $page = min(max(1, (int)($o['page'] ?? 1)), $pages);
    $overdueSql = "EXISTS (SELECT 1 FROM tool_inq_vendor v WHERE v.inq_id=i.id AND v.is_void=0 AND v.received_date IS NULL AND v.due_date IS NOT NULL AND v.due_date<CURDATE())";
    $st = $db->prepare(ti_select_sql() . $where . " ORDER BY (i.is_closed=0 AND $overdueSql) DESC, i.inq_date DESC, i.id DESC LIMIT " . $psize . " OFFSET " . (($page - 1) * $psize));
    $st->execute($p);
    $rows = array_map(function ($r) use ($db) { return ti_row($db, $r, false); }, $st->fetchAll(PDO::FETCH_ASSOC));
    // 逾期總數不受篩選影響（清單上方提示列用）；到貨、結案或不採用的廠商自動不再計入＝自動取消置頂
    $odn = (int)$db->query("SELECT COUNT(*) FROM tool_inq i WHERE i.is_closed=0 AND $overdueSql")->fetchColumn();
    return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total, 'overdue_total' => $odn];
}

/* ═══════════════ 寫入 ═══════════════ */
/** 新增／編輯詢價單本體＋廠商名單＋附件認領。回 [ok, 訊息, id] */
function ti_save(PDO $db, array $P, array $perm, int $uid, string $uname): array {
    ti_ensure_schema($db);
    $id = (int)($P['id'] ?? 0);
    $set = ti_settings($db);
    $old = null;
    if ($id > 0) {
        $st = $db->prepare("SELECT * FROM tool_inq WHERE id=?"); $st->execute([$id]);
        $old = $st->fetch(PDO::FETCH_ASSOC);
        if (!$old) return [false, '查無此詢價單', 0];
        if (!ti_can_edit($perm, $old)) return [false, '您沒有編輯這張詢價單的權限', 0];
    } elseif (empty($perm['canCreate']) && empty($perm['canToolAdmin'])) return [false, '您沒有新增詢價單的權限', 0];

    $partId = (int)($P['part_id'] ?? 0);
    $partText = mb_substr(trim((string)($P['part_no_text'] ?? '')), 0, 100);
    if ($partId > 0) {
        $pc = ti_part_customer($db, $partId);
        if (!$pc) return [false, '綁定的料號不存在，請重新選擇', 0];
        $partText = $pc['part_no'];
    }
    if ($partId <= 0) return [false, '料號尚未綁定：請從主檔清單挑選料號後再儲存', 0];
    $custText = $partId > 0 ? '' : mb_substr(trim((string)($P['customer_text'] ?? '')), 0, 100);
    [$e, $qty] = ti_int($P['qty'] ?? null, 1, 99999999, '需求支數'); if ($e) return [false, $e, 0];

    $io = in_array($P['gear_io'] ?? '', ['ext', 'int'], true) ? $P['gear_io'] : '';
    [$e, $spec] = ti_clean_spec(is_array($P['spec'] ?? null) ? $P['spec'] : []); if ($e) return [false, $e, 0];
    [$e, $type, $tool, $acc, $mat, $coat] = ti_clean_tool($db, (string)($P['tool_type'] ?? ''), is_array($P['tool'] ?? null) ? $P['tool'] : [], $set);
    if ($e) return [false, $e, 0];
    if ($old) {   // 停用的選項：這張單原本選著的繼續放行；新選停用的擋下
        foreach (['accuracy' => $acc, 'material' => $mat, 'coating' => $coat] as $k => $v) {
            if ($v && (int)$old[$k . '_id'] !== (int)$v) {
                $a = $db->prepare("SELECT is_active FROM tool_inq_opt WHERE opt_id=?"); $a->execute([$v]);
                if (!(int)$a->fetchColumn()) return [false, ti_opt_kinds()[$k] . '的這個選項已停用，請改選其他項目', 0];
            }
        }
    } else {
        foreach (['accuracy' => $acc, 'material' => $mat, 'coating' => $coat] as $k => $v) {
            if ($v) { $a = $db->prepare("SELECT is_active FROM tool_inq_opt WHERE opt_id=?"); $a->execute([$v]); if (!(int)$a->fetchColumn()) return [false, ti_opt_kinds()[$k] . '的這個選項已停用，請改選其他項目', 0]; }
        }
    }
    $sp = is_array($P['special'] ?? null) ? $P['special'] : [];
    $special = ['chamfer' => mb_substr(trim((string)($sp['chamfer'] ?? '')), 0, 60), 'stock' => mb_substr(trim((string)($sp['stock'] ?? '')), 0, 60),
                'tool_remark' => mb_substr(trim((string)($sp['tool_remark'] ?? '')), 0, 1000), 'work_remark' => mb_substr(trim((string)($sp['work_remark'] ?? '')), 0, 1000)];
    $remark = mb_substr(trim((string)($P['remark'] ?? '')), 0, 2000);

    $vendors = [];
    foreach ((array)($P['vendors'] ?? []) as $vv) { $vv = trim((string)$vv); if ($vv !== '' && !in_array($vv, $vendors, true)) $vendors[] = $vv; }
    if (count($vendors) > 30) return [false, '一張詢價單最多 30 家廠商', 0];
    $snap = [];
    foreach ($vendors as $vn) { $s = inq_vendor_snapshot($db, $vn); if (!$s) return [false, '廠商 ' . $vn . ' 不存在，請重新挑選', 0]; $snap[$vn] = $s; }

    $now = ti_now($db);
    $tk = mb_substr((string)($P['temp_key'] ?? ''), 0, 40);
    $db->beginTransaction();
    try {
        if ($old) {
            $db->prepare("UPDATE tool_inq SET part_id=?, part_no_text=?, customer_text=?, qty=?, gear_io=?, spec_json=?, tool_type=?, tool_json=?, special_json=?,
                          accuracy_id=?, material_id=?, coating_id=?, remark=?, updated_at=? WHERE id=?")
               ->execute([$partId ?: null, $partText, $custText ?: null, $qty, $io ?: null, json_encode($spec, JSON_UNESCAPED_UNICODE), $type, $tool ? json_encode($tool, JSON_UNESCAPED_UNICODE) : null,
                          json_encode($special, JSON_UNESCAPED_UNICODE), $acc, $mat, $coat, $remark, $now['n'], $id]);
        } else {
            $no = ti_next_no($db, $now['d']);
            $db->prepare("INSERT INTO tool_inq (inq_no, inq_date, part_id, part_no_text, customer_text, qty, gear_io, spec_json, tool_type, tool_json, special_json,
                          accuracy_id, material_id, coating_id, remark, created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$no, $now['d'], $partId ?: null, $partText, $custText ?: null, $qty, $io ?: null, json_encode($spec, JSON_UNESCAPED_UNICODE), $type, $tool ? json_encode($tool, JSON_UNESCAPED_UNICODE) : null,
                          json_encode($special, JSON_UNESCAPED_UNICODE), $acc, $mat, $coat, $remark, $uid, $uname, $now['n'], $now['n']]);
            $id = (int)$db->lastInsertId();
        }
        // 廠商名單同步：新增的建列；移除的若已有回覆／下單／附件資料就擋下（要改用「不再使用」），沒資料才真的刪
        $cur = $db->prepare("SELECT vid, vendor_id_no, vendor_name, reply_date, unit_price, order_date FROM tool_inq_vendor WHERE inq_id=?"); $cur->execute([$id]);
        $have = [];
        foreach ($cur->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['vendor_id_no']] = $r;
        foreach ($have as $vn => $r) {
            if (in_array($vn, $vendors, true)) continue;
            $att = $db->prepare("SELECT COUNT(*) FROM tool_inq_attach WHERE vid=?"); $att->execute([(int)$r['vid']]);
            if ($r['reply_date'] || $r['unit_price'] !== null || $r['order_date'] || (int)$att->fetchColumn() > 0) {
                throw new RuntimeException('廠商「' . $r['vendor_name'] . '」已有回覆／下單／附件資料，不能直接移除；請在「廠商回覆」視窗勾選「不再使用」');
            }
            $db->prepare("DELETE FROM tool_inq_vendor WHERE vid=?")->execute([(int)$r['vid']]);
        }
        $ins = $db->prepare("INSERT INTO tool_inq_vendor (inq_id, vendor_id_no, vendor_name, contact_person, contact_phone, currency, updated_at) VALUES (?,?,?,?,?,'NTD',?)");
        foreach ($vendors as $vn) {
            if (isset($have[$vn])) continue;
            $s = $snap[$vn];
            $ins->execute([$id, $vn, $s['vendor_name'], $s['contact_person'], $s['contact_phone'], $now['n']]);
        }
        if ($tk !== '') {   // 附件暫存轉正（鐵律5）
            $db->prepare("UPDATE tool_inq_attach SET inq_id=?, status='active', temp_key=NULL WHERE temp_key=? AND inq_id IS NULL")->execute([$id, $tk]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return [false, $e instanceof RuntimeException ? $e->getMessage() : ('儲存失敗：' . $e->getMessage()), 0];
    }
    return [true, '', $id];
}

/** 廠商回覆／下單／交期（單一廠商列） */
function ti_vendor_save(PDO $db, int $vid, array $P, array $perm): array {
    $st = $db->prepare("SELECT v.*, i.inq_date, i.created_by FROM tool_inq_vendor v JOIN tool_inq i ON i.id=v.inq_id WHERE v.vid=?"); $st->execute([$vid]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) return [false, '查無此廠商列'];
    if (!ti_can_edit($perm, $v)) return [false, '您沒有編輯這張詢價單的權限'];
    $d = function ($k) use ($P) { $x = trim((string)($P[$k] ?? '')); return $x === '' ? null : $x; };
    foreach (['reply_date' => '廠商回覆日', 'order_date' => '下訂日', 'due_date' => '交期', 'received_date' => '到貨日'] as $k => $lb) {
        if ($d($k) !== null && !ti_date_ok($d($k))) return [false, $lb . '日期格式不正確'];
    }
    $reply = $d('reply_date'); $order = $d('order_date'); $due = $d('due_date'); $recv = $d('received_date');
    if ($reply && $reply < $v['inq_date']) return [false, '廠商回覆日不可早於詢價日（' . $v['inq_date'] . '）'];
    if ($order && $order < $v['inq_date']) return [false, '下訂日不可早於詢價日（' . $v['inq_date'] . '）'];
    if ($order && $reply && $order < $reply) return [false, '下訂日不可早於廠商回覆日'];
    if ($due && $order && $due < $order) return [false, '交期不可早於下訂日'];
    if ($recv && !$order) return [false, '請先填下訂日再填到貨日'];
    if ($recv && $order && $recv < $order) return [false, '到貨日不可早於下訂日'];
    // 到貨數量：按「已到貨」時一併記錄（預設＝詢價單的需求支數，沒有就 1）；已有入庫／出貨紀錄時，不可取消到貨、也不可把數量改得比已處理的還少
    $al = ti_alloc_summary($db, $vid, (int)$v['recv_qty']);
    $recvQty = null;
    if ($recv) {
        $rqRaw = trim((string)($P['recv_qty'] ?? ''));
        if ($rqRaw === '') { $iq = $db->prepare("SELECT qty FROM tool_inq WHERE id=?"); $iq->execute([(int)$v['inq_id']]); $rqRaw = (string)((int)$v['recv_qty'] ?: ((int)$iq->fetchColumn() ?: 1)); }
        [$e, $recvQty] = ti_int($rqRaw, 1, 99999999, '到貨數量'); if ($e) return [false, $e];
        if ($recvQty < $al['stock'] + $al['ship'] + $al['hold']) return [false, '到貨數量不可少於已入庫／已出貨／已預留的合計（' . ($al['stock'] + $al['ship'] + $al['hold']) . '）'];
    } elseif ($al['stock'] + $al['ship'] + $al['hold'] > 0) return [false, '已有入庫／出貨／預留紀錄，不能取消到貨'];
    [$e, $price] = ti_num($P['unit_price'] ?? null, 0.0, 99999999.0, '單價'); if ($e) return [false, $e];
    $leadRaw = trim((string)($P['lead_days'] ?? ''));
    [$e, $lead] = ti_int($leadRaw === '' ? null : $leadRaw, 0, 9999, '交期天數'); if ($e) return [false, $e];
    $remRaw = trim((string)($P['remind_days'] ?? '3')); if ($remRaw === '') $remRaw = '3';
    [$e, $rem] = ti_int($remRaw, 0, 60, '提醒天數'); if ($e) return [false, $e];
    $cur = in_array($P['currency'] ?? '', ti_currencies(), true) ? $P['currency'] : 'NTD';
    $unit = (($P['lead_unit'] ?? 'work') === 'cal') ? 'cal' : 'work';
    if (!$due && $order && $lead !== null) $due = ti_add_days($db, $order, $lead, $unit);   // 交期未填：下訂日＋交期天數
    $note = mb_substr(trim((string)($P['reply_note'] ?? '')), 0, 1000);
    $void = !empty($P['is_void']) ? 1 : 0;
    $now = ti_now($db);
    // 交期或提醒天數改了就重新計算提醒（清掉已發記錄）
    $reset = ($due !== $v['due_date'] || (int)$rem !== (int)$v['remind_days']);
    $db->prepare("UPDATE tool_inq_vendor SET reply_date=?, currency=?, unit_price=?, lead_days=?, lead_unit=?, order_date=?, due_date=?, received_date=?, recv_qty=?, remind_days=?, reply_note=?, is_void=?, updated_at=?"
                 . ($reset ? ", remind_sent_for=NULL, overdue_sent_for=NULL" : "") . " WHERE vid=?")
       ->execute([$reply, $cur, $price, $lead, $unit, $order, $due, $recv, $recvQty, $rem ?? 3, $note, $void, $now['n'], $vid]);
    return [true, ''];
}

/* ═══════════════ 下訂後填刀號 → 自動建立刀具料號（主檔 d_setting Type=H ＋ d_setting_tool） ═══════════════
 * 重複判定（使用者特別交代「相同供應商相同刀號」）有兩個層面，都先擋、由使用者決定，絕不默默建出第二個一樣的料號：
 *   ① 同一家廠商、同一個刀號，已經在別張刀具詢價單綁定過料號（＝同一支刀的重複採購／廠商沿用刀號）
 *   ② 主檔裡已經有同編號的刀具料號（Type=H／OH）
 * 命中時回傳 matches，讓畫面讓使用者選「綁定到既有料號」或「刀號加後綴另建新料號」或取消。
 */
function ti_own_customer_id(PDO $db): string {
    $v = $db->query("SELECT customer_id FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
    return $v ? (string)$v : '';
}
function ti_tool_no_clean(string $v): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, 100); }
/** 主檔刀具料號的規格摘要（給使用者判斷是不是同一支刀） */
function ti_tool_part_summary(PDO $db, int $dId): string {
    $st = $db->prepare("SELECT * FROM d_setting_tool WHERE d_setting_id=? ORDER BY tool_id LIMIT 1"); $st->execute([$dId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return '（主檔尚無刀具規格）';
    $a = [];
    $a[] = $t['tool_kind'] === 'hob' ? '滾刀' : ($t['tool_kind'] === 'shaper' ? '鉋齒刀' : '');
    foreach (['module_display' => '', 'pressure_angle' => '壓力角', 'od_length' => '外徑-長度', 'outer_dia' => '外徑', 'bore_dia' => '內徑', 'teeth' => '齒數', 'tool_type' => '類型', 'material' => '', 'coating' => ''] as $k => $lb) {
        if ($t[$k] === null || $t[$k] === '') continue;
        $val = (string)$t[$k]; if (is_numeric($val) && strpos($val, '.') !== false) $val = rtrim(rtrim($val, '0'), '.');   // 只對小數去尾零（M20 不能被去掉 0）
        $a[] = $lb . $val;
    }
    if ($t['starts_rh']) $a[] = '牙口RH' . $t['starts_rh'];
    if ($t['starts_lh']) $a[] = '牙口LH' . $t['starts_lh'];
    return implode('　', array_filter($a, 'strlen'));
}
function ti_norm($v): string {   // 比較用：空值＝''、數字去尾零、文字不分大小寫
    if ($v === null) return '';
    $s = trim((string)$v);
    if ($s === '') return '';
    if (is_numeric($s)) return rtrim(rtrim(number_format((float)$s, 4, '.', ''), '0'), '.');
    return mb_strtolower($s);
}
/** 這支主檔刀具和「這次詢價的刀具」不一樣的地方（空陣列＝所有條件都相同） */
function ti_tool_diffs(PDO $db, int $dId, array $row, string $vendorIdNo, string $toolNo): array {
    $d = [];
    $n = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?"); $n->execute([$dId]);
    if (ti_norm($n->fetchColumn()) !== ti_norm($toolNo)) $d[] = '刀號';
    $st = $db->prepare("SELECT * FROM d_setting_tool WHERE d_setting_id=? ORDER BY tool_id LIMIT 1"); $st->execute([$dId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { $d[] = '主檔尚無刀具規格'; return $d; }
    foreach (['tool_kind' => '刀種', 'module_display' => '模數', 'pressure_angle' => '壓力角', 'starts_rh' => '牙口數RH', 'starts_lh' => '牙口數LH', 'od_length' => '外徑-長度', 'd_plus_f' => 'D+F',
              'bore_dia' => '內徑', 'tool_type' => '類型', 'teeth' => '齒數', 'outer_dia' => '外徑', 'material' => '材質', 'coating' => '塗層'] as $k => $lb) {
        if (ti_norm($t[$k] ?? null) !== ti_norm($row[$k] ?? null)) $d[] = $lb;
    }
    // 廠商：這支料號如果已有刀具詢價紀錄，必須包含這家廠商才算相同
    $v = $db->prepare("SELECT DISTINCT vendor_id_no FROM tool_inq_vendor WHERE tool_part_id=?"); $v->execute([$dId]);
    $vs = $v->fetchAll(PDO::FETCH_COLUMN);
    if ($vs && !in_array($vendorIdNo, $vs, true)) $d[] = '廠商';
    return $d;
}
/**
 * 同刀號的候選料號（同廠商同刀號在別張詢價單綁定過的、主檔同編號的），每一個都比對「所有條件」：
 * 刀號、刀種、模數、壓力角、牙口數、外徑-長度、D+F、內徑、類型、齒數、外徑、材質、塗層、廠商。全部相同才是 identical。
 */
function ti_tool_part_matches(PDO $db, int $vid, string $vendorIdNo, string $toolNo, array $row): array {
    $ids = [];
    $q = $db->prepare("SELECT v.tool_part_id, i.inq_no, i.inq_date FROM tool_inq_vendor v JOIN tool_inq i ON i.id=v.inq_id
                       WHERE v.vendor_id_no=? AND v.tool_no=? AND v.vid<>? AND v.tool_part_id IS NOT NULL");
    $q->execute([$vendorIdNo, $toolNo, $vid]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $ids[(int)$r['tool_part_id']]['same_vendor'] = $r['inq_no'] . '（' . $r['inq_date'] . '）';
    $p = $db->prepare("SELECT d_id FROM d_setting WHERE Type IN ('H','OH') AND D_Setting_Id=?"); $p->execute([$toolNo]);
    foreach ($p->fetchAll(PDO::FETCH_COLUMN) as $d) $ids[(int)$d]['same_no'] = true;
    $rows = [];
    foreach ($ids as $dId => $flags) {
        $r = $db->prepare("SELECT d.D_Setting_Id, d.Spec_No, d.Revision, c.customer FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id WHERE d.d_id=?");
        $r->execute([$dId]); $d = $r->fetch(PDO::FETCH_ASSOC);
        if (!$d) continue;
        $diffs = ti_tool_diffs($db, $dId, $row, $vendorIdNo, $toolNo);
        $rows[] = ['d_id' => $dId, 'part_no' => $d['D_Setting_Id'], 'spec_no' => (string)$d['Spec_No'], 'revision' => (string)$d['Revision'], 'customer' => (string)($d['customer'] ?? ''),
                   'summary' => ti_tool_part_summary($db, $dId), 'same_vendor_inq' => $flags['same_vendor'] ?? '', 'same_no' => !empty($flags['same_no']),
                   'identical' => !$diffs, 'diffs' => $diffs];
    }
    return $rows;
}
/** 主檔標籤：自動建立「模數」「滾齒刀規格／插齒刀規格」「材質(刀具)」（寫法與主檔管理手動貼標籤相同：item_label_map＋item_sub_label_map）。回傳建立的標籤數；失敗只記 log，不影響料號。 */
function ti_tool_labels_create(PDO $db, int $dId, array $r, int $uid): int {
    $n = 0;
    try {
        $uname = (string)$db->query("SELECT user_cname FROM `user` WHERE id=" . (int)$uid)->fetchColumn();
        $lid = function (string $name) use ($db) { $q = $db->prepare("SELECT label_id FROM dict_label WHERE label_name=? LIMIT 1"); $q->execute([$name]); return (int)$q->fetchColumn(); };
        $sid = function (int $label, string $sub) use ($db) { $q = $db->prepare("SELECT sub_id FROM dict_label_sub WHERE label_id=? AND sub_name=? AND is_active=1 LIMIT 1"); $q->execute([$label, $sub]); return (int)$q->fetchColumn(); };
        $opt = function (int $sub, string $val) use ($db) { $q = $db->prepare("SELECT COUNT(*) FROM dict_label_sub_option WHERE sub_id=? AND option_value=? AND is_active=1"); $q->execute([$sub, $val]); return (int)$q->fetchColumn() > 0; };
        $addL = $db->prepare("INSERT INTO item_label_map (d_id,label_id,created_by,created_by_id,created_at) VALUES (?,?,?,?,NOW())");
        $addS = $db->prepare("INSERT INTO item_sub_label_map (parent_map_id,sub_id,input_value,created_by,created_by_id,created_at) VALUES (?,?,?,?,?,NOW())");
        $fmt = function ($v) { return is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') : (string)$v; };
        $make = function (int $label, array $subs) use ($db, $dId, $uname, $uid, $addL, $addS, $sid, &$n) {   // $subs: [子標籤名 => 值（null＝只貼標籤不帶值）]
            $items = [];
            foreach ($subs as $name => $val) { $s = $sid($label, (string)$name); if ($s) $items[] = [$s, $val]; }
            if (!$items) return;
            $addL->execute([$dId, $label, $uname, $uid]); $map = (int)$db->lastInsertId();
            foreach ($items as [$s, $val]) $addS->execute([$map, $s, $val === null ? null : (string)$val, $uname, $uid]);
            $n++;
        };
        $db->beginTransaction();
        if ($r['module_input_type'] && $r['module_value'] !== null && ($l = $lid('模數'))) $make($l, [(string)$r['module_input_type'] => $fmt($r['module_value'])]);
        $spec = $r['tool_kind'] === 'hob' ? '滾齒刀規格' : '插齒刀規格';
        if ($l = $lid($spec)) {
            $subs = [];
            if ($r['pressure_angle'] !== null) $subs['壓力角'] = $r['pressure_angle'];
            if ($r['tool_kind'] === 'hob') {
                if ($r['starts_rh']) $subs['牙口數-RH'] = $r['starts_rh'];
                if ($r['starts_lh']) $subs['牙口數-LH'] = $r['starts_lh'];
                if ($r['od_length']) $subs['外徑-長度'] = $r['od_length'];
                if ($r['d_plus_f'] !== null) $subs['D+F'] = $fmt($r['d_plus_f']);
                if ($r['bore_dia'] !== null) $subs['內徑'] = $fmt($r['bore_dia']);
            } else {
                if ($r['teeth']) $subs['齒數'] = $r['teeth'];
                if ($r['outer_dia'] !== null) $subs['⌀'] = $fmt($r['outer_dia']);
            }
            if ($r['tool_type'] && ($ts = $sid($l, '類型')) && $opt($ts, (string)$r['tool_type'])) $subs['類型'] = $r['tool_type'];   // 類型是下拉選項，主檔沒有這個選項就不貼
            if ($subs) $make($l, $subs);
        }
        if ($r['material'] && ($l = $lid('材質(刀具)')) && $sid($l, (string)$r['material'])) $make($l, [(string)$r['material'] => null]);   // 材質是主檔既有的選項才貼
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[tool_inq] labels failed: ' . $e->getMessage());
        return 0;
    }
    return $n;
}
/** 詢價單的刀具規格 → d_setting_tool 欄位 */
function ti_tool_row_from_inq(PDO $db, array $inq): array {
    $sp = $inq['spec'] ?: []; $tl = $inq['tool'] ?: []; $kind = $inq['tool_type'];
    $num = function ($v) { return ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v; };
    $trim = function ($v) { return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); };
    $mType = in_array($sp['module_type'] ?? '', ['M', 'CP', 'DP'], true) ? $sp['module_type'] : null; $mVal = $num($sp['module_val'] ?? null);
    $row = ['tool_kind' => $kind, 'module_input_type' => $mVal !== null ? $mType : null, 'module_value' => $mVal,
            'module_display' => ($mVal !== null && $mType) ? $mType . $trim($mVal) : null, 'pressure_angle' => isset($sp['pa']) && $sp['pa'] !== null ? $trim($sp['pa']) : null,
            'starts_rh' => null, 'starts_lh' => null, 'od_length' => null, 'd_plus_f' => null, 'bore_dia' => $num($tl['bore'] ?? null),
            'tool_type' => ['pre_shaving' => 'PS', 'topping' => 'TOP', 'semi_topping' => 'S-TOP'][$tl['profile'] ?? ''] ?? null,
            'teeth' => null, 'outer_dia' => null, 'material' => $inq['material_name'] ?: null, 'coating' => $inq['coating_name'] ?: null, 'remark' => ($inq['special']['tool_remark'] ?? '') ?: null];
    $dp = $sp['depth'] ?? null; if (is_array($dp) && ($dp['st'] ?? '') !== 'standard' && $num($dp['v'] ?? null) !== null) $row['d_plus_f'] = $num($dp['v']);
    if ($kind === 'hob') {
        $s = (int)($tl['starts'] ?? 0) ?: 1;
        if (($tl['hand'] ?? '') === 'LH') $row['starts_lh'] = $s; else $row['starts_rh'] = $s;
        if ($num($tl['od'] ?? null) !== null && $num($tl['len'] ?? null) !== null) $row['od_length'] = $trim($tl['od']) . '-' . $trim($tl['len']);
    } else {
        $row['teeth'] = isset($tl['teeth']) ? (int)$tl['teeth'] : null; $row['outer_dia'] = $num($tl['od'] ?? null);
    }
    return $row;
}
/**
 * mode: create＝建立／suffix＝刀號加後綴另建（使用者在重複視窗確認「確定是不同的刀」）／bind＝綁定到既有料號 $dId／unbind＝解除綁定（不刪料號）
 * 「相同刀具」的定義（使用者 2026-11-18 定案）：刀號與所有規格條件（刀種、模數、壓力角、牙口數、外徑-長度、D+F、內徑、類型、齒數、外徑、材質、塗層、廠商）全部相同才算；
 * 只要有一項不同就視為不同的刀、一律建立新料號（刀號被占用時自動加後綴 -2、-3…），不會把資料併進不相干的料號。
 * 管理員可把重複判定改成「一律新建」（dup_mode=always_new）：連完全相同也不詢問，每次都建立新料號。
 * 回 [ok, message, extra]
 */
function ti_tool_part_apply(PDO $db, int $vid, string $toolNo, string $mode, int $dId, int $uid, array $perm): array {
    ti_ensure_schema($db);
    $st = $db->prepare("SELECT v.*, i.created_by FROM tool_inq_vendor v JOIN tool_inq i ON i.id=v.inq_id WHERE v.vid=?"); $st->execute([$vid]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) return [false, '查無此廠商列', []];
    if (!ti_can_edit($perm, $v)) return [false, '您沒有編輯這張詢價單的權限', []];
    if ($mode === 'unbind') {
        $db->prepare("UPDATE tool_inq_vendor SET tool_part_id=NULL WHERE vid=?")->execute([$vid]);
        return [true, '已解除綁定（主檔裡的料號不會被刪除）', []];
    }
    if (empty($v['order_date'])) return [false, '請先在「② 下訂」填好下訂日並儲存，才能填刀號與建立料號', []];
    if (!empty($v['tool_part_id'])) return [false, '這家廠商已經綁定料號了；如要更換請先解除綁定', []];
    $toolNo = ti_tool_no_clean($toolNo);
    if ($toolNo === '') return [false, '請填刀號', []];
    $inq = ti_get($db, (int)$v['inq_id']);
    if (!in_array($inq['tool_type'], ['hob', 'shaper'], true)) return [false, '請先在詢價單選擇刀具種類（鉋齒刀或滾刀），才能建立或綁定刀具料號', []];
    $set = ti_settings($db);
    $r = ti_tool_row_from_inq($db, $inq);
    $cands = ti_tool_part_matches($db, $vid, (string)$v['vendor_id_no'], $toolNo, $r);
    $ident = array_values(array_filter($cands, function ($c) { return $c['identical']; }));
    $collide = false; foreach ($cands as $c) if ($c['same_no']) $collide = true;

    if ($mode === 'bind') {   // 只能綁定「所有條件都相同」的料號
        $ok = array_filter($ident, function ($c) use ($dId) { return (int)$c['d_id'] === $dId; });
        if (!$ok) return [false, '這支料號與本次詢價的刀具有條件不同，不能視為同一支刀（請改用新建）', []];
        $db->prepare("UPDATE tool_inq_vendor SET tool_no=?, tool_part_id=? WHERE vid=?")->execute([$toolNo, $dId, $vid]);
        return [true, '已綁定到既有料號', ['d_id' => $dId]];
    }
    if ($mode === 'create' && $ident && $set['dup_mode'] !== 'always_new') {
        return [false, '有一支料號的刀號與所有條件都跟這次相同，請確認是不是同一支刀', ['conflict' => true, 'matches' => $ident, 'others' => count($cands) - count($ident)]];
    }
    $own = ti_own_customer_id($db);
    if ($own === '') return [false, '客戶主檔找不到「本公司」，無法建立刀具料號', []];
    $partNo = $toolNo; $note = '';
    if ($mode === 'suffix' || $collide) {   // 刀號加後綴：A-2、A-3…（主檔該編號未被使用的第一個）
        $chk = $db->prepare("SELECT COUNT(*) FROM d_setting WHERE Type IN ('H','OH') AND D_Setting_Id=?");
        for ($n = 2; $n < 1000; $n++) { $chk->execute([$toolNo . '-' . $n]); if (!(int)$chk->fetchColumn()) { $partNo = $toolNo . '-' . $n; break; } }
        if ($partNo === $toolNo) return [false, '找不到可用的後綴，請改刀號', []];
        if ($mode !== 'suffix') $note = '（刀號「' . $toolNo . '」已被規格不同的料號使用，已自動加後綴）';
    }
    [$subId, $subName] = ti_sub_type_for($db, $inq['tool_type'], $set);   // 工件小類：設定對應或依名稱自動找；料號「規格」欄填小類名稱
    $specNo = $subName !== '' ? $subName : ($inq['tool_type'] === 'hob' ? '滾齒刀' : '插齒刀');
    $db->beginTransaction();
    try {
        $dup = $db->prepare("SELECT COUNT(*) FROM d_setting WHERE Type IN ('H','OH') AND D_Setting_Id=? FOR UPDATE"); $dup->execute([$partNo]);
        if ((int)$dup->fetchColumn() > 0) throw new RuntimeException('主檔剛剛已有同編號的刀具料號，請重新操作');
        $db->prepare("INSERT INTO d_setting (D_Setting_Id, Spec_No, Revision, Type, Is_Assembly, Customer_Id, workpiece_sub_type_id, Remark, Created_By, Created_At) VALUES (?,?,?,'H',0,?,?,?,?,NOW())")
           ->execute([$partNo, $specNo, '', $own, $subId ?: null, '由刀具詢價 ' . $inq['inq_no'] . ' 建立（廠商 ' . $v['vendor_name'] . '　刀號 ' . $toolNo . '）', (string)$uid]);
        $newId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO d_setting_tool (d_setting_id, tool_kind, module_input_type, module_value, module_display, pressure_angle, starts_rh, starts_lh, od_length, d_plus_f, bore_dia, has_six_spline, tool_type, teeth, outer_dia, shaper_tag, material, coating, remark, Created_By, Created_At, Modified_By, Modified_At)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,NULL,?,?,?,?,NOW(),?,NOW())")
           ->execute([$newId, $r['tool_kind'], $r['module_input_type'], $r['module_value'], $r['module_display'], $r['pressure_angle'], $r['starts_rh'], $r['starts_lh'], $r['od_length'], $r['d_plus_f'],
                      $r['bore_dia'], $r['tool_type'], $r['teeth'], $r['outer_dia'], $r['material'], $r['coating'], $r['remark'], (string)$uid, (string)$uid]);
        $db->prepare("UPDATE tool_inq_vendor SET tool_no=?, tool_part_id=? WHERE vid=?")->execute([$toolNo, $newId, $vid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return [false, $e instanceof RuntimeException ? $e->getMessage() : ('建立料號失敗：' . $e->getMessage()), []];
    }
    $labels = ti_tool_labels_create($db, $newId, $r, $uid);   // 主檔標籤（模數、滾齒刀／插齒刀規格、材質）
    return [true, '已建立刀具料號「' . $partNo . '」並綁定' . ($labels ? '，並自動貼上 ' . $labels . ' 組標籤與刀具規格' : '，並建立刀具規格') . $note, ['d_id' => $newId, 'part_no' => $partNo]];
}

/* ═══════════════ 到貨後：入庫（選儲位與數量）／出貨（快速出貨，綁訂單） ═══════════════
 * 一批到貨可以「部分出貨、部分入庫」：到貨數量＝recv_qty，每次入庫或出貨記一筆 tool_inq_alloc，合計不可超過到貨數量。
 * 入庫儲位只能選刀具詢價管理員在設定裡勾選的（stock_locations）；入庫寫法與庫存頁一致（stock_items item_type=20 刀具＋stock_transactions 'in'）。
 * 出貨直接呼叫快速出貨的 sq_create_shipment()（一律要綁定訂單），不另寫一套出貨規則。
 */
function ti_stock_ids(PDO $db): array {
    $s = ti_settings($db)['stock_locs'];
    return array_values(array_filter(array_map('intval', explode(',', $s))));
}
/** 儲位清單。$ids＝只取這些（null＝全部啟用的）；每列帶區域名稱與「是否為刀具類別可用儲位」 */
function ti_loc_rows(PDO $db, ?array $ids = null): array {
    if ($ids !== null && !$ids) return [];
    $w = 'l.is_active=1'; if ($ids !== null) $w .= ' AND l.location_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    $rows = $db->query("SELECT l.location_id, l.location_code, l.location_name, COALESCE(a.area_name,'') area_name,
                               EXISTS (SELECT 1 FROM stock_category_locations c WHERE c.location_id=l.location_id AND c.category_id=20) is_tool
                        FROM stock_locations l LEFT JOIN stock_areas a ON a.area_id=l.area
                        WHERE $w ORDER BY a.sort_order, l.sort_order, l.location_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['location_id'] = (int)$r['location_id']; $r['is_tool'] = (int)$r['is_tool']; $r['label'] = trim(($r['area_name'] !== '' ? '[' . $r['area_name'] . '] ' : '') . $r['location_code'] . ($r['location_name'] ? '　' . $r['location_name'] : '')); }
    return $rows;
}
function ti_alloc_summary(PDO $db, int $vid, ?int $recvQty): array {
    $st = $db->prepare("SELECT a.*, COALESCE(l.location_code,'') loc_code, COALESCE(o.Order_oo,'') order_no
                        FROM tool_inq_alloc a LEFT JOIN stock_locations l ON l.location_id=a.location_id LEFT JOIN order_track o ON o.Order_id=a.order_id
                        WHERE a.vid=? ORDER BY a.alloc_id");
    $st->execute([$vid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC); $sum = ['stock' => 0, 'ship' => 0, 'hold' => 0];
    foreach ($rows as &$r) { $r['qty'] = (int)$r['qty']; $r['alloc_id'] = (int)$r['alloc_id']; $sum[$r['kind']] = ($sum[$r['kind']] ?? 0) + $r['qty']; }
    unset($r);
    // hold＝預留待出貨（已預留給某張訂單、尚未開出貨單）：算「已處理」，但畫面另外標出來、可轉成出貨或取消
    return ['recv' => (int)$recvQty, 'stock' => $sum['stock'], 'ship' => $sum['ship'], 'hold' => $sum['hold'],
            'remain' => max(0, (int)$recvQty - $sum['stock'] - $sum['ship'] - $sum['hold']), 'rows' => $rows];
}
/** 共同前置檢查：回 [錯誤, 廠商列, 詢價單, 剩餘可分配數量] */
function ti_alloc_pre(PDO $db, int $vid, int $qty, string $date, array $perm): array {
    $st = $db->prepare("SELECT v.*, i.created_by FROM tool_inq_vendor v JOIN tool_inq i ON i.id=v.inq_id WHERE v.vid=?"); $st->execute([$vid]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) return ['查無此廠商列'];
    if (!ti_can_edit($perm, $v)) return ['您沒有編輯這張詢價單的權限'];
    if (empty($v['received_date'])) return ['請先按「已到貨」'];
    if (empty($v['tool_part_id'])) return ['請先填刀號並建立／綁定刀具料號，才能入庫或出貨'];
    if ($qty < 1) return ['數量必須是大於 0 的整數'];
    if (!ti_date_ok($date)) return ['日期格式不正確'];
    if ($date > ti_now($db)['d']) return ['日期不可晚於今天'];
    $sum = ti_alloc_summary($db, $vid, (int)$v['recv_qty']);
    if ($qty > $sum['remain']) return ['數量超過尚未處理的到貨數量（剩 ' . $sum['remain'] . '）'];
    return ['', $v, ti_get($db, (int)$v['inq_id']), $sum['remain']];
}
function ti_alloc_stock(PDO $db, int $vid, int $locId, int $qty, string $date, int $uid, array $perm): array {
    $p = ti_alloc_pre($db, $vid, $qty, $date, $perm);
    if ($p[0] !== '') return [false, $p[0], []];
    [, $v, $inq] = $p;
    if (!in_array($locId, ti_stock_ids($db), true)) return [false, '這個儲位不在刀具詢價管理員設定的可選儲位內', []];
    $part = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE d_id=?"); $part->execute([(int)$v['tool_part_id']]);
    $pr = $part->fetch(PDO::FETCH_ASSOC);
    if (!$pr) return [false, '綁定的刀具料號已不存在', []];
    if (mb_strlen((string)$pr['D_Setting_Id']) > 30) return [false, '料號超過 30 字，庫存頁無法登錄，請先縮短刀號', []];
    $own = ti_own_customer_id($db);
    $db->beginTransaction();
    try {
        $l = $db->prepare("SELECT location_code FROM stock_locations WHERE location_id=? AND is_active=1"); $l->execute([$locId]);
        $code = $l->fetchColumn();
        if ($code === false) throw new RuntimeException('入庫儲位不存在或已停用');
        $f = $db->prepare("SELECT stock_item_id, qty FROM stock_items WHERE item_type=20 AND is_active=1 AND location_id=? AND d_setting_id=? LIMIT 1 FOR UPDATE");
        $f->execute([$locId, (int)$pr['d_id']]);
        $it = $f->fetch(PDO::FETCH_ASSOC);
        if ($it) {
            $before = (int)$it['qty']; $itemId = (int)$it['stock_item_id'];
            $db->prepare("UPDATE stock_items SET qty=qty+?, Modified_By=?, Modified_At=NOW() WHERE stock_item_id=?")->execute([$qty, $uid, $itemId]);
        } else {
            $before = 0;
            $db->prepare("INSERT INTO stock_items (d_id,d_setting_id,item_type,storage_location,location_id,qty,client_name,client_id,stock_date,unit_id,vendor_id,vendor_name,remark1,Created_By)
                          VALUES (?,?,20,?,?,?,?,?,?,1,?,?,?,?)")
               ->execute([$pr['D_Setting_Id'], (int)$pr['d_id'], $code, $locId, $qty, $own !== '' ? '超正' : null, $own ?: null, $date, mb_substr((string)$v['vendor_id_no'], 0, 11), mb_substr((string)$v['vendor_name'], 0, 60), mb_substr('刀具詢價 ' . $inq['inq_no'], 0, 200), $uid]);
            $itemId = (int)$db->lastInsertId();
        }
        $db->prepare("INSERT INTO stock_transactions (stock_item_id,d_id,txn_type,txn_qty,qty_before,qty_after,location_to,location_to_id,bom_ref,txn_date,remark,Created_By) VALUES (?,?,'in',?,?,?,?,?,NULL,?,?,?)")
           ->execute([$itemId, $pr['D_Setting_Id'], $qty, $before, $before + $qty, $code, $locId, $date, mb_substr('刀具詢價入庫 ' . $inq['inq_no'] . '（' . $v['vendor_name'] . '）', 0, 300), $uid]);
        $txn = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO tool_inq_alloc (vid, kind, qty, location_id, stock_item_id, txn_id, alloc_date, created_by, created_at) VALUES (?, 'stock', ?, ?, ?, ?, ?, ?, NOW())")
           ->execute([$vid, $qty, $locId, $itemId, $txn, $date, $uid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return [false, $e instanceof RuntimeException ? $e->getMessage() : ('入庫失敗：' . $e->getMessage()), []];
    }
    return [true, '已入庫 ' . $qty . ' 個到「' . $code . '」', []];
}
/** 這家廠商的刀具料號可以出貨的訂單（以該料號為品項、尚未出完的）；有關鍵字時另外搜尋所有未出完訂單 */
function ti_ship_orders(PDO $db, int $vid, string $kw = ''): array {
    $v = $db->prepare("SELECT tool_part_id FROM tool_inq_vendor WHERE vid=?"); $v->execute([$vid]);
    $part = (int)$v->fetchColumn();
    $w = ["(ot.Order_status IS NULL OR ot.Order_status NOT IN (6, 9))", "ot.Order_oo IS NOT NULL", "TRIM(ot.Order_oo) <> ''", "UPPER(REPLACE(TRIM(ot.Order_oo), '.', '')) NOT IN ('NA','N/A')"]; $p = [];
    $kw = trim($kw);
    if ($kw !== '') { $w[] = "(ot.Order_oo LIKE ? OR ot.d_id LIKE ? OR ot.Client_name LIKE ? OR ot.Specification LIKE ?)"; $l = '%' . $kw . '%'; array_push($p, $l, $l, $l, $l); }
    else $w[] = "ot.d_id_ID = " . $part;
    $st = $db->prepare("SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.d_id_ID, ot.Specification, ot.Client_name, ot.Client_name_ID, ot.Qty, ot.unit_price,
                               DATE_FORMAT(ot.Delivery_date,'%Y-%m-%d') delivery, COALESCE(cl.customer, ot.Client_name) client_display,
                               COALESCE((SELECT SUM(il.Qty) FROM is_list il WHERE il.Order_id=ot.Order_id),0) shipped
                        FROM order_track ot LEFT JOIN customer_list cl ON cl.customer_id=ot.Client_name_ID
                        WHERE " . implode(' AND ', $w) . " HAVING ot.Qty - shipped > 0 ORDER BY ot.Delivery_date, ot.Order_id LIMIT 30");
    $st->execute($p);
    return array_map(function ($r) use ($part) {
        $r['Order_id'] = (int)$r['Order_id']; $r['remain'] = (int)$r['Qty'] - (int)$r['shipped']; $r['same_part'] = ((int)$r['d_id_ID'] === $part);
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}
function ti_alloc_ship(PDO $db, int $vid, int $orderId, int $qty, string $date, int $uid, array $perm): array {
    $p = ti_alloc_pre($db, $vid, $qty, $date, $perm);
    if ($p[0] !== '') return [false, $p[0], []];
    [, $v, $inq] = $p;
    require_once __DIR__ . '/shipping_lib.php';
    $u = sq_current_user($db); $sp = sq_perms($db, $u);
    if (empty($sp['canEdit'])) return [false, '您沒有出貨登錄權限（快速出貨）', []];
    $o = null;
    foreach (ti_ship_orders($db, $vid, '') as $r) if ($r['Order_id'] === $orderId) { $o = $r; break; }
    // 非同料號的訂單（用關鍵字搜出來的）：再用訂單號精準取一次
    if (!$o) {
        $q = $db->prepare("SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.d_id_ID, ot.Specification, ot.Client_name, ot.Client_name_ID, ot.Qty, ot.unit_price, COALESCE(cl.customer, ot.Client_name) client_display,
                                  COALESCE((SELECT SUM(il.Qty) FROM is_list il WHERE il.Order_id=ot.Order_id),0) shipped
                           FROM order_track ot LEFT JOIN customer_list cl ON cl.customer_id=ot.Client_name_ID
                           WHERE ot.Order_id=? AND (ot.Order_status IS NULL OR ot.Order_status NOT IN (6,9))");
        $q->execute([$orderId]); $o = $q->fetch(PDO::FETCH_ASSOC);
        if ($o) $o['remain'] = (int)$o['Qty'] - (int)$o['shipped'];
    }
    if (!$o || $o['remain'] < 1) return [false, '找不到這張訂單，或訂單已出完', []];
    if ($qty > $o['remain']) return [false, '數量超過訂單尚未出貨的數量（剩 ' . $o['remain'] . '）', []];
    $item = ['order_id' => $orderId, 'd_setting_id' => $o['d_id_ID'] ?: null, 'product_id' => (string)$o['d_id'], 'specification' => (string)$o['Specification'],
             'client_id' => (string)$o['Client_name_ID'], 'client_name' => (string)$o['client_display'], 'qty' => $qty, 'unit_price' => (float)$o['unit_price'],
             'note' => '刀具詢價 ' . $inq['inq_no'], 'warehouse' => ''];
    $r = ti_create_shipment_internal($db, $date, $item, $uid);
    if (empty($r['success'])) return [false, (string)($r['message'] ?? '建立出貨單失敗'), []];
    $isNo = (string)(array_key_first($r['shipments']) ?? '');
    $db->prepare("INSERT INTO tool_inq_alloc (vid, kind, qty, order_id, is_number, alloc_date, created_by, created_at) VALUES (?, 'ship', ?, ?, ?, ?, ?, NOW())")
       ->execute([$vid, $qty, $orderId, $isNo, $date, $uid]);
    return [true, '已開立出貨單 ' . $isNo . '（' . $qty . ' 個，訂單 ' . $o['Order_oo'] . '）', ['is_number' => $isNo]];
}

/** 出貨用的訂單列（同料號的待出貨訂單，或用關鍵字搜到的其他訂單）；找不到回 null */
function ti_order_row(PDO $db, int $vid, int $orderId): ?array {
    foreach (ti_ship_orders($db, $vid, '') as $r) if ($r['Order_id'] === $orderId) return $r;
    $q = $db->prepare("SELECT ot.Order_id, ot.Order_oo, ot.d_id, ot.d_id_ID, ot.Specification, ot.Client_name, ot.Client_name_ID, ot.Qty, ot.unit_price, COALESCE(cl.customer, ot.Client_name) client_display,
                              COALESCE((SELECT SUM(il.Qty) FROM is_list il WHERE il.Order_id=ot.Order_id),0) shipped
                       FROM order_track ot LEFT JOIN customer_list cl ON cl.customer_id=ot.Client_name_ID
                       WHERE ot.Order_id=? AND (ot.Order_status IS NULL OR ot.Order_status NOT IN (6,9))");
    $q->execute([$orderId]); $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    $o['Order_id'] = (int)$o['Order_id']; $o['remain'] = (int)$o['Qty'] - (int)$o['shipped'];
    return $o;
}
function ti_ship_item(array $o, int $qty, string $inqNo): array {
    return ['order_id' => (int)$o['Order_id'], 'd_setting_id' => $o['d_id_ID'] ?: null, 'product_id' => (string)$o['d_id'], 'specification' => (string)$o['Specification'],
            'client_id' => (string)$o['Client_name_ID'], 'client_name' => (string)$o['client_display'], 'qty' => $qty, 'unit_price' => (float)$o['unit_price'],
            'note' => '刀具詢價 ' . $inqNo, 'warehouse' => ''];
}
/** 快速出貨的建單用（不連動「預留待出貨」的自動扣除；那是給「別的入口」開的出貨單用的） */
function ti_create_shipment_internal(PDO $db, string $date, array $item, int $uid): array {
    require_once __DIR__ . '/shipping_lib.php';
    $GLOBALS['TI_SHIP_INTERNAL'] = true;
    try { $r = sq_create_shipment($db, $date, [$item], (string)$uid); } finally { unset($GLOBALS['TI_SHIP_INTERNAL']); }
    return $r;
}
/**
 * 預留待出貨：貨已到、但還沒開出貨單（例如還沒有出貨權限、或客戶還沒要）。只記錄「這批貨預留給哪張訂單」，
 * 不動庫存也不開出貨單；快速出貨的待出貨清單會把這個數量算進該訂單的「可出量」並標出「刀具已到貨 N」，
 * 之後不論從哪個入口開出貨單，系統都會自動把預留扣掉（ti_hold_consume）。
 */
function ti_alloc_hold(PDO $db, int $vid, int $orderId, int $qty, string $date, int $uid, array $perm): array {
    $p = ti_alloc_pre($db, $vid, $qty, $date, $perm);
    if ($p[0] !== '') return [false, $p[0], []];
    $o = ti_order_row($db, $vid, $orderId);
    if (!$o || $o['remain'] < 1) return [false, '找不到這張訂單，或訂單已出完', []];
    $h = $db->prepare("SELECT COALESCE(SUM(qty),0) FROM tool_inq_alloc WHERE kind='hold' AND order_id=?"); $h->execute([$orderId]);
    $room = $o['remain'] - (int)$h->fetchColumn();
    if ($qty > $room) return [false, '數量超過這張訂單還可預留的數量（訂單尚未出貨 ' . $o['remain'] . '，已預留 ' . ($o['remain'] - $room) . '）', []];
    $db->prepare("INSERT INTO tool_inq_alloc (vid, kind, qty, order_id, alloc_date, created_by, created_at) VALUES (?, 'hold', ?, ?, ?, ?, NOW())")->execute([$vid, $qty, $orderId, $date, $uid]);
    return [true, '已預留 ' . $qty . ' 個給訂單 ' . $o['Order_oo'] . '（待出貨）', []];
}
function ti_alloc_hold_row(PDO $db, int $allocId, array $perm): array {   // [錯誤, alloc 列, 廠商列]
    $st = $db->prepare("SELECT a.*, v.inq_id, v.vendor_name, i.created_by, i.inq_no FROM tool_inq_alloc a JOIN tool_inq_vendor v ON v.vid=a.vid JOIN tool_inq i ON i.id=v.inq_id WHERE a.alloc_id=?");
    $st->execute([$allocId]); $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a || $a['kind'] !== 'hold') return ['找不到這筆預留（可能已被處理）'];
    if (!ti_can_edit($perm, $a)) return ['您沒有編輯這張詢價單的權限'];
    return ['', $a];
}
function ti_alloc_hold_cancel(PDO $db, int $allocId, array $perm): array {
    $r = ti_alloc_hold_row($db, $allocId, $perm);
    if ($r[0] !== '') return [false, $r[0], []];
    $db->prepare("DELETE FROM tool_inq_alloc WHERE alloc_id=? AND kind='hold'")->execute([$allocId]);
    return [true, '已取消預留，數量回到「尚未處理」', []];
}
/** 把一筆預留轉成正式出貨：開出貨單（快速出貨，綁訂單） */
function ti_alloc_hold_ship(PDO $db, int $allocId, string $date, int $uid, array $perm): array {
    $r = ti_alloc_hold_row($db, $allocId, $perm);
    if ($r[0] !== '') return [false, $r[0], []];
    $a = $r[1];
    if (!ti_date_ok($date)) return [false, '日期格式不正確', []];
    if ($date > ti_now($db)['d']) return [false, '日期不可晚於今天', []];
    require_once __DIR__ . '/shipping_lib.php';
    $u = sq_current_user($db); $sp = sq_perms($db, $u);
    if (empty($sp['canEdit'])) return [false, '您沒有出貨登錄權限（快速出貨）', []];
    $o = ti_order_row($db, (int)$a['vid'], (int)$a['order_id']);
    if (!$o || $o['remain'] < 1) return [false, '找不到這張訂單，或訂單已出完', []];
    $qty = min((int)$a['qty'], $o['remain']);
    $res = ti_create_shipment_internal($db, $date, ti_ship_item($o, $qty, (string)$a['inq_no']), $uid);
    if (empty($res['success'])) return [false, (string)($res['message'] ?? '建立出貨單失敗'), []];
    $isNo = (string)(array_key_first($res['shipments']) ?? '');
    if ($qty < (int)$a['qty']) {   // 訂單剩下的比預留的少：多出的部分留成預留
        $db->prepare("UPDATE tool_inq_alloc SET qty=qty-? WHERE alloc_id=?")->execute([$qty, $allocId]);
        $db->prepare("INSERT INTO tool_inq_alloc (vid, kind, qty, order_id, is_number, alloc_date, created_by, created_at) VALUES (?, 'ship', ?, ?, ?, ?, ?, NOW())")->execute([(int)$a['vid'], $qty, (int)$a['order_id'], $isNo, $date, $uid]);
    } else {
        $db->prepare("UPDATE tool_inq_alloc SET kind='ship', is_number=?, alloc_date=? WHERE alloc_id=?")->execute([$isNo, $date, $allocId]);
    }
    return [true, '已開立出貨單 ' . $isNo . '（' . $qty . ' 個）', ['is_number' => $isNo]];
}
/** 別的入口（例如快速出貨頁）對這張訂單開了出貨單：把這張訂單的預留依先後順序扣掉，轉成「已出貨」紀錄 */
function ti_hold_consume(PDO $db, int $orderId, int $qty, string $isNumber, int $uid): void {
    $st = $db->prepare("SELECT alloc_id, vid, qty FROM tool_inq_alloc WHERE kind='hold' AND order_id=? ORDER BY alloc_id");
    $st->execute([$orderId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
        if ($qty <= 0) break;
        $take = min((int)$h['qty'], $qty);
        if ($take >= (int)$h['qty']) {
            $db->prepare("UPDATE tool_inq_alloc SET kind='ship', is_number=? WHERE alloc_id=?")->execute([$isNumber, $h['alloc_id']]);
        } else {
            $db->prepare("UPDATE tool_inq_alloc SET qty=qty-? WHERE alloc_id=?")->execute([$take, $h['alloc_id']]);
            $db->prepare("INSERT INTO tool_inq_alloc (vid, kind, qty, order_id, is_number, alloc_date, created_by, created_at) VALUES (?, 'ship', ?, ?, ?, CURDATE(), ?, NOW())")->execute([(int)$h['vid'], $take, $orderId, $isNumber, $uid]);
        }
        $qty -= $take;
    }
}
/** 自動建立刀具料號用的工件小類（主檔 dict_workpiece_sub_type，type_code='H'）。設定有指定就用指定的，沒指定就依名稱找（滾刀＝滾齒刀、鉋齒刀＝插齒刀） */
function ti_sub_type_rows(PDO $db): array {
    return $db->query("SELECT sub_type_id, sub_type_name FROM dict_workpiece_sub_type WHERE type_code='H' AND is_active=1 ORDER BY sort_order, sub_type_id")->fetchAll(PDO::FETCH_ASSOC);
}
function ti_sub_type_for(PDO $db, string $kind, array $set): array {   // [sub_type_id|0, 名稱]
    $want = (int)($kind === 'hob' ? $set['sub_hob'] : $set['sub_shaper']);
    $rows = ti_sub_type_rows($db);
    foreach ($rows as $r) if ($want > 0 && (int)$r['sub_type_id'] === $want) return [(int)$r['sub_type_id'], (string)$r['sub_type_name']];
    $name = $kind === 'hob' ? '滾齒刀' : '插齒刀';
    foreach ($rows as $r) if (trim((string)$r['sub_type_name']) === $name) return [(int)$r['sub_type_id'], $name];
    return [0, ''];
}

/* ═══════════════ 轉存 PDF（一家廠商一個檔） ═══════════════
 * 畫面照列印版面組好 HTML＋CSS，送到這裡由 headless Chrome（attachment_lib 既有的 eg_att_browser_pdf）印成 PDF。
 * 因為 HTML 是使用者端送來的，**一律先過白名單清洗**：只留版面用的標籤與屬性，不收 script／iframe／外部連結／file: 等，
 * 避免有人借這支轉檔去讀伺服器本機檔案或打內網（SSRF）。圖章用的 SVG 與 data:image 內嵌圖片（掃描章）有限度放行。
 */
function ti_pdf_clean_css(string $css): ?string {
    if (strlen($css) > 60000) return null;
    if (preg_match('/url\s*\(|@import|expression\s*\(|javascript:|behavior\s*:|<\/?\s*(style|script)|<!--/i', $css)) return null;
    return $css;
}
function ti_pdf_clean_html(string $html): ?string {
    if (strlen($html) > 700000) return null;
    $okTags = array_flip(['div', 'span', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'b', 'strong', 'i', 'em', 'u', 'br', 'small', 'p', 'h1', 'h2', 'h3', 'h4', 'img',
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'text', 'tspan', 'image', 'defs']);
    $okAttr = array_flip(['class', 'style', 'colspan', 'rowspan', 'width', 'height', 'viewbox', 'd', 'fill', 'stroke', 'stroke-width', 'transform', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'points', 'font-size', 'font-family', 'font-weight', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'opacity', 'fill-opacity', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
        'textlength', 'lengthadjust', 'preserveaspectratio', 'fill-rule', 'stroke-dasharray', 'xmlns']);
    $dataImg = '#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#';
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<' . '?xml encoding="utf-8"?><div id="ti-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $dom->getElementById('ti-root');
    if (!$root) return null;
    $walk = function (DOMNode $n) use (&$walk, $okTags, $okAttr, $dataImg) {
        $kids = [];
        foreach ($n->childNodes as $c) $kids[] = $c;
        foreach ($kids as $c) {
            if ($c->nodeType === XML_COMMENT_NODE || $c->nodeType === XML_PI_NODE) { $n->removeChild($c); continue; }
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($c->nodeName);
            if (!isset($okTags[$tag])) { $n->removeChild($c); continue; }   // script／iframe／object／link／form…整個移除
            $drop = [];
            foreach ($c->attributes as $a) {
                $an = strtolower($a->nodeName); $av = (string)$a->nodeValue;
                if (($an === 'href' || $an === 'xlink:href' || $an === 'src') && in_array($tag, ['image', 'img'], true) && preg_match($dataImg, $av)) continue;
                if (!isset($okAttr[$an])) { $drop[] = $a->nodeName; continue; }
                if ($an === 'xmlns') { if ($av !== 'http://www.w3.org/2000/svg') $drop[] = $a->nodeName; continue; }
                if (preg_match('/url\s*\(|javascript:|file:|https?:|data:|expression\s*\(|@import/i', $av)) $drop[] = $a->nodeName;
            }
            foreach ($drop as $d) $c->removeAttribute($d);
            $walk($c);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $dom->saveHTML($c);
    return $out;
}
/** 回傳 PDF 內容；失敗回 null 並把原因放進 $err */
function ti_pdf_render(string $html, string $css, ?string &$err = null): ?string {
    require_once __DIR__ . '/attachment_lib.php';
    $c = ti_pdf_clean_css($css);
    if ($c === null) { $err = '版面樣式含不允許的內容'; return null; }
    $h = ti_pdf_clean_html($html);
    if ($h === null || trim(strip_tags($h)) === '') { $err = '列印內容為空或太大'; return null; }
    $tmp = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'tiq_' . bin2hex(random_bytes(6));
    $htmlPath = $tmp . '.html'; $pdfPath = $tmp . '.pdf';
    file_put_contents($htmlPath, '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $c . '</style></head><body>' . $h . '</body></html>');
    $ok = eg_att_browser_pdf($htmlPath, $pdfPath, 60);
    @unlink($htmlPath);
    if (!$ok || !is_file($pdfPath)) { @unlink($pdfPath); $err = '伺服器轉 PDF 失敗（找不到瀏覽器或逾時），請改用「列印」另存 PDF'; return null; }
    $bin = (string)file_get_contents($pdfPath); @unlink($pdfPath);
    return (substr($bin, 0, 4) === '%PDF') ? $bin : null;
}

function ti_delete(PDO $db, int $id, array $perm): array {
    $st = $db->prepare("SELECT * FROM tool_inq WHERE id=?"); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return [false, '查無此詢價單'];
    if (!ti_can_edit($perm, $r)) return [false, '您沒有刪除這張詢價單的權限'];
    $o = $db->prepare("SELECT COUNT(*) FROM tool_inq_vendor WHERE inq_id=? AND order_date IS NOT NULL"); $o->execute([$id]);
    if ((int)$o->fetchColumn() > 0 && empty($perm['canToolAdmin'])) return [false, '已有廠商下訂，只有管理員可以刪除（避免訂單紀錄憑空消失）'];
    $al = $db->prepare("SELECT COUNT(*) FROM tool_inq_alloc WHERE vid IN (SELECT vid FROM tool_inq_vendor WHERE inq_id=?)"); $al->execute([$id]);
    if ((int)$al->fetchColumn() > 0) return [false, '已有入庫／出貨紀錄，不能刪除這張詢價單（庫存與出貨單會對不上）；請改用「結案」'];
    $files = $db->prepare("SELECT file_name FROM tool_inq_attach WHERE inq_id=?"); $files->execute([$id]);
    $names = $files->fetchAll(PDO::FETCH_COLUMN);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM tool_inq_attach WHERE inq_id=?")->execute([$id]);
        $db->prepare("DELETE FROM tool_inq_vendor WHERE inq_id=?")->execute([$id]);
        $db->prepare("DELETE FROM tool_inq WHERE id=?")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); return [false, '刪除失敗：' . $e->getMessage()]; }
    $dir = ti_attach_dir($db);
    foreach ($names as $n) @unlink($dir . basename((string)$n));
    return [true, ''];
}

/* ═══════════════ 交期提醒 ═══════════════ */
/** 提醒對象＝建單人＋其單位主管（無法解析時退回業務部門的主管）；只留在職者 */
function ti_remind_targets(PDO $db, int $creatorId): array {
    $ids = [];
    if ($creatorId > 0) $ids[] = $creatorId;
    try {
        $sup = $creatorId > 0 ? eg_unit_supervisor_id($db, $creatorId) : null;
        if ($sup && (int)$sup !== $creatorId) $ids[] = (int)$sup;
        elseif (!$sup) {
            foreach (eg_org_dept_ids($db, 'sales_dept') as $did) { $h = eg_unit_dept_head($db, (int)$did); if ($h && !empty($h['id'])) { $ids[] = (int)$h['id']; break; } }
        }
    } catch (Throwable $e) {}
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    return array_map('intval', $db->query("SELECT id FROM `user` WHERE id IN ($in) AND state<>0 AND user_status<>90")->fetchAll(PDO::FETCH_COLUMN) ?: []);
}
function ti_send_reminder(PDO $db, array $r, string $kind): void {
    $uids = ti_remind_targets($db, (int)$r['created_by']);
    if (!$uids) return;
    $dueTx = str_replace('-', '.', (string)$r['due_date']);
    $days = (int)floor((strtotime((string)$r['due_date']) - strtotime(date('Y-m-d'))) / 86400);
    $when = $days > 0 ? ('還有 ' . $days . ' 天') : ($days === 0 ? '就是今天' : ('已逾期 ' . abs($days) . ' 天'));
    $title = ($kind === 'overdue' ? '【交期逾期・已置頂】' : '【交期將到】') . '刀具詢價 ' . $r['inq_no'] . '　' . $r['vendor_name'] . '　交期 ' . $dueTx;
    $body = "詢價單：" . $r['inq_no'] . "（" . $r['part_no'] . ($r['customer'] ? '／' . $r['customer'] : '') . "）\n廠商：" . $r['vendor_name']
          . "\n下訂日：" . ($r['order_date'] ? str_replace('-', '.', $r['order_date']) : '（未填）') . "\n交期：" . $dueTx . '（' . $when . "）\n"
          . '到貨後請到「詢價單 → 刀具詢價 → 廠商回覆」填寫到貨日，提醒即停止。';
    $eid = 0;
    try {
        $db->prepare("UPDATE live_event SET enddate=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE ref_type='TOOL_INQ_REMIND' AND ref_id=? AND (enddate IS NULL OR enddate>=CURDATE())")->execute([(int)$r['vid']]);
        $db->prepare("INSERT INTO live_event (eventdate, enddate, title, content, status, created_by, source, show_status_to_others, ref_type, ref_id)
                      VALUES (CURDATE(), NULL, ?, ?, 0, ?, '刀具詢價', 1, 'TOOL_INQ_REMIND', ?)")->execute([$title, $body, (int)$r['created_by'], (int)$r['vid']]);
        $eid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode) VALUES (?, 'user', ?, 'read')");
        foreach ($uids as $u) $ins->execute([$eid, $u]);
    } catch (Throwable $e) { error_log('[tool_inq] live_event failed: ' . $e->getMessage()); }
    try {
        require_once __DIR__ . '/../push/push_send.php';
        eg_push_send_to_users($db, $eid ? eg_push_event_recipients($db, $eid) : $uids, ['title' => $title, 'body' => mb_substr($body, 0, 480),
            'url' => '/EGsystem/views/pm/inquiry_sheet.php?tab=tool&id=' . (int)$r['id'], 'tag' => 'tool-inq-' . (int)$r['vid']]);
    } catch (Throwable $e) { error_log('[tool_inq] push failed: ' . $e->getMessage()); }
    try {
        require_once __DIR__ . '/../../telegram/send_message.php';
        if (function_exists('tg_is_configured') && tg_is_configured()) {
            $in = implode(',', array_map('intval', $uids));
            foreach ($db->query("SELECT chat_id FROM telegram_users WHERE is_active=1 AND user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $chat) tg_send_text($chat, $title . "\n" . $body, $db);
        }
    } catch (Throwable $e) { error_log('[tool_inq] telegram failed: ' . $e->getMessage()); }
}
/** 掃描並發送到期提醒；回傳發出幾則。先搶佔（UPDATE 命中才發）避免多個請求同時觸發重複發送 */
function ti_process_reminders(PDO $db): int {
    ti_ensure_schema($db);
    $sql = "SELECT v.*, i.inq_no, i.created_by, i.id, COALESCE(d.D_Setting_Id,i.part_no_text) part_no, COALESCE(c.customer,i.customer_text,'') customer
            FROM tool_inq_vendor v JOIN tool_inq i ON i.id=v.inq_id
            LEFT JOIN d_setting d ON d.d_id=i.part_id LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
            WHERE v.due_date IS NOT NULL AND v.is_void=0 AND v.received_date IS NULL AND i.is_closed=0
              AND CURDATE() >= DATE_SUB(v.due_date, INTERVAL v.remind_days DAY)";
    $n = 0;
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $overdue = date('Y-m-d') > $r['due_date'];
        $col = $overdue ? 'overdue_sent_for' : 'remind_sent_for';
        $own = $db->prepare("UPDATE tool_inq_vendor SET {$col}=? WHERE vid=? AND ({$col} IS NULL OR {$col}<>?)");
        $own->execute([$r['due_date'], $r['vid'], $r['due_date']]);
        if ($own->rowCount() < 1) continue;
        ti_send_reminder($db, $r, $overdue ? 'overdue' : 'soon');
        $n++;
    }
    return $n;
}

} // EG_TOOL_INQ_LIB
