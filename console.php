<?php

/**
 * CLI 入口 (等同 Laravel 的 artisan，但不依賴 Composer)
 *
 * 用法:
 *   php console.php migrate
 *   php console.php sync-warrants 2330
 *   php console.php sync-price 2330 2026-06-01
 *   php console.php import-quotes 2026-09-18 quotes.csv
 *   php console.php calculate 2330 2026-09-18
 *   php console.php sync-real 2330 [2026-09-23]   (抓真實資料 TWSE+FinMind 並計算；不帶日期=最近交易日)
 *   php console.php seed-demo         (灌測試用模擬資料，不需要網路)
 */

require __DIR__ . '/bootstrap.php';

use App\Console\CalculateMetrics;
use App\Console\ImportQuotes;
use App\Console\SyncReal;
use App\Console\SyncUnderlyingPrice;
use App\Console\SyncWarrants;
use App\Support\Database;

$command = $argv[1] ?? null;
$args = array_slice($argv, 2);

switch ($command) {
    case 'migrate':
        Database::migrate();
        echo "Migration 完成。\n";
        break;

    case 'sync-warrants':
        (new SyncWarrants())->handle($args[0] ?? throw new InvalidArgumentException('需要股票代碼'));
        break;

    case 'sync-price':
        (new SyncUnderlyingPrice())->handle(
            $args[0] ?? throw new InvalidArgumentException('需要股票代碼'),
            $args[1] ?? throw new InvalidArgumentException('需要起始日期 (Y-m-d)')
        );
        break;

    case 'import-quotes':
        (new ImportQuotes())->handle(
            $args[0] ?? throw new InvalidArgumentException('需要交易日 (Y-m-d)'),
            $args[1] ?? throw new InvalidArgumentException('需要 CSV 路徑')
        );
        break;

    case 'calculate':
        (new CalculateMetrics())->handle(
            $args[0] ?? throw new InvalidArgumentException('需要股票代碼'),
            $args[1] ?? throw new InvalidArgumentException('需要交易日 (Y-m-d)')
        );
        break;

    case 'sync-real':
        (new SyncReal())->handle(
            $args[0] ?? throw new InvalidArgumentException('需要股票代碼'),
            $args[1] ?? null
        );
        break;

    case 'seed-demo':
        require __DIR__ . '/database/seed_demo.php';
        break;

    default:
        echo "未知指令: {$command}\n";
        echo "可用指令: migrate, sync-real, sync-warrants, sync-price, import-quotes, calculate, seed-demo\n";
        exit(1);
}
