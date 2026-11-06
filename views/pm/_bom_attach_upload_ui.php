<?php
/**
 * Upload_List.php「ERP 匯入」分頁的「BOM 附件上傳」區塊（獨立元件，不碰其他匯入表單）。
 * 後端：src/store/BomAttachUpload_API.php；存到 part_viewer.php 讀取的 ERP/資材 BOM 資料夾。
 */
?>
<style>
#bauBox .bau-tbl { width: 66%; min-width: 640px; background: #fff; margin-bottom: 6px; font-size: 12px; }
#bauBox .bau-tbl th, #bauBox .bau-tbl td { padding: 3px 6px !important; vertical-align: middle !important; }
#bauBox .bau-tbl td span, #bauBox .bau-tbl th span { line-height: 1.3; }
#bauBox .bau-tbl .form-control { height: 24px; padding: 1px 6px; font-size: 12px; }
#bauBanner { display: none; margin-top: 10px; padding: 12px 16px; border-radius: 6px; font-size: 16px; font-weight: bold; }
#bauBanner.ok   { background: #2E7D32; color: #fff; border: 2px solid #1B5E20; }
#bauBanner.warn { background: #DD5138; color: #fff; border: 2px solid #A8351F; }
#bauBanner small { display: block; font-weight: normal; font-size: 12px; margin-top: 4px; opacity: .95; }
</style>
<div id="bauBox" style="background:#FDF0DC;border:2px solid #E4C293;border-radius:6px;padding:8px 12px;margin-bottom:10px">
  <div class="form-horizontal form-label-left">
  <div class="item form-group" style="margin-bottom:0">
    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="bauFile">
      <b>BOM 附件上傳</b> <small>(PDF／圖片，可一次多檔)</small><br>
      <span class="text-muted" style="font-size:11px;">檔名開頭為 BOM 號碼＋標籤後綴（如 B-1150915010-T.pdf）即自動標籤；<br>解析不出的檔案會請你手動指定 BOM 與標籤</span>
    </label>
    <div class="col-md-4 col-sm-4 col-xs-8">
      <div class="input-group">
        <input type="file" id="bauFile" multiple accept=".pdf,.jpg,.jpeg,.png" class="form-control short-input">
        <span class="input-group-btn"><button type="button" id="bauAnalyze" class="btn btn-warning">檢查檔名</button></span>
      </div>
    </div>
  </div>
  </div>
  <div id="bauList" style="display:none;margin-top:10px">
    <table class="table table-condensed bau-tbl">
      <thead><tr><th style="width:120px">BOM 號碼</th><th style="width:110px">料號</th><th style="width:110px">標籤</th><th>檔案名稱</th><th style="width:150px">狀態</th></tr></thead>
      <tbody id="bauBody"></tbody>
    </table>
    <button type="button" id="bauUpload" class="btn btn-success">上傳全部</button>
    <span id="bauMsg" style="margin-left:10px"></span>
    <div id="bauBanner"></div>
  </div>
