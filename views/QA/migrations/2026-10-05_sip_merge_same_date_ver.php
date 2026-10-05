<?php
/**
 * 2026-10-05_sip_merge_same_date_ver.php
 *
 * 使用者交辦：「建立新版次」已經改成日期跟來源版次相同時直接合併為同一版次（不另開新版次
 * 列），但那只管「往後新建」——doc_id=93（SMA-514 齒研 SIP）既有的 ver01(ver_id=102)／
 * ver02(ver_id=132) 兩筆版次本來就同一天（2026-09-10）、都已核准，卡在改版前就存在，
 * 使用者實測「取消送簽再重送」也救不回來（那條路只會影響那一筆自己，不會把兩筆併成一筆），
 * 故另寫這支一次性腳本，把「日期相同的舊資料」依同一套規則回頭整併。
 *
 * 合併規則（比照 ver_new 合併邏輯，唯一差別是這裡要物理刪掉多出來的那一筆）：
 *   同一份文件（doc_id）、同一個 form_date 若有一筆以上版次 →
 *   留「ver_id 最小」(最早建立) 那一筆當倖存版次（版次號／簽核紀錄都不動，
 *   已核准的簽核本來就有效，不必重蓋一次），其餘版次裡「modified_at 最新」那一筆
 *   視為最後正確內容的來源，把它的 ss_item／ss_step 整批覆蓋進倖存版次；
 *   ss_file（圖面/段落附件）用 part_attach_id+usage_kind+sec_key 比對，
 *   倖存版次已經有同一份就不重複搬、沒有才搬過去並視需要接手 draw_file_id；
 *   其餘版次（含內容來源那一筆本身）連同 ss_item／ss_step／ss_sign／ss_file 一併刪除；
 *   ss_doc.cur_ver_id 指到被刪版次的話改指回倖存版次。
 *
 * 範圍：掃全部 ss_ver 依 (doc_id, form_date) 分組找出 COUNT>1 的，不是寫死 doc_id=93，
 * 以後若又出現同一天留兩筆的舊資料，重跑這支就會一起處理（已處理過的组自然不會再被選到）。
 *
 * 預設只試算不寫入；要真的寫請加 --run。可重複執行（已處理過的第二次會是 0 組）。
 *
 *   php views/QA/migrations/2026-10-05_sip_merge_same_date_ver.php
 *   php views/QA/migrations/2026-10-05_sip_merge_same_date_ver.php --run
 */
$root = dirname(__DIR__, 3);
require_once $root . '/src/common/_config.php';
require_once $root . '/src/common/DBConnection.php';

$run = in_array('--run', $argv ?? [], true);
$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "模式：" . ($run ? '真的寫入' : '只試算，不寫入（加 --run 才會真的改資料）') . "\n\n";

$groups = $db->query(
    "SELECT doc_id, form_date FROM ss_ver GROUP BY doc_id, form_date HAVING COUNT(*) > 1"
)->fetchAll(PDO::FETCH_ASSOC);

echo "①找到 " . count($groups) . " 組「同一份文件、同一個日期卻有一筆以上版次」的舊資料\n\n";

