<?php
// =============================================================================
// views/common/attachment_ui.php — 附件標籤/轉檔/預覽 共用前端元件
// 使用方式（頁面需已載入 jQuery + Bootstrap 3）：
//   <?php include '../common/attachment_ui.php'; ? >
//   EGAtt.setup({ scope:'announcement'|'abnormal', api:'<該頁附件API>', tagApi:'../../src/store/attachment_tag_API.php' });
//   EGAtt.process(file, extraParams, function(item){...done...}, function(msg){...fail...});
//
// 提供：
//   - 標籤快取載入 EGAtt.loadTags / 下拉選項 EGAtt.tagOptionsHtml
//   - 標籤管理跳窗（僅主管可開）EGAtt.openTagManager()
//   - 上傳流程：驗證 → (Excel 選工作表) → 轉 PDF → 預覽確認/取消重傳 → 完成
//     item 回傳格式：{kind:'committed', id, file_name, ...} 或 {kind:'pending', upload_id, file_name, is_pdf}
// =============================================================================
if (defined('EG_ATT_UI_LOADED')) return; // 同頁重複 include 保護
define('EG_ATT_UI_LOADED', 1);
?>
<style>
.egatt-chip { display:inline-flex; align-items:center; flex-wrap:wrap; gap:5px; background:#F1F5F9; border:1px solid #CBD5E1; border-radius:5px; padding:4px 8px; margin:3px 3px 0 0; font-size:12.5px; max-width:100%; }
.egatt-chip .egatt-name { font-weight:600; color:#2A3F54; word-break:break-all; }
.egatt-chip select.egatt-tag { font-size:12px; padding:1px 2px; border:1px solid #cbd5e1; border-radius:3px; max-width:130px; }
.egatt-chip input.egatt-desc { font-size:12px; padding:1px 5px; border:1px solid #cbd5e1; border-radius:3px; width:180px; }
.egatt-chip .egatt-del { color:#EF4444; cursor:pointer; border:none; background:none; font-weight:bold; padding:0 3px; }
.egatt-badge-conv { background:#FEF3C7; color:#92400E; border-radius:3px; padding:0 5px; font-size:11px; }
#attTagModal table td { vertical-align:middle; }
#attTagModal .egatt-sw { cursor:pointer; }
.egatt-hint { font-size:12px; color:#8a97a5; }
</style>

<!-- 標籤管理（主管） -->
<div class="modal fade" id="attTagModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-tags"></i> 附件標籤管理 <small id="attTagScopeLabel" style="color:rgba(255,255,255,.8);"></small></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;max-height:70vh;overflow-y:auto;">
            <p class="egatt-hint" style="margin-bottom:10px;">
                <i class="fa fa-info-circle"></i> 附件是否可外送到 4G / Telegram、輸出時是否蓋溯源浮水印，完全由標籤開關決定。
                標籤不可外送的附件：推播通知照發，附件欄顯示「請至內網查看」。<br>
                <i class="fa fa-exclamation-triangle" style="color:#c77c1a;"></i> 事後修改標籤只影響「未來」的發送；Telegram 已發出的檔案無法收回。
                未選標籤的附件會自動套用「預設標籤」（其浮水印開關固定開啟、不可停用）。
            </p>
            <div style="display:flex;gap:6px;margin-bottom:10px;">
                <input type="text" class="form-control input-sm" id="attTagNewName" placeholder="輸入新標籤名稱後按新增（開關預設全關＝最安全）" style="flex:1;">
                <button type="button" class="btn btn-primary btn-sm" id="attTagAddBtn"><i class="fa fa-plus"></i> 新增</button>
                <button type="button" class="btn btn-default btn-sm" id="attTagLogBtn" title="標籤與附件指派的異動紀錄"><i class="fa fa-history"></i> 異動紀錄</button>
            </div>
            <table class="table table-striped table-bordered" style="margin-bottom:0;font-size:13px;">
                <thead><tr>
                    <th>名稱</th><th style="width:70px;" title="允許發送到 4G Web Push">可傳4G</th>
                    <th style="width:86px;" title="允許發送到 Telegram">可傳Telegram</th>
                    <th style="width:76px;" title="輸出時蓋溯源浮水印（員工代碼+時間+單號 斜向平鋪）">浮水印</th>
                    <th style="width:60px;">預設</th><th style="width:60px;">狀態</th><th style="width:150px;">操作</th>
                </tr></thead>
                <tbody id="attTagTbody"></tbody>
            </table>
            <div id="attTagLogArea" style="display:none;margin-top:12px;"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div></div>
</div>

<!-- Excel 工作表選擇 -->
<div class="modal fade" id="attSheetModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#1ABB9C;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close egatt-conv-cancel" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-table"></i> 選擇要轉換的工作表 <small id="attSheetFname" style="color:rgba(255,255,255,.85);"></small></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <p class="egatt-hint">Excel 上傳後系統會轉成 PDF 保存（不保留原始 Excel）。請勾選要轉入 PDF 的工作表：</p>
            <label style="font-weight:600;cursor:pointer;display:block;border-bottom:1px solid #eee;padding-bottom:6px;">
                <input type="checkbox" id="attSheetAll" checked> 全部轉換
            </label>
            <div id="attSheetList" style="max-height:44vh;overflow-y:auto;padding-top:6px;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default egatt-conv-cancel">取消上傳</button>
            <button type="button" class="btn btn-primary" id="attSheetOkBtn"><i class="fa fa-refresh"></i> 開始轉換</button>
        </div>
    </div></div>
</div>

<!-- 轉檔後 PDF 預覽確認 -->
<div class="modal fade" id="attPrevModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-lg" style="width:86%;max-width:1100px;"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close egatt-conv-cancel" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-file-pdf-o"></i> 轉換結果預覽 <small id="attPrevFname" style="color:rgba(255,255,255,.85);"></small></h4>
        </div>
        <div class="modal-body" style="padding:10px;">
            <p class="egatt-hint" style="margin:2px 4px 8px;">請確認轉換後的 PDF 內容可讀。確認後系統只保存此 PDF，原始 Excel/Word 檔將刪除；不滿意可取消重傳。</p>
            <iframe id="attPrevFrame" style="width:100%;height:62vh;border:1px solid #ddd;border-radius:4px;" src="about:blank"></iframe>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default egatt-conv-cancel"><i class="fa fa-times"></i> 取消重傳</button>
            <button type="button" class="btn btn-primary" id="attPrevOkBtn"><i class="fa fa-check"></i> 確認，使用此 PDF</button>
        </div>
    </div></div>
</div>

<script>
window.EGAtt = (function(){
    'use strict';
    var cfg = { scope:'', api:'', tagApi:'' };
    var tagCache = null;          // {data:[], can_manage, default_id}
    var conv = null;              // 進行中的轉檔流程 {uploadId, origName, extra, done, fail, committed}
    var busy = false;

    function esc(s){ return $('<i>').text(s==null?'':s).html(); }

    function setup(o){ cfg = $.extend(cfg, o||{}); }

    // ---------- 標籤 ----------
    function loadTags(force, cb){
        if (tagCache && !force){ if(cb) cb(tagCache); return; }
        $.post(cfg.tagApi, {action:'list', scope:cfg.scope}, function(r){
            if (r && r.success){ tagCache = r; if(cb) cb(r); }
            else { tagCache = {data:[], can_manage:false, default_id:null}; if(cb) cb(tagCache); }
        }, 'json').fail(function(){ tagCache = {data:[], can_manage:false, default_id:null}; if(cb) cb(tagCache); });
    }
    function tagOptionsHtml(selectedId){
        var h = '<option value="">（未選＝套用預設標籤）</option>';
        ((tagCache && tagCache.data) || []).forEach(function(t){
            var mark = [];
            if (+t.allow_webpush) mark.push('4G');
            if (+t.allow_telegram) mark.push('TG');
            var suffix = (+t.is_default ? '【預設】' : '') + (mark.length ? '（可傳' + mark.join('/') + '）' : '');
            h += '<option value="'+t.id+'"'+(String(selectedId||'')===String(t.id)?' selected':'')+'>'+esc(t.name)+esc(suffix)+'</option>';
        });
        return h;
    }
    function tagName(id){
        var t = ((tagCache && tagCache.data) || []).find(function(x){ return String(x.id)===String(id); });
        return t ? t.name : '';
    }
    function canManage(){ return !!(tagCache && tagCache.can_manage); }

    // ---------- 標籤管理跳窗 ----------
    function openTagManager(){
        $('#attTagScopeLabel').text(cfg.scope === 'announcement' ? '（公告用）' : '（異常單用）');
        renderTagTable();
        $('#attTagLogArea').hide().empty();
        $('#attTagModal').modal('show');
    }
    function renderTagTable(){
        $.post(cfg.tagApi, {action:'list', scope:cfg.scope, manage:1}, function(r){
            if (!r || !r.success){ $('#attTagTbody').html('<tr><td colspan="7" class="text-danger">載入失敗</td></tr>'); return; }
            tagCache = r; // 同步快取
            var sw = function(t, field){
                var on = +t[field] === 1;
                return '<a href="javascript:;" class="egatt-sw" data-id="'+t.id+'" data-field="'+field+'" data-val="'+(on?1:0)+'">'
                     + (on ? '<span class="label label-success">開</span>' : '<span class="label label-default">關</span>') + '</a>';
            };
            var h = (r.data||[]).map(function(t){
                var isDef = +t.is_default === 1, isOn = +t.is_active === 1;
                return '<tr data-id="'+t.id+'">'
                     + '<td><input type="text" class="form-control input-sm egatt-tname" value="'+esc(t.name)+'"></td>'
                     + '<td class="text-center">'+sw(t,'allow_webpush')+'</td>'
                     + '<td class="text-center">'+sw(t,'allow_telegram')+'</td>'
                     + '<td class="text-center">'+(isDef ? '<span class="label label-success" title="預設標籤浮水印固定開啟">開(固定)</span>' : sw(t,'require_watermark'))+'</td>'
                     + '<td class="text-center">'+(isDef ? '<i class="fa fa-star" style="color:#f0ad4e;"></i>' : '<a href="javascript:;" class="egatt-setdef" data-id="'+t.id+'" title="設為預設標籤">設為預設</a>')+'</td>'
                     + '<td class="text-center">'+(isOn ? '<span class="label label-success">啟用</span>' : '<span class="label label-default">停用</span>')+'</td>'
                     + '<td><button type="button" class="btn btn-xs btn-primary egatt-tsave">儲存名稱</button> '
                     + (isDef ? '' : '<button type="button" class="btn btn-xs btn-warning egatt-ttoggle" data-to="'+(isOn?0:1)+'">'+(isOn?'停用':'啟用')+'</button>')
                     + '</td></tr>';
            }).join('');
            $('#attTagTbody').html(h || '<tr><td colspan="7" class="text-muted">尚無標籤</td></tr>');
        }, 'json');
    }
    $(document).on('click', '#attTagAddBtn', function(){
        var name = $('#attTagNewName').val().trim();
        if (!name){ $('#attTagNewName').focus(); return; }
        $.post(cfg.tagApi, {action:'add', scope:cfg.scope, name:name}, function(r){
            if (!r || !r.success){ alert((r&&r.message)||'新增失敗'); return; }
            $('#attTagNewName').val('');
            renderTagTable();
        }, 'json');
    });
    $(document).on('click', '#attTagTbody .egatt-sw', function(){
        var d = $(this).data();
        var p = {action:'update', id:d.id};
        p[d.field] = d.val ? 0 : 1;
        $.post(cfg.tagApi, p, function(r){
            if (!r || !r.success){ alert((r&&r.message)||'更新失敗'); }
            renderTagTable();
        }, 'json');
    });
    $(document).on('click', '#attTagTbody .egatt-tsave', function(){
        var $tr = $(this).closest('tr');
        $.post(cfg.tagApi, {action:'update', id:$tr.data('id'), name:$tr.find('.egatt-tname').val().trim()}, function(r){
            if (!r || !r.success){ alert((r&&r.message)||'儲存失敗'); }
            renderTagTable();
        }, 'json');
    });
    $(document).on('click', '#attTagTbody .egatt-ttoggle', function(){
        var $tr = $(this).closest('tr');
        $.post(cfg.tagApi, {action:'update', id:$tr.data('id'), is_active:$(this).data('to')}, function(r){
            if (!r || !r.success){ alert((r&&r.message)||'更新失敗'); }
            renderTagTable();
        }, 'json');
    });
    $(document).on('click', '#attTagTbody .egatt-setdef', function(){
        if (!confirm('確定將此標籤設為預設？未選標籤的附件會自動套用；其浮水印開關將強制開啟。')) return;
        $.post(cfg.tagApi, {action:'set_default', id:$(this).data('id')}, function(r){
            if (!r || !r.success){ alert((r&&r.message)||'設定失敗'); }
            renderTagTable();
        }, 'json');
    });
    $(document).on('click', '#attTagLogBtn', function(){
        var $a = $('#attTagLogArea');
        if ($a.is(':visible')){ $a.hide(); return; }
        $.post(cfg.tagApi, {action:'logs', scope:cfg.scope}, function(r){
            if (!r || !r.success){ $a.show().html('<span class="text-danger">'+esc((r&&r.message)||'載入失敗')+'</span>'); return; }
            var h = '<h5 style="font-weight:700;"><i class="fa fa-history"></i> 最近異動（200 筆內）</h5>'
                  + '<table class="table table-condensed table-bordered" style="font-size:12px;"><thead><tr><th style="width:130px;">時間</th><th style="width:80px;">操作者</th><th style="width:100px;">對象</th><th>欄位</th><th>舊值 → 新值</th></tr></thead><tbody>';
            var tt = { tag:'標籤', event_file:'公告附件', qa_att:'異常單附件' };
            (r.data||[]).forEach(function(l){
                h += '<tr><td>'+esc(l.changed_at)+'</td><td>'+esc(l.actor_name||l.actor_id||'')+'</td>'
                   + '<td>'+(tt[l.target_type]||esc(l.target_type))+' #'+l.target_id+(l.tag_name?'（'+esc(l.tag_name)+'）':'')+'</td>'
                   + '<td>'+esc(l.field)+'</td><td>'+esc(l.old_value==null?'（空）':l.old_value)+' → '+esc(l.new_value==null?'（空）':l.new_value)+'</td></tr>';
            });
            $a.show().html(h + '</tbody></table>');
        }, 'json');
    });

    // ---------- 上傳流程 ----------
    var officeExts = ['xlsx','xls','docx','doc'];
    var allowExts  = ['jpg','jpeg','png','pdf','xlsx','xls','docx','doc'];

    function extOf(name){ var m = /\.([A-Za-z0-9]+)$/.exec(name||''); return m ? m[1].toLowerCase() : ''; }

    /**
     * 處理單一檔案：驗證 → 上傳 → (office: 工作表/轉檔/預覽確認) → done(item)
     * item = {kind:'committed', id, file_name} 或 {kind:'pending', upload_id, file_name, is_pdf}
     */
    function process(file, extra, done, fail){
        fail = fail || function(m){ alert(m); };
        if (busy){ fail('前一個附件還在處理中，請稍候'); return; }
        var ext = extOf(file.name);
        if (allowExts.indexOf(ext) < 0){ fail('只允許 圖片(jpg/png)、PDF、Excel、Word 格式'); return; }
        if (file.size > 20*1024*1024){ fail('檔案超過 20MB 上限'); return; }

        busy = true;
        var fd = new FormData();
        fd.append('action', 'att_upload');
        fd.append('file', file);
        $.each(extra||{}, function(k,v){ fd.append(k, v); });
        $.ajax({url:cfg.api, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
        .done(function(r){
            if (!r || !r.success){ busy=false; fail((r&&r.message)||'上傳失敗'); return; }
            if (r.committed){ busy=false; r.committed.kind='committed'; done(r.committed); return; }
            var p = r.pending || {};
            if (!p.need_convert){ busy=false; done({kind:'pending', upload_id:p.upload_id, file_name:p.orig_name, is_pdf:(extOf(p.orig_name)==='pdf')}); return; }
            // office：需要轉檔流程
            conv = { uploadId:p.upload_id, origName:p.orig_name, extra:extra||{}, done:done, fail:fail };
            if (p.need_sheets){ openSheetModal(p.sheets||[]); }
            else { doConvert(null); }
        })
        .fail(function(x){ busy=false; fail('上傳失敗：' + (x.status||'連線錯誤')); });
    }

    function openSheetModal(sheets){
        $('#attSheetFname').text(conv.origName);
        $('#attSheetAll').prop('checked', true);
        $('#attSheetList').html(sheets.map(function(s){
            return '<label style="display:block;font-weight:normal;cursor:pointer;margin:3px 0;">'
                 + '<input type="checkbox" class="egatt-sheet" value="'+esc(s)+'" checked disabled> '+esc(s)+'</label>';
        }).join(''));
        $('#attSheetModal').modal('show');
    }
    $(document).on('change', '#attSheetAll', function(){
        $('#attSheetList .egatt-sheet').prop('disabled', this.checked).prop('checked', this.checked ? true : false);
    });
    $(document).on('click', '#attSheetOkBtn', function(){
        var sheets = null;
        if (!$('#attSheetAll').prop('checked')){
            sheets = $('#attSheetList .egatt-sheet:checked').map(function(){ return this.value; }).get();
            if (!sheets.length){ alert('請至少勾選一個工作表，或勾選「全部轉換」'); return; }
        }
        $('#attSheetModal').modal('hide');
        doConvert(sheets);
    });
    function doConvert(sheets){
        var $body = $('body');
        var $ov = $('<div style="position:fixed;inset:0;background:rgba(42,63,84,.55);z-index:3000;display:flex;align-items:center;justify-content:center;">'
                   + '<div style="background:#fff;border-radius:8px;padding:22px 34px;font-size:15px;color:#2A3F54;"><i class="fa fa-spinner fa-spin"></i> 正在轉換為 PDF，請稍候…（大檔可能需要數十秒）</div></div>').appendTo($body);
        $.post(cfg.api, {action:'att_convert', upload_id:conv.uploadId, sheets: sheets ? JSON.stringify(sheets) : ''}, function(r){
            $ov.remove();
            if (!r || !r.success){ cancelConv((r&&r.message)||'轉換失敗，該附件視同未完成上傳，請重試或改上傳 PDF'); return; }
            $('#attPrevFname').text(conv.origName + ' → PDF');
            $('#attPrevFrame').attr('src', cfg.api + '?action=att_preview&upload_id=' + encodeURIComponent(conv.uploadId) + '&t=' + Date.now());
            $('#attPrevModal').modal('show');
        }, 'json').fail(function(){ $ov.remove(); cancelConv('轉換失敗（連線逾時），該附件視同未完成上傳'); });
    }
    $(document).on('click', '#attPrevOkBtn', function(){
        var p = $.extend({action:'att_commit', upload_id:conv.uploadId}, conv.extra);
        var $b = $(this).prop('disabled', true);
        $.post(cfg.api, p, function(r){
            $b.prop('disabled', false);
            $('#attPrevModal').modal('hide');
            $('#attPrevFrame').attr('src', 'about:blank');
            if (!r || !r.success){ cancelConv((r&&r.message)||'確認失敗'); return; }
            var c = conv; conv = null; busy = false;
            if (r.committed){ r.committed.kind='committed'; c.done(r.committed); }
            else c.done({kind:'pending', upload_id:c.uploadId, file_name:c.origName.replace(/\.[^.]+$/, '.pdf'), is_pdf:true, converted:true});
        }, 'json').fail(function(){ $b.prop('disabled', false); cancelConv('確認失敗（連線錯誤）'); });
    });
    $(document).on('click', '.egatt-conv-cancel', function(){
        $('#attSheetModal,#attPrevModal').modal('hide');
        $('#attPrevFrame').attr('src', 'about:blank');
        cancelConv(null);
    });
    function cancelConv(msg){
        if (conv){
            $.post(cfg.api, {action:'att_discard', upload_id:conv.uploadId});
            var c = conv; conv = null; busy = false;
            if (msg) c.fail(msg);
        } else { busy = false; if (msg) alert(msg); }
    }

    // 疊窗 z-index（可能疊在其他 modal 之上）
    $('#attTagModal,#attSheetModal,#attPrevModal').on('shown.bs.modal', function(){
        $(this).css('z-index', 2120);
        $('.modal-backdrop').last().css('z-index', 2110);
    }).on('hidden.bs.modal', function(){
        if ($('.modal.in').length) $('body').addClass('modal-open');
    });

    return {
        setup: setup,
        loadTags: loadTags,
        tagOptionsHtml: tagOptionsHtml,
        tagName: tagName,
        canManage: canManage,
        openTagManager: openTagManager,
        process: process,
        esc: esc
    };
})();
</script>
