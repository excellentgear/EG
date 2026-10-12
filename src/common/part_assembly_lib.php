<?php
/**
 * part_assembly_lib.php — 料號附件的「組合圖（.iam）↔ 零件檔」關聯（唯一實作）。
 *
 * 背景（2026-10-12，使用者交辦）：
 *  Inventor 組合檔（.iam）本身只是一張「用到哪些零件」的參照清單，零件的實體形狀在各自的
 *  .ipt 裡；要在線上看得到完整組合，轉檔時必須同時拿得到全部零件檔。但使用者點出兩個很實際
 *  的問題：
 *   ①「全部都上傳會顯示此料號有一堆附件」——一個組合動輒 5 個零件，平鋪在附件清單上會把
 *     真正要看的圖面淹掉。
 *   ②「某些零件是別的料號，已經在別的料號中上傳過檔案，甚至已經設定成整組，是否有辦法不需要
 *     重複上傳」——同一顆零件不該在系統裡存好幾份。
 *
 *  解法：**只建關聯、不複製檔**（站上既有前例：料號附件綁報價單也是只建關聯）。
 *  一張關聯表就同時解決兩件事：
 *   - 同一個料號底下的零件檔：建了關聯之後，附件清單把它收進組合那一列底下，不再平鋪一列。
 *   - 別的料號底下既有的零件檔：直接指過去，完全不必重新上傳；那個檔案在它自己的料號底下
 *     仍然是獨立附件（本來就該是），只是多了一條「被這個組合用到」的關聯。
 *
 *  巢狀組合（零件本身也是 .iam，而且在它自己的料號底下也設定成整組）一律遞迴展開——
 *  Inventor 開巢狀組合需要整棵樹的檔案都在場，少一層就又變成空的。
 *
 * 路徑一律即時組出（鐵律5）：本檔只處理「哪個附件關聯到哪個附件」，不存任何實體路徑。
 */

