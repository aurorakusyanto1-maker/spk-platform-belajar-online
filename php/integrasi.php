<?php
/**
 * =============================================================
 *  MODUL 9 — Integrasi PHP + FastAPI ML
 *  SPK Rekomendasi Platform Belajar Online | SMAN 3 Malang
 * =============================================================
 *  Alur kerja file ini:
 *    1. Ambil semua data platform dari MySQL
 *    2. Kirim ke FastAPI (api_ml.py) → dapat prediksi ML
 *    3. Hitung skor SAW dengan bobot dari 90 responden
 *    4. Gabungkan: Skor Hybrid = (0.7 × SAW) + (0.3 × Proba ML)
 *    5. Ranking berdasarkan skor hybrid tertinggi
 *    6. Simpan hasil ke tabel hasil_saw
 *    7. Tampilkan halaman HTML ranking hybrid
 * =============================================================
 *  Pastikan FastAPI sudah berjalan sebelum membuka halaman ini:
 *    uvicorn api_ml:app --reload --port 8000
 * =============================================================
 */

include 'koneksi.php';

// ═══════════════════════════════════════════════════════════════
// BAGIAN 1 — AMBIL DATA PLATFORM DARI DATABASE
// ═══════════════════════════════════════════════════════════════

$res_platform = mysqli_query($conn, "SELECT * FROM platform ORDER BY id");
$data         = mysqli_fetch_all($res_platform, MYSQLI_ASSOC);

if (empty($data)) {
    die(json_encode(['error' => 'Tidak ada data platform di database.']));
}

// ═══════════════════════════════════════════════════════════════
// BAGIAN 2 — KIRIM KE FASTAPI (PREDIKSI BATCH)
// ═══════════════════════════════════════════════════════════════

// Susun payload: setiap platform jadi satu item
// Nama field HARUS sama dengan PlatformInput di api_ml.py
$payload = array_map(fn($d) => [
    'nama'            => $d['nama'],
    'f1_kelengkapan'  => (float) $d['f1_kelengkapan'],
    'f2_gaya_belajar' => (float) $d['f2_gaya_belajar'],
    'f3_tryout'       => (float) $d['f3_tryout'],
    'f4_harga'        => (float) $d['f4_harga'],
    'f5_rating'       => (float) $d['f5_rating'],
], $data);

// Kirim HTTP POST ke FastAPI menggunakan cURL
$ch = curl_init("http://127.0.0.1:8000/prediksi-batch");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_TIMEOUT        => 10,    // timeout 10 detik
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

