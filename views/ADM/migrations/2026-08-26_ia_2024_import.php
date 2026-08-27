<?php
/**
 * 2024（民國113）年度內部稽核紙本紀錄匯入工具
 * ---------------------------------------------------------------------------
 * 來源：EGsystem/FOR CODEING 說明文件/2024內稽/*.docx（使用者提供，2026-08-26 交辦）
 * 用法（CLI，可重複執行）：
 *   php 2026-08-26_ia_2024_import.php --dry        只列出將要建立什麼，不寫入（預設）
 *   php 2026-08-26_ia_2024_import.php --qualify    依 2024 紙本還原稽核員／陪檢員資格名單
 *   php 2026-08-26_ia_2024_import.php --import     匯入 2024 年度紀錄
 *   php 2026-08-26_ia_2024_import.php --tpl        依 2024 通知單自動建立稽核範本
 *   php 2026-08-26_ia_2024_import.php --verify     驗證已匯入的內容
 *   php 2026-08-26_ia_2024_import.php --rollback   只刪除本工具建立的資料
 *
 * 設計重點：
 *  ⑴**只讀寫資料，完全不改 internal_audit 的三支程式檔**（另一個 session 正在改那三支）。
 *  ⑵本工具建立的每一筆都蓋上 IA2024_MARK 標記，--rollback 只刪標記過的，不會誤刪使用者自建的資料。
 *  ⑶**人員一律依該表單的業務日期回推當時職務**（ai-rules/22）——2024 年的稽核員林國棟已於
 *    2024-12-31 離職，用現況查一定查不到人，要用 eg_people_list_asof()。
 *  ⑷部門名稱在 2024 紙本是「課」、現行組織是「部」，對照表寫在 DEPT_MAP 一眼可核對。
 *  ⑸年度計畫表的 ◎（實際實施）**刻意不寫入**——它由「該部門該月有沒有已執行的稽核案件」
 *    即時推導（ia_plan_actual_map），寫進去反而會跟推導結果打架。只寫 ○（計畫實施）。
 *  ⑹稽核報告表（2-GM-06-08）**不匯入**——它是全自動彙總，案件與 IA 單建好就會自己長出來。
 *
 * 已知限制（等另一個 session 的「稽核員多位」改完再重跑本工具即可補上）：
 *   2024 第 2 次稽核通知單的稽核員是**林國棟／葉卿雅兩位**，但目前 ia_case_dept 每個受稽單位
 *   只有單一 auditor_id 欄位。處理方式：紙本有明確證據的單位（業務課＝葉卿雅，見 IA24121601/02
 *   與稽核報告表）存本人；沒有證據的單位存「林國棟／葉卿雅」文字、auditor_id 留空——
 *   **不亂指定其中一位**。改成多位之後重跑本工具即可正規化。
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("僅限 CLI 執行\n"); }

$ROOT = dirname(__DIR__, 3);                      // …/EGsystem
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/people_lib.php';

const IA2024_MARK = 'IA2024匯入';                  // 本工具建立的資料一律蓋這個記號
const IA2024_YEAR = 2024;

/* ============================================================
 * 一、2024 紙本內容（照抄紙本，方便逐字核對；改這裡就能改匯入內容）
 * ============================================================ */

/** 2024 紙本的「課」對現行組織的「部／組」 */
const DEPT_MAP = [
    '管理課'   => '管理部',
    '業務課'   => '業務部',
    '技術課'   => '技術部',
    '生產課'   => '生產部',
    '品保課'   => '品管部',
    '文管中心' => '文管中心',
    '生管組'   => '生管組',
    '倉管組'   => '倉管組',
    '採購組'   => '採購組',
];

/** 稽核員／陪檢員資格名單（依 2024 兩張稽核通知單上實際出現過的人推回來） */
const QUALIFY_2024 = [
    'auditor' => ['林雅婷', '葉卿雅', '林國棟'],                 // 通知單上「稽核員」欄出現過的人
    'escort'  => ['葉卿雅', '何沐桐', '陳彦驊', '林國棟', '吳仁隆', '林鴻銘'],
];

/** 年度計畫表：○＝計畫實施（◎ 由系統即時推導，不寫入） */
const PLAN_2024 = [
    'planned' => [                                  // 月 => 部門（紙本 12 月那一列的 ○）
        12 => ['生管組', '採購組', '倉管組', '管理課'],
    ],
    'maker'      => ['林雅婷', '2024-01-25'],       // 依 2024年度 內部稽核計劃表.jpg
];

const REMARK_2024 =
    "1.稽核員以過程導向由稽核起始主過程開始循序完成所有相關過程；稽核項目除主過程外，應包含其相關管理及支援過程，但跳過自己的直接職務。\n"
  . "2.主過程:客戶需求檢討→開發→訂單/合約審查→生產→倉儲出貨→客戶回饋\n"
  . "　管理過程:包含但不限文件/記錄管理、人力資源訓練、不符合管理、資料分析、內部稽核、矯正/預防措施管理、持續改善、管理責任…等。\n"
  . "　支援過程:包含但不限採購、供應商管理、IQC/FAI/IPQC/FQC、儀器/量具、機器/治具、生管、型態(鑑別追溯)、特殊特性…等。";

