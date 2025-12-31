<?php
// --- debug sementara, boleh matikan kalau sudah oke ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// ------------------------------------------------------

$conn = new mysqli("localhost", "ifupnvjt_root", "KitaBisa", "ifupnvjt_db_crypto");
if ($conn->connect_error) {
  die("DB error: " . htmlspecialchars($conn->connect_error));
}
$conn->set_charset('utf8mb4');

$search = isset($_POST['query']) ? strtolower(trim($_POST['query'])) : '';

$skipTables = ['signals','migrations']; // tabel non-pair

$tables = [];
$res = $conn->query("SHOW TABLES");
while ($row = $res->fetch_array()) {
  $t = $row[0];
  if (in_array(strtolower($t), $skipTables, true)) continue;
  if ($search !== '' && strpos(strtolower($t), $search) === false) continue;
  $tables[] = $t;
}

$rows = [];
foreach ($tables as $table) {
  $safe = $conn->real_escape_string($table);
  // ambil baris terbaru (urutkan server_time DESC, id DESC)
  $q = $conn->query("SELECT * FROM `{$safe}` ORDER BY server_time DESC, id DESC LIMIT 1");
  if (!$q || $q->num_rows === 0) continue;
  $r = $q->fetch_assoc();

  // pastikan kolom-kolom penting ada
  foreach (['last_price','high_price','low_price','buy_price','sell_price','volume','server_time'] as $k) {
    if (!array_key_exists($k, $r)) { continue 2; }
  }

  $rows[] = ['pair'=>$table, 'data'=>$r];
}

// ===== Sorting: pair yang diawali "btc" di paling atas, sisanya A–Z =====
usort($rows, function($a, $b){
  $pa = strtolower($a['pair']);
  $pb = strtolower($b['pair']);
  $aIsBtc = (substr($pa, 0, 3) === 'btc');  // pengganti str_starts_with
  $bIsBtc = (substr($pb, 0, 3) === 'btc');
  if ($aIsBtc && !$bIsBtc) return -1;
  if (!$aIsBtc && $bIsBtc) return 1;
  return strcmp($pa, $pb);
});

// ===== Output baris <tr> =====
$fmt0 = function($v){ return number_format((float)$v, 0, ',', '.'); };
$fmtV = function($v){ return number_format((float)$v, 2, ',', '.'); };
$fmtT = function($ts){
  if (is_numeric($ts)) return date('d-m-Y H:i:s', (int)$ts);
  return htmlspecialchars((string)$ts);
};

$out = '';
foreach ($rows as $row) {
  $pair = htmlspecialchars($row['pair']);
  $r = $row['data'];

  $out .= '<tr>'
    . '<td><a href="detail.php?pair='.$pair.'">'.$pair.'</a></td>'
    . '<td style="text-align:right">'.$fmt0($r['last_price']).'</td>'
    . '<td style="text-align:right">'.$fmt0($r['high_price']).'</td>'
    . '<td style="text-align:right">'.$fmt0($r['low_price']).'</td>'
    . '<td style="text-align:right">'.$fmt0($r['buy_price']).'</td>'
    . '<td style="text-align:right">'.$fmt0($r['sell_price']).'</td>'
    . '<td style="text-align:right">'.$fmtV($r['volume']).'</td>'
    . '<td>'.$fmtT($r['server_time']).'</td>'
    . '</tr>';
}

if ($out === '') {
  $msg = $search !== '' ? 'Tidak ada pair yang cocok dengan pencarian.' : 'Belum ada data harga untuk ditampilkan.';
  $out = '<tr><td colspan="8" style="text-align:center;color:#666">'.$msg.'</td></tr>';
}

echo $out;
