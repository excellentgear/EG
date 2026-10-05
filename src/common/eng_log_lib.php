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
        // order_note：訂單追蹤「設計備註」自動建立的案件（2026-10-05），只供顯示，
        // 不出現在 eng_log.php 手動新建的選單——那一類一律由訂單追蹤那邊建立。
        'order_note'=> '訂單備註',
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

/* ============================ 共用小工具 ============================
 * 原本各自寫在 EngLog_API.php 裡（函式名相同），2026-10-05 收斂到這裡：
 * 新增的 el_item_upsert()/el_reply_add() 等共用函式也要用到同一套正規化，
 * 放兩處遲早走鐘（鐵律4）。EngLog_API.php 已移除同名定義，改吃這裡的。
 */
function el_norm_date($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = substr(str_replace('/', '-', $v), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
function el_norm_dt($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    if (strlen($v) === 16) $v .= ':00';
    return $v;
}
function el_norm_int($v) { $v = trim((string)$v); return $v === '' ? null : (int)$v; }

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
        // 對象是公司內部時，記下「使用者實際選的那個部門職務」——兼任者不可以事後回推，
        // 回推一律取職級最高那筆，會把使用者選的兼任職務顯示成主要職務（實際踩過）
        "ALTER TABLE eng_log_item ADD COLUMN target_post VARCHAR(80) NULL COMMENT '選定當下的部門＋職稱（兼任者以使用者選的為準）'",
        // 提案人：有些紀錄沒有詢問對象，是主管交辦／自己發現的處理紀錄
        "ALTER TABLE eng_log ADD COLUMN proposer_type VARCHAR(10) NULL COMMENT 'customer/maker/user'",
        "ALTER TABLE eng_log ADD COLUMN proposer_id VARCHAR(30) NULL",
        "ALTER TABLE eng_log ADD COLUMN proposer_label VARCHAR(120) NULL",
        "ALTER TABLE eng_log ADD COLUMN proposer_post VARCHAR(80) NULL COMMENT '公司內部提案人的部門＋職稱'",
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

            /* 廠商刻意只取「使用者明確綁定的那幾家」（上面掃 binds 時已收集），
               不從三軸索引補——索引裡的廠商是綁 BOM 時由 bom_ing 逐關自動展開的，
               一張 BOM 走過五六家外包，全部塞進標題會變成一長串不相干的廠商。
               索引仍保留全部廠商，所以用廠商查詢照樣查得到，只是標題不列。 */
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

/* ============================ 問題項／回覆：純資料操作 ============================
 * 給 EngLog_API.php 與訂單追蹤設計備註小工具共用（2026-10-05 由 EngLog_API.php 抽出）。
 * 刻意不含任何權限檢查、不含交易控制、不含 el_reindex()／updated_at——
 * 呼叫端各自有不同的權限模型（eng_log 本身的角色制 vs 訂單追蹤既有的設計備註權限），
 * 這裡只管資料本身（鐵律4：一份 SQL，兩種守門各自在呼叫端做）。
 * 驗證失敗丟 InvalidArgumentException（訊息可直接顯示給使用者）；其餘例外照常往外丟。
 */

/**
 * 新增／編輯一條問題項。呼叫端要自己開交易、自己呼叫 el_reindex()。
 * @param array $in 可用鍵：question/target_type/target_id/target_label/target_contact/
 *                  target_post/asked_at/follow_up_days/parent_item_id
 * @return int 問題項 id
 */
function el_item_upsert(PDO $db, int $logId, int $itemId, array $in, string $now, string $today): int
{
    $q = trim((string)($in['question'] ?? ''));
    if ($q === '') throw new InvalidArgumentException('請填寫問題內容');
    $tt = trim((string)($in['target_type'] ?? ''));
    if (!in_array($tt, ['customer', 'maker', 'user'], true)) $tt = null;
    $ti = trim((string)($in['target_id'] ?? ''));
    $tl = trim((string)($in['target_label'] ?? ''));
    $tc = trim((string)($in['target_contact'] ?? ''));
    $tp = trim((string)($in['target_post'] ?? ''));
    if ($tt !== null && $ti === '') throw new InvalidArgumentException('對象要從清單選擇（只打名字的話日後對方改名就對應不到）');
    $asked = el_norm_date($in['asked_at'] ?? '') ?? $today;
    if ($asked > $today) throw new InvalidArgumentException('提出日期不可以是未來日期');
    $fud = el_norm_int($in['follow_up_days'] ?? '');
    if ($fud !== null && ($fud < 1 || $fud > 365)) throw new InvalidArgumentException('催回覆天數請填 1～365');

    if ($itemId > 0) {
        $st = $db->prepare("SELECT * FROM eng_log_item WHERE id=? AND log_id=?");
        $st->execute([$itemId, $logId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) throw new InvalidArgumentException('查無此問題項，請重新整理');
        $resend = ((string)$cur['asked_at'] !== (string)$asked || (string)$cur['follow_up_days'] !== (string)$fud) ? 0 : (int)$cur['remind_sent'];
        $db->prepare("UPDATE eng_log_item SET question=?, target_type=?, target_id=?, target_label=?,
                      target_post=?, target_contact=?, asked_at=?, follow_up_days=?, remind_sent=?,
                      updated_at=? WHERE id=?")
           ->execute([$q, $tt, ($ti === '' ? null : $ti), ($tl === '' ? null : $tl), ($tp === '' ? null : $tp),
                      ($tc === '' ? null : $tc), $asked, $fud, $resend, $now, $itemId]);
        return $itemId;
    }

    $mx = $db->prepare("SELECT COALESCE(MAX(seq),0)+1 FROM eng_log_item WHERE log_id=?");
    $mx->execute([$logId]);
    $parent = el_norm_int($in['parent_item_id'] ?? '');
    if ($parent !== null) {
        $pc = $db->prepare("SELECT 1 FROM eng_log_item WHERE id=? AND log_id=?");
        $pc->execute([$parent, $logId]);
        if (!$pc->fetchColumn()) throw new InvalidArgumentException('要延伸的那一條問題不存在，請重新整理');
    }
    $db->prepare("INSERT INTO eng_log_item (log_id, parent_item_id, seq, question, target_type, target_id,
                  target_label, target_post, target_contact, asked_at, status, follow_up_days, remind_sent, created_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?, 'waiting', ?, 0, ?)")
       ->execute([$logId, $parent, (int)$mx->fetchColumn(), $q, $tt, ($ti === '' ? null : $ti),
                  ($tl === '' ? null : $tl), ($tp === '' ? null : $tp), ($tc === '' ? null : $tc),
                  $asked, $fud, $now]);
    return (int)$db->lastInsertId();
}

/** 手動改問題項狀態（已解決／不處理／退回待回覆）。 */
function el_item_set_status(PDO $db, int $logId, int $itemId, string $status, $conclusion, string $now): void
{
    if (!isset(el_item_status()[$status])) throw new InvalidArgumentException('狀態不正確');
    $conclusion = trim((string)$conclusion);
    if ($status === 'waiting') {
        // 退回待回覆時一併重置提醒，否則這條從此不會再催
        $db->prepare("UPDATE eng_log_item SET status='waiting', remind_sent=0, conclusion=?, updated_at=? WHERE id=? AND log_id=?")
           ->execute([($conclusion === '' ? null : $conclusion), $now, $itemId, $logId]);
    } else {
        $db->prepare("UPDATE eng_log_item SET status=?, conclusion=?, updated_at=? WHERE id=? AND log_id=?")
           ->execute([$status, ($conclusion === '' ? null : $conclusion), $now, $itemId, $logId]);
    }
}

/**
 * 新增回覆，可一次套用到多條問題項。呼叫端要自己開交易；附件轉正留給呼叫端處理
 * （只掛在第一則新回覆上，用回傳的 first_id）。
 * @param array $itemIds 問題項 id 陣列
 * @param array $in 可用鍵：content/replied_on/reply_by/channel
 * @return array ['ids'=>新回覆id陣列, 'first_id'=>第一筆的id或0]
 */
function el_reply_add(PDO $db, int $logId, array $itemIds, array $in, int $createdBy, string $now, string $today): array
{
    if (!$itemIds) throw new InvalidArgumentException('請至少勾選一條問題');
    $content = trim((string)($in['content'] ?? ''));
    if ($content === '') throw new InvalidArgumentException('請填寫回覆內容');
    // 回覆日期：未選＝今天；擋未來日期，其餘不限制（補登很久以前的事是正常的）
    $repliedOn = el_norm_date($in['replied_on'] ?? '') ?? $today;
    if ($repliedOn > $today) throw new InvalidArgumentException('回覆日期不可以是未來日期');
    $replyBy = trim((string)($in['reply_by'] ?? ''));
    $channel = trim((string)($in['channel'] ?? ''));
    if ($channel !== '' && !isset(el_channels()[$channel])) $channel = '';

    $newIds = []; $firstId = 0;
    $chk = $db->prepare("SELECT id FROM eng_log_item WHERE id=? AND log_id=?");
    $ins = $db->prepare("INSERT INTO eng_log_reply (item_id, log_id, replied_on, reply_by, channel, content, created_by, created_at)
                         VALUES (?,?,?,?,?,?,?,?)");
    $upd = $db->prepare("UPDATE eng_log_item SET status=CASE WHEN status='waiting' THEN 'answered' ELSE status END,
                         remind_sent=1, updated_at=? WHERE id=?");
    foreach ($itemIds as $iid) {
        $iid = (int)$iid;
        $chk->execute([$iid, $logId]);
        if (!$chk->fetchColumn()) continue;      // 不屬於這筆案件的問題項一律略過
        $ins->execute([$iid, $logId, $repliedOn, ($replyBy === '' ? null : $replyBy),
                       ($channel === '' ? null : $channel), $content, $createdBy, $now]);
        $rid = (int)$db->lastInsertId();
        $newIds[] = $rid;
        if ($firstId === 0) $firstId = $rid;
        $upd->execute([$now, $iid]);
    }
    if (!$newIds) throw new InvalidArgumentException('勾選的問題項不存在，請重新整理');
    return ['ids' => $newIds, 'first_id' => $firstId];
}

/* ============================ 訂單追蹤「設計備註」整合 ============================
 * 2026-10-05 新增：把 views/Sales/NewOrder_Track.php 的 ateNote 欄位接到 eng_log
 * 的資料模型上。訂單格子只跟「它自己綁定的那一個 eng_log 案件」互動，
 * 問題/回覆仍是上面那三支共用函式，這裡只負責「一張訂單對應唯一一個案件」。
 */

/**
 * 依 bind_type='order' 查這張訂單目前綁的案件，查不到才新建。
 * 不管案件目前 open/done 一律沿用同一筆——避免同一張訂單被重複呼叫而長出多個案件。
 * @param array $actor ['uid'=>int, 'dept_id'=>?int]
 */
function el_order_case_get_or_create(PDO $db, int $orderId, array $actor, string $now, string $today): int
{
    $st = $db->prepare("SELECT el.id FROM eng_log_bind b JOIN eng_log el ON el.id = b.log_id
                        WHERE b.bind_type='order' AND b.bind_id=? ORDER BY el.id LIMIT 1");
    $st->execute([(string)$orderId]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) return $id;

    $logNo = el_next_log_no($db, $today);
    $db->prepare("INSERT INTO eng_log (log_no, user_id, dept_id, title, log_type, status, visibility, created_at)
                  VALUES (?,?,?, '訂單備註', 'order_note', 'open', 'dept', ?)")
       ->execute([$logNo, (int)($actor['uid'] ?? 0), $actor['dept_id'] ?? null, $now]);
    $id = (int)$db->lastInsertId();
    $label = el_bind_label($db, 'order', (string)$orderId);
    $db->prepare("INSERT INTO eng_log_bind (log_id, bind_type, bind_id, bind_label, is_manual, sort_order)
                  VALUES (?, 'order', ?, ?, 0, 0)")
       ->execute([$id, (string)$orderId, $label]);
    el_reindex($db, $id);
    return $id;
}

/**
 * 依「底下是否還有非 resolved/dropped 的問題項」自動同步案件的 open/done 狀態，
 * 讓 eng_log.php 既有的案件篩選（待處理／已結案）對這批自動建立的案件也有意義。
 */
function el_order_case_sync_status(PDO $db, int $logId, string $now): void
{
    $st = $db->prepare("SELECT COUNT(*) FROM eng_log_item WHERE log_id=? AND status NOT IN ('resolved','dropped')");
    $st->execute([$logId]);
    $hasOpen = (int)$st->fetchColumn() > 0;
    if ($hasOpen) {
        $db->prepare("UPDATE eng_log SET status='open', closed_at=NULL, updated_at=? WHERE id=?")->execute([$now, $logId]);
    } else {
        $db->prepare("UPDATE eng_log SET status='done', closed_at=COALESCE(closed_at,?), updated_at=? WHERE id=?")
           ->execute([$now, $now, $logId]);
    }
}

/**
 * 批次版：多張訂單各自目前「未處理問題數」（非 resolved/dropped 的問題項數）。
 * 比照 qab_bom_scrap_sum_rows() 既有模式，避免清單頁逐列各查一次（N+1）。
 * @return array [order_id(int) => count(int)]，沒有案件或沒有未處理問題的訂單不會出現（視為 0）
 */
function el_order_open_item_counts(PDO $db, array $orderIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    try {
        $rows = $db->query("
            SELECT b.bind_id AS order_id, COUNT(*) AS cnt
            FROM eng_log_bind b JOIN eng_log_item i ON i.log_id = b.log_id
            WHERE b.bind_type='order' AND b.bind_id IN ({$in})
              AND i.status NOT IN ('resolved','dropped')
            GROUP BY b.bind_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $out[(int)$r['order_id']] = (int)$r['cnt'];
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 「這張訂單目前有沒有未處理的問題」EXISTS 片段，給 WHERE／CASE WHEN 直接接進去用。
 * 比照 order_client_reminder_lib.php 的 ocr_pending_exists_sql() 同一種寫法（鐵律4：
 * 全站只定義這一份，NewOrder_Track.php 的統計/篩選 SQL 全部吃同一份，不要各自拼一次）。
 * eng_log_bind(bind_type,bind_id) 與 eng_log_item(log_id,status) 皆有索引。
 */
function el_order_open_exists_sql(string $orderAlias = 'ot'): string
{
    return "EXISTS (SELECT 1 FROM eng_log_bind elb JOIN eng_log_item eli ON eli.log_id = elb.log_id
        WHERE elb.bind_type='order' AND elb.bind_id = {$orderAlias}.Order_id
        AND eli.status NOT IN ('resolved','dropped'))";
}

/**
 * 「業務」對象的預設人選＝這張訂單的打單人員（order_track.Created_By，存的是工號文字）。
 * 查不到就回 null（UI 端留空讓使用者手動選，不擋流程——舊訂單的 Created_By 可能是空的
 * 或對應不到現職人員）。
 * @param array $orderRow 至少要有 Created_By 鍵（order_track 的一列）
 */
function el_order_business_default(PDO $db, array $orderRow): ?array
{
    $uname = trim((string)($orderRow['Created_By'] ?? ''));
    if ($uname === '') return null;
    try {
        $st = $db->prepare("SELECT u.id, u.user_cname, d.name AS dept, p.name AS pos, COALESCE(p.sort_order,999) s
                            FROM `user` u
                            LEFT JOIN user_department_position_map m ON m.user_id = u.id
                            LEFT JOIN department d ON d.id = m.department_id
                            LEFT JOIN position p ON p.id = m.position_id
                            WHERE u.user_uname = ?
                            ORDER BY s ASC, m.is_main DESC, m.id ASC LIMIT 1");
        $st->execute([$uname]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['id'])) return null;
        return [
            'id'   => (int)$r['id'],
            'name' => (string)$r['user_cname'],
            'post' => trim(((string)$r['dept']) . ' ' . ((string)$r['pos'])),
        ];
    } catch (Throwable $e) { return null; }
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
