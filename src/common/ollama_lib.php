<?php
/**
 * AI 草稿（本機 Ollama）—— 2026-10-16 建立
 *
 * 用途：幫「全站管理員」在寫因應措施、成效確認方式、溝通記錄回覆、追蹤回應措施、
 *       內稽／管審會議要項時先給一份草稿，填入後仍由人修改。
 * 原則：
 *   ① 只給管理員用（eg_ai_is_admin）；一般使用者連按鈕都不輸出，後端也擋。
 *   ② 數字、客戶、廠商、日期、負責人一律由系統真實資料決定並在提示詞裡限定範圍；
 *      模型只寫「文字」。草稿一律標明是草稿，由人確認，不自動存檔。
 *   ③ 連線只打本機 Ollama（預設 http://127.0.0.1:11434），不對外連線。
 * 設定存 system_parameters(param_group='AI_DRAFT')：enabled／model／url。
 */

const EG_AI_GROUP = 'AI_DRAFT';

function eg_ai_setting(PDO $db, string $key, string $default = ''): string
{
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group=? AND param_key=?");
        $st->execute([EG_AI_GROUP, $key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}
function eg_ai_setting_save(PDO $db, string $key, string $value): void
{
    $st = $db->prepare("SELECT 1 FROM system_parameters WHERE param_group=? AND param_key=?");
    $st->execute([EG_AI_GROUP, $key]);
    if ($st->fetchColumn()) $db->prepare("UPDATE system_parameters SET param_value=? WHERE param_group=? AND param_key=?")->execute([$value, EG_AI_GROUP, $key]);
    else $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value) VALUES (?,?,?)")->execute([EG_AI_GROUP, $key, $value]);
}

/** 全站管理員（與 rm_perms 的 isAdmin 同一個判定：admin／superadmin 角色，或帳號 id=1） */
function eg_ai_is_admin(PDO $db, ?array $u): bool
{
    if (!$u) return false;
    $uid = (int)$u['id'];
    if ((int)($u['state'] ?? 0) === 0 || (int)($u['user_status'] ?? 0) === 90) return false;
    if ($uid === 1) return true;
    try {
        $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=? AND r.role_code IN ('admin','superadmin') LIMIT 1");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function eg_ai_url(PDO $db): string { return rtrim(eg_ai_setting($db, 'url', 'http://127.0.0.1:11434'), '/'); }

/** 本機已安裝的模型名稱；連不上回 null */
function eg_ai_models(PDO $db): ?array
{
    $ch = curl_init(eg_ai_url($db) . '/api/tags');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($r === false || $code !== 200) return null;
    $j = json_decode((string)$r, true);
    $out = [];
    foreach (($j['models'] ?? []) as $m) $out[] = (string)$m['name'];
    return $out;
}

/** 預設模型：設定值（若已安裝）→ qwen2.5 系列 → gemma3 → llama3 */
function eg_ai_pick_model(PDO $db, ?array $installed = null): string
{
    $installed = $installed ?? (eg_ai_models($db) ?: []);
    $want = eg_ai_setting($db, 'model', '');
    if ($want !== '' && in_array($want, $installed, true)) return $want;
    foreach (['qwen2.5', 'gemma3', 'llama3:latest'] as $pre) foreach ($installed as $m) if (strpos($m, $pre) === 0) return $m;
    return $installed[0] ?? ($want ?: 'qwen2.5:7b');
}

/** 呼叫 Ollama 聊天介面，回傳純文字（失敗丟例外） */
function eg_ai_chat(PDO $db, string $system, string $user, array $opt = []): string
{
    $model = eg_ai_pick_model($db);
    $body = json_encode([
        'model' => $model, 'stream' => false, 'keep_alive' => '15m',
        'options' => ['temperature' => $opt['temperature'] ?? 0.4, 'num_predict' => $opt['max_tokens'] ?? 500, 'num_ctx' => 4096],
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init(eg_ai_url($db) . '/api/chat');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => (int)($opt['timeout'] ?? 150), CURLOPT_CONNECTTIMEOUT => 3]);
    $r = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($r === false) throw new RuntimeException('連不上 Ollama：' . $err);
    $j = json_decode((string)$r, true);
    if ($code !== 200 || !is_array($j)) throw new RuntimeException('Ollama 回應異常（HTTP ' . $code . '）' . (is_array($j) && !empty($j['error']) ? '：' . $j['error'] : ''));
    return eg_ai_clean((string)($j['message']['content'] ?? ''));
}

/** 去掉模型常見的多餘包裝：程式碼框、「好的，以下是…」開場、前後空白 */
function eg_ai_clean(string $t): string
{
    $t = trim($t);
    $t = preg_replace('/^```[a-z]*\s*|\s*```$/u', '', $t);
    $lines = preg_split('/\R/u', $t);
    while ($lines && preg_match('/^(好的|當然|以下是|以下為|這是|這裡是|沒問題)/u', trim($lines[0])) && mb_strlen(trim($lines[0])) < 40) array_shift($lines);
    $t = trim(implode("\n", $lines));
    $t = preg_replace('/\*\*(.+?)\*\*/u', '$1', $t);            // 不要 markdown 粗體
    return trim($t);
}

/** 公司背景與限制（每一種草稿都帶；部門名稱取自組織主檔，模型只能用這些） */
function eg_ai_company_facts(PDO $db): string
{
    $names = [];
    try { foreach ($db->query("SELECT name FROM department WHERE COALESCE(level,9)<=3 ORDER BY sort_order, id") as $r) $names[] = $r['name']; } catch (Throwable $e) {}
    $names = array_values(array_unique($names));
    return "公司：台灣的齒輪製造業，通過 AS9100D 品質系統認證；認證範圍（稽核製程）只有「齒研」與「插齒」加工。\n"
         . "部門名稱只能使用：" . ($names ? implode('、', $names) : '總經理室、業務課、生管組、生產課、品管課、技術課、管理課、倉管組、採購組') . "。\n"
         . "分工：業務課負責客戶溝通、接單、報價與回覆客戶；生管組負責排程、交期與委外跟催；生產課負責現場加工與廠區；品管課負責檢驗與客訴矯正；"
         . "技術課負責製程與圖面評估；管理課負責人事、教育訓練與法定申報；倉管組負責倉庫與收發料；採購組負責採購與供應商評鑑。";
}

const EG_AI_STYLE = "請用繁體中文（台灣公司內部用語）。簡潔、具體、可執行；不要寫前言、結語、標題、項目符號以外的符號與 markdown；不要重複題目文字；"
                  . "不得捏造題目沒有提供的數字、客戶名稱、日期或人名；不要寫「下週」「下個月」「X 月 X 日前」這類具體時間（需要時間就寫「由負責部門訂定完成日」）；不要寫董事長室、總經理室以外沒列出的部門。";

/**
 * 依種類組提示詞並產生草稿。$ctx 由前端送來的欄位內容（文字一律截長、清掉控制字元）。
 * @return string 草稿文字
 */
function eg_ai_draft(PDO $db, string $kind, array $ctx): string
{
    $g = function ($k, $n = 600) use ($ctx) { return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)($ctx[$k] ?? ''))), 0, $n); };
    $facts = eg_ai_company_facts($db);
    $sys = $facts . "\n" . EG_AI_STYLE;
    switch ($kind) {
        case 'risk_measure': {
            $isOpp = $g('ro_kind', 20) === 'opportunity';
            $u = ($isOpp ? "請針對下面這個「機會」寫因應措施草稿。" : "請針對下面這個「風險」寫因應措施草稿。") . "\n"
               . "利害關係者：" . $g('stakeholder', 100) . "\n需求與期望：" . $g('needs', 200) . "\n議題：" . $g('issue', 400) . "\n"
               . ($g('tracks', 500) !== '' ? "目前已有的措施追蹤（可參考分工，不要重複）：" . $g('tracks', 500) . "\n" : '')
               . "要求：寫 1～3 條，每條一行，每條開頭寫清楚「由哪個部門」負責做什麼；措施要現場做得到、做完之後能用系統資料確認"
               . "（例如 KPI 逐月數字、出貨金額與客戶數、措施追蹤是否如期結案），不要寫需要修改程序書或建立新制度的內容。每條不超過 45 字。";
            return eg_ai_chat($db, $sys, $u, ['max_tokens' => 350]);
        }
        case 'risk_effect_check': {
            $u = "請寫「成效確認方式」草稿。\n議題：" . $g('issue', 400) . "\n因應措施：" . $g('measure', 500) . "\n"
               . "要求：1～2 句，說明用什麼指標、目標是多少來判定成效；指標只能是系統查得到的（KPI 逐月達標、出貨金額或客戶數與前一年比、措施追蹤是否如期結案）；"
               . "目標要依目前實際水準訂，不要寫「100%」「完全不再發生」這種達不到或查不到的值。不超過 70 字。";
            return eg_ai_chat($db, $sys, $u, ['max_tokens' => 220]);
        }
        case 'comm_reply': {
            $u = "利害關係者（" . $g('party_kind', 20) . "）：" . $g('party', 100) . "\n對方反應或提問：" . $g('question', 500) . "\n"
               . "請寫公司的「回覆內容」草稿：1～2 句，說明由哪個部門做什麼、大約什麼時候回覆或完成（不要寫具體日期）。語氣客氣、務實，不要承諾做不到的事。不超過 80 字。";
            return eg_ai_chat($db, $sys, $u, ['max_tokens' => 260]);
        }
        case 'comm_track_action': {
            $u = "利害關係者：" . $g('party', 100) . "\n反應內容：" . $g('content', 500) . ($g('reply', 300) !== '' ? "\n當時的回覆：" . $g('reply', 300) : '') . "\n"
               . "請寫「回應措施」草稿：1～2 句，具體寫出由哪個部門做什麼，做完之後怎麼確認已完成。不超過 80 字。";
            return eg_ai_chat($db, $sys, $u, ['max_tokens' => 260]);
        }
        case 'meeting_items': {
            $mk = $g('meeting_kind', 10);
            $title = $mk === 'mr' ? '管理審查會議（AS9100D 9.3）' : '內部稽核相關會議（事前會議或結束會議）';
            $factsMeeting = eg_ai_meeting_facts($db, (int)($ctx['year'] ?? date('Y')));
            $isDir = $g('item_kind', 10) === 'directive';
            $u = "會議：" . $title . "；主題：" . $g('subject', 100) . "。\n以下是系統查到的實際狀況（只能用這些，不要自己編數字）：\n" . $factsMeeting . "\n"
               . "請寫 " . ($isDir ? '「上級指示要項」' : '「會議要項（報告要點及決議事項）」') . "草稿，3～6 項，每項一行，開頭寫清楚報告或決議的主題，"
               . "後面接要點與決議；需要追蹤的事要寫出負責部門。每項不超過 60 字。";
            $raw = eg_ai_chat($db, $sys, $u . "\n格式：每一項只佔一行（不要把標題與內容分成兩行），不要編號，不要寫「主題」「要點」「決議」這些標籤字，也不要用 # 開頭。", ['max_tokens' => 600, 'timeout' => 180]);
            return eg_ai_join_lines($raw);
        }
    }
    throw new InvalidArgumentException('不支援的草稿種類');
}

