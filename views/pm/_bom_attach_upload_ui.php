<?php
/**
 * Upload_List.php「ERP 匯入」分頁的「BOM 附件上傳」區塊（獨立元件，不碰其他匯入表單）。
 * 後端：src/store/BomAttachUpload_API.php；存到 part_viewer.php 讀取的 ERP/資材 BOM 資料夾。
 */
?>
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
    <table class="table table-condensed" style="background:#fff;margin-bottom:6px">
      <thead><tr><th>檔案</th><th style="width:170px">BOM 號碼</th><th style="width:170px">標籤</th><th style="width:210px">狀態</th></tr></thead>
      <tbody id="bauBody"></tbody>
    </table>
    <button type="button" id="bauUpload" class="btn btn-success">上傳全部</button>
    <span id="bauMsg" style="margin-left:10px"></span>
  </div>
</div>
<script>
(function () {
  var API = '../../src/store/BomAttachUpload_API.php';
  var files = [], rows = [], tags = [];
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
      var st = r.state || '';
      h += '<tr data-i="' + i + '"><td style="word-break:break-all">' + esc(r.name) + '</td>';
      if (r.ok) {
        h += '<td>' + esc(r.bom) + '</td><td><span class="label label-success">' + esc(r.label) + '</span></td>';
      } else {
        h += '<td><input type="text" class="form-control input-sm bau-bom" data-i="' + i + '" value="' + esc(r.bom) + '" placeholder="B-1xxxxxxxxx"></td><td>' + tagSel(i, r.suffix) + '</td>';
      }
      h += '<td class="bau-st">' + (st ? st : (r.ok ? '<span class="text-success">可自動上傳</span>' : '<span style="color:#DD5138">' + esc(r.reason) + '，請手動指定</span>')) + '</td></tr>';
    });
    document.getElementById('bauBody').innerHTML = h;
  }
  document.getElementById('bauAnalyze').addEventListener('click', function () {
    var inp = document.getElementById('bauFile');
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
      document.getElementById('bauList').style.display = 'block'; document.getElementById('bauMsg').textContent = '';
    }).catch(function () { alert('連線失敗，請稍後再試'); });
  });
  document.getElementById('bauBody').addEventListener('input', function (e) { if (e.target.classList.contains('bau-bom')) rows[+e.target.dataset.i].bom = e.target.value; });
  document.getElementById('bauBody').addEventListener('change', function (e) { if (e.target.classList.contains('bau-suffix')) rows[+e.target.dataset.i].suffix = e.target.value; });
  document.getElementById('bauUpload').addEventListener('click', function () {
    var btn = this, todo = [];
    rows.forEach(function (r, i) {
      if (r.done) return;
      if (!r.ok && (!String(r.bom || '').trim() || !r.suffix)) { r.state = '<span style="color:#DD5138">請填 BOM 號碼並選擇標籤</span>'; return; }
      todo.push(i);
    });
    render();
    if (!todo.length) { document.getElementById('bauMsg').textContent = '沒有可上傳的檔案'; return; }
    btn.disabled = true;
    var okN = 0, n = 0;
    (function next() {
      if (n >= todo.length) { btn.disabled = false; document.getElementById('bauMsg').textContent = '完成：成功 ' + okN + ' / ' + todo.length + ' 個'; return; }
      var i = todo[n++], r = rows[i], fd = new FormData();
      fd.append('file', files[i]);
      if (!r.ok) { fd.append('bom', r.bom); fd.append('suffix', r.suffix); }
      post('save', fd).then(function (res) {
        if (res.ok) { r.done = true; okN++; r.state = '<span class="text-success">已上傳 → ' + esc(res.file) + '（' + esc(res.label) + '）</span>'; }
        else r.state = '<span style="color:#DD5138">' + esc(res.msg || '失敗') + '</span>';
      }).catch(function () { r.state = '<span style="color:#DD5138">連線失敗</span>'; }).then(function () {
        document.querySelector('#bauBody tr[data-i="' + i + '"] .bau-st').innerHTML = r.state; next();
      });
    })();
  });
})();
</script>
