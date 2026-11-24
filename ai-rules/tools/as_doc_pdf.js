// AS 文件線上版 → PDF（用無頭 Chrome 在「列印版面」下量過再輸出，所以前端看到的就是印出來的樣子）
// 用法：node as_doc_pdf.js <version_id> <PHPSESSID> <輸出.pdf> <網站根網址，例 http://192.168.2.128>
// 由 views/ADM/as_doc_pdf.php 呼叫。唯一實作，不要在別處再寫一份。
const {spawn}=require('child_process');const fs=require('fs');const os=require('os');const path=require('path');
const [,, VID, SID, OUT, BASE]=process.argv;
const PORT=9700+Math.floor(Math.random()*250);
const UD=path.join(os.tmpdir(),'asdocpdf_'+process.pid+'_'+Date.now());
const CHROME=['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(p=>fs.existsSync(p));
if(!CHROME){console.error('找不到 Chrome／Edge');process.exit(3)}
const ch=spawn(CHROME,['--headless=new','--disable-gpu','--ignore-certificate-errors','--remote-debugging-port='+PORT,'--user-data-dir='+UD,'--no-first-run','about:blank'],{stdio:'ignore'});
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
function done(code){try{ch.kill()}catch(e){} setTimeout(()=>{try{fs.rmSync(UD,{recursive:true,force:true})}catch(e){} process.exit(code)},300);}
setTimeout(()=>{console.error('逾時');done(2)},100000);
(async()=>{
 let tg;for(let i=0;i<50;i++){try{const l=await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();tg=l.find(x=>x.type==='page'&&x.url==='about:blank');if(tg)break}catch(e){}await sleep(300)}
 if(!tg){console.error('Chrome 沒起來');return done(3)}
 const ws=new WebSocket(tg.webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);
 let id=0;const pend={};ws.onmessage=m=>{const d=JSON.parse(m.data);if(d.id&&pend[d.id]){pend[d.id](d);delete pend[d.id]}};
 const send=(method,params={})=>new Promise(r=>{const i=++id;pend[i]=r;ws.send(JSON.stringify({id:i,method,params}))});
 const ev=async(expr)=>{const r=await send('Runtime.evaluate',{expression:expr,returnByValue:true,awaitPromise:true});return r.result&&r.result.result?r.result.result.value:null};
 await send('Network.enable');await send('Page.enable');
 await send('Network.setCookie',{name:'PHPSESSID',value:SID,url:BASE});
 await send('Page.addScriptToEvaluateOnNewDocument',{source:"window.print=function(){};"});
 await send('Page.navigate',{url:BASE+'/EGsystem/views/ADM/as_doc_print.php?version_id='+encodeURIComponent(VID)+'&pdfgen=1'});
 for(let i=0;i<80;i++){await sleep(500);if(await ev("document.readyState==='complete'&&document.querySelectorAll('.adt-page').length>0"))break}
 if(!(await ev("document.querySelectorAll('.adt-page').length>0"))){console.error('頁面沒有內容（沒登入或沒權限）');return done(4)}
 // 圖片與簽章都載完才量
 for(let i=0;i<40;i++){await sleep(500);if(await ev("Array.from(document.images).every(function(m){return m.complete})"))break}
 await sleep(2500);
 await ev("document.fonts?document.fonts.ready.then(function(){return 1}):1");
 await send('Emulation.setEmulatedMedia',{media:'print'});
 await sleep(800);
 const rep=await ev(`(function(){
  var mm=96/25.4,t=(typeof ADT_PAGE_H_MM!=='undefined'?ADT_PAGE_H_MM:0)*mm,log=[];
  if(!t)return 'no-target';
  var pages=Array.prototype.slice.call(document.querySelectorAll('.adt-page'));
  /* ① 長表格頁：把「文件抬頭」放進表格的表頭列，這樣表格被印到好幾張紙時每一張都有抬頭（thead 會跨頁重複） */
  pages.forEach(function(p,i){
    var h=p.getBoundingClientRect().height; if(h<=t*1.5)return;
    var hdr=p.querySelector(':scope > .adt-hdr'), tb=null;
    p.querySelectorAll(':scope > .adt-body table').forEach(function(x){ if(!tb||x.getBoundingClientRect().height>tb.getBoundingClientRect().height) tb=x; });
    if(!hdr||!tb)return;
    var th=tb.querySelector('thead'); if(!th){ th=document.createElement('thead'); var f=tb.querySelector('tr'); if(!f)return; var rows=Array.prototype.slice.call(tb.querySelectorAll(':scope > tbody > tr, :scope > tr')); tb.insertBefore(th,tb.firstChild); }
    var r0=th.querySelector('tr')||tb.querySelector('tr'), cols=0;
    Array.prototype.forEach.call((r0?r0.children:[]),function(c){cols+=parseInt(c.getAttribute('colspan')||'1',10)});
    var tr=document.createElement('tr'), td=document.createElement('td');
    td.colSpan=Math.max(cols,1); td.style.cssText='padding:0;border:0;background:#fff'; td.appendChild(hdr.cloneNode(true)); tr.appendChild(td);
    th.insertBefore(tr,th.firstChild); hdr.parentNode.removeChild(hdr); log.push('hdr→thead p'+(i+1));
  });
  /* ② 比一張紙高一點點的頁（整頁圖片、單一大區塊、簽章區）：等比縮到剛好放進去，不再多印只有頁尾的空白紙 */
  pages.forEach(function(p,i){
    var h=p.getBoundingClientRect().height;
    if(h<=t+0.5||h>t*1.5)return;
    var b=p.querySelector(':scope > .adt-body');
    if(!b){Array.prototype.forEach.call(p.children,function(c){if(!b||c.getBoundingClientRect().height>b.getBoundingClientRect().height)b=c})}
    for(var n=0;n<5;n++){h=p.getBoundingClientRect().height;if(h<=t+0.5)break;var bh=b.getBoundingClientRect().height,cur=parseFloat(b.style.zoom)||1;b.style.zoom=String(cur*Math.max(.5,(bh-(h-t)-2)/bh));}
    log.push('fit p'+(i+1)+' '+Math.round(p.getBoundingClientRect().height/mm)+'mm');
  });
  return log.join(',');})()`);
 console.error('adjust:',rep);
 const pr=await send('Page.printToPDF',{preferCSSPageSize:true,printBackground:true,displayHeaderFooter:false});
 if(!pr.result||!pr.result.data){console.error('printToPDF 失敗');return done(5)}
 fs.writeFileSync(OUT,Buffer.from(pr.result.data,'base64'));
 console.log('ok');done(0);
})().catch(e=>{console.error(e&&e.message||e);done(1)});
