<?php
// c:\MAMP\htdocs\EGsystem\views\Sales\_grind3d_tool_ui.php
// ── 砂輪/刀具干涉 3D 模擬：畫面（CSS＋視窗＋JS）共用檔，唯一實作 ───────────────────
// 2026-10-12 新建（比照 _gear_tool_ui.php／_keyway_tool_ui.php 的可插拔寫法）。
//
// 用法（任何頁面，兩種都可以）：
//   A) 浮動視窗：$GRIND3D_TOOL_PDO = $pdo; include __DIR__.'/_grind3d_tool_ui.php';
//      再放一顆 <button onclick="openGrind3D()">砂輪/刀具干涉3D</button>
//      （按鈕要不要顯示請用 $can_grind3d 判斷——本檔會自動算好）
//   B) 嵌在頁面裡（獨立頁面建議用這個，畫面大得多）：
//      <div id="myHost" style="height:640px"></div>
//      <script>EGGrind3D.mount(document.getElementById('myHost'));</script>
//
// ── 這個工具為什麼不必引入 three.js（2026-10-12 實測確認）─────────────────────
// 本站已內建 o3dv.min.js（Online3DViewer，內部包著 three.js）。它雖然是「檔案檢視器」，
// 但有把下列東西開放出來，所以可以用程式即時產生幾何直接塞進檢視器當主模型，不必存檔、
// 不必重新載入檔案、相機角度也不會被重設：
//   OV.GenerateCylinder/GenerateCone      參數化基本體（本檔另有自寫的迴轉體 revolveZ）
//   OV.Mesh / Model / Node / PhongMaterial 自行堆幾何
//   OV.Node.SetTransformation(矩陣)        擺位與傾角（砂輪/刀具傾角走這裡，不必重算三角形）
//   OV.FinalizeModel → OV.ConvertModelToThreeObject  轉成真正的 three.js 物件
//   viewer.SetMainObject(物件)             進檢視器（EmbeddedViewer 載檔案時就是呼叫這支）
// 已在 Node 裡實際載入函式庫跑過一輪確認：GenerateCylinder 回傳 128 頂點／252 三角形的
// OV.Mesh；節點轉換矩陣的 cos20° 確實出現在轉換後 three.js 物件的 matrix 上；轉換結果是
// 真正的 THREE.Mesh / BufferGeometry / MeshPhongMaterial。
//
// 注意 ConvertModelToThreeObject 是**非同步**的（內部用分批任務逐段建幾何），結果只會
// 從 callbacks.onModelLoaded 回來，呼叫完當下去讀回傳值一定是 null（實測踩過）。
//
// 相機：第一次載入才呼叫 FitSphereToWindow 把模型擺滿畫面；之後參數改變一律只呼叫
// SetMainObject，**刻意不再 Fit**，否則使用者每拉一次拉桿視角就被轉回預設角度。
//
// 本檔不依賴 jQuery，也不引用呼叫端頁面的任何全域函式。
require_once __DIR__ . '/../../src/common/grind3d_tool_lib.php';

/* 重複 include 防護：齒輪計算工具 _gear_tool_ui.php 已經會 include 本檔（它的
   出尾計算分頁旁有一顆 3D 按鈕），而同一個頁面可能又自己 include 一次（例如
   views/TD/grind_interference_3d.php）。第二次要整個不輸出，否則會有兩份 CSS、
   兩個同 id 的浮動視窗與兩份 EGGrind3D。 */
if (defined('EG_GRIND3D_UI_INCLUDED')) return;
define('EG_GRIND3D_UI_INCLUDED', 1);

if (!isset($can_grind3d)) {
    $__g3_pdo = null;
    foreach ([$GRIND3D_TOOL_PDO ?? null, $pdo ?? null, $db ?? null] as $__g3_c) {
        if ($__g3_c instanceof PDO) { $__g3_pdo = $__g3_c; break; }
    }
    $can_grind3d = grind3d_tool_can_use($__g3_pdo, intval($_SESSION['id'] ?? 0));
}
$__g3_dir = __DIR__ . '/../../resource/js/';
/* 函式庫網址推成「網站絕對路徑」：本檔會被不同目錄深度的頁面 include
   （views/Sales、views/TD…），寫死 ../../ 換個目錄就 404。 */
