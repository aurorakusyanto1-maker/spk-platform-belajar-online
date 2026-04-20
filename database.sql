-- ============================================================
-- DATABASE SPK REKOMENDASI PLATFORM BELAJAR ONLINE
-- SMAN 3 Malang | Metode SAW | 90 Responden (Data Riil)
-- ============================================================

CREATE DATABASE IF NOT EXISTS spk_platform_belajar;
USE spk_platform_belajar;

-- ── TABEL KRITERIA ─────────────────────────────────────────
-- Bobot dihitung dari rata-rata skor 90 responden kuesioner
-- Total rata-rata = 20.5112
-- Wj = rata-rata / total_rata
CREATE TABLE kriteria (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  kode      VARCHAR(5)   NOT NULL,
  nama      VARCHAR(100) NOT NULL,
  bobot     FLOAT        NOT NULL,
  tipe      ENUM('benefit','cost') NOT NULL
);

INSERT INTO kriteria (kode, nama, bobot, tipe) VALUES
('F1', 'Kelengkapan Materi',        0.2086, 'benefit'),
('F2', 'Kesesuaian Gaya Belajar',   0.2086, 'benefit'),
('F3', 'Fitur Tryout/Latihan Soal', 0.1999, 'benefit'),
('F4', 'Harga Berlangganan',        0.1945, 'cost'),
('F5', 'Rating Pengguna',           0.1885, 'benefit');

-- ── TABEL PLATFORM (alternatif) ────────────────────────────
-- Nilai F1-F3, F5: skala 1-5 (Benefit)
-- Nilai F4: harga dalam ribuan rupiah (Cost)
-- Sumber: observasi website resmi platform 2025
CREATE TABLE platform (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nama         VARCHAR(100) NOT NULL,
  f1_kelengkapan FLOAT NOT NULL,
  f2_gaya_belajar FLOAT NOT NULL,
  f3_tryout    FLOAT NOT NULL,
  f4_harga     FLOAT NOT NULL,
  f5_rating    FLOAT NOT NULL
);

INSERT INTO platform (nama, f1_kelengkapan, f2_gaya_belajar, f3_tryout, f4_harga, f5_rating) VALUES
('Ruangguru',  5, 4, 5, 150000, 5),
('Zenius',     5, 3, 4, 100000, 4),
('Quipper',    4, 3, 4,  85000, 4),
('Sekolah.mu', 4, 4, 3,  99000, 4),
('Pahamify',   4, 4, 5,  79000, 4),
('Coursera',   5, 2, 3, 299000, 5),
('Skolla',     3, 3, 3,  49000, 3);

-- ── TABEL PROFIL SISWA ─────────────────────────────────────
CREATE TABLE siswa (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nama         VARCHAR(100) NOT NULL,
  kelas        VARCHAR(5),
  jenis_kelamin ENUM('L','P'),
  sekolah      VARCHAR(100) DEFAULT 'SMAN 3 Malang',
  gaya_belajar VARCHAR(20),   -- visual/auditori/readwrite/kinestetik
  budget       INT,           -- budget per bulan (Rp)
  tujuan       VARCHAR(50),
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── TABEL HASIL SAW ────────────────────────────────────────
CREATE TABLE hasil_saw (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  siswa_id     INT NOT NULL,
  platform_id  INT NOT NULL,
  r1           FLOAT,
  r2           FLOAT,
  r3           FLOAT,
  r4           FLOAT,
  r5           FLOAT,
  skor_saw     FLOAT,
  ranking      INT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (siswa_id)    REFERENCES siswa(id),
  FOREIGN KEY (platform_id) REFERENCES platform(id)
);

-- ── TABEL BOBOT RESPONDEN (dari kuesioner 90 responden) ────
CREATE TABLE bobot_responden (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  kriteria_id  INT NOT NULL,
  total_skor   INT NOT NULL,
  rata_rata    FLOAT NOT NULL,
  bobot        FLOAT NOT NULL,
  jumlah_resp  INT DEFAULT 90,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (kriteria_id) REFERENCES kriteria(id)
);

INSERT INTO bobot_responden (kriteria_id, total_skor, rata_rata, bobot) VALUES
(1, 385, 4.2778, 0.2086),
(2, 385, 4.2778, 0.2086),
(3, 369, 4.1000, 0.1999),
(4, 359, 3.9889, 0.1945),
(5, 348, 3.8667, 0.1885);
