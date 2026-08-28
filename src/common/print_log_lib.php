<?php
/**
 * print_log_lib.php — 全站「列印紀錄／簽核紀錄」共用函式庫（2026-08-21 使用者明確要求）
 *
 * 兩件事收斂在這一支：
 *   (1) 列印紀錄：任何頁面按下列印，一律呼叫 eg_print_log_add() 留下
 *       「列印時間／列印人／登入電腦(IP＋電腦名稱)／文件名稱」；
 *   (2) 簽核紀錄：直接讀全站共用的 approval_record（不另存一份，鐵律4），
 *       把 module+entity_id 解析成看得懂的「文件名稱」。
 *
 * 規則見 ai-rules/23-列印與簽核紀錄.md：新模組只要「會列印」或「會簽核」，
 * 就要走這裡自動入帳，禁止各頁自己另開一張列印紀錄表。
 *
 * 自動簽核的紀錄一樣要收，但畫面上絕對不可出現「自動簽核」字樣
 * （使用者明確要求；本檔一律不輸出 is_auto 之類的旗標）。
 */

// ── 資料表 ────────────────────────────────────────────────────────────────
if (!function_exists('eg_print_log_ensure_schema')) {
    function eg_print_log_ensure_schema(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS print_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(40) NOT NULL COMMENT '來源代碼，見 eg_print_sources()',
                doc_kind VARCHAR(16) NOT NULL DEFAULT 'attachment' COMMENT 'attachment=附件檔案 / form=表單報表',
                ref_table VARCHAR(40) NULL COMMENT '文件所屬資料表（附件用）',
                ref_id VARCHAR(40) NULL COMMENT '文件 id（報價/訂單附件在檢視端帶 q/o 前綴，故用字串）',
                doc_name VARCHAR(255) NOT NULL COMMENT '文件名稱（附件檔名或單據標題）',
                part_no VARCHAR(60) NULL COMMENT '相關料號',
                note VARCHAR(255) NULL COMMENT '補充（例：作廢附件）',
                printed_by INT NULL,
                printed_by_name VARCHAR(60) NULL,
                printed_at DATETIME NOT NULL,
                client_ip VARCHAR(45) NULL COMMENT '登入電腦 IP',
                client_host VARCHAR(100) NULL COMMENT '登入電腦名稱（NetBIOS，查不到為 NULL）',
                user_agent VARCHAR(255) NULL,
                KEY idx_pl_at (printed_at),
                KEY idx_pl_user (printed_by, printed_at),
                KEY idx_pl_src (source, printed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='列印紀錄（全站共用，見 ai-rules/23）'");
        } catch (Throwable $e) {}
        // IP→電腦名稱的快取沿用既有的 ip_hostname_cache（audit_log_report.php 在用同一張），
        // 不另建第二張（鐵律4：同一件事只能有一個資料來源）。這裡只確保它存在。
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ip_hostname_cache (
                ip VARCHAR(45) NOT NULL PRIMARY KEY,
                hostname VARCHAR(100) NULL COMMENT '反查到的電腦名稱；查不到存NULL',
                resolved_at DATETIME NOT NULL COMMENT '反查時間'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

// ── 登入電腦：IP 與電腦名稱 ───────────────────────────────────────────────
if (!function_exists('eg_client_ip')) {
    /** 內網直連，不信任 X-Forwarded-For（可偽造），一律取 REMOTE_ADDR */
    function eg_client_ip(): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '::1') $ip = '127.0.0.1';
        if (strpos($ip, '::ffff:') === 0) $ip = substr($ip, 7);   // IPv4-mapped IPv6
        return substr($ip, 0, 45);
    }
}

if (!function_exists('eg_client_host')) {
    /**
     * 由 IP 反查電腦名稱（Windows 內網走 nbtstat）。
     * 查得到快取 30 天、查不到也要快取 1 天——關機／不回應的機器 nbtstat 會等好幾秒，
     * 不快取的話每印一次就卡一次。
     */
    function eg_client_host(PDO $pdo, string $ip): ?string {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
        eg_print_log_ensure_schema($pdo);
        try {
            $st = $pdo->prepare("SELECT hostname, resolved_at FROM ip_hostname_cache WHERE ip=?");
            $st->execute([$ip]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $ttl = $row['hostname'] ? 30 * 86400 : 86400;
                if (time() - strtotime($row['resolved_at']) < $ttl) return $row['hostname'] ?: null;
            }
        } catch (Throwable $e) { return null; }

        $host = eg_resolve_netbios_name($ip);
        try {
            $pdo->prepare("INSERT INTO ip_hostname_cache (ip, hostname, resolved_at) VALUES (?,?,NOW())
                           ON DUPLICATE KEY UPDATE hostname=VALUES(hostname), resolved_at=NOW()")
                ->execute([$ip, $host]);
        } catch (Throwable $e) {}
        return $host;
    }
}

if (!function_exists('eg_resolve_netbios_name')) {
    /**
     * nbtstat -A <ip> 取電腦名稱。
     * 輸出的中文欄位在 Windows 是 Big5、PHP 讀進來是亂碼，所以不可以靠「唯一／群組」那一欄判斷；
     * 改抓 ASCII 的服務代碼：<20>（Server 服務，必為本機名稱）優先，退而取第一個 <00>
     * （第一個 <00> 是電腦名稱，後面的 <00> 才是 WORKGROUP 這類群組名）。
     */
    function eg_resolve_netbios_name(string $ip): ?string {
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) return eg_dns_host_fallback($ip);
        // 伺服器自己這台：nbtstat 問自己會回「找不到主機」（NetBIOS 查詢不繞回本機），
        // 所以直接用本機主機名，不要留白。
        // 一定要連「本機的區網 IP」一起比對：只比 SERVER_ADDR 會漏掉（Apache 可能繫在別的位址），
        // 一漏掉就會退到 DNS 反查、撿到 hosts 檔裡的 host.docker.internal 這種假名字。
        $localName = @gethostname();
        $selfIps = ['127.0.0.1'];
        if (!empty($_SERVER['SERVER_ADDR'])) $selfIps[] = (string)$_SERVER['SERVER_ADDR'];
        if ($localName) {
            $lan = @gethostbynamel($localName);
            if (is_array($lan)) $selfIps = array_merge($selfIps, $lan);
        }
        if (in_array($ip, $selfIps, true)) return $localName ? strtoupper($localName) : null;

        if (!function_exists('exec')) return eg_dns_host_fallback($ip);
        $out = []; $rc = -1;
        @exec('nbtstat -A ' . escapeshellarg($ip) . ' 2>&1', $out, $rc);
        if ($rc !== 0 || !$out) return eg_dns_host_fallback($ip);
        $first00 = null;
        foreach ($out as $line) {
            if (preg_match('/^\s+([A-Za-z0-9_.\-\$]{1,15})\s*<(00|20)>/', $line, $m)) {
                if ($m[2] === '20') return strtoupper($m[1]);
                if ($first00 === null) $first00 = strtoupper($m[1]);
            }
        }
        // NetBIOS 關掉的機器（純 DNS 環境）再試一次 DNS 反查
        return $first00 !== null ? $first00 : eg_dns_host_fallback($ip);
    }
}

if (!function_exists('eg_dns_host_fallback')) {
    /**
     * NetBIOS 查不到時的 DNS 反查。
     * 會過濾掉 hosts 檔常見的假名字（host.docker.internal、localhost…）——那不是使用者的電腦名稱，
     * 印在紀錄上會誤導查核的人，寧可只顯示 IP。
     */
    function eg_dns_host_fallback(string $ip): ?string {
        $r = @gethostbyaddr($ip);
        if (!is_string($r) || $r === '' || $r === $ip) return null;
        if (preg_match('/^(localhost|host\.docker\.internal)$/i', $r)) return null;
        return mb_substr($r, 0, 100);
    }
}

// ── 寫入一筆列印紀錄 ──────────────────────────────────────────────────────
if (!function_exists('eg_print_log_add')) {
    /**
     * $opt: source(必填) / doc_name(必填) / doc_kind / ref_table / ref_id / part_no / note
     *       user_id、user_name 不傳則自動取 session。
     * 回傳 print_log.id；任何失敗都只回 0 不丟例外——列印紀錄壞掉不該擋住使用者列印。
     */
    function eg_print_log_add(PDO $pdo, array $opt): int {
        try {
            eg_print_log_ensure_schema($pdo);
            $source = trim((string)($opt['source'] ?? ''));
            $name   = trim((string)($opt['doc_name'] ?? ''));
            if ($source === '' || $name === '') return 0;

            $uid   = (int)($opt['user_id'] ?? ($_SESSION['id'] ?? 0));
            $uname = trim((string)($opt['user_name'] ?? ''));
            if ($uname === '' && $uid > 0) {
                try {
                    $q = $pdo->prepare("SELECT user_cname FROM user WHERE id=?");
                    $q->execute([$uid]);
                    $uname = (string)($q->fetchColumn() ?: '');
                } catch (Throwable $e) {}
            }
            if ($uname === '') $uname = (string)($_SESSION['userName'] ?? '');

            $ip   = eg_client_ip();
            $host = eg_client_host($pdo, $ip);

            $st = $pdo->prepare("INSERT INTO print_log
                (source, doc_kind, ref_table, ref_id, doc_name, part_no, note,
                 printed_by, printed_by_name, printed_at, client_ip, client_host, user_agent)
                VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,?,?)");
            $st->execute([
                substr($source, 0, 40),
                substr((string)($opt['doc_kind'] ?? 'attachment'), 0, 16),
                ($opt['ref_table'] ?? '') !== '' ? substr((string)$opt['ref_table'], 0, 40) : null,
                ($opt['ref_id'] ?? '')    !== '' ? substr((string)$opt['ref_id'], 0, 40)    : null,
                substr($name, 0, 255),
                ($opt['part_no'] ?? '') !== '' ? substr((string)$opt['part_no'], 0, 60) : null,
                ($opt['note'] ?? '')    !== '' ? substr((string)$opt['note'], 0, 255)   : null,
                $uid ?: null,
                $uname !== '' ? substr($uname, 0, 60) : null,
                $ip !== '' ? $ip : null,
                $host,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

// ── 來源登錄：新的會列印的頁面，加在這裡就會自動出現在紀錄頁的篩選與「涵蓋範圍」說明 ──
if (!function_exists('eg_print_sources')) {
    /** 直接寫進 print_log 的來源（新式，有 IP／電腦名稱） */
    function eg_print_sources(): array {
        return [
            'md_part_attach' => ['label' => '料號主檔－附件檢視', 'page' => 'views/pages/master_data_management.php', 'kind' => 'attachment'],
            'bom_viewer'     => ['label' => 'BOM 檢視器－附件',   'page' => 'views/pm/bom_viewer.php',              'kind' => 'attachment'],
            'part_viewer'    => ['label' => '料號檢視器－附件',   'page' => 'views/pm/part_viewer.php',             'kind' => 'attachment'],
            'image_editor'   => ['label' => '批圖編輯器',         'page' => 'views/Sales/image_editor.php',         'kind' => 'attachment'],
            'internal_audit' => ['label' => '內部稽核－各式表單', 'page' => 'views/ADM/internal_audit.php',         'kind' => 'form'],
            'acc_recon'      => ['label' => '會計－對帳單',       'page' => 'views/ACC/reconcile.php',              'kind' => 'form'],
        ];
    }
}

if (!function_exists('eg_print_legacy_sources')) {
    /**
     * 舊有的各模組專屬列印紀錄表（在本庫出現之前就存在），一律「唯讀彙整」不搬家：
     * 搬家要改動已上線模組、且舊資料的欄位對不齊，風險大於好處（鐵律4）。
     * 這些表沒有 IP／電腦名稱欄位，紀錄頁該兩欄會顯示「—」。
     */
    function eg_print_legacy_sources(): array {
        return [
            'quotation' => [
                'label' => '報價單', 'page' => 'views/Sales/quotation_list_NEW.php', 'table' => 'quotation_print_log',
                'sql' => "SELECT 'quotation' AS source, 'form' AS doc_kind, l.printed_at,
                                 l.printed_by, COALESCE(u.user_cname, CAST(l.printed_by AS CHAR)) AS printed_by_name,
                                 CONCAT('報價單 ', COALESCE(l.quote_no, '')) AS doc_name,
                                 NULL AS part_no, NULL AS client_ip, NULL AS client_host, NULL AS note
                          FROM quotation_print_log l LEFT JOIN user u ON u.id = l.printed_by",
            ],
            'pfmea' => [
                'label' => 'PFMEA 潛在失效模式及效應分析', 'page' => 'views/TD/pfmea.php', 'table' => 'pfmea_print_log',
                'sql' => "SELECT 'pfmea' AS source, 'form' AS doc_kind, l.printed_at,
                                 l.printed_by, COALESCE(l.printed_by_name, u.user_cname) AS printed_by_name,
                                 CONCAT('PFMEA ', COALESCE(d.doc_no, CONCAT('#', l.doc_id))) AS doc_name,
                                 COALESCE(d.part_no_text, ds.D_Setting_Id) AS part_no,
                                 NULL AS client_ip, NULL AS client_host, NULL AS note
                          FROM pfmea_print_log l
                          LEFT JOIN user u ON u.id = l.printed_by
                          LEFT JOIN pfmea_doc d ON d.id = l.doc_id
                          LEFT JOIN d_setting ds ON ds.d_id = d.part_d_id",
            ],
            'td_dev_eval' => [
                'label' => '產品開發評估表', 'page' => 'views/TD/td_dev_eval.php', 'table' => 'td_dev_eval_print_log',
                'sql' => "SELECT 'td_dev_eval' AS source, 'form' AS doc_kind, l.printed_at,
                                 l.printed_by, COALESCE(l.printed_by_name, u.user_cname) AS printed_by_name,
                                 CONCAT('產品開發評估表 ', COALESCE(d.doc_no, CONCAT('#', l.doc_id))) AS doc_name,
                                 COALESCE(d.part_no_text, ds.D_Setting_Id) AS part_no,
                                 NULL AS client_ip, NULL AS client_host, NULL AS note
                          FROM td_dev_eval_print_log l
                          LEFT JOIN user u ON u.id = l.printed_by
                          LEFT JOIN td_dev_eval d ON d.id = l.doc_id
                          LEFT JOIN d_setting ds ON ds.d_id = d.part_d_id",
            ],
            'doc_apply' => [
                'label' => '文件制、修申請單', 'page' => 'views/ADM/doc_apply.php', 'table' => 'doc_apply_print_log',
                'sql' => "SELECT 'doc_apply' AS source, 'form' AS doc_kind, l.printed_at,
                                 l.printed_by, COALESCE(l.printed_name, u.user_cname) AS printed_by_name,
                                 CONCAT('文件制修申請單 ', COALESCE(a.apply_no, CONCAT('#', l.apply_id)),
                                        IFNULL(CONCAT(' ', a.doc_name), '')) AS doc_name,
                                 NULL AS part_no, NULL AS client_ip, NULL AS client_host, NULL AS note
                          FROM doc_apply_print_log l
                          LEFT JOIN user u ON u.id = l.printed_by
                          LEFT JOIN doc_apply a ON a.apply_id = l.apply_id",
            ],
            'form_signer' => [
                'label' => '表單簽核案件', 'page' => 'views/ADM/form_signer.php', 'table' => 'fsd_case_print_log',
                // 綁定的 AS 文件編號/名稱要帶出來（使用者要求）：標題常常只有「1-GM-01 2.0」看不出是什麼文件。
                // 綁定欄位是 link_as_doc_id（逐案挑選的補案件用 as_doc_id），兩個都吃。
                'sql' => "SELECT 'form_signer' AS source, 'form' AS doc_kind, l.printed_at,
                                 l.printed_by, COALESCE(l.printed_by_name, u.user_cname) AS printed_by_name,
                                 " . eg_asdoc_title_sql("COALESCE(NULLIF(c.title,''), CONCAT('簽核案件 #', l.case_id))") . " AS doc_name,
                                 NULL AS part_no, NULL AS client_ip, NULL AS client_host, NULL AS note
                          FROM fsd_case_print_log l
                          LEFT JOIN user u ON u.id = l.printed_by
                          LEFT JOIN fsd_case c ON c.id = l.case_id
                          LEFT JOIN as_document d ON d.id = COALESCE(c.as_doc_id, c.link_as_doc_id)",
            ],
        ];
    }
}

if (!function_exists('eg_asdoc_title_sql')) {
    /**
     * 「案件標題 ＋ 綁定的 AS 文件編號/名稱」怎麼組成一行看得懂的文件名稱（使用者要求 2026-08-21）。
     *
     * 表單簽核案件的標題常常只有「1-GM-01 2.0」這種編號＋版次，看不出是哪一份文件。
     * 綁定的 AS 文件本來就有名稱，帶出來就好。使用者指定的口徑：
     *   **名稱裡已經有 AS 編號就不要重複印編號，但要把該編號的文件名稱顯示出來。**
     *
     * 四種情形（順序有意義）：
     *   ① 沒綁 AS 文件（或編號/名稱是空的）→ 原樣，不動它
     *   ② 標題裡已經有文件名稱 → 原樣（像補案件的「3-GM-01-01-利害關係者溝通記錄表 …」本來就看得懂）
     *   ③ 標題「以編號開頭」→ 把名稱插在編號後面：「1-GM-01 2.0」→「1-GM-01 航太品質手冊 2.0」
     *      比對刻意用「編號＋空白」開頭，不是單純 LOCATE——不然 doc_no `2-DC-01` 會命中
     *      `2-DC-01-02` 這種更長的編號，插進去會把字串弄壞。
     *   ④ 編號出現在標題中間 → 只補名稱在後面，**不重複印編號**（使用者明確要求）
     *   ⑤ 其他 → 前面補上「編號 名稱」
     *
     * @param string $title 標題運算式（已處理過空值退回）
     * @param string $d     as_document 的資料表別名
     */
    function eg_asdoc_title_sql(string $title, string $d = 'd'): string {
        return "CASE
            WHEN $d.doc_no IS NULL OR $d.doc_no = '' OR $d.doc_name IS NULL OR $d.doc_name = '' THEN $title
            WHEN LOCATE($d.doc_name, $title) > 0 THEN $title
            WHEN $title = $d.doc_no OR $title LIKE CONCAT($d.doc_no, ' %')
                 THEN CONCAT($d.doc_no, ' ', $d.doc_name, SUBSTRING($title, CHAR_LENGTH($d.doc_no) + 1))
            WHEN LOCATE($d.doc_no, $title) > 0 THEN CONCAT($title, ' ', $d.doc_name)
            ELSE CONCAT($d.doc_no, ' ', $d.doc_name, ' ', $title)
        END";
    }
}

if (!function_exists('eg_print_all_sources')) {
    /** 新式＋舊式合起來的來源清單（代碼 => label/page/legacy），供篩選下拉與說明使用 */
    function eg_print_all_sources(): array {
        $out = [];
        foreach (eg_print_sources() as $k => $v)        $out[$k] = $v + ['legacy' => false];
        foreach (eg_print_legacy_sources() as $k => $v) {
            // 同一個代碼同時有新舊來源時（例：form_signer 之後也改走 print_log），兩邊都要算
            $out[$k] = ['label' => $v['label'], 'page' => $v['page'], 'kind' => 'form', 'legacy' => true]
                     + ($out[$k] ?? []);
        }
        return $out;
    }
}

// ══ 簽核紀錄 ══════════════════════════════════════════════════════════════
// 資料來源＝全站共用的 approval_record（送審/核准/駁回的事實紀錄，見 approval_lib.php）。
// 這裡只做「module + entity_id → 看得懂的文件名稱」的解析，不另存一份資料（鐵律4）。

if (!function_exists('eg_sign_modules')) {
    /**
     * module 代碼 => 該單據主檔怎麼查。
     *   table/pk  : 主檔資料表與主鍵；沒有主檔的（以年度為 entity_id）留空
     *   name_sql  : 取「文件名稱」的 SQL 運算式（別名固定為 t）
     *   date_sql  : 該單據自己的業務日期；沒有就留 null，改用 approval_record.submitted_at
     * 新模組只要開始寫 approval_record，補一列在這裡就會自動出現在紀錄頁。
     * 沒補的模組不會漏掉，只是文件名稱會退成「模組代碼 #id」（見 eg_sign_resolve_names）。
     */
    function eg_sign_modules(): array {
        // 人事表單的 form_type 存的是代碼（job_desc/skill_assess/competency），直接印出來
        // 使用者根本看不出是哪一張表單。中文名的**唯一來源**是 hr_form_lib.php 的 HRF_FORM_TYPES，
        // 這裡用它現場組出 SQL 的 CASE，不在本檔另外寫一份對照表——寫死的那份會在人家改名後
        // 繼續顯示舊名稱而且不會報錯（鐵律4）。require 放在函式內，沒用到簽核解析的頁面不必付這個成本。
        require_once __DIR__ . '/hr_form_lib.php';
        $hrfCase = 'CASE t.form_type';
        foreach (HRF_FORM_TYPES as $code => $label) {
            $hrfCase .= " WHEN " . var_export((string)$code, true) . " THEN " . var_export((string)$label, true);
        }
        $hrfCase .= ' ELSE t.form_type END';

        return [
            'quotation' => ['label' => '報價單', 'page' => 'views/Sales/quotation_list_NEW.php',
                'table' => 'quotation_list', 'pk' => 'quote_id',
                'name_sql' => "CONCAT('報價單 ', t.quote_no, IFNULL(CONCAT('（', t.client_name, '）'), ''))",
                'date_sql' => 't.quote_date'],
            'quotation_attach' => ['label' => '報價附件補件', 'page' => 'views/Sales/quotation_supplement_view.php',
                'table' => 'quotation_attachments', 'pk' => 'id',
                'name_sql' => "CONCAT('報價附件 ', COALESCE(NULLIF(t.original_name,''), t.filename), '（', t.quote_no, '）')",
                'date_sql' => 'DATE(t.uploaded_at)'],
            'meeting' => ['label' => '會議紀錄', 'page' => 'views/ADM/meeting_record.php',
                'table' => 'meeting_record', 'pk' => 'meeting_id',
                'name_sql' => "CONCAT('會議紀錄 ', COALESCE(NULLIF(t.subject,''), CONCAT('#', t.meeting_id)))",
                'date_sql' => 't.meeting_date'],
            'review_form' => ['label' => '審核表單', 'page' => 'views/ADM/review_form.php',
                'table' => 'rf_instance', 'pk' => 'id',
                'name_sql' => "COALESCE(NULLIF(t.title,''), CONCAT('審核表單 #', t.id))",
                'date_sql' => 't.business_date'],
            'hr_form' => ['label' => '人事表單（職務說明書／技能鑑定／職能鑑定）', 'page' => 'views/ADM/hr_position_forms.php',
                'table' => 'hr_form_instance', 'pk' => 'id',
                // 「專業技能鑑定考核表　黃文德（生產1廠 作業員）－ CNC車床」這種程度才看得出印的是什麼。
                // 職務說明書掛在「職稱」上沒有對應的人（user_cname 是 NULL），這時主體改用職稱，
                // 括號裡就只留部門，不要把職稱印兩次。技能鑑定是一人一機型一張，機型/項目一定要帶出來，
                // 否則同一個人的好幾張表單看起來一模一樣。
                'name_sql' => "CONCAT(
                        {$hrfCase}, ' ',
                        COALESCE(NULLIF(t.user_cname,''), NULLIF(t.position_name,''), CONCAT('#', t.id)),
                        CASE WHEN NULLIF(t.user_cname,'') IS NOT NULL
                             THEN IFNULL(CONCAT('（', NULLIF(CONCAT_WS(' ', NULLIF(t.dept_name,''), NULLIF(t.position_name,'')), ''), '）'), '')
                             ELSE IFNULL(CONCAT('（', NULLIF(t.dept_name,''), '）'), '')
                        END,
                        IFNULL(CONCAT(' － ', NULLIF(COALESCE(NULLIF(t.machine_display_name,''), NULLIF(t.item_name,'')), '')), '')
                    )",
                'date_sql' => 't.business_date'],
            'form_signer' => ['label' => '表單簽核案件', 'page' => 'views/ADM/form_signer.php',
                'table' => 'fsd_case', 'pk' => 'id',
                // 與列印紀錄用同一支 eg_asdoc_title_sql()，兩邊顯示才一致
                'name_sql' => eg_asdoc_title_sql("COALESCE(NULLIF(t.title,''), NULLIF(t.file_name,''), CONCAT('簽核案件 #', t.id))"),
                'join_sql' => "LEFT JOIN as_document d ON d.id = COALESCE(t.as_doc_id, t.link_as_doc_id)",
                'date_sql' => 't.business_date'],
            'eng_change' => ['label' => '工程變更申請單', 'page' => 'views/TD/eng_change.php',
                'table' => 'eng_change', 'pk' => 'ec_id',
                'name_sql' => "CONCAT('工程變更申請單 ', t.doc_no, IFNULL(CONCAT('（', NULLIF(t.part_no,''), '）'), ''))",
                'date_sql' => 't.apply_date'],
            'business_trip' => ['label' => '公出單', 'page' => 'views/ADM/business_trip.php',
                'table' => 'business_trip', 'pk' => 'trip_id',
                'name_sql' => "CONCAT('公出單 ', t.trip_no, IFNULL(CONCAT('（', t.user_name, '）'), ''))",
                'date_sql' => 't.apply_date'],
            'purchase' => ['label' => '申請採購', 'page' => 'views/pages/purchase_request.php',
                'table' => 'purchase_request', 'pk' => 'req_id',
                'name_sql' => "CONCAT('採購申請單 ', t.req_no, IFNULL(CONCAT(' ', t.title), ''))",
                'date_sql' => 'DATE(t.Created_At)'],
            'training_request' => ['label' => '教育訓練需求申請單', 'page' => 'views/ADM/training_record.php',
                'table' => 'training_request', 'pk' => 'request_id',
                'name_sql' => "CONCAT('教育訓練需求申請單 ', COALESCE(NULLIF(t.subject,''), CONCAT('#', t.request_id)))",
                'date_sql' => 't.apply_date'],
            'vendor_audit_sign' => ['label' => '供應商稽核', 'page' => 'views/pm/vendor_audit.php',
                'table' => 'vendor_audit_target', 'pk' => 'target_id',
                'name_sql' => "CONCAT('供應商稽核 ', COALESCE(NULLIF(t.report_no,''), CONCAT('#', t.target_id)), IFNULL(CONCAT('（', m.Maker_Name, '）'), ''))",
                'join_sql' => "LEFT JOIN maker_list m ON m.Maker_Id_No = t.maker_id_no",
                'date_sql' => 't.audit_date'],
            'training_plan' => ['label' => '教育訓練年度計畫', 'page' => 'views/ADM/training_record.php',
                'entity_is_year' => true, 'name_fmt' => '教育訓練年度計畫（%s 年度）'],
            'tool_calib_plan' => ['label' => '量測儀器年度校驗計畫', 'page' => 'views/QC/tool_calibration.php',
                'entity_is_year' => true, 'name_fmt' => '量測儀器年度校驗計畫（%s 年度）'],
            'tool_calib_batch' => ['label' => '量測儀器校驗批次', 'page' => 'views/QC/tool_calibration.php',
                'name_fmt' => '量測儀器校驗批次 #%s'],
            'as_doc_tree' => ['label' => 'AS 文件結構總覽表', 'page' => 'views/ADM/as_document_management.php',
                'name_fmt' => 'AS 文件結構總覽表'],
        ];
    }
}

if (!function_exists('eg_sign_level_label')) {
    /** 簽核關卡代碼轉中文；沒對到的原樣顯示（不寫死一份完整清單，新關卡不會變成空白） */
    function eg_sign_level_label(string $level): string {
        $map = [
            'manager' => '主管核准', 'approve' => '核准', 'approval' => '核准',
            'confirm' => '確認',     'review'  => '審核', 'chair'    => '主席確認',
            'gm'      => '總經理核准',
        ];
        if (isset($map[$level])) return $map[$level];
        if (preg_match('/^stage_(\d+)$/', $level, $m)) return '第 ' . $m[1] . ' 關簽核';
        return $level;
    }
}

if (!function_exists('eg_sign_note_is_internal')) {
    /**
     * 這則簽核意見是不是「內部註記」（補簽核／自動簽核／免審之類）。
     *
     * 使用者明確要求（2026-08-21）：**「（超級管理員補簽核）」這種字樣絕對禁止在前端直接顯示**，
     * 也不可以出現在列印與匯出。原因是那等於在文件上自曝這一關不是本人當場簽的。
     * 要看的話得走「顯示內部註記」按鈕＋管理員的操作確認密碼（見 PrintSignLog_API 的 reveal_note）。
     *
     * 判定用關鍵字比對而不是旗標，因為 approval_record 沒有 is_auto 欄位，這些字串是各模組
     * 寫死在程式裡的（hr_form_lib 的「（超級管理員補簽核）」、form_signer_lib／review_form_lib 的
     * 「（系統自動簽核）」、VendorAudit_API 的「系統自動核可/送審人即簽核人免審」）。
     * 這裡刻意抓寬一點——**誤遮一則正常意見，遠比漏出一則補簽註記安全**。
     */
    function eg_sign_note_is_internal(?string $note): bool {
        $n = trim((string)$note);
        if ($n === '') return false;
        foreach (['補簽', '自動簽核', '自動核可', '系統自動', '免審'] as $kw) {
            if (mb_strpos($n, $kw) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('eg_sign_note_public')) {
    /** 對外（畫面／列印／匯出）要顯示的簽核意見：內部註記一律吃掉，不留任何暗示性文字。 */
    function eg_sign_note_public(?string $note): string {
        return eg_sign_note_is_internal($note) ? '' : trim((string)$note);
    }
}

if (!function_exists('eg_sign_result_label')) {
    /** 簽核結果；pending 一律顯示「待簽核」（不用「未處理」以免跟退回混淆） */
    function eg_sign_result_label(string $status): string {
        return ['approved' => '許可', 'rejected' => '不許可', 'pending' => '待簽核'][$status] ?? $status;
    }
}

if (!function_exists('eg_sign_resolve_names')) {
    /**
     * 批次把 approval_record 列解析出 doc_name／doc_date（每個模組一次查詢，不逐列查）。
     * $rows 需含 module、entity_id；回傳原陣列並補上 doc_name、doc_date、module_label、level_label、result_label。
     */
    function eg_sign_resolve_names(PDO $pdo, array $rows): array {
        if (!$rows) return $rows;
        $reg = eg_sign_modules();
        $byMod = [];
        foreach ($rows as $i => $r) $byMod[(string)$r['module']][] = $i;

        foreach ($byMod as $mod => $idxs) {
            $cfg = $reg[$mod] ?? null;
            $ids = array_values(array_unique(array_map(fn($i) => (int)$rows[$i]['entity_id'], $idxs)));
            $nameById = [];
            $dateById = [];
            if ($cfg && !empty($cfg['table']) && $ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $dateSel = !empty($cfg['date_sql']) ? $cfg['date_sql'] : 'NULL';
                $sql = "SELECT t.{$cfg['pk']} AS _k, {$cfg['name_sql']} AS _n, {$dateSel} AS _d
                        FROM {$cfg['table']} t " . ($cfg['join_sql'] ?? '') . "
                        WHERE t.{$cfg['pk']} IN ($ph)";
                try {
                    $st = $pdo->prepare($sql);
                    $st->execute($ids);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
                        $nameById[(string)$x['_k']] = (string)$x['_n'];
                        $dateById[(string)$x['_k']] = $x['_d'] ? substr((string)$x['_d'], 0, 10) : null;
                    }
                } catch (Throwable $e) {}
            }
            foreach ($idxs as $i) {
                $eid = (string)(int)$rows[$i]['entity_id'];
                $name = $nameById[$eid] ?? null;
                if ($name === null && $cfg && !empty($cfg['name_fmt'])) {
                    $name = strpos($cfg['name_fmt'], '%s') !== false ? sprintf($cfg['name_fmt'], $eid) : $cfg['name_fmt'];
                }
                // 兩種退回情形要講清楚，不要一律只丟「代碼 #編號」讓使用者自己猜：
                //   ① 有登錄主檔但查不到那一列＝單據已被刪除（簽核紀錄本身要保留可追溯性，所以不會跟著消失）
                //   ② 根本沒登錄在 eg_sign_modules()＝新模組還沒補進登錄表
                if ($name === null || $name === '') {
                    $name = ($cfg['label'] ?? $mod) . ' #' . $eid
                          . (($cfg && !empty($cfg['table'])) ? '（單據已刪除）' : '');
                }
                $rows[$i]['doc_name']     = $name;
                $rows[$i]['doc_date']     = $dateById[$eid] ?? null;
                $rows[$i]['module_label'] = $cfg['label'] ?? $mod;
                $rows[$i]['level_label']  = eg_sign_level_label((string)$rows[$i]['level']);
                $rows[$i]['result_label'] = eg_sign_result_label((string)$rows[$i]['status']);
            }
        }
        return $rows;
    }
}

// ══ 涵蓋範圍自我檢查（給頁面的「使用說明」用）═════════════════════════════
// 一律即時算出來，不放一份寫死的清單——寫死的清單會在有人新增頁面後默默過期（鐵律4）。

if (!function_exists('eg_print_coverage')) {
    /**
     * 掃 views/ 底下所有 .php：
     *   有列印動作（window.print / contentWindow.print / egPrintWindow）＝這頁會列印
     *   內含 EGPrintLog. 或 eg_print_log_add(  ＝已經有掛列印紀錄
     *   在 eg_print_legacy_sources() 的 page 清單內 ＝有自己的舊列印紀錄表，也算涵蓋
     * 回傳 ['covered'=>[...], 'uncovered'=>[...]]，每列 ['page'=>..., 'label'=>..., 'via'=>...]
     */
    function eg_print_coverage(string $root): array {
        $legacyByPage = [];
        foreach (eg_print_legacy_sources() as $k => $v) $legacyByPage[$v['page']] = $v['label'];
        $newByPage = [];
        foreach (eg_print_sources() as $k => $v) $newByPage[$v['page']] = $v['label'];

        $covered = $uncovered = [];
        $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'views';
        if (!is_dir($dir)) return ['covered' => [], 'uncovered' => []];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($root, '/\\')) + 1));
            if (strpos($rel, '_封存') !== false) continue;   // 已封存的舊校務系統檔案不算
            $src = @file_get_contents($f->getPathname());
            if ($src === false) continue;
            $canPrint = (strpos($src, 'window.print()') !== false)
                     || (strpos($src, 'contentWindow.print()') !== false)
                     || (strpos($src, 'egPrintWindow') !== false);
            if (!$canPrint) continue;
            // 判定「已掛紀錄」看的是檔案裡真的有呼叫紀錄程式，不是看它有沒有登錄在 eg_print_sources()——
            // 只憑登錄表判定，會在登錄了卻忘了掛的情況下自稱已涵蓋，說明頁就變成假的。
            if (strpos($src, 'EGPrintLog') !== false || strpos($src, 'eg_print_log_add(') !== false) {
                $covered[] = ['page' => $rel, 'label' => $newByPage[$rel] ?? basename($rel), 'via' => 'print_log'];
            } elseif (isset($legacyByPage[$rel])) {
                $covered[] = ['page' => $rel, 'label' => $legacyByPage[$rel], 'via' => 'legacy'];
            } else {
                $uncovered[] = ['page' => $rel, 'label' => basename($rel), 'via' => ''];
            }
        }
        usort($covered,   fn($a, $b) => strcmp($a['page'], $b['page']));
        usort($uncovered, fn($a, $b) => strcmp($a['page'], $b['page']));
        return ['covered' => $covered, 'uncovered' => $uncovered];
    }
}

if (!function_exists('eg_sign_coverage')) {
    /**
     * 已涵蓋＝approval_record 裡實際出現過的 module（即時查，新模組一開始寫就會自己冒出來）
     *          ＋ eg_sign_modules() 登錄過但還沒有資料的模組。
     * 未涵蓋＝資料庫裡其他「看起來是簽核紀錄」的資料表（表名含 sign／approval／cosign／signoff），
     *          這些模組自己存自己的，沒有寫進共用的 approval_record。
     */
    function eg_sign_coverage(PDO $pdo): array {
        $reg = eg_sign_modules();
        $used = [];
        try {
            $st = $pdo->query("SELECT module, COUNT(*) c FROM approval_record GROUP BY module");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $used[$r['module']] = (int)$r['c'];
        } catch (Throwable $e) {}
        $covered = [];
        foreach ($reg as $k => $v) {
            $covered[$k] = ['module' => $k, 'label' => $v['label'], 'page' => $v['page'] ?? '', 'rows' => $used[$k] ?? 0];
        }
        foreach ($used as $k => $c) {
            if (!isset($covered[$k])) $covered[$k] = ['module' => $k, 'label' => $k, 'page' => '', 'rows' => $c];
        }
        ksort($covered);

        $uncovered = [];
        try {
            $st = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS, TABLE_COMMENT FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME <> 'approval_record'
                                 AND TABLE_NAME REGEXP 'signoff|signature|cosign|approval|_sign$|_sign_'
                                 AND TABLE_NAME NOT REGEXP 'design|assign|attendee'
                               ORDER BY TABLE_NAME");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // 設定類的表（預設值、槽位設定）不是簽核事實，不列。
                // 另外 design_notes／roster_assignment／training_attendee_day_sign 這類名稱裡
                // 剛好有 sign 字母的（design/assign/簽到）已在 SQL 端排除，不是簽核紀錄。
                if (preg_match('/(default|setting|designer|stage_signer)$/i', $r['TABLE_NAME'])) continue;
                $uncovered[] = ['table' => $r['TABLE_NAME'], 'rows' => (int)$r['TABLE_ROWS'], 'note' => (string)$r['TABLE_COMMENT']];
            }
        } catch (Throwable $e) {}
        return ['covered' => array_values($covered), 'uncovered' => $uncovered];
    }
}


// ══ 權限 ══════════════════════════════════════════════════════════════════
// 兩級：psl_view_all＝看得到全部人的紀錄；psl_admin＝另可匯出／管理。
// 沒有角色的在職員工仍看得到「自己的」紀錄（自己印過什麼、自己簽過什麼）——
// 這是查自己的足跡，不是查別人。
if (!function_exists('eg_printlog_current_user')) {
    function eg_printlog_current_user(PDO $db): ?array {
        $uname = $_SESSION['userName'] ?? '';
        if ($uname === '') return null;
        try {
            $st = $db->prepare("SELECT id, user_cname, user_status, state FROM `user` WHERE user_uname=?");
            $st->execute([$uname]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('eg_printlog_perms')) {
    function eg_printlog_perms(PDO $db, ?array $u): array {
        if (!$u) return ['isAdmin' => false, 'canAdmin' => false, 'canViewAll' => false, 'uid' => 0];
        $uid = (int)$u['id'];
        $isAdmin = in_array((int)($u['user_status'] ?? 0), [9, 90], true);
        if (!$isAdmin) {
            try {
                $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                    WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
                $st->execute([$uid]);
                $isAdmin = (bool)$st->fetchColumn();
            } catch (Throwable $e) {}
        }
        $has = function (array $codes) use ($db, $uid) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            try {
                $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                    WHERE ur.user_id=? AND r.module='print_sign_log' AND r.role_code IN ($in) LIMIT 1");
                $st->execute(array_merge([$uid], $codes));
                return (bool)$st->fetchColumn();
            } catch (Throwable $e) { return false; }
        };
        $canAdmin   = $isAdmin || $has(['psl_admin']);
        $canViewAll = $canAdmin || $has(['psl_view_all']);
        return ['isAdmin' => $isAdmin, 'canAdmin' => $canAdmin, 'canViewAll' => $canViewAll, 'uid' => $uid];
    }
}

// ══ 查詢 ══════════════════════════════════════════════════════════════════
if (!function_exists('eg_printlog_union_sql')) {
    /** print_log 與各舊列印紀錄表併成同一組欄位；$onlySource 非空時只取該來源（省掉整段 UNION） */
    function eg_printlog_union_sql(string $onlySource = ''): string {
        $parts = [];
        if ($onlySource === '' || isset(eg_print_sources()[$onlySource])) {
            $parts[] = "SELECT p.source, p.doc_kind, p.printed_at, p.printed_by,
                               COALESCE(p.printed_by_name, u.user_cname) AS printed_by_name,
                               p.doc_name, p.part_no, p.client_ip, p.client_host, p.note
                        FROM print_log p LEFT JOIN user u ON u.id = p.printed_by";
        }
        foreach (eg_print_legacy_sources() as $code => $cfg) {
            if ($onlySource !== '' && $onlySource !== $code) continue;
            $parts[] = $cfg['sql'];
        }
        if (!$parts) {
            $parts[] = "SELECT NULL AS source, NULL AS doc_kind, NULL AS printed_at, NULL AS printed_by,
                               NULL AS printed_by_name, NULL AS doc_name, NULL AS part_no,
                               NULL AS client_ip, NULL AS client_host, NULL AS note FROM DUAL WHERE 0";
        }
        return '(' . implode(' UNION ALL ', $parts) . ')';
    }
}

if (!function_exists('eg_printlog_query')) {
    /**
     * 列印紀錄查詢。
     * $f: source / user_id / date_from / date_to / kw / page / per（per=0＝全部，供列印與匯出用）
     * 回傳 ['rows'=>[], 'total'=>int]
     */
    function eg_printlog_query(PDO $db, array $f): array {
        eg_print_log_ensure_schema($db);
        $where = []; $args = [];
        if (!empty($f['source']))    { $where[] = 'x.source = ?';      $args[] = $f['source']; }
        if (!empty($f['user_id']))   { $where[] = 'x.printed_by = ?';  $args[] = (int)$f['user_id']; }
        if (!empty($f['date_from'])) { $where[] = 'x.printed_at >= ?'; $args[] = $f['date_from'] . ' 00:00:00'; }
        if (!empty($f['date_to']))   { $where[] = 'x.printed_at <= ?'; $args[] = $f['date_to'] . ' 23:59:59'; }
        if (!empty($f['kw'])) {
            foreach (preg_split('/\s+/', trim((string)$f['kw'])) as $w) {
                if ($w === '') continue;
                $where[] = '(x.doc_name LIKE ? OR x.part_no LIKE ? OR x.printed_by_name LIKE ? OR x.client_host LIKE ? OR x.client_ip LIKE ?)';
                for ($i = 0; $i < 5; $i++) $args[] = '%' . $w . '%';
            }
        }
        $base = eg_printlog_union_sql('') . ' x';
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $cnt = $db->prepare("SELECT COUNT(*) FROM $base$w");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();

        $sql = "SELECT * FROM $base$w ORDER BY x.printed_at DESC";
        $per = (int)($f['per'] ?? 20);
        if ($per > 0) {
            $page = max(1, (int)($f['page'] ?? 1));
            $sql .= ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);
        }
        $st = $db->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $srcs = eg_print_all_sources();
        foreach ($rows as &$r) { $r['source_label'] = $srcs[$r['source']]['label'] ?? $r['source']; }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('eg_signlog_query')) {
    /**
     * 簽核紀錄查詢（資料來源＝全站共用的 approval_record，含自動簽核產生的紀錄）。
     * $f: module / user_id（比對簽核人；還沒簽的列改比對送出者）/ date_from / date_to / kw / page / per
     * 日期區間：送件日期或簽核日期任一落在區間內都算（使用者要看的是這段期間發生的事）。
     */
    /**
     * 「原始單據已經被刪掉」的簽核紀錄要排除的 WHERE 片段（使用者要求 2026-08-21：那幾列不要顯示）。
     *
     * 為什麼在 SQL 做而不是撈回來再濾：**筆數與分頁**。在 PHP 端濾掉的話，第 1 頁會只剩七、八筆，
     * 總筆數也跟實際列出的對不起來。
     *
     * 表名與主鍵取自 eg_sign_modules() 這份**程式自己的登錄表**（不是使用者輸入），
     * 仍然過一次字元白名單，免得日後有人在登錄表裡貼進奇怪的字串；module 代碼走 PDO::quote。
     * 沒登錄主檔的模組（以年度為 entity_id 的那種）不在此列——那種查不到主檔是正常的，不算已刪除。
     */
    function eg_signlog_orphan_sql(PDO $db): string {
        $parts = [];
        foreach (eg_sign_modules() as $code => $cfg) {
            if (empty($cfg['table']) || empty($cfg['pk'])) continue;
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$cfg['table'])) continue;
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$cfg['pk'])) continue;
            $parts[] = '(a.module = ' . $db->quote((string)$code) . ' AND NOT EXISTS ('
                     . "SELECT 1 FROM `{$cfg['table']}` t WHERE t.`{$cfg['pk']}` = a.entity_id))";
        }
        return $parts ? ('NOT (' . implode(' OR ', $parts) . ')') : '';
    }

    function eg_signlog_query(PDO $db, array $f): array {
        $where = []; $args = [];
        // 原始單據已刪除的紀錄預設不列出；要查的人自己勾「含已刪除單據」
        if (empty($f['include_deleted'])) {
            $orphan = eg_signlog_orphan_sql($db);
            if ($orphan !== '') $where[] = $orphan;
        }
        if (!empty($f['module']))  { $where[] = 'a.module = ?'; $args[] = $f['module']; }
        if (!empty($f['user_id'])) {
            $where[] = '(a.approver_id = ? OR (a.approver_id IS NULL AND a.submitted_by = ?))';
            $args[] = (int)$f['user_id']; $args[] = (int)$f['user_id'];
        }
        if (!empty($f['date_from'])) {
            $where[] = '(a.submitted_at >= ? OR a.decided_at >= ?)';
            $args[] = $f['date_from'] . ' 00:00:00'; $args[] = $f['date_from'] . ' 00:00:00';
        }
        if (!empty($f['date_to'])) {
            $where[] = '(a.submitted_at <= ? OR a.decided_at <= ?)';
            $args[] = $f['date_to'] . ' 23:59:59'; $args[] = $f['date_to'] . ' 23:59:59';
        }
        if (!empty($f['kw'])) {
            foreach (preg_split('/\s+/', trim((string)$f['kw'])) as $w) {
                if ($w === '') continue;
                $where[] = '(a.approver_name LIKE ? OR a.submitted_by_name LIKE ? OR a.note LIKE ? OR a.module LIKE ?)';
                for ($i = 0; $i < 4; $i++) $args[] = '%' . $w . '%';
            }
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $cnt = $db->prepare("SELECT COUNT(*) FROM approval_record a$w");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();

        $sql = "SELECT a.* FROM approval_record a$w ORDER BY COALESCE(a.decided_at, a.submitted_at) DESC, a.id DESC";
        $per = (int)($f['per'] ?? 20);
        if ($per > 0) {
            $page = max(1, (int)($f['page'] ?? 1));
            $sql .= ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);
        }
        $st = $db->prepare($sql);
        $st->execute($args);
        $rows = eg_sign_resolve_names($db, $st->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => $total];
    }
}

