# 台股權證分析工具 (盤後版)

輸入股票代號，列出該標的全部權證，用同類權證的隱含波動率互相比較，
標出「便宜 / 合理 / 偏貴」。資料全部來自免費公開來源 (TWSE、TPEx、FinMind)。

純 PHP 8.4 手刻 (routing、DB、autoload)，不用 Composer / 框架；
結構比照 Laravel 慣例 (`app/Models`、`app/Services`、`app/Console`)，
之後要搬進 Laravel，`Services/BlackScholes.php`、`Services/WarrantValuationService.php`
可以直接複製過去當 Service class。

## 快速開始

### 1. PHP 擴充

需要 `pdo_sqlite`、`curl`、`mbstring`、`openssl`。確認：

```bash
php -m    # 清單裡要有上面四個
```

Windows (winget 裝的 PHP) 預設**沒有 php.ini，這四個都沒開**，二選一：

- **建 php.ini (建議，一次搞定)**：到 PHP 安裝目錄 (`where php` 查)，把
  `php.ini-development` 複製成 `php.ini`，把這幾行的 `;` 拿掉：
  ```ini
  extension_dir = "ext"
  extension=curl
  extension=mbstring
  extension=openssl
  extension=pdo_sqlite
  ```
- **不改 PHP，每次指令帶參數**：
  ```bash
  php -d extension_dir="<PHP目錄>\ext" -d extension=pdo_sqlite -d extension=curl -d extension=mbstring -d extension=openssl ...
  ```

### 2. 建資料庫、啟動

```bash
cp .env.example .env
php console.php migrate                 # 建表 (更新程式後也要再跑一次，會自動補新欄位)
php -S 127.0.0.1:8000 -t public         # 同一個伺服器同時服務前端頁面 + API
```

瀏覽器打開 `http://127.0.0.1:8000/`，輸入股票代號即可 (頁面會先自動查 2330)。
純 API：`curl "http://127.0.0.1:8000/api/warrants?stock_id=2330"`

不想連網路、只想看流程，可以灌模擬資料：`php console.php seed-demo`
(寫入 2330 在 2026-09-18 的 14 檔假權證)，再 `php console.php calculate 2330 2026-09-18`。

## 資料怎麼更新

**網頁/API 查詢時自動在背景更新**：不帶 `trade_date` 查詢時，後端會先問證交所「最近一個已公布收盤行情的交易日」
(結果快取 10 分鐘)。本機沒有那天的資料 (沒查過、或資料停在之前的交易日) 時：

1. 啟動背景程序 `php console.php sync-job <股票> <日期>` 去抓資料並計算，**查詢本身立刻回應**，
   不會卡住 server (PHP 內建 server 一次只能處理一個請求，以前同步跑會整個網站卡住好幾分鐘)。
2. 有舊資料就先顯示舊資料，狀態列顯示「正在背景更新…」；完全沒資料就顯示「資料準備中」(API 回 HTTP 202)。
3. 前端每秒問一次 `/api/sync-status`，完成後跳出「資料更新完成」(失敗則跳紅色提示)，並自動刷新一次。
4. 今天的權證條件檔還沒下載時分兩段：先用之前最近的一份條件快照算完 (約 2–3 秒就能顯示)，
   同一個背景程序接著下載最新條件、重算，完成後跳「已用最新權證條件重新計算」並再刷新一次。
   條件只有除權息才會調整，舊快照幾乎都能用；當天新上市的權證要等第二段完成才會出現。

- 背景工作狀態與 log 在 `storage/jobs/{股票}_{日期}.json/.log`。同一檔同一天完成或失敗後，
  10 分鐘內不會重跑；卡住超過 15 分鐘會視為失敗重跑。
- 每天收盤後第一次抓取要下載 TWSE 約 40MB 的條件檔，證交所慢的時候要 3 分鐘以上 (下載逾時設 10 分鐘)；
  這段在背景第二段進行，同時查好幾檔股票也只會下載一次 (檔案鎖)。本機完全沒有條件快照時才需要等下載。
- 標的歷史股價 (FinMind) 本機已有就只補缺的日期。
- 收盤行情約 15:00 後公布；公布前查詢會拿到前一個交易日。
- 假日 (例如中秋、教師節) 證交所也會回空的行情，程式會視為非交易日跳過。

**手動 (CLI)**：

```bash
php console.php sync-real 2330               # 不帶日期 = 最近一個交易日
php console.php sync-real 8299 2026-09-24    # 指定日期；上櫃也可以
```

### 資料來源

