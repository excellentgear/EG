<?php
/**
 * views/ACC/reconcile.php — 對帳作業（業務對應收、生管對應付）
 *
 * 實務分工：應收由業務對完帳給會計、應付由生管對完帳給會計。
 * 對帳時對方（客戶／廠商）常拿紙本來，且雙方單據切法不一致：
 *   - 我方分批送加工，廠商把它們併成一列請款  → 多項加總（勾多列→合併為一組）
 *   - 我方一次送，廠商拆批甚至拆月份請款      → 單項拆分（一列→拆成多段，可各自指定月份）
 * 這兩種都只影響「對帳底稿」，永不寫回原始出貨／加工紀錄；orig 值永久保留供稽核比對。
 *
 * 其他為了對帳速度做的事：點列即勾選並轉暖淺綠、可拖移排序成與紙本相同順序、
 * 旁邊掛計算機、可輸入對方紙本合計即時算差額、可暫存中斷後再繼續
 * （每個對象×月份只有一份暫存，確認送出後即成為已確認紀錄）。
 *
 * 鎖帳規則：按「確認正確」即鎖帳，涵蓋憑證標記已對完，之後不可改單也不可再暫存；
 * 僅會計管理員可退回重對且須填原因。已開立發票的憑證另有一層更硬的鎖
 * （不提供解鎖，只能作廢／折讓／補開），因為帳面要與國稅局申報一致。
 *
 * 資料一律走 src/store/Acc_API.php；權限 acc_lib.php（roles module='accounting'）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ACC/reconcile.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/acc_lib.php';

$db = (new DBConnection())->getPDO();
acc_ensure_schema($db);
$accUser = acc_current_user($db);
$perms   = acc_perms($db, $accUser);
$roleLbl = $perms['isAdmin'] ? '管理者'
         : ($perms['canAdmin'] ? '會計管理員'
         : ($perms['canEdit'] ? '會計登錄'
         : ($perms['reconAr'] && $perms['reconAp'] ? '應收＋應付對帳'
         : ($perms['reconAr'] ? '應收對帳(業務)'
         : ($perms['reconAp'] ? '應付對帳(生管)'
         : ($perms['canView'] ? '會計檢閱' : '無權限'))))));
$taxRate = acc_tax_rate($db);
include_once '../../src/common/org_role_lib.php';
$ownCompany = eg_company_full_name($db);      // 列印大標題＝本公司全名，動態取（ai-rules/16 第一節）
$reconPref  = acc_recon_pref($db);            // 拖移排序／依勾選順序排序的全站預設值
/* 開頁預設的帳款月份要依結帳日自動切換（使用者 2026-08-28 交辦）：
   結帳日還沒到＝這個月的帳還沒結完，現在對的是上個月。兩側結帳日不同，各算一份。 */
$bmDefault  = ['ar' => acc_default_billing_month($db, 'ar'),
               'ap' => acc_default_billing_month($db, 'ap')];

/* 由對帳單總覽（recon_overview.php）帶參數直接開某一份：
   ?side=ap&party_id=RZ002A&bm=2026-05。沒帶參數就照原本的下拉流程走。 */
