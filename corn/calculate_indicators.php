<?php
// === calculate_indicators.php (fixed) ===
// Hitung RSI, MACD, Bollinger utk semua pair dan simpan sinyal BUY/SELL ke tabel `signals`.
// Jalankan via cron */5 * * * * php /path/to/calculate_indicators.php

sleep(5);
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---- KONFIG ----
$dbHost = 'localhost';
$dbUser = 'ifupnvjt_root';
$dbPass = 'KitaBisa';
$dbName = 'ifupnvjt_db_crypto';

$candlesToFetch = 200;
$rsiPeriod  = 14;
$bbPeriod   = 20;
$bbK        = 2;
$macdFast   = 12;
$macdSlow   = 26;
$macdSignal = 9;

$dedupeMinutes = 10;   // cegah sinyal spam (per pair)

// ---- DB ----
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) { die("DB connect error: ".$conn->connect_error."\n"); }
$conn->set_charset('utf8mb4');

// ---- Helpers ----
function listTables($conn) {
  $t = [];
  $res = $conn->query("SHOW TABLES");
  if ($res) while ($r = $res->fetch_array()) $t[] = $r[0];
  return $t;
}
function fetchPrices($conn, $table, $limit) {
  $safe = $conn->real_escape_string($table);
  $sql = "SELECT last_price, server_time FROM `$safe`
          WHERE last_price IS NOT NULL
          ORDER BY server_time DESC, id DESC
          LIMIT ".intval($limit);
  $res = $conn->query($sql);
  $arr = [];
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $arr[] = ['price' => (float)$r['last_price'], 'time' => (int)$r['server_time']];
    }
  }
  return array_reverse($arr); // oldest -> newest
}
function emaArr($vals, $period) {
  $n = count($vals);
  if ($n < $period) return array_fill(0, $n, null);
  $k = 2 / ($period + 1);
  $out = array_fill(0, $n, null);
  $sum = 0;
  for ($i = 0; $i < $period; $i++) $sum += $vals[$i];
  $ema = $sum / $period;
  $out[$period - 1] = $ema;
  for ($i = $period; $i < $n; $i++) {
    $ema = $vals[$i] * $k + $ema * (1 - $k);
    $out[$i] = $ema;
  }
  return $out;
}
function rsiArr($prices, $period = 14) {
  $n = count($prices);
  if ($n <= $period) return array_fill(0, $n, null);
  $out = array_fill(0, $n, null);

  $g = 0; $l = 0;
  for ($i = 1; $i <= $period; $i++) {
    $c = $prices[$i] - $prices[$i - 1];
    $g += ($c > 0) ? $c : 0;
    $l += ($c < 0) ? -$c : 0;
  }
  $avgG = $g / $period;
  $avgL = $l / $period;

  for ($i = $period; $i < $n; $i++) {
    if ($i > $period) {
      $c = $prices[$i] - $prices[$i - 1];
      $gain = ($c > 0) ? $c : 0;
      $loss = ($c < 0) ? -$c : 0;
      $avgG = (($avgG * ($period - 1)) + $gain) / $period;
      $avgL = (($avgL * ($period - 1)) + $loss) / $period;
    }
    $out[$i] = ($avgL == 0) ? 100.0 : (100 - (100 / (1 + ($avgG / $avgL))));
  }
  return $out;
}
function bbArr($prices, $period = 20, $k = 2) {
  $n = count($prices);
  $mb = array_fill(0, $n, null);
  $ub = array_fill(0, $n, null);
  $lb = array_fill(0, $n, null);
  for ($i = 0; $i < $n; $i++) {
    if ($i < $period - 1) continue;
    $slice = array_slice($prices, $i - $period + 1, $period);
    $mean = array_sum($slice) / $period;
    $var = 0;
    foreach ($slice as $v) $var += ($v - $mean) * ($v - $mean);
    $sd = sqrt($var / $period);
    $mb[$i] = $mean;
    $ub[$i] = $mean + $k * $sd;
    $lb[$i] = $mean - $k * $sd;
  }
  return ['mb' => $mb, 'ub' => $ub, 'lb' => $lb];
}

function insertSignal($conn, $pair, $type, $price, $reason, $conf, $rsi, $macd, $sig, $bbLower, $bbUpper) {
  // pakai query langsung tapi escaped, supaya tidak ribet tipe bind_param
  $pair  = $conn->real_escape_string($pair);
  $type  = $conn->real_escape_string($type);
  $reas  = $conn->real_escape_string($reason);

  $val_rsi = is_null($rsi) ? 'NULL' : (string)floatval($rsi);
  $val_mac = is_null($macd) ? 'NULL' : (string)floatval($macd);
  $val_sig = is_null($sig) ? 'NULL' : (string)floatval($sig);
  $val_bbl = is_null($bbLower) ? 'NULL' : (string)floatval($bbLower);
  $val_bbu = is_null($bbUpper) ? 'NULL' : (string)floatval($bbUpper);

  $sql = "INSERT INTO signals
          (pair, signal_type, price, reason, confidence, rsi, macd, macd_signal, bb_lower, bb_upper)
          VALUES
          ('$pair', '$type', ".floatval($price).", '$reas', ".intval($conf).", $val_rsi, $val_mac, $val_sig, $val_bbl, $val_bbu)";
  if (!$conn->query($sql)) {
    echo "[ERR] insert $pair/$type: ".$conn->error."\n";
  }
}

