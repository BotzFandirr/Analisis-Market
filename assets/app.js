const runBtn = document.getElementById('runBtn');
const pairEl = document.getElementById('pair');
const resolutionEl = document.getElementById('resolution');
const predictionsEl = document.getElementById('predictions');
const signalsEl = document.getElementById('signals');

let chart;

const idr = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
});

function fmtPct(x) {
  const val = (x * 100).toFixed(2) + '%';
  if (x > 0) return `<span class="positive">+${val}</span>`;
  if (x < 0) return `<span class="negative">${val}</span>`;
  return val;
}

function renderPredictions(preds) {
  const labels = {
    besok: 'Besok',
    lusa: 'Lusa',
    '7_hari': '1 Minggu',
    '30_hari': '1 Bulan',
  };

  predictionsEl.innerHTML = Object.entries(preds).map(([k, v]) => `
    <article class="pred-card">
      <h3>${labels[k] ?? k}</h3>
      <div class="price">${idr.format(v.target)}</div>
      <div class="range">Range: ${idr.format(v.range_low)} - ${idr.format(v.range_high)}</div>
      <div class="conf">Kepercayaan Model: ${v.confidence}%</div>
    </article>
  `).join('');
}

function renderSignals(data) {
  const i = data.indicators;
  const rows = [
    ['Harga terakhir', idr.format(data.last_price)],
    ['EMA20 vs EMA50', fmtPct(i.trend_cross)],
    ['RSI(14)', i.rsi14?.toFixed(2) ?? '-'],
    ['Momentum mingguan', fmtPct(i.trend_fast)],
    ['Momentum bulanan', fmtPct(i.trend_slow)],
    ['Volatilitas (ATR/Price)', fmtPct(i.volatility_ratio)],
    ['Pola candle terbaru', i.pattern],
  ];

  signalsEl.innerHTML = rows.map(([k, v]) => `
    <div class="signal"><span>${k}</span><strong>${v}</strong></div>
  `).join('');
}

function renderChart(history, preds) {
  const labels = history.map((x) => new Date(x.time * 1000).toLocaleDateString('id-ID'));
  const values = history.map((x) => x.close);

  const futureLabels = ['Besok', 'Lusa', '1 Minggu', '1 Bulan'];
  const futureValues = [preds.besok.target, preds.lusa.target, preds['7_hari'].target, preds['30_hari'].target];

  const ctx = document.getElementById('priceChart');
  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [...labels, ...futureLabels],
      datasets: [
        {
          label: 'Harga Penutupan',
          data: [...values, null, null, null, null],
          borderColor: '#74b9ff',
          tension: 0.2,
          pointRadius: 0,
          borderWidth: 2,
        },
        {
          label: 'Prediksi Target',
          data: [
            ...new Array(values.length - 1).fill(null),
            values[values.length - 1],
            ...futureValues,
          ],
          borderColor: '#3ed39f',
          borderDash: [6, 6],
          pointRadius: 3,
          tension: 0.15,
          borderWidth: 2,
        },
      ],
    },
    options: {
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: '#dce8ff' } },
      },
      scales: {
        x: { ticks: { color: '#9cb3da', maxTicksLimit: 7 } },
        y: { ticks: { color: '#9cb3da' } },
      },
    },
  });
}

async function runPrediction() {
  const pair = pairEl.value.trim() || 'BTCIDR';
  const resolution = resolutionEl.value;

  runBtn.disabled = true;
  runBtn.textContent = 'Menghitung...';

  try {
    const res = await fetch(`api/predict.php?pair=${encodeURIComponent(pair)}&resolution=${encodeURIComponent(resolution)}`);
    const raw = await res.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      throw new Error('Respons API bukan JSON valid. Cek server PHP / endpoint api/predict.php.');
    }

    if (!data.ok) {
      throw new Error(data.error || 'Gagal mengambil prediksi.');
    }

    renderPredictions(data.predictions);
    renderSignals(data);
    renderChart(data.history, data.predictions);
  } catch (err) {
    predictionsEl.innerHTML = `<div class="pred-card">Error: ${err.message}</div>`;
  } finally {
    runBtn.disabled = false;
    runBtn.textContent = 'Jalankan Prediksi';
  }
}

runBtn.addEventListener('click', runPrediction);
window.addEventListener('load', runPrediction);
