<?php
/**
 * AS 文件「填寫紀錄」彙整 —— 唯一實作（2026-09-10 使用者交辦）
 *
 * 解決的問題：AS 文件管理的「填寫紀錄」原本只看得到兩種東西——①人工上傳的紙本掃描檔
 * （as_form_record）②寫死的兩個電子化模組（CAR／品質異常，靠 as_document.linked_module）。
 * 但公司實際上已經有兩套「線上把這張表單填完並簽核」的模組：
 *   - 表單簽核設計器 views/ADM/form_signer.php（fsd_case）
 *   - 審核表單         views/ADM/review_form.php（rf_instance）
 * 兩者都已經綁定了 AS 文件編號，卻完全不會出現在填寫紀錄裡，等於線上填的表單在 AS9100
 * 的品質紀錄清單上查不到。本庫把四種來源彙整成同一份清單。
 *
 * 使用者拍板的口徑（2026-09-10）：
 *   ①只收「已完成」的（fsd_case.status='approved'／rf_instance.status='approved'）——
 *     還在跑的草稿與進行中不是品質紀錄，不進這份清單。
 *   ②電子化紀錄與紙本上傳**合併成同一份清單**，一律依日期新→舊。
 *   ③預覽（看簽章後的樣貌）依 **AS 文件檢閱權限**，與既有紙本紀錄的預覽一致。
 *
 * 「這份文件有哪些線上表單」怎麼認：
 *   - 表單簽核一般案件：樣板綁定（system_parameters AS_DOC_BIND / fsd_tpl_{樣板id}）→ 該樣板的所有案件
 *   - 表單簽核補案件  ：案件自己挑的 fsd_case.as_doc_id
 *   - 審核表單        ：模板綁定（review_form_tpl_{模板id}）→ 該模板的所有表單
 *   反查一律走 eg_asdoc_bound_ids()（asdoc_lib.php），不要自己 parse system_parameters。
 *
 * 注意：**綁定成不成立與「列印頁上要不要印那個編號」無關**——2026-09-10 新增的
 * fsd_template.as_doc_hide_print／fsd_case.as_doc_hide_print 只影響列印右下角印不印，
 * 這份清單照樣收得到（那正是使用者要的：紙本上本來就印好編號了，系統不必再印一次，
 * 但歸檔連動仍要成立）。
 */
