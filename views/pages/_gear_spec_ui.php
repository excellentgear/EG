<?php
/**
 * _gear_spec_ui.php —— 齒輪規格設定（齒型列）的**全站唯一實作**
 *
 * 2026-10-02 由 views/pages/master_data_management.php 原封不動抽出來，讓報價單管理的
 * 「新增料號」跳窗與主檔管理用**同一份**畫面與規則（使用者交辦：報價單的齒輪規格要跟
 * 主檔管理的齒輪規格設定完全一樣）。抽出來之前那邊只有「齒輪類型／模數／齒數／壓力角…」
 * 幾個欄位，模數沒有 M/CP/DP 切換、沒有齒輪等級、鏈輪與花鍵的專屬欄位也沒有，
 * 於是同一支料號在兩個地方填出來的東西不一樣。
 *
 * 用法（放在 </body> 之前，jQuery 之後；本元件自己不依賴 jQuery）：
 *     <?php
 *       $GEAR_SPEC_CFG = [
 *           'wrap_id'  => 'gear-rows-wrap',   // 齒型列要畫在哪個容器（預設 gear-rows-wrap）
 *           'd_id_el'  => 'pf-d_id',          // 目前料號 d_id 的欄位 id（判斷是不是既有列）
 *           'can'      => ['edit'=>true,'delete'=>false,'modify'=>true],
 *       ];
 *       include __DIR__.'/_gear_spec_ui.php';
 *     ?>
 * 區塊的外框（標題＋容器＋新增齒型鈕）用 eg_gear_spec_section() 輸出，兩頁共用同一份。
 *
 * 對外的全域（**名稱刻意與抽出前一字不差**，所以主檔管理其餘程式碼一行都不用改）：
 *   gearRows / gearTypeOptions / _gearQualityRef
 *   addGearRow() removeGearRow() renderGearRows() collectGearRows()
 *   onGearTypeChange() onModuleTypeChange() formatModuleNew() switchHelixMode() syncDMS()
 *   onChainSizeChange() calcSprocket() onBeltProfileChange() calcTimingPulley()
 *   onSplineStdChange() onSplineTypeChange() onGearQualityStdChange()
 *   gearTypeHasHelix() getGearSpecCategory() getGearTypeName()
 *   reloadGearTypeOptions() loadGearQualityRef() loadChainSizes() loadBeltProfiles()
 *
 * 宿主頁面可以提供（有才用，沒有不會壞）：
 *   deleteGearRow(gearId, dId)  ← 「立即刪除既有齒型列」；只有 can.delete 為真時才會被呼叫
 *
 * 字典一律向 views/pages/gear_spec_api.php 拿（齒輪類型／鏈條規格／皮帶齒型／齒輪等級對照），
 * 一次抓齊、同一個分頁只抓一次，**不要再各頁自己查 dict_gear_type**（鐵律4）。
 */

if (!function_exists('eg_gear_spec_section')) {
    /**
     * 輸出齒輪規格設定區塊的外框（標題＋齒型列容器＋新增齒型鈕）。
     * @param array $opt section_id/wrap_id/can_edit/title/hint/style
     */
    function eg_gear_spec_section(array $opt = []): void {
        $sid   = $opt['section_id'] ?? 'gear-section';
        $wid   = $opt['wrap_id']    ?? 'gear-rows-wrap';
        $can   = !empty($opt['can_edit']);
        $title = $opt['title'] ?? '齒輪規格設定';
        $hint  = $opt['hint']  ?? '一個料號可記錄多組齒型（如雙聯齒輪）';
        $style = $opt['style'] ?? 'display:none;';
        echo '<div id="' . htmlspecialchars($sid) . '" style="' . htmlspecialchars($style) . '">' . "\n";
        echo '    <div class="form-section-title" style="color:#e67e22;"><i class="fa fa-cog fa-spin"></i> ' . htmlspecialchars($title) . '</div>' . "\n";
        echo '    <div id="' . htmlspecialchars($wid) . '"><!-- 動態渲染 --></div>' . "\n";
        if ($can) {
            echo '    <button type="button" class="btn btn-xs btn-default" onclick="addGearRow()" style="margin-top:4px;"><i class="fa fa-plus"></i> 新增齒型</button>' . "\n";
        }
        if ($hint !== '') echo '    <div class="id-hint" style="margin-top:4px;">' . htmlspecialchars($hint) . '</div>' . "\n";
        echo '</div>' . "\n";
    }
}

