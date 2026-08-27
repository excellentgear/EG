<?php
/**
 * 內部稽核：稽核員／陪檢員改成可多位 —— 既有資料回填工具
 * ---------------------------------------------------------------------------
 * 背景（使用者 2026-08-27 交辦）：一個受稽單位的稽核員與陪檢員都可能不只一位。
 * 人員改存到新表 ia_case_dept_person（唯一來源），ia_case_dept 上原本的
 * auditor_id/auditor_name/escort_id/escort_name 降級為顯示用快取。
 *
 * 本工具做三件事（可重複執行，已經有人員列的受稽單位一律略過）：
 *  ⑴把 ia_case_dept 既有的單一欄位搬進 ia_case_dept_person。
 *  ⑵**姓名欄位裡塞了兩個人的舊資料一併拆開**——2024 第 2 次稽核的稽核員是
 *    「林國棟／葉卿雅」兩位，當時 schema 存不下就併成一個字串、auditor_id 留空
 *    （見 2026-08-26_ia_2024_import.php 的「已知限制」）。這裡依 ／ / 、 拆開後
 *    **依該案件的稽核日期回推當時職務**（ai-rules/22；林國棟 2024-12-31 已離職，
 *    用現況查一定查不到人）。
 *  ⑶順手清掉當時為了不遺失資料而寫進備註的「【紙本稽核員】…」那一行
 *    （現在稽核員欄位本身就存得下兩位，留著會在列印版重複出現）。
 *    只清完全吻合當時產生格式的那一行，使用者自己打的備註不動。
 *
 * 用法（CLI）：
 *   php 2026-08-27_ia_multi_auditor.php            只列出將要做什麼，不寫入（＝--dry）
 *   php 2026-08-27_ia_multi_auditor.php --run      實際寫入
 *   php 2026-08-27_ia_multi_auditor.php --verify   列出目前每個受稽單位的人員
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("僅限 CLI 執行\n"); }

$ROOT = dirname(__DIR__, 3);                      // …/EGsystem
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/people_lib.php';
require_once $ROOT . '/src/common/internal_audit_lib.php';

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ia_ensure_schema($db);

$argvAll = $argv ?? [];
$RUN     = in_array('--run', $argvAll, true);
$VERIFY  = in_array('--verify', $argvAll, true);

/** 姓名字串拆成多個人名（舊資料把兩位稽核員併成「林國棟／葉卿雅」） */
function mm_split_names(string $s): array
{
    $parts = preg_split('/[、／\/,，]+/u', trim($s));
    $out = [];
    foreach ($parts as $p) { $p = trim($p); if ($p !== '') $out[] = $p; }
    return $out;
}

/** 姓名 → 該日期當時的職務（回 ['id','dept_id','position_id'] 或 null） */
function mm_person_asof(PDO $db, string $name, string $date): ?array
{
    static $cache = [];
    $k = $name . '@' . $date;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $st = $db->prepare("SELECT id FROM `user` WHERE CONVERT(user_cname USING utf8mb4)=? LIMIT 1");
    $st->execute([$name]);
    $uid = $st->fetchColumn();
    if ($uid === false) return $cache[$k] = null;
    $rows = eg_people_list_asof($db, ['user_ids' => [(int)$uid], 'states' => [1, 2, 3]], $date);
    $r = $rows ? $rows[0] : null;
    return $cache[$k] = ['id' => (int)$uid,
                         'dept_id' => $r['dept_id'] ?? null,
                         'position_id' => $r['position_id'] ?? null];
}

/** user_id → 姓名（比對用，utf8mb4 轉換是因為 user 表是 latin1 欄位） */
function mm_user_name(PDO $db, int $uid): string
{
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $st = $db->prepare("SELECT CONVERT(user_cname USING utf8mb4) FROM `user` WHERE id=?");
    $st->execute([$uid]);
    return $cache[$uid] = (string)($st->fetchColumn() ?: '');
}