/** 一項一行：標題行（以冒號結尾）併入下一行、去掉項目編號與空行 */
function eg_ai_join_lines(string $raw): string
{
    $out = []; $carry = '';
    foreach (preg_split('/\R/u', $raw) as $l) {
        $l = trim(preg_replace('/^[\s・•\-*#]*(\d+[.、)）]\s*)?/u', '', $l));
        $l = trim(preg_replace('/^[#\s]*(主題|要點|決議|上級指示要項|會議要項)\s*[：:]\s*/u', '', $l));
        if ($l === '' || preg_match('/^(主題|要點|決議|上級指示要項|會議要項)[：:]?$/u', $l)) continue;
        if (preg_match('/[：:]$/u', $l)) { $carry .= $l; continue; }
        $out[] = $carry . $l; $carry = '';
    }
    if ($carry !== '') $out[] = rtrim($carry, '：:');
    return implode("\n", $out);
}

/** 會議用的系統事實：風險與機會管制表狀態、措施追蹤逾期、KPI 未達標議題（每一段各自 try/catch，缺一段不影響其他） */
function eg_ai_meeting_facts(PDO $db, int $year): string
{
    $o = [];
    try {
        $d = $db->prepare("SELECT doc_id, doc_no FROM rm_doc WHERE kind='risk' AND is_deleted=0 AND YEAR(fill_date)=? ORDER BY fill_date DESC, doc_id DESC LIMIT 1");
        $d->execute([$year]); $doc = $d->fetch(PDO::FETCH_ASSOC);
        if ($doc) {
            $st = $db->prepare("SELECT COALESCE(effect_status,'none') s, COUNT(*) n FROM rm_item WHERE doc_id=? GROUP BY s");
            $st->execute([(int)$doc['doc_id']]); $m = ['effective'=>'有效','partial'=>'部分有效','ineffective'=>'無效','doing'=>'進行中','none'=>'尚未確認'];
            $parts = []; $tot = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $parts[] = ($m[$r['s']] ?? $r['s']) . ' ' . $r['n'] . ' 項'; $tot += (int)$r['n']; }
            $o[] = $year . ' 年風險與機會管制表（' . $doc['doc_no'] . '）共 ' . $tot . ' 項：' . implode('、', $parts);
        }
    } catch (Throwable $e) {}
    try {
        $st = $db->query("SELECT COUNT(*) n, SUM(due_date<CURDATE() AND is_closed=0) od, SUM(is_closed=0) op FROM comm_track WHERE is_deleted=0");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$r['op'] > 0) $o[] = '溝通管理措施追蹤未結案 ' . (int)$r['op'] . ' 筆' . ((int)$r['od'] > 0 ? '，其中逾期 ' . (int)$r['od'] . ' 筆' : '（沒有逾期）');
    } catch (Throwable $e) {}
    try {
        if (function_exists('rm_suggest_context')) {
            $k = [];
            foreach (rm_suggest_context($db, $year) as $r) if (strpos((string)$r['source'], 'KPI 未達標') === 0) $k[] = $r['issue'];
            if ($k) $o[] = 'KPI：' . implode('；', array_slice($k, 0, 3));
        }
    } catch (Throwable $e) {}
    try {
        $st = $db->prepare("SELECT COUNT(*) n, SUM(stage<>'closed') op FROM ia_nc WHERE is_deleted=0 AND year=?"); $st->execute([$year]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$r['n'] > 0) $o[] = $year . ' 年內部稽核不符合通知單 ' . (int)$r['n'] . ' 張（尚未結案 ' . (int)$r['op'] . ' 張）';
    } catch (Throwable $e) {}
    return $o ? '・' . implode("\n・", $o) : '（系統目前沒有可引用的資料）';
}
