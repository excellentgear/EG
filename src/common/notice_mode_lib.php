<?php
/**
 * 公告 / 通知「各對象通知方式」的共用判定（唯一實作，禁止各頁自己再寫一份）
 *
 * live_event_target.mode 目前有四種：
 *   autoread  開啟通知自動認定已閱（點開就算已閱，自動從鈴鐺移除）
 *   read      已閱（要按「確認已閱」）
 *   sign      回簽
 *   reply     回覆 + 回簽
 *
 * 一個人可能同時符合多個對象（例：全體＋自己部門＋本人各一列），此時一律「取最高義務」：
 *   reply > sign > read > autoread
 * 也就是說 autoread 是最弱的一種：只要同一則裡另有一列把這個人歸在 read（要自己按），
 * 就以 read 為準（嚴格的贏），不可以因為某一列設了 autoread 就自動幫他標已閱。
 *
 * 注意：對外的「義務等級」數字（need_mode）維持既有的 read=1 / sign=2 / reply=3，
 * autoread 也算 1 —— 置頂欄鈴鐺、手機頁多處以 `need_mode <= 1` 判斷「純資訊型通知」，
 * 若把 read 往上推成 2 會讓那些既有判斷全部失效。
 */

if (!function_exists('eg_notice_mode_rank')) {
    /** 義務等級（對外相容值）：autoread/read=1、sign=2、reply=3 */
    function eg_notice_mode_rank(?string $mode): int
    {
        switch ($mode) {
            case 'reply': return 3;
            case 'sign':  return 2;
            default:      return 1; // read / autoread / 其他舊值
        }
    }
}

if (!function_exists('eg_notice_mode_pick')) {
    /**
     * 從「這個人符合的所有對象列的 mode」挑出實際生效的通知方式。
     * @param array $modes 例 ['read','autoread'] → 回 'read'（嚴格的贏）
     * @return string autoread|read|sign|reply
     */
    function eg_notice_mode_pick(array $modes): string
    {
        $top = 0; $hasPlainRead = false; $hasAuto = false;
        foreach ($modes as $m) {
            $r = eg_notice_mode_rank($m);
            if ($r > $top) $top = $r;
            if ($r === 1) { if ($m === 'autoread') $hasAuto = true; else $hasPlainRead = true; }
        }
        if ($top >= 3) return 'reply';
        if ($top === 2) return 'sign';
        // 等級 1：只有在「完全沒有任何一列要求自己按已閱」時才算自動已閱
        return ($hasAuto && !$hasPlainRead) ? 'autoread' : 'read';
    }
}

if (!function_exists('eg_notice_mode_valid')) {
    /** 存檔用白名單：不在清單內一律退回 read */
    function eg_notice_mode_valid(?string $mode): string
    {
        return in_array($mode, ['autoread', 'read', 'sign', 'reply'], true) ? $mode : 'read';
    }
}

if (!function_exists('eg_notice_mode_label')) {
    /** 顯示名稱（畫面/匯出共用，避免各頁各寫一份中文字串） */
    function eg_notice_mode_label(?string $mode): string
    {
        switch ($mode) {
            case 'autoread': return '開啟自動已閱';
            case 'sign':     return '回簽';
            case 'reply':    return '回覆 + 回簽';
            default:         return '已閱';
        }
    }
}
