<?php
/**
 * bom_view_file_lib.php — 圖面查閱頁（views/pm/bom_viewer.php）看得到的四種來源檔案，
 * 其「檢視權限判定 ／ 實體路徑解析 ／ 一次性下載權杖」的唯一實作。
 *
 * 四種來源（前端拿到的 path 長什麼樣）：
 *   nas   圖面分頁的 BOM 圖檔        /nas/B-2508250001.jpg
 *   part  其他附件分頁（料號附件）   ../../src/store/Part_Attachment_API.php?action=download&id=123
 *   order 訂單附件                   ../../src/store/Order_Attachment_API.php?action=download&id=456
 *   quote 報價附件                   ../../src/store/Quotation_File_API.php?action=download&quote_no=..&filename=..
 *
 * 為什麼要有這支（2026-08-25，使用者回報「用小畫家開啟跟下載都有問題」）：
 *  1. 本頁的「下載」原本一律把路徑丟給 `bom_download.php`，但那支只收 `/nas/` 開頭，
 *     附件分頁的 API 網址一送過去就是 400 → 瀏覽器顯示「無法存取網站」。
 *  2. 「小畫家」是把 `open-paint://host + path` 交給用戶端的 VBScript，附件的相對路徑
 *     直接接在 host 後面會組出非法網址（症狀＝「呼叫 open 方法後，才能呼叫此方法」，
 *     那是 oHTTP.Open 失敗後才跑到 setOption 的錯）；**而且 VBScript 是用
 *     MSXML2.ServerXMLHTTP 抓檔、不會帶登入 cookie**，附件 API 一定回 403。
 *     所以附件要能用小畫家開，必須先換一張不需登入也能下載的一次性權杖。
 *  3. 旋轉存檔也要知道「這個網址對應到硬碟上的哪個檔」——三個功能共用同一份解析，
 *     否則三邊各刻一份路徑組法，換 NAS 時一定有人漏改（鐵律4／鐵律5）。
 *
 * 路徑一律「即時組出」：DB 只存檔名，實體位置由目前設定值（bom_scan_dir／
 * part_attach_nas_dir／order_attach_dir／QUOTATION.upload_path）當下算出來。
 */

require_once __DIR__ . '/bom_dir_lib.php';   // BOM 資料夾設定值 + Big5/UTF-8 編碼處理
require_once __DIR__ . '/attach_lib.php';    // 全站附件根資料夾（訂單附件用）
require_once __DIR__ . '/rbac.php';

