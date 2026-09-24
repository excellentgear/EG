<?php
/**
 * src/common/bom_process_date_lib.php
 * ─────────────────────────────────────────────────────────────────────────
 * 管理員修正製程「發包日／回廠日」的唯一核心邏輯（鐵律4）。
 *
 * 背景：views/GM/project_mgmt.php（關聯資料→製程）早就有管理員可直接改
 * bom_ing 發包日／回廠日的功能（project_lib.php 的 prj_bom_dates_admin_update()）。
 * 2026-09-24 使用者交辦：views/pm/OreadyReply_completed_query.php 也要有同樣能力，
 * 且要補三條規則——這三條規則兩邊都要吃到同一套，不可以各自演進出兩份判斷：
 *
 *   ①【硬擋】回廠日不可晚於「這一關已經開立的製程移轉憑單」建立日（依憑單單號回推，
 *      J- 開頭單號格式與 billing_month_lib.php 的 eg_bm_date_from_no() 共用同一套解析，
 *      不另外寫第二份）。移轉憑單一旦開立，代表那筆帳款月份已經認定，回廠日retroactively
 *      改到憑單日期之後會讓帳款月份對不起來，所以這條不可覆寫。
 *   ②【只提醒】回廠日不可晚於品管／包裝檢驗日期——正常時序是先回廠才驗，但重工／
 *      重新發包會讓這個順序倒過來，所以只提醒不擋；呼叫端要在使用者確認後帶
 *      $ackWarning=true 重送一次才會真的寫入。
 *   ③【自動判定】存檔後這一關的 processing_state 依「兩個日期有沒有填」與「有沒有
 *      檢驗紀錄」推導：都空→N（未發包）；只有發包日→ing（加工中）；兩個都填時，
 *      這一關已經有任何品管／包裝檢驗紀錄→E（已移轉，回填舊資料視同已完成）；
 *      否則→Q（QC待驗，等品管來驗）。
 *
 * 呼叫端各自負責自己的鏡射欄位（例如 project_process）與畫面文字，這裡只管
 * bom_ing 本身與這三條共同規則。
 */

if (!function_exists('bomp_transfer_voucher_ceiling')) {
    /**
     * 這個 bom+bom_sn 已經開立的移轉憑單（bom_ing_transfer_log）裡，單號解析得出來的
     * 最早那個日期；沒有任何解析得出來的憑單日期則回 null。
     * 取「最早」而不是「最新」：只要有任何一張憑單已經認定了帳款月份，回廠日就不可以
     * 被改到那張憑單之後（保守方向，避免任何一張的月份被打亂）。
     */
    function bomp_transfer_voucher_ceiling(PDO $db, string $bom, int $bomSn): ?string
    {
        require_once __DIR__ . '/billing_month_lib.php';
        $st = $db->prepare("SELECT transfer_no FROM bom_ing_transfer_log WHERE bom=? AND bom_sn=? AND transfer_no IS NOT NULL");
        $st->execute([$bom, $bomSn]);
        $min = null;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
            $d = eg_bm_date_from_no((string)$no);
            if ($d !== null && ($min === null || $d < $min)) $min = $d;
        }
        return $min;
    }
}

if (!function_exists('bomp_qc_check_dates')) {
    /** 這一關（bom_ing_fid）目前記錄到的品管／包裝檢驗日期，逐來源各取最早一筆：['來源說明'=>'Y-m-d', ...]。 */
    function bomp_qc_check_dates(PDO $db, int $fid): array
    {
        $dates = [];
        try {
            $s = $db->prepare("SELECT MIN(DATE(QC_check_date)) FROM qc_check WHERE bom_ing_fid_ref=? AND QC_check_date IS NOT NULL");
            $s->execute([$fid]);
            $v = $s->fetchColumn();
            if ($v) $dates['QC 報工紀錄'] = $v;
        } catch (Throwable $e) { /* 表不存在時略過 */ }
        try {
            $s = $db->prepare("SELECT MIN(check_date) FROM qc_check_form WHERE bom_ing_fid=? AND check_date IS NOT NULL AND status<>'DRAFT'");
            $s->execute([$fid]);
            $v = $s->fetchColumn();
            if ($v) $dates['線上檢驗單'] = $v;
        } catch (Throwable $e) { /* 表不存在時略過 */ }
        try {
            $s = $db->prepare("SELECT MIN(inspection_date) FROM qc_packing_inspection WHERE bom_ing_fid=?");
            $s->execute([$fid]);
            $v = $s->fetchColumn();
            if ($v) $dates['包裝檢驗紀錄'] = $v;
        } catch (Throwable $e) { /* 表不存在時略過 */ }
        return $dates;
    }
}

