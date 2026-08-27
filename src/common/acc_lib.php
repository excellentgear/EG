<?php
/**
 * src/common/acc_lib.php — 會計模組共用函式庫
 *
 * 第一階段：客戶發票資料維護（統編／發票全名／發票地址／發票 email）。
 * 背景：925 家有效客戶只有 15 家有統編、近一年有出貨的 175 家只有 5 家有，
 *       沒有買方統編就開不出電子發票，這是應收/發票模組的資料前置條件。
 *
 * 後續階段會在此檔擴充：帳款月份計算、應收對帳、發票主檔、收款沖帳、應付。
 * 權限 roles module='accounting'。
 */

if (!defined('ACC_MODULE')) define('ACC_MODULE', 'accounting');

/* ============================================================
 * 資料表建立（比照 vendor_audit_ensure_schema，頁面載入時確保存在）
 *
 * 設計原則：發票／折讓／收款是「會計層」，來源憑證（is_list 出貨、ir_track 退貨）
 * 一律只用 src_type+src_id 參照，不複製金額以外的資料，也不回寫來源表。
 * 客戶統編與抬頭在開立當下做快照，避免事後客戶改名導致舊發票內容變動。
 * ============================================================ */
function acc_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        // 發票主檔（含銷貨折讓證明單，用 doc_type 區分）
        $db->exec("CREATE TABLE IF NOT EXISTS acc_invoice (
            invoice_id     INT NOT NULL AUTO_INCREMENT,
            doc_type       VARCHAR(10)  NOT NULL DEFAULT 'INVOICE' COMMENT 'INVOICE=發票 ALLOWANCE=銷貨折讓證明單',
            invoice_no     VARCHAR(20)  DEFAULT NULL COMMENT '實際開立的發票號碼（外部平台開立後回填）',
            invoice_date   DATE         DEFAULT NULL COMMENT '實際開立日期（回填）',
            customer_id    VARCHAR(11)  DEFAULT NULL COMMENT '對應 customer_list.customer_id',
            customer_name  VARCHAR(60)  NOT NULL COMMENT '客戶簡稱（開立當下快照）',
            customer_full  VARCHAR(100) DEFAULT NULL COMMENT '發票抬頭（開立當下快照）',
            tax_id         VARCHAR(20)  DEFAULT NULL COMMENT '買方統一編號（開立當下快照）',
            billing_month  VARCHAR(7)   NOT NULL COMMENT '帳款月份 YYYY-MM',
            tax_type       TINYINT      NOT NULL DEFAULT 1 COMMENT '1=應稅 2=零稅率 3=免稅',
            tax_rate       DECIMAL(6,4) NOT NULL DEFAULT 0.0500,
            sales_amount   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '未稅金額',
            tax_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '含稅金額',
            status         VARCHAR(10)  NOT NULL DEFAULT 'draft' COMMENT 'draft/exported/issued/void',
            export_batch   VARCHAR(30)  DEFAULT NULL,
            exported_at    DATETIME     DEFAULT NULL,
            ref_invoice_id INT          DEFAULT NULL COMMENT '折讓單所對應的原發票 invoice_id',
            note           VARCHAR(200) DEFAULT NULL,
            void_reason    VARCHAR(200) DEFAULT NULL,
            Created_By     VARCHAR(11)  DEFAULT NULL,
            Created_At     DATETIME     DEFAULT CURRENT_TIMESTAMP,
            Modified_By    VARCHAR(11)  DEFAULT NULL,
            Modified_At    DATETIME     DEFAULT NULL,
            PRIMARY KEY (invoice_id),
            UNIQUE KEY uq_inv_no (invoice_no),
            KEY idx_inv_cust_bm (customer_id, billing_month),
            KEY idx_inv_status  (status),
            KEY idx_inv_bm      (billing_month),
            KEY idx_inv_ref     (ref_invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-發票/折讓單主檔'");

        // 發票明細：src_type+src_id 唯一，防同一張出貨被開兩次發票
        $db->exec("CREATE TABLE IF NOT EXISTS acc_invoice_item (
            item_id    INT NOT NULL AUTO_INCREMENT,
            invoice_id INT NOT NULL,
            src_type   VARCHAR(4)   NOT NULL COMMENT 'IS=出貨(is_list) IR=退貨(ir_track)',
            src_id     INT          NOT NULL COMMENT 'IS_id 或 IR_id',
            src_no     VARCHAR(20)  DEFAULT NULL COMMENT '來源單號（顯示用快照）',
            src_date   DATE         DEFAULT NULL,
            product_id VARCHAR(30)  DEFAULT NULL,
            spec       VARCHAR(120) DEFAULT NULL,
            qty        INT          NOT NULL DEFAULT 0,
            unit_price DECIMAL(12,4) NOT NULL DEFAULT 0,
            amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (item_id),
            UNIQUE KEY uq_item_src (src_type, src_id),
            KEY idx_item_inv (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-發票明細（參照出貨/退貨憑證）'");

        // 收款單
        $db->exec("CREATE TABLE IF NOT EXISTS acc_receipt (
            receipt_id    INT NOT NULL AUTO_INCREMENT,
            receipt_no    VARCHAR(20)  NOT NULL,
            customer_id   VARCHAR(11)  DEFAULT NULL,
            customer_name VARCHAR(60)  NOT NULL,
            receipt_date  DATE         NOT NULL COMMENT '入帳日',
            method        VARCHAR(20)  DEFAULT '匯款' COMMENT '匯款/支票/現金/其他',
            amount        DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '實收金額',
            fee           DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '匯費/手續費（由我方負擔的扣款）',
            bank          VARCHAR(50)  DEFAULT NULL,
            check_no      VARCHAR(30)  DEFAULT NULL COMMENT '支票號碼',
            check_due     DATE         DEFAULT NULL COMMENT '票期',
            note          VARCHAR(200) DEFAULT NULL,
            Created_By    VARCHAR(11)  DEFAULT NULL,
            Created_At    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            Modified_By   VARCHAR(11)  DEFAULT NULL,
            Modified_At   DATETIME     DEFAULT NULL,
            PRIMARY KEY (receipt_id),
            UNIQUE KEY uq_rcpt_no (receipt_no),
            KEY idx_rcpt_cust (customer_id),
            KEY idx_rcpt_date (receipt_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-收款單'");

        // 沖帳明細：多對多，一筆收款可沖多張發票、一張發票可被多筆收款分次沖
        $db->exec("CREATE TABLE IF NOT EXISTS acc_receipt_alloc (
            alloc_id   INT NOT NULL AUTO_INCREMENT,
            receipt_id INT NOT NULL,
            invoice_id INT NOT NULL,
            amount     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '本次沖銷金額',
            Created_By VARCHAR(11) DEFAULT NULL,
            Created_At DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (alloc_id),
            KEY idx_alloc_rcpt (receipt_id),
            KEY idx_alloc_inv  (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-收款沖帳明細（支援部分沖帳與一筆對多張）'");

        /* 付款單（應付側，與收款單對稱）。
           為什麼要有這一層：purchase_request.pay_status／pay_date 是單頭單一欄位，
           表達不了「月結廠商一次匯款付掉五張採購單」或「先付一半」。
           付款單＋沖帳明細才做得到一對多與部分付款。 */
        $db->exec("CREATE TABLE IF NOT EXISTS acc_payment (
            payment_id   INT NOT NULL AUTO_INCREMENT,
            payment_no   VARCHAR(20)  NOT NULL,
            vendor_id    VARCHAR(11)  DEFAULT NULL COMMENT 'maker_list.maker_id_no',
            vendor_name  VARCHAR(120) NOT NULL,
            pay_date     DATE         NOT NULL COMMENT '出帳日',
            method       VARCHAR(20)  DEFAULT '匯款' COMMENT '匯款/支票/現金/其他',
            amount       DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '實付金額',
            fee          DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '匯費/手續費（我方另付的）',
            bank         VARCHAR(50)  DEFAULT NULL,
            check_no     VARCHAR(30)  DEFAULT NULL COMMENT '支票號碼',
            check_due    DATE         DEFAULT NULL COMMENT '票期',
            note         VARCHAR(200) DEFAULT NULL,
            Created_By   VARCHAR(11)  DEFAULT NULL,
            Created_At   DATETIME     DEFAULT CURRENT_TIMESTAMP,
            Modified_By  VARCHAR(11)  DEFAULT NULL,
            Modified_At  DATETIME     DEFAULT NULL,
            PRIMARY KEY (payment_id),
            UNIQUE KEY uq_pay_no (payment_no),
            KEY idx_pay_vendor (vendor_id),
            KEY idx_pay_date (pay_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-付款單（應付）'");

        // 付款沖帳明細：一筆付款可沖多張採購單、一張採購單可被多次部分付款
        $db->exec("CREATE TABLE IF NOT EXISTS acc_payment_alloc (
            alloc_id   INT NOT NULL AUTO_INCREMENT,
            payment_id INT NOT NULL,
            src_type   VARCHAR(6) NOT NULL DEFAULT 'PURC' COMMENT 'PURC=採購單 purchase_request',
            src_id     INT NOT NULL COMMENT 'PURC 時為 purchase_request.req_id',
            amount     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '本次沖銷金額（含稅）',
            Created_By VARCHAR(11) DEFAULT NULL,
            Created_At DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (alloc_id),
            KEY idx_palloc_pay (payment_id),
            KEY idx_palloc_src (src_type, src_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-付款沖帳明細（支援部分付款與一筆對多張）'");

        /* 採購單的結帳方式（現金／月結）——會計端的判定與覆寫。
           現場採購分兩種：現金(零用金，採購自己簡單記帳，不經會計) 與 月結(要經會計)。
           purchase_request 目前沒有結帳方式欄位（pay_method 是自由文字），
           所以預設用規則判（見 acc_purc_settle_mode），判錯時會計可在應付頁逐單覆寫。
           覆寫存在會計自己的表，不動採購模組的資料表與檔案。 */
        $db->exec("CREATE TABLE IF NOT EXISTS acc_purc_flag (
            req_id      INT NOT NULL COMMENT 'purchase_request.req_id',
            settle_mode VARCHAR(10) NOT NULL COMMENT 'CREDIT=月結(進應付) CASH=現金/零用金(不進應付)',
            reason      VARCHAR(200) DEFAULT NULL,
            set_by      INT          DEFAULT NULL,
            set_by_name VARCHAR(50)  DEFAULT NULL,
            set_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (req_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-採購單結帳方式覆寫（現金不進應付）'");

        // 對帳狀態：業務(應收)／生管(應付)對完帳後標記，會計據此判斷可不可以開票／付款。
        // 刻意獨立成表而不加欄位到 is_list / ir_track / bom_ing_transfer_log——
        // 那三張是全系統重度使用的表，加欄位風險高；用 (src_type, src_id) 對應即可。
        $db->exec("CREATE TABLE IF NOT EXISTS acc_recon (
            recon_id     INT NOT NULL AUTO_INCREMENT,
            src_type     VARCHAR(6)   NOT NULL COMMENT 'IS=出貨 IR=退貨 TLOG=加工移轉單',
            src_id       INT          NOT NULL,
            side         VARCHAR(2)   NOT NULL DEFAULT 'ar' COMMENT 'ar=應收 ap=應付',
            status       VARCHAR(10)  NOT NULL DEFAULT 'pending' COMMENT 'pending=未對 ok=已對完 issue=有異常',
            note         VARCHAR(300) DEFAULT NULL COMMENT '對帳註記（如客戶說這筆下月才付）',
            recon_by     INT          DEFAULT NULL,
            recon_by_name VARCHAR(50) DEFAULT NULL,
            recon_at     DATETIME     DEFAULT NULL,
            Created_At   DATETIME     DEFAULT CURRENT_TIMESTAMP,
            Modified_At  DATETIME     DEFAULT NULL,
            PRIMARY KEY (recon_id),
            UNIQUE KEY uq_recon_src (src_type, src_id),
            KEY idx_recon_status (status),
            KEY idx_recon_side (side)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-對帳狀態與註記（不動來源表）'");

        /* 對帳工作底稿：一份底稿 = 客戶或廠商 × 帳款月份 × 側別。
           為什麼要有底稿而不是直接改原始憑證：
           實務上我方一筆可能對到廠商多筆（廠商拆批甚至拆月請款），
           我方多筆也可能對到廠商一筆（廠商把分批送的加工合併成一列）。
           這種「加總／拆分」只是對帳當下的算法，不該去動原始出貨/加工紀錄，
           但必須留下來讓會計與稽核看得懂當初是怎麼對的。
           每個 (側別, 對象, 帳款月份) 只能有一份底稿——落實「一個客戶/廠商×月份只能一筆暫存」。 */
        $db->exec("CREATE TABLE IF NOT EXISTS acc_recon_sheet (
            sheet_id       INT NOT NULL AUTO_INCREMENT,
            side           VARCHAR(2)   NOT NULL COMMENT 'ar=應收 ap=應付',
            party_id       VARCHAR(20)  NOT NULL COMMENT '客戶 customer_id 或廠商 maker_id_no',
            party_name     VARCHAR(60)  DEFAULT NULL,
            billing_month  VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
            status         VARCHAR(10)  NOT NULL DEFAULT 'draft' COMMENT 'draft=暫存 confirmed=已確認鎖帳 reopened=退回重對',
            our_total      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '我方合計(未稅,含加總拆分調整後)',
            their_total    DECIMAL(14,2) DEFAULT NULL COMMENT '對方紙本合計(人工輸入,用來核對)',
            tax_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '含稅',
            memo           VARCHAR(500) DEFAULT NULL,
            saved_by       INT          DEFAULT NULL,
            saved_by_name  VARCHAR(50)  DEFAULT NULL,
            saved_at       DATETIME     DEFAULT NULL,
            confirmed_by   INT          DEFAULT NULL,
            confirmed_by_name VARCHAR(50) DEFAULT NULL,
            confirmed_at   DATETIME     DEFAULT NULL,
            reopen_by      INT          DEFAULT NULL,
            reopen_by_name VARCHAR(50)  DEFAULT NULL,
            reopen_at      DATETIME     DEFAULT NULL,
            reopen_reason  VARCHAR(300) DEFAULT NULL,
            Created_At     DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (sheet_id),
            UNIQUE KEY uq_sheet (side, party_id, billing_month),
            KEY idx_sheet_status (status),
            KEY idx_sheet_month (billing_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-對帳工作底稿'");

        $db->exec("CREATE TABLE IF NOT EXISTS acc_recon_line (
            line_id      INT NOT NULL AUTO_INCREMENT,
            sheet_id     INT NOT NULL,
            sort_order   INT NOT NULL DEFAULT 0 COMMENT '拖移排序後的順序（對照紙本順序）',
            src_type     VARCHAR(6)   NOT NULL COMMENT 'IS/IR/TLOG=來源憑證 SPLIT=拆分出來的子列 MANUAL=手動加列',
            src_id       INT          NOT NULL DEFAULT 0,
            doc_no       VARCHAR(30)  DEFAULT NULL,
            doc_date     DATE         DEFAULT NULL,
            bom          VARCHAR(30)  DEFAULT NULL,
            product_id   VARCHAR(30)  DEFAULT NULL,
            spec         VARCHAR(120) DEFAULT NULL,
            orig_qty     INT          DEFAULT NULL COMMENT '原始數量（來源憑證的值，永久保留供比對）',
            orig_price   DECIMAL(12,4) DEFAULT NULL,
            orig_amount  DECIMAL(14,2) DEFAULT NULL,
            adj_qty      INT          DEFAULT NULL COMMENT '對帳調整值；NULL=沿用原始',
            adj_price    DECIMAL(12,4) DEFAULT NULL,
            adj_amount   DECIMAL(14,2) DEFAULT NULL,
            adj_month    VARCHAR(7)   DEFAULT NULL COMMENT '拆分可跨月：這一段算哪個月的帳',
            checked      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=已對到本筆（畫面轉暖淺綠）',
            group_no     INT          DEFAULT NULL COMMENT '同組＝多項加總（我方多筆對廠商一筆）',
            split_parent INT          DEFAULT NULL COMMENT '拆分來源 line_id（我方一筆對廠商多筆）',
            split_seq    INT          DEFAULT NULL,
            memo         VARCHAR(300) DEFAULT NULL,
            PRIMARY KEY (line_id),
            KEY idx_line_sheet (sheet_id, sort_order),
            KEY idx_line_src (src_type, src_id),
            KEY idx_line_group (sheet_id, group_no),
            KEY idx_line_split (split_parent)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='會計-對帳底稿明細（加總/拆分/排序/勾選都存這裡，不動來源憑證）'");

        $done = true;
    } catch (Throwable $e) {
        error_log('acc_ensure_schema 失敗: ' . $e->getMessage());
    }
}

/* ============================================================
 * 對帳工作底稿
 *
 * 設計要點（對應使用者實務）：
 *  - 加總／拆分只影響底稿，永不寫回 is_list / bom_ing_transfer_log。
 *    orig_* 永久保留原始值，adj_* 是對帳當下的算法，會計與稽核兩邊都看得到。
 *  - 拆分驗證：子列金額合計必須等於父列金額，避免對帳把錢對掉。
 *  - 一個 (側別, 對象, 帳款月份) 只有一份底稿；確認送出後暫存即成為已確認紀錄。
 *  - 確認鎖帳後，該底稿涵蓋的憑證不可再用 acc_edit_doc 修改，
 *    要改必須由會計管理員先退回（reopen），全程留稽核。
 * ============================================================ */

/** 取得底稿（含明細）；沒有暫存時回 null */
function acc_sheet_get(PDO $db, string $side, string $partyId, string $bm): ?array
{
    acc_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM acc_recon_sheet WHERE side=? AND party_id=? AND billing_month=? LIMIT 1");
    $st->execute([$side, $partyId, $bm]);
    $sheet = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sheet) return null;

    $sl = $db->prepare("SELECT * FROM acc_recon_line WHERE sheet_id=? ORDER BY sort_order, line_id");
    $sl->execute([(int)$sheet['sheet_id']]);
    $sheet['lines'] = $sl->fetchAll(PDO::FETCH_ASSOC);

    // 哪些列是拆分父列（有子列指向它）——父列不計入合計，只是容器
    $isParent = [];
    foreach ($sheet['lines'] as $l) {
        if ($l['split_parent'] !== null) $isParent[(int)$l['split_parent']] = true;
    }

    foreach ($sheet['lines'] as &$l) {
        $l['eff_qty']    = ($l['adj_qty']    !== null) ? (int)$l['adj_qty']      : (int)$l['orig_qty'];
        $l['eff_price']  = ($l['adj_price']  !== null) ? (float)$l['adj_price']  : (float)$l['orig_price'];
        $l['eff_amount'] = ($l['adj_amount'] !== null) ? (float)$l['adj_amount'] : (float)$l['orig_amount'];
        $l['adjusted']   = ($l['adj_qty'] !== null || $l['adj_price'] !== null
                            || $l['adj_amount'] !== null || $l['adj_month'] !== null);
        $l['is_split_parent'] = isset($isParent[(int)$l['line_id']]);
        $l['counts_in_total'] = !$l['is_split_parent'];
    }
    unset($l);
    return $sheet;
}

/** 底稿是否已鎖（confirmed）。給 acc_edit_doc 用來擋修改。 */
function acc_sheet_locked_src(PDO $db, string $srcType, int $srcId): ?array
{
    try {
        $st = $db->prepare("SELECT s.sheet_id, s.side, s.party_name, s.billing_month, s.status,
                                   s.confirmed_by_name, s.confirmed_at
                            FROM acc_recon_line l
                            JOIN acc_recon_sheet s ON s.sheet_id = l.sheet_id
                            WHERE l.src_type = ? AND l.src_id = ? AND s.status = 'confirmed'
                            LIMIT 1");
        $st->execute([strtoupper($srcType), $srcId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * 從來源憑證組出一份新底稿的明細（不寫入資料庫）。
 * 已有暫存時前端應改用 acc_sheet_get 的內容，避免蓋掉使用者對過的進度。
 */
function acc_sheet_build(PDO $db, string $side, string $partyId, string $bm): array
{
    $lines = [];
    $i = 0;
    if ($side === 'ap') {
        $d = acc_ap_detail($db, $partyId, $bm);
        foreach ($d['items'] as $it) {
            $lines[] = [
                'sort_order' => ++$i * 10,
                'src_type' => (string)($it['src'] ?? 'TLOG'),
                'src_id' => (int)($it['src_id'] ?? $it['transfer_id']),
                'doc_no' => $it['doc_no'] ?? $it['transfer_no'], 'doc_date' => $it['d'], 'bom' => $it['bom'],
                'product_id' => $it['product_id'], 'spec' => $it['process_name'],
                'orig_qty' => (int)$it['transfer_qty'], 'orig_price' => (float)$it['unit_price'],
                'orig_amount' => (float)$it['process_amount'],
                'adj_qty' => null, 'adj_price' => null, 'adj_amount' => null, 'adj_month' => null,
                'checked' => 0, 'group_no' => null, 'split_parent' => null, 'split_seq' => null,
                'memo' => $it['note'],
            ];
        }
        return ['party_name' => $d['head']['maker_name'], 'lines' => $lines, 'head' => $d['head']];
    }

    // 應收：partyId 傳的是客戶簡稱（is_list 以簡稱歸戶）
    $d = acc_ar_detail($db, $partyId, $bm);
    foreach ($d['items'] as $it) {
        $lines[] = [
            'sort_order' => ++$i * 10, 'src_type' => $it['src_type'], 'src_id' => (int)$it['src_id'],
            'doc_no' => $it['no'], 'doc_date' => $it['date'], 'bom' => null,
            'product_id' => $it['product_id'], 'spec' => $it['spec'],
            'orig_qty' => (int)$it['qty'], 'orig_price' => (float)$it['unit_price'],
            'orig_amount' => (float)$it['amount'],
            'adj_qty' => null, 'adj_price' => null, 'adj_amount' => null, 'adj_month' => null,
            'checked' => 0, 'group_no' => null, 'split_parent' => null, 'split_seq' => null,
            'memo' => $it['note'],
        ];
    }
    return ['party_name' => $d['head']['customer'], 'lines' => $lines, 'head' => $d['head']];
}

/** 一列的有效金額：有調整就用調整值，否則用原始值 */
function acc_line_amount(array $l): float
{
    if (array_key_exists('adj_amount', $l) && $l['adj_amount'] !== null && $l['adj_amount'] !== '') {
        return (float)$l['adj_amount'];
    }
    return (float)($l['orig_amount'] ?? 0);
}

/** 找出哪些列是「拆分父列」（有子列指向它）。前端用 client_key，資料庫用 line_id。 */
function acc_split_parent_keys(array $lines, string $keyField = 'client_key',
                               string $parentField = 'split_parent_key'): array
{
    $p = [];
    foreach ($lines as $l) {
        $pk = $l[$parentField] ?? null;
        if ($pk !== null && $pk !== '' && $pk !== 0) $p[(string)$pk] = true;
    }
    return $p;
}

/**
 * 依明細算合計。
 * 關鍵：**有子列的父列不計入合計**——它只是容器，金額由子列代表，
 * 否則同一筆錢會被算兩次。所以前端不需要（也不該）把父列金額歸零。
 */
function acc_sheet_totals(PDO $db, array $lines, string $keyField = 'client_key',
                          string $parentField = 'split_parent_key'): array
{
    $parents = acc_split_parent_keys($lines, $keyField, $parentField);
    $net = 0.0;
    foreach ($lines as $l) {
        $k = (string)($l[$keyField] ?? '');
        if ($k !== '' && isset($parents[$k])) continue;      // 拆分父列不計入
        $net += acc_line_amount($l);
    }
    $tax = round($net * acc_tax_rate($db));
    return ['our_total' => $net, 'tax_amount' => $tax, 'total_amount' => $net + $tax];
}

/**
 * 儲存底稿（暫存）。整批取代明細，並驗證拆分金額守恆。
 * @param array $sheet side, party_id, party_name, billing_month, their_total, memo
 * @param array $lines 前端目前畫面上的所有列
 */
function acc_sheet_save(PDO $db, array $sheet, array $lines, ?array $user): array
{
    acc_ensure_schema($db);
    $side = ($sheet['side'] ?? 'ar') === 'ap' ? 'ap' : 'ar';
    $pid  = trim((string)($sheet['party_id'] ?? ''));
    $bm   = trim((string)($sheet['billing_month'] ?? ''));
    if ($pid === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) {
        return ['success' => false, 'message' => '缺少對象或帳款月份'];
    }

    // 已確認鎖帳的底稿不可覆蓋
    $cur = acc_sheet_get($db, $side, $pid, $bm);
    if ($cur && $cur['status'] === 'confirmed') {
        return ['success' => false,
                'message' => '此對帳單已於 ' . $cur['confirmed_at'] . ' 由 ' . $cur['confirmed_by_name']
                           . ' 確認鎖帳，不可再存暫存。需修改請由會計管理員退回重對。'];
    }

    /* 拆分守恆：同一父列的子列金額合計必須等於父列的有效金額。
       比對基準是父列自己的金額（adj 優先、否則 orig），不是「被歸零後的 0」——
       父列只是容器，金額由子列代表，所以父列不必也不該被前端歸零。 */
    $byParent  = [];
    $parentAmt = [];
    $parentNo  = [];
    foreach ($lines as $idx => $l) {
        $key = (string)($l['client_key'] ?? ('idx' . $idx));
        $pk  = $l['split_parent_key'] ?? null;
        if ($pk !== null && $pk !== '') {
            $byParent[(string)$pk][] = acc_line_amount($l);
        }
        $parentAmt[$key] = acc_line_amount($l);
        $parentNo[$key]  = (string)($l['doc_no'] ?? $key);
    }
    foreach ($byParent as $pk => $childAmts) {
        if (!array_key_exists($pk, $parentAmt)) {
            return ['success' => false, 'message' => '拆分資料異常：找不到子列對應的原列'];
        }
        $sum = array_sum($childAmts);
        if (abs($sum - $parentAmt[$pk]) > 0.01) {
            return ['success' => false,
                    'message' => '拆分金額不符（' . $parentNo[$pk] . '）：拆出的 ' . count($childAmts)
                               . ' 段合計 ' . number_format($sum, 2)
                               . '，原列金額 ' . number_format($parentAmt[$pk], 2)
                               . '，兩者必須相等（對帳不可把錢對掉）'];
        }
    }

    $t = acc_sheet_totals($db, $lines);
    try {
        $db->beginTransaction();
        if ($cur) {
            $sid = (int)$cur['sheet_id'];
            $db->prepare("UPDATE acc_recon_sheet SET party_name=?, our_total=?, their_total=?,
                          tax_amount=?, total_amount=?, memo=?, status='draft',
                          saved_by=?, saved_by_name=?, saved_at=NOW()
                          WHERE sheet_id=?")
               ->execute([$sheet['party_name'] ?? null, $t['our_total'],
                          ($sheet['their_total'] === '' || $sheet['their_total'] === null) ? null : (float)$sheet['their_total'],
                          $t['tax_amount'], $t['total_amount'],
                          mb_substr((string)($sheet['memo'] ?? ''), 0, 500) ?: null,
                          $user ? (int)$user['id'] : null, $user ? (string)$user['user_cname'] : null, $sid]);
            $db->prepare("DELETE FROM acc_recon_line WHERE sheet_id=?")->execute([$sid]);
        } else {
            $db->prepare("INSERT INTO acc_recon_sheet
                          (side, party_id, party_name, billing_month, status, our_total, their_total,
                           tax_amount, total_amount, memo, saved_by, saved_by_name, saved_at)
                          VALUES (?,?,?,?,'draft',?,?,?,?,?,?,?,NOW())")
               ->execute([$side, $pid, $sheet['party_name'] ?? null, $bm, $t['our_total'],
                          ($sheet['their_total'] === '' || $sheet['their_total'] === null) ? null : (float)$sheet['their_total'],
                          $t['tax_amount'], $t['total_amount'],
                          mb_substr((string)($sheet['memo'] ?? ''), 0, 500) ?: null,
                          $user ? (int)$user['id'] : null, $user ? (string)$user['user_cname'] : null]);
            $sid = (int)$db->lastInsertId();
        }

        // 先插入非拆分列，取得 line_id 後再插子列並接上 split_parent
        $ins = $db->prepare("INSERT INTO acc_recon_line
            (sheet_id, sort_order, src_type, src_id, doc_no, doc_date, bom, product_id, spec,
             orig_qty, orig_price, orig_amount, adj_qty, adj_price, adj_amount, adj_month,
             checked, group_no, split_parent, split_seq, memo)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $keyToId = [];
        foreach ([false, true] as $pass) {                 // 第一輪父列、第二輪子列
            $seq = 0;
            foreach ($lines as $idx => $l) {
                $isChild = !empty($l['split_parent_key']);
                if ($isChild !== $pass) continue;
                $seq++;
                $nul = fn($v) => ($v === '' || $v === null) ? null : $v;
                $ins->execute([
                    $sid, (int)($l['sort_order'] ?? $seq * 10),
                    strtoupper((string)($l['src_type'] ?? 'MANUAL')), (int)($l['src_id'] ?? 0),
                    $nul($l['doc_no'] ?? null), $nul($l['doc_date'] ?? null), $nul($l['bom'] ?? null),
                    $nul($l['product_id'] ?? null), $nul($l['spec'] ?? null),
                    $nul($l['orig_qty'] ?? null), $nul($l['orig_price'] ?? null), $nul($l['orig_amount'] ?? null),
                    $nul($l['adj_qty'] ?? null), $nul($l['adj_price'] ?? null),
                    $nul($l['adj_amount'] ?? null), $nul($l['adj_month'] ?? null),
                    !empty($l['checked']) ? 1 : 0,
                    $nul($l['group_no'] ?? null),
                    $isChild ? ($keyToId[$l['split_parent_key']] ?? null) : null,
                    $nul($l['split_seq'] ?? null),
                    $nul($l['memo'] ?? null),
                ]);
                if (!empty($l['client_key'])) $keyToId[$l['client_key']] = (int)$db->lastInsertId();
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '暫存失敗：' . $e->getMessage()];
    }

    return ['success' => true, 'sheet_id' => $sid, 'totals' => $t,
            'message' => '已暫存對帳進度（' . count($lines) . ' 列）'];
}

/** 確認正確 → 鎖帳。涵蓋的憑證一併標為已對完，並寫稽核。 */
function acc_sheet_confirm(PDO $db, int $sheetId, ?array $user): array
{
    acc_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM acc_recon_sheet WHERE sheet_id=? LIMIT 1");
    $st->execute([$sheetId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return ['success' => false, 'message' => '找不到對帳單'];
    if ($s['status'] === 'confirmed') {
        return ['success' => false, 'message' => '此對帳單已經確認鎖帳（' . $s['confirmed_at'] . '）'];
    }

    $sl = $db->prepare("SELECT line_id, src_type, src_id, checked FROM acc_recon_line WHERE sheet_id=?");
    $sl->execute([$sheetId]);
    $lines = $sl->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) return ['success' => false, 'message' => '此對帳單沒有明細，不能確認'];

    $unchecked = 0;
    foreach ($lines as $l) if (empty($l['checked'])) $unchecked++;

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE acc_recon_sheet SET status='confirmed', confirmed_by=?, confirmed_by_name=?,
                      confirmed_at=NOW() WHERE sheet_id=?")
           ->execute([$user ? (int)$user['id'] : null, $user ? (string)$user['user_cname'] : null, $sheetId]);

        // 涵蓋的原始憑證標為已對完
        foreach ($lines as $l) {
            $t = strtoupper((string)$l['src_type']);
            if (!in_array($t, ['IS', 'IR', 'TLOG', 'PURC'], true) || (int)$l['src_id'] <= 0) continue;
            $side = in_array($t, ['TLOG', 'PURC'], true) ? 'ap' : 'ar';
            $db->prepare("INSERT INTO acc_recon (src_type, src_id, side, status, recon_by, recon_by_name, recon_at)
                          VALUES (?,?,?,'ok',?,?,NOW())
                          ON DUPLICATE KEY UPDATE status='ok', recon_by=VALUES(recon_by),
                            recon_by_name=VALUES(recon_by_name), recon_at=NOW(), Modified_At=NOW()")
               ->execute([$t, (int)$l['src_id'], $side,
                          $user ? (int)$user['id'] : null, $user ? (string)$user['user_cname'] : null]);
        }

        acc_audit($db, 'ACC_RECON', 'acc_recon_sheet', $sheetId,
                  $s['party_name'] . ' ' . $s['billing_month'], [
                      'status'       => ['old' => $s['status'], 'new' => 'confirmed'],
                      'our_total'    => ['old' => null, 'new' => (float)$s['our_total']],
                      'their_total'  => ['old' => null, 'new' => $s['their_total'] === null ? null : (float)$s['their_total']],
                      'tax_amount'   => ['old' => null, 'new' => (float)$s['tax_amount']],
                      'total_amount' => ['old' => null, 'new' => (float)$s['total_amount']],
                      'line_count'   => ['old' => null, 'new' => count($lines)],
                  ], '對帳確認鎖帳', $user);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '確認失敗：' . $e->getMessage()];
    }

    return ['success' => true, 'locked' => count($lines), 'unchecked' => $unchecked,
            'message' => '已確認鎖帳，' . count($lines) . ' 筆憑證標記為已對完'
                       . ($unchecked ? "（其中 {$unchecked} 筆未勾選，仍一併鎖定）" : '')];
}

/** 退回重對（僅會計管理員；權限在 API 層檢查） */
function acc_sheet_reopen(PDO $db, int $sheetId, string $reason, ?array $user): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 2) return ['success' => false, 'message' => '請填寫退回原因（至少 2 個字）'];

    $st = $db->prepare("SELECT * FROM acc_recon_sheet WHERE sheet_id=? LIMIT 1");
    $st->execute([$sheetId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return ['success' => false, 'message' => '找不到對帳單'];
    if ($s['status'] !== 'confirmed') return ['success' => false, 'message' => '此對帳單並未處於鎖帳狀態'];

    try {
        $db->beginTransaction();
        $db->prepare("UPDATE acc_recon_sheet SET status='reopened', reopen_by=?, reopen_by_name=?,
                      reopen_at=NOW(), reopen_reason=? WHERE sheet_id=?")
           ->execute([$user ? (int)$user['id'] : null, $user ? (string)$user['user_cname'] : null,
                      mb_substr($reason, 0, 300), $sheetId]);
        acc_audit($db, 'ACC_RECON', 'acc_recon_sheet', $sheetId,
                  $s['party_name'] . ' ' . $s['billing_month'],
                  ['status' => ['old' => 'confirmed', 'new' => 'reopened']], $reason, $user);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '退回失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'message' => '已退回重對，對帳人員可以繼續修改'];
}

/**
 * 底稿清單（會計／稽核看誰對完了、誰還卡著）
 *
 * 排序與統計一律對「全部符合條件」的資料算完才分頁（差額是運算欄位，
 * 只用當頁資料排會排錯）。per_page=0 或未給＝不分頁全部回傳，
 * recon_parties 等既有呼叫端靠這個預設值維持原行為。
 */
function acc_sheet_list(PDO $db, array $f): array
{
    acc_ensure_schema($db);
    $where = []; $params = [];
    if (!empty($f['side']) && $f['side'] !== 'all') { $where[] = "s.side = ?"; $params[] = $f['side']; }
    if (!empty($f['status']) && $f['status'] !== 'all') { $where[] = "s.status = ?"; $params[] = $f['status']; }
    if (!empty($f['bm_from'])) { $where[] = "s.billing_month >= ?"; $params[] = $f['bm_from']; }
    if (!empty($f['bm_to']))   { $where[] = "s.billing_month <= ?"; $params[] = $f['bm_to']; }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(s.party_name LIKE ? OR s.party_id LIKE ? OR s.saved_by_name LIKE ? OR s.confirmed_by_name LIKE ?)";
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $db->prepare("SELECT s.*,
                          (SELECT COUNT(*) FROM acc_recon_line l WHERE l.sheet_id = s.sheet_id) AS line_cnt,
                          (SELECT COUNT(*) FROM acc_recon_line l2 WHERE l2.sheet_id = s.sheet_id AND l2.checked = 1) AS checked_cnt,
                          (SELECT COUNT(*) FROM acc_recon_line l3 WHERE l3.sheet_id = s.sheet_id
                             AND (l3.adj_qty IS NOT NULL OR l3.adj_price IS NOT NULL
                                  OR l3.adj_amount IS NOT NULL OR l3.adj_month IS NOT NULL)) AS adj_cnt
                        FROM acc_recon_sheet s $ws
                        ORDER BY s.billing_month DESC, s.side, s.party_name");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['our_total']    = (float)$r['our_total'];
        $r['their_total']  = $r['their_total'] === null ? null : (float)$r['their_total'];
        $r['total_amount'] = (float)$r['total_amount'];
        $r['diff']         = ($r['their_total'] === null) ? null : round($r['their_total'] - $r['our_total'], 2);
        $r['line_cnt']     = (int)$r['line_cnt'];
        $r['checked_cnt']  = (int)$r['checked_cnt'];
        $r['adj_cnt']      = (int)$r['adj_cnt'];
    }
    unset($r);

    /* only_diff／only_open 是後端篩選（不是前端只挑當頁），
       否則「有差額的共幾份」會隨當頁載到什麼而變動。 */
    if (!empty($f['only_diff'])) {
        $rows = array_values(array_filter($rows, function ($r) {
            return $r['diff'] !== null && abs($r['diff']) > 0.01;
        }));
    }
    if (!empty($f['only_open'])) {
        $rows = array_values(array_filter($rows, function ($r) {
            return $r['status'] !== 'confirmed';
        }));
    }

    $summary = ['count' => count($rows), 'draft' => 0, 'confirmed' => 0, 'reopened' => 0, 'diff_cnt' => 0];
    foreach ($rows as $r) {
        if (isset($summary[$r['status']])) $summary[$r['status']]++;
        if ($r['diff'] !== null && abs($r['diff']) > 0.01) $summary['diff_cnt']++;
    }

    /* 排序（對全部資料排，不是只排當頁） */
    $sortable = ['billing_month', 'party_name', 'party_id', 'side', 'status',
                 'our_total', 'their_total', 'total_amount', 'diff', 'abs_diff',
                 'line_cnt', 'checked_cnt', 'adj_cnt', 'saved_at', 'confirmed_at'];
    $sort = (string)($f['sort'] ?? '');
    if (!in_array($sort, $sortable, true)) $sort = '';
    if ($sort !== '') {
        $dir     = (($f['dir'] ?? 'desc') === 'asc') ? 1 : -1;
        $numeric = ['our_total', 'their_total', 'total_amount', 'tax_amount',
                    'diff', 'abs_diff', 'line_cnt', 'checked_cnt', 'adj_cnt'];
        $stOrder = ['reopened' => 0, 'draft' => 1, 'confirmed' => 2];
        usort($rows, function ($a, $b) use ($sort, $dir, $numeric, $stOrder) {
            if ($sort === 'abs_diff') {
                // 差額為 null（對方紙本還沒填）一律排到最後，不論升冪降冪
                $x = ($a['diff'] === null) ? null : abs($a['diff']);
                $y = ($b['diff'] === null) ? null : abs($b['diff']);
            } elseif ($sort === 'status') {
                $x = $stOrder[$a['status']] ?? 9; $y = $stOrder[$b['status']] ?? 9;
            } else {
                $x = $a[$sort] ?? null; $y = $b[$sort] ?? null;
            }
            if ($x === null && $y === null) return 0;
            if ($x === null) return 1;      // null 永遠墊底
            if ($y === null) return -1;
            if (in_array($sort, $numeric, true) || $sort === 'status') {
                return ((float)$x <=> (float)$y) * $dir;
            }
            return strcmp((string)$x, (string)$y) * $dir;
        });
    }

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 0);
    if ($perPage > 0) {
        if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
        $page = max(1, (int)($f['page'] ?? 1));
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        return ['rows' => $rows, 'total' => $total, 'page' => $page,
                'per_page' => $perPage, 'summary' => $summary];
    }
    return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
}

/* ============================================================
 * 稽核紀錄
 *
 * 沿用全站既有的 audit_log 表（已有 2,120 筆、且有現成查詢頁
 * views/admin/audit_log_report.php），不另建一套會計專用稽核表——
 * 帳款出問題時只需要查一個地方。
 * action_type：ACC_EDIT=改單據金額/數量/備註、ACC_MONTH=改帳款月份、
 *              ACC_RECON=對帳狀態變更
 * ============================================================ */
function acc_audit(PDO $db, string $action, string $targetType, $targetId,
                   ?string $targetName, array $changes, ?string $reason, ?array $user): void
{
    try {
        $payload = ['changes' => $changes];
        if ($reason !== null && $reason !== '') $payload['reason'] = $reason;
        $st = $db->prepare("INSERT INTO audit_log
                            (action_type, target_type, target_id, target_name, changes,
                             user_id, operator, created_at)
                            VALUES (?,?,?,?,?,?,?,NOW())");
        $st->execute([$action, $targetType, (string)$targetId, $targetName,
                      json_encode($payload, JSON_UNESCAPED_UNICODE),
                      $user ? (int)$user['id'] : null,
                      $user ? (string)$user['user_cname'] : 'system']);
    } catch (Throwable $e) {
        // 稽核寫入失敗不可讓業務操作整批失敗，但一定要留在錯誤日誌裡
        error_log('acc_audit 寫入失敗: ' . $e->getMessage());
    }
}

/** 會計相關稽核紀錄查詢 */
function acc_audit_search(PDO $db, array $f): array
{
    $where  = ["a.action_type IN ('ACC_EDIT','ACC_MONTH','ACC_RECON')"];
    $params = [];

    if (!empty($f['action'])   && $f['action'] !== 'all') { $where[] = "a.action_type = ?"; $params[] = $f['action']; }
    if (!empty($f['date_from'])) { $where[] = "a.created_at >= ?"; $params[] = $f['date_from'] . ' 00:00:00'; }
    if (!empty($f['date_to']))   { $where[] = "a.created_at <= ?"; $params[] = $f['date_to']   . ' 23:59:59'; }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(a.target_id LIKE ? OR a.target_name LIKE ? OR a.operator LIKE ? OR a.changes LIKE ?)";
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $kw . '%';
    }
    $ws = 'WHERE ' . implode(' AND ', $where);

    $st = $db->prepare("SELECT a.id, a.action_type, a.target_type, a.target_id, a.target_name,
                               a.changes, a.user_id, a.operator, a.created_at
                        FROM audit_log a
                        $ws
                        ORDER BY a.id DESC
                        LIMIT 500");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $j = json_decode((string)$r['changes'], true);
        $r['parsed']  = is_array($j) ? ($j['changes'] ?? []) : [];
        $r['reason']  = is_array($j) ? ($j['reason'] ?? '') : '';
        $r['raw_len'] = strlen((string)$r['changes']);
    }
    unset($r);

    $summary = ['count' => count($rows), 'ACC_EDIT' => 0, 'ACC_MONTH' => 0, 'ACC_RECON' => 0];
    foreach ($rows as $r) if (isset($summary[$r['action_type']])) $summary[$r['action_type']]++;

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));
    return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'summary' => $summary];
}

/* ============================================================
 * 對帳：線上修改單據 + 對帳狀態
 * ============================================================ */

/**
 * 各來源可線上修改的欄位白名單。應收單價是 int（資料表型別限制），不接受小數。
 *
 * PURC（採購單）刻意回空陣列＝**不可從會計端線上改金額**：
 * purchase_request 的 subtotal/tax_amount/grand_total 是由 purchase_item 明細算出來的，
 * 只改單頭會讓採購頁自己算的總額對不起來。要改請回採購頁改明細。
 * 對帳時仍可用底稿的「調整」欄位（那只寫底稿、不寫回來源），所以不影響對帳。
 */
function acc_editable_fields(string $srcType): array
{
    switch (strtoupper($srcType)) {
        case 'IS':   return ['Qty' => 'int', 'Unit_price' => 'int', 'Note' => 'text'];
        case 'IR':   return ['Qty' => 'int', 'Unit_price' => 'int', 'IR_ps' => 'text'];
        case 'TLOG': return ['transfer_qty' => 'int', 'modified_unit_price' => 'dec',
                             'process_amount' => 'dec', 'tax_amount' => 'dec', 'note' => 'text'];
        case 'PURC': return [];
        default:     return [];
    }
}

/** 單據所在的表與主鍵 */
function acc_src_table(string $srcType): array
{
    switch (strtoupper($srcType)) {
        case 'IS':   return ['is_list', 'IS_id', 'IS_number'];
        case 'IR':   return ['ir_track', 'IR_id', 'IR_no'];
        case 'TLOG': return ['bom_ing_transfer_log', 'transfer_id', 'transfer_no'];
        case 'PURC': return ['purchase_request', 'req_id', 'req_no'];
        default:     return [];
    }
}

/**
 * 線上修改單據欄位（對帳用）。每一次修改都寫 audit_log，不留紀錄就不寫入。
 * @param array  $fields 欄位=>新值（只認白名單）
 * @param string $reason 修改原因（必填，帳款爭議時要查得出為什麼改）
 */
function acc_edit_doc(PDO $db, string $srcType, int $id, array $fields, string $reason, ?array $user): array
{
    acc_ensure_schema($db);
    $srcType = strtoupper(trim($srcType));
    $meta    = acc_src_table($srcType);
    $allow   = acc_editable_fields($srcType);
    if (!$meta || !$allow) return ['success' => false, 'message' => '不支援的單據類型'];
    if ($id <= 0)          return ['success' => false, 'message' => '缺少單據'];
    $reason = trim($reason);
    if (mb_strlen($reason) < 2) {
        return ['success' => false, 'message' => '請填寫修改原因（至少 2 個字），帳款有爭議時要查得出為什麼改'];
    }
    [$tbl, $pk, $noCol] = $meta;

    // 對帳已確認鎖帳的憑證不可改——要改請會計管理員先退回重對
    $lockedBy = acc_sheet_locked_src($db, $srcType, $id);
    if ($lockedBy) {
        return ['success' => false,
                'message' => '此單據所在的對帳單（' . $lockedBy['party_name'] . ' ' . $lockedBy['billing_month']
                           . '）已於 ' . $lockedBy['confirmed_at'] . ' 由 ' . $lockedBy['confirmed_by_name']
                           . ' 確認鎖帳，不可修改。需修改請洽會計管理員退回重對。'];
    }

    // 應收側：已開發票的單據不可改金額（金額已在發票上）
    if ($srcType === 'IS' || $srcType === 'IR') {
        $used = acc_invoiced_src_map($db);
        $u = $used[$srcType . '-' . $id] ?? null;
        $touchMoney = array_intersect(array_keys($fields), ['Qty', 'Unit_price']);
        if ($u && $touchMoney) {
            return ['success' => false,
                    'message' => '此單據已開立在發票 ' . ($u['invoice_no'] ?: '#' . $u['invoice_id'])
                               . ' 上，不可修改數量或單價。若確定要改，請先作廢該發票。'];
        }
    }

    // 讀舊值
    $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($allow)));
    $st = $db->prepare("SELECT $noCol AS doc_no, $cols FROM $tbl WHERE $pk = ? LIMIT 1");
    $st->execute([$id]);
    $old = $st->fetch(PDO::FETCH_ASSOC);
    if (!$old) return ['success' => false, 'message' => '找不到該單據'];

    $set = []; $vals = []; $changes = [];
    foreach ($fields as $col => $nv) {
        if (!isset($allow[$col])) continue;
        $type = $allow[$col];
        $ov   = $old[$col];

        if ($type === 'int') {
            $s = trim((string)$nv);
            if ($s === '') { $new = null; }
            else {
                if (!preg_match('/^-?\d+$/', str_replace(',', '', $s))) {
                    return ['success' => false,
                            'message' => "「{$col}」只能填整數（資料表欄位型別為 int，不接受小數）"];
                }
                $new = (int)str_replace(',', '', $s);
            }
        } elseif ($type === 'dec') {
            $s = trim((string)$nv);
            if ($s === '') { $new = null; }
            else {
                if (!is_numeric(str_replace(',', '', $s))) {
                    return ['success' => false, 'message' => "「{$col}」必須是數字"];
                }
                $new = round((float)str_replace(',', '', $s), 2);
            }
        } else {
            $new = trim((string)$nv);
            if ($new === '') $new = null;
        }

        // 比較（數值用數值比，避免 "100" 與 100 被當成有變動）
        $same = ($type === 'text')
            ? ((string)$ov === (string)$new)
            : (($ov === null && $new === null) || (abs((float)$ov - (float)$new) < 0.0001));
        if ($same) continue;

        $set[]  = "`$col` = ?";
        $vals[] = $new;
        $changes[$col] = ['old' => $ov, 'new' => $new];
    }

    if (!$set) return ['success' => true, 'updated' => 0, 'message' => '沒有欄位變動'];

    // 加工單改了數量或單價時，加工費與稅額要跟著重算（除非使用者自己指定了金額）
    if ($srcType === 'TLOG' && !isset($changes['process_amount'])) {
        $q  = $changes['transfer_qty']['new']        ?? $old['transfer_qty'];
        $up = $changes['modified_unit_price']['new'] ?? $old['modified_unit_price'];
        if ($up !== null && $q !== null) {
            $amt = round((float)$q * (float)$up, 2);
            if (abs((float)$old['process_amount'] - $amt) > 0.0001) {
                $set[] = "`process_amount` = ?"; $vals[] = $amt;
                $changes['process_amount'] = ['old' => $old['process_amount'], 'new' => $amt];
                if (!isset($changes['tax_amount'])) {
                    $tax = round($amt * acc_tax_rate($db));
                    $set[] = "`tax_amount` = ?"; $vals[] = $tax;
                    $changes['tax_amount'] = ['old' => $old['tax_amount'], 'new' => $tax];
                }
            }
        }
    }

    try {
        $db->beginTransaction();
        // 各表的修改人欄位名稱不同，有就一起更新
        if ($srcType === 'IR')        { $set[] = "Modified_By = ?"; $vals[] = $user ? (string)$user['id'] : null;
                                        $set[] = "Modified_At = NOW()"; }
        elseif ($srcType === 'TLOG')  { $set[] = "changed_by = ?";  $vals[] = $user ? (string)$user['id'] : null;
                                        $set[] = "modified_at = NOW()"; }
        $vals[] = $id;
        $st = $db->prepare("UPDATE $tbl SET " . implode(', ', $set) . " WHERE $pk = ?");
        $st->execute($vals);
        $n = $st->rowCount();
        acc_audit($db, 'ACC_EDIT', $tbl, $id, (string)$old['doc_no'], $changes, $reason, $user);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '修改失敗：' . $e->getMessage()];
    }

    return ['success' => true, 'updated' => $n, 'changes' => $changes,
            'message' => '已修改 ' . count($changes) . ' 個欄位並寫入稽核紀錄'];
}

/** 設定對帳狀態／註記（業務對應收、生管對應付） */
function acc_recon_set(PDO $db, string $srcType, int $id, string $status,
                       ?string $note, ?array $user): array
{
    acc_ensure_schema($db);
    $srcType = strtoupper(trim($srcType));
    $meta = acc_src_table($srcType);
    if (!$meta) return ['success' => false, 'message' => '不支援的單據類型'];
    if ($id <= 0) return ['success' => false, 'message' => '缺少單據'];
    if (!in_array($status, ['pending', 'ok', 'issue'], true)) {
        return ['success' => false, 'message' => '對帳狀態只能是 未對／已對完／有異常'];
    }
    $side = in_array($srcType, ['TLOG', 'PURC'], true) ? 'ap' : 'ar';
    $note = ($note === null || trim($note) === '') ? null : mb_substr(trim($note), 0, 300);

    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT status, note FROM acc_recon WHERE src_type=? AND src_id=? LIMIT 1");
        $st->execute([$srcType, $id]);
        $old = $st->fetch(PDO::FETCH_ASSOC);

        if ($old) {
            $up = $db->prepare("UPDATE acc_recon SET status=?, note=?, side=?, recon_by=?, recon_by_name=?,
                                recon_at=NOW(), Modified_At=NOW() WHERE src_type=? AND src_id=?");
            $up->execute([$status, $note, $side, $user ? (int)$user['id'] : null,
                          $user ? (string)$user['user_cname'] : null, $srcType, $id]);
        } else {
            $ins = $db->prepare("INSERT INTO acc_recon
                                 (src_type, src_id, side, status, note, recon_by, recon_by_name, recon_at)
                                 VALUES (?,?,?,?,?,?,?,NOW())");
            $ins->execute([$srcType, $id, $side, $status, $note,
                           $user ? (int)$user['id'] : null, $user ? (string)$user['user_cname'] : null]);
        }

        acc_audit($db, 'ACC_RECON', $meta[0], $id, null, [
            'status' => ['old' => $old['status'] ?? 'pending', 'new' => $status],
            'note'   => ['old' => $old['note'] ?? null,        'new' => $note],
        ], null, $user);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '對帳狀態儲存失敗：' . $e->getMessage()];
    }

    $lbl = ['pending' => '未對帳', 'ok' => '已對完', 'issue' => '有異常'];
    return ['success' => true, 'message' => '已標記為「' . $lbl[$status] . '」'];
}

/** 批次取對帳狀態：'IS-123' => row */
function acc_recon_map(PDO $db, string $srcType, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT * FROM acc_recon WHERE src_type = ? AND src_id IN ($ph)");
    $st->execute(array_merge([strtoupper($srcType)], $ids));
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[$r['src_type'] . '-' . (int)$r['src_id']] = $r;
    return $m;
}

/* ============================================================
 * 權限（RBAC，比照 shipping_lib.php / vendor_audit_lib.php）
 * ============================================================ */
function acc_current_user(PDO $db): ?array
{
    $uname = $_SESSION['userName'] ?? '';
    if ($uname === '') return null;
    $st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname = ?");
    $st->execute([$uname]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function acc_has_role(PDO $db, int $uid, array $codes): bool
{
    if (!$codes) return false;
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                        WHERE ur.user_id = ? AND r.module = '" . ACC_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    if ($st->fetchColumn()) return true;
    $st = $db->prepare("SELECT 1 FROM user_department_position_map m
                        JOIN position_roles pr ON pr.position_id = m.position_id AND (pr.department_id=0 OR pr.department_id=m.department_id)
                        JOIN roles r ON r.role_id = pr.role_id
                        WHERE m.user_id = ? AND r.module = '" . ACC_MODULE . "' AND r.role_code IN ($in) LIMIT 1");
    $st->execute(array_merge([$uid], $codes));
    return (bool)$st->fetchColumn();
}

function acc_perms(PDO $db, ?array $u): array
{
    if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canEdit' => false, 'canView' => false];
    $uid = (int)$u['id'];
    $isAdmin = in_array((int)$u['user_status'], [9, 90], true);
    if (!$isAdmin) {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                            WHERE ur.user_id = ? AND r.role_code = 'admin' AND r.is_system = 1 LIMIT 1");
        $st->execute([$uid]);
        $isAdmin = (bool)$st->fetchColumn();
    }
    $canAdmin = $isAdmin  || acc_has_role($db, $uid, ['acc_admin']);
    $canEdit  = $canAdmin || acc_has_role($db, $uid, ['acc_edit']);

    /* 對帳角色：實務分工是「應收由業務對完帳給會計、應付由生管對完帳給會計」。
       這兩個角色只能做對帳（改單據、標對帳狀態），不能開發票、不能沖帳、不能付款；
       而且各自只碰自己那一側——業務不能改應付、生管不能改應收。 */
    $reconAr = acc_has_role($db, $uid, ['acc_ar_recon']);
    $reconAp = acc_has_role($db, $uid, ['acc_ap_recon']);

    $canView = $canEdit || $reconAr || $reconAp || acc_has_role($db, $uid, ['acc_view']);

    return [
        'isAdmin'    => $isAdmin,
        'canAdmin'   => $canAdmin,
        'canEdit'    => $canEdit,          // 會計本身：開票、沖帳、匯入
        'canView'    => $canView,
        'reconAr'    => $reconAr,          // 純業務對帳角色（不含會計權限）
        'reconAp'    => $reconAp,          // 純生管對帳角色
        'canReconAr' => $canEdit || $reconAr,   // 可修改應收單據與標對帳狀態
        'canReconAp' => $canEdit || $reconAp,   // 可修改應付單據與標對帳狀態
    ];
}

/* ============================================================
 * 統一編號驗證（財政部檢查碼規則）
 * ============================================================ */

/**
 * 台灣統一編號檢查：8 位數字 + 加權檢查碼。
 * 權重 1,2,1,2,1,2,4,1；各位數乘權重後「各自拆成十位+個位相加」再總和，
 * 總和能被 5 整除即有效；第 7 位為 7 時，總和 +1 亦視為有效（特例）。
 */
function acc_valid_tax_id(?string $t): bool
{
    $t = preg_replace('/\D/', '', (string)$t);
    if (strlen($t) !== 8) return false;
    if ($t === '00000000') return false;      // 全零雖能被 5 整除，但不是有效統編

    $w   = [1, 2, 1, 2, 1, 2, 4, 1];
    $sum = 0;
    for ($i = 0; $i < 8; $i++) {
        $p = ((int)$t[$i]) * $w[$i];
        $sum += intdiv($p, 10) + ($p % 10);
    }
    if ($sum % 5 === 0) return true;
    return ($t[6] === '7' && ($sum + 1) % 5 === 0);
}

/** 回傳客戶發票資料的完備狀態：ok / no_tax / bad_tax / no_full */
function acc_invoice_ready(array $c): string
{
    $tax  = trim((string)($c['tax_id'] ?? ''));
    $full = trim((string)($c['customer_full'] ?? ''));
    if ($tax === '')                  return 'no_tax';
    if (!acc_valid_tax_id($tax))      return 'bad_tax';
    if ($full === '')                 return 'no_full';
    return 'ok';
}

/* ============================================================
 * 客戶發票資料清單
 * ============================================================ */

/**
 * 客戶清單 + 近一年出貨統計（用來決定先補哪些客戶的統編）。
 * 出貨歸戶：is_list 近期 Client_id 幾乎為 NULL，只能用簡稱 Client_name 對 customer_list.customer。
 *
 * @param array $f kw, status(all|no_tax|bad_tax|no_full|ok|shipped), sort, dir, page, per_page, months
 * @return array ['rows'=>, 'total'=>, 'summary'=>]
 */
function acc_customer_invoice_list(PDO $db, array $f): array
{
    $months = (int)($f['months'] ?? 12);
    if ($months <= 0 || $months > 60) $months = 12;
    $since = date('Y-m-d', strtotime("-{$months} months"));

    $where  = ['(c.is_inactive = 0 OR c.is_inactive IS NULL)'];
    $params = [':since' => $since];

    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(c.customer LIKE :kw OR c.customer_full LIKE :kw
                     OR c.customer_id LIKE :kw OR c.tax_id LIKE :kw)";
        $params[':kw'] = '%' . $kw . '%';
    }

    $sql = "
        SELECT c.customer_id, c.customer, c.customer_full, c.tax_id, c.invoice_email,
               c.customer_address, c.billing_contact,
               COALESCE(s.cnt, 0) AS ship_cnt,
               COALESCE(s.amt, 0) AS ship_amt,
               s.last_date
        FROM customer_list c
        LEFT JOIN (
            SELECT il.Client_name,
                   COUNT(*)                       AS cnt,
                   SUM(il.Qty * il.Unit_price)    AS amt,
                   DATE_FORMAT(MAX(il.Order_date), '%Y-%m-%d') AS last_date
            FROM is_list il
            WHERE il.Order_date >= :since
            GROUP BY il.Client_name
        ) s ON s.Client_name = c.customer
        WHERE " . implode(' AND ', $where);

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // 完備狀態
    foreach ($rows as &$r) {
        $r['ship_cnt'] = (int)$r['ship_cnt'];
        $r['ship_amt'] = (float)$r['ship_amt'];
        $r['status']   = acc_invoice_ready($r);
        $r['tax_ok']   = ($r['tax_id'] !== null && trim($r['tax_id']) !== '')
                         ? acc_valid_tax_id($r['tax_id']) : null;
    }
    unset($r);

    // 全部符合條件先算合計（不可只用當頁）
    $summary = ['total' => count($rows), 'ok' => 0, 'no_tax' => 0, 'bad_tax' => 0,
                'no_full' => 0, 'shipped' => 0, 'shipped_no_tax' => 0];
    foreach ($rows as $r) {
        $summary[$r['status']]++;
        if ($r['ship_cnt'] > 0) {
            $summary['shipped']++;
            if ($r['status'] !== 'ok') $summary['shipped_no_tax']++;
        }
    }

    // 狀態篩選
    $status = $f['status'] ?? 'all';
    if ($status === 'shipped') {
        $rows = array_values(array_filter($rows, fn($r) => $r['ship_cnt'] > 0));
    } elseif ($status === 'shipped_gap') {
        $rows = array_values(array_filter($rows, fn($r) => $r['ship_cnt'] > 0 && $r['status'] !== 'ok'));
    } elseif ($status !== 'all') {
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $status));
    }

    // 排序：預設「近一年出貨金額」由大到小 —— 先補最重要的客戶
    $sort = $f['sort'] ?? 'ship_amt';
    $dir  = (($f['dir'] ?? 'desc') === 'asc') ? 1 : -1;
    usort($rows, function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'customer':    return $dir * strnatcasecmp($a['customer'] ?? '', $b['customer'] ?? '');
            case 'customer_id': return $dir * strnatcasecmp($a['customer_id'], $b['customer_id']);
            case 'ship_cnt':    return $dir * ($a['ship_cnt'] <=> $b['ship_cnt']);
            case 'last_date':   return $dir * strcmp($a['last_date'] ?? '', $b['last_date'] ?? '');
            case 'status':      return $dir * strcmp($a['status'], $b['status']);
            default:            return $dir * ($a['ship_amt'] <=> $b['ship_amt']);
        }
    });

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) {
        return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    }
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));

    return [
        'rows'     => array_slice($rows, ($page - 1) * $perPage, $perPage),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => $summary,
    ];
}

/* ============================================================
 * 更新／匯入
 * ============================================================ */

/** 可由本頁維護的欄位白名單（不開放改 customer_id / customer 本身） */
function acc_invoice_fields(): array
{
    return ['customer_full', 'tax_id', 'invoice_email', 'customer_address', 'billing_contact'];
}

/** 單筆就地編輯 */
function acc_update_customer(PDO $db, string $customerId, array $data, string $userId): array
{
    $allow = acc_invoice_fields();
    $set   = [];
    $vals  = [];
    foreach ($allow as $col) {
        if (!array_key_exists($col, $data)) continue;
        $v = trim((string)$data[$col]);
        if ($col === 'tax_id' && $v !== '') {
            $v = preg_replace('/\D/', '', $v);
            if (!acc_valid_tax_id($v)) {
                return ['success' => false, 'message' => "統一編號 {$v} 檢查碼不正確，請確認"];
            }
        }
        $set[]  = "$col = ?";
        $vals[] = ($v === '') ? null : $v;
    }
    if (!$set) return ['success' => false, 'message' => '沒有要更新的欄位'];

    $set[]  = "Modified_By = ?"; $vals[] = $userId;
    $set[]  = "Modified_At = NOW()";
    $vals[] = $customerId;

    try {
        $db->beginTransaction();
        $st = $db->prepare("UPDATE customer_list SET " . implode(', ', $set) . " WHERE customer_id = ?");
        $st->execute($vals);
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'updated' => $n,
                'message' => $n > 0 ? '已更新' : '資料未變更'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '更新失敗：' . $e->getMessage()];
    }
}

/**
 * CSV 匯入試算：把上傳的每一列比對到現有客戶，回傳可套用的變更（不寫入）。
 * 比對優先序：客戶編號 > 統編 > 客戶簡稱（完全相同）> 客戶全名（完全相同）。
 *
 * @param array $rows 已解析的 CSV 資料列（關聯陣列，鍵為對應後的欄位名）
 */
function acc_import_preview(PDO $db, array $rows): array
{
    $cust = $db->query("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                               customer_address, billing_contact
                        FROM customer_list")->fetchAll(PDO::FETCH_ASSOC);

    $byId = $byName = $byFull = $byTax = [];
    foreach ($cust as $c) {
        $byId[strtoupper(trim($c['customer_id']))] = $c;
        if (trim((string)$c['customer'])      !== '') $byName[trim($c['customer'])]      = $c;
        if (trim((string)$c['customer_full']) !== '') $byFull[trim($c['customer_full'])] = $c;
        $t = preg_replace('/\D/', '', (string)$c['tax_id']);
        if ($t !== '') $byTax[$t] = $c;
    }

    $out = ['matched' => [], 'unmatched' => [], 'summary' => [
        'total' => count($rows), 'matched' => 0, 'unmatched' => 0,
        'changed' => 0, 'same' => 0, 'bad_tax' => 0]];

    foreach ($rows as $i => $r) {
        $cid  = strtoupper(trim((string)($r['customer_id']    ?? '')));
        $name = trim((string)($r['customer']      ?? ''));
        $full = trim((string)($r['customer_full'] ?? ''));
        $tax  = preg_replace('/\D/', '', (string)($r['tax_id'] ?? ''));

        $hit = null; $how = '';
        if     ($cid  !== '' && isset($byId[$cid]))    { $hit = $byId[$cid];    $how = '客戶編號'; }
        elseif ($tax  !== '' && isset($byTax[$tax]))   { $hit = $byTax[$tax];   $how = '統一編號'; }
        elseif ($name !== '' && isset($byName[$name])) { $hit = $byName[$name]; $how = '客戶簡稱'; }
        elseif ($full !== '' && isset($byFull[$full])) { $hit = $byFull[$full]; $how = '客戶全名'; }

        $taxBad = ($tax !== '' && !acc_valid_tax_id($tax));
        if ($taxBad) $out['summary']['bad_tax']++;

        if (!$hit) {
            $out['summary']['unmatched']++;
            $out['unmatched'][] = ['row' => $i + 1, 'customer_id' => $cid, 'customer' => $name,
                                   'customer_full' => $full, 'tax_id' => $tax, 'tax_bad' => $taxBad];
            continue;
        }

        // 逐欄比對出「真正會變動」的欄位（空值不覆蓋既有值）
        $chg = [];
        foreach (acc_invoice_fields() as $col) {
            $nv = trim((string)($r[$col] ?? ''));
            if ($col === 'tax_id') $nv = preg_replace('/\D/', '', $nv);
            if ($nv === '') continue;                            // 匯入檔留空 = 不動既有資料
            $ov = trim((string)($hit[$col] ?? ''));
            if ($col === 'tax_id') $ov = preg_replace('/\D/', '', $ov);
            if ($nv !== $ov) $chg[$col] = ['old' => $ov, 'new' => $nv];
        }

        $out['summary']['matched']++;
        if ($chg) $out['summary']['changed']++; else $out['summary']['same']++;

        $out['matched'][] = [
            'row'         => $i + 1,
            'customer_id' => $hit['customer_id'],
            'customer'    => $hit['customer'],
            'match_by'    => $how,
            'changes'     => $chg,
            'tax_bad'     => $taxBad,
        ];
    }
    return $out;
}

/** 套用匯入（只寫入前端勾選的 customer_id；統編檢查碼不合格者一律擋下） */
function acc_import_apply(PDO $db, array $items, string $userId): array
{
    $applied = 0; $skipped = 0; $errors = [];
    try {
        $db->beginTransaction();
        foreach ($items as $it) {
            $cid = trim((string)($it['customer_id'] ?? ''));
            $chg = $it['changes'] ?? [];
            if ($cid === '' || !$chg) { $skipped++; continue; }

            $set = []; $vals = [];
            foreach ($chg as $col => $v) {
                if (!in_array($col, acc_invoice_fields(), true)) continue;
                $nv = trim((string)(is_array($v) ? ($v['new'] ?? '') : $v));
                if ($col === 'tax_id') {
                    $nv = preg_replace('/\D/', '', $nv);
                    if ($nv !== '' && !acc_valid_tax_id($nv)) {
                        $errors[] = "{$cid}：統編 {$nv} 檢查碼不正確，已略過";
                        continue 2;
                    }
                }
                $set[]  = "$col = ?";
                $vals[] = ($nv === '') ? null : $nv;
            }
            if (!$set) { $skipped++; continue; }

            $set[]  = "Modified_By = ?"; $vals[] = $userId;
            $set[]  = "Modified_At = NOW()";
            $vals[] = $cid;
            $st = $db->prepare("UPDATE customer_list SET " . implode(', ', $set) . " WHERE customer_id = ?");
            $st->execute($vals);
            if ($st->rowCount() > 0) $applied++; else $skipped++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '匯入失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'applied' => $applied, 'skipped' => $skipped, 'errors' => $errors,
            'message' => "已更新 {$applied} 家客戶" . ($skipped ? "，略過 {$skipped} 家" : '')];
}

/* ============================================================
 * 帳款月份（結帳日切月）
 *
 * 口徑沿用 Shipping_Analysis_new.php 的 compute_billing_month_global，
 * 但額外支援客戶自己的結帳日（customer_list.settlement_mode / settlement_day）：
 *   FIXED    → 用 settlement_day（實測 908 家 = 25、5 家 = 20）
 *   EOM      → 月底結帳，等於不跨月（實測 11 家）
 *   VARIABLE → 無固定規則，退回用全域截止日（實測 1 家）
 * is_list / ir_track 的 billing_month_override 一律優先於上述計算。
 * ============================================================ */

/** 全域帳款月份截止日（system_settings.billing_cutoff_day，實測 = 25） */
function acc_global_cutoff(PDO $db): int
{
    static $c = null;
    if ($c !== null) return $c;
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='billing_cutoff_day' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        $c = ($v !== false) ? (int)$v : 0;
    } catch (Throwable $e) { $c = 0; }
    return $c;
}

/** 營業稅率（system_settings.acc_tax_rate，預設 5%） */
function acc_tax_rate(PDO $db): float
{
    static $r = null;
    if ($r !== null) return $r;
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key='acc_tax_rate' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        $r = ($v !== false && $v !== '' && (float)$v > 0) ? (float)$v : 0.05;
    } catch (Throwable $e) { $r = 0.05; }
    return $r;
}

/** 取某客戶適用的結帳日；回傳 0 = 不跨月（當月即帳款月） */
function acc_cutoff_for(?array $cust, int $global): int
{
    if (!$cust) return $global;
    $mode = strtoupper(trim((string)($cust['settlement_mode'] ?? '')));
    if ($mode === 'EOM') return 0;                       // 月底結帳＝不跨月
    if ($mode === 'FIXED') {
        $d = (int)($cust['settlement_day'] ?? 0);
        if ($d >= 1 && $d <= 31) return $d;
    }
    return $global;                                      // VARIABLE 或未設定
}

/** 依出貨/退貨日期與結帳日算出帳款月份 YYYY-MM */
function acc_billing_month(string $date, int $cutoff): string
{
    $ts = strtotime($date);
    if ($ts === false) return '';
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    $d = (int)date('j', $ts);
    if ($cutoff > 0 && $d > $cutoff) {
        return ($m === 12) ? sprintf('%04d-01', $y + 1) : sprintf('%04d-%02d', $y, $m + 1);
    }
    return sprintf('%04d-%02d', $y, $m);
}

/** 帳款月份對應要掃描的出貨日期區間（放寬一個月，實際歸屬再逐列精算） */
function acc_scan_range(string $bmFrom, string $bmTo): array
{
    $from = date('Y-m-d', strtotime($bmFrom . '-01 -1 month'));
    $to   = date('Y-m-t', strtotime($bmTo . '-01'));
    return [$from, $to];
}

/* ============================================================
 * 應收對帳（客戶 × 帳款月份）
 * ============================================================ */

/**
 * 應收金額口徑（重要）：
 *  - 不使用 is_sale_type.is_count——那是「本業業績統計」用的旗標。
 *    機台買賣/刀具/砂輪/非本業 的 is_count=0，但那些都是真的要開發票收錢的。
 *  - 計入所有金額不為 0 的出貨列；退貨(ir_track)金額則從應收扣除。
 *  - is_sale_type.exclude_when_nonzero=1（備註、歸還NG）本來就該是 0 元，
 *    若出現金額不視為正常，計入但另外標記為待確認，不靜默吞掉。
 *  - ir_return_type.is_note=1（備註性質退貨）不計金額。
 *
 * @param array $f bm_from, bm_to (YYYY-MM), customer_id, kw, only_gap, sort, dir, page, per_page
 */
function acc_ar_summary(PDO $db, array $f): array
{
    $bmFrom = preg_match('/^\d{4}-\d{2}$/', (string)($f['bm_from'] ?? '')) ? $f['bm_from'] : date('Y-m');
    $bmTo   = preg_match('/^\d{4}-\d{2}$/', (string)($f['bm_to'] ?? ''))   ? $f['bm_to']   : $bmFrom;
    if ($bmTo < $bmFrom) [$bmFrom, $bmTo] = [$bmTo, $bmFrom];
    [$scanFrom, $scanTo] = acc_scan_range($bmFrom, $bmTo);

    $global = acc_global_cutoff($db);
    $rate   = acc_tax_rate($db);

    // 客戶主檔（用簡稱歸戶：近期 is_list.Client_id 幾乎為 NULL）
    $cust = [];
    foreach ($db->query("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                                settlement_mode, settlement_day, payment_method, net_days
                         FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (trim((string)$c['customer']) !== '') $cust[trim($c['customer'])] = $c;
    }

    $bucket = [];   // key = customer_key|billing_month
    $anomaly = [];

    $addRow = function (string $name, string $bm, array $add) use (&$bucket, $cust) {
        $c   = $cust[$name] ?? null;
        $key = ($c ? $c['customer_id'] : '?' . $name) . '|' . $bm;
        if (!isset($bucket[$key])) {
            $bucket[$key] = [
                'customer_id'    => $c['customer_id']   ?? null,
                'customer'       => $name,
                'customer_full'  => $c['customer_full'] ?? null,
                'tax_id'         => $c['tax_id']        ?? null,
                'invoice_email'  => $c['invoice_email'] ?? null,
                'payment_method' => $c['payment_method'] ?? null,
                'net_days'       => $c['net_days']      ?? null,
                'in_master'      => (bool)$c,
                'billing_month'  => $bm,
                'ship_cnt' => 0, 'ship_amt' => 0.0,
                'ret_cnt'  => 0, 'ret_amt'  => 0.0,
                'anomaly'  => 0,
            ];
        }
        foreach ($add as $k => $v) $bucket[$key][$k] += $v;
    };

    // ── 出貨 ────────────────────────────────────────────────────────────
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, il.Client_name, il.Order_date, il.Qty, il.Unit_price,
               il.sale_type, il.billing_month_override,
               COALESCE(ist.exclude_when_nonzero, 0) AS excl_nonzero
        FROM is_list il
        LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
        WHERE il.Order_date BETWEEN ? AND ?");
    $st->execute([$scanFrom, $scanTo]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;                       // 0 元列不構成應收
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;

        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) {
            $bm = acc_billing_month($r['Order_date'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($bm < $bmFrom || $bm > $bmTo) continue;

        $bad = ((int)$r['excl_nonzero'] === 1);
        $addRow($name, $bm, ['ship_cnt' => 1, 'ship_amt' => $amt, 'anomaly' => $bad ? 1 : 0]);
        if ($bad) {
            $anomaly[] = ['is_number' => $r['IS_number'], 'client' => $name, 'date' => $r['Order_date'],
                          'amount' => $amt, 'reason' => '此出貨性質應為 0 元卻有金額，請確認'];
        }
    }

    // ── 退貨（從應收扣除；備註性質不計金額）─────────────────────────────
    $st2 = $db->prepare("
        SELECT it.IR_id, it.IR_no, it.Client_name, it.IR_date, it.Qty, it.Unit_price,
               it.billing_month_override, COALESCE(rt.is_note, 0) AS is_note
        FROM ir_track it
        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
        WHERE it.IR_date BETWEEN ? AND ?");
    $st2->execute([$scanFrom, $scanTo]);

    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;

        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) {
            $bm = acc_billing_month($r['IR_date'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($bm < $bmFrom || $bm > $bmTo) continue;

        $addRow($name, $bm, ['ret_cnt' => 1, 'ret_amt' => $amt]);
    }

    // ── 收斂成清單 ──────────────────────────────────────────────────────
    $rows = [];
    foreach ($bucket as $b) {
        $net = $b['ship_amt'] - $b['ret_amt'];
        $b['net_amt']   = $net;
        $b['tax_amt']   = round($net * $rate);
        $b['total_amt'] = $net + $b['tax_amt'];
        $b['inv_ready'] = acc_invoice_ready($b);            // 能不能開發票
        $rows[] = $b;
    }

    // 篩選
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $rows = array_values(array_filter($rows, fn($r) =>
            mb_stripos((string)$r['customer'], $kw) !== false
            || mb_stripos((string)$r['customer_full'], $kw) !== false
            || mb_stripos((string)$r['customer_id'], $kw) !== false));
    }
    if (!empty($f['customer_id'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['customer_id'] === $f['customer_id']));
    }
    if (!empty($f['only_gap'])) {                            // 只看不能開發票的
        $rows = array_values(array_filter($rows, fn($r) => $r['inv_ready'] !== 'ok'));
    }

    // 全部符合條件才算合計
    $summary = ['groups' => count($rows), 'ship_amt' => 0.0, 'ret_amt' => 0.0,
                'net_amt' => 0.0, 'tax_amt' => 0.0, 'total_amt' => 0.0,
                'not_ready' => 0, 'not_in_master' => 0, 'anomaly' => count($anomaly)];
    foreach ($rows as $r) {
        $summary['ship_amt']  += $r['ship_amt'];
        $summary['ret_amt']   += $r['ret_amt'];
        $summary['net_amt']   += $r['net_amt'];
        $summary['tax_amt']   += $r['tax_amt'];
        $summary['total_amt'] += $r['total_amt'];
        if ($r['inv_ready'] !== 'ok') $summary['not_ready']++;
        if (!$r['in_master'])         $summary['not_in_master']++;
    }

    // 排序：預設應收金額由大到小
    $sort = $f['sort'] ?? 'net_amt';
    $dir  = (($f['dir'] ?? 'desc') === 'asc') ? 1 : -1;
    usort($rows, function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'customer':      return $dir * strnatcasecmp($a['customer'], $b['customer']);
            case 'billing_month': return $dir * strcmp($a['billing_month'], $b['billing_month']);
            case 'ship_amt':      return $dir * ($a['ship_amt'] <=> $b['ship_amt']);
            case 'total_amt':     return $dir * ($a['total_amt'] <=> $b['total_amt']);
            default:              return $dir * ($a['net_amt'] <=> $b['net_amt']);
        }
    });

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) {
        return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0,
                'summary' => $summary, 'anomaly' => $anomaly, 'tax_rate' => $rate,
                'bm_from' => $bmFrom, 'bm_to' => $bmTo];
    }
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));

    return [
        'rows'     => array_slice($rows, ($page - 1) * $perPage, $perPage),
        'total'    => $total, 'page' => $page, 'per_page' => $perPage,
        'summary'  => $summary, 'anomaly' => $anomaly, 'tax_rate' => $rate,
        'bm_from'  => $bmFrom, 'bm_to' => $bmTo,
    ];
}

/** 單一客戶單一帳款月份的明細（對帳單內容） */
function acc_ar_detail(PDO $db, string $clientName, string $billingMonth): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $billingMonth)) return ['items' => [], 'head' => null];
    [$scanFrom, $scanTo] = acc_scan_range($billingMonth, $billingMonth);
    $global = acc_global_cutoff($db);
    $rate   = acc_tax_rate($db);

    $stc = $db->prepare("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                                customer_address, billing_contact, settlement_mode, settlement_day,
                                payment_method, net_days
                         FROM customer_list WHERE customer = ? LIMIT 1");
    $stc->execute([$clientName]);
    $c = $stc->fetch(PDO::FETCH_ASSOC) ?: null;
    $cut = acc_cutoff_for($c, $global);

    $items = [];

    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS d,
               il.Product_id, il.Specification, il.Qty, il.Unit_price, il.Note,
               il.billing_month_override, ot.Order_oo,
               COALESCE(ist.sale_type_name,'') AS sale_type_name
        FROM is_list il
        LEFT JOIN order_track  ot  ON ot.Order_id = il.Order_id
        LEFT JOIN is_sale_type ist ON ist.sale_type_id = il.sale_type
        WHERE il.Client_name = ? AND il.Order_date BETWEEN ? AND ?
        ORDER BY il.Order_date, il.IS_number, il.IS_id");
    $st->execute([$clientName, $scanFrom, $scanTo]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) $bm = acc_billing_month($r['d'], $cut);
        if ($bm !== $billingMonth) continue;
        $items[] = ['kind' => 'ship', 'src_type' => 'IS', 'src_id' => (int)$r['IS_id'],
                    'no' => $r['IS_number'], 'date' => $r['d'],
                    'order_oo' => $r['Order_oo'], 'product_id' => $r['Product_id'],
                    'spec' => $r['Specification'], 'qty' => (int)$r['Qty'],
                    'unit_price' => (float)$r['Unit_price'], 'amount' => $amt,
                    'note' => $r['Note'], 'sale_type' => $r['sale_type_name']];
    }

    $st2 = $db->prepare("
        SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d, it.d_id, it.Specification,
               it.Qty, it.Unit_price, it.IR_ps, it.billing_month_override,
               COALESCE(rt.is_note,0) AS is_note
        FROM ir_track it
        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
        WHERE it.Client_name = ? AND it.IR_date BETWEEN ? AND ?
        ORDER BY it.IR_date, it.IR_no");
    $st2->execute([$clientName, $scanFrom, $scanTo]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $bm = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $bm)) $bm = acc_billing_month($r['d'], $cut);
        if ($bm !== $billingMonth) continue;
        $items[] = ['kind' => 'return', 'src_type' => 'IR', 'src_id' => (int)$r['IR_id'],
                    'no' => $r['IR_no'], 'date' => $r['d'],
                    'order_oo' => null, 'product_id' => $r['d_id'], 'spec' => $r['Specification'],
                    'qty' => -(int)$r['Qty'], 'unit_price' => (float)$r['Unit_price'],
                    'amount' => -$amt, 'note' => $r['IR_ps'], 'sale_type' => '退貨'];
    }

    usort($items, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp((string)$a['no'], (string)$b['no']));

    $net = 0.0;
    foreach ($items as $i) $net += $i['amount'];
    $tax = round($net * $rate);

    return [
        'head' => [
            'customer'       => $clientName,
            'customer_id'    => $c['customer_id']   ?? null,
            'customer_full'  => $c['customer_full'] ?? null,
            'tax_id'         => $c['tax_id']        ?? null,
            'address'        => $c['customer_address'] ?? null,
            'contact'        => $c['billing_contact']  ?? null,
            'payment_method' => $c['payment_method']   ?? null,
            'net_days'       => $c['net_days']         ?? null,
            'cutoff'         => $cut,
            'billing_month'  => $billingMonth,
            'in_master'      => (bool)$c,
            'inv_ready'      => $c ? acc_invoice_ready($c) : 'no_tax',
        ],
        'items'     => $items,
        'net_amt'   => $net,
        'tax_amt'   => $tax,
        'total_amt' => $net + $tax,
        'tax_rate'  => $rate,
    ];
}

/* ============================================================
 * 發票開立（本系統不連電子發票，只產生「待開立清單」供外部平台開立，
 * 再把實際開出的發票號碼與日期回填。）
 * ============================================================ */

/**
 * 已被「未作廢的發票或折讓單」佔用的來源憑證：'IS-123' => 佔用資訊。
 * 必須同時涵蓋 INVOICE 與 ALLOWANCE——折讓單一樣會消耗退貨憑證，
 * 只算 INVOICE 會讓同一張退貨可被重複折讓，最後才被資料庫唯一鍵擋下，
 * 使用者看到的是原始 SQL 錯誤而不是看得懂的訊息。
 */
function acc_invoiced_src_map(PDO $db): array
{
    $st = $db->query("SELECT ii.src_type, ii.src_id, ii.invoice_id, i.status, i.invoice_no, i.doc_type
                      FROM acc_invoice_item ii
                      JOIN acc_invoice i ON i.invoice_id = ii.invoice_id
                      WHERE i.status <> 'void'");
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m[$r['src_type'] . '-' . (int)$r['src_id']] = [
            'invoice_id' => (int)$r['invoice_id'],
            'status'     => $r['status'],
            'invoice_no' => $r['invoice_no'],
            'doc_type'   => $r['doc_type'],
        ];
    }
    return $m;
}

/**
 * 取某帳款月份「尚未開發票」的憑證，依客戶分組（＝待開立清單）。
 * 作廢的發票會把其憑證釋放回來，可重新開立。
 *
 * @return array ['groups'=>[...], 'summary'=>[...]]
 */
function acc_invoice_candidates(PDO $db, string $bm, array $f = []): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $bm)) return ['groups' => [], 'summary' => []];
    [$scanFrom, $scanTo] = acc_scan_range($bm, $bm);
    $global = acc_global_cutoff($db);
    $rate   = acc_tax_rate($db);
    $used   = acc_invoiced_src_map($db);

    $cust = [];
    foreach ($db->query("SELECT customer_id, customer, customer_full, tax_id, invoice_email,
                                settlement_mode, settlement_day
                         FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (trim((string)$c['customer']) !== '') $cust[trim($c['customer'])] = $c;
    }

    $groups = [];
    $touch = function (string $name) use (&$groups, $cust, $bm) {
        if (isset($groups[$name])) return;
        $c = $cust[$name] ?? null;
        $groups[$name] = [
            'customer'      => $name,
            'customer_id'   => $c['customer_id']   ?? null,
            'customer_full' => $c['customer_full'] ?? null,
            'tax_id'        => $c['tax_id']        ?? null,
            'invoice_email' => $c['invoice_email'] ?? null,
            'in_master'     => (bool)$c,
            'billing_month' => $bm,
            'items'         => [],
            'net_amt'       => 0.0,
        ];
        $groups[$name]['inv_ready'] = $c ? acc_invoice_ready($c) : 'no_tax';
    };

    // 出貨
    $st = $db->prepare("
        SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS d,
               il.Client_name, il.Product_id, il.Specification, il.Qty, il.Unit_price,
               il.billing_month_override
        FROM is_list il
        WHERE il.Order_date BETWEEN ? AND ?
        ORDER BY il.Order_date, il.IS_number, il.IS_id");
    $st->execute([$scanFrom, $scanTo]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;
        $b = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $b)) {
            $b = acc_billing_month($r['d'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($b !== $bm) continue;
        if (isset($used['IS-' . (int)$r['IS_id']])) continue;      // 已開過發票

        $touch($name);
        $groups[$name]['items'][] = [
            'src_type' => 'IS', 'src_id' => (int)$r['IS_id'], 'src_no' => $r['IS_number'],
            'src_date' => $r['d'], 'product_id' => $r['Product_id'],
            'spec' => $r['Specification'], 'qty' => (int)$r['Qty'],
            'unit_price' => (float)$r['Unit_price'], 'amount' => $amt,
        ];
        $groups[$name]['net_amt'] += $amt;
    }

    // 退貨（同期退貨直接在發票中扣除，就不需要另開折讓單）
    $st2 = $db->prepare("
        SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d, it.Client_name,
               it.d_id, it.Specification, it.Qty, it.Unit_price, it.billing_month_override,
               COALESCE(rt.is_note,0) AS is_note
        FROM ir_track it
        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
        WHERE it.IR_date BETWEEN ? AND ?
        ORDER BY it.IR_date, it.IR_no");
    $st2->execute([$scanFrom, $scanTo]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $name = trim((string)$r['Client_name']);
        if ($name === '') continue;
        $b = trim((string)$r['billing_month_override']);
        if (!preg_match('/^\d{4}-\d{2}$/', $b)) {
            $b = acc_billing_month($r['d'], acc_cutoff_for($cust[$name] ?? null, $global));
        }
        if ($b !== $bm) continue;
        if (isset($used['IR-' . (int)$r['IR_id']])) continue;

        $touch($name);
        $groups[$name]['items'][] = [
            'src_type' => 'IR', 'src_id' => (int)$r['IR_id'], 'src_no' => $r['IR_no'],
            'src_date' => $r['d'], 'product_id' => $r['d_id'],
            'spec' => $r['Specification'], 'qty' => -(int)$r['Qty'],
            'unit_price' => (float)$r['Unit_price'], 'amount' => -$amt,
        ];
        $groups[$name]['net_amt'] -= $amt;
    }

    // 稅與可開立判定
    $out = [];
    foreach ($groups as $g) {
        if (!$g['items']) continue;
        $g['tax_amt']   = round($g['net_amt'] * $rate);
        $g['total_amt'] = $g['net_amt'] + $g['tax_amt'];
        $g['item_cnt']  = count($g['items']);
        $g['can_issue'] = ($g['inv_ready'] === 'ok' && $g['net_amt'] > 0);
        $out[] = $g;
    }
    usort($out, fn($a, $b) => $b['net_amt'] <=> $a['net_amt']);

    $summary = ['groups' => count($out), 'can_issue' => 0, 'blocked' => 0,
                'net_amt' => 0.0, 'tax_amt' => 0.0, 'total_amt' => 0.0];
    foreach ($out as $g) {
        $summary['net_amt']   += $g['net_amt'];
        $summary['tax_amt']   += $g['tax_amt'];
        $summary['total_amt'] += $g['total_amt'];
        if ($g['can_issue']) $summary['can_issue']++; else $summary['blocked']++;
    }
    return ['groups' => $out, 'summary' => $summary, 'tax_rate' => $rate, 'billing_month' => $bm];
}

/**
 * 建立發票（狀態 draft）。預設一客戶一帳款月份一張；
 * 若 $splitBySrc 為 true 則每張來源出貨單各開一張（使用者可自選）。
 *
 * @param array $sel [{customer, billing_month, src:[{src_type,src_id}...]（可省略＝全取）}]
 */
function acc_create_invoices(PDO $db, array $sel, string $userId, bool $splitBySrc = false): array
{
    acc_ensure_schema($db);
    $made = []; $errors = [];

    try {
        $db->beginTransaction();
        $insInv = $db->prepare(
            "INSERT INTO acc_invoice
             (doc_type, customer_id, customer_name, customer_full, tax_id, billing_month,
              tax_type, tax_rate, sales_amount, tax_amount, total_amount, status, Created_By)
             VALUES ('INVOICE',?,?,?,?,?,1,?,?,?,?,'draft',?)");
        $insItem = $db->prepare(
            "INSERT INTO acc_invoice_item
             (invoice_id, src_type, src_id, src_no, src_date, product_id, spec, qty, unit_price, amount)
             VALUES (?,?,?,?,?,?,?,?,?,?)");

        foreach ($sel as $s) {
            $bm   = trim((string)($s['billing_month'] ?? ''));
            $name = trim((string)($s['customer'] ?? ''));
            if ($name === '' || !preg_match('/^\d{4}-\d{2}$/', $bm)) { $errors[] = "資料不完整，略過"; continue; }

            // 一律以資料庫當下狀態重算可開立項目，不信前端傳來的金額
            $cand = acc_invoice_candidates($db, $bm);
            $grp  = null;
            foreach ($cand['groups'] as $g) if ($g['customer'] === $name) { $grp = $g; break; }
            if (!$grp) { $errors[] = "{$name} {$bm}：已無可開立的憑證"; continue; }

            // 若前端指定了憑證子集，就只取那些
            if (!empty($s['src']) && is_array($s['src'])) {
                $want = [];
                foreach ($s['src'] as $x) $want[$x['src_type'] . '-' . (int)$x['src_id']] = 1;
                $grp['items'] = array_values(array_filter($grp['items'],
                    fn($it) => isset($want[$it['src_type'] . '-' . $it['src_id']])));
            }
            if (!$grp['items']) { $errors[] = "{$name} {$bm}：沒有選到憑證"; continue; }
            if ($grp['inv_ready'] !== 'ok') {
                $errors[] = "{$name}：發票資料不完整（" . $grp['inv_ready'] . "），無法開立";
                continue;
            }

            $batches = $splitBySrc ? array_map(fn($it) => [$it], $grp['items']) : [$grp['items']];
            foreach ($batches as $items) {
                $net = 0.0;
                foreach ($items as $it) $net += $it['amount'];
                if ($net <= 0) { $errors[] = "{$name} {$bm}：金額為 " . round($net) . "，不開立發票（請用折讓單處理）"; continue; }
                $tax = round($net * $cand['tax_rate']);

                $insInv->execute([$grp['customer_id'], $name, $grp['customer_full'], $grp['tax_id'],
                                  $bm, $cand['tax_rate'], $net, $tax, $net + $tax, $userId]);
                $invId = (int)$db->lastInsertId();
                foreach ($items as $it) {
                    $insItem->execute([$invId, $it['src_type'], $it['src_id'], $it['src_no'],
                                       $it['src_date'], $it['product_id'], $it['spec'],
                                       $it['qty'], $it['unit_price'], $it['amount']]);
                }
                $made[] = ['invoice_id' => $invId, 'customer' => $name, 'billing_month' => $bm,
                           'net' => $net, 'tax' => $tax, 'total' => $net + $tax,
                           'item_cnt' => count($items)];
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立發票失敗：' . $e->getMessage()];
    }

    $t = 0.0;
    foreach ($made as $m) $t += $m['total'];
    return ['success' => true, 'created' => count($made), 'invoices' => $made, 'errors' => $errors,
            'message' => '已建立 ' . count($made) . ' 張發票，含稅合計 ' . number_format($t)];
}

/** 發票清單 */
function acc_invoice_list(PDO $db, array $f): array
{
    acc_ensure_schema($db);
    $where = []; $params = [];

    $bmFrom = trim((string)($f['bm_from'] ?? ''));
    $bmTo   = trim((string)($f['bm_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}$/', $bmFrom)) { $where[] = "i.billing_month >= ?"; $params[] = $bmFrom; }
    if (preg_match('/^\d{4}-\d{2}$/', $bmTo))   { $where[] = "i.billing_month <= ?"; $params[] = $bmTo; }

    $status = trim((string)($f['status'] ?? ''));
    if ($status !== '' && $status !== 'all') { $where[] = "i.status = ?"; $params[] = $status; }
    $docType = trim((string)($f['doc_type'] ?? ''));
    if ($docType !== '' && $docType !== 'all') { $where[] = "i.doc_type = ?"; $params[] = $docType; }

    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(i.customer_name LIKE ? OR i.customer_full LIKE ? OR i.invoice_no LIKE ?
                     OR i.tax_id LIKE ? OR i.customer_id LIKE ?)";
        for ($k = 0; $k < 5; $k++) $params[] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT i.*,
                   (SELECT COUNT(*) FROM acc_invoice_item x WHERE x.invoice_id = i.invoice_id) AS item_cnt,
                   COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                             WHERE a.invoice_id = i.invoice_id), 0) AS paid_amt
            FROM acc_invoice i
            $ws
            ORDER BY i.billing_month DESC, i.customer_name, i.invoice_id DESC";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $today = new DateTime(date('Y-m-d'));
    foreach ($rows as &$r) {
        $r['sales_amount'] = (float)$r['sales_amount'];
        $r['tax_amount']   = (float)$r['tax_amount'];
        $r['total_amount'] = (float)$r['total_amount'];
        $r['paid_amt']     = (float)$r['paid_amt'];
        $r['open_amt']     = round($r['total_amount'] - $r['paid_amt'], 2);
        $r['item_cnt']     = (int)$r['item_cnt'];
        // 帳齡：以發票日起算；未開立者不算帳齡
        $r['age_days'] = null;
        if (!empty($r['invoice_date']) && $r['status'] === 'issued' && $r['open_amt'] > 0.005) {
            $r['age_days'] = (int)$today->diff(new DateTime($r['invoice_date']))->days;
        }
        $r['age_bucket'] = ($r['age_days'] === null) ? ''
            : ($r['age_days'] <= 30 ? '0-30' : ($r['age_days'] <= 60 ? '31-60'
              : ($r['age_days'] <= 90 ? '61-90' : '90+')));
    }
    unset($r);

    if (!empty($f['only_open'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['open_amt'] > 0.005 && $r['status'] === 'issued'));
    }

    $summary = ['count' => count($rows), 'total_amt' => 0.0, 'paid_amt' => 0.0, 'open_amt' => 0.0,
                'draft' => 0, 'exported' => 0, 'issued' => 0, 'void' => 0,
                'age' => ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0]];
    foreach ($rows as $r) {
        if ($r['status'] === 'void') { $summary['void']++; continue; }
        $sign = ($r['doc_type'] === 'ALLOWANCE') ? -1 : 1;
        $summary['total_amt'] += $r['total_amount'] * $sign;
        $summary['paid_amt']  += $r['paid_amt'];
        $summary['open_amt']  += $r['open_amt'] * $sign;
        if (isset($summary[$r['status']])) $summary[$r['status']]++;
        if ($r['age_bucket'] !== '') $summary['age'][$r['age_bucket']] += $r['open_amt'];
    }

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));

    return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'summary' => $summary];
}

/** 發票明細 */
function acc_invoice_items(PDO $db, int $invoiceId): array
{
    $st = $db->prepare("SELECT * FROM acc_invoice_item WHERE invoice_id = ? ORDER BY src_date, item_id");
    $st->execute([$invoiceId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 標記為已轉出（給外部平台開立），回傳批次編號 */
function acc_invoice_mark_exported(PDO $db, array $ids, string $userId): array
{
    $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
    if (!$ids) return ['success' => false, 'message' => '沒有選取發票'];
    $batch = 'EXP' . date('YmdHis');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $db->beginTransaction();
        $st = $db->prepare("UPDATE acc_invoice
                            SET status='exported', export_batch=?, exported_at=NOW(),
                                Modified_By=?, Modified_At=NOW()
                            WHERE invoice_id IN ($ph) AND status='draft'");
        $st->execute(array_merge([$batch, $userId], $ids));
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'batch' => $batch, 'updated' => $n,
                'message' => "已標記 {$n} 張為待開立（批次 {$batch}）"];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '標記失敗：' . $e->getMessage()];
    }
}

/**
 * 回填實際開立的發票號碼與日期（手動逐張或 CSV 批次共用同一支）。
 * @param array $pairs [{invoice_id 或 match_key, invoice_no, invoice_date}]
 */
function acc_invoice_backfill(PDO $db, array $pairs, string $userId): array
{
    $ok = 0; $skip = 0; $errors = [];
    try {
        $db->beginTransaction();
        $up = $db->prepare("UPDATE acc_invoice
                            SET invoice_no=?, invoice_date=?, status='issued',
                                Modified_By=?, Modified_At=NOW()
                            WHERE invoice_id=? AND status IN ('draft','exported')");
        foreach ($pairs as $p) {
            $id = (int)($p['invoice_id'] ?? 0);
            $no = strtoupper(trim((string)($p['invoice_no'] ?? '')));
            $dt = trim((string)($p['invoice_date'] ?? ''));
            if ($id <= 0 || $no === '') { $skip++; continue; }
            // 台灣電子發票號碼格式：2 英文字母 + 8 數字
            if (!preg_match('/^[A-Z]{2}\d{8}$/', $no)) {
                $errors[] = "發票號碼 {$no} 格式不正確（應為 2 個英文字母＋8 位數字），已略過";
                $skip++; continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                $errors[] = "發票 {$no} 的開立日期格式不正確，已略過";
                $skip++; continue;
            }
            try {
                $up->execute([$no, $dt, $userId, $id]);
                if ($up->rowCount() > 0) $ok++; else $skip++;
            } catch (PDOException $e) {
                // uq_inv_no 撞號
                $errors[] = "發票號碼 {$no} 已存在於系統，已略過";
                $skip++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '回填失敗：' . $e->getMessage()];
    }
    return ['success' => true, 'applied' => $ok, 'skipped' => $skip, 'errors' => $errors,
            'message' => "已回填 {$ok} 張發票號碼" . ($skip ? "，略過 {$skip} 張" : '')];
}

/** 作廢發票（憑證會釋放回待開立清單；已沖帳者不可作廢） */
function acc_invoice_void(PDO $db, int $invoiceId, string $reason, string $userId): array
{
    if ($invoiceId <= 0) return ['success' => false, 'message' => '缺少發票'];
    $paid = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM acc_receipt_alloc
                               WHERE invoice_id = " . (int)$invoiceId)->fetchColumn();
    if ($paid > 0.005) {
        return ['success' => false, 'message' => '此發票已有收款沖帳紀錄（' . number_format($paid)
                . ' 元），請先取消沖帳再作廢'];
    }
    try {
        $db->beginTransaction();
        $st = $db->prepare("UPDATE acc_invoice SET status='void', void_reason=?,
                            Modified_By=?, Modified_At=NOW()
                            WHERE invoice_id=? AND status<>'void'");
        $st->execute([mb_substr($reason, 0, 200), $userId, $invoiceId]);
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'updated' => $n,
                'message' => $n ? '已作廢，該發票的出貨憑證已釋放回待開立清單' : '此發票已是作廢狀態'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '作廢失敗：' . $e->getMessage()];
    }
}

/**
 * 建立銷貨折讓證明單：處理「發票已開立之後才發生的退貨」。
 * 同期退貨會直接在發票中扣除，不需要折讓單；只有跨期（發票已開）才需要。
 *
 * @param int   $refInvoiceId 原發票
 * @param array $irIds        要折讓的 ir_track.IR_id
 */
function acc_create_allowance(PDO $db, int $refInvoiceId, array $irIds, string $userId): array
{
    acc_ensure_schema($db);
    $irIds = array_values(array_unique(array_map('intval', array_filter($irIds))));
    if ($refInvoiceId <= 0 || !$irIds) return ['success' => false, 'message' => '缺少原發票或退貨明細'];

    $st = $db->prepare("SELECT * FROM acc_invoice WHERE invoice_id=? AND doc_type='INVOICE' LIMIT 1");
    $st->execute([$refInvoiceId]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv)                       return ['success' => false, 'message' => '找不到原發票'];
    if ($inv['status'] === 'void')   return ['success' => false, 'message' => '原發票已作廢，不可開折讓單'];
    if ($inv['status'] !== 'issued') return ['success' => false, 'message' => '原發票尚未開立（狀態 ' . $inv['status'] . '），同期退貨請直接在發票中扣除'];

    $used = acc_invoiced_src_map($db);
    $ph   = implode(',', array_fill(0, count($irIds), '?'));
    $sti  = $db->prepare("SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d,
                                 it.Client_name, it.d_id, it.Specification, it.Qty, it.Unit_price
                          FROM ir_track it WHERE it.IR_id IN ($ph)");
    $sti->execute($irIds);
    $irs = $sti->fetchAll(PDO::FETCH_ASSOC);
    if (!$irs) return ['success' => false, 'message' => '找不到退貨資料'];

    $items = []; $net = 0.0; $errors = [];
    foreach ($irs as $r) {
        if (trim((string)$r['Client_name']) !== trim((string)$inv['customer_name'])) {
            $errors[] = "退貨單 {$r['IR_no']} 的客戶與原發票不符，已略過"; continue;
        }
        if (isset($used['IR-' . (int)$r['IR_id']])) {
            $u = $used['IR-' . (int)$r['IR_id']];
            $what = ($u['doc_type'] === 'ALLOWANCE') ? '折讓單' : '發票';
            $errors[] = "退貨單 {$r['IR_no']} 已折讓或已入帳（{$what} "
                      . ($u['invoice_no'] ?: '#' . $u['invoice_id']) . "），已略過";
            continue;
        }
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) { $errors[] = "退貨單 {$r['IR_no']} 金額為 0，已略過"; continue; }
        $items[] = ['src_id' => (int)$r['IR_id'], 'src_no' => $r['IR_no'], 'src_date' => $r['d'],
                    'product_id' => $r['d_id'], 'spec' => $r['Specification'],
                    'qty' => -(int)$r['Qty'], 'unit_price' => (float)$r['Unit_price'], 'amount' => $amt];
        $net += $amt;
    }
    if (!$items) return ['success' => false, 'message' => '沒有可折讓的退貨明細', 'errors' => $errors];

    $rate = (float)$inv['tax_rate'];
    $tax  = round($net * $rate);
    try {
        $db->beginTransaction();
        $ins = $db->prepare(
            "INSERT INTO acc_invoice
             (doc_type, customer_id, customer_name, customer_full, tax_id, billing_month,
              tax_type, tax_rate, sales_amount, tax_amount, total_amount, status,
              ref_invoice_id, note, Created_By)
             VALUES ('ALLOWANCE',?,?,?,?,?,?,?,?,?,?,'draft',?,?,?)");
        $ins->execute([$inv['customer_id'], $inv['customer_name'], $inv['customer_full'], $inv['tax_id'],
                       date('Y-m'), (int)$inv['tax_type'], $rate, $net, $tax, $net + $tax,
                       $refInvoiceId, '對應原發票 ' . ($inv['invoice_no'] ?: $refInvoiceId), $userId]);
        $aid = (int)$db->lastInsertId();
        $insItem = $db->prepare(
            "INSERT INTO acc_invoice_item
             (invoice_id, src_type, src_id, src_no, src_date, product_id, spec, qty, unit_price, amount)
             VALUES (?,'IR',?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $insItem->execute([$aid, $it['src_id'], $it['src_no'], $it['src_date'],
                               $it['product_id'], $it['spec'], $it['qty'], $it['unit_price'], $it['amount']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '建立折讓單失敗：' . $e->getMessage()];
    }

    return ['success' => true, 'allowance_id' => $aid, 'net' => $net, 'tax' => $tax,
            'total' => $net + $tax, 'item_cnt' => count($items), 'errors' => $errors,
            'message' => '已建立折讓單，折讓未稅 ' . number_format($net) . '、含稅 ' . number_format($net + $tax)];
}

/** 某客戶已開立、仍可開折讓的發票（供折讓單選原發票用） */
function acc_allowance_targets(PDO $db, string $clientName): array
{
    acc_ensure_schema($db);
    $st = $db->prepare("SELECT invoice_id, invoice_no, invoice_date, billing_month,
                               sales_amount, tax_amount, total_amount
                        FROM acc_invoice
                        WHERE doc_type='INVOICE' AND status='issued' AND customer_name=?
                        ORDER BY invoice_date DESC, invoice_id DESC LIMIT 100");
    $st->execute([$clientName]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 某客戶尚未被任何發票／折讓單使用的退貨（供折讓單選明細用） */
function acc_uninvoiced_returns(PDO $db, string $clientName): array
{
    $used = acc_invoiced_src_map($db);
    // 折讓單本身也會佔用 IR，需一併排除
    $st0 = $db->query("SELECT ii.src_id FROM acc_invoice_item ii
                       JOIN acc_invoice i ON i.invoice_id = ii.invoice_id
                       WHERE ii.src_type='IR' AND i.status <> 'void'");
    foreach ($st0->fetchAll(PDO::FETCH_COLUMN) as $sid) $used['IR-' . (int)$sid] = ['x' => 1];

    $st = $db->prepare("SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d,
                               it.d_id, it.Specification, it.Qty, it.Unit_price, it.IR_ps,
                               COALESCE(rt.is_note,0) AS is_note
                        FROM ir_track it
                        LEFT JOIN ir_return_type rt ON rt.type_id = it.return_type_id
                        WHERE it.Client_name=? ORDER BY it.IR_date DESC LIMIT 300");
    $st->execute([$clientName]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['is_note'] === 1) continue;
        if (isset($used['IR-' . (int)$r['IR_id']])) continue;
        $amt = (float)$r['Qty'] * (float)$r['Unit_price'];
        if (abs($amt) < 0.0001) continue;
        $r['amount'] = $amt;
        $out[] = $r;
    }
    return $out;
}

/* ============================================================
 * 收款與沖帳
 *
 * 彈性設計（使用者指定）：一筆收款可沖多張發票、一張發票可被多筆收款分次沖，
 * 支援部分收款與尾款。折讓單以負數參與未收餘額計算（等於減少應收）。
 * ============================================================ */

/** 收款單號：RC + 民國年(3) + MMDD + 序號(3) */
function acc_receipt_next_no(PDO $db, string $date): string
{
    $y      = (int)substr($date, 0, 4) - 1911;
    $prefix = 'RC' . str_pad((string)$y, 3, '0', STR_PAD_LEFT) . substr($date, 5, 2) . substr($date, 8, 2);
    $st = $db->prepare("SELECT MAX(CAST(SUBSTRING(receipt_no, 10) AS UNSIGNED)) FROM acc_receipt WHERE receipt_no LIKE ?");
    $st->execute([$prefix . '%']);
    return $prefix . str_pad((string)((int)$st->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
}

/** 某張發票目前的未收餘額（扣掉所有收款單已沖金額） */
function acc_invoice_open(PDO $db, int $invoiceId): array
{
    $st = $db->prepare("SELECT i.invoice_id, i.invoice_no, i.doc_type, i.status, i.customer_name,
                               i.billing_month, i.total_amount,
                               COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                                         WHERE a.invoice_id = i.invoice_id), 0) AS paid
                        FROM acc_invoice i WHERE i.invoice_id = ? LIMIT 1");
    $st->execute([$invoiceId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return [];
    $r['total_amount'] = (float)$r['total_amount'];
    $r['paid']         = (float)$r['paid'];
    $r['open']         = round($r['total_amount'] - $r['paid'], 2);
    return $r;
}

/** 某客戶尚未收完的已開立發票（供沖帳挑選；折讓單一併列出，金額為負） */
function acc_open_invoices(PDO $db, string $clientName, ?int $includeReceiptId = null): array
{
    acc_ensure_schema($db);
    $st = $db->prepare("
        SELECT i.invoice_id, i.doc_type, i.invoice_no, i.invoice_date, i.billing_month,
               i.total_amount, i.customer_name,
               COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                         WHERE a.invoice_id = i.invoice_id), 0) AS paid,
               COALESCE((SELECT SUM(a2.amount) FROM acc_receipt_alloc a2
                         WHERE a2.invoice_id = i.invoice_id AND a2.receipt_id = ?), 0) AS this_paid
        FROM acc_invoice i
        WHERE i.customer_name = ? AND i.status = 'issued'
        ORDER BY i.invoice_date, i.invoice_id");
    $st->execute([$includeReceiptId ?? 0, $clientName]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sign  = ($r['doc_type'] === 'ALLOWANCE') ? -1 : 1;
        $total = (float)$r['total_amount'] * $sign;
        $paid  = (float)$r['paid'];
        $open  = round($total - $paid, 2);
        // 編輯中的收款單：把它自己已沖的金額算回可沖額度，否則會沖不回去
        $r['total_amount'] = $total;
        $r['paid']         = $paid;
        $r['this_paid']    = (float)$r['this_paid'];
        $r['open']         = $open;
        $r['available']    = round($open + $r['this_paid'], 2);
        if (abs($r['available']) < 0.005) continue;
        $out[] = $r;
    }
    return $out;
}

/** 建立或更新收款單（不含沖帳明細，沖帳走 acc_alloc_save） */
function acc_receipt_save(PDO $db, array $d, string $userId): array
{
    acc_ensure_schema($db);
    $id   = (int)($d['receipt_id'] ?? 0);
    $name = trim((string)($d['customer_name'] ?? ''));
    $date = trim((string)($d['receipt_date'] ?? ''));
    $amt  = (float)($d['amount'] ?? 0);

    if ($name === '')                                  return ['success' => false, 'message' => '請填客戶'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))   return ['success' => false, 'message' => '入帳日格式錯誤'];
    if ($amt <= 0)                                     return ['success' => false, 'message' => '收款金額必須大於 0'];

    $cid = trim((string)($d['customer_id'] ?? ''));
    if ($cid === '') {
        $stc = $db->prepare("SELECT customer_id FROM customer_list WHERE customer = ? LIMIT 1");
        $stc->execute([$name]);
        $cid = (string)($stc->fetchColumn() ?: '');
    }

    $method  = trim((string)($d['method'] ?? '匯款'));
    $fee     = (float)($d['fee'] ?? 0);
    $bank    = trim((string)($d['bank'] ?? ''));
    $ckNo    = trim((string)($d['check_no'] ?? ''));
    $ckDue   = trim((string)($d['check_due'] ?? ''));
    $note    = trim((string)($d['note'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ckDue)) $ckDue = null;

    try {
        $db->beginTransaction();
        if ($id > 0) {
            // 縮小金額時不可小於已沖總額
            $alloc = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM acc_receipt_alloc
                                        WHERE receipt_id = " . (int)$id)->fetchColumn();
            if ($amt < $alloc - 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '收款金額 ' . number_format($amt)
                        . ' 小於已沖帳金額 ' . number_format($alloc) . '，請先調整沖帳明細'];
            }
            $st = $db->prepare("UPDATE acc_receipt SET customer_id=?, customer_name=?, receipt_date=?,
                                method=?, amount=?, fee=?, bank=?, check_no=?, check_due=?, note=?,
                                Modified_By=?, Modified_At=NOW() WHERE receipt_id=?");
            $st->execute([$cid ?: null, $name, $date, $method, $amt, $fee, $bank ?: null,
                          $ckNo ?: null, $ckDue, $note ?: null, $userId, $id]);
            $db->commit();
            return ['success' => true, 'receipt_id' => $id, 'message' => '已更新收款單'];
        }

        $no = acc_receipt_next_no($db, $date);
        $st = $db->prepare("INSERT INTO acc_receipt
                            (receipt_no, customer_id, customer_name, receipt_date, method, amount,
                             fee, bank, check_no, check_due, note, Created_By)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$no, $cid ?: null, $name, $date, $method, $amt, $fee, $bank ?: null,
                      $ckNo ?: null, $ckDue, $note ?: null, $userId]);
        $newId = (int)$db->lastInsertId();
        $db->commit();
        return ['success' => true, 'receipt_id' => $newId, 'receipt_no' => $no,
                'message' => '已建立收款單 ' . $no];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '存檔失敗：' . $e->getMessage()];
    }
}

/**
 * 寫入沖帳明細（整批取代該收款單原有的沖帳）。
 * 三道檢查：沖帳總額 ≤ 收款金額、每張發票不可超沖、發票客戶需與收款單一致。
 */
function acc_alloc_save(PDO $db, int $receiptId, array $allocs, string $userId): array
{
    acc_ensure_schema($db);
    if ($receiptId <= 0) return ['success' => false, 'message' => '缺少收款單'];

    $st = $db->prepare("SELECT * FROM acc_receipt WHERE receipt_id=? LIMIT 1");
    $st->execute([$receiptId]);
    $rc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rc) return ['success' => false, 'message' => '找不到收款單'];

    // 整理輸入
    $clean = [];
    $sum   = 0.0;
    foreach ($allocs as $a) {
        $iid = (int)($a['invoice_id'] ?? 0);
        $amt = round((float)($a['amount'] ?? 0), 2);
        if ($iid <= 0 || abs($amt) < 0.005) continue;
        if (isset($clean[$iid])) $clean[$iid] += $amt; else $clean[$iid] = $amt;
    }
    foreach ($clean as $amt) $sum += $amt;

    if ($sum > (float)$rc['amount'] + 0.005) {
        return ['success' => false, 'message' => '沖帳總額 ' . number_format($sum)
                . ' 超過收款金額 ' . number_format((float)$rc['amount'])];
    }

    try {
        $db->beginTransaction();
        // 先刪掉本收款單原有沖帳，再重寫（額度計算才不會把自己算進去）
        $db->prepare("DELETE FROM acc_receipt_alloc WHERE receipt_id=?")->execute([$receiptId]);

        $ins = $db->prepare("INSERT INTO acc_receipt_alloc (receipt_id, invoice_id, amount, Created_By)
                             VALUES (?,?,?,?)");
        foreach ($clean as $iid => $amt) {
            $inv = acc_invoice_open($db, $iid);
            if (!$inv) { $db->rollBack(); return ['success' => false, 'message' => "發票 #{$iid} 不存在"]; }
            if ($inv['status'] !== 'issued') {
                $db->rollBack();
                return ['success' => false, 'message' => '發票 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 尚未開立或已作廢，不可沖帳'];
            }
            if (trim((string)$inv['customer_name']) !== trim((string)$rc['customer_name'])) {
                $db->rollBack();
                return ['success' => false, 'message' => '發票 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 的客戶（' . $inv['customer_name'] . '）與收款單（' . $rc['customer_name'] . '）不符'];
            }
            $sign = ($inv['doc_type'] === 'ALLOWANCE') ? -1 : 1;
            $open = round((float)$inv['total_amount'] * $sign - (float)$inv['paid'], 2);
            if ($sign > 0 && $amt > $open + 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '發票 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 未收餘額只有 ' . number_format($open) . '，不可沖 ' . number_format($amt)];
            }
            if ($sign < 0 && $amt < $open - 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '折讓單 ' . ($inv['invoice_no'] ?: '#' . $iid)
                        . ' 可沖額度只有 ' . number_format($open)];
            }
            $ins->execute([$receiptId, $iid, $amt, $userId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '沖帳失敗：' . $e->getMessage()];
    }

    $left = round((float)$rc['amount'] - $sum, 2);
    return ['success' => true, 'allocated' => $sum, 'unallocated' => $left,
            'count' => count($clean),
            'message' => '已沖帳 ' . count($clean) . ' 張發票、合計 ' . number_format($sum)
                       . ($left > 0.005 ? '，尚有 ' . number_format($left) . ' 元未分配（暫收款）' : '')];
}

/** 收款單清單（含已沖／未分配金額） */
function acc_receipt_list(PDO $db, array $f): array
{
    acc_ensure_schema($db);
    $where = []; $params = [];
    if (!empty($f['date_from'])) { $where[] = "r.receipt_date >= ?"; $params[] = $f['date_from']; }
    if (!empty($f['date_to']))   { $where[] = "r.receipt_date <= ?"; $params[] = $f['date_to']; }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(r.receipt_no LIKE ? OR r.customer_name LIKE ? OR r.bank LIKE ? OR r.check_no LIKE ?)";
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $db->prepare("SELECT r.*,
                          COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                                    WHERE a.receipt_id = r.receipt_id), 0) AS allocated,
                          (SELECT COUNT(*) FROM acc_receipt_alloc a2
                           WHERE a2.receipt_id = r.receipt_id) AS alloc_cnt
                        FROM acc_receipt r
                        $ws
                        ORDER BY r.receipt_date DESC, r.receipt_id DESC");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['amount']      = (float)$r['amount'];
        $r['fee']         = (float)$r['fee'];
        $r['allocated']   = (float)$r['allocated'];
        $r['alloc_cnt']   = (int)$r['alloc_cnt'];
        $r['unallocated'] = round($r['amount'] - $r['allocated'], 2);
    }
    unset($r);

    if (!empty($f['only_unalloc'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['unallocated'] > 0.005));
    }

    $summary = ['count' => count($rows), 'amount' => 0.0, 'allocated' => 0.0, 'unallocated' => 0.0, 'fee' => 0.0];
    foreach ($rows as $r) {
        $summary['amount']      += $r['amount'];
        $summary['allocated']   += $r['allocated'];
        $summary['unallocated'] += $r['unallocated'];
        $summary['fee']         += $r['fee'];
    }

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));
    return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'summary' => $summary];
}

/** 某收款單的沖帳明細 */
function acc_receipt_allocs(PDO $db, int $receiptId): array
{
    $st = $db->prepare("SELECT a.alloc_id, a.invoice_id, a.amount,
                               i.invoice_no, i.invoice_date, i.doc_type, i.billing_month, i.total_amount
                        FROM acc_receipt_alloc a
                        JOIN acc_invoice i ON i.invoice_id = a.invoice_id
                        WHERE a.receipt_id = ?
                        ORDER BY i.invoice_date, a.alloc_id");
    $st->execute([$receiptId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 刪除收款單（連同沖帳明細） */
function acc_receipt_delete(PDO $db, int $receiptId, string $userId): array
{
    if ($receiptId <= 0) return ['success' => false, 'message' => '缺少收款單'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM acc_receipt_alloc WHERE receipt_id=?")->execute([$receiptId]);
        $st = $db->prepare("DELETE FROM acc_receipt WHERE receipt_id=?");
        $st->execute([$receiptId]);
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'deleted' => $n,
                'message' => $n ? '已刪除收款單與其沖帳明細' : '找不到該收款單'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '刪除失敗：' . $e->getMessage()];
    }
}

/* ============================================================
 * 應付對帳（廠商加工費）
 *
 * 來源：bom_ing_transfer_log。實測 43,622 筆有金額的紀錄中有 43,616 筆
 * 已經帶了 invoice_date / invoice_ym / tax_amount / paid_qty（99.99%），
 * 也就是廠商發票資訊本來就在維護，所以會計端只需要彙總對帳，不必重新登錄。
 * 歸戶：maker_from（本次加工廠商）對 maker_list.maker_id_no。
 * 月份：優先用資料本身的 invoice_ym（廠商發票年月）；沒有才用 transfer_date 配廠商結帳日推算。
 * ============================================================ */

/**
 * 應付彙總（廠商 × 發票年月）
 *
 * 來源有兩種，以 $f['src'] 切換：
 *   TLOG＝廠商加工費（bom_ing_transfer_log）、PURC＝材料／其他採購（purchase_request，只算月結）、
 *   all（預設）＝兩者合併成同一列（同一廠商同一月份本來就該收在同一份對帳單裡）。
 * 每一列都帶 amt_tlog／amt_purc／cnt_tlog／cnt_purc 讓畫面看得出組成。
 *
 * @param array $f ym_from, ym_to (YYYY-MM), src, kw, only_gap, sort, dir, page, per_page
 */
function acc_ap_summary(PDO $db, array $f): array
{
    $src = strtoupper(trim((string)($f['src'] ?? 'all')));
    if (!in_array($src, ['TLOG', 'PURC', 'ALL'], true)) $src = 'ALL';
    $ymFrom = preg_match('/^\d{4}-\d{2}$/', (string)($f['ym_from'] ?? '')) ? $f['ym_from'] : date('Y-m');
    $ymTo   = preg_match('/^\d{4}-\d{2}$/', (string)($f['ym_to'] ?? ''))   ? $f['ym_to']   : $ymFrom;
    if ($ymTo < $ymFrom) [$ymFrom, $ymTo] = [$ymTo, $ymFrom];
    $c1 = str_replace('-', '', $ymFrom);       // invoice_ym 是 char(6) YYYYMM
    $c2 = str_replace('-', '', $ymTo);
    [$dFrom, $dTo] = acc_scan_range($ymFrom, $ymTo);

    $st = $db->prepare("
        SELECT t.maker_from,
               COALESCE(NULLIF(t.invoice_ym,''),
                        DATE_FORMAT(t.transfer_date,'%Y%m'))       AS ym,
               COUNT(*)                                            AS cnt,
               SUM(COALESCE(t.process_amount,0))                   AS amt,
               SUM(COALESCE(t.tax_amount,0))                       AS tax,
               SUM(COALESCE(t.transfer_qty,0))                     AS qty,
               SUM(CASE WHEN t.invoice_date IS NULL THEN 1 ELSE 0 END) AS no_inv_date,
               MIN(t.transfer_date)                                AS d_min,
               MAX(t.transfer_date)                                AS d_max
        FROM bom_ing_transfer_log t
        WHERE COALESCE(t.process_amount,0) <> 0
          AND (
                (t.invoice_ym IS NOT NULL AND t.invoice_ym <> '' AND t.invoice_ym BETWEEN ? AND ?)
             OR ((t.invoice_ym IS NULL OR t.invoice_ym = '') AND t.transfer_date BETWEEN ? AND ?)
              )
        GROUP BY t.maker_from, ym
        ORDER BY amt DESC");
    $st->execute([$c1, $c2, $dFrom, $dTo]);
    $raw = ($src === 'PURC') ? [] : $st->fetchAll(PDO::FETCH_ASSOC);

    // 廠商主檔
    $mk = [];
    foreach ($db->query("SELECT maker_id_no, maker_id, maker_id_all, tax_id, payment_method,
                                net_days, settlement_mode, settlement_day, status
                         FROM maker_list")->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $mk[trim((string)$m['maker_id_no'])] = $m;
    }

    $rows = [];
    foreach ($raw as $r) {
        $id  = trim((string)$r['maker_from']);
        $m   = $mk[$id] ?? null;
        $ym  = (string)$r['ym'];
        $ymF = (strlen($ym) === 6) ? substr($ym, 0, 4) . '-' . substr($ym, 4, 2) : $ym;
        $amt = (float)$r['amt'];
        $tax = (float)$r['tax'];
        $rows[] = [
            'maker_id_no'    => $id,
            'maker_name'     => $m['maker_id']     ?? ($id !== '' ? $id : '（未指定廠商）'),
            'maker_full'     => $m['maker_id_all'] ?? null,
            'tax_id'         => $m['tax_id']       ?? null,
            'payment_method' => $m['payment_method'] ?? null,
            'net_days'       => $m['net_days']     ?? null,
            'in_master'      => (bool)$m,
            'inactive'       => ($m && strtoupper(trim((string)$m['status'])) === 'X'),
            'invoice_ym'     => $ymF,
            'cnt'            => (int)$r['cnt'],
            'qty'            => (int)$r['qty'],
            'amount'         => $amt,
            'tax_amount'     => $tax,
            'total_amount'   => $amt + $tax,
            'no_inv_date'    => (int)$r['no_inv_date'],
            'date_range'     => substr((string)$r['d_min'], 0, 10) . ' ~ ' . substr((string)$r['d_max'], 0, 10),
            'has_tax_id'     => ($m && trim((string)$m['tax_id']) !== ''),
            'src'            => 'TLOG',
            'cnt_tlog'       => (int)$r['cnt'],  'amt_tlog' => $amt,
            'cnt_purc'       => 0,               'amt_purc' => 0.0,
            'paid_purc'      => 0.0,
        ];
    }

    /* 採購側：同一廠商同一月份若加工費那邊已經有一列，就併進同一列
       （對帳時本來就是一份對帳單對一個廠商一個月，不該拆成兩列各對各的）。 */
    if ($src !== 'TLOG') {
        $idx = [];
        foreach ($rows as $i => $r) $idx[$r['maker_id_no'] . '|' . $r['invoice_ym']] = $i;

        foreach (acc_purc_rows($db, ['ym_from' => $ymFrom, 'ym_to' => $ymTo]) as $p) {
            $id  = trim((string)$p['vendor_id']);
            $ymF = $p['billing_month'];
            $key = $id . '|' . $ymF;
            $amt = (float)$p['subtotal'];
            $tax = (float)$p['tax_amount'];

            if (isset($idx[$key])) {
                $r = &$rows[$idx[$key]];
                $r['cnt']          += 1;
                $r['amount']       += $amt;
                $r['tax_amount']   += $tax;
                $r['total_amount'] += $amt + $tax;
                $r['cnt_purc']     += 1;
                $r['amt_purc']     += $amt;
                $r['paid_purc']    += (float)$p['paid_amt'];
                if ($p['invoice_date'] === null || $p['invoice_date'] === '') $r['no_inv_date'] += 1;
                $r['src'] = 'MIX';
                unset($r);
                continue;
            }
            $m = $mk[$id] ?? null;
            $rows[] = [
                'maker_id_no'    => $id,
                'maker_name'     => $m['maker_id'] ?? ($p['vendor_name'] ?: ($id !== '' ? $id : '（未指定廠商）')),
                'maker_full'     => $m['maker_id_all'] ?? null,
                'tax_id'         => $m['tax_id']       ?? null,
                'payment_method' => $m['payment_method'] ?? null,
                'net_days'       => $m['net_days']     ?? null,
                'in_master'      => (bool)$m,
                'inactive'       => ($m && strtoupper(trim((string)$m['status'])) === 'X'),
                'invoice_ym'     => $ymF,
                'cnt'            => 1,
                'qty'            => 0,
                'amount'         => $amt,
                'tax_amount'     => $tax,
                'total_amount'   => $amt + $tax,
                'no_inv_date'    => ($p['invoice_date'] === null || $p['invoice_date'] === '') ? 1 : 0,
                'date_range'     => ($p['invoice_date'] ?: $p['ordered_at']) . ' ~ ' . ($p['invoice_date'] ?: $p['ordered_at']),
                'has_tax_id'     => ($m && trim((string)$m['tax_id']) !== ''),
                'src'            => 'PURC',
                'cnt_tlog'       => 0, 'amt_tlog' => 0.0,
                'cnt_purc'       => 1, 'amt_purc' => $amt,
                'paid_purc'      => (float)$p['paid_amt'],
            ];
            $idx[$key] = count($rows) - 1;
        }
    }

    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $rows = array_values(array_filter($rows, fn($r) =>
            mb_stripos((string)$r['maker_name'], $kw) !== false
            || mb_stripos((string)$r['maker_full'], $kw) !== false
            || mb_stripos((string)$r['maker_id_no'], $kw) !== false
            || mb_stripos((string)$r['tax_id'], $kw) !== false));
    }
    if (!empty($f['only_gap'])) {
        $rows = array_values(array_filter($rows, fn($r) => !$r['in_master'] || !$r['has_tax_id'] || $r['no_inv_date'] > 0));
    }

    $summary = ['groups' => count($rows), 'cnt' => 0, 'amount' => 0.0, 'tax_amount' => 0.0,
                'total_amount' => 0.0, 'not_in_master' => 0, 'no_tax_id' => 0, 'no_inv_date' => 0,
                'amt_tlog' => 0.0, 'amt_purc' => 0.0, 'cnt_tlog' => 0, 'cnt_purc' => 0, 'paid_purc' => 0.0];
    foreach ($rows as $r) {
        $summary['cnt']          += $r['cnt'];
        $summary['amount']       += $r['amount'];
        $summary['tax_amount']   += $r['tax_amount'];
        $summary['total_amount'] += $r['total_amount'];
        if (!$r['in_master'])  $summary['not_in_master']++;
        if (!$r['has_tax_id']) $summary['no_tax_id']++;
        $summary['no_inv_date'] += $r['no_inv_date'];
        $summary['amt_tlog']    += $r['amt_tlog'];
        $summary['amt_purc']    += $r['amt_purc'];
        $summary['cnt_tlog']    += $r['cnt_tlog'];
        $summary['cnt_purc']    += $r['cnt_purc'];
        $summary['paid_purc']   += $r['paid_purc'];
    }

    $sort = $f['sort'] ?? 'total_amount';
    $dir  = (($f['dir'] ?? 'desc') === 'asc') ? 1 : -1;
    usort($rows, function ($a, $b) use ($sort, $dir) {
        switch ($sort) {
            case 'maker':      return $dir * strnatcasecmp($a['maker_name'], $b['maker_name']);
            case 'invoice_ym': return $dir * strcmp($a['invoice_ym'], $b['invoice_ym']);
            case 'cnt':        return $dir * ($a['cnt'] <=> $b['cnt']);
            case 'amount':     return $dir * ($a['amount'] <=> $b['amount']);
            default:           return $dir * ($a['total_amount'] <=> $b['total_amount']);
        }
    });

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0,
                                'summary' => $summary, 'ym_from' => $ymFrom, 'ym_to' => $ymTo];
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));
    return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'summary' => $summary, 'ym_from' => $ymFrom, 'ym_to' => $ymTo, 'src' => $src];
}

/**
 * 應付明細（單一廠商單一發票年月）
 * @param string $src TLOG＝只加工費、PURC＝只採購、all（預設）＝兩者都列
 */
function acc_ap_detail(PDO $db, string $makerIdNo, string $ym, string $src = 'all'): array
{
    $src = strtoupper(trim($src));
    if (!in_array($src, ['TLOG', 'PURC', 'ALL'], true)) $src = 'ALL';
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return ['items' => [], 'head' => null];
    $c = str_replace('-', '', $ym);
    [$dFrom, $dTo] = acc_scan_range($ym, $ym);

    $stm = $db->prepare("SELECT maker_id_no, maker_id, maker_id_all, tax_id, payment_method,
                                net_days, settlement_mode, settlement_day, invoice_address, billing_address
                         FROM maker_list WHERE maker_id_no = ? LIMIT 1");
    $stm->execute([$makerIdNo]);
    $m = $stm->fetch(PDO::FETCH_ASSOC) ?: null;

    $st = $db->prepare("
        SELECT t.transfer_id, t.transfer_no, DATE_FORMAT(t.transfer_date,'%Y-%m-%d') AS d,
               t.bom, t.bom_sn, t.product_id, t.transfer_qty, t.loss_qty, t.paid_qty,
               t.price, t.modified_unit_price, t.process_amount, t.tax_amount,
               DATE_FORMAT(t.invoice_date,'%Y-%m-%d') AS inv_date, t.invoice_ym,
               t.note, t.note2, t.order_no,
               -- bom_ing 同一 (bom, bom_sn) 可能有多列，用 MAX 收斂；
               -- 直接 SELECT 非聚合欄位會被 only_full_group_by 擋下（錯誤 1055）
               COALESCE(MAX(pn.ProcessName),'') AS process_name
        FROM bom_ing_transfer_log t
        LEFT JOIN bom_ing bi ON bi.bom = t.bom AND bi.bom_sn = t.bom_sn
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        WHERE t.maker_from = ? AND COALESCE(t.process_amount,0) <> 0
          AND ( (t.invoice_ym IS NOT NULL AND t.invoice_ym <> '' AND t.invoice_ym = ?)
             OR ((t.invoice_ym IS NULL OR t.invoice_ym = '') AND t.transfer_date BETWEEN ? AND ?) )
        GROUP BY t.transfer_id
        ORDER BY t.transfer_date, t.transfer_no, t.transfer_id");
    $st->execute([$makerIdNo, $c, $dFrom, $dTo]);
    $items = ($src === 'PURC') ? [] : $st->fetchAll(PDO::FETCH_ASSOC);

    $amt = 0.0; $tax = 0.0;
    foreach ($items as &$it) {
        $it['process_amount'] = (float)$it['process_amount'];
        $it['tax_amount']     = (float)$it['tax_amount'];
        $it['unit_price']     = (float)($it['modified_unit_price'] ?: $it['price']);
        $it['src']            = 'TLOG';
        $it['src_id']         = (int)$it['transfer_id'];
        $it['doc_no']         = $it['transfer_no'];
        $amt += $it['process_amount'];
        $tax += $it['tax_amount'];
    }
    unset($it);

    /* 採購（月結）也是這個廠商這個月的應付，同一份對帳單裡一起列，
       欄位對齊加工費那側（src/src_id/doc_no/d/process_name/…），下游不必分兩套處理。 */
    if ($src !== 'TLOG') {
        foreach (acc_purc_rows($db, ['vendor_id' => $makerIdNo, 'ym_from' => $ym, 'ym_to' => $ym]) as $p) {
            $items[] = [
                'src'            => 'PURC',
                'src_id'         => (int)$p['req_id'],
                'transfer_id'    => null,
                'transfer_no'    => $p['req_no'],
                'doc_no'         => $p['req_no'],
                'd'              => $p['invoice_date'] ?: $p['ordered_at'],
                'bom'            => null,
                'bom_sn'         => null,
                'product_id'     => null,
                'transfer_qty'   => 1,
                'loss_qty'       => null,
                'paid_qty'       => null,
                'price'          => (float)$p['subtotal'],
                'modified_unit_price' => null,
                'unit_price'     => (float)$p['subtotal'],
                'process_amount' => (float)$p['subtotal'],
                'tax_amount'     => (float)$p['tax_amount'],
                'inv_date'       => $p['invoice_date'],
                'invoice_ym'     => str_replace('-', '', (string)$p['billing_month']),
                'invoice_no'     => $p['invoice_no'],
                'note'           => $p['title'],
                'note2'          => $p['purpose_label'],
                'order_no'       => null,
                'process_name'   => $p['title'],
                'purc_status'    => $p['status'],
                'pay_status'     => $p['pay_status'],
                'paid_amt'       => (float)$p['paid_amt'],
                'open_amt'       => (float)$p['open_amt'],
                'grand_total'    => (float)$p['grand_total'],
            ];
            $amt += (float)$p['subtotal'];
            $tax += (float)$p['tax_amount'];
        }
        usort($items, fn($a, $b) => strcmp((string)$a['d'], (string)$b['d'])
                                 ?: strcmp((string)$a['doc_no'], (string)$b['doc_no']));
    }

    return [
        'head' => [
            'maker_id_no'    => $makerIdNo,
            'maker_name'     => $m['maker_id']     ?? $makerIdNo,
            'maker_full'     => $m['maker_id_all'] ?? null,
            'tax_id'         => $m['tax_id']       ?? null,
            'payment_method' => $m['payment_method'] ?? null,
            'net_days'       => $m['net_days']     ?? null,
            'address'        => $m['billing_address'] ?? ($m['invoice_address'] ?? null),
            'invoice_ym'     => $ym,
            'in_master'      => (bool)$m,
        ],
        'items'        => $items,
        'amount'       => $amt,
        'tax_amount'   => $tax,
        'total_amount' => $amt + $tax,
    ];
}

/* ============================================================
 * 應付：材料／其他採購（來源 purchase_request）
 *
 * 現場採購分兩種（使用者 2026-07-30 說明）：
 *   現金付款 → 採購自己做簡單的零用金記帳，**目前不經過會計**，所以不進應付。
 *   月結     → 要經過會計，才是這裡要收的應付。
 * purchase_request 沒有「結帳方式」這個結構化欄位（pay_method 是自由文字，
 * 提示字是「匯款／月結／現金」），所以用規則判定＋會計可逐單覆寫（acc_purc_flag）。
 * 判錯不會卡死：應付頁列得出被排除的單與排除原因，一鍵就能改判。
 * 之後採購模組穩定了，正解是在採購頁加一個結帳方式下拉，這裡再改讀那個欄位。
 *
 * 應付成立時點：**已下單之後**（ordered_at 有值或狀態已到 ordered 以後）。
 * 還在詢價／簽核／待下單的單子只是意向，不是負債，不列入應付。
 *
 * 帳款月份：優先用廠商發票日 invoice_date 的月份，沒有才用下單日 ordered_at 的月份，
 * 與加工費那側「優先用 invoice_ym、沒有才用 transfer_date」同一套邏輯。
 * ============================================================ */

/** 被視為現金／零用金的付款方式關鍵字（比對 purchase_request.pay_method 自由文字） */
function acc_purc_cash_keywords(): array
{
    return ['現金', '零用金', '小額', '代墊', 'petty', 'cash'];
}

/**
 * 判定一張採購單的結帳方式。
 * @param array       $r        purchase_request 的一列（要有 pay_method）
 * @param string|null $override acc_purc_flag.settle_mode，有值就以它為準
 * @return string 'CREDIT'（月結，進應付）或 'CASH'（現金／零用金，不進應付）
 */
function acc_purc_settle_mode(array $r, ?string $override = null): string
{
    $ov = strtoupper(trim((string)$override));
    if ($ov === 'CREDIT' || $ov === 'CASH') return $ov;
    $pm = trim((string)($r['pay_method'] ?? ''));
    if ($pm !== '') {
        foreach (acc_purc_cash_keywords() as $kw) {
            if (mb_stripos($pm, $kw) !== false) return 'CASH';
        }
    }
    return 'CREDIT';
}

/** 判定理由（給畫面顯示，讓會計看得出為什麼被排除／納入） */
function acc_purc_mode_reason(array $r, ?string $override = null): string
{
    if (in_array(strtoupper(trim((string)$override)), ['CREDIT', 'CASH'], true)) return '會計手動指定';
    $pm = trim((string)($r['pay_method'] ?? ''));
    if ($pm === '') return '付款方式未填，預設當月結';
    foreach (acc_purc_cash_keywords() as $kw) {
        if (mb_stripos($pm, $kw) !== false) return '付款方式「' . $pm . '」含「' . $kw . '」';
    }
    return '付款方式「' . $pm . '」';
}

/** 採購單是否已成立應付（已下單之後、金額不為 0、未作廢） */
function acc_purc_is_payable_stage(array $r): bool
{
    if ((int)($r['is_active'] ?? 1) !== 1) return false;
    if (abs((float)($r['grand_total'] ?? 0)) < 0.005) return false;
    $st = strtolower(trim((string)($r['status'] ?? '')));
    if (in_array($st, ['rejected', 'canceled'], true)) return false;
    if (!empty($r['ordered_at'])) return true;
    return in_array($st, ['ordered', 'partial', 'received', 'closed'], true);
}

/** 採購單的帳款月份（YYYY-MM）：發票日優先，其次下單日，最後建立日 */
function acc_purc_month(array $r): string
{
    foreach (['invoice_date', 'ordered_at', 'Created_At'] as $k) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v !== '' && $v !== '0000-00-00' && strlen($v) >= 7) return substr($v, 0, 7);
    }
    return '';
}

/**
 * 取出採購應付的原始資料（已判好結帳方式與帳款月份、已算好已付金額）。
 * 這是採購側應付的唯一取數入口，彙總／明細／付款沖帳都吃它，口徑才不會各算各的。
 *
 * @param array $f vendor_id, ym_from, ym_to(YYYY-MM), req_id, include_cash(bool),
 *                 only_open(bool 只列還沒付完的), req_ids(array)
 */
function acc_purc_rows(PDO $db, array $f = []): array
{
    acc_ensure_schema($db);
    $where = ["p.is_active = 1"]; $params = [];
    if (!empty($f['vendor_id'])) { $where[] = "p.vendor_id = ?"; $params[] = $f['vendor_id']; }
    if (!empty($f['req_id']))    { $where[] = "p.req_id = ?";    $params[] = (int)$f['req_id']; }
    if (!empty($f['req_ids']) && is_array($f['req_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $f['req_ids'])));
        if (!$ids) return [];
        $where[] = "p.req_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        foreach ($ids as $x) $params[] = $x;
    }

    $st = $db->prepare("
        SELECT p.req_id, p.req_no, p.title, p.status, p.vendor_id, p.vendor_name,
               p.tax_type, p.subtotal, p.tax_amount, p.grand_total,
               p.invoice_no, DATE_FORMAT(p.invoice_date,'%Y-%m-%d')  AS invoice_date,
               DATE_FORMAT(p.ordered_at,'%Y-%m-%d')                  AS ordered_at,
               DATE_FORMAT(p.Created_At,'%Y-%m-%d')                  AS Created_At,
               p.pay_status, DATE_FORMAT(p.pay_date,'%Y-%m-%d')      AS pay_date,
               p.pay_method, p.is_active, p.requester_name, p.dept_name,
               p.purpose_type, p.purpose_label,
               f.settle_mode AS flag_mode, f.reason AS flag_reason, f.set_by_name AS flag_by,
               COALESCE((SELECT SUM(a.amount) FROM acc_payment_alloc a
                         WHERE a.src_type='PURC' AND a.src_id = p.req_id), 0) AS paid_amt,
               (SELECT COUNT(*) FROM acc_payment_alloc a2
                 WHERE a2.src_type='PURC' AND a2.src_id = p.req_id)           AS pay_cnt
        FROM purchase_request p
        LEFT JOIN acc_purc_flag f ON f.req_id = p.req_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.req_id");
    $st->execute($params);

    $ymFrom = preg_match('/^\d{4}-\d{2}$/', (string)($f['ym_from'] ?? '')) ? $f['ym_from'] : '';
    $ymTo   = preg_match('/^\d{4}-\d{2}$/', (string)($f['ym_to'] ?? ''))   ? $f['ym_to']   : '';

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['settle_mode']   = acc_purc_settle_mode($r, $r['flag_mode']);
        $r['mode_reason']   = acc_purc_mode_reason($r, $r['flag_mode']);
        $r['mode_manual']   = in_array(strtoupper(trim((string)$r['flag_mode'])), ['CREDIT', 'CASH'], true);
        $r['payable_stage'] = acc_purc_is_payable_stage($r);
        $r['billing_month'] = acc_purc_month($r);
        $r['subtotal']      = (float)$r['subtotal'];
        $r['tax_amount']    = (float)$r['tax_amount'];
        $r['grand_total']   = (float)$r['grand_total'];
        $r['paid_amt']      = (float)$r['paid_amt'];
        $r['pay_cnt']       = (int)$r['pay_cnt'];
        $r['open_amt']      = round($r['grand_total'] - $r['paid_amt'], 2);

        if (!$r['payable_stage']) continue;
        if ($r['settle_mode'] === 'CASH' && empty($f['include_cash'])) continue;
        if ($ymFrom !== '' && ($r['billing_month'] === '' || $r['billing_month'] < $ymFrom)) continue;
        if ($ymTo   !== '' && ($r['billing_month'] === '' || $r['billing_month'] > $ymTo))   continue;
        if (!empty($f['only_open']) && $r['open_amt'] <= 0.005) continue;
        $out[] = $r;
    }
    return $out;
}

/** 被排除在應付之外的採購單（現金／零用金），讓會計看得到並可改判 */
function acc_purc_cash_rows(PDO $db, array $f = []): array
{
    $rows = acc_purc_rows($db, array_merge($f, ['include_cash' => true, 'only_open' => false]));
    return array_values(array_filter($rows, fn($r) => $r['settle_mode'] === 'CASH'));
}

/** 會計覆寫某張採購單的結帳方式（現金⇄月結），寫稽核 */
function acc_purc_set_mode(PDO $db, int $reqId, string $mode, string $reason, ?array $user): array
{
    acc_ensure_schema($db);
    $mode = strtoupper(trim($mode));
    if ($reqId <= 0) return ['success' => false, 'message' => '缺少採購單'];
    if (!in_array($mode, ['CREDIT', 'CASH', 'AUTO'], true)) {
        return ['success' => false, 'message' => '結帳方式只能是 CREDIT（月結）／CASH（現金）／AUTO（回到自動判定）'];
    }
    $reason = trim($reason);
    if ($mode !== 'AUTO' && mb_strlen($reason) < 2) {
        return ['success' => false, 'message' => '請填寫原因（至少 2 個字），日後查帳要看得出為什麼這樣歸類'];
    }

    $st = $db->prepare("SELECT p.*, f.settle_mode AS flag_mode FROM purchase_request p
                        LEFT JOIN acc_purc_flag f ON f.req_id = p.req_id
                        WHERE p.req_id = ? LIMIT 1");
    $st->execute([$reqId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['success' => false, 'message' => '找不到採購單'];

    $old = acc_purc_settle_mode($r, $r['flag_mode']);
    $paid = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM acc_payment_alloc
                               WHERE src_type='PURC' AND src_id=" . (int)$reqId)->fetchColumn();
    if ($mode === 'CASH' && $paid > 0.005) {
        return ['success' => false, 'message' => '這張採購單已經有 ' . number_format($paid)
                . ' 元的付款沖帳紀錄，不能改判成現金；請先刪掉相關付款沖帳'];
    }

    try {
        $db->beginTransaction();
        if ($mode === 'AUTO') {
            $db->prepare("DELETE FROM acc_purc_flag WHERE req_id=?")->execute([$reqId]);
        } else {
            $db->prepare("INSERT INTO acc_purc_flag (req_id, settle_mode, reason, set_by, set_by_name, set_at)
                          VALUES (?,?,?,?,?,NOW())
                          ON DUPLICATE KEY UPDATE settle_mode=VALUES(settle_mode), reason=VALUES(reason),
                            set_by=VALUES(set_by), set_by_name=VALUES(set_by_name), set_at=NOW()")
               ->execute([$reqId, $mode, mb_substr($reason, 0, 200),
                          $user ? (int)$user['id'] : null, $user ? (string)$user['user_cname'] : null]);
        }
        $st2 = $db->prepare("SELECT p.*, f.settle_mode AS flag_mode FROM purchase_request p
                             LEFT JOIN acc_purc_flag f ON f.req_id = p.req_id WHERE p.req_id=? LIMIT 1");
        $st2->execute([$reqId]);
        $new = acc_purc_settle_mode($st2->fetch(PDO::FETCH_ASSOC) ?: $r, null);
        if ($mode !== 'AUTO') $new = $mode;

        acc_audit($db, 'ACC_EDIT', 'purchase_request', $reqId, (string)$r['req_no'],
                  ['settle_mode' => ['old' => $old, 'new' => $new]],
                  $mode === 'AUTO' ? '取消手動指定，回到自動判定' : $reason, $user);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '設定失敗：' . $e->getMessage()];
    }
    $lbl = ['CREDIT' => '月結（列入應付）', 'CASH' => '現金／零用金（不列入應付）'];
    return ['success' => true, 'settle_mode' => $new,
            'message' => '已設定為 ' . ($lbl[$new] ?? $new)];
}

/* ── 付款單與沖帳 ─────────────────────────────────────────── */

/** 付款單號：PY + 民國年3碼 + MMDD + 3 碼流水（與收款單 RC 同一套規則） */
function acc_payment_next_no(PDO $db, string $date): string
{
    $y      = (int)substr($date, 0, 4) - 1911;
    $prefix = 'PY' . str_pad((string)$y, 3, '0', STR_PAD_LEFT) . substr($date, 5, 2) . substr($date, 8, 2);
    $st = $db->prepare("SELECT MAX(CAST(SUBSTRING(payment_no, 10) AS UNSIGNED)) FROM acc_payment WHERE payment_no LIKE ?");
    $st->execute([$prefix . '%']);
    return $prefix . str_pad((string)((int)$st->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
}

/** 某廠商還沒付完的採購單（供付款沖帳挑選；編輯中的付款單要把自己已沖的算回額度） */
function acc_open_purchases(PDO $db, string $vendorId, ?int $includePaymentId = null): array
{
    $rows = acc_purc_rows($db, ['vendor_id' => $vendorId]);
    if (!$rows) return [];
    $ids = array_column($rows, 'req_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("SELECT src_id, COALESCE(SUM(amount),0) AS amt FROM acc_payment_alloc
                         WHERE src_type='PURC' AND payment_id = ? AND src_id IN ($in) GROUP BY src_id");
    $st->execute(array_merge([$includePaymentId ?? 0], $ids));
    $mine = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) $mine[(int)$x['src_id']] = (float)$x['amt'];

    $out = [];
    foreach ($rows as $r) {
        $r['this_paid'] = $mine[(int)$r['req_id']] ?? 0.0;
        $r['available'] = round($r['open_amt'] + $r['this_paid'], 2);
        if ($r['available'] <= 0.005) continue;
        $out[] = $r;
    }
    return $out;
}

/** 建立或更新付款單（不含沖帳明細，沖帳走 acc_payment_alloc_save） */
function acc_payment_save(PDO $db, array $d, string $userId): array
{
    acc_ensure_schema($db);
    $id   = (int)($d['payment_id'] ?? 0);
    $name = trim((string)($d['vendor_name'] ?? ''));
    $date = trim((string)($d['pay_date'] ?? ''));
    $amt  = (float)($d['amount'] ?? 0);

    if ($name === '')                                return ['success' => false, 'message' => '請填廠商'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['success' => false, 'message' => '出帳日格式錯誤'];
    if ($amt <= 0)                                   return ['success' => false, 'message' => '付款金額必須大於 0'];

    $vid = trim((string)($d['vendor_id'] ?? ''));
    if ($vid === '') {
        $stv = $db->prepare("SELECT maker_id_no FROM maker_list WHERE maker_id = ? LIMIT 1");
        $stv->execute([$name]);
        $vid = (string)($stv->fetchColumn() ?: '');
    }

    $method = trim((string)($d['method'] ?? '匯款'));
    $fee    = (float)($d['fee'] ?? 0);
    $bank   = trim((string)($d['bank'] ?? ''));
    $ckNo   = trim((string)($d['check_no'] ?? ''));
    $ckDue  = trim((string)($d['check_due'] ?? ''));
    $note   = trim((string)($d['note'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ckDue)) $ckDue = null;

    try {
        $db->beginTransaction();
        if ($id > 0) {
            $alloc = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM acc_payment_alloc
                                        WHERE payment_id = " . (int)$id)->fetchColumn();
            if ($amt < $alloc - 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '付款金額 ' . number_format($amt)
                        . ' 小於已沖帳金額 ' . number_format($alloc) . '，請先調整沖帳明細'];
            }
            $st = $db->prepare("UPDATE acc_payment SET vendor_id=?, vendor_name=?, pay_date=?, method=?,
                                amount=?, fee=?, bank=?, check_no=?, check_due=?, note=?,
                                Modified_By=?, Modified_At=NOW() WHERE payment_id=?");
            $st->execute([$vid ?: null, $name, $date, $method, $amt, $fee, $bank ?: null,
                          $ckNo ?: null, $ckDue, $note ?: null, $userId, $id]);
            $db->commit();
            // 出帳日可能被改過，付款狀態要跟著重算
            acc_purc_sync_pay_status($db, acc_payment_req_ids($db, $id));
            return ['success' => true, 'payment_id' => $id, 'message' => '已更新付款單'];
        }

        $no = acc_payment_next_no($db, $date);
        $st = $db->prepare("INSERT INTO acc_payment
                            (payment_no, vendor_id, vendor_name, pay_date, method, amount,
                             fee, bank, check_no, check_due, note, Created_By)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$no, $vid ?: null, $name, $date, $method, $amt, $fee, $bank ?: null,
                      $ckNo ?: null, $ckDue, $note ?: null, $userId]);
        $newId = (int)$db->lastInsertId();
        $db->commit();
        return ['success' => true, 'payment_id' => $newId, 'payment_no' => $no,
                'message' => '已建立付款單 ' . $no];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '存檔失敗：' . $e->getMessage()];
    }
}

/** 某張付款單沖到的採購單 req_id 清單 */
function acc_payment_req_ids(PDO $db, int $paymentId): array
{
    $st = $db->prepare("SELECT DISTINCT src_id FROM acc_payment_alloc WHERE payment_id=? AND src_type='PURC'");
    $st->execute([$paymentId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 寫入付款沖帳明細（整批取代該付款單原有的沖帳）。
 * 檢查：沖帳總額 ≤ 付款金額、每張採購單不可超付、採購單廠商需與付款單一致、
 * 現金／零用金採購單不可沖（那本來就不經過會計）。
 */
function acc_payment_alloc_save(PDO $db, int $paymentId, array $allocs, string $userId): array
{
    acc_ensure_schema($db);
    if ($paymentId <= 0) return ['success' => false, 'message' => '缺少付款單'];

    $st = $db->prepare("SELECT * FROM acc_payment WHERE payment_id=? LIMIT 1");
    $st->execute([$paymentId]);
    $pay = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pay) return ['success' => false, 'message' => '找不到付款單'];

    $clean = [];
    foreach ($allocs as $a) {
        $rid = (int)($a['req_id'] ?? $a['src_id'] ?? 0);
        $amt = round((float)($a['amount'] ?? 0), 2);
        if ($rid <= 0 || abs($amt) < 0.005) continue;
        $clean[$rid] = ($clean[$rid] ?? 0) + $amt;
    }
    $sum = array_sum($clean);
    if ($sum > (float)$pay['amount'] + 0.005) {
        return ['success' => false, 'message' => '沖帳總額 ' . number_format($sum)
                . ' 超過付款金額 ' . number_format((float)$pay['amount'])];
    }

    $before = acc_payment_req_ids($db, $paymentId);
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM acc_payment_alloc WHERE payment_id=?")->execute([$paymentId]);
        $ins = $db->prepare("INSERT INTO acc_payment_alloc (payment_id, src_type, src_id, amount, Created_By)
                             VALUES (?,'PURC',?,?,?)");
        foreach ($clean as $rid => $amt) {
            $rows = acc_purc_rows($db, ['req_id' => $rid, 'include_cash' => true]);
            $r = $rows[0] ?? null;
            if (!$r) {
                $db->rollBack();
                return ['success' => false, 'message' => "採購單 #{$rid} 不存在、已作廢，或尚未下單（未成立應付）"];
            }
            if ($r['settle_mode'] === 'CASH') {
                $db->rollBack();
                return ['success' => false, 'message' => '採購單 ' . $r['req_no']
                        . ' 是現金／零用金（' . $r['mode_reason'] . '），不經過會計付款；'
                        . '若判定有誤請先在應付頁把它改判成月結'];
            }
            if (trim((string)$r['vendor_id']) !== '' && trim((string)$pay['vendor_id']) !== ''
                && trim((string)$r['vendor_id']) !== trim((string)$pay['vendor_id'])) {
                $db->rollBack();
                return ['success' => false, 'message' => '採購單 ' . $r['req_no'] . ' 的廠商（'
                        . $r['vendor_name'] . '）與付款單（' . $pay['vendor_name'] . '）不符'];
            }
            // 這張單扣掉「本付款單以外」的已沖金額，才是這次可沖的上限
            $stO = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM acc_payment_alloc
                                 WHERE src_type='PURC' AND src_id=? AND payment_id<>?");
            $stO->execute([$rid, $paymentId]);
            $others = (float)$stO->fetchColumn();
            $open   = round((float)$r['grand_total'] - $others, 2);
            if ($amt > $open + 0.005) {
                $db->rollBack();
                return ['success' => false, 'message' => '採購單 ' . $r['req_no'] . ' 未付餘額只有 '
                        . number_format($open) . '，不可沖 ' . number_format($amt)];
            }
            $ins->execute([$paymentId, $rid, $amt, $userId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '沖帳失敗：' . $e->getMessage()];
    }

    // 這次沖到的、以及被拿掉的採購單，付款狀態都要重算
    acc_purc_sync_pay_status($db, array_values(array_unique(array_merge($before, array_keys($clean)))));

    $left = round((float)$pay['amount'] - $sum, 2);
    return ['success' => true, 'allocated' => $sum, 'unallocated' => $left, 'count' => count($clean),
            'message' => '已沖帳 ' . count($clean) . ' 張採購單、合計 ' . number_format($sum)
                       . ($left > 0.005 ? '，尚有 ' . number_format($left) . ' 元未分配（暫付款）' : '')];
}

/**
 * 依會計的沖帳結果回寫 purchase_request.pay_status／pay_date／pay_method。
 * 使用者已拍板：月結採購走會計，所以會計的沖帳就是付款狀態的唯一真相，
 * 採購頁只顯示結果，不再自己判一套。現金單不經會計，這裡完全不碰。
 */
function acc_purc_sync_pay_status(PDO $db, array $reqIds): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $reqIds))));
    if (!$ids) return 0;
    $n = 0;
    foreach ($ids as $rid) {
        $rows = acc_purc_rows($db, ['req_id' => $rid, 'include_cash' => true]);
        $r = $rows[0] ?? null;
        if (!$r || $r['settle_mode'] === 'CASH') continue;   // 現金單不碰
        $st = $db->prepare("SELECT COALESCE(SUM(a.amount),0) AS paid, MAX(p.pay_date) AS last_date,
                                   SUBSTRING_INDEX(GROUP_CONCAT(p.method ORDER BY p.pay_date DESC), ',', 1) AS last_method
                            FROM acc_payment_alloc a JOIN acc_payment p ON p.payment_id = a.payment_id
                            WHERE a.src_type='PURC' AND a.src_id = ?");
        $st->execute([$rid]);
        $x    = $st->fetch(PDO::FETCH_ASSOC) ?: ['paid' => 0, 'last_date' => null, 'last_method' => null];
        $paid = (float)$x['paid'];
        $full = ($paid >= (float)$r['grand_total'] - 0.005) && $paid > 0.005;

        $db->prepare("UPDATE purchase_request SET pay_status=?, pay_date=?, pay_method=? WHERE req_id=?")
           ->execute([$full ? 'paid' : 'unpaid',
                      $full ? ($x['last_date'] ?: null) : null,
                      $paid > 0.005 ? ($x['last_method'] ?: $r['pay_method']) : $r['pay_method'],
                      $rid]);
        $n++;
    }
    return $n;
}

/** 付款單清單（含已沖／未分配金額） */
function acc_payment_list(PDO $db, array $f): array
{
    acc_ensure_schema($db);
    $where = []; $params = [];
    if (!empty($f['date_from'])) { $where[] = "p.pay_date >= ?"; $params[] = $f['date_from']; }
    if (!empty($f['date_to']))   { $where[] = "p.pay_date <= ?"; $params[] = $f['date_to']; }
    $kw = trim((string)($f['kw'] ?? ''));
    if ($kw !== '') {
        $where[] = "(p.payment_no LIKE ? OR p.vendor_name LIKE ? OR p.vendor_id LIKE ? OR p.bank LIKE ? OR p.check_no LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = '%' . $kw . '%';
    }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = $db->prepare("SELECT p.*,
                          COALESCE((SELECT SUM(a.amount) FROM acc_payment_alloc a
                                    WHERE a.payment_id = p.payment_id), 0) AS allocated,
                          (SELECT COUNT(*) FROM acc_payment_alloc a2
                           WHERE a2.payment_id = p.payment_id) AS alloc_cnt
                        FROM acc_payment p $ws
                        ORDER BY p.pay_date DESC, p.payment_id DESC");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['amount']      = (float)$r['amount'];
        $r['fee']         = (float)$r['fee'];
        $r['allocated']   = (float)$r['allocated'];
        $r['alloc_cnt']   = (int)$r['alloc_cnt'];
        $r['unallocated'] = round($r['amount'] - $r['allocated'], 2);
    }
    unset($r);
    if (!empty($f['only_unalloc'])) {
        $rows = array_values(array_filter($rows, fn($r) => $r['unallocated'] > 0.005));
    }

    $summary = ['count' => count($rows), 'amount' => 0.0, 'allocated' => 0.0, 'unallocated' => 0.0, 'fee' => 0.0];
    foreach ($rows as $r) {
        $summary['amount']      += $r['amount'];
        $summary['allocated']   += $r['allocated'];
        $summary['unallocated'] += $r['unallocated'];
        $summary['fee']         += $r['fee'];
    }

    $total   = count($rows);
    $perPage = (int)($f['per_page'] ?? 20);
    if ($perPage === 0) return ['rows' => $rows, 'total' => $total, 'page' => 1, 'per_page' => 0, 'summary' => $summary];
    if (!in_array($perPage, [5, 10, 20, 50], true)) $perPage = 20;
    $page = max(1, (int)($f['page'] ?? 1));
    return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'summary' => $summary];
}

/** 某付款單的沖帳明細（帶採購單資訊） */
function acc_payment_allocs(PDO $db, int $paymentId): array
{
    $st = $db->prepare("SELECT a.alloc_id, a.src_type, a.src_id AS req_id, a.amount,
                               p.req_no, p.title, p.grand_total, p.status,
                               DATE_FORMAT(p.invoice_date,'%Y-%m-%d') AS invoice_date, p.invoice_no
                        FROM acc_payment_alloc a
                        LEFT JOIN purchase_request p ON p.req_id = a.src_id
                        WHERE a.payment_id = ? AND a.src_type='PURC'
                        ORDER BY p.invoice_date, a.alloc_id");
    $st->execute([$paymentId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['amount'] = (float)$r['amount']; $r['grand_total'] = (float)$r['grand_total']; }
    unset($r);
    return $rows;
}

/** 刪除付款單（連同沖帳明細），並把受影響的採購單付款狀態重算回去 */
function acc_payment_delete(PDO $db, int $paymentId, string $userId): array
{
    if ($paymentId <= 0) return ['success' => false, 'message' => '缺少付款單'];
    $ids = acc_payment_req_ids($db, $paymentId);
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM acc_payment_alloc WHERE payment_id=?")->execute([$paymentId]);
        $st = $db->prepare("DELETE FROM acc_payment WHERE payment_id=?");
        $st->execute([$paymentId]);
        $n = $st->rowCount();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '刪除失敗：' . $e->getMessage()];
    }
    acc_purc_sync_pay_status($db, $ids);
    return ['success' => true, 'deleted' => $n,
            'message' => $n ? '已刪除付款單與其沖帳明細，相關採購單付款狀態已重算' : '找不到該付款單'];
}

/* ============================================================
 * 單據快搜（對帳神器）
 *
 * 使用情境：客戶／廠商拿一張紙本單據或對帳單來，上面可能只有
 * 一個單號、一個金額、一個料號或一個日期。這支函式用同一個關鍵字
 * 跨「出貨單／退貨單／發票／折讓單／收款單／加工移轉單」全找一遍，
 * 並直接告訴你每一筆「算在哪個客戶／廠商的哪個帳款月份」，
 * 免得人工翻月份猜。純數字關鍵字會額外做金額比對（含容差）。
 * ============================================================ */
function acc_doc_lookup(PDO $db, string $kw, array $opt = []): array
{
    $kw = trim($kw);
    if ($kw === '') return ['groups' => [], 'summary' => ['total' => 0]];

    $like    = '%' . $kw . '%';
    $isNum   = (bool)preg_match('/^\d{1,12}(\.\d+)?$/', str_replace(',', '', $kw));
    $num     = $isNum ? (float)str_replace(',', '', $kw) : 0.0;
    $tol     = max(1.0, $num * 0.005);           // 金額容差 0.5%，至少 1 元
    $limit   = (int)($opt['limit'] ?? 60);
    $global  = acc_global_cutoff($db);

    // 客戶結帳日（算帳款月份要用）
    $cust = [];
    foreach ($db->query("SELECT customer_id, customer, settlement_mode, settlement_day
                         FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (trim((string)$c['customer']) !== '') $cust[trim($c['customer'])] = $c;
    }
    // 已開發票的憑證 → 讓結果能顯示「已開在哪張發票上」
    $used = acc_invoiced_src_map($db);

    $groups = [];

    /* ── 出貨單 ── */
    $sql = "SELECT il.IS_id, il.IS_number, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS d,
                   il.Client_name, il.Product_id, il.Specification, il.Qty, il.Unit_price,
                   il.billing_month_override, il.Note, ot.Order_oo
            FROM is_list il
            LEFT JOIN order_track ot ON ot.Order_id = il.Order_id
            WHERE il.IS_number LIKE ? OR il.Client_name LIKE ? OR il.Product_id LIKE ?
                  OR il.Specification LIKE ? OR il.Note LIKE ?"
         . ($isNum ? " OR ABS(il.Qty * il.Unit_price - ?) <= ? OR il.Qty = ?" : "")
         . " ORDER BY il.Order_date DESC LIMIT " . $limit;
    $p = [$like, $like, $like, $like, $like];
    if ($isNum) { $p[] = $num; $p[] = $tol; $p[] = (int)$num; }
    $st = $db->prepare($sql); $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = trim((string)$r['Client_name']);
        $bm   = trim((string)$r['billing_month_override']);
        $auto = acc_billing_month($r['d'], acc_cutoff_for($cust[$name] ?? null, $global));
        $u    = $used['IS-' . (int)$r['IS_id']] ?? null;
        $groups['ship'][] = [
            'kind' => 'ship', 'id' => (int)$r['IS_id'], 'no' => $r['IS_number'], 'date' => $r['d'],
            'party' => $name, 'party_kind' => '客戶',
            'product_id' => $r['Product_id'], 'spec' => $r['Specification'],
            'qty' => (int)$r['Qty'], 'unit_price' => (float)$r['Unit_price'],
            'amount' => (float)$r['Qty'] * (float)$r['Unit_price'],
            'billing_month' => ($bm !== '' ? $bm : $auto),
            'auto_month' => $auto, 'overridden' => ($bm !== ''),
            'order_oo' => $r['Order_oo'], 'note' => $r['Note'],
            'invoiced' => $u ? ($u['invoice_no'] ?: '#' . $u['invoice_id']) : null,
            'can_edit_month' => !$u,     // 已開發票的不可再改月份
        ];
    }

    /* ── 退貨單 ── */
    $sql = "SELECT it.IR_id, it.IR_no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d,
                   it.Client_name, it.d_id, it.Specification, it.Qty, it.Unit_price,
                   it.billing_month_override, it.IR_ps
            FROM ir_track it
            WHERE it.IR_no LIKE ? OR it.Client_name LIKE ? OR it.d_id LIKE ?
                  OR it.Specification LIKE ? OR it.IR_ps LIKE ? OR it.C_IR LIKE ?"
         . ($isNum ? " OR ABS(it.Qty * it.Unit_price - ?) <= ? OR it.Qty = ?" : "")
         . " ORDER BY it.IR_date DESC LIMIT " . $limit;
    $p = [$like, $like, $like, $like, $like, $like];
    if ($isNum) { $p[] = $num; $p[] = $tol; $p[] = (int)$num; }
    $st = $db->prepare($sql); $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = trim((string)$r['Client_name']);
        $bm   = trim((string)$r['billing_month_override']);
        $auto = acc_billing_month($r['d'], acc_cutoff_for($cust[$name] ?? null, $global));
        $u    = $used['IR-' . (int)$r['IR_id']] ?? null;
        $groups['return'][] = [
            'kind' => 'return', 'id' => (int)$r['IR_id'], 'no' => $r['IR_no'], 'date' => $r['d'],
            'party' => $name, 'party_kind' => '客戶',
            'product_id' => $r['d_id'], 'spec' => $r['Specification'],
            'qty' => -(int)$r['Qty'], 'unit_price' => (float)$r['Unit_price'],
            'amount' => -((float)$r['Qty'] * (float)$r['Unit_price']),
            'billing_month' => ($bm !== '' ? $bm : $auto),
            'auto_month' => $auto, 'overridden' => ($bm !== ''),
            'note' => $r['IR_ps'],
            'invoiced' => $u ? ($u['invoice_no'] ?: '#' . $u['invoice_id']) : null,
            'can_edit_month' => !$u,
        ];
    }

    /* ── 發票／折讓單 ── */
    try {
        acc_ensure_schema($db);
        $sql = "SELECT i.*, COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                                      WHERE a.invoice_id = i.invoice_id),0) AS paid
                FROM acc_invoice i
                WHERE i.invoice_no LIKE ? OR i.customer_name LIKE ? OR i.customer_full LIKE ?
                      OR i.tax_id LIKE ?"
             . ($isNum ? " OR ABS(i.total_amount - ?) <= ? OR ABS(i.sales_amount - ?) <= ?" : "")
             . " ORDER BY i.invoice_date DESC, i.invoice_id DESC LIMIT " . $limit;
        $p = [$like, $like, $like, $like];
        if ($isNum) { $p[] = $num; $p[] = $tol; $p[] = $num; $p[] = $tol; }
        $st = $db->prepare($sql); $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $groups['invoice'][] = [
                'kind' => ($r['doc_type'] === 'ALLOWANCE') ? 'allowance' : 'invoice',
                'id' => (int)$r['invoice_id'], 'no' => $r['invoice_no'] ?: '#' . $r['invoice_id'],
                'date' => $r['invoice_date'], 'party' => $r['customer_name'], 'party_kind' => '客戶',
                'billing_month' => $r['billing_month'], 'status' => $r['status'],
                'amount' => (float)$r['total_amount'], 'sales' => (float)$r['sales_amount'],
                'tax' => (float)$r['tax_amount'], 'paid' => (float)$r['paid'],
                'open' => round((float)$r['total_amount'] - (float)$r['paid'], 2),
                'tax_id' => $r['tax_id'], 'can_edit_month' => false,
            ];
        }
    } catch (Throwable $e) { /* 表還沒建就略過 */ }

    /* ── 收款單 ── */
    try {
        $sql = "SELECT r.*, COALESCE((SELECT SUM(a.amount) FROM acc_receipt_alloc a
                                      WHERE a.receipt_id = r.receipt_id),0) AS allocated
                FROM acc_receipt r
                WHERE r.receipt_no LIKE ? OR r.customer_name LIKE ? OR r.bank LIKE ?
                      OR r.check_no LIKE ?"
             . ($isNum ? " OR ABS(r.amount - ?) <= ?" : "")
             . " ORDER BY r.receipt_date DESC LIMIT " . $limit;
        $p = [$like, $like, $like, $like];
        if ($isNum) { $p[] = $num; $p[] = $tol; }
        $st = $db->prepare($sql); $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $groups['receipt'][] = [
                'kind' => 'receipt', 'id' => (int)$r['receipt_id'], 'no' => $r['receipt_no'],
                'date' => $r['receipt_date'], 'party' => $r['customer_name'], 'party_kind' => '客戶',
                'amount' => (float)$r['amount'], 'allocated' => (float)$r['allocated'],
                'open' => round((float)$r['amount'] - (float)$r['allocated'], 2),
                'method' => $r['method'], 'check_no' => $r['check_no'],
                'can_edit_month' => false,
            ];
        }
    } catch (Throwable $e) { }

    /* ── 加工移轉單（應付側）── */
    $sql = "SELECT t.transfer_id, t.transfer_no, DATE_FORMAT(t.transfer_date,'%Y-%m-%d') AS d,
                   t.maker_from, t.bom, t.product_id, t.transfer_qty,
                   COALESCE(t.modified_unit_price, t.price) AS up,
                   t.process_amount, t.tax_amount,
                   DATE_FORMAT(t.invoice_date,'%Y-%m-%d') AS inv_date, t.invoice_ym,
                   t.note, t.order_no, m.maker_id, m.maker_id_all
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list m ON m.maker_id_no = t.maker_from
            WHERE COALESCE(t.process_amount,0) <> 0
              AND (t.transfer_no LIKE ? OR t.bom LIKE ? OR t.product_id LIKE ?
                   OR t.maker_from LIKE ? OR m.maker_id LIKE ? OR m.maker_id_all LIKE ?
                   OR t.order_no LIKE ? OR t.note LIKE ?"
         . ($isNum ? " OR ABS(t.process_amount - ?) <= ? OR t.transfer_qty = ?" : "")
         . ") ORDER BY t.transfer_date DESC LIMIT " . $limit;
    $p = [$like, $like, $like, $like, $like, $like, $like, $like];
    if ($isNum) { $p[] = $num; $p[] = $tol; $p[] = (int)$num; }
    $st = $db->prepare($sql); $st->execute($p);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ym  = trim((string)$r['invoice_ym']);
        $ymF = (strlen($ym) === 6) ? substr($ym, 0, 4) . '-' . substr($ym, 4, 2) : '';
        $groups['process'][] = [
            'kind' => 'process', 'id' => (int)$r['transfer_id'], 'no' => $r['transfer_no'],
            'date' => $r['d'], 'party' => $r['maker_id'] ?: $r['maker_from'], 'party_kind' => '廠商',
            'party_id' => $r['maker_from'], 'party_full' => $r['maker_id_all'],
            'bom' => $r['bom'], 'product_id' => $r['product_id'],
            'qty' => (int)$r['transfer_qty'], 'unit_price' => (float)$r['up'],
            'amount' => (float)$r['process_amount'], 'tax' => (float)$r['tax_amount'],
            'billing_month' => ($ymF !== '' ? $ymF : substr((string)$r['d'], 0, 7)),
            'auto_month' => substr((string)$r['d'], 0, 7),
            'overridden' => ($ymF !== '' && $ymF !== substr((string)$r['d'], 0, 7)),
            'inv_date' => $r['inv_date'], 'note' => $r['note'], 'order_no' => $r['order_no'],
            'can_edit_month' => true,
        ];
    }

    // 金額完全相符的排最前面（對紙本時最想先看到的就是這些），其餘依日期新到舊。
    // 另外標出哪些分組撞到 LIMIT——不可靜默截斷，否則使用者以為就這些。
    $truncated = [];
    foreach ($groups as $k => &$g) {
        if (count($g) >= $limit) $truncated[$k] = true;
        usort($g, function ($a, $b) use ($isNum, $num, $tol) {
            if ($isNum) {
                $ha = (abs(abs((float)($a['amount'] ?? 0)) - $num) <= $tol) ? 1 : 0;
                $hb = (abs(abs((float)($b['amount'] ?? 0)) - $num) <= $tol) ? 1 : 0;
                if ($ha !== $hb) return $hb - $ha;
            }
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });
    }
    unset($g);

    $cnt = 0;
    foreach ($groups as $g) $cnt += count($g);
    return ['groups' => $groups, 'keyword' => $kw, 'is_amount_search' => $isNum,
            'limit' => $limit, 'truncated' => $truncated,
            'summary' => ['total' => $cnt,
                          'ship'    => count($groups['ship'] ?? []),
                          'return'  => count($groups['return'] ?? []),
                          'invoice' => count($groups['invoice'] ?? []),
                          'receipt' => count($groups['receipt'] ?? []),
                          'process' => count($groups['process'] ?? [])]];
}

/* ============================================================
 * 帳款月份調整
 *
 * 為什麼需要：出貨或收貨日期超過結帳日時，系統會自動歸到下個月，
 * 但對方（客戶或廠商）可能認定算本月帳。這時要能人工指定。
 *
 * 應收側：改 is_list / ir_track 的 billing_month_override（留空＝恢復自動計算）。
 *         已開過發票的憑證不可改月份——金額已經在發票上了，改月份會讓
 *         對帳單與發票對不起來；要改請先作廢發票。
 * 應付側：改 bom_ing_transfer_log.invoice_ym（廠商發票年月，本來就是這個欄位的語意）。
 * ============================================================ */

/** 可調整帳款月份的單據搜尋（應收：出貨＋退貨；應付：加工移轉單） */
function acc_billing_search(PDO $db, array $f): array
{
    $side = ($f['side'] ?? 'ar') === 'ap' ? 'ap' : 'ar';
    $kw   = trim((string)($f['kw'] ?? ''));
    $from = trim((string)($f['date_from'] ?? ''));
    $to   = trim((string)($f['date_to'] ?? ''));
    $bm   = trim((string)($f['billing_month'] ?? ''));
    $onlyOvr = !empty($f['only_override']);
    $limit   = 300;
    $global  = acc_global_cutoff($db);

    $rows = [];

    if ($side === 'ar') {
        $cust = [];
        foreach ($db->query("SELECT customer, settlement_mode, settlement_day
                             FROM customer_list")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (trim((string)$c['customer']) !== '') $cust[trim($c['customer'])] = $c;
        }
        $used = acc_invoiced_src_map($db);

        foreach ([['is', 'is_list'], ['ir', 'ir_track']] as [$t, $tbl]) {
            $isShip = ($t === 'is');
            $w = []; $p = [];
            if ($isShip) {
                $sel = "il.IS_id AS id, il.IS_number AS no, DATE_FORMAT(il.Order_date,'%Y-%m-%d') AS d,
                        il.Client_name AS party, il.Product_id AS pid, il.Specification AS spec,
                        il.Qty AS qty, il.Unit_price AS up, il.billing_month_override AS ovr";
                $tb  = "is_list il"; $dcol = 'il.Order_date';
                if ($kw !== '') { $w[] = "(il.IS_number LIKE ? OR il.Client_name LIKE ? OR il.Product_id LIKE ?)";
                                  $p = array_merge($p, ["%$kw%", "%$kw%", "%$kw%"]); }
            } else {
                $sel = "it.IR_id AS id, it.IR_no AS no, DATE_FORMAT(it.IR_date,'%Y-%m-%d') AS d,
                        it.Client_name AS party, it.d_id AS pid, it.Specification AS spec,
                        it.Qty AS qty, it.Unit_price AS up, it.billing_month_override AS ovr";
                $tb  = "ir_track it"; $dcol = 'it.IR_date';
                if ($kw !== '') { $w[] = "(it.IR_no LIKE ? OR it.Client_name LIKE ? OR it.d_id LIKE ?)";
                                  $p = array_merge($p, ["%$kw%", "%$kw%", "%$kw%"]); }
            }
            if ($from !== '') { $w[] = "$dcol >= ?"; $p[] = $from; }
            if ($to   !== '') { $w[] = "$dcol <= ?"; $p[] = $to; }
            $ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
            $st = $db->prepare("SELECT $sel FROM $tb $ws ORDER BY $dcol DESC LIMIT $limit");
            $st->execute($p);

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $amt = (float)$r['qty'] * (float)$r['up'];
                if (abs($amt) < 0.0001) continue;
                $name = trim((string)$r['party']);
                $o    = trim((string)$r['ovr']);
                $auto = acc_billing_month($r['d'], acc_cutoff_for($cust[$name] ?? null, $global));
                $cur  = ($o !== '' ? $o : $auto);
                if ($bm !== '' && $cur !== $bm) continue;
                if ($onlyOvr && $o === '') continue;
                $u = $used[strtoupper($t) . '-' . (int)$r['id']] ?? null;
                $rows[] = [
                    'src_type' => strtoupper($t), 'id' => (int)$r['id'], 'no' => $r['no'],
                    'kind' => $isShip ? 'ship' : 'return', 'date' => $r['d'],
                    'party' => $name, 'product_id' => $r['pid'], 'spec' => $r['spec'],
                    'qty' => $isShip ? (int)$r['qty'] : -(int)$r['qty'],
                    'amount' => $isShip ? $amt : -$amt,
                    'auto_month' => $auto, 'override' => $o, 'billing_month' => $cur,
                    'overridden' => ($o !== ''),
                    'invoiced' => $u ? ($u['invoice_no'] ?: '#' . $u['invoice_id']) : null,
                    'locked' => (bool)$u,
                ];
            }
        }
    } else {
        $w = ["COALESCE(t.process_amount,0) <> 0"]; $p = [];
        if ($kw !== '') {
            $w[] = "(t.transfer_no LIKE ? OR t.bom LIKE ? OR t.product_id LIKE ?
                     OR t.maker_from LIKE ? OR m.maker_id LIKE ?)";
            $p = array_merge($p, array_fill(0, 5, "%$kw%"));
        }
        if ($from !== '') { $w[] = "t.transfer_date >= ?"; $p[] = $from; }
        if ($to   !== '') { $w[] = "t.transfer_date <= ?"; $p[] = $to; }
        if ($bm   !== '') { $w[] = "COALESCE(NULLIF(t.invoice_ym,''), DATE_FORMAT(t.transfer_date,'%Y%m')) = ?";
                            $p[] = str_replace('-', '', $bm); }
        $st = $db->prepare("
            SELECT t.transfer_id AS id, t.transfer_no AS no,
                   DATE_FORMAT(t.transfer_date,'%Y-%m-%d') AS d,
                   t.maker_from, m.maker_id, t.bom, t.product_id AS pid,
                   t.transfer_qty AS qty, COALESCE(t.modified_unit_price,t.price) AS up,
                   t.process_amount AS amt, t.tax_amount AS tax,
                   t.invoice_ym, DATE_FORMAT(t.invoice_date,'%Y-%m-%d') AS inv_date
            FROM bom_ing_transfer_log t
            LEFT JOIN maker_list m ON m.maker_id_no = t.maker_from
            WHERE " . implode(' AND ', $w) . "
            ORDER BY t.transfer_date DESC LIMIT $limit");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ym   = trim((string)$r['invoice_ym']);
            $ymF  = (strlen($ym) === 6) ? substr($ym, 0, 4) . '-' . substr($ym, 4, 2) : '';
            $auto = substr((string)$r['d'], 0, 7);
            $cur  = ($ymF !== '' ? $ymF : $auto);
            if ($onlyOvr && ($ymF === '' || $ymF === $auto)) continue;
            $rows[] = [
                'src_type' => 'TLOG', 'id' => (int)$r['id'], 'no' => $r['no'], 'kind' => 'process',
                'date' => $r['d'], 'party' => $r['maker_id'] ?: $r['maker_from'],
                'party_id' => $r['maker_from'], 'bom' => $r['bom'],
                'product_id' => $r['pid'], 'qty' => (int)$r['qty'],
                'amount' => (float)$r['amt'], 'tax' => (float)$r['tax'],
                'auto_month' => $auto, 'override' => $ymF, 'billing_month' => $cur,
                'overridden' => ($ymF !== '' && $ymF !== $auto),
                'inv_date' => $r['inv_date'], 'locked' => false,
            ];
        }
    }

    usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp((string)$a['no'], (string)$b['no']));

    $sum = ['count' => count($rows), 'amount' => 0.0, 'overridden' => 0, 'locked' => 0];
    foreach ($rows as $r) {
        $sum['amount'] += $r['amount'];
        if ($r['overridden']) $sum['overridden']++;
        if (!empty($r['locked'])) $sum['locked']++;
    }
    return ['rows' => $rows, 'summary' => $sum, 'side' => $side];
}

/**
 * 設定單一單據的帳款月份。
 * @param string $srcType IS=出貨 IR=退貨 TLOG=加工移轉單
 * @param string $ym      YYYY-MM；空字串＝恢復自動計算
 */
function acc_set_billing_month(PDO $db, string $srcType, int $id, string $ym, string $userId): array
{
    $srcType = strtoupper(trim($srcType));
    if (!in_array($srcType, ['IS', 'IR', 'TLOG'], true)) return ['success' => false, 'message' => '不支援的單據類型'];
    if ($id <= 0) return ['success' => false, 'message' => '缺少單據'];
    $ym = trim($ym);
    if ($ym !== '' && !preg_match('/^\d{4}-\d{2}$/', $ym)) {
        return ['success' => false, 'message' => '帳款月份格式須為 YYYY-MM'];
    }

    // 已開發票的憑證不可改月份（金額已在發票上，改了對帳單與發票會對不起來）
    if ($srcType === 'IS' || $srcType === 'IR') {
        $used = acc_invoiced_src_map($db);
        $u = $used[$srcType . '-' . $id] ?? null;
        if ($u) {
            return ['success' => false,
                    'message' => '此單據已開立在發票 ' . ($u['invoice_no'] ?: '#' . $u['invoice_id'])
                               . ' 上，不可變更帳款月份。若確定要改，請先作廢該發票。'];
        }
    }

    try {
        $db->beginTransaction();
        if ($srcType === 'IS') {
            $st = $db->prepare("UPDATE is_list SET billing_month_override = ? WHERE IS_id = ?");
            $st->execute([$ym !== '' ? $ym : null, $id]);
        } elseif ($srcType === 'IR') {
            $st = $db->prepare("UPDATE ir_track SET billing_month_override = ?, Modified_By = ?,
                                Modified_At = NOW() WHERE IR_id = ?");
            $st->execute([$ym !== '' ? $ym : null, $userId, $id]);
        } else {
            // 應付：invoice_ym 是 char(6) YYYYMM；清空＝退回用加工日期所在月份
            $st = $db->prepare("UPDATE bom_ing_transfer_log SET invoice_ym = ?, modified_at = NOW(),
                                changed_by = ? WHERE transfer_id = ?");
            $st->execute([$ym !== '' ? str_replace('-', '', $ym) : null, $userId, $id]);
        }
        $n = $st->rowCount();
        $db->commit();
        return ['success' => true, 'updated' => $n,
                'message' => $n ? ($ym !== '' ? "已指定帳款月份為 {$ym}" : '已恢復為自動計算')
                                : '資料未變更'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => '設定失敗：' . $e->getMessage()];
    }
}

/** 批次設定帳款月份 */
function acc_set_billing_month_bulk(PDO $db, array $items, string $ym, string $userId): array
{
    $ok = 0; $fail = 0; $errors = [];
    foreach ($items as $it) {
        $r = acc_set_billing_month($db, (string)($it['src_type'] ?? ''), (int)($it['id'] ?? 0), $ym, $userId);
        if ($r['success'] && ($r['updated'] ?? 0) > 0) $ok++;
        else { $fail++; if (count($errors) < 12) $errors[] = ($it['no'] ?? ('#' . ($it['id'] ?? '?'))) . '：' . $r['message']; }
    }
    return ['success' => true, 'applied' => $ok, 'failed' => $fail, 'errors' => $errors,
            'message' => "已調整 {$ok} 筆" . ($fail ? "，{$fail} 筆未變更" : '')];
}

/** 解析上傳的 CSV（支援 UTF-8 BOM 與 Big5），回傳 [表頭, 資料列] */
function acc_parse_csv(string $raw): array
{
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $conv = @mb_convert_encoding($raw, 'UTF-8', 'BIG-5,CP950,UTF-8');
        if ($conv !== false) $raw = $conv;
    }
    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
    if (!$lines) return [[], []];

    $head = str_getcsv(array_shift($lines));
    $head = array_map(fn($h) => trim($h, " \t\"'"), $head);

    $data = [];
    foreach ($lines as $l) $data[] = str_getcsv($l);
    return [$head, $data];
}
