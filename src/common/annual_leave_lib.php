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
