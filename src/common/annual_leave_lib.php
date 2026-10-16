<?php
/**
 * annual_leave_lib.php — 特休（年假）天數計算 共用庫
 *
 * 由 src/store/_employee_api.php 的 calculateProratedAnnualLeave() 抽出，
 * 讓「員工列表的特休天數」與「請假系統的特休額度」用同一套算法，避免兩邊數字不一致。
 *
 * 算法（曆年制，按比例併算，原樣保留）：
 *   年資級距 → 滿6個月3天 / 滿1年7天 / 滿2年10天 / 滿3~4年14天 / 滿5~9年15天 /
 *              滿10年16天，之後每年+1，上限30天
 *   週年日落在計算年度內時，前期天數與後期天數各自按日數比例併算
 *   進位：到職滿一年的年度無條件進位；其餘半天進位
 *
 * 兩個入口：
 *   eg_annual_leave_raw()         — 與原 calculateProratedAnnualLeave() 完全相同的結果（員工列表用）
 *   eg_annual_leave_entitlement() — raw 再加「到職未滿6個月＝0天」閘門（請假系統額度用）
 *     ※ 該閘門原本寫在 views/ADM/employee_management.php 前端，這裡收回後端統一。
 */

if (!function_exists('eg_annual_leave_raw')) {
    /**
     * 依到職日計算指定年度的特休天數（曆年制按比例）。
     *
     * @param string|null $hireDate 到職日 (Y-m-d)
     * @param int|null    $year     計算年度，null=今年
     * @return float 天數
     */
    function eg_annual_leave_raw(?string $hireDate, ?int $year = null): float {
        if (!$hireDate) {
            return 0;
        }

        $currentYear = $year ?? (int)date('Y');
        try {
            $hire_date_obj = new DateTime($hireDate);
        } catch (Throwable $e) {
            return 0;
        }
        $year_start_obj = new DateTime("$currentYear-01-01");
        $year_end_obj   = new DateTime("$currentYear-12-31");
        $days_in_year   = $year_start_obj->diff($year_end_obj)->days + 1;

        // 週年日
        $anniversary_date_this_year = new DateTime($hire_date_obj->format("$currentYear-m-d"));

        // 取得對應年資的特休天數
        $get_leave_days = function ($years, $months) {
            // 規則：滿10年16天，之後每年+1，上限30天
            if ($years >= 10) return min(30, 16 + ($years - 10));
            // 規則：滿5年為15天（5~9年皆為15天）
            if ($years >= 5) return 15;
            // 規則：滿3, 4年為14天
            if ($years >= 3) return 14;
            if ($years >= 2) return 10;
            if ($years >= 1) return 7;
            // 規則：僅在未滿一年但滿6個月時適用
            if ($years == 0 && $months >= 6) return 3;
            return 0;
        };

        // 1. 計算前期年資 (年初時的年資)
        $interval_at_year_start  = $hire_date_obj->diff($year_start_obj);
        $seniority_years_before  = $interval_at_year_start->y;
        $seniority_months_before = $interval_at_year_start->y * 12 + $interval_at_year_start->m;
        $leave_days_before       = $get_leave_days($seniority_years_before, $seniority_months_before);

        // --- 特殊情況：在計算年度內才滿6個月 ---
        $six_month_anniversary = (clone $hire_date_obj)->add(new DateInterval('P6M'));
        if ($seniority_months_before < 6 && $six_month_anniversary->format('Y') == $currentYear) {
            $leave_days = 0;

            // 1. 滿6個月的3天假，直接給予
            $leave_days += 3;

            // 2. 計算滿一年後的按比例天數
            $one_year_anniversary = (clone $hire_date_obj)->add(new DateInterval('P1Y'));
            if ($one_year_anniversary->format('Y') == $currentYear) {
                $days_after_one_year     = $one_year_anniversary->diff($year_end_obj)->days + 1;
                $pro_rated_after_one_year = (7 / $days_in_year) * $days_after_one_year;
                $leave_days += $pro_rated_after_one_year;
            }

            $total_leave_days = $leave_days;
        } else {
            // --- 正常情況：年初已滿6個月或更久 ---
            // 2. 計算後期年資 (週年日時的年資)
            $seniority_at_anniversary = $hire_date_obj->diff($anniversary_date_this_year)->y;
            $leave_days_after = $get_leave_days($seniority_at_anniversary, 12); // 週年日當天必滿12個月

            $total_leave_days = 0;

            if ($anniversary_date_this_year > $year_start_obj && $anniversary_date_this_year <= $year_end_obj) {
                // 週年日在今年
                $days_before_anniversary = $year_start_obj->diff($anniversary_date_this_year)->days;
                $days_after_anniversary  = $days_in_year - $days_before_anniversary;

                // 判斷前期的計算基數：滿6個月的3天特休，其基數為半年(182.5天)
                $proration_base_before = ($leave_days_before == 3) ? 182.5 : $days_in_year;
                // 後期的計算基數恆為一整年
                $proration_base_after = $days_in_year;

                $pro_rated_before = ($leave_days_before / $proration_base_before) * $days_before_anniversary;
                $pro_rated_after  = ($leave_days_after / $proration_base_after) * $days_after_anniversary;

                $total_leave_days = $pro_rated_before + $pro_rated_after;
            } else {
                // 週年日不在今年 (例如 2/29)，直接用年初年資計算整年
                $total_leave_days = $leave_days_before;
            }
        }

        // --- 根據年資套用不同的進位規則 ---

        // 判斷是否為 "到職滿一年的年度"
        $is_first_anniversary_year = ($seniority_years_before == 0 && $hire_date_obj->format('Y') < $currentYear);

        if ($is_first_anniversary_year) {
            // 規則B: 到職滿一年的特例，無條件進位 (e.g., 6.52 -> 7)
            return (float)ceil($total_leave_days);
        }

        // 規則A: 正常情況，半天進位 (e.g., 14.17 -> 14.5)
        $floor_val    = floor($total_leave_days);
        $decimal_part = $total_leave_days - $floor_val;

        if ($decimal_part > 0.5) {
            return (float)ceil($total_leave_days);
        } elseif ($decimal_part > 0) {
            return (float)($floor_val + 0.5);
        }
        return (float)$total_leave_days;
    }
}

