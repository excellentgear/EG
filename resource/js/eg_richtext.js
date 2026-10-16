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
  // span 的那一組＝AS 文件參照標記（見後端 EG_RT_DOC_ATTRS 的說明，兩邊必須一致）
  var DOC_ATTRS = { 'TD': ['colspan','rowspan'], 'TH': ['colspan','rowspan'],
                    'IMG': ['data-asset'], 'COL': ['span'],
                    'SPAN': ['class','data-asdoc','data-asno','data-asname','data-asmode','data-astpl'] };
  var DOC_ATTR_PAT = { 'colspan': /^([1-9]|[1-9]\d)$/, 'rowspan': /^([1-9]|[1-9]\d)$/,
                       'span': /^([1-9]|[1-9]\d)$/, 'data-asset': /^[1-9]\d{0,9}$/,
                       'class': /^eg-asref$/, 'data-asdoc': /^[1-9]\d{0,9}$/,
                       'data-asno': /^[0-9A-Za-z\-_.\/]{1,40}$/,
                       'data-asname': /^[^<>\r\n]{1,120}$/,
                       'data-asmode': /^(both|name|no)$/,
                       'data-astpl': /^[^<>\r\n]{1,60}$/ };
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
  /* 英數字型：只套在英文／數字上（排在 font-family 最前面，中文字它沒有，瀏覽器自動往後找到中文字型）。
     值只放字型名稱，組合成字型堆疊由 composeFont() 做。 */
  var EN_FONTS = [['', '英數＝同中文'], ['Arial', 'Arial'], ['Calibri', 'Calibri'], ['Times New Roman', 'Times New Roman'],
                  ['Verdana', 'Verdana'], ['Tahoma', 'Tahoma'], ['Georgia', 'Georgia'], ['Courier New', 'Courier New']];
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
    // 只放行 inline-block（句尾文件編號對齊用），none/list-item 一律不收
    'display':           /^inline-block$/i,
    'margin':            /^(\d{1,2}(\.\d{1,2})?(px|em|cm|mm)\s*){1,4}$/,
    'margin-right':      /^(auto|0|\d{1,2}(\.\d{1,2})?(em|px|cm|mm))$/,
    'margin-left':       /^(auto|0|\d{1,2}(\.\d)?em)$/,
    // 圖片／流程圖區塊在畫面上自由移動：只放行 relative 位移（不脫離文字流、不會蓋掉整頁）
    'position':          /^relative$/i,
    'left':              /^-?\d{1,4}px$/,
    'top':               /^-?\d{1,4}px$/,
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
    h += '<select class="egrt-sel egrt-sel-font" data-eg-skip title="中文字型：選取文字後這裡會顯示它現在用的字型">' + opts(DOC_FONTS, '') + '</select>';
    h += '<select class="egrt-sel egrt-sel-fonten" data-eg-skip title="英數字型：只改選取文字裡的英文與數字，中文仍用左邊的字型">' + opts(EN_FONTS, '') + '</select>';
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
       + btn('egNumIndent', 'sort-numeric-asc', '依編號自動排版：整份文件重新掃一遍。'
           + '段落開頭是 1. / 1.1 / 1.1.1 這種階層編號的，依「.」的段數自動排縮、'
           + '對齊數字後的內容，並在編號與文字之間補一個空格；'
           + '句尾是 (2-MM-01) 這種文件編號的連續幾段，括號也會對齊到同一欄')
       + btn('egAlignParen', 'columns', '括號對齊：先用滑鼠選取要對齊的那幾列（例如 8.1～8.3 的相關文件清單），'
           + '按這顆，每一列第一個「(」會對齊到同一欄；不動文字內容，AS 文件參照的標記也不會被破壞。'
           + '想取消就把那幾列的括號前面退格刪掉再重按')
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
    /* 插入 AS 文件編號／名稱（2026-10-15 使用者交辦）。
       刻意不用純圖示：這顆鈕一眼要看得出是「AS 文件」，所以圖示後面直接寫 AS。 */
    if (opt.onInsertAsRef)
      h += '<button type="button" class="egrt-btn" data-cmd="egAsRef" style="font-weight:bold"'
         + ' title="插入 AS 文件編號／名稱：可打編號或名稱搜尋，也可以依部門與階別挑選；'
         + '插入後那份文件改了編號或名稱，這裡會自動提醒你確認">'
         + '<i class="fa fa-plus"></i> AS</button>';
    h += btn('egHr', 'minus', '水平線');
    h += '<span class="egrt-sep"></span>'
       + btn('egHardBreak', 'level-down', '強制換頁（像 Word 的「段落前分頁」）：游標所在的那一段（例如「8. 相關文件」標題）'
           + '固定從新的一頁開始，之後自動分頁、刪字回流都不會把它拉回上一頁。再按一次取消。'
           + '有強制換頁的那一段右上角會標「強制換頁」')
       + btn('egPageBreak', 'scissors', '在游標處分頁（游標後面的內容移到新的一頁；這一刀也是強制的，不會被自動回流拉回來）')
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

  /* ── 依編號自動縮排（使用者 2026-09-23）───────────────────────────────────
     「2.1、2.2 是 2. 底下，所以要自動排縮；另外 2.1、2.2 的內容也要對齊數字標籤」。
     判斷法：段落開頭若是 1. / 1.1 / 6.1.1.2 這種階層編號，用「.」分成幾段就是第幾層，
     階層每深一層縮排一級（跟手動按縮排鈕用同一個 INDENT_STEP，兩者才不會兜不起來）；
     同一段落換行後要對齊「數字後面的內容」不是對齊數字本身，靠吊掛縮排做到
     （padding-left 把整段推進去、text-indent 用等量負值只把第一行往回拉，
     第一行因此停在「數字」的位置、換行後的每一行都停在「內容」的位置）。
     吊掛的寬度**用 canvas 實際量字寬**，不用「一個字幾 px」用猜的——
     這份文件是中西文混排（標楷體/Times New Roman 交錯），猜的話寬度一定兜不準。 */
  // 匯入的文件常常沒有在編號後面留空白（「2.範圍：」「2.1分發」都直接接文字），
  // 所以**不強制要求後面有空白**——判斷是不是編號改看「有沒有點」：
  // 只有一段數字時必須帶著句點（「2.」才算，光是「10月份」的 10 不算）；
  // 有兩段以上（「2.1」本身就帶了點）不論後面有沒有句點都算。
  var NUM_LABEL_RE = /^([\s　]*)(\d{1,2}(?:\.\d{1,2}){0,5})(\.?)/;

  /** 段落文字開頭是不是階層編號；不是就回 null，是的話回 {depth,label}（label 含編號本身、可能的句點與緊接的空白，供量寬用） */
  function numLabelMatch(text) {
    text = text || '';
    var m = NUM_LABEL_RE.exec(text);
    if (!m) return null;
    var num = m[2], depth = num.split('.').length;
    if (depth === 1 && !m[3]) return null;   // 純數字開頭又沒有點——當內文看，不當編號（避免「10月份」誤判）
    var wsAfter = /^[\s　]*/.exec(text.slice(m[0].length))[0];
    // 量寬只量「編號＋後面的空白」：開頭的空白不算（那是 Word 手排的縮排，會另外被清掉，
    // 算進去吊掛寬度就會多出一截）。後面的空白一律正規化成「瀏覽器實際顯示的樣子」——
    // LibreOffice 匯出的原始碼縮排（換行＋tab）在 HTML 只會顯示成一個空格，
    // 直接拿去 measureText 會量成一大截（實例 2-GM-03 的 6.1.1 因此比 6.1.2 多縮 1.3em）
    var wsShown = wsAfter.replace(/[ \t\r\n\f\v]+/g, ' ');
    return { depth: depth, num: num, label: m[0].slice(m[1].length) + wsShown };
  }

  var _numMeasureCanvas = null;
  /** 量這段文字（通常是「編號＋空白」）在該區塊實際字型下的寬度，回傳 {px, fontSizePx} */
  function measureLabelPx(block, label) {
    if (!_numMeasureCanvas) _numMeasureCanvas = d.createElement('canvas');
    var ctx = _numMeasureCanvas.getContext('2d');
    function fontOf(n) {
      var el = (n && n.parentNode && n.parentNode.nodeType === 1) ? n.parentNode : block;
      var cs = w.getComputedStyle(el);
      ctx.font = cs.font || (cs.fontStyle + ' ' + cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily);
      return parseFloat(cs.fontSize) || 16;
    }
    // 編號與後面的空白常常落在不同字型的 <span>（數字 Times New Roman、空白標楷體），
    // 整串用同一個字型量會量錯（標楷體的數字比較寬 → 換行對齊跑到內容右邊）。
    // 所以逐字對回實際的文字節點，每一段用自己那個節點的字型量，再加總。
    var walker = d.createTreeWalker(block, NodeFilter.SHOW_TEXT, null);
    var rest = label, px = 0, fs = 0, first = true, n;
    while (rest && (n = walker.nextNode())) {
      var v = n.nodeValue || '', took = '';
      for (var i = 0; i < v.length && rest; i++) {
        var c = v.charAt(i), isWs = /[\s 　]/.test(c), rw = /[\s 　]/.test(rest.charAt(0));
        if (c === rest.charAt(0) || (isWs && rw)) { took += c; rest = rest.slice(1); }
        else if (took === '' && isWs) continue;      // 編號前被清掉／折疊的空白
        else if (isWs) continue;                    // 被折疊掉的多餘空白
        else break;
      }
      if (took) {
        var f = fontOf(n);
        if (first) { fs = f; first = false; }
        px += ctx.measureText(took.replace(/[ \t\r\n\f\v]+/g, ' ')).width;
      }
    }
    if (rest) {   // 對不上（節點被拆得很碎等）：退回整串用第一個節點字型量
      var w0 = d.createTreeWalker(block, NodeFilter.SHOW_TEXT, null), n0 = w0.nextNode();
      fs = fontOf(n0);
      px = ctx.measureText(label).width;
    }
    return { px: px, fontSizePx: fs || 16 };
  }

  /** 對單一區塊套用「依編號自動縮排」。不是編號段落就不動它（不清掉別的手動縮排）。回傳有沒有套用到 */
  function applyNumIndent(block, addSpace) {
    if (!block || block.nodeType !== 1) return false;
    var m = numLabelMatch(block.textContent);
    if (!m) return false;
    // 編號與文字之間補一個空格：Word 原稿常常是「8.1人力資源管理程序」黏在一起
    //（使用者 2026-10-15）。只在按工具列那顆按鈕時補，打字當下不補——
    // 即時插字會把游標頂走，正在打的人會覺得鍵盤不受控。
    if (addSpace && addNumSpace(block)) m = numLabelMatch(block.textContent) || m;
    var level = Math.max(0, m.depth - 1);
    // 編號前面用空白（含全形／不斷行空白）手排出來的縮排一律拿掉：
    // 現在縮排由階層自動決定，兩邊都有就會縮兩次、文字位置跑掉（使用者 2026-10-15）
    var tw = d.createTreeWalker(block, NodeFilter.SHOW_TEXT, null), tn = tw.nextNode();
    if (tn && /^[\s 　]+/.test(tn.nodeValue)) {
      // 整段都是空白的節點也要清（匯入的內容常把縮排單獨包成一個 <span>），
      // 留著它第一行就多一個空格、編號跟著右移
      tn.nodeValue = tn.nodeValue.replace(/^[\s 　]+/, '');
    }
    var meas = measureLabelPx(block, m.label);
    var hangEm = Math.min(9.9, Math.max(0, Math.round((meas.px / meas.fontSizePx) * 10) / 10));
    var mgEm = Math.min(INDENT_MAX, level * INDENT_STEP);
    /* 只有一層的「1.」「2.」若是接在更深的編號（如 6.4.1）底下，它是那一條的子清單，
       編號要排在上一條「內容」的起點（垂直對齊在 6.4.1 那串字的右側），不是跟 6.4.1 的編號切齊。
       判斷：這一段是「1.」且上一個有編號的段落層級比較深＝子清單開頭；
       「2.」「3.」則接續上一個子清單項目的位置。其他（例如 6.4.1.4 之後的「7.」）仍是頂層。 */
    if (m.depth === 1) {
      var pv = block.previousElementSibling, pm = null, hops = 0;
      while (pv && hops++ < 40) {
        pm = numLabelMatch(pv.textContent);
        if (pm) break;
        pv = pv.previousElementSibling;
      }
      var myN = parseInt(m.num, 10);
      if (pv && pm && pm.depth >= 2 && myN === 1) {
        var pml = parseFloat((pv.style.marginLeft || '').replace('em', '')) || 0;
        var phang = parseFloat(((pv.style.textIndent || '').replace('-', '')).replace('em', '')) || 0;
        mgEm = Math.min(INDENT_MAX, pml + phang);
      } else if (pv && pm && pm.depth === 1 && (parseFloat((pv.style.marginLeft || '').replace('em', '')) || 0) > 0 && myN === parseInt(pm.num, 10) + 1) {
        mgEm = parseFloat((pv.style.marginLeft || '').replace('em', '')) || 0;
      }
    }
    block.style.marginLeft = mgEm ? (mgEm + 'em') : '';
    // padding-left 這個個別屬性不在白名單裡（見 richtext_lib.php），要用 padding 簡寫
    block.style.padding = '0em 0em 0em ' + hangEm.toFixed(1) + 'em';
    block.style.textIndent = '-' + hangEm.toFixed(1) + 'em';
    return true;
  }


  /** 編號與後面的文字之間補一個空格（已經有空白、或編號後面沒東西就不動）。回傳有沒有補 */
  function addNumSpace(block) {
    var m = numLabelMatch(block.textContent || '');
    if (!m) return false;
    if (/[\s\u00a0\u3000]$/.test(m.label)) return false;      // 本來就有空白
    var num = m.label;                                        // 編號本身（含可能的句點）
    var wk = d.createTreeWalker(block, NodeFilter.SHOW_TEXT, null), nd;
    var acc = '';
    while ((nd = wk.nextNode())) {
      var v = nd.nodeValue || '';
      var before = acc.replace(/^[\s\u00a0\u3000]+/, '');
      acc += v;
      var after = acc.replace(/^[\s\u00a0\u3000]+/, '');
      // 編號在這個節點裡結束，而且後面還有字 → 就在這裡插一個空格
      if (before.length < num.length && after.length >= num.length) {
        if (after.indexOf(num) !== 0) return false;           // 開頭不是這個編號，別亂改
        var cut = v.length - (after.length - num.length);
        if (cut < 0 || cut > v.length) return false;
        var rest = v.slice(cut);
        if (/^[\s\u00a0\u3000]/.test(rest)) return false;
        if (rest === '' && !wk.nextNode()) return false;      // 編號後面什麼都沒有
        nd.nodeValue = v.slice(0, cut) + ' ' + rest;
        return true;
      }
    }
    return false;
  }

  /* ── 句尾的 (AS 文件編號) 整列對齊（使用者 2026-10-15）──────────────────
     「8.1人力資源管理程序　(2-MM-01)」這種清單，每一列的括號本來就該對齊在同一欄。
     用空格補是對不齊的（網頁是比例字型，一個空格的寬度跟一個中文字差很多，
     而且每一列要補幾個空格都不一樣），所以把括號前面那段文字包成
     **固定寬度的 inline-block**，寬度取同一群裡最寬的那一段。
     只對「括號內長得像文件編號」（含 - 且有數字）的段落動手，
     一般的「(見附件)」「(備註)」不會被誤當成要對齊的清單。 */
  var AS_TAIL_RE = /^([\s\S]*?)[\s\u3000]*[(（]\s*([0-9A-Za-z][-0-9A-Za-z.]*)\s*[)）][\s\u3000]*$/;

  function asTailMatch(block) {
    if (!block || block.nodeType !== 1) return null;
    if (block.querySelector && block.querySelector('table,img,ul,ol')) return null;
    var t = (block.textContent || '').replace(/[\s\u00a0\u3000]+/g, ' ').trim();
    var m = AS_TAIL_RE.exec(t);
    if (!m) return null;
    var code = m[2];
    if (code.indexOf('-') < 0 || !/\d/.test(code)) return null;   // 要像文件編號才算
    var head = m[1].replace(/[\s\u3000]+$/, '');
    if (head === '') return null;
    return { head: head, code: code };
  }

  /** 把一群「…(編號)」段落的括號對齊：head 包成等寬 inline-block。回傳處理了幾段 */
  function alignAsTails(blocks) {
    if (!_numMeasureCanvas) _numMeasureCanvas = d.createElement('canvas');
    var ctx = _numMeasureCanvas.getContext('2d');
    var items = [], maxEm = 0;
    blocks.forEach(function (b) {
      var mm = asTailMatch(b);
      if (!mm) return;
      var wk = d.createTreeWalker(b, NodeFilter.SHOW_TEXT, null), n0 = wk.nextNode();
      var el = (n0 && n0.parentNode && n0.parentNode.nodeType === 1) ? n0.parentNode : b;
      var cs = w.getComputedStyle(el);
      ctx.font = cs.font || (cs.fontStyle + ' ' + cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily);
      var fs = parseFloat(cs.fontSize) || 16;
      var em = ctx.measureText(mm.head).width / fs;
      if (em > maxEm) maxEm = em;
      items.push({ b: b, m: mm, el: el });
    });
    if (items.length < 2) return 0;                 // 只有一列沒有「對齊」可言
    var wEm = Math.min(40, Math.round((maxEm + 1.2) * 10) / 10);
    items.forEach(function (it) {
      // 重建這一段：<span 固定寬>前段文字</span>(編號)
      // 字型字級沿用原本第一個文字節點的設定，不然重建後整段會變成預設字體
      var sp = d.createElement('span');
      sp.style.display = 'inline-block';
      sp.style.width = wEm + 'em';
      sp.textContent = it.m.head;
      var keep = (it.el && it.el !== it.b && it.el.getAttribute) ? (it.el.getAttribute('style') || '') : '';
      var outer = null;
      if (keep) { outer = d.createElement('span'); outer.setAttribute('style', keep); }
      while (it.b.firstChild) it.b.removeChild(it.b.firstChild);
      var tail = d.createTextNode('(' + it.m.code + ')');
      if (outer) { outer.appendChild(sp); outer.appendChild(tail); it.b.appendChild(outer); }
      else { it.b.appendChild(sp); it.b.appendChild(tail); }
    });
    return items.length;
  }

  /** 整份文件：把連續的「…(編號)」段落分群後各自對齊 */
  function alignAsTailsAll(root) {
    var all = Array.prototype.slice.call(root.querySelectorAll('p,div'));
    var n = 0, run = [];
    function flush() { if (run.length >= 2) n += alignAsTails(run); run = []; }
    all.forEach(function (b) {
      var pb = b.parentNode, nested = false;
      while (pb && pb !== root) {
        if (pb.nodeType === 1 && /^(DIV|P|LI)$/.test(pb.nodeName)) { nested = true; break; }
        pb = pb.parentNode;
      }
      if (nested) return;
      if (asTailMatch(b)) run.push(b);
      else if ((b.textContent || '').trim() === '') { /* 中間的空行不算打斷同一群 */ }
      else flush();
    });
    flush();
    return n;
  }

  /** 整份文件（doc profile）逐區塊套用一次，回傳套到幾段 */
  function applyNumIndentAll(root, addSpace) {
    var n = 0;
    Array.prototype.slice.call(root.querySelectorAll('div,p,li')).forEach(function (b) {
      // 只對「最外層」的區塊做——巢狀在別的區塊裡面的（縮排時包出來的那層）不用重複量
      var parentBlock = b.parentNode;
      while (parentBlock && parentBlock !== root) {
        if (parentBlock.nodeType === 1 && /^(DIV|P|LI)$/.test(parentBlock.nodeName)) return;
        parentBlock = parentBlock.parentNode;
      }
      if (applyNumIndent(b, addSpace)) n++;
    });
    return n;
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
      /* 內距要吃公版的大框設定（上下 body_pad、左右 N 個空格），與列印版同一條規則，
         換行與換頁位置才一致；原本寫死 padding:0，編輯器裡文字會緊貼框線。
         ⚠ 不能直接刪掉：.egrt-body（同一個元素也有）會把 7px 9px 帶回來。 */
      'max-height:none !important;padding:var(--adt-body-pad,0) calc(var(--adt-body-gap,0)*6pt);}',
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
      '.egrt-main > [style*="page-break-before"]{position:relative;}',
      '.egrt-main > [style*="page-break-before"]:before{content:"強制換頁";position:absolute;right:0;top:-1px;',
      'font:10px/14px sans-serif;color:#fff;background:#D6851F;padding:0 5px;border-radius:0 0 0 3px;pointer-events:none;}',
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
      syncFontRead();
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
    /* ── 字型顯示與英數字型 ──────────────────────────────────────────────
       工具列的字型下拉要跟著選取的文字顯示「現在用的是什麼」（使用者 2026-10-16：
       選取文字時看不出是什麼字體）。讀的是瀏覽器算出來的 font-family（含從公版繼承來的），
       不是只看那段文字自己有沒有設字型。選取範圍頭尾字型不同＝顯示「（混合）」。 */
    function parseFams(str) {
      var out = [], m, re = /"([^"]+)"|'([^']+)'|([^,]+)/g;
      while ((m = re.exec(String(str || '')))) out.push(String(m[1] || m[2] || m[3]).trim());
      return out;
    }
    var ZH_NAMES = DOC_FONTS.filter(function (f) { return /[一-鿿]/.test(f[0]); })
                            .map(function (f) { return f[0].match(/^"([^"]+)"/)[1]; });
    var EN_NAMES = EN_FONTS.filter(function (f) { return f[0]; }).map(function (f) { return f[0]; });
    function famsAt(node) {
      var el = node && (node.nodeType === 1 ? node : node.parentNode);
      if (!el || !body || !body.contains(el)) return null;
      return parseFams(w.getComputedStyle(el).fontFamily);
    }
    function pickName(fams, names) {
      for (var i = 0; i < fams.length; i++) if (names.indexOf(fams[i]) >= 0) return fams[i];
      return '';
    }
    function fontKey(fams) {
      if (!fams) return null;
      var zh = pickName(fams, ZH_NAMES);
      // 英數字型：只算「排在中文字型前面」的西文字型（排在後面的只是後援，不是使用者指定的）
      var en = '';
      for (var i = 0; i < fams.length; i++) {
        if (ZH_NAMES.indexOf(fams[i]) >= 0) break;
        if (EN_NAMES.indexOf(fams[i]) >= 0) { en = fams[i]; break; }
      }
      return { zh: zh, en: en };
    }
    function zhValueOf(name) {
      var v = '';
      DOC_FONTS.forEach(function (f) { if (name && f[0].indexOf('"' + name + '"') === 0) v = f[0]; });
      return v;
    }
    /** 中文字型堆疊＋英數字型 → 最後套用的 font-family */
    function composeFont(zhStack, en) {
      var base = zhStack || '"微軟正黑體","Microsoft JhengHei",sans-serif';
      return en ? ('"' + en + '",' + base) : base;
    }
    function syncFontRead() {
      var zhSel = host.querySelector('.egrt-sel-font'), enSel = host.querySelector('.egrt-sel-fonten');
      if (!zhSel && !enSel) return;
      var sel = w.getSelection();
      if (!sel || !sel.rangeCount) return;
      var rg = sel.getRangeAt(0);
      if (!body || !body.contains(rg.commonAncestorContainer)) return;
      var a = fontKey(famsAt(rg.startContainer)), b = fontKey(famsAt(rg.endContainer));
      if (!a) return;
      var mixZh = !!(b && b.zh !== a.zh), mixEn = !!(b && b.en !== a.en);
      function setSel(el, val, mix) {
        if (!el) return;
        var mo = el.querySelector('option[data-mix]');
        if (mix) {
          if (!mo) { mo = d.createElement('option'); mo.setAttribute('data-mix', '1'); mo.value = '__mix'; mo.textContent = '（混合）'; el.insertBefore(mo, el.firstChild); }
          el.value = '__mix';
        } else {
          if (mo) mo.parentNode.removeChild(mo);
          el.value = val;
          if (el.value !== val) el.value = '';
        }
      }
      setSel(zhSel, zhValueOf(a.zh), mixZh);
      setSel(enSel, a.en, mixEn);
      if (zhSel) zhSel.title = '中文字型：' + (mixZh ? '選取的文字有不同字型' : (a.zh || '預設（未指定）'));
      if (enSel) enSel.title = '英數字型：' + (mixEn ? '選取的文字有不同字型' : (a.en || '同中文字型'));
    }
    function applyFontPair(zhStack, en) {
      exec('fontName', composeFont(zhStack, en));
      refreshState();
    }
    function closePops() {
      Array.prototype.slice.call(host.querySelectorAll('.egrt-pop')).forEach(function (p) { p.classList.remove('open'); });
    }
    function changed() { refreshState(); refreshCount(); if (typeof uNote === 'function') uNote(); if (opt.onChange) opt.onChange(); }

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

    /* 開跳窗插入（圖片／流程圖／AS 文件編號）時，回來一定要插在「按下按鈕當下」的游標處。
       ⚠ 不能只靠 restoreRange() 的「目前選取還在編輯區內就不還原」判斷：
         跳窗關掉後呼叫 body.focus()，Chrome 會把游標放到那一頁的開頭，
         於是 restoreRange 以為「游標本來就在編輯區裡」而不還原——插入的位置就變成
         那一頁的最前面（使用者 2026-10-16：「插入後出現的位置跟實際游標位置不同」）。
         所以由開跳窗的指令設旗標，插入時優先還原當時記下的那一個範圍。 */
    var pendingRestore = false;
    function applySavedRange() {
      if (!lastRange) return false;
      try {
        var n0 = lastRange.startContainer;
        if (!n0 || !n0.isConnected) return false;
        var el0 = n0.nodeType === 1 ? n0 : n0.parentNode;
        var sh = el0 && el0.closest ? el0.closest('[contenteditable="true"]') : null;
        if (!sh || !host.contains(sh)) return false;
        body = sh;                       // 多頁模式：游標在哪一頁就插在哪一頁
        // preventScroll：focus() 預設會把那一頁捲進視窗，畫面就「跳一下」（使用者 2026-10-16）
        try { sh.focus({ preventScroll: true }); } catch (e2) { sh.focus(); }
        var sel = w.getSelection();
        sel.removeAllRanges();
        sel.addRange(lastRange);
        return true;
      } catch (e) { return false; }
    }
    /* 插入前後畫面不可以跳：記下視窗與各層捲動容器的捲動位置，插完還原。
       （focus、insertNode、重新分頁都可能把畫面捲到別處，使用者看到的就是焦點「跳一下」。） */
    function saveScrolls() {
      var list = [{ el: null, x: w.pageXOffset, y: w.pageYOffset }];
      var n = host;
      while (n && n !== d.documentElement) {
        if (n.scrollTop || n.scrollLeft) list.push({ el: n, x: n.scrollLeft, y: n.scrollTop });
        n = n.parentNode;
      }
      Array.prototype.slice.call(host.querySelectorAll('.egrt-doc-scroll,.egrt-wrap')).forEach(function (e) {
        list.push({ el: e, x: e.scrollLeft, y: e.scrollTop });
      });
      return list;
    }
    function restoreScrolls(list) {
      list.forEach(function (o) {
        if (!o.el) w.scrollTo(o.x, o.y);
        else { o.el.scrollLeft = o.x; o.el.scrollTop = o.y; }
      });
    }
    function insertHtml(html) {
      var scr = saveScrolls();
      try { insertHtmlInner(html); } finally {
        restoreScrolls(scr);
        // 重新分頁／清洗是延後執行的，等它做完再還原一次
        setTimeout(function () { restoreScrolls(scr); }, 0);
        setTimeout(function () { restoreScrolls(scr); }, 120);
      }
    }
    function insertHtmlInner(html) {
      var useSaved = pendingRestore; pendingRestore = false;
      if (useSaved && applySavedRange()) {
        // 已經回到原本的游標位置，直接插
      } else {
      try { body.focus({ preventScroll: true }); } catch (e3) { body.focus(); }
      if (!restoreRange()) {
        // 真的沒有游標可用（例如一打開就按插入）→ 補在內容最後，而不是開頭
        var r = d.createRange();
        r.selectNodeContents(body);
        r.collapse(false);
        var s = w.getSelection();
        s.removeAllRanges();
        s.addRange(r);
      }
      }
      /* 游標若落在一個 AS 文件參照（.eg-asref 行內標記）裡面——行尾的游標常常會被瀏覽器
         放進前一個 span 的尾端——直接插入會把新的塞進舊的裡面（巢狀），清洗時被拆開，
         最後出現的位置跟游標對不上。先把游標移到那個標記的後面再插。 */
      try {
        var sel2 = w.getSelection();
        if (sel2 && sel2.rangeCount) {
          var rg2 = sel2.getRangeAt(0);
          var nd = rg2.startContainer; nd = nd.nodeType === 1 ? nd : nd.parentNode;
          var ref2 = nd && nd.closest ? nd.closest('.eg-asref') : null;
          if (ref2 && body.contains(ref2) && /<span[^>]*eg-asref/.test(html)) {
            var after = d.createRange();
            after.setStartAfter(ref2); after.collapse(true);
            sel2.removeAllRanges(); sel2.addRange(after);
          }
        }
      } catch (e) {}
      /* AS 文件參照一律用 Range 直接插入節點，不走 execCommand('insertHTML')：
         瀏覽器的 insertHTML 會依「周圍的樣式」重組貼上的內容，游標貼著另一個參照或
         行尾時，實測會把 <span class="eg-asref" data-asdoc=…> 的 class 與 data 屬性整個丟掉，
         只留一個底色的空 span——那一段文字就不再記得「引用的是哪一份文件」，
         文件改名／改編號時就不會被偵測到，而且完全看不出來。 */
      if (/<span[^>]*eg-asref/.test(html)) {
        try {
          var selA = w.getSelection();
          var rgA = selA.getRangeAt(0);
          var tp = d.createElement('template'); tp.innerHTML = html;
          var frag = tp.content;
          var lastN = frag.lastChild;
          rgA.deleteContents();
          rgA.insertNode(frag);
          if (lastN) { rgA.setStartAfter(lastN); rgA.collapse(true); selA.removeAllRanges(); selA.addRange(rgA); }
          rememberRange();
          changed();
          return;
        } catch (e) { /* 失敗就退回下面的 execCommand */ }
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
      /* 強制換頁／括號對齊也要在 body.focus() 之前處理：body 是「最後點過的那一頁」，
         游標若在別頁（例如用鍵盤或程式移過去），body.focus() 會把游標拉到那一頁開頭，
         結果標記打到錯的段落（實測：游標在第 4 頁的「8. 相關文件」，標記卻打在第 1 頁第 1 段）。 */
      if (cmd === 'egHardBreak') { var hb = toggleHardBreak(); w.alert(hb.msg); return; }
      if (cmd === 'egAlignParen') { var an = alignSelectedParen(); w.alert(an.msg); return; }
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
        if (cmd === 'egNumIndent') {
          var got = autoNumIndent();
          var msg = got.n ? ('已依編號自動縮排 ' + got.n + ' 段（編號與文字之間補上空格）。')
                          : '這份文件裡沒有找到「1. / 1.1 / 1.1.1」這種階層編號的段落。';
          if (got.aligned) msg += '\n另外把 ' + got.aligned + ' 段句尾的 (文件編號) 對齊到同一欄。';
          w.alert(msg);
          return;
        }
        if (cmd === 'egFontUp')    { bumpFont(1);  return; }
        if (cmd === 'egFontDown')  { bumpFont(-1); return; }
        if (cmd === 'egLhUp')      { bumpLineHeight(0.1);  return; }
        if (cmd === 'egLhDown')    { bumpLineHeight(-0.1); return; }
        // 開跳窗之前先把游標位置記下來（跳窗一開選取就沒了，回來要插在原處）
        if (cmd === 'egImage')     { rememberRange(); pendingRestore = true; if (opt.onInsertImage) opt.onInsertImage(insertAsset); return; }
        if (cmd === 'egAsRef')     { rememberRange(); pendingRestore = true; if (opt.onInsertAsRef) opt.onInsertAsRef(insertHtml); return; }
        if (cmd === 'egFlow')      { rememberRange(); pendingRestore = true; if (opt.onInsertFlow)  opt.onInsertFlow(insertAsset);  return; }
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
      if (opt.onAddTextToImage)
        h += '<button type="button" data-act="addtext" title="用這張圖當底圖，疊文字上去（跟編輯流程圖同一套工具）">'
           + '<i class="fa fa-font"></i> 加文字</button>';
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
          if (act === 'addtext' && opt.onAddTextToImage) {
            // 「加文字」會另外存成一張新的流程圖資產（原圖保留在底層當底圖），
            // 這個 <img> 要改指向新資產才看得到疊了文字的結果
            opt.onAddTextToImage(id, function (newId) {
              if (!newId) return;
              im.setAttribute('data-asset', String(newId));
              reloadAsset(newId);
              selectImg(im);   // 重新整理按鈕：現在是流程圖了，「加文字」要換成「編輯流程圖」
            });
            return;
          }
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

    /* 表格工具列：點進表格就在表格左上方出現「靠左／置中／靠右」，
       寫成 margin-left/right（auto），整張表一起移動，匯入的表格不置中時用它一鍵置中 */
    var curTbl = null, tblBar = null;
    function clearTblSel() {
      curTbl = null;
      if (tblBar) tblBar.style.display = 'none';
    }
    function placeTblUi() {
      if (!curTbl || !tblBar || !curTbl.isConnected) { clearTblSel(); return; }
      var hr = host.getBoundingClientRect(), tr = curTbl.getBoundingClientRect();
      var scroll = host.querySelector('.egrt-doc-scroll');
      var sr = scroll ? scroll.getBoundingClientRect() : hr;
      if (tr.bottom < sr.top || tr.top > sr.bottom) { tblBar.style.display = 'none'; return; }
      tblBar.style.display = 'block';
      var top = tr.top - hr.top - 26;
      if (top < 2) top = 2;
      tblBar.style.top = top + 'px';
      tblBar.style.left = Math.max(2, tr.left - hr.left) + 'px';
    }
    function selectTbl(tb) {
      if (!tblBar) {
        tblBar = d.createElement('div');
        tblBar.className = 'egrt-imgbar';
        tblBar.style.display = 'none';
        tblBar.innerHTML = '<span style="font-size:11px;color:#8A5A2B;padding:0 4px;">表格位置</span>'
          + '<button type="button" data-tal="left" title="整張表靠左"><i class="fa fa-align-left"></i></button>'
          + '<button type="button" data-tal="center" title="整張表置中"><i class="fa fa-align-center"></i></button>'
          + '<button type="button" data-tal="right" title="整張表靠右"><i class="fa fa-align-right"></i></button>';
        host.appendChild(tblBar);
        tblBar.addEventListener('mousedown', function (e) { e.preventDefault(); });
        tblBar.addEventListener('click', function (e) {
          var b = e.target.closest ? e.target.closest('button') : null;
          if (!b || !curTbl) return;
          var al = b.getAttribute('data-tal');
          curTbl.style.marginLeft  = (al === 'left') ? '0' : 'auto';
          curTbl.style.marginRight = (al === 'right') ? '0' : 'auto';
          placeTblUi();
          changed();
        });
        var sc2 = host.querySelector('.egrt-doc-scroll');
        if (sc2) sc2.addEventListener('scroll', placeTblUi);
      }
      curTbl = tb;
      placeTblUi();
    }

    /** 放開滑鼠的位置落在哪一頁、該插在哪個位置（跨頁搬圖用） */
    function dropSheetAt(x, y) {
      var el = d.elementFromPoint(x, y);
      if (!el || !el.closest) return null;
      var sheet = el.closest('[contenteditable="true"]');
      if (!sheet || !host.contains(sheet)) return null;
      var rg = null;
      try {
        // caretRangeFromPoint 只有 WebKit/Blink 有；沒有就退回「放到那一頁最後面」
        if (d.caretRangeFromPoint) rg = d.caretRangeFromPoint(x, y);
        else if (d.caretPositionFromPoint) {
          var cp = d.caretPositionFromPoint(x, y);
          if (cp) { rg = d.createRange(); rg.setStart(cp.offsetNode, cp.offset); rg.collapse(true); }
        }
        if (rg && !sheet.contains(rg.startContainer)) rg = null;
      } catch (err) { rg = null; }
      return { sheet: sheet, range: rg };
    }

    function selectImg(im) {
      buildImgBar();
      clearImgSel();
      curImg = im;
      im.classList.add('egrt-img-sel');
      /* 真的把這張圖選成「選取範圍」——只加外框是看得到選不到，Ctrl+C 複製的會是空的
         （使用者 2026-10-15 回報「無法複製貼上到別的頁面」）。
         選取範圍一併讓 body 指向它所在的那一頁，貼上時才會落在正確的頁。 */
      try {
        var sheet = im.closest ? im.closest('[contenteditable="true"]') : null;
        if (sheet) body = sheet;
        var rg = d.createRange();
        rg.selectNode(im);
        var sel0 = w.getSelection();
        sel0.removeAllRanges();
        sel0.addRange(rg);
        rememberRange();
      } catch (err) {}
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
      // 「加文字」跟「編輯流程圖」是同一件事的兩個入口：還是純圖片時給「加文字」
      // （拿它當底圖疊文字），已經是流程圖之後改用上面那顆「編輯」鈕繼續調整
      var at = imgBar.querySelector('[data-act="addtext"]');
      if (at) {
        var k3 = opt.assetKind ? opt.assetKind(im.getAttribute('data-asset')) : '';
        at.style.display = (k3 === 'flow') ? 'none' : '';
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
          if (t.value && t.value !== '__mix') {
            var enS = host.querySelector('.egrt-sel-fonten');
            applyFontPair(t.value, enS && enS.value !== '__mix' ? enS.value : '');
          }
        } else if (t.classList.contains('egrt-sel-fonten')) {
          if (t.value === '__mix') return;
          // 中文字型維持現狀：優先用目前選到的那個，沒有就退回這段文字原本的中文字型（含公版）
          var zhS = host.querySelector('.egrt-sel-font');
          var zhStack = (zhS && zhS.value && zhS.value !== '__mix') ? zhS.value : '';
          if (!zhStack) {
            var rg0 = w.getSelection().rangeCount ? w.getSelection().getRangeAt(0) : null;
            var fams = rg0 ? famsAt(rg0.startContainer) : null;
            if (fams) {
              var keep = fams.filter(function (f) { return EN_NAMES.indexOf(f) < 0; });
              zhStack = keep.map(function (f) { return /^(serif|sans-serif|monospace|cursive|fantasy)$/.test(f) ? f : '"' + f + '"'; }).join(',');
            }
          }
          applyFontPair(zhStack, t.value);
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
          if (e.target && e.target.nodeName === 'IMG') { selectImg(e.target); e.stopPropagation(); clearTblSel(); }
          else {
            clearImgSel();
            var tb = e.target && e.target.closest ? e.target.closest('table') : null;
            if (tb && el2.contains(tb)) selectTbl(tb); else clearTblSel();
          }
        });
        // 圖片（含流程圖的每個區塊）按住就能在畫面上任意拖動：
        // 用 position:relative 的 left/top 位移，不影響前後文字排版，列印也照同一位置。
        el2.addEventListener('mousedown', function (e) {
          var im = e.target;
          if (e.button !== 0 || !im || im.nodeName !== 'IMG' || !im.getAttribute('data-asset')) return;
          e.preventDefault();                       // 擋掉瀏覽器內建的圖片拖曳虛影
          var sx = e.clientX, sy = e.clientY, moved = false;
          var ox = parseInt(im.style.left, 10) || 0, oy = parseInt(im.style.top, 10) || 0;
          function mv(ev) {
            var dx = ev.clientX - sx, dy = ev.clientY - sy;
            if (!moved && Math.abs(dx) + Math.abs(dy) < 4) return;
            if (!moved) { moved = true; selectImg(im); }
            im.style.position = 'relative';
            im.style.left = Math.max(-1500, Math.min(1500, ox + dx)) + 'px';
            im.style.top  = Math.max(-1500, Math.min(1500, oy + dy)) + 'px';
            placeImgUi();
          }
          function up(ev) {
            d.removeEventListener('mousemove', mv);
            d.removeEventListener('mouseup', up);
            if (!moved) return;
            /* 放開的位置在別一頁（或同一頁的別處）＝真的把節點搬過去，不是只有視覺位移：
               每一頁是各自獨立的 contenteditable，單靠 left/top 位移永遠離不開原本那一頁，
               而且 .egrt-sheet 是 overflow:hidden，推出去只會被裁掉看不見
               （使用者 2026-10-15 回報「流程圖無法移到別的頁面」）。 */
            var tgt = dropSheetAt(ev.clientX, ev.clientY);
            if (tgt && tgt.sheet) {
              var sameSpot = (tgt.sheet === el2) && !tgt.range;
              if (!sameSpot) {
                var keep = { p: im.style.position, l: im.style.left, t: im.style.top };
                im.style.position = ''; im.style.left = ''; im.style.top = '';
                try {
                  if (tgt.range) tgt.range.insertNode(im);
                  else tgt.sheet.appendChild(im);
                  body = tgt.sheet;
                  selectImg(im);
                } catch (err) {
                  // 插不進去（落在不可編輯處等）就維持原位，不要把圖弄丟
                  im.style.position = keep.p; im.style.left = keep.l; im.style.top = keep.t;
                }
              }
            }
            if (!parseInt(im.style.left, 10) && !parseInt(im.style.top, 10)) {
              im.style.position = ''; im.style.left = ''; im.style.top = '';
            }
            placeImgUi();
            changed();
          }
          d.addEventListener('mousemove', mv);
          d.addEventListener('mouseup', up);
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
      // 貼上的 <img data-asset="N"> 會被清洗掉 src（src 一律不存，見 hydrate 的說明），
      // 不補回來就是一張破圖——使用者 2026-10-15 回報「複製貼上到別的頁面」看不到東西
      if (isDoc) hydrate();
      // 即時依編號自動縮排：只掃游標目前所在的那一段（不是整份文件），
      // 打字打出「2.1」的當下那一段就自動排縮，不必等按「依編號自動縮排」。
      if (isDoc) {
        var sel = w.getSelection();
        var blk = (sel && sel.rangeCount) ? blockOf(sel.anchorNode, body) : null;
        if (blk) applyNumIndent(blk);
      }
      refreshCount();
      markOverflow();
      reflowSoon();      // 打滿一頁就自動流到下一頁（刪字之後也會把下一頁的內容拉回來）
      if (typeof uNote === 'function') uNote();
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
      // 「文件制修訂紀錄書」自己的表格已經印了文件編號／版次（使用者 2026-09-23 指定），
      // 系統頁尾（頁碼＋AS編號版次）在這一頁不印，跟列印版（as_doc_print.php）同規則
      ft.innerHTML = (sp.key === 'revlog') ? '' : ((chrome && chrome.ftr) || '');
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
      /* 頁首是每次都整段 innerHTML 重寫的（fillChrome），所以「太長自動縮字」
         一定要在這裡再跑一次，不然重新分頁之後就被打回原本的字級。 */
      if (w.EGDocFit) w.EGDocFit.run(pagesBox || host);
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

    /* ── 強制換頁（使用者 2026-10-16：像 Word 一樣，「8. 相關文件」要固定從新的一頁開始）──
       標記就是區塊自己的 style="page-break-before:always"（本來就在清洗白名單裡，
       存檔／重開／列印都認得，不必新增任何資料格式）。
       回流有兩個動作要配合：①往後推時遇到有標記的區塊，它與後面的內容整批搬到下一頁；
       ②往前拉時絕不把有標記的區塊拉回上一頁。沒有這兩條，手動分頁一按自動分頁就被拉回去
       （「8.」每次載入都跳回上一頁就是這個原因）。 */
    function isHard(el) {
      return !!(el && el.nodeType === 1 && /(page-break-before\s*:\s*always|break-before\s*:\s*page)/i.test(el.getAttribute('style') || ''));
    }
    /* ⚠ 不能用 el.style.pageBreakBefore = 'always'：Chrome 會把它改寫成新語法
       「break-before: page」存進 style 屬性，而清洗白名單只認舊的 page-break-before，
       存檔時整個標記被剝掉（重開就不見）。一律直接改 style 屬性字串。 */
    function setHard(el, on) {
      var st = String(el.getAttribute('style') || '')
        .replace(/(^|;)\s*(page-break-before|break-before)\s*:[^;]*/gi, '$1')
        .replace(/^[\s;]+|[\s;]+$/g, '');
      if (on) st = (st ? st + ';' : '') + 'page-break-before:always';
      if (st) el.setAttribute('style', st); else el.removeAttribute('style');
    }
    function topBlockOf(node, sheet) {
      var n = (node && node.nodeType === 3) ? node.parentNode : node;
      while (n && n.parentNode !== sheet) n = n.parentNode;
      return (n && n.parentNode === sheet) ? n : null;
    }
    /* Word 文件常用「連按好幾個空白段落」把標題頂到下一頁。改成強制換頁之後，
       那幾個空白段落還留在上一頁底部，一超出就被推到下一頁變成「只有一個空行的空白頁」
       （使用者 2026-10-16：強制換頁後存檔重新載入多出一頁空白）。
       強制換頁的前一頁，尾端的空白段落一律移除（至少留一個區塊）。 */
    function isBlankBlock(b) {
      if (!b || b.nodeType !== 1) return false;
      if (b.querySelector && b.querySelector('img,table,hr,ul,ol,[data-asset],.eg-asref')) return false;
      return String(b.textContent || '').replace(/[\s 　]/g, '') === '';
    }
    function trimBlanksBeforeHard() {
      var ss = sheets();
      for (var i = 0; i < ss.length - 1; i++) {
        var nk = blocksOf(ss[i + 1])[0];
        if (!isHard(nk)) continue;
        var ks = blocksOf(ss[i]), lastBlank = null;
        while (ks.length && isBlankBlock(ks[ks.length - 1])) { lastBlank = ks.pop(); ss[i].removeChild(lastBlank); }
        if (!ks.length) {
          if (i > 0) {
            // 這一頁剩下的全是空白段落＝一張空白頁，直接整頁拿掉（強制換頁後面本來就會另起新頁）
            var wp = wrapOf(ss[i]);
            if (wp && wp.parentNode) wp.parentNode.removeChild(wp);
            ss = sheets(); i--;
          } else if (lastBlank) ss[i].appendChild(lastBlank);     // 第一頁永遠留著
        }
      }
    }
    /** 把有強制換頁標記、卻不在頁首的區塊連同後面的內容搬到新的一頁 */
    function enforceHardBreaks(from) {
      var guard = 0;
      for (var i = Math.max(0, from || 0); i < sheets().length && guard < 500; i++) {
        var s = sheets()[i], ks = blocksOf(s), k;
        for (k = 1; k < ks.length; k++) if (isHard(ks[k])) break;
        if (k >= ks.length) continue;
        guard++;
        var rest = ks.slice(k);
        var nx = nextSheet(s, false);
        // 下一頁的第一個區塊本身也是強制換頁的話，不可以把搬過去的內容塞在它前面（它會落在頁中間）→ 另開一頁
        if (!nx || isHard(blocksOf(nx)[0])) {
          var ssAll = sheets();
          nx = mkSheet('', (i + 1 < ssAll.length) ? ssAll[i + 1] : null);
          nx.innerHTML = '';
        }
        for (var m = rest.length - 1; m >= 0; m--) prependBlock(nx, rest[m]);
      }
      trimBlanksBeforeHard();
    }
    /** 工具列「強制換頁」：游標所在的那一段加上／拿掉標記 */
    function toggleHardBreak() {
      var selx = w.getSelection();
      // 以「游標實際所在的那一頁」為準，不依賴 body（body 只在點擊／聚焦時才更新）
      var s = null;
      if (selx && selx.rangeCount) sheets().forEach(function (x) { if (!s && x.contains(selx.anchorNode)) s = x; });
      if (!s) s = (body && body.classList && body.classList.contains('egrt-body')) ? body : sheets()[0];
      var blk = (selx && selx.rangeCount) ? topBlockOf(selx.anchorNode, s) : null;
      if (!blk) return { ok: false, msg: '請先把游標放在要「從新的一頁開始」的那一段（例如「8. 相關文件」標題）裡。' };
      if (isHard(blk)) {
        setHard(blk, false);
        reflow(0); changed();
        return { ok: true, msg: '已取消這一段的強制換頁（自動分頁時可能會回到上一頁）。' };
      }
      setHard(blk, true);
      reflow(0); changed();
      try { blk.scrollIntoView({ block: 'nearest' }); } catch (e) {}
      return { ok: true, msg: '這一段已設為強制換頁：固定從新的一頁開始，自動分頁不會把它拉回上一頁。' };
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

      enforceHardBreaks(start);
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
          if (isHard(node)) break;                              // 強制換頁的區塊不拉回上一頁
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
      trimBlanksBeforeHard();      // ①②搬動之後才會出現新的「強制換頁前的尾端空白」，要再清一次
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

    /** 工具列的「依編號自動縮排」：整份文件重新掃一遍套用，並重新分頁
     *  （縮排改變了換行位置，某幾頁可能因此變多或變少列，要重排才會準）。 */
    /* 括號對齊（使用者 2026-10-16）：選取幾列後，把每一列第一個「(」對到同一欄。
       做法是在「(」前面插一個空的 inline-block 墊片、寬度＝最右那一列的位置減掉自己的位置——
       **不重建段落**：既有的 alignAsTails 會把整段清掉重組，AS 文件參照的 class／data 標記
       也一起被洗掉；墊片只是多插一個節點，文字、參照、字型樣式都原封不動。
       每次先清掉上一次的墊片再量，所以可以重複按（改了文字再按一次就重新對齊）。 */
    function isLeafBlock(b) { return !b.querySelector('p,div,li,table,ul,ol'); }
    function alignSelectedParen() {
      var sel = w.getSelection(), rg = null;
      if (sel && sel.rangeCount && host.contains(sel.getRangeAt(0).commonAncestorContainer)) rg = sel.getRangeAt(0);
      else if (lastRange) rg = lastRange;
      if (!rg || rg.collapsed) return { n: 0, msg: '請先用滑鼠選取要對齊的那幾列（至少兩列），再按「括號對齊」。' };
      var blocks = [];
      sheets().forEach(function (sh) {
        Array.prototype.slice.call(sh.querySelectorAll('p,div,li')).forEach(function (b) {
          if (isLeafBlock(b) && rg.intersectsNode(b)) blocks.push(b);
        });
      });
      // 先清掉舊墊片
      blocks.forEach(function (b) {
        Array.prototype.slice.call(b.querySelectorAll('span[data-egpad],span[style*="inline-block"]')).forEach(function (sp) {
          if (sp.textContent === '' && sp.children.length === 0) sp.parentNode.removeChild(sp);
          else if (!sp.className && /inline-block/.test(sp.getAttribute('style') || '') && /width/.test(sp.getAttribute('style') || '')) {
            /* 舊的「依編號自動排版」會把括號前的文字包成固定寬度的 inline-block——
               那個盒子的右緣不是括號的自然位置，留著的話量出來的「最右一列」是假的、
               整群就對不到真正最遠的那一列（使用者 2026-10-16 回報）。先拆成一般文字再量。 */
            while (sp.firstChild) sp.parentNode.insertBefore(sp.firstChild, sp);
            sp.parentNode.removeChild(sp);
          }
        });
        b.normalize();
      });
      var rows = [];
      blocks.forEach(function (b) {
        var wk = d.createTreeWalker(b, NodeFilter.SHOW_TEXT, null), nd, prev = [];
        while ((nd = wk.nextNode())) {
          var i = nd.nodeValue.search(/[(（]/);
          if (i >= 0) {
            var hasHead = (nd.nodeValue.slice(0, i).replace(/[\s　 ]+/g, '') !== '') || prev.some(function (x) { return x.nodeValue.replace(/[\s　 ]+/g, '') !== ''; });
            if (!hasHead) return;                     // 括號前面沒字（整列從括號開始）不處理
            // 括號前面的空白一律先收掉（原本靠空格湊的位置沒有意義）
            var j = i, t = nd.nodeValue;
            while (j > 0 && /[\s　 ]/.test(t[j - 1])) j--;
            var rightNode = d.createTextNode(t.slice(i));
            nd.nodeValue = t.slice(0, j);
            nd.parentNode.insertBefore(rightNode, nd.nextSibling);
            if (j === 0) {                              // 空白連到這個節點的開頭：往前一個文字節點繼續收
              for (var k = prev.length - 1; k >= 0; k--) {
                var pv = prev[k].nodeValue, e2 = pv.length;
                while (e2 > 0 && /[\s　 ]/.test(pv[e2 - 1])) e2--;
                prev[k].nodeValue = pv.slice(0, e2);
                if (e2 > 0) break;
              }
            }
            var spc = d.createElement('span');
            spc.setAttribute('data-egpad', '1');
            spc.style.display = 'inline-block';
            spc.style.width = '0px';
            rightNode.parentNode.insertBefore(spc, rightNode);
            rows.push({ b: b, sp: spc, node: rightNode });
            return;
          }
          prev.push(nd);
        }
      });
      if (rows.length < 2) { return { n: 0, msg: '選取範圍內沒有找到至少兩列含「(」的文字。' }; }
      // 同一頁內才互相對齊（不同頁的版面寬度各自獨立）
      var bySheet = [];
      rows.forEach(function (r) {
        var sh = r.b.closest('[contenteditable="true"]');
        var g = null;
        bySheet.forEach(function (x) { if (x.sh === sh) g = x; });
        if (!g) { g = { sh: sh, rows: [] }; bySheet.push(g); }
        g.rows.push(r);
      });
      var done = 0;
      bySheet.forEach(function (g) {
        if (g.rows.length < 2) return;
        if (!_numMeasureCanvas) _numMeasureCanvas = d.createElement('canvas');
        var mctx = _numMeasureCanvas.getContext('2d');
        /* ⚠ 頁面在視窗較窄時會被 fitPages 用 CSS zoom 縮小（pagesBox.style.zoom）：
           getBoundingClientRect 量到的是縮小後的畫面座標，而墊片寬度是寫成 CSS px、之後又會被同一個 zoom 縮一次——
           不換算的話，視窗一窄（例如只開半個螢幕）每個墊片都差 (1-zoom) 倍，括號就對不齊
           （使用者 2026-10-16：我這邊測是齊的、他那邊怎麼按都不齊）。
           用一個 100px 寬的探針量出實際縮放倍率，所有量到的畫面距離都除以它換回 CSS px。 */
        var zoom = 1;
        try {
          var pr = d.createElement('span');
          pr.style.cssText = 'display:inline-block;width:100px;height:1px;';
          g.rows[0].node.parentNode.insertBefore(pr, g.rows[0].node);
          var zw = pr.getBoundingClientRect().width;
          pr.parentNode.removeChild(pr);
          if (zw > 5 && isFinite(zw)) zoom = zw / 100;
        } catch (e) {}
        g.rows.forEach(function (r) {
          var rr = d.createRange();
          rr.setStart(r.node, 0); rr.setEnd(r.node, 1);
          var cs0 = w.getComputedStyle(r.node.parentNode);
          r.fs = parseFloat(cs0.fontSize) || 16;
          /* 對齊的是「看得見的括號墨跡」，不是字元方塊的左緣：
             全形「（」的方塊左半是空白、墨跡在方塊中段；半形「(」墨跡幾乎貼著方塊左緣，
             兩種括號並排時照方塊左緣對齊，畫面上會差半個字（使用者 2026-10-16：
             9.1~9.3 用「（」、9.4 用「(」，按了還是沒對齊）。
             墨跡位置用 canvas 量 actualBoundingBoxLeft（負值＝墨跡在原點右邊）。 */
          var ink = 0;
          try {
            mctx.font = cs0.fontStyle + ' ' + cs0.fontWeight + ' ' + cs0.fontSize + ' ' + cs0.fontFamily;
            var ch = r.node.nodeValue.charAt(0);
            var mt = mctx.measureText(ch);
            if (typeof mt.actualBoundingBoxLeft === 'number') ink = -mt.actualBoundingBoxLeft;
          } catch (e) {}
          r.ink = ink;
          // 位置換算回「未縮放」的座標：畫面座標 ÷ 縮放倍率（墨跡偏移本來就是 CSS px，不必除）
          r.x = rr.getBoundingClientRect().left / zoom + ink;
        });
        var maxX = Math.max.apply(null, g.rows.map(function (r) { return r.x; }));
        // 空隙取同群最大字級的 0.4 倍、**全群共用同一個值**：
        // 各列用自己的字級算的話，字級略有差異的列會停在不同的位置（實測差 1px 以上）
        var gap = 0.4 * Math.max.apply(null, g.rows.map(function (r) { return r.fs; }));
        g.rows.forEach(function (r) {
          var px = Math.max(0, Math.round((maxX - r.x + gap) * 10) / 10);
          r.sp.style.width = Math.min(999, px) + 'px';
          done++;
        });
      });
      changed();
      return { n: done, msg: '已把 ' + done + ' 列的「(」對齊到同一欄。' };
    }

    function autoNumIndent() {
      var n = 0, a = 0;
      sheets().forEach(function (s) { n += applyNumIndentAll(s, true); a += alignAsTailsAll(s); });
      reflow(0);
      changed();
      return { n: n, aligned: a };
    }

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
        // 這一刀是人決定的分頁，要標成強制換頁，不然下一次自動分頁就把它拉回去了
        if (move[0].nodeType === 1) setHard(move[0], true);
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

    /* ── 自己的復原／重做（使用者 2026-10-16：Ctrl+Z 返回不了幾次）──────────────
       瀏覽器內建的復原堆疊只記得「它自己做的編輯」：我們的程式只要動過 DOM
       （自動分頁搬區塊、頁首重寫、括號對齊、強制換頁、插入 AS 參照…）它就整個清掉或錯亂，
       所以打了一段字再做任何一個程式動作，之前的就都退不回去了。
       改成存「整份文件的快照」：每次改動停手 400ms 記一筆（上限 150 筆），
       Ctrl+Z／Ctrl+Y（Ctrl+Shift+Z）在編輯區內一律由這裡處理。
       快照只存這個編輯器的內容，不碰別的欄位的輸入框。 */
    var U = { stack: [], redo: [], cur: null, timer: null, busy: false, max: 150 };
    function uCaret() {
      try {
        var sel = w.getSelection();
        if (!sel || !sel.rangeCount) return null;
        var r = sel.getRangeAt(0), ss = sheets(), i;
        for (i = 0; i < ss.length; i++) if (ss[i].contains(r.startContainer)) break;
        if (i >= ss.length) return null;
        var pre = d.createRange();
        pre.selectNodeContents(ss[i]); pre.setEnd(r.startContainer, r.startOffset);
        return { sheet: i, off: pre.toString().length };
      } catch (e) { return null; }
    }
    function uSetCaret(c) {
      if (!c) return;
      try {
        var ss = sheets(), sh = ss[Math.min(c.sheet, ss.length - 1)];
        if (!sh) return;
        var wk = d.createTreeWalker(sh, NodeFilter.SHOW_TEXT, null), n, acc = 0, tgt = null, toff = 0;
        while ((n = wk.nextNode())) {
          var L = n.nodeValue.length;
          if (acc + L >= c.off) { tgt = n; toff = c.off - acc; break; }
          acc += L;
        }
        var rg = d.createRange();
        if (tgt) { rg.setStart(tgt, toff); } else { rg.selectNodeContents(sh); rg.collapse(false); }
        rg.collapse(true);
        try { sh.focus({ preventScroll: true }); } catch (e1) { sh.focus(); }
        body = sh;
        var sel = w.getSelection(); sel.removeAllRanges(); sel.addRange(rg);
      } catch (e) {}
    }
    function uSnap() { return { html: allHtml(), caret: uCaret() }; }
    function uNote() {
      if (!isDoc || U.busy) return;
      if (U.timer) clearTimeout(U.timer);
      U.timer = setTimeout(uCommit, 400);
    }
    function uCommit() {
      if (U.timer) { clearTimeout(U.timer); U.timer = null; }
      if (!isDoc || U.busy) return;
      var n = uSnap();
      if (!U.cur) { U.cur = n; return; }
      if (n.html === U.cur.html) { return; }
      U.stack.push(U.cur);
      if (U.stack.length > U.max) U.stack.shift();
      U.cur = n;
      U.redo = [];
    }
    function uApply(st) {
      U.busy = true;
      var scr = saveScrolls();
      var prevIdx = Math.max(0, sheets().indexOf(body));
      try {
        renderPages(st.html);
        clearImgSel(); refreshCount(); refreshState();
        // 起點快照沒有游標資訊：焦點一定要留在編輯區，否則下一次 Ctrl+Z／Ctrl+Y 的 keydown
        // 落在 body 上，編輯器收不到（連按復原會退到一半就沒反應）
        uSetCaret(st.caret || { sheet: prevIdx, off: 0 });
      } finally {
        U.busy = false;
        restoreScrolls(scr);
        setTimeout(function () { restoreScrolls(scr); }, 0);
        setTimeout(function () { restoreScrolls(scr); }, 150);
      }
      if (opt.onChange) opt.onChange();
    }
    function uUndo() {
      uCommit();                                  // 先把還沒記下來的最新幾個字記進去，才退得回「打字之前」
      if (!U.stack.length) return false;
      U.redo.push(U.cur);
      U.cur = U.stack.pop();
      uApply(U.cur);
      return true;
    }
    function uRedo() {
      uCommit();
      if (!U.redo.length) return false;
      U.stack.push(U.cur);
      U.cur = U.redo.pop();
      uApply(U.cur);
      return true;
    }
    host.addEventListener('keydown', function (e) {
      if (!isDoc || !(e.ctrlKey || e.metaKey) || e.altKey) return;
      var t = e.target;
      if (!t || !t.closest || !t.closest('[contenteditable="true"]') || !host.contains(t)) return;
      var k = String(e.key || '').toLowerCase();
      if (k === 'z' && !e.shiftKey)              { e.preventDefault(); uUndo(); }
      else if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); uRedo(); }
    }, true);

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
        // 整份換掉內容（載入／匯入）＝新的起點，不能讓 Ctrl+Z 退回「上一份文件」
        if (isDoc) { U.stack = []; U.redo = []; U.cur = null; if (U.timer) { clearTimeout(U.timer); U.timer = null; } setTimeout(function () { if (!U.cur) U.cur = uSnap(); }, 700); }
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
      /** 工具列「依編號自動縮排」的無提示版本，供呼叫端（如匯入完成後）直接觸發 */
      autoNumIndent: function () { return autoNumIndent(); },
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
    // 復原的起點：內容載入完（含第一次回流）之後的樣子，之後的每一步都退得回這裡
    setTimeout(function () { if (!U.cur) U.cur = uSnap(); }, 700);
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
