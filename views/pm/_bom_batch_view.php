<?php
/**
 * 批次檢視（Phase 1：只做顯示，絕不寫入任何資料）
 * ─────────────────────────────────────────────────────────────────────
 * 用途：BOM 拆成多批（batch_label A/B/C…）之後，讓「哪一批走到哪一關、
 *       目前什麼狀態」一眼看得出來。使用者 2026-09-04 交辦。
 *
 * 設計上最重要的三件事（都是為了「絕對不影響現有 18 位使用者」）：
 *   1. 預設全部關閉，開關存在 localStorage＝每個人自己的瀏覽器，
 *      不可能因為我開了而讓別人的畫面改變，也沒有任何後端設定要存。
 *   2. 正式頁 OreadyReply_ForPm_BaseOfTime.php 只有兩處改動：
 *      一行 include，以及 tbody.appendChild(tr) 前的一個 decorateRow() 掛勾
 *      （而且包在 try/catch 裡）。這支檔案就算整個爆掉，原本的列照樣畫得出來。
 *   3. 只處理「真的有 batch_label 的 BOM」。沒拆批的 BOM 一律原封不動，
 *      連一個像素都不會變（使用者指定）。
 *
 * 資料來源：window.bomPSList 的 split_batches / all_split_batches
 *          （製程欄本來就在用這份，不另外查、不新增任何 API）。
 *
 * ⚠ Phase 1 的已知限制：資料庫目前沒有「這批是從哪一批分出來的」父子欄位，
 *   所以流程圖是用「同一個批號跨站相連」畫的。真正的樹狀分流／合併連線
 *   要等 Phase 2 建立父子關聯後才畫得出來，畫面上會標示出來不會騙人。
 */
?>
<style>
/* ── 批次檢視：暖色系為主（ai-rules/10），狀態色沿用使用者指定的圖例 ── */
.eg-bv-legend{display:none;align-items:center;flex-wrap:wrap;gap:10px;padding:5px 9px;margin:0 0 6px;
    background:#FFFDF8;border:1px solid #E0B77A;border-radius:4px;font-size:11px;color:#7A4A12;line-height:1.5;}
.eg-bv-legend.on{display:flex;}
.eg-bv-legend b{font-weight:600;}
.eg-bv-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:3px;vertical-align:middle;}

/* 狀態色塊：字級小一定要自己寫 line-height，否則會繼承表格列行高把整列撐高 */
.eg-bv-badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;line-height:15px;
    white-space:nowrap;border:1px solid transparent;font-weight:600;}
