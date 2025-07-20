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

// --- Mengambil dan Menghapus Pesan dari Session ---
$message = ''; 
$error = '';   

if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); 
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']); 
}

// --- LOGIKA UNTUK UPDATE DIMENSI (Semua Sekaligus) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dimensions'])) {
    // Pastikan input adalah array
    if (isset($_POST['dimension_id']) && is_array($_POST['dimension_id'])) {
        $updated_count = 0;
        foreach ($_POST['dimension_id'] as $index => $id) {
            $dimension_id = (int)$id;
            // Pastikan nilai score adalah numerik dan bukan kosong
            $min_score = filter_var($_POST['min_score'][$index], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            $max_score = filter_var($_POST['max_score'][$index], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

            // Jika ada nilai yang tidak valid, anggap 0 atau berikan error
            if ($min_score === null) $min_score = 0.0;
            if ($max_score === null) $max_score = 0.0;
            
            // Validasi tambahan: min_score tidak boleh lebih besar dari max_score
            if ($min_score > $max_score) {
                $error .= "Nilai Minimum untuk dimensi ID " . htmlspecialchars($dimension_id) . " tidak boleh lebih besar dari Nilai Maksimum.<br>";
                continue; // Lanjutkan ke iterasi berikutnya
            }

            try {
                $stmt = $pdo->prepare("UPDATE temus_dimensions SET min_score = ?, max_score = ? WHERE id = ?");
                $stmt->execute([$min_score, $max_score, $dimension_id]);
                $updated_count += $stmt->rowCount(); // Hitung berapa baris yang terpengaruh
            } catch (PDOException $e) {
                // Catat error ke log dan berikan pesan ke user
                error_log("Error updating dimension ID " . $dimension_id . ": " . $e->getMessage());
                $error .= "Error saat memperbarui dimensi ID " . htmlspecialchars($dimension_id) . ": " . $e->getMessage() . "<br>";
            }
        }

        if (empty($error)) {
            $_SESSION['success_message'] = "Perubahan pada " . $updated_count . " dimensi berhasil disimpan.";
        } else {
            $_SESSION['error_message'] = "Beberapa perubahan mungkin tidak disimpan: <br>" . $error;
        }
    } else {
        $_SESSION['error_message'] = "Tidak ada data dimensi yang diterima untuk diperbarui.";
    }
    header('Location: setting_dimensions.php');
    exit();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR DIMENSI ---
$dimensions = [];
try {
    $stmt = $pdo->query("SELECT id, dimension_name, description, min_score, max_score FROM temus_dimensions ORDER BY id ASC"); // Order by ID untuk konsistensi TEMUS
    $dimensions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Dimensi: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Dimensi TEMUS - Admin MATLEV 5D</title>
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
                <a class="navbar-brand" href="#">Manajemen Dimensi TEMUS</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role_name); ?>)
                </span>
            </div>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Pengaturan Nilai Dimensi TEMUS</h5>
            </div>
            <div class="card-body">
                <?php if (empty($dimensions)): ?>
                    <p>Belum ada dimensi yang terdaftar di database. Pastikan tabel `temus_dimensions` sudah terisi.</p>
                <?php else: ?>
                    <form action="" method="POST">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nama Dimensi</th>
                                        <th>Deskripsi</th>
                                        <th>Nilai Min</th>
                                        <th>Nilai Max</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dimensions as $dimension): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($dimension['id']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($dimension['dimension_name']); ?>
                                                <input type="hidden" name="dimension_id[]" value="<?php echo htmlspecialchars($dimension['id']); ?>">
                                            </td>
                                            <td><?php echo htmlspecialchars($dimension['description']); ?></td>
                                            <td>
                                                <input type="number" step="0.01" class="form-control" name="min_score[]" 
                                                       value="<?php echo htmlspecialchars($dimension['min_score']); ?>" required>
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" class="form-control" name="max_score[]" 
                                                       value="<?php echo htmlspecialchars($dimension['max_score']); ?>" required>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="submit" name="update_dimensions" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Menghilangkan pesan alert setelah beberapa detik (opsional)
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000); // Pesan hilang setelah 5 detik
        });
    </script>
</body>
</html>