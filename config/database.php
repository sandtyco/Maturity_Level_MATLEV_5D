<?php
// config/database.php

$host = 'localhost'; // Host database Anda, biasanya localhost
$db   = 'matlev_5d'; // Nama database Anda (yang sudah Anda siapkan)
$user = 'root';      // Username database Anda (default XAMPP/WAMPP adalah root)
$pass = '';          // Password database Anda (default XAMPP/WAMPP biasanya kosong)
$charset = 'utf8mb4'; // Karakter set

// Data Source Name (DSN) untuk koneksi MySQL via PDO
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Opsi tambahan untuk koneksi PDO
$options = [
    PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,     // Mengaktifkan mode error yang melempar exception untuk debugging
    PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,           // Hasil query akan diambil sebagai array asosiatif
    PDO::ATTR_EMULATE_PREPARES     => false,                      // Menonaktifkan emulasi prepared statements (lebih aman)
];

try {
    // Membuat instance PDO (objek koneksi database)
    $pdo = new PDO($dsn, $user, $pass, $options);
    // echo "Koneksi database berhasil!"; // Anda bisa mengaktifkan ini sementara untuk tes koneksi
} catch (\PDOException $e) {
    // Jika koneksi gagal, hentikan skrip dan tampilkan pesan error
    // CATATAN: Ini hanya untuk pengembangan. Di lingkungan produksi, log error saja
    // dan tampilkan pesan generik ke pengguna untuk alasan keamanan.
    error_log("Database connection error: " . $e->getMessage()); // Mencatat error ke PHP error log
    die("Koneksi database gagal: " . $e->getMessage()); // Menampilkan error langsung ke browser
}