| 資料 | 上市 (TWSE) | 上櫃 (TPEx) |
|---|---|---|
| 權證報價(委買/委賣/收盤) | `MI_INDEX` type=0999/0999P | `stk_wn1430` se=AL |
| 權證 -> 標的代號 | `MI_INDEX` 內含 | OpenAPI `tpex_warrant_daily_quts` |
| 履約價/行使比例/到期日 | OpenAPI `t187ap37_L` (~40MB) | OpenAPI `mopsfin_t187ap37_O` (~15MB) |

- 標的歷史股價 (算 HV)：FinMind `TaiwanStockPrice` (免費等級可用，上市上櫃都有)
- 牛熊證不適用一般 BS 模型，跳過

### 快取與歷史條件 (`storage/cache/`)

- 收盤行情：依日期快取，公布後不會再變。
- 權證條件 (履約價/行使比例/到期日)：來源**只有最新一天**，所以每天下載一次、解析成精簡快照
  `*_terms_YYYYMMDD.json` (約 3~6MB) 保存下來，累積成每天的條件歷史。
  同步過去某天時，用「該日當天或之後最近的一份」快照；都沒有才用今天的並印出提示
  (期間若有除權息調整，履約價/行使比例可能與當時不同)。
- 每筆報價 (`warrant_quotes`) 會記下當天用的履約價/行使比例，計算與 API 都以它為準，
  所以補抓舊日期不會影響其他日期的結果。
- 快取不會自動清；舊的 `*_t187ap37_YYYYMMDD.json` 原始大檔會在下次同步時自動轉成精簡快照並刪除。

## 合理價判斷邏輯

1. 用**委買價**反解每檔權證的隱含波動率 **BIV**(投資人只能用委買價賣出，
   BIV 才反映持有人真正拿得到的價值)。
2. 把同標的、**同認購/認售、價內外程度相近(每5%一組)、到期天數相近**的
   權證分成一組(peer group)，取該組 **BIV 中位數**當作「合理 BIV」
   (組內少於 5 檔時退回同認購/認售的全標的中位數，再不行退回歷史波動率 HV)。
3. 用合理 BIV 代回 Black-Scholes 反算「合理(委買)價」。
4. 實際委買價 vs 合理價偏離超過門檻(預設 ±15%)
   -> 標「偏貴」/「便宜」，否則「合理」。

另外計算 Delta、Theta (每交易日)、實質槓桿、買賣價差比。

### 參數 (`.env`)

| 參數 | 預設 | 說明 |
|---|---|---|
| `RISK_FREE_RATE` | 0.015 | 無風險利率 |
| `TRADING_DAYS_PER_YEAR` | 240 | HV 年化、Theta 換算成每交易日都用這個 |
| `HV_LOOKBACK_DAYS` | 60 | HV 回看交易日數 |
| `LABEL_THRESHOLD_PCT` | 0.15 | 偏貴/便宜門檻 |
| `FINMIND_TOKEN` | (空) | 可不填；填了額度從 300/hr 提高到 600/hr |
| `DB_CONNECTION` | sqlite | 改 `mysql` 並填 `DB_HOST` 等欄位即可切換 |

## 前端 (`public/app.html`)

純 HTML/CSS/JS (無框架、無建置)，`public/index.php` 在根目錄請求時直接輸出它。

- 輸入股票代號或名稱查詢 (Enter 或按查詢)，附常用股票快速按鈕
- **自動完成**：打滿 4 碼數字 (例如 `2330`、`0087`) 或以中文字開頭 (例如 `台`、`聯發`) 時，
  下拉列出符合的上市/上櫃股票與 ETF，可點選或用 ↑↓ + Enter 選；沒有符合的顯示「查無此股票」。
  名稱比對：開頭相符的在前、名稱包含的在後，各自一般股票在前、ETF 在後。
  股票清單來自 TWSE `STOCK_DAY_ALL` + TPEx `tpex_mainboard_daily_close_quotes`，後端 `/api/stocks` 每天快取一份
- 欄位：權證、發行商、認購/售、履約價、價內外、剩餘天數、行使比例、委買/委賣價、成交量(張)、
  BIV、實質槓桿、價差比、Theta/交易日、衰減%/交易日、偏離%、判斷(偏貴/便宜時附合理價；無法判斷時顯示原因：無委買、已到期、極價外，滑鼠移上去有說明)
- **價內外**：認購 = (股價 − 履約價) ÷ 履約價，認售 = (履約價 − 股價) ÷ 履約價；正的顯示「價內 x%」，負的顯示「價外 x%」(灰字)
- **偏離%**：(委買價 − 合理價) ÷ 合理價，正 = 比同類權證貴、負 = 便宜，跟價內外無關
- **預設排序**：依判斷標籤 (合理 → 便宜 → 偏貴 → 無法判斷)，同標籤內成交量大的在前、
  同量再依偏離% 絕對值小的在前；點欄位標題可改排序
