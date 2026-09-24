# 台股權證分析工具 — 後端 (MVP / 盤後版)

## 現況說明

這個沙盒環境的網路政策擋掉了 Packagist、GitHub、以及所有台股資料來源網域
(TWSE、FinMind、mis.twse...)，所以：

1. **沒有用 Composer / Laravel 框架**，改用純 PHP 8.4 手刻(routing、DB、autoload)，
   結構刻意比照 Laravel 慣例(`app/Models`、`app/Services`、`app/Console`)，
   之後你要在自己機器上 `composer create-project laravel/laravel` 搬過去，
   核心的 `Services/BlackScholes.php`、`Services/WarrantValuationService.php`
   完全不用改，直接複製過去當 Service class 用即可。
2. **抓資料的 class 寫好了，但沒辦法在這裡實際連線測試**
   (`FinMindClient`、`SyncWarrants`、`SyncUnderlyingPrice`)。這些程式碼照
   FinMind 官方 API 文件寫，但你必須搬到你自己有對外網路的伺服器
   (例如你的 `vmtfn209114`)上才能真的抓到資料。
3. **計算引擎(BS 定價 / IV 反解 / 合理價判斷)是這裡的重點，已經完整測試過**，
   用模擬資料驗證：故意做高 IV 的權證會被抓成「偏貴」、做低的會被抓成「便宜」，
   結果符合預期(見下方「已驗證」)。

## 目錄結構

```
app/
  Support/         Env、Database (PDO 連線，sqlite/mysql 切換只改 .env)
  Models/          UnderlyingStock, Warrant, PriceSnapshot, WarrantQuote, WarrantDailyMetric
  Services/
    BlackScholes.php            BS定價公式、Delta/Theta、歷史波動率、IV反解(牛頓法+二分法備援)
    WarrantValuationService.php 核心：BIV分組比較 -> 合理BIV -> 合理價 -> 貴/便宜標籤
    FinMindClient.php           FinMind API 客戶端(權證清單 + 標的歷史股價)
  Console/
    SyncWarrants.php            同步某標的的全部權證清單 (需要網路)
    SyncUnderlyingPrice.php     同步標的歷史股價 (需要網路，算HV用)
    ImportQuotes.php            匯入權證委買/委賣/收盤價 CSV (跟資料來源解耦)
    CalculateMetrics.php        盤後批次主流程，串起以上所有東西
database/
  schema.sql       資料表結構
  seed_demo.php    灌模擬資料(不需網路)，用來驗證整套流程
public/
  index.php        API: GET /api/warrants?stock_id=2330
console.php        CLI 入口
```

## 合理價判斷邏輯 (對應研究報告)

1. 用**委買價**反解每檔權證的隱含波動率 **BIV**(投資人只能用委買價賣出，
   BIV 才反映持有人真正拿得到的價值)。
2. 把同標的、**同認購/認售、價內外程度相近(每5%一組)、到期天數相近**的
   權證分成一組(peer group)，取該組 **BIV 中位數**當作「合理 BIV」
   (樣本太少時退回全標的中位數，再不行退回歷史波動率 HV)。
3. 用合理 BIV 代回 Black-Scholes 反算「合理(委買)價」。
4. 實際委買價 vs 合理價偏離超過門檻(預設 ±15%，可在 `.env` 調)
   -> 標「偏貴」/「便宜」，否則「合理」。

## 怎麼跑(在這個沙盒裡可以完整跑一次，不需要網路)

```bash
cd warrant-analyzer
cp .env.example .env
php console.php migrate        # 建表
php console.php seed-demo      # 灌模擬資料(12檔權證，故意做幾檔IV異常)
php console.php calculate 2330 2026-09-18   # 跑計算引擎

# 啟動 (同一個伺服器同時服務前端頁面 + API)
php -S 127.0.0.1:8000 -t public
```

然後瀏覽器打開 `http://127.0.0.1:8000/` 就會看到前端頁面(輸入股票代號 2330，已經預設查一次)。
純 API 呼叫: `curl "http://127.0.0.1:8000/api/warrants?stock_id=2330"`

## 前端說明

