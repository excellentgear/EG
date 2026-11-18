<?php
/**
 * 刀具詢價 UI 片段（2026-11-18）——由 views/pm/inquiry_sheet.php 分三段 include：
 *   $TI_PART = 'style'   → CSS（放在 <style> 內）
 *   $TI_PART = 'pane'    → 「刀具詢價」分頁本體（放在 .right_col 內）
 *   $TI_PART = 'modals'  → 新增／編輯、廠商回覆、設定三個跳窗（放在 body 內）
 *   $TI_PART = 'script'  → JS（放在主 script 之後，沿用主頁的 esc／openMask／closeMask／CSRF／PERM）
 * 資料介面 src/store/ToolInq_API.php；共用邏輯 src/common/tool_inq_lib.php。
 */
$TI_PART = $TI_PART ?? '';
if ($TI_PART === 'style') { ?>
.ti-tabs { display:flex; gap:4px; border-bottom:2px solid #E8D5B5; margin:6px 0 10px; clear:both; }
.ti-tabs button { height:34px; padding:0 18px; border:1px solid #E8D5B5; border-bottom:none; border-radius:6px 6px 0 0; background:#FDF8EF; color:#8A5A2B; font-size:14px; cursor:pointer; margin-bottom:-2px; }
.ti-tabs button.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
.ti-sec { border:1px solid #E8D5B5; border-radius:6px; margin:12px 0; background:#fff; }
.ti-sec > h5 { margin:0; padding:7px 12px; background:#FBF2DE; color:#8A5A2B; font-size:14px; font-weight:bold; border-bottom:1px solid #E8D5B5; border-radius:6px 6px 0 0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.ti-sec > h5 small { font-weight:normal; color:#8a6d45; font-size:12px; }
.ti-row { display:grid; grid-template-columns:230px 1fr; gap:8px; align-items:start; padding:6px 12px; border-top:1px dashed #EADFC8; }
.ti-row:first-of-type { border-top:none; }
.ti-row > label { margin:0; padding-top:5px; font-size:13px; color:#5b3a1e; line-height:1.35; }
.ti-row > label small { display:block; color:#8a6d45; font-size:11.5px; }
.ti-cell { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.ti-cell input[type=text], .ti-cell input[type=number], .ti-cell select { height:30px; border:1px solid #D8BE93; border-radius:4px; padding:0 8px; font-size:13px; color:#5b3a1e; background:#fff; }
.ti-cell input.w80 { width:80px; } .ti-cell input.w110 { width:110px; } .ti-cell input.w160 { width:160px; } .ti-cell select.w110 { width:110px; }
.ti-cell textarea { width:100%; min-height:62px; border:1px solid #D8BE93; border-radius:4px; padding:6px 8px; font-size:13px; }
.ti-cell .ti-unit { font-size:12px; color:#8a6d45; }
.ti-cell .ti-ck { display:inline-flex; align-items:center; gap:3px; font-size:12.5px; color:#5b3a1e; margin:0; white-space:nowrap; cursor:pointer; }
.ti-cell .ti-ck input { margin:0; }
.ti-cell input.ti-bad, .ti-cell select.ti-bad { border-color:#DD5138 !important; background:#FFF1EE; }
.ti-err { color:#C4442D; font-size:12px; width:100%; display:none; }
.ti-err.show { display:block; }
.ti-badge { font-size:11px; border-radius:3px; padding:1px 7px; color:#8A5A2B; background:#F7E0BD; white-space:nowrap; }
.ti-badge.confirmed { background:#F0A24B; color:#fff; } .ti-badge.manual { background:#8A5A2B; color:#fff; } .ti-badge.standard { background:#d6c3a3; color:#5b3a1e; }
.ti-mini { height:26px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#8A5A2B; font-size:12px; cursor:pointer; }
.ti-mini:hover { background:#F7E0BD; }
.ti-bind { display:inline-flex; align-items:center; gap:6px; background:#F7E0BD; color:#5b3a1e; border-radius:12px; padding:2px 10px; font-size:12.5px; }
.ti-bind b { color:#8A5A2B; } .ti-bind span.x { cursor:pointer; color:#DD5138; font-weight:bold; }
.ti-sug { position:fixed; z-index:9600; background:#fff; border:1px solid #D8BE93; border-radius:5px; box-shadow:0 6px 18px rgba(0,0,0,.2); max-height:260px; overflow:auto; display:none; min-width:320px; }
.ti-sug div { padding:6px 10px; font-size:13px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F3EADB; }
.ti-sug div:hover { background:#FBF2DE; } .ti-sug div small { color:#8a6d45; margin-left:6px; }
.ti-chips { display:flex; gap:6px; flex-wrap:wrap; }
.ti-chip { display:inline-flex; align-items:center; gap:5px; background:#FBF2DE; border:1px solid #E8D5B5; border-radius:14px; padding:2px 10px; font-size:12.5px; color:#5b3a1e; }
.ti-chip span.x { cursor:pointer; color:#DD5138; font-weight:bold; } .ti-chip.lock span.x { display:none; }
.ti-att { display:flex; align-items:center; gap:8px; font-size:12.5px; padding:3px 0; flex-wrap:wrap; }
.ti-att .cat { background:#8A5A2B; color:#fff; border-radius:3px; padding:0 7px; font-size:11px; white-space:nowrap; }
.ti-att a { color:#b5762a; } .ti-att .del { color:#DD5138; cursor:pointer; font-weight:bold; }
.ti-upl { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:6px; padding-top:6px; border-top:1px dashed #EADFC8; }
.ti-upl select, .ti-upl input[type=text] { height:30px; border:1px solid #D8BE93; border-radius:4px; padding:0 8px; font-size:13px; }
.ti-card { border:1px solid #E8D5B5; border-radius:6px; background:#fff; margin-bottom:8px; }
.ti-card-hd { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:8px 12px; background:#FBF2DE; border-bottom:1px solid #E8D5B5; border-radius:6px 6px 0 0; }
.ti-card-hd .no { font-weight:bold; color:#b5762a; font-size:13.5px; } .ti-card-hd .dt { font-size:11px; color:#999; display:block; }
.ti-card-hd .part { font-weight:bold; color:#5b3a1e; font-size:14px; } .ti-card-hd .cust { font-size:12px; color:#8a6d45; }
.ti-type { font-size:11px; font-weight:bold; color:#fff; background:#8A5A2B; border-radius:3px; padding:2px 8px; }
.ti-st { font-size:11px; border-radius:10px; padding:2px 10px; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
.ti-st.waiting { background:#F7E0BD; } .ti-st.replied { background:#F0A24B; color:#fff; } .ti-st.ordered { background:#d98a33; color:#fff; } .ti-st.received { background:#8A5A2B; color:#fff; } .ti-st.closed, .ti-st.void, .ti-st.none { background:#e6dccb; color:#7a6446; }
.ti-card-sum { padding:6px 12px; font-size:12.5px; color:#5b3a1e; }
.ti-vt { width:100%; border-collapse:collapse; font-size:12.5px; }
.ti-vt th { background:#FDF8EF; color:#8A5A2B; text-align:left; padding:4px 8px; border-top:1px solid #EADFC8; font-weight:bold; }
.ti-vt td { padding:4px 8px; border-top:1px solid #F3EADB; color:#5b3a1e; vertical-align:middle; } .ti-vt td span { line-height:1.3; }
.ti-vt tr.void td { color:#aaa; text-decoration:line-through; }
.ti-late { color:#C4442D; font-weight:bold; }
.ti-card.overdue { border:2px solid #DD5138; box-shadow:0 0 0 2px #FBE1DB; }
.ti-card.overdue .ti-card-hd { background:#FBE1DB; border-bottom-color:#DD5138; }
.ti-pin { font-size:11px; font-weight:bold; color:#fff; background:#DD5138; border-radius:3px; padding:2px 8px; white-space:nowrap; }
.ti-od-note { padding:6px 12px; background:#FFF1EE; color:#C4442D; font-size:12.5px; font-weight:bold; border-bottom:1px solid #F6C9BF; }
#tOverdueBar { margin:0 0 8px; padding:8px 12px; background:#FBE1DB; border:1px solid #DD5138; border-radius:6px; color:#8f2b18; font-size:13px; font-weight:bold; }
.ti-catbtns { display:inline-flex; gap:6px; flex-wrap:wrap; }
.ti-catbtn { height:30px; padding:0 14px; border:1px solid #D8BE93; border-radius:15px; background:#fff; color:#8A5A2B; font-size:13px; cursor:pointer; }
.ti-catbtn:hover { background:#F7E0BD; } .ti-catbtn.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
.ti-stage { display:flex; gap:8px; align-items:center; flex-wrap:wrap; padding:8px 12px; background:#FDF8EF; border:1px dashed #D8BE93; border-radius:6px; margin:12px 0; font-size:13px; color:#5b3a1e; }
.ti-opt-row { display:flex; gap:6px; align-items:center; margin:4px 0; flex-wrap:wrap; }
.ti-opt-row input[type=text] { height:28px; border:1px solid #D8BE93; border-radius:4px; padding:0 8px; width:170px; }
@media (max-width:760px){ .ti-row { grid-template-columns:1fr; } }
<?php return; }

if ($TI_PART === 'pane') { ?>
<div id="paneTool" style="display:none;">
    <div class="iq-toolbar">
        <label>年度</label><select id="tfYear"><option value="<?= date('Y') ?>" selected><?= date('Y') ?></option><option value="">全部</option></select>
        <label>刀種</label><select id="tfType"><option value="">全部</option><option value="shaper">鉋齒刀</option><option value="hob">滾刀</option></select>
        <label>狀態</label><select id="tfStatus"><option value="">全部</option><option value="waiting">等待報價</option><option value="replied">尚未下訂</option><option value="ordered">尚未到貨</option><option value="received">已到貨</option><option value="closed">已結案</option></select>
        <input type="text" id="tfCust" placeholder="客戶" style="width:120px;" title="客戶名稱（簡稱或全名）"><input type="text" id="tfPart" placeholder="料號／圖號" style="width:140px;"><input type="text" id="tfVendor" placeholder="廠商編號／名稱" style="width:140px;"><input type="text" id="tfKw" placeholder="其他：單號／建單人／備註" style="width:180px;">
        <label>每頁</label><select id="tfPsize"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
        <?php if ($perms['canAdmin']): ?><button id="tBtnSet" type="button"><i class="fa fa-cog"></i> 刀具選項設定</button><?php endif; ?>
        <?php if ($perms['canCreate']): ?><button id="tBtnNew" type="button" class="btn-warm" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增刀具詢價</button><?php endif; ?>
    </div>
    <div id="tOverdueBar" style="display:none;"></div>
    <div class="iq-pager" id="tPager"></div>
    <div id="tList"><div style="text-align:center;color:#999;padding:20px;">載入中…</div></div>
</div>
<?php return; }

if ($TI_PART === 'modals') { ?>
<!-- ══ 刀具詢價：新增／編輯 ══ -->
<div class="iq-mask" id="tiMask"><div class="iq-modal" style="max-width:1020px;">
    <div class="m-head"><span id="tiTitle">新增刀具詢價</span><span class="m-close" onclick="closeMask('tiMask')">✕</span></div>
    <div class="m-body" id="tiBody" style="max-height:74vh;">
        <div class="ti-sec"><h5>基本資料</h5>
            <div class="ti-row"><label>詢價單號／日期／詢價人</label><div class="ti-cell"><input type="text" id="tiNo" class="w110 ro-auto" readonly><input type="text" id="tiDate" class="w110 ro-auto" readonly><input type="text" id="tiWho" class="w110 ro-auto" readonly></div></div>
            <div class="ti-row"><label>工件編號 Workpiece No.<small>輸入料號搜尋主檔並挑選＝綁定料號，客戶自動帶入</small></label>
                <div class="ti-cell"><input type="text" id="tiPart" class="w160" placeholder="輸入料號或圖號搜尋…" autocomplete="off" style="width:260px;">
                    <span id="tiPartTag" class="ti-bind" style="display:none;"></span>
                    <select id="tiGearSel" style="display:none;" title="這個料號有多組齒輪規格，選一組帶入"></select><div class="ti-err" id="tiErrPart"></div></div></div>
            <div class="ti-row"><label>客戶</label><div class="ti-cell"><input type="text" id="tiCust" style="width:260px;" placeholder="綁定料號後自動帶入"><span class="ti-unit" id="tiCustHint"></span></div></div>
            <div class="ti-row"><label>需求支數</label><div class="ti-cell"><input type="number" id="tiQty" class="w110" min="1" step="1" inputmode="numeric"><span class="ti-unit">支（選填）</span><div class="ti-err" id="tiErrQty"></div></div></div>
        </div>

        <div class="ti-sec"><h5>工件規格 <small>有料號齒輪資料會自動帶入，皆可手動修改；齒頂徑～齒面寬可勾「已確認」或「標準」</small></h5>
            <div class="ti-row"><label>外齒輪 ／ 內齒輪<small>External / Internal Gear</small></label><div class="ti-cell">
                <label class="ti-ck"><input type="radio" name="tiIo" value="ext" checked> 外齒</label><label class="ti-ck"><input type="radio" name="tiIo" value="int"> 內齒</label></div></div>
            <div class="ti-row"><label>模數<small>Module</small></label><div class="ti-cell">
                <select id="tiModT" class="w80" style="width:80px"><option value="M">M</option><option value="CP">CP</option><option value="DP">DP</option></select>
                <input type="number" id="tiModV" class="w110" step="any" min="0"><span class="ti-unit" id="tiModHint"></span><div class="ti-err" id="tiErrMod"></div></div></div>
            <div class="ti-row"><label>壓力角<small>Pressure angle</small></label><div class="ti-cell"><input type="number" id="tiPa" class="w110" step="any" value="20"><span class="ti-unit">度</span><div class="ti-err" id="tiErrPa"></div></div></div>
            <div class="ti-row"><label>齒數<small>No. of teeth</small></label><div class="ti-cell"><input type="number" id="tiZ" class="w110" step="1" min="1"><div class="ti-err" id="tiErrZ"></div></div></div>
            <div class="ti-row"><label>螺旋角<small>Helix angle（未填＝0）</small></label><div class="ti-cell">
                <label class="ti-ck"><input type="radio" name="tiHx" value="dec" checked> 十進位</label><label class="ti-ck"><input type="radio" name="tiHx" value="dms"> 度分秒</label>
                <span id="tiHxDec"><input type="number" id="tiHxD" class="w110" step="any" min="0"> <span class="ti-unit">度</span></span>
                <span id="tiHxDms" style="display:none;"><input type="number" id="tiHxDd" class="w80" min="0" step="1" style="width:64px"> °
                    <input type="number" id="tiHxMm" class="w80" min="0" max="59" step="1" style="width:64px"> ′ <input type="number" id="tiHxSs" class="w80" min="0" max="59.99" step="any" style="width:72px"> ″</span>
                <span class="ti-unit" id="tiHxConv"></span>
                <select id="tiHxDir" class="w110"><option value="">方向：—</option><option value="RH">右旋 RH</option><option value="LH">左旋 LH</option></select><div class="ti-err" id="tiErrHx"></div></div></div>
            <div class="ti-row"><label>轉位係數 x<small>供自動計算用（選填，預設 0）</small></label><div class="ti-cell"><input type="number" id="tiX" class="w110" step="any"><div class="ti-err" id="tiErrX"></div></div></div>
            <div id="tiCalcWrap"></div>
        </div>

        <div class="ti-sec"><h5>刀具 <small>鉋齒刀或滾刀擇一（皆選填）</small></h5>
            <div class="ti-row"><label>刀具種類</label><div class="ti-cell">
                <label class="ti-ck"><input type="radio" name="tiTt" value="shaper"> 鉋齒刀 Shaper</label><label class="ti-ck"><input type="radio" name="tiTt" value="hob"> 滾刀 HOB</label>
                <button type="button" class="ti-mini" id="tiTtClr">清除選擇</button><span class="ti-unit" id="tiToolHint" style="color:#b5762a;"></span></div></div>
            <div id="tiToolWrap"></div>
        </div>

        <div class="ti-sec"><h5>特殊修整要求 <small>Special modification requirement</small></h5>
            <div class="ti-row"><label>倒角量<small>Semi-topping amount</small></label><div class="ti-cell"><input type="text" id="tiChamfer" class="w160" maxlength="60" placeholder="例：依設計"></div></div>
            <div class="ti-row"><label>放磨量<small>Stock removal</small></label><div class="ti-cell"><input type="text" id="tiStock" class="w160" maxlength="60"></div></div>
            <div class="ti-row"><label>備註<small>Remark（例：工件硬度 HRC29-32）</small></label><div class="ti-cell"><textarea id="tiToolRemark" maxlength="1000"></textarea></div></div>
        </div>

        <div class="ti-sec"><h5>詢價廠商 <small>輸入廠商編號／名稱模糊搜尋後點選；可多家</small></h5>
            <div class="ti-row"><label>加入廠商</label><div class="ti-cell"><input type="text" id="tiVSearch" placeholder="輸入廠商編號或名稱…" autocomplete="off" style="width:320px;"><div class="ti-chips" id="tiChips" style="width:100%;"></div></div></div>
        </div>

        <div class="ti-sec"><h5>詢價附件 <small>圖面、規格書、刀圖…（新增時就能上傳，存檔後才正式歸屬）</small></h5>
            <div style="padding:6px 12px;"><div id="tiAtt"></div>
                <div class="ti-upl"><span class="ti-unit">類別（必選）</span><span class="ti-catbtns" id="tiAttCat" data-val=""></span>
                    <input type="file" id="tiAttFile"><input type="text" id="tiAttNote" placeholder="說明（選填）" style="width:180px;"><button type="button" class="ti-mini" id="tiAttBtn">上傳</button><span class="ti-unit" id="tiAttMsg"></span></div></div>
        </div>
    </div>
    <div class="m-foot"><button onclick="closeMask('tiMask')">取消</button><button class="b-ok" id="tiSave">儲存</button></div>
</div></div>

<!-- ══ 刀具詢價：廠商回覆／下單／交期 ══ -->
<div class="iq-mask" id="tvMask"><div class="iq-modal" style="max-width:820px;">
    <div class="m-head"><span id="tvTitle">廠商回覆</span><span class="m-close" onclick="closeMask('tvMask')">✕</span></div>
    <div class="m-body" style="max-height:74vh;">
        <div id="tvInfo" style="font-size:12.5px;color:#8a6d45;margin-bottom:6px;"></div>
        <div class="ti-sec"><h5>① 廠商回覆內容 <small>廠商回覆後先填這裡</small></h5>
            <div class="ti-row"><label>廠商回覆日<small>預設今天</small></label><div class="ti-cell"><input type="date" id="tvReply"></div></div>
            <div class="ti-row"><label>廠商單價</label><div class="ti-cell"><select id="tvCur" class="w80" style="width:80px"></select><input type="number" id="tvPrice" class="w110" step="any" min="0"><div class="ti-err" id="tvErrPrice"></div></div></div>
            <div class="ti-row"><label>交期天數<small>廠商回覆的交貨天數</small></label><div class="ti-cell"><input type="number" id="tvLead" class="w110" step="1" min="0"><span class="ti-unit">天</span>
                <label class="ti-ck"><input type="radio" name="tvUnit" value="work" checked> 上班日</label><label class="ti-ck"><input type="radio" name="tvUnit" value="cal"> 日曆日</label></div></div>
            <div class="ti-row"><label>廠商備註</label><div class="ti-cell"><textarea id="tvNote" maxlength="1000"></textarea></div></div>
            <div class="ti-row"><label>廠商提供的附件<small>報價單、刀圖…</small></label><div class="ti-cell" style="display:block;"><div id="tvAtt"></div>
                <div class="ti-upl" style="border-top:none;margin-top:0;"><span class="ti-unit">類別（必選）</span><span class="ti-catbtns" id="tvAttCat" data-val=""></span>
                    <input type="file" id="tvAttFile"><input type="text" id="tvAttNote" placeholder="說明（選填）" style="width:160px;"><button type="button" class="ti-mini" id="tvAttBtn">上傳</button><span class="ti-unit" id="tvAttMsg"></span></div></div></div>
        </div>
        <div class="ti-stage" id="tvOrderBar"><span>決定向這家廠商下單時：</span><button type="button" class="ti-mini warm" id="tvDoOrder" style="height:30px;">② 下訂</button></div>
        <div class="ti-sec" id="tvOrderSec" style="display:none;"><h5>② 下訂與交期提醒</h5>
            <div class="ti-row"><label>下訂日</label><div class="ti-cell"><input type="date" id="tvOrder"></div></div>
            <div class="ti-row"><label>交期<small>留空時，儲存會依「下訂日＋交期天數」自動算出</small></label><div class="ti-cell"><input type="date" id="tvDue"><button type="button" class="ti-mini" id="tvDueCalc">依交期天數算出</button></div></div>
            <div class="ti-row"><label>交期前提醒<small>交期前 N 天、以及逾期各通知一次（建單人＋其單位主管）</small></label><div class="ti-cell"><input type="number" id="tvRemind" class="w80" style="width:80px" step="1" min="0" max="60" value="3"><span class="ti-unit">天（0＝交期當天才提醒）</span><div class="ti-err" id="tvErrRemind"></div></div></div>
        </div>
        <div class="ti-stage" id="tvRecvBar" style="display:none;"><input type="hidden" id="tvRecv"><span id="tvRecvTxt"></span><button type="button" class="ti-mini warm" id="tvDoRecv" style="height:30px;">③ 已到貨</button><button type="button" class="ti-mini" id="tvUndoRecv" style="display:none;">取消到貨</button></div>
        <div class="ti-sec"><h5>其他</h5><div class="ti-row"><label>不採用這家廠商</label><div class="ti-cell"><label class="ti-ck"><input type="checkbox" id="tvVoid"> 這次不採用這家廠商（例如報價太高、交期太長或婉拒）</label>
            <span class="ti-unit" style="width:100%;">勾選後這家的紀錄會保留，但不計入整張詢價的進度、也不再發交期提醒；已經回覆或有附件的廠商不能直接從詢價單移除，要用這個標記代替。</span></div></div></div>
        <div class="ti-sec"><h5>詢價單附件（唯讀）</h5><div style="padding:6px 12px;" id="tvInqAtt"></div></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('tvMask')">關閉</button><button class="b-ok" id="tvSave">儲存</button></div>
</div></div>

<!-- ══ 刀具選項設定（管理員） ══ -->
<div class="iq-mask" id="tsMask"><div class="iq-modal" style="max-width:640px;">
    <div class="m-head"><span>刀具選項設定</span><span class="m-close" onclick="closeMask('tsMask')">✕</span></div>
    <div class="m-body" id="tsBody" style="max-height:74vh;"></div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('tsMask')">關閉</button></div>
</div></div>
<!-- ══ 刀具詢價：列印選擇（多家廠商時） ══ -->
<div class="iq-mask" id="tpMask"><div class="iq-modal" style="max-width:520px;">
    <div class="m-head"><span>列印刀具詢價單</span><span class="m-close" onclick="closeMask('tpMask')">✕</span></div>
    <div class="m-body">
        <div id="tpInfo" style="font-size:12.5px;color:#8a6d45;margin-bottom:8px;"></div>
        <div style="margin-bottom:6px;"><button type="button" class="ti-mini" id="tpAll">全選</button> <button type="button" class="ti-mini" id="tpNone">全不選</button></div>
        <div id="tpList"></div>
        <div class="ti-unit" style="margin-top:8px;display:block;">每家廠商各印一頁（頁首帶該廠商的聯絡人、電話、傳真）。「全部列印」不含已標記不採用的廠商。</div>
    </div>
    <div class="m-foot"><button onclick="closeMask('tpMask')">取消</button><button type="button" id="tpPrintAll">全部列印</button><button class="b-ok" id="tpPrintSel">列印勾選的</button></div>
</div></div>
<div class="ti-sug" id="tiSug"></div>
<?php return; }

if ($TI_PART === 'script') { ?>
<script>
(function(){
var TAPI = '../../src/store/ToolInq_API.php';
var T = { init:null, page:1, pages:1, loaded:false, cur:null, tempKey:'', vendors:[], partId:0, gears:[], fwAuto:null, canEdit:true, vcur:null };
var PI = Math.PI;
var CALC = {}, CALC_DEF = {
  da:{lb:'齒頂徑', en:'Out. Dia.', two:false, std:true}, df:{lb:'齒底徑', en:'Root dia.', two:false, std:true},
  depth:{lb:'全齒深', en:'D+F', two:false, std:true},
  wk:{lb:'跨齒厚 ／ 跨齒數', en:'Spanned t. thickness / tooth no.', two:true, l1:'跨齒厚', l2:'跨齒數', std:true},
  pin:{lb:'跨銷值 ／ 跨銷徑', en:'Over pin value / pin dia.', two:true, l1:'跨銷值', l2:'跨銷徑', std:true},
  fw:{lb:'齒面寬', en:'Face width', two:false, std:false}
};
var STAGE = {waiting:'等待報價', replied:'尚未下訂', ordered:'尚未到貨', received:'已到貨', void:'不採用'};   // 已報價但未下訂＝尚未下訂；已下訂但未到貨＝尚未到貨
var OVERALL = {none:'尚未選廠商', waiting:'等待報價', replied:'尚未下訂', ordered:'尚未到貨', received:'已到貨', void:'廠商皆不採用', closed:'已結案'};
var TYPE_LB = {shaper:'鉋齒刀', hob:'滾刀'};

function tget(action, data, cb){
  $.getJSON(TAPI, $.extend({action:action}, data||{}), function(res){ if(!res.success){ alert(res.message||'操作失敗'); return; } cb && cb(res); }).fail(function(){ alert('連線失敗'); });
}
function tpost(action, data, cb, errcb){
  $.post(TAPI, $.extend({action:action, csrf:CSRF}, data||{}), function(res){
    if(!res.success){ if(errcb) errcb(res); else alert(res.message||'操作失敗'); return; } cb && cb(res);
  }, 'json').fail(function(){ alert('連線失敗'); });
}
function nz(v){ return (v===null||v===undefined||v==='') ? '' : v; }
function rnd(v,n){ if(v===null||!isFinite(v)) return null; var f=Math.pow(10,n||4); return Math.round(v*f)/f; }
function dt(v){ return v ? egFmtDate(v) : ''; }

/* ───────── 分頁切換 ───────── */
function showTab(tab){
  $('.ti-tabs button').removeClass('on').filter('[data-tab="'+tab+'"]').addClass('on');
  $('#paneGen').toggle(tab==='gen'); $('#paneTool').toggle(tab==='tool'); $('#btnSettings').toggle(tab==='gen');
  try { history.replaceState(null,'',location.pathname+(tab==='tool'?'?tab=tool':'')); } catch(e){}
  if (tab==='tool' && !T.loaded) { T.loaded = true; tget('init', {}, function(r){ T.init = r; fillYears(r.years); loadList(1); deepLink(); }); }
}
$('.ti-tabs button').on('click', function(){ showTab($(this).data('tab')); });
function fillYears(ys){
  var $s = $('#tfYear'), cur = $s.val(); $s.empty();
  var thisY = String(new Date().getFullYear()), set = {}; set[thisY] = 1; (ys||[]).forEach(function(y){ set[y]=1; });
  Object.keys(set).sort().reverse().forEach(function(y){ $s.append('<option value="'+y+'">'+y+'</option>'); });
  $s.append('<option value="">全部</option>'); $s.val(cur===''?'':(set[cur]?cur:thisY));
}
function deepLink(){ var m = location.search.match(/[?&]id=(\d+)/); if (m) openEdit(parseInt(m[1],10)); }

/* ───────── 清單 ───────── */
function filters(){ return {year:$('#tfYear').val(), status:$('#tfStatus').val(), tool_type:$('#tfType').val(), customer:$('#tfCust').val(), part:$('#tfPart').val(), vendor:$('#tfVendor').val(), kw:$('#tfKw').val(), psize:$('#tfPsize').val()}; }
var LSEQ = 0;
function loadList(page){
  T.page = page || 1; var seq = ++LSEQ;
  $.getJSON(TAPI, $.extend({action:'list', page:T.page}, filters()), function(res){
    if (seq !== LSEQ) return;
    if (!res.success) { alert(res.message||'載入失敗'); return; }
    T.pages = res.pages; T.page = res.page; renderList(res.rows, res.total); renderPager();
    var $ob = $('#tOverdueBar'); if (res.overdue_total>0) $ob.text('⚠ 有 '+res.overdue_total+' 張刀具詢價的交期已逾期，已自動置頂（填寫「已到貨」或結案後自動取消置頂）').show(); else $ob.hide();
  });
}
$('#tfYear,#tfStatus,#tfType,#tfPsize').on('change', function(){ loadList(1); });
var kwTimer; $('#tfKw,#tfCust,#tfPart,#tfVendor').on('input', function(){ clearTimeout(kwTimer); kwTimer = setTimeout(function(){ loadList(1); }, 300); });
function renderPager(){
  var $p = $('#tPager').empty();
  for (var i=1;i<=T.pages;i++) (function(i){ $('<button>'+i+'</button>').toggleClass('on', i===T.page).on('click', function(){ loadList(i); }).appendTo($p); })(i);
}
function specSum(r){
  var s = r.spec||{}, a=[];
  if (r.gear_io) a.push(r.gear_io==='int'?'內齒':'外齒');
  if (s.module_val) a.push(s.module_type+s.module_val);
  if (s.teeth) a.push('z'+s.teeth);
  if (s.pa) a.push('α'+s.pa+'°');
  if (s.helix_deg) a.push('β'+s.helix_deg+'°'+(s.helix_dir||''));
  if (s.fw && s.fw.v) a.push('齒寬'+s.fw.v);
  return a.join('　');
}
function toolSum(r){
  var t = r.tool||{}, a=[];
  if (r.tool_type) a.push(TYPE_LB[r.tool_type]);
  if (t.od) a.push('外徑'+t.od); if (t.len) a.push('長'+t.len); if (t.bore) a.push('孔'+t.bore);
  if (r.accuracy_name) a.push('精度'+r.accuracy_name); if (r.material_name) a.push(r.material_name); if (r.coating_name) a.push(r.coating_name);
  return a.join('　');
}
function renderList(rows, total){
  var $l = $('#tList').empty();
  if (!rows.length) { $l.append('<div style="text-align:center;color:#999;padding:20px;">沒有符合條件的刀具詢價單</div>'); return; }
  rows.forEach(function(r){
    var canEdit = PERM.canAdmin || r.created_by===PERM.uid || (PERM.myDepts||[]).indexOf('sales')>=0;
    var od = (r.overdue||[]); var $c = $('<div class="ti-card'+(od.length?' overdue':'')+'"></div>');
    var hd = '<div class="ti-card-hd"><div><span class="no">'+esc(r.inq_no)+'</span><span class="dt">'+esc(dt(r.inq_date))+'</span></div>'
      + '<div><span class="part">'+esc(r.part_no)+'</span><br><span class="cust">'+esc(r.customer||'（未填客戶）')+'</span></div>'
      + (r.tool_type?'<span class="ti-type">'+TYPE_LB[r.tool_type]+'</span>':'')
      + (r.qty?'<span style="font-size:12px;">×'+r.qty+' 支</span>':'')
      + '<span style="font-size:12px;color:#8a6d45;">'+esc(r.created_by_name)+'</span>'
      + (od.length?'<span class="ti-pin">📌 交期逾期</span>':'')
      + '<span class="ti-st '+r.status+'">'+OVERALL[r.status]+'</span>'
      + '<span style="margin-left:auto;display:flex;gap:6px;">'
      + '<button class="ti-mini" data-act="print" data-id="'+r.id+'">列印</button>'
      + '<button class="ti-mini" data-act="edit" data-id="'+r.id+'">'+(canEdit?'編輯':'檢視')+'</button>'
      + (canEdit?'<button class="ti-mini" data-act="close" data-id="'+r.id+'" data-v="'+(r.is_closed?0:1)+'">'+(r.is_closed?'重新開啟':'結案')+'</button><button class="ti-mini" style="color:#DD5138;" data-act="del" data-id="'+r.id+'">刪除</button>':'')
      + '</span></div>';
    $c.append(hd);
    if (od.length) $c.append('<div class="ti-od-note">⚠ '+od.map(function(x){ return esc(x.vendor)+' 交期 '+esc(dt(x.due))+' 已逾期 '+x.days+' 天'; }).join('；')+'　（已置頂，到貨後自動取消）</div>');
    $c.append('<div class="ti-card-sum">'+esc(specSum(r)||'（未填工件規格）')+'<br><span style="color:#8a6d45;">'+esc(toolSum(r)||'（未填刀具規格）')+'</span>'+(r.att_count?'　<span style="color:#8a6d45;">📎'+r.att_count+'</span>':'')+'</div>');
    if (r.vendors.length) {
      var t = '<table class="ti-vt"><tr><th>廠商</th><th>狀態</th><th>回覆日</th><th>單價</th><th>下訂日</th><th>交期</th><th></th></tr>';
      r.vendors.forEach(function(v){
        var late = v.due_date && !v.received_date && v.stage!=='void' && v.due_date < todayStr();
        t += '<tr class="'+(v.stage==='void'?'void':'')+'"><td>'+esc(v.vendor_id_no)+' '+esc(v.vendor_name)+'</td><td>'+STAGE[v.stage]+'</td><td>'+esc(dt(v.reply_date))+'</td>'
          + '<td>'+(v.unit_price!==null?esc(v.currency||'')+' '+v.unit_price:'')+'</td><td>'+esc(dt(v.order_date))+'</td>'
          + '<td'+(late?' class="ti-late"':'')+'>'+esc(dt(v.due_date))+(late?'（逾期）':'')+'</td>'
          + '<td><button class="ti-mini" data-act="vendor" data-id="'+r.id+'" data-vid="'+v.vid+'">'+(canEdit?'回覆／下單':'檢視')+'</button></td></tr>';
      });
      $c.append(t+'</table>');
    }
    $l.append($c);
  });
}
function todayStr(){ var d=new Date(); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
$('#tList').on('click', '[data-act]', function(){
  var id = parseInt($(this).data('id'),10), act = $(this).data('act');
  if (act==='edit') openEdit(id);
  else if (act==='print') startPrint(id);
  else if (act==='vendor') openVendor(id, parseInt($(this).data('vid'),10));
  else if (act==='close') tpost('set_closed', {id:id, closed:$(this).data('v')}, function(){ loadList(T.page); });
  else if (act==='del') { if (confirm('確定刪除這張刀具詢價單？廠商回覆與附件會一併刪除，無法復原。')) tpost('delete', {id:id}, function(){ loadList(T.page); }); }
});
$('#tBtnNew').on('click', function(){ openNew(); });

/* ───────── 計算（沿用齒輪計算工具同一套公式；外齒／內齒） ───────── */
function inv(a){ return Math.tan(a)-a; }
function newton(invp){
  var LIM = 89*PI/180, ap = Math.cbrt(3*invp); if (ap>=LIM) return null;
  for (var i=0;i<100;i++){ if (ap>=LIM||ap<=0) return null; var fp=Math.tan(ap)*Math.tan(ap); if(fp===0)break; var an=ap-(Math.tan(ap)-ap-invp)/fp; var df=Math.abs(an-ap); ap=an; if(df<=1e-10)break; }
  return (ap>=LIM||ap<=0) ? null : ap;
}
function params(){
  var v = parseFloat($('#tiModV').val()), t = $('#tiModT').val();
  var mn = !(v>0) ? null : (t==='CP' ? v/PI : (t==='DP' ? 25.4/v : v));
  var z = parseInt($('#tiZ').val(),10), an = parseFloat($('#tiPa').val()), beta = helixDeg();
  var x = parseFloat($('#tiX').val());
  return {mn:mn, z:(z>=2?z:null), an:(an>0&&an<90?an:null), beta:(beta>=0&&beta<90?beta:0), x:(isNaN(x)?0:x), ext:$('input[name=tiIo]:checked').val()!=='int'};
}
function autoValues(){
  var p = params(), o = {da:[null,null], df:[null,null], depth:[null,null], wk:[null,null], pin:[null,null], fw:[T.fwAuto,null]};
  if (!p.mn || !p.z || !p.an) return o;
  var an = p.an*PI/180, beta = p.beta*PI/180, cb = Math.cos(beta), mt = p.mn/cb, d = mt*p.z, at = Math.atan(Math.tan(an)/cb), db = d*Math.cos(at);
  var dp = rnd(1.68*p.mn, 2), sbb = Math.sin(beta)*Math.cos(an), cbb = Math.sqrt(1-sbb*sbb);
  if (p.ext) {
    var da = d+2*p.mn*(1+p.x), df = d-2*p.mn*(1.25-p.x);
    o.da[0]=rnd(da,3); o.df[0]=rnd(df,3); o.depth[0]=rnd((da-df)/2,3);
    var k = Math.max(1, Math.round(p.z*p.an/180+0.5));
    o.wk = [rnd(p.mn*Math.cos(an)*(PI*(k-0.5)+p.z*inv(at))+2*p.x*p.mn*Math.sin(an),3), k];
    var sd = PI/(2*p.z)+2*p.x*Math.tan(an)/p.z, ip = sd+inv(at)+dp/(db*cbb)-PI/p.z, ap = ip>0 ? newton(ip) : null;
    if (ap) { var dc = db/Math.cos(ap); o.pin = [rnd((p.z%2===0)? dc+dp : dc*Math.cos(PI/(2*p.z))+dp, 3), dp]; }
  } else {
    var di = d-2*p.mn*(1+p.x), Df = d+2*p.mn*(1.25-p.x);
    o.da[0]=rnd(di,3); o.df[0]=rnd(Df,3); o.depth[0]=rnd((Df-di)/2,3);
    if (di>db) {
      var ed = PI/(2*p.z)-2*p.x*Math.tan(an)/p.z, ip2 = ed+inv(at)-dp/(db*cbb), ap2 = ip2>0 ? newton(ip2) : null;
      if (ap2) { var dc2 = db/Math.cos(ap2); o.pin = [rnd((p.z%2===0)? dc2-dp : dc2*Math.cos(PI/(2*p.z))-dp, 3), dp]; }
    }
  }
  return o;
}
function initCalc(){ Object.keys(CALC_DEF).forEach(function(k){ CALC[k] = {st:'auto', v:null, v2:null}; }); }
function buildCalcRows(){
  var h = '';
  Object.keys(CALC_DEF).forEach(function(k){
    var c = CALC_DEF[k];
    if (k==='wk') h += '<div class="ti-row" id="tiMeasRow"><label>跨量測方式<small>跨齒厚或跨銷，擇一</small></label><div class="ti-cell"><label class="ti-ck"><input type="radio" name="tiMeas" value="wk" checked> 跨齒厚／跨齒數</label><label class="ti-ck"><input type="radio" name="tiMeas" value="pin"> 跨銷值／跨銷徑</label></div></div>';
    h += '<div class="ti-row ti-calc" data-k="'+k+'"><label>'+c.lb+'<small>'+c.en+'</small></label><div class="ti-cell">'
      + (c.two?'<span class="ti-unit">'+c.l1+'</span>':'')+'<input type="number" step="any" class="w110 cv1">'
      + (c.two?'<span class="ti-unit">'+c.l2+'</span><input type="number" step="any" class="w80 cv2">':'')
      + '<label class="ti-ck"><input type="checkbox" class="cck"> 已確認</label>'
      + (c.std?'<label class="ti-ck"><input type="checkbox" class="cstd"> 標準</label>':'')
      + '<button type="button" class="ti-mini crst" title="放棄修改，回到自動計算">↺ 自動</button><span class="ti-badge"></span><div class="ti-err"></div></div></div>';
  });
  $('#tiCalcWrap').html(h);
}
var BLB = {auto:'自動計算', confirmed:'已確認', manual:'人工修改', standard:'標準'};
function paintCalc(k){
  var c = CALC[k], $r = $('#tiCalcWrap .ti-calc[data-k="'+k+'"]'), std = c.st==='standard';
  $r.find('.cv1').val(std?'':nz(c.v)).prop('disabled', std||!T.canEdit).attr('placeholder', std?'標準':'');
  $r.find('.cv2').val(std?'':nz(c.v2)).prop('disabled', std||!T.canEdit);
  $r.find('.cck').prop('checked', c.st==='confirmed'||c.st==='manual').prop('disabled', std||!T.canEdit);
  $r.find('.cstd').prop('checked', std).prop('disabled', !T.canEdit);
  $r.find('.ti-badge').attr('class','ti-badge '+c.st).text(BLB[c.st]);
}
var MEAS = 'wk', MEAS_USER = false;   // 跨量測方式：沒手動選過就跟著外齒（跨齒厚）／內齒（跨銷）
function setMeas(m, user){
  MEAS = m; if (user) MEAS_USER = true;
  $('input[name=tiMeas][value='+m+']').prop('checked', true);
  $('#tiCalcWrap .ti-calc[data-k=wk]').toggle(m==='wk'); $('#tiCalcWrap .ti-calc[data-k=pin]').toggle(m==='pin');
}
$('#tiCalcWrap').on('change', 'input[name=tiMeas]', function(){ setMeas(this.value, true); });
function defaultMeas(){ if (!MEAS_USER) setMeas($('input[name=tiIo]:checked').val()==='int' ? 'pin' : 'wk', false); }
function recalc(){
  var a = autoValues();
  Object.keys(CALC).forEach(function(k){ if (CALC[k].st==='auto') { CALC[k].v = a[k][0]; CALC[k].v2 = a[k][1]; } paintCalc(k); });
}
$('#tiCalcWrap').on('input', '.cv1,.cv2', function(){
  var k = $(this).closest('.ti-calc').data('k'), $r = $(this).closest('.ti-calc'), c = CALC[k];
  c.st = 'manual'; var v1 = $r.find('.cv1').val(), v2 = $r.find('.cv2').length ? $r.find('.cv2').val() : '';
  c.v = v1===''?null:parseFloat(v1); c.v2 = v2===''?null:parseFloat(v2);
  var bad = (v1!==''&&!(parseFloat(v1)>=0))||(v2!==''&&!(parseFloat(v2)>=0));
  $r.find('.ti-err').text(bad?'請填 0 以上的數字':'').toggleClass('show', bad);
  $r.find('.cck').prop('checked', true); $r.find('.cstd').prop('checked', false); $r.find('.ti-badge').attr('class','ti-badge manual').text(BLB.manual);   // 人工修改＝自動視為已確認
});
$('#tiCalcWrap').on('change', '.cck', function(){ var k=$(this).closest('.ti-calc').data('k'); if (this.checked) { if (CALC[k].st==='auto') CALC[k].st='confirmed'; } else { CALC[k].st='auto'; recalc(); return; } paintCalc(k); });
$('#tiCalcWrap').on('change', '.cstd', function(){ var k=$(this).closest('.ti-calc').data('k'); if (this.checked) { CALC[k].st='standard'; CALC[k].v=null; CALC[k].v2=null; } else { CALC[k].st='auto'; recalc(); return; } paintCalc(k); });
$('#tiCalcWrap').on('click', '.crst', function(){ var k=$(this).closest('.ti-calc').data('k'); CALC[k].st='auto'; recalc(); });

/* 螺旋角：十進位 ⇄ 度分秒 自動換算（唯一真值是十進位度數） */
function helixDeg(){
  if ($('input[name=tiHx]:checked').val()==='dms') {
    var d=parseFloat($('#tiHxDd').val())||0, m=parseFloat($('#tiHxMm').val())||0, s=parseFloat($('#tiHxSs').val())||0;
    return ($('#tiHxDd').val()===''&&$('#tiHxMm').val()===''&&$('#tiHxSs').val()==='') ? null : d+m/60+s/3600;
  }
  var v=parseFloat($('#tiHxD').val()); return isNaN(v)?null:v;
}
function toDms(deg){
  var d=Math.floor(deg), mf=(deg-d)*60, m=Math.floor(mf), s=rnd((mf-m)*60,2);
  if (s>=60){ s=0; m++; } if (m>=60){ m=0; d++; } return {d:d,m:m,s:s};
}
var HXDEG = null;   // 螺旋角真值（十進位度數）；十進位／度分秒兩種輸入共用，切換模式時從它換算
function setHelix(deg, mode){
  if (deg===undefined||deg===null||isNaN(deg)) deg = 0;   // 螺旋角未填＝0
  HXDEG = deg;
  if (mode) $('input[name=tiHx][value='+mode+']').prop('checked', true);
  var dms = $('input[name=tiHx]:checked').val()==='dms';
  $('#tiHxDec').toggle(!dms); $('#tiHxDms').toggle(dms);
  if (deg===null||deg===undefined||isNaN(deg)) { $('#tiHxD,#tiHxDd,#tiHxMm,#tiHxSs').val(''); $('#tiHxConv').text(''); return; }
  var x = toDms(deg);
  $('#tiHxD').val(rnd(deg,6)); $('#tiHxDd').val(x.d); $('#tiHxMm').val(x.m); $('#tiHxSs').val(x.s);
  $('#tiHxConv').text(dms ? '＝ '+rnd(deg,6)+' 度' : '＝ '+x.d+'° '+x.m+'′ '+x.s+'″');
}
$('input[name=tiHx]').on('change', function(){ setHelix(HXDEG, null); recalc(); });
$('#tiHxD,#tiHxDd,#tiHxMm,#tiHxSs').on('input', function(){
  var deg = helixDeg(), dms = $('input[name=tiHx]:checked').val()==='dms';
  HXDEG = deg;
  $('#tiHxConv').text(deg===null?'':(dms ? '＝ '+rnd(deg,6)+' 度' : (function(){ var x=toDms(deg); return '＝ '+x.d+'° '+x.m+'′ '+x.s+'″'; })()));
  check('#tiHxD', function(){ return deg!==null && (deg<0||deg>=90) ? '螺旋角必須在 0 ～ 90 度之間' : ''; }, '#tiErrHx');
  recalc();
});
$('#tiModT,#tiModV,#tiPa,#tiZ,#tiX,input[name=tiIo]').on('input change', function(){ defaultMeas(); recalc(); updModHint(); });
function updModHint(){
  var p = params(), t = $('#tiModT').val();
  $('#tiModHint').text(p.mn && t!=='M' ? '＝ M'+rnd(p.mn,4) : '');
}

/* ───────── 即時驗證（前端擋一次，後端同規則再擋） ───────── */
function check(sel, fn, errSel){
  var msg = fn() || '', $e = $(errSel);
  $(sel).toggleClass('ti-bad', !!msg); $e.text(msg).toggleClass('show', !!msg); return msg;
}
function posInt(v, label, exclMax){
  if (v==='') return ''; if (!/^\d+$/.test(v)) return label+'只能填正整數（不可有小數、負號）';
  var n = parseInt(v,10); if (n<1) return label+'必須大於 0';
  if (exclMax && n>=exclMax) return label+'必須小於 '+exclMax+'（管理員設定的上限）'; return '';
}
function posNum(v, label){ if (v==='') return ''; var n=parseFloat(v); return (isNaN(n)||n<=0) ? label+'必須大於 0' : ''; }
var VAL = [
  ['#tiQty', function(){ return posInt($('#tiQty').val(), '需求支數'); }, '#tiErrQty'],
  ['#tiModV', function(){ return posNum($('#tiModV').val(), '模數'); }, '#tiErrMod'],
  ['#tiPa', function(){ var n=parseFloat($('#tiPa').val()); return $('#tiPa').val()!=='' && (isNaN(n)||n<=0||n>=90) ? '壓力角必須在 0 ～ 90 度之間' : ''; }, '#tiErrPa'],
  ['#tiZ', function(){ return posInt($('#tiZ').val(), '齒數'); }, '#tiErrZ'],
  ['#tiX', function(){ var v=$('#tiX').val(); return v!=='' && (isNaN(parseFloat(v))||Math.abs(parseFloat(v))>5) ? '轉位係數請填 -5 ～ 5' : ''; }, '#tiErrX']
];
VAL.forEach(function(v){ $(v[0]).on('input blur', function(){ check(v[0], v[1], v[2]); }); });
function toolVal(){   // 刀具區欄位（動態產生）：回傳 [selector, 錯誤訊息] 清單
  var s = T.init.settings, out = [];
  var type = $('input[name=tiTt]:checked').val();
  ['tiOd:外徑','tiLen:長度','tiBore:孔徑'].forEach(function(x){ var p=x.split(':'); out.push(['#'+p[0], posNum($('#'+p[0]).val(), p[1]), '#err'+p[0]]); });
  if (type==='shaper') out.push(['#tiShTeeth', posInt($('#tiShTeeth').val(),'齒數'), '#errtiShTeeth']);
  if (type==='hob') { out.push(['#tiSlots', posInt($('#tiSlots').val(),'槽數',s.slots_max), '#errtiSlots']); out.push(['#tiStarts', posInt($('#tiStarts').val(),'牙口數',s.starts_max), '#errtiStarts']); }
  return out;
}
function checkTool(){ var first=''; toolVal().forEach(function(x){ var m=x[1]; $(x[0]).toggleClass('ti-bad',!!m); $(x[2]).text(m).toggleClass('show',!!m); if(m&&!first) first=m; }); return first; }
$('#tiToolWrap').on('input blur', 'input', function(){ checkTool(); });

/* ───────── 刀具欄位（鉋齒刀／滾刀） ───────── */
function optHtml(kind, sel){
  var h = '<option value="">—</option>', list = (T.init.opts[kind]||[]).slice();
  if (sel && !list.some(function(o){ return o.opt_id===sel; }) && T.cur && T.cur[kind+'_name']) list.push({opt_id:sel, name:T.cur[kind+'_name']+'（已停用）'});
  list.forEach(function(o){ h += '<option value="'+o.opt_id+'"'+(o.opt_id===sel?' selected':'')+'>'+esc(o.name)+'</option>'; });
  return h;
}
function numRow(id, lb, en, unit, val){
  return '<div class="ti-row"><label>'+lb+'<small>'+en+'</small></label><div class="ti-cell"><input type="number" id="'+id+'" class="w110" step="any" min="0" value="'+esc(nz(val))+'"><span class="ti-unit">'+unit+'</span><div class="ti-err" id="err'+id+'"></div></div></div>';
}
function buildTool(type, t, ids){
  t = t || {}; ids = ids || {}; var s = T.init.settings, h = '';
  if (!type) { $('#tiToolWrap').html('<div style="padding:8px 12px;color:#8a6d45;font-size:12.5px;">尚未選擇刀具種類（選填）</div>'); return; }
  var prof = '<option value="">— 不選 —</option>'; Object.keys(T.init.profiles).forEach(function(k){ prof += '<option value="'+k+'"'+(t.profile===k?' selected':'')+'>'+esc(T.init.profiles[k])+'</option>'; });
  h += '<div class="ti-row"><label>齒型<small>六種擇一</small></label><div class="ti-cell"><select id="tiProfile" style="min-width:300px;">'+prof+'</select></div></div>';
  if (type==='shaper') {
    h += '<div class="ti-row"><label>盤式 ／ 鐘式 ／ 帶柄式<small>Disk / Bell / Shank Type</small></label><div class="ti-cell"><select id="tiForm" class="w110"><option value="">—</option>'
       + [['disk','盤式 Disk'],['bell','鐘式 Bell'],['shank','帶柄式 Shank']].map(function(x){ return '<option value="'+x[0]+'"'+(t.form===x[0]?' selected':'')+'>'+x[1]+'</option>'; }).join('')+'</select></div></div>';
  }
  h += numRow('tiOd','外徑','Out. Dia.','mm',t.od) + numRow('tiLen','長度','Total length','mm',t.len) + numRow('tiBore','孔徑','Bore Dia.','mm',t.bore);
  if (type==='shaper') {
    h += '<div class="ti-row"><label>齒數<small>No. of teeth</small></label><div class="ti-cell"><input type="number" id="tiShTeeth" class="w110" step="1" min="1" value="'+esc(nz(t.teeth))+'"><div class="ti-err" id="errtiShTeeth"></div></div></div>';
  } else {
    h += '<div class="ti-row"><label>槽數<small>No. of teeth（需 &gt;0 且 &lt;'+s.slots_max+'）</small></label><div class="ti-cell"><input type="number" id="tiSlots" class="w110" step="1" min="1" value="'+esc(nz(t.slots))+'"><div class="ti-err" id="errtiSlots"></div></div></div>'
       + '<div class="ti-row"><label>螺旋角<small>Helical angle on PCD</small></label><div class="ti-cell"><input type="text" id="tiHelixText" class="w160" maxlength="40" placeholder="例：依設計" value="'+esc(nz(t.helix_text))+'"></div></div>'
       + '<div class="ti-row"><label>牙口數 ／ 左右旋<small>牙口數需 &gt;0 且 &lt;'+s.starts_max+'，未填＝1；不選左右旋＝無</small></label><div class="ti-cell"><input type="number" id="tiStarts" class="w80" style="width:80px" step="1" min="1" value="'+esc(nz(t.starts)===''?1:t.starts)+'">'
       + '<select id="tiHand" class="w110"><option value="">無</option><option value="LH"'+(t.hand==='LH'?' selected':'')+'>左旋 LH</option><option value="RH"'+(t.hand==='RH'?' selected':'')+'>右旋 RH</option></select><div class="ti-err" id="errtiStarts"></div></div></div>';
  }
  h += '<div class="ti-row"><label>精度<small>Class of accuracy</small></label><div class="ti-cell"><select id="tiAcc" class="w110">'+optHtml('accuracy', ids.accuracy_id)+'</select></div></div>'
     + '<div class="ti-row"><label>材質<small>Material</small></label><div class="ti-cell"><select id="tiMat" class="w110">'+optHtml('material', ids.material_id)+'</select></div></div>'
     + '<div class="ti-row"><label>鍍層<small>Coating</small></label><div class="ti-cell"><select id="tiCoat" class="w110">'+optHtml('coating', ids.coating_id)+'</select></div></div>';
  $('#tiToolWrap').html(h);
  if (!T.canEdit) $('#tiToolWrap :input').prop('disabled', true);
}
var LASTTOOL = {};   // 切換刀種時保留共同欄位（外徑／長度／孔徑／齒型／精度／材質／鍍層）
function readTool(){
  var type = $('input[name=tiTt]:checked').val() || '', t = {};
  if (!type) return {type:'', t:{}, ids:{}};
  t.profile = $('#tiProfile').val()||''; t.od=$('#tiOd').val(); t.len=$('#tiLen').val(); t.bore=$('#tiBore').val();
  if (type==='shaper') { t.form=$('#tiForm').val()||''; t.teeth=$('#tiShTeeth').val(); }
  else { t.slots=$('#tiSlots').val(); t.starts=$('#tiStarts').val()||'1'; t.hand=$('#tiHand').val()||''; t.helix_text=$('#tiHelixText').val()||''; }
  t.accuracy_id=$('#tiAcc').val()||''; t.material_id=$('#tiMat').val()||''; t.coating_id=$('#tiCoat').val()||'';
  return {type:type, t:t, ids:{accuracy_id:parseInt(t.accuracy_id,10)||null, material_id:parseInt(t.material_id,10)||null, coating_id:parseInt(t.coating_id,10)||null}};
}
$('input[name=tiTt]').on('change', function(){
  var old = readTool(); var keep = {profile:old.t.profile, od:old.t.od, len:old.t.len, bore:old.t.bore};
  buildTool(this.value, keep, old.ids);
});
$('#tiTtClr').on('click', function(){ $('input[name=tiTt]').prop('checked', false); buildTool('', {}, {}); $('#tiToolHint').text(''); });

/* ───────── 料號／廠商搜尋（輸入即模糊搜尋，下拉用 fixed 定位避免被跳窗捲動容器裁切） ───────── */
function showSug($inp, html){
  var $s = $('#tiSug'), r = $inp[0].getBoundingClientRect();
  $s.css({left:r.left, top:r.bottom+2, minWidth:Math.max(320, r.width)}).html(html).show();
}
function hideSug(){ $('#tiSug').hide().empty(); }
$(document).on('click', function(e){ if (!$(e.target).closest('#tiSug,#tiPart,#tiVSearch').length) hideSug(); });
var partTimer, vTimer, PSEQ = 0;
$('#tiPart').on('input', function(){
  if (T.partId) { unbindPart(); }   // 手動改字＝解除綁定，免得畫面寫 A、實際綁著 B
  var kw = $.trim(this.value), $i = $(this); clearTimeout(partTimer);
  if (!kw) { hideSug(); return; }
  partTimer = setTimeout(function(){
    var seq = ++PSEQ;
    $.getJSON(TAPI, {action:'part_search', kw:kw}, function(res){
      if (seq!==PSEQ || !res.success) return;
      if (!res.rows.length) { showSug($i, '<div style="color:#999;cursor:default;">主檔查無此料號（可直接輸入文字，不綁定）</div>'); return; }
      showSug($i, res.rows.map(function(r){ return '<div data-pid="'+r.part_id+'" data-no="'+esc(r.part_no)+'"><b>'+esc(r.part_no)+'</b><small>'+esc(r.drawing_no)+'　'+esc(r.customer||'（未綁客戶）')+'　#'+r.part_id+'</small></div>'; }).join(''));
    });
  }, 250);
});
$('#tiSug').on('click', 'div[data-pid]', function(){ bindPart(parseInt($(this).data('pid'),10), String($(this).data('no'))); hideSug(); });
function unbindPart(){
  T.partId = 0; $('#tiPartTag').hide().empty(); $('#tiGearSel').hide().empty(); T.gears = [];
  $('#tiCust').prop('readonly', false).removeClass('ro-auto'); $('#tiCustHint').text('');
}
function bindPart(pid, no, keepValues){
  T.partId = pid; $('#tiPart').val(no);
  $('#tiPartTag').html('已綁定 <b>'+esc(no)+'</b> #'+pid+' <span class="x" title="解除綁定">✕</span>').show();
  $('#tiErrPart').removeClass('show');
  $.getJSON(TAPI, {action:'part_gear', part_id:pid}, function(res){
    if (!res.success || T.partId!==pid) return;
    var c = res.customer||{};
    $('#tiCust').val(c.customer||'').prop('readonly', true).addClass('ro-auto');
    $('#tiCustHint').text(c.customer ? '（由料號自動帶入）' : '（此料號主檔未綁客戶）');
    T.gears = res.gears||[];
    var $g = $('#tiGearSel').empty();
    if (T.gears.length>1) { T.gears.forEach(function(g,i){ $g.append('<option value="'+i+'">'+esc('帶入：'+(g.label||('齒型'+(i+1))))+'</option>'); }); $g.show(); } else $g.hide();
    if (!keepValues && T.gears.length) applyGear(T.gears[0]);
    if (!keepValues && res.tool && res.tool.tool_type) applyTool(res.tool);
  });
}
$('#tiPartTag').on('click', '.x', function(){ unbindPart(); $('#tiPart').val('').focus(); });
$('#tiGearSel').on('change', function(){ var g = T.gears[parseInt(this.value,10)]; if (g) applyGear(g); });
function applyTool(t){   // 料號主檔有刀具規格（d_setting_tool）就帶入刀具欄位，之後仍可手動修改
  $('input[name=tiTt][value='+t.tool_type+']').prop('checked', true);
  buildTool(t.tool_type, {profile:t.profile, od:t.od, len:t.len, bore:t.bore, teeth:t.teeth, starts:t.starts, hand:t.hand}, {accuracy_id:null, material_id:t.material_id||null, coating_id:t.coating_id||null});
  if (t.tool_remark && !$('#tiToolRemark').val()) $('#tiToolRemark').val(t.tool_remark);
  $('#tiToolHint').text('已由料號主檔的刀具規格帶入'+(t.hint?'（'+t.hint+'）':''));
  checkTool();
}
function applyGear(g){   // 料號有齒輪資料就帶入，之後仍可手動修改
  $('input[name=tiIo][value='+g.io+']').prop('checked', true);
  $('#tiModT').val(g.module_type); $('#tiModV').val(nz(g.module_val)); $('#tiPa').val(nz(g.pa)); $('#tiZ').val(nz(g.teeth));
  $('#tiX').val(g.x===null?'':g.x); $('#tiHxDir').val(g.helix_dir||'');
  setHelix(g.helix_deg>0 ? g.helix_deg : (g.helix_deg===0?0:null), 'dec');
  T.fwAuto = g.face_width;
  initCalc(); recalc(); updModHint();
}
var VSEQ = 0;
$('#tiVSearch').on('input', function(){
  var kw = $.trim(this.value), $i = $(this); clearTimeout(vTimer);
  if (!kw) { hideSug(); return; }
  vTimer = setTimeout(function(){
    var seq = ++VSEQ;
    $.getJSON(TAPI, {action:'vendor_search', kw:kw}, function(res){
      if (seq!==VSEQ || !res.success) return;
      if (!res.rows.length) { showSug($i, '<div style="color:#999;cursor:default;">查無符合的廠商</div>'); return; }
      showSug($i, res.rows.map(function(r){ var added = T.vendors.some(function(v){ return v.vendor_id===r.vendor_id; });
        return '<div data-vid="'+esc(r.vendor_id)+'" data-name="'+esc(r.vendor_name)+'"'+(added?' style="color:#aaa;"':'')+'><b>'+esc(r.vendor_id)+'</b> '+esc(r.vendor_name)+(added?'（已加入）':'')+'<small>'+esc(r.contact)+' '+esc(r.phone)+' '+esc(r.items)+'</small></div>'; }).join(''));
    });
  }, 250);
});
$('#tiSug').on('click', 'div[data-vid]', function(){
  var id = String($(this).data('vid'));
  if (!T.vendors.some(function(v){ return v.vendor_id===id; })) T.vendors.push({vendor_id:id, vendor_name:String($(this).data('name')), locked:false});
  renderChips(); $('#tiVSearch').val(''); hideSug();
});
function renderChips(){
  $('#tiChips').html(T.vendors.map(function(v,i){ return '<span class="ti-chip'+(v.locked||!T.canEdit?' lock':'')+'" title="'+(v.locked?'已有回覆／下單／附件資料，不能移除；請到「廠商回覆」勾選不再使用':'')+'">'+esc(v.vendor_id)+' '+esc(v.vendor_name)+'<span class="x" data-i="'+i+'">✕</span></span>'; }).join('') || '<span style="color:#999;font-size:12.5px;">尚未選廠商（可之後再補）</span>');
}
$('#tiChips').on('click', '.x', function(){ T.vendors.splice(parseInt($(this).data('i'),10), 1); renderChips(); });

/* ───────── 附件（temp/active；類別必選） ───────── */
function attHtml(rows, canDel){
  if (!rows.length) return '<span style="color:#999;font-size:12.5px;">尚無附件</span>';
  var cats = T.init.att_cats;
  return rows.map(function(a){ return '<div class="ti-att"><span class="cat">'+esc(cats[a.category]||a.category)+'</span><a href="'+TAPI+'?action=att_download&att_id='+a.att_id+'" target="_blank">'+esc(a.name)+'</a>'
    + (a.note?'<span style="color:#8a6d45;">'+esc(a.note)+'</span>':'')+'<span style="color:#aaa;font-size:11.5px;">'+esc(a.by)+' '+esc(dt((a.at||'').slice(0,10)))+'</span>'
    + (canDel?'<span class="del" data-att="'+a.att_id+'" title="刪除">✕</span>':'')+'</div>'; }).join('');
}
function loadAtt(){
  var inqId = T.cur ? T.cur.id : 0;
  tget('att_list', {inq_id:inqId, temp_key:inqId?'':T.tempKey}, function(r){
    var rows = r.rows.filter(function(a){ return !a.vid; });
    $('#tiAtt').html(attHtml(rows, T.canEdit));
  });
}
function upload(prefix, extra, msgSel, done){
  var cat = $('#'+prefix+'Cat').attr('data-val'), f = $('#'+prefix+'File')[0].files[0];
  if (!cat) { $(msgSel).css('color','#C4442D').text('請先選擇附件類別'); return; }
  if (!f) { $(msgSel).css('color','#C4442D').text('請選擇要上傳的檔案'); return; }
  var fd = new FormData(); fd.append('action','att_upload'); fd.append('csrf',CSRF); fd.append('category',cat); fd.append('note',$('#'+prefix+'Note').val()); fd.append('file',f);
  Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });
  $(msgSel).css('color','#8a6d45').text('上傳中…');
  $.ajax({url:TAPI, method:'POST', data:fd, processData:false, contentType:false, dataType:'json'}).done(function(res){
    if (!res.success) { $(msgSel).css('color','#C4442D').text(res.message||'上傳失敗'); return; }
    $(msgSel).text(''); $('#'+prefix+'File').val(''); $('#'+prefix+'Note').val(''); done();
  }).fail(function(){ $(msgSel).css('color','#C4442D').text('連線失敗'); });
}
function fillCats($sel, keys){ $sel.attr('data-val','').html(keys.map(function(k){ return '<button type="button" class="ti-catbtn" data-v="'+k+'">'+esc(T.init.att_cats[k])+'</button>'; }).join('')); }
$(document).on('click', '.ti-catbtn', function(){ var $g = $(this).parent(); $g.attr('data-val', $(this).data('v')); $g.find('.ti-catbtn').removeClass('on'); $(this).addClass('on'); });
$('#tiAttBtn').on('click', function(){ upload('tiAtt', {inq_id:T.cur?T.cur.id:0, temp_key:T.tempKey}, '#tiAttMsg', loadAtt); });
$('#tiAtt').on('click', '.del', function(){ if (confirm('刪除這份附件？')) tpost('att_delete', {att_id:$(this).data('att')}, loadAtt); });

/* ───────── 新增／編輯 ───────── */
function resetForm(){
  initCalc(); MEAS_USER = false; T.fwAuto = null; T.partId = 0; T.gears = []; T.vendors = []; LASTTOOL = {};
  $('#tiBody input[type=text],#tiBody input[type=number],#tiBody textarea').val(''); $('#tiPa').val(20);
  $('input[name=tiIo][value=ext]').prop('checked', true); $('#tiModT').val('M'); $('#tiHxDir').val('');
  $('input[name=tiHx][value=dec]').prop('checked', true); setHelix(null,'dec'); $('input[name=tiTt]').prop('checked', false);
  $('#tiToolHint').text(''); $('#tiPartTag').hide(); $('#tiGearSel').hide(); $('#tiCust').prop('readonly', false).removeClass('ro-auto'); $('#tiCustHint').text('');
  $('.ti-err').removeClass('show').text(''); $('.ti-bad').removeClass('ti-bad'); $('#tiAttMsg').text('');
  $('#tiBody :input').prop('disabled', false); $('#tiNo,#tiDate,#tiWho').prop('readonly', true); $('#tiSave').show();
  buildCalcRows(); setMeas('wk', false); buildTool('', {}, {}); fillCats($('#tiAttCat'), ['inquiry','drawing','other']); renderChips(); updModHint();
}
function openNew(){
  if (!T.init) { tget('init', {}, function(r){ T.init = r; openNew(); }); return; }
  T.cur = null; T.canEdit = true; T.tempKey = 'ti' + Date.now().toString(36) + Math.random().toString(36).slice(2,8);
  resetForm();
  $('#tiTitle').text('新增刀具詢價'); $('#tiNo').val('（儲存後產生）'); $('#tiDate').val(egFmtDate(todayStr())); $('#tiWho').val(MY_NAME);
  recalc(); loadAtt(); openMask('tiMask');
}
function openEdit(id){
  if (!T.init) { tget('init', {}, function(r){ T.init = r; openEdit(id); }); return; }
  tget('get', {id:id}, function(res){
    var r = res.inq; T.cur = r; T.canEdit = !!r.can_edit; T.tempKey = '';
    resetForm();
    $('#tiTitle').text((T.canEdit?'編輯':'檢視')+'刀具詢價　'+r.inq_no);
    $('#tiNo').val(r.inq_no); $('#tiDate').val(egFmtDate(r.inq_date)); $('#tiWho').val(r.created_by_name);
    $('#tiPart').val(r.part_no); $('#tiCust').val(r.customer||''); $('#tiQty').val(nz(r.qty));
    if (r.part_id) { T.partId = r.part_id; $('#tiPartTag').html('已綁定 <b>'+esc(r.part_no)+'</b> #'+r.part_id+' <span class="x" title="解除綁定">✕</span>').show(); $('#tiCust').prop('readonly', true).addClass('ro-auto'); $('#tiCustHint').text('（由料號自動帶入）');
      $.getJSON(TAPI, {action:'part_gear', part_id:r.part_id}, function(g){ if (g.success) { T.gears = g.gears||[]; if (!T.fwAuto && T.gears.length) { T.fwAuto = T.gears[0].face_width; recalc(); }
        var $g = $('#tiGearSel').empty(); if (T.gears.length>1) { T.gears.forEach(function(x,i){ $g.append('<option value="'+i+'">'+esc('帶入：'+(x.label||('齒型'+(i+1))))+'</option>'); }); $g.show(); } } }); }
    $('input[name=tiIo][value='+(r.gear_io||'ext')+']').prop('checked', true);
    var s = r.spec||{};
    $('#tiModT').val(s.module_type||'M'); $('#tiModV').val(nz(s.module_val)); $('#tiPa').val(nz(s.pa)); $('#tiZ').val(nz(s.teeth)); $('#tiX').val(nz(s.x)); $('#tiHxDir').val(s.helix_dir||'');
    setHelix(s.helix_deg===undefined?null:s.helix_deg, s.helix_mode||'dec');
    Object.keys(CALC_DEF).forEach(function(k){ var c = s[k]; if (c) CALC[k] = {st:c.st||'auto', v:c.v===undefined?null:c.v, v2:c.v2===undefined?null:c.v2}; });
    if (s.meas) setMeas(s.meas, true); else { MEAS_USER = false; defaultMeas(); }
    recalc();
    if (r.tool_type) { $('input[name=tiTt][value='+r.tool_type+']').prop('checked', true); var t = r.tool||{}; buildTool(r.tool_type, t, {accuracy_id:r.accuracy_id, material_id:r.material_id, coating_id:r.coating_id}); }
    var sp = r.special||{}; $('#tiChamfer').val(sp.chamfer||''); $('#tiStock').val(sp.stock||''); $('#tiToolRemark').val([sp.tool_remark, sp.work_remark, r.remark].filter(function(x){ return !!x; }).join('\n'));   // 舊資料的工件備註／詢價備註併入這一個
    T.vendors = r.vendors.map(function(v){ return {vendor_id:v.vendor_id_no, vendor_name:v.vendor_name, locked: !!(v.reply_date||v.unit_price!==null||v.order_date)}; });
    renderChips(); updModHint(); loadAtt();
    if (!T.canEdit) { $('#tiBody :input').prop('disabled', true); $('#tiSave').hide(); $('.ti-upl').hide(); }
    else $('.ti-upl').show();
    $('#tiBody :input[readonly]').prop('readonly', true);
    openMask('tiMask');
  });
}
$('#tiSave').on('click', function(){
  var errs = [];
  VAL.forEach(function(v){ var m = check(v[0], v[1], v[2]); if (m) errs.push(m); });
  var m2 = checkTool(); if (m2) errs.push(m2);
  var partTxt = $.trim($('#tiPart').val());
  if (!T.partId && !partTxt) { $('#tiErrPart').text('請綁定料號（或至少填寫料號文字）').addClass('show'); errs.push('請綁定料號'); }
  Object.keys(CALC).forEach(function(k){ var c = CALC[k]; if ((c.v!==null&&c.v<0)||(c.v2!==null&&c.v2<0)) errs.push(CALC_DEF[k].lb+'請填 0 以上的數字'); });
  if (errs.length) { alert('尚有欄位需要修正：\n・'+errs.slice(0,6).join('\n・')); return; }
  var s = params(), spec = {module_type:$('#tiModT').val(), module_val:$('#tiModV').val(), pa:$('#tiPa').val(), teeth:$('#tiZ').val(), x:$('#tiX').val(),
      meas:MEAS, helix_mode:$('input[name=tiHx]:checked').val(), helix_deg:(helixDeg()===null?0:rnd(helixDeg(),6)), helix_dir:$('#tiHxDir').val()};
  Object.keys(CALC).forEach(function(k){ spec[k] = {st:CALC[k].st, v:CALC[k].v, v2:CALC[k].v2}; });
  var tool = readTool();
  var data = {id:T.cur?T.cur.id:0, part_id:T.partId, part_no_text:partTxt, customer_text:T.partId?'':$.trim($('#tiCust').val()), qty:$('#tiQty').val(),
      gear_io:$('input[name=tiIo]:checked').val(), spec:JSON.stringify(spec), tool_type:tool.type, tool:JSON.stringify(tool.t),
      special:JSON.stringify({chamfer:$('#tiChamfer').val(), stock:$('#tiStock').val(), tool_remark:$('#tiToolRemark').val(), work_remark:''}),
      remark:'', vendors:JSON.stringify(T.vendors.map(function(v){ return v.vendor_id; })), temp_key:T.tempKey};
  var $b = $(this).prop('disabled', true);
  tpost('save', data, function(res){ $b.prop('disabled', false); closeMask('tiMask'); loadList(T.page); }, function(res){ $b.prop('disabled', false); alert(res.message||'儲存失敗'); });
});

/* ───────── 廠商回覆 ───────── */
var DUE_AUTO = true;   // 交期是系統依「下訂日＋交期天數」算的；使用者手動改過就不再自動覆蓋
function vCan(){ return !!(T.vcur && T.vcur.inq.can_edit); }
function paintRecv(){
  var r = $('#tvRecv').val();
  if (r) { $('#tvRecvTxt').html('✔ 已到貨，到貨日 <b>'+esc(dt(r))+'</b>'); $('#tvDoRecv').hide(); $('#tvUndoRecv').toggle(vCan()); }
  else { $('#tvRecvTxt').text('貨到了就按「已到貨」，系統自動記錄今天為到貨日並停止交期提醒：'); $('#tvDoRecv').toggle(vCan()); $('#tvUndoRecv').hide(); }
}
function showOrder(on){
  $('#tvOrderSec').toggle(on); $('#tvOrderBar').toggle(!on && vCan()); $('#tvRecvBar').toggle(on); paintRecv();
}
function openVendor(inqId, vid){
  if (!T.init) { tget('init', {}, function(r){ T.init = r; openVendor(inqId, vid); }); return; }
  tget('get', {id:inqId}, function(res){
    var r = res.inq, v = r.vendors.filter(function(x){ return x.vid===vid; })[0]; if (!v) return;
    T.vcur = {inq:r, v:v}; var can = !!r.can_edit;
    $('#tvTitle').text('廠商回覆／下單　'+v.vendor_id_no+' '+v.vendor_name);
    $('#tvInfo').html('詢價單 <b>'+esc(r.inq_no)+'</b>　'+esc(r.part_no)+'　'+esc(r.customer||'')+'　詢價日 '+esc(dt(r.inq_date)));
    var $c = $('#tvCur').empty(); T.init.currencies.forEach(function(c){ $c.append('<option>'+c+'</option>'); }); $c.val(v.currency||'NTD');
    $('#tvReply').val(v.reply_date || (can ? todayStr() : ''));   // 回覆日預設今天
    $('#tvPrice').val(nz(v.unit_price)); $('#tvLead').val(nz(v.lead_days)); $('#tvNote').val(v.reply_note||'');
    $('input[name=tvUnit][value='+(v.lead_unit||'work')+']').prop('checked', true);
    $('#tvOrder').val(v.order_date||''); $('#tvDue').val(v.due_date||''); $('#tvRemind').val(v.remind_days); $('#tvRecv').val(v.received_date||''); $('#tvVoid').prop('checked', !!v.is_void);
    DUE_AUTO = !v.due_date;
    $('.ti-err').removeClass('show').text(''); $('#tvAttMsg').text('');
    $('#tvMask :input').not('#tvAttFile,#tvAttNote,#tvAttBtn').prop('disabled', !can); $('#tvSave').toggle(can); $('#tvMask .ti-upl').toggle(can);
    fillCats($('#tvAttCat'), ['quote','drawing','other']);
    $('#tvInqAtt').html(attHtml(r.attaches.filter(function(a){ return !a.vid; }), false));
    showOrder(!!v.order_date);
    loadVAtt(); openMask('tvMask');
  });
}
function loadVAtt(){
  var r = T.vcur.inq; tget('att_list', {inq_id:r.id}, function(x){ T.vcur.inq.attaches = x.rows;
    $('#tvAtt').html(attHtml(x.rows.filter(function(a){ return a.vid===T.vcur.v.vid; }), !!r.can_edit)); });
}
$('#tvAttBtn').on('click', function(){ upload('tvAtt', {vid:T.vcur.v.vid}, '#tvAttMsg', function(){ loadVAtt(); loadList(T.page); }); });
$('#tvAtt').on('click', '.del', function(){ if (confirm('刪除這份附件？')) tpost('att_delete', {att_id:$(this).data('att')}, function(){ loadVAtt(); loadList(T.page); }); });
$('#tvPrice').on('input', function(){ var v=$(this).val(), bad = v!=='' && !(parseFloat(v)>=0); $('#tvErrPrice').text(bad?'單價請填 0 以上的數字':'').toggleClass('show',bad); $(this).toggleClass('ti-bad',bad); });
$('#tvRemind').on('input', function(){ var v=$(this).val(), bad = !/^\d+$/.test(v)||parseInt(v,10)>60; $('#tvErrRemind').text(bad?'請填 0～60 的整數':'').toggleClass('show',bad); $(this).toggleClass('ti-bad',bad); });
function calcDue(quiet){
  var o = $('#tvOrder').val(), l = $('#tvLead').val();
  if (!o || l==='') { if (!quiet) alert('請先填下訂日與交期天數'); return; }
  tget('calc_due', {order:o, days:l, unit:$('input[name=tvUnit]:checked').val()}, function(r){ $('#tvDue').val(r.due); DUE_AUTO = true; });
}
$('#tvDueCalc').on('click', function(){ calcDue(false); });
$('#tvDue').on('input', function(){ DUE_AUTO = false; });
$('#tvLead,#tvOrder,input[name=tvUnit]').on('change', function(){ if (DUE_AUTO && $('#tvOrderSec').is(':visible')) calcDue(true); });
$('#tvDoOrder').on('click', function(){   // ② 下訂：展開下訂區，下訂日預設今天
  showOrder(true); if (!$('#tvOrder').val()) $('#tvOrder').val(todayStr()); DUE_AUTO = !$('#tvDue').val(); calcDue(true);
});
function saveVendor(done){
  if ($('#tvErrPrice').hasClass('show')||$('#tvErrRemind').hasClass('show')) { alert('尚有欄位需要修正'); return; }
  var data = {vid:T.vcur.v.vid, reply_date:$('#tvReply').val(), currency:$('#tvCur').val(), unit_price:$('#tvPrice').val(), lead_days:$('#tvLead').val(), lead_unit:$('input[name=tvUnit]:checked').val(), reply_note:$('#tvNote').val(),
      order_date:$('#tvOrder').val(), due_date:$('#tvDue').val(), remind_days:$('#tvRemind').val(), received_date:$('#tvRecv').val(), is_void:$('#tvVoid').prop('checked')?1:0};
  $('#tvSave,#tvDoRecv,#tvUndoRecv').prop('disabled', true);
  tpost('vendor_save', data, function(){ $('#tvSave,#tvDoRecv,#tvUndoRecv').prop('disabled', false); closeMask('tvMask'); loadList(T.page); },
    function(res){ $('#tvSave,#tvDoRecv,#tvUndoRecv').prop('disabled', false); alert(res.message||'儲存失敗'); });
}
$('#tvSave').on('click', function(){ saveVendor(); });
$('#tvDoRecv').on('click', function(){   // ③ 已到貨：自動記錄今天為到貨日
  if (!$('#tvOrder').val()) { alert('請先填下訂日'); return; }
  if (!confirm('確定這家廠商已到貨？系統會記錄今天（'+egFmtDate(todayStr())+'）為到貨日，並停止交期提醒。')) return;
  $('#tvRecv').val(todayStr()); paintRecv(); saveVendor();
});
$('#tvUndoRecv').on('click', function(){ if (!confirm('取消到貨紀錄？交期提醒會恢復。')) return; $('#tvRecv').val(''); paintRecv(); saveVendor(); });

/* ───────── 管理員：選項與上限設定 ───────── */
$('#tBtnSet').on('click', function(){ renderSettings(); openMask('tsMask'); });
function renderSettings(){
  tget('settings_get', {}, function(r){
    var h = '<div class="ti-sec"><h5>數值上限 <small>牙口數、槽數：需 &gt;0 且 &lt; 上限</small></h5><div class="ti-row"><label>牙口數上限</label><div class="ti-cell"><input type="number" id="tsStarts" class="w80" style="width:90px" min="2" value="'+r.settings.starts_max+'"></div></div>'
      + '<div class="ti-row"><label>槽數上限</label><div class="ti-cell"><input type="number" id="tsSlots" class="w80" style="width:90px" min="2" value="'+r.settings.slots_max+'"></div></div>'
      + '<div class="ti-row"><label>列印簽章欄<small>預設隱藏「審核」，只印經辦與簽收</small></label><div class="ti-cell"><label class="ti-ck"><input type="checkbox" id="tsReview"'+(r.settings.print_review?' checked':'')+'> 列印時顯示「審核」欄</label><button type="button" class="ti-mini" id="tsLimSave">儲存設定</button></div></div></div>';
    Object.keys(r.kinds).forEach(function(k){
      h += '<div class="ti-sec"><h5>'+r.kinds[k]+'選項 <small>已被詢價單選用的不可刪除、但可改名；也可取消「啟用」讓新單不再出現</small></h5><div style="padding:6px 12px;">';
      r.opts[k].forEach(function(o){
        h += '<div class="ti-opt-row" data-id="'+o.opt_id+'" data-kind="'+k+'"><input type="text" class="on" value="'+esc(o.name)+'" maxlength="60"><label class="ti-ck"><input type="checkbox" class="oa"'+(o.is_active?' checked':'')+'> 啟用</label>'
          + '<button type="button" class="ti-mini osave">儲存</button><button type="button" class="ti-mini odel" style="color:#DD5138;" '+(o.used?'disabled title="已被 '+o.used+' 張詢價單選用，不可刪除"':'')+'>刪除</button>'+(o.used?'<span class="ti-unit">已用 '+o.used+' 張</span>':'')+'</div>';
      });
      h += '<div class="ti-opt-row" data-id="0" data-kind="'+k+'"><input type="text" class="on" placeholder="新增'+r.kinds[k]+'選項" maxlength="60"><input type="hidden" class="oa" value="1"><button type="button" class="ti-mini osave warm">新增</button></div></div></div>';
    });
    $('#tsBody').html(h);
  });
}
$('#tsBody').on('click', '#tsLimSave', function(){ tpost('setting_save', {starts_max:$('#tsStarts').val(), slots_max:$('#tsSlots').val(), print_review:$('#tsReview').prop('checked')?1:0}, function(){ alert('已儲存'); tget('init', {}, function(r){ T.init = r; }); }); });
$('#tsBody').on('click', '.osave', function(){
  var $r = $(this).closest('.ti-opt-row'), $a = $r.find('.oa'), act = $a.is(':checkbox') ? ($a.prop('checked')?1:0) : 1;
  tpost('opt_save', {opt_id:$r.data('id'), kind:$r.data('kind'), name:$r.find('.on').val(), is_active:act}, function(){ renderSettings(); tget('init', {}, function(r){ T.init = r; }); });
});
$('#tsBody').on('click', '.odel', function(){
  var $r = $(this).closest('.ti-opt-row'); if (!confirm('確定刪除「'+$r.find('.on').val()+'」？')) return;
  tpost('opt_delete', {opt_id:$r.data('id')}, function(){ renderSettings(); tget('init', {}, function(r){ T.init = r; }); });
});

/* ───────── 列印：一家廠商一頁；多家時可「全部列印」或勾選要印的 ───────── */
var TP = {id:0, vendors:[]};
function startPrint(id){
  var w = window.open('', '_blank');   // 一定要在使用者點擊當下開窗，等資料回來才開會被彈出視窗封鎖擋掉
  if (!w) { alert('請允許彈出視窗'); return; }
  w.document.write('<p style="font:14px sans-serif;padding:20px;">準備列印…</p>');
  $.getJSON(TAPI, {action:'get', id:id}, function(res){
    if (!res.success) { w.close(); alert(res.message||'取得資料失敗'); return; }
    var vs = res.inq.vendors;
    if (vs.length <= 1) { doPrint(id, [], w); return; }
    w.close(); TP = {id:id, vendors:vs};
    $('#tpInfo').html('詢價單 <b>'+esc(res.inq.inq_no)+'</b>　'+esc(res.inq.part_no)+'　共 '+vs.length+' 家廠商，請選擇要列印的廠商：');
    $('#tpList').html(vs.map(function(v){
      return '<label class="ti-ck" style="display:flex;padding:4px 0;"><input type="checkbox" class="tpck" value="'+v.vid+'"'+(v.is_void?'':' checked')+'> '+esc(v.vendor_id_no)+' '+esc(v.vendor_name)+(v.is_void?'（不採用）':'')+'</label>';
    }).join(''));
    openMask('tpMask');
  }).fail(function(){ w.close(); alert('連線失敗'); });
}
$('#tpAll').on('click', function(){ $('#tpList .tpck').prop('checked', true); });
$('#tpNone').on('click', function(){ $('#tpList .tpck').prop('checked', false); });
$('#tpPrintAll').on('click', function(){
  var ids = TP.vendors.filter(function(v){ return !v.is_void; }).map(function(v){ return v.vid; });
  if (!ids.length) ids = TP.vendors.map(function(v){ return v.vid; });
  var w = window.open('', '_blank'); if (!w) { alert('請允許彈出視窗'); return; }
  closeMask('tpMask'); doPrint(TP.id, ids, w);
});
$('#tpPrintSel').on('click', function(){
  var ids = $('#tpList .tpck:checked').map(function(){ return parseInt(this.value,10); }).get();
  if (!ids.length) { alert('請至少勾選一家廠商'); return; }
  var w = window.open('', '_blank'); if (!w) { alert('請允許彈出視窗'); return; }
  closeMask('tpMask'); doPrint(TP.id, ids, w);
});
function doPrint(id, vids, w){
  $.getJSON(TAPI, {action:'print_data', id:id, vids:vids.join(',')}, function(res){
    if (!res.success) { w.close(); alert(res.message||'取得列印資料失敗'); return; }
    var vs = res.vendors.length ? res.vendors : [null];
    var html = vs.map(function(v){ return tpPage(res, v); }).join('');
    tpWrite(w, '刀具詢價單 '+res.inq.inq_no, html, res.as_no, vs.length>1);
    tpost('print_log', {id:id, vids:vids.join(',')});
  }).fail(function(){ w.close(); alert('連線失敗'); });
}
function tpCalc(sp, k, two){
  var c = sp[k]; if (!c) return '';
  if (c.st==='standard') return '標準';
  var a = nz(c.v), b = nz(c.v2);
  if (!two) return a===''?'':String(a);
  return (a===''?'':a) + (b!==''?' ／ '+b:'');
}
function tpMm(v){ return (v===null||v===undefined||v==='') ? '' : esc(v)+' mm'; }
function tpPage(res, v){
  var i = res.inq, c = res.company, sp = i.spec||{}, tl = i.tool||{}, spc = i.special||{}, prof = T.init.profiles;
  var stamp = (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(i.created_by_name, egFmtDate(i.inq_date), false, null) : esc(i.created_by_name);
  var pair = function(l1,v1,l2,v2){ return '<tr><td class="lb">'+l1+'</td><td>'+(v1===undefined||v1===null?'':v1)+'</td><td class="lb">'+l2+'</td><td>'+(v2===undefined||v2===null?'':v2)+'</td></tr>'; };
  var full = function(l,val,h){ return '<tr><td class="lb">'+l+'</td><td colspan="3"'+(h?' style="height:'+h+'px;vertical-align:top;white-space:pre-wrap;"':'')+'>'+(val||'')+'</td></tr>'; };
  var hd = (sp.helix_deg!==undefined && sp.helix_deg!==null && sp.helix_deg!=='') ? parseFloat(sp.helix_deg) : 0;   // 未填顯示 0
  var hx = esc(hd)+'°'+(hd>0 && sp.helix_dir ? ' '+esc(sp.helix_dir) : '');
  var meas = sp.meas || (i.gear_io==='int'?'pin':'wk');
  var h = '<div class="tp-sheet"><div class="co">'+esc(c.customer_full)+'</div>'
    + '<div class="addr">'+esc(c.customer_address)+'　電話：'+esc(c.customer_tel)+'　傳真：'+esc(c.customer_fax)+'</div>'
    + '<div class="tt">刀　具　詢　價　單</div>'
    + '<table class="tp"><tr><td class="lb">廠商名稱：</td><td>'+esc(v?v.vendor_name:'')+'</td><td class="lb">詢價日期：</td><td>'+esc(egFmtDate(i.inq_date))+'</td></tr>'
    + '<tr><td class="lb">聯絡人員：</td><td>'+esc(v?v.contact:'')+'</td><td class="lb">單　　號：</td><td>'+esc(i.inq_no)+'</td></tr>'
    + '<tr><td class="lb">聯絡電話：</td><td>'+esc(v?v.tel:'')+'</td><td class="lb">詢價人員：</td><td>'+esc(i.created_by_name)+'</td></tr>'
    + '<tr><td class="lb">傳真號碼：</td><td>'+esc(v?v.fax:'')+'</td><td class="lb">需求支數：</td><td>'+(i.qty?esc(i.qty)+' 支':'')+'</td></tr></table>'
    // 工件規格（客戶名稱不印在給廠商的詢價單上，只印工件編號）
    + '<div class="sec">工件規格　Workpiece</div><table class="tp">'
    + full('工件編號<br>Workpiece No.', esc(i.part_no))
    + pair('外齒／內齒<br>External / Internal', i.gear_io==='int'?'內齒 Internal':'外齒 External', '模數<br>Module', sp.module_val?esc(sp.module_type+' '+sp.module_val):'')
    + pair('壓力角<br>Pressure angle', sp.pa?esc(sp.pa)+'°':'', '齒數<br>No. of teeth', esc(nz(sp.teeth)))
    + pair('螺旋角<br>Helix angle', hx, '齒面寬<br>Face width', esc(tpCalc(sp,'fw',false)))
    + pair('齒頂徑<br>Out. Dia.', esc(tpCalc(sp,'da',false)), '齒底徑<br>Root dia.', esc(tpCalc(sp,'df',false)))
    + pair('全齒深<br>D+F', esc(tpCalc(sp,'depth',false)), meas==='pin'?'跨銷值／跨銷徑<br>Over pin value / pin dia.':'跨齒厚／跨齒數<br>Spanned t. thickness / tooth no.', esc(tpCalc(sp, meas==='pin'?'pin':'wk', true)))
    + '</table>';
  // 刀具
  if (i.tool_type) {
    h += '<div class="sec">'+(i.tool_type==='hob'?'滾刀　HOB':'鉋齒刀　Shaper')+'</div><table class="tp">'
      + (tl.profile && prof[tl.profile] ? full('齒型<br>Tooth profile', esc(prof[tl.profile])) : '');   // 六種齒型只印使用者選的那一項
    if (i.tool_type==='shaper') {
      var form = {disk:'盤式 Disk', bell:'鐘式 Bell', shank:'帶柄式 Shank'}[tl.form]||'';
      h += pair('盤式／鐘式／帶柄式<br>Disk / Bell / Shank', form, '外徑 Out. Dia.', tpMm(tl.od))
        + pair('長度 Total length', tpMm(tl.len), '孔徑 Bore Dia.', tpMm(tl.bore))
        + pair('齒數 No. of teeth', esc(nz(tl.teeth)), '精度 Class of accuracy', esc(i.accuracy_name))
        + pair('材質 Material', esc(i.material_name), '鍍層 Coating', esc(i.coating_name));
    } else {
      h += pair('外徑 Out. Dia.', tpMm(tl.od), '長度 Total length', tpMm(tl.len))
        + pair('孔徑 Bore Dia.', tpMm(tl.bore), '槽數 No. of teeth', esc(nz(tl.slots)))
        + pair('螺旋角<br>Helical angle on PCD', esc(tl.helix_text||''), '牙口數／左右旋', esc(nz(tl.starts))+' ／ '+({LH:'左旋 LH', RH:'右旋 RH'}[tl.hand]||'無'))
        + pair('精度 Class of accuracy', esc(i.accuracy_name), '材質 Material', esc(i.material_name))
        + pair('鍍層 Coating', esc(i.coating_name), '', '');
    }
    h += '</table>';
  }
  h += '<div class="sec">特殊修整要求　Special modification requirement</div><table class="tp">'
    + pair('倒角量<br>Semi-topping amount', esc(spc.chamfer||''), '放磨量<br>Stock removal', esc(spc.stock||''))
    + full('備註<br>Remark', esc([spc.tool_remark, spc.work_remark, i.remark].filter(function(x){ return !!x; }).join('\n')), 70);
  var atts = (i.attaches||[]).filter(function(a){ return !a.vid; }).map(function(a){ return esc(a.name); });
  if (atts.length) h += full('隨附資料', atts.join('、'));
  var showRev = !!(T.init && T.init.settings && T.init.settings.print_review), cw = showRev ? '33.33%' : '50%';
  h += '</table><table class="sg"><tr><td style="width:'+cw+'"><div class="lb">經辦：</div><div class="sb">'+stamp+'</div></td>'
    + (showRev ? '<td style="width:'+cw+'"><div class="lb">審核：</div></td>' : '')
    + '<td style="width:'+cw+'"><div class="lb">簽收：</div></td></tr></table></div>';
  return h;
}
function tpWrite(w, title, bodyHtml, asNo, multi){
  var css = printCss()
    + '.tp-sheet{page-break-after:always;}.tp-sheet:last-child{page-break-after:auto;}'
    + '.tp-sheet .tt{margin:6px 0;}.tp-sheet .co{font-size:19px;}'
    + '.sec{font-size:12.5px;font-weight:bold;background:#EFEFEF;border:1px solid #999;border-bottom:none;padding:2px 7px;margin-top:6px;}'
    + 'table.tp{width:100%;border-collapse:collapse;font-size:11.5px;table-layout:fixed;}'
    + 'table.tp td{border:1px solid #999;padding:3px 6px;word-break:break-all;line-height:1.3;}'
    + 'table.tp td.lb{background:#F7F7F7;font-weight:bold;width:21%;}'
    + 'table.tp td.chk{font-size:11px;width:33.33%;}'
    + 'table.sg td{height:62px;}';
  var doc = String(asNo||'').replace(/[\'\\]/g, '');
  var js = (multi ? ('var st=document.createElement("style");st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } @bottom-right{ content:\''+doc+'\'; font-size:9pt; color:#333; } }";document.head.appendChild(st);')
                 : ('var st=document.createElement("style");st.textContent="@page{ @bottom-right{ content:\''+doc+'\'; font-size:9pt; color:#333; } }";document.head.appendChild(st);'))
         + 'setTimeout(function(){window.print();},250);';
  w.document.open();
  w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
    + bodyHtml + '<scr'+'ipt>window.onload=function(){'+js+'};</scr'+'ipt></body></html>');
  w.document.close();
}

/* 網址 ?tab=tool 直接開刀具詢價分頁 */
$(function(){ if (/[?&]tab=tool/.test(location.search)) showTab('tool'); });
})();
</script>
<?php return; }
