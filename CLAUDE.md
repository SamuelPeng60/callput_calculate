# CLAUDE.md

台股權證分析工具：輸入股票代號，用同類權證的 BIV 中位數算合理價，標出便宜/合理/偏貴。
使用者看的說明在 README.md；這份是給 Claude 的工作須知。

## 執行 (本機 Windows)

winget 裝的 PHP 8.4 **沒有 php.ini**，`pdo_sqlite`/`curl`/`mbstring`/`openssl` 預設沒開。
使用者決定**不改 PHP 安裝**，一律用 `-d` 參數載入 (不要再提議建 php.ini)：

```bash
D=$(cygpath -w /c/Users/rd7/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe/ext)
PHPX="php -d extension_dir=$D -d extension=pdo_sqlite -d extension=curl -d extension=mbstring -d extension=openssl"

$PHPX -S 127.0.0.1:8000 -t public          # 開 server (用 run_in_background)
$PHPX console.php migrate                  # 改了 schema 之後要跑 (會自動補新欄位)
$PHPX console.php sync-real 2330 [Y-m-d]   # 手動同步真實資料
```

- 只做語法檢查可以直接 `php -l`，不需要擴充。
- PHP 檔每次請求都重新載入，改程式**不用重開 server**。
- `SyncJob::spawn()` 會用同一組 `-d` 參數開子程序；改擴充清單時兩邊要一致。

## 架構重點

- 純 PHP，沒有 Composer/框架；結構仿 Laravel (`app/Models`、`app/Services`、`app/Console`)。
  `bootstrap.php` 自己做 autoload，`console.php` 是 CLI 入口，`public/index.php` 是 API + 前端入口。
- 前端是單一檔案 `public/app.html` (純 JS，無建置)。
- 資料流：`SyncReal` (TWSE/TPEx 行情 + 權證條件 + FinMind 日線) → `CalculateMetrics` →
  `WarrantValuationService` (純運算，不碰 DB) → `warrant_daily_metrics`。
- **網頁查詢不能同步跑 SyncReal**：PHP 內建 server 一次只處理一個請求，下載 40MB 條件檔會卡住整站。
  一律透過 `SyncJob` 開背景程序，狀態檔在 `storage/jobs/`，前端輪詢 `/api/sync-status`，
  完成後跳「更新完成」並刷新一次。
- 權證條件來源只有「最新一天」，所以每天存精簡快照 `storage/cache/*_terms_Ymd.json`；
  每筆 `warrant_quotes` 記下當天用的履約價/行使比例，計算與 API 以它為準。

## 資料源的坑

- TWSE `MI_INDEX` 在**假日和收盤前也回 `stat: OK`**，只是表格 data 是空的 —— 要當成未公布，不能快取。
- TWSE 條件檔 `t187ap37_L` 約 40MB，證交所晚上可能只有 ~200KB/s (3 分多鐘)，下載逾時設 600 秒。
- 收盤行情約 15:00 後公布。FinMind `TaiwanStockInfoWithWarrantSummary` 要付費 (舊流程 sync-warrants 用)。
- 可能有多個背景程序同時寫同一個快取檔：寫檔用 `MarketData::putFile()` (暫存檔 + rename)。

## 測試方式

- 沒有自動化測試，也**沒有 Node**。前端要驗證就用 headless Chrome
  (`C:/Program Files/Google/Chrome/Application/chrome.exe`)：
  在 scratchpad 開一個測試 server，router 把 `/test` 輸出成 `app.html` 並注入測試 script，其餘轉給 `public/index.php`。
  - 短流程：`--headless=new --virtual-time-budget=60000 --dump-dom`，結果寫進頁面上的 `<pre>`。
  - 要等真實背景同步的長流程：`--dump-dom` 會在頁面載入完就輸出，不能用；
    改讓測試 script 把結果 POST 回 router 寫檔，Chrome 在背景跑，測完要記得關掉。
  - Claude in Chrome 擴充不一定連得上，不要依賴它。
- 本機 DB `database/warrant.sqlite` 有真實資料；測試前先備份，造的假資料 (例如改 trade_date) 要清掉。

## 慣例

- 程式註解、使用者訊息、README 用繁體中文；commit message 用英文 (一行標題 + 條列)，直接 commit 在 `main`。
- 檔案是 CRLF (git autocrlf)；用 sed 改檔時 `$` 錨點會對不上 `\r`，多行修改用 Edit 或 Python。
- 前端篩選/排序規則：預設依標籤 (合理→便宜→偏貴)，同標籤成交量大的在前；
  每日衰減 > 2% 的一律排最後 (依衰減%排序時除外)。
