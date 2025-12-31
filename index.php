<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Harga Coin Cryptocurrency</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <style>
    .panel { max-width:1100px; margin:12px auto; background:#fff; border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,0.06); padding:12px; }
    .panel-header { display:flex; justify-content:space-between; align-items:center; gap:10px; }
    .btn-toggle { padding:8px 12px; border:1px solid #ccc; border-radius:6px; background:#fff; cursor:pointer; font-weight:600; }
    .btn-toggle.active { background:#007bff; color:#fff; border-color:#007bff; }
    .small-note { font-size:12px; color:#666; }
    .table-wrap.no-gap{ margin:0; }
  </style>
</head>
<body>
  <div class="container">

    <header class="page-header">
      <h1>Harga Coin Cryptocurrency</h1>

      <div class="controls">
        <div class="search-box">
          <input type="text" id="search" placeholder="Cari pair...">
        </div>
        <!-- tombol export dihapus -->
      </div>
    </header>

    <!-- ===== Recommended (Liquid) — filter turnover/spread/age ===== -->
    <div class="panel">
      <div class="panel-header">
        <h3 style="margin:0">Recommended (Liquid) — BUY ≤ 10 menit</h3>
        <div>
          <button id="btn-toggle-rec" class="btn-toggle">Tampilkan</button>
          <span class="small-note">Filter: Turnover ≥ Rp 2M • Spread ≤ 0.6% • Data ≤ 20 menit</span>
        </div>
      </div>

      <div id="rec-box" style="display:none; margin-top:10px;">
        <table class="ticker-table">
          <thead>
            <tr>
              <th>Pair</th>
              <th>Indikator</th>
              <th>#</th>
              <th>Turnover (≈IDR)</th>
              <th>Spread</th>
              <th>Last Price</th>
              <th>Waktu</th>
            </tr>
          </thead>
          <tbody id="rec-body">
            <tr><td colspan="7" style="text-align:center">Klik "Tampilkan"…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <!-- ===== /Recommended (Liquid) ===== -->

    <!-- ===== Worth to Buy (BUY dalam 10 menit terakhir) ===== -->
    <div class="panel">
      <div class="panel-header">
        <h3 style="margin:0">Worth to Buy — Signal Buy</h3>
        <div>
          <button id="btn-toggle-worth" class="btn-toggle">Tampilkan</button>
          <span class="small-note">Klik untuk tampil/sembunyikan</span>
        </div>
      </div>

      <div id="worth-box" style="display:none; margin-top:10px;">
        <table class="ticker-table" id="worth-table">
          <thead>
            <tr>
              <th>Pair</th>
              <th>Indikator Terpenuhi</th>
              <th># Indikator</th>
              <th>Waktu Sinyal</th>
            </tr>
          </thead>
          <tbody id="worth-body">
            <tr><td colspan="4" style="text-align:center">Klik "Tampilkan" untuk memuat data…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <!-- ===== /Worth to Buy ===== -->

    <!-- ===== Tabel Harga Semua Pair (kartu putih) ===== -->
    <div class="panel table-panel">
      <div class="panel-header">
        <h3 style="margin:0">Data Harga Semua Pair</h3>
      </div>

      <div class="table-wrap no-gap">
        <table class="ticker-table">
          <thead>
            <tr>
              <th>Pair</th>
              <th>Last Price</th>
              <th>High</th>
              <th>Low</th>
              <th>Buy</th>
              <th>Sell</th>
              <th>Volume</th>
              <th>Server Time</th>
            </tr>
          </thead>
          <tbody id="table-body"></tbody>
        </table>
      </div>
    </div>
    <!-- ===== /Tabel Harga ===== -->

  </div>

  <script>
  // ======= Harga (semua pair) =======
  function load_data(query = '') {
    $.ajax({
      url: "fetch_data.php",
      method: "POST",
      data: { query: query },
      success: function(data) {
        $('#table-body').html(data);
        prioritizeBTC();
      }
    });
  }

  // Urutkan baris agar pair yang diawali "btc" di paling atas
  function prioritizeBTC() {
    const rows = $('#table-body tr').get();
    rows.sort(function(a, b) {
      const ap = ($(a).find('td:first').text() || '').trim().toLowerCase();
      const bp = ($(b).find('td:first').text() || '').trim().toLowerCase();
      const aIsBTC = ap.startsWith('btc');
      const bIsBTC = bp.startsWith('btc');
      if (aIsBTC && !bIsBTC) return -1;
      if (!aIsBTC && bIsBTC) return 1;
      return ap.localeCompare(bp);
    });
    $('#table-body').empty().append(rows);
  }

  // ======= Worth to Buy (ajax) =======
  let worthLoadedOnce = false;
  function load_worth_to_buy(){
    $('#worth-body').html('<tr><td colspan="4" style="text-align:center">Memuat…</td></tr>');
    $.get('fetch_worth_to_buy.php') // versi 10 menit terakhir (kalau kamu pakai yang itu)
      .done(function(html){
        $('#worth-body').html(html);
        worthLoadedOnce = true;
      })
      .fail(function(){
        $('#worth-body').html('<tr><td colspan="4" style="text-align:center;color:red">Gagal memuat</td></tr>');
      });
  }

  // ======= Recommended (Liquid) (ajax) =======
  let recLoadedOnce = false;
  function load_recommended(){
    $('#rec-body').html('<tr><td colspan="7" style="text-align:center">Memuat…</td></tr>');
    $.get('fetch_worth_to_buy_liquid.php') // bisa tambahkan parameter ambang via query string
      .done(function(html){
        $('#rec-body').html(html);
        recLoadedOnce = true;
      })
      .fail(function(){
        $('#rec-body').html('<tr><td colspan="7" style="text-align:center;color:red">Gagal memuat</td></tr>');
      });
  }

  $(document).ready(function(){
    // load tabel harga
    load_data();

    // pencarian
    $('#search').on('input', function(){
      load_data($(this).val());
    });

    // toggle panel worth-to-buy
    $('#btn-toggle-worth').on('click', function(){
      const box = $('#worth-box');
      const isHidden = box.css('display') === 'none';
      if (isHidden) {
        box.slideDown(200);
        $(this).addClass('active').text('Sembunyikan');
        if (!worthLoadedOnce) load_worth_to_buy();
      } else {
        box.slideUp(200);
        $(this).removeClass('active').text('Tampilkan');
      }
    });

    // toggle panel recommended (liquid)
    $('#btn-toggle-rec').on('click', function(){
      const box = $('#rec-box');
      const isHidden = box.css('display') === 'none';
      if (isHidden) {
        box.slideDown(200);
        $(this).addClass('active').text('Sembunyikan');
        if (!recLoadedOnce) load_recommended();
      } else {
        box.slideUp(200);
        $(this).removeClass('active').text('Tampilkan');
      }
    });

    // auto-refresh saat panel terbuka
    setInterval(function(){
      if ($('#worth-box').is(':visible')) load_worth_to_buy();
      if ($('#rec-box').is(':visible')) load_recommended();
    }, 5 * 60 * 1000); // 5 menit
  });
  </script>
</body>
</html>
