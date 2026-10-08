/**
 * eg_3d_viewer_tools.js — 3D 檢視器進階功能共用元件（唯一實作）
 *
 * 背景（2026-10-08）：bom_viewer.php 與 master_data_management.php 都用 o3dv.min.js
 * （Online3DViewer，開源）顯示 STP/STEP/STL/OBJ/IGS/IGES 與轉檔後的 IPT/X_T。函式庫
 * 本身只負責「顯示」，沒有上色/量測/截圖列印/批圖編輯器整合這些功能——這些都是本檔
 * 新增的，直接操作函式庫底層暴露出來的 Three.js 物件做出來的。
 *
 * 禁止在 bom_viewer.php／master_data_management.php 各自刻一份——兩邊都已經是幾千行
 * 的大檔案，這份功能只寫一次、只測一次，兩頁各自呼叫本檔的函式接上自己的按鈕/容器。
 *
 * 用到的函式庫底層 API（皆已查證 GitHub 原始碼＋本機 o3dv.min.js 確認存在）：
 *   embeddedViewer.GetViewer()                        → 內部 Viewer 物件
 *   viewer.GetCanvasSize()                             → {width,height}
 *   viewer.GetMeshIntersectionUnderMouse(mode, {x,y})  → 標準 Three.js raycast 結果｜null
 *     （已查證 o3dv.min.js 內部是直接呼叫 raycaster.intersectObject() 把整筆結果原樣回傳，
 *      所以除了 .object 之外，.face（含 a/b/c 三個頂點索引）與 .faceIndex 本來就在，
 *      不需要函式庫額外支援；mouseCoords 是「畫布像素座標」不是標準化座標，要自己從
 *      clientX/Y 換算）
 *   viewer.GetImageAsDataUrl(w,h,isTransparent)        → 目前畫面截圖（含目前上色/角度）
 *   viewer.Render()                                    → 強制重繪
 *   OV.IntersectionMode.MeshOnly                       → 只打到實體面，不含線段
 *
 * 上色做成「單面」（2026-10-08 使用者要求：不要整個零件一次換色，要單個面）：
 * 用頂點色（vertex colors）而不是整個 mesh 換材質——mesh.geometry.attributes.position
 * 本身就是真正的 THREE.BufferAttribute 實例，借它的 .constructor 現場 new 一份「color」
 * 屬性，完全不需要全域 THREE（已查證 o3dv.min.js 不會把 THREE 掛在 window 上）。
 * 最終顏色＝材質色×頂點色，做法：材質色固定白色、沒上色的頂點預設值＝原始材質色
 * （相乘＝原色不變）、上色的三個頂點直接寫使用者選的顏色（相乘白色＝原色不失真，
 * 不會被材質本身的灰色底再乘一次變得混濁）。
 * 風險已知：若該幾何是 indexed（共用頂點、平滑著色），上色的三角形邊界會跟相鄰
 * 未上色面之間出現漸層（因為共用頂點的色是內插的），不是銳利分界——CAD 匯入的
 * 三角化網格多為非共用頂點（硬邊平面著色），實務上多半不會遇到，仍無法保證。
 */
