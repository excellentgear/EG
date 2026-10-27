<?php
/**
 * 品質記錄一覽表（2-DC-01-03）：依表單性質一次設定保存年限（可重複執行；之後仍可在畫面逐列調整）。
 * 規則：永久保存＝履歷／人事基本資料；依客戶指定＝產品與製程、檢驗、訂單相關紀錄；
 *       5 年＝稽核／管理審查／供應商／教育訓練／客戶滿意度／人員考核等；其餘套用預設（3 年，最短）。
 * 備註欄不動、也不參與判斷。用法： php ai-rules/tools/set_qr_retention.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/_config.php';
require_once 'C:/MAMP/htdocs/EGsystem/src/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$has = $db->query("SHOW COLUMNS FROM as_quality_record_item LIKE 'retention_mode'")->fetch();
if (!$has) $db->exec("ALTER TABLE as_quality_record_item ADD COLUMN retention_mode VARCHAR(10) NOT NULL DEFAULT '' AFTER retention_years");

$permanent = ['2-PM-01-03','3-QA-01-02','2-TD-05-01','2-MM-01-18','2-MM-01-14','2-MM-01-08'];
$customer  = ['2-PD-01-01','2-PD-01-03','2-PD-01-07','2-QA-01-01','2-QA-01-02','2-QA-01-03','2-QA-01-04','2-QA-01-06',
              '2-QA-01-08','2-QA-01-09','2-QA-02-01','2-WH-01-04','2-SM-01-01','2-SM-01-02','2-SM-01-03','2-SM-01-05',
              '2-SM-01-06','2-SM-01-07','2-TD-01-01','2-TD-02-01','2-TD-03-01','2-TD-04-01','3-TD-01-02','3-TD-02-02',
              '3-SM-01-01','2-PH-03-01','2-PH-03-02','2-PH-02-01'];
$five = ['2-GM-01-01','2-GM-02-02','2-GM-02-03','2-GM-03-01','2-GM-04-01','2-GM-05-01','2-GM-05-02','2-GM-05-03',
         '2-GM-06-01','2-GM-06-02','2-GM-06-03','2-GM-06-04','2-GM-06-06','2-GM-06-07','2-GM-06-08',
         '2-DC-01-01','2-DC-02-01','2-DC-03-01','2-DC-03-02','2-DC-05-01',
         '2-PH-01-01','2-PH-01-02','2-PH-01-04','2-PH-01-05','3-OB-01-01','3-OB-01-02','3-OB-01-03','3-OB-01-04','3-OB-01-05',
         '3-QA-01-01','3-QA-01-06','2-PM-01-02','2-TD-05-02',
         '2-MM-01-01','2-MM-01-02','2-MM-01-03','2-MM-01-04','2-MM-01-05','2-MM-01-09','2-MM-01-10','2-MM-01-11',
         '2-MM-01-15','2-MM-01-16','2-MM-01-17','2-MM-02-01','2-MM-03-01','2-MM-04-01','2-MM-04-02','2-MM-05-01',
         '3-MM-02-01','3-MM-02-02','3-MM-02-03','3-MM-02-04','3-MM-02-05','3-MM-02-06',
         '2-SM-02-01','2-SM-02-02','2-SM-02-03','2-SM-02-04','3-GM-01-01','3-GM-01-02','3-GM-01-03'];

$up = $db->prepare("UPDATE as_quality_record_item i JOIN as_document d ON d.id=i.doc_id
                    SET i.retention_mode=?, i.retention_years=? WHERE d.doc_no=?");
$n = ['permanent'=>0,'customer'=>0,'years5'=>0,'default'=>0];
$all = $db->query("SELECT d.doc_no FROM as_quality_record_item i JOIN as_document d ON d.id=i.doc_id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($all as $no) {
    if (in_array($no, $permanent, true))     { $up->execute(['permanent', null, $no]); $n['permanent']++; }
    elseif (in_array($no, $customer, true))  { $up->execute(['customer', null, $no]);  $n['customer']++; }
    elseif (in_array($no, $five, true))      { $up->execute(['years', 5, $no]);        $n['years5']++; }
    else                                     { $up->execute(['', null, $no]);          $n['default']++; }
}
print_r($n);
