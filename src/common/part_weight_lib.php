<?php
/**
 * 料號「重量自動計算」資料（唯一實作）— 主檔管理 料號編輯畫面的「自動計算」跳窗用
 *
 * 材質密度對照表自成一張表 part_weight_density（材質子標籤 → 密度 g/cm³），與 KPI 生產效率分析
 * （views/pm/kpi_main.php）完全脫鉤：KPI 那頁日後廢除也不受影響。第一次使用時把 KPI 那張表的
 * 現有三筆「複製」過來當初始值，之後兩邊各自獨立，互不連動。
 *
 * 公式（圓柱）：W(kg)=π/4 × D²(mm²) × L(mm) × ρ(g/cm³) ÷ 1,000,000；
 * 錐（圓台）：V=π/12 × L × (D²+D·d+d²)。實際計算在前端跳窗內做（使用者可逐項調整），
 * 本檔只負責：密度表、材質標籤編號設定、齒輪的齒部外徑／齒寬（沒填最大外徑與長度時的預設來源）。
 */

/** 建表＋初始資料（可重複呼叫；DDL 不可在交易中執行） */
function eg_pw_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if ($pdo->inTransaction()) return;
        if (!$pdo->query("SHOW TABLES LIKE 'part_weight_density'")->fetchColumn()) {
            $pdo->exec("CREATE TABLE part_weight_density (
                density_id INT AUTO_INCREMENT PRIMARY KEY,
                sub_id INT NOT NULL COMMENT '材質標籤底下的子標籤 dict_label_sub.sub_id',
                density_g DECIMAL(8,4) NOT NULL COMMENT '密度 g/cm³',
                sort_order INT NOT NULL DEFAULT 0,
                modified_by VARCHAR(50) NULL,
                modified_at DATETIME NULL,
                UNIQUE KEY uk_sub (sub_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料號重量計算：材質密度對照'");
            // 一次性複製 KPI 那張表目前的設定（只取有綁定子標籤的）；之後兩邊互不連動
            if ($pdo->query("SHOW TABLES LIKE 'kpi_material_density'")->fetchColumn()) {
                $pdo->exec("INSERT IGNORE INTO part_weight_density (sub_id, density_g, sort_order, modified_by, modified_at)
                            SELECT bound_sub_id, density_g, sort_order, 'KPI初始複製', NOW() FROM kpi_material_density
                            WHERE bound_sub_id IS NOT NULL AND bound_sub_id>0");
            }
        }
    } catch (Throwable $e) { error_log('[part_weight] ensure: ' . $e->getMessage()); }
}

/** 材質標籤（dict_label）編號：優先用設定值，沒有就找名稱為「材質」的標籤 */
function eg_pw_material_label_id(PDO $pdo): int {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='part_weight_material_label_id'")->fetchColumn();
        if ($v !== false && (int)$v > 0) return (int)$v;
    } catch (Throwable $e) {}
    try {
        if ($pdo->query("SHOW TABLES LIKE 'kpi_weight_config'")->fetchColumn()) {
            $v = $pdo->query("SELECT config_val FROM kpi_weight_config WHERE config_key='keyword_label_id'")->fetchColumn();
            if ($v !== false && (int)$v > 0) return (int)$v;
        }
    } catch (Throwable $e) {}
    try {
        $v = $pdo->query("SELECT label_id FROM dict_label WHERE label_name='材質' AND is_active=1 ORDER BY label_id LIMIT 1")->fetchColumn();
        if ($v !== false) return (int)$v;
    } catch (Throwable $e) {}
    return 0;
}

