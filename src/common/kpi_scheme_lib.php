<?php
/**
 * kpi_scheme_lib.php — 「KPI 新方案（草案）」的唯一登記表與試算（2026-10-02）
 *
 * 為什麼另外開一支、而不是直接改 kpi_as_indicator：
 *   使用者要先「看了再決定要不要改」，而 kpi_as_indicator / kpi_as_indicator_year 是
 *   2-GM-04-01 正在跑的正式資料（2026 年度九個月的快照都在裡面）。草案階段一旦寫進去，
 *   正式 KPI 表就會多出一堆還沒拍板的列，年度鎖定／月快照／佐證附件也會跟著長出來。
 *   所以草案階段：方案定義放這裡（PHP 唯一登記表）、數字一律「即時試算、不寫快照」，
 *   等使用者拍板後再一次性搬進 kpi_as_indicator（屆時這支只剩對照用途）。
 *
 * 鐵則：
 *   ① 既有指標的數字一律讀正式表的月快照（kpi_as_monthly_value），不自己另算一份——
 *      另算一份就會出現「草案頁 85%、正式頁 83%」這種沒人查得出原因的落差。
 *   ② 新指標的試算一律呼叫既有共用庫（工作日走 kpi_as_workdays_inclusive、回廠日走
 *      vendor_kpi_lib 的 vkRdSQL/vkBackJoin、不合格品走 qa_ncr_lib、AS 文件排程走
 *      asdoc_schedule_lib），不在這裡重寫一套判定，否則正式上線數字會跟草案對不起來。
 *   ③ 這支檔案**不寫入任何資料**（全檔沒有 INSERT/UPDATE/DELETE），純讀取與計算。
 */

require_once __DIR__ . '/kpi_as_lib.php';

/** 草案版次（顯示在頁面與列印版上；改方案時一併改） */
function kpi_scheme_version(): string { return '2026-10-02 草案 v1'; }

/** 試算用的年度（拿哪一年的真實資料當樣本） */
function kpi_scheme_sample_year(): int { return 2026; }

/* ============================================================
 * 一、流程圖八大區塊（對應「附件C 品質管理系統流程圖」）
 * ============================================================ */
function kpi_scheme_blocks(): array {
    return [
        'COP01' => ['name'=>'客戶需求管理 ＋ 客戶滿意管理',
                    'proc'=>'合約訂單審查管理程序／詢價·報價／產品需求審查／客戶滿意度管理程序',
                    'kind'=>'COP'],
        'COP02' => ['name'=>'新產品與製程工程管理程序',
                    'proc'=>'製程開發·專案管理／工程變更管理／產品安全／仿冒零件防治／風險管理',
                    'kind'=>'COP'],
        'COP03' => ['name'=>'生產管理流程',
                    'proc'=>'生產管理程序／委外加工作業程序／首件檢驗／檢驗與測試／不合格品管理程序',
                    'kind'=>'COP'],
        'COP04' => ['name'=>'原物料管理程序',
                    'proc'=>'採購管理辦法／檢驗與測試管理程序／倉儲出貨管理程序／供應商管理程序',
                    'kind'=>'COP'],
        'SP01'  => ['name'=>'資源管理流程',
                    'proc'=>'人力資源管理／基礎設施管理／環境管理程序／工安管理程序',
                    'kind'=>'SP'],
        'SP02'  => ['name'=>'品管輔助管理流程',
                    'proc'=>'文件與紀錄管理程序／檢測儀器校驗管理程序',
                    'kind'=>'SP'],
        'MP01'  => ['name'=>'經營管理流程',
                    'proc'=>'管理責任與權限／績效衡量與管控程序／風險管理／內部稽核／問題分析與解決／管理審查',
                    'kind'=>'MP'],
        'MP02'  => ['name'=>'持續改善管理程序',
                    'proc'=>'矯正與預防措施管理程序',
                    'kind'=>'MP'],
    ];
}

/** 狀態籤（顯示用）：[短標籤, 說明] */
function kpi_scheme_status_label(string $s): array {
    $m = [
        'keep'   => ['沿用',       '既有指標，口徑不變'],
        'move'   => ['改負責部門', '指標不變，只換負責單位'],
        'retune' => ['改口徑',     '既有指標，目標或計算範圍要調整'],
        'new'    => ['新增',       '本次新提案'],
        'watch'  => ['觀察期',     '資料量還不夠，先不訂目標'],
        'warn'   => ['待補資料',   '算得出來，但來源幾乎是空的'],
    ];
    return $m[$s] ?? [$s, ''];
}

/** 資料來源標籤 */
function kpi_scheme_src_label(string $s): string {
    $m = ['auto'=>'全自動', 'semi'=>'半自動', 'manual'=>'人工填寫'];
    return $m[$s] ?? $s;
}

/* ============================================================
 * 二、新方案指標（唯一登記表）
 *   dept  ＝ department.id（顯示時即時查名稱，不寫死部門名＝鐵律4）
 *   src   ＝ auto 全自動／semi 半自動（部分欄位要人填）／manual 人工
 *   calc  ＝ ['existing', 既有指標 item_no]   讀正式表的月快照
 *           ['preview',  函式名, 參數陣列]    即時試算（唯讀）
 *           ['none']                          沒有資料來源，純人工
 * ============================================================ */
