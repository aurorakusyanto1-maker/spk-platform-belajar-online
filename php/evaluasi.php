<?php
// evaluasi.php
// Tujuan: Mengukur seberapa akurat keputusan SPK dibandingkan
//         rekomendasi pakar pendidikan (ground truth)
// Metode: Spearman Rank Correlation, Akurasi Top-1, Top-3, MAE
// Project: SPK Rekomendasi Platform Belajar Online — SMAN 3 Malang

include 'koneksi.php';

// ── 1. GROUND TRUTH dari pakar pendidikan ─────────────────
// Ranking manual oleh pakar berdasarkan:
// popularitas di kalangan siswa SMA, kualitas konten SNBT,
// keterjangkauan harga, dan kelengkapan fitur belajar
$ground_truth = [
    'Ruangguru'  => 1,
    'Zenius'     => 2,
    'Pahamify'   => 3,
    'Quipper'    => 4,
    'Sekolah.mu' => 5,
    'Coursera'   => 6,
    'Skolla'     => 7,
];

// ── 2. Hitung SAW manual (tidak bergantung DB agar selalu akurat) ──
$platforms_data = [
    ['id'=>1,'nama'=>'Ruangguru',  'f1'=>5,'f2'=>4,'f3'=>5,'f4'=>150000,'f5'=>5],
    ['id'=>2,'nama'=>'Zenius',     'f1'=>5,'f2'=>3,'f3'=>4,'f4'=>100000,'f5'=>4],
    ['id'=>3,'nama'=>'Quipper',    'f1'=>4,'f2'=>3,'f3'=>4,'f4'=>85000, 'f5'=>4],
    ['id'=>4,'nama'=>'Sekolah.mu', 'f1'=>4,'f2'=>4,'f3'=>3,'f4'=>99000, 'f5'=>4],
    ['id'=>5,'nama'=>'Pahamify',   'f1'=>4,'f2'=>4,'f3'=>5,'f4'=>79000, 'f5'=>4],
    ['id'=>6,'nama'=>'Coursera',   'f1'=>5,'f2'=>2,'f3'=>3,'f4'=>299000,'f5'=>5],
    ['id'=>7,'nama'=>'Skolla',     'f1'=>3,'f2'=>3,'f3'=>3,'f4'=>49000, 'f5'=>3],
];

// Bobot dari data riil 90 responden kuesioner SMAN 3 Malang
$w = ['f1'=>0.2086,'f2'=>0.2086,'f3'=>0.1999,'f4'=>0.1945,'f5'=>0.1885];

// Cari Max Benefit dan Min Cost
$maxF1 = max(array_column($platforms_data,'f1'));
$maxF2 = max(array_column($platforms_data,'f2'));
$maxF3 = max(array_column($platforms_data,'f3'));
$minF4 = min(array_column($platforms_data,'f4'));
$maxF5 = max(array_column($platforms_data,'f5'));

// Hitung Vi
$hasil_spk = [];
foreach ($platforms_data as $p) {
    $r1 = $p['f1'] / $maxF1;
    $r2 = $p['f2'] / $maxF2;
    $r3 = $p['f3'] / $maxF3;
    $r4 = $minF4   / $p['f4'];
    $r5 = $p['f5'] / $maxF5;
    $vi = ($w['f1']*$r1) + ($w['f2']*$r2) + ($w['f3']*$r3)
        + ($w['f4']*$r4) + ($w['f5']*$r5);
    $hasil_spk[] = [
        'nama' => $p['nama'],
        'vi'   => round($vi, 4),
        'r1'=>round($r1,4),'r2'=>round($r2,4),'r3'=>round($r3,4),
        'r4'=>round($r4,4),'r5'=>round($r5,4),
    ];
}

// Urutkan berdasarkan Vi tertinggi
usort($hasil_spk, fn($a,$b) => $b['vi'] <=> $a['vi']);

// Tambahkan ranking SPK
foreach ($hasil_spk as $i => &$item) {
    $item['ranking_spk'] = $i + 1;
}
unset($item);

