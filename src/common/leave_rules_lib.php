<?php
/**
 * leave_rules_lib.php — 假別特殊規則（喪假／育嬰類）的唯一實作點  2026-07-31
 *
 * 為什麼獨立一支：leave_lib.php 已 1700+ 行（鐵律2 巨檔保護），且這套規則是
 * 「可被人事調參數」的政策層，跟簽核流程的交易邏輯性質不同，分開比較好維護。
 *
 * ── 設計要點 ───────────────────────────────────────────────
 * 1. 規則參數放在 **leave_type 每一列**，不放全域 system_settings。
 *    理由：育嬰留停與育嬰假的上限本來就不一樣，全域設定沒辦法同時服務兩者；
 *    未來人事新增假別也能直接套規則，不必改程式。
 * 2. `rule_kind` 只有兩種：
 *      bereavement 喪假 —— 依「亡故親屬關係」決定天數上限、死亡日起 N 日內請畢、
 *                          同一次治喪（同死亡日＋同關係）跨多張單累計。
 *      parental    育嬰類 —— 依「子女出生日」歸戶，子女滿 N 歲前才能請、
 *                          每一子女有累計上限、可設單次最少天數。
 *                          育嬰留停與育嬰假共用同一套判定，只是參數不同。
 * 3. **累計比較的單位跟著 `rule_max_unit` 走**，因為不同語意本來就該用不同尺：
 *      year / month → 以**曆日**累計（長假講的是「請了多久」，跳過假日算不出 2 年）
 *      day          → 以**系統算出的請假天數（工作日）**累計（與特休同一把尺）
 *      hour         → 以**時數**累計
 *    這條規則必須同步寫在人事設定畫面上，否則人事會填出自己也不知道在算什麼的數字。
 * 4. 違反規則一律**擋下**（使用者 2026-07-30 拍板），前端即時紅字說明原因、
 *    後端送審與修改時再驗一次（不採信前端）。只有「多子女合併計算」是提醒不是擋下——
 *    法規要的是「同時撫育」才合併，那需要人的判斷，系統不該替人事決定。
 */

if (!function_exists('eg_leave_rule_extra_in')) {
    /**
     * 從送進來的資料撈出規則欄位並正規化（空字串一律轉 null，才不會把 '' 寫進 DATE 欄位）。
     * 送審與修改兩條路都用同一支，避免兩邊各自 trim 出不同結果。
     */
    function eg_leave_rule_extra_in(array $in): array {
        $d = function ($v) {
            $v = trim((string)$v);
            return ($v === '' || strtotime($v) === false) ? null : substr($v, 0, 10);
        };
        $gid = (int)($in['rel_grade_id'] ?? 0);
        return [
            'rel_grade_id'   => $gid > 0 ? $gid : null,
            'deceased_date'  => $d($in['deceased_date'] ?? ''),
            'child_birthday' => $d($in['child_birthday'] ?? ''),
        ];
    }
}

if (!function_exists('eg_leave_rule_extra_store')) {
    /**
     * 依假別的 rule_kind 決定「要存哪些規則欄位」。
     * 不是這個假別該有的欄位一律存 null——否則使用者先選喪假填了死亡日、再改成事假送出，
     * 事假單上會莫名其妙掛著一個死亡日期，之後統計與稽核都會被誤導。
     */
    function eg_leave_rule_extra_store(array $type, array $extra): array {
        $kind = (string)($type['rule_kind'] ?? '');
        if ($kind === 'bereavement') {
            return ['rel_grade_id' => $extra['rel_grade_id'], 'deceased_date' => $extra['deceased_date'],
                    'child_birthday' => null];
        }
        if ($kind === 'parental') {
            return ['rel_grade_id' => null, 'deceased_date' => null,
                    'child_birthday' => $extra['child_birthday']];
        }
        return ['rel_grade_id' => null, 'deceased_date' => null, 'child_birthday' => null];
    }
}

if (!function_exists('eg_leave_rule_grades')) {
    /** 喪假親等清單。$activeOnly=false 時連停用的也回（舊單要顯示得出關係名稱） */
    function eg_leave_rule_grades(PDO $db, bool $activeOnly = true): array {
        try {
            $sql = "SELECT id, grade_name, max_days, sort_order, is_active FROM leave_bereavement_grade"
                 . ($activeOnly ? " WHERE is_active = 1" : "")
                 . " ORDER BY sort_order, id";
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['max_days'] = (float)$r['max_days'];
            $r['is_active'] = (int)$r['is_active'];
        }
        return $rows;
    }
}