function kpi_scheme_items(): array {
    return [
    /* ---------- COP01 客戶需求／客戶滿意（業務課=12） ---------- */
    ['code'=>'order_ontime','block'=>'COP01','name'=>'準時出貨率','dept'=>12,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>90,'unit'=>'%',
     'src'=>'auto','status'=>'move','calc'=>['existing',8],
     'basis'=>'分母＝當月交期的訂單筆數（排除已取消）；分子＝出貨日 ≤ 交期＋寬限工作天者。未交判定方式與寬限天數沿用既有年度設定。',
     'note'=>'稽核老師指出這項的責任在業務端，故由生管組改掛業務課。計算完全不變，只改負責部門——正式上線只要改一個設定值，程式零改動。'],

    ['code'=>'order_review','block'=>'COP01','name'=>'訂單審查及時率','dept'=>12,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>90,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_order_review',['days'=>2]],
     'basis'=>'分母＝當月下單（Order_date）的訂單筆數，排除已取消（Order_status=6）；分子＝「接單移轉設計」（ateGet）距下單日 ≤ 2 個工作日者。工作日認定與「出圖準時率」同一套（行事曆 evenement）。',
     'note'=>'對應流程圖 COP01 的「合約訂單審查管理程序」——既有的「出圖準時率」管的是設計→生管那一段，接單→移轉設計這一段目前完全沒有指標。'
           . '⚠ 但試算出來每月都是 95~100%，查證後原因是：2026 年 2,983 張訂單裡有 2,771 張（93%）的 ateGet 與下單日是**同一天**，'
           . '也就是業務多半在建單當下就一起按了「移轉設計」，這個欄位實際上是建單流程的一部分、不是一個獨立的審查動作。'
           . '所以這項指標以目前的作業習慣**量不出東西**（門檻改成 1 天也還是 93%）。'
           . '要有鑑別度有兩條路：① 改口徑成「客戶下單日 → 系統建單日」（但系統沒有存客戶下單日，要新增欄位）'
           . '② 改用「訂單審查完整率」＝檢查交期·數量·單價·製程是否齊備，而不是看時間。**這一項請你決定要改口徑還是拿掉。**'],

    ['code'=>'quote_to_order','block'=>'COP01','name'=>'報價單接單率','dept'=>12,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>70,'unit'=>'%',
     'src'=>'auto','status'=>'keep','calc'=>['existing',4],
     'basis'=>'分母＝當月報價單數；分子＝其中報價單號已被訂單引用者。',
     'note'=>'對應「詢價／報價」。'],

    ['code'=>'complaint_rate','block'=>'COP01','name'=>'客訴件數','dept'=>12,
     'freq'=>'monthly','vtype'=>'count','dir'=>'lte','target'=>2,'unit'=>'件',
     'src'=>'auto','status'=>'keep','calc'=>['existing',1],
     'basis'=>'當月客退明細筆數（可依退貨性質只計入真正的客訴）。',
     'note'=>'對應「客戶滿意管理流程」。'],

    ['code'=>'order_target','block'=>'COP01','name'=>'月份受訂目標達成率','dept'=>12,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>85,'unit'=>'%',
     'src'=>'auto','status'=>'keep','calc'=>['existing',2],
     'basis'=>'當月接單金額 ÷ 該月受訂目標金額。',
     'note'=>''],

    ['code'=>'shipping_target','block'=>'COP01','name'=>'月銷貨額達成率','dept'=>12,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>85,'unit'=>'%',
     'src'=>'auto','status'=>'keep','calc'=>['existing',3],
     'basis'=>'當月出貨金額 ÷ 該月銷貨目標金額。',
     'note'=>''],

    ['code'=>'cust_satis','block'=>'COP01','name'=>'客戶滿意度','dept'=>12,
     'freq'=>'quarterly','vtype'=>'score','dir'=>'gte','target'=>8,'unit'=>'分',
     'src'=>'semi','status'=>'retune','calc'=>['existing',5],
     'basis'=>'準交率／退貨率／客戶開立異常單件數三項由系統自動算（客戶滿意度模組的 cs_auto_metrics 已寫好）；技術·服務·價格三項由 2-SM-02-02 問卷填入。平均只平均「有填的項目」，留白＝尚未填，不是 0 分。',
     'note'=>'原本是「每年一次、純人工」，2026 一格都沒填過。建議改季、改半自動。⚠ 客戶滿意度模組的三張表目前都是 0 筆，要先跑過一次問卷才有完整分數。'],

    /* ---------- COP02 新產品與製程工程（技術課=1） ---------- */
    ['code'=>'drawing_ontime','block'=>'COP02','name'=>'出圖準時率','dept'=>1,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>80,'unit'=>'%',
     'src'=>'auto','status'=>'keep','calc'=>['existing',11],
     'basis'=>'接單移轉設計 → 設計移轉生管 ≤N 工作日為準時。',
     'note'=>'對應「製程開發／專案管理程序」。'],

    ['code'=>'project_fai','block'=>'COP02','name'=>'專案首樣（FAI）過件率','dept'=>1,
     'freq'=>'halfyear','vtype'=>'percent','dir'=>'gte','target'=>null,'unit'=>'%',
     'src'=>'auto','status'=>'watch','calc'=>['existing',22],
     'basis'=>'半年批次：分母＝該半年內已判定的首樣送件；分子＝判定為通過者。尚未判定的不列入分母。',
     'note'=>'AS9100 的絕對重點，建議保留。⚠ 但目前只有 2 筆送樣、專案只有 3 個，母體太小，先累積資料再訂目標。'],

    ['code'=>'dev_eval_lead','block'=>'COP02','name'=>'產品開發評估完成時效','dept'=>1,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>85,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_dev_eval',['days'=>10]],
     'basis'=>'分母＝當月填表（fill_date）的產品開發評估表；分子＝其中在 10 個工作日內完成總經理決行（closed_at）者。天數可調。',
     'note'=>'對應「製程開發／專案管理程序」。2026 年有 41 張評估表（全庫 95 張）。'
           . '⚠ 但試算每月都是 100%，查證後原因是：這 41 張的 closed_at 與 fill_date **全部是同一天**，'
           . '研判是用「全部自動簽核」補登歷史紙本、或當天一次做完，所以時效永遠是 0 天。'
           . '這項要有意義，得等現場真的改成「線上填表→六部門逐一簽→決行」的流程跑一陣子；'
           . '**建議先列為觀察期不訂目標**，或改成「評估表涵蓋率」＝當月新開發料號裡有做評估表的比例。'],

    ['code'=>'type_ctrl','block'=>'COP02','name'=>'型態識別文件確認率','dept'=>1,
     'freq'=>'quarterly','vtype'=>'percent','dir'=>'gte','target'=>30,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_type_ctrl',[]],
     'basis'=>'分母＝已建立的型態識別文件管制表（未刪除）；分子＝確認狀態為「已確認」者。這是「現況快照」，每季季底看一次。',
     'note'=>'對應 AS9100 的型態管理（configuration management），稽核必問。⚠ 現況 380 份只有 4 份已確認＝約 1%，訂目標之前請先確認是「作業習慣還沒建立」還是「本來就不必每一份都確認」。'],

    /* ---------- COP03 生產管理（生產課=9／生管組=6／品管課=2） ---------- */
    ['code'=>'inhouse_ontime','block'=>'COP03','name'=>'廠內關鍵製程準交率','dept'=>9,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>85,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_inhouse_ontime',['procs'=>[12,4,34,27],'tol'=>3]],
     'basis'=>'分母＝當月發包、且已到容忍期的廠內關鍵製程筆數（廠內＝maker_list.internal=1）；分子＝回廠日 ≤ 發包日＋3 個容忍工作天者。回廠日取「生管登錄回廠日／製程移轉憑單單號日期／QC 檢驗日」三者最早，與「外包廠商績效」頁同一套判定。',
     'note'=>'取代原本對外包廠商的要求。⚠ 兩個提醒：① 插齒 2026 只有 69 張製令、報工 0 筆，做不出指標，故預設範圍設為齒研＋滾齒（含粗滾·精滾）；② 現有「外包廠商績效」頁把超正齒研等 11 家廠內單位列為例外廠商（那頁本來就是看外包的），所以正式上線要新開一個計算模組，不能直接重用該頁的函式。'],

    ['code'=>'ht_vendor_ontime','block'=>'COP03','name'=>'特殊製程（熱處理）委外準交率','dept'=>6,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>85,'unit'=>'%',
     'src'=>'auto','status'=>'retune','calc'=>['preview','kps_ht_ontime',['ptypes'=>[7],'tol'=>3]],
     'basis'=>'與上一項同一套判定，範圍改成「委外廠商（internal≠1）× 熱處理類製程（process_type_id=7）」。',
     'note'=>'由原「廠商準時交貨率」限縮而來。認證範圍的外包只有熱處理，但 AS9100 對特殊製程外包的管控是必考題，建議保留而不是整項刪掉。2026 年鑫光 525／長信 235／國鐽 132 筆，發包日覆蓋率 99%。'],

    ['code'=>'process_ng','block'=>'COP03','name'=>'關鍵製程不良率','dept'=>9,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>0.5,'unit'=>'%',
     'src'=>'auto','status'=>'retune','calc'=>['existing',15],
     'basis'=>'分子＝Σ當月報工 NG 數；分母＝Σ當月完成數，限指定製程類別。',
     'note'=>'原「齒研製程不良率」。⚠ 2026 實績 0.00~0.44%、目標卻是 ≤5%＝形同永遠達標，沒有管理訊號。建議目標收緊到 ≤0.5%（或改用 PPM 百萬件不良數）。'],

    ['code'=>'capacity_1','block'=>'COP03','name'=>'產能績效－創成','dept'=>9,
     'freq'=>'monthly','vtype'=>'rate','dir'=>'gte','target'=>15,'unit'=>'顆/小時',
     'src'=>'auto','status'=>'keep','calc'=>['existing',13],
     'basis'=>'Σ本月完成數量 ÷ Σ生產起訖工時（小時）。',
     'note'=>'原本沒有設負責部門，建議補上生產課。'],

    ['code'=>'capacity_2','block'=>'COP03','name'=>'產能績效－成型','dept'=>9,
     'freq'=>'monthly','vtype'=>'rate','dir'=>'gte','target'=>2,'unit'=>'顆/小時',
     'src'=>'auto','status'=>'keep','calc'=>['existing',14],
     'basis'=>'同上，機台範圍不同。',
     'note'=>''],

    ['code'=>'ncr_count','block'=>'COP03','name'=>'不合格品開立件數','dept'=>2,
     'freq'=>'monthly','vtype'=>'count','dir'=>'lte','target'=>40,'unit'=>'件',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_ncr_count',[]],
     'basis'=>'當月不合格品管制記錄表的開立件數。來源＝品質異常處理單＋異常矯正處理單＋客戶退貨＋QC 檢驗不良·特採四種，由 qa_ncr_lib 彙整（不另存一份）。',
     'note'=>'對應「不合格品管理程序」。2026 四來源合計 438 筆（客退 376／QC 不良 56／矯正 4／異常單 2），平均每月約 36 件，目標值待你定。⚠ 不合格品管制記錄表目前只啟用「品質異常處理單」一個來源，照設定撈 2026 全年只有 2 筆；本頁試算刻意四來源全開，才看得出量級。正式上線前要先到該頁設定把四個來源打開。'],

    ['code'=>'packing_ng','block'=>'COP03','name'=>'成品出貨不良率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>null,'unit'=>'%',
     'src'=>'auto','status'=>'watch','calc'=>['existing',17],
     'basis'=>'ΣNG 總數 ÷ Σ實際全檢數量。',
     'note'=>'⚠ 2026 年 1~7 月分母都是 0，8 月才 5 筆、9 月 72 筆＝剛開始用。先觀察半年再訂目標。'],

    /* ---------- COP04 原物料與客供料（品管課=2／倉管組=8） ---------- */
    ['code'=>'cust_material_ng','block'=>'COP04','name'=>'客供料點收檢驗不良率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>1,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_qc_by_proc',['procs'=>[138],'ngs'=>['ng','QQ']]],
     'basis'=>'分母＝當月「客供料」站別已判定的檢驗筆數；分子＝判定為驗退（ng）或特採（QQ）者。',
     'note'=>'取代原提案的「供應商評核合格率」（那項不在你們可控範圍）。這一項同時滿足 AS9100 8.5.3「客戶財產」的管控要求，也正好是你們「客供料加工」的核心模式。2026 已判定 1,015 筆（合格 1,012／驗退 2／特採 1）。'],

    ['code'=>'outsource_ng','block'=>'COP04','name'=>'委外回廠檢驗不良率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>2,'unit'=>'%',
     'src'=>'auto','status'=>'retune','calc'=>['preview','kps_qc_by_proc',['ptypes'=>[7],'ngs'=>['ng']]],
     'basis'=>'分母＝當月熱處理類委外回廠的檢驗筆數；分子＝判定為驗退者。',
     'note'=>'由原「進料檢驗不良率」拆出來。原指標把客供料點收與委外回廠混在同一個數字裡，拆開才看得出問題是出在客戶來料還是外包廠。正式上線只要在既有計算模組加一個「只計入這些製程」參數即可。'],

    ['code'=>'stock_accuracy','block'=>'COP04','name'=>'耗材／刀具盤點正確率','dept'=>8,
     'freq'=>'quarterly','vtype'=>'percent','dir'=>'gte','target'=>95,'unit'=>'%',
     'src'=>'auto','status'=>'retune','calc'=>['existing',10],
     'basis'=>'每季：分母＝該季已完成盤點的明細筆數；分子＝無差異筆數。',
     'note'=>'⚠ 待你決定要不要保留。你提到「沒有庫存」，但系統裡盤點其實很活躍：12 次盤點、每次約 1,480 筆明細，品項 1,522 筆（成品 1,242／半成品 204／刀具 63／檢量具 10／工具 3）。若確定成品不列入管理，建議把範圍限縮到刀具／耗材／油品／檢量具四類；若連耗材也不管，這項就一起停用。'],

    /* ---------- SP01 資源管理（管理課=13） ---------- */
    ['code'=>'training','block'=>'SP01','name'=>'人員教育訓練達成率','dept'=>13,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>95,'unit'=>'%',
     'src'=>'auto','status'=>'keep','calc'=>['existing',19],
     'basis'=>'分母＝當月計畫訓練場次（排除取消）；分子＝其中已完成場次。',
     'note'=>'對應「人力資源管理」。原本沒有設負責部門，建議補上管理課。已依你的意見取消「人員流動率」。⚠ 2026 年 1~7 月全部 100%（計畫幾場就做幾場，必然達標），8/9 月分母 0＝沒排課。建議改成「年度訓練時數達成率」（training_session 已有 hours／actual_hours 欄位）才有鑑別度——要改請告訴我。'],

    ['code'=>'safety','block'=>'SP01','name'=>'職業災害件數','dept'=>13,
     'freq'=>'monthly','vtype'=>'count','dir'=>'lte','target'=>0,'unit'=>'件',
     'src'=>'manual','status'=>'new','calc'=>['none'],
     'basis'=>'當月因公受傷需就醫或請假之件數，由管理課逐月填寫。',
     'note'=>'對應流程圖 SP01 的「工安管理程序」。系統沒有工安模組，只能人工填寫，但這是 AS9100 稽核必問項目，建議即使人工也要列。'],

    /* ---------- SP02 品管輔助（品管課=2／文管中心=14） ---------- */
    ['code'=>'calibration','block'=>'SP02','name'=>'量測儀器按時校驗率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>95,'unit'=>'%',
     'src'=>'auto','status'=>'warn','calc'=>['existing',18],
     'basis'=>'分母＝當月應校驗量具數；分子＝其中準時完成（校驗日 ≤ 到期日＋寬限）者。',
     'note'=>'⚠ **這一項是本次查證裡風險最高的**：系統裡有 80 支量具，但校驗紀錄表一筆都沒有建，'
           . '自動計算 2026 年九個月全部是 0/0；而畫面上看到的 95~100% 是**管理者逐月手動覆寫**上去的（8 個月都是覆寫）。'
           . '稽核老師只要問一句「這個數字怎麼來的、佐證在哪」就會變成缺失。'
           . '請決定：① 把校驗紀錄補進系統（最正確）② 先停用這一項 ③ 維持人工填寫但改標示為「人工」並附紙本佐證。'],

    ['code'=>'asdoc_update','block'=>'SP02','name'=>'AS 文件按時更新率','dept'=>14,
     'freq'=>'quarterly','vtype'=>'percent','dir'=>'gte','target'=>90,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_asdoc_update',[]],
     'basis'=>'分母＝該季已到期的 AS 文件排程點；分子＝已完成者。查不到完成紀錄的一律判「無法判定」、不計入分母（沿用 AS 文件排程模組既有規則：系統查不到 ≠ 沒做）。',
     'note'=>'對應「文件與紀錄管理程序」。AS 文件排程模組的推導函式已經寫好，直接接即可。⚠ 170 份文件只有 33 份設了固定週期（26 份每年／6 份每月／1 份每日），其餘 84 份標不定時、53 份未設，分母偏小，要先把更新頻率補齊。'],

    /* ---------- MP01 經營管理（總經理室=15） ---------- */
    ['code'=>'kpi_overall','block'=>'MP01','name'=>'全廠 KPI 總體達標率','dept'=>15,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>80,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_kpi_overall',[]],
     'basis'=>'分母＝當月有數值的指標數；分子＝其中達成目標者。達標與否一律呼叫 KPI 模組自己的判定函式（kpi_as_below_target），不另寫一套。',
     'note'=>'對應流程圖 MP01 的「績效衡量與管控程序」。零新資料來源——本表自己算自己，是最划算的一項，也是管理審查會議第一個要看的數字。已依你的意見拿掉「內部稽核計畫達成率」與「管理審查會議按時召開」（一年一次，不適合做常態指標）。目前試算是用現行 22 項算的，新方案上線後會自動改以新方案為母體。'],

    /* ---------- MP02 持續改善（品管課=2） ---------- */
    ['code'=>'car_ontime','block'=>'MP02','name'=>'矯正措施按時結案率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>null,'unit'=>'%',
     'src'=>'auto','status'=>'watch','calc'=>['preview','kps_car_ontime',[]],
     'basis'=>'分母＝矯正措施完成期限（correction_due）落在當月的異常矯正處理單；分子＝結案日 ≤ 該期限者。',
     'note'=>'對應「矯正與預防措施管理程序」，是 AS 評鑑時證明公司有在持續改善的最強證據。已依你的意見拿掉「異常重複發生件數」（判定太難）。⚠ 2026 全年只有 4 張矯正單，母體太小，建議前半年當觀察期不訂目標，先把開單習慣建立起來。'],
    ];
}