$__gs_cfg = isset($GEAR_SPEC_CFG) && is_array($GEAR_SPEC_CFG) ? $GEAR_SPEC_CFG : [];
// API 網址：由本檔自身位置推導成網站絕對路徑，這樣不管哪個目錄的頁面 include 都指得到
// （比照 views/Sales/_gear_tool_ui.php 的既有做法）
$__gs_url = 'gear_spec_api.php';
$__gs_droot = rtrim(str_replace(chr(92), '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$__gs_here  = str_replace(chr(92), '/', __DIR__);
if ($__gs_droot !== '' && strpos($__gs_here, $__gs_droot) === 0) {
    $__gs_url = substr($__gs_here, strlen($__gs_droot)) . '/gear_spec_api.php';
}
?>
<style>
/* 齒型列（由主檔管理搬過來，兩頁共用同一份樣式） */
.gear-row { background:#fffbf0; border:1px solid #fde8c0; border-radius:6px; padding:10px 12px 6px; margin-bottom:10px; position:relative; overflow:hidden; }
.gear-row .row { margin-left:-5px; margin-right:-5px; }
.gear-row .row > [class*="col-"] { padding-left:5px; padding-right:5px; }
.gear-row .form-group { margin-bottom:5px; }
.gear-row-title { font-size:11px; font-weight:700; color:#e67e22; margin-bottom:8px; text-transform:uppercase; letter-spacing:.5px; }
.btn-remove-row { position:absolute; top:8px; right:8px; background:none; border:none; color:#c0392b; font-size:16px; cursor:pointer; padding:0 4px; line-height:1; }
.btn-remove-row:hover { color:#e74c3c; }
</style>
<script>
// ══ 齒輪規格設定（唯一實作，見本檔頂端說明）══════════════════════════════
var EG_GEAR_SPEC_CFG = <?= json_encode([
    'wrapId'  => $__gs_cfg['wrap_id'] ?? 'gear-rows-wrap',
    'dIdEl'   => $__gs_cfg['d_id_el'] ?? 'pf-d_id',
    'can'     => array_merge(['edit' => false, 'delete' => false, 'modify' => false], $__gs_cfg['can'] ?? []),
    'apiUrl'  => $__gs_url,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

var gearRows = [];
var gearTypeOptions = [];   // 由 reloadGearTypeOptions() 從字典載入
var _gearQualityRef = [];   // 由 loadGearQualityRef() 從字典載入
var _chainSizeCache = null;
var _beltProfileCache = null;

// ── 宿主無關的小工具（抽出前分別是宿主頁面的 escHtml/trimFloat，這裡自備一份避免相依）──
function _gsEsc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function _gsTF(v) {
    if (v === '' || v === null || v === undefined) return '';
    var n = parseFloat(v);
    if (isNaN(n)) return String(v);
    return String(parseFloat(n.toPrecision(12)));
}
function _gsWrap() { return document.getElementById(EG_GEAR_SPEC_CFG.wrapId); }
function _gsHostDId() {
    var el = document.getElementById(EG_GEAR_SPEC_CFG.dIdEl);
    return parseInt((el && el.value) || '0') || 0;
}
// 權限：優先用本元件的設定，沒設定才退回宿主頁面原本的全域（主檔管理用的就是那三個）
function _gsCan(k) {
    var c = EG_GEAR_SPEC_CFG.can || {};
    if (c[k] !== undefined && c[k] !== null) return !!c[k];
    if (k === 'edit')   return (typeof CAN_EDIT_GEAR !== 'undefined') ? !!CAN_EDIT_GEAR : false;
    if (k === 'delete') return (typeof CAN_DELETE_GEAR !== 'undefined') ? !!CAN_DELETE_GEAR : false;
    if (k === 'modify') return (typeof CAN_MODIFY_EXISTING_GEAR !== 'undefined') ? !!CAN_MODIFY_EXISTING_GEAR : false;
    return false;
}

// ── 字典：一次抓齊、同一個分頁只抓一次 ────────────────────────────────
var _gsDicts = null, _gsDictWaiters = [], _gsDictLoading = false;
function _gsLoadDicts(force, cb) {
    if (!force && _gsDicts) { if (cb) cb(_gsDicts); return; }
    if (cb) _gsDictWaiters.push(cb);
    if (_gsDictLoading && !force) return;   // 已經在抓了，等它回來一起呼叫
    _gsDictLoading = true;
    fetch(EG_GEAR_SPEC_CFG.apiUrl + '?action=dicts', { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(r){
            _gsDicts = (r && r.success && r.data) ? r.data : { types:[], chains:[], belts:[], quality:[] };
            _gsApplyDicts();
            _gsDictDone();
        })
        .catch(function(){
            if (!_gsDicts) _gsDicts = { types:[], chains:[], belts:[], quality:[] };
            _gsDictDone();
        });
}
function _gsDictDone() {
    _gsDictLoading = false;
    var ws = _gsDictWaiters; _gsDictWaiters = [];
    ws.forEach(function(f){ if (f) { try { f(_gsDicts); } catch(e) {} } });
}
function _gsApplyDicts() {
    gearTypeOptions = (_gsDicts.types || []).map(function(g) {
        return {
            value:           String(g.gear_type_id),
            label:           g.type_name || '',
            hasHelix:        (g.has_helix_angle == '1' || g.has_helix_angle === 1),
            specCategory:    g.spec_category || 'standard',
            displayTemplate: g.display_template || ''
        };
    });
    _gearQualityRef = _gsDicts.quality || [];
    _chainSizeCache = _gsDicts.chains  || [];
    _beltProfileCache = _gsDicts.belts || [];
}
// 字典被管理員改過之後要重抓（主檔管理的齒輪類型維護畫面會呼叫）
function reloadGearTypeOptions(callback) { _gsLoadDicts(true, function(){ if (callback) callback(); }); }
function loadGearQualityRef(callback)    { _gsLoadDicts(false, function(){ if (callback) callback(); }); }
function loadChainSizes(cb)              { _gsLoadDicts(false, function(d){ cb(d.chains || []); }); }
function loadBeltProfiles(cb)            { _gsLoadDicts(false, function(d){ cb(d.belts  || []); }); }

function gearTypeHasHelix(typeVal) {
    if (!typeVal && typeVal !== 0) return false;
    var sv = String(typeVal);  // convert int to string for comparison
    var found = gearTypeOptions.filter(function(o){ return o.value===sv; });
    if (found.length) return found[0].hasHelix;
    return typeVal.indexOf('斜')>=0 || typeVal.indexOf('螺旋')>=0 || typeVal.indexOf('蝸')>=0;
}

function addGearRow(data) {
    data = data || {};
    gearRows.push({
        gear_id:              data.gear_id              || 0,
        Gear_Type:            data.Gear_Type             || '',
        Module:               data.Module                || '',
        Teeth:                data.Teeth                 || '',
        Face_Width:           data.Face_Width            || '',
        Helix_Direction:      data.Helix_Direction       || '',
        Helix_Angle_Str:      data.Helix_Angle_Str       || '',
        Helix_Angle:          data.Helix_Angle           || '',
        Pressure_Angle:       data.Pressure_Angle        || '',
        Profile_Shift_X:      data.Profile_Shift_X       || '',
        Workpiece_Length:     data.Workpiece_Length      || '',
        Spec_No:              data.Spec_No               || '',
        Remark_Gear:          data.Remark_Gear           || '',
        Gear_Quality_Std:     data.gear_quality_std      || '',
        Gear_Quality_Grade:   (data.gear_quality_grade !== null && data.gear_quality_grade !== undefined) ? String(data.gear_quality_grade) : '',
        module_input_type:    data.module_input_type      || 'M',
        module_display:       data.module_display         || '',
        spec_chain_size:      data.spec_chain_size       || '',
        spec_pitch:           data.spec_pitch            || '',
        spec_roller_dia:      data.spec_roller_dia       || '',
        spec_starts:          data.spec_starts           || '',
        spec_pulley_profile:  data.spec_pulley_profile   || '',
        spec_spline_type:        data.spec_spline_type         || '',
        spec_spline_major_dia:   data.spec_spline_major_dia    || '',
        spec_spline_minor_dia:   data.spec_spline_minor_dia    || '',
        spec_spline_width:       data.spec_spline_width        || '',
        spec_spline_std:         data.spec_spline_std          || '',
        spec_spline_nominal_dia: data.spec_spline_nominal_dia  || ''
    });
    renderGearRows();
}

function removeGearRow(idx) {
    gearRows.splice(idx, 1);
    renderGearRows();
}

function renderGearRows() {
    var wrap = _gsWrap();
    var canDeleteGearRow  = _gsCan('delete');
    var dId = _gsHostDId();
    if (!gearRows.length) {
        wrap.innerHTML = '<div style="color:#aaa;font-size:12px;padding:6px 0;">尚未設定齒輪規格，點擊下方「新增齒型」</div>';
        return;
    }

    var html = '';
    gearRows.forEach(function(g, i) {
        var isExisting = !!(g.gear_id && dId > 0);
        var rowEditable = _gsCan('edit') && (!isExisting || _gsCan('modify'));
        var hasHelix = gearTypeHasHelix(g.Gear_Type);
        var helixDisplay = hasHelix ? '' : 'display:none;';
        var specCat = getGearSpecCategory(g.Gear_Type);
        var isSprocket    = specCat === 'sprocket';
        var isTimingPulley= specCat === 'timing_pulley';
        var isSpline      = specCat === 'spline';
        var isWormGear    = specCat === 'worm_gear';
        var isStandard    = !isSprocket && !isTimingPulley && !isSpline;
        // 決定模數輸入類型和顯示值（有 module_display 才能正確還原 CP/DP）
        var mit = (g.module_display && g.module_display !== '')
            ? (String(g.module_display).match(/^(M|CP|DP)/i) || ['','M'])[1].toUpperCase()
            : 'M';
        var ro = rowEditable ? '' : 'disabled';

        // Build Gear_Type options
        var typeOpts = '<option value="">— 選擇 —</option>';
        gearTypeOptions.forEach(function(opt) {
            typeOpts += '<option value="'+opt.value+'"'+(String(g.Gear_Type)===opt.value?' selected':'')+'>'+opt.label+'</option>';
        });

        // DMS angle fields
        var dmsAngle = parseDMS(g.Helix_Angle_Str || String(g.Helix_Angle||''));

        html += '<div class="gear-row" id="gear-row-'+i+'"'+(isExisting&&!_gsCan('modify')?' style="opacity:.72;background:#f8f8f8;"':'')+' >';
        html += '<div class="gear-row-title"><i class="fa fa-cog"></i> 齒型 ' + (i+1) + (isExisting&&!_gsCan('modify')?' <span style="font-size:10px;color:#aaa;font-weight:normal;">(唯讀)</span>':'') + '</div>';
        if (rowEditable && !isExisting) {
            // new row: client-side remove
            html += '<button type="button" class="btn-remove-row" onclick="removeGearRow('+i+')" title="移除"><i class="fa fa-times"></i></button>';
        } else if (_gsCan('modify') && isExisting) {
            // existing row with full permission: show remove (client-side) or delete (server-side)
            if (canDeleteGearRow && g.gear_id && typeof deleteGearRow === 'function') {
                html += '<button type="button" class="btn-remove-row" onclick="deleteGearRow('+g.gear_id+','+dId+')" title="立即刪除此齒型" style="color:#e74c3c;"><i class="fa fa-trash"></i></button>';
            } else {
                html += '<button type="button" class="btn-remove-row" onclick="removeGearRow('+i+')" title="移除"><i class="fa fa-times"></i></button>';
            }
        }
        // CRU + existing row: no button at all

        html += '<div class="row">';
        // Gear Type (always visible)
        html += '<div class="col-md-3"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">齒輪類型</label>';
        html += '<select class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Gear_Type" onchange="onGearTypeChange('+i+',this.value)" '+ro+'>'+typeOpts+'</select>';
        html += '</div></div>';

        // ── 標準齒輪欄位（模數 M/CP/DP + 齒數 + 齒寬 + 壓力角）──────────────────
        html += '<div class="gear-standard-fields-'+i+'"'+(isStandard?'':' style="display:none;"')+'>';
        // Module with M/CP/DP toggle
        html += '<div class="col-md-3" data-field-col="Module" data-gear-row="'+i+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;" id="lbl-module-'+i+'">模數</label>';
        html += '<div style="display:flex;gap:3px;">';
        html += '<select class="form-control input-sm" id="module-type-'+i+'" style="width:60px;flex-shrink:0;" onchange="onModuleTypeChange('+i+',this.value)" '+ro+'>';
        html += '<option value="M"'+(mit==='M'?' selected':'')+'>M</option>';
        html += '<option value="CP"'+(mit==='CP'?' selected':'')+'>CP</option>';
        html += '<option value="DP"'+(mit==='DP'?' selected':'')+'>DP</option>';
        html += '</select>';
        var modDisplay;
        if (g.module_display && g.module_display !== '') {
            var _md2 = String(g.module_display).match(/^(?:M|CP|DP)(.+)$/i);
            modDisplay = _md2 ? _md2[1] : g.module_display;
        } else {
            modDisplay = g.Module ? String(g.Module).replace(/^[Mm]/,'') : '';
        }
        html += '<input type="text" class="form-control input-sm gear-field" id="module-val-'+i+'" data-idx="'+i+'" data-field="Module" value="'+_gsEsc(modDisplay)+'" placeholder="2.5" onblur="formatModuleNew('+i+')" '+ro+'>';
        html += '</div>';
        html += '<div id="module-m-display-'+i+'" style="font-size:10px;color:#888;margin-top:2px;display:none;"></div>';
        html += '</div></div>';
        // Teeth
        html += '<div class="col-md-2" data-field-col="Teeth" data-gear-row="'+i+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;" id="lbl-teeth-'+i+'">齒數</label>';
        html += '<input type="number" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Teeth" value="'+_gsEsc(String(g.Teeth||''))+'" placeholder="32" '+ro+'>';
        html += '</div></div>';
        // Face Width
        html += '<div class="col-md-2" data-field-col="Face_Width" data-gear-row="'+i+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">齒寬 (mm)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Face_Width" value="'+_gsEsc(_gsTF(g.Face_Width))+'" placeholder="20" '+ro+'>';
        html += '</div></div>';
        // Pressure Angle
        html += '<div class="col-md-2" data-field-col="Pressure_Angle" data-gear-row="'+i+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">壓力角 (PA)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Pressure_Angle" value="'+_gsEsc(_gsTF(g.Pressure_Angle))+'" placeholder="20°" '+ro+'>';
        html += '</div></div>';
        html += '</div>'; // end gear-standard-fields

        // ── 鏈輪欄位 ─────────────────────────────────────────────────────────────
        html += '<div class="gear-sprocket-fields-'+i+'"'+(isSprocket?'':' style="display:none;"')+'>';
        html += '<div class="col-md-3"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">鏈條規格</label>';
        html += '<select class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_chain_size" onchange="onChainSizeChange('+i+',this.value)" '+ro+'>';
        html += '<option value="">— 選擇或自訂 —</option><option value="custom">自訂節距/滾子徑</option>';
        html += '</select>';
        html += '</div></div>';
        html += '<div class="col-md-2"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">節距 P (mm)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" id="chain-pitch-'+i+'" data-idx="'+i+'" data-field="spec_pitch" value="'+_gsEsc(_gsTF(g.spec_pitch))+'" placeholder="12.7" oninput="calcSprocket('+i+')" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">滾子外徑 Dr (mm)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" id="chain-roller-'+i+'" data-idx="'+i+'" data-field="spec_roller_dia" value="'+_gsEsc(_gsTF(g.spec_roller_dia))+'" placeholder="7.95" oninput="calcSprocket('+i+')" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;" data-field-col="Teeth">齒數</label>';
        html += '<input type="number" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Teeth" value="'+_gsEsc(String(g.Teeth||''))+'" placeholder="20" oninput="calcSprocket('+i+')" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-3"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">自動計算</label>';
        html += '<div id="sprocket-calc-'+i+'" style="font-size:11px;color:#1a5276;background:#f0f4fb;padding:4px 8px;border-radius:4px;line-height:1.6;min-height:28px;"></div>';
        html += '</div></div>';
        html += '</div>'; // end sprocket fields

        // ── 皮帶輪欄位 ───────────────────────────────────────────────────────────
        html += '<div class="gear-pulley-fields-'+i+'"'+(isTimingPulley?'':' style="display:none;"')+'>';
        html += '<div class="col-md-3"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">皮帶齒型</label>';
        html += '<select class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_pulley_profile" onchange="onBeltProfileChange('+i+',this.value)" '+ro+'>';
        html += '<option value="">— 選擇 —</option>';
        html += '</select></div></div>';
        html += '<div class="col-md-2"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">齒數</label>';
        html += '<input type="number" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Teeth" value="'+_gsEsc(String(g.Teeth||''))+'" placeholder="32" oninput="calcTimingPulley('+i+')" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">節距 (mm)</label>';
        html += '<input type="text" class="form-control input-sm" id="pulley-pitch-'+i+'" value="'+_gsEsc(_gsTF(g.spec_pitch))+'" placeholder="自動" readonly style="background:#f5f5f5;">';
        html += '</div></div>';
        html += '<div class="col-md-2"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">自動計算 (PD/OD)</label>';
        html += '<div id="pulley-calc-'+i+'" style="font-size:11px;color:#1a5276;background:#f0f4fb;padding:4px 8px;border-radius:4px;line-height:1.6;min-height:28px;"></div>';
        html += '</div></div>';
        html += '</div>'; // end pulley fields

        // ── 蝸桿牙口數（worm_gear 才顯示，在 Row 1 末端）──────────────────────
        html += '<div class="gear-worm-starts-'+i+'"'+(isWormGear?'':' style="display:none;"')+'>';
        html += '<div class="col-md-2"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">牙口數（條）</label>';
        html += '<input type="number" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_starts" value="'+_gsEsc(String(g.spec_starts||''))+'" placeholder="1" min="1" '+ro+'>';
        html += '</div></div>';
        html += '</div>'; // end worm-starts

        // ── 花鍵齒形（在 Row 1 末，僅花鍵顯示）─────────────────────────────────
        html += '<div class="gear-spline-type-'+i+'"'+(isSpline?'':' style="display:none;"')+'>';
        html += '<div class="col-md-3"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">花鍵標準</label>';
        html += '<select class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_spline_std" onchange="onSplineStdChange('+i+',this.value)" '+ro+'>';
        html += '<option value="">— 選擇 —</option>';
        ['DIN5480','ISO4156','ANSI B92.1','JIS B1603','其他'].forEach(function(s){
            html += '<option value="'+s+'"'+(g.spec_spline_std===s?' selected':'')+'>'+s+'</option>';
        });
        html += '</select></div></div>';
        var _invStds = ['DIN5480','ISO4156','ANSI B92.1','JIS B1603'];
        var _stdIsInv = _invStds.indexOf(g.spec_spline_std) >= 0;
        // 已知標準（均為漸開線）自動鎖定齒形，不顯示選擇器
        html += '<div class="col-md-3 spline-type-block-'+i+'"'+(_stdIsInv?' style="display:none;"':'')+'><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">花鍵齒形</label>';
        html += '<select class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_spline_type" onchange="onSplineTypeChange('+i+',this.value)" '+ro+'>';
        html += '<option value="">— 選擇 —</option>';
        ['漸開線','矩形','三角'].forEach(function(t){
            html += '<option value="'+t+'"'+(g.spec_spline_type===t?' selected':'')+'>'+t+'</option>';
        });
        html += '</select></div></div>';
        html += '</div>'; // end spline-type

        // ── 花鍵 detail（漸開線/矩形/三角，在 Row 1 內，緊接花鍵齒形）──────────
        var spInvDisplay = (!g.spec_spline_type || g.spec_spline_type==='漸開線' || g.spec_spline_type==='三角') ? '' : 'display:none;';
        var spRectDisplay = g.spec_spline_type==='矩形' ? '' : 'display:none;';
        html += '<div class="gear-spline-fields-'+i+'"'+(isSpline?'':' style="display:none;"')+'>';
        html += '<div class="col-md-2 spline-inv-'+i+'" style="'+spInvDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">模數</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Module" value="'+_gsEsc(g.Module ? String(g.Module).replace(/^[Mm]/,'') : '')+'" placeholder="2" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2 spline-inv-'+i+'" style="'+spInvDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">鍵數</label>';
        html += '<input type="number" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Teeth" value="'+_gsEsc(String(g.Teeth||''))+'" placeholder="6" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2 spline-inv-'+i+'" style="'+spInvDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">壓力角 (PA)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Pressure_Angle" value="'+_gsEsc(g.Pressure_Angle||'')+'" placeholder="30°" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2 spline-inv-'+i+'" style="'+spInvDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">公稱直徑 D<sub>B</sub></label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_spline_nominal_dia" value="'+_gsEsc(_gsTF(g.spec_spline_nominal_dia))+'" placeholder="20" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2 spline-rect-'+i+'" style="'+spRectDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">鍵數</label>';
        html += '<input type="number" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Teeth" value="'+_gsEsc(String(g.Teeth||''))+'" placeholder="6" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2 spline-rect-'+i+'" style="'+spRectDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">小徑 (mm)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_spline_minor_dia" value="'+_gsEsc(_gsTF(g.spec_spline_minor_dia))+'" placeholder="23" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2 spline-rect-'+i+'" style="'+spRectDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">大徑 (mm)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_spline_major_dia" value="'+_gsEsc(_gsTF(g.spec_spline_major_dia))+'" placeholder="26" '+ro+'>';
        html += '</div></div>';
        html += '<div class="col-md-2 spline-rect-'+i+'" style="'+spRectDisplay+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">鍵寬 (mm)</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="spec_spline_width" value="'+_gsEsc(_gsTF(g.spec_spline_width))+'" placeholder="6" '+ro+'>';
        html += '</div></div>';
        html += '</div>'; // end spline-fields

        html += '</div>'; // end row 1

        // Row 2 - helix + extras
        html += '<div class="row">';
        // Helix group (show/hide based on type)
        html += '<div class="col-md-2 helix-group-'+i+'" style="'+helixDisplay+'">';
        html += '<div class="form-group" style="margin-bottom:6px;"><label style="font-size:11px;">旋向</label>';
        html += '<select class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Helix_Direction" '+ro+'>';
        html += '<option value="">—</option>';
        html += '<option value="RH"'+(g.Helix_Direction==='RH'?' selected':'')+'>RH 右旋</option>';
        html += '<option value="LH"'+(g.Helix_Direction==='LH'?' selected':'')+'>LH 左旋</option>';
        html += '</select></div></div>';

        // Helix angle
        html += '<div class="col-md-4 helix-group-'+i+'" style="'+helixDisplay+'">';
        html += '<div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">螺旋角 &nbsp;';
        html += '<label style="font-weight:normal;font-size:10px;cursor:pointer;margin:0;">';
        html += '<input type="radio" name="helix-mode-'+i+'" value="dec" '+(dmsAngle.mode!=='dms'?'checked':'')+' onchange="switchHelixMode('+i+',\'dec\')" style="margin-right:2px;">十進位</label> ';
        html += '<label style="font-weight:normal;font-size:10px;cursor:pointer;margin:0;">';
        html += '<input type="radio" name="helix-mode-'+i+'" value="dms" '+(dmsAngle.mode==='dms'?'checked':'')+' onchange="switchHelixMode('+i+',\'dms\')" style="margin-right:2px;">度分秒</label>';
        html += '</label>';
        // decimal mode
        html += '<div id="helix-dec-'+i+'" style="'+(dmsAngle.mode==='dms'?'display:none;':'')+'">';
        html += '<input type="text" class="form-control input-sm gear-field" id="helix-dec-val-'+i+'" data-idx="'+i+'" data-field="Helix_Angle_Str" value="'+_gsEsc(dmsAngle.mode!=='dms'?(g.Helix_Angle_Str||_gsTF(g.Helix_Angle)):'')+'" placeholder="15.5" '+ro+'>';
        html += '</div>';
        // DMS mode
        html += '<div id="helix-dms-'+i+'" style="'+(dmsAngle.mode==='dms'?'':'display:none;')+'">';
        html += '<div class="input-group" style="display:flex;gap:3px;">';
        html += '<input type="number" class="form-control input-sm" id="helix-d-'+i+'" placeholder="度" value="'+_gsEsc(String(dmsAngle.d||''))+'" style="width:50px;" '+ro+'>';
        html += '<input type="number" class="form-control input-sm" id="helix-m-'+i+'" placeholder="分" value="'+_gsEsc(String(dmsAngle.m||''))+'" style="width:50px;" onchange="syncDMS('+i+')" '+ro+'>';
        html += '<input type="number" class="form-control input-sm" id="helix-s-'+i+'" placeholder="秒" value="'+_gsEsc(String(dmsAngle.s||''))+'" style="width:50px;" onchange="syncDMS('+i+')" '+ro+'>';
        html += '</div></div>';
        html += '</div></div></div>';

        // Profile Shift X（僅標準齒輪類型顯示）
        html += '<div class="col-md-2 gear-shift-block-'+i+'"'+(isStandard?'':' style="display:none;"')+' data-field-col="Profile_Shift_X" data-gear-row="'+i+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">轉位係數 X</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Profile_Shift_X" value="'+_gsEsc(_gsTF(g.Profile_Shift_X))+'" placeholder="0" '+ro+'>';
        html += '</div></div>';
        // Remark
        html += '<div class="col-md-3" data-field-col="Remark_Gear" data-gear-row="'+i+'"><div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">備註</label>';
        html += '<input type="text" class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Remark_Gear" value="'+_gsEsc(g.Remark_Gear)+'" placeholder="備用說明" '+ro+'>';
        html += '</div></div>';
        // Gear Quality
        html += '<div class="col-md-3 gear-quality-block-'+i+'"'+(isSprocket?' style="display:none;"':'')+'>';
        html += '<div class="form-group" style="margin-bottom:6px;">';
        html += '<label style="font-size:11px;">齒輪等級</label>';
        html += '<div style="display:flex;gap:4px;">';
        html += '<select class="form-control input-sm gear-field" data-idx="'+i+'" data-field="Gear_Quality_Std" style="width:82px;" onchange="onGearQualityStdChange('+i+',this.value)" '+ro+'>';
        html += '<option value="">—</option>';
        ['JIS','ISO','DIN','AGMA'].forEach(function(s){ html += '<option value="'+s+'"'+(g.Gear_Quality_Std===s?' selected':'')+'>'+s+'</option>'; });
        html += '</select>';
        html += '<select class="form-control input-sm gear-field" id="gq-grade-'+i+'" data-idx="'+i+'" data-field="Gear_Quality_Grade" style="width:75px;" '+ro+'>';
        html += _buildGradeOpts(g.Gear_Quality_Std, g.Gear_Quality_Grade);
        html += '</select>';
        html += '</div></div></div>';
        html += '</div>'; // end row2


        html += '</div>'; // end gear-row
    });

    wrap.innerHTML = html;

    // bind live sync
    wrap.querySelectorAll('.gear-field').forEach(function(el) {
        ['change','input'].forEach(function(ev) {
            el.addEventListener(ev, function() {
                var idx = parseInt(this.dataset.idx);
                var field = this.dataset.field;
                if (!isNaN(idx) && gearRows[idx]) gearRows[idx][field] = this.value;
            });
        });
    });

    // 初始化鏈輪/皮帶輪下拉與計算
    gearRows.forEach(function(g, i) {
        // 初始化鏈輪/皮帶輪下拉與計算
        var cat = getGearSpecCategory(g.Gear_Type);
        if (cat === 'sprocket') _initChainSizeSelect(i, g.spec_chain_size);
        if (cat === 'timing_pulley') _initBeltProfileSelect(i, g.spec_pulley_profile);
    });
    _bindGearKeyNav();
}

function onGearTypeChange(idx, val) {
    gearRows[idx].Gear_Type = val;
    var hasHelix = gearTypeHasHelix(val);
    document.querySelectorAll('.helix-group-'+idx).forEach(function(el){ el.style.display = hasHelix?'':'none'; });
    var cat = getGearSpecCategory(val);
    var isSprocket = cat==='sprocket', isPulley = cat==='timing_pulley', isSpline = cat==='spline', isWorm = cat==='worm_gear';
    var isStd = !isSprocket && !isPulley && !isSpline;
    var stdEl = document.querySelector('.gear-standard-fields-'+idx);
    var spkEl = document.querySelector('.gear-sprocket-fields-'+idx);
    var puEl  = document.querySelector('.gear-pulley-fields-'+idx);
    var splEl = document.querySelector('.gear-spline-fields-'+idx);
    var gqEl  = document.querySelector('.gear-quality-block-'+idx);
    var gsEl  = document.querySelector('.gear-shift-block-'+idx);
    var gwEl  = document.querySelector('.gear-worm-starts-'+idx);
    var gstEl = document.querySelector('.gear-spline-type-'+idx);
    if (stdEl)  stdEl.style.display  = isStd ? '' : 'none';
    if (spkEl)  spkEl.style.display  = isSprocket ? '' : 'none';
    if (puEl)   puEl.style.display   = isPulley ? '' : 'none';
    if (splEl)  splEl.style.display  = isSpline ? '' : 'none';
    if (gqEl)   gqEl.style.display   = isSprocket ? 'none' : '';
    if (gsEl)   gsEl.style.display   = isStd ? '' : 'none';
    if (gwEl)   gwEl.style.display   = isWorm ? '' : 'none';
    if (gstEl)  gstEl.style.display  = isSpline ? '' : 'none';
    if (isSprocket) _initChainSizeSelect(idx, gearRows[idx].spec_chain_size||'');
    if (isPulley)   _initBeltProfileSelect(idx, gearRows[idx].spec_pulley_profile||'');
}

function onModuleTypeChange(idx, mit) {
    gearRows[idx].module_input_type = mit;
    var inp = document.getElementById('module-val-'+idx);
    var disp = document.getElementById('module-m-display-'+idx);
    if (!inp) return;
    var v = parseFloat(inp.value);
    if (!isNaN(v) && v > 0) {
        var mVal;
        if (mit==='M')  mVal = v;
        else if (mit==='CP') mVal = v / Math.PI;
        else if (mit==='DP') mVal = 25.4 / v;
        gearRows[idx].module_display = mit + _gsTF(String(v));
        if (disp) {
            if (mit!=='M') {
                var mStr = _gsTF(mVal.toFixed(6));
                disp.textContent = '= M'+mStr;
                disp.style.display = '';
                gearRows[idx].Module = 'M'+mStr;
            } else {
                disp.style.display = 'none';
                gearRows[idx].Module = 'M'+(inp.value||'');
            }
        }
    }
}

function formatModuleNew(idx) {
    var mitSel = document.getElementById('module-type-'+idx);
    var inp    = document.getElementById('module-val-'+idx);
    var disp   = document.getElementById('module-m-display-'+idx);
    if (!inp) return;
    var mit = mitSel ? mitSel.value : 'M';
    var v   = parseFloat(inp.value);
    if (!isNaN(v) && v > 0) {
        var mVal;
        if (mit==='M')  mVal = v;
        else if (mit==='CP') mVal = v / Math.PI;
        else if (mit==='DP') mVal = 25.4 / v;
        var mStr = _gsTF(mVal.toPrecision(8));
        gearRows[idx].module_input_type = mit;
        gearRows[idx].Module = 'M'+mStr;
        gearRows[idx].module_display = mit + _gsTF(String(v));
        if (disp && mit!=='M') { disp.textContent = '= M'+mStr; disp.style.display = ''; }
        else if (disp) { disp.style.display = 'none'; }
    }
}

function _initChainSizeSelect(idx, currentVal) {
    loadChainSizes(function(chains) {
        var sel = document.querySelector('.gear-sprocket-fields-'+idx+' select[data-field="spec_chain_size"]');
        if (!sel) return;
        var existing = sel.innerHTML.replace(/<option value="custom">.*<\/option>/,'');
        // rebuild options
        var opts = '<option value="">— 選擇或自訂 —</option>';
        var isCustom = currentVal && !chains.some(function(c){ return c.chain_size===currentVal; });
        chains.forEach(function(c) {
            opts += '<option value="'+_gsEsc(c.chain_size)+'"'+(c.chain_size===currentVal?' selected':'')+'>'+_gsEsc(c.chain_size)+' (P='+c.pitch_mm+', Dr='+c.roller_dia_mm+') '+c.chain_std+'</option>';
        });
        opts += '<option value="custom"'+(isCustom?' selected':'')+'>自訂節距/滾子徑</option>';
        sel.innerHTML = opts;
        if (currentVal && !isCustom) _fillChainSize(idx, currentVal, chains);
        if (isCustom) {
            var pitchEl2 = document.getElementById('chain-pitch-'+idx);
            var rollerEl2 = document.getElementById('chain-roller-'+idx);
            if (pitchEl2)  { pitchEl2.readOnly=false;  pitchEl2.style.background=''; }
            if (rollerEl2) { rollerEl2.readOnly=false; rollerEl2.style.background=''; }
        }
        calcSprocket(idx);
    });
}

function onChainSizeChange(idx, val) {
    gearRows[idx].spec_chain_size = val;
    if (val === 'custom') {
        var pitchEl = document.getElementById('chain-pitch-'+idx);
        var rollerEl = document.getElementById('chain-roller-'+idx);
        if (pitchEl)  { pitchEl.readOnly=false;  pitchEl.style.background=''; pitchEl.value=''; }
        if (rollerEl) { rollerEl.readOnly=false; rollerEl.style.background=''; rollerEl.value=''; }
        gearRows[idx].spec_pitch = '';
        gearRows[idx].spec_roller_dia = '';
        return;
    }
    loadChainSizes(function(chains){ _fillChainSize(idx, val, chains); });
}

function _fillChainSize(idx, val, chains) {
    var c = chains.filter(function(x){ return x.chain_size===val; })[0];
    if (!c) return;
    var pitchEl  = document.getElementById('chain-pitch-'+idx);
    var rollerEl = document.getElementById('chain-roller-'+idx);
    if (pitchEl)  { pitchEl.value=c.pitch_mm;    pitchEl.readOnly=true;  pitchEl.style.background='#f5f5f5'; }
    if (rollerEl) { rollerEl.value=c.roller_dia_mm; rollerEl.readOnly=true; rollerEl.style.background='#f5f5f5'; }
    gearRows[idx].spec_pitch = c.pitch_mm;
    gearRows[idx].spec_roller_dia = c.roller_dia_mm;
    calcSprocket(idx);
}

function calcSprocket(idx) {
    var P  = parseFloat(document.getElementById('chain-pitch-'+idx)  ? document.getElementById('chain-pitch-'+idx).value  : '');
    var Dr = parseFloat(document.getElementById('chain-roller-'+idx) ? document.getElementById('chain-roller-'+idx).value : '');
    var Z  = parseInt((document.querySelector('.gear-sprocket-fields-'+idx+' input[data-field="Teeth"]')||{}).value||'');
    var el = document.getElementById('sprocket-calc-'+idx);
    if (!el) return;
    if (!P || !Z || Z<=0) { el.innerHTML = '<span style="color:#aaa;">需要 P、Z 才能計算</span>'; return; }
    var PCD = P / Math.sin(Math.PI / Z);
    var OD  = P * (0.6 + 1/Math.tan(Math.PI/Z));
    var RD  = Dr ? (PCD - Dr) : null;
    el.innerHTML = '<b>PCD</b> = ' + PCD.toFixed(3) + ' mm'
        + (RD!==null ? '<br><b>根圓徑</b> = '+RD.toFixed(3)+' mm' : '')
        + '<br><b>OD ≈</b> ' + OD.toFixed(3) + ' mm';
}

function _initBeltProfileSelect(idx, currentVal) {
    loadBeltProfiles(function(profiles) {
        var sel = document.querySelector('.gear-pulley-fields-'+idx+' select[data-field="spec_pulley_profile"]');
        if (!sel) return;
        var opts = '<option value="">— 選擇 —</option>';
        profiles.forEach(function(p){
            opts += '<option value="'+_gsEsc(p.profile_code)+'"'+(p.profile_code===currentVal?' selected':'')+'>'+_gsEsc(p.profile_code)+' (P='+p.pitch_mm+'mm '+p.belt_standard+')</option>';
        });
        sel.innerHTML = opts;
        if (currentVal) _fillBeltProfile(idx, currentVal, profiles);
    });
}

function onBeltProfileChange(idx, val) {
    gearRows[idx].spec_pulley_profile = val;
    loadBeltProfiles(function(profiles){ _fillBeltProfile(idx, val, profiles); });
}

function _fillBeltProfile(idx, val, profiles) {
    var p = profiles.filter(function(x){ return x.profile_code===val; })[0];
    var pitchEl = document.getElementById('pulley-pitch-'+idx);
    if (p) {
        if (pitchEl) pitchEl.value = p.pitch_mm;
        gearRows[idx].spec_pitch = p.pitch_mm;
        gearRows[idx].spec_pld   = p.pld_mm;
    }
    calcTimingPulley(idx);
}

function calcTimingPulley(idx) {
    var teethEl = document.querySelector('.gear-pulley-fields-'+idx+' input[data-field="Teeth"]');
    var Z = parseInt(teethEl ? teethEl.value : '');
    var pitchEl = document.getElementById('pulley-pitch-'+idx);
    var P = parseFloat(pitchEl ? pitchEl.value : '');
    var g = gearRows[idx]; var PLD = parseFloat(g ? g.spec_pld||0 : 0);
    var el = document.getElementById('pulley-calc-'+idx);
    if (!el) return;
    if (!P || !Z || Z<=0) { el.innerHTML = '<span style="color:#aaa;">需要齒型、齒數才能計算</span>'; return; }
    var PD = (Z * P) / Math.PI;
    var OD = PD - (2 * PLD);
    el.innerHTML = '<b>PD</b> = '+PD.toFixed(3)+' mm<br><b>OD</b> = '+OD.toFixed(3)+' mm';
}

function getGearTypeName(typeVal) {
    if (!typeVal) return '';
    var sv = String(typeVal);
    var found = gearTypeOptions.filter(function(o){ return o.value===sv; });
    return found.length ? found[0].label : '';
}

function onSplineTypeChange(idx, val) {
    gearRows[idx].spec_spline_type = val;
    var isRect = val==='矩形';
    document.querySelectorAll('.spline-inv-'+idx).forEach(function(el){ el.style.display=isRect?'none':''; });
    document.querySelectorAll('.spline-rect-'+idx).forEach(function(el){ el.style.display=isRect?'':'none'; });
}

function onSplineStdChange(idx, val) {
    gearRows[idx].spec_spline_std = val;
    var invStds = ['DIN5480','ISO4156','ANSI B92.1','JIS B1603'];
    var isInvStd = invStds.indexOf(val) >= 0;
    var typeBlock = document.querySelector('.spline-type-block-'+idx);
    if (typeBlock) typeBlock.style.display = isInvStd ? 'none' : '';
    if (isInvStd) {
        gearRows[idx].spec_spline_type = '漸開線';
        var typeEl = document.querySelector('[data-idx="'+idx+'"][data-field="spec_spline_type"]');
        if (typeEl) typeEl.value = '漸開線';
        // 確保顯示漸開線欄位組
        document.querySelectorAll('.spline-inv-'+idx).forEach(function(el){ el.style.display=''; });
        document.querySelectorAll('.spline-rect-'+idx).forEach(function(el){ el.style.display='none'; });
    }
}

function _bindGearKeyNav() {
    var wrap = _gsWrap();
    if (!wrap || wrap._keyNavBound) return;
    wrap._keyNavBound = true;
    wrap.addEventListener('keydown', function(e) {
        var el = e.target;
        // Radio buttons: left/right arrow cycles options in the same name group
        if (el.type === 'radio' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
            e.preventDefault();
            var radios = Array.from(wrap.querySelectorAll('input[type=radio][name="'+el.name+'"]'));
            var idx = radios.indexOf(el);
            var next = e.key === 'ArrowRight' ? (idx + 1) % radios.length : (idx - 1 + radios.length) % radios.length;
            radios[next].click();
            radios[next].focus();
            return;
        }
        // Enter: advance to next visible+enabled input or select (not radio, not hidden, not button)
        if (e.key === 'Enter' && el.tagName !== 'BUTTON' && el.type !== 'radio') {
            e.preventDefault();
            var all = Array.from(wrap.querySelectorAll(
                'input:not([type=radio]):not([type=hidden]):not([disabled]), select:not([disabled])'
            )).filter(function(f) { return f.offsetParent !== null; });
            var cur = all.indexOf(el);
            if (cur >= 0 && cur < all.length - 1) all[cur + 1].focus();
        }
    });
}

function parseDMS(str) {
    if (!str) return {mode:'dec', d:'', m:'', s:''};
    str = String(str);
    // pattern like 11°18'5" or 11d18m5s
    var dmsMatch = str.match(/^(\d+)[°d]\s*(\d+)[\'m]\s*(\d+(?:\.\d+)?)[\"s]?/);
    if (dmsMatch) {
        return {mode:'dms', d:dmsMatch[1], m:dmsMatch[2], s:dmsMatch[3]};
    }
    return {mode:'dec', d:'', m:'', s:''};
}

function dmsToDecimal(d, m, s) {
    return parseFloat(d||0) + parseFloat(m||0)/60 + parseFloat(s||0)/3600;
}

function switchHelixMode(idx, mode) {
    var decDiv = document.getElementById('helix-dec-'+idx);
    var dmsDiv = document.getElementById('helix-dms-'+idx);
    if (mode === 'dms') {
        decDiv.style.display = 'none';
        dmsDiv.style.display = '';
    } else {
        decDiv.style.display = '';
        dmsDiv.style.display = 'none';
    }
}

function syncDMS(idx) {
    var d = parseFloat(document.getElementById('helix-d-'+idx).value||0);
    var m = parseFloat(document.getElementById('helix-m-'+idx).value||0);
    var s = parseFloat(document.getElementById('helix-s-'+idx).value||0);
    var dec = dmsToDecimal(d,m,s);
    var str = d + '°' + m + '\'' + s + '"';
    if (gearRows[idx]) {
        gearRows[idx].Helix_Angle = dec;
        gearRows[idx].Helix_Angle_Str = str;
    }
}

function collectGearRows() {
    gearRows.forEach(function(g, i) {
        // Sync all gear-fields
        var row = document.getElementById('gear-row-'+i);
        if (!row) return;
        row.querySelectorAll('.gear-field').forEach(function(el) {
            if (el.offsetParent !== null) g[el.dataset.field] = el.value;
        });
        // Check if DMS mode active
        var dmsDiv = document.getElementById('helix-dms-'+i);
        if (dmsDiv && dmsDiv.style.display !== 'none') {
            syncDMS(i);
        } else {
            var decVal = document.getElementById('helix-dec-val-'+i);
            if (decVal) {
                g.Helix_Angle_Str = decVal.value;
                g.Helix_Angle = parseFloat(decVal.value) || '';
            }
        }
    });
}

function _buildGradeOpts(std, selectedGrade) {
    var opts = '<option value="">— 等級 —</option>';
    if (!std || !_gearQualityRef.length) return opts;
    var colMap = { JIS:'jis_grade', ISO:'iso_grade', DIN:'din_grade', AGMA:'agma_grade' };
    var col = colMap[std];
    if (!col) return opts;
    var seen = {};
    _gearQualityRef.forEach(function(row) {
        var v = row[col];
        if (v === null || v === undefined || v === '') return;
        var vs = String(v);
        if (seen[vs]) return;
        seen[vs] = true;
        opts += '<option value="'+vs+'"'+(vs===String(selectedGrade)?' selected':'')+'>'+vs+'</option>';
    });
    return opts;
}

function onGearQualityStdChange(idx, std) {
    if (!isNaN(idx) && gearRows[idx]) {
        gearRows[idx]['Gear_Quality_Std']   = std;
        gearRows[idx]['Gear_Quality_Grade'] = '';
    }
    var gradeSelect = document.getElementById('gq-grade-'+idx);
    if (gradeSelect) {
        gradeSelect.innerHTML = _buildGradeOpts(std, '');
        gradeSelect.value = '';
    }
}

function getGearSpecCategory(typeVal) {
    if (!typeVal) return 'standard';
    var sv = String(typeVal);
    var found = gearTypeOptions.filter(function(o){ return o.value===sv; });
    return found.length ? (found[0].specCategory||'standard') : 'standard';
}


// 一載入就先把字典抓回來（齒型下拉、鏈條規格、齒輪等級都要用）
_gsLoadDicts(false, null);
</script>
