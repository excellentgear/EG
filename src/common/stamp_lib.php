<?php
// stamp_lib.php — 圖章管理共用工具（清冊 stamp_register / 掃描章資產 stamp_asset）
// 儲存位置：可於圖章管理頁自訂（system_settings.stamp_attach_base，建議 UNC 路徑）；未設定則用專案內 uploads/stamps。
// 鐵律5：stamp_asset 只存檔名（file_name），完整路徑一律讀取當下用本檔函式現場組出，絕不寫死進 DB。

if (!function_exists('eg_stamp_base')) {
    // 取得掃描章基礎儲存路徑（設定值優先，否則 fallback 專案內 uploads/stamps）
    function eg_stamp_base(PDO $db): string {
        try {
            $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'stamp_attach_base' LIMIT 1");
            $st->execute();
            $v = trim((string)$st->fetchColumn());
            if ($v !== '') return rtrim($v, '\\/');
        } catch (Exception $e) {}
        return realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'stamps';
    }

    // 掃描章實體檔完整路徑（不存在回傳 null）
    function eg_stamp_file_path(PDO $db, array $asset): ?string {
        $fn = basename((string)($asset['file_name'] ?? ''));
        if ($fn === '') return null;
        $p = eg_stamp_base($db) . DIRECTORY_SEPARATOR . $fn;
        return is_file($p) ? $p : null;
    }

    // 取單一使用者的掃描章資產（無則 null）
    function eg_stamp_asset(PDO $db, int $userId): ?array {
        try {
            $st = $db->prepare("SELECT user_id, file_name, band_top, band_bottom FROM stamp_asset WHERE user_id = ?");
            $st->execute([$userId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Exception $e) { return null; }
    }

    // 各模組「簽名圖章樣式」設定所使用的 system_settings key → 說明文字。
    // 新增模組要納入刪除模板前的套用檢查，只要把該模組的設定 key 加進這裡即可，不必動 eg_stamp_template_usages() 本身。
    if (!defined('EG_STAMP_TPL_SETTINGS')) {
        define('EG_STAMP_TPL_SETTINGS', [
            'meeting_stamp_tpl_id'  => '會議紀錄／出席簽到、項目確認簽名',
            'training_stamp_tpl_id' => '教育訓練／簽到表、訓練紀錄',
        ]);
    }

    // 某圖章模板目前被哪些地方套用（清冊登記中 + 各模組簽名圖章樣式設定）；回傳空陣列＝沒有任何套用，可安全刪除。
    function eg_stamp_template_usages(PDO $db, int $templateId): array {
        $out = [];
        if ($templateId <= 0) return $out;
        try {
            $st = $db->prepare("SELECT
                                   CASE WHEN r.position_id IS NOT NULL THEN CONCAT(d.name,'／',p.name)
                                        WHEN r.user_id IS NOT NULL AND r.dept_id IS NOT NULL THEN CONCAT(u.user_cname,'（',d.name,'）')
                                        ELSE COALESCE(u.user_cname, d.name) END AS holder_name
                                 FROM stamp_register r
                                 LEFT JOIN user u ON u.id = r.user_id
                                 LEFT JOIN department d ON d.id = r.dept_id
                                 LEFT JOIN position p ON p.id = r.position_id
                                 WHERE r.template_id = ? AND r.status = 'active'");
            $st->execute([$templateId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $out[] = '清冊登記：' . $name;
            }
        } catch (Exception $e) {}
        try {
            if (EG_STAMP_TPL_SETTINGS) {
                $keys = array_keys(EG_STAMP_TPL_SETTINGS);
                $in = implode(',', array_fill(0, count($keys), '?'));
                $st = $db->prepare("SELECT setting_key FROM system_settings WHERE setting_key IN ($in) AND setting_value = ?");
                $st->execute(array_merge($keys, [(string)$templateId]));
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $key) {
                    $out[] = '模組設定：' . (EG_STAMP_TPL_SETTINGS[$key] ?? $key);
                }
            }
        } catch (Exception $e) {}
        return $out;
    }

    // 全部掃描章資產對照表（給 eg_stamp.js：姓名→資產）。
    // 只回傳檔案實際存在者；t=檔案 mtime 供前端快取破壞。
    function eg_stamp_asset_map(PDO $db): array {
        $out = [];
        try {
            $rows = $db->query("SELECT a.user_id, a.file_name, a.band_top, a.band_bottom, u.user_cname
                                FROM stamp_asset a JOIN user u ON u.id = a.user_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $p = eg_stamp_file_path($db, $r);
                if (!$p) continue;   // 檔案遺失（如換NAS未搬檔）→ 前端自動回退純SVG
                $name = trim((string)$r['user_cname']);
                if ($name === '') continue;
                $out[$name] = [
                    'uid'  => (int)$r['user_id'],
                    'top'  => (float)$r['band_top'],
                    'bot'  => (float)$r['band_bottom'],
                    't'    => @filemtime($p) ?: 0,
                ];
            }
        } catch (Exception $e) {}
        return $out;
    }

    // 員工離職（實際離職日已確定）→ 此人名下所有「使用中」的圖章登記自動作廢，作廢日＝離職日。
    // 只動 user_id＝此人的登記（個人章／部門人員章）；課室章／職稱章不掛在特定人身上，不受影響。
    // 呼叫時機：①src/common/user_leave_tick.php 順路觸發（預定離職日到期自動轉離職）
    //          ②src/store/_employee_api.php 人事於員工管理頁手動把在職狀態改成離職。
    // 2026-09-24 使用者要求：人員離職日後自動將所有此人員之圖章改為作廢。
    // 2026-10-16 追加：一併寫入 revoke_reason='leave'，**復職自動恢復就是靠這個欄位分辨**
    // 「因離職被系統作廢」與「人工判斷要停用」——後者不該被復職偷偷復活。
    function eg_stamp_auto_revoke_for_leave(PDO $pdo, int $userId, string $revokeDate, string $operator = 'system'): int {
        if ($userId <= 0) return 0;
        eg_stamp_ensure_schema($pdo);
        try {
            $st = $pdo->prepare("SELECT id FROM stamp_register WHERE user_id = ? AND status = 'active'");
            $st->execute([$userId]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) return 0;
            $upd = $pdo->prepare("UPDATE stamp_register SET status='revoked', revoke_date=?, revoke_reason='leave',
                                  modified_by=?, modified_at=NOW() WHERE id=?");
            foreach ($ids as $rid) $upd->execute([$revokeDate, $operator, $rid]);
            return count($ids);
        } catch (Exception $e) {
            error_log('[stamp] auto revoke on leave failed for uid=' . $userId . ': ' . $e->getMessage());
            return 0;
        }
    }

    // 帳號狀態限制：「特殊帳號」「最高權限帳號」（employee_management.php 在職狀態下拉 state=90/99）不可為其登記圖章。
    // 2026-09-24 使用者要求。只擋「建立新登記」；既有登記（理論上不該發生，目前查證全庫 0 筆）仍可修改／停用，不回溯處理。
    if (!defined('EG_STAMP_BLOCKED_HOLDER_STATES')) {
        define('EG_STAMP_BLOCKED_HOLDER_STATES', [90, 99]);
    }
    function eg_stamp_holder_blocked(PDO $db, int $userId): ?array {
        if ($userId <= 0) return null;
        try {
            $st = $db->prepare("SELECT state, user_cname FROM user WHERE id = ?");
            $st->execute([$userId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['state'] !== null && in_array((int)$r['state'], EG_STAMP_BLOCKED_HOLDER_STATES, true)) {
                require_once __DIR__ . '/user_active_lib.php';
                return ['state' => (int)$r['state'], 'name' => (string)$r['user_cname'],
                        'label' => eg_user_state_label($r['state'])];
            }
        } catch (Exception $e) {}
        return null;
    }
}

// ============================== 復職恢復圖章／缺圖章提示 ==============================
//
// 為什麼要有這一段（2026-10-16 使用者交辦）：
//   2026-09-24 加的「離職自動作廢圖章」只有單向——**復職時沒有任何程式會把章恢復**，
//   而且不會報錯，要等某張列印文件蓋出預設章才會發現。
//
// 使用者定調：
//   ① 復職後要**自動產生新的核發登記**（不是把原本那列改回 active）——離職那段期間章
//      確實是作廢的，保留原列才追溯得出來；新列的核發日＝復職日。
//   ② **要偵測部門是否與原本相同，不同就只提示、不自動建立**。原因不只是資料好不好看：
//      模板「人員簽章(長方)」的版面是 `{部門} {姓名}`，部門換了章面就是錯的。
//   ③ **有新進人員時自動提示建立圖章**（既有的只有「新增員工存檔後問一次」，按掉就沒了）。
//
// 只處理「因離職而被系統自動作廢」的那些（revoke_reason='leave'）；
// 人工作廢的一律不碰——那是人判斷過要停用的，不該被復職偷偷復活。

if (!function_exists('eg_stamp_ensure_schema')) {
    /**
     * 補上本功能要用的欄位與表（可重複執行）。
     * **這個 MySQL（9.4.0）沒有 `ADD COLUMN IF NOT EXISTS`**（那是 MariaDB 語法，硬寫會 1064
     * 而且錯誤被 catch 吞掉＝欄位永遠加不上去、功能安靜失效），所以一律先 SHOW COLUMNS 再逐欄 ALTER。
     * 只在真的要用到新欄位的路徑呼叫（靜態旗標保證一個 request 最多跑一次），
     * 不要掛在 stamp_lib 載入時——這支共用檔很多頁面都 include，每頁都 SHOW COLUMNS 是白花成本。
     */
    function eg_stamp_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = $db->query("SHOW COLUMNS FROM stamp_register")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('revoke_reason', $cols, true)) {
                $db->exec("ALTER TABLE stamp_register ADD COLUMN `revoke_reason` VARCHAR(20) NULL
                           COMMENT '作廢原因：leave=離職自動作廢(復職可自動重新核發)／leave_reissued=已因復職重新核發過／NULL=人工作廢'");
            }
            if (!in_array('reissued_from_id', $cols, true)) {
                $db->exec("ALTER TABLE stamp_register ADD COLUMN `reissued_from_id` INT NULL
                           COMMENT '本列是由哪一筆作廢登記因復職重新核發而來（stamp_register.id），供追溯'");
            }
            if (!$db->query("SHOW TABLES LIKE 'stamp_no_need'")->fetchColumn()) {
                $db->exec("CREATE TABLE `stamp_no_need` (
                    `user_id` INT NOT NULL COMMENT '已確認不需要圖章的人員',
                    `note` VARCHAR(200) NULL COMMENT '原因備註',
                    `created_by` VARCHAR(30) NULL,
                    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                  COMMENT='標記為不需要圖章的人員，避免「在職卻沒有圖章」的提示變成永久雜訊'");
            }
        } catch (Throwable $e) {
            error_log('[stamp] ensure schema failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('eg_stamp_main_dept')) {
    /** 職務快照 → 主職部門 id（沒有主職就取第一筆；完全沒職務回 0） */
    function eg_stamp_main_dept(array $snap): int {
        foreach ($snap as $s) if ((int)($s['is_main'] ?? 0) === 1) return (int)$s['department_id'];
        return $snap ? (int)$snap[0]['department_id'] : 0;
    }
}

if (!function_exists('eg_stamp_rehire_plan')) {
    /**
     * 復職時要怎麼處理那些「因離職被自動作廢」的圖章。
     * @return array ['reissue'=>[可自動重新核發], 'manual'=>[要人工建立], 'skipped'=>[已經有了/不處理]]
     *
     * 判定（使用者定調「部門不同就只提示」）：
     *   - 部門人員章（登記上有 dept_id）：此人目前**還屬於**那個部門（含兼任）才自動；已不屬於＝dept_left
     *   - 個人章（登記上沒有 dept_id）：**離職當時的主職部門**與**現在的主職部門**相同才自動；不同＝dept_changed
     *     （離職當時部門用 position_history_lib 依離職日回推＝ai-rules/22 同一套；
     *      沒補登過異動紀錄的人會回現況，等於視同沒變——這是刻意的，不然每個人都會被判成要人工處理）
     *   - 模板或種類已停用 → 不自動（tpl_inactive／type_inactive），否則會建出一個畫不出來的章
     *   - 復職後還沒設定部門職稱 → 不自動（no_position）
     *   - 同持有對象＋同模板已經有一筆使用中 → skipped（stamp_register 的唯一性規則本來就擋，不重複建）
     */
    function eg_stamp_rehire_plan(PDO $db, int $userId, ?string $rehireDate = null): array {
        eg_stamp_ensure_schema($db);
        $out = ['reissue' => [], 'manual' => [], 'skipped' => []];
        if ($userId <= 0) return $out;
        require_once __DIR__ . '/position_history_lib.php';

        try {
            // 帳號狀態不可登記圖章者（特殊帳號／最高權限帳號）一律不處理
            if (eg_stamp_holder_blocked($db, $userId)) return $out;

            $st = $db->prepare(
                "SELECT r.id, r.dept_id, r.position_id, r.type_id, r.template_id, r.note,
                        r.issue_date, r.revoke_date,
                        t.type_name, t.is_active AS type_active,
                        p.tpl_name, p.is_active AS tpl_active, p.schema_json,
                        d.name AS dept_name
                 FROM stamp_register r
                 LEFT JOIN stamp_type t ON t.id = r.type_id
                 LEFT JOIN stamp_template p ON p.id = r.template_id
                 LEFT JOIN department d ON d.id = r.dept_id
                 WHERE r.user_id = ? AND r.status = 'revoked' AND r.revoke_reason = 'leave'
                 ORDER BY r.revoke_date DESC, r.id");
            $st->execute([$userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return $out;

            $nowSnap = eg_position_snapshot_now($db, $userId);
            $nowDept = eg_stamp_main_dept($nowSnap);
            $myDepts = array_map(fn($s) => (int)$s['department_id'], $nowSnap);   // 含兼任

            $dup = $db->prepare("SELECT 1 FROM stamp_register
                                 WHERE user_id <=> ? AND dept_id <=> ? AND position_id <=> ?
                                   AND template_id <=> ? AND status = 'active' LIMIT 1");

            foreach ($rows as $r) {
                $item = [
                    'id'          => (int)$r['id'],
                    'type_id'     => $r['type_id'] !== null ? (int)$r['type_id'] : null,
                    'template_id' => $r['template_id'] !== null ? (int)$r['template_id'] : null,
                    'dept_id'     => $r['dept_id'] !== null ? (int)$r['dept_id'] : null,
                    'position_id' => $r['position_id'] !== null ? (int)$r['position_id'] : null,
                    'type_name'   => (string)($r['type_name'] ?? ''),
                    'tpl_name'    => (string)($r['tpl_name'] ?? ''),
                    'dept_name'   => (string)($r['dept_name'] ?? ''),
                    'note'        => (string)($r['note'] ?? ''),
                    'issue_date'  => (string)$r['issue_date'],
                    'revoke_date' => (string)($r['revoke_date'] ?? ''),
                    // 這個模板的章面上到底有沒有印部門？有的話「部門換了」就是章面會錯，
                    // 寫出來管理員才判斷得快（實測模板「人員簽章(長方)」是 {部門} {姓名}）
                    'tpl_uses_dept' => (strpos((string)$r['schema_json'], '{部門') !== false),
                    'reason' => '', 'reason_text' => '',
                ];

                $dup->execute([$userId, $item['dept_id'], $item['position_id'], $item['template_id']]);
                if ($dup->fetchColumn()) {
                    $item['reason'] = 'already_active';
                    $item['reason_text'] = '此持有對象與模板目前已有一筆使用中的登記，不重複建立';
                    $out['skipped'][] = $item; continue;
                }
                if ($r['template_id'] !== null && (int)$r['tpl_active'] !== 1) {
                    $item['reason'] = 'tpl_inactive';
                    $item['reason_text'] = '原模板「' . $item['tpl_name'] . '」已停用，請改選模板後重新建立';
                    $out['manual'][] = $item; continue;
                }
                if ($r['type_id'] !== null && (int)$r['type_active'] !== 1) {
                    $item['reason'] = 'type_inactive';
                    $item['reason_text'] = '原印章種類「' . $item['type_name'] . '」已停用，請重新建立';
                    $out['manual'][] = $item; continue;
                }
                if (!$nowSnap) {
                    $item['reason'] = 'no_position';
                    $item['reason_text'] = '復職後尚未設定部門／職稱，無法判斷部門是否相同，請先補好職務再建立圖章';
                    $out['manual'][] = $item; continue;
                }

                if ($item['dept_id'] !== null) {
                    // 部門人員章／課室相關：還在那個部門才自動恢復
                    if (!in_array($item['dept_id'], $myDepts, true)) {
                        $item['reason'] = 'dept_left';
                        $item['reason_text'] = '原登記綁定「' . ($item['dept_name'] ?: ('部門' . $item['dept_id']))
                            . '」，此人復職後已不屬於該部門，請依新部門重新建立';
                        $out['manual'][] = $item; continue;
                    }
                } else {
                    // 個人章：比「離職當時主職部門」與「現在主職部門」
                    $wasSnap = eg_position_snapshot_at($db, $userId, $item['revoke_date'] ?: $item['issue_date']);
                    $wasDept = eg_stamp_main_dept($wasSnap);
                    if ($wasDept !== 0 && $nowDept !== 0 && $wasDept !== $nowDept) {
                        $item['was_dept'] = eg_position_snapshot_label($wasSnap);
                        $item['now_dept'] = eg_position_snapshot_label($nowSnap);
                        $item['reason'] = 'dept_changed';
                        $item['reason_text'] = '離職當時的部門與復職後不同（' . $item['was_dept'] . ' → ' . $item['now_dept'] . '）'
                            . ($item['tpl_uses_dept'] ? '，而此模板的章面會印出部門，直接恢復會印錯' : '')
                            . '，請重新建立';
                        $out['manual'][] = $item; continue;
                    }
                }

                $item['reason'] = 'ok';
                $item['reason_text'] = '部門與原本相同，可自動重新核發';
                $out['reissue'][] = $item;
            }
        } catch (Throwable $e) {
            error_log('[stamp] rehire plan failed for uid=' . $userId . ': ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('eg_stamp_rehire_apply')) {
    /**
     * 把 plan 裡 reissue 的那些真的建出新登記（核發日＝復職日），並把原作廢列標成
     * leave_reissued 以免下次復職又被撿一次。
     *
     * **呼叫端可能已經在交易裡**（員工管理存檔就是），所以這裡不自己 begin/commit，
     * 由呼叫端的交易涵蓋；失敗丟例外讓呼叫端決定要不要 rollback。
     * @return array 建出來的列 [['new_id','from_id','type_name','tpl_name','dept_name'], ...]
     */
    function eg_stamp_rehire_apply(PDO $db, array $plan, string $rehireDate, string $operator = 'system'): array {
        eg_stamp_ensure_schema($db);
        $made = [];
        if (empty($plan['reissue'])) return $made;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rehireDate)) return $made;
        if (empty($plan['user_id'])) return $made;
        $uid = (int)$plan['user_id'];

        $ins = $db->prepare("INSERT INTO stamp_register
            (user_id, dept_id, position_id, type_id, template_id, issue_date, status, note, created_by, reissued_from_id)
            VALUES (?,?,?,?,?,?,'active',?,?,?)");
        $mark = $db->prepare("UPDATE stamp_register
                              SET revoke_reason='leave_reissued', modified_by=?, modified_at=NOW() WHERE id=?");

        foreach ($plan['reissue'] as $it) {
            // 備註保留原本的，後面接一段來源說明（上限 200 字，與欄位長度一致）
            $tag  = '（復職自動重新核發，原登記 #' . (int)$it['id'] . ' 作廢日 ' . ($it['revoke_date'] ?: '—') . '）';
            $note = trim((string)$it['note']);
            $note = mb_substr($note === '' ? $tag : ($note . ' ' . $tag), 0, 200);
            $ins->execute([
                $uid,
                $it['dept_id'] !== null ? (int)$it['dept_id'] : null,
                $it['position_id'] !== null ? (int)$it['position_id'] : null,
                $it['type_id'] !== null ? (int)$it['type_id'] : null,
                $it['template_id'] !== null ? (int)$it['template_id'] : null,
                $rehireDate, $note, $operator, (int)$it['id'],
            ]);
            $newId = (int)$db->lastInsertId();
            $mark->execute([$operator, (int)$it['id']]);
            $made[] = ['new_id' => $newId, 'from_id' => (int)$it['id'],
                       'type_name' => (string)$it['type_name'], 'tpl_name' => (string)$it['tpl_name'],
                       'dept_name' => (string)$it['dept_name']];
        }
        return $made;
    }
}

if (!function_exists('eg_stamp_rehire_on_reinstate')) {
    /**
     * 復職當下的一次性處理：算 plan → 自動建出可建的 → 回傳要提示的內容。
     * 給 src/store/_employee_api.php 的「在職狀態改回在職」那條路呼叫。
     * @return array ['created'=>[...], 'manual'=>[...], 'skipped'=>[...]]
     */
    function eg_stamp_rehire_on_reinstate(PDO $db, int $userId, string $rehireDate, string $operator = 'system'): array {
        $plan = eg_stamp_rehire_plan($db, $userId, $rehireDate);
        $plan['user_id'] = $userId;
        $created = [];
        try {
            $created = eg_stamp_rehire_apply($db, $plan, $rehireDate, $operator);
        } catch (Throwable $e) {
            // 圖章恢復失敗絕不可讓「復職」這件事存不進去——記 log、改成提示人工處理
            error_log('[stamp] rehire apply failed for uid=' . $userId . ': ' . $e->getMessage());
            foreach ($plan['reissue'] as $it) {
                $it['reason'] = 'apply_failed';
                $it['reason_text'] = '自動重新核發失敗（' . $e->getMessage() . '），請人工建立';
                $plan['manual'][] = $it;
            }
            $created = [];
        }
        return ['created' => $created, 'manual' => $plan['manual'], 'skipped' => $plan['skipped']];
    }
}

if (!function_exists('eg_stamp_attention_rows')) {
    /**
     * 圖章管理頁的常駐提示：目前有哪些人「該有章卻沒有」。兩種來源合併成一份清單：
     *   kind=no_stamp    在職、帳號狀態可登記圖章，卻**完全沒有任何使用中的圖章登記**
     *                    （含新進人員——使用者要求「有新進人員時自動提示建立圖章」。
     *                      既有做法只有「新增員工存檔後 confirm 問一次」，按掉就沒有第二次提醒了）
     *   kind=rehire_todo 因離職被自動作廢、人已復職，但因部門不同等原因沒有自動恢復的登記
     *
     * **已標記「不需要圖章」的人一律不列**（stamp_no_need）——不給這個出口的話，不需要章的
     * 現場人員會永遠掛在提示上，橫幅就變成每天看到卻沒人處理的雜訊（CLAUDE.md 記過這種假警報）。
     */
    function eg_stamp_attention_rows(PDO $db): array {
        eg_stamp_ensure_schema($db);
        $out = [];
        $blocked = implode(',', array_map('intval', EG_STAMP_BLOCKED_HOLDER_STATES));
        try {
            // ① 在職卻沒有任何使用中圖章
            $sql = "SELECT u.id, u.user_cname, u.hire_date,
                           d.name AS dept_name, p.name AS pos_name,
                           DATEDIFF(CURDATE(), u.hire_date) AS days_since_hire
                    FROM `user` u
                    LEFT JOIN user_department_position_map m ON m.user_id = u.id AND m.is_main = 1
                    LEFT JOIN department d ON d.id = m.department_id
                    LEFT JOIN position p ON p.id = m.position_id
                    WHERE u.state = 1
                      AND (u.state IS NULL OR u.state NOT IN ($blocked))
                      AND NOT EXISTS (SELECT 1 FROM stamp_register r WHERE r.user_id = u.id AND r.status = 'active')
                      AND NOT EXISTS (SELECT 1 FROM stamp_no_need n WHERE n.user_id = u.id)
                    ORDER BY u.hire_date DESC, u.id";
            foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = $r['days_since_hire'];
                $out[] = [
                    'kind' => 'no_stamp', 'user_id' => (int)$r['id'], 'name' => (string)$r['user_cname'],
                    'dept_name' => (string)($r['dept_name'] ?? ''), 'pos_name' => (string)($r['pos_name'] ?? ''),
                    'hire_date' => (string)($r['hire_date'] ?? ''),
                    'is_new' => ($d !== null && (int)$d <= 90),      // 到職 90 天內視為新進，排在前面提醒
                    'text' => '在職人員目前沒有任何使用中的圖章登記'
                              . (($d !== null && (int)$d <= 90) ? '（新進人員，到職 ' . (int)$d . ' 天）' : ''),
                ];
            }
            // ② 復職後沒自動恢復、還掛著的
            $sql2 = "SELECT r.id, r.user_id, u.user_cname, r.revoke_date,
                            t.type_name, p.tpl_name, d.name AS dept_name
                     FROM stamp_register r
                     JOIN `user` u ON u.id = r.user_id
                     LEFT JOIN stamp_type t ON t.id = r.type_id
                     LEFT JOIN stamp_template p ON p.id = r.template_id
                     LEFT JOIN department d ON d.id = r.dept_id
                     WHERE r.status = 'revoked' AND r.revoke_reason = 'leave' AND u.state = 1
                       AND (u.state IS NULL OR u.state NOT IN ($blocked))
                     ORDER BY r.revoke_date DESC, r.id";
            foreach ($db->query($sql2)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'kind' => 'rehire_todo', 'user_id' => (int)$r['user_id'], 'name' => (string)$r['user_cname'],
                    'register_id' => (int)$r['id'], 'dept_name' => (string)($r['dept_name'] ?? ''),
                    'type_name' => (string)($r['type_name'] ?? ''), 'tpl_name' => (string)($r['tpl_name'] ?? ''),
                    'revoke_date' => (string)($r['revoke_date'] ?? ''), 'is_new' => false,
                    'text' => '此人已復職，但離職時作廢的「' . (string)($r['type_name'] ?? '圖章')
                              . ($r['tpl_name'] ? '／' . $r['tpl_name'] : '') . '」沒有自動恢復，需要人工建立',
                ];
            }
        } catch (Throwable $e) {
            error_log('[stamp] attention rows failed: ' . $e->getMessage());
        }
        // 新進排最前面（最需要立刻處理），其餘維持各自的日期序
        usort($out, fn($a, $b) => ($b['is_new'] ? 1 : 0) <=> ($a['is_new'] ? 1 : 0));
        return $out;
    }
}

if (!function_exists('eg_stamp_no_need_set')) {
    /** 標記／取消標記「這個人不需要圖章」（呼叫端負責驗管理權限） */
    function eg_stamp_no_need_set(PDO $db, int $userId, bool $on, string $note = '', string $operator = ''): void {
        eg_stamp_ensure_schema($db);
        if ($userId <= 0) return;
        if ($on) {
            $db->prepare("INSERT INTO stamp_no_need (user_id, note, created_by) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE note=VALUES(note), created_by=VALUES(created_by)")
               ->execute([$userId, mb_substr($note, 0, 200), $operator]);
        } else {
            $db->prepare("DELETE FROM stamp_no_need WHERE user_id = ?")->execute([$userId]);
        }
    }
}

if (!function_exists('eg_stamp_no_need_list')) {
    /** 已標記不需要圖章的人員（給設定畫面列出來，才有辦法取消） */
    function eg_stamp_no_need_list(PDO $db): array {
        eg_stamp_ensure_schema($db);
        try {
            return $db->query("SELECT n.user_id, u.user_cname AS name, n.note, n.created_by, n.created_at,
                                      d.name AS dept_name, p.name AS pos_name, u.state
                               FROM stamp_no_need n
                               JOIN `user` u ON u.id = n.user_id
                               LEFT JOIN user_department_position_map m ON m.user_id = u.id AND m.is_main = 1
                               LEFT JOIN department d ON d.id = m.department_id
                               LEFT JOIN position p ON p.id = m.position_id
                               ORDER BY n.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
