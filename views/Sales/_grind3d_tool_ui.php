<?php
// c:\MAMP\htdocs\EGsystem\views\Sales\_grind3d_tool_ui.php
// ── 砂輪干涉 3D 模擬：畫面（CSS＋視窗＋JS）共用檔，唯一實作 ───────────────────
// 2026-10-12 新建（比照 _gear_tool_ui.php／_keyway_tool_ui.php 的可插拔寫法）。
//
// 用法（任何頁面，兩種都可以）：
//   A) 浮動視窗：$GRIND3D_TOOL_PDO = $pdo; include __DIR__.'/_grind3d_tool_ui.php';
//      再放一顆 <button onclick="openGrind3D()">砂輪干涉3D</button>
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
//   OV.Node.SetTransformation(矩陣)        擺位與傾角（砂輪傾角走這裡，不必重算三角形）
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

if (!isset($can_grind3d)) {
    $__g3_pdo = null;
    foreach ([$GRIND3D_TOOL_PDO ?? null, $pdo ?? null, $db ?? null] as $__g3_c) {
        if ($__g3_c instanceof PDO) { $__g3_pdo = $__g3_c; break; }
    }
    $can_grind3d = grind3d_tool_can_use($__g3_pdo, intval($_SESSION['id'] ?? 0));
}
$__g3_dir = __DIR__ . '/../../resource/js/';
?>
<?php if ($can_grind3d): ?>
<style>
/* ── 砂輪干涉 3D 模擬 ─────────────────────────────────────────────────────
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
.g3d-badhint { font-size:11px; color:var(--g3d-bad); font-weight:600;
          line-height:1.5; margin:2px 0 6px; display:none; }

.g3d-slider { display:flex; align-items:center; gap:6px; margin-bottom:5px; }
.g3d-slider > label { flex:0 0 112px; font-size:12px; }
.g3d-slider > input[type=range] { flex:1 1 auto; min-width:0; accent-color:var(--g3d-amber); }
.g3d-slider > span { flex:0 0 58px; font-size:11px; text-align:right;
          font-variant-numeric:tabular-nums; }

.g3d-btn { border:none; border-radius:4px; padding:5px 12px; font-size:12px;
          font-weight:600; cursor:pointer; font-family:inherit; line-height:1.5; }
.g3d-btn-main { background:var(--g3d-amber); color:#fff; }
.g3d-btn-main:hover { background:#b06a26; }
.g3d-btn-o { background:#fff; color:#8a5a22; border:1px solid #d8c3a4; }
.g3d-btn-o:hover { background:#FBF2E6; }
.g3d-btn-o.g3d-on { background:var(--g3d-amber); color:#fff; border-color:var(--g3d-amber); }
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
.g3d-kv { display:grid; grid-template-columns:1fr auto; gap:0;
          background:#fff; font-size:12px; }
.g3d-kv > div { padding:4px 10px; border-top:1px solid #F0E4D2; line-height:1.5; }
.g3d-kv > div:nth-child(2n) { text-align:right; font-variant-numeric:tabular-nums;
          font-weight:600; }
.g3d-kv > div:nth-child(1), .g3d-kv > div:nth-child(2) { border-top:none; }
.g3d-kv .g3d-k-sub { color:#8a7560; font-size:11px; }

/* ── 浮動視窗（方式 A）───────────────────────────────────────────────── */
#g3d-win { position:fixed; z-index:10400; display:none;
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
</style>

<!-- ── 浮動視窗（方式 A；嵌入式用 EGGrind3D.mount() 不經過這裡）──────────── -->
<div id="g3d-win">
    <div id="g3d-hdr">
        <i class="fa fa-circle-o-notch"></i><span>砂輪干涉 3D 模擬</span>
        <span class="g3d-sp"></span>
        <button type="button" onclick="EGGrind3D.close()">關閉</button>
    </div>
    <div id="g3d-body"></div>