if (!function_exists('eg_leave_rule_grade')) {
    /** 單一親等（含停用者）；查不到回 null */
    function eg_leave_rule_grade(PDO $db, int $gradeId): ?array {
        if ($gradeId <= 0) return null;
        foreach (eg_leave_rule_grades($db, false) as $g) if ($g['id'] === $gradeId) return $g;
        return null;
    }
}

if (!function_exists('eg_leave_rule_cap_days')) {
    /** 把「上限值＋單位」換算成天（year 以 365 天、month 以 30 天計；hour 不走這裡） */
    function eg_leave_rule_cap_days(float $value, string $unit): float {
        if ($unit === 'year')  return $value * 365;
        if ($unit === 'month') return $value * 30;
        return $value;   // day
    }
}

if (!function_exists('eg_leave_rule_measure_label')) {
    /** 該單位下「累計用什麼衡量」的中文說明，畫面與訊息共用同一句，避免兩邊講法不一致 */
    function eg_leave_rule_measure_label(string $unit): string {
        if ($unit === 'year' || $unit === 'month') return '曆日（含假日，長假以實際經過的日子計）';
        if ($unit === 'hour') return '時數';
        return '請假天數（只計工作日，與特休同一把尺）';
    }
}

if (!function_exists('eg_leave_rule_cal_days')) {
    /** 曆日數：起訖日相隔幾天（含頭含尾）。2026-01-01~2026-01-01 = 1 天 */
    function eg_leave_rule_cal_days(string $startDt, string $endDt): float {
        $s = strtotime(substr($startDt, 0, 10));
        $e = strtotime(substr($endDt, 0, 10));
        if ($s === false || $e === false || $e < $s) return 0.0;
        return floor(($e - $s) / 86400) + 1;
    }
}

if (!function_exists('eg_leave_rule_amount_in_unit')) {
    /**
     * 把一張單換算成「該規則單位下的量」。
     * @param array $row 需含 start_datetime / end_datetime / total_days / total_hours
     */
    function eg_leave_rule_amount_in_unit(array $row, string $unit): float {
        if ($unit === 'year' || $unit === 'month') {
            return eg_leave_rule_cal_days((string)$row['start_datetime'], (string)$row['end_datetime']);
        }
        if ($unit === 'hour') return (float)($row['total_hours'] ?? 0);
        return (float)($row['total_days'] ?? 0);
    }
}

