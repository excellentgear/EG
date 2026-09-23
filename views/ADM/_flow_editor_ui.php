<?php
/**
 * _flow_editor_ui.php — 流程圖編輯器（CSS＋跳窗＋JS，可 include 的共用元件）
 *
 * 用法（呼叫端）：
 *   include __DIR__ . '/_flow_editor_ui.php';        // 放在 </body> 之前
 *   EGFlow.open({ json: <fabric JSON 或 null>, onSave: function(png, json){...} });
 *   ── png 是 dataURL（已放大 2.5 倍，列印才不會鋸齒）、json 是 fabric 工作檔內容。
 *   呼叫端要自己先載入 resource/js/fabric.min.js。
 *
 * 為什麼不直接沿用 views/Sales/image_editor.php：
 *   那一支是「在既有圖面上加註」的工具（載入 BOM 圖當底圖、標籤庫、齒輪計算…共 8000 行），
 *   跟「從零畫一張流程圖」是不同的工具。這裡沿用它已經驗證過的**存檔模式**
 *   （壓平成 PNG 顯示 ＋ 另存 fabric 工作檔 JSON，所以之後還能回來再編輯），
 *   但不去動那個 8000 行的檔案。
 *
 * 配色一律走 ai-rules/10 的暖色系固定調色盤，不給任意取色器。
 */
?>
<style>
/* ── 流程圖編輯器 ───────────────────────────────────────────────────── */
#egfMask{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10500;display:none;}
#egfMask.open{display:block;}
/* 寬度用固定像素，不可用 vw（vw 相對整個瀏覽器視窗，會蓋過左側選單＝記憶 modal_width_convention）。
   max-width 留 160px：1280px 螢幕時跳窗會自己縮到 1120px，不會壓在 70px 寬的側選單上面。 */
#egfWin{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:1180px;
  max-width:calc(100% - 160px);
  background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.35);display:flex;flex-direction:column;
  max-height:94%;}
#egfWin .egf-hd{padding:9px 14px;border-bottom:1px solid #e4d3ba;background:#faf6f0;border-radius:6px 6px 0 0;
  display:flex;align-items:center;gap:10px;}
#egfWin .egf-hd b{color:#4E2C0B;font-size:15px;}
#egfWin .egf-hd .egf-x{margin-left:auto;cursor:pointer;color:#8A5A2B;font-size:20px;line-height:1;}
/* flex:1 1 auto + min-height:0 ＝ 內容區可收縮並自己捲動，
   標題列與頁尾（含「放進文件」）永遠留在畫面上。
   先前寫 flex:0 0 auto，畫布一放大（例如改成 900×700）頁尾就被擠出視窗外，
   使用者會以為「畫完之後沒有按鈕可以插入到文件上」。 */
#egfWin .egf-bd{padding:10px 14px;overflow:auto;background:#efe9e0;flex:1 1 auto;min-height:0;}
#egfWin .egf-ft{padding:9px 14px;border-top:1px solid #e4d3ba;background:#faf6f0;border-radius:0 0 6px 6px;
  display:flex;align-items:center;gap:8px;}
#egfWin .egf-ft .egf-hint{color:#8A5A2B;font-size:12px;}
#egfWin .egf-ft .egf-sp{margin-left:auto;}
.egf-bar{background:#fff;border:1px solid #e4d3ba;border-radius:4px;padding:5px 6px;margin-bottom:9px;
  display:flex;flex-wrap:wrap;align-items:center;gap:3px;}
.egf-btn{border:1px solid #e4d3ba;background:#faf6f0;color:#6B471A;font-size:12px;line-height:24px;height:26px;
  padding:0 8px;border-radius:3px;}
