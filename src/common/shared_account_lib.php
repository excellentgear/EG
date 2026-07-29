<?php
// 共用帳號（現場多人共用一個登入帳號）：成員綁定 → 通知轉送 的共用函式。
// 規格：ai-rules/13-共用帳號通知與綁定.md
//
// 設計重點：
//  1. 全站唯一的「收件人展開層」。各模組（roster / QA / 報價 / 請假 / CAR / BOM…）零修改自動受益，
//     因為所有 Web Push 都收斂到 eg_push_send_to_users()、Telegram 收斂到 eg_telegram_event_notify()，
//     兩者都在內部呼叫 eg_shared_fanout()。
//  2. 兩種模式：
//     attach（綁定依附）＝員工平常不登入，通知只送共用帳號（本人不推播，站內清單仍看得到留痕）。
//     notify（開通）    ＝員工自有帳號照常用，本人＋共用帳號雙送。
//  3. 訊息一律前綴「【給 ○○○】」，同一共用帳號有多位成員是收件人時「每位成員各一則」不合併，
//     否則現場分不出這則是給誰的。
//  4. 只轉送「指名通知」（live_event_target.target_type='user'）；全體/部門/身分廣播不逐人轉送，
//     否則一台平板綁 5 個人，一則全體公告會變成 6 則。
//
// 未跑遷移（欄位/資料表不存在）時所有函式退化為「無綁定」，全站行為與改造前完全一致。

if (!function_exists('eg_shared_ready')) {
    /** 遷移是否已完成（欄位與資料表都在）。查一次即快取。 */
    function eg_shared_ready(PDO $db): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        $ready = false;
        try {
            $ok = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
                                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shared_account_member'")->fetchColumn();
            $col = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user' AND COLUMN_NAME='is_shared_account'")->fetchColumn();
            $ready = ($ok > 0 && $col > 0);
        } catch (Throwable $e) {
            error_log('[shared] ready check failed: ' . $e->getMessage());
        }
        return $ready;
    }
}

