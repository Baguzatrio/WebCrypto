<?php
// Recommended (Liquid) — minimal 1 indikator, dengan filter likuiditas
$DB_HOST = 'localhost';
$DB_USER = 'ifupnvjt_root';
$DB_PASS = 'KitaBisa';
$DB_NAME = 'ifupnvjt_db_crypto';

$SIGNAL_WINDOW_MIN = 60;    // sinyal ≤ 60 menit terakhir
$DATA_MAX_AGE_MIN  = 20;    // data harga ≤ 20 menit
$TURNOVER_MIN_IDR  = 2000000; // ≥ Rp 2.000.000
$SPREAD_MAX_PCT    = 0.6;   // ≤ 0.6%

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
  echo '<tr><td colspan="7" style="color:red">DB error: '.htmlspecialchars($conn->connect_error).'</td></tr>';
  exit;
}
$conn->set_charset('utf8mb4');

// Sinyal terbaru, confidence ≥ 1, di sisi MySQL (timezone aman)
$sql = "
  SELECT pair, reason, confidence, created_at, price
  FROM signals
  WHERE signal_type='BUY'
    AND confidence >= 1
    AND UNIX_TIMESTAMP(created_at) >= (UNIX_TIMESTAMP() - {$SIGNAL_WINDOW_MIN}*60)
  ORDER BY created_at DESC
  LIMIT 500
";

$res = $conn->query($sql);
if (!$res) {
  echo '<tr><td colspan="7" style="color:red">Query error</td></tr>';
  $conn->close(); exit;
}

if ($res->num_rows === 0) {
  echo '<tr><td colspan="7" style="text-align:center">Tidak ada sinyal BUY dalam '.$SIGNAL_WINDOW_MIN.' menit terakhir.</td></tr>';
  $conn->close(); exit;
}

// terbaru per pair
$latest = [];
while ($r = $res->fetch_assoc()) {
  $p = $r['pair'];
  if (!isset($latest[$p])) $latest[$p] = $r;
}

$out = [];
foreach ($latest as $pair => $sig) {
  $safe = $conn->real_escape_string($pair);
  $q = $conn->query("
    SELECT last_price, buy_price, sell_price, volume, server_time
    FROM `$safe`
    WHERE last_price IS NOT NULL
    ORDER BY server_time DESC, id DESC
    LIMIT 1
  ");
  if (!$q || $q->num_rows === 0) continue;
  $h = $q->fetch_assoc();

  $last = (float)$h['last_price'];
  $buy  = (float)$h['buy_price'];
  $sell = (float)$h['sell_price'];
  $vol  = (float)$h['volume'];
  $ts   = (int)$h['server_time'];

  // umur data
  if ((time() - $ts) > $DATA_MAX_AGE_MIN * 60) continue;

  // spread %
  $mid = ($buy + $sell) / 2.0;
  if ($mid <= 0) continue;
  $spreadPct = (($sell - $buy) / $mid) * 100.0;

  // turnover ~ last * volume
  $turnover = $last * $vol;

  // filter likuiditas
  if ($turnover < $TURNOVER_MIN_IDR) continue;
  if ($spreadPct > $SPREAD_MAX_PCT) continue;

  $out[] = [
    'pair'      => $pair,
    'reason'    => $sig['reason'],
    'conf'      => (int)$sig['confidence'],
    'turnover'  => $turnover,
    'spreadPct' => $spreadPct,
    'last'      => $last,
    'waktu'     => date('Y-m-d H:i:s', $ts),
  ];
}

if (!$out) {
  // fallback non-liquid agar kelihatan datanya
  $fb = $conn->query("
    SELECT pair, reason, confidence, created_at
    FROM signals
    WHERE signal_type='BUY' AND confidence >= 1
    ORDER BY created_at DESC
    LIMIT 10
  ");
  if ($fb && $fb->num_rows) {
    echo '<tr><td colspan="7" style="text-align:center;color:#666">Tidak ada yang lolos likuiditas. Menampilkan 10 sinyal terbaru (non-liquid) untuk pengecekan.</td></tr>';
    while ($r = $fb->fetch_assoc()) {
      $pair  = htmlspecialchars($r['pair']);
      $indk  = htmlspecialchars($r['reason'] ?: '-');
      $conf  = (int)$r['confidence'];
      echo '<tr>';
      echo '  <td><a href="detail.php?pair='.$pair.'">'.$pair.'</a></td>';
      echo '  <td>'.$indk.'</td>';
      echo '  <td style="text-align:center">'.$conf.'</td>';
      echo '  <td colspan="3" style="text-align:center">—</td>';
      echo '  <td>'.$r['created_at'].'</td>';
      echo '</tr>';
    }
  } else {
    echo '<tr><td colspan="7" style="text-align:center">Tidak ada sinyal.</td></tr>';
  }
  $conn->close(); exit;
}

// urutkan turnover desc
usort($out, function($a,$b){ return $b['turnover'] <=> $a['turnover']; });

foreach ($out as $r) {
  $pair  = htmlspecialchars($r['pair']);
  $indk  = htmlspecialchars($r['reason'] ?: '-');
  $conf  = (int)$r['conf'];
  $trn   = number_format($r['turnover'], 0, ',', '.');
  $spr   = number_format($r['spreadPct'], 2, ',', '.');
  $last  = number_format($r['last'], 0, ',', '.');
  $waktu = htmlspecialchars($r['waktu']);

  echo '<tr>';
  echo '  <td><a href="detail.php?pair='.$pair.'">'.$pair.'</a></td>';
  echo '  <td>'.$indk.'</td>';
  echo '  <td style="text-align:center">'.$conf.'</td>';
  echo '  <td style="text-align:right">'.$trn.'</td>';
  echo '  <td style="text-align:right">'.$spr.'%</td>';
  echo '  <td style="text-align:right">'.$last.'</td>';
  echo '  <td>'.$waktu.'</td>';
  echo '</tr>';
}

$conn->close();
