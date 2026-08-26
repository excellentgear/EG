# src/bin — 隨專案帶著走的外部執行檔

## jpegtran.exe ＋ jpeg62.dll（libjpeg-turbo 3.2.0，vc-x64）

**用途**：JPG 的**無損旋轉**。一般的旋轉（GD／ImageMagick／Windows 內建）都是
「解碼 → 轉 → 重新編碼」，每轉一次就多一代壓縮損失、檔案還會變大；jpegtran 是直接搬
JPEG 內部的 DCT 係數，**畫素一個都不會變**。

唯一呼叫端：`src/common/image_rotate_lib.php` 的 `eg_rotate_file()`
（jpegtran 不在／不能用／不是 JPG 時會自動退回 GD，不會變成按了沒反應）。

**實測數據**（2026-08-26，用 7016×4961 的真實掃描圖面）：

| 作法 | 來回轉一次後與原圖的差異 | 檔案大小 | 耗時 |
|---|---|---|---|
| GD（原本的作法） | PSNR 53 dB（看不出來但確實有變） | 1.05MB → 2.4MB | 約 1100 ms |
| Windows 內建 WPF | 也是重新編碼 | → 1.8MB | 約 660 ms |
| ImageMagick | 也是重新編碼 | → 1.7MB | — |
| **jpegtran** | **完全相同（MAE = 0）** | **1,046,313 → 1,046,317 bytes** | **約 100 ms** |

**為什麼不加 `-trim`**：這張圖高 4961 不是 8 的倍數，最後一個不完整的區塊沒辦法轉置。
不加 `-trim` 時 jpegtran 會保留它、內容等於平移 1 個畫素並在邊緣補白（實測那 8 欄
mean=255、標準差=0，是乾淨白邊不是雜訊），而且**來回轉會完全還原**；加了 `-trim` 位移
一樣存在、還會每轉一次就削掉一列畫素。所以選擇一個畫素都不丟的作法。

**為什麼帶有 EXIF 方向的 JPG 不走這裡**：jpegtran 會原樣複製 EXIF，但不會更新
Orientation 標籤，於是瀏覽器會「再轉一次」＝總共轉了兩次。那種檔案交給 GD 處理
（GD 會先把 EXIF 方向烙進畫素再轉）。

### 來源與授權
- 官方 GitHub Release：`libjpeg-turbo/libjpeg-turbo` v3.2.0 → `libjpeg-turbo-3.2.0-vc-x64.exe`
  （SHA256 `662761d8ba8dae04aec74023ebaeceb856c2b56b9b59cfd180759d26300dda42`）
- 以該安裝檔的靜默模式解到暫存目錄後，只取出 `jpegtran.exe`／`jpeg62.dll`，
  **沒有安裝在這台機器上**（安裝痕跡已移除）。
- 授權：見同目錄 `LICENSE-libjpeg-turbo.md`（IJG／BSD 類授權，允許隨程式散布）。

### 換機／還原備份時要注意
這兩個檔已經進版控，所以 clone 或還原後自動就有，不必再做任何安裝。
`jpegtran.exe` 需要 `jpeg62.dll` 放在**同一個資料夾**（程式會一起呼叫），不要只搬其中一個。
