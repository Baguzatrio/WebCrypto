<?php
$conn = new mysqli("localhost", "ifupnvjt_root", "KitaBisa", "ifupnvjt_db_crypto");
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Ambil semua ticker dari API Indodax
$url = "https://indodax.com/api/tickers";
$json = file_get_contents($url);
$data = json_decode($json, true);

foreach ($data['tickers'] as $pair => $info) {
    $last  = $info['last'];
    $high  = $info['high'];
    $low   = $info['low'];
    $buy   = $info['buy'];
    $sell  = $info['sell'];

    // Ambil volume pertama yang ada
    $volume = 0;
    foreach ($info as $key => $val) {
        if (strpos($key, 'vol_') === 0) {
            $volume = $val;
            break;
        }
    }

    $time  = time();

    $sql = "INSERT INTO `$pair` 
            (last_price, high_price, low_price, buy_price, sell_price, volume, server_time) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ddddddi", $last, $high, $low, $buy, $sell, $volume, $time);
        $stmt->execute();
    }
}

//echo "Data berhasil disimpan";
$conn->close();
?>
