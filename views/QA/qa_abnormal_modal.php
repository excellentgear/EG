<?php
// =============================================================================
// views/QA/qa_abnormal_modal.php
// 「開立品質異常單」共用跳窗元件（任何頁面 include 後即可使用）。
// 需求：頁面已載入 jQuery 與 Bootstrap 3（本專案標準版型皆有）。
//
// 使用方式：
//   <?php include '../QA/qa_abnormal_modal.php'; ? >
//   QAAbnormalModal.open({
//       source_type: 'QC',            // 必填：來源模組（QC / IR / ...）
//       source_id:   123,             // 必填：來源主鍵（如 qc_form_id）
//       prefill: {                    // 選填：預帶欄位
//           occurrence_date:'2026-07-03', sqty: 3, phenomenon:'...',
//           bom_no:'B123', bom_process_fids:'139267', qa_ps:'...'
//       },
//       onCreated: function(res){ /* res = {id, no, event_id} */ }
//   });
//
// 開單成功後由後端(store_QA_Abnormal_API.php create)自動：
//   - 建立通知（回覆部門＝回覆回簽、通知/追蹤人員＝已閱、預設可互看回覆）
//   - Web Push 推播，點開導向 views/QA/qa_abnormal_view.php
// =============================================================================
// 附件標籤/轉檔/預覽 共用元件（標籤管理跳窗、Excel工作表選擇、PDF預覽確認）
include __DIR__ . '/../common/attachment_ui.php';
?>
<style>
#qamModal .qam-sec-title { font-weight:700; color:#2A3F54; border-left:4px solid #1ABB9C; padding-left:8px; margin:14px 0 10px; }
#qamModal input[type=number]::-webkit-inner-spin-button,
#qamModal input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
#qamModal input[type=number] { -moz-appearance:textfield; appearance:textfield; }
#qamModal .qam-m5t { display:flex; flex-wrap:wrap; gap:6px; }
#qamModal .qam-m5t label { font-weight:normal; cursor:pointer; margin:0; padding:4px 12px; border:1px solid #ddd; border-radius:4px; background:#f9f9f9; font-size:13px; display:flex; align-items:center; gap:5px; }
#qamModal .qam-chip { display:inline-flex; align-items:center; gap:5px; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:14px; padding:2px 10px; margin:2px; font-size:13px; }
#qamModal .qam-chip .rm { color:#EF4444; cursor:pointer; font-weight:bold; }
#qamModal .qam-sug { position:absolute; top:100%; left:0; right:0; z-index:2001; background:#fff; border:1px solid #ccc; max-height:180px; overflow-y:auto; display:none; box-shadow:0 2px 6px rgba(0,0,0,.15); }
#qamModal .qam-sug div { padding:6px 10px; cursor:pointer; font-size:13px; }
#qamModal .qam-sug div:hover { background:#f5f5f5; }
#qamModal .qam-att-item { display:inline-flex; align-items:center; gap:4px; background:#F1F5F9; border:1px solid #CBD5E1; border-radius:4px; padding:2px 8px; margin:2px; font-size:12px; }
#qamModal .qam-att-item .del { color:#EF4444; cursor:pointer; border:none; background:none; padding:0 2px; }
#qamModal .qam-dept-row { display:flex; align-items:center; gap:8px; padding:3px 0; }
#qamModal .qam-dept-row select { width:180px; }
#qamModal .qam-req { color:#d9534f; font-weight:bold; margin-left:2px; }
</style>

