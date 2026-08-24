<?php
/**
 * 變更員工編號（user.id）並同步全庫所有參照 —— CLI 工具
 *
 * 用法：
 *   試算：& C:\MAMP\bin\php\php8.3.1\php.exe ai-rules\tools\migrate_user_id.php 10 105030101
 *   執行：& C:\MAMP\bin\php\php8.3.1\php.exe ai-rules\tools\migrate_user_id.php 10 105030101 --run
 *   補漏：& ... migrate_user_id.php 10 105030101 --refs-only --run
 *
 * 為什麼需要這支工具：
 *   user.id 是全庫的人員識別碼，但**只有 11 個欄位真的建了外鍵**，其餘幾十處都是隱性參照，
 *   而且命名法不統一（user_id / created_by / approver_id / signed_by / signer_user_id /
 *   sign_approve_id / target_id+target_type / setting_value…）。靠人工列清單一定會漏，
 *   所以本工具**每次執行都重新自動偵測**，不寫死欄位清單。
 *
 * 偵測策略（五種互補，缺一都會漏）：
 *   A. 命名樣式    —— 欄名像人員參照（int 與字串型都查，字串型如 d_setting.Created_By 存的是 id 字串）
 *   B. 值域推論    —— 不看欄名，凡「含該值且所有相異值都落在 user.id 集合內」的整數欄
 *   C. 多型參照    —— type+id 配對（live_event_target.target_type='user'、audit_log='rbac_user'）
 *   D. key-value   —— 設定表中值為人員 id 的設定鍵（boss_review_user_id 等）
 *   E. 內嵌字串    —— JSON/文字內的 "user-<id>"（co_editor_preset.editors_json 等）
 *
 * 安全機制：
 *   - 預設試算，不加 --run 不寫入任何資料
 *   - 單一 transaction，任一步失敗全部 rollback
 *   - 指向 user 的外鍵都是 NO ACTION，故執行期間暫時關閉 FK 檢查，結束後還原
 *   - 執行後請重跑一次試算確認殘留為 0（系統若仍在使用中，可能又產生新的參照）
 *
 * 已知踩坑（2026-08-24 陳俊宏 10 → 105030101 實測）：
 *   掃描與執行之間隔了 6 天，期間使用者持續操作，doc_apply 又新增了 83 筆指向舊 id 的資料。
 *   → 教訓：掃描與執行要盡量同一時間完成，且**執行後一定要重新掃描補漏**。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
mb_internal_encoding('UTF-8');

$OLD = isset($argv[1]) ? (int)$argv[1] : 0;
$NEW = isset($argv[2]) ? (int)$argv[2] : 0;
$RUN       = in_array('--run', $argv, true);
$REFS_ONLY = in_array('--refs-only', $argv, true);
if (!$OLD || !$NEW || $OLD === $NEW) {
    fwrite(STDERR, "usage: migrate_user_id.php OLD_ID NEW_ID [--run] [--refs-only]\n");
    exit(1);
}

$db = new PDO("mysql:host=127.0.0.1;dbname=EGsystem;port=3306;charset=utf8mb4",
              "EG-TS2024", "excell30367593",
              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ── 欄名樣式 ────────────────────────────────────────────────────────────────
// 像「人」的欄位
$PAT_USER = '/(^|_)(user|uid|users)($|_)|_by$|_by_id$|^by_|approver|approve_|signer|sign_|'
          . 'assignee|applicant|apply_by|operator|owner|member|staff|employee|emp_|teacher|'
          . 'trainer|lecturer|attendee|editor|chairman|host|reviewer|checker|confirm|delegate|'
          . 'agent|manager|supervisor|leader|creator|submitter|handler|receiver|sender|recipient|'
          . 'inspector|auditor|actor|filler|decider|signed|resolved/i';
// 明確不是「人」的欄位（職務/角色/部門/廠商/客戶/料號/單號 id）
$PAT_NOT  = '/position_id$|role_id$|dept_id$|department_id$|group_id$|type_id$|status_id$|'
          . '^(maker_id|maker_no|maker_name|customer_id|client_id|part_id|d_id|order_id|tool_id)$/i';

$INT_TYPES = ['int','bigint','smallint','mediumint','tinyint'];
$STR_TYPES = ['varchar','char'];

$tabs = array_flip($db->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA='EGsystem' AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN));
$cols = $db->query(
    "SELECT TABLE_NAME t, COLUMN_NAME c, DATA_TYPE dt, COLUMN_TYPE ct, CHARACTER_MAXIMUM_LENGTH len
     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='EGsystem'")->fetchAll(PDO::FETCH_ASSOC);

$uidSet = array_flip(array_map('intval',
    $db->query("SELECT id FROM `user`")->fetchAll(PDO::FETCH_COLUMN)));

$plan = [];               // ['kind'=>..,'table'=>..,'col'=>..,'cond'=>..,'rows'=>..,'via'=>..]
$seen = [];               // 去重：table.col

/** 這個欄位的型別容不容得下新 id？容不下就必須中止，否則會被截斷成錯的值 */
$fits = function (array $r) use ($NEW): bool {
    $dt = strtolower($r['dt']);
    if (in_array($dt, ['tinyint','smallint','mediumint'], true)) {
        $max = ['tinyint'=>127,'smallint'=>32767,'mediumint'=>8388607][$dt];
        return $NEW <= $max;
    }
    if (in_array($dt, ['varchar','char'], true)) return (int)$r['len'] >= strlen((string)$NEW);
    return true;   // int / bigint
};
$tooSmall = [];

