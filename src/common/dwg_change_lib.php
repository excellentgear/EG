<?php
/**
 * dwg_change_lib.php — 圖面變更：schema／判定／建立變更紀錄（唯一實作點）
 *
 * 判準完整說明見 `ai-rules/15-圖面變更判定依據.md`，這裡只講重點：
 * 判斷「這張圖是不是改過」一律用 **發行章日期**，不是版次。
 *   - 很多客戶圖面根本沒有版次，版次不能設必填也不能當判準
 *   - 同一標籤下不同版次可以並存、舊的不一定會被作廢，所以「作廢」不是可靠訊號
 *   - 原圖（客戶圖）更新常常只是報價階段更新、根本還沒接單，不可觸發
 * 因此只有「自家出的圖」標籤（quotation_file_categories.is_own_drawing=1，
 * 預設是 BOSS圖／++圖／單製 ++圖）要填發行章日期並參與判定。
 *
 * 禁止各頁自己寫這段判定或自己 INSERT qc_drawing_change——會像過去的側欄/輸入欄位一樣失守。
 */

require_once __DIR__ . '/dwg_notify.php';
require_once __DIR__ . '/date_fmt_lib.php';   // 日期顯示一律 YYYY.MM.DD（ai-rules/20）

if (!function_exists('dwg_ensure_schema')) {

/** 欄位補建（沿用本專案慣例：ALTER 包 try，重複執行無害）。sql.php 擋 DDL，故走程式面 migration。 */
function dwg_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN issue_stamp_date DATE NULL COMMENT '發行章日期（自家出圖蓋章日；圖面變更新舊依據，預設帶上傳日可改）'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE part_attachments ADD INDEX idx_issue_stamp (d_id, issue_stamp_date)"); } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN is_own_drawing TINYINT NOT NULL DEFAULT 0 COMMENT '1=自家出的圖（需填發行章日期並參與圖面變更判定）'");
        // 只有「剛加上這欄」時才預設勾這三個；之後由使用者在標籤設定自行調整，不再被覆寫
        $pdo->exec("UPDATE quotation_file_categories SET is_own_drawing=1 WHERE category_name IN ('BOSS圖','++圖','單製 ++圖')");
    } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN trigger_attachment_id INT NULL COMMENT '觸發此變更的料號附件 part_attachments.id（由附件上傳自動判定產生時才有）'"); } catch (Throwable $e) {}
    // 製程標籤（2026-08-20 使用者要求）：同一個料號常常有多個加工項目各自一張圖
    // （實例：MHGC0300191-FW080-2 同時有 -1、-2 兩組 BOSS圖＋++圖），
    // 沒有這一欄的話 -2 改版重出會被判成 -1 的圖面變更。留空＝共用圖，見 dwg_classify_upload()。
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN process_tag VARCHAR(100) NULL COMMENT '製程標籤（此圖對應哪個加工項目；空=共用圖，與所有製程一起比對新舊版）'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE part_attachments ADD INDEX idx_proc_tag (d_id, process_tag)"); } catch (Throwable $e) {}

    // ── 2026-08-21 使用者要求：版次分「客戶版次」與「廠內版次」兩種 ──
    // 客戶版次＝客戶圖自己的版次（很多客戶圖根本沒有，所以不能必填）；
    // 廠內版次＝目前一律用「發行章日期」控制（見 ai-rules/15），所以這兩欄存的是日期字串。
    // rev_scope：customer＝客戶改圖（客戶版次與廠內版次都要換）／internal＝廠內自行改圖（只換廠內版次）。
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN rev_scope VARCHAR(10) NOT NULL DEFAULT 'customer' COMMENT 'customer=客戶版次變更(客戶+廠內都換) / internal=僅廠內版次變更'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN int_old_revision VARCHAR(30) NULL COMMENT '廠內變更前版次（舊圖發行章日期）'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN int_new_revision VARCHAR(30) NULL COMMENT '廠內變更後版次（新圖發行章日期）'"); } catch (Throwable $e) {}
    // 草稿：自動偵測到換圖時先建成 DRAFT（不通知、不複製檢驗標準版次），使用者補完內容按送出才正式成立
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN submitted_at DATETIME NULL COMMENT '草稿送出（正式成立）時間；DRAFT 時為 NULL'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN updated_by INT NULL COMMENT '最後修改人 user.id'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN updated_at DATETIME NULL"); } catch (Throwable $e) {}
    // 自動建立的來源：attach=料號附件上傳／imgedit=批圖編輯器／manual=手動登錄
    try { $pdo->exec("ALTER TABLE qc_drawing_change ADD COLUMN create_source VARCHAR(10) NOT NULL DEFAULT 'manual' COMMENT 'manual/attach/imgedit'"); } catch (Throwable $e) {}

    // 預設簽收（通知）對象：全站一組（使用者拍板）。開單者可以再加人，但不可移除這裡設定的對象。
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS qc_drawing_change_default_ack (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            target_type VARCHAR(10) NOT NULL COMMENT 'user=個人 / dept=部門(含子部門)',
            target_id   INT         NOT NULL,
            updated_by  VARCHAR(50) NULL,
            updated_at  DATETIME    NULL,
            UNIQUE KEY uk_target (target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='圖面變更：全站預設簽收對象（開單者不可移除）'");
    } catch (Throwable $e) {}

    // 料號版次異動紀錄（使用者要求：主檔「版次」旁邊要看得到誰改的、什麼時候改的、對應的廠內版次是哪一天）
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS d_setting_revision_log (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            d_id         INT NOT NULL,
            old_revision VARCHAR(50) NULL COMMENT '變更前客戶版次',
            new_revision VARCHAR(50) NULL COMMENT '變更後客戶版次',
            int_old_revision VARCHAR(30) NULL COMMENT '對應的廠內變更前版次（舊圖發行日）',
            int_new_revision VARCHAR(30) NULL COMMENT '對應的廠內變更後版次（新圖發行日）',
            change_id    INT NULL COMMENT '來源的圖面變更單 qc_drawing_change.id；手動改主檔時為 NULL',
            source       VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual=主檔手動修改 / dwg_change=圖面變更單回寫',
            changed_by   INT NULL,
            changed_by_name VARCHAR(50) NULL,
            changed_at   DATETIME NULL,
            note         VARCHAR(255) NULL,
            KEY idx_did (d_id, changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料號版次異動紀錄（客戶版次＋對應廠內版次）'");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN is_obsolete_mark TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=此標籤代表「已作廢」，掛上的附件不參與新舊版判定'");
        // 只有「剛加上這欄」時才預設勾名稱叫「作廢」的那一個；之後由使用者在標籤設定自行調整，
        // 不再被覆寫（比照 is_own_drawing 的既有作法，避免使用者改過的設定被下次執行洗掉）
        $pdo->exec("UPDATE quotation_file_categories SET is_obsolete_mark=1 WHERE category_name='作廢'");
    } catch (Throwable $e) {}
}

/** 「已作廢」標籤 id 清單（掛到這些標籤的附件一律不參與新舊版判定） */
function dwg_obsolete_cat_ids(PDO $pdo): array {
    dwg_ensure_schema($pdo);
    try {
        $r = $pdo->query("SELECT id FROM quotation_file_categories WHERE is_obsolete_mark=1")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $r);
    } catch (Throwable $e) { return []; }
}

