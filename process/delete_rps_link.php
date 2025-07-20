<?php
session_start();
require_once '../config/database.php';

// Proteksi halaman: Hanya dosen (role_id = 2) yang bisa menghapus link RPS.
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: ../login.php');
    exit();
}

if (isset($_GET['course_id'])) {
    $course_id = (int)$_GET['course_id'];
    $user_id = $_SESSION['user_id'];

    try {
        // Pastikan dosen mengampu mata kuliah yang dipilih sebelum menghapus link
        $stmt_check_course = $pdo->prepare("
            SELECT c.id
            FROM courses c
            JOIN dosen_courses dc ON c.id = dc.course_id
            WHERE dc.dosen_id = :dosen_id AND c.id = :course_id
        ");
        $stmt_check_course->execute([':dosen_id' => $user_id, ':course_id' => $course_id]);
        $course_exists = $stmt_check_course->fetch(PDO::FETCH_ASSOC);

        if (!$course_exists) {
            $_SESSION['error_message'] = "Mata kuliah tidak ditemukan atau Anda tidak memiliki izin untuk menghapus link RPS untuk mata kuliah ini.";
            header('Location: ../views/dosen/dosen_asesor_menu.php');
            exit();
        }

        // Set rps_file_path menjadi NULL (kosong) untuk menghapus link
        $stmt = $pdo->prepare("
            UPDATE courses
            SET rps_file_path = NULL, updated_at = NOW()
            WHERE id = :course_id
        ");
        $stmt->execute([':course_id' => $course_id]);

        $_SESSION['success_message'] = "Link RPS berhasil dihapus!";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();

    } catch (PDOException $e) {
        error_log("Database error during RPS link deletion (using rps_file_path) for course_id {$course_id}: " . $e->getMessage());
        $_SESSION['error_message'] = "Gagal menghapus link RPS dari database. Terjadi kesalahan internal.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }
} else {
    $_SESSION['error_message'] = "ID Mata Kuliah tidak valid.";
    header('Location: ../views/dosen/dosen_asesor_menu.php');
    exit();
}