// ---- MAIN LOOP ----
$tables = listTables($conn);
$nowMinus = date('Y-m-d H:i:s', time() - $dedupeMinutes * 60);

foreach ($tables as $table) {
  $low = strtolower($table);
  if ($low === 'signals' || $low === 'migrations' || substr($low, 0, 7) === 'backup_') continue;

  $rows = fetchPrices($conn, $table, $candlesToFetch);
  if (count($rows) < ($macdSlow + 1)) {
    echo "Skip $table — data kurang\n";
    continue;
  }

  $prices = array_column($rows, 'price');
  $times  = array_column($rows, 'time');
  $n = count($prices);
  $i0 = $n - 1;       // index terakhir
  $im1 = $n - 2;      // sebelumnya

  $rsi = rsiArr($prices, $rsiPeriod);
  $emaF = emaArr($prices, $macdFast);
  $emaS = emaArr($prices, $macdSlow);

  $macd = array_fill(0, $n, null);
  for ($i = 0; $i < $n; $i++) {
    if ($emaF[$i] !== null && $emaS[$i] !== null) $macd[$i] = $emaF[$i] - $emaS[$i];
  }
  // signal (EMA9 dari nilai MACD yang valid), lalu align
  $valid = array_values(array_filter($macd, function($v){ return $v !== null; }));
  $sigAligned = array_fill(0, $n, null);
  if (count($valid) >= $macdSignal) {
    $sigRaw = emaArr($valid, $macdSignal);
    // cari index start MACD valid
    $firstIdx = null;
    for ($i = 0; $i < $n; $i++) { if ($macd[$i] !== null) { $firstIdx = $i; break; } }
    if ($firstIdx !== null) {
      for ($i = 0; $i < count($sigRaw); $i++) $sigAligned[$firstIdx + $i] = $sigRaw[$i];
    }
  }
  $bb = bbArr($prices, $bbPeriod, $bbK);

  $priceNow = $prices[$i0];
  $rsiNow   = $rsi[$i0];
  $rsiPrev  = $rsi[$im1] ?? null;
  $macdNow  = $macd[$i0];
  $macdPrev = $macd[$im1] ?? null;
  $sigNow   = $sigAligned[$i0];
  $sigPrev  = $sigAligned[$im1] ?? null;
  $ubNow    = $bb['ub'][$i0];
  $ubPrev   = $bb['ub'][$im1] ?? null;
  $lbNow    = $bb['lb'][$i0];
  $lbPrev   = $bb['lb'][$im1] ?? null;

  $pairEsc = $conn->real_escape_string($table);

  // ===== BUY rules (≥ 1 indikator) =====
  $buyScore = 0; $buyReasons = [];
  if (!is_null($rsiNow) && $rsiNow < 30) { $buyScore++; $buyReasons[] = 'RSI<30'; }
  if (!is_null($macdNow) && !is_null($sigNow) && $macdNow > $sigNow) { $buyScore++; $buyReasons[] = 'MACD>Signal'; }
  if (!is_null($lbNow) && $priceNow < $lbNow) { $buyScore++; $buyReasons[] = 'Price<BB_Lower'; }

  if ($buyScore >= 1) {
    $chk = $conn->prepare("SELECT COUNT(*) FROM signals WHERE pair=? AND signal_type='BUY' AND created_at>=?");
    $chk->bind_param("ss", $pairEsc, $nowMinus);
    $chk->execute(); $chk->bind_result($cntB); $chk->fetch(); $chk->close();

    if (intval($cntB) === 0) {
      insertSignal($conn, $table, 'BUY', $priceNow, implode(', ', $buyReasons), $buyScore,
                   $rsiNow, $macdNow, $sigNow, $lbNow, null);
    }
  }

  // ===== SELL rules (RSI>70 + optional) =====
  $sellScore = 0; $sellReasons = [];
  if (!is_null($rsiNow) && $rsiNow > 70) { $sellScore++; $sellReasons[] = 'RSI>70'; }
  if (!is_null($macdPrev) && !is_null($sigPrev) && !is_null($macdNow) && !is_null($sigNow) &&
      $macdPrev > $sigPrev && $macdNow < $sigNow) {
    $sellScore++; $sellReasons[] = 'MACD cross down';
  }
  if (!is_null($ubPrev) && !is_null($ubNow) && $prices[$im1] >= $ubPrev && $priceNow < $ubNow) {
    $sellScore++; $sellReasons[] = 'Reject BB upper';
  }

  if ($sellScore >= 1) {
    $chk = $conn->prepare("SELECT COUNT(*) FROM signals WHERE pair=? AND signal_type='SELL' AND created_at>=?");
    $chk->bind_param("ss", $pairEsc, $nowMinus);
    $chk->execute(); $chk->bind_result($cntS); $chk->fetch(); $chk->close();

    if (intval($cntS) === 0) {
      insertSignal($conn, $table, 'SELL', $priceNow, implode(', ', $sellReasons), $sellScore,
                   $rsiNow, $macdNow, $sigNow, null, $ubNow);
    }
  }

  echo "OK $table — BUY=$buyScore SELL=$sellScore\n";
}

$conn->close();
echo "All done.\n";