</div>
<script>
(function () {
  var API = '../../src/store/BomAttachUpload_API.php';
  var files = [], rows = [], tags = [];
  function el(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function post(action, fd) { fd.append('action', action); return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function tagSel(i, cur) {
    var h = '<select class="form-control input-sm bau-suffix" data-i="' + i + '" data-eg-filter="輸入標籤篩選…"><option value="">（請選擇）</option>';
    tags.forEach(function (t) { h += '<option value="' + esc(t.suffix) + '"' + (t.suffix === cur ? ' selected' : '') + '>' + esc(t.label) + '（' + esc(t.suffix) + '）</option>'; });
    return h + '</select>';
  }
  function render() {
    var h = '';
    rows.forEach(function (r, i) {
      h += '<tr data-i="' + i + '">';
      if (r.ok) {
        h += '<td>' + esc(r.bom) + '</td><td>' + esc(r.part) + '</td><td><span class="label label-success">' + esc(r.label) + '</span></td>';
      } else {
        h += '<td><input type="text" class="form-control input-sm bau-bom" data-i="' + i + '" value="' + esc(r.bom) + '" placeholder="B-1xxxxxxxxx"></td>'
           + '<td class="bau-part">' + esc(r.part) + '</td><td>' + tagSel(i, r.suffix) + '</td>';
      }
      h += '<td style="word-break:break-all">' + esc(r.name) + '</td>';
      h += '<td class="bau-st">' + (r.state ? r.state : (r.ok ? '<span class="text-success">可自動上傳</span>' : '<span style="color:#DD5138">' + esc(r.reason) + '，請手動指定</span>')) + '</td></tr>';
    });
    el('bauBody').innerHTML = h;
  }
  function banner(cls, html) { var b = el('bauBanner'); b.className = cls; b.innerHTML = html; b.style.display = 'block'; b.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  el('bauAnalyze').addEventListener('click', function () {
    var inp = el('bauFile');
    files = Array.prototype.slice.call(inp.files || []);
    if (!files.length) { alert('請選擇檔案'); return; }
    var fd = new FormData();
    fd.append('names', JSON.stringify(files.map(function (f) { return f.name; })));
    fetch(API + '?action=tags', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (t) {
      tags = t.tags || [];
      return post('analyze', fd);
    }).then(function (res) {
      if (!res.ok) { alert(res.msg || '分析失敗'); return; }
      rows = res.results;
      render();
      el('bauList').style.display = 'block'; el('bauMsg').textContent = ''; el('bauBanner').style.display = 'none';
    }).catch(function () { alert('連線失敗，請稍後再試'); });
  });
  el('bauBody').addEventListener('input', function (e) { if (e.target.classList.contains('bau-bom')) rows[+e.target.dataset.i].bom = e.target.value; });
  // 手動輸入 BOM 離開欄位時帶出料號（查無製令就即時紅字提示原因）
  el('bauBody').addEventListener('focusout', function (e) {
    if (!e.target.classList.contains('bau-bom')) return;
    var i = +e.target.dataset.i, tr = e.target.closest('tr'), fd = new FormData();
    fd.append('bom', e.target.value);
    post('bom_info', fd).then(function (res) {
      rows[i].part = res.part || '';
      tr.querySelector('.bau-part').textContent = rows[i].part;
      e.target.style.borderColor = (e.target.value.trim() === '' || res.exists) ? '' : '#DD5138';
      if (e.target.value.trim() !== '' && !res.exists) tr.querySelector('.bau-st').innerHTML = '<span style="color:#DD5138">系統查無此製令</span>';
    });
  });
  el('bauBody').addEventListener('change', function (e) { if (e.target.classList.contains('bau-suffix')) rows[+e.target.dataset.i].suffix = e.target.value; });
  el('bauUpload').addEventListener('click', function () {
    var btn = this, todo = [], skipped = 0;
    rows.forEach(function (r, i) {
      if (r.done) return;
      if (!r.ok && (!String(r.bom || '').trim() || !r.suffix)) { r.state = '<span style="color:#DD5138">請填 BOM 號碼並選擇標籤</span>'; skipped++; return; }
      todo.push(i);
    });
    render();
    if (!todo.length) { banner('warn', '沒有可上傳的檔案<small>' + (skipped ? '還有 ' + skipped + ' 個檔案需要先填 BOM 號碼並選擇標籤' : '') + '</small>'); return; }
    btn.disabled = true; el('bauBanner').style.display = 'none';
    var okN = 0, n = 0;
    (function next() {
      if (n >= todo.length) {
        btn.disabled = false; el('bauMsg').textContent = '';
        var failN = todo.length - okN + skipped;
        if (failN === 0) banner('ok', '✔ 上傳完成：共 ' + okN + ' 個檔案已存入 BOM 資料夾<small>可到「圖面查閱」的 ERP/資材報告確認標籤</small>');
        else banner('warn', '⚠ 已上傳 ' + okN + ' 個，仍有 ' + failN + ' 個未上傳<small>請看下方列表的紅字原因，修正後再按「上傳全部」（已成功的不會重複上傳）</small>');
        return;
      }
      var i = todo[n++], r = rows[i], fd = new FormData();
      el('bauMsg').textContent = '上傳中 ' + n + ' / ' + todo.length + ' …';
      fd.append('file', files[i]);
      if (!r.ok) { fd.append('bom', r.bom); fd.append('suffix', r.suffix); }
      post('save', fd).then(function (res) {
        if (res.ok) { r.done = true; okN++; r.state = '<b style="color:#2E7D32">✔ 已上傳</b><br><span style="font-size:11px">' + esc(res.file) + '（' + esc(res.label) + '）</span>'; }
        else r.state = '<b style="color:#DD5138">✘ ' + esc(res.msg || '失敗') + '</b>';
      }).catch(function () { r.state = '<b style="color:#DD5138">✘ 連線失敗</b>'; }).then(function () {
        el('bauBody').querySelector('tr[data-i="' + i + '"] .bau-st').innerHTML = r.state; next();
      });
    })();
  });
})();
</script>
