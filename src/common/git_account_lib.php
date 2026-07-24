<?php
/**
 * git_account_lib.php — GitHub 帳號綁定 + 整站上傳 + 從雲端下載備份
 *
 * 綁定：存 Personal Access Token(PAT)+擁有者,並把「程式碼 repo」與「DB 備份庫」的 origin
 *       改成內嵌 token 的 https URL → 換機/gh 未登入時 push/pull 仍可運作(不依賴 gh keyring)。
 * 整站上傳：把 htdocs/EGsystem 目前所有檔案(含未加入版控者,.gitignore 仍生效) commit+push。
 * 雲端下載：clone/pull DB 備份庫 → 掃 dumps/ → 未登錄的 .sql 補進 db_backup_log(可還原)。
 *
 * Token 存於 db_backup_config.git_token。屬敏感資料(存於本 DB,亦即被備份的同一個庫);
 * 對外 API 一律只回「已綁定/擁有者」,不回 token 本身。
 */

require_once __DIR__ . '/db_backup_lib.php'; // BK_GIT / BK_REPO / eg_bk_cfg_* / eg_bk_exec

if (!defined('EG_CODE_REPO_DIR')) define('EG_CODE_REPO_DIR', 'C:\\MAMP\\htdocs\\EGsystem');

// ── 通用 git（指定工作目錄）──
function eg_git_run(string $dir, array $args): array {
    $parts = [escapeshellarg(BK_GIT), '-C', escapeshellarg($dir)];
    foreach ($args as $a) $parts[] = escapeshellarg($a);
    return eg_bk_exec(implode(' ', $parts));
}

// ── 設定摘要（不含 token 本身）──
function eg_git_cfg(PDO $pdo): array {
    $token = eg_bk_cfg_get($pdo, 'git_token', '');
    return [
        'bound'        => $token !== '',
        'owner'        => eg_bk_cfg_get($pdo, 'git_owner', ''),
        'login'        => eg_bk_cfg_get($pdo, 'git_login', ''),
        'bound_at'     => eg_bk_cfg_get($pdo, 'git_bound_at', ''),
        'code_remote'  => eg_git_current_remote(EG_CODE_REPO_DIR),
        'backup_remote'=> eg_git_current_remote(BK_REPO),
    ];
}

// 目前 origin URL（把內嵌的 token 遮罩後回傳）
function eg_git_current_remote(string $dir): string {
    if (!is_dir($dir . '\\.git')) return '(非 git 目錄)';
    [$c, $out] = eg_git_run($dir, ['remote', 'get-url', 'origin']);
    if ($c !== 0) return '(無 origin)';
    return preg_replace('#https://[^@/]+@#', 'https://***@', trim($out));
}

// 找一份可用的 CA 憑證庫（MAMP 的 curl 預設沒設,借 Git 或 php.ini 的）
function eg_git_cacert(): string {
    $cands = [
        (string)@ini_get('curl.cainfo'),
        (string)@ini_get('openssl.cafile'),
        'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
        'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt',
        'C:\\MAMP\\conf\\cacert.pem',
    ];
    foreach ($cands as $c) { if ($c !== '' && @is_file($c)) return $c; }
    return '';
}

