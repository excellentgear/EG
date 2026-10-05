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
 *   ② 新指標的試算一律呼叫既有共用庫（工作日走 kpi_as_workdays_inclusive、產能走
 *      kpi_as_lib 的 capacity_rate 計算模組、製程不良率走 process_ng_rate 計算模組），
 *      不在這裡重寫一套判定，否則正式上線數字會跟草案對不起來。
 *   ③ 這支檔案**不寫入任何資料**（全檔沒有 INSERT/UPDATE/DELETE），純讀取與計算。
 */

require_once __DIR__ . '/kpi_as_lib.php';

/** 草案版次（顯示在頁面與列印版上；改方案時一併改） */
function kpi_scheme_version(): string { return '2026-10-02 草案 v2'; }

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
        'empty'  => ['無歷史資料', '欄位結構已備妥，但系統裡還沒有任何一筆走完整個流程的紀錄'],
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
 *   hidden＝ true 時暫時不列入主表格與總數（kpi_scheme_visible_items() 會濾掉），
 *           定義保留、隨時可以打開——這支函式本身永遠回傳全部項目，
 *           「要不要顯示」交給呼叫端決定，不要在這裡偷偷砍資料。
 * ============================================================ */
function kpi_scheme_items(): array {
    return [
    /* ---------- COP01 客戶需求／客戶滿意（業務課=12） ----------
     * 排序依使用者指示：訂單金額達成→出貨金額達成→出貨準時（一條「接單→出貨」的業績敘事），
     * 再接客訴→客戶滿意度（客訴是滿意度的輸入因子，兩者放在一起才連得起來）。
     */
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

    ['code'=>'order_ontime','block'=>'COP01','name'=>'準時出貨率','dept'=>12,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>90,'unit'=>'%',
     'src'=>'auto','status'=>'move','calc'=>['existing',8],
     'basis'=>'分母＝當月交期的訂單筆數（排除已取消）；分子＝出貨日 ≤ 交期＋寬限工作天者。未交判定方式與寬限天數沿用既有年度設定。',
     'note'=>'稽核老師指出這項的責任在業務端，故由生管組改掛業務課。計算完全不變，只改負責部門——正式上線只要改一個設定值，程式零改動。'],

    ['code'=>'complaint_rate','block'=>'COP01','name'=>'客訴件數','dept'=>12,
     'freq'=>'monthly','vtype'=>'count','dir'=>'lte','target'=>2,'unit'=>'件',
     'src'=>'auto','status'=>'keep','calc'=>['existing',1],
     'basis'=>'當月客退明細筆數（可依退貨性質只計入真正的客訴）。',
     'note'=>'對應「客戶滿意管理流程」。'],

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

    /* ---------- COP03 生產管理（生產課=9／品管課=2） ---------- */
    ['code'=>'process_ng','block'=>'COP03','name'=>'齒研製程不良率','dept'=>9,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>0.5,'unit'=>'%',
     'src'=>'auto','status'=>'retune','calc'=>['existing',15],
     'basis'=>'限定製程＝齒研（process_type_id=12）。分子＝Σ當月報工 NG 數；分母＝Σ當月完成數。',
     'note'=>'原「關鍵製程不良率（齒研）」改名。⚠ 2026 實績 0.00~0.44%、目標卻是 ≤5%＝形同永遠達標，沒有管理訊號。建議目標收緊到 ≤0.5%（或改用 PPM 百萬件不良數）。'],

    ['code'=>'process_ng_cp5','block'=>'COP03','name'=>'插齒製程不良率','dept'=>9,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>null,'unit'=>'%',
     'src'=>'auto','status'=>'watch','calc'=>['preview','kps_process_ng_proc',['process_type_ids'=>[5]]],
     'basis'=>'與上一項同一套判定（process_ng_rate 計算模組），限定製程＝插齒（process_type_id=5，涵蓋 ProcessNo 5／154 兩個插齒站別代碼）。分子＝Σ當月報工 NG 數；分母＝Σ當月完成數。',
     'note'=>'⚠ 插齒目前完全沒有現場報工紀錄（2026 年 0 筆），這一項會全部空白——不是計算錯誤，是現場還沒有用系統報工登記插齒的生產與不良數量。要有數字，得先請插齒站的人員開始用「待加工排程」報工（與「產能達成率－插齒」同一個先決條件）。'],

    ['code'=>'capacity_1','block'=>'COP03','name'=>'產能達成率－創成','dept'=>9,
     'freq'=>'monthly','vtype'=>'rate','dir'=>'gte','target'=>15,'unit'=>'顆/小時',
     'src'=>'auto','status'=>'keep','calc'=>['existing',13],
     'basis'=>'Σ本月完成數量 ÷ Σ生產起訖工時（小時），機台範圍＝創成磨齒機台。',
     'note'=>'原「產能績效－創成」改名。原本沒有設負責部門，建議補上生產課。'],

    ['code'=>'capacity_2','block'=>'COP03','name'=>'產能達成率－成型','dept'=>9,
     'freq'=>'monthly','vtype'=>'rate','dir'=>'gte','target'=>2,'unit'=>'顆/小時',
     'src'=>'auto','status'=>'keep','calc'=>['existing',14],
     'basis'=>'同上，機台範圍＝成型磨齒機台。',
     'note'=>'原「產能績效－成型」改名。'],

    ['code'=>'capacity_3','block'=>'COP03','name'=>'產能達成率－插齒','dept'=>9,
     'freq'=>'monthly','vtype'=>'rate','dir'=>'gte','target'=>null,'unit'=>'顆/小時',
     'src'=>'auto','status'=>'watch','calc'=>['preview','kps_capacity_custom',['machine_ids'=>[1894]]],
     'basis'=>'與創成／成型同一套判定（Σ完成數量 ÷ Σ生產起訖工時），機台限定插齒機（EG-047）。',
     'note'=>'⚠ 插齒機（machine_id=1894）2026 年完全沒有現場報工紀錄，這一項目前全部是空白——不是計算錯誤，是現場還沒有用系統報工登記插齒的生產數量與工時。要有數字，得先請插齒站的人員開始用「待加工排程」報工。'],

    ['code'=>'packing_ng','block'=>'COP03','name'=>'成品出貨不良率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>null,'unit'=>'%',
     'src'=>'auto','status'=>'watch','calc'=>['existing',17],
     'basis'=>'ΣNG 總數 ÷ Σ實際全檢數量。',
     'note'=>'⚠ 2026 年 1~7 月分母都是 0，8 月才 5 筆、9 月 72 筆＝剛開始用。先觀察半年再訂目標。'],

    /* ---------- COP04 原物料與客供料（品管課=2／採購組=7） ---------- */
    ['code'=>'cust_material_ng','block'=>'COP04','name'=>'客供料點收檢驗不良率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'lte','target'=>1,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_qc_by_proc',['procs'=>[138],'ngs'=>['ng','QQ']]],
     'basis'=>'分母＝當月「客供料」站別已判定的檢驗筆數；分子＝判定為驗退（ng）或特採（QQ）者。',
     'note'=>'取代原提案的「供應商評核合格率」（那項不在你們可控範圍）。這一項同時滿足 AS9100 8.5.3「客戶財產」的管控要求，也正好是你們「客供料加工」的核心模式。2026 已判定 1,015 筆（合格 1,012／驗退 2／特採 1）。'],

    ['code'=>'packing_efficiency','block'=>'COP04','name'=>'包裝效率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>null,'unit'=>'%',
     'src'=>'auto','status'=>'new','calc'=>['preview','kps_packing_efficiency',['days'=>3]],
     'basis'=>'分母＝當月結案的包裝檢驗表；分子＝其中「前一關完成 → 包裝結案」在 3 個工作日內者。起算點優先用前一關的 QC 檢驗完成日（qc_completed_at），該站若還沒標記完成則退回該站最後一次完工報工日期；兩者都查不到的不計入分母。結束點＝包裝檢驗表的結案日期。',
     'note'=>'取代原提案的「委外回廠檢驗不良率」。⚠ **這項目前母體極小**：全庫只有 3 筆包裝檢驗紀錄（8/3、9/1、9/19 各一筆），其中 1 筆沒有綁 BOM 編號、查不到前一關，實際只有 2 筆能用。查證時發現一個很重要的坑：若直接用「前一關的 QC_check_date」當起點，有一筆會算出起點（9/24）比包裝結案（9/23）還晚——查下去是那個 QC 欄位後來被改過（這欄只存最後一次、不是日誌），**所以起點一定要用「QC 完成時標記的 qc_completed_at」，不能用 QC_check_date**，已在計算裡這樣處理。這項要有代表性，得等包裝檢驗表累積到至少一兩個月的量。'],

    ['code'=>'purchase_ontime','block'=>'COP04','name'=>'採購進貨準交率','dept'=>7,'hidden'=>true,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>90,'unit'=>'%',
     'src'=>'auto','status'=>'empty','calc'=>['preview','kps_purchase_ontime',[]],
     'basis'=>'分母＝當月預計到貨日（purchase_request.expected_date）落在該月的請購項目；分子＝實際到貨日（purchase_receipt.rcpt_date）≤ 預計到貨日者。範圍可設定只看刀具／砂輪／油品等耗材類別。',
     'note'=>'取代原提案「耗材／刀具盤點正確率」，改成管刀具、砂輪、油品這類耗材「有沒有準時買到」，比盤點庫存更貼近你們的實際需求。⚠ **這項目前完全沒有歷史資料可試算**（不是母體小，是系統裡請購單只有 1 筆、從沒走完整個流程到收貨，到貨登記表 0 筆）——欄位結構都已齊備（需求日／預計到貨日／實際收貨日），只是採購組目前還沒有用系統走完整個請購→訂購→到貨的流程。要有數字，得先請採購組改用系統開請購單並登記到貨。'],

    /* ---------- SP01 資源管理（管理課=13） ---------- */
    ['code'=>'training','block'=>'SP01','name'=>'人員教育訓練達成率','dept'=>13,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>95,'unit'=>'%',
     'src'=>'auto','status'=>'keep','calc'=>['existing',19],
     'basis'=>'分母＝當月計畫訓練場次（排除取消）；分子＝其中已完成場次。',
     'note'=>'對應「人力資源管理」。原本沒有設負責部門，建議補上管理課。已依你的意見取消「人員流動率」。已依你的意見拿掉「職業災害件數」，SP01 這次就只留這一項（流程圖另外列了「工安管理程序」，但既然你不需要人工填寫的指標，就不勉強湊數）。⚠ 2026 年 1~7 月全部 100%（計畫幾場就做幾場，必然達標），8/9 月分母 0＝沒排課。建議改成「年度訓練時數達成率」（training_session 已有 hours／actual_hours 欄位）才有鑑別度——要改請告訴我。'],

    /* ---------- SP02 品管輔助（品管課=2） ---------- */
    ['code'=>'calibration','block'=>'SP02','name'=>'量測儀器按時校驗率','dept'=>2,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>95,'unit'=>'%',
     'src'=>'auto','status'=>'warn','calc'=>['existing',18],
     'basis'=>'分母＝當月應校驗量具數；分子＝其中準時完成（校驗日 ≤ 到期日＋寬限）者。',
     'note'=>'⚠ **這一項是本次查證裡風險最高的**：系統裡有 80 支量具，但校驗紀錄表一筆都沒有建，'
           . '自動計算 2026 年九個月全部是 0/0；而畫面上看到的 95~100% 是**管理者逐月手動覆寫**上去的（8 個月都是覆寫）。'
           . '稽核老師只要問一句「這個數字怎麼來的、佐證在哪」就會變成缺失。'
           . '請決定：① 把校驗紀錄補進系統（最正確）② 先停用這一項 ③ 維持人工填寫但改標示為「人工」並附紙本佐證。'],

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

/**
 * 看得到的項目（排除 'hidden'=>true 的）。
 * 頁面主表格、總數卡片、各區塊統計一律呼叫這一支，不要直接呼叫 kpi_scheme_items()——
 * 否則「先隱藏」的項目會在總數裡被算進去、卻在表格裡看不到，兩邊對不起來。
 * 「隱藏」跟「建議停用（kpi_scheme_dropped）」不是同一件事：
 * 隱藏＝目前沒有東西好看（例如完全沒有歷史資料），定義留著隨時可以打開；
 * 停用＝已經決定不用這個指標了。
 */
function kpi_scheme_visible_items(): array {
    return array_values(array_filter(kpi_scheme_items(), fn($it) => empty($it['hidden'])));
}

/** 目前被隱藏的項目（連同隱藏原因，供頁面列出讓使用者知道還有什麼、隨時可以打開） */
function kpi_scheme_hidden_items(): array {
    return array_values(array_filter(kpi_scheme_items(), fn($it) => !empty($it['hidden'])));
}

/** 建議停用（不再列入新方案） */
function kpi_scheme_dropped(): array {
    return [
        ['no'=>6, 'name'=>'廠商稽核按時執行率',
         'why'=>'外包只認證熱處理，耗材商不需稽核（你確認過）。'],
        ['no'=>9, 'name'=>'發料錯誤件數',
         'why'=>'客供料做完就出貨，沒有發料作業（你確認過）。原本也沒有設負責部門、2026 是人工填寫。'],
        ['no'=>12,'name'=>'出圖正確性',
         'why'=>'純人工填件數，與「出圖準時率」管同一件事。若要保留，建議改接圖面變更紀錄（qc_drawing_change，目前 7 筆）。保留與否請你決定。'],
        ['no'=>4, 'name'=>'報價單接單率',
         'why'=>'你問這項是不是必要——查過 AS9100 條文，**沒有任何一條要求「報價轉換成訂單的比率」**；那是業務端的商業績效指標（接單率高低反映的是報價策略或業務能力），不是品質管理系統要求。拿掉它之後 COP01 仍有受訂目標達成率、銷貨目標達成率、準時出貨率、客訴件數、客戶滿意度共 5 項，遠超過「每個 COP 至少 2 項」的門檻，不影響涵蓋率。'],
        ['no'=>0, 'name'=>'訂單審查及時率',
         'why'=>'依你的指示拿掉。查證時就已發現這項量不出東西：2026 年 2,983 張訂單有 2,771 張（93%）「接單移轉設計」與下單日是同一天，業務多半建單當下就一起按了移轉，門檻改成 1 天也還是 93%，沒有鑑別度。'],
        ['no'=>0, 'name'=>'廠內關鍵製程準交率／特殊製程（熱處理）委外準交率',
         'why'=>'依你的指示拿掉。COP03 現在沒有交期類指標，改以齒研／插齒製程不良率＋三項產能達成率為主；委外熱處理的管控若之後要恢復，可另外補回。'],
        ['no'=>0, 'name'=>'整體製程不良率',
         'why'=>'依你的指示拿掉，「齒研製程不良率」與新增的「插齒製程不良率」已分別涵蓋這兩個製程。'],
        ['no'=>0, 'name'=>'AS 文件按時更新率',
         'why'=>'依你的指示拿掉。SP02 現在只剩「量測儀器按時校驗率」一項（該項仍標示為待你決定，見下方）。'],
    ];
}

/** 待你決定（流程圖上沒有對應區塊，或資料面有疑慮） */
function kpi_scheme_pending(): array {
    return [
        ['no'=>20,'name'=>'應收帳款（票據）未收件數',
         'why'=>'流程圖上沒有財務流程區塊，且 2026 年一筆快照都沒有填過＝實際上沒有在用。要保留的話建議歸到 MP01 經營管理。'],
        ['no'=>21,'name'=>'明細分類帳（損益表）於期限內完成',
         'why'=>'同上，2026 年零填寫。'],
        ['no'=>0, 'name'=>'量測儀器按時校驗率',
         'why'=>'自動值全年是 0/0，畫面上的 95~100% 是管理者逐月覆寫的。要補校驗紀錄、改標人工、還是停用？'],
        ['no'=>0, 'name'=>'產品開發評估完成時效',
         'why'=>'試算每月都是 100%，查證後是「同一天完成」造成的——41 張評估表的 closed_at 與 fill_date 全部同一天（詳見 ⓘ）。要改口徑還是拿掉？（同一個問題的「訂單審查及時率」已依你指示拿掉）'],
    ];
}

/** 上線前的先決條件（不先處理，指標一上線就是空白或假數字） */
function kpi_scheme_prereq(): array {
    return [
        ['t'=>'量測儀器校驗紀錄要先建',
         'd'=>'系統裡有 80 支量具，但校驗紀錄表一筆都沒有。不補的話「量測儀器按時校驗率」永遠是空白。'],
        ['t'=>'插齒站要開始用系統報工',
         'd'=>'插齒機（EG-047）2026 年現場報工 0 筆，「插齒製程不良率」與「產能達成率－插齒」目前全部是空白，不是計算錯誤。'],
        ['t'=>'包裝檢驗表要累積量',
         'd'=>'全庫只有 3 筆包裝檢驗紀錄，「包裝效率」目前只有 2 筆能用，代表性不足。'],
        ['t'=>'採購要走系統請購→訂購→到貨的完整流程',
         'd'=>'請購單只有 1 筆、從沒走完整個流程，到貨登記表 0 筆，「採購進貨準交率」目前完全沒有歷史資料——這是作業流程要改，不是寫程式能解決的。'],
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

/* ---------- COP03 產能達成率－插齒（薄包裝，直接重用既有的 capacity_rate 計算模組，不重寫邏輯） ---------- */
function kps_capacity_custom(PDO $db, int $year, int $month, array $a): ?array {
    $r = kpi_as_compute($db, 'capacity_rate', $year, $month, $a);
    if ($r === null) return null;
    return ['v'=>$r['value'] ?? null, 'num'=>$r['num'] ?? null, 'den'=>$r['den'] ?? null];
}

/* ---------- COP03 插齒製程不良率（薄包裝，直接重用既有的 process_ng_rate 計算模組） ---------- */
function kps_process_ng_proc(PDO $db, int $year, int $month, array $a): ?array {
    $r = kpi_as_compute($db, 'process_ng_rate', $year, $month, $a);
    if ($r === null) return null;
    return ['v'=>$r['value'] ?? null, 'num'=>$r['num'] ?? null, 'den'=>$r['den'] ?? null];
}

/* ---------- COP04 包裝效率 ----------
 * 「前一關完成 → 包裝結案」的時效。起算點優先用前一關的 QC 完成確認（qc_completed_at），
 * 查不到才退回該站最後一次完工報工日期；兩者都沒有的這一筆不計入分母（查不到≠不準時）。
 * 結束點＝包裝檢驗表的結案日期（closed_at，舊資料沒有這欄時退回 updated_at）。
 *
 * 刻意不用「前一關的 QC_check_date」當起點：那一欄只存最後一次、會被後來的動作覆寫，
 * 實測有一筆的 QC_check_date（9/24）比包裝結案（9/23）還晚——用它會算出負的天數。
 * qc_completed_at 是「標記完成當下」寫入、不會事後被別的動作改掉，才是可信的起點。
 */
function kps_packing_efficiency(PDO $db, int $year, int $month, array $a): ?array {
    $days = max(1, (int)($a['days'] ?? 3));
    $all = kps_packing_efficiency_year($db, $year, $days);
    return $all[$month] ?? ['v'=>null, 'num'=>0, 'den'=>0];
}
function kps_packing_efficiency_year(PDO $db, int $year, int $days): array {
    static $cache = [];
    $ck = $year . '|' . $days;
    if (isset($cache[$ck])) return $cache[$ck];

    $st = $db->prepare("SELECT p.packing_inspection_id, p.bom_ing_fid, p.bom,
                               COALESCE(p.closed_at, p.updated_at) AS cdate
                        FROM qc_packing_inspection p
                        WHERE p.status='closed' AND YEAR(COALESCE(p.closed_at, p.updated_at))=?");
    $st->execute([$year]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    for ($m = 1; $m <= 12; $m++) $out[$m] = ['v'=>null, 'num'=>0, 'den'=>0];
    if (!$rows) return $cache[$ck] = $out;

    $prevSt = $db->prepare("SELECT bom_ing_fid, qc_completed, qc_completed_at
                            FROM bom_ing WHERE bom=? AND bom_sn=(
                                SELECT MAX(x.bom_sn) FROM bom_ing x
                                WHERE x.bom=? AND x.bom_sn<(SELECT bom_sn FROM bom_ing WHERE bom_ing_fid=?))");
    $repSt = $db->prepare("SELECT MAX(report_date) FROM pm_process_daily_report
                           WHERE bom_ing_fid=? AND is_finished=1");

    foreach ($rows as $r) {
        $bom = (string)($r['bom'] ?? '');
        if ($bom === '') continue;                       // 查不到 BOM 編號、找不到前一關，不計入分母
        $m = (int)substr((string)$r['cdate'], 5, 2);
        if ($m < 1 || $m > 12) continue;

        $prevSt->execute([$bom, $bom, (int)$r['bom_ing_fid']]);
        $prev = $prevSt->fetch(PDO::FETCH_ASSOC);
        if (!$prev) continue;                             // 本來就是第一關，沒有「前一關」可比

        $start = null;
        if (!empty($prev['qc_completed']) && !empty($prev['qc_completed_at'])) {
            $start = substr((string)$prev['qc_completed_at'], 0, 10);
        } else {
            $repSt->execute([(int)$prev['bom_ing_fid']]);
            $fin = $repSt->fetchColumn();
            if ($fin) $start = substr((string)$fin, 0, 10);
        }
        if ($start === null) continue;                    // 前一關完成日查不到，不計入分母（查不到≠不準時）

        $out[$m]['den']++;
        $end = substr((string)$r['cdate'], 0, 10);
        $wd = ($start === $end) ? 1 : kpi_as_workdays_inclusive($db, $start, $end);
        if ($end >= $start && $wd <= $days) $out[$m]['num']++;
    }
    foreach ($out as $m => $c) $out[$m]['v'] = $c['den'] > 0 ? $c['num'] / $c['den'] * 100 : null;
    return $cache[$ck] = $out;
}

/* ---------- COP04 採購進貨準交率 ----------
 * 分母＝預計到貨日（purchase_request.expected_date）落在當月的請購項目；
 * 分子＝該項目實際收貨日（purchase_receipt.rcpt_date，取最早一次）≤ 預計到貨日者。
 * 欄位結構齊備，但目前系統裡幾乎沒有走完整流程的紀錄（見 note），試算會全部回 null。
 */
function kps_purchase_ontime(PDO $db, int $year, int $month, array $a): ?array {
    $ym = sprintf('%04d-%02d', $year, $month);
    $st = $db->prepare("SELECT pri.req_id, pr.expected_date,
                               (SELECT MIN(rc.rcpt_date) FROM purchase_receipt rc WHERE rc.pr_item_id=pri.pr_item_id) AS got
                        FROM purchase_request_item pri
                        JOIN purchase_request pr ON pr.req_id=pri.req_id
                        WHERE pr.expected_date IS NOT NULL AND DATE_FORMAT(pr.expected_date,'%Y-%m')=?");
    $st->execute([$ym]);
    $num = 0; $den = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $den++;
        if (!empty($r['got']) && substr((string)$r['got'], 0, 10) <= substr((string)$r['expected_date'], 0, 10)) $num++;
    }
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
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
    foreach (kpi_scheme_visible_items() as $it) {
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
          'new'=>0, 'keep'=>0, 'retune'=>0, 'move'=>0, 'watch'=>0, 'warn'=>0, 'empty'=>0];
    foreach (kpi_scheme_visible_items() as $it) {
        $s['total']++;
        if (isset($s[$it['src']]))    $s[$it['src']]++;
        if (isset($s[$it['status']])) $s[$it['status']]++;
    }
    return $s;
}
