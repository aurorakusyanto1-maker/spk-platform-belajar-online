<?php
// koneksi.php
// Jembatan antara PHP dan database MySQL
// SPK Rekomendasi Platform Belajar Online — SMAN 3 Malang

$host     = "localhost";
$user     = "root";          // user default XAMPP
$password = "";               // kosong di XAMPP lokal
$database = "spk_platform_belajar";

$conn = mysqli_connect($host, $user, $password, $database);

if (!$conn) {
    die("Koneksi gagal: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8");
?>
