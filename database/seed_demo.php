<?php

/**
 * 灌入模擬資料，不需要對外網路，用來在沙盒環境完整跑一遍
 * migrate -> 有股價/權證/報價 -> calculate -> API 輸出 的流程。
 * 正式環境請改用 sync-warrants / sync-price / import-quotes 抓真實資料。
 */

use App\Models\PriceSnapshot;
use App\Models\UnderlyingStock;
use App\Models\Warrant;
use App\Models\WarrantQuote;
use App\Services\BlackScholes as BS;

$stockId = '2330';
$stockName = '台積電(模擬)';
$tradeDate = '2026-09-18';
$S = 600.0;

UnderlyingStock::upsert($stockId, $stockName, 'TSE');

// 造 90 天的模擬歷史收盤價，讓 HV 計算有資料可用
mt_srand(2026);
$price = 560.0;
$date = new DateTimeImmutable($tradeDate);
$history = [];
for ($i = 90; $i >= 0; $i--) {
    $d = $date->modify("-{$i} days");
    if ((int)$d->format('N') >= 6) {
        continue; // 跳過週末
    }
    $price *= (1 + (mt_rand(-180, 180) / 10000));
    $history[$d->format('Y-m-d')] = round($price, 2);
}
// 讓最後一天收在我們設定的 S
$history[$tradeDate] = $S;
foreach ($history as $d => $close) {
    PriceSnapshot::upsert($stockId, $d, $close);
}

// 造 10 檔權證 (跟先前手動測試同一組情境，但這次真的寫進 DB 跑完整流程)
$configs = [
    ['id' => '038001', 'strike' => 580, 'days' => 90, 'trueIv' => 0.30, 'issuer' => '元大證券'],
    ['id' => '038002', 'strike' => 590, 'days' => 88, 'trueIv' => 0.29, 'issuer' => '凱基證券'],
    ['id' => '038003', 'strike' => 600, 'days' => 92, 'trueIv' => 0.31, 'issuer' => '群益證券'],
    ['id' => '038004', 'strike' => 610, 'days' => 85, 'trueIv' => 0.32, 'issuer' => '永豐金證券'],
    ['id' => '038005', 'strike' => 595, 'days' => 90, 'trueIv' => 0.30, 'issuer' => '統一證券'],
    ['id' => '038006', 'strike' => 600, 'days' => 90, 'trueIv' => 0.46, 'issuer' => '國泰證券'], // 偏貴
    ['id' => '038007', 'strike' => 590, 'days' => 91, 'trueIv' => 0.44, 'issuer' => '富邦證券'], // 偏貴
    ['id' => '038008', 'strike' => 600, 'days' => 89, 'trueIv' => 0.17, 'issuer' => '元大證券'], // 便宜
    ['id' => '038009', 'strike' => 605, 'days' => 90, 'trueIv' => 0.16, 'issuer' => '凱基證券'], // 便宜
    ['id' => '038010', 'strike' => 615, 'days' => 93, 'trueIv' => 0.31, 'issuer' => '群益證券'],
    // 幾檔認售權證
    ['id' => '738001', 'strike' => 620, 'days' => 90, 'trueIv' => 0.31, 'issuer' => '元大證券', 'type' => 'put'],
    ['id' => '738002', 'strike' => 610, 'days' => 88, 'trueIv' => 0.42, 'issuer' => '凱基證券', 'type' => 'put'], // 偏貴
    // 即將到期(用來測試前端「剩餘天數<30天」紅色警示)
    ['id' => '038011', 'strike' => 595, 'days' => 20, 'trueIv' => 0.29, 'issuer' => '元大證券'], // 即將到期
    ['id' => '038012', 'strike' => 600, 'days' => 50, 'trueIv' => 0.30, 'issuer' => '群益證券'], // 加速衰退區(30~60天)
];

$r = 0.015;
$q = 0.0;
$ratio = 0.1;

foreach ($configs as $c) {
    $type = $c['type'] ?? 'call';
    $mat = $date->modify("+{$c['days']} days");
    $T = $c['days'] / 365.0;

    Warrant::upsert([
        'warrant_id' => $c['id'],
        'name' => $stockName . ($type === 'call' ? '購' : '售') . substr($c['id'], -2),
        'underlying_stock_id' => $stockId,
        'type' => $type,
        'issuer' => $c['issuer'],
        'listed_date' => $date->modify('-30 days')->format('Y-m-d'),
        'maturity_date' => $mat->format('Y-m-d'),
        'strike_price' => $c['strike'],
        'exercise_ratio' => $ratio,
        'fulfillment_method' => '現金結算',
        'status' => 'active',
    ]);

    $mid = BS::price($type, $S, $c['strike'], $T, $r, $q, $c['trueIv'], $ratio);
    $bid = round($mid * 0.985, 3);
    $ask = round($mid * 1.015, 3);

    WarrantQuote::upsert($c['id'], $tradeDate, $bid, $ask, round($mid, 3), 100 + mt_rand(0, 500));
}

echo "模擬資料建立完成: 標的 {$stockId}，交易日 {$tradeDate}，共 " . count($configs) . " 檔權證。\n";
echo "接下來執行: php console.php calculate {$stockId} {$tradeDate}\n";
