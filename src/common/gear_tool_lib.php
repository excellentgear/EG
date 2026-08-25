<?php
// c:\MAMP\htdocs\EGsystem\src\common\gear_tool_lib.php
// ── 齒輪／花鍵計算工具：共用庫（唯一實作，禁止各頁自刻）──────────────────────
// 2026-08-25 由 views/Sales/NewOrder_Track.php 抽出，供多頁共用。
//   UI （CSS＋浮動視窗 HTML＋JS）：views/Sales/_gear_tool_ui.php
//   API（前端一律 POST 到這支）  ：views/Sales/gear_tool_api.php
//   使用端：views/Sales/NewOrder_Track.php（訂單追蹤）、views/Sales/image_editor.php（批圖編輯器）
// 要再加一個頁面用這個工具：include _gear_tool_ui.php 並自行放一顆呼叫 openGearTool() 的按鈕即可，
// 不要複製 CSS/HTML/JS，也不要另外再寫一份 gear_* 的 API（兩份規則一定會走鐘＝鐵律4）。

require_once __DIR__ . '/org_role_lib.php';

/** 是否為系統管理員（決定工具視窗右上角要不要出現「設定」） */
function gear_tool_is_admin(): bool {
    return in_array(intval($_SESSION['status'] ?? 0), [9, 90], true);
}

/**
 * 誰可以使用齒輪計算工具（唯一判定點）。
 * 沿用 NewOrder_Track 既有的 RBAC 規則：系統管理員（功能碼 all）或持有 ot_gear_calc 的角色。
 * @param PDO $pdo
 * @param int $userId 登入者 user.id
 */
