<?php
session_start();
require_once '../config/database.php';

// Proteksi halaman
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2 || $_SESSION['is_asesor'] != 1) {
    header('Location: ../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $course_id = (int)$_POST['course_id'];
    $user_id = $_SESSION['user_id'];

    // Validasi input
    if (empty($course_id) || !isset($_FILES['rps_file'])) {
        $_SESSION['error_message'] = "Mata kuliah dan file RPS harus dipilih.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    // 1. Pastikan dosen mengampu mata kuliah yang dipilih dan mendapatkan nama mata kuliah
    $course_name_for_file = 'unknown_course'; // Default jika tidak ditemukan
    try {
        $stmt_check_course = $pdo->prepare("
            SELECT c.course_name, c.rps_file_path
            FROM courses c
            JOIN dosen_courses dc ON c.id = dc.course_id
            WHERE dc.dosen_id = :dosen_id AND c.id = :course_id
        ");
        $stmt_check_course->execute([':dosen_id' => $user_id, ':course_id' => $course_id]);
        $course_data = $stmt_check_course->fetch(PDO::FETCH_ASSOC);

        if (!$course_data) {
            $_SESSION['error_message'] = "Mata kuliah tidak ditemukan atau Anda tidak memiliki izin untuk mengunggah RPS untuk mata kuliah ini.";
            header('Location: ../views/dosen/dosen_asesor_menu.php');
            exit();
        }
        $course_name_for_file = preg_replace('/[^a-zA-Z0-9_-]/', '_', $course_data['course_name']);
        $old_rps_file_path = $course_data['rps_file_path']; // Ambil path file RPS lama
    } catch (PDOException $e) {
        error_log("Database error during course check for upload: " . $e->getMessage());
        $_SESSION['error_message'] = "Terjadi kesalahan saat memverifikasi mata kuliah.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    $file_name = $_FILES['rps_file']['name'];
    $file_tmp = $_FILES['rps_file']['tmp_name'];
    $file_size = $_FILES['rps_file']['size'];
    $file_error = $_FILES['rps_file']['error'];

    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    $allowed_ext = ['pdf'];
    $max_file_size = 2 * 1024 * 1024; // 2MB

    if (!in_array($file_ext, $allowed_ext)) {
        $_SESSION['error_message'] = "Format file tidak diizinkan. Hanya file PDF (.pdf) yang diterima.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    if ($file_size > $max_file_size) {
        $_SESSION['error_message'] = "Ukuran file terlalu besar. Maksimal 2MB.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    if ($file_error !== 0) {
        $_SESSION['error_message'] = "Terjadi kesalahan saat mengunggah file. Kode error: " . $file_error;
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }

    // Buat nama file unik: rps_NAMA_MK_TIMESTAMP.pdf
    $new_file_name = 'rps_' . $course_name_for_file . '_' . time() . '.' . $file_ext;
    $upload_dir = '../assets/upload/'; // Folder untuk menyimpan RPS
    $file_destination = $upload_dir . $new_file_name;

    // Pastikan direktori ada
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    // Jika ada file RPS lama, hapus sebelum mengunggah yang baru
    if (!empty($old_rps_file_path) && file_exists($upload_dir . $old_rps_file_path)) {
        unlink($upload_dir . $old_rps_file_path);
    }

    if (move_uploaded_file($file_tmp, $file_destination)) {
        try {
            // Perbarui kolom rps_file_path dan updated_at di tabel courses
            $stmt = $pdo->prepare("
                UPDATE courses
                SET rps_file_path = :file_path, updated_at = NOW()
                WHERE id = :course_id
            ");
            $stmt->execute([
                ':file_path' => $new_file_name,
                ':course_id' => $course_id
            ]);

            $_SESSION['success_message'] = "File RPS untuk Mata Kuliah berhasil diunggah!";
            header('Location: ../views/dosen/dosen_asesor_menu.php');
            exit();

        } catch (PDOException $e) {
            error_log("Database error during RPS upload to courses table: " . $e->getMessage());
            // Hapus file yang sudah terupload jika ada error database
            if (file_exists($file_destination)) {
                unlink($file_destination);
            }
            $_SESSION['error_message'] = "Gagal menyimpan data RPS ke database. Terjadi kesalahan internal.";
            header('Location: ../views/dosen/dosen_asesor_menu.php');
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Gagal memindahkan file yang diunggah.";
        header('Location: ../views/dosen/dosen_asesor_menu.php');
        exit();
    }
} else {
    header('Location: ../views/dosen/dosen_asesor_menu.php');
    exit();
}