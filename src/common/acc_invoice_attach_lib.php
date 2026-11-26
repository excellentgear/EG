<?php
/**
 * 發票附件（發票管理頁，唯一實作）
 *
 * - 附件檔存在全站附件根目錄的「發票」子資料夾（attach_lib.php），DB 只存檔名（鐵律5）。
 * - 一次可傳多個檔；依「檔名上的發票號碼」（英文 2 碼＋數字 8 碼）自動連到發票，
 *   同一張發票可有多個附件；檔名找不到號碼或系統沒有這張發票的，不寫入並回報原因。
 * - 下載一律走 API（權限守門），不給瀏覽器直連 NAS 路徑。
 */
require_once __DIR__ . '/attach_lib.php';

const ACC_INV_ATT_MAX = 20971520;   // 單檔 20MB
const ACC_INV_ATT_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'xls', 'xlsx', 'doc', 'docx', 'csv', 'txt', 'xml', 'zip'];

function acc_inv_att_ensure(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS acc_invoice_attach (
        att_id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        orig_name VARCHAR(200) NOT NULL,
        stored_name VARCHAR(120) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        uploaded_by_name VARCHAR(40) NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_inv (invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='發票附件（檔案在 NAS，DB 只存檔名）'");
}

function acc_inv_att_dir(PDO $db): string
{
    return eg_attach_dir($db, 'acc_invoice_attach_dir', '發票');
}

/** 從檔名取發票號碼（英文 2 碼＋數字 8 碼，前後不可緊接英數字）；沒有回 '' */
function acc_inv_att_no_from_name(string $name): string
{
    $base = preg_replace('/\.[^.]*$/', '', $name);
    if (preg_match_all('/(?<![A-Za-z0-9])([A-Za-z]{2})[-_ ]?(\d{8})(?!\d)/', $base, $m, PREG_SET_ORDER)) {
        return strtoupper($m[0][1] . $m[0][2]);
    }
    return '';
}

/**
 * 處理一次多檔上傳。$files 為 $_FILES['files']（多檔格式）。
 * @return array ['ok'=>[{name,invoice_no,invoice_id}], 'fail'=>[{name,reason}]]
 */
function acc_inv_att_upload(PDO $db, array $files, string $userName): array
{
    acc_inv_att_ensure($db);
    $dir = acc_inv_att_dir($db);
    if (!eg_attach_ensure_dir($dir)) return ['ok' => [], 'fail' => [['name' => '(全部)', 'reason' => '附件資料夾無法建立：' . $dir]]];

    $ok = []; $fail = [];
    $n = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    for ($i = 0; $i < $n; $i++) {
        $name = (string)$files['name'][$i];
        $err  = (int)$files['error'][$i];
        $tmp  = (string)$files['tmp_name'][$i];
        $size = (int)$files['size'][$i];
        if ($err !== UPLOAD_ERR_OK) { $fail[] = ['name' => $name, 'reason' => '上傳失敗（錯誤碼 ' . $err . '）']; continue; }
        if ($size > ACC_INV_ATT_MAX) { $fail[] = ['name' => $name, 'reason' => '檔案超過 20MB']; continue; }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ACC_INV_ATT_EXT, true)) { $fail[] = ['name' => $name, 'reason' => '不支援的檔案類型（.' . $ext . '）']; continue; }
        $no = acc_inv_att_no_from_name($name);
        if ($no === '') { $fail[] = ['name' => $name, 'reason' => '檔名找不到發票號碼（須含英文 2 碼＋數字 8 碼，例 AB12345678）']; continue; }
        $q = $db->prepare("SELECT invoice_id FROM acc_invoice WHERE invoice_no=? AND status<>'void' ORDER BY invoice_id DESC LIMIT 1");
        $q->execute([$no]);
        $iid = (int)$q->fetchColumn();
        if ($iid <= 0) {
            $q = $db->prepare("SELECT invoice_id FROM acc_invoice WHERE invoice_no=? ORDER BY invoice_id DESC LIMIT 1");
            $q->execute([$no]); $iid = (int)$q->fetchColumn();
        }
        if ($iid <= 0) { $fail[] = ['name' => $name, 'reason' => '系統沒有發票號碼 ' . $no . '（請先回填發票號碼）']; continue; }

        $db->prepare("INSERT INTO acc_invoice_attach (invoice_id, orig_name, stored_name, file_size, uploaded_by_name) VALUES (?,?,?,?,?)")
           ->execute([$iid, mb_substr($name, 0, 200), 'pending', $size, $userName]);
        $aid = (int)$db->lastInsertId();
        $stored = $no . '_' . $aid . '.' . $ext;
        if (!@move_uploaded_file($tmp, $dir . $stored)) {
            $db->prepare("DELETE FROM acc_invoice_attach WHERE att_id=?")->execute([$aid]);
            $fail[] = ['name' => $name, 'reason' => '檔案寫入失敗（請確認附件資料夾權限）'];
            continue;
        }
        $db->prepare("UPDATE acc_invoice_attach SET stored_name=? WHERE att_id=?")->execute([$stored, $aid]);
        $ok[] = ['name' => $name, 'invoice_no' => $no, 'invoice_id' => $iid];
    }
    return ['ok' => $ok, 'fail' => $fail];
}

function acc_inv_att_list(PDO $db, int $invoiceId): array
{
    acc_inv_att_ensure($db);
    $q = $db->prepare("SELECT att_id, orig_name, file_size, uploaded_by_name, uploaded_at FROM acc_invoice_attach WHERE invoice_id=? ORDER BY att_id");
    $q->execute([$invoiceId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** 輸出附件檔（exit）。找不到回 404 文字 */
function acc_inv_att_send(PDO $db, int $attId): void
{
    acc_inv_att_ensure($db);
    $q = $db->prepare("SELECT orig_name, stored_name FROM acc_invoice_attach WHERE att_id=?");
    $q->execute([$attId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    $path = $r ? acc_inv_att_dir($db) . basename($r['stored_name']) : '';
    if (!$r || !is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo '找不到附件檔案'; exit; }
    $ext = strtolower(pathinfo($r['stored_name'], PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
             'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'txt' => 'text/plain; charset=utf-8',
             'csv' => 'text/csv; charset=utf-8'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    eg_attach_send_disposition((string)$r['orig_name']);
    readfile($path);
    exit;
}

function acc_inv_att_delete(PDO $db, int $attId): bool
{
    acc_inv_att_ensure($db);
    $q = $db->prepare("SELECT stored_name FROM acc_invoice_attach WHERE att_id=?");
    $q->execute([$attId]);
    $sn = $q->fetchColumn();
    if ($sn === false) return false;
    $db->prepare("DELETE FROM acc_invoice_attach WHERE att_id=?")->execute([$attId]);
    @unlink(acc_inv_att_dir($db) . basename((string)$sn));
    return true;
}