/** 兩張稽核通知單（2-GM-06-02） */
const CASES_2024 = [
    [
        'case_no'     => '241115001',
        'seq_no'      => 1,
        'notify_date' => '2024-11-05',
        'audit_from'  => '2024-11-15',
        'audit_to'    => '2024-11-15',
        'leader'      => '林雅婷',
        'auditors'    => ['林雅婷'],                 // 紙本「稽核員」欄
        'process'     => '教育訓練資料　文件留存　生產流程　倉儲出貨　採購流程',
        'end_meet'    => ['2024-11-15', '16:00', '16:30', '二樓會議室'],
        'maker'       => ['葉卿雅', '2024-11-05'],
        // [受稽單位, 陪檢員, 稽核起始主過程, 受稽時間, 預定完成改善時間]
        // 本張 5 個過程對 5 個單位剛好 1:1，故可逐一對應
        // 第 6 欄＝該單位的稽核員；只有一位稽核員時留空即可（自動用 auditors[0]）
        'depts'       => [
            ['管理課',   '葉卿雅', '教育訓練資料', '',      '',           ''],
            ['文管中心', '葉卿雅', '文件留存',     '',      '',           ''],
            ['生管組',   '何沐桐', '生產流程',     '13:15', '2024-12-02', ''],  // 受稽時間與期限取自稽核報告表
            ['倉管組',   '陳彦驊', '倉儲出貨',     '',      '',           ''],
            ['採購組',   '林國棟', '採購流程',     '',      '',           ''],
        ],
    ],
    [
        'case_no'     => '241216001',
        'seq_no'      => 2,
        'notify_date' => '2024-11-05',
        'audit_from'  => '2024-12-16',
        'audit_to'    => '2024-12-16',
        'leader'      => '林雅婷',
        'auditors'    => ['林國棟', '葉卿雅'],       // **兩位**——目前 schema 只存得下第一位
        'process'     => '客戶需求檢討　開發　訂單/合約審查　生產　客戶回饋',
        'end_meet'    => ['2024-12-16', '16:00', '16:30', '二樓會議室'],
        'maker'       => ['葉卿雅', '2024-11-05'],
        // 本張紙本是 5 個過程對 4 個單位（客戶需求檢討／開發／訂單合約審查／生產／客戶回饋），
        // 不是 1:1。2026-08-27 使用者要求要有內容，故依過程性質對應到單位：
        // 業務課＝客戶需求檢討＋訂單/合約審查（都是業務端）、技術課＝開發、生產課＝生產、品保課＝客戶回饋（客訴）。
        // 完整字串仍保留在案件備註，畫面上可自行改。
        // 第 6 欄＝該單位的稽核員。本張有兩位稽核員，紙本只在「業務課」留下明確證據
        // （IA24121601／IA24121602 與稽核報告表都寫葉卿雅）；其餘三個單位紙本沒寫是誰稽核的，
        // **不猜**——留空時下面會存成「林國棟／葉卿雅」兩位並存，列印出來與通知單一致。
        'depts'       => [
            ['業務課', '吳仁隆', '客戶需求檢討、訂單/合約審查', '09:35', '2025-06-30', '葉卿雅'],
            ['技術課', '何沐桐', '開發',      '', '', ''],
            ['生產課', '林鴻銘', '生產',      '', '', ''],
            ['品保課', '陳彦驊', '客戶回饋',  '', '', ''],
        ],
    ],
];

/** 三張內稽不符合通知單（2-GM-06-07）；nc_no 用系統格式 IA+西元後兩碼+MMDD+2位流水 */
const NCS_2024 = [
    [
        'nc_no'      => 'IA24111501',
        'case_no'    => '241115001',
        'dept'       => '生管組',
        'auditee'    => '何沐桐',
        'audit_date' => '2024-11-15',
        'ref_form_no'=> '2-PD-01-07',
        'fact'       => '圖面變更簽收單，新舊版次相同，無法辦識最新版圖面',
        'nc_type'    => 'minor',
        'clause_ref' => '8.2.4 產品與服務的要求的變更',
        'auditor'    => ['葉卿雅', '2024-11-15'],
        'head'       => ['何沐桐', '2024-11-15'],      // 受審查單位主管
        'due_date'   => '2024-12-02',
        'cause'      => '為何新舊版次相同=>因圖面為生產中提出調整製程或是新增尺寸，而非客戶要改版，故無法進版',
        'corrective' => '將不用客戶版次為生產版次，改以設計出圖日期為版本識別，預計2024.12.02完成',
        'preventive' => '圖面版次將以設計出圖日期為版本使用，達到管控版本之目的，預計2024.12.02完成',
        'resp'       => ['何沐桐', '2024-11-22'],       // 責任主管
        'leader'     => ['', ''],                        // 紙本寫 N/A
        'mgr'        => ['林雅婷', '2024-11-22'],        // 管理代表
    ],
    [
        'nc_no'      => 'IA24121601',
        'case_no'    => '241216001',
        'dept'       => '業務課',
        'auditee'    => '吳仁隆',
        'audit_date' => '2024-12-16',
        'ref_form_no'=> '2-SM-02-01',
        'fact'       => '伸宇-客戶編號 GM0001 未依規定編制，碼數錯誤多一碼',
        'nc_type'    => 'minor',
        'clause_ref' => '8.3.3 設計與開發的輸入 d)組織承諾採用的標準及規範',
        'auditor'    => ['葉卿雅', '2024-12-16'],
        'head'       => ['吳仁隆', '2024-12-16'],
        'due_date'   => '2025-06-30',
        'cause'      => '為何伸宇-客戶編號 GM0001 未依規定編制，碼數錯誤多一碼=>參照其他舊有客戶編號做編制',
        'corrective' => '新增正確客戶編號/2024.12.23',
        'preventive' => "1.編碼方式新增至新人專業教育\n2.將程序書內容內的表單，增加實作經驗\n3.預計2025.06.30完成",
        'resp'       => ['吳仁隆', '2024-12-23'],
        'leader'     => ['', ''],
        'mgr'        => ['林雅婷', '2024-12-23'],
    ],
    [
        'nc_no'      => 'IA24121602',
        'case_no'    => '241216001',
        'dept'       => '業務課',
        'auditee'    => '吳仁隆',
        'audit_date' => '2024-12-16',
        'ref_form_no'=> '2-DC-01-04',
        'fact'       => '霄特-外來文件僅有文件通知未有圖面及出貨檢驗表',
        'nc_type'    => 'minor',
        'clause_ref' => '6.2.1 組織應在品質管理系統所需相關部門、層級建立品質目標應維持品質目標的文件化資訊',
        'auditor'    => ['葉卿雅', '2024-12-16'],
        'head'       => ['吳仁隆', '2024-12-16'],
        'due_date'   => '2025-01-03',
        'cause'      => '僅定義文件未理解應包含圖面及出貨檢驗表',
        'corrective' => '把霄特近期訂單的圖面及出貨檢驗表納入外來文件做管理，預計完成時間2025.01.03',
        'preventive' => "1.未來把圖面及出貨檢驗表納入外來文件做管理\n2.預計完成時間:2025.01.03",
        'resp'       => ['吳仁隆', '2024-12-23'],
        'leader'     => ['', ''],
        'mgr'        => ['林雅婷', '2024-12-23'],
    ],
];

