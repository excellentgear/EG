<?php
/**
 * 廠內廠商 ↔ 製程大類／小類對應（2026-11-03）——唯一實作。
 *
 * 用途：廠內加工的製程站，生管常常沒有把「廠商」移轉進去（bom_ing.maker_id_no 空白），結果報工自動開立的
 * 品質異常單帶不出廠商。管理員在「主檔管理 → 廠商（勾選廠內加工廠商）」設定這個廠內廠商負責哪些製程
 * （大類 process_type 或小類 process_no，可多選）；之後：
 *   ①報工自動開立異常單時，該站沒登記廠商 → 依這裡的對應自動帶入廠內廠商；
 *   ②BOM 總覽「快速移轉」的廠商欄，該站沒登記廠商 → 自動預帶這個廠內廠商。
 * 規則：每個製程大類、每個製程小類各只能設定給**一個**廠內廠商（UNIQUE(scope,ref_id)，後端與畫面都擋）；
 * 判定順序：先看小類（製程本身），再看大類（製程所屬大類）。停用或已取消「廠內」的廠商不參與判定。
 */

if (!function_exists('imb_ensure_schema')) {
    function imb_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if ($db->inTransaction()) return;   // DDL 會隱式 commit，不可在交易中執行
        try {
            $has = $db->query("SHOW TABLES LIKE 'maker_internal_process_bind'")->fetchColumn();
            if (!$has) {
                $db->exec("CREATE TABLE maker_internal_process_bind (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    maker_id_no VARCHAR(11) NOT NULL COMMENT '廠內廠商編號(maker_list.maker_id_no)',
                    scope ENUM('type','process') NOT NULL COMMENT 'type=製程大類(process_type_id)，process=製程小類(ProcessNo)',
                    ref_id INT NOT NULL,
                    created_by INT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_scope_ref (scope, ref_id),
                    KEY idx_maker (maker_id_no)
                ) DEFAULT CHARSET=utf8mb4 COMMENT='廠內廠商對應的製程大類/小類（每個大類或小類只能設定給一個廠內廠商）'");
            }
        } catch (Throwable $e) { error_log('imb_ensure_schema: ' . $e->getMessage()); }
    }
}

