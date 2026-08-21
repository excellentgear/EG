/**
 * eg_print_log.js — 全站共用「列印紀錄」前端（2026-08-21 使用者明確要求）
 *
 * 用法：頁面按下列印時呼叫一次
 *   EGPrintLog.record({
 *     source   : 'bom_viewer',            // 必填，要先登錄在 print_log_lib.php 的 eg_print_sources()
 *     doc_name : '2103430230103.jpg',     // 必填，文件名稱（附件檔名或單據標題）
 *     doc_kind : 'attachment',            // attachment(預設) / form
 *     ref_table: 'part_attachments',      // 選填
 *     ref_id   : 123,                     // 選填
 *     part_no  : 'RC105-N03-A',           // 選填
 *     note     : '作廢'                    // 選填
 *   });
 *
 * 兩個原則（見 ai-rules/23）：
 *   1. 送出即忘：紀錄寫不寫得進去都不可以影響使用者列印，所以不等回應、不跳錯誤。
 *   2. 記的是「按下列印」這個動作：瀏覽器不會告訴網頁使用者最後有沒有真的送印或按取消，
 *      所以列印對話框按取消也會留下一筆——這是刻意的，避免有人靠按取消規避紀錄。
 */
(function (w) {
    'use strict';

    // API 位置由本檔自己的 <script src> 推導，頁面在第幾層資料夾都不用改
    function apiUrl() {
        var s = document.currentScript;
        if (!s) {
            var all = document.getElementsByTagName('script');
            for (var i = all.length - 1; i >= 0; i--) {
                if ((all[i].src || '').indexOf('eg_print_log.js') >= 0) { s = all[i]; break; }
            }
        }
        var src = (s && s.src) || '';
        var cut = src.indexOf('/resource/js/eg_print_log.js');
        if (cut < 0) return '../../src/store/PrintSignLog_API.php';   // 退路：本專案頁面都在第二層
        return src.substring(0, cut) + '/src/store/PrintSignLog_API.php';
    }

    var EGPrintLog = {
        record: function (opt) {
            try {
                if (!opt || !opt.source || !opt.doc_name) return;
                var fd = new FormData();
                fd.append('action', 'log_print');
                fd.append('source', opt.source);
                fd.append('doc_name', String(opt.doc_name).substring(0, 255));
                fd.append('doc_kind', opt.doc_kind || 'attachment');
                if (opt.ref_table) fd.append('ref_table', opt.ref_table);
                if (opt.ref_id || opt.ref_id === 0) fd.append('ref_id', String(opt.ref_id));
                if (opt.part_no)  fd.append('part_no', String(opt.part_no).substring(0, 60));
                if (opt.note)     fd.append('note', String(opt.note).substring(0, 255));
                // keepalive：使用者按完列印可能立刻關掉分頁，沒有 keepalive 的請求會被瀏覽器丟掉
                fetch(apiUrl(), { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true })
                    .catch(function () { /* 紀錄失敗不影響列印，靜默 */ });
            } catch (e) { /* 同上 */ }
        }
    };

    w.EGPrintLog = EGPrintLog;
})(window);
