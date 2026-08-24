// 料號標籤「篩選列」煙霧測試
// 為什麼要有這支：2026-08-24 改篩選列時漏宣告一個變數，整條 forEach 丟 ReferenceError，
// 畫面上「標籤篩選」一個選項都長不出來。php -l 與 node --check 都抓不到（語法是對的），
// 只有實際執行才看得見。這支把篩選列的四支函式抽出來配最小 DOM 假件、餵真實標籤資料跑一遍。
//
// 用法（先產生資料檔，見 ai-rules/tools/tests/README 或下方註解）：
//   SP=<暫存目錄> node ai-rules/tools/tests/test_label_filter_bar.js
// 需要 $SP/labels.json 與 $SP/subs.json（用 sql.php 匯出 dict_label / dict_label_sub），
// 以及 $SP/filter_funcs.js（從 master_data_management.php 抽出 _makeLblValWrap /
// _readLblVals / buildLabelFilterRow / syncLabelFilter 四支函式）。
const fs=require('fs');
const SP=process.env.SP;
const labels=JSON.parse(fs.readFileSync(SP+'/labels.json','utf8')).rows;
const subs  =JSON.parse(fs.readFileSync(SP+'/subs.json','utf8')).rows;

// ── 最小 DOM 假件 ──────────────────────────────────────────
function El(tag){
  return {tagName:tag, children:[], dataset:{}, style:{cssText:'',setProperty(){}},
    className:'', _text:'', _html:'',
    classList:{_s:new Set(), add(c){this._s.add(c)}, remove(c){this._s.delete(c)}, contains(c){return this._s.has(c)}},
    set textContent(v){this._text=v}, get textContent(){return this._text},
    set innerHTML(v){this._html=v; this.children=[]}, get innerHTML(){return this._html},
    appendChild(c){this.children.push(c); c.parentElement=this; return c},
    removeChild(c){this.children=this.children.filter(x=>x!==c)},
    querySelector(sel){return this._all().find(e=>e._match(sel))||null},
    querySelectorAll(sel){return this._all().filter(e=>e._match(sel))},
    _match(sel){ if(sel.startsWith('.')) return (this.className||'').split(/\s+/).includes(sel.slice(1)); return false },
    _all(){ let out=[]; for(const c of this.children){ out.push(c); out=out.concat(c._all()); } return out },
    parentElement:null, get lastChild(){return this.children[this.children.length-1]}
  };
}
const lRow=El('div');
global.document={ createElement:El, createTextNode:t=>{const n=El('#text'); n.textContent=t; return n;}, getElementById:id=> id==='parts-label-filter'?lRow:null };
global._partsFilter={labels:[]};
global.loadParts=()=>{};
global.escHtml=s=>String(s==null?'':s);
global._stripLabelHint=s=>s;
global.trimFloat=n=>n;
global.updatePartsClearBtn=()=>{};
// api()：回傳 jQuery-like deferred，直接同步呼叫
global.api=function(opts){
  return { done(cb){
    if(opts.op==='list') cb({success:true,data:labels.filter(l=>!opts.type_code||String(l.type_code||'').split(',').includes(opts.type_code))});
    return this; }, fail(){return this} };
};
global._ensureSubLabelCache=function(lid,cb){ cb(subs.filter(s=>String(s.label_id)===String(lid))); };

require('vm').runInThisContext(fs.readFileSync(SP+'/filter_funcs.js','utf8'));

let fail=0;
for(const tc of ['','G','J','N','H','CFG']){
  try{
    buildLabelFilterRow(tc);
    const mains=lRow.querySelectorAll('lbl-main-chip'.replace(/^/,'')).length; // 用 class 比對
    const m=lRow._all().filter(e=>(e.className||'').includes('lbl-main-chip')).length;
    const sb=lRow._all().filter(e=>(e.className||'').includes('lbl-sub-chip')).length;
    console.log(`  種類 ${tc||'(全部)'} → 主標籤 ${m} 個、子標籤 ${sb} 個  ${m>0?'OK':'✗ 沒有選項'}`);
    if(m===0) fail++;
  }catch(e){ console.log(`  種類 ${tc||'(全部)'} → ✗ 例外：${e.message}`); fail++; }
}
// 再測按下 chip 後收集篩選條件不會爆
try{
  buildLabelFilterRow('G');
  lRow._all().filter(e=>(e.className||'').includes('lbl-main-chip')).forEach(c=>c.dataset.active='1');
  lRow._all().filter(e=>(e.className||'').includes('lbl-sub-chip')).forEach(c=>c.dataset.active='1');
  syncLabelFilter();
  console.log(`  syncLabelFilter() → 產生 ${_partsFilter.labels.length} 條篩選條件  OK`);
}catch(e){ console.log('  syncLabelFilter() → ✗ 例外：'+e.message); fail++; }
process.exit(fail?1:0);
