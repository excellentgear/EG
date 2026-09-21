/**
 * eg_dt_zhtw.js — DataTables 繁體中文語系（全站唯一實作，禁止各頁再自己寫一份）
 *
 * 為什麼要有這支：原本各頁是寫
 *     language: { url: '//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json' }
 * 而 **DataTables 拿到 language.url 時會先把整張表的初始化停下來等那支 AJAX 回來**。
 * 本系統是廠內網路，那支請求要嘛連不到、要嘛慢好幾秒，於是在它回來之前：
 *   ① 表格完全沒有分頁（所有列都留在 DOM 裡）② 搜尋與 drawCallback 全部不會執行。
 * 退貨追蹤 3,522 筆實測因此要 6.4 秒才看得到東西——這就是「載入很慢」的主因。
 * 改成本機物件之後零網路往返，初始化是同步的。
 *
 * 用法（取代原本的 language:{url:...}）：
 *     <script src="../../resource/js/eg_dt_zhtw.js?v=<?= @filemtime(...) ?>"></script>
 *     $('#tbl').DataTable({ language: EG_DT_ZHTW });
 * 要改其中一兩句：$.extend({}, EG_DT_ZHTW, { lengthMenu:'每頁 _MENU_ 筆' })
 */
var EG_DT_ZHTW = {
    processing:   "處理中...",
    loadingRecords: "載入中...",
    lengthMenu:   "顯示 _MENU_ 項結果",
    zeroRecords:  "沒有符合的結果",
    info:         "顯示第 _START_ 至 _END_ 項結果，共 _TOTAL_ 項",
    infoEmpty:    "顯示第 0 至 0 項結果，共 0 項",
    infoFiltered: "(從 _MAX_ 項結果中過濾)",
    infoPostFix:  "",
    search:       "搜尋:",
    searchPlaceholder: "",
    emptyTable:   "表中資料為空",
    thousands:    ",",
    paginate: { first: "首頁", previous: "上頁", next: "下頁", last: "尾頁" },
    aria: { sortAscending: ": 以升冪排列此列", sortDescending: ": 以降冪排列此列" }
};
