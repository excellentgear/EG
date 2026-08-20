<?php
/**
 * 產品開發評估表(2-TD-02-01) — 建議建立料號清單 共用庫（2026-08-12 使用者明確要求）。
 * 管理員複選客戶名單（存 system_parameters('TD_DEV_EVAL_SUGGEST','customers')，JSON客戶id陣列），
 * 在此名單內、指定區間曾有訂單/報工/BOM/出貨任一記錄的料號都列為候選（已存在td_dev_eval紀錄
 * 或已被忽略者排除）。訂單日期可解析者，資料建立之日期=訂單日期；無訂單日期者另外列出，
 * 提供BOM編號/BOM建立日期/最早報工日期(皆不受區間限制,越早越有參考價值)供快速套用或手動填寫。
 * 客戶／料號在多數來源表只有文字欄位無FK（尤其bom），一律用「文字比對已設定的客戶名單」解析，
 * 解析不到客戶者不列入（無法判斷屬於哪個客戶就無法套用客戶篩選）。
 */

function td_dev_eval_suggest_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS td_dev_eval_suggest_ignore (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_key VARCHAR(60) NOT NULL COMMENT '客戶id或客戶名稱文字',
        part_key VARCHAR(70) NOT NULL COMMENT 'D+d_id 或 T+料號文字',
        customer_name VARCHAR(60) NULL,
        part_no_text VARCHAR(60) NULL,
        note VARCHAR(200) NULL,
        ignored_by INT NULL,
        ignored_by_name VARCHAR(50) NULL,
        ignored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_key (customer_key, part_key)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='建議建立料號清單-使用者手動忽略的候選項'");
}

/** 管理員設定的客戶名單（回傳 [customer_id=>customer名稱]） */
function td_dev_eval_suggest_get_customers(PDO $db): array {
    $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group='TD_DEV_EVAL_SUGGEST' AND param_key='customers' LIMIT 1");
    $st->execute();
    $ids = json_decode((string)$st->fetchColumn(), true) ?: [];
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT customer_id, customer FROM customer_list WHERE customer_id IN ($in) ORDER BY customer");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['customer_id']] = $r['customer'];
    return $out;
}

function td_dev_eval_suggest_save_customers(PDO $db, array $ids, int $uid): void {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    $json = json_encode($ids, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("SELECT id FROM system_parameters WHERE param_group='TD_DEV_EVAL_SUGGEST' AND param_key='customers' LIMIT 1");
    $st->execute();
    $id = $st->fetchColumn();
    if ($id) {
        $db->prepare("UPDATE system_parameters SET param_value=?, updated_by=? WHERE id=?")->execute([$json, $uid, $id]);
    } else {
        $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by)
                      VALUES ('TD_DEV_EVAL_SUGGEST','customers',?,'建議建立料號清單-套用的客戶名單(JSON客戶id陣列)',?)")->execute([$json, $uid]);
    }
}

