<?php
// =============================================================================
// views/QC/inspection_combined_prototype.php
// 品管檢驗「合併頁面」（設定＋輸入合一）
//  - 由 QC 待驗清單按「檢驗」開啟，帶入 ?bom_ing_fid=XXX
//  - QC 邊驗邊設定檢驗項目/標準/判定，存檔寫入新制表(qc_check_form/qc_measurement)
//  - 存檔後回傳彙總(不良數/判定)給開啟此頁的待驗清單(opener)，由 QC 自行選 modal 送出
//  - ★ 不更動 QC_check_list.php 既有回報方式，最終 qc_check 仍由原有送出處理
//  - 未帶 bom_ing_fid 時進入「示範模式」(mock)，方便獨立瀏覽動線
//
//  載入效能：GET 載入只輸出 HTML，不跑任何結構檢查/查詢；所有 DB 操作走 AJAX。
// =============================================================================
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/rbac.php'; // #1 fail-closed：共用 RBAC bootstrap 判定
include_once '../../src/common/qc_inspection_lib.php'; // #3/#10/#12：後端重算/多量具/共用寫入

// 權限不足專用例外：讓 catch 統一回 HTTP 403（前端可據此禁用/提示）
if (!class_exists('QcPermException')) { class QcPermException extends Exception {} }

