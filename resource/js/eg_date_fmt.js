/**
 * 全站共用日期顯示格式化（2026-08-06 使用者明確要求：西元年日期顯示一律 YYYY.MM.DD，唯一實作）。
 * 只用於「顯示」給人看的文字/列印，不要用在要送回後端的 <input type=date> 值或 AJAX 參數
 * （那些欄位仍用瀏覽器原生 yyyy-mm-dd，交給 <input type=date> 自己處理，不受本檔影響）。
 * 禁止各頁自己再寫一份 fmtDate/formatDate，一律載入本檔呼叫 egFmtDate()。
 */
function egFmtDate(d, withTime) {
    if (!d) return '';
    var s = (d instanceof Date) ? d.toISOString() : String(d);
    var m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return s;
    var out = m[1] + '.' + m[2] + '.' + m[3];
    if (withTime && m[4]) out += ' ' + m[4] + ':' + m[5];
    return out;
}
