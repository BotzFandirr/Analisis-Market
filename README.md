# Prediksi Harga Crypto Indodax (PHP)

Web PHP responsif untuk estimasi harga crypto berbasis data candle Indodax dengan teknik:

- EMA (20/50)
- RSI (14)
- ATR (volatilitas)
- Deteksi pola candlestick (doji, engulfing, hammer, shooting star)
- Proyeksi multi horizon: **besok, lusa, 7 hari, 30 hari**

## Menjalankan

```bash
php -S 0.0.0.0:8080
```

Buka `http://localhost:8080`.

## Catatan Akurasi

Model ini memaksimalkan akurasi dari sisi teknikal (statistical projection), namun tetap tidak menjamin hasil pasar karena crypto sangat volatil.