/** 建議停用（不再列入新方案） */
function kpi_scheme_dropped(): array {
    return [
        ['no'=>6, 'name'=>'廠商稽核按時執行率',
         'why'=>'外包只認證熱處理，耗材商不需稽核（你確認過）。對外包的管控改由新方案的「特殊製程（熱處理）委外準交率」承接。'],
        ['no'=>9, 'name'=>'發料錯誤件數',
         'why'=>'客供料做完就出貨，沒有發料作業（你確認過）。原本也沒有設負責部門、2026 是人工填寫。'],
        ['no'=>12,'name'=>'出圖正確性',
         'why'=>'純人工填件數，與「出圖準時率」管同一件事。若要保留，建議改接圖面變更紀錄（qc_drawing_change，目前 7 筆）或直接併入新增的「不合格品開立件數」。保留與否請你決定。'],
    ];
}

/** 待你決定（流程圖上沒有對應區塊，或資料面有疑慮） */
function kpi_scheme_pending(): array {
    return [
        ['no'=>20,'name'=>'應收帳款（票據）未收件數',
         'why'=>'流程圖上沒有財務流程區塊，且 2026 年一筆快照都沒有填過＝實際上沒有在用。要保留的話建議歸到 MP01 經營管理。'],
        ['no'=>21,'name'=>'明細分類帳（損益表）於期限內完成',
         'why'=>'同上，2026 年零填寫。'],
    ];
}

