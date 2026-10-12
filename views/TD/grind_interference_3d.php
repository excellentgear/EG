<?php
/**
 * grind_interference_3d.php — 砂輪干涉 3D 模擬
 * 建立：2026-10-12
 *
 * 使用者需求：「輸入砂輪尺寸、工件齒底徑、肩部尺寸，自動畫出相關 3D，快速看出是否會干涉」
 * ＝齒輪計算工具（views/Sales/_gear_tool_ui.php）「出尾計算」的 3D 版本，可旋轉、含砂輪角度。
 *
 * 本頁只是「殼」：全部功能都在可插拔的共用元件 views/Sales/_grind3d_tool_ui.php 裡，
 * 之後要把這個工具掛到任何頁面（訂單追蹤、批圖編輯器、齒輪計算工具的出尾分頁…）
 * 只要 include 那一支並放一顆呼叫 openGrind3D() 的按鈕即可，**不要把程式碼複製過去**（鐵律4）。
 *
 * 本頁完全不讀寫任何資料表：幾何與干涉判定全部在瀏覽器端算，沒有後端 API。
 * 權限：ot_grind3d（或 ot_gear_calc），判定唯一實作在 src/common/grind3d_tool_lib.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/grind_interference_3d.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/grind3d_tool_lib.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

/* 權限在這裡算一次，共用元件會沿用（它看到 $can_grind3d 已經有值就不重算） */
$can_grind3d = grind3d_tool_can_use($db, $uid);
$roleLabel   = grind3d_tool_is_admin() ? '系統管理者' : ($can_grind3d ? '可使用' : '無權限');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>砂輪干涉 3D 模擬</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
<style>
#sidebar-menu { visibility: hidden; }
:root{ --ink:#4A3524; --sand:#F7E0BD; --amber:#F0A24B; --amber-d:#C77C1A;
       --coral:#DD5138; --line:#E4D3BC; --muted:#a08a6f; }
body { background:#F6F1EA; }
/* .right_col 的第一個子元素一定要 clear:both：Gentelella 的 .top_nav 高度是 0、浮動溢出，
   第一個子元素若自成 BFC 會被壓成寬度 0，標題整條會消失（CLAUDE.md 鐵律6 已踩兩次）。 */
.right_col .page-title { margin:8px 0 6px; overflow:hidden; clear:both; }
.page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px;
                 flex-wrap:wrap; font-size:22px; }
.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                 border-radius:15px; background:#fff; color:var(--amber-d); }
.page-help-btn:hover { background:var(--amber-d); color:#fff; }
.role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px;
            padding:2px 10px; font-weight:normal; }
.warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px;
              padding:12px 14px; margin-bottom:12px; }