function gear_tool_can_use($pdo, int $userId): bool {
    if (gear_tool_is_admin()) return true;
    if ($userId <= 0) return false;
    try {
        require_once __DIR__ . '/role_features_helper.php';
        $f = rf_load_user_features_all($pdo, $userId);
        return in_array('all', $f, true) || in_array('ot_gear_calc', $f, true);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 處理齒輪／花鍵工具的 POST action（比對不到就直接 return，交回呼叫端）。
 * 內含的每個分支都自己 echo json 後 exit，行為與抽出前完全一致。
 */
function gear_tool_handle_action($pdo): void {
    if (!isset($_POST['action'])) return;

    // ── 齒輪計算工具：初始化（建表 + 載入資料 + 設定）─────────────────────
    if ($_POST['action'] === 'gear_init') {
        header('Content-Type: application/json');
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS gear_hob_allowance_mn (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mn_gt DECIMAL(10,6) NOT NULL COMMENT '模數下限（不含）',
                mn_lte DECIMAL(10,6) NOT NULL COMMENT '模數上限（含）',
                hob_allow DECIMAL(10,6) NOT NULL COMMENT '滾齒預留量',
                ask_boss TINYINT(1) NOT NULL DEFAULT 0 COMMENT '需詢問BOSS',
                sort_order INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_by INT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS gear_hob_allowance_da (
                id INT AUTO_INCREMENT PRIMARY KEY,
                da_gt DECIMAL(10,4) NOT NULL COMMENT '外徑下限（不含）',
                da_lte DECIMAL(10,4) NOT NULL COMMENT '外徑上限（含）',
                od_allow DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '外徑預留量',
                ask_boss TINYINT(1) NOT NULL DEFAULT 0 COMMENT '需詢問BOSS',
                sort_order INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_by INT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4");
            // 舊表補欄：is_exact（精確匹配）— 欄位已存在時 MySQL 拋例外，直接 catch 忽略
            try { $pdo->exec("ALTER TABLE gear_hob_allowance_mn ADD COLUMN is_exact TINYINT(1) NOT NULL DEFAULT 0 COMMENT '精確匹配(=)'"); } catch(Exception $e){}
            $mn_rows = $pdo->query("SELECT * FROM gear_hob_allowance_mn ORDER BY is_exact DESC, mn_gt ASC, sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
            $da_rows = $pdo->query("SELECT * FROM gear_hob_allowance_da ORDER BY da_gt ASC, sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
            // 技術部門一律讀全站「組織角色綁定設定」的 rd_dept（含子部門），本頁不再自設一份（2026-08-03）
            require_once __DIR__ . '/org_role_lib.php';
            $tech_ids = eg_org_dept_ids($pdo, 'rd_dept');
            if (!$tech_ids) {
                $gs_row  = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='gear_tool_tech_dept_ids'")->fetch(PDO::FETCH_ASSOC);
                $tech_ids = $gs_row ? (json_decode($gs_row['setting_value'], true) ?: []) : [];
            }
            $depts = $pdo->query("SELECT id, name, parent_id, level FROM department ORDER BY sort_order, level, id")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'mn_rows'=>$mn_rows,'da_rows'=>$da_rows,'tech_dept_ids'=>$tech_ids,'depts'=>$depts]);
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 齒輪工具：儲存模數預留量列 ──────────────────────────────────────────
    if ($_POST['action'] === 'gear_save_mn') {
        header('Content-Type: application/json');
        try {
            $uid=intval($_SESSION['id']??0); $rid=intval($_POST['id']??0);
            $mngt=floatval($_POST['mn_gt']??0);
            $isExact=intval($_POST['is_exact']??0)?1:0;
            $mnlte=$isExact ? $mngt : floatval($_POST['mn_lte']??0);
            $allow=floatval($_POST['hob_allow']??0); $boss=intval($_POST['ask_boss']??0)?1:0;
            $sort=intval($_POST['sort_order']??0);
            if (!$isExact && $mnlte<=$mngt){echo json_encode(['success'=>false,'message'=>'上限必須大於下限']);exit;}
            $pdo->beginTransaction();
            if ($rid>0){
                $st=$pdo->prepare("UPDATE gear_hob_allowance_mn SET mn_gt=?,mn_lte=?,is_exact=?,hob_allow=?,ask_boss=?,sort_order=?,updated_by=?,updated_at=NOW() WHERE id=?");
                $st->execute([$mngt,$mnlte,$isExact,$allow,$boss,$sort,$uid,$rid]);
            }else{
                $st=$pdo->prepare("INSERT INTO gear_hob_allowance_mn (mn_gt,mn_lte,is_exact,hob_allow,ask_boss,sort_order,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)");
                $st->execute([$mngt,$mnlte,$isExact,$allow,$boss,$sort,$uid,$uid]);
            }
            $pdo->commit();
            $rows=$pdo->query("SELECT * FROM gear_hob_allowance_mn ORDER BY is_exact DESC, mn_gt ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'rows'=>$rows]);
        } catch (Exception $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 齒輪工具：刪除模數預留量列 ──────────────────────────────────────────
    if ($_POST['action'] === 'gear_delete_mn') {
        header('Content-Type: application/json');
        try {
            $rid=intval($_POST['id']??0);
            if($rid<=0){echo json_encode(['success'=>false,'message'=>'無效ID']);exit;}
            $pdo->prepare("DELETE FROM gear_hob_allowance_mn WHERE id=?")->execute([$rid]);
            $rows=$pdo->query("SELECT * FROM gear_hob_allowance_mn ORDER BY mn_gt ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'rows'=>$rows]);
        } catch (Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 齒輪工具：儲存外徑預留量列 ──────────────────────────────────────────
    if ($_POST['action'] === 'gear_save_da') {
        header('Content-Type: application/json');
        try {
            $uid=intval($_SESSION['id']??0); $rid=intval($_POST['id']??0);
            $dagt=floatval($_POST['da_gt']??0); $dalte=floatval($_POST['da_lte']??0);
            $allow=floatval($_POST['od_allow']??0); $boss=intval($_POST['ask_boss']??0)?1:0;
            $sort=intval($_POST['sort_order']??0);
            if($dalte<=$dagt){echo json_encode(['success'=>false,'message'=>'<=值必須大於>值']);exit;}
            $pdo->beginTransaction();
            if($rid>0){
                $st=$pdo->prepare("UPDATE gear_hob_allowance_da SET da_gt=?,da_lte=?,od_allow=?,ask_boss=?,sort_order=?,updated_by=?,updated_at=NOW() WHERE id=?");
                $st->execute([$dagt,$dalte,$allow,$boss,$sort,$uid,$rid]);
            }else{
                $st=$pdo->prepare("INSERT INTO gear_hob_allowance_da (da_gt,da_lte,od_allow,ask_boss,sort_order,created_by,updated_by) VALUES (?,?,?,?,?,?,?)");
                $st->execute([$dagt,$dalte,$allow,$boss,$sort,$uid,$uid]);
            }
            $pdo->commit();
            $rows=$pdo->query("SELECT * FROM gear_hob_allowance_da ORDER BY da_gt ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'rows'=>$rows]);
        } catch (Exception $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 齒輪工具：刪除外徑預留量列 ──────────────────────────────────────────
    if ($_POST['action'] === 'gear_delete_da') {
        header('Content-Type: application/json');
        try {
            $rid=intval($_POST['id']??0);
            if($rid<=0){echo json_encode(['success'=>false,'message'=>'無效ID']);exit;}
            $pdo->prepare("DELETE FROM gear_hob_allowance_da WHERE id=?")->execute([$rid]);
            $rows=$pdo->query("SELECT * FROM gear_hob_allowance_da ORDER BY da_gt ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'rows'=>$rows]);
        } catch (Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 齒輪工具：儲存技術課部門設定（管理員限定）──────────────────────────
    if ($_POST['action'] === 'gear_save_settings') {
        header('Content-Type: application/json');
        // 2026-08-03 起技術部門統一在「組織角色綁定設定」維護，本端點停用（避免兩處設定打架）
        echo json_encode(['success'=>false,'message'=>'技術部門已改由「組織角色綁定設定」統一維護，請至該頁設定「設計／技術部門」']);
        exit;
        if (!in_array(intval($_SESSION['status']??0),[9,90])){echo json_encode(['success'=>false,'message'=>'無管理員權限']);exit;}
        try {
            $uid=intval($_SESSION['id']??0); $uname=$_SESSION['userName']??'';
            $ids=json_decode($_POST['dept_ids']??'[]',true)?:[];
            $ids=array_values(array_unique(array_map('intval',array_filter($ids,'is_numeric'))));
            $st=$pdo->prepare("INSERT INTO system_settings (setting_key,setting_value,updated_by_id,updated_by,updated_at)
                VALUES ('gear_tool_tech_dept_ids',?,?,?,NOW())
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by_id=VALUES(updated_by_id),updated_by=VALUES(updated_by),updated_at=NOW()");
            $st->execute([json_encode($ids),$uid,$uname]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 花鍵工具：初始化（建表 + 讀取公差資料）──────────────────────────────
    if ($_POST['action'] === 'spline_init') {
        header('Content-Type: application/json');
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS spline_tolerance_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                standard ENUM('ISO4156','DIN5480','ANSIB922') NOT NULL DEFAULT 'ISO4156',
                is_external TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=外花鍵 0=內花鍵',
                quality_class VARCHAR(10) NOT NULL COMMENT '精度等級',
                fit_code VARCHAR(5) NOT NULL COMMENT '配合代號',
                m_gt DECIMAL(10,4) NULL COMMENT '模數下限(不含)',
                m_lte DECIMAL(10,4) NULL COMMENT '模數上限(含)',
                upper_dev_mm DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '上偏差/EI(mm)',
                tol_mm DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '公差帶寬度(mm)',
                is_estimate TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=估算值 0=標準精確值',
                source_notes TEXT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_by INT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_spline_tol (standard, is_external, quality_class, fit_code)
            ) DEFAULT CHARSET=utf8mb4");
            $rows = $pdo->query("SELECT * FROM spline_tolerance_data ORDER BY standard, is_external DESC, quality_class, fit_code, m_gt")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'tol_rows'=>$rows]);
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    // ── 花鍵工具：儲存公差資料 ───────────────────────────────────────────────
    if ($_POST['action'] === 'spline_save_tol') {
        header('Content-Type: application/json');
        try {
            $uid = intval($_SESSION['id']??0);
            $rid = intval($_POST['id']??0);
            $std = in_array($_POST['standard']??'',['ISO4156','DIN5480','ANSIB922']) ? $_POST['standard'] : 'ISO4156';
            $isExt = intval($_POST['is_external']??1) ? 1 : 0;
            $qc = trim($_POST['quality_class']??'');
            $fc = trim($_POST['fit_code']??'');
            $mgt  = strlen(trim($_POST['m_gt']??''))  ? floatval($_POST['m_gt'])  : null;
            $mlte = strlen(trim($_POST['m_lte']??'')) ? floatval($_POST['m_lte']) : null;
            $udev = floatval($_POST['upper_dev_mm']??0);
            $tol  = floatval($_POST['tol_mm']??0);
            $isEst = intval($_POST['is_estimate']??1) ? 1 : 0;
            $notes = trim($_POST['source_notes']??'');
            if (!$qc || !$fc) { echo json_encode(['success'=>false,'message'=>'精度等級和配合代號為必填']); exit; }
            if ($tol < 0)      { echo json_encode(['success'=>false,'message'=>'公差帶寬度不得為負']); exit; }
            $pdo->beginTransaction();
            if ($rid > 0) {
                $st = $pdo->prepare("UPDATE spline_tolerance_data SET standard=?,is_external=?,quality_class=?,fit_code=?,m_gt=?,m_lte=?,upper_dev_mm=?,tol_mm=?,is_estimate=?,source_notes=?,updated_by=?,updated_at=NOW() WHERE id=?");
                $st->execute([$std,$isExt,$qc,$fc,$mgt,$mlte,$udev,$tol,$isEst,$notes,$uid,$rid]);
            } else {
                $st = $pdo->prepare("INSERT INTO spline_tolerance_data (standard,is_external,quality_class,fit_code,m_gt,m_lte,upper_dev_mm,tol_mm,is_estimate,source_notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$std,$isExt,$qc,$fc,$mgt,$mlte,$udev,$tol,$isEst,$notes,$uid,$uid]);
            }
            $pdo->commit();
            $rows = $pdo->query("SELECT * FROM spline_tolerance_data ORDER BY standard, is_external DESC, quality_class, fit_code, m_gt")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'rows'=>$rows]);
        } catch (Exception $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
        exit;
    }

    // ── 花鍵工具：刪除公差資料 ───────────────────────────────────────────────
    if ($_POST['action'] === 'spline_delete_tol') {
        header('Content-Type: application/json');
        try {
            $rid = intval($_POST['id']??0);
            if ($rid <= 0) { echo json_encode(['success'=>false,'message'=>'無效ID']); exit; }
            $pdo->prepare("DELETE FROM spline_tolerance_data WHERE id=?")->execute([$rid]);
            $rows = $pdo->query("SELECT * FROM spline_tolerance_data ORDER BY standard, is_external DESC, quality_class, fit_code, m_gt")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'rows'=>$rows]);
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

}