$__g3_fs   = str_replace(chr(92), '/', (string)realpath($__g3_dir));
$__g3_root = str_replace(chr(92), '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$__g3_url  = ($__g3_fs && $__g3_root && strpos($__g3_fs, $__g3_root) === 0)
           ? substr($__g3_fs, strlen($__g3_root)) : '../../resource/js';
/* 列印用的大標題＝本公司全名（發票用），一律即時查主檔，禁止寫死（ai-rules/16） */
$__g3_corp = '';
try {
    if (isset($GRIND3D_TOOL_PDO) && $GRIND3D_TOOL_PDO instanceof PDO) {
        $__g3_corp = (string)$GRIND3D_TOOL_PDO->query(
            "SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
    }
} catch (Throwable $e) { $__g3_corp = ''; }

/* 本工具的後端端點（砂輪/刀具預設用），同樣推成網址絕對路徑 */
$__g3_here = str_replace(chr(92), '/', __DIR__);
$__g3_api_url = ($__g3_root && strpos($__g3_here, $__g3_root) === 0)
    ? substr($__g3_here, strlen($__g3_root)) . '/grind3d_api.php' : 'grind3d_api.php';
?>
<?php if ($can_grind3d): ?>
<style>
/* ── 砂輪/刀具干涉 3D 模擬 ─────────────────────────────────────────────────────
   配色依 ai-rules/10：底色一律暖色系（暖棕／琥珀／砂），語意色維持全站通用
   （綠＝安全、紅＝需處理，紅色直接用既有的 #DD5138，不另引入冷色系紅）。 */
.g3d-root { --g3d-ink:#4a3520; --g3d-amber:#C77A30; --g3d-line:#E8C9A0;
            --g3d-bg:#FDF8F2; --g3d-ok:#2e7d32; --g3d-bad:#DD5138;
            display:flex; gap:10px; height:100%; min-height:420px;
            font-family:"Microsoft JhengHei","Segoe UI",sans-serif; color:var(--g3d-ink);
            box-sizing:border-box; }
.g3d-root *, .g3d-root *::before, .g3d-root *::after { box-sizing:border-box; }
.g3d-left  { width:316px; flex:0 0 316px; overflow-y:auto; overflow-x:hidden;
             background:var(--g3d-bg); border:1px solid var(--g3d-line);
             border-radius:6px; padding:10px; }
.g3d-right { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; gap:8px; }
.g3d-canvas-wrap{ flex:1 1 auto; min-height:260px; position:relative; }
.g3d-canvas{ position:absolute; inset:0; border:1px solid var(--g3d-line);
             border-radius:6px; background:#F7F3EE; overflow:hidden; }
.g3d-canvas canvas { display:block; }
/* 尺寸標註疊層：畫在 3D 畫布「上面」的 SVG，不是 3D 物件。
   刻意不用 viewer.AddExtraObject()——量測工具的 _render() 每次都會先
   viewer.ClearExtra()，標註會被它整批清掉；而且 3D 幾何畫不出清楚的文字。
   SVG 以投影後的螢幕座標畫，所以會跟著轉動視角一起動，看起來就像長在模型上。 */
.g3d-dim { position:absolute; inset:0; pointer-events:none; overflow:visible; }
.g3d-dim text { font-family:"Microsoft JhengHei",sans-serif; font-weight:700;
                paint-order:stroke; stroke:#fff; stroke-width:3px; stroke-linejoin:round; }
.g3d-canvas-msg { position:absolute; inset:0; display:flex; align-items:center;
             justify-content:center; flex-direction:column; gap:8px;
             font-size:13px; color:#8a7560; text-align:center; padding:16px; }

.g3d-sec { margin-bottom:12px; }
.g3d-sec > h4 { margin:0 0 6px; font-size:12px; font-weight:700; color:#6b4a24;
             border-bottom:1px solid var(--g3d-line); padding-bottom:3px;
             display:flex; align-items:center; gap:5px; line-height:1.5; }
.g3d-row { display:flex; align-items:center; gap:6px; margin-bottom:5px; }
.g3d-row > label { flex:0 0 112px; font-size:12px; line-height:1.4; }
.g3d-row > .g3d-unit { flex:0 0 22px; font-size:11px; color:#8a7560; }
.g3d-ni { flex:1 1 auto; min-width:0; width:100%; padding:3px 6px; font-size:12px;
          border:1px solid #d8c3a4; border-radius:3px; text-align:right;
          background:#fff; color:var(--g3d-ink); font-family:inherit; }
.g3d-ni:focus { outline:none; border-color:var(--g3d-amber);
          box-shadow:0 0 0 2px rgba(199,122,48,.18); }
.g3d-ni.g3d-err { border-color:var(--g3d-bad); background:#FDEDEA; }
.g3d-hint { font-size:11px; color:#8a7560; line-height:1.5; margin:2px 0 6px; }
.g3d-fx { width:100%; border-collapse:collapse; font-size:12px; }
.g3d-fx th { font-weight:600; font-size:11px; color:#8a7560; text-align:center;
             padding:2px 3px; border-bottom:1px solid var(--g3d-line); }
.g3d-fx td { padding:2px 3px; }
.g3d-fx td.g3d-fx-n { text-align:center; color:#8a7560; font-size:11px; }
.g3d-fx .g3d-ni { padding:2px 5px; }
.g3d-fx-del { border:none; background:none; color:#b9a287; cursor:pointer;
              font-size:14px; line-height:1; padding:0 2px; }
.g3d-fx-del:hover { color:var(--g3d-bad); }
.g3d-kv tr.g3d-bind > div { background:#FDF0EA; }
.g3d-badhint { font-size:11px; color:var(--g3d-bad); font-weight:600;
          line-height:1.5; margin:2px 0 6px; display:none; }

.g3d-slider { display:flex; align-items:center; gap:6px; margin-bottom:5px; }
.g3d-slider > label { flex:0 0 112px; font-size:12px; }
.g3d-slider > input[type=range] { flex:1 1 auto; min-width:0; accent-color:var(--g3d-amber); }
.g3d-slider > span { flex:0 0 58px; font-size:11px; text-align:right;
          font-variant-numeric:tabular-nums; }

.g3d-btn { border:none; border-radius:4px; padding:5px 12px; font-size:12px;
          font-weight:600; cursor:pointer; font-family:inherit; line-height:1.5; }
/* var(--g3d-amber) 的 fallback 值一定要補（2026-10-13 使用者實測踩到）：這顆類別
   同時被 .g3d-root 內部按鈕與 #g3dPresetMask／#g3dKvMask 兩個跳窗（它們是 #g3d-win
   的同層兄弟節點，不在 .g3d-root 範圍內，拿不到該 scope 定義的 CSS 變數）共用，
   沒有 fallback 時那兩個跳窗的「儲存」鈕一律沒有背景色（白底白字＝視覺上消失），
   只有 :hover 寫死色才會顯色，症狀就是「要把滑鼠靠近button才看得到」。 */
.g3d-btn-main { background:var(--g3d-amber, #C77A30); color:#fff; }
.g3d-btn-main:hover { background:#b06a26; }
.g3d-btn-o { background:#fff; color:#8a5a22; border:1px solid #d8c3a4; }
.g3d-btn-o:hover { background:#FBF2E6; }
.g3d-btn-o.g3d-on { background:var(--g3d-amber, #C77A30); color:#fff; border-color:var(--g3d-amber, #C77A30); }
.g3d-btn[disabled] { opacity:.45; cursor:not-allowed; }

.g3d-tools { display:flex; flex-wrap:wrap; gap:5px; align-items:center;
          padding:6px 8px; background:var(--g3d-bg);
          border:1px solid var(--g3d-line); border-radius:6px; }
.g3d-tools .g3d-sep { width:1px; height:18px; background:var(--g3d-line); margin:0 2px; }

.g3d-result { border:1px solid var(--g3d-line); border-radius:6px; overflow:hidden; }
.g3d-verdict { padding:7px 10px; font-size:13px; font-weight:700; color:#fff;
          display:flex; align-items:center; gap:7px; line-height:1.5; }
.g3d-verdict.g3d-v-ok  { background:var(--g3d-ok); }
.g3d-verdict.g3d-v-bad { background:var(--g3d-bad); }
.g3d-verdict.g3d-v-na  { background:#9C8468; }
/* 剛好站在極限位置：不是安全也不是干涉，用琥珀色與另外兩種分開 */
.g3d-verdict.g3d-v-edge{ background:#C77A30; }
.g3d-kv { display:grid; grid-template-columns:1fr auto; gap:0;
          background:#fff; font-size:12px; }
.g3d-kv > div { padding:4px 10px; border-top:1px solid #F0E4D2; line-height:1.5; }
.g3d-kv > div:nth-child(2n) { text-align:right; font-variant-numeric:tabular-nums;
          font-weight:600; }
.g3d-kv > div:nth-child(1), .g3d-kv > div:nth-child(2) { border-top:none; }
.g3d-kv .g3d-k-sub { color:#8a7560; font-size:11px; }

/* ── 浮動視窗（方式 A）───────────────────────────────────────────────── */
/* z-index 要比齒輪計算工具視窗（10500）高：這個工具可以從齒輪工具的「出尾計算」
   分頁右側按鈕開啟，開在它後面就是「按了沒反應」（2026-10-12 實測踩到，截圖才看到
   視窗其實有開、只是整個被蓋住）。鍵槽工具是 10400、齒輪 10500，這裡用 10550。 */
#g3d-win { position:fixed; z-index:10550; display:none;
          width:1180px; max-width:96vw; height:706px; max-height:92vh;
          top:46px; left:50%; transform:translateX(-50%);
          background:#fff; border-radius:8px; border:1px solid var(--g3d-line, #E8C9A0);
          box-shadow:0 12px 40px rgba(0,0,0,.35);
          display:none; flex-direction:column; }
#g3d-win.g3d-show { display:flex; }
#g3d-hdr { border-radius:8px 8px 0 0; cursor:move; user-select:none;
          background:linear-gradient(135deg,#5a3418,#C77A30); color:#fff;
          padding:8px 12px; display:flex; align-items:center; gap:8px;
          font-size:14px; font-weight:700; flex:0 0 auto; }
#g3d-hdr .g3d-sp { margin-left:auto; }
#g3d-hdr button { background:rgba(255,255,255,.18); color:#fff; border:none;
          border-radius:4px; padding:3px 9px; font-size:12px; cursor:pointer;
          font-family:inherit; }
#g3d-hdr button:hover { background:rgba(255,255,255,.3); }
#g3d-body { flex:1 1 auto; min-height:0; padding:10px; }
@media print { #g3d-win { display:none !important; } }

/* 砂輪/刀具預設管理跳窗（z-index 要比 3D 視窗 10550 高，否則從視窗裡開會被蓋住） */
.g3d-mask2 { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10600; display:none; }
.g3d-mask2.g3d-show { display:flex; align-items:flex-start; justify-content:center; padding:40px 12px; }
.g3d-mask2-box { background:#fff; border-radius:8px; width:860px; max-width:96vw;
                 max-height:84vh; display:flex; flex-direction:column;
                 font-family:"Microsoft JhengHei","Segoe UI",sans-serif; color:#4a3520; }
.g3d-mask2-hd { padding:10px 14px; border-bottom:1px solid #E8C9A0; font-weight:700;
                display:flex; align-items:center; gap:8px; font-size:14px; }
.g3d-mask2-bd { padding:12px 14px; overflow:auto; }
.g3d-mask2 .g3d-fx th { white-space:nowrap; }
.g3d-kvchk { display:block; font-size:12.5px; padding:3px 2px; cursor:pointer; }
.g3d-kvchk input { margin-right:6px; }
@media print { .g3d-mask2 { display:none !important; } }

/* ── 2D 工程圖（零成本「數字→2D」，2026-10-13）───────────────────────────
   使用者問「輸入數字畫3D，能不能自動轉成2D畫面或圖檔」。查證既有的齒輪計算
   工具「出尾計算」早就有一張手畫的 SVG 剖面圖（肩部＋齒底徑兩段＋尺寸線），
   走的正是「直接拿同一組數字畫2D」而不是「把3D模型拍扁／反推」——拍扁的3D
   截圖是透視變形、沒有尺寸標註的示意圖，反推更糟（三角網格推不出乾淨的
   線段與正確標註）。這裡延伸同一套手法，覆蓋到本工具完整的輸入（含治具
   多段），SVG 本身就是向量圖，下載 PNG／SVG 或列印都直接可用，但**這不是
   AutoCAD/Inventor 打得開的 CAD 檔**，純粹是示意圖，已在跳窗標題與產生的
   圖面本身都寫明。 */
#g3d-2d-box { position:fixed; z-index:10560; display:none;
            width:820px; max-width:94vw; height:600px; max-height:90vh;
            min-width:420px; min-height:300px; resize:both; overflow:hidden;
            top:60px; left:calc(50% - 410px);
            background:#fff; border-radius:8px; border:1px solid var(--g3d-line, #E8C9A0);
            box-shadow:0 12px 40px rgba(0,0,0,.4); flex-direction:column; }
#g3d-2d-box.g3d-show { display:flex; }
#g3d-2d-hdr { cursor:move; user-select:none; flex:0 0 auto;
            background:linear-gradient(135deg,#5a3418,#C77A30); color:#fff;
            padding:6px 10px; display:flex; align-items:center; gap:6px; font-size:12px; }
#g3d-2d-hdr .g3d-sp { margin-left:auto; }
#g3d-2d-hdr button { background:rgba(255,255,255,.18); color:#fff; border:none;
            border-radius:4px; padding:3px 8px; font-size:11px; cursor:pointer;
            font-family:inherit; }
#g3d-2d-hdr button:hover { background:rgba(255,255,255,.3); }
#g3d-2d-stage { flex:1 1 auto; min-height:0; overflow:auto; padding:10px;
            background:#FDF8F2; display:flex; align-items:center; justify-content:center; }
#g3d-2d-stage svg { width:100%; height:100%; }
@media print { #g3d-2d-box { display:none !important; } }
</style>

<!-- ── 浮動視窗（方式 A；嵌入式用 EGGrind3D.mount() 不經過這裡）──────────── -->
<div id="g3d-win">
    <div id="g3d-hdr">
        <i class="fa fa-circle-o-notch"></i><span>砂輪/刀具干涉 3D 模擬</span>
        <span class="g3d-sp"></span>
        <button type="button" onclick="EGGrind3D.close()">關閉</button>
    </div>
    <div id="g3d-body"></div>
</div>

<!-- 砂輪/刀具預設管理（2026-10-13）：整份清單一次送出 -->
<div class="g3d-mask2" id="g3dPresetMask">
  <div class="g3d-mask2-box">
    <div class="g3d-mask2-hd"><i class="fa fa-sliders"></i> 砂輪/刀具尺寸預設（全廠共用）
        <span style="margin-left:auto"></span>
        <span id="g3dPresetStatus" style="font-size:12px;font-weight:400;margin-right:10px"></span>
        <button type="button" class="g3d-btn g3d-btn-o" id="g3dPresetAdd">＋ 新增一筆</button>
        <button type="button" class="g3d-btn g3d-btn-main" id="g3dPresetApply">儲存</button>
        <button type="button" class="g3d-btn g3d-btn-o" id="g3dPresetClose">關閉</button>
    </div>
    <div class="g3d-mask2-bd">
      <table class="g3d-fx" id="g3dPresetTbl"><thead><tr>
        <th style="min-width:120px">名稱</th><th>外徑</th><th>刃寬</th><th>側面角</th>
        <th>刃高</th><th>傾角</th><th>退刀量</th><th style="width:26px"></th>
      </tr></thead>
      <tbody id="g3dPresetBody" data-eg-row-add="g3dPresetRowAdd"
             data-eg-row-del="g3dPresetRowDel"></tbody></table>
      <div class="g3d-row" style="margin-top:8px">
        <label style="flex:0 0 auto">載入時自動套用</label>
        <select class="g3d-ni" id="g3dPresetAuto" style="text-align:left;flex:1 1 auto"></select>
      </div>
      <div class="g3d-hint">只有一組預設時<b>一律自動套用</b>（不必在這裡指定）；
        有兩組以上時，這裡選到的那一組會在每次開啟工具時自動帶入。
        從齒輪計算工具帶著砂輪/刀具外徑進來時不會自動套用，免得把帶進來的尺寸蓋掉。</div>
      <div class="g3d-hint">名稱必填且不可重複，至少要填砂輪/刀具外徑；其餘留白的欄位套用時不會覆蓋目前的值。
        刪除請按該列最右邊的 ×，按「儲存」才會真的寫入。這份清單是<b>全廠共用</b>的。</div>
    </div>
  </div>
</div>

<!-- 計算結果顯示項目設定（限系統管理員，2026-10-13 使用者要求） -->
<div class="g3d-mask2" id="g3dKvMask">
  <div class="g3d-mask2-box" style="max-width:420px">
    <div class="g3d-mask2-hd"><i class="fa fa-list-ul"></i> 計算結果顯示項目
        <span style="margin-left:auto"></span>
        <span id="g3dKvStatus" style="font-size:12px;font-weight:400;margin-right:10px"></span>
        <button type="button" class="g3d-btn g3d-btn-main" id="g3dKvApply">儲存</button>
        <button type="button" class="g3d-btn g3d-btn-o" id="g3dKvClose">關閉</button>
    </div>
    <div class="g3d-mask2-bd">
      <div id="g3dKvBody"></div>
      <div class="g3d-hint">勾起來的才會出現在畫面的「計算結果」與列印報表上（兩邊一致）。
        這份設定是<b>全廠共用</b>的，只有系統管理員改得了；至少要留一列。</div>
    </div>
  </div>
</div>

<!-- ── 2D 工程圖（零成本「數字→2D」，與數字→3D 平行、不是互相轉換）────────── -->
<div id="g3d-2d-box">
    <div id="g3d-2d-hdr">
        <i class="fa fa-drafting-compass"></i><span>2D 工程圖（示意剖面圖）</span>
        <span class="g3d-sp"></span>
        <button type="button" id="g3d2dPng">下載 PNG</button>
        <button type="button" id="g3d2dSvg">下載 SVG</button>
        <button type="button" id="g3d2dPrint">列印</button>
        <button type="button" id="g3d2dClose">關閉</button>
    </div>
    <div id="g3d-2d-stage"></div>
</div>

<?php /* o3dv.min.js（1MB）與 eg_3d_viewer_tools.js（143KB）刻意**不在這裡載入**：
   本元件會被掛在訂單追蹤（現場每天在用的清單、11,600 行）與批圖編輯器上，
   多 1.1MB 進頁面載入路徑是不能接受的。改成使用者第一次真的打開 3D 工具時
   才動態注入（見下面的 ensureLibs），沒點開就完全沒有成本。 */ ?>
<script>
/* ══════════════════════════════════════════════════════════════════════════
   砂輪/刀具干涉 3D 模擬（唯一實作）
   ── 座標系（整支程式都用這一套，不要另外換算）──────────────────────────
     工件軸 = X 軸；肩部端面固定在 x=0；齒（花鍵）段往 +X；肩部往 -X。
     半徑 r = sqrt(y²+z²)；砂輪/刀具在工件上方（+Y）。
     砂輪/刀具局部座標的迴轉軸 = Z（與工件軸垂直）；傾角 = 繞世界 Y 軸旋轉。
     這一套刻意與齒輪計算工具「出尾計算」的 2D 剖面圖同一個方向（肩部在左、
     齒段在右、肩部面在原點），兩邊的數字才對得起來。

   ── 干涉是怎麼算的（這段是這個工具可不可信的關鍵）──────────────────────
     工件是迴轉體，所以只要把砂輪/刀具表面的點投影回 (軸向 x, 半徑 r) 平面，判斷
     落不落在「肩部實體」這個矩形內即可——不管砂輪/刀具怎麼傾斜都一樣正確。
     禁區 = 肩部實體 = { -Lsh <= x <= 0 , r <= Rs }。
     砂輪/刀具侵入齒段（x >= 0 且 r <= Rr）是正常的，那就是在磨溝槽，不算干涉；
     真正不可以碰到的是肩部。3D 上這兩種用不同顏色分開，不要混在一起。

   ── 為什麼不用「在砂輪/刀具表面撒點掃描」的做法（第一版踩過，必須記住）────────
     第一版是對砂輪/刀具表面撒 2700 個點、再用二分法找極限位置。寫完跑自我驗證就
     發現傾角 0 時算出 50.000，而現有 2D 公式是 52.678，**差 2.7mm（5%）**。
     根因是圓周只取樣 180 等分（每 2°），臨界角 sin(phi) = -0.85（phi =
     238.21°、cos = -0.5268）永遠不會剛好被取樣到，實際命中的是 240°
     （cos = -0.5），於是答案被低估成 100 x 0.5 = 50。這種誤差對干涉判斷不能
     接受，而把取樣加密 20 倍也只是把誤差變小、不會變成 0，還拖慢拉桿連動。
     改成下面的解析解之後，傾角 0 的結果與 2D 公式**完全相同（差 < 1e-12）**。

   ── 解析解的推導（三個關鍵觀察）─────────────────────────────────────────
     ①「砂輪/刀具中心離工件軸心的距離 cy」與傾角無關，恆 = Rr + Rw。
        因為砂輪/刀具上任何一點距工件軸心至少 cy - Rw（砂輪/刀具半徑就是 Rw），而這個
        下界在 phi=270°、t=0 處真的取到，所以「磨到齒底徑」就是 cy - Rw = Rr。
     ② 砂輪/刀具最深那一點恆在 phi=270°、t=0，該點的局部 x = Rw*cos(270°) = 0，
        所以它的世界軸向座標恆等於 cx。因此「溝槽達到全深的位置」＝ cx，
        出尾長度 = 極限位置 cxLimit，不必另外再找最深點。
     ③ 極限位置可以逐「圈」解析求出，不必對整個表面取樣：
        砂輪/刀具是迴轉體，表面由一圈一圈的圓構成，每一圈用 (rho, t) 標定
        （rho = 離砂輪/刀具軸的半徑、t = 沿砂輪/刀具軸的位置）。對某一圈：
            Xlocal(phi) = rho*cos(phi)*cg + t*sg
            r(phi)²     = (rho*sin(phi) + cy)² + (rho*cos(phi)*sg - t*cg)²
        要求的是「這一圈最多能逼近肩部到哪裡」= max(-Xlocal)，條件 r <= Rs。
        -t*sg 是常數，所以等價於「在可行範圍內把 cos(phi) 壓到最小」。
        從 phi=270° 往 180° 走 cos(phi) 單調遞減，所以答案就落在
        **從 270° 出發、第一次碰到 r = Rs 的那個角度**，解一個一維根即可。
        （往 360° 那一側 cos(phi) 遞增，永遠不會比 270° 本身更好，不必算。）
        cxLimit = 所有圈的候選值取最大。
   ══════════════════════════════════════════════════════════════════════════ */
var EGGrind3D = (function () {
    'use strict';

    /* 預設值刻意挑一組「齒段長度 > 出尾長度」的案例：Ø200 砂輪/刀具配 Ø40 齒底徑算出來的
       出尾是 52.7mm，齒段若只有 40mm，砂輪/刀具的切點會落在工件末端之外，一打開看到的
       第一張圖就很難讀（而且那其實代表這支砂輪/刀具根本磨不動這段齒）。 */
    var DEF = { Dw:200, Tw:6, pa:0, Hf:10, tilt:0, Dr:40, Ds:70, Lsh:20, Lsp:80, U:0.5 };

    var SEG = 96;            /* 顯示用迴轉體的圓周分段 */
    var EPS = 1e-9;
    var N_FLANK = 10;         /* 砂輪/刀具側面往內徑取幾圈（只影響「哪些圈參與判定」）*/
    var BISECT = 90;          /* 一維根的二分次數（double 精度下 90 次已到極限）*/

    /* ── 參數正規化（全部轉成數字並夾到合理範圍）───────────────────────── */
    function norm(o) {
        o = o || {};
        function n(k, min, max) {
            var v = parseFloat(o[k]);
            if (!isFinite(v)) v = DEF[k];
            if (min !== undefined && v < min) v = min;
            if (max !== undefined && v > max) v = max;
            return v;
        }
        var p = {
            Dw:   n('Dw',   1, 2000),
            Tw:   n('Tw', 0.1,  500),
            pa:   n('pa',   0,   60),
            Hf:   n('Hf',   0,  500),
            tilt: n('tilt', -75, 75),
            Dr:   n('Dr', 0.1, 2000),
            Ds:   n('Ds', 0.1, 2000),
            Lsh:  n('Lsh',0.1, 2000),
            Lsp:  n('Lsp',0.1, 2000),
            U:    n('U',    0,  500)
        };
        p.Rw = p.Dw / 2; p.Rr = p.Dr / 2; p.Rs = p.Ds / 2;
        p.H  = p.Rs - p.Rr;                       /* 肩部高出齒底徑多少 */
        p.Hf = Math.min(p.Hf, p.Rw * 0.9);        /* 刃高不可超過砂輪/刀具半徑 */
        p.Tb = p.Tw + 2 * p.Hf * Math.tan(p.pa * Math.PI / 180);  /* 砂輪/刀具本體厚度 */

        /* ── 治具：多段直徑，從肩部外端往外依序接（使用者 2026-10-12 拍板的輸入方式）
           第 1 段＝最靠近肩部的那一段。直徑或長度沒填（<=0）的列直接略過，
           讓使用者可以留著空列不必刪。 */
        p.fx = [];
        var raw = Array.isArray(o.fx) ? o.fx : [];
        for (var i = 0; i < raw.length && p.fx.length < 12; i++) {
            var d = parseFloat(raw[i] && raw[i].d), L = parseFloat(raw[i] && raw[i].len);
            if (!isFinite(d) || !isFinite(L) || d <= 0 || L <= 0) continue;
            p.fx.push({ d: Math.min(d, 4000), len: Math.min(L, 4000) });
        }

        /* ── 障礙物清單：砂輪/刀具**不可以碰到**的每一塊實體，一律表示成
              (軸向 x0~x1, 半徑 <= R) 的矩形（工件與治具都是迴轉體，所以在
              (軸向, 半徑) 平面上就是矩形）。肩部與治具用同一種表示法，下面的
              求解、染色、間隙全部共用一條路徑，不會變成兩套規則（鐵律4）。
              **齒段不在這個清單裡**：那是要被磨的料，砂輪/刀具本來就該碰到它。 */
        p.obs = [{ x0: -p.Lsh, x1: 0, R: p.Rs, name: '肩部', kind: 'shoulder' }];
        var cur = -p.Lsh;
        for (var k = 0; k < p.fx.length; k++) {
            var x1 = cur, x0 = cur - p.fx[k].len;
            p.obs.push({ x0: x0, x1: x1, R: p.fx[k].d / 2,
                         name: '治具第' + (k + 1) + '段', kind: 'fixture', idx: k });
            cur = x0;
        }
        p.fxTotal = -p.Lsh - cur;                 /* 治具總長 */
        return p;
    }

    function fx(v, n) {
        if (v === null || v === undefined || !isFinite(v)) return '—';
        return v.toFixed(n === undefined ? 3 : n);
    }

    /* ── 提醒（**不擋下計算**）────────────────────────────────────────────
       2026-10-12 使用者回報「一直卡在一定要肩部>齒底徑，不然無法計算」。
       原本這裡有兩道硬性把關，查證後**兩道都是多餘而且是錯的**：
         ⑴「肩部直徑必須大於齒底徑」——現場常見工件在這一側是**往小的縮**
           （使用者的實例：齒底徑 Ø118、肩部只有 Ø90），這時候根本沒有肩部，
           真正會擋到砂輪/刀具的是治具；擋下來等於整組算不了。而且求解器本來就
           處理得好好的：砂輪/刀具最深只到齒底徑，碰不到比它更小的東西，那一塊
           自然不會是卡住的那一塊（ringLimit 會回 -Infinity）。
         ⑵「肩部高出齒底徑已達砂輪/刀具半徑＝進不到齒底徑」——這句也不成立。
           砂輪/刀具中心恆在 Rr+Rw，不管肩部多高，砂輪/刀具都磨得到齒底徑，只是要離
           肩部遠一點而已（出尾變長）。連 H > 2Rw（肩部比砂輪/刀具整個還高）求解器
           都算得對：那時整個砂輪/刀具都在肩部半徑之內，答案是「砂輪/刀具必須整個待在
           肩部端面右側」，cxLimit ≈ 砂輪/刀具半徑。
       所以這裡改成只回「提醒」，一律不阻止計算。 */
    function notes(p) {
        var w = [];
        if (p.Rs <= p.Rr + EPS) {
            w.push('肩部直徑 Ø' + fx(p.Ds, 1) + ' 不大於齒底徑 Ø' + fx(p.Dr, 1)
                 + '，這一側沒有肩部擋住砂輪/刀具，出尾長度由治具（或完全沒有限制）決定。');
        }
        var small = p.obs.filter(function (o) {
            return o.kind === 'fixture' && o.R <= p.Rr + EPS;
        });
        if (small.length) {
            w.push(small.map(function (o) { return o.name; }).join('、')
                 + ' 的直徑不大於齒底徑，砂輪/刀具碰不到，不會是卡住的那一段。');
        }
        return w;
    }

    /* ══ 一、工件（迴轉體輪廓，局部座標 (r, z)，z 即軸向）══════════════════
       頭尾都落在軸線 r=0，所以迴轉後是封閉實體。 */
    function wpProfile(p) {
        return [[0,-p.Lsh],[p.Rs,-p.Lsh],[p.Rs,0],[p.Rr,0],[p.Rr,p.Lsp],[0,p.Lsp]];
    }

    /* 治具輪廓（接在肩部外端 x=-Lsh 往外）。revolveZ 要求 z 由小到大，所以從
       最外端往肩部方向排。相鄰兩段同直徑時會產生零面積三角形（法向量算出來
       是 NaN），所以要去掉重複點。 */
    function fxProfile(p) {
        if (!p.obs) return null;
        var segs = p.obs.filter(function (o) { return o.kind === 'fixture'; });
        if (!segs.length) return null;
        var prof = [[0, segs[segs.length - 1].x0]];
        for (var i = segs.length - 1; i >= 0; i--) {
            prof.push([segs[i].R, segs[i].x0]);
            prof.push([segs[i].R, segs[i].x1]);
        }
        prof.push([0, segs[0].x1]);
        var out = [];
        for (var k = 0; k < prof.length; k++) {
            var a = out[out.length - 1];
            if (a && Math.abs(a[0] - prof[k][0]) < 1e-9 && Math.abs(a[1] - prof[k][1]) < 1e-9) continue;
            out.push(prof[k]);
        }
        return out.length >= 3 ? out : null;
    }

    /* ══ 二、砂輪/刀具（迴轉體輪廓，局部座標 (rho, t)，t 即砂輪/刀具軸向）════════════
       外徑處刃寬 Tw，往內徑在「刃高 Hf」範圍內每側以側面角 pa 張開，再往內
       就維持本體厚度 Tb 不再變寬（實際修整過的砂輪/刀具就是這個形狀；若讓側面角
       一路張到輪轂，大直徑砂輪/刀具會算出幾十 mm 的荒謬厚度）。 */
    function whProfile(p) {
        var h = p.Tw / 2, r1 = Math.max(p.Rw - p.Hf, 0);
        var ta = (p.Hf > EPS && p.pa > EPS) ? Math.tan(p.pa * Math.PI / 180) : 0;
        /* 某個半徑處的半厚度 */
        function ht(rho) { return rho >= r1 ? h + (p.Rw - rho) * ta : p.Tb / 2; }

        /* 側面一定要沿半徑細分，不可以用一片從 ρ=0 拉到 ρ=Rw 的大三角形：
           干涉染色是「三角形只要有一個頂點在肩部裡就整片染紅」，一片橫跨中心到
           外徑的三角形會讓紅色從外緣一路延伸到砂輪/刀具中心（2026-10-12 截圖實測看到
           一條假的紅楔形）。取樣刻意偏重外側——只有靠近外徑的部分碰得到肩部，
           內側畫粗一點不影響正確性也省三角形。 */
        var frac = [0, 0.35, 0.65, 0.82, 0.90, 0.95, 0.975, 0.99, 1];
        var rs = [], i;
        for (i = 0; i < frac.length; i++) rs.push(p.Rw * frac[i]);
        if (r1 > EPS && r1 < p.Rw) {            /* 側面角的轉折點一定要是一個環，否則稜線會被抹平 */
            rs.push(r1);
            rs.sort(function (a, b2) { return a - b2; });
        }
        /* 去掉過於接近的重複環（會產生零面積三角形，法向量算出來是 NaN） */
        var uniq = [];
        for (i = 0; i < rs.length; i++) {
            if (!uniq.length || rs[i] - uniq[uniq.length - 1] > p.Rw * 1e-4) uniq.push(rs[i]);
        }

        var prof = [];
        for (i = 0; i < uniq.length; i++) prof.push([uniq[i], -ht(uniq[i])]);     /* 下側面：ρ 0→Rw */
        for (i = uniq.length - 1; i >= 0; i--) prof.push([uniq[i],  ht(uniq[i])]); /* 上側面：ρ Rw→0 */
        return prof;
    }

    /* ══ 三、參與干涉判定的「圈」(rho, t) ══════════════════════════════════
       外徑刃帶的兩個邊緣與中央，加上兩側（含側面角斜面）往內徑的若干圈。
       刻意只取外側（rho 從 Rw 往內到 Rw-Hf）：再往內的砂輪/刀具本體離工件軸心更遠
       （至少 cy-rho > cy-Rw），不可能先碰到肩部。 */
    function wheelRings(p) {
        var rings = [], h = p.Tw / 2, ta = Math.tan(p.pa * Math.PI / 180);
        var r1 = Math.max(p.Rw - p.Hf, 0);
        rings.push([p.Rw, -h]); rings.push([p.Rw, 0]); rings.push([p.Rw, h]);
        for (var k = 1; k <= N_FLANK; k++) {
            var rho = p.Rw - (p.Rw - r1) * k / N_FLANK;
            var tt  = h + (p.Rw - rho) * ta;
            rings.push([rho,  tt]);
            rings.push([rho, -tt]);
        }
        return rings;
    }

    /* ══ 四、單一圈能逼近「半徑 R 的障礙物」到哪裡（解析解，見檔頭推導③）══
       回傳 max(-Xlocal)（相對砂輪/刀具中心）；-Infinity＝這一圈永遠碰不到這個半徑。
       R 是參數而不是寫死肩部半徑，肩部與治具每一段才能共用同一條路徑。 */
    function ringLimit(rho, t, cg, sg, cy, R) {
        var Rs2 = R * R;
        function r2(th) {
            var a = rho * Math.sin(th) + cy;
            var b = rho * Math.cos(th) * sg - t * cg;
            return a * a + b * b;
        }
        function cand(th) { return -(rho * Math.cos(th) * cg + t * sg); }
        /* 回傳值一併帶出「是在哪個角度達成的」＝接觸點，3D 上要標出來給人看
           （2026-10-13 使用者回報「3D 看不出來有沒有干涉」）。
           **這支不可以取名 R**：R 是本函式的障礙物半徑參數，函式宣告會被提升而把
           參數整個蓋掉，於是 Rs2 = R * R 變成 NaN、所有比較都不成立，求解器會一律
           回傳砂輪/刀具半徑（實測出尾全部變成 100.000）。自我驗證當場抓到。 */
        function mk(th) { return { v: cand(th), phi: th }; }

        var mid = 1.5 * Math.PI;              /* phi = 270°：砂輪/刀具最深的那一點 */
        if (r2(mid) > Rs2) return { v: -Infinity, phi: mid };  /* 連最深處都碰不到 */

        /* 從 270° 往 180° 掃，找第一次離開可行區（r > Rs）的那一小段再二分。
           刻意先粗掃再二分、不直接對整個 [180°,270°] 二分：r(phi) 絕大多數情況
           單調，但傾角大時 b² 那一項會讓它不保證單調，直接二分有可能跳到另一個
           根上去（算出來的數字看起來正常，錯了也看不出來）。 */
        var lo = mid, hi = null, N = 360;
        for (var i = 1; i <= N; i++) {
            var th = mid - (mid - Math.PI) * i / N;
            if (r2(th) > Rs2) { hi = th; break; }
            lo = th;
        }
        if (hi === null) return mk(Math.PI);     /* 整個分支都可行，極值在 180° */
        for (var k = 0; k < BISECT; k++) {
            var m = (lo + hi) / 2;
            if (r2(m) <= Rs2) lo = m; else hi = m;
        }
        return mk(lo);
    }

    /* ══ 五、擺位：繞世界 Y 軸傾斜 tilt，再平移到 (cx, cy, 0)═══════════════
       world = R_y(tilt) * local + (cx, cy, 0)
       這必須與下面傳給 OV.Node.SetTransformation 的矩陣是同一個轉換，否則
       「算出來的干涉」與「畫出來的位置」會對不起來，而且畫面上完全看不出來。 */
    function placed(lp, cg, sg, cx, cy) {
        var x = lp[0], y = lp[1], z = lp[2];
        return [ x * cg + z * sg + cx,  y + cy,  -x * sg + z * cg ];
    }

    /* ══ 六、禁區的有號距離 ═══════════════════════════════════════════════
       工件與治具都是迴轉體，所以在 (軸向 x, 半徑 r) 平面上每一塊都是矩形。
       回傳 >0 = 侵入深度；<=0 = 在外面（絕對值＝到該矩形的距離）。
       **只用在「3D 上哪些三角形要塗紅色」**，畫面上的數字結論一律用解析解，
       兩者不會互相矛盾（解析解 = 0 的瞬間這裡也剛好 = 0）。 */
    function rectSD(x, r, o) {
        var dxL = o.x0 - x, dxR = x - o.x1, dr = r - o.R;
        if (dxL <= 0 && dxR <= 0 && dr <= 0) return Math.min(-dxL, -dxR, -dr);
        var ox = Math.max(dxL, 0, dxR), oy = Math.max(dr, 0);
        return -Math.sqrt(ox * ox + oy * oy);
    }
    /** 對整份障礙物清單取最大（落在任何一塊裡面就算干涉） */
    function obsSD(x, r, p) {
        var best = -Infinity;
        for (var i = 0; i < p.obs.length; i++) {
            var d = rectSD(x, r, p.obs[i]);
            if (d > best) best = d;
        }
        return best;
    }

    /* ══ 七、主分析 ════════════════════════════════════════════════════════ */
    function analyze(raw) {
        var p = norm(raw);
        var out = { p: p, ok: true, err: '', notes: notes(p) };

        var g = p.tilt * Math.PI / 180, cg = Math.cos(g), sg = Math.sin(g);
        var cy = p.Rr + p.Rw;          /* 解析解，與傾角無關（檔頭推導①）*/
        var rings = wheelRings(p), i;

        /** 砂輪/刀具在半徑 R 這一圈高度上，最多能往 -X 伸多遠（相對砂輪/刀具中心）。
            障礙物右端面在 x1 時，它要求的 cx 下限 = x1 + reach(R)。 */
        function reachAt(R) {
            var m = -Infinity, best = null;
            for (var k = 0; k < rings.length; k++) {
                var rho = rings[k][0], t = rings[k][1];
                var q = ringLimit(rho, t, cg, sg, cy, R);
                if (q.v > m) {
                    m = q.v;
                    best = [rho * Math.cos(q.phi), rho * Math.sin(q.phi), t];
                }
            }
            return { v: m, local: best };
        }
        function reach(R) { return reachAt(R).v; }

        /* 逐一障礙物算出「砂輪/刀具中心最多能靠到哪個軸向位置」，整體取最大。
           會卡住的不一定是肩部——砂輪/刀具夠大時常常是治具某一段先撞到，
           所以要記住是哪一塊在卡（binding），畫面上要指名道姓。 */
        var lim = -Infinity, bind = null;
        for (i = 0; i < p.obs.length; i++) {
            var o = p.obs[i];
            var rc = reachAt(o.R);
            o.cxLimit  = isFinite(rc.v) ? (o.x1 + rc.v) : -Infinity;
            o.hitLocal = rc.local;        /* 接觸點（砂輪/刀具局部座標），3D 上要標出來 */
            if (o.cxLimit > lim) { lim = o.cxLimit; bind = o; }
        }
        /* 全部障礙物都碰不到（例如工件往小的縮、又沒填治具）＝砂輪/刀具沒有任何東西
           擋著，出尾長度 0。這是合法結果，不是錯誤，畫面要講清楚而不是報錯。 */
        out.blocked = isFinite(lim);
        if (!isFinite(lim)) { lim = 0; bind = null; }

        out.ok      = true;
        out.cy      = cy;
        out.cg      = cg;
        out.sg      = sg;
        out.rings   = rings;
        out.reach   = reach;
        out.bind    = bind;
        out.cxLimit = lim;
        /* 最深點恆在 x = cx（檔頭推導②），所以極限位置就是出尾長度。
           **極限位置可能是負的**：工件在這一側往小的縮（沒有肩部）、治具又在很外面
           時，砂輪/刀具可以越過肩部端面繼續磨，這時「出尾長度」是 0 不是負數，多出來的
           那一段改用 overrun 表示並在畫面上講清楚，直接印一個負的出尾沒人看得懂。 */
        out.tailRaw = lim;
        out.tail    = Math.max(lim, 0);
        out.overrun = lim < 0 ? -lim : 0;
        out.tailTot = out.tail + p.U;
        /* ── 砂輪/刀具實際要停的位置（2026-10-13 使用者回報）────────────────────
           ＝從「齒底徑邊緣」（x=0，齒段與肩部的交界面）往外退一個「退刀量」。
           這是製程上真正會發生的那個位置，**與治具完全無關**：治具只決定
           「停在這裡會不會撞到」，不該反過來決定砂輪/刀具停在哪裡。
           原本畫面把砂輪/刀具直接擺到 cxLimit（治具決定的極限位置），看起來就像
           砂輪/刀具跟著治具的段在跑。 */
        out.cxWork     = -p.U;
        out.workMargin = out.cxWork - lim;   /* 正＝退得到，負＝退不到（會撞） */
        /* 2D 公式只描述「肩部」這一塊，治具不在它的模型裡，所以兩者只有在
           肩部就是卡住的那一塊、而且傾角 0 時才會相同——畫面上要講明。 */
        /* 沒有肩部（Rs <= Rr）時 2D 出尾公式沒有意義，給 null 讓畫面顯示「—」，
           不要硬算出一個 0 讓人以為那是答案。 */
        out.tail2d  = (p.H > EPS)
            ? Math.sqrt(Math.max(p.Rw * p.Rw - (p.Rw - p.H) * (p.Rw - p.H), 0)) : null;
        out.tail2dT = (out.tail2d === null) ? null : out.tail2d + p.U;
        out.diff    = (out.tail2d === null) ? null : out.tail - out.tail2d;
        return out;
    }

    /* ══ 七之二、某一塊障礙物的「徑向間隙」═══════════════════════════════
       ＝這一塊還可以再長高多少，砂輪/刀具才會剛好碰到它（截圖上那個 10 就是這個）。
       用同一支解析解反解：把 R 往上加到它要求的 cx 下限正好等於目前的 cx，
       多出來的半徑就是間隙。這樣做的好處是**與軸向餘裕保證一致**——兩個數字
       會在同一瞬間同時變成 0，不會出現「說有間隙卻判干涉」這種自相矛盾。
       回傳 >0＝還有間隙；<0＝已經吃進去多少半徑。 */
    function radialGap(res, o, cx) {
        var lo = 0, hi = res.p.Rw + res.cy;           /* 高到一定會碰到 */
        /* 單調性：R 越大，reach(R) 越大、cxLimit 越大 → 越容易被判干涉 */
        if (o.x1 + res.reach(hi) < cx) return hi - o.R;   /* 怎麼長都碰不到 */
        for (var k = 0; k < 48; k++) {
            var m = (lo + hi) / 2;
            if (o.x1 + res.reach(m) < cx) lo = m; else hi = m;
        }
        return (lo + hi) / 2 - o.R;
    }

    /* 指定砂輪/刀具位置時的狀況。
       刻意回報「軸向餘裕」而不是 3D 最小距離：①它與 cxLimit 由同一個解析解
       推出來，畫面上的「干涉/不干涉」永遠不會跟出尾長度自相矛盾 ②現場要的是
       「還能再靠近幾 mm／必須退幾 mm」這個可以直接拿去用的數字。 */
    /* 判「剛好貼到」的容差（mm）。按下「移到不干涉的極限位置」一定會停在 margin=0，
       所以這個狀態非常常出現；原本它顯示成「不干涉：還可以再靠近 0.000 mm」，
       使用者 2026-10-13 直接回報看不懂（「可以再靠近 0.000 mm 表示已經碰到了嗎?」）。
       0.005mm 遠小於任何加工餘裕，落在這個範圍就直接講「剛好貼到」。 */
    var TOUCH_EPS = 0.005;
    function at(res, cx) {
        var m = cx - res.cxLimit;
        return {
            margin: m,
            touch:  Math.abs(m) <= TOUCH_EPS,          /* 剛好貼到（極限位置）*/
            hit:    m < -TOUCH_EPS,                    /* 真的壓進去了 */
            pen:    m < 0 ? -m : 0,
            clr:    m >= 0 ? m : 0
        };
    }

    /* ══════════════════════════════════════════════════════════════════════
       八、顯示用幾何：把 2D 輪廓繞局部 Z 軸迴轉成 OV.Mesh
       ── 繞法與法向量方向（搞錯的話整個模型會變成內外翻、畫面一片暗）──────
         輪廓一律**按 z 由小到大**排列。四邊形的四個角依 A[s] → A[s+1] →
         B[s+1] → B[s] 的順序切成兩個三角形時，法向量 = 切線(+phi) x 徑向外側，
         方向就是朝外。已逐一驗算三種情況皆正確：
           側面（兩圈半徑都 >0）：t x z_hat = 徑向外側  ✔
           階面（兩圈同 z、半徑遞減，例如肩部端面）：t x (-r_hat) = +z_hat  ✔
           端蓋（其中一圈落在軸線 r=0）：退化成單一三角形，方向同上  ✔
         法向量本身由 OV.FinalizeModel 自己算，這裡只負責把繞法排對。

       classify(x,y,z) 回傳該頂點的材質索引，三角形取三個頂點的最大值
       （三個頂點只要有一個判定成干涉，整個三角形就染紅，寧可標得保守一點）。 */
    function revolveZ(prof, seg, baseMat, name, classify) {
        var mesh = new OV.Mesh();
        if (mesh.SetName) mesh.SetName(name || '');
        var rings = [], i, s;
        for (i = 0; i < prof.length; i++) {
            var r = prof[i][0], z = prof[i][1], idx = [];
            /* OV.Mesh.AddVertex() 只收**一個 Coord3D 物件**，不是 x,y,z 三個數字
               （2026-10-12 實測踩過：OV.GenerateCone 內部那行 AddVertex(0,0,n) 是呼叫
               Generator 包裝物件、不是 Mesh，照抄會把單一數字存成頂點，.x/.y/.z 全是
               undefined → 整個 position 屬性變 NaN。症狀是模型完全不顯示、console 丟
               「computeBoundingSphere(): Computed radius is NaN」，而 PHP lint 與
               node --check 都抓不到，只有真的在瀏覽器渲染一次才看得見）。 */
            if (r <= EPS) {
                idx.push(mesh.AddVertex(new OV.Coord3D(0, 0, z)));
            } else {
                for (s = 0; s < seg; s++) {
                    var a = s / seg * 2 * Math.PI;
                    idx.push(mesh.AddVertex(new OV.Coord3D(r * Math.cos(a), r * Math.sin(a), z)));
                }
            }
            rings.push({ idx: idx, r: r, z: z });
        }
        function vmat(ri, s) {
            if (!classify) return baseMat;
            var rr = rings[ri].r, zz = rings[ri].z;
            var a = (rings[ri].idx.length === 1) ? 0 : (s / seg * 2 * Math.PI);
            return classify(rr * Math.cos(a), rr * Math.sin(a), zz);
        }
        /* 平滑群組（curve）一定要**逐輪廓段各給一個**，不可以整個模型共用同一個：
           共用的話 o3dv 會把「圓周側面」與「端面」在共用頂點處的法向量平均起來，
           一片 Ø200x6 的薄圓盤會被畫成一顆球（2026-10-12 實測，截圖一看就知道，
           但幾何其實完全正確——局部包圍盒 x,y 是 ±100、z 是 ±3）。
           逐段給不同 curve：同一段內沿圓周平滑（圓柱面不會一格一格），
           段與段交界維持銳邊（端面與外徑的轉角是真的要有稜線）。 */
        function tri(a, b, c, m, curve) {
            var t = new OV.Triangle(a, b, c);
            t.SetMaterial(m);
            t.SetCurve(curve);
            mesh.AddTriangle(t);
        }
        for (i = 0; i < prof.length - 1; i++) {
            var A = rings[i], B = rings[i + 1];
            var aSingle = (A.idx.length === 1), bSingle = (B.idx.length === 1);
            if (aSingle && bSingle) continue;                 /* 兩端都在軸線＝退化 */
            for (s = 0; s < seg; s++) {
                var s2 = (s + 1) % seg;
                var m = Math.max(vmat(i, s), vmat(i, s2), vmat(i + 1, s), vmat(i + 1, s2));
                var cv = i + 1;               /* 這一段自己的平滑群組 */
                if (aSingle) {
                    tri(A.idx[0], B.idx[s2], B.idx[s], m, cv);
                } else if (bSingle) {
                    tri(A.idx[s], A.idx[s2], B.idx[0], m, cv);
                } else {
                    tri(A.idx[s], A.idx[s2], B.idx[s2], m, cv);
                    tri(A.idx[s], B.idx[s2], B.idx[s], m, cv);
                }
            }
        }
        return mesh;
    }

    /* 直接組 4x4 轉換矩陣（**行主序寫法不可用**：OV.Matrix.Get() 會被
       THREE.Matrix4.fromArray() 讀走，那支吃的是「欄主序」16 個數字，
       擺錯的話旋轉與平移會互相錯位，而且畫面上只是看起來怪不會報錯）。 */
    function mtx(cg, sg, cx, cy, cz) {
        return new OV.Matrix().Set([
            cg,  0, -sg, 0,      /* 第一欄 */
             0,  1,   0, 0,      /* 第二欄 */
            sg,  0,  cg, 0,      /* 第三欄 */
            cx, cy,  cz, 1       /* 第四欄＝平移 */
        ]);
    }

    function phong(r, g, b, op) {
        var m = new OV.PhongMaterial();
        m.color = new OV.RGBColor(r, g, b);
        m.ambient = new OV.RGBColor(Math.round(r * 0.45), Math.round(g * 0.45), Math.round(b * 0.45));
        m.specular = new OV.RGBColor(40, 40, 40);
        m.shininess = 0.25;
        if (op !== undefined && op < 1) { m.opacity = op; m.transparent = true; }
        return m;
    }

    /* 某個世界座標點在不在砂輪/刀具實體裡面（反轉砂輪/刀具的擺位矩陣再比砂輪/刀具輪廓）。
       用來把**障礙物那一側也染紅**：原本只有砂輪/刀具會變紅，而干涉時紅色那一塊是埋在
       工件/治具裡面的，從外面常常看不到，使用者 2026-10-13 回報「3D 看不出來有沒有
       干涉」。兩邊都染色之後，不管從哪個角度看都會有一塊紅的。 */
    function inWheel(wx, wy, wz, res, cx) {
        var p = res.p;
        var dx = wx - cx, dy = wy - res.cy, dz = wz;
        /* 反轉 R_y(tilt)：轉置就是反矩陣 */
        var lx =  dx * res.cg - dz * res.sg;
        var ly =  dy;
        var lz =  dx * res.sg + dz * res.cg;
        var rho = Math.sqrt(lx * lx + ly * ly);
        if (rho > p.Rw) return false;
        var r1 = Math.max(p.Rw - p.Hf, 0);
        var ta = (p.Hf > EPS && p.pa > EPS) ? Math.tan(p.pa * Math.PI / 180) : 0;
        var half = rho >= r1 ? (p.Tw / 2 + (p.Rw - rho) * ta) : (p.Tb / 2);
        return Math.abs(lz) <= half;
    }

    /* 小球標記（標接觸點用）：半圓輪廓繞 Z 迴轉 */
    function sphereProfile(rad) {
        var prof = [], N = 10;
        for (var i = 0; i <= N; i++) {
            var a = -Math.PI / 2 + Math.PI * i / N;
            prof.push([Math.abs(rad * Math.cos(a)), rad * Math.sin(a)]);
        }
        return prof;
    }

    /* ══ 九、組出整個 OV.Model（工件 + 砂輪/刀具）════════════════════════════════
       工件沿局部 Z 迴轉後，用 R_y(90°) 把局部 Z 轉成世界 X（＝工件軸）。
       砂輪/刀具的局部 Z 本來就是它自己的迴轉軸，傾角 0 時正好對上世界 Z。 */
    function buildModel(res, cx, wpOpacity) {
        var p = res.p;
        var model = new OV.Model();
        var mWp   = model.AddMaterial(phong(214, 198, 176, wpOpacity));   /* 工件：砂色 */
        var mFx   = model.AddMaterial(phong(150, 124,  96, wpOpacity));   /* 治具：暖棕 */
        var mBody = model.AddMaterial(phong(150, 150, 155));              /* 砂輪/刀具本體：灰 */
        /* **宣告順序就是嚴重度順序，不可以隨便調換**：revolveZ 以「三角形四個角的
           材質索引取最大值」決定整個三角形的顏色，所以索引越大越優先。干涉紅一定要
           排在強調琥珀後面，否則卡住那一塊的強調色會把真正的干涉紅整個蓋掉
           （2026-10-13 實測踩到：工件那一側一個紅色三角形都沒有）。 */
        var mBind = model.AddMaterial(phong(199, 122,  48, wpOpacity));   /* 卡住的那一塊：琥珀 */
        var mHit  = model.AddMaterial(phong(221,  81,  56));              /* 干涉：紅（最優先）*/
        var mMark = model.AddMaterial(phong(221,  81,  56));              /* 接觸點標記：紅 */
        /* 原本還有一個「正在切削齒段」的琥珀色，已移除：工件畫的是**加工完成後**的
           齒底徑，砂輪/刀具在正確磨削位置只會與齒底圓相切、不會侵入，所以那個顏色實際上
           永遠不會出現（實測染色統計確認 0 個三角形）。留著等於在畫面上承諾一個不會
           發生的狀態。 */

        /* 砂輪/刀具每個頂點屬於哪一類：先依節點轉換算出世界座標再判定，與 analyze()
           用的是同一支 placed()，所以「畫出來的紅色」與「算出來的干涉」一定一致。 */
        function classify(lx, ly, lz) {
            var w = placed([lx, ly, lz], res.cg, res.sg, cx, res.cy);
            var r = Math.sqrt(w[1] * w[1] + w[2] * w[2]);
            return obsSD(w[0], r, p) > 0 ? mHit : mBody;
        }

        /* 工件：被砂輪/刀具吃進去的部分染紅；肩部如果就是卡住的那一塊，整段用琥珀標出來。
           classify 拿到的是**局部座標**（繞 Z 迴轉前），工件節點的轉換是 R_y(90°)，
           所以局部 (x,y,z) → 世界 (z, y, -x)，要先換過去才能跟砂輪/刀具比。 */
        var bindIsShoulder = res.bind && res.bind.kind === 'shoulder';
        function wpClassify(lx, ly, lz) {
            if (inWheel(lz, ly, -lx, res, cx)) return mHit;
            if (bindIsShoulder && lz >= res.bind.x0 - EPS && lz <= res.bind.x1 + EPS) return mBind;
            return mWp;
        }
        var wp = revolveZ(wpProfile(p), SEG, mWp, '工件', wpClassify);
        var iw = model.AddMesh(wp);
        var nw = new OV.Node();
        nw.AddMeshIndex(iw);
        nw.SetTransformation(new OV.Transformation(mtx(0, 1, 0, 0, 0)));  /* R_y(90°) */
        model.GetRootNode().AddChildNode(nw);

        /* 治具：另外一支 mesh、另外一個材質（暖棕），與工件分得開才看得出是撞到
           哪一邊；沒填治具時 fxProfile() 回 null，整段不畫。 */
        var fxp = fxProfile(p);
        if (fxp) {
            var bindFx = (res.bind && res.bind.kind === 'fixture') ? res.bind : null;
            function fxClassify(lx, ly, lz) {
                if (inWheel(lz, ly, -lx, res, cx)) return mHit;
                if (bindFx && lz >= bindFx.x0 - EPS && lz <= bindFx.x1 + EPS) return mBind;
                return mFx;
            }
            var fxm = revolveZ(fxp, SEG, mFx, '治具', fxClassify);
            var ifx = model.AddMesh(fxm);
            var nfx = new OV.Node();
            nfx.AddMeshIndex(ifx);
            nfx.SetTransformation(new OV.Transformation(mtx(0, 1, 0, 0, 0)));  /* R_y(90°) */
            model.GetRootNode().AddChildNode(nfx);
        }

        var wh = revolveZ(whProfile(p), SEG, mBody, '砂輪/刀具', classify);
        var ih = model.AddMesh(wh);
        var nh = new OV.Node();
        nh.AddMeshIndex(ih);
        nh.SetTransformation(new OV.Transformation(mtx(res.cg, res.sg, cx, res.cy, 0)));
        model.GetRootNode().AddChildNode(nh);

        /* 接觸點標記：砂輪/刀具上「最先碰到那一塊」的那一個點。使用者光看紅色區域不容易
           判斷，標一顆小球直接指出「會撞在這裡」。大小隨砂輪/刀具縮放，太小會看不見。 */
        if (res.bind && res.bind.hitLocal) {
            var hp = placed(res.bind.hitLocal, res.cg, res.sg, cx, res.cy);
            var mk = revolveZ(sphereProfile(Math.max(p.Rw * 0.022, 1.2)), 24, mMark, '接觸點', null);
            var imk = model.AddMesh(mk);
            var nmk = new OV.Node();
            nmk.AddMeshIndex(imk);
            nmk.SetTransformation(new OV.Transformation(mtx(1, 0, hp[0], hp[1], hp[2])));
            model.GetRootNode().AddChildNode(nmk);
            res.hitWorld = hp;
        } else {
            res.hitWorld = null;
        }

        OV.FinalizeModel(model, function () { return new OV.RGBColor(180, 180, 185); });
        return model;
    }

    /* ══ 十、檢視器 ════════════════════════════════════════════════════════ */
    /* 函式庫延後載入，所以 T3（EG3DTools）要等 ensureLibs 完成才解析；
       抓不到（檔案 404）時降級成「只有 3D 與計算、沒有剖面量測截圖」，
       不要讓整支工具因為工具列少一個功能就完全不能用。 */
    var T3 = null;

    /* ── 延後載入：第一次真的要用 3D 時才把兩支重量級函式庫注入 ───────────── */
    /* 後端唯一端點（只服務砂輪/刀具預設；計算完全在前端）。網址比照函式庫推成網站
       絕對路徑，不同目錄深度的頁面 include 都指得到。 */
    var API_URL = '<?= $__g3_api_url ?>';
    var CORP_NAME   = '<?= addslashes($__g3_corp) ?>';          /* 列印大標題用，禁寫死 */
    var PRINTLOG_JS = '<?= $__g3_url ?>/eg_print_log.js?v=<?= @filemtime($__g3_dir."eg_print_log.js") ?>';

    /* 列印紀錄共用檔：本元件會被 include 到各種頁面，不能假設宿主載過它 */
    if (!window.EGPrintLog) {
        var _plS = document.createElement('script');
        _plS.src = PRINTLOG_JS;
        document.head.appendChild(_plS);
    }

    var LIBS = ['<?= $__g3_url ?>/o3dv.min.js',
                '<?= $__g3_url ?>/eg_3d_viewer_tools.js?v=<?= @filemtime($__g3_dir."eg_3d_viewer_tools.js") ?>'];
    var _libState = 0;          /* 0未載 1載入中 2完成 3失敗 */
    var _libQueue = [];
    function ensureLibs(cb) {
        if (_libState === 2) { cb(true); return; }
        if (_libState === 3) { cb(false); return; }
        _libQueue.push(cb);
        if (_libState === 1) return;
        _libState = 1;
        var i = 0;
        (function next() {
            if (i >= LIBS.length) {
                T3 = (typeof EG3DTools !== 'undefined' && EG3DTools) ? EG3DTools : null;
                _libState = (typeof OV !== 'undefined' && OV.EmbeddedViewer) ? 2 : 3;
                var q = _libQueue; _libQueue = [];
                q.forEach(function (f) { f(_libState === 2); });
                return;
            }
            /* 頁面上可能已經有人載過 o3dv（例如圖面查閱）：載過就跳過不重載 */
            if (i === 0 && typeof OV !== 'undefined' && OV.EmbeddedViewer) { i++; next(); return; }
            if (i === 1 && typeof EG3DTools !== 'undefined') { i++; next(); return; }
            var sc = document.createElement('script');
            sc.src = LIBS[i];
            sc.onload = function () { i++; next(); };
            sc.onerror = function () { i = LIBS.length; next(); };
            document.head.appendChild(sc);
        })();
    }

    var VW = null;          /* OV.EmbeddedViewer */
    var VW_HOST = null;
    var VW_OBJ = null;      /* 目前掛在檢視器上的 three.js 物件（換掉前要 dispose）*/
    var FITTED = false;     /* 只有第一次才把模型擺滿畫面，之後不再動相機 */
    var BUILDING = false;

    function ensureViewer(hostEl) {
        if (VW && VW_HOST === hostEl) return VW;
        if (typeof OV === 'undefined' || !OV.EmbeddedViewer) return null;
        hostEl.innerHTML = '';
        VW = new OV.EmbeddedViewer(hostEl, {
            backgroundColor: new OV.RGBAColor(247, 243, 238, 255),
            defaultColor:    new OV.RGBColor(180, 180, 185),
            edgeSettings:    new OV.EdgeSettings(false, new OV.RGBColor(0, 0, 0), 1)
        });
        VW_HOST = hostEl;
        FITTED = false;
        /* 容器剛顯示時排版可能還沒穩定，量到 0x0 的畫布會永遠是空白
           （bom_viewer.php／master_data_management.php 都實測踩過這個坑）。 */
        setTimeout(function () { if (VW && VW.Resize) VW.Resize(); }, 150);
        return VW;
    }

    /* 把模型送進檢視器。
       相機：只有第一次（或使用者按「重設視角」）才 Fit，否則每拉一次拉桿視角就被
       轉回預設角度——EmbeddedViewer 載入檔案時會做的那一步，這裡刻意不做。 */
    function pushToViewer(model, hostEl, refit, done) {
        var ev = ensureViewer(hostEl);
        if (!ev) { if (done) done(false); return; }
        BUILDING = true;
        OV.ConvertModelToThreeObject(model, new OV.ModelToThreeConversionParams(),
            new OV.ModelToThreeConversionOutput(), {
            onModelLoaded: function (obj) {
                var viewer = ev.GetViewer();
                var old = VW_OBJ;
                viewer.SetMainObject(obj);
                VW_OBJ = obj;
                if (old && OV.DisposeThreeObjects) {
                    try { OV.DisposeThreeObjects(old); } catch (e) {}
                }
                var sphere = viewer.GetBoundingSphere(function () { return true; });
                if (sphere) viewer.AdjustClippingPlanesToSphere(sphere);
                if (!FITTED || refit) {
                    viewer.SetUpVector(OV.Direction.Y, false);
                    if (sphere) viewer.FitSphereToWindow(sphere, false);
                    /* 預設擺成前視圖：相機 [0,0,1]、up [0,1,0]，看到的是 X 水平
                       （工件軸）、Y 垂直（砂輪/刀具在上方），與齒輪計算工具的 2D 出尾
                       剖面圖同一個方向，使用者一打開就對得起來，要轉再自己轉。 */
                    if (T3) T3.setStandardView(ev, 'front');
                    FITTED = true;
                }
                viewer.Render();
                BUILDING = false;
                if (done) done(true);
            },
            onTextureLoaded: function () { ev.GetViewer().Render(); }
        });
    }

    /* ══════════════════════════════════════════════════════════════════════
       十一、畫面
       刻意用 JS 生成整個面板，而不是在 PHP 裡寫一份 HTML：這樣「浮動視窗」與
       「嵌在頁面裡」兩種用法共用同一份版面，不會變成兩份各自演進（鐵律4）。
       ══════════════════════════════════════════════════════════════════════ */
    var NUMF = [
        ['Dw',   '砂輪/刀具外徑',        'mm', '砂輪/刀具的外徑，出尾長度主要由它決定（越大出尾越長）'],
        ['Tw',   '砂輪/刀具刃寬',        'mm', '外徑處的厚度（修整後的刃口寬度）'],
        ['pa',   '砂輪/刀具側面角',      '°',  '單側角度。0＝兩側平行的圓盤砂輪/刀具'],
        ['Hf',   '刃高',            'mm', '側面角從外徑往內「作用到多深」（徑向深度）。'
                                         + '外徑往內這一段厚度會依側面角逐漸變寬，超過刃高之後就維持本體厚度不再變寬；'
                                         + '側面角為 0 時這一欄不影響結果'],
        ['tilt', '砂輪/刀具傾角',        '°',  '砂輪/刀具軸相對工件軸的傾斜（磨螺旋齒時的擺角）。這一欄不為 0 時，2D 公式就不再成立'],
        ['Dr',   '工件齒底徑',      'mm', '加工完成後的齒根圓直徑'],
        ['Lsp',  '齒段長度',        'mm', '齒（花鍵）段由齒底徑邊緣往外延伸多長'],
        ['Ds',   '肩部直徑',        'mm', '齒段旁邊那一段大徑，砂輪/刀具不可以碰到它'],
        ['Lsh',  '肩部長度',        'mm', '肩部往軸向延伸多長（只影響畫面，不影響出尾長度）'],
        ['U',    '退刀量',          'mm', '另外加在幾何出尾長度上的安全量']
    ];

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function panelHtml() {
        var h = ['<div class="g3d-root">'];
        /* data-eg-form／data-eg-submit：讓共用檔 eg_input_rules.js（有載入的頁面）
           知道這一塊是一個表單容器，Enter 才會在這些欄位之間跳、最後一欄按 Enter
           等於按「移到不干涉的極限位置」。沒載入共用檔的頁面（批圖編輯器、訂單
           追蹤都在 input_rules_baseline.txt 基準線內）由下面的 bindInputRules()
           備援接手，**只綁在本面板上**，不會去動宿主頁面既有的 Enter 行為。 */
        h.push('<div class="g3d-left" data-eg-form data-eg-submit="#g3dToLimit">');

        h.push('<div class="g3d-sec"><h4>砂輪/刀具</h4>'
            + '<div class="g3d-row"><label>預設</label>'
            + '<select class="g3d-ni" id="g3dPreset" style="text-align:left">'
            + '<option value="">（載入中…）</option></select>'
            + '<span class="g3d-unit"></span></div>'
            + '<div class="g3d-row" style="gap:5px">'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dPresetSave" '
            +   'style="flex:1 1 0">存成預設</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dPresetMng" '
            +   'style="flex:1 1 0">管理預設</button></div>'
            + '<div class="g3d-hint" id="g3dPresetHint">選一個預設會把砂輪/刀具外徑／刃寬／側面角／'
            + '刃高／傾角與退刀量一次帶入。</div>');
        NUMF.slice(0, 5).forEach(function (f) { h.push(fieldHtml(f)); });
        h.push('</div>');

        h.push('<div class="g3d-sec"><h4>工件</h4>');
        NUMF.slice(5, 9).forEach(function (f) { h.push(fieldHtml(f)); });
        h.push('</div>');

        h.push('<div class="g3d-sec"><h4>其他</h4>');
        h.push(fieldHtml(NUMF[9]));
        h.push('</div>');

        /* ── 治具：多段直徑（使用者 2026-10-12 拍板的輸入方式）──────────────
           第 1 段＝最靠近肩部的那一段，往外依序接。留白的列會被忽略，不必刪。 */
        h.push('<div class="g3d-sec"><h4>治具'
            + '<span style="font-weight:400;font-size:11px;color:#8a7560">'
            + '（第 1 段最靠近肩部，往外依序）</span></h4>'
            + '<table class="g3d-fx"><thead><tr>'
            + '<th style="width:26px">段</th><th>直徑 mm</th><th>長度 mm</th><th style="width:26px"></th>'
            + '</tr></thead>'
            /* 可增列表格鐵則（CLAUDE.md UI 規則）：不自刻鍵盤增刪列，掛屬性交給
               共用檔 eg_input_rules.js——末列按 ↓ 自動加一列、沒填東西的末列按 ↑
               自動移除，↑↓ 也會在同欄上下換列。沒載共用檔的頁面由本檔的
               bindInputRules() 備援接手（兩邊共用同一支 g3dFxRowAdd/Del）。 */
            + '<tbody id="g3dFxBody" data-eg-row-add="g3dFxRowAdd" '
            +   'data-eg-row-del="g3dFxRowDel"></tbody></table>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dFxAdd" '
            + 'style="width:100%;margin-top:5px">＋ 新增一段</button>'
            + '<div class="g3d-hint">沒填就只算肩部干涉。砂輪/刀具夠大時，先撞到的常常不是'
            + '肩部而是治具，結果區會指名是哪一段在卡。<br>'
            + '最後一列按 <b>↓</b> 自動加一段；整列都沒填時按 <b>↑</b> 收回去；'
            + '<b>↑↓</b> 也可以在同一欄上下換列。</div>'
            + '</div>');

        h.push('<div class="g3d-badhint" id="g3dErr"></div>');

        h.push('<div class="g3d-sec"><h4>砂輪/刀具位置</h4>'
            + '<div class="g3d-slider"><label>軸向位置</label>'
            + '<input type="range" id="g3dCx"><span id="g3dCxV">—</span></div>'
            + '<div class="g3d-hint">砂輪/刀具中心到齒底徑邊緣（肩部端面）的軸向距離。'
            + '預設停在<b>退刀位置</b>＝齒底徑邊緣往外退一個退刀量，'
            + '<b>位置只由退刀量決定，與治具無關</b>；治具只決定「停在這裡會不會撞到」。'
            + '往左拖會壓進肩部或治具，壓到的部分在 3D 上以紅色標示。</div>'
            + '<button type="button" class="g3d-btn g3d-btn-main" id="g3dToWork" '
            + 'style="width:100%;margin-bottom:5px">回到退刀位置</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dToLimit" '
            + 'style="width:100%">移到不干涉的極限位置</button>'
            + '</div>');

        h.push('<div class="g3d-sec"><h4>顯示</h4>'
            + '<div class="g3d-row"><label style="flex:1 1 auto">'
            + '<input type="checkbox" id="g3dTrans" checked> 工件半透明</label></div>'
            + '<div class="g3d-hint">干涉時砂輪/刀具是埋在肩部或治具裡面的，不透明就看不到紅色'
            + '區域，所以工件與治具預設半透明。要看實體外觀再取消勾選。</div>'
            + '<div class="g3d-row"><label style="flex:1 1 auto">'
            + '<input type="checkbox" id="g3dDimOn" checked> 標註尺寸</label></div>'
            + '<div class="g3d-hint">直接把<b>退刀量</b>與<b>離最先碰到那一塊還有多遠</b>'
            + '標在 3D 上（兩條線的顏色與線型不同），轉動視角時標註會跟著模型走。</div>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dRefit" '
            + 'style="width:100%">重設視角</button>'
            + '</div>');

        h.push('</div>');  /* /g3d-left */

        h.push('<div class="g3d-right">');
        /* 載入提示刻意放在 3D 容器「外面」當同層覆蓋元素：ensureViewer() 會對容器
           下 innerHTML=''（建立畫布前要清空），放裡面會連提示一起被清掉，之後想顯示
           「3D 檢視器載入失敗」也沒有元素可用（2026-10-12 實測踩到）。 */
        h.push('<div class="g3d-canvas-wrap">'
            + '<div class="g3d-canvas" id="g3dCanvas"></div>'
            + '<svg class="g3d-dim" id="g3dDimSvg"></svg>'
            + '<div class="g3d-canvas-msg" id="g3dCanvasMsg">正在建立 3D 模型…</div></div>');

        h.push('<div class="g3d-tools">'
            + '<span style="font-size:11px;color:#8a7560">視角</span>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="iso">等角</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="front">前</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="top">上</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="right">右</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dFocusHit" '
            +   'title="把畫面拉近到會撞到的那一點">對準接觸點</button>'
            + '<span class="g3d-sep"></span>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dSecBtn">剖面</button>'
            + '<span id="g3dSecBox" style="display:none;align-items:center;gap:4px">'
            +   '<button type="button" class="g3d-btn g3d-btn-o g3d-on" data-g3dsec="x">X</button>'
            +   '<button type="button" class="g3d-btn g3d-btn-o" data-g3dsec="y">Y</button>'
            +   '<button type="button" class="g3d-btn g3d-btn-o" data-g3dsec="z">Z</button>'
            +   '<input type="range" id="g3dSecPos" min="0" max="1" step="0.005" value="0.5" '
            +     'style="width:120px;accent-color:#C77A30">'
            +   '<button type="button" class="g3d-btn g3d-btn-o" id="g3dSecFlip">翻面</button>'
            + '</span>'
            + '<span class="g3d-sep"></span>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dMeasBtn">量測</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dShot">截圖</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dPrint">列印</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3d2dBtn">2D工程圖</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" id="g3dKvSet">結果欄位</button>'
            + '<span id="g3dMeasOut" style="font-size:11px;color:#8a5a22"></span>'
            + '</div>');

        h.push('<div class="g3d-result">'
            + '<div class="g3d-verdict g3d-v-na" id="g3dVerdict">計算中…</div>'
            + '<div class="g3d-kv" id="g3dKv"></div></div>');
        h.push('</div></div>');
        return h.join('');
    }

    function fieldHtml(f) {
        return '<div class="g3d-row"><label>' + esc(f[1]) + '</label>'
             + '<input type="number" class="g3d-ni" id="g3d_' + f[0] + '" step="any" '
             + 'data-g3dk="' + f[0] + '" data-eg-hint="' + esc(f[3]) + '">'
             + '<span class="g3d-unit">' + esc(f[2]) + '</span></div>';
    }

    /* ══ 十二、狀態與事件 ══════════════════════════════════════════════════ */
    var ST = {
        root:null, host:null, params:null, res:null, cx:0,
        sec:null, secOpts:{ axis:'x', ratio:0.5, flip:false },
        meas:null, measOn:false, timer:null
    };

    function $1(sel) { return ST.root ? ST.root.querySelector(sel) : null; }
    /* 砂輪/刀具預設的管理跳窗掛在面板**外面**（與浮動視窗同層），$1() 查不到它。
       用這一支以 document 為範圍找，兩者混用過一次就會變成「按了沒反應」。 */
    function $d(id) { return document.getElementById(id); }

    function readParams() {
        var o = {};
        NUMF.forEach(function (f) {
            var el = $1('#g3d_' + f[0]);
            o[f[0]] = el ? el.value : DEF[f[0]];
        });
        o.fx = readFx();
        return o;
    }

    function setParams(o) {
        NUMF.forEach(function (f) {
            var el = $1('#g3d_' + f[0]);
            if (el) el.value = (o && o[f[0]] !== undefined) ? o[f[0]] : DEF[f[0]];
        });
        renderFx((o && Array.isArray(o.fx)) ? o.fx : []);
    }

    /* ── 治具段落表 ──────────────────────────────────────────────────────
       刻意留著「全空的列」不自動刪：使用者可能只是還沒填完，列一消失游標就跑掉。
       空列在 norm() 會被略過，不影響計算。 */
    function readFx() {
        var out = [], tb = $1('#g3dFxBody');
        if (!tb) return out;
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr) {
            var d = tr.querySelector('[data-fxk=d]'), L = tr.querySelector('[data-fxk=len]');
            out.push({ d: d ? d.value : '', len: L ? L.value : '' });
        });
        return out;
    }
    function renderFx(rows) {
        var tb = $1('#g3dFxBody');
        if (!tb) return;
        if (!rows.length) rows = [{ d: '', len: '' }];
        tb.innerHTML = rows.map(function (r, i) {
            return '<tr><td class="g3d-fx-n">' + (i + 1) + '</td>'
                 + '<td><input type="number" class="g3d-ni" step="any" data-fxk="d" value="'
                 + esc(r.d === undefined || r.d === null ? '' : r.d) + '"></td>'
                 + '<td><input type="number" class="g3d-ni" step="any" data-fxk="len" value="'
                 + esc(r.len === undefined || r.len === null ? '' : r.len) + '"></td>'
                 + '<td><button type="button" class="g3d-fx-del" title="刪除這一段">×</button></td></tr>';
        }).join('');
    }
    /* ── 給共用檔 eg_input_rules.js 呼叫的增／刪列（一律不帶參數）────────────
       合約（見該檔檔頭）：add＝在最後面多一列並重繪；del＝移除最後一列並重繪，
       **只剩 1 列時函式自己要擋掉**。兩支都掛在 window 上，因為共用檔是用
       window[函式名] 去找的。 */
    window.g3dFxRowAdd = function () {
        var rows = readFx();
        rows.push({ d: '', len: '' });
        renderFx(rows);
    };
    window.g3dFxRowDel = function () {
        var tb = $1('#g3dFxBody');
        if (!tb) return;
        var rows = readFx();
        if (rows.length <= 1) return;        /* 只剩一列不刪（合約要求自己擋） */
        rows.pop();
        renderFx(rows);
        recompute(false);                    /* 少一段治具，結果要跟著重算 */
    };
    window.g3dPresetRowAdd = function () {
        var tb = $d('g3dPresetBody');
        if (!tb) return;
        var rows = presetReadRowsRaw();
        rows.push({ name: '' });
        presetRenderRows(rows);
    };
    window.g3dPresetRowDel = function () {
        var tb = $d('g3dPresetBody');
        if (!tb) return;
        var rows = presetReadRowsRaw();
        if (rows.length <= 1) return;
        rows.pop();
        presetRenderRows(rows);
    };

    function fxRenumber() {
        var tb = $1('#g3dFxBody');
        if (!tb) return;
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr, i) {
            var c = tr.querySelector('.g3d-fx-n');
            if (c) c.textContent = String(i + 1);
        });
    }

    /* 重新分析（改參數時呼叫）：重算極限位置，並把砂輪/刀具移到極限位置 */
    function recompute(keepCx) {
        var res = analyze(readParams());
        ST.res = res;
        var err = $1('#g3dErr');
        NUMF.forEach(function (f) {
            var el = $1('#g3d_' + f[0]);
            if (el) el.classList.remove('g3d-err');
        });
        if (!res.ok) {
            if (err) { err.textContent = res.err; err.style.display = 'block'; }
            var v = $1('#g3dVerdict');
            if (v) { v.className = 'g3d-verdict g3d-v-na'; v.textContent = '無法計算'; }
            var kv = $1('#g3dKv'); if (kv) kv.innerHTML = '';
            return;
        }
        /* 提醒不是錯誤：用灰字說明「為什麼是這個結果」，不擋下計算、不把欄位標紅。
           （2026-10-12 使用者回報原本會被「肩部一定要大於齒底徑」卡住算不了。） */
        if (err) {
            if (res.notes && res.notes.length) {
                err.innerHTML = res.notes.map(esc).join('<br>');
                err.style.display = 'block';
                err.style.color = '#8a7560';
                err.style.fontWeight = '400';
            } else {
                err.style.display = 'none';
            }
        }

        /* 拉桿範圍：要涵蓋「壓進障礙物」與「完全脫離」兩側，才看得出變化 */
        var p = res.p;
        /* 範圍要**同時涵蓋極限位置與肩部端面(0)**：極限位置可能是負的（砂輪/刀具可以
           越過端面），原本以 0 為基準的算法會把拉桿夾在涵蓋不到極限位置的區間，
           畫面上就變成「拉到底還是干涉」。 */
        var aLo  = Math.min(res.cxLimit, res.cxWork), aHi = Math.max(res.cxLimit, res.cxWork);
        var half = Math.max(Math.abs(aLo) * 0.6, Math.abs(aHi) * 0.6, p.Rw * 0.35, p.Tb * 2, 10);
        var lo = Math.min(aLo - half, -(p.Tb / 2 + 2));
        var hi = Math.max(aHi + half, p.Rw * 0.4);
        var sl = $1('#g3dCx');
        if (sl) {
            sl.min = lo.toFixed(4); sl.max = hi.toFixed(4);
            sl.step = ((hi - lo) / 600).toFixed(5);
            /* 預設停在「退刀位置」而不是治具決定的極限位置——那才是製程上實際
               會停的地方；極限位置只用來判斷「停在這裡撞不撞得到」。 */
            if (!keepCx || ST.cx < lo || ST.cx > hi) ST.cx = res.cxWork;
            sl.value = ST.cx;
        }
        draw(false);
        /* 2D 工程圖視窗若正開著，數字一改就跟著重畫——否則使用者看到的是
           改之前的舊圖，會以為存檔的圖跟畫面不一致。 */
        var box2d = $d('g3d-2d-box');
        if (box2d && box2d.classList.contains('g3d-show')) profile2DOpen();
    }

    /* 重畫（改砂輪/刀具位置時呼叫，不重算極限位置）。
       數字先更新、不等函式庫——結論與出尾長度是純計算，沒有 3D 也該立刻看得到；
       3D 那一半等 ensureLibs 回來再補上。_drawSeq 是為了丟掉「等函式庫期間使用者
       又拖了好幾次拉桿」累積下來的過期回呼，只畫最後一次。 */
    var _drawSeq = 0;
    function draw(refit) {
        var res = ST.res;
        if (!res || !res.ok) return;
        showResult();

        var msg = $1('#g3dCanvasMsg');
        if (msg && _libState < 2) {
            msg.style.display = 'flex';
            msg.textContent = '正在載入 3D 元件…';
        }
        var seq = ++_drawSeq;
        ensureLibs(function (libOk) {
            if (seq !== _drawSeq) return;          /* 已經有更新的一次重畫了 */
            if (!libOk) {
                if (msg) { msg.style.display = 'flex';
                           msg.textContent = '3D 元件載入失敗，計算結果仍然可用'; }
                return;
            }
            var tr = $1('#g3dTrans');
            var model = buildModel(res, ST.cx, (tr && tr.checked) ? 0.42 : 1);
            pushToViewer(model, $1('#g3dCanvas'), refit, function (ok) {
                if (msg) msg.style.display = ok ? 'none' : 'flex';
                if (!ok && msg) msg.textContent = '3D 檢視器建立失敗';
                /* 剖面開著時模型換掉後材質是全新的、裁切面會不見，要重新套一次 */
                if (ok && ST.sec) applySection();
                if (ok) { DIM.sig = null; drawDims(); dimSchedule(); }
            });
        });
    }

    function showResult() {
        var res = ST.res, p = res.p;
        var a = at(res, ST.cx);
        var sl = $1('#g3dCxV');
        if (sl) sl.textContent = fx(ST.cx, 2);
        /* 先卡住的是哪一塊：砂輪/刀具夠大時常常是治具某一段，不是肩部。結論列一定要
           指名道姓，不然使用者會以為只要把肩部改小就解決得了。 */
        var bindName = res.bind ? res.bind.name : '';
        var v = $1('#g3dVerdict');
        if (v) {
            if (!res.blocked) {
                /* 肩部比齒底徑小、治具也都碰不到＝沒有任何東西擋著。這是合法結果，
                   要說「沒有限制」，不是報錯也不是說「不干涉還能再靠近 0mm」。 */
                v.className = 'g3d-verdict g3d-v-ok';
                v.innerHTML = '<i class="fa fa-check-circle"></i> 沒有任何一塊會擋到砂輪/刀具'
                            + '<span style="font-weight:400">'
                            + '（肩部與治具的直徑都不大於齒底徑，砂輪/刀具碰不到）</span>';
            } else if (a.hit) {
                v.className = 'g3d-verdict g3d-v-bad';
                v.innerHTML = '<i class="fa fa-times-circle"></i> 會干涉：砂輪/刀具已經壓進<b>'
                            + esc(bindName) + '</b>，必須往外退 ' + fx(a.pen) + ' mm';
            } else if (a.touch) {
                /* 剛好站在極限位置：既不是「不干涉還有餘裕」也不是「已經撞到」，
                   講成「還可以再靠近 0.000 mm」沒有人看得懂（使用者原話）。 */
                v.className = 'g3d-verdict g3d-v-edge';
                v.innerHTML = '<i class="fa fa-exclamation-circle"></i> 剛好貼到<b>'
                            + esc(bindName) + '</b>：這就是極限位置，<b>再靠近就會撞到</b>'
                            + '<span style="font-weight:400">（還沒有干涉，間隙 0）</span>';
            } else {
                v.className = 'g3d-verdict g3d-v-ok';
                v.innerHTML = '<i class="fa fa-check-circle"></i> 不干涉：離<b>' + esc(bindName)
                            + '</b>還有 ' + fx(a.clr) + ' mm 才會碰撞';
            }
        }
        var d2 = res.diff, shoulderBind = res.bind && res.bind.kind === 'shoulder';
        var rows = [
            ['cx', '砂輪/刀具中心軸向位置<span class="g3d-k-sub">（目前拉桿停的位置）</span>',
                                                      fx(ST.cx) + ' mm'],
            ['work', '<b>退刀位置</b><span class="g3d-k-sub">（齒底徑邊緣往外退 ' + fx(p.U, 2)
                + ' mm＝砂輪/刀具實際會停的位置，與治具無關）</span>',
                                                      '<b>' + fx(res.cxWork) + ' mm</b>'],
            ['margin', '退刀位置還差多少才會撞到' + (res.blocked
                 ? '<span class="g3d-k-sub">（' + esc(bindName) + '）</span>'
                 : '<span class="g3d-k-sub">（沒有東西擋著）</span>'),
                                                      res.blocked
                                                        ? gapTxt('軸向', res.workMargin) + ' mm'
                                                        : '<span class="g3d-k-sub">不會撞到</span>'],
            ['limit', '不干涉的極限位置' + (res.blocked
                 ? '<span class="g3d-k-sub">（由' + esc(bindName) + '決定）</span>'
                 : '<span class="g3d-k-sub">（沒有東西擋著）</span>'),
                                                      fx(res.cxLimit) + ' mm'],
            ['tail', '<b>出尾長度</b><span class="g3d-k-sub">（本工具，含傾角 '
              + fx(p.tilt, 1) + '°' + (shoulderBind ? '' : '，含治具') + '）'
              + (res.overrun > 0 ? '<br>砂輪/刀具還可以越過肩部端面 ' + fx(res.overrun)
                   + ' mm，這一側沒有出尾問題' : '') + '</span>',
                                                      '<b>' + fx(res.tail) + ' mm</b>'],
            ['tailu', '出尾長度＋退刀量 ' + fx(p.U, 2) + ' mm', fx(res.tailTot) + ' mm'],
            ['d2d', '2D 公式值<span class="g3d-k-sub">（齒輪計算工具的出尾計算，只算肩部、只在傾角 0 成立'
                + (res.tail2d === null ? '；這個工件沒有肩部，所以不適用' : '') + '）</span>',
                                                      res.tail2d === null ? '—' : fx(res.tail2d) + ' mm'],
            ['d2ddiff', '2D 公式低估／高估了' + (res.tail2d === null ? '' : (shoulderBind ? ''
                : '<span class="g3d-k-sub">（這次卡住的不是肩部，2D 公式根本沒有算它）</span>')),
                                                      d2 === null ? '—'
                                                        : ((d2 >= 0 ? '+' : '') + fx(d2) + ' mm')],
            ['cy', '砂輪/刀具中心高度<span class="g3d-k-sub">（距工件軸心）</span>', fx(res.cy) + ' mm'],
            /* H 是負的時候「肩部高出齒底徑 -14」很難讀，換句話說比較清楚 */
            ['hh', p.H >= 0 ? '肩部高出齒底徑'
                      : '肩部<span class="g3d-k-sub">比齒底徑小（這一側沒有肩部）</span>',
                                                      fx(Math.abs(p.H)) + ' mm'],
            ['tb', '砂輪/刀具本體厚度<span class="g3d-k-sub">（含側面角張開）</span>', fx(p.Tb) + ' mm']
        ];

        /* 逐一列出每一塊障礙物：軸向餘裕（還能再靠近多少）與徑向間隙（這一塊還能
           再長高多少才會碰到＝截圖上那個 10）。兩個數字由同一支解析解推出來，
           保證同時變 0，不會出現「說有間隙卻判干涉」。 */
        p.obs.forEach(function (o) {
            var reachable = isFinite(o.cxLimit);
            var am = ST.cx - o.cxLimit;
            var rg = radialGap(res, o, ST.cx);
            var tag = (o === res.bind) ? '　← 最先碰到'
                    : (!reachable ? '　（直徑不大於齒底徑，碰不到）' : '');
            rows.push([
                'obs',
                '<b>' + esc(o.name) + '</b><span class="g3d-k-sub"> Ø' + fx(o.R * 2, 1)
                    + '　軸向 ' + fx(o.x0, 1) + '～' + fx(o.x1, 1) + esc(tag) + '</span>',
                (!reachable ? '<span class="g3d-k-sub">軸向 不會擋到</span>' : gapTxt('軸向', am))
                + '　/　' + gapTxt('徑向', rg) + ' mm'
            ]);
        });
        var kv = $1('#g3dKv');
        if (kv) {
            /* 這裡刻意不過 esc()：rows 的兩欄都是本檔自己組出來的靜態說明與
               fx() 產生的數字，沒有任何使用者輸入會流進來，而說明欄要帶 <b>
               與 <span class="g3d-k-sub"> 這些標籤。 */
            kv.innerHTML = rows.filter(function (r) { return kvShow(r[0]); })
                .map(function (r) {
                    return '<div>' + r[1] + '</div><div>' + r[2] + '</div>';
                }).join('');
        }
    }

    /* 把相機對準接觸點。o3dv 沒有「看向某一點」的公開 API，但 FitSphereToWindow
       吃的就是一顆球，所以**借現成包圍球的建構子**自己造一顆小球丟進去
       （與 eg_3d_viewer_tools.js 既有的「借建構子」同一招，o3dv 沒有把 THREE
       掛在全域）。 */
    /* ══ 十一之一、計算結果要顯示哪幾列（管理員可設定，全廠共用）══════════
       2026-10-13 使用者要求。**存的是「要隱藏的」**，所以之後新增的結果列
       預設都看得到，不會被舊設定吃掉（見 grind3d_tool_lib.php 的說明）。
       畫面與列印用同一份判定，不會出現「畫面有、印出來沒有」。 */
    var KV = { hidden: {}, canEdit: false, loaded: false };
    var KV_LABEL = {
        cx: '砂輪/刀具中心軸向位置', work: '退刀位置', margin: '退刀位置還差多少才會撞到',
        limit: '不干涉的極限位置', tail: '出尾長度', tailu: '出尾長度＋退刀量',
        d2d: '2D 公式值', d2ddiff: '2D 公式低估／高估了', cy: '砂輪/刀具中心高度',
        hh: '肩部高出齒底徑', tb: '砂輪/刀具本體厚度', obs: '逐塊障礙物的軸向餘裕與徑向間隙'
    };
    var KV_ORDER = ['cx', 'work', 'margin', 'limit', 'tail', 'tailu',
                    'd2d', 'd2ddiff', 'cy', 'hh', 'tb', 'obs'];

    function kvShow(k) { return !KV.hidden[k]; }

    function kvLoad() {
        presetApi('kv_get', null, function (j) {
            KV.hidden = {};
            if (j && j.success && Array.isArray(j.hidden)) {
                j.hidden.forEach(function (k) { KV.hidden[k] = 1; });
            }
            KV.canEdit = !!(j && j.can_edit);
            KV.loaded = true;
            var b = $1('#g3dKvSet');
            if (b) {
                b.disabled = !KV.canEdit;
                b.title = KV.canEdit ? '' : '只有系統管理員可以設定要顯示哪幾列';
            }
            if (ST.res && ST.res.ok) showResult();
        });
    }

    function kvOpenSet() {
        if (!KV.canEdit) return;
        var box = $d('g3dKvMask'), body = $d('g3dKvBody');
        if (!box || !body) return;
        body.innerHTML = KV_ORDER.map(function (k) {
            return '<label class="g3d-kvchk"><input type="checkbox" data-kvk="' + k + '"'
                 + (kvShow(k) ? ' checked' : '') + '> ' + esc(KV_LABEL[k] || k) + '</label>';
        }).join('');
        var st = $d('g3dKvStatus'); if (st) st.textContent = '';
        box.classList.add('g3d-show');
    }

    function kvSaveSet() {
        var body = $d('g3dKvBody'), st = $d('g3dKvStatus');
        if (!body) return;
        var hide = [];
        Array.prototype.forEach.call(body.querySelectorAll('[data-kvk]'), function (c) {
            if (!c.checked) hide.push(c.getAttribute('data-kvk'));
        });
        if (hide.length >= KV_ORDER.length) {
            if (st) { st.textContent = '至少要留一列'; st.style.color = '#DD5138'; }
            return;
        }
        if (st) { st.textContent = '儲存中…'; st.style.color = '#8a7560'; }
        presetApi('kv_save', { hidden: JSON.stringify(hide) }, function (j) {
            if (j && j.success) {
                KV.hidden = {};
                (j.hidden || []).forEach(function (k) { KV.hidden[k] = 1; });
                if (ST.res && ST.res.ok) showResult();
                if (st) { st.textContent = '已儲存'; st.style.color = '#2e7d32'; }
            } else if (st) {
                st.textContent = (j && j.message) || '儲存失敗'; st.style.color = '#DD5138';
            }
        });
    }

    /* ══ 十一之二、3D 尺寸標註（2026-10-13 使用者要求）════════════════════
       把「退刀量」與「離最先碰到那一塊還有多遠」直接標在 3D 上，像工程圖
       那樣有尺寸線、箭頭與數字，不必低頭去看下面的結果表。

       **刻意畫成疊在畫布上的 SVG，不是 3D 物件**，兩個理由：
         ⑴ 量測工具每量一次就 `viewer.ClearExtra()`，用 AddExtraObject 放的
            標註會被它整批清掉（而且那是別人的功能，不該為了標註去改它）
         ⑵ 3D 幾何畫不出清楚的文字；疊層文字永遠正面朝人、也不會被模型擋住
       座標是把世界座標投影成螢幕座標畫的，所以**轉動視角時標註跟著模型走**，
       看起來就像長在模型上。

       座標系（見檔頭）：工件軸＝世界 X，半徑在 Y/Z，砂輪/刀具在 +Y。
       齒底徑邊緣在 x=0；砂輪/刀具中心 (cx, cy, 0)，最低點恰好是 (cx, Rr, 0)。 */
    var DIM = { raf: 0, sig: null };

    function dimOn() { var c = $1('#g3dDimOn'); return !c || c.checked; }

    /* 世界座標 → 畫布內的螢幕座標。o3dv 沒把 THREE 掛在全域，
       所以 Vector3 一律**借現成實例的建構子**（與本檔其他地方同一招）。 */
    function dimProject(cam, w, h, x, y, z) {
        var V3 = cam.position.constructor;
        var v = new V3(x, y, z);
        v.project(cam);
        return { x: (v.x * 0.5 + 0.5) * w, y: (-v.y * 0.5 + 0.5) * h, vis: v.z < 1 };
    }

    function dimArrow(parts, tx, ty, ux, uy, color) {
        var L = 9, W = 3.2;
        var bx = tx + ux * L, by = ty + uy * L, px = -uy, py = ux;
        parts.push('<polygon points="' + tx.toFixed(1) + ',' + ty.toFixed(1) + ' '
            + (bx + px * W).toFixed(1) + ',' + (by + py * W).toFixed(1) + ' '
            + (bx - px * W).toFixed(1) + ',' + (by - py * W).toFixed(1)
            + '" fill="' + color + '"/>');
    }

    /* a,b＝這段距離的**兩個真實端點**（已投影成螢幕座標）。
       2026-10-13 使用者回報：間隙要標在「會碰到的那兩點」之間，不是另外拉一條
       基準線標在旁邊——所以這裡不再畫延伸線，改在兩端各畫一條垂直短線當界線，
       尺寸線本身就落在那兩點上。 */
    function dimLine(parts, a, b, color, dash, label, labelDy) {
        var _dx = b.x - a.x, _dy = b.y - a.y, _L = Math.sqrt(_dx * _dx + _dy * _dy) || 1;
        var _px = -_dy / _L * 5, _py = _dx / _L * 5;      /* 端點界線：垂直尺寸線 */
        [a, b].forEach(function (q) {
            parts.push('<line x1="' + (q.x - _px).toFixed(1) + '" y1="' + (q.y - _py).toFixed(1)
                + '" x2="' + (q.x + _px).toFixed(1) + '" y2="' + (q.y + _py).toFixed(1)
                + '" stroke="' + color + '" stroke-width="1.2" opacity=".85"/>');
        });
        parts.push('<line x1="' + a.x.toFixed(1) + '" y1="' + a.y.toFixed(1)
            + '" x2="' + b.x.toFixed(1) + '" y2="' + b.y.toFixed(1)
            + '" stroke="' + color + '" stroke-width="1.6"'
            + (dash ? ' stroke-dasharray="' + dash + '"' : '') + '/>');
        var dx = b.x - a.x, dy = b.y - a.y, L = Math.sqrt(dx * dx + dy * dy) || 1;
        var ux = dx / L, uy = dy / L;
        /* 標的距離短時（整個模型縮在畫面裡，10mm 可能只有 8px），箭頭塞不進
           兩端之間就會疊成一團；比照工程圖的標準做法改畫在外側朝內指，
           並把尺寸線往兩邊各延長一截，讓它仍然看得出是一段距離。 */
        if (L >= 26) {
            dimArrow(parts, a.x, a.y, ux, uy, color);
            dimArrow(parts, b.x, b.y, -ux, -uy, color);
        } else {
            dimArrow(parts, a.x, a.y, -ux, -uy, color);
            dimArrow(parts, b.x, b.y, ux, uy, color);
            parts.push('<line x1="' + (a.x - ux * 14).toFixed(1) + '" y1="' + (a.y - uy * 14).toFixed(1)
                + '" x2="' + (b.x + ux * 14).toFixed(1) + '" y2="' + (b.y + uy * 14).toFixed(1)
                + '" stroke="' + color + '" stroke-width="1.6"'
                + (dash ? ' stroke-dasharray="' + dash + '"' : '') + '/>');
        }
        var mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
        parts.push('<text x="' + mx.toFixed(1) + '" y="' + (my + (labelDy || -7)).toFixed(1)
            + '" text-anchor="middle" font-size="13" fill="' + color + '">'
            + esc(label) + '</text>');
    }

    function drawDims() {
        var svg = $1('#g3dDimSvg');
        if (!svg) return;
        var res = ST.res;
        if (!res || !res.ok || !VW || !dimOn()) { svg.innerHTML = ''; return; }
        var cam = null;
        try { var v = VW.GetViewer(); cam = v && v.camera; } catch (e) {}
        if (!cam || !cam.position) { svg.innerHTML = ''; return; }
        var wrap = svg.parentNode, cv = wrap ? wrap.querySelector('canvas') : null;
        if (!cv) { svg.innerHTML = ''; return; }
        var r = cv.getBoundingClientRect(), wr = wrap.getBoundingClientRect();
        if (!r.width || !r.height) { svg.innerHTML = ''; return; }
        svg.setAttribute('viewBox', '0 0 ' + wr.width + ' ' + wr.height);
        var ox = r.left - wr.left, oy = r.top - wr.top;
        try { cam.updateMatrixWorld(); } catch (e) {}
        function P(x, y, z) {
            var q = dimProject(cam, r.width, r.height, x, y, z);
            return { x: q.x + ox, y: q.y + oy };
        }

        var p = res.p, parts = [];

        /* ① 退刀量：標在齒底徑表面上，從<齒底徑邊緣>(x=0) 到<退刀位置>(cxWork)。
           砂輪/刀具最低點恰好落在半徑 Rr 上（cy−Rw＝Rr），所以這條線就貼著砂輪/刀具
           與工件接觸的那條母線，與使用者截圖上標的位置一致。 */
        if (p.U > 1e-9) {
            dimLine(parts, P(0, p.Rr, 0), P(res.cxWork, p.Rr, 0),
                    '#C77A30', '', '退刀量 ' + fx(p.U, 2) + ' mm', 16);   /* 文字放線下方 */
        }

        /* ② 離最先碰到的那一塊還有多遠——**標在真的會碰到的那兩點之間**
           （2026-10-13 使用者回報：原本另外拉一條基準線標在工件下方，看不出
           量的是哪兩點）。砂輪/刀具上那個會先撞到的點是 bind.hitLocal，把它分別
           擺在「砂輪/刀具現在的位置」與「極限位置」，兩點連線就是還剩的距離。 */
        if (res.blocked && isFinite(res.cxLimit) && res.bind && res.bind.hitLocal) {
            var m = ST.cx - res.cxLimit, col, txt;
            if (m < -TOUCH_EPS) { col = '#DD5138'; txt = '已壓進 ' + fx(-m, 2) + ' mm'; }
            else if (m <= TOUCH_EPS) { col = '#C77C1A'; txt = '剛好貼到，再靠近就碰撞'; }
            else { col = '#2e7d32'; txt = '還有 ' + fx(m, 2) + ' mm 距離後碰撞'; }
            var hw = placed(res.bind.hitLocal, res.cg, res.sg, ST.cx, res.cy);        /* 砂輪/刀具上那一點 */
            var hf = placed(res.bind.hitLocal, res.cg, res.sg, res.cxLimit, res.cy);  /* 它會撞到的那一點 */
            dimLine(parts, P(hw[0], hw[1], hw[2]), P(hf[0], hf[1], hf[2]),
                    col, '7,4', txt, -9);                                 /* 文字放線上方 */
        }
        svg.innerHTML = parts.join('');
    }

    /* 視角一動就要重畫。o3dv 沒有「相機變了」的事件，所以用 rAF 比對相機矩陣——
       只比 16 個數字，沒有字串配置也沒有版面讀取，成本可以忽略；面板被換掉
       （mount 重畫或關閉頁面）就自己停下來，不會留一個永遠在跑的迴圈。 */
    function dimTick() {
        DIM.raf = 0;
        if (!ST.root || !document.body.contains(ST.root)) { DIM.sig = null; return; }
        var changed = false;
        try {
            var v = VW && VW.GetViewer(), cam = v && v.camera;
            if (cam) {
                var e = cam.matrixWorld.elements, sig = DIM.sig;
                if (!sig || sig.length !== 18) { changed = true; sig = new Array(18); }
                for (var i = 0; i < 16; i++) { if (sig[i] !== e[i]) { changed = true; } sig[i] = e[i]; }
                if (sig[16] !== (cam.zoom || 1)) { changed = true; sig[16] = cam.zoom || 1; }
                if (sig[17] !== ST.cx) { changed = true; sig[17] = ST.cx; }
                DIM.sig = sig;
            }
        } catch (ex) {}
        if (changed) drawDims();
        dimSchedule();
    }
    function dimSchedule() {
        if (DIM.raf) return;
        DIM.raf = requestAnimationFrame(dimTick);
    }

    /* ══ 十一之三、列印（A4 橫式一頁：左邊數據、右邊 3D 畫面）═══════════════
       2026-10-13 使用者要求：「截圖應該要有列印功能，上方要顯示左方列表相關資訊，
       方便快速確認，固定 A4 橫式，跟畫面一樣左側數據、右側 3D」。

       版面依 ai-rules/16：大標題＝本公司全名（即時查主檔、禁寫死）；
       **不印 AS 文件編號**——本工具是計算模擬，不是正式 AS9100 表單
       （與訂單分析／品質異常分析報告同一個判斷）；單頁所以不印頁碼。
       列印紀錄依 ai-rules/23 呼叫 EGPrintLog.record()。

       3D 那一塊＝「截圖 PNG ＋ 原封不動搬過去的尺寸標註 SVG」疊在一起：
       標註是 SVG，直接搬過去列印時是向量的，數字不會糊；兩者座標系一致
       （SVG 的 viewBox 就是畫布框），所以同比例縮放後仍然對得準。 */
    /* 報表外殼：左邊固定是這一組參數與計算結果，右邊由呼叫端決定要放什麼圖
       （3D 截圖或 2D 剖面圖）。兩種列印共用同一份版面與同一份數據，
       不會出現「3D 印出來的數字跟 2D 印出來的不一樣」（鐵律4）。 */
    function printReport(opt) {
        var res = ST.res;
        if (!res || !res.ok) { alert('目前的參數還算不出結果，不能列印。'); return; }
        var p = res.p, rows = [];
        NUMF.forEach(function (f) { rows.push([f[1], fx(p[f[0]], 2) + ' ' + f[2]]); });
        if (p.fx.length) {
            p.fx.forEach(function (g, i) {
                rows.push(['治具第' + (i + 1) + '段', 'Ø' + fx(g.d, 1) + ' × 長 ' + fx(g.len, 1) + ' mm']);
            });
        } else {
            rows.push(['治具', '（未填，只算肩部干涉）']);
        }
        var inTbl = rows.map(function (r) {
            return '<tr><th>' + esc(r[0]) + '</th><td>' + esc(r[1]) + '</td></tr>';
        }).join('');

        var vd = $1('#g3dVerdict'), kv = $1('#g3dKv');
        var vdTxt = vd ? vd.textContent.replace(/\s+/g, ' ').trim() : '';
        var vdCls = vd ? (/g3d-v-bad/.test(vd.className) ? 'bad'
                        : /g3d-v-edge/.test(vd.className) ? 'edge' : 'ok') : 'ok';
        var d = new Date(), z = function (n) { return ('0' + n).slice(-2); };
        /* 使用者要求列印日期要含時間（同一份參數不同時間印出來才分得出哪一張） */
        var dateStr = d.getFullYear() + '.' + z(d.getMonth() + 1) + '.' + z(d.getDate())
                    + ' ' + z(d.getHours()) + ':' + z(d.getMinutes());

        var w = window.open('', '_blank', 'width=1100,height=760');
        if (!w) { alert('瀏覽器擋下了列印視窗，請允許快顯視窗後再試一次。'); return; }
        /* 標題要依實際列印的是 2D 還是 3D 動態換字，不可寫死「3D 模擬報告」
           （2026-10-13 使用者實測回報：印 2D 剖面圖時標題仍顯示 3D 模擬報告）。 */
        var reportTitle = opt.reportTitle || '3D 模擬報告（出尾長度與治具干涉）';
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            + '<title>砂輪/刀具干涉 ' + esc(reportTitle) + '</title><style>'
            + '@page{size:A4 landscape;margin:0;}'
            + 'body{margin:0;font-family:"Microsoft JhengHei",Arial,sans-serif;color:#2b2b2b;}'
            + '.sheet{width:297mm;height:210mm;box-sizing:border-box;padding:9mm 11mm 7mm;'
            +        'display:flex;flex-direction:column;}'
            + '.ttl{display:flex;align-items:flex-end;gap:6mm;border-bottom:2px solid #8a5a22;'
            +      'padding-bottom:3mm;margin-bottom:4mm;}'
            + '.ttl .l{flex:1 1 auto;min-width:0;text-align:left;}'
            + '.ttl h1{margin:0;font-size:16pt;letter-spacing:1px;}'
            + '.ttl h2{margin:1.5mm 0 0;font-size:11.5pt;font-weight:600;color:#8a5a22;}'
            + '.ttl .meta{flex:0 0 auto;font-size:8.5pt;color:#777;text-align:right;white-space:nowrap;}'
            + '.body{display:flex;gap:6mm;flex:1 1 auto;min-height:0;}'
            + '.cl{flex:0 0 96mm;display:flex;flex-direction:column;gap:3mm;min-height:0;}'
            + '.cr{flex:1 1 auto;display:flex;flex-direction:column;min-width:0;}'
            + 'h3{margin:0 0 1.5mm;font-size:9.5pt;color:#8a5a22;border-left:3px solid #C77A30;padding-left:2mm;}'
            + 'table{width:100%;border-collapse:collapse;font-size:8.5pt;}'
            + 'th,td{border:1px solid #d9cbb6;padding:1mm 2mm;line-height:1.35;}'
            + 'th{background:#FBF4EA;text-align:left;font-weight:600;width:38%;}'
            + 'td{text-align:right;font-variant-numeric:tabular-nums;}'
            + '.vd{border-radius:3px;padding:2mm 3mm;font-size:9pt;font-weight:700;}'
            + '.vd.ok{background:#EAF5EA;border:1px solid #2e7d32;color:#1f5d23;}'
            + '.vd.edge{background:#FDF3E2;border:1px solid #C77C1A;color:#8a5a22;}'
            + '.vd.bad{background:#FBEAE6;border:1px solid #DD5138;color:#b03a24;}'
            + '.kv{display:grid;grid-template-columns:1fr auto;font-size:8pt;'
            +     'border:1px solid #d9cbb6;border-radius:3px;overflow:hidden;}'
            + '.kv>div{padding:0.8mm 2mm;border-top:1px solid #efe3d2;line-height:1.35;}'
            + '.kv>div:nth-child(2n){text-align:right;font-variant-numeric:tabular-nums;font-weight:600;}'
            + '.kv>div:nth-child(1),.kv>div:nth-child(2){border-top:none;}'
            + '.kv .g3d-k-sub{color:#8a7560;font-size:7pt;}'
            + '.imgbox{position:relative;flex:1 1 auto;min-height:0;border:1px solid #d9cbb6;'
            +         'border-radius:3px;overflow:hidden;background:transparent;}'
            + '.imgbox img{width:100%;height:100%;object-fit:contain;display:block;}'
            + '.imgbox svg{position:absolute;left:0;top:0;width:100%;height:100%;}'
            + '.imgbox svg text{font-family:"Microsoft JhengHei",sans-serif;font-weight:700;'
            +                  'paint-order:stroke;stroke:#fff;stroke-width:3px;stroke-linejoin:round;}'
            + '.cap{font-size:7.5pt;color:#8a7560;margin-top:1.5mm;line-height:1.4;}'
            + '</style></head><body><div class="sheet">'
            + '<div class="ttl"><div class="l"><h1>' + esc(CORP_NAME || '砂輪/刀具干涉 3D 模擬') + '</h1>'
            +   '<h2>砂輪/刀具干涉 ' + esc(reportTitle) + '</h2></div>'
            +   '<div class="meta">列印時間：' + dateStr + '</div></div>'
            + '<div class="body">'
            +   '<div class="cl">'
            +     '<div><h3>輸入條件</h3><table>' + inTbl + '</table></div>'
            +     '<div class="vd ' + vdCls + '">' + esc(vdTxt) + '</div>'
            +     '<div><h3>計算結果</h3><div class="kv">' + (kv ? kv.innerHTML : '') + '</div></div>'
            +   '</div>'
            +   '<div class="cr"><h3>' + esc(opt.rightTitle) + '</h3>'
            +     '<div class="imgbox">' + opt.rightInner + '</div>'
            +     '<div class="cap">' + esc(opt.caption) + '</div>'
            +   '</div>'
            + '</div></div></body></html>';
        w.document.open(); w.document.write(html); w.document.close();
        if (window.EGPrintLog) {
            EGPrintLog.record({ source: 'grind3d', doc_kind: 'form',
                doc_name: '砂輪/刀具干涉' + opt.docTag + '報告（砂輪/刀具Ø' + fx(p.Dw, 1)
                        + '／齒底徑Ø' + fx(p.Dr, 1) + '）' });
        }
        /* 新視窗 document.write 之後 load 不保證會再觸發，一律自己叫 print（ai-rules/16） */
        setTimeout(function () { try { w.focus(); w.print(); } catch (e) {} }, 400);
    }

    /* 3D：截圖 PNG ＋ 原封不動搬過去的尺寸標註 SVG（標註是向量的，數字不會糊） */
/* 列印右欄那一塊的長寬比（A4 橫式扣掉左欄與邊界後約 160mm×112mm） */
    var PRINT_W = 1600, PRINT_H = 1120;

    function printSheet() {
        if (!ST.res || !ST.res.ok) { alert('目前的參數還算不出結果，不能列印。'); return; }
        if (!VW || !T3) { alert('3D 還在載入，請稍候再列印。'); return; }
        var svg = $1('#g3dDimSvg'), wrap = svg ? svg.parentNode : null;
        var cv = wrap ? wrap.querySelector('canvas') : null;
        if (!cv) { alert('找不到 3D 畫面，請稍候再列印。'); return; }

        /* 截圖前先把模型「填滿畫面」：拉桿改過尺寸之後，畫面可能還停在舊的縮放
           （模型變小了、相機沒跟著拉近），印出來就是一顆小模型飄在一大片空白裡
           （2026-10-13 使用者實測回報）。只為了這一張截圖暫時 FitSphereToWindow
           （保留目前視角方向，只調距離/平移，與第一次載入時同一支函式同一種
           不動旋轉的用法），拍完立刻用存下來的相機還原，不影響使用者畫面上
           原本的縮放狀態。**標註要在換相機之後重畫**，不然截圖跟標註線會是
           兩個不同縮放層級疊在一起、對不準。 */
        var viewer = VW.GetViewer();
        var savedCam = viewer.navigation && viewer.navigation.GetCamera();
        var sphere = savedCam && viewer.GetBoundingSphere(function () { return true; });
        var size0 = viewer.GetCanvasSize();

        /* **擷取前先把檢視器切成列印框的長寬比**：畫面上的畫布又寬又扁（約 3.6:1），
           照原樣擷取放進列印框（約 1.45:1）會被上下留白夾成一條細長的圖，模型只
           佔很小一塊（2026-10-13 使用者回報「圖太小看不清楚」）。切成列印框的
           比例再 FitSphereToWindow，模型就會真的填滿那一塊。
           Resize 與相機都在**同一個 JS 週期內**還原，瀏覽器不會畫出中間狀態。 */
        viewer.Resize(PRINT_W, PRINT_H);
        if (sphere) viewer.FitSphereToWindow(sphere, false);
        viewer.Render();
        drawDims();                                        /* 標註要用新的比例重算 */
        /* 標註是以「畫布座標」算出來的，viewBox 要跟著換成擷取尺寸才對得準 */
        if (svg) svg.setAttribute('viewBox', '0 0 ' + PRINT_W + ' ' + PRINT_H);
        var ovl = (svg && dimOn()) ? svg.outerHTML : '';
        /* 第三個參數 transparent＝不要把檢視器底色烙進圖裡（使用者要求背景透明） */
        var png = viewer.GetImageAsDataUrl(PRINT_W * 2, PRINT_H * 2, true);

        viewer.Resize(size0.width, size0.height);
        if (sphere) viewer.navigation.SetCamera(savedCam);
        viewer.Render();
        drawDims();                                        /* 截完還原，標註重畫一次 */
        printReport({
            reportTitle: '3D 模擬報告（出尾長度與治具干涉）',
            rightTitle: '3D 模擬（與畫面相同視角）',
            rightInner: '<img src="' + png + '">' + ovl,
            caption: '示意模型：工件畫成階段軸（齒底徑圓柱＋肩部＋治具各段），'
                   + '不含實際齒形／齒溝；標註之尺寸由同一組參數解析計算，非量測估值。',
            docTag: '3D模擬'
        });
    }

    function focusContact() {
        if (!VW || !ST.res || !ST.res.ok) return;
        var hp = ST.res.hitWorld;
        if (!hp) { alert('目前沒有任何一塊會擋到砂輪/刀具，沒有接觸點可以對準。'); return; }
        try {
            var viewer = VW.GetViewer();
            var sph = viewer.GetBoundingSphere(function () { return true; });
            if (!sph || !sph.center) return;
            var SphCtor = sph.constructor, VecCtor = sph.center.constructor;
            var rad = Math.max(ST.res.p.Rw * 0.18, ST.res.p.Tb * 3, 8);
            viewer.FitSphereToWindow(new SphCtor(new VecCtor(hp[0], hp[1], hp[2]), rad), false);
            viewer.Render();
        } catch (e) { /* 相機對不準不該讓整個工具壞掉 */ }
    }

    /* 間隙數字的顯示。|值| 在容差內一律當成 0：浮點算出來的 -1e-13 會被 toFixed
       印成「-0.000」還標成紅色，看起來像已經干涉了（2026-10-13 畫面實測看到）。 */
    function gapTxt(label, v) {
        if (Math.abs(v) <= TOUCH_EPS) {
            return '<span style="color:#C77A30">' + label + ' 0.000（剛好貼到）</span>';
        }
        /* 這段 HTML 會被 printReport() 原樣複製進列印視窗，那是全新文件、沒有
           .g3d-root 可定義 --g3d-bad，var() 沒給 fallback 在那邊會變成沒上色。 */
        if (v < 0) return '<span style="color:var(--g3d-bad, #DD5138)">' + label + ' ' + fx(v) + '</span>';
        return label + ' +' + fx(v);
    }

    function applySection() {
        if (!VW || !T3) return;
        if (ST.sec) { try { T3.clearSectionMode(VW, ST.sec); } catch (e) {} }
        ST.sec = T3.setSectionMode(VW, ST.secOpts);
    }

    /* ══ 十二之二、砂輪/刀具尺寸預設 ════════════════════════════════════════════
       2026-10-13 使用者交辦。**存在資料庫（system_parameters）不是 localStorage**：
       砂輪/刀具是現場共用設備，外徑／刃寬／側面角／刃高／傾角／退刀量是全廠共同的事實，
       存瀏覽器會變成每個人每台電腦各自重打一次、改了別人也不知道。
       讀取所有使用者都可以；**新增／修改／刪除限管理員**（比照齒輪計算工具的
       「預留量管理」，那也是全廠共用的對照表）。後端唯一端點 grind3d_api.php。 */
    var PRESET_KEYS = ['Dw', 'Tw', 'pa', 'Hf', 'tilt', 'U'];
    var PRESETS = { rows: [], canEdit: false, loaded: false };

    function presetApi(action, data, cb) {
        var body = 'action=' + encodeURIComponent(action);
        if (data) for (var k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k))
                body += '&' + k + '=' + encodeURIComponent(data[k]);
        }
        fetch(API_URL, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body
        }).then(function (r) { return r.json(); })
          .then(function (j) { cb(j); })
          .catch(function () { cb({ success: false, message: '連線失敗' }); });
    }

    function presetRender() {
        var sel = $1('#g3dPreset');
        if (!sel) return;
        var cur = sel.value;
        var h = ['<option value="">— 不套用預設 —</option>'];
        PRESETS.rows.forEach(function (r, i) {
            h.push('<option value="' + i + '">' + esc(r.name)
                 + (r.Dw ? '（Ø' + fx(r.Dw, 1) + '）' : '') + '</option>');
        });
        sel.innerHTML = h.join('');
        if (cur && sel.querySelector('option[value="' + cur + '"]')) sel.value = cur;
        var mng = $1('#g3dPresetMng'), sav = $1('#g3dPresetSave');
        /* 沒有管理權限時不要把按鈕藏起來——藏起來使用者只會覺得「怎麼沒有這個功能」。
           留著但停用並寫清楚原因（後端同規則再擋一次＝鐵律8）。 */
        [mng, sav].forEach(function (b) {
            if (!b) return;
            b.disabled = !PRESETS.canEdit;
            b.title = PRESETS.canEdit ? '' : '只有系統管理員可以維護砂輪/刀具預設';
        });
        var hint = $1('#g3dPresetHint');
        if (hint && PRESETS.loaded) {
            hint.textContent = PRESETS.rows.length
                ? ('選一個預設會把砂輪/刀具外徑／刃寬／側面角／刃高／傾角與退刀量一次帶入。'
                   + '目前有 ' + PRESETS.rows.length + ' 筆。'
                   + (PRESETS.canEdit ? '' : '（維護請洽系統管理員）'))
                : (PRESETS.canEdit ? '還沒有任何預設。把目前的砂輪/刀具尺寸填好後按「存成預設」。'
                                   : '還沒有任何預設，請洽系統管理員建立。');
        }
    }

    function presetLoad() {
        presetApi('presets_get', null, function (j) {
            PRESETS.rows = (j && j.success && Array.isArray(j.rows)) ? j.rows : [];
            PRESETS.canEdit = !!(j && j.can_edit);
            PRESETS.loaded = true;
            presetRender();
            presetAutoApply();
        });
    }

    /* 載入時自動套用（2026-10-13 使用者交辦）：
         只有一組  → 一律套用（不必有人去指定，不然等於要設定兩次）
         兩組以上  → 套用管理員在「管理預設」裡指定的那一組，沒指定就不套
       **呼叫端有帶砂輪/刀具外徑進來時一律不套**（從齒輪計算工具的出尾計算開進來的
       那條路），否則會把使用者剛在那邊算好的砂輪/刀具尺寸無聲蓋掉。
       整支只在每次 mount 之後跑一次。 */
    function presetAutoPick() {
        if (!PRESETS.rows.length) return -1;
        for (var i = 0; i < PRESETS.rows.length; i++) if (PRESETS.rows[i].auto) return i;
        return PRESETS.rows.length === 1 ? 0 : -1;
    }
    function presetAutoApply() {
        /* 預設是非同步撈回來的：使用者手腳快，有可能在這之前就已經開始改尺寸了，
           這時候套下去等於把他剛打的字無聲蓋掉，一律放棄自動套用。 */
        if (ST.presetAutoDone || ST.hostGaveWheel || ST.userTouched) return;
        ST.presetAutoDone = true;
        var i = presetAutoPick();
        if (i < 0) return;
        var sel = $1('#g3dPreset');
        if (sel) sel.value = String(i);
        presetApply(i);
    }

    function presetApply(i) {
        var r = PRESETS.rows[i];
        if (!r) return;
        PRESET_KEYS.forEach(function (k) {
            var el = $1('#g3d_' + k);
            if (el && r[k] !== undefined && r[k] !== null) el.value = r[k];
        });
        recompute(false);
    }

    /* 管理跳窗：整份清單一次送出（只有幾十筆，整份覆寫不會有「刪到一半失敗」
       的中間狀態，也不必處理 id 與併發） */
    function presetOpenManager() {
        if (!PRESETS.canEdit) return;
        var box = $d('g3dPresetMask');
        if (!box) return;
        var asel = $d('g3dPresetAuto');
        if (asel) asel.removeAttribute('data-want');
        presetRenderRows(PRESETS.rows.slice());
        box.classList.add('g3d-show');
    }
    function presetRenderRows(rows) {
        var tb = $d('g3dPresetBody');
        if (!tb) return;
        if (!rows.length) rows = [{ name: '' }];
        tb.innerHTML = rows.map(function (r) {
            var cells = PRESET_KEYS.map(function (k) {
                return '<td><input type="number" class="g3d-ni" step="any" data-pk="' + k
                     + '" value="' + esc(r[k] === undefined || r[k] === null ? '' : r[k]) + '"></td>';
            }).join('');
            return '<tr><td><input type="text" class="g3d-ni" style="text-align:left" data-pk="name" value="'
                 + esc(r.name || '') + '"></td>' + cells
                 + '<td><button type="button" class="g3d-fx-del" title="刪除這一筆">×</button></td></tr>';
        }).join('');
        presetRenderAutoSel(rows);
    }
    /* 「載入時自動套用」下拉：選項就是目前表格上的名稱，所以改名/增刪列之後
       要跟著重建；用名稱而不是列序當記憶，刪掉中間一列才不會莫名其妙換一組。 */
    function presetRenderAutoSel(rows) {
        var sel = $d('g3dPresetAuto');
        if (!sel) return;
        var want = sel.getAttribute('data-want');
        if (want === null) {
            want = '';
            rows.forEach(function (r) { if (r && r.auto && r.name) want = r.name; });
        }
        var h = ['<option value="">— 不自動套用 —</option>'];
        rows.forEach(function (r) {
            if (!r || !r.name) return;
            h.push('<option value="' + esc(r.name) + '">' + esc(r.name) + '</option>');
        });
        sel.innerHTML = h.join('');
        var hit = '';
        Array.prototype.forEach.call(sel.options, function (op) {
            if (op.value && op.value === want) hit = op.value;
        });
        sel.value = hit;
        sel.setAttribute('data-want', hit);
    }
    /* 畫面上**每一列**（含還沒填名稱的空列）。鍵盤增刪列與重繪要用這一支，
       用下面會略過空列的那一支會讓剛加出來的空列當場消失。 */
    function presetReadRowsRaw() {
        var tb = $d('g3dPresetBody'), out = [];
        if (!tb) return out;
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr) {
            var o = {};
            Array.prototype.forEach.call(tr.querySelectorAll('[data-pk]'), function (inp) {
                var v = inp.value.trim();
                if (v !== '') o[inp.getAttribute('data-pk')] = v;
            });
            out.push(o);
        });
        return out;
    }
    /* 要送出去存檔的列（沒填名稱的視為空列，直接略過） */
    function presetReadRows() {
        var sel = $d('g3dPresetAuto');
        var autoName = sel ? sel.value : '';
        return presetReadRowsRaw().filter(function (o) { return !!o.name; })
            .map(function (o) { o.auto = (autoName && o.name === autoName) ? 1 : 0; return o; });
    }
    function presetSaveRows(rows, onDone) {
        var st = $d('g3dPresetStatus');
        if (st) { st.textContent = '儲存中…'; st.style.color = '#8a7560'; }
        presetApi('presets_save', { rows: JSON.stringify(rows) }, function (j) {
            if (j && j.success) {
                PRESETS.rows = j.rows || [];
                PRESETS.canEdit = !!j.can_edit;
                presetRender();
                if (st) { st.textContent = '已儲存（' + PRESETS.rows.length + ' 筆）'; st.style.color = '#2e7d32'; }
                if (onDone) onDone(true);
            } else {
                if (st) { st.textContent = (j && j.message) || '儲存失敗'; st.style.color = '#DD5138'; }
                if (onDone) onDone(false);
            }
        });
    }

    /* ══ 十二之三、2D 工程圖（零成本「數字→2D」，2026-10-13）═══════════════
       見上方 CSS 區塊的說明：直接拿建立 3D 用的同一組參數（p／res）畫 SVG
       剖面圖，不是把 3D 模型拍扁或反推——所以尺寸標註保證精確，不是估的。
       只畫「工件＋治具＋砂輪/刀具在極限位置」的半剖面，不畫實際齒形/齒溝（與
       3D 那邊已知的範圍限制一致，檔頭說明已經寫過）。 */
    function buildProfileSVG(p, res) {
        var VW = 900, VH = 460;
        var obs = p.obs || [];

        /* ── 版面範圍：x 取所有障礙物＋齒段＋砂輪/刀具極限位置的涵蓋範圍，
           r 取所有半徑＋砂輪/刀具頂部（cy+Rw）的涵蓋範圍──────────────────── */
        var xMin = -p.Lsh, xMax = p.Lsp;
        obs.forEach(function (o) { if (o.x0 < xMin) xMin = o.x0; });
        /* 砂輪/刀具一定要畫得進來（退刀位置是主角，極限位置是對照），不然圓會被切掉。 */
        [res.cxWork, res.cxLimit].forEach(function (cx) {
            if (!isFinite(cx)) return;
            xMin = Math.min(xMin, cx - p.Rw * 1.05);
            xMax = Math.max(xMax, cx + p.Rw * 1.05);
        });
        /* 砂輪/刀具中心高度在 res 上不是 p 上——寫成 p.cy 會讓 rMax 變成 NaN，
           之後每一個 sx()/sy() 都是 NaN，整張圖「一條線都畫不出來而且不報錯」
           （2026-10-13 使用者回報「2D 工程圖沒有資料」就是這個）。 */
        var rMax = Math.max(p.Rs, p.Rr, res.cy + p.Rw);
        obs.forEach(function (o) { if (o.R > rMax) rMax = o.R; });
        var xSpan = Math.max(xMax - xMin, 1), rSpan = Math.max(rMax, 1);

        /* 提醒文字是整句中文，SVG 的 <text> 不會自動換行，照原樣畫就會「一行拉過
           整張圖、兩句疊在一起看不出內容」（2026-10-13 使用者回報）。先折行、
           再依行數決定上方要留多少空間。 */
        var noteLines = [];
        (res.notes || []).slice(0, 2).forEach(function (n) {
            var t = String(n), per = 46;          /* 中文字寬約等於字級，46 字約 460px */
            while (t.length > per) {
                var cut = t.lastIndexOf('，', per);
                if (cut < per * 0.5) cut = t.lastIndexOf('。', per);
                if (cut < per * 0.5) cut = per;
                noteLines.push(t.slice(0, cut + 1));
                t = t.slice(cut + 1);
            }
            if (t) noteLines.push(t);
        });
        noteLines = noteLines.slice(0, 4);

        var padL = 26, padR = 26, padT = 34 + noteLines.length * 13 + 22, padB = 118;
        var sc = Math.min((VW - padL - padR) / xSpan, (VH - padT - padB) / rSpan);
        padL = Math.floor((VW - xSpan * sc) / 2);   /* 水平置中 */
        function sx(x) { return padL + (x - xMin) * sc; }
        function sy(r) { return VH - padB - r * sc; }

        var parts = [];
        /* 箭頭：路徑的尖端在 x=8，所以 refX 一定要是 8，尖端才會剛好落在線的端點上
           （原本 marker-end 給 6、marker-start 給 1，箭頭整個凸出標線之外＝
           2026-10-13 使用者回報「標線箭頭都超過標線了」）。
           另外 markerUnits 預設是 strokeWidth，會跟著線粗一起縮放、大小不一致，
           一律改成 userSpaceOnUse 固定 8×6。 */
        var defs = '<defs>'
            + '<marker id="pa"  markerUnits="userSpaceOnUse" markerWidth="8" markerHeight="6"'
            +   ' refX="8" refY="3" orient="auto"><path d="M0,0 L8,3 L0,6 Z" fill="#555"/></marker>'
            + '<marker id="pa2" markerUnits="userSpaceOnUse" markerWidth="8" markerHeight="6"'
            +   ' refX="8" refY="3" orient="auto-start-reverse"><path d="M0,0 L8,3 L0,6 Z" fill="#555"/></marker>'
            + '<marker id="pb"  markerUnits="userSpaceOnUse" markerWidth="8" markerHeight="6"'
            +   ' refX="8" refY="3" orient="auto"><path d="M0,0 L8,3 L0,6 Z" fill="#8a5a22"/></marker>'
            + '<marker id="pb2" markerUnits="userSpaceOnUse" markerWidth="8" markerHeight="6"'
            +   ' refX="8" refY="3" orient="auto-start-reverse"><path d="M0,0 L8,3 L0,6 Z" fill="#8a5a22"/></marker>'
            + '</defs>'
            /* 文字一律加白色外框，壓在線條或色塊上也看得清楚 */
            + '<style>text{paint-order:stroke;stroke:#fff;stroke-width:3px;stroke-linejoin:round;}</style>';

        /* ── 標題與提醒（與結果面板同一份 res.notes，不要另寫一份說法）──── */
        parts.push('<text x="' + (VW / 2) + '" y="20" text-anchor="middle" font-size="15" '
            + 'font-weight="700" fill="#4a3520">砂輪/刀具干涉 2D 剖面圖（示意，傾角 '
            + fx(p.tilt, 1) + '°' + (res.blocked && res.bind && res.bind.kind !== 'shoulder' ? '、含治具' : '')
            + '）</text>');
        noteLines.forEach(function (n, i) {
            parts.push('<text x="' + (VW / 2) + '" y="' + (36 + i * 13) + '" text-anchor="middle" '
                + 'font-size="10" fill="#8a5a22">' + esc(n) + '</text>');
        });

        /* ── 工件軸線（中心線）────────────────────────────────────────── */
        parts.push('<line x1="' + sx(xMin) + '" y1="' + sy(0) + '" x2="' + sx(xMax) + '" y2="' + sy(0)
            + '" stroke="#ddd" stroke-width="1" stroke-dasharray="6,3"/>');

        /* ── 齒（花鍵）段：x∈[0,Lsp]，R=Rr，綠色──與出尾計算同一套配色慣例 */
        parts.push('<rect x="' + sx(0) + '" y="' + sy(p.Rr) + '" width="' + (p.Lsp * sc).toFixed(1)
            + '" height="' + (p.Rr * sc).toFixed(1) + '" fill="#e8f5e9" stroke="#388e3c" stroke-width="1.5"/>');
        parts.push('<text x="' + sx(p.Lsp / 2) + '" y="' + (sy(p.Rr) - 6) + '" text-anchor="middle" '
            + 'font-size="10" fill="#2e7d32">齒段</text>');

        /* ── 肩部：x∈[-Lsh,0]，R=Rs，藍色 ──────────────────────────────── */
        parts.push('<rect x="' + sx(-p.Lsh) + '" y="' + sy(p.Rs) + '" width="' + (p.Lsh * sc).toFixed(1)
            + '" height="' + (p.Rs * sc).toFixed(1) + '" fill="#dce8f8" stroke="#1565c0" stroke-width="1.5"/>');
        var shHit = res.bind && res.bind.kind === 'shoulder';
        parts.push('<text x="' + sx(-p.Lsh / 2) + '" y="' + (sy(p.Rs) - 18) + '" text-anchor="middle" '
            + 'font-size="10" font-weight="' + (shHit ? '700' : '400') + '" '
            + 'fill="' + (shHit ? '#DD5138' : '#1565c0') + '">肩部'
            + (shHit ? '（先碰到）' : '') + '</text>');

        /* ── 肩部面（x=0）紅色虛線，與出尾計算同一個視覺慣例 ────────────── */
        parts.push('<line x1="' + sx(0) + '" y1="' + sy(rMax * 1.04) + '" x2="' + sx(0) + '" y2="' + sy(0)
            + '" stroke="#e53935" stroke-width="1.2" stroke-dasharray="5,3"/>');

        /* ── 治具：各段依序往外接，棕褐色（與工件顏色區分，不是同一個料件） ── */
        obs.forEach(function (o) {
            if (o.kind !== 'fixture') return;
            parts.push('<rect x="' + sx(o.x0) + '" y="' + sy(o.R) + '" width="' + ((o.x1 - o.x0) * sc).toFixed(1)
                + '" height="' + (o.R * sc).toFixed(1) + '" fill="#EFE3CE" stroke="#9C8468" stroke-width="1.5"/>');
            var hit = (o === res.bind);
            parts.push('<text x="' + sx((o.x0 + o.x1) / 2) + '" y="' + (sy(o.R) - 6) + '" text-anchor="middle" '
                + 'font-size="10" font-weight="' + (hit ? '700' : '400') + '" '
                + 'fill="' + (hit ? '#DD5138' : '#8a5a22') + '">' + esc(o.name)
                + (hit ? '（先碰到）' : '') + '</text>');
        });

        /* ── 直徑標註：半剖面圖的慣例＝從中心線量到外徑、數值標 Ø（使用者要求
              「直徑應該直接標示直徑」，原本只有一行文字不是尺寸標註）。
              段太窄時放不下箭頭與數字，退回只標在上方名稱後面。 */
        /* 直徑標註。**這張圖畫的是半剖面（只畫軸心以上那一半）**，所以尺寸線是
           從中心線量到外徑、長度等於半徑；依製圖慣例這種情形**只在外徑那一端給
           箭頭**，中心線那一端不給（給了會被讀成「這一段長度就是直徑」）。
           數值仍標 Ø＝直徑（2026-10-13 使用者指正）。
           標籤一律帶段名，否則畫面上會出現一個沒頭沒尾的「Ø70.0」看不出是哪一段。 */
        function diaDim(x0, x1, R, color, label) {
            var xm = sx((x0 + x1) / 2), y0 = sy(0), yR = sy(R);
            /* 段名已經畫在矩形上方了，這裡只標數值，不要再帶一次名字（會變成同一個
               名字出現兩次）。段太窄畫不下尺寸線時，數值往上再讓一層，不要跟段名
               疊在同一個高度（2026-10-13 使用者看到一個沒頭沒尾的「Ø70.0」就是這樣來的）。 */
            var txt = 'Ø' + fx(R * 2, 1);
            if ((x1 - x0) * sc < 26 || (y0 - yR) < 26) {
                parts.push('<text x="' + xm + '" y="' + (yR - 30) + '" text-anchor="middle" '
                    + 'font-size="9.5" fill="' + color + '">' + esc(txt) + '</text>');
                return;
            }
            parts.push('<line x1="' + xm + '" y1="' + y0 + '" x2="' + xm + '" y2="' + yR
                + '" stroke="' + color + '" stroke-width="1" marker-end="url(#pa)"/>');
            parts.push('<text x="' + (xm + 4) + '" y="' + ((y0 + yR) / 2) + '" font-size="9.5" '
                + 'fill="' + color + '" dominant-baseline="middle">' + esc(txt) + '</text>');
        }
        diaDim(0, p.Lsp, p.Rr, '#2e7d32', '齒底徑');
        diaDim(-p.Lsh, 0, p.Rs, '#1565c0', '肩部');
        obs.forEach(function (o) {
            if (o.kind === 'fixture') diaDim(o.x0, o.x1, o.R, '#8a5a22', o.name);
        });

        /* ── 長度標註（第一排，各段各自的 x 範圍不重疊，可以共用一排）──── */
        var dimY1 = sy(0) + 30;
        function lenDim(x0, x1, label) {
            var ax0 = sx(x0), ax1 = sx(x1);
            parts.push('<line x1="' + ax0 + '" y1="' + dimY1 + '" x2="' + ax1 + '" y2="' + dimY1
                + '" stroke="#888" stroke-width="1" marker-start="url(#pa2)" marker-end="url(#pa)"/>');
            parts.push('<line x1="' + ax0 + '" y1="' + (sy(0) + 5) + '" x2="' + ax0 + '" y2="' + (dimY1 - 3)
                + '" stroke="#bbb" stroke-width="0.8"/>');
            parts.push('<line x1="' + ax1 + '" y1="' + (sy(0) + 5) + '" x2="' + ax1 + '" y2="' + (dimY1 - 3)
                + '" stroke="#bbb" stroke-width="0.8"/>');
            parts.push('<text x="' + ((ax0 + ax1) / 2) + '" y="' + (dimY1 + 13) + '" text-anchor="middle" '
                + 'font-size="9.5" fill="#666">' + esc(label) + '</text>');
        }
        lenDim(-p.Lsh, 0, '肩部長 ' + fx(p.Lsh, 1));
        obs.forEach(function (o) {
            if (o.kind !== 'fixture') return;
            if ((o.x1 - o.x0) * sc < 26) return;   /* 太短的段標不下文字就跳過，圖面不硬塞 */
            lenDim(o.x0, o.x1, fx(o.x1 - o.x0, 1));
        });

        /* ── 砂輪/刀具（極限位置）：圓心恆在 (cxLimit, cy)，半徑 Rw──────────────
           傾角 0 時是精確的真圓；傾角不為 0 時真實輪廓不是圓（第二段虛線＋
           文字說明），但圓心位置（含出尾長度）仍是精確解，不是示意猜的。 */
        var wy = sy(res.cy), wr = p.Rw * sc;
        var dash = p.tilt !== 0 ? ' stroke-dasharray="4,3"' : '';
        function wheelAt(cx, main, label) {
            var wx = sx(cx);
            parts.push('<circle cx="' + wx + '" cy="' + wy + '" r="' + wr
                + '" fill="' + (main ? 'rgba(199,122,48,.16)' : 'none') + '" stroke="'
                + (main ? '#C77A30' : '#bda98e') + '" stroke-width="' + (main ? 1.6 : 1)
                + '"' + (main ? dash : ' stroke-dasharray="3,4"') + '/>');
            parts.push('<circle cx="' + wx + '" cy="' + wy + '" r="' + (main ? 3 : 2)
                + '" fill="' + (main ? '#C77A30' : '#bda98e') + '"/>');
            /* 標籤放在圓的**側邊**（主角放右、對照放左），不要頂在圓的正上方——
               那裡正好是上方說明文字的位置，兩邊會疊在一起（使用者回報）。 */
            parts.push('<text x="' + (wx + (main ? wr + 6 : -wr - 6)) + '" y="' + (wy - 4)
                + '" text-anchor="' + (main ? 'start' : 'end') + '" '
                + 'font-size="10" fill="' + (main ? '#8a5a22' : '#a89477') + '">'
                + esc(label) + '</text>');
        }
        /* 主角是「退刀位置」（砂輪/刀具實際會停的地方，只由退刀量決定）；
           極限位置只畫成淡虛線當對照，免得又看成「砂輪/刀具跟著治具跑」。 */
        wheelAt(res.cxWork, true, '砂輪/刀具（退刀位置，退刀量 ' + fx(p.U, 2) + '）');
        if (res.blocked && Math.abs(res.cxLimit - res.cxWork) > 1e-6)
            wheelAt(res.cxLimit, false, '極限位置（再往外就會撞到）');
        if (p.tilt !== 0) {
            parts.push('<text x="' + (sx(res.cxWork) + p.Rw * sc + 6) + '" y="' + (wy + 10)
                + '" font-size="9" fill="#b08a60">傾角 ' + fx(p.tilt, 1)
                + '°，實際外形非真圓，此圖僅示意位置</text>');
        }

        /* ── 出尾長度主尺寸（第二排，0 → cxLimit）──────────────────────── */
        var dimY2 = dimY1 + 34;
        if (res.blocked) {
            var zx = sx(0), lx = sx(res.cxLimit);
            parts.push('<line x1="' + Math.min(zx, lx) + '" y1="' + dimY2 + '" x2="' + Math.max(zx, lx) + '" y2="' + dimY2
                + '" stroke="#DD5138" stroke-width="1.4" marker-start="url(#pa2)" marker-end="url(#pa)"/>');
            parts.push('<line x1="' + zx + '" y1="' + (sy(0) + 5) + '" x2="' + zx + '" y2="' + (dimY2 - 3)
                + '" stroke="#DD5138" stroke-width="0.8" stroke-dasharray="2,2"/>');
            parts.push('<line x1="' + lx + '" y1="' + (sy(0) + 5) + '" x2="' + lx + '" y2="' + (dimY2 - 3)
                + '" stroke="#DD5138" stroke-width="0.8" stroke-dasharray="2,2"/>');
            parts.push('<text x="' + ((zx + lx) / 2) + '" y="' + (dimY2 + 14) + '" text-anchor="middle" '
                + 'font-size="11" font-weight="700" fill="#c0392b">出尾長度 ' + fx(res.tail) + '</text>');
            /* 「先碰到哪一段」不再另外畫一行字——那行字會跟段名、直徑標註疊在
               一起（2026-10-13 使用者回報）。改成直接把那一段的**段名標紅並加註**，
               訊息一樣清楚又少一段文字。段名是在上面畫 rect 時一起畫的，所以這裡
               只補一個紅色的註記放在段名右邊。 */
        } else if (res.overrun > 0) {
            parts.push('<text x="' + sx(0) + '" y="' + (dimY2 + 14) + '" font-size="11" fill="#8a7560">'
                + '這一側沒有東西擋著砂輪/刀具，可再越過肩部面 ' + fx(res.overrun) + ' mm</text>');
        }

        /* ── 右下角：出尾長度＋退刀量，與出尾計算同一個「最終數字」慣例 ── */
        parts.push('<text x="' + (VW - 6) + '" y="' + (VH - 6) + '" text-anchor="end" font-size="11" '
            + 'fill="#6a1b9a" font-weight="600">出尾（含退刀 ' + fx(p.U, 2) + '）= '
            + fx(res.tailTot) + ' mm</text>');

        /* ── 免責文字：示意圖不是 CAD 檔，一律印在左下角不要讓人誤會 ────── */
        parts.push('<text x="6" y="' + (VH - 6) + '" font-size="9" fill="#b0a08a">'
            + '示意剖面圖（向量繪製，尺寸精確），非 CAD 圖檔；不含實際齒形／齒溝</text>');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + VW + ' ' + VH + '">'
            + defs + parts.join('') + '</svg>';
    }

    function profile2DOpen() {
        if (!ST.res || !ST.res.ok) return;
        var stage = $d('g3d-2d-stage');
        var box = $d('g3d-2d-box');
        if (!stage || !box) return;
        stage.innerHTML = buildProfileSVG(ST.res.p, ST.res);
        box.classList.add('g3d-show');
    }
    function profile2DClose() {
        var box = $d('g3d-2d-box');
        if (box) box.classList.remove('g3d-show');
    }
    /* SVG 轄 PNG：序列化→Blob→Image→canvas→toDataURL，不依賴任何外部套件。
       canvas 畫布用 2 倍 viewBox 尺寸輸出，下載出來的 PNG 才不會模糊。 */
    function profile2DDownloadPng() {
        var svgEl = $1q('#g3d-2d-stage svg');
        if (!svgEl) return;
        var xml = new XMLSerializer().serializeToString(svgEl);
        var vb = svgEl.viewBox.baseVal;
        var scalePx = 2;
        var cv = document.createElement('canvas');
        cv.width = vb.width * scalePx; cv.height = vb.height * scalePx;
        var ctx = cv.getContext('2d');
        var img = new Image();
        var blob = new Blob([xml], { type: 'image/svg+xml;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        img.onload = function () {
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
            ctx.drawImage(img, 0, 0, cv.width, cv.height);
            URL.revokeObjectURL(url);
            var a = document.createElement('a');
            a.href = cv.toDataURL('image/png');
            a.download = '砂輪/刀具干涉2D剖面_' + new Date().toISOString().slice(0, 10) + '.png';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        };
        img.src = url;
    }
    function profile2DDownloadSvg() {
        var svgEl = $1q('#g3d-2d-stage svg');
        if (!svgEl) return;
        /* 這裡刻意分段拼字串、不要在原始碼裡直接連著寫出「尖括號加問號」那組
           XML 宣告開頭——即使寫在 JS 字串或註解裡，PHP 的 short_open_tag
           只要是開著的就會原樣解析成 PHP 開始標籤，整份檔案因此壞掉。
           **這一支 CLI 的 php -l 驗不出來**：CLI 用的 php.ini
           （bin/php/php8.3.1/php.ini）short_open_tag=Off，但 Apache 實際吃的
           是另一份（conf/php8.3.1/php.ini）short_open_tag=On——同一支 php -l
           在這台機器對這個設定項完全是假陰性，一定要用真實 HTTP 請求驗證，
           連註解裡出現同一組符號都會重現這個問題（已實測踩過兩次）。 */
        var xmlDecl = '<' + '?xml version="1.0" encoding="UTF-8"?' + '>\n';
        var xml = xmlDecl + new XMLSerializer().serializeToString(svgEl);
        var blob = new Blob([xml], { type: 'image/svg+xml;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = '砂輪/刀具干涉2D剖面_' + new Date().toISOString().slice(0, 10) + '.svg';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    }
    /* 2D 剖面圖的列印走與 3D 完全相同的報表外殼：左邊數據、右邊圖、A4 橫式
       （2026-10-13 使用者要求兩種列印一致）。 */
    function profile2DPrint() {
        var svgEl = $1q('#g3d-2d-stage svg');
        if (!svgEl) { alert('2D 工程圖還沒畫出來。'); return; }
        printReport({
            reportTitle: '2D 模擬報告（出尾長度與治具干涉）',
            rightTitle: '2D 剖面圖（示意，尺寸精確）',
            rightInner: new XMLSerializer().serializeToString(svgEl),
            caption: '向量繪製的示意剖面圖，尺寸由同一組參數解析計算；'
                   + '非 CAD 圖檔，不含實際齒形／齒溝。',
            docTag: '2D剖面圖'
        });
    }
    /* $1q：不限定 ST.root 的 querySelector（本面板的輸出目標是頂層的
       #g3d-2d-box，不是 mount() 掛載的那個容器）。 */
    function $1q(sel) { return document.querySelector(sel); }

    /* #g3d2dPng／#g3d2dSvg／#g3d2dPrint／#g3d2dClose 四顆活在 #g3d-2d-box 這個
       靜態頂層節點裡（跟 #g3d-win 同層，模組載入時就存在），這裡直接綁沒問題。
       **#g3d2dBtn 不是**——它是 panelHtml() 產生、掛在面板裡的按鈕，模組載入
       當下 mount() 還沒跑過，這裡抓一定是 null；已改到 mount() 內部去綁
       （跟 g3dShot／g3dMeasBtn 等其他面板內按鈕同一個位置，避免同一種坑）。 */
    (function () {
        var pngBtn = $d('g3d2dPng'); if (pngBtn) pngBtn.addEventListener('click', profile2DDownloadPng);
        var svgBtn = $d('g3d2dSvg'); if (svgBtn) svgBtn.addEventListener('click', profile2DDownloadSvg);
        var prtBtn = $d('g3d2dPrint'); if (prtBtn) prtBtn.addEventListener('click', profile2DPrint);
        var clsBtn = $d('g3d2dClose'); if (clsBtn) clsBtn.addEventListener('click', profile2DClose);
    })();

    /* 2D 工程圖視窗標題列可拖曳（獨立於 #g3d-win 的拖曳邏輯，互不干擾）。*/
    (function () {
        var hdr = null, win = null, dx = 0, dy = 0, dragging = false;
        document.addEventListener('mousedown', function (e) {
            hdr = $d('g3d-2d-hdr'); win = $d('g3d-2d-box');
            if (!hdr || !win || !hdr.contains(e.target)) return;
            if (e.target.tagName === 'BUTTON') return;
            var r = win.getBoundingClientRect();
            win.style.left = r.left + 'px'; win.style.top = r.top + 'px';
            dx = e.clientX - r.left; dy = e.clientY - r.top;
            dragging = true;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging || !win) return;
            win.style.left = (e.clientX - dx) + 'px';
            win.style.top = Math.max(0, e.clientY - dy) + 'px';
        });
        document.addEventListener('mouseup', function () { dragging = false; });
    })();

    /* ══ 十三、掛載 ════════════════════════════════════════════════════════ */
    function mount(el, opts) {
        opts = opts || {};
        /* 呼叫端帶了砂輪/刀具外徑進來（例如齒輪計算工具的出尾計算）就不要自動套用
           預設，那會把帶進來的尺寸蓋掉。 */
        ST.hostGaveWheel = !!(opts.params && isFinite(parseFloat(opts.params.Dw)));
        ST.presetAutoDone = false;
        ST.userTouched    = false;
        el.innerHTML = panelHtml();
        ST.root = el;
        ST.host = el;
        setParams(opts.params || DEF);

        /* 參數欄位：打字就重算（防抖 120ms，避免每個按鍵都重建一次模型）*/
        el.querySelectorAll('[data-g3dk]').forEach(function (inp) {
            inp.addEventListener('input', function () {
                ST.userTouched = true;      /* 之後預設撈回來也不可以蓋掉他打的字 */
                clearTimeout(ST.timer);
                ST.timer = setTimeout(function () { recompute(false); }, 120);
            });
        });

        /* 治具表：輸入即重算；＋ 新增一段；× 刪除（事件委派，新增的列也涵蓋） */
        var fxb = $1('#g3dFxBody');
        if (fxb) {
            fxb.addEventListener('input', function (e) {
                if (!e.target.getAttribute || !e.target.getAttribute('data-fxk')) return;
                /* 與上面的數字欄位一致：改參數就把砂輪/刀具重新移到新的極限位置。
                   兩種輸入一個會移、一個不會移，使用者會以為其中一個壞了。 */
                clearTimeout(ST.timer);
                ST.timer = setTimeout(function () { recompute(false); }, 150);
            });
            fxb.addEventListener('click', function (e) {
                if (!e.target.classList || !e.target.classList.contains('g3d-fx-del')) return;
                var tr = e.target.closest('tr');
                if (!tr) return;
                if (fxb.querySelectorAll('tr').length <= 1) {
                    /* 只剩一列時不刪掉列、只清空：整張表消失會讓人以為功能不見了 */
                    Array.prototype.forEach.call(tr.querySelectorAll('input'),
                        function (x) { x.value = ''; });
                } else {
                    tr.parentNode.removeChild(tr);
                }
                fxRenumber();
                recompute(false);
            });
        }
        /* 治具表按 ↑↓ 換列之後，游標一律移到「直徑」欄（2026-10-13 使用者要求：
           換到新的一段本來就是從直徑開始填，停在長度欄還要再按一次 Tab）。
           **刻意不自己接管 ↑↓**——增刪列是共用檔 eg_input_rules.js 的合約
           （本元件另有備援），自己再寫一份會變成兩套規則；這裡只在它把焦點
           移好之後、用 focusin 把游標再撥到直徑欄，兩邊互不干擾。
           不呼叫 select()：對 input[type=number] 呼叫 select() 會把焦點搶回自己，
           與延後執行的自動全選湊在一起會變成無窮迴圈（2026-10-12 實測踩過）。 */
        var fxArrowT = 0;
        if (fxb) {
            fxb.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
                /* **一定要延後做**：實測共用檔是在 keydown 之後才非同步把焦點移到
                   同一欄（事件序列 kd → fi:d → fi:len → fi:len），同步撥過去會被它
                   再蓋回來；等它移完再撥才有效。80ms 使用者感覺不到。 */
                clearTimeout(fxArrowT);
                fxArrowT = setTimeout(function () {
                    var a = document.activeElement;
                    if (!a || !a.getAttribute || a.getAttribute('data-fxk') !== 'len') return;
                    if (!fxb.contains(a)) return;              /* 焦點已經跑到別處就不要亂動 */
                    var tr = a.closest('tr'), d = tr && tr.querySelector('[data-fxk=d]');
                    if (d) d.focus();
                }, 80);
            });
            /* 用滑鼠點長度欄是刻意要填長度，不可以被撥走 */
            fxb.addEventListener('mousedown', function () { clearTimeout(fxArrowT); });
        }

        var fxAdd = $1('#g3dFxAdd');
        if (fxAdd) fxAdd.addEventListener('click', function () {
            var rows = readFx();
            rows.push({ d: '', len: '' });
            renderFx(rows);
            var all = $1('#g3dFxBody').querySelectorAll('[data-fxk=d]');
            if (all.length) all[all.length - 1].focus();
        });

        var sl = $1('#g3dCx');
        if (sl) {
            sl.addEventListener('input', function () {
                ST.cx = parseFloat(sl.value);
                clearTimeout(ST.timer);
                ST.timer = setTimeout(function () { draw(false); }, 40);
            });
        }
        var tw = $1('#g3dToWork');
        if (tw) tw.addEventListener('click', function () {
            if (!ST.res || !ST.res.ok) return;
            ST.cx = ST.res.cxWork;
            if (sl) sl.value = ST.cx;
            draw(false);
        });
        var tl = $1('#g3dToLimit');
        if (tl) tl.addEventListener('click', function () {
            if (!ST.res || !ST.res.ok) return;
            ST.cx = ST.res.cxLimit;
            if (sl) sl.value = ST.cx;
            draw(false);
        });
        var tr = $1('#g3dTrans');
        if (tr) tr.addEventListener('change', function () { draw(false); });
        var dm = $1('#g3dDimOn');
        if (dm) dm.addEventListener('change', function () { DIM.sig = null; drawDims(); });
        /* 視窗或面板尺寸改變時相機矩陣不會變，rAF 的比對察覺不到，要另外補一次 */
        window.addEventListener('resize', function () { DIM.sig = null; drawDims(); });
        var rf = $1('#g3dRefit');
        if (rf) rf.addEventListener('click', function () { draw(true); });

        el.querySelectorAll('[data-g3dview]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (VW && T3) T3.setStandardView(VW, b.getAttribute('data-g3dview'));
            });
        });

        var sb = $1('#g3dSecBtn'), sbox = $1('#g3dSecBox');
        if (sb) sb.addEventListener('click', function () {
            if (ST.sec) {
                try { T3.clearSectionMode(VW, ST.sec); } catch (e) {}
                ST.sec = null;
                sb.classList.remove('g3d-on');
                if (sbox) sbox.style.display = 'none';
            } else {
                applySection();
                sb.classList.add('g3d-on');
                if (sbox) sbox.style.display = 'inline-flex';
            }
        });
        el.querySelectorAll('[data-g3dsec]').forEach(function (b) {
            b.addEventListener('click', function () {
                ST.secOpts.axis = b.getAttribute('data-g3dsec');
                el.querySelectorAll('[data-g3dsec]').forEach(function (x) {
                    x.classList.toggle('g3d-on', x === b);
                });
                if (ST.sec) T3.updateSectionMode(VW, ST.sec, ST.secOpts);
            });
        });
        var sp = $1('#g3dSecPos');
        if (sp) sp.addEventListener('input', function () {
            ST.secOpts.ratio = parseFloat(sp.value);
            if (ST.sec) T3.updateSectionMode(VW, ST.sec, ST.secOpts);
        });
        var sf = $1('#g3dSecFlip');
        if (sf) sf.addEventListener('click', function () {
            ST.secOpts.flip = !ST.secOpts.flip;
            sf.classList.toggle('g3d-on', ST.secOpts.flip);
            if (ST.sec) T3.updateSectionMode(VW, ST.sec, ST.secOpts);
        });

        var mb = $1('#g3dMeasBtn');
        if (mb) mb.addEventListener('click', function () {
            if (!T3 || !T3.MeasureState) return;
            ST.measOn = !ST.measOn;
            mb.classList.toggle('g3d-on', ST.measOn);
            if (ST.measOn && !ST.meas) {
                ST.meas = new T3.MeasureState();
                T3.attachMeasureInteraction($1('#g3dCanvas'), 'canvas',
                    function () { return { viewer: VW, state: ST.meas }; },
                    function () { return ST.measOn; },
                    function (info) {
                        var o = $1('#g3dMeasOut');
                        if (o) o.textContent = (info && info.text) ? info.text : '';
                    });
            }
            if (!ST.measOn) {
                var o = $1('#g3dMeasOut'); if (o) o.textContent = '';
            }
        });

        var kvb = $1('#g3dKvSet');
        if (kvb) {
            kvb.disabled = !KV.canEdit;
            kvb.addEventListener('click', kvOpenSet);
        }
        var prt = $1('#g3dPrint');
        if (prt) prt.addEventListener('click', printSheet);
        var shot = $1('#g3dShot');
        if (shot) shot.addEventListener('click', function () {
            if (!VW || !T3) return;
            var url = T3.screenshot(VW, { width: 1600 });
            var a = document.createElement('a');
            a.href = url;
            a.download = '砂輪/刀具干涉_' + new Date().toISOString().slice(0, 10) + '.png';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        });

        var btn2d = $1('#g3d2dBtn');
        if (btn2d) btn2d.addEventListener('click', profile2DOpen);

        /* ── 砂輪/刀具預設 ─────────────────────────────────────────────────── */
        var psel = $1('#g3dPreset');
        if (psel) psel.addEventListener('change', function () {
            if (psel.value !== '') presetApply(parseInt(psel.value, 10));
        });
        var psave = $1('#g3dPresetSave');
        if (psave) psave.addEventListener('click', function () {
            if (!PRESETS.canEdit) return;
            var name = window.prompt('這組砂輪/刀具尺寸要取什麼名字？（例：Ø300 粗磨）', '');
            if (name === null) return;
            name = String(name).trim();
            if (!name) { alert('請輸入名稱'); return; }
            var cur = { name: name };
            PRESET_KEYS.forEach(function (k) {
                var e2 = $1('#g3d_' + k);
                if (e2 && e2.value !== '') cur[k] = e2.value;
            });
            var rows = PRESETS.rows.slice();
            var idx = -1;
            for (var i = 0; i < rows.length; i++) {
                if (String(rows[i].name).toLowerCase() === name.toLowerCase()) { idx = i; break; }
            }
            if (idx >= 0) {
                if (!confirm('已經有一筆叫「' + name + '」的預設，要覆蓋它嗎？')) return;
                rows[idx] = cur;
            } else {
                rows.push(cur);
            }
            presetSaveRows(rows, function (ok) {
                if (ok) alert('已存成預設「' + name + '」');
            });
        });
        var pmng = $1('#g3dPresetMng');
        if (pmng) pmng.addEventListener('click', presetOpenManager);

        var kvmask = $d('g3dKvMask');
        if (kvmask) {
            kvmask.addEventListener('click', function (e) {
                if (e.target === kvmask) kvmask.classList.remove('g3d-show');
            });
            var kvc = $d('g3dKvClose');
            if (kvc) kvc.addEventListener('click', function () { kvmask.classList.remove('g3d-show'); });
            var kva = $d('g3dKvApply');
            if (kva) kva.addEventListener('click', kvSaveSet);
        }

        var pmask = $d('g3dPresetMask');
        if (pmask) {
            pmask.addEventListener('click', function (e) {
                if (e.target === pmask) pmask.classList.remove('g3d-show');
            });
            var pclose = $d('g3dPresetClose');
            if (pclose) pclose.addEventListener('click', function () {
                pmask.classList.remove('g3d-show');
            });
            /* 選了之後要把選擇記在 data-want 上：新增/刪除列會整個重畫下拉，
               不記住的話剛選好的那一組會被打回上一次的值。 */
            var pauto = $d('g3dPresetAuto');
            if (pauto) pauto.addEventListener('change', function () {
                pauto.setAttribute('data-want', pauto.value);
            });
            var padd = $d('g3dPresetAdd');
            if (padd) padd.addEventListener('click', function () {
                /* 一定要用 Raw：presetReadRows() 會略過沒填名稱的列，
                   畫面上已經有一列空的時候按「新增」會把它弄不見 */
                var rows = presetReadRowsRaw();
                rows.push({ name: '' });
                presetRenderRows(rows);
            });
            var papply = $d('g3dPresetApply');
            if (papply) papply.addEventListener('click', function () {
                presetSaveRows(presetReadRows(), function (ok) {
                    if (ok) presetRenderRows(PRESETS.rows.slice());
                });
            });
            var pbody = $d('g3dPresetBody');
            if (pbody) pbody.addEventListener('click', function (e) {
                if (!e.target.classList || !e.target.classList.contains('g3d-fx-del')) return;
                var tr = e.target.closest('tr');
                var all = Array.prototype.slice.call(pbody.querySelectorAll('tr'));
                var at2 = all.indexOf(tr);
                /* 用畫面上的列序刪：presetReadRowsRaw() 與畫面是一列對一列
                   （會略過空列的 presetReadRows() 索引對不起來，會刪錯人） */
                var disp = presetReadRowsRaw();
                if (at2 >= 0) disp.splice(at2, 1);
                presetRenderRows(disp);
            });
        }

        /* ── 對準接觸點：把畫面拉近到「會撞到的那一點」──────────────────
           使用者 2026-10-13 回報「3D 看不出來有沒有干涉」：砂輪/刀具 Ø300 配 Ø40 工件時，
           接觸區相對整個畫面非常小，光靠顏色不夠，要能一鍵看過去。 */
        var fh = $1('#g3dFocusHit');
        if (fh) fh.addEventListener('click', focusContact);

        bindInputRules(el);
        presetLoad();
        kvLoad();
        recompute(false);
        return ST;
    }

    /* ══ 輸入欄位行為（Enter 跳下一欄／聚焦全選／有值雙擊清空）══════════════
       CLAUDE.md 的 UI 鐵則規定這些一律走共用檔 resource/js/eg_input_rules.js，
       不要各頁自刻。但本元件會被掛在**沒有載入那支共用檔**的頁面上（批圖編輯器、
       訂單追蹤都列在 input_rules_baseline.txt 基準線內，硬塞共用檔會改變那兩頁
       既有的 Enter 行為、可能誤送出），所以這裡做的是「**共用檔不在時才接手、
       而且只綁在本元件的面板上**」的備援：
         - 共用檔在（window.EG_INPUT_RULES）→ 整段跳過，交給它（面板已標
           data-eg-form／data-eg-submit，它就知道 Enter 要在哪些欄位之間跳）
         - 共用檔不在 → 只對 el 內的欄位綁，絕不碰 document，宿主頁面零影響
       行為與共用檔一致：最後一欄按 Enter＝按下該區塊的主要動作鈕。 */
    function bindInputRules(el) {
        if (window.EG_INPUT_RULES) return;      /* 共用檔已接手，不要綁第二份 */

        function fields() {
            return Array.prototype.filter.call(
                el.querySelectorAll('.g3d-left input[type=number]'),
                function (x) { return !x.disabled && x.offsetParent !== null; });
        }
        /* ── 規則 6 的備援：可增列表格的 ↑↓ 換列與自動增／刪列 ──────────────
           合約與共用檔完全一致（見 eg_input_rules.js 檔頭）：
             末列按 ↓          → 呼叫 data-eg-row-add 指定的全域函式，多一列
             沒填東西的末列按 ↑ → 呼叫 data-eg-row-del，收回那一列（只剩 1 列時函式自己擋）
             其餘 ↑↓           → 在同一欄上下換列
           數字欄的原生 ↑↓ 會改值，所以一律 preventDefault 蓋掉，否則使用者想換列
           卻把尺寸改掉了還不知道。 */
        el.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
            if (e.ctrlKey || e.altKey || e.metaKey) return;
            var t = e.target;
            if (!t || !t.closest) return;
            var td = t.closest('td'), tr = t.closest('tr');
            if (!td || !tr) return;
            var tb = tr.parentNode;
            if (!tb || !tb.getAttribute) return;
            var addFn = tb.getAttribute('data-eg-row-add');
            var delFn = tb.getAttribute('data-eg-row-del');
            if (!addFn && !delFn) return;

            var rows = Array.prototype.slice.call(tb.querySelectorAll('tr'));
            var ri = rows.indexOf(tr);
            var ci = Array.prototype.indexOf.call(tr.children, td);
            var isLast = (ri === rows.length - 1);

            function blank(row) {
                var list = row.querySelectorAll('input,select,textarea');
                for (var i = 0; i < list.length; i++) {
                    if (String(list[i].value == null ? '' : list[i].value).trim() !== '') return false;
                }
                return true;
            }
            function focusCell(idx) {
                setTimeout(function () {
                    var rs = tb.querySelectorAll('tr');
                    if (!rs[idx]) return;
                    var cell = rs[idx].children[ci];
                    var inp = cell && cell.querySelector('input,select,textarea');
                    if (inp) { inp.focus(); try { inp.select(); } catch (err) {} }
                }, 0);
            }

            /* 刪列要排在換列之前：末列是空的時候上一列還在，先換列就沒機會刪了 */
            if (isLast && e.key === 'ArrowUp' && ri > 0 && delFn && blank(tr)) {
                e.preventDefault();
                if (typeof window[delFn] === 'function') { window[delFn](); focusCell(ri - 1); }
                return;
            }
            var to = (e.key === 'ArrowUp') ? ri - 1 : ri + 1;
            if (to >= 0 && to < rows.length) {
                e.preventDefault();
                focusCell(to);
                return;
            }
            if (isLast && e.key === 'ArrowDown' && addFn) {
                e.preventDefault();
                if (typeof window[addFn] === 'function') { window[addFn](); focusCell(ri + 1); }
                return;
            }
            /* 沒有上／下一列可去時，數字欄仍要擋掉原生改值 */
            if (t.tagName === 'INPUT' && t.type === 'number') e.preventDefault();
        });

        el.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.ctrlKey || e.altKey || e.metaKey || e.shiftKey) return;
            var t = e.target;
            if (!t || t.tagName !== 'INPUT' || t.type !== 'number') return;
            e.preventDefault();
            var list = fields(), i = list.indexOf(t);
            if (i < 0) return;
            if (i < list.length - 1) {
                list[i + 1].focus();      /* 全選交給下面的 focusin，這裡不要再呼叫一次 */
            } else {
                var go = $1('#g3dToLimit');     /* 最後一欄＝觸發本區塊的主要動作 */
                if (go) go.click();
                t.blur();
            }
        });
        /* 聚焦自動全選。
           ── 這裡有一個很難查的坑（2026-10-12 實測，務必保留這兩道防護）──────
           `select()` 對 input[type=number] **會把焦點搶回自己身上**。而自動全選
           必須延後到下一個 tick（滑鼠點進欄位時，同步 select 會被接著發生的
           mouseup 放游標覆蓋掉），兩件事湊在一起就會變成無窮迴圈：
             焦點 Dw→Tw 時，Dw 那個還沒執行的 select() 一觸發就把焦點搶回 Dw，
             接著 Tw 的 select() 又搶回 Tw……實測兩個欄位每 15~30ms 互搶一次，
             畫面上的症狀是「按 Enter 跳到下一欄，又自己跳回上一欄」。
           兩道防護：①同時間只保留一個待執行的 select（換欄位就取消上一個）
                     ②真的執行時再確認「這個欄位現在仍然是焦點所在」才 select。 */
        var selTimer = null;
        el.addEventListener('focusin', function (e) {
            var t = e.target;
            if (!(t && t.tagName === 'INPUT' && t.type === 'number' && t.value !== '')) return;
            clearTimeout(selTimer);
            selTimer = setTimeout(function () {
                if (document.activeElement === t) { try { t.select(); } catch (err) {} }
            }, 0);
        });
        el.addEventListener('dblclick', function (e) {
            var t = e.target;
            if (t && t.tagName === 'INPUT' && t.type === 'number' && t.value !== '') {
                t.value = '';
                t.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    /* ══ 十四、浮動視窗 ════════════════════════════════════════════════════ */
    /* 一個頁面只會有一組工具狀態（ST 是模組單例）：嵌入式與浮動視窗擇一使用即可。
       若頁面先用 mount() 嵌在版面裡、之後又呼叫 open()，會把工具搬進浮動視窗，
       這時**要把目前輸入的參數帶過去**，不然使用者打好的尺寸會被重設成預設值。 */
    function open(params) {
        var win = document.getElementById('g3d-win');
        if (!win) return;
        win.classList.add('g3d-show');
        var body = document.getElementById('g3d-body');
        if (!ST.root || ST.root !== body) {
            var carry = params || (ST.root ? readParams() : null);
            mount(body, { params: carry });
        } else if (params) {
            setParams(params); recompute(false);
        }
        if (VW && VW.Resize) setTimeout(function () { VW.Resize(); }, 120);
    }
    function close() {
        var win = document.getElementById('g3d-win');
        if (win) win.classList.remove('g3d-show');
    }

    /* 標題列可拖曳 */
    (function () {
        var hdr = null, win = null, dx = 0, dy = 0, dragging = false;
        document.addEventListener('mousedown', function (e) {
            hdr = document.getElementById('g3d-hdr');
            win = document.getElementById('g3d-win');
            if (!hdr || !win || !hdr.contains(e.target)) return;
            if (e.target.tagName === 'BUTTON') return;
            var r = win.getBoundingClientRect();
            win.style.left = r.left + 'px';
            win.style.top = r.top + 'px';
            win.style.transform = 'none';
            dx = e.clientX - r.left; dy = e.clientY - r.top;
            dragging = true;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging || !win) return;
            win.style.left = (e.clientX - dx) + 'px';
            win.style.top = Math.max(0, e.clientY - dy) + 'px';
        });
        document.addEventListener('mouseup', function () { dragging = false; });
    })();

    /* ══ 十五、自我驗證 ════════════════════════════════════════════════════
       傾角 0 時，數值解出來的出尾長度必須與齒輪計算工具的 2D 公式完全相同。
       這是整支程式最重要的一條正確性檢查：座標系、擺位矩陣、取樣、二分法只要
       有任何一處寫錯，這個等式就不會成立。瀏覽器 console 打
       EGGrind3D.selfTest() 就會跑。 */
    /* 獨立的暴力驗算：對砂輪/刀具表面密集撒點，直接找「還能不干涉的最小 cx」。
       這是刻意寫的第二套做法（慢、但完全不依賴上面那套解析推導），用來交叉
       驗證解析解。離散取樣一定會低估，所以正確的關係是：
         暴力解 <= 解析解，且取樣加密時暴力解會往解析解收斂。 */
    function bruteLimit(raw, nPhi, nRing) {
        var p = norm(raw);
        var g = p.tilt * Math.PI / 180, cg = Math.cos(g), sg = Math.sin(g);
        var cy = p.Rr + p.Rw, h = p.Tw / 2;
        var ta = Math.tan(p.pa * Math.PI / 180), r1 = Math.max(p.Rw - p.Hf, 0);

        /* 砂輪/刀具外側表面的 (rho, t)：刃帶整段 + 兩側斜面整段，都密集切 */
        var rings = [], i;
        for (i = 0; i <= nRing; i++) rings.push([p.Rw, -h + p.Tw * i / nRing]);
        for (i = 1; i <= nRing; i++) {
            var rho = p.Rw - (p.Rw - r1) * i / nRing;
            var tt  = h + (p.Rw - rho) * ta;
            rings.push([rho, tt]); rings.push([rho, -tt]);
        }

        var best = -Infinity;
        for (var k = 0; k < nPhi; k++) {
            var th = k / nPhi * 2 * Math.PI, c = Math.cos(th), s = Math.sin(th);
            for (var j = 0; j < rings.length; j++) {
                var w = placed([rings[j][0] * c, rings[j][0] * s, rings[j][1]], cg, sg, 0, cy);
                var r = Math.sqrt(w[1] * w[1] + w[2] * w[2]);
                if (r <= p.Rs && -w[0] > best) best = -w[0];
            }
        }
        return best;
    }

    function selfTest() {
        var out = [], pass = 0, total = 0;
        function ck(name, cond, detail) {
            total++; if (cond) pass++;
            out.push((cond ? '  PASS  ' : '  FAIL  ') + name + (detail ? '   ' + detail : ''));
        }

        out.push('【一】傾角 0 時必須與現有 2D 公式完全相同');
        [   { Dw:200, Dr:40,  Ds:70,  Tw:6,  tilt:0 },
            { Dw:150, Dr:30,  Ds:46,  Tw:4,  tilt:0 },
            { Dw:300, Dr:120, Ds:160, Tw:10, tilt:0 },
            { Dw:200, Dr:40,  Ds:42,  Tw:6,  tilt:0 },
            { Dw:200, Dr:40,  Ds:70,  Tw:6,  tilt:0, pa:15, Hf:8 },
            { Dw:60,  Dr:20,  Ds:28,  Tw:3,  tilt:0 }
        ].forEach(function (c, i) {
            var r = analyze(c);
            if (!r.ok) { ck('案例' + (i+1), false, r.err); return; }
            var d = Math.abs(r.tail - r.tail2d);
            ck('案例' + (i+1) + ' Dw' + c.Dw + ' Dr' + c.Dr + ' Ds' + c.Ds,
               d < 1e-9,
               '解析=' + r.tail.toFixed(9) + '  2D公式=' + r.tail2d.toFixed(9)
             + '  差=' + d.toExponential(1));
        });

        out.push('【二】傾角要真的被算進去（沒差異就表示傾角沒有作用）');
        var t0 = analyze({ Dw:200, Dr:40, Ds:70, Tw:6, tilt:0 });
        [10, 20, 30, -20].forEach(function (tg) {
            var r = analyze({ Dw:200, Dr:40, Ds:70, Tw:6, tilt:tg });
            ck('傾角 ' + tg + '°', Math.abs(r.tail - t0.tail) > 1e-4,
               '出尾=' + r.tail.toFixed(4) + '（0° 時 ' + t0.tail.toFixed(4) + '）');
        });

        out.push('【三】與獨立的暴力撒點驗算交叉比對（暴力解應 <= 解析解並收斂）');
        [   { Dw:200, Dr:40, Ds:70, Tw:6, tilt:0 },
            { Dw:200, Dr:40, Ds:70, Tw:6, tilt:20 },
            { Dw:200, Dr:40, Ds:70, Tw:6, tilt:-30 },
            { Dw:250, Dr:60, Ds:95, Tw:8, tilt:15, pa:12, Hf:10 }
        ].forEach(function (c) {
            /* 判準刻意是「收斂行為」而不是一個隨意的容差：離散取樣必定低估，
               所以 ①暴力解不可以超過解析解 ②取樣加密 10 倍，殘差要縮小 3 倍
               以上（實測是 5~10 倍，符合 O(1/N)）。用固定容差的話門檻訂多少
               都是憑感覺，而且傾角越大誤差越大，一定會誤判成程式錯。 */
            var a = analyze(c).tail;
            var b1 = bruteLimit(c, 2000, 12), b2 = bruteLimit(c, 20000, 12);
            var e1 = a - b1, e2 = a - b2;
            ck('傾角 ' + (c.tilt || 0) + '°  pa' + (c.pa || 0),
               b1 <= a + 1e-9 && b2 <= a + 1e-9 && e2 > -1e-9 && e1 / Math.max(e2, 1e-12) >= 3,
               '解析=' + a.toFixed(6) + '  暴力2千殘差=' + e1.toExponential(2)
             + '  暴力2萬殘差=' + e2.toExponential(2)
             + '  縮小=' + (e1 / Math.max(e2, 1e-12)).toFixed(1) + '倍');
        });

        out.push('【四】治具干涉（使用者截圖的實例：砂輪/刀具 Ø300、治具 Ø118x50 + Ø90x30）');
        var FXC = { Dw:300, Dr:40, Ds:70, Tw:6, Lsh:20, Lsp:80, tilt:0,
                    fx:[{d:118,len:50},{d:90,len:30}] };
        var rF = analyze(FXC);
        var rN = analyze({ Dw:300, Dr:40, Ds:70, Tw:6, Lsh:20, Lsp:80, tilt:0 });
        ck('有治具時障礙物 = 肩部 + 2 段', rF.p.obs.length === 3,
           rF.p.obs.map(function (o) { return o.name + '(Ø' + (o.R*2) + ')'; }).join(' '));
        ck('治具段的軸向位置接在肩部外端往外排',
           Math.abs(rF.p.obs[1].x1 - (-20)) < 1e-9 && Math.abs(rF.p.obs[1].x0 - (-70)) < 1e-9
             && Math.abs(rF.p.obs[2].x1 - (-70)) < 1e-9 && Math.abs(rF.p.obs[2].x0 - (-100)) < 1e-9,
           '第1段 -70~-20、第2段 -100~-70');
        ck('治具把極限位置往外推（比只有肩部時遠）', rF.cxLimit > rN.cxLimit + 1e-6,
           '只有肩部=' + rN.cxLimit.toFixed(3) + '  加治具=' + rF.cxLimit.toFixed(3));
        ck('會指出是治具在卡，不是肩部', rF.bind && rF.bind.kind === 'fixture',
           '最先碰到 = ' + (rF.bind ? rF.bind.name : '(無)'));
        /* 徑向間隙與軸向餘裕必須同時為 0：這是兩個數字一致性的關鍵檢查 */
        var atLim = { p: rF.p, cy: rF.cy, cg: rF.cg, sg: rF.sg, rings: rF.rings, reach: rF.reach };
        var gapAtLimit = radialGap(atLim, rF.bind, rF.cxLimit);
        ck('在極限位置時，卡住那一段的徑向間隙恰為 0', Math.abs(gapAtLimit) < 1e-3,
           '徑向間隙 = ' + gapAtLimit.toExponential(2));
        var gapOut = radialGap(atLim, rF.bind, rF.cxLimit + 10);
        ck('砂輪/刀具往外退 10mm 後徑向間隙應為正', gapOut > 0, '徑向間隙 = ' + gapOut.toFixed(4) + ' mm');
        /* 空的治具列要被忽略，不可以當成 Ø0 的障礙物 */
        var rE = analyze({ Dw:300, Dr:40, Ds:70, Tw:6, Lsh:20, Lsp:80,
                           fx:[{d:'',len:''},{d:0,len:50},{d:118,len:0}] });
        ck('沒填完的治具列被忽略', rE.p.obs.length === 1 && Math.abs(rE.cxLimit - rN.cxLimit) < 1e-9);

        out.push('【五】工件往小的縮（肩部比齒底徑小）一樣要算得出來，不可以擋下');
        /* 2026-10-12 使用者回報「一直卡在一定要肩部>齒底徑，不然無法計算」。
           這是他的實際案例：齒底徑 Ø118、肩部只有 Ø90（這一側根本沒有肩部），
           治具 Ø105x105 + Ø178x53。 */
        var UC = { Dw:300, Dr:118, Ds:90, Lsh:105, Lsp:50, U:10, tilt:0,
                   fx:[{d:105,len:105},{d:178,len:53}] };
        var rU = analyze(UC);
        ck('算得出來（不再被擋下）', rU.ok === true && !rU.err, rU.err || '(無錯誤)');
        ck('有提醒說明為什麼沒有肩部', (rU.notes || []).length > 0,
           (rU.notes || []).join(' / '));
        ck('比齒底徑小的那幾塊被判成碰不到',
           !isFinite(rU.p.obs[0].cxLimit) && !isFinite(rU.p.obs[1].cxLimit),
           '肩部Ø90 與 治具第1段Ø105 皆 < 齒底徑Ø118');
        ck('只有 Ø178 那一段算得出極限位置', isFinite(rU.p.obs[2].cxLimit),
           '治具第2段 cxLimit = ' + rU.p.obs[2].cxLimit.toFixed(3));
        ck('極限位置為負＝砂輪/刀具可以越過肩部端面，出尾長度算 0',
           rU.cxLimit < 0 && rU.tail === 0 && rU.overrun > 0,
           '極限 ' + rU.cxLimit.toFixed(3) + '　出尾 ' + rU.tail
             + '　可越過 ' + rU.overrun.toFixed(3) + ' mm');
        ck('沒有肩部時 2D 公式顯示為不適用', rU.tail2d === null);

        out.push('【六】其他原本被誤擋的情形');
        /* 肩部直徑「恰等於」齒底徑是邊界情形：砂輪/刀具最深那一點剛好擦到它，所以
           cxLimit 會算出 0（有限值，不是碰不到），出尾長度 0＝沒有任何限制。
           第一版斷言寫成「碰不到」是把邊界當成一般情形，判錯的是斷言不是程式。 */
        var rEq = analyze({ Dw:200, Dr:40, Ds:40, Tw:6 });
        /* 容差用 1e-3 mm（1 微米）：這個邊界情形的根恰好落在掃描起點 φ=270° 上，
           粗掃＋二分只能收斂到 1e-6 mm 等級（1 奈米），對加工完全不相干，
           用 1e-6 當判準只是在考驗數值解析度不是在驗正確性。 */
        ck('肩部直徑等於齒底徑：算得出來且沒有出尾限制',
           rEq.ok && Math.abs(rEq.cxLimit) < 1e-3 && rEq.tail < 1e-3,
           '極限 ' + rEq.cxLimit.toExponential(2) + ' mm　出尾 ' + rEq.tail.toExponential(2) + ' mm');
        /* 肩部比砂輪/刀具半徑還高：砂輪/刀具中心恆在 Rr+Rw，照樣磨得到齒底徑，只是出尾變長。
           原本這裡會回「這支砂輪/刀具在幾何上進不到齒底徑」，那句話是錯的。 */
        var rT = analyze({ Dw:20, Dr:10, Ds:40, Tw:2 });
        ck('肩部高度大於砂輪/刀具半徑：算得出來', rT.ok && rT.tail > 0,
           '出尾 = ' + rT.tail.toFixed(4) + ' mm（砂輪/刀具半徑 10、肩部高出 15）');
        /* 肩部比整個砂輪/刀具還高（H > 2Rw）：整個砂輪/刀具都在肩部半徑之內，答案是
           「砂輪/刀具必須整個待在肩部端面右側」，cxLimit 約等於砂輪/刀具半徑。 */
        var rH = analyze({ Dw:20, Dr:10, Ds:60, Tw:2 });
        ck('肩部高過整個砂輪/刀具：砂輪/刀具必須整個退到端面右側',
           rH.ok && Math.abs(rH.tail - 10) < 0.5,
           '出尾 = ' + rH.tail.toFixed(4) + ' mm（砂輪/刀具半徑 10）');
        ck('極小肩部（H=0.1）仍算得出來',
           analyze({ Dw:200, Dr:40, Ds:40.2, Tw:6 }).ok);

        out.push('── 通過 ' + pass + ' / ' + total + ' 項');
        return out.join('\n');
    }

    return {
        mount: mount, open: open, close: close,
        analyze: analyze, at: at, selfTest: selfTest,
        /* 給自我測試與瀏覽器端驗收用：目前的面板狀態與已載入的預設清單 */
        state: function () { return ST; }, _presets: function () { return PRESETS; },
        _kv: function () { return KV; },
        _vw: function () { return VW; },
        DEF: DEF, _norm: norm, _obsSD: obsSD, _placed: placed, _radialGap: radialGap,
        _brute: bruteLimit, _wpProfile: wpProfile, _whProfile: whProfile,
        _buildModel: buildModel
    };
})();

function openGrind3D(params) { EGGrind3D.open(params); }
</script>
<?php endif; ?>
