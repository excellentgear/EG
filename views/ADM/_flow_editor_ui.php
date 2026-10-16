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
#egfWin .egf-bd{padding:9px 14px 10px;overflow:auto;background:#efe9e0;flex:1 1 auto;min-height:0;}
#egfWin .egf-ft{padding:9px 14px;border-top:1px solid #e4d3ba;background:#faf6f0;border-radius:0 0 6px 6px;
  display:flex;align-items:center;gap:8px;}
#egfWin .egf-ft .egf-hint{color:#8A5A2B;font-size:12px;}
#egfWin .egf-ft .egf-sp{margin-left:auto;}
/* 工具列是**標題列的下一塊、不在捲動區裡面**（使用者 2026-10-15：上排內容要固定不可捲動）。
   放在 .egf-bd 裡面的話，畫布一捲動工具列就跟著跑掉，正在畫圖時要往回捲才按得到。 */
.egf-bar{background:#fff;border:1px solid #e4d3ba;border-radius:4px;padding:5px 6px;
  margin:10px 14px 0;flex:0 0 auto;
  display:flex;flex-wrap:wrap;align-items:center;gap:3px;}
/* 左下角的縮放把手：拖曳改變整個跳窗大小（雙擊＝還原預設） */
.egf-grip{position:absolute;right:0;bottom:0;width:18px;height:18px;cursor:nwse-resize;z-index:5;
  background:repeating-linear-gradient(-45deg,transparent 0 2px,#c9a06a 2px 3px);
  border-radius:0 0 6px 0;-webkit-user-select:none;user-select:none;}
.egf-grip:hover{background:repeating-linear-gradient(-45deg,transparent 0 2px,#8A5A2B 2px 3px);}
/* 左下角的縮放滑桿——比照 Excel 那一條：拖著就放大縮小編輯畫面
   （使用者 2026-10-16：工具列那組「顯示 －／60%／＋」不要，改成可以拖的） */
.egf-zoom{display:inline-flex;align-items:center;gap:5px;flex:0 0 auto;}
.egf-zoom input[type=range]{-webkit-appearance:none;appearance:none;width:150px;height:4px;
  background:#e4d3ba;border-radius:2px;outline:none;cursor:pointer;margin:0;padding:0;}
.egf-zoom input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;
  width:15px;height:15px;border-radius:50%;background:#F0A24B;border:2px solid #fff;
  box-shadow:0 0 0 1px #c9a06a;cursor:grab;}
.egf-zoom input[type=range]::-webkit-slider-thumb:active{cursor:grabbing;background:#DD5138;}
.egf-zoom input[type=range]::-moz-range-thumb{width:15px;height:15px;border-radius:50%;
  background:#F0A24B;border:2px solid #fff;box-shadow:0 0 0 1px #c9a06a;cursor:grab;}
.egf-zbtn{border:1px solid #e4d3ba;background:#faf6f0;color:#6B471A;width:22px;height:22px;
  line-height:18px;padding:0;border-radius:3px;font-size:13px;}
.egf-zbtn:hover{background:#f2e6d4;}
.egf-zval{font-size:12px;color:#8A5A2B;min-width:42px;text-align:center;}
.egf-btn{border:1px solid #e4d3ba;background:#faf6f0;color:#6B471A;font-size:12px;line-height:24px;height:26px;
  padding:0 8px;border-radius:3px;}
.egf-btn:hover{background:#f2e6d4;}
.egf-btn.on{background:#e8d5b8;border-color:#d8c7b0;}
.egf-btn.egf-danger{color:#DD5138;}
.egf-asbar{background:#FDF0DC;border:1px solid #E8C89A;color:#8A5A2B;border-radius:4px;
  padding:3px 8px;font-size:12px;display:inline-flex;align-items:center;gap:8px;max-width:62%;}
.egf-asbar i{color:#DD5138;}
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
        雙擊圖形可直接打字；線選起來只會出現兩端的圓點（可各自拉），要改中間的轉折請在線上雙擊
      </span>
      <span class="egf-x" id="egfClose">&times;</span>
    </div>
    <!-- 工具列固定在標題列下方，不隨畫布捲動 -->
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
        <button type="button" class="egf-btn" id="egfSnap" title="靠齊格線：開啟後移動與畫線都會對到 10px 的格子上（預設關閉，想精準擺位時就讓它關著）"><i class="fa fa-th"></i> 格線</button>
        <button type="button" class="egf-btn on" id="egfGuide" title="對齊輔助線：拖曳時跟其他圖形的邊或中心接近就顯示橘色虛線並自動對齊"><i class="fa fa-magnet"></i> 對齊</button>
        <span class="egf-lab">畫布</span>
        <input type="number" class="egf-num" id="egfCW" value="760" min="200" max="2000" step="20" data-eg-skip>
        <span class="egf-lab" style="margin-left:0">×</span>
        <input type="number" class="egf-num" id="egfCH" value="560" min="200" max="3000" step="20" data-eg-skip>
        <button type="button" class="egf-btn" id="egfResize">套用</button>
        <span class="egf-sep"></span>
        <span class="egf-lab">圖內文字</span>
        <button type="button" class="egf-btn" id="egfTxtL" title="圖形內文字靠左"><i class="fa fa-align-left"></i></button>
        <button type="button" class="egf-btn" id="egfTxtC" title="圖形內文字左右置中（預設）"><i class="fa fa-align-center"></i></button>
        <button type="button" class="egf-btn" id="egfTxtR" title="圖形內文字靠右"><i class="fa fa-align-right"></i></button>
        <button type="button" class="egf-btn" id="egfTxtT" title="圖形內文字靠上"><i class="fa fa-angle-up"></i></button>
        <button type="button" class="egf-btn" id="egfTxtM" title="圖形內文字上下置中（預設）"><i class="fa fa-minus"></i></button>
        <button type="button" class="egf-btn" id="egfTxtB" title="圖形內文字靠下"><i class="fa fa-angle-down"></i></button>
        <button type="button" class="egf-btn" id="egfAsRef" style="display:none;font-weight:bold"
                title="插入 AS 文件編號／名稱：先選一個圖形（或文字），插進去的文字會記住是哪一份 AS 文件；那份文件改了編號或名稱，下次打開這張流程圖會提示你更新"><i class="fa fa-plus"></i> AS</button>
        <span class="egf-sep"></span>
        <span class="egf-lab">連線</span>
        <select class="egf-num" id="egfConnKind" style="width:92px" data-eg-skip
                title="選著一條線時改這裡會直接套用到那條線（新畫的線用左邊面板的「箭頭／直線」拖出來）">
          <option value="arrow">單箭頭 →</option>
          <option value="darrow">雙箭頭 ↔</option>
          <option value="line">直線（無箭頭）</option>
          <option value="rarrow">反向箭頭 ←</option>
        </select>
        <button type="button" class="egf-btn" id="egfConnFlip" title="把選取那條線的頭尾對調（箭頭跟著反向）"><i class="fa fa-exchange"></i> 反向</button>
        <button type="button" class="egf-btn" id="egfConnEdit" title="編輯節點：打開之後線上每個轉折點都會出現可拖的橘色圓點，線段中間也會有空心「＋」可以加折點；在節點上再點一下（或按住 Alt 點）＝取消那個節點（併回相鄰的節點）。直接在線上雙擊也可以打開／關掉。&#10;平常是關著的——不關的話拖線很容易誤拖到中間的節點，線就被拉成彎的。"><i class="fa fa-crosshairs"></i> 節點</button>
        <button type="button" class="egf-btn" id="egfConnNode" title="在選取那條線的最長一段中間加一個折點（也可以按「節點」打開節點編輯，點線段中間的空心「＋」圓點新增；折點可以任意拖曳）"><i class="fa fa-plus"></i> 折點</button>
        <button type="button" class="egf-btn" id="egfConnOrtho" title="把選取那條線改成直角折線（流程圖最常用的轉折方式，依兩端的相對位置自動決定先走橫的還是先走直的）"><i class="fa fa-level-up"></i> 直角</button>
        <button type="button" class="egf-btn" id="egfConnStraight" title="把選取那條線的折點全部移除，變回頭尾一條直線"><i class="fa fa-minus"></i> 取直</button>
        <button type="button" class="egf-btn" id="egfConnCurve" title="圓滑／取直曲線：通過每一個折點畫成平滑曲線（至少要有一個折點，先按「折點」）"><i class="fa fa-adjust"></i> 圓滑</button>
        <span class="egf-sep"></span>
        <span class="egf-lab">排列</span>
        <button type="button" class="egf-btn" id="egfAlignL" title="框選兩個以上的圖形後按這裡：全部的「左邊緣」對齊到第一個的左邊緣"><i class="fa fa-align-left"></i> 靠左</button>
        <button type="button" class="egf-btn" id="egfAlignVC" title="框選兩個以上的圖形後按這裡：全部的中心對齊到第一個的中心垂直線（左右對齊成一直行）"><i class="fa fa-align-center"></i> 中線</button>
        <button type="button" class="egf-btn" id="egfAlignR" title="框選兩個以上的圖形後按這裡：全部的「右邊緣」對齊到第一個的右邊緣"><i class="fa fa-align-right"></i> 靠右</button>
        <button type="button" class="egf-btn" id="egfAlignHC" title="全部的中心對齊到第一個的中心水平線（上下對齊成一橫列）"><i class="fa fa-ellipsis-h"></i> 橫線</button>
        <button type="button" class="egf-btn" id="egfDistV" title="由上到下等距排好、彼此不重疊，中心也對齊成一直行"><i class="fa fa-sort-amount-asc"></i> 直排</button>
        <button type="button" class="egf-btn" id="egfDistH" title="由左到右等距排好、彼此不重疊，中心也對齊成一橫列"><i class="fa fa-exchange"></i> 橫排</button>
    </div>
    <div class="egf-bd">
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
          <div class="egf-pal-t">連線（在畫布上畫）</div>
          <div class="egf-tiles">
            <div class="egf-tile" data-add="arrow" title="點一下起點、再點一下終點就連起來；中間想轉幾個彎就多點幾下，最後雙擊（或按 Enter）結束。也可以直接由起點拖到終點畫一段。">
              <span class="egf-ic egf-ic-arrow"></span><i>箭頭</i></div>
            <div class="egf-tile" data-add="line" title="點一下起點、再點一下終點就連起來；中間想轉幾個彎就多點幾下，最後雙擊（或按 Enter）結束。也可以直接由起點拖到終點畫一段。">
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
            圖形<b>雙擊</b>就能打字。<br>
            畫線：<b>點起點→點終點</b>，中間多點幾下就是折線，雙擊或 Enter 結束。<br>
            線<b>選起來</b>只會出現<b>兩端</b>的橘色圓點（拖它接到圖形上）。<br>
            要改中間的轉折：在線上<b>雙擊</b>（或按工具列「節點」）→ 每個折點都可拖、
            線段中間的空心<b>＋</b>＝加折點、<b>在折點上再點一下＝取消那個折點</b>。<br>
            鍵盤：<b>V</b> 選取、<b>A</b> 箭頭、<b>L</b> 直線、<b>方向鍵</b>微調位置。
          </div>
        </div>
        <div class="egf-canhost">
          <div class="egf-canwrap"><canvas id="egfCanvas" width="760" height="560"></canvas></div>
        </div>
      </div>
    </div>
    <div class="egf-ft">
      <span class="egf-zoom">
        <button type="button" class="egf-zbtn" id="egfZoomOut" title="縮小編輯畫面">－</button>
        <input type="range" id="egfZoomRange" min="25" max="400" step="1" value="100" data-eg-skip
               title="拖曳這條滑桿放大縮小編輯畫面（只是看起來變大，圖的實際尺寸沒有變）">
        <button type="button" class="egf-zbtn" id="egfZoomIn" title="放大編輯畫面">＋</button>
        <span class="egf-zval" id="egfZoomVal">100%</span>
        <button type="button" class="egf-btn" id="egfZoomFit" title="縮放到剛好看得到整張圖（也可以按住 Ctrl 滾滑鼠滾輪縮放）">符合視窗</button>
        <button type="button" class="egf-btn" id="egfZoom1" title="回到 100%">100%</button>
      </span>
      <span class="egf-asbar" id="egfAsBar" style="display:none">
        <i class="fa fa-exclamation-triangle"></i>
        <span id="egfAsBarTxt"></span>
        <button type="button" class="egf-btn" id="egfAsUpd">更新引用文字</button>
      </span>
      <span class="egf-hint" id="egfStat">　</span>
      <span class="egf-sp"></span>
      <button type="button" class="btn btn-default" id="egfCancel">取消</button>
      <button type="button" class="btn btn-warning" id="egfSave" style="font-weight:bold;padding:6px 18px;">
        <i class="fa fa-check"></i> 插入到文件</button>
    </div>
    <!-- 視窗右下角的縮放把手（拖曳改變這個跳窗的大小，雙擊還原） -->
    <span class="egf-grip" id="egfGrip"
          title="拖曳這個角可以改變跳窗大小；雙擊＝還原成預設大小"></span>
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

  // snap 預設 false：使用者要的是「放到想放的位置」，靠齊格線會讓圖形一直跳到 10px 的倍數
  var cv = null, nextId = 1, snap = false, hist = [], histAt = -1, quiet = false;
  var lineColor = '#333333', fillColor = '#ffffff', fontSize = 12;
  var onSaveCb = null;
  var GRID = 10;
  // 用既有圖片當底圖時，畫布上限（等比例縮）：太大打開就要一直捲才看得到全貌
  var EGF_BG_MAX_W = 1400, EGF_BG_MAX_H = 1000;

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
  /* 圖形內的文字：預設上下左右都置中，可以改成靠左／靠右、靠上／靠下
     （使用者 2026-10-15）。對齊方式記在圖形上（egHA／egVA），
     不是記在文字上——圖形縮放、移動都要重新定位文字，統一由這支算。 */
  function syncLabel(shape) {
    var lb = labelOf(shape);
    if (!lb) return;
    /* 座標一律走 calcTransformMatrix 的 m[4]/m[5]（絕對中心），
       不可以直接讀 shape.left/top：框選之後圖形被收進 activeSelection，
       left/top 會變成「相對群組中心」的值，拿它當絕對座標會把文字丟到
       畫布左上角（使用者 2026-10-16「框選移動時內部文字沒有跟著移動」）。
       群組被縮放時成員自己的 scaleX 不變，要再乘上群組的縮放。 */
    var gs = shape.group ? (shape.group.scaleX || 1) : 1;
    var gt = shape.group ? (shape.group.scaleY || 1) : 1;
    var wpx = shape.getScaledWidth() * gs, hpx = shape.getScaledHeight() * gt;
    var m = shape.calcTransformMatrix();
    var cx = m[4], cy = m[5], absTop = cy - hpx / 2;
    var ha = shape.egHA || 'center', va = shape.egVA || 'middle';
    var pad = 6;
    // 水平：文字框本來就跟圖形同寬，靠左右只要換 textAlign，不必移動框
    lb.set({ width: Math.max(24, wpx - 12), textAlign: ha,
             originX: 'center', left: cx });
    if (va === 'top')         lb.set({ originY: 'top',    top: absTop + pad });
    else if (va === 'bottom') lb.set({ originY: 'bottom', top: absTop + hpx - pad });
    else                      lb.set({ originY: 'center', top: cy });
    lb.setCoords();
  }

  /** 設定選取圖形內文字的對齊（h：left/center/right；v：top/middle/bottom） */
  function setLabelAlign(which, val) {
    var objs = cv.getActiveObjects().filter(function (o) {
      return o.egRole === 'shape' || (o.egRole === 'label' && shapeOf(o));
    });
    if (!objs.length) { stat('請先點選要調整的圖形'); return; }
    var n = 0;
    objs.forEach(function (o) {
      var sh = (o.egRole === 'shape') ? o : shapeOf(o);
      if (!sh) return;
      if (which === 'h') sh.egHA = val; else sh.egVA = val;
      syncLabel(sh);
      n++;
    });
    cv.requestRenderAll();
    snapshot();
    stat('已調整 ' + n + ' 個圖形內文字的對齊');
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

  /** 連接線的物件本體（唯一建構點）。
   *  ⚠ 2026-10-15 由 fabric.Line 換成 fabric.Polyline：使用者要「像批圖編輯器
   *    那樣可以自由移動跟設置線段折線」，而 Line 永遠只有兩端、加不了折點。
   *    Polyline 的點一律存**絕對座標**（本編輯器的連接線不旋轉也不縮放，
   *    所以 left/top 由 _setPositionDimensions 依點自己算，省掉一層換算）。 */
  function mkConnObj(pts, opt) {
    var o = new fabric.Polyline(pts.map(function (p) { return { x: p.x, y: p.y }; }), {
      stroke: (opt && opt.stroke) || lineColor, strokeWidth: (opt && opt.strokeWidth) || 1.5,
      fill: '', strokeUniform: true, strokeLineCap: 'round', strokeLineJoin: 'round',
      objectCaching: false, hasControls: false,
      lockScalingX: true, lockScalingY: true, lockRotation: true
    });
    return o;
  }
  /** 絕對座標的節點清單（畫面上看到的位置） */
  function connPts(ln) {
    if (!ln || !ln.points) return [];
    var m = ln.calcTransformMatrix();
    var ox = ln.pathOffset ? ln.pathOffset.x : 0, oy = ln.pathOffset ? ln.pathOffset.y : 0;
    return ln.points.map(function (p) {
      return fabric.util.transformPoint({ x: p.x - ox, y: p.y - oy }, m);
    });
  }
  /** 用絕對座標整組換掉節點（會順手把 left/top/width/height/pathOffset 重算） */
  function setConnPts(ln, abs) {
    if (!ln || !abs || abs.length < 2) return;
    ln.points = abs.map(function (p) { return { x: p.x, y: p.y }; });
    ln.set({ angle: 0, scaleX: 1, scaleY: 1, flipX: false, flipY: false });
    ln._setPositionDimensions({});
    ln.setCoords();
    ln.dirty = true;
  }

  function addConn(kind, x1, y1, x2, y2) {
    return addConnPts(kind, [{ x: snapv(x1), y: snapv(y1) }, { x: snapv(x2), y: snapv(y2) }]);
  }
  function addConnPts(kind, pts) {
    var id = nextId++;
    var ln = mkConnObj(pts);
    ln.egRole = 'conn'; ln.egId = id; ln.egKind = kind; ln.egCurve = false;
    cv.add(ln);
    /* kind：line＝沒有箭頭／arrow＝終點箭頭／darrow＝兩端都有／rarrow＝起點箭頭。
       每個箭頭記下自己在哪一端（egEnd），syncConn 才知道要擺在哪、角度朝哪邊。
       舊工作檔的箭頭沒有 egEnd，一律當成終點（見 headsOf）。 */
    connHeadKinds(kind).forEach(function (endKey) { addHead(id, endKey); });
    syncConn(ln);
    cv.setActiveObject(ln);
    cv.requestRenderAll();
    return ln;
  }


  /* ── 圖形的 8 個連接點（使用者 2026-10-15 要求）─────────────────────────
     端點位置依形狀算：矩形類是外框的四邊中點與四個角，菱形則是四個頂點加四條邊的中點
     ——用同一組外框座標去放，菱形的「角」會落在圖形外面的空白處。
     連線端點吸附到連接點之後記在線上（egPA／egPB），圖形一移動線就跟著走，
     不必再手動把線重拉一次。 */
  var PORT_KEYS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
  var PORT_SNAP = 16;          // 滑鼠離連接點幾 px 以內就吸附

  function portsOf(sh) {
    if (!sh) return null;
    var r = sh.getBoundingRect(true, true);
    var L = r.left, T = r.top, W = r.width, H = r.height, cx = L + W / 2, cy = T + H / 2;
    if (sh.egKind === 'judge') {
      // 菱形：四個頂點＋四條邊的中點（角點放在外框角上會飄在圖外）
      return { N: {x: cx, y: T}, E: {x: L + W, y: cy}, S: {x: cx, y: T + H}, W: {x: L, y: cy},
               NE: {x: L + W * 0.75, y: T + H * 0.25}, SE: {x: L + W * 0.75, y: T + H * 0.75},
               SW: {x: L + W * 0.25, y: T + H * 0.75}, NW: {x: L + W * 0.25, y: T + H * 0.25} };
    }
    if (sh.egKind === 'doc') {
      // 平行四邊形：上下邊依傾斜量內縮，點才會貼在實際的邊上
      var off = Math.min(W * 0.1, 14);
      return { N: {x: cx + off / 2, y: T}, S: {x: cx - off / 2, y: T + H},
               E: {x: L + W - off / 2, y: cy}, W: {x: L + off / 2, y: cy},
               NE: {x: L + W, y: T}, SE: {x: L + W - off, y: T + H},
               SW: {x: L, y: T + H}, NW: {x: L + off, y: T} };
    }
    return { N: {x: cx, y: T}, NE: {x: L + W, y: T}, E: {x: L + W, y: cy}, SE: {x: L + W, y: T + H},
             S: {x: cx, y: T + H}, SW: {x: L, y: T + H}, W: {x: L, y: cy}, NW: {x: L, y: T} };
  }

  function shapeById(id) {
    var r = null;
    cv.getObjects().forEach(function (o) { if (o.egRole === 'shape' && o.egId === id) r = o; });
    return r;
  }

  /** 綁定資訊 {id,port} → 絕對座標；圖形被刪掉就回 null */
  function portPos(bind) {
    if (!bind || !bind.id) return null;
    var sh = shapeById(bind.id);
    if (!sh) return null;
    var ps = portsOf(sh);
    return (ps && ps[bind.port]) ? ps[bind.port] : null;
  }

  /** 找離某一點最近的連接點（只找圖形，不找自己）；超過 PORT_SNAP 就回 null */
  function nearestPort(x, y) {
    var best = null, bd = PORT_SNAP;
    cv.getObjects().forEach(function (o) {
      if (o.egRole !== 'shape') return;
      var ps = portsOf(o);
      PORT_KEYS.forEach(function (k) {
        var pt = ps[k];
        if (!pt) return;
        var dd = Math.sqrt((pt.x - x) * (pt.x - x) + (pt.y - y) * (pt.y - y));
        if (dd < bd) { bd = dd; best = { id: o.egId, port: k, x: pt.x, y: pt.y }; }
      });
    });
    return best;
  }

  /* 拖連線端點時，把附近圖形的連接點畫出來當提示（放開就清掉） */
  var portDots = [];
  function clearPortDots() {
    portDots.forEach(function (o) { cv.remove(o); });
    portDots = [];
  }
  function showPortDots(x, y) {
    clearPortDots();
    cv.getObjects().forEach(function (o) {
      if (o.egRole !== 'shape') return;
      var r = o.getBoundingRect(true, true);
      // 只畫滑鼠附近那個圖形的點，全部畫出來畫面會一片圓點看不到圖
      if (x < r.left - 60 || x > r.left + r.width + 60 || y < r.top - 60 || y > r.top + r.height + 60) return;
      var ps = portsOf(o);
      PORT_KEYS.forEach(function (k) {
        var pt = ps[k];
        if (!pt) return;
        /* 錨點純粹是「拖端點時的提示」，一律不收滑鼠事件：收事件的話會蓋住
           端點控制圓，也會害 fabric 把下層的圖形一起拖走。 */
        var c = new fabric.Circle({ left: pt.x, top: pt.y, radius: 4,
          originX: 'center', originY: 'center', fill: '#fff',
          stroke: '#F0A24B', strokeWidth: 2, selectable: false, evented: false,
          objectCaching: false, egRole: 'port', egPortId: o.egId, egPortKey: k });
        cv.add(c); c.bringToFront();
        portDots.push(c);
      });
    });
  }



  /* 框選（activeSelection）一次搬好幾個物件時，fabric 只給「群組」這一個 target，
     它沒有 egRole，所以原本的同步完全不會被呼叫——圖形內的文字跟箭頭
     （它們都是 selectable:false，永遠不會被收進群組）就會留在原地。
     一律先用這支攤開成成員，再走原本的同步邏輯。 */
  function eachSel(t, fn) {
    if (!t) return;
    if (t.type === 'activeSelection' && t.getObjects) t.getObjects().forEach(fn);
    else fn(t);
  }
  function syncMoved(o) {
    if (!o) return;
    if (o.egRole === 'shape') { syncLabel(o); syncBoundConns(o); }
    else if (o.egRole === 'conn') syncConn(o);   // 箭頭不在群組裡，要自己重算位置
  }

  /** 圖形移動／縮放後，把綁在它身上的連線端點一起帶走 */
  function syncBoundConns(sh) {
    if (!sh || sh.egRole !== 'shape') return;
    cv.getObjects().forEach(function (o) {
      if (o.egRole !== 'conn') return;
      /* 這條線自己也在框選裡面時，fabric 會整組搬它，這裡再寫一次絕對
         座標等於移兩次（而且 setConnPts 會把群組相對的 left/top 整個重算掉）。 */
      if (o.group) return;
      if ((!o.egPA || o.egPA.id !== sh.egId) && (!o.egPB || o.egPB.id !== sh.egId)) return;
      var a = lineAbs(o);
      var pa = portPos(o.egPA), pb = portPos(o.egPB);
      setLineAbs(o, pa ? pa.x : a.x1, pa ? pa.y : a.y1, pb ? pb.x : a.x2, pb ? pb.y : a.y2);
      syncConn(o);
    });
  }

  /** 匯入時：端點剛好落在某個圖形的連接點附近就自動綁起來 */
  function autoBindConns() {
    cv.getObjects().forEach(function (o) {
      if (o.egRole !== 'conn') return;
      var a = lineAbs(o);
      var pa = nearestPort(a.x1, a.y1), pb = nearestPort(a.x2, a.y2);
      if (pa) o.egPA = { id: pa.id, port: pa.port };
      if (pb) o.egPB = { id: pb.id, port: pb.port };
    });
  }


  /* ── 對齊輔助線（使用者 2026-10-15：要像 Figma 一樣看得到有沒有跟上面對齊）──
     拖曳中比對其他物件的 左／中／右 與 上／中／下，差距在 GUIDE_SNAP 以內就
     吸過去並畫一條線。輔助線是暫時的畫面元素，**存檔與復原都不可以收進去**
     （跟連接點提示一樣，見 snapshot()／doSave()）。 */
  var GUIDE_SNAP = 6;
  var guideOn = true;            // 對齊輔助線總開關（工具列「對齊」鈕）
  var guideObjs = [];

  function clearGuides() {
    guideObjs.forEach(function (g) { cv.remove(g); });
    guideObjs = [];
    /* 畫線預覽等暫時物件一律標 egRole='guide'，這裡一併掃掉——
       它們絕對不可以被存進工作檔、也不可以被印進插入文件的那張圖。 */
    cv.getObjects().slice().forEach(function (o) { if (o.egRole === 'guide') cv.remove(o); });
  }
  function addGuide(pos, dir) {
    var ln = new fabric.Line(
      dir === 'v' ? [pos, -4000, pos, 4000] : [-4000, pos, 4000, pos],
      { stroke: '#F0A24B', strokeWidth: 1, strokeDashArray: [4, 3],
        selectable: false, evented: false, objectCaching: false, egRole: 'guide' });
    cv.add(ln); ln.bringToFront();
    guideObjs.push(ln);
  }

  /** 拖曳中的對齊：回傳有沒有吸附到東西 */
  function applyGuides(o) {
    clearGuides();
    if (!guideOn) return false;
    if (!o || o.egRole === 'handle') return false;
    var r = o.getBoundingRect(true, true);
    var mine = {
      x: [r.left, r.left + r.width / 2, r.left + r.width],
      y: [r.top, r.top + r.height / 2, r.top + r.height]
    };
    var bx = null, by = null;
    cv.getObjects().forEach(function (t) {
      if (t === o) return;
      if (t.egRole === 'handle' || t.egRole === 'port' || t.egRole === 'guide'
          || t.egRole === 'label' || t.egRole === 'head') return;
      // 跟著一起被選取的物件不算（整組在動，互相對齊沒有意義）
      if (o.type === 'activeSelection' && o._objects && o._objects.indexOf(t) >= 0) return;
      var tr = t.getBoundingRect(true, true);
      var tx = [tr.left, tr.left + tr.width / 2, tr.left + tr.width];
      var ty = [tr.top, tr.top + tr.height / 2, tr.top + tr.height];
      mine.x.forEach(function (a) {
        tx.forEach(function (b) {
          var dd = Math.abs(a - b);
          if (dd <= GUIDE_SNAP && (!bx || dd < bx.d)) bx = { d: dd, pos: b, delta: b - a };
        });
      });
      mine.y.forEach(function (a) {
        ty.forEach(function (b) {
          var dd = Math.abs(a - b);
          if (dd <= GUIDE_SNAP && (!by || dd < by.d)) by = { d: dd, pos: b, delta: b - a };
        });
      });
    });
    if (bx) { o.set({ left: o.left + bx.delta }); addGuide(bx.pos, 'v'); }
    if (by) { o.set({ top: o.top + by.delta }); addGuide(by.pos, 'h'); }
    if (bx || by) o.setCoords();
    return !!(bx || by);
  }

  /* ── Alt＋拖曳＝複製一份（使用者 2026-10-15）──────────────────────────
     做法是「在**原來的位置**留下一份複本」，使用者拖走的還是原本那一個，
     放開之後看起來就像複製出一個新的。複製一律走 addNode／addFreeText／addConn，
     不用 fabric 的 clone——節點的文字標籤是另一個物件靠 egId 配對的，
     clone 出來的那一份不會有自己的標籤，字會留在原地不跟著走。 */
  var altDragDone = false, altDragFrom = null;

  function dupObject(o, atLeft, atTop) {
    if (!o) return null;
    if (o.egRole === 'shape') {
      var lb = labelOf(o);
      var sh = addNode(o.egKind || 'proc', atLeft, atTop, lb ? (lb.text || '') : '');
      sh.set({ scaleX: o.scaleX, scaleY: o.scaleY, angle: o.angle,
               fill: o.fill, stroke: o.stroke, strokeWidth: o.strokeWidth });
      sh.setCoords();
      syncLabel(sh);
      return sh;
    }
    if (o.egRole === 'text') {
      var t = addFreeText(atLeft, atTop);
      t.set({ text: o.text, width: o.width, fontSize: o.fontSize, fill: o.fill, fontFamily: o.fontFamily });
      t.setCoords();
      return t;
    }
    if (o.egRole === 'conn') {
      var dx = atLeft - o.left, dy = atTop - o.top;
      var np = addConnPts(o.egKind || 'arrow', connPts(o).map(function (p) {
        return { x: p.x + dx, y: p.y + dy };     // 折點也要一起複製，不然複本會變成直線
      }));
      np.egCurve = !!o.egCurve;
      np.set({ stroke: o.stroke, strokeWidth: o.strokeWidth });
      syncConn(np);
      return np;
    }
    return null;
  }


  /* ── 框選之後的對齊與排列（使用者 2026-10-15）────────────────────────────
     「所有的中心點都對齊，且不重疊」。基準一律取**第一個**（畫布上最早建立的
     那一個，也就是 fabric 給的物件順序第一個），其餘跟著它走。
     ⚠ 一定要先 discardActiveObject()：物件還在 activeSelection 裡面時，
       getBoundingRect(true,true) 回的是**群組座標**不是絕對座標，
       拿它算對齊會整組亂跑（本專案在批圖編輯器踩過同一個坑）。 */
  var ARRANGE_GAP = 24;          // 等距排列的間距（px）

  function arrangeSelected(mode) {
    var sel = cv.getActiveObjects().filter(function (o) {
      return o.egRole === 'shape' || o.egRole === 'text';
    });
    if (sel.length < 2) { stat('請先框選兩個以上的圖形或文字（按住拖曳框起來，或按住 Shift 逐個點選）'); return; }
    /* 先完整解除群組選取、重繪一次，**之後**才讀座標。
       物件還在 activeSelection 裡面時，left/top 是相對於群組中心的值，
       calcTransformMatrix／getBoundingRect 也會把群組矩陣算進去，
       在這個時間點取基準會把所有圖形推到畫布左上角（實測中心從 106 變成 0）。 */
    cv.discardActiveObject();
    sel.forEach(function (o) { if (o.group) o.group = undefined; o.setCoords(); });
    cv.requestRenderAll();

    /* 絕對中心一律讀 calcTransformMatrix 的 m[4]/m[5]。
       ⚠ 不要改用 getBoundingRect()：物件剛從 activeSelection 出來時它回的是
         **還沒更新的舊座標**（實測三個圖形都回 0，而 calcTransformMatrix 回正確的
         106／386／181），呼叫過 setCoords() 也一樣。 */
    var info = sel.map(function (o) {
      var m = o.calcTransformMatrix();
      return { o: o, w: o.getScaledWidth(), h: o.getScaledHeight(), cx: m[4], cy: m[5] };
    });
    var base = info[0];

    /* 用「目前中心 → 目標中心」的差去位移 left/top，不直接指定座標：
       圖形、文字的 origin 設定不一樣（文字是 center），直接算 left 會各自錯開。 */
    function moveCenter(it, cx, cy) {
      var m = it.o.calcTransformMatrix();
      it.o.set({ left: it.o.left + (cx - m[4]), top: it.o.top + (cy - m[5]) });
      it.o.setCoords();
      if (it.o.egRole === 'shape') { syncLabel(it.o); syncBoundConns(it.o); }
    }
    function moveTo(it, nx, ny) {       // nx/ny＝新的「外框左上角」
      moveCenter(it, nx + it.w / 2, ny + it.h / 2);
    }
    info.forEach(function (it) { it.left = it.cx - it.w / 2; it.top = it.cy - it.h / 2; });

    if (mode === 'al') {                // 左邊緣對齊第一個的左邊緣
      info.forEach(function (it) { moveTo(it, base.left, it.top); });
    } else if (mode === 'ar') {         // 右邊緣對齊第一個的右邊緣
      var rx = base.left + base.w;
      info.forEach(function (it) { moveTo(it, rx - it.w, it.top); });
    } else if (mode === 'vc') {         // 中心 X 對齊第一個
      info.forEach(function (it) { moveTo(it, base.cx - it.w / 2, it.top); });
    } else if (mode === 'hc') {         // 中心 Y 對齊第一個
      info.forEach(function (it) { moveTo(it, it.left, base.cy - it.h / 2); });
    } else if (mode === 'dv') {         // 由上到下等距、不重疊，中心對齊成一直行
      var byY = info.slice().sort(function (a, b) { return a.cy - b.cy; });
      var y = byY[0].top;
      byY.forEach(function (it) {
        moveTo(it, base.cx - it.w / 2, y);
        y += it.h + ARRANGE_GAP;
      });
    } else if (mode === 'dh') {         // 由左到右等距、不重疊，中心對齊成一橫列
      var byX = info.slice().sort(function (a, b) { return a.cx - b.cx; });
      var x = byX[0].left;
      byX.forEach(function (it) {
        moveTo(it, x, base.cy - it.h / 2);
        x += it.w + ARRANGE_GAP;
      });
    }

    // 排完重新選起來，方便接著再按別的排列鈕
    var ns = new fabric.ActiveSelection(sel, { canvas: cv });
    cv.setActiveObject(ns);
    cv.requestRenderAll();
    snapshot();
    stat({ vc: '已把中心對齊成一直行', hc: '已把中心對齊成一橫列',
           dv: '已由上到下等距排好（不重疊）', dh: '已由左到右等距排好（不重疊）' }[mode] || '已排列');
  }

  /** 這種線要在哪幾端放箭頭 */
  function connHeadKinds(kind) {
    if (kind === 'darrow') return ['a', 'b'];
    if (kind === 'rarrow') return ['a'];
    if (kind === 'arrow')  return ['b'];
    return [];
  }

  function addHead(id, endKey) {
    var hd = new fabric.Polygon(arrowHeadPoints(), {
      originX: 'center', originY: 'bottom',
      fill: lineColor, stroke: lineColor, strokeWidth: 0.5,
      selectable: false, evented: false, objectCaching: false
    });
    hd.egRole = 'head'; hd.egId = id; hd.egEnd = endKey;
    cv.add(hd);
    return hd;
  }

  /** 一條線的箭頭（可能兩個）；舊資料沒有 egEnd 的一律當終點 */
  function headsOf(ln) {
    var out = [];
    cv.getObjects().forEach(function (o) {
      if (o.egRole === 'head' && o.egId === ln.egId) {
        if (!o.egEnd) o.egEnd = 'b';
        out.push(o);
      }
    });
    return out;
  }

  /** 換一條線的箭頭樣式（無／單／雙／反向） */
  function setConnKind(ln, kind) {
    if (!ln || ln.egRole !== 'conn') return;
    headsOf(ln).forEach(function (h) { cv.remove(h); });
    ln.egKind = kind;
    connHeadKinds(kind).forEach(function (endKey) { addHead(ln.egId, endKey); });
    syncConn(ln);
    cv.requestRenderAll();
  }

  /** 把線的頭尾對調（箭頭跟著反向，綁定的錨點也跟著換邊） */
  function flipConn(ln) {
    if (!ln || ln.egRole !== 'conn') return;
    var pa = ln.egPA, pb = ln.egPB;
    setConnPts(ln, connPts(ln).reverse());     // 折點也要跟著倒過來
    ln.egPA = pb; ln.egPB = pa;
    syncConn(ln);
    showHandles(ln);
    cv.requestRenderAll();
  }

  /* ── 折線：加折點／直角／取直／圓滑（使用者 2026-10-15：要像批圖編輯器
        那樣「自由移動跟設置線段折線」）──────────────────────────────── */
  /** 在最長的那一段中間加一個折點（最長的那段才有空間拖，加在短的看起來像沒反應） */
  function connAddNode(ln) {
    var ps = connPts(ln);
    if (ps.length < 2) return false;
    var at = 0, best = -1;
    for (var i = 0; i < ps.length - 1; i++) {
      var dd = Math.hypot(ps[i + 1].x - ps[i].x, ps[i + 1].y - ps[i].y);
      if (dd > best) { best = dd; at = i; }
    }
    ps.splice(at + 1, 0, { x: (ps[at].x + ps[at + 1].x) / 2, y: (ps[at].y + ps[at + 1].y) / 2 });
    setConnPts(ln, ps);
    return true;
  }
  /** 直角折線：依兩端的相對位置自動決定先走橫的還是先走直的
   *  （綁在圖形錨點上時以錨點方向為準，從圖形的左右兩側出發就先走橫的） */
  function connOrtho(ln) {
    var a = lineAbs(ln);
    var dx = a.x2 - a.x1, dy = a.y2 - a.y1;
    if (Math.abs(dx) < 2 || Math.abs(dy) < 2) return false;   // 本來就是水平／垂直，不必轉折
    var horizFirst = true;
    var pk = (ln.egPA && ln.egPA.port) || '';
    if (pk === 'N' || pk === 'S') horizFirst = false;
    else if (pk === 'E' || pk === 'W') horizFirst = true;
    else horizFirst = Math.abs(dx) >= Math.abs(dy);
    var mid = horizFirst ? { x: a.x1 + dx / 2, y: a.y1 } : { x: a.x1, y: a.y1 + dy / 2 };
    var mid2 = horizFirst ? { x: a.x1 + dx / 2, y: a.y2 } : { x: a.x2, y: a.y1 + dy / 2 };
    setConnPts(ln, [{ x: a.x1, y: a.y1 }, mid, mid2, { x: a.x2, y: a.y2 }]);
    ln.egCurve = false;
    return true;
  }
  /** 取直：折點全部移除 */
  function connStraight(ln) {
    var a = lineAbs(ln);
    setConnPts(ln, [{ x: a.x1, y: a.y1 }, { x: a.x2, y: a.y2 }]);
    ln.egCurve = false;
    return true;
  }

  function headOf(ln) {
    var r = null;
    cv.getObjects().forEach(function (o) { if (o.egRole === 'head' && o.egId === ln.egId) r = o; });
    return r;
  }

  /** 線的端點是靠 x1/y1/x2/y2，但 fabric 移動 Line 時改的是 left/top——
   *  兩套座標要自己對起來，不然箭頭會跟線分家。這裡一律以「絕對端點」為準重算。 */
  /** 頭尾兩端的絕對座標（中間的折點不在這裡；要全部請用 connPts） */
  function lineAbs(ln) {
    var ps = connPts(ln);
    if (ps.length < 2) return { x1: 0, y1: 0, x2: 0, y2: 0 };
    var a = ps[0], b = ps[ps.length - 1];
    return { x1: a.x, y1: a.y, x2: b.x, y2: b.y };
  }
  /** 只換頭尾、中間的折點留著（圖形移動時帶著線走就是走這一支） */
  function setLineAbs(ln, x1, y1, x2, y2) {
    var ps = connPts(ln);
    if (ps.length < 2) ps = [{ x: x1, y: y1 }, { x: x2, y: y2 }];
    ps[0] = { x: x1, y: y1 };
    ps[ps.length - 1] = { x: x2, y: y2 };
    setConnPts(ln, ps);
  }
  function syncConn(ln) {
    var hs = headsOf(ln);
    if (!hs.length) return;
    var ps = connPts(ln);
    if (ps.length < 2) return;
    /* 箭頭本體的三個點是 (0,0) 尖端、(±6,-11) 底邊，配上 originY:'bottom'
       ＝「left/top 指的是尖端」，而**沒有旋轉時它是朝下的**（底邊在尖端上方）。
       所以要轉到線的方向是 φ−90，不是 φ+90——寫成 +90 會讓每一支箭頭都剛好
       反過來指（尖端位置仍在終點上，所以乍看很像對的，要量底邊在哪一側才看得出來）。 */
    var n = ps.length;
    hs.forEach(function (hd) {
      /* 折線的箭頭角度要看「貼著那一端的那一段」，不是頭尾直線的方向——
         用頭尾算的話，轉了彎的線箭頭會朝著斜的方向，看起來像沒對準。 */
      var tip = (hd.egEnd === 'a') ? ps[0] : ps[n - 1];
      var from = (hd.egEnd === 'a') ? ps[1] : ps[n - 2];
      var ang = Math.atan2(tip.y - from.y, tip.x - from.x) * 180 / Math.PI - 90;
      hd.set({ left: tip.x, top: tip.y, angle: ang, fill: ln.stroke, stroke: ln.stroke });
      hd.setCoords();
    });
  }

  /* ── 節點編輯：選到連接線時，每個節點長出一個可拖的控制點，
        線段中間另有空心「＋」可以插入折點（做法與批圖編輯器的「編輯端點」同一套）。
        ⚠ 刻意改用 fabric 的**自訂控制點**而不是另外加一顆 Circle 當 handle：
          另加物件時 fabric 會先把原本選著的線取消選取、控制圓也跟著被清掉，
          使用者就「按得到卻拖不動」（2026-10-15 回報過一次）；
          控制點是物件自己的一部分，不會有這個問題，而且不會被存進工作檔。 ── */
  function connNodePos(i) {
    return function (dim, finalMatrix, ln) {
      if (!ln.canvas || !ln.points || !ln.points[i]) return new fabric.Point(-99999, -99999);
      var p = connPts(ln)[i];
      if (!p) return new fabric.Point(-99999, -99999);
      return fabric.util.transformPoint(new fabric.Point(p.x, p.y), ln.canvas.viewportTransform);
    };
  }
  function connMidPos(i) {
    return function (dim, finalMatrix, ln) {
      if (!ln.canvas || !ln.points) return new fabric.Point(-99999, -99999);
      var ps = connPts(ln), a = ps[i], b = ps[i + 1];
      if (!a || !b) return new fabric.Point(-99999, -99999);
      return fabric.util.transformPoint(new fabric.Point((a.x + b.x) / 2, (a.y + b.y) / 2),
                                        ln.canvas.viewportTransform);
    };
  }
  function connNodeAction(i) {
    return function (eventData, transform, x, y) {
      var ln = transform.target;
      /* 剛剛才在這個控制點上「取消節點」：接下來那幾下 mousemove 要忽略，
         不然刪完之後索引整個位移，會把別的節點拖到滑鼠位置去。 */
      if (ln.__egNodeGuard && Date.now() - ln.__egNodeGuard < 600) return false;
      var ps = connPts(ln);
      if (!ps[i]) return false;
      var isEnd = (i === 0 || i === ps.length - 1);
      var nx = snapv(x), ny = snapv(y);
      if (isEnd) {
        // 端點靠近圖形的連接點就吸過去，並記住綁在哪（之後圖形移動線會跟著走）
        showPortDots(x, y);
        var hit = nearestPort(x, y);
        if (hit) { nx = hit.x; ny = hit.y; }
        if (i === 0) ln.egPA = hit ? { id: hit.id, port: hit.port } : null;
        else         ln.egPB = hit ? { id: hit.id, port: hit.port } : null;
      }
      if (!isFinite(nx) || !isFinite(ny)) return false;
      ps[i] = { x: nx, y: ny };
      setConnPts(ln, ps);
      syncConn(ln);
      return true;
    };
  }
  function connInsertAction(i) {
    return function (eventData, transform) {
      var ln = transform.target;
      var now = Date.now();
      if (ln.__egIns && now - ln.__egIns < 350) return;   // 防連點插出一堆節點
      ln.__egIns = now;
      var ps = connPts(ln), a = ps[i], b = ps[i + 1];
      if (!a || !b) return;
      ps.splice(i + 1, 0, { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
      setConnPts(ln, ps);
      ln.controls = buildConnControls(ln);
      syncConn(ln);
      cv.requestRenderAll();
      snapshot();
    };
  }
  /** 取消一個折點：把它併回相鄰的節點（端點不可取消，至少要留兩點） */
  function connDelNode(ln, i) {
    var ps = connPts(ln);
    if (ps.length <= 2) return false;
    if (i <= 0 || i >= ps.length - 1) return false;    // 兩端是接圖形用的，不可以取消
    ps.splice(i, 1);
    setConnPts(ln, ps);
    syncConn(ln);
    return true;
  }
  /** 兩個節點靠得太近就併成一個（畫線時連點兩下、或把節點拖到重疊，會留下一堆看不出來的點） */
  function connDedupe(ln, tol) {
    var ps = connPts(ln);
    if (ps.length < 3) return false;
    var out = [ps[0]], t = tol || 3;
    for (var i = 1; i < ps.length; i++) {
      var a = out[out.length - 1];
      if (Math.abs(ps[i].x - a.x) < t && Math.abs(ps[i].y - a.y) < t && i !== ps.length - 1) continue;
      out.push(ps[i]);
    }
    // 尾點跟前一點重疊時留尾點（它可能綁著圖形錨點）
    while (out.length > 2 && Math.abs(out[out.length - 1].x - out[out.length - 2].x) < t
                          && Math.abs(out[out.length - 1].y - out[out.length - 2].y) < t) {
      out.splice(out.length - 2, 1);
    }
    if (out.length !== ps.length && out.length >= 2) { setConnPts(ln, out); syncConn(ln); return true; }
    return false;
  }

  /* 目前處於「節點編輯」的那條線（egId）。預設是 null＝選起來只看得到兩端的圓點。
     使用者 2026-10-15：「箭頭變出一堆點導致完全無法拉到端點」「預設的節點影響使用
     （拖移時會變成彎折）」——所以中間的折點與「＋」一律要雙擊線（或按工具列「節點」）
     才出現，平常只留兩端那兩顆，拖線才不會動不動就被拉成彎的。 */
  var nodeEditId = null;

  function buildConnControls(ln) {
    var ctrls = {}, n = (ln.points || []).length;
    var full = (nodeEditId !== null && nodeEditId === ln.egId);

    /* 中間那一段的「＋」要**先**定義。
       fabric 的 _findTargetCorner 是從 controls 的最後一個鍵往前找，
       先定義＝後被命中；端點放最後定義，重疊時端點才搶得贏
       （這正是「一堆點疊在一起、就是拉不到端點」的直接原因）。 */
    if (full && n <= 20) {
      for (var k = 0; k < n - 1; k++) {
        (function (k) {
          ctrls['m' + k] = new fabric.Control({
            positionHandler: connMidPos(k),
            mouseDownHandler: connInsertAction(k),
            actionHandler: function () { return false; },
            cursorStyle: 'copy',
            /* 線段在畫面上太短時整顆不要出現——不只是不畫，連命中判定都要關掉
               （getVisibility 兩邊都會用到），否則短線上的「＋」會蓋住旁邊的端點。 */
            getVisibility: function (o) { return segScreenLen(o, k) >= 30; },
            render: function (ctx, left, top) {
              ctx.save();
              ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#F0A24B'; ctx.lineWidth = 1.2;
              ctx.beginPath(); ctx.arc(left, top, 5, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
              ctx.lineWidth = 1.4;
              ctx.beginPath();
              ctx.moveTo(left - 3, top); ctx.lineTo(left + 3, top);
              ctx.moveTo(left, top - 3); ctx.lineTo(left, top + 3);
              ctx.stroke();
              ctx.restore();
            }
          });
        })(k);
      }
    }

    // 中間的折點（只有節點編輯模式才有）
    for (var i = 1; i < n - 1; i++) {
      (function (i) {
        ctrls['p' + i] = new fabric.Control({
          positionHandler: connNodePos(i),
          actionHandler: connNodeAction(i),
          mouseDownHandler: connNodeDelHandler(i),
          actionName: 'egConnNode',
          cursorStyle: 'crosshair',
          getVisibility: function () { return full; },
          render: function (ctx, left, top) {
            ctx.save();
            ctx.fillStyle = '#F0A24B'; ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.arc(left, top, 5.5, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
            ctx.restore();
          }
        });
      })(i);
    }

    // 兩端：永遠都在，而且**最後定義**＝重疊時優先命中
    [0, n - 1].forEach(function (i) {
      if (i < 0 || n < 2) return;
      var bound = (i === 0 && ln.egPA) || (i === n - 1 && ln.egPB);
      ctrls['p' + i] = new fabric.Control({
        positionHandler: connNodePos(i),
        actionHandler: connNodeAction(i),
        actionName: 'egConnNode',
        cursorStyle: 'crosshair',
        render: function (ctx, left, top) {
          ctx.save();
          // 扣在圖形錨點上的那一端畫成深色實心，看得出來「這端是綁住的」
          ctx.fillStyle = bound ? '#8A5A2B' : '#F0A24B';
          ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 2;
          ctx.beginPath(); ctx.arc(left, top, 7, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
          ctx.restore();
        }
      });
    });
    return ctrls;
  }

  /** 第 k 段在**畫面上**有多長（含顯示縮放），用來決定短線段要不要長出「＋」 */
  function segScreenLen(ln, k) {
    var ps = connPts(ln), a = ps[k], b = ps[k + 1];
    if (!a || !b) return 0;
    var z = (ln.canvas && ln.canvas.viewportTransform) ? ln.canvas.viewportTransform[0] : 1;
    return Math.hypot(b.x - a.x, b.y - a.y) * z;
  }

  /** 折點上「再點一下」或「Alt＋點」＝取消那個折點（併回相鄰節點） */
  function connNodeDelHandler(i) {
    return function (eventData, transform) {
      var ln = transform.target, now = Date.now();
      var alt = eventData && (eventData.altKey || eventData.ctrlKey);
      var again = ln.__egNodeHit && ln.__egNodeHit.i === i && (now - ln.__egNodeHit.t) < 600;
      ln.__egNodeHit = { i: i, t: now };
      if (!alt && !again) return;              // 第一下＝準備拖曳，不刪
      if (!connDelNode(ln, i)) return;
      ln.__egNodeGuard = now;                  // 擋掉這一輪的拖曳（索引已經變了）
      ln.__egNodeHit = null;
      ln.controls = buildConnControls(ln);
      ln.setCoords();
      cv.requestRenderAll();
      snapshot();
      stat('已取消這個折點（併回相鄰的節點）');
    };
  }
  /** 收掉所有連接線的節點控制點（名稱沿用 clearHandles，呼叫端不必改） */
  function clearHandles() {
    if (!cv) return;
    cv.getObjects().forEach(function (o) {
      if (o.egRole === 'conn' && o.__egNodes) {
        delete o.__egNodes; delete o.controls;
        o.hasControls = false; o.hasBorders = true;
        o.setCoords();
      }
    });
  }
  function showHandles(ln) {
    if (!ln || ln.egRole !== 'conn') return;
    clearHandles();
    ln.controls = buildConnControls(ln);
    ln.__egNodes = true;
    ln.hasControls = true;
    ln.hasBorders = false;
    ln.setCoords();
    syncNodeBtn();
    cv.requestRenderAll();
  }
  /** 工具列「節點」那顆鈕要跟目前狀態一致（唯一一處，不要各呼叫端各自 toggle） */
  function syncNodeBtn() {
    var b = el('egfConnEdit');
    if (b) b.classList.toggle('on', nodeEditId !== null);
  }
  /** 切換「節點編輯」：on 不給就是反向切換。回傳切換後的狀態 */
  function setNodeEdit(ln, on) {
    if (!ln || ln.egRole !== 'conn') return false;
    var want = (typeof on === 'boolean') ? on : (nodeEditId !== ln.egId);
    nodeEditId = want ? ln.egId : null;
    if (want) connDedupe(ln);          // 進去之前先把重疊的點併掉，免得看到一堆疊在一起的圓點
    showHandles(ln);
    return want;
  }

  /* ── 插入 AS 文件編號／名稱（2026-10-15 使用者交辦：流程圖、烏龜圖裡面也要能用）──
     流程圖的文字是畫布上的物件、不是 HTML，所以沒辦法像內文那樣包一個 <span data-asdoc>。
     改成把「引用了哪一份文件」記在**物件自己的 egAsRef** 上並一起存進工作檔，
     打開時再跟資料庫現況比對（asRefCheck），改過的就提示一鍵更新。
     挑文件的畫面刻意**由呼叫端提供**（opt.onPickAsRef）：那份清單與樣式設定是
     AS 文件模組的事，流程圖編輯器不該自己去打 AS 的 API（它也用在別的頁面）。 */
  var onPickAsRefCb = null, onAsRefCheckCb = null;

  /** 目前選到的那一個「放得下文字」的物件（圖形→它的標籤；文字→本身） */
  function asRefTarget() {
    var o = cv && cv.getActiveObject();
    if (!o) return null;
    if (o.egRole === 'shape') return labelOf(o);
    if (o.egRole === 'label' || o.egRole === 'text') return o;
    return null;
  }

  /** 把文字插進目前選到的圖形／文字；都沒選就在畫布中間放一個新的文字物件 */
  function asRefInsert(meta) {
    if (!meta || !meta.text) return;
    var t = asRefTarget();
    if (!t) {
      t = addFreeText(Math.round(LOGW / 2) - 70, Math.round(LOGH / 2) - 10);
      t.set({ text: '' });
    }
    var cur = String(t.text || '');
    t.set({ text: cur ? (cur + meta.text) : meta.text });
    /* 一個物件可能引用好幾份文件（例如一個方框裡寫兩份表單），所以 egAsRef 是陣列。
       `at` 記的是插入當下的字串位置，更新文字時才知道要換哪一段。 */
    var list = (t.egAsRef && t.egAsRef.slice) ? t.egAsRef.slice() : [];
    list.push({ doc_id: meta.doc_id, no: meta.no, name: meta.name,
                mode: meta.mode, tpl: meta.tpl || '', text: meta.text, at: cur.length });
    t.egAsRef = list;
    var sh = shapeOf(t);
    if (sh) syncLabel(sh);
    cv.setActiveObject(sh || t);
    cv.requestRenderAll();
    snapshot();
    stat('已插入「' + meta.text + '」（記住了是哪一份 AS 文件，之後那份文件改編號或改名會提示更新）');
  }

  /** 這張流程圖引用到的全部 AS 文件（去重） */
  function asRefList() {
    var out = [], seen = {};
    if (!cv) return out;
    cv.getObjects().forEach(function (o) {
      (o.egAsRef || []).forEach(function (r) {
        var k = String(r.doc_id);
        if (!seen[k]) { seen[k] = 1; out.push({ doc_id: r.doc_id, no: r.no, name: r.name }); }
      });
    });
    return out;
  }

  /** 把物件上所有過時的引用文字換成新的（cur＝{doc_id:{no,name}}）。回傳換掉幾處 */
  function asRefApply(cur) {
    var n = 0;
    cv.getObjects().forEach(function (o) {
      var list = o.egAsRef || [];
      if (!list.length) return;
      var txt = String(o.text || ''), changed = false;
      list.forEach(function (r) {
        var c = cur[String(r.doc_id)];
        if (!c) return;
        var want = asRefText(r.mode, c.no, c.name, r.tpl);
        if (want === r.text) return;
        // 以「原本插進去的那段字」為準做取代；找不到就不動（使用者可能自己改過字）
        if (txt.indexOf(r.text) >= 0) {
          txt = txt.replace(r.text, want);
          r.text = want; r.no = c.no; r.name = c.name;
          changed = true; n++;
        }
      });
      if (changed) {
        o.set({ text: txt });
        var sh = shapeOf(o);
        if (sh) syncLabel(sh);
      }
    });
    if (n) { cv.requestRenderAll(); snapshot(); }
    return n;
  }

  /** 與後端 eg_asdoc_insert_text() 同一套規則（只帶名稱時固定加「」） */
  function asRefText(mode, no, name, tpl) {
    if (mode === 'no') return String(no || '');
    if (mode === 'name') return '「' + String(name || '') + '」';
    var t = tpl || '{name}　({no})';
    return t.replace(/\{no\}/g, String(no || '')).replace(/\{name\}/g, String(name || ''));
  }

  /** 打開時比對資料庫現況，有改過就在狀態列提示並長出「更新引用文字」鈕 */
  function asRefCheck() {
    var bar = el('egfAsBar');
    if (bar) bar.style.display = 'none';
    if (!onAsRefCheckCb) return;
    var list = asRefList();
    if (!list.length) return;
    onAsRefCheckCb(list, function (cur) {
      if (!cur) return;
      var bad = [];
      cv.getObjects().forEach(function (o) {
        (o.egAsRef || []).forEach(function (r) {
          var c = cur[String(r.doc_id)];
          if (!c) { bad.push('（文件已被刪除）' + r.no + ' ' + r.name); return; }
          var want = asRefText(r.mode, c.no, c.name, r.tpl);
          if (want !== r.text) bad.push(r.text + ' → ' + want);
        });
      });
      if (!bad.length) return;
      window.__egfAsCur = cur;
      if (bar) {
        el('egfAsBarTxt').textContent = '這張流程圖引用的 AS 文件有 ' + bad.length
          + ' 處已經改了：' + bad.slice(0, 3).join('、') + (bad.length > 3 ? ' …' : '');
        bar.style.display = '';
      }
    });
  }

  /* ── 歷史（復原/重做）──────────────────────────────────────────────── */
  /** 序列化：fabric 的 toJSON 不含畫布尺寸，自己補上 egW/egH，
   *  不然存回來的流程圖會被還原成預設尺寸，圖形跑到畫布外面 */
  function serialize() {
    var jo = cv.toJSON(['egRole', 'egId', 'egKind', 'egPA', 'egPB', 'egEnd', 'egHA', 'egVA', 'egCurve',
                        'egAsRef']);
    jo.egW = LOGW;
    jo.egH = LOGH;
    return JSON.stringify(jo);
  }

  function snapshot() {
    if (quiet || !cv) return;
    /* ⚠ 這裡**不可以**呼叫 clearHandles()：節點控制點是連接線物件自己的一部分
       （fabric 的自訂 controls，不會被序列化），清掉只會讓使用者剛選起來的線
       突然沒有節點可以拖——而每做完一個動作都會 snapshot 一次，症狀就是
       「線選起來了、卻拖不到端點」。要清暫時**物件**請用下面兩支。 */
    clearPortDots();        // 連接點提示只是暫時畫上去的，不可以存進工作檔
    clearGuides();          // 對齊輔助線同理
    var j = serialize();
    if (hist[histAt] === j) return;
    hist = hist.slice(0, histAt + 1);
    hist.push(j);
    if (hist.length > 40) hist.shift();
    histAt = hist.length - 1;
  }
  /* 從 Word 匯入的流程圖（src/common/as_doc_flow_import_lib.php 產生的中介格式）。
     刻意**不是** Fabric 的序列化格式：那個格式綁 Fabric 版本，手寫很容易出錯；
     改成在這裡用編輯器自己的 addNode／addConn／addFreeText 重建，
     長出來的物件就跟使用者自己畫的一模一樣（拖曳、雙擊改字、改色全都照常）。 */
  function buildFromImport(jj) {
    quiet = true;
    cv.clear();
    setLogicalSize(Math.max(200, jj.w || 600), Math.max(200, jj.h || 400));
    var snapWas = snap; snap = false;          // 匯入的座標要照原樣，不可以對齊格線
    (jj.items || []).forEach(function (it) {
      try {
        if (it.t === 'conn') { addConn(it.kind === 'arrow' ? 'arrow' : 'line', it.x1, it.y1, it.x2, it.y2); return; }
        if (it.t === 'text') {
          var t = addFreeText(it.x, it.y);
          t.set({ text: String(it.text || ''), width: Math.max(24, it.w || 80) });
          return;
        }
        var sh = addNode(it.kind || 'proc', it.x, it.y, String(it.text || ''));
        // addNode 用的是固定尺寸，要縮放成 Word 裡的實際大小，標籤跟著重新置中
        var bw = sh.width || 1, bh = sh.height || 1;
        sh.set({ scaleX: (it.w || bw) / bw, scaleY: (it.h || bh) / bh });
        sh.setCoords();
        syncLabel(sh);
      } catch (err) {}
    });
    snap = snapWas;
    autoBindConns();            // 端點本來就畫在圖形邊上，直接綁起來
    cv.discardActiveObject();
    cv.requestRenderAll();
    quiet = false;
    // 縮放統一由 open() 的 afterLoad 處理（它在這之後才跑，在這裡縮會被它蓋掉）
  }

  function restore(j, cb) {
    quiet = true;
    // 畫布尺寸要先套：loadFromJSON 不管它，不先設好的話圖形會跑到畫布外面
    try {
      var jj = (typeof j === 'string') ? JSON.parse(j) : j;
      if (jj && jj.egImport) {                 // Word 匯入的中介格式：用建構函式重建
        buildFromImport(jj);
        snapshot();
        if (cb) cb();
        return;
      }
      if (jj && jj.egW && jj.egH) setLogicalSize(jj.egW, jj.egH);
    } catch (e) {}
    cv.loadFromJSON(j, function () {
      // 還原後要把 egId 的最大值找回來，否則新增的節點會撞到既有 id
      var mx = 0, oldLines = [];
      cv.getObjects().forEach(function (o) {
        if (o.egRole === 'handle' || o.egRole === 'port' || o.egRole === 'guide') { cv.remove(o); return; }
        if (o.egId && o.egId > mx) mx = o.egId;
        if (o.egRole === 'label') { o.selectable = false; o.evented = false; }
        if (o.egRole === 'head')  { o.selectable = false; o.evented = false; }
        if (o.egRole === 'conn')  { o.hasControls = false; o.hasBorders = true;
                                    if (o.type === 'line') oldLines.push(o); }
        // 底圖鎖定：還原之後要再鎖一次，不然使用者拖曳挪動了底圖，
        // 存檔會把移位後的座標一起存下去，之後每次重開都對不回原位
        if (o.egRole === 'bg')    { o.selectable = false; o.evented = false; cv.sendToBack(o); }
      });
      nextId = mx + 1;
      /* 2026-10-15 之前的工作檔，連接線是 fabric.Line（只有兩端、加不了折點）。
         一打開就就地換成 Polyline，使用者不必重畫；egId／egKind／綁定／顏色全部沿用，
         箭頭物件本來就是另一個物件靠 egId 配對的，不必動。 */
      oldLines.forEach(function (o) { migrateLineConn(o); });
      cv.requestRenderAll();
      quiet = false;
      if (cb) cb();
    });
  }
  /** 舊 fabric.Line 連接線 → Polyline（就地換掉，保留堆疊順序） */
  function migrateLineConn(o) {
    var mi = Math.min(o.x1, o.x2), mj = Math.min(o.y1, o.y2);
    var pts = [{ x: o.left + (o.x1 - mi), y: o.top + (o.y1 - mj) },
               { x: o.left + (o.x2 - mi), y: o.top + (o.y2 - mj) }];
    var np = mkConnObj(pts, { stroke: o.stroke, strokeWidth: o.strokeWidth || 1.5 });
    np.egRole = 'conn'; np.egId = o.egId; np.egKind = o.egKind || 'arrow';
    np.egPA = o.egPA || null; np.egPB = o.egPB || null; np.egCurve = false;
    var idx = cv.getObjects().indexOf(o);
    cv.remove(o);
    cv.add(np);
    if (idx >= 0) np.moveTo(idx);
    syncConn(np);
    return np;
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
  /** 圖形被刪掉時，把綁在它身上的連線解開（留在原地，不要憑空跳走） */
  function unbindShape(id) {
    cv.getObjects().forEach(function (o) {
      if (o.egRole !== 'conn') return;
      if (o.egPA && o.egPA.id === id) o.egPA = null;
      if (o.egPB && o.egPB.id === id) o.egPB = null;
    });
  }


  function delSel() {
    var objs = cv.getActiveObjects();
    if (!objs.length) { stat('請先選要刪除的東西'); return; }
    objs.forEach(function (o) {
      if (o.egRole === 'handle') return;
      if (o.egRole === 'shape') { var lb = labelOf(o); if (lb) cv.remove(lb); unbindShape(o.egId); }
      if (o.egRole === 'conn')  { headsOf(o).forEach(function (h) { cv.remove(h); }); }
      if (o.egRole === 'label') { var sh = shapeOf(o); if (sh) { unbindShape(sh.egId); cv.remove(sh); } }
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
        // 雙箭頭有兩個箭頭，改色要兩個都改（只改一個會變成一端舊色）
        if (o.egRole === 'conn') headsOf(o).forEach(function (h) { h.set({ fill: c, stroke: c }); });
        if (o.egRole === 'label' || o.egRole === 'text') o.set({ fill: c, stroke: null });
      } else if (o.egRole === 'shape') {
        o.set('fill', c);
      }
    });
    cv.requestRenderAll();
    snapshot();
  }

  /* ── 建立畫布與事件 ───────────────────────────────────────────────── */
  /* 連接線的點擊判定要放寬（使用者 2026-10-15：「無法分別拖移兩端位置」）。
     fabric.Line 的命中範圍就是它的外框，而一條垂直／水平線的外框只有
     strokeWidth（1.5px）那麼寬——實測幾乎點不中，選不到線就看不到端點控制圓，
     當然也拖不動。這裡只對流程圖的連接線（egRole='conn'）改成
     「滑鼠離線段 8px 以內就算點中」，其他 fabric.Line（輔助線、預覽線）不受影響。 */
  /** 連接線（Polyline）的命中判定：逐段算「滑鼠離線段多遠」，8px 以內算點中。
   *  fabric 預設用外框判定，而水平／垂直的線外框只有 strokeWidth(1.5px) 那麼薄，
   *  實測幾乎點不中——選不到線就看不到節點，當然也拖不動。 */
  function patchConnHitArea() {
    if (fabric.Polyline.prototype.__egHitPatched) return;
    var orig = fabric.Polyline.prototype.containsPoint;
    fabric.Polyline.prototype.containsPoint = function (point, absolute, calculate) {
      if (this.egRole === 'conn' && point && this.points && this.points.length > 1) {
        /* ⚠ fabric 傳進來的 point 是「畫面座標」（含縮放位移），節點是場景座標，
           直接比對在縮放時永遠不會命中，要先反算回場景座標。 */
        var vt = (this.canvas && this.canvas.viewportTransform) || [1, 0, 0, 1, 0, 0];
        var zx = vt[0] || 1, zy = vt[3] || 1;
        var px = (point.x - vt[4]) / zx, py = (point.y - vt[5]) / zy;
        var ps = connPts(this);
        var tol = 8 / Math.max(0.05, Math.min(zx, zy)) + (this.strokeWidth || 1) / 2;
        for (var i = 0; i < ps.length - 1; i++) {
          var ax = ps[i].x, ay = ps[i].y, bx = ps[i + 1].x, by = ps[i + 1].y;
          var dx = bx - ax, dy = by - ay, len2 = dx * dx + dy * dy;
          var t = len2 ? ((px - ax) * dx + (py - ay) * dy) / len2 : 0;
          t = Math.max(0, Math.min(1, t));
          var qx = ax + t * dx, qy = ay + t * dy;
          if ((px - qx) * (px - qx) + (py - qy) * (py - qy) <= tol * tol) return true;
        }
        return false;
      }
      return orig.call(this, point, absolute, calculate);
    };
    fabric.Polyline.prototype.__egHitPatched = true;
  }

  /** 圓滑曲線：egCurve=true 的連接線用 Catmull-Rom 曲線通過每一個節點
   *  （節點資料不變，節點編輯照常可用）。做法沿用批圖編輯器的同一套。 */
  function patchCurvedPoly() {
    if (fabric.Polyline.prototype.__egCurvePatched) return;
    var orig = fabric.Polyline.prototype.commonRender;
    fabric.Polyline.prototype.commonRender = function (ctx) {
      if (!this.egCurve || !this.points || this.points.length < 3) return orig.call(this, ctx);
      var pts = this.points, n = pts.length, ox = this.pathOffset.x, oy = this.pathOffset.y;
      if (isNaN(pts[n - 1].y)) return false;
      var P = function (i) { return pts[Math.max(0, Math.min(n - 1, i))]; };
      ctx.beginPath();
      ctx.moveTo(P(0).x - ox, P(0).y - oy);
      for (var i = 0; i < n - 1; i++) {
        var p0 = P(i - 1), p1 = P(i), p2 = P(i + 1), p3 = P(i + 2);
        ctx.bezierCurveTo(
          p1.x + (p2.x - p0.x) / 6 - ox, p1.y + (p2.y - p0.y) / 6 - oy,
          p2.x - (p3.x - p1.x) / 6 - ox, p2.y - (p3.y - p1.y) / 6 - oy,
          p2.x - ox, p2.y - oy);
      }
      return true;
    };
    fabric.Polyline.prototype.__egCurvePatched = true;
  }

  function patchLineHitArea() {
    if (fabric.Line.prototype.__egHitPatched) return;
    var orig = fabric.Line.prototype.containsPoint;
    fabric.Line.prototype.containsPoint = function (point, absolute, calculate) {
      if (this.egRole === 'conn' && point) {
        /* ⚠ fabric 傳進來的 point 是「畫面座標」（含縮放位移），
           而 left/top/x1..y2 是場景座標——直接比對在縮放時永遠不會命中。
           先用 viewportTransform 反算回場景座標再比。 */
        var vt = (this.canvas && this.canvas.viewportTransform) || [1, 0, 0, 1, 0, 0];
        var zx = vt[0] || 1, zy = vt[3] || 1;
        var px = (point.x - vt[4]) / zx, py = (point.y - vt[5]) / zy;
        var mi = Math.min(this.x1, this.x2), mj = Math.min(this.y1, this.y2);
        var x1 = this.left + (this.x1 - mi), y1 = this.top + (this.y1 - mj);
        var x2 = this.left + (this.x2 - mi), y2 = this.top + (this.y2 - mj);
        var dx = x2 - x1, dy = y2 - y1, len2 = dx * dx + dy * dy;
        var t = len2 ? ((px - x1) * dx + (py - y1) * dy) / len2 : 0;
        t = Math.max(0, Math.min(1, t));
        var qx = x1 + t * dx, qy = y1 + t * dy;
        // 容差是「畫面上的 8px」，換算回場景座標要除以縮放倍率
        var tol = 8 / Math.max(0.05, Math.min(zx, zy)) + (this.strokeWidth || 1) / 2;
        return ((px - qx) * (px - qx) + (py - qy) * (py - qy)) <= tol * tol;
      }
      return orig.call(this, point, absolute, calculate);
    };
    fabric.Line.prototype.__egHitPatched = true;
  }

  /* 圖形內文字進入編輯後，再快速點兩下／三下，fabric 會自己「選取一個字詞／整行」
     （IText 內建的雙擊選字、三擊選行）。流程圖的文字多半是「2-GM-01管理責任與經營策劃程序」
     這種中間沒有空格的一長串，所以選字詞＝整段選起來，接著打的第一個字就把原文整段取代
     （使用者 2026-10-16：「編輯圖形內文字一樣一打字原本內容就消失了」）。
     對圖形標籤改成只把游標放到點的位置、不選取；其他文字物件（獨立文字）維持原本行為。 */
  /* ⚠ 游標位置要連「隱藏的 textarea」一起設：fabric 的輸入其實是打進那個 textarea，
     再由它回寫到文字物件；只改 IText 自己的 selectionStart/End，textarea 裡仍是上一次
     的選取範圍（重新進入編輯時是整段全選），接著打的字就照 textarea 的範圍把原文取代掉。 */
  function syncTextareaCaret(lb, a, b) {
    var e2 = (b === undefined ? a : b);
    function go() {
      var t = lb && lb.hiddenTextarea;
      if (!t || !lb.isEditing) return;
      try { t.selectionStart = a; t.selectionEnd = e2; } catch (e) {}
    }
    go();
    /* fabric 在滑鼠放開、textarea 取得焦點之後會再重寫一次選取範圍（實測重新進入編輯時
       是整段全選），所以立即設一次還不夠，要等它做完再補設。 */
    setTimeout(go, 0);
    setTimeout(go, 60);
  }
  function patchLabelSelect() {
    var P = fabric.IText && fabric.IText.prototype;
    if (!P || P.__egLabelPatched) return;
    P.__egLabelPatched = true;
    ['selectWord', 'selectLine'].forEach(function (nm) {
      var orig = P[nm];
      if (typeof orig !== 'function') return;
      P[nm] = function (i) {
        if (this.egRole === 'label') {
          var n = String(this.text || '').length;
          var at = (typeof i === 'number' && i >= 0 && i <= n) ? i : n;
          this.setSelectionStart(at);
          this.setSelectionEnd(at);
          syncTextareaCaret(this, at);
          return;
        }
        return orig.apply(this, arguments);
      };
    });
  }

  function build() {
    patchLabelSelect();
    patchLineHitArea();
    patchConnHitArea();
    patchCurvedPoly();
    cv = new fabric.Canvas('egfCanvas', { backgroundColor: '#ffffff', preserveObjectStacking: true });

    cv.on('object:moving', function (e) {
      var o = e.target;
      // Alt 拖曳＝在原位留一份複本（只做一次，之後的 move 不再複製）
      if (e.e && e.e.altKey && !altDragDone && altDragFrom
          && (o.egRole === 'shape' || o.egRole === 'text' || o.egRole === 'conn')) {
        altDragDone = true;
        var keep = cv.getActiveObject();
        dupObject(o, altDragFrom.left, altDragFrom.top);
        if (keep) cv.setActiveObject(keep);
        stat('已複製一份（原本的留在原位）');
      }
      o.set({ left: snapv(o.left), top: snapv(o.top) });
      applyGuides(o);                       // 對齊輔助線（會順手吸附）
      eachSel(o, syncMoved);                // 節點控制點會自己跟著走，不必收掉
    });
    // 按下的當下記住原始位置，Alt 複製要把複本放回這裡
    cv.on('mouse:down', function (e) {
      altDragDone = false;
      var t = e.target;
      altDragFrom = (t && t.egRole !== 'handle' && t.egRole !== 'port')
                  ? { left: t.left, top: t.top } : null;
    });

    /* 刻意**沒有**「滑過圖形就冒出錨點、點兩個錨點自動連線」那一套：
       錨點密集又會攔截滑鼠，實測很容易點一下就生出一條短線、再點又多一條
       （使用者 2026-10-15：「一直改都改不好」）。改回最單純的做法——
       線就是自由拖拉；只有在**拖連線端點**時才顯示附近圖形的錨點當提示，
       靠過去會吸附並記住綁定（之後移動圖形，線會跟著走）。 */
    cv.on('mouse:up', function () { clearGuides(); clearPortDots(); });
    cv.on('object:scaling', function (e) { eachSel(e.target, syncMoved); });
    cv.on('object:modified', function (e) {
      var o = e.target;
      clearPortDots();
      clearGuides();
      eachSel(o, syncMoved);
      if (o && o.egRole === 'conn') showHandles(o);
      snapshot();
    });
    cv.on('selection:created', onSel);
    cv.on('selection:updated', onSel);
    cv.on('selection:cleared', function () { nodeEditId = null; clearHandles(); syncNodeBtn(); });

    function onSel() {
      clearHandles();
      var objs = cv.getActiveObjects();
      /* 換選別的東西就退出節點編輯——否則下一條線一選起來又是滿滿的圓點，
         又回到「拖線變成彎折」那個狀況。 */
      if (!(objs.length === 1 && objs[0].egRole === 'conn' && objs[0].egId === nodeEditId)) nodeEditId = null;
      if (objs.length === 1 && objs[0].egRole === 'conn') showHandles(objs[0]);
      syncNodeBtn();
    }

    // 雙擊圖形＝直接打字（標籤平常 evented=false，所以要自己算命中）
    cv.on('mouse:dblclick', function (e) {
      if (pend) { finishPend(true); return; }   // 畫線中：雙擊＝結束這條線
      /* 雙擊線＝進入／離開節點編輯（使用者 2026-10-15 指定的做法）。
         平常只有兩端的圓點，拖線就是整條搬動；要改中間的轉折才雙擊打開。 */
      var tg = e.target;
      if (tg && tg.egRole === 'conn') {
        var on = setNodeEdit(tg);
        stat(on ? '節點編輯：每個折點都可以拖，線段中間的空心＋＝加折點，在折點上再點一下（或 Alt＋點）＝取消那個折點。再雙擊一次關閉'
                : '已關閉節點編輯（線上只剩兩端的圓點，拖線不會再被拉成彎的）');
        return;
      }
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
      /* 已經有字的圖形：游標放在「點下去的那個位置」，不要整段選起來——
         選起來的話接著打的第一個字會把原本的字整段取代掉，使用者看到的就是
         「想加字，原有文字卻直接消失」（2026-10-15 回報）。
         本來就是空的才全選（反正沒東西，只是讓游標就定位）。 */
      var txt = String(lb.text || '');
      if (txt === '') {
        lb.selectAll();
      } else {
        var idx = txt.length;
        try {
          var got = lb.getSelectionStartFromPointer(e.e);
          if (typeof got === 'number' && got >= 0 && got <= txt.length) idx = got;
        } catch (err) {}
        lb.setSelectionStart(idx);
        lb.setSelectionEnd(idx);
        syncTextareaCaret(lb, idx);
      }
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

    /* ── 畫線（做法比照批圖編輯器的「兩點連線」工具）────────────────────
       點起點 → 點終點就連起來；中間想轉幾個彎就多點幾下（折線），
       最後**雙擊或按 Enter** 結束。畫完工具還留著，可以一條接一條畫。
       也保留原本的「由起點拖到終點」畫一段（拖曳放開就直接完成）。
       ⚠ 連續點按一定要有「正在畫的樣子」：原本按下去到放開之間畫面完全沒變化，
         使用者會以為「點了沒反應、畫不出直線」（2026-10-15 回報）。 */
    var pend = null, drag = null, pendPrev = null, pendPts = [], pendDots = [];
    function clearPendPrev() {
      if (pendPrev) { cv.remove(pendPrev); pendPrev = null; }
      pendDots.forEach(function (o) { cv.remove(o); });
      pendDots = [];
      cv.requestRenderAll();
    }
    /** 把「已確定的點＋目前滑鼠位置」畫成虛線預覽
     *  ⚠ 每次都整組重建，不要留著舊物件改座標——輔助物件一律標 egRole='guide'，
     *    而 clearGuides() 會把畫面上所有 guide 掃掉（存檔與輸出圖都不可以含它們），
     *    留著參考就會變成「物件已經不在畫布上、改了座標也不會顯示」。 */
    function drawPendPrev(cur) {
      clearPendPrev();
      var pts = pendPts.slice();
      if (cur) pts.push(cur);
      if (pts.length >= 2) {
        pendPrev = new fabric.Polyline(pts, {
          stroke: lineColor, strokeWidth: 1.5, strokeDashArray: [6, 4], fill: '',
          selectable: false, evented: false, objectCaching: false, egRole: 'guide' });
        cv.add(pendPrev);
        pendPrev.bringToFront();
      }
      // 已確定的點各放一顆小紅點，看得出來「這幾個點已經定了」
      pendPts.forEach(function (p) {
        var dot = new fabric.Circle({ left: p.x, top: p.y, radius: 4,
          originX: 'center', originY: 'center',
          fill: '#DD5138', stroke: '#fff', strokeWidth: 1.5,
          selectable: false, evented: false, objectCaching: false, egRole: 'guide' });
        cv.add(dot); dot.bringToFront();
        pendDots.push(dot);
      });
      cv.requestRenderAll();
    }
    /** 結束這一條：>=2 個點就建立連接線 */
    function finishPend(keepTool) {
      var pts = pendPts.slice();
      pendPts = []; drag = null;
      clearPendPrev();
      if (pts.length >= 2) {
        /* 雙擊結束時最後兩點常常重疊（同一個位置按了兩下）；中間也可能因為手抖
           連點兩下而留下看不出來的重複點——那正是「箭頭變出一堆點」的來源，
           所以**整條**都要把靠太近的點併掉，不是只處理尾巴。 */
        var clean = [pts[0]];
        for (var pi = 1; pi < pts.length; pi++) {
          var pa = clean[clean.length - 1];
          if (Math.abs(pts[pi].x - pa.x) < 6 && Math.abs(pts[pi].y - pa.y) < 6) continue;
          clean.push(pts[pi]);
        }
        if (clean.length < 2) { if (!keepTool) { w.__egfSetPending(null); return; } stat(pendHint(pend)); return; }
        pts = clean;
        addConnPts(pend || 'arrow', pts);
        snapshot();
      }
      if (!keepTool) { w.__egfSetPending(null); return; }
      stat(pendHint(pend) + '（上一條已完成，可以接著畫下一條；要停止請按 V 或左邊面板那顆按鈕）');
    }
    function pendHint(k) {
      return (k === 'line' ? '畫直線' : '畫箭頭')
        + '：點一下起點、再點一下終點；中間多點幾下就是折線，雙擊或按 Enter 結束（也可以直接由起點拖到終點）';
    }
    cv.on('mouse:down', function (e) {
      if (!pend) return;
      var p = cv.getPointer(e.e);
      drag = { x: snapv(p.x), y: snapv(p.y) };
    });
    cv.on('mouse:move', function (e) {
      if (!pend || !e || !e.e) return;
      var p = cv.getPointer(e.e);
      if (!pendPts.length && !drag) return;        // 還沒下第一點，不必畫預覽
      drawPendPrev({ x: snapv(p.x), y: snapv(p.y) });
    });
    cv.on('mouse:up', function (e) {
      if (!pend) return;
      var p = cv.getPointer(e.e);
      var px = snapv(p.x), py = snapv(p.y);
      var moved = drag && (Math.abs(px - drag.x) >= 6 || Math.abs(py - drag.y) >= 6);
      if (moved) {
        // 拖曳：起點→放開的位置畫一段（還沒下過點時就是完整一條，有點時就是最後一段）
        if (!pendPts.length) pendPts.push({ x: drag.x, y: drag.y });
        pendPts.push({ x: px, y: py });
        finishPend(true);
        return;
      }
      // 單純點一下＝下一個點
      pendPts.push({ x: px, y: py });
      drag = null;
      drawPendPrev(null);
      stat(pendPts.length === 1
        ? (pendHint(pend) + '　→　已下第 1 點，再點終點')
        : (pendHint(pend) + '　→　已有 ' + pendPts.length + ' 點，雙擊或按 Enter 結束'));
    });
    w.__egfFinishPend = function () { if (pend && pendPts.length >= 2) { finishPend(true); return true; } return false; };
    w.__egfCancelPend = function () {
      if (!pend) return false;
      if (pendPts.length) { pendPts = []; drag = null; clearPendPrev(); stat(pendHint(pend)); return true; }
      w.__egfSetPending(null);
      return true;
    };
    w.__egfSetPending = function (k) {
      pend = k;
      pendPts = []; drag = null;
      clearPendPrev();
      cv.defaultCursor = k ? 'crosshair' : 'default';
      /* 畫線中一律不要讓滑鼠打到既有物件：不關掉的話點在圖形上會變成「拖動那個圖形」，
         使用者以為在畫線、結果是把方塊搬走了。框選的藍框也一起關掉。 */
      cv.selection = !k;
      cv.skipTargetFind = !!k;
      stat(k ? pendHint(k) : '　');
      if (!k) clearPendUI();
    };
  }

  /* ── 顯示縮放 ──────────────────────────────────────────────────────────
     使用者要求「流程圖要可以在跳窗內放大」。
     **只放大顯示，不動畫布的實際尺寸**——所以只改 canvas 的 CSS 尺寸
     （`setDimensions(..., {cssOnly:true})`），backstore 維持原樣，
     匯出與存檔拿到的仍是原尺寸；fabric v5 的 getPointer 會自己換算 CSS 縮放，
     滑鼠點擊位置不會跑掉。 */
  var ZOOM = 1;
  /* 畫布的「邏輯尺寸」：物件座標、存檔、輸出圖都用它。
     顯示尺寸＝邏輯尺寸 × ZOOM，由 applyZoom() 設到 canvas 上。
     ⚠ 一定要跟顯示尺寸分開存：縮放之後 cv.getWidth() 回的是顯示尺寸，
       拿它去存檔會讓工作檔的畫布大小隨「上次看的縮放」變來變去。 */
  var LOGW = 760, LOGH = 560;

  function setLogicalSize(w, h) {
    LOGW = Math.max(60, Math.round(w || LOGW));
    LOGH = Math.max(60, Math.round(h || LOGH));
    applyZoom();
  }
  var ZOOM_STEPS = [0.25, 0.4, 0.5, 0.6, 0.75, 0.9, 1, 1.25, 1.5, 2, 2.5, 3];

  function applyZoom() {
    if (!cv) return;
    /* 用 Fabric 自己的縮放（viewportTransform）＋真的改 canvas 尺寸，
       內容會以新的解析度**重新畫一次**，所以放大縮小都清楚。
       原本是 setDimensions(..., {cssOnly:true})＝只改 CSS 把點陣圖拉伸，
       縮小時文字糊成一團，而且繞過了 Fabric 的高解析度處理
       （使用者 2026-10-15 回報「圖示內文字看起來很模糊」）。 */
    cv.setZoom(ZOOM);
    cv.setDimensions({ width: Math.round(LOGW * ZOOM), height: Math.round(LOGH * ZOOM) });
    var pct = Math.round(ZOOM * 100);
    var v = el('egfZoomVal');
    if (v) v.textContent = pct + '%';
    /* 滑桿跟數值要雙向同步：用＋－或「符合視窗」改過之後，滑桿還停在舊位置的話，
       下一次拖它會先跳回舊值。 */
    var r = el('egfZoomRange');
    if (r && String(r.value) !== String(pct)) r.value = Math.max(25, Math.min(400, pct));
  }
  function setZoom(z, silent) {
    ZOOM = Math.max(0.15, Math.min(4, z));
    applyZoom();
    if (!silent) stat('顯示縮放 ' + Math.round(ZOOM * 100) + '%（只是看起來變大，實際尺寸沒有變）');
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
    var k = Math.min((host.clientWidth - 16) / LOGW,
                     (host.clientHeight - 16) / LOGH);
    setZoom(k > 0 ? k : 1);
  }

  /* ── 面板：拖曳產生與一鍵範本 ─────────────────────────────────────────
     使用者要求「希望可以快速用拉的產生，不需要真的自己畫」，所以圖形改成
     從左側面板拖到畫布上；拖不習慣的人點一下也一樣放得上去。 */
  var SHAPE_SIZE = { proc:{w:130,h:50}, judge:{w:120,h:68}, term:{w:120,h:44},
                     doc:{w:134,h:48}, text:{w:150,h:22} };
  var KIND_NAME  = { proc:'處理', judge:'判斷', term:'起訖', doc:'文件', text:'文字' };
  var dragKind = null;

  /** 切換目前的工具（null＝回到選取），面板上的亮起狀態一起處理 */
  function setTool(k) {
    clearPendUI();
    if (k) {
      var b = d.querySelector('#egfWin [data-add="' + k + '"]');
      if (b) b.classList.add('on');
    }
    if (w.__egfSetPending) w.__egfSetPending(k);
  }
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
    if (LOGW < ww || LOGH < hh) setLogicalSize(Math.max(LOGW, ww), Math.max(LOGH, hh));
    el('egfCW').value = LOGW;
    el('egfCH').value = LOGH;
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
  /* ── 左下角的縮放把手（使用者 2026-10-15：跳窗左下角要能拖移放大縮小）──
     跳窗平常是 transform 置中的；一開始拖就先把它換成固定的 left/top，
     之後以「左下角」為基準調整＝往左拖變寬、往下拖變高，右上角不動。
     大小記在 localStorage，下次打開沿用（畫布開大的人不必每次重拉）。 */
  var EGF_WIN_LS = 'egf_win_size';
  var EGF_MIN_W = 720, EGF_MIN_H = 420;

  function winApplySize(wpx, hpx) {
    var win = el('egfWin');
    if (!win) return;
    var maxW = Math.max(EGF_MIN_W, window.innerWidth - 40);
    var maxH = Math.max(EGF_MIN_H, window.innerHeight - 24);
    wpx = Math.max(EGF_MIN_W, Math.min(maxW, Math.round(wpx)));
    hpx = Math.max(EGF_MIN_H, Math.min(maxH, Math.round(hpx)));
    win.style.width = wpx + 'px';
    win.style.height = hpx + 'px';
    win.style.maxWidth = 'none';
    win.style.maxHeight = 'none';
    return { w: wpx, h: hpx };
  }
  function winRestoreSize() {
    var win = el('egfWin');
    if (!win) return;
    /* 每次打開都先回到 CSS 的置中規則再套大小——上一次拖曳留下的 left/top 是
       當時那個瀏覽器視窗的座標，換個螢幕或改過視窗大小就會開在畫面外面。 */
    win.style.left = ''; win.style.top = ''; win.style.transform = '';
    try {
      var s = JSON.parse(localStorage.getItem(EGF_WIN_LS) || 'null');
      if (s && s.w && s.h) winApplySize(s.w, s.h);
      else { win.style.width = ''; win.style.height = ''; win.style.maxWidth = ''; win.style.maxHeight = ''; }
    } catch (e) {}
  }
  function winResetSize() {
    var win = el('egfWin');
    if (!win) return;
    win.style.width = ''; win.style.height = '';
    win.style.maxWidth = ''; win.style.maxHeight = '';
    win.style.left = ''; win.style.top = ''; win.style.transform = '';
    try { localStorage.removeItem(EGF_WIN_LS); } catch (e) {}
    stat('視窗大小已還原成預設');
  }
  function setupGrip() {
    var grip = el('egfGrip'), win = el('egfWin');
    if (!grip || !win || grip.__egBound) return;
    grip.__egBound = 1;
    var st = null;
    grip.addEventListener('mousedown', function (e) {
      e.preventDefault();
      var r = win.getBoundingClientRect();
      // 置中的 transform 會讓後續計算對不起來，一律先釘成絕對位置
      win.style.left = r.left + 'px';
      win.style.top = r.top + 'px';
      win.style.transform = 'none';
      st = { x: e.clientX, y: e.clientY, w: r.width, h: r.height, left: r.left };
      d.body.style.cursor = 'nwse-resize';
    });
    d.addEventListener('mousemove', function (e) {
      if (!st) return;
      var dx = e.clientX - st.x, dy = e.clientY - st.y;
      // 把手在右下角：左上角固定不動，往右往下拖就是變大
      winApplySize(st.w + dx, st.h + dy);
      if (cv) cv.requestRenderAll();
    });
    d.addEventListener('mouseup', function () {
      if (!st) return;
      st = null;
      d.body.style.cursor = '';
      var r = win.getBoundingClientRect();
      try { localStorage.setItem(EGF_WIN_LS, JSON.stringify({ w: Math.round(r.width), h: Math.round(r.height) })); } catch (e) {}
      stat('視窗大小已調整為 ' + Math.round(r.width) + '×' + Math.round(r.height) + '（下次打開會沿用；雙擊右下角把手可還原）');
    });
    grip.addEventListener('dblclick', function (e) { e.preventDefault(); winResetSize(); });
  }

  /* 滑桿不是 button，接不到 click 委派，要自己綁 input（拖曳中即時縮放）。 */
  function setupZoomRange() {
    var r = el('egfZoomRange');
    if (!r || r.__egBound) return;
    r.__egBound = 1;
    r.addEventListener('input', function () { setZoom((parseInt(r.value, 10) || 100) / 100, true); });
    r.addEventListener('change', function () {
      stat('顯示縮放 ' + Math.round(ZOOM * 100) + '%（只是看起來變大，實際尺寸沒有變）');
    });
  }

  function bind() {
    setupGrip();
    setupZoomRange();
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
          stat(snap ? '已開啟靠齊格線（每 10px）' : '已關閉靠齊格線，可以放到任何位置');
          break;
        case 'egfGuide':
          guideOn = !guideOn;
          t.classList.toggle('on', guideOn);
          if (!guideOn) clearGuides();
          stat(guideOn ? '已開啟對齊輔助線' : '已關閉對齊輔助線，拖曳不會再被吸過去');
          break;
        case 'egfResize': {
          var ww = Math.max(200, Math.min(2000, parseInt(el('egfCW').value, 10) || 760));
          var hh = Math.max(200, Math.min(3000, parseInt(el('egfCH').value, 10) || 560));
          setLogicalSize(ww, hh); cv.requestRenderAll();
          stat('畫布已改成 ' + ww + '×' + hh);
          snapshot();
          break;
        }
        case 'egfConnEdit': {
          var ce = cv && cv.getActiveObject();
          if (!ce || ce.egRole !== 'conn') { stat('請先點選一條連線，再按「節點」（也可以直接在線上雙擊）'); break; }
          var cOn = setNodeEdit(ce);
          cv.requestRenderAll();
          stat(cOn ? '節點編輯已開啟：折點可拖、空心＋＝加折點、在折點上再點一下（或 Alt＋點）＝取消那個折點'
                   : '節點編輯已關閉（線上只剩兩端的圓點）');
          break;
        }
        case 'egfConnNode':
        case 'egfConnOrtho':
        case 'egfConnStraight':
        case 'egfConnCurve': {
          var cn = cv && cv.getActiveObject();
          if (!cn || cn.egRole !== 'conn') { stat('請先點選一條連線（線選起來之後會出現可以拖的節點）'); break; }
          // 這四顆都是在改轉折，按了就順手把節點顯示出來，不然使用者看不到剛加的折點
          if (t.id !== 'egfConnStraight') nodeEditId = cn.egId;
          if (t.id === 'egfConnNode') {
            connAddNode(cn); stat('已加一個折點，拖那顆橘色圓點就能調整轉折位置（在折點上再點一下＝取消它）');
          } else if (t.id === 'egfConnOrtho') {
            if (connOrtho(cn)) stat('已改成直角折線，每個轉角都可以再拖');
            else stat('這條線本來就是水平或垂直的，不需要轉折');
          } else if (t.id === 'egfConnStraight') {
            connStraight(cn); stat('已取直（折點全部移除）');
          } else {
            if (!cn.egCurve && (cn.points || []).length < 3) { stat('至少要有一個折點才能圓滑——請先按「折點」'); break; }
            cn.egCurve = !cn.egCurve; cn.dirty = true;
            stat(cn.egCurve ? '已改成圓滑曲線（通過每一個折點）' : '已改回直線段');
          }
          syncConn(cn);
          showHandles(cn);
          cv.requestRenderAll();
          snapshot();
          break;
        }
        case 'egfAsRef': {
          if (!onPickAsRefCb) { stat('這個頁面沒有提供 AS 文件清單'); break; }
          onPickAsRefCb(asRefInsert);
          break;
        }
        case 'egfAsUpd': {
          var cur = window.__egfAsCur;
          if (!cur) break;
          var n = asRefApply(cur);
          el('egfAsBar').style.display = 'none';
          stat(n ? ('已更新 ' + n + ' 處引用文字（記得按下方「插入到文件」才會存回去）') : '沒有需要更新的地方');
          break;
        }
        case 'egfConnFlip': {
          var fl = cv.getActiveObject();
          if (fl && fl.egRole === 'conn') { flipConn(fl); snapshot(); stat('已把這條線反向'); }
          else stat('請先點選一條連線');
          break;
        }
        case 'egfTxtL': setLabelAlign('h', 'left');   break;
        case 'egfTxtC': setLabelAlign('h', 'center'); break;
        case 'egfTxtR': setLabelAlign('h', 'right');  break;
        case 'egfTxtT': setLabelAlign('v', 'top');    break;
        case 'egfTxtM': setLabelAlign('v', 'middle'); break;
        case 'egfTxtB': setLabelAlign('v', 'bottom'); break;
        case 'egfAlignL':  arrangeSelected('al'); break;
        case 'egfAlignR':  arrangeSelected('ar'); break;
        case 'egfAlignVC': arrangeSelected('vc'); break;
        case 'egfAlignHC': arrangeSelected('hc'); break;
        case 'egfDistV':   arrangeSelected('dv'); break;
        case 'egfDistH':   arrangeSelected('dh'); break;
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
    /* Ctrl＋滾輪縮放（跟一般繪圖軟體一樣）。
       刻意要按著 Ctrl：不按的話滾輪要留給畫布捲動，圖一大就只能用捲軸找東西。 */
    var ck = el('egfConnKind');
    if (ck) ck.addEventListener('change', function () {
      var o = cv && cv.getActiveObject();
      if (o && o.egRole === 'conn') { setConnKind(o, ck.value); showHandles(o); snapshot(); stat('已換成' + ck.options[ck.selectedIndex].text); }
    });

    var canhost = d.querySelector('#egfWin .egf-canhost');
    if (canhost) {
      canhost.addEventListener('wheel', function (e) {
        if (!e.ctrlKey || !cv) return;
        e.preventDefault();
        setZoom(ZOOM * (e.deltaY < 0 ? 1.1 : 1 / 1.1));
      }, { passive: false });
    }

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
      /* Enter＝結束正在畫的折線（比照批圖編輯器） */
      else if (e.key === 'Enter') {
        if (w.__egfFinishPend && w.__egfFinishPend()) { e.preventDefault(); }
      }
      /* 工具快捷鍵：V 選取、A 箭頭、L 直線（跟批圖編輯器同一組按鍵） */
      else if (!e.ctrlKey && !e.metaKey && !e.altKey && 'vVaAlL'.indexOf(e.key) >= 0 && e.key.length === 1) {
        e.preventDefault();
        var kk = e.key.toLowerCase();
        setTool(kk === 'v' ? null : (kk === 'a' ? 'arrow' : 'line'));
      }
      /* 方向鍵微調位置：一下 1px、按住 Shift 一下 10px
         （使用者 2026-10-15：「移動時會像有格點一樣無法對齊到想要對齊的地方」，
           格線吸附已預設關閉，再給鍵盤微調才真的放得到想要的位置） */
      else if (e.key.indexOf('Arrow') === 0) {
        var objs = cv ? cv.getActiveObjects().filter(function (o) {
          return o.egRole === 'shape' || o.egRole === 'text' || o.egRole === 'conn';
        }) : [];
        if (!objs.length) return;
        e.preventDefault();
        var st = e.shiftKey ? 10 : 1;
        var ddx = (e.key === 'ArrowLeft' ? -st : e.key === 'ArrowRight' ? st : 0);
        var ddy = (e.key === 'ArrowUp' ? -st : e.key === 'ArrowDown' ? st : 0);
        objs.forEach(function (o) {
          o.set({ left: o.left + ddx, top: o.top + ddy });
          o.setCoords();
          if (o.egRole === 'shape') { syncLabel(o); syncBoundConns(o); }
          if (o.egRole === 'conn') syncConn(o);
        });
        var one = cv.getActiveObject();
        if (one && one.egRole === 'conn') showHandles(one);
        cv.requestRenderAll();
        snapshot();
        stat('已移動 ' + st + 'px（按住 Shift 一次移 10px）');
      }
      /* Esc 刻意**不關閉**整個編輯器（使用者 2026-10-15）：畫到一半誤按一下
         就把視窗關掉、畫的東西全沒了。Esc 只用來「放掉目前選取的東西」，
         要關請按右上角的 × 或下方的取消。 */
      else if (e.key === 'Escape') {
        e.preventDefault();
        // 正在畫線＝先丟掉這一條（還沒畫完的點），再按一次才是回到選取工具
        if (w.__egfCancelPend && w.__egfCancelPend()) { stat('已取消這一條線（再按 Esc 回到選取工具）'); return; }
        nodeEditId = null; syncNodeBtn();
        if (cv) { cv.discardActiveObject(); clearHandles(); clearPortDots(); clearGuides(); cv.requestRenderAll(); }
        stat('已取消選取（要關閉請按右上角的 ×）');
      }
    });
  }

  function doSave() {
    if (!cv) return;
    clearHandles();
    clearPortDots();        // 連接點提示不可以被印進圖裡
    clearGuides();          // 對齊輔助線同理
    /* 輸出之前一定要把顯示縮放歸 1：toDataURL 的 left/top/width/height 會被
       viewportTransform 換算，縮放中輸出會裁到錯的範圍（圖只剩一角或整片空白）。 */
    var _z = ZOOM;
    if (_z !== 1) { ZOOM = 1; applyZoom(); }
    cv.discardActiveObject();
    cv.requestRenderAll();
    var objs = cv.getObjects().filter(function (o) {
      return o.egRole !== 'handle' && o.egRole !== 'port' && o.egRole !== 'guide';
    });
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
    var cw = Math.min(LOGW - cx, Math.ceil(maxX - minX + PAD * 2));
    var ch = Math.min(LOGH - cy, Math.ceil(maxY - minY + PAD * 2));
    if (!(cw > 0 && ch > 0)) { cx = 0; cy = 0; cw = LOGW; ch = LOGH; }
    // multiplier 2.5＝列印時不會鋸齒（列印是 300dpi 等級，螢幕 1x 印出來會糊）
    var png = cv.toDataURL({ format: 'png', multiplier: 2.5, enableRetinaScaling: false,
                             left: cx, top: cy, width: cw, height: ch });
    var json = serialize();
    if (_z !== 1) { ZOOM = _z; applyZoom(); }     // 還原使用者原本的顯示縮放
    var cb = onSaveCb;
    close();
    if (cb) cb(png, json);
  }

  function open(opt) {
    opt = opt || {};
    onSaveCb = opt.onSave || null;
    onPickAsRefCb = opt.onPickAsRef || null;
    onAsRefCheckCb = opt.onAsRefCheck || null;
    window.__egfAsCur = null;
    el('egfMask').classList.add('open');
    if (!cv) { build(); bind(); }
    setupGrip();                 // 呼叫端可能在 bind() 之前就換過 DOM，保險再綁一次（有防重複）
    winRestoreSize();            // 沿用上次拖好的視窗大小
    paintSwatches();
    nodeEditId = null;
    clearHandles();
    syncNodeBtn();
    hist = []; histAt = -1;
    stat('　');

    function afterLoad() {
      el('egfCW').value = LOGW;
      el('egfCH').value = LOGH;
      var ab = el('egfAsRef');
      if (ab) ab.style.display = onPickAsRefCb ? '' : 'none';
      try { asRefCheck(); } catch (err) {}
      /* 不沿用上一次的縮放：先回 100%，**畫布比視窗大就自動縮到整張看得完**。
         從 Word 匯入的流程圖實測 666×575，比可視區大，固定 100% 的話
         一打開只看得到左上角一塊，下半張要捲才找得到（使用者 2026-10-15 回報）。 */
      ZOOM = 1; applyZoom();
      var host = d.querySelector('#egfWin .egf-canhost');
      if (host && host.clientWidth > 40 && host.clientHeight > 40
          && (LOGW > host.clientWidth - 16 || LOGH > host.clientHeight - 16)) {
        zoomFit();
      }
      snapshot();
    }
    if (opt.json) {
      restore(opt.json, afterLoad);   // restore 內已套用工作檔存的畫布尺寸
    } else if (opt.bgImageUrl) {
      /* 拿一張既有的圖片當底圖、疊文字上去（2026-09-23 使用者要求：像批圖編輯器
         那樣有圖層可以加文字，用在匯入 Word 時轉不進來的流程圖/示意圖）。
         底圖畫好就鎖起來（selectable:false）——這裡要保護的是「不小心把底圖拖走
         或縮放」，不是真的要改圖片內容，改內容請回原檔重新插入。存檔後這張圖
         就變成一張「流程圖」資產（含 fabric 工作檔），之後可以用同一顆
         「編輯流程圖」鈕再回來調整文字位置，不必重新上傳底圖。 */
      cv.clear();
      cv.backgroundColor = '#ffffff';
      nextId = 1;
      stat('底圖載入中…');
      fabric.Image.fromURL(opt.bgImageUrl, function (img) {
        if (!img || !img.width) {
          stat('底圖載入失敗，已改成空白畫布');
          setLogicalSize(760, 560);
          cv.requestRenderAll();
          afterLoad();
          return;
        }
        // 過大的圖等比例縮進畫布可用的上限，避免視窗打開就要捲很遠才看得到全貌
        var k = Math.min(1, EGF_BG_MAX_W / img.width, EGF_BG_MAX_H / img.height);
        setLogicalSize(Math.round(img.width * k), Math.round(img.height * k));
        img.set({ left: 0, top: 0, originX: 'left', originY: 'top',
                  scaleX: k, scaleY: k, selectable: false, evented: false, hasControls: false });
        img.egRole = 'bg';
        cv.add(img);
        cv.sendToBack(img);
        cv.requestRenderAll();
        afterLoad();
      }, { crossOrigin: 'anonymous' });
    } else {
      cv.clear();
      cv.backgroundColor = '#ffffff';
      setLogicalSize(760, 560);
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
