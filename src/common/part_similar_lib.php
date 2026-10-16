<?php
/**
 * part_similar_lib.php —— 「類似料號」偵測（全站唯一實作，2026-10-16 使用者交辦）
 *
 * 背景：客戶常做「一樣的東西、料號不同」。接單／報價／批圖時要能提醒「這個料號跟某某料號很像」，
 * 由使用者自己決定要不要去看。**本檔只讀不寫**（設定除外），任何一支函式出錯都回空結果，
 * 絕不丟例外給呼叫端——這個功能是輔助提示，不可以因為它壞掉而影響報價、訂單、批圖、主檔存檔。
 *
 * 判定分三級（越前面越像）：
 *   B  齒輪規格完全相同 ＋ 工件總長相同 ＋ 管理員指定的比對標籤全部相同
 *   A  齒輪規格完全相同 ＋ 工件總長相同
 *   C  齒輪規格完全相同，但總長其中一方沒填（無法確認；只列為「參考」）
 *   兩邊總長都有填卻不同 → 不算類似。
 * 「完全相同」＝逐欄位正規化後相等（模數／齒數／齒寬／螺旋方向與角度／壓力角／轉位係數…），不做容差。
 *
 * 範圍限制（要讓使用者知道）：系統裡只有齒輪類料號（d_setting.Type='G'）有結構化的齒輪規格，
 * 其他種類（軸件、片狀…）沒有可比對的齒輪資料，**目前不偵測**。
 *
 * 設定存 system_parameters(param_group='PART_SIMILAR')：
 *   enabled        1/0，預設 1
 *   compare_labels JSON 陣列，管理員指定「第 B 級要比對哪些標籤」的 label_id，預設空（＝只有 A/C 級）
 */

const PS_GROUP = 'PART_SIMILAR';

/** 讀設定（查不到一律回預設值） */
function ps_settings(PDO $db): array {
    $out = ['enabled' => 1, 'labels' => []];
    try {
        $st = $db->prepare("SELECT param_key, param_value FROM system_parameters WHERE param_group=?");
        $st->execute([PS_GROUP]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['param_key'] === 'enabled') $out['enabled'] = ((string)$r['param_value'] === '0') ? 0 : 1;
            if ($r['param_key'] === 'compare_labels') {
                $a = json_decode((string)$r['param_value'], true);
                if (is_array($a)) $out['labels'] = array_values(array_unique(array_filter(array_map('intval', $a))));
            }
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 存設定。label 一律檢查真的存在且啟用（防止直打 API 塞不存在的 id）。 */
function ps_save_settings(PDO $db, int $enabled, array $labels): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $labels))));
    if (count($ids) > 30) return ['ok' => false, 'message' => '比對標籤最多選 30 個'];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT label_id FROM dict_label WHERE is_active=1 AND label_id IN ($ph)");
        $st->execute($ids);
        $ok = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_intersect($ids, $ok));
    }
    $ins = $db->prepare("INSERT INTO system_parameters (param_group,param_key,param_value) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE param_value=VALUES(param_value)");
    $ins->execute([PS_GROUP, 'enabled', $enabled ? '1' : '0']);
    $ins->execute([PS_GROUP, 'compare_labels', json_encode($ids)]);
    return ['ok' => true, 'labels' => $ids];
}