/** 上線前的先決條件（不先處理，指標一上線就是空白或假數字） */
function kpi_scheme_prereq(): array {
    return [
        ['t'=>'量測儀器校驗紀錄要先建',
         'd'=>'系統裡有 80 支量具，但校驗紀錄表一筆都沒有。不補的話「量測儀器按時校驗率」永遠是空白。'],
        ['t'=>'AS 文件更新頻率要補齊',
         'd'=>'170 份文件只有 33 份設了固定週期，其餘 137 份標不定時或未設，「AS 文件按時更新率」的分母會偏小、不具代表性。'],
        ['t'=>'不合格品管制記錄表要開啟四個來源',
         'd'=>'目前只啟用「品質異常處理單」一種，照設定撈 2026 全年只有 2 筆；四種全開是 438 筆。'],
        ['t'=>'採購到貨要上系統（或接受 COP04 只有 2~3 項）',
         'd'=>'請購單只有 2 筆、到貨登記表 0 筆，所以「刀具／耗材採購交期達成率」目前算不出來——這是作業流程要改，不是寫程式能解決的。'],
    ];
}

/* ============================================================
 * 三、試算（唯讀；不寫任何快照）
 * ============================================================ */

/**
 * 某指標的逐月值。
 * @return array|null [月 => ['v'=>值|null,'num'=>分子,'den'=>分母]]；整項無法試算回 null
 */
