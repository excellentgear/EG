<?php
/**
 * eng_log_lib.php — 工程處理紀錄（eng_log）共用函式庫
 *
 * 被 views/TD/eng_log.php 與 src/store/EngLog_API.php 共用。
 * 僅提供函式，不啟動 session、不輸出內容。呼叫端需自備 $db (PDO)。
 *
 * 設計重點（規格見 2026-09-09 與使用者討論定案）：
 *  1. 最小單位是「問題項」不是對話串——一次批圖／發包常有十幾二十條問題，
 *     客戶不會一次回完也不會照順序回，所以每一條各自有對象、狀態、回覆與附件。
 *  2. 回覆的「對方實際回覆日期」(replied_on) 與「在系統補登的時間」(created_at) 分兩欄。
 *     客戶多半是電話回的、隔一兩天才補進系統，只留一個時間欄會讓「這件事拖幾天」全錯。
 *  3. 三軸索引 eng_log_index（料號／客戶／廠商）是這個模組的全部價值，存檔時自動展開，
 *     查詢只吃這張表；bind_label 只是顯示用，主檔改名不影響查詢。
 *  4. 異常矯正單／品質異常處理單目前仍是紙本，單號開放手填(is_manual=1)，
 *     但手填單號展開三軸是空的 → 存檔時提示一併綁 BOM/料號/出貨單/退貨單（提示不硬擋）。
 *  5. 逾期天數一律算「工作天」，直接沿用 car_lib.php 的 car_working_days_between()，
 *     不再寫第三份工作日判定（鐵律4）。
 *
 * 關聯資料表：eng_log / _bind / _index / _item / _reply / _file / _step
 */

require_once __DIR__ . '/car_lib.php';   // car_working_days_between()：工作天判定唯一實作，不重複寫

if (!function_exists('el_bind_types')) {

/* ============================ 常數與標籤 ============================ */

/** 綁定型別 → 顯示名稱。manual=1 者為手填單號（系統查不到對應資料） */
function el_bind_types(): array {
    return [
        'bom'      => ['name' => 'BOM',        'manual' => 0],
        'part'     => ['name' => '料號',       'manual' => 0],
        'order'    => ['name' => '訂單',       'manual' => 0],
        'ship'     => ['name' => '出貨單',     'manual' => 0],
        'return'   => ['name' => '退貨單',     'manual' => 0],
        'customer' => ['name' => '客戶',       'manual' => 0],
        'maker'    => ['name' => '廠商',       'manual' => 0],
        'car_no'   => ['name' => '異常矯正單', 'manual' => 1],
        'qa_no'    => ['name' => '品質異常處理單', 'manual' => 1],
    ];
}

/** 可以展開出料號的載體（手填單號需要搭配其中之一才查得回來） */
function el_carrier_types(): array { return ['bom', 'part', 'order', 'ship', 'return']; }

/** 一張單底下可能有多個料號、需要跳出勾選清單的載體 */
function el_multipart_types(): array { return ['ship', 'return']; }

function el_item_status(): array {
    return ['waiting' => '待回覆', 'answered' => '已回覆', 'resolved' => '已解決', 'dropped' => '不處理'];
}

function el_log_status(): array {
    return ['open' => '處理中', 'done' => '已結案'];
}

function el_visibility(): array {
    return ['self' => '僅自己', 'dept' => '本部門', 'all' => '全公司'];
}

/**
 * 案件類型（使用者 2026-09-11 指定收斂成三種，仍可不選）。
 * 舊資料可能還留著 outsource/spec/material/quality/delivery，這裡一併保留對照，
 * 否則舊紀錄的類型會顯示成空白。
 */
function el_log_types(): array {
    return [
        'drawing'   => '批圖',
        'process'   => '製程中',
        'return'    => '退貨',
        'other'     => '未分類',
        // ── 以下為舊值，只供顯示，不再出現在選單 ──
        'outsource' => '發包', 'spec' => '規格', 'material' => '材料',
        'quality'   => '品質', 'delivery' => '交期',
    ];
}
/** 目前還能被選的三種（＋未分類） */
function el_log_types_active(): array {
    return ['drawing' => '批圖', 'process' => '製程中', 'return' => '退貨'];
}

/**
 * 綁定型別 → 自動帶出的案件類型（使用者指定：退貨單＝退貨、訂單＝批圖）。
 * 只在「使用者還沒自己選過類型」時套用，選過就不覆蓋。
 */
function el_auto_type_for_bind(string $bindType): string {
    switch ($bindType) {
        case 'return': return 'return';
        case 'order':  return 'drawing';
        default:       return '';
    }
}

/**
 * 回覆方式（電話／Mail／Line…）。
 *
 * 一律即時查 eng_log_channel 現況組出來，**不寫死清單**（鐵律4）——管理員改名或刪掉之後，
 * 寫死的對照表會繼續顯示舊名稱而且不會報錯。第一次執行時自動種入預設四項。
 * @return array [code => name]（只含啟用中的；$all=true 時連停用的也回）
 */
function el_channels(PDO $db = null, bool $all = false): array {
    if ($db === null) return [];
    $out = [];
    try {
        $sql = "SELECT code, name FROM eng_log_channel" . ($all ? '' : " WHERE is_active=1")
             . " ORDER BY sort_order, id";
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['code']] = $r['name'];
    } catch (Throwable $e) {}
    return $out;
}

