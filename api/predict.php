<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function safePair(string $pair): string
{
    $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $pair) ?? '');
    return $clean !== '' ? $clean : 'BTCIDR';
}

function requestUrl(string $url): array
{
    $resp = false;
    $code = 0;
    $err = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AnalisisMarketBot/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/plain,*/*',
                'Origin: https://indodax.com',
                'Referer: https://indodax.com/',
            ],
        ]);

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' =>
                    "User-Agent: Mozilla/5.0 (compatible; AnalisisMarketBot/1.0)\r\n" .
                    "Accept: application/json,text/plain,*/*\r\n" .
                    "Origin: https://indodax.com\r\n" .
                    "Referer: https://indodax.com/\r\n",
            ],
        ]);

        $resp = @file_get_contents($url, false, $ctx);
        $httpResponseHeader = $http_response_header ?? [];
        if (isset($httpResponseHeader[0]) && preg_match('/\\s(\\d{3})\\s/', $httpResponseHeader[0], $m)) {
            $code = (int)$m[1];
        }
        if ($resp === false) {
            $err = 'Gagal mengambil data via HTTP stream.';
        }
    }

    return ['body' => $resp, 'code' => $code, 'error' => $err];
}

function resolutionToSeconds(string $resolution): int
{
    switch ($resolution) {
        case '60':
            return 3600;
        case '240':
            return 14400;
        case 'D':
            return 86400;
        default:
            return 14400;
    }
}

function pairToApiPair(string $pair): string
{
    foreach (['USDT', 'IDR', 'BTC', 'ETH'] as $quote) {
        if (substr($pair, -strlen($quote)) === $quote) {
            $base = substr($pair, 0, -strlen($quote));
            if ($base !== '') {
                return strtolower($base . '_' . $quote);
            }
        }
    }
    return strtolower($pair);
}

function candlesFromTrades(string $pair, string $resolution, int $from, int $to): array
{
    $apiPair = pairToApiPair($pair);
    $url = "https://indodax.com/api/{$apiPair}/trades";
    $r = requestUrl($url);
    if ($r['body'] === false || $r['code'] >= 400 || $r['code'] === 0) {
        return [];
    }

    $trades = json_decode((string)$r['body'], true);
    if (!is_array($trades) || count($trades) === 0) {
        return [];
    }

    $bucket = resolutionToSeconds($resolution);
    $map = [];
    foreach ($trades as $t) {
        $ts = (int)($t['date'] ?? 0);
        $price = (float)($t['price'] ?? 0);
        if ($ts < $from || $ts > $to || $price <= 0) {
            continue;
        }
        $k = (int)(floor($ts / $bucket) * $bucket);
        if (!isset($map[$k])) {
            $map[$k] = [
                'time' => $k,
                'open' => $price,
                'high' => $price,
                'low' => $price,
                'close' => $price,
            ];
        } else {
            $map[$k]['high'] = max($map[$k]['high'], $price);
            $map[$k]['low'] = min($map[$k]['low'], $price);
            $map[$k]['close'] = $price;
        }
    }

    ksort($map);
    return array_values($map);
}

function fetchCandles(string $pair, string $resolution = '240', int $lookbackDays = 210): array
{
    $to = time();
    $from = $to - ($lookbackDays * 86400);
    $querySets = [];
    $pairLower = strtolower($pair);
    $pairUnderscore = strtolower(preg_replace('/(IDR|USDT|BTC|ETH)$/', '_$1', $pair) ?? $pair);
    foreach ([$pair, $pairLower, $pairUnderscore] as $symbol) {
        $querySets[] = http_build_query([
            'symbol' => $symbol,
            'resolution' => $resolution,
            'from' => (string)$from,
            'to' => (string)$to,
        ]);
    }

    $urls = [];
    foreach ($querySets as $qs) {
        $urls[] = "https://indodax.com/tradingview/history_v2?$qs";
        $urls[] = "https://indodax.com/tradingview/history?$qs";
    }
    $urls = array_values(array_unique($urls));

    $resp = false;
    $code = 0;
    $errors = [];

    foreach ($urls as $url) {
        $r = requestUrl($url);
        $resp = $r['body'];
        $code = (int)$r['code'];
        $err = (string)$r['error'];

        if ($resp !== false && $code > 0 && $code < 400) {
            break;
        }

        $errors[] = sprintf('%s => HTTP %d %s', $url, $code, $err);
    }

    if ($resp === false || $code >= 400 || $code === 0) {
        $candlesFromTradeFallback = candlesFromTrades($pair, $resolution, $from, $to);
        if (count($candlesFromTradeFallback) > 20) {
            return $candlesFromTradeFallback;
        }
        jsonOut([
            'ok' => false,
            'error' => 'Gagal mengambil data Indodax.',
            'detail' => implode(' | ', array_slice($errors, 0, 2)),
        ], 502);
    }

    $json = json_decode($resp, true);
    if (!is_array($json) || !isset($json['c']) || !is_array($json['c'])) {
        jsonOut([
            'ok' => false,
            'error' => 'Format data Indodax tidak valid.',
        ], 502);
    }

    $t = $json['t'] ?? [];
    $o = $json['o'] ?? [];
    $h = $json['h'] ?? [];
    $l = $json['l'] ?? [];
    $c = $json['c'] ?? [];

    $candles = [];
    $n = min(count($t), count($o), count($h), count($l), count($c));
    for ($i = 0; $i < $n; $i++) {
        $candles[] = [
            'time' => (int)$t[$i],
            'open' => (float)$o[$i],
            'high' => (float)$h[$i],
            'low' => (float)$l[$i],
            'close' => (float)$c[$i],
        ];
    }

    return array_values(array_filter($candles, fn(array $x): bool => $x['close'] > 0));
}

