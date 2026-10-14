<?php
/**
 * 網頁式填寫跳窗（CSS＋HTML）— 填寫頁與設計器預覽共用，只放樣式與骨架，邏輯在 resource/js/eg_sheet_form_ui.js。
 * 畫面外的試算表引擎容器 #fmEngine 也在這裡（只負責公式計算，使用者看不到）。
 */
?>
<style>
    #fmMask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.5); z-index:10050; overflow:auto; }
    #fmMask.on { display:block; }
    .fm-panel { background:#fff; width:1100px; max-width:96vw; margin:3vh auto; border-radius:8px; box-shadow:0 6px 30px rgba(0,0,0,.35); display:flex; flex-direction:column; max-height:94vh; }
    .fm-head { background:#F7E0BD; color:#5b3a1e; padding:10px 16px; border-radius:8px 8px 0 0; display:flex; align-items:center; gap:10px; }
    .fm-head .fm-title { font-weight:bold; font-size:16px; flex:1; }
    .fm-head .m-close { cursor:pointer; color:#b5762a; font-size:18px; }
    .fm-body { padding:14px 18px; overflow-y:auto; flex:1; color:#5b3a1e; font-size:13.5px; }
    .fm-foot { padding:10px 16px; border-top:1px solid #EADFC8; display:flex; gap:8px; align-items:center; background:#FDF8EF; border-radius:0 0 8px 8px; flex-wrap:wrap; }
    .fm-foot button { height:32px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
    .fm-foot button.b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; }
    .fm-foot button.b-no { background:#DD5138; color:#fff; border-color:#c23f28; }
    .fm-foot button:disabled { opacity:.5; cursor:default; }
    .fm-meta { display:grid; grid-template-columns:2fr 1fr 1.4fr; gap:10px; margin-bottom:12px; }
    .fm-sec { font-weight:bold; color:#8A5A2B; margin:14px 0 8px; padding-bottom:4px; border-bottom:1.5px solid #E8D5B5; }
    .fm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:10px 16px; }
    .fm-fld label { display:block; font-size:12.5px; margin-bottom:3px; color:#5b3a1e; }
    .fm-fld label .req { color:#DD5138; margin-left:2px; }
    .fm-in, .fm-meta input, .fm-meta select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; background:#fff; color:#3d2a14; }
    textarea.fm-in { resize:vertical; min-height:56px; }
    .fm-in:disabled, .fm-meta input:disabled { background:#F7F2E8; color:#6b5a45; }
    .fm-in.fm-bad { border-color:#DD5138; background:#FFF4F1; }
    .fm-msg { color:#DD5138; font-size:12px; margin-top:2px; }
    .fm-chk { display:flex; align-items:center; gap:6px; height:32px; }
    .fm-del { border:none; background:none; color:#DD5138; cursor:pointer; font-size:13px; }
    .fm-addrow { margin-top:8px; height:30px; padding:0 14px; border:1px dashed #D8BE93; border-radius:4px; background:#FDF8EF; color:#8A5A2B; cursor:pointer; }
    .fm-calcbox { display:inline-block; min-width:200px; margin:0 14px 8px 0; }
    .fm-calcbox .v { border:1px solid #E8D5B5; border-radius:4px; background:#FBF6EE; padding:5px 8px; font-weight:bold; min-height:30px; box-sizing:border-box; }
    .fm-card { border:1px solid #E8D5B5; border-radius:8px; margin-bottom:10px; background:#FFFDF8; }
    .fm-card-h { display:flex; justify-content:space-between; align-items:center; padding:6px 12px; background:#FBF0DD; border-radius:8px 8px 0 0; color:#5b3a1e; }
    .fm-cardgrid { padding:10px 12px; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); }
    .fm-fld.fm-wide { grid-column:span 2; }
    .fm-cv { border:1px solid #E8D5B5; border-radius:4px; background:#FBF6EE; padding:5px 8px; font-weight:bold; min-height:30px; box-sizing:border-box; }
    .fm-errsum { color:#DD5138; font-size:12.5px; margin-top:10px; white-space:pre-line; }
    .fm-lkbox { position:fixed; z-index:10200; background:#fff; border:1px solid #D8BE93; border-radius:4px; box-shadow:0 4px 14px rgba(0,0,0,.2); max-height:260px; overflow-y:auto; }
    .fm-lkitem { padding:6px 10px; cursor:pointer; font-size:13px; color:#3d2a14; border-bottom:1px solid #F0E7D5; }
    .fm-lkitem:hover { background:#FBF0DD; }
    .fm-lkitem small { display:block; color:#8a6d45; font-size:11.5px; }
    /* 簽核流程 */
    .fm-flow { border:1px solid #E8D5B5; border-radius:8px; background:#FDF8EF; padding:8px 12px; margin-bottom:12px; }
    .fm-flow .fm-steps { display:flex; flex-wrap:wrap; gap:8px; align-items:stretch; }
    .fm-step { border:1px solid #E0BE86; border-radius:6px; background:#fff; padding:6px 10px; min-width:150px; font-size:12.5px; }
    .fm-step b { color:#8A5A2B; }
    .fm-step.s-done { background:#F3F7E8; border-color:#B7C98A; }
    .fm-step.s-cur { background:#FFF3D1; border-color:#F0A24B; }
    .fm-step.s-rej { background:#FFF4F1; border-color:#DD5138; }
    .fm-step .st-note { color:#8a6d45; font-size:11.5px; margin-top:2px; white-space:pre-line; }
    .fm-decide { border:1.5px solid #F0A24B; background:#FFF8E8; border-radius:8px; padding:10px 12px; margin-bottom:12px; }
    .fm-decide textarea { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; margin-top:6px; }
    /* 手機／窄螢幕：跳窗滿版、欄位單欄 */
    @media (max-width:768px) {
        .fm-panel { width:100vw; max-width:100vw; margin:0; border-radius:0; max-height:100vh; height:100vh; }
        .fm-head { border-radius:0; }
        .fm-body { padding:10px 12px; }
        .fm-meta { grid-template-columns:1fr; }
        .fm-grid, .fm-cardgrid { grid-template-columns:1fr; }
        .fm-fld.fm-wide { grid-column:span 1; }
        .fm-foot { position:sticky; bottom:0; }
        .fm-foot button { flex:1 1 40%; }
        .fm-step { min-width:46%; }
        .fm-in, .fm-meta input, .fm-meta select { font-size:16px; }
    }
    /* 簽章列（表單底部，顯示已簽核者的圖章） */
    .fm-stamps { display:flex; flex-wrap:wrap; gap:14px; margin-top:6px; }
</style>
<div id="fmMask"><div class="fm-panel">
    <div class="fm-head"><span class="fm-title" id="fmTitle"></span><span id="fmState"></span><span class="m-close" id="btnFmX">✕</span></div>
    <div class="fm-body">
        <div class="fm-meta">
            <div class="fm-fld"><label>單據標題</label><input type="text" id="fmName" maxlength="150" placeholder="可留空，方便日後查找"></div>
            <div class="fm-fld"><label>業務日期</label><input type="date" id="fmDate"></div>
            <div class="fm-fld"><label>模板／建立人</label><div id="fmWho" style="padding:6px 0;color:#8a6d45;"></div></div>
        </div>
        <div id="fmDecide" style="display:none;"></div>
        <div id="fmFlow"></div>
        <div id="fmBase"></div>
        <div id="fmDetail"></div>
        <div id="fmCalc"></div>
        <div class="fm-errsum" id="fmErr"></div>
    </div>
    <div class="fm-foot">
        <button id="btnFmPrint"><i class="fa fa-print"></i> 列印</button>
        <span style="flex:1"></span>
        <button id="btnFmWithdraw" style="display:none;"><i class="fa fa-undo"></i> 撤回</button>
        <button id="btnFmSave"><i class="fa fa-save"></i> 儲存草稿</button>
        <button id="btnFmDone" class="b-ok"><i class="fa fa-check"></i> 完成送出</button>
        <button id="btnFmClose">關閉</button>
    </div>
</div></div>
<div id="fmEngine" style="position:fixed;left:-10000px;top:0;width:900px;height:600px;overflow:hidden;" aria-hidden="true"></div>