if (!function_exists('eg_pasm_ensure_schema')) {

/** 可以當組合元件的副檔名（與 inventor_preview_lib 的 EG_INVENTOR_COMPONENT_EXTS 同一套） */
function eg_pasm_component_exts(): array {
    require_once __DIR__ . '/inventor_preview_lib.php';
    return EG_INVENTOR_COMPONENT_EXTS;
}

function eg_pasm_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS part_attach_assembly_link (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            assembly_attach_id   INT NOT NULL            COMMENT '組合圖本身（part_attachments.id，副檔名 .iam）',
            component_attach_id  INT NOT NULL            COMMENT '零件檔（part_attachments.id，可以是別的料號底下的附件）',
            created_by           VARCHAR(50) NULL,
            created_by_id        INT NULL,
            created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_pair (assembly_attach_id, component_attach_id),
            INDEX idx_asm (assembly_attach_id),
            INDEX idx_comp (component_attach_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='組合圖(.iam)與它用到的零件檔的關聯；只建關聯不複製檔，零件可在別的料號底下'");
    } catch (Throwable $e) {}
}

/**
 * 某個組合直接關聯到的零件（不遞迴）。
 * @return array 每筆 ['id','d_id','part_no','filename','original_name','file_size','uploaded_at']
 */
function eg_pasm_components(PDO $pdo, int $assemblyAttachId): array {
    eg_pasm_ensure_schema($pdo);
    if ($assemblyAttachId <= 0) return [];
    $st = $pdo->prepare(
        "SELECT pa.id, pa.d_id, d.D_Setting_Id AS part_no, pa.filename, pa.original_name, pa.file_size, pa.uploaded_at
           FROM part_attach_assembly_link l
           JOIN part_attachments pa ON pa.id = l.component_attach_id AND pa.deleted_at IS NULL
           LEFT JOIN d_setting d ON d.d_id = pa.d_id
          WHERE l.assembly_attach_id = ?
          ORDER BY l.id");
    $st->execute([$assemblyAttachId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * 某個組合「整棵樹」會用到的零件（遞迴展開巢狀組合）。
 * 巢狀組合：零件本身也是 .iam，而它在自己的料號底下也設定了整組——Inventor 開巢狀組合
 * 需要整棵樹的檔案都在場，只帶第一層會讓下層全部解析不到、整個組合又變成空的。
 * @return array 同 eg_pasm_components()，已去重（同一個附件只出現一次）
 */
function eg_pasm_components_recursive(PDO $pdo, int $assemblyAttachId, array &$seen = []): array {
    if ($assemblyAttachId <= 0 || isset($seen[$assemblyAttachId])) return [];
    $seen[$assemblyAttachId] = true;
    $out = [];
    foreach (eg_pasm_components($pdo, $assemblyAttachId) as $c) {
        $cid = (int)$c['id'];
        if (isset($seen[$cid])) continue;
        $seen[$cid] = true;
        $out[] = $c;
        $ext = strtolower(pathinfo((string)($c['original_name'] ?: $c['filename']), PATHINFO_EXTENSION));
        if ($ext === 'iam') {   // 子組合：把它底下的零件也一起帶上來
            foreach (eg_pasm_components_recursive($pdo, $cid, $seen) as $sub) $out[] = $sub;
        }
    }
    return $out;
}

/** 這個附件是不是「同一個料號底下某個組合的零件」——清單要把它收進組合那一列、不再平鋪 */
function eg_pasm_hidden_component_ids(PDO $pdo, int $dId): array {
    eg_pasm_ensure_schema($pdo);
    if ($dId <= 0) return [];
    $st = $pdo->prepare(
        "SELECT DISTINCT l.component_attach_id
           FROM part_attach_assembly_link l
           JOIN part_attachments c ON c.id = l.component_attach_id AND c.d_id = ?
           JOIN part_attachments a ON a.id = l.assembly_attach_id   AND a.d_id = ? AND a.deleted_at IS NULL");
    $st->execute([$dId, $dId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** 一次查出某個料號底下每個組合各有幾個零件（清單徽章用，避免逐列各查一次） */
function eg_pasm_counts_for_part(PDO $pdo, int $dId): array {
    eg_pasm_ensure_schema($pdo);
    if ($dId <= 0) return [];
    $st = $pdo->prepare(
        "SELECT l.assembly_attach_id AS aid, COUNT(*) AS n
           FROM part_attach_assembly_link l
           JOIN part_attachments a ON a.id = l.assembly_attach_id AND a.d_id = ? AND a.deleted_at IS NULL
           JOIN part_attachments c ON c.id = l.component_attach_id AND c.deleted_at IS NULL
          GROUP BY l.assembly_attach_id");
    $st->execute([$dId]);
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $m[(int)$r['aid']] = (int)$r['n'];
    return $m;
}

/**
 * 依「缺少的零件檔名」到全站找同名的既有附件當候選。
 * 使用者拍板：**一律列出候選、人確認後才連結**——像「M8x1.25 x30L.ipt」這種標準件檔名，
 * 不同料號底下可能有同名但其實不同的檔，自動連結會悄悄顯示錯的零件形狀而且不易發現。
 * @param array $names 缺少的檔名（組合檔回報的原始檔名）
 * @return array ['檔名' => [候選附件...]]，沒有候選的檔名不會出現在結果裡
 */
function eg_pasm_find_candidates(PDO $pdo, array $names): array {
    eg_pasm_ensure_schema($pdo);
    $names = array_values(array_unique(array_filter(array_map('strval', $names), function ($n) { return $n !== ''; })));
    if (!$names) return [];
    $ph = implode(',', array_fill(0, count($names), '?'));
    $st = $pdo->prepare(
        "SELECT pa.id, pa.d_id, d.D_Setting_Id AS part_no, pa.original_name, pa.file_size, pa.uploaded_at
           FROM part_attachments pa
           LEFT JOIN d_setting d ON d.d_id = pa.d_id
          WHERE pa.deleted_at IS NULL AND pa.original_name IN ($ph)
          ORDER BY pa.original_name, pa.id");
    $st->execute($names);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out[(string)$r['original_name']][] = $r;
    }
    return $out;
}

/**
 * 建立關聯。回傳 ['linked'=>n, 'skipped'=>[理由...]]
 * 守門：組合必須是 .iam、零件必須是 .ipt/.iam、不可自己連自己、不可造成循環。
 */
function eg_pasm_link(PDO $pdo, int $assemblyAttachId, array $componentIds, string $byName = '', int $byId = 0): array {
    eg_pasm_ensure_schema($pdo);
    $res = ['linked' => 0, 'skipped' => []];
    $st = $pdo->prepare("SELECT id, filename, original_name FROM part_attachments WHERE id=? AND deleted_at IS NULL");
    $st->execute([$assemblyAttachId]);
    $asm = $st->fetch(PDO::FETCH_ASSOC);
    if (!$asm) { $res['skipped'][] = '找不到組合圖附件'; return $res; }
    if (strtolower(pathinfo((string)($asm['original_name'] ?: $asm['filename']), PATHINFO_EXTENSION)) !== 'iam') {
        $res['skipped'][] = '指定的附件不是組合圖（.iam）'; return $res;
    }
    $exts = eg_pasm_component_exts();
    $ins = $pdo->prepare("INSERT IGNORE INTO part_attach_assembly_link (assembly_attach_id, component_attach_id, created_by, created_by_id) VALUES (?,?,?,?)");
    foreach ($componentIds as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0 || $cid === $assemblyAttachId) { $res['skipped'][] = '不可連結自己'; continue; }
        $st->execute([$cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) { $res['skipped'][] = "附件 #$cid 不存在"; continue; }
        $nm  = (string)($c['original_name'] ?: $c['filename']);
        $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) { $res['skipped'][] = $nm . '：不是 Inventor 零件檔'; continue; }
        // 循環保護：要連進來的那個子組合，它底下（遞迴）不可以又含有這個組合
        if ($ext === 'iam') {
            $seen = [];
            foreach (eg_pasm_components_recursive($pdo, $cid, $seen) as $sub) {
                if ((int)$sub['id'] === $assemblyAttachId) { $res['skipped'][] = $nm . '：會造成組合互相包含'; continue 2; }
            }
        }
        $ins->execute([$assemblyAttachId, $cid, $byName, $byId ?: null]);
        if ($ins->rowCount() > 0) $res['linked']++;
    }
    return $res;
}

/** 解除關聯（只拿掉關聯，不會刪除任何檔案） */
function eg_pasm_unlink(PDO $pdo, int $assemblyAttachId, int $componentAttachId): bool {
    eg_pasm_ensure_schema($pdo);
    $st = $pdo->prepare("DELETE FROM part_attach_assembly_link WHERE assembly_attach_id=? AND component_attach_id=?");
    $st->execute([$assemblyAttachId, $componentAttachId]);
    return $st->rowCount() > 0;
}

}