/* 嵌入式容器：高度要給死，3D 畫布靠它撐開（父層沒有高度時 o3dv 會量到 0x0 永遠空白） */
#g3dHost { height:calc(100vh - 190px); min-height:440px; }
.help-doc h4 { font-size:14px; color:var(--ink); margin:14px 0 6px; border-bottom:1px solid var(--line); padding-bottom:4px; }
.help-doc h4:first-child { margin-top:0; }
.help-doc p, .help-doc li { font-size:13px; line-height:1.8; color:#5a4530; }
.help-doc ul { padding-left:22px; margin:4px 0 8px; }
.help-doc code { background:#F3E8D6; color:#8a5a2b; padding:1px 5px; border-radius:3px; }
.g3d-mask { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10500; display:none; }
.g3d-mask.show { display:flex; align-items:flex-start; justify-content:center; padding:40px 12px; }
.g3d-mask .box { background:#fff; border-radius:8px; width:860px; max-width:96vw;
                 max-height:86vh; display:flex; flex-direction:column; }
.g3d-mask .bh { padding:10px 14px; border-bottom:1px solid var(--line); font-weight:700;
                color:var(--ink); display:flex; align-items:center; gap:8px; }
.g3d-mask .bb { padding:14px 18px; overflow:auto; }
@media print { .page-help-btn, .g3d-mask { display:none !important; } }
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">

        <div class="page-title">
            <h3><i class="fa fa-circle-o-notch" style="color:var(--coral);"></i> 砂輪干涉 3D 模擬
                <span class="role-tag">目前身分：<?= htmlspecialchars($roleLabel) ?></span>
                <a href="../Sales/NewOrder_Track.php" class="btn btn-default btn-sm"
                   style="margin-left:auto;" title="齒輪計算工具的 2D 出尾計算在訂單追蹤頁">
                   <i class="fa fa-calculator"></i> 齒輪計算（2D 出尾）</a>
                <button id="btnPageHelp" class="page-help-btn"><i class="fa fa-question-circle"></i> 使用說明</button>
            </h3>
        </div>

<?php if (!$can_grind3d): ?>
        <div class="warm-panel">
            您沒有本頁的使用權限。請洽系統管理員到「訂單追蹤」的角色設定，在「計算工具」群組
            勾選 <code>砂輪干涉3D</code>（功能碼 <code>ot_grind3d</code>）；已經有
            <code>齒輪計算</code>（<code>ot_gear_calc</code>）權限的人自動具備。
        </div>
<?php else: ?>
        <div id="g3dHost"></div>
<?php endif; ?>

    </div>
</div>
</div>

<!-- ── 使用說明（鐵律7）──────────────────────────────────────────────── -->
<div class="g3d-mask" id="helpUseMask">
  <div class="box">
    <div class="bh"><i class="fa fa-question-circle"></i> 使用說明
        <span style="margin-left:auto"></span>
        <button class="btn btn-xs btn-default" onclick="document.getElementById('helpUseMask').classList.remove('show')">關閉</button>
    </div>
    <div class="bb help-doc">
      <h4>這個頁面在回答什麼問題</h4>
      <p>磨齒（或花鍵）時砂輪會磨到靠近肩部的位置，砂輪是圓的，所以越靠近肩部、溝槽就越淺，
         一定會留下一段磨不到全深的長度，這段就是<b>出尾長度</b>；再往肩部靠就會<b>撞到肩部</b>。
         本頁依輸入的尺寸把砂輪與工件畫成可旋轉的 3D，並算出：</p>
      <ul>
        <li>砂輪<b>最靠近肩部又不會撞到</b>的極限位置</li>
        <li>該位置下的<b>出尾長度</b>（含砂輪傾角）</li>
        <li>任意位置下<b>還能再靠近幾 mm</b>／<b>必須往外退幾 mm</b></li>
      </ul>

      <h4>還有哪裡可以開這個工具</h4>
      <p>同一個工具、同一套算法，三個入口的結果一定一致：</p>
      <ul>
        <li><b>齒輪計算工具 →「出尾計算」分頁右側</b>的橘色【砂輪干涉3D】（訂單追蹤頁與批圖編輯器都有）
            ——按下去會<b>自動帶入出尾計算已經填好的</b>刀具外徑／齒根直徑／肩部直徑／退刀量，不必重打。</li>
        <li><b>批圖編輯器底部狀態列</b>，齒輪計算右側的【砂輪干涉3D】。</li>
        <li>本頁（整個畫面看 3D，空間最大）。</li>
      </ul>

      <h4>操作步驟</h4>
      <ul>
        <li>左欄輸入砂輪與工件尺寸，<b>打字就會即時重算與重畫</b>，不必按任何按鈕。</li>
        <li>3D 畫面用滑鼠<b>拖曳旋轉</b>、滾輪縮放；工具列有等角／前／上／右四個標準視角。</li>
        <li>拖「軸向位置」拉桿可以把砂輪往肩部推，壓到肩部的部分會變<b>紅色</b>。</li>
        <li>按「移到不干涉的極限位置」直接跳到剛好不撞到的位置。</li>
      </ul>

      <h4>顏色的意思（重要）</h4>
      <ul>
        <li><b>灰色</b>＝砂輪；<b>砂色</b>＝工件。</li>
        <li><b>紅色</b>＝砂輪壓進<b>肩部</b>的那一塊，也就是會撞到的地方。</li>
        <li>工件預設<b>半透明</b>：干涉時砂輪是埋在肩部裡面的，不透明就看不到紅色區域。
            要看實體外觀再取消勾選，或用工具列的「剖面」切開看。</li>
        <li>砂輪與齒段看起來只是「碰到」而不是切進去，這是對的——工件畫的是
            <b>加工完成後</b>的齒底徑，砂輪在正確位置本來就只與齒底圓相切。</li>
      </ul>

      <h4>為什麼要做 3D 版、跟 2D 的出尾計算差在哪</h4>
      <p>訂單追蹤頁齒輪計算工具的「出尾計算」用的是
         <code>出尾 = √(Rw² − (Rw − H)²)</code>（Rw＝砂輪半徑、H＝(肩部直徑−齒底徑)/2），
         那是<b>砂輪軸與工件軸平行</b>（傾角 0）時軸向剖面上的圓弦長，
         <b>傾角一旦不是 0 這條公式就不再成立</b>——砂輪的迴轉體被斜置之後，在軸向剖面上的
         輪廓不再是圓，最先碰到肩部的那個點也會離開軸向剖面。</p>
      <p>本頁兩個數字並列顯示：傾角 0 時兩者<b>完全相同</b>（已用程式驗證到浮點精度極限），
         傾角不為 0 時的差額就是 2D 公式的誤差。例如 Ø200 砂輪、齒底徑 Ø40、肩部 Ø70 時，
         傾角 0 是 52.68mm，傾角 20° 其實只有 46.10mm（2D 公式<b>高估了 6.58mm</b>）。</p>

      <h4>欄位說明</h4>
      <ul>
        <li><b>砂輪刃寬</b>：外徑處的厚度。<b>側面角</b>是單側角度，0＝兩側平行的圓盤砂輪；
            <b>刃高</b>是側面角往內徑作用多高，再往內就維持本體厚度不再變寬。</li>
        <li><b>砂輪傾角</b>：砂輪軸相對工件軸的傾斜（磨螺旋齒時的擺角）。</li>
        <li><b>齒底徑</b>：加工<b>完成後</b>的齒根圓直徑。<b>肩部直徑</b>＝砂輪不可以碰到的大徑段。</li>
        <li><b>肩部長度／齒段長度</b>：只影響 3D 畫出來的長短，不影響出尾長度。</li>
        <li><b>退刀量</b>：另外加在幾何出尾長度上的安全量，與 2D 出尾計算同一個意思。</li>
      </ul>

      <h4>目前的範圍與尚未做的事</h4>
      <ul>
        <li>工件畫成<b>砂輪＋階段軸</b>（齒底徑圓柱＋肩部台階），<b>沒有畫出實際齒形／齒溝</b>。
            對「砂輪會不會撞到肩部」這個問題齒形不影響答案（撞的是肩部圓柱端面不是齒溝），
            要問「砂輪進不進得到齒溝底」才需要齒形，那是另一件事。</li>
        <li>沒有做列印報告（會連帶需要列印紀錄，見 ai-rules/23），目前請用工具列的「截圖」。</li>
        <li>本頁<b>不寫入任何資料</b>，只是計算與顯示工具。</li>
      </ul>

      <h4>權限</h4>
      <p>功能碼 <code>ot_grind3d</code>（砂輪干涉3D），在<b>訂單追蹤</b>的角色設定
         「計算工具」群組指派；已有 <code>ot_gear_calc</code>（齒輪計算）者自動具備。
         刻意與齒輪計算、鍵槽計算放在同一個群組，不另開一套模組與角色，
         否則管理員要在兩個地方各指派一次。</p>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>

<?php
/* 砂輪干涉 3D 模擬元件（CSS＋視窗＋JS，唯一實作）。
   本頁用「嵌入式」掛法：元件本身也支援浮動視窗（openGrind3D()），
   但獨立頁面用整個畫面看 3D 比開一個視窗好得多。
   注意：元件內部會載入 o3dv.min.js 與 eg_3d_viewer_tools.js，所以要放在
   jQuery 等版型 JS 之後、下面那段 mount 之前。 */
$GRIND3D_TOOL_PDO = $db;
include __DIR__ . '/../Sales/_grind3d_tool_ui.php';
?>

<script>
$(document).ready(function () {
    $('#btnPageHelp').on('click', function () {
        document.getElementById('helpUseMask').classList.add('show');
    });
    $('#helpUseMask').on('click', function (e) {
        if (e.target === this) this.classList.remove('show');
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') document.getElementById('helpUseMask').classList.remove('show');
    });

    var host = document.getElementById('g3dHost');
    if (host && window.EGGrind3D) EGGrind3D.mount(host);
});

/* 側欄要等共用選單那支 window.load 的收合邏輯先跑完才顯示，不可以在 document.ready
   就顯示——document.ready 通常比 window.load 早，本頁又載了 1MB 的 o3dv，兩者落差
   被拉長，會讓使用者看到「測試功能」子選單還是展開的那個中間畫面。本段在 DOM 順序上
   排在 sideAndTopBarMenu.html 之後，同一事件的多個監聽者按註冊順序觸發，所以共用邏輯
   的收合一定先跑完。 */
$(window).on('load', function () {
    $('#sidebar-menu').css('visibility', 'visible');
});
</script>
</body>
</html>
