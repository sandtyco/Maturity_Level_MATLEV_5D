<?php
session_start();
require_once '../config/database.php';

// Proteksi halaman: Hanya dosen (role_id = 2) yang bisa mengakses.
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $course_id = (int)$_POST['course_id'];
    $rps_link = trim($_POST['rps_link']);
    $user_id = $_SESSION['user_id'];

    // Validasi input: pastikan mata kuliah dipilih dan link RPS tidak kosong
    if (empty($course_id) || empty($rps_link)) {
        $_SESSION['error_message'] = "Mata kuliah dan link RPS harus diisi.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    // Validasi format link (opsional, tapi disarankan)
    if (!filter_var($rps_link, FILTER_VALIDATE_URL)) {
        $_SESSION['error_message'] = "Format link RPS tidak valid. Pastikan ini adalah URL yang benar.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    // 1. Pastikan dosen mengampu mata kuliah yang dipilih
    try {
        $stmt_check_course = $pdo->prepare("
            SELECT c.id
            FROM courses c
            JOIN dosen_courses dc ON c.id = dc.course_id
            WHERE dc.dosen_id = :dosen_id AND c.id = :course_id
        ");
        $stmt_check_course->execute([':dosen_id' => $user_id, ':course_id' => $course_id]);
        $course_exists = $stmt_check_course->fetch(PDO::FETCH_ASSOC);

        if (!$course_exists) {
            $_SESSION['error_message'] = "Mata kuliah tidak ditemukan atau Anda tidak memiliki izin untuk mengunggah link RPS untuk mata kuliah ini.";
            header('Location: ../views/dosen/dosen_asesor_menu.php');
            exit();
        }
    } catch (PDOException $e) {
        error_log("Database error during course check for RPS link upload (using rps_file_path): " . $e->getMessage());
        $_SESSION['error_message'] = "Terjadi kesalahan saat memverifikasi mata kuliah.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    try {
        // Perbarui kolom rps_file_path dan updated_at di tabel courses
        $stmt = $pdo->prepare("
            UPDATE courses
            SET rps_file_path = :rps_link, updated_at = NOW()
            WHERE id = :course_id
        ");
        $stmt->execute([
            ':rps_link' => $rps_link,
            ':course_id' => $course_id
        ]);

        $_SESSION['success_message'] = "Link RPS untuk Mata Kuliah berhasil disimpan!";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();

    } catch (PDOException $e) {
        error_log("Database error during RPS link update (using rps_file_path) for course_id {$course_id}: " . $e->getMessage());
        $_SESSION['error_message'] = "Gagal menyimpan link RPS ke database. Terjadi kesalahan internal.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }
} else {
    // Jika diakses tidak melalui POST request
    header('Location: ../views/dosen/dosen_asesor_menu.php');
    exit();
}