function kpi_scheme_preview(PDO $db, array $item, int $year): ?array {
    $calc = $item['calc'] ?? ['none'];
    $mode = $calc[0] ?? 'none';
    if ($mode === 'none') return null;

    if ($mode === 'existing') {
        return kps_from_snapshot($db, (int)($calc[1] ?? 0), $year);
    }
    if ($mode === 'preview') {
        $fn   = (string)($calc[1] ?? '');
        $args = (array)($calc[2] ?? []);
        if (!function_exists($fn)) return null;
        $curY = (int)date('Y'); $curM = (int)date('n');
        $out = [];
        foreach (kpi_as_months($item['freq']) as $m) {
            // 還沒發生的期間不試算——給了數字就是假數字
            if ($year > $curY || ($year === $curY && $m > $curM)) { $out[$m] = null; continue; }
            try { $out[$m] = $fn($db, $year, $m, $args); }
            catch (Throwable $e) { $out[$m] = null; }
        }
        return $out;
    }
    return null;
}

/** 既有指標：直接讀正式表的月快照（草案頁不另算一份，見檔頭鐵則①） */
function kps_from_snapshot(PDO $db, int $itemNo, int $year): ?array {
    if ($itemNo <= 0) return null;
    $st = $db->prepare("SELECT mv.month, mv.auto_value, mv.manual_value, mv.override_value,
                               mv.numerator, mv.denominator
                        FROM kpi_as_monthly_value mv
                        JOIN kpi_as_indicator i ON i.indicator_id=mv.indicator_id
                        WHERE i.item_no=? AND mv.year=?");
    $st->execute([$itemNo, $year]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // 值的來源要跟著帶出來：稽核時「這個數字怎麼來的」答不出來就是缺失。
        // 實測 2026 年有不少格是管理者覆寫的（例：量測儀器校驗率 8 個月全是覆寫、自動值是空的）。
        $v = $r['override_value']; $src = 'override';
        if ($v === null) { $v = $r['manual_value'];  $src = 'manual'; }
        if ($v === null) { $v = $r['auto_value'];    $src = 'auto'; }
        if ($v === null) $src = '';
        $out[(int)$r['month']] = ['v'   => ($v === null ? null : (float)$v),
                                  'num' => $r['numerator'],
                                  'den' => $r['denominator'],
                                  'src' => $src];
    }
    return $out;
}

/* ---------- COP01 訂單審查及時率 ---------- */
function kps_order_review(PDO $db, int $year, int $month, array $a): ?array {
    $days = max(1, (int)($a['days'] ?? 2));
    $ym = sprintf('%04d-%02d', $year, $month);
    $st = $db->prepare("SELECT Order_date, ateGet FROM order_track
                        WHERE DATE_FORMAT(Order_date,'%Y-%m')=?
                          AND (Order_status IS NULL OR Order_status<>6)");
    $st->execute([$ym]);
    $num = 0; $den = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $den++;
        if (empty($r['ateGet'])) continue;               // 還沒移轉設計＝審查尚未完成
        $d1 = substr((string)$r['Order_date'], 0, 10);
        $d2 = substr((string)$r['ateGet'], 0, 10);
        if ($d2 < $d1) { $num++; continue; }             // 先移轉、事後才補建訂單（既有資料常見），視為及時
        $wd = ($d1 === $d2) ? 1 : kpi_as_workdays_inclusive($db, $d1, $d2);
        if ($wd <= $days) $num++;
    }
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
}

/* ---------- COP02 產品開發評估完成時效 ---------- */
function kps_dev_eval(PDO $db, int $year, int $month, array $a): ?array {
    $days = max(1, (int)($a['days'] ?? 10));
    $ym = sprintf('%04d-%02d', $year, $month);
    $st = $db->prepare("SELECT fill_date, closed_at FROM td_dev_eval
                        WHERE is_deleted=0 AND DATE_FORMAT(fill_date,'%Y-%m')=?");
    $st->execute([$ym]);
    $num = 0; $den = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $den++;
        if (empty($r['closed_at'])) continue;            // 還沒完成決行
        $d1 = substr((string)$r['fill_date'], 0, 10);
        $d2 = substr((string)$r['closed_at'], 0, 10);
        if ($d2 < $d1) { $num++; continue; }
        $wd = ($d1 === $d2) ? 1 : kpi_as_workdays_inclusive($db, $d1, $d2);
        if ($wd <= $days) $num++;
    }
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
}

/* ---------- COP02 型態識別文件確認率（現況快照，季指標） ---------- */
function kps_type_ctrl(PDO $db, int $year, int $month, array $a): ?array {
    $den = (int)$db->query("SELECT COUNT(*) FROM type_id_ctrl_doc WHERE is_deleted=0")->fetchColumn();
    $num = (int)$db->query("SELECT COUNT(*) FROM type_id_ctrl_doc
                            WHERE is_deleted=0 AND review_status='confirmed'")->fetchColumn();
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
}

/* ---------- COP03 準交率（廠內／委外共用同一套判定） ----------
 * 判定與「外包廠商績效」頁完全相同：發包日＋容忍工作天＝截止日；回廠日取
 * 「生管登錄回廠日／製程移轉憑單單號日期／QC 檢驗日」三者最早（vendor_kpi_lib 的 vkRdSQL）。
 * 唯一的差別是範圍：那一頁把廠內單位列在例外廠商裡（它本來就是看外包的），
 * 所以這裡不吃那份例外清單，改用 internal 旗標與製程自行界定範圍。
 */
function kps_ontime_core(PDO $db, int $year, int $month, string $scope,
                         array $procNos, array $procTypes, int $tol): ?array {
    // 整年查一次、在 PHP 分月（逐月各發一次 LATERAL 查詢實測要 3.9 秒，整年一次只要 0.4 秒）
    $all = kps_ontime_year($db, $year, $scope, $procNos, $procTypes, $tol);
    return $all[$month] ?? ['v'=>null, 'num'=>0, 'den'=>0];
}

/** 某年度逐月的準交率（整年一次查完，依發包月份分組）；同一組條件只算一次 */
function kps_ontime_year(PDO $db, int $year, string $scope,
                         array $procNos, array $procTypes, int $tol): array {
    static $cache = [];
    $ck = $scope . '|' . implode(',', $procNos) . '|' . implode(',', $procTypes) . '|' . $tol . '|' . $year;
    if (isset($cache[$ck])) return $cache[$ck];

    require_once __DIR__ . '/vendor_kpi_lib.php';
    $ds = sprintf('%04d-01-01', $year);
    $de = sprintf('%04d-12-31', $year);
    $today = date('Y-m-d');

    $where = ["bi.outsource_date IS NOT NULL", "DATE(bi.outsource_date) BETWEEN ? AND ?"];
    $bind  = [$ds, $de];
    if ($scope === 'internal')     $where[] = "mk.internal=1";
    elseif ($scope === 'external') $where[] = "(mk.internal IS NULL OR mk.internal<>1)";
    if ($procNos) {
        $where[] = "bi.process_no IN (" . implode(',', array_fill(0, count($procNos), '?')) . ")";
        $bind = array_merge($bind, array_map('intval', $procNos));
    }
    if ($procTypes) {
        $where[] = "pn.process_type_id IN (" . implode(',', array_fill(0, count($procTypes), '?')) . ")";
        $bind = array_merge($bind, array_map('intval', $procTypes));
    }
    $sql = "SELECT DATE(bi.outsource_date) AS od, " . vkRdSQL('bi') . " AS rd
            FROM bom_ing bi
            LEFT JOIN maker_list mk ON mk.maker_id_no=bi.maker_id_no
            LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
            " . vkBackJoin('bi') . "
            WHERE " . implode(' AND ', $where);
    $st = $db->prepare($sql);
    $st->execute($bind);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    for ($m = 1; $m <= 12; $m++) $out[$m] = ['v'=>null, 'num'=>0, 'den'=>0];
    if (!$rows) return $cache[$ck] = $out;

    $maps   = loadWorkdayMaps($db, date('Y-m-d', strtotime($ds . ' -30 days')),
                                   date('Y-m-d', strtotime($de . ' +60 days')));
    $cutoff = subtractWorkdays($today, $tol, $maps);
    $dl = [];
    foreach ($rows as $r) {
        $od = (string)$r['od'];
        if ($od > $cutoff) continue;                     // 還沒到容忍期，不列入分母（與那一頁同一條規則）
        $m = (int)substr($od, 5, 2);
        if ($m < 1 || $m > 12) continue;
        $out[$m]['den']++;
        if (!isset($dl[$od])) $dl[$od] = calcDeadline($od, $tol, $maps);
        $rd = (string)($r['rd'] ?? '');
        if ($rd !== '' && $rd <= $dl[$od]) $out[$m]['num']++;
    }
    foreach ($out as $m => $c) {
        $out[$m]['v'] = $c['den'] > 0 ? $c['num'] / $c['den'] * 100 : null;
    }
    return $cache[$ck] = $out;
}
function kps_inhouse_ontime(PDO $db, int $year, int $month, array $a): ?array {
    return kps_ontime_core($db, $year, $month, 'internal',
                           (array)($a['procs'] ?? []), (array)($a['ptypes'] ?? []), (int)($a['tol'] ?? 3));
}
function kps_ht_ontime(PDO $db, int $year, int $month, array $a): ?array {
    return kps_ontime_core($db, $year, $month, 'external',
                           (array)($a['procs'] ?? []), (array)($a['ptypes'] ?? [7]), (int)($a['tol'] ?? 3));
}

/* ---------- COP04 依製程切分的點收／回廠檢驗不良率 ----------
 * 口徑與既有「進料檢驗不良率」完全相同（分母＝當月已判定的檢驗筆數），
 * 只多一個「限定哪些製程」的範圍；正式上線時在既有計算模組加一個參數即可。
 */
function kps_qc_by_proc(PDO $db, int $year, int $month, array $a): ?array {
    $all = kps_qc_year($db, $year, (array)($a['procs'] ?? []), (array)($a['ptypes'] ?? []),
                       (array)($a['ngs'] ?? ['ng']));
    return $all[$month] ?? ['v'=>null, 'num'=>0, 'den'=>0];
}

/** 某年度逐月的檢驗不良率（整年一次 GROUP BY month 查完） */
function kps_qc_year(PDO $db, int $year, array $procs, array $ptypes, array $ngs): array {
    if (!$ngs) $ngs = ['ng'];
    static $cache = [];
    $ck = implode(',', $procs) . '|' . implode(',', $ptypes) . '|' . implode(',', $ngs) . '|' . $year;
    if (isset($cache[$ck])) return $cache[$ck];

    $where = ["bi.QC_check_date IS NOT NULL", "YEAR(bi.QC_check_date)=?",
              "bi.QC_check IS NOT NULL", "bi.QC_check<>''"];
    $bind  = [$year];
    if ($procs) {
        $where[] = "bi.process_no IN (" . implode(',', array_fill(0, count($procs), '?')) . ")";
        $bind = array_merge($bind, array_map('intval', $procs));
    }
    if ($ptypes) {
        $where[] = "pn.process_type_id IN (" . implode(',', array_fill(0, count($ptypes), '?')) . ")";
        $bind = array_merge($bind, array_map('intval', $ptypes));
    }
    $ngIn = implode(',', array_fill(0, count($ngs), '?'));
    $sql = "SELECT MONTH(bi.QC_check_date) m, COUNT(*) den,
                   SUM(CASE WHEN bi.QC_check IN ($ngIn) THEN 1 ELSE 0 END) num
            FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
            WHERE " . implode(' AND ', $where) . " GROUP BY m";
    $st = $db->prepare($sql);
    $st->execute(array_merge($ngs, $bind));

    $out = [];
    for ($m = 1; $m <= 12; $m++) $out[$m] = ['v'=>null, 'num'=>0, 'den'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int)$r['m']; $den = (int)$r['den']; $num = (int)$r['num'];
        $out[$m] = ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
    }
    return $cache[$ck] = $out;
}

/* ---------- COP03 不合格品開立件數 ---------- */
function kps_ncr_count(PDO $db, int $year, int $month, array $a): ?array {
    require_once __DIR__ . '/qa_ncr_lib.php';
    static $cache = [];
    if (!isset($cache[$year])) {
        // 草案試算刻意把四個來源都打開（正式上線時以該模組的啟用設定為準）：
        // 目前只啟用「品質異常處理單」一種，照設定撈 2026 全年只有 2 筆，看不出這個指標的量級。
        $rows = ncr_rows($db, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year),
                         ['sources'=>['qa','car','ir','qc']]);
        $by = [];
        for ($m = 1; $m <= 12; $m++) $by[$m] = 0;
        foreach ($rows as $r) {
            $m = (int)substr((string)($r['insp_date'] ?? ''), 5, 2);
            if ($m >= 1 && $m <= 12) $by[$m]++;
        }
        $cache[$year] = $by;
    }
    $n = (int)($cache[$year][$month] ?? 0);
    return ['v'=>(float)$n, 'num'=>$n, 'den'=>null];
}