.egf-btn:hover{background:#f2e6d4;}
.egf-btn.on{background:#e8d5b8;border-color:#d8c7b0;}
.egf-btn.egf-danger{color:#DD5138;}
.egf-sep{display:inline-block;width:1px;height:18px;background:#e4d3ba;margin:0 5px;}
.egf-lab{font-size:12px;color:#8A5A2B;margin:0 3px 0 6px;}
.egf-sel{height:26px;border:1px solid #d8c7b0;border-radius:3px;background:#fff;color:#4E2C0B;font-size:12px;}
.egf-num{width:56px;height:26px;border:1px solid #d8c7b0;border-radius:3px;padding:0 4px;font-size:12px;}
.egf-sw{display:inline-block;width:20px;height:20px;border:1px solid #cfc0a8;border-radius:3px;
  vertical-align:middle;cursor:pointer;margin:0 1px;}
.egf-sw.on{outline:2px solid #8A5A2B;}
.egf-canwrap{background:#fff;border:1px solid #d8c7b0;display:inline-block;box-shadow:0 1px 4px rgba(0,0,0,.12);}
/* 畫布放大時不可以把左側面板擠掉，所以面板固定寬、畫布區自己捲動 */
.egf-work{display:flex;gap:10px;align-items:flex-start;}
.egf-canhost{flex:1 1 auto;min-width:0;overflow:auto;}
.egf-pal{flex:0 0 164px;width:164px;background:#fff;border:1px solid #e4d3ba;border-radius:4px;padding:8px;}
.egf-pal-t{font-size:12px;color:#8A5A2B;font-weight:bold;margin:2px 0 5px;}
.egf-tiles{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:9px;}
.egf-tile{width:70px;height:56px;border:1px solid #e4d3ba;border-radius:4px;background:#faf6f0;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:grab;
  -webkit-user-select:none;user-select:none;}
.egf-tile:hover{background:#f2e6d4;border-color:#d8c7b0;}
.egf-tile.on{background:#e8d5b8;border-color:#8A5A2B;}
.egf-tile:active{cursor:grabbing;}
.egf-tile i{font-style:normal;font-size:11px;color:#6B471A;line-height:1;}
.egf-ic{display:block;width:34px;height:18px;border:1.5px solid #6B471A;background:#fff;}
.egf-ic-term{border-radius:9px;}
.egf-ic-judge{width:22px;height:22px;transform:rotate(45deg) scale(.78);}
.egf-ic-doc{transform:skewX(-12deg);}
.egf-ic-text{border:0;background:none;font-size:15px;color:#6B471A;line-height:18px;text-align:center;font-weight:bold;}
.egf-ic-arrow,.egf-ic-line{height:0;border:0;border-top:2px solid #6B471A;width:34px;margin-top:8px;position:relative;}
.egf-ic-arrow:after{content:'';position:absolute;right:-1px;top:-4px;border-left:7px solid #6B471A;
  border-top:4px solid transparent;border-bottom:4px solid transparent;}
.egf-tpl{display:block;width:100%;text-align:left;border:1px solid #e4d3ba;border-radius:4px;
  background:#FCEFD9;color:#6B471A;padding:5px 7px;margin-bottom:5px;}
.egf-tpl:hover{background:#F7E0BD;border-color:#d8c7b0;}
.egf-tpl b{display:block;font-size:12.5px;color:#4E2C0B;}
.egf-tpl span{display:block;font-size:11px;color:#8A5A2B;line-height:1.5;}
.egf-pal-tip{font-size:11px;color:#8A5A2B;line-height:1.7;margin-top:8px;
  border-top:1px dashed #e4d3ba;padding-top:6px;}
.egf-canwrap.egf-drop{outline:2px dashed #F0A24B;outline-offset:-2px;}
</style>

<div id="egfMask">
  <div id="egfWin">
    <div class="egf-hd">
      <b><i class="fa fa-sitemap"></i> 流程圖</b>
      <span class="egf-hint" style="color:#8A5A2B;font-size:12px">
        雙擊圖形可直接打字；選起來可拖曳與縮放；箭頭選起來後兩端的圓點可以各自拉
      </span>
      <span class="egf-x" id="egfClose">&times;</span>
    </div>
    <div class="egf-bd">
      <div class="egf-bar">
        <span class="egf-lab" style="margin-left:0">框線</span><span id="egfSwLine"></span>
        <span class="egf-lab">填色</span><span id="egfSwFill"></span>
        <span class="egf-lab">字級</span>
        <select class="egf-sel" id="egfFontSize" data-eg-skip>
          <option>10</option><option>11</option><option selected>12</option><option>14</option><option>16</option><option>18</option>
        </select>
        <span class="egf-sep"></span>
        <button type="button" class="egf-btn" id="egfUndo" title="復原 (Ctrl+Z)"><i class="fa fa-undo"></i></button>
        <button type="button" class="egf-btn" id="egfRedo" title="重做 (Ctrl+Y)"><i class="fa fa-repeat"></i></button>
        <button type="button" class="egf-btn egf-danger" id="egfDel" title="刪除選取 (Delete)"><i class="fa fa-trash-o"></i></button>
        <span class="egf-sep"></span>
        <button type="button" class="egf-btn on" id="egfSnap" title="靠齊格線（畫得比較整齊）"><i class="fa fa-th"></i> 格線</button>
        <span class="egf-lab">畫布</span>
        <input type="number" class="egf-num" id="egfCW" value="760" min="200" max="2000" step="20" data-eg-skip>
        <span class="egf-lab" style="margin-left:0">×</span>
        <input type="number" class="egf-num" id="egfCH" value="560" min="200" max="3000" step="20" data-eg-skip>
        <button type="button" class="egf-btn" id="egfResize">套用</button>
        <span class="egf-sep"></span>
        <span class="egf-lab">顯示</span>
        <button type="button" class="egf-btn" id="egfZoomOut" title="縮小顯示（不會改變實際大小）">－</button>
        <span class="egf-lab" id="egfZoomVal" style="min-width:38px;display:inline-block;text-align:center">100%</span>
        <button type="button" class="egf-btn" id="egfZoomIn" title="放大顯示（不會改變實際大小）">＋</button>
        <button type="button" class="egf-btn" id="egfZoomFit" title="縮放到剛好看得到整張圖">符合視窗</button>
        <button type="button" class="egf-btn" id="egfZoom1" title="回到 100%">100%</button>
      </div>
      <div class="egf-work">
        <div class="egf-pal">
          <div class="egf-pal-t">圖形（拖到畫布上）</div>
          <div class="egf-tiles">
            <div class="egf-tile" draggable="true" data-drag="proc"  title="處理／作業步驟">
              <span class="egf-ic egf-ic-proc"></span><i>處理</i></div>
            <div class="egf-tile" draggable="true" data-drag="judge" title="判斷／分歧">
              <span class="egf-ic egf-ic-judge"></span><i>判斷</i></div>
            <div class="egf-tile" draggable="true" data-drag="term"  title="開始／結束">
              <span class="egf-ic egf-ic-term"></span><i>起訖</i></div>
            <div class="egf-tile" draggable="true" data-drag="doc"   title="文件／表單">
              <span class="egf-ic egf-ic-doc"></span><i>文件</i></div>
            <div class="egf-tile" draggable="true" data-drag="text"  title="純文字說明">
              <span class="egf-ic egf-ic-text">字</span><i>文字</i></div>
          </div>
          <div class="egf-pal-t">連線（在畫布上拖）</div>
          <div class="egf-tiles">
            <div class="egf-tile" data-add="arrow" title="由起點拖到終點">
              <span class="egf-ic egf-ic-arrow"></span><i>箭頭</i></div>
            <div class="egf-tile" data-add="line" title="由起點拖到終點">
              <span class="egf-ic egf-ic-line"></span><i>直線</i></div>
          </div>
          <div class="egf-pal-t">範本（一鍵插入）</div>
          <button type="button" class="egf-tpl" data-tpl="turtle">
            <b>烏龜圖</b><span>過程分析：輸入／輸出＋四隻腳</span></button>
          <button type="button" class="egf-tpl" data-tpl="flow3">
            <b>基本流程</b><span>開始→作業→判斷→結束</span></button>
          <button type="button" class="egf-tpl" data-tpl="pdca">
            <b>PDCA 循環</b><span>規劃→執行→查核→改善</span></button>
          <div class="egf-pal-tip">
            拖不習慣也可以直接點一下圖形，會放到畫布上。<br>
            圖形<b>雙擊</b>就能打字。
          </div>
        </div>
        <div class="egf-canhost">
          <div class="egf-canwrap"><canvas id="egfCanvas" width="760" height="560"></canvas></div>
        </div>
      </div>
    </div>
    <div class="egf-ft">
      <span class="egf-hint" id="egfStat">　</span>
      <span class="egf-sp"></span>
      <button type="button" class="btn btn-default" id="egfCancel">取消</button>
      <button type="button" class="btn btn-warning" id="egfSave" style="font-weight:bold;padding:6px 18px;">
        <i class="fa fa-check"></i> 插入到文件</button>
    </div>
  </div>
</div>

<script>
/* 流程圖編輯器（唯一實作）。呼叫端只需要 EGFlow.open({json, onSave}) */
(function (w, d) {
  'use strict';
  if (w.EGFlow) return;

  /* Fabric 5.3.0 已知 bug 最小防護（完整版見 views/Sales/image_editor.php 的說明）：
     textBaseline 預設值誤植成非法值 'alphabetical'，每幀每個文字物件都會印一條主控台警告，
     警告洪流就是「操作卡頓」的來源。本地 fabric.min.js 已修字，這裡是第二道保險。
     （沒有抽成共用補丁檔是刻意的：image_editor.php 那支有更完整的一套，
       要統一成 eg_fabric_fix.js 得動那個 8000 行的檔案，屬另一件事。） */
  if (w.fabric && fabric.Text && fabric.Text.prototype.textBaseline === 'alphabetical') {
    fabric.Text.prototype.textBaseline = 'alphabetic';
  }

  // ai-rules/10 暖色系固定調色盤（不給任意取色器，否則各頁流程圖顏色會走鐘）
  var LINE_COLORS = ['#333333', '#8A5A2B', '#B06F27', '#D6851F', '#A34E2A', '#DD5138'];
  var FILL_COLORS = ['#ffffff', '#faf6f0', '#F7E0BD', '#EBD3A8', '#E8C07A', '#F8DCD5'];

  var cv = null, nextId = 1, snap = true, hist = [], histAt = -1, quiet = false;
  var lineColor = '#333333', fillColor = '#ffffff', fontSize = 12;
  var onSaveCb = null;
  var GRID = 10;

  function el(id) { return d.getElementById(id); }
  function stat(msg) { var s = el('egfStat'); if (s) s.textContent = msg || '　'; }
  function snapv(n) { return snap ? Math.round(n / GRID) * GRID : n; }

  /* ── 節點＝圖形＋標籤兩個物件，用 egId 連在一起 ───────────────────────
     刻意不用 fabric.Group 包起來：group 一旦成形，裡面的文字就不能直接雙擊編輯
     （要先拆組再包回去，狀態很容易掉）。改成兩個獨立物件靠 egId 配對，
     圖形移動或縮放後把標籤重新擺到中心；標籤平常 selectable=false，
     雙擊時才臨時打開讓它進編輯模式。 */
  function labelOf(shape) {
    var r = null;
    cv.getObjects().forEach(function (o) { if (o.egRole === 'label' && o.egId === shape.egId) r = o; });
    return r;
  }
  function shapeOf(label) {
    var r = null;
    cv.getObjects().forEach(function (o) { if (o.egRole === 'shape' && o.egId === label.egId) r = o; });
    return r;
  }
  function syncLabel(shape) {
    var lb = labelOf(shape);
    if (!lb) return;
    var wpx = shape.getScaledWidth(), hpx = shape.getScaledHeight();
    lb.set({ width: Math.max(24, wpx - 12) });
    lb.set({ left: shape.left + wpx / 2, top: shape.top + hpx / 2 });
    lb.setCoords();
  }

  function addLabel(shape, text) {
    var lb = new fabric.Textbox(text || '', {
      left: shape.left + shape.width / 2, top: shape.top + shape.height / 2,
      originX: 'center', originY: 'center', textAlign: 'center',
      width: Math.max(24, shape.width - 12),
      fontSize: fontSize, fill: '#333333', fontFamily: '"微軟正黑體","Microsoft JhengHei",sans-serif',
      selectable: false, evented: false, splitByGrapheme: true
    });
    lb.egRole = 'label'; lb.egId = shape.egId;
    cv.add(lb);
    return lb;
  }

  function baseShapeOpts(x, y) {
    return { left: snapv(x), top: snapv(y), fill: fillColor, stroke: lineColor, strokeWidth: 1.5,
             strokeUniform: true, objectCaching: false };
  }

  function addNode(kind, x, y, text) {
    var id = nextId++, sh;
    if (kind === 'judge') {
      // 菱形：用 Polygon（fabric 沒有現成的菱形）
      sh = new fabric.Polygon([{x:60,y:0},{x:120,y:34},{x:60,y:68},{x:0,y:34}], baseShapeOpts(x, y));
    } else if (kind === 'term') {
      sh = new fabric.Rect(Object.assign(baseShapeOpts(x, y), { width: 120, height: 44, rx: 22, ry: 22 }));
    } else if (kind === 'doc') {
      sh = new fabric.Polygon([{x:14,y:0},{x:134,y:0},{x:120,y:48},{x:0,y:48}], baseShapeOpts(x, y));
    } else {
      sh = new fabric.Rect(Object.assign(baseShapeOpts(x, y), { width: 130, height: 50 }));
    }
    sh.egRole = 'shape'; sh.egId = id; sh.egKind = kind;
    cv.add(sh);
    addLabel(sh, text === undefined ? '' : text);
    cv.setActiveObject(sh);
    cv.requestRenderAll();
    return sh;
  }

  function addFreeText(x, y) {
    var t = new fabric.Textbox('文字', {
      left: snapv(x), top: snapv(y), width: 150, fontSize: fontSize, fill: '#333333',
      fontFamily: '"微軟正黑體","Microsoft JhengHei",sans-serif', objectCaching: false
    });
    t.egRole = 'text';
    cv.add(t); cv.setActiveObject(t); cv.requestRenderAll();
    return t;
  }

  /* ── 箭頭／直線：線＋箭頭，兩端各有一個可拖的控制點 ─────────────────── */
  function arrowHeadPoints() { return [{x:0,y:0},{x:-6,y:-11},{x:6,y:-11}]; }

  function addConn(kind, x1, y1, x2, y2) {
    var id = nextId++;
    var ln = new fabric.Line([snapv(x1), snapv(y1), snapv(x2), snapv(y2)], {
      stroke: lineColor, strokeWidth: 1.5, strokeUniform: true, objectCaching: false,
      hasControls: false, lockScalingX: true, lockScalingY: true
    });
    ln.egRole = 'conn'; ln.egId = id; ln.egKind = kind;
    cv.add(ln);
    if (kind === 'arrow') {
      var hd = new fabric.Polygon(arrowHeadPoints(), {
        left: ln.x2, top: ln.y2, originX: 'center', originY: 'bottom',
        fill: lineColor, stroke: lineColor, strokeWidth: 0.5,
        selectable: false, evented: false, objectCaching: false
      });
      hd.egRole = 'head'; hd.egId = id;
      cv.add(hd);
    }
    syncConn(ln);
    cv.setActiveObject(ln);
    cv.requestRenderAll();
    return ln;
  }

  function headOf(ln) {
    var r = null;
    cv.getObjects().forEach(function (o) { if (o.egRole === 'head' && o.egId === ln.egId) r = o; });
    return r;
  }

  /** 線的端點是靠 x1/y1/x2/y2，但 fabric 移動 Line 時改的是 left/top——
   *  兩套座標要自己對起來，不然箭頭會跟線分家。這裡一律以「絕對端點」為準重算。 */
  function lineAbs(ln) {
    var cx = ln.left + (ln.strokeWidth || 0) / 2, cy = ln.top + (ln.strokeWidth || 0) / 2;
    // fabric 的 Line：left/top 是 bounding box 左上角，x1..y2 是相對於中心的原始座標
    var minX = Math.min(ln.x1, ln.x2), minY = Math.min(ln.y1, ln.y2);
    return {
      x1: ln.left + (ln.x1 - minX), y1: ln.top + (ln.y1 - minY),
      x2: ln.left + (ln.x2 - minX), y2: ln.top + (ln.y2 - minY)
    };
  }
  function setLineAbs(ln, x1, y1, x2, y2) {
    ln.set({ x1: x1, y1: y1, x2: x2, y2: y2,
             left: Math.min(x1, x2), top: Math.min(y1, y2),
             width: Math.abs(x2 - x1), height: Math.abs(y2 - y1) });
    ln.setCoords();
  }
  function syncConn(ln) {
    var hd = headOf(ln);
    if (!hd) return;
    var a = lineAbs(ln);
    /* 箭頭本體的三個點是 (0,0) 尖端、(±6,-11) 底邊，配上 originY:'bottom'
       ＝「left/top 指的是尖端」，而**沒有旋轉時它是朝下的**（底邊在尖端上方）。
       所以要轉到線的方向是 φ−90，不是 φ+90——寫成 +90 會讓每一支箭頭都剛好
       反過來指（尖端位置仍在終點上，所以乍看很像對的，要量底邊在哪一側才看得出來）。 */
    var ang = Math.atan2(a.y2 - a.y1, a.x2 - a.x1) * 180 / Math.PI - 90;
    hd.set({ left: a.x2, top: a.y2, angle: ang, fill: ln.stroke, stroke: ln.stroke });
    hd.setCoords();
  }

  /* 端點控制點：選到連接線時長出兩個小圓，拖它改端點（不是縮放整條線——
     縮放會讓線寬與箭頭一起變形，看起來像圖片被拉大） */
  var hA = null, hB = null;
  function clearHandles() {
    [hA, hB].forEach(function (h) { if (h) cv.remove(h); });
    hA = hB = null;
  }
  function showHandles(ln) {
    clearHandles();
    var a = lineAbs(ln);
    function mk(px, py, which) {
      var c = new fabric.Circle({ left: px, top: py, radius: 5, originX: 'center', originY: 'center',
        fill: '#F0A24B', stroke: '#fff', strokeWidth: 2, hasControls: false, hasBorders: false,
        objectCaching: false, egRole: 'handle', egWhich: which, egFor: ln });
      cv.add(c); c.bringToFront();
      return c;
    }
    hA = mk(a.x1, a.y1, 'a');
    hB = mk(a.x2, a.y2, 'b');
    cv.requestRenderAll();
  }

  /* ── 歷史（復原/重做）──────────────────────────────────────────────── */
  /** 序列化：fabric 的 toJSON 不含畫布尺寸，自己補上 egW/egH，
   *  不然存回來的流程圖會被還原成預設尺寸，圖形跑到畫布外面 */
  function serialize() {
    var jo = cv.toJSON(['egRole', 'egId', 'egKind']);
    jo.egW = cv.getWidth();
    jo.egH = cv.getHeight();
    return JSON.stringify(jo);
  }

  function snapshot() {
    if (quiet || !cv) return;
    clearHandles();
    var j = serialize();
    if (hist[histAt] === j) return;
    hist = hist.slice(0, histAt + 1);
    hist.push(j);
    if (hist.length > 40) hist.shift();
    histAt = hist.length - 1;
  }
  function restore(j, cb) {
    quiet = true;
    // 畫布尺寸要先套：loadFromJSON 不管它，不先設好的話圖形會跑到畫布外面
    try {
      var jj = (typeof j === 'string') ? JSON.parse(j) : j;
      if (jj && jj.egW && jj.egH) { cv.setWidth(jj.egW); cv.setHeight(jj.egH); }
    } catch (e) {}
    cv.loadFromJSON(j, function () {
      // 還原後要把 egId 的最大值找回來，否則新增的節點會撞到既有 id
      var mx = 0;
      cv.getObjects().forEach(function (o) {
        if (o.egRole === 'handle') { cv.remove(o); return; }
        if (o.egId && o.egId > mx) mx = o.egId;
        if (o.egRole === 'label') { o.selectable = false; o.evented = false; }
        if (o.egRole === 'head')  { o.selectable = false; o.evented = false; }
        if (o.egRole === 'conn')  { o.hasControls = false; }
      });
      nextId = mx + 1;
      cv.requestRenderAll();
      quiet = false;
      if (cb) cb();
    });
  }
  function undo() {
    if (histAt <= 0) { stat('沒有可以復原的動作了'); return; }
    histAt--; restore(hist[histAt]); stat('已復原');
  }
  function redo() {
    if (histAt >= hist.length - 1) { stat('沒有可以重做的動作了'); return; }
    histAt++; restore(hist[histAt]); stat('已重做');
  }

  /* ── 刪除（節點要連標籤一起刪，連接線要連箭頭一起刪）────────────────── */
  function delSel() {
    var objs = cv.getActiveObjects();
    if (!objs.length) { stat('請先選要刪除的東西'); return; }
    objs.forEach(function (o) {
      if (o.egRole === 'handle') return;
      if (o.egRole === 'shape') { var lb = labelOf(o); if (lb) cv.remove(lb); }
      if (o.egRole === 'conn')  { var hd = headOf(o);  if (hd) cv.remove(hd); }
      if (o.egRole === 'label') { var sh = shapeOf(o); if (sh) cv.remove(sh); }
      cv.remove(o);
    });
    cv.discardActiveObject();
    clearHandles();
    cv.requestRenderAll();
    snapshot();
  }

  /* ── 調色盤 ───────────────────────────────────────────────────────── */
  function paintSwatches() {
    function fill(host, list, cur, kind) {
      host.innerHTML = list.map(function (c) {
        return '<span class="egf-sw' + (c === cur ? ' on' : '') + '" data-kind="' + kind + '" data-c="' + c
             + '" style="background:' + c + '"></span>';
      }).join('');
    }
    fill(el('egfSwLine'), LINE_COLORS, lineColor, 'line');
    fill(el('egfSwFill'), FILL_COLORS, fillColor, 'fill');
  }
  function applyColor(kind, c) {
    if (kind === 'line') lineColor = c; else fillColor = c;
    paintSwatches();
    var objs = cv.getActiveObjects();
    if (!objs.length) { stat('已選好顏色，接下來新增的圖形會用這個顏色'); return; }
    objs.forEach(function (o) {
      if (o.egRole === 'handle') return;
      if (kind === 'line') {
        o.set('stroke', c);
        if (o.egRole === 'conn') { var hd = headOf(o); if (hd) hd.set({ fill: c, stroke: c }); }
        if (o.egRole === 'label' || o.egRole === 'text') o.set({ fill: c, stroke: null });
      } else if (o.egRole === 'shape') {
        o.set('fill', c);
      }
    });
    cv.requestRenderAll();
    snapshot();
  }

  /* ── 建立畫布與事件 ───────────────────────────────────────────────── */
  function build() {
    cv = new fabric.Canvas('egfCanvas', { backgroundColor: '#ffffff', preserveObjectStacking: true });

    cv.on('object:moving', function (e) {
      var o = e.target;
      if (o.egRole === 'handle') {
        var ln = o.egFor;
        if (!ln) return;
        var a = lineAbs(ln);
        var nx = snapv(o.left), ny = snapv(o.top);
        if (o.egWhich === 'a') setLineAbs(ln, nx, ny, a.x2, a.y2);
        else                   setLineAbs(ln, a.x1, a.y1, nx, ny);
        syncConn(ln);
        return;
      }
      o.set({ left: snapv(o.left), top: snapv(o.top) });
      if (o.egRole === 'shape') syncLabel(o);
      if (o.egRole === 'conn')  { syncConn(o); clearHandles(); }
    });
    cv.on('object:scaling', function (e) {
      if (e.target.egRole === 'shape') syncLabel(e.target);
    });
    cv.on('object:modified', function (e) {
      var o = e.target;
      if (o && o.egRole === 'shape') syncLabel(o);
      if (o && o.egRole === 'conn') { syncConn(o); showHandles(o); }
      if (o && o.egRole === 'handle' && o.egFor) showHandles(o.egFor);
      snapshot();
    });
    cv.on('selection:created', onSel);
    cv.on('selection:updated', onSel);
    cv.on('selection:cleared', function () { clearHandles(); });

    function onSel() {
      var objs = cv.getActiveObjects();
      clearHandles();
      if (objs.length === 1 && objs[0].egRole === 'conn') showHandles(objs[0]);
    }

    // 雙擊圖形＝直接打字（標籤平常 evented=false，所以要自己算命中）
    cv.on('mouse:dblclick', function (e) {
      var p = cv.getPointer(e.e);
      var hit = null;
      cv.getObjects().forEach(function (o) {
        if (o.egRole !== 'shape') return;
        var r = o.getBoundingRect(true, true);
        if (p.x >= r.left && p.x <= r.left + r.width && p.y >= r.top && p.y <= r.top + r.height) hit = o;
      });
      if (!hit) return;
      var lb = labelOf(hit);
      if (!lb) return;
      lb.selectable = true; lb.evented = true;
      cv.setActiveObject(lb);
      lb.enterEditing();
      lb.selectAll();
      cv.requestRenderAll();
    });
    cv.on('text:editing:exited', function (e) {
      var o = e.target;
      if (o && o.egRole === 'label') {
        o.selectable = false; o.evented = false;
        var sh = shapeOf(o);
        if (sh) { syncLabel(sh); cv.setActiveObject(sh); }
      }
      snapshot();
    });

    // 拖曳畫線：按住「箭頭／直線」之後在畫布上拖
    var pend = null, drag = null;
    cv.on('mouse:down', function (e) {
      if (!pend) return;
      var p = cv.getPointer(e.e);
      drag = { x: p.x, y: p.y };
    });
    cv.on('mouse:up', function (e) {
      if (!pend) return;
      var p = cv.getPointer(e.e);
      var x1 = drag ? drag.x : p.x, y1 = drag ? drag.y : p.y;
      // 只是點一下（沒有拖）就給一條預設長度的線，不然使用者會以為按了沒反應
      if (Math.abs(p.x - x1) < 6 && Math.abs(p.y - y1) < 6) { p.x = x1; p.y = y1 + 70; }
      addConn(pend, x1, y1, p.x, p.y);
      pend = null; drag = null;
      cv.defaultCursor = 'default';
      clearPendUI();
      snapshot();
    });
    w.__egfSetPending = function (k) {
      pend = k;
      cv.defaultCursor = k ? 'crosshair' : 'default';
      stat(k === 'arrow' ? '在畫布上由起點拖到終點畫出箭頭（點一下也可以，會給一條預設長度的線）'
                         : '在畫布上由起點拖到終點畫出直線');
    };
  }

  /* ── 顯示縮放 ──────────────────────────────────────────────────────────
     使用者要求「流程圖要可以在跳窗內放大」。
     **只放大顯示，不動畫布的實際尺寸**——所以只改 canvas 的 CSS 尺寸
     （`setDimensions(..., {cssOnly:true})`），backstore 維持原樣，
     匯出與存檔拿到的仍是原尺寸；fabric v5 的 getPointer 會自己換算 CSS 縮放，
     滑鼠點擊位置不會跑掉。 */
  var ZOOM = 1;
  var ZOOM_STEPS = [0.25, 0.4, 0.5, 0.6, 0.75, 0.9, 1, 1.25, 1.5, 2, 2.5, 3];

  function applyZoom() {
    if (!cv) return;
    cv.setDimensions({ width: Math.round(cv.getWidth() * ZOOM) + 'px',
                       height: Math.round(cv.getHeight() * ZOOM) + 'px' }, { cssOnly: true });
    var v = el('egfZoomVal');
    if (v) v.textContent = Math.round(ZOOM * 100) + '%';
  }
  function setZoom(z) {
    ZOOM = Math.max(0.15, Math.min(4, z));
    applyZoom();
    stat('顯示縮放 ' + Math.round(ZOOM * 100) + '%（只是看起來變大，實際尺寸沒有變）');
  }
  function zoomStep(dir) {
    var i = 0, best = 0, d = 1e9;
    for (i = 0; i < ZOOM_STEPS.length; i++) {
      var dd = Math.abs(ZOOM_STEPS[i] - ZOOM);
      if (dd < d) { d = dd; best = i; }
    }
    setZoom(ZOOM_STEPS[Math.max(0, Math.min(ZOOM_STEPS.length - 1, best + dir))]);
  }
  function zoomFit() {
    var host = d.querySelector('#egfWin .egf-canhost');
    if (!host || !cv) return;
    var k = Math.min((host.clientWidth - 16) / cv.getWidth(),
                     (host.clientHeight - 16) / cv.getHeight());
    setZoom(k > 0 ? k : 1);
  }

  /* ── 面板：拖曳產生與一鍵範本 ─────────────────────────────────────────
     使用者要求「希望可以快速用拉的產生，不需要真的自己畫」，所以圖形改成
     從左側面板拖到畫布上；拖不習慣的人點一下也一樣放得上去。 */
  var SHAPE_SIZE = { proc:{w:130,h:50}, judge:{w:120,h:68}, term:{w:120,h:44},
                     doc:{w:134,h:48}, text:{w:150,h:22} };
  var KIND_NAME  = { proc:'處理', judge:'判斷', term:'起訖', doc:'文件', text:'文字' };
  var dragKind = null;

  function clearPendUI() {
    Array.prototype.slice.call(d.querySelectorAll('#egfWin [data-add],#egfWin [data-drag]'))
      .forEach(function (b) { b.classList.remove('on'); });
  }

  /** 放一個圖形到畫布；x/y 省略＝點擊新增（自動往右下錯開，不會全部疊在一起） */
  function dropShape(kind, x, y) {
    if (x === null || x === undefined) {
      var n = cv.getObjects().filter(function (o) { return o.egRole === 'shape' || o.egRole === 'text'; }).length;
      x = 60 + (n % 5) * 20; y = 50 + (n % 8) * 30;
    } else {
      // 拖放時讓游標落在圖形中央，比較符合直覺
      var sz = SHAPE_SIZE[kind] || SHAPE_SIZE.proc;
      x = x - sz.w / 2; y = y - sz.h / 2;
    }
    x = Math.max(0, x); y = Math.max(0, y);
    var o = (kind === 'text') ? addFreeText(x, y) : addNode(kind, x, y, '');
    snapshot();
    stat('已放上「' + (KIND_NAME[kind] || kind) + '」——雙擊圖形就可以打字');
    return o;
  }

  /* ── 範本 ─────────────────────────────────────────────────────────── */
  /** 畫布至少要這麼大，範本才放得下 */
  function ensureCanvas(ww, hh) {
    if (cv.getWidth()  < ww) cv.setWidth(ww);
    if (cv.getHeight() < hh) cv.setHeight(hh);
    el('egfCW').value = cv.getWidth();
    el('egfCH').value = cv.getHeight();
  }
  function hasContent() {
    return cv.getObjects().some(function (o) {
      return o.egRole === 'shape' || o.egRole === 'conn' || o.egRole === 'text';
    });
  }
  /** 指定尺寸的方框（範本用；judge/doc 是多邊形不吃 width/height，範本一律用方框） */
  function boxAt(x, y, ww, hh, text, kind) {
    var s = addNode(kind || 'proc', x, y, text);
    if (ww) { s.set({ width: ww, height: hh }); s.setCoords(); syncLabel(s); }
    return s;
  }
  function textAt(x, y, s, size) {
    var t = addFreeText(x, y);
    t.set({ text: s, width: 60, fontSize: size || fontSize, textAlign: 'center' });
    t.setCoords();
    return t;
  }

  function tplTurtle() {
    ensureCanvas(900, 640);
    // 中心＝這個過程本身；左右是輸入輸出；四隻腳是「用什麼／用誰／如何做／做得如何」
    boxAt(370, 288, 170, 70, '過程名稱\n（請填）');
    boxAt( 55, 296, 150, 56, '輸入\n上游過程・需求');
    boxAt(700, 296, 150, 56, '輸出\n下游過程・成果');
    boxAt(150,  70, 170, 62, '用什麼？\n設備・工具・材料');
    boxAt(585,  70, 170, 62, '用誰？\n人員・職能・訓練');
    boxAt(150, 508, 170, 62, '如何做？\n程序書・作業標準');
    boxAt(585, 508, 170, 62, '做得如何？\n績效指標・量測方式');
    addConn('arrow', 205, 324, 370, 324);   // 輸入 → 過程
    addConn('arrow', 540, 324, 700, 324);   // 過程 → 輸出
    addConn('arrow', 235, 132, 400, 288);   // 用什麼 ↘
    addConn('arrow', 670, 132, 510, 288);   // 用誰 ↙
    addConn('arrow', 235, 508, 400, 358);   // 如何做 ↗
    addConn('arrow', 670, 508, 510, 358);   // 做得如何 ↖
  }

  function tplFlow3() {
    ensureCanvas(560, 470);
    boxAt(180,  26, 120, 44, '開始', 'term');
    boxAt(175, 106, 130, 50, '作業步驟');
    boxAt(180, 192, 120, 68, '判斷', 'judge');
    boxAt(175, 300, 130, 50, '後續作業');
    boxAt(180, 386, 120, 44, '結束', 'term');
    addConn('arrow', 240,  70, 240, 106);
    addConn('arrow', 240, 156, 240, 192);
    addConn('arrow', 240, 260, 240, 300);
    addConn('arrow', 240, 350, 240, 386);
    // 「否」回到作業步驟：兩段直線＋一支回頭的箭頭
    addConn('line',  300, 226, 430, 226);
    addConn('line',  430, 226, 430, 131);
    addConn('arrow', 430, 131, 305, 131);
    textAt(250, 262, '是', 11);
    textAt(330, 196, '否', 11);
  }

  function tplPdca() {
    ensureCanvas(560, 420);
    boxAt( 60,  60, 170, 70, 'P 規劃 Plan\n訂目標與做法');
    boxAt(330,  60, 170, 70, 'D 執行 Do\n照做法實施');
    boxAt(330, 280, 170, 70, 'C 查核 Check\n量測與比對');
    boxAt( 60, 280, 170, 70, 'A 改善 Act\n矯正與標準化');
    addConn('arrow', 230,  95, 330,  95);
    addConn('arrow', 415, 130, 415, 280);
    addConn('arrow', 330, 315, 230, 315);
    addConn('arrow', 145, 280, 145, 130);
  }

  function insertTemplate(key) {
    if (hasContent() && !w.confirm(
        '畫布上已經有內容。\n\n按「確定」＝清空後插入這個範本；\n按「取消」＝保留目前的圖、不插入。')) return;
    w.__egfSetPending(null);
    clearPendUI();
    quiet = true;                       // 範本是一次成形，中間過程不要進復原歷史
    try {
      cv.clear();
      cv.backgroundColor = '#ffffff';
      nextId = 1;
      if      (key === 'turtle') tplTurtle();
      else if (key === 'flow3')  tplFlow3();
      else if (key === 'pdca')   tplPdca();
    } finally { quiet = false; }
    cv.discardActiveObject();
    applyZoom();
    cv.requestRenderAll();
    snapshot();
    stat('已插入範本——雙擊任何一個框就可以改字，不要的框選起來按 Delete');
  }

  /* ── 工具列綁定 ───────────────────────────────────────────────────── */
  function bind() {
    var win = el('egfWin');
    win.addEventListener('click', function (e) {
      // .egf-x（右上角的 ×）是 <span> 不是 button，選擇器漏掉它就會「關不掉，只能按 ESC」
      // 面板上的圖形是 <div data-drag>、範本是 <button data-tpl>，
      // 選擇器漏掉它們就會「點了完全沒反應」（.egf-x 當初就是這樣被漏掉的）
      var t = e.target.closest ? e.target.closest('[data-add],[data-drag],[data-tpl],.egf-sw,button,.egf-x') : null;
      if (!t) return;
      // 範本：一鍵把整張圖放上去（使用者：不需要真的自己畫）
      var tpl = t.getAttribute && t.getAttribute('data-tpl');
      if (tpl) { insertTemplate(tpl); return; }

      // 面板上的圖形：拖比較快，但也要能點一下就放上去（拖不習慣的人）
      var add = t.getAttribute && (t.getAttribute('data-add') || t.getAttribute('data-drag'));
      if (add) {
        clearPendUI();
        if (add === 'arrow' || add === 'line') { t.classList.add('on'); w.__egfSetPending(add); return; }
        w.__egfSetPending(null);
        dropShape(add, null, null);
        return;
      }
      if (t.classList && t.classList.contains('egf-sw')) {
        applyColor(t.getAttribute('data-kind'), t.getAttribute('data-c'));
        return;
      }
      switch (t.id) {
        case 'egfUndo': undo(); break;
        case 'egfRedo': redo(); break;
        case 'egfDel':  delSel(); break;
        case 'egfSnap':
          snap = !snap;
          t.classList.toggle('on', snap);
          stat(snap ? '已開啟靠齊格線（每 10px）' : '已關閉靠齊格線');
          break;
        case 'egfResize': {
          var ww = Math.max(200, Math.min(2000, parseInt(el('egfCW').value, 10) || 760));
          var hh = Math.max(200, Math.min(3000, parseInt(el('egfCH').value, 10) || 560));
          cv.setWidth(ww); cv.setHeight(hh); applyZoom(); cv.requestRenderAll();
          stat('畫布已改成 ' + ww + '×' + hh);
          snapshot();
          break;
        }
        case 'egfZoomIn':  zoomStep(+1); break;
        case 'egfZoomOut': zoomStep(-1); break;
        case 'egfZoomFit': zoomFit(); break;
        case 'egfZoom1':   setZoom(1); break;
        case 'egfSave':   doSave(); break;
        case 'egfCancel':
        case 'egfClose':  close(); break;
      }
    });
    /* 從面板把圖形拖到畫布上。
       用 HTML5 dragstart/drop：畫布是 <canvas>，fabric 自己的 mouse 事件抓不到
       「從畫面別的地方拖進來」這件事，硬用 mousemove 模擬會跟 fabric 的框選打架。 */
    win.addEventListener('dragstart', function (e) {
      var t = e.target.closest ? e.target.closest('[data-drag]') : null;
      if (!t) return;
      dragKind = t.getAttribute('data-drag');
      try {
        e.dataTransfer.setData('text/plain', dragKind);
        e.dataTransfer.effectAllowed = 'copy';
      } catch (err) {}
      stat('拖到右邊畫布上放開就會建立「' + (KIND_NAME[dragKind] || dragKind) + '」');
    });
    win.addEventListener('dragend', function () {
      dragKind = null;
      var wr = d.querySelector('#egfWin .egf-canwrap');
      if (wr) wr.classList.remove('egf-drop');
    });
    var wrap = d.querySelector('#egfWin .egf-canwrap');
    if (wrap) {
      wrap.addEventListener('dragover', function (e) {
        if (!dragKind) return;
        e.preventDefault();                       // 不 preventDefault 就不會觸發 drop
        try { e.dataTransfer.dropEffect = 'copy'; } catch (err) {}
        wrap.classList.add('egf-drop');
      });
      wrap.addEventListener('dragleave', function (e) {
        if (e.target === wrap) wrap.classList.remove('egf-drop');
      });
      wrap.addEventListener('drop', function (e) {
        e.preventDefault();
        wrap.classList.remove('egf-drop');
        var kind = dragKind;
        if (!kind) { try { kind = e.dataTransfer.getData('text/plain'); } catch (err) {} }
        dragKind = null;
        if (!kind || !SHAPE_SIZE[kind]) return;
        // 座標要相對「畫布本身」不是外框（外框有邊框與捲動位移）
        var r = el('egfCanvas').getBoundingClientRect();
        dropShape(kind, e.clientX - r.left, e.clientY - r.top);
      });
    }

    el('egfFontSize').addEventListener('change', function () {
      fontSize = parseInt(this.value, 10) || 12;
      var objs = cv.getActiveObjects();
      objs.forEach(function (o) {
        if (o.egRole === 'label' || o.egRole === 'text') o.set('fontSize', fontSize);
        if (o.egRole === 'shape') { var lb = labelOf(o); if (lb) lb.set('fontSize', fontSize); }
      });
      cv.requestRenderAll();
      snapshot();
    });
    // 鍵盤：Delete 刪除、Ctrl+Z/Y 復原重做（在文字編輯中一律不攔）
    d.addEventListener('keydown', function (e) {
      if (!el('egfMask').classList.contains('open')) return;
      var ao = cv && cv.getActiveObject();
      if (ao && ao.isEditing) return;
      var tag = (e.target && e.target.tagName) || '';
      if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
      if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); delSel(); }
      else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); undo(); }
      else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); }
      else if (e.key === 'Escape') { close(); }
    });
  }

  function doSave() {
    if (!cv) return;
    clearHandles();
    cv.discardActiveObject();
    cv.requestRenderAll();
    var objs = cv.getObjects().filter(function (o) { return o.egRole !== 'handle'; });
    if (!objs.length) { stat('畫布是空的，沒有東西可以放進文件'); return; }
    /* 只輸出「真的有畫東西」的那一塊，不要把畫布兩側的空白也插進文件
       （使用者：插入文件上時，應該依照實際大小插入，不是連旁邊畫布空白都插入）。
       取所有物件的外接矩形再留一點邊，並夾在畫布範圍內。 */
    var PAD = 8;
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    objs.forEach(function (o) {
      var r = o.getBoundingRect(true, true);
      if (r.left < minX) minX = r.left;
      if (r.top  < minY) minY = r.top;
      if (r.left + r.width  > maxX) maxX = r.left + r.width;
      if (r.top  + r.height > maxY) maxY = r.top + r.height;
    });
    var cx = Math.max(0, Math.floor(minX - PAD)), cy = Math.max(0, Math.floor(minY - PAD));
    var cw = Math.min(cv.getWidth()  - cx, Math.ceil(maxX - minX + PAD * 2));
    var ch = Math.min(cv.getHeight() - cy, Math.ceil(maxY - minY + PAD * 2));
    if (!(cw > 0 && ch > 0)) { cx = 0; cy = 0; cw = cv.getWidth(); ch = cv.getHeight(); }
    // multiplier 2.5＝列印時不會鋸齒（列印是 300dpi 等級，螢幕 1x 印出來會糊）
    var png = cv.toDataURL({ format: 'png', multiplier: 2.5, enableRetinaScaling: false,
                             left: cx, top: cy, width: cw, height: ch });
    var json = serialize();
    var cb = onSaveCb;
    close();
    if (cb) cb(png, json);
  }

  function open(opt) {
    opt = opt || {};
    onSaveCb = opt.onSave || null;
    el('egfMask').classList.add('open');
    if (!cv) { build(); bind(); }
    paintSwatches();
    clearHandles();
    hist = []; histAt = -1;
    stat('　');

    function afterLoad() {
      el('egfCW').value = cv.getWidth();
      el('egfCH').value = cv.getHeight();
      // 每次開啟都回到 100%，不要沿用上一次的顯示縮放
      ZOOM = 1; applyZoom();
      snapshot();
    }
    if (opt.json) {
      restore(opt.json, afterLoad);   // restore 內已套用工作檔存的畫布尺寸
    } else {
      cv.clear();
      cv.backgroundColor = '#ffffff';
      cv.setWidth(760); cv.setHeight(560);
      nextId = 1;
      cv.requestRenderAll();
      afterLoad();
    }
  }
  function close() {
    el('egfMask').classList.remove('open');
    if (cv) { cv.defaultCursor = 'default'; }
    onSaveCb = null;
  }

  // canvas() 是唯讀存取器：fabric 不會把實例掛在 <canvas> 元素上，
  // 所以自動測試與日後要整合的程式沒有別的辦法拿到畫布內容。
  w.EGFlow = { open: open, close: close, canvas: function () { return cv; } };
})(window, document);
</script>
