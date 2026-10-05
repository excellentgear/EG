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
 *   ③ 這支檔案**原則上不寫入業務資料**（kpi_as_indicator／kpi_as_monthly_value 等正式表
 *      全檔沒有 INSERT/UPDATE/DELETE，純讀取與計算）。
 *      **唯一的例外**是 2026-10-05 新增的「春節目標調整」管理員額外調整率（kpi_scheme_cny_adjust）——
 *      那是這個草案功能自己的設定、不是正式 KPI 資料，寫入收斂在本檔「五、春節目標調整」整節，
 *      且只有 KpiSchemeCny_API.php 的 save 動作會呼叫，其餘函式仍然唯讀。
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
     'src'=>'auto','status'=>'retune','calc'=>['preview','kps_target_cny_adjusted',['item_no'=>2]],
     'basis'=>'當月接單金額 ÷ 該月受訂目標金額，讀正式表的達成率快照；遇春節月份再依「春節目標調整」的比例放大達成率（見下）。',
     'note'=>kps_cny_note_text()],

    ['code'=>'shipping_target','block'=>'COP01','name'=>'月銷貨額達成率','dept'=>12,
     'freq'=>'monthly','vtype'=>'percent','dir'=>'gte','target'=>85,'unit'=>'%',
     'src'=>'auto','status'=>'retune','calc'=>['preview','kps_target_cny_adjusted',['item_no'=>3]],
     'basis'=>'當月出貨金額 ÷ 該月銷貨目標金額，讀正式表的達成率快照；遇春節月份再依「春節目標調整」的比例放大達成率（見下）。',
     'note'=>kps_cny_note_text()],

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

/**
 * 2026-10-05（續）：自有計算模組改成「先組出整批來源列，再套用排除」的共同寫法——
 * compute（算 KPI 數值）與 detail（不符合標準的明細）吃同一份 rows，才不會出現
 * 「明細顯示的筆數」與「KPI 算出來的分子分母」兩邊對不起來的情況（這是 KPI.php
 * 早期版本吃過的虧，本方案從一開始就用同一套)。row 的形狀：
 *   key  = 來源列的唯一識別（排除時存這個）
 *   kind = 'bad'（不符合標準，分子不算）／'info'（正常，分子算）
 *   vals = {欄位代號: 顯示文字}，給明細表格用
 *   dims = {維度代號: 值}，給「排除規則」用；本方案自有模組目前刻意不開放規則式
 *          排除（kps_calc_dims() 對這些 calc 回空陣列），所以這裡留空即可
 *   why  = 一句話說明為什麼算 bad／info
 */
function kps_calc_apply_excl(array $rows, array $exclRows = [], array $rules = []): array {
    $exSet = $exclRows ? array_flip(array_map('strval', $exclRows)) : [];
    $num = 0; $den = 0;
    foreach ($rows as $r) {
        if ($exSet && isset($exSet[(string)$r['key']])) continue;
        if (!empty($r['dims']) && kpi_as_dims_hit($r['dims'], $rules) !== '') continue;
        $den++;
        if (($r['kind'] ?? 'bad') !== 'bad') $num++;
    }
    return ['v'=>$den > 0 ? $num / $den * 100 : null, 'num'=>$num, 'den'=>$den];
}

/* ---------- COP02 產品開發評估完成時效 ---------- */
function kps_dev_eval_rows(PDO $db, int $year, int $month, int $days): array {
    $ym = sprintf('%04d-%02d', $year, $month);
    $st = $db->prepare("SELECT id, doc_no, part_no_text, product_name, fill_date, closed_at
                        FROM td_dev_eval WHERE is_deleted=0 AND DATE_FORMAT(fill_date,'%Y-%m')=?");
    $st->execute([$ym]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d1 = substr((string)$r['fill_date'], 0, 10);
        $kind = 'bad'; $why = ''; $wd = null; $d2 = '';
        if (empty($r['closed_at'])) { $why = '還沒完成決行'; }
        else {
            $d2 = substr((string)$r['closed_at'], 0, 10);
            if ($d2 < $d1) { $why = '決行日早於填表日（資料可能有誤）'; }
            else {
                $wd = ($d1 === $d2) ? 1 : kpi_as_workdays_inclusive($db, $d1, $d2);
                if ($wd > $days) { $why = '經過 ' . $wd . ' 個工作日，超過門檻 ' . $days . ' 天'; }
                else { $kind = 'info'; $why = '準時完成（' . $wd . ' 個工作日）'; }
            }
        }
        $rows[] = ['key'=>(string)$r['id'], 'kind'=>$kind, 'dims'=>[], 'why'=>$why,
                   'vals'=>['doc'=>(string)$r['doc_no'], 'part'=>(string)($r['part_no_text'] ?: $r['product_name']),
                            'fill'=>$d1, 'close'=>$d2, 'days'=>$wd === null ? '' : $wd]];
    }
    return $rows;
}
function kps_dev_eval(PDO $db, int $year, int $month, array $a, array $exclRows = []): ?array {
    $days = max(1, (int)($a['days'] ?? 10));
    return kps_calc_apply_excl(kps_dev_eval_rows($db, $year, $month, $days), $exclRows);
}

/* ---------- COP02 型態識別文件確認率（現況快照，季指標；不分月份，每個月看到的是同一份現況） ---------- */
function kps_type_ctrl_rows(PDO $db): array {
    $rows = [];
    $st = $db->query("SELECT id, doc_no, process_desc, review_status FROM type_id_ctrl_doc WHERE is_deleted=0");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ok = ((string)$r['review_status'] === 'confirmed');
        $rows[] = ['key'=>(string)$r['id'], 'kind'=>$ok ? 'info' : 'bad', 'dims'=>[],
                   'why'=>$ok ? '已確認' : ('尚未確認（' . ((string)$r['review_status'] ?: '待確認') . '）'),
                   'vals'=>['doc'=>(string)$r['doc_no'], 'proc'=>(string)($r['process_desc'] ?? ''),
                            'status'=>$ok ? '已確認' : ((string)$r['review_status'] ?: '待確認')]];
    }
    return $rows;
}
function kps_type_ctrl(PDO $db, int $year, int $month, array $a, array $exclRows = []): ?array {
    return kps_calc_apply_excl(kps_type_ctrl_rows($db), $exclRows);
}

/* ---------- COP04 依製程切分的點收／回廠檢驗不良率 ----------
 * 口徑與既有「進料檢驗不良率」完全相同（分母＝當月已判定的檢驗筆數），
 * 只多一個「限定哪些製程」的範圍；正式上線時在既有計算模組加一個參數即可。
 */
function kps_qc_by_proc_rows_year(PDO $db, int $year, array $procs, array $ptypes, array $ngs): array {
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
    // 判定 NG 與否在 PHP 端逐列比對，SQL 本身不用 NG 條件過濾（不良與正常都要一起列出來才有完整分母）。
    $st = $db->prepare("SELECT bi.bom_ing_fid, bi.bom, bi.process_no, pn.ProcessName,
                               bi.QC_check_date, bi.QC_check
                        FROM bom_ing bi LEFT JOIN process_no pn ON pn.ProcessNo=bi.process_no
                        WHERE " . implode(' AND ', $where));
    $st->execute($bind);

    $out = []; for ($m = 1; $m <= 12; $m++) $out[$m] = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = (int)substr((string)$r['QC_check_date'], 5, 2);
        if ($m < 1 || $m > 12) continue;
        $isNg = in_array((string)$r['QC_check'], $ngs, true);
        $procName = (string)($r['ProcessName'] ?: $r['process_no']);
        $out[$m][] = ['key'=>(string)$r['bom_ing_fid'], 'kind'=>$isNg ? 'bad' : 'info', 'dims'=>[],
                      'why'=>$isNg ? ('判定為「' . $r['QC_check'] . '」') : '合格',
                      'vals'=>['bom'=>(string)$r['bom'], 'proc'=>$procName,
                               'date'=>substr((string)$r['QC_check_date'], 0, 10), 'result'=>(string)$r['QC_check']]];
    }
    return $cache[$ck] = $out;
}
function kps_qc_by_proc(PDO $db, int $year, int $month, array $a, array $exclRows = [], array $rules = []): ?array {
    $rows = kps_qc_by_proc_rows_year($db, $year, kpi_as_list($a['procs'] ?? []),
                                     kpi_as_list($a['ptypes'] ?? []), kpi_as_list($a['ngs'] ?? ['ng']))[$month] ?? [];
    return kps_calc_apply_excl($rows, $exclRows, $rules);
}

/* ---------- COP03 產能達成率－插齒／製程不良率（已改為直接重用官方 capacity_rate／process_ng_rate
   計算模組，見 kps_as_delegate_map()；kpi_scheme_compute_by_key() 會在抵達這裡之前就轉呼叫
   kpi_as_compute()，本節原本兩支薄包裝函式已無呼叫端，留著只為相容舊版 kpi_scheme_items()
   種子資料裡的函式名稱字串，不再是計算路徑上會被呼叫到的程式碼。 ---------- */