/** 主查詢：回傳候選清單（已排除已存在td_dev_eval紀錄與已忽略項），依客戶/料號排序 */
function td_dev_eval_suggest_candidates(PDO $db, string $dateFrom, string $dateTo): array {
    $custMap = td_dev_eval_suggest_get_customers($db); // id=>name
    if (!$custMap) return [];
    $nameToId = array_flip($custMap);
    $idList = array_keys($custMap);
    $idPh = implode(',', array_fill(0, count($idList), '?'));
    $nameList = array_values($custMap);
    $namePh = implode(',', array_fill(0, count($nameList), '?'));

    $agg = []; // key = custId|partKey => data

    $touch = function(string $custId, string $custName, ?int $partPk, string $partText, string $src, ?string $date) use (&$agg) {
        $partKey = $partPk ? ('D'.$partPk) : ('T'.$partText);
        $key = $custId.'|'.$partKey;
        if (!isset($agg[$key])) {
            $agg[$key] = [
                'customer_id'=>$custId, 'customer_name'=>$custName,
                'part_d_id'=>$partPk, 'part_no_text'=>$partText, 'part_key'=>$partKey,
                'product_name'=>null, 'earliest_order_date'=>null,
                'has_order'=>false, 'has_report'=>false, 'has_bom'=>false, 'has_ship'=>false,
            ];
        }
        $agg[$key]['has_'.$src] = true;
        if ($src === 'order' && $date) {
            if (!$agg[$key]['earliest_order_date'] || $date < $agg[$key]['earliest_order_date']) $agg[$key]['earliest_order_date'] = $date;
        }
    };

    // 1) 訂單
    $st = $db->prepare("SELECT d_id, d_id_ID, Client_name, Client_name_ID, Order_date, Specification
                         FROM order_track
                         WHERE Order_date BETWEEN ? AND ?
                           AND ( Client_name_ID IN ($idPh) OR (Client_name_ID IS NULL AND Client_name IN ($namePh)) )");
    $st->execute(array_merge([$dateFrom, $dateTo], $idList, $nameList));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $custId = $r['Client_name_ID'] ?: ($nameToId[$r['Client_name']] ?? null);
        if (!$custId || !isset($custMap[$custId])) continue;
        $partPk = $r['d_id_ID'] ? (int)$r['d_id_ID'] : null;
        $touch($custId, $custMap[$custId], $partPk, (string)$r['d_id'], 'order', $r['Order_date']);
        $key = $custId.'|'.($partPk ? 'D'.$partPk : 'T'.$r['d_id']);
        if (isset($agg[$key]) && !$agg[$key]['product_name'] && $r['Specification']) $agg[$key]['product_name'] = $r['Specification'];
    }

    // 2) 出貨
    $st = $db->prepare("SELECT Product_id, d_setting_id, Client_id, Client_name, Order_date
                         FROM is_list
                         WHERE Order_date BETWEEN ? AND ?
                           AND ( Client_id IN ($idPh) OR (Client_id IS NULL AND Client_name IN ($namePh)) )");
    $st->execute(array_merge([$dateFrom, $dateTo], $idList, $nameList));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $custId = $r['Client_id'] ?: ($nameToId[$r['Client_name']] ?? null);
        if (!$custId || !isset($custMap[$custId])) continue;
        $partPk = $r['d_setting_id'] ? (int)$r['d_setting_id'] : null;
        $touch($custId, $custMap[$custId], $partPk, (string)$r['Product_id'], 'ship', null);
    }

    // 3) BOM（無客戶FK，只能文字比對）
    $st = $db->prepare("SELECT bom, d_id, d_setting_id, Client_Name
                         FROM bom
                         WHERE Created_At BETWEEN ? AND ? AND Client_Name IN ($namePh)");
    $st->execute(array_merge([$dateFrom.' 00:00:00', $dateTo.' 23:59:59'], $nameList));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $custId = $nameToId[$r['Client_Name']] ?? null;
        if (!$custId) continue;
        $partPk = $r['d_setting_id'] ? (int)$r['d_setting_id'] : null;
        $touch($custId, $custMap[$custId], $partPk, (string)$r['d_id'], 'bom', null);
    }

    // 4) 報工（透過 bom_ing→bom 取得料號與客戶，無客戶FK只能文字比對）
    $st = $db->prepare("SELECT b.d_id, b.d_setting_id, b.Client_Name
                         FROM pm_process_daily_report r
                         JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
                         JOIN bom b ON b.bom = bi.bom
                         WHERE r.report_date BETWEEN ? AND ? AND b.Client_Name IN ($namePh)
                         GROUP BY b.d_id, b.d_setting_id, b.Client_Name");
    $st->execute(array_merge([$dateFrom, $dateTo], $nameList));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $custId = $nameToId[$r['Client_Name']] ?? null;
        if (!$custId) continue;
        $partPk = $r['d_setting_id'] ? (int)$r['d_setting_id'] : null;
        $touch($custId, $custMap[$custId], $partPk, (string)$r['d_id'], 'report', null);
    }

    if (!$agg) return [];

    // 補齊未解析 part_d_id 的料號（用文字對 d_setting.D_Setting_Id）
    $needText = [];
    foreach ($agg as $row) if (!$row['part_d_id']) $needText[$row['part_no_text']] = true;
    if ($needText) {
        $texts = array_keys($needText);
        $ph = implode(',', array_fill(0, count($texts), '?'));
        $st = $db->prepare("SELECT d_id, D_Setting_Id FROM d_setting WHERE D_Setting_Id IN ($ph)");
        $st->execute($texts);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[$r['D_Setting_Id']] = (int)$r['d_id'];
        foreach ($agg as $k => $row) {
            if (!$row['part_d_id'] && isset($map[$row['part_no_text']])) {
                $agg[$k]['part_d_id'] = $map[$row['part_no_text']];
                $agg[$k]['part_key'] = 'D'.$map[$row['part_no_text']];
            }
        }
    }

    // 合併同一客戶+料號的重複項：同一實體料號可能因為「有的來源記錄有帶 part_d_id(D key)、有的沒帶只能靠
    // 文字比對(T key)」在上面的迴圈裡被當成兩筆分開累計，剛才才把 T key 那筆的 part_d_id 補齊，
    // 這裡要把兩筆合併回同一筆，否則清單上同一料號會重複出現兩行（使用者實測抓到）
    $merged = [];
    foreach ($agg as $row) {
        $mergeKey = $row['customer_id'].'|'.($row['part_d_id'] ? 'D'.$row['part_d_id'] : $row['part_key']);
        if (!isset($merged[$mergeKey])) { $merged[$mergeKey] = $row; continue; }
        foreach (['has_order','has_report','has_bom','has_ship'] as $flag) {
            if ($row[$flag]) $merged[$mergeKey][$flag] = true;
        }
        if (!$merged[$mergeKey]['product_name'] && $row['product_name']) $merged[$mergeKey]['product_name'] = $row['product_name'];
        if ($row['earliest_order_date'] && (!$merged[$mergeKey]['earliest_order_date'] || $row['earliest_order_date'] < $merged[$mergeKey]['earliest_order_date'])) {
            $merged[$mergeKey]['earliest_order_date'] = $row['earliest_order_date'];
        }
        if ($row['part_d_id'] && !$merged[$mergeKey]['part_d_id']) {
            $merged[$mergeKey]['part_d_id'] = $row['part_d_id'];
            $merged[$mergeKey]['part_key'] = $row['part_key'];
        }
    }
    $agg = $merged;

    // 排除已存在 td_dev_eval 紀錄的客戶+料號組合
    $exist = $db->query("SELECT DISTINCT customer_name, part_d_id, part_no_text FROM td_dev_eval WHERE is_deleted=0")->fetchAll(PDO::FETCH_ASSOC);
    $existSet = [];
    foreach ($exist as $e) {
        $existSet[$e['customer_name'].'|D'.$e['part_d_id']] = true;
        if ($e['part_no_text']) $existSet[$e['customer_name'].'|T'.$e['part_no_text']] = true;
    }

    // 排除已忽略項
    $ign = $db->query("SELECT customer_key, part_key FROM td_dev_eval_suggest_ignore")->fetchAll(PDO::FETCH_ASSOC);
    $ignSet = [];
    foreach ($ign as $i) $ignSet[$i['customer_key'].'|'.$i['part_key']] = true;

    $out = [];
    foreach ($agg as $row) {
        $custId = $row['customer_id'];
        $custName = $row['customer_name'];
        $partKey = $row['part_key'];
        if (isset($existSet[$custName.'|'.$partKey])) continue;
        if (isset($ignSet[$custId.'|'.$partKey])) continue;
        $row['part_display'] = $row['part_no_text'];
        $out[] = $row;
    }

    // 補參考資訊（BOM編號/BOM建立日期/最早報工日期/最早訂單日期，不受區間限制）
    require_once __DIR__ . '/td_dev_eval_lib.php';
    $defaultProductName = td_dev_eval_default_product_name_get($db);
    foreach ($out as &$row) {
        // 訂單規格文字優先；查無規格文字時才用「產品名稱」全域預設值墊底（2026-08-13使用者更正：
        // 預設值是全部產品通用的單一值，不是特定料號，不應該覆蓋掉實際訂單上的規格文字）
        if (!$row['product_name'] && $defaultProductName) $row['product_name'] = $defaultProductName;
        $ref = td_dev_eval_suggest_part_reference($db, $row['part_d_id'], $row['part_no_text'], $row['customer_name']);
        $row['bom_no'] = $ref['bom_no'];
        $row['bom_created_at'] = $ref['bom_created_at'];
        $row['earliest_report_date'] = $ref['earliest_report_date'];
        // 若全時間範圍最早訂單比目前區間內找到的最早訂單還早（或區間內根本沒有訂單），標記出來提醒使用者，
        // 預設仍帶入區間內最早一筆訂單日期（沒有的話留給使用者決定），使用者可自行改套用更早的日期。
        $allTime = $ref['earliest_order_date_all_time'];
        $row['earliest_order_date_all_time'] = ($allTime && (!$row['earliest_order_date'] || $allTime < $row['earliest_order_date']))
            ? $allTime : null;
    }
    unset($row);

    usort($out, function($a, $b) {
        $c = strcmp($a['customer_name'], $b['customer_name']);
        return $c !== 0 ? $c : strcmp($a['part_no_text'], $b['part_no_text']);
    });
    return $out;
}

