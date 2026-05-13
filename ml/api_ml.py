"""
=============================================================
  MODUL 9 — REST API Machine Learning dengan FastAPI
  SPK Rekomendasi Platform Belajar Online
  SMAN 3 Malang
=============================================================
  Cara menjalankan:
    uvicorn api_ml:app --reload --port 8000

  Endpoint tersedia:
    GET  /                  → cek status API
    GET  /platform          → lihat daftar platform dari DB (opsional)
    POST /prediksi          → prediksi 1 platform
    POST /prediksi-batch    → prediksi banyak platform sekaligus

  Dokumentasi otomatis:
    http://127.0.0.1:8000/docs
=============================================================
"""

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
import joblib
import numpy as np
from typing import List

# ── Inisialisasi aplikasi FastAPI ────────────────────────────
app = FastAPI(
    title="SPK Platform Belajar Online — ML API",
    description="REST API Machine Learning untuk prediksi rekomendasi platform belajar. "
                "Menggunakan Random Forest Classifier dengan 5 kriteria dari kuesioner "
                "90 responden SMAN 3 Malang.",
    version="1.0.0"
)

# ── CORS — izinkan PHP (XAMPP) memanggil API ini ─────────────
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],      # izinkan semua origin (termasuk localhost XAMPP)
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── Load model & scaler ──────────────────────────────────────
# File ini dibuat setelah menjalankan train_model.py
try:
    model  = joblib.load("model_platform.pkl")
    scaler = joblib.load("scaler_platform.pkl")
    print("[OK] Model dan scaler berhasil dimuat.")
except FileNotFoundError as e:
    print(f"[ERROR] File tidak ditemukan: {e}")
    print("[INFO] Jalankan train_model.py terlebih dahulu!")
    model  = None
    scaler = None

# ── Schema Input ─────────────────────────────────────────────
class PlatformInput(BaseModel):
    """
    Data satu platform yang akan diprediksi.
    Sesuai dengan kolom pada tabel 'platform' di database MySQL.
    """
    nama:             str   = Field(..., example="Pahamify")
    f1_kelengkapan:   float = Field(..., ge=1, le=5, example=4,
                                   description="Kelengkapan materi (1-5)")
    f2_gaya_belajar:  float = Field(..., ge=1, le=5, example=4,
                                   description="Kesesuaian gaya belajar (1-5)")
    f3_tryout:        float = Field(..., ge=1, le=5, example=5,
                                   description="Fitur tryout/latihan soal (1-5)")
    f4_harga:         float = Field(..., ge=0,       example=79000,
                                   description="Harga berlangganan per bulan (Rupiah)")
    f5_rating:        float = Field(..., ge=1, le=5, example=4,
                                   description="Rating pengguna (1-5)")

# ── Schema Output ─────────────────────────────────────────────
class PrediksiOutput(BaseModel):
    nama:         str
    label:        int    # 1 = Rekomen, 0 = Tidak
    probabilitas: float  # tingkat keyakinan model (0.0 - 1.0)
    keterangan:   str    # "Direkomendasikan" / "Tidak Direkomendasikan"

# ── Helper: prediksi satu platform ───────────────────────────
def prediksi_satu(item: PlatformInput) -> dict:
    """Jalankan prediksi untuk satu item platform."""
    if model is None or scaler is None:
        raise HTTPException(
            status_code=503,
            detail="Model belum dimuat. Jalankan train_model.py terlebih dahulu."
        )

    # Susun array fitur sesuai urutan saat training
    # Urutan HARUS sama dengan X = data[['f1','f2','f3','f4','f5']] di train_model.py
    X = np.array([[
        item.f1_kelengkapan,
        item.f2_gaya_belajar,
        item.f3_tryout,
        item.f4_harga,
        item.f5_rating
    ]])

    # Normalisasi menggunakan scaler yang sama saat training
    X_scaled = scaler.transform(X)

    # Prediksi label (0 atau 1)
    label = int(model.predict(X_scaled)[0])

    # Probabilitas: ambil proba untuk label yang diprediksi
    proba = float(model.predict_proba(X_scaled)[0][label])

    return {
        "nama":         item.nama,
        "label":        label,
        "probabilitas": round(proba, 4),
        "keterangan":   "Direkomendasikan" if label == 1 else "Tidak Direkomendasikan"
    }

# ═══════════════════════════════════════════════════════════════
# ENDPOINT
# ═══════════════════════════════════════════════════════════════

@app.get("/", summary="Cek status API")
def root():
    """Endpoint untuk mengecek apakah API aktif dan model sudah dimuat."""
    return {
        "status":       "aktif",
        "aplikasi":     "SPK Platform Belajar Online — ML API",
        "sekolah":      "SMAN 3 Malang",
        "model_loaded": model is not None,
        "versi":        "1.0.0",
        "docs":         "http://127.0.0.1:8000/docs"
    }


@app.post(
    "/prediksi",
    response_model=PrediksiOutput,
    summary="Prediksi satu platform"
)
def prediksi(data: PlatformInput):
    """
    Prediksi apakah sebuah platform belajar layak direkomendasikan.

    - **label 1** = Direkomendasikan untuk siswa SMA
    - **label 0** = Tidak Direkomendasikan
    - **probabilitas** = tingkat keyakinan model (0.0 – 1.0)
    """
    return prediksi_satu(data)


@app.post(
    "/prediksi-batch",
    summary="Prediksi banyak platform sekaligus"
)
def prediksi_batch(items: List[PlatformInput]):
    """
    Prediksi semua platform sekaligus.
    Endpoint ini dipanggil oleh integrasi.php melalui cURL.

    Menerima list platform, mengembalikan list hasil prediksi.
    """
    if not items:
        raise HTTPException(status_code=400, detail="Data tidak boleh kosong.")

    hasil = []
    for item in items:
        hasil.append(prediksi_satu(item))

    return {
        "total":    len(hasil),
        "rekomen":  sum(1 for h in hasil if h["label"] == 1),
        "tidak":    sum(1 for h in hasil if h["label"] == 0),
        "hasil":    hasil
    }


@app.get("/info-model", summary="Informasi model yang digunakan")
def info_model():
    """Menampilkan informasi detail tentang model Random Forest yang dimuat."""
    if model is None:
        raise HTTPException(status_code=503, detail="Model belum dimuat.")

    return {
        "algoritma":      "Random Forest Classifier",
        "n_estimators":   model.n_estimators,
        "n_fitur":        model.n_features_in_,
        "nama_fitur": [
            "F1 - Kelengkapan Materi",
            "F2 - Kesesuaian Gaya Belajar",
            "F3 - Fitur Tryout",
            "F4 - Harga Berlangganan",
            "F5 - Rating Pengguna"
        ],
        "feature_importances": {
            "F1_kelengkapan":  round(float(model.feature_importances_[0]), 4),
            "F2_gaya_belajar": round(float(model.feature_importances_[1]), 4),
            "F3_tryout":       round(float(model.feature_importances_[2]), 4),
            "F4_harga":        round(float(model.feature_importances_[3]), 4),
            "F5_rating":       round(float(model.feature_importances_[4]), 4),
        },
        "kelas": ["Tidak Direkomendasikan (0)", "Direkomendasikan (1)"]
    }
