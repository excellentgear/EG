<?php
// BOM 管理員修正面板後端（唯一實作；BOM總覽與已完工BOM查詢共用）。由各頁以 require 載入，相對路徑以呼叫頁（views/pm）為準。
// 呼叫端須已確認 $_POST['action'] 是 baf_* 之一。處理完一律 exit。
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';
    require_once '../../src/common/confirm_password_lib.php';
    require_once '../../src/common/bom_admin_fix_lib.php';
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($db) && class_exists('DBConnection')) { $c = new DBConnection(); $db = $c->getPDO(); }
    $uid = (int)($_SESSION['id'] ?? 0);
    if (!$uid) { echo json_encode(['success'=>false,'message'=>'未登入']); exit; }
    if (!ber_is_admin($db, $uid)) { echo json_encode(['success'=>false,'message'=>'只有管理員可以使用管理員修正']); exit; }
    $act = $_POST['action'];
    try {
        if ($act === 'baf_check') { // 只讀：管理員在修改視窗的發單數下方看總數異常提示，不需解鎖
            $bm = trim((string)($_POST['bom'] ?? ''));
            echo json_encode(['success'=>true] + baf_check($db, $bm)); exit;
        }
        if ($act === 'baf_unlock') {
            $r = eg_confirm_password_verify_scoped($db, $uid, (string)($_POST['password'] ?? ''), 'oready_admin_fix');
            if (!$r['ok']) { echo json_encode(['success'=>false,'message'=>$r['msg']]); exit; }
            $_SESSION['oready_admin_fix'] = 1;
            try { $nm = $_SESSION['user_cname'] ?? ($_SESSION['userName'] ?? (string)$uid);
                  $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at) VALUES ('enable','oready_admin_fix','0','BOM管理員修正面板',NULL,?,?,NOW())")->execute([$uid, $nm]); } catch (Throwable $e) {}
            echo json_encode(['success'=>true]); exit;
        }
        if ($act === 'baf_lock') { unset($_SESSION['oready_admin_fix']); echo json_encode(['success'=>true]); exit; }
        if (empty($_SESSION['oready_admin_fix'])) { echo json_encode(['success'=>false,'need_unlock'=>true,'message'=>'請先輸入操作確認密碼']); exit; }
        $bom = trim((string)($_POST['bom'] ?? ''));
        if ($bom === '') { echo json_encode(['success'=>false,'message'=>'缺少 BOM']); exit; }
        if ($act === 'baf_list') { echo json_encode(['success'=>true] + baf_list($db, $bom)); exit; }
        if ($act === 'baf_flow_set') {
            baf_flow_set($db, $bom, (int)($_POST['transfer_id'] ?? 0), (string)($_POST['field'] ?? ''), (int)($_POST['batch_id'] ?? -1), $uid);
            echo json_encode(['success'=>true,'message'=>'已儲存']); exit;
        }
        if ($act === 'baf_bparent_set') {
            baf_bparent_set($db, $bom, (int)($_POST['batch_id'] ?? 0), (int)($_POST['parent_id'] ?? 0), $uid);
            echo json_encode(['success'=>true,'message'=>'已儲存']); exit;
        }
        if ($act === 'baf_flow_alloc') {
            $items = json_decode((string)($_POST['items'] ?? '[]'), true);
            baf_flow_alloc_set($db, $bom, (int)($_POST['transfer_id'] ?? 0), is_array($items) ? $items : [], $uid);
            echo json_encode(['success'=>true,'message'=>'已儲存拆分']); exit;
        }
        if ($act === 'baf_process_set') {
            $pr = baf_process_set($db, $bom, (int)($_POST['fid'] ?? 0), (int)($_POST['process_no'] ?? 0), $uid);
            echo json_encode(['success'=>true,'message'=>'已修正製程ID ' . $pr['old'] . ' → ' . $pr['new'] . ($pr['changed'] ? '（' . implode('、', $pr['changed']) . '）' : '')]); exit;
        }
        if ($act === 'baf_alias_set') {
            baf_alias_set($db, $bom, (int)($_POST['alias_sn'] ?? 0), (int)($_POST['target_sn'] ?? 0), $uid);
            echo json_encode(['success'=>true,'message'=>'已儲存SN對應']); exit;
        }
        $fid = (int)($_POST['fid'] ?? 0);
        $kind = (string)($_POST['kind'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $d = trim((string)($_POST['date'] ?? ''));
        $fl = fn($k) => (float)($_POST[$k] ?? 0);
        if ($kind !== 'transfer' && !baf_fid_ok($db, $fid, $bom)) { echo json_encode(['success'=>false,'message'=>'這個製程不屬於這個 BOM']); exit; }
        if ($act === 'baf_save') {
            if ($kind === 'send') {
                ber_save_send($db, $fid, $id, $fl('send_qty'), $uid);
                ber_save_batch_meta($db, $fid, $id, trim((string)($_POST['maker_no'] ?? '')), trim((string)($_POST['maker'] ?? '')), $d, $uid);
            } elseif ($kind === 'return') {
                ber_save_return($db, $fid, $id, $fl('return_qty'), $fl('scrap_qty'), $fl('withdraw_qty'), $d, $uid, true, $fl('short_qty'), $fl('badmat_qty'));
                require_once '../../src/common/qa_abnormal_lib.php';
                $bmBid = (int)$db->query("SELECT batch_id FROM bom_ing_outsource_return WHERE return_id=" . (int)$id)->fetchColumn();
                $bmAo = qab_outsource_batch_auto_open($db, $bmBid, $uid);
            } elseif ($kind === 'qc') {
                baf_qc_save($db, $fid, $id, (int)($_POST['ok'] ?? 0), (int)($_POST['aod'] ?? 0), (int)($_POST['qq'] ?? 0), (int)($_POST['ng'] ?? 0), $d, $uid);
            } elseif ($kind === 'transfer') {
                baf_transfer_save($db, $bom, $id, (int)($_POST['qty'] ?? 0), $d, $uid);
            } else { throw new Exception('這種紀錄不能在這裡修改'); }
            echo json_encode(['success'=>true,'message'=>'已儲存' . (isset($bmAo) ? $bmAo['msg'] : '')]);
        } elseif ($act === 'baf_delete') {
            if ($kind === 'send') ber_delete_batch($db, $fid, $id, $uid, true);
            elseif ($kind === 'return') {
                $dBid = (int)$db->query("SELECT batch_id FROM bom_ing_outsource_return WHERE return_id=" . (int)$id)->fetchColumn();
                ber_delete_return($db, $fid, $id, $uid, true);
                require_once '../../src/common/qa_abnormal_lib.php';
                $dAo = $dBid > 0 ? qab_outsource_batch_auto_open($db, $dBid, $uid) : ['msg' => ''];   // 刪掉報廢那筆 → 自動作廢先前開立的異常單
            }
            else throw new Exception('這種紀錄不能刪除');
            echo json_encode(['success'=>true,'message'=>'已刪除' . (isset($dAo) ? $dAo['msg'] : '')]);
        } elseif ($act === 'baf_add_send') {
            ber_add_batch($db, $fid, trim((string)($_POST['maker_no'] ?? '')), trim((string)($_POST['maker'] ?? '')), $fl('send_qty'), $d, $uid, true); // 管理員補發單不受發單數上限擋，超過會在「總數異常」紅字提示
            // 主畫面製程格讀的是 bom_ing.outsource_date／return_date；補登只寫批次表時格子不會顯示 → 只在欄位仍是空的時候補上（不覆蓋既有值、不改狀態）
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $db->prepare("UPDATE bom_ing SET outsource_date=CONCAT(?, ' 00:00:00') WHERE bom_ing_fid=? AND outsource_date IS NULL")->execute([$d, $fid]);
            }
            // 製程格依狀態決定顯示什麼：尚未發包(N)的站補了發單就改成加工中，格子才會出現發單日與廠商
            $db->prepare("UPDATE bom_ing SET processing_state='ing' WHERE bom_ing_fid=? AND processing_state='N'")->execute([$fid]);
            echo json_encode(['success'=>true,'message'=>'已新增發單']);
        } else {
            ber_add_return($db, $fid, (int)($_POST['batch_id'] ?? 0), $fl('return_qty'), $fl('scrap_qty'), $fl('withdraw_qty'), $d, $uid, $fl('short_qty'), $fl('badmat_qty'));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $op = $db->prepare("SELECT COUNT(*) FROM bom_ing_outsource_batch WHERE bom_ing_fid=? AND status='open'");
                $op->execute([$fid]);
                if ((int)$op->fetchColumn() === 0) {
                    $db->prepare("UPDATE bom_ing SET return_date=CONCAT(?, ' 00:00:00') WHERE bom_ing_fid=? AND return_date IS NULL")->execute([$d, $fid]);
                    // 全部回廠結清、狀態還停在未發包／加工中 → 視為已移轉，格子才會顯示「M/D 回」
                    $db->prepare("UPDATE bom_ing SET processing_state='E' WHERE bom_ing_fid=? AND processing_state IN ('N','ing')")->execute([$fid]);
                }
            }
            require_once '../../src/common/qa_abnormal_lib.php';
            $bmAo = qab_outsource_batch_auto_open($db, (int)($_POST['batch_id'] ?? 0), $uid);
            echo json_encode(['success'=>true,'message'=>'已新增回廠／報廢／取回' . $bmAo['msg']]);
        }
    } catch (Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