if (!function_exists('eg_asdoc_fill_records')) {

require_once __DIR__ . '/asdoc_lib.php';

/** 資料量小（單一文件的紀錄數以十為單位），一次取回再於 PHP 端合併排序，不硬拼 UNION——
 *  as_form_record 與 user 表的字元集不同（user 有 latin1 欄位），硬 UNION 容易踩 1267 混合定序錯誤。 */
function eg_asdoc_fill_rows(PDO $db, int $docId): array {
    $rows = [];
    if ($docId <= 0) return $rows;

    // ── ①紙本／檔案上傳（as_form_record） ──
    try {
        $st = $db->prepare("SELECT r.id, r.title, r.record_date, r.note,
                                   COALESCE(u.user_cname, r.uploaded_by) AS person
                            FROM as_form_record r
                            LEFT JOIN `user` u ON u.user_uname = r.uploaded_by
                            WHERE r.form_doc_id=? AND r.is_deleted=0");
        $st->execute([$docId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['src'=>'paper', 'src_name'=>'紙本／檔案', 'id'=>(int)$r['id'],
                       'title'=>(string)$r['title'], 'rec_date'=>$r['record_date'],
                       'person'=>(string)$r['person'], 'note'=>(string)$r['note'],
                       'status'=>'', 'can_preview'=>true, 'open_url'=>''];
        }
    } catch (Throwable $e) { /* 表不存在時當作沒有紙本紀錄 */ }

    // ── ②表單簽核設計器（一般案件＝樣板綁定；補案件＝案件自己綁的） ──
    try {
        $tplIds = eg_asdoc_bound_ids($db, 'fsd_tpl_', $docId);
        $where = ["(c.case_kind='backfill' AND c.as_doc_id=" . $docId . ")"];
        if ($tplIds) $where[] = "(c.case_kind='normal' AND c.template_id IN (" . implode(',', $tplIds) . "))";
        $st = $db->prepare("SELECT c.id, c.title, c.business_date, c.case_kind, c.export_pdf_name,
                                   c.filler_name, c.applicant_name
                            FROM fsd_case c
                            WHERE c.status='approved' AND (" . implode(' OR ', $where) . ")");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['src'=>'fsd', 'src_name'=>($r['case_kind']==='backfill' ? '表單簽核（補登）' : '表單簽核'),
                       'id'=>(int)$r['id'], 'title'=>(string)$r['title'], 'rec_date'=>$r['business_date'],
                       'person'=>(string)($r['filler_name'] ?: $r['applicant_name']),
                       'note'=>'', 'status'=>'已完成',
                       'can_preview'=>trim((string)$r['export_pdf_name']) !== '',
                       'open_url'=>'../ADM/form_signer.php?case_id=' . (int)$r['id']];
        }
    } catch (Throwable $e) { /* 模組表不存在時略過此來源 */ }

    // ── ③審核表單（模板綁定） ──
    try {
        $rvfIds = eg_asdoc_bound_ids($db, 'review_form_tpl_', $docId);
        if ($rvfIds) {
            $st = $db->prepare("SELECT i.id, i.title, i.business_date, i.created_by_name, t.name AS tpl_name
                                FROM rf_instance i
                                LEFT JOIN rf_template t ON t.id = i.template_id
                                WHERE i.status='approved' AND i.template_id IN (" . implode(',', $rvfIds) . ")");
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['src'=>'rvf', 'src_name'=>'審核表單', 'id'=>(int)$r['id'],
                           'title'=>(string)($r['title'] ?: $r['tpl_name']), 'rec_date'=>$r['business_date'],
                           'person'=>(string)$r['created_by_name'], 'note'=>'', 'status'=>'已完成',
                           'can_preview'=>true,
                           'open_url'=>'../ADM/review_form.php?inst_id=' . (int)$r['id']];
            }
        }
    } catch (Throwable $e) { /* 模組表不存在時略過此來源 */ }

    // ── ④既有的 linked_module 電子化模組（CAR／品質異常；維持原本行為，只是改成同一份清單） ──
    try {
        $st = $db->prepare("SELECT linked_module FROM as_document WHERE id=?");
        $st->execute([$docId]);
        $mod = (string)($st->fetchColumn() ?: '');
        if ($mod === 'car') {
            foreach ($db->query("SELECT id, car_no, fill_date, source_desc FROM car_order")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = ['src'=>'car', 'src_name'=>'異常矯正處理單(CAR)', 'id'=>(int)$r['id'],
                           'title'=>(string)$r['car_no'], 'rec_date'=>$r['fill_date'], 'person'=>'',
                           'note'=>(string)$r['source_desc'], 'status'=>'', 'can_preview'=>false,
                           'open_url'=>'../QA/correction_order.php'];
        } elseif ($mod === 'qa_abnormal') {
            foreach ($db->query("SELECT id, abnormal_order_no, occurrence_date, abnormal_phenomenon FROM qa_abnormal_order")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = ['src'=>'qa_abnormal', 'src_name'=>'品質異常處理單', 'id'=>(int)$r['id'],
                           'title'=>(string)$r['abnormal_order_no'], 'rec_date'=>$r['occurrence_date'], 'person'=>'',
                           'note'=>(string)$r['abnormal_phenomenon'], 'status'=>'', 'can_preview'=>false,
                           'open_url'=>'../QA/qa_abnormal_view.php'];
        }
    } catch (Throwable $e) { /* 模組表不存在時略過此來源 */ }

    // 業務日期新→舊；沒有日期的排最後（不是排最前，否則沒填日期的舊資料會一直霸佔第一頁）
    usort($rows, function ($a, $b) {
        $da = trim((string)$a['rec_date']); $db_ = trim((string)$b['rec_date']);
        if (($da === '') !== ($db_ === '')) return $da === '' ? 1 : -1;
        if ($da !== $db_) return strcmp($db_, $da);
        if ($a['src'] !== $b['src']) return strcmp($a['src'], $b['src']);
        return $b['id'] <=> $a['id'];
    });
    return $rows;
}

/** 分頁版（後端分頁，符合 ai-rules/08：總筆數一律以全部符合條件的資料為準） */
function eg_asdoc_fill_records(PDO $db, int $docId, int $page = 1, int $size = 10): array {
    $all = eg_asdoc_fill_rows($db, $docId);
    $size = max(5, min(50, $size));
    $total = count($all);
    $pages = max(1, (int)ceil($total / $size));
    $page = max(1, min($pages, $page));
    return ['total'=>$total, 'page'=>$page, 'page_size'=>$size,
            'rows'=>array_slice($all, ($page - 1) * $size, $size)];
}

/**
 * 守門：這一筆表單簽核案件真的屬於這份 AS 文件嗎？
 * 預覽端點是用「AS 文件檢閱權限」放行的，若不再驗一次歸屬，任何有 AS 檢閱權的人只要把
 * case_id 換成別的數字就能看到任何一件簽核案件的 PDF（鐵律8：不可只擋前端／只擋清單）。
 */
function eg_asdoc_fill_owns_fsd_case(PDO $db, int $docId, int $caseId): bool {
    if ($docId <= 0 || $caseId <= 0) return false;
    try {
        $st = $db->prepare("SELECT case_kind, template_id, as_doc_id, status FROM fsd_case WHERE id=?");
        $st->execute([$caseId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c || ($c['status'] ?? '') !== 'approved') return false;
        if (($c['case_kind'] ?? '') === 'backfill') return (int)$c['as_doc_id'] === $docId;
        return in_array((int)$c['template_id'], eg_asdoc_bound_ids($db, 'fsd_tpl_', $docId), true);
    } catch (Throwable $e) { return false; }
}

/** 守門：這一筆審核表單真的屬於這份 AS 文件嗎？（理由同上） */
function eg_asdoc_fill_owns_rvf(PDO $db, int $docId, int $instId): bool {
    if ($docId <= 0 || $instId <= 0) return false;
    try {
        $st = $db->prepare("SELECT template_id, status FROM rf_instance WHERE id=?");
        $st->execute([$instId]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i || ($i['status'] ?? '') !== 'approved') return false;
        return in_array((int)$i['template_id'], eg_asdoc_bound_ids($db, 'review_form_tpl_', $docId), true);
    } catch (Throwable $e) { return false; }
}

/** 反查：這一筆審核表單被哪些 AS 文件收進填寫紀錄（給 ReviewForm_API 判斷可否放行唯讀檢視）。 */
function eg_asdoc_rvf_doc_ids(PDO $db, int $templateId): array {
    if ($templateId <= 0) return [];
    try {
        $id = eg_asdoc_id($db, 'review_form_tpl_' . $templateId);
        return $id ? [$id] : [];
    } catch (Throwable $e) { return []; }
}

}