/** 可供管理員勾選的標籤清單 */
function ps_label_choices(PDO $db): array {
    try {
        return $db->query("SELECT label_id, label_name FROM dict_label WHERE is_active=1 ORDER BY sort_order, label_id")
                  ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** 數字正規化：15.50→15.5、0/空→''；非數字原樣 trim */
function ps_num($v): string {
    $s = trim((string)($v ?? ''));
    if ($s === '') return '';
    if (is_numeric($s)) {
        $f = (float)$s;
        if (abs($f) < 1e-9) return '';
        $s = rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.');
    }
    return $s;
}

/** 齒輪規格欄位定義：key => [顯示名稱, 取值函式] */
function ps_gear_fields(): array {
    return [
        'type'     => '齒型',
        'module'   => '模數',
        'teeth'    => '齒數',
        'face'     => '齒寬',
        'helix_d'  => '螺旋方向',
        'helix_a'  => '螺旋角',
        'pa'       => '壓力角',
        'shift'    => '轉位係數',
        'chain'    => '鏈條尺寸',
        'pitch'    => '節距',
        'roller'   => '滾子直徑',
        'starts'   => '條數',
        'pulley'   => '皮帶輪型式',
        'pld'      => 'PLD',
        'sp_type'  => '栓槽型式',
        'sp_major' => '栓槽大徑',
        'sp_minor' => '栓槽小徑',
        'sp_width' => '栓槽齒寬',
        'sp_std'   => '栓槽標準',
        'sp_nom'   => '栓槽公稱徑',
    ];
}

/** 一列 d_setting_gear → 正規化後的 [欄位key => 字串]（比對用） */
function ps_gear_norm(array $g, array $typeNames): array {
    $mod = '';
    if (function_exists('eg_gear_module_text')) $mod = eg_gear_module_text($g);
    else $mod = trim((string)($g['module_display'] ?? '')) ?: trim((string)($g['Module'] ?? ''));
    $mod = strtoupper(str_replace(' ', '', $mod));
    $pa = ps_num($g['Pressure_Angle'] ?? '');
    $pa = rtrim($pa, '°');
    if ($pa === '') $pa = '20';   // 畫面顯示的預設值，空白視同 20
    $tid = (int)($g['Gear_Type'] ?? 0);
    return [
        'type'     => $tid > 0 ? ($typeNames[$tid] ?? ('#' . $tid)) : '',
        'module'   => $mod,
        'teeth'    => ps_num($g['Teeth'] ?? ''),
        'face'     => ps_num($g['Face_Width'] ?? ''),
        'helix_d'  => trim((string)($g['Helix_Direction'] ?? '')),
        'helix_a'  => ps_num($g['Helix_Angle'] ?? ''),
        'pa'       => $pa,
        'shift'    => ps_num($g['Profile_Shift_X'] ?? ''),
        'chain'    => trim((string)($g['spec_chain_size'] ?? '')),
        'pitch'    => ps_num($g['spec_pitch'] ?? ''),
        'roller'   => ps_num($g['spec_roller_dia'] ?? ''),
        'starts'   => ps_num($g['spec_starts'] ?? ''),
        'pulley'   => trim((string)($g['spec_pulley_profile'] ?? '')),
        'pld'      => ps_num($g['spec_pld'] ?? ''),
        'sp_type'  => trim((string)($g['spec_spline_type'] ?? '')),
        'sp_major' => ps_num($g['spec_spline_major_dia'] ?? ''),
        'sp_minor' => ps_num($g['spec_spline_minor_dia'] ?? ''),
        'sp_width' => ps_num($g['spec_spline_width'] ?? ''),
        'sp_std'   => trim((string)($g['spec_spline_std'] ?? '')),
        'sp_nom'   => ps_num($g['spec_spline_nominal_dia'] ?? ''),
    ];
}

function ps_gear_type_names(PDO $db): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        foreach ($db->query("SELECT gear_type_id, type_name FROM dict_gear_type") as $r) $m[(int)$r['gear_type_id']] = (string)$r['type_name'];
    } catch (Throwable $e) {}
    return $m;
}

/** 某料號全部齒輪列（已正規化）。回傳 [ ['norm'=>..., 'sig'=>string], ... ]，依簽章排序以便兩邊對齊 */
function ps_gear_list(PDO $db, int $dId): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT * FROM d_setting_gear WHERE d_setting_id=? ORDER BY gear_id");
        $st->execute([$dId]);
        $tn = ps_gear_type_names($db);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $n = ps_gear_norm($g, $tn);
            // 完全沒有齒數的列（空白列）不算齒輪資料
            if ($n['teeth'] === '' && $n['module'] === '' && $n['sp_type'] === '' && $n['chain'] === '') continue;
            // 轉位係數常常有一方沒填（實案例：同一支圖，一支填 -0.29、另一支空白），所以不放進簽章，
            // 改由 ps_find 另外比：兩邊都有填且不同才算不同；只有一方填就算相同但在原因裡註明。
            $sigParts = $n; unset($sigParts['shift']);
            $out[] = ['norm' => $n, 'sig' => implode('|', $sigParts)];
        }
    } catch (Throwable $e) {}
    usort($out, function ($a, $b) { return strcmp($a['sig'], $b['sig']); });
    return $out;
}

function ps_gear_sig_all(array $list): string {
    return implode(';', array_column($list, 'sig'));
}