// ── 驗證 token：先試 GitHub API(拿 login/scopes),SSL 失敗則退回 git ls-remote(只驗可用性)──
function eg_git_verify_token(string $token): array {
    $token = trim($token);
    if ($token === '') return ['ok'=>false,'msg'=>'請輸入 Token'];

    $ch = curl_init('https://api.github.com/user');
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: EGsystem-backup',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
    ];
    $ca = eg_git_cacert();
    if ($ca !== '') $opt[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opt);
    $resp = curl_exec($ch);
    $curlErr = ($resp === false) ? curl_error($ch) : '';
    if ($resp !== false) {
        $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdr   = substr($resp, 0, $hsize);
        $body  = substr($resp, $hsize);
        curl_close($ch);
        if ($code === 401) return ['ok'=>false,'msg'=>'Token 無效或已過期(401)'];
        if ($code === 200) {
            $j = json_decode($body, true);
            $scopes = '';
            if (preg_match('/x-oauth-scopes:\s*([^\r\n]*)/i', $hdr, $m)) $scopes = trim($m[1]);
            if ($scopes !== '' && strpos($scopes, 'repo') === false) {
                return ['ok'=>false,'msg'=>"Token 缺少 repo 權限(目前:$scopes)。請重新產生並勾選 repo"];
            }
            return ['ok'=>true,'login'=>$j['login'] ?? '','scopes'=>$scopes,'msg'=>'驗證成功'];
        }
        // 其他 HTTP 碼 → 落到 git 備援
    } else {
        curl_close($ch);
    }

    // 備援：用 git ls-remote 對現有 repo 測試 token（git 有自己的 CA,通常連得上）
    $target = '';
    foreach ([BK_REPO, EG_CODE_REPO_DIR] as $dir) {
        [$c, $out] = eg_git_run($dir, ['remote', 'get-url', 'origin']);
        if ($c === 0 && preg_match('#github\.com[/:]([^/]+/[^/]+?)(?:\.git)?/?$#i', trim($out), $m)) {
            $target = $m[1]; break;
        }
    }
    if ($target === '') {
        return ['ok'=>false,'msg'=>'GitHub API 連線失敗(' . $curlErr . '),且本機無現有 repo 可供備援驗證'];
    }
    $url = 'https://' . $token . '@github.com/' . $target . '.git';
    [$lc, $lo] = eg_bk_exec(implode(' ', [escapeshellarg(BK_GIT), 'ls-remote', '--heads', escapeshellarg($url)]));
    if ($lc === 0) {
        // 從 target 推 owner 當 login
        $login = explode('/', $target)[0];
        return ['ok'=>true,'login'=>$login,'scopes'=>'(git 驗證)','msg'=>'驗證成功(經 git ls-remote)'];
    }
    return ['ok'=>false,'msg'=>'Token 驗證失敗:' . preg_replace('/https:\/\/[^@]*@/', 'https://***@', mb_substr($lo, 0, 200))];
}

// 把 token 內嵌進 origin URL（保留原 owner/repo 路徑）
function eg_git_apply_token_remote(string $dir, string $token): array {
    if (!is_dir($dir . '\\.git')) return ['ok'=>false,'msg'=>$dir . ' 非 git 目錄'];
    [$c, $out] = eg_git_run($dir, ['remote', 'get-url', 'origin']);
    if ($c !== 0) return ['ok'=>false,'msg'=>$dir . ' 無 origin remote'];
    $url = trim($out);
    // 取出 github.com/owner/repo.git 主體
    if (!preg_match('#github\.com[/:]([^/]+/[^/]+?)(?:\.git)?/?$#i', $url, $m)) {
        return ['ok'=>false,'msg'=>'無法解析 origin:' . $url];
    }
    $path = $m[1];
    $newUrl = 'https://' . $token . '@github.com/' . $path . '.git';
    [$rc, $ro] = eg_git_run($dir, ['remote', 'set-url', 'origin', $newUrl]);
    if ($rc !== 0) return ['ok'=>false,'msg'=>'設定 remote 失敗:' . mb_substr($ro, 0, 150)];
    return ['ok'=>true,'path'=>$path];
}

// ── 綁定 ──
function eg_git_bind(PDO $pdo, string $token, string $by): array {
    $v = eg_git_verify_token($token);
    if (!$v['ok']) return $v;
    // 兩個 repo 的 remote 都套用 token(換機時 push/pull 免登入)
    $notes = [];
    foreach ([EG_CODE_REPO_DIR => '程式碼庫', BK_REPO => 'DB備份庫'] as $dir => $label) {
        $r = eg_git_apply_token_remote($dir, $token);
        $notes[] = $label . ':' . ($r['ok'] ? 'OK' : $r['msg']);
    }
    eg_bk_cfg_set($pdo, 'git_token', $token, $by);
    eg_bk_cfg_set($pdo, 'git_login', $v['login'], $by);
    eg_bk_cfg_set($pdo, 'git_owner', $v['login'], $by);
    eg_bk_cfg_set($pdo, 'git_bound_at', date('Y-m-d H:i:s'), $by);
    return ['ok'=>true,'msg'=>'已綁定 GitHub 帳號:' . $v['login'] . '（' . implode('；', $notes) . '）','login'=>$v['login']];
}

// ── 解除綁定（清 token,remote 還原成不含 token）──
function eg_git_unbind(PDO $pdo, string $by): array {
    foreach ([EG_CODE_REPO_DIR, BK_REPO] as $dir) {
        [$c, $out] = eg_git_run($dir, ['remote', 'get-url', 'origin']);
        if ($c === 0 && preg_match('#github\.com[/:]([^/]+/[^/]+?)(?:\.git)?/?$#i', trim($out), $m)) {
            eg_git_run($dir, ['remote', 'set-url', 'origin', 'https://github.com/' . $m[1] . '.git']);
        }
    }
    eg_bk_cfg_set($pdo, 'git_token', '', $by);
    return ['ok'=>true,'msg'=>'已解除綁定,兩個 repo 的 remote 已清除 token'];
}