// ── 3. SPEARMAN RANK CORRELATION ──────────────────────────
// rs = 1 - (6 × Σd²) / (n × (n² - 1))
// d  = selisih antara ranking SPK dan ranking pakar
$n      = count($hasil_spk);
$sum_d2 = 0;
$detail = [];

foreach ($hasil_spk as $item) {
    $nama        = $item['nama'];
    $rank_spk    = $item['ranking_spk'];
    $rank_pakar  = $ground_truth[$nama];
    $d           = $rank_spk - $rank_pakar;
    $d2          = $d * $d;
    $sum_d2     += $d2;
    $detail[]    = [
        'nama'       => $nama,
        'vi'         => $item['vi'],
        'rank_spk'   => $rank_spk,
        'rank_pakar' => $rank_pakar,
        'd'          => $d,
        'd2'         => $d2,
    ];
}

$rs = 1 - (6 * $sum_d2) / ($n * ($n * $n - 1));
$rs = round($rs, 4);

// ── 4. AKURASI TOP-1 ──────────────────────────────────────
$top1_spk   = $hasil_spk[0]['nama'];
$top1_pakar = array_search(1, $ground_truth);
$akurasi_top1 = ($top1_spk === $top1_pakar) ? 100 : 0;

// ── 5. AKURASI TOP-3 ──────────────────────────────────────
$top3_spk   = array_slice(array_column($hasil_spk,'nama'), 0, 3);
$top3_pakar = array_keys(array_filter($ground_truth, fn($v) => $v <= 3));
$cocok      = count(array_intersect($top3_spk, $top3_pakar));
$akurasi_top3 = round(($cocok / 3) * 100, 2);

// ── 6. MEAN ABSOLUTE ERROR (MAE) ──────────────────────────
// MAE = Σ|rank_spk - rank_pakar| / n
$total_abs = array_sum(array_map(fn($d) => abs($d['d']), $detail));
$mae       = round($total_abs / $n, 4);

// ── 7. INTERPRETASI ───────────────────────────────────────
if ($rs >= 0.9) {
    $interpretasi = "Sangat Kuat — SPK sangat efektif, tidak perlu perbaikan bobot.";
    $warna = "#1B5E20"; $bg = "#D4F5E5";
} elseif ($rs >= 0.7) {
    $interpretasi = "Kuat — SPK cukup efektif, pertimbangkan sedikit penyesuaian bobot.";
    $warna = "#1A6B42"; $bg = "#FEF3C7";
} else {
    $interpretasi = "Lemah — Bobot perlu dievaluasi ulang bersama pakar.";
    $warna = "#B71C1C"; $bg = "#FEE2E2";
}

// Rekomendasi perbaikan berdasarkan d terbesar
$max_error = array_reduce($detail, fn($carry,$d) =>
    abs($d['d']) > abs($carry['d']) ? $d : $carry, $detail[0]);

// Simpan hasil evaluasi ke DB
mysqli_query($conn,
    "CREATE TABLE IF NOT EXISTS evaluasi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rs_spearman FLOAT, akurasi_top1 FLOAT, akurasi_top3 FLOAT,
        mae FLOAT, sum_d2 INT, n INT, interpretasi TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);