/** 標籤值：label_id => ['name'=>..., 'vals'=>[排序後字串...]] */
function ps_label_values(PDO $db, int $dId, ?array $onlyIds = null): array {
    $out = [];
    try {
        $sql = "SELECT m.map_id, m.label_id, m.input_value, l.label_name FROM item_label_map m
                JOIN dict_label l ON l.label_id=m.label_id WHERE m.d_id=? AND l.is_active=1";
        $par = [$dId];
        if ($onlyIds !== null) {
            if (!$onlyIds) return [];
            $sql .= " AND m.label_id IN (" . implode(',', array_map('intval', $onlyIds)) . ")";
        }
        $st = $db->prepare($sql . " ORDER BY l.sort_order, m.label_id, m.map_id");
        $st->execute($par);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $mapIds = array_map('intval', array_column($rows, 'map_id'));
        $subs = [];
        $ss = $db->query("SELECT s.parent_map_id, s.sub_id, s.input_value, d.sub_name FROM item_sub_label_map s
                          LEFT JOIN dict_label_sub d ON d.sub_id=s.sub_id
                          WHERE s.parent_map_id IN (" . implode(',', $mapIds) . ")");
        foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $s) $subs[(int)$s['parent_map_id']][] = $s;
        foreach ($rows as $r) {
            $lid = (int)$r['label_id'];
            if (!isset($out[$lid])) $out[$lid] = ['name' => (string)$r['label_name'], 'vals' => []];
            $parts = [];
            $iv = trim((string)($r['input_value'] ?? ''));
            if ($iv !== '') $parts[] = ps_num($iv);
            foreach ($subs[(int)$r['map_id']] ?? [] as $s) {
                $sv = trim((string)($s['input_value'] ?? ''));
                $nm = trim((string)($s['sub_name'] ?? ''));
                $sv = ps_num($sv);
                if ($nm !== '' || $sv !== '') $parts[] = ($nm !== '' && $sv !== '') ? ($nm . ':' . $sv) : ($nm !== '' ? $nm : $sv);
            }
            sort($parts);
            $out[$lid]['vals'][] = $parts ? implode(' / ', $parts) : '（已掛）';
        }
        foreach ($out as &$o) { sort($o['vals']); }
        unset($o);
    } catch (Throwable $e) {}
    return $out;
}

