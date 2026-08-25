/* eg_photo_album.js — 照片相簿：九宮格縮圖 + 點開放大（全站唯一實作，禁止各頁自刻）
 * ---------------------------------------------------------------------------
 * 2026-08-25 使用者要求：產品照片這種附件要像相簿一樣，九宮格排列、可各別點開放大。
 * 目前使用端：料號主檔附件跳窗、BOM檢視器、料號檢視器（三頁共用同一份，改這裡三頁一起生效）。
 *
 * 用法：
 *   EGAlbum.grid(el, photos, {selectable:true, onOpen:fn(i), onSelect:fn(ids)});
 *   EGAlbum.viewer(photos, index);      // 直接開放大檢視
 *   EGAlbum.thumb(url, 400);            // 由附件下載網址推出縮圖網址
 *
 * photos 每一筆：{ id, url, name, uploaded_at, uploaded_by, note, badges:[{text,color,bg}] }
 * 縮圖走後端 GD 產生（download&thumb=1），不是把原圖塞進 <img> 縮小——
 * 一張手機照片動輒 5MB，九宮格一次 9 張＝40MB，那是會卡住整個跳窗的。
 */
(function (global) {
'use strict';

var CSS_ID = 'eg-album-css';
function injectCss() {
    if (document.getElementById(CSS_ID)) return;
    var s = document.createElement('style');
    s.id = CSS_ID;
    s.textContent = [
        '.eg-album-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:10px;align-content:start;overflow-y:auto;height:100%;box-sizing:border-box;}',
        '.eg-album-cell{position:relative;border:1px solid #e4e8ed;border-radius:6px;overflow:hidden;background:#fff;cursor:pointer;transition:box-shadow .12s,border-color .12s;}',
        '.eg-album-cell:hover{border-color:#F0A24B;box-shadow:0 2px 10px rgba(240,162,75,.35);}',
        '.eg-album-cell.sel{border-color:#DD5138;box-shadow:0 0 0 2px rgba(221,81,56,.35);}',
        '.eg-album-thumb{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:#f4f5f7;}',
        '.eg-album-thumb.na{display:flex;align-items:center;justify-content:center;color:#b9a68d;font-size:26px;}',
        '.eg-album-cap{padding:3px 6px;font-size:10px;color:#7A5C33;background:#FFF9F0;border-top:1px solid #F1E3CE;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
        '.eg-album-cap b{font-weight:600;color:#5B4526;}',
        '.eg-album-chk{position:absolute;top:5px;left:5px;width:18px;height:18px;cursor:pointer;z-index:2;}',
        '.eg-album-badges{position:absolute;top:4px;right:4px;display:flex;gap:2px;flex-wrap:wrap;justify-content:flex-end;max-width:80%;z-index:1;}',
        '.eg-album-badges span{font-size:9px;font-weight:700;border-radius:3px;padding:0 4px;line-height:15px;}',
        '.eg-album-empty{color:#aaa;font-size:13px;text-align:center;padding:30px 10px;}',
        /* ── 放大檢視（燈箱） ── */
        '.eg-lb-mask{position:fixed;inset:0;background:rgba(32,24,16,.92);z-index:20000;display:flex;flex-direction:column;}',
        '.eg-lb-bar{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:8px 14px;color:#F7E0BD;font-size:12px;background:rgba(0,0,0,.35);}',
        '.eg-lb-bar .nm{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
        '.eg-lb-btn{background:rgba(255,255,255,.12);border:1px solid rgba(247,224,189,.45);color:#F7E0BD;border-radius:5px;padding:2px 10px;font-size:12px;cursor:pointer;white-space:nowrap;}',
        '.eg-lb-btn:hover{background:rgba(240,162,75,.35);}',
        '.eg-lb-stage{flex:1;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;}',
        '.eg-lb-stage img{max-width:100%;max-height:100%;transform-origin:center center;user-select:none;-webkit-user-drag:none;}',
        '.eg-lb-nav{position:absolute;top:50%;transform:translateY(-50%);width:46px;height:78px;border:none;background:rgba(0,0,0,.38);color:#fff;font-size:30px;cursor:pointer;line-height:1;}',
        '.eg-lb-nav:hover{background:rgba(240,162,75,.6);}',
        '.eg-lb-nav.prev{left:0;border-radius:0 6px 6px 0;}',
        '.eg-lb-nav.next{right:0;border-radius:6px 0 0 6px;}',
        '.eg-lb-film{flex:0 0 auto;display:flex;gap:6px;padding:7px 10px;overflow-x:auto;background:rgba(0,0,0,.35);}',
        '.eg-lb-film img{height:52px;width:52px;object-fit:cover;border-radius:4px;border:2px solid transparent;cursor:pointer;opacity:.6;}',
        '.eg-lb-film img.on{border-color:#F0A24B;opacity:1;}'
    ].join('\n');
    document.head.appendChild(s);
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

var IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
function isImageName(name) {
    var e = String(name || '').split('.').pop().toLowerCase();
    return IMG_EXT.indexOf(e) >= 0;
}

/** 由附件下載網址推出縮圖網址（同一支 API 加 thumb 參數；非圖片就原樣回傳） */
function thumbUrl(url, w) {
    if (!url) return '';
    if (String(url).indexOf('action=download') < 0) return url;   // 只有附件 API 支援縮圖
    return url + '&thumb=1&w=' + (w || 400);
}

/* ── 九宮格 ────────────────────────────────────────────────────────────── */
/**
 * @param {HTMLElement|string} el      容器（元素或 id）
 * @param {Array}  photos             照片清單
 * @param {Object} opts               {selectable, selected:[], onOpen(idx), onSelect(ids), emptyText, cols}
 */
function grid(el, photos, opts) {
    injectCss();
    var box = (typeof el === 'string') ? document.getElementById(el) : el;
    if (!box) return;
    opts = opts || {};
    photos = photos || [];
    if (!photos.length) {
        box.innerHTML = '<div class="eg-album-empty"><i class="fa fa-picture-o"></i><br>' + esc(opts.emptyText || '這本相簿還沒有照片') + '</div>';
        return;
    }
    var sel = {};
    (opts.selected || []).forEach(function (id) { sel[String(id)] = true; });
    var cols = opts.cols || 3;
    var html = '';
    photos.forEach(function (p, i) {
        var img = isImageName(p.name || p.url)
            ? '<img class="eg-album-thumb" loading="lazy" src="' + esc(thumbUrl(p.url, 400)) + '" alt="' + esc(p.name) + '"'
              + ' onerror="this.onerror=null;this.src=' + "'" + esc(p.url) + "'" + ';">'
            : '<div class="eg-album-thumb na"><i class="fa fa-file-o"></i></div>';
        var badges = '';
        (p.badges || []).forEach(function (b) {
            badges += '<span style="background:' + esc(b.bg || '#F7E0BD') + ';color:' + esc(b.color || '#7A4A12') + ';">' + esc(b.text) + '</span>';
        });
        var cap = esc(p.name || '');
        var sub = [p.uploaded_at || '', p.uploaded_by || ''].filter(Boolean).join(' · ');
        html += '<div class="eg-album-cell' + (sel[String(p.id)] ? ' sel' : '') + '" data-idx="' + i + '" data-id="' + esc(p.id) + '" title="' + cap + '">'
              + (opts.selectable ? '<input type="checkbox" class="eg-album-chk"' + (sel[String(p.id)] ? ' checked' : '') + ' data-id="' + esc(p.id) + '">' : '')
              + (badges ? '<div class="eg-album-badges">' + badges + '</div>' : '')
              + img
              + '<div class="eg-album-cap"><b>' + cap + '</b>' + (sub ? '<br>' + esc(sub) : '') + '</div>'
              + '</div>';
    });
    box.innerHTML = '<div class="eg-album-grid" style="grid-template-columns:repeat(' + cols + ',1fr);">' + html + '</div>';

    var gridEl = box.querySelector('.eg-album-grid');
    gridEl.addEventListener('click', function (e) {
        var chk = e.target.closest('.eg-album-chk');
        if (chk) {                                  // 勾選：不開放大
            e.stopPropagation();
            chk.closest('.eg-album-cell').classList.toggle('sel', chk.checked);
            if (opts.onSelect) opts.onSelect(selectedIds(box));
            return;
        }
        var cell = e.target.closest('.eg-album-cell');
        if (!cell) return;
        var idx = parseInt(cell.dataset.idx, 10) || 0;
        if (opts.onOpen) opts.onOpen(idx, photos[idx]);
        else viewer(photos, idx);
    });
}

/** 取目前勾選的照片 id（字串陣列） */
function selectedIds(el) {
    var box = (typeof el === 'string') ? document.getElementById(el) : el;
    if (!box) return [];
    return Array.prototype.map.call(box.querySelectorAll('.eg-album-chk:checked'), function (c) { return c.dataset.id; });
}

/** 全選 / 全不選 */
function selectAll(el, on) {
    var box = (typeof el === 'string') ? document.getElementById(el) : el;
    if (!box) return [];
    Array.prototype.forEach.call(box.querySelectorAll('.eg-album-chk'), function (c) {
        c.checked = !!on;
        c.closest('.eg-album-cell').classList.toggle('sel', !!on);
    });
    return selectedIds(box);
}

/* ── 放大檢視（燈箱）──────────────────────────────────────────────────── */
var _lb = null;

function viewer(photos, idx) {
    injectCss();
    close();
    photos = photos || [];
    if (!photos.length) return;
    idx = Math.max(0, Math.min(photos.length - 1, idx || 0));

    var mask = document.createElement('div');
    mask.className = 'eg-lb-mask';
    mask.innerHTML =
        '<div class="eg-lb-bar">'
      +   '<span class="ct" style="flex:0 0 auto;font-weight:700;"></span>'
      +   '<span class="nm"></span>'
      +   '<button type="button" class="eg-lb-btn" data-act="zo">－</button>'
      +   '<span class="zl" style="min-width:44px;text-align:center;">100%</span>'
      +   '<button type="button" class="eg-lb-btn" data-act="zi">＋</button>'
      +   '<button type="button" class="eg-lb-btn" data-act="fit">⊡ 還原</button>'
      +   '<a class="eg-lb-btn dl" href="#" download><i class="fa fa-download"></i> 另存</a>'
      +   '<a class="eg-lb-btn op" href="#" target="_blank"><i class="fa fa-external-link"></i> 開新視窗</a>'
      +   '<button type="button" class="eg-lb-btn" data-act="close" style="font-size:16px;line-height:1;padding:1px 10px;">&times;</button>'
      + '</div>'
      + '<div class="eg-lb-stage">'
      +   '<button type="button" class="eg-lb-nav prev" data-act="prev">‹</button>'
      +   '<img alt="">'
      +   '<button type="button" class="eg-lb-nav next" data-act="next">›</button>'
      + '</div>'
      + '<div class="eg-lb-film"></div>';
    document.body.appendChild(mask);

    var st = { photos: photos, i: idx, scale: 1, tx: 0, ty: 0, drag: false, sx: 0, sy: 0, stx: 0, sty: 0, mask: mask };
    _lb = st;

    var img   = mask.querySelector('.eg-lb-stage img');
    var film  = mask.querySelector('.eg-lb-film');
    var zl    = mask.querySelector('.zl');

    function apply() {
        img.style.transform = 'translate(' + st.tx + 'px,' + st.ty + 'px) scale(' + st.scale + ')';
        zl.textContent = Math.round(st.scale * 100) + '%';
        img.style.cursor = st.scale > 1.05 ? (st.drag ? 'grabbing' : 'grab') : 'default';
    }
    function show(i) {
        st.i = (i + photos.length) % photos.length;
        var p = photos[st.i];
        st.scale = 1; st.tx = 0; st.ty = 0;
        img.src = p.url;
        mask.querySelector('.nm').textContent = p.name || '';
        mask.querySelector('.ct').textContent = (st.i + 1) + ' / ' + photos.length;
        var dl = mask.querySelector('.dl'), op = mask.querySelector('.op');
        dl.href = p.url; dl.setAttribute('download', p.name || 'photo');
        op.href = p.url;
        Array.prototype.forEach.call(film.children, function (t, k) { t.classList.toggle('on', k === st.i); });
        var on = film.children[st.i];
        if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest', inline: 'center' });
        apply();
    }

    // 底部縮圖膠捲：張數多時才有意義，但一律顯示比較好預期（一張時也只是一格）
    var fh = '';
    photos.forEach(function (p, k) { fh += '<img data-k="' + k + '" src="' + esc(thumbUrl(p.url, 160)) + '" alt="">'; });
    film.innerHTML = fh;
    film.addEventListener('click', function (e) {
        var t = e.target.closest('img');
        if (t) show(parseInt(t.dataset.k, 10) || 0);
    });

    mask.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]');
        if (b) {
            var a = b.dataset.act;
            if (a === 'close') close();
            else if (a === 'prev') show(st.i - 1);
            else if (a === 'next') show(st.i + 1);
            else if (a === 'zi') { st.scale = Math.min(20, st.scale * 1.25); apply(); }
            else if (a === 'zo') { st.scale = Math.max(0.1, st.scale / 1.25); if (st.scale < 1.05) { st.tx = st.ty = 0; } apply(); }
            else if (a === 'fit') { st.scale = 1; st.tx = st.ty = 0; apply(); }
            return;
        }
        // 點背景（不是圖片、不是工具列）＝關閉
        if (e.target === mask || e.target.classList.contains('eg-lb-stage')) close();
    });

    mask.querySelector('.eg-lb-stage').addEventListener('wheel', function (e) {
        e.preventDefault();
        st.scale = Math.min(20, Math.max(0.1, st.scale * (e.deltaY < 0 ? 1.12 : 1 / 1.12)));
        if (st.scale < 1.05) { st.tx = st.ty = 0; }
        apply();
    }, { passive: false });

    img.addEventListener('mousedown', function (e) {
        if (st.scale <= 1.05) return;
        e.preventDefault();
        st.drag = true; st.sx = e.clientX; st.sy = e.clientY; st.stx = st.tx; st.sty = st.ty; apply();
    });
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
    function onMove(e) { if (!st.drag) return; st.tx = st.stx + (e.clientX - st.sx); st.ty = st.sty + (e.clientY - st.sy); apply(); }
    function onUp() { if (st.drag) { st.drag = false; apply(); } }
    st._off = function () { document.removeEventListener('mousemove', onMove); document.removeEventListener('mouseup', onUp); };

    document.addEventListener('keydown', onKey);
    st._offKey = function () { document.removeEventListener('keydown', onKey); };
    function onKey(e) {
        if (!_lb) return;
        if (e.key === 'Escape')      { close(); }
        else if (e.key === 'ArrowLeft')  { show(st.i - 1); }
        else if (e.key === 'ArrowRight') { show(st.i + 1); }
    }

    show(idx);
}

function close() {
    if (!_lb) return;
    try { _lb._off && _lb._off(); _lb._offKey && _lb._offKey(); } catch (e) {}
    if (_lb.mask && _lb.mask.parentNode) _lb.mask.parentNode.removeChild(_lb.mask);
    _lb = null;
}

global.EGAlbum = {
    grid: grid,
    viewer: viewer,
    close: close,
    thumb: thumbUrl,
    selectedIds: selectedIds,
    selectAll: selectAll,
    isImage: isImageName
};

})(window);
