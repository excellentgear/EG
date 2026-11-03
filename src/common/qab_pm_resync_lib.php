<?php
/**
 * 報工NG自動開立的品質異常單：職務異動（含補登）後自動重算「開單人」與「責任單位的部門／人員」
 * （2026-11-03 使用者交辦：補登了人員異動紀錄後，報工自動開立的單要自動修正，結案與否都要）。
 *
 * 為什麼需要：自動開單當下是依「當時的組織資料」解析現場主管與報工人員所屬部門；事後才補登
 * 過去的職務異動（ai-rules/22：一律以業務日期當時為準），原本解析的結果就不對了。
 *
 * 規則（與 qab_auto_open_from_bom_ing() 開單當下完全同一套，不另寫一份判定）：
 *  - 開單人＝eg_unit_supervisor_available(報工人員, 觸發報工的報工日)；
 *  - 責任單位的部門／人員（只有責任廠商是廠內加工廠商時）＝各筆報工人員與其報工日當時所屬部門；
 *    已有該報工人員的列但部門不同→改成正確部門；整張單完全沒有責任部門列→補上；
 *    有別人手動填的列（沒有該報工人員）→不動。
 *  - 只動 auto_opened=1 且有 pm_report_id、未刪除的單；結案與否都處理；每項異動留 qa_abnormal_edit_log。
 *  不重送通知（單已通知過，不重發擾人）。
 */
require_once __DIR__ . '/qa_abnormal_lib.php';
require_once __DIR__ . '/unit_supervisor_lib.php';

if (!function_exists('qab_pm_resync_orders')) {
    /** @return array ['checked'=>int,'changes'=>[['order_no','what','from','to'],...]] */
    function qab_pm_resync_orders(PDO $db, ?int $opId = null): array
    {
        $out = ['checked' => 0, 'changes' => []];
        $orders = $db->query("SELECT id, abnormal_order_no, created_by, pm_report_id, COALESCE(resp_is_internal,0) AS is_int
                              FROM qa_abnormal_order
                              WHERE auto_opened=1 AND pm_report_id IS NOT NULL AND pm_report_id>0 AND deleted_at IS NULL")
                       ->fetchAll(PDO::FETCH_ASSOC);
        if (!$orders) return $out;
        $trig = $db->prepare("SELECT production_user_id, Created_By, report_date FROM pm_process_daily_report WHERE report_id=?");
        $reps = $db->prepare("SELECT production_user_id, report_date FROM pm_process_daily_report WHERE abnormal_order_id=?");
        $setBy = $db->prepare("UPDATE qa_abnormal_order SET created_by=? WHERE id=?");
        $logIns = $db->prepare("INSERT INTO qa_abnormal_edit_log (abnormal_order_id, action, reason, before_json, after_json, changed_by, changed_at)
                                VALUES (?,?,?,?,?,?,NOW())");
        // 沿用本檔原本的呼叫形狀：$log->execute([單id, 操作者, 欄位, 舊值, 新值, 原因])
        $log = new class($logIns) {
            private $st; function __construct($st) { $this->st = $st; }
            function execute(array $a) {
                $this->st->execute([$a[0], 'resync', $a[5], json_encode([$a[2] => $a[3]], JSON_UNESCAPED_UNICODE),
                                    json_encode([$a[2] => $a[4]], JSON_UNESCAPED_UNICODE), $a[1] ?: null]);
            }
        };
        $nameOf = function (int $uid) use ($db): string {
            static $c = [];
            if (!isset($c[$uid])) { $s = $db->prepare("SELECT user_cname FROM user WHERE id=?"); $s->execute([$uid]); $c[$uid] = (string)($s->fetchColumn() ?: $uid); }
            return $c[$uid];
        };
        $deptName = function (int $did) use ($db): string {
            static $c = [];
            if (!isset($c[$did])) { $s = $db->prepare("SELECT name FROM department WHERE id=?"); $s->execute([$did]); $c[$did] = (string)($s->fetchColumn() ?: ('#' . $did)); }
            return $c[$did];
        };
        $editor = (int)($opId ?: 0);
        $why = '職務異動（補登）後，依當時組織資料自動修正';

        foreach ($orders as $o) {
            $out['checked']++;
            $oid = (int)$o['id'];
            try {
                // ① 開單人
                $trig->execute([(int)$o['pm_report_id']]);
                $t = $trig->fetch(PDO::FETCH_ASSOC);
                if ($t) {
                    $reporter = (int)($t['production_user_id'] ?: $t['Created_By']);
                    $op = eg_unit_supervisor_available($db, $reporter, null, (string)$t['report_date']);
                    $newBy = (int)($op['id'] ?? 0);
                    if ($newBy > 0 && $newBy !== (int)$o['created_by']) {
                        $setBy->execute([$newBy, $oid]);
                        $log->execute([$oid, $editor, '開單人', $nameOf((int)$o['created_by']), $nameOf($newBy), $why]);
                        $out['changes'][] = ['order_no' => $o['abnormal_order_no'], 'what' => '開單人',
                                             'from' => $nameOf((int)$o['created_by']), 'to' => $nameOf($newBy)];
                    }
                }
                // ② 責任單位的部門／人員（廠內加工廠商才有）
                if ((int)$o['is_int'] === 1) {
                    $reps->execute([$oid]);
                    $cur = $db->prepare("SELECT id, dept_id, user_id FROM qa_abnormal_resp WHERE order_id=?");
                    $cur->execute([$oid]);
                    $have = $cur->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($reps->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                        $u = (int)$rr['production_user_id'];
                        if ($u <= 0) continue;
                        $d = (int)eg_unit_user_dept($db, $u, (string)$rr['report_date']);
                        if ($d <= 0) continue;
                        $mine = null;
                        foreach ($have as $h) if ((int)$h['user_id'] === $u) { $mine = $h; break; }
                        if ($mine) {
                            if ((int)$mine['dept_id'] !== $d) {
                                $db->prepare("DELETE FROM qa_abnormal_resp WHERE id=?")->execute([(int)$mine['id']]);
                                $db->prepare("INSERT IGNORE INTO qa_abnormal_resp (order_id, dept_id, user_id) VALUES (?,?,?)")->execute([$oid, $d, $u]);
                                $log->execute([$oid, $editor, '責任部門', $deptName((int)$mine['dept_id']) . '／' . $nameOf($u), $deptName($d) . '／' . $nameOf($u), $why]);
                                $out['changes'][] = ['order_no' => $o['abnormal_order_no'], 'what' => '責任部門',
                                                     'from' => $deptName((int)$mine['dept_id']), 'to' => $deptName($d)];
                            }
                        } elseif (!$have) {
                            $db->prepare("INSERT IGNORE INTO qa_abnormal_resp (order_id, dept_id, user_id) VALUES (?,?,?)")->execute([$oid, $d, $u]);
                            $log->execute([$oid, $editor, '責任部門', '', $deptName($d) . '／' . $nameOf($u), '報工NG自動開立單，責任單位帶入報工人員與其部門']);
                            $out['changes'][] = ['order_no' => $o['abnormal_order_no'], 'what' => '責任部門／人員（補上）',
                                                 'from' => '（空）', 'to' => $deptName($d) . '／' . $nameOf($u)];
                        }
                    }
                }
            } catch (Throwable $e) { error_log('qab_pm_resync ' . $oid . ': ' . $e->getMessage()); }
        }
        return $out;
    }
}