/** 料號基本資料（顯示用） */
function ps_part_head(PDO $db, int $dId): ?array {
    try {
        $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id AS part_no, d.Drawing_No AS drawing_no, d.Spec_No AS spec_no,
                                   d.Revision AS rev, d.Type AS ptype, d.Customer_Id AS customer_id, d.workpiece_length AS wlen,
                                   c.customer AS customer
                            FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                            WHERE d.d_id=?");
        $st->execute([$dId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** 用中文寫出齒輪規格摘要（原因說明用） */
function ps_gear_brief(array $list): string {
    $bits = [];
    foreach ($list as $g) {
        $n = $g['norm'];
        $b = [];
        if ($n['type'] !== '') $b[] = $n['type'];
        if ($n['module'] !== '') $b[] = $n['module'];
        if ($n['teeth'] !== '') $b[] = $n['teeth'] . '齒';
        if ($n['face'] !== '') $b[] = '齒寬' . $n['face'];
        if ($n['helix_a'] !== '') $b[] = '螺旋角' . $n['helix_a'] . ($n['helix_d'] !== '' ? $n['helix_d'] : '');
        if ($n['pa'] !== '') $b[] = '壓力角' . $n['pa'];
        if ($n['shift'] !== '') $b[] = 'X' . $n['shift'];
        $bits[] = implode(' ', $b);
    }
    return implode('；', $bits);
}

/**
 * 找類似料號。
 * @return array ['ok'=>bool,'enabled'=>bool,'note'=>string,'target'=>[...],'items'=>[...],'total'=>int,'label_cfg'=>int]
 *   items 每筆：d_id/part_no/drawing_no/customer/same_customer/level(B|A|C)/reasons[]
 */
function ps_find(PDO $db, int $dId, int $limit = 12): array {
    $res = ['ok' => true, 'enabled' => true, 'note' => '', 'target' => null, 'items' => [], 'total' => 0, 'label_cfg' => 0];
    try {
        $cfg = ps_settings($db);
        $res['label_cfg'] = count($cfg['labels']);
        if (!$cfg['enabled']) { $res['enabled'] = false; return $res; }
        $head = ps_part_head($db, $dId);
        if (!$head) return $res;
        $res['target'] = ['d_id' => $dId, 'part_no' => $head['part_no'], 'drawing_no' => $head['drawing_no'], 'customer' => $head['customer']];
        $mine = ps_gear_list($db, $dId);
        if (!$mine) { $res['note'] = 'no_gear'; return $res; }   // 沒有齒輪規格＝沒有比對依據，安靜略過
        $mySig = ps_gear_sig_all($mine);
        $myLen = ps_num($head['wlen']);
        // 預篩：只撈「齒數」與我任一列相同的料號，再逐一比完整簽章（全庫齒輪列只有數百筆，很快）
        $teeth = array_values(array_unique(array_filter(array_map(function ($g) { return $g['norm']['teeth']; }, $mine), 'strlen')));
        $cands = [];
        if ($teeth) {
            $ph = implode(',', array_fill(0, count($teeth), '?'));
            $st = $db->prepare("SELECT DISTINCT d_setting_id FROM d_setting_gear WHERE Teeth IN ($ph) AND d_setting_id<>?");
            $st->execute(array_merge($teeth, [$dId]));
            $cands = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } else {
            // 沒有齒數（鏈輪／栓槽…）：以齒型預篩
            $st = $db->prepare("SELECT DISTINCT d_setting_id FROM d_setting_gear WHERE d_setting_id<>? AND (spec_chain_size=? OR spec_spline_type=?)");
            $st->execute([$dId, $mine[0]['norm']['chain'], $mine[0]['norm']['sp_type']]);
            $cands = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $myTags = $cfg['labels'] ? ps_label_values($db, $dId, $cfg['labels']) : [];
        $found = [];
        foreach ($cands as $cid) {
            $theirs = ps_gear_list($db, $cid);
            if (!$theirs || ps_gear_sig_all($theirs) !== $mySig) continue;
            $h = ps_part_head($db, $cid);
            if (!$h) continue;
            // 轉位係數：兩邊都有填且不同 → 不算類似；只有一方填 → 算相同並註明
            $shiftNote = ''; $shiftBad = false;
            foreach ($mine as $i => $mg) {
                $a = $mg['norm']['shift']; $b = $theirs[$i]['norm']['shift'] ?? '';
                if ($a !== '' && $b !== '' && abs((float)$a - (float)$b) > 0.00005) { $shiftBad = true; break; }
                if (($a === '') !== ($b === '')) $shiftNote = '轉位係數只有一方有填（' . ($a !== '' ? '本料號 X' . $a : '對方 X' . $b) . '），視為相同';
            }
            if ($shiftBad) continue;
            $theirLen = ps_num($h['wlen']);
            $lenOk = null;   // null＝無法確認
            if ($myLen !== '' && $theirLen !== '') {
                if (abs((float)$myLen - (float)$theirLen) > 0.0049) continue;   // 兩邊都有填卻不同＝不是類似
                $lenOk = true;
            }
            $reasons = ['齒輪規格完全相同（' . ps_gear_brief($mine) . '）'];
            if ($shiftNote !== '') $reasons[] = $shiftNote;
            if ($lenOk === true) $reasons[] = '工件總長相同（' . $myLen . ' mm）';
            else $reasons[] = '工件總長' . ($myLen === '' && $theirLen === '' ? '雙方都未填' : ($myLen === '' ? '本料號未填' : '對方未填')) . '，無法確認是否相同';
            $level = ($lenOk === true) ? 'A' : 'C';
            if ($level === 'A' && $cfg['labels'] && $myTags) {
                $theirTags = ps_label_values($db, $cid, $cfg['labels']);
                $same = true;
                $names = [];
                foreach ($cfg['labels'] as $lid) {
                    $a = $myTags[$lid]['vals'] ?? [];
                    $b = $theirTags[$lid]['vals'] ?? [];
                    if ($a !== $b) { $same = false; break; }
                    if ($a) $names[] = $myTags[$lid]['name'];
                }
                if ($same && $names) { $level = 'B'; $reasons[] = '標籤相同：' . implode('、', $names); }
            }
            $found[] = [
                'd_id' => $cid, 'part_no' => $h['part_no'], 'drawing_no' => $h['drawing_no'], 'customer' => $h['customer'],
                'same_customer' => ((string)$h['customer_id'] === (string)$head['customer_id'] && (string)$head['customer_id'] !== ''),
                'level' => $level, 'reasons' => $reasons,
            ];
        }
        $rank = ['B' => 0, 'A' => 1, 'C' => 2];
        usort($found, function ($a, $b) use ($rank) {
            if ($rank[$a['level']] !== $rank[$b['level']]) return $rank[$a['level']] <=> $rank[$b['level']];
            if ($a['same_customer'] !== $b['same_customer']) return $a['same_customer'] ? -1 : 1;
            return strcmp((string)$a['part_no'], (string)$b['part_no']);
        });
        $res['total'] = count($found);
        $res['items'] = array_slice($found, 0, max(1, $limit));
    } catch (Throwable $e) {
        error_log('ps_find: ' . $e->getMessage());
        return ['ok' => false, 'enabled' => true, 'note' => 'error', 'target' => null, 'items' => [], 'total' => 0, 'label_cfg' => 0];
    }
    return $res;
}

/** 兩個料號的逐項比對（比對跳窗用）。所有圖面連結由前端用 d_id 組。 */
function ps_compare(PDO $db, int $a, int $b): array {
    $out = ['ok' => false];
    try {
        $ha = ps_part_head($db, $a); $hb = ps_part_head($db, $b);
        if (!$ha || !$hb) return $out;
        $cfg = ps_settings($db);
        $rows = [];
        $add = function (string $group, string $name, string $va, string $vb, bool $cfgd = false) use (&$rows) {
            $rows[] = ['group' => $group, 'name' => $name, 'a' => $va, 'b' => $vb, 'same' => ($va === $vb), 'cfg' => $cfgd];
        };
        $add('基本資料', '客戶', (string)$ha['customer'], (string)$hb['customer']);
        $add('基本資料', '料號', (string)$ha['part_no'], (string)$hb['part_no']);
        $add('基本資料', '圖號', (string)$ha['drawing_no'], (string)$hb['drawing_no']);
        $add('基本資料', '版次', (string)$ha['rev'], (string)$hb['rev']);
        $ga = ps_gear_list($db, $a); $gb = ps_gear_list($db, $b);
        $n = max(count($ga), count($gb));
        $fields = ps_gear_fields();
        for ($i = 0; $i < $n; $i++) {
            $grp = $n > 1 ? ('齒輪規格 #' . ($i + 1)) : '齒輪規格';
            foreach ($fields as $k => $label) {
                $va = $ga[$i]['norm'][$k] ?? ''; $vb = $gb[$i]['norm'][$k] ?? '';
                if ($va === '' && $vb === '') continue;   // 兩邊都沒有的欄位不佔版面
                $add($grp, $label, $va, $vb);
            }
        }
        $add('尺寸', '工件總長 (mm)', ps_num($ha['wlen']), ps_num($hb['wlen']));
        $ta = ps_label_values($db, $a); $tb = ps_label_values($db, $b);
        $ids = array_values(array_unique(array_merge(array_keys($ta), array_keys($tb))));
        // 管理員指定要比對的標籤排前面
        usort($ids, function ($x, $y) use ($cfg) {
            $cx = in_array($x, $cfg['labels'], true) ? 0 : 1; $cy = in_array($y, $cfg['labels'], true) ? 0 : 1;
            return $cx <=> $cy ?: $x <=> $y;
        });
        foreach ($ids as $lid) {
            $nm = $ta[$lid]['name'] ?? $tb[$lid]['name'];
            $add('標籤', $nm, implode('；', $ta[$lid]['vals'] ?? []), implode('；', $tb[$lid]['vals'] ?? []), in_array($lid, $cfg['labels'], true));
        }
        $out = ['ok' => true, 'a' => $ha, 'b' => $hb, 'rows' => $rows];
    } catch (Throwable $e) { error_log('ps_compare: ' . $e->getMessage()); }
    return $out;
}

/** 製程名稱：quotation_item.process_notes 存的是子標籤 id（逗號分隔） */
function ps_process_names(PDO $db, string $notes): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        try { foreach ($db->query("SELECT sub_tag_id, sub_tag_name FROM quotation_process_sub_tag") as $r) $map[(int)$r['sub_tag_id']] = (string)$r['sub_tag_name']; } catch (Throwable $e) {}
    }
    $names = [];
    foreach (preg_split('/[,\s]+/', trim($notes)) as $t) {
        if ($t !== '' && ctype_digit($t) && isset($map[(int)$t])) $names[] = $map[(int)$t];
    }
    return implode('、', $names);
}

/** 該料號最近 N 筆報價（已送出、非草稿、非備註列） */
function ps_recent_quotes(PDO $db, int $dId, int $n = 3): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT qi.item_id, qi.quote_id, ql.quote_no, ql.quote_date, qi.quantity, qi.unit_price, ql.currency,
                                   qi.is_tiered, qi.process_notes, qi.specification
                            FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id
                            WHERE qi.d_setting_d_id=? AND COALESCE(qi.note_only,0)=0
                              AND COALESCE(ql.is_draft,0)=0 AND COALESCE(ql.is_temp_save,0)=0
                            ORDER BY ql.quote_date DESC, qi.item_id DESC LIMIT " . max(1, (int)$n));
        $st->execute([$dId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'item_id' => (int)$r['item_id'], 'no' => $r['quote_no'], 'date' => $r['quote_date'],
                'process' => ps_process_names($db, (string)$r['process_notes']),
                'qty' => ps_num($r['quantity']), 'price' => ps_num($r['unit_price']), 'currency' => $r['currency'],
                'tiered' => (int)$r['is_tiered'] === 1,
            ];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 該料號最近 N 筆訂單（排除暫停/取消） */
function ps_recent_orders(PDO $db, int $dId, int $n = 3): array {
    $out = [];
    try {
        $st = $db->prepare("SELECT Order_id, Order_oo, Order_date, Qty, unit_price, currency, Processing_items
                            FROM order_track WHERE d_id_ID=? AND COALESCE(Order_status,0)<>6
                            ORDER BY Order_date DESC, Order_id DESC LIMIT " . max(1, (int)$n));
        $st->execute([$dId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['order_id' => (int)$r['Order_id'], 'no' => $r['Order_oo'], 'date' => $r['Order_date'],
                      'process' => (string)$r['Processing_items'], 'qty' => ps_num($r['Qty']),
                      'price' => ps_num($r['unit_price']), 'currency' => $r['currency']];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** 單筆報價項目內容（點「報價單號」開的明細） */
function ps_quote_detail(PDO $db, int $itemId): ?array {
    try {
        $st = $db->prepare("SELECT qi.*, ql.quote_no, ql.quote_date, ql.client_name, ql.currency, ql.note AS quote_note
                            FROM quotation_item qi JOIN quotation_list ql ON ql.quote_id=qi.quote_id WHERE qi.item_id=?");
        $st->execute([$itemId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $t = $db->prepare("SELECT qty_min, qty_max, unit_price, amount FROM quotation_item_tier WHERE item_id=? ORDER BY sort_order, qty_min");
        $t->execute([$itemId]);
        return [
            'no' => $r['quote_no'], 'date' => $r['quote_date'], 'client' => $r['client_name'], 'currency' => $r['currency'],
            'part' => $r['product_id'], 'spec' => $r['specification'], 'process' => ps_process_names($db, (string)$r['process_notes']),
            'qty' => ps_num($r['quantity']), 'unit' => $r['unit'], 'price' => ps_num($r['unit_price']), 'amount' => ps_num($r['amount']),
            'tiers' => $t->fetchAll(PDO::FETCH_ASSOC), 'note' => $r['quote_note'],
        ];
    } catch (Throwable $e) { return null; }
}

/** 單筆訂單內容（點「訂單號」開的明細） */
function ps_order_detail(PDO $db, int $orderId): ?array {
    try {
        $st = $db->prepare("SELECT Order_oo, Order_date, Delivery_date, Client_name, Qty, unit_price, currency, Processing_items,
                                   Specification, Order_ps, C_order, Order_status
                            FROM order_track WHERE Order_id=?");
        $st->execute([$orderId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return ['no' => $r['Order_oo'], 'date' => $r['Order_date'], 'delivery' => $r['Delivery_date'], 'client' => $r['Client_name'],
                'qty' => ps_num($r['Qty']), 'price' => ps_num($r['unit_price']), 'currency' => $r['currency'],
                'process' => $r['Processing_items'], 'spec' => $r['Specification'], 'note' => $r['Order_ps'], 'customer_order' => $r['C_order']];
    } catch (Throwable $e) { return null; }
}

/** 依料號關鍵字找料號（查詢用）：回 d_id／料號／客戶，最多 15 筆；同時標出有沒有齒輪規格 */
function ps_search_parts(PDO $db, string $kw): array {
    $kw = trim($kw);
    if (mb_strlen($kw) < 2) return [];
    try {
        $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id AS part_no, c.customer,
                                   EXISTS(SELECT 1 FROM d_setting_gear g WHERE g.d_setting_id=d.d_id) AS has_gear
                            FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                            WHERE d.D_Setting_Id LIKE ? ORDER BY (d.D_Setting_Id=?) DESC, d.d_id DESC LIMIT 15");
        $st->execute(['%' . $kw . '%', $kw]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