if (!function_exists('imb_get')) {
    /** 某廠商目前設定的對應：['type'=>[大類id…], 'process'=>[ProcessNo…]] */
    function imb_get(PDO $db, string $makerNo): array
    {
        imb_ensure_schema($db);
        $out = ['type' => [], 'process' => []];
        $st = $db->prepare("SELECT scope, ref_id FROM maker_internal_process_bind WHERE maker_id_no=?");
        $st->execute([$makerNo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['scope']][] = (int)$r['ref_id'];
        return $out;
    }
}

if (!function_exists('imb_options')) {
    /**
     * 畫面用：全部製程大類（含底下的小類）與目前的歸屬。
     * 每一項帶 owner（已被「其他」廠內廠商設定時的 maker_id_no／簡稱，畫面反灰不可選）與 mine（本廠商已選）。
     */
    function imb_options(PDO $db, string $makerNo): array
    {
        imb_ensure_schema($db);
        $owner = ['type' => [], 'process' => []];
        $rs = $db->query("SELECT b.scope, b.ref_id, b.maker_id_no, m.maker_id FROM maker_internal_process_bind b
                          LEFT JOIN maker_list m ON m.maker_id_no = b.maker_id_no")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $r) $owner[$r['scope']][(int)$r['ref_id']] = ['no' => $r['maker_id_no'], 'name' => (string)($r['maker_id'] ?: $r['maker_id_no'])];
        $mk = function (string $scope, int $id) use ($owner, $makerNo) {
            $o = $owner[$scope][$id] ?? null;
            return ['mine' => $o && $o['no'] === $makerNo, 'owner' => ($o && $o['no'] !== $makerNo) ? $o : null];
        };
        $types = $db->query("SELECT process_type_id, process_type FROM process_type WHERE COALESCE(is_active,1)=1 ORDER BY sort_order, process_type_id")->fetchAll(PDO::FETCH_ASSOC);
        $procs = $db->query("SELECT ProcessNo, ProcessName, process_type_id FROM process_no ORDER BY ProcessNo")->fetchAll(PDO::FETCH_ASSOC);
        $byType = [];
        foreach ($procs as $p) $byType[(int)$p['process_type_id']][] = $p;
        $out = [];
        foreach ($types as $t) {
            $tid = (int)$t['process_type_id'];
            $row = ['id' => $tid, 'name' => (string)$t['process_type']] + $mk('type', $tid);
            $row['processes'] = [];
            foreach ($byType[$tid] ?? [] as $p) {
                $row['processes'][] = ['no' => (int)$p['ProcessNo'], 'name' => (string)$p['ProcessName']] + $mk('process', (int)$p['ProcessNo']);
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('imb_save')) {
    /**
     * 儲存某廠商的對應（整組取代）。必須是廠內廠商（internal=1）；與其他廠內廠商重複的項目一律擋下並指名是誰。
     * $typeIds／$procNos 傳空陣列＝清空。
     */
    function imb_save(PDO $db, string $makerNo, array $typeIds, array $procNos, ?int $uid): void
    {
        imb_ensure_schema($db);
        $makerNo = trim($makerNo);
        $st = $db->prepare("SELECT maker_id, internal FROM maker_list WHERE maker_id_no=?");
        $st->execute([$makerNo]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new Exception('找不到這個廠商（請先儲存廠商資料）');
        $typeIds = array_values(array_unique(array_filter(array_map('intval', $typeIds))));
        $procNos = array_values(array_unique(array_filter(array_map('intval', $procNos))));
        if (($typeIds || $procNos) && (int)$m['internal'] !== 1) throw new Exception('只有「廠內加工廠商」可以設定對應的製程');

        // 存在性
        if ($typeIds) {
            $in = implode(',', $typeIds);
            if ((int)$db->query("SELECT COUNT(*) FROM process_type WHERE process_type_id IN ($in)")->fetchColumn() !== count($typeIds)) throw new Exception('有製程大類不存在');
        }
        if ($procNos) {
            $in = implode(',', $procNos);
            if ((int)$db->query("SELECT COUNT(*) FROM process_no WHERE ProcessNo IN ($in)")->fetchColumn() !== count($procNos)) throw new Exception('有製程小類不存在');
        }
        // 衝突：已被其他廠內廠商設定
        $chk = $db->prepare("SELECT b.maker_id_no, COALESCE(m.maker_id, b.maker_id_no) AS nm FROM maker_internal_process_bind b
                             LEFT JOIN maker_list m ON m.maker_id_no=b.maker_id_no WHERE b.scope=? AND b.ref_id=? AND b.maker_id_no<>?");
        foreach ($typeIds as $id) {
            $chk->execute(['type', $id, $makerNo]);
            if ($o = $chk->fetch(PDO::FETCH_ASSOC)) {
                $n = $db->prepare("SELECT process_type FROM process_type WHERE process_type_id=?"); $n->execute([$id]);
                throw new Exception('製程大類「' . $n->fetchColumn() . '」已由廠內廠商「' . $o['nm'] . '」設定，每個大類只能設定一個廠內廠商');
            }
        }
        foreach ($procNos as $no) {
            $chk->execute(['process', $no, $makerNo]);
            if ($o = $chk->fetch(PDO::FETCH_ASSOC)) {
                $n = $db->prepare("SELECT ProcessName FROM process_no WHERE ProcessNo=?"); $n->execute([$no]);
                throw new Exception('製程「' . $n->fetchColumn() . '」已由廠內廠商「' . $o['nm'] . '」設定，每個小類只能設定一個廠內廠商');
            }
        }
        $own = $db->inTransaction();
        if (!$own) $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM maker_internal_process_bind WHERE maker_id_no=?")->execute([$makerNo]);
            $ins = $db->prepare("INSERT INTO maker_internal_process_bind (maker_id_no, scope, ref_id, created_by) VALUES (?,?,?,?)");
            foreach ($typeIds as $id) $ins->execute([$makerNo, 'type', $id, $uid]);
            foreach ($procNos as $no) $ins->execute([$makerNo, 'process', $no, $uid]);
            if (!$own) $db->commit();
        } catch (Throwable $e) {
            if (!$own && $db->inTransaction()) $db->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') throw new Exception('這個製程剛剛被其他廠內廠商設定了，請重新整理後再試');
            throw $e;
        }
    }
}

if (!function_exists('imb_resolve')) {
    /**
     * 某製程（ProcessNo）對應的廠內廠商。先看小類（製程本身）再看大類；廠商須啟用中且仍是廠內廠商。
     * @return array|null ['maker_id_no','maker_id','via'=>'process'|'type']
     */
    function imb_resolve(PDO $db, int $processNo): ?array
    {
        if ($processNo <= 0) return null;
        imb_ensure_schema($db);
        $sql = "SELECT b.maker_id_no, m.maker_id FROM maker_internal_process_bind b
                JOIN maker_list m ON m.maker_id_no=b.maker_id_no AND m.internal=1 AND (m.status IS NULL OR m.status<>'X')
                WHERE b.scope=? AND b.ref_id=? LIMIT 1";
        $st = $db->prepare($sql);
        $st->execute(['process', $processNo]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return ['maker_id_no' => $r['maker_id_no'], 'maker_id' => (string)$r['maker_id'], 'via' => 'process'];
        $t = $db->prepare("SELECT process_type_id FROM process_no WHERE ProcessNo=?");
        $t->execute([$processNo]);
        $tid = (int)$t->fetchColumn();
        if ($tid > 0) {
            $st->execute(['type', $tid]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) return ['maker_id_no' => $r['maker_id_no'], 'maker_id' => (string)$r['maker_id'], 'via' => 'type'];
        }
        return null;
    }
}
