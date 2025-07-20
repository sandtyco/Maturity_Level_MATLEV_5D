<?php
session_start();
require_once '../config/database.php';

// Proteksi halaman
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2 || $_SESSION['is_asesor'] != 1) {
    header('Location: ../login.php');
    exit();
}

if (isset($_GET['course_id']) && is_numeric($_GET['course_id'])) {
    $course_id = (int)$_GET['course_id'];
    $user_id = $_SESSION['user_id'];

    try {
        // 1. Pastikan dosen mengampu mata kuliah yang dipilih dan ambil path file RPS
        $stmt_get_file = $pdo->prepare("
            SELECT c.rps_file_path
            FROM courses c
            JOIN dosen_courses dc ON c.id = dc.course_id
            WHERE c.id = :course_id AND dc.dosen_id = :dosen_id
        ");
        $stmt_get_file->execute([':course_id' => $course_id, ':dosen_id' => $user_id]);
        $course_data = $stmt_get_file->fetch(PDO::FETCH_ASSOC);

        if ($course_data && !empty($course_data['rps_file_path'])) {
            $file_to_delete = '../../assets/upload/' . $course_data['rps_file_path'];

            // 2. Update rps_file_path di tabel courses menjadi NULL
            //    dan set updated_at
            $stmt_update = $pdo->prepare("
                UPDATE courses
                SET rps_file_path = NULL, updated_at = NOW()
                WHERE id = :course_id
            ");
            $stmt_update->execute([':course_id' => $course_id]);

            if ($stmt_update->rowCount() > 0) {
                // 3. Hapus file fisik setelah berhasil dihapus dari database
                if (file_exists($file_to_delete) && !is_dir($file_to_delete)) {
                    unlink($file_to_delete);
                }
                $_SESSION['success_message'] = "File RPS untuk Mata Kuliah berhasil dihapus!";
            } else {
                $_SESSION['error_message'] = "Gagal menghapus file RPS dari database.";
            }
        } else {
            $_SESSION['error_message'] = "File RPS tidak ditemukan untuk mata kuliah ini atau Anda tidak memiliki izin untuk menghapusnya.";
        }
    } catch (PDOException $e) {
        error_log("Database error during RPS file deletion from courses table: " . $e->getMessage());
        $_SESSION['error_message'] = "Gagal menghapus file RPS. Terjadi kesalahan database.";
    }
} else {
    $_SESSION['error_message'] = "ID Mata Kuliah tidak valid.";
}

header('Location: ../views/dosen/dosen_asesor_menu.php');
exit();