if (!function_exists('eg_bvf_perms')) {

/** 路徑結尾要修掉的分隔符（Windows 兩種都可能） */
function eg_bvf_seps(): string { return '/' . chr(92); }

// ──────────────────────────────────────────────────────────────────────────
// 權限
// ──────────────────────────────────────────────────────────────────────────
/**
 * 圖面查閱頁的分頁權限（與 bom_viewer.php 頁面上的判定同一份）。
 * 圖面：一律開放。報價：需 quotation_view。其他附件／訂單附件：過渡期——
 * 未指派 master_data 角色者維持開放，已指派則需 md_attach_view。
 */
function eg_bvf_perms(PDO $db, int $uid): array {
    $out = ['admin' => false, 'quote' => false, 'other' => true];
    try {
        $feats = rbac_user_features($db, $uid);
        $out['admin'] = rbac_has($feats, 'all');
        $out['quote'] = $out['admin'] || rbac_has($feats, 'quotation_view');
        $rq = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND r.module='master_data' LIMIT 1");
        $rq->execute([$uid]);
        $hasMdRole = (bool)$rq->fetchColumn();
        $out['other'] = $out['admin'] || !$hasMdRole || rbac_has($feats, 'md_attach_view');
    } catch (Throwable $e) {
        $out = ['admin' => false, 'quote' => false, 'other' => true];
    }
    return $out;
}

/** 這個使用者看不看得到這種來源的檔案（旋轉／小畫家／下載都用同一把尺） */
function eg_bvf_can_read(PDO $db, int $uid, string $src): bool {
    $p = eg_bvf_perms($db, $uid);
    switch ($src) {
        case 'nas':   return true;            // 圖面分頁本來就對所有登入者開放
        case 'part':
        case 'order': return (bool)$p['other'];
        case 'quote': return (bool)$p['quote'];
    }
    return false;
}

// ──────────────────────────────────────────────────────────────────────────
// 前端路徑 → 來源種類
// ──────────────────────────────────────────────────────────────────────────
/**
 * 把前端的 path（/nas/… 或附件 API 網址）判成 ['src'=>…, 'ref'=>[…]]。
 * 認不出來一律回 null，不要猜。
 */
function eg_bvf_parse_url(string $url): ?array {
    $url = trim($url);
    if ($url === '') return null;
    if (strpos($url, '/nas/') === 0) {
        return ['src' => 'nas', 'ref' => ['path' => $url]];
    }
    $qs = '';
    $qPos = strpos($url, '?');
    if ($qPos !== false) $qs = substr($url, $qPos + 1);
    $q = [];
    parse_str($qs, $q);
    if (strpos($url, 'Part_Attachment_API.php') !== false && !empty($q['id'])) {
        return ['src' => 'part', 'ref' => ['id' => (int)$q['id']]];
    }
    if (strpos($url, 'Order_Attachment_API.php') !== false && !empty($q['id'])) {
        return ['src' => 'order', 'ref' => ['id' => (int)$q['id']]];
    }
    if (strpos($url, 'Quotation_File_API.php') !== false && !empty($q['quote_no']) && !empty($q['filename'])) {
        return ['src' => 'quote', 'ref' => [
            'quote_no' => (string)$q['quote_no'],
            'filename' => (string)$q['filename'],
        ]];
    }
    return null;
}

// ──────────────────────────────────────────────────────────────────────────
// 來源種類 → 實體檔案
// ──────────────────────────────────────────────────────────────────────────
/**
 * 解析成實體檔案。
 * @return array|null ['fs'=>檔案系統完整路徑, 'name'=>顯示檔名, 'ext'=>副檔名, 'src'=>來源]
 */
function eg_bvf_resolve(PDO $db, array $t): ?array {
    $src = $t['src'] ?? '';
    $ref = $t['ref'] ?? [];
    $fs = null; $name = '';
    switch ($src) {
        case 'nas': {
            $r = eg_bvf_resolve_nas($db, (string)($ref['path'] ?? ''));
            if (!$r) return null;
            [$fs, $name] = $r;
            break;
        }
        case 'part': {
            $id = (int)($ref['id'] ?? 0);
            if ($id <= 0) return null;
            $st = $db->prepare("SELECT filename, original_name, d_id FROM part_attachments WHERE id=?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $base = eg_bvf_setting($db, 'part_attach_nas_dir');
            if ($base === '') return null;
            $fs   = rtrim($base, eg_bvf_seps()) . DIRECTORY_SEPARATOR . $row['d_id']
                  . DIRECTORY_SEPARATOR . $row['filename'];
            $name = $row['original_name'] ?: $row['filename'];
            break;
        }
        case 'order': {
            $id = (int)($ref['id'] ?? 0);
            if ($id <= 0) return null;
            $st = $db->prepare("SELECT filename, original_name FROM order_attachments WHERE id=?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $dir  = eg_attach_dir($db, 'order_attach_dir', '訂單');
            $fs   = rtrim($dir, eg_bvf_seps()) . DIRECTORY_SEPARATOR . $row['filename'];
            $name = $row['original_name'] ?: $row['filename'];
            break;
        }
        case 'quote': {
            $quoteNo  = preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)($ref['quote_no'] ?? ''));
            $filename = basename((string)($ref['filename'] ?? ''));
            if ($quoteNo === '' || $filename === '') return null;
            $base = '';
            try {
                $st = $db->prepare("SELECT param_value FROM system_parameters
                                    WHERE param_group='QUOTATION' AND param_key='upload_path' LIMIT 1");
                $st->execute();
                $v = $st->fetchColumn();
                if ($v !== false && $v !== null) {
                    $decoded = json_decode((string)$v, true);
                    if (is_string($decoded)) $base = $decoded;
                }
            } catch (Throwable $e) {}
            if ($base === '') return null;
            $dir  = rtrim($base, eg_bvf_seps()) . DIRECTORY_SEPARATOR . $quoteNo;
            $fs   = $dir . DIRECTORY_SEPARATOR . $filename;
            // 路徑穿越保險：解出來的實體路徑必須真的落在該報價單資料夾底下
            $real = realpath($fs); $realDir = realpath($dir);
            if (!$real || !$realDir || strpos($real, $realDir) !== 0) return null;
            $fs   = $real;
            $name = $filename;
            break;
        }
        default: return null;
    }
    if (!$fs || !is_file($fs)) return null;
    return [
        'fs'   => $fs,
        'name' => $name !== '' ? $name : basename($fs),
        'ext'  => strtolower(pathinfo($name !== '' ? $name : $fs, PATHINFO_EXTENSION)),
        'src'  => $src,
    ];
}

/**
 * /nas/xxx.jpg → BOM 資料夾底下的實體檔案。
 * 資料夾取自設定鍵 bom_scan_dir（bom_dir_lib），**不再寫死 Z: 磁碟機代號或 UNC 字串**；
 * 中文路徑在 Windows 可能要 Big5，交給 bom_dir_lib 判斷（漏轉不會報錯、只會讀不到）。
 */
function eg_bvf_resolve_nas(PDO $db, string $path): ?array {
    if (strpos($path, '/nas/') !== 0) return null;
    // 注意：不能用 urldecode()——它會把檔名裡「原本就是字面上的 +」（B-xxx++.jpg 這種加工圖
    // 變體命名）誤當成空白解掉而 404；rawurldecode() 只解 %XX，不動字面 +
    $rel = rawurldecode(substr($path, strlen('/nas/')));
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) return null;
    if (preg_match('#^[a-zA-Z]:|^[/\\\\]#', $rel)) return null;   // 不接受絕對路徑
    $name = basename(str_replace(chr(92), '/', $rel));
    $rel  = str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dir  = eg_bom_fs_path(eg_bom_scan_dir($db));
    $fs   = rtrim($dir, eg_bvf_seps()) . DIRECTORY_SEPARATOR . $rel;
    if (!is_file($fs) && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // 資料夾是 Big5 才讀得到時，檔名部分也要一起轉（見 bom_dir_lib 檔頭）
        $b5 = @mb_convert_encoding($rel, 'Big5', 'UTF-8');
        if ($b5 !== false && $b5 !== '') {
            $alt = rtrim($dir, eg_bvf_seps()) . DIRECTORY_SEPARATOR . $b5;
            if (is_file($alt)) $fs = $alt;
        }
    }
    return [$fs, $name];
}

/** system_settings 單一設定值（去頭尾空白；沒有就空字串） */
function eg_bvf_setting(PDO $db, string $key): string {
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? trim((string)$v) : '';
    } catch (Throwable $e) { return ''; }
}

