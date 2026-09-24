<?php
/**
 * as_doc_heading_lib.php — AS 文件線上版「固定標題範本」（2026-09-24 新增）
 *
 * 做什麼：新建文件（沒有任何舊版可以複製）時，依「文件階別」自動帶入管理員
 * 事先設好的固定大標題／小標題骨架，省得每次都從空白開始打「1.目的 2.範圍…」。
 *
 * 範本怎麼建立，兩條路都寫進同一張表：
 *  ①管理員直接在設定跳窗手打（adh_save_tree 整層覆寫）
 *  ②從「已經有線上內容」的既有文件裡挑——但既有文件（如 2-DC-01）多半是從 Word
 *    匯入的純 <p> 段落、完全沒有語意上的標題標籤，沒東西可挑；要挑得到，
 *    那份文件裡真正要當範本的段落必須先用編輯器工具列本來就有的「段落階層」
 *    下拉改成「標題1／標題2」（<h1>/<h2>）。這裡刻意**不**用文字比對或縮排猜測
 *    去偵測「看起來像標題」的段落——公司文件的手打編號寫法五花八門
 *    （"6.1"、"6.1.1"、單純數字、全形數字…），猜錯了範本裡會混進整段內文。
 *    只認真正的 <h1>/<h2>，是唯一不會猜錯的判準。
 *
 * 大標題＝<h1>（parent_id=NULL），小標題＝<h2>（parent_id=大標題的 id）。
 * 「選小標題會連帶把上面那個大標題也標成要帶入」是前端的事（harvest 清單本來就
 * 是按文件順序列出來的，勾小標題時往上找最近一個 <h1> 一起勾起來即可），
 * 這支只負責「把整層存起來」與「把存好的範本組成一段可以直接塞進編輯器的 HTML」。
 */

if (!function_exists('adh_ensure_schema')) {

function adh_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $st = $db->prepare("SHOW TABLES LIKE 'as_doc_heading_tpl'");
        $st->execute();
        if ($st->fetchColumn() !== false) { $done = true; return; }
    } catch (Throwable $e) { return; }
    if ($db->inTransaction()) return;   // 交易中不下 DDL（隱式 commit），下一次請求再建
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS as_doc_heading_tpl (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doc_level VARCHAR(10) NOT NULL COMMENT '對應 as_document.doc_level：一階/二階/三階/四階',
            parent_id INT NULL COMMENT 'NULL=大標題；有值=小標題，指向同表某一列大標題的 id',
            seq INT NOT NULL DEFAULT 0 COMMENT '同一層內的顯示順序',
            heading_text VARCHAR(200) NOT NULL,
            created_by INT NULL, created_at DATETIME NULL,
            updated_by INT NULL, updated_at DATETIME NULL,
            KEY idx_level (doc_level, parent_id, seq)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Throwable $e) { error_log('[adh] ensure_schema: ' . $e->getMessage()); }
}

/** 文件階別的固定值域，settings 下拉與存檔驗證共用同一份（鐵律4） */
function adh_levels(): array { return ['一階', '二階', '三階', '四階']; }