$mergedN = 0;
foreach ($groups as $g) {
    $docId = (int)$g['doc_id'];
    $date  = (string)$g['form_date'];
    $doc   = $db->prepare("SELECT doc_id, title, cur_ver_id FROM ss_doc WHERE doc_id=?");
    $doc->execute([$docId]);
    $doc = $doc->fetch(PDO::FETCH_ASSOC);

    $vst = $db->prepare("SELECT * FROM ss_ver WHERE doc_id=? AND form_date=? ORDER BY ver_id");
    $vst->execute([$docId, $date]);
    $vers = $vst->fetchAll(PDO::FETCH_ASSOC);

    $survivor = $vers[0];                                   // ver_id 最小＝最早建立的那一筆
    $others   = array_slice($vers, 1);
    // 內容來源＝其餘版次裡 modified_at 最新的那一筆（最後被改過、最接近正確狀態）
    usort($others, fn($a, $b) => strcmp((string)$b['modified_at'], (string)$a['modified_at']));
    $srcVer = $others[0];

    echo "doc_id={$docId}　「{$doc['title']}」　{$date}　共 " . count($vers) . " 筆：\n";
    foreach ($vers as $v) {
        $tag = ((int)$v['ver_id'] === (int)$survivor['ver_id']) ? '【倖存】'
             : (((int)$v['ver_id'] === (int)$srcVer['ver_id']) ? '【內容來源，之後刪除】' : '【刪除】');
        echo "    ver_id={$v['ver_id']}　版次{$v['ver_no']}　{$v['status']}　modified_at={$v['modified_at']}　{$tag}\n";
    }

    if (!$run) { $mergedN++; echo "\n"; continue; }

    $db->beginTransaction();
    try {
        $survId = (int)$survivor['ver_id'];
        $srcId  = (int)$srcVer['ver_id'];

        // ① ss_item／ss_step：整批用內容來源那一筆覆蓋倖存版次
        $db->prepare("DELETE FROM ss_item WHERE ver_id=?")->execute([$survId]);
        $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, up_limit, lo_limit, owner, owner_dept_id,
                          method, tool_type_id, tool_id, tool_no, freq, note, tpl_id, lock_ctrl, lock_q,
                          ctrl_pat, q_pat, input_kind)
                      SELECT ?, seq, ctrl_point, q_char, up_limit, lo_limit, owner, owner_dept_id,
                          method, tool_type_id, tool_id, tool_no, freq, note, tpl_id, lock_ctrl, lock_q,
                          ctrl_pat, q_pat, input_kind
                      FROM ss_item WHERE ver_id=?")->execute([$survId, $srcId]);

        $db->prepare("DELETE FROM ss_step WHERE ver_id=?")->execute([$survId]);
        $db->prepare("INSERT INTO ss_step (ver_id, seq, sect, step_name, img_file_id, step_text, note, kv_json)
                      SELECT ?, seq, sect, step_name, img_file_id, step_text, note, kv_json
                      FROM ss_step WHERE ver_id=?")->execute([$survId, $srcId]);

        // ② ss_file：倖存版次已經有同一份（同 part_attach_id+usage_kind+sec_key）就不重複搬；
        //    沒有才搬過去，並記下「內容來源的舊 file_id → 倖存版次新/既有 file_id」的對照，
        //    draw_file_id 才能跟著接手到正確的那一筆。
        $survFiles = $db->prepare(
            "SELECT file_id, usage_kind, part_attach_id, sec_key FROM ss_file WHERE ver_id=?");
        $survFiles->execute([$survId]);
        $survFiles = $survFiles->fetchAll(PDO::FETCH_ASSOC);
        $matchKey = fn($r) => ($r['usage_kind'] ?? '') . '|' . ($r['part_attach_id'] ?? '') . '|' . ($r['sec_key'] ?? '');
        $survByKey = [];
        foreach ($survFiles as $f) $survByKey[$matchKey($f)][] = (int)$f['file_id'];

        $srcFiles = $db->prepare("SELECT * FROM ss_file WHERE ver_id=?");
        $srcFiles->execute([$srcId]);
        $srcFiles = $srcFiles->fetchAll(PDO::FETCH_ASSOC);
        $fileMap = [];                                         // 內容來源的舊 file_id → 倖存版次的 file_id
        foreach ($srcFiles as $f) {
            $k = $matchKey($f);
            if (!empty($survByKey[$k])) {
                $fileMap[(int)$f['file_id']] = (int)array_shift($survByKey[$k]);
                continue;
            }
            $db->prepare("INSERT INTO ss_file (doc_id, ver_id, usage_kind, sec_key, src, part_attach_id, file_name,
                              orig_name, mime, file_size, rot, uploaded_at, uploaded_by)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$docId, $survId, $f['usage_kind'], $f['sec_key'], $f['src'], $f['part_attach_id'],
                          $f['file_name'], $f['orig_name'], $f['mime'], $f['file_size'], (int)($f['rot'] ?? 0),
                          $f['uploaded_at'], $f['uploaded_by']]);
            $fileMap[(int)$f['file_id']] = (int)$db->lastInsertId();
        }
        $srcDrawId = (int)($srcVer['draw_file_id'] ?? 0);
        if ($srcDrawId > 0 && isset($fileMap[$srcDrawId])) {
            $db->prepare("UPDATE ss_ver SET draw_file_id=? WHERE ver_id=?")
               ->execute([$fileMap[$srcDrawId], $survId]);
        }

        // ③ 其餘版次（含內容來源）連同明細一併刪除
        foreach ($others as $o) {
            $oid = (int)$o['ver_id'];
            $db->prepare("DELETE FROM ss_item WHERE ver_id=?")->execute([$oid]);
            $db->prepare("DELETE FROM ss_step WHERE ver_id=?")->execute([$oid]);
            $db->prepare("DELETE FROM ss_sign WHERE ver_id=?")->execute([$oid]);
            $db->prepare("DELETE FROM ss_file WHERE ver_id=?")->execute([$oid]);
            $db->prepare("DELETE FROM ss_ver WHERE ver_id=?")->execute([$oid]);
        }

        // ④ cur_ver_id 若指著被刪的版次，改指回倖存版次
        if (in_array((int)$doc['cur_ver_id'], array_map(fn($o) => (int)$o['ver_id'], $others), true)) {
            $db->prepare("UPDATE ss_doc SET cur_ver_id=? WHERE doc_id=?")->execute([$survId, $docId]);
        }

        $db->commit();
        $mergedN++;
        echo "    -> 已合併為 ver_id={$survId}（版次{$survivor['ver_no']}），其餘 " . count($others) . " 筆已刪除\n\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "    !! 失敗：" . $e->getMessage() . "\n\n";
    }
}

echo "②" . ($run ? "已處理" : "可處理") . " {$mergedN} 組\n";