/**
 * 「有專案(2-GM-02)但還沒建產品開發評估表」的候選料號（2026-08-20 新增來源，
 * 使用者要求與既有偵測鈕合併、偵測後可選來源）。
 * 這個來源**不受客戶名單設定與查詢區間限制**——會被立成專案就代表要管，不必再靠掃訂單去猜。
 * 回傳欄位刻意對齊 td_dev_eval_suggest_candidates()，前端與批次建立可以共用同一套處理。
 */
function td_dev_eval_suggest_project_candidates(PDO $db): array {
    try {
        require_once __DIR__ . '/project_lib.php';
        prj_ensure_schema($db);
        $rows = prj_missing_for($db, 'dev_eval');
    } catch (Throwable $e) {
        return [];   // 專案模組不可用時只是少一個來源，不能讓整份建議清單掛掉
    }
    if (!$rows) return [];

    // 已忽略的照樣要排除（跟原本來源同一套忽略清單）
    $ignSet = [];
    foreach (td_dev_eval_suggest_ignore_list($db) as $g) $ignSet[$g['customer_id'] . '|' . $g['part_key']] = true;

    require_once __DIR__ . '/td_dev_eval_lib.php';
    $defaultProductName = td_dev_eval_default_product_name_get($db);

    $out = [];
    foreach ($rows as $r) {
        $custId  = (string)($r['Customer_Id'] ?? '');
        $partKey = 'D' . (int)$r['ds_pk'];
        if (isset($ignSet[$custId . '|' . $partKey])) continue;
        $out[] = [
            'customer_id'   => $custId,
            'customer_name' => (string)($r['customer_name'] ?: $custId),
            'part_d_id'     => (int)$r['ds_pk'],
            'part_no_text'  => (string)$r['part_no'],
            'part_display'  => (string)$r['part_no'],
            'part_key'      => $partKey,
            'product_name'  => $defaultProductName ?: null,
            'earliest_order_date' => null,
            'earliest_order_date_all_time' => null,
            'has_order' => false, 'has_report' => false, 'has_bom' => false, 'has_ship' => false,
            'bom_no' => null, 'bom_created_at' => null, 'earliest_report_date' => null,
            'src_label'    => '專案',
            'project_no'   => (string)$r['project_no'],
            'project_name' => (string)$r['project_name'],
        ];
    }
    return $out;
}

