<?php
/**
 * 料號別名共用庫（2026-07-31 建立）——「一個代號要能唯一對應到我方料號」的唯一實作點
 *
 * 為什麼要有這支（使用者 2026-07-31 定調的現場事實）：
 *   ① 客戶圖面上本來就會印好幾組編號（客戶料號／圖號／流水編號），這些編號多半**沒有建成料號**，
 *      但查詢時都要對得回我方料號 → 群組（把數個料號綁成一組）放不下這種資料。
 *   ② 客戶改料號但沒改圖，新舊其實是同一個東西，兩邊都有建料號 → 要查得到舊料號底下的歷史資料。
 *   ③ ①②混合：同一個料號底下，有些代號有建料號、有些沒有。
 *   ④ 業務報價打的料號與最後訂單料號不同 → 建訂單時要能用訂單料號反查到報價，否則 OP 轉訂單失效。
 *   結論：做成「代號 → 我方主料號」的對照表，需要時再用 linked_d_id 指回那個代號自己的料號。
 *
 * 唯一性規則（使用者選定）：**同一家客戶底下，一個代號只能對應一個料號**。
 *   不同客戶可以各自使用相同字串（兩家都叫 A-100 但實際是不同零件），搜尋時一併列出並標明客戶。
 *   customer_id 為 NULL＝通用代號，通用代號在全庫唯一（MySQL 的 UNIQUE 不管 NULL，故在程式層擋）。
 *
 * 不要各頁自己寫別名 SQL；要查「這個代號是哪個料號」一律走 eg_part_alias_resolve()。
 */

if (!function_exists('eg_part_alias_types')) {
    /** 代號類型：key => 顯示名稱 */
    function eg_part_alias_types(): array {
        return [
            'customer_part' => '客戶料號',
            'drawing'       => '圖面編號',
            'old_part'      => '舊料號',
            'quote_part'    => '報價料號',
            'mask'          => '遮蔽料號（加工單）',
            'other'         => '其他代號',
        ];
    }
}

if (!function_exists('eg_part_alias_type_label')) {
    function eg_part_alias_type_label(?string $type): string {
        $m = eg_part_alias_types();
        return $m[(string)$type] ?? '其他代號';
    }
}

if (!function_exists('eg_part_alias_ensure_table')) {
    /** 建表（冪等）。各頁載入本庫後呼叫一次即可。 */
    function eg_part_alias_ensure_table(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS d_setting_alias (
                alias_id    INT AUTO_INCREMENT PRIMARY KEY,
                d_id        INT NOT NULL COMMENT 'FK → d_setting.d_id（我方主料號）',
                alias_code  VARCHAR(100) NOT NULL COMMENT '客戶料號／圖號／舊料號／報價料號等外部代號',
                alias_type  VARCHAR(20)  NOT NULL DEFAULT 'customer_part' COMMENT 'customer_part/drawing/old_part/quote_part/other',
                customer_id VARCHAR(20)  NULL COMMENT '這個代號是哪家客戶在用；NULL=通用',
                linked_d_id INT          NULL COMMENT '這個代號本身也有建成料號時，指向該料號的 d_id',
                note        VARCHAR(200) NULL,
                sort_order  INT NOT NULL DEFAULT 0,
                created_by  INT NULL,
                created_at  DATETIME NULL,
                UNIQUE KEY uq_code_cust (alias_code, customer_id),
                INDEX idx_d_id (d_id),
                INDEX idx_code (alias_code),
                INDEX idx_linked (linked_d_id)
            ) COMMENT='料號別名：客戶料號/圖號/舊料號/報價料號 → 我方主料號'");
        } catch (Exception $e) { /* 已存在或無權限，交由呼叫端自行處理 */ }
    }
}

