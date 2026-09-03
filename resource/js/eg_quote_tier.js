/**
 * eg_quote_tier.js — 階梯報價的顯示格式（唯一實作，禁止各頁自刻）
 *
 * 階梯報價的品項本身沒有數量與單價（都在 quotation_item_tier 的各階裡），
 * 只印「(階梯)」的話使用者看不到數量區間也看不到單價。
 * 各頁的表格樣式不同，故這裡只提供「文字怎麼寫」，表格 markup 留給呼叫端。
 *
 * EGQuoteTier.rangeText(tier)   → '10~19' / '100以上'
 * EGQuoteTier.tolText(tier)     → '容差±5%｜備註'（沒有容差回空字串）
 * EGQuoteTier.priceText(tier)   → '$400'
 * EGQuoteTier.summary(tiers)    → '10~19 $400／20~49 $320'（一行摘要用）
 */
(function (w) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(n) {
        var f = parseFloat(n);
        if (!isFinite(f)) return '';
        return f.toLocaleString('zh-TW', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }
    function isBlank(v) { return v === null || v === undefined || v === ''; }

    var EGQuoteTier = {
        rangeText: function (t) {
            if (!t) return '';
            var mn = num(Math.round(Number(t.qty_min || 0)));
            return isBlank(t.qty_max) ? (mn + '以上') : (mn + '~' + num(Math.round(Number(t.qty_max))));
        },
        priceText: function (t) {
            if (!t || isBlank(t.unit_price)) return '';
            return '$' + num(t.unit_price);
        },
        // 已做過 HTML escape，可直接塞進 innerHTML
        tolText: function (t) {
            if (!t || isBlank(t.tolerance_value)) return '';
            return '容差±' + num(t.tolerance_value) + esc(t.tolerance_unit || '')
                 + (t.tolerance_note ? '｜' + esc(t.tolerance_note) : '');
        },
        summary: function (tiers) {
            if (!tiers || !tiers.length) return '';
            var self = this;
            return tiers.map(function (t) { return self.rangeText(t) + ' ' + self.priceText(t); }).join('／');
        }
    };

    w.EGQuoteTier = EGQuoteTier;
})(window);
