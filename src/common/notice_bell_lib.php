<?php
/**
 * src/common/notice_bell_lib.php — 置頂列「分類鈴鐺」設定（唯一實作）
 *
 * 主鈴鐺（公告／通知）永遠存在、永遠收全部通知，不受這裡設定影響；
 * 這裡只決定「主鈴鐺左側要多出哪幾顆分類鈴鐺」：管理員可新增／刪除／改名／換圖示／排序，
 * 每顆分類鈴鐺依「來源（通知的 ref_type）」與「類型（待辦＝要回覆回簽／通知＝僅閱讀）」從主鈴鐺的未讀清單篩出對應通知。
 *
 * 只影響顯示：分類鈴鐺是由瀏覽器端從主鈴鐺清單複製出來的檢視，點開、已讀、回覆、回簽全部沿用主鈴鐺原本的流程，
 * 後端不新增任何通知查詢，也不改變任何通知的對象或狀態。
 *
 * 來源比對規則（存在 src_json，字串陣列）：
 *   'QA*'      ＝ref_type 以 QA 開頭（含 QAB_L1、QA_DECIDE…）
 *   'CAR'      ＝ref_type 完全等於 CAR
 *   '(none)'   ＝沒有 ref_type 的一般公告／通知
 */

if (!function_exists('eg_nbell_ensure_schema')) {

    /** 建表（只在設定頁呼叫；全站每頁載入的側欄不呼叫，避免每次請求都下 DDL） */
    function eg_nbell_ensure_schema(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS `notice_bell_cat` (
            `cat_id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(30) NOT NULL,
            `icon` VARCHAR(40) NOT NULL DEFAULT 'fa-bell-o',
            `kind` VARCHAR(8) NOT NULL DEFAULT 'all' COMMENT 'all=全部 todo=待辦(需回覆/回簽) info=通知(僅閱讀)',
            `src_json` TEXT NULL COMMENT '來源比對規則(字串陣列)',
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL
        ) DEFAULT CHARSET=utf8mb4 COMMENT='置頂列分類鈴鐺設定（只影響顯示）'");
    }

    /** 管理員可挑的圖示（Font Awesome 4） */
    function eg_nbell_icons(): array
    {
        return ['fa-bell-o', 'fa-exclamation-triangle', 'fa-cogs', 'fa-cubes', 'fa-wrench', 'fa-clipboard', 'fa-file-text-o',
                'fa-truck', 'fa-flag', 'fa-star', 'fa-bolt', 'fa-bullhorn', 'fa-calendar', 'fa-comments-o', 'fa-gavel',
                'fa-industry', 'fa-tags', 'fa-users', 'fa-pencil-square-o', 'fa-check-square-o', 'fa-inbox', 'fa-shopping-cart',
                'fa-search', 'fa-balance-scale', 'fa-flask', 'fa-heart', 'fa-bookmark', 'fa-cog', 'fa-envelope-o', 'fa-thumb-tack'];
    }

    /** 來源群組（以 ref_type 前綴歸類成「哪一頁／哪個模組」）：[比對規則 => 顯示名稱] */
    function eg_nbell_source_groups(): array
    {
        return [
            'QA*'               => '品質異常處理單（待決策、裁決結果、補登廠商…）',
            'CAR*'              => '異常矯正處理單',
            'QUOTATION*'        => '報價單（核准、補件）',
            'STOCK_REQ*'        => '領庫（領料）需求單',
            'MEETING*'          => '會議紀錄',
            'CON_REVIEW*'       => '合約審查',
            'ENG_CHANGE*'       => '工程變更申請單',
            'DWG*'              => '圖面變更紀錄',
            'FSD*'              => '表單簽核',
            'RVF*'              => '審核表單',
            'DOC_APPLY*'        => '文件制修申請單',
            'IA_*'              => '內部稽核（不符合通知單）',
            'ROSTER*'           => '輪值排班',
            'TRAINING*'         => '教育訓練',
            'VENDOR_AUDIT*'     => '供應商稽核',
            'COMM_*'            => '溝通管理',
            'AS_*'              => 'AS 文件（負責人、排程提醒）',
            'PURCHASE*'         => '申請採購',
            'BUSINESS_TRIP*'    => '公出單',
            'NOTICE_APPROVAL*'  => '公告簽核',
            '(none)'            => '一般公告／通知（沒有來源頁面的）',
        ];
    }

    /** 正規化一組來源規則：只留字串、去重、限長 */
    function eg_nbell_clean_src($src): array
    {
        $out = [];
        foreach ((array)$src as $s) {
            $s = trim((string)$s);
            if ($s === '' || mb_strlen($s, 'UTF-8') > 60) continue;
            if ($s !== '(none)' && !preg_match('/^[A-Za-z0-9_]+\*?$/', $s)) continue;
            $out[$s] = $s;
        }
        return array_values($out);
    }

    /**
     * 側欄用：啟用中的分類鈴鐺（依 sort_order）。表不存在或任何錯誤一律回空陣列＝不顯示任何分類鈴鐺，
     * 不影響主鈴鐺與頁面載入。
     * @return array [['id','name','icon','kind','src'=>[...]], ...]
     */
    function eg_nbell_active(PDO $db): array
    {
        try {
            $rows = $db->query("SELECT cat_id, name, icon, kind, src_json FROM notice_bell_cat WHERE is_active=1 ORDER BY sort_order, cat_id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        $icons = eg_nbell_icons();
        $out = [];
        foreach ($rows as $r) {
            $src = json_decode((string)$r['src_json'], true);
            $out[] = [
                'id'   => (int)$r['cat_id'],
                'name' => (string)$r['name'],
                'icon' => in_array($r['icon'], $icons, true) ? $r['icon'] : 'fa-bell-o',
                'kind' => in_array($r['kind'], ['all', 'todo', 'info'], true) ? $r['kind'] : 'all',
                'src'  => eg_nbell_clean_src(is_array($src) ? $src : []),
            ];
        }
        return $out;
    }
}
