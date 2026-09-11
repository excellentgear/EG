<?php
/**
 * EngLog_API.php — 工程處理紀錄 後端 API
 *
 * 前端：views/TD/eng_log.php ｜ 共用邏輯：src/common/eng_log_lib.php
 *
 * 全部寫入都在 transaction 內；可見範圍一律由後端 el_visible_sql() 強制（鐵律8，
 * 不可只擋前端）。附件只存檔名，路徑即時組（鐵律5）。
 */
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）

require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/eng_log_lib.php';
require_once __DIR__ . '/../common/attach_lib.php';

$conn = new DBConnection();
$db = $conn->getPDO();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* download 要輸出二進位，不能先送 JSON 標頭 */
if ($action !== 'download') header('Content-Type: application/json; charset=utf-8');

function jout($data) { echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $extra = []) { echo json_encode(['success' => false, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE); exit; }

/* ── 登入與 CSRF ────────────────────────────────────────────────────────
   先驗登入再驗 CSRF：token 會在同一個請求裡重新產生，沒登入時比對必定不過，
   訊息講錯的話使用者只會一直重整卻永遠存不進去（見 session_gc_csrf_false_alarm）。*/
if (empty($_SESSION['id']) || empty($_SESSION['userName'])) {
    if ($action === 'download') { http_response_code(403); exit('未登入'); }
    jerr('尚未登入，請重新登入後再試', ['code' => 'LOGIN']);
}
if (empty($_SESSION['el_csrf'])) $_SESSION['el_csrf'] = bin2hex(random_bytes(16));

el_ensure_schema($db);
$ME = el_current_user($db);
$P  = el_perms($db, $ME);
if (!$P['canView']) {
    if ($action === 'download') { http_response_code(403); exit('無權限'); }
    jerr('沒有本模組的使用權限，請洽管理員申請', ['no_access' => true]);
}

/** 寫入類動作一律驗 CSRF */
function need_csrf() {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['el_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', ['code' => 'CSRF']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jerr('僅接受 POST');
}
function need_edit() { global $P; if (!$P['canEdit']) jerr('沒有編輯權限（唯讀檢閱角色）'); }

/* ── 小工具 ──────────────────────────────────────────────────────────── */

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

/** 這筆案件我看得到嗎（明細與所有子動作的唯一守門） */
function el_load_log(PDO $db, int $id, array $P): array {
    $st = $db->prepare("SELECT t.*, u.user_cname AS owner_name FROM eng_log t
                        LEFT JOIN `user` u ON u.id = t.user_id WHERE t.id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr('查無此紀錄（可能已被刪除，請重新整理）');
    [$vs, $vp] = el_visible_sql($P);
    $chk = $db->prepare("SELECT 1 FROM eng_log t WHERE t.id = ? AND {$vs}");
    $chk->execute(array_merge([$id], $vp));
    if (!$chk->fetchColumn()) jerr('沒有權限檢視這筆紀錄');
    return $row;
}
/** 可不可以改這筆（本人或管理員） */
function el_can_write(array $row, array $P): bool {
    return $P['canAdmin'] || ((int)$row['user_id'] === (int)$P['uid'] && $P['canEdit']);
}
function el_need_write(array $row, array $P) {
    if (!el_can_write($row, $P)) jerr('只能修改自己建立的紀錄（管理員才能改他人的）');
}

$now = $db->query("SELECT NOW()")->fetchColumn();   // 時間戳一律取 DB 時間（PHP 是 UTC）
$today = substr((string)$now, 0, 10);

/* ============================ 動作 ============================ */

if ($action === 'csrf_token') jout(['csrf' => (string)$_SESSION['el_csrf']]);

/* ── 開頁基本資料 ──────────────────────────────────────────────────── */
if ($action === 'bootstrap') {
    $types = [];
    foreach (el_bind_types() as $k => $v) $types[$k] = $v;
    jout([
        'csrf'  => (string)$_SESSION['el_csrf'],
        'perms' => $P,
        'me'    => ['id' => $P['uid'], 'name' => $P['name']],
        'dict'  => [
            'bind_types'   => $types,
            'carrier'      => el_carrier_types(),
            'multipart'    => el_multipart_types(),
            'item_status'  => el_item_status(),
            'log_status'   => el_log_status(),
            'visibility'   => el_visibility(),
            'channels'     => el_channels($db),
            'channels_all' => el_channels($db, true),
            'log_types'    => el_log_types(),
            'log_types_active' => el_log_types_active(),
            'auto_type'    => ['return'=>'return', 'order'=>'drawing'],
            'def_follow_up'=> el_default_follow_up_days(),
            'def_urgent'   => el_default_urgent_days(),
        ],
        'today' => $today,
    ]);
}

/**
 * 三軸篩選下拉的選項。
 *
 * 刻意只列「索引裡真的出現過的」客戶／料號／廠商，不是整份主檔——
 * 客戶主檔近千家、料號上萬筆，全部灌進下拉只會讓使用者在一堆查不到東西的選項裡亂翻。
 * 可見範圍同樣套 el_visible_sql()，否則會從下拉選項洩漏別人紀錄綁到誰。
 */
if ($action === 'filter_options') {
    [$vs, $vp] = el_visible_sql($P);
    $out = ['customers' => [], 'parts' => [], 'makers' => []];
    try {
        /* 顯示文字要把 ID 一起帶上（使用者要求可以打部分 ID 篩選）——
           共用的 data-eg-filter 是比對選項「顯示文字」，ID 不在文字裡就篩不到。 */
        $st = $db->prepare("SELECT DISTINCT x.customer_id AS id,
                                   CONCAT(COALESCE(c.customer, x.customer_id), '（', x.customer_id, '）') AS label
                            FROM eng_log_index x JOIN eng_log t ON t.id = x.log_id
                            LEFT JOIN customer_list c ON c.customer_id = x.customer_id
                            WHERE x.customer_id IS NOT NULL AND {$vs} ORDER BY c.customer LIMIT 500");
        $st->execute($vp);
        $out['customers'] = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $db->prepare("SELECT DISTINCT x.part_d_id AS id, d.D_Setting_Id AS label
                            FROM eng_log_index x JOIN eng_log t ON t.id = x.log_id
                            LEFT JOIN d_setting d ON d.d_id = x.part_d_id
                            WHERE x.part_d_id IS NOT NULL AND {$vs} ORDER BY d.D_Setting_Id LIMIT 500");
        $st->execute($vp);
        $out['parts'] = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $db->prepare("SELECT DISTINCT x.maker_id_no AS id,
                                   CONCAT(COALESCE(m.maker_id, x.maker_id_no), '（', x.maker_id_no, '）') AS label
                            FROM eng_log_index x JOIN eng_log t ON t.id = x.log_id
                            LEFT JOIN maker_list m ON m.maker_id_no = x.maker_id_no
                            WHERE x.maker_id_no IS NOT NULL AND {$vs} ORDER BY m.maker_id LIMIT 500");
        $st->execute($vp);
        $out['makers'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { jerr('讀取篩選選項失敗：' . $e->getMessage()); }
    jout($out);
}

/** 上方統計卡的數字（每張卡＝一個快捷篩選，數字與該篩選撈出來的筆數一致） */
if ($action === 'stats') {
    [$vs, $vp] = el_visible_sql($P);
    $def = el_default_follow_up_days();
    $waiting = "EXISTS(SELECT 1 FROM eng_log_item i WHERE i.log_id=t.id AND i.status='waiting')";
    $overdue = "EXISTS(SELECT 1 FROM eng_log_item i WHERE i.log_id=t.id AND i.status='waiting'
                    AND i.asked_at IS NOT NULL
                    AND i.asked_at <= DATE_SUB(CURDATE(), INTERVAL COALESCE(i.follow_up_days,{$def}) DAY))";
    $sql = "SELECT
              COUNT(*) AS total,
              SUM(t.status='open') AS open_cnt,
              SUM(t.status='open' AND {$waiting}) AS waiting_cnt,
              SUM(t.status='open' AND {$overdue}) AS overdue_cnt,
              SUM(t.status='open' AND t.deadline IS NOT NULL
                  AND DATE(t.deadline) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS week_cnt,
              SUM(t.status='done') AS done_cnt,
              SUM(NOT EXISTS(SELECT 1 FROM eng_log_index x WHERE x.log_id=t.id
                  AND (x.part_d_id IS NOT NULL OR x.customer_id IS NOT NULL OR x.maker_id_no IS NOT NULL))) AS unlinked_cnt
            FROM eng_log t WHERE {$vs}";
    $st = $db->prepare($sql);
    $st->execute($vp);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($r as $k => $v) $r[$k] = (int)$v;
    jout(['stats' => $r]);
}

/* ── 清單 ──────────────────────────────────────────────────────────── */
if ($action === 'list') {
    [$vs, $vp] = el_visible_sql($P);
    $where = [$vs]; $params = $vp;

    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        // 全表搜尋一律 LIKE '%詞%'，多關鍵字每個都要命中（可分散在不同欄位）。
        // 禁用 ngram FULLTEXT：料號 RC105-N03-A 這種含「-」的字串會回 0 筆。
        foreach (preg_split('/\s+/', $kw) as $w) {
            if ($w === '') continue;
            $like = '%' . $w . '%';
            $where[] = "(t.log_no LIKE ? OR t.title LIKE ? OR t.note LIKE ? OR t.conclusion LIKE ?
                     OR EXISTS(SELECT 1 FROM eng_log_bind b WHERE b.log_id=t.id AND (b.bind_label LIKE ? OR b.bind_id LIKE ?))
                     OR EXISTS(SELECT 1 FROM eng_log_item i WHERE i.log_id=t.id AND (i.question LIKE ? OR i.target_label LIKE ? OR i.target_contact LIKE ? OR i.conclusion LIKE ?))
                     OR EXISTS(SELECT 1 FROM eng_log_reply rp WHERE rp.log_id=t.id AND (rp.content LIKE ? OR rp.reply_by LIKE ?)))";
            $params = array_merge($params, array_fill(0, 12, $like));
        }
    }
    // 三軸篩選一律吃索引表
    $cust = trim((string)($_GET['customer'] ?? ''));
    if ($cust !== '') { $where[] = "EXISTS(SELECT 1 FROM eng_log_index x WHERE x.log_id=t.id AND x.customer_id=?)"; $params[] = $cust; }
    $part = trim((string)($_GET['part'] ?? ''));
    if ($part !== '') { $where[] = "EXISTS(SELECT 1 FROM eng_log_index x WHERE x.log_id=t.id AND x.part_d_id=?)"; $params[] = (int)$part; }
    $maker = trim((string)($_GET['maker'] ?? ''));
    if ($maker !== '') { $where[] = "EXISTS(SELECT 1 FROM eng_log_index x WHERE x.log_id=t.id AND x.maker_id_no=?)"; $params[] = $maker; }

    $type = trim((string)($_GET['log_type'] ?? ''));
    if ($type !== '') { $where[] = "t.log_type = ?"; $params[] = $type; }
    $mine = trim((string)($_GET['mine'] ?? ''));
    if ($mine === '1') { $where[] = "t.user_id = ?"; $params[] = (int)$P['uid']; }
    $d1 = el_norm_date($_GET['d1'] ?? ''); if ($d1) { $where[] = "DATE(t.created_at) >= ?"; $params[] = $d1; }
    $d2 = el_norm_date($_GET['d2'] ?? ''); if ($d2) { $where[] = "DATE(t.created_at) <= ?"; $params[] = $d2; }

    // 快捷篩選（過期／待處理／本週到期／未連結料號）
    $quick = trim((string)($_GET['quick'] ?? ''));
    $def = el_default_follow_up_days();
    $waitingExists = "EXISTS(SELECT 1 FROM eng_log_item i WHERE i.log_id=t.id AND i.status='waiting')";
    $overdueExists = "EXISTS(SELECT 1 FROM eng_log_item i WHERE i.log_id=t.id AND i.status='waiting'
                          AND i.asked_at IS NOT NULL
                          AND i.asked_at <= DATE_SUB(CURDATE(), INTERVAL COALESCE(i.follow_up_days,{$def}) DAY))";
    if     ($quick === 'open')     { $where[] = "t.status='open'"; }
    elseif ($quick === 'waiting')  { $where[] = "t.status='open' AND {$waitingExists}"; }
    elseif ($quick === 'overdue')  { $where[] = "t.status='open' AND {$overdueExists}"; }
    elseif ($quick === 'week')     { $where[] = "t.status='open' AND t.deadline IS NOT NULL AND DATE(t.deadline) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"; }
    elseif ($quick === 'done')     { $where[] = "t.status='done'"; }
    elseif ($quick === 'unlinked') { $where[] = "NOT EXISTS(SELECT 1 FROM eng_log_index x WHERE x.log_id=t.id
                                   AND (x.part_d_id IS NOT NULL OR x.customer_id IS NOT NULL OR x.maker_id_no IS NOT NULL))"; }

    $w = implode(' AND ', $where);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per  = (int)($_GET['per'] ?? 20);
    if (!in_array($per, [5,10,20,50,0], true)) $per = 20;

    $cst = $db->prepare("SELECT COUNT(*) FROM eng_log t WHERE {$w}");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();

    $lim = $per > 0 ? ' LIMIT ' . (($page - 1) * $per) . ',' . $per : '';
    $st = $db->prepare("
        SELECT t.*, u.user_cname AS owner_name,
               (SELECT COUNT(*) FROM eng_log_item i WHERE i.log_id=t.id) AS item_cnt,
               (SELECT COUNT(*) FROM eng_log_item i WHERE i.log_id=t.id AND i.status IN ('answered','resolved')) AS item_done,
               (SELECT COUNT(*) FROM eng_log_item i WHERE i.log_id=t.id AND i.status='waiting') AS item_wait,
               (SELECT MIN(i.asked_at) FROM eng_log_item i WHERE i.log_id=t.id AND i.status='waiting') AS oldest_wait,
               (SELECT COUNT(*) FROM eng_log_index x WHERE x.log_id=t.id
                  AND (x.part_d_id IS NOT NULL OR x.customer_id IS NOT NULL OR x.maker_id_no IS NOT NULL)) AS part_cnt
        FROM eng_log t LEFT JOIN `user` u ON u.id = t.user_id
        WHERE {$w} ORDER BY t.created_at DESC, t.id DESC{$lim}");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_map('intval', $ids));
        $bs = $db->query("SELECT log_id, bind_type, bind_id, bind_label, is_manual
                          FROM eng_log_bind WHERE log_id IN ({$in}) ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($bs as $b) $map[(int)$b['log_id']][] = $b;

        /* 只要展開得出料號就在清單上顯示（使用者要求）——包含綁 BOM／訂單／出貨單
           間接推導出來的，不是只有直接綁的那種。點下去要能開圖面，所以連
           d_setting.D_Setting_Id（料號文字）一起帶出來。 */
        $ps = $db->query("SELECT x.log_id, x.part_d_id AS pk, d.D_Setting_Id AS part_no
                          FROM eng_log_index x LEFT JOIN d_setting d ON d.d_id = x.part_d_id
                          WHERE x.log_id IN ({$in}) AND x.part_d_id IS NOT NULL
                          ORDER BY d.D_Setting_Id")->fetchAll(PDO::FETCH_ASSOC);
        $pmap = [];
        foreach ($ps as $p) $pmap[(int)$p['log_id']][] = ['pk' => (int)$p['pk'], 'part_no' => (string)$p['part_no']];

        // 客戶名稱：綁 BOM 自動推導出來的也要顯示（使用者要求列表看得到客戶）
        $cs = $db->query("SELECT x.log_id, COALESCE(c.customer, x.customer_id) AS name
                          FROM eng_log_index x LEFT JOIN customer_list c ON c.customer_id = x.customer_id
                          WHERE x.log_id IN ({$in}) AND x.customer_id IS NOT NULL
                          ORDER BY c.customer")->fetchAll(PDO::FETCH_ASSOC);
        $cmap = [];
        foreach ($cs as $c) { $n = trim((string)$c['name']); if ($n !== '') $cmap[(int)$c['log_id']][$n] = true; }

        foreach ($rows as &$r) {
            $r['binds'] = $map[(int)$r['id']] ?? [];
            $r['parts'] = $pmap[(int)$r['id']] ?? [];
            $r['customers'] = array_keys($cmap[(int)$r['id']] ?? []);
            $r['auto_title'] = el_auto_title($db, (int)$r['id'], (string)$r['log_type']);
            $r['title_manual'] = (string)($r['title_manual'] ?? '');
            // 綁了 BOM 時，點料號要開「只含這張 BOM 的圖檔」的 part_viewer（比照 OreadyReply）
            $r['bom'] = '';
            foreach ($r['binds'] as $b) {
                if ($b['bind_type'] === 'bom') { $r['bom'] = (string)$b['bind_id']; break; }
            }
            $r['wait_days'] = $r['oldest_wait'] ? el_item_waiting_days($db, (string)$r['oldest_wait']) : 0;
            $r['unlinked']  = ((int)$r['part_cnt'] === 0);
        }
        unset($r);
    }
    jout(['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
}

/* ── 明細 ──────────────────────────────────────────────────────────── */
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $row = el_load_log($db, $id, $P);

    $st = $db->prepare("SELECT * FROM eng_log_bind WHERE log_id=? ORDER BY sort_order, id");
    $st->execute([$id]);
    $binds = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT * FROM eng_log_item WHERE log_id=? ORDER BY seq, id");
    $st->execute([$id]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    $replies = [];
    if ($items) {
        $in = implode(',', array_map(fn($x) => (int)$x['id'], $items));
        $rs = $db->query("SELECT r.*, u.user_cname AS created_by_name FROM eng_log_reply r
                          LEFT JOIN `user` u ON u.id = r.created_by
                          WHERE r.item_id IN ({$in}) ORDER BY r.replied_on, r.id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $x) $replies[(int)$x['item_id']][] = $x;
    }
    /* 公司內部的對象要連「部門 職稱」一起顯示（使用者要求，不能只有人名）。
       依 people_lib 的挑法取職級最高那筆，兼任者顯示的就是他真正的簽核身分。 */
    $postMap = [];
    $uids = [];
    foreach ($items as $it) if (($it['target_type'] ?? '') === 'user' && $it['target_id'] !== null) $uids[] = (int)$it['target_id'];
    if ($uids) {
        $uin = implode(',', array_unique(array_filter($uids)));
        if ($uin !== '') {
            try {
                foreach ($db->query("SELECT m.user_id, d.name AS dept, p.name AS pos, COALESCE(p.sort_order,999) s
                                     FROM user_department_position_map m
                                     LEFT JOIN department d ON d.id = m.department_id
                                     LEFT JOIN position p ON p.id = m.position_id
                                     WHERE m.user_id IN ({$uin})
                                     ORDER BY s ASC, m.is_main DESC, m.id ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $u = (int)$r['user_id'];
                    if (isset($postMap[$u])) continue;   // 只取排序後的第一筆
                    $postMap[$u] = trim(((string)$r['dept']) . ' ' . ((string)$r['pos']));
                }
            } catch (Throwable $e) {}
        }
    }
    $def = el_default_follow_up_days();
    foreach ($items as &$it) {
        $it['target_post'] = (($it['target_type'] ?? '') === 'user')
            ? (string)($postMap[(int)$it['target_id']] ?? '') : '';
        $it['replies'] = $replies[(int)$it['id']] ?? [];
        $it['wait_days'] = ($it['status'] === 'waiting') ? el_item_waiting_days($db, (string)$it['asked_at']) : 0;
        $it['overdue'] = ($it['status'] === 'waiting' && $it['wait_days'] >= (int)($it['follow_up_days'] ?? $def));
    }
    unset($it);

    $st = $db->prepare("SELECT * FROM eng_log_file WHERE log_id=? AND status='active' ORDER BY id");
    $st->execute([$id]);
    $files = $st->fetchAll(PDO::FETCH_ASSOC);

    $row['can_write'] = el_can_write($row, $P);
    $row['auto_title'] = el_auto_title($db, $id, (string)$row['log_type']);
    $row['title_manual'] = (string)($row['title_manual'] ?? '');
    jout(['log' => $row, 'binds' => $binds, 'items' => $items, 'files' => $files,
          'unlinked' => !el_has_part($db, $id)]);
}

/* ── 新增／編輯案件 ─────────────────────────────────────────────────── */
if ($action === 'save_log') {
    need_csrf(); need_edit();
    $id    = (int)($_POST['id'] ?? 0);
    // 標題欄位存的是「使用者自己打的那一段」；自動標題每次讀取時即時算，不存 DB
    $title = trim((string)($_POST['title'] ?? ''));
    if (mb_strlen($title) > 200) jerr('標題請控制在 200 字以內');

    $logType = trim((string)($_POST['log_type'] ?? 'other'));
    $vis     = trim((string)($_POST['visibility'] ?? 'dept'));
    if (!isset(el_visibility()[$vis])) $vis = 'dept';
    $deadline = el_norm_dt($_POST['deadline'] ?? '');
    $remind   = el_norm_int($_POST['remind_before_minutes'] ?? '');
    $urgent   = el_norm_int($_POST['urgent_days'] ?? '');
    $note     = trim((string)($_POST['note'] ?? ''));

    $binds = json_decode((string)($_POST['binds'] ?? '[]'), true);
    if (!is_array($binds)) $binds = [];
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) $items = [];
    $tempFiles = json_decode((string)($_POST['temp_files'] ?? '[]'), true);
    if (!is_array($tempFiles)) $tempFiles = [];

    $bindTypes = el_bind_types();

    /* 綁定對象必填（2026-09-11 使用者要求）：沒有綁定的紀錄日後用客戶／料號／廠商
       一筆都查不到，等於存了沒有用。 */
    if (!$binds) jerr('請至少綁定一個對象（BOM／料號／訂單／出貨單／退貨單／客戶／廠商）');

    /* 類型沒選時依綁定自動判定（退貨單＝退貨、訂單＝批圖），使用者仍可自己改 */
    if ($logType === '' || $logType === 'other') {
        foreach ($binds as $b) {
            $auto = el_auto_type_for_bind((string)($b['bind_type'] ?? ''));
            if ($auto !== '') { $logType = $auto; break; }
        }
    }
    if ($logType === '') $logType = 'other';

    /* 對象一定要帶 ID（使用者要求）：只打名字的話，對方主檔改名就對應不到，
       三軸索引也展不出這一家。前端擋一次、這裡同規則再擋一次（鐵律8）。 */
    foreach ($items as $k => $it) {
        $tt = trim((string)($it['target_type'] ?? ''));
        if ($tt === '') continue;
        if (!in_array($tt, ['customer', 'maker', 'user'], true)) jerr('第 ' . ($k + 1) . ' 條的對象類別不正確');
        if (trim((string)($it['target_id'] ?? '')) === '')
            jerr('第 ' . ($k + 1) . ' 條的對象要從清單選擇（只打名字的話日後對方改名就對應不到）');
    }

    try {
        $db->beginTransaction();

        if ($id > 0) {
            $st = $db->prepare("SELECT * FROM eng_log WHERE id=?");
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) { $db->rollBack(); jerr('查無此紀錄（可能已被刪除，請重新整理）'); }
            if (!el_can_write($cur, $P)) { $db->rollBack(); jerr('只能修改自己建立的紀錄（管理員才能改他人的）'); }
            // 期限或提醒被改過 → 重置 remind_sent，否則改了期限也不會再提醒
            $resend = ($cur['deadline'] !== $deadline || (string)$cur['remind_before_minutes'] !== (string)$remind) ? 0 : (int)$cur['remind_sent'];
            $db->prepare("UPDATE eng_log SET title=?, title_manual=?, log_type=?, visibility=?, deadline=?,
                          remind_before_minutes=?, remind_sent=?, urgent_days=?, note=?, updated_at=? WHERE id=?")
               ->execute([$title, ($title === '' ? null : $title), $logType, $vis, $deadline,
                          $remind, $resend, $urgent, ($note === '' ? null : $note), $now, $id]);
        } else {
            $logNo = el_next_log_no($db, $today);
            $db->prepare("INSERT INTO eng_log (log_no, user_id, dept_id, title, title_manual, log_type, status,
                          visibility, deadline, remind_before_minutes, remind_sent, urgent_days, note, created_at)
                          VALUES (?,?,?,?,?,?,'open',?,?,?,0,?,?,?)")
               ->execute([$logNo, (int)$P['uid'], $P['dept_id'], $title, ($title === '' ? null : $title),
                          $logType, $vis, $deadline, $remind, $urgent, ($note === '' ? null : $note), $now]);
            $id = (int)$db->lastInsertId();
        }

        /* 綁定：整批重寫（數量少，比逐筆比對簡單且不會漏） */
        $db->prepare("DELETE FROM eng_log_bind WHERE log_id=?")->execute([$id]);
        $insB = $db->prepare("INSERT INTO eng_log_bind (log_id, bind_type, bind_id, bind_label, is_manual, sel_parts, meta, sort_order)
                              VALUES (?,?,?,?,?,?,?,?)");
        $seen = []; $sort = 0;
        foreach ($binds as $b) {
            $t = (string)($b['bind_type'] ?? '');
            $bid = trim((string)($b['bind_id'] ?? ''));
            if (!isset($bindTypes[$t]) || $bid === '') continue;
            $key = $t . '|' . $bid;
            if (isset($seen[$key])) continue;         // 去重
            $seen[$key] = true;
            $manual = (int)$bindTypes[$t]['manual'];
            $label = $manual ? $bid : (el_bind_label($db, $t, $bid) ?? trim((string)($b['bind_label'] ?? '')));
            $sel = null;
            if (in_array($t, el_multipart_types(), true) && isset($b['sel_parts']) && is_array($b['sel_parts'])) {
                $sel = json_encode(array_values(array_map('intval', $b['sel_parts'])));
            }
            // meta：BOM 綁定時記下使用者選的是哪一關製程與當時的發包廠商
            $meta = null;
            if (isset($b['meta']) && is_array($b['meta'])) $meta = json_encode($b['meta'], JSON_UNESCAPED_UNICODE);
            $insB->execute([$id, $t, $bid, ($label === '' ? null : $label), $manual, $sel, $meta, $sort++]);
        }

        /* 新建時可一次帶入多條問題（可增列表格一口氣打完） */
        if ($items) {
            $mx = $db->prepare("SELECT COALESCE(MAX(seq),0) FROM eng_log_item WHERE log_id=?");
            $mx->execute([$id]);
            $seq = (int)$mx->fetchColumn();
            $insI = $db->prepare("INSERT INTO eng_log_item (log_id, seq, question, target_type, target_id, target_label,
                                  target_contact, asked_at, status, follow_up_days, remind_sent, created_at)
                                  VALUES (?,?,?,?,?,?,?,?, 'waiting', ?, 0, ?)");
            foreach ($items as $it) {
                $q = trim((string)($it['question'] ?? ''));
                if ($q === '') continue;
                $tt = trim((string)($it['target_type'] ?? ''));
                if (!in_array($tt, ['customer', 'maker', 'user'], true)) $tt = null;
                $ti = trim((string)($it['target_id'] ?? ''));
                $insI->execute([$id, ++$seq, $q, $tt, ($ti === '' ? null : $ti),
                                (trim((string)($it['target_label'] ?? '')) ?: null),
                                (trim((string)($it['target_contact'] ?? '')) ?: null),
                                (el_norm_date($it['asked_at'] ?? '') ?? $today),
                                el_norm_int($it['follow_up_days'] ?? ''), $now]);
            }
        }

        /* 暫存附件轉正（新增中就能上傳＝鐵律5） */
        if ($tempFiles) {
            $up = $db->prepare("UPDATE eng_log_file SET log_id=?, owner_type='log', owner_id=?, status='active', expire_at=NULL
                                WHERE id=? AND status='temp' AND uploaded_by=?");
            foreach ($tempFiles as $fid) $up->execute([$id, $id, (int)$fid, (int)$P['uid']]);
        }

        el_reindex($db, $id);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }

    jout(['id' => $id, 'warning' => el_manual_only_warning($binds), 'unlinked' => !el_has_part($db, $id)]);
}

/* ── 刪除案件 ──────────────────────────────────────────────────────── */
if ($action === 'delete_log') {
    need_csrf(); need_edit();
    $id = (int)($_POST['id'] ?? 0);
    $row = el_load_log($db, $id, $P);
    el_need_write($row, $P);
    try {
        $db->beginTransaction();
        // 附件實體檔一併刪除（只刪這筆自己的）
        $st = $db->prepare("SELECT file_name FROM eng_log_file WHERE log_id=?");
        $st->execute([$id]);
        $dir = eg_attach_dir($db, 'eng_log_nas_dir', '工程處理紀錄');
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fn) { @unlink($dir . $fn); }
        foreach (['eng_log_file','eng_log_reply','eng_log_item','eng_log_bind','eng_log_index','eng_log_step'] as $t)
            $db->prepare("DELETE FROM {$t} WHERE log_id=?")->execute([$id]);
        $db->prepare("DELETE FROM eng_log WHERE id=?")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('刪除失敗：' . $e->getMessage());
    }
    jout([]);
}

/* ── 結案／重新開啟 ─────────────────────────────────────────────────── */
if ($action === 'close_log') {
    need_csrf(); need_edit();
    $id = (int)($_POST['id'] ?? 0);
    $row = el_load_log($db, $id, $P);
    el_need_write($row, $P);
    // 點開即刷新鐵則：已經被別人結案了就擋下並請對方重新整理
    if ($row['status'] === 'done') jerr('這筆已經結案了，請重新整理後查看');
    // 結論改為選填（2026-09-11 使用者指正：不該強迫寫）
    $conclusion = trim((string)($_POST['conclusion'] ?? ''));
    if (mb_strlen($conclusion) > 500) jerr('結論請控制在 500 字以內');
    $db->prepare("UPDATE eng_log SET status='done', conclusion=?, closed_at=?, updated_at=? WHERE id=?")
       ->execute([$conclusion, $now, $now, $id]);
    jout([]);
}
if ($action === 'reopen_log') {
    need_csrf(); need_edit();
    $id = (int)($_POST['id'] ?? 0);
    $row = el_load_log($db, $id, $P);
    el_need_write($row, $P);
    if ($row['status'] !== 'done') jerr('這筆目前不是已結案狀態，請重新整理後查看');
    $db->prepare("UPDATE eng_log SET status='open', closed_at=NULL, updated_at=? WHERE id=?")->execute([$now, $id]);
    jout([]);
}

/* ── 問題項 ────────────────────────────────────────────────────────── */
if ($action === 'item_save') {
    need_csrf(); need_edit();
    $logId = (int)($_POST['log_id'] ?? 0);
    $row = el_load_log($db, $logId, $P);
    el_need_write($row, $P);
    $itemId = (int)($_POST['id'] ?? 0);
    $q = trim((string)($_POST['question'] ?? ''));
    if ($q === '') jerr('請填寫問題內容');

    $tt = trim((string)($_POST['target_type'] ?? ''));
    if (!in_array($tt, ['customer', 'maker', 'user'], true)) $tt = null;
    $ti = trim((string)($_POST['target_id'] ?? ''));
    $tl = trim((string)($_POST['target_label'] ?? ''));
    $tc = trim((string)($_POST['target_contact'] ?? ''));
    // 對象一定要帶 ID（同 save_log）：只打名字的話對方改名就對應不到，索引也展不出來
    if ($tt !== null && $ti === '') jerr('對象要從清單選擇（只打名字的話日後對方改名就對應不到）');
    $asked = el_norm_date($_POST['asked_at'] ?? '') ?? $today;
    if ($asked > $today) jerr('提出日期不可以是未來日期');
    $fud = el_norm_int($_POST['follow_up_days'] ?? '');
    if ($fud !== null && ($fud < 1 || $fud > 365)) jerr('催回覆天數請填 1～365');

    try {
        $db->beginTransaction();
        if ($itemId > 0) {
            $st = $db->prepare("SELECT * FROM eng_log_item WHERE id=? AND log_id=?");
            $st->execute([$itemId, $logId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) { $db->rollBack(); jerr('查無此問題項，請重新整理'); }
            $resend = ((string)$cur['asked_at'] !== (string)$asked || (string)$cur['follow_up_days'] !== (string)$fud) ? 0 : (int)$cur['remind_sent'];
            $db->prepare("UPDATE eng_log_item SET question=?, target_type=?, target_id=?, target_label=?,
                          target_contact=?, asked_at=?, follow_up_days=?, remind_sent=?, updated_at=? WHERE id=?")
               ->execute([$q, $tt, ($ti === '' ? null : $ti), ($tl === '' ? null : $tl),
                          ($tc === '' ? null : $tc), $asked, $fud, $resend, $now, $itemId]);
        } else {
            $mx = $db->prepare("SELECT COALESCE(MAX(seq),0)+1 FROM eng_log_item WHERE log_id=?");
            $mx->execute([$logId]);
            // 延伸問題：對方回覆之後才衍生出來的小問題，掛在原問題底下
            $parent = el_norm_int($_POST['parent_item_id'] ?? '');
            if ($parent !== null) {
                $pc = $db->prepare("SELECT 1 FROM eng_log_item WHERE id=? AND log_id=?");
                $pc->execute([$parent, $logId]);
                if (!$pc->fetchColumn()) { $db->rollBack(); jerr('要延伸的那一條問題不存在，請重新整理'); }
            }
            $db->prepare("INSERT INTO eng_log_item (log_id, parent_item_id, seq, question, target_type, target_id,
                          target_label, target_contact, asked_at, status, follow_up_days, remind_sent, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?, 'waiting', ?, 0, ?)")
               ->execute([$logId, $parent, (int)$mx->fetchColumn(), $q, $tt, ($ti === '' ? null : $ti),
                          ($tl === '' ? null : $tl), ($tc === '' ? null : $tc), $asked, $fud, $now]);
            $itemId = (int)$db->lastInsertId();
        }
        el_reindex($db, $logId);      // 對象改了，索引要跟著更新
        $db->prepare("UPDATE eng_log SET updated_at=? WHERE id=?")->execute([$now, $logId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }
    jout(['id' => $itemId]);
}

if ($action === 'item_delete') {
    need_csrf(); need_edit();
    $logId = (int)($_POST['log_id'] ?? 0);
    $row = el_load_log($db, $logId, $P);
    el_need_write($row, $P);
    $itemId = (int)($_POST['id'] ?? 0);
    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT file_name FROM eng_log_file WHERE owner_type='reply' AND owner_id IN
                            (SELECT id FROM eng_log_reply WHERE item_id=?)
                            UNION SELECT file_name FROM eng_log_file WHERE owner_type='item' AND owner_id=?");
        $st->execute([$itemId, $itemId]);
        $dir = eg_attach_dir($db, 'eng_log_nas_dir', '工程處理紀錄');
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fn) { @unlink($dir . $fn); }
        $db->prepare("DELETE FROM eng_log_file WHERE owner_type='reply' AND owner_id IN
                      (SELECT id FROM eng_log_reply WHERE item_id=?)")->execute([$itemId]);
        $db->prepare("DELETE FROM eng_log_file WHERE owner_type='item' AND owner_id=?")->execute([$itemId]);
        $db->prepare("DELETE FROM eng_log_reply WHERE item_id=?")->execute([$itemId]);
        $db->prepare("DELETE FROM eng_log_item WHERE id=? AND log_id=?")->execute([$itemId, $logId]);
        el_reindex($db, $logId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('刪除失敗：' . $e->getMessage());
    }
    jout([]);
}

/* 手動改問題項狀態（已解決／不處理／退回待回覆） */
if ($action === 'item_status') {
    need_csrf(); need_edit();
    $logId = (int)($_POST['log_id'] ?? 0);
    $row = el_load_log($db, $logId, $P);
    el_need_write($row, $P);
    $itemId = (int)($_POST['id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    if (!isset(el_item_status()[$status])) jerr('狀態不正確');
    $conclusion = trim((string)($_POST['conclusion'] ?? ''));
    if ($status === 'waiting') {
        // 退回待回覆時一併重置提醒，否則這條從此不會再催
        $db->prepare("UPDATE eng_log_item SET status='waiting', remind_sent=0, conclusion=?, updated_at=? WHERE id=? AND log_id=?")
           ->execute([($conclusion === '' ? null : $conclusion), $now, $itemId, $logId]);
    } else {
        $db->prepare("UPDATE eng_log_item SET status=?, conclusion=?, updated_at=? WHERE id=? AND log_id=?")
           ->execute([$status, ($conclusion === '' ? null : $conclusion), $now, $itemId, $logId]);
    }
    jout([]);
}

/**
 * 再等 N 天：催過一次、對方說「再給我兩天」時用。
 * 不做這個的話，remind_sent 已經是 1，這條問題從此再也不會提醒（personal_task 沒有這個需求，
 * 因為期限只會到一次）。
 */
if ($action === 'item_snooze') {
    need_csrf(); need_edit();
    $logId = (int)($_POST['log_id'] ?? 0);
    $row = el_load_log($db, $logId, $P);
    el_need_write($row, $P);
    $itemId = (int)($_POST['id'] ?? 0);
    $days = (int)($_POST['days'] ?? 0);
    if ($days < 1 || $days > 365) jerr('請填 1～365 天');
    $st = $db->prepare("SELECT asked_at, follow_up_days FROM eng_log_item WHERE id=? AND log_id=?");
    $st->execute([$itemId, $logId]);
    $cur = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cur) jerr('查無此問題項，請重新整理');
    // 以「已等的工作天 + 再等 N 天」當新門檻，這樣下次提醒就會落在 N 天之後
    $waited = el_item_waiting_days($db, (string)$cur['asked_at']);
    $db->prepare("UPDATE eng_log_item SET follow_up_days=?, remind_sent=0, updated_at=? WHERE id=?")
       ->execute([$waited + $days, $now, $itemId]);
    jout(['follow_up_days' => $waited + $days]);
}

/* ── 回覆（可一次套用到多條問題） ────────────────────────────────────── */
if ($action === 'reply_add') {
    need_csrf(); need_edit();
    $logId = (int)($_POST['log_id'] ?? 0);
    $row = el_load_log($db, $logId, $P);
    el_need_write($row, $P);

    $itemIds = json_decode((string)($_POST['item_ids'] ?? '[]'), true);
    if (!is_array($itemIds) || !$itemIds) jerr('請至少勾選一條問題');
    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') jerr('請填寫回覆內容');

    // 回覆日期：未選＝今天（使用者明確要求）；擋未來日期，其餘不限制（補登很久以前的事是正常的）
    $repliedOn = el_norm_date($_POST['replied_on'] ?? '') ?? $today;
    if ($repliedOn > $today) jerr('回覆日期不可以是未來日期');

    $replyBy = trim((string)($_POST['reply_by'] ?? ''));
    $channel = trim((string)($_POST['channel'] ?? ''));
    if ($channel !== '' && !isset(el_channels()[$channel])) $channel = '';
    $tempFiles = json_decode((string)($_POST['temp_files'] ?? '[]'), true);
    if (!is_array($tempFiles)) $tempFiles = [];

    $newIds = [];
    try {
        $db->beginTransaction();
        $chk = $db->prepare("SELECT id FROM eng_log_item WHERE id=? AND log_id=?");
        $ins = $db->prepare("INSERT INTO eng_log_reply (item_id, log_id, replied_on, reply_by, channel, content, created_by, created_at)
                             VALUES (?,?,?,?,?,?,?,?)");
        $upd = $db->prepare("UPDATE eng_log_item SET status=CASE WHEN status='waiting' THEN 'answered' ELSE status END,
                             remind_sent=1, updated_at=? WHERE id=?");
        $first = true;
        foreach ($itemIds as $iid) {
            $iid = (int)$iid;
            $chk->execute([$iid, $logId]);
            if (!$chk->fetchColumn()) continue;      // 不屬於這筆案件的問題項一律略過
            $ins->execute([$iid, $logId, $repliedOn, ($replyBy === '' ? null : $replyBy),
                           ($channel === '' ? null : $channel), $content, (int)$P['uid'], $now]);
            $rid = (int)$db->lastInsertId();
            $newIds[] = $rid;
            $upd->execute([$now, $iid]);
            // 附件只掛在第一則（同一份檔案不重複複製到每一條問題）
            if ($first && $tempFiles) {
                $up = $db->prepare("UPDATE eng_log_file SET log_id=?, owner_type='reply', owner_id=?, status='active', expire_at=NULL
                                    WHERE id=? AND status='temp' AND uploaded_by=?");
                foreach ($tempFiles as $fid) $up->execute([$logId, $rid, (int)$fid, (int)$P['uid']]);
                $first = false;
            }
        }
        if (!$newIds) { $db->rollBack(); jerr('勾選的問題項不存在，請重新整理'); }
        $db->prepare("UPDATE eng_log SET updated_at=? WHERE id=?")->execute([$now, $logId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage());
    }
    jout(['ids' => $newIds, 'count' => count($newIds)]);
}

if ($action === 'reply_delete') {
    need_csrf(); need_edit();
    $logId = (int)($_POST['log_id'] ?? 0);
    $row = el_load_log($db, $logId, $P);
    el_need_write($row, $P);
    $rid = (int)($_POST['id'] ?? 0);
    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT item_id FROM eng_log_reply WHERE id=? AND log_id=?");
        $st->execute([$rid, $logId]);
        $iid = (int)$st->fetchColumn();
        if (!$iid) { $db->rollBack(); jerr('查無此回覆，請重新整理'); }
        $fs = $db->prepare("SELECT file_name FROM eng_log_file WHERE owner_type='reply' AND owner_id=?");
        $fs->execute([$rid]);
        $dir = eg_attach_dir($db, 'eng_log_nas_dir', '工程處理紀錄');
        foreach ($fs->fetchAll(PDO::FETCH_COLUMN) as $fn) { @unlink($dir . $fn); }
        $db->prepare("DELETE FROM eng_log_file WHERE owner_type='reply' AND owner_id=?")->execute([$rid]);
        $db->prepare("DELETE FROM eng_log_reply WHERE id=?")->execute([$rid]);
        // 刪光了就退回待回覆（狀態由有沒有回覆推導）
        $st2 = $db->prepare("SELECT status FROM eng_log_item WHERE id=?");
        $st2->execute([$iid]);
        $newStatus = el_item_auto_status($db, $iid, (string)$st2->fetchColumn());
        $db->prepare("UPDATE eng_log_item SET status=?, updated_at=? WHERE id=?")->execute([$newStatus, $now, $iid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('刪除失敗：' . $e->getMessage());
    }
    jout([]);
}

/* ── 綁定搜尋 ──────────────────────────────────────────────────────── */
if ($action === 'bind_search') {
    $type = trim((string)($_GET['type'] ?? ''));
    $kw   = trim((string)($_GET['kw'] ?? ''));
    if (!isset(el_bind_types()[$type])) jerr('綁定型別不正確');
    if ($kw === '') jout(['rows' => []]);
    $like = '%' . $kw . '%';
    $rows = [];
    /* 單據類（BOM／訂單／出貨單／退貨單）一律「單號、客戶名稱、客戶ID、料號」都能搜——
       實際作業時手上未必有單號，多半是先想到「和大那張 RC105 的單」。 */
    try {
        switch ($type) {
            case 'bom':
                $st = $db->prepare("SELECT b.bom AS id, b.bom AS label,
                                           CONCAT(COALESCE(b.Client_Name,''), '　', COALESCE(b.d_id,'')) AS sub
                                    FROM bom b
                                    WHERE b.bom LIKE ? OR b.Client_Name LIKE ? OR b.d_id LIKE ?
                                    ORDER BY b.bom DESC LIMIT 30");
                $st->execute([$like, $like, $like]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); break;
            case 'part':
                $st = $db->prepare("SELECT d.d_id AS id, d.D_Setting_Id AS label,
                                           CONCAT(COALESCE(c.customer,''), '　', COALESCE(d.Drawing_No,'')) AS sub
                                    FROM d_setting d LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                                    WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ?
                                       OR c.customer LIKE ? OR d.Customer_Id LIKE ?
                                    ORDER BY d.D_Setting_Id LIMIT 30");
                $st->execute([$like, $like, $like, $like]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); break;
            case 'order':
                $st = $db->prepare("SELECT o.Order_id AS id, o.Order_oo AS label,
                                           CONCAT(COALESCE(o.Client_name,''), '　', COALESCE(o.d_id,'')) AS sub
                                    FROM order_track o
                                    WHERE o.Order_oo LIKE ? OR o.Client_name LIKE ?
                                       OR o.Client_name_ID LIKE ? OR o.d_id LIKE ?
                                    ORDER BY o.Order_id DESC LIMIT 30");
                $st->execute([$like, $like, $like, $like]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); break;
            /* 出貨單／退貨單要能用料號搜，但**不可以**寫成相關子查詢：
               is_list 有 37,745 列，`EXISTS(... WHERE i2.IS_number = i.IS_number ...)` 會對每一列
               再掃一次整張表，實測直接把請求拖到逾時。改成兩段：先用料號文字查出 d_id（單次、
               走 D_Setting_Id 索引），再用 `d_setting_id IN (...)` 過濾，成本與命中數無關。 */
            case 'ship':
            case 'return':
                $dids = [];
                $q = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id LIKE ? LIMIT 200");
                $q->execute([$like]);
                $dids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
                $didIn = $dids ? (' OR x.d_setting_id IN (' . implode(',', $dids) . ')') : '';
                if ($type === 'ship') {
                    $st = $db->prepare("SELECT x.IS_number AS id, x.IS_number AS label,
                                               CONCAT(COALESCE(x.Client_name,''), '　', COALESCE(MIN(x.Order_date),''),
                                                      '　', COUNT(DISTINCT x.d_setting_id), ' 個料號') AS sub
                                        FROM is_list x
                                        WHERE (x.IS_number LIKE ? OR x.Client_name LIKE ? OR x.Client_id LIKE ?{$didIn})
                                        GROUP BY x.IS_number, x.Client_name ORDER BY x.IS_number DESC LIMIT 30");
                    $st->execute([$like, $like, $like]);
                } else {
                    $st = $db->prepare("SELECT x.IR_no AS id, x.IR_no AS label,
                                               CONCAT(COALESCE(x.Client_name,''), '　', COALESCE(MIN(x.IR_date),''),
                                                      '　', COUNT(DISTINCT x.d_setting_id), ' 個料號') AS sub
                                        FROM ir_track x
                                        WHERE (x.IR_no LIKE ? OR x.Client_name LIKE ?{$didIn})
                                        GROUP BY x.IR_no, x.Client_name ORDER BY x.IR_no DESC LIMIT 30");
                    $st->execute([$like, $like]);
                }
                $rows = $st->fetchAll(PDO::FETCH_ASSOC); break;
            case 'customer':
                $st = $db->prepare("SELECT customer_id AS id, customer AS label, customer_id AS sub FROM customer_list
                                    WHERE customer LIKE ? OR customer_id LIKE ? ORDER BY customer LIMIT 30");
                $st->execute([$like, $like]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); break;
            case 'maker':
                $st = $db->prepare("SELECT maker_id_no AS id, maker_id AS label, maker_id_all AS sub FROM maker_list
                                    WHERE maker_id LIKE ? OR maker_id_all LIKE ? OR maker_id_no LIKE ?
                                    ORDER BY maker_id LIMIT 30");
                $st->execute([$like, $like, $like]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); break;
            default:
                jout(['rows' => []]);   // 手填單號不需要搜尋
        }
    } catch (Throwable $e) { jerr('搜尋失敗：' . $e->getMessage()); }
    jout(['rows' => $rows]);
}

/**
 * 問題對象＝公司內部時的部門清單／部門底下的人員。
 *
 * 人員一律走 people_lib 的 eg_people_list()（鐵律／ai-rules/08 第五節，禁止各頁自寫人員 SQL）：
 * 只列未離職者、依職稱 sort_order 由高到低排序、長期請假者標記假別期間。
 * 帶 dept_ids 時**含兼任**——某人主職在技術部、兼任生管組組長，在生管組底下也找得到他。
 */
if ($action === 'dept_list') {
    $rows = [];
    try {
        $rows = $db->query("SELECT id, name FROM department
                            ORDER BY COALESCE(sort_order,999), id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { jerr('讀取部門失敗：' . $e->getMessage()); }
    jout(['rows' => $rows]);
}
if ($action === 'dept_people') {
    require_once __DIR__ . '/../common/people_lib.php';
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId <= 0) jout(['rows' => []]);
    $out = [];
    try {
        $people = eg_people_list($db, ['dept_ids' => [$deptId]]);
        foreach ($people as $p) {
            $label = trim((string)($p['position_name'] ?? '')) !== ''
                   ? $p['position_name'] . '　' . $p['user_cname'] : $p['user_cname'];
            if (!empty($p['leave_note'])) $label .= '（' . $p['leave_note'] . '）';
            $out[] = ['id' => (string)$p['id'], 'label' => (string)$p['user_cname'],
                      'display' => $label, 'position' => (string)($p['position_name'] ?? ''),
                      'dept' => (string)($p['dept_name'] ?? '')];
        }
    } catch (Throwable $e) { jerr('讀取人員失敗：' . $e->getMessage()); }
    jout(['rows' => $out]);
}

/**
 * 綁定 BOM 的逐關製程進度（顯示方式比照 views/user/personal_task.php 的 BOM 製程條）。
 * 走共用的 eg_bom_progress()，與個人工作紀錄同一套口徑，不另寫一份。
 */
if ($action === 'bom_flow') {
    require_once __DIR__ . '/../common/bom_progress_lib.php';
    $boms = json_decode((string)($_GET['boms'] ?? '[]'), true);
    if (!is_array($boms)) $boms = [];
    $out = [];
    foreach (array_slice($boms, 0, 40) as $b) {
        $b = trim((string)$b);
        if ($b === '') continue;
        $p = eg_bom_progress($db, $b);
        if ($p) $out[$b] = $p;
    }
    jout(['flows' => $out]);
}

/**
 * 一張 BOM 的逐關製程＋目前發包廠商（類型＝製程中時挑「哪一關出問題」）。
 * 選到的那一關若已發包就自動帶出廠商；沒發包就讓使用者自己搜廠商（可不綁）。
 */
if ($action === 'bom_processes') {
    $bom = trim((string)($_GET['bom'] ?? ''));
    if ($bom === '') jout(['rows' => []]);
    jout(['rows' => el_bom_processes($db, $bom)]);
}

/* ── 回覆方式選項的維護（僅管理員） ─────────────────────────────────── */
if ($action === 'channel_list') {
    jout(['rows' => el_channel_rows($db)]);
}
if ($action === 'channel_save') {
    need_csrf();
    if (!$P['canAdmin']) jerr('只有管理員可以維護回覆方式');
    $cid  = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請填寫名稱');
    if (mb_strlen($name) > 40) jerr('名稱請控制在 40 字以內');
    $act  = !empty($_POST['is_active']) ? 1 : 0;
    $sort = (int)($_POST['sort_order'] ?? 0);
    try {
        if ($cid > 0) {
            // code 建立後不再更動：既有回覆存的是 code，改了會全部對不到
            $db->prepare("UPDATE eng_log_channel SET name=?, sort_order=?, is_active=? WHERE id=?")
               ->execute([$name, $sort, $act, $cid]);
        } else {
            $code = trim((string)($_POST['code'] ?? ''));
            if ($code === '') $code = 'c' . substr(bin2hex(random_bytes(4)), 0, 6);
            if (!preg_match('/^[a-z0-9_]{1,20}$/i', $code)) jerr('代碼只能用英數字與底線');
            $chk = $db->prepare("SELECT 1 FROM eng_log_channel WHERE code=?");
            $chk->execute([$code]);
            if ($chk->fetchColumn()) jerr('這個代碼已經存在');
            $db->prepare("INSERT INTO eng_log_channel (code, name, sort_order, is_active, created_at) VALUES (?,?,?,?,?)")
               ->execute([$code, $name, $sort, $act, $now]);
            $cid = (int)$db->lastInsertId();
        }
    } catch (Throwable $e) { jerr('儲存失敗：' . $e->getMessage()); }
    jout(['id' => $cid, 'rows' => el_channel_rows($db)]);
}
if ($action === 'channel_delete') {
    need_csrf();
    if (!$P['canAdmin']) jerr('只有管理員可以維護回覆方式');
    $cid = (int)($_POST['id'] ?? 0);
    $st = $db->prepare("SELECT code FROM eng_log_channel WHERE id=?");
    $st->execute([$cid]);
    $code = (string)$st->fetchColumn();
    if ($code === '') jerr('查無此選項，請重新整理');
    // 已經被用過的不給硬刪，否則那些回覆的方式會變成查不到的孤兒；改建議停用
    $u = $db->prepare("SELECT COUNT(*) FROM eng_log_reply WHERE channel=?");
    $u->execute([$code]);
    $used = (int)$u->fetchColumn();
    if ($used > 0) jerr('這個方式已經被 ' . $used . ' 則回覆使用中，不能刪除。請改成「停用」，既有紀錄才不會變成空白。');
    $db->prepare("DELETE FROM eng_log_channel WHERE id=?")->execute([$cid]);
    jout(['rows' => el_channel_rows($db)]);
}

/** 附件備註（顯示在附件下方） */
if ($action === 'file_note') {
    need_csrf(); need_edit();
    $fid  = (int)($_POST['id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if (mb_strlen($note) > 500) jerr('備註請控制在 500 字以內');
    $st = $db->prepare("SELECT * FROM eng_log_file WHERE id=?");
    $st->execute([$fid]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) jerr('查無此附件，請重新整理');
    if ((int)$f['log_id'] > 0) { $row = el_load_log($db, (int)$f['log_id'], $P); el_need_write($row, $P); }
    elseif ((int)$f['uploaded_by'] !== (int)$P['uid']) jerr('只能修改自己上傳的暫存檔');
    $db->prepare("UPDATE eng_log_file SET note=? WHERE id=?")->execute([($note === '' ? null : $note), $fid]);
    jout([]);
}

/** 出貨單／退貨單底下的料號（多料號時跳出勾選清單） */
if ($action === 'carrier_parts') {
    $type = trim((string)($_GET['type'] ?? ''));
    $id   = trim((string)($_GET['id'] ?? ''));
    if (!in_array($type, el_multipart_types(), true)) jerr('這個型別不需要選料號');
    $parts = el_carrier_parts($db, $type, $id);
    jout(['parts' => $parts, 'head' => el_carrier_head($db, $type, $id)]);
}

/** 同一個手填單號在別的案件出現過（日後異常單電子化時靠這個字串接得起來） */
if ($action === 'manual_no_check') {
    $type = trim((string)($_GET['type'] ?? ''));
    $no   = trim((string)($_GET['no'] ?? ''));
    $except = (int)($_GET['except'] ?? 0);
    if (!isset(el_bind_types()[$type]) || !el_bind_types()[$type]['manual']) jout(['rows' => []]);
    jout(['rows' => el_manual_no_others($db, $type, $no, $except)]);
}

/* ── 附件 ──────────────────────────────────────────────────────────── */
if ($action === 'upload') {
    need_csrf(); need_edit();
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('檔案上傳失敗，請重新選擇');
    $f = $_FILES['file'];
    if ($f['size'] > 50 * 1024 * 1024) jerr('單一檔案請小於 50MB');

    $orig = (string)$f['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== '' && !preg_match('/^[a-z0-9]{1,8}$/', $ext)) jerr('副檔名不正確');
    // 可執行檔一律擋下
    if (in_array($ext, ['php','phtml','exe','bat','cmd','com','scr','vbs','js','jar','msi'], true))
        jerr('不接受這種檔案類型');

    $dir = eg_attach_dir($db, 'eng_log_nas_dir', '工程處理紀錄');
    if (!eg_attach_ensure_dir($dir)) jerr('附件資料夾無法建立，請洽管理員檢查路徑設定');

    $name = 'el_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
    if (!@move_uploaded_file($f['tmp_name'], $dir . $name)) jerr('檔案寫入失敗，請洽管理員檢查資料夾權限');

    // 先以 temp 存放（新增中就能上傳＝鐵律5），存檔時轉正；逾期未轉正由清理程序刪除
    $ownerType = trim((string)($_POST['owner_type'] ?? 'log'));
    if (!in_array($ownerType, ['log','item','reply'], true)) $ownerType = 'log';
    $ownerId = (int)($_POST['owner_id'] ?? 0);
    $logId   = (int)($_POST['log_id'] ?? 0);

    if ($logId > 0) {
        $row = el_load_log($db, $logId, $P);
        el_need_write($row, $P);
        $db->prepare("INSERT INTO eng_log_file (log_id, owner_type, owner_id, file_name, original_name, file_size,
                      status, uploaded_by, created_at) VALUES (?,?,?,?,?,?, 'active', ?, ?)")
           ->execute([$logId, $ownerType, ($ownerId ?: $logId), $name, $orig, (int)$f['size'], (int)$P['uid'], $now]);
    } else {
        $db->prepare("INSERT INTO eng_log_file (log_id, owner_type, owner_id, file_name, original_name, file_size,
                      status, expire_at, uploaded_by, created_at) VALUES (NULL,?,NULL,?,?,?, 'temp',
                      DATE_ADD(?, INTERVAL 1 DAY), ?, ?)")
           ->execute([$ownerType, $name, $orig, (int)$f['size'], $now, (int)$P['uid'], $now]);
    }
    jout(['id' => (int)$db->lastInsertId(), 'file_name' => $name, 'original_name' => $orig, 'file_size' => (int)$f['size']]);
}

if ($action === 'file_delete') {
    need_csrf(); need_edit();
    $fid = (int)($_POST['id'] ?? 0);
    $st = $db->prepare("SELECT * FROM eng_log_file WHERE id=?");
    $st->execute([$fid]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) jerr('查無此附件，請重新整理');
    if ((int)$f['log_id'] > 0) {
        $row = el_load_log($db, (int)$f['log_id'], $P);
        el_need_write($row, $P);
    } elseif ((int)$f['uploaded_by'] !== (int)$P['uid']) {
        jerr('只能刪除自己上傳的暫存檔');
    }
    $dir = eg_attach_dir($db, 'eng_log_nas_dir', '工程處理紀錄');
    @unlink($dir . $f['file_name']);
    $db->prepare("DELETE FROM eng_log_file WHERE id=?")->execute([$fid]);
    jout([]);
}

if ($action === 'download') {
    $fid = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT * FROM eng_log_file WHERE id=?");
    $st->execute([$fid]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) { http_response_code(404); exit('查無此附件'); }
    if ((int)$f['log_id'] > 0) {
        el_load_log($db, (int)$f['log_id'], $P);      // 看得到案件才下載得到附件
    } elseif ((int)$f['uploaded_by'] !== (int)$P['uid']) {
        http_response_code(403); exit('無權限');
    }
    // 只准單純檔名，擋路徑穿越
    $name = (string)$f['file_name'];
    if ($name === '' || strpbrk($name, "/\\") !== false || strpos($name, '..') !== false) { http_response_code(400); exit('檔名不合法'); }
    $path = eg_attach_dir($db, 'eng_log_nas_dir', '工程處理紀錄') . $name;
    if (!is_file($path)) { http_response_code(404); exit('檔案不存在（可能已被移除）'); }

    $mime = 'application/octet-stream';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mimes = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
              'gif'=>'image/gif','webp'=>'image/webp','txt'=>'text/plain; charset=utf-8'];
    if (isset($mimes[$ext])) $mime = $mimes[$ext];
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    eg_attach_send_disposition((string)($f['original_name'] ?: $name));
    readfile($path);
    exit;
}

jerr('未知的操作：' . $action);