if (!function_exists('eg_part_alias_list')) {
    /**
     * 取某料號底下的所有別名（畫面維護區塊用）
     * @return array 每列含 alias_id/alias_code/alias_type/type_label/customer_id/customer_name/linked_d_id/linked_part_no/note
     */
    function eg_part_alias_list(PDO $db, int $d_id): array {
        if ($d_id <= 0) return [];
        try {
            $st = $db->prepare("SELECT a.alias_id, a.alias_code, a.alias_type, a.customer_id, a.linked_d_id, a.note, a.sort_order,
                                       c.customer AS customer_name,
                                       ld.D_Setting_Id AS linked_part_no, lc.customer AS linked_customer_name
                                FROM d_setting_alias a
                                LEFT JOIN customer_list c  ON c.customer_id = a.customer_id
                                LEFT JOIN d_setting     ld ON ld.d_id       = a.linked_d_id
                                LEFT JOIN customer_list lc ON lc.customer_id = ld.Customer_Id
                                WHERE a.d_id = ?
                                ORDER BY a.sort_order, a.alias_id");
            $st->execute([$d_id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) { return []; }
        foreach ($rows as &$r) $r['type_label'] = eg_part_alias_type_label($r['alias_type']);
        unset($r);
        return $rows;
    }
}

if (!function_exists('eg_part_alias_conflict')) {
    /**
     * 檢查某代號能不能掛在 $d_id 底下。
     * @return array|null null=沒問題；有問題回 ['message'=>...]
     */
    function eg_part_alias_conflict(PDO $db, int $d_id, string $code, ?string $customer_id, int $ignore_alias_id = 0): ?array {
        $code = trim($code);
        if ($code === '') return null;
        $cust = ($customer_id === null || trim($customer_id) === '') ? null : trim($customer_id);

        // 同一家客戶（或同為通用）底下，這個代號已經指向別的料號
        $sql = "SELECT a.alias_id, a.d_id, d.D_Setting_Id
                FROM d_setting_alias a LEFT JOIN d_setting d ON d.d_id = a.d_id
                WHERE a.alias_code = ? AND a.alias_id <> ? AND a.d_id <> ? AND "
             . ($cust === null ? "a.customer_id IS NULL" : "a.customer_id = ?");
        $par = [$code, $ignore_alias_id, $d_id];
        if ($cust !== null) $par[] = $cust;
        try {
            $st = $db->prepare($sql); $st->execute($par);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                return ['message' => "代號「{$code}」已經對應到料號「{$r['D_Setting_Id']}」"
                                   . ($cust === null ? '（通用代號）' : '（同一客戶底下）') . '，不可重複指向不同料號'];
            }
        } catch (Exception $e) { return null; }

        // 通用代號（沒指定客戶）與任何客戶專屬代號互斥，避免同字串同時有兩種解讀
        try {
            if ($cust === null) {
                $st = $db->prepare("SELECT d.D_Setting_Id, a.customer_id FROM d_setting_alias a LEFT JOIN d_setting d ON d.d_id=a.d_id
                                    WHERE a.alias_code=? AND a.customer_id IS NOT NULL AND a.alias_id<>? AND a.d_id<>? LIMIT 1");
                $st->execute([$code, $ignore_alias_id, $d_id]);
                if ($r = $st->fetch(PDO::FETCH_ASSOC))
                    return ['message' => "代號「{$code}」已被客戶 {$r['customer_id']} 用在料號「{$r['D_Setting_Id']}」上，請改指定客戶而非通用"];
            }
        } catch (Exception $e) { /* ignore */ }
        return null;
    }
}

if (!function_exists('eg_part_alias_save')) {
    /**
     * 覆寫某料號的別名清單（呼叫端自行包 transaction）。
     * @param array $rows 每列 ['alias_code'=>, 'alias_type'=>, 'customer_id'=>, 'linked_d_id'=>, 'note'=>]
     * @throws Exception 代號衝突時丟出，訊息可直接顯示給使用者
     */
    function eg_part_alias_save(PDO $db, int $d_id, array $rows, ?int $uid = null): void {
        if ($d_id <= 0) return;
        eg_part_alias_ensure_table($db);
        $partNo = '';
        try {
            $q = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
            $q->execute([$d_id]); $partNo = (string)$q->fetchColumn();
        } catch (Exception $e) {}

        $types = eg_part_alias_types();
        $clean = [];
        $seen  = [];
        foreach ($rows as $r) {
            $code = trim((string)($r['alias_code'] ?? ''));
            if ($code === '') continue;
            if ($partNo !== '' && strcasecmp($code, $partNo) === 0)
                throw new Exception("代號「{$code}」與這個料號本身相同，不必登記");
            $type = (string)($r['alias_type'] ?? 'customer_part');
            if (!isset($types[$type])) $type = 'other';
            $cust = trim((string)($r['customer_id'] ?? ''));
            $cust = ($cust === '') ? null : $cust;
            $linked = intval($r['linked_d_id'] ?? 0);
            if ($linked === $d_id) $linked = 0;             // 指向自己沒有意義
            $key = mb_strtolower($code) . '|' . ($cust ?? '');
            if (isset($seen[$key])) continue;                // 同一次送出的重複列
            $seen[$key] = true;
            $c = eg_part_alias_conflict($db, $d_id, $code, $cust);
            if ($c) throw new Exception($c['message']);
            $clean[] = [$code, $type, $cust, $linked ?: null, trim((string)($r['note'] ?? '')) ?: null];
        }

        $db->prepare("DELETE FROM d_setting_alias WHERE d_id=?")->execute([$d_id]);
        $ins = $db->prepare("INSERT INTO d_setting_alias (d_id, alias_code, alias_type, customer_id, linked_d_id, note, sort_order, created_by, created_at)
                             VALUES (?,?,?,?,?,?,?,?,NOW())");
        foreach ($clean as $i => $c) $ins->execute([$d_id, $c[0], $c[1], $c[2], $c[3], $c[4], $i, $uid]);
    }
}

if (!function_exists('eg_part_alias_resolve')) {
    /**
     * 「使用者打了一個代號，那是哪個料號？」——各頁料號搜尋一律走這支。
     * 會同時比對料號本身、圖面代號欄位與別名表，命中的原因寫在 match_reason。
     *
     * @param string $kw          使用者輸入（可為部分字串，內部用 LIKE %kw%）
     * @param array  $opt         customer_id=只限某客戶、limit=筆數上限（預設20）、exact=true 走完全比對
     * @return array 每列 d_id/D_Setting_Id/Customer_Id/client_name/match_code/match_reason/via_alias
     */
    function eg_part_alias_resolve(PDO $db, string $kw, array $opt = []): array {
        $kw = trim($kw);
        if ($kw === '') return [];
        $limit = max(1, min(200, intval($opt['limit'] ?? 20)));
        $cust  = trim((string)($opt['customer_id'] ?? ''));
        $exact = !empty($opt['exact']);
        $term  = $exact ? $kw : ('%' . $kw . '%');
        $op    = $exact ? '=' : 'LIKE';
        $out = [];

        // ① 料號本身／圖面代號
        try {
            $sql = "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Customer_Id, c.customer AS client_name
                    FROM d_setting d LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                    WHERE (d.D_Setting_Id $op ? OR (d.Drawing_No IS NOT NULL AND d.Drawing_No <> '' AND d.Drawing_No $op ?))";
            $par = [$term, $term];
            if ($cust !== '') { $sql .= " AND d.Customer_Id = ?"; $par[] = $cust; }
            $sql .= " LIMIT $limit";
            $st = $db->prepare($sql); $st->execute($par);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hitSelf = $exact ? (strcasecmp($r['D_Setting_Id'], $kw) === 0)
                                  : (mb_stripos($r['D_Setting_Id'], $kw) !== false);
                $r['match_code']   = $hitSelf ? $r['D_Setting_Id'] : (string)$r['Drawing_No'];
                $r['match_reason'] = $hitSelf ? '料號' : '圖面代號';
                $r['via_alias']    = 0;
                $out[(int)$r['d_id']] = $r;
            }
        } catch (Exception $e) { /* ignore */ }

        // ② 別名表（客戶料號／圖號／舊料號／報價料號）
        try {
            $sql = "SELECT d.d_id, d.D_Setting_Id, d.Drawing_No, d.Spec_No, d.Customer_Id, c.customer AS client_name,
                           a.alias_code, a.alias_type, a.customer_id AS alias_customer_id, ac.customer AS alias_customer_name
                    FROM d_setting_alias a
                    JOIN d_setting d ON d.d_id = a.d_id
                    LEFT JOIN customer_list c  ON c.customer_id = d.Customer_Id
                    LEFT JOIN customer_list ac ON ac.customer_id = a.customer_id
                    WHERE a.alias_code $op ?";
            $par = [$term];
            if ($cust !== '') { $sql .= " AND (a.customer_id = ? OR a.customer_id IS NULL)"; $par[] = $cust; }
            $sql .= " LIMIT $limit";
            $st = $db->prepare($sql); $st->execute($par);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $did = (int)$r['d_id'];
                if (isset($out[$did])) continue;              // 料號本身已經命中就不重複列
                $who = $r['alias_customer_name'] ?: ($r['alias_customer_id'] ?: '');
                $r['match_code']   = $r['alias_code'];
                $r['match_reason'] = eg_part_alias_type_label($r['alias_type']) . ($who !== '' ? "（{$who}）" : '');
                $r['via_alias']    = 1;
                $out[$did] = $r;
            }
        } catch (Exception $e) { /* 表還沒建就只回料號本身的結果 */ }

        return array_values($out);
    }
}

if (!function_exists('eg_part_alias_related_dids')) {
    /**
     * 撈歷史資料用：這個料號 + 所有「其實是同一個東西」的料號 d_id。
     * 包含 ①本身 ②別名裡 linked_d_id 指到的料號 ③別的料號把「本料號」登記成別名的那些。
     */
    function eg_part_alias_related_dids(PDO $db, int $d_id): array {
        $ids = [$d_id => true];
        if ($d_id <= 0) return [];
        try {
            $st = $db->prepare("SELECT linked_d_id FROM d_setting_alias WHERE d_id=? AND linked_d_id IS NOT NULL");
            $st->execute([$d_id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $x) $ids[(int)$x] = true;
            $st = $db->prepare("SELECT d_id FROM d_setting_alias WHERE linked_d_id=?");
            $st->execute([$d_id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $x) $ids[(int)$x] = true;
        } catch (Exception $e) { /* ignore */ }
        unset($ids[0]);
        return array_map('intval', array_keys($ids));
    }
}

if (!function_exists('eg_part_alias_mask_code')) {
    /**
     * 加工單用：這個料號對外（給廠商的加工單）要印的「遮蔽料號」。
     * 某客戶的件送特定廠商加工時要遮蔽真實料號，圖面上印的就是這個代號。
     * 沒設定遮蔽料號就回傳原料號（呼叫端不必判斷）。
     * @param string $customer_id 只取這家客戶指定的遮蔽代號；留空＝不分客戶
     */
    function eg_part_alias_mask_code(PDO $db, int $d_id, string $customer_id = ''): string {
        $partNo = '';
        try {
            $q = $db->prepare("SELECT D_Setting_Id FROM d_setting WHERE d_id=?");
            $q->execute([$d_id]); $partNo = (string)$q->fetchColumn();
            $sql = "SELECT alias_code FROM d_setting_alias WHERE d_id=? AND alias_type='mask'";
            $par = [$d_id];
            if ($customer_id !== '') { $sql .= " AND (customer_id=? OR customer_id IS NULL) ORDER BY (customer_id IS NULL)"; $par[] = $customer_id; }
            $sql .= " LIMIT 1";
            $st = $db->prepare($sql); $st->execute($par);
            $mask = (string)$st->fetchColumn();
            if ($mask !== '') return $mask;
        } catch (Exception $e) { /* ignore */ }
        return $partNo;
    }
}

if (!function_exists('eg_part_alias_badges')) {
    /**
     * 一次取多個料號的別名（清單頁顯示「＝客戶XXX的料號」用）
     * @return array d_id => [ ['code'=>,'type_label'=>,'customer'=>], ... ]
     */
    function eg_part_alias_badges(PDO $db, array $dIds): array {
        $dIds = array_values(array_unique(array_filter(array_map('intval', $dIds))));
        if (!$dIds) return [];
        $ph = implode(',', array_fill(0, count($dIds), '?'));
        $out = [];
        try {
            $st = $db->prepare("SELECT a.d_id, a.alias_code, a.alias_type, a.customer_id, c.customer AS customer_name
                                FROM d_setting_alias a LEFT JOIN customer_list c ON c.customer_id=a.customer_id
                                WHERE a.d_id IN ($ph) ORDER BY a.sort_order, a.alias_id");
            $st->execute($dIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['d_id']][] = [
                    'code'       => $r['alias_code'],
                    'type_label' => eg_part_alias_type_label($r['alias_type']),
                    'customer'   => $r['customer_name'] ?: ($r['customer_id'] ?: ''),
                ];
            }
        } catch (Exception $e) { return []; }
        return $out;
    }
}
