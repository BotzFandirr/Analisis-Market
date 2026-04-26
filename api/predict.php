<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

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
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/plain,*/*',
                'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7'
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
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n" .
                    "Accept: application/json,text/plain,*/*\r\n"
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $httpResponseHeader = $http_response_header ?? [];
        if (isset($httpResponseHeader[0]) && preg_match('/\\s(\\d{3})\\s/', $httpResponseHeader[0], $m)) {
            $code = (int)$m[1];
        }
        if ($resp === false) {
            $err = 'Stream failed.';
        }
    }

    return ['body' => $resp, 'code' => $code, 'error' => $err];
}

function resolutionToSeconds(string $resolution): int
{
    switch ($resolution) {
        case '60': return 3600;
        case '240': return 14400;
        case 'D': return 86400;
        default: return 14400;
    }
}

function pairToApiPair(string $pair): string
{
    foreach (['USDT', 'IDR', 'BTC', 'ETH'] as $quote) {
        if (substr($pair, -strlen($quote)) === $quote) {
            $base = substr($pair, 0, -strlen($quote));
            if ($base !== '') return strtolower($base . '_' . $quote);
        }
    }
    return strtolower($pair);
}

function candlesFromCryptoCompare(string $pair, string $resolution): array
{
    $base = 'BTC';
    $quote = 'IDR';
    foreach (['USDT', 'IDR', 'BTC', 'ETH'] as $q) {
        if (substr(strtoupper($pair), -strlen($q)) === $q) {
            $base = substr(strtoupper($pair), 0, -strlen($q));
            $quote = $q;
            break;
        }
    }

    $endpoint = 'histohour';
    $aggregate = 1;
    if ($resolution === '240') {
        $aggregate = 4;
    } elseif ($resolution === 'D') {
        $endpoint = 'histoday';
    }

    $url = "https://min-api.cryptocompare.com/data/v2/{$endpoint}?fsym={$base}&tsym={$quote}&limit=200&aggregate={$aggregate}";
    $r = requestUrl($url);
    $json = json_decode((string)$r['body'], true);

    if (!isset($json['Data']['Data']) || !is_array($json['Data']['Data'])) {
        return [];
    }

    $candles = [];
    foreach ($json['Data']['Data'] as $c) {
        if (isset($c['close']) && $c['close'] > 0) {
            $candles[] = [
                'time' => (int)$c['time'],
                'open' => (float)$c['open'],
                'high' => (float)$c['high'],
                'low' => (float)$c['low'],
                'close' => (float)$c['close'],
            ];
        }
    }
    return $candles;
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
    }

    $resp = false;
    $code = 0;
    
    foreach ($urls as $url) {
        $r = requestUrl($url);
        $resp = $r['body'];
        $code = (int)$r['code'];
        if ($resp !== false && $code > 0 && $code < 400) break;
    }

    $json = is_string($resp) ? json_decode($resp, true) : null;
    $isJsonValid = is_array($json) && isset($json['c']) && is_array($json['c']);

    // Jika Indodax memblokir IP Hosting, alihkan ke Super Fallback API Global
    if ($resp === false || $code >= 400 || $code === 0 || !$isJsonValid) {
        
        $candlesGlobal = candlesFromCryptoCompare($pair, $resolution);
        
        if (count($candlesGlobal) > 20) {
            return $candlesGlobal;
        }

        $debugStr = is_string($resp) ? trim(substr(strip_tags($resp), 0, 40)) : 'No Response';
        jsonOut([
            'ok' => false,
            'error' => 'Akses IP Server diblokir. Gagal beralih ke jalur cadangan.',
            'detail' => 'API Utama dan Global tidak merespons. (Debug: ' . $debugStr . ')',
        ], 400);
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

    return array_values(array_filter($candles, function(array $x): bool {
        return $x['close'] > 0;
    }));
}

function ema(array $values, int $period): array
{
    if (count($values) < $period) return [];
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
    if (count($values) <= $period) return null;
    $gain = 0.0; $loss = 0.0;
    for ($i = count($values) - $period; $i < count($values); $i++) {
        $diff = $values[$i] - $values[$i - 1];
        if ($diff > 0) $gain += $diff; else $loss += abs($diff);
    }
    if ($loss == 0.0) return 100.0;
    $rs = ($gain / $period) / ($loss / $period);
    return 100 - (100 / (1 + $rs));
}