`public/app.html`：純 HTML/CSS/JS(無框架、無建置工具)，`public/index.php` 在
根目錄請求時直接把它讀出來當 SPA 入口，`/api/warrants` 維持 API 路由。

功能：
- 輸入股票代號查詢(Enter 或按查詢鍵)，附常用股票快速按鈕
- 表格顯示全部權證，含合理/偏貴/便宜彩色標籤，偏貴/便宜時附上「合理價」數字
- 點欄位標題可排序(履約價、剩餘天數、BIV、實質槓桿、偏離%...)，預設依偏離%排序(偏貴優先)
- 篩選：認購/認售、標籤(便宜/合理/偏貴)、發行商下拉
- RWD：手機版表格可橫向捲動，篩選列自動換行
- 深色模式：跟隨系統設定自動切換

目前一檔股票撐 12 檔權證測試都正常；權證數量上看百檔時若感覺表格捲動吃力，
可以再加虛擬滾動(研究報告建議的 TanStack Virtual 之類)，屆時再處理。

## 真實資料 (一行指令，免費資料源)

```bash
php console.php sync-real 2330 2026-09-23
```

- 權證報價(委買/委賣/收盤)：TWSE `MI_INDEX` 每日收盤行情(收盤後約 15:00 後公布)
- 權證條件(履約價/行使比例/到期日)：TWSE OpenAPI `t187ap37_L`，約 40MB，每天快取一次在 `storage/cache/`
- 標的歷史股價(算 HV)：FinMind `TaiwanStockPrice`(免費等級可用)
- 抓完自動跑 `calculate`
- 限制：只有**上市**權證(上櫃 TPEx 權證未接)；牛熊證跳過
- Windows 上 PHP 需要啟用 `pdo_sqlite`、`mbstring`、`curl`、`openssl` 擴充

FinMind 的 `TaiwanStockInfoWithWarrantSummary`(下面 `sync-warrants` 用的)需要付費等級，免費會回 400。

## 要接真實資料時 (舊流程，FinMind 權證清單需付費)

```bash
php console.php sync-warrants 2330              # 抓權證清單 (FinMind)
php console.php sync-price 2330 2026-01-01      # 抓標的歷史股價 (算HV用)
php console.php import-quotes 2026-09-18 quotes.csv   # 匯入當天委買賣價(自己爬)
php console.php calculate 2330 2026-09-18       # 跑計算
```

`quotes.csv` 格式：`warrant_id,bid_price,ask_price,close_price,volume`

**重要缺口**：FinMind 沒有權證委買/委賣價跟 IV 的免費資料，需要你自己爬
「權證資訊揭露平台」(warrants.sfi.org.tw，收盤後)或券商權證網站，輸出成
上面的 CSV 格式即可接上。這部分因為是爬蟲(會依網站改版而變動)，建議之後
需要時我再幫你針對實際網頁結構寫。

## 已驗證的計算結果 (模擬資料)

12 檔權證中，刻意把 038006/038007/738002 的 IV 設定明顯偏高、038008/038009
設定明顯偏低，其餘正常。跑完 `calculate` 後結果：

| 代碼 | 購售 | BIV | 合理BIV | 合理價 | 標籤 |
|---|---|---|---|---|---|
| 038006 | 認購 | 45.3% | 29.0% | 3.548 | **偏貴** |
| 038007 | 認購 | 43.3% | 30.6% | 4.260 | **偏貴** |
| 038008 | 認購 | 16.7% | 29.0% | 3.528 | **便宜** |
| 038009 | 認購 | 15.8% | 29.0% | 3.314 | **便宜** |
| 738002 | 認售 | 41.3% | 30.5% | 4.003 | **偏貴** |
| (其餘7檔) | | ~29-32% | ~29-31% | | 合理 |

判斷邏輯完全正確地把人為做高/做低的權證抓出來。

## 下一步

- 前端：等你確認後端邏輯 OK，再做網頁介面(輸入股票代號 -> 列表 + 顏色標籤)。
- 資料源：需要時再寫「權證資訊揭露平台」的爬蟲，接上 `import-quotes`。
- 之後可選：即時報價(mis.twse) + Redis 快取，做到盤中更新。
