<?php
/**
 * KPI 新方案（草案） — views/news/KPI_new.php
 *
 * 依「附件C 品質管理系統流程圖」的八大區塊（COP01~04／SP01·02／MP01·02）重新規劃的 KPI 方案，
 * 給使用者先看過、決定要不要修改，拍板後才搬進正式的 KPI 表（2-GM-04-01）。
 *
 * 與正式 KPI 頁（KPI.php）的關係：
 *   ① 這一頁**不寫入任何資料**——沒有填值、沒有覆寫、沒有快照、沒有附件，純唯讀。
 *   ② 既有指標的數字一律讀正式表的月快照，不另算一份（否則兩頁會對不起來）。
 *   ③ 新指標的數字是「即時試算」，用的是 2026 年的真實資料，讓使用者看得到實際落點。
 *   ④ 方案定義與試算邏輯全部在 src/common/kpi_scheme_lib.php（唯一登記表）。
 *
 * 權限沿用 kpi 模組既有角色（看得到正式 KPI 的人就看得到這一頁），不另開角色。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/news/KPI_new.php?in=999";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/kpi_as_lib.php';
include_once '../../src/common/kpi_scheme_lib.php';

$db = (new DBConnection())->getPDO();
kpi_as_ensure_schema($db);
$kpiUser  = kpi_as_current_user($db);
$kpiPerms = kpi_as_perms($db, $kpiUser);
$roleLabel = $kpiPerms['isAdmin'] ? '管理者'
           : ($kpiPerms['canAdmin'] ? 'KPI管理員'
           : ($kpiPerms['canFill'] ? 'KPI填報'
           : ($kpiPerms['canView'] ? 'KPI檢閱（唯讀）' : '無權限')));

/* 公司全名（ai-rules/16：一律動態取，禁寫死） */
$companyName = '';
try {
    $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
    if ($r) $companyName = trim((string)($r['customer_full'] ?: $r['customer']));
} catch (Throwable $e) {}

/* 部門名稱（即時查，不寫死＝鐵律4） */
$deptName = [];
foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $deptName[(int)$r['id']] = (string)$r['name'];
}

$YEAR   = kpi_scheme_sample_year();
$BLOCKS = kpi_scheme_blocks();
$ITEMS  = kpi_scheme_visible_items();
$HIDDEN = kpi_scheme_hidden_items();
$SUM    = kpi_scheme_summary();
$BSTAT  = kpi_scheme_block_stat();

/* ---- 逐項試算（唯讀；只有有檢閱權才跑，省掉無權限者的查詢成本） ---- */
$VALUES = [];
$calcMs = 0;
if ($kpiPerms['canView']) {
    $t0 = microtime(true);
    foreach ($ITEMS as $it) $VALUES[$it['code']] = kpi_scheme_preview($db, $it, $YEAR);
    $calcMs = (int)round((microtime(true) - $t0) * 1000);
}

