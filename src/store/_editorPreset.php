<?php
// 共同編輯者預設名單 API（co_editor_preset；公告 module='notice'、品質異常單 module='qa'）
// list：私人名單(本人建立)優先排序，其後為公開名單
// save：同人同模組同名 → 覆寫更新；is_public=1 公開(所有人可選)、0 私人(僅本人)
// delete：僅能刪除自己建立的名單
header('Content-Type: application/json; charset=utf-8');
include("../../src/common/_config.php"); // session_start + $db

if (!isset($_SESSION['id'])) { echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit(); }
$uid = (int)$_SESSION['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'list') {
        $module = trim($_GET['module'] ?? $_POST['module'] ?? 'notice');
        $st = $db->prepare("SELECT id, name, is_public, owner_id FROM co_editor_preset
                            WHERE module = ? AND (is_public = 1 OR owner_id = ?)
                            ORDER BY (owner_id = ? AND is_public = 0) DESC, is_public ASC, name ASC");
        $st->execute([$module, $uid, $uid]);
        $data = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $data[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'is_public' => (int)$r['is_public'],
                'is_mine' => ((int)$r['owner_id'] === $uid) ? 1 : 0,
            ];
        }
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

    } elseif ($action === 'get') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM co_editor_preset WHERE id = ? AND (is_public = 1 OR owner_id = ?)");
        $st->execute([$id, $uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) { echo json_encode(['ok' => false, 'msg' => '找不到名單或無權限']); exit(); }
        $editors = json_decode((string)$r['editors_json'], true) ?: [];
        echo json_encode(['ok' => true, 'id' => (int)$r['id'], 'name' => $r['name'], 'is_public' => (int)$r['is_public'], 'editors' => $editors], JSON_UNESCAPED_UNICODE);

    } elseif ($action === 'save') {
        $module = trim($_POST['module'] ?? 'notice');
        $name = trim($_POST['name'] ?? '');
        $isPublic = !empty($_POST['is_public']) ? 1 : 0;
        $editors = json_decode((string)($_POST['editors'] ?? '[]'), true);
        if ($name === '') { echo json_encode(['ok' => false, 'msg' => '名單簡稱不可空白']); exit(); }
        if (!is_array($editors) || empty($editors)) { echo json_encode(['ok' => false, 'msg' => '名單內容不可為空']); exit(); }
        // 通知對象名單（module 以 _target 結尾）：存 code/name/mode，允許 all/status/dept/user 且不限人數
        if (substr($module, -7) === '_target') {
            $clean = [];
            foreach ($editors as $e) {
                $code = trim((string)($e['code'] ?? ''));
                if ($code === '') continue;
                $clean[] = ['code' => $code, 'name' => (string)($e['name'] ?? ''), 'mode' => (string)($e['mode'] ?? 'read')];
            }
            if (empty($clean)) { echo json_encode(['ok' => false, 'msg' => '名單內容不可為空']); exit(); }
            $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
            $ck = $db->prepare("SELECT id FROM co_editor_preset WHERE module = ? AND owner_id = ? AND name = ?");
            $ck->execute([$module, $uid, $name]);
            if ($exist = (int)$ck->fetchColumn()) {
                $db->prepare("UPDATE co_editor_preset SET is_public = 0, editors_json = ? WHERE id = ?")->execute([$json, $exist]);
                echo json_encode(['ok' => true, 'id' => $exist]);
            } else {
                $db->prepare("INSERT INTO co_editor_preset (module, owner_id, name, is_public, editors_json) VALUES (?,?,?,0,?)")
                   ->execute([$module, $uid, $name, $json]);
                echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
            }
            exit();
        }
        // 整理：只留 type/id/name，人員最多 5 位
        $clean = []; $userCount = 0;
        foreach ($editors as $e) {
            $type = ($e['type'] ?? '') === 'dept' ? 'dept' : 'user';
            $id = (int)($e['id'] ?? 0);
            if ($id <= 0) continue;
            if ($type === 'user') { if ($userCount >= 5) continue; $userCount++; }
            $clean[] = ['type' => $type, 'id' => $id, 'name' => (string)($e['name'] ?? '')];
        }
        if (empty($clean)) { echo json_encode(['ok' => false, 'msg' => '名單內容不可為空']); exit(); }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        // 同人同模組同名 → 覆寫
        $ck = $db->prepare("SELECT id FROM co_editor_preset WHERE module = ? AND owner_id = ? AND name = ?");
        $ck->execute([$module, $uid, $name]);
        $exist = (int)$ck->fetchColumn();
        if ($exist) {
            $db->prepare("UPDATE co_editor_preset SET is_public = ?, editors_json = ? WHERE id = ?")->execute([$isPublic, $json, $exist]);
            echo json_encode(['ok' => true, 'id' => $exist]);
        } else {
            $db->prepare("INSERT INTO co_editor_preset (module, owner_id, name, is_public, editors_json) VALUES (?,?,?,?,?)")
               ->execute([$module, $uid, $name, $isPublic, $json]);
            echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare("DELETE FROM co_editor_preset WHERE id = ? AND owner_id = ?");
        $st->execute([$id, $uid]);
        if ($st->rowCount() === 0) { echo json_encode(['ok' => false, 'msg' => '找不到名單或僅能刪除自己建立的名單']); exit(); }
        echo json_encode(['ok' => true]);

    } else {
        echo json_encode(['ok' => false, 'msg' => '未知動作']);
    }
} catch (Throwable $e) {
    error_log('[editor_preset] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => '資料庫錯誤']);
}
