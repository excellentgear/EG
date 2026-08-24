// 刀具規格表單（d_setting_tool）煙霧測試
// 驗 fillToolSpecForm / collectToolSpec / clearToolSpecForm / onToolKindChange 四支
// 實際執行：帶入真實滾齒刀資料→收集回來比對、切插齒刀時欄位顯示切換、清空、
// null/undefined 不可變成字串 "null"。這類錯 php -l 與 node --check 都抓不到。
//   用法：先把四支函式抽到 $SP/tool_funcs.js，再 SP=<暫存目錄> node test_tool_spec_form.js
const fs=require('fs'), SP=process.env.SP;
const els={};
function mk(id){ return els[id]={id,value:'',checked:false,style:{display:''}}; }
['pt-tool_kind','pt-module_input_type','pt-module_value','pt-pressure_angle','pt-starts_rh','pt-starts_lh',
 'pt-od_length','pt-d_plus_f','pt-bore_dia','pt-tool_type','pt-teeth','pt-outer_dia','pt-shaper_tag',
 'pt-material','pt-coating','pt-remark','pt-has_six_spline'].forEach(mk);
const hob=[{style:{display:''}}], shp=[{style:{display:''}}];
global.document={ getElementById:id=>els[id]||null,
  querySelectorAll:s=> s==='.tool-hob-only'?hob : s==='.tool-shaper-only'?shp : [] };
require('vm').runInThisContext(fs.readFileSync(SP+'/tool_funcs.js','utf8'));

let fail=0;
// ① 帶入一筆真實的滾齒刀資料，再收集回來，比對是否一致
const src={tool_kind:'hob',module_input_type:'M',module_value:'1.25',pressure_angle:'20',starts_rh:'1',
           starts_lh:null,od_length:'80-120',d_plus_f:'3.0000',bore_dia:'26.9880',tool_type:'PGSP',
           teeth:null,outer_dia:null,shaper_tag:null,material:null,coating:null,remark:null,has_six_spline:0};
try{
  fillToolSpecForm(src);
  const got=collectToolSpec();
  const chk=['tool_kind','module_input_type','module_value','pressure_angle','starts_rh','od_length','d_plus_f','bore_dia','tool_type'];
  const bad=chk.filter(k=>String(got[k])!==String(src[k]));
  console.log('  滾齒刀 帶入→收集  ' + (bad.length? '✗ 不一致: '+bad.join(','):'OK'));
  if(bad.length) fail++;
  console.log('  滾齒刀欄位顯示    hob='+hob[0].style.display+' shaper='+shp[0].style.display+
              (hob[0].style.display===''&&shp[0].style.display==='none'?'  OK':'  ✗'));
  if(!(hob[0].style.display===''&&shp[0].style.display==='none')) fail++;
}catch(e){ console.log('  ✗ 例外：'+e.message); fail++; }

// ② 切成插齒刀：應改顯示插齒刀欄位
try{
  fillToolSpecForm({tool_kind:'shaper',teeth:'20',outer_dia:'75',shaper_tag:'A',has_six_spline:1});
  const g=collectToolSpec();
  const ok = g.tool_kind==='shaper' && g.teeth==='20' && g.has_six_spline===1 &&
             shp[0].style.display==='' && hob[0].style.display==='none';
  console.log('  插齒刀 帶入→收集  '+(ok?'OK':'✗ '+JSON.stringify({kind:g.tool_kind,teeth:g.teeth,six:g.has_six_spline})));
  if(!ok) fail++;
}catch(e){ console.log('  ✗ 例外：'+e.message); fail++; }

// ③ 清空
try{
  clearToolSpecForm();
  const g=collectToolSpec();
  const dirty=Object.keys(g).filter(k=>k!=='has_six_spline'&&g[k]!=='');
  console.log('  清空表單          '+(dirty.length===0&&g.has_six_spline===0?'OK':'✗ 殘留 '+dirty.join(',')));
  if(dirty.length||g.has_six_spline!==0) fail++;
}catch(e){ console.log('  ✗ 例外：'+e.message); fail++; }

// ④ null 值不可變成字串 "null"
try{
  fillToolSpecForm({tool_kind:'hob',material:null,remark:undefined});
  const g=collectToolSpec();
  const ok=g.material===''&&g.remark==='';
  console.log('  null/undefined    '+(ok?'OK（不會存成字串 null）':'✗ material='+g.material+' remark='+g.remark));
  if(!ok) fail++;
}catch(e){ console.log('  ✗ 例外：'+e.message); fail++; }
process.exit(fail?1:0);