// ── A. 命名樣式 ─────────────────────────────────────────────────────────────
foreach ($cols as $r) {
    $t = $r['t']; $c = $r['c']; $dt = strtolower($r['dt']);
    if (!isset($tabs[$t])) continue;
    if ($t === 'user' && $c === 'id') continue;
    if (!preg_match($PAT_USER, $c) || preg_match($PAT_NOT, $c)) continue;
    if (!in_array($dt, $INT_TYPES, true) && !in_array($dt, $STR_TYPES, true)) continue;
    try {
        $n = $db->query("SELECT COUNT(*) FROM `$t` WHERE `$c`='$OLD'")->fetchColumn();
        if (!$n) continue;
        if (!$fits($r)) { $tooSmall[] = "$t.$c ({$r['ct']})"; continue; }
        $seen["$t.$c"] = 1;
        $plan[] = ['kind'=>'col','table'=>$t,'col'=>$c,'cond'=>null,'rows'=>$n,'via'=>'A 命名'];
    } catch (Throwable $e) {}
}

// ── B. 值域推論（補抓命名不規則者，如 sales_track_note.created_by_id）────────
foreach ($cols as $r) {
    $t = $r['t']; $c = $r['c']; $dt = strtolower($r['dt']);
    if (!isset($tabs[$t]) || isset($seen["$t.$c"])) continue;
    if ($t === 'user' && $c === 'id') continue;
    if (!in_array($dt, $INT_TYPES, true) || preg_match($PAT_NOT, $c)) continue;
    try {
        $n = $db->query("SELECT COUNT(*) FROM `$t` WHERE `$c`=$OLD")->fetchColumn();
        if (!$n) continue;
        $d = $db->query("SELECT DISTINCT `$c` v FROM `$t` WHERE `$c` IS NOT NULL LIMIT 500")
                ->fetchAll(PDO::FETCH_COLUMN);
        if (count($d) < 2 || count($d) >= 500) continue;   // 太單調或太發散，不足以判定
        foreach ($d as $v) if (!isset($uidSet[(int)$v])) continue 2;
        if (!$fits($r)) { $tooSmall[] = "$t.$c ({$r['ct']})"; continue; }
        $seen["$t.$c"] = 1;
        $plan[] = ['kind'=>'col','table'=>$t,'col'=>$c,'cond'=>null,'rows'=>$n,'via'=>'B 值域'];
    } catch (Throwable $e) {}
}