function atr(array $candles, int $period = 14): ?float
{
    if (count($candles) <= $period) return null;
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
    if ($n < 3) return ['pattern' => 'netral', 'bias' => 0.0, 'score' => 0];

    $a = $candles[$n - 2];
    $b = $candles[$n - 1];

    $bodyA = abs($a['close'] - $a['open']);
    $bodyB = abs($b['close'] - $b['open']);
    $rangeB = max($b['high'] - $b['low'], 1e-9);

    if ($bodyB / $rangeB < 0.1) return ['pattern' => 'doji', 'bias' => 0.0, 'score' => 55];

    $bullEngulf = $a['close'] < $a['open'] && $b['close'] > $b['open'] && $b['close'] >= $a['open'] && $b['open'] <= $a['close'] && $bodyB > $bodyA;
    if ($bullEngulf) return ['pattern' => 'bullish engulfing', 'bias' => 0.015, 'score' => 66];

    $bearEngulf = $a['close'] > $a['open'] && $b['close'] < $b['open'] && $b['open'] >= $a['close'] && $b['close'] <= $a['open'] && $bodyB > $bodyA;
    if ($bearEngulf) return ['pattern' => 'bearish engulfing', 'bias' => -0.015, 'score' => 66];

    $upperWick = $b['high'] - max($b['close'], $b['open']);
    $lowerWick = min($b['close'], $b['open']) - $b['low'];

    if ($lowerWick > ($bodyB * 1.8) && $upperWick < $bodyB) return ['pattern' => 'hammer', 'bias' => 0.01, 'score' => 62];
    if ($upperWick > ($bodyB * 1.8) && $lowerWick < $bodyB) return ['pattern' => 'shooting star', 'bias' => -0.01, 'score' => 62];

    return ['pattern' => 'netral', 'bias' => 0.0, 'score' => 58];
}

function pct(float $a, float $b): float
{
    return $a == 0.0 ? 0.0 : ($b - $a) / $a;
}

function clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

$pair = safePair($_GET['pair'] ?? 'BTCIDR');
$resolution = strtoupper((string)($_GET['resolution'] ?? '240'));
if (!in_array($resolution, ['60', '240', 'D'], true)) $resolution = '240';

$candles = fetchCandles($pair, $resolution);
if (count($candles) < 80) {
    jsonOut(['ok' => false, 'error' => 'Data candle kurang dari batas wajar. Coba pair lain.'], 422);
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
$dayAgo = $closes[max(0, count($closes) - 2)];
$threeDaysAgo = $closes[max(0, count($closes) - 4)];

$trend1D = pct((float)$dayAgo, (float)$last);
$trend3D = pct((float)$threeDaysAgo, (float)$last);
$trendFast = pct((float)$weekAgo, (float)$last);
$trendSlow = pct((float)$monthAgo, (float)$last);
$trendCross = ($ema20Last !== false && $ema50Last !== false && $ema50Last > 0) ? (($ema20Last - $ema50Last) / $ema50Last) : 0.0;

$rsiBias = 0.0;
if ($rsi14 !== null) {
    if ($rsi14 < 30) $rsiBias = 0.008;
    elseif ($rsi14 > 70) $rsiBias = -0.008;
}

$volatility = $last > 0 ? ($atr14 / $last) : 0.0;

$horizons = ['besok' => 1, 'lusa' => 2, '7_hari' => 7, '30_hari' => 30];
$predictions = [];
foreach ($horizons as $label => $days) {
    // Per-horizon forecast agar tidak selalu bergerak linear naik/turun antar periode.
    $shortMomentum = ($trend1D * 0.45) + ($trend3D * 0.35) + ($trendFast * 0.20);
    $momentumDecay = exp(-$days / 8);
    $trendBuild = 1 - exp(-$days / 18);
    $meanReversion = -$shortMomentum * min(0.55, $days / 30 * 0.55);
    $crossEffect = $trendCross * (0.22 + ($days / 100));
    $patternEffect = $pattern['bias'] * exp(-$days / 6);
    $rsiEffect = $rsiBias * (0.8 + ($days / 40));
    $slowTrendEffect = $trendSlow * $trendBuild * 0.55;

    $rawReturn = ($shortMomentum * $momentumDecay * 0.9)
        + $slowTrendEffect
        + $crossEffect
        + $meanReversion
        + $patternEffect
        + $rsiEffect;

    $volatilityCap = max(0.012, min(0.25, $volatility * sqrt($days) * 2.2));
    $expectedReturn = clamp($rawReturn, -$volatilityCap, $volatilityCap);
    $base = $last * (1 + $expectedReturn);

    $volBand = max(0.008, $volatility * sqrt($days) * 0.9);
    $rangeLow = $base * (1 - $volBand);
    $rangeHigh = $base * (1 + $volBand);

    $dirScore = ($expectedReturn * 180) + ($trendCross * 20) + ($pattern['bias'] * 220);
    $direction = 'sideways';
    if ($dirScore > 0.6) $direction = 'naik';
    if ($dirScore < -0.6) $direction = 'turun';
    $directionConfidence = (int)round(clamp(50 + abs($dirScore) * 2.8, 50, 92));
    
    $predictions[$label] = [
        'days' => $days,
        'target' => round($base, 2),
        'range_low' => round($rangeLow, 2),
        'range_high' => round($rangeHigh, 2),
        'direction' => $direction,
        'direction_confidence' => $directionConfidence,
        'confidence' => (int)round(clamp(74 - ($volatility * 180) + (abs($trendCross) * 80) + ($pattern['score'] - 58) * 0.55, 40, 92)),
    ];
}

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
        'trend_1d' => $trend1D,
        'trend_3d' => $trend3D,
        'trend_slow' => $trendSlow,
        'trend_cross' => $trendCross,
        'pattern' => $pattern['pattern'],
        'volatility_ratio' => $volatility,
    ],
    'predictions' => $predictions,
    'history' => array_slice($candles, -180),
]);
