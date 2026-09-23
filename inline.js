
'use strict';
/* Fabric 5.3.0 已知 bug 修補（本地 fabric.min.js 已修字，這裡是多層保險）：
   1) textBaseline 預設值誤植 'alphabetical'（非法值）→ 瀏覽器每幀、每個文字物件都印一條主控台警告，
      警告洪流就是「操作卡頓/當機30秒」的元兇。光改 prototype 預設值不夠——舊工作檔/舊標籤/剪貼簿
      序列化時把錯值存成了物件「自己的」屬性，載入後蓋過預設值——所以在渲染入口把每顆實例就地矯正。
   2) IText 游標動畫在物件已被移出畫布後仍可能再跑一拍 → this.canvas undefined → getRetinaScaling
      例外打斷渲染迴圈（殘影/卡頓來源之一），入口加防呆直接略過。 */
if (window.fabric && fabric.Text) {
    if (fabric.Text.prototype.textBaseline === 'alphabetical') fabric.Text.prototype.textBaseline = 'alphabetic';
    const __setTextStyles = fabric.Text.prototype._setTextStyles;
    fabric.Text.prototype._setTextStyles = function (ctx, charStyle, forMeasuring) {
        if (this.textBaseline === 'alphabetical') this.textBaseline = 'alphabetic';
        return __setTextStyles.call(this, ctx, charStyle, forMeasuring);
    };
    const __renderCursor = fabric.IText.prototype.renderCursorOrSelection;
    fabric.IText.prototype.renderCursorOrSelection = function () {
        if (!this.canvas || !this.canvas.contextTop) return;
        return __renderCursor.call(this);
    };
    /* 3) 已被移出畫布卻仍殘留為「作用中選取」的物件：每一幀畫控制點都因 this.canvas=undefined 拋例外
       （drawControls → getRetinaScaling），渲染迴圈整個死掉＝殘影/凍結。物件層防呆略過，
       畫布層順手把殘留選取清掉，讓畫面自然恢復。 */
    const __objDrawControls = fabric.Object.prototype.drawControls;
    fabric.Object.prototype.drawControls = function (ctx, styleOverride) {
        if (!this.canvas) return this;
        return __objDrawControls.call(this, ctx, styleOverride);
    };
    // 多選(activeSelection)裡的子物件畫框線走 drawBordersInGroup（不經過 drawControls），
    // 子物件被移出畫布後 this.canvas.getZoom 一樣會拋，這裡同樣略過
    const __objDrawBIG = fabric.Object.prototype.drawBordersInGroup;
    if (__objDrawBIG) {
        fabric.Object.prototype.drawBordersInGroup = function () {
            if (!this.canvas) return this;
            return __objDrawBIG.apply(this, arguments);
        };
    }
    if (fabric.Canvas.prototype.drawControls) {
        const __cvsDrawControls = fabric.Canvas.prototype.drawControls;
        fabric.Canvas.prototype.drawControls = function (ctx) {
            const ao = this._activeObject;
            // 殘留選取：本體被移出畫布，或多選裡任一子物件已被移出（undo 重建後常見）→ 清掉不畫
            if (ao && (!ao.canvas || (ao.type === 'activeSelection' && ao.getObjects && ao.getObjects().some(o => !o.canvas)))) {
                this._activeObject = null;
                return;
            }
            return __cvsDrawControls.call(this, ctx);
        };
    }
    /* 渲染保險絲：渲染途中拋例外會讓那一幀畫到一半就停——上層畫布殘留舊選取框（看得到卻刪不掉的
       幽靈外框），且之後每幀重複拋＝畫面永遠卡在殘影。把整個渲染包起來：出錯跳過該幀、清掉上層
       殘框、丟掉可疑的作用中選取讓下一幀自然恢復；錯誤限流記錄到主控台（5秒最多一則防洪流）。 */
    let __lastRenderErr = 0;
    const __renderCanvas = fabric.Canvas.prototype.renderCanvas;
    fabric.Canvas.prototype.renderCanvas = function (ctx, objects) {
        try { return __renderCanvas.call(this, ctx, objects); }
        catch (e) {
            const now = Date.now();
            if (now - __lastRenderErr > 5000) { __lastRenderErr = now; console.warn('[EGdraw] 渲染例外（已自動跳過該幀並清除殘留選取）：', e); }
            try { this.clearContext(this.contextTop); } catch (e2) { }
            this._activeObject = null;
        }
    };
}
/* ════════════════════════════════════════════════════════════════════
   批圖編輯器主程式（Fabric.js 5.3）
   物件模型：所有東西（圖片/文字/形狀/遮蓋）都是可再編輯的物件（Figma 式），
   匯出時才壓平成點陣圖（小畫家式結果）。
   ════════════════════════════════════════════════════════════════════ */
const USER_ID = null;
const USER_CNAME = null;
const OWN_COMPANY = null;
const IS_MGR = null;
const CAN_DEPT_STAMP = null;
let deptStampColorHex = null;
const CAN_DELETE_WORKFILE = null;
const WORKFILE_MAX_COUNT = null;
const USER_PREFS = null;
const RESIZE_PRESETS = null;
const RESIZE_DEFAULT_IDX = null;
const MY_DEPTS = null;
const MY_MAIN_DEPT_ID = null;
const CLIP_KEY = 'eg_imgedit_clip';           // 跨視窗剪貼簿（localStorage，同網域共用）
const DIRDB = 'eg_imgedit_fs';                // IndexedDB：預設儲存資料夾 handle
// 由圖面檢視（bom_viewer.php）等頁面帶入的料號：?part_no=料號文字，開「料號附件」存檔跳窗時
// 自動搜尋/選好這個料號、檔名也直接帶入，不用使用者自己再打一次
const PRELOAD_PART_NO = new URLSearchParams(window.location.search).get('part_no') || '';

let artW = 1600, artH = 1200;                 // 畫布（工作區）尺寸
let currentTool = 'select';
let spaceDown = false;

// fabric.js 只設 imageSmoothingEnabled，沒設 imageSmoothingQuality（瀏覽器預設='low'）。
// 存檔/列印都會把畫布以 2~4 倍放大匯出（見 exportRegionDataURL），若來源是掃描/拍照 JPG，
// 低品質內插會把原本不明顯的 JPEG 8x8 壓縮方格等比放大變成清楚可見的色塊——這一步發生在
// 每次 render（含 toDataURL 匯出用的暫存畫布），所以在 fabric 的共用函式補上 'high' 才能
// 一次涵蓋畫面顯示＋存檔＋列印全部路徑，不必每個匯出呼叫點各自設定。
(function () {
    const orig = fabric.util.setImageSmoothing;
    fabric.util.setImageSmoothing = function (ctx, value) {
        orig(ctx, value);
        try { ctx.imageSmoothingQuality = 'high'; } catch (e) {}
    };
})();

const canvas = new fabric.Canvas('c', {
    backgroundColor: null,
    preserveObjectStacking: true,
    selection: true,
    stopContextMenu: true,
    fireRightClick: true,
    fireMiddleClick: true,
    uniformScaling: true
});
// 擋掉瀏覽器中鍵自動捲動，讓「按住滾輪中鍵拖移畫面」可用
const wrapForMiddle = document.getElementById('canvas-wrap');
wrapForMiddle.addEventListener('mousedown', function (e) { if (e.button === 1) e.preventDefault(); });
wrapForMiddle.addEventListener('auxclick', function (e) { if (e.button === 1) e.preventDefault(); });
fabric.Object.prototype.transparentCorners = false;
fabric.Object.prototype.cornerColor = '#2779bd';
fabric.Object.prototype.cornerStyle = 'circle';
fabric.Object.prototype.cornerSize = 9;
fabric.Object.prototype.borderColor = '#4da3e8';

/* ── 畫布（工作區）＝一個白色底 Rect，匯出時以它的範圍裁切 ── */
let artboard = new fabric.Rect({
    left: 0, top: 0, width: artW, height: artH,
    fill: '#ffffff', selectable: false, evented: false,
    id: '__artboard', shadow: new fabric.Shadow({ color: 'rgba(0,0,0,.45)', blur: 18, offsetX: 0, offsetY: 4 })
});
canvas.add(artboard);

/* ── Figma 式「圖框」裁切（使用者要求 2026-08-24）─────────────────────────────
   白色畫布(artboard)＝Figma 的 Frame。判定與 Figma 一致：以物件「中心點」是否落在圖框內
   決定它屬不屬於這個圖框——屬於圖框的物件超出邊界的部分被切掉不顯示（畫面、匯出、列印一致），
   中心在圖框外的物件則完全不受影響照樣整個顯示。
   做法是包住 fabric.Object.prototype.render 在場景座標下 ctx.clip()，**不動物件本身的 clipPath**：
   clipPath 會被 canvas.toJSON() 序列化進 undo 快照與工作檔，換一張圖/改框架尺寸後就會留著一份
   對不上的舊裁切框，且每個物件都多存一份矩形。渲染層攔截則零序列化成本、關掉就完全恢復原狀。
   ‧群組(Group)的子物件跳過（整個群組會被當成一個物件裁切，才不會每個子件各自判定）；
     多重選取(activeSelection)的子物件仍各自判定，否則一選取就整批不裁切、畫面會跳動。
   ‧選取外框與控制點不走這條路徑，所以被裁掉的部分仍選得到、拖得回來。 */
let frameClipOn = true;
function onFrameClipToggle() {
    frameClipOn = !!document.getElementById('frame-clip').checked;
    canvas.getObjects().forEach(o => { o.dirty = true; });   // 有開物件快取的（文字/群組）不標記就不會重畫
    canvas.requestRenderAll();
    saveUserPrefsDebounced();
    toast(frameClipOn ? '已開啟圖框裁切：超出白色畫布的部分不顯示也不會印出來' : '已關閉圖框裁切：所有物件完整顯示');
}
(function installFrameClip() {
    const orig = fabric.Object.prototype.render;
    fabric.Object.prototype.render = function (ctx) {
        if (!frameClipOn || this.id === '__artboard' || !this.canvas
            || (this.group && this.group.type !== 'activeSelection')) return orig.call(this, ctx);
        const ab = artboard;
        if (!ab || !ab.canvas) return orig.call(this, ctx);
        const w = ab.width * (ab.scaleX || 1), h = ab.height * (ab.scaleY || 1);
        if (!(w > 0) || !(h > 0)) return orig.call(this, ctx);
        // 絕對中心＝變換矩陣的平移量。⚠ 不可用 getBoundingRect(true,true)：物件被多重選取
        // (activeSelection) 收編時，那支回的是「群組座標系」的框，一選取就會判定錯、畫面跟著跳動
        let cx, cy;
        try { const m = this.calcTransformMatrix(); cx = m[4]; cy = m[5]; }
        catch (e) { return orig.call(this, ctx); }
        // 中心在圖框外＝不屬於這個圖框（Figma 裡就是畫布上的獨立物件），完整顯示不裁切
        if (cx < ab.left || cx > ab.left + w || cy < ab.top || cy > ab.top + h) return orig.call(this, ctx);
        // 完全在圖框內＝不用裁，省下每個物件一次 save/clip/restore（沒被群組時 bounding rect 才是絕對座標）
        if (!this.group) {
            const br = this.getBoundingRect(true, true);
            if (br.left >= ab.left && br.top >= ab.top
                && br.left + br.width <= ab.left + w && br.top + br.height <= ab.top + h) return orig.call(this, ctx);
        }
        ctx.save();
        ctx.beginPath();
        ctx.rect(ab.left, ab.top, w, h);   // 此處 ctx 已套用 viewportTransform＝場景座標
        ctx.clip();
        orig.call(this, ctx);
        ctx.restore();
    };
})();
function findArtboard() {
    const o = canvas.getObjects().find(o => o.id === '__artboard');
    if (o) { artboard = o; artboard.selectable = false; artboard.evented = false; }
    return artboard;
}
function setArtboardSize(w, h, bg) {
    artW = Math.max(50, Math.round(w)); artH = Math.max(50, Math.round(h));
    artboard.set({ width: artW, height: artH, scaleX: 1, scaleY: 1 });
    if (bg) artboard.set('fill', bg);
    canvas.sendToBack(artboard);
    document.getElementById('st-canvas').textContent = artW + '×' + artH;
    canvas.requestRenderAll();
}

/* ── 圖面像素縮放：輸入目標寬/高，等比例縮放整張圖面（所有物件的位置與大小一起等比例調整）── */
function openResizeModal() {
    document.getElementById('rs-current').textContent = Math.round(artW) + '×' + Math.round(artH);
    document.getElementById('rs-w').value = Math.round(artW);
    document.getElementById('rs-h').value = Math.round(artH);
    renderResizePresets();
    showModal('resize-modal');
}
document.getElementById('rs-w').addEventListener('input', function () {
    const w = parseFloat(this.value) || 0;
    if (w > 0 && artW > 0) document.getElementById('rs-h').value = Math.round(w * (artH / artW));
});
document.getElementById('rs-h').addEventListener('input', function () {
    const h = parseFloat(this.value) || 0;
    if (h > 0 && artH > 0) document.getElementById('rs-w').value = Math.round(h * (artW / artH));
});
function applyResizeTo(newW, newH) {
    newW = Math.max(10, Math.round(newW)); newH = Math.max(10, Math.round(newH));
    const factor = newW / artW;
    canvas.discardActiveObject();
    canvas.getObjects().forEach(o => {
        if (o === artboard) return;
        o.set({
            left: (o.left || 0) * factor,
            top: (o.top || 0) * factor,
            scaleX: (o.scaleX || 1) * factor,
            scaleY: (o.scaleY || 1) * factor
        });
        o.dirty = true;
        o.setCoords();
    });
    setArtboardSize(newW, newH);
    canvas.requestRenderAll();
    zoomFit();
    pushState();
    toast('圖面已等比例縮放為 ' + newW + '×' + newH);
}
function applyResize() {
    const w = parseFloat(document.getElementById('rs-w').value) || 0;
    const h = parseFloat(document.getElementById('rs-h').value) || 0;
    if (w < 10 || h < 10) { toast('尺寸太小'); return; }
    if (!confirm('確定把整張圖面等比例縮放為 ' + Math.round(w) + '×' + Math.round(h) + '？（可 Ctrl+Z 復原）')) return;
    applyResizeTo(w, h);
    hideModal('resize-modal');
}
/* 常用尺寸：最多 3 組，可命名，其中一組可設為「快速縮放」的預設 */
let resizePresets = JSON.parse(JSON.stringify(RESIZE_PRESETS || []));
let resizeDefaultIdx = RESIZE_DEFAULT_IDX || 0;
function renderResizePresets() {
    const box = document.getElementById('rs-presets');
    if (!resizePresets.length) { box.innerHTML = '<span style="color:#8b949e;font-size:12px;">尚未設定常用尺寸</span>'; return; }
    box.innerHTML = resizePresets.map((p, i) =>
        '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:12.5px;">' +
        '<label style="display:flex;align-items:center;gap:3px;white-space:nowrap;"><input type="radio" name="rs-default" ' + (i === resizeDefaultIdx ? 'checked' : '') + ' onchange="setResizeDefault(' + i + ')"> 預設</label>' +
        '<span style="flex:1;">' + escHtml(p.name) + '（' + p.w + '×' + p.h + '）</span>' +
        '<button class="tb-btn" onclick="applyResizeTo(' + p.w + ',' + p.h + ')" title="套用這組尺寸"><i class="fa fa-check"></i></button>' +
        '<button class="tb-btn" style="color:#ff8a80;" onclick="deleteResizePreset(' + i + ')" title="刪除這組"><i class="fa fa-trash"></i></button>' +
        '</div>').join('');
}
function addResizePreset() {
    const name = document.getElementById('rs-preset-name').value.trim();
    const w = parseFloat(document.getElementById('rs-preset-w').value) || 0;
    const h = parseFloat(document.getElementById('rs-preset-h').value) || 0;
    if (!name) { toast('請輸入名稱'); return; }
    if (w < 10 || h < 10) { toast('請輸入寬高'); return; }
    if (resizePresets.length >= 3) { toast('最多只能設定 3 組常用尺寸，請先刪除一組再新增'); return; }
    resizePresets.push({ name, w: Math.round(w), h: Math.round(h) });
    document.getElementById('rs-preset-name').value = '';
    document.getElementById('rs-preset-w').value = '';
    document.getElementById('rs-preset-h').value = '';
    saveResizePresets();
    renderResizePresets();
}
function deleteResizePreset(i) {
    resizePresets.splice(i, 1);
    if (resizeDefaultIdx >= resizePresets.length) resizeDefaultIdx = 0;
    saveResizePresets();
    renderResizePresets();
}
function setResizeDefault(i) {
    resizeDefaultIdx = i;
    saveResizePresets();
}
async function saveResizePresets() {
    try {
        const fd = new FormData();
        fd.append('action', 'save_resize_presets');
        fd.append('presets', JSON.stringify(resizePresets));
        fd.append('default_index', resizeDefaultIdx);
        await fetch('image_editor.php', { method: 'POST', body: fd });
    } catch (e) {}
}
function quickResize() {
    if (!resizePresets.length) { toast('尚未設定常用尺寸，請先設定'); openResizeModal(); return; }
    const p = resizePresets[resizeDefaultIdx] || resizePresets[0];
    if (!confirm('確定套用「' + p.name + '」（' + p.w + '×' + p.h + '）等比例縮放整張圖面？')) return;
    applyResizeTo(p.w, p.h);
}

/* ── 縮放至框架（A4/A3，直式/橫式）：把整張圖面等比例縮小/放大＋置中，套進一個固定像素尺寸的
   標準紙張框架。不同圖面原始解析度差很多（掃描/拍照 dpi 不一），蓋章工具的「大小」是用畫布 px
   輸入，同樣 110px 在兩張解析度不同的圖上印出來實際大小會不一樣；先套用同一個框架尺寸再蓋章，
   往後同一框架下蓋章大小就會一致。固定 200dpi 換算 A4/A3 px（僅供編輯期參考解析度，跟列印畫質
   無關——doPrintPDF 匯出時一律會依實際紙張需求重新算圖，見上方 exportRegionDataURL）。
   點選按鈕才觸發，不會自動套用。 */
const FRAME_DPI = 200;
const FRAME_MM = { A4: [210, 297], A3: [297, 420] };
/* 套用框架後的建議蓋章大小＝1 英吋（印出約 25mm）。用 FRAME_DPI 推導而不是寫死 200，
   日後調整框架解析度時建議值會自己跟著走，不會變成兩處對不起來的數字。
   A4/A3 用同一個 FRAME_DPI，所以同一個 px 值在兩種紙上印出來的實體大小相同，不必分開設。 */
const STAMP_SUGGEST_PX = FRAME_DPI;
let stampSuggestLabel = '';   // 目前建議值是哪一個框架給的（空＝還沒套過框架，不顯示提示）
/* 依「目前輸入值是不是等於建議值」切換提示文字：等於＝告訴他這已經是建議大小不用改；
   不等於＝提醒建議值多少並可一鍵改回去。 */
function refreshStampSizeHint() {
    const el = document.getElementById('stamp-size-hint'), inp = document.getElementById('p-stamp-size');
    if (!el || !inp) return;
    if (!stampSuggestLabel) { el.style.display = 'none'; return; }
    const cur = parseInt(inp.value, 10) || 0;
    el.style.display = '';
    el.title = '「' + stampSuggestLabel + '」框架下，' + STAMP_SUGGEST_PX + 'px 印出來約 25mm；'
             + '同一個框架都用這個大小，各張圖面蓋出來的章才會一樣大。';
    if (cur === STAMP_SUGGEST_PX) {
        el.style.color = '#F0A24B'; el.style.cursor = 'default'; el.onclick = null;
        el.innerHTML = '✓ 已帶入建議大小（' + stampSuggestLabel + '，約 25mm），不需再調';
    } else {
        el.style.color = '#F7E0BD'; el.style.cursor = 'pointer';
        el.onclick = function () { inp.value = STAMP_SUGGEST_PX; inp.dispatchEvent(new Event('change')); refreshStampSizeHint(); };
        el.innerHTML = '已自行調整（建議 ' + STAMP_SUGGEST_PX + '，點此改回）';
    }
}
/* 套用框架的當下就把蓋章大小帶成建議值（使用者要求：不要讓人再自己去改）。
   dispatchEvent('change') 是為了讓它進個人偏好一起存起來（見 PREF_FIELDS）。 */
function applyStampSizeSuggestion(label) {
    stampSuggestLabel = label || '';
    const inp = document.getElementById('p-stamp-size');
    if (inp) { inp.value = STAMP_SUGGEST_PX; inp.dispatchEvent(new Event('change')); }
    refreshStampSizeHint();
}
function frameSizePx(code) {
    const paper = code.slice(0, 2), land = code.slice(2) === 'L';
    const [mmShort, mmLong] = FRAME_MM[paper];
    let w = Math.round(mmShort / 25.4 * FRAME_DPI), h = Math.round(mmLong / 25.4 * FRAME_DPI);
    if (land) { const t = w; w = h; h = t; }
    return { w, h };
}
function applyFrameFit(frameW, frameH, label) {
    frameW = Math.max(10, Math.round(frameW)); frameH = Math.max(10, Math.round(frameH));
    const factor = Math.min(frameW / artW, frameH / artH);
    const dx = (frameW - artW * factor) / 2, dy = (frameH - artH * factor) / 2;
    canvas.discardActiveObject();
    canvas.getObjects().forEach(o => {
        if (o === artboard) return;
        o.set({
            left: (o.left || 0) * factor + dx,
            top: (o.top || 0) * factor + dy,
            scaleX: (o.scaleX || 1) * factor,
            scaleY: (o.scaleY || 1) * factor
        });
        o.dirty = true;
        o.setCoords();
    });
    setArtboardSize(frameW, frameH);
    canvas.requestRenderAll();
    zoomFit();
    pushState();
    applyStampSizeSuggestion(label);
    toast('已縮放置中為' + (label ? '「' + label + '」' : '') + ' ' + frameW + '×' + frameH
        + '；蓋章大小已帶入建議值 ' + STAMP_SUGGEST_PX + '（約 25mm）');
}
function applyFrameFitFromSelect() {
    const code = document.getElementById('frame-size').value;
    const labels = { A4L: 'A4 橫式', A4P: 'A4 直式', A3L: 'A3 橫式', A3P: 'A3 直式' };
    const { w, h } = frameSizePx(code);
    if (!confirm('確定把整張圖面等比例縮放＋置中到「' + labels[code] + '」（' + w + '×' + h + ' px）？\n（可 Ctrl+Z 復原）')) return;
    applyFrameFit(w, h, labels[code]);
}

/* ── 視窗尺寸/縮放/平移 ── */
const wrap = document.getElementById('canvas-wrap');
function resizeViewport() {
    canvas.setDimensions({ width: wrap.clientWidth, height: wrap.clientHeight });
    canvas.requestRenderAll();
}
window.addEventListener('resize', resizeViewport);
/* 換螢幕(不同DPI)/喚醒/切回分頁後，canvas 偶爾會繪圖異常（殘影、只剩選取控制點）；
   回來時強制整張畫布連同每個物件的快取都重畫一次 */
function forceFullRepaint() {
    resizeViewport();
    canvas.getObjects().forEach(o => { o.dirty = true; if (o.getObjects) o.getObjects().forEach(c => { c.dirty = true; }); });
    canvas.requestRenderAll();
}
window.addEventListener('focus', forceFullRepaint);
document.addEventListener('visibilitychange', function () { if (!document.hidden) forceFullRepaint(); });

function setZoomLabel() {
    document.getElementById('zoom-label').textContent = Math.round(canvas.getZoom() * 100) + '%';
}
function zoomFit() {
    const m = 40;
    const z = Math.min((wrap.clientWidth - m) / artW, (wrap.clientHeight - m) / artH, 4);
    const zz = Math.max(0.02, z);
    canvas.setViewportTransform([zz, 0, 0, zz,
        (wrap.clientWidth - artW * zz) / 2 - artboard.left * zz,
        (wrap.clientHeight - artH * zz) / 2 - artboard.top * zz]);
    setZoomLabel(); canvas.requestRenderAll();
}
function zoomToSelection() {
    const obj = canvas.getActiveObject();
    if (!obj) { toast('請先選取物件'); return; }
    const b = obj.getBoundingRect(true, true); // absolute (scene) coords
    const m = 60;
    const z = Math.min((wrap.clientWidth - m) / b.width, (wrap.clientHeight - m) / b.height, 8);
    canvas.setViewportTransform([z, 0, 0, z,
        (wrap.clientWidth - b.width * z) / 2 - b.left * z,
        (wrap.clientHeight - b.height * z) / 2 - b.top * z]);
    setZoomLabel(); canvas.requestRenderAll();
}
/* 框選放大：拖出一個場景矩形，把畫面等比例縮放到剛好顯示該範圍並置中（CAD window-zoom）。 */
function zoomToRect(x, y, w, h) {
    const m = 20;
    const z = Math.max(0.02, Math.min((wrap.clientWidth - m) / w, (wrap.clientHeight - m) / h, 16));
    canvas.setViewportTransform([z, 0, 0, z,
        (wrap.clientWidth - w * z) / 2 - x * z,
        (wrap.clientHeight - h * z) / 2 - y * z]);
    setZoomLabel(); canvas.requestRenderAll();
}
/* 進入/離開「框選放大」一次性模式：期間暫停選取與命中判定，拖完自動還原成目前工具設定。 */
function startZoomRect() {
    zoomRectMode = true;
    canvas.discardActiveObject();
    canvas.selection = false;
    canvas.skipTargetFind = true;
    canvas.defaultCursor = 'zoom-in';
    canvas.setCursor('zoom-in');
    const b = document.getElementById('btn-zoomrect'); if (b) b.classList.add('active');
    canvas.requestRenderAll();
    toast('框選放大：拖出要放大的範圍（Esc 取消）');
}
function exitZoomRect() {
    if (!zoomRectMode) return;
    zoomRectMode = false;
    if (zoomRectDraw && zoomRectDraw.obj) canvas.remove(zoomRectDraw.obj);
    zoomRectDraw = null;
    const b = document.getElementById('btn-zoomrect'); if (b) b.classList.remove('active');
    setTool(currentTool);   // 還原目前工具的 selection / 命中判定 / 游標
}
canvas.on('mouse:wheel', function (opt) {
    const e = opt.e;
    let z = canvas.getZoom() * Math.pow(0.999, e.deltaY);
    z = Math.min(12, Math.max(0.02, z));
    canvas.zoomToPoint({ x: e.offsetX, y: e.offsetY }, z);
    setZoomLabel();
    e.preventDefault(); e.stopPropagation();
});

/* ── 每個繪圖工具「各記各的」線條設定（使用者要求 2026-07-31）──────────────────
   屬性列 sec-stroke（外框色/粗細/端點/線型/填色）原本是全站一組全域值，於是在
   兩點連線改的端點、線型…切回直線時還留著，反之亦然。改成：切走工具時把目前
   屬性列的值記到「上一個工具」名下，切到新工具時把該工具自己記住的值填回去。
   ‧只有「畫圖工具」列入記憶；選取(V)的屬性列是回填「選取物」的屬性，不列入，
     否則點到別人畫的粗紅線就會把工具預設值改掉。
   ‧填回去用直接設 .value（不觸發 change），所以不會誤改到畫布上的物件。
   ‧記憶內容隨個人偏好存進 system_settings（見 PREF_FIELDS / toolStyles），下次開啟沿用。 */
const TOOL_STYLE_TOOLS  = ['draw', 'line', 'connect', 'rect', 'ellipse', 'dimdist', 'dimcircle', 'dimangle'];
const TOOL_STYLE_FIELDS = [['p-stroke', 'stroke'], ['p-width', 'width'], ['p-line-ends', 'lineEnds'],
                           ['p-line-style', 'lineStyle'], ['p-fill', 'fill'], ['p-fill-on', 'fillOn', true]];
let toolStyles = {};   // { 工具代號: {stroke,width,lineEnds,lineStyle,fill,fillOn} }
function captureToolStyle(t) {
    if (!TOOL_STYLE_TOOLS.includes(t)) return;
    const s = {};
    TOOL_STYLE_FIELDS.forEach(([id, key, isCheckbox]) => {
        const el = document.getElementById(id);
        if (el) s[key] = isCheckbox ? el.checked : el.value;
    });
    toolStyles[t] = s;
}
function applyToolStyle(t) {
    const s = toolStyles[t];
    if (!s) return;   // 這個工具還沒用過＝沿用目前值（第一次使用不會有「怎麼跟剛剛不一樣」的意外）
    TOOL_STYLE_FIELDS.forEach(([id, key, isCheckbox]) => {
        const el = document.getElementById(id);
        if (!el || s[key] === undefined || s[key] === null) return;
        if (isCheckbox) el.checked = !!s[key]; else el.value = s[key];
    });
    document.getElementById('p-width-v').textContent = document.getElementById('p-width').value;
}
/* 選取(V)狀態下改屬性列＝在改「選取到的那個物件」，但那也是使用者「最後一次設定」的意思：
   常見流程是畫完一條線 → 覺得太細 → 選起來改粗 → 再畫下一條，這時下一條要接續改好的粗細，
   不能又跳回工具上次記住的舊值（使用者回報 2026-09-02）。所以把「使用者主動改的那一個欄位」
   同步寫進「選取物對應的那個工具」的記憶裡。
   ‧只記使用者真的動過的**單一欄位**，不整組覆蓋——否則只改顏色會把沒同步的粗細一起寫進去。
   ‧只有 change 事件才會走到這裡；refreshPropbar 回填是直接設 .value（不觸發 change），
     所以「單純點選別人畫的粗紅線」不會改到任何工具的預設值（維持原本的設計）。 */
function objToolKey(o) {
    if (!o) return null;
    if (o.type === 'activeSelection' && o.getObjects) {          // 多選：以第一個判斷得出來的為準
        for (const c of o.getObjects()) { const k = objToolKey(c); if (k) return k; }
        return null;
    }
    if (o.dimKind === 'distance' || o.isDimGuide) return 'dimdist';
    if (o.dimKind === 'diameter') return 'dimcircle';
    if (o.dimKind === 'angle') return 'dimangle';
    if (o.connectKind) return 'connect';                        // 兩點連線畫出來的（直線或曲線）
    if (isLineLike(o)) return 'line';
    if (o.type === 'path') return 'draw';
    if (o.type === 'rect') return 'rect';
    if (o.type === 'ellipse' || o.type === 'circle') return 'ellipse';
    return null;
}
function captureToolStyleField(key) {
    if (currentTool !== 'select') return;      // 畫圖工具本身由 captureToolStyle() 整組記
    const t = objToolKey(canvas.getActiveObject());
    if (!t || !TOOL_STYLE_TOOLS.includes(t)) return;
    const f = TOOL_STYLE_FIELDS.find(x => x[1] === key);
    if (!f) return;
    const el = document.getElementById(f[0]);
    if (!el) return;
    if (!toolStyles[t]) toolStyles[t] = {};
    toolStyles[t][key] = f[2] ? el.checked : el.value;
}
TOOL_STYLE_FIELDS.forEach(([id, key]) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => captureToolStyleField(key));
});

/* ── 工具切換 ── */
function setTool(t) {
    if (t !== 'connect') clearConnectDraft();   // 切走工具時清掉兩點連線的第一點/預覽
    if (t !== currentTool) { captureToolStyle(currentTool); applyToolStyle(t); }   // 各工具的線條設定互不覆蓋（畫筆的筆刷在下面才依填回的值重建）
    currentTool = t;
    document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('tool-' + t);
    if (btn) btn.classList.add('active');

    canvas.isDrawingMode = (t === 'draw');
    if (t === 'draw') {
        canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
        canvas.freeDrawingBrush.color = document.getElementById('p-stroke').value;
        const bw = parseInt(document.getElementById('p-width').value, 10) || 3;
        canvas.freeDrawingBrush.width = bw;
        canvas.freeDrawingBrush.strokeDashArray = dashArrayFor(document.getElementById('p-line-style').value, bw);
    }
    const isSelect = (t === 'select');
    const isCropTool = (t === 'cropmove' || t === 'cropcopy' || t === 'cropmovelasso');
    canvas.selection = isSelect;
    canvas.skipTargetFind = !isSelect && !isCropTool;   // 框選複製/搬移也允許點到既有物件（例如剛切下那塊）直接拖曳，不必先切回選取
    canvas.defaultCursor = (t === 'pan') ? 'grab' : (isSelect ? 'default' : 'crosshair');
    if (!isSelect && !isCropTool) canvas.discardActiveObject();

    // 屬性列切換
    document.getElementById('sec-stroke').classList.toggle('show', ['draw','line','connect','rect','ellipse','select','dimdist','dimcircle','dimangle'].includes(t));
    // 「端點」只有直線／畫筆工具在畫新物件時真的有作用（矩形/橢圓/標註工具沒有端點可設，選取既有物件也不會回填生效，故只依工具顯示）
    document.getElementById('wrap-line-ends').style.display = ['line', 'draw', 'connect'].includes(t) ? '' : 'none';
    document.getElementById('sec-connect').classList.toggle('show', t === 'connect');
    document.getElementById('sec-dimstyle').classList.toggle('show', t === 'dimcircle');
    document.getElementById('sec-crop').classList.toggle('show', isCropTool);
    document.getElementById('sec-text').classList.toggle('show', ['text','label'].includes(t));
    document.getElementById('sec-mask').classList.toggle('show', ['maskrect','masklasso'].includes(t));
    document.getElementById('sec-balloon').classList.toggle('show', t === 'balloon');
    document.getElementById('sec-dc').classList.toggle('show', t === 'dc');
    document.getElementById('sec-stamp').classList.toggle('show', t === 'stamp');
    if (t === 'balloon') document.getElementById('p-balloon-next').value = nextBalloonLetter();
    if (t === 'dc') document.getElementById('p-dc-num').value = nextDcNumber();
    canvas.requestRenderAll();
}

/* ── 滑鼠操作：平移 / 形狀繪製 / 遮蓋 / 框選複製 / 文字 ── */
let isPanning = false, lastPan = null;
let drawing = null;   // 進行中的形狀 {type, obj, startX, startY, points}
let zoomRectMode = false, zoomRectDraw = null;   // 框選放大：模式旗標與進行中的框

function scenePoint(opt) { return canvas.getPointer(opt.e, false); } // scene coords

canvas.on('mouse:down', function (opt) {
    const e = opt.e;
    if (currentTool === 'pan' || spaceDown || e.button === 1) {
        isPanning = true; lastPan = { x: e.clientX, y: e.clientY };
        canvas.defaultCursor = 'grabbing';
        return;
    }
    if (zoomRectMode) {   // 框選放大：起點
        const zp = scenePoint(opt);
        zoomRectDraw = { startX: zp.x, startY: zp.y, obj: null };
        return;
    }
    if (canvas.isDrawingMode) return;
    const p = scenePoint(opt);

    if (currentTool === 'text' || currentTool === 'label') {
        addText(p.x, p.y, currentTool === 'label');
        return;
    }
    if (currentTool === 'balloon') { placeBalloon(p.x, p.y); return; }   // 工具保持啟用，連續點連續編
    if (currentTool === 'dc') { placeDcMark(p.x, p.y); return; }
    if (currentTool === 'stamp') { placeStamp(p.x, p.y); return; }
    if (currentTool === 'dimangle') { startDimAngle(p.x, p.y); return; }
    if (currentTool === 'connect') { handleConnectClick(p); return; }
    if (['rect','ellipse','line','maskrect','cropcopy','cropmove','dimdist','dimcircle'].includes(currentTool)) {
        // 框選複製/搬移：點在既有物件上（例如剛切下、還沒拖到定位的那塊）就交給 Fabric 正常拖曳，不要開新框
        if ((currentTool === 'cropcopy' || currentTool === 'cropmove') && opt.target) return;
        drawing = { type: currentTool, startX: p.x, startY: p.y, obj: null };
        return;
    }
    if (currentTool === 'masklasso') {
        drawing = { type: 'masklasso', points: [{ x: p.x, y: p.y }], obj: null };
        return;
    }
    if (currentTool === 'cropmovelasso') {
        if (opt.target) return;   // 點在既有物件上（例如剛切下那塊）交給 Fabric 正常拖曳
        drawing = { type: 'cropmovelasso', points: [{ x: p.x, y: p.y }], obj: null };
        return;
    }
});

canvas.on('mouse:move', function (opt) {
    const e = opt.e;
    if (isPanning && lastPan) {
        const vpt = canvas.viewportTransform;
        vpt[4] += e.clientX - lastPan.x;
        vpt[5] += e.clientY - lastPan.y;
        lastPan = { x: e.clientX, y: e.clientY };
        canvas.setViewportTransform(vpt);
        return;
    }
    if (zoomRectMode) {   // 框選放大：拖曳中的橘色虛線框（暖色系）
        if (!zoomRectDraw) return;
        const zp = scenePoint(opt);
        const zx = Math.min(zoomRectDraw.startX, zp.x), zy = Math.min(zoomRectDraw.startY, zp.y);
        const zw = Math.abs(zp.x - zoomRectDraw.startX), zh = Math.abs(zp.y - zoomRectDraw.startY);
        if (zoomRectDraw.obj) canvas.remove(zoomRectDraw.obj);
        zoomRectDraw.obj = new fabric.Rect({
            left: zx, top: zy, width: zw, height: zh,
            fill: 'rgba(240,162,75,.12)', stroke: '#f0a24b',
            strokeWidth: 1 / canvas.getZoom(), strokeDashArray: [5, 4],
            selectable: false, evented: false, objectCaching: false
        });
        canvas.add(zoomRectDraw.obj);
        canvas.requestRenderAll();
        return;
    }
    const p = scenePoint(opt);
    document.getElementById('st-pos').textContent = Math.round(p.x) + ', ' + Math.round(p.y);
    if (currentTool === 'connect' && connectFirst) { updateConnectPreview(p); return; }
    if (!drawing) return;

    const stroke = document.getElementById('p-stroke').value;
    const sw = parseInt(document.getElementById('p-width').value, 10) || 3;
    const fillOn = document.getElementById('p-fill-on').checked;
    const fill = fillOn ? document.getElementById('p-fill').value : 'transparent';
    const maskColor = document.getElementById('p-maskcolor').value;

    const x = Math.min(drawing.startX, p.x), y = Math.min(drawing.startY, p.y);
    const w = Math.abs(p.x - drawing.startX), h = Math.abs(p.y - drawing.startY);

    if (drawing.type === 'masklasso' || drawing.type === 'cropmovelasso') {
        // 套索預覽：就地更新同一個 Polyline 的 points（每個 mousemove 都重建新物件會 O(n²)，長套索明顯掉幀）
        drawing.points.push({ x: p.x, y: p.y });
        if (!drawing.obj) {
            const style = (drawing.type === 'masklasso')
                ? { stroke: '#e53935', fill: 'rgba(229,57,53,.15)', strokeDashArray: [4, 3] }
                : { stroke: '#6fc3ff', fill: 'rgba(39,121,189,.15)', strokeDashArray: [5, 4] };
            drawing.obj = new fabric.Polyline(drawing.points, Object.assign({
                strokeWidth: 1 / canvas.getZoom(), selectable: false, evented: false, objectCaching: false
            }, style));
            canvas.add(drawing.obj);
        } else {
            drawing.obj._setPositionDimensions({});
            drawing.obj.dirty = true;
        }
        canvas.requestRenderAll();
        return;
    }

    if (drawing.obj) canvas.remove(drawing.obj);
    let o = null;
    const lineDash = dashArrayFor(document.getElementById('p-line-style').value, sw);
    if (drawing.type === 'rect') {
        o = new fabric.Rect({ left: x, top: y, width: w, height: h, stroke, strokeWidth: sw, fill, strokeUniform: true, strokeDashArray: lineDash });
    } else if (drawing.type === 'ellipse') {
        o = new fabric.Ellipse({ left: x, top: y, rx: w / 2, ry: h / 2, stroke, strokeWidth: sw, fill, strokeUniform: true, strokeDashArray: lineDash });
    } else if (drawing.type === 'line' || drawing.type === 'dimdist' || drawing.type === 'dimcircle') {
        o = new fabric.Line([drawing.startX, drawing.startY, p.x, p.y], { stroke, strokeWidth: sw, strokeUniform: true, strokeDashArray: lineDash });
    } else if (drawing.type === 'maskrect') {
        o = new fabric.Rect({ left: x, top: y, width: w, height: h, fill: maskColor, stroke: null });
    } else if (drawing.type === 'cropcopy' || drawing.type === 'cropmove') {
        o = new fabric.Rect({ left: x, top: y, width: w, height: h, fill: 'rgba(39,121,189,.15)', stroke: '#6fc3ff', strokeWidth: 1 / canvas.getZoom(), strokeDashArray: [5, 4] });
    }
    if (o) {
        o.set({ selectable: false, evented: false, objectCaching: false });
        drawing.obj = o;
        canvas.add(o); canvas.requestRenderAll();
    }
});

canvas.on('mouse:up', function (opt) {
    if (isPanning) { isPanning = false; lastPan = null; canvas.defaultCursor = (currentTool === 'pan') ? 'grab' : 'default'; return; }
    if (zoomRectMode) {   // 框選放大：放開＝縮放到該範圍（一次性，用完即還原工具）
        const d = zoomRectDraw; zoomRectDraw = null;
        if (d && d.obj) canvas.remove(d.obj);
        const zp = scenePoint(opt);
        const zw = d ? Math.abs(zp.x - d.startX) : 0, zh = d ? Math.abs(zp.y - d.startY) : 0;
        exitZoomRect();
        if (d && zw >= 5 && zh >= 5) zoomToRect(Math.min(d.startX, zp.x), Math.min(d.startY, zp.y), zw, zh);
        else canvas.requestRenderAll();   // 太小＝誤點，僅取消
        return;
    }
    if (!drawing) return;
    const d = drawing; drawing = null;
    const p = scenePoint(opt);

    if (d.type === 'masklasso') {
        if (d.obj) canvas.remove(d.obj);
        if (d.points.length > 2) {
            const poly = new fabric.Polygon(d.points, {
                fill: document.getElementById('p-maskcolor').value, stroke: null, objectCaching: false
            });
            canvas.add(poly); finishNewObject(poly);
        }
        return;
    }
    if (d.type === 'cropmovelasso') {
        if (d.obj) canvas.remove(d.obj);
        if (d.points.length > 2) doCropMoveLasso(d.points);
        else canvas.requestRenderAll();
        return;   // 停留在此工具，可連續框選（同框選搬移）
    }
    if (!d.obj) return;
    canvas.remove(d.obj);
    const w = Math.abs(p.x - d.startX), h = Math.abs(p.y - d.startY);
    if (w < 3 && h < 3) { canvas.requestRenderAll(); return; } // 誤點不建物件

    if (d.type === 'cropcopy') { doCropCopy(Math.min(d.startX, p.x), Math.min(d.startY, p.y), w, h); return; }
    if (d.type === 'cropmove') { doCropMove(Math.min(d.startX, p.x), Math.min(d.startY, p.y), w, h); return; }

    const color = document.getElementById('p-stroke').value;
    const width = parseInt(document.getElementById('p-width').value, 10) || 3;
    const dash = dashArrayFor(document.getElementById('p-line-style').value, width);

    if (d.type === 'dimdist' || d.type === 'dimcircle') {
        const isDia = (d.type === 'dimcircle');
        const extendOut = isDia && document.getElementById('p-dim-style').value === 'extend';
        const shape = makeDimDistanceShape(d.startX, d.startY, p.x, p.y, color, width, dash, !isDia, isDia ? '⌀' : '', extendOut);
        shape.dimKind = isDia ? 'diameter' : 'distance';
        canvas.add(shape);
        finishNewObject(shape);
        const txtChild = shape.getObjects().find(o => o.type === 'i-text');
        if (txtChild) startGroupTextEdit(shape, txtChild, true);   // 畫完立刻進入輸入，游標停在最後（⌀後面）
        return;
    }

    let o = null;
    const ends = document.getElementById('p-line-ends').value;
    if (d.type === 'line' && ends !== 'none') {
        o = makeArrow(d.startX, d.startY, p.x, p.y, color, width, ends, dash);
    } else {
        o = d.obj; // 直接把預覽物件轉正式
        o.set({ objectCaching: true });
    }
    o.set({ selectable: true, evented: true });
    canvas.add(o);
    if (o === d.obj) { /* 已在畫布上，避免重複加 */ canvas.remove(o); canvas.add(o); }
    finishNewObject(o);
});

/* 連續工具：畫完不切回選取，可一直畫（同球標邏輯）；Esc 或 V 回選取工具 */
const CONTINUOUS_TOOLS = ['maskrect', 'masklasso', 'rect', 'ellipse', 'line', 'dimdist', 'dimcircle'];
function finishNewObject(o) {
    if (CONTINUOUS_TOOLS.includes(currentTool)) {
        canvas.requestRenderAll();
        pushState();
        return;
    }
    canvas.setActiveObject(o);
    setTool('select');
    canvas.requestRenderAll();
    pushState();
}

/* ── 兩點連線：點第一點→點第二點，自動用直線或曲線相連 ──
   直線＝跟直線工具同款（含端點箭頭選項），之後雙擊可編輯端點；
   曲線＝三節點圓滑折線（curved=Catmull-Rom），先天帶一點弧度並自動進入
   「編輯端點」模式，拖中間圓點即調曲度（頭尾圓點＝改連接位置）。 */
let connectFirst = null, connectMarker = null, connectPreview = null;
function clearConnectDraft() {
    if (connectMarker) { canvas.remove(connectMarker); connectMarker = null; }
    if (connectPreview) { canvas.remove(connectPreview); connectPreview = null; }
    connectFirst = null;
}
function updateConnectPreview(p) {
    if (connectPreview) canvas.remove(connectPreview);
    connectPreview = new fabric.Line([connectFirst.x, connectFirst.y, p.x, p.y], {
        stroke: document.getElementById('p-stroke').value,
        strokeWidth: Math.max(1, 1 / canvas.getZoom()), strokeDashArray: [5, 4],
        selectable: false, evented: false, objectCaching: false
    });
    canvas.add(connectPreview);
    canvas.requestRenderAll();
}
/* 兩點間圓弧取樣：bulge＝弧高/弦長，n 段（回傳 n+1 個落在同一圓上的點） */
function connectArcPoints(a, b, bulge, n) {
    const dx = b.x - a.x, dy = b.y - a.y;
    const d = Math.hypot(dx, dy);
    const h = d * bulge;
    const ux = -dy / d, uy = dx / d;                       // 垂直單位向量（凸向側）
    const R = h / 2 + d * d / (8 * h);
    const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
    const cx = mx + ux * (h - R), cy = my + uy * (h - R);  // 圓心
    const sx = mx + ux * h, sy = my + uy * h;              // 弧頂
    const a0 = Math.atan2(a.y - cy, a.x - cx);
    let sweep = Math.atan2(b.y - cy, b.x - cx) - a0;
    sweep = ((sweep % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
    // 兩個掃掠方向，取「會經過弧頂」的那一個
    const pm = { x: cx + R * Math.cos(a0 + sweep / 2), y: cy + R * Math.sin(a0 + sweep / 2) };
    if (Math.hypot(pm.x - sx, pm.y - sy) > R * 0.2) sweep -= 2 * Math.PI;
    const pts = [];
    for (let i = 0; i <= n; i++) {
        const t = a0 + sweep * i / n;
        pts.push({ x: cx + R * Math.cos(t), y: cy + R * Math.sin(t) });
    }
    return pts;
}
function handleConnectClick(p) {
    if (!connectFirst) {   // 第一點：放個小紅點提示
        connectFirst = { x: p.x, y: p.y };
        connectMarker = new fabric.Circle({
            left: p.x, top: p.y, radius: 4 / canvas.getZoom(), fill: '#e53935',
            originX: 'center', originY: 'center', selectable: false, evented: false, objectCaching: false
        });
        canvas.add(connectMarker);
        canvas.requestRenderAll();
        return;
    }
    const a = connectFirst, b = { x: p.x, y: p.y };
    if (Math.hypot(b.x - a.x, b.y - a.y) < 3) { toast('兩點太近，請點遠一點的位置'); return; }
    clearConnectDraft();
    const stroke = document.getElementById('p-stroke').value;
    const sw = parseInt(document.getElementById('p-width').value, 10) || 3;
    const dash = dashArrayFor(document.getElementById('p-line-style').value, sw);
    const kind = document.getElementById('p-connect-kind').value;
    if (kind === 'curve') {
        // 沿真正的圓弧取 5 個節點（弧高＝弦長 50%≈半圓），圓滑曲線通過圓上的點＝視覺圓潤；
        // 弧凸向取決於兩點點擊順序；工具保持啟用可連續連線，之後雙擊曲線＝編輯端點拖節點調曲度
        const poly = new fabric.Polyline(connectArcPoints(a, b, 0.5, 4), {
            stroke, strokeWidth: sw, fill: 'transparent', strokeUniform: true,
            strokeDashArray: dash, strokeLineCap: 'round', strokeLineJoin: 'round', objectCaching: false
        });
        poly.curved = true;
        poly.connectKind = 'curve';   // 出身標記：選起來改屬性時要記回「兩點連線」而不是「直線」工具
        canvas.add(poly);
        canvas.requestRenderAll();
        pushState();
        return;
    }
    const ends = document.getElementById('p-line-ends').value;
    const o = (ends !== 'none')
        ? makeArrow(a.x, a.y, b.x, b.y, stroke, sw, ends, dash)
        : new fabric.Line([a.x, a.y, b.x, b.y], { stroke, strokeWidth: sw, strokeUniform: true, strokeDashArray: dash });
    o.connectKind = 'line';
    canvas.add(o);
    canvas.requestRenderAll();
    pushState();   // 直線：工具保持啟用，可連續點下一組兩點（Esc 或 V 回選取）
}

/* 線型（實線/虛線/中心線）：dashArrayFor 依粗細等比縮放，styleFromDashArray 是反查（供屬性列同步顯示） */
function dashArrayFor(style, sw) {
    if (style === 'dashed') return [Math.max(6, sw * 3), Math.max(4, sw * 2)];
    if (style === 'dashdot') return [Math.max(8, sw * 4), Math.max(3, sw * 1.5), Math.max(2, sw * 0.8), Math.max(3, sw * 1.5)];
    return null;
}
function styleFromDashArray(arr) {
    if (!arr || !arr.length) return 'solid';
    return arr.length >= 4 ? 'dashdot' : 'dashed';
}

/* 直線/箭頭的「角度」以水平線為 0 度基準（畫出來當下的斜線本身 x1..y2 方向 + 目前旋轉量），而非物件自身方向 */
function normDeg(a) { return ((a + 180) % 360 + 360) % 360 - 180; }
function isLineLike(o) { return !!o && (o.type === 'line' || (o.type === 'group' && o.isArrowGroup)); }
function trueLineAngle(o) {
    const line = (o.type === 'line') ? o : o.getObjects().find(c => c.type === 'line');
    if (!line) return o.angle || 0;
    const dx = line.x2 - line.x1, dy = line.y2 - line.y1;
    const base = Math.atan2(dy, dx) * 180 / Math.PI;
    return normDeg(base + (o.angle || 0));
}

/* 箭頭 = 線 + 三角形頭端組成群組；ends: none / end(單) / both(雙) */
function arrowHeadLen(width) { return Math.max(18, width * 5); }   // 箭頭大小公式；粗細調整時要用同一套（見 p-width 監聽）
/* 箭頭三角形要「尖端剛好在線段端點」：Triangle 以中心定位，所以中心要沿線方向往內縮 headLen/2 */
function arrowHeadTri(px, py, dirDeg, headLen, color) {
    const rad = dirDeg * Math.PI / 180;
    return new fabric.Triangle({
        left: px - Math.cos(rad) * headLen / 2, top: py - Math.sin(rad) * headLen / 2,
        originX: 'center', originY: 'center',
        width: headLen, height: headLen, angle: dirDeg + 90, fill: color
    });
}
function makeArrow(x1, y1, x2, y2, color, width, ends, dash) {
    const angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    const headLen = arrowHeadLen(width);
    const rad = angle * Math.PI / 180, ux = Math.cos(rad), uy = Math.sin(rad);
    const hasEnd = (ends === 'end' || ends === 'both');
    // 線只畫到「箭頭底部」不畫到尖端：粗線畫到尖端會從細細的箭頭尖旁邊露出來
    const len = Math.hypot(x2 - x1, y2 - y1);
    const s = (len > headLen * (ends === 'both' ? 2 : 1) + 4) ? headLen : 0;
    const lx1 = (ends === 'both') ? x1 + ux * s : x1, ly1 = (ends === 'both') ? y1 + uy * s : y1;
    const lx2 = hasEnd ? x2 - ux * s : x2, ly2 = hasEnd ? y2 - uy * s : y2;
    const items = [new fabric.Line([lx1, ly1, lx2, ly2], { stroke: color, strokeWidth: width, strokeUniform: true, strokeDashArray: dash || null })];
    if (hasEnd) items.push(arrowHeadTri(x2, y2, angle, headLen, color));
    if (ends === 'both') items.push(arrowHeadTri(x1, y1, angle + 180, headLen, color));
    const g = new fabric.Group(items, {});
    g.isArrowGroup = true;
    g.merged = true;   // 箭頭視為單一物件，雙擊不拆
    return g;
}
/* 直線端點的絕對座標（含物件自身/所屬群組的位移旋轉縮放）
   ⚠ 必須扣掉 fabric 自己的偏差：fabric.Line 的端點是用 width 算的（calcLinePoints），
   但物件中心是用 width+strokeWidth 算的（_getTransformedDimensions），於是回傳的端點
   一律往右下多出 strokeWidth/2。這個偏差平常看不出來，可是「讀端點→寫回端點」的地方
   （拖端點、重建箭頭）每呼叫一次就累加一次，而拖曳中每個 mousemove 都會呼叫——
   結果就是「拉一端，另一端自己跑掉」，粗線更明顯。修在這裡，所有呼叫端一次到位。 */
function lineAbsEndpoints(line) {
    const m = line.calcTransformMatrix();
    const lp = line.calcLinePoints();   // 相對線中心的區域座標
    const pts = [fabric.util.transformPoint({ x: lp.x1, y: lp.y1 }, m),
                 fabric.util.transformPoint({ x: lp.x2, y: lp.y2 }, m)];
    const sw = line.strokeWidth || 0;
    if (sw) {
        // 偏差量在「線自己的父座標系」，會跟著自身角度轉，再套上所屬群組矩陣的旋轉/縮放（不含平移）
        const bx = (line.strokeUniform ? sw : sw * Math.abs(line.scaleX || 1)) / 2;
        const by = (line.strokeUniform ? sw : sw * Math.abs(line.scaleY || 1)) / 2;
        const r = fabric.util.degreesToRadians(line.angle || 0);
        let dx = bx * Math.cos(r) - by * Math.sin(r), dy = bx * Math.sin(r) + by * Math.cos(r);
        if (line.group) {
            const g = line.group.calcTransformMatrix();
            const nx = g[0] * dx + g[2] * dy, ny = g[1] * dx + g[3] * dy;
            dx = nx; dy = ny;
        }
        pts[0].x -= dx; pts[0].y -= dy;
        pts[1].x -= dx; pts[1].y -= dy;
    }
    return pts;
}
/* 箭頭群組的「真實」頭尾＝箭頭尖端（群組裡的線段有被縮短，不能拿線段端點當頭尾）。
   三角形尖端＝其區域座標 (0, -高/2) 經自身+群組矩陣轉換。makeArrow 的順序固定：先 end(x2) 再 start(x1)。 */
function trueArrowEndpoints(obj) {
    if (obj.type === 'line') return lineAbsEndpoints(obj);
    const line = obj.getObjects().find(c => c.type === 'line');
    const tris = obj.getObjects().filter(c => c.type === 'triangle');
    const pts = lineAbsEndpoints(line);
    const apex = t => fabric.util.transformPoint({ x: 0, y: -t.height / 2 }, t.calcTransformMatrix());
    if (tris.length >= 2) return [apex(tris[1]), apex(tris[0])];
    if (tris.length === 1) return [pts[0], apex(tris[0])];   // 單箭頭：箭頭固定在第二端
    return pts;
}
/* 就地「改頭尾座標」重畫箭頭群組：不換物件（拖曳中 transform.target 必須一直是同一個），
   只把內部的線段＋三角形換成新座標算出來的那一組，並把群組本身的外框/縮放/角度歸位。
   有了這支，箭頭（端點≠無）選取時就跟一般直線一樣只出現頭尾兩個圓點可各自拉，
   不再是整個外框等比例縮放（使用者要求 2026-08-24：要像 Figma 真的是一條線可以拉伸）。 */
function reshapeArrowGroup(g, a, b) {
    const line = g.getObjects().find(c => c.type === 'line');
    const tris = g.getObjects().filter(c => c.type === 'triangle');
    if (!line) return false;
    if (Math.hypot(b.x - a.x, b.y - a.y) < 1) return false;   // 兩端重疊＝方向算不出來（atan2 會亂跳）
    const ends = (tris.length >= 2) ? 'both' : (tris.length === 1 ? 'end' : 'none');
    const tmp = makeArrow(a.x, a.y, b.x, b.y, line.stroke, line.strokeWidth || 3, ends, line.strokeDashArray || null);
    g._objects.forEach(c => { c.group = null; });
    g._objects = tmp._objects;
    g._objects.forEach(c => { c.group = g; });
    g.set({ left: tmp.left, top: tmp.top, width: tmp.width, height: tmp.height,
            scaleX: 1, scaleY: 1, angle: 0, flipX: false, flipY: false });
    g.dirty = true;
    g.setCoords();
    return true;
}
/* 就地重建箭頭群組（改粗細/端點模式時用整支重畫，避免只放大三角形造成尖端跑位、跟線沒對齊） */
function rebuildArrowGroup(g, opts) {
    opts = opts || {};
    const line = g.getObjects().find(c => c.type === 'line');
    const tris = g.getObjects().filter(c => c.type === 'triangle');
    if (!line) return g;
    const pts = trueArrowEndpoints(g);
    const no = makeArrow(pts[0].x, pts[0].y, pts[1].x, pts[1].y,
        opts.color != null ? opts.color : line.stroke,
        opts.width != null ? opts.width : (line.strokeWidth || 3),
        opts.ends != null ? opts.ends : (tris.length >= 2 ? 'both' : 'end'),
        opts.dash !== undefined ? opts.dash : (line.strokeDashArray || null));
    const idx = canvas.getObjects().indexOf(g);
    canvas.remove(g);
    canvas.add(no);
    if (idx >= 0) no.moveTo(idx);
    return no;
}
/* Excel 式端點拖曳：直線只顯示頭尾兩個圓形控制點，拖曳＝直接改該端點座標（改方向/長度）。
   矩形/橢圓維持原生四角控制點拖曳調整大小。actionHandler 把絕對座標寫回 x1..y2 並歸零
   angle/scale（fabric.Line 設定座標時會自動重算 left/top/width/height）。 */
(function installLineEndpointControls() {
    function mkControl(idx) {
        return new fabric.Control({
            positionHandler: function (dim, finalMatrix, obj) {
                const lp = obj.calcLinePoints();
                const pt = (idx === 1) ? { x: lp.x1, y: lp.y1 } : { x: lp.x2, y: lp.y2 };
                return fabric.util.transformPoint(pt,
                    fabric.util.multiplyTransformMatrices(obj.canvas.viewportTransform, obj.calcTransformMatrix()));
            },
            actionHandler: function (eventData, transform, x, y) {
                const line = transform.target;
                const pts = lineAbsEndpoints(line);
                const p1 = (idx === 1) ? { x, y } : pts[0];
                const p2 = (idx === 2) ? { x, y } : pts[1];
                line.set({ angle: 0, scaleX: 1, scaleY: 1, flipX: false, flipY: false });
                line.set({ x1: p1.x, y1: p1.y, x2: p2.x, y2: p2.y });
                line.setCoords();
                return true;
            },
            cursorStyle: 'crosshair',
            actionName: 'modifyLineEnd',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#2779bd'; ctx.lineWidth = 1.5;
                ctx.beginPath(); ctx.arc(left, top, 6, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.restore();
            }
        });
    }
    fabric.Line.prototype.controls = { p1: mkControl(1), p2: mkControl(2) };
})();
/* 圓滑曲線渲染：curved=true 的折線/多邊形，用 Catmull-Rom 曲線通過所有節點取代直線段。
   覆寫 Polyline.commonRender（Polygon 也共用），節點資料不變，編輯端點照常可用。 */
(function installCurvedPolyRender() {
    const orig = fabric.Polyline.prototype.commonRender;
    fabric.Polyline.prototype.commonRender = function (ctx) {
        // 圓角（Figma 式）：cornerRadius>0 時把每個轉角改成圓弧（節點資料不變，編輯端點照常可用）。
        // 與「圓滑(curved)」互斥：圓滑已是通過節點的曲線，不再另外倒圓角。
        if (this.cornerRadius > 0 && !this.curved && this.points && this.points.length >= 3
            && !isNaN(this.points[this.points.length - 1].y)) {
            return renderRoundedPoly(this, ctx);
        }
        if (!this.curved || !this.points || this.points.length < 3) return orig.call(this, ctx);
        const pts = this.points, n = pts.length, ox = this.pathOffset.x, oy = this.pathOffset.y;
        if (!n || isNaN(pts[n - 1].y)) return false;
        const closed = (this.type === 'polygon');
        const P = i => closed ? pts[((i % n) + n) % n] : pts[Math.max(0, Math.min(n - 1, i))];
        ctx.beginPath();
        ctx.moveTo(P(0).x - ox, P(0).y - oy);
        const segs = closed ? n : n - 1;
        for (let i = 0; i < segs; i++) {
            const p0 = P(i - 1), p1 = P(i), p2 = P(i + 1), p3 = P(i + 2);
            ctx.bezierCurveTo(
                p1.x + (p2.x - p0.x) / 6 - ox, p1.y + (p2.y - p0.y) / 6 - oy,
                p2.x - (p3.x - p1.x) / 6 - ox, p2.y - (p3.y - p1.y) / 6 - oy,
                p2.x - ox, p2.y - oy);
        }
        return true;
    };
})();
/* 倒圓角描繪：把折線/多邊形的每個轉角切成半徑 r 的圓弧（用二次貝茲曲線，控制點＝原本的尖角）。
   r 會被夾在相鄰兩邊長度一半以內，避免相鄰圓角互相吃掉。開放折線的頭尾端點維持尖角不倒。 */
function renderRoundedPoly(poly, ctx) {
    const pts = poly.points, n = pts.length, ox = poly.pathOffset.x, oy = poly.pathOffset.y;
    const closed = (poly.type === 'polygon');
    const R = poly.cornerRadius || 0;
    const A = new Array(n), B = new Array(n), sharp = new Array(n);
    for (let i = 0; i < n; i++) {
        const cur = pts[i];
        // 開放折線的頭尾沒有可倒圓的完整轉角，維持尖角
        if (!closed && (i === 0 || i === n - 1)) { sharp[i] = true; A[i] = B[i] = cur; continue; }
        const prev = pts[(i - 1 + n) % n], next = pts[(i + 1) % n];
        const d1x = prev.x - cur.x, d1y = prev.y - cur.y, l1 = Math.hypot(d1x, d1y);
        const d2x = next.x - cur.x, d2y = next.y - cur.y, l2 = Math.hypot(d2x, d2y);
        const r = Math.min(R, l1 / 2, l2 / 2);
        if (!(r > 0) || l1 === 0 || l2 === 0) { sharp[i] = true; A[i] = B[i] = cur; continue; }
        A[i] = { x: cur.x + d1x / l1 * r, y: cur.y + d1y / l1 * r };   // 靠前一段邊的切點
        B[i] = { x: cur.x + d2x / l2 * r, y: cur.y + d2y / l2 * r };   // 靠後一段邊的切點
        sharp[i] = false;
    }
    ctx.beginPath();
    const start = closed ? A[0] : pts[0];
    ctx.moveTo(start.x - ox, start.y - oy);
    const last = closed ? n : n - 1;   // 封閉：走完 n 個角回到起點；開放：走到最後一點
    for (let k = 0; k <= last; k++) {
        const i = k % n;
        if (k === 0) {   // 起點已 moveTo（封閉＝A[0]，開放＝pts[0]），先倒第 0 角
            if (closed && !sharp[0]) ctx.quadraticCurveTo(pts[0].x - ox, pts[0].y - oy, B[0].x - ox, B[0].y - oy);
            continue;
        }
        if (closed && k === n) {   // 收尾：連回起點角 A[0]
            ctx.lineTo(A[0].x - ox, A[0].y - oy);
            break;
        }
        if (sharp[i]) {
            ctx.lineTo(pts[i].x - ox, pts[i].y - oy);
        } else {
            ctx.lineTo(A[i].x - ox, A[i].y - oy);
            ctx.quadraticCurveTo(pts[i].x - ox, pts[i].y - oy, B[i].x - ox, B[i].y - oy);
        }
    }
    if (closed) ctx.closePath();
    return true;
}

/* ── Excel「編輯端點」：直線/折線/矩形/不規則遮蓋 進入節點編輯模式 ──────────
   拖曳實心圓點＝移動該節點；點各線段中間的「＋」＝在該處插入新節點（直線第一次編輯會先轉成折線）。
   節點座標數學沿用 fabric 官方 custom-controls-polygon 範例（pathOffset / _setPositionDimensions / 錨定點）。 */
function polyEditSizeWithStroke(o) {
    const sx = o.strokeUniform ? 1 / o.scaleX : 1, sy = o.strokeUniform ? 1 / o.scaleY : 1;
    return { x: o.width + sx * (o.strokeWidth || 0), y: o.height + sy * (o.strokeWidth || 0) };
}
function polyPointPositionHandler(i) {
    return function (dim, finalMatrix, poly) {
        const p = poly.points[i];
        // 節點數變動後的殘留控制點、或物件已被移出畫布（undo 重建後的殘留選取）：移到畫面外，不讓它把渲染搞掛
        if (!p || !poly.canvas) return new fabric.Point(-99999, -99999);
        const pt = { x: p.x - poly.pathOffset.x, y: p.y - poly.pathOffset.y };
        return fabric.util.transformPoint(pt,
            fabric.util.multiplyTransformMatrices(poly.canvas.viewportTransform, poly.calcTransformMatrix()));
    };
}
function polyMidPositionHandler(i) {
    return function (dim, finalMatrix, poly) {
        const a = poly.points[i], b = poly.points[(i + 1) % poly.points.length];
        if (!a || !b || !poly.canvas) return new fabric.Point(-99999, -99999);
        const pt = { x: (a.x + b.x) / 2 - poly.pathOffset.x, y: (a.y + b.y) / 2 - poly.pathOffset.y };
        return fabric.util.transformPoint(pt,
            fabric.util.multiplyTransformMatrices(poly.canvas.viewportTransform, poly.calcTransformMatrix()));
    };
}
function polyPointActionHandler(i) {
    return function (eventData, transform, x, y) {
        const poly = transform.target;
        const local = poly.toLocalPoint(new fabric.Point(x, y), 'center', 'center');
        const base = polyEditSizeWithStroke(poly);
        const size = poly._getTransformedDimensions(0, 0);
        // 節點全部垂直/水平共線時寬或高=0，除以0會把座標變成Infinity「毒化」整個物件
        // （看不見、選得到、每幀渲染出錯畫面不清除→拖曳殘影），一律防呆
        const sx = size.x || 1, sy = size.y || 1;
        const nx = local.x * base.x / sx + poly.pathOffset.x;
        const ny = local.y * base.y / sy + poly.pathOffset.y;
        if (!isFinite(nx) || !isFinite(ny)) return false;
        poly.points[i] = { x: nx, y: ny };
        return true;
    };
}
/* 改動節點後物件的寬高/中心會變，用「另一個節點」當錨點把物件釘在原地（官方範例作法） */
function polyAnchorWrapper(anchorIndex, fn) {
    return function (eventData, transform, x, y) {
        const poly = transform.target;
        const anchor = poly.points[anchorIndex];
        if (!anchor) return false;
        const anchorAbs = fabric.util.transformPoint(
            { x: anchor.x - poly.pathOffset.x, y: anchor.y - poly.pathOffset.y },
            poly.calcTransformMatrix());
        const done = fn(eventData, transform, x, y);
        poly._setPositionDimensions({});
        const base = polyEditSizeWithStroke(poly);
        const nx = (poly.points[anchorIndex].x - poly.pathOffset.x) / (base.x || 1);
        const ny = (poly.points[anchorIndex].y - poly.pathOffset.y) / (base.y || 1);
        if (isFinite(nx) && isFinite(ny) && isFinite(anchorAbs.x) && isFinite(anchorAbs.y)) {
            poly.setPositionByOrigin(anchorAbs, nx + 0.5, ny + 0.5);
        }
        return done;
    };
}
function polyInsertPointHandler(i) {
    return function (eventData, transform, x, y) {
        const poly = transform.target;
        // 防連點：畫面卡頓時排隊的連續 click 會在同一個「＋」上狂插一堆節點
        const now = Date.now();
        if (poly.__lastNodeInsert && now - poly.__lastNodeInsert < 350) return;
        poly.__lastNodeInsert = now;
        const a = poly.points[i], b = poly.points[(i + 1) % poly.points.length];
        poly.points.splice(i + 1, 0, { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
        poly.controls = buildPolyEditControls(poly);
        poly.dirty = true;
        canvas.requestRenderAll();
        pushState();
    };
}
function buildPolyEditControls(poly) {
    const controls = {};
    poly.points.forEach((pt, i) => {
        controls['p' + i] = new fabric.Control({
            positionHandler: polyPointPositionHandler(i),
            actionHandler: polyAnchorWrapper(i > 0 ? i - 1 : poly.points.length - 1, polyPointActionHandler(i)),
            actionName: 'modifyPoly',
            cursorStyle: 'crosshair',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#2779bd'; ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 1.5;
                ctx.beginPath(); ctx.arc(left, top, 6, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.restore();
            }
        });
    });
    // 節點多時不放「＋」控制點（控制點數量加倍會拖慢渲染；要加節點的情境都是少節點的折線）
    const segCount = (poly.points.length > 40) ? 0 : ((poly.type === 'polygon') ? poly.points.length : poly.points.length - 1);
    for (let i = 0; i < segCount; i++) {
        controls['m' + i] = new fabric.Control({
            positionHandler: polyMidPositionHandler(i),
            mouseDownHandler: polyInsertPointHandler(i),
            actionHandler: function () { return false; },   // ＋只負責插入節點，插完由使用者拖新出現的實心點
            cursorStyle: 'copy',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#2779bd'; ctx.lineWidth = 1.2;
                ctx.beginPath(); ctx.arc(left, top, 5, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.strokeStyle = '#2779bd'; ctx.lineWidth = 1.4;
                ctx.beginPath();
                ctx.moveTo(left - 3, top); ctx.lineTo(left + 3, top);
                ctx.moveTo(left, top - 3); ctx.lineTo(left, top + 3);
                ctx.stroke();
                ctx.restore();
            }
        });
    }
    return controls;
}
/* ── 直線選取＝直接出現頭尾兩個端點圓點，拖曳即改線段長短/方向（不再是一般物件的整體縮放）──
   拖端點時把線攤平回 angle=0/scale=1 再用絕對座標重設 x1..y2（fabric.Line 的 _set 會自動重算外框），
   不改物件型別，端點箭頭下拉/角度欄等 isLineLike 相關功能全部不受影響。 */
/* 頭尾座標：一般直線取線段兩端，箭頭群組取「箭頭尖端」（群組內線段有被縮短，不能拿線段端點當頭尾） */
function anyLineEndpoints(o) { return (o.type === 'line') ? lineAbsEndpoints(o) : trueArrowEndpoints(o); }
function lineEndPositionHandler(which) {
    return function (dim, finalMatrix, o) {
        if (!o.canvas) return new fabric.Point(-99999, -99999);   // 已被移出畫布的殘留選取
        const p = anyLineEndpoints(o)[which];
        return fabric.util.transformPoint(new fabric.Point(p.x, p.y), o.canvas.viewportTransform);
    };
}
function lineEndActionHandler(which) {
    return function (eventData, transform, x, y) {
        const o = transform.target;
        const other = anyLineEndpoints(o)[which === 0 ? 1 : 0];
        const a = (which === 0) ? { x, y } : other;
        const b = (which === 0) ? other : { x, y };
        if (!isFinite(a.x) || !isFinite(a.y) || !isFinite(b.x) || !isFinite(b.y)) return false;   // 防 NaN 毒化
        if (o.type !== 'line') return reshapeArrowGroup(o, a, b);   // 箭頭群組：整支依新頭尾重畫（尖端才不會跑位）
        o.set({ angle: 0, scaleX: 1, scaleY: 1, flipX: false, flipY: false });
        o.set({ x1: a.x, y1: a.y, x2: b.x, y2: b.y });
        o.setCoords();
        return true;
    };
}
function buildLineEndControls() {
    const controls = {};
    [0, 1].forEach(i => {
        controls['e' + i] = new fabric.Control({
            positionHandler: lineEndPositionHandler(i),
            actionHandler: lineEndActionHandler(i),
            actionName: 'modifyLine',
            cursorStyle: 'crosshair',
            render: function (ctx, left, top) {
                ctx.save();
                ctx.fillStyle = '#2779bd'; ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 1.5;
                ctx.beginPath(); ctx.arc(left, top, 6, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
                ctx.restore();
            }
        });
    });
    return controls;
}
function refreshLineEndControls(e) {
    ((e && e.deselected) || []).forEach(o => { if (o.__lineEndCtrls) { delete o.controls; delete o.__lineEndCtrls; } });
    const obj = canvas.getActiveObject();
    // 直線與箭頭群組（isArrowGroup）一律改成「只有頭尾兩個圓點」的線段編輯，不用一般物件的外框縮放；
    // 標註（dimKind：尺寸線/直徑）自成一組畫法，維持原本的整體縮放不動
    if (obj && isLineLike(obj) && !obj.dimKind && !obj.isDimGuide && !obj.__pointEditing && !obj.locked && !obj.__lineEndCtrls) {
        obj.controls = buildLineEndControls();
        obj.__lineEndCtrls = true;
    }
}
canvas.on('selection:created', refreshLineEndControls);
canvas.on('selection:updated', refreshLineEndControls);
canvas.on('selection:cleared', function (e) {
    ((e && e.deselected) || []).forEach(o => { if (o.__lineEndCtrls) { delete o.controls; delete o.__lineEndCtrls; } });
});
function toEditablePolyline(obj) {
    if (obj.type === 'polyline' || obj.type === 'polygon') return obj;
    if (obj.type === 'rect') {
        // 矩形 → 四角多邊形（封閉），之後就能拖角、插入節點拉成任意形狀
        obj.setCoords();
        const c = obj.aCoords;
        const poly = new fabric.Polygon([
            { x: c.tl.x, y: c.tl.y }, { x: c.tr.x, y: c.tr.y }, { x: c.br.x, y: c.br.y }, { x: c.bl.x, y: c.bl.y }
        ], {
            stroke: obj.stroke, strokeWidth: obj.strokeWidth, strokeDashArray: obj.strokeDashArray || null,
            fill: obj.fill || 'transparent', strokeUniform: true, strokeLineJoin: 'miter'
        });
        canvas.remove(obj);
        canvas.add(poly);
        return poly;
    }
    if (obj.type !== 'line') return null;
    const pts = lineAbsEndpoints(obj);
    const poly = new fabric.Polyline([{ x: pts[0].x, y: pts[0].y }, { x: pts[1].x, y: pts[1].y }], {
        stroke: obj.stroke, strokeWidth: obj.strokeWidth, strokeDashArray: obj.strokeDashArray || null,
        fill: 'transparent', strokeUniform: true, strokeLineCap: 'round', strokeLineJoin: 'round'
    });
    canvas.remove(obj);
    canvas.add(poly);
    return poly;
}
/* 離開編輯端點：還原預設控制點（供切換鈕、Esc、點空白/點其他物件共用） */
function exitPointEdit(obj) {
    if (!obj || !obj.__pointEditing) return;
    delete obj.__pointEditing;
    delete obj.controls;          // 還原成 prototype 預設控制點
    obj.hasBorders = true;
    obj.objectCaching = !obj.curved;   // 圓滑曲線可能超出節點外框，關快取避免被裁掉
    obj.setCoords();
}
/* 點空白處或點選其他物件＝自動離開編輯端點（否則物件卡在節點模式，回頭點選還是節點控制點） */
canvas.on('selection:cleared', function (e) {
    (e && e.deselected || []).forEach(o => { if (o.__pointEditing) { exitPointEdit(o); canvas.requestRenderAll(); } });
});
canvas.on('selection:updated', function (e) {
    (e && e.deselected || []).forEach(o => { if (o.__pointEditing) { exitPointEdit(o); canvas.requestRenderAll(); } });
});
function togglePointEdit() {
    const obj = canvas.getActiveObject();
    if (!obj) return;
    if (obj.__pointEditing) {
        exitPointEdit(obj);
        canvas.requestRenderAll();
        refreshPropbar();
        pushState();
        return;
    }
    const poly = toEditablePolyline(obj);
    if (!poly) { toast('只有直線、折線、矩形、不規則遮蓋可以編輯端點'); return; }
    poly.__pointEditing = true;
    poly.objectCaching = false;       // 編輯中即時重繪，節點拖曳才不會殘影
    poly.hasBorders = false;
    poly.controls = buildPolyEditControls(poly);
    canvas.setActiveObject(poly);
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
    toast('拖曳藍色圓點調整形狀；點線段中間的「＋」新增節點；屬性列可「封閉/打開」頭尾、「圓滑/取直」曲線；再按一次「編輯端點」完成');
}
/* 編輯端點模式的兩個附加功能：封閉/打開（折線↔多邊形）、圓滑/取直（直線段↔通過節點的曲線） */
function togglePolyClosed() {
    const obj = canvas.getActiveObject();
    if (!obj || !obj.__pointEditing) return;
    const Cls = (obj.type === 'polygon') ? fabric.Polyline : fabric.Polygon;
    const np = new Cls(obj.points.map(p => ({ x: p.x, y: p.y })), {
        left: obj.left, top: obj.top, angle: obj.angle, scaleX: obj.scaleX, scaleY: obj.scaleY,
        flipX: obj.flipX, flipY: obj.flipY,
        stroke: obj.stroke, strokeWidth: obj.strokeWidth, strokeDashArray: obj.strokeDashArray || null,
        fill: obj.fill, strokeUniform: obj.strokeUniform,
        strokeLineCap: obj.strokeLineCap, strokeLineJoin: obj.strokeLineJoin
    });
    np.curved = obj.curved;
    const idx = canvas.getObjects().indexOf(obj);
    canvas.remove(obj);
    canvas.add(np);
    if (idx >= 0) np.moveTo(idx);
    np.__pointEditing = true;
    np.objectCaching = false;
    np.hasBorders = false;
    np.controls = buildPolyEditControls(np);
    canvas.setActiveObject(np);
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
}
function togglePolySmooth() {
    const obj = canvas.getActiveObject();
    if (!obj || !obj.__pointEditing) return;
    if (!obj.curved && obj.points.length < 3) { toast('至少要 3 個節點才能圓滑（先用「＋」加節點）'); return; }
    obj.curved = !obj.curved;
    obj.dirty = true;
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
}

/* 雙底線：fabric 原生只有單底線(underline)。doubleUnderline=true 時沿用 fabric 自己的底線繪製流程，
   把 offset 往下移一段再畫第二條（同粗細同顏色），跟原生底線完全同款式。 */
(function installDoubleUnderline() {
    const origRender = fabric.Text.prototype._render;
    fabric.Text.prototype._render = function (ctx) {
        origRender.call(this, ctx);
        if (this.doubleUnderline && this.underline) {
            this.offsets = { underline: 0.24, linethrough: this.offsets.linethrough, overline: this.offsets.overline };
            this._renderTextDecoration(ctx, 'underline');
            delete this.offsets;   // 還原回 prototype 上的預設值（0.10）
        }
    };
})();

/* ── 文字 / 標籤（Figma 式：隨時可移動、雙擊編輯、拉角縮放） ──
   文字＝單一 IText，底色可選；標籤＝固定有邊框的文字框（像標籤機印出來的標籤），是「邊框 Rect + IText」
   的小群組，雙擊比照標籤庫的群組內文字編輯機制（startGroupTextEdit／finishGroupTextEdit），改完字邊框
   自動貼合新字長（finishGroupTextEdit 內 isQuickLabel 分支處理，不影響標籤庫既有的規格標籤重建邏輯）。 */
/* 新文字/標籤的預設底色：與「改選取物件底色」分離存放，改既有物件的底色不會污染這個預設，
   之後新畫的文字/標籤才不會跟著沿用上一個被改過的顏色（每個物件底色各自獨立）。
   要改新物件預設＝先點空白處取消選取，再調底色色票（見 applyTextBg 的 !obj 分支）。 */
let newTextBg = { on: false, color: '#fff59d' };
function addText(x, y, isLabel) {
    if (isLabel) { addLabelBox(x, y); return; }
    const size = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
    const color = document.getElementById('p-textcolor').value;
    const bold = document.getElementById('p-bold').checked;
    const bgOn = newTextBg.on;
    const bg = newTextBg.color;
    const ul = document.getElementById('p-underline').value;
    const t = new fabric.IText('輸入文字', {
        left: x, top: y, fontSize: size, fill: color,
        fontFamily: '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        fontWeight: bold ? 'bold' : 'normal',
        backgroundColor: bgOn ? bg : '',
        underline: ul !== 'none'
    });
    t.doubleUnderline = (ul === 'double');
    canvas.add(t);
    canvas.setActiveObject(t);
    setTool('select');
    t.enterEditing(); t.selectAll();
    canvas.requestRenderAll();
    pushState();
}
function labelBoxPadding(fontSize) { return Math.max(6, fontSize * 0.28); }
function addLabelBox(x, y) {
    const size = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
    const color = document.getElementById('p-textcolor').value;
    const bg = newTextBg.on ? newTextBg.color : '#fff59d';
    const text = new fabric.IText('標籤文字', {
        fontSize: size, fill: color, fontWeight: 'bold',
        fontFamily: '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        originX: 'center', originY: 'center', left: 0, top: 0
    });
    const pad = labelBoxPadding(size);
    const box = new fabric.Rect({
        width: text.width + pad * 2, height: text.height + pad * 2, left: 0, top: 0,
        originX: 'center', originY: 'center', fill: bg, stroke: color, strokeWidth: 1.5, rx: 4, ry: 4
    });
    const g = new fabric.Group([box, text], { left: x, top: y });
    g.labelSpec = { kind: 'fabric' };
    g.labelKind = 'fabric';
    g.isQuickLabel = true;
    canvas.add(g);
    canvas.setActiveObject(g);
    setTool('select');
    canvas.requestRenderAll();
    pushState();
    startGroupTextEdit(g, text);   // 放上後直接進入編輯，跟文字工具手感一致
}

/* ── 工程符號快速插入 ＋ ^ 上下公差堆疊文字（Inventor 式） ─────────────
   符號：屬性列「文字」區與「建立文字標籤」跳窗各有一條符號鈕，點一下插到游標處。
   公差：一般文字輸入 A^B（如 25 -0^-0.18），結束編輯自動變成「-0 疊在 -0.18 上」
   的小字（0.55 倍），雙擊可還原成含 ^ 的原始字串整串重編。 */
const EG_SYMBOLS = [
    ['Ø', '直徑'], ['°', '度'], ['±', '正負公差'], ['▽', '加工符號（研磨＝連按多個）'],
    ['↧', '深度'], ['⌴', '沉頭孔／柱坑'], ['⌵', '錐坑'], ['□', '正方形'],
    ['⌒', '圓弧'], ['Ra', '表面粗糙度'], ['×', '乘號']
];
(function initSymStrips() {
    const mk = fn => EG_SYMBOLS.map(s =>
        '<button class="pb-btn" style="min-width:30px;padding:4px 6px;font-size:13px;" onclick="' + fn + '(\'' + s[0] + '\')" title="' + s[1] + '">' + s[0] + '</button>').join('');
    document.getElementById('sym-pad').innerHTML = mk('insertSym');
    document.getElementById('nl-sym-strip').innerHTML = mk('nlInsertSym');
    // 浮動快捷列：符號（編輯文字時浮在輸入框上方）＋ 旋轉角度（選取物件時浮在物件上方）
    document.getElementById('float-syms').innerHTML = EG_SYMBOLS.map(s =>
        '<button onclick="insertSym(\'' + s[0] + '\')" title="' + s[1] + '">' + s[0] + '</button>').join('');
    document.getElementById('float-rot').innerHTML = [45, -90, 90, 180].map(a =>
        '<button onclick="rotateQuickBy(' + a + ')" title="以目前角度為基準，再旋轉 ' + a + ' 度（物件中心）">' + (a > 0 ? '+' : '') + a + '°</button>').join('')
        + '<button onclick="flipSelected(\'h\')" title="水平翻轉（左右鏡射）">⇋</button>'
        + '<button onclick="flipSelected(\'v\')" title="垂直翻轉（上下鏡射）">⇵</button>'
        + '<input type="number" id="float-rot-v" min="-360" max="360" step="1" placeholder="±°"'
        + ' title="手動輸入相對角度（-360～360，以目前角度為 0 點），Enter 或離開欄位即套用"'
        + ' style="width:56px;background:rgba(255,255,255,.75);border:1px solid #cbb377;border-radius:4px;color:#5a4a20;font-size:12px;padding:3px 4px;"'
        + ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();applyFloatRotInput();}" onchange="applyFloatRotInput()">';
})();
/* 旋轉快捷鍵：以「目前角度」為 0 點的相對旋轉（圖塊/圖片看不出原始 0 度方向，相對轉比較直覺；
   屬性列「角度」欄位維持絕對角度，會同步更新） */
function rotateQuickBy(delta) {
    const obj = canvas.getActiveObject(); if (!obj) return;
    obj.rotate(normDeg((obj.angle || 0) + delta));   // normDeg 正規化到 ±180，避免角度無限累積
    obj.setCoords();
    if (obj.isDimGuide && obj.dimAngleId) rebuildDimAngleArc(obj.dimAngleId);
    refreshPropbar();
    canvas.requestRenderAll();
    pushState();
}
/* 水平/垂直翻轉（小畫家式鏡射）：群組/多選整體翻 */
function flipSelected(axis) {
    const obj = canvas.getActiveObject(); if (!obj) return;
    const k = (axis === 'h') ? 'flipX' : 'flipY';
    obj.set(k, !obj[k]);
    obj.setCoords();
    canvas.requestRenderAll();
    pushState();
}
function applyFloatRotInput() {
    const inp = document.getElementById('float-rot-v');
    let v = parseFloat(inp.value);
    if (isNaN(v) || v === 0) { inp.value = ''; return; }
    v = Math.max(-360, Math.min(360, v));   // 限制 ±360 度，避免計算錯誤
    inp.value = '';
    rotateQuickBy(v);
}
/* 浮動快捷列定位：跟著選取物/編輯框走（每次畫布重繪時更新，移動/縮放/捲動都會跟上） */
function positionFloatBars() {
    const symEl = document.getElementById('float-syms');
    const rotEl = document.getElementById('float-rot');
    const obj = canvas.getActiveObject();
    const editing = !!(obj && obj.isEditing);
    // 框選搬移/複製切下的圖塊也要能就地旋轉，所以這幾個工具選取中也顯示旋轉快捷列
    const showRot = !!(obj && !editing && !obj.__pointEditing
        && ['select', 'cropmove', 'cropcopy', 'cropmovelasso'].includes(currentTool));
    symEl.style.display = editing ? 'flex' : 'none';
    rotEl.style.display = showRot ? 'flex' : 'none';
    const el = editing ? symEl : (showRot ? rotEl : null);
    if (!el || !obj) return;
    const br = obj.getBoundingRect();   // 含視圖縮放/平移＝畫布像素座標
    const cr = canvas.upperCanvasEl.getBoundingClientRect();
    const w = el.offsetWidth, h = el.offsetHeight;
    // 旋轉快捷列要再往上讓開 fabric 的旋轉控制點(在物件上緣正中央約 40px 處)＋控制點角尺寸，
    // 否則快捷列剛好蓋住旋轉點，使用者按不到。文字編輯中的符號列不會有旋轉點，維持小間距即可。
    const rotHandleClear = (el === rotEl && obj.hasRotatingPoint !== false && obj.lockRotation !== true)
        ? 40 + (fabric.Object.prototype.cornerSize || 9) + 6 : 0;
    const gap = 10 + rotHandleClear;
    let left = cr.left + br.left + br.width / 2 - w / 2;
    let top = cr.top + br.top - h - gap;
    left = Math.max(4, Math.min(left, window.innerWidth - w - 4));
    if (top < cr.top + 4) top = cr.top + br.top + br.height + 10;   // 上方放不下改物件下方
    el.style.left = left + 'px';
    el.style.top = top + 'px';
}
canvas.on('after:render', positionFloatBars);
/* 符號浮動面板：開在按鈕正下方；點面板/按鈕以外的地方自動收起 */
function toggleSymPad() {
    const pad = document.getElementById('sym-pad');
    if (pad.style.display === 'flex') { pad.style.display = 'none'; return; }
    const r = document.getElementById('sym-btn').getBoundingClientRect();
    pad.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 220)) + 'px';
    pad.style.top = (r.bottom + 4) + 'px';
    pad.style.display = 'flex';
}
document.addEventListener('mousedown', function (e) {
    const pad = document.getElementById('sym-pad');
    if (pad.style.display === 'flex' && !e.target.closest('#sym-pad, #sym-btn')) pad.style.display = 'none';
});
function insertSym(s) {
    const obj = canvas.getActiveObject();
    if (obj && obj.isEditing) {   // IText 編輯中（含標籤/群組文字的暫時編輯框）：插入游標處
        const st = obj.selectionStart || 0, en = obj.selectionEnd || 0;
        obj.insertChars(s, null, st, en);
        obj.selectionStart = obj.selectionEnd = st + s.length;
        if (obj.hiddenTextarea) {   // 同步隱藏 textarea，接著打字才不會吃掉剛插入的符號
            obj.hiddenTextarea.value = obj.text;
            obj.hiddenTextarea.selectionStart = obj.hiddenTextarea.selectionEnd = obj.selectionStart;
        }
        obj.dirty = true;
        canvas.requestRenderAll();
        return;
    }
    if (obj && (obj.type === 'i-text' || obj.type === 'text') && !obj.merged) {   // 只選取未進編輯：附加到最後
        obj.set('text', obj.text + s);
        obj.dirty = true; obj.setCoords();
        canvas.requestRenderAll(); pushState();
        return;
    }
    toast('請先雙擊要編輯的文字（或用文字工具點畫布）再按符號');
}
function nlInsertSym(s) {
    const ta = document.getElementById('nl-text');
    const st = ta.selectionStart || 0, en = ta.selectionEnd || 0;
    ta.value = ta.value.slice(0, st) + s + ta.value.slice(en);
    ta.selectionStart = ta.selectionEnd = st + s.length;
    ta.focus();
}
/* ^ 公差：兩側限「數字/字母/±.,°」的短字串，避免把 25-0^-0.18 的基準值 25 一起吃進堆疊 */
const TOL_INPUT_RE = /([+\-±]?[\w.,°]+)\^([+\-±]?[\w.,°]+)/;
function makeTolGroup(raw, style) {
    const fs = style.fontSize || 28;
    const small = Math.max(8, Math.round(fs * 0.55));
    const base = {
        fontFamily: style.fontFamily || '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        fontWeight: style.fontWeight || 'normal', fill: style.fill || '#d32f2f',
        backgroundColor: style.backgroundColor || ''
    };
    const items = [];
    const lineH = fs * 1.16;
    String(raw).split('\n').forEach((line, li) => {
        const cy = li * lineH;   // 該行的垂直中線
        let x = 0, last = 0, m;
        const re = new RegExp(TOL_INPUT_RE.source, 'g');
        const put = str => {   // 一般（大字）片段
            if (!str) return;
            const t = new fabric.Text(str, Object.assign({}, base, { fontSize: fs, left: x, top: cy, originY: 'center' }));
            items.push(t); x += t.width;
        };
        while ((m = re.exec(line))) {
            put(line.slice(last, m.index));
            // 上排底貼中線、下排頂貼中線＝整疊高度約等於基準字高
            const up = new fabric.Text(m[1], Object.assign({}, base, { fontSize: small, left: x, top: cy - fs * 0.02, originY: 'bottom' }));
            const dn = new fabric.Text(m[2], Object.assign({}, base, { fontSize: small, left: x, top: cy + fs * 0.02, originY: 'top' }));
            items.push(up, dn);
            x += Math.max(up.width, dn.width);
            last = m.index + m[0].length;
        }
        put(line.slice(last));
    });
    if (!items.length) return null;
    const g = new fabric.Group(items, {});
    g.labelSpec = { kind: 'tol', text: String(raw), fontSize: fs, fill: base.fill,
                    fontWeight: base.fontWeight, fontFamily: base.fontFamily, backgroundColor: base.backgroundColor };
    g.labelKind = 'tol';
    return g;
}
function convertToTolGroup(t) {
    let g = null;
    try {
        g = makeTolGroup(t.text, { fontSize: t.fontSize, fill: t.fill, fontWeight: t.fontWeight,
                                   fontFamily: t.fontFamily, backgroundColor: t.backgroundColor });
    } catch (e) { console.warn('[EGdraw] 公差文字建立例外：', e); }
    if (!g || !isFinite(g.width) || !isFinite(g.height)) return;   // 建失敗就保留原文字，不毒化畫布
    const c = t.getCenterPoint();
    g.set({ angle: t.angle, scaleX: t.scaleX, scaleY: t.scaleY, originX: 'center', originY: 'center' });
    g.setPositionByOrigin(c, 'center', 'center');
    g.setCoords();
    canvas.remove(t);
    canvas.add(g);
    canvas.setActiveObject(g);
    canvas.requestRenderAll();
    pushState();
}
/* 雙擊 ^ 公差群組：還原成含 ^ 的原始字串整串重編（比照 startGroupTextEdit 的暫時編輯框機制） */
function startTolEdit(group) {
    const spec = group.labelSpec;
    const c = group.getCenterPoint();
    const tmp = new fabric.IText(spec.text, {
        left: c.x, top: c.y, originX: 'center', originY: 'center',
        angle: group.angle, scaleX: group.scaleX, scaleY: group.scaleY,
        fontSize: spec.fontSize || 28,
        fontFamily: spec.fontFamily || '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        fontWeight: spec.fontWeight || 'normal', fill: spec.fill || '#d32f2f',
        backgroundColor: '#fff8d6'
    });
    tmp.__groupEditFor = group;   // 讓「刪除」知道使用者要刪的是整組
    group.visible = false;
    canvas.add(tmp);
    canvas.setActiveObject(tmp);
    tmp.enterEditing();
    tmp.selectAll();
    tmp.on('editing:exited', function () {
        const val = tmp.text;
        try { tmp.abortCursorAnimation(); } catch (e) { /* 游標動畫沒在跑就算了 */ }
        canvas.remove(tmp);
        if (tmp.__deleteGroup) {
            canvas.remove(group);
            canvas.discardActiveObject();
            canvas.requestRenderAll();
            pushState();
            return;
        }
        if (restoring || canvas.getObjects().indexOf(group) === -1) { canvas.requestRenderAll(); return; }
        group.visible = true;
        const style = { fontSize: tmp.fontSize, fill: tmp.fill, fontWeight: tmp.fontWeight, fontFamily: tmp.fontFamily,
                        backgroundColor: (tmp.backgroundColor !== '#fff8d6') ? tmp.backgroundColor : (spec.backgroundColor || '') };
        const center = group.getCenterPoint();
        const { scaleX, scaleY, angle } = group;
        let ng = null;
        if (TOL_INPUT_RE.test(val)) {
            try { ng = makeTolGroup(val, style); } catch (e) { console.warn('[EGdraw] 公差文字重建例外：', e); }
        } else {   // 改到沒有 ^ 了：變回一般文字
            ng = new fabric.IText(val, { fontSize: style.fontSize, fill: style.fill, fontWeight: style.fontWeight,
                                         fontFamily: style.fontFamily, backgroundColor: style.backgroundColor });
        }
        if (!ng || !isFinite(ng.width) || !isFinite(ng.height)) {
            toast('公差文字重建失敗，已保留原內容（此次修改未套用）');
            canvas.requestRenderAll();
            return;
        }
        canvas.remove(group);
        ng.set({ scaleX, scaleY, angle, originX: 'center', originY: 'center' });
        ng.setPositionByOrigin(center, 'center', 'center');
        ng.setCoords();
        canvas.add(ng);
        canvas.setActiveObject(ng);
        canvas.requestRenderAll();
        pushState();
    });
}

/* ── 快速標註（CAD 風格）：距離 / 直徑 / 角度 ──────────────────────────
   標註線＋文字數值包成同一個群組（labelSpec kind='fabric'），移動時一起動；雙擊沿用標籤庫既有的
   「群組內文字編輯」機制（startGroupTextEdit）。距離/直徑無法從像素推算真實尺寸，文字預設留空
   （直徑帶 ⌀ 前綴）由使用者自行輸入實測值；角度是純幾何夾角、不受比例尺影響，自動帶入算好的度數。 */
/* 標註文字的角度與擺放位置：沿線段方向、放在線的上側；角度正規化到 ±90 內，字不會上下顛倒 */
function dimTextPose(x1, y1, x2, y2, extraGap) {
    let a = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    if (a > 90) a -= 180;
    if (a < -90) a += 180;
    const fs = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
    const k = fs * 0.75 + (extraGap || 0);
    const rad = a * Math.PI / 180;
    return { x: (x1 + x2) / 2 + Math.sin(rad) * k, y: (y1 + y2) / 2 - Math.cos(rad) * k, angle: a };
}
function makeDimText(x, y, str, angleDeg) {
    const t = new fabric.IText(str || '', {
        left: x, top: y, originX: 'center', originY: 'center',
        fontSize: parseInt(document.getElementById('p-fontsize').value, 10) || 28,
        fill: document.getElementById('p-textcolor').value, fontWeight: 'bold',
        fontFamily: '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif',
        backgroundColor: '#ffffff', angle: angleDeg || 0
    });
    t.dimKind = 'label';
    return t;
}
/* withTicks：距離標註兩端有垂直小刻度線（CAD 尺寸界線收尾），直徑標註不要（會看起來像多出兩條線） */
function makeDimDistanceShape(x1, y1, x2, y2, color, width, dash, withTicks, textPrefix, extendOut) {
    const angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
    const headLen = arrowHeadLen(width);
    const rad0 = angle * Math.PI / 180, ux = Math.cos(rad0), uy = Math.sin(rad0);
    // 線只畫到「箭頭底部」不畫到尖端，粗線才不會從箭頭尖旁邊露出來
    const len = Math.hypot(x2 - x1, y2 - y1);
    const s = (len > headLen * 2 + 4) ? headLen : 0;
    const items = [
        new fabric.Line([x1 + ux * s, y1 + uy * s, x2 - ux * s, y2 - uy * s], { stroke: color, strokeWidth: width, strokeUniform: true, strokeDashArray: dash || null }),
        arrowHeadTri(x2, y2, angle, headLen, color),
        arrowHeadTri(x1, y1, angle + 180, headLen, color)
    ];
    if (withTicks) {
        const nx = -Math.sin(angle * Math.PI / 180), ny = Math.cos(angle * Math.PI / 180);
        const tick = 8 + width;
        items.push(new fabric.Line([x1 - nx * tick, y1 - ny * tick, x1 + nx * tick, y1 + ny * tick], { stroke: color, strokeWidth: Math.max(1, width * 0.6) }));
        items.push(new fabric.Line([x2 - nx * tick, y2 - ny * tick, x2 + nx * tick, y2 + ny * tick], { stroke: color, strokeWidth: Math.max(1, width * 0.6) }));
    }
    if (extendOut) {
        // 延伸式（直徑標註第二種樣式）：線越過第二點往外延伸，文字沿斜線放在延伸段上方（拖曳結束端＝文字端）
        const fs = parseInt(document.getElementById('p-fontsize').value, 10) || 28;
        const extLen = fs * 2.2 + headLen;
        items.push(new fabric.Line([x2, y2, x2 + ux * extLen, y2 + uy * extLen],
            { stroke: color, strokeWidth: width, strokeUniform: true, strokeDashArray: dash || null }));
        let a2 = angle;                            // 角度正規化到 ±90，字不會上下顛倒
        if (a2 > 90) a2 -= 180;
        if (a2 < -90) a2 += 180;
        const mx = x2 + ux * extLen * 0.55, my = y2 + uy * extLen * 0.55;
        const k = fs * 0.75 + width;
        const rad2 = a2 * Math.PI / 180;
        items.push(makeDimText(mx + Math.sin(rad2) * k, my - Math.cos(rad2) * k, textPrefix || '', a2));
    } else {
        const pose = dimTextPose(x1, y1, x2, y2, width);
        items.push(makeDimText(pose.x, pose.y, textPrefix || '', pose.angle));
    }
    const g = new fabric.Group(items, {});
    g.labelSpec = { kind: 'fabric' };   // 讓雙擊走「群組內文字編輯」而不是拆群組
    g.dimKind = 'distance';
    return g;
}
/* 角度標註：點一下產生「角度標示」（雙箭頭弧線＋度數）＋兩條各自獨立的虛擬輔助線。
   - 兩條輔助線可以分開放：拖曳頭尾圓形控制點各自對齊圖面上要量的兩條邊（不用相交在同一點）
   - 角度＝兩線方向的夾角；弧線畫在兩線延伸的交點上（平行時畫在兩線中間）
   - 輔助線平常自動隱藏，點選角度標示（或輔助線本身）才顯示（見 updateDimGuideVisibility）
   - 標示與輔助線用 dimAngleId 綁定：一起存檔還原、刪掉任一個時整組一併刪除（見 deleteSelection） */
function guidesOfAngle(id) { return canvas.getObjects().filter(o => o.isDimGuide && o.dimAngleId === id); }
function arcOfAngle(id) { return canvas.getObjects().find(o => o.dimKind === 'angle' && o.dimAngleId === id); }
function lineLineIntersection(a, b) {
    const [p1, p2] = a, [p3, p4] = b;
    const d = (p2.x - p1.x) * (p4.y - p3.y) - (p2.y - p1.y) * (p4.x - p3.x);
    if (Math.abs(d) < 1e-6) return null;   // 平行
    const t = ((p3.x - p1.x) * (p4.y - p3.y) - (p3.y - p1.y) * (p4.x - p3.x)) / d;
    return { x: p1.x + t * (p2.x - p1.x), y: p1.y + t * (p2.y - p1.y) };
}
function startDimAngle(vx, vy) {
    const id = 'da' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const R = 90;
    const mkGuide = angDeg => {
        const rad = angDeg * Math.PI / 180;
        const dx = Math.cos(rad) * R, dy = Math.sin(rad) * R;
        const line = new fabric.Line([vx - dx, vy - dy, vx + dx, vy + dy], {
            stroke: '#2b8fd6', strokeWidth: 3, strokeDashArray: [7, 5], opacity: 0.9,
            hasBorders: false, padding: 8
        });
        line.isDimGuide = true;      // 頭尾圓形控制點沿用直線的全域端點控制，拖端點即改方向/位置
        line.dimAngleId = id;
        return line;
    };
    canvas.add(mkGuide(0)); canvas.add(mkGuide(-60));
    rebuildDimAngleArc(id);
    const arc = arcOfAngle(id);
    if (arc) canvas.setActiveObject(arc);
    setTool('select');
    updateDimGuideVisibility();
    canvas.requestRenderAll();
    pushState();
    toast('拖曳藍色虛線頭尾的圓點，把兩條線各自對齊要量的邊（可分開放）；點空白處虛線自動隱藏，點角度標示可再叫出來調整');
}
function rebuildDimAngleArc(id) {
    const gs = guidesOfAngle(id);
    if (gs.length < 2) return;
    const old = arcOfAngle(id);
    // 使用者改過度數文字的樣式（底色/顏色/字級…）時，重建後要沿用，不能每次調整輔助線就重設
    const oldText = (old && old.getObjects) ? old.getObjects().find(o => o.type === 'i-text') : null;
    if (old) canvas.remove(old);
    const [g1, g2] = gs;
    const e1 = lineAbsEndpoints(g1), e2 = lineAbsEndpoints(g2);
    let c = lineLineIntersection(e1, e2);
    if (!c) c = { x: (e1[0].x + e1[1].x + e2[0].x + e2[1].x) / 4, y: (e1[0].y + e1[1].y + e2[0].y + e2[1].y) / 4 };
    const a1 = trueLineAngle(g1), a2 = trueLineAngle(g2);
    let diff = ((a2 - a1) % 360 + 360) % 360;
    let signedHalf = diff > 180 ? (diff - 360) / 2 : diff / 2;
    if (diff > 180) diff = 360 - diff;
    const R = 40;
    const sweep = (((a2 - a1) % 360 + 360) % 360) <= 180 ? 1 : 0;
    const rad = deg => deg * Math.PI / 180;
    const sx = c.x + R * Math.cos(rad(a1)), sy = c.y + R * Math.sin(rad(a1));
    const ex = c.x + R * Math.cos(rad(a2)), ey = c.y + R * Math.sin(rad(a2));
    const color = document.getElementById('p-stroke').value;
    const width = parseInt(document.getElementById('p-width').value, 10) || 3;
    const arcW = Math.max(2, width * 0.7);
    const arc = new fabric.Path('M ' + sx + ' ' + sy + ' A ' + R + ' ' + R + ' 0 0 ' + sweep + ' ' + ex + ' ' + ey,
        { stroke: color, strokeWidth: arcW, fill: 'transparent' });
    // 弧線兩端加箭頭（沿弧線切線方向、尖端貼齊弧線端點），像真正的 CAD 角度標註
    const headLen = Math.max(14, Math.min(22, arcW * 6));
    const dirEnd = a2 + (sweep ? 90 : -90);
    const dirStart = a1 + (sweep ? -90 : 90);
    const midAngle = a1 + signedHalf;
    const tx = c.x + (R + 26) * Math.cos(rad(midAngle)), ty = c.y + (R + 26) * Math.sin(rad(midAngle));
    const text = makeDimText(tx, ty, diff.toFixed(1) + '°', 0);
    if (oldText) {
        text.set({ fill: oldText.fill, fontSize: oldText.fontSize, fontWeight: oldText.fontWeight, backgroundColor: oldText.backgroundColor, underline: !!oldText.underline });
        text.doubleUnderline = !!oldText.doubleUnderline;
    }
    const arcGroup = new fabric.Group([
        arc,
        arrowHeadTri(sx, sy, dirStart, headLen, color),
        arrowHeadTri(ex, ey, dirEnd, headLen, color),
        text
    ], {});
    arcGroup.labelSpec = { kind: 'fabric' };   // 雙擊可改度數文字（群組內文字編輯）
    arcGroup.dimKind = 'angle';
    arcGroup.dimAngleId = id;
    canvas.add(arcGroup);
    canvas.requestRenderAll();
}
/* 輔助線顯示規則：只有選到同一組的角度標示（或輔助線本身）才顯示，平常自動隱藏。
   隱藏時必須同時設為「不可選取」——不然 Ctrl+A/框選會把看不見的線掃進選取，
   變成一個拖得動、裡面卻空無一物的藍色選取框（幽靈選取）。 */
function updateDimGuideVisibility() {
    const ao = canvas.getActiveObject();
    const ids = new Set();
    if (ao) {
        if (ao.dimAngleId) ids.add(ao.dimAngleId);
        if (ao.type === 'activeSelection' && ao.getObjects) ao.getObjects().forEach(o => { if (o.dimAngleId) ids.add(o.dimAngleId); });
    }
    let changed = false;
    canvas.getObjects().forEach(o => {
        if (!o.isDimGuide) return;
        const want = ids.has(o.dimAngleId);
        if (o.visible !== want || o.selectable !== want) {
            o.visible = want; o.selectable = want; o.evented = want;
            o.dirty = true; changed = true;
        }
    });
    if (changed) canvas.requestRenderAll();
}
canvas.on('selection:created', updateDimGuideVisibility);
canvas.on('selection:updated', updateDimGuideVisibility);
canvas.on('selection:cleared', updateDimGuideVisibility);

/* ── 蓋章：回墨印（版式沿用 CAR 簽章：公司名兩列/日期/下段文字） ────────
   fabric 原生物件繪製 → 天生透明背景，蓋在圖上自動去背。 */
const STAMP_KAI = "DFKai-SB,BiauKai,KaiTi,'標楷體',serif";
function makeStamp(bottomText, color, size, dateStr) {
    const items = [];
    items.push(new fabric.Circle({ left: 50, top: 50, radius: 47, fill: 'transparent', stroke: color, strokeWidth: 2.6, originX: 'center', originY: 'center' }));
    const chord = y => { const r = 45.7, dy = y - 50, dx = Math.sqrt(Math.max(0, r * r - dy * dy)); return [50 - dx, 50 + dx]; };
    const c1 = chord(27.5), c2 = chord(68.5);
    items.push(new fabric.Line([c1[0], 27.5, c1[1], 27.5], { stroke: color, strokeWidth: 1.4 }));
    items.push(new fabric.Line([c2[0], 68.5, c2[1], 68.5], { stroke: color, strokeWidth: 1.4 }));
    const company = OWN_COMPANY || '';
    const l1 = company.substring(0, 4), l2 = company.substring(4);
    // 對齊 CAR 印章的 SVG baseline：fabric 用中心定位，中心 y ≈ baseline − 字級×0.35
    const fit = (txt, baseY, fs, maxW, font) => {
        if (!txt) return null;
        const t = new fabric.Text(txt, {
            left: 50, top: baseY - fs * 0.35, originX: 'center', originY: 'center',
            fontSize: fs, fill: color, fontWeight: 'bold', fontFamily: font || STAMP_KAI
        });
        if (t.width > maxW) t.set('scaleX', maxW / t.width);   // 字多自動壓縮（同 textLength）
        return t;
    };
    const bfs = bottomText.length > 3 ? 15 : 19;
    [fit(l1, 15, 11, 58), fit(l2, 26, 11.5, 76),
     fit(dateStr, 54.5, 14.5, 72, "'Times New Roman','Courier New',serif"),
     fit(bottomText, 84.5, bfs, 56)].forEach(t => { if (t) items.push(t); });
    const g = new fabric.Group(items, { originX: 'center', originY: 'center', opacity: 0.92 });
    const sc = size / Math.max(g.width, g.height);
    g.set({ scaleX: sc, scaleY: sc });
    return g;
}
function placeStamp(x, y) {
    const type = document.getElementById('p-stamp-type').value;
    const size = Math.max(40, parseInt(document.getElementById('p-stamp-size').value, 10) || 110);
    if (type === 'tpl') { placeTplStamp(x, y, size); return; }
    const conf = {
        self:  { text: USER_CNAME || '簽章', color: '#cf3a2b' },        // 本人＝紅（同 CAR，固定）
        tech:  { text: '技術課',             color: deptStampColorHex }, // 管理者可在「用章人員」設定藍/紅
        issue: { text: '發行章',             color: deptStampColorHex }
    }[type] || { text: USER_CNAME, color: '#cf3a2b' };
    const g = makeStamp(conf.text, conf.color, size, stampDateStr());
    g.set({ left: x, top: y });
    g.setCoords();
    canvas.add(g);
    canvas.requestRenderAll();
    pushState();
}

/* ── 模板章：圖章管理頁設計的參數化圖章（stamp_template），依種類選模板、變數帶被登記者資料 ── */
const STAMP_API = '/EGsystem/src/store/store_Stamp_API.php';
let TPL_STAMPS = [], TPL_HOLDERS = [];
function initTplStamps() {
    fetch(STAMP_API + '?action=pick_meta').then(r => r.json()).then(res => {
        if (!res.ok) return;
        TPL_STAMPS = res.templates || []; TPL_HOLDERS = res.holders || [];
        const sel = document.getElementById('p-stamp-tpl');
        sel.innerHTML = TPL_STAMPS.map(t =>
            `<option value="${t.id}">${(t.type_name ? t.type_name + '｜' : '') + t.tpl_name}</option>`).join('')
            || '<option value="">（無啟用模板，請先到圖章管理頁建立）</option>';
        onTplStampChange();
    }).catch(() => {});
}
function onTplStampChange() {
    const t = TPL_STAMPS.find(x => String(x.id) === document.getElementById('p-stamp-tpl').value);
    const wrapHolder = document.getElementById('wrap-stamp-holder');
    const hsel = document.getElementById('p-stamp-holder');
    if (!t) { wrapHolder.style.display = 'none'; hsel._list = []; return; }
    let schema = {}; try { schema = JSON.parse(t.schema_json || '{}'); } catch (e) {}
    const need = EGStampTpl.usesTokens(schema);
    if (!need.name && !need.dept && !need.position) {
        // 這顆模板純固定字樣/日期/編號，完全用不到「被登記對象」的資料 → 不需要選，直接隱藏，減少不必要的操作
        wrapHolder.style.display = 'none'; hsel._list = []; hsel._selfOk = false;
        return;
    }
    wrapHolder.style.display = '';
    // 對象清單＝該模板綁定種類的「使用中」登記；且要能提供此模板實際用到的變數（過濾掉選了也印不出東西的對象，如模板要{部門}但選到純個人卻沒填部門）
    let hs = TPL_HOLDERS.filter(h => (!t.type_id || String(h.type_id || '') === String(t.type_id || ''))
        && (!need.name || h.name) && (!need.dept || h.dept) && (!need.position || h.position));
    // 同一人可能同時登記多個模板（見圖章清冊）、也可能同時有主要部門＋兼任部門，後端已展開成多列。
    // 此模板若用不到{部門}變數，多列印出來結果完全相同（純重複），只保留一筆避免同一人顯示兩次；
    // 若模板要用到{部門}，才需要讓使用者依部門分別挑選（後端已依部門排序、標示主／兼任）。
    if (!need.dept) {
        const seen = new Set();
        hs = hs.filter(h => {
            const key = h.user_id != null ? 'u' + h.user_id : ('d' + h.dept_id + 'p' + h.position_id);
            if (seen.has(key)) return false;
            seen.add(key); return true;
        });
    }
    const selfOk = !need.dept && !need.position;   // 模板只用到{姓名}時，才能用「本人」快速預設（部門/職稱本人無法代表）
    hsel.innerHTML = (selfOk ? '<option value="">本人（' + (USER_CNAME || '') + '）</option>' : '<option value="">— 請選擇（此模板需要部門/職稱資料）—</option>') +
        hs.map((h, i) => `<option value="${i}">${h.holder_name}${h.dept_id ? '（部門章）' : ''}</option>`).join('');
    hsel._list = hs; hsel._selfOk = selfOk;
}
document.getElementById('p-stamp-type').addEventListener('change', function () {
    const isTpl = this.value === 'tpl';
    document.getElementById('wrap-stamp-tpl').style.display = isTpl ? '' : 'none';
    document.getElementById('wrap-stamp-holder').style.display = isTpl ? '' : 'none';
    if (isTpl && !TPL_STAMPS.length) initTplStamps();
});
document.getElementById('p-stamp-tpl').addEventListener('change', onTplStampChange);
async function placeTplStamp(x, y, size) {
    const t = TPL_STAMPS.find(o => String(o.id) === document.getElementById('p-stamp-tpl').value);
    if (!t) { toast('尚無可用模板，請先到「圖章管理」頁設計並啟用'); return; }
    let schema = {}; try { schema = JSON.parse(t.schema_json || '{}'); } catch (e) {}
    const need = EGStampTpl.usesTokens(schema);
    const holderNeeded = need.name || need.dept || need.position;
    const hsel = document.getElementById('p-stamp-holder');
    const h = (hsel._list || [])[parseInt(hsel.value, 10)];
    if (holderNeeded && !h && !(hsel._selfOk && hsel.value === '')) { toast('請先選擇被登記對象（此模板需要部門/職稱資料才印得出來）'); return; }
    const ctx = h ? { name: h.name || '', dept: h.dept || '', position: h.position || '' }
                  : { name: USER_CNAME || '', dept: '', position: '' };
    ctx.company = OWN_COMPANY || '';
    ctx.date = stampDateStr();
    if (EGStampTpl.hasSerial(schema)) {   // 有 {編號} 才取號（依模板跳號規則遞增，取了就算用掉）
        try {
            const fd = new FormData(); fd.append('template_id', t.id);
            const res = await fetch(STAMP_API + '?action=next_serial', { method: 'POST', body: fd }).then(r => r.json());
            if (!res.ok) { toast(res.error || '取編號失敗'); return; }
            ctx.serial = res.serial;
        } catch (e) { toast('取編號失敗'); return; }
    }
    // 以 3 倍尺寸算圖再縮回，蓋大章也不糊；走 <img> 光柵化避免 fabric 解析 SVG 文字的相容性問題
    const hiSize = Math.max(300, size * 3);
    const svg = EGStampTpl.render(Object.assign({}, schema, { size: hiSize }), ctx);
    fabric.Image.fromURL('data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg), function (img) {
        if (!img || !img.width) { toast('圖章產生失敗'); return; }
        const sc = size / Math.max(img.width, img.height);
        img.set({ left: x, top: y, originX: 'center', originY: 'center', scaleX: sc, scaleY: sc, opacity: 0.92 });
        img.setCoords(); canvas.add(img); canvas.setActiveObject(img);
        canvas.requestRenderAll(); pushState();
    });
}

/* 部門印章權限初始化：無權者隱藏技術課章/發行章；管理者顯示設定按鈕
   模板章（含個人章/職稱章等，變數會帶入被登記者姓名部門）2026-08-12 起限管理者可用，
   避免非管理者任意挑選蓋上他人的個人章冒名簽署（後端 pick_meta/next_serial 同步擋，前端只是先隱藏） */
(function initStampPerm() {
    const sel = document.getElementById('p-stamp-type');
    if (!CAN_DEPT_STAMP) {
        Array.from(sel.options).slice().forEach(o => { if (o.value === 'tech' || o.value === 'issue') sel.removeChild(o); });
    }
    if (!IS_MGR) {
        const tplOpt = document.getElementById('opt-stamp-tpl');
        if (tplOpt) tplOpt.remove();
    }
    const dateEl = document.getElementById('p-stamp-date');
    if (dateEl) {
        const d = new Date(), p = n => String(n).padStart(2, '0');
        dateEl.value = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
        if (!IS_MGR) dateEl.disabled = true;   // 非管理者固定今天，不可改
    }
    if (IS_MGR) document.getElementById('btn-stamp-perm').style.display = '';
})();

/* 用章人員設定（管理者限定） */
let spData = null;
async function openStampPermModal() {
    showModal('stampperm-modal');
    document.getElementById('sp-users').innerHTML = '載入中…';
    try {
        const fd = new FormData(); fd.append('action', 'get_stamp_users');
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        spData = { depts: res.depts, users: res.users, selected: new Set((res.selected || []).map(Number)) };
        document.getElementById('sp-dept').innerHTML = res.depts.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
        document.getElementById('sp-color').value = (res.color === 'red') ? 'red' : 'blue';
        renderStampUsers();
    } catch (e) {
        document.getElementById('sp-users').innerHTML = '<span style="color:#ff8a80;">載入失敗：' + escHtml(e.message || '') + '</span>';
    }
}
function renderStampUsers() {
    if (!spData) return;
    const dept = parseInt(document.getElementById('sp-dept').value, 10);
    const list = spData.users.filter(u => u.department_id == dept);
    const box = document.getElementById('sp-users');
    if (!list.length) { box.innerHTML = '<span style="color:#8b949e;font-size:12px;">此部門沒有人員</span>'; return; }
    box.innerHTML = list.map(u =>
        '<label style="display:inline-flex;align-items:center;gap:5px;width:48%;margin:3px 0;font-size:12.5px;cursor:pointer;">' +
        '<input type="checkbox" data-uid="' + u.id + '"' + (spData.selected.has(Number(u.id)) ? ' checked' : '') +
        ' onchange="this.checked ? spData.selected.add(' + u.id + ') : spData.selected.delete(' + u.id + ')">' +
        escHtml(u.user_cname || ('#' + u.id)) + '</label>').join('');
}
async function saveStampUsers() {
    if (!spData) return;
    try {
        const fd = new FormData();
        fd.append('action', 'save_stamp_users');
        fd.append('user_ids', JSON.stringify(Array.from(spData.selected)));
        fd.append('color', document.getElementById('sp-color').value);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        deptStampColorHex = (res.color === 'red') ? '#cf3a2b' : '#2b4a9b';
        const colorWord = (res.color === 'red') ? '紅' : '藍';
        document.getElementById('opt-stamp-tech').textContent = '技術課章（' + colorWord + '）';
        document.getElementById('opt-stamp-issue').textContent = '發行章（' + colorWord + '）';
        hideModal('stampperm-modal');
        toast('已儲存用章人員名單（共 ' + res.count + ' 人）與印章顏色；被移除者重新開啟編輯器後生效');
    } catch (e) { toast('儲存失敗：' + (e.message || '')); }
}

/* ── 快速標籤①：球標（圓圈＋英文字母，自動接續編號） ─────────────────── */
function lettersToNum(s) {                       // A=1, B=2 ... Z=26, AA=27
    let n = 0;
    for (const ch of String(s).toUpperCase()) {
        const c = ch.charCodeAt(0);
        if (c < 65 || c > 90) return 0;
        n = n * 26 + (c - 64);
    }
    return n;
}
function numToLetters(n) {
    let s = '';
    while (n > 0) { n--; s = String.fromCharCode(65 + (n % 26)) + s; n = Math.floor(n / 26); }
    return s || 'A';
}
function balloonObjects() {
    return canvas.getObjects().filter(o => o.balloonLetter);
}
function nextBalloonLetter() {
    const used = balloonObjects().map(o => lettersToNum(o.balloonLetter)).filter(n => n > 0);
    if (used.length) return numToLetters(Math.max(...used) + 1);   // 接續畫布上既有球標
    const m = lettersToNum((document.getElementById('p-balloon-next') || {}).value);
    return m ? numToLetters(m) : 'A';                              // 尊重使用者手動起始字母
}
function makeBalloonGlyphItems(letter, size, cx, cy) {
    return [
        new fabric.Circle({ left: cx, top: cy, radius: size / 2, fill: '#ffffff', stroke: '#000000', strokeWidth: Math.max(1.5, size / 20), originX: 'center', originY: 'center' }),
        new fabric.Text(letter, { left: cx, top: cy, fontSize: size * 0.62, fontFamily: 'Arial', fontWeight: 'bold', fill: '#000000', originX: 'center', originY: 'center' })
    ];
}
function placeBalloon(x, y) {
    const letter = (document.getElementById('p-balloon-next').value || 'A').toUpperCase().replace(/[^A-Z]/g, '') || nextBalloonLetter();
    const size = Math.max(12, parseInt(document.getElementById('p-balloon-size').value, 10) || 40);
    const g = new fabric.Group(makeBalloonGlyphItems(letter, size, 0, 0), { left: x, top: y, originX: 'center', originY: 'center' });
    g.balloonLetter = letter;
    canvas.add(g);
    canvas.requestRenderAll();
    // 下一顆自動遞增（維持球標工具，連續點連續編）
    document.getElementById('p-balloon-next').value = numToLetters(lettersToNum(letter) + 1);
    updateBalloonSummary();
    pushState();
}
/* 右下角球標範圍：「Ⓐ～Ⓕ」——字母用真實圓圈球標樣式呈現（不帶「球標」字樣）。
   每次變動自動刪舊建新；使用者移動過的位置會沿用。 */
function updateBalloonSummary() {
    const old = canvas.getObjects().find(o => o.id === '__balloonSummary');
    const nums = balloonObjects().map(o => lettersToNum(o.balloonLetter)).filter(n => n > 0).sort((a, b) => a - b);
    if (!nums.length) { if (old) canvas.remove(old); canvas.requestRenderAll(); return; }
    const lo = numToLetters(nums[0]), hi = numToLetters(nums[nums.length - 1]);
    const s = 34, fs = 26;
    const items = []; let x = 0;
    const addTxt = str => {
        const t = new fabric.Text(str, { left: x, top: 0, originY: 'center', fontSize: fs, fontFamily: LABEL_FONT, fontWeight: 'bold', fill: '#000000', backgroundColor: '#ffffff' });
        items.push(t); x += t.width + 5;
    };
    const addGlyph = L => { items.push(...makeBalloonGlyphItems(L, s, x + s / 2, 0)); x += s + 5; };
    addGlyph(lo);
    if (hi !== lo) { addTxt('～'); addGlyph(hi); }
    let left, top;
    if (old) { left = old.left; top = old.top; canvas.remove(old); } // 已有 → 自動刪除重建，位置沿用
    else {
        left = artboard.left + artW - 14;   // 預設貼齊圖面右下角，可再拖移
        top = artboard.top + artH - 14;
    }
    const g = new fabric.Group(items, { left, top, originX: 'right', originY: 'bottom' });
    g.id = '__balloonSummary';
    canvas.add(g);
    canvas.requestRenderAll();
}

/* ── 快速標籤②：設變標示（菱形/三角形＋號碼；左上角自動設變列表） ────── */
function dcMarkObjects() {
    return canvas.getObjects().filter(o => o.dcNumber && o.dcRole === 'mark');
}
/* 存檔時抄一份「這次的設變說明」給圖面變更紀錄當變更摘要（使用者要求 2026-08-25）。
   取號碼**最大**那一組＝這次新加的；號碼小的是歷次設變的歷史紀錄，不該重複帶進這次的單。
   列表文字的格式是「日期  說明」（placeDcMark 建立時就填好日期），日期另有欄位故這裡去掉前綴。
   還沒雙擊填說明的（只有日期）會回傳空字串，讓摘要留白由使用者自己補，不硬塞一個日期進去。 */
function dcLatestNote() {
    const ts = canvas.getObjects().filter(o => o.dcRole === 'legendText' && o.dcNumber);
    if (!ts.length) return { num: 0, text: '' };
    const num = Math.max(...ts.map(o => o.dcNumber));
    const text = ts.filter(o => o.dcNumber === num)
        .map(o => String(o.text || '')
            .replace(/^\s*\d{4}[.\-\/]\d{1,2}[.\-\/]\d{1,2}\s*/, '')   // 去掉開頭的日期
            .replace(/\s+/g, ' ').trim())
        .filter(Boolean).join('　');
    return { num: num, text: text };
}
function makeDcSymbol(num, shape, size) {
    let s;
    if (shape === 'triangle') {
        s = new fabric.Triangle({ width: size * 1.12, height: size, fill: '#ffffff', stroke: '#000000', strokeWidth: Math.max(1.5, size / 20), originX: 'center', originY: 'center' });
    } else {
        s = new fabric.Rect({ width: size * 0.74, height: size * 0.74, angle: 45, fill: '#ffffff', stroke: '#000000', strokeWidth: Math.max(1.5, size / 20), originX: 'center', originY: 'center' });
    }
    const txt = new fabric.Text(String(num), {
        fontSize: size * 0.46, fontFamily: 'Arial', fontWeight: 'bold', fill: '#000000',
        originX: 'center', originY: 'center', top: shape === 'triangle' ? size * 0.14 : 0
    });
    return new fabric.Group([s, txt], { originX: 'center', originY: 'center' });
}
function todayStr() {
    const d = new Date(), p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '.' + p(d.getMonth() + 1) + '.' + p(d.getDate());
}
// 圖章上印的日期：一般使用者固定今天；管理者可在工具列調整（依單據業務日期補蓋章），2026-08-12 使用者要求
function stampDateStr() {
    const el = document.getElementById('p-stamp-date');
    if (el && el.value) { const [y, m, d] = el.value.split('-'); return y + '.' + m + '.' + d; }
    return todayStr();
}
function nextDcNumber() {
    const used = canvas.getObjects().filter(o => o.dcNumber).map(o => o.dcNumber);
    return used.length ? Math.max(...used) + 1 : 1;
}
function placeDcMark(x, y) {
    const shape = document.getElementById('p-dc-shape').value;
    const size = Math.max(12, parseInt(document.getElementById('p-dc-size').value, 10) || 40);
    // 同一次設變多處 → 每次點擊都用「號碼」欄的同一個數字；使用者可自行改起始號
    let num = parseInt(document.getElementById('p-dc-num').value, 10);
    if (!num || num < 1) { num = nextDcNumber(); document.getElementById('p-dc-num').value = num; }

    // 圖面上的標示（工具保持啟用，同號可連續點多處）
    const mark = makeDcSymbol(num, shape, size);
    mark.set({ left: x, top: y });
    mark.dcNumber = num; mark.dcShape = shape; mark.dcRole = 'mark';
    canvas.add(mark);

    // 左上角設變列表：該號碼第一次放置時才建立（標示＋今日日期＋雙擊可輸入文字）
    const hasLegend = canvas.getObjects().some(o => o.dcRole === 'legend' && o.dcNumber === num);
    if (!hasLegend) {
        const legendMark = makeDcSymbol(num, shape, size * 0.9);
        legendMark.dcNumber = num; legendMark.dcShape = shape; legendMark.dcRole = 'legend';
        const legendText = new fabric.IText(todayStr() + '  ', {
            fontSize: size * 0.5, fontFamily: '"Microsoft JhengHei", Arial, sans-serif',
            fill: '#000000', backgroundColor: '#ffffff', originY: 'center'
        });
        legendText.dcNumber = num; legendText.dcRole = 'legendText';
        canvas.add(legendMark); canvas.add(legendText);
        reflowDcLegend();
        toast('設變 ' + num + ' 已加入左上角列表（雙擊該列文字輸入說明）；同號可繼續點圖面加標示，下一次設變請改「號碼」欄');
    }
    canvas.requestRenderAll();
    pushState();
}
/* 設變列表排版：錨定圖面左上角，號碼越大（越新）越上面；新增時自動重新判定位置 */
function reflowDcLegend() {
    const marks = canvas.getObjects().filter(o => o.dcRole === 'legend').sort((a, b) => b.dcNumber - a.dcNumber);
    const texts = {};
    canvas.getObjects().filter(o => o.dcRole === 'legendText').forEach(o => texts[o.dcNumber] = o);
    const x0 = artboard.left + 16, y0 = artboard.top + 16;
    let y = y0;
    marks.forEach(m => {
        const h = m.getBoundingRect(true, true).height;
        m.set({ left: x0 + m.getBoundingRect(true, true).width / 2, top: y + h / 2, originX: 'center', originY: 'center' });
        m.setCoords();
        const t = texts[m.dcNumber];
        if (t) {
            t.set({ left: x0 + m.getBoundingRect(true, true).width + 10, top: y + h / 2, originY: 'center' });
            t.setCoords();
        }
        y += h + 8;
    });
}

/* ── 標籤庫：規格化標籤（雙擊改字、外框自動貼合、可存庫共用） ─────────── */
const LABEL_FONT = '"Microsoft JhengHei", "PingFang TC", Arial, sans-serif';
/* 內建常用標籤已改為「技術部」部門標籤（存在 imgedit_labels，owner_type=dept、owner_dept_id=1），
   由技術部成員在標籤庫「管理」跳窗維護；種子資料見 ai-rules/tools/imgedit_seed_builtin_labels.php。
   此處不再硬寫 PRESET_LABELS，標籤一律走 customLabels（DB）渲染。 */
let __labelInk = '#000000';   // makeLabelFromSpec 執行期間的文字色（spec.color）
function mkLabelText(str, fs, extra) {
    return new fabric.Text(str, Object.assign({
        fontSize: fs, fontFamily: LABEL_FONT, fontWeight: 'bold', fill: __labelInk
    }, extra || {}));
}
/* 數值格文字：多行時第 2 行起自動縮小（公差慣例：基準值大字、上下公差小字）。
   用 fabric 的 per-char styles 實作，仍是同一個文字物件，雙擊編輯/重建流程不受影響 */
function mkValText(str, fs, extra) {
    const t = mkLabelText(String(str), fs, extra);
    const lines = String(str).split('\n');
    if (lines.length > 1) {
        const small = Math.max(10, Math.round(fs * 0.75));
        const styles = {};
        for (let li = 1; li < lines.length; li++) {
            styles[li] = {};
            for (let ci = 0; ci < lines[li].length; ci++) styles[li][ci] = { fontSize: small };
        }
        t.styles = styles;
        t.initDimensions();
    }
    return t;
}
function makeLabelFromSpec(spec) {
    spec = JSON.parse(JSON.stringify(spec || {}));   // 表格空白格會就地補 vals/cellVals/body 預設值，先複製一份避免動到標籤庫共用的 spec
    __labelInk = spec.color || '#000000';
    const fs = spec.fontSize || 44;
    const bw = spec.strokeW || 4;
    const bgFill = specBg(spec, 'transparent', '#ffffff');   // 白底（預設）/透明/自訂色
    const items = [];
    if (spec.kind === 'plain') {
        items.push(mkLabelText(spec.text, fs, { textAlign: spec.align || 'center', backgroundColor: specBg(spec, '', '#ffffff'), specPath: 'text' }));
    } else if (spec.kind === 'box') {
        const t = mkLabelText(spec.text, fs, { textAlign: spec.align || 'center', originX: 'center', originY: 'center', left: 0, top: 0, specPath: 'text' });
        const pad = spec.pad != null ? spec.pad : fs * 0.32;
        items.push(new fabric.Rect({
            left: 0, top: 0, originX: 'center', originY: 'center',
            width: t.width + pad * 2, height: t.height + pad * 1.1,
            fill: bgFill, stroke: '#000000', strokeWidth: bw
        }));
        items.push(t);
    } else if (spec.kind === 'inline') {
        const pad = fs * 0.32, gap = fs * 0.35;
        const chunks = []; let x = 0;
        (spec.segs || []).forEach((s, i) => {
            const t = mkLabelText(s.t, fs, { left: x, top: 0, originY: 'center', specPath: 'segs.' + i });
            if (s.box) {
                chunks.push(new fabric.Rect({
                    left: x - fs * 0.14, top: -fs * 0.66, width: t.width + fs * 0.28, height: fs * 1.32,
                    fill: 'transparent', stroke: '#000000', strokeWidth: Math.max(2, bw - 1)
                }));
            }
            chunks.push(t);
            x += t.width + gap;
        });
        const total = x - gap;
        items.push(new fabric.Rect({
            left: -pad, top: -fs * 0.66 - pad * 0.55, width: total + pad * 2, height: fs * 1.32 + pad * 1.1,
            fill: bgFill, stroke: '#000000', strokeWidth: bw
        }));
        items.push(...chunks);
    } else if (spec.kind === 'grind3') {
        // 研磨記號：n 個倒三角形並排＋上方文字（預設 G）；文字雙擊可改
        const t = fs * 1.15, th = t * 0.866, n = Math.max(1, spec.count || 3);
        items.push(mkLabelText(spec.text != null ? spec.text : 'G', fs, {
            originX: 'center', originY: 'bottom', left: n * t / 2, top: -fs * 0.12,
            backgroundColor: specBg(spec, '', '#ffffff'), specPath: 'text'
        }));
        for (let i = 0; i < n; i++) {
            items.push(new fabric.Polygon(
                [{ x: i * t, y: 0 }, { x: (i + 1) * t, y: 0 }, { x: i * t + t / 2, y: th }],
                { fill: bgFill, stroke: __labelInk, strokeWidth: Math.max(2, bw * 0.75), strokeUniform: true, strokeLineJoin: 'round' }
            ));
        }
    } else if (spec.kind === 'rough') {
        // 表面粗糙度記號：倒三角＋斜線＋水平尾線；左側數值（0.8）與尾線上文字（G）雙擊都可改
        const t = fs * 1.15, th = t * 0.866, sw2 = Math.max(2, bw * 0.75);
        const B = { x: t, y: 0 }, C = { x: t / 2, y: th };
        const k = 2.1;   // 斜線＝三角形右邊沿 C→B 方向延伸
        const E = { x: C.x + (B.x - C.x) * k, y: C.y + (B.y - C.y) * k };
        const tail = t * 1.35;
        items.push(new fabric.Polygon([{ x: 0, y: 0 }, B, C],
            { fill: bgFill, stroke: __labelInk, strokeWidth: sw2, strokeUniform: true, strokeLineJoin: 'round' }));
        items.push(new fabric.Polyline([B, E, { x: E.x + tail, y: E.y }],
            { fill: 'transparent', stroke: __labelInk, strokeWidth: sw2, strokeUniform: true, strokeLineJoin: 'round' }));
        items.push(mkLabelText(spec.val != null ? spec.val : '0.8', fs * 0.85, {
            originX: 'right', originY: 'center', left: t * 0.95, top: -th * 0.5,
            backgroundColor: specBg(spec, '', '#ffffff'), specPath: 'val'
        }));
        items.push(mkLabelText(spec.text != null ? spec.text : 'G', fs, {
            originX: 'center', originY: 'bottom', left: E.x + tail * 0.5, top: E.y - fs * 0.08,
            backgroundColor: specBg(spec, '', '#ffffff'), specPath: 'text'
        }));
    } else if (spec.kind === 'table') {
        const rows = spec.rows || [];
        const cols = spec.cols || null;   // 雙欄式：title + 欄標題列 + 空白格（如 熱處理前置：防碳/鎖螺絲）
        const pad = fs * 0.4;
        // 空白格內建可填數值的文字（預設空字串）：雙擊空白格即可像公差標籤那樣填入數字，格子隨內容自動加寬加高
        if (cols && cols.length) spec.cellVals = cols.map((c, i) => (spec.cellVals || [])[i] || '');
        else if (rows.length) spec.vals = rows.map((r, i) => (spec.vals || [])[i] || '');
        else if (spec.body == null) spec.body = '';
        const titleT = mkLabelText(spec.title || '', fs, { specPath: 'title' });
        const th = fs * 1.7, rh = spec.rowH || fs * 2.8;
        if (cols && cols.length) {
            const valTs = cols.map((c, i) => mkValText(spec.cellVals[i], fs, { originX: 'center', originY: 'center', textAlign: 'center', specPath: 'cellVals.' + i }));
            const colW = Math.max(fs * 4, ...cols.map(c => mkLabelText(c, fs).width + pad * 2), ...valTs.map(t => t.width + pad * 2));
            const W = Math.max(titleT.width + pad * 2, colW * cols.length);
            const cw = W / cols.length;
            const bodyH = Math.max(spec.bodyH || fs * 2.6, ...valTs.map(t => t.height + pad));
            items.push(new fabric.Rect({ left: 0, top: 0, width: W, height: th, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            titleT.set({ left: W / 2, top: th / 2, originX: 'center', originY: 'center' });
            items.push(titleT);
            cols.forEach((c, i) => {
                items.push(new fabric.Rect({ left: i * cw, top: th, width: cw, height: rh * 0.7, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                items.push(mkLabelText(c, fs, { left: i * cw + cw / 2, top: th + rh * 0.35, originX: 'center', originY: 'center', specPath: 'cols.' + i }));
                items.push(new fabric.Rect({ left: i * cw, top: th + rh * 0.7, width: cw, height: bodyH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                valTs[i].set({ left: i * cw + cw / 2, top: th + rh * 0.7 + bodyH / 2 });
                items.push(valTs[i]);
            });
        } else if (!rows.length) {
            // 標題＋空白大格（如 (  )粗滾、(  )精滾、(  )插齒）；大格雙擊可填數值
            const bodyT = mkValText(spec.body, fs, { originX: 'center', originY: 'center', textAlign: 'center', specPath: 'body' });
            const W = Math.max(titleT.width + pad * 2, fs * 6, bodyT.width + pad * 2);
            const bodyH = Math.max(spec.bodyH || fs * 3.4, bodyT.height + pad);
            items.push(new fabric.Rect({ left: 0, top: 0, width: W, height: th, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            titleT.set({ left: W / 2, top: th / 2, originX: 'center', originY: 'center' });
            items.push(titleT);
            items.push(new fabric.Rect({ left: 0, top: th, width: W, height: bodyH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            bodyT.set({ left: W / 2, top: th + bodyH / 2 });
            items.push(bodyT);
        } else {
            const valTs = rows.map((r, i) => mkValText(spec.vals[i], fs, { originX: 'center', originY: 'center', textAlign: 'center', specPath: 'vals.' + i }));
            let col0 = Math.max(fs, ...rows.map(r => mkLabelText(r, fs).width)) + pad * 2;
            const col1Min = Math.max(spec.cellW || fs * 5.5, ...valTs.map(t => t.width + pad * 2));
            const rowH = Math.max(rh, ...valTs.map(t => t.height + pad));   // 多行數值（如上下公差）整列自動加高
            const W = Math.max(titleT.width + pad * 2, col0 + col1Min);
            const col1 = W - col0;
            items.push(new fabric.Rect({ left: 0, top: 0, width: W, height: th, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
            titleT.set({ left: W / 2, top: th / 2, originX: 'center', originY: 'center' });
            items.push(titleT);
            rows.forEach((r, i) => {
                const y = th + i * rowH;
                items.push(new fabric.Rect({ left: 0, top: y, width: col0, height: rowH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                items.push(new fabric.Rect({ left: col0, top: y, width: col1, height: rowH, fill: bgFill, stroke: '#000000', strokeWidth: bw }));
                items.push(mkLabelText(r, fs, { left: col0 / 2, top: y + rowH / 2, originX: 'center', originY: 'center', specPath: 'rows.' + i }));
                valTs[i].set({ left: col0 + col1 / 2, top: y + rowH / 2 });
                items.push(valTs[i]);
            });
        }
    }
    const g = new fabric.Group(items, {});
    g.labelSpec = JSON.parse(JSON.stringify(spec));
    g.labelKind = spec.kind;
    return g;
}
function setSpecByPath(spec, path, val) {
    const parts = String(path).split('.');
    let cur = spec;
    for (let i = 0; i < parts.length - 1; i++) cur = cur[parts[i]];
    const last = parts[parts.length - 1];
    if (cur[last] !== null && typeof cur[last] === 'object' && 't' in cur[last]) cur[last].t = val;
    else cur[last] = val;
}
function viewCenter() {
    const vpt = canvas.viewportTransform;
    return { x: (wrap.clientWidth / 2 - vpt[4]) / vpt[0], y: (wrap.clientHeight / 2 - vpt[5]) / vpt[3] };
}
function placeLabelObject(o) {
    // 尺寸算出 NaN 的物件一旦進畫布會毒化整個渲染迴圈（殘影、卡死），寧可不插入
    if (!o || !isFinite(o.width) || !isFinite(o.height)) { toast('標籤建立失敗（尺寸異常），未插入'); return; }
    const c = viewCenter();
    o.set({ originX: 'center', originY: 'center', left: c.x, top: c.y });
    o.setCoords();
    canvas.add(o);
    canvas.setActiveObject(o);
    setTool('select');
    canvas.requestRenderAll();
    pushState();
}
/* 標籤底色統一判讀：transparent→offVal；自訂色(#hex)→原色；white/未設定→defVal（預設白底） */
function specBg(spec, offVal, defVal) {
    if (spec.bg === 'transparent') return offVal;
    if (spec.bg && spec.bg !== 'white') return spec.bg;
    return defVal;
}
/* 在標籤物件最底層墊一塊底色矩形（自組 fabric 標籤用；矩形帶 isLabelBgRect 供之後切換底色辨識） */
function addLabelBgRect(o, bgFill) {
    const pad = 8;
    const rect = new fabric.Rect({ fill: bgFill, strokeWidth: 0 });
    rect.isLabelBgRect = true;
    if (o.type === 'group') {
        // 群組：以群組中心座標塞進最底層，寬高各外擴 pad（不用 addWithUpdate，座標已是群組相對值）
        rect.set({ left: -o.width / 2 - pad, top: -o.height / 2 - pad, width: o.width + pad * 2, height: o.height + pad * 2 });
        o.add(rect);
        o._objects.pop(); o._objects.unshift(rect);
        o.set({ width: o.width + pad * 2, height: o.height + pad * 2 });
        o.dirty = true;
        return o;
    }
    // 單一物件：量絕對邊界後包成群組
    o.set({ left: 0, top: 0, originX: 'left', originY: 'top' });
    o.setCoords();
    const br = o.getBoundingRect(true, true);
    rect.set({ left: br.left - pad, top: br.top - pad, width: br.width + pad * 2, height: br.height + pad * 2 });
    return new fabric.Group([rect, o], {});
}
/* 由 spec 建立標籤物件（統一入口；multi＝群組標籤：多個標籤直排組成一組） */
function buildLabelObject(spec, done) {
    if (spec.kind === 'image') {
        fabric.Image.fromURL(encodeURI(spec.url), function (img) {
            if (!img || !img.width) { done(null); return; }
            img.labelSpec = spec; img.labelKind = 'image';
            done(img);
        }, { crossOrigin: 'anonymous' });
        return;
    }
    if (spec.kind === 'fabric') {
        const j = JSON.parse(JSON.stringify(spec.json));
        if (j.type === 'activeSelection') j.type = 'group';
        fabric.util.enlivenObjects([j], function (objs) {
            if (!objs || !objs[0]) { done(null); return; }
            let o = objs[0];
            // 自組（fabric）標籤預設墊白底，插入時勾「以透明背景插入」或存標籤時選透明才不墊；也可存自訂色
            const bgFill = specBg(spec, '', '#ffffff');
            if (bgFill) o = addLabelBgRect(o, bgFill);
            o.labelSpec = spec; o.labelKind = 'fabric';
            done(o);
        });
        return;
    }
    if (spec.kind === 'tol') {
        // ^公差堆疊存成的標籤：makeLabelFromSpec 做不出來（會變空白標籤），要用 makeTolGroup 重建
        let g = null;
        try {
            g = makeTolGroup(spec.text, Object.assign({}, spec, { backgroundColor: specBg(spec, '', spec.backgroundColor || '#ffffff') }));
        } catch (e) { console.warn('[EGdraw] 公差標籤重建例外：', e); }
        done((g && isFinite(g.width) && isFinite(g.height)) ? g : null);
        return;
    }
    if (spec.kind === 'multi') {
        // 群組標籤：整組的底色設定（透明插入/自訂色）往下傳給各子標籤
        const parts = (spec.specs || []).map(p => {
            const q = JSON.parse(JSON.stringify(p));
            if (spec.bg && q.kind !== 'image') q.bg = spec.bg;
            return q;
        });
        const objs = [];
        const next = i => {
            if (i >= parts.length) {
                if (!objs.length) { done(null); return; }
                let y = 0;
                objs.forEach(o => {
                    o.set({ originX: 'left', originY: 'top', left: 0, top: y });
                    o.setCoords();
                    y += o.getScaledHeight() + 12;
                });
                const g = new fabric.Group(objs, {});
                g.labelSpec = spec; g.labelKind = 'multi';
                done(g);
                return;
            }
            buildLabelObject(parts[i], o => { if (o) objs.push(o); next(i + 1); });
        };
        next(0);
        return;
    }
    done(makeLabelFromSpec(spec));
}
function insertLabel(spec) {
    const s = JSON.parse(JSON.stringify(spec));
    const cb = document.getElementById('lib-transparent');
    if (cb && cb.checked && s.kind !== 'image') s.bg = 'transparent';   // 透明插入對自組(fabric)/群組(multi)標籤也生效
    buildLabelObject(s, o => {
        if (!o) { toast('標籤載入失敗'); return; }
        placeLabelObject(o);
    });
}
/* 選取中的標籤：底色切換（規格標籤＝白底⇄透明重建；自組fabric/快速標籤＝就地切換底色矩形，保留改過的字） */
function toggleLabelBg() {
    const g = canvas.getActiveObject();
    if (!g || !g.labelSpec || ['image', 'tol'].includes(g.labelSpec.kind)) { toast('請先選取一個標籤（公差文字請用屬性列「底色」欄）'); return; }
    if (g.labelSpec.kind === 'fabric' && g.type === 'group') {
        // 自組標籤/快速標籤：直接切換底色矩形，不重建（重建會把插入後改過的字洗掉）
        const onColor = specBg(g.labelSpec, '', '') || document.getElementById('p-textbg').value || '#ffffff';
        let bgRect = g.getObjects().find(o => o.isLabelBgRect) || (g.isQuickLabel ? g.getObjects().find(o => o.type === 'rect') : null);
        if (bgRect) {
            const nowOn = bgRect.fill && bgRect.fill !== 'transparent';
            bgRect.set('fill', nowOn ? 'transparent' : onColor);
            bgRect.dirty = true;
            g.labelSpec.bg = nowOn ? 'transparent' : (onColor === '#ffffff' ? 'white' : onColor);
        } else {
            // 以透明插入、沒有底色矩形：補墊一塊在最底層
            const pad = 8;
            const rect = new fabric.Rect({ left: -g.width / 2 - pad, top: -g.height / 2 - pad, width: g.width + pad * 2, height: g.height + pad * 2, fill: onColor, strokeWidth: 0 });
            rect.isLabelBgRect = true;
            g.add(rect);
            g._objects.pop(); g._objects.unshift(rect);
            g.set({ width: g.width + pad * 2, height: g.height + pad * 2 });
            g.labelSpec.bg = (onColor === '#ffffff') ? 'white' : onColor;
        }
        g.dirty = true;
        canvas.requestRenderAll();
        pushState();
        toast('標籤底色：' + (g.labelSpec.bg === 'transparent' ? '透明' : '有底色'));
        return;
    }
    const spec = JSON.parse(JSON.stringify(g.labelSpec));
    spec.bg = (spec.bg === 'transparent') ? 'white' : 'transparent';
    const center = g.getCenterPoint();
    const { scaleX, scaleY, angle } = g;
    buildLabelObject(spec, ng => {   // multi 群組標籤 makeLabelFromSpec 做不出來，統一走 buildLabelObject
        if (!ng || !isFinite(ng.width) || !isFinite(ng.height)) { toast('標籤重建失敗，底色未變更'); return; }
        canvas.remove(g);
        ng.set({ scaleX, scaleY, angle, originX: 'center', originY: 'center' });
        ng.setPositionByOrigin(center, 'center', 'center');
        ng.setCoords();
        canvas.add(ng);
        canvas.setActiveObject(ng);
        canvas.requestRenderAll();
        pushState();
        toast('標籤底色：' + (spec.bg === 'transparent' ? '透明' : '白底'));
    });
}

/* 標籤庫面板（分類顯示＋篩選） */
let libLoaded = false, customLabels = [];
function toggleLabelLib() {
    const el = document.getElementById('label-lib');
    el.classList.toggle('show');
    if (el.classList.contains('show') && !libLoaded) { loadLabelLibrary(); libLoaded = true; }
}
/* 側欄寬度：拖曳左緣調整，記住每台電腦的偏好 */
(function initLibResizer() {
    const panel = document.getElementById('label-lib');
    const saved = parseInt(localStorage.getItem('eg_imgedit_lib_w') || '0', 10);
    if (saved >= 220) panel.style.width = Math.min(saved, window.innerWidth * 0.7) + 'px';
    const rz = document.getElementById('lib-resizer');
    rz.addEventListener('mousedown', function (e) {
        e.preventDefault();
        rz.classList.add('active');
        const move = ev => {
            let w = wrap.getBoundingClientRect().right - ev.clientX;   // 面板右緣固定貼齊畫布右緣
            w = Math.max(220, Math.min(window.innerWidth * 0.7, w));
            panel.style.width = w + 'px';
        };
        const up = () => {
            rz.classList.remove('active');
            localStorage.setItem('eg_imgedit_lib_w', String(Math.round(panel.getBoundingClientRect().width)));
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    });
})();
function labelThumbHTML(dataURL, name, delId) {
    return '<img src="' + (dataURL || '') + '" alt=""><span class="lib-name">' + escHtml(name) + '</span>' +
        (delId ? '<span class="lib-del" title="刪除這個自訂標籤" onclick="event.stopPropagation();deleteCustomLabel(' + delId + ')"><i class="fa fa-trash"></i></span>' : '');
}
function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
/* #標示：縮圖左上角有底色小徽章（面板與管理跳窗共用） */
function tagChipsHTML(tags) {
    // 一個標籤可以有多組 #（上限 TAGS_MAX）。縮圖只有 104px 寬，全部攤開會蓋掉圖，
    // 所以固定顯示前 3 個、其餘收成灰底「+N」；搜尋比對的是完整字串，收起來的一樣搜得到。
    const all = String(tags || '').trim().split(/\s+/).filter(Boolean);
    if (!all.length) return '';
    const shown = all.slice(0, 3), rest = all.length - shown.length;
    return '<span class="lib-tags" title="#' + escHtml(all.join(' #')) + '">'
        + shown.map(t => '<span class="lib-tag">#' + escHtml(t) + '</span>').join('')
        + (rest > 0 ? '<span class="lib-tag more">+' + rest + '</span>' : '')
        + '</span>';
}
/* #標示輸入的共用檢查（唯一實作，三個輸入欄共用；規則與後端 $parseLabelTags 完全一致＝鐵律8
   前端即時擋一次、後端同規則再擋一次，兩邊都會把「為什麼錯」講出來） */
const TAGS_MAX = 10, TAG_LEN_MAX = 12, TAGS_TOTAL_MAX = 100;
function parseTagsInput(raw) {
    const arr = [];
    String(raw || '').split(/[\s,，#]+/).forEach(t => { if (t && arr.indexOf(t) < 0) arr.push(t); });
    const joined = arr.join(' ');
    let err = '';
    if (arr.length > TAGS_MAX) err = '#標示最多 ' + TAGS_MAX + ' 個，目前有 ' + arr.length + ' 個，請刪掉 ' + (arr.length - TAGS_MAX) + ' 個';
    else {
        const long = arr.find(t => t.length > TAG_LEN_MAX);
        if (long) err = '單一個 #標示最多 ' + TAG_LEN_MAX + ' 個字，「' + long + '」太長了';
        else if (joined.length > TAGS_TOTAL_MAX) err = '#標示總長度最多 ' + TAGS_TOTAL_MAX + ' 個字（目前 ' + joined.length + '），請縮短或減少個數';
    }
    return { list: arr, value: joined, err: err };
}
function bindTagsInput(inputId, hintId) {
    const el = document.getElementById(inputId), hint = document.getElementById(hintId);
    if (!el || !hint) return;
    const run = () => {
        const r = parseTagsInput(el.value);
        el.style.borderColor = r.err ? '#dd5138' : '';
        hint.className = 'tag-hint' + (r.err ? '' : ' okmsg');
        hint.textContent = r.err ? r.err : (r.list.length ? '已輸入 ' + r.list.length + ' 個：#' + r.list.join(' #') : '');
    };
    el.addEventListener('input', run);
    el.addEventListener('change', run);
    el._egTagsCheck = run;
}
/* 送出前再擋一次：回傳 true＝有錯（已把原因顯示在欄位下方並聚焦） */
function tagsInputBlocked(inputId) {
    const el = document.getElementById(inputId);
    if (!el) return false;
    const r = parseTagsInput(el.value);
    if (el._egTagsCheck) el._egTagsCheck();
    if (r.err) { el.focus(); toast(r.err); return true; }
    return false;
}
['sl-tags', 'nl-tags', 'st-tags'].forEach(id => bindTagsInput(id, id + '-hint'));
function allTags() {
    const set = new Set();
    customLabels.forEach(r => String(r.tags || '').trim().split(/\s+/).filter(Boolean).forEach(t => set.add(t)));
    return Array.from(set);
}
function allCategories() {
    const cats = [];
    customLabels.forEach(r => { const c = r.category || '未分類'; if (!cats.includes(c)) cats.push(c); });
    return cats;
}
function refreshCatControls() {
    const sel = document.getElementById('lib-cat-filter');
    const keep = sel.value;
    sel.innerHTML = '<option value="">— 全部分類 —</option>' +
        allCategories().map(c => '<option value="' + escHtml(c) + '">' + escHtml(c) + '</option>').join('');
    if (Array.from(sel.options).some(o => o.value === keep)) sel.value = keep;
    document.getElementById('lib-cat-datalist').innerHTML =
        allCategories().map(c => '<option value="' + escHtml(c) + '">').join('');
    document.getElementById('lib-tag-datalist').innerHTML =
        allTags().map(t => '<option value="' + escHtml(t) + '">').join('');
}
/* ── 標籤管理跳窗：框選 / Ctrl 多選 / 拖曳搬移（Ctrl+拖曳＝複製） ── */
let LIB_UID = 0, ALL_DEPTS = [];
const lmSel = new Set();
async function openLibMgr() {
    lmSel.clear();
    lmAnchor = null; lmAnchorGrid = null;
    showModal('libmgr-modal');
    document.getElementById('libmgr-body').innerHTML = '<div style="color:#8b949e;padding:20px;">載入中…</div>';
    await loadLabelLibrary();   // 取最新資料（含部門/權限資訊）
    lmInitChips();
    renderLibMgr();
    if (!MY_DEPTS.length && !IS_MGR) toast('提醒：你的帳號（' + (USER_CNAME || '') + '，ID ' + LIB_UID + '）在人員部門對應表查無部門，故沒有部門欄可放；請把此訊息回報管理者');
}
function lmIsOwn(row) { return IS_MGR || Number(row.owner_user_id) === Number(LIB_UID); }
function lmCanSee(row) {
    return lmIsOwn(row)
        || row.owner_type === 'company'
        || (row.owner_type === 'dept' && MY_DEPTS.some(d => Number(d.id) === Number(row.owner_dept_id)));
}
// 拖曳權限：看得到就能拖（非本人的公司/部門標籤一律以「複製」語意處理，見 drop 判斷；搬移仍限本人）
function lmCanTouch(row) { return lmCanSee(row); }
/* 部門晶片：單一部門欄，頂部列出使用者的部門按鈕（可複選）。
   拖進部門欄＝同時發佈到所有點亮的部門；顯示內容也跟著點亮的部門過濾。 */
let lmDeptChips = [];
let lmAnchor = null, lmAnchorGrid = null;   // Shift 範圍選取的錨點
function lmInitChips() {
    lmDeptChips = MY_DEPTS.map(d => ({ id: Number(d.id), name: d.name, on: true }));
    // 管理者：把「已有部門標籤」的其他部門也列為晶片（預設不點亮）
    if (IS_MGR) {
        ALL_DEPTS.forEach(d => {
            if (!lmDeptChips.some(c => c.id === Number(d.id)) &&
                customLabels.some(r => r.owner_type === 'dept' && Number(r.owner_dept_id) === Number(d.id))) {
                lmDeptChips.push({ id: Number(d.id), name: d.name, on: false });
            }
        });
    }
}
function lmActiveDepts() { return lmDeptChips.filter(c => c.on).map(c => c.id); }
function lmToggleChip(id) {
    const c = lmDeptChips.find(c => c.id === id);
    if (c) c.on = !c.on;
    renderLibMgr();
}
function lmAddDeptChip(sel) {
    const id = parseInt(sel.value, 10);
    if (!id) return;
    if (!lmDeptChips.some(c => c.id === id)) {
        const d = ALL_DEPTS.find(d => Number(d.id) === id);
        if (d) lmDeptChips.push({ id, name: d.name, on: true });
    } else lmDeptChips.find(c => c.id === id).on = true;
    renderLibMgr();
}
function lmCols() {
    return [
        { scope: 'private', title: '🔒 私人標籤', color: '#b39ddb' },
        { scope: 'dept',    title: '👥 部門標籤', color: '#1abb9c' },
        { scope: 'company', title: '🏢 公司共用' + (IS_MGR ? '' : '（可放入，僅管理者可刪）'), color: '#e67e22' }
    ];
}
function lmUpdateCount() { document.getElementById('lm-sel-count').textContent = lmSel.size; }
function renderLibMgr() {
    const body = document.getElementById('libmgr-body');
    // 記住各欄捲動位置：設定分類/#標示/隱藏名稱等操作後重繪，不要跳回最上面
    const scrollPos = {};
    body.querySelectorAll('.lm-col').forEach(col => {
        const g = col.querySelector('.lm-grid');
        if (g) scrollPos[col.dataset.scope] = g.scrollTop;
    });
    body.innerHTML = '';
    lmCols().forEach(col => {
        const el = document.createElement('div');
        el.className = 'lm-col';
        el.dataset.scope = col.scope;
        let head = '<span style="color:' + col.color + ';">' + escHtml(col.title) + '</span>';
        if (col.scope === 'dept') {
            // 部門切換鈕（可複選）：拖進此欄＝同時發佈到所有點亮的部門
            head += '<span style="display:flex;flex-wrap:wrap;gap:4px;margin-left:4px;">'
                + lmDeptChips.map(c => '<span class="lm-chip' + (c.on ? ' on' : '') + '" onclick="lmToggleChip(' + c.id + ')" title="點擊切換；拖進此欄會同時放到所有亮起的部門">' + escHtml(c.name) + '</span>').join('')
                + '</span>';
            if (IS_MGR && ALL_DEPTS.length) {
                head += '<select onchange="lmAddDeptChip(this)" onclick="event.stopPropagation()" style="background:#14161a;border:1px solid #45494f;color:#8b949e;border-radius:3px;font-size:11px;padding:1px;max-width:70px;">'
                    + '<option value="">＋部門</option>'
                    + ALL_DEPTS.filter(d => !lmDeptChips.some(c => c.id === Number(d.id))).map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('')
                    + '</select>';
            }
        }
        el.innerHTML = '<div class="lm-col-head" style="flex-wrap:wrap;">' + head + '</div>';
        const grid = document.createElement('div');
        grid.className = 'lm-grid';
        // 欄內容
        let rows = [];
        if (col.scope === 'private') rows = customLabels.filter(r => r.owner_type === 'private');
        else if (col.scope === 'company') rows = customLabels.filter(r => r.owner_type === 'company');
        else if (col.scope === 'dept') {
            const act = lmActiveDepts();
            rows = customLabels.filter(r => r.owner_type === 'dept' && act.includes(Number(r.owner_dept_id)));
        }
        rows.sort((a, b) => String(a.category || '').localeCompare(String(b.category || ''))
            || String(a.label_name || '').localeCompare(String(b.label_name || '')));
        let lastCat = null;
        rows.forEach(row => {
            // 分類標題列：一眼看出哪些標籤屬於哪一類；點標題＝整組選取（Ctrl+點＝保留原選取加減這一組）
            const catName = row.category || '未分類';
            if (catName !== lastCat) {
                lastCat = catName;
                const groupIds = rows.filter(r => (r.category || '未分類') === catName).map(r => r.label_id);
                const ch = document.createElement('div');
                ch.className = 'lm-cat-head';
                ch.textContent = '▸ ' + catName + '（' + groupIds.length + '）';
                ch.title = '點擊＝選取這一分類的全部標籤（已全選時再點＝取消）；Ctrl+點擊＝保留原選取再加減這一組';
                ch.addEventListener('click', e => {
                    e.stopPropagation();
                    const allIn = groupIds.every(id => lmSel.has(id));
                    if (!(e.ctrlKey || e.metaKey)) lmSel.clear();
                    if (allIn) groupIds.forEach(id => lmSel.delete(id));
                    else groupIds.forEach(id => lmSel.add(id));
                    lmAnchor = null; lmAnchorGrid = null;
                    syncLmSelClass(); lmUpdateCount();
                });
                grid.appendChild(ch);
            }
            const it = document.createElement('div');
            it.className = 'lm-item' + (lmSel.has(row.label_id) ? ' sel' : '') + (lmCanTouch(row) ? '' : ' lock');
            it.style.position = 'relative';
            it.dataset.id = row.label_id;
            it.title = row.label_name + (row.category ? '（' + row.category + '）' : '')
                + (row.tags ? '｜#' + String(row.tags).trim().split(/\s+/).join(' #') : '')
                + (lmCanTouch(row) ? '' : '｜他人建立，無法移動');
            it.innerHTML = '<img alt=""><span class="lm-name"' + (Number(row.hide_name) ? ' style="color:#bbb;font-style:italic;"' : '') + '>'
                + escHtml(row.label_name) + (Number(row.hide_name) ? ' <i class="fa fa-eye-slash"></i>' : '') + '</span>'
                + (col.scope === 'dept' && row.dept_name ? '<span class="lm-dept-badge">' + escHtml(row.dept_name) + '</span>' : '')
                + tagChipsHTML(row.tags);
            makeSpecThumb(row.spec, url => { const img = it.querySelector('img'); if (img && url) img.src = url; });
            it.draggable = lmCanTouch(row);
            it.addEventListener('click', e => {
                e.stopPropagation();
                const id = row.label_id;
                if (e.shiftKey && lmAnchor != null && lmAnchorGrid === grid) {
                    // Shift＝從錨點到此的範圍選取（同一欄內）；加 Ctrl 則保留原選取
                    const items = Array.from(grid.querySelectorAll('.lm-item'));
                    const i1 = items.findIndex(x => parseInt(x.dataset.id, 10) === lmAnchor);
                    const i2 = items.findIndex(x => parseInt(x.dataset.id, 10) === id);
                    if (i1 >= 0 && i2 >= 0) {
                        if (!(e.ctrlKey || e.metaKey)) lmSel.clear();
                        const a = Math.min(i1, i2), b = Math.max(i1, i2);
                        for (let k = a; k <= b; k++) lmSel.add(parseInt(items[k].dataset.id, 10));
                    }
                } else if (e.ctrlKey || e.metaKey) {
                    lmSel.has(id) ? lmSel.delete(id) : lmSel.add(id);
                    lmAnchor = id; lmAnchorGrid = grid;
                } else {
                    lmSel.clear(); lmSel.add(id);
                    lmAnchor = id; lmAnchorGrid = grid;
                }
                syncLmSelClass(); lmUpdateCount();
            });
            it.addEventListener('dragstart', e => {
                if (!lmSel.has(row.label_id)) { lmSel.clear(); lmSel.add(row.label_id); syncLmSelClass(); lmUpdateCount(); }
                e.dataTransfer.setData('text/plain', 'eg-labels');
                e.dataTransfer.effectAllowed = 'copyMove';
            });
            grid.appendChild(it);
        });
        if (!rows.length) grid.innerHTML = '<div style="color:#555;font-size:11px;padding:6px;">（空）拖曳標籤到這裡</div>';
        // 放置目標
        el.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = e.ctrlKey ? 'copy' : 'move';
            el.classList.add('dragover');
        });
        el.addEventListener('dragleave', () => el.classList.remove('dragover'));
        el.addEventListener('drop', e => {
            e.preventDefault();
            el.classList.remove('dragover');
            const scope = col.scope;
            let deptIds = [];
            if (scope === 'dept') {
                deptIds = lmActiveDepts();
                if (!deptIds.length) { toast('請先點亮至少一個部門按鈕（部門欄頂部）'); return; }
            }
            // 選取中若含非本人擁有的標籤（公司共用／別人建立的部門標籤，如技術部內建）→ 強制以複製處理，
            // 不會動到原標籤（搬移只對自己的標籤有效，否則後端會略過，造成「拖了沒反應」的錯覺）
            const notOwned = Array.from(lmSel).some(id => {
                const r = customLabels.find(x => Number(x.label_id) === Number(id));
                return r && !lmIsOwn(r);
            });
            lmMove(scope, deptIds, (e.ctrlKey || notOwned) ? 'copy' : 'move');
        });
        // 框選（滑鼠在欄內空白處拖出選取框）
        grid.addEventListener('mousedown', e => {
            if (e.target.closest('.lm-item, .lm-cat-head') || e.button !== 0) return;
            const keep = e.ctrlKey || e.metaKey;
            const rub = document.getElementById('lm-rubber');
            const sx = e.clientX, sy = e.clientY;
            rub.style.display = 'block';
            const move = ev => {
                const x = Math.min(sx, ev.clientX), y = Math.min(sy, ev.clientY);
                const w = Math.abs(ev.clientX - sx), h = Math.abs(ev.clientY - sy);
                Object.assign(rub.style, { left: x + 'px', top: y + 'px', width: w + 'px', height: h + 'px' });
                if (!keep) lmSel.clear();
                grid.querySelectorAll('.lm-item').forEach(it => {
                    const r = it.getBoundingClientRect();
                    const hit = !(r.right < x || r.left > x + w || r.bottom < y || r.top > y + h);
                    if (hit) lmSel.add(parseInt(it.dataset.id, 10));
                });
                syncLmSelClass(); lmUpdateCount();
            };
            const up = () => {
                rub.style.display = 'none'; rub.style.width = '0'; rub.style.height = '0';
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            };
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
            e.preventDefault();
        });
        el.appendChild(grid);
        body.appendChild(el);
    });
    // 還原各欄捲動位置
    body.querySelectorAll('.lm-col').forEach(col => {
        const g = col.querySelector('.lm-grid');
        if (g && scrollPos[col.dataset.scope]) g.scrollTop = scrollPos[col.dataset.scope];
    });
    syncLmSelClass(); lmUpdateCount();
}
function syncLmSelClass() {
    document.querySelectorAll('#libmgr-body .lm-item').forEach(it =>
        it.classList.toggle('sel', lmSel.has(parseInt(it.dataset.id, 10))));
}
async function lmMove(scope, deptIds, mode) {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'move_labels');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('mode', mode);
        fd.append('scope', scope);
        fd.append('dept_ids', JSON.stringify(deptIds || []));
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        toast((mode === 'copy' ? '已複製 ' : '已搬移 ') + res.count + ' 個標籤');
        lmSel.clear();
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('執行失敗：' + (e.message || '')); }
}
/* 組成群組標籤：選取的標籤 spec 打包成 multi，存回標籤庫（點一下整組插入） */
// 切換「存標籤」跳窗的範圍區塊：一般存標籤＝可選範圍；非管理者組群組＝鎖定私人（綁定使用者，不影響別人）
function setSaveLabelGroupMode(isGroupByNonMgr) {
    document.getElementById('sl-scope-row').style.display  = isGroupByNonMgr ? 'none' : '';
    document.getElementById('sl-scope-hint').style.display = isGroupByNonMgr ? 'none' : '';
    document.getElementById('sl-group-hint').style.display = isGroupByNonMgr ? '' : 'none';
}
function lmMakeGroupLabel() {
    if (lmSel.size < 2) { toast('請先選取兩個以上的標籤'); return; }
    const rows = customLabels.filter(r => lmSel.has(r.label_id));   // 依顯示順序
    if (!rows.length) return;
    pendingLabelSpec = { kind: 'multi', specs: rows.map(r => JSON.parse(JSON.stringify(r.spec))) };
    syncSlBg('');   // 群組標籤背景預設白底（同各子標籤預設），要整組透明/自訂色再自行選
    document.getElementById('sl-name').value = '';
    document.getElementById('sl-cat').value = '群組';
    document.getElementById('sl-tags').value = '';
    document.getElementById('sl-scope').value = 'private';
    const sd = document.getElementById('sl-dept');
    sd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    sd.style.display = 'none';
    setSaveLabelGroupMode(!IS_MGR);   // 非管理者：群組標籤鎖定私人
    showModal('savelabel-modal');
    document.getElementById('sl-name').focus();
}
/* 批次設定分類 */
function lmOpenSetCat() {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    document.getElementById('sc-cat').value = '';
    showModal('setcat-modal');
    document.getElementById('sc-cat').focus();
}
async function confirmSetCat() {
    const cat = document.getElementById('sc-cat').value.trim();
    try {
        const fd = new FormData();
        fd.append('action', 'set_label_category');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('category', cat);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        hideModal('setcat-modal');
        toast('已把 ' + res.count + ' 個標籤設為分類「' + (cat || '未分類') + '」');
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('設定失敗：' + (e.message || '')); }
}
/* 批次設定 #標示 */
function lmOpenSetTags() {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    let pre = '';
    if (lmSel.size === 1) {   // 只選一個時帶入現值方便修改
        const r = customLabels.find(r => lmSel.has(r.label_id));
        pre = (r && r.tags) ? r.tags : '';
    }
    document.getElementById('st-tags').value = pre;
    showModal('settags-modal');
    document.getElementById('st-tags').focus();
}
async function confirmSetTags() {
    if (tagsInputBlocked('st-tags')) return;
    const tags = document.getElementById('st-tags').value.trim();
    try {
        const fd = new FormData();
        fd.append('action', 'set_label_tags');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('tags', tags);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        hideModal('settags-modal');
        toast(tags ? ('已把 ' + res.count + ' 個標籤設定#標示「' + tags + '」') : ('已清除 ' + res.count + ' 個標籤的#標示'));
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('設定失敗：' + (e.message || '')); }
}
async function lmSetHideName(hide) {
    if (!lmSel.size) { toast('請先選取標籤'); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'set_label_flag');
        fd.append('label_ids', JSON.stringify(Array.from(lmSel)));
        fd.append('hide_name', hide);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        toast((hide ? '已設定 ' : '已恢復 ') + res.count + ' 個標籤' + (hide ? '不顯示名稱' : '顯示名稱'));
        await loadLabelLibrary();
        renderLibMgr();
    } catch (e) { toast('設定失敗：' + (e.message || '')); }
}
async function lmDeleteSelected() {
    if (!lmSel.size) { toast('請先選取要刪除的標籤'); return; }
    if (!confirm('確定刪除選取的 ' + lmSel.size + ' 個標籤？')) return;
    let ok = 0, fail = 0;
    for (const id of Array.from(lmSel)) {
        try {
            const fd = new FormData();
            fd.append('action', 'delete_label'); fd.append('label_id', id);
            const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
            res.success ? ok++ : fail++;
        } catch (e) { fail++; }
    }
    toast('已刪除 ' + ok + ' 個' + (fail ? '；' + fail + ' 個無權限或失敗' : ''));
    lmSel.clear();
    await loadLabelLibrary();
    renderLibMgr();
}

/* 公司/部門標籤區塊顯示開關：只影響自己看到的標籤庫（依使用者存在瀏覽器 localStorage，別人不受影響） */
const SCOPE_SHOW_KEY = 'egdraw_scope_show_' + USER_ID;
let scopeShow = { company: true, dept: true };
try { Object.assign(scopeShow, JSON.parse(localStorage.getItem(SCOPE_SHOW_KEY) || '{}')); } catch (e) { }
function scopeShown(key) { return (key in scopeShow) ? !!scopeShow[key] : true; }
function setScopeShow(key, on) {
    scopeShow[key] = !!on;
    try { localStorage.setItem(SCOPE_SHOW_KEY, JSON.stringify(scopeShow)); } catch (e) { }
    renderLibrary();
}
(function initScopeShowUI() {
    const c = document.getElementById('lib-show-company'), d = document.getElementById('lib-show-dept');
    if (c) c.checked = scopeShown('company');
    if (d) d.checked = scopeShown('dept');
})();
function renderLibrary() {
    const filter = document.getElementById('lib-cat-filter').value;
    // 模糊搜尋：名稱/#標示/分類都比對；「#xx」只比對#標示；空格分隔多關鍵字＝全部都要符合
    const q = (document.getElementById('lib-search').value || '').trim().toLowerCase();
    const words = q ? q.split(/\s+/) : [];
    const hitQ = hay => !words.length || words.every(w => hay.includes(w));
    const tagHay = tags => String(tags || '').trim().split(/\s+/).filter(Boolean).map(t => '#' + t).join(' ');
    // 標籤：先分範圍（公司共用/部門/私人），範圍內再依分類分組（原「內建標籤」已改為技術部部門標籤，一併走這裡）
    const cbox = document.getElementById('lib-customs');
    cbox.innerHTML = '';
    const SCOPES = [
        { key: 'company', title: '🏢 公司共用', color: '#e67e22' },
        { key: 'dept',    title: '👥 部門標籤', color: '#1abb9c' },
        { key: 'private', title: '🔒 私人標籤', color: '#b39ddb' }
    ];
    SCOPES.filter(sc => scopeShown(sc.key)).forEach(sc => {
        const rows = customLabels.filter(r => (r.owner_type || 'company') === sc.key
            && (!filter || (r.category || '未分類') === filter)
            && hitQ((r.label_name + ' ' + (r.category || '') + ' ' + tagHay(r.tags)).toLowerCase()));
        if (!rows.length) return;
        const sh = document.createElement('div');
        sh.className = 'lib-sec';
        sh.style.cssText = 'font-weight:700;color:' + sc.color + ';margin-top:10px;';
        sh.textContent = sc.title;
        cbox.appendChild(sh);
        const cgroups = {};
        rows.forEach(r => { const c = r.category || '未分類'; (cgroups[c] = cgroups[c] || []).push(r); });
        Object.keys(cgroups).forEach(cat => {
            const h = document.createElement('div'); h.className = 'lib-sec'; h.textContent = '▸ ' + cat;
            cbox.appendChild(h);
            cgroups[cat].forEach(row => {
                const div = document.createElement('div');
                div.className = 'lib-item';
                const suffix = (sc.key === 'dept' && row.dept_name) ? '【' + row.dept_name + '】' : '';
                const fullName = row.label_name + suffix + (row.created_by ? '（' + row.created_by + '）' : '');
                // hide_name＝縮圖即內容，名稱不重複顯示（滑鼠停留仍看得到）
                div.innerHTML = (Number(row.hide_name) ? '<img alt="">' : labelThumbHTML('', fullName, row.label_id)) + tagChipsHTML(row.tags);
                div.title = fullName + (row.tags ? '｜#' + String(row.tags).trim().split(/\s+/).join(' #') : '');
                div.onclick = () => insertLabel(row.spec);
                cbox.appendChild(div);
                makeSpecThumb(row.spec, url => { const img = div.querySelector('img'); if (img && url) img.src = url; });
            });
        });
    });
    if (!cbox.children.length)
        cbox.innerHTML = '<div style="color:#666;font-size:11px;padding:6px;">' + ((filter || q) ? '沒有符合篩選/搜尋的自訂標籤' : '尚無自訂標籤。選取畫布上的物件後按下方「把選取存為標籤」。') + '</div>';
}
async function loadLabelLibrary() {
    try {
        const fd = new FormData(); fd.append('action', 'list_labels');
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        customLabels = [];
        res.labels.forEach(row => {
            try { row.spec = JSON.parse(row.spec_json); row.label_id = Number(row.label_id); customLabels.push(row); } catch (e) { /* 略過壞資料 */ }
        });
        LIB_UID = Number(res.uid || 0);
        ALL_DEPTS = res.all_depts || [];
        if (Array.isArray(res.my_depts)) {   // 以後端最新回傳為準（修正頁面載入時的舊資料）
            MY_DEPTS.length = 0;
            res.my_depts.forEach(d => MY_DEPTS.push(d));
        }
        refreshCatControls();
        renderLibrary();
    } catch (e) {
        refreshCatControls();
        renderLibrary();
        document.getElementById('lib-customs').innerHTML =
            '<div style="color:#c0392b;font-size:11px;padding:6px;">標籤庫載入失敗：' + escHtml(e.message || '') + '</div>';
    }
}
function makeSpecThumb(spec, cb) {
    if (spec.kind === 'image') { cb(encodeURI(spec.url)); return; }   // 縮圖直接用原圖
    buildLabelObject(JSON.parse(JSON.stringify(spec)), g => {
        if (!g) { cb(null); return; }
        try { cb(g.toDataURL({ format: 'png', multiplier: Math.min(1, 210 / g.width, 100 / g.height) })); } catch (e) { cb(null); }
    });
}
let pendingLabelSpec = null;
function saveSelectionAsLabel() {
    const obj = canvas.getActiveObject();
    if (!obj) { toast('請先在畫布上選取要存成標籤的物件（可框選多個）'); return; }
    if (obj.labelSpec && obj.labelSpec.kind !== 'fabric') pendingLabelSpec = JSON.parse(JSON.stringify(obj.labelSpec));
    else {
        const j = obj.toObject(SNAP_PROPS);
        if (j.type === 'activeSelection') j.type = 'group';
        pendingLabelSpec = { kind: 'fabric', json: j };
    }
    syncSlBg(pendingLabelSpec.bg);
    document.getElementById('sl-name').value = '';
    document.getElementById('sl-cat').value = document.getElementById('lib-cat-filter').value || '';
    document.getElementById('sl-tags').value = '';
    // 範圍：預設私人；部門下拉帶自己所屬部門
    document.getElementById('sl-scope').value = 'private';
    const sd = document.getElementById('sl-dept');
    sd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    sd.style.display = 'none';
    setSaveLabelGroupMode(false);   // 一般存標籤：範圍可選（還原群組模式可能隱藏的區塊）
    showModal('savelabel-modal');
    document.getElementById('sl-name').focus();
}
/* 存標籤跳窗「背景」選項回填：white/transparent/自訂色（undefined 視為白底預設） */
function syncSlBg(bg) {
    const sel = document.getElementById('sl-bg'), clr = document.getElementById('sl-bg-color');
    sel.value = (bg === 'transparent') ? 'transparent' : ((bg && bg !== 'white') ? 'custom' : 'white');
    clr.value = /^#[0-9a-fA-F]{6}$/.test(bg || '') ? bg : '#ffffff';
    clr.style.display = (sel.value === 'custom') ? '' : 'none';
}
async function confirmSaveLabel() {
    const name = document.getElementById('sl-name').value.trim();
    const cat = document.getElementById('sl-cat').value.trim();
    if (!name) { toast('請輸入標籤名稱'); return; }
    if (tagsInputBlocked('sl-tags')) return;
    if (!pendingLabelSpec) { hideModal('savelabel-modal'); return; }
    if (pendingLabelSpec.kind !== 'image') {
        const bgSel = document.getElementById('sl-bg').value;
        pendingLabelSpec.bg = (bgSel === 'custom') ? (document.getElementById('sl-bg-color').value || '#ffffff') : bgSel;
    }
    try {
        // 非管理者的群組標籤一律綁定使用者（私人），不讓群組進到公司共用/部門干擾別人
        const isGroup = pendingLabelSpec && pendingLabelSpec.kind === 'multi';
        const scope = (isGroup && !IS_MGR) ? 'private' : document.getElementById('sl-scope').value;
        const fd = new FormData();
        fd.append('action', 'save_label');
        fd.append('name', name);
        fd.append('category', cat);
        fd.append('tags', document.getElementById('sl-tags').value.trim());
        fd.append('scope', scope);
        fd.append('dept_id', document.getElementById('sl-dept').value || '0');
        fd.append('spec', JSON.stringify(pendingLabelSpec));
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        pendingLabelSpec = null;
        hideModal('savelabel-modal');
        toast('已存入標籤庫：' + name + (cat ? '（' + cat + '）' : ''));
        await loadLabelLibrary();
        if (document.getElementById('libmgr-modal').classList.contains('show')) renderLibMgr();
    } catch (e) { toast('儲存失敗：' + (e.message || '')); }
}
async function deleteCustomLabel(id) {
    if (!confirm('確定刪除這個自訂標籤？（標籤庫是全體共用的）')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete_label'); fd.append('label_id', id);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        loadLabelLibrary();
    } catch (e) { toast('刪除失敗：' + (e.message || '')); }
}

/* 建立文字標籤（可改字規格標籤建立器） */
function openNewLabelModal() {
    document.getElementById('nl-text').value = '';
    document.getElementById('nl-name').value = '';
    document.getElementById('nl-cat').value = document.getElementById('lib-cat-filter').value || '';
    document.getElementById('nl-tags').value = '';
    document.getElementById('nl-scope').value = 'private';
    const nd = document.getElementById('nl-dept');
    nd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    nd.style.display = 'none';
    showModal('newlabel-modal');
    document.getElementById('nl-text').focus();
}
async function confirmNewLabel() {
    const text = document.getElementById('nl-text').value.replace(/\s+$/, '');
    if (!text.trim()) { toast('請輸入標籤文字'); return; }
    if (tagsInputBlocked('nl-tags')) return;
    const spec = {
        kind: document.getElementById('nl-kind').value,
        text: text,
        fontSize: Math.max(10, parseInt(document.getElementById('nl-size').value, 10) || 44),
        align: document.getElementById('nl-align').value
    };
    if (document.getElementById('nl-transparent').checked) spec.bg = 'transparent';
    const name = document.getElementById('nl-name').value.trim() || text.split('\n')[0].substring(0, 30);
    try {
        const fd = new FormData();
        fd.append('action', 'save_label');
        fd.append('name', name);
        fd.append('category', document.getElementById('nl-cat').value.trim());
        fd.append('tags', document.getElementById('nl-tags').value.trim());
        fd.append('scope', document.getElementById('nl-scope').value);
        fd.append('dept_id', document.getElementById('nl-dept').value || '0');
        fd.append('spec', JSON.stringify(spec));
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        hideModal('newlabel-modal');
        toast('已建立標籤：' + name);
        await loadLabelLibrary();
        if (document.getElementById('libmgr-modal').classList.contains('show')) renderLibMgr();
    } catch (e) { toast('建立失敗：' + (e.message || '')); }
}

/* 雙擊群組：
   - 一般群組（或 Alt+雙擊任何群組）→ 進入群組：拆成多選，可個別移動，調整完 Ctrl+G 重新群組
   - 標籤群組 → 就地編輯其中的文字（規格標籤改完外框自動貼合字長） */
function enterGroup(g) {
    // 重組時要還原的自訂屬性：漏掉會讓標註/箭頭/快速標籤在「進入群組→重組」一趟之後失去識別，
    // 連動刪除、角度重算、雙擊改字、外框貼字等行為全部失效
    const props = { labelSpec: g.labelSpec, labelKind: g.labelKind, dimKind: g.dimKind, dimAngleId: g.dimAngleId,
                    isQuickLabel: g.isQuickLabel, merged: g.merged, isArrowGroup: g.isArrowGroup, isFreehandEnds: g.isFreehandEnds };
    g.toActiveSelection();
    const sel = canvas.getActiveObject();
    if (sel) sel._regroupProps = props;   // Ctrl+G 重組時還原標籤屬性
    canvas.requestRenderAll();
    pushState();
    toast('已進入群組（拆成多選）：點空白處取消選取後可個別移動；調整完框選物件按 Ctrl+G 重新群組');
}
/* 雙擊直線/折線/矩形/不規則遮蓋＝直接進入「編輯端點」模式（跟屬性列按鈕同功能，較好找） */
canvas.on('mouse:dblclick', function (opt) {
    const t = opt.target;
    if (!t || currentTool !== 'select' || t.__pointEditing) return;
    if (['line', 'polyline', 'polygon', 'rect'].includes(t.type) && !t.isDimGuide) {
        canvas.setActiveObject(t);
        togglePointEdit();
    }
});
canvas.on('mouse:dblclick', function (opt) {
    const g = opt.target;
    if (!g || g.type !== 'group' || currentTool !== 'select') return;
    if (opt.e.altKey) { enterGroup(g); return; }
    if (g.merged) return;                                        // 合併物件＝單一物件，雙擊不拆
    if (g.labelSpec && g.labelSpec.kind === 'tol') { startTolEdit(g); return; }   // ^公差堆疊：整串重編
    if (!g.labelSpec || g.labelSpec.kind === 'multi') { enterGroup(g); return; }
    const p = canvas.getPointer(opt.e);
    const texts = [];
    // 標籤內的 ^ 公差堆疊小組也算「可雙擊改字」的對象（還原成含 ^ 的原始字串整串重編）
    g.forEachObject(o => { if (o.type === 'text' || o.type === 'i-text' || (o.type === 'group' && o.labelSpec && o.labelSpec.kind === 'tol')) texts.push(o); });
    if (!texts.length) return;
    let best = null, bd = Infinity;
    texts.forEach(t => {
        const d = fabric.util.qrDecompose(t.calcTransformMatrix());
        const dist = (d.translateX - p.x) ** 2 + (d.translateY - p.y) ** 2;
        if (dist < bd) { bd = dist; best = t; }
    });
    startGroupTextEdit(g, best);
});
/* 一般文字（文字工具）：結束編輯時若含 A^B（如 -0^-0.18）自動轉成上下公差堆疊群組。
   注意：此事件在 fabric exitEditing() 執行到一半時同步觸發，當場移除物件會讓後續
   this.canvas.fire('object:modified') 讀到 undefined 噴例外，所以延後一個 tick 再轉換 */
canvas.on('text:editing:exited', function (opt) {
    const t = opt.target;
    if (!t || t.type !== 'i-text' || t.__groupEditFor || t.dcRole || t.dimKind) return;   // 暫時編輯框/設變列表/標註文字不轉
    if (!TOL_INPUT_RE.test(t.text)) return;
    setTimeout(function () {
        if (restoring || canvas.getObjects().indexOf(t) === -1) return;   // 已被其他流程移除（如編輯中刪除/復原）
        convertToTolGroup(t);
    }, 0);
});
function startGroupTextEdit(group, child, cursorToEnd) {
    // child 也可能是標籤內的 ^ 公差堆疊小組：編輯時還原原始字串，字體樣式記在它的 labelSpec
    const isTolChild = (child.type === 'group' && child.labelSpec && child.labelSpec.kind === 'tol');
    const st = isTolChild ? child.labelSpec : child;
    const dec = fabric.util.qrDecompose(child.calcTransformMatrix());
    child.visible = false; group.dirty = true;
    canvas.requestRenderAll();
    const tmp = new fabric.IText(isTolChild ? String(st.text || '') : child.text, {
        left: dec.translateX, top: dec.translateY, originX: 'center', originY: 'center',
        angle: dec.angle, scaleX: dec.scaleX, scaleY: dec.scaleY,
        fontSize: st.fontSize, fontFamily: st.fontFamily, fontWeight: st.fontWeight,
        fill: st.fill, textAlign: child.textAlign || 'center', backgroundColor: '#fff8d6',
        underline: !!child.underline
    });
    tmp.doubleUnderline = !!child.doubleUnderline;
    tmp.__groupEditFor = group;   // 讓「刪除」知道使用者要刪的是整組，不是這個暫時編輯框
    canvas.add(tmp);
    canvas.setActiveObject(tmp);
    tmp.enterEditing();
    if (cursorToEnd) { tmp.setSelectionStart(tmp.text.length); tmp.setSelectionEnd(tmp.text.length); }
    else tmp.selectAll();
    tmp.on('editing:exited', function () {
        const val = tmp.text;
        try { tmp.abortCursorAnimation(); } catch (e) { /* 游標動畫沒在跑就算了 */ }
        canvas.remove(tmp);
        if (tmp.__deleteGroup) {   // 編輯中按了刪除：連同整組一起刪，不要再把群組加回來
            canvas.remove(group);
            // 角度標註整組連動：刪弧線群組時把同 id 的隱藏輔助線一併刪掉，不留孤兒
            if (group.dimAngleId) canvas.getObjects().slice().forEach(o => { if (o.dimAngleId === group.dimAngleId) canvas.remove(o); });
            canvas.discardActiveObject();
            canvas.requestRenderAll();
            pushState();
            return;
        }
        // 復原/重做把畫布整個換掉了，或群組已被其他流程移除：不能再把舊群組加回去（會生出與歷史不符的幽靈物件）
        if (restoring || canvas.getObjects().indexOf(group) === -1) { canvas.requestRenderAll(); return; }
        // 編輯期間若有改文字樣式（底色/顏色/粗體/字級/底線），要同步回真正的文字，不然編輯結束就跳回舊樣式
        if (isTolChild) {
            Object.assign(child.labelSpec, { fill: tmp.fill, fontWeight: tmp.fontWeight, fontSize: tmp.fontSize });
            if (tmp.backgroundColor !== '#fff8d6') child.labelSpec.backgroundColor = tmp.backgroundColor;
        } else {
            child.set({ fill: tmp.fill, fontWeight: tmp.fontWeight, fontSize: tmp.fontSize, underline: tmp.underline });
            child.doubleUnderline = tmp.doubleUnderline;
            if (tmp.backgroundColor !== '#fff8d6') child.set('backgroundColor', tmp.backgroundColor);   // #fff8d6=編輯中的提示底色，沒被使用者改過就不帶回去
        }
        child.dirty = true;
        child.visible = true;
        finishGroupTextEdit(group, child, val);
    });
}
function finishGroupTextEdit(group, child, val) {
    const center = group.getCenterPoint();
    if (group.labelSpec && group.labelSpec.kind !== 'fabric' && child.specPath) {
        // 規格標籤：改 spec 後整顆重建（外框自動貼合新字長），保留使用者縮放/旋轉
        const spec = JSON.parse(JSON.stringify(group.labelSpec));
        setSpecByPath(spec, child.specPath, val);
        // 先建好並驗證尺寸再替換：重建若出例外或算出 NaN（毒化物件＝殘影/卡死來源），保留原標籤不動
        let ng = null;
        try { ng = makeLabelFromSpec(spec); } catch (e) { console.warn('[EGdraw] 標籤重建例外：', e); }
        if (!ng || !isFinite(ng.width) || !isFinite(ng.height)) {
            toast('標籤重建失敗，已保留原內容（此次修改未套用）');
            canvas.requestRenderAll();
            return;
        }
        const { scaleX, scaleY, angle } = group;
        canvas.remove(group);
        ng.set({ scaleX, scaleY, angle, originX: 'center', originY: 'center' });
        ng.setPositionByOrigin(center, 'center', 'center');
        ng.setCoords();
        canvas.add(ng);
        canvas.setActiveObject(ng);
    } else {
        // 自由組合（fabric）標籤／快速標註群組：改字後重組群組以重算邊界
        const props = { labelSpec: group.labelSpec, labelKind: group.labelKind, isQuickLabel: group.isQuickLabel, dimKind: group.dimKind, dimAngleId: group.dimAngleId };
        const kids = group.getObjects().slice();
        const wasQuickLabel = group.isQuickLabel;
        group.destroy();               // 還原子物件為絕對座標（含群組縮放）
        // 標籤內也支援 ^ 公差堆疊：字串含 A^B → 文字換成堆疊小組；堆疊小組改到沒 ^ → 換回一般文字
        const wasTol = (child.type === 'group' && child.labelSpec && child.labelSpec.kind === 'tol');
        let textObj = child;
        if (TOL_INPUT_RE.test(val) || wasTol) {
            const st = wasTol ? child.labelSpec : child;
            const style = { fontSize: st.fontSize || 28, fill: st.fill, fontWeight: st.fontWeight,
                            fontFamily: st.fontFamily, backgroundColor: st.backgroundColor || '' };
            let no = null;
            try {
                no = TOL_INPUT_RE.test(val) ? makeTolGroup(val, style) : new fabric.IText(val, style);
            } catch (e) { console.warn('[EGdraw] 標籤內公差文字建立例外：', e); }
            if (no && isFinite(no.width) && isFinite(no.height)) {
                const c0 = child.getCenterPoint();
                no.set({ originX: 'center', originY: 'center', angle: child.angle || 0 });
                no.setPositionByOrigin(c0, 'center', 'center');
                no.setCoords();
                const idx = kids.indexOf(child);
                if (idx >= 0) kids[idx] = no; else kids.push(no);
                textObj = no;
            } else if (!wasTol) { child.set('text', val); child.dirty = true; }
        } else {
            child.set('text', val); child.dirty = true;
        }
        if (wasQuickLabel) {
            // 快速標籤（左側「標籤」工具）：邊框自動貼合新字長，中心點不變。
            // 用文字的「原生(未縮放)」寬高＋和文字相同的縮放/角度來算邊框——若直接用 getScaledWidth，
            // 而邊框自身又已帶著群組縮放，改字後會被重複套一次縮放，導致邊框過大/過小、看起來像沒依內容貼合。
            const box = kids.find(k => k.type === 'rect');
            if (box && box !== textObj) {
                if (typeof textObj.initDimensions === 'function') textObj.initDimensions();  // 先確保新字的寬高已重算
                const pad = labelBoxPadding((wasTol ? (child.labelSpec.fontSize || 28) : child.fontSize) || 28);
                box.set({
                    width: textObj.width + pad * 2,
                    height: textObj.height + pad * 2,
                    scaleX: textObj.scaleX, scaleY: textObj.scaleY, angle: textObj.angle || 0
                });
                box.setPositionByOrigin(textObj.getCenterPoint(), 'center', 'center');
                box.setCoords();
            }
        }
        canvas.remove(group);
        const ng = new fabric.Group(kids);
        Object.assign(ng, props);
        canvas.add(ng);
        canvas.setActiveObject(ng);
    }
    canvas.requestRenderAll();
    pushState();
}

/* 複製出來的物件若帶著角度標註的 dimAngleId，要換發新 id：
   兩份標示共用同一 id 會互相干擾（重算只刪到一份、刪一份會連動刪掉另一份） */
function reissueDimIds(root) {
    const map = {};
    const visit = o => {
        if (o.dimAngleId) {
            map[o.dimAngleId] = map[o.dimAngleId] || ('da' + Date.now() + '_' + Math.floor(Math.random() * 100000));
            o.dimAngleId = map[o.dimAngleId];
        }
        if (o.getObjects) o.getObjects().forEach(visit);
    };
    visit(root);
}
/* Figma 式快速複製：Alt+拖曳 = 原地留一份、拖走一份（Ctrl+D 亦可）。
   一定要「真的開始拖曳」才複製——Alt+單擊/雙擊（進入群組手勢）若立即複製，
   會原地疊出看不見的重疊複本，就是「刪了又還有一個」的殘留來源。 */
let altClonePending = null;   // { src, left, top }
canvas.on('mouse:down', function (opt) {
    if (currentTool !== 'select' || !opt.e.altKey || !opt.target || opt.target === artboard) return;
    altClonePending = { src: opt.target, left: opt.target.left, top: opt.target.top };
});
canvas.on('object:moving', function (e) {
    if (!altClonePending || !e || e.target !== altClonePending.src) return;
    const { src, left, top } = altClonePending;
    altClonePending = null;
    src.clone(function (cl) {
        cl.set({ left, top });   // 複製品留在原位（src 已經被拖走了）
        reissueDimIds(cl);
        canvas.add(cl);
        cl.moveTo(canvas.getObjects().indexOf(src));  // 複製品墊在原件下方，使用者拖走的是原件
        pushState();
    }, SNAP_PROPS);
});
canvas.on('mouse:up', function () { altClonePending = null; });

/* ── 圖片載入：檔案 / 拖放 / 剪貼簿 ── */
function openImageFiles() { document.getElementById('file-input').click(); }
document.getElementById('file-input').addEventListener('change', function () {
    loadFiles(Array.from(this.files)); this.value = '';
});

let addOffset = 0;
function loadFiles(files) {
    const imgs = files.filter(f => (f.type || '').startsWith('image/'));
    const pdfs = files.filter(f => f.type === 'application/pdf' || /\.pdf$/i.test(f.name || ''));
    if (!imgs.length && !pdfs.length) { toast('沒有可用的圖片或 PDF 檔'); return; }
    imgs.forEach((f, i) => {
        const r = new FileReader();
        r.onload = e => addImageFromURL(e.target.result, i);
        r.readAsDataURL(f);
    });
    if (pdfs.length) {
        // PDF 一次處理一份（多頁要跳窗問頁碼，同時開多份會互相蓋掉）
        if (pdfs.length > 1) toast('一次只能帶入一個 PDF，先開啟「' + pdfs[0].name + '」');
        const r = new FileReader();
        r.onload = ev => openPdfFromSource({ data: new Uint8Array(ev.target.result) }, pdfs[0].name);
        r.readAsArrayBuffer(pdfs[0]);
    }
}
function addImageFromURL(url, cascade) {
    fabric.Image.fromURL(url, function (img) {
        if (!img || !img.width) { toast('圖檔載入失敗'); return; }
        // 圖比畫布大很多 → 自動撐大畫布（解決小畫家貼圖被裁掉的問題）
        if (img.width > artW || img.height > artH) {
            setArtboardSize(Math.max(artW, img.width + 40), Math.max(artH, img.height + 40));
            zoomFit();
        }
        const off = (cascade || 0) * 30 + (addOffset % 5) * 24;
        addOffset++;
        img.set({
            left: artboard.left + (artW - img.width * (img.scaleX || 1)) / 2 + off,
            top: artboard.top + (artH - img.height * (img.scaleY || 1)) / 2 + off
        });
        canvas.add(img);
        canvas.setActiveObject(img);
        setTool('select');
        canvas.requestRenderAll();
        pushState();
        toast('已加入圖片（' + img.width + '×' + img.height + '），可拖角縮放對齊');
    }, { crossOrigin: 'anonymous' });
}

/* ── PDF → 圖檔（2026-07-31）───────────────────────────────────────────────
   PDF 是文件格式、不是點陣圖，Fabric 開不了；改成用 pdf.js 在「瀏覽器端」把指定頁
   渲染到暫存 canvas 再當成圖片放進畫布（原 PDF 檔不動）。
   ‧為什麼放前端做：本機沒有 Ghostscript / poppler / Imagick，LibreOffice 轉 PDF→PNG
     只吃得到第一頁；pdf.js 是向量渲染，要幾 dpi 就幾 dpi，且多頁可以讓使用者挑。
   ‧函式庫放本機 resource/js/pdfjs（內網不走 CDN），且「用到才載入」——1MB 多的
     worker 不該讓每次開編輯器都變慢。
   ‧多頁 PDF 一律跳窗問要開哪幾頁（可 1,3-5），單頁直接開不囉嗦。 */
const PDFJS_BASE = '../../resource/js/pdfjs/';
const PDFJS_V    = 'null';
let pdfjsLoading = null;
function ensurePdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfjsLoading) return pdfjsLoading;
    pdfjsLoading = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = PDFJS_BASE + 'pdf.min.js?v=' + PDFJS_V;
        s.onload = () => {
            if (!window.pdfjsLib) { pdfjsLoading = null; reject(new Error('pdfjsLib 未載入')); return; }
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js?v=' + PDFJS_V;
            resolve(window.pdfjsLib);
        };
        s.onerror = () => { pdfjsLoading = null; reject(new Error('找不到 resource/js/pdfjs/pdf.min.js')); };
        document.head.appendChild(s);
    });
    return pdfjsLoading;
}
let pdfPending = null;   // 等使用者填頁碼的 PDF：{doc, name}
/* src：{url:'…'}（同源檔案／API 下載端點皆可，帶 cookie 過權限閘門）或 {data:Uint8Array}（本機開檔/拖入） */
async function openPdfFromSource(src, name) {
    toast('PDF 讀取中…');
    let lib;
    try { lib = await ensurePdfJs(); }
    catch (e) { toast('無法載入 PDF 轉圖元件：' + (e.message || e)); return; }
    try {
        const doc = await lib.getDocument(Object.assign({ withCredentials: true }, src)).promise;
        if (doc.numPages === 1) { await renderPdfPages(doc, [1], 3, name); return; }
        pdfPending = { doc: doc, name: name || 'PDF' };
        document.getElementById('pdf-file-name').textContent = (name || 'PDF') + '（共 ' + doc.numPages + ' 頁）';
        document.getElementById('pdf-total').textContent = doc.numPages;
        document.getElementById('pdf-pages').value = '1';
        showModal('pdfpage-modal');
        setTimeout(() => { const el = document.getElementById('pdf-pages'); if (el) { el.focus(); el.select(); } }, 60);
    } catch (e) {
        toast('PDF 讀取失敗：' + (e && e.message ? e.message : e));
    }
}
/* 頁碼字串 → 頁碼陣列：接受「1」「1,3」「1,3-5」「1~3」，超出範圍/重複的自動略過 */
function parsePdfPageSpec(spec, total) {
    const out = [];
    String(spec || '').split(/[,，、\s]+/).forEach(seg => {
        if (!seg) return;
        const m = seg.match(/^(\d+)\s*[-–~～至到]\s*(\d+)$/);
        if (m) {
            let a = parseInt(m[1], 10), b = parseInt(m[2], 10);
            if (a > b) { const t = a; a = b; b = t; }
            for (let i = a; i <= b; i++) if (i >= 1 && i <= total && out.indexOf(i) === -1) out.push(i);
            return;
        }
        const n = parseInt(seg, 10);
        if (n >= 1 && n <= total && out.indexOf(n) === -1) out.push(n);
    });
    return out;
}
async function confirmPdfPages() {
    if (!pdfPending) { hideModal('pdfpage-modal'); return; }
    const total = pdfPending.doc.numPages;
    const pages = parsePdfPageSpec(document.getElementById('pdf-pages').value, total);
    if (!pages.length) { toast('請輸入要開啟的頁碼（1～' + total + '，可用 1,3-5）'); return; }
    if (pages.length > 20) { toast('一次最多帶入 20 頁，請分批'); return; }
    const q = parseFloat(document.getElementById('pdf-quality').value) || 3;
    hideModal('pdfpage-modal');
    const d = pdfPending; pdfPending = null;
    await renderPdfPages(d.doc, pages, q, d.name);
}
/* 把指定頁渲染成圖片加進畫布。quality＝以 72dpi 為底的倍率（3≈216dpi）；
   單邊超過 MAX_SIDE 就自動降倍率——A0 圖面用 3 倍會到 1 億像素，瀏覽器直接爆掉。 */
async function renderPdfPages(doc, pages, quality, name) {
    const MAX_SIDE = 4000;
    for (let i = 0; i < pages.length; i++) {
        try {
            const page = await doc.getPage(pages[i]);
            const base = page.getViewport({ scale: 1 });
            let scale = quality;
            const longest = Math.max(base.width, base.height);
            if (longest * scale > MAX_SIDE) scale = Math.max(1, MAX_SIDE / longest);
            const vp = page.getViewport({ scale: scale });
            const cv = document.createElement('canvas');
            cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
            const ctx = cv.getContext('2d');
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, cv.width, cv.height);   // PDF 底是透明的，先鋪白否則存成 JPG 會變黑底
            await page.render({ canvasContext: ctx, viewport: vp }).promise;
            addImageFromURL(cv.toDataURL('image/png'), i);
            toast('已帶入' + (name ? '「' + name + '」' : '') + '第 ' + pages[i] + ' 頁（' + cv.width + '×' + cv.height + '）');
        } catch (e) {
            toast('第 ' + pages[i] + ' 頁轉圖失敗：' + (e && e.message ? e.message : e));
        }
    }
}

/* 拖放開圖 */
['dragenter', 'dragover'].forEach(ev => document.addEventListener(ev, e => {
    e.preventDefault();
    if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files'))
        document.getElementById('drop-hint').classList.add('show');
}));
['dragleave', 'drop'].forEach(ev => document.addEventListener(ev, e => {
    e.preventDefault();
    if (ev === 'drop' || e.target === document.documentElement || !e.relatedTarget)
        document.getElementById('drop-hint').classList.remove('show');
}));
document.addEventListener('drop', e => {
    e.preventDefault();
    if (e.dataTransfer && e.dataTransfer.files.length) loadFiles(Array.from(e.dataTransfer.files));
});

/* 剪貼簿貼上：優先系統圖片（小畫家）→ 內部物件複製 → 跨視窗剪貼簿 */
let internalClip = null, internalClipTs = 0;
document.addEventListener('paste', function (e) {
    // 焦點在輸入欄位（例如匯出檔名）時，貼上交給瀏覽器，不動畫布
    const tag = (document.activeElement || {}).tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    const items = (e.clipboardData || {}).items || [];
    for (const it of items) {
        if (it.type && it.type.startsWith('image/')) {
            const blob = it.getAsFile();
            const r = new FileReader();
            r.onload = ev => addImageFromURL(ev.target.result, 0);
            r.readAsDataURL(blob);
            e.preventDefault();
            return;
        }
    }
    if (isTextEditing()) return; // 文字編輯中的純文字貼上交給 IText
    pasteInternalOrCross();
});
async function pasteFromButton() {
    // 按鈕觸發：嘗試 async clipboard（需 HTTPS/localhost），失敗則提示用 Ctrl+V
    if (navigator.clipboard && navigator.clipboard.read) {
        try {
            const items = await navigator.clipboard.read();
            for (const item of items) {
                const t = item.types.find(t => t.startsWith('image/'));
                if (t) {
                    const blob = await item.getType(t);
                    const r = new FileReader();
                    r.onload = ev => addImageFromURL(ev.target.result, 0);
                    r.readAsDataURL(blob);
                    return;
                }
            }
        } catch (err) { /* fallthrough */ }
    }
    if (!pasteInternalOrCross()) toast('請直接按 Ctrl+V 貼上（瀏覽器限制，按鈕無法讀取系統剪貼簿）');
}
const CLIP_TTL_MS = 10 * 60 * 1000;   // 跨視窗剪貼簿只保留 10 分鐘：過期自動失效，避免很久以前複製的東西一直被 Ctrl+V 貼回來
function pasteInternalOrCross() {
    let cross = null;
    try { cross = JSON.parse(localStorage.getItem(CLIP_KEY) || 'null'); } catch (e) {}
    if (cross && (!cross.ts || Date.now() - cross.ts > CLIP_TTL_MS)) {
        cross = null;
        try { localStorage.removeItem(CLIP_KEY); } catch (e) {}
    }
    const crossIsNewer = cross && (!internalClip || cross.ts > internalClipTs);
    if (crossIsNewer && cross.objs && cross.objs.length) {
        // 跨視窗貼上：優先還原成可編輯的向量物件（保留顏色/線型/文字內容等），不是扁平化圖片
        fabric.util.enlivenObjects(cross.objs, function (objs) {
            objs.forEach(o => { o.set({ left: (o.left || 0) + 20, top: (o.top || 0) + 20 }); reissueDimIds(o); canvas.add(o); o.setCoords(); });
            if (objs.length > 1) canvas.setActiveObject(new fabric.ActiveSelection(objs, { canvas }));
            else if (objs[0]) canvas.setActiveObject(objs[0]);
            canvas.requestRenderAll();
            pushState();
        });
        return true;
    }
    if (crossIsNewer && cross.dataURL) {
        addImageFromURL(cross.dataURL, 0);
        return true;
    }
    if (internalClip) {
        internalClip.clone(function (cl) {
            cl.set({ left: cl.left + 20, top: cl.top + 20 });
            reissueDimIds(cl);
            if (cl.type === 'activeSelection') {
                cl.canvas = canvas;
                cl.forEachObject(o => canvas.add(o));
                cl.setCoords();
            } else canvas.add(cl);
            canvas.setActiveObject(cl);
            canvas.requestRenderAll();
            pushState();
        }, SNAP_PROPS);
        return true;
    }
    return false;
}

/* Ctrl+C：內部複製（同視窗貼上用，保留完整 Fabric 物件）＋ 寫入跨視窗剪貼簿（把選取內容序列化成向量
   JSON，讓另一個視窗貼上時能重建成可編輯物件，不是扁平化圖片；序列化太大寫不進 localStorage 才退回
   扁平化 JPEG 預覽圖，兩者都失敗就明講「太大複製不過去」，不要默默失敗讓使用者以為有複製到） */
function serializeSelectionForClip(obj, cb) {
    obj.clone(function (cl) {
        // 單一物件：clone 本身就是絕對座標，直接序列化，不必經過畫布
        if (cl.type !== 'activeSelection') { cb([cl.toObject(SNAP_PROPS)]); return; }
        // 多選：子物件座標是相對於選取框的，得暫時放回畫布還原成絕對座標再序列化；
        // 用 try/finally 保證一定移除，途中出錯才不會留下一份「殘影」在畫布上
        const added = [];
        try {
            cl.canvas = canvas;
            cl.forEachObject(o => { canvas.add(o); added.push(o); });
            cl.setCoords();
            cb(added.map(o => o.toObject(SNAP_PROPS)));
        } finally {
            added.forEach(o => canvas.remove(o));
            canvas.requestRenderAll();
        }
    }, SNAP_PROPS);
}
function copySelection() {
    const obj = canvas.getActiveObject();
    if (!obj) return false;
    obj.clone(function (cl) { internalClip = cl; internalClipTs = Date.now(); }, SNAP_PROPS);
    const ts = Date.now();
    serializeSelectionForClip(obj, function (arr) {
        try {
            localStorage.setItem(CLIP_KEY, JSON.stringify({ ts, objs: arr }));
            return;
        } catch (e) { /* 向量 JSON 太大，往下退回扁平化圖片 */ }
        try {
            const url = exportSelectionDataURL(obj, 'jpeg', 1);
            localStorage.setItem(CLIP_KEY, JSON.stringify({ ts, dataURL: url }));
        } catch (e2) {
            toast('已複製（本視窗內可貼上），但內容過大無法同步到其他批圖視窗');
        }
    });
    return true;
}
function duplicateSelection() {
    const obj = canvas.getActiveObject();
    if (!obj) return;
    obj.clone(function (cl) {
        cl.set({ left: cl.left + 20, top: cl.top + 20 });
        reissueDimIds(cl);
        if (cl.type === 'activeSelection') {
            cl.canvas = canvas;
            cl.forEachObject(o => canvas.add(o));
            cl.setCoords();
        } else canvas.add(cl);
        canvas.setActiveObject(cl);
        canvas.requestRenderAll();
        pushState();
    }, SNAP_PROPS);
}

/* ── 框選複製（把區域合成影像變新圖塊；白底視為透明，貼上時不蓋住下面的東西） ── */
function doCropCopy(x, y, w, h) {
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    const el = exportRegionCanvasEl(x, y, w, h, 1);
    if (document.getElementById('p-crop-transparent').checked) whiteToTransparent(el);
    const url = el.toDataURL('image/png');
    // 不再順手寫入跨視窗剪貼簿：同步寫大字串會卡 UI、常撞 5MB 配額，要跨窗請選取後按 Ctrl+C（自動寫入跨窗剪貼簿）
    fabric.Image.fromURL(url, function (img) {
        img.set({ left: x + 24, top: y + 24 });
        img.transparentBg = document.getElementById('p-crop-transparent').checked;   // 透明切塊之後挖空用擦除
        canvas.add(img);
        canvas.setActiveObject(img);
        setTool('select');
        canvas.requestRenderAll();
        pushState();
        toast('已複製框選範圍成新圖塊（要複製到另一個批圖視窗：選取後按 Ctrl+C，到另一窗 Ctrl+Shift+V 貼上）');
    });
}

/* ── 框選搬移（小畫家式，所見即所得）：跟框選複製一樣把框內看得到的東西（底圖＋畫上去的物件）
   一起烙進切塊圖搬走——底圖從範圍真正挖空、完整落在框內的物件烙進切塊後從原處移除；
   只壓到框線一部分的物件無法切一半，保持原樣不動（也不會烙進切塊）。
   切下來的內容白底視為透明，拖到新位置不會蓋住下面的東西。
   用完停留在此工具可連續框選，按 Esc 或切換其他工具才離開 ── */
function doCropMove(x, y, w, h) {
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    const bgObjs = backgroundImagesInRect(x, y, w, h);
    const moved = contentObjectsInRect(x, y, w, h);   // 完整落在框內：烙進切塊並從原處移除
    const carried = contentObjectsTouchingRect(x, y, w, h).filter(o => moved.indexOf(o) === -1);   // 只壓到一部分：原封不動一起帶走
    const others = canvas.getObjects().filter(o => o.id !== '__artboard' && bgObjs.indexOf(o) === -1 && moved.indexOf(o) === -1);
    const prevVis = others.map(o => o.visible !== false);
    others.forEach(o => { o.visible = false; });   // 只藏「部分壓框」與框外的物件；框內物件留著＝跟複製一樣烙進圖
    const el = exportRegionCanvasEl(x, y, w, h, 1);
    others.forEach((o, i) => { o.visible = prevVis[i]; });
    moved.forEach(o => canvas.remove(o));   // 已烙進切塊，原件移除（Ctrl+Z 可整批復原）
    if (document.getElementById('p-crop-transparent').checked) whiteToTransparent(el);
    const url = el.toDataURL('image/png');
    const fillColor = toHex(artboard.fill) || '#ffffff';
    let skipped = 0;
    bgObjs.forEach(o => {
        if (punchHoleInImage(o, x, y, w, h, fillColor)) return;
        skipped++;
        // 無法真正挖空（旋轉/已裁切）的底圖，退回原本「疊一層遮板」做法，只蓋住那張圖被選到的範圍
        const br = o.getBoundingRect(true, true);
        const ix = Math.max(x, br.left), iy = Math.max(y, br.top);
        const ix2 = Math.min(x + w, br.left + br.width), iy2 = Math.min(y + h, br.top + br.height);
        if (ix2 > ix && iy2 > iy) canvas.add(new fabric.Rect({ left: ix, top: iy, width: ix2 - ix, height: iy2 - iy, fill: fillColor }));
    });
    canvas.requestRenderAll();
    fabric.Image.fromURL(url, function (img) {
        img.set({ left: x, top: y });
        img.transparentBg = document.getElementById('p-crop-transparent').checked;   // 透明切塊之後挖空用擦除
        canvas.add(img);
        selectSliceWithCarried(img, carried);
        canvas.requestRenderAll();
        pushState();
        toast((skipped
            ? '已切下框選範圍；部分底圖因旋轉/已裁切無法真正挖空，改用底色覆蓋，直接拖到新位置'
            : '已切下框選範圍（原底圖已真正挖空），直接拖到新位置；不滿意可 Ctrl+Z 復原')
            + (moved.length ? '；框內 ' + moved.length + ' 個物件已一起切進圖塊' : '')
            + (carried.length ? '；壓到框線的 ' + carried.length + ' 個物件（文字/形狀）保持原樣一起選取，拖曳會跟著移動' : ''));
    });
}

/* ── 匯出核心：以 identity viewport 座標裁切，確保所見即所得 ── */
function exportRegionDataURL(x, y, w, h, format, mult, quality) {
    const vpt = canvas.viewportTransform.slice();
    const active = canvas.getActiveObject();
    canvas.discardActiveObject();
    canvas.setViewportTransform([1, 0, 0, 1, 0, 0]);
    const prevShadow = artboard.shadow;
    artboard.shadow = null;
    // 裁切區域的邊緣若因縮放倍率非整數而有次像素誤差，畫布本身透明底會露出來：
    // PNG 會變成邊緣鋸齒，JPEG（不支援透明）瀏覽器預設補黑＝列印出現黑色框線。
    // 匯出時暫時把畫布底色設白（跟 artboard 底色一致），邊緣露出的縫隙就補白而非透明/黑，解決列印偶發框線。
    const prevBg = canvas.backgroundColor;
    canvas.backgroundColor = '#ffffff';
    canvas.requestRenderAll();
    const url = canvas.toDataURL({
        format: format || 'png',
        quality: (quality != null ? quality : 0.92),
        left: x, top: y, width: w, height: h,
        multiplier: mult || 1,
        enableRetinaScaling: false
    });
    canvas.backgroundColor = prevBg;
    artboard.shadow = prevShadow;
    canvas.setViewportTransform(vpt);
    if (active) canvas.setActiveObject(active);
    canvas.requestRenderAll();
    return url;
}
function exportSelectionDataURL(obj, format, mult, quality) {
    const b = obj.getBoundingRect(true, true);
    return exportRegionDataURL(b.left, b.top, b.width, b.height, format, mult, quality);
}
/* ── 壓平成圖：把底圖＋所有物件燒成單一張圖重新放上（效果同「存成圖片後重新開啟」）。
   壓平後整張都是底圖像素，框選搬移／套索就能對原本的向量圖形（圓、線、標籤）真正挖空切缺口。
   新圖 angle=0、無裁切、縮放1，符合 punchHoleInImage 的可挖空條件。可 Ctrl+Z 復原。 */
function flattenAll() {
    const others = canvas.getObjects().filter(o => o !== artboard);
    if (!others.length) { toast('畫布上沒有東西可壓平'); return; }
    if (!confirm('把底圖與所有物件（含文字、標籤、形狀、浮水印）壓平成一張圖？\n壓平後就不能再個別編輯這些物件（可 Ctrl+Z 復原）。')) return;
    flushPendingState();   // 先把壓平前的狀態寫進復原快照，Ctrl+Z 才回得來
    const br = artboard.getBoundingRect(true, true);
    // 2 倍解析度壓平（放大檢視不糊）；超大畫布封頂在單邊 8192px，避免超出瀏覽器 canvas 上限
    const mult = Math.min(2, 8192 / Math.max(br.width, br.height, 1));
    let url = null;
    try { url = exportRegionDataURL(br.left, br.top, br.width, br.height, 'png', mult); }
    catch (e) { console.warn('[EGdraw] 壓平成圖轉圖失敗：', e); }
    if (!url || url.length < 100) { toast('壓平失敗：畫布轉圖時發生問題（F12 主控台有詳情），畫布未受影響'); return; }
    fabric.Image.fromURL(url, function (img) {
        if (!img || !img.width) { toast('壓平失敗：圖片載入異常，畫布未受影響'); return; }
        canvas.discardActiveObject();
        canvas.getObjects().slice().forEach(o => { if (o !== artboard) canvas.remove(o); });
        img.set({ left: br.left, top: br.top, scaleX: br.width / img.width, scaleY: br.height / img.height });
        img.setCoords();
        canvas.add(img);
        canvas.requestRenderAll();
        pushState();
        toast('已壓平成單一圖片：可用「框選搬移／套索」直接切缺口；不滿意可 Ctrl+Z 復原');
    });
}
/* ── 壓平選取：只把選取的物件（單一或多選）燒成一張透明背景的圖，其他物件不受影響。
   跟「合併」的差別：合併後仍是向量群組；壓平後變成圖片像素，框選搬移／套索可對它真正挖空。 */
function flattenSelection() {
    const obj = canvas.getActiveObject();
    if (!obj || obj === artboard) { toast('請先選取要壓平的物件'); return; }
    flushPendingState();   // 壓平前狀態先入復原快照
    const parts = (obj.type === 'activeSelection') ? obj.getObjects().slice() : [obj];
    const b = obj.getBoundingRect(true, true);
    // 4 倍解析度：壓平的多半是局部物件（比整張畫布小很多），拉高倍率讓線條放大檢視仍銳利；
    // 單邊 8192px 封頂，超大選取範圍自動降倍避免超出瀏覽器 canvas 上限
    const mult = Math.min(4, 8192 / Math.max(b.width, b.height, 1));
    // 先解散選取：多選狀態下子物件座標是「相對於選取框」的，直接複製會得到錯位座標；
    // 解散後回到畫布絕對座標，逐一複製才正確
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    // 一定要用「複製品」轉圖（跟標籤縮圖同一招）：對畫布上的原件直接 toDataURL，fabric 會把原件
    // 暫時搬進暫存畫布再搬回，中途一出錯原件就壞在半路（canvas 參照斷掉→本體不畫、只剩選取框線）。
    // 複製品在畫布外，轉圖怎麼失敗都不傷原件。
    let done = 0;
    const clones = new Array(parts.length);
    parts.forEach((o, i) => o.clone(function (cl) {
        clones[i] = cl;
        if (++done < parts.length) return;
        const usable = clones.filter(Boolean);
        if (!usable.length) { toast('壓平選取失敗：物件複製異常，原物件未受影響'); return; }
        const g = (usable.length === 1) ? usable[0] : new fabric.Group(usable);
        let url = null;
        try { url = g.toDataURL({ format: 'png', multiplier: mult }); }
        catch (e) { console.warn('[EGdraw] 壓平選取轉圖失敗：', e); }
        if (!url || url.length < 100) { toast('壓平選取失敗：物件轉圖時發生問題（F12 主控台有詳情），原物件未受影響'); return; }
        fabric.Image.fromURL(url, function (img) {
            if (!img || !img.width) { toast('壓平選取失敗：圖片載入異常，原物件未受影響'); return; }
            parts.forEach(o => canvas.remove(o));   // 還在畫布上的移除；期間被刪掉的自動略過
            img.transparentBg = true;   // 透明背景圖：之後挖空/切除改用擦除變透明，不填白色
            img.set({ left: b.left, top: b.top, scaleX: b.width / img.width, scaleY: b.height / img.height });
            img.setCoords();
            canvas.add(img);
            canvas.setActiveObject(img);
            canvas.requestRenderAll();
            pushState();
            toast('已把選取物件壓平成一張圖（透明背景）：可用「框選搬移／套索」對它切缺口；不滿意可 Ctrl+Z 復原');
        });
    }, SNAP_PROPS));
}
/* 同 exportRegionDataURL，但回傳實際 <canvas> 供進一步像素處理（白底轉透明用） */
function exportRegionCanvasEl(x, y, w, h, mult) {
    const vpt = canvas.viewportTransform.slice();
    const active = canvas.getActiveObject();
    canvas.discardActiveObject();
    canvas.setViewportTransform([1, 0, 0, 1, 0, 0]);
    const prevShadow = artboard.shadow;
    artboard.shadow = null;
    canvas.requestRenderAll();
    const el = canvas.toCanvasElement(mult || 1, { left: x, top: y, width: w, height: h });
    artboard.shadow = prevShadow;
    canvas.setViewportTransform(vpt);
    if (active) canvas.setActiveObject(active);
    canvas.requestRenderAll();
    return el;
}
/* 選取後透明選擇：白色（含接近白）像素視為透明，搬移/貼上時白底不會蓋住下面的東西 */
function whiteToTransparent(canvasEl, threshold) {
    threshold = threshold || 245;
    const ctx = canvasEl.getContext('2d');
    const id = ctx.getImageData(0, 0, canvasEl.width, canvasEl.height);
    const d = id.data;
    for (let i = 0; i < d.length; i += 4) {
        if (d[i] >= threshold && d[i + 1] >= threshold && d[i + 2] >= threshold) d[i + 3] = 0;
    }
    ctx.putImageData(id, 0, 0);
}
/* 完整落在範圍內的「畫上去的物件」（標籤/文字/球標/形狀…）：框選搬移時跟著切塊一起選取移動。
   排除底圖（走挖空）、鎖定/隱藏物件與標註輔助線 */
function contentObjectsInRect(x, y, w, h) {
    return canvas.getObjects().filter(o => {
        if (o.id === '__artboard' || o.locked || o.visible === false || o.isDimGuide) return false;
        if (o.type === 'image' && !o.labelSpec && !o.labelKind) return false;   // 底圖另外走挖空流程
        const br = o.getBoundingRect(true, true);
        return br.left >= x - 2 && br.top >= y - 2 && br.left + br.width <= x + w + 2 && br.top + br.height <= y + h + 2;
    });
}
/* 只壓到框線一部分的物件（Figma 框選＝碰到就算）：不能烙進切塊（會被切一半），
   改成「原封不動一起帶走」——切塊圖與這些物件一起變成多重選取，拖曳時整組跟著移動。
   使用者回報 2026-08-24：框住文字時只要文字外框有一點點超出框線，切下來就是一塊純白、
   文字還留在原地被白塊蓋住，看起來像「框選搬移壞掉」。 */
function contentObjectsTouchingRect(x, y, w, h) {
    return canvas.getObjects().filter(o => {
        if (o.id === '__artboard' || o.locked || o.visible === false || o.isDimGuide) return false;
        if (o.type === 'image' && !o.labelSpec && !o.labelKind) return false;   // 底圖另外走挖空流程
        const br = o.getBoundingRect(true, true);
        return !(br.left + br.width <= x || br.left >= x + w || br.top + br.height <= y || br.top >= y + h);
    });
}
/* 切塊圖與「一起帶走的物件」組成多重選取，放開滑鼠就能整組拖到新位置 */
function selectSliceWithCarried(img, carried) {
    if (!carried || !carried.length) { canvas.setActiveObject(img); return; }
    carried.forEach(o => canvas.bringToFront(o));   // 保證浮在切塊圖之上，不會被白底蓋住
    const sel = new fabric.ActiveSelection([img].concat(carried), { canvas: canvas });
    canvas.setActiveObject(sel);
}
function pointInPoly(px, py, pts) {
    let inside = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const xi = pts[i].x, yi = pts[i].y, xj = pts[j].x, yj = pts[j].y;
        if ((yi > py) !== (yj > py) && px < (xj - xi) * (py - yi) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
}
/* 框選範圍內、屬於「底圖」的 fabric.Image（排除標籤/球標等其他物件與畫布本身） */
function backgroundImagesInRect(x, y, w, h) {
    return canvas.getObjects().filter(o => {
        if (o.type !== 'image' || o.id === '__artboard' || o.labelSpec || o.labelKind) return false;
        const br = o.getBoundingRect(true, true);
        return !(br.left + br.width <= x || br.left >= x + w || br.top + br.height <= y || br.top >= y + h);
    });
}
/* 把底圖在選取範圍內的部分真正挖空（燒進圖片像素填色），而不是疊一層遮板；
   有旋轉或已裁切(cropX/cropY)的底圖座標換算太複雜且容易算錯，跳過改用底色覆蓋。
   transparentBg 的圖（壓平選取／透明切塊）：挖空範圍改用「擦除」讓像素變透明，不填白色 */
function punchHoleInImage(obj, x, y, w, h, fillColor, polyPoints) {
    const ang = ((obj.angle || 0) % 360 + 360) % 360;
    if (ang > 0.01 && ang < 359.99) return false;
    if (obj.cropX || obj.cropY) return false;
    const br = obj.getBoundingRect(true, true);
    const ix = Math.max(x, br.left), iy = Math.max(y, br.top);
    const ix2 = Math.min(x + w, br.left + br.width), iy2 = Math.min(y + h, br.top + br.height);
    if (ix2 <= ix || iy2 <= iy) return false;
    const natW = obj.width, natH = obj.height;
    if (!natW || !natH || !br.width || !br.height) return false;
    const sx = natW / br.width, sy = natH / br.height;
    const off = document.createElement('canvas');
    off.width = natW; off.height = natH;
    const ctx = off.getContext('2d');
    ctx.drawImage(obj._element, 0, 0, natW, natH);
    if (obj.transparentBg) { ctx.globalCompositeOperation = 'destination-out'; ctx.fillStyle = '#000000'; }
    else ctx.fillStyle = fillColor;
    if (polyPoints && polyPoints.length > 2) {
        // 不規則挖空：把場景座標的套索點換算成這張圖自己的像素座標，直接照形狀填色（canvas fill 本來就能畫任意多邊形）
        ctx.beginPath();
        polyPoints.forEach((pt, i) => {
            const lx = (pt.x - br.left) * sx, ly = (pt.y - br.top) * sy;
            if (i === 0) ctx.moveTo(lx, ly); else ctx.lineTo(lx, ly);
        });
        ctx.closePath();
        ctx.fill();
    } else {
        const lx = (ix - br.left) * sx, ly = (iy - br.top) * sy;
        const lw = (ix2 - ix) * sx, lh = (iy2 - iy) * sy;
        ctx.fillRect(lx, ly, lw, lh);
    }
    obj.setElement(off);
    obj.dirty = true;
    // 重要：element 若一直是 canvas 元素，之後「每一次」undo 快照的 getSrc() 都會對整張底圖重做
    // toDataURL PNG 編碼（數千像素圖一次數百ms～數秒），是「用過框選搬移後開始狂卡」的主因。
    // 這裡一次性編碼成 dataURL 換回 <img>，之後快照直接取 src 字串，零成本。
    const punchedUrl = off.toDataURL('image/png');
    const imgEl = new Image();
    imgEl.onload = function () {
        if (canvas.getObjects().indexOf(obj) === -1) return;   // 換圖途中被刪掉就算了
        obj.setElement(imgEl);
        obj.dirty = true;
        canvas.requestRenderAll();
    };
    imgEl.src = punchedUrl;
    return true;
}
/* 框選搬移（不規則套索版）：座套索式，只把底圖依套索形狀真正挖空，其他物件不受影響，可連續使用 */
function polyBBox(points) {
    const xs = points.map(p => p.x), ys = points.map(p => p.y);
    const x = Math.min(...xs), y = Math.min(...ys);
    return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y };
}
function clipCanvasToPolygon(canvasEl, points, offsetX, offsetY) {
    const ctx = canvasEl.getContext('2d');
    ctx.save();
    ctx.globalCompositeOperation = 'destination-in';
    ctx.beginPath();
    points.forEach((pt, i) => {
        const lx = pt.x - offsetX, ly = pt.y - offsetY;
        if (i === 0) ctx.moveTo(lx, ly); else ctx.lineTo(lx, ly);
    });
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}
function doCropMoveLasso(points) {
    const b = polyBBox(points);
    if (b.w < 3 || b.h < 3) return;
    canvas.discardActiveObject();
    canvas.requestRenderAll();
    const bgObjs = backgroundImagesInRect(b.x, b.y, b.w, b.h);
    // 四個角都在套索形狀內＝完整落在框內的物件：烙進切塊並從原處移除（跟複製一樣所見即所得）
    const moved = contentObjectsInRect(b.x, b.y, b.w, b.h).filter(o => {
        const br = o.getBoundingRect(true, true);
        return pointInPoly(br.left, br.top, points) && pointInPoly(br.left + br.width, br.top, points)
            && pointInPoly(br.left, br.top + br.height, points) && pointInPoly(br.left + br.width, br.top + br.height, points);
    });
    // 只壓到套索一部分的物件：跟矩形版一樣原封不動一起帶走，不要藏起來變成一塊空白
    const carried = contentObjectsTouchingRect(b.x, b.y, b.w, b.h).filter(o => moved.indexOf(o) === -1);
    const others = canvas.getObjects().filter(o => o.id !== '__artboard' && bgObjs.indexOf(o) === -1 && moved.indexOf(o) === -1);
    const prevVis = others.map(o => o.visible !== false);
    others.forEach(o => { o.visible = false; });
    const el = exportRegionCanvasEl(b.x, b.y, b.w, b.h, 1);
    others.forEach((o, i) => { o.visible = prevVis[i]; });
    moved.forEach(o => canvas.remove(o));   // 已烙進切塊，原件移除（Ctrl+Z 可整批復原）
    clipCanvasToPolygon(el, points, b.x, b.y);   // 只留套索範圍內的內容，範圍外變透明
    if (document.getElementById('p-crop-transparent').checked) whiteToTransparent(el);
    const url = el.toDataURL('image/png');
    const fillColor = toHex(artboard.fill) || '#ffffff';
    let anySkipped = false;
    bgObjs.forEach(o => { if (!punchHoleInImage(o, b.x, b.y, b.w, b.h, fillColor, points)) anySkipped = true; });
    if (anySkipped) canvas.add(new fabric.Polygon(points.slice(), { fill: fillColor }));   // 跟矩形版遮板一樣保持可選取，才能被使用者移動/刪除
    canvas.requestRenderAll();
    fabric.Image.fromURL(url, function (img) {
        img.set({ left: b.x, top: b.y });
        img.transparentBg = true;   // 套索切塊範圍外一定是透明像素，之後挖空一律用擦除
        canvas.add(img);
        selectSliceWithCarried(img, carried);
        canvas.requestRenderAll();
        pushState();
        toast((anySkipped
            ? '已切下不規則框選範圍；部分底圖因旋轉/已裁切無法真正挖空，改用色塊覆蓋，直接拖到新位置'
            : '已切下不規則框選範圍（原底圖已真正挖空），直接拖到新位置；不滿意可 Ctrl+Z 復原')
            + (moved.length ? '；框內 ' + moved.length + ' 個物件已一起切進圖塊' : '')
            + (carried.length ? '；壓到範圍邊緣的 ' + carried.length + ' 個物件保持原樣一起選取，拖曳會跟著移動' : ''));
    });
}

/* ── 匯出 / 列印 / 另存 ── */
function defaultFileName() {
    const d = new Date(), p = n => String(n).padStart(2, '0');
    return '批圖_' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '_' + p(d.getHours()) + p(d.getMinutes());
}
let lastExportName = '';   // 視窗未關前記住上次輸入的匯出檔名，第二次匯出不再跳回預設
function openExportModal() {
    document.getElementById('ex-name').value = lastExportName || defaultFileName();
    const hint = document.getElementById('ex-fs-hint');
    if (window.showSaveFilePicker) {
        hint.innerHTML = '「另存圖片」會開啟儲存位置選擇視窗（會記住上次資料夾）。';
    } else {
        hint.innerHTML = '目前以 HTTP 連線，瀏覽器只允許存到「下載」資料夾。<br>若要每次選擇資料夾：瀏覽器設定 → 下載 → 開啟「<b>每次下載前先詢問儲存位置</b>」，即可存到各自習慣的資料夾。';
    }
    showModal('export-modal');
}
function buildExportURL(formatOverride) {
    const range = document.getElementById('ex-range').value;
    const format = formatOverride || document.getElementById('ex-format').value;
    const mult = parseFloat(document.getElementById('ex-mult').value) || 1;
    if (range === 'selection') {
        const obj = canvas.getActiveObject();
        if (!obj) { toast('沒有選取物件，改匯出整個畫布'); }
        else return exportSelectionDataURL(obj, format, mult);
    }
    return exportRegionDataURL(artboard.left, artboard.top, artW, artH, format, mult);
}
function dataURLtoBlob(u) {
    const [head, body] = u.split(',');
    const mime = head.match(/:(.*?);/)[1];
    const bin = atob(body);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return new Blob([arr], { type: mime });
}
async function doSave() {
    const format = document.getElementById('ex-format').value;
    const ext = format === 'jpeg' ? '.jpg' : '.png';
    lastExportName = document.getElementById('ex-name').value.trim();
    const name = (lastExportName || defaultFileName()) + ext;
    const url = buildExportURL();
    const blob = dataURLtoBlob(url);

    if (window.showSaveFilePicker) {
        try {
            const opts = {
                suggestedName: name,
                types: [{ description: format.toUpperCase(), accept: { [blob.type]: [ext] } }]
            };
            const dir = await loadDirHandle();
            if (dir) opts.startIn = dir;
            const fh = await window.showSaveFilePicker(opts);
            const w = await fh.createWritable();
            await w.write(blob); await w.close();
            hideModal('export-modal');
            toast('已儲存：' + fh.name);
            return;
        } catch (e) {
            if (e && e.name === 'AbortError') return; // 使用者取消
            /* 失敗改走下載 fallback */
        }
    }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    hideModal('export-modal');
    toast('已送出下載：' + name);
}
function doPrint() {
    // 列印一律用 PNG（無損）：文字/線條邊緣才不會被 JPEG 壓縮糊掉。
    // 但 PNG×2 的 base64 常達數 MB，過去用 document.write 塞進列印視窗會很卡；
    // 這裡改用 Blob 網址（URL.createObjectURL），瀏覽器直接讀二進位、不必解析巨大 base64，
    // 兼顧「清晰（PNG）」與「快（Blob）」。列印完成後再釋放網址。
    const url = buildExportURL('png');
    const blob = dataURLtoBlob(url);
    const objUrl = URL.createObjectURL(blob);
    const w = window.open('', '_blank');
    if (!w) { URL.revokeObjectURL(objUrl); toast('列印視窗被瀏覽器攔截，請允許彈出視窗'); return; }
    w.document.write('<!DOCTYPE html><html><head><title>列印 - 批圖</title>' +
        '<style>html,body{margin:0;padding:0;}img{max-width:100%;}@media print{img{width:100%;}}</style>' +
        '</head><body><img src="' + objUrl + '" onload="setTimeout(function(){window.focus();window.print();},150)"></body></html>');
    w.document.close();
    const revoke = function () { URL.revokeObjectURL(objUrl); };
    try { w.addEventListener('afterprint', function () { setTimeout(revoke, 1000); }); } catch (e) {}
    setTimeout(revoke, 120000);   // 保底：afterprint 未觸發也會釋放
    hideModal('export-modal');
}

/* 列印：走 PDF 管線。把畫布（或選取範圍）以約 300 DPI 產成高解析 PNG，用 pdfmake 包成單頁 PDF
   後直接列印。相較瀏覽器 HTML/SVG 列印會多墊一層自家低解析光柵化，PDF 由瀏覽器的 PDF 引擎以印表機
   解析度輸出，畫質最接近「另存圖片後用看圖程式/本機列印」。pdfmake 未載入或產圖失敗時退回向量(SVG)列印。 */
function doPrintPDF() {
    // 列印紀錄（ai-rules/23）：只在這個「使用者按下列印」的入口記一次；
    // 底下 doPrintVector()/printFallback() 是同一次列印的退路，不可各記一次否則一次列印變三筆。
    try {
        if (window.EGPrintLog) {
            EGPrintLog.record({
                source  : 'image_editor',
                doc_name: (lastExportName || (typeof PRELOAD_PART_NO !== 'undefined' && PRELOAD_PART_NO) || defaultFileName()),
                doc_kind: 'attachment',
                part_no : (typeof PRELOAD_PART_NO !== 'undefined' ? PRELOAD_PART_NO : '')
            });
        }
    } catch (e) {}
    if (typeof pdfMake === 'undefined') { toast('PDF 元件未載入，改用向量列印'); doPrintVector(); return; }
    const range = document.getElementById('ex-range').value;
    let x = artboard.left || 0, y = artboard.top || 0, w = artW, h = artH, sel = null;
    if (range === 'selection') {
        sel = canvas.getActiveObject();
        if (sel) { const b = sel.getBoundingRect(true, true); x = b.left; y = b.top; w = b.width; h = b.height; }
        else toast('沒有選取物件，改列印整個畫布');
    }
    // 紙張版面(點,1/72吋)：依長寬決定直/橫，內縮邊界後等比例縮放置中＝一律縮成一頁
    const PAPERS = { A4: { w: 595.28, h: 841.89 }, A3: { w: 841.89, h: 1190.55 } };
    const paper = PAPERS[(document.getElementById('ex-paper') || {}).value] || PAPERS.A4;
    const landscape = w >= h;
    const pageW = landscape ? paper.h : paper.w, pageH = landscape ? paper.w : paper.h;
    const margin = 18;                                  // 約 0.25 吋
    const scale = Math.min((pageW - margin * 2) / w, (pageH - margin * 2) / h);
    const dispW = w * scale, dispH = h * scale;         // PDF 點(1/72吋)＝紙上顯示尺寸
    // 目標約 400 DPI：算出來源需要的倍率（上限 4 以免記憶體爆掉、下限 1）。
    // 原本 300 DPI 對表格內小字（如簽到表細格）不夠銳利，使用者實測回報文字模糊，故調高目標值。
    let mult = (dispW / 72 * 400) / w;
    mult = Math.max(1, Math.min(4, mult));
    // 影像格式用 JPEG 而非 PNG：pdfmake 嵌 PNG 會把像素重新 deflate 壓縮，高解析大圖
    // 會踩到內建 zlib 的緩衝溢位(RangeError: offset is out of bounds)整個列印失敗;JPEG 已是壓縮格式,
    // pdfmake 直接以 DCTDecode 嵌入不再 deflate,可完全避開該崩潰。底圖為白色 artboard,JPEG 無透明也不會變黑。
    // 品質提高到 0.98（原 0.95）：文字/細線邊緣的 JPEG 量化模糊在 0.95 仍肉眼可見，0.98 明顯更銳利，
    // 檔案增加量可接受（單頁 PDF）。
    let dataURL;
    try {
        dataURL = (range === 'selection' && sel)
            ? exportSelectionDataURL(sel, 'jpeg', mult, 0.98)
            : exportRegionDataURL(x, y, w, h, 'jpeg', mult, 0.98);
    } catch (err) { toast('產生列印影像失敗，改用向量列印'); doPrintVector(); return; }
    const docDef = {
        pageSize: { width: pageW, height: pageH },
        pageMargins: [margin, margin, margin, margin],
        content: [{ image: dataURL, width: dispW, height: dispH, alignment: 'center' }]
    };
    // 不直接用 pdfmake 內建的 .print()：它是「非同步產生 PDF」，若這張圖太大／記憶體不足／
    // 影像嵌入失敗，錯誤是在 pdfmake 內部的非同步流程裡丟出，外層 try/catch 完全攔不到，結果就是
    // 「按了列印沒反應」(有 toast 也不會出現)。改用 getBlob(callback)：能攔到同步例外，並用看門狗
    // 逾時偵測非同步卡死，任何一種失敗都退回可正常運作的快速(PNG)列印，絕不留下「沒反應」。
    let doc;
    try { doc = pdfMake.createPdf(docDef); }
    catch (err) { console.warn('[EGdraw] createPdf 失敗：', err); toast('產生列印 PDF 失敗(' + ((err && err.message) || err) + ')，改用快速列印'); printFallback(); return; }
    hideModal('export-modal');
    let settled = false;
    const watchdog = setTimeout(function () {
        if (settled) return; settled = true;
        toast('PDF 產生逾時，改用快速列印'); printFallback();
    }, 10000);
    pdfToBlob(doc,
        function (blob) {
            if (settled) return; settled = true; clearTimeout(watchdog);
            if (!blob) { toast('PDF 產生失敗，改用快速列印'); printFallback(); return; }
            try { printPdfBlob(blob); }
            catch (e) { toast('列印 PDF 失敗，改用快速列印'); printFallback(); }
        },
        function (err) {
            if (settled) return; settled = true; clearTimeout(watchdog);
            console.warn('[EGdraw] PDF 產生失敗：', err);
            toast('產生列印 PDF 失敗(' + ((err && err.message) || err) + ')，改用快速列印'); printFallback();
        }
    );
}

/* 取得 pdfmake 產生的 PDF Blob，相容不同版本的 pdfmake：新版有 getBlob，舊版(本專案打包的版本)
   沒有 getBlob 只有 getBuffer / getBase64。依序挑一個可用的把 PDF 轉成 Blob 回呼，全都沒有才報錯。 */
function pdfToBlob(doc, cb, errCb) {
    try {
        if (typeof doc.getBlob === 'function') {
            doc.getBlob(function (b) { cb(b); });
        } else if (typeof doc.getBuffer === 'function') {
            doc.getBuffer(function (buf) { cb(new Blob([buf], { type: 'application/pdf' })); });
        } else if (typeof doc.getBase64 === 'function') {
            doc.getBase64(function (b64) {
                const bin = atob(b64), arr = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
                cb(new Blob([arr], { type: 'application/pdf' }));
            });
        } else {
            errCb(new Error('pdfmake 無可用輸出方法(getBlob/getBuffer/getBase64)'));
        }
    } catch (e) { errCb(e); }
}

// 隱藏 iframe 建立小工具：不呼叫 window.open，所以在 bom_viewer 用 window.open 開出的彈出視窗裡
// 也不會被瀏覽器攔截（彈窗內再開彈窗、或逾時後失去使用者手勢，最容易被擋而變成「按了沒反應」）。
function makeHiddenPrintFrame(cleanup) {
    const ifr = document.createElement('iframe');
    ifr.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(ifr);
    setTimeout(function () {                 // 保底清理：afterprint 未必觸發
        if (cleanup) cleanup();
        if (ifr.parentNode) ifr.parentNode.removeChild(ifr);
    }, 120000);
    return ifr;
}

/* 把 pdfmake 產生的 PDF Blob 以隱藏 iframe 送印：PDF 載入完成(onload)才送印。 */
function printPdfBlob(blob) {
    const objUrl = URL.createObjectURL(blob);
    const ifr = makeHiddenPrintFrame(function () { URL.revokeObjectURL(objUrl); });
    ifr.onload = function () {
        setTimeout(function () { try { ifr.contentWindow.focus(); ifr.contentWindow.print(); } catch (e) {} }, 200);
    };
    ifr.src = objUrl;
}

/* 依目前列印範圍(整個畫布或選取物件)的長寬，回傳紙張方向字串：寬>=高＝橫向 landscape。
   讓後備(PNG/向量)列印跟 PDF 路徑一樣自動判定直/橫，不會把橫圖硬塞進直式頁。 */
function currentPaperSize() {
    const base = ((document.getElementById('ex-paper') || {}).value === 'A3') ? 'A3' : 'A4';
    let w = artW, h = artH;
    if ((document.getElementById('ex-range') || {}).value === 'selection') {
        const obj = canvas.getActiveObject();
        if (obj) { const b = obj.getBoundingRect(true, true); w = b.width; h = b.height; }
    }
    return base + (w >= h ? ' landscape' : ' portrait');
}

/* 後備列印：把畫布直接輸出成 PNG，一樣走隱藏 iframe（不呼叫 window.open），確保彈出視窗內也印得出來。
   靠 <img onload> 等圖真的載入完才送印，避免印出空白。 */
function printFallback() {
    let url;
    try { url = buildExportURL('png'); }
    catch (e) { toast('列印失敗：畫布轉圖發生問題（F12 有詳情）'); return; }
    const blob = dataURLtoBlob(url);
    const objUrl = URL.createObjectURL(blob);
    const paper = currentPaperSize();
    const ifr = makeHiddenPrintFrame(function () { URL.revokeObjectURL(objUrl); });
    const d = ifr.contentWindow.document;
    d.open();
    // @page 指定紙張＋方向 auto；圖片同時限制寬高不超過一頁可列印範圍(max-height:96vh)＝自動縮成一頁
    d.write('<!DOCTYPE html><html><head><title>列印 - 批圖</title>' +
        '<style>@page{size:' + paper + '; margin:8mm;}html,body{margin:0;padding:0;height:100%;}' +
        'img{display:block;margin:0 auto;max-width:100%;max-height:96vh;width:auto;height:auto;}</style>' +
        '</head><body><img src="' + objUrl + '" onload="setTimeout(function(){window.focus();window.print();},150)"></body></html>');
    d.close();
}

/* 清晰列印：以向量 SVG 輸出。文字/線條在 SVG 內仍是向量，列印時瀏覽器直接以印表機解析度描繪，
   放大到 A4 也不會像點陣圖那樣糊；只有底圖照片是嵌入的點陣（維持原始解析度＝已是最佳）。
   相容性保險：極少數自訂圖形若 toSVG 失敗或顯示異常，攔截例外後退回快速（點陣）列印。 */
function doPrintVector() {
    const range = document.getElementById('ex-range').value;
    let x = artboard.left || 0, y = artboard.top || 0, w = artW, h = artH;
    if (range === 'selection') {
        const obj = canvas.getActiveObject();
        if (obj) { const b = obj.getBoundingRect(true, true); x = b.left; y = b.top; w = b.width; h = b.height; }
        else toast('沒有選取物件，改列印整個畫布');
    }
    const active = canvas.getActiveObject();
    canvas.discardActiveObject();
    const prevShadow = artboard.shadow;
    artboard.shadow = null;   // 陰影是編輯畫面用的，列印不要
    // 隱藏中的輔助線/物件（visible=false）暫時排除，避免被 toSVG 帶進列印
    const hidden = canvas.getObjects().filter(o => o.visible === false && !o.excludeFromExport);
    hidden.forEach(o => o.excludeFromExport = true);
    let svg;
    try {
        svg = canvas.toSVG({
            viewBox: { x: x, y: y, width: w, height: h },
            width: w, height: h, suppressPreamble: true
        });
    } catch (err) {
        hidden.forEach(o => o.excludeFromExport = false);
        artboard.shadow = prevShadow;
        if (active) canvas.setActiveObject(active);
        canvas.requestRenderAll();
        toast('此圖含無法向量化的圖形，改用快速列印');
        printFallback();
        return;
    }
    hidden.forEach(o => o.excludeFromExport = false);
    artboard.shadow = prevShadow;
    if (active) canvas.setActiveObject(active);
    canvas.requestRenderAll();
    // 去掉 XML 宣告與 DOCTYPE 前綴，只留 svg 標籤內嵌進列印文件（內嵌才會被當向量列印）
    const i = svg.indexOf('<svg');
    if (i > 0) svg = svg.slice(i);
    const paper = currentPaperSize();
    const win = window.open('', '_blank');
    if (!win) { toast('列印視窗被瀏覽器攔截，請允許彈出視窗'); return; }
    // body onload 會等 SVG 內嵌照片載入完再列印；不放 <script> 以免污染外層頁面解析
    // @page 指定紙張＋方向 auto；svg 限制寬高不超過一頁(max-height:96vh)＝自動縮成一頁
    win.document.write('<!DOCTYPE html><html><head><title>列印 - 批圖</title>' +
        '<style>@page{size:' + paper + '; margin:8mm;}html,body{margin:0;padding:0;height:100%;}' +
        'svg{display:block;margin:0 auto;max-width:100%;max-height:96vh;width:auto;height:auto;}</style>' +
        '</head><body onload="setTimeout(function(){window.focus();window.print();},250)">' +
        svg + '</body></html>');
    win.document.close();
    hideModal('export-modal');
}

/* ── 料號附件：儲存（壓平PNG＋工作檔）與開啟工作檔 ── */
function openPartModal() {
    // 檔名預設：有帶入料號就直接用料號當檔名（現場慣例本來就是打料號當檔名），沒有才退回時間戳記
    document.getElementById('pf-name').value = PRELOAD_PART_NO || defaultFileName();
    document.getElementById('pf-scope').value = 'dept';
    document.getElementById('pf-no-workfile').checked = false;
    document.getElementById('pf-save-status').textContent = '';
    document.querySelectorAll('.pf-cat').forEach(c => { c.checked = false; });
    const _pfIss = document.getElementById('pf-issue-date'); if (_pfIss) _pfIss.value = '';
    document.getElementById('pf-revision').value = '';
    pfRenderRevHint();
    document.getElementById('pf-mult').value = '2';   // 每次開窗回到建議值，避免上次調低後忘了調回去
    pfRenderMultHint();
    pfOnNoWorkfileChange();
    pfRenderCatHint();
    pfShareSelected = new Set();
    document.getElementById('pf-share-q').value = '';
    const pd = document.getElementById('pf-dept');
    pd.innerHTML = MY_DEPTS.map(d => '<option value="' + d.id + '">' + escHtml(d.name) + '</option>').join('');
    if (MY_MAIN_DEPT_ID) pd.value = String(MY_MAIN_DEPT_ID);   // 預設主要職務部門
    pfOnScopeChange();
    showModal('partfile-modal');
    if (PRELOAD_PART_NO) {
        document.getElementById('pf-q').value = PRELOAD_PART_NO;
        pfSearch(true);
    } else {
        document.getElementById('pf-q').focus();
    }
}
/* 附件標籤：邊選邊回饋（錯誤即時顯示原因，CLAUDE.md 表單三總則③） */
function pfSelectedCats() {
    return Array.from(document.querySelectorAll('.pf-cat:checked')).map(c => c.value);
}
function pfTodayStr() {
    const d = new Date();
    return d.getFullYear() + '-' + ('0'+(d.getMonth()+1)).slice(-2) + '-' + ('0'+d.getDate()).slice(-2);
}
/** 勾「只存圖片（不建立工作檔）」＋標籤有「自家出的圖」才要發行章日期；回傳是否通過驗證。
 *  有建立工作檔＝暫存檔（工作檔在料號附件看不到、下次存檔就被取代），不填發行章日期也不判定圖面變更。
 *  只在標籤勾選變動（欄位剛顯示）時預設今天，
 *  不要掛在日期欄自己的 onchange 上——不然打字打到一半、瀏覽器對未完成日期觸發 change 給空字串，
 *  會被這裡的「空值補今天」蓋掉使用者正在打的內容，變成畫面上的日期一直跳掉。 */
function pfSyncIssueRow() {
    const noWf = document.getElementById('pf-no-workfile').checked;
    const need = noWf && Array.from(document.querySelectorAll('.pf-cat:checked')).some(c => c.dataset.own === '1');
    const row = document.getElementById('pf-issue-row'), inp = document.getElementById('pf-issue-date');
    row.style.display = need ? '' : 'none';
    if (!need) return true;
    if (!inp.value) inp.value = pfTodayStr();          // 預設今天，可改
    return pfValidateIssueDate();
}
/** 日期欄本身 onchange：只驗證＋更新提示文字，不動 value（避免蓋掉使用者輸入中的內容） */
function pfOnIssueDateChange() { pfValidateIssueDate(); }
function pfValidateIssueDate() {
    const inp = document.getElementById('pf-issue-date'), hint = document.getElementById('pf-issue-hint');
    if (inp.value && inp.value > pfTodayStr()) {
        inp.style.borderColor = '#ff8a80';
        hint.style.color = '#ff8a80'; hint.textContent = '發行章日期不可以是未來日期';
        return false;
    }
    inp.style.borderColor = '';
    hint.style.color = '#8b949e';
    hint.textContent = '存檔後會跟這個料號既有的自家圖面比對：比較新＝圖面變更（請到料號附件頁或圖面變更紀錄頁登錄變更內容）。';
    return true;
}
function pfRenderCatHint() {
    const sel = pfSelectedCats(), hint = document.getElementById('pf-cat-hint'), box = document.getElementById('pf-cats');
    if (sel.length) {
        const names = Array.from(document.querySelectorAll('.pf-cat:checked')).map(c => c.parentElement.textContent.trim());
        hint.style.color = '#7ed957';
        hint.textContent = '已選：' + names.join('、');
        box.style.borderColor = '#45494f';
    } else {
        hint.style.color = '#ff8a80';
        hint.textContent = '尚未選擇標籤——存下去會變成分不出圖面／報價的無標籤附件';
        box.style.borderColor = '#ff8a80';
    }
    pfSyncIssueRow();
}
/* 範圍切換：部門→顯示部門下拉；指定人員→顯示搜尋+勾選名單（延遲載入使用者清單） */
let pfAllUsers = null, pfShareSelected = new Set();
function pfOnScopeChange() {
    const scope = document.getElementById('pf-scope').value;
    document.getElementById('pf-dept').style.display = (scope === 'dept') ? '' : 'none';
    document.getElementById('pf-share-box').style.display = (scope === 'custom') ? '' : 'none';
    if (scope === 'custom' && !pfAllUsers) loadPfShareUsers();
}
function pfOnNoWorkfileChange() {
    const noWf = document.getElementById('pf-no-workfile').checked;
    document.getElementById('pf-scope-box').style.display = noWf ? 'none' : '';
    pfSyncIssueRow();   // 切到「只存圖片」＝正式成品圖才要發行章日期；切回建工作檔要把欄位收掉
    document.getElementById('pf-save-hint').innerHTML = noWf
        ? '只存<b>壓平 PNG</b> 到料號附件（上面選的<b>附件標籤掛在它身上</b>），不產生 .egwork.json 工作檔，之後不能再用批圖編輯器打開重改。<b>這條路徑才是「正式出圖」</b>：標籤屬於自家出的圖時要填發行章日期，並會做圖面變更判定。'
        : '會存兩個附件：<b>壓平 PNG</b>＋<b>工作檔 .egwork.json</b>（用下方「開啟」重新載入後，標籤/文字/球標全部仍可編輯）。<b>這條路徑存出來的東西＝暫存，只有在這個編輯器裡看得到</b>——連壓平 PNG 也<b>不會出現在料號附件、圖面查閱、外來文件清單</b>等任何其他頁面（不分登入者，避免被當成正式圖面造成誤會）。也因此不填發行章日期、不做圖面變更判定；<b>要正式出圖請勾「只存圖片，不建立工作檔」</b>。分享範圍只管「誰能開這份工作檔繼續編輯」，沒有「全公司共用」，避免所有人都能改到；同一料號最多保留 null 份，超過會自動刪掉最舊的一份（不影響剛存好的這份，也不會刪到圖）。';
}
async function loadPfShareUsers() {
    document.getElementById('pf-share-list').innerHTML = '載入中…';
    try {
        const fd = new FormData(); fd.append('action', 'list_users_for_share');
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        pfAllUsers = res.users;
        pfRenderShareUsers();
    } catch (e) { document.getElementById('pf-share-list').innerHTML = '<span style="color:#ff8a80;">載入失敗：' + escHtml(e.message || '') + '</span>'; }
}
function pfRenderShareUsers() {
    if (!pfAllUsers) return;
    const q = document.getElementById('pf-share-q').value.trim().toLowerCase();
    const list = q ? pfAllUsers.filter(u => (u.user_cname || '').toLowerCase().includes(q)) : pfAllUsers;
    const box = document.getElementById('pf-share-list');
    box.innerHTML = list.length ? list.map(u => {
        const dp = u.dept_name ? ('<span style="color:#8b949e;">（' + escHtml(u.dept_name) + (u.pos_name ? ' ' + escHtml(u.pos_name) : '') + '）</span>') : '';
        return '<label style="display:flex;align-items:center;gap:5px;width:100%;margin:3px 0;font-size:12.5px;cursor:pointer;">' +
            '<input type="checkbox" data-uid="' + u.id + '"' + (pfShareSelected.has(Number(u.id)) ? ' checked' : '') +
            ' onchange="this.checked ? pfShareSelected.add(' + u.id + ') : pfShareSelected.delete(' + u.id + ')">' +
            escHtml(u.user_cname || ('#' + u.id)) + dp + '</label>';
    }).join('')
        : '<span style="color:#8b949e;font-size:12px;">查無符合的人員（可能是對方沒有批圖編輯器使用權）</span>';
}
let pfPartsMap = {};   // d_id -> D_Setting_Id，搜尋後供 pfApplyPartToName() 把檔名帶成料號
async function pfSearch(auto) {
    const q = document.getElementById('pf-q').value.trim();
    if (!q) { if (!auto) toast('請輸入料號或圖號關鍵字'); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'part_search'); fd.append('q', q);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        const sel = document.getElementById('pf-part');
        pfPartsMap = {};
        res.parts.forEach(p => { pfPartsMap[p.d_id] = p.D_Setting_Id; });
        sel.innerHTML = res.parts.length
            ? res.parts.map(p => '<option value="' + p.d_id + '">' + escHtml(p.D_Setting_Id + (p.Drawing_No ? '｜' + p.Drawing_No : '')) + '</option>').join('')
            : '<option value="">查無符合料號</option>';
        // 自動帶入時（從圖面檢視開啟）：料號可能對到多筆不同客戶，優先選「料號完全相同」那筆，
        // 而不是搜尋結果預設選到的第一筆（LIKE 排序不保證是同一顆料號）
        if (auto && PRELOAD_PART_NO) {
            const exact = res.parts.find(p => p.D_Setting_Id === PRELOAD_PART_NO);
            if (exact) sel.value = String(exact.d_id);
        }
        pfApplyPartToName();   // 搜尋出結果後，檔名自動帶成選到的料號（現場慣例打料號當檔名，見 pf-name 預設註解）
        pfLoadWorkfiles();
    } catch (e) { if (!auto) toast('搜尋失敗：' + (e.message || '')); }
}
/** 搜尋/切換選到的料號時，把「檔名」自動帶成該料號（使用者可再手動改） */
function pfApplyPartToName() {
    const partNo = pfPartsMap[document.getElementById('pf-part').value];
    if (partNo) document.getElementById('pf-name').value = partNo;
}
/* 製程標籤候選：換料號就重載。分兩組——「此料號用過的」放前面（＝使用者要的每料號自動記憶），
   「此料號訂單的加工項目」放後面。選項一多就靠共用檔的 data-eg-filter 打字篩選。 */
async function pfLoadProcTags() {
    const sel = document.getElementById('pf-proc');
    if (!sel) return;
    const keep = sel.value;
    const d = document.getElementById('pf-part').value;
    sel.innerHTML = '<option value="">（共用，不分製程）</option>';
    if (!d) { pfRenderProcHint(); return; }
    try {
        const fd = new FormData();
        fd.append('action', 'process_candidates'); fd.append('d_id', d);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        const used = (res.items || []).filter(i => i.source === 'used');
        const ord  = (res.items || []).filter(i => i.source !== 'used');
        const addGroup = (label, arr) => {
            if (!arr.length) return;
            const g = document.createElement('optgroup');
            g.label = label;
            arr.forEach(i => { const o = document.createElement('option'); o.value = i.value; o.textContent = i.value; g.appendChild(o); });
            sel.appendChild(g);
        };
        addGroup('此料號已經用過', used);
        addGroup('此料號的訂單加工項目', ord);
        if (keep) { for (const o of sel.options) { if (o.value === keep) { sel.value = keep; break; } } }
    } catch (e) { /* 候選載不到不擋存檔，留「共用」即可 */ }
    pfRenderProcHint();
}
function pfRenderProcHint() {
    const sel = document.getElementById('pf-proc'), hint = document.getElementById('pf-proc-hint');
    if (!sel || !hint) return;
    const n = sel.querySelectorAll('option').length - 1;   // 扣掉「共用」
    if (sel.value) {
        hint.style.color = '#7ed957';
        hint.textContent = '這張圖屬於「' + sel.value + '」；只會跟同料號、同標籤、同製程（或未指定製程的共用圖）比新舊版。';
    } else {
        hint.style.color = '#8b949e';
        hint.textContent = n > 0
            ? '選填。留空＝共用圖，會跟這個料號同標籤的所有製程一起比新舊版；不同加工項目各自一張圖時請選製程。'
            : '此料號的訂單還沒有加工項目可選，留空＝共用圖。';
    }
}
const PF_SCOPE_LABEL = { private: '私人', dept: '部門共用', custom: '指定人員', company: '公司共用（舊資料）' };
async function pfLoadWorkfiles() {
    const d = document.getElementById('pf-part').value;
    const box = document.getElementById('pf-works-list');
    if (!d) { box.innerHTML = '選料號後自動列出'; return; }
    box.innerHTML = '載入中…';
    try {
        const fd = new FormData();
        fd.append('action', 'list_workfiles'); fd.append('d_id', d);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        box.innerHTML = res.works.length ? res.works.map(w =>
            '<div style="display:flex;align-items:center;gap:6px;padding:4px 2px;border-bottom:1px solid #333;">' +
            '<span style="flex:1;">' + escHtml((w.original_name || '').replace('.egwork.json', '')) + '｜' + escHtml(w.uploaded_at || '') + '｜' + escHtml(w.uploaded_by || '') +
            '　<b style="color:#6fc3ff;">' + (PF_SCOPE_LABEL[w.scope] || w.scope) + '</b>' + (w.is_latest ? ' <b style="color:#7ed957;">（最新）</b>' : '') + '</span>' +
            '<button class="tb-btn" onclick="pfOpenWork(' + w.id + ')" title="開啟"><i class="fa fa-folder-open"></i></button>' +
            (w.can_delete ? '<button class="tb-btn" style="color:#ff8a80;" onclick="pfDeleteWork(' + w.id + ')" title="刪除"><i class="fa fa-trash"></i></button>' : '') +
            '</div>').join('')
            : '<span style="color:#8b949e;">此料號尚無你看得到的批圖工作檔</span>';
    } catch (e) { box.innerHTML = '載入失敗'; toast('工作檔列表載入失敗：' + (e.message || '')); }
}
function nowTimeStr() {
    const d = new Date();
    return ('0'+d.getHours()).slice(-2) + ':' + ('0'+d.getMinutes()).slice(-2) + ':' + ('0'+d.getSeconds()).slice(-2);
}
/* 壓平圖存檔倍率：文字/線條等向量物件放大幾倍都清晰，但底圖若是掃描/拍照的相片，
   原本就有的雜訊、暈影會被同比放大變得明顯（2026-08-07 使用者回報手寫字旁出現陰影狀瑕疵）。
   做法：先算「一般畫布用 3 倍、超大畫布降到 2 倍（單邊超過 8192px 上傳體積會爆量）」這個上限，
   再另外抓畫布上面積最大那張相片的「原生像素／目前顯示尺寸」比率當作天花板——相片本身沒有
   的細節，放大倍率不該超過它原生就有的解析度，超過只是把既有瑕疵等比放大，不是加畫質。
   小張的貼圖/圖示（面積 <30% 畫布）忽略，只看主要底圖，避免被小圖拖累整體倍率。 */
/* 存進料號附件的輸出倍率。
   ★2026-08-20 改寫：原本會用「底圖原始像素 ÷ 畫布顯示尺寸」(srcCap) 把倍率壓回 1×，理由是
   「輸出不必超過來源解析度，放大也長不出新細節」。但實測列印結果不成立——使用者拿同一張圖比對
   「另存圖片(匯出跳窗預設 2×) → 再上傳料號附件」與「直接存料號附件」兩份檔案（6682×4834 vs
   3341×2417），前者印出來明顯銳利。原因有兩個：
     ① 畫上去的標籤/文字/圈選/遮蓋都是向量，2× 才有 2 倍的邊緣取樣，1× 的斜線與細字會有鋸齒；
     ② 列印時若圖的像素密度低於印表機所需（A3 橫式 3341px ≈ 202dpi），是由瀏覽器/印表機往上
        內插放大，掃描圖的細線會被糊成灰邊；先輸出到 2× 再讓它往下取樣，邊緣乾淨得多。
   故改為「使用者在跳窗選的倍率」（預設 2×＝與另存圖片一致），只保留單邊 8192px 的 canvas 上限保護。 */
function computeSaveMult() {
    const want = parseFloat((document.getElementById('pf-mult') || {}).value) || 2;
    const cap = Math.max(1, 8192 / Math.max(artW, artH, 1));   // 超過瀏覽器 canvas 上限會直接轉圖失敗
    return Math.max(1, Math.min(want, cap));
}
/* 版次即時檢查：唯一規則是長度上限 50（欄位型別 varchar(50)），超過就紅字說明原因。
   maxlength 擋得住鍵盤輸入，但貼上／自動填入仍可能超長，所以照樣驗一次；後端另有同規則。 */
function pfRenderRevHint() {
    const inp = document.getElementById('pf-revision'), hint = document.getElementById('pf-rev-hint');
    if (!inp || !hint) return true;
    const v = inp.value.trim();
    if (v.length > 50) {
        inp.style.borderColor = '#ff8a80';
        hint.style.color = '#ff8a80';
        hint.textContent = '版次最多 50 個字（目前 ' + v.length + ' 個）';
        return false;
    }
    inp.style.borderColor = '';
    hint.style.color = '#8b949e';
    hint.textContent = '選填。填了會掛在壓平 PNG 上（工作檔不掛），附件清單會顯示 Rev. 標籤。';
    return true;
}
/* 倍率提示：即時顯示實際輸出像素、被 8192 上限壓下來時要講原因，並粗估檔案大小（線圖 PNG 約 0.8 bytes/px） */
function pfRenderMultHint() {
    const hint = document.getElementById('pf-mult-hint');
    if (!hint) return;
    const want = parseFloat(document.getElementById('pf-mult').value) || 2;
    const mult = computeSaveMult();
    const w = Math.round(artW * mult), h = Math.round(artH * mult);
    const mb = (w * h * 0.8) / 1048576;
    let txt = '輸出 ' + w + '×' + h + ' px（約 ' + (mb < 1 ? mb.toFixed(1) : Math.round(mb)) + ' MB）';
    if (mult < want - 0.01) txt += '　※ 已達瀏覽器單邊 8192px 上限，實際只能用 ' + mult.toFixed(2) + '×';
    if (mult < 2) { hint.style.color = '#f0a24b'; txt += '　※ 低於 2× 時列印會比「另存圖片後再上傳」模糊'; }
    else hint.style.color = '#8b949e';
    hint.textContent = txt;
}
async function pfSave() {
    const d = document.getElementById('pf-part').value;
    if (!d) { toast('請先搜尋並選擇料號'); return; }
    const name = document.getElementById('pf-name').value.trim() || defaultFileName();
    const noWorkfile = document.getElementById('pf-no-workfile').checked;
    const scope = document.getElementById('pf-scope').value;
    if (!noWorkfile && scope === 'custom' && !pfShareSelected.size) { toast('請至少勾選一位要分享的人員，或改選別的範圍'); return; }
    const cats = pfSelectedCats();
    if (!cats.length) { pfRenderCatHint(); document.getElementById('pf-cats').scrollIntoView({block:'nearest'}); toast('請至少選擇一個附件標籤'); return; }
    if (!pfSyncIssueRow()) { document.getElementById('pf-issue-date').focus(); toast('請確認發行章日期'); return; }
    if (!pfRenderRevHint()) { document.getElementById('pf-revision').focus(); toast('版次太長，請縮短'); return; }
    const issueDate = (document.getElementById('pf-issue-row').style.display !== 'none')
                    ? (document.getElementById('pf-issue-date').value || '') : '';
    // 存檔要跑一段時間（大圖匯出＋上傳），期間按鈕維持在畫面上不消失、狀態列一路顯示到底──
    // 不能只靠會自動消失的 toast，使用者盯著跳窗容易錯過（見使用者回報）
    const btn = document.getElementById('pf-save-btn');
    const status = document.getElementById('pf-save-status');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 儲存中…';
    status.style.color = '#8b949e';
    status.textContent = '儲存中，請稍候…（大圖需要幾秒到幾十秒）';
    try {
        const pngMult = computeSaveMult();
        const png = exportRegionDataURL(artboard.left, artboard.top, artW, artH, 'png', pngMult);
        const fd = new FormData();
        fd.append('action', 'save_workfile');
        fd.append('d_id', d);
        fd.append('name', name);
        fd.append('png', png);
        fd.append('no_workfile', noWorkfile ? '1' : '');
        if (!noWorkfile) {
            fd.append('work', JSON.stringify(canvas.toJSON(SNAP_PROPS)));
            fd.append('scope', scope);
            fd.append('dept_id', document.getElementById('pf-dept').value || '0');
            fd.append('share_user_ids', JSON.stringify(Array.from(pfShareSelected)));
        }
        fd.append('category_ids', cats.join(','));
        fd.append('issue_stamp_date', issueDate);
        fd.append('revision', document.getElementById('pf-revision').value.trim());
        fd.append('process_tag', document.getElementById('pf-proc').value || '');
        const dcNote = dcLatestNote();
        if (dcNote.text) { fd.append('dc_summary', dcNote.text); fd.append('dc_number', String(dcNote.num)); }
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        const savedAt = nowTimeStr();
        toast(noWorkfile
            ? '已存入料號附件：壓平圖'
            : '已存入料號附件：壓平圖＋工作檔（底圖抽離 ' + (res.extracted || 0) + ' 張）' +
              (res.auto_removed ? '，並自動清掉 ' + res.auto_removed + ' 份超過保留上限的舊工作檔' : ''));
        status.style.color = '#7ed957';
        status.innerHTML = '<i class="fa fa-check-circle"></i> 已於 ' + savedAt + ' 儲存成功' + (noWorkfile ? '（僅圖片）' : '（圖片＋工作檔）');
        // 圖面變更判定：本頁不做登錄表單（欄位多、跳窗會蓋住畫布），
        // 改成問要不要自動建立→建成草稿→另開分頁到「圖面變更紀錄」頁補完（與料號附件上傳同一條路）
        const v = res.dwg_verdict || {};
        if (v.kind === 'change') {
            const ok = confirm('偵測到圖面變更\n\n這張圖的發行章日期（' + egFmtDate(v.issue_date) + '）比此料號現有最新的自家圖面（'
                + (v.prev_name||'') + '，' + egFmtDate(v.prev_date) + '）新。\n\n'
                + '要不要現在自動建立圖面變更紀錄？\n'
                + '（按「確定」會自動帶好料號、客戶、新舊發行日建成草稿，並另開分頁讓你補寫變更內容；\n'
                + '　按「取消」也沒關係，之後到料號附件跳窗按「自動換圖記錄」一樣建得出來）');
            if (ok) {
                const fd2 = new FormData();
                fd2.append('action', 'auto_dwg_change');
                fd2.append('d_id', d);   // d = pfSave() 開頭取的 pf-part 值（已確認非空）
                if (res.png_id) fd2.append('attachment_id', res.png_id);
                try {
                    const r2 = await fetch('image_editor.php', { method: 'POST', body: fd2 }).then(x => x.json());
                    if (r2 && r2.success) { toast(r2.message); window.open('../QC/drawing_change_log.php?id=' + r2.id, '_blank'); }
                    else { alert((r2 && r2.message) || '建立失敗'); }
                } catch (e2) { alert('建立失敗：' + (e2.message || '連線錯誤')); }
            }
        } else if (v.kind === 'change_logged') {
            // 同一次換圖已經有變更單（例：BOSS圖 先傳過了，這次傳的是同一張圖的 ++圖）→
            // 不再問一次，只告訴使用者去哪裡看，避免同一次換圖開出兩張單
            toast(v.message || '這一次換圖已經登錄過變更紀錄');
        } else if (v.kind === 'first') {
            toast('已記錄為首次發行（此料號第一張帶發行章日期的自家圖面）');
        }
        pfLoadWorkfiles();
    } catch (e) {
        toast('儲存失敗：' + (e.message || ''));
        status.style.color = '#ff8a80';
        status.innerHTML = '<i class="fa fa-times-circle"></i> ' + nowTimeStr() + ' 儲存失敗：' + escHtml(e.message || '');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> 儲存';
    }
}
async function pfOpenWork(wid) {
    if (!confirm('開啟工作檔會取代目前畫布內容（未儲存的變更會消失），確定？')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'load_workfile'); fd.append('id', wid);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        restoreState(res.work);
        undoStack = [res.work];
        redoStack = [];
        hideModal('partfile-modal');
        setTimeout(zoomFit, 200);
        toast('已開啟工作檔：' + (res.name || '') + '（所有標籤/文字仍可編輯）');
    } catch (e) { toast('開啟失敗：' + (e.message || '')); }
}
async function pfDeleteWork(wid) {
    if (!confirm('確定刪除這份批圖工作檔？刪除後無法復原。')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete_workfile'); fd.append('id', wid);
        const res = await fetch('image_editor.php', { method: 'POST', body: fd }).then(r => r.json());
        if (!res.success) throw new Error(res.message || '');
        toast('已刪除工作檔');
        pfLoadWorkfiles();
    } catch (e) { toast('刪除失敗：' + (e.message || '')); }
}

/* 預設資料夾 handle（IndexedDB，僅 HTTPS/localhost 可用；HTTP 下自動停用） */
function idb() {
    return new Promise((res, rej) => {
        const rq = indexedDB.open(DIRDB, 1);
        rq.onupgradeneeded = () => rq.result.createObjectStore('handles');
        rq.onsuccess = () => res(rq.result);
        rq.onerror = () => rej(rq.error);
    });
}
async function loadDirHandle() {
    if (!window.showDirectoryPicker) return null;
    try {
        const db = await idb();
        return await new Promise((res) => {
            const tx = db.transaction('handles', 'readonly').objectStore('handles').get('dir_' + USER_ID);
            tx.onsuccess = () => res(tx.result || null);
            tx.onerror = () => res(null);
        });
    } catch (e) { return null; }
}

/* ── 物件屬性列 ── */
function isTextEditing() {
    const o = canvas.getActiveObject();
    return !!(o && o.isEditing);
}
/* 顏色/粗細列只對「真的有邊框/填色可調」的物件（含群組、多選內含的子物件）有意義；圖片/文字/群組整體本身不算 */
function isStrokeable(o) {
    if (!o) return false;
    if ((o.type === 'group' || o.type === 'activeSelection') && o.getObjects) return o.getObjects().some(isStrokeable);
    return ['rect', 'ellipse', 'line', 'path', 'polygon', 'polyline'].includes(o.type);
}
function refreshPropbar() {
    const obj = canvas.getActiveObject();
    const sec = document.getElementById('sec-object');
    sec.classList.toggle('show', !!obj);
    const st = document.getElementById('st-sel');
    // 選取時上方顏色/粗細列改依「選到的物件類型」決定要不要出現，不是只看目前工具
    if (currentTool === 'select') {
        document.getElementById('sec-stroke').classList.toggle('show', isStrokeable(obj));
        // 選到直線/箭頭時顯示「端點」下拉並回填目前模式，改選＝就地互換（見 p-line-ends change 監聽）
        const canEnds = !!obj && isLineLike(obj) && !obj.dimKind && !obj.isDimGuide;
        document.getElementById('wrap-line-ends').style.display = canEnds ? '' : 'none';
        if (canEnds) {
            document.getElementById('p-line-ends').value = (obj.type === 'line') ? 'none'
                : (obj.getObjects().filter(c => c.type === 'triangle').length >= 2 ? 'both' : 'end');
        }
    }
    if (!obj) {
        // 沒選取＝色票顯示「新文字/標籤」的預設底色，讓使用者知道下一個要畫的物件會是什麼底色
        document.getElementById('p-textbg-on').checked = newTextBg.on;
        document.getElementById('p-textbg').value = newTextBg.color;
        st.textContent = '未選取'; return;
    }
    const b = obj.getBoundingRect(true, true);
    st.innerHTML = '選取 <b>' + (obj.type === 'activeSelection' ? obj.getObjects().length + ' 個物件' : objTypeName(obj)) +
        '</b>（' + Math.round(b.width) + '×' + Math.round(b.height) + '）';
    document.getElementById('p-scale').value = Math.round((obj.scaleX || 1) * 100);
    document.getElementById('p-angle').value = Math.round(isLineLike(obj) ? trueLineAngle(obj) : (obj.angle || 0));
    document.getElementById('p-opacity').value = Math.round((obj.opacity ?? 1) * 100);
    if (isStrokeable(obj)) {
        const strokedChild = (obj.type === 'group' || obj.type === 'activeSelection') && obj.getObjects
            ? (obj.getObjects().find(isStrokeable) || obj) : obj;
        document.getElementById('p-line-style').value = styleFromDashArray(strokedChild.strokeDashArray);
        // 外框色與粗細也一併回填成「選到的這個物件」目前的值：不回填的話滑桿還停在上一次的數字，
        // 選了一條 10 的線一拖滑桿會突然跳成 3（使用者回報 2026-09-02）
        const hxStroke = toHex(strokedChild.stroke);
        if (hxStroke) document.getElementById('p-stroke').value = hxStroke;
        const swNow = Math.round(strokedChild.strokeWidth || 0);
        if (swNow >= 1) {
            document.getElementById('p-width').value = Math.min(40, swNow);
            document.getElementById('p-width-v').textContent = document.getElementById('p-width').value;
        }
        // 回填「填色」目前狀態（拿選取內第一個有填色概念的形狀）
        let fillChild = null;
        eachInSelection(obj, o => { if (!fillChild && ['rect', 'ellipse', 'circle', 'polygon'].includes(o.type)) fillChild = o; return false; });
        if (fillChild) {
            const onNow = !!(fillChild.fill && fillChild.fill !== 'transparent');
            document.getElementById('p-fill-on').checked = onNow;
            if (onNow) { const hx = toHex(fillChild.fill); if (hx) document.getElementById('p-fill').value = hx; }
        }
    }
    document.getElementById('btn-group').textContent = (obj.type === 'group') ? '解散群組' : '群組';
    const epBtn = document.getElementById('btn-edit-points');
    epBtn.style.display = (['line', 'polyline', 'polygon', 'rect'].includes(obj.type) && !obj.isDimGuide) ? '' : 'none';
    epBtn.textContent = obj.__pointEditing ? '完成編輯' : '編輯端點';
    // 圓角滑桿：矩形（native rx/ry）與封閉/折線圖形（cornerRadius）可倒圓角
    const canCorner = ['rect', 'polygon', 'polyline'].includes(obj.type) && !obj.isDimGuide;
    document.getElementById('wrap-corner').style.display = canCorner ? '' : 'none';
    if (canCorner) {
        const cr = (obj.type === 'rect') ? Math.round(obj.rx || 0) : Math.round(obj.cornerRadius || 0);
        document.getElementById('p-corner').value = cr;
        document.getElementById('p-corner-v').textContent = cr;
    }
    const inPtEdit = !!obj.__pointEditing;
    document.getElementById('btn-poly-close').style.display = inPtEdit ? '' : 'none';
    document.getElementById('btn-poly-smooth').style.display = inPtEdit ? '' : 'none';
    if (inPtEdit) {
        document.getElementById('btn-poly-close').textContent = (obj.type === 'polygon') ? '打開' : '封閉';
        document.getElementById('btn-poly-smooth').textContent = obj.curved ? '取直' : '圓滑';
    }
    document.getElementById('btn-label-bg').style.display = (obj.labelSpec && !['image', 'tol'].includes(obj.labelSpec.kind)) ? '' : 'none';
    // 選到文字（含標籤/標註等群組裡的文字）時同步並顯示文字屬性區——底線/粗體/字級/底色對已建立的文字隨時可改
    const txt = firstTextIn(obj);
    if (currentTool === 'select') document.getElementById('sec-text').classList.toggle('show', !!txt);
    if (txt) {
        document.getElementById('p-textcolor').value = toHex(txt.fill) || '#d32f2f';
        document.getElementById('p-fontsize').value = Math.round(txt.fontSize * (txt.scaleX || 1));
        document.getElementById('p-bold').checked = (txt.fontWeight === 'bold');
        document.getElementById('p-underline').value = txt.underline ? (txt.doubleUnderline ? 'double' : 'single') : 'none';
        // 快速標籤/自組標籤：底色看的是底色矩形（邊框矩形）的填色，不是文字底色
        const bgBox = (obj.type === 'group' && obj.getObjects)
            ? (obj.getObjects().find(k => k.isLabelBgRect) || (obj.isQuickLabel ? obj.getObjects().find(k => k.type === 'rect') : null))
            : null;
        if (bgBox) {
            const onNow = !!(bgBox.fill && bgBox.fill !== 'transparent');
            document.getElementById('p-textbg-on').checked = onNow;
            if (onNow) document.getElementById('p-textbg').value = toHex(bgBox.fill) || '#fff59d';
        } else {
            document.getElementById('p-textbg-on').checked = !!txt.backgroundColor;
            if (txt.backgroundColor) document.getElementById('p-textbg').value = toHex(txt.backgroundColor) || '#fff59d';
        }
    }
}
/* 選取物件（含群組/多選遞迴）裡的第一個文字物件 */
function firstTextIn(o) {
    if (!o) return null;
    if (o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') return o;
    if ((o.type === 'group' || o.type === 'activeSelection') && o.getObjects) {
        for (const c of o.getObjects()) { const t = firstTextIn(c); if (t) return t; }
    }
    return null;
}
function objTypeName(o) {
    return ({ image: '圖片', 'i-text': '文字', textbox: '文字', rect: '矩形', ellipse: '橢圓', line: '直線',
              group: '群組', path: '手繪線', polygon: '遮蓋(不規則)', polyline: '折線' })[o.type] || o.type;
}
function toHex(c) {
    if (!c || typeof c !== 'string') return null;
    if (c[0] === '#') return c.length === 7 ? c : null;
    const m = c.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!m) return null;
    return '#' + [m[1], m[2], m[3]].map(n => (+n).toString(16).padStart(2, '0')).join('');
}
canvas.on('selection:created', refreshPropbar);
canvas.on('selection:updated', refreshPropbar);
canvas.on('selection:cleared', refreshPropbar);
canvas.on('object:modified', (e) => {
    // 縮放結束後強制重繪快取，避免 Fabric 預設的 noScaleCache 造成放大後文字/標籤模糊
    const t = e && e.target;
    if (t && t.type === 'group' && t.isArrowGroup &&
        (Math.abs((t.scaleX || 1) - 1) > 1e-4 || Math.abs((t.scaleY || 1) - 1) > 1e-4)) {
        // 箭頭被四角控制點縮放：整支依縮放後的頭尾座標重建，粗細維持不變（只改長度/方向），
        // 避免箭頭頭端被非等比拉扯而歪斜變形（trueArrowEndpoints 已含縮放矩陣，重建後 scale 歸零）
        const no = rebuildArrowGroup(t, {});
        canvas.setActiveObject(no);
        canvas.requestRenderAll();
        refreshPropbar(); pushState();
        return;
    }
    if (t) {
        t.dirty = true;
        if (t.getObjects) t.getObjects().forEach(o => { o.dirty = true; });
    }
    if (t && t.isDimGuide && t.dimAngleId) rebuildDimAngleArc(t.dimAngleId);
    canvas.requestRenderAll();
    refreshPropbar(); pushState();
});

/* 屬性 → 套用到選取物件 */
document.getElementById('p-scale').addEventListener('change', function () {
    const obj = canvas.getActiveObject(); if (!obj) return;
    const s = Math.max(1, parseFloat(this.value) || 100) / 100;
    obj.set({ scaleX: s, scaleY: s }); obj.setCoords();
    canvas.requestRenderAll(); pushState();
});
document.getElementById('p-angle').addEventListener('change', function () {
    const obj = canvas.getActiveObject(); if (!obj) return;
    let v = parseFloat(this.value) || 0;
    if (isLineLike(obj)) {
        // 直線/箭頭：0 度＝水平線，輸入值是「相對水平的目標角度」，換算成要疊加的旋轉量
        const delta = v - trueLineAngle(obj);
        obj.rotate((obj.angle || 0) + delta);
    } else {
        obj.rotate(v);   // 以物件中心旋轉
    }
    obj.setCoords();
    if (obj.isDimGuide && obj.dimAngleId) rebuildDimAngleArc(obj.dimAngleId);
    canvas.requestRenderAll(); pushState();
});
document.getElementById('p-opacity').addEventListener('input', function () {
    const obj = canvas.getActiveObject(); if (!obj) return;
    obj.set('opacity', (parseInt(this.value, 10) || 100) / 100);
    canvas.requestRenderAll();
});
/* 套用工具：把設定套到選取物（含多選 activeSelection 與群組內的子物件） */
function eachInSelection(obj, fn) {
    if (!obj) return 0;
    let n = 0;
    const visit = o => {
        fn(o) && n++;
        if ((o.type === 'group' || o.type === 'activeSelection') && o.getObjects) o.getObjects().forEach(visit);
    };
    visit(obj);
    return n;
}
document.getElementById('p-width').addEventListener('input', function () {
    document.getElementById('p-width-v').textContent = this.value;
    const v = parseInt(this.value, 10) || 3;
    if (canvas.isDrawingMode) canvas.freeDrawingBrush.width = v;
    const obj = canvas.getActiveObject();
    // 箭頭群組：拖動滑桿當下就即時改粗細（使用者回報 2026-09-02：不要放開才看到變化）。
    // 用 reshapeArrowGroup 就地換掉內部的線＋三角頭（同一個物件、不 remove/add、不重設選取），
    // 比 rebuildArrowGroup 輕很多，拖動中每一格都跑得動；放開滑桿（change）只需要記一步復原。
    if (obj && obj.type === 'group' && obj.isArrowGroup) {
        applyArrowGroupWidth(obj, v);
        canvas.requestRenderAll();
        return;
    }
    const headLen = arrowHeadLen(v);
    const n = eachInSelection(obj, o => {
        if (o.stroke && (o.type === 'line' || o.type === 'path' || o.type === 'rect' || o.type === 'ellipse' || o.type === 'circle' || o.type === 'polygon' || o.type === 'polyline')) {
            o.set('strokeWidth', v);
            // 虛線/中心線的間距是依粗細等比算的，改粗細時要一起重算，否則間距還停在舊粗細的比例
            if (o.strokeDashArray && o.strokeDashArray.length) {
                o.set('strokeDashArray', dashArrayFor(styleFromDashArray(o.strokeDashArray), v));
            }
            o.dirty = true; return true;
        }
        if (o.type === 'triangle') {   // 箭頭頭端：大小要跟著粗細一起變，不然線變粗頭還是原本那麼小
            o.set({ width: headLen, height: headLen }); o.setCoords(); o.dirty = true; return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); }
});
/* 箭頭群組就地改粗細：線與三角頭一起換成新粗細算出來的那一組（頭大小＝arrowHeadLen(粗細)），
   頭尾位置維持原本的箭頭尖端座標。回傳有沒有真的改到。 */
function applyArrowGroupWidth(g, v) {
    const aline = g.getObjects().find(c => c.type === 'line');
    if (!aline) return false;
    const pts = trueArrowEndpoints(g);   // 要先取，reshape 之後群組的 scale/angle 會被歸位
    if (aline.strokeDashArray && aline.strokeDashArray.length) {
        // 箭頭虛線也要依新粗細重算間距（否則變粗後虛線間距不跟著變）
        aline.set('strokeDashArray', dashArrayFor(styleFromDashArray(aline.strokeDashArray), v));
    }
    aline.set('strokeWidth', v);
    return reshapeArrowGroup(g, pts[0], pts[1]);   // 內部依 line.strokeWidth 重算三角頭大小與線段縮短量
}
document.getElementById('p-width').addEventListener('change', function () {
    // 拖動中（input）已經即時改好了，放開滑桿只記錄一步復原
    const obj = canvas.getActiveObject();
    if (obj && obj.type === 'group' && obj.isArrowGroup) { canvas.requestRenderAll(); pushState(); }
});
/* 圓角滑桿：矩形用 native rx/ry；封閉/折線圖形設 cornerRadius（由 renderRoundedPoly 描繪，
   節點資料不變仍可「編輯端點」）。拖動即時預覽（input），放開才記錄一步復原（change）。 */
(function () {
    const slider = document.getElementById('p-corner');
    function apply(commit) {
        const obj = canvas.getActiveObject();
        if (!obj) return;
        const v = parseInt(slider.value, 10) || 0;
        document.getElementById('p-corner-v').textContent = v;
        if (obj.type === 'rect') {
            const r = Math.min(v, Math.min(obj.width, obj.height) / 2);   // 夾在半個短邊內，避免溢出變形
            obj.set({ rx: r, ry: r });
        } else if (obj.type === 'polygon' || obj.type === 'polyline') {
            obj.set('cornerRadius', v);   // 每個角的實際半徑在 renderRoundedPoly 內再依邊長夾限
        } else return;
        obj.dirty = true;
        canvas.requestRenderAll();
        if (commit) pushState();
    }
    slider.addEventListener('input', function () { apply(false); });
    slider.addEventListener('change', function () { apply(true); });
})();
document.getElementById('p-line-style').addEventListener('change', function () {
    const v = this.value;
    if (canvas.isDrawingMode) canvas.freeDrawingBrush.strokeDashArray = dashArrayFor(v, canvas.freeDrawingBrush.width || 3);
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.stroke && (o.type === 'line' || o.type === 'path' || o.type === 'rect' || o.type === 'ellipse' || o.type === 'circle' || o.type === 'polygon' || o.type === 'polyline')) {
            o.set('strokeDashArray', dashArrayFor(v, o.strokeWidth || 3)); o.dirty = true; return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
/* 端點下拉：選取既有直線/箭頭時改選＝就地互換（線↔單箭頭↔雙箭頭，保留顏色/粗細/線型與位置） */
document.getElementById('p-line-ends').addEventListener('change', function () {
    const obj = canvas.getActiveObject();
    if (!obj || !isLineLike(obj) || obj.dimKind || obj.isDimGuide) return;
    const line = (obj.type === 'line') ? obj : obj.getObjects().find(c => c.type === 'line');
    if (!line) return;
    const pts = trueArrowEndpoints(obj);   // 箭頭群組的子線段有被縮短，要用箭頭尖端當真實頭尾
    const stroke = line.stroke || document.getElementById('p-stroke').value;
    const sw = line.strokeWidth || 3;
    const dashArr = line.strokeDashArray || null;
    const ends = this.value;
    canvas.remove(obj);
    let no;
    if (ends === 'none') {
        no = new fabric.Line([pts[0].x, pts[0].y, pts[1].x, pts[1].y], { stroke, strokeWidth: sw, strokeUniform: true, strokeDashArray: dashArr });
    } else {
        no = makeArrow(pts[0].x, pts[0].y, pts[1].x, pts[1].y, stroke, sw, ends, dashArr);
    }
    canvas.add(no);
    canvas.setActiveObject(no);
    canvas.requestRenderAll();
    pushState();
});
document.getElementById('p-stroke').addEventListener('input', function () {
    if (canvas.isDrawingMode) canvas.freeDrawingBrush.color = this.value;
    const v = this.value;
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.stroke || o.type === 'path') { o.set('stroke', v); o.dirty = true; return true; }
        if (o.type === 'triangle') { o.set('fill', v); o.dirty = true; return true; }   // 箭頭頭端
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); }
});
/* 填色：原本只在「畫新形狀」當下取值，選取既有形狀改填色沒反應——補上套用到選取物（含標籤框） */
function applyFillToSelection(withHistory) {
    const on = document.getElementById('p-fill-on').checked;
    const v = on ? document.getElementById('p-fill').value : 'transparent';
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.type === 'triangle') return false;   // 箭頭頭端填色跟線色連動，由「顏色」欄管
        if (['rect', 'ellipse', 'circle', 'polygon'].includes(o.type)) {
            o.set('fill', v); o.dirty = true;
            if (o.group) o.group.dirty = true;
            if (o.group && o.group.labelSpec && (o.isLabelBgRect || o.group.isQuickLabel)) o.group.labelSpec.bg = on ? v : 'transparent';   // 標籤框＝底色，記回 spec
            return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); if (withHistory) pushState(); }
}
document.getElementById('p-fill').addEventListener('input', function () { applyFillToSelection(false); });
document.getElementById('p-fill').addEventListener('change', function () { applyFillToSelection(true); });
document.getElementById('p-fill-on').addEventListener('change', function () { applyFillToSelection(true); });
document.getElementById('p-textcolor').addEventListener('input', function () {
    const v = this.value;
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') { o.set('fill', v); o.dirty = true; return true; }
        return false;
    });
    // 規格標籤把顏色記進 spec，之後雙擊改字重建不掉色
    if (obj && obj.labelSpec && !['fabric', 'image', 'multi'].includes(obj.labelSpec.kind)) obj.labelSpec.color = v;
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); }
});
document.getElementById('p-fontsize').addEventListener('change', function () {
    const obj = canvas.getActiveObject();
    const v = Math.max(6, parseInt(this.value, 10) || 28);
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox') { o.set({ fontSize: v, scaleX: 1, scaleY: 1 }); o.setCoords(); o.dirty = true; return true; }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
document.getElementById('p-bold').addEventListener('change', function () {
    const obj = canvas.getActiveObject();
    const bold = this.checked;
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox') { o.set('fontWeight', bold ? 'bold' : 'normal'); o.dirty = true; return true; }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
function applyTextBg() {
    const obj = canvas.getActiveObject();
    const on = document.getElementById('p-textbg-on').checked;
    let bg = document.getElementById('p-textbg').value;
    if (!obj) {   // 沒選取物件＝設定「新文字/標籤」的預設底色，不動任何既有物件
        newTextBg = { on: on, color: bg };
        return;
    }
    // 勾選加底色時色票若是白色且文字原本沒底色，改用預設黃色：白色色票多半是先前
    // 點選過標註文字（內建白底）被同步留下的，白底疊在白圖紙上看不出來，使用者會以為勾了沒效
    if (on && bg.toLowerCase() === '#ffffff' && !(obj && (obj.isQuickLabel || obj.labelKind === 'fabric'))) {
        // 快速標籤/自組標籤有邊框或底色矩形，白底看得出來，不需要改黃
        const t = firstTextIn(obj);
        if (t && !t.backgroundColor) {
            bg = '#fff59d';
            document.getElementById('p-textbg').value = bg;
        }
    }
    const n = eachInSelection(obj, o => {
        // 快速標籤（文字工具的「標籤」）：底色＝邊框矩形的填色，不是文字底色（改文字底色看起來像沒反應）
        if (o.isQuickLabel && o.type === 'group' && o.getObjects) {
            const box = o.getObjects().find(k => k.type === 'rect');
            const t = o.getObjects().find(k => k.type === 'i-text' || k.type === 'text');
            if (box) {
                box.set('fill', on ? bg : 'transparent'); box.dirty = true;
                if (t && t.backgroundColor) { t.set('backgroundColor', ''); t.dirty = true; }   // 清掉先前誤設在文字上的底色
                o.dirty = true;
                if (o.labelSpec) o.labelSpec.bg = on ? bg : 'transparent';
                return true;
            }
        }
        // 自組（fabric）標籤的底色矩形
        if (o.isLabelBgRect) {
            o.set('fill', on ? bg : 'transparent'); o.dirty = true;
            if (o.group) { o.group.dirty = true; if (o.group.labelSpec) o.group.labelSpec.bg = on ? bg : 'transparent'; }
            return true;
        }
        if (o.isQuickLabel) return false;   // 已在上面整組處理，別再讓遞迴改到裡面的文字
        if ((o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') && !(o.group && o.group.isQuickLabel)) {
            o.set('backgroundColor', on ? bg : ''); o.dirty = true; return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
}
document.getElementById('p-underline').addEventListener('change', function () {
    const v = this.value;
    const obj = canvas.getActiveObject();
    const n = eachInSelection(obj, o => {
        if (o.type === 'i-text' || o.type === 'textbox' || o.type === 'text') {
            o.set('underline', v !== 'none'); o.doubleUnderline = (v === 'double'); o.dirty = true; return true;
        }
        return false;
    });
    if (n) { if (obj.type === 'group') obj.dirty = true; canvas.requestRenderAll(); pushState(); }
});
document.getElementById('p-textbg').addEventListener('input', applyTextBg);
document.getElementById('p-textbg-on').addEventListener('change', applyTextBg);
document.getElementById('p-maskcolor').addEventListener('input', function () {
    const obj = canvas.getActiveObject();
    if (obj && obj.type === 'polygon') { obj.set('fill', this.value); canvas.requestRenderAll(); }
});

/* ── 鎖定（Figma 式）：鎖住底圖等物件，點擊會穿透不再誤選 ── */
function lockSelection() {
    const obj = canvas.getActiveObject();
    if (!obj) { toast('請先選取要鎖定的物件（例如底圖）'); return; }
    const targets = (obj.type === 'activeSelection') ? obj.getObjects().slice() : [obj];
    canvas.discardActiveObject();
    targets.forEach(o => { o.locked = true; o.selectable = false; o.evented = false; });
    canvas.requestRenderAll();
    updateLockUI();
    pushState();
    toast('已鎖定 ' + targets.length + ' 個物件（點擊會穿透）。要解開請按屬性列右側「解鎖全部」');
}
/* 座標被算壞（NaN/Infinity）的物件：看不見、選得到、每幀渲染出錯造成拖曳殘影——直接移除 */
function purgePoisonedObjects() {
    let n = 0;
    canvas.getObjects().slice().forEach(o => {
        if (o === artboard) return;
        if (!isFinite(o.left) || !isFinite(o.top) || !isFinite(o.width) || !isFinite(o.height) || !isFinite(o.scaleX) || !isFinite(o.scaleY)) {
            canvas.remove(o); n++;
        }
    });
    return n;
}
function unlockAll() {
    // 一鍵自救：解鎖正常鎖定物、救回「點不到又刪不掉」的殘留物、清掉座標壞掉的毒化物件
    const purged = purgePoisonedObjects();
    const locked = canvas.getObjects().filter(o => o !== artboard && (o.locked || o.selectable === false || o.evented === false) && !o.isDimGuide);
    locked.forEach(o => { o.locked = false; o.selectable = true; o.evented = true; });
    // 順手救回卡在隱形狀態的一般物件（框選搬移等流程暫時隱藏後若中途出錯沒還原，物件會看不見但佔位）
    let unhidden = 0;
    canvas.getObjects().forEach(o => {
        if (o !== artboard && !o.isDimGuide && o.visible === false) { o.visible = true; o.dirty = true; unhidden++; }
    });
    if (!purged && !locked.length && !unhidden) return;
    canvas.requestRenderAll();
    updateLockUI();
    pushState();
    toast('已解鎖 ' + locked.length + ' 個物件' + (purged ? '，並清除 ' + purged + ' 個損壞殘留物' : '') + (unhidden ? '，救回 ' + unhidden + ' 個隱形物件' : ''));
}
function updateLockUI() {
    const n = canvas.getObjects().filter(o => o.locked).length;
    document.getElementById('lock-info').style.display = n ? 'inline-flex' : 'none';
    document.getElementById('lock-count').textContent = n;
}

/* ── 浮水印：單一或自動間距填滿，預設鎖定避免誤點 ── */
function openWmModal() { showModal('wm-modal'); document.getElementById('wm-text').focus(); }
function removeWatermark() {
    const olds = canvas.getObjects().filter(o => o.wmRole);
    olds.forEach(o => canvas.remove(o));
    if (olds.length) { updateLockUI(); canvas.requestRenderAll(); pushState(); }
}
function applyWatermark() {
    const text = document.getElementById('wm-text').value.trim();
    if (!text) { toast('請輸入浮水印文字'); return; }
    const angle = parseInt(document.getElementById('wm-angle').value, 10) || 0;
    const mode = document.getElementById('wm-mode').value;
    const opacity = (parseInt(document.getElementById('wm-opacity').value, 10) || 15) / 100;
    const color = document.getElementById('wm-color').value;
    removeWatermark();

    const mk = (fs, x, y) => new fabric.Text(text, {
        left: x, top: y, originX: 'center', originY: 'center',
        fontSize: fs, fontFamily: LABEL_FONT, fontWeight: 'bold',
        fill: color, angle: angle, selectable: false, evented: false
    });
    const cx = artboard.left + artW / 2, cy = artboard.top + artH / 2;
    let wm;
    if (mode === 'single') {
        // 字級自動：寬度約佔畫布七成
        const probe = mk(100, 0, 0);
        const fs = Math.max(20, Math.min(100 * (artW * 0.7) / probe.width, artH * 0.6));
        wm = new fabric.Group([mk(fs, cx, cy)], {});
    } else {
        // 填滿：依字寬自動抓間距，奇偶列錯開半格
        const fs = Math.max(12, parseInt(document.getElementById('wm-size').value, 10) || 60);
        const probe = mk(fs, 0, 0);
        const dx = probe.width + fs * 1.6;
        const dy = probe.height + fs * 2.2;
        const items = [];
        let row = 0;
        for (let y = artboard.top - dy / 2; y < artboard.top + artH + dy; y += dy, row++) {
            const off = (row % 2) ? dx / 2 : 0;
            for (let x = artboard.left - dx / 2 + off; x < artboard.left + artW + dx; x += dx) {
                items.push(mk(fs, x, y));
            }
        }
        wm = new fabric.Group(items, {});
        // 裁掉超出畫布的部分，列印才乾淨
        wm.clipPath = new fabric.Rect({
            left: artboard.left, top: artboard.top, width: artW, height: artH, absolutePositioned: true
        });
    }
    wm.set({ opacity: opacity });
    wm.wmRole = 'wm';
    wm.locked = true; wm.selectable = false; wm.evented = false;   // 預設鎖定
    canvas.add(wm);
    updateLockUI();
    canvas.requestRenderAll();
    pushState();
    hideModal('wm-modal');
    toast('浮水印已套用（已自動鎖定；要調整請按屬性列右側「解鎖全部」）');
}

/* 圖層 / 群組 / 刪除 */
function layerCmd(cmd) {
    const obj = canvas.getActiveObject(); if (!obj) return;
    if (cmd === 'front') canvas.bringToFront(obj);
    if (cmd === 'forward') canvas.bringForward(obj);
    if (cmd === 'backward') canvas.sendBackwards(obj);
    if (cmd === 'back') canvas.sendToBack(obj);
    canvas.sendToBack(artboard); // 畫布永遠最底
    canvas.requestRenderAll(); pushState();
}
/* 合併：多物件 → 單一物件（雙擊不拆，Alt+雙擊才拆），縮放比例位置固定 */
function mergeSelection() {
    const obj = canvas.getActiveObject();
    if (!obj || obj.type !== 'activeSelection') { toast('請先框選或 Shift 點選要合併的多個物件'); return; }
    const g = obj.toGroup();
    g.merged = true;
    canvas.requestRenderAll();
    refreshPropbar();
    pushState();
    toast('已合併為單一物件（要拆開：Alt+雙擊）');
}
function groupCmd() {
    const obj = canvas.getActiveObject(); if (!obj) return;
    if (obj.type === 'group') {
        enterGroup(obj);
        return;
    } else if (obj.type === 'activeSelection') {
        const props = obj._regroupProps;
        const g = obj.toGroup();
        if (props && g) Object.assign(g, props);   // 由「進入群組」拆出的，重組時還原標籤屬性
    } else { toast('請以框選或 Shift 點選多個物件再群組'); return; }
    canvas.requestRenderAll(); refreshPropbar(); pushState();
}
/* Ctrl+A：全選畫布上的物件（含畫布外，排除底板/鎖定/浮水印鎖定物） */
function selectAllObjects() {
    setTool('select');
    // visible !== false：隱藏物件（例如角度標註的輔助線）不可被全選掃進來，會變成空的幽靈選取框
    const objs = canvas.getObjects().filter(o => o !== artboard && o.selectable !== false && !o.locked && o.visible !== false);
    if (!objs.length) { toast('畫布上沒有可選取的物件'); return; }
    canvas.discardActiveObject();
    const sel = new fabric.ActiveSelection(objs, { canvas: canvas });
    canvas.setActiveObject(sel);
    canvas.requestRenderAll();
    refreshPropbar();
}
function deleteSelection() {
    const obj = canvas.getActiveObject(); if (!obj) return;
    if (obj.__groupEditFor) {
        // 正在編輯標籤/標註的文字：按刪除＝把整組刪掉（不然只會刪到暫時編輯框，群組又被加回來，看起來永遠刪不掉）
        obj.__deleteGroup = true;
        obj.exitEditing();
        return;
    }
    const hadBalloon = (obj.balloonLetter || (obj.type === 'activeSelection' && obj.getObjects().some(o => o.balloonLetter)));
    const removed = (obj.type === 'activeSelection') ? obj.getObjects().slice() : [obj];
    removed.forEach(o => canvas.remove(o));
    canvas.discardActiveObject();
    // 角度標註整組連動刪除：刪標示→隱藏中的輔助線一併刪；刪輔助線→標示與另一條也一併刪
    const daIds = new Set(removed.map(o => o.dimAngleId).filter(Boolean));
    if (daIds.size) canvas.getObjects().slice().forEach(o => { if (o.dimAngleId && daIds.has(o.dimAngleId)) canvas.remove(o); });
    if (hadBalloon) updateBalloonSummary();  // 球標增減 → 右下角範圍文字自動重建
    canvas.requestRenderAll(); pushState();
}

/* ── Undo / Redo（JSON 快照） ──
   快照＝整張畫布序列化。debounce 150ms 合併連續動作；undo/redo 前先 flush 未寫入的快照。
   穩定性關鍵：貼上的底圖是數 MB 的 base64 dataURL，若每份快照都內含一份，
   每個動作都要同步 stringify 幾 MB 字串（畫面凍結），30 份快照更會撐爆分頁記憶體（整頁當掉）。
   → dataURL 只存一份進 IMG_SRC_POOL 共用池，快照裡只放「__imgpool:索引」占位，
     快照從數 MB 縮到幾 KB；還原時再換回原字串（同一個字串參照，不另占記憶體）。 */
let undoStack = [], redoStack = [], restoring = false, pushTimer = null;
const IMG_SRC_POOL = [];   // 本次開啟期間用過的大圖 dataURL，各存一份；快照/舊快照都可能引用，不做淘汰
function snapWalk(node, fn) {
    if (!node || typeof node !== 'object') return;
    fn(node);
    if (Array.isArray(node.objects)) node.objects.forEach(o => snapWalk(o, fn));
    if (node.backgroundImage) snapWalk(node.backgroundImage, fn);
    if (node.overlayImage) snapWalk(node.overlayImage, fn);
}
function snapPoolify(json) {   // 大 dataURL → 池索引占位（就地修改）
    snapWalk(json, n => {
        if (typeof n.src === 'string' && n.src.length > 2000 && n.src.slice(0, 5) === 'data:') {
            let idx = IMG_SRC_POOL.indexOf(n.src);
            if (idx === -1) { IMG_SRC_POOL.push(n.src); idx = IMG_SRC_POOL.length - 1; }
            n.src = '__imgpool:' + idx;
        }
    });
}
function snapUnpoolify(json) {   // 池索引占位 → 原 dataURL（沒有占位的舊快照/工作檔原樣通過）
    snapWalk(json, n => {
        if (typeof n.src === 'string' && n.src.slice(0, 10) === '__imgpool:') {
            const s = IMG_SRC_POOL[parseInt(n.src.slice(10), 10)];
            if (s) n.src = s;
        }
    });
}
const SNAP_PROPS = ['id', 'selectable', 'evented', 'locked', 'merged', 'balloonLetter', 'dcNumber', 'dcShape', 'dcRole', 'labelSpec', 'labelKind', 'specPath', 'wmRole', 'isArrowGroup', 'dimKind', 'isFreehandEnds', 'isQuickLabel', 'doubleUnderline', 'isDimGuide', 'dimAngleId', 'curved', 'cornerRadius', 'transparentBg', 'isLabelBgRect'];
/* 卡頓/當機診斷：主要耗時點超過門檻就在主控台留紀錄（回報問題時請開 F12 把紅字/黃字截圖）；
   未攔截的程式例外第一次發生時跳 toast 提醒——渲染迴圈被例外打斷正是「殘影＋卡死」的典型來源 */
let __egErrToasted = false;
window.addEventListener('error', function (ev) {
    console.warn('[EGdraw] 未攔截例外：', ev.message, ev.filename, ev.lineno);
    if (!__egErrToasted) {
        __egErrToasted = true;
        toast('偵測到程式例外（畫面可能出現殘影或卡頓）：' + (ev.message || '不明錯誤') + '——建議儲存後重新整理，並回報這則訊息');
    }
});
function __egSlow(op, t0) {
    const ms = Math.round(performance.now() - t0);
    if (ms > 300) console.warn('[EGdraw] ' + op + ' 耗時 ' + ms + 'ms');
}
function doPushState() {
    pushTimer = null;
    if (restoring) return;
    const __t0 = performance.now();
    try {
        const j = canvas.toJSON(SNAP_PROPS);
        snapPoolify(j);   // 大圖 dataURL 抽進共用池，快照只剩幾 KB，stringify 不再凍結畫面
        const snap = JSON.stringify(j);
        if (undoStack[undoStack.length - 1] === snap) return;   // 內容沒變就不疊快照
        undoStack.push(snap);
        redoStack = [];
        draftDirty = true;   // 內容有變＝下一輪自動暫存要重存
        // 上限用「總位元組」控管，不只份數（保險：載入工作檔的第一份快照仍是未池化的原始字串）
        let total = 0;
        for (let i = 0; i < undoStack.length; i++) total += undoStack[i].length;
        while ((undoStack.length > 30 || total > 120 * 1024 * 1024) && undoStack.length > 3) {
            total -= undoStack[0].length;
            undoStack.shift();
        }
    } catch (e) { /* 圖太大時快照失敗不影響操作 */ }
    __egSlow('undo快照', __t0);
}

/* ── 暫存檔（IndexedDB，這台電腦、依使用者區分）──
   暫存＝最新 undo 快照＋圖片池（快照本來就池化，存檔零額外編碼成本）。
   每人最多 5 件手動暫存（需命名，同名＝覆蓋更新）＋1 件「自動暫存」（內容有變每 60 秒與關窗前自動更新）。
   開啟編輯器不再自動詢問；按頂列「暫存」開跳窗：暫存目前畫布／開啟／個別刪除／清除全部。 */
let draftDirty = false;
function draftDb() {
    return new Promise((res, rej) => {
        const rq = indexedDB.open('egdraw_drafts', 1);
        rq.onupgradeneeded = () => rq.result.createObjectStore('draft');
        rq.onsuccess = () => res(rq.result);
        rq.onerror = () => rej(rq.error);
    });
}
function draftPut(val) {
    return draftDb().then(db => new Promise((res, rej) => {
        const tx = db.transaction('draft', 'readwrite');
        tx.objectStore('draft').put(val, 'u' + USER_ID);
        tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    }));
}
function draftGet() {
    return draftDb().then(db => new Promise((res, rej) => {
        const rq = db.transaction('draft').objectStore('draft').get('u' + USER_ID);
        rq.onsuccess = () => res(rq.result || null); rq.onerror = () => rej(rq.error);
    }));
}
function draftClearAll() {
    return draftDb().then(db => new Promise((res, rej) => {
        const tx = db.transaction('draft', 'readwrite');
        tx.objectStore('draft').delete('u' + USER_ID);
        tx.oncomplete = res; tx.onerror = () => rej(tx.error);
    })).catch(() => {});
}
function draftLoadList() {
    return draftGet().then(d => {
        if (!d) return [];
        if (Array.isArray(d.list)) return d.list;
        if (d.snap) return [{ id: 'legacy', name: '先前的暫存', ts: d.ts || Date.now(), snap: d.snap, pool: d.pool || [] }];   // 舊版單一暫存自動升級成清單
        return [];
    });
}
function draftSaveList(list) { return draftPut({ list: list }); }
function currentDraftSnapEntry(quiet) {
    if (restoring) { if (!quiet) toast('還原進行中，請稍後再試'); return null; }
    if (canvas.getObjects().filter(o => o.id !== '__artboard').length === 0) { if (!quiet) toast('畫布是空的，沒有東西可暫存'); return null; }
    flushPendingState();   // 確保最新內容已進快照
    const snap = undoStack[undoStack.length - 1];
    if (!snap) { if (!quiet) toast('暫存失敗：目前沒有可用的內容快照'); return null; }
    return { snap: snap, pool: IMG_SRC_POOL.slice(), ts: Date.now() };
}
function autoSaveDraft() {
    const base = currentDraftSnapEntry(true);
    if (!base) return;
    draftLoadList().then(list => {
        const e = Object.assign({ id: 'auto', name: '自動暫存' }, base);
        const i = list.findIndex(x => x.id === 'auto');
        if (i >= 0) list[i] = e; else list.unshift(e);
        return draftSaveList(list);
    }).then(() => { draftDirty = false; }).catch(() => { /* 空間不足等，60 秒後自然重試 */ });
}
setInterval(() => { if (draftDirty) autoSaveDraft(); }, 60000);
window.addEventListener('pagehide', function () { if (draftDirty) autoSaveDraft(); });   // 關窗前盡力補存
function openDraftModal() {
    document.getElementById('dr-name').value = defaultFileName();
    renderDraftList();
    showModal('draft-modal');
}
function renderDraftList() {
    const box = document.getElementById('dr-list');
    box.innerHTML = '載入中…';
    draftLoadList().then(list => {
        if (!list.length) { box.innerHTML = '<span style="color:#8b949e;font-size:12px;">目前沒有暫存檔。輸入名稱後按「暫存目前畫布」。</span>'; return; }
        const p = n => String(n).padStart(2, '0');
        box.innerHTML = list.map(e => {
            const d = new Date(e.ts || 0);
            const when = d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
            const mb = Math.round(((e.snap || '').length + (e.pool || []).reduce((s, x) => s + (x ? x.length : 0), 0)) / 1048576 * 10) / 10;
            return '<div style="display:flex;align-items:center;gap:6px;padding:5px 2px;border-bottom:1px solid #333;">' +
                '<span style="flex:1;">' + (e.id === 'auto' ? '<i class="fa fa-refresh" style="color:#8b949e;margin-right:4px;" title="內容有變每60秒自動更新"></i>' : '') +
                escHtml(e.name || '未命名') + '<span style="color:#8b949e;font-size:11.5px;">｜' + when + '｜約 ' + (mb || 0.1) + 'MB</span></span>' +
                '<button class="tb-btn" onclick="draftOpen(\'' + e.id + '\')" title="開啟這份暫存（會取代目前畫布內容）"><i class="fa fa-folder-open"></i></button>' +
                '<button class="tb-btn" style="color:#ff8a80;" onclick="draftRemove(\'' + e.id + '\')" title="刪除這份暫存"><i class="fa fa-trash"></i></button></div>';
        }).join('');
    }).catch(() => { box.innerHTML = '<span style="color:#ff8a80;">暫存清單載入失敗</span>'; });
}
function draftManualSave() {
    const name = document.getElementById('dr-name').value.trim();
    if (!name) { toast('請先輸入暫存名稱'); return; }
    const base = currentDraftSnapEntry(false);
    if (!base) return;
    draftLoadList().then(list => {
        const i = list.findIndex(x => x.id !== 'auto' && x.name === name);
        if (i >= 0) list[i] = Object.assign({ id: list[i].id, name: name }, base);   // 同名＝覆蓋更新
        else {
            if (list.filter(x => x.id !== 'auto').length >= 5) { toast('手動暫存最多 5 件，請先刪除不需要的再存'); return null; }
            list.unshift(Object.assign({ id: 'm' + Date.now(), name: name }, base));
        }
        return draftSaveList(list).then(() => {
            draftDirty = false;
            toast('已暫存「' + name + '」（只存在這台電腦的瀏覽器）');
            renderDraftList();
        });
    }).catch(e => toast('暫存失敗：' + ((e && e.message) || '瀏覽器儲存空間不足')));
}
function draftOpen(id) {
    draftLoadList().then(list => {
        const e = list.find(x => String(x.id) === String(id));
        if (!e || !e.snap) { toast('找不到這份暫存'); return; }
        if (!confirm('開啟暫存「' + (e.name || '') + '」會取代目前畫布內容（未儲存的變更會消失），確定？')) return;
        // 快照裡是 __imgpool:索引 占位，指向「這份暫存自己的圖片池」；
        // 中途開啟時目前的池可能已有東西，必須先併入目前池並改寫快照索引，否則會張冠李戴
        const map = (e.pool || []).map(s => { let i = IMG_SRC_POOL.indexOf(s); if (i === -1) { IMG_SRC_POOL.push(s); i = IMG_SRC_POOL.length - 1; } return i; });
        const snap = String(e.snap).replace(/"__imgpool:(\d+)"/g, (m, n) => (map[+n] !== undefined) ? '"__imgpool:' + map[+n] + '"' : m);
        hideModal('draft-modal');
        restoreState(snap);
        const wait = setInterval(() => {
            if (restoring) return;
            clearInterval(wait);
            zoomFit();
            pushState();
            toast('已開啟暫存「' + (e.name || '') + '」；要長期保存請用「料號附件」存工作檔');
        }, 150);
    }).catch(() => toast('開啟暫存失敗'));
}
function draftRemove(id) {
    draftLoadList().then(list => {
        const e = list.find(x => String(x.id) === String(id));
        if (!e) return;
        if (!confirm('刪除暫存「' + (e.name || '') + '」？（無法復原）')) return;
        return draftSaveList(list.filter(x => String(x.id) !== String(id))).then(renderDraftList);
    }).catch(() => toast('刪除失敗'));
}
function draftClearAllConfirm() {
    if (!confirm('清除你在這台電腦上的全部暫存檔（含自動暫存）？無法復原。')) return;
    draftClearAll().then(() => { renderDraftList(); toast('已清除全部暫存'); });
}
function pushState() {
    if (restoring) return;
    clearTimeout(pushTimer);
    pushTimer = setTimeout(doPushState, 150);
}
function flushPendingState() {
    if (pushTimer) { clearTimeout(pushTimer); doPushState(); }
}
function restoreState(json) {
    restoring = true;
    const __t0 = performance.now();
    try {
        // 先清掉目前選取再重建：等 loadFromJSON 換掉全部物件後才清，舊多選裡的物件 canvas 已是
        // undefined，destroy→setCoords→控制點 positionHandler 會連環拋例外（殘影/卡頓來源）
        try { canvas.discardActiveObject(); } catch (e) { /* 選取已壞掉就算了，下面照樣重建 */ }
        const j = (typeof json === 'string') ? JSON.parse(json) : json;
        snapUnpoolify(j);   // 快照裡的池索引換回真正的 dataURL；未池化的舊格式原樣通過
        canvas.loadFromJSON(j, function () { restoreDone(); __egSlow('undo還原', __t0); });
    } catch (e) {
        restoring = false;   // JSON 壞掉時 restoring 卡在 true 會讓之後所有快照永久靜默失效
        toast('還原失敗：資料格式有誤');
    }
}
function restoreDone() {
    try {
        canvas.discardActiveObject();   // 舊選取框指向已被重建取代的物件，留著會變成拖得動卻刪不掉的幽靈框
        findArtboard();
        canvas.sendToBack(artboard);
        purgePoisonedObjects();         // 清掉座標已變成 NaN/Infinity 的毒化物件（看不見選得到、渲染出錯留殘影）
        canvas.getObjects().forEach(o => { if (o.curved) o.objectCaching = false; });   // 圓滑曲線可能超出快取框，關快取避免被裁掉
        artW = Math.round(artboard.width * (artboard.scaleX || 1));
        artH = Math.round(artboard.height * (artboard.scaleY || 1));
        document.getElementById('st-canvas').textContent = artW + '×' + artH;
        canvas.requestRenderAll();
        refreshPropbar();
        updateLockUI();
    } finally {
        restoring = false;   // 中途出錯也一定要解除，否則之後所有快照永久靜默失效
    }
}
function undo() {
    if (restoring) return;   // 還原進行中不疊加：連按 Ctrl+Z 排隊多發全畫布重建會凍結數秒
    flushPendingState();
    if (undoStack.length < 2) { toast('沒有可復原的步驟'); return; }
    redoStack.push(undoStack.pop());
    restoreState(undoStack[undoStack.length - 1]);
}
function redo() {
    if (restoring) return;
    flushPendingState();
    if (!redoStack.length) { toast('沒有可重做的步驟'); return; }
    const s = redoStack.pop();
    undoStack.push(s);
    restoreState(s);
}
canvas.on('path:created', function (opt) {
    try { applyFreehandEnds(opt.path); } catch (e) { /* 端點加不上去也不影響手繪線本身，靜默略過 */ }
    setTimeout(pushState, 30);
});
/* 手繪線的端點（無/單箭頭/雙箭頭）：從 fabric.Path 內部座標換算成畫布絕對座標算出頭尾切線方向，
   換算方式跟既有群組內文字定位（fabric.util.transformPoint + calcTransformMatrix）同一套原理。 */
function applyFreehandEnds(path) {
    const ends = document.getElementById('p-line-ends').value;
    if (ends === 'none' || !path || !path.path || path.path.length < 2) return;
    const toPt = cmd => ({ x: cmd[cmd.length - 2], y: cmd[cmd.length - 1] });
    const off = path.pathOffset || { x: 0, y: 0 };
    const m = path.calcTransformMatrix();
    const abs = path.path.map(cmd => { const pt = toPt(cmd); return fabric.util.transformPoint({ x: pt.x - off.x, y: pt.y - off.y }, m); });
    const first = abs[0], second = abs[1] || abs[0];
    const last = abs[abs.length - 1], prev = abs[abs.length - 2] || last;
    const width = path.strokeWidth || 3;
    const color = path.stroke || '#000000';
    const headLen = arrowHeadLen(width);
    const angEnd = Math.atan2(last.y - prev.y, last.x - prev.x) * 180 / Math.PI;
    const angStart = Math.atan2(first.y - second.y, first.x - second.x) * 180 / Math.PI;
    const items = [path];
    // 手繪路徑無法像直線那樣縮短，箭頭改成「底部貼在筆畫末端、尖端往外延伸」，筆畫就不會超過尖端
    const radE = angEnd * Math.PI / 180, radS = angStart * Math.PI / 180;
    if (ends === 'end' || ends === 'both') items.push(arrowHeadTri(last.x + Math.cos(radE) * headLen, last.y + Math.sin(radE) * headLen, angEnd, headLen, color));
    if (ends === 'both') items.push(arrowHeadTri(first.x + Math.cos(radS) * headLen, first.y + Math.sin(radS) * headLen, angStart, headLen, color));
    if (items.length > 1) {
        canvas.remove(path);
        const g = new fabric.Group(items, {});
        g.merged = true; g.isFreehandEnds = true;
        canvas.add(g);
    }
}
canvas.on('object:removed', function () { if (!restoring && !drawing) { /* deleteSelection 已 pushState，避免重複 */ } });

/* ── 鍵盤 ── */
document.addEventListener('keydown', function (e) {
    if (e.code === 'Space' && !isTextEditing()) { spaceDown = true; canvas.defaultCursor = 'grab'; }
    const tag = (document.activeElement || {}).tagName;
    const inInput = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || isTextEditing();

    if (e.ctrlKey && !e.shiftKey && e.key.toLowerCase() === 'z') { if (!inInput) { e.preventDefault(); undo(); } return; }
    if (e.ctrlKey && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z'))) { if (!inInput) { e.preventDefault(); redo(); } return; }
    if (e.ctrlKey && e.key.toLowerCase() === 'c') { if (!inInput) { if (copySelection()) e.preventDefault(); } return; }
    if (e.ctrlKey && e.key.toLowerCase() === 'd') { if (!inInput) { e.preventDefault(); duplicateSelection(); } return; }
    if (e.ctrlKey && e.key.toLowerCase() === 'g') {
        if (!inInput) { e.preventDefault(); groupCmd(); } return;
    }
    if (e.ctrlKey && e.key === '0') { e.preventDefault(); zoomFit(); return; }
    if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'v') {
        if (!inInput) { e.preventDefault(); if (!pasteInternalOrCross()) toast('沒有可貼上的跨窗/內部內容'); }
        return;
    }
    if (e.ctrlKey && e.key.toLowerCase() === 'a') {
        if (!inInput) { e.preventDefault(); selectAllObjects(); }
        return;
    }
    if (inInput) return;

    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); deleteSelection(); return; }
    // 方向鍵微調選取物件：1px，Shift＝10px
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        const obj = canvas.getActiveObject();
        if (obj) {
            e.preventDefault();
            const step = e.shiftKey ? 10 : 1;
            if (e.key === 'ArrowUp') obj.set('top', obj.top - step);
            if (e.key === 'ArrowDown') obj.set('top', obj.top + step);
            if (e.key === 'ArrowLeft') obj.set('left', obj.left - step);
            if (e.key === 'ArrowRight') obj.set('left', obj.left + step);
            obj.setCoords();
            canvas.requestRenderAll();
            clearTimeout(window.__nudgeTimer);
            window.__nudgeTimer = setTimeout(pushState, 400);   // 連按只記一次復原點
        }
        return;
    }
    if (e.key === 'Escape') {
        if (zoomRectMode) { exitZoomRect(); return; }   // 先取消框選放大模式
        const ao = canvas.getActiveObject();
        if (ao && ao.__pointEditing) { togglePointEdit(); return; }   // 第一下 Esc 先離開編輯端點模式，第二下才取消選取
        setTool('select'); canvas.discardActiveObject(); canvas.requestRenderAll(); return;
    }
    const keyTool = { v: 'select', h: 'pan', b: 'draw', l: 'line', r: 'rect', o: 'ellipse', t: 'text', m: 'maskrect', c: 'cropcopy', x: 'cropmove' }[e.key.toLowerCase()];
    if (keyTool && !e.ctrlKey && !e.altKey) setTool(keyTool);
});
document.addEventListener('keyup', function (e) {
    if (e.code === 'Space') { spaceDown = false; canvas.defaultCursor = (currentTool === 'pan') ? 'grab' : 'default'; }
});

/* ── 跳窗 / 畫布設定 / 其他 ── */
function showModal(id) {
    // 後開的跳窗要疊在已開跳窗上方（例：標籤管理 → 組成群組標籤），不然會被壓在下面要先關掉才能用
    // 基準 960＝壓過浮動快捷列（旋轉/符號鍵 .obj-float z=950、sym-pad z=900），但仍低於 toast(999)
    const el = document.getElementById(id);
    let z = 960;
    document.querySelectorAll('.modal-mask.show').forEach(m => { z = Math.max(z, parseInt(m.style.zIndex, 10) || 960); });
    el.style.zIndex = (z + 1);
    el.classList.add('show');
}
function hideModal(id) { const el = document.getElementById(id); el.classList.remove('show'); el.style.zIndex = ''; }
function openCanvasModal() {
    document.getElementById('cv-w').value = artW;
    document.getElementById('cv-h').value = artH;
    document.getElementById('cv-bg').value = toHex(artboard.fill) || '#ffffff';
    showModal('canvas-modal');
}
function applyCanvasModal() {
    setArtboardSize(parseInt(document.getElementById('cv-w').value, 10) || artW,
                    parseInt(document.getElementById('cv-h').value, 10) || artH,
                    document.getElementById('cv-bg').value);
    hideModal('canvas-modal');
    zoomFit(); pushState();
}
function fitArtboardToContent() {
    const objs = canvas.getObjects().filter(o => o !== artboard);
    if (!objs.length) { toast('畫布上沒有內容'); return; }
    let l = Infinity, t = Infinity, r = -Infinity, b = -Infinity;
    objs.forEach(o => {
        const bb = o.getBoundingRect(true, true);
        l = Math.min(l, bb.left); t = Math.min(t, bb.top);
        r = Math.max(r, bb.left + bb.width); b = Math.max(b, bb.top + bb.height);
    });
    const pad = 10;
    artboard.set({ left: l - pad, top: t - pad });
    setArtboardSize(r - l + pad * 2, b - t + pad * 2);
    zoomFit(); pushState();
    toast('畫布已調整為剛好包住所有內容');
}
function openSecondWindow() {
    window.open(location.href, 'egImgEditor_' + Date.now(),
        'width=1280,height=860,menubar=no,toolbar=no,location=no,status=no,resizable=yes');
}
let toastTimer = null;
function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.style.display = 'none', 3200);
}

/* 數字輸入框 UI 規則：聚焦全選、雙擊清空、Enter 跳下一欄 */
document.querySelectorAll('input[type=number], .ni').forEach(inp => {
    inp.addEventListener('focus', function () { this.select(); });
    inp.addEventListener('dblclick', function () { this.value = ''; });
    inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const all = Array.from(document.querySelectorAll('input, select'));
            const i = all.indexOf(this);
            for (let j = i + 1; j < all.length; j++) {
                if (all[j].offsetParent !== null) { all[j].focus(); break; }
            }
            this.dispatchEvent(new Event('change'));
        }
    });
});

/* ── 個人畫圖偏好：顏色/粗細/印章大小…改過就記住，下次開啟沿用（存在 system_settings，依使用者區分）── */
const PREF_FIELDS = [
    ['p-stroke', 'stroke'], ['p-width', 'width'], ['p-line-ends', 'lineEnds'], ['p-line-style', 'lineStyle'],
    ['p-fill', 'fill'], ['p-fill-on', 'fillOn', true],
    ['p-textcolor', 'textColor'], ['p-fontsize', 'fontSize'], ['p-bold', 'bold', true], ['p-underline', 'underline'],
    ['p-textbg', 'textBg'], ['p-textbg-on', 'textBgOn', true],
    ['p-balloon-size', 'balloonSize'], ['p-dc-shape', 'dcShape'], ['p-dc-size', 'dcSize'],
    ['p-stamp-size', 'stampSize'], ['p-maskcolor', 'maskColor'], ['p-crop-transparent', 'cropTransparent', true],
    ['frame-clip', 'frameClip', true],
    ['p-connect-kind', 'connectKind'], ['p-dim-style', 'dimStyle']
];
function applyUserPrefs() {
    PREF_FIELDS.forEach(([id, key, isCheckbox]) => {
        const el = document.getElementById(id);
        const v = USER_PREFS[key];
        if (!el || v === undefined || v === null) return;
        if (isCheckbox) el.checked = !!v; else el.value = v;
    });
    document.getElementById('p-width-v').textContent = document.getElementById('p-width').value;
    frameClipOn = !!document.getElementById('frame-clip').checked;   // 勾選狀態是直接寫 .checked（不觸發 change），旗標要自己同步
    canvas.requestRenderAll();
    // 各繪圖工具自己的線條設定（見 captureToolStyle/applyToolStyle）
    if (USER_PREFS.toolStyles && typeof USER_PREFS.toolStyles === 'object') toolStyles = USER_PREFS.toolStyles;
}
let prefsSaveTimer = null;
function saveUserPrefsDebounced() {
    clearTimeout(prefsSaveTimer);
    prefsSaveTimer = setTimeout(() => {
        const prefs = {};
        PREF_FIELDS.forEach(([id, key, isCheckbox]) => {
            const el = document.getElementById(id);
            if (!el) return;
            prefs[key] = isCheckbox ? el.checked : el.value;
        });
        captureToolStyle(currentTool);   // 現在正在用的工具剛改的值也要一起記起來（不必等切走工具）
        prefs.toolStyles = toolStyles;
        const fd = new FormData();
        fd.append('action', 'save_user_prefs');
        fd.append('prefs', JSON.stringify(prefs));
        fetch('image_editor.php', { method: 'POST', body: fd }).catch(() => {});
    }, 600);
}
PREF_FIELDS.forEach(([id]) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', saveUserPrefsDebounced);
});
applyUserPrefs();

/* ── 初始化 ── */
resizeViewport();
setArtboardSize(artW, artH);
zoomFit();
pushState();  // 初始狀態
// 開啟編輯器不再自動詢問暫存檔（使用者要求取消）；要接續編輯請按頂列「暫存」自行選擇開啟
setTool('select');

/* ── 由 BOM 檢視器等頁面帶入圖檔：?preload=<絕對URL> 則自動載入該圖 ── */
(function () {
    var qs = new URLSearchParams(window.location.search);
    var preload = qs.get('preload');
    if (!preload) return;
    var name = qs.get('preload_name') || '';
    // PDF（副檔名或呼叫端指定 preload_type=pdf；走 API 下載端點的網址不一定有副檔名）
    // → 先轉成圖檔再進畫布，多頁會跳窗問要開哪幾頁
    if ((qs.get('preload_type') || '').toLowerCase() === 'pdf' || /\.pdf(\?|#|$)/i.test(preload)) {
        openPdfFromSource({ url: preload }, name || 'PDF');
        return;
    }
    // 同源圖檔，直接載入畫布（addImageFromURL 內含載入失敗提示）
    if (name) toast('帶入圖檔：' + name);
    addImageFromURL(preload, 0);
})();