/** 單一料號的參考資訊：最新BOM編號/建立日期、最早報工日期、最早訂單日期（皆不受篩選區間限制，越早越有參考價值） */
function td_dev_eval_suggest_part_reference(PDO $db, ?int $partDId, string $partText, string $custName = ''): array {
    $cond = $partDId ? "(b.d_setting_id = ? OR b.d_id = ?)" : "b.d_id = ?";
    $params = $partDId ? [$partDId, $partText] : [$partText];
    $st = $db->prepare("SELECT bom, Created_At FROM bom b WHERE $cond ORDER BY Created_At DESC LIMIT 1");
    $st->execute($params);
    $bomRow = $st->fetch(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT MIN(r.report_date) FROM pm_process_daily_report r
                         JOIN bom_ing bi ON bi.bom_ing_fid = r.bom_ing_fid
                         JOIN bom b ON b.bom = bi.bom
                         WHERE $cond");
    $st->execute($params);
    $earliestReport = $st->fetchColumn();

    // 全時間範圍(不受查詢區間限制)最早一筆訂單日期，用來提醒「其實有比目前區間更早的訂單」（2026-08-12 使用者明確要求）
    $earliestOrderAllTime = null;
    if ($custName !== '') {
        $ocond = $partDId ? "(d_id_ID = ? OR d_id = ?)" : "d_id = ?";
        $oparams = $partDId ? [$partDId, $partText] : [$partText];
        $st = $db->prepare("SELECT MIN(Order_date) FROM order_track WHERE Client_name=? AND ($ocond)");
        $st->execute(array_merge([$custName], $oparams));
        $earliestOrderAllTime = $st->fetchColumn() ?: null;
    }

    return [
        'bom_no' => $bomRow['bom'] ?? null,
        'bom_created_at' => $bomRow['Created_At'] ?? null,
        'earliest_report_date' => $earliestReport ?: null,
        'earliest_order_date_all_time' => $earliestOrderAllTime,
    ];
}

/** 單一料號在區間內的相關記錄明細（供跳窗查看，含報價記錄） */
function td_dev_eval_suggest_part_history(PDO $db, ?int $partDId, string $partText, string $custName, string $dateFrom, string $dateTo): array {
    $out = [];
    $condTxt = "d_id = ?"; $paramsTxt = [$partText];
    $condPk  = $partDId ? "d_id_ID = ?" : null;

    $st = $db->prepare("SELECT Order_oo, Order_date, Qty FROM order_track WHERE Client_name=? AND ($condTxt".($condPk?" OR $condPk":"").") AND Order_date BETWEEN ? AND ? ORDER BY Order_date DESC");
    $st->execute(array_merge([$custName], $paramsTxt, $partDId?[$partDId]:[], [$dateFrom, $dateTo]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type'=>'訂單', 'label'=>$r['Order_oo'], 'date'=>$r['Order_date'], 'note'=>'數量 '.$r['Qty']];

    $st = $db->prepare("SELECT IS_number, Order_date, Qty FROM is_list WHERE Client_name=? AND (Product_id=?".($partDId?" OR d_setting_id=?":"").") AND Order_date BETWEEN ? AND ? ORDER BY Order_date DESC");
    $st->execute(array_merge([$custName, $partText], $partDId?[$partDId]:[], [$dateFrom, $dateTo]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type'=>'出貨', 'label'=>$r['IS_number'], 'date'=>$r['Order_date'], 'note'=>'數量 '.$r['Qty']];

    $st = $db->prepare("SELECT bom, Created_At, sqty FROM bom WHERE Client_Name=? AND (d_id=?".($partDId?" OR d_setting_id=?":"").") AND Created_At BETWEEN ? AND ? ORDER BY Created_At DESC");
    $st->execute(array_merge([$custName, $partText], $partDId?[$partDId]:[], [$dateFrom.' 00:00:00', $dateTo.' 23:59:59']));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type'=>'BOM', 'label'=>$r['bom'], 'date'=>substr((string)$r['Created_At'],0,10), 'note'=>'數量 '.$r['sqty']];

    $st = $db->prepare("SELECT b.d_id, r.report_date, r.process_no FROM pm_process_daily_report r
                         JOIN bom_ing bi ON bi.bom_ing_fid=r.bom_ing_fid JOIN bom b ON b.bom=bi.bom
                         WHERE b.Client_Name=? AND (b.d_id=?".($partDId?" OR b.d_setting_id=?":"").") AND r.report_date BETWEEN ? AND ? ORDER BY r.report_date DESC");
    $st->execute(array_merge([$custName, $partText], $partDId?[$partDId]:[], [$dateFrom, $dateTo]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type'=>'報工', 'label'=>'製程'.$r['process_no'], 'date'=>$r['report_date'], 'note'=>''];

    $st = $db->prepare("SELECT ql.quote_no, ql.quote_date, qi.quantity FROM quotation_item qi
                         JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                         WHERE ql.client_name=? AND (qi.product_id=?".($partDId?" OR qi.d_setting_d_id=?":"").") AND ql.quote_date BETWEEN ? AND ? ORDER BY ql.quote_date DESC");
    $st->execute(array_merge([$custName, $partText], $partDId?[$partDId]:[], [$dateFrom, $dateTo]));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['type'=>'報價', 'label'=>$r['quote_no'], 'date'=>$r['quote_date'], 'note'=>'數量 '.$r['quantity']];

    usort($out, function($a,$b){ return strcmp($b['date'] ?? '', $a['date'] ?? ''); });
    return $out;
}

function td_dev_eval_suggest_ignore_add(PDO $db, string $custId, string $partKey, string $custName, ?string $partText, int $uid, string $uname, string $note = ''): void {
    $db->prepare("INSERT INTO td_dev_eval_suggest_ignore (customer_key, part_key, customer_name, part_no_text, note, ignored_by, ignored_by_name)
                  VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note), ignored_by=VALUES(ignored_by), ignored_by_name=VALUES(ignored_by_name), ignored_at=NOW()")
       ->execute([$custId, $partKey, $custName, $partText, $note, $uid, $uname]);
}

function td_dev_eval_suggest_ignore_list(PDO $db): array {
    return $db->query("SELECT * FROM td_dev_eval_suggest_ignore ORDER BY ignored_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

function td_dev_eval_suggest_ignore_remove(PDO $db, int $id): void {
    $db->prepare("DELETE FROM td_dev_eval_suggest_ignore WHERE id=?")->execute([$id]);
}

/**
 * 批次建立：$rows 每筆 [customer_name, part_d_id, part_no_text, product_name, fill_date]，
 * fill_date 必填（前端已依「訂單日期」或使用者手動指定/快速套用決定），建立為 draft 狀態表頭
 * （僅建立表頭殼，32項確認結果與簽核仍需照正常流程逐一進行，不代填）。
 */
function td_dev_eval_suggest_bulk_create(PDO $db, array $rows, int $uid, string $uname): array {
    require_once __DIR__ . '/td_dev_eval_lib.php';
    $created = 0; $errors = [];
    foreach ($rows as $row) {
        $custName = trim((string)($row['customer_name'] ?? ''));
        $partDId = !empty($row['part_d_id']) ? (int)$row['part_d_id'] : null;
        $partText = trim((string)($row['part_no_text'] ?? ''));
        $fillDate = trim((string)($row['fill_date'] ?? ''));
        if ($custName === '' || $fillDate === '') { $errors[] = ($partText ?: '(無料號)').'：缺客戶或填表日期'; continue; }
        try {
            $docNo = td_dev_eval_next_doc_no($db, $fillDate); // 編號依填表日期(2026-08-20)
            // 建立當下就試算預估需求量（使用者明確要求：批次建立這種「建立評估表」的路徑一樣要自動算好填入）
            $estQty = $partDId ? td_dev_eval_estimate_qty($db, $partDId, $fillDate) : null;
            $db->prepare("INSERT INTO td_dev_eval (doc_no, customer_name, part_d_id, part_no_text, product_name, est_qty, fill_date, status, created_by, created_by_name)
                          VALUES (?,?,?,?,?,?,?, 'draft', ?, ?)")
               ->execute([$docNo, $custName, $partDId, $partText ?: null, $row['product_name'] ?? null, $estQty, $fillDate, $uid, $uname]);
            $created++;
        } catch (Throwable $e) { $errors[] = ($partText ?: '(無料號)').'：'.$e->getMessage(); }
    }
    return ['created'=>$created, 'errors'=>$errors];
}

/** td_dev_eval.php 頁首提醒用：候選筆數（近一年、已設定的客戶名單） */
function td_dev_eval_suggest_pending_count(PDO $db): int {
    if (!td_dev_eval_suggest_get_customers($db)) return 0;
    try {
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-1 year'));
        return count(td_dev_eval_suggest_candidates($db, $from, $to));
    } catch (Throwable $e) { return 0; }
}