<div class="modal fade" id="qamModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title" id="qamTitle">開立品質異常單</h4>
        </div>
        <div class="modal-body" style="padding:18px 22px; max-height:72vh; overflow-y:auto;">
            <input type="hidden" id="qam_source_type">
            <input type="hidden" id="qam_source_id">
            <input type="hidden" id="qam_bom_no">
            <input type="hidden" id="qam_bom_process_fids">
            <input type="file" id="qam_file_input" style="display:none;" accept=".jpg,.jpeg,.png,.pdf,.xls,.xlsx,.doc,.docx">

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>異常單號</label>
                        <input type="text" class="form-control input-sm" id="qam_no" placeholder="自動產生" readonly style="background:#eee;">
                    </div>
                    <div class="form-group">
                        <label>異常種類<span class="qam-req">*</span>
                            <button type="button" class="btn btn-xs btn-default" id="qam_type_manage_btn" title="新增／修改異常種類"><i class="fa fa-cog"></i> 管理</button>
                        </label>
                        <select class="form-control input-sm" id="qam_type"><option value="">請選擇...</option></select>
                    </div>
                    <div class="form-group">
                        <label>異常發生日期<span class="qam-req">*</span></label>
                        <input type="date" class="form-control input-sm" id="qam_occ_date">
                    </div>
                    <div class="form-group">
                        <label>異常數量<span class="qam-req">*</span></label>
                        <input type="number" class="form-control input-sm" id="qam_sqty" placeholder="不良品數量">
                    </div>
                    <div class="form-group">
                        <label>發現單位<span class="qam-req">*</span></label>
                        <select class="form-control input-sm" id="qam_found_unit">
                            <option value="">請選擇</option>
                            <option value="廠內">廠內</option>
                            <option value="客退">客退</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>責任單位 <small class="text-muted">（可先選「未指定」，後續可修改）</small></label>
                        <div style="display:flex;gap:16px;margin-bottom:6px;">
                            <label style="font-weight:normal;cursor:pointer;margin:0;"><input type="radio" name="qam_resp_type" value="" checked> 未指定</label>
                            <label style="font-weight:normal;cursor:pointer;margin:0;"><input type="radio" name="qam_resp_type" value="vendor"> 廠商</label>
                            <label style="font-weight:normal;cursor:pointer;margin:0;"><input type="radio" name="qam_resp_type" value="dept"> 廠內部門</label>
                        </div>
                        <div id="qam_resp_vendor_ui" style="display:none;position:relative;">
                            <input type="text" class="form-control input-sm" id="qam_vendor_kw" placeholder="輸入廠商名稱或編號搜尋..." autocomplete="off">
                            <div class="qam-sug" id="qam_vendor_sug"></div>
                            <input type="hidden" id="qam_vendor_id">
                        </div>
                        <div id="qam_resp_dept_ui" style="display:none;">
                            <select class="form-control input-sm" id="qam_resp_dept"><option value="">選擇部門</option></select>
                            <select class="form-control input-sm" id="qam_resp_person" style="margin-top:5px;"><option value="">選擇人員(選填)</option></select>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>異常現象描述<span class="qam-req">*</span>
                            <button type="button" class="btn btn-xs btn-default qam-att-btn" data-field="phenomenon"><i class="fa fa-paperclip"></i> 附件</button>
                        </label>
                        <textarea class="form-control" id="qam_phenomenon" rows="5" placeholder="詳細描述異常現象..."></textarea>
                        <div id="qam_att_phenomenon"></div>
                    </div>
                    <div class="form-group">
                        <label>5M+T 異常原因分類（單選）<span class="qam-req">*</span></label>
                        <div class="qam-m5t">
                            <?php foreach (['人','機器','材料','方法','工具','環','其他'] as $m): ?>
                            <label><input type="radio" name="qam_defect_category" value="<?= $m ?>" style="margin:0;"> <?= $m ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>原因分析<span class="qam-req">*</span>
                            <button type="button" class="btn btn-xs btn-default qam-att-btn" data-field="defect_detail"><i class="fa fa-paperclip"></i> 附件</button>
                        </label>
                        <textarea class="form-control" id="qam_defect_detail" rows="3" placeholder="為何發生此異常？針對上方 5M+T 分類做原因分析（例：刀具磨耗未即時更換）..."></textarea>
                        <div id="qam_att_defect_detail"></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>品管備註<span class="qam-req">*</span>
                            <button type="button" class="btn btn-xs btn-default qam-att-btn" data-field="qa_ps"><i class="fa fa-paperclip"></i> 附件</button>
                        </label>
                        <textarea class="form-control" id="qam_qa_ps" rows="3" placeholder="品管檢驗與判定備註（例：抽驗數/判定依據/與現場溝通事項），非原因分析..."></textarea>
                        <div id="qam_att_qa_ps"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div id="qam_disp_box" style="border:2px solid #c77c1a;border-radius:8px;padding:10px 12px;background:#fffdf7;">
                        <div style="font-weight:700;color:#c77c1a;margin-bottom:6px;"><i class="fa fa-gavel"></i> 異常處置判定區
                            <span id="qam_disp_no_perm" class="text-danger" style="display:none;font-size:12px;font-weight:normal;">（您沒有回覆處置的權限，無法填寫）</span>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label>異常處置方式 <small class="text-muted">（選填）</small></label>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;">
                                <?php foreach (['特採','報廢','重工','需矯正','轉總經理裁示'] as $d): ?>
                                <label style="display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;font-weight:normal;margin:0;">
                                    <input type="checkbox" name="qam_disposition" value="<?= $d ?>" style="margin:0;"> <?= $d ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label>處置說明 <small class="text-muted">（選填）</small></label>
                            <textarea class="form-control" id="qam_disposition_note" rows="2" placeholder="處置說明..."></textarea>
                        </div>
                        <div style="border-top:1px dashed #e6d9b8;padding-top:6px;font-size:12.5px;color:#5a6b7b;">
                            <b>可判定人員（決策順序）：</b>
                            <button type="button" class="btn btn-xs btn-default" id="qam_disp_order_btn" title="調整決策順序（僅具此功能者可調整，可存成預設順序）"><i class="fa fa-sort"></i> 排序</button>
                            <div id="qam_disp_deciders" style="margin-top:4px;">載入中…</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin:2px 0 8px;">
                <label style="font-weight:normal;cursor:pointer;margin:0;">
                    <input type="checkbox" id="qam_show_inline" style="margin:0 4px 0 0;vertical-align:middle;">
                    附件 2 件（含）以下時，於檢視畫面直接顯示附件（僅電腦版；4G 與 Telegram 一律以清單呈現）
                </label>
            </div>

            <div class="qam-sec-title"><i class="fa fa-sitemap"></i> 回覆部門（需回覆＋回簽）<span class="qam-req">*</span>
                <small class="text-muted qam-edit-only" style="display:none;font-weight:normal;">（修改時：新勾選的部門/人員會收到通知要求回覆；已回覆的部門保留原記錄、不會重複要求）</small>
            </div>
            <div id="qam_dept_container" style="max-height:220px;overflow-y:auto;border:1px solid #eee;padding:8px 10px;border-radius:4px;display:grid;grid-template-columns:1fr 1fr;gap:0 14px;align-content:start;">
                <span class="text-muted">載入中…</span>
            </div>
            <div id="qam_dept_selected" style="margin-top:6px;"></div>

            <div class="qam-sec-title qam-editor-sec"><i class="fa fa-users"></i> 共同編輯者 <small class="text-muted" style="font-weight:normal;">（選填，部門或最多 5 位人員；會與開單人並列公告者，且可修改此異常單，皆留編輯記錄）</small></div>
            <div class="row qam-editor-sec">
                <div class="col-md-6">
                    <div class="form-group" style="position:relative;">
                        <label>人員（最多 5 位）</label>
                        <div id="qam_editor_chips"></div>
                        <input type="text" class="form-control input-sm" id="qam_editor_kw" placeholder="輸入姓名／部門／職稱搜尋後點選加入..." autocomplete="off">
                        <div class="qam-sug" id="qam_editor_sug"></div>
                    </div>
                    <div class="form-group">
                        <label>部門</label>
                        <div style="display:flex;gap:6px;">
                            <select class="form-control input-sm" id="qam_editor_dept" style="flex:1;"><option value="">選擇部門...</option></select>
                            <button type="button" class="btn btn-default btn-sm" id="qam_editor_dept_add"><i class="fa fa-plus"></i> 加入部門</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>預設名單 <small class="text-muted">（私人名單優先顯示）</small></label>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <select class="form-control input-sm" id="qam_editor_preset" style="flex:1;min-width:150px;"><option value="">套用預設名單…</option></select>
                            <button type="button" class="btn btn-default btn-sm" id="qam_editor_preset_save" title="將目前共同編輯者存成預設名單（可設公開或私人）"><i class="fa fa-star-o"></i> 儲存名單</button>
                            <button type="button" class="btn btn-default btn-sm" id="qam_editor_preset_del" title="刪除選取的預設名單（僅能刪除自己建立的）"><i class="fa fa-trash-o"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="qam-sec-title qam-create-only"><i class="fa fa-bell-o"></i> 通知設定</div>
            <div class="row qam-create-only">
                <div class="col-md-6">
                    <div class="form-group" style="position:relative;">
                        <label>通知人員 <small class="text-muted">（選填，收到通知，義務＝已閱）</small></label>
                        <div id="qam_notify_chips"></div>
                        <input type="text" class="form-control input-sm" id="qam_notify_kw" placeholder="輸入姓名／部門／職稱搜尋後點選加入..." autocomplete="off">
                        <div class="qam-sug" id="qam_notify_sug"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group" style="position:relative;">
                        <label>追蹤人員 <small class="text-muted">（選填，對象回覆時會收到回覆內容通知，最多 5 名）</small></label>
                        <div id="qam_follower_chips"></div>
                        <input type="text" class="form-control input-sm" id="qam_follower_kw" placeholder="輸入姓名／部門／職稱搜尋後點選加入..." autocomplete="off">
                        <div class="qam-sug" id="qam_follower_sug"></div>
                    </div>
                </div>
            </div>
            <div class="row qam-create-only">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>回覆期限<span class="qam-req">*</span>
                            <small class="text-muted">（預設 <span id="qam_deadline_days">10</span> 個工作天，可修改；逾期後對象無法再回覆/回簽）</small>
                            <button type="button" class="btn btn-xs btn-default" id="qam_deadline_cfg" title="修改預設工作天數"><i class="fa fa-cog"></i></button>
                        </label>
                        <input type="date" class="form-control input-sm" id="qam_reply_deadline">
                    </div>
                </div>
                <div class="col-md-6" style="padding-top:24px;">
                    <button type="button" class="btn btn-default btn-sm" id="qam_save_default_btn" title="將目前的通知人員與追蹤人員儲存為預設名單，之後開單自動帶入">
                        <i class="fa fa-star-o"></i> 將目前名單儲存為預設
                    </button>
                    <span id="qam_default_msg" class="text-success" style="display:none;font-size:12px;margin-left:6px;"></span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <div class="form-group qam-edit-only" style="display:none;text-align:left;margin-bottom:10px;">
                <label>本次修改說明 <small class="text-muted">（選填，會記錄於編輯記錄）</small></label>
                <input type="text" class="form-control input-sm" id="qam_edit_reason" maxlength="255" placeholder="說明此次修改內容或原因...">
            </div>
            <button type="button" class="btn btn-info pull-left qam-edit-only" id="qam_edit_log_btn" style="display:none;"><i class="fa fa-history"></i> 編輯記錄</button>
            <button type="button" class="btn btn-danger pull-left qam-edit-only" id="qam_close_btn" style="display:none;margin-left:6px;"><i class="fa fa-archive"></i> 結案</button>
            <button type="button" class="btn btn-default pull-left" id="qam_att_tag_btn" style="display:none;margin-left:6px;" title="附件標籤管理（僅主管）"><i class="fa fa-tags"></i> 附件標籤管理</button>
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qam_save_btn"><i class="fa fa-check"></i> 確認開立並發送通知</button>
        </div>
    </div></div>
</div>

<!-- 異常單編輯記錄（疊在開單跳窗上層） -->
<div class="modal fade" id="qamEditLogModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-history"></i> 異常單編輯記錄 <small id="qamEditLogNo" style="color:rgba(255,255,255,.8);"></small></h4>
        </div>
        <div class="modal-body" style="padding:14px 18px;max-height:64vh;overflow-y:auto;" id="qam_edit_log_body">
            <span class="text-muted">載入中…</span>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<!-- 處置判定人員決策順序（疊在開單跳窗上層；僅具 qa_disposition_reply 者可調整） -->
<div class="modal fade" id="qamDispOrderModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#c77c1a;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-sort"></i> 處置判定人員 — 決策順序</h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <p class="text-muted" style="font-size:12.5px;"><i class="fa fa-info-circle"></i> 以「↑ / ↓」調整順序後按「儲存為預設順序」；此順序為全系統預設，所有可判定者皆可再調整。</p>
            <table class="table table-bordered" style="margin-bottom:0;">
                <thead><tr><th style="width:60px;">順序</th><th>人員</th><th style="width:110px;">調整</th></tr></thead>
                <tbody id="qam_disp_order_tbody"></tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qam_disp_order_save"><i class="fa fa-save"></i> 儲存為預設順序</button>
        </div>
    </div></div>
</div>

<!-- 異常種類管理（疊在開單跳窗上層） -->
<div class="modal fade" id="qamTypeModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-cog"></i> 管理異常種類</h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <div style="display:flex;gap:6px;margin-bottom:10px;">
                <input type="text" class="form-control input-sm" id="qam_new_type_name" placeholder="輸入新種類名稱後按新增" style="flex:1;">
                <button type="button" class="btn btn-primary btn-sm" id="qam_add_type_btn"><i class="fa fa-plus"></i> 新增</button>
            </div>
            <table class="table table-striped table-bordered" style="margin-bottom:0;">
                <thead><tr><th>名稱</th><th style="width:70px;">狀態</th><th style="width:160px;">操作</th></tr></thead>
                <tbody id="qam_type_tbody"></tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<script>