if (!function_exists('eg_shared_bindings_for')) {
    /**
     * 取得這些員工的共用帳號綁定。
     * @return array member_uid => [ ['shared_uid'=>int,'mode'=>'attach|notify'], ... ]
     */
    function eg_shared_bindings_for(PDO $db, array $memberUids): array
    {
        $memberUids = array_values(array_unique(array_filter(array_map('intval', $memberUids))));
        if (empty($memberUids) || !eg_shared_ready($db)) return [];
        try {
            $in = implode(',', $memberUids);
            $rows = $db->query("SELECT m.member_uid, m.shared_uid, m.mode
                                FROM shared_account_member m
                                JOIN `user` s ON s.id = m.shared_uid AND s.is_shared_account = 1
                                WHERE m.active = 1 AND m.member_uid IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $out[(int)$r['member_uid']][] = [
                    'shared_uid' => (int)$r['shared_uid'],
                    'mode'       => ($r['mode'] === 'notify' ? 'notify' : 'attach'),
                ];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[shared] bindings_for failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('eg_shared_fanout')) {
    /**
     * 收件人展開：原收件人清單 → 實際投遞清單。
     *
     * @param int[]      $uids       原收件人 user.id
     * @param int[]|null $fanoutOnly 只有在此集合內的 uid 才轉送（＝指名通知的收件人）；
     *                               null＝全部都可轉送（模組直接指名推播時用）。
     * @return array [ ['deliver_uid'=>int,'for_uid'=>int,'for_name'=>string], ... ]
     *               deliver_uid===for_uid 表示「本人自己收」，不需加「【給 ○○○】」前綴。
     */
    function eg_shared_fanout(PDO $db, array $uids, ?array $fanoutOnly = null): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (empty($uids)) return [];

        $plain = [];
        foreach ($uids as $u) $plain[] = ['deliver_uid' => $u, 'for_uid' => $u, 'for_name' => ''];
        if (!eg_shared_ready($db)) return $plain;

        try {
            $allow = ($fanoutOnly === null) ? null : array_flip(array_map('intval', $fanoutOnly));
            $bind  = eg_shared_bindings_for($db, $uids);
            if (empty($bind)) return $plain;

            // 需要姓名的員工（有綁定者）
            $names = [];
            $needName = array_keys($bind);
            if ($needName) {
                $in = implode(',', array_map('intval', $needName));
                foreach ($db->query("SELECT id, user_cname FROM `user` WHERE id IN ($in)") as $r) {
                    $names[(int)$r['id']] = (string)$r['user_cname'];
                }
            }

            $out = [];
            $seen = [];
            $push = function (int $deliver, int $for) use (&$out, &$seen, $names) {
                $k = $deliver . '|' . $for;
                if (isset($seen[$k])) return;
                $seen[$k] = 1;
                $out[] = [
                    'deliver_uid' => $deliver,
                    'for_uid'     => $for,
                    'for_name'    => ($deliver === $for) ? '' : ($names[$for] ?? ''),
                ];
            };

            foreach ($uids as $u) {
                $canFanout = ($allow === null) || isset($allow[$u]);
                if (!$canFanout || empty($bind[$u])) { $push($u, $u); continue; }

                $selfKept = false;
                foreach ($bind[$u] as $b) {
                    if ($b['mode'] === 'notify') $selfKept = true;   // 開通：本人也收
                    $push($b['shared_uid'], $u);
                }
                // attach：本人不推播（站內清單仍看得到，見 sideAndTopBarMenu / _myNotices）
                if ($selfKept) $push($u, $u);
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[shared] fanout failed: ' . $e->getMessage());
            return $plain;   // 失敗一律退回原行為，不可讓通知整個發不出去
        }
    }
}

if (!function_exists('eg_shared_prefix_title')) {
    /** 依 fanout 結果決定標題前綴：轉送給共用帳號時加「【給 ○○○】」。 */
    function eg_shared_prefix_title(string $title, array $entry): string
    {
        if (($entry['deliver_uid'] ?? 0) === ($entry['for_uid'] ?? 0)) return $title;
        $name = trim((string)($entry['for_name'] ?? ''));
        if ($name === '') return $title;
        return '【給 ' . $name . '】' . $title;
    }
}

if (!function_exists('eg_shared_named_recipients')) {
    /**
     * 某公告的「指名收件人」（live_event_target.target_type='user'）。
     * 全體/部門/身分廣播不在其中 → 不逐人轉送到共用帳號（避免現場平板洗版）。
     * @return int[]
     */
    function eg_shared_named_recipients(PDO $db, int $eventId): array
    {
        try {
            $st = $db->prepare("SELECT DISTINCT target_id FROM live_event_target
                                WHERE live_event_id = ? AND target_type = 'user'");
            $st->execute([$eventId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('[shared] named_recipients failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('eg_shared_view_uids')) {
    /**
     * 站內清單/鈴鐺用：登入者實際「看得到誰的指名通知」。
     * 共用帳號 → [自己] + 其 active 成員；一般帳號 → [自己]。
     * @return int[]
     */
    function eg_shared_view_uids(PDO $db, int $uid): array
    {
        $uid = (int)$uid;
        if ($uid <= 0) return [];
        if (!eg_shared_ready($db)) return [$uid];
        try {
            $st = $db->prepare("SELECT m.member_uid FROM shared_account_member m
                                JOIN `user` s ON s.id = m.shared_uid AND s.is_shared_account = 1
                                JOIN `user` u ON u.id = m.member_uid
                                WHERE m.active = 1 AND m.shared_uid = ?");
            $st->execute([$uid]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            return array_values(array_unique(array_merge([$uid], $ids)));
        } catch (Throwable $e) {
            error_log('[shared] view_uids failed: ' . $e->getMessage());
            return [$uid];
        }
    }
}

if (!function_exists('eg_shared_member_names')) {
    /** 共用帳號的成員 id => 姓名（active 者）。 */
    function eg_shared_member_names(PDO $db, int $sharedUid): array
    {
        if (!eg_shared_ready($db)) return [];
        try {
            $st = $db->prepare("SELECT u.id, u.user_cname FROM shared_account_member m
                                JOIN `user` u ON u.id = m.member_uid
                                WHERE m.active = 1 AND m.shared_uid = ?");
            $st->execute([(int)$sharedUid]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['user_cname'];
            return $out;
        } catch (Throwable $e) {
            error_log('[shared] member_names failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('eg_shared_is_member_of')) {
    /** $memberUid 是否為 $sharedUid 的 active 成員（代簽驗密碼前必查）。 */
    function eg_shared_is_member_of(PDO $db, int $sharedUid, int $memberUid): bool
    {
        if (!eg_shared_ready($db)) return false;
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM shared_account_member m
                                JOIN `user` s ON s.id = m.shared_uid AND s.is_shared_account = 1
                                WHERE m.active = 1 AND m.shared_uid = ? AND m.member_uid = ?");
            $st->execute([(int)$sharedUid, (int)$memberUid]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[shared] is_member_of failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('eg_shared_password_locked')) {
    /** 該帳號是否禁止改密碼（lock_password=1）。 */
    function eg_shared_password_locked(PDO $db, int $uid): bool
    {
        if (!eg_shared_ready($db)) return false;
        try {
            $st = $db->prepare("SELECT lock_password FROM `user` WHERE id = ?");
            $st->execute([(int)$uid]);
            return ((int)$st->fetchColumn() === 1);
        } catch (Throwable $e) {
            error_log('[shared] password_locked failed: ' . $e->getMessage());
            return false;
        }
    }
}