var EG3DTools = (function () {

    // ── 滑鼠座標 → 畫布像素座標 → 命中結果（含 .object/.face/.faceIndex）────────
    function pickMesh(embeddedViewer, canvasEl, clientX, clientY) {
        if (!embeddedViewer || typeof OV === 'undefined') return null;
        var viewer = embeddedViewer.GetViewer();
        var rect = canvasEl.getBoundingClientRect();
        var x = clientX - rect.left;
        var y = clientY - rect.top;
        var size = viewer.GetCanvasSize();
        if (x < 0 || y < 0 || x > size.width || y > size.height) return null;
        return viewer.GetMeshIntersectionUnderMouse(OV.IntersectionMode.MeshOnly, { x: x, y: y });
    }

    // ── 上色（單面）：記住每個 mesh 的頂點色資料＋原始材質，才能「恢復預設色」────
    function ColorState() {
        this.meshStates = new Map();   // mesh → {origMaterial, colorAttr, baseColor, paintedKeys}
    }
    ColorState.prototype._ensureMeshState = function (mesh) {
        if (this.meshStates.has(mesh)) return this.meshStates.get(mesh);
        var geo = mesh.geometry;
        var posAttr = geo.attributes.position;
        var baseMat = Array.isArray(mesh.material) ? mesh.material[0] : mesh.material;
        var baseColor = baseMat.color.clone();   // 原始材質色＝尚未上色頂點的預設值
        var colorAttr = geo.attributes.color;
        if (!colorAttr) {
            var AttrCtor = posAttr.constructor;   // 借用既有屬性的建構子，不靠全域 THREE
            var arr = new Float32Array(posAttr.count * 3);
            for (var i = 0; i < arr.length; i += 3) {
                arr[i] = baseColor.r; arr[i + 1] = baseColor.g; arr[i + 2] = baseColor.b;
            }
            colorAttr = new AttrCtor(arr, 3);
            geo.setAttribute('color', colorAttr);
        }
        var origMaterial = mesh.material;
        var mats = Array.isArray(origMaterial) ? origMaterial : [origMaterial];
        var newMats = mats.map(function (m) {
            var clone = m.clone();
            clone.vertexColors = true;
            clone.color.set(0xffffff);   // 材質色固定白色，顏色完全交給頂點色決定
            clone.needsUpdate = true;    // 切換 vertexColors 要重新編譯 shader
            return clone;
        });
        mesh.material = Array.isArray(origMaterial) ? newMats : newMats[0];
        var state = { origMaterial: origMaterial, colorAttr: colorAttr, baseColor: baseColor, paintedKeys: new Set() };
        this.meshStates.set(mesh, state);
        return state;
    };
    // face：GetMeshIntersectionUnderMouse 回傳結果裡的 .face（{a,b,c,normal}）
    ColorState.prototype.paintFace = function (mesh, face, hexColor) {
        if (!mesh || !face) return;
        var state = this._ensureMeshState(mesh);
        var c = state.baseColor.clone();
        c.set(hexColor);
        var arr = state.colorAttr.array;
        [face.a, face.b, face.c].forEach(function (vi) {
            arr[vi * 3] = c.r; arr[vi * 3 + 1] = c.g; arr[vi * 3 + 2] = c.b;
        });
        state.colorAttr.needsUpdate = true;
        state.paintedKeys.add(face.a + '_' + face.b + '_' + face.c);
    };
    ColorState.prototype.resetMesh = function (mesh) {
        var state = this.meshStates.get(mesh);
        if (!state) return;
        mesh.material = state.origMaterial;
        var arr = state.colorAttr.array, bc = state.baseColor;
        for (var i = 0; i < arr.length; i += 3) { arr[i] = bc.r; arr[i + 1] = bc.g; arr[i + 2] = bc.b; }
        state.colorAttr.needsUpdate = true;
        this.meshStates.delete(mesh);
    };
    ColorState.prototype.resetAll = function () {
        var self = this;
        Array.from(this.meshStates.keys()).forEach(function (mesh) { self.resetMesh(mesh); });
    };
    ColorState.prototype.hasAny = function () { return this.meshStates.size > 0; };

    // ── 截圖：回傳目前畫面（含目前上色/角度/縮放）的 PNG dataURL ─────────────
    // 尺寸刻意用畫布目前的實際像素大小（不是固定值），縮放/視窗大小不同截出來的
    // 比例才會跟使用者正在看的一致；devicePixelRatio 由函式庫內部自行處理。
    function screenshot(embeddedViewer, opts) {
        opts = opts || {};
        var viewer = embeddedViewer.GetViewer();
        var size = viewer.GetCanvasSize();
        var w = opts.width || size.width;
        var h = opts.height || size.height;
        var url = viewer.GetImageAsDataUrl(w, h, !!opts.transparent);
        viewer.Resize(size.width, size.height); // GetImageAsDataUrl 會改渲染器尺寸，截完要還原顯示大小
        viewer.Render();
        return url;
    }

    // ── 列印：截圖＋料號名稱＋列印日期，純前端組頁面，不經伺服器、沒有暫存檔 ──
    function printCurrentView(embeddedViewer, opts) {
        opts = opts || {};
        var dataUrl = screenshot(embeddedViewer, { width: 1600 });
        var w = window.open('', '_blank', 'width=900,height=700');
        if (!w) { alert('瀏覽器擋下了列印視窗，請允許快顯視窗後再試一次'); return; }
        var today = new Date();
        var dateStr = today.getFullYear() + '.' + String(today.getMonth() + 1).padStart(2, '0') + '.' + String(today.getDate()).padStart(2, '0');
        var title = opts.partName || opts.fileName || '3D 模型';
        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escHtml3d(title) + '</title>'
            + '<style>'
            + '@page{size:A4 portrait;margin:15mm;}'
            + 'body{font-family:"Microsoft JhengHei",Arial,sans-serif;margin:0;color:#222;}'
            + '.hdr{display:flex;justify-content:space-between;align-items:baseline;border-bottom:2px solid #333;padding-bottom:8px;margin-bottom:14px;}'
            + '.hdr h1{font-size:18px;margin:0;}'
            + '.hdr .meta{font-size:12px;color:#666;}'
            + '.imgwrap{text-align:center;}'
            + '.imgwrap img{max-width:100%;max-height:230mm;}'
            + '</style></head><body>'
            + '<div class="hdr"><h1>' + escHtml3d(title) + '</h1><div class="meta">列印日期：' + dateStr + '</div></div>'
            + '<div class="imgwrap"><img src="' + dataUrl + '"></div>'
            + '</body></html>';
        w.document.open(); w.document.write(html); w.document.close();
        // 新視窗的 load 事件不可靠（有些瀏覽器 document.write 之後不會再觸發），比照全站其他
        // 深連結列印做法改用 setTimeout 自行觸發（見 ai-rules/16）
        setTimeout(function () { try { w.focus(); w.print(); } catch (e) {} }, 300);
    }

    // ── 在批圖編輯器開啟：截圖存進 localStorage，帶一把短效 key 開新分頁 ──────
    // 不能把 dataURL 直接放進網址參數——一張截圖轉 base64 動輒數十萬字元，遠超過
    // Apache LimitRequestLine(8190)／瀏覽器網址長度限制，會整個連不進去（鐵律：見
    // 2026-09-21 內稽模組「把一整份清單的 id 送給後端」同一種坑，這裡反過來是取）。
    // localStorage 是同源分頁間穩定共享的管道，寫入後附一個時間戳記 key，讀取端讀到
    // 就立刻刪除，不會越積越多。
    function openInImageEditor(embeddedViewer, opts) {
        opts = opts || {};
        var dataUrl = screenshot(embeddedViewer, { width: 2000 });
        var key = 'eg3d_shot_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
        try {
            localStorage.setItem(key, dataUrl);
        } catch (e) {
            alert('截圖資料過大，瀏覽器儲存空間不足，無法帶入批圖編輯器');
            return;
        }
        var url = (opts.editorUrl || '../Sales/image_editor.php') + '?preload_blob_key=' + encodeURIComponent(key)
            + '&preload_name=' + encodeURIComponent(opts.fileName || '3D截圖.png');
        if (opts.partNo) url += '&part_no=' + encodeURIComponent(opts.partNo);
        if (opts.partDId) url += '&part_d_id=' + encodeURIComponent(opts.partDId);
        window.open(url, 'egImgEditor_' + Date.now(), 'width=1280,height=860,menubar=no,toolbar=no,location=no,status=no,resizable=yes');
    }

    function escHtml3d(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return {
        pickMesh: pickMesh,
        ColorState: ColorState,
        screenshot: screenshot,
        printCurrentView: printCurrentView,
        openInImageEditor: openInImageEditor
    };
})();