/** 回覆方式完整資料（設定畫面用） */
function el_channel_rows(PDO $db): array {
    try {
        return $db->query("SELECT id, code, name, sort_order, is_active FROM eng_log_channel
                           ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================ 資料表 ============================ */

/**
 * 建表（可重複執行）。新欄位一律用 try/catch ALTER，舊站台自動補齊。
 * 注意：本檔沒有版本鎖，每次都會跑一次 CREATE IF NOT EXISTS（成本極低）。
 */
function el_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db->exec("CREATE TABLE IF NOT EXISTS eng_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_no VARCHAR(24) NOT NULL COMMENT '案件編號 EL+民國年3碼+MMDD+3流水，依建立日期產生',
        user_id INT NOT NULL COMMENT '建立者 FK→user.id',
        dept_id INT NULL COMMENT '建立者當時的部門（可見度=dept 時用）',
        title VARCHAR(200) NOT NULL,
        log_type VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT 'outsource發包/drawing批圖/spec規格/material材料/quality品質/delivery交期/other',
        status VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open處理中 / done已結案',
        visibility VARCHAR(10) NOT NULL DEFAULT 'dept' COMMENT 'self僅自己 / dept本部門 / all全公司',
        deadline DATETIME NULL COMMENT '案件期限',
        remind_before_minutes INT NULL COMMENT '期限前幾分鐘提醒，NULL=不提醒',
        remind_sent TINYINT NOT NULL DEFAULT 0,
        urgent_days INT NULL COMMENT '幾天內算急件，NULL=用預設3',
        note TEXT NULL COMMENT '案件備註',
        conclusion VARCHAR(500) NULL COMMENT '結案結論（結案時必填）',
        closed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_log_no (log_no),
        KEY idx_user (user_id), KEY idx_status (status), KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 案件'");

    $db->exec("CREATE TABLE IF NOT EXISTS eng_log_bind (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        bind_type VARCHAR(12) NOT NULL COMMENT '見 el_bind_types()',
        bind_id VARCHAR(60) NOT NULL COMMENT '有電子檔者存主鍵；手填單號直接存單號字串',
        bind_label VARCHAR(150) NULL COMMENT '顯示用，查詢一律不吃這欄',
        is_manual TINYINT NOT NULL DEFAULT 0 COMMENT '1=手填單號，系統查不到',
        sel_parts TEXT NULL COMMENT '出貨單/退貨單底下選定的料號 d_id JSON 陣列；NULL=全部',
        sort_order INT NOT NULL DEFAULT 0,
        KEY idx_log (log_id), KEY idx_type_id (bind_type, bind_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 綁定對象'");

    $db->exec("CREATE TABLE IF NOT EXISTS eng_log_index (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        part_d_id INT NULL COMMENT 'FK→d_setting.d_id',
        customer_id VARCHAR(30) NULL COMMENT 'FK→customer_list.customer_id',
        maker_id_no VARCHAR(30) NULL COMMENT 'FK→maker_list.maker_id_no',
        KEY idx_log (log_id), KEY idx_part (part_d_id), KEY idx_cust (customer_id), KEY idx_maker (maker_id_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 三軸索引（存檔時自動展開）'");

    $db->exec("CREATE TABLE IF NOT EXISTS eng_log_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        seq INT NOT NULL DEFAULT 1 COMMENT '畫面上的問題編號',
        question TEXT NOT NULL,
        target_type VARCHAR(10) NULL COMMENT 'customer/maker/user，一條問題只對一個對象',
        target_id VARCHAR(30) NULL,
        target_label VARCHAR(120) NULL,
        target_contact VARCHAR(60) NULL COMMENT '聯絡人姓名，自由輸入（客戶窗口不在系統裡）',
        asked_at DATE NULL COMMENT '提出日期',
        status VARCHAR(10) NOT NULL DEFAULT 'waiting',
        follow_up_days INT NULL COMMENT '等滿幾個工作天沒回就提醒，NULL=用預設7',
        remind_sent TINYINT NOT NULL DEFAULT 0,
        conclusion VARCHAR(500) NULL COMMENT '這一條最後怎麼處理',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        KEY idx_log (log_id), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 問題項'");

    $db->exec("CREATE TABLE IF NOT EXISTS eng_log_reply (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        log_id INT NOT NULL,
        replied_on DATE NOT NULL COMMENT '★對方實際回覆的日期，未選＝存檔當天',
        reply_by VARCHAR(60) NULL COMMENT '對方是誰',
        channel VARCHAR(10) NULL COMMENT '見 el_channels()',
        content TEXT NOT NULL,
        created_by INT NULL COMMENT '誰補登的',
        created_at DATETIME NOT NULL COMMENT '★補登時間，與 replied_on 刻意分開',
        KEY idx_item (item_id), KEY idx_log (log_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 對象回覆'");

    $db->exec("CREATE TABLE IF NOT EXISTS eng_log_file (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NULL,
        owner_type VARCHAR(8) NOT NULL DEFAULT 'log' COMMENT 'log案件 / item問題項 / reply回覆',
        owner_id INT NULL,
        file_name VARCHAR(255) NOT NULL COMMENT '只存檔名，路徑即時組（鐵律5）',
        original_name VARCHAR(255) NULL,
        file_size INT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'active' COMMENT 'temp=尚未存檔的暫存 / active=正式',
        expire_at DATETIME NULL,
        uploaded_by INT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_log (log_id), KEY idx_owner (owner_type, owner_id), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 附件'");

    /* 回覆方式選項：管理員可自行新增／改名／停用，所以不是寫死的常數 */
    $db->exec("CREATE TABLE IF NOT EXISTS eng_log_channel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL COMMENT '存進 eng_log_reply.channel 的值，建立後不再更動',
        name VARCHAR(40) NOT NULL COMMENT '顯示名稱，改名不影響既有紀錄',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1 COMMENT '0=停用（既有紀錄照樣顯示，只是不再出現在按鈕列）',
        created_at DATETIME NULL,
        UNIQUE KEY uq_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 回覆方式選項'");
    try {
        if ((int)$db->query("SELECT COUNT(*) FROM eng_log_channel")->fetchColumn() === 0) {
            $ins = $db->prepare("INSERT INTO eng_log_channel (code, name, sort_order, is_active, created_at)
                                 VALUES (?,?,?,1,NOW())");
            $i = 0;
            foreach (['phone' => '電話', 'email' => 'Mail', 'line' => 'Line', 'onsite' => '現場',
                      'meeting' => '會議', 'other' => '其他'] as $c => $n) $ins->execute([$c, $n, $i++]);
        }
    } catch (Throwable $e) {}

    /* 後續追加的欄位（舊站台自動補齊；已存在時 ALTER 會丟例外，直接略過） */
    foreach ([
        "ALTER TABLE eng_log ADD COLUMN title_manual VARCHAR(200) NULL COMMENT '使用者自己打的標題，顯示在自動標題後方' AFTER title",
        "ALTER TABLE eng_log_bind ADD COLUMN meta TEXT NULL COMMENT '型別專屬附加資料（BOM：選定的製程與當時的發包廠商）'",
        "ALTER TABLE eng_log_item ADD COLUMN parent_item_id INT NULL COMMENT '延伸問題：由哪一條問題衍生出來的' AFTER log_id",
        "ALTER TABLE eng_log_file ADD COLUMN note VARCHAR(500) NULL COMMENT '附件備註，顯示在附件下方'",
    ] as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }

    $db->exec("CREATE TABLE IF NOT EXISTS eng_log_step (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_id INT NOT NULL,
        step_name VARCHAR(100) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        planned_at DATETIME NULL,
        reached_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY idx_log (log_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工程處理紀錄 進度（第三期才會有 UI）'");
}

/* ============================ 使用者與權限 ============================ */

function el_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function el_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes || $uid <= 0) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='eng_log' AND r.role_code IN ($in) LIMIT 1");
        $st->execute(array_merge([$uid], $codes));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** 建立者當時的部門（取職級最高那筆，比照 people_lib 的挑法） */
function el_user_dept(PDO $db, int $uid): ?int
{
    try {
        $st = $db->prepare("SELECT m.department_id FROM user_department_position_map m
                            LEFT JOIN position p ON p.id = m.position_id
                            WHERE m.user_id = ?
                            ORDER BY COALESCE(p.sort_order,999) ASC, m.is_main DESC, m.id ASC LIMIT 1");
        $st->execute([$uid]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (int)$v;
    } catch (Throwable $e) { return null; }
}

/**
 * canAdmin 管理員：查全部、改他人的、模組設定
 * canViewAll 檢閱：可看全公司的紀錄（唯讀）
 * canEdit  可開立／編輯自己的紀錄
 * 沒有任何角色者 canView=false（本模組不 fail-open）
 */
function el_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin'=>false,'canAdmin'=>false,'canViewAll'=>false,'canEdit'=>false,'canView'=>false,'uid'=>0,'name'=>'','dept_id'=>null];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)($u['user_status'] ?? 0), [9, 90], true);
    if (!$isAdmin) {
        try {
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
            $st->execute([$uid]);
            $isAdmin = (bool)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    $canAdmin   = $isAdmin || el_has_role($db, $uid, ['eng_log_admin']);
    $canViewAll = $canAdmin || el_has_role($db, $uid, ['eng_log_view_all']);
    $canEdit    = $canAdmin || el_has_role($db, $uid, ['eng_log_edit']);
    $canView    = $canEdit || $canViewAll;
    return ['isAdmin'=>$isAdmin, 'canAdmin'=>$canAdmin, 'canViewAll'=>$canViewAll,
            'canEdit'=>$canEdit, 'canView'=>$canView, 'uid'=>$uid,
            'name'=>(string)$u['user_cname'], 'dept_id'=>el_user_dept($db, $uid)];
}

/**
 * 清單/明細的可見範圍 SQL 片段（後端強制，不可只擋前端＝鐵律8）。
 * @return array [sql, params]  sql 已含括號，直接 AND 進 WHERE
 */
function el_visible_sql(array $P): array
{
    if ($P['canAdmin'] || $P['canViewAll']) return ['(1)', []];
    $uid = (int)$P['uid'];
    $dept = $P['dept_id'];
    // 自己的 一律看得到；別人的要看 visibility：all 全公司可見、dept 需同部門
    $sql = "(t.user_id = ? OR t.visibility = 'all'";
    $params = [$uid];
    if ($dept !== null) { $sql .= " OR (t.visibility = 'dept' AND t.dept_id = ?)"; $params[] = (int)$dept; }
    $sql .= ")";
    return [$sql, $params];
}

/* ============================ 編號 ============================ */

/**
 * 案件編號：EL + 民國年3碼 + MMDD + 3位流水（依建立日期，不是「今天」）。
 * 例：2026-09-09 → EL-1150909001
 */
function el_next_log_no(PDO $db, ?string $date = null): string
{
    $d = $date ? substr($date, 0, 10) : date('Y-m-d');
    $ts = strtotime($d);
    if ($ts === false) $ts = time();
    $prefix = 'EL-' . sprintf('%03d', (int)date('Y', $ts) - 1911) . date('md', $ts);
    for ($try = 0; $try < 50; $try++) {
        $st = $db->prepare("SELECT log_no FROM eng_log WHERE log_no LIKE ? ORDER BY log_no DESC LIMIT 1");
        $st->execute([$prefix . '%']);
        $last = (string)$st->fetchColumn();
        $seq = $last === '' ? 1 : ((int)substr($last, -3) + 1);
        $no = $prefix . sprintf('%03d', $seq);
        $chk = $db->prepare("SELECT 1 FROM eng_log WHERE log_no = ?");
        $chk->execute([$no]);
        if (!$chk->fetchColumn()) return $no;
    }
    return $prefix . substr((string)microtime(true), -3);
}

/**
 * 自動標題（使用者指定格式）：客戶 ｜ 類型 ｜ 廠商 ｜ 料號1 ｜ 料號2 ｜ 料號3 …更多料號
 *
 * 幾個刻意的規則：
 *  - 每次讀取都**即時算**，不存進 DB：綁定改了、料號主檔改名了，標題自動跟著對。
 *  - 只綁客戶、沒綁到任何料號時，料號位置顯示「全料號」——那種案件本來就是
 *    針對這家客戶而不是某個料號，不是資料缺漏。
 *  - 料號最多列 3 個，超過補「…更多料號」，避免清單被一長串料號撐爆。
 *  - 使用者自己打的標題接在自動標題後面（$titleManual），不是取代它。
 */
function el_auto_title(PDO $db, int $logId, string $logType = '', array $binds = null): string
{
    $typeNames = el_log_types();
    $custs = []; $makers = []; $parts = [];

    // 綁定：沒傳進來就即時查（存檔中會把當下要寫的 binds 傳進來）
    if ($binds === null) {
        try {
            $st = $db->prepare("SELECT bind_type, bind_id, bind_label FROM eng_log_bind WHERE log_id=? ORDER BY sort_order, id");
            $st->execute([$logId]);
            $binds = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $binds = []; }
    }
    $hasCustomerBind = false;
    foreach ($binds as $b) {
        $t = (string)($b['bind_type'] ?? '');
        if ($t === 'customer') $hasCustomerBind = true;
        if ($t === 'maker') {
            $lb = trim((string)($b['bind_label'] ?? '')) ?: (el_bind_label($db, 'maker', (string)$b['bind_id']) ?? '');
            if ($lb !== '') $makers[$lb] = true;
        }
    }

    // 客戶／料號／廠商一律取自三軸索引（綁 BOM 自動推導出來的也算）
    if ($logId > 0) {
        try {
            $st = $db->prepare("SELECT c.customer FROM eng_log_index x
                                LEFT JOIN customer_list c ON c.customer_id = x.customer_id
                                WHERE x.log_id=? AND x.customer_id IS NOT NULL ORDER BY c.customer");
            $st->execute([$logId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) if (trim((string)$v) !== '') $custs[(string)$v] = true;

            $st = $db->prepare("SELECT d.D_Setting_Id FROM eng_log_index x
                                LEFT JOIN d_setting d ON d.d_id = x.part_d_id
                                WHERE x.log_id=? AND x.part_d_id IS NOT NULL ORDER BY d.D_Setting_Id");
            $st->execute([$logId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) if (trim((string)$v) !== '') $parts[(string)$v] = true;

            $st = $db->prepare("SELECT m.maker_id FROM eng_log_index x
                                LEFT JOIN maker_list m ON m.maker_id_no = x.maker_id_no
                                WHERE x.log_id=? AND x.maker_id_no IS NOT NULL ORDER BY m.maker_id");
            $st->execute([$logId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) if (trim((string)$v) !== '') $makers[(string)$v] = true;
        } catch (Throwable $e) {}
    }

    $seg = [];
    $custList = array_keys($custs);
    if ($custList) $seg[] = count($custList) <= 2 ? implode('、', $custList) : ($custList[0] . ' 等 ' . count($custList) . ' 家');

    $tn = $typeNames[$logType] ?? '';
    if ($tn !== '' && $logType !== 'other') $seg[] = $tn;

    $mkList = array_keys($makers);
    if ($mkList) $seg[] = count($mkList) <= 2 ? implode('、', $mkList) : ($mkList[0] . ' 等 ' . count($mkList) . ' 家');

    $pList = array_keys($parts);
    if ($pList) {
        $show = array_slice($pList, 0, 3);
        $seg[] = implode(' | ', $show) . (count($pList) > 3 ? ' …更多料號' : '');
    } elseif ($hasCustomerBind || $custList) {
        // 只針對這家客戶、沒有特定料號——這是正常情況，不是漏填
        $seg[] = '全料號';
    }

    return $seg ? mb_substr(implode(' | ', $seg), 0, 300) : '';
}

/** 清單／明細要顯示的完整標題＝自動標題 ＋ 使用者自己打的標題 */
function el_display_title(PDO $db, array $row): string
{
    $auto = el_auto_title($db, (int)$row['id'], (string)($row['log_type'] ?? ''));
    $manual = trim((string)($row['title_manual'] ?? ''));
    if ($auto === '' && $manual === '') return (string)$row['log_no'];
    if ($auto === '') return $manual;
    return $manual === '' ? $auto : ($auto . '　' . $manual);
}

/* ============================ 綁定：解析與展開 ============================ */

/** 綁定顯示文字一律以 DB 當下資料為準；手填單號直接回單號本身 */
function el_bind_label(PDO $db, string $type, string $id): ?string
{
    $types = el_bind_types();
    if (!isset($types[$type])) return null;
    if ($types[$type]['manual']) return $id !== '' ? $id : null;
    try {
        switch ($type) {
            case 'bom':
                $st = $db->prepare("SELECT bom FROM bom WHERE bom = ?");
                $st->execute([$id]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
            case 'part':
                $st = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id = ?");
                $st->execute([(int)$id]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
            case 'order':
                $st = $db->prepare("SELECT Order_oo FROM order_track WHERE Order_id = ?");
                $st->execute([(int)$id]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
            case 'ship':
                $st = $db->prepare("SELECT IS_number FROM is_list WHERE IS_number = ? LIMIT 1");
                $st->execute([$id]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
            case 'return':
                $st = $db->prepare("SELECT IR_no FROM ir_track WHERE IR_no = ? LIMIT 1");
                $st->execute([$id]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
            case 'customer':
                $st = $db->prepare("SELECT customer FROM customer_list WHERE customer_id = ?");
                $st->execute([$id]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
            case 'maker':
                $st = $db->prepare("SELECT maker_id FROM maker_list WHERE maker_id_no = ?");
                $st->execute([$id]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
        }
    } catch (Throwable $e) {}
    return null;
}

/**
 * 一張出貨單／退貨單底下有哪些料號（給前端跳出勾選清單用）。
 * 實測 is_list 與 ir_track 的 d_setting_id 皆 0 筆為空、0 筆孤兒，所以綁到單就一定對得到料號。
 * @return array [['d_id'=>int,'part_no'=>string,'qty'=>string], ...]
 */
function el_carrier_parts(PDO $db, string $type, string $id): array
{
    $rows = [];
    try {
        if ($type === 'ship') {
            $st = $db->prepare("SELECT i.d_setting_id AS d_id, d.D_Setting_Id AS part_no, SUM(i.Qty) AS qty
                                FROM is_list i LEFT JOIN d_setting d ON d.d_id = i.d_setting_id
                                WHERE i.IS_number = ? AND i.d_setting_id IS NOT NULL AND i.d_setting_id <> 0
                                GROUP BY i.d_setting_id, d.D_Setting_Id ORDER BY d.D_Setting_Id");
            $st->execute([$id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($type === 'return') {
            $st = $db->prepare("SELECT t.d_setting_id AS d_id, d.D_Setting_Id AS part_no, SUM(t.Qty) AS qty
                                FROM ir_track t LEFT JOIN d_setting d ON d.d_id = t.d_setting_id
                                WHERE t.IR_no = ? AND t.d_setting_id IS NOT NULL AND t.d_setting_id <> 0
                                GROUP BY t.d_setting_id, d.D_Setting_Id ORDER BY d.D_Setting_Id");
            $st->execute([$id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {}
    foreach ($rows as &$r) { $r['d_id'] = (int)$r['d_id']; $r['part_no'] = (string)($r['part_no'] ?? ''); }
    return $rows;
}

/** 出貨單／退貨單的抬頭資訊（勾選清單標題用） */
function el_carrier_head(PDO $db, string $type, string $id): array
{
    try {
        if ($type === 'ship') {
            $st = $db->prepare("SELECT Client_name, MIN(Order_date) AS d FROM is_list WHERE IS_number = ? GROUP BY Client_name LIMIT 1");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return ['client' => (string)$r['Client_name'], 'date' => (string)$r['d']];
        } elseif ($type === 'return') {
            $st = $db->prepare("SELECT Client_name, MIN(IR_date) AS d FROM ir_track WHERE IR_no = ? GROUP BY Client_name LIMIT 1");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return ['client' => (string)$r['Client_name'], 'date' => (string)$r['d']];
        }
    } catch (Throwable $e) {}
    return ['client' => '', 'date' => ''];
}

/**
 * 把「料號」解析成 d_setting.d_id（int）。
 *
 * ★ 這裡有兩個很容易寫錯的欄位（2026-09-09 實測）：
 *   - `bom.d_id` 與 `order_track.d_id` 都是**料號文字**（varchar），不是 d_setting 的主鍵；
 *     真正的 int FK 分別是 `bom.d_setting_id` 與 `order_track.d_id_ID`。
 *   - 但那兩個 int FK 大量是空的：bom 11,971 筆有 9,694 筆空（81%）、
 *     order_track 9,263 筆有 5,216 筆沒有客戶 ID。只靠 int FK 會讓多數綁定變成「未連結」。
 *
 * 所以：int FK 優先，沒有才用料號文字回查，且**只在唯一命中時才採用**。
 * d_setting 裡有 1,661 個重複的料號字串（實測 BOM 有 1,609 筆會對到多筆），
 * 有歧義時**寧可不連結**也不要亂猜——猜錯會讓別的料號查出不相干的紀錄，
 * 而沒連結的案件會被「未連結料號」篩選撈出來，使用者可以手動補綁一個明確的料號。
 *
 * @return int 0＝解析不出來
 */
function el_resolve_part_id(PDO $db, $intId, ?string $textNo): int
{
    $i = (int)$intId;
    if ($i > 0) return $i;
    $t = trim((string)$textNo);
    if ($t === '') return 0;
    try {
        $st = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ? LIMIT 2");
        $st->execute([$t]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) === 1) return (int)$rows[0];
    } catch (Throwable $e) {}
    return 0;   // 對不到或有歧義：不猜
}

/** 客戶：ID 優先，沒有才用客戶名稱回查，同樣只採用唯一命中 */
function el_resolve_customer_id(PDO $db, ?string $idVal, ?string $nameText): ?string
{
    $v = trim((string)$idVal);
    if ($v !== '') return $v;
    $n = trim((string)$nameText);
    if ($n === '') return null;
    try {
        $st = $db->prepare("SELECT customer_id FROM customer_list WHERE customer = ? LIMIT 2");
        $st->execute([$n]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) === 1) return (string)$rows[0];
    } catch (Throwable $e) {}
    return null;
}

/** 出貨單上的客戶 ID（實測 is_list.Client_id 全表為空，所以呼叫端一定要準備名稱回退） */
function el_ship_client_id(PDO $db, string $isNumber): ?string
{
    try {
        $st = $db->prepare("SELECT Client_id FROM is_list
                            WHERE IS_number = ? AND Client_id IS NOT NULL AND Client_id <> '' LIMIT 1");
        $st->execute([$isNumber]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) { return null; }
}

/** 料號 d_id → 客戶 customer_id（料號決定客戶，見 d_setting.Customer_Id） */
function el_part_customer(PDO $db, int $dId): ?string
{
    if ($dId <= 0) return null;
    try {
        $st = $db->prepare("SELECT Customer_Id FROM d_setting WHERE d_id = ?");
        $st->execute([$dId]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || trim((string)$v) === '') ? null : trim((string)$v);
    } catch (Throwable $e) { return null; }
}

/**
 * 重建某案件的三軸索引。綁定或問題項對象一有變動就要呼叫（唯一入口）。
 * 手填單號展不出任何東西——這正是存檔時要提示補綁載體的原因。
 */
function el_reindex(PDO $db, int $logId): void
{
    $parts = []; $custs = []; $makers = [];

    $addPart = function ($d) use (&$parts, &$custs, $db) {
        $d = (int)$d;
        if ($d <= 0) return;
        $parts[$d] = true;
        $c = el_part_customer($db, $d);
        if ($c !== null) $custs[$c] = true;
    };

    $st = $db->prepare("SELECT bind_type, bind_id, sel_parts FROM eng_log_bind WHERE log_id = ?");
    $st->execute([$logId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $t = (string)$b['bind_type']; $id = (string)$b['bind_id'];
        try {
            switch ($t) {
                case 'part':
                    $addPart($id);
                    break;
                case 'bom':
                    // bom.d_setting_id 才是 int FK；bom.d_id 是料號文字（見 el_resolve_part_id）
                    $q = $db->prepare("SELECT d_setting_id, d_id, Client_Name FROM bom WHERE bom = ?");
                    $q->execute([$id]);
                    if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                        $addPart(el_resolve_part_id($db, $r['d_setting_id'], (string)$r['d_id']));
                        $cc = el_resolve_customer_id($db, null, (string)($r['Client_Name'] ?? ''));
                        if ($cc !== null) $custs[$cc] = true;
                    }
                    // 這張 BOM 發給哪幾家廠商，系統自己知道（逐關製程上就有 maker_id_no）
                    $q2 = $db->prepare("SELECT DISTINCT maker_id_no FROM bom_ing
                                        WHERE bom = ? AND maker_id_no IS NOT NULL AND maker_id_no <> ''");
                    $q2->execute([$id]);
                    foreach ($q2->fetchAll(PDO::FETCH_COLUMN) as $m) $makers[(string)$m] = true;
                    break;
                case 'order':
                    // order_track.d_id_ID 才是 int FK；d_id 是料號文字
                    $q = $db->prepare("SELECT d_id_ID, d_id, Client_name_ID, Client_name FROM order_track WHERE Order_id = ?");
                    $q->execute([(int)$id]);
                    if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                        $addPart(el_resolve_part_id($db, $r['d_id_ID'], (string)$r['d_id']));
                        $cc = el_resolve_customer_id($db, (string)($r['Client_name_ID'] ?? ''), (string)($r['Client_name'] ?? ''));
                        if ($cc !== null) $custs[$cc] = true;
                    }
                    break;
                case 'ship':
                case 'return':
                    $sel = null;
                    if ($b['sel_parts'] !== null && trim((string)$b['sel_parts']) !== '') {
                        $tmp = json_decode((string)$b['sel_parts'], true);
                        if (is_array($tmp)) $sel = array_map('intval', $tmp);
                    }
                    foreach (el_carrier_parts($db, $t, $id) as $p) {
                        if ($sel !== null && !in_array((int)$p['d_id'], $sel, true)) continue;
                        $addPart((int)$p['d_id']);
                    }
                    // is_list.Client_id 實測全表皆空，所以一定要有名稱回退；ir_track 只有 Client_name
                    $head = el_carrier_head($db, $t, $id);
                    $cc = el_resolve_customer_id($db, ($t === 'ship' ? el_ship_client_id($db, $id) : null), $head['client']);
                    if ($cc !== null) $custs[$cc] = true;
                    break;
                case 'customer':
                    if ($id !== '') $custs[$id] = true;
                    break;
                case 'maker':
                    if ($id !== '') $makers[$id] = true;
                    break;
                // car_no / qa_no：手填單號，展不出任何東西
            }
        } catch (Throwable $e) {}
    }

    // 問題項的對象也要進索引（問了哪一家廠商，就查得到這一家）
    try {
        $st2 = $db->prepare("SELECT target_type, target_id FROM eng_log_item
                             WHERE log_id = ? AND target_id IS NOT NULL AND target_id <> ''");
        $st2->execute([$logId]);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $it) {
            if ($it['target_type'] === 'customer') $custs[(string)$it['target_id']] = true;
            elseif ($it['target_type'] === 'maker') $makers[(string)$it['target_id']] = true;
        }
    } catch (Throwable $e) {}

    $db->prepare("DELETE FROM eng_log_index WHERE log_id = ?")->execute([$logId]);
    $ins = $db->prepare("INSERT INTO eng_log_index (log_id, part_d_id, customer_id, maker_id_no) VALUES (?,?,?,?)");
    foreach (array_keys($parts)  as $v) $ins->execute([$logId, (int)$v, null, null]);
    foreach (array_keys($custs)  as $v) $ins->execute([$logId, null, (string)$v, null]);
    foreach (array_keys($makers) as $v) $ins->execute([$logId, null, null, (string)$v]);
}

/** 這筆案件有沒有連到任何料號（沒有的話清單標「未連結」） */
function el_has_part(PDO $db, int $logId): bool
{
    // 有料號、有客戶、有廠商任一都算「查得回來」（2026-09-11 使用者指正：
    // 只綁客戶是針對整家客戶而非某個料號，那種案件不該被標成未連結）
    try {
        $st = $db->prepare("SELECT 1 FROM eng_log_index WHERE log_id = ?
                            AND (part_d_id IS NOT NULL OR customer_id IS NOT NULL OR maker_id_no IS NOT NULL) LIMIT 1");
        $st->execute([$logId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * 一張 BOM 的逐關製程＋目前發包廠商（類型＝製程中時讓使用者挑是哪一關出問題）。
 * 走共用的 eg_bom_progress()，與 personal_task 的製程條同一套口徑。
 */
function el_bom_processes(PDO $db, string $bom): array
{
    require_once __DIR__ . '/bom_progress_lib.php';
    $p = eg_bom_progress($db, $bom);
    if (!$p) return [];
    $out = [];
    foreach ($p['nodes'] as $i => $n) {
        // maker 是廠商名稱（bom_ing.maker_id），再回查編號才綁得住主檔
        $makerNo = '';
        $mk = trim((string)($n['maker'] ?? ''));
        if ($mk !== '') {
            try {
                $st = $db->prepare("SELECT maker_id_no FROM maker_list WHERE maker_id = ? LIMIT 1");
                $st->execute([$mk]);
                $v = $st->fetchColumn();
                if ($v !== false) $makerNo = (string)$v;
            } catch (Throwable $e) {}
        }
        $out[] = [
            'seq' => $i + 1, 'bom_sn' => $n['bom_sn'], 'name' => $n['name'],
            'maker' => $mk, 'maker_id_no' => $makerNo,
            'outsourced' => ($n['outsource_date'] ? 1 : 0),
            'outsource_date' => $n['outsource_date'], 'return_date' => $n['return_date'],
            'reached' => $n['reached'], 'current' => $n['current'],
        ];
    }
    return $out;
}

/**
 * 存檔前的守門提示：只填了手填單號、卻沒有任何查得回來的載體。
 * 這是「提示」不是硬擋——確實有對不到料號的異常單（客訴、包裝、運送），
 * 硬擋的話那些單永遠進不來。回傳提示字串，沒問題時回 null。
 */
function el_manual_only_warning(array $binds): ?string
{
    $manual = []; $hasCarrier = false;
    $types = el_bind_types();
    // 客戶／廠商也算「查得回來」（使用者 2026-09-11 指正）：只綁客戶是因為這件事針對
    // 這家客戶、不是某個特定料號，那是正常情況，不該被當成資料缺漏一直跳警告。
    $carriers = array_merge(el_carrier_types(), ['customer', 'maker']);
    foreach ($binds as $b) {
        $t = (string)($b['bind_type'] ?? '');
        if (in_array($t, $carriers, true)) { $hasCarrier = true; }
        if (isset($types[$t]) && $types[$t]['manual']) $manual[] = $types[$t]['name'];
    }
    if (!$manual || $hasCarrier) return null;
    return '這筆只填了' . implode('、', array_unique($manual))
         . '的單號，系統查不到它的料號與客戶，之後用客戶／料號／廠商都搜尋不到這筆紀錄。'
         . '建議一併綁定 BOM、料號、出貨單或退貨單其中之一。';
}

/** 同一個手填單號在別的案件出現過幾次（日後異常單電子化時靠這個字串接得起來） */
function el_manual_no_others(PDO $db, string $type, string $no, int $exceptLogId = 0): array
{
    if ($no === '') return [];
    try {
        $st = $db->prepare("SELECT DISTINCT t.id, t.log_no, t.title
                            FROM eng_log_bind b JOIN eng_log t ON t.id = b.log_id
                            WHERE b.bind_type = ? AND b.bind_id = ? AND t.id <> ?
                            ORDER BY t.id DESC LIMIT 10");
        $st->execute([$type, $no, $exceptLogId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/* ============================ 問題項狀態與逾期 ============================ */

/** 依有沒有回覆推導問題項狀態（resolved/dropped 是人工設的，不覆蓋） */
function el_item_auto_status(PDO $db, int $itemId, string $cur): string
{
    if ($cur === 'resolved' || $cur === 'dropped') return $cur;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM eng_log_reply WHERE item_id = ?");
        $st->execute([$itemId]);
        return ((int)$st->fetchColumn() > 0) ? 'answered' : 'waiting';
    } catch (Throwable $e) { return $cur; }
}

/**
 * 問題項等了幾個工作天（waiting 才有意義）。
 * 算工作天不是日曆天，否則週一上班每一條都會變成逾期。
 */
function el_item_waiting_days(PDO $db, ?string $askedAt): int
{
    if (!$askedAt) return 0;
    return car_working_days_between($db, $askedAt, date('Y-m-d'));
}

function el_default_follow_up_days(): int { return 7; }
function el_default_urgent_days(): int { return 3; }

/* ============================ 提醒 ============================ */

/**
 * 掃描「提醒時間已到、尚未發送」的案件期限與問題項催回覆並發送。
 * 走 Web Push＋Telegram、不寫 live_event（比照 personal_task，避免公告變亂）。
 * 先 UPDATE remind_sent=1 搶佔再發送，多程序同時跑也不會重複發。
 * @return int 實際發送筆數
 */
function el_process_due_reminders(PDO $db): int
{
    require_once __DIR__ . '/personal_task_notify.php';   // personal_task_remind_user()：推播管線唯一實作
    el_ensure_schema($db);
    $sent = 0;
    $url = '/EGsystem/views/TD/eng_log.php';

    // ── 案件期限提醒 ──
    try {
        $rows = $db->query("
            SELECT t.id, t.user_id, t.log_no, t.title, t.deadline
            FROM eng_log t
            WHERE t.status = 'open' AND t.remind_sent = 0
              AND t.deadline IS NOT NULL AND t.remind_before_minutes IS NOT NULL
              AND NOW() >= DATE_SUB(t.deadline, INTERVAL t.remind_before_minutes MINUTE)
        ")->fetchAll(PDO::FETCH_ASSOC);
        $claim = $db->prepare("UPDATE eng_log SET remind_sent = 1 WHERE id = ? AND remind_sent = 0");
        foreach ($rows as $r) {
            $claim->execute([$r['id']]);
            if ($claim->rowCount() < 1) continue;
            $body = '「' . $r['title'] . '」期限 ' . substr((string)$r['deadline'], 0, 16) . '（' . $r['log_no'] . '）';
            personal_task_remind_user($db, (int)$r['user_id'], '⏰ 工程處理紀錄 期限提醒', $body, $url, 'eng-log');
            $sent++;
        }
    } catch (Throwable $e) { error_log('[eng_log] deadline remind failed: ' . $e->getMessage()); }

    // ── 問題項催回覆提醒 ──
    // SQL 先用日曆天粗篩（工作天一定 <= 日曆天，不會漏掉），再用工作天精算一次。
    try {
        $def = el_default_follow_up_days();
        $rows = $db->query("
            SELECT i.id, i.log_id, i.seq, i.question, i.asked_at, i.target_label,
                   COALESCE(i.follow_up_days, {$def}) AS fud,
                   t.user_id, t.log_no, t.title
            FROM eng_log_item i JOIN eng_log t ON t.id = i.log_id
            WHERE i.status = 'waiting' AND i.remind_sent = 0 AND t.status = 'open'
              AND i.asked_at IS NOT NULL
              AND i.asked_at <= DATE_SUB(CURDATE(), INTERVAL COALESCE(i.follow_up_days, {$def}) DAY)
        ")->fetchAll(PDO::FETCH_ASSOC);
        $claim = $db->prepare("UPDATE eng_log_item SET remind_sent = 1 WHERE id = ? AND remind_sent = 0");
        foreach ($rows as $r) {
            $waited = el_item_waiting_days($db, (string)$r['asked_at']);
            if ($waited < (int)$r['fud']) continue;   // 工作天還沒到（中間有連假）
            $claim->execute([$r['id']]);
            if ($claim->rowCount() < 1) continue;
            $q = mb_substr((string)$r['question'], 0, 30);
            $body = '「' . $r['title'] . '」第 ' . $r['seq'] . ' 條已等 ' . $waited . ' 個工作天未回覆'
                  . ($r['target_label'] ? '（' . $r['target_label'] . '）' : '') . "\n" . $q;
            personal_task_remind_user($db, (int)$r['user_id'], '⏰ 工程處理紀錄 待回覆提醒', $body, $url, 'eng-log');
            $sent++;
        }
    } catch (Throwable $e) { error_log('[eng_log] follow-up remind failed: ' . $e->getMessage()); }

    return $sent;
}

} // end if (!function_exists('el_bind_types'))