if (!function_exists('eg_annual_leave_entitlement')) {
    /**
     * 請假系統用的特休額度：raw 天數 + 「到職未滿6個月＝0天」閘門。
     * 閘門只在計算「今年」時生效（判斷基準為 $asOf，預設今天）；查歷史年度時直接回 raw。
     *
     * @param string|null $hireDate 到職日 (Y-m-d)
     * @param int|null    $year     年度，null=今年
     * @param string|null $asOf     判斷基準日 (Y-m-d)，null=今天（測試用）
     */
    function eg_annual_leave_entitlement(?string $hireDate, ?int $year = null, ?string $asOf = null): float {
        if (!$hireDate) return 0;
        $year = $year ?? (int)date('Y');
        $days = eg_annual_leave_raw($hireDate, $year);
        if ($days <= 0) return 0;

        // 只有計算「當前年度」時才套用未滿6個月閘門；歷史/未來年度以該年度整年結果為準
        $todayStr = $asOf ?? date('Y-m-d');
        if ((int)date('Y', strtotime($todayStr)) !== $year) return $days;

        try {
            $sixMonth = (new DateTime($hireDate))->add(new DateInterval('P6M'));
            $today    = new DateTime($todayStr);
            $today->setTime(0, 0, 0);
            if ($today < $sixMonth) return 0;
        } catch (Throwable $e) { /* 日期異常時不擋，回原值 */ }

        return $days;
    }
}

// ============================== 復職年資（離職期間處理） ==============================
//
// 為什麼要有這一段（2026-10-16 使用者交辦）：
//   `eg_annual_leave_raw()` 只吃一個到職日，而「離職→復職」的人 `user.hire_date` 永遠是
//   第一次到職那天（員工管理頁刻意不改它——改掉的話第一段任職期間的舊單據會被全站的
//   asof 判定當成「當時還沒進公司」而整批查不到人）。
//   所以特休年資會把中間沒上班的那段也一起算進去。
//
// 做法：**不改那 160 行按比例併算的演算法**，只把「要拿哪一天當到職日」算出來再餵進去。
//   這樣三種模式共用同一套進位與併算規則，不會出現兩套算法對不起來。
//
// 三種模式（管理員在請假系統「管理者設定 → 特休年資」選，預設 none＝維持現狀）：
//   none         原樣用 user.hire_date（舊行為，導入本功能不會動到任何人現在的天數）
//   exclude_gap  到職日往後推「不在職天數」＝年資累計、中間離職的日子不算
//   restart      年資自最後一次復職日重新起算
//
// 期間的認定來源是 `user_status_history`（員工管理→異動紀錄），不是 `user.leave_date`——
// 後者只留得下「最新一次」離職日，回不去歷史。

