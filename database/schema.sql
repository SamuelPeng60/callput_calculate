-- 台股權證分析工具 資料庫 Schema (SQLite 開發版；正式環境可直接用同一份 SQL 語法微調後跑在 MySQL)

-- 標的股票
CREATE TABLE IF NOT EXISTS underlying_stocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stock_id VARCHAR(10) NOT NULL UNIQUE,   -- 例如 2330
    name VARCHAR(50),                       -- 例如 台積電
    market VARCHAR(10),                     -- TSE / OTC
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 標的股票每日收盤價 (用來算歷史波動率 HV)
CREATE TABLE IF NOT EXISTS price_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stock_id VARCHAR(10) NOT NULL,
    trade_date DATE NOT NULL,
    open REAL,
    high REAL,
    low REAL,
    close REAL NOT NULL,
    volume INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(stock_id, trade_date)
);
CREATE INDEX IF NOT EXISTS idx_price_snapshots_stock_date ON price_snapshots(stock_id, trade_date);

-- 權證主檔
CREATE TABLE IF NOT EXISTS warrants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    warrant_id VARCHAR(10) NOT NULL UNIQUE,   -- 權證代碼 例如 031234
    name VARCHAR(50),
    underlying_stock_id VARCHAR(10) NOT NULL, -- 對應 underlying_stocks.stock_id
    type VARCHAR(4) NOT NULL,                 -- call=認購 / put=認售
    issuer VARCHAR(30),                       -- 發行券商
    listed_date DATE,
    maturity_date DATE NOT NULL,              -- 到期日
    strike_price REAL NOT NULL,               -- 履約價
    exercise_ratio REAL NOT NULL DEFAULT 1.0, -- 行使比例(換股比例)
    fulfillment_method VARCHAR(10),           -- 現金結算 / 證券給付
    status VARCHAR(10) DEFAULT 'active',      -- active / matured / delisted
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_warrants_underlying ON warrants(underlying_stock_id);
CREATE INDEX IF NOT EXISTS idx_warrants_maturity ON warrants(maturity_date);

-- 權證每日原始報價 (委買/委賣/收盤價)，來源可以是任何 scraper/匯入方式，
-- 跟下面的計算結果表分開，方便之後替換資料來源而不動計算邏輯
CREATE TABLE IF NOT EXISTS warrant_quotes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    warrant_id VARCHAR(10) NOT NULL,
    trade_date DATE NOT NULL,
    bid_price REAL,
    ask_price REAL,
    close_price REAL,
    volume INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(warrant_id, trade_date)
);
CREATE INDEX IF NOT EXISTS idx_warrant_quotes_date ON warrant_quotes(trade_date);

-- 權證每日快照 + 計算結果 (盤後批次寫入)
CREATE TABLE IF NOT EXISTS warrant_daily_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    warrant_id VARCHAR(10) NOT NULL,
    trade_date DATE NOT NULL,

    -- 原始市場資料
    underlying_close REAL NOT NULL,     -- 當天標的收盤價
    warrant_close REAL,                 -- 權證收盤價
    bid_price REAL,                     -- 委買價
    ask_price REAL,                     -- 委賣價
    volume INTEGER,

    -- 中介計算值
    days_to_maturity INTEGER NOT NULL,  -- 距到期日曆天數
    hv REAL,                            -- 標的歷史波動率 (年化)
    theoretical_price REAL,             -- 用 HV 算出的 BS 理論價 (參考用)

    biv REAL,                           -- 委買價反解隱波
    siv REAL,                           -- 委賣價反解隱波
    close_iv REAL,                      -- 收盤價反解隱波

    peer_group_key VARCHAR(50),         -- 分組 key: 標的+認購售+價內外區間+到期天數區間
    peer_median_biv REAL,               -- 同群 BIV 中位數
    fair_biv REAL,                      -- 採用的合理 BIV (=peer_median_biv，可調整)
    fair_price REAL,                    -- 合理 BIV 代回 BS 算出的合理(委買)價
    deviation_pct REAL,                 -- (bid_price - fair_price) / fair_price

    label VARCHAR(10),                  -- cheap / fair / expensive

    delta REAL,
    theta REAL,
    effective_leverage REAL,            -- 實質槓桿
    spread_ratio REAL,                  -- 買賣價差比

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(warrant_id, trade_date)
);
CREATE INDEX IF NOT EXISTS idx_wdm_warrant_date ON warrant_daily_metrics(warrant_id, trade_date);
CREATE INDEX IF NOT EXISTS idx_wdm_trade_date ON warrant_daily_metrics(trade_date);
