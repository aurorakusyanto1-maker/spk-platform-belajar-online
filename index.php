<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SPK Rekomendasi Platform Belajar Online</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; background: #f0f4f8; color: #333; }

    header {
      background: linear-gradient(135deg, #1F3864, #2E5F8A);
      color: white; padding: 20px 30px;
    }
    header h1 { font-size: 20px; }
    header p  { font-size: 13px; opacity: 0.8; margin-top: 4px; }

    .container { max-width: 1000px; margin: 24px auto; padding: 0 16px; }

    .card {
      background: white; border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      padding: 20px; margin-bottom: 20px;
    }
    .card h2 { font-size: 16px; color: #1F3864; margin-bottom: 14px;
               border-bottom: 2px solid #D6E4F0; padding-bottom: 8px; }

    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { background: #1F3864; color: white; padding: 10px 12px; text-align: center; }
    td { padding: 9px 12px; text-align: center; border-bottom: 1px solid #eee; }
    tr:nth-child(even) { background: #f5f9ff; }
    .cost-col { background: #fff3e0 !important; color: #bf360c; font-weight: bold; }

    .btn {
      background: #1F3864; color: white; border: none;
      padding: 12px 28px; border-radius: 8px; font-size: 15px;
      cursor: pointer; transition: background 0.2s;
    }
    .btn:hover { background: #2E5F8A; }

    .rank-1 { background: #a5d6a7 !important; font-weight: bold; }
    .rank-2 { background: #c8e6c9 !important; }
    .rank-3 { background: #dcedc8 !important; }
    .rank-7 { background: #ffccbc !important; }

    .badge-benefit {
      background: #e8f5e9; color: #1b5e20;
      padding: 2px 8px; border-radius: 12px; font-size: 11px;
    }
    .badge-cost {
      background: #fff3e0; color: #bf360c;
      padding: 2px 8px; border-radius: 12px; font-size: 11px;
    }

    .bobot-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
    .bobot-item {
      background: #EAF2FB; border-radius: 8px; padding: 8px 14px;
      font-size: 12px; text-align: center; flex: 1; min-width: 100px;
    }
    .bobot-item strong { display: block; font-size: 15px; color: #1F3864; }

    #loading { display: none; color: #666; font-style: italic; margin-top: 12px; }
    .center { text-align: center; }
    .note { font-size: 12px; color: #888; margin-top: 8px; }
  </style>
</head>
<body>

<header>
  <h1>Sistem Pendukung Keputusan — Rekomendasi Platform Belajar Online</h1>
  <p>SMAN 3 Malang &nbsp;|&nbsp; Metode SAW &nbsp;|&nbsp; Bobot dari data 90 responden kuesioner</p>
</header>

<div class="container">

  <!-- BOBOT KRITERIA -->
  <div class="card">
    <h2>Bobot Kriteria (dari Kuesioner 90 Responden)</h2>
    <div class="bobot-row">
      <div class="bobot-item"><strong>0,2086</strong>F1 — Kelengkapan Materi<br><span class="badge-benefit">Benefit</span></div>
      <div class="bobot-item"><strong>0,2086</strong>F2 — Kesesuaian Gaya Belajar<br><span class="badge-benefit">Benefit</span></div>
      <div class="bobot-item"><strong>0,1999</strong>F3 — Fitur Tryout<br><span class="badge-benefit">Benefit</span></div>
      <div class="bobot-item" style="background:#fff3e0"><strong style="color:#bf360c">0,1945</strong>F4 — Harga<br><span class="badge-cost">Cost</span></div>
      <div class="bobot-item"><strong>0,1885</strong>F5 — Rating Pengguna<br><span class="badge-benefit">Benefit</span></div>
    </div>
    <p class="note">Total bobot = 0,2086 + 0,2086 + 0,1999 + 0,1945 + 0,1885 = 1,0001 ≈ 1,00 ✓</p>
  </div>

  <!-- DATA PLATFORM -->
  <div class="card">
    <h2>Data Alternatif Platform Belajar Online</h2>
    <?php
    include 'koneksi.php';
    $res = mysqli_query($conn, "SELECT * FROM platform ORDER BY id");
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    ?>
    <table>
      <tr>
        <th>No</th>
        <th>Nama Platform</th>
        <th>F1 Kelengkapan<br><small>(Benefit, 1-5)</small></th>
        <th>F2 Gaya Belajar<br><small>(Benefit, 1-5)</small></th>
        <th>F3 Tryout<br><small>(Benefit, 1-5)</small></th>
        <th>F4 Harga (Rp)<br><small>(Cost)</small></th>
        <th>F5 Rating<br><small>(Benefit, 1-5)</small></th>
      </tr>
      <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td><strong><?= $r['nama'] ?></strong></td>
        <td><?= $r['f1_kelengkapan'] ?></td>
        <td><?= $r['f2_gaya_belajar'] ?></td>
        <td><?= $r['f3_tryout'] ?></td>
        <td class="cost-col">Rp <?= number_format($r['f4_harga'],0,',','.') ?></td>
        <td><?= $r['f5_rating'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="note">Sumber: Observasi website resmi platform (2025)</p>
  </div>

  <!-- TOMBOL HITUNG -->
  <div class="card center">
    <h2>Perhitungan SAW</h2>
    <p style="margin-bottom:14px;color:#555;font-size:13px;">
      Klik tombol di bawah untuk menjalankan perhitungan SAW dan mendapatkan ranking platform terbaik.
    </p>
    <button class="btn" onclick="hitungSAW()">Hitung SAW & Tampilkan Ranking</button>
    <p id="loading">Menghitung...</p>
  </div>

  <!-- HASIL -->
  <div id="hasil-container" style="display:none">

    <!-- Normalisasi -->
    <div class="card">
      <h2>Matriks Ternormalisasi (rij)</h2>
      <p style="font-size:12px;color:#666;margin-bottom:10px;">
        Benefit: rij = xij / Max(xij) &nbsp;|&nbsp; Cost: rij = Min(xij) / xij
      </p>
      <table id="tabel-norm">
        <tr>
          <th>Platform</th>
          <th>r1 (F1)</th><th>r2 (F2)</th><th>r3 (F3)</th>
          <th>r4 (F4) Cost</th><th>r5 (F5)</th>
        </tr>
      </table>
    </div>

    <!-- Hasil ranking -->
    <div class="card">
      <h2>Hasil Ranking Platform Belajar Online</h2>
      <p style="font-size:12px;color:#666;margin-bottom:10px;">
        Vi = Σ(Wj × rij) &nbsp;|&nbsp; Platform dengan Vi tertinggi = Rekomendasi Terbaik
      </p>
      <table id="tabel-hasil">
        <tr>
          <th>Rank</th>
          <th>Platform</th>
          <th>r1 × 0,2086</th>
          <th>r2 × 0,2086</th>
          <th>r3 × 0,1999</th>
          <th>r4 × 0,1945</th>
          <th>r5 × 0,1885</th>
          <th>Skor SAW (Vi)</th>
          <th>Keterangan</th>
        </tr>
      </table>
      <p class="note" id="kesimpulan"></p>
    </div>

  </div>

</div>

<script>
function hitungSAW() {
  document.getElementById('loading').style.display = 'block';
  document.getElementById('hasil-container').style.display = 'none';

  const bobot = [0.2086, 0.2086, 0.1999, 0.1945, 0.1885];

  fetch('hitung_saw.php')
    .then(r => r.json())
    .then(res => {
      document.getElementById('loading').style.display = 'none';
      document.getElementById('hasil-container').style.display = 'block';

      const data = res.data;

      // ── Tabel normalisasi ──
      const tNorm = document.getElementById('tabel-norm');
      while (tNorm.rows.length > 1) tNorm.deleteRow(1);
      data.forEach(item => {
        const tr = tNorm.insertRow();
        tr.innerHTML = `
          <td><strong>${item.nama}</strong></td>
          <td>${item.r1}</td><td>${item.r2}</td><td>${item.r3}</td>
          <td style="background:#fff3e0;color:#bf360c;font-weight:bold">${item.r4}</td>
          <td>${item.r5}</td>`;
      });

      // ── Tabel hasil ranking ──
      const tHasil = document.getElementById('tabel-hasil');
      while (tHasil.rows.length > 1) tHasil.deleteRow(1);

      data.forEach((item, i) => {
        const rank = i + 1;
        const rowClass = rank === 1 ? 'rank-1' : rank === 2 ? 'rank-2' :
                         rank === 3 ? 'rank-3' : rank === 7 ? 'rank-7' : '';
        const ket = rank === 1 ? '🥇 Rekomendasi Terbaik' :
                    rank === 2 ? '🥈 Alternatif Terbaik'  :
                    rank === 3 ? '🥉' : '';
        const tr = tHasil.insertRow();
        if (rowClass) tr.className = rowClass;
        tr.innerHTML = `
          <td><strong>${rank}</strong></td>
          <td><strong>${item.nama}</strong></td>
          <td>${(item.r1 * bobot[0]).toFixed(4)}</td>
          <td>${(item.r2 * bobot[1]).toFixed(4)}</td>
          <td>${(item.r3 * bobot[2]).toFixed(4)}</td>
          <td style="color:#bf360c">${(item.r4 * bobot[3]).toFixed(4)}</td>
          <td>${(item.r5 * bobot[4]).toFixed(4)}</td>
          <td><strong>${item.skor_saw}</strong></td>
          <td>${ket}</td>`;
      });

      document.getElementById('kesimpulan').textContent =
        `✅ Rekomendasi terbaik untuk siswa SMAN 3 Malang: ${data[0].nama} (Vi = ${data[0].skor_saw})`;
    })
    .catch(err => {
      document.getElementById('loading').textContent = 'Error: ' + err.message;
    });
}
</script>

</body>
</html>
