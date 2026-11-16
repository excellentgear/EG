<?php
/**
 * 流程地圖（頁面導覽圖）共用庫
 *
 * 一張地圖＝多個節點（頁面或文字方塊）＋多條箭頭。節點掛 system_module_pages.page_id，
 * 網址與頁名一律即時查主檔，不存進地圖（改頁名／換網址不會失聯）。
 *
 * 權限＝完全照左側選單（sideAndTopBarMenu.html）的判定：
 *   ① user_module_permissions scope=page 以 page_id 命中
 *   ② scope=group 以 system_modules.module_code 命中該頁所屬群組
 *   ③ 單頁模組（system_modules.page_id）另可由 roles.module 的角色命中
 * 沒權限的節點，後端連網址都不回傳（不是只擋前端）。
 */
require_once __DIR__ . '/role_features_helper.php';

if (!function_exists('fmap_ensure_schema')) {
    function fmap_ensure_schema(PDO $db): void {
        static $done = false;
        if ($done) return;
        $done = true;
        $have = [];
        foreach ($db->query("SHOW TABLES LIKE 'flow_map%'")->fetchAll(PDO::FETCH_NUM) as $r) $have[$r[0]] = 1;
        if (!isset($have['flow_map'])) {
            $db->exec("CREATE TABLE flow_map (
                map_id INT AUTO_INCREMENT PRIMARY KEY,
                map_name VARCHAR(60) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                canvas_w INT NOT NULL DEFAULT 1400,
                canvas_h INT NOT NULL DEFAULT 700,
                created_at DATETIME NULL
            ) DEFAULT CHARSET=utf8mb4");
        }
        if (!isset($have['flow_map_node'])) {
            $db->exec("CREATE TABLE flow_map_node (
                node_id INT AUTO_INCREMENT PRIMARY KEY,
                map_id INT NOT NULL,
                page_id INT NULL,
                label VARCHAR(60) NOT NULL DEFAULT '',
                x INT NOT NULL DEFAULT 0,
                y INT NOT NULL DEFAULT 0,
                w INT NOT NULL DEFAULT 150,
                h INT NOT NULL DEFAULT 46,
                color TINYINT NOT NULL DEFAULT 0,
                KEY idx_map (map_id)
            ) DEFAULT CHARSET=utf8mb4");
        }
        if (!isset($have['flow_map_edge'])) {
            $db->exec("CREATE TABLE flow_map_edge (
                edge_id INT AUTO_INCREMENT PRIMARY KEY,
                map_id INT NOT NULL,
                from_node INT NOT NULL,
                to_node INT NOT NULL,
                label VARCHAR(30) NOT NULL DEFAULT '',
                KEY idx_map (map_id)
            ) DEFAULT CHARSET=utf8mb4");
        }
    }
}

if (!function_exists('fmap_is_admin')) {
    function fmap_is_admin(PDO $db, int $uid): bool {
        try {
            $st = $db->prepare("SELECT user_status FROM `user` WHERE id=?");
            $st->execute([$uid]);
            if (in_array((int)$st->fetchColumn(), [9, 90], true)) return true;
            $st = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                                WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
            $st->execute([$uid]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('fmap_visible_page_ids')) {
    /** 回傳 [page_id => true]，與左側選單判定一致 */
    function fmap_visible_page_ids(PDO $db, int $uid): array {
        $perm = ['group' => [], 'page' => []];
        $st = $db->prepare("SELECT scope, module_code FROM user_module_permissions WHERE user_id=?");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['scope'] === 'group') $perm['group'][$r['module_code']] = 1;
            elseif ($r['scope'] === 'page') $perm['page'][(string)$r['module_code']] = 1;
        }
        $pages = $db->query("SELECT page_id, group_id FROM system_module_pages")->fetchAll(PDO::FETCH_ASSOC);
        $mods  = $db->query("SELECT module_code, group_id, page_id FROM system_modules")->fetchAll(PDO::FETCH_ASSOC);
        $groupMod = []; $singleMod = [];
        foreach ($mods as $m) {
            if (!empty($m['page_id'])) $singleMod[(int)$m['page_id']] = $m['module_code'];
            elseif (!empty($m['group_id'])) $groupMod[(int)$m['group_id']] = $m['module_code'];
        }
        $out = [];
        foreach ($pages as $p) {
            $pid = (int)$p['page_id'];
            if (isset($perm['page'][(string)$pid])) { $out[$pid] = true; continue; }
            $gid = (int)$p['group_id'];
            if ($gid && isset($groupMod[$gid]) && isset($perm['group'][$groupMod[$gid]])) { $out[$pid] = true; continue; }
            if (isset($singleMod[$pid])) {
                $mc = $singleMod[$pid];
                if (isset($perm['group'][$mc]) || rf_has_module_role($db, $uid, $mc)) $out[$pid] = true;
            }
        }
        return $out;
    }
}

if (!function_exists('fmap_load')) {
    /** 讀一張地圖；網址只給有權限者。$withAll=true（編輯者）時回傳全部不遮蔽 */
    function fmap_load(PDO $db, int $mapId, int $uid, bool $withAll): ?array {
        $st = $db->prepare("SELECT * FROM flow_map WHERE map_id=?");
        $st->execute([$mapId]);
        $map = $st->fetch(PDO::FETCH_ASSOC);
        if (!$map) return null;
        $st = $db->prepare("SELECT n.node_id, n.page_id, n.label, n.x, n.y, n.w, n.h, n.color,
                                   p.page_name, p.page_url
                            FROM flow_map_node n LEFT JOIN system_module_pages p ON p.page_id=n.page_id
                            WHERE n.map_id=? ORDER BY n.node_id");
        $st->execute([$mapId]);
        $vis = fmap_visible_page_ids($db, $uid);
        $nodes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $isPage = !empty($n['page_id']);
            $ok = !$isPage || isset($vis[(int)$n['page_id']]);
            $name = $isPage ? (string)($n['page_name'] ?? '') : '';
            $n['text'] = $isPage ? ($n['label'] !== '' ? $n['label'] : ($name !== '' ? $name : '（頁面已移除）')) : $n['label'];
            $n['allowed'] = $ok;
            if ($isPage && !$ok && !$withAll) $n['page_url'] = null;   // 沒權限：連網址都不給
            unset($n['page_name']);
            foreach (['node_id','page_id','x','y','w','h','color'] as $k) $n[$k] = $n[$k] === null ? null : (int)$n[$k];
            $nodes[] = $n;
        }
        $st = $db->prepare("SELECT edge_id, from_node, to_node, label FROM flow_map_edge WHERE map_id=?");
        $st->execute([$mapId]);
        $edges = array_map(function ($e) {
            return ['edge_id' => (int)$e['edge_id'], 'from' => (int)$e['from_node'], 'to' => (int)$e['to_node'], 'label' => $e['label']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        return ['map' => ['map_id' => (int)$map['map_id'], 'map_name' => $map['map_name'],
                          'canvas_w' => (int)$map['canvas_w'], 'canvas_h' => (int)$map['canvas_h']],
                'nodes' => $nodes, 'edges' => $edges];
    }
}