/* ---------- MP02 矯正措施按時結案率 ---------- */
function kps_car_ontime(PDO $db, int $year, int $month, array $a): ?array {
    $ym = sprintf('%04d-%02d', $year, $month);
    $st = $db->prepare("SELECT correction_due, close_date FROM car_order
                        WHERE correction_due IS NOT NULL AND DATE_FORMAT(correction_due,'%Y-%m')=?");
    $st->execute([$ym]);
    $num = 0; $den = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $den++;
        if (!empty($r['close_date'])
            && substr((string)$r['close_date'], 0, 10) <= substr((string)$r['correction_due'], 0, 10)) $num++;
    }
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
}

/* ---------- SP02 AS 文件按時更新率（季） ---------- */
function kps_asdoc_update(PDO $db, int $year, int $month, array $a): ?array {
    require_once __DIR__ . '/asdoc_schedule_lib.php';
    static $cache = [];
    if (!array_key_exists($year, $cache)) {
        try { asched_ensure($db); $cache[$year] = asched_plan($db, $year); }
        catch (Throwable $e) { $cache[$year] = null; }
    }
    $plan = $cache[$year];
    if (!$plan) return null;

    $q  = (int)ceil($month / 3);
    $ms = ($q - 1) * 3 + 1; $me = $q * 3;
    $num = 0; $den = 0;
    foreach (($plan['rows'] ?? []) as $r) {
        $m = (int)($r['due_month'] ?? 0);
        if ($m < $ms || $m > $me) continue;
        $state = (string)($r['state'] ?? '');
        // unknown（查不到完成紀錄）／nomonth（月份未定）一律不計入分母＝沿用該模組既有規則；
        // upcoming／due 是「還沒到期」，也不該列入「按時更新率」的分母。
        if ($state === 'done')         { $den++; $num++; }
        elseif ($state === 'overdue')  { $den++; }
    }
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
}

