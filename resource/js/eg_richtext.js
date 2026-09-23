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
  // doc profile（整份文件用，如二階程序書）額外放行的標籤／屬性。
  // 與後端 richtext_lib.php 的 EG_RT_DOC_TAGS / EG_RT_DOC_ATTRS 必須一致。
  var DOC_TAGS = TAGS.concat([
    'H1','H2','H3','H4','H5','H6',
    'TABLE','THEAD','TBODY','TFOOT','TR','TD','TH','COLGROUP','COL',
    'IMG','HR','SUB','SUP'
  ]);
  var DOC_ATTRS = { 'TD': ['colspan','rowspan'], 'TH': ['colspan','rowspan'],
                    'IMG': ['data-asset'], 'COL': ['span'] };
  var DOC_ATTR_PAT = { 'colspan': /^([1-9]|[1-9]\d)$/, 'rowspan': /^([1-9]|[1-9]\d)$/,
                       'span': /^([1-9]|[1-9]\d)$/, 'data-asset': /^[1-9]\d{0,9}$/ };
  // 可選字型：值是「字型堆疊」——中文字型一定要有英文後援，不然換一台電腦就印不出來
  var DOC_FONTS = [
    ['', '（預設）'],
    ['"標楷體","DFKai-SB","BiauKai",serif', '標楷體'],
    ['"微軟正黑體","Microsoft JhengHei",sans-serif', '微軟正黑體'],
    ['"新細明體","PMingLiU",serif', '新細明體'],
    ['Arial,Helvetica,sans-serif', 'Arial'],
    ['"Times New Roman",Times,serif', 'Times New Roman'],
    ['Consolas,"Courier New",monospace', 'Consolas']
  ];
  var DOC_SIZES = ['9pt','10pt','11pt','12pt','14pt','16pt','18pt','20pt','24pt'];
  var DOC_BLOCKS = [['P','正文'],['H1','標題 1'],['H2','標題 2'],['H3','標題 3'],['H4','標題 4']];
  // 允許保留的 CSS 宣告（與後端 richtext_lib.php 的 EG_RT_STYLES 必須一致）。
  // font-weight / text-decoration 一定要留：貼 Word 內容時粗體底線就是走這兩個屬性。
  var STYLES = {
    'color':                /^(#[0-9A-Fa-f]{3,8}|rgba?\(\s*[\d.,%\s]+\)|[a-zA-Z]{3,20})$/,
    'background-color':     /^(#[0-9A-Fa-f]{3,8}|rgba?\(\s*[\d.,%\s]+\)|[a-zA-Z]{3,20})$/,
    'font-weight':          /^(bold|bolder|normal|lighter|[1-9]00)$/i,
    'font-style':           /^(italic|oblique|normal)$/i,
    'text-decoration':      /^[a-zA-Z\- ]{1,40}$/,
    'text-decoration-line': /^[a-zA-Z\- ]{1,40}$/,
    // 縮排：只收 em 單位、最多 20em，寫在區塊元素上（見 indentBlocks）
    'margin-left':          /^(\d{1,2}(\.\d)?)em$/
  };
  // doc profile 額外放行的宣告（與後端 EG_RT_DOC_STYLES 一致）
  var DOC_STYLES = {
    'font-family':       /^[一-鿿\w\s,'"\-]{1,120}$/,
    'font-size':         /^([1-9]\d?(\.\d)?)(pt|px)$/,
    'text-align':        /^(left|right|center|justify)$/i,
    'text-indent':       /^(-?\d{1,2}(\.\d)?)(em|pt)$/,
    'line-height':       /^(\d(\.\d{1,2})?|1[0-9]{1,2}%|[1-9]\d?0%)$/,
    // cm/mm 要放行：Word 匯入進來的版面單位就是 cm（流程圖方框是 width:3.55cm）
    'width':             /^(\d{1,3}(\.\d{1,2})?)(px|%|em|cm|mm)$/,
    'height':            /^(\d{1,4}(\.\d{1,2})?)(px|em|cm|mm)$/,
    'padding':           /^(\d{1,2}(\.\d{1,2})?(px|em|cm|mm)\s*){1,4}$/,
    'vertical-align':    /^(top|middle|bottom|baseline)$/i,
    'border':            /^(\d{1,2}px\s+(solid|dashed|dotted|none)\s+(#[0-9A-Fa-f]{3,8}|[a-zA-Z]{3,20})|none|0)$/i,
    'border-collapse':   /^(collapse|separate)$/i,
    'border-width':      /^(\d{1,2}px\s*){1,4}$/,
    'border-style':      /^((solid|dashed|dotted|none)\s*){1,4}$/i,
    'border-color':      /^((#[0-9A-Fa-f]{3,8}|[a-zA-Z]{3,20})\s*){1,4}$/,
    'float':             /^(left|right|none)$/i,
    'margin':            /^(\d{1,2}(\.\d{1,2})?(px|em|cm|mm)\s*){1,4}$/,
    'margin-right':      /^(\d{1,2}(\.\d{1,2})?)(em|px|cm|mm)$/,
    'page-break-before': /^(always|auto|avoid)$/i,
    'page-break-after':  /^(always|auto|avoid)$/i,
    'page-break-inside': /^(avoid|auto)$/i
  };

  /** 取得清洗設定；'doc'＝整份文件，其餘一律 basic（＝改版前的行為） */
  function profileCfg(name) {
    if (name !== 'doc') return { tags: TAGS, styles: STYLES, attrs: {}, attrPat: {} };
    var st = {};
    Object.keys(STYLES).forEach(function (k) { st[k] = STYLES[k]; });
    Object.keys(DOC_STYLES).forEach(function (k) { st[k] = DOC_STYLES[k]; });
    return { tags: DOC_TAGS, styles: st, attrs: DOC_ATTRS, attrPat: DOC_ATTR_PAT };
  }
  var INDENT_STEP = 2;    // 每按一次縮排 2em
  var INDENT_MAX  = 20;   // 上限（與 STYLES['margin-left'] 的 2 位數上限一致）
  // 這些指令要產生 <b>/<i>/<u>/<strike> 標籤，不要走 CSS——
  // styleWithCSS=true 時它們會變成 <span style="font-weight:bold">，樣式一被清就整個失效。
  var TAG_CMDS = ['bold', 'italic', 'underline', 'strikeThrough'];
  var NBSP   = String.fromCharCode(160);

  function el(sel) { return typeof sel === 'string' ? d.querySelector(sel) : (sel && sel.jquery ? sel[0] : sel); }
  function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

  // ── 清洗（與後端 richtext_lib.php 同一套白名單）────────────────────────
  function cleanStyle(style, styles) {
    if (!style) return '';
    styles = styles || STYLES;
    var keep = [];
    style.split(';').forEach(function (decl) {
      var i = decl.indexOf(':'); if (i < 0) return;
      var p = decl.slice(0, i).trim().toLowerCase(), v = decl.slice(i + 1).trim();
      // 全域封鎖（與後端 eg_rt_clean_style 同一道）：任何屬性都不准出現 url()/expression()/偽協定
      if (/url\s*\(|expression\s*\(|javascript\s*:|data\s*:/i.test(v)) return;
      if (!styles.hasOwnProperty(p) || !styles[p].test(v)) return;
      keep.push(p + ':' + v);
    });
    return keep.join(';');
  }

  function cleanNode(node, cfg) {
    cfg = cfg || profileCfg('basic');
    var kids = Array.prototype.slice.call(node.childNodes);   // 先快照：清洗過程會改動 childNodes
    kids.forEach(function (c) {
      if (c.nodeType === 3) return;                                    // 文字節點
      if (c.nodeType !== 1) { c.parentNode.removeChild(c); return; }   // 註解等
      var tag = c.nodeName.toUpperCase();
      // script/style 連同內容整個移除（脫殼會把 JS 原始碼變成可見文字）
      if (tag === 'SCRIPT' || tag === 'STYLE') { c.parentNode.removeChild(c); return; }
      cleanNode(c, cfg);                                       // 先清子層再決定自己去留
      // <img> 沒有合法資產編號＝來路不明（貼上帶進來的外部圖、base64），整個移除
      if (tag === 'IMG') {
        var aid = c.getAttribute('data-asset') || '';
        if (!cfg.attrs['IMG'] || !DOC_ATTR_PAT['data-asset'].test(aid)) {
          c.parentNode.removeChild(c); return;
        }
      }
      if (cfg.tags.indexOf(tag) < 0) {                          // 脫殼：只留文字與合法格式
        while (c.firstChild) c.parentNode.insertBefore(c.firstChild, c);
        c.parentNode.removeChild(c);
        return;
      }
      // 沒有 <ul>/<ol> 當父層的孤兒 <li> 改成 <div>：
      // 貼上 Word／網頁內容時很容易只帶進 <li> 而沒有清單容器，瀏覽器仍會把它算成
      // display:list-item ＝ 每一行前面莫名其妙冒出一個「•」（使用者 2026-09-07 回報）。
      // 換成 <div> 可以保住原本的分行，只是不再有項目符號。
      if (tag === 'LI') {
        var pt = c.parentNode && c.parentNode.nodeName ? c.parentNode.nodeName.toUpperCase() : '';
        if (pt !== 'UL' && pt !== 'OL') {
          var dv = d.createElement('div');
          if (c.getAttribute('style')) dv.setAttribute('style', c.getAttribute('style'));
          while (c.firstChild) dv.appendChild(c.firstChild);
          c.parentNode.replaceChild(dv, c);
          c = dv;
        }
      }
      var style = cleanStyle(c.getAttribute('style'), cfg.styles);
      var keepAttr = [];
      (cfg.attrs[tag] || []).forEach(function (an) {
        var av = c.getAttribute(an);
        if (!av) return;
        var pat = cfg.attrPat[an];
        if (pat && !pat.test(av)) return;
        keepAttr.push([an, av]);
      });
      Array.prototype.slice.call(c.attributes).forEach(function (a) { c.removeAttribute(a.name); });
      if (style) c.setAttribute('style', style);
      keepAttr.forEach(function (kv) { c.setAttribute(kv[0], kv[1]); });
    });
  }

  /** 清洗 HTML 字串，回傳可安全塞進頁面的 HTML
   *  @param {string} [profile] 'doc'＝整份文件；省略＝basic（＝改版前的行為） */
  function clean(html, profile) {
    if (!html) return '';
    var cfg = profileCfg(profile);
    var box = d.createElement('div');
    box.innerHTML = String(html);
    cleanNode(box, cfg);
    var out = box.innerHTML.trim();
    // 圖片與表格本身就是內容（一張流程圖、一張權責分工表都可能一個字都沒有）
    if (toText(out) === '' && !/<img/i.test(out) && !/<table/i.test(out)) return '';
    return out;
  }

  /** 轉純文字（比對「有沒有填東西」、tooltip、匯出用） */
  function toText(html) {
    if (!html) return '';
    var box = d.createElement('div');
    // 表格與標題也要斷欄斷行，否則整張表擠成一長串字（doc profile 用得到）
    box.innerHTML = String(html)
      .replace(/<\/t[dh]>/gi, '\t')
      .replace(/<(br|\/li|\/div|\/p|\/tr|\/table|\/h[1-6]|hr)\s*\/?>/gi, '\n');
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
      + btn('egOutdent', 'outdent', '減少縮排')
      + btn('egIndent', 'indent', '增加縮排')
      + '<span class="egrt-sep"></span>'
      + btn('insertUnorderedList', 'list-ul', '項目符號清單（會顯示「•」）')
      + btn('insertOrderedList', 'list-ol', '編號清單')
      + '</div>';
  }

  /* ── doc profile 工具列 ─────────────────────────────────────────────────
     二階程序書這種「整份文件」用的完整工具列。basic（備註欄）不會走到這裡。
     圖片上傳／裁切／流程圖編輯刻意做成**回呼**（opt.onInsertImage 等）：
     那三件事要打各模組自己的 API、權限也各自不同，寫死在共用元件裡就綁死了。 */
  function docToolbarHtml(opt) {
    var btn = function (cmd, icon, title) {
      return '<button type="button" class="egrt-btn" data-cmd="' + cmd + '" title="' + escAttr(title) + '"><i class="fa fa-' + icon + '"></i></button>';
    };
    var opts = function (list, cur) {
      return list.map(function (o) {
        var v = (o instanceof Array) ? o[0] : o, t = (o instanceof Array) ? o[1] : o;
        return '<option value="' + escAttr(v) + '"' + (v === cur ? ' selected' : '') + '>' + escAttr(t) + '</option>';
      }).join('');
    };
    var h = '<div class="egrt-bar egrt-bar-doc">';
    // 第一排：區塊階層／字型／字級／字形／顏色
    h += '<select class="egrt-sel egrt-sel-block" data-eg-skip title="段落階層">' + opts(DOC_BLOCKS, 'P') + '</select>';
    h += '<select class="egrt-sel egrt-sel-font" data-eg-skip title="字型">' + opts(DOC_FONTS, '') + '</select>';
    h += btn('egFontDown', 'minus', '字級調小（選取的字會保持選取，可以連按）')
       + '<select class="egrt-sel egrt-sel-size" data-eg-skip title="字級">'
       + '<option value="">字級</option>' + opts(DOC_SIZES, '') + '</select>'
       + btn('egFontUp', 'plus', '字級調大（選取的字會保持選取，可以連按）');
    h += '<span class="egrt-sep"></span><span class="egrt-lab">行距</span>'
       + btn('egLhDown', 'minus', '行距調小')
       + '<span class="egrt-read egrt-lh-read" title="目前行距">1.6</span>'
       + btn('egLhUp', 'plus', '行距調大');
    h += '<span class="egrt-sep"></span>'
       + btn('bold', 'bold', '粗體 (Ctrl+B)') + btn('italic', 'italic', '斜體 (Ctrl+I)')
       + btn('underline', 'underline', '底線 (Ctrl+U)') + btn('strikeThrough', 'strikethrough', '刪除線');
    h += '<span class="egrt-drop"><button type="button" class="egrt-btn egrt-tgl" data-pop="fore" title="文字顏色">'
       + '<i class="fa fa-font"></i><span class="egrt-ul" style="background:#DD5138;"></span></button>' + swatches('fore', TEXT_COLORS) + '</span>'
       + '<span class="egrt-drop"><button type="button" class="egrt-btn egrt-tgl" data-pop="back" title="背景底色">'
       + '<i class="fa fa-paint-brush"></i><span class="egrt-ul" style="background:#F0A24B;"></span></button>' + swatches('back', BG_COLORS) + '</span>'
       + btn('removeFormat', 'eraser', '清除格式');
    h += '<span class="egrt-br"></span>';
    // 第二排：對齊／縮排／清單／表格／插入
    h += btn('justifyLeft', 'align-left', '靠左') + btn('justifyCenter', 'align-center', '置中')
       + btn('justifyRight', 'align-right', '靠右') + btn('justifyFull', 'align-justify', '兩端對齊')
       + '<span class="egrt-sep"></span>'
       + btn('egOutdent', 'outdent', '減少縮排') + btn('egIndent', 'indent', '增加縮排')
       + '<span class="egrt-sep"></span>'
       // 上下對齊（作用在游標所在的表格儲存格；整張表選起來就整張套用）
       + btn('egVaTop', 'angle-up', '儲存格靠上')
       + btn('egVaMid', 'minus', '儲存格上下置中')
       + btn('egVaBot', 'angle-down', '儲存格靠下')
       + '<span class="egrt-sep"></span>'
       + btn('insertUnorderedList', 'list-ul', '項目符號清單') + btn('insertOrderedList', 'list-ol', '編號清單')
       + '<span class="egrt-sep"></span>';
    // 插入表格（小面板選列欄數）
    h += '<span class="egrt-drop"><button type="button" class="egrt-btn egrt-tgl" data-pop="tbl" title="插入表格"><i class="fa fa-table"></i></button>'
       + '<div class="egrt-pop egrt-pop-tbl egrt-pop-wide">'
       + '<div class="egrt-pf"><label>列數</label><input type="number" class="egrt-ti egrt-tr-n" value="3" min="1" max="50" data-eg-skip></div>'
       + '<div class="egrt-pf"><label>欄數</label><input type="number" class="egrt-ti egrt-tc-n" value="3" min="1" max="12" data-eg-skip></div>'
       + '<div class="egrt-pf"><label><input type="checkbox" class="egrt-th-chk" checked data-eg-skip> 第一列為表頭</label></div>'
       + '<div style="text-align:right"><button type="button" class="btn btn-xs btn-warning egrt-tbl-ins">插入</button></div>'
       + '</div></span>';
    h += btn('egRowAdd', 'plus-square-o', '在下方插入一列（游標要在表格內）')
       + btn('egRowDel', 'minus-square-o', '刪除這一列')
       + btn('egColAdd', 'columns', '在右方插入一欄')
       + btn('egColDel', 'trash-o', '刪除這一欄')
       + '<span class="egrt-sep"></span>';
    if (opt.onInsertImage) h += btn('egImage', 'picture-o', '插入圖片');
    if (opt.onInsertFlow)  h += btn('egFlow', 'sitemap', '插入流程圖');
    h += btn('egHr', 'minus', '水平線');
    h += '<span class="egrt-sep"></span>'
       + btn('egPageBreak', 'scissors', '在游標處分頁（游標後面的內容移到新的一頁）')
       + btn('egAddPage', 'file-o', '在最後面新增一頁')
       + btn('egAutoPage', 'magic', '自動分頁：把超出每一頁的內容往後推，直到每頁都放得下')
       + '<span class="egrt-sep"></span>'
       + '<button type="button" class="egrt-btn on" data-view="single" title="一頁一頁顯示"><i class="fa fa-file-text-o"></i></button>'
       + '<button type="button" class="egrt-btn" data-view="double" title="兩頁並排顯示"><i class="fa fa-columns"></i> 雙頁</button>';
    h += '</div>';
    return h;
  }

  /** 找出游標所在的儲存格 */
  function cellOf(body) {
    var sel = w.getSelection();
    if (!sel || !sel.rangeCount || !body.contains(sel.anchorNode)) return null;
    var n = sel.anchorNode;
    if (n.nodeType === 3) n = n.parentNode;
    while (n && n !== body) {
      if (n.nodeType === 1 && (n.nodeName === 'TD' || n.nodeName === 'TH')) return n;
      n = n.parentNode;
    }
    return null;
  }

  var CELL_CSS = 'border:1px solid #333333;padding:4px';

  function newCell(tag, txt) {
    var c = d.createElement(tag);
    c.setAttribute('style', CELL_CSS + (tag === 'th' ? ';text-align:center' : ''));
    c.innerHTML = txt || '<br>';
    return c;
  }

  function tableHtml(rows, cols, withHead) {
    var h = '<table style="border-collapse:collapse;width:100%">';
    for (var r = 0; r < rows; r++) {
      h += '<tr>';
      for (var c = 0; c < cols; c++) {
        var isH = withHead && r === 0;
        h += '<' + (isH ? 'th' : 'td') + ' style="' + CELL_CSS + (isH ? ';text-align:center' : '') + '"><br></' + (isH ? 'th' : 'td') + '>';
      }
      h += '</tr>';
    }
    return h + '</table><p><br></p>';
  }

  /** 表格加減列欄。做到了就回傳「游標該落在哪一格」，沒做到回傳 false
   *  （刪掉的那一格本來就是游標所在的格子，不指定新位置的話選取會整個消失，
   *    畫面就會跳回文件開頭＝使用者 2026-09-23 回報的「焦點跳動」） */
  function tableOp(body, op) {
    var cell = cellOf(body);
    if (!cell) return false;
    var row = cell.parentNode, tbl = row;
    while (tbl && tbl.nodeName !== 'TABLE') tbl = tbl.parentNode;
    if (!tbl) return false;
    var idx = Array.prototype.indexOf.call(row.children, cell);

    var keep = cell;                       // 做完之後游標要落在哪一格
    if (op === 'egRowAdd') {
      var nr = d.createElement('tr');
      for (var i = 0; i < row.children.length; i++) nr.appendChild(newCell('td'));
      row.parentNode.insertBefore(nr, row.nextSibling);
      keep = nr.children[idx] || nr.children[0];     // 游標移到新加的那一列同一欄
    } else if (op === 'egRowDel') {
      // 只剩一列就不給刪整列（整張表都不見了使用者會以為系統壞掉），請他刪整張表
      if (tbl.rows && tbl.rows.length <= 1) return false;
      var rows = Array.prototype.slice.call(tbl.rows), ri = rows.indexOf(row);
      var nextRow = rows[ri + 1] || rows[ri - 1];    // 刪完接手的那一列：優先下面那列
      keep = nextRow ? (nextRow.children[idx] || nextRow.children[0]) : null;
      row.parentNode.removeChild(row);
    } else if (op === 'egColAdd') {
      Array.prototype.slice.call(tbl.rows).forEach(function (r) {
        var ref = r.children[idx];
        var nc = newCell(ref && ref.nodeName === 'TH' ? 'th' : 'td');
        r.insertBefore(nc, ref ? ref.nextSibling : null);
      });
      keep = row.children[idx + 1] || cell;
    } else if (op === 'egColDel') {
      if (row.children.length <= 1) return false;
      Array.prototype.slice.call(tbl.rows).forEach(function (r) {
        if (r.children[idx]) r.removeChild(r.children[idx]);
      });
      keep = row.children[idx] || row.children[row.children.length - 1];
    } else return false;
    return keep || true;
  }

  /** 把游標放進某一格（並讓那一頁成為目前的編輯區），不捲動畫面 */
  function caretIntoCell(cell) {
    if (!cell || !cell.parentNode) return false;
    var r = d.createRange();
    r.selectNodeContents(cell);
    r.collapse(true);
    var s = w.getSelection();
    s.removeAllRanges();
    s.addRange(r);
    return true;
  }

  /* ── 縮排 ────────────────────────────────────────────────────────────────
     刻意**不用** execCommand('indent')：Chrome 會產生 <blockquote>，
     而 blockquote 不在白名單裡，存檔清洗時會被脫殼＝縮排整個消失，而且不會報錯。
     這裡自己在區塊元素上加 margin-left（div/p 本來就在白名單），
     縮排才存得住、也才跟顯示端一致。
     （使用者 2026-09-07：原本是用清單來排版，結果前端每行都冒出「•」，
       他要的其實是縮排而不是項目符號。） */
  function blockOf(node, root) {
    var n = (node && node.nodeType === 3) ? node.parentNode : node;
    while (n && n !== root) {
      if (n.nodeType === 1 && ['DIV','P','LI'].indexOf(n.nodeName) >= 0) return n;
      n = n.parentNode;
    }
    return null;
  }
  /** 取得選取範圍涵蓋的區塊元素；整段還沒有區塊容器時就地包一個 <div> */
  /** 選取範圍涵蓋的區塊元素。
   *  @param {boolean} [createIfNone] 真的要改東西（縮排／行距）時才傳 true。
   *  ⚠ 這個旗標非常重要：整份文件連一個 div/p 都沒有時，舊版**一律**把整頁內容
   *    搬進一個新的 <div>——而 refreshState() 只是要「讀」目前的行距也會呼叫它，
   *    於是「游標一點進表格儲存格，工具列一更新就把整頁重新包一層」，
   *    節點被搬走游標當然就掉到文件開頭＝使用者 2026-09-23 回報的「刪除表格後焦點亂跳」。 */
  function selectedBlocks(body, createIfNone) {
    var sel = w.getSelection();
    if (!sel || !sel.rangeCount || !body.contains(sel.anchorNode)) return [];
    var rg = sel.getRangeAt(0);
    var out = [];
    var all = body.querySelectorAll('div,p,li');
    for (var i = 0; i < all.length; i++) {
      if (rg.intersectsNode(all[i])) {
        // 只取最外層那一個，巢狀的子區塊不重複加（不然一按縮排就跳兩格）
        var covered = false;
        for (var j = 0; j < out.length; j++) { if (out[j].contains(all[i])) { covered = true; break; } }
        if (!covered) out.push(all[i]);
      }
    }
    if (!out.length) {
      var b = blockOf(rg.startContainer, body);
      if (b) { out.push(b); }
      else {
        // 游標在表格的儲存格裡（格子裡常常只有純文字，沒有 div/p）→ 就以那一格為準，
        // 行距與縮排套在 <td> 上一樣有效，也不必去動整頁的結構
        var cell = cellOf(body);
        if (cell) out.push(cell);
        else if (createIfNone) {
          // 整個編輯區還是純文字（沒有任何區塊）→ 包一層 div 才有東西可以縮排
          var dv = d.createElement('div');
          while (body.firstChild) dv.appendChild(body.firstChild);
          body.appendChild(dv);
          out.push(dv);
        }
      }
    }
    return out;
  }
  /**
   * 把「上下對齊」套到選取範圍碰到的每一個儲存格。
   * 回傳有沒有套到任何一格——沒有的話呼叫端要提示使用者先點進表格裡。
   */
  function setCellVAlign(body, va) {
    var sel = w.getSelection();
    if (!sel || !sel.rangeCount) return false;
    var rg = sel.getRangeAt(0);
    // 游標所在的那一格。**不可以用模組層的 body 去判斷**——多頁文件時 body 是第一頁，
    // 使用者點在第 4 頁的表格上就會被判成「不在編輯區裡」而整個沒反應（實測抓到）。
    var one = rg.startContainer;
    one = (one.nodeType === 1 ? one : one.parentNode);
    one = one && one.closest ? one.closest('td,th') : null;
    // 真正的範圍是「這一格所在的那一頁」，沒有就退回傳進來的 body
    var scope = one && one.closest ? (one.closest('.egrt-main') || one.closest('[contenteditable]')) : null;
    if (!scope) scope = body;
    var cells = [];
    if (one && rg.collapsed) cells = [one];
    else {
      Array.prototype.slice.call(scope.querySelectorAll('td,th')).forEach(function (c) {
        if (rg.intersectsNode ? rg.intersectsNode(c) : false) cells.push(c);
      });
      if (!cells.length && one) cells = [one];
    }
    if (!cells.length) return false;
    cells.forEach(function (c) { c.style.verticalAlign = va; });
    return true;
  }

  function indentBlocks(body, dir) {
    var blocks = selectedBlocks(body, true);
    blocks.forEach(function (b) {
      var cur = parseFloat((b.style.marginLeft || '').replace('em', '')) || 0;
      var nx  = Math.max(0, Math.min(INDENT_MAX, cur + dir * INDENT_STEP));
      if (nx <= 0) b.style.marginLeft = '';
      else b.style.marginLeft = nx + 'em';
    });
    return blocks.length > 0;
  }

  /** 載入共用的內文排版 CSS（與列印版同一個檔，保證換頁位置一致） */
  function injectDocCss() {
    if (d.getElementById('eg-docpage-css')) return;
    var src = '';
    var all = d.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if ((all[i].src || '').indexOf('eg_richtext.js') >= 0) { src = all[i].src; break; }
    }
    var cut = src.indexOf('/resource/js/eg_richtext.js');
    var href = (cut < 0) ? '../../resource/css/eg_doc_page.css'
                         : src.substring(0, cut) + '/resource/css/eg_doc_page.css';
    var lk = d.createElement('link');
    lk.id = 'eg-docpage-css';
    lk.rel = 'stylesheet';
    lk.href = href;
    d.head.appendChild(lk);
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
      '.egrt-view p{margin:0;}',
      // ── doc profile（整份文件）─────────────────────────────────────────
      '.egrt-bar-doc{padding:4px 5px;}',
      '.egrt-br{display:block;height:3px;}',
      '.egrt-sel{height:26px;border:1px solid #d8c7b0;border-radius:3px;background:#fff;color:#4E2C0B;',
      'font-size:12px;padding:0 2px;margin:0 2px;vertical-align:middle;max-width:130px;}',
      '.egrt-pop-wide{width:172px;}',
      '.egrt-lab{font-size:12px;color:#8A5A2B;margin:0 2px 0 4px;vertical-align:middle;}',
      '.egrt-read{display:inline-block;min-width:30px;text-align:center;font-size:12px;color:#4E2C0B;',
      'background:#fff;border:1px solid #d8c7b0;border-radius:3px;line-height:24px;height:26px;',
      'padding:0 4px;vertical-align:middle;}',
      '.egrt-pf{margin-bottom:5px;font-size:12px;color:#6B471A;}',
      '.egrt-pf label{display:inline-block;width:44px;margin:0;font-weight:normal;}',
      '.egrt-ti{width:62px;height:24px;border:1px solid #d8c7b0;border-radius:3px;padding:0 4px;font-size:12px;}',
      /* 文件本體：灰底捲動區內放一張或多張白紙，像 Word 一樣一頁一頁。
         ⚠ 紙張寬度是「整張 A4」(210mm) 而不是內容寬——內距用列印版同一組邊界，
           算出來的內容寬才會跟列印一致（180mm）。先前寫成 width:180mm 再加 15mm 內距，
           編輯區的內容寬只剩 150mm，比列印窄，所以匯入的表格在畫面上會溢出、列印其實放得下
           ＝根本沒有做到所見即所得（使用者 2026-09-22 回報）。
         ⚠ overflow-x 一律 hidden，再配合 fitPages() 自動縮放到容器寬度內，
           所以**結構上不可能出現左右拉桿**（使用者明確要求底下不要有左右移動的拉桿）。*/
      '.egrt-doc-scroll{background:#efe9e0;overflow-y:auto;overflow-x:hidden;border-radius:0 0 4px 4px;}',
      '.egrt-pages{display:flex;flex-wrap:wrap;justify-content:center;align-items:flex-start;',
      'gap:26px 16px;padding:26px 8px 16px;}',
      /* 紙張高度是**固定**的（不是 min-height）：固定才量得出「內容有沒有超出這一頁」，
         也才有辦法自動把超出的搬到下一頁。先前寫 min-height 的話紙會跟著內容長高
         （實測長到 7553px），scrollHeight 永遠等於 clientHeight，偵測不到超出。
         overflow:hidden＝超出的部分被裁掉，跟真的紙一樣；但不會有人看不到自己打的字——
         超出的當下就會自動往後搬（見 reflowSoon()），搬不動時才框紅提示。 */
      /* 頁碼籤／刪除此頁／超出提示一律放在**紙張外面**（.egrt-sheetwrap 底下的兄弟元素）。
         放在紙張裡面會出兩個問題，兩個都是實測抓到的：
           ⑴ 絕對定位的子元素會被算進 scrollHeight，於是每一頁都被判成「內容超出」；
           ⑵ 更嚴重：joinPages() 取的是紙張的 innerHTML，那些提示文字會被一起存進內容
              （往返一次之後「刪除此頁」變成文件內容，頁數也跟著暴增）。 */
      '.egrt-sheetwrap{position:relative;flex:0 0 auto;}',
      // 內文排版（字級、行高、段落與表格間距）一律由 resource/css/eg_doc_page.css 提供，
      // 列印版 <link> 的是同一個檔——兩邊各寫一份的話換頁位置會對不起來（見該檔說明）
      /* 下緣多留 12px 的安全邊：列印引擎排出來的行高跟編輯器會差個幾 px，
         剛好塞滿的一頁列印時就會多擠出一張幾乎空白的紙（實測畫面 8 頁、PDF 卻 10 頁）。
         寧可每頁少排一點點，也不要印出來多好幾張。 */
      '.egrt-sheet{background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.18);padding:16mm 15mm calc(18mm + 12px);',
      'max-height:none;overflow:hidden;box-sizing:border-box;display:flex;flex-direction:column;}',
      /* 可編輯區＝紙張內容高扣掉頁首頁尾。這樣編輯器塞得下的量就是列印放得下的量，
         不然列印會因為多了頁首頁尾而把內容擠到下一頁（實測畫面 14 頁、PDF 卻 22 頁）。 */
      /* ⚠ max-height/min-height 一定要重設：.egrt-main 同時掛著 .egrt-body（備註欄用的 class），
         那邊有 max-height:260px，不蓋掉的話可編輯區會被壓成 260px，
         編輯器一頁塞得下的量遠小於列印可用高，列印就會整批多出一倍的頁數（實測畫面 14 頁、PDF 22 頁）。 */
      '.egrt-main{flex:1 1 auto;overflow:hidden;outline:none;min-height:0 !important;',
      'max-height:none !important;padding:0;}',
      '.egrt-chrome{flex:0 0 auto;}',
      '.egrt-chrome *{cursor:default;}',
      // 系統頁（封面／制修訂紀錄書／目錄）：唯讀，標示「系統自動產生」
      '.egrt-sys .egrt-sheet{background:#fdfbf7;}',
      '.egrt-syslab{position:absolute;left:0;top:-19px;font-size:11px;line-height:16px;color:#fff;',
      'background:#8A5A2B;border-radius:3px;padding:0 7px;white-space:nowrap;}',
      '.egrt-pageno{position:absolute;left:0;top:-19px;font-size:11px;line-height:16px;',
      'color:#8A5A2B;background:#e6ddd0;border-radius:3px;padding:0 7px;white-space:nowrap;}',
      // 內容超出這一頁：紙張加紅框，右上角掛提示（搬不動時才會留著，一般會自動回流）
      '.egrt-sheet.egrt-over{outline:2px solid #DD5138;outline-offset:0;}',
      // 被自動加長的頁：用漸層底色標出「超過 A4 的那一段」，看得出來列印會跨頁
      '.egrt-sheet.egrt-grown{background:#fff;}',
      '.egrt-ovbadge{position:absolute;right:0;top:-19px;font-size:11px;line-height:16px;color:#fff;',
      'background:#DD5138;border-radius:3px;padding:0 7px;cursor:pointer;white-space:nowrap;}',
      '.egrt-delpage{position:absolute;right:0;top:-19px;font-size:11px;line-height:16px;',
      'color:#A34E2A;background:#e6ddd0;border-radius:3px;padding:0 7px;cursor:pointer;}',
      '.egrt-delpage:hover{background:#DD5138;color:#fff;}',
      // 空白頁：按鈕標成橘色，使用者一眼看得出「這一頁是空的，可以刪」
      '.egrt-delpage.egrt-blankpage{background:#F0A24B;color:#fff;font-weight:bold;}',
      // 待確認：紅底閃一下，並且明講要再按一次或按 Enter
      '.egrt-delpage.egrt-delarm{background:#DD5138;color:#fff;font-weight:bold;}',
      // 內容裡若還殘留分頁標記（舊資料），在編輯器裡標示出來
      '.egrt-page hr[style*="page-break"]{border-top:2px dashed #D6851F;position:relative;margin:18px 0;}',
      '.egrt-page hr[style*="page-break"]:after{content:"分頁";position:absolute;right:0;top:-9px;',
      'background:#F7E0BD;color:#8A5A2B;font-size:10px;line-height:14px;padding:0 5px;border-radius:3px;}',
      // 選取中的圖片＋右下角縮放把手
      '.egrt-img-sel{outline:2px solid #F0A24B;outline-offset:1px;}',
      '.egrt-imgbar{position:absolute;z-index:2300;background:#fff;border:1px solid #d8c7b0;border-radius:4px;',
      'box-shadow:0 3px 10px rgba(0,0,0,.18);padding:3px 4px;white-space:nowrap;}',
      '.egrt-imgbar button{border:1px solid #e4d3ba;background:#faf6f0;color:#6B471A;font-size:11px;',
      'line-height:20px;height:22px;padding:0 6px;border-radius:3px;margin:0 1px;}',
      '.egrt-imgbar button:hover{background:#f2e6d4;}',
      '.egrt-imgbar .egrt-ib-del{color:#DD5138;}',
      '.egrt-handle{position:absolute;z-index:2299;width:12px;height:12px;background:#F0A24B;',
      'border:2px solid #fff;border-radius:2px;box-shadow:0 1px 3px rgba(0,0,0,.3);cursor:nwse-resize;}'
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

    var prof = (opt.profile === 'doc') ? 'doc' : 'basic';
    var cfg  = profileCfg(prof);
    var isDoc = prof === 'doc';
    if (isDoc) injectDocCss();

    host.classList.add('egrt-wrap');
    if (isDoc) {
      // 捲動容器（不可編輯）→ 頁面容器 → 一張或多張紙（每一張自己是 contenteditable）
      host.innerHTML = docToolbarHtml(opt)
        + '<div class="egrt-doc-scroll" style="max-height:' + (parseInt(opt.height, 10) || 560) + 'px">'
        + '<div class="egrt-pages"></div></div>'
        + (opt.maxLen ? '<div class="egrt-count"></div>' : '');
      host.style.position = host.style.position || 'relative';   // 圖片浮動工具列以它為定位父層
    } else {
      host.innerHTML = toolbarHtml()
        + '<div class="egrt-body" contenteditable="true" data-eg-skip data-ph="' + escAttr(opt.placeholder || '') + '"></div>'
        + (opt.maxLen ? '<div class="egrt-count"></div>' : '');
    }

    var pagesBox = host.querySelector('.egrt-pages');
    var count = host.querySelector('.egrt-count');
    var maxLen = opt.maxLen || 0;
    var paper = { size: opt.pageSize || 'A4', orient: opt.orientation || 'portrait' };
    var viewMode = (opt.viewMode === 'double') ? 'double' : 'single';

    /* body ＝「目前作用中的那一頁」。
       刻意保留這個變數名並在焦點移動時改指向：這樣底下所有既有邏輯
       （exec／插入／表格加減列欄／縮排／圖片選取）都自動作用在使用者正在編輯的那一頁，
       不必逐一改寫成「找出目前是哪一頁」。 */
    var body = null;
    /** 可編輯的正文區（一頁一個）。系統頁沒有正文區，所以不會被算進來 */
    function sheets() {
      return pagesBox ? Array.prototype.slice.call(pagesBox.querySelectorAll('.egrt-main')) : [];
    }
    /** 正文區所在的那張紙 */
    function sheetOf(m) {
      return (m && m.closest) ? m.closest('.egrt-sheet') : null;
    }
    /** 紙張的外框（頁碼籤等輔助元素掛在這裡，不在紙張裡面） */
    function wrapOf(s) {
      return (s && s.closest) ? s.closest('.egrt-sheetwrap') : null;
    }
    if (isDoc) { body = mkSheet(); }
    else { body = host.querySelector('.egrt-body'); bindSheet(body); }

    // 換色指令要 styleWithCSS=true（產生 <span style="color:…"> 而不是已淘汰的 <font>）；
    // 粗體/斜體/底線/刪除線要 styleWithCSS=false，才會產生 <b>/<i>/<u>/<strike> 標籤。
    function useCss(cmd) {
      try { d.execCommand('styleWithCSS', false, TAG_CMDS.indexOf(cmd) < 0); } catch (e) {}
    }

    function refreshCount() {
      if (!count) return;
      // 多頁模式要算全部頁的字數
      var n = toText(isDoc ? joinPages() : body.innerHTML).length;
      count.textContent = n + ' / ' + maxLen + ' 字';
      count.classList.toggle('over', n > maxLen);
    }
    function refreshState() {
      ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList',
       'justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'].forEach(function (c) {
        var b = host.querySelector('.egrt-btn[data-cmd="' + c + '"]');
        if (!b) return;
        var on = false;
        try { on = d.queryCommandState(c); } catch (e) {}
        b.classList.toggle('on', !!on);
      });
      if (!isDoc) return;
      syncLhRead();
      syncSizeRead();
      // 選單要跟著游標位置顯示「現在是什麼」，不然使用者永遠看到「正文」而不知道自己在標題裡
      var blkSel = host.querySelector('.egrt-sel-block');
      if (blkSel) {
        var b2 = blockOf(w.getSelection() && w.getSelection().anchorNode, body);
        var nm = 'P';
        var n = b2 || (w.getSelection() ? w.getSelection().anchorNode : null);
        if (n && n.nodeType === 3) n = n.parentNode;
        while (n && n !== body) {
          if (/^H[1-6]$/.test(n.nodeName)) { nm = n.nodeName; break; }
          n = n.parentNode;
        }
        blkSel.value = nm;
      }
      var tblBtns = ['egRowAdd', 'egRowDel', 'egColAdd', 'egColDel'];
      var inCell = !!cellOf(body);
      tblBtns.forEach(function (c) {
        var b3 = host.querySelector('.egrt-btn[data-cmd="' + c + '"]');
        if (b3) b3.style.opacity = inCell ? '1' : '.45';
      });
    }
    function closePops() {
      Array.prototype.slice.call(host.querySelectorAll('.egrt-pop')).forEach(function (p) { p.classList.remove('open'); });
    }
    function changed() { refreshState(); refreshCount(); if (opt.onChange) opt.onChange(); }

    /* 記住游標位置。
       插入圖片／流程圖要先開跳窗，跳窗一開（尤其流程圖那個 Fabric 畫布）焦點就離開編輯區、
       選取範圍跟著失效；等回來再 execCommand('insertHTML') 時，圖會插到文件開頭之類
       完全不是使用者原本游標的地方。所以在編輯區裡每次移動游標都把範圍記下來，
       插入前若目前選取已經不在編輯區內，就先還原回去。 */
    var lastRange = null;
    function rememberRange() {
      var sel = w.getSelection();
      if (!sel || !sel.rangeCount) return;
      var r = sel.getRangeAt(0);
      if (body.contains(r.commonAncestorContainer)) lastRange = r.cloneRange();
    }
    function restoreRange() {
      var sel = w.getSelection();
      var inBody = sel && sel.rangeCount && body.contains(sel.getRangeAt(0).commonAncestorContainer);
      if (inBody) return true;
      if (!lastRange) return false;
      try {
        sel.removeAllRanges();
        sel.addRange(lastRange);
        return true;
      } catch (e) { return false; }
    }
    // 每一頁的監聽統一在 bindSheet() 綁（多頁模式會動態長出新的頁）

    function insertHtml(html) {
      body.focus();
      if (!restoreRange()) {
        // 真的沒有游標可用（例如一打開就按插入）→ 補在內容最後，而不是開頭
        var r = d.createRange();
        r.selectNodeContents(body);
        r.collapse(false);
        var s = w.getSelection();
        s.removeAllRanges();
        s.addRange(r);
      }
      try { d.execCommand('insertHTML', false, html); } catch (e) {}
      rememberRange();
      changed();
    }
    /** 字級：execCommand('fontSize') 只吃 1~7，所以先用 size=7 當記號標出選取範圍，
     *  再把那些 <font size="7"> 換成 <span style="font-size:14pt">（<font> 不在白名單、存檔會被脫殼）。
     *  這是處理「任意跨節點選取」最可靠的做法，自己用 range.surroundContents() 會在
     *  選取只覆蓋部分節點時直接丟例外。 */
    function applyFontSize(val) {
      if (!val) return;
      body.focus();
      restoreRange();      // 下拉會搶走焦點，要把使用者原本選的那段字還原回來
      try { d.execCommand('styleWithCSS', false, false); } catch (e) {}
      try { d.execCommand('fontSize', false, '7'); } catch (e) {}
      var made = [];
      Array.prototype.slice.call(body.querySelectorAll('font[size="7"]')).forEach(function (f) {
        var sp = d.createElement('span');
        sp.style.fontSize = val;
        while (f.firstChild) sp.appendChild(f.firstChild);
        f.parentNode.replaceChild(sp, f);
        made.push(sp);
      });
      /* 換掉節點會讓選取消失（使用者回報「每次修改完就取消我選擇的文字」）。
         這裡把選取重新框到剛產生的那幾個 span，所以可以連按 +／- 一直調。 */
      if (made.length) {
        try {
          var rg = d.createRange();
          rg.setStartBefore(made[0]);
          rg.setEndAfter(made[made.length - 1]);
          var sl = w.getSelection();
          sl.removeAllRanges();
          sl.addRange(rg);
          rememberRange();
        } catch (e) {}
      }
      syncSizeRead();
      changed();
    }

    /** 目前游標／選取處的字級（pt，四捨五入到整數）
     *  ⚠ 不可以直接用 anchorNode：套用字級之後選取是用 setStartBefore/setEndAfter 重設的，
     *    anchorNode 會是**父層**，量到的是段落的字級而不是剛套上去的那個，
     *    結果就是「連按第二次沒有變大」（13pt→13pt，實測抓到）。
     *    所以要取選取範圍內第一個真的有字的文字節點，看它的父元素。 */
    function curFontPt() {
      var sel = w.getSelection();
      var nd = null;
      if (sel && sel.rangeCount) {
        var rg = sel.getRangeAt(0);
        var root = rg.commonAncestorContainer;
        if (root.nodeType === 3) root = root.parentNode;
        try {
          var tw = d.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
          var t;
          while ((t = tw.nextNode())) {
            if (t.textContent.replace(/\s/g, '') === '') continue;
            if (rg.intersectsNode ? rg.intersectsNode(t) : true) { nd = t.parentNode; break; }
          }
        } catch (e) {}
        if (!nd) nd = rg.startContainer;
      }
      if (nd && nd.nodeType === 3) nd = nd.parentNode;
      if (!nd || !body.contains(nd)) nd = body;
      var px = parseFloat(getComputedStyle(nd).fontSize) || 16;
      return Math.max(6, Math.min(72, Math.round(px * 72 / 96)));
    }
    /** 字級 +/-：一次 1pt，連按可以邊看邊調 */
    function bumpFont(step) {
      body.focus();
      restoreRange();
      applyFontSize(Math.max(6, Math.min(72, curFontPt() + step)) + 'pt');
    }
    function syncSizeRead() {
      var sel = host.querySelector('.egrt-sel-size');
      if (sel) {
        var v = curFontPt() + 'pt';
        var has = false;
        Array.prototype.slice.call(sel.options).forEach(function (o) { if (o.value === v) has = true; });
        sel.value = has ? v : '';
      }
    }

    /** 目前選取處的行距（沒設過就換算 computed 值） */
    function curLineHeight() {
      var bs = selectedBlocks(body);
      var b = bs[0];
      if (!b) return 1.6;
      if (b.style.lineHeight) return parseFloat(b.style.lineHeight) || 1.6;
      var cs = getComputedStyle(b);
      var lh = parseFloat(cs.lineHeight), fs = parseFloat(cs.fontSize);
      return (lh && fs) ? Math.round(lh / fs * 10) / 10 : 1.6;
    }
    /** 行距 +/-：一次 0.1，套在選取涵蓋的區塊上（line-height 在 doc 白名單內，存得住） */
    function bumpLineHeight(step) {
      body.focus();
      restoreRange();
      var bs = selectedBlocks(body, true);
      if (!bs.length) return;
      var v = Math.max(1, Math.min(3, Math.round((curLineHeight() + step) * 10) / 10));
      bs.forEach(function (b) { b.style.lineHeight = String(v); });
      var read = host.querySelector('.egrt-lh-read');
      if (read) read.textContent = v.toFixed(1);
      rememberRange();
      changed();
    }
    function syncLhRead() {
      var read = host.querySelector('.egrt-lh-read');
      if (read) read.textContent = curLineHeight().toFixed(1);
    }
    /** 依資產編號把 <img> 的 src 補回來。
     *  src 刻意不存進內容（見 richtext_lib.php 的說明），所以每次帶入內容都要補一次。 */
    function hydrate() {
      if (!isDoc || !opt.assetUrl) return;
      // 多頁模式要掃所有頁，不能只掃目前那一頁
      Array.prototype.slice.call(pagesBox.querySelectorAll('img[data-asset]')).forEach(function (im) {
        var id = im.getAttribute('data-asset');
        im.setAttribute('src', opt.assetUrl(id));
        im.setAttribute('draggable', 'false');
        im.setAttribute('alt', '');
      });
    }

    function exec(cmd, val) {
      /* 上下對齊要在「搶焦點之前」處理：body 是第一頁，body.focus() 會把游標從
         使用者點的那一頁（例如第 4 頁的表格）拉回第一頁，restoreRange() 也還原不回來，
         結果就是按了完全沒反應（實測抓到）。工具列的 mousedown 已經 preventDefault，
         所以這時候的即時選取仍然是使用者點的那一格。 */
      if (cmd === 'egVaTop' || cmd === 'egVaMid' || cmd === 'egVaBot') {
        var va0 = cmd === 'egVaTop' ? 'top' : (cmd === 'egVaBot' ? 'bottom' : 'middle');
        if (!setCellVAlign(body, va0)) {
          alert('上下對齊是設定在表格的儲存格上。\n請把游標點進表格裡的某一格（或把整張表選起來）再按一次。');
        } else { changed(); }
        return;
      }
      body.focus();
      restoreRange();   // 從下拉（會搶焦點）過來時，要把使用者原本選的那段字還原回來
      // 縮排是自己實作的（見 indentBlocks），不走 execCommand
      if (cmd === 'egIndent' || cmd === 'egOutdent') {
        indentBlocks(body, cmd === 'egIndent' ? 1 : -1);
        changed();
        return;
      }
      if (isDoc) {
        if (cmd === 'egRowAdd' || cmd === 'egRowDel' || cmd === 'egColAdd' || cmd === 'egColDel') {
          var keepCell = tableOp(body, cmd);
          if (!keepCell) {
            alert(cmd === 'egRowDel' ? '只剩一列了，不能再刪。要整張表拿掉請把表格選起來按 Delete。'
                : cmd === 'egColDel' ? '只剩一欄了，不能再刪。'
                : '請先把游標點進表格裡的任一個儲存格。');
            return;
          }
          // 游標留在「接手的那一格」，不要讓它掉到文件開頭（焦點跳動）
          if (keepCell && keepCell.nodeType === 1) caretIntoCell(keepCell);
          changed();
          return;
        }
        if (cmd === 'egHr')        { insertHtml('<hr>'); return; }
        // 分頁＝真的長出新的一頁（游標所在區塊之後的內容整批移過去），不是插一條線
        if (cmd === 'egPageBreak') { splitAtCaret(); return; }
        if (cmd === 'egAddPage')   { api.addPage(); return; }
        if (cmd === 'egAutoPage')  { autoPaginate(); return; }
        if (cmd === 'egFontUp')    { bumpFont(1);  return; }
        if (cmd === 'egFontDown')  { bumpFont(-1); return; }
        if (cmd === 'egLhUp')      { bumpLineHeight(0.1);  return; }
        if (cmd === 'egLhDown')    { bumpLineHeight(-0.1); return; }
        // 開跳窗之前先把游標位置記下來（跳窗一開選取就沒了，回來要插在原處）
        if (cmd === 'egImage')     { rememberRange(); if (opt.onInsertImage) opt.onInsertImage(insertAsset); return; }
        if (cmd === 'egFlow')      { rememberRange(); if (opt.onInsertFlow)  opt.onInsertFlow(insertAsset);  return; }
      }
      useCss(cmd);
      try { d.execCommand(cmd, false, val === undefined ? null : val); } catch (e) {}
      refreshState(); refreshCount();
      if (opt.onChange) opt.onChange();
    }

    /* ── 圖片／流程圖：插入、選取、縮放、對齊 ─────────────────────────────
       內容裡只放 <img data-asset="N">，src 由 hydrate() 依編號組回來。
       裁切與「編輯流程圖」都是回呼，實際動作由模組頁面做（它才有 API 與權限）。 */
    function insertAsset(assetId, o) {
      o = o || {};
      var wd = o.width || '60%';
      insertHtml('<img data-asset="' + String(assetId).replace(/[^0-9]/g, '') + '" style="width:' + wd + '">'
               + (o.noBreak ? '' : ''));
      hydrate();
      var im = body.querySelector('img[data-asset="' + assetId + '"]');
      if (im) selectImg(im);
    }

    var curImg = null, imgBar = null, imgHandle = null;

    function clearImgSel() {
      if (curImg) curImg.classList.remove('egrt-img-sel');
      curImg = null;
      if (imgBar) imgBar.style.display = 'none';
      if (imgHandle) imgHandle.style.display = 'none';
    }

    function buildImgBar() {
      if (imgBar) return;
      imgBar = d.createElement('div');
      imgBar.className = 'egrt-imgbar';
      imgBar.style.display = 'none';
      var h = '';
      ['25', '50', '75', '100'].forEach(function (p) {
        h += '<button type="button" data-w="' + p + '" title="寬度 ' + p + '%">' + p + '%</button>';
      });
      h += '<button type="button" data-al="left" title="靠左"><i class="fa fa-align-left"></i></button>'
         + '<button type="button" data-al="center" title="置中"><i class="fa fa-align-center"></i></button>'
         + '<button type="button" data-al="right" title="靠右"><i class="fa fa-align-right"></i></button>';
      if (opt.onCropImage) h += '<button type="button" data-act="crop" title="裁切"><i class="fa fa-crop"></i> 裁切</button>';
      if (opt.onEditFlow)  h += '<button type="button" data-act="flow" title="編輯流程圖"><i class="fa fa-sitemap"></i> 編輯</button>';
      h += '<button type="button" class="egrt-ib-del" data-act="del" title="刪除"><i class="fa fa-trash-o"></i></button>';
      imgBar.innerHTML = h;
      host.appendChild(imgBar);

      imgHandle = d.createElement('div');
      imgHandle.className = 'egrt-handle';
      imgHandle.style.display = 'none';
      host.appendChild(imgHandle);

      imgBar.addEventListener('mousedown', function (e) { e.preventDefault(); });
      imgBar.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button') : null;
        if (!b || !curImg) return;
        var im = curImg;
        if (b.getAttribute('data-w')) { im.style.width = b.getAttribute('data-w') + '%'; im.style.height = ''; }
        else if (b.getAttribute('data-al')) {
          var blk = blockOf(im, body);
          if (!blk) {   // 圖片還直接掛在編輯區根層：包一層 div 才有東西可以對齊
            blk = d.createElement('div');
            im.parentNode.insertBefore(blk, im);
            blk.appendChild(im);
          }
          blk.style.textAlign = b.getAttribute('data-al');
        } else {
          var act = b.getAttribute('data-act'), id = im.getAttribute('data-asset');
          if (act === 'del') { im.parentNode.removeChild(im); clearImgSel(); changed(); return; }
          if (act === 'crop' && opt.onCropImage) { opt.onCropImage(id, function () { reloadAsset(id); }); return; }
          if (act === 'flow' && opt.onEditFlow)  { opt.onEditFlow(id,  function () { reloadAsset(id); }); return; }
          return;
        }
        placeImgUi();
        changed();
      });

      // 右下角把手拖曳＝改寬度（以百分比存，換螢幕寬度也不會爆版）
      imgHandle.addEventListener('mousedown', function (e) {
        if (!curImg) return;
        e.preventDefault();
        var im = curImg, startX = e.clientX, startW = im.getBoundingClientRect().width;
        var hostW = (im.parentNode && im.parentNode.getBoundingClientRect)
          ? im.parentNode.getBoundingClientRect().width : body.getBoundingClientRect().width;
        if (hostW <= 0) hostW = 1;
        function mv(ev) {
          var pct = Math.max(5, Math.min(100, Math.round((startW + (ev.clientX - startX)) / hostW * 100)));
          im.style.width = pct + '%';
          im.style.height = '';
          placeImgUi();
        }
        function up() {
          d.removeEventListener('mousemove', mv);
          d.removeEventListener('mouseup', up);
          changed();
        }
        d.addEventListener('mousemove', mv);
        d.addEventListener('mouseup', up);
      });
    }

    /** 換過內容（裁切完、流程圖改完）後強制重載該圖，否則瀏覽器會拿舊的快取圖 */
    function reloadAsset(id) {
      if (!opt.assetUrl) return;
      var root = isDoc ? pagesBox : body;    // 同一張圖可能被放在別的頁
      Array.prototype.slice.call(root.querySelectorAll('img[data-asset="' + id + '"]')).forEach(function (im) {
        im.setAttribute('src', opt.assetUrl(id) + (opt.assetUrl(id).indexOf('?') >= 0 ? '&' : '?') + '_t=' + Date.now());
      });
      placeImgUi();
      changed();
    }

    function placeImgUi() {
      if (!curImg || !imgBar) return;
      var hr = host.getBoundingClientRect(), ir = curImg.getBoundingClientRect();
      var scroll = host.querySelector('.egrt-doc-scroll');
      var sr = scroll ? scroll.getBoundingClientRect() : hr;
      // 圖片被捲出可見範圍就把工具列收起來，不然它會浮在別的內容上
      if (ir.bottom < sr.top || ir.top > sr.bottom) {
        imgBar.style.display = 'none'; imgHandle.style.display = 'none';
        return;
      }
      imgBar.style.display = 'block';
      imgHandle.style.display = 'block';
      var top = ir.top - hr.top - 28;
      if (top < 2) top = ir.bottom - hr.top + 4;
      imgBar.style.top  = top + 'px';
      imgBar.style.left = Math.max(2, Math.min(ir.left - hr.left, hr.width - imgBar.offsetWidth - 4)) + 'px';
      imgHandle.style.top  = (ir.bottom - hr.top - 6) + 'px';
      imgHandle.style.left = (ir.right - hr.left - 6) + 'px';
    }

    function selectImg(im) {
      buildImgBar();
      clearImgSel();
      curImg = im;
      im.classList.add('egrt-img-sel');
      // 流程圖才需要「編輯」鈕；純圖片不需要（沒有 fabric 工作檔可編）
      var fb = imgBar.querySelector('[data-act="flow"]');
      if (fb) {
        var kind = opt.assetKind ? opt.assetKind(im.getAttribute('data-asset')) : '';
        fb.style.display = (kind === 'flow') ? '' : 'none';
      }
      var cb = imgBar.querySelector('[data-act="crop"]');
      if (cb) {
        var k2 = opt.assetKind ? opt.assetKind(im.getAttribute('data-asset')) : '';
        cb.style.display = (k2 === 'flow') ? 'none' : '';   // 流程圖要改內容不是裁切
      }
      placeImgUi();
    }

    if (isDoc) {
      var sc = host.querySelector('.egrt-doc-scroll');
      if (sc) sc.addEventListener('scroll', placeImgUi);
      w.addEventListener('resize', placeImgUi);
      // 選單（區塊階層／字型／字級）
      host.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.classList) return;
        if (t.classList.contains('egrt-sel-block')) { exec('formatBlock', '<' + t.value.toLowerCase() + '>'); }
        else if (t.classList.contains('egrt-sel-font')) {
          if (t.value) exec('fontName', t.value);
        } else if (t.classList.contains('egrt-sel-size')) {
          applyFontSize(t.value);
        }
      });
      // 插入表格
      host.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.egrt-tbl-ins') : null;
        if (!b) return;
        var pop = host.querySelector('.egrt-pop-tbl');
        var r = Math.max(1, Math.min(50, parseInt(pop.querySelector('.egrt-tr-n').value, 10) || 3));
        var c = Math.max(1, Math.min(12, parseInt(pop.querySelector('.egrt-tc-n').value, 10) || 3));
        var wh = pop.querySelector('.egrt-th-chk').checked;
        closePops();
        insertHtml(tableHtml(r, c, wh));
      });
    }

    host.addEventListener('mousedown', function (e) {
      if (!e.target.closest || !e.target.closest('.egrt-bar')) return;
      /* 表單控制項（字型／字級／段落階層下拉、插入表格的數字欄）**絕對不可以 preventDefault**：
         在 <select> 的 mousedown 上 preventDefault 會讓下拉整個打不開——
         症狀就是「點了完全沒反應」（使用者 2026-09-22 回報）。
         這些控制項本來就會搶走焦點，所以改成「點下去之前先把游標範圍記下來」，
         套用格式時再還原（見 exec()／applyFontSize() 的 restoreRange）。
         ⚠ 這個 bug 我的無頭測試原本抓不到，因為測試是直接設 select.value 再發 change 事件，
           繞過了 mousedown。往後測下拉一定要用真滑鼠點擊。 */
      if (/^(SELECT|INPUT|TEXTAREA|OPTION)$/.test(e.target.tagName)) { rememberRange(); return; }
      // 其餘（按鈕、色票）一律不搶焦點，否則選取範圍會消失、格式就套不到剛選的字
      e.preventDefault();
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
      var vb = t.closest('.egrt-btn[data-view]');
      if (vb) { closePops(); api.setViewMode(vb.getAttribute('data-view')); return; }
      var btn = t.closest('.egrt-btn[data-cmd]');
      if (btn) { closePops(); exec(btn.getAttribute('data-cmd')); }
    });
    d.addEventListener('click', function (e) {
      if (!host.contains(e.target)) closePops();
    });

    /* ── 每一頁的監聽（多頁模式會動態長出新的頁，所以一定要收斂成一支）────── */
    function bindSheet(el2) {
      if (!el2 || el2._egrtBound) return el2;
      el2._egrtBound = 1;

      // 貼上：一律先清洗再插入，避免把 Word/網頁的整片樣式、<script>、外部圖片帶進來
      el2.addEventListener('paste', function (e) {
        e.preventDefault();
        var dt = e.clipboardData || w.clipboardData;
        if (!dt) return;
        var html = dt.getData('text/html');
        var ins = html ? clean(html, prof) : (dt.getData('text/plain') || '')
          .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\r?\n/g, '<br>');
        try { d.execCommand('insertHTML', false, ins); } catch (err) {}
        afterEdit();
      });
      el2.addEventListener('input', afterEdit);
      el2.addEventListener('keyup', function () { refreshState(); rememberRange(); });
      el2.addEventListener('mouseup', function () { refreshState(); rememberRange(); });
      // 焦點一進來就把 body 指向這一頁（所有工具列動作都作用在使用者正在編輯的那一頁）
      el2.addEventListener('focusin', function () { body = el2; });
      el2.addEventListener('mousedown', function () { body = el2; });

      if (isDoc) {
        el2.addEventListener('click', function (e) {
          if (e.target && e.target.nodeName === 'IMG') { selectImg(e.target); e.stopPropagation(); }
          else clearImgSel();
        });
        el2.addEventListener('dblclick', function (e) {
          // 雙擊流程圖＝直接開編輯器（跟 Word 雙擊圖表一樣）
          if (e.target && e.target.nodeName === 'IMG' && opt.onEditFlow) {
            var id = e.target.getAttribute('data-asset');
            if (!opt.assetKind || opt.assetKind(id) === 'flow') {
              opt.onEditFlow(id, function () { reloadAsset(id); });
            }
          }
        });
      }
      return el2;
    }

    function afterEdit() {
      refreshCount();
      markOverflow();
      reflowSoon();      // 打滿一頁就自動流到下一頁（刪字之後也會把下一頁的內容拉回來）
      if (opt.onChange) opt.onChange();
    }

    /* ── 分頁 ───────────────────────────────────────────────────────────────
       一張紙＝一個 contenteditable。頁與頁的邊界在內容裡是
       <hr style="page-break-after:always">（本來就在白名單裡、列印也認得），
       所以資料格式沒有變：get() 把各頁接起來時再插回那個標記，列印版一個字都不必改。

       為什麼不做「打字打滿自動流到下一頁」：那要在每次按鍵重新量測並切頁，
       游標還要在重排之後回到原處，contenteditable 上非常不穩（Google Docs 那種
       是自己寫排版引擎、根本不用 contenteditable）。這裡改成**分頁由人決定**
       （按「插入分頁」），但內容超出一頁時會把那張紙框紅並提示，不會默默印出界。 */
    var PAGE_MARK = '<hr style="page-break-after:always">';

    function paperMM() {
      var d2 = (String(paper.size).toUpperCase() === 'A3') ? [297, 420] : [210, 297];
      return (paper.orient === 'landscape') ? [d2[1], d2[0]] : d2;
    }
    /** $el2 可以是正文區或紙張，一律套到「紙」上 */
    function applyPaper(el2) {
      var sh = (el2 && el2.classList && el2.classList.contains('egrt-sheet')) ? el2 : sheetOf(el2);
      if (!sh) return;
      var mm = paperMM();
      sh.style.width = mm[0] + 'mm';
      sh.style.height = mm[1] + 'mm';     // 固定高度，見 .egrt-sheet 的說明
    }

    /** 建立一張紙（html＝內容；beforeSheet＝插在哪一張紙之前，省略＝加在最後） */
    function mkSheet(html, beforeSheet) {
      var wrap = d.createElement('div');
      wrap.className = 'egrt-sheetwrap';
      var sh = d.createElement('div');
      sh.className = 'egrt-sheet';
      wrap.appendChild(sh);

      var hdr = d.createElement('div');
      hdr.className = 'egrt-chrome egrt-chrome-hdr';
      hdr.setAttribute('contenteditable', 'false');
      sh.appendChild(hdr);

      var s = d.createElement('div');
      s.className = 'egrt-body egrt-page egrt-main eg-docbody';
      s.setAttribute('contenteditable', 'true');
      s.setAttribute('data-eg-skip', '');
      s.setAttribute('data-ph', opt.placeholder || '');
      s.innerHTML = (html === undefined || html === null || html === '') ? '<p><br></p>' : html;
      sh.appendChild(s);

      var ftr = d.createElement('div');
      ftr.className = 'egrt-chrome egrt-chrome-ftr';
      ftr.setAttribute('contenteditable', 'false');
      sh.appendChild(ftr);

      applyPaper(sh);
      var no = d.createElement('span');
      no.className = 'egrt-pageno';
      wrap.appendChild(no);
      var bw = beforeSheet ? wrapOf(beforeSheet) : null;
      if (bw && bw.parentNode === pagesBox) pagesBox.insertBefore(wrap, bw);
      else pagesBox.appendChild(wrap);
      bindSheet(s);
      return s;
    }

    /** 內容 HTML → 依分頁標記切成多張紙 */
    function renderPages(html) {
      pagesBox.innerHTML = '';
      var parts = String(html || '').split(/<hr[^>]*page-break-after[^>]*>/i);
      if (!parts.length) parts = [''];
      parts.forEach(function (p) { mkSheet(p); });
      renderSysPages();
      body = sheets()[0] || null;
      hydrate();
      numberPages();
      fitPages();
      // 載入就先回流一次：匯入的 Word 多半沒有分頁符，這樣一打開就已經是一頁一頁
      reflow(0);
      markOverflow();
      /* 再算一次——初次量測時**字體還沒載完、圖片還沒解碼**，行高與圖片高度都還會變，
         只超出幾十 px 的邊界頁會被判成「放得下」（實測：一頁只超出 44px 就被漏掉，
         手動 refit() 一次才出現）。所以字體就緒與圖片載完都要再回流一次。 */
      var settle = function () { reflow(0); markOverflow(); };
      if (d.fonts && d.fonts.ready && d.fonts.ready.then) {
        d.fonts.ready.then(function () { setTimeout(settle, 60); });
      } else {
        setTimeout(settle, 300);
      }
      var imgs = Array.prototype.slice.call(pagesBox.querySelectorAll('img'));
      var pending = imgs.filter(function (i) { return !i.complete; }).length;
      if (pending) {
        imgs.forEach(function (i) {
          if (i.complete) return;
          var done = function () { if (--pending <= 0) settle(); };
          i.addEventListener('load', done);
          i.addEventListener('error', done);
        });
      }
    }

    /** 各頁接回一份 HTML（頁與頁之間放回分頁標記）
     *  ⚠ 被自動拆到下一頁的長表格，**存檔時一律接回成一張完整的表**——
     *    存成兩張的話，下次載入就永遠是兩張、再拆一次還會愈拆愈碎。
     *    接合是在**複本**上做的，畫面上的分頁完全不受影響。 */
    function joinPages() {
      var ss = sheets();
      if (!ss.length) return '';
      var cs = ss.map(function (s) { return s.cloneNode(true); });
      // ⚠ 一定要「先全部接合完，最後才拿掉記號」──
      //   邊接邊拿掉的話，第二張續表就找不到原表了（原表的記號已經被前一頁清掉），
      //   結果是存檔時後面的列整批不見（實測 70 列只剩 24 列）
      cs.forEach(function (n) {
        Array.prototype.forEach.call(n.querySelectorAll('table[data-egrt-cont]'), function (t) {
          var id = t.getAttribute('data-egrt-split'), org = null;
          for (var k = 0; k < cs.length && !org; k++) {
            org = cs[k].querySelector('table[data-egrt-split="' + id + '"]:not([data-egrt-cont])');
          }
          if (org) {
            var tb = tblBody(org);
            tblRows(t).forEach(function (r) { tb.appendChild(r); });
          }
          var blk = t.closest('div,p');
          if (blk && blk.children.length === 1 && blk.parentNode) blk.parentNode.removeChild(blk);
          else if (t.parentNode) t.parentNode.removeChild(t);
        });
      });
      cs.forEach(function (n) {
        Array.prototype.forEach.call(n.querySelectorAll('table[data-egrt-split]'), function (t) {
          t.removeAttribute('data-egrt-split'); t.removeAttribute('data-egrt-cont');
        });
      });
      return cs.map(function (n) { return n.innerHTML; }).join(PAGE_MARK);
    }

    /* ── 版面樣板（封面／制修訂紀錄書／目錄／頁首／頁尾）────────────────────
       HTML 全部由後端 as_doc_tpl_lib 產生（API action=tpl），這裡只負責擺位置
       與把頁首樣板裡的 {{PAGE}}/{{TOTAL}}/{{VER}} 代入。
       **不在這裡再組一次版面**——兩邊各寫一份版面一定會走鐘（鐵律4）。 */
    var chrome = null;

    function mkSysSheet(sp) {
      var wrap = d.createElement('div');
      wrap.className = 'egrt-sheetwrap egrt-sys';
      var sh = d.createElement('div');
      sh.className = 'egrt-sheet';
      wrap.appendChild(sh);
      var bd = d.createElement('div');
      // 刻意不叫 .egrt-main：那個 class 是「可編輯的正文區」，系統頁不能被算成正文頁
      bd.className = 'egrt-sysbody eg-docbody';
      bd.innerHTML = sp.html || '';
      sh.appendChild(bd);
      var ft = d.createElement('div');
      ft.className = 'egrt-chrome egrt-chrome-ftr';
      ft.innerHTML = (chrome && chrome.ftr) || '';
      sh.appendChild(ft);
      applyPaper(sh);
      var lab = d.createElement('span');
      lab.className = 'egrt-syslab';
      lab.textContent = (sp.label || '') + '（系統自動產生，不必也不能在這裡編輯）';
      wrap.appendChild(lab);
      var no = d.createElement('span');
      no.className = 'egrt-pageno';
      wrap.appendChild(no);
      return wrap;
    }

    function renderSysPages() {
      if (!pagesBox) return;
      Array.prototype.slice.call(pagesBox.querySelectorAll('.egrt-sheetwrap.egrt-sys'))
        .forEach(function (w2) { w2.parentNode.removeChild(w2); });
      if (!chrome || !chrome.sys || !chrome.sys.length) return;
      var first = pagesBox.firstElementChild;
      chrome.sys.forEach(function (sp) {
        var wp = mkSysSheet(sp);
        if (first) pagesBox.insertBefore(wp, first); else pagesBox.appendChild(wp);
      });
    }

    /** 這一頁的頁版別（後端算好的；頁數比存檔時多出來的那幾頁退回文件目前版次） */
    function pageVerOf(i) {
      if (!chrome) return '';
      var a = chrome.pageVers || [];
      return (a[i] !== undefined && a[i] !== null && a[i] !== '') ? a[i] : (chrome.docVer || '');
    }

    function fillChrome(mainEl, pageNo, total, ver) {
      var sh = sheetOf(mainEl);
      if (!sh) return;
      var h = sh.querySelector('.egrt-chrome-hdr');
      var f = sh.querySelector('.egrt-chrome-ftr');
      if (h) {
        h.innerHTML = (chrome && chrome.hdrTpl)
          ? String(chrome.hdrTpl).split('{{PAGE}}').join(pageNo)
              .split('{{TOTAL}}').join(total).split('{{VER}}').join(ver)
          : '';
      }
      if (f) f.innerHTML = (chrome && chrome.ftr) || '';
    }

    function numberPages() {
      var ss = sheets(), n = ss.length;
      /* 頁次只算正文（使用者 2026-09-23 指定：文件制修訂紀錄書與目錄都不算一頁），
         所以正文從第 1 頁開始、總頁數也只算正文。系統頁的籤改成寫它自己是什麼。 */
      var total = n;
      var sysLabels = (chrome && chrome.sys) ? chrome.sys : [];
      Array.prototype.slice.call(pagesBox ? pagesBox.querySelectorAll('.egrt-sheetwrap.egrt-sys') : [])
        .forEach(function (wp, i) {
          var no2 = wp.querySelector('.egrt-pageno');
          if (no2) {
            var lb = (sysLabels[i] && sysLabels[i].label) ? sysLabels[i].label : '系統頁';
            no2.textContent = lb + '（不計入頁次）';
          }
        });
      ss.forEach(function (s, i) {
        var wrap = wrapOf(s);
        if (!wrap) return;
        fillChrome(s, i + 1, total, pageVerOf(i));
        var no = wrap.querySelector('.egrt-pageno');
        if (no) no.textContent = '第 ' + (i + 1) + ' 頁 / 共 ' + total + ' 頁';
        var del = wrap.querySelector('.egrt-delpage');
        if (n > 1) {
          if (!del) {
            del = d.createElement('span');
            del.className = 'egrt-delpage';
            del.addEventListener('mousedown', function (e) { e.preventDefault(); e.stopPropagation(); });
            /* 兩段式確認（使用者指定）：第一次按＝進入待確認，第二次按或按 Enter 才真的刪。
               刻意不用原生 confirm——那個跳窗會把焦點搶走，回來之後游標位置就沒了。 */
            del.addEventListener('click', function (e) {
              e.preventDefault(); e.stopPropagation();
              if (del.getAttribute('data-arm') === '1') { disarmDel(); delPage(s); return; }
              armDel(del, s);
            });
            wrap.appendChild(del);
          }
          // 空白頁另外標出來，使用者才找得到要刪哪一頁
          var blank = !s.textContent.replace(/ |\s/g, '') && !s.querySelector('img,table,hr');
          del.textContent = del.getAttribute('data-arm') === '1'
            ? '再按一次或按 Enter 刪除' : (blank ? '刪除此空白頁' : '刪除此頁');
          del.classList.toggle('egrt-blankpage', blank);
        } else if (del) { del.parentNode.removeChild(del); }
      });
    }

    /**
     * 回流之後還是超出的頁＝「單一區塊本身就比一頁高」（實測是幾張很長的表格）。
     * 這種搬不動，但**絕對不能就這樣裁掉**——使用者會看不到也改不到那幾列。
     * 所以讓那一頁自己長高（height:auto）並框紅說明「列印時會自動跨頁」，
     * 列印版本來就會依 page-break 規則正確跨頁，所以印出來是對的。
     */
    /**
     * 這一頁的內容有沒有超出「一張紙」的高度。
     * ⚠ 一定要相對**固定頁高**量，不可以相對「目前的 clientHeight」——
     *   已經被加長過的頁，clientHeight 就是內容高度，量起來永遠「沒有超出」，
     *   於是第二次呼叫 markOverflow() 就把加長還原掉（而 renderPages 正好連呼叫兩次，
     *   症狀是初次載入完全沒作用、手動 refit() 一次才出現）。
     *   所以量測前先把高度還原成固定值，量完再依結果決定要不要加長——這樣才可重複執行。
     */
    function isOverPage(s) {
      var sh = sheetOf(s) || s;
      var wasGrown = sh.classList.contains('egrt-grown');
      if (wasGrown) { sh.style.height = paperMM()[1] + 'mm'; sh.style.minHeight = ''; }
      var over = s.scrollHeight > s.clientHeight + 2;
      if (wasGrown && over) { sh.style.height = 'auto'; sh.style.minHeight = paperMM()[1] + 'mm'; }
      return over;
    }

    function markOverflow() {
      sheets().forEach(function (s) {
        var sh = sheetOf(s) || s;
        var over = isOverPage(s);
        if (over && blocksOf(s).length <= 1) {
          sh.style.height = 'auto';
          sh.style.minHeight = paperMM()[1] + 'mm';
          sh.classList.add('egrt-grown');
        } else if (sh.classList.contains('egrt-grown')) {
          sh.classList.remove('egrt-grown');
          applyPaper(sh);
        }
        sh.classList.toggle('egrt-over', over);
        var wrap = wrapOf(s);
        if (!wrap) return;
        var b = wrap.querySelector('.egrt-ovbadge');
        if (over) {
          if (!b) {
            b = d.createElement('span');
            b.className = 'egrt-ovbadge';
            b.addEventListener('mousedown', function (e) { e.preventDefault(); e.stopPropagation(); });
            b.addEventListener('click', function (e) {
              e.preventDefault(); e.stopPropagation();
              pushOverflow(s);
            });
            wrap.appendChild(b);
          }
          // ⚠ egrt-grown 是掛在「紙」上不是可編輯區，這裡判錯會顯示錯的說明（實測抓到）
          b.textContent = sh.classList.contains('egrt-grown')
            ? '這一頁只有一個區塊（單一列或一張大圖）比 A4 還高、拆不開，已自動加長（列印會自動跨頁，內容不會漏）'
            : '內容超出這一頁，點這裡把超出的搬到下一頁';
        } else if (b) { b.parentNode.removeChild(b); }
      });
    }

    /** 點紅色提示：把超出這一頁的內容搬到下一頁（就是從這一頁開始回流一次） */
    function pushOverflow(s) {
      /* 長表格現在會自動拆列跨頁（見 splitTailTable），所以按下去先跑一次回流。
         還是放不下的只剩「單一列或單張圖本身就比一頁高」——那真的搬不動，
         那一頁已經自動加長，列印時仍會依 page-break 規則正確跨頁。 */
      var ks = blocksOf(s);
      if (ks.length === 1 && !splitTailTable(s)) {
        w.alert('這一頁只有一個區塊比 A4 還高，而且沒辦法再往下拆'
              + '（通常是「單獨一列」的內容太長，或是一張很大的圖），所以這一頁已經自動加長。\n\n'
              + '・列印沒問題：列印時會自動跨頁，內容不會遺漏。\n'
              + '・想讓編輯畫面也剛好一頁：把那一列的內容拆成兩列、把圖縮小，或把紙張改成 A3／橫式。');
        return;
      }
      var idx = sheets().indexOf(s);
      reflow(idx > 0 ? idx : 0);
      changed();
    }

    /* ── 版面回流（真正的分頁）────────────────────────────────────────────
       打字打滿就自動流到下一頁、刪掉字之後下一頁的內容自動拉回來，跟 Word 一樣。

       為什麼這件事在 contenteditable 上做得到：因為回流是**搬移既有節點**
       （insertBefore／appendChild）而不是重建 HTML——節點被搬走時，
       選取範圍指向的還是同一個文字節點，所以**游標會跟著節點一起到下一頁**，
       不需要自己記位置再還原（那才是不穩的做法）。
       量測只有瀏覽器做得到（後端匯入時無法得知一頁放得下多少），所以一定在前端做。 */
    /** 紙張裡的內容區塊（輔助元素已經移到紙張外面，所以這裡就是全部子元素） */
    function blocksOf(s) { return Array.prototype.slice.call(s.children); }
    function fitsPage(s) { return s.scrollHeight <= s.clientHeight + 2; }
    function appendBlock(s, node) { s.appendChild(node); }
    function prependBlock(s, node) {
      var first = blocksOf(s)[0];
      if (first) s.insertBefore(node, first); else appendBlock(s, node);
    }
    function nextSheet(s, create) {
      var wrap = wrapOf(s);
      var nw = wrap && wrap.nextElementSibling;
      // 回傳的必須是「可編輯的正文區」而不是「紙」——回傳紙的話回流會把區塊插到
      // 頁首/頁尾的旁邊而不是正文裡，結果就是整份只剩一頁（實測抓到）
      if (nw && nw.classList && nw.classList.contains('egrt-sheetwrap')
          && !nw.classList.contains('egrt-sys')) return nw.querySelector('.egrt-main');
      if (!create) return null;
      var ss = sheets(), i = ss.indexOf(s);
      var ns = mkSheet('', (i >= 0 && i + 1 < ss.length) ? ss[i + 1] : null);
      ns.innerHTML = '';     // 不預留空段落，等內容搬進來（否則每次分頁都多一行空白）
      return ns;
    }

    /* ── 長表格自動跨頁（使用者 2026-09-23：不希望超過 A4，超過的要自動到下一頁）──
       把放不下的「列」搬到下一頁的續表（欄寬與表頭跟著複製），原表至少留一列。
       ⚠ 會不收斂的做法是「拆了就不管」：回流的第二階段又把續表往前拉、再拆一次，
         一張表拆完還會讓後面每一頁連鎖重排（先前試作實測連點 30 次頁數完全沒變）。
         所以每次回流**一開始先把所有續表接回原表**，再依當時的高度從頭重拆——
         同樣的內容一定得到同樣的結果，可重複執行。
       存檔時存的也一律是「接回去的完整表格」（見 joinPages），
       下次載入再依當時的紙張大小重拆，不會把使用者的表格永久拆成兩張。 */
    var splitSeq = 0;
    /** 表格最外層的資料列（<thead> 的不算，那是表頭要留在原表並複製到續表） */
    function tblRows(t) {
      var out = [];
      Array.prototype.forEach.call(t.children, function (c) {
        if (c.tagName === 'TR') out.push(c);
        else if (c.tagName === 'TBODY') {
          Array.prototype.forEach.call(c.children, function (r) { if (r.tagName === 'TR') out.push(r); });
        }
      });
      return out;
    }
    function tblBody(t) {
      var tb = null;
      Array.prototype.forEach.call(t.children, function (c) { if (!tb && c.tagName === 'TBODY') tb = c; });
      if (!tb) { tb = d.createElement('tbody'); t.appendChild(tb); }
      return tb;
    }
    /** 續表：複製 <table> 本身與 colgroup／thead（欄寬與表頭才會跟原表一樣） */
    function mkContTable(t, id) {
      var c = t.cloneNode(false);
      c.setAttribute('data-egrt-split', id);
      c.setAttribute('data-egrt-cont', '1');
      Array.prototype.forEach.call(t.children, function (ch) {
        if (ch.tagName === 'COLGROUP' || ch.tagName === 'THEAD') c.appendChild(ch.cloneNode(true));
      });
      c.appendChild(d.createElement('tbody'));
      return c;
    }
    /** 這一頁最後一個區塊是表格的話，把放不下的列搬到下一頁。回傳有沒有真的搬 */
    function splitTailTable(s) {
      var ks = blocksOf(s);
      var last = ks[ks.length - 1];
      if (!last || last.nodeType !== 1) return false;
      var box = null, tbl = last;
      if (last.tagName !== 'TABLE') {                 // 匯入的內容常把表格包在一層 <div> 裡
        var inner = last.querySelector && last.querySelector('table');
        if (!inner || inner.parentNode !== last) return false;
        box = last; tbl = inner;
      }
      var rows = tblRows(tbl);
      if (rows.length < 2) return false;              // 只有一列＝拆不動（那一頁自己加長）
      var id = tbl.getAttribute('data-egrt-split') || ('sp' + (++splitSeq) + '-' + Date.now().toString(36));
      tbl.setAttribute('data-egrt-split', id);
      var cont = mkContTable(tbl, id), cb = tblBody(cont), moved = 0;
      while (rows.length - moved > 1) {
        cb.insertBefore(rows[rows.length - 1 - moved], cb.firstChild);
        moved++;
        if (fitsPage(s)) break;
      }
      if (!moved) return false;
      var blk = cont;
      if (box) { blk = box.cloneNode(false); blk.appendChild(cont); }
      prependBlock(nextSheet(s, true), blk);
      return true;
    }
    /* 把「整頁內容被包在一層沒有任何屬性的 <div> 裡」這種結構攤平。
       兩個來源：⑴舊版 selectedBlocks() 的 bug（游標一點進表格儲存格就把整頁包一層，已修）
                 ⑵有些匯入來源本來就多包一層。
       不攤平的話**整頁就只有一個區塊、回流永遠搬不動**，那一頁只好一直往下長高，
       使用者看到的就是「這一頁超過 A4 了還是不會自動分頁」。
       只動「沒有任何屬性、而且裡面裝的是區塊元素」的 div——
       打字產生的 <div>一行字</div> 裝的是純文字，不會被誤拆。 */
    function unwrapNakedBlocks(s) {
      var hit = false;
      Array.prototype.slice.call(s.children).forEach(function (k) {
        if (k.tagName !== 'DIV' || k.attributes.length) return;
        var hasBlock = false;
        Array.prototype.forEach.call(k.children, function (c) {
          if (/^(DIV|P|TABLE|H[1-6]|UL|OL|HR)$/.test(c.tagName)) hasBlock = true;
        });
        if (!hasBlock) return;
        while (k.firstChild) s.insertBefore(k.firstChild, k);
        s.removeChild(k);
        hit = true;
      });
      return hit;
    }

    /* 「整頁內容被塞在一張單列單格的表格裡」＝Word 的頁框表格（舊版匯入會連框一起帶進來）。
       這種表格**拆不動**（只有一列），那一頁只好一直長高、永遠超過 A4。
       只有在「這一頁放不下、而且真的拆不動」時才拆掉外框，把格子裡的內容攤到頁面上——
       攤開之後才有多個區塊可以往下一頁搬。新版匯入已經在匯入當下就拆掉了（adi_unwrap_page_tables），
       這裡是為了救「之前就已經匯進來的舊內容」。 */
    function unwrapFrameTable(s) {
      var ks = blocksOf(s);
      if (ks.length !== 1) return false;
      var t = ks[0];
      if (!t || t.tagName !== 'TABLE') return false;
      var rows = tblRows(t);
      if (rows.length !== 1) return false;                 // 有兩列以上就交給 splitTailTable 拆
      var cells = Array.prototype.slice.call(rows[0].children);
      if (cells.length !== 1) return false;                // 只處理「單列單格」的頁框
      var cell = cells[0];
      if (!cell.querySelector('table,p,div')) return false; // 格子裡不是整段內容就別動它
      while (cell.firstChild) s.insertBefore(cell.firstChild, t);
      s.removeChild(t);
      return true;
    }

    /** 把所有續表接回原表。回傳受影響的最前面那一頁（沒有就 -1） */
    function unsplitTables() {
      var conts = host.querySelectorAll('.egrt-main table[data-egrt-cont]');
      if (!conts.length) return -1;
      var ss = sheets(), minIdx = -1;
      Array.prototype.forEach.call(conts, function (t) {
        var id = t.getAttribute('data-egrt-split');
        var org = id ? host.querySelector('.egrt-main table[data-egrt-split="' + id + '"]:not([data-egrt-cont])') : null;
        if (org) {
          var tb = tblBody(org);
          tblRows(t).forEach(function (r) { tb.appendChild(r); });
          var os = org.closest('.egrt-main'), oi = ss.indexOf(os);
          if (oi >= 0 && (minIdx < 0 || oi < minIdx)) minIdx = oi;
        }
        // 續表自己（連同幫它包的那層 div）整塊拿掉；空掉的頁由回流第③步清除
        var blk = t.closest('.egrt-main > *');
        if (blk && blk.parentNode) blk.parentNode.removeChild(blk);
        else if (t.parentNode) t.parentNode.removeChild(t);
      });
      Array.prototype.forEach.call(host.querySelectorAll('.egrt-main table[data-egrt-split]'), function (t) {
        t.removeAttribute('data-egrt-split');
      });
      return minIdx;
    }

    /** 回流前後把畫面「釘」在同一個位置：以游標所在的那一頁為錨，
     *  不然刪掉一張表或一列之後版面一重排，畫面就會自己跳到別的地方
     *  （使用者 2026-09-23 回報的「焦點跳動」另一半）。 */
    function scrollAnchor() {
      var sc = host.querySelector('.egrt-doc-scroll');
      if (!sc) return null;
      var sel = w.getSelection();
      var n = sel && sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
      if (n && n.nodeType === 3) n = n.parentNode;
      var sh = (n && n.closest) ? n.closest('.egrt-sheetwrap') : null;
      if (!sh || !host.contains(sh)) return null;
      return { sc: sc, sh: sh, top: sh.getBoundingClientRect().top - sc.getBoundingClientRect().top };
    }
    function scrollRestore(a) {
      if (!a || !a.sh || !host.contains(a.sh)) return;
      var now = a.sh.getBoundingClientRect().top - a.sc.getBoundingClientRect().top;
      var diff = now - a.top;
      if (Math.abs(diff) > 1) a.sc.scrollTop += diff;
    }

    var reflowing = false;
    /** @param {number} [from] 從第幾頁開始（打字時只從目前那一頁往後算，整份文件才不會每次都重排） */
    function reflow(from) {
      if (!isDoc || reflowing) return;
      reflowing = true;
      var anchor = scrollAnchor();
      // 先把「之前被加長過」的頁還原成固定高度，不然量不出有沒有超出
      sheets().forEach(function (s) {
        var sh = sheetOf(s);
        if (sh && sh.classList.contains('egrt-grown')) { sh.classList.remove('egrt-grown'); applyPaper(sh); }
      });
      // 整頁被包在一層空 div 裡的先攤平，否則那一頁永遠只有一個區塊、搬不動也拆不開
      sheets().forEach(function (s0) { unwrapNakedBlocks(s0); });
      // 先把上一輪拆開的續表接回原表，再從頭重拆（不這樣做就不會收斂）
      var merged = unsplitTables();
      var start = Math.max(0, from || 0);
      if (merged >= 0) start = Math.min(start, merged);

      var guard = 0, i = start;
      // ① 往後推：超出的區塊搬到下一頁；只剩一張長表格就改「拆列」
      for (; i < sheets().length && guard < 4000; i++) {
        var s = sheets()[i];
        while (!fitsPage(s) && guard++ < 4000) {
          var ks = blocksOf(s);
          if (ks.length <= 1) {
            // 拆列 → 拆不動就試著拆掉 Word 頁框 → 都不行才放棄（那一頁自己加長）
            if (!splitTailTable(s) && !unwrapFrameTable(s)) break;
            continue;
          }
          prependBlock(nextSheet(s, true), ks[ks.length - 1]);
        }
      }
      // ② 往前拉：下一頁的第一個區塊如果這一頁放得下就拉回來（刪字之後版面才會回流）
      for (i = start; i < sheets().length - 1 && guard < 9000; i++) {
        var a = sheets()[i], b = sheets()[i + 1];
        while (guard++ < 9000) {
          var bk = blocksOf(b);
          if (!bk.length) break;
          var node = bk[0];
          appendBlock(a, node);
          if (!fitsPage(a)) { prependBlock(b, node); break; }   // 放不下就還回去
        }
      }
      // ③ 清掉搬空的頁（第一頁永遠留著）
      sheets().forEach(function (s, idx) {
        if (idx === 0) return;
        if (!blocksOf(s).length) { var wp = wrapOf(s); if (wp) wp.parentNode.removeChild(wp); }
      });
      if (!sheets().length) { body = mkSheet(); }
      else if (!body || !host.contains(body)) { body = sheets()[0]; }
      numberPages(); fitPages(); markOverflow();
      scrollRestore(anchor);
      reflowing = false;
    }

    var reflowTimer = null;
    /** 打字之後延遲回流：每個按鍵都重排會卡，350ms 沒動作才做 */
    function reflowSoon() {
      if (!isDoc) return;
      if (reflowTimer) clearTimeout(reflowTimer);
      reflowTimer = setTimeout(function () {
        reflowTimer = null;
        var idx = sheets().indexOf(body);
        reflow(idx > 0 ? idx : 0);
      }, 350);
    }

    /** 工具列的「自動分頁」：整份文件重排一次（匯入完的一整頁就是靠這個變成一頁一頁） */
    function autoPaginate() { reflow(0); changed(); }

    /** 在游標處分頁：游標所在區塊之後的內容整批移到新的一頁 */
    function splitAtCaret() {
      var s = body;
      if (!s || !s.classList.contains('egrt-body')) s = sheets()[0];
      if (!s) return;
      var blk = blockOf(w.getSelection() && w.getSelection().anchorNode, s);
      var kids = blocksOf(s);
      var idx = blk ? kids.indexOf(blk) : -1;
      var move = (idx >= 0) ? kids.slice(idx + 1) : [];
      var ssAll = sheets(), si = ssAll.indexOf(s);
      var ns = mkSheet('', (si >= 0 && si + 1 < ssAll.length) ? ssAll[si + 1] : null);
      if (move.length) {
        ns.innerHTML = '';
        move.forEach(function (k) { ns.appendChild(k); });
      }
      numberPages(); fitPages(); markOverflow(); changed();
      ns.focus();
      body = ns;
    }

    /* ── 刪除此頁的兩段式確認 ──────────────────────────────────────────
       armDel() 把按鈕切成待確認狀態並接管 Enter；8 秒沒動作或按 Esc 自動取消，
       免得使用者離開之後還留著一個「按 Enter 就會刪頁」的狀態。 */
    var delArmed = null, delTimer = null, delKeyBound = false;
    function disarmDel() {
      if (delTimer) { clearTimeout(delTimer); delTimer = null; }
      if (delArmed) {
        delArmed.removeAttribute('data-arm');
        delArmed.classList.remove('egrt-delarm');
        delArmed = null;
      }
      numberPages();                    // 讓按鈕文字回到「刪除此頁／刪除此空白頁」
    }
    function armDel(btn, s) {
      disarmDel();
      delArmed = btn;
      btn.setAttribute('data-arm', '1');
      btn.classList.add('egrt-delarm');
      btn.textContent = '再按一次或按 Enter 刪除';
      delTimer = setTimeout(disarmDel, 8000);
      if (!delKeyBound) {
        delKeyBound = true;
        d.addEventListener('keydown', function (e) {
          if (!delArmed) return;
          var target = delArmed._sheet;
          if (e.key === 'Enter') { e.preventDefault(); disarmDel(); if (target) delPage(target); }
          else if (e.key === 'Escape') { disarmDel(); }
        }, true);
      }
      btn._sheet = s;
    }

    function delPage(s) {
      if (sheets().length <= 1) return;
      var wp = wrapOf(s);
      if (wp) wp.parentNode.removeChild(wp);
      body = sheets()[0];
      numberPages(); fitPages(); markOverflow(); changed();
    }

    /** 自動縮放到容器寬度內——這是「底下永遠不會有左右拉桿」的保證 */
    function fitPages() {
      if (!isDoc || !pagesBox) return;
      var sc = host.querySelector('.egrt-doc-scroll');
      if (!sc) return;
      var per = (viewMode === 'double') ? 2 : 1;
      var mm = paperMM();
      var pw = mm[0] * 96 / 25.4;                       // 一張紙的 px 寬
      var cs = getComputedStyle(pagesBox);
      var gap = parseFloat(cs.columnGap || cs.gap) || 16;
      var padX = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0);
      // 一列要放 per 張紙所需的寬度（含紙間空隙與容器內距）
      var need = per * pw + (per - 1) * gap + padX;
      var avail = sc.clientWidth || host.clientWidth || need;
      var k = Math.min(1, avail / need);
      if (!isFinite(k) || k <= 0) k = 1;
      pagesBox.style.zoom = (k < 1) ? k : '';
      /* 雙頁模式：容器寬度限制成剛好兩張，flex-wrap 才會每列放兩張。
         ⚠ 這裡是 border-box（Bootstrap 的全域設定），maxWidth 含內距——
           先前漏加內距，兩張紙就差幾 px 放不下而換行，看起來像「雙頁沒有作用」。 */
      pagesBox.style.maxWidth = (per === 2) ? Math.ceil(need + 2) + 'px' : '';
      pagesBox.style.margin = '0 auto';
      placeImgUi();
    }
    if (isDoc) {
      w.addEventListener('resize', fitPages);
      // 側欄收放會改變容器寬度，但那不會觸發 window resize，所以也監看容器本身
      if (w.ResizeObserver) {
        try { new w.ResizeObserver(fitPages).observe(host.querySelector('.egrt-doc-scroll')); } catch (e) {}
      }
    }

    /** 目前全部內容（多頁模式＝各頁以分頁標記接起來） */
    function allHtml() { return isDoc ? joinPages() : body.innerHTML; }

    var api = {
      host: host, profile: prof,
      get body() { return body; },     // 目前作用中的那一頁（會隨焦點改變）
      // get() 回傳的內容裡 <img> 已經沒有 src（清洗時剝掉），存進 DB 的永遠只有資產編號
      get: function () { return clean(allHtml(), prof); },
      set: function (html) {
        var c2 = clean(html, prof);
        if (isDoc) { renderPages(c2); }
        else { body.innerHTML = c2; hydrate(); }
        clearImgSel();
        refreshCount(); refreshState();
      },
      text: function () { return toText(allHtml()); },
      focus: function () { if (body) body.focus(); },
      over: function () { return maxLen > 0 && toText(allHtml()).length > maxLen; },
      maxLen: maxLen,
      /** 紙張大小與方向（編輯區要跟列印一致才叫所見即所得） */
      setPaper: function (size, orient) {
        paper.size = (String(size).toUpperCase() === 'A3') ? 'A3' : 'A4';
        paper.orient = (orient === 'landscape') ? 'landscape' : 'portrait';
        sheets().forEach(applyPaper);
        fitPages(); markOverflow();
      },
      /** 檢視模式：single＝一頁一頁／double＝兩頁並排 */
      setViewMode: function (m) {
        viewMode = (m === 'double') ? 'double' : 'single';
        Array.prototype.slice.call(host.querySelectorAll('.egrt-btn[data-view]')).forEach(function (b) {
          b.classList.toggle('on', b.getAttribute('data-view') === viewMode);
        });
        fitPages();
      },
      viewMode: function () { return viewMode; },
      pageCount: function () { return isDoc ? sheets().length : 1; },
      addPage: function () { var s = mkSheet(); numberPages(); fitPages(); changed(); s.focus(); body = s; return s; },
      refit: function () { fitPages(); markOverflow(); },
      /** 帶入版面樣板（由 API action=tpl 取得）：系統頁、頁首樣板、頁尾、頁版別 */
      setChrome: function (o) {
        chrome = o || null;
        renderSysPages();
        numberPages();
        reflow(0);      // 頁首頁尾會佔掉高度，要重新分頁
        fitPages(); markOverflow();
      },
      autoPaginate: function () { autoPaginate(); },
      /** 讓模組頁面在上傳/編輯完之後把圖插進來或重新載入 */
      insertAsset: function (id, o) { insertAsset(id, o); },
      reloadAsset: function (id) { reloadAsset(id); },
      hydrate: function () { hydrate(); },
      /** 內容目前引用到哪些資產編號（存檔時要據此清掉沒在用的資產；多頁模式要掃所有頁） */
      assetIds: function () {
        var root = isDoc ? pagesBox : body;
        return Array.prototype.slice.call(root.querySelectorAll('img[data-asset]'))
          .map(function (im) { return parseInt(im.getAttribute('data-asset'), 10); })
          .filter(function (n) { return n > 0; });
      }
    };
    host._egrt = api;
    if (isDoc) { numberPages(); fitPages(); api.setViewMode(viewMode); }
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
    /** 顯示用：清洗後回傳 HTML 字串（呼叫端直接塞進 innerHTML）
     *  @param {string} [profile] 'doc'＝整份文件；省略＝basic */
    render: function (html, profile) { return clean(html, profile); },
    toText: toText,
    TEXT_COLORS: TEXT_COLORS,
    BG_COLORS: BG_COLORS,
    DOC_FONTS: DOC_FONTS,
    DOC_SIZES: DOC_SIZES
  };
})(window, document);