/**
 * 稽核範本（起始主過程＋受稽單位＋陪檢員候選部門）
 * 來源就是 2024 兩張稽核通知單上「稽核起始主過程 × 受稽單位」那一列——不是另外一份檔案。
 * 第 1 張 5 個過程對 5 個單位剛好 1:1，直接建；第 2 張 5 個過程對 4 個單位不是 1:1，
 * 只建對得起來的三個（客戶需求檢討/業務課、開發/技術課、生產/生產課），
 * 其餘（訂單合約審查、客戶回饋、品保課）**不猜**，由 --tpl 列出來請使用者自己補。
 * [起始主過程, 受稽單位, 2024 實際陪檢員（用來推陪檢員候選部門）]
 */
const TEMPLATES_2024 = [
    ['教育訓練資料',   '管理課',   '葉卿雅'],
    ['文件留存',       '文管中心', '葉卿雅'],
    ['生產流程',       '生管組',   '何沐桐'],
    ['倉儲出貨',       '倉管組',   '陳彦驊'],
    ['採購流程',       '採購組',   '林國棟'],
    ['客戶需求檢討',   '業務課',   '吳仁隆'],
    ['開發',           '技術課',   '何沐桐'],
    ['生產',           '生產課',   '林鴻銘'],
];
/** 紙本上有、但對不到唯一受稽單位的過程（--tpl 只列出來提醒，不建） */
const TEMPLATES_UNMAPPED = ['訂單/合約審查', '客戶回饋'];

/** 系統稽核紀錄表（2-GM-06-06，2024-12-16）：[序, 表單編號, 表單名稱, 受稽人, ok/ng, 備註] */
const SYSTEM_CHECK_2024 = [
    'case_no'    => '241216001',
    'check_date' => '2024-12-16',
    'auditor'    => '葉卿雅',
    'rows' => [
        ['2-SM-01-02', '報價單',                   '吳仁隆', 'ok', ''],
        ['2-SM-01-05', '客戶訂購單',               '吳仁隆', 'ok', ''],
        ['2-SM-02-01', '客戶基本資料表',           '吳仁隆', 'ng', 'IA24121601'],
        ['2-SM-02-02', '客戶滿意度調查表',         '吳仁隆', 'ok', ''],
        ['2-SM-02-03', '客戶滿意度統計資料表',     '吳仁隆', 'ok', ''],
        ['2-SM-02-04', '客戶滿意度監控表',         '吳仁隆', 'ok', ''],
        ['2-DC-01-04', '外來文件一覽表',           '吳仁隆', 'ng', 'IA24121602'],
        ['2-TD-01-01', '工程變更申請/審查/通知單', '何沐桐', 'ok', ''],
        ['3-TD-01-02', '潛在失效模式及效應分析',   '何沐桐', 'ok', ''],
        ['2-TD-02-01', '產品開發評估表',           '何沐桐', 'ok', ''],
        ['2-PM-01-01', '機器設備一覽表',           '林鴻銘', 'ok', ''],
        ['2-PM-01-03', '機器設備履歷表',           '林鴻銘', 'ok', ''],
        ['3-TD-02-03', '標準作業流程SOP',          '林鴻銘', 'ok', ''],   // as_document 查無此編號，存純文字
        ['2-QA-01-09', '現場檢驗記錄表',           '林鴻銘', 'ok', ''],
        ['2-QA-01-01', '品質異常處理單',           '陳彦驊', 'ok', ''],
        ['2-QA-02-01', '標準檢驗指導書 SIP',       '陳彦驊', 'ok', ''],
        ['3-QA-01-01', '校驗計劃表',               '陳彦驊', 'ok', ''],
        ['3-QA-01-02', '檢驗設備履歷表',           '陳彦驊', 'ok', ''],
        ['3-QA-01-06', '量測室溫、濕度記錄表',     '陳彦驊', 'ok', ''],
    ],
];

/* ============================================================
 * 二、解析工具
 * ============================================================ */

$DB   = (new DBConnection())->getPDO();
$args = array_slice($argv, 1);
$MODE = 'dry';
foreach (['qualify', 'import', 'tpl', 'verify', 'rollback', 'dry'] as $m) {
    if (in_array('--' . $m, $args, true)) { $MODE = $m; break; }
}

