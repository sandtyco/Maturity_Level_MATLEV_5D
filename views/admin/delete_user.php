<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path jika berbeda

// Proteksi halaman: hanya admin (role_id = 1) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: ../login.php');
    exit();
}

// Pastikan request method adalah POST (untuk keamanan, menghindari hapus via URL)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_users.php?status=invalid_request');
    exit();
}

$user_id = $_POST['id'] ?? null; // Ambil user_id dari POST data

if (!$user_id) {
    header('Location: manage_users.php?status=no_id');
    exit();
}

try {
    // Mulai transaksi database
    $pdo->beginTransaction();

    // Hapus pengguna dari tabel 'users'
    // Karena kita sudah mengatur ON DELETE CASCADE pada foreign keys
    // di tabel 'dosen_details' dan 'mahasiswa_details' (yang merujuk ke users.id),
    // serta pada 'dosen_courses' dan 'mahasiswa_courses' (yang merujuk ke detail tables),
    // cukup menghapus dari tabel 'users' saja akan secara otomatis menghapus data terkait.
    $stmt_delete = $pdo->prepare("DELETE FROM users WHERE id = :user_id");
    $stmt_delete->execute([':user_id' => $user_id]);

    $pdo->commit(); // Commit transaksi
    header('Location: manage_users.php?status=success_delete');
    exit();

} catch (PDOException $e) {
    $pdo->rollBack(); // Rollback jika ada error
    // Log error untuk debugging (opsional, tapi disarankan)
    // error_log("Error deleting user: " . $e->getMessage());
    header('Location: manage_users.php?status=error&message=' . urlencode($e->getMessage()));
    exit();
}
?>