if (!function_exists('eg_annual_leave_rehire_modes')) {
    /** 模式登記表（唯一實作，前後端共用，禁止在畫面或 API 另寫一份對照） */
    function eg_annual_leave_rehire_modes(): array {
        return [
            'none' => [
                'label' => '維持現狀：一律用到職日原值',
                'desc'  => '離職期間照算年資（本功能導入前的行為）。',
            ],
            'exclude_gap' => [
                'label' => '扣除離職期間後計算',
                'desc'  => '年資累計，但中間沒在職的日子不算；做法是把到職日往後推「不在職天數」。',
            ],
            'restart' => [
                'label' => '自最後一次復職日重新計算',
                'desc'  => '年資中斷重新起算，等同把復職日當成新的到職日。',
            ],
        ];
    }
}

if (!function_exists('eg_annual_leave_rehire_cfg')) {
    /**
     * 讀設定（system_settings）。
     *   leave_annual_rehire_mode    none | exclude_gap | restart（預設 none）
     *   leave_annual_rehire_unpaid  1＝留職停薪／育嬰留停也一併扣除（**只有 exclude_gap 模式吃它**，預設 0）
     *     ※ 刻意預設不扣：留停復職在勞基法上年資不中斷，要不要扣是公司政策，不是系統該替人決定的。
     *     ※ restart 模式一律只看「離職」，留停復職不會讓年資重新起算。
     */
    function eg_annual_leave_rehire_cfg(PDO $db, bool $flush = false): array {
        // 請求內快取：員工列表一次會算 50 多個人，不必每個人都回頭查設定表。
        // **存檔時一定要清掉**（eg_annual_leave_rehire_save 會呼叫 $flush），否則同一個 request
        // 內「存完再讀回來」會拿到舊值＝畫面顯示設定沒生效（dqa_excl_map 踩過同一個坑）。
        static $cache = null;
        if ($flush) { $cache = null; return ['mode' => 'none', 'include_unpaid' => false]; }
        if ($cache !== null) return $cache;

        $cfg = ['mode' => 'none', 'include_unpaid' => false];
        try {
            $st = $db->query("SELECT setting_key, setting_value FROM system_settings
                              WHERE setting_key IN ('leave_annual_rehire_mode','leave_annual_rehire_unpaid')");
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if ($k === 'leave_annual_rehire_mode') $cfg['mode'] = (string)$v;
                else $cfg['include_unpaid'] = ((string)$v === '1');
            }
        } catch (Throwable $e) { /* 設定表讀不到時走預設＝維持現狀，不擋流程 */ }
        if (!isset(eg_annual_leave_rehire_modes()[$cfg['mode']])) $cfg['mode'] = 'none';
        return $cfg;
    }
}

if (!function_exists('eg_annual_leave_rehire_save')) {
    /** 存設定（呼叫端負責驗管理員權限）。模式一律過登記表白名單，不合法就擋下。 */
    function eg_annual_leave_rehire_save(PDO $db, array $in, int $uid, string $uname = ''): void {
        $modes = eg_annual_leave_rehire_modes();
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                            VALUES (?,?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                                updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by), updated_at=NOW()");
        if (array_key_exists('mode', $in)) {
            $m = (string)$in['mode'];
            if (!isset($modes[$m])) throw new InvalidArgumentException('不支援的年資計算方式：' . $m);
            $st->execute(['leave_annual_rehire_mode', $m, $uid, $uname]);
        }
        if (array_key_exists('include_unpaid', $in)) {
            $st->execute(['leave_annual_rehire_unpaid', (!empty($in['include_unpaid']) ? '1' : '0'), $uid, $uname]);
        }
        eg_annual_leave_rehire_cfg($db, true);   // 清掉請求內快取，存完立刻讀回來才是新值
    }
}