window.QAAbnormalModal = (function(){
    'use strict';
    var API = '../../src/store/store_QA_Abnormal_API.php';
    var FOLLOWER_MAX = 5;
    var opt = null;                 // open() 傳入的設定
    var tempKey = '';               // 附件暫存 key
    var saved = false;              // 是否已成功開立（未開立關窗要清暫存附件）
    var notifyUsers = [];           // [{id,name}]
    var followerUsers = [];         // [{id,name}]
    var attach = { phenomenon:[], defect_detail:[], qa_ps:[] };
    var attField = '';
    var deptsAll = [];              // 全部部門
    var deptCfg = [];               // 預設回覆部門設定
    var canDisp = true;             // 是否具「回覆處置」權限(qa_disposition_reply)
    var PRESET_API = '../../src/store/_editorPreset.php';
    var editorList = [];            // 共同編輯者 [{type:'dept'|'user', id, name}]
    var editId = null;              // 編輯模式時的異常單 id（null=開立模式）
    var editDetail = null;          // 編輯模式時的原始明細（避免 update 未傳欄位被清空）

    function esc(s){ return $('<i>').text(s==null?'':s).html(); }
    function today(){ var d=new Date(); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }

    // ---------- 附件標籤/轉檔共用元件（views/common/attachment_ui.php） ----------
    EGAtt.setup({ scope:'abnormal', api:API, tagApi:'../../src/store/attachment_tag_API.php' });
    function qamLoadTags(){
        EGAtt.loadTags(false, function(r){
            $('#qam_att_tag_btn').toggle(!!r.can_manage);
            renderAtt(); // 標籤載入後重繪，讓每個附件的標籤下拉有選項
        });
    }
    $(document).on('click', '#qam_att_tag_btn', function(){ EGAtt.openTagManager(); });

    // ---------- 模式切換（開立 / 修改） ----------
    function setMode(isEdit){
        $('#qamModal .qam-create-only').toggle(!isEdit);
        $('#qamModal .qam-edit-only').toggle(!!isEdit);
        $('#qam_save_btn').html(isEdit
            ? '<i class="fa fa-check"></i> 儲存修改'
            : '<i class="fa fa-check"></i> 確認開立並發送通知');
    }

    // ---------- 開啟 ----------
    function open(o){
        opt = o || {};
        if (!opt.source_type || !opt.source_id){ alert('QAAbnormalModal.open 需要 source_type / source_id'); return; }
        saved = false;
        editId = null; editDetail = null;
        setMode(false);
        tempKey = 'qam_' + Date.now() + '_' + Math.floor(Math.random()*100000);
        notifyUsers = []; followerUsers = [];
        editorList = [];
        attach = { phenomenon:[], defect_detail:[], qa_ps:[] };

        // 重設表單
        $('#qam_source_type').val(opt.source_type);
        $('#qam_source_id').val(opt.source_id);
        var p = opt.prefill || {};
        $('#qam_no').val('');
        $('#qam_type').val('');
        $('#qam_occ_date').val(p.occurrence_date || today());
        $('#qam_sqty').val(p.sqty != null ? p.sqty : '');
        $('#qam_found_unit').val(p.found_unit || '廠內');
        $('#qam_phenomenon').val(p.phenomenon || '');
        $('#qam_defect_detail').val('');
        $('#qam_qa_ps').val(p.qa_ps || '');
        $('#qam_disposition_note').val('');
        $('#qam_reply_deadline').val('');
        $('#qam_bom_no').val(p.bom_no || '');
        $('#qam_bom_process_fids').val(p.bom_process_fids || '');
        $('input[name=qam_defect_category]').prop('checked', false);
        $('input[name=qam_disposition]').prop('checked', false);
        $('input[name=qam_resp_type][value=""]').prop('checked', true).trigger('change');
        $('#qam_vendor_kw,#qam_vendor_id').val('');
        $('#qam_resp_dept,#qam_resp_person').val('');
        $('#qam_show_inline').prop('checked', false);
        qamLoadTags();
        renderAtt(); renderChips();

        // 載入基礎資料
        $.post(API, {action:'get_next_no'}, function(r){ if(r.success) $('#qam_no').val(r.no); }, 'json');
        loadTypes('');
        loadDepts();
        loadDefaults();
        loadDeadlineDefault();
        loadMyPerms();
        loadDispDeciders();
        renderEditorChips();
        loadEditorPresets();

        $('#qamTitle').text('開立品質異常單' + (opt.title_suffix ? ' — ' + opt.title_suffix : ''));
        $('#qamModal').modal('show');
    }

    // ---------- 開啟「修改」模式（載入既有異常單） ----------
    function openEdit(orderId, o){
        opt = o || {};
        saved = false;
        editId = parseInt(orderId, 10);
        tempKey = 'qam_' + Date.now() + '_' + Math.floor(Math.random()*100000);
        notifyUsers = []; followerUsers = [];
        editorList = [];
        pendingFlows = null;
        attach = { phenomenon:[], defect_detail:[], qa_ps:[] };
        setMode(true);
        $('#qam_edit_reason').val('');
        $('#qam_show_inline').prop('checked', false);
        qamLoadTags();
        renderAtt(); renderEditorChips();
        loadTypes('');
        loadDepts();
        loadMyPerms();
        loadDispDeciders();
        loadEditorPresets();
        // 載入既有附件（可於此修改標籤/說明；變更會寫異動紀錄並重建檢視快取版）
        $.post(API, {action:'get_attachments', abnormal_order_id: parseInt(orderId,10)}, function(ra){
            if (!ra || !ra.success) return;
            attach = { phenomenon:[], defect_detail:[], qa_ps:[] };
            (ra.data||[]).forEach(function(a){ if (attach[a.field_type]) attach[a.field_type].push(a); });
            renderAtt();
        }, 'json');

        $.post(API, {action:'get_detail', id: editId}, function(r){
            if (!r || !r.success){ alert('載入異常單失敗：' + ((r && r.message) || '')); return; }
            var d = r.data;
            editDetail = d;
            $('#qam_no').val(d.abnormal_order_no || '');
            $('#qam_occ_date').val(d.occurrence_date || '');
            $('#qam_sqty').val(d.sqty != null ? d.sqty : '');
            $('#qam_found_unit').val(d.found_unit || '');
            $('#qam_phenomenon').val(d.abnormal_phenomenon || '');
            $('#qam_defect_detail').val(d.defect_detail || '');
            $('#qam_qa_ps').val(d.qa_ps || '');
            $('#qam_disposition_note').val(d.disposition_note || '');
            $('#qam_bom_no').val(d.bom_no || '');
            $('#qam_bom_process_fids').val(d.bom_process_fids || '');
            $('input[name=qam_defect_category]').prop('checked', false);
            if (d.defect_category) $('input[name=qam_defect_category][value="'+d.defect_category+'"]').prop('checked', true);
            $('input[name=qam_disposition]').prop('checked', false);
            String(d.disposition || '').split(/[,、]/).forEach(function(v){
                v = v.trim();
                if (v) $('input[name=qam_disposition][value="'+v+'"]').prop('checked', true);
            });
            // 責任單位
            var rt = d.responsible_type || '';
            $('input[name=qam_resp_type][value="'+rt+'"]').prop('checked', true).trigger('change');
            if (rt === 'vendor'){
                $('#qam_vendor_id').val(d.responsible_vendor_id || '');
                $('#qam_vendor_kw').val(d.vendor_name || d.responsible_vendor_id || '');
            } else if (rt === 'dept'){
                // 部門下拉由 change handler 以 deptsAll 填充，deptsAll 為非同步載入 → 延後設值
                setTimeout(function(){
                    $('input[name=qam_resp_type][value="dept"]').trigger('change');
                    $('#qam_resp_dept').val(String(d.responsible_dept_id || ''));
                    if (d.responsible_dept_id){
                        $.post(API, {action:'get_dept_users', dept_id:d.responsible_dept_id, mode:0}, function(ru){
                            var $p = $('#qam_resp_person').empty().append('<option value="">選擇人員(選填)</option>');
                            (ru.data||[]).forEach(function(u){ $p.append('<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'); });
                            $p.val(String(d.responsible_person_id || ''));
                        }, 'json');
                    }
                }, 600);
            }
            // 異常種類（loadTypes 為非同步 → 延後設值）
            setTimeout(function(){ $('#qam_type').val(String(d.abnormal_type_id || '')); }, 600);
            // 結案按鈕：依目前狀態顯示 結案 / 取消結案
            qamSetCloseBtn(parseInt(d.is_closed, 10) === 1);
            // 附件直接顯示勾選
            $('#qam_show_inline').prop('checked', parseInt(d.show_attach_inline, 10) === 1);
            // 回覆部門：以既有流程預填（含已回覆者；已回覆的部門後端不會重複建立要求）
            applyFlowsWhenReady(d.flow || []);
            // 共同編輯者
            editorList = (d.co_editors || []).map(function(e){ return {type:e.type, id:e.id, name:e.name}; });
            renderEditorChips();
        }, 'json');

        $('#qamTitle').text('修改品質異常單' + (opt.title_suffix ? ' — ' + opt.title_suffix : ''));
        $('#qamModal').modal('show');
    }

    // 關窗未儲存 → 清暫存附件
    $('#qamModal').on('hidden.bs.modal', function(){
        if (!saved && tempKey) $.post(API, {action:'cleanup_temp_attachments', temp_key: tempKey});
    });

    // ---------- 回覆期限預設（今天 + N 個工作天，依行事曆；N 可由齒輪修改） ----------
    function loadDeadlineDefault(){
        $.post(API, {action:'get_reply_deadline_default'}, function(r){
            if (!r || !r.success) return;
            $('#qam_deadline_days').text(r.days);
            if (!$('#qam_reply_deadline').val()) $('#qam_reply_deadline').val(r.date);
        }, 'json');
    }
    $(document).on('click', '#qam_deadline_cfg', function(){
        var cur = $('#qam_deadline_days').text() || '10';
        var v = prompt('回覆期限預設工作天數（開單時依行事曆自動推算日期，週末與休假日不計、補班日照算）：', cur);
        if (v === null) return;
        v = parseInt(v, 10);
        if (!v || v < 1){ alert('請輸入大於 0 的整數天數'); return; }
        $.post(API, {action:'save_setting', key:'qa_reply_deadline_workdays', value:String(v)}, function(r){
            if (!r || !r.success){ alert('儲存失敗：' + ((r&&r.message)||'')); return; }
            $('#qam_reply_deadline').val('');   // 依新天數重算
            loadDeadlineDefault();
        }, 'json');
    });

    // ---------- 處置方式/處置說明權限（qa_disposition_reply；於 QC 頁「權限設定」指派角色） ----------
    var isPrimaryDecider = false; // 本人是否為首要決策者（「轉總經理裁示」僅其可勾）
    function applyDispPerm(){
        $('input[name=qam_disposition]').prop('disabled', !canDisp);
        $('#qam_disposition_note').prop('disabled', !canDisp);
        $('#qam_disp_no_perm').toggle(!canDisp);
        // 「轉總經理裁示」僅首要決策者可勾選（伺服器端亦會過濾）
        var $gm = $('input[name=qam_disposition][value="轉總經理裁示"]');
        if (!isPrimaryDecider){
            $gm.prop('disabled', true).prop('checked', false);
            $gm.closest('label').css('opacity', .55).attr('title', '僅首要決策者可勾選「轉總經理裁示」');
        } else if (canDisp){
            $gm.prop('disabled', false);
            $gm.closest('label').css('opacity', 1).attr('title', '');
        }
    }
    function loadMyPerms(){
        $.post(API, {action:'get_my_qa_perms'}, function(r){
            canDisp = (r && r.success) ? !!r.can_disposition : true;
            applyDispPerm();
        }, 'json');
    }

    // ---------- 處置判定人員（具 qa_disposition_reply 者）＋首要/最終決策者＋決策順序 ----------
    var dispDeciders = [];
    function loadDispDeciders(){
        $.post(API, {action:'get_disposition_deciders'}, function(r){
            if (!r || !r.success){ $('#qam_disp_deciders').text('載入失敗'); return; }
            dispDeciders = r.data || [];
            $('#qam_disp_order_btn').toggle(!!r.can_sort && dispDeciders.length > 1);
            var roleMark = { primary:'【首要】', secondary:'【次要】', final:'【最終】' };
            var listHtml = dispDeciders.length
                ? dispDeciders.map(function(u, i){ return '<span class="qam-chip">' + (i+1) + '. ' + (roleMark[u.role]||'') + esc(u.name) + '</span>'; }).join('')
                : '<span class="text-muted">尚無人員具「勾選/回覆異常處置」功能，請至 設定→權限設定 指派角色功能</span>';
            $('#qam_disp_deciders').html(listHtml);
            // 首要／最終決策者與其今日代理人（於 品管檢驗頁 設定→異常單處置決策設定）
            $.post(API, {action:'get_decision_setting'}, function(cfg){
                if (!cfg || !cfg.success) return;
                isPrimaryDecider = !!cfg.is_primary;
                applyDispPerm(); // 依「是否為首要決策者」重套「轉總經理裁示」可勾狀態
                var byId = {};
                (cfg.pool || []).forEach(function(p){ byId[p.id] = p; });
                function line(role, uid){
                    if (!uid || !byId[uid]) return '<span class="text-muted">' + role + '：未設定</span>';
                    var p = byId[uid];
                    var dep = (p.deputies || []).map(function(x){ return x.name; }).join('、');
                    return role + '：<b>' + esc(p.user_cname) + '</b>' + (dep ? '（今日代理人：' + esc(dep) + '）' : '');
                }
                var secNames = (cfg.secondary || []).map(function(id){ return byId[id] ? byId[id].user_cname : null; }).filter(Boolean);
                $('#qam_disp_deciders').html(
                    '<div style="margin-bottom:3px;">' + line('首要決策者', cfg.primary) + '　' + line('最終決策者', cfg.final)
                    + (secNames.length ? '<br>次要決策者（首要請假時可代為判定）：' + esc(secNames.join('、')) : '')
                    + '</div>' + listHtml
                );
            }, 'json');
        }, 'json');
    }
    var dispRoleTag = { primary:'<span class="label label-primary">首要</span> ', secondary:'<span class="label label-info">次要</span> ', final:'<span class="label label-warning">最終</span> ' };
    function renderDispOrderTable(){
        var n = dispDeciders.length;
        $('#qam_disp_order_tbody').html(dispDeciders.map(function(u, i){
            var pinned = (u.role === 'primary' || u.role === 'final');
            // 首要固定第一、最終固定最後；中間項不可越過固定列
            var upOk   = !pinned && i > 0 && dispDeciders[i-1].role !== 'primary' ? '' : 'disabled';
            var downOk = !pinned && i < n-1 && dispDeciders[i+1].role !== 'final' ? '' : 'disabled';
            return '<tr data-id="'+u.id+'">'
                 + '<td>'+(i+1)+'</td><td>'+(dispRoleTag[u.role]||'')+esc(u.name)+'</td>'
                 + '<td>'
                 + (pinned
                    ? '<span class="text-muted" style="font-size:12px;"><i class="fa fa-lock"></i> 位置固定</span>'
                    : '<button type="button" class="btn btn-xs btn-default qam-disp-up" '+upOk+'><i class="fa fa-arrow-up"></i></button> '
                      + '<button type="button" class="btn btn-xs btn-default qam-disp-down" '+downOk+'><i class="fa fa-arrow-down"></i></button>')
                 + '</td></tr>';
        }).join(''));
    }
    $(document).on('click', '#qam_disp_order_btn', function(){
        renderDispOrderTable();
        $('#qamDispOrderModal').modal('show');
    });
    $(document).on('click', '.qam-disp-up, .qam-disp-down', function(){
        var id = String($(this).closest('tr').data('id'));
        var i = dispDeciders.findIndex(function(u){ return String(u.id) === id; });
        var j = $(this).hasClass('qam-disp-up') ? i - 1 : i + 1;
        if (i < 0 || j < 0 || j >= dispDeciders.length) return;
        var tmp = dispDeciders[i]; dispDeciders[i] = dispDeciders[j]; dispDeciders[j] = tmp;
        renderDispOrderTable();
    });
    $(document).on('click', '#qam_disp_order_save', function(){
        var $b = $(this).prop('disabled', true);
        $.post(API, {action:'save_disposition_order', order: JSON.stringify(dispDeciders.map(function(u){ return u.id; }))}, function(r){
            $b.prop('disabled', false);
            if (!r || !r.success){ alert((r && r.message) || '儲存失敗'); return; }
            $('#qamDispOrderModal').modal('hide');
            loadDispDeciders();
            alert('決策順序已儲存為預設。');
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });
    $('#qamDispOrderModal').on('shown.bs.modal', function(){
        $(this).css('z-index', 2080);
        $('.modal-backdrop').last().css('z-index', 2070);
    });
    $('#qamDispOrderModal').on('hidden.bs.modal', function(){
        if ($('#qamModal').is(':visible')) $('body').addClass('modal-open');
    });

    // ---------- 異常種類 ----------
    function loadTypes(keepVal){
        var cur = (keepVal !== undefined) ? keepVal : $('#qam_type').val();
        $.post(API, {action:'get_abnormal_types'}, function(r){
            var $s = $('#qam_type').empty().append('<option value="">請選擇異常種類...</option>');
            (r.data||[]).forEach(function(t){ $s.append('<option value="'+t.type_id+'">'+esc(t.type_name)+'</option>'); });
            if (cur) $s.val(cur);
        }, 'json');
    }
    function renderTypeTable(){
        $.post(API, {action:'manage_abnormal_type', sub_action:'get_all'}, function(r){
            var h = (r.data||[]).map(function(t){
                var on = String(t.is_active) === '1';
                return '<tr data-id="'+t.type_id+'" data-active="'+(on?1:0)+'">'
                     + '<td><input type="text" class="form-control input-sm qam-type-name" value="'+esc(t.type_name)+'"></td>'
                     + '<td>'+(on ? '<span class="label label-success">啟用</span>' : '<span class="label label-default">停用</span>')+'</td>'
                     + '<td>'
                     + '<button type="button" class="btn btn-xs btn-primary qam-type-save">儲存</button> '
                     + '<button type="button" class="btn btn-xs btn-warning qam-type-toggle">'+(on?'停用':'啟用')+'</button> '
                     + '<button type="button" class="btn btn-xs btn-danger qam-type-del">刪除</button>'
                     + '</td></tr>';
            }).join('');
            $('#qam_type_tbody').html(h || '<tr><td colspan="3" class="text-muted">尚無資料</td></tr>');
        }, 'json');
    }
    $(document).on('click', '#qam_type_manage_btn', function(){ renderTypeTable(); $('#qamTypeModal').modal('show'); });
    // 疊窗：管理跳窗與其背板墊高於開單跳窗之上；關閉後保留背景 modal 可捲動
    $('#qamTypeModal').on('shown.bs.modal', function(){
        $(this).css('z-index', 2080);
        $('.modal-backdrop').last().css('z-index', 2070);
    });
    $('#qamTypeModal').on('hidden.bs.modal', function(){
        if ($('#qamModal').is(':visible')) $('body').addClass('modal-open');
        loadTypes(); // 回填最新種類清單（保留原選取）
    });
    $(document).on('click', '#qam_add_type_btn', function(){
        var name = $('#qam_new_type_name').val().trim();
        if (!name){ $('#qam_new_type_name').focus(); return; }
        $.post(API, {action:'manage_abnormal_type', sub_action:'add', name:name}, function(){
            $('#qam_new_type_name').val('');
            renderTypeTable();
        }, 'json');
    });
    $(document).on('click', '#qam_type_tbody .qam-type-save', function(){
        var $tr = $(this).closest('tr');
        var name = $tr.find('.qam-type-name').val().trim();
        if (!name) return;
        $.post(API, {action:'manage_abnormal_type', sub_action:'update', id:$tr.data('id'), name:name, active:$tr.data('active')}, function(){ renderTypeTable(); }, 'json');
    });
    $(document).on('click', '#qam_type_tbody .qam-type-toggle', function(){
        var $tr = $(this).closest('tr');
        $.post(API, {action:'manage_abnormal_type', sub_action:'update', id:$tr.data('id'),
                     name:$tr.find('.qam-type-name').val().trim(), active:($tr.data('active') ? 0 : 1)}, function(){ renderTypeTable(); }, 'json');
    });
    $(document).on('click', '#qam_type_tbody .qam-type-del', function(){
        var $tr = $(this).closest('tr');
        if (!confirm('確定刪除「'+$tr.find('.qam-type-name').val()+'」？已使用此種類的異常單將顯示為空白。')) return;
        $.post(API, {action:'manage_abnormal_type', sub_action:'delete', id:$tr.data('id')}, function(){ renderTypeTable(); }, 'json');
    });

    // ---------- 回覆部門 ----------
    function loadDepts(){
        $.post(API, {action:'get_all_depts'}, function(r1){
            deptsAll = r1.data || [];
            $.post(API, {action:'get_dept_config'}, function(r2){
                deptCfg = r2.config || [];
                renderDepts();
            }, 'json');
        }, 'json');
    }
    function renderDepts(){
        var cfgIds = {}; deptCfg.forEach(function(c){ cfgIds[c.id] = parseInt(c.mode)||0; });
        var h = deptsAll.map(function(d){
            var inCfg = cfgIds.hasOwnProperty(d.id);
            return '<div class="qam-dept-row">'
                 + '<label style="font-weight:normal;margin:0;cursor:pointer;min-width:130px;">'
                 + '<input type="checkbox" class="qam-dept-chk" value="'+d.id+'" data-mode="'+(inCfg?cfgIds[d.id]:0)+'" '+(inCfg?'checked':'')+'> '+esc(d.name)+'</label>'
                 + '<select class="form-control input-sm qam-dept-user" data-dept="'+d.id+'" style="display:'+(inCfg?'inline-block':'none')+';"><option value="">整個部門(不指定人)</option></select>'
                 + '</div>';
        }).join('');
        $('#qam_dept_container').html(h || '<span class="text-muted">尚無部門資料</span>');
        // 已勾選者載入人員清單
        $('#qam_dept_container .qam-dept-chk:checked').each(function(){ loadDeptUsers($(this).val(), $(this).data('mode')); });
        renderDeptSelected();
        // 編輯模式：套用異常單既有流程（覆蓋預設勾選）
        if (pendingFlows){ applyFlowPrefill(pendingFlows); pendingFlows = null; }
        // 共同編輯者的部門下拉一併填充
        var $ed = $('#qam_editor_dept');
        if ($ed.find('option').length <= 1){
            deptsAll.forEach(function(d){ $ed.append('<option value="'+d.id+'">'+esc(d.name)+'</option>'); });
        }
    }
    function loadDeptUsers(deptId, mode, selUid){
        $.post(API, {action:'get_dept_users', dept_id:deptId, mode:mode||0}, function(r){
            var $s = $('.qam-dept-user[data-dept="'+deptId+'"]').empty().append('<option value="">整個部門(不指定人)</option>');
            (r.data||[]).forEach(function(u){ $s.append('<option value="'+u.id+'">'+esc(u.user_cname)+(u.position_name?'（'+esc(u.position_name)+'）':'')+'</option>'); });
            if (selUid) $s.val(String(selUid));
            renderDeptSelected();
        }, 'json');
    }
    // 編輯模式：以異常單既有流程預填回覆部門（等 renderDepts 畫完再套用）
    var pendingFlows = null;
    function applyFlowPrefill(flows){
        $('#qam_dept_container .qam-dept-chk').prop('checked', false);
        $('#qam_dept_container .qam-dept-user').hide().val('');
        (flows||[]).forEach(function(f){
            var $chk = $('#qam_dept_container .qam-dept-chk[value="'+f.dept_id+'"]');
            if (!$chk.length) return;
            $chk.prop('checked', true);
            $('.qam-dept-user[data-dept="'+f.dept_id+'"]').show();
            loadDeptUsers(f.dept_id, $chk.data('mode'), f.user_id || '');
        });
        renderDeptSelected();
    }
    function applyFlowsWhenReady(flows){
        if ($('#qam_dept_container .qam-dept-chk').length) applyFlowPrefill(flows);
        else pendingFlows = flows;
    }
    // 已勾選的回覆部門／人員摘要（chip 按 × 可取消勾選）
    function renderDeptSelected(){
        var chips = [];
        $('#qam_dept_container .qam-dept-chk:checked').each(function(){
            var did = $(this).val();
            var dname = $(this).parent().text().trim();
            var $sel = $('.qam-dept-user[data-dept="'+did+'"]');
            var who = $sel.val() ? $sel.find('option:selected').text() : '整個部門';
            chips.push('<span class="qam-chip">'+esc(dname)+'｜'+esc(who)+'<span class="rm rm-dept" data-dept="'+did+'">×</span></span>');
        });
        $('#qam_dept_selected').html(chips.length
            ? '<small class="text-muted" style="margin-right:4px;">已選：</small>' + chips.join('')
            : '<small class="text-muted">尚未勾選回覆部門</small>');
    }
    $(document).on('change', '#qam_dept_container .qam-dept-chk', function(){
        var $sel = $('.qam-dept-user[data-dept="'+$(this).val()+'"]');
        if (this.checked){ $sel.show(); loadDeptUsers($(this).val(), $(this).data('mode')); }
        else { $sel.hide().val(''); }
        renderDeptSelected();
    });
    $(document).on('change', '#qam_dept_container .qam-dept-user', renderDeptSelected);
    $(document).on('click', '#qam_dept_selected .rm-dept', function(){
        var did = $(this).data('dept');
        $('#qam_dept_container .qam-dept-chk[value="'+did+'"]').prop('checked', false);
        $('.qam-dept-user[data-dept="'+did+'"]').hide().val('');
        renderDeptSelected();
    });

    // ---------- 責任單位 ----------
    $(document).on('change', 'input[name=qam_resp_type]', function(){
        var v = $('input[name=qam_resp_type]:checked').val();
        $('#qam_resp_vendor_ui').toggle(v==='vendor');
        $('#qam_resp_dept_ui').toggle(v==='dept');
        if (v === 'dept' && $('#qam_resp_dept option').length <= 1){
            deptsAll.forEach(function(d){ $('#qam_resp_dept').append('<option value="'+d.id+'">'+esc(d.name)+'</option>'); });
        }
    });
    var vendorTimer = null;
    $(document).on('input', '#qam_vendor_kw', function(){
        var kw = $(this).val().trim();
        $('#qam_vendor_id').val('');
        clearTimeout(vendorTimer);
        if (!kw){ $('#qam_vendor_sug').hide(); return; }
        vendorTimer = setTimeout(function(){
            $.post(API, {action:'search_vendors', keyword:kw}, function(r){
                var h = (r.data||[]).map(function(v){
                    return '<div data-id="'+esc(v.maker_id_no)+'" data-name="'+esc(v.maker_name)+'">'+esc(v.maker_name)+' <span class="text-muted">'+esc(v.maker_id_no)+'</span></div>';
                }).join('');
                $('#qam_vendor_sug').html(h || '<div class="text-muted">查無廠商</div>').show();
            }, 'json');
        }, 250);
    });
    $(document).on('click', '#qam_vendor_sug div[data-id]', function(){
        $('#qam_vendor_kw').val($(this).data('name'));
        $('#qam_vendor_id').val($(this).data('id'));
        $('#qam_vendor_sug').hide();
    });
    $(document).on('change', '#qam_resp_dept', function(){
        var did = $(this).val();
        var $p = $('#qam_resp_person').empty().append('<option value="">選擇人員(選填)</option>');
        if (did) $.post(API, {action:'get_dept_users', dept_id:did, mode:0}, function(r){
            (r.data||[]).forEach(function(u){ $p.append('<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'); });
        }, 'json');
    });

    // ---------- 通知 / 追蹤人員 picker ----------
    function renderChips(){
        var mk = function(list, cls){
            return list.map(function(u){ return '<span class="qam-chip">'+esc(u.name)+'<span class="rm" data-id="'+u.id+'" data-list="'+cls+'">×</span></span>'; }).join('');
        };
        $('#qam_notify_chips').html(mk(notifyUsers, 'notify'));
        $('#qam_follower_chips').html(mk(followerUsers, 'follower'));
    }
    $(document).on('click', '#qamModal .qam-chip .rm', function(){
        var id = String($(this).data('id'));
        if ($(this).data('list') === 'notify') notifyUsers = notifyUsers.filter(function(u){ return String(u.id)!==id; });
        else followerUsers = followerUsers.filter(function(u){ return String(u.id)!==id; });
        renderChips();
    });
    function bindUserSearch(inputSel, sugSel, listName){
        var timer = null;
        $(document).on('input', inputSel, function(){
            var kw = $(this).val().trim();
            clearTimeout(timer);
            if (!kw){ $(sugSel).hide(); return; }
            timer = setTimeout(function(){
                $.post(API, {action:'search_users', keyword:kw}, function(r){
                    var h = (r.data||[]).map(function(u){
                        var info = [u.dept_name, u.position_name].filter(Boolean).map(esc).join('／');
                        return '<div data-id="'+u.id+'" data-name="'+esc(u.user_cname)+'">'+esc(u.user_cname)+(info?' <span class="text-muted">'+info+'</span>':'')+'</div>';
                    }).join('');
                    $(sugSel).html(h || '<div class="text-muted">查無人員</div>').show();
                }, 'json');
            }, 250);
        });
        $(document).on('click', sugSel + ' div[data-id]', function(){
            var u = { id: $(this).data('id'), name: $(this).data('name') };
            var list = listName === 'notify' ? notifyUsers : followerUsers;
            if (listName === 'follower' && followerUsers.length >= FOLLOWER_MAX){ alert('追蹤人員最多 ' + FOLLOWER_MAX + ' 名'); return; }
            if (!list.some(function(x){ return String(x.id)===String(u.id); })) list.push(u);
            renderChips();
            $(inputSel).val(''); $(sugSel).hide();
        });
    }
    bindUserSearch('#qam_notify_kw', '#qam_notify_sug', 'notify');
    bindUserSearch('#qam_follower_kw', '#qam_follower_sug', 'follower');

    // ---------- 共同編輯者（部門或最多 5 位人員）＋公開/私人預設名單 ----------
    function renderEditorChips(){
        $('#qam_editor_chips').html(editorList.map(function(e){
            return '<span class="qam-chip">'+esc(e.name)+(e.type==='dept'?'（部門）':'')
                 + '<span class="rm rm-editor" data-type="'+e.type+'" data-id="'+e.id+'">×</span></span>';
        }).join(''));
    }
    $(document).on('click', '#qam_editor_chips .rm-editor', function(){
        var t = $(this).data('type'), id = String($(this).data('id'));
        editorList = editorList.filter(function(e){ return !(e.type===t && String(e.id)===id); });
        renderEditorChips();
    });
    (function(){ // 人員搜尋加入（上限 5 位）
        var timer = null;
        $(document).on('input', '#qam_editor_kw', function(){
            var kw = $(this).val().trim();
            clearTimeout(timer);
            if (!kw){ $('#qam_editor_sug').hide(); return; }
            timer = setTimeout(function(){
                $.post(API, {action:'search_users', keyword:kw}, function(r){
                    var h = (r.data||[]).map(function(u){
                        var info = [u.dept_name, u.position_name].filter(Boolean).map(esc).join('／');
                        return '<div data-id="'+u.id+'" data-name="'+esc(u.user_cname)+'">'+esc(u.user_cname)+(info?' <span class="text-muted">'+info+'</span>':'')+'</div>';
                    }).join('');
                    $('#qam_editor_sug').html(h || '<div class="text-muted">查無人員</div>').show();
                }, 'json');
            }, 250);
        });
        $(document).on('click', '#qam_editor_sug div[data-id]', function(){
            var userCount = editorList.filter(function(e){ return e.type==='user'; }).length;
            if (userCount >= 5){ alert('共同編輯者的人員最多 5 位'); return; }
            var u = { type:'user', id:$(this).data('id'), name:$(this).data('name') };
            if (!editorList.some(function(e){ return e.type==='user' && String(e.id)===String(u.id); })) editorList.push(u);
            renderEditorChips();
            $('#qam_editor_kw').val(''); $('#qam_editor_sug').hide();
        });
    })();
    $(document).on('click', '#qam_editor_dept_add', function(){
        var did = $('#qam_editor_dept').val();
        if (!did){ alert('請先選擇部門'); return; }
        var name = $('#qam_editor_dept option:selected').text();
        if (!editorList.some(function(e){ return e.type==='dept' && String(e.id)===String(did); })){
            editorList.push({ type:'dept', id:parseInt(did,10), name:name });
            renderEditorChips();
        }
    });
    // 預設名單（module='qa'）：私人優先顯示（註明【私人】），下方為公開
    function loadEditorPresets(sel){
        $.get(PRESET_API, {action:'list', module:'qa'}, function(r){
            if (!r || !r.ok) return;
            var h = '<option value="">套用預設名單…</option>';
            (r.data||[]).forEach(function(p){
                h += '<option value="'+p.id+'" data-own="'+(p.is_mine?1:0)+'">'+(p.is_public==1?'':'【私人】')+esc(p.name)+'</option>';
            });
            $('#qam_editor_preset').html(h);
            if (sel) $('#qam_editor_preset').val(String(sel));
        }, 'json');
    }
    $(document).on('change', '#qam_editor_preset', function(){
        var pid = this.value;
        if (!pid) return;
        $.get(PRESET_API, {action:'get', id:pid}, function(r){
            if (!r || !r.ok){ alert((r&&r.msg)||'載入名單失敗'); return; }
            editorList = (r.editors||[]).map(function(e){ return {type:e.type, id:e.id, name:e.name}; });
            renderEditorChips();
        }, 'json');
    });
    $(document).on('click', '#qam_editor_preset_save', function(){
        if (!editorList.length){ alert('請先加入共同編輯者再儲存名單'); return; }
        var name = prompt('請輸入此共同編輯名單的簡稱（提供選擇時顯示）：');
        if (name === null) return;
        name = name.trim();
        if (!name){ alert('名單簡稱不可空白'); return; }
        var isPublic = confirm('要設為「公開名單」讓其他使用者也能選用嗎？\n（確定＝公開，取消＝私人，僅自己可選）') ? 1 : 0;
        $.post(PRESET_API, {action:'save', module:'qa', name:name, is_public:isPublic, editors:JSON.stringify(editorList)}, function(r){
            if (!r || !r.ok){ alert((r&&r.msg)||'儲存失敗'); return; }
            loadEditorPresets(r.id);
            alert('名單「'+name+'」已儲存為'+(isPublic?'公開':'私人')+'名單');
        }, 'json');
    });
    $(document).on('click', '#qam_editor_preset_del', function(){
        var pid = $('#qam_editor_preset').val();
        if (!pid){ alert('請先於下拉選單選取要刪除的名單'); return; }
        if ($('#qam_editor_preset option:selected').data('own') != 1){ alert('僅能刪除自己建立的名單'); return; }
        if (!confirm('確認刪除名單「'+$('#qam_editor_preset option:selected').text()+'」？')) return;
        $.post(PRESET_API, {action:'delete', id:pid}, function(r){
            if (!r || !r.ok){ alert((r&&r.msg)||'刪除失敗'); return; }
            loadEditorPresets();
        }, 'json');
    });
    // 結案 / 取消結案（編輯模式；權限同修改，供之後「未結案追蹤」篩選）
    function qamSetCloseBtn(isClosed){
        $('#qam_close_btn')
            .data('closed', isClosed ? 1 : 0)
            .toggleClass('btn-danger', !isClosed).toggleClass('btn-warning', !!isClosed)
            .html(isClosed ? '<i class="fa fa-undo"></i> 取消結案' : '<i class="fa fa-archive"></i> 結案');
    }
    $(document).on('click', '#qam_close_btn', function(){
        if (!editId) return;
        var closed = $(this).data('closed') == 1;
        var act = closed ? 'reopen_order' : 'close_order';
        if (!confirm(closed ? '確認取消結案？此單將重新列入未結案追蹤。' : '確認結案？結案後可於追蹤功能以「未結案」篩選排除此單。')) return;
        var $b = $(this).prop('disabled', true);
        $.post(API, {action:act, id:editId}, function(r){
            $b.prop('disabled', false);
            if (!r || !r.success){ alert((r && r.message) || '操作失敗'); return; }
            qamSetCloseBtn(parseInt(r.is_closed, 10) === 1);
            alert(parseInt(r.is_closed, 10) === 1 ? '已結案（已寫入編輯記錄）' : '已取消結案（已寫入編輯記錄）');
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    // 編輯記錄（編輯模式）
    $(document).on('click', '#qam_edit_log_btn', function(){
        if (!editId) return;
        $('#qamEditLogNo').text($('#qam_no').val() || '');
        $('#qam_edit_log_body').html('<span class="text-muted">載入中…</span>');
        $('#qamEditLogModal').modal('show');
        $.post(API, {action:'get_order_edit_log', id:editId}, function(r){
            if (!r || !r.success){ $('#qam_edit_log_body').html('<span class="text-danger">載入失敗</span>'); return; }
            if (!(r.data||[]).length){ $('#qam_edit_log_body').html('<span class="text-muted">尚無編輯記錄</span>'); return; }
            var actName = { update:'修改', request:'提出修改請求', grant:'開放修改', close:'結案', reopen:'取消結案', decide:'處置判定', decide_final:'最終裁決' };
            var h = '<table class="table table-striped table-bordered" style="font-size:12.5px;margin-bottom:0;">'
                  + '<thead><tr><th style="width:130px;">時間</th><th style="width:90px;">人員</th><th style="width:100px;">動作</th><th>說明 / 變更內容（僅列有變動欄位）</th></tr></thead><tbody>';
            var fieldNames = { occurrence_date:'發生日期', found_unit:'發現單位', abnormal_phenomenon:'異常現象', abnormal_type_id:'異常種類id',
                               defect_category:'原因分類', defect_detail:'原因說明', disposition:'處置方式', disposition_note:'處置說明',
                               qa_ps:'品管備註', sqty:'異常數量', gm_decision:'總經理裁示', gm_note:'裁示說明',
                               responsible_type:'責任類型', responsible_vendor_id:'責任廠商', responsible_dept_id:'責任部門id',
                               responsible_person_id:'責任人員id', bom_no:'BOM', co_editors:'共同編輯者' };
            r.data.forEach(function(it){
                var det = it.reason ? ('<div><b>說明：</b>'+esc(it.reason)+'</div>') : '';
                try {
                    var b = it.before_json ? JSON.parse(it.before_json) : null;
                    var a = it.after_json ? JSON.parse(it.after_json) : null;
                    if (b && a){
                        Object.keys(fieldNames).forEach(function(k){
                            var ov = b[k] == null ? '' : (Array.isArray(b[k]) ? b[k].join('、') : String(b[k]));
                            var nv = a[k] == null ? '' : (Array.isArray(a[k]) ? a[k].join('、') : String(a[k]));
                            if (ov !== nv) det += '<div><b>'+fieldNames[k]+'</b>：<span style="color:#c0392b;background:#fdf0ee;border-radius:3px;padding:0 4px;">'+esc(ov||'（空）')+'</span> → <span style="color:#169a80;background:#eefaf6;border-radius:3px;padding:0 4px;">'+esc(nv||'（空）')+'</span></div>';
                        });
                    }
                } catch(e){}
                if (!det) det = '<span class="text-muted">（無欄位變動）</span>';
                h += '<tr><td>'+esc(it.changed_at)+'</td><td>'+esc(it.changed_by_name||'')+'</td><td>'+(actName[it.action]||esc(it.action))+'</td><td>'+det+'</td></tr>';
            });
            h += '</tbody></table>';
            $('#qam_edit_log_body').html(h);
        }, 'json');
    });
    $('#qamEditLogModal').on('shown.bs.modal', function(){
        $(this).css('z-index', 2080);
        $('.modal-backdrop').last().css('z-index', 2070);
    });
    $('#qamEditLogModal').on('hidden.bs.modal', function(){
        if ($('#qamModal').is(':visible')) $('body').addClass('modal-open');
    });
    // 點其他地方收合建議
    $(document).on('click', function(e){ if(!$(e.target).closest('#qamModal .form-group, #qam_vendor_kw').length) $('#qamModal .qam-sug').hide(); });

    // 預設名單載入 / 儲存
    function loadDefaults(){
        $.post(API, {action:'get_setting', key:'qa_notify_default_users_named'}, function(r){
            try { notifyUsers = JSON.parse(r.value || '[]') || []; } catch(e){ notifyUsers = []; }
            $.post(API, {action:'get_setting', key:'qa_follower_default_users_named'}, function(r2){
                try { followerUsers = (JSON.parse(r2.value || '[]') || []).slice(0, FOLLOWER_MAX); } catch(e){ followerUsers = []; }
                renderChips();
            }, 'json');
        }, 'json');
    }
    $(document).on('click', '#qam_save_default_btn', function(){
        // 存兩份：含名字(前端顯示用)與純 id(後端預設套用)
        $.post(API, {action:'save_setting', key:'qa_notify_default_users_named', value: JSON.stringify(notifyUsers)});
        $.post(API, {action:'save_setting', key:'qa_notify_default_users', value: JSON.stringify(notifyUsers.map(function(u){return u.id;}))});
        $.post(API, {action:'save_setting', key:'qa_follower_default_users_named', value: JSON.stringify(followerUsers)});
        $.post(API, {action:'save_setting', key:'qa_follower_default_users', value: JSON.stringify(followerUsers.map(function(u){return u.id;}))}, function(){
            $('#qam_default_msg').text('已儲存為預設名單').show();
            setTimeout(function(){ $('#qam_default_msg').fadeOut(); }, 2500);
        }, 'json');
    });

    // ---------- 附件（格式限制：圖片/PDF/Excel/Word；Excel/Word 自動轉 PDF 需預覽確認）----------
    function renderAtt(){
        ['phenomenon','defect_detail','qa_ps'].forEach(function(f){
            $('#qam_att_'+f).html(attach[f].map(function(a){
                return '<span class="egatt-chip">'
                     + '<span class="egatt-name"><i class="fa fa-paperclip"></i> '+esc(a.file_name)+'</span>'
                     + (a.converted ? '<span class="egatt-badge-conv" title="已由 Excel/Word 轉為 PDF">已轉PDF</span>' : '')
                     + '<select class="egatt-tag qam-att-tag" data-id="'+a.id+'" data-field="'+f+'" title="附件標籤">'+EGAtt.tagOptionsHtml(a.tag_id)+'</select>'
                     + '<input type="text" class="egatt-desc qam-att-desc" data-id="'+a.id+'" data-field="'+f+'" value="'+esc(a.description||'')+'" maxlength="255" placeholder="附件說明(選填)" title="修改後自動儲存">'
                     + '<button type="button" class="egatt-del qam-att-del" data-id="'+a.id+'" data-field="'+f+'" title="刪除">×</button></span>';
            }).join(''));
        });
    }
    $(document).on('click', '#qamModal .qam-att-btn', function(){ attField = $(this).data('field'); $('#qam_file_input').val('').click(); });
    $(document).on('change', '#qam_file_input', function(){
        if (!this.files || !this.files[0]) return;
        var file = this.files[0], f = attField;
        this.value = '';
        // 走共用流程：驗證(副檔名+20MB) → Excel選工作表 → 轉PDF → 預覽確認 → 入庫
        EGAtt.process(file, { field_type: f, temp_key: tempKey }, function(item){
            attach[f].push({ id:item.id, file_name:item.file_name, tag_id:item.tag_id||'', description:item.description||'', converted:!!item.converted });
            renderAtt();
        }, function(msg){ alert('上傳失敗：' + msg); });
    });
    // 標籤/說明修改即存（寫異動紀錄；已綁定異常單者會重建檢視快取版）
    $(document).on('change', '#qamModal .qam-att-tag', function(){
        var id = $(this).data('id'), f = $(this).data('field'), val = this.value;
        $.post(API, {action:'att_set_meta', id:id, tag_id:val}, function(r){
            if (!r || !r.success){ alert((r&&r.message)||'標籤儲存失敗'); return; }
            var a = attach[f].find(function(x){ return String(x.id)===String(id); });
            if (a) a.tag_id = val;
        }, 'json');
    });
    var qamDescTimer = {};
    $(document).on('input', '#qamModal .qam-att-desc', function(){
        var id = $(this).data('id'), f = $(this).data('field'), val = this.value;
        clearTimeout(qamDescTimer[id]);
        qamDescTimer[id] = setTimeout(function(){
            $.post(API, {action:'att_set_meta', id:id, description:val}, function(r){
                if (!r || !r.success){ alert((r&&r.message)||'說明儲存失敗'); return; }
                var a = attach[f].find(function(x){ return String(x.id)===String(id); });
                if (a) a.description = val;
            }, 'json');
        }, 600);
    });
    $(document).on('click', '#qamModal .qam-att-del', function(){
        var id = $(this).data('id'), f = $(this).data('field');
        $.post(API, {action:'delete_attachment', id:id}, function(){
            attach[f] = attach[f].filter(function(a){ return String(a.id) !== String(id); });
            renderAtt();
        }, 'json');
    });

    // ---------- 儲存 ----------
    $(document).on('click', '#qam_save_btn', function(){
        // 必填檢查：除「異常處置方式、處置說明、通知人員」外皆必填
        function req(ok, msg, $focus){
            if (ok) return true;
            alert(msg);
            if ($focus && $focus.length) $focus.focus();
            return false;
        }
        var phenomenon = $('#qam_phenomenon').val().trim();
        var respType = $('input[name=qam_resp_type]:checked').val();
        var depts = [];
        $('#qam_dept_container .qam-dept-chk:checked').each(function(){
            depts.push({
                dept_id: parseInt($(this).val()),
                user_id: parseInt($('.qam-dept-user[data-dept="'+$(this).val()+'"]').val()) || null,
                mode: parseInt($(this).data('mode')) || 0
            });
        });
        if (!req($('#qam_type').val(), '請選擇異常種類', $('#qam_type'))) return;
        if (!req($('#qam_occ_date').val(), '請填寫異常發生日期', $('#qam_occ_date'))) return;
        if (!req($('#qam_sqty').val() !== '', '請填寫異常數量', $('#qam_sqty'))) return;
        if (!req($('#qam_found_unit').val(), '請選擇發現單位', $('#qam_found_unit'))) return;
        // 責任單位可選「未指定」（後續可再修改）；選了廠商/部門才驗證明細
        if (respType === 'vendor' && !req($('#qam_vendor_id').val(), '請搜尋並點選責任廠商', $('#qam_vendor_kw'))) return;
        if (respType === 'dept' && !req($('#qam_resp_dept').val(), '請選擇責任部門', $('#qam_resp_dept'))) return;
        if (!req(phenomenon, '請填寫異常現象描述', $('#qam_phenomenon'))) return;
        if (!req($('input[name=qam_defect_category]:checked').length, '請選擇 5M+T 異常原因分類')) return;
        if (!req($('#qam_defect_detail').val().trim(), '請填寫原因分析', $('#qam_defect_detail'))) return;
        if (!req($('#qam_qa_ps').val().trim(), '請填寫品管備註', $('#qam_qa_ps'))) return;
        if (!req(depts.length, '請至少勾選一個回覆部門')) return;
        if (!editId){ // 回覆期限僅開立時必填（修改畫面不變更通知設定；追蹤人員為選填）
            if (!req($('#qam_reply_deadline').val(), '請填寫回覆期限', $('#qam_reply_deadline'))) return;
        }

        var disp = $('input[name=qam_disposition]:checked').map(function(){ return this.value; }).get().join('、');

        // ===== 修改模式：action=update（不變更回覆部門流程與通知設定；留編輯記錄）=====
        if (editId){
            var $ebtn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 儲存中…');
            $.post(API, {
                action: 'update',
                id: editId,
                occurrence_date: $('#qam_occ_date').val(),
                found_unit: $('#qam_found_unit').val(),
                abnormal_phenomenon: phenomenon,
                abnormal_type_id: $('#qam_type').val(),
                defect_category: $('input[name=qam_defect_category]:checked').val() || '',
                defect_detail: $('#qam_defect_detail').val(),
                disposition: disp,
                disposition_note: $('#qam_disposition_note').val(),
                qa_ps: $('#qam_qa_ps').val(),
                sqty: $('#qam_sqty').val(),
                bom_no: $('#qam_bom_no').val(),
                bom_process_fids: $('#qam_bom_process_fids').val(),
                responsible_type: respType,
                responsible_vendor_id: respType==='vendor' ? $('#qam_vendor_id').val() : '',
                responsible_dept_id:   respType==='dept'   ? $('#qam_resp_dept').val() : '',
                responsible_person_id: respType==='dept'   ? $('#qam_resp_person').val() : '',
                // 未在修改畫面顯示的欄位帶回原值，避免被清空
                responsible_unit: (editDetail && editDetail.responsible_unit) || '',
                gm_decision: (editDetail && editDetail.gm_decision) || '',
                gm_note: (editDetail && editDetail.gm_note) || '',
                departments: JSON.stringify(depts),
                temp_key: tempKey,
                co_editors: JSON.stringify(editorList),
                show_attach_inline: $('#qam_show_inline').is(':checked') ? 1 : 0,
                edit_reason: $('#qam_edit_reason').val()
            }, function(res){
                $ebtn.prop('disabled', false).html('<i class="fa fa-check"></i> 儲存修改');
                if (!res.success){
                    if (res.no_perm){ alert('您沒有修改此異常單的權限。'); }
                    else alert('儲存失敗：' + (res.message||''));
                    return;
                }
                saved = true;
                $('#qamModal').modal('hide');
                alert('異常單已更新，並已寫入編輯記錄。' + (res.warn ? '\n\n⚠ ' + res.warn : ''));
                if (opt && typeof opt.onUpdated === 'function') opt.onUpdated({ id: editId });
            }, 'json').fail(function(x){
                $ebtn.prop('disabled', false).html('<i class="fa fa-check"></i> 儲存修改');
                alert('儲存錯誤：' + x.responseText);
            });
            return;
        }

        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 開立中…');
        $.post(API, {
            action: 'create',
            abnormal_order_no: $('#qam_no').val(),
            source_type: $('#qam_source_type').val(),
            source_id:   $('#qam_source_id').val(),
            occurrence_date: $('#qam_occ_date').val(),
            found_unit: $('#qam_found_unit').val(),
            abnormal_phenomenon: phenomenon,
            abnormal_type_id: $('#qam_type').val(),
            defect_category: $('input[name=qam_defect_category]:checked').val() || '',
            defect_detail: $('#qam_defect_detail').val(),
            disposition: disp,
            disposition_note: $('#qam_disposition_note').val(),
            qa_ps: $('#qam_qa_ps').val(),
            sqty: $('#qam_sqty').val(),
            departments: JSON.stringify(depts),
            bom_no: $('#qam_bom_no').val(),
            bom_process_fids: $('#qam_bom_process_fids').val(),
            responsible_type: respType,
            responsible_vendor_id: respType==='vendor' ? $('#qam_vendor_id').val() : '',
            responsible_dept_id:   respType==='dept'   ? $('#qam_resp_dept').val() : '',
            responsible_person_id: respType==='dept'   ? $('#qam_resp_person').val() : '',
            temp_key: tempKey,
            notify_users: JSON.stringify(notifyUsers.map(function(u){ return u.id; })),
            follower_users: JSON.stringify(followerUsers.map(function(u){ return u.id; })),
            co_editors: JSON.stringify(editorList),
            show_attach_inline: $('#qam_show_inline').is(':checked') ? 1 : 0,
            reply_deadline: $('#qam_reply_deadline').val()
        }, function(res){
            $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 確認開立並發送通知');
            if (!res.success){ alert('開立失敗：' + (res.message||'')); return; }
            saved = true;
            $('#qamModal').modal('hide');
            if (res.decide_warn) alert('⚠ ' + res.decide_warn);
            if (opt && typeof opt.onCreated === 'function') opt.onCreated(res);
        }, 'json').fail(function(x){
            $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 確認開立並發送通知');
            alert('開立錯誤：' + x.responseText);
        });
    });

    // 輸入欄位雙擊清除（依專案輸入規範）
    $(document).on('dblclick', '#qamModal input[type=text], #qamModal input[type=number], #qamModal input[type=date], #qamModal textarea', function(){ $(this).val(''); });
    // 聚焦全選
    $(document).on('focus', '#qamModal input[type=text], #qamModal input[type=number]', function(){ var el=this; setTimeout(function(){ try{ el.select(); }catch(e){} }, 0); });

    return { open: open, openEdit: openEdit };
})();
</script>