function say(string $s = ''): void { echo $s . "\n"; }
function head(string $s): void { say(''); say('=== ' . $s . ' ==='); }

/** 部門名稱（紙本用語）→ department.id */
function dept_id(PDO $db, string $paperName): ?int
{
    static $cache = [];
    if (array_key_exists($paperName, $cache)) return $cache[$paperName];
    $name = DEPT_MAP[$paperName] ?? $paperName;
    $st = $db->prepare("SELECT id FROM department WHERE CONVERT(name USING utf8mb4)=? LIMIT 1");
    $st->execute([$name]);
    $id = $st->fetchColumn();
    return $cache[$paperName] = ($id === false ? null : (int)$id);
}

/**
 * 姓名 → 該業務日期當時的職務（ai-rules/22；2024 的人可能早就離職，一定要用 asof）
 * @return array|null ['id','user_cname','dept_id','dept_name','position_id','position_name']
 */
function person_asof(PDO $db, string $name, string $date): ?array
{
    static $cache = [];
    $k = $name . '@' . $date;
    if (array_key_exists($k, $cache)) return $cache[$k];
    $st = $db->prepare("SELECT id FROM `user` WHERE CONVERT(user_cname USING utf8mb4)=? LIMIT 1");
    $st->execute([$name]);
    $uid = $st->fetchColumn();
    if ($uid === false) return $cache[$k] = null;
    $rows = eg_people_list_asof($db, ['user_ids' => [(int)$uid], 'states' => [1, 2, 3]], $date);
    return $cache[$k] = ($rows ? $rows[0] : null);
}

/** as_document.doc_no → id（查無回 null，呼叫端改存純文字） */
function asdoc_id(PDO $db, string $docNo): ?int
{
    static $cache = [];
    if (array_key_exists($docNo, $cache)) return $cache[$docNo];
    $st = $db->prepare("SELECT id FROM as_document WHERE doc_no=? LIMIT 1");
    $st->execute([$docNo]);
    $id = $st->fetchColumn();
    return $cache[$docNo] = ($id === false ? null : (int)$id);
}

/** 先把所有人名/部門解析一次，有解析不出來的一律先報出來不硬做 */
function preflight(PDO $db): array
{
    $problems = [];
    $depts = [];
    foreach (array_keys(DEPT_MAP) as $p) {
        $id = dept_id($db, $p);
        if ($id === null) $problems[] = "部門對不到：{$p}（對照為 " . DEPT_MAP[$p] . "）";
        else $depts[$p] = $id;
    }
    $people = [];
    foreach (CASES_2024 as $c) {
        $names = array_merge([$c['leader']], $c['auditors'], [$c['maker'][0]], array_column($c['depts'], 1));
        foreach ($names as $n) {
            if ($n === '') continue;
            $p = person_asof($db, $n, $c['audit_from']);
            if (!$p) $problems[] = "人員對不到（{$c['audit_from']} 當時）：{$n}";
            else $people[$n . '@' . $c['audit_from']] = $p;
        }
    }
    foreach (NCS_2024 as $n) {
        foreach ([[$n['auditee']], [$n['auditor'][0]], [$n['head'][0]], [$n['resp'][0]], [$n['mgr'][0]]] as $pair) {
            $nm = $pair[0];
            if ($nm === '') continue;
            if (!person_asof($db, $nm, $n['audit_date'])) $problems[] = "人員對不到（{$n['audit_date']} 當時）：{$nm}";
        }
    }
    return [$depts, $people, $problems];
}

/* ============================================================
 * 三、各動作
 * ============================================================ */

