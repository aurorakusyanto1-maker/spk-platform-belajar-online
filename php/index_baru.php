<?php
// ================================================================
//  index.php — SPK Platform Belajar Online
//  SMAN 3 Malang | SAW + Random Forest ML
//  Semua dalam satu file: PHP (data) + HTML + CSS + JS
// ================================================================

include 'koneksi.php';

// ── Ambil data platform dari MySQL ──────────────────────────────
$res_platform = mysqli_query($conn, "SELECT * FROM platform ORDER BY id");
$platforms_db = mysqli_fetch_all($res_platform, MYSQLI_ASSOC);

// ── Ambil bobot kriteria dari MySQL ─────────────────────────────
$res_bobot = mysqli_query($conn, "SELECT * FROM kriteria ORDER BY id");
$bobot_db  = mysqli_fetch_all($res_bobot, MYSQLI_ASSOC);

// ── Hitung SAW untuk semua platform (dipakai di dashboard) ──────
$maxF1 = max(array_column($platforms_db, 'f1_kelengkapan'));
$maxF2 = max(array_column($platforms_db, 'f2_gaya_belajar'));
$maxF3 = max(array_column($platforms_db, 'f3_tryout'));
$minF4 = min(array_column($platforms_db, 'f4_harga'));
$maxF5 = max(array_column($platforms_db, 'f5_rating'));

$w = [
    'f1' => 0.2086, 'f2' => 0.2086, 'f3' => 0.1999,
    'f4' => 0.1945, 'f5' => 0.1885,
];

$platforms_saw = [];
foreach ($platforms_db as $p) {
    $r1   = $p['f1_kelengkapan']  / $maxF1;
    $r2   = $p['f2_gaya_belajar'] / $maxF2;
    $r3   = $p['f3_tryout']       / $maxF3;
    $r4   = $minF4                / $p['f4_harga'];
    $r5   = $p['f5_rating']       / $maxF5;
    $skor = ($w['f1']*$r1)+($w['f2']*$r2)+($w['f3']*$r3)+($w['f4']*$r4)+($w['f5']*$r5);
    $platforms_saw[] = array_merge($p, [
        'r1'=>round($r1,4),'r2'=>round($r2,4),'r3'=>round($r3,4),
        'r4'=>round($r4,4),'r5'=>round($r5,4),'skor_saw'=>round($skor,4),
    ]);
}
usort($platforms_saw, fn($a,$b) => $b['skor_saw'] <=> $a['skor_saw']);

// ── Proses form input rekomendasi ───────────────────────────────
$hasil_rekomendasi = null;
$profil_input      = null;
$error_api         = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nama'])) {
    $nama   = htmlspecialchars(trim($_POST['nama']));
    $kelas  = htmlspecialchars($_POST['kelas']);
    $gaya   = htmlspecialchars($_POST['gaya_belajar']);
    $tujuan = htmlspecialchars($_POST['tujuan']);
    $budget = (int) $_POST['budget'];

    $profil_input = compact('nama','kelas','gaya','tujuan','budget');

    // Simpan siswa ke database
    $gaya_esc   = mysqli_real_escape_string($conn, $gaya);
    $tujuan_esc = mysqli_real_escape_string($conn, $tujuan);
    $nama_esc   = mysqli_real_escape_string($conn, $nama);
    $kelas_esc  = mysqli_real_escape_string($conn, $kelas);

    $cek = mysqli_query($conn, "SELECT id FROM siswa WHERE nama='$nama_esc' AND kelas='$kelas_esc' LIMIT 1");
    if (mysqli_num_rows($cek) > 0) {
        $siswa_id = mysqli_fetch_assoc($cek)['id'];
    } else {
        mysqli_query($conn,
            "INSERT INTO siswa (nama, kelas, gaya_belajar, budget, tujuan, sekolah)
             VALUES ('$nama_esc','$kelas_esc','$gaya_esc',$budget,'$tujuan_esc','SMAN 3 Malang')"
        );
        $siswa_id = mysqli_insert_id($conn);
    }

    // Panggil FastAPI untuk prediksi batch
    $payload = array_map(fn($p) => [
        'nama'            => $p['nama'],
        'f1_kelengkapan'  => (float)$p['f1_kelengkapan'],
        'f2_gaya_belajar' => (float)$p['f2_gaya_belajar'],
        'f3_tryout'       => (float)$p['f3_tryout'],
        'f4_harga'        => (float)$p['f4_harga'],
        'f5_rating'       => (float)$p['f5_rating'],
    ], $platforms_db);

    $ch = curl_init("http://127.0.0.1:8000/prediksi-batch");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Kecocokan gaya belajar per platform
    $gaya_cocok = [
        'Ruangguru'  => ['visual','kinestetik'],
        'Zenius'     => ['readwrite','auditori'],
        'Quipper'    => ['kinestetik','readwrite'],
        'Sekolah.mu' => ['visual','auditori','readwrite','kinestetik'],
        'Pahamify'   => ['visual','kinestetik'],
        'Coursera'   => ['readwrite','auditori'],
        'Skolla'     => ['readwrite'],
    ];

    $hasil_hybrid = [];
    foreach ($platforms_db as $i => $row) {
        $r1 = $row['f1_kelengkapan']  / $maxF1;
        $r2 = $row['f2_gaya_belajar'] / $maxF2;
        $r3 = $row['f3_tryout']       / $maxF3;
        $r4 = $minF4                  / $row['f4_harga'];
        $r5 = $row['f5_rating']       / $maxF5;
        $skor_saw = ($w['f1']*$r1)+($w['f2']*$r2)+($w['f3']*$r3)+($w['f4']*$r4)+($w['f5']*$r5);

        // Ambil proba ML (fallback jika API mati)
        $proba_fallback = ['Ruangguru'=>0.92,'Zenius'=>0.88,'Quipper'=>0.85,
                           'Sekolah.mu'=>0.82,'Pahamify'=>0.95,'Coursera'=>0.45,'Skolla'=>0.40];
        if ($http_code === 200 && $response) {
            $api_data = json_decode($response, true);
            $proba_ml = $api_data['hasil'][$i]['probabilitas'] ?? $proba_fallback[$row['nama']] ?? 0.5;
            $label_ml = $api_data['hasil'][$i]['label'] ?? 1;
        } else {
            $proba_ml = $proba_fallback[$row['nama']] ?? 0.5;
            $label_ml = $proba_ml >= 0.7 ? 1 : 0;
            $error_api = true;
        }

        // Bonus gaya belajar — lebih signifikan agar hasil bervariasi
        $cocok = in_array($gaya, $gaya_cocok[$row['nama']] ?? []);
        $gaya_bonus = $cocok ? 1.15 : 0.90;

        // Bonus & penalti tujuan
        $tujuan_bonus = 1.0;
        if ($tujuan === 'UTBK/SNBT') {
            if ($row['f3_tryout'] >= 5)           $tujuan_bonus = 1.15;
            elseif ($row['f3_tryout'] <= 3)       $tujuan_bonus = 0.85;
        }
        if ($tujuan === 'Skill Digital') {
            if ($row['nama'] === 'Coursera')      $tujuan_bonus = 1.20;
            elseif ($row['f1_kelengkapan'] <= 3)  $tujuan_bonus = 0.85;
        }
        if ($tujuan === 'Olimpiade') {
            if ($row['f1_kelengkapan'] >= 5)      $tujuan_bonus = 1.15;
            elseif ($row['f1_kelengkapan'] <= 3)  $tujuan_bonus = 0.85;
        }
        if ($tujuan === 'Ulangan Sekolah') {
            if ($row['f3_tryout'] >= 4)           $tujuan_bonus = 1.10;
        }
        if ($tujuan === 'Pendalaman Materi') {
            if ($row['f1_kelengkapan'] >= 5)      $tujuan_bonus = 1.12;
            elseif ($row['f1_kelengkapan'] <= 3)  $tujuan_bonus = 0.88;
        }
        if ($tujuan === 'Bahasa Asing') {
            if ($row['nama'] === 'Coursera')      $tujuan_bonus = 1.18;
            elseif ($row['f2_gaya_belajar'] >= 4) $tujuan_bonus = 1.08;
        }

        // Penalti budget — lebih tegas
        $budget_ok   = $row['f4_harga'] <= $budget;
        $budget_pnlt = $budget_ok ? 1.0 : 0.65;

        $skor_hybrid = round((0.7 * $skor_saw * $gaya_bonus * $tujuan_bonus * $budget_pnlt) + (0.3 * $proba_ml), 4);

        $hasil_hybrid[] = [
            'id'          => $row['id'],
            'nama'        => $row['nama'],
            'f4_harga'    => $row['f4_harga'],
            'f5_rating'   => $row['f5_rating'],
            'r1'          => round($r1,4), 'r2'=>round($r2,4),
            'r3'          => round($r3,4), 'r4'=>round($r4,4), 'r5'=>round($r5,4),
            'skor_saw'    => round($skor_saw,4),
            'proba_ml'    => round($proba_ml,4),
            'label_ml'    => $label_ml,
            'skor_hybrid' => $skor_hybrid,
            'match_gaya'  => $cocok,
            'budget_ok'   => $budget_ok,
        ];
    }

    usort($hasil_hybrid, fn($a,$b) => $b['skor_hybrid'] <=> $a['skor_hybrid']);
    foreach ($hasil_hybrid as $rank => &$item) { $item['ranking'] = $rank + 1; }
    unset($item);

    // Simpan ke database hasil_saw
    mysqli_query($conn, "DELETE FROM hasil_saw WHERE siswa_id = $siswa_id");
    foreach ($hasil_hybrid as $item) {
        $pid   = $item['id'];
        $r1=$item['r1'];$r2=$item['r2'];$r3=$item['r3'];$r4=$item['r4'];$r5=$item['r5'];
        $skor  = $item['skor_hybrid'];
        $rank  = $item['ranking'];
        mysqli_query($conn,
            "INSERT INTO hasil_saw (siswa_id,platform_id,r1,r2,r3,r4,r5,skor_saw,ranking)
             VALUES ($siswa_id,$pid,$r1,$r2,$r3,$r4,$r5,$skor,$rank)"
        );
    }

    $hasil_rekomendasi = $hasil_hybrid;
}

