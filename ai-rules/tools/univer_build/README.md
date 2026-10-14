# Univer 本機打包（表格模板用）

站上的「表格模板管理／填寫」用 **Univer**（Apache-2.0，免費、本機執行、不連外）。
內網連不出去，所以不走 CDN：用 esbuild 打包成單檔放進專案。

產出（已進版控，一般情況不必重打包）：
- `resource/js/eg_univer.js`（約 12MB，視窗全域 `window.EGUniver`）
- `resource/css/eg_univer.css`

## 要升版或重新打包時

```
mkdir 暫存目錄 && cd 暫存目錄
npm init -y
npm i @univerjs/presets @univerjs/preset-sheets-core @univerjs/preset-sheets-data-validation react react-dom rxjs esbuild
cp <專案>/ai-rules/tools/univer_build/entry.js .
node_modules/.bin/esbuild entry.js --bundle --minify --format=iife --outfile=out/eg_univer.js \
  --define:process.env.NODE_ENV='"production"' --loader:.png=dataurl --loader:.svg=dataurl \
  --loader:.woff2=dataurl --loader:.woff=dataurl --loader:.ttf=dataurl
cp out/eg_univer.js <專案>/resource/js/ ; cp out/eg_univer.css <專案>/resource/css/
```

## 注意

- **不要用 Univer 的付費功能**：儲存格保護／權限（Pro）、列印、匯出等。本專案的記入鎖定改由
  `resource/js/eg_sheet_form.js`（前端攔截）＋ `src/common/sheet_form_lib.php`（後端只收待填欄位）自己做。
- 升版後務必重測：設計器套用待填欄位、填寫鎖定、加明細列（SUM 自動延伸）、列印。
  `insertRowAfter` 沿用樣式與資料驗證、但**不複製** `custom` 的行為，是 `eg_sheet_form.js` 加列邏輯的前提。
- 載入這個檔的頁面只有 `sheet_form_template.php`、`sheet_form.php`，不要放進訂單追蹤等常用頁面的載入路徑。