/**
 * 此料號的製程標籤候選清單（2026-08-20 使用者拍板：只列「訂單內此料號有過的製程」，
 * 不列 process_no 全表 205 筆——那份主檔是全公司的製程，跟這個料號有沒有這道加工無關）。
 *
 * 另外把「此料號的附件已經打過的製程標籤」也一起列出來＝使用者要的「每個料號分開記憶」：
 * 打過一次之後同一個料號再上傳就選得到，不必重打，也不會因為舊訂單被刪掉就選不到既有的值。
 *
 * 比對範圍是整組同料號的 d_setting（同 dwg_sibling_d_ids），不然多客戶的同一個料號會各記各的。
 *
 * @return array<int,array{value:string,source:string}> value=製程字串，source=used/order
 */
function dwg_process_candidates(PDO $pdo, int $dId): array {
    dwg_ensure_schema($pdo);
    $dIds = dwg_sibling_d_ids($pdo, $dId);
    $ph   = implode(',', array_fill(0, count($dIds), '?'));
    $out  = [];
    // ① 此料號的附件已經用過的（使用者要的「自動記憶在此料號內」）
    try {
        $st = $pdo->prepare("SELECT process_tag, MAX(uploaded_at) AS last_at
                               FROM part_attachments
                              WHERE d_id IN ($ph) AND deleted_at IS NULL
                                AND process_tag IS NOT NULL AND process_tag <> ''
                              GROUP BY process_tag ORDER BY last_at DESC");
        $st->execute($dIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['process_tag']] = ['value' => $r['process_tag'], 'source' => 'used'];
        }
    } catch (Throwable $e) {}
    // ② 此料號的訂單加工項目（order_track.Processing_items，例：「全製 (齒研)」「代料到齒研」「拉串」）
    try {
        $st = $pdo->prepare("SELECT Processing_items AS p, MAX(Order_date) AS last_od
                               FROM order_track
                              WHERE d_id_ID IN ($ph) AND Processing_items IS NOT NULL AND Processing_items <> ''
                              GROUP BY Processing_items ORDER BY last_od DESC");
        $st->execute($dIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $v = trim((string)$r['p']);
            if ($v !== '' && !isset($out[$v])) $out[$v] = ['value' => $v, 'source' => 'order'];
        }
    } catch (Throwable $e) {}
    return array_values($out);
}

/** 「自家出的圖」標籤 id 清單 */
function dwg_own_drawing_cat_ids(PDO $pdo): array {
    dwg_ensure_schema($pdo);
    try {
        $r = $pdo->query("SELECT id FROM quotation_file_categories WHERE is_own_drawing=1 AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $r);
    } catch (Throwable $e) { return []; }
}

/**
 * 同一個料號（D_Setting_Id）底下的所有 d_setting.d_id。
 *
 * 為什麼要有這支：同一個料號常常有多筆 d_setting（不同客戶，或不小心建重複的），
 * 圖面卻是同一張。圖面檢視（bom_viewer/part_viewer）本來就是依 D_Setting_Id 把
 * 各 d_id 的附件合併顯示，所以圖面變更判定也必須跟著看整組，
 * 否則會出現「畫面明明看得到舊圖，系統卻說這是首次發行」。
 * 查不到料號字串時回退成只有自己，行為與舊版相同。
 *
 * @return int[] 至少包含傳入的 $dId
 */
function dwg_sibling_d_ids(PDO $pdo, int $dId): array {
    try {
        $st = $pdo->prepare("SELECT s.d_id FROM d_setting s
                             WHERE s.D_Setting_Id = (SELECT D_Setting_Id FROM d_setting WHERE d_id=?)
                               AND s.D_Setting_Id IS NOT NULL AND s.D_Setting_Id <> ''");
        $st->execute([$dId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { $ids = []; }
    if (!in_array($dId, $ids, true)) $ids[] = $dId;
    return array_values(array_unique($ids));
}

/** 傳入的標籤裡有沒有「自家出的圖」——有就代表這次上傳要填發行章日期 */
function dwg_needs_issue_date(PDO $pdo, array $catIds): bool {
    $own = dwg_own_drawing_cat_ids($pdo);
    if (!$own) return false;
    return (bool)array_intersect(array_map('intval', $catIds), $own);
}

/**
 * 把這次的比對範圍講成人話（「BOSS圖／製程：拉串」），用在判定訊息上。
 * 沒有這一段的話，使用者看到「首次發行」但畫面上明明有別張圖，會以為系統壞了——
 * 其實只是不同標籤或不同加工項目、本來就各自算各自的。
 */
function dwg_scope_desc(PDO $pdo, array $catIds, string $proc): string {
    $names = [];
    if ($catIds) {
        try {
            $ph = implode(',', array_fill(0, count($catIds), '?'));
            $st = $pdo->prepare("SELECT category_name FROM quotation_file_categories WHERE id IN ($ph) ORDER BY sort_order, id");
            $st->execute($catIds);
            $names = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {}
    }
    $s = $names ? implode('／', $names) : '自家出的圖';
    return $s . '／製程：' . ($proc !== '' ? $proc : '（共用，未指定製程）');
}

/**
 * 判定這次上傳是「首次發行」還是「圖面變更」。
 *
 * 比對範圍（2026-08-20 使用者拍板重新定義，取代原本「所有自家圖標籤合併成一組」的作法）：
 *   ① 同一個料號（含同料號的其他 d_setting，見 dwg_sibling_d_ids）
 *   ② **同一個標籤**——BOSS圖 只跟 BOSS圖 比、++圖 只跟 ++圖 比、單製 ++圖 再自成一組。
 *      舊版是把三個標籤合併成一組（理由是「BOSS圖與++圖是同一次出圖的兩種呈現」），
 *      使用者明確要求改成分開：那三種圖本來就各自有自己的新舊版脈絡。
 *      同一批上傳 BOSS圖＋++圖 仍然只會跳一次變更登錄，因為上傳端本來就對整批取第一個判定
 *      （見 master_data_management.php 的 changeHit），不會因為分開比就變成兩筆。
 *   ③ **製程標籤相同**——同一個料號的不同加工項目各自有自己的圖，不可互相判新舊。
 *      任一方留空＝共用圖，共用圖跟所有製程一起比（使用者拍板）。
 *   ④ 掛「已作廢」標籤的附件不參與判定（不會被當成前一版，也不會把別人擠成舊版）。
 *      註：ai-rules/15 講的是「作廢」不可以拿來當**判準**（舊版不一定會被標作廢），
 *      這裡是反過來用——有標作廢的一定不是現行版，方向不同不牴觸。
 *
 * @param string|null $processTag 這次上傳的製程標籤；null/'' ＝共用圖
 * @return array{kind:string, prev_date:?string, prev_name:?string, message:string, needs_date:bool}
 *   kind: none=不是自家出圖標籤不判定／first=首次發行／change=變更／same=補件或重掃／older=補登舊版
 */
function dwg_classify_upload(PDO $pdo, int $dId, array $catIds, ?string $issueDate, ?string $processTag = null): array {
    $out = ['kind' => 'none', 'prev_date' => null, 'prev_name' => null, 'message' => '',
            'needs_date' => false, 'issue_date' => $issueDate,
            'prev_other_d_id' => null, 'prev_other_note' => '',
            'process_tag' => (string)$processTag, 'prev_process_tag' => ''];
    if (!dwg_needs_issue_date($pdo, $catIds)) return $out;
    $out['needs_date'] = true;
    if (!$issueDate) { $out['message'] = '此標籤屬於「自家出的圖」，必須填發行章日期'; return $out; }

    // 只比「這次上傳自己帶的那幾個自家圖標籤」，不再把全部自家圖標籤混在一起（②）
    $own     = dwg_own_drawing_cat_ids($pdo);
    $sameTag = array_values(array_intersect(array_map('intval', $catIds), $own));
    if (!$sameTag) return $out;   // 理論上不會發生（前面 dwg_needs_issue_date 已經確認有交集）
    $obsolete = dwg_obsolete_cat_ids($pdo);
    $proc     = trim((string)$processTag);
    $prev = null;
    try {
        // 比對範圍要涵蓋「同一個料號的所有 d_setting 列」，不能只看傳進來的 d_id。
        // 同一個料號常常有多筆 d_setting（不同客戶、或建重複），圖卻是同一張；
        // 只比單一 d_id 會把「其實已經有舊圖」誤判成首次發行而不跳變更登錄。
        // 圖面檢視（bom_viewer/part_viewer）本來就是把同料號各 d_id 的附件合併顯示，
        // 判定跟著一致才不會出現「畫面看得到舊圖、系統卻說這是首次發行」。
        $dIds = dwg_sibling_d_ids($pdo, $dId);
        $dPh  = implode(',', array_fill(0, count($dIds), '?'));
        $args = $dIds;
        // ② 標籤：FIND_IN_SET 逐一比對（category_ids 是逗號字串），只找同標籤的
        $ors  = implode(' OR ', array_fill(0, count($sameTag), 'FIND_IN_SET(?, pa.category_ids)'));
        $args = array_merge($args, $sameTag);
        $sql  = "SELECT pa.id, pa.d_id, pa.original_name, pa.issue_stamp_date, pa.process_tag
                   FROM part_attachments pa
                  WHERE pa.d_id IN ($dPh) AND pa.deleted_at IS NULL
                    AND pa.issue_stamp_date IS NOT NULL AND ($ors)";
        // ④ 已作廢的不參與
        if ($obsolete) {
            $sql .= ' AND NOT (' . implode(' OR ', array_fill(0, count($obsolete), 'FIND_IN_SET(?, pa.category_ids)')) . ')';
            $args = array_merge($args, $obsolete);
        }
        // ③ 製程：本次有填→只比同製程或對方留空（共用圖）；本次留空→共用圖跟全部一起比，不加條件
        if ($proc !== '') {
            $sql .= " AND (pa.process_tag = ? OR pa.process_tag IS NULL OR pa.process_tag = '')";
            $args[] = $proc;
        }
        $sql .= ' ORDER BY pa.issue_stamp_date DESC, pa.id DESC LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $prev = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($prev) {
            $out['prev_process_tag'] = (string)($prev['process_tag'] ?? '');
            if ((int)$prev['d_id'] !== $dId) $out['prev_other_d_id'] = (int)$prev['d_id'];
        }
    } catch (Throwable $e) { return $out; }

    if (!$prev) {
        $out['kind'] = 'first';
        $out['message'] = '此料號在「' . dwg_scope_desc($pdo, $sameTag, $proc) . '」底下第一次有帶發行章日期的圖面＝首次發行，不需要登錄圖面變更。';
        return $out;
    }
    $out['prev_date'] = (string)$prev['issue_stamp_date'];
    $out['prev_name'] = (string)($prev['original_name'] ?? '');
    // 舊圖掛在同料號的另一筆 d_setting（多客戶或重複建檔）時要講明白，否則使用者會覺得莫名其妙
    if (!empty($out['prev_other_d_id'])) {
        try {
            $cq = $pdo->prepare("SELECT COALESCE(c.customer,'（未設客戶）') FROM d_setting s
                                 LEFT JOIN customer_list c ON c.customer_id=s.Customer_Id WHERE s.d_id=?");
            $cq->execute([$out['prev_other_d_id']]);
            $out['prev_other_note'] = '（前一版掛在同料號的另一筆料號主檔：' . (string)$cq->fetchColumn()
                                    . '，d_id ' . $out['prev_other_d_id'] . '）';
        } catch (Throwable $e) {}
    }
    // 比大小用原始 Y-m-d，顯示才轉 YYYY.MM.DD（ai-rules/20：只管顯示，不動查詢與儲存）
    $cmp  = strcmp($issueDate, $out['prev_date']);
    // 前一版如果是別的製程（＝其中一方是共用圖），一定要講出來，否則使用者無從判斷是不是選錯製程標籤
    $procNote = '';
    if ($out['prev_process_tag'] !== $proc) {
        $procNote = '（前一版製程：' . ($out['prev_process_tag'] !== '' ? $out['prev_process_tag'] : '共用')
                  . '，本次：' . ($proc !== '' ? $proc : '共用') . '）';
    }
    $prevShow = eg_fmt_date($out['prev_date']) . $out['prev_other_note'] . $procNote;
    if ($cmp > 0) {
        $out['kind'] = 'change';
        $out['message'] = '發行章日期比前一版（' . $prevShow . '）新＝這是一次圖面變更，請填寫變更內容。';
    } elseif ($cmp === 0) {
        $out['kind'] = 'same';
        $out['message'] = '發行章日期與前一版相同（' . $prevShow . '）＝視為補件／重掃，不另開變更紀錄。';
    } else {
        $out['kind'] = 'older';
        $out['message'] = '發行章日期比現有最新版（' . $prevShow . '）舊＝視為補登舊版，不另開變更紀錄。';
    }
    return $out;
}

/**
 * 簽收對象展開：可以指定「部門」也可以指定「個人」，兩者混合。
 *
 * 部門一律連子部門一起算（組織是樹狀：資材部→生管/採購/倉管組），
 * 沿用 org_role_lib 的 eg_dept_subtree_ids()，不要只比對單一 department_id。
 * 部門展開出來的人一律走 people_lib（人員列表鐵則：離職與特殊帳號不列）。
 *
 * @return int[] 去重後的 user_id
 */
function dwg_expand_ack_targets(PDO $pdo, array $userIds, array $deptIds): array {
    $out = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));
    if ($deptIds) {
        require_once __DIR__ . '/org_role_lib.php';
        require_once __DIR__ . '/people_lib.php';
        $all = [];
        foreach ($deptIds as $d) { foreach (eg_dept_subtree_ids($pdo, $d) as $sub) $all[$sub] = true; }
        if ($all) {
            foreach (eg_people_list($pdo, ['dept_ids' => array_keys($all)]) as $p) $out[] = (int)$p['id'];
        }
    }
    return array_values(array_unique(array_filter($out)));
}

/**
 * 全站「預設簽收對象」（使用者拍板：全站一組，不分客戶/製程）。
 * 開單者一定會收到這些人，且**不可移除**——守門在後端 dwg_create_change() 一律再併回去，
 * 不是只把前端的 × 拿掉而已（鐵律8：不做只擋 UI 的半套）。
 *
 * @return array{users:int[], depts:int[]}
 */
function dwg_default_ack_get(PDO $pdo): array {
    dwg_ensure_schema($pdo);
    $out = ['users' => [], 'depts' => []];
    try {
        foreach ($pdo->query("SELECT target_type, target_id FROM qc_drawing_change_default_ack")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['target_type'] === 'dept') $out['depts'][] = (int)$r['target_id'];
            else                              $out['users'][] = (int)$r['target_id'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 存全站預設簽收對象（整組覆寫）。呼叫端負責權限與 CSRF。 */
function dwg_default_ack_save(PDO $pdo, array $userIds, array $deptIds, string $byName): void {
    dwg_ensure_schema($pdo);
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM qc_drawing_change_default_ack");
        $ins = $pdo->prepare("INSERT INTO qc_drawing_change_default_ack (target_type, target_id, updated_by, updated_at) VALUES (?,?,?,NOW())");
        foreach ($userIds as $u) $ins->execute(['user', $u, $byName]);
        foreach ($deptIds as $d) $ins->execute(['dept', $d, $byName]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/**
 * 料號基本資料：料號字串、客戶名稱、目前客戶版次。
 * 「選了料號要自動帶出客戶名稱」全部走這一支，不要各頁自己 join
 * （客戶欄位是 d_setting.Customer_Id → customer_list.customer）。
 */
function dwg_part_info(PDO $pdo, int $dId): array {
    $out = ['d_id' => $dId, 'part_no' => '', 'customer_id' => 0, 'customer' => '', 'revision' => ''];
    try {
        $st = $pdo->prepare("SELECT s.D_Setting_Id, s.Revision, s.Customer_Id, c.customer
                               FROM d_setting s LEFT JOIN customer_list c ON c.customer_id = s.Customer_Id
                              WHERE s.d_id = ?");
        $st->execute([$dId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out['part_no']     = (string)$r['D_Setting_Id'];
            $out['revision']    = (string)($r['Revision'] ?? '');
            $out['customer_id'] = (int)($r['Customer_Id'] ?? 0);
            $out['customer']    = (string)($r['customer'] ?? '');
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * 廠內版次（＝發行章日期）的「變更前／變更後」自動帶值。
 *
 * 使用者要求：廠內版次若有舊圖，自動顯示**舊圖的發行日**為變更前版次、**新圖的發行日**為變更後版次。
 * 取值範圍與 dwg_classify_upload() 的判定範圍完全一致（同料號含 sibling、同標籤、同製程、排除作廢），
 * 否則畫面帶出來的日期會跟系統判定用的那一版對不起來。
 *
 * 同一天上傳的多張圖視同**同一版**（現場一張圖常掃成兩三個檔），所以「變更前」要往前找到
 * 第一個**日期不同且更舊**的那一版，不是單純取第二筆。
 *
 * @param int|null $triggerAttachId 有指定＝以這張附件當「變更後」那一版（自動偵測換圖時用）；
 *                                  null＝取目前最新的一版。
 * @return array{new_date:?string,new_name:?string,new_id:?int,old_date:?string,old_name:?string,old_id:?int}
 */
function dwg_internal_rev_pair(PDO $pdo, int $dId, array $catIds = [], ?string $processTag = null, ?int $triggerAttachId = null): array {
    dwg_ensure_schema($pdo);
    $out = ['new_date' => null, 'new_name' => null, 'new_id' => null,
            'old_date' => null, 'old_name' => null, 'old_id' => null];
    $own = dwg_own_drawing_cat_ids($pdo);
    if (!$own) return $out;
    // 沒指定標籤＝比全部自家圖標籤（手動登錄時使用者只選了料號，還沒有標籤脈絡）
    $tags = array_values(array_intersect(array_map('intval', $catIds), $own)) ?: $own;
    $obsolete = dwg_obsolete_cat_ids($pdo);
    $proc = trim((string)$processTag);
    try {
        $dIds = dwg_sibling_d_ids($pdo, $dId);
        $args = $dIds;
        $sql  = "SELECT pa.id, pa.original_name, pa.issue_stamp_date, pa.process_tag
                   FROM part_attachments pa
                  WHERE pa.d_id IN (" . implode(',', array_fill(0, count($dIds), '?')) . ")
                    AND pa.deleted_at IS NULL AND pa.issue_stamp_date IS NOT NULL
                    AND (" . implode(' OR ', array_fill(0, count($tags), 'FIND_IN_SET(?, pa.category_ids)')) . ")";
        $args = array_merge($args, $tags);
        if ($obsolete) {
            $sql .= ' AND NOT (' . implode(' OR ', array_fill(0, count($obsolete), 'FIND_IN_SET(?, pa.category_ids)')) . ')';
            $args = array_merge($args, $obsolete);
        }
        if ($proc !== '') { $sql .= " AND (pa.process_tag = ? OR pa.process_tag IS NULL OR pa.process_tag = '')"; $args[] = $proc; }
        $sql .= ' ORDER BY pa.issue_stamp_date DESC, pa.id DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return $out; }
    if (!$rows) return $out;

    $newIdx = 0;
    if ($triggerAttachId) {
        foreach ($rows as $i => $r) { if ((int)$r['id'] === $triggerAttachId) { $newIdx = $i; break; } }
    }
    $new = $rows[$newIdx];
    $out['new_date'] = (string)$new['issue_stamp_date'];
    $out['new_name'] = (string)($new['original_name'] ?? '');
    $out['new_id']   = (int)$new['id'];
    // 同一天＝同一版，往後找到第一個日期更舊的才是「變更前」
    for ($i = $newIdx + 1; $i < count($rows); $i++) {
        if ((string)$rows[$i]['issue_stamp_date'] !== $out['new_date']) {
            $out['old_date'] = (string)$rows[$i]['issue_stamp_date'];
            $out['old_name'] = (string)($rows[$i]['original_name'] ?? '');
            $out['old_id']   = (int)$rows[$i]['id'];
            break;
        }
    }
    return $out;
}

/**
 * 寫一筆料號版次異動紀錄（使用者要求：主檔「版次」旁邊要看得到誰改的、何時改的、對應哪一天的廠內版次）。
 * 只記錄「真的有變」的異動；客戶版次與廠內版次都沒動就不寫，免得清單被無意義的列灌滿。
 */
function dwg_log_revision(PDO $pdo, int $dId, ?string $oldRev, ?string $newRev,
                          ?string $intOld = null, ?string $intNew = null,
                          string $source = 'manual', ?int $changeId = null,
                          int $byId = 0, string $byName = '', string $note = ''): bool {
    dwg_ensure_schema($pdo);
    $revSame = trim((string)$oldRev) === trim((string)$newRev);
    $intSame = trim((string)$intOld) === trim((string)$intNew);
    if ($revSame && $intSame) return false;
    try {
        $pdo->prepare("INSERT INTO d_setting_revision_log
              (d_id, old_revision, new_revision, int_old_revision, int_new_revision, change_id, source, changed_by, changed_by_name, changed_at, note)
              VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)")
            ->execute([$dId, $oldRev, $newRev, $intOld, $intNew, $changeId, $source, ($byId ?: null),
                       mb_substr($byName, 0, 50), mb_substr($note, 0, 255)]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** 某料號的版次異動紀錄（含同料號的其他 d_setting——圖是同一張，版次歷程要看整組） */
function dwg_revision_log_list(PDO $pdo, int $dId): array {
    dwg_ensure_schema($pdo);
    try {
        $dIds = dwg_sibling_d_ids($pdo, $dId);
        $st = $pdo->prepare("SELECT l.*, c.change_no, s.D_Setting_Id AS part_no
                               FROM d_setting_revision_log l
                               LEFT JOIN qc_drawing_change c ON c.id = l.change_id
                               LEFT JOIN d_setting s ON s.d_id = l.d_id
                              WHERE l.d_id IN (" . implode(',', array_fill(0, count($dIds), '?')) . ")
                              ORDER BY l.changed_at DESC, l.id DESC LIMIT 200");
        $st->execute($dIds);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * 簽收對象挑選器（resource/js/eg_ack_picker.js）用的人員與部門清單。
 *
 * 兩個入口共用（圖面變更紀錄頁的 lookups、料號附件的 dwg_lookups），
 * 免得一邊改了另一邊沒改。人員一律走 people_lib（人員列表鐵則：不列離職與特殊帳號、
 * 標記長期請假、依部門/職稱 sort_order 排序，欄位順序部門／職稱／姓名）。
 *
 * 部門成員必須用 user_department_position_map 的**全部**對應，不能只用 people_lib
 * 挑出來的主要職務部門——一人可掛多個部門，而 dwg_expand_ack_targets() 走
 * eg_people_list(['dept_ids'=>…]) 是「任一對應命中就算」，只看主要部門會少算，
 * 造成畫面顯示的人數比實際通知人數少，也會讓某些部門整個不出現在清單裡。
 *
 * @return array{people:array, departments:array}
 */
function dwg_ack_lookup_data(PDO $pdo): array {
    $people = []; $depts = [];
    try {
        require_once __DIR__ . '/people_lib.php';
        require_once __DIR__ . '/org_role_lib.php';
        $rows = eg_people_list($pdo);
        foreach ($rows as $r) {
            $people[] = [
                'id'         => $r['id'],
                'name'       => $r['user_cname'],
                'dept_id'    => $r['dept_id'],
                'dept_name'  => $r['dept_name'],
                'position'   => $r['position_name'],
                'leave_note' => $r['leave_note'] ?? '',
            ];
        }
        $byDept  = [];
        $liveIds = array_map(function ($r) { return (int)$r['id']; }, $rows);
        if ($liveIds) {
            $ph = implode(',', array_fill(0, count($liveIds), '?'));
            $mp = $pdo->prepare("SELECT DISTINCT user_id, department_id FROM user_department_position_map WHERE user_id IN ($ph)");
            $mp->execute($liveIds);
            foreach ($mp->fetchAll(PDO::FETCH_ASSOC) as $m) {
                if ($m['department_id']) $byDept[(int)$m['department_id']][] = (int)$m['user_id'];
            }
        }
        $dRows = $pdo->query("SELECT id, name, parent_id, COALESCE(sort_order,999) AS sort_order FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $nameById = []; $parentById = [];
        foreach ($dRows as $d) { $nameById[(int)$d['id']] = $d['name']; $parentById[(int)$d['id']] = (int)($d['parent_id'] ?? 0); }
        foreach ($dRows as $d) {
            $members = [];
            foreach (eg_dept_subtree_ids($pdo, (int)$d['id']) as $sub) {
                foreach (($byDept[$sub] ?? []) as $u) $members[$u] = true;
            }
            if (!$members) continue;                       // 沒有在職成員的部門不列，避免選了卻沒人收到
            $path = $d['name']; $p = $parentById[(int)$d['id']]; $guard = 0;
            while ($p && isset($nameById[$p]) && $guard++ < 10) { $path = $nameById[$p] . ' / ' . $path; $p = $parentById[$p] ?? 0; }
            $depts[] = ['id' => (int)$d['id'], 'name' => $d['name'], 'path' => $path,
                        'user_ids' => array_keys($members), 'count' => count($members)];
        }
    } catch (Throwable $e) {}
    return ['people' => $people, 'departments' => $depts];
}

/**
 * 把該料號目前生效的檢驗標準整組複製成新版次，舊版停用但保留（舊檢驗紀錄仍追溯得到當時標準）。
 *
 * 抽出來給「建立正式變更單」與「草稿送出」兩條路徑共用：草稿階段刻意**不**複製版次
 * （半成品不該先把 QC 的檢驗標準換掉），等使用者補完內容按送出才做這一步。
 * 呼叫端要自己包 transaction。
 *
 * @return array{old:?int,new:?int}
 */
function dwg_clone_inspection_version(PDO $pdo, int $dId, string $newRevLabel): array {
    $oldVerId = $pdo->query("SELECT version_id FROM qc_inspection_version WHERE d_id=" . $dId . " AND is_active=1 ORDER BY version_id DESC LIMIT 1")->fetchColumn();
    $oldVerId = $oldVerId ? (int)$oldVerId : null;
    $newVerId = null;
    if ($oldVerId) {
        $label = $newRevLabel !== '' ? mb_substr($newRevLabel, 0, 30) : ('變更 ' . date('Y-m-d'));
        $pdo->prepare("INSERT INTO qc_inspection_version (d_id, version_label, source_type, is_active) VALUES (?,?, 'REVISION', 1)")
            ->execute([$dId, $label]);
        $newVerId = (int)$pdo->lastInsertId();

        $src = $pdo->prepare("SELECT * FROM qc_inspection_item WHERE version_id=?");
        $src->execute([$oldVerId]);
        $insI = $pdo->prepare("INSERT INTO qc_inspection_item
            (version_id, form_type_id, process_name, item_code, item_name, standard_text,
             min_value, max_value, plus_tolerance, minus_tolerance, result_type, sort_order, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insT = $pdo->prepare("INSERT INTO qc_inspection_item_tool_type (item_id, QC_Tool_List_id, is_primary) VALUES (?,?,?)");
        $getT = $pdo->prepare("SELECT QC_Tool_List_id, is_primary FROM qc_inspection_item_tool_type WHERE item_id=?");
        foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $insI->execute([$newVerId, $it['form_type_id'], $it['process_name'], $it['item_code'], $it['item_name'],
                            $it['standard_text'], $it['min_value'], $it['max_value'], $it['plus_tolerance'],
                            $it['minus_tolerance'], $it['result_type'], $it['sort_order'], $it['is_active']]);
            $nid = (int)$pdo->lastInsertId();
            $getT->execute([$it['item_id']]);
            foreach ($getT->fetchAll(PDO::FETCH_ASSOC) as $t) { try { $insT->execute([$nid, $t['QC_Tool_List_id'], $t['is_primary']]); } catch (Exception $e) {} }
        }
        $pdo->prepare("UPDATE qc_inspection_version SET is_active=0 WHERE d_id=? AND version_id<>?")->execute([$dId, $newVerId]);
    }
    return ['old' => $oldVerId, 'new' => $newVerId];
}

/** 變更單的通知內文（建立與草稿送出共用，兩邊格式才不會走鐘） */
function dwg_notify_body(array $c): string {
    $cust = trim((string)($c['old_revision'] ?? '')) . ' → ' . trim((string)($c['new_revision'] ?? ''));
    if (trim($cust, ' →') === '') $cust = '（未填）';
    $intOld = (string)($c['int_old_revision'] ?? '');
    $intNew = (string)($c['int_new_revision'] ?? '');
    $int = ($intOld !== '' ? eg_fmt_date($intOld) : '（首次）') . ' → ' . ($intNew !== '' ? eg_fmt_date($intNew) : '（未填）');
    return '圖面變更單 ' . $c['change_no'] . '（AS 2-PD-01-07）' . "\n"
         . '變更範圍：' . (($c['rev_scope'] ?? 'customer') === 'internal' ? '僅廠內版次' : '客戶版次（客戶＋廠內都換版）') . "\n"
         . '客戶版次：' . $cust . "\n"
         . '廠內版次（發行日）：' . $int . "\n"
         . '摘要：' . $c['summary'] . "\n" . '請點入確認並簽收。';
}

/**
 * 把「變更後的客戶版次」回寫料號主檔並留下版次異動紀錄（使用者拍板：要回寫，且要有紀錄可查）。
 * 只有 rev_scope=customer（客戶改圖）才動主檔版次；廠內自行改圖不碰客戶版次。
 */
function dwg_apply_revision_to_part(PDO $pdo, array $c, int $byId, string $byName): void {
    $dId    = (int)$c['d_id'];
    $newRev = trim((string)($c['new_revision'] ?? ''));
    $oldRev = trim((string)($c['old_revision'] ?? ''));
    $isCust = (($c['rev_scope'] ?? 'customer') !== 'internal');
    if ($isCust && $newRev !== '') {
        try {
            $cur = $pdo->prepare("SELECT Revision FROM d_setting WHERE d_id=?");
            $cur->execute([$dId]);
            $curRev = (string)$cur->fetchColumn();
            if ($oldRev === '') $oldRev = $curRev;
            if ($curRev !== $newRev) {
                $pdo->prepare("UPDATE d_setting SET Revision=?, Modified_By=?, Modified_At=NOW() WHERE d_id=?")
                    ->execute([$newRev, mb_substr($byName, 0, 50), $dId]);
            }
        } catch (Throwable $e) {}
    }
    dwg_log_revision($pdo, $dId, ($isCust ? $oldRev : null), ($isCust ? $newRev : null),
                     (string)($c['int_old_revision'] ?? ''), (string)($c['int_new_revision'] ?? ''),
                     'dwg_change', (int)$c['id'], $byId, $byName,
                     '圖面變更單 ' . $c['change_no'] . ($isCust ? '' : '（僅廠內版次）'));
}

/**
 * 建立一筆圖面變更紀錄，並做三件事（與 views/QC/drawing_change_log.php 手動登錄同一套）：
 *   ① 把該料號目前生效的檢驗標準整組複製成新版次，舊版停用但保留
 *   ② 寫入簽收名單（一律併入全站預設簽收對象，開單者移不掉）
 *   ③ 通知尚未簽收的人（行動型，沒簽會一直留在置頂未讀）
 *
 * status='DRAFT' 時（自動偵測換圖產生的草稿）**①③都不做**，等使用者補完內容按送出
 * （dwg_submit_change）才正式成立——半成品不該先把 QC 的檢驗標準換掉、也不該先驚動全公司。
 *
 * 呼叫端負責權限檢查與 CSRF；本函式自行開 transaction（呼叫端不要先開）。
 *
 * @param array $p d_id 必填；status=DRAFT 時 summary 可留空，其餘必填。
 *                 old_revision/new_revision（客戶版次）、int_old_revision/int_new_revision（廠內版次＝發行日）、
 *                 rev_scope(customer|internal)、change_date、source、customer_doc_no、from_process_no、detail、
 *                 ack_users[]、ack_depts[]、created_by、trigger_attachment_id、create_source 選填
 * @return array{id:int, change_no:string, new_version_id:?int, old_version_id:?int, status:string}
 */
function dwg_create_change(PDO $pdo, array $p): array {
    dwg_ensure_schema($pdo);
    $dId = (int)($p['d_id'] ?? 0);
    $summary = trim((string)($p['summary'] ?? ''));
    $status  = (($p['status'] ?? '') === 'DRAFT') ? 'DRAFT' : 'OPEN';
    if ($dId <= 0)      throw new Exception('請選擇料號');
    if ($status !== 'DRAFT' && $summary === '') throw new Exception('請填寫變更摘要');
    $oldRev = trim((string)($p['old_revision'] ?? ''));
    $newRev = trim((string)($p['new_revision'] ?? ''));
    $intOld = trim((string)($p['int_old_revision'] ?? ''));
    $intNew = trim((string)($p['int_new_revision'] ?? ''));
    $scope  = (($p['rev_scope'] ?? '') === 'internal') ? 'internal' : 'customer';
    $csrc   = in_array(($p['create_source'] ?? ''), ['attach', 'imgedit'], true) ? $p['create_source'] : 'manual';
    $fromP  = (($p['from_process_no'] ?? '') === '' || $p['from_process_no'] === null) ? null : (int)$p['from_process_no'];
    $uid    = (int)($p['created_by'] ?? 0);
    // 簽收對象可以混合指定部門與個人；部門在這裡展開成人員（含子部門、只列在職）。
    // 全站預設簽收對象一律併進來——前端把 chip 的 × 拿掉只是體驗，真正的守門在這一行。
    $def    = dwg_default_ack_get($pdo);
    $ackIds = dwg_expand_ack_targets($pdo,
                array_merge((array)($p['ack_users'] ?? []), $def['users']),
                array_merge((array)($p['ack_depts'] ?? []), $def['depts']));
    $trigId = ($p['trigger_attachment_id'] ?? null) ? (int)$p['trigger_attachment_id'] : null;

    $pdo->beginTransaction();
    try {
        // 變更單號 DWG-YYYYMM-nnn（同月流水）
        $ym = date('Ym');
        $n  = (int)$pdo->query("SELECT COUNT(*) FROM qc_drawing_change WHERE change_no LIKE 'DWG-$ym-%'")->fetchColumn() + 1;
        $changeNo = sprintf('DWG-%s-%03d', $ym, $n);

        // 草稿不換檢驗標準版次（見上方說明）
        $ver = ($status === 'DRAFT') ? ['old' => null, 'new' => null]
                                     : dwg_clone_inspection_version($pdo, $dId, ($newRev !== '' ? $newRev : $intNew));

        $pdo->prepare("INSERT INTO qc_drawing_change
            (change_no, as_doc_no, d_id, old_revision, new_revision, int_old_revision, int_new_revision, rev_scope,
             change_date, source, customer_doc_no, from_process_no, summary, detail,
             old_version_id, new_version_id, trigger_attachment_id, create_source, status, submitted_at, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$changeNo, '2-PD-01-07', $dId, $oldRev, $newRev, ($intOld ?: null), ($intNew ?: null), $scope,
                       (($p['change_date'] ?? '') ?: null), trim((string)($p['source'] ?? '')),
                       trim((string)($p['customer_doc_no'] ?? '')), $fromP,
                       $summary, trim((string)($p['detail'] ?? '')), $ver['old'], $ver['new'], $trigId, $csrc,
                       $status, ($status === 'DRAFT' ? null : date('Y-m-d H:i:s')), $uid]);
        $id = (int)$pdo->lastInsertId();

        $insA = $pdo->prepare("INSERT INTO qc_drawing_change_ack (change_id, user_id, acked_at) VALUES (?,?,NULL)");
        foreach ($ackIds as $u) { $insA->execute([$id, $u]); }

        // 正式成立才回寫料號主檔版次並留紀錄（草稿階段主檔不該被動到）
        if ($status !== 'DRAFT') {
            dwg_apply_revision_to_part($pdo, [
                'id' => $id, 'd_id' => $dId, 'change_no' => $changeNo, 'rev_scope' => $scope,
                'old_revision' => $oldRev, 'new_revision' => $newRev,
                'int_old_revision' => $intOld, 'int_new_revision' => $intNew,
            ], $uid, dwg_user_name($pdo, $uid));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // 通知（失敗不影響已寫入的變更紀錄）。草稿不通知。
    if ($status !== 'DRAFT' && $ackIds) {
        $partNo = dwg_part_info($pdo, $dId)['part_no'];
        dwg_notify($pdo, $id, '【圖面變更】料號 ' . $partNo . '　請簽收確認',
            dwg_notify_body(['change_no' => $changeNo, 'rev_scope' => $scope, 'old_revision' => $oldRev,
                             'new_revision' => $newRev, 'int_old_revision' => $intOld,
                             'int_new_revision' => $intNew, 'summary' => $summary]),
            $ackIds, $uid, 'reply');
    }
    return ['id' => $id, 'change_no' => $changeNo, 'new_version_id' => $ver['new'],
            'old_version_id' => $ver['old'], 'status' => $status];
}

/** 使用者中文姓名（找不到就回工號字串，不讓紀錄留空） */
function dwg_user_name(PDO $pdo, int $uid): string {
    if ($uid <= 0) return '';
    try {
        $st = $pdo->prepare("SELECT user_cname FROM user WHERE id=?");
        $st->execute([$uid]);
        $n = (string)$st->fetchColumn();
        return $n !== '' ? $n : (string)$uid;
    } catch (Throwable $e) { return (string)$uid; }
}

/**
 * 草稿送出＝正式成立：這時候才複製檢驗標準版次、回寫主檔版次、發簽收通知。
 * 摘要是必填（草稿可以先空著，但送出前一定要寫——只有填表的人知道這次改了什麼）。
 */
function dwg_submit_change(PDO $pdo, int $id, int $uid): array {
    dwg_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM qc_drawing_change WHERE id=?");
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception('查無此變更紀錄');
    if ($c['status'] !== 'DRAFT') throw new Exception('此變更單已經送出過了，不需要再送一次');
    if (trim((string)$c['summary']) === '') throw new Exception('請先填寫變更摘要再送出');

    $pdo->beginTransaction();
    try {
        $ver = dwg_clone_inspection_version($pdo, (int)$c['d_id'],
                 (trim((string)$c['new_revision']) !== '' ? (string)$c['new_revision'] : (string)$c['int_new_revision']));
        $pdo->prepare("UPDATE qc_drawing_change SET status='OPEN', submitted_at=NOW(),
                       old_version_id=?, new_version_id=?, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$ver['old'], $ver['new'], $uid, $id]);
        dwg_apply_revision_to_part($pdo, $c, $uid, dwg_user_name($pdo, $uid));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $ackIds = [];
    try {
        $a = $pdo->prepare("SELECT user_id FROM qc_drawing_change_ack WHERE change_id=? AND acked_at IS NULL");
        $a->execute([$id]);
        // 送出者自己不會收到通知（dwg_notify 會把 actor 過濾掉），回報人數也要跟著扣掉，
        // 否則畫面會說「已通知 1 位」但其實一封都沒發出去
        $ackIds = array_values(array_filter(array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN)),
                    function ($u) use ($uid) { return $u > 0 && $u !== $uid; }));
    } catch (Throwable $e) {}
    if ($ackIds) {
        $partNo = dwg_part_info($pdo, (int)$c['d_id'])['part_no'];
        dwg_notify($pdo, $id, '【圖面變更】料號 ' . $partNo . '　請簽收確認', dwg_notify_body($c), $ackIds, $uid, 'reply');
    }
    return ['id' => $id, 'change_no' => (string)$c['change_no'], 'new_version_id' => $ver['new'], 'notified' => count($ackIds)];
}

/**
 * 誰可以修改這一筆變更紀錄（使用者拍板）：
 *   ① 自己填的（created_by＝本人）→ 直接可改
 *   ② 別人填的 → 只有管理員可以改，而且要輸入**操作確認密碼**（走全站共用的 confirm_password_lib）
 * 回傳 need_password=true 代表「可以改，但要先驗密碼」。
 *
 * @return array{can:bool, need_password:bool, reason:string}
 */
function dwg_edit_permission(array $change, int $uid, bool $isAdmin): array {
    if ($uid > 0 && (int)$change['created_by'] === $uid) {
        return ['can' => true, 'need_password' => false, 'reason' => ''];
    }
    if ($isAdmin) {
        return ['can' => true, 'need_password' => true,
                'reason' => '這筆不是你填寫的，管理員修改他人的變更紀錄需要輸入操作確認密碼'];
    }
    return ['can' => false, 'need_password' => false,
            'reason' => '只能修改自己填寫的變更紀錄（這筆是由他人建立的，請洽該同仁或管理員）'];
}


}   // function_exists