/** 跳窗需要的背景資料：密度表、材質子標籤清單、齒輪預設外徑／齒寬（d_id>0 才查） */
function eg_pw_context(PDO $pdo, int $dId, callable $daExpr): array {
    eg_pw_ensure($pdo);
    $lid = eg_pw_material_label_id($pdo);
    $subs = [];
    if ($lid > 0) {
        $st = $pdo->prepare("SELECT sub_id, sub_name FROM dict_label_sub WHERE label_id=? AND is_active=1 ORDER BY sort_order, sub_id");
        $st->execute([$lid]);
        $subs = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $dens = [];
    try {
        foreach ($pdo->query("SELECT d.density_id, d.sub_id, d.density_g, COALESCE(s.sub_name,'') AS sub_name
                              FROM part_weight_density d LEFT JOIN dict_label_sub s ON s.sub_id=d.sub_id
                              ORDER BY d.sort_order, d.density_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dens[] = ['sub_id' => (int)$r['sub_id'], 'sub_name' => $r['sub_name'], 'density_g' => (float)$r['density_g']];
        }
    } catch (Throwable $e) {}
    $gear = null;
    if ($dId > 0) {
        try {
            $da = $daExpr('g', 'dt');
            $st = $pdo->prepare("SELECT MAX(ROUND($da, 3)) AS da, MAX(g.Face_Width) AS fw
                                 FROM d_setting_gear g LEFT JOIN dict_gear_type dt ON dt.gear_type_id=g.Gear_Type
                                 WHERE g.d_setting_id=? AND CAST(REPLACE(g.Module,'M','') AS DECIMAL(20,8))>0 AND g.Teeth>0");
            $st->execute([$dId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && ($r['da'] !== null || $r['fw'] !== null)) {
                $gear = ['da' => $r['da'] !== null ? (float)$r['da'] : null, 'fw' => $r['fw'] !== null ? (float)$r['fw'] : null];
            }
        } catch (Throwable $e) {}
    }
    // 標籤加工（鍵／中心牙孔／孔加工）要認的標籤名稱與孔加工子標籤名稱
    $tagLabels = []; $subsHole = [];
    try {
        foreach ($pdo->query("SELECT label_id, label_name FROM dict_label WHERE label_id IN (3,4,20)")->fetchAll(PDO::FETCH_ASSOC) as $r) $tagLabels[(string)$r['label_id']] = $r['label_name'];
        foreach ($pdo->query("SELECT sub_id, sub_name FROM dict_label_sub WHERE label_id=20")->fetchAll(PDO::FETCH_ASSOC) as $r) $subsHole[(string)$r['sub_id']] = $r['sub_name'];
    } catch (Throwable $e) {}
    return ['material_label_id' => $lid, 'subs' => $subs, 'densities' => $dens, 'gear' => $gear, 'tag_labels' => $tagLabels ?: new stdClass(), 'subs_hole' => $subsHole ?: new stdClass()];
}

/**
 * 儲存密度表（整張覆寫）。items=[{sub_id,density_g}]；回傳 [ok, 訊息]。
 * 同一個材質子標籤只能有一筆（重複視為錯誤，不默默覆蓋）。
 */
function eg_pw_save_densities(PDO $pdo, array $items, string $by): array {
    eg_pw_ensure($pdo);
    $seen = []; $clean = [];
    foreach ($items as $i => $it) {
        $sid = (int)($it['sub_id'] ?? 0);
        $dg  = round((float)($it['density_g'] ?? 0), 4);
        if ($sid <= 0 && $dg <= 0) continue;
        if ($sid <= 0) return [false, '第 ' . ($i + 1) . ' 列請選擇材質'];
        if ($dg <= 0 || $dg > 30) return [false, '第 ' . ($i + 1) . ' 列密度必須是大於 0 的數字（單位 g/cm³）'];
        if (isset($seen[$sid])) return [false, '第 ' . ($i + 1) . ' 列的材質已經設定過，同一種材質只能有一個密度'];
        $seen[$sid] = 1;
        $clean[] = [$sid, $dg];
    }
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM part_weight_density");
        $ins = $pdo->prepare("INSERT INTO part_weight_density (sub_id, density_g, sort_order, modified_by, modified_at) VALUES (?,?,?,?,NOW())");
        foreach ($clean as $k => $c) $ins->execute([$c[0], $c[1], $k * 10, $by]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, '儲存失敗：' . $e->getMessage()];
    }
    return [true, '已儲存'];
}

/** 清單用：一次查多個料號的重量 → [d_id => kg(float)]；沒重量（NULL 或 0）的不回傳 */
function eg_pw_weight_map(PDO $pdo, array $dIds): array {
    $dIds = array_values(array_unique(array_filter(array_map('intval', $dIds))));
    if (!$dIds) return [];
    $out = [];
    try {
        $st = $pdo->prepare("SELECT d_id, Weight_Kg FROM d_setting WHERE Weight_Kg IS NOT NULL AND Weight_Kg>0 AND d_id IN (" . implode(',', array_fill(0, count($dIds), '?')) . ")");
        $st->execute($dIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['d_id']] = (float)$r['Weight_Kg'];
    } catch (Throwable $e) {}
    return $out;
}
