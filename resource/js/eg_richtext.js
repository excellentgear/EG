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
    'width':             /^(\d{1,3}(\.\d{1,2})?)(px|%|em)$/,
    'height':            /^(\d{1,4}(\.\d{1,2})?)(px|em)$/,
    'padding':           /^(\d{1,2}(\.\d)?(px|em)\s*){1,4}$/,
    'vertical-align':    /^(top|middle|bottom|baseline)$/i,
    'border':            /^(\d{1,2}px\s+(solid|dashed|dotted|none)\s+(#[0-9A-Fa-f]{3,8}|[a-zA-Z]{3,20})|none|0)$/i,
    'border-collapse':   /^(collapse|separate)$/i,
    'border-width':      /^(\d{1,2}px\s*){1,4}$/,
    'border-style':      /^((solid|dashed|dotted|none)\s*){1,4}$/i,
    'border-color':      /^((#[0-9A-Fa-f]{3,8}|[a-zA-Z]{3,20})\s*){1,4}$/,
    'float':             /^(left|right|none)$/i,
    'margin':            /^(\d{1,2}(\.\d)?(px|em)\s*){1,4}$/,
    'margin-right':      /^(\d{1,2}(\.\d)?)(em|px)$/,
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
    h += '<select class="egrt-sel egrt-sel-size" data-eg-skip title="字級">'
       + '<option value="">字級</option>' + opts(DOC_SIZES, '') + '</select>';
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
    h += btn('egHr', 'minus', '水平線') + btn('egPageBreak', 'scissors', '插入分頁（列印時從這裡換頁）');
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

  /** 表格加減列欄。回傳有沒有真的做到事（沒做到＝游標不在表格內） */
  function tableOp(body, op) {
    var cell = cellOf(body);
    if (!cell) return false;
    var row = cell.parentNode, tbl = row;
    while (tbl && tbl.nodeName !== 'TABLE') tbl = tbl.parentNode;
    if (!tbl) return false;
    var idx = Array.prototype.indexOf.call(row.children, cell);

    if (op === 'egRowAdd') {
      var nr = d.createElement('tr');
      for (var i = 0; i < row.children.length; i++) nr.appendChild(newCell('td'));
      row.parentNode.insertBefore(nr, row.nextSibling);
    } else if (op === 'egRowDel') {
      // 只剩一列就不給刪整列（整張表都不見了使用者會以為系統壞掉），請他刪整張表
      if (tbl.rows && tbl.rows.length <= 1) return false;
      row.parentNode.removeChild(row);
    } else if (op === 'egColAdd') {
      Array.prototype.slice.call(tbl.rows).forEach(function (r) {
        var ref = r.children[idx];
        var nc = newCell(ref && ref.nodeName === 'TH' ? 'th' : 'td');
        r.insertBefore(nc, ref ? ref.nextSibling : null);
      });
    } else if (op === 'egColDel') {
      if (row.children.length <= 1) return false;
      Array.prototype.slice.call(tbl.rows).forEach(function (r) {
        if (r.children[idx]) r.removeChild(r.children[idx]);
      });
    } else return false;
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
  function selectedBlocks(body) {
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
        // 整個編輯區還是純文字（沒有任何區塊）→ 包一層 div 才有東西可以縮排
        var dv = d.createElement('div');
        while (body.firstChild) dv.appendChild(body.firstChild);
        body.appendChild(dv);
        out.push(dv);
      }
    }
    return out;
  }
  function indentBlocks(body, dir) {
    var blocks = selectedBlocks(body);
    blocks.forEach(function (b) {
      var cur = parseFloat((b.style.marginLeft || '').replace('em', '')) || 0;
      var nx  = Math.max(0, Math.min(INDENT_MAX, cur + dir * INDENT_STEP));
      if (nx <= 0) b.style.marginLeft = '';
      else b.style.marginLeft = nx + 'em';
    });
    return blocks.length > 0;
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
      '.egrt-pf{margin-bottom:5px;font-size:12px;color:#6B471A;}',
      '.egrt-pf label{display:inline-block;width:44px;margin:0;font-weight:normal;}',
      '.egrt-ti{width:62px;height:24px;border:1px solid #d8c7b0;border-radius:3px;padding:0 4px;font-size:12px;}',
      // 文件本體：灰底捲動區內放一張白紙，所見即所得。
      // 可編輯元素就是那張紙（.egrt-body.egrt-page），捲動容器不可編輯——
      // 兩者分開，get()/set() 才不會把捲動容器的 div 一起存進內容裡。
      '.egrt-doc-scroll{background:#efe9e0;overflow:auto;border-radius:0 0 4px 4px;}',
      '.egrt-page{background:#fff;margin:14px auto;padding:18mm 15mm;box-shadow:0 1px 6px rgba(0,0,0,.18);',
      'font-size:12pt;line-height:1.6;color:#333;min-height:0;max-height:none;overflow:visible;}',
      '.egrt-page h1{font-size:18pt;margin:0 0 10px;}',
      '.egrt-page h2{font-size:15pt;margin:14px 0 8px;}',
      '.egrt-page h3{font-size:13pt;margin:12px 0 6px;}',
      '.egrt-page h4{font-size:12pt;margin:10px 0 5px;font-weight:bold;}',
      '.egrt-page table{border-collapse:collapse;margin:6px 0;}',
      '.egrt-page td,.egrt-page th{border:1px solid #333;padding:4px;}',
      '.egrt-page img{max-width:100%;}',
      '.egrt-page hr{border:0;border-top:1px solid #bbb;margin:10px 0;}',
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

    host.classList.add('egrt-wrap');
    if (isDoc) {
      // 捲動容器與可編輯的「紙」分開（見 .egrt-doc-scroll / .egrt-page 的註解）
      host.innerHTML = docToolbarHtml(opt)
        + '<div class="egrt-doc-scroll" style="max-height:' + (parseInt(opt.height, 10) || 560) + 'px">'
        + '<div class="egrt-body egrt-page" contenteditable="true" data-eg-skip'
        + ' style="width:' + escAttr(opt.pageWidth || '180mm') + '"'
        + ' data-ph="' + escAttr(opt.placeholder || '') + '"></div></div>'
        + (opt.maxLen ? '<div class="egrt-count"></div>' : '');
      host.style.position = host.style.position || 'relative';   // 圖片浮動工具列以它為定位父層
    } else {
      host.innerHTML = toolbarHtml()
        + '<div class="egrt-body" contenteditable="true" data-eg-skip data-ph="' + escAttr(opt.placeholder || '') + '"></div>'
        + (opt.maxLen ? '<div class="egrt-count"></div>' : '');
    }

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
      ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList',
       'justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'].forEach(function (c) {
        var b = host.querySelector('.egrt-btn[data-cmd="' + c + '"]');
        if (!b) return;
        var on = false;
        try { on = d.queryCommandState(c); } catch (e) {}
        b.classList.toggle('on', !!on);
      });
      if (!isDoc) return;
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
    function insertHtml(html) {
      body.focus();
      try { d.execCommand('insertHTML', false, html); } catch (e) {}
      changed();
    }
    /** 字級：execCommand('fontSize') 只吃 1~7，所以先用 size=7 當記號標出選取範圍，
     *  再把那些 <font size="7"> 換成 <span style="font-size:14pt">（<font> 不在白名單、存檔會被脫殼）。
     *  這是處理「任意跨節點選取」最可靠的做法，自己用 range.surroundContents() 會在
     *  選取只覆蓋部分節點時直接丟例外。 */
    function applyFontSize(val) {
      body.focus();
      if (!val) return;
      try { d.execCommand('styleWithCSS', false, false); } catch (e) {}
      try { d.execCommand('fontSize', false, '7'); } catch (e) {}
      Array.prototype.slice.call(body.querySelectorAll('font[size="7"]')).forEach(function (f) {
        var sp = d.createElement('span');
        sp.style.fontSize = val;
        while (f.firstChild) sp.appendChild(f.firstChild);
        f.parentNode.replaceChild(sp, f);
      });
      changed();
    }
    /** 依資產編號把 <img> 的 src 補回來。
     *  src 刻意不存進內容（見 richtext_lib.php 的說明），所以每次帶入內容都要補一次。 */
    function hydrate() {
      if (!isDoc || !opt.assetUrl) return;
      Array.prototype.slice.call(body.querySelectorAll('img[data-asset]')).forEach(function (im) {
        var id = im.getAttribute('data-asset');
        im.setAttribute('src', opt.assetUrl(id));
        im.setAttribute('draggable', 'false');
        im.setAttribute('alt', '');
      });
    }

    function exec(cmd, val) {
      body.focus();
      // 縮排是自己實作的（見 indentBlocks），不走 execCommand
      if (cmd === 'egIndent' || cmd === 'egOutdent') {
        indentBlocks(body, cmd === 'egIndent' ? 1 : -1);
        changed();
        return;
      }
      if (isDoc) {
        if (cmd === 'egRowAdd' || cmd === 'egRowDel' || cmd === 'egColAdd' || cmd === 'egColDel') {
          if (!tableOp(body, cmd)) {
            alert(cmd === 'egRowDel' ? '只剩一列了，不能再刪。要整張表拿掉請把表格選起來按 Delete。'
                : cmd === 'egColDel' ? '只剩一欄了，不能再刪。'
                : '請先把游標點進表格裡的任一個儲存格。');
            return;
          }
          changed();
          return;
        }
        if (cmd === 'egHr')        { insertHtml('<hr>'); return; }
        if (cmd === 'egPageBreak') { insertHtml('<hr style="page-break-after:always">'); return; }
        if (cmd === 'egImage')     { if (opt.onInsertImage) opt.onInsertImage(insertAsset); return; }
        if (cmd === 'egFlow')      { if (opt.onInsertFlow)  opt.onInsertFlow(insertAsset);  return; }
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
      Array.prototype.slice.call(body.querySelectorAll('img[data-asset="' + id + '"]')).forEach(function (im) {
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
      body.addEventListener('click', function (e) {
        if (e.target && e.target.nodeName === 'IMG') { selectImg(e.target); e.stopPropagation(); }
        else clearImgSel();
      });
      body.addEventListener('dblclick', function (e) {
        // 雙擊流程圖＝直接開編輯器（跟 Word 雙擊圖表一樣）
        if (e.target && e.target.nodeName === 'IMG' && opt.onEditFlow) {
          var id = e.target.getAttribute('data-asset');
          if (!opt.assetKind || opt.assetKind(id) === 'flow') {
            opt.onEditFlow(id, function () { reloadAsset(id); });
          }
        }
      });
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
      var ins = html ? clean(html, prof) : (dt.getData('text/plain') || '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\r?\n/g, '<br>');
      try { d.execCommand('insertHTML', false, ins); } catch (err) {}
      refreshCount();
      if (opt.onChange) opt.onChange();
    });
    body.addEventListener('input', function () { refreshCount(); if (opt.onChange) opt.onChange(); });
    body.addEventListener('keyup', refreshState);
    body.addEventListener('mouseup', refreshState);

    var api = {
      host: host, body: body, profile: prof,
      // get() 回傳的內容裡 <img> 已經沒有 src（清洗時剝掉），存進 DB 的永遠只有資產編號
      get: function () { return clean(body.innerHTML, prof); },
      set: function (html) {
        body.innerHTML = clean(html, prof);
        hydrate();            // 把 <img> 的 src 依資產編號補回來才看得到圖
        clearImgSel();
        refreshCount(); refreshState();
      },
      text: function () { return toText(body.innerHTML); },
      focus: function () { body.focus(); },
      over: function () { return maxLen > 0 && toText(body.innerHTML).length > maxLen; },
      maxLen: maxLen,
      /** 讓模組頁面在上傳/編輯完之後把圖插進來或重新載入 */
      insertAsset: function (id, o) { insertAsset(id, o); },
      reloadAsset: function (id) { reloadAsset(id); },
      hydrate: function () { hydrate(); },
      /** 內容目前引用到哪些資產編號（存檔時要據此清掉沒在用的資產） */
      assetIds: function () {
        return Array.prototype.slice.call(body.querySelectorAll('img[data-asset]'))
          .map(function (im) { return parseInt(im.getAttribute('data-asset'), 10); })
          .filter(function (n) { return n > 0; });
      }
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
