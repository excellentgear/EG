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
    if ($seedOpt) {   // 種子取自業務現行紙本（精度手寫 AA／A／B、材質 P5、鍍層「照工件需求」），管理員可再增修
        $ins = $db->prepare("INSERT IGNORE INTO tool_inq_opt (kind,name,sort_order) VALUES (?,?,?)");
        foreach ([['accuracy','AA',1],['accuracy','A',2],['accuracy','B',3],['material','P5',1],['coating','照工件需求',1]] as $r) $ins->execute($r);
    }
    $done = true;
}

/* ═══════════════ 設定與選項 ═══════════════ */
function ti_settings(PDO $db): array {
    ti_ensure_schema($db);
    $o = ['starts_max' => 10, 'slots_max' => 50];   // 牙口數／槽數：需 >0 且 <上限
    try { foreach ($db->query("SELECT skey,sval FROM tool_inq_setting") as $r) if (isset($o[$r['skey']])) $o[$r['skey']] = (int)$r['sval']; } catch (Throwable $e) {}
    return $o;
}
function ti_settings_save(PDO $db, int $startsMax, int $slotsMax): array {
    ti_ensure_schema($db);
    if ($startsMax < 2 || $startsMax > 999) return [false, '牙口數上限請填 2～999（需大於 1，因為牙口數必須 >0 且 <上限）'];
    if ($slotsMax < 2 || $slotsMax > 999) return [false, '槽數上限請填 2～999（需大於 1，因為槽數必須 >0 且 <上限）'];
    $st = $db->prepare("INSERT INTO tool_inq_setting (skey,sval) VALUES (?,?) ON DUPLICATE KEY UPDATE sval=VALUES(sval)");
    $st->execute(['starts_max', $startsMax]); $st->execute(['slots_max', $slotsMax]);
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
    if (!empty($perm['canAdmin'])) return true;
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
        [$e, $v] = ti_int($t['starts'] ?? null, 1, null, '牙口數', $set['starts_max']); if ($e) return $fail($e); $o['starts'] = $v;
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
    $pre = 'TI' . date('ymd', strtotime($date));
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
        $v['lead_days'] = $v['lead_days'] !== null ? (int)$v['lead_days'] : null;
        $v['unit_price'] = $v['unit_price'] !== null ? (float)$v['unit_price'] : null;
        $v['stage'] = ti_vendor_stage($v);
        return $v;
    }, $vs->fetchAll(PDO::FETCH_ASSOC));
    $o['status'] = ti_overall($r, $o['vendors']);
    $ac = $db->prepare("SELECT category, vid, COUNT(*) c FROM tool_inq_attach WHERE inq_id=? AND status='active' GROUP BY category, vid"); $ac->execute([$id]);
    $o['att_count'] = array_sum(array_map(function ($x) { return (int)$x['c']; }, $ac->fetchAll(PDO::FETCH_ASSOC)));
    if ($full) $o['attaches'] = ti_attaches($db, $id);
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
    if (($o['status'] ?? '') === 'open')   $w[] = 'i.is_closed=0';
    if (($o['status'] ?? '') === 'closed') $w[] = 'i.is_closed=1';
    if (in_array($o['tool_type'] ?? '', ['shaper', 'hob'], true)) { $w[] = 'i.tool_type=?'; $p[] = $o['tool_type']; }
    $kw = trim((string)($o['kw'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/u', $kw) as $k) {   // 多關鍵字：每個都要命中（可分散在不同欄位）
            $like = '%' . $k . '%';
            $w[] = "(i.inq_no LIKE ? OR i.part_no_text LIKE ? OR d.D_Setting_Id LIKE ? OR c.customer LIKE ? OR c.customer_full LIKE ? OR i.customer_text LIKE ?
                     OR i.created_by_name LIKE ? OR EXISTS (SELECT 1 FROM tool_inq_vendor v WHERE v.inq_id=i.id AND (v.vendor_name LIKE ? OR v.vendor_id_no LIKE ?)))";
            array_push($p, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
    }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $cnt = $db->prepare("SELECT COUNT(*) FROM tool_inq i LEFT JOIN d_setting d ON d.d_id=i.part_id LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id" . $where);
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();
    $psize = in_array((int)($o['psize'] ?? 10), [5, 10, 20, 50], true) ? (int)$o['psize'] : 10;
    $pages = max(1, (int)ceil($total / $psize));
    $page = min(max(1, (int)($o['page'] ?? 1)), $pages);
    $st = $db->prepare(ti_select_sql() . $where . " ORDER BY i.inq_date DESC, i.id DESC LIMIT " . $psize . " OFFSET " . (($page - 1) * $psize));
    $st->execute($p);
    $rows = array_map(function ($r) use ($db) { return ti_row($db, $r, false); }, $st->fetchAll(PDO::FETCH_ASSOC));
    return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
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
    } elseif (empty($perm['canCreate'])) return [false, '您沒有新增詢價單的權限', 0];

    $partId = (int)($P['part_id'] ?? 0);
    $partText = mb_substr(trim((string)($P['part_no_text'] ?? '')), 0, 100);
    if ($partId > 0) {
        $pc = ti_part_customer($db, $partId);
        if (!$pc) return [false, '綁定的料號不存在，請重新選擇', 0];
        $partText = $pc['part_no'];
    }
    if ($partId <= 0 && $partText === '') return [false, '請綁定料號（或至少填寫料號文字）', 0];
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
    [$e, $price] = ti_num($P['unit_price'] ?? null, 0.0, 99999999.0, '單價'); if ($e) return [false, $e];
    $leadRaw = trim((string)($P['lead_days'] ?? ''));
    [$e, $lead] = ti_int($leadRaw === '' ? null : $leadRaw, 0, 9999, '交期天數'); if ($e) return [false, $e];
    $remRaw = trim((string)($P['remind_days'] ?? '3')); if ($remRaw === '') $remRaw = '3';
    [$e, $rem] = ti_int($remRaw, 0, 60, '提醒天數'); if ($e) return [false, $e];
    $cur = in_array($P['currency'] ?? '', ti_currencies(), true) ? $P['currency'] : 'NTD';
    if (!$due && $order && $lead !== null) $due = date('Y-m-d', strtotime($order . ' +' . $lead . ' day'));   // 交期未填：下訂日＋交期天數
    $note = mb_substr(trim((string)($P['reply_note'] ?? '')), 0, 1000);
    $void = !empty($P['is_void']) ? 1 : 0;
    $now = ti_now($db);
    // 交期或提醒天數改了就重新計算提醒（清掉已發記錄）
    $reset = ($due !== $v['due_date'] || (int)$rem !== (int)$v['remind_days']);
    $db->prepare("UPDATE tool_inq_vendor SET reply_date=?, currency=?, unit_price=?, lead_days=?, order_date=?, due_date=?, received_date=?, remind_days=?, reply_note=?, is_void=?, updated_at=?"
                 . ($reset ? ", remind_sent_for=NULL, overdue_sent_for=NULL" : "") . " WHERE vid=?")
       ->execute([$reply, $cur, $price, $lead, $order, $due, $recv, $rem ?? 3, $note, $void, $now['n'], $vid]);
    return [true, ''];
}

function ti_delete(PDO $db, int $id, array $perm): array {
    $st = $db->prepare("SELECT * FROM tool_inq WHERE id=?"); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return [false, '查無此詢價單'];
    if (!ti_can_edit($perm, $r)) return [false, '您沒有刪除這張詢價單的權限'];
    $o = $db->prepare("SELECT COUNT(*) FROM tool_inq_vendor WHERE inq_id=? AND order_date IS NOT NULL"); $o->execute([$id]);
    if ((int)$o->fetchColumn() > 0 && empty($perm['canAdmin'])) return [false, '已有廠商下訂，只有管理員可以刪除（避免訂單紀錄憑空消失）'];
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
    $title = ($kind === 'overdue' ? '【交期逾期】' : '【交期將到】') . '刀具詢價 ' . $r['inq_no'] . '　' . $r['vendor_name'] . '　交期 ' . $dueTx;
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