// ── C. 多型參照（type + id 配對）─────────────────────────────────────────────
$PAIRS = [['ref_type','ref_id'],['target_type','target_id'],['obj_type','obj_id'],
          ['entity_type','entity_id'],['owner_type','owner_id'],['source_type','source_id']];
$USERISH = '/^(user|rbac_user|employee|staff|person|member)$/i';
foreach (array_keys($tabs) as $t) {
    $tc = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA='EGsystem' AND TABLE_NAME='$t'")->fetchAll(PDO::FETCH_COLUMN);
    $map = array_combine(array_map('strtolower', $tc), $tc);
    foreach ($PAIRS as [$k, $v]) {
        if (!isset($map[$k], $map[$v])) continue;
        $kc = $map[$k]; $vc = $map[$v];
        if (isset($seen["$t.$vc"])) continue;
        try {
            $rows = $db->query("SELECT `$kc` k, COUNT(*) n FROM `$t` WHERE `$vc`=$OLD GROUP BY `$kc`")
                       ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $rr) {
                if (!preg_match($USERISH, (string)$rr['k'])) continue;   // 只認人員型
                $cond = "`$kc`=" . $db->quote($rr['k']);
                $plan[] = ['kind'=>'col','table'=>$t,'col'=>$vc,'cond'=>$cond,
                           'rows'=>$rr['n'],'via'=>"C 多型[{$rr['k']}]"];
            }
        } catch (Throwable $e) {}
    }
}

// ── D. key-value 設定表 ─────────────────────────────────────────────────────
$KEYCOLS = ['setting_key','param_key','key_name','item_key','pkey'];
$VALCOLS = ['setting_value','param_value','value','val','item_value'];
$PAT_KEY = '/user|decider|approver|reviewer|boss|signer|manager|owner|chief|head|leader/i';
foreach (array_keys($tabs) as $t) {
    $tc = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA='EGsystem' AND TABLE_NAME='$t'")->fetchAll(PDO::FETCH_COLUMN);
    $map = array_combine(array_map('strtolower', $tc), $tc);
    $k = null; $v = null;
    foreach ($KEYCOLS as $x) if (isset($map[$x])) { $k = $map[$x]; break; }
    foreach ($VALCOLS as $x) if (isset($map[$x])) { $v = $map[$x]; break; }
    if (!$k || !$v) continue;
    try {
        $rows = $db->query("SELECT `$k` k FROM `$t` WHERE `$v`='$OLD'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $key) {
            if (!preg_match($PAT_KEY, (string)$key)) continue;   // 值剛好是 10 的門檻設定不算
            $plan[] = ['kind'=>'kv','table'=>$t,'col'=>$v,'keycol'=>$k,'key'=>$key,
                       'rows'=>1,'via'=>'D 設定'];
        }
    } catch (Throwable $e) {}
}

// ── E. JSON / 文字內嵌 "user-<id>" ──────────────────────────────────────────
foreach ($cols as $r) {
    $t = $r['t']; $c = $r['c']; $dt = strtolower($r['dt']);
    if (!isset($tabs[$t])) continue;
    if (!in_array($dt, ['json','text','mediumtext','longtext','varchar'], true)) continue;
    try {
        $n = $db->query("SELECT COUNT(*) FROM `$t` WHERE `$c` REGEXP 'user-$OLD([^0-9]|$)'")->fetchColumn();
        if (!$n) continue;
        $pks = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA='EGsystem' AND TABLE_NAME='$t' AND COLUMN_KEY='PRI'")
                  ->fetchAll(PDO::FETCH_COLUMN);
        if (count($pks) !== 1) continue;   // 複合主鍵不處理，改請人工檢視
        $plan[] = ['kind'=>'embed','table'=>$t,'col'=>$c,'pk'=>$pks[0],'rows'=>$n,'via'=>'E 內嵌'];
    } catch (Throwable $e) {}
}