/** 稽核員／陪檢員資格名單：依 2024 紙本重建（認到 人員＋部門＋職稱） */
function do_qualify(PDO $db, bool $write): void
{
    head('稽核員／陪檢員資格名單（依 2024 紙本）');
    $asof = '2024-12-16';                      // 用當年最後一次稽核日回推職務
    $plan = [];
    foreach (QUALIFY_2024 as $kind => $names) {
        foreach ($names as $n) {
            $p = person_asof($db, $n, $asof);
            if (!$p) { say("  ! {$kind} {$n} 在 {$asof} 當時查不到職務，略過"); continue; }
            $plan[$kind][] = $p;
        }
    }
    foreach ($plan as $kind => $rows) {
        $label = $kind === 'auditor' ? '稽核員' : '陪檢員';
        say("  {$label}（" . count($rows) . " 位）：");
        foreach ($rows as $p) {
            say(sprintf('    %-8s %s / %s', $p['user_cname'], $p['dept_name'], $p['position_name'] ?: '（無職稱）'));
        }
    }
    if (!$write) { say('  （--dry：未寫入）'); return; }

    $db->beginTransaction();
    try {
        foreach ($plan as $kind => $rows) {
            $db->prepare("DELETE FROM ia_qualified_person WHERE kind=?")->execute([$kind]);
            $ins = $db->prepare("INSERT INTO ia_qualified_person
                                    (kind,user_id,dept_id,position_id,sort_order,updated_at,updated_by)
                                 VALUES (?,?,?,?,?,NOW(),?)");
            $i = 0;
            foreach ($rows as $p) {
                $ins->execute([$kind, (int)$p['id'], (int)$p['dept_id'], (int)$p['position_id'], ++$i, IA2024_MARK]);
            }
        }
        $db->commit();
        say('  已寫入。');
    } catch (Throwable $e) { $db->rollBack(); say('  寫入失敗：' . $e->getMessage()); }
}

/** 匯入 2024 年度紀錄 */
function do_import(PDO $db, bool $write): void
{
    [$depts, , $problems] = preflight($db);
    if ($problems) {
        head('無法匯入——下列對照解析不出來，請先確認');
        foreach (array_unique($problems) as $p) say('  ! ' . $p);
        return;
    }

    head('將建立的內容');
    say('  年度計畫表 1 張（' . IA2024_YEAR . '；○ 計畫實施 ' . count(PLAN_2024['planned'][12]) . ' 格，◎ 由系統推導不寫入）');
    foreach (CASES_2024 as $c) {
        say("  稽核通知單 {$c['case_no']}（{$c['audit_from']}，受稽單位 " . count($c['depts']) . ' 個，稽核員 '
            . implode('／', $c['auditors']) . '）');
    }
    say('  內稽不符合通知單 ' . count(NCS_2024) . ' 張：' . implode('、', array_column(NCS_2024, 'nc_no')));
    say('  系統稽核紀錄表 1 張（' . count(SYSTEM_CHECK_2024['rows']) . ' 項）');
    say('  稽核報告表：不匯入（系統自動彙總）');
    if (!$write) { say(''); say('  （--dry：未寫入。加 --import 才會真的建立）'); return; }

    $db->beginTransaction();
    try {
        /* ---- 年度計畫表 ---- */
        $mk = person_asof($db, PLAN_2024['maker'][0], PLAN_2024['maker'][1]);
        $db->prepare("INSERT INTO ia_plan (year,title,remark,status,maker_id,maker_name,maker_date,
                        created_by,created_by_name,created_at,is_deleted)
                      VALUES (?,?,?,'approved',?,?,?,?,?,NOW(),0)")
           ->execute([IA2024_YEAR, IA2024_YEAR . ' 年內部稽核計畫表', '○計畫實施　◎實際實施',
                      $mk ? (int)$mk['id'] : null, PLAN_2024['maker'][0], PLAN_2024['maker'][1],
                      0, IA2024_MARK]);
        $planId = (int)$db->lastInsertId();
        $pdIns  = $db->prepare("INSERT INTO ia_plan_dept (plan_id,dept_id,dept_name,sort_order) VALUES (?,?,?,?)");
        $pcIns  = $db->prepare("INSERT INTO ia_plan_cell (plan_id,dept_id,month,planned) VALUES (?,?,?,1)");
        $order  = 0;
        foreach (array_keys(DEPT_MAP) as $paper) {
            $pdIns->execute([$planId, $depts[$paper], DEPT_MAP[$paper], ++$order]);
        }
        foreach (PLAN_2024['planned'] as $month => $list) {
            foreach ($list as $paper) $pcIns->execute([$planId, $depts[$paper], (int)$month]);
        }

        /* ---- 稽核通知單 ---- */
        $caseIdByNo = [];
        foreach (CASES_2024 as $c) {
            $leader = person_asof($db, $c['leader'], $c['audit_from']);
            $maker  = person_asof($db, $c['maker'][0], $c['maker'][1]);
            $note   = REMARK_2024 . "
【稽核起始主過程】" . $c['process'];
            if (count($c['auditors']) > 1) {
                // schema 目前一個受稽單位只有一位稽核員；第二位以後先記在備註不遺失
                $note .= "\n【紙本稽核員】" . implode('／', $c['auditors']);
            }
            $db->prepare("INSERT INTO ia_case (year,seq_no,case_no,notify_date,audit_from,audit_to,
                            leader_id,leader_name,leader_dept_id,leader_position_id,
                            end_meet_date,end_meet_start,end_meet_end,end_meet_place,remark,
                            status,executed,executed_date,maker_id,maker_name,maker_date,
                            created_by,created_by_name,created_at,is_deleted)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'closed',1,?,?,?,?,0,?,NOW(),0)")
               ->execute([IA2024_YEAR, $c['seq_no'], $c['case_no'], $c['notify_date'],
                          $c['audit_from'], $c['audit_to'],
                          $leader ? (int)$leader['id'] : null, $c['leader'],
                          $leader ? (int)$leader['dept_id'] : null, $leader ? (int)$leader['position_id'] : null,
                          $c['end_meet'][0], $c['end_meet'][1], $c['end_meet'][2], $c['end_meet'][3],
                          $note, $c['audit_to'],
                          $maker ? (int)$maker['id'] : null, $c['maker'][0], $c['maker'][1], IA2024_MARK]);
            $caseId = (int)$db->lastInsertId();
            $caseIdByNo[$c['case_no']] = $caseId;

            $allAuditors = implode('／', $c['auditors']);
            $cdIns = $db->prepare("INSERT INTO ia_case_dept (case_id,sort_order,start_process,dept_id,dept_name,
                                     auditor_id,auditor_name,auditor_dept_id,auditor_position_id,
                                     escort_id,escort_name,escort_dept_id,escort_position_id,
                                     audited_date,audited_time,improve_due)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ord = 0;
            foreach ($c['depts'] as [$paperDept, $escortName, $proc, $time, $due, $auditorName]) {
                $es = person_asof($db, $escortName, $c['audit_from']);
                // 該單位的稽核員：紙本有明確證據就用它；只有一位稽核員時就是那一位；
                // 有多位又沒有證據時，兩位並存成文字（auditor_id 留 null），不亂指定其中一位。
                $an = $auditorName !== '' ? $auditorName
                    : (count($c['auditors']) === 1 ? $c['auditors'][0] : $allAuditors);
                $ap = ($an === $allAuditors && count($c['auditors']) > 1)
                    ? null : person_asof($db, $an, $c['audit_from']);
                $cdIns->execute([$caseId, ++$ord, $proc ?: null,
                    $depts[$paperDept], DEPT_MAP[$paperDept] ?? $paperDept,
                    $ap ? (int)$ap['id'] : null, $an,
                    $ap ? (int)$ap['dept_id'] : null, $ap ? (int)$ap['position_id'] : null,
                    $es ? (int)$es['id'] : null, $escortName,
                    $es ? (int)$es['dept_id'] : null, $es ? (int)$es['position_id'] : null,
                    $c['audit_from'], $time ?: null, $due ?: null]);
            }
        }

        /* ---- 內稽不符合通知單（紙本已全部簽完 → stage=closed） ---- */
        foreach (NCS_2024 as $n) {
            $d   = $n['audit_date'];
            $ate = person_asof($db, $n['auditee'], $d);
            $aud = person_asof($db, $n['auditor'][0], $d);
            $hd  = person_asof($db, $n['head'][0], $d);
            $rp  = person_asof($db, $n['resp'][0], $n['resp'][1]);
            $mg  = person_asof($db, $n['mgr'][0], $n['mgr'][1]);
            $db->prepare("INSERT INTO ia_nc (nc_no,case_id,year,dept_id,dept_name,auditee_id,auditee_name,
                            audit_date,src_kind,ref_form_no,fact,nc_type,clause_ref,due_date,
                            auditor_id,auditor_name,auditor_date,head_id,head_name,head_date,
                            cause,corrective,preventive,resp_id,resp_name,resp_date,
                            leader_id,leader_name,leader_date,mgr_note,mgr_id,mgr_name,mgr_date,
                            stage,created_by,created_by_name,created_at,is_deleted)
                          VALUES (?,?,?,?,?,?,?,?,'manual',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                                  ?,?,?,?,?,?,?,'closed',0,?,NOW(),0)")
               ->execute([$n['nc_no'], $caseIdByNo[$n['case_no']] ?? null, IA2024_YEAR,
                          $depts[$n['dept']], DEPT_MAP[$n['dept']] ?? $n['dept'],
                          $ate ? (int)$ate['id'] : null, $n['auditee'], $d,
                          $n['ref_form_no'], $n['fact'], $n['nc_type'], $n['clause_ref'], $n['due_date'],
                          $aud ? (int)$aud['id'] : null, $n['auditor'][0], $n['auditor'][1],
                          $hd ? (int)$hd['id'] : null, $n['head'][0], $n['head'][1],
                          $n['cause'], $n['corrective'], $n['preventive'],
                          $rp ? (int)$rp['id'] : null, $n['resp'][0], $n['resp'][1],
                          null, $n['leader'][0] ?: null, $n['leader'][1] ?: null,
                          '', $mg ? (int)$mg['id'] : null, $n['mgr'][0], $n['mgr'][1],
                          IA2024_MARK]);
        }

        /* ---- 系統稽核紀錄表 ---- */
        $sc  = SYSTEM_CHECK_2024;
        $aud = person_asof($db, $sc['auditor'], $sc['check_date']);
        $db->prepare("INSERT INTO ia_check (case_id,year,kind,title,auditor_id,auditor_name,
                        auditor_dept_id,auditor_position_id,check_date,status,
                        created_by,created_by_name,created_at,is_deleted)
                      VALUES (?,?,'system',?,?,?,?,?,?,'done',0,?,NOW(),0)")
           ->execute([$caseIdByNo[$sc['case_no']] ?? null, IA2024_YEAR, '系統稽核紀錄表',
                      $aud ? (int)$aud['id'] : null, $sc['auditor'],
                      $aud ? (int)$aud['dept_id'] : null, $aud ? (int)$aud['position_id'] : null,
                      $sc['check_date'], IA2024_MARK]);
        $checkId = (int)$db->lastInsertId();
        // 備註欄寫的 IA 單號要真的「連」到那張不符合通知單（nc_id），不能只是一段文字——
        // 2026-08-27 使用者回報：畫面上看起來像還沒開不符合通知單，就是因為 nc_id 是空的，
        // 那一列才會一直顯示「＋開不符合單」。
        $ncIdByNo = [];
        $qn = $db->prepare("SELECT nc_id FROM ia_nc WHERE nc_no=? AND COALESCE(is_deleted,0)=0");
        foreach (NCS_2024 as $n) {
            $qn->execute([$n['nc_no']]);
            $v = $qn->fetchColumn();
            if ($v !== false) $ncIdByNo[$n['nc_no']] = (int)$v;
        }
        $ciIns = $db->prepare("INSERT INTO ia_check_item
                                 (check_id,sort_order,is_header,col_a,col_b,col_c,ref_kind,ref_id,result,remark,nc_id)
                               VALUES (?,?,0,?,?,?,?,?,?,?,?)");
        $i = 0;
        foreach ($sc['rows'] as [$docNo, $docName, $auditee, $result, $remark]) {
            $did = asdoc_id($db, $docNo);
            $ncId = ($remark !== '' && isset($ncIdByNo[$remark])) ? $ncIdByNo[$remark] : null;
            $ciIns->execute([$checkId, ++$i, $docNo, $docName, $auditee,
                             $did ? 'as_document' : null, $did, $result, $remark ?: null, $ncId]);
        }

        $db->commit();
        say('');
        say('  已建立：計畫表 plan_id=' . $planId
            . '、案件 ' . implode('/', $caseIdByNo)
            . '、IA 單 ' . count(NCS_2024) . ' 張、系統稽核紀錄表 check_id=' . $checkId);
    } catch (Throwable $e) {
        $db->rollBack();
        say('  匯入失敗（已全部回滾）：' . $e->getMessage());
    }
}

/** 依 2024 稽核通知單自動建立稽核範本 */
function do_tpl(PDO $db, bool $write): void
{
    require_once dirname(__DIR__, 3) . '/src/common/internal_audit_lib.php';
    head('稽核範本（來源＝2024 稽核通知單的「起始主過程 × 受稽單位」）');

    // 稽核員候選部門＝資格名單上稽核員實際所屬部門（沒有名單時不設限）
    $auditorDepts = [];
    foreach (QUALIFY_2024['auditor'] as $n) {
        $p = person_asof($db, $n, '2024-12-16');
        if ($p) $auditorDepts[(int)$p['dept_id']] = $p['dept_name'];
    }
    if (!$auditorDepts) { say('  ! 稽核員資格名單是空的，請先跑 --qualify'); return; }

    $units = [];
    foreach (ia_audit_units($db) as $u) $units[(int)$u['key']] = $u['name'];

    $plan = [];
    foreach (TEMPLATES_2024 as [$proc, $paperDept, $escortName]) {
        $did = dept_id($db, $paperDept);
        if ($did === null || !isset($units[$did])) {
            say("  ! {$proc} / {$paperDept}：受稽單位對不到（可能被併進其他受稽單位群組），略過");
            continue;
        }
        // 陪檢員候選部門＝受稽單位本身＋2024 實際陪檢員所屬部門（2024 有跨部門陪檢的情形）
        $eDepts = [$did => $units[$did]];
        $ep = person_asof($db, $escortName, '2024-12-16');
        if ($ep) $eDepts[(int)$ep['dept_id']] = $ep['dept_name'];
        $plan[] = ['proc'=>$proc, 'unit_id'=>$did, 'unit'=>$units[$did], 'edepts'=>$eDepts];
        say(sprintf('  %-14s → 受稽單位 %-8s 陪檢員候選：%s', $proc, $units[$did], implode('、', $eDepts)));
    }
    say('  稽核員候選部門（所有範本共用）：' . implode('、', $auditorDepts));
    if (TEMPLATES_UNMAPPED) {
        say('  未建立（紙本過程數與單位數不是 1:1，無法確定對應，請自行補）：'
            . implode('、', TEMPLATES_UNMAPPED));
    }
    if (!$write) { say('  （--dry：未寫入）'); return; }

    $db->beginTransaction();
    try {
        $skip = 0; $made = 0;
        foreach ($plan as $t) {
            // 同一個「起始主過程＋受稽單位」已存在就不重建（本工具可重複執行）
            $q = $db->prepare("SELECT tpl_id FROM ia_process_template WHERE process_name=? AND unit_dept_id=?");
            $q->execute([$t['proc'], $t['unit_id']]);
            if ($q->fetchColumn() !== false) { $skip++; continue; }
            $db->prepare("INSERT INTO ia_process_template (process_name,unit_dept_id,note,sort_order,
                              is_active,updated_at,updated_by)
                          VALUES (?,?,?,(SELECT COALESCE(MAX(s.sort_order),0)+10
                                         FROM (SELECT sort_order FROM ia_process_template) s),1,NOW(),?)")
               ->execute([$t['proc'], $t['unit_id'], '依 2024 稽核通知單自動建立', IA2024_MARK]);
            $tplId = (int)$db->lastInsertId();
            $ins = $db->prepare("INSERT INTO ia_process_tpl_dept (tpl_id,kind,dept_id) VALUES (?,?,?)");
            foreach (array_keys($auditorDepts) as $d) $ins->execute([$tplId, 'auditor', $d]);
            foreach (array_keys($t['edepts'])    as $d) $ins->execute([$tplId, 'escort',  $d]);
            $made++;
        }
        $db->commit();
        say("  已建立 {$made} 個範本" . ($skip ? "（{$skip} 個已存在，略過）" : ''));
    } catch (Throwable $e) { $db->rollBack(); say('  建立失敗：' . $e->getMessage()); }
}

function do_verify(PDO $db): void
{
    head('驗證');
    $q = function (string $sql) use ($db) { return (int)$db->query($sql)->fetchColumn(); };
    $m = "'" . IA2024_MARK . "'";
    say('  年度計畫表：'   . $q("SELECT COUNT(*) FROM ia_plan  WHERE created_by_name=$m") . ' 張（應 1）');
    say('  計畫 ○ 格數：'  . $q("SELECT COUNT(*) FROM ia_plan_cell c JOIN ia_plan p ON p.plan_id=c.plan_id WHERE p.created_by_name=$m") . '（應 4）');
    say('  稽核通知單：'   . $q("SELECT COUNT(*) FROM ia_case  WHERE created_by_name=$m") . ' 張（應 2）');
    say('  受稽單位列：'   . $q("SELECT COUNT(*) FROM ia_case_dept d JOIN ia_case c ON c.case_id=d.case_id WHERE c.created_by_name=$m") . '（應 9）');
    say('  不符合通知單：' . $q("SELECT COUNT(*) FROM ia_nc    WHERE created_by_name=$m") . ' 張（應 3）');
    say('  系統稽核紀錄表：' . $q("SELECT COUNT(*) FROM ia_check WHERE created_by_name=$m") . ' 張（應 1）');
    say('  紀錄表項目：'   . $q("SELECT COUNT(*) FROM ia_check_item i JOIN ia_check k ON k.check_id=i.check_id WHERE k.created_by_name=$m") . '（應 19）');
    say('  資格名單：'     . $q("SELECT COUNT(*) FROM ia_qualified_person WHERE updated_by=$m") . ' 筆（應 9）');
    say('  稽核範本：'     . $q("SELECT COUNT(*) FROM ia_process_template WHERE updated_by=$m") . ' 個（應 8）');
    say('  紀錄表已連到 IA 單的列：' . $q("SELECT COUNT(*) FROM ia_check_item i JOIN ia_check k ON k.check_id=i.check_id
                                            WHERE k.created_by_name=$m AND i.nc_id IS NOT NULL") . '（應 2）');
    say('  起始主過程已填的受稽單位：' . $q("SELECT COUNT(*) FROM ia_case_dept d JOIN ia_case c ON c.case_id=d.case_id
                                              WHERE c.created_by_name=$m AND COALESCE(d.start_process,'')<>''") . '（應 9）');

    head('紙本上有、但目前 schema 存不下的（等「稽核員多位」改完再重跑本工具）');
    $any = false;
    foreach (CASES_2024 as $c) {
        if (count($c['auditors']) > 1) {
            $any = true;
            say("  {$c['case_no']}：稽核員 " . implode('／', $c['auditors']) . ' 共 ' . count($c['auditors']) . ' 位');
            say('    目前 ia_case_dept 每個受稽單位只有單一 auditor_id，處理方式：');
            say('    ・紙本有明確證據的單位 → 存該位本人（可帶出職稱與圖章）');
            say('    ・紙本沒寫是誰稽核的單位 → auditor_name 存「' . implode('／', $c['auditors'])
                . '」兩位並存、auditor_id 留空（列印與通知單一致，不亂指定其中一位）');
            say('    改成多位後重跑本工具即可正規化。');
        }
    }
    if (!$any) say('  （無）');

    head('起始主過程留白、需人工確認的受稽單位');
    $blank = 0;
    foreach (CASES_2024 as $c) {
        foreach ($c['depts'] as [$paperDept, , $proc]) {   // 只看起始主過程
            if ($proc === '') { say("  {$c['case_no']}　{$paperDept}（紙本過程數與單位數不是 1:1，無法確定對應）"); $blank++; }
        }
    }
    if (!$blank) say('  （無）');

    head('系統稽核紀錄表對不到 AS 文件的項目（存純文字，不影響列印）');
    $miss = 0;
    foreach (SYSTEM_CHECK_2024['rows'] as [$docNo, $docName]) {
        if (asdoc_id($db, $docNo) === null) { say("  {$docNo} {$docName}"); $miss++; }
    }
    if (!$miss) say('  （無）');
}

function do_rollback(PDO $db): void
{
    head('回滾（只刪本工具建立的資料）');
    $m = IA2024_MARK;
    $db->beginTransaction();
    try {
        $n = [];
        $st = $db->prepare("SELECT check_id FROM ia_check WHERE created_by_name=?"); $st->execute([$m]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid)
            $db->prepare("DELETE FROM ia_check_item WHERE check_id=?")->execute([$cid]);
        $st = $db->prepare("DELETE FROM ia_check WHERE created_by_name=?"); $st->execute([$m]); $n['系統稽核紀錄表'] = $st->rowCount();
        $st = $db->prepare("DELETE FROM ia_nc WHERE created_by_name=?");    $st->execute([$m]); $n['不符合通知單'] = $st->rowCount();
        $q = $db->prepare("SELECT case_id FROM ia_case WHERE created_by_name=?"); $q->execute([$m]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cid)
            $db->prepare("DELETE FROM ia_case_dept WHERE case_id=?")->execute([$cid]);
        $st = $db->prepare("DELETE FROM ia_case WHERE created_by_name=?");  $st->execute([$m]); $n['稽核通知單'] = $st->rowCount();
        $q = $db->prepare("SELECT plan_id FROM ia_plan WHERE created_by_name=?"); $q->execute([$m]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $db->prepare("DELETE FROM ia_plan_cell WHERE plan_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM ia_plan_dept WHERE plan_id=?")->execute([$pid]);
        }
        $st = $db->prepare("DELETE FROM ia_plan WHERE created_by_name=?");  $st->execute([$m]); $n['年度計畫表'] = $st->rowCount();
        $q = $db->prepare("SELECT tpl_id FROM ia_process_template WHERE updated_by=?"); $q->execute([$m]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $tid)
            $db->prepare("DELETE FROM ia_process_tpl_dept WHERE tpl_id=?")->execute([$tid]);
        $st = $db->prepare("DELETE FROM ia_process_template WHERE updated_by=?"); $st->execute([$m]); $n['稽核範本'] = $st->rowCount();
        $st = $db->prepare("DELETE FROM ia_qualified_person WHERE updated_by=?"); $st->execute([$m]); $n['資格名單'] = $st->rowCount();
        $db->commit();
        foreach ($n as $k => $v) say("  已刪 {$k}：{$v} 筆");
    } catch (Throwable $e) { $db->rollBack(); say('  回滾失敗：' . $e->getMessage()); }
}

/* ============================================================ */

say('2024（民國113）年度內部稽核紙本匯入工具　模式：--' . $MODE);
switch ($MODE) {
    case 'qualify':  do_qualify($DB, true);  break;
    case 'import':   do_import($DB, true);   break;
    case 'tpl':      do_tpl($DB, true);      break;
    case 'verify':   do_verify($DB);         break;
    case 'rollback': do_rollback($DB);       break;
    default:
        do_qualify($DB, false);
        do_import($DB, false);
        do_tpl($DB, false);
        say('');
        say('（以上為預覽。--qualify 還原資格名單、--import 匯入紀錄、--verify 驗證、--rollback 回滾）');
}
say('');