/** 某一階的範本，樹狀（大標題 + 底下的小標題），依 seq 排序 */
function adh_tree(PDO $db, string $docLevel): array
{
    adh_ensure_schema($db);
    if (!in_array($docLevel, adh_levels(), true)) return [];
    try {
        $st = $db->prepare("SELECT id, parent_id, seq, heading_text FROM as_doc_heading_tpl
                            WHERE doc_level=? ORDER BY (parent_id IS NOT NULL), seq, id");
        $st->execute([$docLevel]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }

    $tops = []; $idxOf = [];
    foreach ($rows as $r) {
        if ($r['parent_id'] === null) {
            $r['children'] = [];
            $idxOf[(int)$r['id']] = count($tops);
            $tops[] = $r;
        }
    }
    foreach ($rows as $r) {
        if ($r['parent_id'] !== null && isset($idxOf[(int)$r['parent_id']])) {
            $tops[$idxOf[(int)$r['parent_id']]]['children'][] = $r;
        }
    }
    return $tops;
}

/**
 * 整層覆寫存檔（比照站上多處「整批儲存」慣例：先刪這一階全部舊列，
 * 再依畫面順序整批插入，新增/改名/刪除/搬移順序一次到位，不必逐列 CRUD）。
 * $tops 格式：[ ['text'=>'目的', 'children'=>[ ['text'=>'...'], ... ]], ... ]
 */
function adh_save_tree(PDO $db, string $docLevel, array $tops, int $uid): array
{
    adh_ensure_schema($db);
    if (!in_array($docLevel, adh_levels(), true)) return ['ok' => false, 'msg' => '不合法的文件階別'];

    $clean = [];
    foreach ($tops as $t) {
        $txt = trim((string)($t['text'] ?? ''));
        if ($txt === '') continue;
        if (mb_strlen($txt, 'UTF-8') > 200) $txt = mb_substr($txt, 0, 200, 'UTF-8');
        $kids = [];
        foreach ((array)($t['children'] ?? []) as $c) {
            $ctxt = trim((string)($c['text'] ?? ''));
            if ($ctxt === '') continue;
            if (mb_strlen($ctxt, 'UTF-8') > 200) $ctxt = mb_substr($ctxt, 0, 200, 'UTF-8');
            $kids[] = $ctxt;
        }
        $clean[] = ['text' => $txt, 'children' => $kids];
    }

    try {
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM as_doc_heading_tpl WHERE doc_level=?");
        $del->execute([$docLevel]);
        $insTop = $db->prepare("INSERT INTO as_doc_heading_tpl
            (doc_level, parent_id, seq, heading_text, created_by, created_at, updated_by, updated_at)
            VALUES (?,NULL,?,?,?,NOW(),?,NOW())");
        $insSub = $db->prepare("INSERT INTO as_doc_heading_tpl
            (doc_level, parent_id, seq, heading_text, created_by, created_at, updated_by, updated_at)
            VALUES (?,?,?,?,?,NOW(),?,NOW())");
        $seq = 0;
        foreach ($clean as $t) {
            $seq++;
            $insTop->execute([$docLevel, $seq, $t['text'], $uid ?: null, $uid ?: null]);
            $topId = (int)$db->lastInsertId();
            $sseq = 0;
            foreach ($t['children'] as $ctxt) {
                $sseq++;
                $insSub->execute([$docLevel, $topId, $sseq, $ctxt, $uid ?: null, $uid ?: null]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '儲存失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'count' => count($clean)];
}

/**
 * 從一段內容（version 的 content_html）挑出真正標記過「標題1／標題2」的段落，
 * 依文件裡出現的順序回傳。只認 <h1>/<h2>，理由見檔頭說明。
 * @return array [['tag'=>'h1'|'h2','text'=>'...'], ...]
 */
function adh_extract_headings(string $html): array
{
    $html = trim($html);
    if ($html === '') return [];
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="eg-adh-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return [];
    $root = $doc->getElementById('eg-adh-root');
    if (!$root) return [];

    $out = [];
    $xp = new DOMXPath($doc);
    foreach ($xp->query('.//h1 | .//h2', $root) as $node) {
        $txt = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
        if ($txt === '') continue;
        $out[] = ['tag' => strtolower($node->nodeName), 'text' => $txt];
    }
    return $out;
}

/** 把範本樹組成可以直接塞進編輯器的一段 HTML（大標題 <h1>、小標題 <h2>，
 *  每個標題後面留一個空段落給使用者打內文，跟編輯器工具列「標題1/標題2」
 *  格式輸出的標籤完全相同，所以貼進去之後使用者一樣可以用同一顆下拉再改） */
function adh_skeleton_html(array $tops): string
{
    $h = '';
    foreach ($tops as $t) {
        $h .= '<h1>' . adh_e($t['heading_text']) . '</h1><p><br></p>';
        foreach ((array)($t['children'] ?? []) as $c) {
            $h .= '<h2>' . adh_e($c['heading_text']) . '</h2><p><br></p>';
        }
    }
    return $h;
}

function adh_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** 搜尋「已經有線上內容」的文件版次，給「從既有文件挑選標題」的來源選單用。
 *  一律列已存進 as_doc_content 且真的有字的那些，同一份文件的每個版次各自一列
 *  （版次之間內容可能不同，讓管理員自己挑是哪一版）。 */
function adh_doc_search(PDO $db, string $kw, int $limit = 30): array
{
    $kw = trim($kw);
    $sql = "SELECT d.id doc_id, d.doc_no, d.doc_name, d.doc_level, v.id version_id, v.version
            FROM as_doc_content c
            JOIN as_document_version v ON v.id = c.version_id
            JOIN as_document d ON d.id = v.doc_id AND d.is_deleted = 0
            WHERE c.content_html IS NOT NULL AND c.content_html <> ''";
    $args = [];
    if ($kw !== '') {
        $sql .= " AND (d.doc_no LIKE ? OR d.doc_name LIKE ?)";
        $args[] = '%' . $kw . '%'; $args[] = '%' . $kw . '%';
    }
    $sql .= " ORDER BY d.doc_no, v.revised_date DESC LIMIT " . max(1, min(100, $limit));
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

} // function_exists guard