/* ---------- MP01 全廠 KPI 總體達標率 ---------- */
function kps_kpi_overall(PDO $db, int $year, int $month, array $a): ?array {
    $st = $db->prepare("SELECT i.indicator_id, y.target_direction, y.target_value
                        FROM kpi_as_indicator i
                        JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        WHERE i.is_active=1 AND y.is_active=1");
    $st->execute([$year]);
    $iys = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$iys) return ['v'=>null, 'num'=>0, 'den'=>0];

    $mv = $db->prepare("SELECT auto_value, manual_value, override_value FROM kpi_as_monthly_value
                        WHERE indicator_id=? AND year=? AND month=?");
    $num = 0; $den = 0;
    foreach ($iys as $iy) {
        $mv->execute([(int)$iy['indicator_id'], $year, $month]);
        $r = $mv->fetch(PDO::FETCH_ASSOC);
        if (!$r) continue;
        $v = $r['override_value'];
        if ($v === null) $v = $r['manual_value'];
        if ($v === null) $v = $r['auto_value'];
        if ($v === null) continue;                       // 沒有數值的不列入分母
        $den++;
        // 達標與否一律走 KPI 模組自己的判定，不在這裡另寫一套
        if (!kpi_as_below_target((float)$v, $iy)) $num++;
    }
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
}

/* ============================================================
 * 四、彙總（給頁面與列印版用）
 * ============================================================ */

/** 區塊 → 指標數；順便算出每個區塊的自動化比例 */
function kpi_scheme_block_stat(): array {
    $out = [];
    foreach (kpi_scheme_blocks() as $code => $b) $out[$code] = ['n'=>0, 'auto'=>0, 'new'=>0];
    foreach (kpi_scheme_items() as $it) {
        $c = $it['block'];
        if (!isset($out[$c])) continue;
        $out[$c]['n']++;
        if ($it['src'] === 'auto') $out[$c]['auto']++;
        if ($it['status'] === 'new') $out[$c]['new']++;
    }
    return $out;
}

/** 整體摘要 */
function kpi_scheme_summary(): array {
    $s = ['total'=>0, 'auto'=>0, 'semi'=>0, 'manual'=>0,
          'new'=>0, 'keep'=>0, 'retune'=>0, 'move'=>0, 'watch'=>0, 'warn'=>0];
    foreach (kpi_scheme_items() as $it) {
        $s['total']++;
        if (isset($s[$it['src']]))    $s[$it['src']]++;
        if (isset($s[$it['status']])) $s[$it['status']]++;
    }
    return $s;
}
