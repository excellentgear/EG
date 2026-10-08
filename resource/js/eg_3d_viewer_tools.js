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
        openInImageEditor: openInImageEditor
    };
})();
