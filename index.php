<?php
$defaultPair = 'BTCIDR';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Prediksi Crypto Indodax (Candlestick AI)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>
  <script src="assets/app.js" defer></script>
</head>
<body>
  <main class="container">
    <header class="hero card glass">
      <div>
        <p class="eyebrow">Analisis Candlestick • Indodax</p>
        <h1>Prediksi Harga Crypto</h1>
        <p class="subtitle">
          Mesin prediksi menggunakan gabungan tren EMA, RSI, MACD, ATR, serta pola candle
          (engulfing, hammer, shooting star, doji) untuk estimasi harga ke depan.
        </p>
      </div>
      <div class="badge-wrap">
        <span class="badge">Realtime Market Data</span>
        <span class="badge">Model Multi-Horizon</span>
      </div>
    </header>

    <section class="card controls">
      <div class="field">
        <label for="pair">Pair (Indodax)</label>
        <input id="pair" value="<?= htmlspecialchars($defaultPair, ENT_QUOTES) ?>" placeholder="Contoh: ETHIDR, SOLIDR" />
      </div>
      <div class="field">
        <label for="resolution">Timeframe Candle</label>
        <select id="resolution">
          <option value="60">1 Jam</option>
          <option value="240" selected>4 Jam</option>
          <option value="D">1 Hari</option>
        </select>
      </div>
      <button id="runBtn" class="btn-primary">Jalankan Prediksi</button>
    </section>

    <section class="grid-two">
      <article class="card">
        <h2>Harga Historis & Proyeksi</h2>
        <div class="chart-container">
          <canvas id="priceChart"></canvas>
        </div>
      </article>

      <article class="card">
        <h2>Ringkasan Sinyal</h2>
        <div id="signals" class="signals"></div>
      </article>
    </section>

    <section class="card">
      <h2>Target Prediksi</h2>
      <div id="predictions" class="prediction-grid"></div>
      <p class="disclaimer">
        Catatan: ini adalah estimasi statistik berbasis candle dan indikator teknikal,
        bukan jaminan keuntungan. Gunakan manajemen risiko sebelum trading/investasi.
      </p>
    </section>
  </main>
</body>
</html>