</div>

<script src="../../resource/js/o3dv.min.js"></script>
<script src="../../resource/js/eg_3d_viewer_tools.js?v=<?= @filemtime($__g3_dir.'eg_3d_viewer_tools.js') ?>"></script>
<script>
/* ══════════════════════════════════════════════════════════════════════════
   砂輪干涉 3D 模擬（唯一實作）
   ── 座標系（整支程式都用這一套，不要另外換算）──────────────────────────
     工件軸 = X 軸；肩部端面固定在 x=0；齒（花鍵）段往 +X；肩部往 -X。
     半徑 r = sqrt(y²+z²)；砂輪在工件上方（+Y）。
     砂輪局部座標的迴轉軸 = Z（與工件軸垂直）；傾角 = 繞世界 Y 軸旋轉。
     這一套刻意與齒輪計算工具「出尾計算」的 2D 剖面圖同一個方向（肩部在左、
     齒段在右、肩部面在原點），兩邊的數字才對得起來。

   ── 干涉是怎麼算的（這段是這個工具可不可信的關鍵）──────────────────────
     工件是迴轉體，所以只要把砂輪表面的點投影回 (軸向 x, 半徑 r) 平面，判斷
     落不落在「肩部實體」這個矩形內即可——不管砂輪怎麼傾斜都一樣正確。
     禁區 = 肩部實體 = { -Lsh <= x <= 0 , r <= Rs }。
     砂輪侵入齒段（x >= 0 且 r <= Rr）是正常的，那就是在磨溝槽，不算干涉；
     真正不可以碰到的是肩部。3D 上這兩種用不同顏色分開，不要混在一起。

   ── 為什麼不用「在砂輪表面撒點掃描」的做法（第一版踩過，必須記住）────────
     第一版是對砂輪表面撒 2700 個點、再用二分法找極限位置。寫完跑自我驗證就
     發現傾角 0 時算出 50.000，而現有 2D 公式是 52.678，**差 2.7mm（5%）**。
     根因是圓周只取樣 180 等分（每 2°），臨界角 sin(phi) = -0.85（phi =
     238.21°、cos = -0.5268）永遠不會剛好被取樣到，實際命中的是 240°
     （cos = -0.5），於是答案被低估成 100 x 0.5 = 50。這種誤差對干涉判斷不能
     接受，而把取樣加密 20 倍也只是把誤差變小、不會變成 0，還拖慢拉桿連動。
     改成下面的解析解之後，傾角 0 的結果與 2D 公式**完全相同（差 < 1e-12）**。

   ── 解析解的推導（三個關鍵觀察）─────────────────────────────────────────
     ①「砂輪中心離工件軸心的距離 cy」與傾角無關，恆 = Rr + Rw。
        因為砂輪上任何一點距工件軸心至少 cy - Rw（砂輪半徑就是 Rw），而這個
        下界在 phi=270°、t=0 處真的取到，所以「磨到齒底徑」就是 cy - Rw = Rr。
     ② 砂輪最深那一點恆在 phi=270°、t=0，該點的局部 x = Rw*cos(270°) = 0，
        所以它的世界軸向座標恆等於 cx。因此「溝槽達到全深的位置」＝ cx，
        出尾長度 = 極限位置 cxLimit，不必另外再找最深點。
     ③ 極限位置可以逐「圈」解析求出，不必對整個表面取樣：
        砂輪是迴轉體，表面由一圈一圈的圓構成，每一圈用 (rho, t) 標定
        （rho = 離砂輪軸的半徑、t = 沿砂輪軸的位置）。對某一圈：
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

    /* 預設值刻意挑一組「齒段長度 > 出尾長度」的案例：Ø200 砂輪配 Ø40 齒底徑算出來的
       出尾是 52.7mm，齒段若只有 40mm，砂輪的切點會落在工件末端之外，一打開看到的
       第一張圖就很難讀（而且那其實代表這支砂輪根本磨不動這段齒）。 */
    var DEF = { Dw:200, Tw:6, pa:0, Hf:10, tilt:0, Dr:40, Ds:70, Lsh:20, Lsp:80, U:0.5 };

    var SEG = 96;            /* 顯示用迴轉體的圓周分段 */
    var EPS = 1e-9;
    var N_FLANK = 10;         /* 砂輪側面往內徑取幾圈（只影響「哪些圈參與判定」）*/
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
        p.Hf = Math.min(p.Hf, p.Rw * 0.9);        /* 刃高不可超過砂輪半徑 */
        p.Tb = p.Tw + 2 * p.Hf * Math.tan(p.pa * Math.PI / 180);  /* 砂輪本體厚度 */
        return p;
    }

    function fx(v, n) {
        if (v === null || v === undefined || !isFinite(v)) return '—';
        return v.toFixed(n === undefined ? 3 : n);
    }

    /* ── 可不可以算（算不出來要講清楚原因，不要畫一張看起來對的圖）──────── */
    function why(p) {
        if (p.Rs <= p.Rr + EPS)
            return '肩部直徑必須大於齒底徑，否則沒有肩部、也就沒有出尾問題。';
        if (p.H >= p.Rw - EPS)
            return '肩部高出齒底徑 ' + fx(p.H) + ' mm，已達砂輪半徑 ' + fx(p.Rw)
                 + ' mm，這支砂輪在幾何上進不到齒底徑。';
        return '';
    }

    /* ══ 一、工件（迴轉體輪廓，局部座標 (r, z)，z 即軸向）══════════════════
       頭尾都落在軸線 r=0，所以迴轉後是封閉實體。 */
    function wpProfile(p) {
        return [[0,-p.Lsh],[p.Rs,-p.Lsh],[p.Rs,0],[p.Rr,0],[p.Rr,p.Lsp],[0,p.Lsp]];
    }

    /* ══ 二、砂輪（迴轉體輪廓，局部座標 (rho, t)，t 即砂輪軸向）════════════
       外徑處刃寬 Tw，往內徑在「刃高 Hf」範圍內每側以側面角 pa 張開，再往內
       就維持本體厚度 Tb 不再變寬（實際修整過的砂輪就是這個形狀；若讓側面角
       一路張到輪轂，大直徑砂輪會算出幾十 mm 的荒謬厚度）。 */
    function whProfile(p) {
        var h = p.Tw / 2, r1 = Math.max(p.Rw - p.Hf, 0);
        var ta = (p.Hf > EPS && p.pa > EPS) ? Math.tan(p.pa * Math.PI / 180) : 0;
        /* 某個半徑處的半厚度 */
        function ht(rho) { return rho >= r1 ? h + (p.Rw - rho) * ta : p.Tb / 2; }

        /* 側面一定要沿半徑細分，不可以用一片從 ρ=0 拉到 ρ=Rw 的大三角形：
           干涉染色是「三角形只要有一個頂點在肩部裡就整片染紅」，一片橫跨中心到
           外徑的三角形會讓紅色從外緣一路延伸到砂輪中心（2026-10-12 截圖實測看到
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
       刻意只取外側（rho 從 Rw 往內到 Rw-Hf）：再往內的砂輪本體離工件軸心更遠
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

    /* ══ 四、單一圈能逼近肩部到哪裡（解析解，見檔頭推導③）══════════════════
       回傳這一圈要求的 cx 下限；-Infinity＝這一圈永遠碰不到肩部。 */
    function ringLimit(rho, t, p, cg, sg, cy) {
        var Rs2 = p.Rs * p.Rs;
        function r2(th) {
            var a = rho * Math.sin(th) + cy;
            var b = rho * Math.cos(th) * sg - t * cg;
            return a * a + b * b;
        }
        function cand(th) { return -(rho * Math.cos(th) * cg + t * sg); }

        var mid = 1.5 * Math.PI;              /* phi = 270°：砂輪最深的那一點 */
        if (r2(mid) > Rs2) return -Infinity;  /* 連最深處都碰不到肩部 */

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
        if (hi === null) return cand(Math.PI);   /* 整個分支都可行，極值在 180° */
        for (var k = 0; k < BISECT; k++) {
            var m = (lo + hi) / 2;
            if (r2(m) <= Rs2) lo = m; else hi = m;
        }
        return cand(lo);
    }

    /* ══ 五、擺位：繞世界 Y 軸傾斜 tilt，再平移到 (cx, cy, 0)═══════════════
       world = R_y(tilt) * local + (cx, cy, 0)
       這必須與下面傳給 OV.Node.SetTransformation 的矩陣是同一個轉換，否則
       「算出來的干涉」與「畫出來的位置」會對不起來，而且畫面上完全看不出來。 */
    function placed(lp, cg, sg, cx, cy) {
        var x = lp[0], y = lp[1], z = lp[2];
        return [ x * cg + z * sg + cx,  y + cy,  -x * sg + z * cg ];
    }

    /* ══ 六、禁區（肩部實體）的有號距離 ═══════════════════════════════════
       在 (軸向 x, 半徑 r) 平面上，肩部就是矩形 x in [-Lsh,0]、r in [0,Rs]。
       回傳 >0 = 侵入深度；<=0 = 在外面（絕對值＝到肩部的距離）。
       只用在「3D 上哪些三角形要塗紅色」，數字結論一律用上面的解析解。 */
    function shoulderSD(x, r, p) {
        var dxL = -p.Lsh - x, dxR = x, dr = r - p.Rs;
        if (dxL <= 0 && dxR <= 0 && dr <= 0) return Math.min(-dxL, -dxR, -dr);
        var ox = Math.max(dxL, 0, dxR), oy = Math.max(dr, 0);
        return -Math.sqrt(ox * ox + oy * oy);
    }

    /* ══ 七、主分析 ════════════════════════════════════════════════════════ */
    function analyze(raw) {
        var p = norm(raw), msg = why(p);
        var out = { p: p, ok: false, err: msg };
        if (msg) return out;

        var g = p.tilt * Math.PI / 180, cg = Math.cos(g), sg = Math.sin(g);
        var cy = p.Rr + p.Rw;          /* 解析解，與傾角無關（檔頭推導①）*/

        var rings = wheelRings(p), lim = -Infinity, i;
        for (i = 0; i < rings.length; i++) {
            var v = ringLimit(rings[i][0], rings[i][1], p, cg, sg, cy);
            if (v > lim) lim = v;
        }
        if (!isFinite(lim)) lim = 0;   /* 理論上不會發生（最深點恆可達齒底徑）*/

        out.ok      = true;
        out.cy      = cy;
        out.cg      = cg;
        out.sg      = sg;
        out.rings   = rings;
        out.cxLimit = lim;
        out.tail    = lim;             /* 最深點恆在 x = cx（檔頭推導②）*/
        out.tailTot = lim + p.U;
        out.tail2d  = Math.sqrt(Math.max(p.Rw * p.Rw - (p.Rw - p.H) * (p.Rw - p.H), 0));
        out.tail2dT = out.tail2d + p.U;
        out.diff    = out.tail - out.tail2d;
        return out;
    }

    /* 指定砂輪位置時的狀況。
       刻意回報「軸向餘裕」而不是 3D 最小距離：①它與 cxLimit 由同一個解析解
       推出來，畫面上的「干涉/不干涉」永遠不會跟出尾長度自相矛盾 ②現場要的是
       「還能再靠近幾 mm／必須退幾 mm」這個可以直接拿去用的數字。 */
    function at(res, cx) {
        var m = cx - res.cxLimit;
        return { hit: m < 0, margin: m, pen: m < 0 ? -m : 0, clr: m >= 0 ? m : 0 };
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

    /* ══ 九、組出整個 OV.Model（工件 + 砂輪）════════════════════════════════
       工件沿局部 Z 迴轉後，用 R_y(90°) 把局部 Z 轉成世界 X（＝工件軸）。
       砂輪的局部 Z 本來就是它自己的迴轉軸，傾角 0 時正好對上世界 Z。 */
    function buildModel(res, cx, wpOpacity) {
        var p = res.p;
        var model = new OV.Model();
        var mWp   = model.AddMaterial(phong(214, 198, 176, wpOpacity));   /* 工件：砂色 */
        var mBody = model.AddMaterial(phong(150, 150, 155));              /* 砂輪本體：灰 */
        var mHit  = model.AddMaterial(phong(221,  81,  56));              /* 干涉肩部：紅 */
        /* 原本還有一個「正在切削齒段」的琥珀色，已移除：工件畫的是**加工完成後**的
           齒底徑，砂輪在正確磨削位置只會與齒底圓相切、不會侵入，所以那個顏色實際上
           永遠不會出現（實測染色統計確認 0 個三角形）。留著等於在畫面上承諾一個不會
           發生的狀態。 */

        /* 砂輪每個頂點屬於哪一類：先依節點轉換算出世界座標再判定，與 analyze()
           用的是同一支 placed()，所以「畫出來的紅色」與「算出來的干涉」一定一致。 */
        function classify(lx, ly, lz) {
            var w = placed([lx, ly, lz], res.cg, res.sg, cx, res.cy);
            var r = Math.sqrt(w[1] * w[1] + w[2] * w[2]);
            return shoulderSD(w[0], r, p) > 0 ? mHit : mBody;
        }

        var wp = revolveZ(wpProfile(p), SEG, mWp, '工件', null);
        var iw = model.AddMesh(wp);
        var nw = new OV.Node();
        nw.AddMeshIndex(iw);
        nw.SetTransformation(new OV.Transformation(mtx(0, 1, 0, 0, 0)));  /* R_y(90°) */
        model.GetRootNode().AddChildNode(nw);

        var wh = revolveZ(whProfile(p), SEG, mBody, '砂輪', classify);
        var ih = model.AddMesh(wh);
        var nh = new OV.Node();
        nh.AddMeshIndex(ih);
        nh.SetTransformation(new OV.Transformation(mtx(res.cg, res.sg, cx, res.cy, 0)));
        model.GetRootNode().AddChildNode(nh);

        OV.FinalizeModel(model, function () { return new OV.RGBColor(180, 180, 185); });
        return model;
    }

    /* ══ 十、檢視器 ════════════════════════════════════════════════════════ */
    /* eg_3d_viewer_tools.js 在本檔之前載入，所以正常情況下一定抓得到；抓不到
       （檔案 404）時降級成「只有 3D 與計算、沒有剖面量測截圖」，不要讓整支
       工具因為工具列少一個功能就完全不能用。 */
    var T3 = (typeof EG3DTools !== "undefined" && EG3DTools) ? EG3DTools : null;

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
                       （工件軸）、Y 垂直（砂輪在上方），與齒輪計算工具的 2D 出尾
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
        ['Dw',   '砂輪外徑',        'mm', '砂輪的外徑，出尾長度主要由它決定（越大出尾越長）'],
        ['Tw',   '砂輪刃寬',        'mm', '外徑處的厚度（修整後的刃口寬度）'],
        ['pa',   '砂輪側面角',      '°',  '單側角度。0＝兩側平行的圓盤砂輪'],
        ['Hf',   '刃高',            'mm', '側面角往內徑作用的高度，再往內就維持本體厚度不再變寬'],
        ['tilt', '砂輪傾角',        '°',  '砂輪軸相對工件軸的傾斜（磨螺旋齒時的擺角）。這一欄不為 0 時，2D 公式就不再成立'],
        ['Dr',   '工件齒底徑',      'mm', '加工完成後的齒根圓直徑'],
        ['Ds',   '肩部直徑',        'mm', '砂輪不可以碰到的那一段大徑'],
        ['Lsh',  '肩部長度',        'mm', '肩部往軸向延伸多長（只影響畫面，不影響出尾長度）'],
        ['Lsp',  '齒段長度',        'mm', '齒（花鍵）段往另一側延伸多長'],
        ['U',    '退刀量',          'mm', '另外加在幾何出尾長度上的安全量']
    ];

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function panelHtml() {
        var h = ['<div class="g3d-root">'];
        h.push('<div class="g3d-left">');

        h.push('<div class="g3d-sec"><h4>砂輪</h4>');
        NUMF.slice(0, 5).forEach(function (f) { h.push(fieldHtml(f)); });
        h.push('</div>');

        h.push('<div class="g3d-sec"><h4>工件</h4>');
        NUMF.slice(5, 9).forEach(function (f) { h.push(fieldHtml(f)); });
        h.push('</div>');

        h.push('<div class="g3d-sec"><h4>其他</h4>');
        h.push(fieldHtml(NUMF[9]));
        h.push('</div>');

        h.push('<div class="g3d-badhint" id="g3dErr"></div>');

        h.push('<div class="g3d-sec"><h4>砂輪位置</h4>'
            + '<div class="g3d-slider"><label>軸向位置</label>'
            + '<input type="range" id="g3dCx"><span id="g3dCxV">—</span></div>'
            + '<div class="g3d-hint">砂輪中心到肩部端面的軸向距離。往左拖會壓進肩部，'
            + '壓到的部分在 3D 上以紅色標示。</div>'
            + '<button type="button" class="g3d-btn g3d-btn-main" id="g3dToLimit" '
            + 'style="width:100%">移到不干涉的極限位置</button>'
            + '</div>');

        h.push('<div class="g3d-sec"><h4>顯示</h4>'
            + '<div class="g3d-row"><label style="flex:1 1 auto">'
            + '<input type="checkbox" id="g3dTrans" checked> 工件半透明</label></div>'
            + '<div class="g3d-hint">干涉時砂輪是埋在肩部裡面的，工件不透明就看不到紅色區域，'
            + '所以預設半透明。要看實體外觀再取消勾選。</div>'
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
            + '<div class="g3d-canvas-msg" id="g3dCanvasMsg">正在建立 3D 模型…</div></div>');

        h.push('<div class="g3d-tools">'
            + '<span style="font-size:11px;color:#8a7560">視角</span>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="iso">等角</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="front">前</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="top">上</button>'
            + '<button type="button" class="g3d-btn g3d-btn-o" data-g3dview="right">右</button>'
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

    function readParams() {
        var o = {};
        NUMF.forEach(function (f) {
            var el = $1('#g3d_' + f[0]);
            o[f[0]] = el ? el.value : DEF[f[0]];
        });
        return o;
    }

    function setParams(o) {
        NUMF.forEach(function (f) {
            var el = $1('#g3d_' + f[0]);
            if (el) el.value = (o && o[f[0]] !== undefined) ? o[f[0]] : DEF[f[0]];
        });
    }

    /* 重新分析（改參數時呼叫）：重算極限位置，並把砂輪移到極限位置 */
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
            ['Ds','Dr','Dw'].forEach(function (k) {
                var el = $1('#g3d_' + k); if (el) el.classList.add('g3d-err');
            });
            var v = $1('#g3dVerdict');
            if (v) { v.className = 'g3d-verdict g3d-v-na'; v.textContent = '無法計算'; }
            var kv = $1('#g3dKv'); if (kv) kv.innerHTML = '';
            return;
        }
        if (err) err.style.display = 'none';

        /* 拉桿範圍：要涵蓋「壓進肩部」與「完全脫離」兩側，才看得出變化 */
        var p = res.p;
        var lo = -(p.Tb / 2 + Math.max(res.cxLimit * 0.35, 2));
        var hi = Math.max(res.cxLimit * 1.6, p.Rw * 0.4) + p.Tb;
        var sl = $1('#g3dCx');
        if (sl) {
            sl.min = lo.toFixed(4); sl.max = hi.toFixed(4);
            sl.step = ((hi - lo) / 600).toFixed(5);
            if (!keepCx || ST.cx < lo || ST.cx > hi) ST.cx = res.cxLimit;
            sl.value = ST.cx;
        }
        draw(false);
    }

    /* 重畫（改砂輪位置時呼叫，不重算極限位置）*/
    function draw(refit) {
        var res = ST.res;
        if (!res || !res.ok) return;
        var tr = $1('#g3dTrans');
        var model = buildModel(res, ST.cx, (tr && tr.checked) ? 0.42 : 1);
        var msg = $1('#g3dCanvasMsg');
        pushToViewer(model, $1('#g3dCanvas'), refit, function (ok) {
            if (msg) msg.style.display = ok ? 'none' : 'flex';
            if (!ok && msg) msg.textContent = '3D 檢視器載入失敗（o3dv.min.js 未載入）';
            /* 剖面開著的時候，模型換掉之後材質是全新的、裁切面會不見，要重新套一次 */
            if (ok && ST.sec) applySection();
        });
        showResult();
    }

    function showResult() {
        var res = ST.res, p = res.p;
        var a = at(res, ST.cx);
        var sl = $1('#g3dCxV');
        if (sl) sl.textContent = fx(ST.cx, 2);
        var v = $1('#g3dVerdict');
        if (v) {
            if (a.hit) {
                v.className = 'g3d-verdict g3d-v-bad';
                v.innerHTML = '<i class="fa fa-times-circle"></i> 會干涉：砂輪必須往外退 '
                            + fx(a.pen) + ' mm 才不會碰到肩部';
            } else {
                v.className = 'g3d-verdict g3d-v-ok';
                v.innerHTML = '<i class="fa fa-check-circle"></i> 不干涉：還可以再往肩部靠近 '
                            + fx(a.clr) + ' mm';
            }
        }
        var d2 = res.diff;
        var rows = [
            ['砂輪中心軸向位置',                      fx(ST.cx) + ' mm'],
            ['不干涉的極限位置',                      fx(res.cxLimit) + ' mm'],
            ['<b>出尾長度</b><span class="g3d-k-sub">（本工具，含傾角 '
              + fx(p.tilt, 1) + '°）</span>',         '<b>' + fx(res.tail) + ' mm</b>'],
            ['出尾長度＋退刀量 ' + fx(p.U, 2) + ' mm', fx(res.tailTot) + ' mm'],
            ['2D 公式值<span class="g3d-k-sub">（齒輪計算工具的出尾計算，只在傾角 0 成立）</span>',
                                                      fx(res.tail2d) + ' mm'],
            ['2D 公式低估／高估了',                   (d2 >= 0 ? '+' : '') + fx(d2) + ' mm'],
            ['砂輪中心高度<span class="g3d-k-sub">（距工件軸心）</span>', fx(res.cy) + ' mm'],
            ['肩部高出齒底徑',                        fx(p.H) + ' mm'],
            ['砂輪本體厚度<span class="g3d-k-sub">（含側面角張開）</span>', fx(p.Tb) + ' mm']
        ];
        var kv = $1('#g3dKv');
        if (kv) {
            /* 這裡刻意不過 esc()：rows 的兩欄都是本檔自己組出來的靜態說明與
               fx() 產生的數字，沒有任何使用者輸入會流進來，而說明欄要帶 <b>
               與 <span class="g3d-k-sub"> 這些標籤。 */
            kv.innerHTML = rows.map(function (r) {
                return '<div>' + r[0] + '</div><div>' + r[1] + '</div>';
            }).join('');
        }
    }

    function applySection() {
        if (!VW || !T3) return;
        if (ST.sec) { try { T3.clearSectionMode(VW, ST.sec); } catch (e) {} }
        ST.sec = T3.setSectionMode(VW, ST.secOpts);
    }

    /* ══ 十三、掛載 ════════════════════════════════════════════════════════ */
    function mount(el, opts) {
        opts = opts || {};
        el.innerHTML = panelHtml();
        ST.root = el;
        ST.host = el;
        setParams(opts.params || DEF);

        /* 參數欄位：打字就重算（防抖 120ms，避免每個按鍵都重建一次模型）*/
        el.querySelectorAll('[data-g3dk]').forEach(function (inp) {
            inp.addEventListener('input', function () {
                clearTimeout(ST.timer);
                ST.timer = setTimeout(function () { recompute(false); }, 120);
            });
        });

        var sl = $1('#g3dCx');
        if (sl) {
            sl.addEventListener('input', function () {
                ST.cx = parseFloat(sl.value);
                clearTimeout(ST.timer);
                ST.timer = setTimeout(function () { draw(false); }, 40);
            });
        }
        var tl = $1('#g3dToLimit');
        if (tl) tl.addEventListener('click', function () {
            if (!ST.res || !ST.res.ok) return;
            ST.cx = ST.res.cxLimit;
            if (sl) sl.value = ST.cx;
            draw(false);
        });
        var tr = $1('#g3dTrans');
        if (tr) tr.addEventListener('change', function () { draw(false); });
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

        var shot = $1('#g3dShot');
        if (shot) shot.addEventListener('click', function () {
            if (!VW || !T3) return;
            var url = T3.screenshot(VW, { width: 1600 });
            var a = document.createElement('a');
            a.href = url;
            a.download = '砂輪干涉_' + new Date().toISOString().slice(0, 10) + '.png';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        });

        recompute(false);
        return ST;
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
    /* 獨立的暴力驗算：對砂輪表面密集撒點，直接找「還能不干涉的最小 cx」。
       這是刻意寫的第二套做法（慢、但完全不依賴上面那套解析推導），用來交叉
       驗證解析解。離散取樣一定會低估，所以正確的關係是：
         暴力解 <= 解析解，且取樣加密時暴力解會往解析解收斂。 */
    function bruteLimit(raw, nPhi, nRing) {
        var p = norm(raw);
        var g = p.tilt * Math.PI / 180, cg = Math.cos(g), sg = Math.sin(g);
        var cy = p.Rr + p.Rw, h = p.Tw / 2;
        var ta = Math.tan(p.pa * Math.PI / 180), r1 = Math.max(p.Rw - p.Hf, 0);

        /* 砂輪外側表面的 (rho, t)：刃帶整段 + 兩側斜面整段，都密集切 */
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

        out.push('【四】退化與邊界情形要被擋下、不可以畫出看起來正常的圖');
        ck('肩部直徑 <= 齒底徑', !analyze({ Dr:40, Ds:40 }).ok);
        ck('肩部高度 >= 砂輪半徑', !analyze({ Dw:20, Dr:10, Ds:40 }).ok);
        ck('極小肩部（H=0.1）仍算得出來',
           analyze({ Dw:200, Dr:40, Ds:40.2, Tw:6 }).ok);

        out.push('── 通過 ' + pass + ' / ' + total + ' 項');
        return out.join('\n');
    }

    return {
        mount: mount, open: open, close: close,
        analyze: analyze, at: at, selfTest: selfTest,
        DEF: DEF, _norm: norm, _shoulderSD: shoulderSD, _placed: placed,
        _brute: bruteLimit, _wpProfile: wpProfile, _whProfile: whProfile,
        _buildModel: buildModel
    };
})();

function openGrind3D(params) { EGGrind3D.open(params); }
</script>
<?php endif; ?>