// ──────────────────────────────────────────────────────────────────────────
// 一次性下載權杖（給用戶端的小畫家 VBScript 用；它抓檔時不會帶登入 cookie）
// ──────────────────────────────────────────────────────────────────────────
/** 權杖暫存目錄（系統暫存區，不放 NAS） */
function eg_bvf_token_dir(): string {
    $d = rtrim(sys_get_temp_dir(), eg_bvf_seps()) . DIRECTORY_SEPARATOR . 'eg_bomopen';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}

/**
 * 發一張權杖。存的是「來源種類＋識別鍵」，不是實體路徑——真正的路徑等
 * 兌換當下再算（鐵律5），這樣權杖檔外洩也拿不到 NAS 位置。
 * @return string 權杖（發不出來回空字串）
 */
function eg_bvf_token_issue(array $t, int $uid, int $ttl = 180): string {
    $dir = eg_bvf_token_dir();
    if (!is_dir($dir)) return '';
    eg_bvf_token_gc($dir);
    $tok  = bin2hex(random_bytes(16));
    $data = [
        'src' => $t['src'] ?? '', 'ref' => $t['ref'] ?? [],
        'uid' => $uid, 'exp' => time() + max(30, $ttl),
    ];
    $ok = @file_put_contents($dir . DIRECTORY_SEPARATOR . $tok . '.json', json_encode($data));
    return $ok ? $tok : '';
}

/** 兌換權杖（**一次性**：讀完就刪）。過期／不存在回 null */
function eg_bvf_token_consume(string $tok): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) return null;
    $f = eg_bvf_token_dir() . DIRECTORY_SEPARATOR . $tok . '.json';
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    @unlink($f);
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || (int)($data['exp'] ?? 0) < time()) return null;
    return $data;
}

/** 順手清掉過期的權杖檔（每次發權杖時做一次，不值得為它排排程） */
function eg_bvf_token_gc(string $dir): void {
    $now = time();
    foreach (@glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        if ($now - (int)@filemtime($f) > 3600) @unlink($f);
    }
}

}