/** 顯示值格式化 */
function kpsFmt($v, string $type): string {
    if ($v === null) return '';
    $v = (float)$v;
    if ($type === 'percent') return (round($v * 10) / 10) . '%';
    if ($type === 'count')   return (string)(round($v * 10) / 10);
    return (string)(round($v * 10) / 10);
}
/** 未達標？（與 KPI 模組同一套判定語意） */
function kpsBelow($v, array $it): bool {
    if ($v === null || $it['target'] === null) return false;
    $t = (float)$it['target']; $v = (float)$v;
    if ($it['dir'] === 'lte') return $v > $t;
    if ($it['dir'] === 'yes') return $v < 1;
    return $v < $t;
}
function kpsFreqName(string $f): string {
    return ['monthly'=>'每月','quarterly'=>'每季','halfyear'=>'半年','yearly'=>'每年'][$f] ?? $f;
}
function kpsTargetText(array $it): string {
    if ($it['target'] === null) return '觀察期（未訂）';
    $op = $it['dir'] === 'lte' ? '≤' : ($it['dir'] === 'yes' ? '＝' : '≥');
    $t  = rtrim(rtrim(number_format((float)$it['target'], 2, '.', ''), '0'), '.');
    return $op . ' ' . $t . $it['unit'];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPI 新方案（草案）</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        /* ===== 暖色系（ai-rules/10）===== */
        .page-help-btn { margin-left:auto; height:30px; padding:0 12px; border:1px solid #D8BE93;
            background:#FDF8EF; color:#8A5A2B; border-radius:4px; cursor:pointer; font-size:13px; }
        .page-help-btn:hover { background:#F7E0BD; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .right_col .page-title h2 { margin:6px 0; }
        .ks-wrap { clear:both; }

        .ks-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .ks-toolbar button, .ks-toolbar a.btn { height:30px; font-size:13px; line-height:1; padding:0 10px;
            border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ks-toolbar button:hover, .ks-toolbar a.btn:hover { background:#F7E0BD; }
        .ks-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD;
            border-radius:12px; padding:4px 12px; }

        /* 草案提示條 */
        .ks-draft { border:1.5px solid #F0A24B; background:#FFF7E8; border-radius:8px;
            padding:10px 14px; margin-bottom:10px; font-size:13px; color:#5b3a1e; line-height:1.8; }
        .ks-draft b { color:#C2601C; }

        /* 摘要卡 */
        .ks-cards { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
        .ks-card { flex:1 1 128px; min-width:128px; border:1px solid #E8D5B5; border-radius:8px;
            background:#fff; padding:8px 12px; }
        .ks-card .n { font-size:21px; font-weight:bold; color:#8A5A2B; line-height:1.3; }
        .ks-card .t { font-size:12px; color:#8a6d45; }

        /* 主表 */
        .ks-tblwrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.ks-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        table.ks-tbl th, table.ks-tbl td { border:1px solid #EADFC8; padding:4px 6px; text-align:center;
            white-space:nowrap; }
        table.ks-tbl thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; position:sticky; top:0; z-index:5;
            box-shadow:inset 0 -2px 0 #D8BE93; }
        table.ks-tbl td.ks-name { text-align:left; min-width:190px; white-space:normal; }
        table.ks-tbl td.ks-left { text-align:left; }
        table.ks-tbl tbody tr:hover { background:#FBF0DD; }
        tr.ks-blk td { background:#F0E2C8; color:#6b4a20; font-weight:bold; text-align:left; font-size:13px; }
        tr.ks-blk .bk-code { display:inline-block; background:#8A5A2B; color:#fff; border-radius:4px;
            padding:1px 8px; margin-right:8px; font-size:12px; line-height:18px; }
        tr.ks-blk .bk-proc { font-weight:normal; color:#8a6d45; font-size:11.5px; display:block; margin-top:2px; }
        .ks-below { color:#DD5138; font-weight:bold; }
        .ks-na { color:#c4b7a3; }
        /* 值來源記號：覆寫/手動的格子要看得出來（稽核會問「這數字怎麼來的」） */
        .ks-mk { font-size:9px; vertical-align:super; color:#C2601C; }
        .ks-mk.man { color:#8a6d45; }
        .ks-mk.cny { color:#DD5138; font-weight:bold; margin-left:1px; }

        /* 標籤（line-height 一定要自己指定，否則會繼承 custom.css 的 td span{line-height:28px} 把列撐高） */
        .ks-tag { display:inline-block; font-size:10px; line-height:16px; height:16px; padding:0 6px;
            border-radius:8px; margin-left:4px; vertical-align:1px; white-space:nowrap; }
        .ks-tag.new    { background:#F0A24B; color:#fff; }
        .ks-tag.keep   { background:#F1ECE3; color:#8a6d45; }
        .ks-tag.retune { background:#F7E0BD; color:#8A5A2B; }
        .ks-tag.move   { background:#EFE3C8; color:#6b4a20; }
        .ks-tag.watch  { background:#FBE6C8; color:#A6630E; }
        .ks-tag.warn   { background:#DD5138; color:#fff; }
        .ks-tag.empty  { background:#F1ECE3; color:#a08356; border:1px dashed #D8BE93; line-height:14px; }
        .ks-src { display:inline-block; font-size:10px; line-height:16px; height:16px; padding:0 6px;
            border-radius:8px; white-space:nowrap; }
        .ks-src.auto   { background:#EAF0E2; color:#4d6b33; }
        .ks-src.semi   { background:#F7E0BD; color:#8A5A2B; }
        .ks-src.manual { background:#F1ECE3; color:#8a6d45; }
        .ks-i { cursor:pointer; color:#b5762a; margin-left:4px; }

        /* 下方三區 */
        .ks-sec { border:1px solid #E8D5B5; border-radius:8px; background:#fff; margin-top:12px; }
        .ks-sec > h4 { margin:0; padding:8px 12px; background:#F7E0BD; color:#5b3a1e; font-size:14px;
            font-weight:bold; border-radius:8px 8px 0 0; }
        .ks-sec > div { padding:10px 14px; font-size:13px; color:#5b3a1e; line-height:1.85; }
        .ks-sec ul { padding-left:20px; margin:4px 0; }
        .ks-sec .ks-why { color:#7a6046; }

        /* modal */
        .ks-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .ks-modal { background:#fff; border-radius:8px; max-width:720px; margin:60px auto; padding:0;
            box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:82vh; display:flex; flex-direction:column; }
        .ks-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px;
            border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .ks-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .ks-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; line-height:1.85; }
        .ks-modal .m-body h5 { font-size:13px; color:#8A5A2B; margin:10px 0 3px; font-weight:bold; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:12px 0 4px; border-bottom:1px solid #EADFC8; padding-bottom:3px; }
        .help-doc ul { padding-left:20px; margin:4px 0; }
        .help-doc b { color:#8A5A2B; }
        .ks-noperm { border:1px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px;
            color:#5b3a1e; text-align:center; }

        /* 列印：A3 橫式（ai-rules/16）。這是內部草案不是正式 AS 表單，故只印公司全名、不印 AS 編號。 */
        @media print {
            @page { size: A3 landscape; margin: 14mm 14mm 16mm 14mm; }
            @page { @bottom-left { content: "第 " counter(page) " 頁／共 " counter(pages) " 頁";
                                   font-size: 9pt; color:#555; } }
            .page-title, .ks-toolbar, .left_col, .nav_menu, footer, .ks-i, .ks-mask { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; min-height:0 !important; height:auto !important; }
            .container.body, .main_container { min-height:0 !important; height:auto !important; }
            body { background:#fff !important; }
            .ks-print-head { display:block !important; text-align:center; margin-bottom:8px; }
            .ks-print-head .c { font-size:16pt; font-weight:bold; color:#000; }
            .ks-print-head .t { font-size:13pt; color:#000; margin-top:2px; }
            .ks-print-head .s { font-size:9pt; color:#444; margin-top:2px; }
            .ks-tblwrap { overflow:visible !important; border:0; }
            table.ks-tbl { font-size:8.5pt; }
            table.ks-tbl thead { display:table-header-group; }
            table.ks-tbl tr { page-break-inside:avoid; }
            .ks-sec { page-break-inside:avoid; }
        }
        .ks-print-head { display:none; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">KPI 新方案（草案）
                <small style="color:#8a6d45;">依品質管理系統流程圖八大區塊重新規劃　<?= htmlspecialchars(kpi_scheme_version()) ?></small></h2>
            <button type="button" class="page-help-btn" id="btnPageHelp" title="這一頁怎麼用">
                <i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$kpiPerms['canView']): ?>
        <div class="ks-wrap">
            <div class="ks-noperm">
                <h4><i class="fa fa-lock"></i> 無 KPI 檢閱權限</h4>
                <p>這一頁沿用 KPI 模組的角色，請洽管理者於「使用者權限設定」指派 KPI 角色。</p>
            </div>
        </div>
<?php else: ?>
        <div class="ks-wrap">

        <div class="ks-print-head">
            <div class="c"><?= htmlspecialchars($companyName ?: '（尚未設定本公司全名）') ?></div>
            <div class="t">KPI 新方案（草案）　<?= htmlspecialchars(kpi_scheme_version()) ?></div>
            <div class="s">數字為 <?= $YEAR ?> 年真實資料試算，僅供討論，非正式 KPI 紀錄</div>
        </div>

        <div class="ks-toolbar">
            <span style="font-size:13px;color:#5b3a1e;">試算年度　<b><?= $YEAR ?></b></span>
            <button onclick="location.reload()"><i class="fa fa-refresh"></i> 重新試算</button>
            <button onclick="openCnyMask()"><i class="fa fa-calendar-check-o"></i> 春節目標調整設定</button>
            <button onclick="doPrint()"><i class="fa fa-print"></i> 列印（A3 橫式）</button>
            <a class="btn" href="KPI.php" style="line-height:28px;"><i class="fa fa-table"></i> 回正式 KPI 表</a>
            <span class="ks-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b></span>
        </div>

        <div class="ks-draft">
            <b><i class="fa fa-exclamation-triangle"></i> 這是草案，不影響正式 KPI。</b>
            正式 KPI 表（2-GM-04-01）維持原本 22 項，2026 年的資料一格都沒有動；
            這一頁唯一會寫入的是「春節目標調整」的管理員額外調整率（下方 <i class="fa fa-calendar-check-o"></i> 按鈕），
            那是這個草案功能自己的設定，不是正式 KPI 資料。<br>
            表格裡的數字是<b>用 <?= $YEAR ?> 年的真實資料即時試算</b>出來的（本次耗時 <?= $calcMs ?> ms），目的是讓你先看到「這個指標實際上會長成什麼樣子」再決定要不要採用。
            既有指標直接讀正式表的月快照（所以跟正式頁一定一致）；新指標則是當場算。<br>
            格子右上角的記號：<span class="ks-mk">✱</span> ＝管理者覆寫的值、<span class="ks-mk man">✎</span> ＝人工填寫的值、
            <span class="ks-mk cny">春</span> ＝春節自動調整過（滑鼠移過去看原始值與調整後的差異），沒有記號＝系統自動算的。
            <b>紅字</b>＝未達該項目標。
        </div>

        <div class="ks-cards">
            <div class="ks-card"><div class="n"><?= $SUM['total'] ?></div><div class="t">指標總數</div></div>
            <div class="ks-card"><div class="n"><?= $SUM['auto'] ?></div><div class="t">全自動計算</div></div>
            <div class="ks-card"><div class="n"><?= $SUM['semi'] ?></div><div class="t">半自動</div></div>
            <div class="ks-card"><div class="n"><?= $SUM['manual'] ?></div><div class="t">人工填寫</div></div>
            <div class="ks-card"><div class="n"><?= $SUM['new'] ?></div><div class="t">新增</div></div>
            <div class="ks-card"><div class="n"><?= $SUM['retune'] + $SUM['move'] ?></div><div class="t">改口徑／改部門</div></div>
            <div class="ks-card"><div class="n"><?= count($HIDDEN) ?></div><div class="t">暫時隱藏</div></div>
            <div class="ks-card"><div class="n"><?= count(kpi_scheme_dropped()) ?></div><div class="t">建議停用</div></div>
        </div>

        <div class="ks-tblwrap">
        <table class="ks-tbl">
            <thead>
                <tr>
                    <th style="min-width:26px;">#</th>
                    <th style="min-width:190px;">指標名稱</th>
                    <th>負責部門</th>
                    <th>頻率</th>
                    <th>目標</th>
                    <th>資料來源</th>
                    <?php for ($m = 1; $m <= 12; $m++): ?><th><?= $m ?>月</th><?php endfor; ?>
                    <th>平均／合計</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $seq = 0;
            foreach ($BLOCKS as $bcode => $b):
                $bItems = array_values(array_filter($ITEMS, fn($x) => $x['block'] === $bcode));
                if (!$bItems) continue;
                $st = $BSTAT[$bcode];
            ?>
                <tr class="ks-blk"><td colspan="19">
                    <span class="bk-code"><?= htmlspecialchars($bcode) ?></span><?= htmlspecialchars($b['name']) ?>
                    <span style="font-weight:normal;color:#8a6d45;font-size:12px;">
                        （<?= $st['n'] ?> 項，其中自動 <?= $st['auto'] ?> 項、新增 <?= $st['new'] ?> 項）</span>
                    <span class="bk-proc">涵蓋程序：<?= htmlspecialchars($b['proc']) ?></span>
                </td></tr>
                <?php foreach ($bItems as $it):
                    $seq++;
                    $vals = $VALUES[$it['code']] ?? null;
                    [$tagTxt, ] = kpi_scheme_status_label($it['status']);
                    // 平均（比率型）或合計（件數型）
                    $nums = [];
                    if ($vals) foreach ($vals as $c) { if ($c !== null && $c['v'] !== null) $nums[] = (float)$c['v']; }
                    $agg = null; $aggLbl = '';
                    if ($nums) {
                        if ($it['vtype'] === 'count') { $agg = array_sum($nums); $aggLbl = '合計'; }
                        else { $agg = array_sum($nums) / count($nums); $aggLbl = '平均'; }
                    }
                ?>
                <tr>
                    <td><?= $seq ?></td>
                    <td class="ks-name">
                        <?= htmlspecialchars($it['name']) ?>
                        <span class="ks-tag <?= htmlspecialchars($it['status']) ?>"><?= htmlspecialchars($tagTxt) ?></span>
                        <i class="fa fa-info-circle ks-i" onclick="showInfo('<?= htmlspecialchars($it['code']) ?>')"
                           title="計算方式與備註"></i>
                    </td>
                    <td><?= htmlspecialchars($deptName[$it['dept']] ?? '（未設定）') ?></td>
                    <td><?= kpsFreqName($it['freq']) ?></td>
                    <td><?= htmlspecialchars(kpsTargetText($it)) ?></td>
                    <td><span class="ks-src <?= htmlspecialchars($it['src']) ?>"><?= htmlspecialchars(kpi_scheme_src_label($it['src'])) ?></span></td>
                    <?php for ($m = 1; $m <= 12; $m++):
                        $c = ($vals && array_key_exists($m, $vals)) ? $vals[$m] : null;
                        if ($c === null || $c['v'] === null) { echo '<td class="ks-na">–</td>'; continue; }
                        $v   = (float)$c['v'];
                        $bad = kpsBelow($v, $it);
                        $src = (string)($c['src'] ?? '');
                        $mk  = $src === 'override' ? '<span class="ks-mk">✱</span>'
                             : ($src === 'manual'  ? '<span class="ks-mk man">✎</span>' : '');
                        // 春節調整過的格子另外加一個記號，並把「原始值→調整後值」寫進提示裡
                        $cnyOrig = $c['cny_orig_v'] ?? null;
                        $tipParts = [];
                        if ($cnyOrig !== null) {
                            $mk .= '<span class="ks-mk cny" title="春節調整">春</span>';
                            $tipParts[] = sprintf('春節調整：原始 %s%% → 調整後 %s%%（比例 %s）',
                                rtrim(rtrim(number_format((float)$cnyOrig, 1), '0'), '.'),
                                rtrim(rtrim(number_format($v, 1), '0'), '.'),
                                rtrim(rtrim(number_format((float)($c['cny_ratio'] ?? 1), 4), '0'), '.'));
                        }
                        if (isset($c['den']) && $c['den'] !== null && $c['den'] !== '')
                            $tipParts[] = '分子 ' . (string)$c['num'] . ' ／ 分母 ' . (string)$c['den'];
                        elseif (isset($c['num']) && $c['num'] !== null)
                            $tipParts[] = '件數 ' . (string)$c['num'];
                        $tip = $tipParts ? ' title="' . htmlspecialchars(implode('；', $tipParts)) . '"' : '';
                        echo '<td' . $tip . '><span class="' . ($bad ? 'ks-below' : '') . '">'
                           . htmlspecialchars(kpsFmt($v, $it['vtype'])) . '</span>' . $mk . '</td>';
                    endfor; ?>
                    <td><?= $agg === null ? '<span class="ks-na">–</span>'
                            : '<b>' . htmlspecialchars(kpsFmt($agg, $it['vtype'])) . '</b>'
                              . '<span style="font-size:10px;color:#8a6d45;"> ' . $aggLbl . '</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($HIDDEN): ?>
        <div class="ks-sec">
            <h4><i class="fa fa-eye-slash"></i> 暫時隱藏（<?= count($HIDDEN) ?> 項，定義保留、隨時可以打開）</h4>
            <div><ul>
            <?php foreach ($HIDDEN as $it): ?>
                <li><b><?= htmlspecialchars($it['block']) ?>　<?= htmlspecialchars($it['name']) ?></b>　—
                    <span class="ks-why"><?= htmlspecialchars($it['note']) ?></span>
                    <i class="fa fa-info-circle ks-i" onclick="showInfo('<?= htmlspecialchars($it['code']) ?>')"
                       title="計算方式與備註"></i></li>
            <?php endforeach; ?>
            </ul></div>
        </div>
        <?php endif; ?>

        <div class="ks-sec">
            <h4><i class="fa fa-ban"></i> 建議停用（依你回饋的營運實況，不再列入新方案）</h4>
            <div><ul>
            <?php foreach (kpi_scheme_dropped() as $d): ?>
                <li><b><?= (int)$d['no'] > 0 ? '原 #' . (int)$d['no'] . '　' : '' ?><?= htmlspecialchars($d['name']) ?></b>　—
                    <span class="ks-why"><?= htmlspecialchars($d['why']) ?></span></li>
            <?php endforeach; ?>
            </ul></div>
        </div>

        <div class="ks-sec">
            <h4><i class="fa fa-question-circle"></i> 待你決定</h4>
            <div><ul>
            <?php foreach (kpi_scheme_pending() as $d): ?>
                <li><b><?= (int)$d['no'] > 0 ? '原 #' . (int)$d['no'] . '　' : '' ?><?= htmlspecialchars($d['name']) ?></b>　—
                    <span class="ks-why"><?= htmlspecialchars($d['why']) ?></span></li>
            <?php endforeach; ?>
            </ul></div>
        </div>

        <div class="ks-sec">
            <h4><i class="fa fa-wrench"></i> 上線前的先決條件（不先處理，指標一上線就是空白或假數字）</h4>
            <div><ul>
            <?php foreach (kpi_scheme_prereq() as $p): ?>
                <li><b><?= htmlspecialchars($p['t']) ?></b>　—　<span class="ks-why"><?= htmlspecialchars($p['d']) ?></span></li>
            <?php endforeach; ?>
            </ul></div>
        </div>

        </div><!-- /.ks-wrap -->
<?php endif; ?>
    </div>
</div>
</div>

<!-- 單項說明 -->
<div class="ks-mask" id="infoMask">
    <div class="ks-modal">
        <div class="m-head"><span id="infoTitle">指標說明</span>
            <span class="m-close" onclick="closeMask('infoMask')">&times;</span></div>
        <div class="m-body" id="infoBody"></div>
    </div>
</div>

<!-- 春節目標調整設定 -->
<div class="ks-mask" id="cnyMask">
    <div class="ks-modal" style="max-width:860px;">
        <div class="m-head"><span>春節目標調整設定　<span id="cnyYearLabel"></span></span>
            <span class="m-close" onclick="closeMask('cnyMask')">&times;</span></div>
        <div class="m-body">
            <div class="ks-why" style="margin-bottom:10px;">
                影響「月份受訂目標達成率」「月銷貨額達成率」兩項。自動比例＝(30−春節損失工作天數)/30，
                損失天數取自行事曆上標題含「春節」的國定假日（只算週一到週五）；春節跨兩個月時兩個月各自計算。
                「額外調整率」是管理員疊加的手動修正（百分點，可正可負），用來微調自動算出來的跟實際出入太大的情況。
            </div>
            <div id="cnyNoEdit" class="vio-warn" style="display:none;">
                你沒有 KPI 管理者權限，以下僅供檢視，無法修改額外調整率。
            </div>
            <div style="overflow-x:auto;">
            <table class="ks-tbl" style="width:100%;">
                <thead><tr>
                    <th>月份</th><th>春節損失天數</th><th>自動比例</th>
                    <th>額外調整率(%)</th><th>最終比例</th><th>備註</th><th></th>
                </tr></thead>
                <tbody id="cnyTbody"></tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- 使用說明（鐵律7） -->
<div class="ks-mask" id="helpUseMask">
    <div class="ks-modal">
        <div class="m-head"><span>使用說明</span>
            <span class="m-close" onclick="closeMask('helpUseMask')">&times;</span></div>
        <div class="m-body help-doc">
            <h4>這一頁是什麼</h4>
            <p>依「附件C 品質管理系統流程圖」的八大區塊（COP01~COP04、SP01·SP02、MP01·MP02）重新規劃的 KPI 方案草案。
               目的是讓你<b>先看到每個指標用真實資料算出來長什麼樣子</b>，再決定要不要採用或修改。</p>

            <h4>它會不會動到正式 KPI</h4>
            <ul>
                <li><b>不會。</b>正式 KPI 表（2-GM-04-01）維持原本 22 項，2026 年的資料一格都沒有動，不能填值、不能覆寫、不會產生快照或附件。</li>
                <li>唯一的例外是「春節目標調整」的管理員額外調整率——那是這個草案功能自己的設定（獨立的小資料表），不是正式 KPI 資料，不影響 2-GM-04-01。</li>
                <li>等你拍板後，才會把確定的指標搬進正式表。</li>
            </ul>

            <h4>表格怎麼看</h4>
            <ul>
                <li>每個區塊一條底色標題列，括號裡寫明該區塊有幾項、其中幾項可自動計算。</li>
                <li><b>既有指標</b>的數字直接讀正式 KPI 表的月快照，所以跟正式頁一定一致。</li>
                <li><b>新指標</b>的數字是當場試算（用 <?= $YEAR ?> 年真實資料），還沒發生的月份一律留白、不給假數字。</li>
                <li>滑鼠移到數字上會顯示<b>分子／分母</b>。</li>
                <li>格子右上角：<span class="ks-mk">✱</span>＝管理者覆寫、<span class="ks-mk man">✎</span>＝人工填寫、
                    <span class="ks-mk cny">春</span>＝春節自動調整過、無記號＝系統自動算。
                    <b>這個記號很重要</b>——稽核時被問「這個數字怎麼來的」，有覆寫記號的就要拿得出佐證。</li>
                <li><b>紅字</b>＝未達該項目標。標「觀察期」的項目刻意不訂目標，所以不會有紅字。</li>
            </ul>

            <h4>狀態標籤</h4>
            <ul>
                <li><span class="ks-tag new">新增</span>本次新提案的指標</li>
                <li><span class="ks-tag keep">沿用</span>既有指標，口徑不變</li>
                <li><span class="ks-tag retune">改口徑</span>既有指標，目標或計算範圍要調整</li>
                <li><span class="ks-tag move">改負責部門</span>指標不變，只換負責單位</li>
                <li><span class="ks-tag watch">觀察期</span>資料量還不夠，先不訂目標</li>
                <li><span class="ks-tag warn">待補資料</span>算得出來，但來源幾乎是空的</li>
                <li><span class="ks-tag empty">無歷史資料</span>欄位結構已備妥，但系統裡還沒有任何一筆走完整個流程的紀錄</li>
            </ul>

            <h4>每一項的計算方式去哪裡看</h4>
            <p>點指標名稱右邊的 <i class="fa fa-info-circle" style="color:#b5762a;"></i>，會列出該項的
               <b>計算方式</b>（分子分母怎麼取）與<b>備註</b>（資料面的提醒、為什麼這樣設計）。</p>

            <h4>「暫時隱藏」是什麼</h4>
            <p>主表格下方若有「暫時隱藏」區塊，代表這幾項<b>定義已經寫好、但先不放進主表格與總數裡</b>——
               通常是目前完全沒有歷史資料可看，放在表格裡只會是一整排空白。
               跟「建議停用」不同：停用是已經決定不用了，隱藏只是先收起來，隨時可以打開。</p>

            <h4>列印</h4>
            <p>固定 A3 橫式。列印對話框請把紙張選成 A3。這是內部討論用的草案、不是正式 AS 表單，
               所以只印公司全名與頁碼，<b>不印 AS 文件編號</b>。</p>

            <h4>權限</h4>
            <p>沿用 KPI 模組既有角色，看得到正式 KPI 表的人就看得到這一頁，不另外指派。</p>
        </div>
    </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script>
$(document).ready(function(){
    var $activeMenu = $('#sidebar-menu .nav.side-menu > li.active');
    if ($activeMenu.length) {
        $activeMenu.removeClass('active').find('ul.child_menu').hide();
        $activeMenu.find('li.current-page').removeClass('current-page');
    }
    $('#sidebar-menu').css('visibility', 'visible');
});

var ITEM_INFO = <?= json_encode(array_map(function ($x) {
        return ['name'=>$x['name'], 'block'=>$x['block'], 'basis'=>$x['basis'], 'note'=>$x['note'],
                'status'=>$x['status'], 'src'=>$x['src']];
    }, array_column(array_merge($ITEMS, $HIDDEN), null, 'code')), JSON_UNESCAPED_UNICODE) ?>;
var STATUS_TXT = <?php
    $statusKeys = ['keep','move','retune','new','watch','warn','empty'];
    $statusMap = [];
    foreach ($statusKeys as $sk) $statusMap[$sk] = kpi_scheme_status_label($sk)[1];
    echo json_encode($statusMap, JSON_UNESCAPED_UNICODE);
?>;

function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function showInfo(code){
    var it = ITEM_INFO[code];
    if (!it) return;
    document.getElementById('infoTitle').textContent = it.block + '　' + it.name;
    var h = '<h5>計算方式</h5><div>' + esc(it.basis) + '</div>';
    if (it.note) h += '<h5>備註與提醒</h5><div>' + esc(it.note) + '</div>';
    h += '<h5>這一項的狀態</h5><div>' + esc(STATUS_TXT[it.status] || it.status) + '</div>';
    document.getElementById('infoBody').innerHTML = h;
    openMask('infoMask');
}

var CNY_API = '../../src/store/KpiSchemeCny_API.php';
var CNY_MONTH_NAME = ['','1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];

function openCnyMask(){
    openMask('cnyMask');
    document.getElementById('cnyYearLabel').textContent = <?= $YEAR ?>;
    document.getElementById('cnyTbody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr>';
    $.getJSON(CNY_API, { action:'list', year: <?= $YEAR ?> }, function(res){
        if (!res || !res.ok) { document.getElementById('cnyTbody').innerHTML =
            '<tr><td colspan="7" style="text-align:center;color:#DD5138;">載入失敗：' + esc((res && res.error) || '') + '</td></tr>'; return; }
        document.getElementById('cnyNoEdit').style.display = res.can_edit ? 'none' : 'block';
        var html = '';
        res.rows.forEach(function(r){
            var editable = res.can_edit;
            html += '<tr data-m="' + r.month + '">'
                 + '<td>' + CNY_MONTH_NAME[r.month] + '</td>'
                 + '<td>' + (r.lost_days > 0 ? r.lost_days + ' 天' : '<span class="ks-na">–</span>') + '</td>'
                 + '<td>' + (parseFloat(r.auto_ratio) < 1 ? (parseFloat(r.auto_ratio)*100).toFixed(1)+'%' : '<span class="ks-na">不調整</span>') + '</td>'
                 + '<td>' + (editable
                       ? '<input type="text" class="cny-extra" value="' + esc(r.extra_pct) + '" style="width:64px;height:24px;border:1px solid #D8BE93;border-radius:3px;padding:0 4px;text-align:right;">'
                       : esc(r.extra_pct)) + '</td>'
                 + '<td><b>' + (parseFloat(r.final_ratio)*100).toFixed(1) + '%</b></td>'
                 + '<td>' + (editable
                       ? '<input type="text" class="cny-note" value="' + esc(r.note || '') + '" placeholder="選填：調整原因" style="width:140px;height:24px;border:1px solid #D8BE93;border-radius:3px;padding:0 4px;">'
                       : esc(r.note || '')) + '</td>'
                 + '<td>' + (editable ? '<button onclick="saveCnyRow(' + r.month + ')" style="height:24px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:3px;cursor:pointer;padding:0 8px;">儲存</button>' : '') + '</td>'
                 + '</tr>';
        });
        document.getElementById('cnyTbody').innerHTML = html;
    }).fail(function(){
        document.getElementById('cnyTbody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#DD5138;">連線失敗</td></tr>';
    });
}

function saveCnyRow(month){
    var $tr = $('#cnyTbody tr[data-m="' + month + '"]');
    var extra = $tr.find('.cny-extra').val();
    var note = $tr.find('.cny-note').val();
    if (extra === '' || isNaN(parseFloat(extra))) { alert('額外調整率請輸入數字（可以是 0）'); return; }
    $.post(CNY_API, { action:'save', year: <?= $YEAR ?>, month: month, extra_pct: extra, note: note }, function(res){
        if (!res || !res.ok) { alert('儲存失敗：' + ((res && res.error) || '')); return; }
        // 直接整頁重載：既重新載入設定面板的最新值，也讓主表格跟著重算
        // （這一格調整前後可能從「有調整」變成「沒調整」，兩邊只有整頁重算才會一致）
        location.reload();
    }, 'json').fail(function(){ alert('儲存失敗：連線異常'); });
}

function doPrint(){
    // ai-rules/23：列印一律留紀錄（一次列印只記一筆）
    try {
        if (window.EGPrintLog && EGPrintLog.record) {
            EGPrintLog.record({ source:'kpi_scheme', doc_kind:'form',
                                doc_name:'KPI 新方案（草案） <?= $YEAR ?>' });
        }
    } catch(e){}
    alert('列印對話框請把紙張選成 A3、方向選橫式。');
    window.print();
}

// 點遮罩空白處關閉
$(document).on('click', '.ks-mask', function(e){ if (e.target === this) this.style.display='none'; });
$(document).on('click', '#btnPageHelp', function(){ openMask('helpUseMask'); });
</script>
</body>
</html>