// ── 整站上傳（把 htdocs/EGsystem 現有全部檔案 commit+push）──
function eg_git_upload_full_site(PDO $pdo, string $by): array {
    if (eg_bk_cfg_get($pdo, 'git_token', '') === '') return ['ok'=>false,'msg'=>'請先綁定 GitHub 帳號'];
    $dir = EG_CODE_REPO_DIR;
    // 先看有多少變更(含未追蹤,.gitignore 生效)
    [$sc, $sout] = eg_git_run($dir, ['status', '--porcelain']);
    $changed = array_filter(explode("\n", trim($sout)), 'strlen');
    $n = count($changed);
    eg_git_run($dir, ['add', '-A']);
    if ($n > 0) {
        [$cc, $cout] = eg_git_run($dir, ['commit', '-m', '全站完整備份快照（' . $by . ' ' . date('Y-m-d H:i') . '）']);
        if ($cc !== 0 && stripos($cout, 'nothing to commit') === false) {
            return ['ok'=>false,'msg'=>'commit 失敗:' . mb_substr($cout, 0, 200)];
        }
    }
    [$pc, $pout] = eg_git_run($dir, ['push', 'origin', 'HEAD']);
    if ($pc !== 0) return ['ok'=>false,'msg'=>'push 失敗:' . preg_replace('/https:\/\/[^@]*@/', 'https://***@', mb_substr($pout, 0, 300))];
    return ['ok'=>true,'msg'=>"整站已上傳到 GitHub 程式碼庫（本次提交 $n 項變更；.gitignore 排除的機密檔不會上傳）"];
}

// ── 從雲端下載備份（clone 或 pull DB 備份庫 → 補登錄 dumps）──
function eg_git_pull_backups(PDO $pdo, string $by): array {
    $token = eg_bk_cfg_get($pdo, 'git_token', '');
    $existed = is_dir(BK_REPO . '\\.git');

    if (!$existed) {
        // 全新機:需要有 token 且知道 repo URL 才能 clone
        if ($token === '') return ['ok'=>false,'msg'=>'本機尚無備份庫,且未綁定帳號無法 clone。請先綁定 GitHub 帳號'];
        $owner = eg_bk_cfg_get($pdo, 'git_owner', '');
        if ($owner === '') return ['ok'=>false,'msg'=>'找不到擁有者資訊,無法 clone'];
        $url = 'https://' . $token . '@github.com/' . $owner . '/EGsystem-dbbackup.git';
        @mkdir(dirname(BK_REPO), 0777, true);
        [$cc, $co] = eg_bk_exec(implode(' ', [escapeshellarg(BK_GIT), 'clone', escapeshellarg($url), escapeshellarg(BK_REPO)]));
        if ($cc !== 0) return ['ok'=>false,'msg'=>'clone 失敗:' . preg_replace('/https:\/\/[^@]*@/', 'https://***@', mb_substr($co, 0, 300))];
    } else {
        [$pc, $po] = eg_git_run(BK_REPO, ['pull', '--ff-only', 'origin', 'HEAD']);
        if ($pc !== 0) return ['ok'=>false,'msg'=>'pull 失敗:' . preg_replace('/https:\/\/[^@]*@/', 'https://***@', mb_substr($po, 0, 300))];
    }

    // 掃 dumps/ → 未登錄者補進 db_backup_log
    [, $hash] = eg_git_run(BK_REPO, ['rev-parse', 'HEAD']);
    $hash = trim($hash);
    $files = glob(BK_DUMPS . '\\EGsystem_*.sql') ?: [];
    $added = 0;
    $chk = $pdo->prepare("SELECT id FROM db_backup_log WHERE filename=? LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO db_backup_log (filename,rel_path,size_bytes,git_commit,trigger_type,status,pushed,note,created_by,created_at,finished_at)
                          VALUES (?,?,?,?,'cloud','success',1,'從雲端下載',?,NOW(),NOW())");
    foreach ($files as $f) {
        $fn = basename($f);
        $chk->execute([$fn]);
        if ($chk->fetchColumn()) continue;
        $ins->execute([$fn, BK_DUMPS_REL . '/' . $fn, filesize($f), $hash, $by]);
        $added++;
    }
    $total = count($files);
    return ['ok'=>true,'msg'=>($existed ? '已從雲端更新備份庫。' : '已從雲端 clone 備份庫。')
                            . "工作區共 $total 個備份檔,新登錄 $added 筆(可在列表還原)"];
}
