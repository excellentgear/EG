<?php
/**
 * 加工排程：機台分類（唯一實作）
 *  - ps_machine_category      分類（名稱、顯示順序）
 *  - ps_machine_category_map  機台→分類（machine_id 為主鍵＝一台機台只能屬於一個分類）
 *  - ps_unassigned_cat        未指派工單→分類（未指派工單本身沒有機台，由生管自行歸類；分類內順序＝未指派清單的順序）
 * 一個分類都沒建時，畫面完全維持原樣。
 */

function psc_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ps_machine_category (
        cat_id INT AUTO_INCREMENT PRIMARY KEY,
        cat_name VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ps_machine_category_map (
        machine_id INT NOT NULL PRIMARY KEY,
        cat_id INT NOT NULL,
        KEY idx_cat (cat_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ps_unassigned_cat (
        bom_ing_fid INT NOT NULL PRIMARY KEY,
        cat_id INT NOT NULL,
        KEY idx_cat (cat_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 畫面用資料：['cats'=>[{id,name}], 'machine'=>{機台id=>分類id}, 'card'=>{bom_ing_fid=>分類id}] */
function psc_data(PDO $pdo): array
{
    $out = ['cats' => [], 'machine' => new stdClass(), 'card' => new stdClass()];
    try {
        psc_ensure($pdo);
        foreach ($pdo->query("SELECT cat_id, cat_name FROM ps_machine_category ORDER BY sort_order, cat_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['cats'][] = ['id' => (int)$r['cat_id'], 'name' => $r['cat_name']];
        }
        if (!$out['cats']) return $out;
        $m = [];
        foreach ($pdo->query("SELECT machine_id, cat_id FROM ps_machine_category_map")->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(string)$r['machine_id']] = (int)$r['cat_id'];
        $c = [];
        foreach ($pdo->query("SELECT bom_ing_fid, cat_id FROM ps_unassigned_cat")->fetchAll(PDO::FETCH_ASSOC) as $r) $c[(string)$r['bom_ing_fid']] = (int)$r['cat_id'];
        $out['machine'] = (object)$m;
        $out['card'] = (object)$c;
    } catch (Throwable $e) {
        error_log('psc_data: ' . $e->getMessage());
    }
    return $out;
}

/** 整批儲存分類（名稱、順序、成員機台）。$cats = [{id,name,machines:[機台id]}]，陣列順序＝顯示順序 */
function psc_save_cats(PDO $pdo, array $cats): void
{
    psc_ensure($pdo);   // DDL 一律在交易之前
    $names = [];
    $seen = [];
    foreach ($cats as $c) {
        $n = trim((string)($c['name'] ?? ''));
        if ($n === '') throw new Exception('分類名稱不可空白');
        if (mb_strlen($n) > 30) throw new Exception('分類名稱最多 30 字：' . $n);
        $k = mb_strtolower($n);
        if (isset($names[$k])) throw new Exception('分類名稱重複：' . $n);
        $names[$k] = 1;
        foreach ((array)($c['machines'] ?? []) as $mid) {
            $mid = (int)$mid;
            if ($mid <= 0) continue;
            if (isset($seen[$mid])) throw new Exception('同一台機台只能設定在一個分類（機台編號 ' . $mid . ' 重複）');
            $seen[$mid] = 1;
        }
    }
    $pdo->beginTransaction();
    try {
        $ids = [];
        $chk = $pdo->prepare("SELECT 1 FROM ps_machine_category WHERE cat_id=?");
        $upd = $pdo->prepare("UPDATE ps_machine_category SET cat_name=?, sort_order=? WHERE cat_id=?");
        $ins = $pdo->prepare("INSERT INTO ps_machine_category (cat_name, sort_order) VALUES (?, ?)");
        foreach (array_values($cats) as $i => $c) {
            $id = (int)($c['id'] ?? 0);
            $name = trim((string)$c['name']);
            $exists = false;
            if ($id > 0) { $chk->execute([$id]); $exists = (bool)$chk->fetchColumn(); }
            if ($exists) {
                $upd->execute([$name, $i + 1, $id]);
            } else {
                $ins->execute([$name, $i + 1]);
                $id = (int)$pdo->lastInsertId();
            }
            $ids[$i] = $id;
        }
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $pdo->exec("DELETE FROM ps_machine_category WHERE cat_id NOT IN ($in)");
            $pdo->exec("DELETE FROM ps_unassigned_cat WHERE cat_id NOT IN ($in)");
        } else {
            $pdo->exec("DELETE FROM ps_machine_category");
            $pdo->exec("DELETE FROM ps_unassigned_cat");
        }
        $pdo->exec("DELETE FROM ps_machine_category_map");
        $mi = $pdo->prepare("INSERT INTO ps_machine_category_map (machine_id, cat_id) VALUES (?, ?)");
        foreach (array_values($cats) as $i => $c) {
            foreach ((array)($c['machines'] ?? []) as $mid) {
                $mid = (int)$mid;
                if ($mid > 0) $mi->execute([$mid, $ids[$i]]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** 未指派工單歸類：$map = [bom_ing_fid => 分類id（0＝取消分類）] */
function psc_save_cards(PDO $pdo, array $map): int
{
    psc_ensure($pdo);
    $valid = [];
    foreach ($pdo->query("SELECT cat_id FROM ps_machine_category")->fetchAll(PDO::FETCH_COLUMN) as $id) $valid[(int)$id] = 1;
    $n = 0;
    $set = $pdo->prepare("REPLACE INTO ps_unassigned_cat (bom_ing_fid, cat_id) VALUES (?, ?)");
    $del = $pdo->prepare("DELETE FROM ps_unassigned_cat WHERE bom_ing_fid=?");
    foreach ($map as $fid => $cid) {
        $fid = (int)$fid; $cid = (int)$cid;
        if ($fid <= 0) continue;
        if ($cid > 0 && isset($valid[$cid])) { $set->execute([$fid, $cid]); $n++; }
        elseif ($cid === 0) { $del->execute([$fid]); $n++; }
    }
    return $n;
}
