<?php
// 設定回應為 JSON
header('Content-Type: application/json');

// 接收前端傳來的 JSON 資料
$input = json_decode(file_get_contents('php://input'), true);
$data = isset($input['data']) ? $input['data'] : [];
$user_prompt = isset($input['user_prompt']) ? trim($input['user_prompt']) : '';

if (empty($data)) {
    echo json_encode(['success' => false, 'message' => '沒有收到資料']);
    exit;
}

// 1. 檢查 Ollama 服務狀態與模型列表
$ch_tags = curl_init("http://localhost:11434/api/tags");
curl_setopt($ch_tags, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_tags, CURLOPT_TIMEOUT, 3); // 快速檢查連線
$tags_response = curl_exec($ch_tags);

if (curl_errno($ch_tags)) {
    echo json_encode(['success' => false, 'message' => '無法連接本地 AI 服務 (Ollama)。請確認您已安裝並啟動 Ollama (http://localhost:11434)。']);
    curl_close($ch_tags);
    exit;
}
curl_close($ch_tags);

$tags_data = json_decode($tags_response, true);
$models = isset($tags_data['models']) ? $tags_data['models'] : [];
$target_model = '';
$installed_list = [];

// 定義支援的模型關鍵字 (優先順序)
$supported_keywords = ['llama3', 'gemma', 'mistral', 'qwen'];

foreach ($models as $m) {
    $name = $m['name'];
    $installed_list[] = $name;
}

// 依序尋找支援的模型
foreach ($supported_keywords as $keyword) {
    foreach ($installed_list as $installed_name) {
        if (strpos($installed_name, $keyword) !== false) {
            $target_model = $installed_name;
            break 2; 
        }
    }
}

if (empty($target_model)) {
    $msg = "未檢測到支援的 AI 模型 (Llama 3, Gemma, Mistral, Qwen)。請在終端機執行 `ollama pull llama3` 或其他支援模型進行安裝。";
    if (!empty($installed_list)) {
        $msg .= "\n(目前已安裝的模型: " . implode(', ', $installed_list) . ")";
    }
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// 為了避免超過 AI 的處理上限 (Context Window)，建議限制筆數或只傳送關鍵欄位
// 這裡範例取前 50 筆
// $data_sample = array_slice($data, 0, 50); // 移除筆數限制，確保分析結果與統計表一致
$json_string = json_encode($data, JSON_UNESCAPED_UNICODE);

// 設定 Prompt (提示詞)
// 使用固定的綜合分析 Prompt
$prompt = "Role: Professional Sales Data Analyst & Auditor.\n" .
          "Task: Analyze the provided sales data (JSON) and generate a comprehensive report in Traditional Chinese (繁體中文).\n" .
          "Note: The data provided has already been filtered for `is_count=1` (valid sales records).\n\n" .
          "Please structure the report as follows:\n\n" .
          "### 1. 異常與稽核分析 (優先顯示)\n" .
          "- **金額異常**：找出金額 <= 0 或異常偏高(>100萬)的交易。\n" .
          "- **數量/單價異常**：Qty=0 或 Unit_price=0 的交易。\n" .
          "- **重複交易**：同單號或同內容的重複出貨。\n" .
          "- **其他異常**：任何不合常理的交易。\n" .
          "- **重要**：列出具體異常訂單時，請務必使用格式 `[單號:單號內容]` (例如 `[單號:IS20230101]`)，以便系統建立連結。\n\n" .
          "### 2. 基本營運分析\n" .
          "- 總出貨金額、總數量、總筆數。\n" .
          "- 平均單筆出貨金額。\n\n" .
          "### 3. 客戶與產品分析\n" .
          "- 前 5 大客戶 (依金額排序)。\n" .
          "- 熱銷產品 TOP 5 (依金額或數量)。\n" .
          "- 滯銷產品或單價異常波動的產品(若有)。\n\n" .
          "### 4. 趨勢與建議\n" .
          "- 簡述出貨時間趨勢 (如月底/月初差異)。\n" .
          "- 給予管理層的簡短建議。\n\n" .
          "Constraints:\n" .
          "- Use Markdown formatting (bold, list, etc.).\n" .
          "- Do not use conversational filler (e.g., 'Here is the report'). Start directly with the report.\n\n" .
          "Data:\n" . $json_string;

// 呼叫本地 Ollama API
$api_url = "http://localhost:11434/api/generate";
$payload = [
    "model" => $target_model, // 使用偵測到的模型名稱
    "prompt" => $prompt,
    "stream" => false // 設定為 false 以便一次取得完整回應
];

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 180); // AI 分析需要時間，設定 180秒超時

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(['success' => false, 'message' => 'AI 服務連線超時或錯誤: ' . curl_error($ch)]);
} else {
    $result = json_decode($response, true);
    if (isset($result['response'])) {
        echo json_encode(['success' => true, 'analysis' => $result['response'], 'model_used' => $target_model]);
    } else {
        echo json_encode(['success' => false, 'message' => 'AI 回傳格式異常', 'debug' => $response]);
    }
}
curl_close($ch);
?>