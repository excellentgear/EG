/**
 * AI 草稿（本機 Ollama）前端共用檔 —— 2026-10-16
 * 只有全站管理員看得到按鈕（後端 AiDraft_API.php 也擋）。草稿只填進欄位，不自動存檔。
 *
 * 用法：
 *   ① 頁面載入本檔後呼叫 EGAiDraft.init('../../src/store/AiDraft_API.php');
 *   ② 在要放按鈕的地方輸出 EGAiDraft.btnHtml('種類')，一般使用者看不到（預設隱藏，管理員且 Ollama 在線才顯示）
 *   ③ EGAiDraft.reg('種類', {collect:function($btn){return {…ctx};}, apply:function($btn,text){…}});
 *      collect 回傳 null＝不送出（自己先提示原因）。
 */
(function (w, $) {
    var API = '', CSRF = '', ON = false, REG = {};
    var css = '.ai-draft-btn{display:none !important;margin-left:6px;}body.ai-on .ai-draft-btn{display:inline-block !important;}'
            + '.ai-draft-btn.busy{opacity:.6;pointer-events:none;}.ai-only{display:none !important;}body.ai-on .ai-only{display:block !important;}';
    $('<style>').text(css).appendTo('head');
    function toast(msg, bad) {
        var $t = $('<div>').text(msg).css({position: 'fixed', right: '18px', bottom: '70px', zIndex: 20000, padding: '8px 14px', borderRadius: '6px',
            background: bad ? '#DD5138' : '#6B4E2E', color: '#fff', fontSize: '13px', maxWidth: '360px', boxShadow: '0 2px 8px rgba(0,0,0,.3)'}).appendTo('body');
        setTimeout(function () { $t.fadeOut(300, function () { $t.remove(); }); }, bad ? 5000 : 2600);
    }
    var E = {
        init: function (api) {
            API = api;
            $.getJSON(API, {action: 'info'}, function (r) {
                if (!r || !r.ok || !r.enabled) return;
                CSRF = r.csrf; ON = true; E.model = r.model;
                $('body').addClass('ai-on');
            });
        },
        btnHtml: function (kind, label) {
            return '<button type="button" class="b-mini warm ai-draft-btn" data-aidraft="' + kind + '" title="AI 草稿（本機 Ollama）：只產生草稿填入欄位，請確認後再存檔"><i class="fa fa-magic"></i> ' + (label || 'AI 草稿') + '</button>';
        },
        csrf: function () { return CSRF; },
        reg: function (kind, o) { REG[kind] = o; },
        /** 目標欄位已有內容時：確認取代，取消＝附加在後面 */
        fill: function ($el, text) {
            var cur = $.trim($el.val() || '');
            if (cur && !confirm('欄位已有內容，要用草稿取代嗎？\n（按「取消」＝把草稿附加在原內容後面）')) text = cur + '\n' + text;
            $el.val(text).trigger('input').trigger('change');
        }
    };
    $(document).on('click', '[data-aidraft]', function (ev) {
        ev.preventDefault(); ev.stopPropagation();
        var $b = $(this), kind = $b.data('aidraft'), h = REG[kind];
        if (!ON || !h || $b.hasClass('busy')) return;
        var ctx = h.collect($b);
        if (!ctx) return;
        var html0 = $b.html();
        $b.addClass('busy').html('<i class="fa fa-spinner fa-spin"></i> 產生中…');
        $.post(API, {action: 'draft', kind: kind, ctx: JSON.stringify(ctx), csrf: CSRF}, null, 'json').done(function (r) {
            if (r && r.ok) { h.apply($b, r.text); toast('已填入草稿（' + r.model + '，' + r.sec + ' 秒），請確認內容後再存檔'); }
            else toast((r && r.error) || '產生失敗', true);
        }).fail(function (x) {
            var m = ''; try { m = JSON.parse(x.responseText).error; } catch (e) {}
            toast(m || '產生失敗（Ollama 沒有回應）', true);
        }).always(function () { $b.removeClass('busy').html(html0); });
    });
    w.EGAiDraft = E;
})(window, jQuery);
