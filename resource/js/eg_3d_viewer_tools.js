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
 *   viewer.AddExtraObject(obj) / viewer.ClearExtra()   → 疊加自訂 Three.js 物件的官方管道
 *     （量測標記點/連線用這個，不要自己 mesh.parent.add(...)——實測過那條路徑物件雖然
 *      真的進了場景圖、draw call 也真的送出去，但沒特別處理深度測試時常被模型本體整個
 *      擋住、肉眼完全看不到；AddExtraObject 是函式庫自己留的擴充點，且與 mainModel 分開
 *      管理，ClearExtra() 可只清掉標記不動到主模型的材質/上色狀態）。疊加物件材質務必設
 *      depthTest:false＋高 renderOrder，量測標記才會穩定蓋在模型表面之上。
 *
 * 上色做成「自動判定同一面＋可框選」（2026-10-08 使用者兩輪要求：①不要整個零件一次
 * 換色，要單面 ②能不能自動判定哪些三角形屬於同一面 ③框選也要做）：
 * 用頂點色（vertex colors）而不是整個 mesh 換材質——mesh.geometry.attributes.position
 * 本身就是真正的 THREE.BufferAttribute 實例，借它的 .constructor 現場 new 一份「color」
 * 屬性，完全不需要全域 THREE（已查證 o3dv.min.js 不會把 THREE 掛在 window 上）。
 * 最終顏色＝材質色×頂點色，做法：材質色固定白色、沒上色的頂點預設值＝原始材質色
 * （相乘＝原色不變）、上色的頂點直接寫使用者選的顏色（相乘白色＝原色不失真，不會
 * 被材質本身的灰色底再乘一次變得混濁）。
 *
 * 「同一面」判定＝以三角形相鄰關係＋法向量夾角做 flood fill（逐鄰居局部比較角度，
 * 不是都跟起點比較，才能正確延伸到有弧度的曲面而不是只吃平面）：平面＝鄰接三角形
 * 法向量完全相同，曲面（圓角/倒角）＝鄰接三角形法向量連續小角度漸變，真正的邊界
 * （不同 CAD 面交界）＝鄰接三角形法向量夾角明顯跳動，flood fill 在那裡自然停下來。
 * 這個做法不需要 CAD 原始拓樸資料（occt-import-js 轉出來的是三角化網格，面與面的
 * 分界資訊沒有保留），純粹用幾何重建，三角化夠細時準確度足以讓「點一下＝整個面」
 * 這個操作符合直覺。相鄰關係用共用頂點索引建邊表，indexed 幾何直接用索引值、
 * non-indexed 幾何（每個三角形頂點各自獨立複製）改用座標四捨五入分組當作「同一個
 * 頂點」。鄰接圖與法向量逐 mesh 快取（WeakMap），只在第一次點選該 mesh 時建一次。
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

    // ── 三角形鄰接圖＋法向量（每個 mesh 第一次用到才建，之後快取）────────────────
    var _faceGraphCache = new WeakMap();   // mesh → {triCount, getTri, normals, adjacency}
    function _faceNormal(posArr, ia, ib, ic) {
        var ax = posArr[ia*3], ay = posArr[ia*3+1], az = posArr[ia*3+2];
        var bx = posArr[ib*3], by = posArr[ib*3+1], bz = posArr[ib*3+2];
        var cx = posArr[ic*3], cy = posArr[ic*3+1], cz = posArr[ic*3+2];
        var ux = bx-ax, uy = by-ay, uz = bz-az;
        var vx = cx-ax, vy = cy-ay, vz = cz-az;
        var nx = uy*vz-uz*vy, ny = uz*vx-ux*vz, nz = ux*vy-uy*vx;
        var len = Math.sqrt(nx*nx+ny*ny+nz*nz) || 1;
        return [nx/len, ny/len, nz/len];
    }
    function _buildFaceGraph(mesh) {
        var cached = _faceGraphCache.get(mesh);
        if (cached) return cached;
        var geo = mesh.geometry;
        var posAttr = geo.attributes.position;
        var posArr = posAttr.array;
        var idxAttr = geo.index;
        var triCount, getTri;
        if (idxAttr) {
            var idxArr = idxAttr.array;
            triCount = Math.floor(idxArr.length / 3);
            getTri = function (i) { return [idxArr[i*3], idxArr[i*3+1], idxArr[i*3+2]]; };
        } else {
            triCount = Math.floor(posAttr.count / 3);
            getTri = function (i) { return [i*3, i*3+1, i*3+2]; };
        }
        var normals = new Array(triCount);
        for (var t = 0; t < triCount; t++) {
            var v = getTri(t);
            normals[t] = _faceNormal(posArr, v[0], v[1], v[2]);
        }
        // 「同一個頂點」的判定鍵：indexed 幾何本來就共用索引；non-indexed 幾何每個三角形
        // 的頂點各自獨立複製（即使座標相同也是不同的陣列位置），要先依座標四捨五入分組
        // 才找得出哪些三角形其實共用著同一個空間位置的頂點。
        var vertKey;
        if (idxAttr) {
            vertKey = function (vi) { return vi; };
        } else {
            var posToGroup = new Map();
            var groupOf = new Int32Array(posAttr.count);
            var nextGroup = 0;
            for (var p = 0; p < posAttr.count; p++) {
                var key = Math.round(posArr[p*3]*1e4)+','+Math.round(posArr[p*3+1]*1e4)+','+Math.round(posArr[p*3+2]*1e4);
                var g = posToGroup.get(key);
                if (g === undefined) { g = nextGroup++; posToGroup.set(key, g); }
                groupOf[p] = g;
            }
            vertKey = function (vi) { return groupOf[vi]; };
        }
        var edgeMap = new Map();   // "較小組_較大組" → [triIndex,...]（共用這條邊的三角形）
        for (var t2 = 0; t2 < triCount; t2++) {
            var v2 = getTri(t2);
            var g0 = vertKey(v2[0]), g1 = vertKey(v2[1]), g2 = vertKey(v2[2]);
            var edges = [[g0,g1],[g1,g2],[g2,g0]];
            for (var e = 0; e < 3; e++) {
                var a = edges[e][0], b = edges[e][1];
                var key = a < b ? (a+'_'+b) : (b+'_'+a);
                var list = edgeMap.get(key);
                if (!list) { list = []; edgeMap.set(key, list); }
                list.push(t2);
            }
        }
        var adjacency = new Array(triCount);
        for (var t3 = 0; t3 < triCount; t3++) adjacency[t3] = [];
        edgeMap.forEach(function (list) {
            if (list.length < 2) return;   // 邊界邊（只有一個三角形）沒有鄰居
            for (var i = 0; i < list.length; i++) {
                for (var j = 0; j < list.length; j++) {
                    if (i !== j) adjacency[list[i]].push(list[j]);
                }
            }
        });
        var graph = { triCount: triCount, getTri: getTri, normals: normals, adjacency: adjacency };
        _faceGraphCache.set(mesh, graph);
        return graph;
    }
    // 從 startFaceIndex 開始，往鄰接三角形 flood fill，逐鄰居局部比較法向量夾角
    // （不是都跟起點比，才能正確吃到整段有弧度的曲面）；回傳三角形索引陣列。
    function sameFaceTriangles(mesh, startFaceIndex, angleDeg) {
        var graph = _buildFaceGraph(mesh);
        if (startFaceIndex == null || startFaceIndex < 0 || startFaceIndex >= graph.triCount) return [];
        var cosThresh = Math.cos((angleDeg == null ? 12 : angleDeg) * Math.PI / 180);
        var visited = new Uint8Array(graph.triCount);
        var stack = [startFaceIndex];
        visited[startFaceIndex] = 1;
        var result = [startFaceIndex];
        while (stack.length) {
            var cur = stack.pop();
            var n0 = graph.normals[cur];
            var neighbors = graph.adjacency[cur];
            for (var k = 0; k < neighbors.length; k++) {
                var nb = neighbors[k];
                if (visited[nb]) continue;
                var n1 = graph.normals[nb];
                var dot = n0[0]*n1[0] + n0[1]*n1[1] + n0[2]*n1[2];
                if (dot >= cosThresh) {
                    visited[nb] = 1;
                    result.push(nb);
                    stack.push(nb);
                }
            }
        }
        return result;
    }
    // 點一下＝挑到的那個三角形 + 自動延伸出去的整個「同一面」
    function pickFaceGroup(embeddedViewer, canvasEl, clientX, clientY, angleDeg) {
        var hit = pickMesh(embeddedViewer, canvasEl, clientX, clientY);
        if (!hit || !hit.object || hit.faceIndex == null) return null;
        return { mesh: hit.object, triIndices: sameFaceTriangles(hit.object, hit.faceIndex, angleDeg), hit: hit };
    }
    // 框選：在畫面矩形範圍內按固定間距取樣多個點各自 raycast，命中的三角形各自再
    // 延伸成整個同一面（expand 預設開），合併成「mesh → 三角形索引集合」。
    // 這個做法不需要相機投影矩陣（函式庫沒有公開這部分），用「取樣逐點 raycast」
    // 反過來達成同樣效果，完全建立在已經驗證可用的 pickMesh 之上，風險最低。
    function pickFaceGroupsInRect(embeddedViewer, canvasEl, rect, opts) {
        opts = opts || {};
        var step = opts.step || 8;
        var angleDeg = opts.angleDeg;
        var expand = opts.expand !== false;
        var result = new Map();
        var x0 = Math.min(rect.x1, rect.x2), x1 = Math.max(rect.x1, rect.x2);
        var y0 = Math.min(rect.y1, rect.y2), y1 = Math.max(rect.y1, rect.y2);
        for (var y = y0; y <= y1; y += step) {
            for (var x = x0; x <= x1; x += step) {
                var hit = pickMesh(embeddedViewer, canvasEl, x, y);
                if (!hit || !hit.object || hit.faceIndex == null) continue;
                var set = result.get(hit.object);
                if (!set) { set = new Set(); result.set(hit.object, set); }
                if (expand) {
                    var tris = sameFaceTriangles(hit.object, hit.faceIndex, angleDeg);
                    for (var i = 0; i < tris.length; i++) set.add(tris[i]);
                } else {
                    set.add(hit.faceIndex);
                }
            }
        }
        return result;   // Map<mesh, Set<triIndex>>
    }

    // ── 上色：記住每個 mesh 的頂點色資料＋原始材質，才能「恢復預設色」────────────
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
    // triIndices：要上色的三角形索引陣列（來自 sameFaceTriangles／pickFaceGroup／框選結果）
    ColorState.prototype.paintTriangleIndices = function (mesh, triIndices, hexColor) {
        if (!mesh || !triIndices || !triIndices.length) return;
        var state = this._ensureMeshState(mesh);
        var graph = _buildFaceGraph(mesh);
        var c = state.baseColor.clone();
        c.set(hexColor);
        var arr = state.colorAttr.array;
        for (var i = 0; i < triIndices.length; i++) {
            var ti = triIndices[i];
            if (ti < 0 || ti >= graph.triCount) continue;
            var v = graph.getTri(ti);
            for (var k = 0; k < 3; k++) {
                var vi = v[k];
                arr[vi*3] = c.r; arr[vi*3+1] = c.g; arr[vi*3+2] = c.b;
            }
            state.paintedKeys.add(ti);
        }
        state.colorAttr.needsUpdate = true;
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

    // ── 整合滑鼠互動：單點＝點一下自動判定同一面，拖曳（超過門檻像素）＝框選 ──────
    // containerEl：3D 檢視器外層容器（需 position:relative/absolute，拖曳方框疊加其上，
    //   兩頁的 #bom-3d-wrap／#pav-preview 都是靜態存在、不會被 innerHTML 整個換掉的容器，
    //   所以可以在頁面載入時綁定一次，不必像內層 canvas 容器那樣擔心換檔後失聯）。
    // canvasSelector：在 containerEl 底下找畫布的選擇器（例如 '#bom-3d-viewer canvas'）。
    // getCtx()：互動當下即時回傳 {embeddedViewer, colorState, hexColor}（每次切檔案這三個
    //   都會換，所以用函式取得當下最新值，不要傳入當下的值）。
    // isActiveFn()：回傳目前是否處於上色模式（呼叫端自己的開關按鈕狀態）。
    function attachColorInteraction(containerEl, canvasSelector, getCtx, isActiveFn, opts) {
        if (!containerEl) return;
        opts = opts || {};
        var angleDeg = opts.angleDeg;
        var dragThreshold = opts.dragThreshold || 5;
        var rectEl = null, startX = 0, startY = 0, dragging = false, moved = false;

        function ensureRectEl() {
            if (rectEl && rectEl.parentNode === containerEl) return rectEl;
            rectEl = document.createElement('div');
            rectEl.className = 'eg3d-select-rect';
            rectEl.style.cssText = 'position:absolute;border:1px dashed #e67e22;background:rgba(230,126,34,.15);pointer-events:none;z-index:6;display:none;';
            containerEl.appendChild(rectEl);
            return rectEl;
        }
        function updateRect(x0, y0, x1, y1) {
            var r = ensureRectEl();
            var cRect = containerEl.getBoundingClientRect();
            r.style.left = (Math.min(x0, x1) - cRect.left) + 'px';
            r.style.top = (Math.min(y0, y1) - cRect.top) + 'px';
            r.style.width = Math.abs(x1 - x0) + 'px';
            r.style.height = Math.abs(y1 - y0) + 'px';
            r.style.display = 'block';
        }
        function hideRect() { if (rectEl) rectEl.style.display = 'none'; }

        // 用「捕獲階段」(capture:true) 攔截，一定要搶在 o3dv 自己掛在 canvas 上的滑鼠事件
        // （視角旋轉／平移）之前擋下——實測沒加 capture 時，拖曳框選的同時視角也會跟著轉
        // （bubble 階段時事件已經先傳到 canvas、o3dv 自己的拖曳旋轉已經先啟動了）；
        // stopPropagation() 在捕獲階段會讓事件根本到不了 canvas，o3dv 的拖曳旋轉就永遠
        // 不會被觸發，而不是「兩者都觸發、事後才去擋」。
        containerEl.addEventListener('mousedown', function (e) {
            if (!isActiveFn() || e.button !== 0) return;
            dragging = true; moved = false; startX = e.clientX; startY = e.clientY;
            e.preventDefault();
            e.stopPropagation();
        }, true);
        window.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            if (Math.abs(e.clientX - startX) > dragThreshold || Math.abs(e.clientY - startY) > dragThreshold) {
                moved = true;
                updateRect(startX, startY, e.clientX, e.clientY);
            }
        });
        window.addEventListener('mouseup', function (e) {
            if (!dragging) return;
            dragging = false;
            hideRect();
            if (!isActiveFn()) return;
            var ctx = getCtx();
            if (!ctx || !ctx.embeddedViewer || !ctx.colorState) return;
            var canvasEl = containerEl.querySelector(canvasSelector);
            if (!canvasEl) return;
            if (!moved) {
                var g = pickFaceGroup(ctx.embeddedViewer, canvasEl, startX, startY, angleDeg);
                if (g && g.triIndices.length) {
                    ctx.colorState.paintTriangleIndices(g.mesh, g.triIndices, ctx.hexColor);
                    ctx.embeddedViewer.GetViewer().Render();
                }
            } else {
                var prevCursor = document.body.style.cursor;
                document.body.style.cursor = 'wait';
                var groups = pickFaceGroupsInRect(ctx.embeddedViewer, canvasEl,
                    { x1: startX, y1: startY, x2: e.clientX, y2: e.clientY }, { angleDeg: angleDeg });
                groups.forEach(function (triSet, mesh) {
                    ctx.colorState.paintTriangleIndices(mesh, Array.from(triSet), ctx.hexColor);
                });
                document.body.style.cursor = prevCursor;
                if (groups.size) ctx.embeddedViewer.GetViewer().Render();
            }
        });
    }

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

    // ══════════════════════════════════════════════════════════════════════
    // 尺寸量測：點／線／面，兩兩距離，顯示單位（2026-10-08 使用者要求）
    // ══════════════════════════════════════════════════════════════════════
    // 單位假設：STEP/IPT 轉檔後的三角網格本身沒有附帶任何「這個模型是用什麼單位建的」
    // 資訊（occt-import-js／Online3DViewer 都不處理比例），本廠 Inventor 繪圖慣例一律
    // 用毫米(mm)，故本工具的數值一律標示 mm；這是「假設」不是「已驗證」，若量到的數字
    // 與圖面標註的已知尺寸對不上，代表來源模型本身的比例不是 mm，需要另外處理。
    //
    // 「線」＝三角網格沒有保留 CAD 的邊/面拓樸資料，用 _buildFaceGraph() 的鄰接關係反推
    // 「特徵邊」：一條邊只被一個三角形使用＝網格邊界，或邊兩側的三角形分屬不同「同一面」
    // 群組＝兩個 CAD 面的交界，兩者都算特徵邊；點一下時只在「點到的這個面群組」範圍內
    // 找最近的特徵邊，不用掃整個模型。
    //
    // 點-點／點-線（點到線段）為精確公式；點-面＝對該面群組全部三角形做逐三角形最近點
    // 比對，曲面也精確；線-線為精確的三維線段最近距離；凡是「面-面」或「線-面」因為
    // 沒有做真正的連續曲面最近距離最佳化，改用兩邊頂點兩兩比對取最小值當近似值，結果
    // 一律標示「≈」，不假裝是精確值。
    var _measureMarkerColor = '#00b0ff', _measureRodColor = '#ff6d00';

    function _vlen(a, b) {
        var dx = a[0]-b[0], dy = a[1]-b[1], dz = a[2]-b[2];
        return Math.sqrt(dx*dx+dy*dy+dz*dz);
    }
    function _pointToSegmentDistance(p, a, b) {
        var abx=b[0]-a[0], aby=b[1]-a[1], abz=b[2]-a[2];
        var apx=p[0]-a[0], apy=p[1]-a[1], apz=p[2]-a[2];
        var abLenSq = abx*abx+aby*aby+abz*abz;
        var t = abLenSq > 1e-12 ? (apx*abx+apy*aby+apz*abz)/abLenSq : 0;
        t = Math.max(0, Math.min(1, t));
        var cx=a[0]+abx*t, cy=a[1]+aby*t, cz=a[2]+abz*t;
        return _vlen(p, [cx,cy,cz]);
    }
    // 兩條三維線段之間的最短距離（經典解法：先求無限直線的最近參數，再夾到 [0,1]）
    function _segmentToSegmentDistance(p1, p2, p3, p4) {
        var d1=[p2[0]-p1[0],p2[1]-p1[1],p2[2]-p1[2]];
        var d2=[p4[0]-p3[0],p4[1]-p3[1],p4[2]-p3[2]];
        var r=[p1[0]-p3[0],p1[1]-p3[1],p1[2]-p3[2]];
        function dot(a,b){ return a[0]*b[0]+a[1]*b[1]+a[2]*b[2]; }
        var a=dot(d1,d1), e=dot(d2,d2), f=dot(d2,r);
        var s, t;
        if (a <= 1e-12 && e <= 1e-12) { s=0; t=0; }
        else if (a <= 1e-12) { s=0; t=Math.max(0,Math.min(1, f/e)); }
        else {
            var c = dot(d1,r);
            if (e <= 1e-12) { t=0; s=Math.max(0,Math.min(1, -c/a)); }
            else {
                var b = dot(d1,d2);
                var denom = a*e-b*b;
                s = denom > 1e-12 ? Math.max(0,Math.min(1,(b*f-c*e)/denom)) : 0;
                t = (b*s+f)/e;
                if (t < 0) { t=0; s=Math.max(0,Math.min(1,-c/a)); }
                else if (t > 1) { t=1; s=Math.max(0,Math.min(1,(b-c)/a)); }
            }
        }
        var c1=[p1[0]+d1[0]*s,p1[1]+d1[1]*s,p1[2]+d1[2]*s];
        var c2=[p3[0]+d2[0]*t,p3[1]+d2[1]*t,p3[2]+d2[2]*t];
        return _vlen(c1,c2);
    }
    // 點到三角形的最近距離（精確，含投影落在三角形外要夾到最近邊/頂點的情況）
    function _pointToTriangleDistance(p, a, b, c) {
        function sub(u,v){ return [u[0]-v[0],u[1]-v[1],u[2]-v[2]]; }
        function dot(u,v){ return u[0]*v[0]+u[1]*v[1]+u[2]*v[2]; }
        var ab=sub(b,a), ac=sub(c,a), ap=sub(p,a);
        var d1=dot(ab,ap), d2=dot(ac,ap);
        if (d1<=0 && d2<=0) return _vlen(p,a);
        var bp=sub(p,b);
        var d3=dot(ab,bp), d4=dot(ac,bp);
        if (d3>=0 && d4<=d3) return _vlen(p,b);
        var vc=d1*d4-d3*d2;
        if (vc<=0 && d1>=0 && d3<=0) { var v=d1/(d1-d3); return _vlen(p,[a[0]+ab[0]*v,a[1]+ab[1]*v,a[2]+ab[2]*v]); }
        var cp=sub(p,c);
        var d5=dot(ab,cp), d6=dot(ac,cp);
        if (d6>=0 && d5<=d6) return _vlen(p,c);
        var vb=d5*d2-d1*d6;
        if (vb<=0 && d2>=0 && d6<=0) { var w=d2/(d2-d6); return _vlen(p,[a[0]+ac[0]*w,a[1]+ac[1]*w,a[2]+ac[2]*w]); }
        var va=d3*d6-d5*d4;
        if (va<=0 && (d4-d3)>=0 && (d5-d6)>=0) {
            var w2=(d4-d3)/((d4-d3)+(d5-d6));
            return _vlen(p,[b[0]+(c[0]-b[0])*w2, b[1]+(c[1]-b[1])*w2, b[2]+(c[2]-b[2])*w2]);
        }
        var denom=1/(va+vb+vc), v2=vb*denom, w3=vc*denom;
        return _vlen(p,[a[0]+ab[0]*v2+ac[0]*w3, a[1]+ab[1]*v2+ac[1]*w3, a[2]+ab[2]*v2+ac[2]*w3]);
    }

    // 某個 mesh 上一組三角形（同一面群組）在「世界座標」下的三角形頂點清單＋面積＋重心
    function _groupWorldTriangles(mesh, triIndices) {
        var graph = _buildFaceGraph(mesh);
        var posArr = mesh.geometry.attributes.position.array;
        mesh.updateMatrixWorld(true);
        var m = mesh.matrixWorld.elements;
        function toWorld(lx,ly,lz) {
            return [
                m[0]*lx+m[4]*ly+m[8]*lz+m[12],
                m[1]*lx+m[5]*ly+m[9]*lz+m[13],
                m[2]*lx+m[6]*ly+m[10]*lz+m[14]
            ];
        }
        var tris = [], area = 0, cx=0, cy=0, cz=0, wsum=0;
        triIndices.forEach(function (ti) {
            var v = graph.getTri(ti);
            var a = toWorld(posArr[v[0]*3],posArr[v[0]*3+1],posArr[v[0]*3+2]);
            var b = toWorld(posArr[v[1]*3],posArr[v[1]*3+1],posArr[v[1]*3+2]);
            var c = toWorld(posArr[v[2]*3],posArr[v[2]*3+1],posArr[v[2]*3+2]);
            var ab=[b[0]-a[0],b[1]-a[1],b[2]-a[2]], ac=[c[0]-a[0],c[1]-a[1],c[2]-a[2]];
            var cxp=ab[1]*ac[2]-ab[2]*ac[1], cyp=ab[2]*ac[0]-ab[0]*ac[2], czp=ab[0]*ac[1]-ab[1]*ac[0];
            var triArea = 0.5*Math.sqrt(cxp*cxp+cyp*cyp+czp*czp);
            area += triArea;
            var tcx=(a[0]+b[0]+c[0])/3, tcy=(a[1]+b[1]+c[1])/3, tcz=(a[2]+b[2]+c[2])/3;
            cx += tcx*triArea; cy += tcy*triArea; cz += tcz*triArea; wsum += triArea;
            tris.push([a,b,c]);
        });
        var centroid = wsum > 1e-9 ? [cx/wsum, cy/wsum, cz/wsum] : (tris.length ? [(tris[0][0][0]+tris[0][1][0]+tris[0][2][0])/3,(tris[0][0][1]+tris[0][1][1]+tris[0][2][1])/3,(tris[0][0][2]+tris[0][1][2]+tris[0][2][2])/3] : [0,0,0]);
        return { tris: tris, area: area, centroid: centroid };
    }
    function _distancePointToTriSet(p, triSet) {
        var best = Infinity;
        triSet.forEach(function (t) { best = Math.min(best, _pointToTriangleDistance(p, t[0], t[1], t[2])); });
        return best;
    }
    // 近似值：兩組三角形的頂點兩兩比較取最小（face-face／edge-face 用，標「≈」）
    function _approxMinDistanceTriSets(triSetA, triSetB) {
        var best = Infinity;
        var vertsA = [], vertsB = [];
        triSetA.forEach(function (t) { vertsA.push(t[0], t[1], t[2]); });
        triSetB.forEach(function (t) { vertsB.push(t[0], t[1], t[2]); });
        for (var i = 0; i < vertsA.length; i++) {
            for (var j = 0; j < vertsB.length; j++) { best = Math.min(best, _vlen(vertsA[i], vertsB[j])); }
        }
        return best;
    }

    // 在「點到的那個同面群組」範圍內，收集全部「特徵邊」（網格邊界，或與相鄰群組的交界）
    // ——三角網格沒有保留 CAD 的邊/面拓樸，這是唯一能反推出「這算一條邊」的依據。
    // 回傳 [{va,vb,a,b,len}]，va/vb 是頂點索引、a/b 是世界座標、len 是該小段本身長度。
    function _collectFeatureEdges(mesh, groupTriIndices) {
        var graph = _buildFaceGraph(mesh);
        var groupSet = new Set(groupTriIndices);
        var posArr = mesh.geometry.attributes.position.array;
        mesh.updateMatrixWorld(true);
        var m = mesh.matrixWorld.elements;
        function toWorld(vi) {
            var lx=posArr[vi*3], ly=posArr[vi*3+1], lz=posArr[vi*3+2];
            return [ m[0]*lx+m[4]*ly+m[8]*lz+m[12], m[1]*lx+m[5]*ly+m[9]*lz+m[13], m[2]*lx+m[6]*ly+m[10]*lz+m[14] ];
        }
        var seen = new Set();   // 同一條邊在相鄰兩三角形各自列舉時只收一次
        var out = [];
        groupTriIndices.forEach(function (ti) {
            var v = graph.getTri(ti);
            var edges = [[v[0],v[1]],[v[1],v[2]],[v[2],v[0]]];
            var neighbors = graph.adjacency[ti];
            edges.forEach(function (edge) {
                var sharedInGroup = false;
                for (var k = 0; k < neighbors.length; k++) {
                    var nb = neighbors[k];
                    if (!groupSet.has(nb)) continue;
                    var nv = graph.getTri(nb);
                    if (nv.indexOf(edge[0]) >= 0 && nv.indexOf(edge[1]) >= 0) { sharedInGroup = true; break; }
                }
                if (sharedInGroup) return;   // 群組內部邊，不是特徵邊
                var key = edge[0] < edge[1] ? (edge[0]+'_'+edge[1]) : (edge[1]+'_'+edge[0]);
                if (seen.has(key)) return;
                seen.add(key);
                var a = toWorld(edge[0]), b = toWorld(edge[1]);
                out.push({ va: edge[0], vb: edge[1], a: a, b: b, len: _vlen(a, b) });
            });
        });
        return out;
    }
    // 找離 worldPoint 最近的特徵邊，並沿著頂點度數=2 的連續邊鏈往兩端延伸，直到形成封閉
    // 迴圈（例如一個圓孔的完整圓周）或遇到端點/分岔（開放邊界，或這條邊牽到別的特徵交會
    // 處就停止，避免誤接成不相關的另一段）。回傳 {a,b}＝最近那一小段（給標記點定位用）、
    // totalLen＝整條邊鏈/迴圈的總長度、closed＝是否形成封閉迴圈。
    function _nearestFeatureEdgeLoop(mesh, groupTriIndices, worldPoint) {
        var edges = _collectFeatureEdges(mesh, groupTriIndices);
        if (!edges.length) return null;
        var bestIdx = -1, bestDist = Infinity;
        edges.forEach(function (e, i) {
            var d = _pointToSegmentDistance(worldPoint, e.a, e.b);
            if (d < bestDist) { bestDist = d; bestIdx = i; }
        });
        var nearest = edges[bestIdx];
        // 建「頂點 → 牽到哪幾條邊（邊陣列索引）」，供沿鏈延伸用
        var vertEdges = new Map();
        edges.forEach(function (e, i) {
            [e.va, e.vb].forEach(function (vi) {
                if (!vertEdges.has(vi)) vertEdges.set(vi, []);
                vertEdges.get(vi).push(i);
            });
        });
        var usedIdx = new Set([bestIdx]);
        var totalLen = nearest.len;
        var closed = false;
        // 分別往 va 端、vb 端延伸
        [nearest.va, nearest.vb].forEach(function (startVert) {
            var curVert = startVert;
            for (var guard = 0; guard < edges.length + 1; guard++) {
                var candidates = (vertEdges.get(curVert) || []).filter(function (i) { return !usedIdx.has(i); });
                if (candidates.length !== 1) break;   // 端點（0）或分岔（>1）都停止，分岔不猜著接
                var idx = candidates[0];
                usedIdx.add(idx);
                var e = edges[idx];
                totalLen += e.len;
                curVert = e.va === curVert ? e.vb : e.va;
                if (curVert === nearest.va || curVert === nearest.vb) { closed = true; break; }   // 繞回起點，封閉迴圈
            }
        });
        return { a: nearest.a, b: nearest.b, totalLen: totalLen, closed: closed, segCount: usedIdx.size };
    }

    function _fmtMm(v) { return (Math.round(v * 100) / 100).toString(); }

    // ── 標記幾何（八面體小點／連接細桿），一樣用借用建構子那招，不靠全域 THREE ────────
    function _buildMarkerGeom(refMesh, size) {
        var GeoCtor = refMesh.geometry.constructor;
        var geo = new GeoCtor();
        var s = size;
        var verts = new Float32Array([ s,0,0, -s,0,0, 0,s,0, 0,-s,0, 0,0,s, 0,0,-s ]);
        var PosAttrCtor = refMesh.geometry.attributes.position.constructor;
        geo.setAttribute('position', new PosAttrCtor(verts, 3));
        geo.computeVertexNormals();
        return geo;
    }
    function _buildRodGeom(refMesh, p1, p2, thickness) {
        var GeoCtor = refMesh.geometry.constructor;
        var geo = new GeoCtor();
        var dx=p2[0]-p1[0], dy=p2[1]-p1[1], dz=p2[2]-p1[2];
        var len = Math.sqrt(dx*dx+dy*dy+dz*dz) || 1;
        var ux=dx/len, uy=dy/len, uz=dz/len;
        // 找一個跟方向不平行的參考軸算出垂直基底，建一根細長方柱當「桿」
        var ref = Math.abs(uy) < 0.9 ? [0,1,0] : [1,0,0];
        var perp1 = [ uy*ref[2]-uz*ref[1], uz*ref[0]-ux*ref[2], ux*ref[1]-uy*ref[0] ];
        var pl = Math.sqrt(perp1[0]*perp1[0]+perp1[1]*perp1[1]+perp1[2]*perp1[2]) || 1;
        perp1 = [perp1[0]/pl, perp1[1]/pl, perp1[2]/pl];
        var perp2 = [ uy*perp1[2]-uz*perp1[1], uz*perp1[0]-ux*perp1[2], ux*perp1[1]-uy*perp1[0] ];
        var t = thickness;
        function off(base, s1, s2) {
            return [ base[0]+perp1[0]*s1+perp2[0]*s2, base[1]+perp1[1]*s1+perp2[1]*s2, base[2]+perp1[2]*s1+perp2[2]*s2 ];
        }
        var v = [
            off(p1,-t,-t), off(p1,t,-t), off(p1,t,t), off(p1,-t,t),
            off(p2,-t,-t), off(p2,t,-t), off(p2,t,t), off(p2,-t,t)
        ];
        var faces = [
            [0,1,2],[0,2,3], [4,6,5],[4,7,6],
            [0,4,5],[0,5,1], [1,5,6],[1,6,2],
            [2,6,7],[2,7,3], [3,7,4],[3,4,0]
        ];
        var verts = new Float32Array(v.length * 3);
        v.forEach(function (p, i) { verts[i*3]=p[0]; verts[i*3+1]=p[1]; verts[i*3+2]=p[2]; });
        var idxArr = [];
        faces.forEach(function (f) { idxArr.push(f[0], f[1], f[2]); });
        var PosAttrCtor = refMesh.geometry.attributes.position.constructor;
        geo.setAttribute('position', new PosAttrCtor(verts, 3));
        geo.setIndex(idxArr);
        geo.computeVertexNormals();
        return geo;
    }
    function _buildMarkerMaterial(refMesh, hexColor) {
        var baseMat = Array.isArray(refMesh.material) ? refMesh.material[0] : refMesh.material;
        var mat = baseMat.clone();
        mat.vertexColors = false;
        mat.color.set(hexColor);
        mat.side = 2;              // DoubleSide：量測標記本來就不該有「背面看不到」的問題
        mat.depthTest = false;     // 一律蓋在模型之上，不會被模型本體擋住（已實測驗證必要）
        mat.depthWrite = false;
        mat.needsUpdate = true;
        return mat;
    }

    // ── MeasureState：點／線／面量測狀態機，滑動視窗保留「最近兩筆」算兩者距離 ─────────
    function MeasureState() {
        this.picks = [];          // 最多保留 2 筆：{kind:'point'|'edge'|'face', worldPoint, selfValue, selfLabel, mesh}
        this.extraObjs = [];      // 目前加進 viewer 的標記物件（供之後清除參考用，實際清除交給 ClearExtra）
    }
    MeasureState.prototype._pushPick = function (pick) {
        this.picks.push(pick);
        if (this.picks.length > 2) this.picks.shift();
    };
    MeasureState.prototype.pickPoint = function (embeddedViewer, canvasEl, clientX, clientY) {
        var hit = pickMesh(embeddedViewer, canvasEl, clientX, clientY);
        if (!hit || !hit.object || !hit.point) return false;
        this._pushPick({ kind: 'point', worldPoint: [hit.point.x, hit.point.y, hit.point.z], mesh: hit.object });
        this._render(embeddedViewer);
        return true;
    };
    MeasureState.prototype.pickEdge = function (embeddedViewer, canvasEl, clientX, clientY, angleDeg) {
        var hit = pickMesh(embeddedViewer, canvasEl, clientX, clientY);
        if (!hit || !hit.object || hit.faceIndex == null) return false;
        var group = sameFaceTriangles(hit.object, hit.faceIndex, angleDeg);
        var edge = _nearestFeatureEdgeLoop(hit.object, group, [hit.point.x, hit.point.y, hit.point.z]);
        if (!edge) return false;
        var mid = [(edge.a[0]+edge.b[0])/2, (edge.a[1]+edge.b[1])/2, (edge.a[2]+edge.b[2])/2];
        // 沿著連續特徵邊走一圈算出的總長度（例如一個圓孔的完整圓周，不是只有點到的那一小段
        // 三角化線段）；selfLabel 依是否繞成封閉迴圈標示清楚，三角化愈細誤差愈小。
        this._pushPick({
            kind: 'edge', worldPoint: mid, a: edge.a, b: edge.b, selfValue: edge.totalLen,
            selfLabel: edge.closed ? '邊線總長（封閉迴圈，如圓孔周長）' : '邊線總長（開放端）',
            mesh: hit.object
        });
        this._render(embeddedViewer);
        return true;
    };
    MeasureState.prototype.pickFace = function (embeddedViewer, canvasEl, clientX, clientY, angleDeg) {
        var hit = pickMesh(embeddedViewer, canvasEl, clientX, clientY);
        if (!hit || !hit.object || hit.faceIndex == null) return false;
        var group = sameFaceTriangles(hit.object, hit.faceIndex, angleDeg);
        var gw = _groupWorldTriangles(hit.object, group);
        this._pushPick({ kind: 'face', worldPoint: gw.centroid, tris: gw.tris, selfValue: gw.area, selfLabel: '面積', mesh: hit.object, areaUnit: 'mm²' });
        this._render(embeddedViewer);
        return true;
    };
    MeasureState.prototype.clearAll = function (embeddedViewer) {
        this.picks = [];
        this.extraObjs = [];
        if (embeddedViewer) embeddedViewer.GetViewer().ClearExtra();
    };
    // 畫面上的標記：每一筆各一顆小標記點，兩筆之間再加一根連接桿
    MeasureState.prototype._render = function (embeddedViewer) {
        var viewer = embeddedViewer.GetViewer();
        viewer.ClearExtra();
        this.extraObjs = [];
        if (!this.picks.length) { viewer.Render(); return; }
        var refMesh = this.picks[this.picks.length - 1].mesh;
        refMesh.geometry.computeBoundingSphere();
        var modelScale = Math.max(1, (refMesh.geometry.boundingSphere ? refMesh.geometry.boundingSphere.radius : 20) * 0.015);
        var self = this;
        this.picks.forEach(function (p) {
            var geo = _buildMarkerGeom(refMesh, modelScale);
            var mat = _buildMarkerMaterial(refMesh, _measureMarkerColor);
            var MeshCtor = refMesh.constructor;
            var marker = new MeshCtor(geo, mat);
            marker.position.set(p.worldPoint[0], p.worldPoint[1], p.worldPoint[2]);
            marker.renderOrder = 9999;
            viewer.AddExtraObject(marker);
            self.extraObjs.push(marker);
        });
        if (this.picks.length === 2) {
            var geoR = _buildRodGeom(refMesh, this.picks[0].worldPoint, this.picks[1].worldPoint, modelScale * 0.18);
            var matR = _buildMarkerMaterial(refMesh, _measureRodColor);
            var MeshCtor2 = refMesh.constructor;
            var rod = new MeshCtor2(geoR, matR);
            rod.renderOrder = 9998;
            viewer.AddExtraObject(rod);
            this.extraObjs.push(rod);
        }
        viewer.Render();
    };
    // 計算「最近兩筆」之間的距離（exact／≈），回傳 {text, html}；picks 不足兩筆時回傳每一筆的自身量測
    MeasureState.prototype.getSummaryHtml = function () {
        if (!this.picks.length) return '尚未量測，請點選模型上的點／線／面';
        var lines = [];
        var kindLabel = { point: '點', edge: '線', face: '面' };
        this.picks.forEach(function (p, i) {
            var s = '第' + (i+1) + '筆（' + kindLabel[p.kind] + '）';
            if (p.selfValue != null) s += '：' + p.selfLabel + ' = ' + _fmtMm(p.selfValue) + ' ' + (p.areaUnit || 'mm');
            lines.push(s);
        });
        if (this.picks.length === 2) {
            var A = this.picks[0], B = this.picks[1];
            var approx = false, dist;
            if (A.kind === 'point' && B.kind === 'point') { dist = _vlen(A.worldPoint, B.worldPoint); }
            else if (A.kind === 'point' && B.kind === 'edge') { dist = _pointToSegmentDistance(A.worldPoint, B.a, B.b); }
            else if (A.kind === 'edge' && B.kind === 'point') { dist = _pointToSegmentDistance(B.worldPoint, A.a, A.b); }
            else if (A.kind === 'edge' && B.kind === 'edge') { dist = _segmentToSegmentDistance(A.a, A.b, B.a, B.b); }
            else if (A.kind === 'point' && B.kind === 'face') { dist = _distancePointToTriSet(A.worldPoint, B.tris); }
            else if (A.kind === 'face' && B.kind === 'point') { dist = _distancePointToTriSet(B.worldPoint, A.tris); }
            else if (A.kind === 'face' && B.kind === 'face') { dist = _approxMinDistanceTriSets(A.tris, B.tris); approx = true; }
            else {
                // edge-face 兩種排列：用邊的兩端點到面三角形集合的最短距離，近似值
                var edgeSide = A.kind === 'edge' ? A : B;
                var faceSide = A.kind === 'face' ? A : B;
                dist = Math.min(_distancePointToTriSet(edgeSide.a, faceSide.tris), _distancePointToTriSet(edgeSide.b, faceSide.tris));
                approx = true;
            }
            lines.push((approx ? '≈ ' : '') + '兩者距離：' + _fmtMm(dist) + ' mm' + (approx ? '（概略值，含面的量測無法做到完全精確）' : ''));
        }
        return lines.join('<br>');
    };

    // ── 整合滑鼠互動：點一下＝依目前模式（點/線/面）記錄一筆並更新畫面與結果文字 ──────
    // containerEl／canvasSelector 與 attachColorInteraction 同一套規則（見上方）。
    // getCtx() 回傳 {embeddedViewer, measureState}；getModeFn() 回傳 'point'|'edge'|'face'|null。
    function attachMeasureInteraction(containerEl, canvasSelector, getCtx, getModeFn, onUpdate) {
        if (!containerEl) return;
        containerEl.addEventListener('mousedown', function (e) {
            if (!getModeFn() || e.button !== 0) return;
            e.preventDefault();
            e.stopPropagation();
        }, true);
        containerEl.addEventListener('click', function (e) {
            var mode = getModeFn();
            if (!mode) return;
            var ctx = getCtx();
            if (!ctx || !ctx.embeddedViewer || !ctx.measureState) return;
            var canvasEl = containerEl.querySelector(canvasSelector);
            if (!canvasEl) return;
            var ok = false;
            if (mode === 'point') ok = ctx.measureState.pickPoint(ctx.embeddedViewer, canvasEl, e.clientX, e.clientY);
            else if (mode === 'edge') ok = ctx.measureState.pickEdge(ctx.embeddedViewer, canvasEl, e.clientX, e.clientY);
            else if (mode === 'face') ok = ctx.measureState.pickFace(ctx.embeddedViewer, canvasEl, e.clientX, e.clientY);
            if (ok && typeof onUpdate === 'function') onUpdate(ctx.measureState);
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // 透視（隱藏線）顯示：被遮蔽的部份用「較淡的線條」表示，不是整片灰色實體
    // ══════════════════════════════════════════════════════════════════════
    // 使用者原始要求是「像 Inventor 那樣，被遮蔽的部份用虛線表示」。查證後決定改用
    // 「深色實線（可見邊）＋淺灰實線（被遮蔽邊）」兩色呈現，不是真正的虛線，原因：
    // Three.js 的虛線需要 LineDashedMaterial（內部有另一套 shader，判斷依據是
    // material.isLineDashedMaterial，不是隨便一個材質設個 dashSize 屬性就會變虛線）；
    // 已查證 o3dv.min.js 內部雖然打包了 LineDashedMaterial 的程式碼（dashSize/gapSize/
    // computeLineDistances 都在），但函式庫自己從來沒有實際建立過這個類別的實例，所以
    // 没有「借既有實例的建構子」這條路可走（上色/標記點都是靠這招才不需要全域 THREE，
    // 這裡沒有現成實例可借）。真要做到位需要自己手刻一段客製 shader（material.
    // onBeforeCompile 注入 GLSL），風險與工時都高出一截；改用「顏色深淺」區分可見／
    // 被遮蔽的邊，視覺上一樣能清楚看穿模型內部，且技術上更穩健（純粹材質屬性操作，
    // 跟上色/量測標記同一套已驗證可行的手法），已實測確認效果清楚可用。
    //
    // 做法：①開啟函式庫內建的邊線產生（EdgeSettings，依法向量夾角門檻抓出硬邊，
    // 回傳真正的 THREE.LineSegments）②複製兩份：一份正常深度測試（可見邊，深色）、
    // 一份深度測試反轉成「只有在既有深度更淺時才畫」＝GreaterDepth（6，Three.js 的
    // 深度函式列舉值，被遮蔽邊，淺灰）③原本的實體面材質只關閉 colorWrite（不關閉
    // depthWrite／不隱藏 mesh），面本身仍正常寫入深度緩衝供②的遮蔽判斷用、也不擋
    // 滑鼠 raycast（上色/量測在透視模式下一樣點得到），畫面上只是不會畫出灰色實體。
    // 兩份邊線物件透過 viewer.AddExtraObject() 疊加——**刻意不呼叫 viewer.ClearExtra()
    // 清除**，因為那會把量測標記也一併清掉；關閉透視模式時改用 extraModel.
    // GetRootObject().remove(...) 只移除這兩個物件本身，不影響量測/上色的其他疊加物件。
    var _GREATER_DEPTH = 6;   // Three.js 的 DepthModes 列舉值（NeverDepth=0...GreaterDepth=6），無全域 THREE 可借，直接用數字
    function setHiddenLineMode(embeddedViewer) {
        var viewer = embeddedViewer.GetViewer();
        var mm = viewer.mainModel;
        var settings = new OV.EdgeSettings(true, new OV.RGBColor(26, 26, 26), 1);
        mm.SetEdgeSettings(settings);
        var edgeObj = null;
        mm.EnumerateEdges(function (o) { if (!edgeObj) edgeObj = o; });
        if (!edgeObj) return null;   // 這個模型沒有偵測到任何硬邊（極少見，例如完全平滑的球體）

        var LineCtor = edgeObj.constructor;
        var frontMat = edgeObj.material.clone();
        frontMat.color.set('#1a1a1a');
        frontMat.needsUpdate = true;
        var frontObj = new LineCtor(edgeObj.geometry, frontMat);
        frontObj.renderOrder = 10;

        var backMat = edgeObj.material.clone();
        backMat.color.set('#c7c7c7');
        backMat.depthFunc = _GREATER_DEPTH;
        backMat.depthWrite = false;
        backMat.needsUpdate = true;
        var backObj = new LineCtor(edgeObj.geometry, backMat);
        backObj.renderOrder = 5;

        viewer.AddExtraObject(frontObj);
        viewer.AddExtraObject(backObj);

        var touchedMats = [];
        mm.EnumerateMeshes(function (o) {
            var mats = Array.isArray(o.material) ? o.material : [o.material];
            mats.forEach(function (mt) {
                if (mt.colorWrite === false) return;   // 已經處理過（同一份材質被多個 mesh 共用時避免重複記錄）
                touchedMats.push(mt);
                mt.colorWrite = false;
                mt.needsUpdate = true;
            });
        });
        viewer.Render();
        return { frontObj: frontObj, backObj: backObj, touchedMats: touchedMats };
    }
    function clearHiddenLineMode(embeddedViewer, state) {
        if (!embeddedViewer || !state) return;
        var viewer = embeddedViewer.GetViewer();
        var mm = viewer.mainModel;
        var root = viewer.extraModel.GetRootObject();
        if (state.frontObj) root.remove(state.frontObj);
        if (state.backObj) root.remove(state.backObj);
        mm.SetEdgeSettings(new OV.EdgeSettings(false, new OV.RGBColor(0, 0, 0), 1));
        (state.touchedMats || []).forEach(function (mt) { mt.colorWrite = true; mt.needsUpdate = true; });
        viewer.Render();
    }

    function escHtml3d(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return {
        pickMesh: pickMesh,
        sameFaceTriangles: sameFaceTriangles,
        pickFaceGroup: pickFaceGroup,
        pickFaceGroupsInRect: pickFaceGroupsInRect,
        attachColorInteraction: attachColorInteraction,
        ColorState: ColorState,
        screenshot: screenshot,
        printCurrentView: printCurrentView,
        openInImageEditor: openInImageEditor,
        MeasureState: MeasureState,
        attachMeasureInteraction: attachMeasureInteraction,
        setHiddenLineMode: setHiddenLineMode,
        clearHiddenLineMode: clearHiddenLineMode
    };
})();