- **每日衰減 > 2% 的權證一律排在最後面** (不論依哪個欄位排序；只有依「衰減%/交易日」排序時照數值排)
- 篩選：認購/認售、標籤、發行商、剩餘天數 (≥90/120/180 天或自訂)、成交量 (有成交/≥10/100/500 張或自訂)
- **每次查詢只抓成交量前 200 檔** (熱門股權證上千檔)；超過 200 檔時工具列出現「顯示所有權證 (N 檔)」按鈕，
  按下才抓全部。換股票查詢會回到 200 檔；篩選/排序只作用在已載入的權證上
- **刷新權證資料**：工具列的按鈕，不管本機資料新舊，都在背景重新抓這檔股票最近交易日的資料並重算
  (重新確認最近交易日、不看 10 分鐘快取)，完成後自動刷新表格 (`POST /api/refresh?stock_id=`)
- 警示：剩餘 < 30 天紅字「即將到期」、< 60 天「加速衰退區」；每日衰減 > 2% 紅字；
  偏貴或即將到期的整列標紅
- 每頁 100 筆分頁；RWD (手機表格可橫向捲動)；深色模式跟隨系統

## 目錄結構

```
app/
  Support/     Env、Database (PDO 連線，sqlite/mysql 切換只改 .env；migrate)
  Models/      UnderlyingStock, Warrant, PriceSnapshot, WarrantQuote, WarrantDailyMetric
  Services/
    BlackScholes.php             BS 定價、Delta/Theta、歷史波動率、IV 反解(牛頓法+二分法備援)
    WarrantValuationService.php  核心：BIV 分組比較 -> 合理 BIV -> 合理價 -> 標籤 (純運算，不碰 DB)
    TwseClient.php / TpexClient.php  上市/上櫃權證行情與條件
    FinMindClient.php            標的歷史股價 (以及舊流程的權證清單)
    StockList.php                上市+上櫃股票/ETF 代號名稱清單 (搜尋框自動完成)
    MarketData.php               HTTP、快取、條件快照、數字/民國日期解析
  Console/
    SyncReal.php                 主要流程：抓行情+條件+股價 -> calculate
    SyncJob.php                  網頁觸發的背景同步 (啟動子程序、狀態檔)
    CalculateMetrics.php         盤後計算主流程
    SyncUnderlyingPrice.php      標的歷史股價
    ImportQuotes.php / SyncWarrants.php  舊流程 (見下)
database/
  schema.sql       資料表結構
  seed_demo.php    模擬資料 (不需網路)
public/
  index.php        API: GET /api/warrants?stock_id=2330[&trade_date=Y-m-d]、GET /api/stocks、
                   GET /api/sync-status?stock_id=&trade_date=；以及前端入口
  app.html         前端
console.php        CLI 入口
```

## 舊流程 (FinMind 權證清單需付費)

```bash
php console.php sync-warrants 2330              # 權證清單 (FinMind TaiwanStockInfoWithWarrantSummary，免費會回 400)
php console.php sync-price 2330 2026-01-01      # 標的歷史股價
php console.php import-quotes 2026-09-18 quotes.csv   # 匯入當天委買賣價
php console.php calculate 2330 2026-09-18
```

`quotes.csv` 格式：`warrant_id,bid_price,ask_price,close_price,volume`

## 模擬資料驗證結果

`seed-demo` 裡刻意把 038006/038007/738002 的 IV 設定明顯偏高、038008/038009
設定明顯偏低，其餘正常。跑完 `calculate` 後：

| 代碼 | 購售 | BIV | 合理BIV | 合理價 | 標籤 |
|---|---|---|---|---|---|
| 038006 | 認購 | 45.3% | 29.0% | 3.548 | **偏貴** |
| 038007 | 認購 | 43.3% | 30.6% | 4.260 | **偏貴** |
| 038008 | 認購 | 16.7% | 29.0% | 3.528 | **便宜** |
| 038009 | 認購 | 15.8% | 29.0% | 3.314 | **便宜** |
| 738002 | 認售 | 41.3% | 30.5% | 4.003 | **偏貴** |
| (其餘) | | ~29-32% | ~29-31% | | 合理 |

## 之後可做

- 盤中即時報價 (mis.twse) + 快取，做到盤中更新
- 分組時納入行使比例；快取自動清理
