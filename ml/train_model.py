"""
=============================================================
  MODUL 8 — Training Model Machine Learning
  SPK Rekomendasi Platform Belajar Online
  SMAN 3 Malang | Random Forest Classifier
=============================================================
  Cara menjalankan:
    python train_model.py

  Output:
    model_platform.pkl    → model Random Forest terlatih
    scaler_platform.pkl   → StandardScaler untuk normalisasi
=============================================================
"""

import pandas as pd
from sklearn.model_selection import train_test_split
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
from sklearn.preprocessing import StandardScaler
import joblib

# ── 1. Load dataset ─────────────────────────────────────────
print("=" * 55)
print("  TRAINING MODEL ML — SPK Platform Belajar Online")
print("=" * 55)

data = pd.read_csv("data_platform.csv")

print(f"\n[INFO] Dataset dimuat: {len(data)} baris")
print(f"[INFO] Distribusi label:")
print(f"       Rekomen  (1) : {(data['label'] == 1).sum()} platform")
print(f"       Tidak    (0) : {(data['label'] == 0).sum()} platform")

# ── 2. Pisahkan fitur (X) dan label (y) ─────────────────────
# 5 fitur sesuai kriteria SPK project SMAN 3 Malang
X = data[['f1_kelengkapan', 'f2_gaya_belajar', 'f3_tryout',
          'f4_harga', 'f5_rating']]
y = data['label']

print(f"\n[INFO] Fitur yang digunakan: {list(X.columns)}")

# ── 3. Scaling fitur ─────────────────────────────────────────
# Sangat penting: f4_harga (49000-350000) vs f1-f3,f5 (1-5)
# Tanpa scaling, model akan condong ke f4_harga karena nilainya jauh lebih besar
scaler    = StandardScaler()
X_scaled  = scaler.fit_transform(X)

print("\n[INFO] StandardScaler diterapkan pada semua fitur")
print("       f4_harga (49000-350000) diseragamkan dengan f1-f5 (1-5)")

# ── 4. Split data 80% training, 20% testing ─────────────────
X_train, X_test, y_train, y_test = train_test_split(
    X_scaled, y,
    test_size=0.2,
    random_state=42,
    stratify=y          # pastikan proporsi label seimbang
)

print(f"\n[INFO] Data training : {len(X_train)} sampel (80%)")
print(f"[INFO] Data testing  : {len(X_test)} sampel (20%)")

# ── 5. Training Random Forest ────────────────────────────────
# n_estimators=100 : pakai 100 pohon keputusan → voting mayoritas
# random_state=42  : agar hasil reproducible / sama setiap dijalankan
model = RandomForestClassifier(
    n_estimators=100,
    max_depth=None,         # pohon tumbuh bebas
    min_samples_split=2,
    random_state=42
)

print("\n[PROSES] Melatih Random Forest (100 pohon)...")
model.fit(X_train, y_train)
print("[SELESAI] Model berhasil dilatih!")

# ── 6. Evaluasi model ────────────────────────────────────────
y_pred  = model.predict(X_test)
akurasi = accuracy_score(y_test, y_pred)

print("\n" + "=" * 55)
print("  HASIL EVALUASI MODEL")
print("=" * 55)
print(f"\nAkurasi Model     : {akurasi * 100:.1f}%")

print("\nClassification Report:")
print(classification_report(
    y_test, y_pred,
    target_names=['Tidak Rekomen (0)', 'Rekomen (1)']
))

print("Confusion Matrix:")
cm = confusion_matrix(y_test, y_pred)
print(f"  TN={cm[0][0]}  FP={cm[0][1]}")
print(f"  FN={cm[1][0]}  TP={cm[1][1]}")

# ── 7. Feature Importance ────────────────────────────────────
nama_fitur = [
    'F1 - Kelengkapan Materi',
    'F2 - Kesesuaian Gaya Belajar',
    'F3 - Fitur Tryout',
    'F4 - Harga Berlangganan',
    'F5 - Rating Pengguna'
]
importances = model.feature_importances_

print("\n" + "=" * 55)
print("  FEATURE IMPORTANCE (Kriteria paling berpengaruh)")
print("=" * 55)

# Urutkan dari yang paling penting
sorted_idx  = importances.argsort()[::-1]
for rank, idx in enumerate(sorted_idx):
    bar = "█" * int(importances[idx] * 40)
    print(f"  #{rank+1} {nama_fitur[idx]:<35} {importances[idx]:.4f}  {bar}")

# ── 8. Simpan model & scaler ─────────────────────────────────
joblib.dump(model,  'model_platform.pkl')
joblib.dump(scaler, 'scaler_platform.pkl')

print("\n" + "=" * 55)
print("  FILE TERSIMPAN")
print("=" * 55)
print("  model_platform.pkl    → model Random Forest")
print("  scaler_platform.pkl   → StandardScaler")
print("\n[SELESAI] Siap digunakan oleh api_ml.py")
print("=" * 55)

# ── 9. Contoh prediksi manual ────────────────────────────────
print("\n[TEST] Prediksi manual Pahamify:")
import numpy as np
contoh = np.array([[4, 4, 5, 79000, 4]])   # data Pahamify
contoh_scaled = scaler.transform(contoh)
label   = model.predict(contoh_scaled)[0]
proba   = model.predict_proba(contoh_scaled)[0][label]
print(f"       F1=4, F2=4, F3=5, F4=79000, F5=4")
print(f"       Hasil : {'Rekomen' if label==1 else 'Tidak Rekomen'}")
print(f"       Proba : {proba:.4f} ({proba*100:.1f}% keyakinan)")
