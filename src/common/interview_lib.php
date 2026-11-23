<?php
/**
 * 訪談記錄（人事面談）— 共用函式
 * 定位：行政人事紀錄，不編入 AS9100 品質文件清單、不綁 AS 文件編號；與「利害關係者溝通記錄」完全分開。
 * 類型：事件調查（綁簽呈）／新進員工關心／在職員工定期關心（管理員可增減）。
 * 可見範圍：訪談人、建立人、人事部門（組織角色綁定 hr_dept）、本頁管理員（interview_admin）、全站管理員。
 * 改善事項：訪談中員工反映的作業環境／作業困難，由訪談人整理成「不含個人資料」的描述後，
 *           轉成溝通管理的措施追蹤項目（comm_track，類別＝員工）；訪談原文不離開本頁。
 */
require_once __DIR__ . '/memo_lib.php';
require_once __DIR__ . '/comm_mgmt_lib.php';

function iv_ensure_schema(PDO $db): void
{
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS iv_type (
            type_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL,
            need_memo TINYINT NOT NULL DEFAULT 0 COMMENT '1＝這種訪談要綁簽呈（事件調查）',
            need_next TINYINT NOT NULL DEFAULT 0 COMMENT '1＝需要排下次約談（定期關心）',
            sort_order INT NOT NULL DEFAULT 0, is_active TINYINT NOT NULL DEFAULT 1,
            UNIQUE KEY uk_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訪談類型'");
        if (!(int)$db->query('SELECT COUNT(*) FROM iv_type')->fetchColumn()) {
            $db->exec("INSERT INTO iv_type (name, need_memo, need_next, sort_order) VALUES
                ('事件調查',1,0,10), ('新進員工關心',0,1,20), ('在職員工定期關心',0,1,30)");
        }
        try { if (!$db->query("SHOW COLUMNS FROM iv_type LIKE 'guide'")->fetch()) $db->exec("ALTER TABLE iv_type ADD COLUMN guide TEXT NULL COMMENT '引導問題（一行一題，選填用）'"); } catch (Throwable $e) {}
        $db->exec("CREATE TABLE IF NOT EXISTS iv_place (
            place_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT NOT NULL DEFAULT 0, is_active TINYINT NOT NULL DEFAULT 1,
            UNIQUE KEY uk_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訪談地點選單'");
        $db->exec("CREATE TABLE IF NOT EXISTS iv_record (
            iv_id INT AUTO_INCREMENT PRIMARY KEY,
            iv_no VARCHAR(20) NOT NULL COMMENT 'IV-YYYYMM-流水三碼（依訪談日期）',
            type_id INT NULL, type_name VARCHAR(40) NULL,
            memo_id INT NULL COMMENT '關聯簽呈（事件調查用）',
            iv_date DATE NOT NULL, start_time VARCHAR(5) NULL, end_time VARCHAR(5) NULL, place VARCHAR(100) NULL,
            interviewer_id INT NULL, interviewer_name VARCHAR(60) NULL, interviewer_dept VARCHAR(100) NULL, interviewer_pos VARCHAR(60) NULL,
            interviewee_id INT NULL, interviewee_name VARCHAR(60) NULL, interviewee_dept_id INT NULL, interviewee_dept VARCHAR(100) NULL, interviewee_pos VARCHAR(60) NULL,
            content TEXT NULL COMMENT '談話內容（機密）', follow TEXT NULL COMMENT '後續處理／追蹤',
            next_date DATE NULL COMMENT '下次約談日', status VARCHAR(10) NOT NULL DEFAULT 'draft' COMMENT 'draft／done',
            done_at DATETIME NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_no (iv_no), KEY idx_date (iv_date), KEY idx_memo (memo_id), KEY idx_next (next_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訪談記錄（行政人事，不屬品質文件）'");
        $db->exec("CREATE TABLE IF NOT EXISTS iv_improve (
            imp_id INT AUTO_INCREMENT PRIMARY KEY, iv_id INT NOT NULL,
            content VARCHAR(1000) NOT NULL COMMENT '去識別化的改善事項描述',
            track_id INT NULL COMMENT '已轉入溝通管理措施追蹤表的 track_id',
            created_by INT NULL, created_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0, KEY idx_iv (iv_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訪談記錄的改善事項'");
        $db->exec("CREATE TABLE IF NOT EXISTS iv_file (
            file_id INT AUTO_INCREMENT PRIMARY KEY, iv_id INT NOT NULL,
            file_name VARCHAR(120) NOT NULL COMMENT 'NAS 上的存檔名（只存檔名，路徑現場組）', original_name VARCHAR(255) NULL, file_size INT NOT NULL DEFAULT 0,
            uploaded_by INT NULL, created_at DATETIME NULL, is_deleted TINYINT NOT NULL DEFAULT 0, KEY idx_iv (iv_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='訪談記錄附件（簽章結果掃描檔等）'");
        $st = $db->prepare("SELECT 1 FROM roles WHERE role_code='interview_admin' AND module='interview_record' LIMIT 1");
        $st->execute();
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO roles (role_code, role_name, module) VALUES ('interview_admin','訪談記錄管理員','interview_record')")->execute();
        }
    } catch (Throwable $e) {}
}

function iv_perms(PDO $db, ?array $u): array
{
    $mp = memo_perms($db, $u);
    $uid = (int)($u['id'] ?? 0);
    $active = $u && in_array((int)($u['state'] ?? 0), [1, 2, 3], true);
    $role = $uid ? iv_has_role($db, $uid, 'interview_admin') : false;
    $hr = $uid ? memo_is_hr($db, $uid) : false;
    return ['uid'=>$uid, 'isAdmin'=>$mp['isAdmin'], 'canAdmin'=>($mp['isAdmin'] || $role), 'isHr'=>$hr,
            'viewAll'=>($mp['isAdmin'] || $role || $hr),
            'canUse'=>($active || $mp['isAdmin']) && ($mp['isAdmin'] || $role || $hr || $active)];
}

/** 能不能看／改這份訪談記錄（訪談人、建立人、人事部門、管理員） */
function iv_can_see(array $r, array $p): bool
{
    if ($p['viewAll']) return true;
    return (int)$r['interviewer_id'] === $p['uid'] || (int)$r['created_by'] === $p['uid'];
}
function iv_visible_sql(array $p): array
{
    if ($p['viewAll']) return ['1=1', []];
    return ['(r.interviewer_id=? OR r.created_by=?)', [$p['uid'], $p['uid']]];
}

function iv_next_no(PDO $db, string $date): string
{
    $ym = date('Ym', strtotime($date));
    $st = $db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(iv_no, 11) AS UNSIGNED)),0) FROM iv_record WHERE iv_no LIKE ?");
    $st->execute(["IV-$ym-%"]);
    return sprintf('IV-%s-%03d', $ym, (int)$st->fetchColumn() + 1);
}

function iv_types(PDO $db, bool $onlyActive = true): array
{
    $sql = "SELECT * FROM iv_type" . ($onlyActive ? " WHERE is_active=1" : "") . " ORDER BY sort_order, type_id";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** 人員身分（部門／職稱）：優先使用呼叫端選到的部門，否則取主要職務；依訪談日期回推（ai-rules/22） */
function iv_identity(PDO $db, int $uid, int $deptId = 0, ?string $bizDate = null): array
{
    $ids = cm_identities($db, $uid, $bizDate);
    $pick = null;
    foreach ($ids as $i) { if ($deptId && (int)$i['department_id'] === $deptId) { $pick = $i; break; } }
    if (!$pick) foreach ($ids as $i) { if ((int)$i['is_main']) { $pick = $i; break; } }
    if (!$pick && $ids) $pick = $ids[0];
    return ['name'=>cm_user_name($db, $uid), 'dept_id'=>$pick ? (int)$pick['department_id'] : 0,
            'dept'=>$pick ? (string)$pick['department_name'] : '', 'pos'=>$pick ? (string)$pick['position_name'] : ''];
}

function iv_has_role(PDO $db, int $uid, string $code): bool
{
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.module='interview_record' AND r.role_code=? LIMIT 1");
        $st->execute([$uid, $code]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}


/** 訪談記錄附件的存放目錄（鐵律5：根資料夾＋頁面名稱，路徑現場組） */
function iv_attach_dir(PDO $db): string
{
    require_once __DIR__ . '/attach_lib.php';
    return eg_attach_dir($db, 'interview_record_nas_dir', '訪談記錄');
}

/** 某筆訪談記錄的附件清單 */
function iv_files(PDO $db, int $ivId): array
{
    $st = $db->prepare("SELECT file_id, original_name, file_name, file_size, created_at FROM iv_file WHERE iv_id=? AND is_deleted=0 ORDER BY file_id");
    $st->execute([$ivId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 這張簽呈相關的訪談記錄（只回目前使用者看得到的），含各筆附件。$memoIds＝要查的簽呈 id 們 */
function iv_for_memos(PDO $db, array $memoIds, array $P): array
{
    $memoIds = array_values(array_filter(array_map('intval', $memoIds)));
    if (!$memoIds) return [];
    $st = $db->query("SELECT r.* FROM iv_record r WHERE r.is_deleted=0 AND r.memo_id IN (" . implode(',', $memoIds) . ") ORDER BY r.iv_date, r.iv_id");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!iv_can_see($r, $P)) continue;
        $r['files'] = array_map(function ($f) { return ['file_id' => (int)$f['file_id'], 'name' => (string)($f['original_name'] ?: $f['file_name'])]; }, iv_files($db, (int)$r['iv_id']));
        $out[] = $r;
    }
    return $out;
}

/** 簽呈檢視畫面要的「調查連結」：來源簽呈、相關訪談記錄（含附件）、已建立的調查結果簽呈、能不能轉為調查 */
function memo_invest_links(PDO $db, array $memo, array $perms, array $u): array
{
    iv_ensure_schema($db);
    $uid = (int)$u['id'];
    $P = iv_perms($db, $u);
    $src = null; $srcId = (int)($memo['src_memo_id'] ?? 0);
    if ($srcId) {
        $sm = memo_row($db, $srcId);
        if ($sm) $src = ['memo_id' => (int)$sm['memo_id'], 'memo_no' => $sm['memo_no'], 'subject' => memo_can_see($sm, $perms, $uid) ? $sm['subject'] : '', 'can' => memo_can_see($sm, $perms, $uid)];
    }
    $ivs = iv_for_memos($db, $srcId ? [$srcId, (int)$memo['memo_id']] : [(int)$memo['memo_id']], $P);
    $ivs = array_map(function ($r) {
        return ['iv_id' => (int)$r['iv_id'], 'iv_no' => $r['iv_no'], 'iv_date' => $r['iv_date'], 'status' => $r['status'],
                'who' => trim($r['interviewee_dept'] . ' ' . $r['interviewee_name']), 'files' => $r['files']];
    }, $ivs);
    $res = [];
    $st = $db->prepare("SELECT memo_id, memo_no, subject, status FROM memo_request WHERE src_memo_id=? AND COALESCE(is_deleted,0)=0 ORDER BY memo_id");
    $st->execute([(int)$memo['memo_id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $m = memo_row($db, (int)$r['memo_id']); if ($m && memo_can_see($m, $perms, $uid)) $res[] = $r; }
    $own = (int)$memo['user_id'] === $uid || (int)$memo['created_by'] === $uid;
    return ['src' => $src, 'interviews' => $ivs, 'results' => $res,
            'can_invest' => $memo['status'] === 'approved' && !$srcId && $P['canUse'] && ($own || $P['viewAll'])];
}

/** 「調查結果簽呈」格式草稿：系統查得到的帶入，查不到的留【待填：…】（不捏造事實） */
function memo_invest_template(PDO $db, array $src, array $perms, array $u): array
{
    iv_ensure_schema($db);
    $P = iv_perms($db, $u);
    $ivs = iv_for_memos($db, [(int)$src['memo_id']], $P);
    $d = $src['closed_date'] ?: $src['apply_date'];
    $ymd = function ($x) { $t = strtotime((string)$x); return $t ? (date('Y', $t) . ' 年 ' . (int)date('n', $t) . ' 月 ' . (int)date('j', $t) . ' 日') : '【待填：日期】'; };
    $people = []; $dates = []; $att = []; $n = 0;
    $cn = ['一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
    foreach ($ivs as $r) {
        $people[] = trim($r['interviewee_dept'] . ' ' . $r['interviewee_name']);
        $dates[] = $r['iv_date'];
        $line = '附件' . ($cn[$n] ?? ($n + 1)) . '：訪談記錄 ' . $r['iv_no'] . '（' . trim($r['interviewee_dept'] . ' ' . $r['interviewee_name']) . '，' . $ymd($r['iv_date']) . '）';
        if ($r['files']) $line .= '，含簽章結果附件 ' . count($r['files']) . ' 份';
        $att[] = $line; $n++;
    }
    sort($dates);
    $when = $dates ? ($dates[0] === end($dates) ? $ymd($dates[0]) : $ymd($dates[0]) . '至' . $ymd(end($dates))) : '【待填：訪談日期】';
    $who = $people ? implode('、', $people) : '【待填：受訪人員】';
    $c = "說明：\n"
       . "一、事件緣由：依據 " . $ymd($d) . "核准之調查請示簽呈（簽呈編號 " . $src['memo_no'] . "）辦理。事件發生之時間、地點與起因：【待填：簡述發生時間、地點與起因】。\n\n"
       . "二、調查過程與佐證：已於 " . $when . " 完成相關人員（" . $who . "）訪談，並調閱【待填：相關出勤／現場紀錄】核對"
       . ($att ? "（詳如下列附件）：\n" . implode("\n", $att) : "（詳如附件）：【待填：附件清單】") . "\n\n"
       . "三、調查結果（事實認定）：【待填：客觀陳述查證屬實之經過】\n\n"
       . "四、擬辦（處置建議）：\n"
       . "（一）依公司工作規則第【待填：條號】條，建議【待填：處置方式，例如口頭告誡、調整班別／崗位】。\n"
       . "（二）本案屬一般行政人事管理案件，核決後連同附件歸入人資機密檔案保存，不納入品質管理系統（AS9100）文件範疇。";
    return ['subject' => '呈報【待填：單位名稱】同仁間職場衝突事件之調查結果與後續處置建議，請 核示。', 'content' => $c, 'secrecy' => $src['secrecy'], 'interview_cnt' => count($ivs)];
}
