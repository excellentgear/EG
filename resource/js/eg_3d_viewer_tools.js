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

    // 剖面（section）開啟時，材質的 clippingPlanes 只是渲染端（shader 逐 fragment）的
    // 效果——函式庫自己的 GetMeshIntersectionUnderMouse 是標準 Three.js Raycaster，完全
    // 不知道 clippingPlanes 這回事，對已經「看不到」的被切除區域照樣能命中（那些三角形
    // 在幾何上其實還在，只是渲染時被剪掉），所以點一下剖面切開的洞裡面，實際上會選到
    // 洞「對面」或「裡面」那些原本該被隱藏的三角形——這正是 2026-10-12 使用者回報「剖面
    // 是否無法自動辨識被剖開的是實心面」背後的真因：點了看起來空的地方，上色卻跑到奇怪
    // 的地方去。修法：命中結果的世界座標點，逐一代入該 mesh 材質目前的 clippingPlanes
    // 公式驗證是否會被剪掉，會的話一律當成沒點到（不是去猜下一個更近的交點，函式庫沒有
    // 開放拿到完整交點清單，這個已經足夠解決「點了卻跑到別處」的誤導）。
    function _hitIsClipped(hit) {
        if (!hit || !hit.object || !hit.point) return false;
        var mats = Array.isArray(hit.object.material) ? hit.object.material : [hit.object.material];
        var px = hit.point.x, py = hit.point.y, pz = hit.point.z;
        for (var i = 0; i < mats.length; i++) {
            var mt = mats[i];
            var planes = mt && mt.clippingPlanes;
            if (!planes || !planes.length) continue;
            if (mt.clipIntersection) {
                // AND 語意（雙平面楔形/象限剖面）：每一個平面都判定「切除」才真的切除
                var allDiscard = true;
                for (var j = 0; j < planes.length; j++) {
                    var pl = planes[j];
                    var d = pl.normal.x * px + pl.normal.y * py + pl.normal.z * pz + pl.constant;
                    if (d >= 0) { allDiscard = false; break; }
                }
                if (allDiscard) return true;
            } else {
                // OR 語意（單平面剖面）：任一平面判定切除就切除
                for (var k = 0; k < planes.length; k++) {
                    var pl2 = planes[k];
                    var d2 = pl2.normal.x * px + pl2.normal.y * py + pl2.normal.z * pz + pl2.constant;
                    if (d2 < 0) return true;
                }
            }
        }
        return false;
    }
    // ── 滑鼠座標 → 畫布像素座標 → 命中結果（含 .object/.face/.faceIndex）────────
    function pickMesh(embeddedViewer, canvasEl, clientX, clientY) {
        if (!embeddedViewer || typeof OV === 'undefined') return null;
        var viewer = embeddedViewer.GetViewer();
        var rect = canvasEl.getBoundingClientRect();
        var x = clientX - rect.left;
        var y = clientY - rect.top;
        var size = viewer.GetCanvasSize();
        if (x < 0 || y < 0 || x > size.width || y > size.height) return null;
        var hit = viewer.GetMeshIntersectionUnderMouse(OV.IntersectionMode.MeshOnly, { x: x, y: y });
        if (_hitIsClipped(hit)) return null;
        return hit;
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
        // vertKey 一併存進 graph 讓外部（如 _collectFeatureEdges）共用同一套「同一個頂點」
        // 判定——不可以在別處用原始陣列索引做 .indexOf() 比對代替，non-indexed 幾何每個
        // 三角形的頂點都是各自獨立複製的陣列位置，原始索引永遠不會重複，那樣比對永遠比
        // 不到，等於完全比不出「這條邊是不是跟群組內的鄰居共用」（2026-10-08 實測踩到：
        // 整個模型 3186 個三角形被誤判成 9540 條「特徵邊」，幾乎等於全部原始邊一條都沒
        // 被濾掉，畫出來的透視線框因此變成蜘蛛網——不是角度門檻的問題，是這裡比對錯了）。
        var graph = { triCount: triCount, getTri: getTri, normals: normals, adjacency: adjacency, vertKey: vertKey };
        _faceGraphCache.set(mesh, graph);
        return graph;
    }
    // 從 startFaceIndex 開始，往鄰接三角形 flood fill，逐鄰居局部比較法向量夾角
    // （不是都跟起點比，才能正確吃到整段有弧度的曲面）；回傳三角形索引陣列。
    // 預設角度門檻（2026-10-12 使用者回報「上色無法直接上整個內孔的顏色」後由 12→20 度，
    // 唯一受影響的呼叫端是上色互動 attachColorInteraction——其餘呼叫端如
    // _buildCleanEdgeGeometry 皆自己明確帶入角度參數不受這裡的預設值影響）：用實際模型
    // （80 ADAPTER BASE，pk=26903）逐一實測多個小孔內壁三角形的成長曲線才找出來的安全
    // 區間——這批小孔單圈只切出約 14~16 個面（每格跨角約 22.5~25.7 度），用舊的 12 度
    // 連第一步都跨不過去（flood fill 只選到起點那 1~2 個三角形就停了，使用者點一次只
    // 塗到一小條）；但同一批小孔從 14 度到 44 度選到的三角形數完全沒有變化（穩定在
    // 52／156 個，代表這就是該孔內壁自然的完整邊界，不是被門檻卡住），過了 44 度才會
    // 在 46 度附近暴衝到 1000+ 個（混進完全不相干的其他面，真正的過度合併）。20 度落
    // 在「連得起小孔整圈」與「暴衝邊界」中間，兩側都留了十幾度的緩衝帶，刻意不要調更高
    // ——那只是在縮小安全邊界，沒有實益。
    function sameFaceTriangles(mesh, startFaceIndex, angleDeg) {
        var graph = _buildFaceGraph(mesh);
        if (startFaceIndex == null || startFaceIndex < 0 || startFaceIndex >= graph.triCount) return [];
        var cosThresh = Math.cos((angleDeg == null ? 20 : angleDeg) * Math.PI / 180);
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

    // ── 整合滑鼠互動：單點＝點一下自動判定同一面，按住 Ctrl 拖曳＝框選，純拖曳（不按
    // Ctrl）＝正常旋轉視角 ──────────────────────────────────────────────────
    // containerEl：3D 檢視器外層容器（需 position:relative/absolute，拖曳方框疊加其上，
    //   兩頁的 #bom-3d-wrap／#pav-preview 都是靜態存在、不會被 innerHTML 整個換掉的容器，
    //   所以可以在頁面載入時綁定一次，不必像內層 canvas 容器那樣擔心換檔後失聯）。
    // canvasSelector：在 containerEl 底下找畫布的選擇器（例如 '#bom-3d-viewer canvas'）。
    // getCtx()：互動當下即時回傳 {embeddedViewer, colorState, hexColor}（每次切檔案這三個
    //   都會換，所以用函式取得當下最新值，不要傳入當下的值）。
    // isActiveFn()：回傳目前是否處於上色模式（呼叫端自己的開關按鈕狀態）。
    //
    // 2026-10-12 使用者回報「上色時無法左鍵旋轉」：原本上色模式一開，整個 mousedown
    // 在捕獲階段就被攔下（stopPropagation），不管是不是真的要拖框選都先擋掉，等於上色中
    // 完全沒辦法用滑鼠左鍵轉視角、要轉得先關掉上色模式再開回來，體驗很差。改成**只有按住
    // Ctrl（或 Mac 的 Cmd）才攔截**：放開 Ctrl 的純拖曳完全不呼叫 preventDefault／
    // stopPropagation，原生事件照常傳給 o3dv 的旋轉互動；按住 Ctrl 拖曳才是框選上色（維持
    // 原本行為不變）；不管有沒有按 Ctrl，只要「幾乎沒有移動」（沒超過 dragThreshold）都
    // 視為單純點一下＝點一下上色同一面（這是最常用的操作，兩種手勢都應該可以觸發）。
    function attachColorInteraction(containerEl, canvasSelector, getCtx, isActiveFn, opts) {
        if (!containerEl) return;
        opts = opts || {};
        var angleDeg = opts.angleDeg;
        var dragThreshold = opts.dragThreshold || 5;
        var rectEl = null, startX = 0, startY = 0, dragging = false, moved = false, rectMode = false;

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

        // 用「捕獲階段」(capture:true) 只是為了比 o3dv 早一步讀到這次 mousedown 按沒按
        // Ctrl——真的要攔截（框選模式）才呼叫 preventDefault／stopPropagation；純拖曳
        // （沒按 Ctrl）完全不擋，事件原封不動繼續往下傳給 o3dv 自己的旋轉處理。
        containerEl.addEventListener('mousedown', function (e) {
            if (!isActiveFn() || e.button !== 0) return;
            dragging = true; moved = false; startX = e.clientX; startY = e.clientY;
            rectMode = !!(e.ctrlKey || e.metaKey);
            if (rectMode) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
        window.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            if (Math.abs(e.clientX - startX) > dragThreshold || Math.abs(e.clientY - startY) > dragThreshold) {
                moved = true;
                if (rectMode) updateRect(startX, startY, e.clientX, e.clientY);
            }
        });
        window.addEventListener('mouseup', function (e) {
            if (!dragging) return;
            dragging = false;
            hideRect();
            if (!isActiveFn()) return;
            if (moved && !rectMode) return;   // 純拖曳旋轉視角，已經由 o3dv 原生處理，這裡不做任何上色動作
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
    // 同上，但回傳線段上最近的那個點（給水平/垂直分量分解用，不只是距離純量）
    function _pointToSegmentClosestPoint(p, a, b) {
        var abx=b[0]-a[0], aby=b[1]-a[1], abz=b[2]-a[2];
        var apx=p[0]-a[0], apy=p[1]-a[1], apz=p[2]-a[2];
        var abLenSq = abx*abx+aby*aby+abz*abz;
        var t = abLenSq > 1e-12 ? (apx*abx+apy*aby+apz*abz)/abLenSq : 0;
        t = Math.max(0, Math.min(1, t));
        return [a[0]+abx*t, a[1]+aby*t, a[2]+abz*t];
    }
    // 兩條三維線段之間的最短距離（經典解法：先求無限直線的最近參數，再夾到 [0,1]）；
    // 回傳 {c1,c2,dist}——c1/c2 是兩條線段上最近的那兩個點，供水平/垂直分量分解用
    // （量測結果不是只給一個斜邊總距離，使用者要能看出左右差多少、上下差多少）。
    function _segmentToSegmentClosest(p1, p2, p3, p4) {
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
        return { c1: c1, c2: c2, dist: _vlen(c1, c2) };
    }
    // 把兩個世界座標點的向量差拆成「水平距離」（XZ 平面，sqrt(ΔX²+ΔZ²)）與「垂直距離」
    // （|ΔY|）——比照 Inventor 的量測習慣，水平/垂直比單純的空間直線距離更實用（例如
    // 「這兩個孔左右差多少、上下差多少」）。本檢視器的相機 up 向量固定是 Y 軸（無論
    // 來源模型原始座標系為何，載入後一律以 Y 為垂直方向顯示），故此處一律用 Y 分量
    // 當「垂直」，不隨模型而異。
    function _horizVertBreakdown(p1, p2) {
        var dx = p2[0]-p1[0], dy = p2[1]-p1[1], dz = p2[2]-p1[2];
        return {
            dx: dx, dy: dy, dz: dz,
            horizontal: Math.sqrt(dx*dx + dz*dz),
            vertical: Math.abs(dy)
        };
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
                // 一定要用 graph.vertKey 比對「同一個頂點」，不可以直接比較原始陣列索引——
                // non-indexed 幾何每個三角形的頂點都是各自獨立複製的陣列位置，原始索引
                // 永遠不會重複，用 .indexOf() 比對會讓每一條邊都被誤判成「沒有共用鄰居」。
                var ka = graph.vertKey(edge[0]), kb = graph.vertKey(edge[1]);
                var sharedInGroup = false;
                for (var k = 0; k < neighbors.length; k++) {
                    var nb = neighbors[k];
                    if (!groupSet.has(nb)) continue;
                    var nv = graph.getTri(nb);
                    var nk0 = graph.vertKey(nv[0]), nk1 = graph.vertKey(nv[1]), nk2 = graph.vertKey(nv[2]);
                    if ((nk0===ka||nk1===ka||nk2===ka) && (nk0===kb||nk1===kb||nk2===kb)) { sharedInGroup = true; break; }
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
        var graph = _buildFaceGraph(mesh);
        var edges = _collectFeatureEdges(mesh, groupTriIndices);
        if (!edges.length) return null;
        var bestIdx = -1, bestDist = Infinity;
        edges.forEach(function (e, i) {
            var d = _pointToSegmentDistance(worldPoint, e.a, e.b);
            if (d < bestDist) { bestDist = d; bestIdx = i; }
        });
        var nearest = edges[bestIdx];
        // 建「頂點 → 牽到哪幾條邊（邊陣列索引）」，供沿鏈延伸用；鍵一定要用 graph.vertKey
        // （空間位置）不是原始索引——non-indexed 幾何同一個空間頂點在不同邊線物件上的
        // va/vb 原始索引不會相同，用原始索引當鍵永遠串不起來，走沒兩步就斷掉。
        var vertEdges = new Map();
        edges.forEach(function (e, i) {
            [graph.vertKey(e.va), graph.vertKey(e.vb)].forEach(function (vk) {
                if (!vertEdges.has(vk)) vertEdges.set(vk, []);
                vertEdges.get(vk).push(i);
            });
        });
        var usedIdx = new Set([bestIdx]);
        var totalLen = nearest.len;
        var closed = false;
        var startKeyA = graph.vertKey(nearest.va), startKeyB = graph.vertKey(nearest.vb);
        // 分別往 va 端、vb 端延伸
        [startKeyA, startKeyB].forEach(function (startKey) {
            var curKey = startKey;
            for (var guard = 0; guard < edges.length + 1; guard++) {
                var candidates = (vertEdges.get(curKey) || []).filter(function (i) { return !usedIdx.has(i); });
                if (candidates.length !== 1) break;   // 端點（0）或分岔（>1）都停止，分岔不猜著接
                var idx = candidates[0];
                usedIdx.add(idx);
                var e = edges[idx];
                totalLen += e.len;
                var eka = graph.vertKey(e.va), ekb = graph.vertKey(e.vb);
                curKey = eka === curKey ? ekb : eka;
                if (curKey === startKeyA || curKey === startKeyB) { closed = true; break; }   // 繞回起點，封閉迴圈
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
            var approx = false, dist, hv = null;   // hv＝{horizontal,vertical,dx,dy,dz}，只有點對點／線對線才算（精確值才拆分量，概略值拆了也沒有意義）
            if (A.kind === 'point' && B.kind === 'point') {
                dist = _vlen(A.worldPoint, B.worldPoint);
                hv = _horizVertBreakdown(A.worldPoint, B.worldPoint);
            }
            else if (A.kind === 'point' && B.kind === 'edge') { dist = _pointToSegmentDistance(A.worldPoint, B.a, B.b); }
            else if (A.kind === 'edge' && B.kind === 'point') { dist = _pointToSegmentDistance(B.worldPoint, A.a, A.b); }
            else if (A.kind === 'edge' && B.kind === 'edge') {
                var cs = _segmentToSegmentClosest(A.a, A.b, B.a, B.b);
                dist = cs.dist;
                hv = _horizVertBreakdown(cs.c1, cs.c2);
            }
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
            // 水平/垂直分量——比照 Inventor 的量測習慣，不是只給一個直線斜邊距離（本檢視器
            // 一律以 Y 軸當垂直方向，XZ 平面當水平面，見 _horizVertBreakdown 說明）
            if (hv) {
                lines.push('　水平距離 = ' + _fmtMm(hv.horizontal) + ' mm　|　垂直距離 = ' + _fmtMm(hv.vertical) + ' mm');
                lines.push('　ΔX = ' + _fmtMm(hv.dx) + '　ΔY = ' + _fmtMm(hv.dy) + '　ΔZ = ' + _fmtMm(hv.dz) + ' (mm)');
            }
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
    // 透視（隱藏線）顯示：可見邊實線、被遮蔽邊真正的虛線（2026-10-08 使用者兩輪
    // 回饋定案：①一定要是真虛線，不接受顏色深淺替代 ②圓弧面不可以把三角化網格的
    // 每一條小三角邊都畫出來，只能畫真正的面與面交界）
    // ══════════════════════════════════════════════════════════════════════
    // 【乾淨邊線】直接重用上色功能的「同一面」判定（_buildFaceGraph／sameFaceTriangles／
    // _collectFeatureEdges）——把整個 mesh 的三角形做完整分割（逐一 flood fill 直到每個
    // 三角形都歸類到某個面群組），取每個群組的特徵邊（邊界／與相鄰群組的交界）去重合併，
    // 完全不使用函式庫內建的 EdgesGeometry（那只看「相鄰三角形法向量夾角」，曲面只要
    // 三角化不夠細、每片小三角形都超過角度門檻，就會整圈都被判成「邊」，正是使用者截圖
    // 看到的那種一條一條的雜線；同一面判定是看「整個連續曲面」，面本身內部再多小三角形
    // 都不會畫出邊界，只有真正跟別的面交界的地方才算）。
    //
    // 【真虛線】Three.js 的虛線需要 LineDashedMaterial 專屬 shader（material.
    // isLineDashedMaterial 判斷），但已查證函式庫從未實際建立過這個類別的實例，沒有
    // 現成實例可借（上色/標記點能不靠全域 THREE 全都是靠「借既有實例的建構子」這招）。
    // 改用 material.onBeforeCompile 直接在任何一個既有材質（借 LineBasicMaterial 實例
    // 複製）的編譯前 shader 原始碼字串裡注入：①自訂頂點屬性 `lineDistance`（每一小段
    // 邊線各自獨立算「沿線距離」，起點 0、終點＝線段長度，不是整條折線累加，段落之間
    // 虛線節奏才不會對不齊）②fragment shader 用 `mod(vLineDistance, dash+gap) > dash`
    // 做 discard——這是標準 Three.js 自訂 shader 擴充點（供第三方套件掛虛線/漸層等效果
    // 用的機制），不是魔改函式庫本身。注入點選 `#include <fog_vertex>`（頂點）與
    // `#include <clipping_planes_fragment>`（片段，與系統既有的裁切剖面功能同一個掛點，
    // 兩者疊加互不干擾）。
    //
    // 做法：①實體面材質只關閉 colorWrite（不關閉 depthWrite、不隱藏 mesh），面仍正常
    // 寫入深度緩衝、也不擋滑鼠 raycast ②每個 mesh 各建一份「可見邊」（一般深度測試，
    // 實線深色）與一份「被遮蔽邊」（深度測試反轉成 GreaterDepth=6，Three.js 深度函式
    // 列舉值；虛線，淺灰）③兩者皆透過 viewer.AddExtraObject() 疊加——**刻意不呼叫
    // viewer.ClearExtra() 整個清空**，那會把量測標記也一併清掉；關閉透視時改用
    // extraModel.GetRootObject().remove() 只移除透視模式自己建立的物件。
    var _GREATER_DEPTH = 6;   // Three.js 的 DepthModes 列舉值（NeverDepth=0...GreaterDepth=6），無全域 THREE 可借，直接用數字

    // 找出兩個「鄰接三角形」（由 graph.adjacency 配對出的）之間真正共用的那條邊，回傳
    // v1 那組裡對應的兩個原始頂點索引（與 v2 對應位置在 vertKey 容許誤差內視為同一點，
    // 取哪一邊的原始索引都對應到同一個空間座標）。只給輪廓邊使用。
    function _adjSharedEdge(graph, v1, v2) {
        var k2 = [graph.vertKey(v2[0]), graph.vertKey(v2[1]), graph.vertKey(v2[2])];
        var shared = [];
        for (var i = 0; i < 3; i++) {
            var ki = graph.vertKey(v1[i]);
            if (ki === k2[0] || ki === k2[1] || ki === k2[2]) shared.push(v1[i]);
        }
        return shared.length === 2 ? shared : null;
    }

    // 輪廓邊（視角相關的剪影邊，2026-10-08 使用者回報圓柱左右兩側輪廓線缺漏後新增）：
    // 跟「同一面判定」找出來的固定幾何邊（面與面交界）完全是兩種東西——圓柱側面本身
    // 是連續曲面、整圈都在同一個 flood-fill 群組內，沒有任何內部三角化邊會被判成
    // 「特徵邊」（這是對的，否則又會變成蜘蛛網）；但從某個角度看，圓柱左右兩側「看起來
    // 像邊」的那條線其實是**法向量剛好垂直於視線方向**的那一圈三角形邊界，會隨著旋轉
    // 模型即時移動、不是固定幾何，必須每次視角改變時重新算（見下方 setHiddenLineMode
    // 掛在 navigation.callbacks.onUpdate 的重算邏輯）。
    // 判定：每個三角形先算「是不是朝向相機」（法向量與「三角形中心→相機」方向的點積，
    // 同方向＝朝向相機、反方向＝背向相機——全部在 MESH 本地座標算：相機世界座標先用
    // mesh.matrixWorld 的反矩陣換算成本地座標，才能跟 graph.normals 本來就是本地座標
    // 的三角形法向量維持同一個座標系，不需要另外處理法向量專屬的反轉置矩陣）；兩個
    // 相鄰三角形一個朝向、一個背向，中間那條共用邊就是輪廓邊。
    function _buildSilhouetteGeometry(mesh, eyeWorld) {
        var graph = _buildFaceGraph(mesh);
        if (!graph.triCount) return null;
        mesh.updateMatrixWorld(true);
        var invM = mesh.matrixWorld.clone().invert();
        var Vec3Ctor = mesh.position.constructor;   // 借用既有 Vector3 實例的建構子，不靠全域 THREE
        var eyeLocalV = new Vec3Ctor(eyeWorld[0], eyeWorld[1], eyeWorld[2]).applyMatrix4(invM);
        var eyeLocal = [eyeLocalV.x, eyeLocalV.y, eyeLocalV.z];
        var posArr = mesh.geometry.attributes.position.array;
        var front = new Uint8Array(graph.triCount);
        for (var t = 0; t < graph.triCount; t++) {
            var v = graph.getTri(t);
            var cx = (posArr[v[0]*3]+posArr[v[1]*3]+posArr[v[2]*3])/3;
            var cy = (posArr[v[0]*3+1]+posArr[v[1]*3+1]+posArr[v[2]*3+1])/3;
            var cz = (posArr[v[0]*3+2]+posArr[v[1]*3+2]+posArr[v[2]*3+2])/3;
            var n = graph.normals[t];
            var dot = n[0]*(eyeLocal[0]-cx) + n[1]*(eyeLocal[1]-cy) + n[2]*(eyeLocal[2]-cz);
            front[t] = dot > 0 ? 1 : 0;
        }
        var m = mesh.matrixWorld.elements;
        function toWorld(vi) {
            var lx = posArr[vi*3], ly = posArr[vi*3+1], lz = posArr[vi*3+2];
            return [ m[0]*lx+m[4]*ly+m[8]*lz+m[12], m[1]*lx+m[5]*ly+m[9]*lz+m[13], m[2]*lx+m[6]*ly+m[10]*lz+m[14] ];
        }
        var verts = [];
        var seen = new Set();
        for (var t2 = 0; t2 < graph.triCount; t2++) {
            var nbs = graph.adjacency[t2];
            for (var k = 0; k < nbs.length; k++) {
                var nb = nbs[k];
                if (nb <= t2) continue;   // 每條鄰接邊只處理一次（兩個方向各列舉一次，故只在 nb>t2 時處理）
                if (front[t2] === front[nb]) continue;   // 同向，不是輪廓邊
                var shared = _adjSharedEdge(graph, graph.getTri(t2), graph.getTri(nb));
                if (!shared) continue;
                var ka = graph.vertKey(shared[0]), kb = graph.vertKey(shared[1]);
                var key = ka < kb ? (ka+'_'+kb) : (kb+'_'+ka);
                if (seen.has(key)) continue;
                seen.add(key);
                var a = toWorld(shared[0]), b = toWorld(shared[1]);
                verts.push(a[0],a[1],a[2], b[0],b[1],b[2]);
            }
        }
        if (!verts.length) return null;
        var GeoCtor = mesh.geometry.constructor;
        var geo = new GeoCtor();
        var PosAttrCtor = mesh.geometry.attributes.position.constructor;
        geo.setAttribute('position', new PosAttrCtor(new Float32Array(verts), 3));
        return geo;
    }

    // 把一個 mesh 的三角形完整分割成多個「同一面」群組，合併各群組的特徵邊、去重，
    // 建成一份 WORLD 座標的 LineSegments 幾何＋每個端點各自的 lineDistance 屬性。
    function _buildCleanEdgeGeometry(mesh, angleDeg) {
        var graph = _buildFaceGraph(mesh);
        if (!graph.triCount) return null;
        var visited = new Uint8Array(graph.triCount);
        var edgeMap = new Map();   // "va_vb"(小到大) → {a,b}（世界座標兩端點）
        for (var t = 0; t < graph.triCount; t++) {
            if (visited[t]) continue;
            var group = sameFaceTriangles(mesh, t, angleDeg);
            for (var gi = 0; gi < group.length; gi++) visited[group[gi]] = 1;
            var feats = _collectFeatureEdges(mesh, group);
            feats.forEach(function (e) {
                // 去重鍵要用 vertKey（空間位置）不是原始索引——同一條邊界邊會被相鄰兩個
                // 群組各自列舉一次，non-indexed 幾何兩邊看到的原始索引不同（各自獨立複製
                // 的陣列位置），不轉成 vertKey 比對會把同一條邊畫兩次（不是錯但多餘）。
                var ka = graph.vertKey(e.va), kb = graph.vertKey(e.vb);
                var key = ka < kb ? (ka + '_' + kb) : (kb + '_' + ka);
                if (!edgeMap.has(key)) edgeMap.set(key, e);
            });
        }
        if (!edgeMap.size) return null;
        var verts = [], dists = [];
        edgeMap.forEach(function (e) {
            verts.push(e.a[0], e.a[1], e.a[2], e.b[0], e.b[1], e.b[2]);
            var segLen = _vlen(e.a, e.b);
            dists.push(0, segLen);   // 每小段獨立歸零，不整條邊線累加
        });
        var GeoCtor = mesh.geometry.constructor;
        var geo = new GeoCtor();
        var PosAttrCtor = mesh.geometry.attributes.position.constructor;
        geo.setAttribute('position', new PosAttrCtor(new Float32Array(verts), 3));
        geo.setAttribute('lineDistance', new PosAttrCtor(new Float32Array(dists), 1));
        return geo;
    }
    // 真虛線材質：複製一份既有 LineBasicMaterial 實例，用 onBeforeCompile 注入
    // dash/gap 判斷用的 GLSL（標準 Three.js 自訂 shader 擴充點，不是改函式庫本身）。
    function _buildDashedLineMaterial(baseLineMat, hexColor, dashSize, gapSize) {
        var mat = baseLineMat.clone();
        mat.color.set(hexColor);
        mat.onBeforeCompile = function (shader) {
            shader.uniforms.dashSize = { value: dashSize };
            shader.uniforms.gapSize = { value: gapSize };
            shader.vertexShader = shader.vertexShader
                .replace('#include <common>', '#include <common>\nattribute float lineDistance;\nvarying float vLineDistance;')
                .replace('#include <fog_vertex>', 'vLineDistance = lineDistance;\n#include <fog_vertex>');
            shader.fragmentShader = shader.fragmentShader
                .replace('#include <common>', '#include <common>\nuniform float dashSize;\nuniform float gapSize;\nvarying float vLineDistance;')
                .replace('#include <clipping_planes_fragment>', '#include <clipping_planes_fragment>\nif ( mod( vLineDistance, dashSize + gapSize ) > dashSize ) discard;');
        };
        mat.needsUpdate = true;
        return mat;
    }
    function setHiddenLineMode(embeddedViewer) {
        var viewer = embeddedViewer.GetViewer();
        var mm = viewer.mainModel;
        // 短暫開啟函式庫內建邊線只是為了借一份真正的 LineBasicMaterial 實例（建構子
        // 外借技巧需要現成實例），實際幾何改用自己重建的乾淨版本，借到材質後立刻關掉
        // 內建邊線，否則使用者會同時看到內建的雜亂版本疊在乾淨版本上面。
        mm.SetEdgeSettings(new OV.EdgeSettings(true, new OV.RGBColor(26, 26, 26), 1));
        var edgeObj = null;
        mm.EnumerateEdges(function (o) { if (!edgeObj) edgeObj = o; });
        mm.SetEdgeSettings(new OV.EdgeSettings(false, new OV.RGBColor(0, 0, 0), 1));
        if (!edgeObj) return null;   // 這個模型沒有偵測到任何硬邊（極少見，例如完全平滑的球體）
        var LineCtor = edgeObj.constructor;
        var baseLineMat = edgeObj.material;

        var frontObjs = [], backObjs = [];
        var meshFrontMat = new Map();   // mesh → 可見邊材質，輪廓邊重用同一份材質（視覺風格要一致）
        var meshBackMat = new Map();    // mesh → 被遮蔽邊材質（虛線＋反轉深度測試），輪廓邊的被遮蔽部分重用同一份
        mm.EnumerateMeshes(function (mesh) {
            // 角度門檻用 20 度——這裡只是要畫「視覺輪廓」不是要精準選出單一 CAD 面，跟
            // 上色功能（也是 20 度，見 sameFaceTriangles 開頭註解，2026-10-12 已從舊的
            // 12 度調整過）用途不同，純屬巧合用了同一個數字，改動其中一處不必連動另一處。
            // 已用真實模型做角度掃描實測校準：12 度把整個模型切成 344 個群組（318 個只有
            // 1~2 個三角形，曲面三角化較粗的地方——例如小孔的圓柱面——每一小塊facet 都
            // 超過 12 度被判成獨立群組，畫出來反而是一團蜘蛛網）；20～30 度之間穩定收斂到
            // 44 個群組（只剩 6 個小群組）；到 45 度以上開始過度合併（群組數驟減、最大
            // 群組暴增到 1000+，代表把真正不同的 CAD 面誤連在一起）。20 度正好落在「雜線
            // 消失、尚未開始誤併」的穩定區間，不要再往上調。
            var geo = _buildCleanEdgeGeometry(mesh, 20);
            if (!geo) return;
            mesh.geometry.computeBoundingSphere();
            var r = (mesh.geometry.boundingSphere && mesh.geometry.boundingSphere.radius) || 20;
            var dashSize = Math.max(0.3, r * 0.018), gapSize = Math.max(0.2, r * 0.012);

            var frontMat = baseLineMat.clone();
            frontMat.color.set('#1a1a1a');
            frontMat.needsUpdate = true;
            meshFrontMat.set(mesh, frontMat);
            var frontObj = new LineCtor(geo, frontMat);
            frontObj.renderOrder = 10;
            viewer.AddExtraObject(frontObj);
            frontObjs.push(frontObj);

            var backMat = _buildDashedLineMaterial(baseLineMat, '#8a8a8a', dashSize, gapSize);
            backMat.depthFunc = _GREATER_DEPTH;
            backMat.depthWrite = false;
            meshBackMat.set(mesh, backMat);
            var backObj = new LineCtor(geo, backMat);
            backObj.renderOrder = 5;
            viewer.AddExtraObject(backObj);
            backObjs.push(backObj);
        });
        if (!frontObjs.length) return null;

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

        // 輪廓邊（視角相關，見 _buildSilhouetteGeometry 註解）：初始建一次，之後掛在
        // navigation.callbacks.onUpdate（函式庫內部每次旋轉/縮放/平移都會呼叫它來觸發
        // 重繪，借用同一個掛點不必自己另外偵測滑鼠事件）。用 requestAnimationFrame 節流
        // ──同一畫面更新期間 onUpdate 可能被連續呼叫多次，只在下一個畫面重算一次，拖曳
        // 旋轉時才不會因為每個 mousemove 都重新掃描全部三角形而卡頓。
        // silhouetteObjs 陣列本體刻意只用 length=0＋push 清空重建、不整個重新指派一個新
        // 陣列——這樣 return 出去給 clearHiddenLineMode 用的參照永遠有效，不會在下一次
        // 旋轉重算之後變成指向舊陣列的過期快照。
        var silhouetteObjs = [];
        var root = viewer.extraModel.GetRootObject();
        function rebuildSilhouette() {
            silhouetteObjs.forEach(function (o) { root.remove(o); });
            silhouetteObjs.length = 0;
            var camera = viewer.navigation && viewer.navigation.GetCamera();
            if (!camera || !camera.eye) return;
            var eyeWorld = [camera.eye.x, camera.eye.y, camera.eye.z];
            mm.EnumerateMeshes(function (mesh) {
                var fmat = meshFrontMat.get(mesh);
                var bmat = meshBackMat.get(mesh);
                if (!fmat || !bmat) return;   // 完全沒有特徵邊的 mesh（如整顆平滑球）不處理輪廓邊
                var geo = _buildSilhouetteGeometry(mesh, eyeWorld);
                if (!geo) return;
                // 輪廓邊同樣要分「可見／被遮蔽」兩個 pass（2026-10-12 使用者回報：孔洞內壁
                // 的輪廓線——連接上下兩個圓孔邊緣的那兩條側邊線——被遮蔽時應顯示虛線，但
                // 先前輪廓邊一律只用 frontMat 畫成實線，沒有對應的虛線版本。外凸輪廓（例如
                // 柱體外側）本來就不可能被自己擋住，用一般深度測試＋實心材質時自然永遠通過
                // 可見那一關；但凹陷特徵（孔洞內壁）的輪廓線確實會被孔口近側內壁擋住，一律
                // 套用跟一般特徵邊相同的「同一份幾何、兩份材質（一般深度測試=實線可見／反轉
                // 深度測試=虛線被遮蔽）」寫法，不需要額外判斷凹凸，兩種情況自動各自正確顯示。
                var fObj = new LineCtor(geo, fmat);
                fObj.renderOrder = 10;
                viewer.AddExtraObject(fObj);
                silhouetteObjs.push(fObj);
                var bObj = new LineCtor(geo, bmat);
                bObj.renderOrder = 5;
                viewer.AddExtraObject(bObj);
                silhouetteObjs.push(bObj);
            });
        }
        var silhouettePending = false;
        function scheduleSilhouetteRebuild() {
            if (silhouettePending) return;
            silhouettePending = true;
            requestAnimationFrame(function () {
                silhouettePending = false;
                rebuildSilhouette();
                viewer.Render();
            });
        }
        rebuildSilhouette();
        var origOnUpdate = viewer.navigation && viewer.navigation.callbacks && viewer.navigation.callbacks.onUpdate;
        if (viewer.navigation && viewer.navigation.callbacks) {
            viewer.navigation.callbacks.onUpdate = function () {
                scheduleSilhouetteRebuild();
                if (origOnUpdate) origOnUpdate();
            };
        }

        viewer.Render();
        return { frontObjs: frontObjs, backObjs: backObjs, touchedMats: touchedMats, silhouetteObjs: silhouetteObjs, _origOnUpdate: origOnUpdate };
    }
    function clearHiddenLineMode(embeddedViewer, state) {
        if (!embeddedViewer || !state) return;
        var viewer = embeddedViewer.GetViewer();
        var root = viewer.extraModel.GetRootObject();
        (state.frontObjs || []).forEach(function (o) { root.remove(o); });
        (state.backObjs || []).forEach(function (o) { root.remove(o); });
        (state.silhouetteObjs || []).forEach(function (o) { root.remove(o); });
        (state.touchedMats || []).forEach(function (mt) { mt.colorWrite = true; mt.needsUpdate = true; });
        if (viewer.navigation && viewer.navigation.callbacks && state._origOnUpdate) {
            viewer.navigation.callbacks.onUpdate = state._origOnUpdate;
        }
        viewer.Render();
    }

    // 透視（隱藏線）＋剖面同時開啟時的串接（2026-10-12 使用者回報：兩者同時啟用時，
    // 切掉的那一側看起來還是「空心」能看穿）。根因：剖面（setSectionMode／
    // updateSectionMode）只把 clippingPlanes 設在**實體面材質**（mm.EnumerateMeshes 那
    // 一圈）上，完全不知道透視模式另外建立的那一批「邊線」物件（frontObjs／backObjs／
    // silhouetteObjs，材質各自獨立 clone，從未繼承實體面的 clippingPlanes）——兩套狀態
    // 原本各自獨立運作。於是單獨開剖面（實體面渲染）正常看得到封頂實心斷面；但一旦同時
    // 開透視，實體面被透視模式整批 colorWrite=false 隱藏掉只剩邊線，而這些邊線完全沒有
    // 被剖面裁切，被切除掉的那部分幾何的邊線照樣整組畫出來——使用者看到的「空心」其實是
    // 被移除那半邊的完整線稿疊在封頂面外面，造成「看穿到裡面」的錯覺（封頂實心面本身沒有
    // 問題，只是被周圍一堆不該出現的殘留邊線蓋過去，難以辨識）。
    // 修法：把目前的剖切平面（與 clipIntersection 旗標）也套到透視模式這批邊線材質上；
    // 兩個模式各自的 UI 事件（開關／調整剖面／開關透視）都要呼叫這支同步一次，確保邊線
    // 材質的 clippingPlanes 永遠跟剖面目前的狀態一致（清除剖面時傳 null 清空）。沿用
    // updateSectionMode 同一條規則——直接指派新的 planes 陣列參照，不要求呼叫端自己組
    // 更新邏輯；多個 LineSegments 物件共用同一份材質（frontObjs/backObjs 的一般特徵邊
    // 與 silhouetteObjs 的輪廓邊本來就共用同一組 meshFrontMat/meshBackMat，見
    // setHiddenLineMode 內的輪廓邊重建），逐一設定即使重複也無副作用。
    function applySectionClipToHiddenLine(hlState, planes, clipIntersection) {
        if (!hlState) return;
        var mats = [];
        function collect(objs) {
            (objs || []).forEach(function (o) {
                if (o.material && mats.indexOf(o.material) < 0) mats.push(o.material);
            });
        }
        collect(hlState.frontObjs);
        collect(hlState.backObjs);
        collect(hlState.silhouetteObjs);
        mats.forEach(function (mt) {
            mt.clippingPlanes = planes || null;
            mt.clipIntersection = !!clipIntersection;
            mt.needsUpdate = true;
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // 剖面（切面）：沿 X/Y/Z 任一軸切一刀，只保留切面的一側（stretch goal，
    // 使用者原話「如果還可以就繼續做」）
    // ══════════════════════════════════════════════════════════════════════
    // 查證發現 o3dv.min.js 打包的 Three.js 內建完整的材質切平面（material.clippingPlanes）
    // 支援，裁切運算整段在標準 shader chunk 裡（UNION_CLIPPING_PLANES／NUM_CLIPPING_PLANES），
    // 不需要自己刻 shader。更重要的是**不需要全域 THREE.Plane 建構子**——內部套用切平面時
    // 是呼叫 `scratchPlane.copy(materialClippingPlanes[i])`，Plane.copy() 只會讀來源物件的
    // `.normal`（再丟給 Vector3.copy 讀 .x/.y/.z）與 `.constant` 兩個屬性，純粹鴨子定型，
    // 所以傳一個「長得像」的陽春物件 `{normal:{x,y,z}, constant:N}` 完全可行（已實測確認：
    // 一個三角錐模型乾淨被切開，不需要借任何既有實例的建構子）。
    // 2026-10-08 使用者回報：剖面切開後斷面應該呈現「實心填滿」，不該是「鏤空看穿到對面」
    // ——已改用真正的幾何封頂（見下方「剖面封頂」區塊），不再是單純裁切鏤空版。
    //
    // ★符號陷阱（已實測踩到、務必記住）：Three.js 的 Plane 不是「normal·point = constant」
    // 這種直覺寫法，而是「normal·point + constant = 0」——也就是 **constant 的符號跟直覺
    // 相反**。一開始用 `constant = planePos` 在 X／Z 軸測試「看起來」完全正常，是因為這兩
    // 軸的包圍盒剛好左右對稱（中點為 0，0 跟 -0 沒有差別，符號錯了也看不出來）；一換成
    // Y 軸（包圍盒是 0~34.9，不對稱）就整個不會動——这是由 50%、extreme 值一路二分搜尋
    // 才揪出來的，純看程式碼或 shader 原始碼推不出來。正確公式是 `constant = -planePos
    // * normalSign`（normalSign 即 normal[axis] 的 ±1），兩個方向都已實測驗證正確。
    // ══════════════════════════════════════════════════════════════════════
    // 剖面封頂（實心斷面，2026-10-08 使用者回報剖面應呈現實心填滿、不是鏤空殼狀後新增）
    // ══════════════════════════════════════════════════════════════════════
    // 原因：Three.js 的 clippingPlanes 只是幾何裁切（丟棄超出範圍的三角形片段），模型本身
    // 是一層零厚度的外殼網格；查證過這個模型的材質是 DoubleSide（side=2，STEP/IPT 轉檔
    // 常見，確保纏繞方向不一致時仍整面可見），裁切後露出的是殼內側、對面內壁的背面，
    // 看起來就是使用者說的「鏤空殼狀」而不是「實心材料被切開」。
    // 查證：這個函式庫內部 WebGLRenderer 建立時沒有要求 `stencil:true`（已用 CDP 直接讀
    // `renderer.getContext().getContextAttributes()` 確認 `stencil:false`），所以無法
    // 沿用 Three.js 官方 webgl_clipping_stencil 範例那套「模板緩衝兩階段算法」（這招需要
    // 畫面已經有 stencil buffer 才能用，拿不到就整段失效，不能硬套）。
    // 改用**真正的幾何封頂**：直接算出剖切平面與整個 mesh 表面的交線（逐三角形求交），
    // 把交線接成封閉迴圈，再用「挖洞耳切法」（ear clipping with hole bridging，與
    // Mapbox earcut.js 同一種演算法思路）三角化成實心面片，鋪在剖切位置——這是純幾何
    // 運算，不依賴 stencil 緩衝區，天生正確處理「洞中有洞」（例如剖面剛好切過螺絲孔，
    // 孔要維持中空不能被誤填）：用每個迴圈的重心做 point-in-polygon 巢狀層數判定——
    // 被其他迴圈包住**偶數**次＝實心邊界（含它自己的洞）、**奇數**次＝洞的邊界，不限定
    // 只處理一層洞。
    function _vecLen3(v) { return Math.sqrt(v[0]*v[0]+v[1]*v[1]+v[2]*v[2]) || 1; }
    function _vcross3(a, b) { return [a[1]*b[2]-a[2]*b[1], a[2]*b[0]-a[0]*b[2], a[0]*b[1]-a[1]*b[0]]; }
    // 平面的正交基底：origin＝平面上離世界原點最近的點（Three.js Plane 慣例 normal·p+constant=0
    // 時，p=-constant*normal 正好滿足：dot(-c*n,n)+c = -c*|n|² +c = -c+c = 0，因為 n 是單位向量）；
    // u／v 是平面內互相垂直的單位切向量，供 3D↔2D 投影互轉用。
    function _planeBasis3d(plane) {
        var n = [plane.normal.x, plane.normal.y, plane.normal.z];
        var ref = Math.abs(n[0]) < 0.9 ? [1,0,0] : [0,1,0];
        var u = _vcross3(ref, n); var ul = _vecLen3(u); u = [u[0]/ul, u[1]/ul, u[2]/ul];
        var v = _vcross3(n, u);
        var origin = [-plane.constant*n[0], -plane.constant*n[1], -plane.constant*n[2]];
        return { origin: origin, u: u, v: v, n: n };
    }
    // 單一三角形與剖切平面求交，回傳「有方向性」的線段（方向沿三角形原本頂點順序，由
    // 「保留側→剪掉側」的那個交點指向「剪掉側→保留側」的交點）——這個定向規則是標準封頂
    // 演算法的作法，讓收集到的全部線段接起來的迴圈，面向剖面法向量那一側時自然是正確的
    // 外圍方向；本實作另外把封頂材質設成 DoubleSide（見下方），即使定向方向不如預期也
    // 不影響可不可見，只有「偶數層/奇數層」這個分類邏輯需要迴圈方向一致，而不是要求
    // CCW/CW 對應特定的可見面。
    // ★ 2026-10-12：頂點「剛好落在剖切平面上」一定要單獨處理，否則剖在模型對稱中心時整個
    //   斷面會消失。舊版把 da<=0 當一側、da>0 當另一側，頂點正好在平面上（d=0，對稱零件
    //   剖在正中央時成千上萬個頂點都是這種）會被硬歸到「保留側」，於是三角形被判定成沒有
    //   跨過平面、一條交線都收不到——實測 Z 軸 50%（平面正好通過模型中心）斷面完全不見。
    //   改成先用容差把「幾乎在平面上」的 d 歸零，再依零的個數分四種情形處理，這是三角形
    //   網格等值面抽取的標準作法。
    function _triPlaneIntersectSeg(v0, v1, v2, plane, eps) {
        var nx = plane.normal.x, ny = plane.normal.y, nz = plane.normal.z, c = plane.constant;
        function side(p) { return p[0]*nx + p[1]*ny + p[2]*nz + c; }
        var verts = [v0, v1, v2], d = [side(v0), side(v1), side(v2)];
        var i;
        eps = eps || 0;
        for (i = 0; i < 3; i++) { if (Math.abs(d[i]) <= eps) d[i] = 0; }
        var zeros = [];
        for (i = 0; i < 3; i++) { if (d[i] === 0) zeros.push(i); }
        if (zeros.length === 3) return null;        // 整片三角形貼在平面上（輪廓由它的鄰居負責，這裡收了反而會重複）
        if (zeros.length === 2) {                   // 剛好有一條邊落在平面上＝那條邊就是輪廓線
            return { a: verts[zeros[0]].slice(), b: verts[zeros[1]].slice() };
        }
        var pts = [];
        if (zeros.length === 1) {
            var zi = zeros[0], o1 = (zi+1)%3, o2 = (zi+2)%3;
            if ((d[o1] > 0) === (d[o2] > 0)) return null;   // 只有一個角「碰到」平面，不算穿過
            pts.push(verts[zi].slice());
        }
        for (i = 0; i < 3; i++) {
            var a = verts[i], b = verts[(i+1)%3], da = d[i], db = d[(i+1)%3];
            if (da === 0 || db === 0) continue;
            if ((da < 0) === (db < 0)) continue;
            var t = da / (da - db);
            pts.push([a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t]);
        }
        if (pts.length !== 2) return null;
        return { a: pts[0], b: pts[1] };
    }
    // 整個模型與一個剖切平面求交，把所有三角形各自算出的小線段接成一條條封閉迴圈（世界座標）
    function _buildCapLoops3d(mm, plane) {
        var segs = [];
        // 「頂點剛好落在平面上」的判定容差，依模型尺度換算（見 _triPlaneIntersectSeg 註解）
        var mbb = mm.GetBoundingBox(function () { return true; });
        var planeEps = mbb
            ? Math.max(1e-7, Math.sqrt(
                  (mbb.max.x-mbb.min.x)*(mbb.max.x-mbb.min.x) +
                  (mbb.max.y-mbb.min.y)*(mbb.max.y-mbb.min.y) +
                  (mbb.max.z-mbb.min.z)*(mbb.max.z-mbb.min.z)) * 1e-6)
            : 1e-5;
        // 剖切平面剛好落在「一整排頂點」上時（對稱零件剖在正中央、或剖在 CAD 鑲嵌的接縫
        // 上）會冒出大量退化三角形，輪廓抽取結果不可靠——實測 Z 軸 50%（平面正好通過模型
        // 中心）有 206 個頂點正好在平面上、54 個三角形整條邊貼在平面上，算出來的輪廓是壞的，
        // 斷面整片消失。把「用來抽輪廓的」平面沿法線往保留側挪一個極小量（模型尺度的百萬分
        // 之幾，這個零件上約 0.002mm，肉眼與量測都看不出來）就能完全避開這一整類退化情形；
        // 乘上 17.3 這種非整數倍率是刻意的，避免挪完又剛好落在另一排整數座標的頂點上。
        // 封頂最後仍是用原本的 plane 基底重建（見 _buildCapGeometry），所以畫出來的位置
        // 還是在真正的剖切面上，不會偏移。
        var cutPlane = { normal: plane.normal, constant: plane.constant - planeEps * 17.3 };
        mm.EnumerateMeshes(function (mesh) {
            var geo = mesh.geometry;
            var posAttr = geo.attributes.position;
            var posArr = posAttr.array;
            var idxAttr = geo.index;
            mesh.updateMatrixWorld(true);
            var m = mesh.matrixWorld.elements;
            function toWorld(vi) {
                var lx = posArr[vi*3], ly = posArr[vi*3+1], lz = posArr[vi*3+2];
                return [ m[0]*lx+m[4]*ly+m[8]*lz+m[12], m[1]*lx+m[5]*ly+m[9]*lz+m[13], m[2]*lx+m[6]*ly+m[10]*lz+m[14] ];
            }
            var triCount, getTri;
            if (idxAttr) { var ia = idxAttr.array; triCount = Math.floor(ia.length/3); getTri = function (i) { return [ia[i*3], ia[i*3+1], ia[i*3+2]]; }; }
            else { triCount = Math.floor(posAttr.count/3); getTri = function (i) { return [i*3, i*3+1, i*3+2]; }; }
            for (var t = 0; t < triCount; t++) {
                var v = getTri(t);
                var seg = _triPlaneIntersectSeg(toWorld(v[0]), toWorld(v[1]), toWorld(v[2]), cutPlane, planeEps);
                if (seg) segs.push(seg);
            }
        });
        if (!segs.length) return [];
        // ── 端點焊接（welding）────────────────────────────────────────────────
        // ★ 2026-10-12 這裡原本是整個剖面功能真正的病灶，而且症狀完全不像幾何錯：
        //   舊版用「座標四捨五入到 0.001 當字典鍵」去配對相鄰線段的端點——**四捨五入不是
        //   容差**。同一個實際頂點由相鄰兩個三角形各自內插算出來時會差到浮點誤差等級
        //   （頂點是 float32，還要再乘世界矩陣），只要那個值剛好落在 0.0005 的進位邊界
        //   附近，兩邊就會被分到不同的鍵而配不起來；鏈一斷，舊版「沒有閉合就整條丟掉」
        //   的判斷會把那一整段輪廓**無聲地**扔掉，連錯誤訊息都沒有。
        //   實測：Y 軸 22% 的截面本該是一整片圓盤，卻只剩外緣六片細月牙（其餘迴圈全被
        //   丟棄），平常又剛好被模型外壁擋住，使用者看到的就是「斷面不見了／空心」。
        //   這也終於解釋了前幾輪的「拖一下滑桿就正常了」——換個剖切位置只是剛好沒踩到
        //   進位邊界而已，不是什麼 z-fighting 被矯正。
        //   改成真正的容差配對：空間雜湊（格子邊長＝容差），查詢時連同周圍 26 格一起找，
        //   才不會發生「兩點明明只差 1e-9 卻因為落在不同格子而配不起來」。
        var minX=Infinity, minY=Infinity, minZ=Infinity, maxX=-Infinity, maxY=-Infinity, maxZ=-Infinity;
        segs.forEach(function (s) {
            [s.a, s.b].forEach(function (p) {
                if (p[0]<minX) minX=p[0]; if (p[0]>maxX) maxX=p[0];
                if (p[1]<minY) minY=p[1]; if (p[1]>maxY) maxY=p[1];
                if (p[2]<minZ) minZ=p[2]; if (p[2]>maxZ) maxZ=p[2];
            });
        });
        function _dist3(a, b) { var dx=a[0]-b[0], dy=a[1]-b[1], dz=a[2]-b[2]; return Math.sqrt(dx*dx+dy*dy+dz*dz); }
        var diag = _dist3([minX,minY,minZ], [maxX,maxY,maxZ]);
        // 容差取截面尺度的十萬分之二：遠大於 float32 內插誤差，又遠小於任何真實幾何特徵
        var tol = Math.max(1e-6, diag * 2e-5);
        var cell = tol * 2;
        var nodes = [], grid = new Map();
        function _cellKey(p, ox, oy, oz) {
            return (Math.floor(p[0]/cell)+ox) + ',' + (Math.floor(p[1]/cell)+oy) + ',' + (Math.floor(p[2]/cell)+oz);
        }
        function _weld(p) {
            for (var ox=-1; ox<=1; ox++) for (var oy=-1; oy<=1; oy++) for (var oz=-1; oz<=1; oz++) {
                var bucket = grid.get(_cellKey(p, ox, oy, oz));
                if (!bucket) continue;
                for (var bi=0; bi<bucket.length; bi++) {
                    var q = nodes[bucket[bi]];
                    if (Math.abs(q[0]-p[0])<tol && Math.abs(q[1]-p[1])<tol && Math.abs(q[2]-p[2])<tol) return bucket[bi];
                }
            }
            var id = nodes.length;
            nodes.push([p[0], p[1], p[2]]);
            var k = _cellKey(p, 0, 0, 0);
            if (!grid.has(k)) grid.set(k, []);
            grid.get(k).push(id);
            return id;
        }
        var edges = [];
        segs.forEach(function (s) {
            var a = _weld(s.a), b = _weld(s.b);
            if (a !== b) edges.push([a, b]);   // 兩端焊成同一點＝長度在容差內的退化線段，丟掉
        });
        if (!edges.length) return [];
        // ── 串成迴圈 ─────────────────────────────────────────────────────────
        // 刻意改成「無方向」走訪：線段的方向性只對「哪一側是保留側」有意義，而下游的巢狀
        // 判定（_pointInPoly2d 是奇偶跨越法）與耳切（_earClipTriangulate 自己會統一成 CCW）
        // 都不吃迴圈方向；無方向走訪對法向量不一致、有翻面三角形的網格容錯高很多。
        var adj = new Map();
        edges.forEach(function (e, i) {
            if (!adj.has(e[0])) adj.set(e[0], []);
            adj.get(e[0]).push(i);
            if (!adj.has(e[1])) adj.set(e[1], []);
            adj.get(e[1]).push(i);
        });
        var used = new Uint8Array(edges.length);
        function _pickAt(node) {
            var list = adj.get(node);
            if (list) { for (var i=0; i<list.length; i++) { if (!used[list[i]]) return list[i]; } }
            return -1;
        }
        function _other(ei, node) { return edges[ei][0] === node ? edges[ei][1] : edges[ei][0]; }
        var closedPaths = [], openPaths = [];
        for (var si = 0; si < edges.length; si++) {
            if (used[si]) continue;
            used[si] = 1;
            var head = edges[si][0];
            var path = [head, edges[si][1]];
            var cur = edges[si][1], closed = false, guard = 0;
            while (guard++ < edges.length + 5) {
                if (cur === head) { path.pop(); closed = true; break; }
                var ni = _pickAt(cur);
                if (ni < 0) break;
                used[ni] = 1; cur = _other(ni, cur); path.push(cur);
            }
            if (!closed) {   // 起點可能落在鏈的中間，往反方向再延伸一次
                cur = head; guard = 0;
                while (guard++ < edges.length + 5) {
                    var ni2 = _pickAt(cur);
                    if (ni2 < 0) break;
                    used[ni2] = 1; cur = _other(ni2, cur); path.unshift(cur);
                    if (cur === path[path.length-1]) { path.pop(); closed = true; break; }
                }
            }
            if (path.length < 3) continue;
            (closed ? closedPaths : openPaths).push(path);
        }
        // ── 輪廓縫合（contour healing）────────────────────────────────────────
        // ★ 2026-10-12 使用者回報「剖面拉到某個地方時會有問題，但過去後又沒事」。
        //   實測那支長件（LSPS160501，70×70×422mm）在 X48%／Y50%／Y76% 斷面**整個消失**，
        //   相鄰 2% 的位置卻完全正常。查出來不是破一大塊，而是 CAD 轉網格時留下的微小裂縫：
        //   兩段開放弧的端點只相距 0.009～0.038mm，而上面的焊接容差是 0.0086mm——就差這麼
        //   一點點配不起來，整圈輪廓被切成兩段開放弧，舊邏輯對開放弧一律丟棄，斷面就整個
        //   不見了。單純把焊接容差調大並不安全（會把真正相鄰的細微特徵焊在一起），所以改
        //   成多一道縫合：**只對「已經斷掉的那幾個端點」**反覆找最近的一對接起來，容差放寬
        //   到輪廓尺度的千分之一（這支件約 0.43mm，遠大於裂縫、又遠小於任何真實特徵）。
        //   縫合之後仍然閉不起來的（真的缺一大段）才丟棄——寧可少畫，也不要憑空補出一塊
        //   形狀錯誤的實心面。
        var stitchTol = Math.max(tol * 50, diag * 0.001);
        var mergedAny = true;
        while (mergedAny && openPaths.length > 1) {
            mergedAny = false;
            var bi = -1, bj = -1, bRev = false, bd = Infinity;
            for (var i = 0; i < openPaths.length; i++) {
                var tailI = nodes[openPaths[i][openPaths[i].length-1]];
                for (var j = 0; j < openPaths.length; j++) {
                    if (i === j) continue;
                    var dHead = _dist3(tailI, nodes[openPaths[j][0]]);
                    if (dHead < bd) { bd = dHead; bi = i; bj = j; bRev = false; }
                    var dTail = _dist3(tailI, nodes[openPaths[j][openPaths[j].length-1]]);
                    if (dTail < bd) { bd = dTail; bi = i; bj = j; bRev = true; }
                }
            }
            if (bi >= 0 && bd <= stitchTol) {
                var add = openPaths[bj].slice();
                if (bRev) add.reverse();
                if (_dist3(nodes[openPaths[bi][openPaths[bi].length-1]], nodes[add[0]]) <= stitchTol) add.shift();
                openPaths[bi] = openPaths[bi].concat(add);
                openPaths.splice(bj, 1);
                mergedAny = true;
            }
        }
        openPaths.forEach(function (p) {
            if (p.length < 3) return;
            var gap = _dist3(nodes[p[0]], nodes[p[p.length-1]]);
            if (gap <= stitchTol) { closedPaths.push(p); return; }
            // 最後退路：縫合之後仍然閉不起來，但缺口相對於這條弧自己的長度很小（<5%）時
            // 仍視同閉合直接拉一條弦補上。缺口佔比太大的才真的丟棄——那種情況硬補會生出
            // 一塊形狀完全錯誤的實心面，比少畫更糟。
            var span = 0;
            for (var pi = 1; pi < p.length; pi++) span += _dist3(nodes[p[pi-1]], nodes[p[pi]]);
            if (gap <= span * 0.05) closedPaths.push(p);
        });
        return closedPaths.map(function (p) {
            return p.map(function (id) { return nodes[id].slice(); });
        });
    }
    function _loop3dTo2d(loop3d, basis) {
        return loop3d.map(function (p) {
            var dx = p[0]-basis.origin[0], dy = p[1]-basis.origin[1], dz = p[2]-basis.origin[2];
            return [dx*basis.u[0]+dy*basis.u[1]+dz*basis.u[2], dx*basis.v[0]+dy*basis.v[1]+dz*basis.v[2]];
        });
    }
    // ── 以下五支（_signedArea2d／_centroid2d／_pointInPoly2d／_pts2dEq／_seg2dIntersect／
    //    _bridgeHolesInto／_pointInTri2d／_earClipTriangulate）自 2026-10-12 起**已不再被
    //    呼叫**：封頂三角化改用 _buildCapTriangles2d 裡的掃描線梯形分解（原因見該函式的
    //    註解——橋接＋耳切在有孔截面上會無聲地只畫出碎片）。保留原始碼僅供對照，要改封頂
    //    填充請改掃描線那一段，不要再接回這條路。
    function _signedArea2d(pts) {
        var a = 0;
        for (var i = 0; i < pts.length; i++) { var p = pts[i], q = pts[(i+1)%pts.length]; a += p[0]*q[1] - q[0]*p[1]; }
        return a / 2;
    }
    function _centroid2d(pts) {
        var cx = 0, cy = 0; pts.forEach(function (p) { cx += p[0]; cy += p[1]; });
        return [cx/pts.length, cy/pts.length];
    }
    function _pointInPoly2d(pt, poly) {
        var x = pt[0], y = pt[1], inside = false;
        for (var i = 0, j = poly.length-1; i < poly.length; j = i++) {
            var xi = poly[i][0], yi = poly[i][1], xj = poly[j][0], yj = poly[j][1];
            if (((yi > y) !== (yj > y)) && (x < (xj-xi)*(y-yi)/(yj-yi) + xi)) inside = !inside;
        }
        return inside;
    }
    function _pts2dEq(a, b) { return Math.abs(a[0]-b[0]) < 1e-9 && Math.abs(a[1]-b[1]) < 1e-9; }
    function _seg2dIntersect(p1, p2, p3, p4) {
        function cross(o, a, b) { return (a[0]-o[0])*(b[1]-o[1]) - (a[1]-o[1])*(b[0]-o[0]); }
        var d1 = cross(p3,p4,p1), d2 = cross(p3,p4,p2), d3 = cross(p1,p2,p3), d4 = cross(p1,p2,p4);
        return ((d1>0&&d2<0)||(d1<0&&d2>0)) && ((d3>0&&d4<0)||(d3<0&&d4>0));
    }
    // 把洞的迴圈「橋接」併入外圍迴圈，變成一條沒有自我交叉的簡單多邊形（挖洞耳切法的前置
    // 步驟，思路與 Mapbox earcut.js 的 hole-linking 相同）：每個洞找它最右邊（u 最大）的
    // 頂點當進入點，往外圍找「距離最近、橋接線段不會跟任何既有邊相交」的頂點搭橋，把洞
    // 整圈插進外圍序列（去程橋＋整圈洞＋回到洞起點＋回程橋）。
    function _bridgeHolesInto(outer, holes) {
        var ring = outer.slice();
        var holesSorted = holes.slice().sort(function (a, b) {
            var maxA = Math.max.apply(null, a.map(function (p) { return p[0]; }));
            var maxB = Math.max.apply(null, b.map(function (p) { return p[0]; }));
            return maxB - maxA;
        });
        holesSorted.forEach(function (hole) {
            var ei = 0;
            for (var k = 1; k < hole.length; k++) { if (hole[k][0] > hole[ei][0]) ei = k; }
            var entry = hole[ei];
            var cand = ring.map(function (p, idx) { return { idx: idx, d: (p[0]-entry[0])*(p[0]-entry[0]) + (p[1]-entry[1])*(p[1]-entry[1]) }; })
                            .sort(function (a, b) { return a.d - b.d; });
            var bridgeIdx = -1;
            for (var c = 0; c < cand.length; c++) {
                var idx = cand[c].idx;
                var ok = true;
                for (var e = 0; e < ring.length; e++) {
                    var a = ring[e], b = ring[(e+1)%ring.length];
                    if (_pts2dEq(a,ring[idx]) || _pts2dEq(b,ring[idx]) || _pts2dEq(a,entry) || _pts2dEq(b,entry)) continue;
                    if (_seg2dIntersect(ring[idx], entry, a, b)) { ok = false; break; }
                }
                if (ok) { bridgeIdx = idx; break; }
            }
            if (bridgeIdx < 0) bridgeIdx = 0;   // 保底：極少數退化情形找不到乾淨的橋，寧可可能略醜也不整個洞放棄不處理
            var reordered = hole.slice(ei).concat(hole.slice(0, ei));
            var insertPts = reordered.concat([reordered[0]], [ring[bridgeIdx]]);
            ring = ring.slice(0, bridgeIdx+1).concat(insertPts).concat(ring.slice(bridgeIdx+1));
        });
        return ring;
    }
    function _pointInTri2d(p, a, b, c) {
        function sign(p1, p2, p3) { return (p1[0]-p3[0])*(p2[1]-p3[1]) - (p2[0]-p3[0])*(p1[1]-p3[1]); }
        var d1 = sign(p,a,b), d2 = sign(p,b,c), d3 = sign(p,c,a);
        var hasNeg = (d1<0)||(d2<0)||(d3<0), hasPos = (d1>0)||(d2>0)||(d3>0);
        return !(hasNeg && hasPos);
    }
    // 標準耳切三角化（ear clipping）：逐一找「凸角且三角形內不含其他頂點」的耳朵剪掉，
    // 直到剩 3 點。輸入務必先經過 _bridgeHolesInto 處理成不自交的簡單多邊形。
    function _earClipTriangulate(poly) {
        var pts = poly.slice();
        for (var i = pts.length-1; i > 0; i--) { if (_pts2dEq(pts[i], pts[i-1])) pts.splice(i,1); }
        if (pts.length > 1 && _pts2dEq(pts[0], pts[pts.length-1])) pts.pop();
        if (pts.length < 3) return [];
        if (_signedArea2d(pts) < 0) pts.reverse();   // 統一成 CCW，耳切的凸角判斷才一致
        var idx = pts.map(function (_, i) { return i; });
        var tris = [];
        var guard = 0;
        while (idx.length > 3 && guard++ < pts.length*pts.length + 20) {
            var earFound = false;
            for (var i = 0; i < idx.length; i++) {
                var i0 = idx[(i-1+idx.length)%idx.length], i1 = idx[i], i2 = idx[(i+1)%idx.length];
                var a = pts[i0], b = pts[i1], c = pts[i2];
                var cr = (b[0]-a[0])*(c[1]-a[1]) - (b[1]-a[1])*(c[0]-a[0]);
                if (cr <= 1e-12) continue;   // 非凸角，不能當耳朵
                var isEar = true;
                for (var k = 0; k < idx.length; k++) {
                    var pk = idx[k];
                    if (pk===i0 || pk===i1 || pk===i2) continue;
                    if (_pointInTri2d(pts[pk], a, b, c)) { isEar = false; break; }
                }
                if (!isEar) continue;
                tris.push([a,b,c]);
                idx.splice(i,1);
                earFound = true;
                break;
            }
            if (!earFound) break;   // 數值精度或退化多邊形導致找不到耳朵，放棄剩餘部份不強求
        }
        if (idx.length === 3) tris.push([pts[idx[0]], pts[idx[1]], pts[idx[2]]]);
        return tris;
    }
    // 把全部迴圈（外圍與洞一視同仁）填成 2D 三角形清單——「掃描線梯形分解」。
    //
    // ★ 2026-10-12：這裡原本是「巢狀判定分出實心/洞 → 把洞橋接併入外圍 → 耳切三角化
    //   (ear clipping)」，那套是**這個 3D 剖面功能一路以來「斷面空心」的真正病灶**，
    //   而且症狀完全不像三角化壞掉：
    //   橋接會在多邊形上製造「自我接觸」的重複頂點（橋的兩端各出現兩次），而耳切判斷
    //   一個角能不能當耳朵時要檢查「三角形內不可含其他頂點」，`_pointInTri2d` 連落在
    //   **邊界上**的點都算「在裡面」——橋上的那些重複頂點正好落在候選耳朵的邊上，於是
    //   每一個跨過橋的耳朵都被否決；洞一多（這個法蘭截面有 6 個螺栓孔）就完全找不到
    //   耳朵，`if (!earFound) break;` 直接放棄剩下的部份，**只剩最外圈幾片碎片**，而且
    //   不會丟任何錯誤。實測 Y 軸 22% 的截面本該是一整片圓盤，只畫出外緣六片細月牙，
    //   平常又剛好被模型外壁擋住 → 使用者看到的就是「斷面不見了／看起來空心」。
    //   「有孔的截面才壞、沒孔的正常」「換個剖切位置就好了」也都是同一個原因。
    //
    //   改用掃描線梯形分解，從根本上繞開這整類問題：
    //   ①不需要分辨誰是外圍誰是洞——用偶奇填充（even-odd）規則自然成立，巢狀幾層都對；
    //   ②不需要橋接，也就沒有自我接觸的重複頂點；
    //   ③不需要找耳朵，不會有「找不到就放棄一半」這種無聲失敗。
    //   作法：把所有邊收集起來，依**每一個頂點的 y** 切成一條條水平帶（所以帶內任何一條
    //   邊都不會有端點、必定是直線穿過整條帶 → 梯形的斜邊與原本的邊完全重合，分解是
    //   精確的不是近似），每條帶取中線求出所有交點、由左到右排序後兩兩配對成梯形，
    //   每個梯形切兩個三角形。
    function _buildCapTriangles2d(loops2d) {
        var edges = [], ys = [];
        (loops2d || []).forEach(function (loop) {
            if (!loop || loop.length < 3) return;
            for (var i = 0; i < loop.length; i++) {
                var a = loop[i], b = loop[(i+1) % loop.length];
                ys.push(a[1]);
                if (a[1] === b[1]) continue;   // 水平邊不會貢獻交點，只貢獻一條帶的邊界（已由 ys 收走）
                edges.push(a[1] < b[1] ? { x0:a[0], y0:a[1], x1:b[0], y1:b[1] } : { x0:b[0], y0:b[1], x1:a[0], y1:a[1] });
            }
        });
        if (edges.length < 2) return [];
        ys.sort(function (p, q) { return p - q; });
        var spanY = ys[ys.length-1] - ys[0];
        if (!(spanY > 0)) return [];
        var yEps = spanY * 1e-9;
        var bands = [ys[0]];
        for (var i = 1; i < ys.length; i++) { if (ys[i] - bands[bands.length-1] > yEps) bands.push(ys[i]); }
        if (bands.length < 2) return [];
        var tris = [];
        for (var bi = 0; bi < bands.length - 1; bi++) {
            var yTop = bands[bi], yBot = bands[bi+1];
            if (yBot - yTop <= yEps) continue;
            var yMid = (yTop + yBot) / 2;
            var xs = [];
            for (var ei = 0; ei < edges.length; ei++) {
                var e = edges[ei];
                if (e.y0 > yMid || e.y1 < yMid) continue;   // 帶的切點落在每個頂點上，所以邊不是整條穿過就是完全沒碰到
                var inv = (e.x1 - e.x0) / (e.y1 - e.y0);
                xs.push({ mid: e.x0 + (yMid - e.y0) * inv, top: e.x0 + (yTop - e.y0) * inv, bot: e.x0 + (yBot - e.y0) * inv });
            }
            if (xs.length < 2) continue;
            xs.sort(function (p, q) { return p.mid - q.mid; });
            for (var k = 0; k + 1 < xs.length; k += 2) {   // 偶奇填充：第1↔2、第3↔4…之間才是實心材料
                var L = xs[k], R = xs[k+1];
                if (Math.abs(R.top - L.top) < 1e-12 && Math.abs(R.bot - L.bot) < 1e-12) continue;
                var tl = [L.top, yTop], tr = [R.top, yTop], br = [R.bot, yBot], bl = [L.bot, yBot];
                tris.push([tl, tr, br]);
                tris.push([tl, br, bl]);
            }
        }
        return tris;
    }
    // 整合：給一個剖切平面與整個模型，算出封頂幾何（世界座標 BufferGeometry）＋連帶算這個
    // 封頂的 2D 三角化結果／平面基底（供呼叫端另外疊 45 度剖面斜線用，見
    // _buildCapHatchGeometry，三角化只算一次兩邊共用，不要各自重算一次）。沒有交線（平面
    // 完全沒切到模型）或三角化不出結果時回傳 null，呼叫端就不加這個封頂物件。
    function _buildCapGeometry(mm, plane) {
        var loops3d = _buildCapLoops3d(mm, plane);
        if (!loops3d.length) return null;
        var basis = _planeBasis3d(plane);
        var loops2d = loops3d.map(function (l) { return _loop3dTo2d(l, basis); });
        var tris2d = _buildCapTriangles2d(loops2d);
        if (!tris2d.length) return null;
        var verts = [], normals = [];
        tris2d.forEach(function (tri) {
            tri.forEach(function (p2) {
                verts.push(
                    basis.origin[0] + p2[0]*basis.u[0] + p2[1]*basis.v[0],
                    basis.origin[1] + p2[0]*basis.u[1] + p2[1]*basis.v[1],
                    basis.origin[2] + p2[0]*basis.u[2] + p2[1]*basis.v[2]
                );
                normals.push(basis.n[0], basis.n[1], basis.n[2]);
            });
        });
        var sampleMesh = null;
        mm.EnumerateMeshes(function (m) { if (!sampleMesh) sampleMesh = m; });
        if (!sampleMesh) return null;
        var GeoCtor = sampleMesh.geometry.constructor;
        var PosAttrCtor = sampleMesh.geometry.attributes.position.constructor;
        var geo = new GeoCtor();
        geo.setAttribute('position', new PosAttrCtor(new Float32Array(verts), 3));
        geo.setAttribute('normal', new PosAttrCtor(new Float32Array(normals), 3));
        return { geo: geo, tris2d: tris2d, basis: basis };
    }
    // ── 剖面斜線（45 度剖面劃線，2026-10-12 使用者要求比照工程圖慣例：剖切到的實心材料
    // 用斜線（hachure）表示，不要整面實色填滿）──────────────────────────────────
    // 作法：在封頂的 2D 參數空間（跟三角化共用同一份 basis.u/v）畫一組等間距、固定角度的
    // 平行線，逐一跟「已經三角化好的每一片三角形」求交——三角形互不重疊、剛好鋪滿整個填色
    // 區域（含挖洞處自然沒有三角形＝線也不會畫進洞裡，不需要另外處理洞），不需要另外對整個
    // 多邊形（可能帶洞）重新寫一套裁剪邏輯。單一三角形一次直線裁剪最多只會留下一段（三角形
    // 是凸的），逐一相交收集起來串成這條斜線在填色區域內的所有線段。
    function _clipLineByHalfPlane2d(tMin, tMax, p0, dir, edgeP, nx, ny) {
        // 半平面以「內側」為 dot(n, point-edgeP) >= 0
        var num = nx * (edgeP[0] - p0[0]) + ny * (edgeP[1] - p0[1]);
        var den = nx * dir[0] + ny * dir[1];
        if (Math.abs(den) < 1e-12) {
            var inside = nx * (p0[0] - edgeP[0]) + ny * (p0[1] - edgeP[1]) >= -1e-9;
            return inside ? [tMin, tMax] : null;
        }
        var t = num / den;
        if (den > 0) { if (t > tMin) tMin = t; } else { if (t < tMax) tMax = t; }
        return tMin <= tMax ? [tMin, tMax] : null;
    }
    function _line2dTriClip(p0, dir, a, b, c) {
        var area2 = (b[0]-a[0])*(c[1]-a[1]) - (b[1]-a[1])*(c[0]-a[0]);
        if (Math.abs(area2) < 1e-9) return null;   // 退化（近似共線）三角形，跳過
        var sign = area2 >= 0 ? 1 : -1;
        var edges = [[a,b],[b,c],[c,a]];
        var range = [-1e7, 1e7];
        for (var i = 0; i < 3; i++) {
            var p = edges[i][0], q = edges[i][1];
            var ex = q[0]-p[0], ey = q[1]-p[1];
            var nx = -ey*sign, ny = ex*sign;   // 邊方向逆時針轉90度＝內向法線（配合三角形纏繞方向）
            range = _clipLineByHalfPlane2d(range[0], range[1], p0, dir, p, nx, ny);
            if (!range) return null;
        }
        return range;
    }
    // tris2d：同一個封頂已經算好的三角化結果；angleDeg：斜線角度（工程圖慣例 45 度，相鄰
    // 封頂刻意可改 135 度互相區分，等同紙本上「不同零件的剖面線角度要錯開」慣例）；
    // spacing：平行線間距（2D 參數空間座標單位，與模型座標同單位）。回傳 2D 線段陣列。
    function _buildCapHatchSegments2d(tris2d, angleDeg, spacing) {
        var rad = angleDeg * Math.PI / 180;
        var dir = [Math.cos(rad), Math.sin(rad)];
        var nrm = [-dir[1], dir[0]];   // 垂直方向，用來把各平行線分組（沿這個方向等距切片）
        var minOff = Infinity, maxOff = -Infinity;
        tris2d.forEach(function (tri) {
            tri.forEach(function (p) {
                var off = p[0]*nrm[0] + p[1]*nrm[1];
                if (off < minOff) minOff = off;
                if (off > maxOff) maxOff = off;
            });
        });
        if (!isFinite(minOff) || !isFinite(maxOff)) return [];
        var segments = [];
        var start = Math.floor(minOff / spacing) * spacing;
        for (var off = start; off <= maxOff + spacing; off += spacing) {
            var p0 = [nrm[0]*off, nrm[1]*off];
            var ranges = [];
            for (var t = 0; t < tris2d.length; t++) {
                var tri = tris2d[t];
                var range = _line2dTriClip(p0, dir, tri[0], tri[1], tri[2]);
                if (range) ranges.push(range);
            }
            if (!ranges.length) continue;
            // 合併同一條斜線上首尾相接的區間：封頂的三角化是「掃描線梯形帶」，一條斜線
            // 會被切成上百個相鄰小段，不合併的話每一小段都要產生一條極窄三角面帶，頂點數
            // 會暴增十倍以上（而且相鄰段的接縫在畫面上還可能出現細微鋸齒）。
            ranges.sort(function (a, b) { return a[0] - b[0]; });
            var joinTol = spacing * 1e-3;
            var cur = ranges[0].slice();
            for (var ri = 1; ri <= ranges.length; ri++) {
                if (ri < ranges.length && ranges[ri][0] <= cur[1] + joinTol) {
                    if (ranges[ri][1] > cur[1]) cur[1] = ranges[ri][1];
                    continue;
                }
                if (cur[1] - cur[0] > joinTol) {
                    segments.push([
                        [p0[0] + dir[0]*cur[0], p0[1] + dir[1]*cur[0]],
                        [p0[0] + dir[0]*cur[1], p0[1] + dir[1]*cur[1]]
                    ]);
                }
                if (ri < ranges.length) cur = ranges[ri].slice();
            }
        }
        return segments;
    }
    // 把斜線畫成「極細的三角面帶」而不是 LineSegments（2026-10-12 實測發現的坑：WebGL 的
    // `polygonOffset` 只對三角形／多邊形的光柵化生效，GL_LINES 完全不吃這個設定——封頂本身
    // 用 polygonOffset 讓它穩定贏過模型本體在同一剖切位置的背面沒問題，但斜線跟封頂自己
    // 的填色面「同樣共平面」時，斜線沒有 polygonOffset 可用，深度測試打平手常常輸給封頂
    // 填色面本身而完全不可見——改成跟封頂一樣是三角面（每條斜線=一條極窄的長方形帶、兩個
    // 三角形），一樣能設 polygonOffset 且給比封頂更靠近鏡頭的偏移量，才能穩定疊在封頂
    // 填色之上，不受視角影響；寬度抓間距的一小部分，看起來細但不會跟封頂填色打架）。
    function _buildCapHatchQuads2d(segs2d, lineWidth) {
        var half = lineWidth / 2;
        var tris = [];
        segs2d.forEach(function (seg) {
            var p1 = seg[0], p2 = seg[1];
            var dx = p2[0]-p1[0], dy = p2[1]-p1[1];
            var len = Math.sqrt(dx*dx+dy*dy);
            if (len < 1e-9) return;
            var nx = -dy/len*half, ny = dx/len*half;
            var a = [p1[0]+nx, p1[1]+ny], b = [p1[0]-nx, p1[1]-ny];
            var c = [p2[0]-nx, p2[1]-ny], d = [p2[0]+nx, p2[1]+ny];
            tris.push([a,b,c], [a,c,d]);
        });
        return tris;
    }
    // tris2d：封頂已算好的三角化結果；basis：同一份平面基底；PosAttrCtor/GeoCtor：借用的
    // BufferGeometry／position 屬性建構子（跟封頂本身共用同一份，呼叫端傳入）。回傳世界座標
    // BufferGeometry（含法向量，供 Phong 材質正常受光），沒有斜線可畫時回傳 null。
    function _buildCapHatchGeometry(tris2d, basis, angleDeg, spacing, lineWidth, PosAttrCtor, GeoCtor) {
        var segs2d = _buildCapHatchSegments2d(tris2d, angleDeg, spacing);
        if (!segs2d.length) return null;
        var quadTris2d = _buildCapHatchQuads2d(segs2d, lineWidth);
        if (!quadTris2d.length) return null;
        var verts = [], normals = [];
        quadTris2d.forEach(function (tri) {
            tri.forEach(function (p2) {
                verts.push(
                    basis.origin[0] + p2[0]*basis.u[0] + p2[1]*basis.v[0],
                    basis.origin[1] + p2[0]*basis.u[1] + p2[1]*basis.v[1],
                    basis.origin[2] + p2[0]*basis.u[2] + p2[1]*basis.v[2]
                );
                normals.push(basis.n[0], basis.n[1], basis.n[2]);
            });
        });
        var geo = new GeoCtor();
        geo.setAttribute('position', new PosAttrCtor(new Float32Array(verts), 3));
        geo.setAttribute('normal', new PosAttrCtor(new Float32Array(normals), 3));
        return geo;
    }
    // 封頂底色（2026-10-12 使用者要求改為工程圖常見的剖面樣式：45度斜線劃線＋淺灰底，
    // 取代原本的暖色系實心填滿）。
    var _EG_CAP_BASE_COLOR = '#C9C9C9';   // 淺灰（使用者原話「不要整面顯示黑色，請改為淺灰色」）
    var _EG_CAP_HATCH_COLOR = '#8a8a8a';  // 斜線顏色，比底色深一階才看得出線條
    function _buildCapMaterial(sampleMesh, colorIdx) {
        var baseMat = Array.isArray(sampleMesh.material) ? sampleMesh.material[0] : sampleMesh.material;
        // 這時候 baseMat.clippingPlanes 已經被 setSectionMode 設成本檔鴨子定型的陽春物件
        // （{normal:{x,y,z},constant}，不是真正的 THREE.Plane 實例）——Three.js 的
        // Material.clone() 內部會對 clippingPlanes 陣列逐一呼叫 .clone()，陽春物件沒有
        // 這個方法會直接丟例外；clone 前先暫時清空、clone 完再還原，不影響原本材質的裁切。
        var savedPlanes = baseMat.clippingPlanes;
        baseMat.clippingPlanes = null;
        var mat = baseMat.clone();
        baseMat.clippingPlanes = savedPlanes;
        // ★ 封頂是本檔自己算出來的新幾何，只有 position/normal，沒有模型本體那些額外屬性
        //   與貼圖；但材質是「複製模型材質」來的，會把那些設定一併帶過來。最嚴重的是
        //   vertexColors——2026-10-12 使用者回報「剖面還是完全沒有改善」，截圖裡斷面是整片
        //   純黑：根因是他先用過「上色」功能，上色模式會把模型材質改成 vertexColors=true
        //   （見 ColorState._ensureMeshState，顏色改由每個頂點的 color 屬性決定、材質色固定
        //   白色），複製過來的封頂材質因此也要求頂點色，而封頂幾何根本沒有 color 屬性，
        //   WebGL 對未提供的屬性一律餵 (0,0,0)，於是下面設的淺灰被乘成 0＝整片黑。
        //   （這也是為什麼「沒上過色的模型」怎麼測都正常、一上色就黑——同一支程式的兩種狀態。）
        //   一律把這類「屬於模型本體、不屬於封頂」的設定歸零，不要只修 vertexColors 一項。
        mat.vertexColors = false;
        ['map','lightMap','aoMap','emissiveMap','specularMap','alphaMap','bumpMap','normalMap',
         'displacementMap','roughnessMap','metalnessMap','envMap'].forEach(function (k) {
            if (mat[k]) mat[k] = null;
        });
        if (mat.emissive) mat.emissive.set('#000000');
        if (mat.metalness !== undefined) mat.metalness = 0;      // 金屬度 1 又沒有環境貼圖時也會算成黑
        if (mat.roughness !== undefined) mat.roughness = 0.85;
        mat.transparent = false;
        mat.opacity = 1;
        mat.flatShading = false;
        // ★ 2026-10-12 使用者回報「透視的剖面顯示異常」：透視（隱藏線）模式會把模型本體的
        //   材質整批設成 colorWrite=false（只寫深度、不畫顏色，面才會變成只剩邊線），而封頂
        //   材質是**複製模型材質**來的，於是連這個旗標一起繼承 → 封頂完全不畫出來，透視＋
        //   剖面時斷面整片消失。與上色那次的 vertexColors 是同一類問題（複製材質會把「屬於
        //   模型本體的顯示狀態」一併帶走），所以這裡一律把這幾個旗標也還原成正常值。
        mat.colorWrite = true;
        mat.depthWrite = true;
        mat.depthTest = true;
        mat.visible = true;
        mat.color.set(_EG_CAP_BASE_COLOR);
        if (mat.specular) mat.specular.set('#222222');   // Phong 材質若保留原本高反光度，淺灰底會被鏡面反射洗成死白，降低一點
        mat.shininess = 6;
        mat.side = 2;   // THREE.DoubleSide（已用 CDP 實測確認此數值，函式庫未暴露全域 THREE 列舉）
        mat.clippingPlanes = null;
        mat.clipIntersection = false;
        // 封頂與模型本體在剖切位置「完全共平面」，深度值幾乎相同，深度測試在極小浮點誤差
        // 下勝負不定——2026-10-12 使用者回報：剛切開時斷面有時看起來像「中空」看到內部
        // 背面（Y軸剖面尤其穩定重現，X／Z軸則是偶發、拖一下剖面位置滑桿重建一次才會消失），
        // 正是經典的 z-fighting：封頂有時輸掉深度測試，被模型本體自己在同一位置的背面
        // （通常光照角度不利、顯得暗沉甚至近黑）蓋過去。標準修法是 `polygonOffset`——把
        // 封頂在深度緩衝區裡「偷偷」往鏡頭方向推一點點（負值＝更靠近鏡頭），讓它穩定贏得
        // 深度測試，不需要使用者手動重建剖面才「矯正」一次。偏移量刻意給得足夠大
        // （-4／-4，Three.js 預設範例常見的 -1 在這個模型的三角面密度下不夠，實測仍偶爾
        // 輸掉），但維持在「看不出封頂本身浮空」的視覺容許範圍內。
        mat.polygonOffset = true;
        mat.polygonOffsetFactor = -4;
        mat.polygonOffsetUnits = -4;
        mat.needsUpdate = true;
        return mat;
    }
    // 依目前啟用中的剖切平面陣列，逐一建立封頂物件（雙軸時每個平面各自的封頂要再被「另一
    // 個」平面裁掉超出範圍的部份，否則雙軸剖面的封頂會畫出整個無限大截面、超出實際只保留
    // 3/4 的那個象限範圍）。回傳新建立的封頂物件陣列（含斜線，見下方），供呼叫端存進
    // state 以利之後移除——刻意把斜線物件也放進同一個陣列回傳，呼叫端既有的清除邏輯
    // （逐一 root.remove()）不需要另外改。
    function _rebuildCaps(viewer, mm, planes) {
        var sampleMesh = null;
        mm.EnumerateMeshes(function (m) { if (!sampleMesh) sampleMesh = m; });
        if (!sampleMesh) return [];
        var MeshCtor = sampleMesh.constructor;
        var caps = [];

        // 斜線間距／線寬依整個模型的包圍盒尺度換算（跟透視模式虛線間距 dashSize 同一種
        // 做法），模型大小不同時斜線密度維持視覺上接近的比例，不會一個模型線條擠成一團、
        // 另一個模型卻稀疏到看不出是剖面線。
        var mbbox = mm.GetBoundingBox(function () { return true; });
        var mscale = mbbox ? _vecLen3([mbbox.max.x-mbbox.min.x, mbbox.max.y-mbbox.min.y, mbbox.max.z-mbbox.min.z]) : 100;
        var hatchSpacing = Math.max(0.8, mscale * 0.025);
        var hatchWidth = Math.max(0.08, hatchSpacing * 0.12);
        var PosAttrCtor = sampleMesh.geometry.attributes.position.constructor;
        var GeoCtor = sampleMesh.geometry.constructor;

        planes.forEach(function (plane, i) {
            var built = _buildCapGeometry(mm, plane);
            if (!built) return;
            var mat = _buildCapMaterial(sampleMesh, i);
            // ★ 2026-10-12 使用者回報「楔形剖面還是異常」：雙平面（楔形／象限）時每一塊封頂
            //   都要被「另一個平面」限制範圍，但方向必須**反過來**。Three.js 的規則是「有號
            //   距離為負的點被切掉」，而模型本體在楔形模式下是 clipIntersection=true（兩個
            //   平面都判定要切才真的切）；所以平面 A 上真正被挖開、需要畫斷面的那一段，正好
            //   是「平面 B 也判定要切除」的那一側。直接把平面 B 原樣拿來裁封頂會剛好相反——
            //   封頂被畫在楔形缺口的**對面**（埋在實體裡看不見，只在零件表面露出一條細線），
            //   缺口裡面反而沒有斷面。把另一個平面的法向量與 constant 同時取反號，就是反轉
            //   它的切除側。
            var others = planes.filter(function (_, j) { return j !== i; }).map(function (p) {
                return { normal: { x: -p.normal.x, y: -p.normal.y, z: -p.normal.z }, constant: -p.constant };
            });
            if (others.length) mat.clippingPlanes = others;
            var capObj = new MeshCtor(built.geo, mat);
            capObj.renderOrder = 6;   // 比透視模式的可見邊(10)低、比背面虛線邊(5)高，封頂面本身不需要跟邊線搶深度排序
            viewer.AddExtraObject(capObj);
            caps.push(capObj);

            // 相鄰封頂（雙平面楔形/象限剖面時）斜線角度刻意錯開 90 度（45／135），對應紙本
            // 工程圖「不同零件（或此處=不同剖切面）的剖面線角度要互相區分」慣例，同時也
            // 順便解決了舊版「兩塊封頂同色、分不出交界」的問題，不必再靠顏色分。
            var angleDeg = (i % 2 === 0) ? 45 : 135;
            var hatchGeo = _buildCapHatchGeometry(built.tris2d, built.basis, angleDeg, hatchSpacing, hatchWidth, PosAttrCtor, GeoCtor);
            if (hatchGeo) {
                // 斜線本身也是三角面（見 _buildCapHatchQuads2d 註解：改用三角面才吃得到
                // polygonOffset），材質沿用跟封頂同一套 _buildCapMaterial 再覆寫顏色與
                // 更靠近鏡頭的偏移量，確保穩定疊在封頂填色之上、不受視角影響。
                var hatchMat = _buildCapMaterial(sampleMesh, i);
                hatchMat.color.set(_EG_CAP_HATCH_COLOR);
                if (others.length) hatchMat.clippingPlanes = others;
                hatchMat.polygonOffsetFactor = -8;
                hatchMat.polygonOffsetUnits = -8;
                hatchMat.needsUpdate = true;
                var hatchObj = new MeshCtor(hatchGeo, hatchMat);
                hatchObj.renderOrder = 7;
                viewer.AddExtraObject(hatchObj);
                caps.push(hatchObj);
            }
        });
        return caps;
    }
    function _buildClipPlane(bbox, axis, ratio, flip) {
        var min = bbox.min[axis], max = bbox.max[axis];
        var planePos = min + (max - min) * ratio;
        var normal = { x: 0, y: 0, z: 0 };
        var normalSign = flip ? -1 : 1;
        normal[axis] = normalSign;
        var constant = -planePos * normalSign;   // Three.js Plane 慣例：normal·point + constant = 0（見上方註解）
        return { normal: normal, constant: constant };
    }
    // ──「楔形剖面」（3/4剖視，2026-10-12 使用者更正）────────────────────────
    // 使用者最初的「3/4剖面」要求，先前誤理解成「兩個互相垂直、各自獨立的軸向剖切面，
    // 只切掉重疊的那個象限（固定90度）」。使用者看過截圖後用時鐘面比喻更正：3/4剖視圖
    // 的正確畫法是「兩把剖切面共用同一條軸線（像鐘面指針共用圓心），兩者之間夾出的
    // 那個**角度可調、通常遠小於90度**的楔形範圍被挖空」——參考圖2（標準工程圖3/4剖視）
    // 正是沿著一條旋轉軸，切出一個窄楔形缺口露出內部孔／軸結構，其餘外觀保持完整立體。
    // 幾何作法：挑一個旋轉軸（X/Y/Z），兩個剖切面都**通過模型包圍盒中心、且都平行於該
    // 旋轉軸**（滿足「共用同一條軸線」），差別只在繞軸旋轉的角度不同；楔形寬度＝兩個角
    // 度的差，使用者可調（1～179度），不再限定固定90度。
    //
    // 法向量推導（已用代入驗證，見下方 _buildWedgePlane）：設垂直於旋轉軸的平面上一點
    // 的角度為 φ（繞軸測量），平面在角度 θ 處、法向量取 N(θ)=(sinθ,-cosθ)（在該垂直平面
    // 內的兩個分量），則 dot(N(θ),方向(φ)) = -sin(φ-θ)，<0 時 φ∈(θ,θ+180°)。兩個楔形面
    // 分別取 θ1（which=1）與 θ2（which=2，法向量取反號 N'(θ)=(-sinθ,cosθ)，使 dot<0 時
    // φ∈(θ-180°,θ)），兩者的「切除」交集恰好是 φ∈(θ1,θ2)（前提 0<θ2-θ1<180°）——這正是
    // 要挖空的那個楔形，故跟舊版象限剖面一樣要用 clipIntersection=true（AND 語意：兩面
    // 都判定切除才真正切除）。
    function _perpAxes3d(axis) {
        if (axis === 'x') return ['y', 'z'];
        if (axis === 'y') return ['z', 'x'];
        return ['x', 'y'];
    }
    function _buildWedgePlane(center, axis, angleDeg, which) {
        var rad = angleDeg * Math.PI / 180;
        var ax = _perpAxes3d(axis);
        var normal = { x: 0, y: 0, z: 0 };
        if (which === 1) {
            normal[ax[0]] = Math.sin(rad);
            normal[ax[1]] = -Math.cos(rad);
        } else {
            normal[ax[0]] = -Math.sin(rad);
            normal[ax[1]] = Math.cos(rad);
        }
        var constant = -(normal.x * center.x + normal.y * center.y + normal.z * center.z);
        return { normal: normal, constant: constant };
    }
    function _buildWedgePlanes(bbox, axis, startDeg, widthDeg, flip) {
        var center = {
            x: (bbox.min.x + bbox.max.x) / 2,
            y: (bbox.min.y + bbox.max.y) / 2,
            z: (bbox.min.z + bbox.max.z) / 2
        };
        var w = Math.max(1, Math.min(179, widthDeg));
        var a1 = startDeg, a2 = startDeg + (flip ? -w : w);
        if (a2 < a1) { var t = a1; a1 = a2; a2 = t; }
        return [_buildWedgePlane(center, axis, a1, 1), _buildWedgePlane(center, axis, a2, 2)];
    }
    // 統一算出目前該用哪幾個剖切平面——楔形模式（opts.axis2 有指定旋轉軸）完全取代單軸
    // 剖切（不是疊加），兩者是互斥的兩種顯示模式，不是「再加切一刀」。
    function _computeSectionPlanes(bbox, opts) {
        var wedge = !!(opts.axis2 && opts.axis2 !== 'none');
        if (wedge) {
            var startDeg = (opts.ratio2 == null ? 0.25 : opts.ratio2) * 360;
            var widthDeg = 1 + (opts.ratio3 == null ? 0.5 : opts.ratio3) * 178;
            return { planes: _buildWedgePlanes(bbox, opts.axis2, startDeg, widthDeg, !!opts.flip2), dual: true };
        }
        var axis = opts.axis || 'x';
        var ratio = opts.ratio == null ? 0.5 : opts.ratio;
        var flip = !!opts.flip;
        return { planes: [_buildClipPlane(bbox, axis, ratio, flip)], dual: false };
    }
    function setSectionMode(embeddedViewer, opts) {
        opts = opts || {};
        var viewer = embeddedViewer.GetViewer();
        var mm = viewer.mainModel;
        var bbox = mm.GetBoundingBox(function () { return true; });
        if (!bbox) return null;
        var pc = _computeSectionPlanes(bbox, opts);
        var planes = pc.planes, dual = pc.dual;

        viewer.renderer.localClippingEnabled = true;
        var touchedMats = [];
        mm.EnumerateMeshes(function (o) {
            var mats = Array.isArray(o.material) ? o.material : [o.material];
            mats.forEach(function (mt) {
                mt.clippingPlanes = planes;
                mt.clipIntersection = dual;
                mt.needsUpdate = true;
                touchedMats.push(mt);
            });
        });
        var capObjs = _rebuildCaps(viewer, mm, planes);
        viewer.Render();
        return { touchedMats: touchedMats, plane: planes[0], plane2: planes[1] || null, dual: dual, capObjs: capObjs, bbox: { min: { x: bbox.min.x, y: bbox.min.y, z: bbox.min.z }, max: { x: bbox.max.x, y: bbox.max.y, z: bbox.max.z } } };
    }
    // 調整現有剖面（換軸／拖曳位置／翻轉保留側／切換楔形）不必重新掃描全部 mesh，但**一定
    // 要建一組全新的 plane 物件、重新指派整個 clippingPlanes 陣列**，不可以沿用舊物件只
    // 改屬性值——已實測踩到這個坑：Three.js 的 WebGLClipping 內部對 clippingState 有快取
    // 機制，單純 mutate 同一個 plane 物件的 normal/constant（即使材質 needsUpdate=true）
    // 在某些情況下畫面完全不會更新（X 軸測試正常，切到 Y 軸後滑桿/切換卻看起來像沒反應，
    // 一度以為是 change 事件沒綁好，查過 _bom3dSectionOpts 確認狀態其實有正確更新，問題
    // 出在渲染端讀到的還是舊的裁切面）；改成每次都發新物件＋重新指派陣列參照後才穩定
    // 重現切面。
    function updateSectionMode(embeddedViewer, state, opts) {
        if (!state || !state.bbox) return;
        opts = opts || {};
        var pc = _computeSectionPlanes(state.bbox, opts);
        var planes = pc.planes, dual = pc.dual;
        state.plane = planes[0]; state.plane2 = planes[1] || null; state.dual = dual;
        state.touchedMats.forEach(function (mt) {
            mt.clippingPlanes = planes;
            mt.clipIntersection = dual;
            mt.needsUpdate = true;
        });
        var viewer = embeddedViewer.GetViewer();
        var root = viewer.extraModel.GetRootObject();
        (state.capObjs || []).forEach(function (o) { root.remove(o); });
        state.capObjs = _rebuildCaps(viewer, viewer.mainModel, planes);
        viewer.Render();
    }
    function clearSectionMode(embeddedViewer, state) {
        if (!embeddedViewer || !state) return;
        (state.touchedMats || []).forEach(function (mt) { mt.clippingPlanes = null; mt.clipIntersection = false; mt.needsUpdate = true; });
        var viewer = embeddedViewer.GetViewer();
        var root = viewer.extraModel.GetRootObject();
        (state.capObjs || []).forEach(function (o) { root.remove(o); });
        viewer.Render();
    }

    function escHtml3d(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 標準視角（2026-10-12 使用者要求：像繪圖軟體一樣可以快速翻到前/後/上/下/左/右/
    // 等角視圖，不想每次都要自己手動拖曳旋轉）。
    // ══════════════════════════════════════════════════════════════════════
    // 這裡刻意不做成「可互動點擊的立體導航方塊」（ViewCube）——查證過函式庫本身完全沒有
    // 這個元件、也沒有任何「切到標準視角」的內建 API（已 grep 全部原始碼確認），要做出
    // 一顆完整的立體方塊（含面/邊/角 26 種可點擊區域各自對應一個視角、hover 高亮、跟相機
    // 即時同步旋轉）是一個份量很大的全新 UI 元件，而使用者實際要解決的問題是「沒辦法
    // 快速翻到想要的角度」，一排「前/後/左/右/上/下/等角」按鈕已經能滿足同樣的實際需求、
    // 風險遠低很多。
    //
    // 技術上刻意跟先前「對準剖切面法向量」的相機朝向技術（openInImageEditor 一類）分開：
    // 那個技術依賴**模型當下的幾何特徵**（剖切面在哪、法向量朝哪）去算相機角度，已實測
    // 對複雜模型會算出退化／切線方向的視角而不可靠；這裡的六個標準方向與等角視圖是**固定
    // 的世界座標軸方向**，跟模型長什麼樣子完全無關，純粹是相機位置的三角函數計算，不會有
    // 同一種不可靠的問題。
    //
    // 寫法沿用 2026-10-08 以來已經多處驗證可行的「借既有實例的建構子」技巧（函式庫沒有
    // 把 THREE 掛在全域，不能 `new THREE.Vector3()`）：camera.eye.constructor 借
    // Vector3、camera.constructor 借相機類別本身（查證過是 `(eye, center, up, fov)`
    // 四個參數的建構子）。
    var _STD_VIEWS = {
        front:  { dir: [0, 0, 1],  up: [0, 1, 0] },
        back:   { dir: [0, 0, -1], up: [0, 1, 0] },
        right:  { dir: [1, 0, 0],  up: [0, 1, 0] },
        left:   { dir: [-1, 0, 0], up: [0, 1, 0] },
        top:    { dir: [0, 1, 0],  up: [0, 0, -1] },
        bottom: { dir: [0, -1, 0], up: [0, 0, 1] },
        iso:    { dir: [1, 1, 1],  up: [0, 1, 0] }
    };
    function setStandardView(embeddedViewer, viewName) {
        if (!embeddedViewer) return false;
        var cfg = _STD_VIEWS[viewName];
        if (!cfg) return false;
        var viewer = embeddedViewer.GetViewer();
        var mm = viewer.mainModel;
        var bbox = mm.GetBoundingBox(function () { return true; });
        if (!bbox) return false;
        var center = {
            x: (bbox.min.x + bbox.max.x) / 2,
            y: (bbox.min.y + bbox.max.y) / 2,
            z: (bbox.min.z + bbox.max.z) / 2
        };
        var camera = viewer.navigation && viewer.navigation.GetCamera();
        if (!camera || !camera.eye) return false;
        // 距離沿用目前相機跟中心的距離（保留使用者目前縮放的程度），退化成 0（例如剛
        // 載入、eye 剛好跟 center 一樣）時改用包圍盒對角線長度推一個合理值。注意不可以
        // 用 _vecLen3（它對長度 0 的向量回傳 1 不是 0，是給別處避免除以 0 用的，這裡
        // 剛好要分辨「真的是 0」才能正確觸發下面的備援距離，兩者用途不同不能共用）。
        var ex = camera.eye.x - camera.center.x, ey = camera.eye.y - camera.center.y, ez = camera.eye.z - camera.center.z;
        var curDist = Math.sqrt(ex * ex + ey * ey + ez * ez);
        var dist = curDist > 1e-6 ? curDist : _vecLen3([bbox.max.x - bbox.min.x, bbox.max.y - bbox.min.y, bbox.max.z - bbox.min.z]) * 1.5;
        var dlen = _vecLen3(cfg.dir);
        var nx = cfg.dir[0] / dlen, ny = cfg.dir[1] / dlen, nz = cfg.dir[2] / dlen;
        var Vec3Ctor = camera.eye.constructor;
        var CamCtor = camera.constructor;
        var newEye = new Vec3Ctor(center.x + nx * dist, center.y + ny * dist, center.z + nz * dist);
        var newCenter = new Vec3Ctor(center.x, center.y, center.z);
        var newUp = new Vec3Ctor(cfg.up[0], cfg.up[1], cfg.up[2]);
        var newCam = new CamCtor(newEye, newCenter, newUp, camera.fov);
        viewer.navigation.SetCamera(newCam);
        viewer.Render();
        return true;
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
        clearHiddenLineMode: clearHiddenLineMode,
        applySectionClipToHiddenLine: applySectionClipToHiddenLine,
        setSectionMode: setSectionMode,
        updateSectionMode: updateSectionMode,
        clearSectionMode: clearSectionMode,
        setStandardView: setStandardView
    };
})();