// ── 型別容不下就中止（避免靜默截斷成錯誤的 id）───────────────────────────────
if ($tooSmall) {
    fwrite(STDERR, "中止：以下欄位型別容不下新 id {$NEW}，請先擴充欄位型別\n  "
                 . implode("\n  ", $tooSmall) . "\n");
    exit(2);
}

// ── 執行 ────────────────────────────────────────────────────────────────────
$total = 0; $log = [];
try {
    if ($RUN) { $db->exec("SET FOREIGN_KEY_CHECKS=0"); $db->beginTransaction(); }

    if (!$REFS_ONLY) {
        if ($db->query("SELECT COUNT(*) FROM `user` WHERE id=$NEW")->fetchColumn())
            throw new RuntimeException("目標 id $NEW 已被使用，中止");
        if (!$db->query("SELECT COUNT(*) FROM `user` WHERE id=$OLD")->fetchColumn())
            throw new RuntimeException("找不到 user.id=$OLD");
        if ($RUN) $db->exec("UPDATE `user` SET id=$NEW WHERE id=$OLD");
        $log[] = sprintf('%-52s %6d  %s', 'user.id〔主檔〕', 1, '主檔'); $total += 1;
    } else {
        if (!$db->query("SELECT COUNT(*) FROM `user` WHERE id=$NEW")->fetchColumn())
            throw new RuntimeException("--refs-only 需主檔已是 {$NEW}，但查無此列");
        if ($db->query("SELECT COUNT(*) FROM `user` WHERE id=$OLD")->fetchColumn())
            throw new RuntimeException("user.id=$OLD 仍存在，不該用 --refs-only");
    }

    foreach ($plan as $p) {
        $t = $p['table']; $c = $p['col'];
        if ($p['kind'] === 'col') {
            $w = "`$c`='$OLD'" . ($p['cond'] ? " AND {$p['cond']}" : '');
            if ($RUN) $db->exec("UPDATE `$t` SET `$c`='$NEW' WHERE $w");
            $label = "$t.$c" . ($p['cond'] ? " [{$p['cond']}]" : '');
        } elseif ($p['kind'] === 'kv') {
            $kq = $db->quote($p['key']);
            if ($RUN) $db->exec("UPDATE `$t` SET `$c`='$NEW' WHERE `{$p['keycol']}`=$kq AND `$c`='$OLD'");
            $label = $t . '[' . $p['key'] . ']';
        } else {   // embed
            $pk = $p['pk'];
            $rows = $db->query("SELECT `$pk` k, `$c` v FROM `$t` WHERE `$c` REGEXP 'user-$OLD([^0-9]|$)'")
                       ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $rr) {
                $nv = preg_replace('/user-' . $OLD . '(?![0-9])/', 'user-' . $NEW, $rr['v']);
                if ($nv === $rr['v']) continue;
                if ($RUN) {
                    $st = $db->prepare("UPDATE `$t` SET `$c`=? WHERE `$pk`=?");
                    $st->execute([$nv, $rr['k']]);
                }
            }
            $label = "{$t}.{$c}（user-{$OLD} 字串）";
        }
        $log[] = sprintf('%-52s %6d  %s', $label, $p['rows'], $p['via']);
        $total += $p['rows'];
    }

    if ($RUN) { $db->commit(); $db->exec("SET FOREIGN_KEY_CHECKS=1"); }
} catch (Throwable $e) {
    if ($RUN && $db->inTransaction()) $db->rollBack();
    if ($RUN) $db->exec("SET FOREIGN_KEY_CHECKS=1");
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . ' @line ' . $e->getLine() . "\n");
    exit(3);
}

echo $RUN ? "=== 已執行（user.id {$OLD} → {$NEW}）===\n" : "=== 試算，未寫入（user.id {$OLD} → {$NEW}）===\n";
foreach ($log as $l) echo $l, "\n";
echo str_repeat('-', 72), "\n";
printf("%-52s %6d\n", '合計異動筆數', $total);
printf("%-52s %6d\n", '涉及欄位／項目數', count($log));
if ($RUN) echo "\n請再跑一次試算確認殘留為 0（系統使用中可能又產生新參照）。\n";