if (!function_exists('eg_annual_leave_absence_map')) {
    /**
     * 每個人的「不在職期間」：[user_id => [['from','to','kind'], ...]]（kind: resign／unpaid）
     *
     * 來源 `user_status_history`：status 0=離職、1=在職(復職)、2=留職停薪、3=育嬰留停。
     * 兩個一定要處理的現實問題：
     *   ⑴ **同一次離職可能有重複列**（人工補登又撞到系統自動寫的那一筆，實測 user 111030101
     *      就有兩筆 status=0、start_date 完全相同）→ 故算出來的期間最後一律做**區間聯集**，
     *      重複的會被併成一段；用「狀態機逐列推進」的寫法會被重複列推成假的第二段離職。
     *   ⑵ **留停的期間在同一列就有 end_date**，但之後往往還會有一筆 status=1「恢復在職」，
     *      兩者都算一次就會重複扣 → 所以留停用它自己的 end_date 為準，沒填才退回下一個復職日。
     *
     * 期間是**半開的**：離職日當天仍算在職、復職日當天也算在職，所以中間不在職的天數
     * ＝兩個日期相差的天數 − 1（見 eg_annual_leave_absence_days()）；這與全站 asof
     * 在職判定（`hrf_employed_at_map()`：離職日／到職日當天都算在職）同一個口徑。
     *
     * @param array|null $userIds 只要這幾個人（null＝全部）
     */
    function eg_annual_leave_absence_map(PDO $db, ?array $userIds = null): array {
        static $all = null;
        if ($all === null) {
            $all = [];
            $rows = [];
            try {
                // 這張表一人一次狀態變更才一列（全庫目前個位數），直接全撈再分組最單純
                $rows = $db->query("SELECT id, user_id, status, start_date, end_date
                                    FROM user_status_history
                                    WHERE start_date IS NOT NULL
                                    ORDER BY user_id, start_date, id")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* 表不存在：視同沒有任何離職紀錄 */ }

            $byUser = [];
            foreach ($rows as $r) $byUser[(int)$r['user_id']][] = $r;

            foreach ($byUser as $uid => $list) {
                // 先收集所有「回來上班」的日期，供下面找期間的結束點
                $backDates = [];
                foreach ($list as $r) {
                    if ((int)$r['status'] === 1) $backDates[] = (string)$r['start_date'];
                }
                sort($backDates);

                $periods = [];
                foreach ($list as $r) {
                    $status = (int)$r['status'];
                    if ($status === 1) continue;                       // 復職列本身不是不在職期間
                    $from = (string)$r['start_date'];
                    if ($from === '') continue;
                    $end = ($status === 0) ? null : trim((string)($r['end_date'] ?? ''));
                    if ($end === '') $end = null;
                    if ($end === null) {
                        // 沒有自己的結束日（離職一律如此）→ 找第一個不早於起日的復職日
                        foreach ($backDates as $d) { if ($d >= $from) { $end = $d; break; } }
                    }
                    $periods[] = [
                        'from' => $from,
                        'to'   => $end,                                // null＝到現在還沒回來
                        'kind' => ($status === 0) ? 'resign' : 'unpaid',
                    ];
                }
                $all[$uid] = eg_annual_leave_absence_merge($periods);
            }
        }

        if ($userIds === null) return $all;
        $out = [];
        foreach ($userIds as $u) { $u = (int)$u; if (isset($all[$u])) $out[$u] = $all[$u]; }
        return $out;
    }
}

if (!function_exists('eg_annual_leave_absence_merge')) {
    /**
     * 區間聯集（重疊或相接的併成一段）。null 的迄日視為無限遠。
     * kind 以 resign 優先（同一段時間同時被登記成離職與留停時，離職才是正確的那個）。
     */
    function eg_annual_leave_absence_merge(array $periods): array {
        if (count($periods) <= 1) return array_values($periods);
        usort($periods, function ($a, $b) {
            if ($a['from'] !== $b['from']) return strcmp($a['from'], $b['from']);
            // 迄日 null（無限遠）排後面，合併時才會被當成吃掉前面那段
            if ($a['to'] === null) return 1;
            if ($b['to'] === null) return -1;
            return strcmp($a['to'], $b['to']);
        });
        $out = [];
        foreach ($periods as $p) {
            if (!$out) { $out[] = $p; continue; }
            $i = count($out) - 1;
            $lastTo = $out[$i]['to'];
            // 上一段已經開到無限遠，或這一段的起日還在上一段裡面 → 併進去
            if ($lastTo === null || $p['from'] <= $lastTo) {
                if ($p['to'] === null) $out[$i]['to'] = null;
                elseif ($lastTo !== null && $p['to'] > $lastTo) $out[$i]['to'] = $p['to'];
                if ($p['kind'] === 'resign') $out[$i]['kind'] = 'resign';
            } else {
                $out[] = $p;
            }
        }
        return $out;
    }
}

if (!function_exists('eg_annual_leave_absence_days')) {
    /**
     * 一段不在職期間實際沒上班幾天。
     * 離職日當天與復職日當天都算在職，所以是「相差天數 − 1」
     * （例：07-31 離職、08-11 復職 → 相差 11 天 → 不在職 08-01~08-10 共 10 天）。
     */
    function eg_annual_leave_absence_days(string $from, string $to): int {
        try {
            $a = new DateTime($from); $b = new DateTime($to);
            $a->setTime(0, 0, 0); $b->setTime(0, 0, 0);
            if ($b < $a) return 0;
            return max(0, (int)$a->diff($b)->days - 1);
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('eg_annual_leave_effective_hire')) {
    /**
     * 算出「這個年度要拿哪一天當到職日」。
     *
     * @param array $absences eg_annual_leave_absence_map() 回的其中一個人的期間清單
     * @param array $cfg      eg_annual_leave_rehire_cfg()
     * @param int|null $year  計算年度（null=今年）
     * @return array ['orig','hire','mode','gap_days','restart_from','adjusted','periods']
     *
     * 兩個刻意的判斷（不這樣做會算出看起來像壞掉的數字）：
     *   ⑴ **只看「年末之前」已經發生的期間**，迄日超過年末就夾到年末。
     *      不夾的話，今年才離職的人會讓去年的特休也跟著被扣，等於回頭改寫歷史年度的額度。
     *   ⑵ **restart 模式取「不晚於年末的最後一次復職日」**，沒有就退回原到職日。
     *      直接拿最新復職日去算歷史年度會變成「當年還沒到職」而回 0 天，那是錯的——
     *      2026 才復職的人，2025 年確實是在職的。
     */
    function eg_annual_leave_effective_hire(?string $hireDate, array $absences, array $cfg, ?int $year = null): array {
        $year = $year ?? (int)date('Y');
        $out = [
            'orig' => $hireDate, 'hire' => $hireDate, 'mode' => (string)($cfg['mode'] ?? 'none'),
            'gap_days' => 0, 'restart_from' => null, 'adjusted' => false, 'periods' => [],
        ];
        if (!$hireDate || $out['mode'] === 'none' || !$absences) return $out;
        $cap = sprintf('%04d-12-31', $year);

        $use = [];
        foreach ($absences as $p) {
            $kind = (string)$p['kind'];
            // restart 只認「離職」：留停復職在勞基法上年資不中斷，不該讓年資重新起算
            if ($out['mode'] === 'restart' && $kind !== 'resign') continue;
            // exclude_gap 要不要連留停一起扣，由管理員勾選決定
            if ($out['mode'] === 'exclude_gap' && $kind === 'unpaid' && empty($cfg['include_unpaid'])) continue;
            $from = (string)$p['from'];
            if ($from === '' || $from > $cap) continue;      // 整段落在年末之後：這個年度不受影響
            if ($from < $hireDate) continue;                 // 早於到職日的紀錄不合理（資料有誤），略過
            $to = $p['to'];
            $effTo = ($to === null || $to > $cap) ? $cap : (string)$to;
            $use[] = ['from' => $from, 'to' => $to, 'eff_to' => $effTo, 'kind' => $kind,
                      'open' => ($to === null), 'days' => eg_annual_leave_absence_days($from, $effTo)];
        }
        if (!$use) return $out;
        $out['periods'] = $use;

        if ($out['mode'] === 'restart') {
            $last = null;
            foreach ($use as $p) {
                if ($p['to'] === null) continue;             // 還沒回來，沒有復職日可用
                if ((string)$p['to'] > $cap) continue;       // 復職日落在年末之後，這個年度還不算復職
                if ($last === null || (string)$p['to'] > $last) $last = (string)$p['to'];
            }
            if ($last !== null && $last > $hireDate) {
                $out['hire'] = $last; $out['restart_from'] = $last; $out['adjusted'] = true;
            }
            return $out;
        }

        $days = 0;
        foreach ($use as $p) $days += (int)$p['days'];
        if ($days > 0) {
            try {
                $out['hire'] = (new DateTime($hireDate))->add(new DateInterval('P' . $days . 'D'))->format('Y-m-d');
                $out['gap_days'] = $days;
                $out['adjusted'] = true;
            } catch (Throwable $e) { /* 日期異常：維持原到職日 */ }
        }
        return $out;
    }
}

if (!function_exists('eg_annual_leave_hire_note')) {
    /**
     * 把 eg_annual_leave_effective_hire() 的結果講成一句人看得懂的話（唯一實作：畫面與 API 共用）。
     * 沒有調整就回空字串——**調整過一定要顯示出來**，否則使用者只會看到一個跟到職日對不起來的
     * 天數，看起來就像系統算錯。
     */
    function eg_annual_leave_hire_note(array $eff): string {
        if (empty($eff['adjusted'])) return '';
        require_once __DIR__ . '/date_fmt_lib.php';
        $orig = eg_fmt_date($eff['orig']);
        if ($eff['mode'] === 'restart') {
            return '年資自最後一次復職日 ' . eg_fmt_date($eff['restart_from']) . ' 重新起算（原到職日 ' . $orig . '）';
        }
        $kinds = [];
        foreach (($eff['periods'] ?? []) as $p) { if ((int)$p['days'] > 0) $kinds[$p['kind']] = true; }
        $what = isset($kinds['unpaid']) ? (isset($kinds['resign']) ? '離職與留停' : '留停') : '離職';
        return '已扣除' . $what . '期間共 ' . (int)$eff['gap_days'] . ' 天，年資以 '
             . eg_fmt_date($eff['hire']) . ' 起算（原到職日 ' . $orig . '）';
    }
}

if (!function_exists('eg_annual_leave_explain')) {
    /**
     * 單一人員的年資調整結果（含說明文字），給畫面顯示用。
     * @param string|null $hireDate 傳 null 時自己去查 user.hire_date
     */
    function eg_annual_leave_explain(PDO $db, int $userId, ?string $hireDate = null, ?int $year = null): array {
        if ($hireDate === null) {
            try {
                $st = $db->prepare("SELECT hire_date FROM user WHERE id = ? LIMIT 1");
                $st->execute([$userId]);
                $hireDate = $st->fetchColumn() ?: null;
            } catch (Throwable $e) { $hireDate = null; }
        }
        $cfg = eg_annual_leave_rehire_cfg($db);
        $map = eg_annual_leave_absence_map($db);
        $eff = eg_annual_leave_effective_hire($hireDate, $map[$userId] ?? [], $cfg, $year);
        $eff['note'] = eg_annual_leave_hire_note($eff);
        return $eff;
    }
}

if (!function_exists('eg_annual_leave_raw_user')) {
    /** eg_annual_leave_raw() 的「吃復職年資設定」版本（員工列表的特休天數用）。 */
    function eg_annual_leave_raw_user(PDO $db, int $userId, ?string $hireDate = null, ?int $year = null): float {
        $eff = eg_annual_leave_explain($db, $userId, $hireDate, $year);
        return eg_annual_leave_raw($eff['hire'], $year);
    }
}

if (!function_exists('eg_annual_leave_entitlement_user')) {
    /** eg_annual_leave_entitlement() 的「吃復職年資設定」版本（請假系統額度用）。 */
    function eg_annual_leave_entitlement_user(PDO $db, int $userId, ?string $hireDate = null,
                                              ?int $year = null, ?string $asOf = null): float {
        $eff = eg_annual_leave_explain($db, $userId, $hireDate, $year);
        return eg_annual_leave_entitlement($eff['hire'], $year, $asOf);
    }
}
