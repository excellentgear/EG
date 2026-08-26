<?php
/**
 * 機台顯示名稱格式 —— 全站唯一實作（2026-08-26 建立）
 *
 * 為什麼要有這支：機台主檔 machine_list 同時有「機台名稱(machine)」「現場編號(field_no)」
 * 「財產編號(asset_no)」「機型(machine_model)」等多個識別欄位，現場人員習慣看的欄位各單位不同
 * （報工紀錄查詢想看現場編號、機台設備一覽表想看名稱＋機型）。使用者要求「管理員可自行設定要顯示
 * 哪些欄位、可多欄位複合顯示（例：機台編號_機器名稱）」，所以把「該顯示成什麼字串」收斂成一支
 * 共用庫：設定只存一份（system_settings），任何頁面要顯示機台一律呼叫 eg_machine_label()，
 * 禁止各頁自己拼欄位（鐵律4：兩邊各拼一份，改了設定只有一邊會變）。
 *
 * 用法：
 *   require_once __DIR__.'/machine_label_lib.php';
 *   $cfg = eg_machine_label_cfg($pdo);
 *   $sql = "SELECT ..., ".eg_machine_label_sql('m','mpt')." FROM ... LEFT JOIN machine_list m ...
 *           LEFT JOIN process_type mpt ON mpt.process_type_id = m.machine_type_id";
 *   foreach ($rows as &$r) $r['machine_label'] = eg_machine_label($r, $cfg);
 *
 * 注意：eg_machine_label_sql() 產生的欄位別名就是欄位原名（machine / field_no / …），
 * 呼叫端的 SELECT 若已經有同名欄位請自行擇一，不要兩邊都放（MySQL 會取後者，容易看不出來）。
 */

/** 可選欄位白名單：key = 欄位代碼（存進設定值的就是這些），value = [中文名, 來源SQL表達式樣板] */
function eg_machine_label_fields(): array {
    return [
        'machine'       => ['機台名稱',  '{m}.machine'],
        'field_no'      => ['現場編號',  '{m}.field_no'],
        'asset_no'      => ['財產編號',  '{m}.asset_no'],
        'machine_model' => ['機型',      '{m}.machine_model'],
        'machine_type'  => ['機台種類',  '{pt}.process_type'],
        'manufacturer'  => ['製造商',    '{m}.manufacturer'],
        'spec'          => ['規格',      '{m}.spec'],
    ];
}

const EG_MACHINE_LABEL_KEY = 'EQUIP_MACHINE_LABEL_FMT';
const EG_MACHINE_LABEL_SEP_MAX = 5;

/** 目前設定：['fields'=>[欄位代碼…], 'sep'=>'分隔字元']；沒設定過＝只顯示機台名稱（與改版前完全相同） */
function eg_machine_label_cfg(PDO $db): array {
    $raw = null;
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
        $st->execute([EG_MACHINE_LABEL_KEY]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && $v !== '') $raw = json_decode((string)$v, true);
    } catch (Throwable $e) { $raw = null; }
    return eg_machine_label_cfg_normalize(is_array($raw) ? $raw : []);
}

/** 正規化＋白名單過濾（存檔與讀取共用同一份規則，避免手改 DB 或舊資料塞進怪欄位） */
function eg_machine_label_cfg_normalize(array $raw): array {
    $allow = array_keys(eg_machine_label_fields());
    $fields = [];
    foreach ((array)($raw['fields'] ?? []) as $f) {
        $f = trim((string)$f);
        if (in_array($f, $allow, true) && !in_array($f, $fields, true)) $fields[] = $f;
    }
    if (!$fields) $fields = ['machine'];
    $sep = (string)($raw['sep'] ?? '_');
    if ($sep === '') $sep = '_';
    if (mb_strlen($sep) > EG_MACHINE_LABEL_SEP_MAX) $sep = mb_substr($sep, 0, EG_MACHINE_LABEL_SEP_MAX);
    return ['fields' => $fields, 'sep' => $sep];
}

/** 存檔（呼叫端自行做權限守門） */
function eg_machine_label_cfg_save(PDO $db, array $raw): array {
    $cfg = eg_machine_label_cfg_normalize($raw);
    $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $st->execute([EG_MACHINE_LABEL_KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
    return $cfg;
}

/**
 * 產生要放進 SELECT 的欄位清單（一律把「所有」可選欄位都撈出來，不是只撈目前設定的那幾個）——
 * 這樣管理員改設定後不必改任何 SQL，且列印/CSV/清單三處拿到的資料完全一致。
 * $ptAlias 給不出來時（呼叫端沒 join process_type）傳 null，機台種類一律以空值處理。
 */
function eg_machine_label_sql(string $mAlias = 'm', ?string $ptAlias = null): string {
    $out = [];
    foreach (eg_machine_label_fields() as $key => $def) {
        $expr = str_replace(['{m}', '{pt}'], [$mAlias, (string)$ptAlias], $def[1]);
        if (strpos($def[1], '{pt}') !== false && $ptAlias === null) $expr = 'NULL';
        $out[] = $expr . ' AS ' . $key;
    }
    return implode(', ', $out);
}

/** 同一組欄位的「不帶別名」版本，給 GROUP BY 用（MySQL 的 ONLY_FULL_GROUP_BY 下，
 *  把這些欄位一起 GROUP BY 最保險，不必賭它有沒有推導出函式相依）。 */
function eg_machine_label_group_sql(string $mAlias = 'm', ?string $ptAlias = null): string {
    $out = [];
    foreach (eg_machine_label_fields() as $def) {
        if (strpos($def[1], '{pt}') !== false && $ptAlias === null) continue;
        $out[] = str_replace(['{m}', '{pt}'], [$mAlias, (string)$ptAlias], $def[1]);
    }
    return implode(', ', $out);
}

/** 依設定把一列機台資料組成顯示字串；設定的欄位全空時退回機台名稱（不會變成空白或一串分隔符） */
function eg_machine_label(array $row, array $cfg): string {
    $parts = [];
    foreach ($cfg['fields'] as $f) {
        $v = trim((string)($row[$f] ?? ''));
        if ($v !== '') $parts[] = $v;
    }
    if ($parts) return implode($cfg['sep'], $parts);
    return trim((string)($row['machine'] ?? ''));
}

/** 批次套用：替每一列補上 machine_label 欄位 */
function eg_machine_label_apply(array $rows, array $cfg): array {
    foreach ($rows as &$r) { $r['machine_label'] = eg_machine_label($r, $cfg); }
    unset($r);
    return $rows;
}