function kps_capacity_custom(PDO $db, int $year, int $month, array $a): ?array {
    $r = kpi_as_compute($db, 'capacity_rate', $year, $month, $a);
    if ($r === null) return null;
    return ['v'=>$r['value'] ?? null, 'num'=>$r['num'] ?? null, 'den'=>$r['den'] ?? null];
}
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
function kps_packing_efficiency_rows_year(PDO $db, int $year, int $days): array {
    static $cache = [];
    $ck = $year . '|' . $days;
    if (isset($cache[$ck])) return $cache[$ck];

    $st = $db->prepare("SELECT p.packing_inspection_id, p.bom_ing_fid, p.bom,
                               COALESCE(p.closed_at, p.updated_at) AS cdate
                        FROM qc_packing_inspection p
                        WHERE p.status='closed' AND YEAR(COALESCE(p.closed_at, p.updated_at))=?");
    $st->execute([$year]);
    $srcRows = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = []; for ($m = 1; $m <= 12; $m++) $out[$m] = [];
    if (!$srcRows) return $cache[$ck] = $out;

    $prevSt = $db->prepare("SELECT bom_ing_fid, qc_completed, qc_completed_at
                            FROM bom_ing WHERE bom=? AND bom_sn=(
                                SELECT MAX(x.bom_sn) FROM bom_ing x
                                WHERE x.bom=? AND x.bom_sn<(SELECT bom_sn FROM bom_ing WHERE bom_ing_fid=?))");
    $repSt = $db->prepare("SELECT MAX(report_date) FROM pm_process_daily_report
                           WHERE bom_ing_fid=? AND is_finished=1");

    foreach ($srcRows as $r) {
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

        $end = substr((string)$r['cdate'], 0, 10);
        $wd = ($start === $end) ? 1 : kpi_as_workdays_inclusive($db, $start, $end);
        $ok = ($end >= $start && $wd <= $days);
        $out[$m][] = ['key'=>(string)$r['packing_inspection_id'], 'kind'=>$ok ? 'info' : 'bad', 'dims'=>[],
                      'why'=>$ok ? ($wd . ' 個工作日內完成') : ('經過 ' . $wd . ' 個工作日，超過門檻 ' . $days . ' 天'),
                      'vals'=>['bom'=>$bom, 'start'=>$start, 'end'=>$end, 'days'=>$wd]];
    }
    return $cache[$ck] = $out;
}
function kps_packing_efficiency(PDO $db, int $year, int $month, array $a, array $exclRows = []): ?array {
    $days = max(1, (int)($a['days'] ?? 3));
    $rows = kps_packing_efficiency_rows_year($db, $year, $days)[$month] ?? [];
    return kps_calc_apply_excl($rows, $exclRows);
}

/* ---------- COP04 採購進貨準交率 ----------
 * 分母＝預計到貨日（purchase_request.expected_date）落在當月的請購項目；
 * 分子＝該項目實際收貨日（purchase_receipt.rcpt_date，取最早一次）≤ 預計到貨日者。
 * 欄位結構齊備，但目前系統裡幾乎沒有走完整流程的紀錄（見 note），試算會全部回 null。
 */
function kps_purchase_ontime_rows(PDO $db, int $year, int $month): array {
    $ym = sprintf('%04d-%02d', $year, $month);
    $st = $db->prepare("SELECT pri.pr_item_id, pri.item_name, pr.req_no, pr.expected_date,
                               (SELECT MIN(rc.rcpt_date) FROM purchase_receipt rc WHERE rc.pr_item_id=pri.pr_item_id) AS got
                        FROM purchase_request_item pri
                        JOIN purchase_request pr ON pr.req_id=pri.req_id
                        WHERE pr.expected_date IS NOT NULL AND DATE_FORMAT(pr.expected_date,'%Y-%m')=?");
    $st->execute([$ym]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $exp = substr((string)$r['expected_date'], 0, 10);
        $got = $r['got'] !== null ? substr((string)$r['got'], 0, 10) : '';
        $ok = ($got !== '' && $got <= $exp);
        $rows[] = ['key'=>(string)$r['pr_item_id'], 'kind'=>$ok ? 'info' : 'bad', 'dims'=>[],
                   'why'=>$got === '' ? '尚未收貨' : ($ok ? '準時到貨' : '逾期到貨'),
                   'vals'=>['req'=>(string)$r['req_no'], 'item'=>(string)$r['item_name'],
                            'expected'=>$exp, 'got'=>$got]];
    }
    return $rows;
}
function kps_purchase_ontime(PDO $db, int $year, int $month, array $a, array $exclRows = []): ?array {
    return kps_calc_apply_excl(kps_purchase_ontime_rows($db, $year, $month), $exclRows);
}

/* ---------- MP02 矯正措施按時結案率 ---------- */
function kps_car_ontime_rows(PDO $db, int $year, int $month): array {
    $ym = sprintf('%04d-%02d', $year, $month);
    $st = $db->prepare("SELECT id, car_no, correction_due, close_date FROM car_order
                        WHERE correction_due IS NOT NULL AND DATE_FORMAT(correction_due,'%Y-%m')=?");
    $st->execute([$ym]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $due = substr((string)$r['correction_due'], 0, 10);
        $close = $r['close_date'] !== null ? substr((string)$r['close_date'], 0, 10) : '';
        $ok = ($close !== '' && $close <= $due);
        $rows[] = ['key'=>(string)$r['id'], 'kind'=>$ok ? 'info' : 'bad', 'dims'=>[],
                   'why'=>$close === '' ? '尚未結案' : ($ok ? '按時結案' : '逾期結案'),
                   'vals'=>['no'=>(string)$r['car_no'], 'due'=>$due, 'close'=>$close]];
    }
    return $rows;
}
function kps_car_ontime(PDO $db, int $year, int $month, array $a, array $exclRows = []): ?array {
    return kps_calc_apply_excl(kps_car_ontime_rows($db, $year, $month), $exclRows);
}

/* ---------- MP01 全廠 KPI 總體達標率（舊版，吃正式系統的 kpi_as_indicator，
   已無呼叫端——DB 驅動的計算一律走下面的 kps_scheme_kpi_overall，留著只相容舊種子資料字串）---------- */
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

/* ============================================================
 * 五、春節目標調整（2026-10-05 使用者要求）
 *
 * 問題：月份受訂／銷貨目標是固定金額，但春節期間工廠實際能上班的天數比平常少，
 * 拿同一個固定目標去比，春節那個月的達成率必然偏低——那不是業績真的差，
 * 是分母（目標）本身就不合理，看那個月的達成率會失真。
 *
 * 規則（使用者定義）：
 *   ① 基準 30 天：比例 = (30 − 該月春節損失的工作天數) / 30，只有春節會觸發調整，
 *      其他月份一律比例=1（不調整）。
 *   ② 春節若跨兩個月，兩個月各自依各自分到的天數算比例——這樣算出來的結果
 *      天然就會比「整段春節都算在同一個月」時，每個月的降幅都小（損失天數被分散了）。
 *   ③ 管理員可疊加一個「額外調整率」，微調自動算出來的比例，避免跟實際出入太大。
 *
 * 春節日期哪裡來：行事曆 evenement（分類「國定假日」day_type='s'）裡標題含「春節」
 * 的那幾天——這是 HR／行政每年固定會登錄的既有行事曆資料，不另外維護第二份日期表
 * （鐵律4）。只算週一到週五：週末本來就不算工作日，春節蓋到週末不該重複扣。
 *
 * 寫入：本節是這支檔案唯一有寫入動作的地方（管理員額外調整率），只有
 * KpiSchemeCny_API.php 的 save 動作會呼叫 kps_cny_override_save()，其餘函式唯讀。
 * ============================================================ */

/** 給 order_target／shipping_target 共用的備註文字，避免兩處各打一份、改一邊忘了另一邊 */
function kps_cny_note_text(): string {
    return '原本固定用一個金額當每月目標，春節月份工作天數變少，達成率會失真——不是業績真的差，是分母（目標）本身就不合理。'
         . '春節那個月（可能是一個月，也可能跨兩個月）的達成率，等同於用「目標先依實際可上班天數的比例折算」'
         . '再重算一次（基準 30 天，其他月份不調整），表格上滑鼠移過去的提示看得到調整前後的差異。'
         . '⚠ 正式系統這兩個指標 2026 年 1~6 月都被管理者手動覆寫過（原本自動算出來的數字跟固定目標對不起來，已人工修正），'
         . '所以調整刻意做在「達成率本身」而不是重算一次「調整後的目標金額」——目標縮小成原本比例，達成率放大成原本的倒數，數學上等價，'
         . '但不會動到管理者已經確認過的覆寫值。管理員可以在「春節目標調整設定」疊加一個額外調整率微調，避免自動算出來的跟實際狀況差太多。';
}

/** 建表（可重複呼叫）：管理員額外調整率，本檔唯一的寫入資料表 */
function kpi_scheme_cny_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS kpi_scheme_cny_adjust (
            id INT AUTO_INCREMENT PRIMARY KEY,
            year SMALLINT NOT NULL,
            month TINYINT NOT NULL,
            extra_pct DECIMAL(5,2) NOT NULL DEFAULT 0
                COMMENT '管理員額外調整率(百分點，可正可負，疊加在自動算出的比例上)',
            note VARCHAR(200) NULL,
            updated_by VARCHAR(30) NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uk_ym (year, month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='KPI新方案草案：春節目標調整的管理員額外調整率，見 kpi_scheme_lib.php'");
    } catch (Throwable $e) {}
}

/**
 * 某年度逐月「春節損失的工作天數」。
 * 只算週一到週五（週末本來就不是工作日，不重複扣）；一段連假橫跨月份時，
 * 直接依實際日期分月累加，不必另外處理「跨月」——這就是為什麼同一段春節
 * 分跨兩個月時，各月的損失天數自然比全部算在同一個月時來得少。
 */
function kps_cny_lost_days_by_month(PDO $db, int $year): array {
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];
    $out = array_fill(1, 12, 0);
    try {
        $st = $db->prepare("SELECT DATE(e.start) d1, DATE(COALESCE(e.end,e.start)) d2
                            FROM evenement e JOIN event_category ec ON ec.id=e.category_id
                            WHERE ec.day_type='s' AND e.title LIKE '%春節%'
                              AND YEAR(e.start)<=? AND YEAR(COALESCE(e.end,e.start))>=?");
        $st->execute([$year, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = strtotime((string)$r['d1']); $end = strtotime((string)$r['d2']);
            if ($d === false || $end === false || $end < $d) continue;
            $guard = 0;
            while ($d <= $end && $guard++ < 60) {
                $y = (int)date('Y', $d); $m = (int)date('n', $d);
                $dow = (int)date('w', $d);                       // 0=週日 … 6=週六
                if ($y === $year && $dow !== 0 && $dow !== 6) $out[$m]++;
                $d = strtotime('+1 day', $d);
            }
        }
    } catch (Throwable $e) {}
    return $cache[$year] = $out;
}

/** 管理員額外調整率：單月讀取（查不到回 null，不是回 0——0 是「管理員確認過不必調」，null 是「還沒設」） */
function kps_cny_override_get(PDO $db, int $year, int $month): ?array {
    kpi_scheme_cny_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT extra_pct, note, updated_by, updated_at
                            FROM kpi_scheme_cny_adjust WHERE year=? AND month=?");
        $st->execute([$year, $month]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/** 管理員額外調整率：整年讀取（供設定頁一次列出 12 個月） */
function kps_cny_override_all(PDO $db, int $year): array {
    kpi_scheme_cny_ensure_schema($db);
    $out = [];
    try {
        $st = $db->prepare("SELECT month, extra_pct, note, updated_by, updated_at
                            FROM kpi_scheme_cny_adjust WHERE year=?");
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['month']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** 寫入管理員額外調整率（本檔唯一的寫入點，只給 KpiSchemeCny_API.php 呼叫） */
function kps_cny_override_save(PDO $db, int $year, int $month, float $extraPct, string $note, string $byName): void {
    kpi_scheme_cny_ensure_schema($db);
    $extraPct = max(-50, min(50, $extraPct));                    // 守住合理範圍，不給打出離譜的值
    $note = mb_substr(trim($note), 0, 200);
    $st = $db->prepare("INSERT INTO kpi_scheme_cny_adjust (year, month, extra_pct, note, updated_by, updated_at)
                        VALUES (?,?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE extra_pct=VALUES(extra_pct), note=VALUES(note),
                                                updated_by=VALUES(updated_by), updated_at=NOW()");
    $st->execute([$year, $month, $extraPct, $note === '' ? null : $note, $byName]);
}

/**
 * 某年某月的最終調整比例＝自動比例＋管理員額外調整率，夾在 [0, 1.5] 之間
 * （防呆：不給調成負的目標，也不給調到離譜大）。
 * 回傳 auto（自動算出的）、extra（管理員設定，無則 null）、final（兩者疊加後採用的）、lost_days。
 */
function kps_cny_ratio(PDO $db, int $year, int $month): array {
    $lost = kps_cny_lost_days_by_month($db, $year)[$month] ?? 0;
    $auto = $lost > 0 ? max(0, (30 - $lost) / 30) : 1.0;
    $ov = kps_cny_override_get($db, $year, $month);
    $extraPct = $ov ? (float)$ov['extra_pct'] : 0.0;
    $final = max(0, min(1.5, $auto + $extraPct / 100));
    return ['auto'=>$auto, 'extra_pct'=>$extraPct, 'note'=>$ov['note'] ?? null, 'final'=>$final, 'lost_days'=>$lost];
}

/**
 * COP01 月份受訂／銷貨目標達成率的試算入口：讀正式表快照的達成率（鐵律①：永遠讀正式快照，
 * 不重算實際金額），遇春節月份就把這個達成率除以當月比例（＝等同目標縮小成原本比例倍）。
 * $a['item_no'] 指定要讀哪一個既有指標（2＝受訂、3＝銷貨），這支函式本身是通用的，
 * 之後有別的「金額目標」指標要套用同一套春節調整，傳不同 item_no 即可重用。
 */
function kps_target_cny_adjusted(PDO $db, int $year, int $month, array $a): ?array {
    $itemNo = (int)($a['item_no'] ?? 0);
    if ($itemNo <= 0) return null;
    $snap = kps_from_snapshot($db, $itemNo, $year);
    $cell = $snap[$month] ?? null;
    if ($cell === null || $cell['v'] === null) return $cell;

    $r = kps_cny_ratio($db, $year, $month);
    $ratio = $r['final'];
    if ($ratio >= 0.999) {
        // 非春節月份：原樣回傳，只附帶比例資訊（=1）供畫面判斷要不要顯示調整籤
        return $cell + ['cny_ratio'=>$ratio, 'cny_lost'=>$r['lost_days'], 'cny_orig_v'=>null];
    }

    /* 刻意不碰 num/den、不試圖重算出「調整後的目標金額」：
     * 這兩個指標在正式系統裡 numerator/denominator 只是自動計算當下的殘留值，
     * 查證真實資料發現 2026 年 1~6 月全部被管理者手動覆寫過（auto_value 算出來
     * 只有 0.03%~68%，跟 denominator=8,000,000 這個固定值兜不起來），覆寫之後
     * num/den 早就跟畫面顯示的 v 脫鉤——拿脫鉤的 den 乘比例重算，等於悄悄蓋掉
     * 管理者已經確認過的覆寫值。數學上「目標縮小成原本的 ratio 倍」等價於
     * 「達成率放大成原本的 1/ratio 倍」，所以直接對顯示值 v 做這個運算，
     * 不管 v 原本是自動算的還是人工覆寫的都通用、都不會誤改到原始資料。 */
    $adjV = $ratio > 0 ? (float)$cell['v'] / $ratio : null;
    return ['v'=>$adjV, 'num'=>$cell['num'], 'den'=>$cell['den'], 'src'=>$cell['src'],
            'cny_ratio'=>$ratio, 'cny_lost'=>$r['lost_days'], 'cny_orig_v'=>$cell['v']];
}

/* ============================================================
 * 六、指標正式化儲存（2026-10-05 使用者要求）
 *
 * 「KPI 新方案」要從草案變成正式可用、管理員可自行編輯設定的頁面，但使用者明確要求
 * **絕對不可以影響 `views/news/KPI.php`**——那一頁直接讀 `kpi_as_indicator` /
 * `kpi_as_indicator_year` / `kpi_as_monthly_value`，這三張表本節完全不碰（不新增、
 * 不刪除、不修改一筆），只有唯讀 SELECT（`kps_from_snapshot()` 既有做法，給「既有
 * 指標」讀同一份真實數字用，這是從一開始就有的安全設計，不是本節新增的風險）。
 *
 * 做法：指標定義（原本寫死在 kpi_scheme_items() 的 PHP 陣列）搬進兩張**全新、獨立**
 * 的表（kpi_scheme_indicator / kpi_scheme_indicator_year，結構比照 kpi_as_indicator
 * 系列但完全分開），管理員可以在 KPI_new.php 的「設定」分頁編輯；kpi_scheme_items()
 * 保留在程式碼裡**只當成一次性的種子資料來源**，不再是頁面即時讀取的對象。
 *
 * 刻意不做月快照／年度鎖定：維持草案的「即時試算」——指標定義一旦存進資料庫，
 * 已經發生過的月份本來就會在每次載入時立刻算出來，不需要另外寫一套「回填」。
 * 這樣更簡單、風險更低，而且跟正式表的鎖定機制完全無關、不會混淆兩套邏輯。
 * ============================================================ */

/** 建表（可重複呼叫）：指標主檔＋年度設定，本節唯一的寫入資料表 */
function kpi_scheme_ind_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS kpi_scheme_indicator (
            indicator_id INT AUTO_INCREMENT PRIMARY KEY,
            item_no INT NOT NULL,
            block VARCHAR(10) NOT NULL COMMENT 'COP01~04/SP01/SP02/MP01/MP02',
            name VARCHAR(100) NOT NULL,
            freq ENUM('monthly','quarterly','halfyear','yearly') NOT NULL DEFAULT 'monthly',
            value_type ENUM('percent','count','score','rate','yesno') NOT NULL DEFAULT 'percent',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            Created_By VARCHAR(30) NULL, Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            Modified_By VARCHAR(30) NULL, Modified_At DATETIME NULL,
            UNIQUE KEY uk_item_no (item_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='KPI 新方案指標主檔（與正式 kpi_as_indicator 完全分離，不互相影響）'");
        $db->exec("CREATE TABLE IF NOT EXISTS kpi_scheme_indicator_year (
            iy_id INT AUTO_INCREMENT PRIMARY KEY,
            indicator_id INT NOT NULL,
            year SMALLINT NOT NULL,
            owner_dept_id INT NULL, owner_user_id INT NULL, owner_position_id INT NULL,
            owner_display VARCHAR(50) NULL,
            source_mode ENUM('auto','manual') NOT NULL DEFAULT 'manual',
            calculator_key VARCHAR(60) NULL COMMENT '對應 kpi_scheme_registry() 的 key',
            params_json TEXT NULL,
            target_direction ENUM('gte','lte','yes') NOT NULL DEFAULT 'gte',
            target_value DECIMAL(12,2) NULL, target_unit VARCHAR(20) NULL, target_text VARCHAR(60) NULL,
            note VARCHAR(200) NULL COMMENT '管理員自由備註',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            Created_By VARCHAR(30) NULL, Created_At DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            Modified_By VARCHAR(30) NULL, Modified_At DATETIME NULL,
            UNIQUE KEY uk_iy (indicator_id, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='KPI 新方案指標年度設定（與正式 kpi_as_indicator_year 完全分離，不互相影響）'");

        // ---- 2026-10-05（續）：總覽頁的單一欄位修改／逐筆排除／排除規則，比照 KPI.php
        // 的 kpi_as_monthly_value／kpi_as_adjust／kpi_as_excl_rule，但一律另開新表、
        // indicator_id 指向 kpi_scheme_indicator（不是 kpi_as_indicator）——兩套 id
        // 空間各自獨立，絕對不可以共用正式表，否則「排除第3項」會連正式系統的第3項一起中獎。
        $db->exec("CREATE TABLE IF NOT EXISTS kpi_scheme_monthly_value (
            mv_id INT AUTO_INCREMENT PRIMARY KEY,
            indicator_id INT NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL,
            manual_value DECIMAL(14,4) NULL COMMENT '人工填寫值(source_mode=manual)',
            filled_by INT NULL, filled_by_name VARCHAR(50) NULL, filled_at DATETIME NULL,
            note VARCHAR(200) NULL,
            override_value DECIMAL(14,4) NULL COMMENT '覆寫值(source_mode=auto，顯示優先序最高)',
            override_by INT NULL, override_by_name VARCHAR(50) NULL, override_at DATETIME NULL,
            override_reason VARCHAR(255) NULL COMMENT '覆寫原因(必填，供追溯)',
            UNIQUE KEY uk_cell (indicator_id, year, month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='KPI新方案-每月人工填寫/覆寫值(與正式 kpi_as_monthly_value 完全分離)'");
        $db->exec("CREATE TABLE IF NOT EXISTS kpi_scheme_adjust (
            adj_id INT AUTO_INCREMENT PRIMARY KEY,
            indicator_id INT NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL,
            calculator_key VARCHAR(40) NOT NULL,
            row_key VARCHAR(100) NOT NULL, row_label VARCHAR(255) NULL, row_json TEXT NULL,
            reason VARCHAR(255) NULL,
            created_by INT NULL, created_by_name VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cell_row (indicator_id, year, month, row_key),
            KEY idx_cell (indicator_id, year, month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='KPI新方案-排除指定來源列(不改真實資料，與正式 kpi_as_adjust 完全分離)'");
        $db->exec("CREATE TABLE IF NOT EXISTS kpi_scheme_excl_rule (
            rule_id INT AUTO_INCREMENT PRIMARY KEY,
            indicator_id INT NOT NULL, year SMALLINT NOT NULL,
            scope ENUM('year','all') NOT NULL DEFAULT 'year',
            dim VARCHAR(20) NOT NULL, val VARCHAR(190) NOT NULL,
            reason VARCHAR(255) NULL,
            created_by INT NULL, created_by_name VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_rule (indicator_id, year, dim, val),
            KEY idx_iy (indicator_id, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='KPI新方案-依維度整批排除(不改真實資料，與正式 kpi_as_excl_rule 完全分離)'");
    } catch (Throwable $e) {}
}

/**
 * 計算方式登記表——只收錄這個新方案自己會用到的 calculator_key，數量遠比正式系統的
 * 21 筆少（約 10 筆），每筆含 name/desc/params schema，schema 格式與
 * `kpi_as_registry()` 完全相同（int/num/bool/choice/months_map/typedays_map/
 * process_type_ids/machine_type_ids 這幾種型別的參數編輯器可以直接共用同一套
 * 前端渲染邏輯，不必重寫）。
 */
function kpi_scheme_registry(): array {
    return [
        'existing' => [
            'name'=>'沿用正式指標的數字', 'desc'=>'直接讀正式 KPI 表（2-GM-04-01）某一項的月快照，不重算，確保兩邊數字一致。',
            'params'=>[['key'=>'item_no','label'=>'正式指標項次(item_no)','type'=>'int','fe'=>0]]],
        'existing_cny' => [
            'name'=>'沿用正式指標＋春節目標調整', 'desc'=>'同上，但春節月份會依「春節目標調整設定」自動放大達成率。',
            'params'=>[['key'=>'item_no','label'=>'正式指標項次(item_no)','type'=>'int','fe'=>0]]],
        'target_order' => [
            'name'=>'月受訂目標達成率（本方案自有目標）',
            'desc'=>'接單金額(訂單交期歸屬)÷本方案自己設定的各月受訂目標金額——完全不讀正式 KPI 系統的設定，'
                  . '各月目標金額直接在這裡填，不必去正式 KPI 設定頁（KPI.php）調整。可勾選春節月份自動放大達成率。',
            'params'=>[['key'=>'monthly_targets','label'=>'各月受訂目標金額','type'=>'months_map','fe'=>0],
                       ['key'=>'cny','label'=>'春節月份自動放大達成率','type'=>'bool','fe'=>0]]],
        'target_shipping' => [
            'name'=>'月銷貨目標達成率（本方案自有目標）',
            'desc'=>'出貨金額÷本方案自己設定的各月銷貨目標金額——完全不讀正式 KPI 系統的設定，'
                  . '各月目標金額直接在這裡填，不必去正式 KPI 設定頁（KPI.php）調整。可勾選春節月份自動放大達成率。',
            'params'=>[['key'=>'monthly_targets','label'=>'各月銷貨目標金額','type'=>'months_map','fe'=>0],
                       ['key'=>'cny','label'=>'春節月份自動放大達成率','type'=>'bool','fe'=>0]]],
        'dev_eval_lead' => [
            'name'=>'產品開發評估完成時效', 'desc'=>'分母＝當月填表的產品開發評估表；分子＝N 個工作日內完成決行者。',
            'params'=>[['key'=>'days','label'=>'門檻工作日數','type'=>'int','fe'=>1]]],
        'type_ctrl' => [
            'name'=>'型態識別文件確認率', 'desc'=>'現況快照：已建立的型態識別文件管制表中，已確認的佔比。',
            'params'=>[]],
        'process_ng_proc' => [
            'name'=>'製程不良率（限定製程類別）', 'desc'=>'薄包裝重用正式系統的 process_ng_rate 計算模組，限定製程類別。',
            'params'=>[['key'=>'process_type_ids','label'=>'製程類別','type'=>'process_type_ids','fe'=>0]]],
        'capacity_custom' => [
            'name'=>'產能達成率（限定機台）', 'desc'=>'薄包裝重用正式系統的 capacity_rate 計算模組，限定機台。',
            'params'=>[['key'=>'machine_ids','label'=>'機台id(逗號分隔)','type'=>'textlist','fe'=>0]]],
        'qc_by_proc' => [
            'name'=>'檢驗不良率（限定製程）', 'desc'=>'分母＝當月指定製程已判定的檢驗筆數；分子＝判定為指定狀態者。',
            'params'=>[['key'=>'procs','label'=>'限定製程編號(逗號分隔)','type'=>'textlist','fe'=>0],
                       ['key'=>'ngs','label'=>'算不良的判定(逗號分隔，如 ng,QQ)','type'=>'textlist','fe'=>1]]],
        'packing_efficiency' => [
            'name'=>'包裝效率', 'desc'=>'前一關完成(QC完成確認或報工完工) → 包裝結案，在門檻工作日內的比例。',
            'params'=>[['key'=>'days','label'=>'門檻工作日數','type'=>'int','fe'=>1]]],
        'purchase_ontime' => [
            'name'=>'採購進貨準交率', 'desc'=>'分母＝當月預計到貨的請購項目；分子＝實際到貨日未超過預計到貨日者。',
            'params'=>[]],
        'car_ontime' => [
            'name'=>'矯正措施按時結案率', 'desc'=>'分母＝當月到期的矯正措施；分子＝結案日未超過期限者。',
            'params'=>[]],
        'kpi_overall' => [
            'name'=>'全廠 KPI 總體達標率', 'desc'=>'分母＝本方案當月有數值的指標數；分子＝其中達成目標者（排除本項自己）。',
            'params'=>[]],
    ];
}

/**
 * 哪些 calculator_key 是「直接重用官方 kpi_as_compute()/kpi_as_detail() 引擎」——
 * 左邊是本方案自己的 calculator_key，右邊是 kpi_as_registry() 裡對應的官方 calc key。
 * 這組 calc 因為官方引擎本來就是「純函式、exclRows/rules 皆由參數傳入」，
 * 排除（逐筆 adjust／整年度 excl_rule）與「不符合標準的明細」可以整套直接借用，
 * 不必在這裡重寫一份——capacity_rate／process_ng_rate／order_target_amount／
 * shipping_target_amount 四個都在官方 kpi_as_detail_supported() 名單內。
 */
function kps_as_delegate_map(): array {
    return [
        'capacity_custom'  => 'capacity_rate',
        'process_ng_proc'  => 'process_ng_rate',
        'target_order'     => 'order_target_amount',
        'target_shipping'  => 'shipping_target_amount',
    ];
}

/** 這個 calculator_key 可不可以用「排除規則」（整年度依維度排除）；只有重用官方引擎的那四種才有 */
function kps_calc_dims(string $calc): array {
    $map = kps_as_delegate_map();
    return isset($map[$calc]) ? kpi_as_calc_dims($map[$calc]) : [];
}

/** 這個 calculator_key 支援不支援「數值明細／不符合標準的明細」 */
function kps_detail_supported(string $calc): bool {
    $map = kps_as_delegate_map();
    if (isset($map[$calc])) return kpi_as_detail_supported($map[$calc]);
    return in_array($calc, ['dev_eval_lead', 'type_ctrl', 'qc_by_proc', 'packing_efficiency',
                             'purchase_ontime', 'car_ontime', 'kpi_overall'], true);
}

/**
 * 依 calculator_key 分派到對應的試算函式，統一入口（新增指標只要在上面登記表加一筆、這裡加一個 case）。
 * $exclRows＝這一格（這個指標＋年＋月）逐筆排除的 row_key；$rules＝這個指標整年度的排除規則
 * （dim=>[val,...]），兩者皆由呼叫端（kpi_scheme_preview_row／明細 API）從 kpi_scheme_adjust／
 * kpi_scheme_excl_rule 讀出——本函式不自己查表，維持純函式好測試。
 */
function kpi_scheme_compute_by_key(PDO $db, string $calcKey, int $year, int $month, array $params,
                                   array $exclRows = [], array $rules = []): ?array {
    $asMap = kps_as_delegate_map();
    if (isset($asMap[$calcKey])) {
        $res = kpi_as_compute($db, $asMap[$calcKey], $year, $month, $params, $exclRows, $rules);
        if ($res === null) return null;
        $out = ['v'=>$res['value'] ?? null, 'num'=>$res['num'] ?? null, 'den'=>$res['den'] ?? null];
        // 春節自動調整（本方案自己算出來的 v，直接調整，不經過正式系統快照——
        // 與 kps_target_cny_adjusted() 同一套數學，差別只是作用對象是「自己算的 v」）
        if ($out['v'] !== null && kpi_as_pv($params, 'cny', false)) {
            $r = kps_cny_ratio($db, $year, $month);
            if ($r['final'] < 0.999) {
                $out['cny_orig_v'] = $out['v'];
                $out['v'] = $r['final'] > 0 ? $out['v'] / $r['final'] : null;
            }
            $out['cny_ratio'] = $r['final']; $out['cny_lost'] = $r['lost_days'];
        }
        return $out;
    }
    switch ($calcKey) {
        case 'existing': {
            $snap = kps_from_snapshot($db, (int)($params['item_no'] ?? 0), $year);
            return $snap[$month] ?? null;
        }
        case 'existing_cny':
            return kps_target_cny_adjusted($db, $year, $month, $params);
        case 'dev_eval_lead':      return kps_dev_eval($db, $year, $month, $params, $exclRows);
        case 'type_ctrl':          return kps_type_ctrl($db, $year, $month, $params, $exclRows);
        case 'qc_by_proc':         return kps_qc_by_proc($db, $year, $month, $params, $exclRows, $rules);
        case 'packing_efficiency': return kps_packing_efficiency($db, $year, $month, $params, $exclRows);
        case 'purchase_ontime':    return kps_purchase_ontime($db, $year, $month, $params, $exclRows);
        case 'car_ontime':         return kps_car_ontime($db, $year, $month, $params, $exclRows);
        case 'kpi_overall':        return kps_scheme_kpi_overall($db, $year, $month, $params, $exclRows);
        default: return null;
    }
}

/**
 * 全廠 KPI 總體達標率——改成對「這個新方案自己的指標集合」算達標率（不是正式系統的
 * 22 項），讀 kpi_scheme_indicator_year，查詢時排除自己這個 item_no 避免自我循環。
 */
/**
 * 全廠 KPI 總體達標率的來源列——每一個其他指標（排除自己）算一列。
 * 每個子指標都要套用**它自己的**逐筆排除／排除規則才算，否則總體達標率會跟
 * 畫面上那個子指標顯示的數字（已排除過的）對不起來。
 */
function kps_kpi_overall_rows(PDO $db, int $year, int $month, int $selfItemNo): array {
    kpi_scheme_ind_ensure_schema($db);
    $st = $db->prepare("SELECT i.indicator_id, i.item_no, i.name, i.freq, i.value_type,
                               y.calculator_key, y.params_json, y.target_direction, y.target_value, y.target_unit
                        FROM kpi_scheme_indicator i
                        JOIN kpi_scheme_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        WHERE i.is_active=1 AND y.is_active=1 AND y.source_mode='auto'");
    $st->execute([$year]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($selfItemNo > 0 && (int)$r['item_no'] === $selfItemNo) continue;
        $p = [];
        try { $p = json_decode((string)$r['params_json'], true) ?: []; } catch (Throwable $e) {}
        $iid = (int)$r['indicator_id'];
        // 非逐月指標（quarterly/halfyear/yearly）這個月不一定有值，照查就好，查不到就跳過不算
        $cell = kpi_scheme_compute_by_key($db, (string)$r['calculator_key'], $year, $month, $p,
            kps_adjust_keys($db, $iid, $year, $month), kps_excl_rules($db, $iid, $year));
        if (!$cell || $cell['v'] === null) continue;
        $v = (float)$cell['v']; $tv = $r['target_value'] === null ? null : (float)$r['target_value'];
        $below = false;
        if ($tv !== null) {
            if ($r['target_direction'] === 'lte') $below = $v > $tv;
            elseif ($r['target_direction'] === 'yes') $below = $v < 1;
            else $below = $v < $tv;
        }
        $out[] = ['key'=>(string)$iid, 'kind'=>$below ? 'bad' : 'info', 'dims'=>[],
                  'why'=>$below ? '未達目標' : '已達目標',
                  'vals'=>['item'=>'#'.$r['item_no'].' '.$r['name'],
                           'value'=>rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')
                                   . ($r['value_type'] === 'percent' ? '%' : (string)$r['target_unit'])]];
    }
    return $out;
}
function kps_scheme_kpi_overall(PDO $db, int $year, int $month, array $params, array $exclRows = []): ?array {
    $selfItemNo = (int)($params['_self_item_no'] ?? 0);   // 由呼叫端(kpi_scheme_preview_row)注入，避免自我循環
    return kps_calc_apply_excl(kps_kpi_overall_rows($db, $year, $month, $selfItemNo), $exclRows);
}

/**
 * 一次性種子：把 kpi_scheme_items() 目前的 23 項（含 1 項隱藏＝is_active=0）寫進新表，
 * 只給指定年度建立，已存在的 item_no 不重複寫入（可重複執行、不會長出重複資料）。
 * 回傳本次新增了幾項。只有 CLI 遷移腳本會呼叫，不是頁面載入路徑。
 */
function kpi_scheme_ind_seed(PDO $db, int $year, string $byName): int {
    kpi_scheme_ind_ensure_schema($db);
    $seq = 0; $created = 0;
    $stIns = $db->prepare("INSERT INTO kpi_scheme_indicator
        (item_no, block, name, freq, value_type, sort_order, is_active, Created_By)
        VALUES (?,?,?,?,?,?,?,?)");
    $stIy = $db->prepare("INSERT INTO kpi_scheme_indicator_year
        (indicator_id, year, owner_dept_id, source_mode, calculator_key, params_json,
         target_direction, target_value, target_unit, target_text, is_active, Created_By)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stChk = $db->prepare("SELECT indicator_id FROM kpi_scheme_indicator WHERE item_no=?");

    foreach (kpi_scheme_items() as $it) {
        $seq++;
        $stChk->execute([$seq]);
        if ($stChk->fetchColumn()) continue;   // 已經種過，不重複

        // 把舊的 calc=['existing',N] / ['preview',fn,args] / ['none'] 轉成新的 calculator_key/params
        $calc = $it['calc'] ?? ['none'];
        $mode = $calc[0] ?? 'none';
        $calcKey = null; $params = [];
        if ($mode === 'existing') {
            $calcKey = 'existing';
            $params = ['item_no' => (int)($calc[1] ?? 0)];
        } elseif ($mode === 'preview') {
            $fnMap = ['kps_dev_eval'=>'dev_eval_lead', 'kps_type_ctrl'=>'type_ctrl',
                      'kps_process_ng_proc'=>'process_ng_proc', 'kps_capacity_custom'=>'capacity_custom',
                      'kps_qc_by_proc'=>'qc_by_proc', 'kps_packing_efficiency'=>'packing_efficiency',
                      'kps_purchase_ontime'=>'purchase_ontime', 'kps_car_ontime'=>'car_ontime',
                      'kps_kpi_overall'=>'kpi_overall',
                      // 月份受訂/銷貨目標達成率實際是 calc=['preview','kps_target_cny_adjusted',...]
                      // （春節調整包裝函式），不是 ['existing',N]——它要對映到 existing_cny，
                      // 下面 'existing' 分支裡原本想在這裡做的特判永遠不會被走到。
                      'kps_target_cny_adjusted'=>'existing_cny'];
            $calcKey = $fnMap[(string)($calc[1] ?? '')] ?? null;
            $params = (array)($calc[2] ?? []);
        }
        $sourceMode = $calcKey ? 'auto' : 'manual';

        $stIns->execute([$seq, $it['block'], $it['name'], $it['freq'], $it['vtype'], $seq * 10,
                          empty($it['hidden']) ? 1 : 0, $byName]);
        $iid = (int)$db->lastInsertId();
        $stIy->execute([$iid, $year, $it['dept'] ?? null, $sourceMode, $calcKey,
                         $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
                         $it['dir'], $it['target'], $it['unit'], null,
                         empty($it['hidden']) ? 1 : 0, $byName]);
        $created++;
    }
    return $created;
}

/** 讀某年度全部指標＋設定（給頁面主表格與設定分頁共用） */
function kpi_scheme_list_year(PDO $db, int $year): array {
    kpi_scheme_ind_ensure_schema($db);
    $st = $db->prepare("SELECT i.indicator_id, i.item_no, i.block, i.name, i.freq, i.value_type,
                               i.sort_order, i.is_active AS ind_active,
                               y.iy_id, y.owner_dept_id, y.owner_user_id, y.owner_position_id, y.owner_display,
                               y.source_mode, y.calculator_key, y.params_json,
                               y.target_direction, y.target_value, y.target_unit, y.target_text,
                               y.note, y.is_active AS year_active
                        FROM kpi_scheme_indicator i
                        LEFT JOIN kpi_scheme_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        ORDER BY i.sort_order, i.item_no");
    $st->execute([$year]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ============================================================
 * 六之二、單一欄位修改／逐筆排除／排除規則（2026-10-05 續，比照 KPI.php）
 * ------------------------------------------------------------
 * 三張新表各自的存取函式，皆以 kpi_scheme_indicator.indicator_id 為鍵，
 * 與正式系統的 kpi_as_monthly_value／kpi_as_adjust／kpi_as_excl_rule 完全分離。
 * ============================================================ */

/** 某指標某年度逐月的人工填寫／覆寫值（month=>row） */
function kps_monthly_values(PDO $db, int $iid, int $year): array {
    kpi_scheme_ind_ensure_schema($db);
    $out = [];
    try {
        $st = $db->prepare("SELECT * FROM kpi_scheme_monthly_value WHERE indicator_id=? AND year=?");
        $st->execute([$iid, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['month']] = $r;
    } catch (Throwable $e) {}
    return $out;
}

/** 某一格已排除的 row_key（計算時用） */
function kps_adjust_keys(PDO $db, int $iid, int $year, int $month): array {
    kpi_scheme_ind_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT row_key FROM kpi_scheme_adjust WHERE indicator_id=? AND year=? AND month=?");
        $st->execute([$iid, $year, $month]);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }
}
/** 某一格已排除的來源列（完整資料，內部畫面用） */
function kps_adjust_rows(PDO $db, int $iid, int $year, int $month): array {
    kpi_scheme_ind_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT * FROM kpi_scheme_adjust WHERE indicator_id=? AND year=? AND month=? ORDER BY adj_id");
        $st->execute([$iid, $year, $month]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}
/** 這個指標目前適用的排除規則（本年度自己的＋標成「所有年度」的），給計算用：dim=>[val,...] */
function kps_excl_rules(PDO $db, int $iid, int $year): array {
    kpi_scheme_ind_ensure_schema($db);
    $out = [];
    try {
        $st = $db->prepare("SELECT dim, val FROM kpi_scheme_excl_rule WHERE indicator_id=? AND (year=? OR scope='all')");
        $st->execute([$iid, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (string)$r['dim']; $v = (string)$r['val'];
            if (!isset($out[$d]) || !in_array($v, $out[$d], true)) $out[$d][] = $v;
        }
    } catch (Throwable $e) {}
    return $out;
}
/** 這個指標目前適用的排除規則（完整資料，畫面用） */
function kps_excl_rule_rows(PDO $db, int $iid, int $year): array {
    kpi_scheme_ind_ensure_schema($db);
    try {
        $st = $db->prepare("SELECT * FROM kpi_scheme_excl_rule WHERE indicator_id=? AND (year=? OR scope='all')
                            ORDER BY scope DESC, dim, val");
        $st->execute([$iid, $year]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * 不符合標準的明細（數值明細）。回傳形狀與 kpi_as_detail() 完全相同：
 * ['cols','rows'(含 bad/info 兩種 kind)，'total'(僅 bad 計數)，'note'，'dims'(可篩選/可設規則的維度)，...]
 * 重用官方引擎的四種 calc 直接轉呼叫 kpi_as_detail()；本方案自有的七種另外組裝，
 * 一律走 kpi_as_detail_finish() 收尾（標記規則命中、整理維度選項），不另寫一份收尾邏輯。
 */
function kps_detail(PDO $db, string $calc, int $year, int $month, array $params, array $rules = []): array {
    $asMap = kps_as_delegate_map();
    if (isset($asMap[$calc])) {
        $d = kpi_as_detail($db, $asMap[$calc], $year, $month, $params, $rules);
        $d['supported'] = 1; $d['readonly'] = 0;
        return $d;
    }
    if ($calc === 'existing' || $calc === 'existing_cny') {
        // existing／existing_cny 本身只是讀正式系統的月快照，沒有自己的來源列——
        // 但正式項次背後用的計算模組如果本來就支援明細，就唯讀借用過來顯示
        // （只 SELECT 正式表的排除規則，不寫、也不提供排除功能，鐵則①不破壞）。
        $itemNo = (int)($params['item_no'] ?? 0);
        $oi = kps_official_calc_info($db, $itemNo, $year);
        if ($oi && $oi['calculator_key'] && kpi_as_detail_supported((string)$oi['calculator_key'])) {
            $oParams = kpi_as_params($oi['params_json']);
            $oRules = kpi_as_excl_rules($db, (int)$oi['indicator_id'], $year);
            $d = kpi_as_detail($db, (string)$oi['calculator_key'], $year, $month, $oParams, $oRules);
            $d['supported'] = 1; $d['readonly'] = 1;
            $noteParts = ['（沿用正式 KPI 表「#' . $itemNo . '」的明細，僅供檢視）'];
            // 明細是依公式即時重算出來的，畫面上那一格顯示的數字如果是「管理者手動覆寫」，
            // 兩者天生就對不上——覆寫就是人判斷公式算出來的不準才手動修正的，不講清楚
            // 使用者會以為系統算錯。
            $snap = kps_from_snapshot($db, $itemNo, $year);
            $cell = $snap[$month] ?? null;
            $cellSrc = $cell['src'] ?? '';
            if ($cell && ($cellSrc === 'override' || $cellSrc === 'manual')) {
                $noteParts[] = '⚠ 畫面上這一格目前顯示的 ' . rtrim(rtrim(number_format((float)$cell['v'], 2), '0'), '.')
                             . ' 是' . ($cellSrc === 'override' ? '管理者手動覆寫' : '人工填寫') . '的數字，'
                             . '不是下面這份依公式即時重算的明細算出來的（公式算出來的是分子/分母比對應的那個數字），'
                             . '兩者不一定相同，請以畫面上顯示的那個數字為準。';
            }
            $d['note'] = implode(' ', $noteParts) . ($d['note'] ?? '');
            return $d;
        }
        $hasOfficialCalc = $oi && !empty($oi['calculator_key']);
        $out = ['cols'=>[], 'rows'=>[], 'total'=>0, 'supported'=>0, 'readonly'=>1,
                'note'=>$hasOfficialCalc ? ('這個計算方式（' . (string)$oi['calculator_key'] . '）在正式系統也還沒有逐筆明細，請到正式 KPI 表查看目前數值。')
                      : ($oi ? '正式項次是人工填寫，沒有來源明細可看，請到正式 KPI 表查看目前數值。'
                             : '找不到對應的正式指標。')];
        return kpi_as_detail_finish($out, []);
    }
    $out = ['cols'=>[], 'rows'=>[], 'total'=>0, 'note'=>'', 'supported'=>1, 'readonly'=>0];
    switch ($calc) {
        case 'dev_eval_lead':
            $out['cols'] = [['k'=>'doc','t'=>'文件編號'], ['k'=>'part','t'=>'料號/品名'],
                            ['k'=>'fill','t'=>'填表日'], ['k'=>'close','t'=>'決行日'], ['k'=>'days','t'=>'工作日']];
            $out['note'] = '分母＝本月填表的產品開發評估表；分子＝門檻工作日內完成決行者。';
            $out['rows'] = kps_dev_eval_rows($db, $year, $month, max(1, (int)($params['days'] ?? 10)));
            break;
        case 'type_ctrl':
            $out['cols'] = [['k'=>'doc','t'=>'文件編號'], ['k'=>'proc','t'=>'製程'], ['k'=>'status','t'=>'確認狀態']];
            $out['note'] = '分母＝已建立的型態識別文件管制表（現況快照，不分月份）；分子＝已確認者。';
            $out['rows'] = kps_type_ctrl_rows($db);
            break;
        case 'qc_by_proc':
            $out['cols'] = [['k'=>'bom','t'=>'製令'], ['k'=>'proc','t'=>'製程'],
                            ['k'=>'date','t'=>'檢驗日'], ['k'=>'result','t'=>'判定']];
            $out['note'] = '分母＝本月已判定的檢驗筆數；分子＝判定非指定不良項目者。';
            $out['rows'] = kps_qc_by_proc_rows_year($db, $year, kpi_as_list($params['procs'] ?? []),
                kpi_as_list($params['ptypes'] ?? []), kpi_as_list($params['ngs'] ?? ['ng']))[$month] ?? [];
            break;
        case 'packing_efficiency':
            $out['cols'] = [['k'=>'bom','t'=>'製令'], ['k'=>'start','t'=>'前一關完成日'],
                            ['k'=>'end','t'=>'包裝結案日'], ['k'=>'days','t'=>'工作日']];
            $out['note'] = '分母＝本月結案的包裝檢驗；分子＝前一關完成到包裝結案在門檻工作日內者。';
            $out['rows'] = kps_packing_efficiency_rows_year($db, $year, max(1, (int)($params['days'] ?? 3)))[$month] ?? [];
            break;
        case 'purchase_ontime':
            $out['cols'] = [['k'=>'req','t'=>'請購單號'], ['k'=>'item','t'=>'項目'],
                            ['k'=>'expected','t'=>'預計到貨日'], ['k'=>'got','t'=>'實際到貨日']];
            $out['note'] = '分母＝預計到貨日落在本月的請購項目；分子＝實際到貨日未超過預計到貨日者。';
            $out['rows'] = kps_purchase_ontime_rows($db, $year, $month);
            break;
        case 'car_ontime':
            $out['cols'] = [['k'=>'no','t'=>'矯正單號'], ['k'=>'due','t'=>'應結案日'], ['k'=>'close','t'=>'實際結案日']];
            $out['note'] = '分母＝應結案日落在本月的矯正措施；分子＝實際結案日未超過應結案日者。';
            $out['rows'] = kps_car_ontime_rows($db, $year, $month);
            break;
        case 'kpi_overall':
            $out['cols'] = [['k'=>'item','t'=>'指標'], ['k'=>'value','t'=>'本月數值']];
            $out['note'] = '分母＝本方案當月有數值的其他指標數；分子＝其中達成目標者。';
            $out['rows'] = kps_kpi_overall_rows($db, $year, $month, (int)($params['_self_item_no'] ?? 0));
            break;
        default:
            $out['note'] = '這個計算方式沒有逐筆明細。';
            $out['supported'] = 0;
    }
    return kpi_as_detail_finish($out, $rules);
}

/** 查某個正式 KPI 項次目前的計算方式與參數（唯讀；給 existing／existing_cny 的「數值明細」借用正式明細用，
 *  只 SELECT 不寫——鐵則①已經在用的同一種唯讀借用，這裡只是多借「明細」這一塊） */
function kps_official_calc_info(PDO $db, int $itemNo, int $year): ?array {
    if ($itemNo <= 0) return null;
    $st = $db->prepare("SELECT i.indicator_id, y.calculator_key, y.params_json
                        FROM kpi_as_indicator i
                        JOIN kpi_as_indicator_year y ON y.indicator_id=i.indicator_id AND y.year=?
                        WHERE i.item_no=?");
    $st->execute([$year, $itemNo]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** 單一指標的試算（DB 列版本，取代舊的 kpi_scheme_preview($item)；邏輯相同只是資料來源換成 DB）。
 *  依序決定每一格要顯示的值：覆寫（override）＞人工填寫（manual）＞自動計算（auto，已套用排除）。 */
function kpi_scheme_preview_row(PDO $db, array $row, int $year): ?array {
    if ((int)($row['year_active'] ?? 0) !== 1) return null;
    $iid = (int)$row['indicator_id'];
    $curY = (int)date('Y'); $curM = (int)date('n');
    $mv = kps_monthly_values($db, $iid, $year);
    $out = [];

    if ((string)$row['source_mode'] !== 'auto' || empty($row['calculator_key'])) {
        // 人工填寫：只讀 kpi_scheme_monthly_value.manual_value，沒填就是 null（畫面顯示「–」）。
        // 月份範圍一律用 kpi_as_valid_months()（手動填寫可選期間內任何一個月記錄），
        // 不可用 kpi_as_months()（那只會列出季/半年/年的 bucket 結束月，會漏看別的月填的值）。
        foreach (kpi_as_valid_months($row) as $m) {
            if ($year > $curY || ($year === $curY && $m > $curM)) { $out[$m] = null; continue; }
            $r = $mv[$m] ?? null;
            $out[$m] = ($r && $r['manual_value'] !== null)
                ? ['v'=>(float)$r['manual_value'], 'num'=>null, 'den'=>null, 'src'=>'manual',
                   'filled_by'=>$r['filled_by_name'] ?? '', 'filled_at'=>$r['filled_at'] ?? '', 'note'=>$r['note'] ?? '']
                : null;
        }
        return $out;
    }

    $params = [];
    try { $params = json_decode((string)$row['params_json'], true) ?: []; } catch (Throwable $e) {}
    if ($row['calculator_key'] === 'kpi_overall') $params['_self_item_no'] = (int)$row['item_no'];
    $rules = kps_excl_rules($db, $iid, $year);

    foreach (kpi_as_valid_months($row) as $m) {
        if ($year > $curY || ($year === $curY && $m > $curM)) { $out[$m] = null; continue; }
        $r = $mv[$m] ?? null;
        if ($r && $r['override_value'] !== null) {
            $out[$m] = ['v'=>(float)$r['override_value'], 'num'=>null, 'den'=>null, 'src'=>'override',
                        'ov_by'=>$r['override_by_name'] ?? '', 'ov_at'=>$r['override_at'] ?? '',
                        'ov_reason'=>$r['override_reason'] ?? ''];
            continue;
        }
        $exclRows = kps_adjust_keys($db, $iid, $year, $m);
        try { $c = kpi_scheme_compute_by_key($db, (string)$row['calculator_key'], $year, $m, $params, $exclRows, $rules); }
        catch (Throwable $e) { $c = null; }
        if ($c !== null) $c['src'] = 'auto';
        $out[$m] = $c;
    }
    return $out;
}

/** 人工填寫（source_mode=manual 專用）。寫入 kpi_scheme_monthly_value.manual_value。 */
function kps_fill_save(PDO $db, int $iid, int $year, int $month, float $val, string $note, int $uid, string $uname): void {
    kpi_scheme_ind_ensure_schema($db);
    $st = $db->prepare("INSERT INTO kpi_scheme_monthly_value
            (indicator_id,year,month,manual_value,filled_by,filled_by_name,filled_at,note)
            VALUES (?,?,?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE manual_value=VALUES(manual_value), filled_by=VALUES(filled_by),
                    filled_by_name=VALUES(filled_by_name), filled_at=NOW(), note=VALUES(note)");
    $st->execute([$iid, $year, $month, $val, $uid, $uname, $note !== '' ? $note : null]);
}
function kps_fill_clear(PDO $db, int $iid, int $year, int $month): void {
    kpi_scheme_ind_ensure_schema($db);
    $db->prepare("UPDATE kpi_scheme_monthly_value SET manual_value=NULL, filled_by=NULL,
                  filled_by_name=NULL, filled_at=NULL, note=NULL WHERE indicator_id=? AND year=? AND month=?")
       ->execute([$iid, $year, $month]);
}
/** 手動覆寫（source_mode=auto 專用，顯示優先序最高）。寫入 kpi_scheme_monthly_value.override_value。 */
function kps_override_save(PDO $db, int $iid, int $year, int $month, float $val, string $reason, int $uid, string $uname): void {
    kpi_scheme_ind_ensure_schema($db);
    $st = $db->prepare("INSERT INTO kpi_scheme_monthly_value
            (indicator_id,year,month,override_value,override_by,override_by_name,override_at,override_reason)
            VALUES (?,?,?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE override_value=VALUES(override_value), override_by=VALUES(override_by),
                    override_by_name=VALUES(override_by_name), override_at=NOW(), override_reason=VALUES(override_reason)");
    $st->execute([$iid, $year, $month, $val, $uid, $uname, $reason]);
}
function kps_override_clear(PDO $db, int $iid, int $year, int $month): void {
    kpi_scheme_ind_ensure_schema($db);
    $db->prepare("UPDATE kpi_scheme_monthly_value SET override_value=NULL, override_by=NULL,
                  override_by_name=NULL, override_at=NULL, override_reason=NULL
                  WHERE indicator_id=? AND year=? AND month=?")
       ->execute([$iid, $year, $month]);
}

/* ============================================================
 * 七、設定頁寫入（給 KPI_new.php 的「設定」分頁用，一樣只碰新表）
 * ============================================================ */

/** 下一個可用 item_no（不要求連續，單純找最大值+1） */
function kpi_scheme_next_item_no(PDO $db): int {
    kpi_scheme_ind_ensure_schema($db);
    return (int)$db->query("SELECT COALESCE(MAX(item_no),0)+1 FROM kpi_scheme_indicator")->fetchColumn();
}

/**
 * 新增或更新指標主檔。$post['indicator_id']<=0 時建立新指標（自動配 item_no，
 * 並同時建立該年度的 indicator_year 空列，呼叫端再用 kpi_scheme_iy_save 補上目標/來源）。
 * 回傳 indicator_id。
 */
function kpi_scheme_ind_save(PDO $db, array $post, int $year, string $byName): int {
    kpi_scheme_ind_ensure_schema($db);
    $iid = (int)($post['indicator_id'] ?? 0);
    $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 100);
    if ($name === '') throw new RuntimeException('指標名稱必填');
    $block = (string)($post['block'] ?? '');
    if (!isset(kpi_scheme_blocks()[$block])) throw new RuntimeException('區塊不合法');
    $freq = in_array($post['freq'] ?? '', ['monthly','quarterly','halfyear','yearly'], true) ? $post['freq'] : 'monthly';
    $vt = in_array($post['value_type'] ?? '', ['percent','count','score','rate','yesno'], true) ? $post['value_type'] : 'percent';
    $active = (int)($post['is_active'] ?? 1) ? 1 : 0;

    if ($iid > 0) {
        $st = $db->prepare("SELECT 1 FROM kpi_scheme_indicator WHERE indicator_id=?");
        $st->execute([$iid]);
        if (!$st->fetchColumn()) throw new RuntimeException('找不到指標');
        $sort = (int)($post['sort_order'] ?? 0);
        $st = $db->prepare("UPDATE kpi_scheme_indicator SET name=?, block=?, freq=?, value_type=?,
                            sort_order=?, is_active=?, Modified_By=?, Modified_At=NOW() WHERE indicator_id=?");
        $st->execute([$name, $block, $freq, $vt, $sort, $active, $byName, $iid]);
        return $iid;
    }

    $itemNo = kpi_scheme_next_item_no($db);
    $sort = $itemNo * 10;
    $st = $db->prepare("INSERT INTO kpi_scheme_indicator (item_no, block, name, freq, value_type, sort_order, is_active, Created_By)
                        VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([$itemNo, $block, $name, $freq, $vt, $sort, $active, $byName]);
    $iid = (int)$db->lastInsertId();
    // 同時建一列空的年度設定，呼叫端馬上會用 kpi_scheme_iy_save 補上目標/擔當者/來源——
    // 不先建這一列的話，新增指標後要等使用者填完年度設定才看得到這一列，體驗上像是「存了但不見了」。
    $st = $db->prepare("INSERT INTO kpi_scheme_indicator_year (indicator_id, year, source_mode, target_direction, is_active, Created_By)
                        VALUES (?,?,'manual','gte',?,?)");
    $st->execute([$iid, $year, $active, $byName]);
    return $iid;
}

/** 新增或更新指標的年度設定（目標/擔當者/來源/參數）。$params 為關聯陣列，會被 json_encode。 */
function kpi_scheme_iy_save(PDO $db, int $indicatorId, int $year, array $post, array $params, string $byName): void {
    kpi_scheme_ind_ensure_schema($db);
    $sourceMode = ($post['source_mode'] ?? '') === 'auto' ? 'auto' : 'manual';
    $calcKey = $sourceMode === 'auto' ? trim((string)($post['calculator_key'] ?? '')) : null;
    if ($sourceMode === 'auto' && $calcKey === '') throw new RuntimeException('自動模式請選擇計算方式');
    if ($calcKey !== null && !isset(kpi_scheme_registry()[$calcKey])) throw new RuntimeException('計算方式不合法');
    $dir = in_array($post['target_direction'] ?? '', ['gte','lte','yes'], true) ? $post['target_direction'] : 'gte';
    $tv = ($post['target_value'] ?? '') === '' ? null : (float)$post['target_value'];
    $tu = mb_substr(trim((string)($post['target_unit'] ?? '')), 0, 20);
    $tt = mb_substr(trim((string)($post['target_text'] ?? '')), 0, 60);
    $note = mb_substr(trim((string)($post['note'] ?? '')), 0, 200);
    $active = (int)($post['is_active'] ?? 1) ? 1 : 0;
    $deptId = ($post['owner_dept_id'] ?? '') !== '' ? (int)$post['owner_dept_id'] : null;
    $userId = ($post['owner_user_id'] ?? '') !== '' ? (int)$post['owner_user_id'] : null;
    $posId  = ($post['owner_position_id'] ?? '') !== '' ? (int)$post['owner_position_id'] : null;
    $disp = mb_substr(trim((string)($post['owner_display'] ?? '')), 0, 50);

    $st = $db->prepare("SELECT iy_id FROM kpi_scheme_indicator_year WHERE indicator_id=? AND year=?");
    $st->execute([$indicatorId, $year]);
    $exists = $st->fetchColumn();
    $paramsJson = $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null;

    if ($exists) {
        $st = $db->prepare("UPDATE kpi_scheme_indicator_year SET
            owner_dept_id=?, owner_user_id=?, owner_position_id=?, owner_display=?,
            source_mode=?, calculator_key=?, params_json=?,
            target_direction=?, target_value=?, target_unit=?, target_text=?, note=?, is_active=?,
            Modified_By=?, Modified_At=NOW() WHERE indicator_id=? AND year=?");
        $st->execute([$deptId, $userId, $posId, $disp, $sourceMode, $calcKey, $paramsJson,
                      $dir, $tv, $tu, $tt, $note, $active, $byName, $indicatorId, $year]);
    } else {
        $st = $db->prepare("INSERT INTO kpi_scheme_indicator_year
            (indicator_id, year, owner_dept_id, owner_user_id, owner_position_id, owner_display,
             source_mode, calculator_key, params_json, target_direction, target_value, target_unit,
             target_text, note, is_active, Created_By)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$indicatorId, $year, $deptId, $userId, $posId, $disp, $sourceMode, $calcKey, $paramsJson,
                      $dir, $tv, $tu, $tt, $note, $active, $byName]);
    }
}