$deep = ['side' => '', 'party_id' => '', 'bm' => ''];
if (isset($_GET['side']) || isset($_GET['party_id']) || isset($_GET['bm'])) {
    $deep['side']     = (($_GET['side'] ?? '') === 'ap') ? 'ap' : 'ar';
    $deep['party_id'] = trim((string)($_GET['party_id'] ?? ''));
    $bm = trim((string)($_GET['bm'] ?? ''));
    $deep['bm'] = preg_match('/^\d{4}-\d{2}$/', $bm) ? $bm : '';
    if ($deep['party_id'] === '' || $deep['bm'] === '') $deep = ['side' => '', 'party_id' => '', 'bm' => ''];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>對帳作業</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/nprogress.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<style>
/* 色系隨側別切換：應收＝琥珀橘（錢進來）、應付＝暖棕赭（錢出去），
   都在暖色系內但明顯可辨，另有文字與箭頭雙重標示。 */
:root{
  --a-line:#E8D5B5; --a-line2:#D8BE93; --a-bg:#FDF8EF; --a-bg2:#FFF7E8;
  --a-ink:#5b3a1e; --a-ink2:#8a6d45; --a-brand:#8A5A2B;
  --a-acc:#F0A24B; --a-acc-d:#d98a33; --a-ok:#F7E0BD; --a-bad:#DD5138;
  /* 已對到本筆的暖淺綠（偏橄欖／暖調，不是冷螢光綠） */
  --a-hit:#E4EDD4; --a-hit-bd:#BFCF9E;
}
body.side-ap{
  --a-line:#DFCBA9; --a-line2:#C9AE85; --a-bg:#FBF5EC; --a-bg2:#F6EBDA;
  --a-ink:#4E2C0B; --a-ink2:#7d6242; --a-brand:#7A4A1E;
  --a-acc:#A2703A; --a-acc-d:#8A5A2B; --a-ok:#E8D3B4; --a-bad:#C0392B;
}
#sidebar-menu{visibility:hidden;}
.right_col .page-title{margin:8px 0 4px;overflow:hidden;}

.side-band{display:flex;align-items:center;gap:10px;clear:both;margin-bottom:8px;color:#fff;
  border-radius:8px;padding:7px 14px;font-size:15px;box-shadow:inset 0 -3px 0 rgba(0,0,0,.14);
  background:linear-gradient(90deg,#F0A24B 0%,#E8912F 100%);}
body.side-ap .side-band{background:linear-gradient(90deg,#8A5A2B 0%,#6E4520 100%);}
.side-band .sb-sub{font-size:12.5px;opacity:.92;font-weight:normal;}
.side-band .sb-sw{margin-left:auto;display:flex;gap:6px;}
.side-band .sb-sw button{font-size:13px;color:#fff;background:rgba(255,255,255,.16);
  border:1px solid rgba(255,255,255,.5);border-radius:14px;padding:4px 14px;cursor:pointer;}
.side-band .sb-sw button.on{background:#fff;color:var(--a-brand);font-weight:bold;}

.a-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;clear:both;
  border:1.5px solid var(--a-line);border-radius:8px;padding:8px 10px;margin-bottom:8px;background:var(--a-bg);}
.a-bar label{margin:0;font-size:13px;color:var(--a-ink);font-weight:normal;}
.a-bar input[type=text],.a-bar input[type=month],.a-bar input[type=number],.a-bar select,.a-bar button{
  height:32px;font-size:13px;line-height:1;padding:0 10px;border:1px solid var(--a-line2);
  border-radius:4px;background:#fff;color:var(--a-ink);}
.a-bar button{cursor:pointer;}
.a-bar button:hover{background:var(--a-ok);}
.a-bar .btn-warm{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.a-bar .btn-warm:hover{background:var(--a-acc-d);}
.a-bar button:disabled{opacity:.45;cursor:default;}
.a-role{margin-left:auto;font-size:13px;color:var(--a-ink);background:var(--a-ok);
  border-radius:12px;padding:5px 12px;white-space:nowrap;}
.a-role .fa-question-circle{cursor:pointer;color:#b5762a;margin-left:5px;}
#partySel{min-width:260px;}

.a-stat{display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:8px;
  border:1.5px solid var(--a-line);border-radius:8px;padding:9px 14px;background:var(--a-bg2);}
.a-stat .n{font-size:19px;font-weight:bold;color:var(--a-brand);}
.a-stat .n.big{font-size:23px;}
.a-stat .n.bad{color:var(--a-bad);}
.a-stat .n.good{color:#5C7A2E;}
.a-stat .l{font-size:12px;color:var(--a-ink2);}
.a-stat .sep{width:1px;height:30px;background:var(--a-line);}
.a-stat input{height:30px;width:130px;text-align:right;border:1px solid var(--a-line2);
  border-radius:4px;font-size:14px;padding:0 7px;color:var(--a-ink);}
.st-pill{display:inline-block;padding:2px 11px;border-radius:11px;font-size:12px;font-weight:bold;}
.stp-new{background:#EFE6D6;color:#8a6d45;}
.stp-draft{background:var(--a-acc);color:#fff;}
.stp-confirmed{background:#5C7A2E;color:#fff;}
.stp-reopened{background:var(--a-bad);color:#fff;}

.a-wrap{overflow-x:auto;border:1px solid var(--a-line);border-radius:6px;background:#fff;}
table.a-t{width:100%;border-collapse:collapse;font-size:13px;}
table.a-t th,table.a-t td{border:1px solid #EADFC8;padding:3px 6px;white-space:nowrap;text-align:center;}
table.a-t thead th{position:sticky;top:0;z-index:2;background:var(--a-ok);color:var(--a-ink);font-weight:bold;}
table.a-t td.l{text-align:left;}
table.a-t td.r{text-align:right;}
table.a-t tbody tr:nth-child(even){background:#FFFCF6;}
/* 已對到本筆＝暖淺綠 */
table.a-t tbody tr.hit{background:var(--a-hit) !important;}
table.a-t tbody tr.hit td{border-color:var(--a-hit-bd);}
table.a-t tbody tr.sel{outline:2px solid var(--a-acc);outline-offset:-2px;}
table.a-t tbody tr.grp{border-left:4px solid var(--a-acc);}
table.a-t tbody tr.child{background:#FBF3E6;}
table.a-t tbody tr.child td:first-child{padding-left:22px;}
table.a-t tbody tr.parent{color:#a08a6a;font-style:italic;}
table.a-t tbody tr.dragover{border-top:3px solid var(--a-acc);}
table.a-t tbody tr.dragging{opacity:.4;}
.drag-h{cursor:grab;color:var(--a-ink2);user-select:none;}
.drag-h:active{cursor:grabbing;}

.cell-in{width:78px;height:24px;text-align:right;border:1px solid transparent;border-radius:3px;
  font-size:12.5px;padding:0 4px;color:var(--a-ink);background:transparent;}
.cell-in:hover{border-color:var(--a-line2);background:#fff;}
.cell-in:focus{border-color:var(--a-acc);background:#FFFBF3;outline:none;}
.cell-in.wide{width:130px;text-align:left;}
.cell-in.mth{width:112px;text-align:center;}
.cell-in.adj{background:#FFF3DF;border-color:var(--a-line2);font-weight:bold;}
.orig-v{font-size:11px;color:#a08a6a;}

.pill{display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;line-height:16px;}
.p-g{background:var(--a-acc);color:#fff;}
.p-s{background:#5C7A2E;color:#fff;}
.p-lock{background:#C9B69F;color:#fff;}
.p-inv{background:#8A5A2B;color:#fff;}
.btn-mini{height:23px;padding:0 7px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);font-size:11.5px;}
.btn-mini:hover{background:var(--a-ok);}

.dock{position:fixed;left:0;right:0;bottom:0;z-index:900;background:var(--a-brand);color:#fff;
  padding:9px 18px;display:none;align-items:center;gap:16px;box-shadow:0 -2px 10px rgba(0,0,0,.22);}
.dock b{font-size:17px;}
.dock .sp{margin-left:auto;display:flex;gap:7px;}
.dock button{height:33px;padding:0 15px;border-radius:5px;border:1px solid #fff;
  background:transparent;color:#fff;font-size:13.5px;cursor:pointer;}
.dock button.go{background:var(--a-acc);border-color:var(--a-acc-d);font-weight:bold;}
.dock button.ok{background:#5C7A2E;border-color:#4A6325;font-weight:bold;}
.dock button:hover{background:rgba(255,255,255,.16);}
.dock button.go:hover{background:var(--a-acc-d);}
.dock button:disabled{opacity:.45;cursor:default;}

/* 計算機 */
.calc{position:fixed;right:16px;bottom:70px;z-index:950;width:212px;background:#fff;
  border:2px solid var(--a-acc);border-radius:10px;box-shadow:0 4px 18px rgba(0,0,0,.25);display:none;}
.calc.show{display:block;}
.calc .c-head{background:var(--a-acc);color:#fff;padding:5px 10px;font-size:13px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;cursor:move;}
.calc .c-head .x{margin-left:auto;cursor:pointer;}
.calc .c-scr{padding:6px 9px;text-align:right;font-size:20px;font-weight:bold;color:var(--a-ink);
  background:#FFFBF3;border-bottom:1px solid var(--a-line);min-height:34px;word-break:break-all;}
.calc .c-exp{padding:2px 9px;text-align:right;font-size:11px;color:var(--a-ink2);min-height:16px;
  background:#FFFBF3;word-break:break-all;}
.calc .c-pad{display:grid;grid-template-columns:repeat(4,1fr);gap:3px;padding:6px;}
.calc .c-pad button{height:31px;border:1px solid var(--a-line2);border-radius:4px;background:#fff;
  font-size:14px;cursor:pointer;color:var(--a-ink);}
.calc .c-pad button:hover{background:var(--a-ok);}
.calc .c-pad button.op{background:var(--a-bg2);}
.calc .c-pad button.eq{background:var(--a-acc);color:#fff;font-weight:bold;}
.calc .c-tip{padding:0 9px 7px;font-size:10.5px;color:var(--a-ink2);line-height:1.5;}

.a-mask{display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;
  background:rgba(60,40,20,.45);z-index:9999;overflow:auto;padding:34px 12px;}
.a-mask.show{display:block;}
.a-modal{background:#fff;border-radius:8px;width:820px;max-width:100%;margin:0 auto;
  box-shadow:0 6px 30px rgba(0,0,0,.3);}
.a-modal.narrow{width:600px;}
.a-modal .m-head{background:var(--a-ok);color:var(--a-ink);padding:9px 14px;font-weight:bold;
  border-radius:8px 8px 0 0;display:flex;align-items:center;}
.a-modal .m-close{margin-left:auto;cursor:pointer;font-size:17px;}
.a-modal .m-body{padding:14px;max-height:68vh;overflow:auto;}
.a-modal .m-foot{padding:10px 14px;border-top:1px solid var(--a-line);text-align:right;}
.a-modal .m-foot button{height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:4px;
  background:#fff;cursor:pointer;color:var(--a-ink);margin-left:5px;}
.a-modal .m-foot button.go{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}

.info{background:var(--a-bg2);border-left:5px solid var(--a-acc);color:var(--a-ink);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;line-height:1.6;}
.warn{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}
.okbox{background:#EEF4E2;color:#3F5520;border-left:5px solid #5C7A2E;
  padding:8px 12px;border-radius:4px;font-size:13px;margin-bottom:8px;}

.a-msg{position:fixed;top:64px;right:18px;z-index:12000;min-width:250px;max-width:440px;
  padding:11px 15px;border-radius:6px;display:none;font-size:14px;box-shadow:0 3px 12px rgba(0,0,0,.2);}
.a-msg.ok{background:#F7E0BD;color:#5b3a1e;border-left:5px solid var(--a-acc);}
.a-msg.bad{background:#FBE3DC;color:#7a2c17;border-left:5px solid var(--a-bad);}
.a-noperm{border:1.5px solid var(--a-line);background:var(--a-bg);border-radius:8px;padding:26px;color:var(--a-ink);}
.a-hint{font-size:11.5px;color:var(--a-ink2);margin-top:5px;line-height:1.6;}
kbd{background:#f4e6ce;border:1px solid var(--a-line2);border-bottom-width:2px;border-radius:3px;
  padding:0 5px;font-size:11px;color:var(--a-ink);font-family:inherit;}

/* ── 料號即時搜尋（打字→出清單→點選→跳到那一列並打亮）─────────────── */
.find-wrap{position:relative;display:inline-block;}
.find-pop{position:absolute;top:34px;left:0;z-index:1200;width:440px;max-height:300px;overflow:auto;
  background:#fff;border:1.5px solid var(--a-acc);border-radius:6px;
  box-shadow:0 5px 18px rgba(0,0,0,.22);display:none;}
.find-pop.show{display:block;}
.find-pop .fi{padding:5px 9px;border-bottom:1px solid #F1E6D2;cursor:pointer;font-size:12.5px;color:var(--a-ink);}
.find-pop .fi:hover,.find-pop .fi.on{background:var(--a-ok);}
.find-pop .fi b{color:var(--a-brand);}
.find-pop .fi .sub{color:var(--a-ink2);font-size:11px;}
.find-pop .fi-none{padding:10px;color:var(--a-ink2);font-size:12.5px;}
table.a-t tbody tr.flash td{animation:egflash 1.8s ease-out 1;}
@keyframes egflash{0%{background:#F0A24B;}100%{background:transparent;}}

/* ── 開關（拖移排序／依勾選順序排序，都預設關閉，管理員可改預設）───── */
.sw{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 11px;cursor:pointer;
  border:1px solid var(--a-line2);border-radius:16px;background:#fff;font-size:12.5px;color:var(--a-ink2);}
.sw .dot{width:11px;height:11px;border-radius:50%;background:#CFC3AE;}
.sw.on{background:var(--a-acc);color:#fff;border-color:var(--a-acc-d);font-weight:bold;}
.sw.on .dot{background:#fff;}

/* 單據類別徽章（出貨／退貨／加工費／採購） */
.k-ship{background:#F7E0BD;color:#5b3a1e;}
.k-ret{background:#DD5138;color:#fff;}
.k-proc{background:#A2703A;color:#fff;}
.k-split{background:#C9B69F;color:#fff;}

/* ── 右側參考面板：這一列的報價與訂單（拖移關閉時點列開啟）─────────── */
.ref-dock{position:fixed;top:0;right:0;bottom:56px;width:430px;z-index:880;background:#fff;
  border-left:3px solid var(--a-acc);box-shadow:-3px 0 16px rgba(0,0,0,.2);display:none;flex-direction:column;}
/* class 名稱刻意不叫 .show：Bootstrap 版型自帶 .show{display:block !important}，
   會把這裡的 display:flex 蓋掉，.r-body 的 flex:1 就失效、面板永遠捲不動（2026-08-28 使用者回報） */
.ref-dock.ref-on{display:flex !important;}
.ref-dock .r-body{min-height:0;}
body.ref-open .right_col{padding-right:442px;}
.ref-dock .r-head{background:var(--a-acc);color:#fff;padding:8px 12px;font-weight:bold;font-size:14px;
  display:flex;align-items:center;gap:8px;flex:0 0 auto;}
.ref-dock .r-head .x{margin-left:auto;cursor:pointer;font-size:17px;}
.ref-dock .r-body{flex:1 1 auto;overflow:auto;padding:10px 12px;font-size:12.5px;color:var(--a-ink);}
.ref-sec{margin-bottom:11px;}
.ref-sec h5{margin:0 0 5px;font-size:13px;color:var(--a-brand);font-weight:bold;
  border-bottom:1px solid var(--a-line);padding-bottom:3px;}
table.ref-t{width:100%;border-collapse:collapse;font-size:12px;}
table.ref-t th,table.ref-t td{border:1px solid #EADFC8;padding:2px 5px;}
table.ref-t th{background:var(--a-bg2);color:var(--a-ink2);white-space:nowrap;width:78px;text-align:left;}
.chk{display:flex;gap:6px;align-items:flex-start;padding:5px 7px;border-radius:4px;margin-bottom:4px;line-height:1.55;}
.chk .cl{font-weight:bold;white-space:nowrap;}
.chk-ok{background:#EEF4E2;color:#3F5520;border-left:4px solid #5C7A2E;}
.chk-warn{background:#FFF3DF;color:#7a5320;border-left:4px solid var(--a-acc);}
.chk-bad{background:#FBE3DC;color:#7a2c17;border-left:4px solid var(--a-bad);}
.chk-na{background:#F3ECE0;color:#7d6242;border-left:4px solid #C9B69F;}
.cand{max-height:210px;overflow:auto;border:1px solid var(--a-line);border-radius:4px;}
.cand table{width:100%;border-collapse:collapse;font-size:11.5px;}
.cand th,.cand td{border-bottom:1px solid #F1E6D2;padding:3px 5px;text-align:center;white-space:nowrap;}
.cand th{background:var(--a-ok);position:sticky;top:0;}
.cand tr.now{background:var(--a-hit);}

/* ── 單價對照大字帶：面板一打開最上面就看得到要核對的單價，不用整片讀 ────── */
.pcmp{border-radius:6px;padding:9px 10px 8px;margin-bottom:11px;border:2px solid;}
.pcmp .pc-hd{font-size:12px;font-weight:bold;display:flex;align-items:center;gap:7px;margin-bottom:6px;}
.pcmp .pc-tag{font-size:11.5px;padding:1px 9px;border-radius:10px;color:#fff;}
.pcmp .pc-row{display:flex;align-items:flex-end;gap:6px;}
.pcmp .pc-cell{flex:1 1 0;min-width:0;text-align:center;background:rgba(255,255,255,.72);
  border-radius:5px;padding:4px 3px 5px;}
.pcmp .pc-l{display:block;font-size:11px;color:#7d6242;white-space:nowrap;}
.pcmp .pc-v{display:block;font-size:22px;font-weight:bold;line-height:1.2;
  word-break:break-all;letter-spacing:-.3px;}
.pcmp .pc-v.na{font-size:13px;color:#a08a6a;font-weight:normal;padding:5px 0;}
.pcmp .pc-op{font-size:12px;color:#a08a6a;padding-bottom:9px;}
.pcmp .pc-note{font-size:12px;margin-top:6px;line-height:1.55;}
.pcmp-ok{border-color:#5C7A2E;background:#EEF4E2;}
.pcmp-ok .pc-hd,.pcmp-ok .pc-note{color:#3F5520;} .pcmp-ok .pc-tag{background:#5C7A2E;}
.pcmp-ok .pc-v{color:#3F5520;}
.pcmp-warn{border-color:var(--a-acc);background:#FFF3DF;}
.pcmp-warn .pc-hd,.pcmp-warn .pc-note{color:#7a5320;} .pcmp-warn .pc-tag{background:var(--a-acc);}
.pcmp-warn .pc-v{color:#8a5320;}
.pcmp-bad{border-color:var(--a-bad);background:#FBE3DC;}
.pcmp-bad .pc-hd,.pcmp-bad .pc-note{color:#7a2c17;} .pcmp-bad .pc-tag{background:var(--a-bad);}
.pcmp-bad .pc-v{color:var(--a-bad);}
.pcmp-na{border-color:#C9B69F;background:#F3ECE0;}
.pcmp-na .pc-hd,.pcmp-na .pc-note{color:#7d6242;} .pcmp-na .pc-tag{background:#C9B69F;}
.pcmp-na .pc-v{color:#5b3a1e;}
/* 面板標題列也把出貨單價帶上，捲到下面時仍看得到 */
.ref-dock .r-head .hd-price{margin-left:auto;font-size:15px;font-weight:bold;white-space:nowrap;}
.ref-dock .r-head .x{margin-left:10px;}

/* 跨月找單／設定跳窗內的表格 */
table.ot{width:100%;border-collapse:collapse;font-size:12.5px;}
table.ot th,table.ot td{border:1px solid #EADFC8;padding:3px 6px;text-align:center;white-space:nowrap;}
table.ot th{background:var(--a-ok);color:var(--a-ink);position:sticky;top:0;}
table.ot td.l{text-align:left;}
table.ot td.r{text-align:right;}
table.ot tr.pick{background:var(--a-hit);}
table.ot tr.lock{color:#a08a6a;}
.page-help-btn{margin-left:auto;height:32px;padding:0 14px;border:1px solid var(--a-line2);border-radius:16px;
  background:#fff;color:var(--a-ink);cursor:pointer;font-size:13px;}
.page-help-btn:hover{background:var(--a-ok);}
.help-doc{font-size:13.5px;color:#5b3a1e;line-height:1.9;}
.help-doc h4{font-size:15px;color:var(--a-brand);margin:14px 0 5px;font-weight:bold;}
.help-doc h4:first-child{margin-top:0;}
.help-doc ul{padding-left:20px;margin:0 0 6px;}

@media print{
  .a-bar,.dock,.calc,.a-mask,.side-band .sb-sw,.nav_menu,.left_col,footer{display:none !important;}
  .right_col{margin:0 !important;padding:0 !important;}
  table.a-t thead th{position:static;}
}
</style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
  <?php include '../partPage/sideAndTopBarMenu.html' ?>
  <div class="right_col" role="main">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
      <h2 style="margin:6px 0;"><i class="fa fa-check-square-o" style="color:#F0A24B;"></i> 對帳作業
        <small style="color:#8a6d45;">點列即勾選、可查報價／訂單、可跨月帶單、可加總／拆分、可暫存續對；確認正確即鎖帳交會計</small></h2>
      <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
    <div class="a-noperm">
      <h4><i class="fa fa-lock"></i> 無對帳權限</h4>
      <p>請洽管理者於「使用者權限設定」指派「應收對帳(業務)」、「應付對帳(生管)」或會計相關角色。</p>
    </div>
<?php else: ?>
    <div class="side-band">
      <i class="fa" id="sideIcon"></i>
      <b id="sideName">應收帳款</b><span class="sb-sub" id="sideSub">錢進來 · 向客戶收款</span>
      <span class="sb-sw">
        <button id="btnSideAr" class="on">應收（客戶）</button>
        <button id="btnSideAp">應付（廠商）</button>
      </span>
    </div>

    <div class="a-bar">
      <label id="bmLbl" style="cursor:help;">帳款月份 <i class="fa fa-info-circle" style="color:#b5762a;"></i></label>
      <input type="month" id="bm" style="width:145px;">
      <label id="partyLbl">客戶</label>
      <select id="partySel" data-eg-filter="輸入客戶編號或名稱篩選…"><option value="">請先選帳款月份</option></select>
      <button id="btnLoad" class="btn-warm"><i class="fa fa-folder-open-o"></i> 載入對帳單</button>
      <button id="btnCalc"><i class="fa fa-calculator"></i> 計算機</button>
      <span class="a-role">目前角色：<b><?= htmlspecialchars($roleLbl) ?></b>
        <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
    </div>

    <div id="sheetBox" style="display:none;">
      <div class="a-stat">
        <div><span class="st-pill" id="stStatus">—</span></div>
        <div class="sep"></div>
        <div><span class="n" id="stLines">—</span> <span class="l">列</span></div>
        <div><span class="n good" id="stChecked">—</span> <span class="l">已對到</span></div>
        <div class="sep"></div>
        <div><span class="n big" id="stOur">—</span> <span class="l">我方未稅</span></div>
        <div>
          <span class="l">對方紙本合計</span><br>
          <input type="number" id="theirTotal" step="0.01" placeholder="輸入紙本金額">
        </div>
        <div><span class="n big" id="stDiff">—</span> <span class="l">差額</span></div>
        <div class="sep"></div>
        <div><span class="n" id="stTax">—</span> <span class="l">稅額</span></div>
        <div><span class="n" id="stTotal">—</span> <span class="l">含稅</span></div>
      </div>
      <div id="noteBox"></div>
      <div id="syncBox"></div>

      <div class="a-bar" style="background:var(--a-bg2);">
        <span class="find-wrap">
          <input type="text" id="findKw" style="width:200px;" autocomplete="off"
                 placeholder="搜尋料號／單號（即時）" title="打字即時模糊比對料號或單號，點清單那一筆會跳到對帳單上的該列並打亮">
          <div class="find-pop" id="findPop"></div>
        </span>
        <button id="btnOutside" title="交接日期前後的單，對方常做在前一個或後一個月份的帳裡"><i class="fa fa-calendar-o"></i> 跨月找單／帶入本月</button>
        <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
        <button id="btnGroup"><i class="fa fa-object-group"></i> 合併為一組（多項加總）</button>
        <button id="btnUngroup"><i class="fa fa-object-ungroup"></i> 取消分組</button>
        <button id="btnSplit"><i class="fa fa-cut"></i> 拆分此列</button>
        <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
        <button id="btnCheckAll"><i class="fa fa-check-square-o"></i> 全部標已對到</button>
        <button id="btnUncheckAll"><i class="fa fa-square-o"></i> 全部取消</button>
        <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
        <button id="btnExport"><i class="fa fa-file-text-o"></i> 匯出底稿</button>
        <button id="btnPrint"><i class="fa fa-print"></i> 列印對帳單</button>
      </div>

      <div class="a-bar" style="background:var(--a-bg);">
        <span class="sw" id="swDrag" title="開啟後才能拖曳左側把手調整順序；關閉時點列＝查看該列的報價與訂單"><span class="dot"></span> 拖移排序</span>
        <span class="sw" id="swAuto" title="開啟後每勾選一列就把它排到已勾選那一區的最後，順序＝你勾選的順序，方便照著對方紙本一路對下來"><span class="dot"></span> 依勾選順序排序</span>
        <span style="width:1px;height:22px;background:var(--a-line);margin:0 4px;"></span>
        <button id="btnPartyOpt"><i class="fa fa-address-card-o"></i> 對象設定</button>
        <button id="btnReconSet" style="display:none;"><i class="fa fa-cog"></i> 模組設定</button>
        <span class="a-hint" style="margin-left:6px;" id="modeHint"></span>
      </div>

      <div class="a-wrap">
        <table class="a-t" id="tbl">
          <thead><tr>
            <th style="width:26px;"></th>
            <th style="width:30px;">✓</th>
            <th>單號</th><th>日期</th><th style="width:52px;">類別</th>
            <th class="ap-only">製令</th>
            <th>料號</th><th>說明</th>
            <th>原始數量</th><th>原始單價</th><th>原始金額</th>
            <th style="background:#F0CFA0;">調整數量</th>
            <th style="background:#F0CFA0;">調整單價</th>
            <th style="background:#F0CFA0;">調整金額</th>
            <th style="background:#F0CFA0;">指定月份</th>
            <th>組／拆</th><th>備註</th><th>操作</th>
          </tr></thead>
          <tbody id="tbody"></tbody>
          <tfoot id="tfoot"></tfoot>
        </table>
      </div>
      <div class="a-hint">
        「調整」欄留空＝沿用原始值。<b>加總與拆分只影響這份對帳底稿，不會改到原始出貨／加工紀錄</b>——
        原始值永久保留在左邊供會計與稽核比對。拆分出來的各段金額合計必須等於原列金額，否則存不進去。
        有子列的父列會標為<span class="pill p-s">拆分來源</span>且不計入合計（金額由子列代表）。
      </div>
    </div>

    <div id="emptyBox" class="info" style="display:block;">
      選擇「帳款月份」與對象後按<b>載入對帳單</b>。若之前有暫存，會自動接續上次的進度；
      沒有的話就依該月份的出貨／加工紀錄即時組出一份新的。
    </div>
<?php endif; ?>
  </div>
  <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 底部動作條 -->
<div class="dock" id="dock">
  <span>已對到 <b id="dkChecked">0</b> / <span id="dkLines">0</span> 列</span>
  <span>我方 <b id="dkOur">0</b></span>
  <span id="dkDiff"></span>
  <span class="sp">
    <button id="btnSave" class="go"><i class="fa fa-save"></i> 暫存進度</button>
    <button id="btnConfirm" class="ok"><i class="fa fa-check"></i> 確認正確（鎖帳）</button>
    <button id="btnReopen" style="display:none;"><i class="fa fa-undo"></i> 退回重對</button>
  </span>
</div>

<!-- 計算機 -->
<div class="calc" id="calc">
  <div class="c-head" id="calcHead"><i class="fa fa-calculator"></i>&nbsp;計算機<span class="x" id="calcX">✕</span></div>
  <div class="c-exp" id="calcExp"></div>
  <div class="c-scr" id="calcScr">0</div>
  <div class="c-pad">
    <button data-c="7">7</button><button data-c="8">8</button><button data-c="9">9</button><button class="op" data-c="/">÷</button>
    <button data-c="4">4</button><button data-c="5">5</button><button data-c="6">6</button><button class="op" data-c="*">×</button>
    <button data-c="1">1</button><button data-c="2">2</button><button data-c="3">3</button><button class="op" data-c="-">−</button>
    <button data-c="0">0</button><button data-c=".">.</button><button class="op" data-c="%">%</button><button class="op" data-c="+">＋</button>
    <button data-c="C">C</button><button data-c="BS">⌫</button><button data-c="SUM" title="把已勾選列的金額加總帶進來">Σ勾選</button>
    <button class="eq" data-c="=">＝</button>
  </div>
  <div class="c-tip">Σ勾選＝把目前已對到的列金額加總帶入。% 為除以 100。</div>
</div>

<!-- 拆分 -->
<div class="a-mask" id="mkSplit"><div class="a-modal">
  <div class="m-head"><i class="fa fa-cut"></i>&nbsp;拆分此列<span class="m-close" data-close="mkSplit">✕</span></div>
  <div class="m-body">
    <div class="info" id="spInfo"></div>
    <div class="a-bar">
      <label>拆成幾段</label>
      <input type="number" id="spN" min="2" max="12" value="2" style="width:80px;">
      <button id="spGen" class="btn-warm">產生欄位（平均分配）</button>
      <span class="a-hint" style="margin-left:6px;">用於「我方一次送、廠商拆批或拆月份請款」</span>
    </div>
    <div id="spBox"></div>
  </div>
  <div class="m-foot">
    <span id="spTally" style="float:left;font-size:13px;color:var(--a-ink);line-height:32px;"></span>
    <button data-close="mkSplit">取消</button>
    <button class="go" id="spOk"><i class="fa fa-check"></i> 套用拆分</button>
  </div>
</div></div>

<!-- 退回重對 -->
<div class="a-mask" id="mkReopen"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-undo"></i>&nbsp;退回重對<span class="m-close" data-close="mkReopen">✕</span></div>
  <div class="m-body">
    <div class="warn">退回後對帳人員可再修改此對帳單涵蓋的單據。此動作會記錄退回人、時間與原因。</div>
    <label style="font-size:13px;color:var(--a-ink);">退回原因（必填）</label>
    <input type="text" id="roReason" maxlength="300" style="width:100%;height:32px;
      border:1px solid var(--a-line2);border-radius:4px;padding:0 8px;color:var(--a-ink);">
  </div>
  <div class="m-foot">
    <button data-close="mkReopen">取消</button>
    <button class="go" id="roOk"><i class="fa fa-undo"></i> 確認退回</button>
  </div>
</div></div>

<!-- 角色說明 -->
<div class="a-mask" id="mkRole"><div class="a-modal narrow">
  <div class="m-head">角色權限說明<span class="m-close" data-close="mkRole">✕</span></div>
  <div class="m-body" style="font-size:13.5px;color:#5b3a1e;line-height:1.9;">
    <b>管理者／會計管理員</b>：兩側都可對帳，另可<b>退回已鎖帳的對帳單</b>。<br>
    <b>會計登錄</b>：兩側都可對帳。<br>
    <b>應收對帳(業務)</b>：只能對<b>應收</b>（客戶／出貨退貨），不可碰應付。<br>
    <b>應付對帳(生管)</b>：只能對<b>應付</b>（廠商／加工費），不可碰應收。<br>
    <b>會計檢閱</b>：只能看，不能改也不能確認。<br>
    <span style="color:#8a6d45;font-size:12.5px;">
      鎖帳規則：按「確認正確」即鎖帳，之後不可改單也不可再暫存，僅會計管理員可退回。<br>
      已開立發票的憑證另有更硬的鎖——不提供解鎖，只能作廢／折讓／補開（帳面須與國稅局申報一致）。<br>
      角色於「使用者權限設定」頁指派（模組 accounting）。</span>
  </div>
</div></div>

<!-- 這一列的報價／訂單對照（拖移關閉時點列開啟） -->
<div class="ref-dock" id="refDock">
  <div class="r-head"><i class="fa fa-link"></i>&nbsp;<span id="refTitle">報價／訂單對照</span>
    <span class="hd-price" id="refHeadPrice"></span><span class="x" id="refX">✕</span></div>
  <div class="r-body" id="refBody"></div>
</div>

<!-- 跨月找單：交接日期前後的單常被做到前一個或後一個月份 -->
<div class="a-mask" id="mkOutside"><div class="a-modal" style="width:960px;">
  <div class="m-head"><i class="fa fa-calendar-o"></i>&nbsp;跨月找單／帶入本月<span class="m-close" data-close="mkOutside">✕</span></div>
  <div class="m-body">
    <div class="info">
      對帳常遇到<b>交接日期前後的單</b>：對方把它算在前一個或後一個月份。這裡列出<b>這個對象在本月份以外</b>的出貨／退貨（應付則是加工移轉），
      勾選後按下方按鈕即可<b>改成目前這份對帳單的月份</b>並帶進來。<br>
      改的是該單據的「帳款月份指定值」，<b>不會動到出貨日期或金額</b>；<b>已開立發票的單據不可調整</b>（會與國稅局申報對不起來），清單上會標示。
    </div>
    <div class="a-bar">
      <label>料號／單號</label>
      <input type="text" id="otKw" style="width:190px;" placeholder="模糊比對，可留空">
      <label>搜尋範圍</label>
      <select id="otSpan" style="width:150px;">
        <option value="1">前後 1 個月</option>
        <option value="2">前後 2 個月</option>
        <option value="3" selected>前後 3 個月</option>
        <option value="6">前後 6 個月</option>
        <option value="12">前後 12 個月</option>
      </select>
      <button id="otGo" class="btn-warm"><i class="fa fa-search"></i> 搜尋</button>
      <button id="otAll"><i class="fa fa-check-square-o"></i> 全選／全不選</button>
      <span class="a-hint" id="otRange" style="margin-left:6px;"></span>
    </div>
    <div style="max-height:46vh;overflow:auto;border:1px solid var(--a-line);border-radius:4px;">
      <table class="ot" id="otTbl"><thead><tr>
        <th style="width:34px;">選</th><th>單號</th><th>日期</th><th>類別</th><th>料號</th><th>品名／製令</th>
        <th>數量</th><th>單價</th><th>金額</th><th>目前帳款月份</th><th>狀態</th>
      </tr></thead><tbody id="otBody"><tr><td colspan="11" style="padding:16px;color:#8a6d45;">按「搜尋」開始</td></tr></tbody></table>
    </div>
  </div>
  <div class="m-foot">
    <span id="otTally" style="float:left;font-size:13px;color:var(--a-ink);line-height:32px;"></span>
    <button data-close="mkOutside">關閉</button>
    <button class="go" id="otApply"><i class="fa fa-arrow-down"></i> 改成本月份並帶入</button>
  </div>
</div></div>

<!-- 對象設定：部分客戶不提供對帳單 -->
<div class="a-mask" id="mkParty"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-address-card-o"></i>&nbsp;對象設定<span class="m-close" data-close="mkParty">✕</span></div>
  <div class="m-body">
    <div class="info">
      有些客戶<b>不提供對帳單</b>，我方直接用開立的出貨單認列待收金額。
      勾選後這份對帳單就<b>不會再要求輸入對方紙本合計、也不顯示差額</b>，確認鎖帳時也不會提醒「尚未輸入對方紙本合計」。
    </div>
    <div style="font-size:13px;color:var(--a-ink);margin-bottom:8px;">對象：<b id="poName">—</b></div>
    <label style="font-size:13.5px;color:var(--a-ink);display:flex;align-items:center;gap:7px;font-weight:normal;">
      <input type="checkbox" id="poNo" style="width:16px;height:16px;">
      這個對象<b>不提供對帳單</b>（以我方出貨單認列待收金額）
    </label>
    <div style="margin-top:9px;">
      <label style="font-size:13px;color:var(--a-ink);">備註（選填）</label>
      <input type="text" id="poNote" maxlength="200" style="width:100%;height:32px;border:1px solid var(--a-line2);
        border-radius:4px;padding:0 8px;color:var(--a-ink);" placeholder="例：客戶只認我方出貨單，月底寄對帳明細即可">
    </div>
    <div class="a-hint" id="poWho"></div>
  </div>
  <div class="m-foot">
    <button data-close="mkParty">取消</button>
    <button class="go" id="poOk"><i class="fa fa-save"></i> 儲存</button>
  </div>
</div></div>

<!-- 模組設定（僅會計管理員）：預設開關與 AS 文件綁定 -->
<div class="a-mask" id="mkSet"><div class="a-modal narrow">
  <div class="m-head"><i class="fa fa-cog"></i>&nbsp;對帳作業模組設定<span class="m-close" data-close="mkSet">✕</span></div>
  <div class="m-body">
    <div class="info">這裡設的是<b>全站預設值</b>；每位使用者仍可在畫面上自行切換，切換結果只記在自己的瀏覽器。</div>
    <label style="font-size:13.5px;color:var(--a-ink);display:flex;align-items:center;gap:7px;font-weight:normal;">
      <input type="checkbox" id="stDrag" style="width:16px;height:16px;"> 預設開啟「拖移排序」
    </label>
    <label style="font-size:13.5px;color:var(--a-ink);display:flex;align-items:center;gap:7px;font-weight:normal;margin-top:6px;">
      <input type="checkbox" id="stAuto" style="width:16px;height:16px;"> 預設開啟「依勾選順序排序」
    </label>
    <hr style="border-color:var(--a-line);">
    <div style="font-size:13px;color:var(--a-ink);">列印用的 AS 文件編號綁定（頁尾右下角）</div>
    <div style="margin:6px 0;">
      <span id="asdocShow" style="font-size:13.5px;color:var(--a-brand);font-weight:bold;">未綁定</span>
      <button class="btn-mini" id="btnAsPick" style="margin-left:8px;">選擇文件</button>
      <button class="btn-mini" id="btnAsClear">清除綁定</button>
    </div>
    <div class="a-hint">綁定後列印的對帳單表頭會用該文件名稱、頁尾右下角印出文件編號（四階文件自動附版次，並依帳款月份回推當時版次）。</div>
  </div>
  <div class="m-foot">
    <button data-close="mkSet">取消</button>
    <button class="go" id="setOk"><i class="fa fa-save"></i> 儲存預設值</button>
  </div>
</div></div>

<!-- 使用說明（鐵律7） -->
<div class="a-mask" id="helpUseMask"><div class="a-modal">
  <div class="m-head"><i class="fa fa-question-circle"></i>&nbsp;對帳作業　使用說明<span class="m-close" data-close="helpUseMask">✕</span></div>
  <div class="m-body help-doc">
    <h4>這一頁在做什麼</h4>
    把某個客戶（應收）或廠商（應付）某個帳款月份的單據排成一份<b>對帳底稿</b>，跟對方拿來的紙本一列一列對。
    對完按「確認正確」即<b>鎖帳</b>交會計開票／付款。加總、拆分、排序、勾選<b>只影響這份底稿</b>，永遠不會改到原始出貨／加工紀錄。

    <h4>操作步驟</h4>
    <ul>
      <li>選<b>帳款月份</b>與<b>對象</b>（對象下拉可直接打客戶編號或名稱篩選）→ 按「載入對帳單」。之前有暫存會自動接續。</li>
      <li><b>點任一列</b>＝標記「已對到」（轉暖淺綠）；同時右側會開出<b>這一列的報價與訂單對照</b>。</li>
      <li>對方把我方多筆併成一列請款 → 勾選那幾列按<b>合併為一組</b>，表尾會顯示該組小計。</li>
      <li>對方把我方一筆拆開請款（甚至拆到不同月份）→ 選那一列按<b>拆分此列</b>，各段合計必須等於原列金額。</li>
      <li>對方紙本上的單在別的月份 → 按<b>跨月找單／帶入本月</b>，勾選後一鍵改成目前月份。</li>
      <li>把對方紙本合計填進上方欄位，差額自動算；對完按<b>確認正確（鎖帳）</b>。</li>
    </ul>

    <h4>兩個開關（預設都關閉）</h4>
    <ul>
      <li><b>拖移排序</b>：開啟後可拖左側 <i class="fa fa-bars"></i> 把手，把順序排成跟對方紙本一樣。<b>關閉時點列＝查看報價／訂單對照</b>。</li>
      <li><b>依勾選順序排序</b>：開啟後每勾選一列就自動排到已勾選那一區的最後，等於「照著對方紙本一路勾下來，順序就跟紙本一樣」。</li>
      <li>兩個開關的<b>預設值由會計管理員在「模組設定」設定</b>；個人切換結果記在自己的瀏覽器，不影響別人。</li>
    </ul>

    <h4>報價／訂單對照（點列後看右側）</h4>
    <ul>
      <li>單價鏈是<b>報價單 → 訂單 → 出貨單</b>，三段任一段對不上都會列出來（綠＝相符、橘＝要注意、紅＝不符）。</li>
      <li>出貨單沒綁訂單、或訂單沒綁報價時，下方會列出<b>同客戶同料號的候選</b>，可直接綁定；不綁也可以，就人工判斷單價。</li>
      <li><b>報價數量的比對是粗略的</b>：階梯報價用階梯區間（含該階容差）判定，非階梯報價用 ±10% 判定，僅供提醒，實際仍以業務判斷為準。</li>
      <li>應付（加工費）沒有報價單與客戶訂單可對照，只會檢查「數量×單價＝加工金額」與 ERP 原始單價是否被覆寫過。</li>
    </ul>

    <h4>退貨（出貨退回）</h4>
    應收的對帳單<b>本來就含退貨</b>，在「類別」欄標為<span class="pill k-ret">退貨</span>，數量與金額都是<b>負數</b>，直接扣減本月應收。
    備註性質的退貨（<code>ir_return_type.is_note=1</code>）不計金額，所以不會出現。

    <h4>不提供對帳單的客戶</h4>
    有些客戶不寄對帳單回來，我方直接用出貨單認列待收金額。按<b>對象設定</b>勾起來後，這個對象就不再顯示「對方紙本合計／差額」，
    確認鎖帳時也不會再提醒沒填紙本金額。

    <h4>常見疑問</h4>
    <ul>
      <li><b>載入時說「有 N 筆本月單據不在底稿中」？</b> 表示暫存之後又有新單據歸到這個月（常見於剛用跨月找單改過月份），按提示的按鈕即可加入。</li>
      <li><b>說「有 N 筆已不屬於本月份」？</b> 表示底稿裡的單被改到別的月份了，可一鍵移除，避免同一筆錢對兩次。</li>
      <li><b>鎖帳後要改？</b> 只有會計管理員能「退回重對」且必須填原因；已開發票的憑證另有更硬的鎖，只能作廢／折讓／補開。</li>
      <li><b>列印出來跟畫面不一樣？</b> 列印走的是正式對帳單版面（A4、公司全名、頁碼、AS 文件編號），不是把網頁直接印下來。</li>
    </ul>

    <h4>設定入口與權限</h4>
    <ul>
      <li><b>對象設定</b>（是否提供對帳單）：有該側對帳權限者即可設定。</li>
      <li><b>模組設定</b>（預設開關、AS 文件綁定）：僅<b>會計管理員</b>看得到。</li>
      <li>角色於「使用者權限設定」頁指派（模組 accounting）：應收對帳(業務)只能碰應收、應付對帳(生管)只能碰應付、會計檢閱只能看。</li>
    </ul>
  </div>
</div></div>

<div class="a-msg" id="msg"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?: time() ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?: time() ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?: time() ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?: time() ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?: time() ?>"></script>
<script>window.__ownCompany = <?= json_encode($ownCompany, JSON_UNESCAPED_UNICODE) ?>;</script>
<script>
/* 版型的 #sidebar-menu 預設 visibility:hidden，必須在此恢復，否則整個左側欄不會出現 */
$(document).ready(function () {
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) {
        $am.removeClass('active').find('ul.child_menu').hide();
        $am.find('li.current-page').removeClass('current-page');
    }
    $('#sidebar-menu').css('visibility', 'visible');
});

(function ($) {
'use strict';
var API = '../../src/store/Acc_API.php';
var TAX_RATE = <?= json_encode($taxRate) ?>;
var P = {
  canReconAr: <?= $perms['canReconAr'] ? 'true' : 'false' ?>,
  canReconAp: <?= $perms['canReconAp'] ? 'true' : 'false' ?>,
  canAdmin:   <?= $perms['canAdmin']   ? 'true' : 'false' ?>
};

var ME = null;
var CSRF = '', SIDE = 'ar', sheet = null, lines = [], selIdx = -1, keySeq = 0, canEdit = false;
/* 兩個開關：全站預設值由會計管理員設定，個人切換記在自己的 localStorage（不影響別人） */
var PREF = <?= json_encode($reconPref) ?>;
var dragOn = false, autoOn = false, checkSeq = 0;
var OPT = {no_statement:0, note:null};        // 這個對象的選項（是否提供對帳單）
var SETL = null;                              // 這個對象實際適用的結帳條件
var refSrc = null;                            // 目前在右側面板顯示的來源憑證
function prefKey(k){ return 'acc_recon_' + k; }
function loadSwitches(){
  var d = localStorage.getItem(prefKey('drag'));
  var a = localStorage.getItem(prefKey('auto'));
  dragOn = (d===null) ? !!PREF.drag_default     : (d==='1');
  autoOn = (a===null) ? !!PREF.autosort_default : (a==='1');
  paintSwitches();
}
function paintSwitches(){
  $('#swDrag').toggleClass('on', dragOn);
  $('#swAuto').toggleClass('on', autoOn);
  $('#modeHint').html(dragOn
    ? '拖移排序<b>開啟中</b>：拖左側 <i class="fa fa-bars"></i> 把手可調順序；此時點列只會標記已對到。'
    : '拖移排序關閉中：<b>點任一列＝標記已對到，同時右側顯示該列的報價／訂單對照</b>。');
}
function dispDate(d){ return (typeof egFmtDate==='function') ? egFmtDate(d) : (d||''); }
/* 從總覽頁帶進來的目標（沒帶就是空字串），對象清單載完後自動選起來並載入 */
var DEEP = <?= json_encode($deep, JSON_UNESCAPED_UNICODE) ?>;
var pendingParty = '';
var ST_LABEL = {'new':'尚未建立', draft:'暫存中', confirmed:'已確認鎖帳', reopened:'已退回重對'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function nf(n){ return Math.round(Number(n)||0).toLocaleString('en-US'); }
/* 價格顯示：小數點後全是 0 就整個不顯示（40.000000→40），有值就保留到有意義的那一位（40.0250000→40.025）。
   DB 的單價欄位最多到 6 位小數（quotation_item_tier.unit_price），所以取 6 位再去尾端 0。 */
function pnum(v){
  if(v===null || v===undefined || v==='') return '';
  var n=Number(v);
  if(isNaN(n)) return String(v);
  return String(parseFloat(n.toFixed(6)));
}
function n2(n){ return pnum(Number(n)||0); }
function toast(m,bad){
  var $m=$('#msg').removeClass('ok bad').addClass(bad?'bad':'ok').html(m).stop(true,true).fadeIn(150);
  clearTimeout($m.data('t')); $m.data('t',setTimeout(function(){ $m.fadeOut(400); },bad?7000:3600));
}
function openMask(id){ $('#'+id).addClass('show'); }
function closeMask(id){ $('#'+id).removeClass('show'); }
$(document).on('click','[data-close]',function(){ closeMask($(this).data('close')); });
$(document).on('click','.a-mask',function(e){ if(e.target===this) $(this).removeClass('show'); });
$('#btnRoleHelp').on('click',function(){ openMask('mkRole'); });
function nk(){ return 'k' + (++keySeq); }
function amtOf(l){
  return (l.adj_amount!==null && l.adj_amount!==undefined && l.adj_amount!=='')
    ? Number(l.adj_amount) : Number(l.orig_amount||0);
}
/* 有子列的父列不計入合計（金額由子列代表），與後端同一套規則 */
function parentKeys(){
  var s={};
  lines.forEach(function(l){ if(l.split_parent_key) s[l.split_parent_key]=1; });
  return s;
}

/* ══ 側別切換 ══ */
function setSide(s){
  SIDE = (s==='ap') ? 'ap' : 'ar';
  $('body').toggleClass('side-ap', SIDE==='ap');
  $('#btnSideAr').toggleClass('on', SIDE==='ar');
  $('#btnSideAp').toggleClass('on', SIDE==='ap');
  $('#sideIcon').attr('class','fa ' + (SIDE==='ap'?'fa-arrow-circle-up':'fa-arrow-circle-down'));
  $('#sideName').text(SIDE==='ap'?'應付帳款':'應收帳款');
  $('#sideSub').text(SIDE==='ap'?'錢出去 · 付給廠商':'錢進來 · 向客戶收款');
  $('#partyLbl').text(SIDE==='ap'?'廠商':'客戶');
  $('.ap-only').toggle(SIDE==='ap');
  sheet=null; lines=[]; $('#sheetBox').hide(); $('#emptyBox').show(); $('#dock').hide();
  applyDefaultBm();
  loadParties();
}
$('#btnSideAr').on('click',function(){
  if(!P.canReconAr && !P.canAdmin){ toast('你沒有應收對帳權限', true); return; }
  setSide('ar');
});
$('#btnSideAp').on('click',function(){
  if(!P.canReconAp && !P.canAdmin){ toast('你沒有應付對帳權限', true); return; }
  setSide('ap');
});

/* ══ 初始化 ══ */
/* 預設帳款月份＝依結帳日回推（廠商 20 日、客戶 25 日之類），不是一律上個月。
   使用者自己動過月份之後就不再自動改，否則切個側別就把他選的月份蓋掉。 */
var BM_DEF = <?= json_encode($bmDefault, JSON_UNESCAPED_UNICODE) ?>;
var bmTouched = false;
function applyDefaultBm(){
  if(bmTouched) return;                 // 已手動指定（或由總覽帶入）就整個不動，連提示都不要蓋掉
  var d = BM_DEF[SIDE] || null;
  if(!d || !d.billing_month) return;
  $('#bm').val(d.billing_month);
  bmWhy(d.reason||'');
}
/* 為什麼預設是這個月：只掛成 tooltip，不要佔工具列版面（使用者 2026-08-28 要求） */
function bmWhy(txt){ $('#bm,#bmLbl').attr('title', txt || ''); }
$('#bm').on('change', function(){ bmTouched = true; bmWhy('已手動指定月份（不再依結帳日自動切換）'); });
$.getJSON(API,{action:'meta'},function(r){
  if(!r.ok){ toast(esc(r.error||'初始化失敗'), true); return; }
  CSRF=r.csrf; ME=r.user||null;
  loadSwitches();
  $('#btnReconSet').toggle(!!P.canAdmin);
  if(DEEP.party_id){
    // 由總覽頁指定了要開哪一份：設好月份與側別，對象清單載完會自動接著載入
    $('#bm').val(DEEP.bm);
    bmTouched = true;
    bmWhy('由對帳單總覽指定的月份');
    pendingParty = DEEP.party_id;
    setSide(DEEP.side);
    return;
  }
  // 只有單側權限的人，直接切到他那一側
  if(!P.canReconAr && P.canReconAp) setSide('ap'); else setSide('ar');
}).fail(function(){ toast('無法連線到會計 API', true); });

function loadParties(){
  var bm=$('#bm').val();
  if(!bm){ $('#partySel').html('<option value="">請先選帳款月份</option>'); return; }
  $('#partySel').html('<option value="">載入中…</option>');
  $.post(API+'?action=recon_parties',{side:SIDE, bm:bm},function(r){
    if(!r.ok){ toast(esc(r.error||'查詢失敗'), true); return; }
    var h='<option value="">請選擇（共 '+r.parties.length+' 家）</option>';
    r.parties.forEach(function(p){
      var tag='';
      if(p.sheet) tag = ' ['+(ST_LABEL[p.sheet.status]||p.sheet.status)
        + (p.sheet.status==='draft' ? ' '+p.sheet.checked_cnt+'/'+p.sheet.line_cnt : '') + ']';
      // 選項文字要含「編號」，打字篩選（data-eg-filter）比對的就是這串顯示文字
      var pid = (p.customer_id || p.party_id || '');
      var idTxt = (pid && pid !== p.party_name) ? (' ' + pid) : '';
      h+='<option value="'+esc(p.party_id)+'">'+esc(p.party_name)+esc(idTxt)
        +'（'+p.cnt+' 筆 / '+nf(p.amount)+'）'+esc(tag)
        +(p.no_statement ? ' ［不提供對帳單］' : '')+'</option>';
    });
    $('#partySel').html(h);
    if(pendingParty){
      var want=pendingParty; pendingParty='';
      // 該對象若該月已無來源憑證（例如全數拆到別的月份）就不會在清單裡，
      // 補一個 option 進去，底稿仍然要能開起來看
      var found=false;
      $('#partySel option').each(function(){ if(this.value===want) found=true; });
      if(!found){
        $('#partySel').append('<option value="'+esc(want)+'">'+esc(want)+'（已有對帳底稿）</option>');
      }
      $('#partySel').val(want);
      if($('#partySel').val()===want) loadSheet();
      else toast('找不到指定的對象：'+esc(want), true);
    }
  },'json').fail(function(){ toast('查詢對象清單失敗', true); });
}
$('#bm').on('change', loadParties);

/* ══ 載入底稿 ══ */
$('#btnLoad').on('click', loadSheet);
$('#partySel').on('change',function(){ if($(this).val()) loadSheet(); });

function loadSheet(){
  var bm=$('#bm').val(), pid=$('#partySel').val();
  if(!bm || !pid){ toast('請選擇帳款月份與對象', true); return; }
  $.post(API+'?action=sheet_load',{side:SIDE, party_id:pid, billing_month:bm},function(r){
    if(!r.ok){ toast(esc(r.error||'載入失敗'), true); return; }
    sheet = r.sheet; canEdit = !!r.can_edit;
    keySeq = 0;
    // 統一成前端用的 key 模型：client_key / split_parent_key
    var idMap = {};
    lines = (sheet.lines||[]).map(function(l){
      var k = nk();
      if(l.line_id) idMap[l.line_id] = k;
      return $.extend({}, l, {client_key:k});
    });
    lines.forEach(function(l){
      l.split_parent_key = (l.split_parent && idMap[l.split_parent]) ? idMap[l.split_parent] : null;
    });
    OPT = r.opt || {no_statement:0, note:null};
    SETL = r.settlement || null;
    // 勾選順序：底稿存回來時只剩 sort_order，用它重建一組序號，之後再勾的就接在後面
    checkSeq = 0;
    lines.slice().sort(function(a,b){ return (a.sort_order||0)-(b.sort_order||0); })
         .forEach(function(l){ if(l.checked) l.check_seq = ++checkSeq; });
    closeRef();
    $('#theirTotal').val(sheet.their_total===null||sheet.their_total===undefined ? '' : sheet.their_total);
    $('#emptyBox').hide(); $('#sheetBox').show();
    $('#btnReopen').toggle(P.canAdmin && sheet.status==='confirmed');
    render();
    renderSync(r.missing||[], r.stale||[]);
    toast(r.from==='draft'
      ? '已接續上次暫存的進度（'+esc(sheet.party_name)+' '+esc(sheet.billing_month)+'）'
      : '已依該月份的單據組出新的對帳單（尚未暫存）');
  },'json').fail(function(x){
    var m='載入失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    toast(esc(m), true);
  });
}

/* ══ 畫表 ══ */
function render(){
  var locked = (sheet.status==='confirmed');
  var editable = canEdit && !locked;
  var pk = parentKeys();

  // 依 sort_order 排；拆分子列緊跟在父列後面
  var top = orderedTops();
  var ordered = [];
  top.forEach(function(l){
    ordered.push(l);
    lines.filter(function(c){ return c.split_parent_key===l.client_key; })
         .sort(function(a,b){ return (a.split_seq||0)-(b.split_seq||0); })
         .forEach(function(c){ ordered.push(c); });
  });

  var h='';
  ordered.forEach(function(l){
    var i = lines.indexOf(l);
    var isChild = !!l.split_parent_key;
    var isParent = !!pk[l.client_key];
    var cls = [];
    if(l.checked) cls.push('hit');
    if(i===selIdx) cls.push('sel');
    if(l.group_no) cls.push('grp');
    if(isChild) cls.push('child');
    if(isParent) cls.push('parent');
    var ro = editable ? '' : ' readonly';

    var canDrag = editable && dragOn && !isChild;
    h+='<tr data-i="'+i+'" class="'+cls.join(' ')+'"'+(canDrag?' draggable="true"':'')+'>'
      +'<td>'+(canDrag?'<span class="drag-h"><i class="fa fa-bars"></i></span>':'')+'</td>'
      +'<td>'+(l.checked?'<i class="fa fa-check" style="color:#5C7A2E;"></i>':'')+'</td>'
      +'<td class="l">'+esc(l.doc_no||'')+'</td>'
      +'<td>'+esc(dispDate(l.doc_date))+'</td>'
      +'<td>'+kindPill(l)+'</td>'
      +(SIDE==='ap'?'<td>'+esc(l.bom||'')+'</td>':'')
      +'<td class="l">'+esc(l.product_id||'')+'</td>'
      +'<td class="l">'+esc((l.spec||'').substr(0,18))+'</td>'
      +'<td class="r orig-v">'+(l.orig_qty===null?'':nf(l.orig_qty))+'</td>'
      +'<td class="r orig-v">'+(l.orig_price===null?'':n2(l.orig_price))+'</td>'
      +'<td class="r orig-v"><b>'+(l.orig_amount===null?'':nf(l.orig_amount))+'</b></td>'
      +'<td><input type="number" class="cell-in adj-q'+(l.adj_qty!==null&&l.adj_qty!==''?' adj':'')+'" data-f="adj_qty" value="'+esc(l.adj_qty===null?'':l.adj_qty)+'"'+ro+'></td>'
      +'<td><input type="number" step="0.0001" class="cell-in adj-p'+(l.adj_price!==null&&l.adj_price!==''?' adj':'')+'" data-f="adj_price" value="'+esc(l.adj_price===null?'':l.adj_price)+'"'+ro+'></td>'
      +'<td><input type="number" step="0.01" class="cell-in adj-a'+(l.adj_amount!==null&&l.adj_amount!==''?' adj':'')+'" data-f="adj_amount" value="'+esc(l.adj_amount===null?'':l.adj_amount)+'"'+ro+'></td>'
      +'<td><input type="month" class="cell-in mth'+(l.adj_month?' adj':'')+'" data-f="adj_month" value="'+esc(l.adj_month||'')+'"'+ro+'></td>'
      +'<td>'+(l.group_no?'<span class="pill p-g">組'+l.group_no+'</span>':'')
             +(isParent?'<span class="pill p-s">拆分來源</span>':'')
             +(isChild?'<span class="pill p-s">第'+(l.split_seq||'')+'段</span>':'')+'</td>'
      +'<td><input type="text" class="cell-in wide" data-f="memo" value="'+esc(l.memo||'')+'"'+ro+'></td>'
      +'<td>'+(editable&&isChild?'<button class="btn-mini rm-child">移除</button>':'')+'</td>'
      +'</tr>';
  });
  $('#tbody').html(h);
  renderFoot(pk);
  updateStat();
}

function renderFoot(pk){
  // 各分組小計（多項加總的組要看得到合計，才能跟對方那一列比）
  var g={}, h='';
  lines.forEach(function(l){
    if(!l.group_no) return;
    if(pk[l.client_key]) return;
    g[l.group_no] = (g[l.group_no]||0) + amtOf(l);
  });
  Object.keys(g).forEach(function(k){
    h+='<tr><td colspan="'+(SIDE==='ap'?11:10)+'" class="r"><b>加總組 '+esc(k)+' 小計</b></td>'
      +'<td colspan="3" class="r"><b style="color:var(--a-brand);font-size:15px;">'+nf(g[k])+'</b></td>'
      +'<td colspan="4"></td></tr>';
  });
  $('#tfoot').html(h);
}

/* ══ 統計與差額 ══ */
function totals(){
  var pk=parentKeys(), net=0, ck=0;
  lines.forEach(function(l){
    if(pk[l.client_key]) return;
    net += amtOf(l);
  });
  lines.forEach(function(l){ if(l.checked) ck++; });
  var tax=Math.round(net*TAX_RATE);
  return {net:net, tax:tax, total:net+tax, checked:ck, count:lines.length};
}
function updateStat(){
  var t=totals();
  var noStmt = !!(OPT && OPT.no_statement);
  var their = (noStmt || $('#theirTotal').val()==='') ? null : Number($('#theirTotal').val());
  var diff  = (their===null) ? null : Math.round((their - t.net)*100)/100;
  // 不提供對帳單的對象：沒有「對方紙本」這回事，欄位收起來免得看的人以為漏填
  $('#theirTotal').closest('div').toggle(!noStmt);
  $('#stDiff').closest('div').toggle(!noStmt);

  $('#stStatus').attr('class','st-pill stp-'+(sheet.status||'new')).text(ST_LABEL[sheet.status]||sheet.status);
  $('#stLines').text(nf(t.count));
  $('#stChecked').text(nf(t.checked));
  $('#stOur').text(nf(t.net));
  $('#stTax').text(nf(t.tax));
  $('#stTotal').text(nf(t.total));
  $('#stDiff').removeClass('bad good')
    .addClass(diff===null?'':(Math.abs(diff)<0.01?'good':'bad'))
    .text(diff===null?'—':nf(diff));

  $('#dkChecked').text(nf(t.checked)); $('#dkLines').text(nf(t.count));
  $('#dkOur').text(nf(t.net));
  $('#dkDiff').html(diff===null?'' : (Math.abs(diff)<0.01
      ? '<b style="color:#CFE8A8;">✓ 與對方紙本相符</b>'
      : '差額 <b style="color:#FFD9CF;">'+nf(diff)+'</b>'));
  $('#dock').css('display','flex');

  var locked=(sheet.status==='confirmed');
  $('#btnSave,#btnConfirm').prop('disabled', !canEdit || locked);
  $('#btnGroup,#btnUngroup,#btnSplit,#btnCheckAll,#btnUncheckAll').prop('disabled', !canEdit || locked);
  $('#btnReopen').toggle(P.canAdmin && locked);

  var nb='';
  if(locked) nb='<div class="okbox">此對帳單已於 '+esc(sheet.confirmed_at||'')+' 由 <b>'
    +esc(sheet.confirmed_by_name||'')+'</b> 確認鎖帳，內容不可再修改。'
    +(P.canAdmin?'需修改請按右下角「退回重對」。':'需修改請洽會計管理員退回。')+'</div>';
  else if(sheet.status==='reopened') nb='<div class="warn">此對帳單曾被 <b>'+esc(sheet.reopen_by_name||'')
    +'</b> 於 '+esc(sheet.reopen_at||'')+' 退回重對，原因：'+esc(sheet.reopen_reason||'')+'</div>';
  else if(!canEdit) nb='<div class="warn">你沒有這一側的對帳修改權限，只能檢視。</div>';
  $('#noteBox').html(nb);
}
$('#theirTotal').on('input', updateStat);

/* ══ 點列勾選（已對到→暖淺綠）══ */
$(document).on('click','#tbody td',function(e){
  if($(e.target).is('input,button,.drag-h,i')) return;
  var $tr=$(this).closest('tr'), i=parseInt($tr.data('i'),10);
  selIdx=i;
  var l=lines[i];
  if(canEdit && sheet.status!=='confirmed'){
    l.checked = l.checked ? 0 : 1;
    l.check_seq = l.checked ? (++checkSeq) : 0;
    applyAutoOrder();
  }
  render();
  // 拖移關閉時，點列同時把這一列的報價／訂單對照叫出來（使用者要求）
  if(!dragOn) openRef(l);
});
$('#btnCheckAll').on('click',function(){
  orderedTops().forEach(function(l){ if(!l.checked){ l.checked=1; l.check_seq=++checkSeq; } });
  lines.forEach(function(l){ if(l.split_parent_key && !l.checked){ l.checked=1; l.check_seq=++checkSeq; } });
  applyAutoOrder(); render();
});
$('#btnUncheckAll').on('click',function(){
  lines.forEach(function(l){ l.checked=0; l.check_seq=0; }); checkSeq=0;
  applyAutoOrder(); render();
});

/* ══ 就地編輯調整值 ══ */
$(document).on('change','#tbody .cell-in',function(){
  var i=parseInt($(this).closest('tr').data('i'),10);
  var f=$(this).data('f'), v=$.trim($(this).val());
  lines[i][f] = (v==='') ? null : v;
  // 改了數量或單價、但沒自己填金額 → 自動算金額，省得手動乘
  if((f==='adj_qty'||f==='adj_price') && (lines[i].adj_amount===null||lines[i].adj_amount==='')){
    var q = (lines[i].adj_qty!==null&&lines[i].adj_qty!=='') ? Number(lines[i].adj_qty) : Number(lines[i].orig_qty);
    var p = (lines[i].adj_price!==null&&lines[i].adj_price!=='') ? Number(lines[i].adj_price) : Number(lines[i].orig_price);
    if(!isNaN(q)&&!isNaN(p)) lines[i].adj_amount = Math.round(q*p*100)/100;
  }
  render();
});

/* ══ 多項加總 ══ */
$('#btnGroup').on('click',function(){
  var sel=lines.filter(function(l){ return l.checked && !l.split_parent_key; });
  if(sel.length<2){ toast('請先點選（勾選）至少 2 列要合併的單據', true); return; }
  var mx=0; lines.forEach(function(l){ if(l.group_no) mx=Math.max(mx,Number(l.group_no)); });
  var g=mx+1;
  sel.forEach(function(l){ l.group_no=g; });
  // 同組排在一起，方便與對方那一列對照
  var base=Math.min.apply(null, sel.map(function(l){ return l.sort_order||0; }));
  sel.forEach(function(l,n){ l.sort_order = base + n; });
  render();
  var sum=0; sel.forEach(function(l){ sum+=amtOf(l); });
  toast('已合併 '+sel.length+' 列為<b>組 '+g+'</b>，小計 <b>'+nf(sum)+'</b>（可與對方那一列比對）');
});
$('#btnUngroup').on('click',function(){
  var n=0;
  lines.forEach(function(l){ if(l.checked && l.group_no){ l.group_no=null; n++; } });
  if(!n){ toast('請先勾選要取消分組的列', true); return; }
  render(); toast('已取消 '+n+' 列的分組');
});

/* ══ 單項拆分 ══ */
var spTarget=null;
$('#btnSplit').on('click',function(){
  if(selIdx<0 || !lines[selIdx]){ toast('請先點一列要拆分的單據（點該列任一處）', true); return; }
  var l=lines[selIdx];
  if(l.split_parent_key){ toast('這已經是拆分出來的子列，不能再拆', true); return; }
  var has=lines.some(function(x){ return x.split_parent_key===l.client_key; });
  if(has){ toast('這列已經拆分過了，請先移除原本的子列', true); return; }
  spTarget=l;
  $('#spInfo').html('要拆分：<b>'+esc(l.doc_no||'')+'</b>　'+esc(l.doc_date||'')
    +'　'+esc(l.product_id||'')+'　金額 <b>'+nf(amtOf(l))+'</b><br>'
    +'用於「我方一次送、但對方拆批或拆月份請款」。<b>各段金額合計必須等於 '+nf(amtOf(l))+'</b>。');
  $('#spN').val(2); $('#spBox').empty(); $('#spTally').text('');
  openMask('mkSplit');
  $('#spGen').click();
});
$('#spGen').on('click',function(){
  var n=Math.max(2,Math.min(12,parseInt($('#spN').val(),10)||2));
  var tot=amtOf(spTarget);
  var each=Math.floor(tot/n*100)/100;
  var h='<table class="a-t"><thead><tr><th>段</th><th>金額</th><th>帳款月份</th><th>備註</th></tr></thead><tbody>';
  for(var i=0;i<n;i++){
    var v = (i===n-1) ? Math.round((tot-each*(n-1))*100)/100 : each;
    h+='<tr><td>'+(i+1)+'</td>'
      +'<td><input type="number" step="0.01" class="sp-a" value="'+v+'" style="width:120px;height:28px;text-align:right;border:1px solid var(--a-line2);border-radius:4px;padding:0 5px;"></td>'
      +'<td><input type="month" class="sp-m" value="'+esc(spTarget.adj_month||sheet.billing_month)+'" style="width:135px;height:28px;border:1px solid var(--a-line2);border-radius:4px;"></td>'
      +'<td><input type="text" class="sp-n" placeholder="如：廠商 6 月才請這段" style="width:230px;height:28px;border:1px solid var(--a-line2);border-radius:4px;padding:0 5px;"></td></tr>';
  }
  $('#spBox').html(h+'</tbody></table>');
  spTally();
});
function spTally(){
  var sum=0; $('.sp-a').each(function(){ sum+=Number(this.value)||0; });
  var tot=amtOf(spTarget), d=Math.round((sum-tot)*100)/100;
  $('#spTally').html('各段合計 <b>'+nf(sum)+'</b> ／ 原列 '+nf(tot)
    + (Math.abs(d)<0.01 ? ' <b style="color:#5C7A2E;">✓ 相符</b>'
                        : ' <b style="color:var(--a-bad);">差 '+nf(d)+'（必須為 0 才能套用）</b>'));
  $('#spOk').prop('disabled', Math.abs(d)>=0.01);
}
$(document).on('input','.sp-a', spTally);
$('#spOk').on('click',function(){
  var tot=amtOf(spTarget), sum=0, kids=[];
  $('#spBox tbody tr').each(function(n){
    var a=Number($(this).find('.sp-a').val())||0;
    sum+=a;
    kids.push({amount:a, month:$(this).find('.sp-m').val(), memo:$.trim($(this).find('.sp-n').val())});
  });
  if(Math.abs(sum-tot)>=0.01){ toast('各段合計必須等於原列金額', true); return; }
  // 移除舊子列後重建
  lines = lines.filter(function(l){ return l.split_parent_key!==spTarget.client_key; });
  kids.forEach(function(k,n){
    lines.push({
      client_key:nk(), split_parent_key:spTarget.client_key, split_seq:n+1,
      src_type:'SPLIT', src_id:0, doc_no:spTarget.doc_no, doc_date:spTarget.doc_date,
      bom:spTarget.bom, product_id:spTarget.product_id, spec:spTarget.spec,
      orig_qty:null, orig_price:null, orig_amount:tot,
      adj_qty:null, adj_price:null, adj_amount:k.amount, adj_month:k.month||null,
      checked:0, group_no:null, memo:k.memo||null,
      sort_order:(spTarget.sort_order||0)+ (n+1)/100
    });
  });
  closeMask('mkSplit'); render();
  toast('已把 <b>'+esc(spTarget.doc_no||'')+'</b> 拆成 '+kids.length+' 段（原列改為拆分來源，不計入合計）');
});
$(document).on('click','.rm-child',function(e){
  e.stopPropagation();
  var i=parseInt($(this).closest('tr').data('i'),10);
  var k=lines[i].split_parent_key;
  lines.splice(i,1);
  var left=lines.filter(function(l){ return l.split_parent_key===k; });
  left.forEach(function(l,n){ l.split_seq=n+1; });
  selIdx=-1; render();
  toast(left.length ? '已移除一段（剩 '+left.length+' 段，注意合計需與原列相符才能存）'
                    : '已移除全部拆分，該列恢復計入合計');
});

/* ══ 拖移排序 ══ */
var dragIdx=-1;
$(document).on('dragstart','#tbody tr',function(e){
  if(!dragOn){ e.preventDefault(); return; }
  dragIdx=parseInt($(this).data('i'),10);
  $(this).addClass('dragging');
  try{ e.originalEvent.dataTransfer.effectAllowed='move';
       e.originalEvent.dataTransfer.setData('text/plain',String(dragIdx)); }catch(err){}
});
$(document).on('dragend','#tbody tr',function(){ $('#tbody tr').removeClass('dragging dragover'); });
$(document).on('dragover','#tbody tr',function(e){
  e.preventDefault();
  $('#tbody tr').removeClass('dragover'); $(this).addClass('dragover');
});
$(document).on('drop','#tbody tr',function(e){
  e.preventDefault();
  $('#tbody tr').removeClass('dragover dragging');
  var to=parseInt($(this).data('i'),10);
  if(dragIdx<0 || to<0 || dragIdx===to) return;
  var src=lines[dragIdx], dst=lines[to];
  if(src.split_parent_key) return;                 // 子列跟著父列走，不單獨排
  // 重排：把 src 的 sort_order 插到 dst 前面
  var tops=lines.filter(function(l){ return !l.split_parent_key; })
                .sort(function(a,b){ return (a.sort_order||0)-(b.sort_order||0); });
  var arr=tops.filter(function(l){ return l!==src; });
  var at=arr.indexOf(dst);
  if(at<0) at=arr.length;
  arr.splice(at,0,src);
  arr.forEach(function(l,n){ l.sort_order=(n+1)*10; });
  // 子列跟著父列
  lines.filter(function(l){ return l.split_parent_key; }).forEach(function(c){
    var p=lines.filter(function(x){ return x.client_key===c.split_parent_key; })[0];
    if(p) c.sort_order=(p.sort_order||0)+(c.split_seq||1)/100;
  });
  dragIdx=-1; selIdx=-1; render();
});

/* ══ 暫存 / 確認 / 退回 ══ */
function payload(){
  return {
    sheet: JSON.stringify({
      side:SIDE, party_id:sheet.party_id, party_name:sheet.party_name,
      billing_month:sheet.billing_month,
      their_total: ((OPT&&OPT.no_statement)||$('#theirTotal').val()==='') ? null : $('#theirTotal').val(),
      memo: sheet.memo||null
    }),
    lines: JSON.stringify(lines.map(function(l){
      return {
        client_key:l.client_key, split_parent_key:l.split_parent_key||null, split_seq:l.split_seq||null,
        sort_order:Math.round(l.sort_order||0), src_type:l.src_type, src_id:l.src_id||0,
        doc_no:l.doc_no, doc_date:l.doc_date, bom:l.bom, product_id:l.product_id, spec:l.spec,
        orig_qty:l.orig_qty, orig_price:l.orig_price, orig_amount:l.orig_amount,
        adj_qty:l.adj_qty, adj_price:l.adj_price, adj_amount:l.adj_amount, adj_month:l.adj_month,
        checked:l.checked?1:0, group_no:l.group_no||null, memo:l.memo||null
      };
    }))
  };
}
$('#btnSave').on('click',function(){
  var p=payload(); p.csrf=CSRF;
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 暫存中…');
  $.post(API+'?action=sheet_save', p, function(r){
    $b.prop('disabled',false).html('<i class="fa fa-save"></i> 暫存進度');
    if(!r.ok){ toast(esc(r.error||'暫存失敗'), true); return; }
    sheet.sheet_id=r.sheet_id; sheet.status='draft';
    toast(esc(r.message)); loadSheet();
  },'json').fail(function(x){
    $b.prop('disabled',false).html('<i class="fa fa-save"></i> 暫存進度');
    var m='暫存失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    toast(esc(m), true);
  });
});
$('#btnConfirm').on('click',function(){
  var t=totals();
  var their=((OPT&&OPT.no_statement)||$('#theirTotal').val()==='')?null:Number($('#theirTotal').val());
  var diff = their===null?null:Math.round((their-t.net)*100)/100;
  var warn='';
  var noStmt = !!(OPT && OPT.no_statement);
  if(t.checked<t.count) warn+='\n・還有 '+(t.count-t.checked)+' 列沒有標記「已對到」';
  if(!noStmt && diff!==null && Math.abs(diff)>=0.01) warn+='\n・與對方紙本仍有差額 '+nf(diff)+' 元';
  if(!noStmt && their===null) warn+='\n・尚未輸入對方紙本合計（無法比對）';
  if(!confirm('確認這份對帳單正確並鎖帳？'+(warn?'\n\n請注意：'+warn:'')
    +'\n\n鎖帳後不可再修改，僅會計管理員可退回重對。')) return;

  // 先把目前畫面存起來再確認，避免鎖到舊版本
  var p=payload(); p.csrf=CSRF;
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
  $.post(API+'?action=sheet_save', p, function(r){
    if(!r.ok){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
               toast(esc(r.error||'暫存失敗'), true); return; }
    $.post(API+'?action=sheet_confirm',{csrf:CSRF, sheet_id:r.sheet_id},function(c){
      $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
      if(!c.ok){ toast(esc(c.error||'確認失敗'), true); return; }
      toast('<b>'+esc(c.message)+'</b>');
      loadSheet(); loadParties();
    },'json').fail(function(){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
                               toast('確認失敗', true); });
  },'json').fail(function(){ $b.prop('disabled',false).html('<i class="fa fa-check"></i> 確認正確（鎖帳）');
                             toast('暫存失敗', true); });
});
$('#btnReopen').on('click',function(){ $('#roReason').val(''); openMask('mkReopen'); });
$('#roOk').on('click',function(){
  var rs=$.trim($('#roReason').val());
  if(rs.length<2){ toast('請填寫退回原因', true); $('#roReason').focus(); return; }
  $.post(API+'?action=sheet_reopen',{csrf:CSRF, sheet_id:sheet.sheet_id, reason:rs},function(r){
    if(!r.ok){ toast(esc(r.error||'退回失敗'), true); return; }
    closeMask('mkReopen'); toast(esc(r.message)); loadSheet(); loadParties();
  },'json').fail(function(){ toast('退回失敗', true); });
});

$('#btnExport').on('click',function(){
  if(!sheet || !sheet.sheet_id){ toast('請先暫存後再匯出', true); return; }
  window.location = API+'?action=sheet_export&side='+SIDE
    +'&party_id='+encodeURIComponent(sheet.party_id)
    +'&billing_month='+encodeURIComponent(sheet.billing_month);
});
$('#btnPrint').on('click', printSheet);

/* ══ 計算機 ══ */
var cExp='', cVal='0';
$('#btnCalc').on('click',function(){ $('#calc').toggleClass('show'); });
$('#calcX').on('click',function(){ $('#calc').removeClass('show'); });
$(document).on('click','.c-pad button',function(){
  var c=$(this).data('c');
  if(c==='C'){ cExp=''; cVal='0'; }
  else if(c==='BS'){ cExp=cExp.slice(0,-1); cVal=cExp||'0'; }
  else if(c==='SUM'){
    var s=0; lines.forEach(function(l){ if(l.checked) s+=amtOf(l); });
    cExp=String(s); cVal=String(s);
  }
  else if(c==='='){
    try{
      var e=cExp.replace(/%/g,'/100');
      if(!/^[\d+\-*/.() ]+$/.test(e)) throw 0;
      var v=Function('"use strict";return ('+e+')')();
      cVal=(v===undefined||v===null||isNaN(v))?'錯誤':String(Math.round(v*10000)/10000);
      cExp=(cVal==='錯誤')?'':cVal;
    }catch(err){ cVal='錯誤'; cExp=''; }
  }
  else { cExp+=String(c); cVal=cExp; }
  $('#calcExp').text(cExp);
  $('#calcScr').text(cVal);
});
/* 計算機可拖動 */
(function(){
  var dx=0,dy=0,drag=false;
  $('#calcHead').on('mousedown',function(e){
    if($(e.target).is('#calcX')) return;
    drag=true;
    var o=$('#calc').offset();
    dx=e.pageX-o.left; dy=e.pageY-o.top;
    e.preventDefault();
  });
  $(document).on('mousemove',function(e){
    if(!drag) return;
    $('#calc').css({left:(e.pageX-dx)+'px', top:(e.pageY-dy)+'px', right:'auto', bottom:'auto'});
  }).on('mouseup',function(){ drag=false; });
})();

/* ══════════════════════════════════════════════════════════════════════
 * 以下為 2026-08-28 依使用者要求新增的對帳輔助功能
 * ══════════════════════════════════════════════════════════════════════ */

/* ── 類別徽章：退貨要一眼看得出來（金額是負的，會直接扣減本月應收）───── */
function kindPill(l){
  if(l.split_parent_key) return '<span class="pill k-split">拆分</span>';
  var t=(l.src_type||'').toUpperCase();
  if(t==='IR')   return '<span class="pill k-ret">退貨</span>';
  if(t==='TLOG') return '<span class="pill k-proc">加工</span>';
  if(t==='PURC') return '<span class="pill k-proc">採購</span>';
  if(t==='IS')   return '<span class="pill k-ship">出貨</span>';
  return '';
}
function kindText(l){
  if(l.split_parent_key) return '拆分';
  var t=(l.src_type||'').toUpperCase();
  return t==='IR'?'退貨':(t==='TLOG'?'加工':(t==='PURC'?'採購':(t==='IS'?'出貨':'')));
}

/* ── 排序：預設照 sort_order；「依勾選順序排序」開啟時已勾選的排在前面 ── */
function orderedTops(){
  var tops = lines.filter(function(l){ return !l.split_parent_key; });
  tops.sort(function(a,b){
    if(autoOn){
      var ac=a.checked?0:1, bc=b.checked?0:1;
      if(ac!==bc) return ac-bc;
      if(a.checked) return (a.check_seq||0)-(b.check_seq||0);
    }
    return (a.sort_order||0)-(b.sort_order||0);
  });
  return tops;
}
/* 把目前顯示順序寫回 sort_order，這樣「依勾選順序」排出來的結果暫存後也留得住 */
function applyAutoOrder(){
  if(!autoOn) return;
  orderedTops().forEach(function(l,n){ l.sort_order=(n+1)*10; });
  lines.filter(function(l){ return l.split_parent_key; }).forEach(function(c){
    var p=lines.filter(function(x){ return x.client_key===c.split_parent_key; })[0];
    if(p) c.sort_order=(p.sort_order||0)+(c.split_seq||1)/100;
  });
}

/* ── 兩個開關 ───────────────────────────────────────────────────────── */
$('#swDrag').on('click',function(){
  dragOn=!dragOn; localStorage.setItem(prefKey('drag'), dragOn?'1':'0');
  if(dragOn) closeRef();
  paintSwitches(); if(sheet) render();
});
$('#swAuto').on('click',function(){
  autoOn=!autoOn; localStorage.setItem(prefKey('auto'), autoOn?'1':'0');
  paintSwitches();
  if(sheet){ applyAutoOrder(); render();
    toast(autoOn ? '已開啟：之後每勾選一列就會排到已勾選那一區的最後（＝你勾選的順序）'
                 : '已關閉依勾選順序排序，恢復原本的排列順序'); }
});

/* ── 底稿與來源不一致的提醒（跨月改過月份、或暫存後又有新單）─────────── */
var syncMissing=[];
function renderSync(missing, stale){
  syncMissing = missing||[];
  var h='';
  if(syncMissing.length){
    var sum=0; syncMissing.forEach(function(l){ sum+=Number(l.orig_amount)||0; });
    h+='<div class="warn" style="display:flex;align-items:center;gap:10px;">'
      +'<span>有 <b>'+syncMissing.length+'</b> 筆本月份的單據不在這份底稿裡（合計 '+nf(sum)
      +'）——通常是暫存之後才把它們改成本月份，或又有新的出貨。</span>'
      +'<button class="btn-mini" id="btnAddMissing" style="margin-left:auto;">加入這 '+syncMissing.length+' 筆</button>'
      +'<button class="btn-mini" id="btnShowMissing">看看是哪幾筆</button></div>';
  }
  if(SETL && SETL.source==='party' && SETL.billing_month && sheet
     && SETL.billing_month!==sheet.billing_month){
    h+='<div class="info">這個對象自訂結帳日為 <b>'+esc(String(SETL.cut_day||SETL.day))
      +'</b> 日（與'+(SIDE==='ap'?'廠商':'客戶')+'預設不同）：'+esc(SETL.reason||'')
      +'　目前開的是 <b>'+esc(sheet.billing_month)+'</b>。'
      +'<button class="btn-mini" id="btnUseSetlBm" style="margin-left:8px;">改對 '+esc(SETL.billing_month)+'</button></div>';
  }
  if(stale && stale.length){
    h+='<div class="warn">有 <b>'+stale.length+'</b> 筆底稿上的單據<b>已不屬於本月份</b>（可能被改到別的月份）：'
      + stale.map(function(x){ return esc(x.doc_no||'')+'／'+esc(x.product_id||''); }).join('、')
      +'　<button class="btn-mini" id="btnDropStale">從底稿移除這 '+stale.length+' 筆</button>'
      +'<div class="a-hint">不移除的話同一筆錢可能被兩個月份各對一次。</div></div>';
  }
  $('#syncBox').html(h);
  staleIds = (stale||[]).map(function(x){ return Number(x.line_id); });
}
var staleIds=[];
$(document).on('click','#btnUseSetlBm',function(){
  if(!SETL || !SETL.billing_month) return;
  bmTouched = true;
  $('#bm').val(SETL.billing_month);
  bmWhy('依此對象自訂結帳日');
  var keep = $('#partySel').val();
  loadParties();
  setTimeout(function(){ if($('#partySel').val()!==keep) $('#partySel').val(keep);
                         if($('#partySel').val()) loadSheet(); }, 700);
});
$(document).on('click','#btnAddMissing',function(){
  var max=0; lines.forEach(function(l){ max=Math.max(max, Number(l.sort_order)||0); });
  syncMissing.forEach(function(l,n){
    lines.push($.extend({}, l, {client_key:nk(), split_parent_key:null,
      sort_order:max+(n+1)*10, checked:0, check_seq:0}));
  });
  toast('已加入 '+syncMissing.length+' 筆，記得按「暫存進度」存起來');
  syncMissing=[]; $('#syncBox').html(''); render();
});
$(document).on('click','#btnShowMissing',function(){
  var h='<table class="ot"><thead><tr><th>單號</th><th>日期</th><th>類別</th><th>料號</th><th>數量</th><th>金額</th></tr></thead><tbody>';
  syncMissing.forEach(function(l){
    h+='<tr><td class="l">'+esc(l.doc_no||'')+'</td><td>'+esc(dispDate(l.doc_date))+'</td>'
      +'<td>'+kindPill(l)+'</td><td class="l">'+esc(l.product_id||'')+'</td>'
      +'<td class="r">'+nf(l.orig_qty)+'</td><td class="r">'+nf(l.orig_amount)+'</td></tr>';
  });
  $('#otTbl').length;
  alertBox('這些單據屬於本月份但不在底稿裡', h+'</tbody></table>');
});
$(document).on('click','#btnDropStale',function(){
  if(!staleIds.length) return;
  var before=lines.length;
  lines = lines.filter(function(l){ return staleIds.indexOf(Number(l.line_id))<0; });
  toast('已移除 '+(before-lines.length)+' 筆，記得按「暫存進度」存起來');
  staleIds=[]; render(); renderSync(syncMissing, []);
});

/* 簡易資訊跳窗（借用既有 mkSplit 之外另開一個，避免蓋掉正在填的內容） */
function alertBox(title, html){
  if(!$('#mkInfo').length){
    $('body').append('<div class="a-mask" id="mkInfo"><div class="a-modal">'
      +'<div class="m-head"><span id="ibTitle"></span><span class="m-close" data-close="mkInfo">✕</span></div>'
      +'<div class="m-body" id="ibBody"></div>'
      +'<div class="m-foot"><button data-close="mkInfo">關閉</button></div></div></div>');
  }
  $('#ibTitle').text(title); $('#ibBody').html(html); openMask('mkInfo');
}

/* ── 料號／單號即時搜尋：打字出清單，點選跳到那一列並打亮 ───────────── */
function findMatch(kw){
  var ws=$.trim(kw).toLowerCase().split(/\s+/).filter(function(x){ return x!==''; });
  if(!ws.length) return [];
  return orderedTops().concat(lines.filter(function(l){ return l.split_parent_key; }))
    .filter(function(l){
      var hay=((l.product_id||'')+' '+(l.doc_no||'')+' '+(l.spec||'')+' '+(l.bom||'')+' '+(l.memo||'')).toLowerCase();
      for(var i=0;i<ws.length;i++) if(hay.indexOf(ws[i])<0) return false;
      return true;
    }).slice(0,30);
}
function paintFind(){
  var kw=$('#findKw').val();
  if(!$.trim(kw)){ $('#findPop').removeClass('show').empty(); return; }
  var hit=findMatch(kw);
  if(!hit.length){ $('#findPop').addClass('show').html('<div class="fi-none">找不到符合的列（只在目前這份對帳單裡找）</div>'); return; }
  var h='';
  hit.forEach(function(l){
    var i=lines.indexOf(l);
    h+='<div class="fi" data-i="'+i+'"><b>'+esc(l.product_id||'（無料號）')+'</b>　'+esc(l.doc_no||'')
      +'　'+kindPill(l)+'<span class="sub">　'+esc(dispDate(l.doc_date))+'　數量 '+nf(l.orig_qty)
      +'　金額 '+nf(amtOf(l))+(l.checked?'　✔已對到':'')+'</span></div>';
  });
  $('#findPop').addClass('show').html(h);
}
$('#findKw').on('input', paintFind).on('focus', function(){ if($.trim(this.value)) paintFind(); });
$('#findKw').on('keydown', function(e){
  if(e.key==='Escape'){ $('#findPop').removeClass('show'); return; }
  if(e.key!=='Enter') return;
  e.preventDefault();                       // 不要觸發共用檔的「Enter＝按主要動作鈕」
  var $f=$('#findPop .fi').first();
  if($f.length) $f.trigger('click');
});
$(document).on('click','#findPop .fi',function(){
  gotoLine(parseInt($(this).data('i'),10));
  $('#findPop').removeClass('show');
});
$(document).on('click',function(e){
  if(!$(e.target).closest('.find-wrap').length) $('#findPop').removeClass('show');
});
function gotoLine(i){
  if(isNaN(i) || !lines[i]) return;
  selIdx=i; render();
  var $tr=$('#tbody tr[data-i="'+i+'"]');
  if(!$tr.length) return;
  $tr.addClass('flash');
  var top=$tr.offset().top - $(window).height()/2;
  $('html,body').animate({scrollTop: Math.max(0, top)}, 220);
  setTimeout(function(){ $tr.removeClass('flash'); }, 1900);
  if(!dragOn) openRef(lines[i]);
}

/* ── 右側面板：這一列的報價與訂單對照 ───────────────────────────────── */
function closeRef(){ $('#refDock').removeClass('ref-on'); $('body').removeClass('ref-open'); refSrc=null; }
$('#refX').on('click', closeRef);
function openRef(l){
  if(!l) return;
  if(l.split_parent_key || !l.src_id || l.src_type==='SPLIT'){
    refSrc=null;
    $('#refTitle').text('報價／訂單對照'); $('#refHeadPrice').text('');
    $('#refBody').html('<div class="chk chk-na"><span class="cl">無法對照</span>'
      +'<span>這是拆分出來的子列（或手動加列），沒有對應的來源單據。請點<b>原始那一列</b>查看。</span></div>');
    $('#refDock').addClass('ref-on'); $('body').addClass('ref-open');
    return;
  }
  refSrc={src_type:l.src_type, src_id:l.src_id};
  $('#refTitle').text((l.doc_no||'') + '　' + (l.product_id||''));
  $('#refHeadPrice').text('');
  $('#refBody').html('<div style="padding:14px;color:#8a6d45;"><i class="fa fa-spinner fa-spin"></i> 查詢中…</div>');
  $('#refDock').addClass('ref-on'); $('body').addClass('ref-open');
  $.post(API+'?action=recon_line_ref',{src_type:l.src_type, src_id:l.src_id},function(r){
    if(!r.ok){ $('#refBody').html('<div class="chk chk-bad"><span>'+esc(r.error||'查詢失敗')+'</span></div>'); return; }
    renderRef(r.ref);
  },'json').fail(function(x){
    var m='查詢失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){}
    $('#refBody').html('<div class="chk chk-bad"><span>'+esc(m)+'</span></div>');
  });
}
/* 單價對照大字帶：使用者要求「要核對的單價要明顯，不用整片讀才找得到」。
   三段單價（出貨／訂單／報價）並排放大，狀態一眼可辨；
   退貨比對的是「同料號近期出貨單價」，加工費比對的是「ERP 原始單價」。 */
function priceBand(f){
  var d=f.doc||{}, cell=function(label,val,na){
    return '<div class="pc-cell"><span class="pc-l">'+esc(label)+'</span>'
         + (na ? '<span class="pc-v na">'+esc(val)+'</span>'
               : '<b class="pc-v">'+esc(val)+'</b>')+'</div>';
  };
  var op='<div class="pc-op">vs</div>';
  var cls='pcmp-na', tag='無法比對', note='', row='';

  if(d.kind==='process'){
    var up=Number(d.unit_price)||0, erp=Number(d.erp_price)||0;
    row = cell('本單加工單價', pnum(up))
        + op + cell('ERP 原始單價', erp?pnum(erp):'—', !erp);
    var amt=Number(d.amount)||0, calc=Math.round((Number(d.qty)||0)*up*100)/100;
    if(d.price_overridden){ cls='pcmp-warn'; tag='單價被覆寫';
      note='本系統把 ERP 原始單價改成 '+pnum(up)+'，差 '+pnum(up-erp)+'／件。'; }
    else { cls='pcmp-ok'; tag='與 ERP 相同'; note='加工單價未被覆寫。'; }
    if(Math.abs(calc-amt)>=0.51){ cls='pcmp-bad'; tag='金額不符';
      note='數量 × 單價 = '+nf(calc)+'，與加工金額 '+nf(amt)+' 差 '+nf(calc-amt)+'。'; }
  }
  else if(d.kind==='return'){
    var rp=Number(d.unit_price)||0;
    var ship=(f.returns_of&&f.returns_of.length)?Number(f.returns_of[0].Unit_price):null;
    row = cell('退回單價', pnum(rp))
        + op + cell('近期出貨單價', ship===null?'查無':pnum(ship), ship===null);
    if(ship===null){ cls='pcmp-na'; tag='無出貨可比'; note='這個料號查不到同客戶的出貨紀錄。'; }
    else if(Math.abs(ship-rp)<0.005){ cls='pcmp-ok'; tag='相符'; note='退回單價與當初出貨單價一致。'; }
    else { cls='pcmp-warn'; tag='不同';
      note='退回單價與近期出貨單價差 '+pnum(rp-ship)+'／件，請確認是否為折讓價。'; }
  }
  else {
    var sp=Number(d.unit_price)||0;
    var op1=(f.order && f.order.unit_price!==null && f.order.unit_price!=='') ? Number(f.order.unit_price) : null;
    var qp=(f.quote && Number(f.quote.eff_unit_price)>0) ? Number(f.quote.eff_unit_price) : null;
    row = cell('出貨單價', pnum(sp))
        + op + cell('訂單單價', op1===null ? (f.order?'訂單未填':'未綁訂單') : pnum(op1), op1===null)
        + op + cell('報價單價', qp===null ? (f.order? (f.quote?'報價未填':'未綁報價') : '—') : pnum(qp), qp===null);
    var bad = (op1!==null && Math.abs(op1-sp)>=0.005);
    var warn= (qp!==null && Math.abs(qp-sp)>=0.005);
    var qty = Number(d.qty)||0;
    if(bad){ cls='pcmp-bad'; tag='與訂單不符';
      note='出貨比訂單 '+(sp>op1?'高':'低')+' '+pnum(Math.abs(sp-op1))+'／件，本列金額差 '
          + nf((sp-op1)*qty)+'。'; }
    else if(warn){ cls='pcmp-warn'; tag='與報價不符';
      note='出貨與訂單單價相符，但與報價 '+(sp>qp?'高':'低')+' '+pnum(Math.abs(sp-qp))+'／件'
          + (f.quote&&f.quote.quote_no ? ('（報價單 '+esc(f.quote.quote_no)+'）') : '')+'。'; }
    else if(op1!==null && qp!==null){ cls='pcmp-ok'; tag='三段相符'; note='報價、訂單、出貨單價完全一致。'; }
    else if(op1!==null){ cls='pcmp-ok'; tag='與訂單相符';
      note='訂單單價一致；' + (f.order&&f.order.quote_no ? '報價單價無法取得。' : '此訂單沒有綁報價，報價單價請人工核對。'); }
    else { cls='pcmp-na'; tag='需人工核對';
      note=(f.order?'這張訂單沒有填單價。':'這張出貨單沒有綁訂單。')+'請人工核對單價，或在下方綁定。'; }
  }
  return '<div class="pcmp '+cls+'"><div class="pc-hd">單價對照'
       + '<span class="pc-tag">'+esc(tag)+'</span></div>'
       + '<div class="pc-row">'+row+'</div>'
       + (note?'<div class="pc-note">'+note+'</div>':'')+'</div>';
}
function refRow(k,v){ return '<tr><th>'+esc(k)+'</th><td>'+(v==null?'':v)+'</td></tr>'; }
function renderRef(f){
  var d=f.doc||{}, h='';
  $('#refHeadPrice').text(d.unit_price==null?'':('單價 '+pnum(d.unit_price)));

  h+=priceBand(f);
  h+='<div class="ref-sec"><h5>本列單據</h5><table class="ref-t">'
    + refRow('單號', esc(d.no||''))
    + refRow('日期', esc(dispDate(d.date)))
    + refRow('類別', d.kind==='return'?'<span class="pill k-ret">退貨</span>'
                    :(d.kind==='process'?'<span class="pill k-proc">加工費</span>':'<span class="pill k-ship">出貨</span>'))
    + refRow('料號', esc(d.product_id||''))
    + refRow('品名／製程', esc(d.spec||''))
    + refRow('數量 × 單價', nf(d.qty)+' × '+esc(pnum(d.unit_price)))
    + refRow('金額', '<b>'+nf(d.amount)+'</b>')
    + (d.note ? refRow('備註', esc(d.note)) : '')
    + '</table></div>';

  h+='<div class="ref-sec"><h5>自動比對結果</h5>';
  if(!f.checks || !f.checks.length) h+='<div class="chk chk-na"><span>沒有可比對的項目</span></div>';
  (f.checks||[]).forEach(function(c){
    h+='<div class="chk chk-'+esc(c.status)+'"><span class="cl">'+esc(c.label)+'</span><span>'+esc(c.text)+'</span></div>';
  });
  h+='</div>';

  if(f.order){
    var o=f.order;
    h+='<div class="ref-sec"><h5>已綁定的訂單</h5><table class="ref-t">'
      + refRow('訂單編號', esc(o.Order_oo||''))
      + refRow('客戶單號', esc(o.C_order||''))
      + refRow('接單／交期', esc(dispDate(o.odate))+' ／ '+esc(dispDate(o.ddate)))
      + refRow('訂單數量', nf(o.Qty)+'（累計已出 '+nf(o.shipped_qty)+'）')
      + refRow('訂單單價', esc(o.unit_price==null?'（未填）':pnum(o.unit_price)))
      + refRow('綁定報價', esc(o.quote_no||'（未綁定）'))
      + '</table></div>';
  }
  if(f.quote){
    var q=f.quote;
    h+='<div class="ref-sec"><h5>已綁定的報價</h5><table class="ref-t">'
      + refRow('報價單號', esc(q.quote_no||''))
      + refRow('報價日期', esc(dispDate(q.quote_date)))
      + refRow('報價客戶', esc(q.client_name||''))
      + refRow('報價數量', nf(q.quantity)+' '+esc(q.unit||''))
      + refRow('報價單價', Number(q.is_tiered)===1 ? ('階梯價，適用 <b>'+esc(pnum(q.eff_unit_price))+'</b>')
                                                  : ('<b>'+esc(pnum(q.unit_price))+'</b>'))
      + '</table>';
    if(q.tiers && q.tiers.length){
      h+='<div class="cand" style="margin-top:5px;"><table><thead><tr><th>數量下限</th><th>上限</th><th>單價</th><th>容差</th></tr></thead><tbody>';
      q.tiers.forEach(function(t){
        var on = q.matched_tier && Number(q.matched_tier.tier_id)===Number(t.tier_id);
        h+='<tr'+(on?' class="now"':'')+'><td>'+nf(t.qty_min)+'</td>'
          +'<td>'+(t.qty_max==null?'以上':nf(t.qty_max))+'</td>'
          +'<td>'+esc(pnum(t.unit_price))+'</td>'
          +'<td>'+(t.tolerance_value==null?'—':(esc(pnum(t.tolerance_value))+esc(t.tolerance_unit||'')))+'</td></tr>';
      });
      h+='</tbody></table></div>';
    }
    h+='</div>';
  }

  if(f.returns_of && f.returns_of.length){
    h+='<div class="ref-sec"><h5>同料號近期出貨（供比對退回單價）</h5><div class="cand"><table>'
      +'<thead><tr><th>出貨單號</th><th>日期</th><th>數量</th><th>單價</th></tr></thead><tbody>';
    f.returns_of.forEach(function(x){
      h+='<tr><td>'+esc(x.IS_number)+'</td><td>'+esc(dispDate(x.d))+'</td><td>'+nf(x.Qty)+'</td>'
        +'<td>'+esc(pnum(x.Unit_price))+'</td></tr>';
    });
    h+='</tbody></table></div></div>';
  }

  // 綁定區（只有出貨列才有，且要有該側對帳權限、底稿未鎖帳）
  var editable = canEdit && sheet && sheet.status!=='confirmed';
  if(f.supports_bind){
    h+='<div class="ref-sec"><h5>綁定訂單'+(editable?'':'（唯讀）')+'</h5>';
    if(!f.order_candidates.length) h+='<div class="chk chk-na"><span>找不到同客戶同料號的訂單</span></div>';
    else{
      h+='<div class="cand"><table><thead><tr><th>訂單編號</th><th>接單日</th><th>數量</th><th>已出</th><th>單價</th><th>操作</th></tr></thead><tbody>';
      f.order_candidates.forEach(function(o){
        var now = f.order && Number(f.order.Order_id)===Number(o.Order_id);
        h+='<tr'+(now?' class="now"':'')+'><td>'+esc(o.Order_oo)+'</td><td>'+esc(dispDate(o.odate))+'</td>'
          +'<td>'+nf(o.Qty)+'</td><td>'+nf(o.shipped)+'</td><td>'+esc(o.unit_price==null?'—':pnum(o.unit_price))+'</td>'
          +'<td>'+(now?'<span class="pill p-s">目前</span>'
                     :(editable?'<button class="btn-mini bd-o" data-oid="'+o.Order_id+'">綁定</button>':'—'))+'</td></tr>';
      });
      h+='</tbody></table></div>';
      if(editable && f.order) h+='<div style="margin-top:5px;"><button class="btn-mini bd-o" data-oid="0">解除訂單綁定</button></div>';
    }
    h+='</div>';

    h+='<div class="ref-sec"><h5>綁定報價'+(editable?'':'（唯讀）')+'</h5>';
    if(!f.order) h+='<div class="chk chk-na"><span>要先綁訂單，報價是掛在訂單上的</span></div>';
    else if(!f.quote_candidates.length) h+='<div class="chk chk-na"><span>找不到同客戶同料號的報價明細</span></div>';
    else{
      h+='<div class="cand"><table><thead><tr><th>報價單號</th><th>日期</th><th>數量</th><th>單價</th><th>操作</th></tr></thead><tbody>';
      f.quote_candidates.forEach(function(q){
        var now = f.quote && Number(f.quote.item_id)===Number(q.item_id);
        h+='<tr'+(now?' class="now"':'')+'><td>'+esc(q.quote_no)+'</td><td>'+esc(dispDate(q.quote_date))+'</td>'
          +'<td>'+nf(q.quantity)+'</td>'
          +'<td>'+(Number(q.is_tiered)===1?'階梯價':esc(pnum(q.unit_price)))+'</td>'
          +'<td>'+(now?'<span class="pill p-s">目前</span>'
                     :(editable?'<button class="btn-mini bd-q" data-iid="'+q.item_id+'" data-oid="'+f.order.Order_id+'">綁定</button>':'—'))+'</td></tr>';
      });
      h+='</tbody></table></div>';
      if(editable && f.quote) h+='<div style="margin-top:5px;"><button class="btn-mini bd-q" data-iid="0" data-oid="'+f.order.Order_id+'">解除報價綁定</button></div>';
      h+='<div class="a-hint">綁定會寫回訂單的報價欄位（全站共用），並留下稽核紀錄。</div>';
    }
    h+='</div>';
  }
  $('#refBody').html(h);
}
$(document).on('click','.bd-o',function(){
  if(!refSrc) return;
  var oid=Number($(this).data('oid'))||0;
  if(!confirm(oid? '確定把這張出貨單綁定到選取的訂單？' : '確定解除這張出貨單的訂單綁定？')) return;
  $.post(API+'?action=recon_bind_order',{csrf:CSRF, is_id:refSrc.src_id, order_id:oid},function(r){
    if(!r.ok){ toast(esc(r.error||'綁定失敗'), true); return; }
    toast(esc(r.message));
    openRef({src_type:refSrc.src_type, src_id:refSrc.src_id, doc_no:$('#refTitle').text()});
  },'json').fail(function(x){
    var m='綁定失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true);
  });
});
$(document).on('click','.bd-q',function(){
  if(!refSrc) return;
  var iid=Number($(this).data('iid'))||0, oid=Number($(this).data('oid'))||0;
  if(!confirm(iid? '確定把這張訂單綁定到選取的報價明細？' : '確定解除這張訂單的報價綁定？')) return;
  $.post(API+'?action=recon_bind_quote',{csrf:CSRF, order_id:oid, item_id:iid},function(r){
    if(!r.ok){ toast(esc(r.error||'綁定失敗'), true); return; }
    toast(esc(r.message));
    openRef({src_type:refSrc.src_type, src_id:refSrc.src_id});
  },'json').fail(function(x){
    var m='綁定失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true);
  });
});

/* ── 跨月找單：把別的月份的單改成本月份並帶入 ───────────────────────── */
var otRows=[];
$('#btnOutside').on('click',function(){
  if(!sheet){ toast('請先載入對帳單', true); return; }
  $('#otKw').val(''); $('#otBody').html('<tr><td colspan="11" style="padding:16px;color:#8a6d45;">按「搜尋」開始</td></tr>');
  $('#otTally').text(''); $('#otRange').text('');
  openMask('mkOutside');
  $('#otGo').click();
});
$('#otGo').on('click',function(){
  if(!sheet) return;
  $('#otBody').html('<tr><td colspan="11" style="padding:16px;"><i class="fa fa-spinner fa-spin"></i> 搜尋中…</td></tr>');
  $.post(API+'?action=recon_outside',{side:SIDE, party_id:sheet.party_id, billing_month:sheet.billing_month,
                                      kw:$('#otKw').val(), months:$('#otSpan').val()},function(r){
    if(!r.ok){ toast(esc(r.error||'搜尋失敗'), true); return; }
    otRows=r.rows||[];
    $('#otRange').text('搜尋範圍 '+dispDate(r.range[0])+' ~ '+dispDate(r.range[1])+'，本月份＝'+r.billing_month);
    paintOt();
  },'json').fail(function(){ toast('搜尋失敗', true); });
});
function paintOt(){
  if(!otRows.length){
    $('#otBody').html('<tr><td colspan="11" style="padding:16px;color:#8a6d45;">這個範圍內沒有「本月份以外」的單據</td></tr>');
    $('#otTally').text(''); return;
  }
  var h='';
  otRows.forEach(function(r,i){
    var lock = !!r.locked;
    h+='<tr class="'+(r._pick?'pick ':'')+(lock?'lock':'')+'" data-i="'+i+'">'
      +'<td>'+(lock?'<i class="fa fa-lock" title="已開發票，不可調整"></i>'
                   :'<input type="checkbox" class="ot-ck"'+(r._pick?' checked':'')+'>')+'</td>'
      +'<td class="l">'+esc(r.no||'')+'</td><td>'+esc(dispDate(r.date))+'</td>'
      +'<td>'+(r.kind==='return'?'<span class="pill k-ret">退貨</span>'
              :(r.kind==='process'?'<span class="pill k-proc">加工</span>':'<span class="pill k-ship">出貨</span>'))+'</td>'
      +'<td class="l">'+esc(r.product_id||'')+'</td><td class="l">'+esc((r.spec||'').substr(0,20))+'</td>'
      +'<td class="r">'+nf(r.qty)+'</td><td class="r">'+esc(pnum(r.unit_price))+'</td>'
      +'<td class="r"><b>'+nf(r.amount)+'</b></td>'
      +'<td>'+esc(r.cur_month||'')+(r.is_override?' <span class="pill p-g">已指定</span>':'')+'</td>'
      +'<td>'+(lock?('已開票 '+esc(r.locked)):'可調整')+'</td></tr>';
  });
  $('#otBody').html(h);
  otTally();
}
function otTally(){
  var n=0,s=0;
  otRows.forEach(function(r){ if(r._pick && !r.locked){ n++; s+=Number(r.amount)||0; } });
  $('#otTally').html('已選 <b>'+n+'</b> 筆／合計 <b>'+nf(s)+'</b>');
  $('#otApply').prop('disabled', n===0);
}
$(document).on('change','#otBody .ot-ck',function(){
  var i=parseInt($(this).closest('tr').data('i'),10);
  otRows[i]._pick=this.checked;
  $(this).closest('tr').toggleClass('pick', this.checked);
  otTally();
});
$(document).on('click','#otBody td',function(e){
  if($(e.target).is('input')) return;
  var $ck=$(this).closest('tr').find('.ot-ck');
  if($ck.length) $ck.prop('checked', !$ck.prop('checked')).trigger('change');
});
$('#otAll').on('click',function(){
  var any=otRows.some(function(r){ return r._pick && !r.locked; });
  otRows.forEach(function(r){ r._pick = any?false:!r.locked; });
  paintOt();
});
$('#otApply').on('click',function(){
  var items=[];
  otRows.forEach(function(r){ if(r._pick && !r.locked) items.push({src_type:r.src_type, id:r.src_id, no:r.no}); });
  if(!items.length){ toast('請先勾選要帶入的單據', true); return; }
  if(!confirm('確定把這 '+items.length+' 筆的帳款月份改成 '+sheet.billing_month+'？\n\n只會改「帳款月份指定值」，不會動到出貨日期與金額。')) return;
  var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> 調整中…');
  $.post(API+'?action=recon_move_month',{csrf:CSRF, billing_month:sheet.billing_month, items:JSON.stringify(items)},function(r){
    $b.prop('disabled',false).html('<i class="fa fa-arrow-down"></i> 改成本月份並帶入');
    if(!r.ok){ toast(esc(r.error||'調整失敗'), true); return; }
    toast(esc(r.message)+(r.errors&&r.errors.length?('<br>'+r.errors.map(esc).join('<br>')):''), (r.failed>0));
    closeMask('mkOutside');
    loadSheet();          // 重新載入：底稿會列出「本月份有、底稿沒有」的差異讓使用者加入
  },'json').fail(function(x){
    $b.prop('disabled',false).html('<i class="fa fa-arrow-down"></i> 改成本月份並帶入');
    var m='調整失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true);
  });
});

/* ── 對象設定：不提供對帳單 ─────────────────────────────────────────── */
$('#btnPartyOpt').on('click',function(){
  if(!sheet){ toast('請先載入對帳單', true); return; }
  $('#poName').text(sheet.party_name+'（'+sheet.party_id+'）');
  $('#poNo').prop('checked', !!(OPT&&OPT.no_statement));
  $('#poNote').val((OPT&&OPT.note)||'');
  $('#poWho').html(OPT&&OPT.updated_at ? ('最後修改：'+esc(OPT.updated_by_name||'')+'　'+esc(dispDate(OPT.updated_at))) : '');
  openMask('mkParty');
});
$('#poOk').on('click',function(){
  $.post(API+'?action=recon_party_opt_save',{csrf:CSRF, side:SIDE, party_id:sheet.party_id,
        no_statement: $('#poNo').prop('checked')?1:0, note:$('#poNote').val()},function(r){
    if(!r.ok){ toast(esc(r.error||'儲存失敗'), true); return; }
    OPT=r.opt||OPT; closeMask('mkParty'); toast(esc(r.message)); updateStat(); loadParties();
  },'json').fail(function(x){
    var m='儲存失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true);
  });
});

/* ── 模組設定（僅會計管理員）：預設開關＋AS 文件綁定 ────────────────── */
var AS_DOCS=[], AS_CUR=0;
$('#btnReconSet').on('click',function(){
  $('#stDrag').prop('checked', !!PREF.drag_default);
  $('#stAuto').prop('checked', !!PREF.autosort_default);
  $.getJSON(API,{action:'recon_asdoc_meta'},function(r){
    if(!r.ok) return;
    AS_DOCS=r.docs||[]; AS_CUR=r.current||0;
    $('#asdocShow').text(r.label||'尚未綁定');
  });
  openMask('mkSet');
});
$('#btnAsPick').on('click',function(){
  if(!window.EGAsDoc){ toast('AS 文件選擇器未載入', true); return; }
  EGAsDoc.open({docs:AS_DOCS, current:AS_CUR, title:'對帳單的 AS 文件編號綁定', onSave:function(id, doc){
    $.post(API+'?action=recon_asdoc_save',{csrf:CSRF, as_doc_id:id},function(r){
      if(!r.ok){ toast(esc(r.error||'設定失敗'), true); return; }
      AS_CUR=id;
      $('#asdocShow').text(id? (EGAsDoc.label? EGAsDoc.label(doc) : ((doc&&doc.doc_no)||'')) : '尚未綁定');
      toast(esc(r.message));
    },'json').fail(function(){ toast('設定失敗', true); });
  }});
});
$('#btnAsClear').on('click',function(){
  if(!confirm('確定清除 AS 文件綁定？列印時頁尾將不再印文件編號。')) return;
  $.post(API+'?action=recon_asdoc_save',{csrf:CSRF, as_doc_id:0},function(r){
    if(!r.ok){ toast(esc(r.error||'設定失敗'), true); return; }
    AS_CUR=0; $('#asdocShow').text('尚未綁定'); toast(esc(r.message));
  },'json').fail(function(){ toast('設定失敗', true); });
});
$('#setOk').on('click',function(){
  $.post(API+'?action=recon_pref_save',{csrf:CSRF,
        drag_default: $('#stDrag').prop('checked')?1:0,
        autosort_default: $('#stAuto').prop('checked')?1:0},function(r){
    if(!r.ok){ toast(esc(r.error||'儲存失敗'), true); return; }
    PREF=r.pref||PREF; closeMask('mkSet'); toast(esc(r.message));
  },'json').fail(function(x){
    var m='儲存失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} toast(esc(m), true);
  });
});

/* ── 使用說明（鐵律7）────────────────────────────────────────────────── */
$('#btnPageHelp').on('click',function(){ openMask('helpUseMask'); });

/* ── 列印：正式對帳單版面（ai-rules/16），不是把網頁直接印下來 ───────── */
function printSheet(){
  if(!sheet || !lines.length){ toast('請先載入對帳單', true); return; }
  $.getJSON(API,{action:'recon_print_meta', side:SIDE, party_id:sheet.party_id,
                 billing_month:sheet.billing_month},function(m){
    if(!m.ok){ toast(esc(m.error||'取得列印資訊失敗'), true); return; }
    if(window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(function(){ doPrint(m); });
    else doPrint(m);
  }).fail(function(){ toast('取得列印資訊失敗', true); });
}
function doPrint(m){
  var isAp = (SIDE==='ap');
  var title = m.as_doc_name || (isAp ? '應付帳款對帳單' : '應收帳款對帳單');
  var pk = parentKeys();
  var t  = totals();
  var noStmt = !!(OPT && OPT.no_statement);
  // 以畫面上輸入的金額為準：剛組出來還沒暫存的底稿 sheet.their_total 是 null，
  // 拿它一起判斷會變成「打了紙本金額卻印不出來」
  var their  = (noStmt || $('#theirTotal').val()==='') ? null : Number($('#theirTotal').val());
  var diff   = (their===null) ? null : Math.round((their - t.net)*100)/100;

  // 顯示順序與畫面一致；拆分父列只是容器不印（金額由子列代表）
  var ord=[];
  orderedTops().forEach(function(l){
    ord.push(l);
    lines.filter(function(c){ return c.split_parent_key===l.client_key; })
         .sort(function(a,b){ return (a.split_seq||0)-(b.split_seq||0); })
         .forEach(function(c){ ord.push(c); });
  });
  ord = ord.filter(function(l){ return !pk[l.client_key]; });

  var cols = isAp
    ? ['項次','日期','單號','製令','料號','製程','數量','單價','金額','備註']
    : ['項次','日期','單號','類別','料號','品名規格','數量','單價','金額','備註'];
  var wid  = isAp ? [4,8,12,11,13,15,7,7,9,14] : [4,8,12,6,13,17,7,7,9,17];

  var body='', n=0, gSum={}, seen={};
  ord.forEach(function(l,idx){
    n++;
    var q = (l.adj_qty!==null&&l.adj_qty!=='') ? l.adj_qty : l.orig_qty;
    var p = (l.adj_price!==null&&l.adj_price!=='') ? l.adj_price : l.orig_price;
    var a = amtOf(l);
    var memo=[];
    if(l.memo) memo.push(l.memo);
    if(l.adj_month && l.adj_month!==sheet.billing_month) memo.push('列入 '+l.adj_month);
    if(l.group_no) memo.push('併計組'+l.group_no);
    if(l.split_parent_key) memo.push('分段'+(l.split_seq||''));
    body+='<tr>'
      +'<td>'+n+'</td><td>'+esc(dispDate(l.doc_date))+'</td><td class="tl">'+esc(l.doc_no||'')+'</td>'
      +(isAp ? '<td class="tl">'+esc(l.bom||'')+'</td>' : '<td>'+esc(kindText(l))+'</td>')
      +'<td class="tl">'+esc(l.product_id||'')+'</td>'
      +'<td class="tl">'+esc(l.spec||'')+'</td>'
      +'<td class="tr">'+(q===null||q===''?'':nf(q))+'</td>'
      +'<td class="tr">'+esc(pnum(p))+'</td>'
      +'<td class="tr"><b>'+nf(a)+'</b></td>'
      +'<td class="tl">'+esc(memo.join('；'))+'</td></tr>';
    if(l.group_no){
      gSum[l.group_no]=(gSum[l.group_no]||0)+a;
      var next=ord[idx+1];
      if(!next || next.group_no!==l.group_no){
        body+='<tr class="gsum"><td colspan="8" class="tr">併計組 '+esc(l.group_no)+' 小計</td>'
             +'<td class="tr"><b>'+nf(gSum[l.group_no])+'</b></td><td></td></tr>';
      }
    }
  });

  var stampDate = (sheet.status==='confirmed' && sheet.confirmed_at)
                  ? String(sheet.confirmed_at).substr(0,10) : new Date().toISOString().substr(0,10);
  var stampHtml = (window.EGStamp)
      ? EGStamp.stamp((sheet.confirmed_by_name || (ME&&ME.name) || ''), dispDate(stampDate), false) : '';

  var head =
     '<div class="pt-co">'+esc(m.company||'')+'</div>'
    +'<div class="pt-ti">'+esc(title)+'</div>'
    +'<table class="p-meta"><colgroup><col style="width:11%"><col style="width:28%"><col style="width:11%">'
    +'<col style="width:17%"><col style="width:11%"><col style="width:22%"></colgroup><tbody>'
    +'<tr><th>'+(isAp?'廠商':'客戶')+'</th><td>'+esc((m.party&&(m.party.full_name||m.party.name))||sheet.party_name)+'</td>'
    +'<th>統一編號</th><td>'+esc((m.party&&m.party.tax_id)||'')+'</td>'
    +'<th>帳款月份</th><td><b>'+esc(sheet.billing_month)+'</b></td></tr>'
    +'<tr><th>地址</th><td>'+esc((m.party&&m.party.address)||'')+'</td>'
    +'<th>列印日期</th><td>'+esc(dispDate(new Date().toISOString().substr(0,10)))+'</td>'
    +'<th>狀態</th><td>'+esc(ST_LABEL[sheet.status]||sheet.status)+'</td></tr>'
    +'</tbody></table>';

  var sumRows =
     '<tr><th>未稅合計</th><td class="tr">'+nf(t.net)+'</td></tr>'
    +'<tr><th>營業稅</th><td class="tr">'+nf(t.tax)+'</td></tr>'
    +'<tr><th>含稅總計</th><td class="tr"><b>'+nf(t.total)+'</b></td></tr>'
    + (noStmt
        ? '<tr><th>對帳方式</th><td class="tr">本'+(isAp?'廠商':'客戶')+'不提供對帳單，以我方出貨單認列</td></tr>'
        : (their===null ? ''
           : '<tr><th>對方合計</th><td class="tr">'+nf(their)+'</td></tr>'
            +'<tr><th>差額</th><td class="tr"><b>'+nf(diff)+'</b></td></tr>'));

  var foot =
     '<div class="p-bot">'
    +'<table class="p-sum"><colgroup><col style="width:52%"><col style="width:48%"></colgroup><tbody>'+sumRows+'</tbody></table>'
    +'<table class="p-sign"><colgroup><col style="width:33.33%"><col style="width:33.33%"><col style="width:33.34%"></colgroup>'
    +'<thead><tr><th>'+(isAp?'廠商':'客戶')+'確認</th><th>覆核</th><th>製表</th></tr></thead>'
    +'<tbody><tr><td class="sg"></td><td class="sg"></td><td class="sg">'+stampHtml+'</td></tr></tbody></table>'
    +'</div>'
    +'<div class="p-note">本對帳單金額為未稅，如與貴公司帳載不符，請於收到後七日內聯繫本公司會計，逾期視同無誤。</div>';

  var colg='<colgroup>'+wid.map(function(w){ return '<col style="width:'+w+'%">'; }).join('')+'</colgroup>';
  var tbl='<table class="p-tb">'+colg+'<thead><tr>'+cols.map(function(c){ return '<th>'+c+'</th>'; }).join('')
        +'</tr></thead><tbody>'+body+'</tbody></table>';

  var asTxt = String(m.as_doc_no||'').replace(/['\\]/g,'');
  var css = 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;margin:0;color:#222;'
      + '-webkit-print-color-adjust:exact;print-color-adjust:exact;padding:0;}'
      + '*{box-sizing:border-box;}'
      + '.pt-co{text-align:center;font-size:22px;font-weight:bold;letter-spacing:2px;}'
      + '.pt-ti{text-align:center;font-size:16px;font-weight:bold;margin:2px 0 8px;}'
      + 'table{width:100%;max-width:100%;table-layout:fixed;border-collapse:collapse;}'
      + 'table.p-meta{font-size:11px;margin-bottom:6px;}'
      + 'table.p-meta th,table.p-meta td{border:1px solid #666;padding:3px 6px;text-align:left;'
      + 'overflow-wrap:break-word;word-break:break-word;}'
      + 'table.p-meta th{background:#f3ead6;white-space:nowrap;}'
      + 'table.p-tb{font-size:11px;}'
      + 'table.p-tb thead{display:table-header-group;}'
      + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 5px;text-align:center;'
      + 'overflow-wrap:break-word;word-break:break-word;}'
      + 'table.p-tb thead th{background:#f3ead6;}'
      + 'table.p-tb td.tl{text-align:left;} table.p-tb td.tr{text-align:right;}'
      + 'table.p-tb tr{break-inside:avoid;page-break-inside:avoid;}'
      + 'table.p-tb tr.gsum td{background:#faf1e2;font-style:italic;}'
      + '.p-bot{display:flex;gap:10px;margin-top:8px;break-inside:avoid;page-break-inside:avoid;}'
      + 'table.p-sum{font-size:12px;width:42%;}'
      + 'table.p-sum th,table.p-sum td{border:1px solid #666;padding:3px 7px;}'
      + 'table.p-sum th{background:#f3ead6;text-align:left;white-space:nowrap;}'
      + 'table.p-sum td.tr{text-align:right;}'
      + 'table.p-sign{font-size:12px;width:58%;}'
      + 'table.p-sign th,table.p-sign td{border:1px solid #666;padding:3px;text-align:center;}'
      + 'table.p-sign th{background:#f3ead6;}'
      + 'table.p-sign td.sg{height:26mm;vertical-align:middle;}'
      + '.p-note{font-size:10px;color:#555;margin-top:5px;line-height:1.6;overflow-wrap:break-word;}'
      // 圖章（列印視窗拿不到 eg_stamp.js 注入的樣式，必須自己寫齊；ai-rules/18 鐵則6）
      + '.stamp-wrap{display:inline-block;text-align:center;margin:2px 0;}'
      + '.stamp-wrap .stamp-title{display:block;font-size:11px;color:#999;}'
      + '.stamp-wrap svg{-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + '.stamp-wrap svg.car-stamp{width:91px;height:91px;}'
      + '.stamp-wrap.stamp-fill{height:auto !important;display:inline-block;}'
      + '@page{size:A4 landscape;margin:12mm 8mm 16mm;'
      + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
      + '}';

  var w=window.open('','_blank');
  if(!w){ toast('請允許彈出視窗以列印', true); return; }
  // <!DOCTYPE html> 不可省：漏了會落入 Quirks Mode，scrollHeight 量不準、單頁也會誤印頁碼（ai-rules/16 三之二）
  w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
    + esc(title)+' '+esc(sheet.party_name)+' '+esc(sheet.billing_month)+'</title>'
    + '<style>'+css+'</style></head><body>'+head+tbl+foot
    + '<scr'+'ipt>window.onload=function(){'
    + 'var onePage=(210-28)*96/25.4;'
    + 'if(document.body.scrollHeight>onePage*0.92){'
    + 'var st=document.createElement(\'style\');'
    + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
    + 'document.head.appendChild(st);}'
    + 'setTimeout(function(){window.print();},250);};</scr'+'ipt></body></html>');
  w.document.close(); w.focus();

  // 列印紀錄（ai-rules/23 鐵則：會列印的頁面一律留下紀錄）
  if(window.EGPrintLog){
    EGPrintLog.record({source:'acc_recon', doc_kind:'form',
      doc_name: title+' '+sheet.party_name+' '+sheet.billing_month,
      ref_table:'acc_recon_sheet', ref_id: sheet.sheet_id||0,
      note:(isAp?'應付':'應收')+'／'+ord.length+' 列／未稅 '+Math.round(t.net)});
  }
}

})(jQuery);
</script>
</body>
</html>