if (!function_exists('eg_printlog_people')) {
    /**
     * 篩選用的人員清單＝「實際在紀錄裡出現過的人」。
     * 這裡刻意不套 people_lib 的在職過濾：舊紀錄的當事人離職了，那筆紀錄還是要查得到，
     * 濾掉在職狀態會讓歷史資料變成查不到人（人員列表鐵則規範的是「要指派誰」的名單，不是查歷史）。
     * 排序仍依鐵則：部門 sort_order → 職稱 sort_order → 姓名。
     */
    function eg_printlog_people(PDO $db, array $extraIds = []): array {
        eg_print_log_ensure_schema($db);
        $ids = [];
        // $extraIds：其他分頁（如檢驗作業）出現過、但沒有列印／簽核紀錄的人，
        // 不帶進來的話那些人不會出現在人員下拉裡（純新增參數，既有呼叫端不受影響）
        foreach ($extraIds as $x) { if ((int)$x > 0) $ids[(int)$x] = true; }
        $collect = function (string $sql) use ($db, &$ids) {
            try {
                foreach ($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $x) $ids[(int)$x] = true;
            } catch (Throwable $e) {}
        };
        $collect("SELECT DISTINCT printed_by FROM print_log WHERE printed_by IS NOT NULL");
        foreach (eg_print_legacy_sources() as $cfg) {
            $collect("SELECT DISTINCT printed_by FROM {$cfg['table']} WHERE printed_by IS NOT NULL");
        }
        $collect("SELECT DISTINCT approver_id FROM approval_record WHERE approver_id IS NOT NULL");
        $collect("SELECT DISTINCT submitted_by FROM approval_record WHERE submitted_by IS NOT NULL");
        unset($ids[0]);
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            // 部門／職稱不在 user 表上，掛在 user_department_position_map（一人可兼多職）；
            // 顯示哪一筆比照 people_lib.php：取職級最高的那筆，兼任常才是真正的職務身分。
            $st = $db->prepare("SELECT u.id, u.user_cname, u.state,
                                       d.name AS dept_name, p.name AS position_name,
                                       COALESCE(d.sort_order, 999) AS d_sort, COALESCE(p.sort_order, 999) AS p_sort
                                FROM `user` u
                                LEFT JOIN user_department_position_map m ON m.id = (
                                    SELECT m2.id FROM user_department_position_map m2
                                    LEFT JOIN position p2 ON p2.id = m2.position_id
                                    WHERE m2.user_id = u.id
                                    ORDER BY COALESCE(p2.sort_order, 999) ASC, m2.is_main DESC, m2.id ASC LIMIT 1)
                                LEFT JOIN department d ON d.id = m.department_id
                                LEFT JOIN position p ON p.id = m.position_id
                                WHERE u.id IN ($in)
                                ORDER BY d_sort, p_sort, CONVERT(u.user_cname USING utf8mb4)");
            $st->execute(array_keys($ids));
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