if (!function_exists('bomp_has_any_inspection')) {
    /** 這一關有沒有任何品管／包裝檢驗紀錄（不看日期，只看有沒有存在） */
    function bomp_has_any_inspection(PDO $db, int $fid): bool
    {
        foreach (['qc_check' => 'bom_ing_fid_ref', 'qc_check_form' => 'bom_ing_fid', 'qc_packing_inspection' => 'bom_ing_fid'] as $t => $c) {
            try {
                $s = $db->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c`=?");
                $s->execute([$fid]);
                if ((int)$s->fetchColumn() > 0) return true;
            } catch (Throwable $e) { /* 表不存在時略過 */ }
        }
        return false;
    }
}

if (!function_exists('bomp_derive_state')) {
    /** 規則③：依日期填寫情形＋有無檢驗紀錄推導 processing_state。 */
    function bomp_derive_state(PDO $db, int $fid, ?string $out, ?string $ret): string
    {
        if ($out && $ret) return bomp_has_any_inspection($db, $fid) ? 'E' : 'Q';
        if ($out && !$ret) return 'ing';
        return 'N';
    }
}

if (!function_exists('bomp_admin_set_dates')) {
    /**
     * 管理員修正發包日／回廠日的唯一核心邏輯（不含呼叫端自己的鏡射欄位）。
     *
     * 回傳：
     *   成功 → ['ok'=>true, 'row'=>['bom_ing_fid','outsource_date','return_date','processing_state','prev_state']]
     *   規則①擋下 → ['ok'=>false, 'blocked'=>'說明文字']（不可覆寫）
     *   規則②提醒 → ['ok'=>false, 'warning'=>'說明文字']（$ackWarning=true 時略過，直接寫入）
     *
     * 拋例外的情況：查無這一關 / 日期格式不正確。
     */
    function bomp_admin_set_dates(PDO $db, int $fid, $outsourceDate, $returnDate, array $user, bool $ackWarning = false): array
    {
        $norm = static function ($v) {
            $v = trim((string)($v ?? ''));
            if ($v === '') return null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new RuntimeException('日期格式不正確');
            return $v;
        };
        $out = $norm($outsourceDate);
        $ret = $norm($returnDate);

        $st = $db->prepare("SELECT bom_ing_fid, bom, bom_sn, outsource_date, return_date, processing_state
                             FROM bom_ing WHERE bom_ing_fid=?");
        $st->execute([$fid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('查無此製程列 (bom_ing_fid=' . $fid . ')');

        // ①硬擋：不可晚於已開立的移轉憑單日期
        if ($ret !== null) {
            $ceil = bomp_transfer_voucher_ceiling($db, (string)$row['bom'], (int)$row['bom_sn']);
            if ($ceil !== null && $ret > $ceil) {
                return ['ok' => false, 'blocked' =>
                    "這一關已經開立過製程移轉憑單（依單號回推日期為 {$ceil}），回廠日不可晚於這個日期，避免帳款月份對不起來。"];
            }
        }
        // ②只提醒：不可晚於品管／包裝檢驗日期（重工/重新發包屬正常例外，故只提醒不擋）
        if ($ret !== null && !$ackWarning) {
            foreach (bomp_qc_check_dates($db, $fid) as $label => $d) {
                if ($ret > $d) {
                    return ['ok' => false, 'warning' =>
                        "回廠日（{$ret}）晚於{$label}（{$d}）——一般是先回廠才驗得到，若這一關是重工／重新發包後再驗，屬正常情況，可確認後略過此提醒直接儲存。"];
                }
            }
        }

        $newState = bomp_derive_state($db, $fid, $out, $ret);
        $prevState = (string)$row['processing_state'];

        $db->beginTransaction();
        try {
            $u1 = $db->prepare("UPDATE bom_ing SET outsource_date=?, return_date=?, processing_state=?, Modified_At=NOW(), Modified_By=?
                                 WHERE bom_ing_fid=?");
            $u1->execute([$out, $ret, $newState, (string)($user['id'] ?? ''), $fid]);
            $note = '管理員修正發包日/回廠日：'
                  . ($row['outsource_date'] ?: '(空)') . '→' . ($out ?: '(空)') . '；'
                  . ($row['return_date'] ?: '(空)') . '→' . ($ret ?: '(空)') . '；狀態 ' . $prevState . '→' . $newState;
            $db->prepare("INSERT INTO bom_ing_event (bom_ing_fid, event_type, event_note, Created_By) VALUES (?,?,?,?)")
               ->execute([$fid, 'admin_set_dates', mb_substr($note, 0, 200), (string)($user['id'] ?? 'system')]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }

        return ['ok' => true, 'row' => [
            'bom_ing_fid'      => $fid,
            'outsource_date'   => $out,
            'return_date'      => $ret,
            'processing_state' => $newState,
            'prev_state'       => $prevState,
        ]];
    }
}
