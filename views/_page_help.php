<?php
/**
 * 共用「使用說明」跳窗（CLAUDE.md 鐵律7 的唯一實作，禁止各頁再自刻一份）
 * ------------------------------------------------------------------------------
 * 為什麼要共用：鐵律7 規定每一頁都要有「使用說明」，位置與樣式**全站統一**。
 * 先前的做法是「照抄 vendor_audit.php」，抄一次就多一份 CSS 與跳窗結構，
 * 之後要調整外觀就得一頁一頁改（鐵律4）。這支只負責「殼」，內容由呼叫端給。
 *
 * 用法（兩步）：
 *   ① 頁首標題列右側放一顆按鈕（`margin-left:auto` 靠右）：
 *        <button id="btnPageHelp" class="page-help-btn"><i class="fa fa-question-circle"></i> 使用說明</button>
 *   ② `</body>` 之前：
 *        <?php
 *        $PAGE_HELP_TITLE = '快速出貨 使用說明';
 *        $PAGE_HELP_BODY  = '<h4>一、這一頁在做什麼</h4><ul><li>…</li></ul>';
 *        include __DIR__ . '/../_page_help.php';
 *        ?>
 *
 * 三件刻意這樣做的事：
 *   - **不依賴 jQuery**：有些頁面的 jQuery 是在頁尾才載入的，用原生事件才不會有順序問題。
 *   - **CSS 全部 `.eg-help-` 前綴**（除了鐵律7 指定的 `.page-help-btn`），不會汙染呼叫端的樣式。
 *   - **列印時一律隱藏**：說明不是文件的一部分。
 *
 * 內容怎麼寫（鐵律7）：功能說明＋操作步驟＋重要行為/常見疑問＋設定入口＋權限角色。
 * 頁面功能改了，這段內容要同步更新。
 */
if (!isset($PAGE_HELP_BODY) || trim((string)$PAGE_HELP_BODY) === '') return;
$__ph_title = isset($PAGE_HELP_TITLE) && trim((string)$PAGE_HELP_TITLE) !== '' ? (string)$PAGE_HELP_TITLE : '使用說明';
?>
<style>
.page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
    background:#F0A24B; color:#fff; cursor:pointer; }
.page-help-btn:hover { background:#d98a33; }
.eg-help-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:12000; }
.eg-help-mask.on { display:block; }
.eg-help-modal { background:#fff; border-radius:8px; max-width:760px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
    max-height:calc(100vh - 72px); display:flex; flex-direction:column; }
.eg-help-head { flex:0 0 auto; display:flex; align-items:center; gap:8px; padding:10px 14px;
    border-bottom:1px solid #EEDCC0; background:#F7EEDF; border-radius:8px 8px 0 0; color:#8A5A2B; font-weight:bold; }
.eg-help-head .x { margin-left:auto; cursor:pointer; font-size:18px; color:#a08356; }
.eg-help-head .x:hover { color:#C4442D; }
.eg-help-body { flex:1 1 auto; overflow:auto; padding:12px 16px; }
.eg-help-foot { flex:0 0 auto; padding:8px 14px; border-top:1px solid #EEDCC0; text-align:right; }
.eg-help-foot button { height:28px; padding:0 14px; border:1px solid #D8BE93; border-radius:4px; background:#fff; cursor:pointer; }
.help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
.help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
.help-doc h4:first-child { margin-top:0; }
.help-doc b { color:#8A5A2B; }
.help-doc ul { margin:4px 0 8px; padding-left:20px; }
.help-doc li { margin:2px 0; }
.help-doc p { margin:4px 0 8px; }
.help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
@media print { .page-help-btn, .eg-help-mask { display:none !important; } }
</style>
<div class="eg-help-mask" id="helpUseMask">
    <div class="eg-help-modal">
        <div class="eg-help-head">
            <i class="fa fa-question-circle"></i>
            <span><?= htmlspecialchars($__ph_title, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="x" data-eg-help-close>&times;</span>
        </div>
        <div class="eg-help-body help-doc"><?= $PAGE_HELP_BODY ?></div>
        <div class="eg-help-foot"><button type="button" data-eg-help-close>關閉</button></div>
    </div>
</div>
<script>
(function(){
    var mask = document.getElementById('helpUseMask');
    if (!mask) return;
    function open(){ mask.classList.add('on'); }
    function close(){ mask.classList.remove('on'); }
    var btn = document.getElementById('btnPageHelp');
    if (btn) btn.addEventListener('click', function(e){ e.preventDefault(); open(); });
    mask.addEventListener('click', function(e){
        // 點 ✕／關閉，或點跳窗外面的灰底都關掉
        if (e.target === mask || e.target.hasAttribute('data-eg-help-close')) close();
    });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
    window.egPageHelpOpen = open;      // 別的地方要叫出說明時用
})();
</script>
