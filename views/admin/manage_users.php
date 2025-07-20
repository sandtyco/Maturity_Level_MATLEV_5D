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

$message = '';
// Tangani pesan status dari URL (misalnya dari edit_user.php atau detail_user.php)
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success_update') {
        $message = '<div class="alert alert-success">Perubahan pengguna berhasil disimpan!</div>';
    } elseif ($_GET['status'] == 'no_id_detail') { // Status baru dari detail_user.php
        $message = '<div class="alert alert-warning">ID pengguna tidak disertakan untuk melihat detail.</div>';
    } elseif ($_GET['status'] == 'not_found_detail') { // Status baru dari detail_user.php
        $message = '<div class="alert alert-danger">Detail pengguna tidak ditemukan.</div>';
    } elseif ($_GET['status'] == 'no_id') {
        $message = '<div class="alert alert-warning">ID pengguna tidak ditemukan untuk diedit.</div>';
    } elseif ($_GET['status'] == 'not_found') {
        $message = '<div class="alert alert-danger">Pengguna tidak ditemukan.</div>';
    }
    // Anda bisa tambahkan pesan untuk status lain jika ada, misal untuk delete
    // elseif ($_GET['status'] == 'delete_success') {
    //     $message = '<div class="alert alert-success">Pengguna berhasil dihapus!</div>';
    // }
}

$users = [];
try {
    // Query untuk mengambil semua pengguna dan detail terkait mereka (dosen/mahasiswa)
    $stmt = $pdo->query("
        SELECT
            u.id AS user_id,
            u.username,
            r.role_name,
            u.is_assessor,
            dd.full_name AS dosen_name,
            dd.nidn AS dosen_nidn,
            md.full_name AS mhs_name,
            md.nim AS mhs_nim
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN dosen_details dd ON u.id = dd.user_id AND u.role_id = 2
        LEFT JOIN mahasiswa_details md ON u.id = md.user_id AND u.role_id = 3
        ORDER BY u.username
    ");
    $users = $stmt->fetchAll();

} catch (PDOException $e) {
    // Tampilkan pesan error dengan Bootstrap alert
    $message = "<div class='alert alert-danger text-center mt-3' role='alert'>Error mengambil data pengguna: " . $e->getMessage() . "</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Pengguna - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.datatables.net/v/bs5/dt-2.3.2/b-3.2.3/b-print-3.2.3/datatables.min.css" rel="stylesheet" integrity="sha384-7hBlFRKMwxBCvczRRz+3wfQgCaTazT2KZEKcj6JIJJSyO3td3HyaxIO1PIrMHyGe" crossorigin="anonymous">
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
	<!-- bagian tampilan sidebar -->
	<div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
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
            <h1>Data Pengguna</h1>

			<?php echo $message; // Menampilkan pesan status/error di sini ?>

			<div class="action-buttons">
				<a href="add_user.php" class="btn btn-success">Tambah Pengguna Baru</a>
			</div>

			<?php if (!empty($users)): ?>
				<table id="userTable" class="table table-striped table-bordered" style="width:100%">
					<thead>
						<tr>
							<th>Username</th>
							<th>Role</th>
							<th>Nama Lengkap</th>
							<th>NIDN/NIM</th>
							<th>Asesor?</th>
							<th>Aksi</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($users as $user): ?>
							<tr>
								<td>
									<a href="detail_user.php?id=<?php echo htmlspecialchars($user['user_id']); ?>">
										<?php echo htmlspecialchars($user['username']); ?>
									</a>
								</td>
								<td><?php echo htmlspecialchars($user['role_name']); ?></td>
								<td>
									<?php
									// Tampilkan nama lengkap sesuai role
									if ($user['role_name'] == 'Dosen') {
										echo htmlspecialchars($user['dosen_name'] ?? '-');
									} elseif ($user['role_name'] == 'Mahasiswa') {
										echo htmlspecialchars($user['mhs_name'] ?? '-');
									} else {
										echo '-'; // Untuk Admin atau role lain
									}
									?>
								</td>
								<td>
									<?php
									// Tampilkan NIDN/NIM sesuai role
									if ($user['role_name'] == 'Dosen') {
										echo htmlspecialchars($user['dosen_nidn'] ?? '-');
									} elseif ($user['role_name'] == 'Mahasiswa') {
										echo htmlspecialchars($user['mhs_nim'] ?? '-');
									} else {
										echo '-'; // Untuk Admin atau role lain
									}
									?>
								</td>
								<td><?php echo $user['is_assessor'] ? 'Ya' : 'Tidak'; ?></td>
								<td class="action-links">
									<a href="edit_user.php?id=<?php echo htmlspecialchars($user['user_id']); ?>" class="btn btn-sm btn-info text-white me-2">Edit</a>

									<form action="delete_user.php" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pengguna ini? Semua data terkait juga akan dihapus.');">
										<input type="hidden" name="id" value="<?php echo htmlspecialchars($user['user_id']); ?>">
										<button type="submit" class="btn btn-sm btn-danger">Hapus</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<p class="text-center mt-3">Tidak ada pengguna ditemukan.</p>
			<?php endif; ?>

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
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    
    <script src="https://cdn.datatables.net/v/bs5/dt-2.3.2/b-3.2.3/b-print-3.2.3/datatables.min.js" integrity="sha384-Dxv7CCU6quG+r3TCp/mPGDA5CGhAy1kfkGsWwMTcavb8HNJWVXdPCU8yaxCRTTQK" crossorigin="anonymous"></script>
    
	<script type="text/javascript" src="https://cdn.datatables.net/2.0.8/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#userTable').DataTable();
        });
    </script>
</body>
</html>