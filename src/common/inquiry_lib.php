<?php
/**
 * 詢價單（inquiry）—— 業務詢外包加工／生管詢外包材料／採購詢相關採購　三部門共用唯一實作
 *
 * 格式參照 FOR CODEING 說明文件/詢價單.xlsx（超正齒輪科技有限公司既有紙本：
 * 廠商名稱／聯絡人員／聯絡電話／幣別｜單號／詢價人員／傳真號碼／詢價日期｜
 * 項次／產品編號／規格／數量／單價｜備註｜經辦／審核／簽收，AS 編號 3-OB-01-05）。
 *
 * 一次詢價常常要同時問好幾家廠商：建立時可指定多家，系統自動展開成多張「子單」（一家一張，
 * 各自獨立單號／聯絡資料／單價欄）；子單預設「跟隨母單」——改母單的項目/數量/規格會自動同步
 * 到每一張跟隨中的子單，直接改某一張子單的項目內容則該張自動脫離跟隨（之後母單再怎麼改都不會
 * 動到它），兩種編輯方式並存，互不干擾。單價永遠只存在子單（那是廠商回覆的資料，母單本身
 * 不是要發給任何人的文件，不會有單價）。
 *
 * 綁定對象三種，全部「可選、可後補」：
 *   - 料號（d_setting）：掛在**項目列**（一張單可能問好幾支料號）
 *   - BOM（bom）／請購單（purchase_request）：掛在**母單**（整次詢價對應同一張 BOM 或同一張請購單）
 * 任一種綁不綁都不影響詢價單本身的建立與列印，之後隨時可以補上或改掉。
 *
 * 管理員可逐部門（業務／生管／採購）設定：
 *   allow_free_part   —— 允許用「非實際存在的料號」詢價（產品編號可以不綁料號主檔，純打字）
 *   allow_custom_spec —— 允許自訂規格（不是由料號主檔帶出的唯讀文字，使用者自己打規格）
 * 兩者都預設關閉（嚴格：要求綁真實料號、規格由料號帶出），管理員可視需要逐部門放寬。
 *
 * 2026-10-08 使用者再次交辦收斂的規則（皆前後端雙重把關，鐵律8）：
 *   - 詢價人員一律＝目前登入者本人，不可修改、也不接受前端送來的值；詢價日期一律＝伺服器今天，
 *     建立後不可再改（不接受前端送來的值）——這兩項是「這是誰、哪天問的」的事實，不是可編輯欄位。
 *   - 能不能「新增詢價單」完全看使用者**實際任職或兼任**於業務／生管／採購哪些部門（含管理員，
 *     管理員若本身不在這三個部門一樣不能新增，只能設定/檢視），來源部門下拉也只列這些。
 *   - 廠商可依「加工類別」標籤（既有 dict_maker_main_category／dict_maker_sub_category／
 *     maker_sub_category_mapping，master_data 廠商分頁同一套，不另建新的分類系統）篩選縮小勾選清單，
 *     篩選只影響「目前看得到誰」，已勾選的廠商換篩選條件也不會被洗掉。
 *   - 備註可選用「常用用語」範本（inq_note_tpl）：分「部門」（僅同部門語境可見可用）與「公開」
 *     （所有來源部門都看得到）兩種；任何可以新增詢價單的人都能新增/編輯/刪除**自己**設定的範本
 *     （管理員額外可編輯/刪除別人的，供日常維護），點選是「帶入」備註欄而不是強制套用。
 */
