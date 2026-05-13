<?php
// hitung_saw.php
// Core logic metode SAW untuk SPK Platform Belajar Online
// Langkah: ambil data → cari max/min → normalisasi → bobot → ranking → simpan

include 'koneksi.php';

// ── 1. Ambil data semua platform dari database ─────────────
$sql = "SELECT * FROM platform";
$res = mysqli_query($conn, $sql);
$platforms = mysqli_fetch_all($res, MYSQLI_ASSOC);

// ── 2. Ambil bobot kriteria (dari hasil kuesioner 90 responden) ──
$sql_bobot = "SELECT * FROM kriteria ORDER BY id";
$res_bobot = mysqli_query($conn, $sql_bobot);
$kriteria  = mysqli_fetch_all($res_bobot, MYSQLI_ASSOC);

// Bobot dari data riil 90 responden SMAN 3 Malang
$w = [
    'f1' => 0.2086,  // Kelengkapan Materi   (Benefit)
    'f2' => 0.2086,  // Gaya Belajar         (Benefit)
    'f3' => 0.1999,  // Fitur Tryout         (Benefit)
    'f4' => 0.1945,  // Harga                (Cost)
    'f5' => 0.1885,  // Rating               (Benefit)
];

// ── 3. Cari nilai MAX (Benefit) dan MIN (Cost) ────────────
$max_f1 = max(array_column($platforms, 'f1_kelengkapan'));  // = 5
$max_f2 = max(array_column($platforms, 'f2_gaya_belajar')); // = 4
$max_f3 = max(array_column($platforms, 'f3_tryout'));        // = 5
$min_f4 = min(array_column($platforms, 'f4_harga'));         // = 49000
$max_f5 = max(array_column($platforms, 'f5_rating'));        // = 5

// ── 4. Normalisasi & hitung skor SAW ─────────────────────
$hasil = [];
foreach ($platforms as $p) {

    // Normalisasi BENEFIT: Xij / Max(Xij)
    $r1 = $p['f1_kelengkapan']  / $max_f1;
    $r2 = $p['f2_gaya_belajar'] / $max_f2;
    $r3 = $p['f3_tryout']       / $max_f3;
    $r5 = $p['f5_rating']       / $max_f5;

    // Normalisasi COST: Min(Xij) / Xij
    $r4 = $min_f4 / $p['f4_harga'];

    // Skor SAW = Σ(bobot × normalisasi)
    $skor = ($w['f1'] * $r1)
          + ($w['f2'] * $r2)
          + ($w['f3'] * $r3)
          + ($w['f4'] * $r4)
          + ($w['f5'] * $r5);

    $hasil[] = [
        'id'       => $p['id'],
        'nama'     => $p['nama'],
        'r1'       => round($r1,   4),
        'r2'       => round($r2,   4),
        'r3'       => round($r3,   4),
        'r4'       => round($r4,   4),
        'r5'       => round($r5,   4),
        'skor_saw' => round($skor, 4),
    ];
}

// ── 5. Urutkan berdasarkan skor tertinggi (ranking) ───────
usort($hasil, fn($a, $b) => $b['skor_saw'] <=> $a['skor_saw']);

// ── 6. Simpan hasil ke database (contoh siswa_id = 1) ─────
$siswa_id = isset($_GET['siswa_id']) ? (int)$_GET['siswa_id'] : 1;

// Hapus hasil lama siswa ini dulu
mysqli_query($conn, "DELETE FROM hasil_saw WHERE siswa_id = $siswa_id");

foreach ($hasil as $rank => $item) {
    $ranking   = $rank + 1;
    $pid       = $item['id'];
    $skor      = $item['skor_saw'];
    $r1 = $item['r1']; $r2 = $item['r2']; $r3 = $item['r3'];
    $r4 = $item['r4']; $r5 = $item['r5'];

    mysqli_query($conn,
        "INSERT INTO hasil_saw
            (siswa_id, platform_id, r1, r2, r3, r4, r5, skor_saw, ranking)
         VALUES
            ($siswa_id, $pid, $r1, $r2, $r3, $r4, $r5, $skor, $ranking)"
    );
}

// ── 7. Kirim hasil ke frontend sebagai JSON ───────────────
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'bobot'  => $w,
    'data'   => $hasil
]);
?>