$cds = $db->query("SELECT cd.*, c.audit_from, c.notify_date, c.case_no
                     FROM ia_case_dept cd JOIN ia_case c ON c.case_id=cd.case_id
                    ORDER BY cd.case_id, cd.sort_order, cd.cd_id")->fetchAll(PDO::FETCH_ASSOC);

if ($VERIFY) {
    $ids = array_map(function ($r) { return (int)$r['cd_id']; }, $cds);
    $map = ia_cd_people_map($db, $ids, []);            // 不給回退來源，只看真的存進去的
    echo str_pad('稽核件號', 12) . str_pad('受稽單位', 16) . str_pad('稽核員', 30) . "陪檢員\n";
    foreach ($cds as $r) {
        $p = $map[(int)$r['cd_id']] ?? ['auditor' => [], 'escort' => []];
        echo str_pad((string)$r['case_no'], 12)
           . str_pad((string)$r['dept_name'], 16)
           . str_pad((ia_cd_names($p['auditor']) ?: '（無）') . '(' . count($p['auditor']) . ')', 30)
           . (ia_cd_names($p['escort']) ?: '（無）') . '(' . count($p['escort']) . ")\n";
    }
    exit(0);
}

$have = [];
foreach ($db->query("SELECT DISTINCT cd_id, kind FROM ia_case_dept_person")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $have[(int)$r['cd_id'] . '-' . $r['kind']] = 1;
}

$plan = []; $unresolved = [];
foreach ($cds as $r) {
    $date = (string)($r['audit_from'] ?: $r['notify_date'] ?: date('Y-m-d'));
    foreach (['auditor', 'escort'] as $kind) {
        if (isset($have[(int)$r['cd_id'] . '-' . $kind])) continue;
        $names    = mm_split_names((string)($r[$kind . '_name'] ?? ''));
        $cachedId = (int)($r[$kind . '_id'] ?? 0);
        if (!$names && !$cachedId) continue;
        $cachedName = $cachedId ? mm_user_name($db, $cachedId) : '';
        if (!$names && $cachedName !== '') $names = [$cachedName];

        $people = [];
        foreach ($names as $n) {
            if ($cachedId && $n === $cachedName) {
                // 原本就存得對的那一位：連當初挑的部門／職稱一起沿用，不要重新猜
                $people[] = ['user_id' => $cachedId, 'user_name' => $n,
                             'dept_id' => $r[$kind . '_dept_id'] ?? null,
                             'position_id' => $r[$kind . '_position_id'] ?? null];
                continue;
            }
            $p = mm_person_asof($db, $n, $date);
            if (!$p) $unresolved[] = $r['case_no'] . ' / ' . $r['dept_name'] . ' / ' . $kind . ' / ' . $n;
            $people[] = ['user_id' => $p['id'] ?? 0, 'user_name' => $n,
                         'dept_id' => $p['dept_id'] ?? null, 'position_id' => $p['position_id'] ?? null];
        }
        $plan[] = ['cd_id' => (int)$r['cd_id'], 'case_id' => (int)$r['case_id'], 'kind' => $kind,
                   'case_no' => $r['case_no'], 'dept' => $r['dept_name'],
                   'from' => (string)($r[$kind . '_name'] ?? ''), 'people' => $people];
    }
}

echo ($RUN ? "【實際寫入】\n" : "【試算，不寫入；要真的執行請加 --run】\n");
foreach ($plan as $x) {
    echo '  ' . $x['case_no'] . ' ' . str_pad((string)$x['dept'], 14)
       . ($x['kind'] === 'auditor' ? '稽核員' : '陪檢員') . '  '
       . str_pad((string)$x['from'], 24) . ' → ' . count($x['people']) . ' 位：'
       . ia_cd_names($x['people']) . "\n";
}
if (!$plan) echo "  （沒有需要回填的受稽單位，可能已經跑過了）\n";
if ($unresolved) {
    echo "\n  ⚠ 以下姓名對不到人員主檔，會只保留姓名（user_id 留空）：\n";
    foreach (array_unique($unresolved) as $u) echo "    - $u\n";
}

/* 備註裡那一行「【紙本稽核員】…」在稽核員存得下多位之後就多餘了 */
$remarkFix = [];
foreach ($db->query("SELECT case_id, case_no, remark FROM ia_case
                      WHERE remark LIKE '%【紙本稽核員】%'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $new = preg_replace('/\R?【紙本稽核員】[^\r\n]*/u', '', (string)$c['remark']);
    if ($new !== null && $new !== $c['remark']) {
        $remarkFix[] = ['case_id' => (int)$c['case_id'], 'case_no' => $c['case_no'], 'remark' => $new];
    }
}
if ($remarkFix) {
    echo "\n  備註清理（移除「【紙本稽核員】…」那一行）：" . count($remarkFix) . " 張通知單\n";
    foreach ($remarkFix as $f) echo '    - ' . $f['case_no'] . "\n";
}

if (!$RUN) { echo "\n（試算結束，未寫入任何資料）\n"; exit(0); }

$db->beginTransaction();
try {
    foreach ($plan as $x) ia_cd_people_set($db, $x['cd_id'], $x['case_id'], $x['kind'], $x['people']);
    $up = $db->prepare("UPDATE ia_case SET remark=?, updated_at=NOW() WHERE case_id=?");
    foreach ($remarkFix as $f) $up->execute([$f['remark'], $f['case_id']]);
    $db->commit();
    echo "\n完成：回填 " . count($plan) . " 組人員、清理 " . count($remarkFix) . " 張通知單的備註。\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "\n失敗，已全部回復：" . $e->getMessage() . "\n";
    exit(1);
}