.eg-bv-ing {background:#E8F0FE;border-color:#B7CDEB;color:#2E6DA4;}   /* 加工中 */
.eg-bv-qc  {background:#F7E0BD;border-color:#E0B77A;color:#7A4A12;}   /* QC待驗 */
.eg-bv-ok  {background:#EAF3EA;border-color:#CFE3CF;color:#4A7A4A;}   /* 待移轉（本站已驗完） */
.eg-bv-mv  {background:#F1EBE1;border-color:#D8C7AE;color:#7A5A2E;}   /* 已移轉（已走到下一站） */
.eg-bv-ng  {background:#DD5138;border-color:#DD5138;color:#fff;}      /* 判定NG（附加標，不蓋掉狀態） */
.eg-bv-na  {background:#F5F5F5;border-color:#E2E2E2;color:#999;}      /* 待發包 */
.eg-bv-done{opacity:.55;}                                             /* 本站已做完 → 整列淡化 */

/* 發單日欄的「同時進行中」摘要 */
.eg-bv-sum{margin:0 0 4px;padding:3px 5px;background:#FFFDF8;border:1px dashed #E0B77A;border-radius:3px;
    font-size:10px;line-height:1.5;color:#7A4A12;}
.eg-bv-sum-t{font-weight:600;display:block;margin-bottom:1px;}
.eg-bv-sum-i{display:block;white-space:nowrap;}

/* 製程欄的批次列 */
.eg-bv-cell-h{font-size:10.5px;font-weight:600;color:#444;margin-bottom:3px;}
.eg-bv-chip{font-size:9px;font-weight:normal;background:#F7E0BD;color:#7A4A12;border-radius:8px;padding:1px 5px;margin-left:3px;}
.eg-bv-row{display:flex;align-items:center;gap:4px;margin:2px 0;padding:2px 0 2px 5px;
    border-left:2px solid #E0B77A;font-size:10px;line-height:1.4;}
.eg-bv-lbl{font-weight:700;color:#7A4A12;min-width:12px;}
.eg-bv-qty{font-weight:600;color:#333;}
.eg-bv-sub{color:#777;font-size:9px;}

/* 欄標題旁的「此欄有進行中批次」圓點 */
.eg-bv-hdot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#F0A24B;margin-left:4px;vertical-align:middle;}

/* ── 流程圖列 ── */
tr.eg-bv-flow-row > td{background:#FFFDF8 !important;border-top:2px solid #E0B77A !important;padding:7px 10px !important;}
.eg-bv-flow-wrap{overflow-x:auto;}
.eg-bv-flow-t{font-size:11px;font-weight:600;color:#7A4A12;margin-bottom:5px;}
.eg-bv-lane{display:flex;align-items:stretch;margin-bottom:5px;}
.eg-bv-lane-lbl{flex:0 0 34px;display:flex;align-items:center;justify-content:center;font-weight:700;
    font-size:11px;color:#7A4A12;background:#F7E0BD;border:1px solid #E0B77A;border-radius:3px;margin-right:4px;}
.eg-bv-station{flex:0 0 168px;display:flex;align-items:center;}
.eg-bv-node{flex:1;min-width:0;border:1px solid #E0B77A;border-radius:4px;background:#fff;padding:3px 5px;font-size:10px;line-height:1.35;}
.eg-bv-node.na{border-style:dashed;border-color:#E2E2E2;background:transparent;}
/* 已經過此關（待移轉／已移轉）：淺暖底＋較深的暖色框線 */
.eg-bv-node.eg-bv-passed{background:#FBF3E6;border-color:#C9A063;}
/* 目前關卡：另一個淺暖底＋較粗的左框線，一眼看得出「現在在這裡」 */
.eg-bv-node.eg-bv-current{background:#FDF6EC;border-color:#F0A24B;box-shadow:inset 3px 0 0 #F0A24B;}
/* 節點內的備註（廠商下方）*/
.eg-bv-note-ro{font-size:9px;color:#7A4A12;line-height:1.35;margin-top:2px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eg-bv-note-raw{font-size:9px;color:#999;line-height:1.35;margin-top:1px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eg-bv-note-in{width:100%;box-sizing:border-box;margin-top:2px;padding:1px 3px;font-size:9px;
    line-height:1.35;border:1px solid #E0B77A;border-radius:2px;background:#fff;color:#333;}
.eg-bv-note-in:focus{outline:none;border-color:#F0A24B;}
/* 從發單日欄搬進來的狀態按鈕／檢驗燈號 */
.eg-bv-node-act{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:3px;}
.eg-bv-node-act:empty{display:none;}
.eg-bv-node-act .bv-btnrow{margin-top:2px !important;}
.eg-bv-node-t{font-weight:600;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eg-bv-node-s{color:#777;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eg-bv-link{flex:0 0 22px;height:1px;background:#C9A063;position:relative;align-self:center;}
.eg-bv-link:after{content:'';position:absolute;right:0;top:-3px;border:3px solid transparent;border-left-color:#C9A063;}
.eg-bv-link.off{background:transparent;}
.eg-bv-link.off:after{display:none;}
.eg-bv-branch{color:#DD5138;font-size:9px;font-weight:600;}
.eg-bv-note{font-size:10px;color:#A8814A;margin-top:3px;}

/* 流程圖開啟時，發單日欄留下的「目前這一關」摘要 */
.eg-bv-cur-line{margin-top:2px;line-height:1.25;}
.eg-bv-cur-t{font-weight:600;font-size:12px;color:#333;}
.eg-bv-cur-s{color:#555;font-size:11px;}
.eg-bv-cur-h{color:#A8814A;font-size:10px;margin-top:2px;}

/* 開關鈕 */
#eg-bv-toggle{margin-left:6px;}
.eg-bv-menu{position:absolute;z-index:12000;background:#fff;border:1px solid #E0B77A;border-radius:4px;
    box-shadow:0 3px 10px rgba(0,0,0,.15);padding:7px 10px;font-size:12px;min-width:210px;}
.eg-bv-menu label{display:block;font-weight:normal;margin:0 0 5px;cursor:pointer;color:#333;}
.eg-bv-menu label:last-of-type{margin-bottom:0;}
.eg-bv-menu input{margin-right:5px;}
.eg-bv-menu hr{margin:7px 0;border-top:1px solid #eee;}
.eg-bv-menu .eg-bv-hint{font-size:10.5px;color:#A8814A;line-height:1.5;}
@media print{.eg-bv-legend,#eg-bv-toggle{display:none !important;}}
</style>
<script>
/* 批次檢視：全部程式碼在這個 IIFE 內，對外只暴露 window.EGBatchView */
(function () {
    'use strict';

    var LS_KEY = 'eg_bom_batch_view_v1';

    // 快取：decorateRow 是「每一列每一次重繪」都會呼叫的，而本頁每 5 秒自動更新一次；
    // 每列都去讀一次 localStorage 是不必要的固定成本，故快取起來、寫入時才失效。
    var _pref = null;
    // 公司預設（管理員設定）：none / lane / flow / both。
    // 個人「沒自己動過開關」時套用它；動過的人以自己的選擇為準（使用者拍板）。
    function companyDefault() {
        var v = String(window.EG_BV_DEFAULT || 'none');
        return (v === 'lane' || v === 'flow' || v === 'both') ? v : 'none';
    }
    function defaultPref() {
        var d = companyDefault();
        return { lane: (d === 'lane' || d === 'both'), flow: (d === 'flow' || d === 'both'), _from: 'company' };
    }
    function readPref() {
        if (_pref) return _pref;
        var raw = null;
        try { raw = localStorage.getItem(LS_KEY); } catch (e) { raw = null; }  // 私密視窗會丟例外
        if (raw === null || raw === '') { _pref = defaultPref(); return _pref; }
        try { _pref = JSON.parse(raw) || defaultPref(); }
        catch (e) { _pref = defaultPref(); }
        return _pref;
    }
    function writePref(p) {
        _pref = p || {};
        delete _pref._from;   // 一旦自己動過就不再是「跟著公司預設」
        try { localStorage.setItem(LS_KEY, JSON.stringify(_pref)); } catch (e) {}
    }
    function usingCompanyDefault() { return readPref()._from === 'company'; }
    function resetToCompanyDefault() {
        try { localStorage.removeItem(LS_KEY); } catch (e) {}
        _pref = null;
    }
    function on(k) { return !!readPref()[k]; }
    function anyOn() { var p = readPref(); return !!(p.lane || p.flow); }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ── 一個批次的狀態判定（唯一實作，泳道與流程圖共用）──
       ⚠ 狀態文字一律比照「發單日」欄目前顯示的說法，兩邊不可以各講各的
       （使用者 2026-09-07 回報：泳道寫「已結」「OK」，發單日同一批寫「已移轉」「待移轉」）。
       欄位語意見資料字典：N=待發包 P=待移轉 Q=QC待驗 ing=加工中 E=已移轉。
       另兩條規則同樣照抄發單日欄：
         ① qc_completed=1 但狀態還停在 Q → 一律視為 P（待移轉）
         ② QC 判定 NG 不是一種「狀態」，狀態照原本顯示，NG 另外加一個紅標
            （舊版直接用 NG 蓋掉狀態，看不出那批到底走到哪裡） */
    function statusOf(b) {
        var st  = String(b.processing_state || '');
        var eff = (st === 'Q' && b.qc_completed == 1) ? 'P' : st;
        var r;
        if (eff === 'ing')                              r = { k: 'ing',  t: '加工中', cls: 'eg-bv-ing' };
        else if (eff === 'Q')                           r = { k: 'qc',   t: 'QC待驗', cls: 'eg-bv-qc'  };
        else if (eff === 'P')                           r = { k: 'wait', t: '待移轉', cls: 'eg-bv-ok'  };
        else if (eff === 'E' || eff === '1' || eff === 1) r = { k: 'done', t: '已移轉', cls: 'eg-bv-mv' };
        else if (eff === 'N')                           r = { k: 'na',   t: '待發包', cls: 'eg-bv-na'  };
        else                                            r = { k: 'na',   t: eff || '—', cls: 'eg-bv-na' };
        r.ng   = (String(b.QC_check || '') === 'ng');   // 判定 NG（額外紅標，不蓋掉狀態）
        r.dim  = (r.k === 'done' || r.k === 'wait');    // 這一站已經做完 → 整列淡化
        return r;
    }
    // 「進行中」＝加工中／QC待驗，或判定 NG（NG 要跳出來讓人看到）
    function isLive(s) { return s.k === 'ing' || s.k === 'qc' || !!s.ng; }
    // 狀態標籤（含 NG 紅標）的 HTML，泳道與流程圖共用
    function badgeHtml(s) {
        return '<span class="eg-bv-badge ' + s.cls + '">' + esc(s.t) + '</span>'
             + (s.ng ? '<span class="eg-bv-badge eg-bv-ng" style="margin-left:3px;">NG</span>' : '');
    }

    /* ── 取這支 BOM 的逐站批次結構 ──
       來源就是製程欄本來在用的 window.bomPSList，不另外查資料 */
    function stationsOf(bom) {
        var key = String(bom || '').trim();
        if (!key) return [];
        var list = (window.bomPSList || []).filter(function (p) {
            return p && p.bom && String(p.bom).trim() === key;
        });
        list.sort(function (a, b) { return (parseInt(a.bom_sn, 10) || 0) - (parseInt(b.bom_sn, 10) || 0); });
        return list.map(function (p) {
            var bs = (p.split_batches && p.split_batches.length > 1) ? p.split_batches
                   : (p.all_split_batches && p.all_split_batches.length > 1) ? p.all_split_batches
                   : null;
            return {
                bom_sn: p.bom_sn,
                name: p.ProcessName || '',
                batches: bs   // null＝這一站沒有拆批
            };
        });
    }
    function hasSplit(sts) {
        for (var i = 0; i < sts.length; i++) if (sts[i].batches) return true;
        return false;
    }

    function fmtDate(d) {
        if (!d) return '';
        var m = String(d).match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/);
        return m ? (parseInt(m[2], 10) + '/' + parseInt(m[3], 10)) : String(d);
    }

    /* ── (a) 泳道表格 ─────────────────────────────────────────── */

    // 發單日欄頂端的「同時進行中」摘要
    function summaryHtml(sts) {
        var live = [];
        sts.forEach(function (s) {
            if (!s.batches) return;
            s.batches.forEach(function (b) {
                var st = statusOf(b);
                if (!isLive(st)) return;
                live.push('<span class="eg-bv-sum-i">' + esc(s.bom_sn) + esc(s.name)
                        + ' <b>' + esc(b.batch_label || '─') + '</b> '
                        + badgeHtml(st) + '</span>');
            });
        });
        if (!live.length) return '';
        return '<div class="eg-bv-sum"><span class="eg-bv-sum-t">同時進行中：</span>' + live.join('') + '</div>';
    }

    // 單一製程欄的批次列表
    function stationCellHtml(s) {
        var h = '<div class="eg-bv-cell-h">' + esc(s.name)
              + '<span class="eg-bv-chip">拆' + s.batches.length + '批</span></div>';
        s.batches.forEach(function (b) {
            var st = statusOf(b);
            var sub = [fmtDate(b.outsource_date), b.maker_id || ''].filter(Boolean).join(' ');
            h += '<div class="eg-bv-row' + (st.dim ? ' eg-bv-done' : '') + '">'
              +    '<span class="eg-bv-lbl">' + esc(b.batch_label || '─') + '</span>'
              +    '<span class="eg-bv-qty">' + esc(b.sqty != null ? b.sqty : '') + '</span>'
              +    badgeHtml(st)
              +    (sub ? '<span class="eg-bv-sub">' + esc(sub) + '</span>' : '')
              +  '</div>';
        });
        return h;
    }

    /* ── (b) 流程圖 ───────────────────────────────────────────── */
    // 這一批是不是「目前關卡」＝發單日欄目前顯示的那幾關（row.bom_sn 可能是 "80,90,100"）
    function curSnSet(row) {
        var m = {};
        String(row && row.bom_sn != null ? row.bom_sn : '').split(',').forEach(function (s) {
            s = String(s).trim(); if (s) m[s] = true;
        });
        return m;
    }
    // 節點的走勢分類（使用者指定）：
    //   passed  已經過此關（待移轉／已移轉）→ 淺暖底＋框線變色
    //   current 目前關卡（bom_sn 在發單日欄那組裡）→ 另一個淺暖底
    //   其餘    未走到 → 維持原本樣式
    function nodePhase(st, s, curSet) {
        if (st.k === 'wait' || st.k === 'done') return 'passed';
        if (curSet[String(s.bom_sn).trim()]) return 'current';
        return '';
    }
    // 備註能不能在流程圖裡直接改（使用者指定）：
    //   已移轉(E) → 不得編輯；目前關卡 → 在上方「BOM/製程備註」欄編輯，這裡唯讀；
    //   其餘（未來關卡）→ 這裡可以直接編輯。
    function noteEditable(st, phase) {
        if (st.k === 'done') return false;   // 已移轉
        if (phase === 'current') return false;
        return canEditNote();
    }
    function canEditNote() { return (window.userStatus == 1); }
    // 節點裡「廠商下方」要顯示的備註：單關備註（可編輯的那個）＋ ERP 原始備註
    function noteHtml(b, st, phase) {
        var sp  = (b.single_bet_ps === null || b.single_bet_ps === undefined) ? '' : String(b.single_bet_ps);
        var raw = (b.ps === null || b.ps === undefined) ? '' : String(b.ps);
        var h = '';
        if (noteEditable(st, phase) && b.bom_ing_fid) {
            h += '<input class="eg-bv-note-in" type="text" value="' + esc(sp) + '"'
               + ' data-fid="' + esc(b.bom_ing_fid) + '" data-orig="' + esc(sp) + '"'
               + ' placeholder="單關備註（Enter 存檔）" title="單關備註（Enter 存檔）">';
        } else if (sp !== '') {
            h += '<div class="eg-bv-note-ro" title="' + esc(sp) + '">' + esc(sp) + '</div>';
        }
        if (raw !== '') h += '<div class="eg-bv-note-raw" title="' + esc(raw) + '">' + esc(raw) + '</div>';
        return h;
    }

    function flowHtml(sts, row) {
        var curSet = curSnSet(row);
        // 只取有拆批的站，並收集所有出現過的批號
        var cols = sts.filter(function (s) { return s.batches; });
        if (!cols.length) return '';
        var labels = [], seen = {};
        cols.forEach(function (s) {
            s.batches.forEach(function (b) {
                var l = b.batch_label || '─';
                if (!seen[l]) { seen[l] = true; labels.push(l); }
            });
        });
        labels.sort();

        var h = '<div class="eg-bv-flow-t">批次流程（依批號相連）</div><div class="eg-bv-flow-wrap">';
        labels.forEach(function (l) {
            h += '<div class="eg-bv-lane"><div class="eg-bv-lane-lbl">' + esc(l) + '</div>';
            var firstSeen = -1;
            cols.forEach(function (s, ci) {
                var b = null;
                for (var i = 0; i < s.batches.length; i++) {
                    if ((s.batches[i].batch_label || '─') === l) { b = s.batches[i]; break; }
                }
                if (b && firstSeen < 0) firstSeen = ci;
                h += '<div class="eg-bv-station">';
                if (b) {
                    var st = statusOf(b);
                    var phase = nodePhase(st, s, curSet);
                    var sub = [fmtDate(b.outsource_date), b.maker_id || ''].filter(Boolean).join(' ');
                    // data-bv-* 是給 decorateRow 把「狀態按鈕／檢驗燈號」原封不動搬進來用的
                    h += '<div class="eg-bv-node' + (st.dim ? ' eg-bv-done' : '')
                      +      (phase ? ' eg-bv-' + phase : '') + '"'
                      +      ' data-bv-sn="' + esc(s.bom_sn) + '" data-bv-label="' + esc(b.batch_label || '') + '">'
                      +    '<div class="eg-bv-node-t">' + esc(s.bom_sn) + esc(s.name)
                      +      ' <span class="eg-bv-qty">x' + esc(b.sqty != null ? b.sqty : '') + '</span>'
                      +      ' ' + badgeHtml(st) + '</div>'
                      +    (sub ? '<div class="eg-bv-node-s">' + esc(sub) + '</div>' : '')
                      +    noteHtml(b, st, phase)
                      +    '<div class="eg-bv-node-act"></div>'
                      +    (firstSeen === ci && ci > 0 ? '<div class="eg-bv-branch">∟ 此站才出現（由其他批分出）</div>' : '')
                      +  '</div>';
                } else {
                    h += '<div class="eg-bv-node na"><div class="eg-bv-node-s">—</div></div>';
                }
                h += '</div>';
                // 連線（最後一站不畫）
                if (ci < cols.length - 1) {
                    var nextHas = cols[ci + 1].batches.some(function (x) { return (x.batch_label || '─') === l; });
                    h += '<div class="eg-bv-link' + (b && nextHas ? '' : ' off') + '"></div>';
                }
            });
            h += '</div>';
        });
        h += '</div><div class="eg-bv-note">※ Phase 1：目前資料庫還沒有「這批從哪一批分出來」的父子欄位，'
           + '所以上面是用<b>同一個批號跨站相連</b>畫的；真正的分流／合併連線要等 Phase 2 建立父子關聯後才畫得出來。</div>';
        return h;
    }

    /* ── 欄標題圓點：該製程欄目前有進行中的批次 ── */
    function markHeaders() {
        var tbl = document.getElementById('table-DOWN');
        if (!tbl) return;
        var hrow = tbl.querySelector('thead tr');
        if (!hrow) return;
        // 先清掉舊的（關掉泳道時也要清乾淨）
        var olds = hrow.querySelectorAll('.eg-bv-hdot');
        for (var k = 0; k < olds.length; k++) olds[k].parentNode.removeChild(olds[k]);
        if (!on('lane')) return;
        var body = tbl.querySelector('tbody');
        if (!body) return;
        var ths = hrow.children, liveCols = {};
        var rows = body.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
            var tds = rows[r].children;
            for (var i = 0; i < tds.length; i++) {
                if (String(tds[i].className || '').indexOf('process-col') >= 0
                    && tds[i].querySelector('.eg-bv-ing, .eg-bv-qc, .eg-bv-ng')) {
                    liveCols[i] = true;
                }
            }
        }
        Object.keys(liveCols).forEach(function (i) {
            var th = ths[i];
            if (th && !th.querySelector('.eg-bv-hdot')) {
                var d = document.createElement('span');
                d.className = 'eg-bv-hdot';
                d.title = '此製程目前有進行中的批次';
                th.appendChild(d);
            }
        });
    }

    /* 圖例顯不顯示 */
    function syncLegend() {
        var lg = document.getElementById('eg-bv-legend');
        if (lg) lg.className = 'eg-bv-legend' + (on('lane') ? ' on' : '');
    }

    /* ── 表格每次重繪後都要同步圖例與欄標題圓點 ──
       清單有很多條重繪路徑（篩選、換頁、排序、5 秒自動更新），
       只在按開關時更新會出現「換頁後圓點不見了」。用 MutationObserver
       盯 tbody，任何一條路徑重繪都涵蓋得到，且只在真的重畫時才跑。 */
    var _syncTimer = null;
    function scheduleSync() {
        if (_syncTimer) clearTimeout(_syncTimer);
        _syncTimer = setTimeout(function () {
            _syncTimer = null;
            try { syncLegend(); markHeaders(); } catch (e) {}
        }, 80);
    }
    function watchTable() {
        var tbl = document.getElementById('table-DOWN');
        var body = tbl && tbl.querySelector('tbody');
        if (!body || body.__egBvWatched) return;
        body.__egBvWatched = true;
        try {
            new MutationObserver(scheduleSync).observe(body, { childList: true });
        } catch (e) { /* 舊瀏覽器沒有 MutationObserver 就算了，不影響其他功能 */ }
        scheduleSync();
    }

    /* ── 把發單日欄的「狀態按鈕／檢驗燈號」搬進對應的流程圖節點 ──────────────
       刻意用搬移（appendChild 同一個節點）而不是重畫：那些按鈕的 onclick 裡
       包著移轉權限、featTransfer、isCRU、userStatus 等一整套判斷，重刻一份
       必定走鐘（鐵律4），而且將來正式頁改了規則這裡不會跟著改。            */
    function relocateActions(tr, ftd) {
        var tdOut = tr.querySelector('td[name="outsource_date"]');
        if (!tdOut) return;
        var blocks = tdOut.querySelectorAll('.bv-proc-block');
        for (var i = 0; i < blocks.length; i++) {
            var blk = blocks[i];
            var sn  = blk.getAttribute('data-bv-sn') || '';
            var lbl = blk.getAttribute('data-bv-label') || '';
            var node = ftd.querySelector('.eg-bv-node[data-bv-sn="' + cssEsc(sn) + '"][data-bv-label="' + cssEsc(lbl) + '"]');
            if (!node) continue;   // 找不到對應節點就原地保留，絕不把按鈕弄不見
            var slot = node.querySelector('.eg-bv-node-act');
            if (!slot) continue;
            var rows = blk.querySelectorAll('.bv-btnrow');
            for (var j = 0; j < rows.length; j++) slot.appendChild(rows[j]);
        }
    }
    // 屬性選擇器用的簡易跳脫（批號只會是 A/B/C 這種，但還是保守處理）
    function cssEsc(v) { return String(v).replace(/["\\]/g, '\\$&'); }

    /* ── 流程圖開啟時，發單日欄只留「總數量＋目前這一關的製程/日期/廠商」──────
       各批次明細改到流程圖看（使用者指定）。按鈕已由 relocateActions 搬走，
       這裡只是把剩下的批次區塊收成一行摘要；工作天數那一行原樣保留。      */
    function slimOutsourceCell(tr, sts, row) {
        var tdOut = tr.querySelector('td[name="outsource_date"]');
        if (!tdOut) return;
        var blocks = tdOut.querySelectorAll('.bv-proc-block');
        if (!blocks.length) return;
        var curSet = curSnSet(row);
        // 取「目前這一關」：以發單日欄本來就在顯示的那些 sn 為準，取最後（最新）一個
        var pick = null;
        for (var i = 0; i < sts.length; i++) {
            if (curSet[String(sts[i].bom_sn).trim()]) pick = sts[i];
        }
        var line = document.createElement('div');
        line.className = 'eg-bv-cur-line';
        if (pick) {
            // 日期／廠商取這一關最新的那一批（多批時以最新發單日為準）
            var best = null;
            (pick.batches || []).forEach(function (b) {
                if (!best || String(b.outsource_date || '') > String(best.outsource_date || '')) best = b;
            });
            var od = best ? fmtDate(best.outsource_date) : '';
            var mk = best ? (best.maker_id || '') : '';
            line.innerHTML = '<div class="eg-bv-cur-t">' + esc(pick.bom_sn) + esc(pick.name) + '</div>'
                           + ((od || mk) ? '<div class="eg-bv-cur-s"></div>' : '')
                           + '<div class="eg-bv-cur-h">各批次明細與操作請見下方流程圖</div>';
            // 廠商名稱的電話／傳真／地址浮動視窗不可以因為改版就不見了：
            // 沿用正式頁的 applyMakerPopover（同一份實作），本列此時還沒被 append，
            // updateTable 結尾那次 popover 初始化會一併涵蓋到。
            var sEl = line.querySelector('.eg-bv-cur-s');
            if (sEl) {
                if (od) sEl.appendChild(document.createTextNode(od + (mk ? ' ' : '')));
                if (mk) {
                    var mkSpan = document.createElement('span');
                    mkSpan.className = 'maker-info-pop';
                    mkSpan.textContent = mk;
                    if (typeof window.applyMakerPopover === 'function') {
                        try { window.applyMakerPopover(mkSpan, best && best.maker_id_no, mk); } catch (e) {}
                    }
                    sEl.appendChild(mkSpan);
                }
            }
        } else {
            line.innerHTML = '<div class="eg-bv-cur-h">各批次明細與操作請見下方流程圖</div>';
        }
        blocks[0].parentNode.insertBefore(line, blocks[0]);
        for (var k = 0; k < blocks.length; k++) blocks[k].parentNode.removeChild(blocks[k]);
    }

    /* ── 流程圖裡的單關備註輸入框：Enter 存檔 ───────────────────────────────
       走正式頁既有的端點與規則（一個 bom_ing_fid 一筆），不另外開 API。   */
    function bindNoteInputs(ftd) {
        var ins = ftd.querySelectorAll('.eg-bv-note-in');
        for (var i = 0; i < ins.length; i++) {
            (function (el) {
                el.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter' && e.keyCode !== 13) return;
                    e.preventDefault();
                    var fid = el.getAttribute('data-fid') || '';
                    var val = el.value;
                    if (!/^\d+$/.test(fid)) return;
                    if (val === (el.getAttribute('data-orig') || '')) return;
                    el.disabled = true; el.style.borderColor = '#F0A24B';
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '_update_single_bet_ps.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function () {
                        if (xhr.readyState !== 4) return;
                        el.disabled = false; el.style.borderColor = '';
                        var ok = false, msg = '';
                        try { var r = JSON.parse(xhr.responseText); ok = !!(r && r.success); msg = (r && r.message) || ''; }
                        catch (ex) { msg = '回應格式錯誤'; }
                        if (ok) {
                            el.setAttribute('data-orig', val);
                            el.style.backgroundColor = '#EAF3EA';
                            setTimeout(function () { el.style.backgroundColor = ''; }, 900);
                            // 同步本機資料，下一次重繪才不會把剛打的字換回舊值
                            syncNoteToLocal(fid, val);
                        } else {
                            el.style.borderColor = '#DD5138';
                            alert('單關備註存檔失敗：' + (msg || '未知錯誤'));
                        }
                    };
                    xhr.send('bom_ing_fid=' + encodeURIComponent(fid) + '&single_bet_ps=' + encodeURIComponent(val));
                });
                // 在輸入框裡打字不要觸發表格的其他按鍵處理
                el.addEventListener('keyup', function (e) { e.stopPropagation(); });
            })(ins[i]);
        }
    }
    function syncNoteToLocal(fid, val) {
        (window.bomPSList || []).forEach(function (p) {
            if (!p) return;
            if (String(p.bom_ing_fid) === String(fid)) p.single_bet_ps = val;
            ['split_batches', 'all_split_batches'].forEach(function (key) {
                if (!Array.isArray(p[key])) return;
                p[key].forEach(function (b) { if (b && String(b.bom_ing_fid) === String(fid)) b.single_bet_ps = val; });
            });
        });
    }

    /* ── 對外唯一入口：正式頁在 tbody.appendChild(tr) 前呼叫 ── */
    function decorateRow(tr, row, tbody) {
        if (!anyOn()) return;                       // 沒開＝完全不做事
        if (!tr || !row || !row.bom) return;
        var sts = stationsOf(row.bom);
        if (!hasSplit(sts)) return;                 // 沒拆批的 BOM 一律不動（使用者指定）
        tr.setAttribute('data-eg-bv', '1');

        if (on('lane')) {
            // 發單日欄：最前面插「同時進行中」摘要
            var tdOut = tr.querySelector('td[name="outsource_date"]');
            if (tdOut) {
                var sh = summaryHtml(sts);
                if (sh) tdOut.insertAdjacentHTML('afterbegin', sh);
            }
            // 製程欄：有拆批的站改用新版樣式重畫
            var pcs = tr.querySelectorAll('td.process-col');
            for (var i = 0; i < pcs.length && i < sts.length; i++) {
                if (sts[i] && sts[i].batches) pcs[i].innerHTML = stationCellHtml(sts[i]);
            }
        }

        if (on('flow')) {
            var fh = flowHtml(sts, row);
            if (fh) {
                var ftr = document.createElement('tr');
                ftr.className = 'eg-bv-flow-row';
                var ftd = document.createElement('td');
                ftd.colSpan = Math.max(1, tr.children.length);
                ftd.innerHTML = fh;
                ftr.appendChild(ftd);
                // 狀態按鈕／檢驗燈號一律「搬」而不是重畫：同一個 DOM 節點連同它的
                // onclick 與權限判斷整組移進流程圖，才不會在這裡重刻一份權限規則（鐵律4/8）。
                relocateActions(tr, ftd);
                // 發單日欄改成只留「總數量＋目前這一關」（使用者指定）
                slimOutsourceCell(tr, sts, row);
                bindNoteInputs(ftd);
                // ⚠ 這裡絕對不可以自己 tbody.appendChild(tr)——呼叫端在我 return 之後
                //   還會再 append 一次，同一個節點 append 兩次是「搬移」，
                //   結果會變成本列跑到流程列後面。改用微任務：等呼叫端把 tr 放進
                //   DOM 之後（同步區塊結束時）再把流程列插在它正後方。
                Promise.resolve().then(function () {
                    if (tr.parentNode && !ftr.parentNode) tr.parentNode.insertBefore(ftr, tr.nextSibling);
                });
            }
        }
    }

    /* ── 開關 UI（自己注入工具列，正式頁不必為了這顆按鈕改任何一行）── */
    function refresh() {
        syncLegend();
        var b = document.getElementById('eg-bv-toggle');
        if (b) {
            var n = (on('lane') ? 1 : 0) + (on('flow') ? 1 : 0);
            b.className = 'btn btn-xs ' + (n ? 'btn-warning' : 'btn-default');
            b.innerHTML = '<i class="fa fa-sitemap"></i> 批次檢視' + (n ? '（開）' : '');
        }
        if (typeof window.processAndRenderData === 'function') window.processAndRenderData();
        scheduleSync();
    }

    function buildMenu(btn) {
        var old = document.getElementById('eg-bv-menu');
        if (old) { old.remove(); return; }
        var p = readPref();
        var m = document.createElement('div');
        m.id = 'eg-bv-menu';
        m.className = 'eg-bv-menu';
        var dnames = { none: '都不開啟', lane: '泳道表格', flow: '流程圖', both: '兩種都開' };
        var cd = companyDefault();
        m.innerHTML =
            '<label><input type="checkbox" id="eg-bv-lane"' + (p.lane ? ' checked' : '') + '>泳道表格（發單日摘要＋製程欄批次）</label>' +
            '<label><input type="checkbox" id="eg-bv-flow"' + (p.flow ? ' checked' : '') + '>流程圖（該列下方展開站點連線）</label>' +
            '<hr><div class="eg-bv-hint">兩種可以分開開、也可以一起開。<br>' +
            '設定只存在你自己的瀏覽器，<b>不會影響其他人</b>。<br>' +
            '只有<b>拆過批</b>的 BOM 會換成新版面。<br>' +
            '開啟流程圖時，發單日欄只留「目前這一關」，各批次的狀態按鈕與檢驗燈號改到流程圖裡按。<br>' +
            '目前公司預設：<b>' + esc(dnames[cd] || cd) + '</b>' +
            (usingCompanyDefault() ? '（你正在套用公司預設）' : '') + '</div>' +
            '<div style="margin-top:6px;"><button type="button" id="eg-bv-reset" class="btn btn-xs btn-default">還原成公司預設</button></div>' +
            (window.EG_BV_CAN_SET_DEFAULT ?
              '<hr><div class="eg-bv-hint"><b>管理員：設定公司預設</b><br>只影響「沒有自己動過開關」的人。</div>' +
              '<select id="eg-bv-cdef" class="form-control input-sm" style="margin-top:4px;">' +
                ['none','lane','flow','both'].map(function (k) {
                    return '<option value="' + k + '"' + (k === cd ? ' selected' : '') + '>' + esc(dnames[k]) + '</option>';
                }).join('') +
              '</select><div id="eg-bv-cdef-msg" style="font-size:10.5px;margin-top:3px;min-height:14px;"></div>'
              : '');
        document.body.appendChild(m);
        var r = btn.getBoundingClientRect();
        m.style.left = Math.max(6, Math.min(r.left, window.innerWidth - m.offsetWidth - 10)) + 'px';
        m.style.top  = (r.bottom + window.scrollY + 4) + 'px';
        m.querySelector('#eg-bv-lane').onchange = function () { var q = readPref(); q.lane = this.checked; writePref(q); refresh(); };
        m.querySelector('#eg-bv-flow').onchange = function () { var q = readPref(); q.flow = this.checked; writePref(q); refresh(); };
        m.querySelector('#eg-bv-reset').onclick = function () { resetToCompanyDefault(); m.remove(); refresh(); };
        var cdefSel = m.querySelector('#eg-bv-cdef');
        if (cdefSel) {
            cdefSel.onchange = function () {
                var val = this.value, msg = m.querySelector('#eg-bv-cdef-msg'), sel = this;
                sel.disabled = true;
                if (msg) { msg.style.color = '#A8814A'; msg.textContent = '儲存中…'; }
                var xhr = new XMLHttpRequest();
                // 端點就是本頁自己（action 走 OreadyReply_ForPm_BaseOfTime_ajax.php），
                // 後端會再驗一次是不是系統管理員（鐵律8）。
                xhr.open('POST', window.location.pathname, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState !== 4) return;
                    sel.disabled = false;
                    var ok = false, em = '';
                    try { var r = JSON.parse(xhr.responseText.replace(/^﻿/, '')); ok = !!(r && r.success); em = (r && r.message) || ''; }
                    catch (ex) { em = '回應格式錯誤'; }
                    if (ok) {
                        window.EG_BV_DEFAULT = val;
                        if (msg) { msg.style.color = '#4A7A4A'; msg.textContent = '✔ 已儲存，未自訂開關的人下次開頁面就會套用'; }
                        if (usingCompanyDefault()) { _pref = null; refresh(); }
                    } else {
                        if (msg) { msg.style.color = '#DD5138'; msg.textContent = '✘ ' + (em || '儲存失敗'); }
                        sel.value = companyDefault();
                    }
                };
                xhr.send('action=bv_save_default&value=' + encodeURIComponent(val));
            };
        }
        setTimeout(function () {
            document.addEventListener('mousedown', function h(e) {
                var mm = document.getElementById('eg-bv-menu');
                if (mm && !mm.contains(e.target) && e.target !== btn) { mm.remove(); document.removeEventListener('mousedown', h); }
            });
        }, 0);
    }

    function inject() {
        if (document.getElementById('eg-bv-toggle')) return;
        // 掛在「通知廠商圖」右側（使用者指定）。刻意不掛在「顯示製程／設定業務」
        // 那一列，那一列多一顆鈕會把排版擠掉。
        var anchor = document.getElementById('btn-vendor-notify-img');
        if (anchor && anchor.parentNode && anchor.parentNode.tagName === 'A') anchor = anchor.parentNode;
        if (!anchor) anchor = document.querySelector('button[onclick="scrollToProcesses()"]');  // 退路
        if (!anchor) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'eg-bv-toggle';
        btn.title = '拆批的 BOM 用新版面顯示（泳道表格／流程圖），設定只存在你自己的瀏覽器';
        btn.onclick = function (e) { e.stopPropagation(); buildMenu(btn); };
        anchor.insertAdjacentElement('afterend', btn);

        // 圖例（只有開泳道時才顯示）
        var lg = document.createElement('div');
        lg.id = 'eg-bv-legend';
        lg.className = 'eg-bv-legend';
        // 狀態名稱與「發單日」欄完全相同，兩邊不可以各講各的
        lg.innerHTML = '<b>圖例</b>'
            + '<span><span class="eg-bv-dot" style="background:#999;"></span>待發包</span>'
            + '<span><span class="eg-bv-dot" style="background:#2E6DA4;"></span>加工中</span>'
            + '<span><span class="eg-bv-dot" style="background:#F0A24B;"></span>QC待驗</span>'
            + '<span><span class="eg-bv-dot" style="background:#4A7A4A;"></span>待移轉（本站已驗完，淡化）</span>'
            + '<span><span class="eg-bv-dot" style="background:#C9A063;"></span>已移轉（已走到下一站，淡化）</span>'
            + '<span><span class="eg-bv-dot" style="background:#DD5138;"></span>判定NG（附加在狀態右側）</span>'
            + '<span><span class="eg-bv-hdot" style="margin-left:0;"></span>欄位標題旁圓點＝該欄目前有進行中批次</span>';
        var tbl = document.getElementById('table-DOWN');
        if (tbl && tbl.parentNode) tbl.parentNode.insertBefore(lg, tbl);
        syncLegend();
        // 只更新按鈕外觀，不在載入當下觸發重繪（避免多跑一次 processAndRenderData）
        var n = (on('lane') ? 1 : 0) + (on('flow') ? 1 : 0);
        btn.className = 'btn btn-xs ' + (n ? 'btn-warning' : 'btn-default');
        btn.innerHTML = '<i class="fa fa-sitemap"></i> 批次檢視' + (n ? '（開）' : '');
        watchTable();
    }

    window.EGBatchView = {
        decorateRow: decorateRow,
        markHeaders: markHeaders,
        isOn: anyOn,
        // 設定被本頁以外的地方改掉時（其他分頁、或手動改 localStorage）用來讓快取失效
        reload: function () { _pref = null; syncLegend(); scheduleSync(); }
    };
    // 另一個分頁改了設定 → 這一頁的快取要失效，不然兩邊會不一致
    window.addEventListener('storage', function (e) {
        if (e && e.key === LS_KEY) { _pref = null; syncLegend(); scheduleSync(); }
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(inject, 300); });
    else setTimeout(inject, 300);
})();
</script>