// #3 CSRF：以 session token 保護所有寫入型 POST（本專案無既有 CSRF 機制，故自建）
// 於 GET 載入時產生並嵌入頁面；AJAX 寫入時後端比對。
if (empty($_SESSION['qc_csrf'])) { $_SESSION['qc_csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['qc_csrf'];

// -----------------------------------------------------------------------------
// 後端 API（僅在 AJAX POST 時執行，GET 載入完全不進入此區塊）
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $conn = new DBConnection();
    $pdo  = $conn->getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $user_id = trim($_SESSION['id'] ?? $_SESSION['user_id'] ?? 'QC');

    // ---- schema 已移出熱路徑（#2）----
    // 所有欄位/輔助表改由一次性 migration 建置：
    //   views/QC/migrations/2026-07-21_inspection_schema.php
    // 正式請求路徑不再跑 SHOW COLUMNS / ALTER / CREATE，效能佳且免 web 帳號 ALTER 權限。
    // 保留同名空函式，讓既有呼叫點無痛（no-op）。新增欄位請改 migration，勿加回熱路徑。
    function ensureSchema($pdo) { /* no-op：見 migrations/2026-07-21_inspection_schema.php */ }

    // ---- 沿用既有角色/權限框架(roles/role_features/user_roles)讀取使用者功能 ----
    // #1 fail-closed：委派 src/common/rbac.php 的共用判定(bootstrap)——
    //   已被指派角色者：嚴格以其角色功能聯集為準（即使無任何功能＝無權限）；
    //   完全未指派角色者：僅在系統「尚無管理員」時暫時全權(供初始設定)，一旦有管理員即無權限。
    //   → 修掉原本「無角色一律回 ['all']」的安全漏洞（含存檔/改設定全權）。
    function loadUserFeatures($pdo, $user_id) {
        return rbac_user_features($pdo, (int)$user_id);
    }
    function hasFeature($features, $code) {
        return in_array('all', $features, true) || in_array($code, $features, true);
    }
    // 主管(可開放/修改檢驗歷程)：擁有 qc_edit_history 功能
    function isSupervisor($pdo, $user_id) {
        return hasFeature(loadUserFeatures($pdo, $user_id), 'qc_edit_history');
    }
    // 檢閱權限：任一 QC 相關功能即可檢視；qc_view_readonly 為「僅可檢視」的最低權限
    function canViewInspection($feats) {
        foreach (['qc_view_readonly','qc_fill_inspection','qc_edit_history','qc_manage_settings','qc_manage_sampling','qa_disposition_reply'] as $c) {
            if (hasFeature($feats, $c)) return true;
        }
        return false;
    }
    function requireViewPerm($pdo, $user_id) {
        if (!canViewInspection(loadUserFeatures($pdo, $user_id))) {
            throw new QcPermException('您沒有檢閱檢驗表的權限，請洽管理員於 設定 → 權限設定（角色 → QC 功能）開通「唯讀檢閱」');
        }
    }
    // 管理檢驗設定（量具/幾何公差/通用樣板）權限
    function requireSettingPerm($pdo, $user_id) {
        if (!hasFeature(loadUserFeatures($pdo, $user_id), 'qc_manage_settings')) {
            throw new QcPermException('您沒有「管理檢驗設定」權限，請洽管理員於 設定 → 權限設定（角色 → QC 功能）開通');
        }
    }
    // 抽樣規則獨立權限：主管(qc_edit_history)固定可修改，亦可用 qc_manage_sampling 單獨授權
    function canManageSampling($feats) {
        return hasFeature($feats, 'qc_manage_sampling') || hasFeature($feats, 'qc_edit_history');
    }
    function requireSamplingPerm($pdo, $user_id) {
        if (!canManageSampling(loadUserFeatures($pdo, $user_id))) {
            throw new QcPermException('抽樣規則僅主管可修改，請洽管理員於 設定 → 權限設定（角色 → QC 功能）開通「抽樣規則設定」');
        }
    }

    // ---- 取得/建立 該料號的預設檢驗版本（版本隱藏化：2/3料號永遠用這個）----
    function getDefaultVersionId($pdo, $d_id) {
        $s = $pdo->prepare("SELECT version_id FROM qc_inspection_version WHERE d_id=? AND is_active=1 ORDER BY version_id DESC LIMIT 1");
        $s->execute([$d_id]);
        $v = $s->fetchColumn();
        if ($v) return (int)$v;
        $pdo->prepare("INSERT INTO qc_inspection_version (d_id, version_label, source_type, is_active) VALUES (?, '目前使用中', 'REVISION', 1)")
            ->execute([$d_id]);
        return (int)$pdo->lastInsertId();
    }

    // ---- 取得預設表單型態（優先 GENERAL）----
    function getDefaultFormTypeId($pdo) {
        $v = $pdo->query("SELECT form_type_id FROM qc_inspection_form_type WHERE is_active=1 AND form_code='GENERAL' LIMIT 1")->fetchColumn();
        if ($v) return (int)$v;
        $v = $pdo->query("SELECT form_type_id FROM qc_inspection_form_type WHERE is_active=1 ORDER BY form_type_id ASC LIMIT 1")->fetchColumn();
        if ($v) return (int)$v;
        $pdo->exec("INSERT INTO qc_inspection_form_type (form_code, form_name, inspection_stage, is_active) VALUES ('GENERAL','一般檢','IPQC',1)");
        return (int)$pdo->lastInsertId();
    }

    // ---- 讀取系統設定（找不到回預設）----
    function qcSetting($pdo, $key, $default) {
        try {
            $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $default : $v;
        } catch (Exception $e) { return $default; }
    }
    // ---- #6 本人寬限期：同一 created_by 於存檔後 N 小時內可自改（不需主管解鎖）----
    // 回傳 true 代表「此人可在寬限期內自行修改此筆」。
    function qcOwnerWithinGrace($pdo, $form, $user_id) {
        if (!$form || trim((string)$form['created_by']) !== trim((string)$user_id)) return false;
        $graceH = (float)qcSetting($pdo, 'qc_self_edit_grace_hours', 8);
        if ($graceH <= 0) return false;
        $ct = strtotime((string)($form['created_at'] ?? ''));
        if (!$ct) return false;
        return time() < ($ct + (int)round($graceH * 3600));
    }

    try {
        // #3 CSRF：寫入型 action 一律比對 session token（前端經 ajaxPrefilter 自動夾帶 csrf）
        $WRITE_ACTIONS = ['save_inspection','save_draft','discard_draft','update_inspection','set_ncr_decision','unlock_record','relock_record',
            'save_tool_category','delete_tool_category','save_tool_instance','delete_tool_instance','replace_tool_category',
            'save_special_item','delete_special_item','manage_templates','manage_sampling_rules'];
        if (in_array($_POST['action'], $WRITE_ACTIONS, true)) {
            $tok = $_POST['csrf'] ?? '';
            if (!is_string($tok) || $tok === '' || !hash_equals((string)($_SESSION['qc_csrf'] ?? ''), $tok)) {
                throw new QcPermException('連線憑證失效或不符，請重新整理頁面後再試 (CSRF)');
            }
        }

        // =====================================================================
        // 0) 查詢本人 QC 權限（頁面載入時呼叫，決定設定選單/按鈕顯示）
        // =====================================================================
        if ($_POST['action'] === 'get_my_perms') {
            $feats = loadUserFeatures($pdo, $user_id);
            echo json_encode([
                'success'             => true,
                'can_view'            => canViewInspection($feats),
                'can_fill'            => hasFeature($feats, 'qc_fill_inspection'),
                'is_supervisor'       => hasFeature($feats, 'qc_edit_history'),
                'can_manage_settings' => hasFeature($feats, 'qc_manage_settings'),
                'can_manage_sampling' => canManageSampling($feats),
                'is_admin'            => hasFeature($feats, 'all'),
                'current_user'        => $user_id,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 1) 載入待驗項目的檢驗情境（料號/客戶/數量/既有標準/量具/歷程）
        // =====================================================================
        if ($_POST['action'] === 'load_context') {
            ensureSchema($pdo);
            $fid = (int)($_POST['bom_ing_fid'] ?? 0);
            if (!$fid) throw new Exception('缺少 bom_ing_fid');
            $feats = loadUserFeatures($pdo, $user_id);
            $is_sup = hasFeature($feats, 'qc_edit_history');
            if (!canViewInspection($feats)) {
                echo json_encode(['success'=>false, 'no_view'=>true,
                    'message'=>'您沒有檢閱檢驗表的權限，請洽管理員於 設定 → 權限設定（角色 → QC 功能）開通「唯讀檢閱」或其他 QC 權限'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $sql = "SELECT bi.bom_ing_fid, bi.bom, bi.sqty, bi.process_no, bi.batch_label,
                           b.d_id AS part_no, b.Client_Name,
                           d.d_id AS d_setting_pk, d.D_Setting_Id, d.Revision,
                           pn.ProcessName
                    FROM bom_ing bi
                    LEFT JOIN bom b ON bi.bom = b.bom
                    LEFT JOIN d_setting d ON b.d_id = d.D_Setting_Id
                    LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                    WHERE bi.bom_ing_fid = ?";
            $s = $pdo->prepare($sql);
            $s->execute([$fid]);
            $ctx = $s->fetch(PDO::FETCH_ASSOC);
            if (!$ctx) throw new Exception('查無此待驗項目 (bom_ing_fid='.$fid.')');

            $d_id = (int)$ctx['d_setting_pk'];
            $process = $ctx['ProcessName'];

            $items = [];
            $version_id = null; $form_type_id = getDefaultFormTypeId($pdo);
            if ($d_id) {
                $version_id = getDefaultVersionId($pdo, $d_id);
                // 既有標準項目（含主要量具名稱）
                $iq = "SELECT i.item_id, i.item_code, i.item_name, i.standard_text,
                              i.min_value, i.max_value, i.plus_tolerance, i.minus_tolerance,
                              i.result_type, i.sort_order, i.process_name,
                              (SELECT tl.QC_Tool FROM qc_inspection_item_tool_type itt
                                 JOIN qc_tool_list tl ON itt.QC_Tool_List_id = tl.QC_Tool_List_id
                                 WHERE itt.item_id = i.item_id ORDER BY itt.is_primary DESC LIMIT 1) AS tool_name
                       FROM qc_inspection_item i
                       WHERE i.version_id=? AND i.form_type_id=? AND i.is_active=1
                         AND (i.process_name <=> ? OR i.process_name IS NULL)
                       ORDER BY i.sort_order ASC, i.item_id ASC";
                $is = $pdo->prepare($iq);
                $is->execute([$version_id, $form_type_id, $process]);
                // 去除 DECIMAL 補的尾零（小數點後僅 0 則省略），避免浮點雜訊
                $fmt = function($v){ if ($v === null) return ''; $s = rtrim(rtrim((string)$v, '0'), '.'); return ($s === '' || $s === '-') ? '0' : $s; };
                foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $items[] = [
                        'item_id'   => (int)$r['item_id'],
                        'name'      => $r['item_name'],
                        'std'       => $r['standard_text'],
                        'up'        => $fmt($r['plus_tolerance']),
                        'lo'        => $fmt($r['minus_tolerance']),
                        'tool'      => $r['tool_name'] ?: '',
                        'type'      => $r['result_type'] === 'OKNG' ? 'OKNG' : 'NUM',
                    ];
                }
            }

            // 量具種類清單（下拉用）
            $tools = $pdo->query("SELECT QC_Tool FROM qc_tool_list ORDER BY sort_order ASC, QC_Tool ASC")
                         ->fetchAll(PDO::FETCH_COLUMN);

            // 抽驗數（依規則；無規則則簡易推估）
            $sample_qty = 0;
            try {
                $sr = $pdo->prepare("SELECT sample_qty FROM qc_sampling_rule WHERE ? BETWEEN min_qty AND max_qty ORDER BY min_qty DESC LIMIT 1");
                $sr->execute([(int)$ctx['sqty']]);
                $sample_qty = (int)$sr->fetchColumn();
            } catch (Exception $e) {}
            if (!$sample_qty) { $q=(int)$ctx['sqty']; $sample_qty = $q>=500?8:($q>=100?5:3); }
            if ($sample_qty > (int)$ctx['sqty']) $sample_qty = (int)$ctx['sqty'];
            if ($sample_qty < 1) $sample_qty = 1;

            // 既有檢驗歷程（批次/複驗，含異常單決定）
            $history = [];
            try {
                $hq = "SELECT f.qc_form_id, f.batch_no, f.round_no, f.incoming_qty, f.sample_qty, f.ng_qty, f.check_result,
                              f.check_date, f.created_at, f.created_by, f.main_remark,
                              f.edit_unlocked, f.last_edited_by, f.last_edited_at,
                              f.ncr_decision, f.ncr_skip_reason, f.abnormal_order_id, qa.abnormal_order_no,
                              (SELECT COUNT(*) FROM qc_inspection_edit_log el WHERE el.qc_form_id = f.qc_form_id) AS edit_log_count
                       FROM qc_check_form f
                       LEFT JOIN qa_abnormal_order qa ON qa.id = f.abnormal_order_id
                       WHERE f.bom_ing_fid=? AND f.status <> 'DRAFT' ORDER BY f.batch_no ASC, f.round_no ASC, f.qc_form_id ASC";
                $hs = $pdo->prepare($hq); $hs->execute([$fid]);
                $history = $hs->fetchAll(PDO::FETCH_ASSOC);
                // #6：標記每筆「本人是否於寬限期內可自改」
                foreach ($history as &$hr) { $hr['self_grace'] = qcOwnerWithinGrace($pdo, $hr, $user_id) ? 1 : 0; }
                unset($hr);
            } catch (Exception $e) { /* 欄位可能尚未建立 */ }

            // #4：本人未送出的草稿（若有）→ 供前端提示載回
            $draftFid = 0;
            try {
                $dq = $pdo->prepare("SELECT qc_form_id FROM qc_check_form WHERE bom_ing_fid=? AND status='DRAFT' AND created_by=? ORDER BY qc_form_id DESC LIMIT 1");
                $dq->execute([$fid, $user_id]);
                $draftFid = (int)$dq->fetchColumn();
            } catch (Exception $e) {}

            echo json_encode([
                'success' => true,
                'context' => [
                    'bom_ing_fid' => (int)$ctx['bom_ing_fid'],
                    'bom'         => $ctx['bom'],
                    'part_no'     => $ctx['part_no'],
                    'client'      => $ctx['Client_Name'],
                    'order_qty'   => (int)$ctx['sqty'],
                    'process'     => $process,
                    'batch_label' => $ctx['batch_label'],  // 拆批時的批次代號，同製程有多批送驗要能分辨是哪一批
                    'd_id'        => $d_id,
                    'version_id'  => $version_id,
                    'form_type_id'=> $form_type_id,
                    'sample_qty'  => $sample_qty,
                ],
                'items'   => $items,
                'tools'   => $tools,
                'history' => $history,
                'draft_form_id' => $draftFid,
                'has_std' => count($items) > 0,
                'is_supervisor' => $is_sup,
                'is_admin'      => hasFeature($feats, 'all'),
                'can_fill'      => hasFeature($feats, 'qc_fill_inspection'),
                'can_manage_settings' => hasFeature($feats, 'qc_manage_settings'),
                'can_manage_sampling' => canManageSampling($feats),
                'current_user'  => $user_id,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 1.5) 搜尋待驗項目（示範/獨立瀏覽用，可輸入部分料號/BOM/客戶）
        // =====================================================================
        if ($_POST['action'] === 'search_pending') {
            requireViewPerm($pdo, $user_id);
            $kw = trim($_POST['keyword'] ?? '');
            $sql = "SELECT bi.bom_ing_fid, bi.bom, b.d_id AS part_no, b.Client_Name AS client, pn.ProcessName AS process, bi.sqty, bi.batch_label
                    FROM bom_ing bi
                    LEFT JOIN bom b ON bi.bom = b.bom
                    LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                    WHERE bi.processing_state IN ('Q','P') AND bi.qc_completed = 0
                      AND (bi.bom LIKE :kw OR b.d_id LIKE :kw OR b.Client_Name LIKE :kw)
                    ORDER BY bi.outsource_date DESC LIMIT 30";
            $st = $pdo->prepare($sql);
            $st->execute([':kw' => "%$kw%"]);
            echo json_encode(['success'=>true, 'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 2) 儲存檢驗結果（明細→新制表；同步更新標準；回傳彙總）
        // =====================================================================
        if ($_POST['action'] === 'save_inspection') {
            ensureSchema($pdo);
            $featsS = loadUserFeatures($pdo, $user_id);
            if (!hasFeature($featsS, 'qc_fill_inspection')) {
                throw new QcPermException('您沒有「填寫檢驗表單」權限，請洽管理員於 權限設定（角色 → QC 功能）開通');
            }

            $fid          = (int)($_POST['bom_ing_fid'] ?? 0);
            $d_id         = (int)($_POST['d_id'] ?? 0);
            $process      = $_POST['process_name'] ?? null;
            $batch_no     = (int)($_POST['batch_no'] ?? 1);
            $round_no     = (int)($_POST['round_no'] ?? 1);
            $incoming_qty = (int)($_POST['incoming_qty'] ?? 0);
            $sample_qty   = (int)($_POST['sample_qty'] ?? 0);
            $main_remark  = trim($_POST['main_remark'] ?? '');
            $update_std   = ($_POST['update_std'] ?? '0') === '1';
            // 改寫料號標準(update_std)屬設定層級 → 需「管理檢驗設定」權限
            if ($update_std && !hasFeature($featsS, 'qc_manage_settings')) {
                throw new QcPermException('修改檢驗標準需「管理檢驗設定」權限；如僅要記錄本次實測，請取消「同步更新標準」後再存檔');
            }
            $items        = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items)) $items = [];
            $pcs = json_decode($_POST['pcs_verdicts'] ?? '[]', true);
            if (!is_array($pcs)) $pcs = [];
            if (!$fid) throw new Exception('缺少 bom_ing_fid');
            // 此料件尚無料號設定(d_setting)：擋下並提示，不自動建立
            if ($d_id <= 0) throw new Exception('此料件尚未建立料號，請先到 基本設定 建立料號');

            $version_id   = getDefaultVersionId($pdo, $d_id);
            $form_type_id = getDefaultFormTypeId($pdo);

            $pdo->beginTransaction();

            // --- 2a. 解析/落地檢驗項目，取得 item_id ---
            $itemIds = []; // 與 $items 同索引
            if ($update_std) {
                // 全量更新此料號(版本+型態+製程)的標準
                $delTool = "DELETE t FROM qc_inspection_item_tool_type t
                            JOIN qc_inspection_item i ON t.item_id=i.item_id
                            WHERE i.version_id=? AND i.form_type_id=? AND (i.process_name <=> ?)";
                $pdo->prepare($delTool)->execute([$version_id, $form_type_id, $process]);
                $pdo->prepare("DELETE FROM qc_inspection_item WHERE version_id=? AND form_type_id=? AND (process_name <=> ?)")
                    ->execute([$version_id, $form_type_id, $process]);
            }

            $insItem = $pdo->prepare(
                "INSERT INTO qc_inspection_item
                 (version_id, form_type_id, process_name, item_code, item_name, standard_text,
                  min_value, max_value, plus_tolerance, minus_tolerance, result_type, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?)");
            $findItem = $pdo->prepare(
                "SELECT item_id FROM qc_inspection_item
                 WHERE version_id=? AND form_type_id=? AND (process_name <=> ?) AND item_name=? ORDER BY item_id DESC LIMIT 1");
            $insTool = $pdo->prepare(
                "INSERT INTO qc_inspection_item_tool_type (item_id, QC_Tool_List_id, is_primary) VALUES (?, ?, 1)");
            $toolIdByName = function($name) use ($pdo) {
                if ($name === '' || $name === null) return null;
                $s = $pdo->prepare("SELECT QC_Tool_List_id FROM qc_tool_list WHERE QC_Tool=? LIMIT 1");
                $s->execute([$name]);
                $v = $s->fetchColumn();
                return $v ? (int)$v : null;
            };
            // #10：由量具「實例」(Tool_id) 反查其類型(QC_Tool_List_id)，供 update_std 存標準量具類型
            $catByToolId = function($toolId) use ($pdo) {
                if (!$toolId) return null;
                $s = $pdo->prepare("SELECT QC_Tool_List_id FROM qc_tool WHERE Tool_id=? LIMIT 1");
                $s->execute([(int)$toolId]);
                $v = $s->fetchColumn();
                return $v ? (int)$v : null;
            };

            foreach ($items as $idx => $it) {
                $name = trim($it['name'] ?? '');
                if ($name === '') { $itemIds[$idx] = null; continue; }
                $type = ($it['type'] ?? 'NUM') === 'OKNG' ? 'OKNG' : 'NUMERIC';
                $plus  = ($it['up'] ?? '') !== '' ? $it['up'] : null;
                $minus = ($it['lo'] ?? '') !== '' ? $it['lo'] : null;
                $code  = (string)($idx + 1);

                $iid = null;
                if (!$update_std) {
                    // 沿用既有標準項目（依名稱比對），找不到才新建(設為停用，不污染標準)
                    $findItem->execute([$version_id, $form_type_id, $process, $name]);
                    $iid = $findItem->fetchColumn();
                }
                if (!$iid) {
                    $insItem->execute([
                        $version_id, $form_type_id, $process, $code, $name,
                        ($it['std'] ?? ''), $plus, $minus, $type, $idx + 1,
                        $update_std ? 1 : 0,
                    ]);
                    $iid = (int)$pdo->lastInsertId();
                    if ($update_std) {
                        // 優先由選定的量具實例反查類型；無則回退舊的類型名稱對應
                        $tid = $catByToolId($it['tool_id'] ?? null);
                        if (!$tid) $tid = $toolIdByName($it['tool'] ?? '');
                        if ($tid) { try { $insTool->execute([$iid, $tid]); } catch (Exception $e) {} }
                    }
                }
                $itemIds[$idx] = (int)$iid;
            }

            // --- 2c. 先寫入檢驗表頭（ng/判定先給預設值，寫完明細再由後端彙總回填）---
            $insForm = $pdo->prepare(
                "INSERT INTO qc_check_form
                 (bom_ing_fid, d_id, version_id, form_type_id, process_name, batch_no, round_no,
                  incoming_qty, sample_qty, ng_qty, check_result, status, main_remark, pcs_verdicts, check_date, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'OK', 'SUBMITTED', ?, ?, NOW(), ?, NOW())");
            $insForm->execute([
                $fid, $d_id, $version_id, (string)$form_type_id, $process, $batch_no, $round_no,
                $incoming_qty, $sample_qty, $main_remark,
                json_encode($pcs, JSON_UNESCAPED_UNICODE), $user_id,
            ]);
            $qc_form_id = (int)$pdo->lastInsertId();

            // --- 2d. 寫入實測明細 + 後端重算判定/彙總（#3 後端為準；#10 多量具/多次量測）---
            $tot = qc_persist_readings($pdo, $qc_form_id, $items, $itemIds, $pcs, $user_id);
            $ng_qty = $tot['ng_qty']; $aod_qty = $tot['aod_qty']; $check_result = $tot['check_result'];
            $pdo->prepare("UPDATE qc_check_form SET ng_qty=?, check_result=? WHERE qc_form_id=?")
                ->execute([$ng_qty, $check_result, $qc_form_id]);

            // --- #4：正式存檔後，清掉同(批次,複驗)的本人草稿(含其明細)，避免殘留/重複 ---
            $dq = $pdo->prepare("SELECT qc_form_id FROM qc_check_form WHERE bom_ing_fid=? AND batch_no=? AND round_no=? AND status='DRAFT' AND created_by=?");
            $dq->execute([$fid, $batch_no, $round_no, $user_id]);
            foreach ($dq->fetchAll(PDO::FETCH_COLUMN) as $did) {
                $pdo->prepare("DELETE FROM qc_measurement WHERE qc_form_id=?")->execute([$did]);
                $pdo->prepare("DELETE FROM qc_check_form WHERE qc_form_id=?")->execute([$did]);
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'qc_form_id' => $qc_form_id,
                'summary' => [
                    'bom_ing_fid'  => $fid,
                    'process'      => $process,
                    'batch_no'     => $batch_no,
                    'round_no'     => $round_no,
                    'incoming_qty' => $incoming_qty,
                    'sample_qty'   => $sample_qty,
                    'total_items'  => count($items),
                    'ng_qty'       => $ng_qty,   // 判定NG項目數 → 不良數
                    'aod_qty'      => $aod_qty,  // 允收(讓步)項目數
                    'check_result' => $check_result,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 2.5) #4 草稿自動存檔：以 status=DRAFT upsert（同 bom_ing_fid+批次+複驗+本人一份）
        //      填寫過程背景保存，關掉視窗不流失；正式存檔後草稿自動清除。
        // =====================================================================
        if ($_POST['action'] === 'save_draft') {
            if (!hasFeature(loadUserFeatures($pdo, $user_id), 'qc_fill_inspection')) {
                throw new QcPermException('您沒有「填寫檢驗表單」權限');
            }
            $fid        = (int)($_POST['bom_ing_fid'] ?? 0);
            $d_id       = (int)($_POST['d_id'] ?? 0);
            $process    = $_POST['process_name'] ?? null;
            $batch_no   = (int)($_POST['batch_no'] ?? 1);
            $round_no   = (int)($_POST['round_no'] ?? 1);
            $incoming_qty = (int)($_POST['incoming_qty'] ?? 0);
            $sample_qty   = (int)($_POST['sample_qty'] ?? 0);
            $main_remark  = trim($_POST['main_remark'] ?? '');
            if (!$fid || $d_id <= 0) throw new Exception('缺少草稿必要參數');
            $version_id   = getDefaultVersionId($pdo, $d_id);
            $form_type_id = getDefaultFormTypeId($pdo);

            // 草稿以「整包 JSON」保存前端填寫內容，不建立標準項目、不寫 qc_measurement（避免污染標準）
            $draftJson = json_encode([
                'items'        => (json_decode($_POST['items'] ?? '[]', true) ?: []),
                'pcs'          => (json_decode($_POST['pcs_verdicts'] ?? '[]', true) ?: []),
                'incoming_qty' => $incoming_qty, 'sample_qty' => $sample_qty, 'main_remark' => $main_remark,
            ], JSON_UNESCAPED_UNICODE);

            $f = $pdo->prepare("SELECT qc_form_id FROM qc_check_form WHERE bom_ing_fid=? AND batch_no=? AND round_no=? AND status='DRAFT' AND created_by=? ORDER BY qc_form_id DESC LIMIT 1");
            $f->execute([$fid, $batch_no, $round_no, $user_id]);
            $draftId = (int)$f->fetchColumn();
            if ($draftId) {
                // last_edited_by 一定要跟著寫：只寫時間會變成「有修改時間、查不到修改人」
                $pdo->prepare("UPDATE qc_check_form SET incoming_qty=?, sample_qty=?, main_remark=?, process_name=?, draft_json=?, last_edited_by=?, last_edited_at=NOW() WHERE qc_form_id=?")
                    ->execute([$incoming_qty, $sample_qty, $main_remark, $process, $draftJson, $user_id, $draftId]);
            } else {
                $pdo->prepare("INSERT INTO qc_check_form (bom_ing_fid, d_id, version_id, form_type_id, process_name, batch_no, round_no, incoming_qty, sample_qty, ng_qty, check_result, status, main_remark, draft_json, created_by, created_at, last_edited_by, last_edited_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'OK', 'DRAFT', ?, ?, ?, NOW(), ?, NOW())")
                    ->execute([$fid, $d_id, $version_id, (string)$form_type_id, $process, $batch_no, $round_no, $incoming_qty, $sample_qty, $main_remark, $draftJson, $user_id, $user_id]);
                $draftId = (int)$pdo->lastInsertId();
            }
            echo json_encode(['success'=>true, 'draft_form_id'=>$draftId], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // #4：取回草稿內容（整包 JSON）
        if ($_POST['action'] === 'get_draft') {
            requireViewPerm($pdo, $user_id);
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            $s = $pdo->prepare("SELECT draft_json FROM qc_check_form WHERE qc_form_id=? AND status='DRAFT' AND created_by=?");
            $s->execute([$qid, $user_id]);
            $j = $s->fetchColumn();
            echo json_encode(['success'=>true, 'draft'=>($j ? json_decode($j, true) : null)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // #4：捨棄本人草稿（含明細）
        if ($_POST['action'] === 'discard_draft') {
            if (!hasFeature(loadUserFeatures($pdo, $user_id), 'qc_fill_inspection')) {
                throw new QcPermException('您沒有「填寫檢驗表單」權限');
            }
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            $fid = (int)($_POST['bom_ing_fid'] ?? 0);
            $pdo->beginTransaction();
            $sel = $pdo->prepare("SELECT qc_form_id FROM qc_check_form WHERE status='DRAFT' AND created_by=? AND (qc_form_id=? OR (?>0 AND bom_ing_fid=?))");
            $sel->execute([$user_id, $qid, $fid, $fid]);
            foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $did) {
                $pdo->prepare("DELETE FROM qc_measurement WHERE qc_form_id=?")->execute([$did]);
                $pdo->prepare("DELETE FROM qc_check_form WHERE qc_form_id=?")->execute([$did]);
            }
            $pdo->commit();
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 3) 取得單筆歷程明細（供編輯載入 + 顯示鎖定狀態/權限）
        // =====================================================================
        if ($_POST['action'] === 'get_history_record') {
            ensureSchema($pdo);
            requireViewPerm($pdo, $user_id);
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            if (!$qid) throw new Exception('缺少 qc_form_id');
            $h = $pdo->prepare("SELECT * FROM qc_check_form WHERE qc_form_id=?");
            $h->execute([$qid]);
            $form = $h->fetch(PDO::FETCH_ASSOC);
            if (!$form) throw new Exception('查無此檢驗紀錄');

            $fmt = function($v){ if ($v === null) return ''; $s = rtrim(rtrim((string)$v, '0'), '.'); return ($s === '' || $s === '-') ? '0' : $s; };
            $sampleN = max(1, (int)$form['sample_qty']);
            // #10：帶回 measure_method/tool_id/Tool_No，依 (方法,量具) 分組還原「主讀值 + 加量測」
            $mq = "SELECT m.item_id, m.sample_no, m.measured_value, m.result, m.item_verdict, m.remark,
                          m.measure_method, m.tool_id, m.reading_seq, t.Tool_No,
                          i.item_name, i.standard_text, i.plus_tolerance, i.minus_tolerance, i.result_type,
                          (SELECT tl.QC_Tool FROM qc_inspection_item_tool_type itt JOIN qc_tool_list tl ON itt.QC_Tool_List_id=tl.QC_Tool_List_id WHERE itt.item_id=i.item_id ORDER BY itt.is_primary DESC LIMIT 1) AS tool_name
                   FROM qc_measurement m JOIN qc_inspection_item i ON m.item_id=i.item_id
                   LEFT JOIN qc_tool t ON m.tool_id=t.Tool_id
                   WHERE m.qc_form_id=? ORDER BY i.sort_order ASC, m.item_id ASC, m.measurement_id ASC";
            $ms = $pdo->prepare($mq); $ms->execute([$qid]);
            $blank = function($n){ $a=[]; for($i=0;$i<$n;$i++) $a[]=['v'=>'','r'=>'OK']; return $a; };
            $byItem = [];   // iid => 基本欄位 + _groups
            foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $iid = (int)$r['item_id'];
                if (!isset($byItem[$iid])) {
                    $byItem[$iid] = [
                        'item_id'=>$iid, 'name'=>$r['item_name'], 'std'=>$r['standard_text'],
                        'up'=>$fmt($r['plus_tolerance']), 'lo'=>$fmt($r['minus_tolerance']),
                        'tool'=>$r['tool_name'] ?: '', 'type'=>$r['result_type']==='OKNG'?'OKNG':'NUM',
                        'verdict'=>$r['item_verdict'] ?: 'OK', 'remark'=>($r['remark'] ?? ''),
                        '_groups'=>[],
                    ];
                }
                if ((int)$r['sample_no'] <= 0) continue; // 純判定列(無實測)
                $key = ($r['measure_method'] ?? '') . '|' . ($r['tool_id'] ?? '');
                if (!isset($byItem[$iid]['_groups'][$key])) {
                    $byItem[$iid]['_groups'][$key] = [
                        'tool_id'=>isset($r['tool_id'])?(int)$r['tool_id']:null,
                        'tool_no'=>$r['Tool_No'] ?? '', 'method'=>$r['measure_method'] ?? '',
                        'samples'=>$blank($sampleN),
                    ];
                }
                $pos = (int)$r['sample_no'] - 1;
                if ($pos >= 0 && $pos < $sampleN) {
                    $byItem[$iid]['_groups'][$key]['samples'][$pos] = ['v'=>$r['measured_value'], 'r'=>$r['result']];
                }
            }
            // 分組收斂：第一組=主讀值(samples/tool_id)，其餘=加量測(extra[])
            foreach ($byItem as $iid => &$row) {
                $groups = array_values($row['_groups']); unset($row['_groups']);
                if ($groups) {
                    $g0 = $groups[0];
                    $row['samples'] = $g0['samples'];
                    $row['tool_id'] = $g0['tool_id'];
                    if (($row['tool'] === '' || $row['tool'] === null) && $g0['method'] !== '') $row['tool'] = $g0['method'];
                    $row['extra'] = [];
                    for ($gi = 1; $gi < count($groups); $gi++) {
                        $row['extra'][] = [
                            'tool_id'=>$groups[$gi]['tool_id'], 'tool_no'=>$groups[$gi]['tool_no'],
                            'method'=>$groups[$gi]['method'], 'samples'=>$groups[$gi]['samples'],
                        ];
                    }
                } else {
                    $row['samples'] = []; $row['extra'] = []; $row['tool_id'] = null;
                }
            }
            unset($row);
            $featsH = loadUserFeatures($pdo, $user_id);
            $is_sup = hasFeature($featsH, 'qc_edit_history');
            $can_fill_h = hasFeature($featsH, 'qc_fill_inspection');
            echo json_encode([
                'success'=>true,
                'header'=>[
                    'qc_form_id'=>(int)$form['qc_form_id'], 'bom_ing_fid'=>(int)$form['bom_ing_fid'],
                    'batch_no'=>(int)$form['batch_no'], 'round_no'=>(int)$form['round_no'],
                    'incoming_qty'=>(int)$form['incoming_qty'], 'sample_qty'=>(int)$form['sample_qty'],
                    'ng_qty'=>(int)$form['ng_qty'], 'check_result'=>$form['check_result'],
                    'process_name'=>$form['process_name'], 'main_remark'=>$form['main_remark'],
                    'pcs_verdicts'=>(is_array($pv = json_decode($form['pcs_verdicts'] ?? '[]', true)) ? $pv : []),
                    'edit_unlocked'=>(int)$form['edit_unlocked'],
                    // 列印簽章用：已存檔紀錄的簽章日期＝檢驗日，簽章人＝存檔者（v2 列印版）
                    'check_date'=>$form['check_date'] ?? ($form['created_at'] ?? ''),
                    'created_by'=>$form['created_by'] ?? '',
                    'creator_name'=>(function() use ($pdo, $form) {
                        try {
                            $q = $pdo->prepare("SELECT COALESCE(NULLIF(user_cname,''), user_uname) FROM user WHERE id=?");
                            $q->execute([(int)$form['created_by']]);
                            return (string)($q->fetchColumn() ?: '');
                        } catch (Exception $e) { return ''; }
                    })(),
                    'last_edited_by'=>$form['last_edited_by'], 'last_edited_at'=>$form['last_edited_at'],
                    'ncr_decision'=>$form['ncr_decision'] ?? null,
                    'abnormal_order_id'=>isset($form['abnormal_order_id']) ? (int)$form['abnormal_order_id'] : 0,
                    'abnormal_order_no'=>(!empty($form['abnormal_order_id'])
                        ? (function() use ($pdo, $form) {
                              $q = $pdo->prepare("SELECT abnormal_order_no FROM qa_abnormal_order WHERE id=?");
                              $q->execute([(int)$form['abnormal_order_id']]);
                              return $q->fetchColumn() ?: null;
                          })()
                        : null),
                ],
                'items'=>array_values($byItem),
                'is_supervisor'=>$is_sup,
                // 唯讀檢閱者即使該筆已開放修改也不可編輯（需填寫或主管權限）
                // #6：本人寬限期內可自改（不需主管解鎖）
                'can_edit'=>($is_sup || ($can_fill_h && ((int)$form['edit_unlocked'] === 1 || qcOwnerWithinGrace($pdo, $form, $user_id)))),
                'self_grace'=>qcOwnerWithinGrace($pdo, $form, $user_id),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 4) 主管開放某筆修改（解鎖）
        // =====================================================================
        if ($_POST['action'] === 'unlock_record') {
            ensureSchema($pdo);
            if (!isSupervisor($pdo, $user_id)) throw new QcPermException('需主管權限才能開放修改');
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if (!$qid) throw new Exception('缺少 qc_form_id');
            $pdo->prepare("UPDATE qc_check_form SET edit_unlocked=1, unlocked_by=?, unlocked_at=NOW() WHERE qc_form_id=?")
                ->execute([$user_id, $qid]);
            $pdo->prepare("INSERT INTO qc_inspection_edit_log (qc_form_id, action, reason, changed_by) VALUES (?, 'UNLOCK', ?, ?)")
                ->execute([$qid, $reason, $user_id]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 4.5) 取消修改回鎖：僅在該筆仍為開放狀態時回鎖並寫稽核紀錄
        //      （回鎖只會讓狀態更嚴格，故不限主管；已鎖定者為 no-op 不寫紀錄）
        // =====================================================================
        if ($_POST['action'] === 'relock_record') {
            ensureSchema($pdo);
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            if (!$qid) throw new Exception('缺少 qc_form_id');
            $st = $pdo->prepare("UPDATE qc_check_form SET edit_unlocked=0 WHERE qc_form_id=? AND edit_unlocked=1");
            $st->execute([$qid]);
            if ($st->rowCount() > 0) {
                $pdo->prepare("INSERT INTO qc_inspection_edit_log (qc_form_id, action, reason, changed_by) VALUES (?, 'RELOCK', ?, ?)")
                    ->execute([$qid, '取消修改，自動回鎖', $user_id]);
            }
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 5) 覆寫更新已存歷程（需已開放或主管），並寫稽核紀錄，完成後自動回鎖
        // =====================================================================
        if ($_POST['action'] === 'update_inspection') {
            ensureSchema($pdo);
            $featsU = loadUserFeatures($pdo, $user_id);
            if (!hasFeature($featsU, 'qc_fill_inspection') && !hasFeature($featsU, 'qc_edit_history')) {
                throw new QcPermException('您沒有「填寫檢驗表單」權限，請洽管理員於 權限設定（角色 → QC 功能）開通');
            }
            $qid    = (int)($_POST['qc_form_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $items  = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items)) $items = [];
            $pcs = json_decode($_POST['pcs_verdicts'] ?? '[]', true);
            if (!is_array($pcs)) $pcs = [];
            $incoming_qty = (int)($_POST['incoming_qty'] ?? 0);
            $sample_qty   = (int)($_POST['sample_qty'] ?? 0);
            $main_remark  = trim($_POST['main_remark'] ?? '');
            if (!$qid) throw new Exception('缺少 qc_form_id');
            if ($reason === '') throw new Exception('請填寫修改原因');

            $h = $pdo->prepare("SELECT * FROM qc_check_form WHERE qc_form_id=?");
            $h->execute([$qid]);
            $form = $h->fetch(PDO::FETCH_ASSOC);
            if (!$form) throw new Exception('查無此檢驗紀錄');

            $is_sup = isSupervisor($pdo, $user_id);
            // #6：主管、已解鎖、或本人寬限期內 → 可改；否則需主管先開放
            $own_grace = qcOwnerWithinGrace($pdo, $form, $user_id);
            if ((int)$form['edit_unlocked'] !== 1 && !$is_sup && !$own_grace) throw new Exception('此筆已鎖定，請主管先開放修改（本人可自改的寬限時間已過）');

            // 修改前快照
            $bm = $pdo->prepare("SELECT item_id, sample_no, measured_value, result, item_verdict FROM qc_measurement WHERE qc_form_id=? ORDER BY item_id, sample_no");
            $bm->execute([$qid]);
            $before = ['header'=>['incoming_qty'=>(int)$form['incoming_qty'],'sample_qty'=>(int)$form['sample_qty'],'ng_qty'=>(int)$form['ng_qty'],'check_result'=>$form['check_result'],'main_remark'=>$form['main_remark']], 'measurements'=>$bm->fetchAll(PDO::FETCH_ASSOC)];

            $version_id = (int)$form['version_id']; $form_type_id = (int)$form['form_type_id']; $process = $form['process_name'];
            $findItem = $pdo->prepare("SELECT item_id FROM qc_inspection_item WHERE version_id=? AND form_type_id=? AND (process_name <=> ?) AND item_name=? ORDER BY item_id DESC LIMIT 1");
            $insItem  = $pdo->prepare("INSERT INTO qc_inspection_item (version_id, form_type_id, process_name, item_code, item_name, standard_text, plus_tolerance, minus_tolerance, result_type, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

            $pdo->beginTransaction();
            // 解析 item_id
            $itemIds = [];
            foreach ($items as $idx => $it) {
                $name = trim($it['name'] ?? '');
                if ($name === '') { $itemIds[$idx] = null; continue; }
                $iid = !empty($it['item_id']) ? (int)$it['item_id'] : null;
                if (!$iid) {
                    $findItem->execute([$version_id, $form_type_id, $process, $name]);
                    $iid = $findItem->fetchColumn();
                }
                if (!$iid) {
                    $type = ($it['type'] ?? 'NUM') === 'OKNG' ? 'OKNG' : 'NUMERIC';
                    $plus  = ($it['up'] ?? '') !== '' ? $it['up'] : null;
                    $minus = ($it['lo'] ?? '') !== '' ? $it['lo'] : null;
                    $insItem->execute([$version_id, $form_type_id, $process, (string)($idx+1), $name, ($it['std'] ?? ''), $plus, $minus, $type, $idx+1]);
                    $iid = (int)$pdo->lastInsertId();
                }
                $itemIds[$idx] = (int)$iid;
            }
            // 重寫明細 + 後端重算判定/彙總（#3 後端為準；#10 多量具/多次量測；#12 與存檔共用同一函式）
            $tot = qc_persist_readings($pdo, $qid, $items, $itemIds, $pcs, $user_id);
            $ng_qty = $tot['ng_qty']; $aod_qty = $tot['aod_qty']; $check_result = $tot['check_result'];

            // 更新表頭 + 自動回鎖
            $pdo->prepare("UPDATE qc_check_form SET incoming_qty=?, sample_qty=?, ng_qty=?, check_result=?, main_remark=?, pcs_verdicts=?, edit_unlocked=0, last_edited_by=?, last_edited_at=NOW() WHERE qc_form_id=?")
                ->execute([$incoming_qty, $sample_qty, $ng_qty, $check_result, $main_remark, json_encode($pcs, JSON_UNESCAPED_UNICODE), $user_id, $qid]);

            $after = ['header'=>['incoming_qty'=>$incoming_qty,'sample_qty'=>$sample_qty,'ng_qty'=>$ng_qty,'check_result'=>$check_result,'main_remark'=>$main_remark], 'items'=>$items];
            $pdo->prepare("INSERT INTO qc_inspection_edit_log (qc_form_id, action, reason, changes_json, changed_by) VALUES (?, 'EDIT', ?, ?, ?)")
                ->execute([$qid, $reason, json_encode(['before'=>$before,'after'=>$after], JSON_UNESCAPED_UNICODE), $user_id]);
            $pdo->commit();

            echo json_encode(['success'=>true, 'summary'=>[
                'bom_ing_fid'=>(int)$form['bom_ing_fid'], 'qc_form_id'=>$qid,
                'ng_qty'=>$ng_qty, 'aod_qty'=>$aod_qty, 'check_result'=>$check_result,
                'incoming_qty'=>$incoming_qty, 'sample_qty'=>$sample_qty,
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 5.5) NG 後的異常單決定：OPEN=已開立(記關聯) / SKIP=不開立(必填原因)
        // =====================================================================
        if ($_POST['action'] === 'set_ncr_decision') {
            ensureSchema($pdo);
            $featsN = loadUserFeatures($pdo, $user_id);
            if (!hasFeature($featsN, 'qc_fill_inspection') && !hasFeature($featsN, 'qc_edit_history')) {
                throw new QcPermException('您沒有記錄異常單決定的權限（需「填寫檢驗表單」或主管權限）');
            }
            $qid    = (int)($_POST['qc_form_id'] ?? 0);
            $dec    = ($_POST['decision'] ?? '') === 'OPEN' ? 'OPEN' : 'SKIP';
            $reason = trim($_POST['reason'] ?? '');
            $aoid   = (int)($_POST['abnormal_order_id'] ?? 0);
            if (!$qid) throw new Exception('缺少 qc_form_id');
            if ($dec === 'SKIP' && $reason === '') throw new Exception('請填寫不開立異常單的原因');
            $pdo->prepare("UPDATE qc_check_form SET ncr_decision=?, ncr_skip_reason=?, abnormal_order_id=? WHERE qc_form_id=?")
                ->execute([$dec, ($dec === 'SKIP' ? $reason : null), ($dec === 'OPEN' && $aoid ? $aoid : null), $qid]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 6) 查詢某筆的修改稽核紀錄
        // =====================================================================
        if ($_POST['action'] === 'get_edit_log') {
            ensureSchema($pdo);
            requireViewPerm($pdo, $user_id);
            $qid = (int)($_POST['qc_form_id'] ?? 0);
            $s = $pdo->prepare("SELECT l.log_id, l.action, l.reason, l.changes_json, l.changed_by, l.changed_at, u.user_cname
                                FROM qc_inspection_edit_log l LEFT JOIN user u ON TRIM(l.changed_by)=u.id
                                WHERE l.qc_form_id=? ORDER BY l.log_id DESC");
            $s->execute([$qid]);
            echo json_encode(['success'=>true, 'logs'=>$s->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 7) 設定：量具設定（種類/編號 CRUD、取代刪除）— 與 inspection_standard_setting.php 共用資料表
        // =====================================================================
        if ($_POST['action'] === 'get_tool_manage_data') {
            $categories = $pdo->query("SELECT * FROM qc_tool_list ORDER BY sort_order ASC, QC_Tool ASC")->fetchAll(PDO::FETCH_ASSOC);
            // 量具規格＝校驗模組綁的採購料號（qc_tool.purchase_spec_id → purchase_spec，見 tool_calibration 量具料號對應）
            // 未建表／未加欄的環境不 join，回傳內容與原本相同
            $hasSpecJoin = false; $hasBrandCol = false;
            try {
                $hasSpecJoin = (bool)$pdo->query("SHOW COLUMNS FROM qc_tool LIKE 'purchase_spec_id'")->fetchColumn()
                            && (bool)$pdo->query("SHOW TABLES LIKE 'purchase_spec'")->fetchColumn();
                if ($hasSpecJoin) $hasBrandCol = (bool)$pdo->query("SHOW COLUMNS FROM purchase_spec LIKE 'brand'")->fetchColumn();
            } catch (Throwable $e) {}
            $tools = $pdo->query("SELECT t.*"
                     . ($hasSpecJoin ? ", ps.spec_text" . ($hasBrandCol ? ", ps.brand AS spec_brand" : "") : "") . "
                      FROM qc_tool t"
                     . ($hasSpecJoin ? " LEFT JOIN purchase_spec ps ON ps.spec_id=t.purchase_spec_id" : "") . "
                      ORDER BY t.Tool_No ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true, 'categories'=>$categories, 'tools'=>$tools], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'save_tool_category') {
            requireSettingPerm($pdo, $user_id);
            $id = $_POST['id'] ?? ''; $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('名稱必填');
            if ($id) $pdo->prepare("UPDATE qc_tool_list SET QC_Tool=? WHERE QC_Tool_List_id=?")->execute([$name, $id]);
            else $pdo->prepare("INSERT INTO qc_tool_list (QC_Tool) VALUES (?)")->execute([$name]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'delete_tool_category') {
            requireSettingPerm($pdo, $user_id);
            $id = (int)($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM qc_tool WHERE QC_Tool_List_id=?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) throw new Exception('此種類下尚有量具編號，請先清空編號後再刪除（或改用「取代並刪除」）');
            $pdo->prepare("DELETE FROM qc_tool_list WHERE QC_Tool_List_id=?")->execute([$id]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'save_tool_instance') {
            requireSettingPerm($pdo, $user_id);
            $id = $_POST['id'] ?? ''; $cat_id = $_POST['cat_id'] ?? ''; $no = trim($_POST['no'] ?? '');
            if ($cat_id === '' || $no === '') throw new Exception('資料不完整');
            // 量測儀器校驗管理設為「不可設定量具編號」的種類（僅檢驗方式，如目視）不得掛編號
            $noNo = false;
            try {
                $s = $pdo->prepare("SELECT has_tool_no FROM qc_tool_list WHERE QC_Tool_List_id=?");
                $s->execute([$cat_id]);
                $v = $s->fetchColumn();
                $noNo = ($v !== false && $v !== null && (int)$v === 0);
            } catch (PDOException $e) { /* 尚未加欄 → 不擋，維持原行為 */ }
            if ($noNo) throw new Exception('此量具種類已設為「不可設定量具編號」（僅為檢驗方式），如需開放請至「量測儀器校驗管理－類別設定」調整');
            if ($id) $pdo->prepare("UPDATE qc_tool SET Tool_No=?, QC_Tool_List_id=? WHERE Tool_id=?")->execute([$no, $cat_id, $id]);
            else $pdo->prepare("INSERT INTO qc_tool (Tool_No, QC_Tool_List_id, Created_at) VALUES (?, ?, NOW())")->execute([$no, $cat_id]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'delete_tool_instance') {
            requireSettingPerm($pdo, $user_id);
            $pdo->prepare("DELETE FROM qc_tool WHERE Tool_id=?")->execute([(int)($_POST['id'] ?? 0)]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'replace_tool_category') {
            requireSettingPerm($pdo, $user_id);
            $old_id = (int)($_POST['old_id'] ?? 0); $new_id = (int)($_POST['new_id'] ?? 0);
            if (!$old_id || !$new_id || $old_id === $new_id) throw new Exception('無效的參數');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE qc_inspection_item_tool_type SET QC_Tool_List_id=? WHERE QC_Tool_List_id=?")->execute([$new_id, $old_id]);
            $pdo->prepare("UPDATE qc_tool SET QC_Tool_List_id=? WHERE QC_Tool_List_id=?")->execute([$new_id, $old_id]);
            $pdo->prepare("DELETE FROM qc_tool_list WHERE QC_Tool_List_id=?")->execute([$old_id]);
            $pdo->commit();
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 8) 設定：幾何公差管理（qc_special_characteristic CRUD）
        // =====================================================================
        if ($_POST['action'] === 'get_special_items') {
            $rows = $pdo->query("SELECT characteristic_id AS id, name, symbol, description AS code FROM qc_special_characteristic WHERE is_active=1 ORDER BY characteristic_id ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true, 'special_items'=>$rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'save_special_item') {
            requireSettingPerm($pdo, $user_id);
            $id = $_POST['id'] ?? ''; $name = trim($_POST['name'] ?? '');
            $symbol = $_POST['symbol'] ?? ''; $code = $_POST['code'] ?? '';
            if ($name === '') throw new Exception('名稱為必填');
            if ($id) $pdo->prepare("UPDATE qc_special_characteristic SET name=?, symbol=?, description=? WHERE characteristic_id=?")->execute([$name, $symbol, $code, $id]);
            else $pdo->prepare("INSERT INTO qc_special_characteristic (name, symbol, description, is_active) VALUES (?, ?, ?, 1)")->execute([$name, $symbol, $code]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_POST['action'] === 'delete_special_item') {
            requireSettingPerm($pdo, $user_id);
            $pdo->prepare("DELETE FROM qc_special_characteristic WHERE characteristic_id=?")->execute([(int)($_POST['id'] ?? 0)]);
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // =====================================================================
        // 9) 設定：通用樣板管理（qc_inspection_template；亦供「匯入通用樣板」使用）
        // =====================================================================
        if ($_POST['action'] === 'manage_templates') {
            $sub = $_POST['sub_action'] ?? '';
            if ($sub === 'list') {
                $rows = $pdo->query("SELECT * FROM qc_inspection_template ORDER BY template_id DESC")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true, 'data'=>$rows], JSON_UNESCAPED_UNICODE);
            } elseif ($sub === 'get_items') {
                $tid = (int)($_POST['template_id'] ?? 0);
                // tool_ids 為 qc_tool_list 的 id（單值），轉回量具名稱給前端下拉
                $st = $pdo->prepare("SELECT ti.*, (SELECT tl.QC_Tool FROM qc_tool_list tl WHERE tl.QC_Tool_List_id = ti.tool_ids LIMIT 1) AS tool_name
                                     FROM qc_inspection_template_item ti WHERE ti.template_id=? ORDER BY ti.sort_order ASC");
                $st->execute([$tid]);
                $fmt = function($v){ if ($v === null || $v === '') return ''; $s = rtrim(rtrim((string)$v, '0'), '.'); return ($s === '' || $s === '-') ? '0' : $s; };
                $items = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $items[] = [
                        'name' => $r['item_name'],
                        'std'  => $r['standard_text'],
                        'up'   => $fmt($r['plus_tolerance']),
                        'lo'   => $fmt($r['minus_tolerance']),
                        'tool' => $r['tool_name'] ?: '',
                        'type' => $r['result_type'] === 'OKNG' ? 'OKNG' : 'NUM',
                    ];
                }
                echo json_encode(['success'=>true, 'items'=>$items], JSON_UNESCAPED_UNICODE);
            } elseif ($sub === 'save') {
                requireSettingPerm($pdo, $user_id);
                $tid = $_POST['template_id'] ?? '';
                $name = trim($_POST['name'] ?? '');
                $items = json_decode($_POST['items'] ?? '[]', true);
                if ($name === '') throw new Exception('請輸入樣板名稱');
                if (!is_array($items) || !count($items)) throw new Exception('樣板至少需要一個檢驗項目');
                $pdo->beginTransaction();
                if ($tid) {
                    $pdo->prepare("UPDATE qc_inspection_template SET template_name=? WHERE template_id=?")->execute([$name, $tid]);
                    $pdo->prepare("DELETE FROM qc_inspection_template_item WHERE template_id=?")->execute([$tid]);
                } else {
                    $pdo->prepare("INSERT INTO qc_inspection_template (template_name) VALUES (?)")->execute([$name]);
                    $tid = $pdo->lastInsertId();
                }
                $ins = $pdo->prepare("INSERT INTO qc_inspection_template_item
                    (template_id, item_name, standard_text, plus_tolerance, minus_tolerance, result_type, tool_ids, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $toolId = $pdo->prepare("SELECT QC_Tool_List_id FROM qc_tool_list WHERE QC_Tool=? LIMIT 1");
                foreach ($items as $idx => $it) {
                    $tId = null;
                    if (!empty($it['tool'])) { $toolId->execute([$it['tool']]); $tId = $toolId->fetchColumn() ?: null; }
                    $ins->execute([
                        $tid, $it['name'] ?? '', $it['std'] ?? '',
                        (($it['up'] ?? '') !== '') ? $it['up'] : null,
                        (($it['lo'] ?? '') !== '') ? $it['lo'] : null,
                        (($it['type'] ?? 'NUM') === 'OKNG') ? 'OKNG' : 'NUMERIC',
                        $tId, $idx,
                    ]);
                }
                $pdo->commit();
                echo json_encode(['success'=>true, 'template_id'=>(int)$tid], JSON_UNESCAPED_UNICODE);
            } elseif ($sub === 'delete') {
                requireSettingPerm($pdo, $user_id);
                $tid = (int)($_POST['template_id'] ?? 0);
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM qc_inspection_template WHERE template_id=?")->execute([$tid]);
                $pdo->prepare("DELETE FROM qc_inspection_template_item WHERE template_id=?")->execute([$tid]);
                $pdo->commit();
                echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('未知的 sub_action: ' . $sub);
            }
            exit;
        }

        // =====================================================================
        // 10) 設定：抽樣規則（qc_sampling_rule CRUD；load_context 的建議抽驗數依此計算）
        // =====================================================================
        if ($_POST['action'] === 'manage_sampling_rules') {
            $sub = $_POST['sub_action'] ?? '';
            if ($sub === 'list') {
                $rows = $pdo->query("SELECT * FROM qc_sampling_rule ORDER BY min_qty ASC")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true, 'data'=>$rows], JSON_UNESCAPED_UNICODE);
            } elseif ($sub === 'save') {
                requireSamplingPerm($pdo, $user_id);
                $min = (int)($_POST['min'] ?? 0); $max = (int)($_POST['max'] ?? 0);
                $sample = (int)($_POST['sample'] ?? 0); $id = $_POST['id'] ?? '';
                if ($max < $min) throw new Exception('最大數量不可小於最小數量');
                if ($sample < 1) throw new Exception('抽驗數至少為 1');
                if ($id) $pdo->prepare("UPDATE qc_sampling_rule SET min_qty=?, max_qty=?, sample_qty=? WHERE rule_id=?")->execute([$min, $max, $sample, $id]);
                else $pdo->prepare("INSERT INTO qc_sampling_rule (min_qty, max_qty, sample_qty) VALUES (?, ?, ?)")->execute([$min, $max, $sample]);
                echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            } elseif ($sub === 'delete') {
                requireSamplingPerm($pdo, $user_id);
                $pdo->prepare("DELETE FROM qc_sampling_rule WHERE rule_id=?")->execute([(int)($_POST['id'] ?? 0)]);
                echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE);
            } else {
                throw new Exception('未知的 sub_action: ' . $sub);
            }
            exit;
        }

        // 註：主管權限改用既有角色框架(roles/role_features/user_roles)管理。
        // 角色↔功能：本頁「設定→權限設定」透過 Roles_API.php 指派 qc_edit_history。
        // 人員↔角色：於 views/user/user_permissions.php 設定。

        // =====================================================================
        // #8 同料號歷次檢驗（跨 BOM）：列出同一料號(d_id)歷來檢驗表，點入看逐項實測
        // =====================================================================
        if ($_POST['action'] === 'history_by_part') {
            requireViewPerm($pdo, $user_id);
            $d_id = (int)($_POST['d_id'] ?? 0);
            if (!$d_id) throw new Exception('缺少料號 d_id');
            $q = $pdo->prepare(
                "SELECT f.qc_form_id, f.bom_ing_fid, f.batch_no, f.round_no, f.process_name,
                        f.check_date, f.created_at, f.check_result, f.ng_qty, f.incoming_qty, f.sample_qty,
                        f.created_by, u.user_cname, bi.bom
                 FROM qc_check_form f
                 LEFT JOIN bom_ing bi ON bi.bom_ing_fid = f.bom_ing_fid
                 LEFT JOIN user u ON TRIM(f.created_by) = u.id
                 WHERE f.d_id = ? AND f.status <> 'DRAFT'
                 ORDER BY f.qc_form_id DESC LIMIT 300");
            $q->execute([$d_id]);
            echo json_encode(['success'=>true, 'rows'=>$q->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new Exception('未知的 action: ' . $_POST['action']);
    } catch (QcPermException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(403); // #1：權限不足統一回 403，前端據此禁用/提示
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'code' => 403], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 由待驗清單跳窗開啟時帶 popup=1：隱藏左側選單與置頂欄，純內容全寬顯示
$isPopup = isset($_GET['popup']) && $_GET['popup'] == '1';
// 跳窗模式不引入選單（選單原負責擋未登入），故此處自行做登入檢查
if ($isPopup && !isset($_SESSION['id']) && !isset($_SESSION['user_id'])) {
    echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>品管檢驗（合併）</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; appearance: textfield; }

        .proto-banner { background:#fff3cd; border:1px solid #ffeeba; color:#856404; padding:8px 12px; border-radius:4px; margin-bottom:12px; font-size:13px; }
        .search-result-item { cursor:pointer; padding:8px 10px; border-bottom:1px solid #eee; }
        .search-result-item:hover { background:#f5f5f5; }

        .batch-chip { display:inline-block; padding:6px 12px; margin:0 6px 6px 0; border-radius:18px; border:1px solid #ccc; background:#fff; cursor:pointer; font-size:13px; user-select:none; }
        .batch-chip:hover { border-color:#26b99a; }
        .batch-chip.active { background:#26b99a; border-color:#26b99a; color:#fff; }
        .batch-chip .st { font-weight:bold; margin-left:4px; }
        .batch-chip.active .st { color:#fff; }
        .st-ok { color:#5cb85c; } .st-ng { color:#d9534f; } .st-redo { color:#f0ad4e; } .st-wait { color:#5bc0de; }

        #proc-tabs > li > a { padding:6px 14px; font-weight:bold; }

        /* 固定表格版面：欄寬固定，實測值欄取剩餘寬度，PCS 格子自動換列；
           table-layout:fixed + input min-width:0 讓表格永遠不超出容器(既不出現左右拉桿、也不會遮擋右側欄位) */
        #items-table { table-layout:fixed; width:100%; max-width:100%; }
        #items-table > thead th, #items-table > tbody td, #items-table > tfoot td { word-break:break-word; overflow:hidden; }
        .table-input { width:100%; min-width:0; border:1px solid #ccc; padding:3px 5px; border-radius:3px; }
        .table-input[readonly] { background:#eee; color:#555; }
        #items-table thead th { background:#f5f5f5; position:sticky; top:0; z-index:2; white-space:nowrap; text-align:center; }
        #items-table td { vertical-align:middle; }
        .okng-btn { cursor:pointer; user-select:none; font-weight:bold; }
        /* 實測值：每 PCS 一格，格子上方自帶抽驗序號(P1、P2…)，換列也看得出是第幾格 */
        .s-slot { display:inline-block; width:52px; margin:0 3px 4px 0; text-align:center; vertical-align:top; }
        .s-slot .num-cell { width:50px; padding-left:2px; padding-right:2px; }
        .s-num { display:block; font-size:10px; color:#999; line-height:1.1; margin-bottom:1px; }
        /* 輸入格/判定格靠左排(格子自帶序號,不需與表頭數字對齊) */
        #items-table td.sample-cell, #items-table td#verdict-cells { text-align:left; }
        /* 判定結果列（每 PCS 一格，可點擊手動切換） */
        .pcs-verdict { cursor:pointer; user-select:none; font-weight:bold; display:inline-block; min-width:44px; }
        .pcs-verdict.manual { outline:2px dashed #f0ad4e; }
        #items-table tfoot td { position:sticky; bottom:0; background:#f5f5f5; z-index:2; }
        /* #10 加量測：同尺寸多量具/多次量測 */
        .add-reading { display:inline-block; margin-top:3px; font-size:12px; color:#26b99a; cursor:pointer; }
        .add-reading:hover { text-decoration:underline; }
        tr.reading-sub > td { background:#f3f9f7; border-top:1px dashed #cfe6df; }
        tr.reading-sub .f-tool2 { border-color:#9ccebf; }
        /* 量具欄：下拉維持固定窄寬(不撐寬、不擠壓實測值欄)；量具編號在下方自動換列完整顯示 */
        #items-table td.tool-col { width:104px; min-width:104px; max-width:104px; }
        #items-table select.f-tool, #items-table select.f-tool2 { width:100%; }
        .tool-sel-label { font-size:11px; color:#555; word-break:break-all; line-height:1.25; margin-top:2px; }
        /* #5 逐項備註圖示 */
        .item-note { cursor:pointer; color:#aaa; }
        .item-note:hover { color:#26b99a; }
        .item-note.has-note { color:#f0ad4e; } /* 已有備註 */

        /* #7 列印/匯出：正式檢驗表版面（A4，交瀏覽器原生分頁；不用 JS 量高度） */
        #print-area { display:none; }
        @media print {
            @page { size:A4 portrait; margin:12mm 10mm; }
            body { background:#fff !important; }
            body * { visibility:hidden; }
            #print-area, #print-area * { visibility:visible; }
            #print-area { display:block; position:absolute; left:0; top:0; width:100%; color:#000; font-size:12px; }
            #print-area .pr-title { text-align:center; font-size:18px; font-weight:bold; margin-bottom:2px; }
            #print-area .pr-sub { text-align:center; font-size:12px; margin-bottom:8px; }
            #print-area .pr-meta { width:100%; border-collapse:collapse; margin-bottom:6px; }
            #print-area .pr-meta td { border:1px solid #000; padding:3px 6px; }
            #print-area .pr-meta .k { background:#f0f0f0; font-weight:bold; white-space:nowrap; width:70px; }
            #print-area table.pr-items { width:100%; border-collapse:collapse; }
            #print-area table.pr-items th, #print-area table.pr-items td { border:1px solid #000; padding:3px 4px; text-align:center; }
            #print-area table.pr-items thead th { background:#eee; }
            #print-area table.pr-items thead { display:table-header-group; } /* 每頁重複表頭 */
            #print-area table.pr-items tr { page-break-inside:avoid; }
            #print-area .pr-ng { color:#000; font-weight:bold; text-decoration:underline; }
            #print-area .pr-sign { margin-top:14px; width:100%; border-collapse:collapse; }
            #print-area .pr-sign td { border:1px solid #000; padding:14px 6px 4px; text-align:center; vertical-align:bottom; height:46px; }
            #print-area .pr-sign .lbl { font-size:11px; color:#333; }
        }

        /* #9 平板友善：觸控裝置/較窄螢幕加大點擊目標與輸入框，不影響桌機格狀輸入效率 */
        @media (max-width:1024px), (pointer:coarse) {
            .table-input { padding:6px 4px; font-size:15px; }
            .s-slot { width:60px; margin:0 4px 6px 0; }
            .s-slot .num-cell { width:58px; }
            .okng-btn { display:inline-block; min-width:54px; padding:8px 4px; }
            .pcs-verdict { min-width:54px; padding:8px 4px; }
            #items-table td, #items-table th { padding:6px 4px; }
            .add-reading, .item-note, .remove-row, .remove-sub { font-size:15px; padding:2px 4px; }
            .btn-sm, .btn-xs { padding:7px 12px; font-size:14px; }
        }
        .ng-value { background:#f2dede !important; color:#a94442; font-weight:bold; }
        .ok-value { color:#3c763d; }
        .remove-row { color:#d9534f; cursor:pointer; }
        .muted-help { color:#999; font-size:12px; }
        .result-box { background:#f9f9f9; border:1px solid #e5e5e5; border-radius:5px; padding:12px; }
        .history-row td { font-size:13px; }
        .std-toggle { font-size:13px; }
        /* 設定下拉選單往左展開，避免被視窗右緣遮蔽 */
        .page-title .dropdown-menu { right:0 !important; left:auto !important; }
    </style>
</head>
<body class="<?php echo $isPopup ? 'popup-mode' : 'nav-sm'; ?>">
<div class="container body">
    <div class="main_container">
        <?php if (!$isPopup) include '../partPage/sideAndTopBarMenu.html'; ?>

        <div class="<?php echo $isPopup ? 'col-md-12' : 'right_col'; ?>" role="main"<?php echo $isPopup ? ' style="width:100%;float:none;padding:15px;"' : ''; ?>>
            <div class="page-title">
                <div class="title_left"><h3>品管檢驗 <small>檢驗結果輸入（設定＋輸入合一）</small></h3></div>
                <div class="title_right">
                    <div class="pull-right">
                        <button class="btn btn-default btn-sm" id="btn-print"><i class="fa fa-print"></i> 列印</button>
                        <button class="btn btn-default btn-sm" id="btn-csv"><i class="fa fa-file-excel-o"></i> 匯出CSV</button>
                        <button class="btn btn-default btn-sm" id="btn-history"><i class="fa fa-history"></i> 歷史紀錄</button>
                        <div class="btn-group">
                            <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown"><i class="fa fa-cog"></i> 設定 <span class="caret"></span></button>
                            <ul class="dropdown-menu dropdown-menu-right">
                                <!-- 前三項需「管理檢驗設定」(qc_manage_settings)；抽樣規則獨立權限(qc_manage_sampling，主管固定可用)，載入權限後顯示 -->
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-tool-setting"><i class="fa fa-wrench"></i> 量具設定</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-special-setting"><i class="fa fa-cog"></i> 幾何公差管理</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-template-setting"><i class="fa fa-list-alt"></i> 通用樣板管理</a></li>
                                <li class="sampling-menu-item" style="display:none;"><a href="#" id="btn-sampling-setting"><i class="fa fa-list-ol"></i> 抽樣規則設定</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-qadept-setting"><i class="fa fa-sitemap"></i> 異常單回覆部門設定</a></li>
                                <li><a href="#" id="btn-qadecide-setting"><i class="fa fa-gavel"></i> 異常單處置決策設定</a></li>
                                <li class="divider"></li>
                                <li><a href="#" id="btn-perm-setting"><i class="fa fa-key"></i> 權限設定（角色）</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="clearfix"></div>

            <div class="proto-banner" id="mode-banner"></div>
            <div id="no-view-hint" class="alert alert-danger" style="display:none;"></div>

            <div class="row">
                <div class="col-md-12">
                    <div class="x_panel">
                        <div class="x_content">

                            <!-- ① 選擇待驗（示範模式才顯示搜尋；正式由待驗清單帶入） -->
                            <div id="step-search" style="border-bottom:1px solid #eee; padding-bottom:12px; margin-bottom:12px; display:none;">
                                <h4>① 選擇待驗項目</h4>
                                <div class="row">
                                    <div class="col-md-5">
                                        <div class="input-group">
                                            <input type="text" id="search-kw" class="form-control" placeholder="示範模式：點此顯示範例料號">
                                            <span class="input-group-btn"><button class="btn btn-primary" id="btn-search">搜尋</button></span>
                                        </div>
                                        <div id="search-results" style="border:1px solid #eee; margin-top:4px;"></div>
                                    </div>
                                </div>
                            </div>

                            <div id="selected-info" class="well well-sm" style="display:none; border-left:5px solid #337ab7;"></div>

                            <div id="main-area" style="display:none;">
                                <div id="no-part-hint" class="alert alert-danger" style="display:none;">
                                    <i class="fa fa-exclamation-circle"></i> 此料件尚未建立料號，請先到 <b>基本設定</b> 建立料號後再檢驗（暫無法儲存）。
                                </div>
                                <div id="no-perm-hint" class="alert alert-danger" style="display:none;">
                                    <i class="fa fa-ban"></i> 您沒有<b>「填寫檢驗表單」</b>權限，僅能檢視。請洽管理員於 設定 → 權限設定（角色 → QC 功能）開通。
                                </div>
                                <div id="edit-mode-banner" class="alert alert-info" style="display:none;">
                                    <i class="fa fa-pencil"></i> <b>修改模式</b>：正在修改歷程 qc_form_id=<span id="edit-form-id"></span>，儲存時需填修改原因，存檔後此筆會自動回鎖。
                                    <button class="btn btn-xs btn-default pull-right" id="btn-exit-edit">取消修改，回到新檢驗</button>
                                </div>
                                <h4>② 批次（數量分批 / 退回重做歷程）</h4>
                                <div id="batch-bar" style="margin-bottom:6px;"></div>
                                <div id="batch-history" style="margin-bottom:12px;"></div>

                                <h4 style="margin-top:18px;">③ 檢驗項目（邊驗邊設定）</h4>
                                <ul class="nav nav-tabs" id="proc-tabs" style="margin-bottom:10px;"></ul>

                                <div id="no-std-hint" class="alert alert-warning" style="display:none;">
                                    <i class="fa fa-exclamation-triangle"></i> 此料號／製程<b>尚未設定檢驗標準</b>。可直接在下表新增項目，或
                                    <button class="btn btn-xs btn-info" id="btn-import-tpl"><i class="fa fa-download"></i> 匯入通用樣板</button>
                                    後微調。<b>勾選下方「同步更新標準」存檔後即成此料號標準，下次自動帶出。</b>
                                </div>

                                <div class="table-responsive" style="max-height:55vh; overflow-y:auto; overflow-x:auto;">
                                    <table class="table table-bordered table-striped" id="items-table">
                                        <thead><tr>
                                            <th width="44">編號<br><a href="#" id="btn-code-mode" style="font-weight:normal;font-size:11px;white-space:nowrap;" title="切換編號顯示方式（A、B、C… ↔ 1、2、3…）"></a></th>
                                            <th width="104">檢驗項目</th>
                                            <th width="66">標準值</th>
                                            <th width="58">上公差</th>
                                            <th width="58">下公差</th>
                                            <th width="104">量具</th>
                                            <th width="60">結果型態</th>
                                            <th>實測值<div id="sample-nums" style="font-weight:normal;"></div></th>
                                            <th width="50" title="備註 / 刪除"></th>
                                        </tr></thead>
                                        <tbody id="items-body"></tbody>
                                        <tfoot><tr id="verdict-row">
                                            <td colspan="7" class="text-right" style="font-weight:bold;">判定結果 <span class="muted-help">(該PCS任一項NG即自動NG，點擊可手動修改，雙擊恢復自動)</span></td>
                                            <td class="sample-cell" id="verdict-cells"></td>
                                            <td></td>
                                        </tr></tfoot>
                                    </table>
                                </div>
                                <div style="margin-bottom:16px;">
                                    <button class="btn btn-success btn-sm" id="btn-add-row"><i class="fa fa-plus"></i> 新增檢驗項目</button>
                                    <button class="btn btn-default btn-sm" id="btn-import-tpl2"><i class="fa fa-download"></i> 匯入通用樣板</button>
                                    <label class="std-toggle pull-right" style="margin-top:6px;">
                                        <input type="checkbox" id="chk-save-std" checked> 存檔時同步更新此料號的檢驗標準
                                    </label>
                                </div>

                                <h4>④ 判定與處置</h4>
                                <div class="result-box">
                                    <div class="row">
                                        <div class="col-md-3 form-group">
                                            <label>本批送驗數</label>
                                            <input type="number" class="form-control input-sm" id="inp-qty" value="0">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>抽驗數</label>
                                            <input type="number" class="form-control input-sm" id="inp-sample" value="5">
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>不良數（判定結果NG自動加總）</label>
                                            <input type="number" class="form-control input-sm" id="inp-ng" value="0" readonly>
                                        </div>
                                        <div class="col-md-3 form-group">
                                            <label>整體判定</label>
                                            <div>
                                                <label class="radio-inline"><input type="radio" name="judge" value="OK" checked> 合格</label>
                                                <label class="radio-inline"><input type="radio" name="judge" value="NG"> 不良</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>處置 / 備註</label>
                                        <textarea class="form-control" id="inp-remark" rows="2" placeholder="例：尺寸 A 超差，退回重做…"></textarea>
                                    </div>
                                    <div class="text-right">
                                        <button class="btn btn-default" id="btn-cancel"><i class="fa fa-times"></i> 取消</button>
                                        <button class="btn btn-warning" id="btn-redo"><i class="fa fa-undo"></i> 判退回重做（本批新增複驗）</button>
                                        <button class="btn btn-primary" id="btn-save"><i class="fa fa-save"></i> 儲存檢驗結果</button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$isPopup) include '../partPage/footer.html'; ?>
    </div>
</div>

<!-- 樣板選擇 Modal（示意：正式版接通用樣板） -->
<div class="modal fade" id="tplModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">選擇通用樣板</h4></div>
        <div class="modal-body"><div class="list-group" id="tpl-list"></div></div>
    </div></div>
</div>

<!-- NG 後詢問是否開立異常單 Modal（必選：開立 或 填原因不開立） -->
<div class="modal fade" id="ngAskModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#d9534f;color:#fff;border-radius:6px 6px 0 0;">
            <h4 class="modal-title"><i class="fa fa-exclamation-triangle"></i> 檢驗判定不良（NG）</h4>
        </div>
        <div class="modal-body">
            <p id="ng-ask-info" style="font-size:15px;"></p>
            <div class="text-center" style="margin:16px 0;">
                <button class="btn btn-danger btn-lg" id="btn-ng-open" style="margin-right:14px;"><i class="fa fa-file-text-o"></i> 開立異常單</button>
                <button class="btn btn-default btn-lg" id="btn-ng-skip">不開立（填原因）</button>
            </div>
            <div id="ng-skip-area" style="display:none;">
                <label>不開立異常單的原因（必填，會記錄於檢驗歷程）</label>
                <textarea class="form-control" id="ng-skip-reason" rows="2" placeholder="例：輕微偏差已現場處置、客戶允收…"></textarea>
                <div class="text-right" style="margin-top:8px;">
                    <button class="btn btn-primary btn-sm" id="btn-ng-skip-confirm"><i class="fa fa-check"></i> 確認不開立</button>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 修改紀錄 Modal -->
<div class="modal fade" id="logModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-history"></i> 檢驗歷程修改紀錄</h4></div>
        <div class="modal-body" id="log-modal-body"></div>
    </div></div>
</div>

<!-- 刪除角色 Modal（先列出該角色人員，可轉移角色，需輸入大寫 Y） -->
<div class="modal fade" id="roleDeleteModal" tabindex="-1" role="dialog" style="z-index:10600;">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title text-danger"><i class="fa fa-trash"></i> 刪除角色：<span id="del-role-name"></span></h4></div>
        <div class="modal-body">
            <div id="del-role-users" style="margin-bottom:10px;"></div>
            <div class="form-group" id="del-transfer-wrap" style="display:none;">
                <label>將上列人員轉移到角色：</label>
                <select id="del-transfer-role" class="form-control"></select>
                <p class="help-block">選「不轉移」則僅移除這些人員的此角色指派。</p>
            </div>
            <div class="form-group">
                <label class="text-danger">此操作無法復原。確認請輸入大寫 <b>Y</b>：</label>
                <input id="del-confirm-y" class="form-control" maxlength="1" style="width:80px;" autocomplete="off">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-danger" id="btn-confirm-del-role"><i class="fa fa-trash"></i> 確定刪除角色</button>
        </div>
    </div></div>
</div>

<!-- 權限設定 Modal（角色↔功能；沿用既有 roles 框架） -->
<div class="modal fade" id="permModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-key"></i> 權限設定（角色 → QC 功能）</h4></div>
        <div class="modal-body">
            <p class="text-muted" style="font-size:13px;">勾選各角色可使用的 QC 功能。<b>人員對應角色請至「人員權限(user_permissions)」頁面設定。</b>系統管理員角色預設擁有全部權限。</p>
            <div class="row">
                <div class="col-md-4">
                    <label>角色</label>
                    <div style="margin-bottom:6px;">
                        <button class="btn btn-xs btn-success" id="btn-add-role"><i class="fa fa-plus"></i> 新增</button>
                        <button class="btn btn-xs btn-default" id="btn-rename-role" disabled><i class="fa fa-pencil"></i> 重新命名</button>
                        <button class="btn btn-xs btn-danger" id="btn-delete-role" disabled><i class="fa fa-trash"></i> 刪除</button>
                    </div>
                    <div class="list-group" id="perm-role-list" style="max-height:320px;overflow:auto;"></div>
                </div>
                <div class="col-md-8">
                    <label>此角色可用的 QC 功能</label>
                    <div id="perm-feature-box" style="border:1px solid #eee;padding:10px;min-height:200px;">
                        <p class="text-muted">← 請先選擇角色</p>
                    </div>
                    <div class="text-right" style="margin-top:10px;">
                        <button class="btn btn-primary btn-sm" id="btn-save-perm" disabled><i class="fa fa-save"></i> 儲存此角色設定</button>
                    </div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 量具設定 Modal（種類 + 編號；與 inspection_standard_setting.php 共用資料表） -->
<div class="modal fade" id="toolManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-wrench"></i> 量具設定</h4></div>
        <div class="modal-body">
            <div class="row">
                <div class="col-md-5" style="border-right:1px solid #eee;">
                    <h4>1. 量具種類</h4>
                    <form id="tool-cat-form" class="form-inline" style="margin-bottom:10px;">
                        <input type="hidden" id="tc-id">
                        <div class="form-group"><input type="text" id="tc-name" class="form-control input-sm" placeholder="種類名稱 (如: 游標卡尺)" required></div>
                        <button type="submit" class="btn btn-primary btn-sm" id="btn-save-tc">新增</button>
                        <button type="button" class="btn btn-default btn-sm" id="btn-cancel-tc" style="display:none;">取消</button>
                    </form>
                    <div class="list-group" id="tool-cat-list" style="max-height:400px;overflow:auto;"></div>
                </div>
                <div class="col-md-7">
                    <h4>2. 量具編號</h4>
                    <div id="tool-instance-area" style="display:none;">
                        <p class="text-info">當前選擇種類：<strong id="current-cat-name"></strong></p>
                        <div id="ti-nono-hint" class="alert alert-warning" style="display:none;padding:8px 12px;">
                            此種類在「量測儀器校驗管理」已設為<strong>不可設定量具編號</strong>（僅為檢驗方式，如目視），故不提供新增編號。
                            如需開放，請至該頁工具列「類別設定」調整。
                        </div>
                        <form id="tool-inst-form" class="form-inline" style="margin-bottom:10px;">
                            <input type="hidden" id="ti-id"><input type="hidden" id="ti-cat-id">
                            <div class="form-group"><input type="text" id="ti-no" class="form-control input-sm" placeholder="量具編號 (如: C01)" required></div>
                            <button type="submit" class="btn btn-success btn-sm" id="btn-save-ti">新增編號</button>
                            <button type="button" class="btn btn-default btn-sm" id="btn-cancel-ti" style="display:none;">取消</button>
                        </form>
                        <table class="table table-striped table-bordered table-condensed">
                            <thead><tr><th>編號</th><th width="80">操作</th></tr></thead>
                            <tbody id="tool-inst-list"></tbody>
                        </table>
                    </div>
                    <div id="tool-instance-empty" class="text-muted" style="padding-top:50px;text-align:center;">
                        <i class="fa fa-arrow-left"></i> 請先從左側選擇一個量具種類
                    </div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 量具取代並刪除 Modal -->
<div class="modal fade" id="toolReplaceModal" tabindex="-1" role="dialog" style="z-index:10600;">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title">取代並刪除量具</h4></div>
        <div class="modal-body">
            <p class="text-danger">您即將刪除：<strong id="replace-old-name"></strong></p>
            <p>請選擇要將現有資料轉移到哪個量具種類：</p>
            <input type="hidden" id="replace-old-id">
            <select id="replace-new-id" class="form-control"></select>
            <p class="help-block"><small>執行後，舊種類將被刪除，所有關聯的檢驗項目與量具編號將移至新種類。</small></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-danger" id="btn-confirm-replace">確認取代並刪除</button>
        </div>
    </div></div>
</div>

<!-- 幾何公差管理 Modal -->
<div class="modal fade" id="specialItemManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-cog"></i> 幾何公差與特殊項目設定</h4></div>
        <div class="modal-body">
            <div class="well well-sm">
                <form id="special-item-form" class="form-inline">
                    <input type="hidden" id="si-id">
                    <div class="form-group"><input type="text" id="si-name" class="form-control input-sm" placeholder="名稱 (如: 真圓度)" required></div>
                    <div class="form-group"><input type="text" id="si-symbol" class="form-control input-sm" placeholder="符號 (如: ○)" size="5"></div>
                    <div class="form-group"><input type="text" id="si-code" class="form-control input-sm" placeholder="代碼/英文" size="10"></div>
                    <button type="submit" class="btn btn-primary btn-sm" id="btn-save-si">新增</button>
                    <button type="button" class="btn btn-default btn-sm" id="btn-cancel-si" style="display:none;">取消</button>
                </form>
            </div>
            <div class="list-group" id="manage-special-list" style="max-height:300px;overflow:auto;"></div>
        </div>
    </div></div>
</div>

<!-- 通用樣板管理 Modal -->
<div class="modal fade" id="templateManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-list-alt"></i> 通用檢驗樣板管理</h4></div>
        <div class="modal-body">
            <div class="alert alert-info" style="font-size:13px;">請先在主畫面「③ 檢驗項目」表格填好項目，再點「從當前表格建立樣板」。點「編輯」會把樣板載入主畫面表格修改後更新。</div>
            <div class="input-group" style="margin-bottom:10px;">
                <input type="text" id="new-template-name" class="form-control" placeholder="輸入新樣板名稱 (例如: 一般車件標準)">
                <span class="input-group-btn">
                    <button class="btn btn-default" type="button" id="btn-cancel-edit-template" style="display:none;">取消編輯</button>
                    <button class="btn btn-success" type="button" id="btn-create-template">從當前表格建立樣板</button>
                </span>
            </div>
            <hr style="margin:10px 0;">
            <div class="list-group" id="template-list"></div>
        </div>
    </div></div>
</div>

<!-- 抽樣規則設定 Modal -->
<div class="modal fade" id="samplingRuleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-list-ol"></i> 抽樣規則設定</h4></div>
        <div class="modal-body">
            <p class="text-muted" style="font-size:13px;">依「本批送驗數」落在哪個範圍決定建議抽驗數（載入待驗項目時自動帶入）。</p>
            <form id="rule-form" class="form-inline well well-sm">
                <input type="hidden" id="rule-id">
                <input type="number" id="rule-min" class="form-control input-sm" placeholder="最小數量" style="width:90px;" required>
                ~
                <input type="number" id="rule-max" class="form-control input-sm" placeholder="最大數量" style="width:90px;" required>
                ：抽
                <input type="number" id="rule-sample" class="form-control input-sm" placeholder="數量" style="width:70px;" required>
                <button type="submit" class="btn btn-primary btn-sm" id="btn-save-rule">新增</button>
                <button type="button" class="btn btn-default btn-sm" id="btn-cancel-rule" style="display:none;">取消</button>
            </form>
            <table class="table table-striped table-bordered table-condensed">
                <thead><tr><th>數量範圍</th><th>抽驗數</th><th width="110">操作</th></tr></thead>
                <tbody id="rule-list"></tbody>
            </table>
        </div>
    </div></div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<?php include '../QA/qa_abnormal_modal.php'; // 共用「開立品質異常單」跳窗元件（QAAbnormalModal，含 openEdit 修改模式） ?>

<!-- 異常單回覆部門設定 Modal（自 IR_Track 移入 2026-07-06；開單跳窗的「回覆部門」清單來源） -->
<div class="modal fade" id="qaDeptCfgModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-sitemap"></i> 品質異常單 — 可用部門設定</h4>
        </div>
        <div class="modal-body" style="max-height:64vh;overflow-y:auto;">
            <p class="text-muted" style="font-size:12.5px;"><i class="fa fa-info-circle"></i> 勾選的部門會出現在「開立異常單」跳窗的回覆部門清單並預設勾選；右側模式決定可指定人員的範圍。</p>
            <div id="qadept_cfg_container"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="btn-qadept-save">儲存設定</button>
        </div>
    </div></div>
</div>

<!-- 異常單處置決策設定 Modal（品管單位部門／首要決策者／最終決策者；代理人沿用 HR 使用者代理設定） -->
<div class="modal fade" id="qaDecideCfgModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-gavel"></i> 異常單處置決策設定</h4>
        </div>
        <div class="modal-body" style="max-height:66vh;overflow-y:auto;">
            <div class="form-group">
                <label>品管單位的部門 <small class="text-muted">（屬這些部門的可判定人員，系統會預設建議為首要決策者）</small></label>
                <div id="qadc_depts" style="max-height:150px;overflow-y:auto;border:1px solid #eee;border-radius:4px;padding:6px 10px;display:grid;grid-template-columns:1fr 1fr;"></div>
            </div>
            <div class="form-group">
                <label>首要決策者 <small class="text-muted">（異常單開立後優先送其判定）</small></label>
                <select class="form-control input-sm" id="qadc_primary"><option value="">請選擇...</option></select>
                <div id="qadc_primary_dep" style="font-size:12px;color:#5a6b7b;margin-top:3px;"></div>
            </div>
            <div class="form-group">
                <label>最終決策者 <small class="text-muted">（首要決策者判定「需最終裁決」或處置含「轉總經理裁示」時通知；裁決寫入總經理裁示欄位）</small></label>
                <select class="form-control input-sm" id="qadc_final"><option value="">請選擇...</option></select>
                <div id="qadc_final_dep" style="font-size:12px;color:#5a6b7b;margin-top:3px;"></div>
            </div>
            <div class="form-group">
                <label>次要決策者 <small class="text-muted">（首要決策者「請假」時，與首要決策者代理人一同收到判定通知、可代為判定；勾選並以 ↑↓ 排序。代理人同時具判定功能者，是否列為次要決策者在此設定）</small></label>
                <div id="qadc_secondary" style="border:1px solid #eee;border-radius:4px;padding:6px 10px;max-height:200px;overflow-y:auto;"></div>
            </div>
            <p class="text-muted" style="font-size:12px;">
                <i class="fa fa-info-circle"></i> 候選名單＝具「勾選/回覆異常處置」功能之人員；<b>屬品管單位者標示【品管】並建議設為首要決策者</b>。
                代理人沿用「人資設定 → 使用者代理設定」（user_delegate，含代理期間與順序），此處僅顯示不可修改。
                決策者當日尚有行程時，系統會同時通知其代理人（附行程時段），任一人判定後其他人即無須處理。
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qadc_save"><i class="fa fa-save"></i> 儲存設定</button>
        </div>
    </div></div>
</div>

<!-- 異常單處置決策 Modal（首要判定 / 最終裁決；由決策通知點入 ?decide_abnormal=N） -->
<div class="modal fade" id="qaDecideModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#c77c1a;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-gavel"></i> <span id="qadm_title">處置判定</span> — <span id="qadm_no"></span></h4>
        </div>
        <div class="modal-body" style="max-height:66vh;overflow-y:auto;">
            <table class="table table-bordered" style="font-size:13px;margin-bottom:10px;">
                <tr><th style="width:100px;background:#f8f9fa;">開單人</th><td id="qadm_creator"></td></tr>
                <tr><th style="background:#f8f9fa;">來源</th><td id="qadm_src"></td></tr>
                <tr><th style="background:#f8f9fa;">異常現象</th><td id="qadm_phen" style="white-space:pre-line;"></td></tr>
                <tr><th style="background:#f8f9fa;">原因說明</th><td id="qadm_detail" style="white-space:pre-line;"></td></tr>
                <tr id="qadm_prim_row" style="display:none;"><th style="background:#f8f9fa;">首要判定</th><td id="qadm_prim"></td></tr>
            </table>
            <div class="form-group">
                <label id="qadm_opt_label">處置方式 <span style="color:#d9534f;">*</span></label>
                <div id="qadm_opts" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>
            <div class="form-group">
                <label id="qadm_note_label">處置說明</label>
                <textarea class="form-control" id="qadm_note" rows="3"></textarea>
            </div>
            <div class="form-group" id="qadm_final_wrap">
                <label style="font-weight:normal;cursor:pointer;"><input type="checkbox" id="qadm_need_final"> 需送「最終決策者」裁決 <small class="text-muted">（勾選「轉總經理裁示」時自動送出）</small></label>
            </div>
            <div style="text-align:right;">
                <a id="qadm_view" target="_blank" style="font-size:12.5px;">開啟異常單完整內容 <i class="fa fa-external-link"></i></a>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qadm_submit"><i class="fa fa-check"></i> 送出判定</button>
        </div>
    </div></div>
</div>

<!-- 異常單修改請求 Modal（無修改權限時：通知主管要求開放修改，原因必填） -->
<div class="modal fade" id="qaEditReqModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#c77c1a;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-lock"></i> 無修改權限 — 異常單 <span id="qaer-no"></span></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <p>您目前沒有修改此異常單的權限（僅 管理員／QC主管／開單人／共同編輯者 可直接修改）。</p>
            <p><b>是否通知主管，要求開放修改此異常單？</b><br>
               <small class="text-muted">主管核准後僅您本人可修改，其他使用者仍不可修改。</small></p>
            <div class="form-group">
                <label>修改原因 <span style="color:#d9534f;">*</span></label>
                <textarea class="form-control" id="qaer-reason" rows="3" maxlength="255" placeholder="請說明需要修改此異常單的原因（必填）..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-warning" id="qaer-send"><i class="fa fa-paper-plane"></i> 通知主管要求開放修改</button>
        </div>
    </div></div>
</div>

<!-- 異常單修改請求核准 Modal（主管由通知點入，可快速開放修改） -->
<div class="modal fade" id="qaEditApproveModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-unlock-alt"></i> 異常單修改請求 — <span id="qaea-no"></span></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <table class="table table-bordered" style="margin-bottom:10px;font-size:13px;">
                <tr><th style="width:110px;background:#f8f9fa;">請求人</th><td id="qaea-requester"></td></tr>
                <tr><th style="background:#f8f9fa;">修改原因</th><td id="qaea-reason" style="white-space:pre-line;"></td></tr>
                <tr><th style="background:#f8f9fa;">提出時間</th><td id="qaea-time"></td></tr>
                <tr><th style="background:#f8f9fa;">狀態</th><td id="qaea-status"></td></tr>
            </table>
            <p class="text-muted" style="font-size:12.5px;"><i class="fa fa-info-circle"></i> 「開放修改」後，<b>僅提出請求的使用者本人</b>可修改此異常單，其他使用者不可修改；所有修改皆會留下編輯記錄。</p>
        </div>
        <div class="modal-footer">
            <a class="btn btn-default pull-left" id="qaea-view" target="_blank"><i class="fa fa-file-text-o"></i> 檢視異常單</a>
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
            <button type="button" class="btn btn-success" id="qaea-approve"><i class="fa fa-unlock"></i> 開放修改</button>
        </div>
    </div></div>
</div>

<script>
$(function(){
    'use strict';
    var QA_API = '../../src/store/store_QA_Abnormal_API.php';
    var qs = new URLSearchParams(location.search);
    var qaEditAb  = parseInt(qs.get('edit_abnormal') || '', 10);
    var qaEditReq = parseInt(qs.get('edit_request') || '', 10);

    // 由公告/通知「編輯」或核准通知進入：開啟指定異常單的修改畫面（先檢查權限）
    if (qaEditAb){
        $.post(QA_API, {action:'check_edit_perm', id:qaEditAb}, function(r){
            if (!r || !r.success){ alert('無法檢查異常單修改權限：' + ((r && r.message) || '')); return; }
            if (r.can_edit){
                QAAbnormalModal.openEdit(qaEditAb, { title_suffix: r.order_no || '' });
            } else {
                $('#qaer-no').text(r.order_no || ('#' + qaEditAb));
                $('#qaer-reason').val('');
                $('#qaEditReqModal').data('oid', qaEditAb).modal('show');
            }
        }, 'json');
    }

    // ============ 處置決策（首要判定 / 最終裁決；?decide_abnormal=N 由決策通知點入） ============
    var qaDecideAb = parseInt(qs.get('decide_abnormal') || '', 10);
    var qadmStage = 'primary';
    var DISP_OPTS = ['特採','報廢','重工','需矯正','轉總經理裁示'];
    var GM_OPTS = ['特採','重工','報廢','需矯正'];
    function qadmRenderOpts(list, checkedCsv){
        var checked = String(checkedCsv || '').split(/[,、]/).map(function(s){ return s.trim(); });
        $('#qadm_opts').html(list.map(function(o){
            return '<label style="display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;font-weight:normal;margin:0;">'
                 + '<input type="checkbox" class="qadm-opt" value="'+o+'" '+(checked.indexOf(o)>=0?'checked':'')+' style="margin:0;"> '+o+'</label>';
        }).join(''));
    }
    if (qaDecideAb){
        $.post(QA_API, {action:'get_decide_context', id:qaDecideAb}, function(r){
            if (!r || !r.success){ alert((r && r.message) || '載入決策情境失敗'); return; }
            if (!r.stage){
                if (confirm('此異常單目前沒有待決策事項（可能已完成判定）。要開啟異常單檢視頁嗎？')) window.open('../QA/qa_abnormal_view.php?id=' + qaDecideAb);
                return;
            }
            if (!r.allowed){ alert('您不是此階段的決策者或其代理人，無法判定。'); return; }
            qadmStage = r.stage;
            var o = r.order;
            $('#qadm_no').text(o.no || '');
            $('#qadm_creator').text(o.created_by_name || '');
            $('#qadm_src').text(o.source_desc || '');
            $('#qadm_phen').text(o.phenomenon || '');
            $('#qadm_detail').text(o.defect_detail || '');
            $('#qadm_view').attr('href', '../QA/qa_abnormal_view.php?id=' + o.id);
            if (r.stage === 'final'){
                $('#qadm_title').text('最終裁決');
                $('#qadm_opt_label').html('最終裁決 <small class="text-muted">（寫入總經理裁示欄位）</small>');
                $('#qadm_note_label').text('裁決說明');
                $('#qadm_prim_row').show();
                $('#qadm_prim').text((o.disposition || '').replace(/,/g, '、') + (o.disposition_note ? '｜' + o.disposition_note : ''));
                $('#qadm_final_wrap').hide();
                qadmRenderOpts(GM_OPTS, o.gm_decision);
                $('#qadm_note').val(o.gm_note || '');
            } else {
                $('#qadm_title').text('處置判定');
                $('#qadm_opt_label').html('處置方式 <span style="color:#d9534f;">*</span>');
                $('#qadm_note_label').text('處置說明');
                $('#qadm_prim_row').hide();
                $('#qadm_final_wrap').show();
                $('#qadm_need_final').prop('checked', false);
                qadmRenderOpts(DISP_OPTS, o.disposition);
                $('#qadm_note').val(o.disposition_note || '');
            }
            $('#qaDecideModal').data('oid', o.id).modal('show');
        }, 'json');
    }
    $('#qadm_submit').on('click', function(){
        var oid = $('#qaDecideModal').data('oid');
        var picked = $('.qadm-opt:checked').map(function(){ return this.value; }).get().join(',');
        var note = ($('#qadm_note').val() || '').trim();
        var data = { action:'decide', id:oid, stage:qadmStage };
        if (qadmStage === 'final'){
            if (!picked && !note){ alert('請勾選裁決或填寫裁決說明'); return; }
            data.gm_decision = picked; data.gm_note = note;
        } else {
            if (!picked){ alert('請至少勾選一項處置方式'); return; }
            data.disposition = picked; data.disposition_note = note;
            data.need_final = ($('#qadm_need_final').is(':checked') || picked.indexOf('轉總經理裁示') >= 0) ? 1 : 0;
        }
        var $b = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 送出中…');
        $.post(QA_API, data, function(r){
            $b.prop('disabled', false).html('<i class="fa fa-check"></i> 送出判定');
            if (!r || !r.success){ alert((r && r.message) || '送出失敗'); return; }
            $('#qaDecideModal').modal('hide');
            var msg = (qadmStage === 'final' ? '最終裁決已送出。' : '處置判定已送出。') + '相關人員的決策通知已自動結束。';
            if (r.warn) msg += '\n\n⚠ ' + r.warn;
            alert(msg);
        }, 'json').fail(function(){
            $b.prop('disabled', false).html('<i class="fa fa-check"></i> 送出判定');
            alert('連線失敗');
        });
    });

    // ============ 處置決策設定（品管單位部門／首要／最終決策者） ============
    $('#btn-qadecide-setting').on('click', function(e){
        e.preventDefault();
        $.post(QA_API, {action:'get_decision_setting'}, function(r){
            if (!r || !r.success){ alert('載入設定失敗'); return; }
            var qc = r.qc_dept_ids || [];
            // 品管部門已改由全站「組織角色綁定設定」決定（含子部門）→ 本頁一律反灰唯讀（2026-08-03）
            $('#qadc_depts').html((r.departments || []).map(function(d){
                return '<label style="font-weight:normal;margin:0 0 3px;color:#999;cursor:not-allowed;">'
                     + '<input type="checkbox" class="qadc-dept" value="'+d.id+'" '+(qc.indexOf(d.id)>=0?'checked':'')
                     + ' disabled> '+$('<i>').text(d.name).html()+'</label>';
            }).join('')
            + '<div style="grid-column:1/-1;font-size:12px;color:#8a6d45;margin-top:4px;">此項目已統一由'
            + '<a href="../admin/org_role_setting.php" target="_blank"><b>組織角色綁定設定</b></a>'
            + '的「品管部門」決定（含其子部門），僅能在該頁修改。</div>');
            function poolOpts(sel){
                var h = '<option value="">請選擇...</option>';
                (r.pool || []).forEach(function(p){
                    h += '<option value="'+p.id+'" data-dep="'+$('<i>').text((p.deputies||[]).map(function(x){return x.name;}).join('、')).html()+'">'
                       + (p.in_qc ? '【品管】' : '') + $('<i>').text(p.user_cname).html() + '</option>';
                });
                return h;
            }
            $('#qadc_primary').html(poolOpts()).val(String(r.primary || ''));
            $('#qadc_final').html(poolOpts()).val(String(r.final || ''));
            // ── 次要決策者：勾選＋排序（首要/最終本人不可勾；標示其是否為首要/最終的今日代理人）──
            (function renderSecondary(){
                var byId = {};
                (r.pool || []).forEach(function(p){ byId[p.id] = p; });
                var primDeps = (byId[r.primary] ? (byId[r.primary].deputies || []) : []).map(function(d){ return d.id; });
                var finDeps  = (byId[r.final]   ? (byId[r.final].deputies   || []) : []).map(function(d){ return d.id; });
                var sec = (r.secondary || []).filter(function(id){ return byId[id]; });
                var ordered = sec.concat((r.pool || []).map(function(p){ return p.id; }).filter(function(id){ return sec.indexOf(id) < 0; }));
                $('#qadc_secondary').html(ordered.map(function(id){
                    var p = byId[id];
                    var badge = '';
                    if (primDeps.indexOf(id) >= 0) badge += ' <span class="label label-primary">首要今日代理人</span>';
                    if (finDeps.indexOf(id)  >= 0) badge += ' <span class="label label-warning">最終今日代理人</span>';
                    return '<div class="qadc-sec-row" data-id="'+id+'" style="display:flex;align-items:center;gap:8px;padding:3px 0;border-bottom:1px solid #f5f5f5;">'
                         + '<label style="font-weight:normal;margin:0;cursor:pointer;flex:1;">'
                         + '<input type="checkbox" class="qadc-sec-chk" value="'+id+'" '+(sec.indexOf(id)>=0?'checked':'')+'> '
                         + (p.in_qc ? '【品管】' : '') + $('<i>').text(p.user_cname).html() + badge + '</label>'
                         + '<span class="qadc-sec-mv">'
                         + '<button type="button" class="btn btn-xs btn-default qadc-sec-up"><i class="fa fa-arrow-up"></i></button> '
                         + '<button type="button" class="btn btn-xs btn-default qadc-sec-down"><i class="fa fa-arrow-down"></i></button>'
                         + '</span></div>';
                }).join('') || '<span class="text-muted">尚無可判定人員</span>');
                function applySecDisable(){
                    var pid = String($('#qadc_primary').val() || ''), fid = String($('#qadc_final').val() || '');
                    $('#qadc_secondary .qadc-sec-row').each(function(){
                        var id = String($(this).data('id'));
                        var isPF = (id === pid || id === fid);
                        $(this).find('.qadc-sec-chk').prop('disabled', isPF);
                        if (isPF) $(this).find('.qadc-sec-chk').prop('checked', false);
                        $(this).css('opacity', isPF ? .5 : 1).attr('title', isPF ? '首要/最終決策者本人不需列為次要決策者' : '');
                    });
                }
                applySecDisable();
                $('#qadc_primary, #qadc_final').off('change.sec').on('change.sec', applySecDisable);
            })();
            function showDep(sel, box){
                var dep = $(sel + ' option:selected').data('dep') || '';
                $(box).html(sel && $(sel).val() ? ('今日生效代理人：' + (dep || '（無，可至 人資設定→使用者代理設定 指定）')) : '');
            }
            showDep('#qadc_primary', '#qadc_primary_dep'); showDep('#qadc_final', '#qadc_final_dep');
            $('#qadc_primary').off('change.dep').on('change.dep', function(){ showDep('#qadc_primary', '#qadc_primary_dep'); });
            $('#qadc_final').off('change.dep').on('change.dep', function(){ showDep('#qadc_final', '#qadc_final_dep'); });
            // 預設建議：尚未設定首要時，自動帶入第一位屬品管單位的可判定人員
            if (!r.primary){
                var sug = (r.pool || []).filter(function(p){ return p.in_qc; })[0];
                if (sug) { $('#qadc_primary').val(String(sug.id)).trigger('change.dep'); }
            }
            $('#qadc_save').prop('disabled', !r.can_manage).attr('title', r.can_manage ? '' : '僅主管（角色勾選「認定為主管」）可儲存');
            $('#qaDecideCfgModal').modal('show');
        }, 'json');
    });
    // 次要決策者排序（↑↓ 移整列）
    $(document).on('click', '.qadc-sec-up, .qadc-sec-down', function(){
        var $row = $(this).closest('.qadc-sec-row');
        if ($(this).hasClass('qadc-sec-up')) { var $prev = $row.prev('.qadc-sec-row'); if ($prev.length) $row.insertBefore($prev); }
        else { var $next = $row.next('.qadc-sec-row'); if ($next.length) $row.insertAfter($next); }
    });
    $('#qadc_save').on('click', function(){
        var qcDepts = $('.qadc-dept:checked').map(function(){ return parseInt(this.value, 10); }).get();
        var primaryId = $('#qadc_primary').val() || 0, finalId = $('#qadc_final').val() || 0;
        if (primaryId && primaryId === finalId){ alert('首要決策者與最終決策者不可為同一人'); return; }
        var secondary = $('#qadc_secondary .qadc-sec-chk:checked').map(function(){ return parseInt(this.value, 10); }).get();
        var $b = $(this).prop('disabled', true);
        $.post(QA_API, {action:'save_decision_setting', qc_dept_ids: JSON.stringify(qcDepts), primary: primaryId, final: finalId, secondary: JSON.stringify(secondary)}, function(r){
            $b.prop('disabled', false);
            if (!r || !r.success){ alert((r && r.message) || '儲存失敗'); return; }
            $('#qaDecideCfgModal').modal('hide');
            alert('處置決策設定已儲存');
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    // 送出修改請求（原因必填）→ 系統自動通知主管（主管通知內含快速開放修改按鈕）
    $('#qaer-send').on('click', function(){
        var oid = $('#qaEditReqModal').data('oid');
        var reason = ($('#qaer-reason').val() || '').trim();
        if (!reason){ alert('請填寫修改原因（必填）'); $('#qaer-reason').focus(); return; }
        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 送出中…');
        $.post(QA_API, {action:'request_edit', id:oid, reason:reason}, function(r){
            $btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 通知主管要求開放修改');
            if (!r || !r.success){ alert('送出失敗：' + ((r && r.message) || '')); return; }
            $('#qaEditReqModal').modal('hide');
            if (r.no_supervisor){ alert(r.message || '目前尚無角色被勾選「認定為主管」，請洽管理員設定。'); }
            else alert('已通知主管。主管開放修改後，您會收到通知（點通知可直接進入修改畫面）。');
        }, 'json').fail(function(){
            $btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 通知主管要求開放修改');
            alert('連線失敗');
        });
    });

    // 主管由通知點入：載入修改請求並提供快速「開放修改」
    if (qaEditReq){
        $.post(QA_API, {action:'get_edit_request', id:qaEditReq}, function(r){
            if (!r || !r.success){ alert((r && r.message) || '載入修改請求失敗'); return; }
            var d = r.data;
            $('#qaea-no').text(d.abnormal_order_no || '');
            $('#qaea-requester').text(d.requester_name || ('#' + d.requested_by));
            $('#qaea-reason').text(d.reason || '');
            $('#qaea-time').text(d.created_at || '');
            $('#qaea-view').attr('href', '../QA/qa_abnormal_view.php?id=' + d.abnormal_order_id);
            if (d.status === 'approved'){
                $('#qaea-status').html('<span class="label label-success">已開放</span> ' + (d.approver_name ? '（' + d.approver_name + '）' : ''));
                $('#qaea-approve').prop('disabled', true).text('已開放修改');
            } else {
                $('#qaea-status').html('<span class="label label-warning">待處理</span>');
                $('#qaea-approve').prop('disabled', !d.is_supervisor);
                if (!d.is_supervisor) $('#qaea-approve').attr('title', '僅主管可開放修改');
            }
            $('#qaEditApproveModal').data('rid', qaEditReq).modal('show');
        }, 'json');
    }

    // ============ 異常單回覆部門設定（自 IR_Track 移入） ============
    $('#btn-qadept-setting').on('click', function(e){
        e.preventDefault();
        var $c = $('#qadept_cfg_container').html('<span class="text-muted">載入中…</span>');
        $.post(QA_API, { action:'get_all_depts' }, function(r1){
            var depts = (r1 && r1.data) || [];
            $.post(QA_API, { action:'get_dept_config' }, function(r2){
                var cfgMap = {};
                if (r2 && r2.success && r2.config) r2.config.forEach(function(c){ cfgMap[c.id] = c.mode; });
                var h = '';
                depts.forEach(function(d){
                    var on = cfgMap.hasOwnProperty(d.id);
                    var mode = on ? cfgMap[d.id] : 0;
                    h += '<div class="row" style="margin:0;border-bottom:1px solid #f0f0f0;padding:5px 0;">'
                       + '<div class="col-xs-6"><label style="font-weight:normal;margin:0;cursor:pointer;">'
                       + '<input type="checkbox" class="qadept-cfg-chk" value="'+d.id+'" '+(on?'checked':'')+'> '+$('<i>').text(d.name).html()+'</label></div>'
                       + '<div class="col-xs-6"><select class="form-control input-sm qadept-cfg-mode" style="display:'+(on?'block':'none')+';">'
                       + '<option value="0"'+(mode==0?' selected':'')+'>本部門</option>'
                       + '<option value="1"'+(mode==1?' selected':'')+'>含下級部門</option>'
                       + '<option value="2"'+(mode==2?' selected':'')+'>僅下級主管</option>'
                       + '</select></div></div>';
                });
                $c.html(h || '<span class="text-muted">尚無部門資料</span>');
                $('#qaDeptCfgModal').modal('show');
            }, 'json');
        }, 'json');
    });
    $(document).on('change', '.qadept-cfg-chk', function(){
        $(this).closest('.row').find('.qadept-cfg-mode').toggle(this.checked);
    });
    $('#btn-qadept-save').on('click', function(){
        var depts = [];
        $('.qadept-cfg-chk:checked').each(function(){
            depts.push({ dept_id: $(this).val(), mode: $(this).closest('.row').find('.qadept-cfg-mode').val() || 0 });
        });
        var $b = $(this).prop('disabled', true);
        $.post(QA_API, { action:'save_dept_config', depts: JSON.stringify(depts) }, function(res){
            $b.prop('disabled', false);
            if (res && res.success){ $('#qaDeptCfgModal').modal('hide'); alert('可用部門設定已儲存'); }
            else alert('儲存失敗：' + ((res && res.message) || ''));
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    // 開放修改（僅主管；核准後通知請求者，且僅該使用者可修改）
    $('#qaea-approve').on('click', function(){
        var rid = $('#qaEditApproveModal').data('rid');
        if (!confirm('確認開放修改？（僅提出請求的使用者本人可修改此異常單）')) return;
        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
        $.post(QA_API, {action:'approve_edit_request', id:rid}, function(r){
            if (!r || !r.success){
                $btn.prop('disabled', false).html('<i class="fa fa-unlock"></i> 開放修改');
                alert('開放失敗：' + ((r && r.message) || ''));
                return;
            }
            $btn.html('<i class="fa fa-check"></i> 已開放修改');
            $('#qaea-status').html('<span class="label label-success">已開放</span>');
            alert('已開放修改並通知提出請求的使用者。');
        }, 'json').fail(function(){
            $btn.prop('disabled', false).html('<i class="fa fa-unlock"></i> 開放修改');
            alert('連線失敗');
        });
    });
});
</script>
<script>
$(function(){
    'use strict';
    var API = location.pathname; // 後端就是本檔
    // #3 CSRF：自動把 session token 夾帶進本頁所有 POST（含 $.post 物件型 data 與字串型 data）
    var CSRF = <?php echo json_encode($CSRF, JSON_UNESCAPED_SLASHES); ?>;
    $.ajaxPrefilter(function(opts){
        var m = (opts.type || opts.method || 'GET').toUpperCase();
        if (m !== 'POST') return;
        if (typeof opts.data === 'string'){
            if (opts.data.indexOf('csrf=') === -1) opts.data += (opts.data ? '&' : '') + 'csrf=' + encodeURIComponent(CSRF);
        } else if (opts.data && typeof opts.data === 'object' && !(opts.data instanceof FormData)){
            if (opts.data.csrf === undefined) opts.data.csrf = CSRF;
        } else if (opts.data == null){
            opts.data = { csrf: CSRF };
        }
    });
    // #4 自動存草稿狀態指示（右下角）
    $('body').append('<span id="draft-status" style="position:fixed;right:14px;bottom:12px;background:#5cb85c;color:#fff;padding:4px 12px;border-radius:14px;font-size:12px;z-index:9999;display:none;box-shadow:0 1px 4px rgba(0,0,0,.3);"></span>');
    var TOOLS = ['卡尺','分厘卡','投影機','三次元','針規','目視'];
    // #10：量具實例(Tool_No)清單，供「量具下拉」與「＋加量測」使用。measure_method 由類型自動帶。
    var TOOL_INSTANCES = []; // [{id:'7', no:'CMM-01', cat:'三次元'}]
    function loadToolInstances(){
        $.post(API, { action:'get_tool_manage_data' }, function(res){
            if(!res || !res.success) return;
            var cats={}; (res.categories||[]).forEach(function(c){ cats[c.QC_Tool_List_id]=c.QC_Tool; });
            TOOL_INSTANCES = (res.tools||[]).map(function(t){ return { id:String(t.Tool_id), no:t.Tool_No, cat:cats[t.QC_Tool_List_id]||'' }; });
            refreshToolSelects();
        }, 'json');
    }
    // 量具實例下拉（值=Tool_id；顯示「類型 / Tool_No」）
    function toolInstOptions(selId){
        var h='<option value="">—</option>';
        TOOL_INSTANCES.forEach(function(t){
            h+='<option value="'+t.id+'" '+(String(selId)===t.id?'selected':'')+'>'+esc((t.cat?t.cat+' / ':'')+t.no)+'</option>';
        });
        return h;
    }
    // 依「類型名稱」找該類型的第一支實例(供舊資料/標準以類型名帶入時預選)
    function firstInstOfCat(catName){
        if(!catName) return '';
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].cat===catName) return TOOL_INSTANCES[i].id; }
        return '';
    }
    function resolvePrimaryToolId(it){
        if(it && it.tool_id!=null && it.tool_id!=='') return String(it.tool_id);
        if(it && it.tool) return firstInstOfCat(it.tool);
        return '';
    }
    // 實例載入後，重繪所有量具下拉並回填原本要選的量具(先看 data-tid 實例，其次 data-tcat 類型首支)
    function refreshToolSelects(){
        $('#items-body').find('select.f-tool, select.f-tool2').each(function(){
            var $s=$(this);
            var want=$s.attr('data-tid') || ($s.val()||'');
            if(!want){ var cat=$s.attr('data-tcat'); if(cat) want=firstInstOfCat(cat); }
            $s.html(toolInstOptions(want)).val(want);
            updateToolLabel($s);
        });
    }
    // 把選到的量具「類型 / 編號」顯示在下拉下方(自動換列)，避免撐寬欄位或被遮
    function updateToolLabel($sel){
        var txt=$sel.find('option:selected').text();
        if(txt==='—') txt='';
        $sel.closest('td').find('.tool-sel-label').first().text(txt);
    }
    $('#items-body').on('change','.f-tool,.f-tool2', function(){ updateToolLabel($(this)); });
    var MOCK_TPL = [
        { name:'一般車件 5 項', items:[
            {name:'外徑 ⌀', std:'12.00', up:'0.02', lo:'-0.02', tool:'分厘卡', type:'NUM'},
            {name:'總長', std:'45.0', up:'0.1', lo:'-0.1', tool:'卡尺', type:'NUM'},
            {name:'內孔 ⌀', std:'6.00', up:'0.03', lo:'0', tool:'針規', type:'NUM'},
            {name:'外觀', std:'無毛邊/刮傷', up:'', lo:'', tool:'目視', type:'OKNG'},
            {name:'倒角', std:'C0.5', up:'', lo:'', tool:'目視', type:'OKNG'}
        ]},
        { name:'齒輪件 3 項', items:[
            {name:'外徑 ⌀', std:'30.00', up:'0.05', lo:'-0.05', tool:'三次元', type:'NUM'},
            {name:'齒厚', std:'2.50', up:'0.02', lo:'-0.02', tool:'三次元', type:'NUM'},
            {name:'外觀', std:'無缺齒', up:'', lo:'', tool:'目視', type:'OKNG'}
        ]}
    ];

    var ctx = null;                       // 後端帶回的情境
    var state = { sampleN:5, batches:[], curBatch:0, processes:[], curProc:0, demo:false, is_supervisor:false, can_fill:true, canManageSettings:false, canManageSampling:false, canView:true, editFormId:null };

    function getFid(){ return new URLSearchParams(location.search).get('bom_ing_fid'); }

    // 量具/幾何公差/樣板依 qc_manage_settings 顯示；抽樣規則獨立（主管或 qc_manage_sampling）
    function applyMenuPerms(){
        $('.setting-menu-item').toggle(!!state.canManageSettings);
        $('.sampling-menu-item').toggle(!!state.canManageSampling);
    }

    // ============ 初始化 ============
    // 先取本人 QC 權限（示範模式也需要，用來決定設定選單是否顯示）
    $.post(API, { action:'get_my_perms' }, function(res){
        if(!res || !res.success) return;
        state.can_fill = res.can_fill !== false;
        state.is_supervisor = !!res.is_supervisor;
        state.canManageSettings = !!res.can_manage_settings;
        state.canManageSampling = !!res.can_manage_sampling;
        state.canView = !!res.can_view;
        applyMenuPerms();
        if (!state.canView && state.demo){
            $('#no-view-hint').html('<i class="fa fa-ban"></i> 您沒有檢閱檢驗表的權限，請洽管理員於 設定 → 權限設定（角色 → QC 功能）開通「唯讀檢閱」').show();
            $('#step-search').hide();
        }
    }, 'json');
    loadToolInstances(); // #10：載入量具實例供量具下拉/加量測

    var fid = getFid();
    if (fid) {
        $('#mode-banner').html('<i class="fa fa-link"></i> 來自待驗清單：bom_ing_fid = <b>'+fid+'</b>，資料為真實資料庫內容，儲存會寫入新制表。');
        loadContext(fid);
    } else {
        state.demo = true;
        $('#mode-banner').html('<i class="fa fa-info-circle"></i> <b>示範模式</b>（未帶 bom_ing_fid）：可點搜尋瀏覽動線。正式由「QC待驗清單」按檢驗開啟並帶入料件。可加 <code>?bom_ing_fid=139267</code> 測試真實資料。');
        $('#step-search').show();
    }

    // ============ 載入真實情境 ============
    function loadContext(fid){
        $.post(API, { action:'load_context', bom_ing_fid:fid }, function(res){
            if (!res.success){
                if (res.no_view){
                    // 無檢閱權限：顯示提示即可（不導頁、不設 lastpage，避免登入死循環）
                    state.canView = false;
                    $('#no-view-hint').html('<i class="fa fa-ban"></i> '+esc(res.message||'您沒有檢閱檢驗表的權限')).show();
                    $('#main-area,#selected-info,#step-search').hide();
                    return;
                }
                alert('載入失敗：'+res.message); return;
            }
            ctx = res.context;
            if (res.tools && res.tools.length) TOOLS = res.tools;
            state.is_supervisor = !!res.is_supervisor;
            state.can_fill = res.can_fill !== false;
            state.canManageSettings = !!res.can_manage_settings;
            state.canManageSampling = !!res.can_manage_sampling;
            applyMenuPerms();
            state.sampleN = ctx.sample_qty || 5;
            state.processes = [ ctx.process || '檢驗' ];
            state.curProc = 0;
            // 由歷程組批次；無則建批次1
            buildBatchesFromHistory(res.history || []);
            renderSelectedInfo();
            $('#main-area').show();
            $('#inp-qty').val(ctx.order_qty || 0);
            $('#inp-sample').val(state.sampleN);
            renderBatches(); renderProcTabs();
            renderItems((res.items||[]).slice());
            $('#no-std-hint').toggle(!res.has_std);
            // 無料號(d_setting)或無「填寫檢驗表單」權限：提示並停用儲存
            var noPart = !ctx.d_id || ctx.d_id <= 0;
            $('#no-part-hint').toggle(noPart);
            $('#no-perm-hint').toggle(!state.can_fill);
            $('#btn-save,#btn-redo').prop('disabled', noPart || !state.can_fill);
            maybeOfferDraft(res.draft_form_id||0); // #4 未送出草稿提示
        }, 'json').fail(function(x){ alert('載入錯誤：'+x.responseText); });
    }

    function reloadContext(){ if(ctx) loadContext(ctx.bom_ing_fid); }

    // ============ #4 草稿 / 自動存檔 ============
    var draftTimer=null, draftDirty=false;
    function draftEligible(){ return ctx && !state.demo && !state.editFormId && state.can_fill && ctx.d_id>0; }
    function scheduleDraftSave(){ if(!draftEligible()) return; draftDirty=true; if(draftTimer) clearTimeout(draftTimer); draftTimer=setTimeout(saveDraftNow, 2500); }
    function saveDraftNow(){
        if(!draftEligible() || !draftDirty) return;
        var items=collectItems(); if(!items.length){ draftDirty=false; return; }
        var b=state.batches[state.curBatch]||{no:1,rounds:[]};
        $.post(API,{ action:'save_draft', bom_ing_fid:ctx.bom_ing_fid, d_id:ctx.d_id, process_name:ctx.process,
            batch_no:b.no, round_no:(b.rounds.length+1),
            incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
            main_remark:$('#inp-remark').val(), items:JSON.stringify(items), pcs_verdicts:JSON.stringify(collectPcsVerdicts())
        }, function(res){
            if(res && res.success){ draftDirty=false; state.draftFormId=res.draft_form_id;
                var t=new Date(), p=function(n){return('0'+n).slice(-2);};
                $('#draft-status').text('已自動存草稿 '+p(t.getHours())+':'+p(t.getMinutes())+':'+p(t.getSeconds())).stop(true,true).show();
            }
        }, 'json');
    }
    // 綁定：任何輸入/操作即排程存草稿
    $('#items-body').on('input change','input,select', scheduleDraftSave);
    $('#items-body').on('click','.okng-btn,.remove-row,.remove-sub,.add-reading,.item-note', function(){ setTimeout(scheduleDraftSave,60); });
    $('#inp-qty,#inp-sample,#inp-remark').on('input', scheduleDraftSave);
    $('#verdict-cells').on('click dblclick','.pcs-verdict', function(){ setTimeout(scheduleDraftSave,60); });
    $(window).on('beforeunload', function(){ if(draftEligible() && draftDirty){ try{ saveDraftNow(); }catch(e){} } });

    function maybeOfferDraft(draftId){
        if(!draftId || state.editFormId){ $('#draft-banner').hide(); return; }
        state.draftFormId=draftId;
        if(!$('#draft-banner').length){
            $('#mode-banner').after('<div id="draft-banner" class="proto-banner" style="background:#d9edf7;border-color:#bce8f1;color:#31708f;"></div>');
        }
        $('#draft-banner').html('<i class="fa fa-clock-o"></i> 偵測到您先前<b>未送出的草稿</b>（關掉視窗前自動保存的內容）。'+
            '<button class="btn btn-xs btn-info" id="btn-restore-draft" style="margin-left:8px;"><i class="fa fa-download"></i> 載回草稿</button> '+
            '<button class="btn btn-xs btn-default" id="btn-discard-draft"><i class="fa fa-trash"></i> 捨棄</button>').show();
    }
    $(document).on('click','#btn-restore-draft', function(){
        var did=state.draftFormId; if(!did) return;
        $.post(API,{action:'get_draft',qc_form_id:did}, function(res){
            if(!res.success || !res.draft){ alert('載回失敗或草稿已不存在'); $('#draft-banner').hide(); return; }
            var d=res.draft;
            state.sampleN=parseInt(d.sample_qty)||state.sampleN;
            $('#inp-qty').val(d.incoming_qty||0); $('#inp-sample').val(state.sampleN); $('#inp-remark').val(d.main_remark||'');
            renderItems((d.items||[]).slice());
            applyPcsVerdicts(d.pcs||[]);
            $('#no-std-hint').hide(); $('#draft-banner').hide();
        }, 'json');
    });
    $(document).on('click','#btn-discard-draft', function(){
        if(!confirm('確定捨棄此草稿？此動作無法復原。')) return;
        $.post(API,{action:'discard_draft',bom_ing_fid:(ctx?ctx.bom_ing_fid:0),qc_form_id:(state.draftFormId||0)}, function(){
            state.draftFormId=0; $('#draft-banner').hide();
        }, 'json');
    });

    // 進入修改模式：載入該筆歷程的項目與數值
    function openEditRecord(qcFormId){
        $.post(API,{action:'get_history_record',qc_form_id:qcFormId},function(res){
            if(!res.success){ alert('載入失敗：'+res.message); return; }
            if(!res.can_edit){ alert('此筆已鎖定，請主管先開放修改。'); return; }
            var h=res.header;
            state.editFormId=qcFormId;
            state.sampleN=h.sample_qty||state.sampleN;
            $('#inp-qty').val(h.incoming_qty||0);
            $('#inp-sample').val(state.sampleN);
            $('#inp-remark').val(h.main_remark||'');
            renderItems((res.items||[]).slice());
            applyPcsVerdicts(h.pcs_verdicts||[]);
            $('#no-std-hint').hide();
            $('#edit-form-id').text(qcFormId);
            $('#edit-mode-banner').show();
            $('#chk-save-std').prop('checked',false).closest('label').hide(); // 修改歷程不動標準
            $('#btn-save').html('<i class="fa fa-save"></i> 儲存修改');
            $('#btn-redo').hide();
            $('html,body').animate({scrollTop:0},200);
        },'json').fail(function(x){ alert('載入錯誤：'+x.responseText); });
    }
    function exitEditMode(){
        state.editFormId=null;
        $('#edit-mode-banner').hide();
        $('#chk-save-std').prop('checked',true).closest('label').show();
        $('#btn-save').html('<i class="fa fa-save"></i> 儲存檢驗結果');
        $('#btn-redo').show();
        reloadContext();
    }
    // 取消修改：若該筆先前經主管開放(edit_unlocked=1)，取消時自動回鎖，
    // 操作欄按鈕才會恢復為「開放修改」（儲存修改則由後端自動回鎖，不會重複寫紀錄）
    $('#btn-exit-edit').on('click', function(){
        var qid = state.editFormId;
        if (qid){
            $.post(API,{action:'relock_record',qc_form_id:qid},function(){ exitEditMode(); },'json')
             .fail(function(){ exitEditMode(); });
        } else {
            exitEditMode();
        }
    });

    // 檢視修改稽核紀錄
    function viewEditLog(qcFormId){
        $.post(API,{action:'get_edit_log',qc_form_id:qcFormId},function(res){
            if(!res.success){ alert('查詢失敗：'+res.message); return; }
            var logs=res.logs||[];
            var html='<table class="table table-condensed table-bordered"><thead><tr><th>時間</th><th>行為</th><th>人員</th><th>原因/變更</th></tr></thead><tbody>';
            if(!logs.length){ html+='<tr><td colspan="4" class="text-center text-muted">尚無修改紀錄</td></tr>'; }
            logs.forEach(function(l){
                var actMap={UNLOCK:'開放修改',EDIT:'修改',RELOCK:'回鎖'};
                var detail=esc(l.reason||'');
                if(l.changes_json){ detail+=' <a href="#" class="show-diff" data-json=\''+esc(l.changes_json)+'\'>[改前/改後]</a>'; }
                html+='<tr><td>'+esc(l.changed_at)+'</td><td>'+(actMap[l.action]||l.action)+'</td><td>'+esc(l.user_cname||l.changed_by)+'</td><td>'+detail+'</td></tr>';
            });
            html+='</tbody></table>';
            $('#log-modal-body').html(html);
            $('#logModal').modal('show');
        },'json');
    }
    $('#log-modal-body').on('click','.show-diff', function(e){ e.preventDefault();
        try{ var d=JSON.parse($(this).attr('data-json')); alert('改前：\n'+JSON.stringify(d.before,null,2)+'\n\n改後：\n'+JSON.stringify(d.after,null,2)); }catch(_){ alert('無法解析變更內容'); }
    });

    function buildBatchesFromHistory(history){
        state.batches = [];
        var byBatch = {};
        history.forEach(function(h){
            var b = h.batch_no || 1;
            if (!byBatch[b]) byBatch[b] = { no:b, status:'WAIT', rounds:[] };
            byBatch[b].rounds.push({
                date:(h.check_date||h.created_at||''), status:(h.check_result==='NG'?'NG':'OK'),
                qc_form_id:h.qc_form_id, round_no:(h.round_no||1), ng_qty:(h.ng_qty||0),
                edit_unlocked:(parseInt(h.edit_unlocked)||0),
                self_grace:(parseInt(h.self_grace)||0),
                edit_log_count:(parseInt(h.edit_log_count)||0),
                last_edited_by:h.last_edited_by, last_edited_at:h.last_edited_at,
                ncr_decision:h.ncr_decision, ncr_skip_reason:h.ncr_skip_reason,
                abnormal_order_id:h.abnormal_order_id, abnormal_order_no:h.abnormal_order_no
            });
            byBatch[b].status = (h.check_result==='NG'?'NG':'OK');
        });
        var keys = Object.keys(byBatch).map(Number).sort(function(a,b){return a-b;});
        keys.forEach(function(k){ state.batches.push(byBatch[k]); });
        if (!state.batches.length) state.batches.push({ no:1, status:'WAIT', rounds:[] });
        state.curBatch = state.batches.length - 1;
    }

    function renderSelectedInfo(){
        $('#selected-info').show().html(
            '<div class="row">'+
            '<div class="col-xs-4"><b>料號：</b><a href="javascript:void(0)" id="lnk-part-drawing" style="color:#2A3F54;font-weight:bold;text-decoration:underline;" title="點擊開啟圖檔預覽(可縮小/拖到另一螢幕)">'+esc(ctx.part_no)+' <i class="fa fa-picture-o"></i></a></div>'+
            '<div class="col-xs-4"><b>客戶：</b>'+esc(ctx.client)+'</div>'+
            '<div class="col-xs-4"><b>BOM：</b>'+esc(ctx.bom)+'</div>'+
            '<div class="col-xs-4"><b>製程：</b>'+esc(ctx.process)+'</div>'+
            '<div class="col-xs-4"><b>訂單數：</b>'+ctx.order_qty+'</div>'+
            '<div class="col-xs-4"><b>建議抽驗數：</b>'+ctx.sample_qty+'</div>'+
            '</div>');
    }
    function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];}); }

    // 點料號 → 開啟與 OreadyReply_ForPm_BaseOfTime2 相同的圖檔預覽視窗(part_viewer.php)
    // 為獨立瀏覽器視窗：可縮小、可拖到另一螢幕/分頁
    function openBomFiles(bom, did){
        if(!bom && !did) return;
        var w=screen.availWidth, h=screen.availHeight;
        var pw=Math.min(1400, Math.round(w*0.85)), ph=Math.min(900, Math.round(h*0.88));
        var pl=Math.round((w-pw)/2), pt=Math.round((h-ph)/2);
        var url = did
            ? '../pm/part_viewer.php?d_id='+encodeURIComponent(did)+(bom?'&bom='+encodeURIComponent(bom):'')
            : '../pm/bom_viewer.php?bom='+encodeURIComponent(bom);
        var winName = did ? ('part_dv_'+did) : ('bom_viewer_'+bom);
        window.open(url, winName,
            'width='+pw+',height='+ph+',left='+pl+',top='+pt+',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no');
    }
    $('#selected-info').on('click','#lnk-part-drawing', function(){ if(ctx) openBomFiles(ctx.bom, ctx.part_no); });

    // ============ 搜尋待驗項目（真實資料；可輸入部分料號/BOM/客戶）============
    function doSearch(){
        var kw=$('#search-kw').val().trim();
        $('#search-results').html('<div class="search-result-item text-muted">搜尋中…</div>');
        $.post(API,{action:'search_pending',keyword:kw},function(res){
            if(!res.success){ $('#search-results').html('<div class="search-result-item text-danger">搜尋失敗：'+esc(res.message||'')+'</div>'); return; }
            var d=res.data||[];
            if(!d.length){ $('#search-results').html('<div class="search-result-item text-muted">查無待驗項目</div>'); return; }
            $('#search-results').html(d.map(function(r){
                return '<div class="search-result-item" data-fid="'+r.bom_ing_fid+'"><b>'+esc(r.bom)+'</b> ／ 料號 '+esc(r.part_no||'')+' ／ '+esc(r.client||'')+' <span class="text-muted">'+esc(r.process||'')+' · 數量'+(r.sqty||0)+'</span></div>';
            }).join(''));
        },'json').fail(function(x){ $('#search-results').html('<div class="search-result-item text-danger">搜尋錯誤</div>'); });
    }
    $('#btn-search').on('click', doSearch);
    $('#search-kw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); doSearch(); } });
    $('#search-results').on('click','.search-result-item[data-fid]', function(){
        var fid=$(this).data('fid'); if(!fid) return;
        var pop = new URLSearchParams(location.search).get('popup');
        location.search = (pop?'?popup=1&':'?') + 'bom_ing_fid=' + fid;
    });

    // ============ 批次列 + 歷程 ============
    function statusLabel(s){
        return ({OK:'<span class="st st-ok">✔合格</span>',NG:'<span class="st st-ng">✘不良</span>',
                 REDO:'<span class="st st-redo">⟳重做中</span>',WAIT:'<span class="st st-wait">…待驗</span>'})[s]||'';
    }
    function renderBatches(){
        var h = state.batches.map(function(b,i){
            return '<span class="batch-chip '+(i===state.curBatch?'active':'')+'" data-i="'+i+'">批次'+b.no+statusLabel(b.status)+'</span>';
        }).join('');
        h += '<span class="batch-chip" id="btn-new-batch" style="border-style:dashed;"><i class="fa fa-plus"></i> 新到貨批次</span>';
        $('#batch-bar').html(h);
        renderHistory();
    }
    $('#batch-bar').on('click','.batch-chip[data-i]', function(){ state.curBatch=$(this).data('i'); renderBatches(); });
    $('#batch-bar').on('click','#btn-new-batch', function(){
        state.batches.push({ no:state.batches.length+1, status:'WAIT', rounds:[] });
        state.curBatch = state.batches.length-1; renderBatches();
    });
    function renderHistory(){
        var b = state.batches[state.curBatch];
        if (!b || !b.rounds.length){ $('#batch-history').empty(); return; }
        var rows = b.rounds.map(function(r,i){
            var act = '';
            if (r.qc_form_id) {
                var locked = !r.edit_unlocked;
                // #6：本人寬限期內即使鎖定也可自改
                var selfGrace = !!r.self_grace && state.can_fill;
                if (locked && !state.is_supervisor && !selfGrace) {
                    act += '<span class="text-muted" title="已鎖定，需主管開放"><i class="fa fa-lock"></i> 鎖定</span> ';
                }
                if (locked && state.is_supervisor) {
                    act += '<button class="btn btn-xs btn-warning act-unlock" data-id="'+r.qc_form_id+'"><i class="fa fa-unlock-alt"></i> 開放修改</button> ';
                }
                if ((!locked && state.can_fill) || state.is_supervisor || selfGrace) {
                    act += '<button class="btn btn-xs btn-primary act-edit" data-id="'+r.qc_form_id+'"'+(locked&&selfGrace?' title="本人寬限期內可自改（免主管解鎖）"':'')+'><i class="fa fa-pencil"></i> 修改'+(locked&&selfGrace?'（本人）':'')+'</button> ';
                }
                // 僅此筆有修改紀錄（開放/修改稽核）時才顯示「紀錄」按鈕
                if (r.edit_log_count > 0) {
                    act += '<button class="btn btn-xs btn-default act-log" data-id="'+r.qc_form_id+'"><i class="fa fa-history"></i> 紀錄</button>';
                }
            }
            var edited = r.last_edited_at ? ('<br><small class="text-muted">最後修改：'+esc(r.last_edited_by||'')+' '+esc(r.last_edited_at)+'</small>') : '';
            // NG 的異常單狀態：已開單→連結；不開單→原因；未決定→補開按鈕
            var ncr = '';
            if (r.status==='NG' && r.qc_form_id){
                if (r.abnormal_order_no){
                    ncr = '<a class="btn btn-xs btn-info" href="../QA/qa_abnormal_view.php?id='+r.abnormal_order_id+'" target="_blank" title="開啟異常單">'+esc(r.abnormal_order_no)+'</a>';
                } else if (r.ncr_decision==='SKIP'){
                    ncr = '<span class="label label-default" title="不開單原因：'+esc(r.ncr_skip_reason||'')+'">不開單</span>';
                } else if (state.can_fill || state.is_supervisor){
                    ncr = '<button class="btn btn-xs btn-warning act-open-ncr" data-id="'+r.qc_form_id+'"><i class="fa fa-file-text-o"></i> 開異常單</button>';
                } else {
                    ncr = '<span class="label label-default" title="唯讀檢閱：無法開立異常單">未開單</span>';
                }
            }
            return '<tr class="history-row"><td>第'+(r.round_no||(i+1))+'次</td><td>'+esc(r.date)+edited+'</td><td>'+statusLabel(r.status)+'</td><td>不良 '+(r.ng_qty||0)+'</td><td>'+ncr+'</td><td>'+act+'</td></tr>';
        }).join('');
        $('#batch-history').html(
            '<div class="muted-help" style="margin-bottom:4px;">批次'+b.no+' 檢驗歷程（退回重做會在此累積）'+(state.is_supervisor?'　<span class="label label-info">主管</span> 可開放/修改':'')+'：</div>'+
            '<table class="table table-condensed table-bordered" style="background:#fff;"><thead>'+
            '<tr><th width="70">次數</th><th width="190">日期</th><th width="90">結果</th><th width="80">不良</th><th width="110">異常單</th><th width="220">操作</th></tr></thead><tbody>'+rows+'</tbody></table>');
    }
    // 主管開放某筆修改
    $('#batch-history').on('click','.act-unlock', function(){
        var id=$(this).data('id');
        var reason=prompt('開放此筆修改的原因（會記錄）：','');
        if(reason===null) return;
        $.post(API,{action:'unlock_record',qc_form_id:id,reason:reason},function(res){
            if(!res.success){ alert('開放失敗：'+res.message); return; }
            alert('已開放此筆修改。'); reloadContext();
        },'json');
    });
    // 載入某筆進入編輯模式
    $('#batch-history').on('click','.act-edit', function(){ openEditRecord($(this).data('id')); });
    // 檢視修改紀錄
    $('#batch-history').on('click','.act-log', function(){ viewEditLog($(this).data('id')); });

    // ============ 製程分頁 ============
    function renderProcTabs(){
        $('#proc-tabs').html(state.processes.map(function(p,i){
            return '<li class="'+(i===state.curProc?'active':'')+'"><a href="#" data-i="'+i+'">'+esc(p)+'</a></li>';
        }).join(''));
    }
    $('#proc-tabs').on('click','a', function(e){ e.preventDefault(); state.curProc=$(this).data('i'); renderProcTabs(); });

    // ============ 檢驗項目表 ============
    // 編號顯示模式：ALPHA=A、B、C… / NUM=1、2、3…（使用者可切換，記在瀏覽器）
    var codeMode = localStorage.getItem('qc_item_code_mode') || 'ALPHA';
    function codeLabel(i){
        if (codeMode !== 'ALPHA') return String(i+1);
        var s='', n=i+1;
        while(n>0){ var r=(n-1)%26; s=String.fromCharCode(65+r)+s; n=Math.floor((n-1)/26); }
        return s;
    }
    function renderCodeModeBtn(){ $('#btn-code-mode').text(codeMode==='ALPHA' ? 'A,B,C…（切換）' : '1,2,3…（切換）'); }
    renderCodeModeBtn();
    $('#btn-code-mode').on('click', function(e){
        e.preventDefault();
        codeMode = (codeMode==='ALPHA') ? 'NUM' : 'ALPHA';
        localStorage.setItem('qc_item_code_mode', codeMode);
        renderCodeModeBtn(); reindex();
    });

    function toolOptions(sel){ return TOOLS.map(function(t){ return '<option '+(t===sel?'selected':'')+'>'+esc(t)+'</option>'; }).join(''); }
    // 每 PCS 一格（固定寬度 .s-slot 讓表頭編號與判定結果列對齊）；samples 有值時帶入既有實測
    function sampleInputs(type, samples){
        samples = samples || [];
        var h='';
        for (var i=0;i<state.sampleN;i++){
            var sv = samples[i] || null;
            var numTag = '<span class="s-num">'+(i+1)+'</span>';
            if (type==='OKNG'){
                var ng = sv && (sv.r==='NG' || sv.v==='NG');
                h += '<span class="s-slot">'+numTag+'<span class="label okng-btn '+(ng?'ng-value':'label-default')+'" tabindex="0" data-s="'+(i+1)+'">'+(ng?'NG':'OK')+'</span></span>';
            } else {
                var val = (sv && sv.v!=null) ? String(sv.v) : '';
                var cls = (val!=='') ? ((sv && sv.r==='NG') ? ' ng-value' : ' ok-value') : '';
                h += '<span class="s-slot">'+numTag+'<input type="number" inputmode="decimal" class="table-input num-cell'+cls+'" value="'+esc(val)+'" data-s="'+(i+1)+'"></span>';
            }
        }
        return h;
    }
    // 每格已自帶抽驗序號，表頭不再重複整排數字，改放簡短提示
    function renderSampleNums(){
        $('#sample-nums').html('<span style="font-weight:normal;font-size:11px;color:#999;">（每格上方數字＝抽驗序號，共 '+state.sampleN+' 件）</span>');
    }
    function itemRow(it, idx){
        var isNum = it.type!=='OKNG';
        var stdVal = (it.std!==undefined && it.std!==null && it.std!=='') ? it.std : (isNum ? '' : 'OK');
        var rmk=it.remark||'';
        return '<tr data-type="'+(it.type||'NUM')+'" data-itemid="'+(it.item_id||'')+'" data-remark="'+esc(rmk)+'">'+
            '<td class="text-center">'+codeLabel(idx)+'</td>'+
            '<td><input class="table-input f-name" value="'+esc(it.name||'')+'"></td>'+
            '<td><input class="table-input f-std" value="'+esc(stdVal)+'"></td>'+
            '<td><input class="table-input f-up" value="'+esc(it.up||'')+'" '+(isNum?'':'readonly')+'></td>'+
            '<td><input class="table-input f-lo" value="'+esc(it.lo||'')+'" '+(isNum?'':'readonly')+'></td>'+
            '<td class="tool-col"><select class="table-input f-tool" data-tid="'+esc(it.tool_id||'')+'" data-tcat="'+esc(it.tool||'')+'">'+toolInstOptions(resolvePrimaryToolId(it))+'</select>'+
                '<div class="tool-sel-label"></div>'+
                '<a href="#" class="add-reading small" title="同尺寸再用其他量具/方法量一次（如三次元＋投影機）"><i class="fa fa-plus"></i> 加量測</a></td>'+
            '<td><select class="table-input sel-type">'+
                '<option value="NUM" '+(isNum?'selected':'')+'>數值</option>'+
                '<option value="OKNG" '+(isNum?'':'selected')+'>OK/NG</option></select></td>'+
            '<td class="sample-cell">'+sampleInputs(it.type, it.samples)+'</td>'+
            '<td class="text-center" style="white-space:nowrap">'+
                '<i class="fa fa-comment-o item-note'+(rmk?' has-note':'')+'" title="逐項備註（點擊填寫，如「毛邊已修」）"></i> '+
                '<i class="fa fa-trash remove-row"></i></td></tr>';
    }
    // #10：加量測子列（同尺寸的第二/三筆讀值，量具實例可不同、每 PCS 可留空＝未量測）
    function readingSubRow(parentIt, ex){
        var type=(parentIt.type==='OKNG')?'OKNG':'NUM';
        ex=ex||{};
        return '<tr class="reading-sub" data-type="'+type+'">'+
            '<td></td>'+
            '<td colspan="4" class="text-right"><span class="text-muted" style="font-size:12px">↳ 加量測（其他量具/方法）</span></td>'+
            '<td class="tool-col"><select class="table-input f-tool2" data-tid="'+esc(ex.tool_id||'')+'" data-tcat="'+esc(ex.method||'')+'">'+toolInstOptions(ex.tool_id)+'</select><div class="tool-sel-label"></div></td>'+
            '<td></td>'+
            '<td class="sample-cell">'+sampleInputs(type, ex.samples)+'</td>'+
            '<td class="text-center"><i class="fa fa-trash remove-sub" title="移除此加量測列"></i></td></tr>';
    }
    function renderItems(items){
        var html=(items||[]).map(function(it,idx){
            var h=itemRow(it,idx);
            (it.extra||[]).forEach(function(ex){ h+=readingSubRow(it, ex); });
            return h;
        }).join('');
        $('#items-body').html(html); reindex(); renderSampleNums(); renderVerdictRow();
    }
    // 編號重排時，檢驗項目自動帶入最左側編號；
    // 只有「空白或仍等於先前自動帶入編號」的欄位才連動，使用者自訂的名稱不覆蓋
    function reindex(){
        $('#items-body tr:not(.reading-sub)').each(function(i){
            var label = codeLabel(i);
            $(this).find('td:first').text(label);
            var $name = $(this).find('.f-name');
            var prev  = $(this).data('autoCode');
            var v     = ($name.val()||'').trim();
            if (v==='' || v===prev) $name.val(label);
            $(this).data('autoCode', label);
        });
    }
    // 此表所有輸入欄位取得游標時自動全選，方便直接覆蓋輸入
    $('#items-body').on('focusin', 'input.table-input', function(){
        var el=this; setTimeout(function(){ try{ el.select(); }catch(_){} }, 0);
    });

    // ---- 判定結果列：每 PCS 一格；垂直任一項目 NG 即自動 NG，可點擊手動修改（雙擊恢復自動）----
    function renderVerdictRow(){
        var h=''; for(var i=1;i<=state.sampleN;i++) h+='<span class="s-slot"><span class="s-num">'+i+'</span><span class="label label-default pcs-verdict" tabindex="0" data-s="'+i+'" title="點擊手動切換 OK/NG，雙擊恢復自動判定">OK</span></span>';
        $('#verdict-cells').html(h);
        updateAutoVerdicts();
    }
    function setPcsCell($el, v){
        var ng = (v==='NG');
        $el.toggleClass('ng-value', ng).toggleClass('label-default', !ng).text(ng?'NG':'OK');
    }
    function updateAutoVerdicts(){
        // 尚未建立任何檢驗項目 → 判定結果顯示「—」，不預設合格（避免「還沒驗就顯示 OK」的誤導）
        var hasItems = $('#items-body tr:not(.reading-sub)').length > 0;
        $('#verdict-cells .pcs-verdict').each(function(){
            var $c=$(this);
            if(!hasItems){ $c.removeData('manual').removeClass('manual ng-value').addClass('label-default').text('—'); return; }
            if($c.data('manual')) return; // 手動修改過的不覆蓋
            var s=$c.data('s'), ng=false;
            $('#items-body tr').each(function(){
                var $slot=$(this).find('.sample-cell .s-slot').eq(s-1);
                if($slot.find('.ng-value').length){ ng=true; return false; }
            });
            setPcsCell($c, ng?'NG':'OK');
        });
        recalcNG(hasItems);
    }
    // 不良數 = 判定結果為 NG 的 PCS 數；無檢驗項目時不良數留空、整體判定不預設
    function recalcNG(hasItems){
        if(hasItems===undefined) hasItems = $('#items-body tr:not(.reading-sub)').length > 0;
        if(!hasItems){ $('#inp-ng').val(''); $('input[name=judge]').prop('checked', false); return; }
        var ng = $('#verdict-cells .pcs-verdict.ng-value').length;
        $('#inp-ng').val(ng);
        $('input[name=judge][value="'+(ng>0?'NG':'OK')+'"]').prop('checked', true);
    }
    $('#verdict-cells').on('click','.pcs-verdict', function(){
        var $c=$(this);
        $c.data('manual',1).addClass('manual');
        setPcsCell($c, $c.hasClass('ng-value')?'OK':'NG');
        recalcNG();
    });
    $('#verdict-cells').on('dblclick','.pcs-verdict', function(){
        $(this).removeData('manual').removeClass('manual');
        updateAutoVerdicts();
    });
    // 修改模式：還原先前存檔的每 PCS 判定（僅還原手動改過的，其餘自動重算）
    function applyPcsVerdicts(arr){
        if(!arr || !arr.length) return;
        $('#verdict-cells .pcs-verdict').each(function(i){
            var pv=arr[i]; if(!pv || !pv.m) return;
            $(this).data('manual',1).addClass('manual');
            setPcsCell($(this), pv.v==='NG'?'NG':'OK');
        });
        updateAutoVerdicts();
    }
    function collectPcsVerdicts(){
        var out=[];
        $('#verdict-cells .pcs-verdict').each(function(){
            out.push({ v: $(this).hasClass('ng-value')?'NG':'OK', m: $(this).data('manual')?1:0 });
        });
        return out;
    }

    $('#btn-add-row').on('click', function(){ $('#items-body').append(itemRow({type:'NUM'}, $('#items-body tr').length)); reindex(); $('#no-std-hint').hide(); });
    $('#items-body').on('click','.remove-row', function(){
        var $tr=$(this).closest('tr');
        $tr.nextUntil('tr:not(.reading-sub)').remove(); // 連同其加量測子列一起移除
        $tr.remove(); reindex(); updateAutoVerdicts();
    });
    $('#items-body').on('change','.sel-type', function(){
        var $tr=$(this).closest('tr'), type=$(this).val(); $tr.attr('data-type',type);
        var isNum=type==='NUM';
        $tr.find('.f-up,.f-lo').prop('readonly',!isNum).val(isNum?'':'');
        // OK/NG 型態：標準值自動帶 OK
        var $std=$tr.find('.f-std');
        if(!isNum){ $std.val('OK'); } else if($std.val()==='OK'){ $std.val(''); }
        $tr.find('.sample-cell').html(sampleInputs(type));
        // 該項目底下的加量測子列同步改型態並清空
        $tr.nextUntil('tr:not(.reading-sub)').each(function(){ $(this).attr('data-type',type).find('.sample-cell').html(sampleInputs(type)); });
        updateAutoVerdicts();
    });
    $('#items-body').on('click','.okng-btn', function(){
        var ng=$(this).hasClass('ng-value');
        $(this).toggleClass('ng-value',!ng).toggleClass('label-default',ng).text(ng?'OK':'NG');
        updateAutoVerdicts();
    });
    $('#items-body').on('input','.num-cell', function(){
        var $tr=$(this).closest('tr');
        // 加量測子列沿用其上方主項目的公差判定
        var $ref=$tr.hasClass('reading-sub') ? $tr.prevAll('tr:not(.reading-sub)').first() : $tr;
        var base=parseFloat($ref.find('.f-std').val());
        var up=parseFloat($ref.find('.f-up').val()), lo=parseFloat($ref.find('.f-lo').val());
        var v=parseFloat($(this).val());
        if(isNaN(v)||isNaN(base)){ $(this).removeClass('ng-value ok-value'); }
        else { var hi=base+(isNaN(up)?0:up), low=base+(isNaN(lo)?0:lo); var ng=(v>hi||v<low); $(this).toggleClass('ng-value',ng).toggleClass('ok-value',!ng); }
        updateAutoVerdicts();
    });
    // #10：＋加量測 → 於該項目下方插入一筆加量測子列；移除子列
    $('#items-body').on('click','.add-reading', function(e){
        e.preventDefault();
        var $tr=$(this).closest('tr');
        var it={ type:$tr.attr('data-type') };
        var $sub=$(readingSubRow(it, {}));
        // 插到該主列與其現有子列之後
        var $after=$tr; while($after.next('.reading-sub').length){ $after=$after.next('.reading-sub'); }
        $after.after($sub);
    });
    $('#items-body').on('click','.remove-sub', function(){ $(this).closest('tr').remove(); updateAutoVerdicts(); });
    // #5 逐列（單項）備註：點筆記圖示填寫，預設收合不佔空間
    $('#items-body').on('click','.item-note', function(){
        var $tr=$(this).closest('tr');
        var cur=$tr.attr('data-remark')||'';
        var v=prompt('本項目備註（處置/狀況，如「毛邊已修」）：', cur);
        if(v===null) return;
        v=String(v).slice(0,255);
        $tr.attr('data-remark', v);
        $(this).toggleClass('has-note', v.trim()!=='');
    });

    // ============ 鍵盤導航：上下左右移動游標、Enter 跳下一格 ============
    // 每列的可導航控制項（欄位對齊：名稱/標準/上/下/量具/型態/各抽/判定）
    function navGrid(){
        var rows=[];
        $('#items-body tr:not(.reading-sub)').each(function(){
            var $tr=$(this), c=[];
            ['.f-name','.f-std','.f-up','.f-lo','.f-tool','.sel-type'].forEach(function(s){ var el=$tr.find(s)[0]; if(el) c.push(el); });
            $tr.find('.sample-cell').find('.num-cell, .okng-btn').each(function(){ c.push(this); });
            rows.push(c);
        });
        return rows;
    }
    $('#items-body').on('keydown','input, select, .okng-btn', function(e){
        var k=e.key;
        if(['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Enter'].indexOf(k)===-1){
            if((k===' '||k==='Spacebar') && $(this).hasClass('okng-btn')){ $(this).click(); e.preventDefault(); }
            return;
        }
        var grid=navGrid(), el=this, r=-1, c=-1;
        for(var i=0;i<grid.length;i++){ var j=grid[i].indexOf(el); if(j>=0){ r=i; c=j; break; } }
        if(r<0) return;
        var isText = el.tagName==='INPUT';
        var atStart = isText ? (el.selectionStart===0) : true;
        var atEnd   = isText ? (el.selectionEnd===(el.value||'').length) : true;
        function focusCell(nr,nc){
            if(nr<0 || nr>=grid.length || !grid[nr].length) return false;
            nc=Math.max(0, Math.min(nc, grid[nr].length-1));
            var t=grid[nr][nc]; if(!t) return false;
            t.focus(); if(t.select){ try{ t.select(); }catch(_){} }
            return true;
        }
        if(k==='ArrowUp'){ if(focusCell(r-1,c)) e.preventDefault(); }
        else if(k==='ArrowDown'){ if(focusCell(r+1,c)) e.preventDefault(); }
        else if(k==='ArrowLeft'){ if(atStart && focusCell(r,c-1)) e.preventDefault(); }
        else if(k==='ArrowRight'){ if(atEnd && focusCell(r,c+1)) e.preventDefault(); }
        else if(k==='Enter'){ e.preventDefault(); if(c+1<grid[r].length) focusCell(r,c+1); else focusCell(r+1,0); }
    });

    // ============ 樣板（匯入：讀取通用樣板資料表；無資料時示範模式退回內建示意） ============
    function mockTplHtml(){ return MOCK_TPL.map(function(t,i){ return '<a href="#" class="list-group-item" data-i="'+i+'">'+esc(t.name)+'（示意）</a>'; }).join(''); }
    function openTpl(){
        $('#tpl-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $('#tplModal').modal('show');
        $.post(API,{action:'manage_templates',sub_action:'list'},function(res){
            var d=(res && res.success && res.data)||[];
            if(d.length){
                $('#tpl-list').html(d.map(function(t){ return '<a href="#" class="list-group-item" data-tid="'+t.template_id+'">'+esc(t.template_name)+'</a>'; }).join(''));
            } else if(state.demo){
                $('#tpl-list').html(mockTplHtml());
            } else {
                $('#tpl-list').html('<div class="list-group-item text-muted">尚無通用樣板，請先到 設定 → 通用樣板管理 建立。</div>');
            }
        },'json').fail(function(){ $('#tpl-list').html(mockTplHtml()); });
    }
    $('#btn-import-tpl,#btn-import-tpl2').on('click', openTpl);
    $('#tpl-list').on('click','a', function(e){
        e.preventDefault();
        var tid=$(this).data('tid');
        if(tid){
            $.post(API,{action:'manage_templates',sub_action:'get_items',template_id:tid},function(res){
                if(!res.success){ alert('載入樣板失敗：'+(res.message||'')); return; }
                renderItems(res.items||[]);
                $('#no-std-hint').hide(); $('#tplModal').modal('hide');
            },'json');
        } else {
            renderItems(MOCK_TPL[$(this).data('i')].items.slice());
            $('#no-std-hint').hide(); $('#tplModal').modal('hide');
        }
    });

    // ============ 抽驗數連動 ============
    $('#inp-sample').on('input', function(){
        var n=parseInt($(this).val())||1; state.sampleN=n<1?1:n;
        $('#items-body tr').each(function(){ $(this).find('.sample-cell').html(sampleInputs($(this).attr('data-type'))); });
        renderSampleNums(); renderVerdictRow();
    });

    // ============ 收集表格資料（含 #10 加量測子列）============
    function readRowSamples($row, type){
        var s=[];
        if(type==='OKNG'){ $row.find('.okng-btn').each(function(){ var ng=$(this).hasClass('ng-value'); s.push({v:(ng?'NG':'OK'), r:(ng?'NG':'OK')}); }); }
        else { $row.find('.num-cell').each(function(){ var v=$(this).val(); if(v!=='') s.push({v:v, r:($(this).hasClass('ng-value')?'NG':'OK')}); }); }
        return s;
    }
    function collectItems(){
        var out=[];
        $('#items-body tr:not(.reading-sub)').each(function(){
            var $tr=$(this);
            var name=$tr.find('.f-name').val().trim();
            if(!name) return;
            var type=$tr.attr('data-type')==='OKNG'?'OKNG':'NUM';
            var samples=readRowSamples($tr, type);
            // 加量測子列 → extra[]
            var extra=[], anyNG=$tr.find('.sample-cell .ng-value').length>0;
            $tr.nextUntil('tr:not(.reading-sub)').each(function(){
                var $sub=$(this);
                var es=readRowSamples($sub, type);
                var etid=$sub.find('.f-tool2').val()||'';
                if($sub.find('.sample-cell .ng-value').length) anyNG=true;
                if(es.length || etid) extra.push({ tool_id:etid, samples:es });
            });
            out.push({
                item_id:$tr.attr('data-itemid')||'', name:name, std:$tr.find('.f-std').val(),
                up:$tr.find('.f-up').val(), lo:$tr.find('.f-lo').val(),
                tool_id:$tr.find('.f-tool').val()||'', tool:'',
                type:type, verdict:(anyNG?'NG':'OK'), samples:samples, extra:extra,
                remark:$tr.attr('data-remark')||''
            });
        });
        return out;
    }

    // ============ 儲存 / 退回重做 ============
    function doSave(asRedo){
        if(state.demo){ alert('示範模式不寫入資料庫。請用 ?bom_ing_fid=139267 開啟以實際測試儲存。'); return; }
        var items=collectItems();
        if(!items.length){ alert('請至少輸入一個檢驗項目'); return; }

        // 修改模式：覆寫既有歷程並寫稽核
        if(state.editFormId){
            var reason=prompt('請填寫修改原因（必填，會記錄於稽核）：','');
            if(reason===null) return;
            if(reason.trim()===''){ alert('必須填寫修改原因'); return; }
            var $eb=$('#btn-save').prop('disabled',true);
            $.post(API,{ action:'update_inspection', qc_form_id:state.editFormId, reason:reason,
                incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
                main_remark:$('#inp-remark').val(), items:JSON.stringify(items),
                pcs_verdicts:JSON.stringify(collectPcsVerdicts())
            },function(res){
                $eb.prop('disabled',false);
                if(!res.success){ alert('修改失敗：'+res.message); return; }
                var s=res.summary;
                if(window.opener && !window.opener.closed){ try{ window.opener.postMessage({type:'qc_inspection_done',bom_ing_fid:s.bom_ing_fid,summary:s,qc_form_id:s.qc_form_id,edited:true},'*'); }catch(e){} }
                alert('已儲存修改（qc_form_id='+s.qc_form_id+'）\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'\n此筆已自動回鎖。');
                exitEditMode();
            },'json').fail(function(x){ $eb.prop('disabled',false); alert('修改錯誤：'+x.responseText); });
            return;
        }

        var b=state.batches[state.curBatch];
        var payload={
            action:'save_inspection', bom_ing_fid:ctx.bom_ing_fid, d_id:ctx.d_id, part_no:ctx.part_no,
            process_name:ctx.process, batch_no:b.no, round_no:(b.rounds.length+1),
            incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
            main_remark:$('#inp-remark').val(), update_std:$('#chk-save-std').is(':checked')?'1':'0',
            items:JSON.stringify(items), pcs_verdicts:JSON.stringify(collectPcsVerdicts())
        };
        var $btn=$(asRedo?'#btn-redo':'#btn-save').prop('disabled',true);
        $.post(API, payload, function(res){
            $btn.prop('disabled',false);
            if(!res.success){ alert('儲存失敗：'+res.message); return; }
            var s=res.summary;
            // 更新本地批次歷程
            b.rounds.push({ date:'剛剛', status:(asRedo?'NG':s.check_result), remark:(asRedo?'退回重做':('不良'+s.ng_qty)), qc_form_id:res.qc_form_id, round_no:(b.rounds.length+1), ng_qty:s.ng_qty });
            b.status = asRedo ? 'REDO' : s.check_result;
            renderBatches();
            var hasOpener = window.opener && !window.opener.closed;
            // 回傳彙總給待驗清單(opener)，由 QC 自行選 modal 送出
            if(hasOpener){
                try{ window.opener.postMessage({ type:'qc_inspection_done', bom_ing_fid:s.bom_ing_fid, summary:s, qc_form_id:res.qc_form_id, redo:!!asRedo }, '*'); }catch(e){}
            }
            // 儲存後續流程（NG 時先詢問是否開立異常單，決定後才執行）
            function finishSave(){
                if(asRedo){
                    // 退回重做：已記錄歷程，留在本頁可繼續，僅提示
                    alert('已記錄退回重做（qc_form_id='+res.qc_form_id+'）。重做送回後可再驗一次。');
                    return;
                }
                if(hasOpener){
                    // 將待驗清單帶到前景並關閉本跳窗，由待驗清單呈現結果讓 QC 確認
                    try{ window.opener.focus(); }catch(e){}
                    window.close();
                    // 若瀏覽器阻擋自動關閉，提供退路
                    setTimeout(function(){
                        if(confirm('檢驗結果已儲存並回傳待驗清單。\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'\n按確定關閉本視窗。')) window.close();
                    }, 400);
                } else {
                    alert('已儲存（qc_form_id='+res.qc_form_id+'）\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'　允收(讓步)：'+s.aod_qty+'\n（此頁非由待驗清單開啟，未回傳）');
                }
            }
            if(s.check_result==='NG'){ openNgAsk(res.qc_form_id, s, items, finishSave); }
            else finishSave();
        },'json').fail(function(x){ $btn.prop('disabled',false); alert('儲存錯誤：'+x.responseText); });
    }

    // ============ NG → 是否開立品質異常單 ============
    var ngCtx = null; // { qcFormId, summary, items, done, decided }
    function ngSummaryText(items){
        var lines=['品管檢驗判定 NG，NG 項目：']; var n=0;
        (items||[]).forEach(function(it){
            if(it.verdict!=='NG') return;
            n++;
            var tol=(it.up||it.lo)?('（公差 '+(it.up?'+'+it.up:'')+(it.lo?' / '+it.lo:'')+'）'):'';
            var ngVals=(it.samples||[]).filter(function(sv){ return sv.r==='NG'; }).map(function(sv){ return sv.v; }).filter(function(v){ return v!==''; }).join(', ');
            lines.push(n+'. '+it.name+'：標準 '+(it.std||'-')+tol+(ngVals?('，NG 實測值：'+ngVals):''));
        });
        return lines.join('\n');
    }
    function openNgAsk(qcFormId, s, items, done){
        ngCtx = { qcFormId:qcFormId, summary:s, items:items, done:done, decided:false };
        $('#ng-ask-info').html('本次檢驗判定為<b class="text-danger">不良</b>（NG 項目 <b>'+s.ng_qty+'</b> 項）。是否開立品質異常單？<br><small class="text-muted">開立後將自動通知回覆部門與相關人員，並要求回覆回簽。</small>');
        $('#ng-skip-area').hide(); $('#ng-skip-reason').val('');
        $('#ngAskModal').modal('show');
    }
    function ngPrefill(sqty, phenomenon){
        return {
            sqty: sqty,
            phenomenon: phenomenon,
            qa_ps: $('#inp-remark').val(),
            bom_no: (ctx ? ctx.bom : ''),
            bom_process_fids: (ctx ? String(ctx.bom_ing_fid) : '')
        };
    }
    // 開單/不開單後即時更新本地歷程列的異常單欄位（按鈕→單號連結/不開單標籤），不必重新載入
    function updateLocalNcr(qcFormId, patch){
        state.batches.forEach(function(b){
            b.rounds.forEach(function(r){ if(r.qc_form_id===qcFormId) $.extend(r, patch); });
        });
        renderBatches();
    }
    $('#btn-ng-open').on('click', function(){
        if(!ngCtx) return;
        $('#ngAskModal').modal('hide');
        QAAbnormalModal.open({
            source_type: 'QC',
            source_id: ngCtx.qcFormId,
            title_suffix: (ctx ? ('料號 '+ctx.part_no) : ''),
            prefill: ngPrefill(ngCtx.summary.ng_qty, ngSummaryText(ngCtx.items)),
            onCreated: function(r){
                ngCtx.decided = true;
                var qid = ngCtx.qcFormId;
                $.post(API, { action:'set_ncr_decision', qc_form_id:qid, decision:'OPEN', abnormal_order_id:r.id }, function(){
                    updateLocalNcr(qid, { ncr_decision:'OPEN', abnormal_order_id:r.id, abnormal_order_no:r.no });
                    alert('異常單 '+r.no+' 已開立並發送通知。');
                    var d = ngCtx.done; ngCtx = null;
                    if(d) d();
                }, 'json');
            }
        });
    });
    // 開單跳窗被取消（未成功開立）→ 回到詢問視窗重新選擇
    $('#qamModal').on('hidden.bs.modal', function(){
        if(ngCtx && !ngCtx.decided){ setTimeout(function(){ $('#ngAskModal').modal('show'); }, 300); }
    });
    $('#btn-ng-skip').on('click', function(){ $('#ng-skip-area').slideDown(120); $('#ng-skip-reason').focus(); });
    $('#btn-ng-skip-confirm').on('click', function(){
        if(!ngCtx) return;
        var reason=$('#ng-skip-reason').val().trim();
        if(!reason){ alert('請填寫不開立異常單的原因'); $('#ng-skip-reason').focus(); return; }
        var qid = ngCtx.qcFormId;
        $.post(API, { action:'set_ncr_decision', qc_form_id:qid, decision:'SKIP', reason:reason }, function(r){
            if(!r.success){ alert(r.message||'儲存失敗'); return; }
            ngCtx.decided = true;
            updateLocalNcr(qid, { ncr_decision:'SKIP', ncr_skip_reason:reason });
            $('#ngAskModal').modal('hide');
            var d = ngCtx.done; ngCtx = null;
            if(d) d();
        }, 'json');
    });
    // 歷程列「開異常單」（NG 但當時未決定/補開）
    $('#batch-history').on('click','.act-open-ncr', function(){
        var qid=$(this).data('id');
        $.post(API,{action:'get_history_record',qc_form_id:qid},function(res){
            if(!res.success){ alert('載入失敗：'+res.message); return; }
            // 畫面可能過期：此筆其實已開過單 → 顯示單號並刷新，避免重複開單
            if(res.header && res.header.abnormal_order_no){
                alert('此筆檢驗已開立異常單 '+res.header.abnormal_order_no+'，不可重複開立。');
                updateLocalNcr(qid, { ncr_decision:'OPEN', abnormal_order_id:res.header.abnormal_order_id, abnormal_order_no:res.header.abnormal_order_no });
                return;
            }
            QAAbnormalModal.open({
                source_type:'QC', source_id:qid,
                title_suffix:(ctx ? ('料號 '+ctx.part_no) : ''),
                prefill: ngPrefill(res.header.ng_qty, ngSummaryText(res.items)),
                onCreated:function(r){
                    $.post(API,{action:'set_ncr_decision',qc_form_id:qid,decision:'OPEN',abnormal_order_id:r.id},function(){
                        alert('異常單 '+r.no+' 已開立並發送通知。');
                        reloadContext();
                    },'json');
                }
            });
        },'json');
    });
    // 取消：不存檔。獨立視窗(由待驗清單開啟)→關閉視窗；否則返回上一頁
    $('#btn-cancel').on('click', function(){
        if(!confirm('確定取消？尚未儲存的輸入將不會保留。')) return;
        if(window.opener && !window.opener.closed){ window.close(); }
        else if(history.length > 1){ history.back(); }
        else { location.href = 'QC_check_list_test.php'; }
    });
    $('#btn-save').on('click', function(){ doSave(false); });
    $('#btn-redo').on('click', function(){ if($('#inp-remark').val().trim()===''){ $('#inp-remark').val('退回重做'); } doSave(true); });

    // ============ 權限設定（角色 → QC 功能；用既有 Roles_API） ============
    var ROLES_API = '../../src/store/Roles_API.php';
    var QC_FEATURES = [
        { code:'qc_fill_inspection',   label:'填寫檢驗表單（儲存檢驗結果/退回重做）' },
        { code:'qc_edit_history',      label:'修改 / 開放檢驗歷程（主管）' },
        { code:'qc_manage_settings',   label:'管理檢驗設定（量具 / 幾何公差 / 通用樣板）' },
        { code:'qc_manage_sampling',   label:'抽樣規則設定（主管「修改/開放檢驗歷程」固定可用；此處可另單獨授權）' },
        { code:'qc_view_readonly',     label:'唯讀檢閱（僅可檢視檢驗表與異常單，不可修改/開單）' },
        { code:'qa_disposition_reply', label:'勾選 / 回覆異常單「異常處置方式、處置說明」' },
        { code:'qc_supervisor',        label:'認定為主管（收到並核准異常單修改請求、可直接修改異常單；與管理員不同）' }
    ];
    var QC_CODES = QC_FEATURES.map(function(f){ return f.code; });
    var _permRole = null, _permRoleCur = [], _permRolesData = [], _permRoleName = '', _permRoleSys = false;
    $('#btn-perm-setting').on('click', function(e){ e.preventDefault(); _permRole=null; loadPermRoles(); $('#permModal').modal('show'); });
    function loadPermRoles(){
        // 僅載入 QC 模組角色＋系統管理員（各頁面角色分開設定，唯管理員全頁共用）
        $.get(ROLES_API, { action:'get_roles', module:'qc' }, function(res){
            if(!res.success){ $('#perm-role-list').html('<div class="text-danger">載入角色失敗</div>'); return; }
            _permRolesData = res.data || [];
            $('#perm-role-list').html(_permRolesData.map(function(r){
                return '<a href="#" class="list-group-item perm-role" data-id="'+r.role_id+'" data-sys="'+r.is_system+'" data-name="'+esc(r.role_name)+'">'+esc(r.role_name)+(r.is_system==1?' <span class="label label-default">系統</span>':'')+'</a>';
            }).join(''));
            $('#btn-rename-role,#btn-delete-role').prop('disabled', true);
        },'json');
    }
    // 新增角色
    $('#btn-add-role').on('click', function(){
        var name=prompt('新增角色名稱：',''); if(name===null) return; name=name.trim(); if(!name) return;
        $.post(ROLES_API,{action:'save_role',role_name:name,module:'qc'},function(res){
            if(!res.success){ alert('新增失敗：'+(res.message||'')); return; } loadPermRoles();
        },'json');
    });
    // 重新命名
    $('#btn-rename-role').on('click', function(){
        if(!_permRole||_permRoleSys) return;
        var name=prompt('修改角色名稱：',_permRoleName); if(name===null) return; name=name.trim(); if(!name) return;
        $.post(ROLES_API,{action:'save_role',role_id:_permRole,role_name:name},function(res){
            if(!res.success){ alert('修改失敗：'+(res.message||'')); return; } loadPermRoles();
        },'json');
    });
    // 刪除角色（先列人員→可轉移→輸入 Y）
    $('#btn-delete-role').on('click', function(){
        if(!_permRole||_permRoleSys) return;
        $('#del-role-name').text(_permRoleName);
        $('#del-confirm-y').val('');
        $('#del-role-users').html('讀取人員中…');
        $('#del-transfer-wrap').hide();
        $('#roleDeleteModal').modal('show');
        $.get(ROLES_API,{action:'get_users'},function(res){
            var users=(res.data||[]).filter(function(u){ return (u.roles||[]).some(function(r){ return r.role_id==_permRole; }); });
            $('#roleDeleteModal').data('users', users);
            if(!users.length){ $('#del-role-users').html('<div class="alert alert-info" style="margin:0;">目前沒有人員被指派為此角色，可直接刪除。</div>'); }
            else {
                $('#del-role-users').html('<div class="alert alert-warning" style="margin:0;"><b>下列 '+users.length+' 位人員目前是「'+esc(_permRoleName)+'」：</b><br>'+users.map(function(u){ return esc(u.user_cname||u.user_uname||u.id); }).join('、')+'</div>');
                var opts='<option value="">不轉移（僅移除此角色指派）</option>'+_permRolesData.filter(function(r){ return r.role_id!=_permRole; }).map(function(r){ return '<option value="'+r.role_id+'">'+esc(r.role_name)+'</option>'; }).join('');
                $('#del-transfer-role').html(opts); $('#del-transfer-wrap').show();
            }
        },'json');
    });
    $('#btn-confirm-del-role').on('click', function(){
        if($('#del-confirm-y').val()!=='Y'){ alert('請輸入大寫 Y 以確認刪除'); return; }
        var users=$('#roleDeleteModal').data('users')||[];
        var transferTo=$('#del-transfer-role').val();
        var $b=$(this).prop('disabled',true);
        function doDelete(){
            $.post(ROLES_API,{action:'delete_role',role_id:_permRole},function(res){
                $b.prop('disabled',false);
                if(!res.success){ alert('刪除失敗：'+(res.message||'')); return; }
                $('#roleDeleteModal').modal('hide'); _permRole=null; $('#perm-feature-box').html('<p class="text-muted">← 請先選擇角色</p>'); $('#btn-save-perm').prop('disabled',true);
                loadPermRoles(); alert('角色已刪除。');
            },'json').fail(function(x){ $b.prop('disabled',false); alert('刪除錯誤：'+x.responseText); });
        }
        if(transferTo && users.length){
            // 先把人員指派到新角色，再刪除舊角色(delete_role 會移除舊指派)
            var i=0; (function next(){
                if(i>=users.length){ doDelete(); return; }
                $.post(ROLES_API,{action:'assign_user_role',user_id:users[i].id,role_id:transferTo},function(){ i++; next(); },'json')
                 .fail(function(){ i++; next(); });
            })();
        } else { doDelete(); }
    });

    $('#perm-role-list').on('click','.perm-role', function(e){ e.preventDefault();
        $('.perm-role').removeClass('active'); $(this).addClass('active');
        _permRole=$(this).data('id'); var isSys=$(this).data('sys')==1;
        _permRoleName=$(this).data('name'); _permRoleSys=isSys;
        $('#btn-rename-role,#btn-delete-role').prop('disabled', isSys);
        $.get(ROLES_API,{action:'get_role_features',role_id:_permRole},function(res){
            _permRoleCur = res.success ? (res.data||[]) : [];
            var all = _permRoleCur.indexOf('all')!==-1;
            var html = QC_FEATURES.map(function(f){
                var chk=(all||_permRoleCur.indexOf(f.code)!==-1)?'checked':'';
                return '<div class="checkbox"><label><input type="checkbox" class="perm-feat" value="'+f.code+'" '+chk+(isSys||all?' disabled':'')+'> '+f.label+'</label></div>';
            }).join('');
            if(all) html+='<p class="text-info">此角色擁有全部權限(all)，無需逐項勾選。</p>';
            if(isSys&&!all) html+='<p class="text-muted">系統角色不可修改。</p>';
            $('#perm-feature-box').html(html);
            $('#btn-save-perm').prop('disabled', isSys||all);
        },'json');
    });
    $('#btn-save-perm').on('click', function(){
        if(!_permRole) return;
        var checked=$('#perm-feature-box .perm-feat:checked').map(function(){return this.value;}).get();
        // 合併：保留此角色非 QC 的既有功能 + 本次勾選的 QC 功能（避免洗掉其他模組權限）
        var merged=_permRoleCur.filter(function(c){ return QC_CODES.indexOf(c)===-1; }).concat(checked);
        $.post(ROLES_API,{action:'save_role_features',role_id:_permRole,features:JSON.stringify(merged)},function(res){
            if(!res.success){ alert('儲存失敗：'+(res.message||'')); return; }
            alert('角色權限已儲存。'); _permRoleCur=merged;
        },'json').fail(function(x){ alert('儲存錯誤：'+x.responseText); });
    });

    // ============ 設定：量具設定（種類/編號 CRUD、取代刪除） ============
    var toolMg = { categories:[], tools:[] }, curToolCat = null;
    $('#btn-tool-setting').on('click', function(e){ e.preventDefault(); loadToolMg(); $('#toolManageModal').modal('show'); });
    function loadToolMg(){
        $('#tool-cat-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'get_tool_manage_data'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            toolMg = res;
            // 同步主表量具下拉來源（之後新增的檢驗項目列即用最新清單）
            TOOLS = res.categories.map(function(c){ return c.QC_Tool; });
            renderToolCats();
            if(curToolCat && res.categories.some(function(c){ return c.QC_Tool_List_id==curToolCat; })){
                renderToolInsts(curToolCat);
            } else {
                curToolCat=null; $('#tool-instance-area').hide(); $('#tool-instance-empty').show();
            }
        },'json');
    }
    function renderToolCats(){
        $('#tool-cat-list').html(toolMg.categories.map(function(c){
            return '<a href="#" class="list-group-item tool-cat-item '+(c.QC_Tool_List_id==curToolCat?'active':'')+'" data-id="'+c.QC_Tool_List_id+'" data-name="'+esc(c.QC_Tool)+'">'+esc(c.QC_Tool)+
                '<span class="pull-right">'+
                '<button class="btn btn-xs btn-warning btn-edit-tc" style="margin:0;" title="改名"><i class="fa fa-pencil"></i></button> '+
                '<button class="btn btn-xs btn-info btn-replace-tc" style="margin:0;" title="取代並刪除"><i class="fa fa-exchange"></i></button> '+
                '<button class="btn btn-xs btn-danger btn-del-tc" style="margin:0;" title="刪除"><i class="fa fa-trash"></i></button>'+
                '</span></a>';
        }).join('') || '<div class="list-group-item text-muted">尚無量具種類</div>');
    }
    $('#tool-cat-list').on('click','.tool-cat-item', function(e){
        if($(e.target).closest('button').length) return;
        e.preventDefault();
        curToolCat=$(this).data('id');
        $('#current-cat-name').text($(this).data('name'));
        $('#ti-cat-id').val(curToolCat);
        renderToolCats(); renderToolInsts(curToolCat);
    });
    function renderToolInsts(catId){
        $('#tool-instance-empty').hide(); $('#tool-instance-area').show();
        // 校驗管理頁設為「不可設定量具編號」的種類（如目視）→ 只能看，不給新增編號
        var cat=toolMg.categories.filter(function(c){ return c.QC_Tool_List_id==catId; })[0]||{};
        var noNo = (cat.has_tool_no!==undefined && Number(cat.has_tool_no)===0);
        $('#ti-nono-hint').toggle(noNo);
        $('#tool-inst-form').toggle(!noNo);
        var list=toolMg.tools.filter(function(t){ return t.QC_Tool_List_id==catId; });
        $('#tool-inst-list').html(list.length ? list.map(function(t){
            return '<tr><td>'+esc(t.Tool_No)+'</td><td>'+
                '<button class="btn btn-xs btn-info btn-edit-ti" data-id="'+t.Tool_id+'" data-no="'+esc(t.Tool_No)+'"><i class="fa fa-pencil"></i></button> '+
                '<button class="btn btn-xs btn-danger btn-del-ti" data-id="'+t.Tool_id+'"><i class="fa fa-trash"></i></button></td></tr>';
        }).join('') : '<tr><td colspan="2" class="text-center text-muted">尚無編號</td></tr>');
    }
    $('#tool-cat-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_tool_category',id:$('#tc-id').val(),name:$('#tc-name').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            $('#tc-id').val(''); $('#tc-name').val('');
            $('#btn-save-tc').text('新增'); $('#btn-cancel-tc').hide();
            loadToolMg();
        },'json');
    });
    $('#tool-cat-list').on('click','.btn-edit-tc', function(){
        var $i=$(this).closest('.tool-cat-item');
        $('#tc-id').val($i.data('id')); $('#tc-name').val($i.data('name')).focus();
        $('#btn-save-tc').text('儲存'); $('#btn-cancel-tc').show();
    });
    $('#btn-cancel-tc').on('click', function(){
        $('#tc-id').val(''); $('#tc-name').val('');
        $('#btn-save-tc').text('新增'); $(this).hide();
    });
    $('#tool-cat-list').on('click','.btn-del-tc', function(){
        if(!confirm('確定刪除此量具種類？')) return;
        $.post(API,{action:'delete_tool_category',id:$(this).closest('.tool-cat-item').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            curToolCat=null; loadToolMg();
        },'json');
    });
    $('#tool-cat-list').on('click','.btn-replace-tc', function(e){
        e.stopPropagation();
        var $i=$(this).closest('.tool-cat-item'), oldId=$i.data('id');
        $('#replace-old-id').val(oldId); $('#replace-old-name').text($i.data('name'));
        $('#replace-new-id').html(toolMg.categories.filter(function(c){ return c.QC_Tool_List_id!=oldId; })
            .map(function(c){ return '<option value="'+c.QC_Tool_List_id+'">'+esc(c.QC_Tool)+'</option>'; }).join(''));
        $('#toolReplaceModal').modal('show');
    });
    $('#btn-confirm-replace').on('click', function(){
        $.post(API,{action:'replace_tool_category',old_id:$('#replace-old-id').val(),new_id:$('#replace-new-id').val()},function(res){
            if(!res.success){ alert(res.message||'取代失敗'); return; }
            $('#toolReplaceModal').modal('hide'); curToolCat=null; loadToolMg();
        },'json');
    });
    $('#tool-inst-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_tool_instance',id:$('#ti-id').val(),cat_id:$('#ti-cat-id').val(),no:$('#ti-no').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            $('#ti-id').val(''); $('#ti-no').val('');
            $('#btn-save-ti').text('新增編號'); $('#btn-cancel-ti').hide();
            loadToolMg();
        },'json');
    });
    $('#tool-inst-list').on('click','.btn-edit-ti', function(){
        $('#ti-id').val($(this).data('id')); $('#ti-no').val($(this).data('no')).focus();
        $('#btn-save-ti').text('儲存'); $('#btn-cancel-ti').show();
    });
    $('#btn-cancel-ti').on('click', function(){
        $('#ti-id').val(''); $('#ti-no').val('');
        $('#btn-save-ti').text('新增編號'); $(this).hide();
    });
    $('#tool-inst-list').on('click','.btn-del-ti', function(){
        if(!confirm('確定刪除此量具編號？')) return;
        $.post(API,{action:'delete_tool_instance',id:$(this).data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadToolMg();
        },'json');
    });

    // ============ 設定：幾何公差管理 ============
    $('#btn-special-setting').on('click', function(e){ e.preventDefault(); loadSpecialItems(); $('#specialItemManageModal').modal('show'); });
    function loadSpecialItems(){
        $('#manage-special-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'get_special_items'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var items=res.special_items||[];
            $('#manage-special-list').html(items.length ? items.map(function(it){
                return '<div class="list-group-item clearfix" data-id="'+it.id+'" data-name="'+esc(it.name)+'" data-symbol="'+esc(it.symbol||'')+'" data-code="'+esc(it.code||'')+'">'+
                    '<span class="badge pull-left" style="margin-right:10px;background:#777;">'+esc(it.symbol||'')+'</span>'+
                    '<strong>'+esc(it.name)+'</strong> <small class="text-muted">('+esc(it.code||'')+')</small>'+
                    '<div class="pull-right">'+
                    '<button class="btn btn-xs btn-info btn-edit-si"><i class="fa fa-pencil"></i></button> '+
                    '<button class="btn btn-xs btn-danger btn-del-si"><i class="fa fa-trash"></i></button>'+
                    '</div></div>';
            }).join('') : '<div class="list-group-item text-muted">尚無資料，請於上方新增</div>');
        },'json');
    }
    $('#special-item-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_special_item',id:$('#si-id').val(),name:$('#si-name').val(),symbol:$('#si-symbol').val(),code:$('#si-code').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetSiForm(); loadSpecialItems();
        },'json');
    });
    $('#manage-special-list').on('click','.btn-edit-si', function(){
        var $i=$(this).closest('.list-group-item');
        $('#si-id').val($i.data('id')); $('#si-name').val($i.data('name'));
        $('#si-symbol').val($i.data('symbol')); $('#si-code').val($i.data('code'));
        $('#btn-save-si').text('儲存'); $('#btn-cancel-si').show();
    });
    $('#btn-cancel-si').on('click', resetSiForm);
    function resetSiForm(){
        $('#si-id').val(''); $('#special-item-form')[0].reset();
        $('#btn-save-si').text('新增'); $('#btn-cancel-si').hide();
    }
    $('#manage-special-list').on('click','.btn-del-si', function(){
        if(!confirm('確定刪除？')) return;
        $.post(API,{action:'delete_special_item',id:$(this).closest('.list-group-item').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadSpecialItems();
        },'json');
    });

    // ============ 設定：通用樣板管理（建立/更新用主畫面表格內容） ============
    var editingTplId = null;
    $('#btn-template-setting').on('click', function(e){ e.preventDefault(); loadTplManage(); $('#templateManageModal').modal('show'); });
    function loadTplManage(){
        $('#template-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'manage_templates',sub_action:'list'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var d=res.data||[];
            $('#template-list').html(d.length ? d.map(function(t){
                return '<div class="list-group-item clearfix"><strong>'+esc(t.template_name)+'</strong>'+
                    '<div class="pull-right">'+
                    '<button class="btn btn-xs btn-info btn-edit-tpl" data-id="'+t.template_id+'" data-name="'+esc(t.template_name)+'"><i class="fa fa-pencil"></i> 編輯</button> '+
                    '<button class="btn btn-xs btn-danger btn-del-tpl" data-id="'+t.template_id+'"><i class="fa fa-trash"></i> 刪除</button>'+
                    '</div></div>';
            }).join('') : '<div class="list-group-item text-muted">尚無樣板</div>');
        },'json');
    }
    $('#btn-create-template').on('click', function(){
        var name=$('#new-template-name').val().trim();
        if(!name){ alert('請輸入樣板名稱'); $('#new-template-name').focus(); return; }
        var items=collectItems();
        if(!items.length){ alert('主畫面檢驗項目表是空的，無法建立樣板'); return; }
        var payload={action:'manage_templates',sub_action:'save',name:name,items:JSON.stringify(items)};
        if(editingTplId) payload.template_id=editingTplId;
        var $b=$(this).prop('disabled',true);
        $.post(API,payload,function(res){
            $b.prop('disabled',false);
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetTplEdit(); loadTplManage();
        },'json').fail(function(){ $b.prop('disabled',false); alert('儲存錯誤'); });
    });
    $('#template-list').on('click','.btn-edit-tpl', function(){
        if(!confirm('這將清空目前主畫面的檢驗項目表，並載入此樣板內容供編輯，確定嗎？')) return;
        var tid=$(this).data('id'), tname=$(this).data('name');
        $.post(API,{action:'manage_templates',sub_action:'get_items',template_id:tid},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            renderItems(res.items||[]);
            $('#no-std-hint').hide();
            editingTplId=tid;
            $('#new-template-name').val(tname);
            $('#btn-create-template').text('更新樣板').removeClass('btn-success').addClass('btn-warning');
            $('#btn-cancel-edit-template').show();
            $('#templateManageModal').modal('hide');
            $('html,body').animate({scrollTop:$('#items-table').offset().top-120},300);
        },'json');
    });
    $('#template-list').on('click','.btn-del-tpl', function(){
        if(!confirm('確定刪除此樣板？')) return;
        $.post(API,{action:'manage_templates',sub_action:'delete',template_id:$(this).data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadTplManage();
        },'json');
    });
    $('#btn-cancel-edit-template').on('click', resetTplEdit);
    function resetTplEdit(){
        editingTplId=null;
        $('#new-template-name').val('');
        $('#btn-create-template').text('從當前表格建立樣板').removeClass('btn-warning').addClass('btn-success');
        $('#btn-cancel-edit-template').hide();
    }

    // ============ 設定：抽樣規則 ============
    $('#btn-sampling-setting').on('click', function(e){ e.preventDefault(); loadRules(); $('#samplingRuleModal').modal('show'); });
    function loadRules(){
        $('#rule-list').html('<tr><td colspan="3" class="text-center text-muted">載入中…</td></tr>');
        $.post(API,{action:'manage_sampling_rules',sub_action:'list'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var d=res.data||[];
            $('#rule-list').html(d.length ? d.map(function(r){
                return '<tr data-id="'+r.rule_id+'" data-min="'+r.min_qty+'" data-max="'+r.max_qty+'" data-sample="'+r.sample_qty+'">'+
                    '<td>'+r.min_qty+' ~ '+r.max_qty+'</td><td>'+r.sample_qty+'</td><td>'+
                    '<button class="btn btn-xs btn-info btn-edit-rule"><i class="fa fa-pencil"></i></button> '+
                    '<button class="btn btn-xs btn-danger btn-del-rule"><i class="fa fa-trash"></i></button></td></tr>';
            }).join('') : '<tr><td colspan="3" class="text-center text-muted">尚無規則（無規則時系統用簡易推估：≥500抽8、≥100抽5、其餘抽3）</td></tr>');
        },'json');
    }
    $('#rule-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'manage_sampling_rules',sub_action:'save',id:$('#rule-id').val(),
            min:$('#rule-min').val(),max:$('#rule-max').val(),sample:$('#rule-sample').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetRuleForm(); loadRules();
        },'json');
    });
    $('#rule-list').on('click','.btn-edit-rule', function(){
        var $tr=$(this).closest('tr');
        $('#rule-id').val($tr.data('id'));
        $('#rule-min').val($tr.data('min')); $('#rule-max').val($tr.data('max')); $('#rule-sample').val($tr.data('sample'));
        $('#btn-save-rule').text('儲存'); $('#btn-cancel-rule').show();
    });
    $('#btn-cancel-rule').on('click', resetRuleForm);
    function resetRuleForm(){
        $('#rule-id').val(''); $('#rule-form')[0].reset();
        $('#btn-save-rule').text('新增'); $('#btn-cancel-rule').hide();
    }
    $('#rule-list').on('click','.btn-del-rule', function(){
        if(!confirm('確定刪除此規則？')) return;
        $.post(API,{action:'manage_sampling_rules',sub_action:'delete',id:$(this).closest('tr').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadRules();
        },'json');
    });

    // ============ #8 同料號歷次檢驗查詢 ============
    $('#btn-history').on('click', function(){
        if(!ctx || !ctx.d_id){ alert('請先由待驗清單開啟一筆檢驗（需有料號）再查詢歷史。'); return; }
        openPartHistory();
    });
    function ensureHistoryModal(){
        if($('#partHistModal').length) return;
        $('body').append(
          '<div class="modal fade" id="partHistModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">'+
          '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>'+
          '<h4 class="modal-title"><i class="fa fa-history"></i> 同料號歷次檢驗</h4></div>'+
          '<div class="modal-body"><div id="partHistList">載入中…</div><div id="partHistDetail" style="margin-top:12px;"></div></div>'+
          '<div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>'+
          '</div></div></div>');
    }
    function openPartHistory(){
        ensureHistoryModal();
        $('#partHistDetail').empty(); $('#partHistList').html('載入中…');
        $('#partHistModal').modal('show');
        $.post(API,{action:'history_by_part', d_id:ctx.d_id}, function(res){
            if(!res.success){ $('#partHistList').html('查詢失敗：'+esc(res.message||'')); return; }
            var rows=res.rows||[];
            if(!rows.length){ $('#partHistList').html('<div class="text-muted">此料號尚無歷史檢驗紀錄。</div>'); return; }
            var h='<div class="text-muted" style="margin-bottom:6px;">料號 <b>'+esc(ctx.part_no||'')+'</b>　共 '+rows.length+' 筆（點「檢視」看逐項實測與同尺寸落點）</div>'+
              '<div style="max-height:230px;overflow:auto;"><table class="table table-condensed table-bordered"><thead><tr>'+
              '<th>日期</th><th>製令</th><th>製程</th><th>批/複</th><th>判定</th><th>不良</th><th>檢驗人</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r){
                var d=String(r.check_date||r.created_at||'').substring(0,16);
                h+='<tr><td>'+esc(d)+'</td><td>'+esc(r.bom||'')+'</td><td>'+esc(r.process_name||'')+'</td>'+
                   '<td>'+(r.batch_no||1)+'/'+(r.round_no||1)+'</td><td>'+statusLabel(r.check_result)+'</td>'+
                   '<td>'+(r.ng_qty||0)+'</td><td>'+esc(r.user_cname||r.created_by||'')+'</td>'+
                   '<td><button class="btn btn-xs btn-primary ph-view" data-id="'+r.qc_form_id+'">檢視</button></td></tr>';
            });
            h+='</tbody></table></div>';
            $('#partHistList').html(h);
        }, 'json');
    }
    $(document).on('click','.ph-view', function(){
        var id=$(this).data('id');
        $('#partHistDetail').html('載入中…');
        $.post(API,{action:'get_history_record',qc_form_id:id}, function(res){
            if(!res.success){ $('#partHistDetail').html('載入失敗：'+esc(res.message||'')); return; }
            $('#partHistDetail').html(renderHistDetail(res));
        }, 'json');
    });
    function renderHistDetail(res){
        var h=res.header, its=res.items||[];
        var out='<div class="well well-sm" style="margin-bottom:8px;"><b>逐項實測</b>（單號 '+h.qc_form_id+'；送驗 '+(h.incoming_qty||0)+'／抽驗 '+(h.sample_qty||0)+'；整體 '+(h.check_result==='NG'?'<span class="text-danger">不良</span>':'合格')+'）</div>';
        out+='<div style="max-height:300px;overflow:auto;"><table class="table table-condensed table-bordered"><thead><tr><th>項目</th><th>標準</th><th>量具</th><th>實測（各PCS）</th><th>判定</th></tr></thead><tbody>';
        its.forEach(function(it){
            var readings=[{tool:(it.tool||''), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:(ex.method||ex.tool_no||''), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var vals=(rd.samples||[]).map(function(s){ return (s&&s.v!==''&&s.v!=null)?('<span class="'+((s.r==='NG')?'text-danger':'')+'">'+esc(s.v)+'</span>'):'·'; }).join('　');
                out+='<tr>'+(ri===0?('<td rowspan="'+readings.length+'">'+esc(it.name)+'</td><td rowspan="'+readings.length+'">'+esc(it.std||'')+((it.up||it.lo)?(' ('+esc(it.up||'')+'/'+esc(it.lo||'')+')'):'')+'</td>'):'')+
                    '<td>'+esc(rd.tool||'')+'</td><td>'+vals+'</td>'+(ri===0?('<td rowspan="'+readings.length+'">'+(it.verdict==='NG'?'<span class="text-danger">NG</span>':(it.verdict==='AOD'?'特採':'OK'))+'</td>'):'')+'</tr>';
            });
            if(it.remark) out+='<tr><td colspan="5" class="text-muted" style="font-size:12px">備註：'+esc(it.remark)+'</td></tr>';
        });
        out+='</tbody></table></div>';
        return out;
    }

    // ============ #7 列印 / 匯出 CSV ============
    $('body').append('<div id="print-area"></div>');
    function toolLabelById(id){
        if(!id) return '';
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].id===String(id)) return (TOOL_INSTANCES[i].cat?TOOL_INSTANCES[i].cat+' / ':'')+TOOL_INSTANCES[i].no; }
        return '';
    }
    function currentMeta(){
        return {
            part:(ctx&&ctx.part_no)||'', client:(ctx&&ctx.client)||'', bom:(ctx&&ctx.bom)||'',
            process:(ctx&&ctx.process)||'',
            incoming:parseInt($('#inp-qty').val())||0, sample:parseInt($('#inp-sample').val())||0,
            remark:$('#inp-remark').val()||'',
            judge:($('#verdict-cells .pcs-verdict.ng-value').length>0?'不良':'合格'), ng:($('#inp-ng').val()||'0')
        };
    }
    function buildPrintHtml(){
        var m=currentMeta(), items=collectItems(), n=state.sampleN;
        var now=new Date(), pad=function(x){return('0'+x).slice(-2);};
        var dateStr=now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
        var head='<div class="pr-title">品管檢驗記錄表</div><div class="pr-sub">表單編號 2-QA-01-06（線上檢驗系統列印）</div>'+
            '<table class="pr-meta"><tr>'+
            '<td class="k">料號</td><td>'+esc(m.part)+'</td><td class="k">客戶</td><td>'+esc(m.client)+'</td><td class="k">製令/BOM</td><td>'+esc(m.bom)+'</td></tr>'+
            '<tr><td class="k">製程</td><td>'+esc(m.process)+'</td><td class="k">送驗數</td><td>'+m.incoming+'</td><td class="k">抽驗數</td><td>'+m.sample+'</td></tr>'+
            '<tr><td class="k">日期</td><td>'+dateStr+'</td><td class="k">整體判定</td><td>'+m.judge+'（不良 '+m.ng+'）</td><td class="k">備註</td><td>'+esc(m.remark)+'</td></tr></table>';
        var pcsHead=''; for(var i=1;i<=n;i++) pcsHead+='<th>'+i+'</th>';
        var body='';
        items.forEach(function(it,idx){
            var readings=[{tool:toolLabelById(it.tool_id), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:toolLabelById(ex.tool_id), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var cells='';
                for(var i=0;i<n;i++){ var s=(rd.samples||[])[i]; var v=(s&&s.v!=null&&s.v!=='')?s.v:''; cells+='<td'+((s&&s.r==='NG')?' class="pr-ng"':'')+'>'+esc(v)+'</td>'; }
                body+='<tr>'+
                    (ri===0?('<td rowspan="'+readings.length+'">'+(idx+1)+'</td><td rowspan="'+readings.length+'" style="text-align:left">'+esc(it.name)+'</td><td rowspan="'+readings.length+'">'+esc(it.std||'')+'</td><td rowspan="'+readings.length+'">'+esc((it.up||'')+(it.lo?(' / '+it.lo):''))+'</td>'):'')+
                    '<td>'+esc(rd.tool||'')+'</td>'+cells+
                    (ri===0?('<td rowspan="'+readings.length+'">'+(it.verdict==='NG'?'NG':(it.verdict==='AOD'?'特採':'OK'))+'</td>'):'')+'</tr>';
                if(ri===0 && it.remark){ body+='<tr><td colspan="'+(5+n)+'" style="text-align:left;font-size:11px">備註：'+esc(it.remark)+'</td></tr>'; }
            });
        });
        var tbl='<table class="pr-items"><thead><tr><th>編號</th><th>檢驗項目</th><th>標準</th><th>公差</th><th>量具</th>'+pcsHead+'<th>判定</th></tr></thead><tbody>'+body+'</tbody></table>';
        var sign='<table class="pr-sign"><tr><td>檢驗員<div class="lbl">Inspector</div></td><td>主管審核<div class="lbl">Approved</div></td><td>日期<div class="lbl">Date</div></td></tr></table>';
        return head+tbl+sign;
    }
    $('#btn-print').on('click', function(){
        if(!ctx){ alert('請先由待驗清單開啟一筆檢驗再列印。'); return; }
        if(!collectItems().length){ alert('尚無檢驗項目可列印。'); return; }
        $('#print-area').html(buildPrintHtml());
        window.print();
    });
    // CSV：每筆讀值一列（含加量測），UTF-8 BOM 讓 Excel 正確顯示中文
    $('#btn-csv').on('click', function(){
        if(!ctx){ alert('請先開啟一筆檢驗再匯出。'); return; }
        var items=collectItems(); if(!items.length){ alert('尚無檢驗項目可匯出。'); return; }
        var n=state.sampleN, m=currentMeta();
        var head=['編號','檢驗項目','標準','上公差','下公差','量具']; for(var i=1;i<=n;i++) head.push('PCS'+i); head.push('判定','備註');
        var q=function(s){ s=(s==null?'':String(s)); return '"'+s.replace(/"/g,'""')+'"'; };
        var lines=[head.map(q).join(',')];
        items.forEach(function(it,idx){
            var readings=[{tool:toolLabelById(it.tool_id), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:toolLabelById(ex.tool_id), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var row=[ri===0?(idx+1):'', ri===0?it.name:'', ri===0?(it.std||''):'', ri===0?(it.up||''):'', ri===0?(it.lo||''):'', rd.tool||''];
                for(var i=0;i<n;i++){ var s=(rd.samples||[])[i]; row.push((s&&s.v!=null)?s.v:''); }
                row.push(ri===0?(it.verdict||''):'', ri===0?(it.remark||''):'');
                lines.push(row.map(q).join(','));
            });
        });
        var csv='﻿'+lines.join('\r\n');
        var blob=new Blob([csv],{type:'text/csv;charset=utf-8;'});
        var a=document.createElement('a'), url=URL.createObjectURL(blob);
        a.href=url; a.download='檢驗記錄_'+(m.part||'')+'_'+m.process+'.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    });
});
</script>
</body>
</html>