if (!defined('EG_INQUIRY_LIB')) {
define('EG_INQUIRY_LIB', 1);

require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';

/** 三個來源部門：內部代碼 => [顯示名稱, org_role_lib 綁定鍵] */
function inq_depts(): array {
    return [
        'sales'    => ['業務', 'sales_dept'],
        'pmc'      => ['生管', 'pm_dept'],
        'purchase' => ['採購', 'purchase_dept'],
    ];
}
function inq_dept_label(string $key): string {
    $d = inq_depts();
    return $d[$key][0] ?? $key;
}

/* ════════════════════════════════════════════════════════════════════════
   資料表（⚠ DDL 會造成隱式 commit，一律先確認不存在、且不在交易中才下）
   ════════════════════════════════════════════════════════════════════════ */
function inq_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    if ($db->inTransaction()) return;
    $exists = function (PDO $db, string $t): bool {
        $st = $db->prepare("SHOW TABLES LIKE ?"); $st->execute([$t]);
        return $st->fetchColumn() !== false;
    };
    try {
        if (!$exists($db, 'inq_group')) {
            $db->exec("CREATE TABLE inq_group (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_dept VARCHAR(20) NOT NULL COMMENT 'sales/pmc/purchase',
                bind_type VARCHAR(20) NULL COMMENT 'bom/purchase_request，可為空',
                bind_ref VARCHAR(100) NULL COMMENT 'bom=bom.bom文字；purchase_request=purchase_request.req_no',
                bind_label VARCHAR(150) NULL COMMENT '綁定對象的快照顯示文字（對象被刪除時仍看得出原本綁的是什麼）',
                requester_id INT NOT NULL,
                requester_name VARCHAR(60) NOT NULL,
                inquiry_date DATE NOT NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'NTD',
                note VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open/closed',
                created_by INT NULL, created_at DATETIME NULL,
                modified_by INT NULL, modified_at DATETIME NULL,
                deleted_at DATETIME NULL, deleted_by INT NULL,
                INDEX idx_dept (source_dept), INDEX idx_bind (bind_type, bind_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單·母單（詢價案）'");
        }
        if (!$exists($db, 'inq_group_item')) {
            $db->exec("CREATE TABLE inq_group_item (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                seq INT NOT NULL DEFAULT 1,
                part_id INT NULL COMMENT 'd_setting.d_id，綁實際料號',
                part_no_text VARCHAR(100) NULL COMMENT '未綁料號主檔時的自填產品編號',
                spec_text VARCHAR(255) NULL,
                qty DECIMAL(14,3) NULL,
                note VARCHAR(255) NULL,
                bom VARCHAR(30) NULL COMMENT '綁定的 BOM 編號（逐項目可各綁不同 BOM，可選）',
                bom_ing_fid INT NULL COMMENT '綁定 BOM 內的哪一個製程（bom_ing.bom_ing_fid）；料號/規格由此自動帶出',
                INDEX idx_group (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單·母單項目（範本列）'");
        }
        if (!$exists($db, 'inq_doc')) {
            $db->exec("CREATE TABLE inq_doc (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                doc_no VARCHAR(30) NOT NULL,
                vendor_id VARCHAR(11) NOT NULL COMMENT 'maker_list.maker_id_no',
                vendor_name VARCHAR(120) NULL,
                contact_person VARCHAR(100) NULL,
                contact_phone VARCHAR(50) NULL,
                contact_fax VARCHAR(50) NULL,
                follow_parent TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open/replied/void',
                created_by INT NULL, created_at DATETIME NULL,
                modified_by INT NULL, modified_at DATETIME NULL,
                printed_at DATETIME NULL,
                deleted_at DATETIME NULL, deleted_by INT NULL,
                UNIQUE KEY uk_doc_no (doc_no),
                INDEX idx_group (group_id), INDEX idx_vendor (vendor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單·子單（一家廠商一張）'");
        }
        if (!$exists($db, 'inq_doc_item')) {
            $db->exec("CREATE TABLE inq_doc_item (
                id INT AUTO_INCREMENT PRIMARY KEY,
                doc_id INT NOT NULL,
                group_item_id INT NULL COMMENT '對應母單項目 id；NULL＝只存在這張子單自己加的列（已脫離同步）',
                seq INT NOT NULL DEFAULT 1,
                part_id INT NULL,
                part_no_text VARCHAR(100) NULL,
                spec_text VARCHAR(255) NULL,
                qty DECIMAL(14,3) NULL,
                unit_price DECIMAL(14,4) NULL COMMENT '廠商回覆單價，只存在子單',
                note VARCHAR(255) NULL,
                bom VARCHAR(30) NULL,
                bom_ing_fid INT NULL,
                INDEX idx_doc (doc_id), INDEX idx_gitem (group_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單·子單項目'");
        }
        if (!$exists($db, 'inq_process_type_setting')) {
            $db->exec("CREATE TABLE inq_process_type_setting (
                process_type_id INT NOT NULL PRIMARY KEY COMMENT 'process_type.process_type_id',
                enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否列為「建議詢價」製程大項（預設開，沒有列的一律視為開）',
                updated_by INT NULL, updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單·管理員設定哪些製程大項建議可詢價（軟性建議，不是硬性限制，生管仍可選BOM內任何製程）'");
        }
        if (!$exists($db, 'inq_doc_seq')) {
            $db->exec("CREATE TABLE inq_doc_seq (
                seq_key VARCHAR(40) NOT NULL PRIMARY KEY COMMENT 'vendor_id+yyyymmdd',
                seq_no INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單子單編號配號計數器'");
        }
        if (!$exists($db, 'inq_dept_setting')) {
            $db->exec("CREATE TABLE inq_dept_setting (
                dept_key VARCHAR(20) NOT NULL PRIMARY KEY,
                allow_free_part TINYINT(1) NOT NULL DEFAULT 0,
                allow_custom_spec TINYINT(1) NOT NULL DEFAULT 0,
                updated_by INT NULL, updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單·各部門使用規則'");
            foreach (array_keys(inq_depts()) as $k) {
                $db->prepare("INSERT INTO inq_dept_setting (dept_key) VALUES (?)")->execute([$k]);
            }
        }
        if (!$exists($db, 'inq_note_tpl')) {
            $db->exec("CREATE TABLE inq_note_tpl (
                id INT AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(10) NOT NULL COMMENT 'dept/public',
                dept_key VARCHAR(20) NULL COMMENT 'scope=dept 時適用的部門',
                content VARCHAR(255) NOT NULL,
                created_by INT NOT NULL,
                created_by_name VARCHAR(60) NULL,
                created_at DATETIME NULL,
                INDEX idx_scope (scope, dept_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詢價單·備註常用用語'");
        }
        // 既有表格缺欄位時補 ALTER（表已存在就不會重跑上面的 CREATE，新加欄位要在這裡補；
        // 先 SHOW COLUMNS 確認不存在才下 DDL，避免交易中隱式 commit 的既有陷阱）
        $addCol = function (string $t, string $col, string $def) use ($db) {
            $st = $db->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $st->execute([$col]);
            if ($st->fetchColumn() === false) { $db->exec("ALTER TABLE `$t` ADD COLUMN $col $def"); }
        };
        $addCol('inq_group_item', 'bom', "VARCHAR(30) NULL COMMENT '綁定的 BOM 編號'");
        $addCol('inq_group_item', 'bom_ing_fid', "INT NULL COMMENT '綁定 BOM 內的哪一個製程'");
        $addCol('inq_doc_item', 'bom', "VARCHAR(30) NULL");
        $addCol('inq_doc_item', 'bom_ing_fid', "INT NULL");
        $addCol('inq_doc_item', 'vendor_note', "VARCHAR(255) NULL COMMENT '廠商報價備註'");
        $addCol('inq_doc', 'price_filled_by', "INT NULL COMMENT '回填價格者'");
        $addCol('inq_doc', 'price_filled_by_name', "VARCHAR(60) NULL");
        $addCol('inq_doc', 'price_filled_at', "DATETIME NULL COMMENT '回填價格時間'");
        $done = true;
    } catch (Throwable $e) { error_log('[inq] ensure_schema: ' . $e->getMessage()); }
}

/* ════════════════════════════════════════════════════════════════════════
   權限
   ════════════════════════════════════════════════════════════════════════ */
function inq_user_dept_keys(PDO $db, int $uid): array {
    if ($uid <= 0) return [];
    try {
        $st = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id=? AND department_id IS NOT NULL");
        $st->execute([$uid]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach (inq_depts() as $k => $d) {
        foreach ($ids as $did) { if (eg_org_in_dept($db, $d[1], $did)) { $out[] = $k; break; } }
    }
    return $out;
}

function inq_perms(PDO $db, int $uid): array {
    $none = ['uid'=>0,'name'=>'','isAdmin'=>false,'canAdmin'=>false,'canCreate'=>false,'canView'=>false,'myDepts'=>[]];
    if ($uid <= 0) return $none;
    $st = $db->prepare("SELECT id, user_cname, user_uname, state, user_status FROM `user` WHERE id=?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return $none;
    if ((int)$u['state'] === 0 || (int)$u['user_status'] === 90) return $none;

    $codes = [];
    try {
        $q = $db->prepare("SELECT r.role_code FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=?");
        $q->execute([$uid]);
        $codes = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {}
    $has = function (array $c) use ($codes) { return (bool)array_intersect($c, $codes); };
    $isAdmin  = $uid === 1 || $has(['admin','superadmin']);
    $canAdmin = $isAdmin || $has(['inquiry_admin']);
    $myDepts  = inq_user_dept_keys($db, $uid);
    // 一般使用者新增詢價單一律只看「實際任職或兼任於業務／生管／採購」——新增詢價單是在
    // 代表一個真實業務角色去問價，不在這三個部門就不可以新增。
    // 管理員（含全站管理者）不受此限制，一律可新增任何部門身分的詢價單——使用者明確要求，
    // 避免管理員帳號本身不在這三個部門時完全無法測試/補登。
    $canCreate = $canAdmin || !empty($myDepts);
    $canView   = $canCreate || $has(['inquiry_view']);

    return ['uid'=>$uid, 'name'=>(string)($u['user_cname'] ?: $u['user_uname']),
            'isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canCreate'=>$canCreate, 'canView'=>$canView, 'myDepts'=>$myDepts];
}

/** 這筆詢價案／子單，這個人能不能編輯（本人、同來源部門的同事、或管理員） */
function inq_can_edit_dept(array $perm, string $sourceDept): bool {
    return $perm['canAdmin'] || in_array($sourceDept, $perm['myDepts'] ?? [], true);
}

/* ════════════════════════════════════════════════════════════════════════
   部門使用規則設定
   ════════════════════════════════════════════════════════════════════════ */
function inq_dept_settings_all(PDO $db): array {
    inq_ensure_schema($db);
    $out = [];
    foreach (array_keys(inq_depts()) as $k) { $out[$k] = ['allow_free_part'=>0, 'allow_custom_spec'=>0]; }
    try {
        foreach ($db->query("SELECT dept_key, allow_free_part, allow_custom_spec FROM inq_dept_setting") as $r) {
            $out[$r['dept_key']] = ['allow_free_part'=>(int)$r['allow_free_part'], 'allow_custom_spec'=>(int)$r['allow_custom_spec']];
        }
    } catch (Throwable $e) {}
    return $out;
}
function inq_dept_setting(PDO $db, string $key): array {
    $all = inq_dept_settings_all($db);
    return $all[$key] ?? ['allow_free_part'=>0, 'allow_custom_spec'=>0];
}
function inq_dept_setting_save(PDO $db, string $key, bool $allowFreePart, bool $allowCustomSpec, int $by): bool {
    if (!isset(inq_depts()[$key])) return false;
    inq_ensure_schema($db);
    try {
        $st = $db->prepare("UPDATE inq_dept_setting SET allow_free_part=?, allow_custom_spec=?, updated_by=?, updated_at=NOW() WHERE dept_key=?");
        $st->execute([$allowFreePart?1:0, $allowCustomSpec?1:0, $by, $key]);
        if ($st->rowCount() === 0) {
            $db->prepare("INSERT INTO inq_dept_setting (dept_key, allow_free_part, allow_custom_spec, updated_by, updated_at) VALUES (?,?,?,?,NOW())")
               ->execute([$key, $allowFreePart?1:0, $allowCustomSpec?1:0, $by]);
        }
        return true;
    } catch (Throwable $e) { return false; }
}

/* ════════════════════════════════════════════════════════════════════════
   子單編號：廠商代號＋民國年3碼＋MM＋DD＋流水3碼（同一天同一家廠商各自接續）
   ════════════════════════════════════════════════════════════════════════ */
define('EG_INQ_DOC_PREFIX_DEFAULT', 'RFQ');
/** 單號前綴（管理員可設定，預設 RFQ），存 system_parameters，唯一寫入點 inq_doc_prefix_save() */
function inq_doc_prefix_get(PDO $db): string {
    try {
        // system_parameters.param_value 欄位型別是 JSON，一律要 json_encode/json_decode，
        // 不能當純字串直接塞（會因為不是合法 JSON 而寫入失敗）
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='INQUIRY' AND param_key='doc_no_prefix' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        if ($v === false) return EG_INQ_DOC_PREFIX_DEFAULT;
        $decoded = json_decode((string)$v, true);
        $s = is_string($decoded) ? trim($decoded) : trim((string)$v);
        return $s !== '' ? $s : EG_INQ_DOC_PREFIX_DEFAULT;
    } catch (Throwable $e) { return EG_INQ_DOC_PREFIX_DEFAULT; }
}
function inq_doc_prefix_save(PDO $db, string $prefix, int $uid): array {
    $prefix = strtoupper(trim($prefix));
    if ($prefix === '') return ['success'=>false, 'message'=>'前綴不可空白'];
    if (!preg_match('/^[A-Z0-9]{1,8}$/', $prefix)) return ['success'=>false, 'message'=>'前綴只能是英文字母或數字，最多 8 碼'];
    try {
        $json = json_encode($prefix, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE param_group='INQUIRY' AND param_key='doc_no_prefix'");
        $st->execute([$json, $uid]);
        if ($st->rowCount() === 0) {
            $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by) VALUES ('INQUIRY','doc_no_prefix',?,?,?)")
               ->execute([$json, '詢價單單號前綴', $uid]);
        }
        return ['success'=>true, 'prefix'=>$prefix];
    } catch (Throwable $e) { error_log('[inq] doc_prefix_save: ' . $e->getMessage()); return ['success'=>false, 'message'=>'儲存失敗']; }
}

/**
 * 子單單號＝前綴＋YYYYMMDD＋流水3碼（使用者明確要求，前綴可由管理員設定，預設 RFQ）。
 * **同一天所有子單共用同一組流水號，不分廠商**（使用者原話「子單也要依照規則跳號」）——
 * 同一次詢價展開給 3 家廠商，號碼就是連續的 RFQ20261008001/002/003，不是各廠商各自起跳。
 */
function inq_next_doc_no(PDO $db, string $date): string {
    $d = $date !== '' ? $date : date('Y-m-d');
    $ymd = str_replace('-', '', substr($d, 0, 10));
    $prefix = inq_doc_prefix_get($db);
    $key = $prefix . $ymd;
    $db->prepare("INSERT INTO inq_doc_seq (seq_key, seq_no) VALUES (?, 1) ON DUPLICATE KEY UPDATE seq_no = seq_no + 1")
       ->execute([$key]);
    $st = $db->prepare("SELECT seq_no FROM inq_doc_seq WHERE seq_key=?");
    $st->execute([$key]);
    $seq = (int)$st->fetchColumn();
    return $key . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/* ════════════════════════════════════════════════════════════════════════
   廠商／料號／BOM／請購單　查詢
   ════════════════════════════════════════════════════════════════════════ */
function inq_vendor_list(PDO $db): array {
    try {
        $rows = $db->query("SELECT maker_id_no, maker_id, contact_person, contact_title, m_tel, m_tel2, m_fax, m_process_items
                            FROM maker_list WHERE status IS NULL OR status<>'X' ORDER BY maker_id_no")
                   ->fetchAll(PDO::FETCH_ASSOC);
        // 每家廠商附上「加工類別」sub_cat_id 清單，前端依此做標籤篩選（既有 master_data 廠商分頁同一套分類）
        $cat = [];
        foreach ($db->query("SELECT maker_id_no, sub_cat_id FROM maker_sub_category_mapping") as $r) {
            $cat[$r['maker_id_no']][] = (int)$r['sub_cat_id'];
        }
        foreach ($rows as &$r) { $r['sub_cat_ids'] = $cat[$r['maker_id_no']] ?? []; }
        return $rows;
    } catch (Throwable $e) { return []; }
}

/**
 * 廠商「加工類別」標籤階層（既有 dict_maker_main_category／dict_maker_sub_category／
 * maker_category_hierarchy，master_data 廠商分頁同一套，不另建分類系統）。
 * @return array [{main_cat_id, main_cat_name, subs:[{sub_cat_id, sub_cat_name, sub_cat_group}]}]
 */
function inq_vendor_categories(PDO $db): array {
    try {
        $main = $db->query("SELECT main_cat_id, main_cat_name FROM dict_maker_main_category WHERE is_active=1 ORDER BY sort_order")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($main as $m) {
            $st = $db->prepare("SELECT s.sub_cat_id, s.sub_cat_name, s.sub_cat_group
                                FROM maker_category_hierarchy h
                                JOIN dict_maker_sub_category s ON s.sub_cat_id=h.sub_cat_id AND s.is_active=1
                                WHERE h.main_cat_id=? ORDER BY h.sort_order");
            $st->execute([$m['main_cat_id']]);
            $subs = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($subs) { $out[] = ['main_cat_id'=>(int)$m['main_cat_id'], 'main_cat_name'=>$m['main_cat_name'], 'subs'=>$subs]; }
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * 製程大項（process_type）→ 廠商加工類別（sub_cat）的對照：一個製程大項通常對到唯一一個小類
 * （dict_maker_sub_category.ref_process_type_id），找不到就回 null（這個製程沒有對應的廠商分類，
 * 不強求一定要有，純生管的內部製程本來就不一定有對應的外部廠商類別）。
 */
function inq_process_type_cat(PDO $db, int $processTypeId): ?array {
    if ($processTypeId <= 0) return null;
    try {
        $st = $db->prepare("SELECT s.sub_cat_id, s.sub_cat_name, h.main_cat_id, m.main_cat_name
                            FROM dict_maker_sub_category s
                            JOIN maker_category_hierarchy h ON h.sub_cat_id=s.sub_cat_id
                            JOIN dict_maker_main_category m ON m.main_cat_id=h.main_cat_id
                            WHERE s.ref_process_type_id=? AND s.is_active=1 LIMIT 1");
        $st->execute([$processTypeId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * 製程大項清單＋管理員「建議可詢價」設定（軟性建議，不是硬性限制——生管仍可挑 BOM 內任何製程，
 * 這份設定只決定哪些製程在挑選畫面上標「建議」優先顯示）。沒有登記在 inq_process_type_setting 的
 * 一律視為「建議」（opt-out，不必先設定就能用）。
 */
function inq_process_type_list(PDO $db): array {
    inq_ensure_schema($db);
    try {
        $set = [];
        foreach ($db->query("SELECT process_type_id, enabled FROM inq_process_type_setting") as $r) {
            $set[(int)$r['process_type_id']] = (int)$r['enabled'];
        }
        $out = [];
        foreach ($db->query("SELECT process_type_id, process_type FROM process_type WHERE is_active=1 ORDER BY sort_order, process_type_id") as $r) {
            $pid = (int)$r['process_type_id'];
            $out[] = ['process_type_id'=>$pid, 'process_type'=>$r['process_type'],
                      'enabled'=>array_key_exists($pid, $set) ? $set[$pid] : 1,
                      'cat'=>inq_process_type_cat($db, $pid)];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}
function inq_process_type_setting_save(PDO $db, int $processTypeId, bool $enabled, int $uid): bool {
    inq_ensure_schema($db);
    try {
        $st = $db->prepare("UPDATE inq_process_type_setting SET enabled=?, updated_by=?, updated_at=NOW() WHERE process_type_id=?");
        $st->execute([$enabled?1:0, $uid, $processTypeId]);
        if ($st->rowCount() === 0) {
            $db->prepare("INSERT INTO inq_process_type_setting (process_type_id, enabled, updated_by, updated_at) VALUES (?,?,?,NOW())")
               ->execute([$processTypeId, $enabled?1:0, $uid]);
        }
        return true;
    } catch (Throwable $e) { return false; }
}

/**
 * 某張 BOM 底下的製程步驟（比照 qab_bom_processes() 同一種 JOIN 寫法，QA 模組的責任單位挑選器）。
 * 回傳每一關的料號規格備註（single_bet_ps，使用者要自動帶入詢價單規格欄用）、製程大項，
 * 以及是否在管理員「建議可詢價」清單內。
 */
function inq_bom_processes(PDO $db, string $bom): array {
    $bom = trim($bom);
    if ($bom === '') return [];
    try {
        $enabledMap = [];
        foreach (inq_process_type_list($db) as $pt) { $enabledMap[$pt['process_type_id']] = $pt['enabled']; }
        $st = $db->prepare("SELECT i.bom_ing_fid, i.bom_sn, i.process_no, i.single_bet_ps, i.maker_id_no,
                                   pn.ProcessName, pn.process_type_id, ml.maker_id AS vendor_name
                            FROM bom_ing i
                            LEFT JOIN process_no pn ON pn.ProcessNo=i.process_no
                            LEFT JOIN maker_list ml ON ml.maker_id_no=i.maker_id_no
                            WHERE i.bom=? AND (i.is_consumed IS NULL OR i.is_consumed=0)
                            ORDER BY i.bom_sn");
        $st->execute([$bom]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ptid = $r['process_type_id'] !== null ? (int)$r['process_type_id'] : 0;
            $out[] = [
                'bom_ing_fid' => (int)$r['bom_ing_fid'],
                'bom_sn'      => (int)$r['bom_sn'],
                'process_no'  => $r['process_no'] === null ? null : (int)$r['process_no'],
                'process_name'=> (string)($r['ProcessName'] ?? ''),
                'process_type_id' => $ptid,
                'single_bet_ps'   => (string)($r['single_bet_ps'] ?? ''),
                'vendor_name' => (string)($r['vendor_name'] ?? ''),
                'suggested'   => $ptid > 0 ? (bool)($enabledMap[$ptid] ?? true) : false,
                'cat'         => $ptid > 0 ? inq_process_type_cat($db, $ptid) : null,
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * BOM 本身綁定的料號（bom.d_id／d_setting_id），供「只綁 BOM、尚未指定製程」時自動帶出料號用。
 * d_setting_id 現場有八成是空的（見記憶 bom_d_setting_id_mostly_null），空值時退一步用 d_id 文字
 * 去精準比對 d_setting.D_Setting_Id，**只在剛好比對到唯一一筆時**才自動綁，模稜兩可寧可不綁
 * （料號文字本身仍會帶出，只是不會有主檔 id）。
 */
function inq_bom_part(PDO $db, string $bom): ?array {
    $bom = trim($bom);
    if ($bom === '') return null;
    try {
        $st = $db->prepare("SELECT d_id, d_setting_id, sqty FROM bom WHERE bom=? LIMIT 1");
        $st->execute([$bom]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $partId = $r['d_setting_id'] ? (int)$r['d_setting_id'] : null;
        $dIdText = trim((string)($r['d_id'] ?? ''));
        if (!$partId && $dIdText !== '') {
            $st2 = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? LIMIT 2");
            $st2->execute([$dIdText]);
            $cands = $st2->fetchAll(PDO::FETCH_COLUMN);
            if (count($cands) === 1) { $partId = (int)$cands[0]; }
        }
        return ['part_id'=>$partId, 'part_no'=>$dIdText, 'sqty'=>(int)$r['sqty']];
    } catch (Throwable $e) { return null; }
}

/** 某個 bom_ing_fid 完整解出：所屬 BOM、料號、規格備註、製程大項與對應廠商分類 */
function inq_bom_ing_detail(PDO $db, int $bomIngFid): ?array {
    if ($bomIngFid <= 0) return null;
    try {
        $st = $db->prepare("SELECT i.bom, i.single_bet_ps, pn.ProcessName, pn.process_type_id
                            FROM bom_ing i LEFT JOIN process_no pn ON pn.ProcessNo=i.process_no
                            WHERE i.bom_ing_fid=? LIMIT 1");
        $st->execute([$bomIngFid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $part = inq_bom_part($db, $r['bom']) ?? ['part_id'=>null, 'part_no'=>'', 'sqty'=>0];
        $ptid = $r['process_type_id'] !== null ? (int)$r['process_type_id'] : 0;
        return [
            'bom' => $r['bom'], 'part_id' => $part['part_id'], 'part_no' => $part['part_no'], 'sqty' => $part['sqty'],
            'single_bet_ps' => (string)($r['single_bet_ps'] ?? ''), 'process_name' => (string)($r['ProcessName'] ?? ''),
            'process_type_id' => $ptid, 'cat' => $ptid > 0 ? inq_process_type_cat($db, $ptid) : null,
        ];
    } catch (Throwable $e) { return null; }
}

function inq_vendor_snapshot(PDO $db, string $vendorIdNo): ?array {
    try {
        $st = $db->prepare("SELECT maker_id_no, maker_id, contact_person, m_tel, m_tel2, m_fax
                            FROM maker_list WHERE maker_id_no=? LIMIT 1");
        $st->execute([$vendorIdNo]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'vendor_id'      => $r['maker_id_no'],
            'vendor_name'    => $r['maker_id'],
            'contact_person' => (string)($r['contact_person'] ?? ''),
            'contact_phone'  => (string)($r['m_tel'] ?: $r['m_tel2'] ?: ''),
            'contact_fax'    => (string)($r['m_fax'] ?? ''),
        ];
    } catch (Throwable $e) { return null; }
}

function inq_part_search(PDO $db, string $kw, int $limit = 20): array {
    $kw = trim($kw);
    if ($kw === '') return [];
    try {
        $st = $db->prepare("SELECT d_id, D_Setting_Id, Drawing_No, Customer_Id, Remark
                            FROM d_setting WHERE D_Setting_Id LIKE ? OR Drawing_No LIKE ?
                            ORDER BY d_id DESC LIMIT ?");
        $kwLike = '%' . $kw . '%';
        $st->bindValue(1, $kwLike, PDO::PARAM_STR);
        $st->bindValue(2, $kwLike, PDO::PARAM_STR);
        $st->bindValue(3, $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'part_id' => (int)$r['d_id'],
                'part_no' => $r['D_Setting_Id'],
                'label'   => $r['D_Setting_Id'] . ($r['Customer_Id'] ? '（' . $r['Customer_Id'] . '）' : '') . ' #' . $r['d_id'],
                'spec'    => (string)($r['Remark'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}
function inq_part_get(PDO $db, int $partId): ?array {
    if ($partId <= 0) return null;
    try {
        $st = $db->prepare("SELECT d_id, D_Setting_Id, Remark FROM d_setting WHERE d_id=? LIMIT 1");
        $st->execute([$partId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['part_id'=>(int)$r['d_id'], 'part_no'=>$r['D_Setting_Id'], 'spec'=>(string)($r['Remark']??'')] : null;
    } catch (Throwable $e) { return null; }
}

function inq_bom_search(PDO $db, string $kw, int $limit = 20): array {
    $kw = trim($kw);
    if ($kw === '') return [];
    try {
        // d_id 是這張製令實際對應的料號文字（使用者要求選單要能看到料號方便確認是不是選對那一張），
        // 同時也開放用料號本身搜尋
        $st = $db->prepare("SELECT bom, d_id, Client_Name, specification, sqty FROM bom WHERE bom LIKE ? OR d_id LIKE ? ORDER BY bom DESC LIMIT ?");
        $kwLike = '%' . $kw . '%';
        $st->bindValue(1, $kwLike, PDO::PARAM_STR);
        $st->bindValue(2, $kwLike, PDO::PARAM_STR);
        $st->bindValue(3, $limit, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $spec = (string)($r['specification'] ?? '');
            $out[] = ['bom'=>$r['bom'], 'label'=>$r['bom'] . '　料號:' . (string)($r['d_id'] ?? '')
                . '（' . $r['Client_Name'] . ($spec !== '' ? '／'.$spec : '') . '×' . $r['sqty'] . '）'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function inq_preq_search(PDO $db, string $kw, int $limit = 20): array {
    $kw = trim($kw);
    try {
        $sql = "SELECT req_id, req_no, title, requester_name FROM purchase_request WHERE is_active=1";
        $params = [];
        if ($kw !== '') { $sql .= " AND (req_no LIKE ? OR title LIKE ?)"; $params = ['%'.$kw.'%', '%'.$kw.'%']; }
        $sql .= " ORDER BY req_id DESC LIMIT ?";
        $st = $db->prepare($sql);
        foreach ($params as $i => $p) { $st->bindValue($i+1, $p, PDO::PARAM_STR); }
        $st->bindValue(count($params)+1, $limit, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['ref'=>$r['req_no'], 'label'=>$r['req_no'] . '　' . $r['title'] . '（' . $r['requester_name'] . '）'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * 綁定對象的顯示文字快照（供 bind_label）。
 * bom 沒有整數主鍵（資料表本身沒有 PK），purchase_request.req_no 本身就是 UNIQUE——
 * 兩種綁定一律以**文字**當參照鍵（bind_ref），不勉強湊一個整數 id。
 */
function inq_bind_label(PDO $db, string $type, string $ref): string {
    if ($ref === '') return '';
    try {
        if ($type === 'bom') {
            $st = $db->prepare("SELECT bom, Client_Name, specification FROM bom WHERE bom=? LIMIT 1");
            $st->execute([$ref]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? ($r['bom'] . '（' . $r['Client_Name'] . '／' . $r['specification'] . '）') : $ref;
        }
        if ($type === 'purchase_request') {
            $st = $db->prepare("SELECT req_no, title FROM purchase_request WHERE req_no=? LIMIT 1");
            $st->execute([$ref]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ? ($r['req_no'] . '　' . (string)$r['title']) : $ref;
        }
    } catch (Throwable $e) {}
    return $ref;
}

/* ════════════════════════════════════════════════════════════════════════
   項目列正規化（套用部門使用規則，決定 part_id / part_no_text / spec_text 怎麼存）
   ════════════════════════════════════════════════════════════════════════ */
/**
 * 一列詢價項目可以有三種來源，優先序由高到低：
 *   ①綁 BOM 製程（bom_ing_fid）——料號／規格一律由這一關自動帶出（規格＝single_bet_ps）
 *   ②只綁 BOM（bom，未指定製程）——料號由 BOM 自動帶出，規格維持手填
 *   ③綁料號主檔（part_id）或純打字（part_no_text，需部門允許 allow_free_part）
 * 綁 BOM／BOM 製程是「真實存在的業務對象」，即使 bom.d_setting_id 解不出主檔 id（現場常見），
 * 也不受 allow_free_part 限制——那一條規則管的是「使用者純打字沒有任何依據」的情況，不是這個。
 */
function inq_normalize_item(PDO $db, array $it, array $deptSet): array {
    $bomIngFid = (int)($it['bom_ing_fid'] ?? 0);
    $bomText   = trim((string)($it['bom'] ?? ''));
    $partId = (int)($it['part_id'] ?? 0);
    $partNo = trim((string)($it['part_no_text'] ?? ''));
    $spec   = trim((string)($it['spec_text'] ?? ''));
    $qty    = isset($it['qty']) && $it['qty'] !== '' ? (float)$it['qty'] : null;
    $note   = trim((string)($it['note'] ?? ''));
    $procTypeId = 0;
    $boundByBom = false;

    if ($bomIngFid > 0) {
        $d = inq_bom_ing_detail($db, $bomIngFid);
        if (!$d) return ['_invalid' => '綁定的 BOM 製程已不存在，請重新選擇'];
        $bomText = $d['bom'];
        $partId  = (int)($d['part_id'] ?: 0);
        if ($d['part_no'] !== '') $partNo = $d['part_no'];
        if (!$deptSet['allow_custom_spec'] || $spec === '') { $spec = $d['single_bet_ps']; }
        $procTypeId = (int)$d['process_type_id'];
        $boundByBom = true;
    } elseif ($bomText !== '') {
        $bp = inq_bom_part($db, $bomText);
        if (!$bp) return ['_invalid' => '綁定的 BOM「' . $bomText . '」已不存在，請重新選擇'];
        $partId = (int)($bp['part_id'] ?: 0);
        if ($bp['part_no'] !== '') $partNo = $bp['part_no'];
        $boundByBom = true;
    }

    if ($partId > 0) {
        $p = inq_part_get($db, $partId);
        if (!$p) { $partId = 0; }
        else {
            $partNo = $p['part_no'];
            // 綁 BOM 製程時規格已經由上面的 single_bet_ps 決定，不要被料號主檔的備註蓋掉
            if (!$boundByBom && !$deptSet['allow_custom_spec']) { $spec = $p['spec']; }
        }
    } elseif (!$boundByBom && !$deptSet['allow_free_part']) {
        // 不允許非實際存在之料號，且這一列不是靠 BOM／BOM製程帶出來的：純打字視為不合法
        if ($partNo !== '') { return ['_invalid' => '產品編號「' . $partNo . '」未綁定實際料號主檔（本部門不允許自訂料號）']; }
    }
    $cat = $procTypeId > 0 ? inq_process_type_cat($db, $procTypeId) : null;
    return ['part_id'=>$partId ?: null, 'part_no_text'=>$partNo, 'spec_text'=>$spec, 'qty'=>$qty, 'note'=>$note ?: null,
            'bom'=>$bomText ?: null, 'bom_ing_fid'=>$bomIngFid ?: null, '_process_type_id'=>$procTypeId,
            '_main_cat_id'=>$cat['main_cat_id'] ?? null, '_main_cat_name'=>$cat['main_cat_name'] ?? ''];
}

/**
 * 同一張詢價單（母單或子單）若有多列綁了 BOM 製程，這些製程對到的「廠商加工大類」必須一致，
 * 避免一張單混著問加工廠的製程又問耗材供應商，讓收到單的廠商看得一頭霧水（使用者明確要求）。
 * 沒有對到任何廠商大類的製程（$it['_process_type_id'] 解不出 cat）不受此限制。
 * @param array $normItems inq_normalize_item() 的回傳值陣列
 * @return string 不合法時的錯誤訊息；合法回傳空字串
 */
function inq_items_same_main_cat_check(array $normItems): string {
    $mainCatId = null; $mainCatName = ''; $firstLabel = '';
    foreach ($normItems as $n) {
        if (empty($n['bom_ing_fid']) || empty($n['_main_cat_id'])) continue;
        if ($mainCatId === null) { $mainCatId = (int)$n['_main_cat_id']; $mainCatName = (string)$n['_main_cat_name']; $firstLabel = (string)($n['part_no_text'] ?? ''); continue; }
        if ((int)$n['_main_cat_id'] !== $mainCatId) {
            return '詢價項目的製程類別不一致：「' . $firstLabel . '」是「' . $mainCatName . '」，「' . ($n['part_no_text'] ?? '') . '」卻是「' . $n['_main_cat_name'] . '」——同一張詢價單的 BOM 製程必須是同一個加工類別大類，避免廠商混亂。';
        }
    }
    return '';
}

/* ════════════════════════════════════════════════════════════════════════
   建立詢價案（母單＋項目＋一家或多家廠商＝自動展開成多張子單）
   ════════════════════════════════════════════════════════════════════════ */
function inq_group_create(PDO $db, array $p, int $uid, string $uname): array {
    inq_ensure_schema($db);
    $dept = (string)($p['source_dept'] ?? '');
    if (!isset(inq_depts()[$dept])) return ['success'=>false, 'message'=>'來源部門不合法'];
    $items = is_array($p['items'] ?? null) ? $p['items'] : [];
    $vendorIds = is_array($p['vendor_ids'] ?? null) ? array_values(array_unique(array_filter(array_map('strval', $p['vendor_ids'])))) : [];
    if (!$items) return ['success'=>false, 'message'=>'至少要有一列詢價項目'];
    if (!$vendorIds) return ['success'=>false, 'message'=>'至少要選一家廠商'];

    $deptSet = inq_dept_setting($db, $dept);
    $normItems = [];
    foreach ($items as $it) {
        $n = inq_normalize_item($db, $it, $deptSet);
        if (!empty($n['_invalid'])) return ['success'=>false, 'message'=>$n['_invalid']];
        if ($n['part_id'] === null && $n['part_no_text'] === '' && $n['spec_text'] === '') continue; // 空列略過
        $normItems[] = $n;
    }
    if (!$normItems) return ['success'=>false, 'message'=>'至少要有一列詢價項目'];
    $catErr = inq_items_same_main_cat_check($normItems);
    if ($catErr !== '') return ['success'=>false, 'message'=>$catErr];

    $bindType = (string)($p['bind_type'] ?? '');
    $bindRef  = trim((string)($p['bind_ref'] ?? ''));
    $bindLabel = ($bindType && $bindRef) ? inq_bind_label($db, $bindType, $bindRef) : null;

    // 詢價人員＝本人、詢價日期＝伺服器今天，一律不採信前端送來的值（使用者明確要求不可修改）
    $date    = date('Y-m-d');
    $reqId   = $uid;
    $reqName = $uname;

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO inq_group
            (source_dept, bind_type, bind_ref, bind_label, requester_id, requester_name, inquiry_date, currency, note, created_by, created_at, modified_by, modified_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),?,NOW())")
           ->execute([$dept, $bindType ?: null, $bindRef ?: null, $bindLabel, $reqId, $reqName, $date,
                      (string)($p['currency'] ?? 'NTD') ?: 'NTD', trim((string)($p['note'] ?? '')) ?: null, $uid, $uid]);
        $groupId = (int)$db->lastInsertId();

        $giIns = $db->prepare("INSERT INTO inq_group_item (group_id, seq, part_id, part_no_text, spec_text, qty, note, bom, bom_ing_fid) VALUES (?,?,?,?,?,?,?,?,?)");
        $gItems = [];
        $seq = 1;
        foreach ($normItems as $n) {
            $giIns->execute([$groupId, $seq, $n['part_id'], $n['part_no_text'], $n['spec_text'], $n['qty'], $n['note'], $n['bom'], $n['bom_ing_fid']]);
            $gItems[] = ['id'=>(int)$db->lastInsertId()] + $n;
            $seq++;
        }

        $docIns  = $db->prepare("INSERT INTO inq_doc (group_id, doc_no, vendor_id, vendor_name, contact_person, contact_phone, contact_fax, created_by, created_at, modified_by, modified_at)
                                  VALUES (?,?,?,?,?,?,?,?,NOW(),?,NOW())");
        $diIns   = $db->prepare("INSERT INTO inq_doc_item (doc_id, group_item_id, seq, part_id, part_no_text, spec_text, qty, note, bom, bom_ing_fid) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $docIds  = [];
        foreach ($vendorIds as $vid) {
            $snap = inq_vendor_snapshot($db, $vid);
            if (!$snap) continue;   // 不存在的廠商代號直接略過
            $docNo = inq_next_doc_no($db, $date);
            $docIns->execute([$groupId, $docNo, $snap['vendor_id'], $snap['vendor_name'], $snap['contact_person'], $snap['contact_phone'], $snap['contact_fax'], $uid, $uid]);
            $docId = (int)$db->lastInsertId();
            $docIds[] = $docId;
            $s = 1;
            foreach ($gItems as $gi) {
                $diIns->execute([$docId, $gi['id'], $s, $gi['part_id'], $gi['part_no_text'], $gi['spec_text'], $gi['qty'], $gi['note'], $gi['bom'], $gi['bom_ing_fid']]);
                $s++;
            }
        }
        if (!$docIds) { $db->rollBack(); return ['success'=>false, 'message'=>'選擇的廠商代號皆不存在'];}
        $db->commit();
        return ['success'=>true, 'group_id'=>$groupId, 'doc_ids'=>$docIds];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[inq] group_create: ' . $e->getMessage());
        return ['success'=>false, 'message'=>'建立失敗'];
    }
}

/** 既有詢價案追加一家（或多家）廠商：由母單目前的項目複製成新的子單 */
function inq_group_add_vendors(PDO $db, int $groupId, array $vendorIds, int $uid): array {
    $g = inq_group_row($db, $groupId);
    if (!$g) return ['success'=>false, 'message'=>'找不到這個詢價案'];
    $gItems = inq_group_items($db, $groupId);
    $vendorIds = array_values(array_unique(array_filter(array_map('strval', $vendorIds))));
    if (!$vendorIds) return ['success'=>false, 'message'=>'至少要選一家廠商'];
    // 這個詢價案裡已經有的廠商不重複展開（直打 API 繞過畫面的 disabledIds 時的第二道防線）
    $already = array_column(inq_group_docs($db, $groupId), 'vendor_id');
    $vendorIds = array_values(array_diff($vendorIds, $already));
    if (!$vendorIds) return ['success'=>false, 'message'=>'選擇的廠商都已經在這個詢價案裡了'];

    $db->beginTransaction();
    try {
        $docIns = $db->prepare("INSERT INTO inq_doc (group_id, doc_no, vendor_id, vendor_name, contact_person, contact_phone, contact_fax, created_by, created_at, modified_by, modified_at)
                                 VALUES (?,?,?,?,?,?,?,?,NOW(),?,NOW())");
        $diIns  = $db->prepare("INSERT INTO inq_doc_item (doc_id, group_item_id, seq, part_id, part_no_text, spec_text, qty, note, bom, bom_ing_fid) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $newIds = [];
        foreach ($vendorIds as $vid) {
            $snap = inq_vendor_snapshot($db, $vid);
            if (!$snap) continue;
            $docNo = inq_next_doc_no($db, $g['inquiry_date']);
            $docIns->execute([$groupId, $docNo, $snap['vendor_id'], $snap['vendor_name'], $snap['contact_person'], $snap['contact_phone'], $snap['contact_fax'], $uid, $uid]);
            $docId = (int)$db->lastInsertId();
            $newIds[] = $docId;
            $s = 1;
            foreach ($gItems as $gi) {
                $diIns->execute([$docId, $gi['id'], $s, $gi['part_id'], $gi['part_no_text'], $gi['spec_text'], $gi['qty'], $gi['note'], $gi['bom'], $gi['bom_ing_fid']]);
                $s++;
            }
        }
        if (!$newIds) { $db->rollBack(); return ['success'=>false, 'message'=>'選擇的廠商代號皆不存在或已經在這個詢價案裡']; }
        $db->commit();
        return ['success'=>true, 'doc_ids'=>$newIds];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[inq] add_vendors: ' . $e->getMessage());
        return ['success'=>false, 'message'=>'新增廠商失敗'];
    }
}

/* ════════════════════════════════════════════════════════════════════════
   讀取
   ════════════════════════════════════════════════════════════════════════ */
function inq_group_row(PDO $db, int $groupId): ?array {
    $st = $db->prepare("SELECT * FROM inq_group WHERE id=? AND deleted_at IS NULL");
    $st->execute([$groupId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function inq_group_items(PDO $db, int $groupId): array {
    $st = $db->prepare("SELECT * FROM inq_group_item WHERE group_id=? ORDER BY seq, id");
    $st->execute([$groupId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function inq_doc_row(PDO $db, int $docId): ?array {
    $st = $db->prepare("SELECT * FROM inq_doc WHERE id=? AND deleted_at IS NULL");
    $st->execute([$docId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function inq_doc_items(PDO $db, int $docId): array {
    $st = $db->prepare("SELECT * FROM inq_doc_item WHERE doc_id=? ORDER BY seq, id");
    $st->execute([$docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function inq_group_docs(PDO $db, int $groupId): array {
    $st = $db->prepare("SELECT * FROM inq_doc WHERE group_id=? AND deleted_at IS NULL ORDER BY id");
    $st->execute([$groupId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 詢價案完整內容（母單＋項目＋全部子單＋各自項目），給編輯畫面與列印用 */
function inq_group_full(PDO $db, int $groupId): ?array {
    $g = inq_group_row($db, $groupId);
    if (!$g) return null;
    $g['items'] = inq_group_items($db, $groupId);
    $docs = inq_group_docs($db, $groupId);
    foreach ($docs as &$d) { $d['items'] = inq_doc_items($db, (int)$d['id']); }
    $g['docs'] = $docs;
    return $g;
}

/** 清單（供主頁面列表用） */
function inq_group_list(PDO $db, array $opt = []): array {
    inq_ensure_schema($db);
    $where = ['g.deleted_at IS NULL'];
    $params = [];
    if (!empty($opt['dept'])) { $where[] = 'g.source_dept=?'; $params[] = $opt['dept']; }
    if (!empty($opt['status'])) { $where[] = 'g.status=?'; $params[] = $opt['status']; }
    if (!empty($opt['date_from'])) { $where[] = 'g.inquiry_date>=?'; $params[] = $opt['date_from']; }
    if (!empty($opt['date_to']))   { $where[] = 'g.inquiry_date<=?'; $params[] = $opt['date_to']; }
    if (!empty($opt['kw'])) {
        $where[] = '(g.requester_name LIKE ? OR g.note LIKE ? OR g.bind_label LIKE ?
                     OR EXISTS(SELECT 1 FROM inq_doc d2 WHERE d2.group_id=g.id AND d2.deleted_at IS NULL
                               AND (d2.vendor_name LIKE ? OR d2.doc_no LIKE ?))
                     OR EXISTS(SELECT 1 FROM inq_group_item gi2 WHERE gi2.group_id=g.id
                               AND (gi2.part_no_text LIKE ? OR gi2.spec_text LIKE ?)))';
        $kw = '%' . $opt['kw'] . '%';
        array_push($params, $kw, $kw, $kw, $kw, $kw, $kw, $kw);
    }
    $sql = "SELECT g.*,
                   (SELECT COUNT(*) FROM inq_doc d WHERE d.group_id=g.id AND d.deleted_at IS NULL) AS doc_count,
                   (SELECT GROUP_CONCAT(d.vendor_name SEPARATOR '、') FROM inq_doc d WHERE d.group_id=g.id AND d.deleted_at IS NULL) AS vendor_names
            FROM inq_group g WHERE " . implode(' AND ', $where) . " ORDER BY g.id DESC LIMIT 500";
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ════════════════════════════════════════════════════════════════════════
   編輯母單（會同步到所有 follow_parent=1 的子單）
   ════════════════════════════════════════════════════════════════════════ */
function inq_group_update(PDO $db, int $groupId, array $p, int $uid): array {
    $g = inq_group_row($db, $groupId);
    if (!$g) return ['success'=>false, 'message'=>'找不到這個詢價案'];
    $deptSet = inq_dept_setting($db, $g['source_dept']);
    $items = is_array($p['items'] ?? null) ? $p['items'] : null;

    $db->beginTransaction();
    try {
        // 表頭：來源部門／廠商／詢價人員／詢價日期一律不給改（前端已鎖，這裡再擋一次，鐵律8）——
        // 詢價人員＝建立者本人、詢價日期＝建立當天的事實，不是可回頭編輯的欄位。
        $curr = (string)($p['currency'] ?? $g['currency']) ?: 'NTD';
        $note = array_key_exists('note', $p) ? (trim((string)$p['note']) ?: null) : $g['note'];
        $db->prepare("UPDATE inq_group SET currency=?, note=?, modified_by=?, modified_at=NOW() WHERE id=?")
           ->execute([$curr, $note, $uid, $groupId]);

        if ($items !== null) {
            $normItems = [];
            foreach ($items as $it) {
                $n = inq_normalize_item($db, $it, $deptSet);
                if (!empty($n['_invalid'])) { $db->rollBack(); return ['success'=>false, 'message'=>$n['_invalid']]; }
                if ($n['part_id'] === null && $n['part_no_text'] === '' && $n['spec_text'] === '') continue;
                $normItems[] = $n;
            }
            if (!$normItems) { $db->rollBack(); return ['success'=>false, 'message'=>'至少要有一列詢價項目']; }
            $catErr = inq_items_same_main_cat_check($normItems);
            if ($catErr !== '') { $db->rollBack(); return ['success'=>false, 'message'=>$catErr]; }

            // 母單項目整批重建（簡單可靠；項目列數通常不多）
            $db->prepare("DELETE FROM inq_group_item WHERE group_id=?")->execute([$groupId]);
            $giIns = $db->prepare("INSERT INTO inq_group_item (group_id, seq, part_id, part_no_text, spec_text, qty, note, bom, bom_ing_fid) VALUES (?,?,?,?,?,?,?,?,?)");
            $newGItems = [];
            $seq = 1;
            foreach ($normItems as $n) {
                $giIns->execute([$groupId, $seq, $n['part_id'], $n['part_no_text'], $n['spec_text'], $n['qty'], $n['note'], $n['bom'], $n['bom_ing_fid']]);
                $newGItems[] = ['id'=>(int)$db->lastInsertId()] + $n;
                $seq++;
            }

            // 同步到所有「跟隨母單」的子單：整批重建那些子單的項目（脫離跟隨的子單完全不動）
            $docs = $db->prepare("SELECT id FROM inq_doc WHERE group_id=? AND deleted_at IS NULL AND follow_parent=1");
            $docs->execute([$groupId]);
            $diIns = $db->prepare("INSERT INTO inq_doc_item (doc_id, group_item_id, seq, part_id, part_no_text, spec_text, qty, note, bom, bom_ing_fid) VALUES (?,?,?,?,?,?,?,?,?,?)");
            foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $docId) {
                $db->prepare("DELETE FROM inq_doc_item WHERE doc_id=?")->execute([$docId]);
                $s = 1;
                foreach ($newGItems as $gi) {
                    $diIns->execute([$docId, $gi['id'], $s, $gi['part_id'], $gi['part_no_text'], $gi['spec_text'], $gi['qty'], $gi['note'], $gi['bom'], $gi['bom_ing_fid']]);
                    $s++;
                }
            }
        }
        $db->commit();
        return ['success'=>true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[inq] group_update: ' . $e->getMessage());
        return ['success'=>false, 'message'=>'儲存失敗'];
    }
}

/** 後補／改掉綁定對象（bom／purchase_request），或清空（$bindRef=''＝解除綁定） */
function inq_group_bind(PDO $db, int $groupId, string $bindType, string $bindRef, int $uid): array {
    $g = inq_group_row($db, $groupId);
    if (!$g) return ['success'=>false, 'message'=>'找不到這個詢價案'];
    // 'bom' 已改為逐項目綁定（inq_group_item.bom/bom_ing_fid），母單層級只剩『請購單』可綁
    if ($bindType !== '' && !in_array($bindType, ['purchase_request'], true)) {
        return ['success'=>false, 'message'=>'綁定類型不合法'];
    }
    $bindRef = trim($bindRef);
    $label = ($bindType && $bindRef) ? inq_bind_label($db, $bindType, $bindRef) : null;
    try {
        $db->prepare("UPDATE inq_group SET bind_type=?, bind_ref=?, bind_label=?, modified_by=?, modified_at=NOW() WHERE id=?")
           ->execute([$bindType ?: null, $bindRef ?: null, $label, $uid, $groupId]);
        return ['success'=>true, 'bind_label'=>$label];
    } catch (Throwable $e) { return ['success'=>false, 'message'=>'儲存失敗']; }
}

/* ════════════════════════════════════════════════════════════════════════
   編輯子單（直接改內容＝脫離跟隨；只改單價或狀態不影響跟隨關係）
   ════════════════════════════════════════════════════════════════════════ */
function inq_doc_update(PDO $db, int $docId, array $p, int $uid): array {
    $d = inq_doc_row($db, $docId);
    if (!$d) return ['success'=>false, 'message'=>'找不到這張子單'];
    $g = inq_group_row($db, (int)$d['group_id']);
    $deptSet = $g ? inq_dept_setting($db, $g['source_dept']) : ['allow_free_part'=>0,'allow_custom_spec'=>0];

    $items = is_array($p['items'] ?? null) ? $p['items'] : null;
    $db->beginTransaction();
    try {
        if ($items !== null) {
            $normItems = [];
            foreach ($items as $it) {
                $n = inq_normalize_item($db, $it, $deptSet);
                if (!empty($n['_invalid'])) { $db->rollBack(); return ['success'=>false, 'message'=>$n['_invalid']]; }
                $price = isset($it['unit_price']) && $it['unit_price'] !== '' ? (float)$it['unit_price'] : null;
                if ($n['part_id'] === null && $n['part_no_text'] === '' && $n['spec_text'] === '' && $price === null) continue;
                $n['unit_price'] = $price;
                $n['group_item_id'] = isset($it['group_item_id']) ? (int)$it['group_item_id'] ?: null : null;
                $normItems[] = $n;
            }
            $catErr = inq_items_same_main_cat_check($normItems);
            if ($catErr !== '') { $db->rollBack(); return ['success'=>false, 'message'=>$catErr]; }

            // 內容簽章：qty 兩邊都要過同一種格式化（DB 的 DECIMAL 欄位讀回來是固定小數位的字串如
            // "5.000"，normalize 後的新值是 PHP float 轉字串會變成 "5"，直接比字串一定會判成「有改」
            // ——這是只登記單價、沒動內容卻還是被判定脫離跟隨的根因，兩邊一律先轉 float 再比）。
            $sigOf = function ($r) {
                $q = isset($r['qty']) && $r['qty'] !== null && $r['qty'] !== '' ? (string)(float)$r['qty'] : '';
                return $r['part_id'] . '|' . $r['part_no_text'] . '|' . $r['spec_text'] . '|' . $q . '|' . ($r['bom'] ?? '') . '|' . ($r['bom_ing_fid'] ?? '');
            };
            $old = inq_doc_items($db, $docId);
            $oldSig = array_map($sigOf, $old);
            $newSig = array_map($sigOf, $normItems);
            $contentChanged = ($oldSig !== $newSig);

            $db->prepare("DELETE FROM inq_doc_item WHERE doc_id=?")->execute([$docId]);
            $diIns = $db->prepare("INSERT INTO inq_doc_item (doc_id, group_item_id, seq, part_id, part_no_text, spec_text, qty, unit_price, note, bom, bom_ing_fid) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $seq = 1;
            foreach ($normItems as $n) {
                $diIns->execute([$docId, $n['group_item_id'], $seq, $n['part_id'], $n['part_no_text'], $n['spec_text'], $n['qty'], $n['unit_price'], $n['note'], $n['bom'], $n['bom_ing_fid']]);
                $seq++;
            }
            // 內容（料號/規格/數量/列數）真的不同才脫離跟隨；只改單價不算
            if ($contentChanged && (int)$d['follow_parent'] === 1) {
                $db->prepare("UPDATE inq_doc SET follow_parent=0 WHERE id=?")->execute([$docId]);
            }
        }
        $status = (string)($p['status'] ?? '');
        if (in_array($status, ['open','replied','void'], true)) {
            $db->prepare("UPDATE inq_doc SET status=? WHERE id=?")->execute([$status, $docId]);
        }
        $db->prepare("UPDATE inq_doc SET modified_by=?, modified_at=NOW() WHERE id=?")->execute([$uid, $docId]);
        $db->commit();
        return ['success'=>true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[inq] doc_update: ' . $e->getMessage());
        return ['success'=>false, 'message'=>'儲存失敗'];
    }
}

/** 回填廠商報價：跳窗只能改「單價」與「廠商報價備註」，其餘欄位（料號/規格/數量/綁定）
 * 完全不經過 inq_normalize_item／內容簽章判斷，絕對不會影響 follow_parent 跟隨狀態；
 * 一律記錄回填人與回填時間（inq_doc.price_filled_by/_by_name/_at）。 */
function inq_doc_price_fill(PDO $db, int $docId, array $items, int $uid, string $uname): array {
    $d = inq_doc_row($db, $docId);
    if (!$d) return ['success'=>false, 'message'=>'找不到這張子單'];
    $existing = inq_doc_items($db, $docId);
    $byId = [];
    foreach ($existing as $r) { $byId[(int)$r['id']] = true; }
    $anyPrice = false;
    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE inq_doc_item SET unit_price=?, vendor_note=? WHERE id=? AND doc_id=?");
        foreach ($items as $it) {
            $itemId = (int)($it['id'] ?? 0);
            if ($itemId <= 0 || !isset($byId[$itemId])) continue; // 只能動這張子單自己的項目
            $price = (isset($it['unit_price']) && $it['unit_price'] !== '' && $it['unit_price'] !== null) ? (float)$it['unit_price'] : null;
            if ($price !== null) $anyPrice = true;
            $note = isset($it['vendor_note']) ? (trim((string)$it['vendor_note']) ?: null) : null;
            $upd->execute([$price, $note, $itemId, $docId]);
        }
        // 有填任一單價，且這張單還停在「等待報價」時，自動帶成「已回覆」——回填價格就是收到報價了，
        // 不應該還要使用者另外再手動切換狀態（void 這種特殊狀態不自動覆蓋）。
        $statusSql = ($anyPrice && $d['status'] === 'open') ? ", status='replied'" : '';
        $db->prepare("UPDATE inq_doc SET price_filled_by=?, price_filled_by_name=?, price_filled_at=NOW(), modified_by=?, modified_at=NOW()$statusSql WHERE id=?")
           ->execute([$uid, $uname, $uid, $docId]);
        $db->commit();
        return ['success'=>true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[inq] doc_price_fill: ' . $e->getMessage());
        return ['success'=>false, 'message'=>'儲存失敗'];
    }
}

/** 手動把子單重新接回跟隨母單（會立刻依母單目前內容整批覆蓋這張子單的項目，單價一併清空） */
function inq_doc_refollow(PDO $db, int $docId, int $uid): array {
    $d = inq_doc_row($db, $docId);
    if (!$d) return ['success'=>false, 'message'=>'找不到這張子單'];
    $gItems = inq_group_items($db, (int)$d['group_id']);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM inq_doc_item WHERE doc_id=?")->execute([$docId]);
        $diIns = $db->prepare("INSERT INTO inq_doc_item (doc_id, group_item_id, seq, part_id, part_no_text, spec_text, qty, note, bom, bom_ing_fid) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $s = 1;
        foreach ($gItems as $gi) {
            $diIns->execute([$docId, $gi['id'], $s, $gi['part_id'], $gi['part_no_text'], $gi['spec_text'], $gi['qty'], $gi['note'], $gi['bom'], $gi['bom_ing_fid']]);
            $s++;
        }
        $db->prepare("UPDATE inq_doc SET follow_parent=1, modified_by=?, modified_at=NOW() WHERE id=?")->execute([$uid, $docId]);
        $db->commit();
        return ['success'=>true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success'=>false, 'message'=>'操作失敗'];
    }
}

function inq_doc_delete(PDO $db, int $docId, int $uid): array {
    $d = inq_doc_row($db, $docId);
    if (!$d) return ['success'=>false, 'message'=>'找不到這張子單'];
    try {
        $db->prepare("UPDATE inq_doc SET deleted_at=NOW(), deleted_by=? WHERE id=?")->execute([$uid, $docId]);
        return ['success'=>true];
    } catch (Throwable $e) { return ['success'=>false, 'message'=>'刪除失敗']; }
}

function inq_group_delete(PDO $db, int $groupId, int $uid): array {
    $g = inq_group_row($db, $groupId);
    if (!$g) return ['success'=>false, 'message'=>'找不到這個詢價案'];
    try {
        $db->prepare("UPDATE inq_group SET deleted_at=NOW(), deleted_by=? WHERE id=?")->execute([$uid, $groupId]);
        $db->prepare("UPDATE inq_doc SET deleted_at=NOW(), deleted_by=? WHERE group_id=? AND deleted_at IS NULL")->execute([$uid, $groupId]);
        return ['success'=>true];
    } catch (Throwable $e) { return ['success'=>false, 'message'=>'刪除失敗']; }
}

function inq_group_set_status(PDO $db, int $groupId, string $status, int $uid): array {
    if (!in_array($status, ['open','closed'], true)) return ['success'=>false, 'message'=>'狀態不合法'];
    try {
        $db->prepare("UPDATE inq_group SET status=?, modified_by=?, modified_at=NOW() WHERE id=?")->execute([$status, $uid, $groupId]);
        return ['success'=>true];
    } catch (Throwable $e) { return ['success'=>false, 'message'=>'操作失敗']; }
}

/* ════════════════════════════════════════════════════════════════════════
   公司資料（發票全名/地址/電話/傳真，動態取，禁寫死——ai-rules/16）
   ════════════════════════════════════════════════════════════════════════ */
function inq_own_company(PDO $db): array {
    try {
        $r = $db->query("SELECT customer_full, customer_tel, customer_fax, customer_address
                          FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $r ?: ['customer_full'=>'', 'customer_tel'=>'', 'customer_fax'=>'', 'customer_address'=>''];
    } catch (Throwable $e) { return ['customer_full'=>'', 'customer_tel'=>'', 'customer_fax'=>'', 'customer_address'=>'']; }
}

/** 列印一張子單所需的全部資料（公司資訊／詢價案表頭／廠商／項目／AS 文件編號） */
function inq_print_doc_data(PDO $db, int $docId): ?array {
    $d = inq_doc_row($db, $docId);
    if (!$d) return null;
    $g = inq_group_row($db, (int)$d['group_id']);
    if (!$g) return null;
    $d['items'] = inq_doc_items($db, $docId);
    return ['doc'=>$d, 'group'=>$g, 'company'=>inq_own_company($db)];
}

/* ════════════════════════════════════════════════════════════════════════
   備註常用用語（分「部門」／「公開」；任何能新增詢價單的人可管理自己設定的項目，
   管理員額外可編輯/刪除別人的——使用者 2026-10-08 明確要求）
   ════════════════════════════════════════════════════════════════════════ */

/** 給這個人看（可選用）的範本：公開的全部 + 他自己任職/兼任部門的「部門」範本 */
function inq_note_tpl_list(PDO $db, array $myDepts, int $uid, bool $isAdmin): array {
    inq_ensure_schema($db);
    try {
        $where = ["scope='public'"];
        $params = [];
        if ($myDepts) {
            $ph = implode(',', array_fill(0, count($myDepts), '?'));
            $where[] = "(scope='dept' AND dept_key IN ($ph))";
            $params = $myDepts;
        }
        $sql = "SELECT * FROM inq_note_tpl WHERE " . implode(' OR ', $where) . " ORDER BY scope, dept_key, id DESC";
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['can_edit'] = $isAdmin || (int)$r['created_by'] === $uid; }
        return $rows;
    } catch (Throwable $e) { return []; }
}

/** $id=0 新增，否則編輯；編輯只能是本人設定的或管理員 */
function inq_note_tpl_save(PDO $db, int $id, string $scope, string $deptKey, string $content, array $myDepts, int $uid, string $uname, bool $isAdmin): array {
    $content = trim($content);
    if ($content === '') return ['success'=>false, 'message'=>'內容不可空白'];
    if (!in_array($scope, ['dept','public'], true)) return ['success'=>false, 'message'=>'範圍不合法'];
    if ($scope === 'dept') {
        if (!isset(inq_depts()[$deptKey])) return ['success'=>false, 'message'=>'部門不合法'];
        if (!$isAdmin && !in_array($deptKey, $myDepts, true)) return ['success'=>false, 'message'=>'不是這個部門的人員，不能設定這個部門的用語'];
    } else { $deptKey = ''; }

    inq_ensure_schema($db);
    try {
        if ($id > 0) {
            $st = $db->prepare("SELECT created_by FROM inq_note_tpl WHERE id=?");
            $st->execute([$id]);
            $owner = $st->fetchColumn();
            if ($owner === false) return ['success'=>false, 'message'=>'找不到這個範本'];
            if (!$isAdmin && (int)$owner !== $uid) return ['success'=>false, 'message'=>'只能編輯自己設定的範本'];
            $db->prepare("UPDATE inq_note_tpl SET scope=?, dept_key=?, content=? WHERE id=?")
               ->execute([$scope, $deptKey ?: null, $content, $id]);
        } else {
            $db->prepare("INSERT INTO inq_note_tpl (scope, dept_key, content, created_by, created_by_name, created_at) VALUES (?,?,?,?,?,NOW())")
               ->execute([$scope, $deptKey ?: null, $content, $uid, $uname]);
            $id = (int)$db->lastInsertId();
        }
        return ['success'=>true, 'id'=>$id];
    } catch (Throwable $e) { return ['success'=>false, 'message'=>'儲存失敗']; }
}

function inq_note_tpl_delete(PDO $db, int $id, int $uid, bool $isAdmin): array {
    try {
        $st = $db->prepare("SELECT created_by FROM inq_note_tpl WHERE id=?");
        $st->execute([$id]);
        $owner = $st->fetchColumn();
        if ($owner === false) return ['success'=>false, 'message'=>'找不到這個範本'];
        if (!$isAdmin && (int)$owner !== $uid) return ['success'=>false, 'message'=>'只能刪除自己設定的範本'];
        $db->prepare("DELETE FROM inq_note_tpl WHERE id=?")->execute([$id]);
        return ['success'=>true];
    } catch (Throwable $e) { return ['success'=>false, 'message'=>'刪除失敗']; }
}

}