// ── Data icon platform ──────────────────────────────────────────
$platform_icons = [
    'Ruangguru'=>'🎓','Zenius'=>'🔬','Quipper'=>'📖',
    'Sekolah.mu'=>'🏫','Pahamify'=>'💡','Coursera'=>'🌐','Skolla'=>'✏️',
];
$gaya_info = [
    'visual'     => ['emoji'=>'👁️','label'=>'Visual',    'color'=>'#3B82F6','bg'=>'#EFF6FF','border'=>'#BFDBFE',
                     'desc'=>'Platform dengan video animasi cocok untukmu'],
    'auditori'   => ['emoji'=>'👂','label'=>'Auditori',   'color'=>'#7C5CBF','bg'=>'#F3F0FF','border'=>'#E8E0FF',
                     'desc'=>'Platform dengan penjelasan audio cocok untukmu'],
    'readwrite'  => ['emoji'=>'✍️','label'=>'Baca Tulis', 'color'=>'#3BB87A','bg'=>'#EDFBF4','border'=>'#D4F5E5',
                     'desc'=>'Platform dengan modul teks lengkap cocok untukmu'],
    'kinestetik' => ['emoji'=>'🏃','label'=>'Kinestetik', 'color'=>'#FF8C42','bg'=>'#FFF5EE','border'=>'#FFE0CC',
                     'desc'=>'Platform dengan banyak latihan soal cocok untukmu'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BelajarPintar — SPK Platform Belajar</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&family=Fredoka+One&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --orange:#FF8C42;--orange-light:#FFE0CC;--orange-bg:#FFF5EE;
  --purple:#7C5CBF;--purple-light:#E8E0FF;--purple-bg:#F3F0FF;
  --green:#3BB87A;--green-light:#D4F5E5;--green-bg:#EDFBF4;
  --blue:#3B82F6;--blue-light:#DBEAFE;--blue-bg:#EFF6FF;
  --red:#EF4444;--red-light:#FEE2E2;
  --dark:#1E1B4B;--body:#6B7280;--light-gray:#F8F9FB;
  --white:#FFFFFF;--border:#E5E7EB;
  --shadow:0 4px 24px rgba(30,27,75,0.08);
  --shadow-sm:0 2px 8px rgba(30,27,75,0.06);
}
body{font-family:'Nunito',sans-serif;background:#F0EEFF;color:var(--dark);min-height:100vh;}

/* HEADER */
.header{
  background:var(--white);padding:14px 28px;
  display:flex;align-items:center;justify-content:space-between;
  border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100;
}
.logo{display:flex;align-items:center;gap:10px;}
.logo-icon{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#7C5CBF,#A78BFA);display:flex;align-items:center;justify-content:center;font-size:20px;}
.logo-text{font-family:'Fredoka One',cursive;font-size:20px;color:var(--dark);}
.logo-sub{font-size:11px;color:var(--body);font-weight:600;letter-spacing:0.5px;}
.nav{display:flex;gap:4px;}
.nav-btn{padding:8px 18px;border-radius:50px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .2s;font-family:'Nunito',sans-serif;color:var(--body);background:transparent;text-decoration:none;display:inline-block;}
.nav-btn.active,.nav-btn:hover{background:var(--purple);color:white;}
.user-pill{display:flex;align-items:center;gap:8px;background:var(--purple-bg);padding:6px 14px 6px 6px;border-radius:50px;}
.user-avatar{width:30px;height:30px;border-radius:50%;background:var(--purple);color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;}
.user-name{font-size:13px;font-weight:700;color:var(--purple);}

/* LAYOUT */
.main{display:flex;min-height:calc(100vh - 65px);}
.sidebar{width:220px;min-width:220px;background:white;padding:20px 14px;border-right:1px solid var(--border);display:flex;flex-direction:column;gap:4px;}
.sidebar-label{font-size:10px;font-weight:800;color:var(--body);letter-spacing:1px;text-transform:uppercase;padding:12px 10px 6px;}
.menu-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;color:var(--body);border:none;background:none;width:100%;text-align:left;text-decoration:none;transition:all .2s;}
.menu-item:hover{background:var(--light-gray);color:var(--dark);}
.menu-item.active{background:var(--purple-bg);color:var(--purple);}
.menu-icon{font-size:16px;width:20px;text-align:center;}
.menu-badge{margin-left:auto;background:var(--orange);color:white;font-size:10px;font-weight:800;padding:2px 7px;border-radius:50px;}
.content{flex:1;padding:24px;overflow-y:auto;}

/* CARDS */
.card{background:white;border-radius:20px;padding:20px;box-shadow:var(--shadow-sm);border:1px solid var(--border);}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.card-title{font-size:15px;font-weight:800;color:var(--dark);}
.card-badge{font-size:11px;font-weight:700;padding:4px 12px;border-radius:50px;}
.badge-orange{background:var(--orange-light);color:#C05500;}
.badge-purple{background:var(--purple-light);color:var(--purple);}
.badge-green{background:var(--green-light);color:#1A6B42;}
.badge-blue{background:var(--blue-light);color:#1D4ED8;}
.badge-red{background:var(--red-light);color:#991B1B;}

/* HERO */
.hero{background:linear-gradient(135deg,#7C5CBF 0%,#A78BFA 60%,#C4B5FD 100%);border-radius:24px;padding:28px 32px;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;position:relative;overflow:hidden;min-height:140px;}
.hero::before{content:'';position:absolute;top:-40px;right:180px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,0.08);}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.2);color:white;font-size:11px;font-weight:800;padding:4px 12px;border-radius:50px;margin-bottom:10px;}
.hero-title{font-family:'Fredoka One',cursive;font-size:26px;color:white;line-height:1.2;margin-bottom:8px;}
.hero-sub{font-size:13px;color:rgba(255,255,255,0.85);font-weight:600;margin-bottom:18px;max-width:340px;}
.hero-btn{background:white;color:var(--purple);border:none;padding:10px 22px;border-radius:50px;font-size:13px;font-weight:800;cursor:pointer;font-family:'Nunito',sans-serif;box-shadow:0 4px 12px rgba(0,0,0,0.15);text-decoration:none;display:inline-block;transition:transform .2s;}
.hero-btn:hover{transform:translateY(-2px);}
.hero-emojis{display:flex;align-items:center;flex-shrink:0;z-index:2;}
.hero-emoji-item{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;border:3px solid white;box-shadow:0 4px 12px rgba(0,0,0,0.15);margin-right:-10px;transition:transform .2s;cursor:default;}
.hero-emoji-item:last-child{margin-right:0;}
.hero-emoji-item:hover{transform:translateY(-6px) scale(1.1);}

/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;}
.stat-card{background:white;border-radius:18px;padding:18px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-sm);border:1px solid var(--border);transition:transform .2s;}
.stat-card:hover{transform:translateY(-2px);}
.stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.stat-icon.orange{background:var(--orange-light);}
.stat-icon.purple{background:var(--purple-light);}
.stat-icon.green{background:var(--green-light);}
.stat-icon.blue{background:var(--blue-light);}
.stat-num{font-family:'Fredoka One',cursive;font-size:26px;color:var(--dark);line-height:1;}
.stat-label{font-size:12px;font-weight:700;color:var(--body);margin-top:2px;}
.stat-trend{font-size:11px;font-weight:700;margin-top:4px;color:var(--green);}

/* PLATFORM LIST */
.platform-item{display:flex;align-items:center;gap:12px;padding:12px;border-radius:12px;margin-bottom:8px;border:1.5px solid var(--border);background:white;transition:all .2s;}
.platform-item.rank-1{border-color:var(--orange);background:var(--orange-bg);}
.platform-item.rank-2{border-color:var(--purple);background:var(--purple-bg);}
.platform-item.rank-3{border-color:var(--green);background:var(--green-bg);}
.plat-rank{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0;align-self:flex-start;}
.plat-logo{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;border:1.5px solid var(--border);background:white;}
.plat-info{flex:1;min-width:0;color:var(--dark);}
.plat-name{font-size:13px;font-weight:800;color:#1E1B4B !important;}
.plat-meta{font-size:11px;font-weight:600;color:#6B7280 !important;}
.plat-score{text-align:right;flex-shrink:0;}
.score-num{font-family:'Fredoka One',cursive;font-size:18px;}
.score-bar{height:5px;border-radius:3px;background:rgba(0,0,0,0.1);margin-top:4px;overflow:hidden;min-width:60px;}
.score-fill{height:100%;border-radius:3px;}

/* BOBOT */
.bobot-row{display:flex;gap:8px;flex-wrap:wrap;}
.bobot-chip{flex:1;min-width:80px;border-radius:12px;padding:10px 8px;text-align:center;border:1.5px solid var(--border);}
.bobot-chip-num{font-family:'Fredoka One',cursive;font-size:18px;}
.bobot-chip-label{font-size:10px;font-weight:700;color:var(--body);line-height:1.2;margin-top:2px;}
.bobot-chip-type{font-size:9px;font-weight:800;padding:2px 6px;border-radius:50px;margin-top:4px;display:inline-block;}

/* FORM */
.form-section{max-width:780px;margin:0 auto;}
.form-step{background:white;border-radius:24px;padding:28px;box-shadow:var(--shadow);border:1px solid var(--border);margin-bottom:20px;}
.step-header{display:flex;align-items:center;gap:14px;margin-bottom:24px;}
.step-bubble{width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,var(--purple),#A78BFA);color:white;font-family:'Fredoka One',cursive;font-size:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.step-title{font-size:17px;font-weight:800;color:var(--dark);}
.step-sub{font-size:12px;font-weight:600;color:var(--body);margin-top:2px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-label{font-size:12px;font-weight:800;color:var(--dark);}
.form-input,.form-select{padding:11px 14px;border-radius:12px;border:1.5px solid var(--border);background:var(--light-gray);font-family:'Nunito',sans-serif;font-size:13px;font-weight:700;color:var(--dark);transition:all .2s;outline:none;}
.form-input:focus,.form-select:focus{border-color:var(--purple);background:white;box-shadow:0 0 0 3px rgba(124,92,191,.1);}

/* GAYA SELECTOR */
.gaya-selector{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.gaya-opt{border:2px solid var(--border);border-radius:14px;padding:14px 10px;text-align:center;cursor:pointer;transition:all .2s;background:var(--light-gray);position:relative;}
.gaya-opt:hover{transform:translateY(-2px);}
.gaya-opt input{position:absolute;opacity:0;width:0;height:0;}
.gaya-opt.sel-visual{border-color:#3B82F6;background:var(--blue-bg);}
.gaya-opt.sel-auditori{border-color:var(--purple);background:var(--purple-bg);}
.gaya-opt.sel-readwrite{border-color:var(--green);background:var(--green-bg);}
.gaya-opt.sel-kinestetik{border-color:var(--orange);background:var(--orange-bg);}
.check-dot{position:absolute;top:8px;right:8px;width:18px;height:18px;border-radius:50%;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;transition:all .2s;}
.gaya-opt.sel-visual .check-dot,.gaya-opt.sel-auditori .check-dot,
.gaya-opt.sel-readwrite .check-dot,.gaya-opt.sel-kinestetik .check-dot{background:var(--green);color:white;}
.gaya-emoji{font-size:28px;margin-bottom:6px;}
.gaya-name{font-size:12px;font-weight:800;margin-bottom:2px;}
.gaya-desc{font-size:10px;font-weight:600;color:var(--body);line-height:1.3;}

/* TUJUAN */
.tujuan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.tujuan-opt{border:2px solid var(--border);border-radius:12px;padding:12px 10px;text-align:center;cursor:pointer;transition:all .2s;background:var(--light-gray);position:relative;}
.tujuan-opt:hover,.tujuan-opt.selected{border-color:var(--purple);background:var(--purple-bg);}
.tujuan-opt input{position:absolute;opacity:0;width:0;height:0;}
.tujuan-emoji{font-size:22px;margin-bottom:4px;}
.tujuan-name{font-size:12px;font-weight:800;color:var(--dark);}
.tujuan-sub{font-size:10px;font-weight:600;color:var(--body);margin-top:2px;}

/* BUDGET */
.budget-display{text-align:center;padding:12px;background:var(--purple-bg);border-radius:12px;margin-bottom:10px;}
.budget-num{font-family:'Fredoka One',cursive;font-size:22px;color:var(--purple);}
.budget-label{font-size:11px;font-weight:700;color:var(--body);}
input[type=range]{width:100%;-webkit-appearance:none;height:6px;border-radius:3px;background:linear-gradient(to right,var(--purple) var(--pct,25%),var(--border) var(--pct,25%));outline:none;cursor:pointer;}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:20px;height:20px;border-radius:50%;background:var(--purple);border:3px solid white;box-shadow:0 2px 8px rgba(124,92,191,.4);cursor:pointer;}
.budget-marks{display:flex;justify-content:space-between;font-size:10px;font-weight:700;color:var(--body);margin-top:4px;}

/* SUBMIT */
.submit-btn{background:linear-gradient(135deg,#7C5CBF,#A78BFA);color:white;border:none;padding:16px 48px;border-radius:50px;font-size:16px;font-weight:800;cursor:pointer;font-family:'Nunito',sans-serif;box-shadow:0 6px 20px rgba(124,92,191,.35);transition:all .25s;display:inline-flex;align-items:center;gap:10px;}
.submit-btn:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(124,92,191,.45);}

/* HASIL */
.rek-hero{background:linear-gradient(135deg,#3BB87A,#34D399);border-radius:24px;padding:24px 28px;margin-bottom:20px;display:flex;align-items:center;gap:20px;box-shadow:0 8px 24px rgba(59,184,122,.25);}
.rek-trophy{font-size:52px;flex-shrink:0;}
.rek-label{font-size:11px;font-weight:800;color:rgba(255,255,255,.8);letter-spacing:1px;margin-bottom:4px;}
.rek-name{font-family:'Fredoka One',cursive;font-size:30px;color:white;line-height:1;}
.rek-sub{font-size:13px;color:rgba(255,255,255,.9);font-weight:600;margin-top:6px;}
.rek-score{margin-left:auto;text-align:center;flex-shrink:0;background:rgba(255,255,255,.2);border-radius:16px;padding:14px 20px;}
.rek-score-num{font-family:'Fredoka One',cursive;font-size:34px;color:white;line-height:1;}
.rek-score-label{font-size:11px;font-weight:800;color:rgba(255,255,255,.8);margin-top:2px;}

.match-banner{border-radius:16px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;border:2px solid;}
.match-icon{font-size:30px;flex-shrink:0;}
.match-title{font-size:14px;font-weight:800;}
.match-desc{font-size:12px;font-weight:600;margin-top:2px;opacity:.85;}
.match-pct{margin-left:auto;font-family:'Fredoka One',cursive;font-size:28px;flex-shrink:0;}

.hasil-grid{display:grid;grid-template-columns:1fr 310px;gap:18px;margin-bottom:20px;}
.profil-recap{background:var(--purple-bg);border-radius:14px;padding:14px;margin-bottom:14px;}
.profil-recap-title{font-size:12px;font-weight:800;color:var(--purple);margin-bottom:10px;}
.profil-item{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px;}
.profil-text{font-weight:700;color:var(--dark);}
.profil-val{font-weight:600;color:var(--body);margin-left:auto;}

/* TABEL */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;font-weight:600;}
thead tr{background:#F3F0FF;}
th{padding:9px 10px;font-weight:800;font-size:11px;text-align:center;}
th:first-child{text-align:left;border-radius:10px 0 0 0;}
th:last-child{border-radius:0 10px 0 0;}
td{padding:9px 10px;text-align:center;border-bottom:1px solid #F0EEFF;}
td:first-child{text-align:left;font-weight:800;}
tr:last-child td{border-bottom:none;}

/* API WARNING */
.api-warn{background:#FEF3C7;border:1.5px solid #FCD34D;border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:12px;font-weight:700;color:#92400E;display:flex;align-items:center;gap:8px;}

/* ANIMASI */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.a1{animation:fadeUp .4s ease both;}
.a2{animation:fadeUp .4s .08s ease both;}
.a3{animation:fadeUp .4s .16s ease both;}
.a4{animation:fadeUp .4s .24s ease both;}

/* LOADING OVERLAY */
.loading-overlay{position:fixed;inset:0;background:rgba(30,27,75,.55);display:none;align-items:center;justify-content:center;z-index:999;backdrop-filter:blur(4px);}
.loading-card{background:white;border-radius:24px;padding:36px 48px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.loading-emoji{font-size:48px;margin-bottom:14px;}
.loading-text{font-size:16px;font-weight:800;color:var(--dark);margin-bottom:6px;}
.loading-sub{font-size:12px;font-weight:600;color:var(--body);}
.dot-loader{display:flex;gap:6px;justify-content:center;margin-top:14px;}
.dot{width:8px;height:8px;border-radius:50%;background:var(--purple);}
.dot:nth-child(1){animation:bounce .6s ease infinite;}
.dot:nth-child(2){animation:bounce .6s .1s ease infinite;}
.dot:nth-child(3){animation:bounce .6s .2s ease infinite;}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}

/* POPUP MODAL */
.popup-overlay{position:fixed;inset:0;background:rgba(30,27,75,.6);z-index:998;display:none;align-items:center;justify-content:center;backdrop-filter:blur(6px);padding:20px;}
.popup-overlay.show{display:flex;}
.popup-modal{background:white;border-radius:24px;width:100%;max-width:520px;max-height:88vh;overflow-y:auto;box-shadow:0 24px 64px rgba(30,27,75,.25);animation:popIn .3s cubic-bezier(.34,1.56,.64,1) both;}
@keyframes popIn{from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}
.popup-header{padding:20px 20px 16px;border-bottom:1px solid var(--border);}
.popup-top{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
.popup-icon{width:52px;height:52px;border-radius:16px;border:2px solid var(--border);background:var(--light-gray);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;}
.popup-nama{font-family:'Fredoka One',cursive;font-size:22px;color:var(--dark);}
.popup-tag{font-size:10px;font-weight:800;padding:3px 10px;border-radius:50px;margin-top:4px;display:inline-block;}
.popup-close{margin-left:auto;width:32px;height:32px;border-radius:50%;border:none;background:var(--light-gray);color:var(--body);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s;}
.popup-close:hover{background:var(--red-light);color:var(--red);}
.popup-scores{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.popup-score-box{border-radius:12px;padding:10px;text-align:center;}
.popup-score-val{font-family:'Fredoka One',cursive;font-size:20px;}
.popup-score-lbl{font-size:10px;font-weight:700;color:var(--body);margin-top:2px;}
.popup-body{padding:16px 20px;}
.popup-section{margin-bottom:14px;}
.popup-section-title{font-size:12px;font-weight:800;color:var(--dark);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.popup-pros-cons{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.popup-list{list-style:none;padding:0;}
.popup-list li{font-size:12px;font-weight:600;color:var(--body);padding:5px 0;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:6px;line-height:1.4;}
.popup-list li:last-child{border-bottom:none;}
.popup-cocok-box{background:var(--purple-bg);border-radius:12px;padding:12px;font-size:12px;font-weight:600;color:var(--dark);line-height:1.5;}
.popup-footer{padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.popup-visit-btn{background:var(--purple);color:white;border:none;padding:10px 20px;border-radius:50px;font-size:13px;font-weight:800;cursor:pointer;font-family:'Nunito',sans-serif;transition:all .2s;text-decoration:none;display:inline-block;}
.popup-visit-btn:hover{background:#6B4FAF;transform:translateY(-1px);}
.popup-rank-badge{font-size:12px;font-weight:700;color:var(--body);}

</style>
</head>
<body>

<!-- LOADING OVERLAY -->
<div class="loading-overlay" id="loading-overlay">
  <div class="loading-card">
    <div class="loading-emoji">🧠</div>
    <div class="loading-text">Menganalisis Profilmu...</div>
    <div class="loading-sub">SAW + Machine Learning sedang bekerja</div>
    <div class="dot-loader">
      <div class="dot"></div><div class="dot"></div><div class="dot"></div>
    </div>
  </div>
</div>


<!-- POPUP MODAL -->
<div class="popup-overlay" id="popup-overlay" onclick="closePopupOutside(event)">
  <div class="popup-modal" id="popup-modal">
    <div class="popup-header">
      <div class="popup-top">
        <div class="popup-icon" id="pop-icon">💡</div>
        <div>
          <div class="popup-nama" id="pop-nama">—</div>
          <span class="popup-tag" id="pop-tag"></span>
        </div>
        <button class="popup-close" onclick="closePopup()">✕</button>
      </div>
      <div class="popup-scores">
        <div class="popup-score-box" style="background:var(--green-bg);">
          <div class="popup-score-val" style="color:var(--green);" id="pop-hybrid">—</div>
          <div class="popup-score-lbl">Skor Hybrid</div>
        </div>
        <div class="popup-score-box" style="background:var(--blue-bg);">
          <div class="popup-score-val" style="color:var(--blue);" id="pop-saw">—</div>
          <div class="popup-score-lbl">Skor SAW</div>
        </div>
        <div class="popup-score-box" style="background:var(--purple-bg);">
          <div class="popup-score-val" style="color:var(--purple);" id="pop-ml">—</div>
          <div class="popup-score-lbl">Proba ML</div>
        </div>
      </div>
    </div>
    <div class="popup-body">

      <!-- Kecocokan gaya belajar -->
      <div class="popup-section" id="pop-gaya-section">
        <div class="popup-section-title" id="pop-gaya-title">🧠 Kecocokan Gaya Belajar</div>
        <div id="pop-gaya-content"></div>
      </div>

      <!-- Budget -->
      <div class="popup-section" id="pop-budget-section" style="display:none;">
        <div class="popup-section-title" style="color:var(--red);">⚠️ Perhatian Budget</div>
        <div style="background:var(--red-light);border-radius:10px;padding:10px 12px;font-size:12px;font-weight:600;color:#991B1B;">
          Harga platform ini melebihi budget kamu. Pertimbangkan platform lain yang lebih sesuai, atau sesuaikan budget.
        </div>
      </div>

      <!-- Pros & Cons -->
      <div class="popup-section">
        <div class="popup-pros-cons">
          <div>
            <div class="popup-section-title" style="color:var(--green);">✅ Keunggulan</div>
            <ul class="popup-list" id="pop-pros"></ul>
          </div>
          <div>
            <div class="popup-section-title" style="color:var(--red);">⚠️ Kekurangan</div>
            <ul class="popup-list" id="pop-cons"></ul>
          </div>
        </div>
      </div>

      <!-- Cocok untuk siapa -->
      <div class="popup-section">
        <div class="popup-section-title">🎯 Cocok untuk Siapa?</div>
        <div class="popup-cocok-box" id="pop-cocok"></div>
      </div>

      <!-- Harga -->
      <div class="popup-section">
        <div class="popup-section-title">💰 Harga Berlangganan</div>
        <div style="background:var(--orange-bg);border-radius:12px;padding:12px;display:flex;align-items:center;justify-content:space-between;">
          <span style="font-size:12px;font-weight:700;color:var(--body);">Per bulan</span>
          <span style="font-family:'Fredoka One',cursive;font-size:20px;color:var(--orange);" id="pop-harga">—</span>
        </div>
      </div>

    </div>
    <div class="popup-footer">
      <a class="popup-visit-btn" id="pop-visit" href="#" target="_blank">🌐 Kunjungi Website</a>
      <span class="popup-rank-badge" id="pop-rank"></span>
    </div>
  </div>
</div>

<!-- HEADER -->
<div class="header">
  <div class="logo">
    <div class="logo-icon">📚</div>
    <div>
      <div class="logo-text">BelajarPintar</div>
      <div class="logo-sub">SPK Platform Belajar</div>
    </div>
  </div>
  <nav class="nav">
    <a class="nav-btn <?= !isset($_GET['page']) || $_GET['page']==='dashboard' ? 'active' : '' ?>"
       href="?page=dashboard">Dashboard</a>
    <a class="nav-btn <?= isset($_GET['page']) && $_GET['page']==='rekomendasi' ? 'active' : '' ?>"
       href="?page=rekomendasi">Rekomendasi</a>
    <a class="nav-btn <?= isset($_GET['page']) && $_GET['page']==='platform' ? 'active' : '' ?>"
       href="?page=platform">Platform</a>
    <a class="nav-btn <?= isset($_GET['page']) && $_GET['page']==='tentang' ? 'active' : '' ?>"
       href="?page=tentang">Tentang</a>
  </nav>
  <div class="user-pill">
    <div class="user-avatar">
      <?php
        if ($profil_input) {
          $words = explode(' ', $profil_input['nama']);
          echo strtoupper(implode('', array_map(fn($w)=>$w[0], array_slice($words,0,2))));
        } else { echo '?'; }
      ?>
    </div>
    <span class="user-name">
      <?= $profil_input ? htmlspecialchars(explode(' ',$profil_input['nama'])[0]).'.': 'Tamu' ?>
    </span>
  </div>
</div>

<!-- MAIN -->
<div class="main">

  <!-- SIDEBAR -->
  <div class="sidebar">
    <div class="sidebar-label">Menu Utama</div>
    <a class="menu-item <?= !isset($_GET['page'])||$_GET['page']==='dashboard'?'active':'' ?>" href="?page=dashboard">
      <span class="menu-icon">🏠</span>Dashboard
    </a>
    <a class="menu-item <?= isset($_GET['page'])&&$_GET['page']==='rekomendasi'?'active':'' ?>" href="?page=rekomendasi">
      <span class="menu-icon">🎯</span>Rekomendasi
    </a>
    <?php if($hasil_rekomendasi): ?>
    <a class="menu-item <?= isset($_GET['page'])&&$_GET['page']==='hasil'?'active':'' ?>" href="?page=hasil">
      <span class="menu-icon">📊</span>Hasil SAW
      <span class="menu-badge">Baru</span>
    </a>
    <?php endif; ?>
    <a class="menu-item <?= isset($_GET['page'])&&$_GET['page']==='platform'?'active':'' ?>" href="?page=platform">
      <span class="menu-icon">💻</span>Data Platform
    </a>
    <div class="sidebar-label">Info</div>
    <a class="menu-item <?= isset($_GET['page'])&&$_GET['page']==='tentang'?'active':'' ?>" href="?page=tentang">
      <span class="menu-icon">⚖️</span>Bobot Kriteria
    </a>
  </div>

  <!-- CONTENT -->
  <div class="content">
  <?php
  $page = $_GET['page'] ?? 'dashboard';
  // Redirect ke hasil setelah POST
  if ($_SERVER['REQUEST_METHOD']==='POST' && $hasil_rekomendasi) $page = 'hasil';

  // ════════════════════════════════════════════════
  // PAGE: DASHBOARD
  // ════════════════════════════════════════════════
  if ($page === 'dashboard'): ?>

    <div class="hero a1">
      <div>
  
        <div class="hero-title">Temukan Platform<br>Belajarmu!</div>
        <div class="hero-sub">Sistem cerdas SAW + ML yang merekomendasikan platform belajar paling sesuai gaya belajar dan kebutuhanmu.</div>
        <a class="hero-btn" href="?page=rekomendasi">Mulai Rekomendasi →</a>
      </div>
      <div class="hero-emojis">
        <div class="hero-emoji-item" style="background:#DBEAFE;">🧑‍💻</div>
        <div class="hero-emoji-item" style="background:#E8E0FF;">🧕</div>
        <div class="hero-emoji-item" style="background:#D4F5E5;">👩‍🎓</div>
        <div class="hero-emoji-item" style="background:#FFE0CC;">🧑‍🎒</div>
      </div>
    </div>

    <div class="stats-row a2">
      <div class="stat-card">
        <div class="stat-icon orange">👥</div>
        <div><div class="stat-num">90</div><div class="stat-label">Responden Kuesioner</div><div class="stat-trend">↑ SMAN 3 Malang</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple">💻</div>
        <div><div class="stat-num"><?= count($platforms_db) ?></div><div class="stat-label">Platform Dievaluasi</div><div class="stat-trend">↑ Data Riil 2025</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green">⚖️</div>
        <div><div class="stat-num">5</div><div class="stat-label">Kriteria SAW</div><div class="stat-trend">↑ Benefit + Cost</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue">🤖</div>
        <div><div class="stat-num">100%</div><div class="stat-label">Akurasi Model ML</div><div class="stat-trend">↑ Random Forest</div></div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;" class="a3">
      <!-- Ranking platform dari DB -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">🏆 Ranking Platform (SAW)</div>
          <span class="card-badge badge-orange">Data Realtime DB</span>
        </div>
        <?php
        $rank_colors = [
          ['rank-1','background:#FF8C42;color:white;','var(--orange)'],
          ['rank-2','background:#7C5CBF;color:white;','var(--purple)'],
          ['rank-3','background:#3BB87A;color:white;','var(--green)'],
        ];
        $rank_icons = ['🥇','🥈','🥉'];
        foreach ($platforms_saw as $i => $p):
          $rc  = $i < 3 ? $rank_colors[$i] : ['','background:var(--light-gray);color:var(--body);','var(--body)'];
          $pct = round($p['skor_saw'] * 100);
          $icon = $platform_icons[$p['nama']] ?? '💻';
        ?>
        <div class="platform-item <?= $rc[0] ?>">
          <div class="plat-rank" style="<?= $rc[1] ?>"><?= $i<3 ? $rank_icons[$i] : $i+1 ?></div>
          <div class="plat-logo"><?= $icon ?></div>
          <div class="plat-info">
            <div class="plat-name"><?= htmlspecialchars($p['nama']) ?></div>
            <div class="plat-meta">Rp <?= number_format($p['f4_harga']/1000,0) ?>rb/bln · ⭐<?= $p['f5_rating'] ?></div>
          </div>
          <div class="plat-score">
            <div class="score-num" style="color:<?= $rc[2] ?>;"><?= $p['skor_saw'] ?></div>
            <div style="font-size:10px;font-weight:700;color:var(--body);">skor Vi</div>
            <div class="score-bar"><div class="score-fill" style="width:<?= $pct ?>%;background:<?= $rc[2] ?>;"></div></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Bobot kriteria -->
      <div>
        <div class="card" style="margin-bottom:14px;">
          <div class="card-header">
            <div class="card-title">⚖️ Bobot Kriteria</div>
            <span class="card-badge badge-purple">90 Responden</span>
          </div>
          <div class="bobot-row">
            <?php
            $bobot_colors = ['var(--blue)','var(--purple)','var(--green)','var(--orange)','var(--blue)'];
            foreach ($bobot_db as $bi => $b):
              $bc = $bobot_colors[$bi];
            ?>
            <div class="bobot-chip" style="border-color:<?= $bc ?>30;background:<?= $bc ?>10;">
              <div class="bobot-chip-num" style="color:<?= $bc ?>;"><?= number_format($b['bobot'],4) ?></div>
              <div class="bobot-chip-label"><?= htmlspecialchars($b['nama']) ?></div>
              <div class="bobot-chip-type" style="background:<?= $bc ?>20;color:<?= $bc ?>;"><?= $b['tipe'] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="font-size:11px;font-weight:700;color:var(--body);text-align:center;margin-top:8px;">
            Sumber: Kuesioner riil siswa SMAN 3 Malang
          </div>
        </div>

        <!-- Gaya belajar -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">🧠 Gaya Belajar Siswa</div>
            <span class="card-badge badge-green">VARK</span>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <?php
            $gayas = [
              ['👁️','Visual',   28,'#1D4ED8','#3B82F6','var(--blue-bg)', 75],
              ['👂','Auditori',  22,'#5B21B6','#7C5CBF','var(--purple-bg)',58],
              ['✍️','Baca Tulis',18,'#065F46','#3BB87A','var(--green-bg)', 48],
              ['🏃','Kinestetik',22,'#9A3412','#FF8C42','var(--orange-bg)',58],
            ];
            foreach ($gayas as $g): ?>
            <div style="background:<?=$g[5]?>;border-radius:12px;padding:12px;text-align:center;">
              <div style="font-size:22px;"><?=$g[0]?></div>
              <div style="font-size:12px;font-weight:800;color:<?=$g[3]?>;margin-top:4px;"><?=$g[1]?></div>
              <div style="font-family:'Fredoka One',cursive;font-size:20px;color:<?=$g[4]?>;"><?=$g[2]?></div>
              <div style="font-size:10px;font-weight:600;color:var(--body);">siswa</div>
              <div style="height:4px;background:rgba(0,0,0,.1);border-radius:2px;margin-top:6px;">
                <div style="height:100%;width:<?=$g[6]?>%;background:<?=$g[4]?>;border-radius:2px;"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

  <?php
  // ════════════════════════════════════════════════
  // PAGE: REKOMENDASI (FORM)
  // ════════════════════════════════════════════════
  elseif ($page === 'rekomendasi'): ?>

    <div class="a1" style="margin-bottom:20px;">
      <div style="font-family:'Fredoka One',cursive;font-size:24px;color:var(--dark);margin-bottom:4px;">🎯 Isi Profilmu Dulu</div>
      <div style="font-size:13px;font-weight:600;color:var(--body);">Sistem akan merekomendasikan platform paling cocok berdasarkan profil dan gaya belajarmu.</div>
    </div>

    <form method="POST" action="?page=hasil" onsubmit="showLoading()" class="form-section">

      <!-- STEP 1: Data Diri -->
      <div class="form-step a2">
        <div class="step-header">
          <div class="step-bubble">1</div>
          <div><div class="step-title">Data Diri</div><div class="step-sub">Isi informasi dasar tentang kamu</div></div>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">👤 Nama Lengkap</label>
            <input class="form-input" type="text" name="nama" placeholder="Nama kamu..." required>
          </div>
          <div class="form-group">
            <label class="form-label">🏫 Kelas</label>
            <select class="form-select" name="kelas" required>
              <option value="">-- Pilih Kelas --</option>
              <option value="X">Kelas X</option>
              <option value="XI">Kelas XI</option>
              <option value="XII">Kelas XII</option>
            </select>
          </div>
        </div>
      </div>

      <!-- STEP 2: Tujuan -->
      <div class="form-step a2">
        <div class="step-header">
          <div class="step-bubble">2</div>
          <div><div class="step-title">Tujuan Belajar</div><div class="step-sub">Pilih satu tujuan utama kamu</div></div>
        </div>
        <div class="tujuan-grid">
          <?php
          $tujuan_list = [
            ['UTBK/SNBT','🎓','UTBK / SNBT','Persiapan masuk PTN'],
            ['Ulangan Sekolah','📝','Ulangan Sekolah','UTS, UAS, PAS'],
            ['Pendalaman Materi','📚','Pendalaman Materi','Belajar mandiri'],
            ['Olimpiade','🏆','Olimpiade','KSN, OSN, dll'],
            ['Skill Digital','💡','Skill Digital','Coding, desain, dll'],
            ['Bahasa Asing','🌐','Bahasa Asing','Inggris, Jepang, dll'],
          ];
          foreach ($tujuan_list as $t): ?>
          <label class="tujuan-opt" onclick="selectTujuan(this)">
            <input type="radio" name="tujuan" value="<?= $t[0] ?>" required>
            <div class="tujuan-emoji"><?= $t[1] ?></div>
            <div class="tujuan-name"><?= $t[2] ?></div>
            <div class="tujuan-sub"><?= $t[3] ?></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- STEP 3: Gaya Belajar -->
      <div class="form-step a3">
        <div class="step-header">
          <div class="step-bubble">3</div>
          <div><div class="step-title">Gaya Belajar (VARK)</div><div class="step-sub">Pilih cara belajar yang paling sesuai denganmu</div></div>
        </div>
        <div class="gaya-selector">
          <?php
          $gaya_opts = [
            ['visual',    '👁️','Visual',    '#1D4ED8','Suka belajar lewat video, gambar, dan diagram'],
            ['auditori',  '👂','Auditori',  '#5B21B6','Suka mendengarkan penjelasan dan podcast'],
            ['readwrite', '✍️','Baca Tulis','#065F46','Suka membaca modul dan mencatat materi'],
            ['kinestetik','🏃','Kinestetik','#9A3412','Suka latihan soal langsung dan tryout'],
          ];
          foreach ($gaya_opts as $g): ?>
          <label class="gaya-opt" id="opt-<?= $g[0] ?>" onclick="selectGaya('<?= $g[0] ?>')">
            <input type="radio" name="gaya_belajar" value="<?= $g[0] ?>" required>
            <div class="check-dot" id="dot-<?= $g[0] ?>">✓</div>
            <div class="gaya-emoji"><?= $g[1] ?></div>
            <div class="gaya-name" style="color:<?= $g[3] ?>;"><?= $g[2] ?></div>
            <div class="gaya-desc"><?= $g[4] ?></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- STEP 4: Budget -->
      <div class="form-step a4">
        <div class="step-header">
          <div class="step-bubble">4</div>
          <div><div class="step-title">Budget per Bulan</div><div class="step-sub">Geser slider untuk menyesuaikan budget berlangganan</div></div>
        </div>
        <div class="budget-display">
          <div class="budget-num" id="budget-display">Rp 100.000</div>
          <div class="budget-label">Budget maksimal per bulan</div>
        </div>
        <input type="range" id="budget-slider" name="budget" min="50000" max="300000"
               step="10000" value="100000" oninput="updateBudget(this.value)" style="--pct:25%">
        <div class="budget-marks">
          <span>Rp 50rb</span><span>Rp 100rb</span><span>Rp 150rb</span><span>Rp 200rb</span><span>Rp 300rb</span>
        </div>
      </div>

      <!-- SUBMIT -->
      <div style="text-align:center;padding:10px 0;">
        <button type="submit" class="submit-btn">
          <span>🔍</span> Cari Rekomendasi Untukku
        </button>
        <div style="margin-top:10px;font-size:11px;font-weight:700;color:var(--body);">
          Sistem menganalisis menggunakan SAW + Random Forest ML
        </div>
      </div>

    </form>

  <?php
  // ════════════════════════════════════════════════
  // PAGE: HASIL
  // ════════════════════════════════════════════════
  elseif ($page === 'hasil' && $hasil_rekomendasi):
    $best = $hasil_rekomendasi[0];
    $gi   = $gaya_info[$profil_input['gaya']] ?? $gaya_info['visual'];
    $match_pct = $best['match_gaya'] ? 95 : 72;
  ?>

    <!-- Tombol ubah profil -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;" class="a1">
      <div>
        <div style="font-family:'Fredoka One',cursive;font-size:22px;color:var(--dark);">📊 Hasil Rekomendasi</div>
        <div style="font-size:12px;font-weight:600;color:var(--body);">
          Halo <?= htmlspecialchars(explode(' ',$profil_input['nama'])[0]) ?>! Ini platform terbaik untukmu
        </div>
      </div>
      <a href="?page=rekomendasi" style="background:white;color:var(--purple);border:2px solid var(--purple);padding:10px 20px;border-radius:50px;font-size:13px;font-weight:800;text-decoration:none;transition:all .2s;">
        ← Ubah Profil
      </a>
    </div>

    <?php if ($error_api): ?>
    <div class="api-warn a1">
      ⚠️ FastAPI tidak aktif — menggunakan data probabilitas ML offline. Jalankan <code>uvicorn api_ml:app --reload --port 8000</code> untuk hasil lebih akurat.
    </div>
    <?php endif; ?>

    <!-- Hero rekomendasi terbaik -->
    <div class="rek-hero a2">
      <div class="rek-trophy">🏆</div>
      <div>
        <div class="rek-label">REKOMENDASI TERBAIK UNTUKMU</div>
        <div class="rek-name">
          <?= $platform_icons[$best['nama']] ?? '💻' ?> <?= htmlspecialchars($best['nama']) ?>
        </div>
        <div class="rek-sub">
          Rp <?= number_format($best['f4_harga']/1000,0) ?>rb/bln · ⭐<?= $best['f5_rating'] ?>/5 ·
          <?= $best['match_gaya'] ? '✓ Cocok dengan gaya belajar '.$gi['label'] : 'Rekomendasi sistem' ?>
        </div>
      </div>
      <div class="rek-score">
        <div class="rek-score-num"><?= $best['skor_hybrid'] ?></div>
        <div class="rek-score-label">Skor Hybrid</div>
      </div>
    </div>

    <!-- Banner kecocokan gaya belajar -->
    <div class="match-banner a3" style="background:<?= $gi['bg'] ?>;border-color:<?= $gi['border'] ?>;">
      <div class="match-icon"><?= $gi['emoji'] ?></div>
      <div>
        <div class="match-title">Kecocokan dengan Gaya Belajar <?= $gi['label'] ?></div>
        <div class="match-desc">
          <strong><?= htmlspecialchars($best['nama']) ?></strong>
          <?= $best['match_gaya'] ? 'sangat cocok' : 'cukup cocok' ?>
          untuk kamu yang bergaya belajar <strong><?= $gi['label'] ?></strong>.
          <?= $gi['desc'] ?>.
        </div>
      </div>
      <div class="match-pct" style="color:<?= $gi['color'] ?>;"><?= $match_pct ?>%</div>
    </div>

    <!-- Grid ranking + profil -->
    <div class="hasil-grid a3">

      <!-- Ranking semua platform -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">🏆 Ranking Semua Platform</div>
          <span class="card-badge badge-orange">SAW + ML Hybrid</span>
        </div>

        <?php
        $platform_detail = [
          'Ruangguru'  => ['url'=>'ruangguru.com',  'tag'=>'Terpopuler', 'tagcolor'=>'#FF8C42',
            'pros'=>['Materi terlengkap & terstruktur','Video animasi berkualitas tinggi','Tryout UTBK paling lengkap','Bimbingan guru berpengalaman'],
            'cons'=>['Harga paling mahal','Fitur premium terbatas di paket dasar'],
            'cocok'=>'Siswa yang serius persiapan UTBK dan tidak keberatan dengan harga premium.'],
          'Zenius'     => ['url'=>'zenius.net',     'tag'=>'Konseptual', 'tagcolor'=>'#7C5CBF',
            'pros'=>['Penjelasan konsep sangat mendalam','Cocok untuk auditori & baca tulis','Konten berkualitas tinggi','Harga relatif wajar'],
            'cons'=>['Tampilan kurang modern','Tryout tidak selengkap Ruangguru'],
            'cocok'=>'Siswa yang ingin benar-benar paham konsep, bukan sekadar hafal rumus.'],
          'Quipper'    => ['url'=>'quipper.com',    'tag'=>'Terjangkau', 'tagcolor'=>'#3BB87A',
            'pros'=>['Harga kompetitif','Soal latihan sangat banyak','Kurikulum sesuai sekolah','Cocok untuk ulangan harian'],
            'cons'=>['Fitur lebih sederhana','Video tidak sebanyak Ruangguru'],
            'cocok'=>'Siswa yang butuh latihan soal banyak dengan budget terbatas.'],
          'Sekolah.mu' => ['url'=>'sekolah.mu',    'tag'=>'Multistyle', 'tagcolor'=>'#3B82F6',
            'pros'=>['Mendukung semua gaya belajar','Proyek berbasis kompetensi','Konten beragam & kreatif','Komunitas aktif'],
            'cons'=>['Tryout kurang lengkap','Fokus lebih ke skill umum'],
            'cocok'=>'Siswa yang ingin belajar dengan cara yang beragam dan tidak monoton.'],
          'Pahamify'   => ['url'=>'pahamify.com',  'tag'=>'Best Value',  'tagcolor'=>'#3BB87A',
            'pros'=>['Harga paling terjangkau','Tryout lengkap & terstruktur','Animasi menarik & gamifikasi','Cocok untuk visual & kinestetik'],
            'cons'=>['Konten tidak selengkap Ruangguru','Support terbatas'],
            'cocok'=>'Siswa yang ingin platform lengkap dengan budget minimal, terutama untuk UTBK.'],
          'Coursera'   => ['url'=>'coursera.org',  'tag'=>'Internasional','tagcolor'=>'#1D4ED8',
            'pros'=>['Konten dari universitas top dunia','Sertifikat diakui internasional','Skill digital & bahasa lengkap','Kualitas konten sangat tinggi'],
            'cons'=>['Harga sangat mahal','Kurang relevan untuk kurikulum SMA','Bahasa pengantar Inggris'],
            'cocok'=>'Siswa yang ingin belajar skill digital atau bahasa asing untuk persiapan kuliah/karir.'],
          'Skolla'     => ['url'=>'skolla.id',     'tag'=>'Entry Level', 'tagcolor'=>'#6B7280',
            'pros'=>['Harga paling murah','Cocok untuk pemula','Ringan diakses','Tidak perlu komitmen besar'],
            'cons'=>['Fitur paling terbatas','Konten tidak selengkap platform lain','Rating lebih rendah'],
            'cocok'=>'Siswa yang baru mulai belajar online dan ingin mencoba dengan biaya minimal.'],
        ];

        $rank_icons2 = ['🥇','🥈','🥉'];
        $rank_colors2 = [
          ['rank-1','background:#FF8C42;color:white;','var(--orange)'],
          ['rank-2','background:#7C5CBF;color:white;','var(--purple)'],
          ['rank-3','background:#3BB87A;color:white;','var(--green)'],
        ];
        foreach ($hasil_rekomendasi as $i => $p) {
          $rc   = $i < 3 ? $rank_colors2[$i] : ['','background:var(--light-gray);color:var(--body);','var(--body)'];
          $pct2 = round($p['skor_hybrid'] * 100);
          $icon = $platform_icons[$p['nama']] ?? '💻';
          $m_tag = $p['match_gaya']
            ? "<span style='font-size:10px;font-weight:800;padding:2px 8px;border-radius:50px;background:{$gi['bg']};color:{$gi['color']};margin-top:3px;display:inline-block;'>{$gi['emoji']} Cocok {$gi['label']}</span>"
            : "<span style='font-size:10px;font-weight:700;padding:2px 8px;border-radius:50px;background:var(--light-gray);color:var(--body);margin-top:3px;display:inline-block;'>Gaya lain</span>";
          $over = !$p['budget_ok']
            ? "<span style='font-size:9px;font-weight:800;color:var(--red);'> · ⚠️ Melebihi budget</span>" : '';


          $detail = $platform_detail[$p['nama']] ?? null;
          $pros_json   = $detail ? json_encode($detail['pros'],   JSON_UNESCAPED_UNICODE) : '[]';
          $cons_json   = $detail ? json_encode($detail['cons'],   JSON_UNESCAPED_UNICODE) : '[]';
          $cocok_str   = $detail ? htmlspecialchars($detail['cocok'])   : '-';
          $tag_str     = $detail ? htmlspecialchars($detail['tag'])     : '';
          $tagcolor    = $detail ? $detail['tagcolor'] : '#888';
          $url_str     = $detail ? $detail['url'] : '#';
        ?>
        <?php
          $popup_data = json_encode([
            'nama'     => $p['nama'],
            'icon'     => $icon,
            'hybrid'   => $p['skor_hybrid'],
            'saw'      => $p['skor_saw'],
            'ml'       => $p['proba_ml'],
            'harga'    => 'Rp ' . number_format($p['f4_harga'],0,',','.'),
            'ranking'  => $p['ranking'],
            'matchGaya'=> (bool)$p['match_gaya'],
            'gayaLabel'=> $gi['label'],
            'gayaEmoji'=> $gi['emoji'],
            'gayaColor'=> $gi['color'],
            'pros'     => $detail ? $detail['pros'] : [],
            'cons'     => $detail ? $detail['cons'] : [],
            'cocok'    => $detail ? $detail['cocok'] : '-',
            'tag'      => $detail ? $detail['tag'] : '',
            'tagColor' => $detail ? $detail['tagcolor'] : '#888',
            'url'      => $detail ? $detail['url'] : '#',
            'overBudget'=> !(bool)$p['budget_ok'],
          ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <div class="platform-item <?= $rc[0] ?>" style="cursor:pointer;"
          data-popup='<?= $popup_data ?>'
          onclick="showPopupFromEl(this)">
          <div class="plat-rank" style="<?= $rc[1] ?>"><?= $i<3 ? $rank_icons2[$i] : $i+1 ?></div>
          <div class="plat-logo"><?= $icon ?></div>
          <div class="plat-info">
            <div class="plat-name"><?= htmlspecialchars($p['nama']) ?></div>
            <div class="plat-meta">Rp <?= number_format($p['f4_harga']/1000,0) ?>rb/bln · ⭐<?= $p['f5_rating'] ?><?= $over ?></div>
            <?= $m_tag ?>
          </div>
          <div class="plat-score">
            <div class="score-num" style="color:<?= $rc[2] ?>;"><?= $p['skor_hybrid'] ?></div>
            <div style="font-size:10px;font-weight:700;color:var(--body);">hybrid</div>
            <div class="score-bar"><div class="score-fill" style="width:<?= $pct2 ?>%;background:<?= $rc[2] ?>;"></div></div>
          </div>
          <div style="font-size:10px;color:var(--body);margin-left:4px;opacity:0.6;">›</div>
        </div>
        <?php } ?>
      </div>

      <!-- Profil + ML insight -->
      <div>
        <!-- Profil recap -->
        <div class="card" style="margin-bottom:14px;">
          <div class="card-header">
            <div class="card-title">👤 Profil Kamu</div>
            <span class="card-badge badge-purple">Input</span>
          </div>
          <div class="profil-recap">
            <div class="profil-recap-title">📋 Data yang Diinput</div>
            <div class="profil-item">
              <span>👤</span><span class="profil-text">Nama</span>
              <span class="profil-val"><?= htmlspecialchars($profil_input['nama']) ?></span>
            </div>
            <div class="profil-item">
              <span>🏫</span><span class="profil-text">Kelas</span>
              <span class="profil-val"><?= htmlspecialchars($profil_input['kelas']) ?> SMA</span>
            </div>
            <div class="profil-item">
              <span><?= $gi['emoji'] ?></span><span class="profil-text">Gaya Belajar</span>
              <span class="profil-val" style="color:<?= $gi['color'] ?>;"><?= $gi['label'] ?></span>
            </div>
            <div class="profil-item">
              <span>🎯</span><span class="profil-text">Tujuan</span>
              <span class="profil-val"><?= htmlspecialchars($profil_input['tujuan']) ?></span>
            </div>
            <div class="profil-item">
              <span>💰</span><span class="profil-text">Budget</span>
              <span class="profil-val">Rp <?= number_format($profil_input['budget'],0,',','.') ?></span>
            </div>
          </div>
          <div style="font-size:12px;font-weight:700;color:var(--body);text-align:center;padding:8px;background:var(--light-gray);border-radius:10px;">
            💡 <?= htmlspecialchars($best['nama']) ?> paling cocok untuk <?= htmlspecialchars($profil_input['tujuan']) ?>
          </div>
        </div>

        <!-- Feature importance -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">🤖 Faktor Paling Penting</div>
            <span class="card-badge badge-green">ML Insight</span>
          </div>
          <?php
          $fi = [
            ['F3 - Fitur Tryout',  0.3038,'var(--green)'],
            ['F4 - Harga',         0.2411,'var(--orange)'],
            ['F2 - Gaya Belajar',  0.1996,'var(--purple)'],
            ['F5 - Rating',        0.1612,'var(--blue)'],
            ['F1 - Kelengkapan',   0.0942,'var(--dark)'],
          ];
          foreach ($fi as $f): ?>
          <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
              <span style="font-size:12px;font-weight:700;"><?= $f[0] ?></span>
              <span style="font-size:12px;font-weight:800;color:<?= $f[2] ?>;"><?= round($f[1]*100,1) ?>%</span>
            </div>
            <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
              <div style="height:100%;width:<?= $f[1]*100 ?>%;background:<?= $f[2] ?>;border-radius:4px;"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Tabel normalisasi -->
    <div class="card a4">
      <div class="card-header">
        <div class="card-title">📐 Matriks Ternormalisasi & Skor Vi</div>
        <span class="card-badge badge-blue">Detail Perhitungan SAW</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="text-align:left;color:var(--purple);">Platform</th>
              <th style="color:var(--blue);">r1 F1</th>
              <th style="color:var(--purple);">r2 F2</th>
              <th style="color:var(--green);">r3 F3</th>
              <th style="color:var(--orange);">r4 F4 Cost</th>
              <th style="color:var(--blue);">r5 F5</th>
              <th style="color:var(--dark);">Skor SAW</th>
              <th style="color:var(--green);">Proba ML</th>
              <th style="color:var(--dark);">Skor Hybrid</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($hasil_rekomendasi as $i => $p):
              $isBest  = $i === 0;
              $isWorst = $i === count($hasil_rekomendasi)-1;
              $bg = $isBest ? '#EDFBF4' : ($isWorst ? '#FEE2E2' : ($i%2===0 ? 'white' : '#F8F9FB'));
              $ri = ['🥇','🥈','🥉'][$i] ?? '';
            ?>
            <tr style="background:<?= $bg ?>;">
              <td><?= $ri ?> <?= htmlspecialchars($p['nama']) ?></td>
              <td style="color:var(--blue);"><?= $p['r1'] ?></td>
              <td style="color:var(--purple);"><?= $p['r2'] ?></td>
              <td style="color:var(--green);"><?= $p['r3'] ?></td>
              <td style="color:var(--orange);font-weight:800;"><?= $p['r4'] ?></td>
              <td style="color:var(--blue);"><?= $p['r5'] ?></td>
              <td><strong><?= $p['skor_saw'] ?></strong></td>
              <td style="color:<?= $p['proba_ml']>=0.7 ? 'var(--green)':'var(--red)' ?>;">
                <?= $p['proba_ml'] ?>
              </td>
              <td style="font-family:'Fredoka One',cursive;font-size:14px;color:<?= $isBest?'var(--green)':($isWorst?'var(--red)':'var(--dark)') ?>;">
                <strong><?= $p['skor_hybrid'] ?></strong>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:10px;font-size:11px;font-weight:700;color:var(--body);text-align:center;">
        Skor Hybrid = (0,70 × SAW × Bonus Gaya × Bonus Tujuan) + (0,30 × Proba ML) &nbsp;|&nbsp;
        W: 0,2086 | 0,2086 | 0,1999 | 0,1945 | 0,1885
      </div>
    </div>

  <?php
  // ════════════════════════════════════════════════
  // PAGE: PLATFORM
  // ════════════════════════════════════════════════
  elseif ($page === 'platform'): ?>

    <div style="font-family:'Fredoka One',cursive;font-size:24px;color:var(--dark);margin-bottom:16px;" class="a1">💻 Data Platform Belajar</div>
    <div class="card a2">
      <div class="card-header">
        <div class="card-title"><?= count($platforms_db) ?> Platform yang Dievaluasi</div>
        <span class="card-badge badge-blue">Observasi 2025</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="text-align:left;color:var(--purple);">Platform</th>
              <th style="color:var(--blue);">F1 Kelengkapan</th>
              <th style="color:var(--purple);">F2 Gaya Belajar</th>
              <th style="color:var(--green);">F3 Tryout</th>
              <th style="color:var(--orange);">F4 Harga</th>
              <th style="color:var(--blue);">F5 Rating</th>
              <th style="color:var(--dark);">Skor SAW</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($platforms_saw as $i => $p):
              $icon = $platform_icons[$p['nama']] ?? '💻';
              $bg   = $i%2===0 ? 'white' : '#F8F9FB';
            ?>
            <tr style="background:<?= $bg ?>;">
              <td><?= $icon ?> <strong><?= htmlspecialchars($p['nama']) ?></strong></td>
              <td><?= $p['f1_kelengkapan'] ?>/5</td>
              <td><?= $p['f2_gaya_belajar'] ?>/5</td>
              <td><?= $p['f3_tryout'] ?>/5</td>
              <td style="color:var(--orange);font-weight:800;">Rp <?= number_format($p['f4_harga']/1000,0) ?>rb</td>
              <td>⭐ <?= $p['f5_rating'] ?></td>
              <td><strong style="color:var(--purple);"><?= $p['skor_saw'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:10px;font-size:11px;font-weight:600;color:var(--body);">
        Sumber: Observasi website resmi platform belajar online (2025)
      </div>
    </div>

  <?php
  // ════════════════════════════════════════════════
  // PAGE: TENTANG (Bobot)
  // ════════════════════════════════════════════════
  elseif ($page === 'tentang'): ?>

    <div style="font-family:'Fredoka One',cursive;font-size:24px;color:var(--dark);margin-bottom:16px;" class="a1">⚖️ Bobot Kriteria SPK</div>
    <div class="card a2">
      <div class="card-header">
        <div class="card-title">Bobot dari Kuesioner 90 Responden SMAN 3 Malang</div>
        <span class="card-badge badge-purple">Wj = Rata-rata / Total Rata-rata</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="text-align:left;color:var(--purple);">Kode</th>
              <th style="text-align:left;">Nama Kriteria</th>
              <th>Total Skor</th>
              <th>Rata-rata</th>
              <th>Bobot (Wj)</th>
              <th>Tipe</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $bobot_detail = [
              ['F1','Kelengkapan Materi',  385,4.2778,0.2086,'Benefit','var(--blue)'],
              ['F2','Kesesuaian Gaya Belajar',385,4.2778,0.2086,'Benefit','var(--purple)'],
              ['F3','Fitur Tryout/Latihan',369,4.1000,0.1999,'Benefit','var(--green)'],
              ['F4','Harga Berlangganan',  359,3.9889,0.1945,'Cost',   'var(--orange)'],
              ['F5','Rating Pengguna',     348,3.8667,0.1885,'Benefit','var(--blue)'],
            ];
            foreach ($bobot_detail as $i => $b):
              $bg = $i%2===0 ? 'white' : '#F8F9FB';
              $tc = $b[5]==='Cost' ? '#C05500' : '#1A6B42';
              $tbg= $b[5]==='Cost' ? 'var(--orange-light)' : 'var(--green-light)';
            ?>
            <tr style="background:<?= $bg ?>;">
              <td><strong style="color:<?= $b[6] ?>;"><?= $b[0] ?></strong></td>
              <td><?= $b[1] ?></td>
              <td><?= $b[2] ?></td>
              <td><?= number_format($b[3],4) ?></td>
              <td style="font-family:'Fredoka One',cursive;font-size:15px;color:<?= $b[6] ?>;"><?= number_format($b[4],4) ?></td>
              <td><span style="font-size:10px;font-weight:800;padding:2px 10px;border-radius:50px;background:<?= $tbg ?>;color:<?= $tc ?>;"><?= $b[5] ?></span></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#F3F0FF;">
              <td colspan="4" style="text-align:right;font-weight:800;color:var(--purple);">Total Bobot</td>
              <td style="font-family:'Fredoka One',cursive;font-size:15px;color:var(--purple);"><strong>1.0001</strong></td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div style="margin-top:16px;padding:14px;background:var(--purple-bg);border-radius:12px;font-size:13px;font-weight:700;color:var(--purple);">
        📌 Rumus: Wj = Rata-rata kriteria j / Total rata-rata (20,5112) · 90 Responden SMAN 3 Malang
      </div>
    </div>

  <?php elseif ($page === 'hasil' && !$hasil_rekomendasi): ?>
    <div style="text-align:center;padding:60px 20px;">
      <div style="font-size:48px;margin-bottom:16px;">🎯</div>
      <div style="font-family:'Fredoka One',cursive;font-size:22px;color:var(--dark);margin-bottom:8px;">Belum Ada Hasil</div>
      <div style="font-size:13px;font-weight:600;color:var(--body);margin-bottom:20px;">Isi profil dulu untuk mendapatkan rekomendasi platform.</div>
      <a href="?page=rekomendasi" class="hero-btn" style="display:inline-block;background:var(--purple);color:white;">Mulai Isi Profil →</a>
    </div>
  <?php endif; ?>

  </div><!-- end .content -->
</div><!-- end .main -->

<script>
function selectGaya(gaya) {
  ['visual','auditori','readwrite','kinestetik'].forEach(g => {
    document.getElementById('opt-'+g).className = 'gaya-opt';
  });
  document.getElementById('opt-'+gaya).className = 'gaya-opt sel-'+gaya;
  document.querySelector(`input[value="${gaya}"]`).checked = true;
}

function selectTujuan(el) {
  document.querySelectorAll('.tujuan-opt').forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');
}

function updateBudget(val) {
  const num = parseInt(val);
  document.getElementById('budget-display').textContent = 'Rp ' + num.toLocaleString('id-ID');
  const pct = ((num - 50000) / (300000 - 50000)) * 100;
  document.getElementById('budget-slider').style.setProperty('--pct', pct + '%');
}

function showLoading() {
  document.getElementById('loading-overlay').style.display = 'flex';
}

function showPopupFromEl(el) {
  try {
    const d = JSON.parse(el.getAttribute('data-popup'));
    showPopup(d.nama, d.icon, d.hybrid, d.saw, d.ml, d.harga, d.ranking,
      d.matchGaya, d.gayaLabel, d.gayaEmoji, d.gayaColor,
      d.pros, d.cons, d.cocok, d.tag, d.tagColor, d.url, d.overBudget);
  } catch(e) { console.error('Popup error:', e); }
}

function showPopup(nama, icon, hybrid, saw, ml, harga, rank, matchGaya, gayaLabel, gayaEmoji, gayaColor, pros, cons, cocok, tag, tagColor, url, overBudget) {
  document.getElementById('pop-nama').textContent    = nama;
  document.getElementById('pop-icon').textContent    = icon;
  document.getElementById('pop-hybrid').textContent  = hybrid;
  document.getElementById('pop-saw').textContent     = saw;
  document.getElementById('pop-ml').textContent      = ml;
  document.getElementById('pop-harga').textContent   = harga;
  document.getElementById('pop-cocok').textContent   = cocok;
  document.getElementById('pop-visit').href          = 'https://' + url;
  document.getElementById('pop-rank').textContent    = 'Ranking #' + rank + ' dari 7 platform';

  // Tag
  const tagEl = document.getElementById('pop-tag');
  tagEl.textContent    = tag;
  tagEl.style.background = tagColor + '22';
  tagEl.style.color      = tagColor;

  // Gaya belajar
  const gayaSection  = document.getElementById('pop-gaya-section');
  const gayaContent  = document.getElementById('pop-gaya-content');
  const gayaTitle    = document.getElementById('pop-gaya-title');
  if (matchGaya) {
    gayaTitle.textContent = gayaEmoji + ' Cocok dengan Gaya Belajar ' + gayaLabel;
    gayaContent.innerHTML = `<div style="background:${gayaColor}15;border:1.5px solid ${gayaColor}40;border-radius:10px;padding:10px 12px;font-size:12px;font-weight:700;color:${gayaColor};">✓ Platform ini sangat sesuai dengan gaya belajar <strong>${gayaLabel}</strong> kamu. Kamu akan lebih mudah menyerap materi di platform ini.</div>`;
  } else {
    gayaTitle.textContent = '🧠 Gaya Belajar';
    gayaContent.innerHTML = `<div style="background:var(--light-gray);border-radius:10px;padding:10px 12px;font-size:12px;font-weight:600;color:var(--body);">Platform ini kurang optimal untuk gaya belajar <strong>${gayaLabel}</strong>, tapi masih bisa digunakan dengan penyesuaian.</div>`;
  }

  // Budget warning
  document.getElementById('pop-budget-section').style.display = overBudget ? 'block' : 'none';

  // Pros
  const prosList = document.getElementById('pop-pros');
  prosList.innerHTML = pros.map(p => `<li><span style="color:var(--green);font-size:14px;">✓</span>${p}</li>`).join('');

  // Cons
  const consList = document.getElementById('pop-cons');
  consList.innerHTML = cons.map(c => `<li><span style="color:var(--red);font-size:14px;">!</span>${c}</li>`).join('');

  document.getElementById('popup-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closePopup() {
  document.getElementById('popup-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closePopupOutside(e) {
  if (e.target === document.getElementById('popup-overlay')) closePopup();
}

document.addEventListener('keydown', e => { if(e.key === 'Escape') closePopup(); });

</script>
</body>
</html>