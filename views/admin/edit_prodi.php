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

$prodi_data = null; // Variabel untuk menyimpan data prodi yang akan diedit
$institutions = []; // Variabel untuk menyimpan daftar institusi untuk dropdown
$message = ''; // Untuk pesan sukses (digunakan jika tidak redirect)
$error = '';   // Untuk pesan error (digunakan jika tidak redirect)

// --- Ambil ID Program Studi dari URL ---
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $prodi_id = (int)$_GET['id'];

    try {
        // Ambil data Program Studi berdasarkan ID
        $stmt = $pdo->prepare("SELECT id, institution_id, program_name, level FROM programs_of_study WHERE id = ?");
        $stmt->execute([$prodi_id]);
        $prodi_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prodi_data) {
            $_SESSION['error_message'] = "Program Studi tidak ditemukan.";
            header('Location: manage_prodi.php');
            exit();
        }

        // Ambil daftar institusi untuk dropdown
        $stmt_inst = $pdo->query("SELECT id, nama_pt FROM institutions ORDER BY nama_pt ASC");
        $institutions = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error mengambil data Program Studi atau Institusi: " . $e->getMessage();
        header('Location: manage_prodi.php');
        exit();
    }
} else {
    // Jika tidak ada ID, redirect kembali ke manage_prodi.php
    header('Location: manage_prodi.php');
    exit();
}

// --- LOGIKA UNTUK UPDATE PROGRAM STUDI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prodi'])) {
    $id = (int)$_POST['id']; // Pastikan ID dikirim kembali dari form
    $institution_id_new = (int)$_POST['institution_id'];
    $program_name_new = trim($_POST['program_name']);
    $level_new = trim($_POST['level']);

    if (empty($institution_id_new) || empty($program_name_new) || empty($level_new)) {
        $error = "Semua kolom (Institusi, Nama Program Studi, Level) harus diisi.";
        // Tidak redirect di sini agar pengguna bisa melihat error dan memperbaiki input
    } elseif ($id !== $prodi_id) {
        // Pencegahan potensi tampering ID jika ID di GET dan POST tidak cocok
        $error = "ID Program Studi tidak cocok.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE programs_of_study SET institution_id = ?, program_name = ?, level = ? WHERE id = ?");
            $stmt->execute([$institution_id_new, $program_name_new, $level_new, $id]);
            
            if ($stmt->rowCount()) {
                $_SESSION['success_message'] = "Program Studi '<strong>" . htmlspecialchars($program_name_new) . "</strong>' berhasil diperbarui.";
            } else {
                $_SESSION['error_message'] = "Tidak ada perubahan yang terdeteksi atau Program Studi gagal diperbarui.";
            }
            // Redirect kembali ke manage_prodi.php setelah update (berhasil/gagal)
            header('Location: manage_prodi.php');
            exit();

        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error memperbarui Program Studi: " . $e->getMessage();
            header('Location: manage_prodi.php');
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
    <title>Edit Program Studi - Admin MATLEV 5D</title>
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
                <a class="nav-link active" href="manage_prodi.php"> <i class="fas fa-fw fa-graduation-cap me-2"></i>Data Program Studi
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
                <a class="navbar-brand" href="#">Edit Program Studi</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role_name); ?>)
                </span>
            </div>
        </nav>

        <?php 
            // Untuk pesan error yang TIDAK menyebabkan redirect (misal: validasi input form)
            if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($prodi_data): // Tampilkan form hanya jika data prodi ditemukan ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Edit Detail Program Studi: <?php echo htmlspecialchars($prodi_data['program_name']); ?></h5>
                </div>
                <div class="card-body">
                    <form action="" method="POST">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($prodi_data['id']); ?>">
                        
                        <div class="mb-3">
                            <label for="institution_id" class="form-label">Institusi</label>
                            <select class="form-select" id="institution_id" name="institution_id" required>
                                <option value="">Pilih Institusi</option>
                                <?php foreach ($institutions as $inst): ?>
                                    <option value="<?php echo htmlspecialchars($inst['id']); ?>"
                                        <?php echo ($prodi_data['institution_id'] == $inst['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($inst['nama_pt']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($institutions)): ?>
                                <small class="text-danger">Belum ada institusi terdaftar. Tambahkan institusi terlebih dahulu.</small>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="program_name" class="form-label">Nama Program Studi</label>
                            <input type="text" class="form-control" id="program_name" name="program_name" value="<?php echo htmlspecialchars($prodi_data['program_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="level" class="form-label">Level (Contoh: S1, D3, S2)</label>
                            <input type="text" class="form-control" id="level" name="level" value="<?php echo htmlspecialchars($prodi_data['level']); ?>" required>
                        </div>
                        <button type="submit" name="update_prodi" class="btn btn-primary">Update Program Studi</button>
                        <a href="manage_prodi.php" class="btn btn-secondary">Batal</a>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                Pilih program studi dari daftar untuk mengeditnya. <a href="manage_prodi.php">Kembali ke Data Program Studi</a>
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