// Tangani error koneksi ke API
if ($curl_err || $http_code !== 200) {
    $pesan_error = $curl_err
        ? "Tidak dapat terhubung ke FastAPI. Pastikan sudah menjalankan:<br><code>uvicorn api_ml:app --reload --port 8000</code>"
        : "FastAPI mengembalikan HTTP $http_code";
    die("
    <div style='font-family:Arial;max-width:600px;margin:60px auto;padding:24px;
                border:1px solid #f5c6cb;border-radius:8px;background:#fff3f3;'>
        <h3 style='color:#c0392b;margin:0 0 12px'>❌ Koneksi ke API ML Gagal</h3>
        <p>$pesan_error</p>
        <p style='margin-top:12px;font-size:13px;color:#666'>
            Buka terminal baru, masuk ke folder <code>ml/</code>, lalu jalankan perintah di atas.
        </p>
    </div>");
}

// Parse respons JSON dari FastAPI
$hasil_api  = json_decode($response, true);
$prediksi   = $hasil_api['hasil'] ?? [];

// ═══════════════════════════════════════════════════════════════
// BAGIAN 3 — HITUNG SKOR SAW + HYBRID
// ═══════════════════════════════════════════════════════════════

// Bobot dari kuesioner 90 responden SMAN 3 Malang
// Total = 1.0001 ≈ 1.00 ✓
$w = [
    'f1' => 0.2086,   // Kelengkapan Materi  (Benefit)
    'f2' => 0.2086,   // Gaya Belajar        (Benefit)
    'f3' => 0.1999,   // Fitur Tryout        (Benefit)
    'f4' => 0.1945,   // Harga               (Cost)
    'f5' => 0.1885,   // Rating              (Benefit)
];

// Nilai acuan normalisasi (dari data tabel platform)
$max_f1    = 5;       // max kelengkapan
$max_f2    = 4;       // max gaya belajar (nilai max di data = 4)
$max_f3    = 5;       // max tryout
$min_f4    = 49000;   // harga TERENDAH = Skolla (untuk normalisasi Cost)
$max_f5    = 5;       // max rating

// Bobot kontribusi ML ke skor hybrid
$bobot_ml  = 0.3;     // ML  = 30%
$bobot_saw = 0.7;     // SAW = 70%

$hasil_hybrid = [];

foreach ($data as $i => $row) {
    // ── Normalisasi SAW ──────────────────────────────────────
    $r1 = $row['f1_kelengkapan']  / $max_f1;          // Benefit
    $r2 = $row['f2_gaya_belajar'] / $max_f2;          // Benefit
    $r3 = $row['f3_tryout']       / $max_f3;          // Benefit
    $r4 = $min_f4                 / $row['f4_harga']; // Cost: min/xij
    $r5 = $row['f5_rating']       / $max_f5;          // Benefit

    // ── Skor SAW: Vi = Σ(Wj × rij) ──────────────────────────
    $skor_saw = ($w['f1'] * $r1)
              + ($w['f2'] * $r2)
              + ($w['f3'] * $r3)
              + ($w['f4'] * $r4)
              + ($w['f5'] * $r5);

    // ── Ambil hasil prediksi ML dari API ─────────────────────
    $label_ml = $prediksi[$i]['label']        ?? 0;
    $proba_ml = $prediksi[$i]['probabilitas'] ?? 0.0;
    $ket_ml   = $prediksi[$i]['keterangan']   ?? '-';

    // ── Skor Hybrid: gabungan SAW + ML ───────────────────────
    // Formula: (70% × skor_SAW) + (30% × probabilitas_ML)
    $skor_hybrid = ($bobot_saw * $skor_saw) + ($bobot_ml * $proba_ml);

    $hasil_hybrid[] = [
        'platform_id'  => (int) $row['id'],
        'nama'         => $row['nama'],
        // Nilai ternormalisasi
        'r1'           => round($r1, 4),
        'r2'           => round($r2, 4),
        'r3'           => round($r3, 4),
        'r4'           => round($r4, 4),
        'r5'           => round($r5, 4),
        // Skor individual
        'skor_saw'     => round($skor_saw,    4),
        'label_ml'     => $label_ml,
        'proba_ml'     => round($proba_ml,    4),
        'keterangan_ml'=> $ket_ml,
        'skor_hybrid'  => round($skor_hybrid, 4),
        // Data asli untuk referensi
        'f4_harga'     => (int) $row['f4_harga'],
    ];
}

// ── Ranking berdasarkan skor hybrid tertinggi ────────────────
usort($hasil_hybrid, fn($a, $b) => $b['skor_hybrid'] <=> $a['skor_hybrid']);

// Tambahkan nomor ranking
foreach ($hasil_hybrid as $rank => &$item) {
    $item['ranking'] = $rank + 1;
}
unset($item);

// ═══════════════════════════════════════════════════════════════
// BAGIAN 4 — SIMPAN HASIL KE DATABASE (siswa_id = 1 default)
// ═══════════════════════════════════════════════════════════════

$siswa_id = isset($_GET['siswa_id']) ? (int) $_GET['siswa_id'] : 1;

// Hapus hasil lama untuk siswa ini
mysqli_query($conn, "DELETE FROM hasil_saw WHERE siswa_id = $siswa_id");

foreach ($hasil_hybrid as $item) {
    $pid     = $item['platform_id'];
    $r1      = $item['r1'];   $r2 = $item['r2'];
    $r3      = $item['r3'];   $r4 = $item['r4'];   $r5 = $item['r5'];
    $skor    = $item['skor_hybrid'];   // simpan skor hybrid sebagai skor_saw
    $ranking = $item['ranking'];

    mysqli_query($conn,
        "INSERT INTO hasil_saw
            (siswa_id, platform_id, r1, r2, r3, r4, r5, skor_saw, ranking)
         VALUES
            ($siswa_id, $pid, $r1, $r2, $r3, $r4, $r5, $skor, $ranking)"
    );
}

// ═══════════════════════════════════════════════════════════════
// BAGIAN 5 — TAMPILAN HTML
// ═══════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SPK Hybrid — SAW + ML | SMAN 3 Malang</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; background: #f0f4f8; color: #333; }

    header {
      background: linear-gradient(135deg, #1F3864, #2E5F8A);
      color: white; padding: 20px 30px;
    }
    header h1 { font-size: 20px; }
    header p  { font-size: 13px; opacity: 0.8; margin-top: 4px; }

    .container { max-width: 1100px; margin: 24px auto; padding: 0 16px; }

    .card {
      background: white; border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      padding: 20px; margin-bottom: 20px;
    }
    .card h2 {
      font-size: 16px; color: #1F3864; margin-bottom: 14px;
      border-bottom: 2px solid #D6E4F0; padding-bottom: 8px;
    }

    /* Kartu metrik ringkasan */
    .metric-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .metric-box {
      background: #f5f9ff; border-radius: 8px; padding: 14px;
      text-align: center; border: 1px solid #D6E4F0;
    }
    .metric-box .val { font-size: 22px; font-weight: bold; color: #1F3864; }
    .metric-box .lbl { font-size: 12px; color: #888; margin-top: 4px; }

    /* Label ML */
    .badge-rekomen {
      background: #e8f5e9; color: #1b5e20;
      padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold;
    }
    .badge-tidak {
      background: #fff3e0; color: #bf360c;
      padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold;
    }

    /* Tabel */
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th {
      background: #1F3864; color: white;
      padding: 10px 12px; text-align: center;
    }
    td { padding: 9px 12px; text-align: center; border-bottom: 1px solid #eee; }
    tr:nth-child(even) { background: #f5f9ff; }

    .rank-1 { background: #c8e6c9 !important; font-weight: bold; }
    .rank-2 { background: #dcedc8 !important; }
    .rank-3 { background: #f0f4c3 !important; }
    .last-rank { background: #ffccbc !important; }

    .skor-hybrid { font-size: 15px; font-weight: bold; color: #1F3864; }
    .skor-rendah { color: #bf360c !important; }
    .harga-col   { color: #bf360c; font-weight: bold; }

    /* Progress bar skor */
    .bar-wrap { background: #e0e0e0; border-radius: 4px; height: 8px; min-width: 80px; }
    .bar-fill  { height: 8px; border-radius: 4px; background: #1F3864; }

    /* Formula box */
    .formula {
      background: #EAF2FB; border-left: 4px solid #1F3864;
      padding: 12px 16px; border-radius: 0 8px 8px 0;
      font-size: 13px; margin: 12px 0;
    }
    .formula strong { color: #1F3864; }

    .note { font-size: 12px; color: #888; margin-top: 8px; }

    /* Responsif */
    @media (max-width: 768px) {
      .metric-grid { grid-template-columns: repeat(2, 1fr); }
    }
  </style>
</head>
<body>

<header>
  <h1>SPK Hybrid — SAW + Machine Learning</h1>
  <p>Rekomendasi Platform Belajar Online &nbsp;|&nbsp; SMAN 3 Malang &nbsp;|&nbsp;
     Random Forest + SAW Terintegrasi</p>
</header>

<div class="container">

  <!-- ── RINGKASAN ────────────────────────────────────────── -->
  <div class="card">
    <h2>Ringkasan Hasil Integrasi</h2>
    <?php
      $total_platform = count($hasil_hybrid);
      $total_rekomen  = array_sum(array_column($hasil_hybrid, 'label_ml'));
      $top_platform   = $hasil_hybrid[0]['nama'];
      $top_skor       = $hasil_hybrid[0]['skor_hybrid'];
    ?>
    <div class="metric-grid">
      <div class="metric-box">
        <div class="val"><?= $total_platform ?></div>
        <div class="lbl">Total Platform</div>
      </div>
      <div class="metric-box" style="background:#e8f5e9;border-color:#a5d6a7">
        <div class="val" style="color:#1b5e20"><?= $total_rekomen ?></div>
        <div class="lbl">Direkomendasikan ML</div>
      </div>
      <div class="metric-box" style="background:#fff3e0;border-color:#ffcc80">
        <div class="val" style="color:#bf360c"><?= $total_platform - $total_rekomen ?></div>
        <div class="lbl">Tidak Rekomen ML</div>
      </div>
      <div class="metric-box" style="background:#e3f2fd;border-color:#90caf9">
        <div class="val" style="color:#0d47a1; font-size:16px"><?= $top_platform ?></div>
        <div class="lbl">Rekomendasi Terbaik (<?= $top_skor ?>)</div>
      </div>
    </div>
  </div>

  <!-- ── FORMULA ──────────────────────────────────────────── -->
  <div class="card">
    <h2>Formula Skor Hybrid</h2>
    <div class="formula">
      <strong>Skor Hybrid</strong> = (0,70 × Skor SAW) + (0,30 × Probabilitas ML)
    </div>
    <p style="font-size:13px;color:#555;margin-top:8px">
      SAW berkontribusi <strong>70%</strong> (berdasarkan bobot kuesioner 90 responden) dan
      Machine Learning berkontribusi <strong>30%</strong> (keyakinan prediksi Random Forest).
      Platform dengan skor hybrid tertinggi menjadi rekomendasi utama.
    </p>
  </div>

  <!-- ── TABEL HASIL HYBRID ───────────────────────────────── -->
  <div class="card">
    <h2>Hasil Ranking Hybrid (SAW + ML)</h2>
    <table>
      <thead>
        <tr>
          <th>Rank</th>
          <th>Platform</th>
          <th>Harga/Bulan</th>
          <th>Skor SAW</th>
          <th>Prediksi ML</th>
          <th>Proba ML</th>
          <th>Skor Hybrid</th>
          <th>Visualisasi</th>
          <th>Keterangan</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hasil_hybrid as $item):
          $rank     = $item['ranking'];
          $rowClass = match(true) {
            $rank === 1                          => 'rank-1',
            $rank === 2                          => 'rank-2',
            $rank === 3                          => 'rank-3',
            $rank === $total_platform            => 'last-rank',
            default                              => ''
          };
          $skorClass = $item['skor_hybrid'] < 0.6 ? 'skor-rendah' : 'skor-hybrid';
          $barPct    = round($item['skor_hybrid'] * 100);
        ?>
        <tr class="<?= $rowClass ?>">
          <td>
            <strong>
              <?= match($rank) {
                1 => '🥇 1',
                2 => '🥈 2',
                3 => '🥉 3',
                default => $rank
              } ?>
            </strong>
          </td>
          <td style="text-align:left"><strong><?= htmlspecialchars($item['nama']) ?></strong></td>
          <td class="harga-col">Rp <?= number_format($item['f4_harga'], 0, ',', '.') ?></td>
          <td><?= $item['skor_saw'] ?></td>
          <td>
            <span class="<?= $item['label_ml'] ? 'badge-rekomen' : 'badge-tidak' ?>">
              <?= $item['label_ml'] ? '✓ Rekomen' : '✗ Tidak' ?>
            </span>
          </td>
          <td><?= $item['proba_ml'] ?></td>
          <td class="<?= $skorClass ?>"><?= $item['skor_hybrid'] ?></td>
          <td>
            <div class="bar-wrap">
              <div class="bar-fill" style="width:<?= $barPct ?>%;
                background:<?= $item['label_ml'] ? '#2E7D32' : '#BF360C' ?>"></div>
            </div>
          </td>
          <td>
            <?php if ($rank === 1): ?>
              <span style="color:#1b5e20;font-weight:bold">Terbaik</span>
            <?php elseif ($rank <= 3): ?>
              <span style="color:#33691e">Top 3</span>
            <?php elseif (!$item['label_ml']): ?>
              <span style="color:#bf360c">Tidak Rekomen</span>
            <?php else: ?>
              <span style="color:#666">Alternatif</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">
      Skor Hybrid = (0,70 × SAW) + (0,30 × Probabilitas ML) &nbsp;|&nbsp;
      Rekomendasi terbaik: <strong><?= $hasil_hybrid[0]['nama'] ?></strong>
      (Hybrid = <?= $hasil_hybrid[0]['skor_hybrid'] ?>)
    </p>
  </div>

  <!-- ── DETAIL NORMALISASI SAW ───────────────────────────── -->
  <div class="card">
    <h2>Detail Normalisasi SAW (rij)</h2>
    <p style="font-size:12px;color:#666;margin-bottom:10px">
      Benefit: rij = xij / Max &nbsp;|&nbsp;
      Cost (F4): rij = Min(49000) / xij &nbsp;|&nbsp;
      Skor SAW: Vi = Σ(Wj × rij) dengan bobot dari 90 responden
    </p>
    <table>
      <thead>
        <tr>
          <th>Platform</th>
          <th>r1 F1<br><small>÷5</small></th>
          <th>r2 F2<br><small>÷4</small></th>
          <th>r3 F3<br><small>÷5</small></th>
          <th>r4 F4 (Cost)<br><small>49000÷x</small></th>
          <th>r5 F5<br><small>÷5</small></th>
          <th>Skor SAW</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hasil_hybrid as $item): ?>
        <tr>
          <td style="text-align:left"><strong><?= htmlspecialchars($item['nama']) ?></strong></td>
          <td><?= $item['r1'] ?></td>
          <td><?= $item['r2'] ?></td>
          <td><?= $item['r3'] ?></td>
          <td style="background:#fff3e0;color:#bf360c;font-weight:bold"><?= $item['r4'] ?></td>
          <td><?= $item['r5'] ?></td>
          <td><strong><?= $item['skor_saw'] ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="note">
      Bobot: W1=0,2086 | W2=0,2086 | W3=0,1999 | W4=0,1945 | W5=0,1885
    </p>
  </div>

  <!-- ── INFO TEKNIS ──────────────────────────────────────── -->
  <div class="card">
    <h2>Informasi Teknis Integrasi</h2>
    <table>
      <tr style="background:#f5f9ff">
        <td style="text-align:left;font-weight:bold">Status FastAPI</td>
        <td style="text-align:left;color:green">✓ Terhubung (HTTP 200)</td>
      </tr>
      <tr>
        <td style="text-align:left;font-weight:bold">URL API</td>
        <td style="text-align:left"><code>http://127.0.0.1:8000/prediksi-batch</code></td>
      </tr>
      <tr style="background:#f5f9ff">
        <td style="text-align:left;font-weight:bold">Algoritma ML</td>
        <td style="text-align:left">Random Forest Classifier (100 pohon)</td>
      </tr>
      <tr>
        <td style="text-align:left;font-weight:bold">Bobot SAW</td>
        <td style="text-align:left">Dari kuesioner 90 responden SMAN 3 Malang (2025)</td>
      </tr>
      <tr style="background:#f5f9ff">
        <td style="text-align:left;font-weight:bold">Formula Hybrid</td>
        <td style="text-align:left">(0,70 × SAW) + (0,30 × Probabilitas ML)</td>
      </tr>
      <tr>
        <td style="text-align:left;font-weight:bold">Hasil disimpan</td>
        <td style="text-align:left">Tabel <code>hasil_saw</code>, siswa_id = <?= $siswa_id ?></td>
      </tr>
      <tr style="background:#f5f9ff">
        <td style="text-align:left;font-weight:bold">Respons API</td>
        <td style="text-align:left">
          Total: <?= $hasil_api['total'] ?? '-' ?> |
          Rekomen: <?= $hasil_api['rekomen'] ?? '-' ?> |
          Tidak: <?= $hasil_api['tidak'] ?? '-' ?>
        </td>
      </tr>
    </table>
  </div>

</div>
</body>
</html>
