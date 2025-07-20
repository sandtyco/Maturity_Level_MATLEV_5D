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

// Inisialisasi variabel statistik
$total_dosen = 0;
$total_mahasiswa = 0;
$total_institusi = 0;
$total_program_studi = 0;
$total_courses = 0;
$total_users = 0;

try {
    // Ambil statistik jumlah Dosen
    $stmt_dosen = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = 2");
    $total_dosen = $stmt_dosen->fetchColumn();

    // Ambil statistik jumlah Mahasiswa
    $stmt_mahasiswa = $pdo->query("SELECT COUNT(*) FROM users WHERE role_id = 3");
    $total_mahasiswa = $stmt_mahasiswa->fetchColumn();

    // Ambil statistik jumlah Institusi
    $stmt_institusi = $pdo->query("SELECT COUNT(*) FROM institutions");
    $total_institusi = $stmt_institusi->fetchColumn();

    // Ambil statistik jumlah Program Studi
    $stmt_program_studi = $pdo->query("SELECT COUNT(*) FROM programs_of_study");
    $total_program_studi = $stmt_program_studi->fetchColumn();

    // Ambil statistik jumlah Courses
    $stmt_courses = $pdo->query("SELECT COUNT(*) FROM courses");
    $total_courses = $stmt_courses->fetchColumn();

    // Ambil total pengguna
    $stmt_users = $pdo->query("SELECT COUNT(*) FROM users");
    $total_users = $stmt_users->fetchColumn();

} catch (PDOException $e) {
    // Tampilkan pesan error jika terjadi masalah database
    $error_message = "<div class='alert alert-danger'>Error mengambil data statistik: " . $e->getMessage() . "</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
	<link rel="icon" href="../../assets/img/favicon.png" type="image/x-icon">
    <style>
        body {
            display: flex; /* Menggunakan flexbox untuk layout sidebar dan konten */
            min-height: 100vh; /* Memastikan body mengisi seluruh tinggi viewport */
            background-color: #f8f9fa;
        }
        #sidebar {
            width: 250px;
            background-color: #343a40; /* Dark background for sidebar */
            color: white;
            padding: 20px;
            flex-shrink: 0; /* Mencegah sidebar menyusut */
        }
        #sidebar a {
            color: white;
            text-decoration: none;
            padding: 10px 0;
            display: block;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        #sidebar a:hover {
            background-color: #495057; /* Darker on hover */
        }
        #content {
            flex-grow: 1; /* Konten mengisi sisa ruang */
            padding: 20px;
        }
        .navbar-brand {
            font-weight: bold;
            margin-bottom: 20px;
        }
        .card-stats {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        .card-stats:hover {
            transform: translateY(-5px);
        }
        .card-stats .icon {
            font-size: 3rem;
            margin-bottom: 10px;
            color: #007bff;
        }
        .card-stats h4 {
            font-size: 1.2rem;
            color: #6c757d;
        }
        .card-stats p {
            font-size: 2rem;
            font-weight: bold;
            color: #343a40;
        }
    </style>
</head>
<body>

	<!-- bagian awal tampilan sidebar -->
    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link active" href="admin_dashboard.php">
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
	<!-- bagian akhir tampilan sidebar -->
	
	<!-- bagian sisi kanan isi konten -->
    <div id="content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light rounded-3 mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">Halo, <?php echo htmlspecialchars($username); ?>!</a>
                <span class="navbar-text ms-auto">
                    Role: <?php echo htmlspecialchars($role_name); ?>
                </span>
            </div>
        </nav>

        <?php if (isset($error_message)) echo $error_message; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card-stats">
                    <div class="icon text-primary"><i class="fas fa-users"></i></div>
                    <h4>Total Pengguna</h4>
                    <p><?php echo $total_users; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-stats">
                    <div class="icon text-success"><i class="fas fa-chalkboard-teacher"></i></div>
                    <h4>Total Dosen</h4>
                    <p><?php echo $total_dosen; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-stats">
                    <div class="icon text-info"><i class="fas fa-user-graduate"></i></div>
                    <h4>Total Mahasiswa</h4>
                    <p><?php echo $total_mahasiswa; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-stats">
                    <div class="icon text-warning"><i class="fas fa-university"></i></div>
                    <h4>Total Institusi</h4>
                    <p><?php echo $total_institusi; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-stats">
                    <div class="icon text-danger"><i class="fas fa-book-open"></i></div>
                    <h4>Total Program Studi</h4>
                    <p><?php echo $total_program_studi; ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-stats">
                    <div class="icon text-secondary"><i class="fas fa-book"></i></div>
                    <h4>Total Mata Kuliah</h4>
                    <p><?php echo $total_courses; ?></p>
                </div>
            </div>
        </div>

        <div class="card mt-4 p-4">
            <h5>Informasi Tambahan</h5>
            <p>Selamat datang di panel administrasi. Anda memiliki kendali penuh atas Data Pengguna, institusi, program studi, dan mata kuliah.</p>
            <p>Gunakan menu di samping kiri untuk navigasi.</p>
        </div>

		<!-- bagian footer -->
		<footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
			<h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
		</footer>
		<!-- bagian footer -->
		
    </div>
	<!-- akhir bagian sisi kanan isi konten -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
</body>
</html>