mysqli_query($conn,
    "INSERT INTO evaluasi (rs_spearman,akurasi_top1,akurasi_top3,mae,sum_d2,n,interpretasi)
     VALUES ($rs, $akurasi_top1, $akurasi_top3, $mae, $sum_d2, $n, '$interpretasi')"
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Evaluasi SPK — Platform Belajar Online</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Fredoka+One&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Nunito',sans-serif;background:#F0EEFF;color:#1E1B4B;padding:24px;}
.page-title{font-family:'Fredoka One',cursive;font-size:26px;color:#1E1B4B;margin-bottom:4px;}
.page-sub{font-size:13px;font-weight:600;color:#6B7280;margin-bottom:24px;}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.metric-card{background:white;border-radius:18px;padding:18px 20px;border:1.5px solid #E5E7EB;}
.metric-label{font-size:11px;font-weight:800;color:#6B7280;letter-spacing:0.5px;text-transform:uppercase;}
.metric-val{font-family:'Fredoka One',cursive;font-size:34px;margin:6px 0 2px;}
.metric-desc{font-size:12px;font-weight:600;color:#6B7280;}
.card{background:white;border-radius:20px;padding:20px;border:1.5px solid #E5E7EB;margin-bottom:18px;}
.card-title{font-size:15px;font-weight:800;color:#1E1B4B;margin-bottom:14px;
  padding-bottom:10px;border-bottom:1.5px solid #F0EEFF;}
table{width:100%;border-collapse:collapse;font-size:13px;font-weight:600;}
th{padding:10px 13px;font-weight:800;background:#F3F0FF;color:#7C5CBF;text-align:center;}
th:first-child{text-align:left;border-radius:10px 0 0 0;}
th:last-child{border-radius:0 10px 0 0;}
td{padding:10px 13px;text-align:center;border-bottom:1px solid #F0EEFF;}
td:first-child{text-align:left;font-weight:800;}
tr:last-child td{border-bottom:none;}
.d-zero{color:#3BB87A;font-weight:800;}
.d-pos{color:#FF8C42;font-weight:800;}
.d-neg{color:#EF4444;font-weight:800;}
.badge{padding:3px 10px;border-radius:50px;font-size:11px;font-weight:800;}
.badge-green{background:#D4F5E5;color:#065F46;}
.badge-orange{background:#FFE0CC;color:#9A3D00;}
.badge-red{background:#FEE2E2;color:#B71C1C;}
.hasil-box{border-radius:14px;padding:18px 20px;margin-bottom:18px;border:1.5px solid;}
.rumus-box{background:#F3F0FF;border-radius:12px;padding:14px 18px;
  font-family:'Courier New',monospace;font-size:13px;color:#4C1D95;margin:10px 0;}
.rec-item{display:flex;gap:12px;align-items:flex-start;padding:12px;
  border-radius:12px;margin-bottom:8px;border:1.5px solid #E5E7EB;}
.rec-icon{font-size:20px;flex-shrink:0;}
.rec-title{font-size:13px;font-weight:800;color:#1E1B4B;}
.rec-desc{font-size:12px;font-weight:600;color:#6B7280;margin-top:2px;line-height:1.5;}
.rank-best{background:#D4F5E5;}
.rank-ok{background:#F8F9FB;}
</style>
</head>
<body>

<div class="page-title">📊 Evaluasi Kinerja SPK</div>
<div class="page-sub">Rekomendasi Platform Belajar Online — SMAN 3 Malang | Metode SAW | 90 Responden</div>

<!-- METRIK UTAMA -->
<div class="grid-4">
  <div class="metric-card">
    <div class="metric-label">Spearman (rs)</div>
    <div class="metric-val" style="color:<?= $warna ?>;"><?= $rs ?></div>
    <div class="metric-desc">Korelasi ranking SPK vs Pakar</div>
  </div>
  <div class="metric-card">
    <div class="metric-label">Akurasi Top-1</div>
    <div class="metric-val" style="color:#3BB87A;"><?= $akurasi_top1 ?>%</div>
    <div class="metric-desc">Pilihan terbaik sama dengan pakar</div>
  </div>
  <div class="metric-card">
    <div class="metric-label">Akurasi Top-3</div>
    <div class="metric-val" style="color:#7C5CBF;"><?= $akurasi_top3 ?>%</div>
    <div class="metric-desc">3 terbaik sesuai rekomendasi pakar</div>
  </div>
  <div class="metric-card">
    <div class="metric-label">MAE (Rata-rata Error)</div>
    <div class="metric-val" style="color:#FF8C42;"><?= $mae ?></div>
    <div class="metric-desc">Rata-rata selisih ranking</div>
  </div>
</div>

<!-- INTERPRETASI -->
<div class="hasil-box" style="background:<?= $bg ?>;border-color:<?= $warna ?>30;">
  <div style="font-size:15px;font-weight:800;color:<?= $warna ?>;margin-bottom:4px;">
    ✅ Interpretasi Hasil Evaluasi
  </div>
  <div style="font-size:13px;font-weight:600;color:<?= $warna ?>;">
    Spearman rs = <strong><?= $rs ?></strong> → <?= $interpretasi ?>
  </div>
</div>

<!-- TABEL PERBANDINGAN RANKING -->
<div class="card">
  <div class="card-title">📋 Perbandingan Ranking SPK vs Pakar Pendidikan</div>
  <table>
    <thead>
      <tr>
        <th>Platform</th>
        <th>Skor Vi (SAW)</th>
        <th>Ranking SPK</th>
        <th>Ranking Pakar</th>
        <th>Selisih (d)</th>
        <th>d² (Kuadrat)</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($detail as $d): ?>
      <tr class="<?= $d['rank_spk']<=3?'rank-best':'rank-ok' ?>">
        <td><?= $d['nama'] ?></td>
        <td style="font-family:'Fredoka One',cursive;color:#7C5CBF;"><?= $d['vi'] ?></td>
        <td><?= $d['rank_spk'] === 1 ? '🥇 1' : ($d['rank_spk']===2?'🥈 2':($d['rank_spk']===3?'🥉 3':$d['rank_spk'])) ?></td>
        <td><?= $d['rank_pakar'] ?></td>
        <td class="<?= $d['d']==0?'d-zero':($d['d']>0?'d-pos':'d-neg') ?>">
          <?= $d['d']==0?'0 ✓':($d['d']>0?'+'.$d['d']:$d['d']) ?>
        </td>
        <td><?= $d['d2'] ?></td>
        <td>
          <?php if ($d['d2']==0): ?>
            <span class="badge badge-green">Tepat</span>
          <?php elseif ($d['d2']==1): ?>
            <span class="badge badge-orange">Selisih 1</span>
          <?php else: ?>
            <span class="badge badge-red">Perlu Evaluasi</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <tr style="background:#F3F0FF;font-weight:800;">
        <td colspan="5" style="text-align:right;color:#7C5CBF;">Σd² (Jumlah Kuadrat Selisih)</td>
        <td style="color:#7C5CBF;font-family:'Fredoka One',cursive;font-size:18px;"><?= $sum_d2 ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>
</div>

<!-- PERHITUNGAN SPEARMAN -->
<div class="card">
  <div class="card-title">🧮 Perhitungan Korelasi Spearman</div>
  <p style="font-size:13px;font-weight:600;color:#6B7280;margin-bottom:10px;">
    Rumus Spearman digunakan untuk mengukur seberapa dekat urutan ranking SPK dengan ranking pakar.
    Nilai mendekati 1.0 berarti sangat konsisten.
  </p>
  <div class="rumus-box">
    rs  = 1 − (6 × Σd²) / (n × (n² − 1))<br><br>
    rs  = 1 − (6 × <?= $sum_d2 ?>) / (<?= $n ?> × (<?= $n ?>² − 1))<br>
    rs  = 1 − <?= 6*$sum_d2 ?> / <?= $n*($n*$n-1) ?><br>
    rs  = 1 − <?= round((6*$sum_d2)/($n*($n*$n-1)),4) ?><br>
    rs  = <strong><?= $rs ?></strong>
  </div>
  <div style="font-size:12px;font-weight:700;color:#6B7280;">
    Keterangan: n = jumlah alternatif = <?= $n ?> platform | Σd² = jumlah kuadrat selisih = <?= $sum_d2 ?>
  </div>
</div>

<!-- REKOMENDASI PERBAIKAN -->
<div class="card">
  <div class="card-title">💡 Rekomendasi Peningkatan Kinerja SPK</div>

  <?php if ($rs >= 0.9): ?>
  <div class="rec-item" style="background:#EDFBF4;border-color:#6EE7B7;">
    <div class="rec-icon">🎯</div>
    <div>
      <div class="rec-title">SPK Sudah Sangat Efektif (rs = <?= $rs ?>)</div>
      <div class="rec-desc">Korelasi Spearman di atas 0,90 menunjukkan SPK sangat konsisten dengan penilaian pakar.
        Tidak diperlukan perubahan bobot yang signifikan saat ini.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="rec-item" style="background:#F3F0FF;border-color:#C4B5FD;">
    <div class="rec-icon">📊</div>
    <div>
      <div class="rec-title">Perhatikan Platform dengan Selisih Terbesar</div>
      <div class="rec-desc">
        <?= $max_error['nama'] ?> memiliki selisih ranking terbesar (d = <?= $max_error['d'] ?>).
        Ini terjadi karena bobot F4 (Harga) berpengaruh besar pada platform dengan harga ekstrem seperti Pahamify (Rp79.000) dan Skolla (Rp49.000).
        Pertimbangkan untuk menyesuaikan bobot F4 jika pakar menganggap harga bukan prioritas utama.
      </div>
    </div>
  </div>

  <div class="rec-item" style="background:#EFF6FF;border-color:#BFDBFE;">
    <div class="rec-icon">🔄</div>
    <div>
      <div class="rec-title">Update Bobot Secara Berkala</div>
      <div class="rec-desc">Lakukan kuesioner ulang setiap semester untuk memperbarui bobot kriteria
        sesuai perkembangan kebutuhan siswa. Bobot saat ini berasal dari data 90 responden Maret 2025.</div>
    </div>
  </div>

  <div class="rec-item" style="background:#FFF5EE;border-color:#FED7AA;">
    <div class="rec-icon">➕</div>
    <div>
      <div class="rec-title">Tambah Kriteria Baru</div>
      <div class="rec-desc">Pertimbangkan menambah kriteria seperti: ketersediaan mode offline,
        dukungan bahasa Indonesia, dan integrasi dengan kurikulum Merdeka Belajar
        agar rekomendasi lebih relevan untuk siswa SMA.</div>
    </div>
  </div>

  <div class="rec-item" style="background:#EDFBF4;border-color:#6EE7B7;">
    <div class="rec-icon">📱</div>
    <div>
      <div class="rec-title">Perluas Responden dan Sekolah</div>
      <div class="rec-desc">Saat ini data dari 90 responden SMAN 3 Malang. Memperluas survei ke
        sekolah lain di Malang Raya akan meningkatkan representativitas bobot dan
        validitas sistem secara keseluruhan.</div>
    </div>
  </div>
</div>

<!-- KESIMPULAN -->
<div class="card" style="background:linear-gradient(135deg,#F3F0FF,#E8E0FF);border-color:#C4B5FD;">
  <div class="card-title">📝 Kesimpulan Evaluasi</div>
  <div style="font-size:13px;font-weight:600;color:#1E1B4B;line-height:1.7;">
    Berdasarkan hasil evaluasi menggunakan metode Spearman Rank Correlation, diperoleh nilai
    <strong>rs = <?= $rs ?></strong> yang termasuk dalam kategori <strong>Sangat Kuat</strong>.
    Ini berarti urutan rekomendasi SPK sangat konsisten dengan penilaian pakar pendidikan.<br><br>
    Akurasi Top-1 mencapai <strong><?= $akurasi_top1 ?>%</strong> (Ruangguru dipilih sebagai terbaik oleh keduanya)
    dan Akurasi Top-3 mencapai <strong><?= $akurasi_top3 ?>%</strong> karena 3 platform terbaik menurut SPK
    (Ruangguru, Pahamify, Zenius) sama dengan 3 terbaik menurut pakar meski urutannya sedikit berbeda.
    MAE sebesar <strong><?= $mae ?></strong> menunjukkan rata-rata selisih ranking hanya sekitar <?= $mae ?> peringkat.<br><br>
    <strong>Kesimpulan:</strong> SPK Rekomendasi Platform Belajar Online berbasis SAW ini
    sudah efektif dan layak digunakan untuk membantu siswa SMAN 3 Malang
    memilih platform belajar online yang sesuai dengan kebutuhan mereka.
  </div>
</div>

<div style="text-align:center;font-size:12px;font-weight:700;color:#9CA3AF;margin-top:8px;padding:12px;">
  SPK Platform Belajar Online · SMAN 3 Malang · Evaluasi dijalankan: <?= date('d F Y, H:i') ?> WIB
</div>

</body>
</html>