if (!function_exists('eg_leave_rule_fmt')) {
    /** 數值顯示：小數尾 0 省略（3.50→3.5、3.00→3），比照全站數字規範 */
    function eg_leave_rule_fmt(float $v): string {
        $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}

if (!function_exists('eg_leave_rule_unit_word')) {
    /** 上限單位的顯示字（累計時一律換算成天／小時，所以 year/month 顯示「天」） */
    function eg_leave_rule_unit_word(string $unit): string {
        return ($unit === 'hour') ? '小時' : '天';
    }
}

if (!function_exists('eg_leave_rule_quota')) {
    /**
     * 算出這張單所屬「事件／子女」的上限、已用、剩餘。
     * 已用＝同一人、同一假別、同一事件（喪假：同死亡日＋同關係；育嬰：同子女出生日）
     *       且狀態為 approved / pending / cancel_pending 的單（送審中也要佔用，否則可以連送好幾張繞過上限）。
     *
     * @param array $extra ['rel_grade_id'=>int, 'deceased_date'=>'Y-m-d', 'child_birthday'=>'Y-m-d']
     * @param int|null $excludeReqId 修改自己這張單時要把自己排除，否則會跟自己相撞
     * @return array|null 沒有上限（或資料不足以判斷）回 null
     */
    function eg_leave_rule_quota(PDO $db, int $uid, array $type, array $extra, ?int $excludeReqId = null): ?array {
        $kind = (string)($type['rule_kind'] ?? '');
        if ($kind === '') return null;
        $unit = (string)($type['rule_max_unit'] ?? 'day');
        $cap = null; $capNote = '';

        if ($kind === 'bereavement') {
            // 喪假上限一律取自親等表，忽略 leave_type.rule_max_value（不同關係天數不同，單一數字表達不了）
            $g = eg_leave_rule_grade($db, (int)($extra['rel_grade_id'] ?? 0));
            if (!$g) return null;
            $cap = (float)$g['max_days'];
            $unit = 'day';
            $capNote = $g['grade_name'];
        } else {
            if ($type['rule_max_value'] === null || $type['rule_max_value'] === '') return null;   // 不設上限
            $cap = eg_leave_rule_cap_days((float)$type['rule_max_value'], $unit);
            if ($unit === 'hour') $cap = (float)$type['rule_max_value'];
        }
        if ($cap === null || $cap <= 0) return null;

        // 同一事件的既有單
        $where = ["employee_id = ?", "leave_type_id = ?", "status IN ('approved','pending','cancel_pending')"];
        $args = [$uid, (int)$type['id']];
        if ($kind === 'bereavement') {
            if (empty($extra['deceased_date'])) return null;
            $where[] = "deceased_date = ?"; $args[] = $extra['deceased_date'];
            $where[] = "rel_grade_id = ?";  $args[] = (int)$extra['rel_grade_id'];
        } else {
            if (empty($extra['child_birthday'])) return null;
            $where[] = "child_birthday = ?"; $args[] = $extra['child_birthday'];
        }
        if ($excludeReqId) { $where[] = "id <> ?"; $args[] = $excludeReqId; }

        $used = 0.0; $rows = [];
        try {
            $st = $db->prepare("SELECT id, start_datetime, end_datetime, total_days, total_hours, status
                                FROM leave_request WHERE " . implode(' AND ', $where));
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) $used += eg_leave_rule_amount_in_unit($r, $unit);

        return ['cap' => $cap, 'used' => round($used, 2), 'remaining' => round(max(0, $cap - $used), 2),
                'unit' => $unit, 'unit_word' => eg_leave_rule_unit_word($unit),
                'measure' => eg_leave_rule_measure_label($unit),
                'cap_note' => $capNote, 'existing' => count($rows)];
    }
}

if (!function_exists('eg_leave_rule_check')) {
    /**
     * 送審／修改前的規則檢查。**這是唯一守門處，前端只是提早顯示同樣的結果。**
     *
     * @param array $amt eg_leave_calc_amount() 的結果（hours/days）
     * @param array $extra 申請單上填的規則欄位
     * @return array ['ok'=>bool, 'msg'=>string 擋下原因, 'warns'=>string[] 提醒但不擋, 'quota'=>array|null]
     */
    function eg_leave_rule_check(PDO $db, int $uid, array $type, string $start, string $end,
                                 array $amt, array $extra, ?int $excludeReqId = null): array {
        $kind = (string)($type['rule_kind'] ?? '');
        $ret = ['ok' => true, 'msg' => '', 'warns' => [], 'quota' => null];
        if ($kind === '') return $ret;

        $name = (string)$type['leave_name'];
        $sd = substr($start, 0, 10);
        $ed = substr($end, 0, 10);
        $today = date('Y-m-d');
        $bad = function (string $m) { return ['ok' => false, 'msg' => $m, 'warns' => [], 'quota' => null]; };

        if ($kind === 'bereavement') {
            $gid = (int)($extra['rel_grade_id'] ?? 0);
            $dd  = trim((string)($extra['deceased_date'] ?? ''));
            if ($gid <= 0) return $bad("請選擇「亡故親屬關係」——{$name}可請的天數依關係而定，不填無法判斷上限。");
            if ($dd === '')  return $bad("請填寫「死亡日期」——{$name}的請假期限與可請天數都從這天起算。");
            if (strtotime($dd) === false) return $bad('死亡日期格式不正確。');
            if ($dd > $today) return $bad("死亡日期（{$dd}）不可以是未來日期。");
            $g = eg_leave_rule_grade($db, $gid);
            if (!$g) return $bad('選到的親屬關係已不存在，請重新選擇。');

            if ($sd < $dd) return $bad("{$name}的開始日（{$sd}）不可早於死亡日期（{$dd}）。");
            $deadlineDays = ($type['rule_deadline_days'] === null) ? 0 : (int)$type['rule_deadline_days'];
            if ($deadlineDays > 0) {
                $deadline = date('Y-m-d', strtotime($dd . ' +' . $deadlineDays . ' day'));
                if ($ed > $deadline) {
                    return $bad("{$name}須於死亡日起 {$deadlineDays} 日內請畢（最後可請到 {$deadline}），"
                              . "您填的結束日是 {$ed}。逾期請洽人事處理。");
                }
            }
            $q = eg_leave_rule_quota($db, $uid, $type, $extra, $excludeReqId);
            if ($q) {
                $this_ = eg_leave_rule_amount_in_unit(
                    ['start_datetime' => $start, 'end_datetime' => $end,
                     'total_days' => $amt['days'], 'total_hours' => $amt['hours']], $q['unit']);
                if ($this_ > $q['remaining'] + 0.001) {
                    return $bad(sprintf('%s（%s）上限 %s %s，同一次治喪已請 %s %s，本次 %s %s，超過 %s %s。',
                        $name, $g['grade_name'],
                        eg_leave_rule_fmt($q['cap']), $q['unit_word'],
                        eg_leave_rule_fmt($q['used']), $q['unit_word'],
                        eg_leave_rule_fmt($this_), $q['unit_word'],
                        eg_leave_rule_fmt($this_ - $q['remaining']), $q['unit_word']));
                }
                $ret['quota'] = $q;
            }
            return $ret;
        }

        // ── 育嬰類（育嬰留停／育嬰假共用）──
        $cb = trim((string)($extra['child_birthday'] ?? ''));
        if ($cb === '') return $bad("請填寫「子女出生日期」——{$name}的可請期間與累計上限都以子女歸戶計算。");
        if (strtotime($cb) === false) return $bad('子女出生日期格式不正確。');
        if ($cb > $today) return $bad("子女出生日期（{$cb}）不可以是未來日期。");
        if ($sd < $cb) return $bad("{$name}的開始日（{$sd}）不可早於子女出生日（{$cb}）。");

        if ($type['rule_child_age_years'] !== null && (float)$type['rule_child_age_years'] > 0) {
            $yrs = (float)$type['rule_child_age_years'];
            // 「至該子女滿 N 歲止」→ 結束日不得晚於 N 歲生日當天
            $limitDate = date('Y-m-d', strtotime($cb . ' +' . (int)$yrs . ' year'));
            if ($ed > $limitDate) {
                return $bad(sprintf('%s須於子女滿 %s 歲前請畢（該子女 %s 生日是 %s，最後可請到當天），您填的結束日是 %s。',
                    $name, eg_leave_rule_fmt($yrs), eg_leave_rule_fmt($yrs) . ' 歲', $limitDate, $ed));
            }
        }

        if ($type['rule_min_days'] !== null && (float)$type['rule_min_days'] > 0) {
            $minD = (float)$type['rule_min_days'];
            $cal = eg_leave_rule_cal_days($start, $end);
            if ($cal < $minD - 0.001) {
                return $bad(sprintf('%s單次不得少於 %s 日（以曆日計，含假日），本次只有 %s 日。',
                    $name, eg_leave_rule_fmt($minD), eg_leave_rule_fmt($cal)));
            }
        }

        $q = eg_leave_rule_quota($db, $uid, $type, $extra, $excludeReqId);
        if ($q) {
            $this_ = eg_leave_rule_amount_in_unit(
                ['start_datetime' => $start, 'end_datetime' => $end,
                 'total_days' => $amt['days'], 'total_hours' => $amt['hours']], $q['unit']);
            if ($this_ > $q['remaining'] + 0.001) {
                return $bad(sprintf('%s每一子女上限 %s %s，此子女（%s 出生）已請 %s %s，本次 %s %s，超過 %s %s。',
                    $name, eg_leave_rule_fmt($q['cap']), $q['unit_word'], $cb,
                    eg_leave_rule_fmt($q['used']), $q['unit_word'],
                    eg_leave_rule_fmt($this_), $q['unit_word'],
                    eg_leave_rule_fmt($this_ - $q['remaining']), $q['unit_word']));
            }
            $ret['quota'] = $q;
        }

        // 多子女合併計算：只提醒不擋。法規要的是「同時撫育 2 人以上」才合併計算，
        // 是否同時撫育需要人的判斷（例如另一個孩子是否仍受撫育），系統不該替人事決定。
        try {
            $st = $db->prepare("SELECT DISTINCT child_birthday FROM leave_request
                                WHERE employee_id = ? AND child_birthday IS NOT NULL AND child_birthday <> ?
                                  AND status IN ('approved','pending','cancel_pending')
                                  AND leave_type_id IN (SELECT id FROM leave_type WHERE rule_kind = 'parental')"
                              . ($excludeReqId ? " AND id <> ?" : ""));
            $st->execute($excludeReqId ? [$uid, $cb, $excludeReqId] : [$uid, $cb]);
            $others = $st->fetchAll(PDO::FETCH_COLUMN);
            if ($others) {
                $ret['warns'][] = '您另有其他子女（' . implode('、', $others) . ' 出生）的育嬰請假紀錄。'
                    . '依法同時撫育 2 人以上者育嬰留停期間應合併計算、最長以最幼子女 2 年為限，'
                    . '請人事於簽核時確認是否需合併計算。';
            }
        } catch (Throwable $e) {}

        return $ret;
    }
}
