/**
 * eg_richtext.js ── 全站共用「富文字備註」編輯器（唯一實作，禁止各頁自刻）
 *
 * 讓備註/說明欄位能像 Word 一樣做部份粗體、斜體、底線、刪除線、換文字色、換底色、條列。
 * 配色只給 ai-rules/10 的固定暖色系調色盤，不給任意取色器——否則各頁備註顏色會走鐘。
 *
 * 用法：
 *   <div id="myRemark"></div>
 *   EGRichText.attach('#myRemark');              // 建立工具列＋編輯區
 *   EGRichText.set('#myRemark', htmlFromServer); // 帶入現有內容
 *   var html = EGRichText.get('#myRemark');      // 取出（已前端清洗）送後端
 *   $('#cell').html(EGRichText.render(html));    // 顯示（清洗後才輸出）
 *
 * 鐵律8：前端清洗只是體感，**後端存檔前一定要再跑 eg_richtext_sanitize()**
 * （src/common/richtext_lib.php），否則略過前端直打 API 就能塞 <script>。
 */
(function (w, d) {
  'use strict';
  if (w.EGRichText) return;

  // ── 調色盤（ai-rules/10 暖色系，禁止冷暖混雜；同語意跨頁一致）──────────
  var TEXT_COLORS = [
    ['#333333', '黑'],      ['#4E2C0B', '深棕'],   ['#7A4A34', '深可可'],
    ['#8A5A2B', '暖棕'],    ['#B06F27', '赭棕'],   ['#D6851F', '琥珀橘'],
    ['#A34E2A', '磚紅'],    ['#DD5138', '珊瑚紅'], ['#a08a6f', '暖灰棕']
  ];
  var BG_COLORS = [
    ['transparent', '無底色'], ['#faf6f0', '暖白'],   ['#F7E0BD', '淺砂琥珀'],
    ['#EBD3A8', '砂'],         ['#E8C07A', '淺琥珀'], ['#F0A24B', '琥珀橘'],
    ['#F8DCD5', '珊瑚淺'],     ['#F0D7C8', '陶土淺'], ['#E9B8AC', '珊瑚中']
  ];

  var TAGS = ['B','STRONG','I','EM','U','S','STRIKE','DEL','BR','UL','OL','LI','SPAN','DIV','P'];
  // 允許保留的 CSS 宣告（與後端 richtext_lib.php 的 EG_RT_STYLES 必須一致）。
  // font-weight / text-decoration 一定要留：貼 Word 內容時粗體底線就是走這兩個屬性。
  var STYLES = {
    'color':                /^(#[0-9A-Fa-f]{3,8}|rgba?\(\s*[\d.,%\s]+\)|[a-zA-Z]{3,20})$/,
    'background-color':     /^(#[0-9A-Fa-f]{3,8}|rgba?\(\s*[\d.,%\s]+\)|[a-zA-Z]{3,20})$/,
    'font-weight':          /^(bold|bolder|normal|lighter|[1-9]00)$/i,
    'font-style':           /^(italic|oblique|normal)$/i,
    'text-decoration':      /^[a-zA-Z\- ]{1,40}$/,
    'text-decoration-line': /^[a-zA-Z\- ]{1,40}$/
  };
  // 這些指令要產生 <b>/<i>/<u>/<strike> 標籤，不要走 CSS——
  // styleWithCSS=true 時它們會變成 <span style="font-weight:bold">，樣式一被清就整個失效。
  var TAG_CMDS = ['bold', 'italic', 'underline', 'strikeThrough'];
  var NBSP   = String.fromCharCode(160);

  function el(sel) { return typeof sel === 'string' ? d.querySelector(sel) : (sel && sel.jquery ? sel[0] : sel); }
  function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

  // ── 清洗（與後端 richtext_lib.php 同一套白名單）────────────────────────
  function cleanStyle(style) {
    if (!style) return '';
    var keep = [];
    style.split(';').forEach(function (decl) {
      var i = decl.indexOf(':'); if (i < 0) return;
      var p = decl.slice(0, i).trim().toLowerCase(), v = decl.slice(i + 1).trim();
      if (!STYLES.hasOwnProperty(p) || !STYLES[p].test(v)) return;
      keep.push(p + ':' + v);
    });
    return keep.join(';');
  }

  function cleanNode(node) {
    var kids = Array.prototype.slice.call(node.childNodes);   // 先快照：清洗過程會改動 childNodes
    kids.forEach(function (c) {
      if (c.nodeType === 3) return;                                    // 文字節點
      if (c.nodeType !== 1) { c.parentNode.removeChild(c); return; }   // 註解等
      var tag = c.nodeName.toUpperCase();
      // script/style 連同內容整個移除（脫殼會把 JS 原始碼變成可見文字）
      if (tag === 'SCRIPT' || tag === 'STYLE') { c.parentNode.removeChild(c); return; }
      cleanNode(c);                                            // 先清子層再決定自己去留
      if (TAGS.indexOf(tag) < 0) {                             // 脫殼：只留文字與合法格式
        while (c.firstChild) c.parentNode.insertBefore(c.firstChild, c);
        c.parentNode.removeChild(c);
        return;
      }
      var style = cleanStyle(c.getAttribute('style'));
      Array.prototype.slice.call(c.attributes).forEach(function (a) { c.removeAttribute(a.name); });
      if (style) c.setAttribute('style', style);
    });
  }

  /** 清洗 HTML 字串，回傳可安全塞進頁面的 HTML */
  function clean(html) {
    if (!html) return '';
    var box = d.createElement('div');
    box.innerHTML = String(html);
    cleanNode(box);
    var out = box.innerHTML.trim();
    return toText(out) === '' ? '' : out;   // 只剩 <br> / 空 div 視同沒填
  }

  /** 轉純文字（比對「有沒有填東西」、tooltip、匯出用） */
  function toText(html) {
    if (!html) return '';
    var box = d.createElement('div');
    box.innerHTML = String(html).replace(/<(br|\/li|\/div|\/p)\s*\/?>/gi, '\n');
    return (box.textContent || '').split(NBSP).join(' ').trim();
  }

  // ── 工具列 ─────────────────────────────────────────────────────────────
  function swatches(kind, list) {
    return '<div class="egrt-pop egrt-pop-' + kind + '">' + list.map(function (c) {
      var isNone = c[0] === 'transparent';
      return '<a href="javascript:void(0)" class="egrt-sw" data-kind="' + kind + '" data-color="' + c[0] + '"'
           + ' title="' + escAttr(c[1]) + '" style="background:' + (isNone ? '#fff' : c[0]) + ';'
           + (isNone ? 'color:#c00;font-size:12px;line-height:17px;text-align:center;' : '') + '">'
           + (isNone ? '✕' : '') + '</a>';
    }).join('') + '</div>';
  }

  function toolbarHtml() {
    var btn = function (cmd, icon, title) {
      return '<button type="button" class="egrt-btn" data-cmd="' + cmd + '" title="' + escAttr(title) + '"><i class="fa fa-' + icon + '"></i></button>';
    };
    return '<div class="egrt-bar">'
      + btn('bold', 'bold', '粗體 (Ctrl+B)')
      + btn('italic', 'italic', '斜體 (Ctrl+I)')
      + btn('underline', 'underline', '底線 (Ctrl+U)')
      + btn('strikeThrough', 'strikethrough', '刪除線')
      + '<span class="egrt-sep"></span>'
      + '<span class="egrt-drop"><button type="button" class="egrt-btn egrt-tgl" data-pop="fore" title="文字顏色">'
      + '<i class="fa fa-font"></i><span class="egrt-ul" style="background:#DD5138;"></span></button>' + swatches('fore', TEXT_COLORS) + '</span>'
      + '<span class="egrt-drop"><button type="button" class="egrt-btn egrt-tgl" data-pop="back" title="背景底色">'
      + '<i class="fa fa-paint-brush"></i><span class="egrt-ul" style="background:#F0A24B;"></span></button>' + swatches('back', BG_COLORS) + '</span>'
      + '<span class="egrt-sep"></span>'
      + btn('insertUnorderedList', 'list-ul', '項目符號清單')
      + btn('insertOrderedList', 'list-ol', '編號清單')
      + '</div>';
  }

  function injectCss() {
    if (d.getElementById('egrt-css')) return;
    var s = d.createElement('style');
    s.id = 'egrt-css';
    s.textContent = [
      '.egrt-wrap{border:1px solid #d8c7b0;border-radius:4px;background:#fff;}',
      '.egrt-bar{background:#faf6f0;border-bottom:1px solid #efe7db;padding:3px 4px;border-radius:4px 4px 0 0;}',
      '.egrt-btn{border:1px solid transparent;background:transparent;color:#6B471A;width:28px;height:26px;',
      'line-height:1;border-radius:3px;padding:0;margin:0 1px;vertical-align:middle;position:relative;}',
      '.egrt-btn:hover{background:#f2e6d4;border-color:#e4d3ba;}',
      '.egrt-btn.on{background:#e8d5b8;border-color:#d8c7b0;}',
      '.egrt-ul{position:absolute;left:5px;right:5px;bottom:3px;height:3px;border-radius:1px;}',
      '.egrt-sep{display:inline-block;width:1px;height:18px;background:#e4d3ba;margin:0 4px;vertical-align:middle;}',
      '.egrt-drop{position:relative;display:inline-block;}',
      '.egrt-pop{display:none;position:absolute;top:100%;left:0;z-index:2200;background:#fff;border:1px solid #d8c7b0;',
      'border-radius:4px;box-shadow:0 3px 10px rgba(0,0,0,.18);padding:5px;width:92px;}',
      '.egrt-pop.open{display:block;}',
      '.egrt-sw{display:inline-block;width:18px;height:18px;margin:2px;border:1px solid #cfc0a8;border-radius:3px;text-decoration:none;}',
      '.egrt-sw:hover{outline:2px solid #8a5a2b;}',
      '.egrt-body{min-height:70px;max-height:260px;overflow:auto;padding:7px 9px;font-size:13px;line-height:1.7;outline:none;}',
      '.egrt-body:empty:before{content:attr(data-ph);color:#a08a6f;}',
      '.egrt-body ul,.egrt-body ol{margin:0 0 0 4px;padding-left:20px;}',
      '.egrt-body p{margin:0 0 4px;}',
      '.egrt-count{font-size:11px;color:#a08a6f;text-align:right;padding:0 8px 4px;}',
      '.egrt-count.over{color:#DD5138;font-weight:bold;}',
      '.egrt-view ul,.egrt-view ol{margin:0;padding-left:18px;}',
      '.egrt-view p{margin:0;}'
    ].join('');
    d.head.appendChild(s);
  }

  // ── 建立編輯器 ─────────────────────────────────────────────────────────
  function attach(target, opt) {
    var host = el(target);
    if (!host) return null;
    if (host._egrt) return host._egrt;
    opt = opt || {};
    injectCss();

    host.classList.add('egrt-wrap');
    host.innerHTML = toolbarHtml()
      + '<div class="egrt-body" contenteditable="true" data-eg-skip data-ph="' + escAttr(opt.placeholder || '') + '"></div>'
      + (opt.maxLen ? '<div class="egrt-count"></div>' : '');

    var body = host.querySelector('.egrt-body');
    var count = host.querySelector('.egrt-count');
    var maxLen = opt.maxLen || 0;

    // 換色指令要 styleWithCSS=true（產生 <span style="color:…"> 而不是已淘汰的 <font>）；
    // 粗體/斜體/底線/刪除線要 styleWithCSS=false，才會產生 <b>/<i>/<u>/<strike> 標籤。
    function useCss(cmd) {
      try { d.execCommand('styleWithCSS', false, TAG_CMDS.indexOf(cmd) < 0); } catch (e) {}
    }

    function refreshCount() {
      if (!count) return;
      var n = toText(body.innerHTML).length;
      count.textContent = n + ' / ' + maxLen + ' 字';
      count.classList.toggle('over', n > maxLen);
    }
    function refreshState() {
      ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].forEach(function (c) {
        var b = host.querySelector('.egrt-btn[data-cmd="' + c + '"]');
        if (!b) return;
        var on = false;
        try { on = d.queryCommandState(c); } catch (e) {}
        b.classList.toggle('on', !!on);
      });
    }
    function closePops() {
      Array.prototype.slice.call(host.querySelectorAll('.egrt-pop')).forEach(function (p) { p.classList.remove('open'); });
    }
    function exec(cmd, val) {
      body.focus();
      useCss(cmd);
      try { d.execCommand(cmd, false, val === undefined ? null : val); } catch (e) {}
      refreshState(); refreshCount();
      if (opt.onChange) opt.onChange();
    }

    host.addEventListener('mousedown', function (e) {
      // 工具列一律不搶焦點，否則選取範圍會消失、格式就套不到剛選的字
      if (e.target.closest && e.target.closest('.egrt-bar')) e.preventDefault();
    });
    host.addEventListener('click', function (e) {
      var t = e.target;
      if (!t.closest) return;
      var sw = t.closest('.egrt-sw');
      if (sw) {
        var color = sw.getAttribute('data-color');
        if (sw.getAttribute('data-kind') === 'fore') exec('foreColor', color === 'transparent' ? '#333333' : color);
        else exec('hiliteColor', color);
        closePops();
        return;
      }
      var tgl = t.closest('.egrt-tgl');
      if (tgl) {
        var pop = host.querySelector('.egrt-pop-' + tgl.getAttribute('data-pop'));
        var wasOpen = pop.classList.contains('open');
        closePops();
        if (!wasOpen) pop.classList.add('open');
        return;
      }
      var btn = t.closest('.egrt-btn[data-cmd]');
      if (btn) { closePops(); exec(btn.getAttribute('data-cmd')); }
    });
    d.addEventListener('click', function (e) {
      if (!host.contains(e.target)) closePops();
    });

    // 貼上：一律先清洗再插入，避免把 Word/網頁的整片樣式、<script>、外部圖片帶進來
    body.addEventListener('paste', function (e) {
      e.preventDefault();
      var dt = e.clipboardData || w.clipboardData;
      if (!dt) return;
      var html = dt.getData('text/html');
      var ins = html ? clean(html) : (dt.getData('text/plain') || '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\r?\n/g, '<br>');
      try { d.execCommand('insertHTML', false, ins); } catch (err) {}
      refreshCount();
      if (opt.onChange) opt.onChange();
    });
    body.addEventListener('input', function () { refreshCount(); if (opt.onChange) opt.onChange(); });
    body.addEventListener('keyup', refreshState);
    body.addEventListener('mouseup', refreshState);

    var api = {
      host: host, body: body,
      get: function () { return clean(body.innerHTML); },
      set: function (html) { body.innerHTML = clean(html); refreshCount(); refreshState(); },
      text: function () { return toText(body.innerHTML); },
      focus: function () { body.focus(); },
      over: function () { return maxLen > 0 && toText(body.innerHTML).length > maxLen; },
      maxLen: maxLen
    };
    host._egrt = api;
    refreshCount();
    return api;
  }

  function of(target) { var h = el(target); return h && h._egrt ? h._egrt : null; }

  w.EGRichText = {
    attach: attach,
    of: of,
    get: function (t) { var a = of(t); return a ? a.get() : ''; },
    set: function (t, html) { var a = of(t) || attach(t); if (a) a.set(html); return a; },
    clean: clean,
    /** 顯示用：清洗後回傳 HTML 字串（呼叫端直接塞進 innerHTML） */
    render: function (html) { return clean(html); },
    toText: toText,
    TEXT_COLORS: TEXT_COLORS,
    BG_COLORS: BG_COLORS
  };
})(window, document);