function ema(array $values, int $period): array
{
    if (count($values) < $period) {
        return [];
    }

    $k = 2 / ($period + 1);
    $out = [];
    $seed = array_sum(array_slice($values, 0, $period)) / $period;
    $out[$period - 1] = $seed;

    for ($i = $period; $i < count($values); $i++) {
        $prev = $out[$i - 1] ?? $seed;
        $out[$i] = ($values[$i] * $k) + ($prev * (1 - $k));
    }

    return $out;
}

function rsi(array $values, int $period = 14): ?float
{
    if (count($values) <= $period) {
        return null;
    }

    $gain = 0.0;
    $loss = 0.0;
    for ($i = count($values) - $period; $i < count($values); $i++) {
        $diff = $values[$i] - $values[$i - 1];
        if ($diff > 0) {
            $gain += $diff;
        } else {
            $loss += abs($diff);
        }
    }

    if ($loss == 0.0) {
        return 100.0;
    }

    $rs = ($gain / $period) / ($loss / $period);
    return 100 - (100 / (1 + $rs));
}

function atr(array $candles, int $period = 14): ?float
{
    if (count($candles) <= $period) {
        return null;
    }

    $trs = [];
    for ($i = 1; $i < count($candles); $i++) {
        $high = $candles[$i]['high'];
        $low = $candles[$i]['low'];
        $prevClose = $candles[$i - 1]['close'];
        $trs[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
    }

    return array_sum(array_slice($trs, -$period)) / $period;
}

function detectPattern(array $candles): array
{
    $n = count($candles);
    if ($n < 3) {
        return ['pattern' => 'netral', 'bias' => 0.0, 'score' => 0];
    }

    $a = $candles[$n - 2];
    $b = $candles[$n - 1];

    $bodyA = abs($a['close'] - $a['open']);
    $bodyB = abs($b['close'] - $b['open']);
    $rangeB = max($b['high'] - $b['low'], 1e-9);

    $isDoji = $bodyB / $rangeB < 0.1;
    if ($isDoji) {
        return ['pattern' => 'doji', 'bias' => 0.0, 'score' => 55];
    }

    $bullEngulf = $a['close'] < $a['open']
        && $b['close'] > $b['open']
        && $b['close'] >= $a['open']
        && $b['open'] <= $a['close']
        && $bodyB > $bodyA;

    if ($bullEngulf) {
        return ['pattern' => 'bullish engulfing', 'bias' => 0.015, 'score' => 66];
    }

    $bearEngulf = $a['close'] > $a['open']
        && $b['close'] < $b['open']
        && $b['open'] >= $a['close']
        && $b['close'] <= $a['open']
        && $bodyB > $bodyA;

    if ($bearEngulf) {
        return ['pattern' => 'bearish engulfing', 'bias' => -0.015, 'score' => 66];
    }

    $upperWick = $b['high'] - max($b['close'], $b['open']);
    $lowerWick = min($b['close'], $b['open']) - $b['low'];

    if ($lowerWick > ($bodyB * 1.8) && $upperWick < $bodyB) {
        return ['pattern' => 'hammer', 'bias' => 0.01, 'score' => 62];
    }

    if ($upperWick > ($bodyB * 1.8) && $lowerWick < $bodyB) {
        return ['pattern' => 'shooting star', 'bias' => -0.01, 'score' => 62];
    }

    return ['pattern' => 'netral', 'bias' => 0.0, 'score' => 58];
}

function pct(float $a, float $b): float
{
    if ($a == 0.0) {
        return 0.0;
    }

    return ($b - $a) / $a;
}

$pair = safePair($_GET['pair'] ?? 'BTCIDR');
$resolution = strtoupper((string)($_GET['resolution'] ?? '240'));
if (!in_array($resolution, ['60', '240', 'D'], true)) {
    $resolution = '240';
}

$candles = fetchCandles($pair, $resolution);
if (count($candles) < 80) {
    jsonOut([
        'ok' => false,
        'error' => 'Data candle kurang. Coba pair lain.',
    ], 422);
}

$closes = array_column($candles, 'close');
$ema20 = ema($closes, 20);
$ema50 = ema($closes, 50);
$ema20Last = end($ema20);
$ema50Last = end($ema50);
$rsi14 = rsi($closes, 14);
$atr14 = atr($candles, 14) ?? 0.0;
$pattern = detectPattern($candles);

$last = $closes[count($closes) - 1];
$weekAgo = $closes[max(0, count($closes) - 8)];
$monthAgo = $closes[max(0, count($closes) - 31)];

$trendFast = pct((float)$weekAgo, (float)$last);
$trendSlow = pct((float)$monthAgo, (float)$last);
$trendCross = ($ema20Last !== false && $ema50Last !== false && $ema50Last > 0)
    ? (($ema20Last - $ema50Last) / $ema50Last)
    : 0.0;

$rsiBias = 0.0;
if ($rsi14 !== null) {
    if ($rsi14 < 30) {
        $rsiBias = 0.008;
    } elseif ($rsi14 > 70) {
        $rsiBias = -0.008;
    }
}

$volatility = $last > 0 ? ($atr14 / $last) : 0.0;
$shortDrift = ($trendFast * 0.55) + ($trendCross * 0.30) + ($pattern['bias'] * 1.2) + $rsiBias;
$longDrift = ($trendSlow * 0.55) + ($trendCross * 0.35) + ($rsiBias * 0.5);
$shortDrift = max(-0.08, min(0.08, $shortDrift));
$longDrift = max(-0.05, min(0.05, $longDrift));
$riskDampen = max(0.22, 1 - ($volatility * 2.8));
$meanAnchor = ($ema20Last !== false && $ema50Last !== false)
    ? (($ema20Last * 0.55) + ($ema50Last * 0.45))
    : $last;

$horizons = [
    'besok' => 1,
    'lusa' => 2,
    '7_hari' => 7,
    '30_hari' => 30,
];

$predictions = [];
foreach ($horizons as $label => $days) {
    $trendDecay = exp(-$days / 10.0);
    $patternDecay = exp(-$days / 3.0);
    $meanRevertWeight = min(0.72, (1 - $trendDecay) * 0.9);

    $trendComponent = (($shortDrift * $trendDecay) + ($longDrift * (1 - $trendDecay))) * sqrt($days);
    $reversionComponent = pct((float)$last, (float)$meanAnchor) * $meanRevertWeight;
    $horizonReturn = ($trendComponent + $reversionComponent + ($pattern['bias'] * $patternDecay)) * $riskDampen;
    $horizonReturn = max(-0.22, min(0.22, $horizonReturn));

    $base = $last * (1 + $horizonReturn);
    $volBand = max(0.003, $volatility * sqrt($days) * 0.85);
    $low = $base * (1 - $volBand);
    $high = $base * (1 + $volBand);

    $direction = $horizonReturn >= 0 ? 'naik' : 'turun';
    $directionStrength = min(95, max(50, (int)round(50 + (abs($horizonReturn) * 620))));
    $confidence = (int)round(max(40, min(92,
        72
        - ($volatility * 200)
        + (abs($trendCross) * 100)
        + ($pattern['score'] - 58) * 0.6
    )));

    $predictions[$label] = [
        'days' => $days,
        'target' => round($base, 2),
        'range_low' => round($low, 2),
        'range_high' => round($high, 2),
        'confidence' => $confidence,
        'direction' => $direction,
        'direction_strength' => $directionStrength,
        'expected_return' => round($horizonReturn, 6),
    ];
}

$history = array_slice($candles, -180);

jsonOut([
    'ok' => true,
    'pair' => $pair,
    'resolution' => $resolution,
    'last_price' => $last,
    'indicators' => [
        'ema20' => $ema20Last,
        'ema50' => $ema50Last,
        'rsi14' => $rsi14,
        'atr14' => $atr14,
        'trend_fast' => $trendFast,
        'trend_slow' => $trendSlow,
        'trend_cross' => $trendCross,
        'pattern' => $pattern['pattern'],
        'volatility_ratio' => $volatility,
    ],
    'predictions' => $predictions,
    'history' => $history,
]);
