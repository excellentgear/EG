<?php
/**
 * 表格模板（Sheet Form）共用函式庫 — 2026-10-14 新增
 *
 * 用「像 Excel 的格子」設計表單模板，再依模板填寫、列印、（第二階段）送簽。
 * 取代 review_form 那種「固定欄位表格」的模板管理；舊模組不動、不遷移。
 *
 * 前端試算表元件：Univer（Apache-2.0，免費；已用 esbuild 打包成單檔放 resource/js/eg_univer.js，
 *   不走 CDN）。注意：Univer 的「儲存格保護」屬付費版功能，這裡不用——記入模式的鎖定改由
 *   ①前端攔截編輯 ②送出時後端用 fields_json 重新驗證（只收「待填欄位」的值）雙重把關。
 *
 * 資料模型（關鍵設計）：
 *  - sf_template.book_json   ＝ Univer 整份活頁簿快照（含樣式／合併／公式／資料驗證）
 *  - 「待填欄位」不另存一份對照表，而是寫在儲存格自己的 custom.sf（隨列插入自動位移），
 *    存模板時由 sf_book_fields() 掃出來存成 sf_template.fields_json（唯一事實來源是 book_json）。
 *  - sf_instance 不存整份活頁簿，只存「欄位 id → 值」（values_json）；建立時把當時的模板快照複製一份
 *    （tpl_book_json／tpl_fields_json），之後模板再怎麼改都不影響已填的單據（可追溯）。
 *  - 明細列：同一列上 custom.sf.g 非空的欄位屬於明細列；填寫時可「加一列」，extra_rows＝多加了幾列，
 *    明細欄位的值是陣列（長度＝extra_rows+1）。第一版限一個明細列。
 */

require_once __DIR__ . '/asdoc_lib.php';

const SF_TYPES = ['text', 'number', 'date', 'select', 'checkbox', 'person'];
const SF_MAX_BOOK_BYTES = 8 * 1024 * 1024;

function sf_asdoc_module(int $tid): string { return 'sheet_form_tpl_' . $tid; }

/* ============================================================ schema ============================================================ */

