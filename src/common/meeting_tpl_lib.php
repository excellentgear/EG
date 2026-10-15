<?php
/**
 * 會議範本（2026-10-15 使用者交辦：管理審查會議要能預先設好制式內容與需填入的項目）
 *
 * 範本 meeting_tpl：主題／地點／時間＋會議通知單的「會議要項」文字與應出席單位。
 * 範本項目 meeting_tpl_item：套用後會長成會議記錄裡的項目（kind＝directive/general/announce），
 *   content 是制式文字，可含「【待填：提示】」標記＝需要記錄人填入的內容（送出前一律擋下沒填的）；
 *   data_src 指定一種「系統資料」，套用當下依該會議日期算出文字附在內容後面（仍可修改）。
 * 系統資料來源一律登記在 meeting_tpl_sources()，前端清單與後端計算都從這裡取，不各寫一份（鐵律4）。
 */

if (!defined('MEETING_TPL_BLANK_RE')) {
    // 沒填的待填標記：【待填：xxx】（全形括號）
    define('MEETING_TPL_BLANK_RE', '/【待填[：:][^】]*】/u');
}

function meeting_tpl_ensure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_tpl (
        tpl_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL COMMENT '範本名稱',
        subject VARCHAR(100) NOT NULL COMMENT '帶入的會議主題',
        location VARCHAR(100) NULL,
        start_time VARCHAR(5) NULL,
        end_time VARCHAR(5) NULL,
        notice_items TEXT NULL COMMENT '會議通知單的會議要項文字',
        notice_depts VARCHAR(300) NULL COMMENT '應出席單位 department.id 逗號分隔',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議範本'");
    $db->exec("CREATE TABLE IF NOT EXISTS meeting_tpl_item (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tpl_id INT NOT NULL,
        kind VARCHAR(15) NOT NULL DEFAULT 'general' COMMENT 'directive/general/announce',
        sort_order INT NOT NULL DEFAULT 0,
        content TEXT NOT NULL,
        owner_depts VARCHAR(200) NULL COMMENT '預設負責部門 department.id 逗號分隔',
        remark VARCHAR(200) NULL,
        data_src VARCHAR(30) NULL COMMENT '系統資料來源代碼，見 meeting_tpl_sources()',
        KEY idx_tpl (tpl_id)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='會議範本項目'");
}

/** 可帶入的系統資料來源：代碼 => 顯示名稱（唯一登記處） */
function meeting_tpl_sources(): array {
    return [
        'kpi' => '關鍵績效指標（KPI）達成狀況',
        'car' => '異常矯正處理單（矯正單）統計',
        'ia'  => '內部稽核不符合事項統計',
        'qa'  => '品質異常處理單統計',
    ];
}

/** 依代碼與會議日期算出一段文字（失敗時回說明，不丟例外，免得整個套用失敗） */
function meeting_tpl_source_text(PDO $db, string $src, string $meetingDate): string {
    $ts = strtotime($meetingDate) ?: time();
    $y = (int)date('Y', $ts); $mo = (int)date('n', $ts);
    $from = sprintf('%04d-01-01', $y); $to = date('Y-m-d', $ts);
    try {
        switch ($src) {
            case 'kpi': return meeting_tpl_text_kpi($db, $y, $mo);
            case 'car':
                $r = $db->prepare("SELECT status, COUNT(*) n FROM car_order WHERE fill_date BETWEEN ? AND ? GROUP BY status");
                $r->execute([$from, $to]);
                $m = $r->fetchAll(PDO::FETCH_KEY_PAIR);
                $tot = array_sum($m); $cl = (int)($m['closed'] ?? 0);
                return "【系統資料】{$y}年度截至{$to}：矯正單共 {$tot} 件，已結案 {$cl} 件，未結案 " . ($tot - $cl) . ' 件。';
            case 'ia':
                $r = $db->prepare("SELECT stage, COUNT(*) n FROM ia_nc WHERE is_deleted=0 AND audit_date BETWEEN ? AND ? GROUP BY stage");
                $r->execute([$from, $to]);
                $m = $r->fetchAll(PDO::FETCH_KEY_PAIR);
                $tot = array_sum($m); $cl = (int)($m['closed'] ?? 0);
                return "【系統資料】{$y}年度截至{$to}：內部稽核不符合事項共 {$tot} 件，已結案 {$cl} 件，未結案 " . ($tot - $cl) . ' 件。';
            case 'qa':
                $r = $db->prepare("SELECT COUNT(*) n, SUM(is_closed=1) c FROM qa_abnormal_order WHERE deleted_at IS NULL AND occurrence_date BETWEEN ? AND ?");
                $r->execute([$from, $to]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                $tot = (int)($row['n'] ?? 0); $cl = (int)($row['c'] ?? 0);
                return "【系統資料】{$y}年度截至{$to}：品質異常處理單共 {$tot} 件，已結案 {$cl} 件，未結案 " . ($tot - $cl) . ' 件。';
        }
    } catch (Throwable $e) {
        return '【系統資料】（' . (meeting_tpl_sources()[$src] ?? $src) . '取得失敗，請手動填入）';
    }
    return '';
}

function meeting_tpl_text_kpi(PDO $db, int $y, int $mo): string {
    include_once __DIR__ . '/kpi_as_lib.php';
    $st = $db->prepare("SELECT i.indicator_id, i.name, y.target_direction, y.target_value, y.target_unit, y.target_text
                          FROM kpi_as_indicator i JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                         WHERE i.is_active=1 AND y.is_active=1 ORDER BY i.sort_order, i.indicator_id");
    $st->execute([$y]);
    $inds = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$inds) return "【系統資料】{$y}年度尚未設定 KPI 指標。";
    $mq = $db->prepare("SELECT * FROM kpi_as_monthly_value WHERE indicator_id=? AND year=? AND month<=? ORDER BY month DESC");
    $lines = []; $bad = 0;
    foreach ($inds as $iy) {
        $mq->execute([(int)$iy['indicator_id'], $y, $mo]);
        $val = null; $mm = null;
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $mv) {
            $v = kpi_as_display_value($mv);
            if ($v !== null) { $val = $v; $mm = (int)$mv['month']; break; }
        }
        $dir = ['lte'=>'≤', 'yes'=>'', 'gte'=>'≥'][$iy['target_direction']] ?? '≥';
        $unit = (string)($iy['target_unit'] ?? '');
        $tg = $iy['target_value'] !== null ? ($dir . (float)$iy['target_value'] . $unit) : (string)($iy['target_text'] ?? '');
        if ($val === null) { $lines[] = "・{$iy['name']}：尚無數據（目標 {$tg}）"; continue; }
        $below = kpi_as_below_target($val, $iy);
        if ($below) $bad++;
        $lines[] = "・{$iy['name']}：{$mm}月 " . (float)$val . $unit . "（目標 {$tg}）" . ($below ? '【未達標】' : '達標');
    }
    return "【系統資料】{$y}年度 KPI 截至 {$mo} 月（未達標 {$bad} 項）：\n" . implode("\n", $lines);
}

function meeting_tpl_list(PDO $db, bool $withItems = true): array {
    meeting_tpl_ensure($db);
    $tpls = $db->query("SELECT * FROM meeting_tpl WHERE is_active=1 ORDER BY sort_order, tpl_id")->fetchAll(PDO::FETCH_ASSOC);
    if ($withItems) {
        $iq = $db->prepare("SELECT kind, content, owner_depts, remark, data_src FROM meeting_tpl_item WHERE tpl_id=? ORDER BY sort_order, id");
        foreach ($tpls as &$t) { $iq->execute([(int)$t['tpl_id']]); $t['items'] = $iq->fetchAll(PDO::FETCH_ASSOC); }
        unset($t);
    }
    return $tpls;
}

/** 內容裡是否還有沒填的【待填：…】 */
function meeting_tpl_has_blank(string $content): bool {
    return (bool)preg_match(MEETING_TPL_BLANK_RE, $content);
}
