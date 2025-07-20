<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path ke file database.php

// Proteksi halaman: hanya admin (role_id = 1) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: ../login.php'); // Arahkan ke halaman login
    exit();
}

$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];

$question_data = null; 
$aspects = []; 
$question_types = []; 
$message = ''; 
$error = '';   

// --- Ambil ID Pertanyaan dari URL ---
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $question_id = (int)$_GET['id'];

    try {
        // Ambil data Pertanyaan berdasarkan ID
        $stmt = $pdo->prepare("SELECT id, question_text, aspect_id, question_type_id FROM assessment_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$question_data) {
            $_SESSION['error_message'] = "Pertanyaan tidak ditemukan.";
            header('Location: setting_questions.php');
            exit();
        }

        // Ambil daftar aspek untuk dropdown
        $stmt_aspects = $pdo->query("SELECT id, aspect_name FROM assessment_aspects ORDER BY aspect_name ASC");
        $aspects = $stmt_aspects->fetchAll(PDO::FETCH_ASSOC);

        // Ambil daftar tipe pertanyaan untuk dropdown
        $stmt_types = $pdo->query("SELECT id, type_name FROM assessment_question_types ORDER BY type_name ASC");
        $question_types = $stmt_types->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error mengambil data Pertanyaan atau referensi: " . $e->getMessage();
        header('Location: setting_questions.php');
        exit();
    }
} else {
    header('Location: setting_questions.php');
    exit();
}

// --- LOGIKA UNTUK UPDATE PERTANYAAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question'])) {
    $id = (int)$_POST['id']; 
    $question_text_new = trim($_POST['question_text']);
    $aspect_id_new = (int)$_POST['aspect_id'];
    $question_type_id_new = (int)$_POST['question_type_id'];

    if (empty($question_text_new) || empty($aspect_id_new) || empty($question_type_id_new)) {
        $error = "Semua kolom (Pertanyaan, Aspek, Tipe Pertanyaan) harus diisi.";
        // Perbarui data yang ditampilkan di form agar tidak hilang
        $question_data['question_text'] = $question_text_new;
        $question_data['aspect_id'] = $aspect_id_new;
        $question_data['question_type_id'] = $question_type_id_new;
    } elseif ($id !== $question_id) {
        $error = "ID Pertanyaan tidak cocok.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE assessment_questions SET question_text = ?, aspect_id = ?, question_type_id = ? WHERE id = ?");
            $stmt->execute([$question_text_new, $aspect_id_new, $question_type_id_new, $id]);
            
            if ($stmt->rowCount()) {
                $_SESSION['success_message'] = "Pertanyaan berhasil diperbarui.";
            } else {
                $_SESSION['error_message'] = "Tidak ada perubahan yang terdeteksi atau Pertanyaan gagal diperbarui.";
            }
            header('Location: setting_questions.php');
            exit();

        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error memperbarui Pertanyaan: " . $e->getMessage();
            header('Location: setting_questions.php');
            exit();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pertanyaan Instrumen - Admin MATLEV 5D</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
	<link rel="icon" href="../../assets/img/favicon.png" type="image/x-icon">
    <style>
        body {
            display: flex;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        #sidebar {
            width: 250px;
            background-color: #343a40;
            color: white;
            padding: 20px;
            flex-shrink: 0;
        }
        #sidebar a {
            color: white;
            text-decoration: none;
            padding: 10px 0;
            display: block;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        #sidebar a:hover, #sidebar a.active {
            background-color: #495057;
        }
        #content {
            flex-grow: 1;
            padding: 20px;
        }
        .navbar-brand {
            font-weight: bold;
        }
        .card-header {
            background-color: #007bff;
            color: white;
        }
        /* Style untuk dropdown menu dark agar terlihat di sidebar gelap */
        .dropdown-menu-dark {
            background-color: #495057; /* Warna latar belakang menu dropdown */
            border: 1px solid rgba(0, 0, 0, 0.15);
        }
        .dropdown-menu-dark .dropdown-item {
            color: white; /* Warna teks item dropdown */
        }
        .dropdown-menu-dark .dropdown-item:hover,
        .dropdown-menu-dark .dropdown-item:focus {
            background-color: #6c757d; /* Warna hover untuk item dropdown */
        }
    </style>
</head>
<body>

    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="admin_dashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_users.php">
                    <i class="fas fa-fw fa-users me-2"></i>Data Pengguna
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_institution.php">
                    <i class="fas fa-fw fa-building me-2"></i>Data Institusi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_prodi.php">
                    <i class="fas fa-fw fa-graduation-cap me-2"></i>Data Program Studi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_rps.php">
                    <i class="fas fa-fw fa-book me-2"></i>Data RPS Mata Kuliah
                </a>
            </li>

            <li class="nav-item dropdown">
				<a class="nav-link dropdown-toggle" href="#" id="setupInstrumenDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
					<i class="fas fa-fw fa-cogs me-2"></i>Setting Instrument
				</a>
				<ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="setupInstrumenDropdown">
                    <li><a class="dropdown-item" href="setting_questions.php">Kuesioner</a></li>
                    <li><a class="dropdown-item" href="setting_dimensions.php">Nilai Dimensi</a></li>
                    <li><a class="dropdown-item" href="setting_result.php">Deskripsi Hasil</a></li>
                    <li><a class="dropdown-item" href="setting_rules.php">Klasifikasi Hasil</a></li>
                </ul>
			</li>
            <li class="nav-item mt-auto">
                <a class="nav-link" href="../../logout.php">
                    <i class="fas fa-fw fa-sign-out-alt me-2"></i>Logout
                </a>
            </li>
        </ul>
    </div>
    <div id="content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light rounded-3 mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">Edit Pertanyaan Instrumen</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role_name); ?>)
                </span>
            </div>
        </nav>

        <?php 
            if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($question_data): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Edit Detail Pertanyaan ID: <?php echo htmlspecialchars($question_data['id']); ?></h5>
                </div>
                <div class="card-body">
                    <form action="" method="POST">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($question_data['id']); ?>">
                        
                        <div class="mb-3">
                            <label for="question_text" class="form-label">Teks Pertanyaan</label>
                            <textarea class="form-control" id="question_text" name="question_text" rows="3" required><?php echo htmlspecialchars($question_data['question_text']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="aspect_id" class="form-label">Aspek</label>
                            <select class="form-select" id="aspect_id" name="aspect_id" required>
                                <option value="">Pilih Aspek</option>
                                <?php foreach ($aspects as $aspect): ?>
                                    <option value="<?php echo htmlspecialchars($aspect['id']); ?>"
                                        <?php echo ($question_data['aspect_id'] == $aspect['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($aspect['aspect_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($aspects)): ?>
                                <small class="text-danger">Belum ada aspek terdaftar.</small>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="question_type_id" class="form-label">Tipe Pertanyaan</label>
                            <select class="form-select" id="question_type_id" name="question_type_id" required>
                                <option value="">Pilih Tipe Pertanyaan</option>
                                <?php foreach ($question_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type['id']); ?>"
                                        <?php echo ($question_data['question_type_id'] == $type['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['type_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($question_types)): ?>
                                <small class="text-danger">Belum ada tipe pertanyaan terdaftar.</small>
                            <?php endif; ?>
                        </div>
                        <button type="submit" name="update_question" class="btn btn-primary">Update Pertanyaan</button>
                        <a href="setting_questions.php" class="btn btn-secondary">Batal</a>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                Pilih pertanyaan dari daftar untuk mengeditnya. <a href="setting_questions.php">Kembali ke Manajemen Pertanyaan</a>
            </div>
        <?php endif; ?>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
</body>
</html>