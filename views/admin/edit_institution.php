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

$institution_data = null; // Variabel untuk menyimpan data institusi yang akan diedit
$message = ''; // Untuk pesan sukses (digunakan jika tidak redirect)
$error = '';   // Untuk pesan error (digunakan jika tidak redirect)

// --- Ambil ID Institusi dari URL ---
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $institution_id = (int)$_GET['id'];

    try {
        // Ambil data institusi berdasarkan ID
        $stmt = $pdo->prepare("SELECT id, kode_pt, nama_pt, alamat_pt, telp_pt, email_pt FROM institutions WHERE id = ?");
        $stmt->execute([$institution_id]);
        $institution_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$institution_data) {
            $_SESSION['error_message'] = "Institusi tidak ditemukan.";
            // Jika tidak ditemukan, redirect kembali ke manage_institution.php
            header('Location: manage_institution.php');
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error mengambil data institusi: " . $e->getMessage();
        header('Location: manage_institution.php');
        exit();
    }
} else {
    // Jika tidak ada ID, redirect kembali ke manage_institution.php
    header('Location: manage_institution.php');
    exit();
}

// --- LOGIKA UNTUK UPDATE INSTITUSI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_institution'])) {
    $id = (int)$_POST['id']; // Pastikan ID dikirim kembali dari form
    $kode_pt = trim($_POST['kode_pt']);
    $nama_pt = trim($_POST['nama_pt']);
    $alamat_pt = trim($_POST['alamat_pt']);
    $telp_pt = trim($_POST['telp_pt']);
    $email_pt = trim($_POST['email_pt']);

    if (empty($kode_pt) || empty($nama_pt) || empty($alamat_pt) || empty($telp_pt) || empty($email_pt)) {
        $error = "Semua kolom (Kode PT, Nama PT, Alamat, Telepon, Email) harus diisi.";
        // Tidak redirect di sini agar pengguna bisa melihat error dan memperbaiki input
    } elseif ($id !== $institution_id) {
        // Pencegahan potensi tampering ID jika ID di GET dan POST tidak cocok
        $error = "ID institusi tidak cocok.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE institutions SET kode_pt = ?, nama_pt = ?, alamat_pt = ?, telp_pt = ?, email_pt = ? WHERE id = ?");
            $stmt->execute([$kode_pt, $nama_pt, $alamat_pt, $telp_pt, $email_pt, $id]);
            
            if ($stmt->rowCount()) {
                $_SESSION['success_message'] = "Institusi '<strong>" . htmlspecialchars($nama_pt) . "</strong>' berhasil diperbarui.";
            } else {
                $_SESSION['error_message'] = "Tidak ada perubahan yang terdeteksi atau institusi gagal diperbarui.";
            }
            // Redirect kembali ke manage_institution.php setelah update (berhasil/gagal)
            header('Location: manage_institution.php');
            exit();

        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error memperbarui institusi: " . $e->getMessage();
            header('Location: manage_institution.php');
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
    <title>Edit Institusi - Admin MATLEV 5D</title>
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
                <a class="nav-link active" href="manage_institution.php"> <i class="fas fa-fw fa-building me-2"></i>Data Institusi
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
                <a class="navbar-brand" href="#">Edit Institusi</a>
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

        <?php if ($institution_data): // Tampilkan form hanya jika data institusi ditemukan ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Edit Detail Institusi: <?php echo htmlspecialchars($institution_data['nama_pt']); ?></h5>
                </div>
                <div class="card-body">
                    <form action="" method="POST">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($institution_data['id']); ?>">
                        
                        <div class="mb-3">
                            <label for="kode_pt" class="form-label">Kode Perguruan Tinggi</label>
                            <input type="text" class="form-control" id="kode_pt" name="kode_pt" value="<?php echo htmlspecialchars($institution_data['kode_pt']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="nama_pt" class="form-label">Nama Perguruan Tinggi</label>
                            <input type="text" class="form-control" id="nama_pt" name="nama_pt" value="<?php echo htmlspecialchars($institution_data['nama_pt']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="alamat_pt" class="form-label">Alamat Perguruan Tinggi</label>
                            <textarea class="form-control" id="alamat_pt" name="alamat_pt" rows="3" required><?php echo htmlspecialchars($institution_data['alamat_pt']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="telp_pt" class="form-label">Telepon Perguruan Tinggi</label>
                            <input type="tel" class="form-control" id="telp_pt" name="telp_pt" value="<?php echo htmlspecialchars($institution_data['telp_pt']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email_pt" class="form-label">Email Perguruan Tinggi</label>
                            <input type="email" class="form-control" id="email_pt" name="email_pt" value="<?php echo htmlspecialchars($institution_data['email_pt']); ?>" required>
                        </div>
                        <button type="submit" name="update_institution" class="btn btn-primary">Update Institusi</button>
                        <a href="manage_institution.php" class="btn btn-secondary">Batal</a>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                Pilih institusi dari daftar untuk mengeditnya. <a href="manage_institution.php">Kembali ke Data Institusi</a>
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