function sf_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $has = function (string $t) use ($db): bool {
        $st = $db->prepare("SHOW TABLES LIKE ?");
        $st->execute([$t]);
        return (bool)$st->fetchColumn();
    };
    // DDL 會造成隱式 commit，交易中不可執行（比照 eg_org_ensure_schema 的教訓）
    if ($db->inTransaction()) { $done = true; return; }
    if (!$has('sf_template')) {
        $db->exec("CREATE TABLE sf_template (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            paper_size ENUM('A4','A3') NOT NULL DEFAULT 'A4',
            orientation ENUM('portrait','landscape') NOT NULL DEFAULT 'landscape',
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            version INT NOT NULL DEFAULT 1,
            book_json LONGTEXT NULL COMMENT 'Univer 活頁簿快照',
            fields_json MEDIUMTEXT NULL COMMENT '由 book_json 掃出的待填欄位清單（衍生資料）',
            created_by INT NULL, created_at DATETIME NULL,
            modified_by INT NULL, modified_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表格模板'");
    }
    if (!$has('sf_instance')) {
        $db->exec("CREATE TABLE sf_instance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            tpl_version INT NOT NULL DEFAULT 1,
            title VARCHAR(150) NOT NULL DEFAULT '',
            biz_date DATE NULL COMMENT '業務日期（表單上的日期，列印 AS 版次依它回推）',
            status ENUM('draft','done') NOT NULL DEFAULT 'draft',
            tpl_book_json LONGTEXT NULL COMMENT '建立當下的模板快照',
            tpl_fields_json MEDIUMTEXT NULL,
            values_json LONGTEXT NULL COMMENT '{欄位id: 值或(明細列)值陣列}',
            extra_rows INT NOT NULL DEFAULT 0 COMMENT '明細列多加了幾列',
            created_by INT NULL, created_at DATETIME NULL,
            modified_by INT NULL, modified_at DATETIME NULL,
            submitted_at DATETIME NULL,
            deleted_at DATETIME NULL,
            KEY idx_tpl (template_id), KEY idx_creator (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表格模板填寫單據'");
    }
    $done = true;
}

/* ============================================================ 使用者／權限 ============================================================ */

function sf_current_user(PDO $db): ?array {
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

const SF_FEATURES = [
    ['code' => 'sf_view_all',       'group' => 'view', 'label' => '檢視全部人員建立的表單'],
    ['code' => 'sf_create',         'group' => 'op',   'label' => '建立/填寫表單（沒指派也看得到並可填自己的表單）'],
    ['code' => 'sf_print',          'group' => 'op',   'label' => '列印'],
    ['code' => 'sf_template_admin', 'group' => 'op',   'label' => '模板管理（設計模板、停用/刪除、AS 文件綁定）'],
];

function sf_perms(PDO $db, ?array $u): array {
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canCreate'=>false,'canViewAll'=>false,'canPrint'=>false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    require_once __DIR__ . '/role_features_helper.php';
    $feat = rf_load_user_features_all($db, $uid);
    $has = function (string $code) use ($isAdmin, $feat) { return $isAdmin || in_array('all', $feat, true) || in_array($code, $feat, true); };
    return [
        'isAdmin'    => $isAdmin,
        'canAdmin'   => $has('sf_template_admin'),
        'canCreate'  => true,
        'canViewAll' => $has('sf_view_all') || $has('sf_template_admin'),
        'canPrint'   => true,
    ];
}

/* ============================================================ 欄位掃描／驗證 ============================================================ */

/** 活頁簿第一張工作表的 cellData（Univer 以字串當列/欄索引） */
function sf_book_sheet(array $book): array {
    $order = $book['sheetOrder'] ?? [];
    $sheets = $book['sheets'] ?? [];
    $sid = $order[0] ?? (array_key_first($sheets) ?? null);
    return ($sid !== null && isset($sheets[$sid]) && is_array($sheets[$sid])) ? $sheets[$sid] : [];
}

/**
 * 從活頁簿掃出待填欄位。每個欄位：
 * id, r, c（0 起算）, t(型別), req(必填), opt(選項), g(明細列群組，空字串＝不是明細), label
 * 規則違反時丟 InvalidArgumentException（訊息給使用者看）。
 */
function sf_book_fields(array $book): array {
    $sheet = sf_book_sheet($book);
    $cd = $sheet['cellData'] ?? [];
    $fields = [];
    $ids = [];
    $groupRows = [];
    foreach ($cd as $r => $row) {
        if (!is_array($row)) continue;
        foreach ($row as $c => $cell) {
            $sf = $cell['custom']['sf'] ?? null;
            if (!is_array($sf)) continue;
            $id = (string)($sf['id'] ?? '');
            $t  = (string)($sf['t'] ?? 'text');
            if ($id === '' || !preg_match('/^[A-Za-z0-9_]{1,32}$/', $id)) throw new InvalidArgumentException('有待填欄位的編號不合法，請重新設定該欄位');
            if (isset($ids[$id])) throw new InvalidArgumentException('待填欄位編號重複（' . $id . '），請重新設定其中一格');
            if (!in_array($t, SF_TYPES, true)) throw new InvalidArgumentException('不支援的欄位型別：' . $t);
            $ids[$id] = true;
            $opt = [];
            if ($t === 'select') {
                foreach ((array)($sf['opt'] ?? []) as $o) { $o = trim((string)$o); if ($o !== '' && !in_array($o, $opt, true)) $opt[] = $o; }
                if (!$opt) throw new InvalidArgumentException('下拉選單欄位至少要有一個選項（第 ' . ((int)$r + 1) . ' 列）');
            }
            $g = trim((string)($sf['g'] ?? ''));
            if ($g !== '') $groupRows[(int)$r] = true;
            $fields[] = [
                'id'=>$id, 'r'=>(int)$r, 'c'=>(int)$c, 't'=>$t, 'req'=>!empty($sf['req']) ? 1 : 0,
                'opt'=>$opt, 'g'=>$g, 'label'=>mb_substr(trim((string)($sf['label'] ?? '')), 0, 40),
            ];
        }
    }
    if (count($groupRows) > 1) throw new InvalidArgumentException('明細列只能設在同一列（目前橫跨 ' . count($groupRows) . ' 列）');
    usort($fields, function ($a, $b) { return [$a['r'], $a['c']] <=> [$b['r'], $b['c']]; });
    return $fields;
}

function sf_fields_group_row(array $fields): ?int {
    foreach ($fields as $f) if ($f['g'] !== '') return (int)$f['r'];
    return null;
}

/** 值正規化＋型別檢查。回傳 [值, 錯誤訊息或null]。空值一律回 ''（checkbox 回 false）。 */
function sf_norm_value(array $f, $v): array {
    if ($f['t'] === 'checkbox') {
        $b = ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'TRUE');
        return [$b, null];
    }
    if ($v === null || $v === false) return ['', null];
    $v = trim((string)$v);
    if ($v === '') return ['', null];
    switch ($f['t']) {
        case 'number':
            if (!is_numeric($v)) return [$v, '必須是數字'];
            return [$v + 0, null];
        case 'date':
            if (!preg_match('/^(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return [$v, '日期格式不正確'];
            return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), null];
        case 'select':
            if (!in_array($v, $f['opt'], true)) return [$v, '不在選項內'];
            return [$v, null];
        default:
            return [mb_substr($v, 0, 2000), null];
    }
}

/**
 * 驗證並正規化整包值。回傳 ['values'=>…, 'errors'=>[[欄位id, 列索引或null, 訊息]…]]。
 * 只收 fields 裡有的欄位 id（其餘一律丟掉＝防止有人直接打 API 塞別的格子）。
 * $strict=true 時（送出）才檢查必填。
 */
function sf_validate_values(array $fields, array $values, int $extra, bool $strict): array {
    $out = [];
    $errs = [];
    foreach ($fields as $f) {
        $id = $f['id'];
        $isG = $f['g'] !== '';
        $raw = $values[$id] ?? ($isG ? [] : '');
        $label = $f['label'] !== '' ? $f['label'] : ('第' . ($f['r'] + 1) . '列');
        if ($isG) {
            if (!is_array($raw)) $raw = [$raw];
            $arr = [];
            for ($i = 0; $i <= $extra; $i++) {
                [$nv, $er] = sf_norm_value($f, $raw[$i] ?? '');
                if ($er) $errs[] = [$id, $i, $label . '（第' . ($i + 1) . '筆）' . $er];
                elseif ($strict && $f['req'] && ($nv === '' || $nv === false && $f['t'] !== 'checkbox')) $errs[] = [$id, $i, $label . '（第' . ($i + 1) . '筆）必填'];
                $arr[] = $nv;
            }
            $out[$id] = $arr;
        } else {
            if (is_array($raw)) $raw = $raw[0] ?? '';
            [$nv, $er] = sf_norm_value($f, $raw);
            if ($er) $errs[] = [$id, null, $label . $er];
            elseif ($strict && $f['req'] && $f['t'] !== 'checkbox' && $nv === '') $errs[] = [$id, null, $label . '必填'];
            $out[$id] = $nv;
        }
    }
    return ['values'=>$out, 'errors'=>$errs];
}

/* ============================================================ 模板 ============================================================ */

function sf_template_get(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT * FROM sf_template WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sf_template_list(PDO $db): array {
    $rows = $db->query("SELECT t.id, t.name, t.paper_size, t.orientation, t.status, t.version, t.fields_json, t.modified_at, t.created_at,
            (SELECT COUNT(*) FROM sf_instance i WHERE i.template_id=t.id AND i.deleted_at IS NULL) AS instance_count
        FROM sf_template t ORDER BY t.status, t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $f = json_decode((string)$r['fields_json'], true) ?: [];
        $r['field_count'] = count($f);
        unset($r['fields_json']);
        $r['as_doc'] = eg_asdoc_get($db, sf_asdoc_module((int)$r['id']));
    }
    return $rows;
}

/** 存模板（新增或更新）。回傳模板 id。 */
function sf_template_save(PDO $db, int $uid, int $id, string $name, string $paper, string $orient, string $bookJson): int {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('請輸入模板名稱');
    if (mb_strlen($name) > 100) throw new InvalidArgumentException('模板名稱不可超過 100 字');
    if (!in_array($paper, ['A4', 'A3'], true)) $paper = 'A4';
    if (!in_array($orient, ['portrait', 'landscape'], true)) $orient = 'landscape';
    if (strlen($bookJson) > SF_MAX_BOOK_BYTES) throw new InvalidArgumentException('表格內容過大（超過 8MB）');
    $book = json_decode($bookJson, true);
    if (!is_array($book) || !isset($book['sheets'])) throw new InvalidArgumentException('表格資料格式不正確');
    $fields = sf_book_fields($book);
    $fj = json_encode($fields, JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        $old = sf_template_get($db, $id);
        if (!$old) throw new InvalidArgumentException('找不到此模板');
        $db->prepare("UPDATE sf_template SET name=?, paper_size=?, orientation=?, book_json=?, fields_json=?, version=version+1,
                      modified_by=?, modified_at=NOW() WHERE id=?")
           ->execute([$name, $paper, $orient, $bookJson, $fj, $uid, $id]);
        return $id;
    }
    $db->prepare("INSERT INTO sf_template (name, paper_size, orientation, book_json, fields_json, created_by, created_at, modified_by, modified_at)
                  VALUES (?,?,?,?,?,?,NOW(),?,NOW())")
       ->execute([$name, $paper, $orient, $bookJson, $fj, $uid, $uid]);
    return (int)$db->lastInsertId();
}

/* ============================================================ 單據 ============================================================ */

function sf_instance_get(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT i.*, u.user_cname AS creator_name FROM sf_instance i LEFT JOIN user u ON u.id=i.created_by
                        WHERE i.id=? AND i.deleted_at IS NULL");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sf_instance_can_edit(array $perms, array $inst, int $uid): bool {
    if ($inst['status'] !== 'draft') return false;
    return $perms['canAdmin'] || (int)$inst['created_by'] === $uid;
}

/** 列印用：表頭（綁定 AS 文件名稱）與右下編號（版次依業務日期回推）。未綁定退回模板名稱與空編號。 */
function sf_print_meta(PDO $db, int $tid, string $tplName, ?string $bizDate): array {
    $doc = eg_asdoc_get($db, sf_asdoc_module($tid));
    $no = '';
    if ($doc) {
        $no = eg_asdoc_no_asof_id($db, (int)$doc['id'], $bizDate);
        if ($no === '') $no = eg_asdoc_no($doc);
    }
    $company = '';
    try {
        $company = (string)($db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    return ['title'=>$doc ? $doc['doc_name'] : $tplName, 'doc_no'=>$no, 'company'=>$company, 'bound'=>$doc ? 1 : 0];
}
