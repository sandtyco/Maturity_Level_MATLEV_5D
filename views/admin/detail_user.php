<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path jika berbeda

// Proteksi halaman: hanya admin (role_id = 1) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];

$user_id = $_GET['id'] ?? null; // Ambil ID pengguna dari URL
$user_data = [];
$detail_data = []; // Untuk menyimpan data dosen_details atau mahasiswa_details
$assigned_courses = []; // Untuk menyimpan mata kuliah yang diampu/diambil

if (!$user_id) {
    // Redirect jika tidak ada ID pengguna di URL
    header('Location: manage_users.php?status=no_id_detail');
    exit();
}

try {
    // 1. Ambil data dasar pengguna dari tabel users
    $stmt_user = $pdo->prepare("SELECT u.id, u.username, u.is_assessor, r.role_name, r.id as role_id FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :user_id");
    $stmt_user->execute([':user_id' => $user_id]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        // Redirect jika pengguna tidak ditemukan
        header('Location: manage_users.php?status=not_found_detail');
        exit();
    }

    // 2. Ambil data detail sesuai role
    if ($user_data['role_id'] == 2) { // Dosen
        $stmt_detail = $pdo->prepare("SELECT dd.*, inst.nama_pt AS institution_name, pos.program_name AS program_of_study_name FROM dosen_details dd LEFT JOIN institutions inst ON dd.institution_id = inst.id LEFT JOIN programs_of_study pos ON dd.program_of_study_id = pos.id WHERE dd.user_id = :user_id");
        $stmt_detail->execute([':user_id' => $user_id]);
        $detail_data = $stmt_detail->fetch(PDO::FETCH_ASSOC);

        // Ambil mata kuliah yang diampu dosen
        $stmt_courses = $pdo->prepare("SELECT c.course_name FROM dosen_courses dc JOIN courses c ON dc.course_id = c.id WHERE dc.dosen_id = :dosen_id");
        $stmt_courses->execute([':dosen_id' => $user_id]);
        $assigned_courses = $stmt_courses->fetchAll(PDO::FETCH_COLUMN, 0);

    } elseif ($user_data['role_id'] == 3) { // Mahasiswa
        $stmt_detail = $pdo->prepare("SELECT md.*, inst.nama_pt AS institution_name, pos.program_name AS program_of_study_name FROM mahasiswa_details md LEFT JOIN institutions inst ON md.institution_id = inst.id LEFT JOIN programs_of_study pos ON md.program_of_study_id = pos.id WHERE md.user_id = :user_id");
        $stmt_detail->execute([':user_id' => $user_id]);
        $detail_data = $stmt_detail->fetch(PDO::FETCH_ASSOC);

        // Ambil mata kuliah yang diambil mahasiswa
        $stmt_courses = $pdo->prepare("SELECT c.course_name FROM mahasiswa_courses mc JOIN courses c ON mc.course_id = c.id WHERE mc.mahasiswa_id = :mahasiswa_id");
        $stmt_courses->execute([':mahasiswa_id' => $user_id]);
        $assigned_courses = $stmt_courses->fetchAll(PDO::FETCH_COLUMN, 0);
    }

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    $user_data = null; // Set null agar tidak mencoba menampilkan data yang error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Pengguna - <?php echo htmlspecialchars($user_data['username'] ?? 'Tidak Ditemukan'); ?></title>
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
        /* Tambahan untuk DataTables agar menyatu dengan Bootstrap */
        .dataTables_wrapper .dataTables_filter input {
            margin-bottom: 10px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.5em 1em;
        }
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding-top: 0.85em;
            padding-bottom: 0.85em;
        }
    </style>
</head>
<body>

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
	
    <div class="container">
	
		<nav class="navbar navbar-expand-lg navbar-light bg-light rounded-3 mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">Halo, <?php echo htmlspecialchars($username); ?>!</a>
                <span class="navbar-text ms-auto">
                    Role: <?php echo htmlspecialchars($role_name); ?>
                </span>
            </div>
        </nav>
		
        <h1>Detail Pengguna: <?php echo htmlspecialchars($user_data['username'] ?? 'Tidak Ditemukan'); ?></h1>

        <?php if ($user_data): ?>
            <div class="card p-3">
                <div class="detail-row">
                    <span class="detail-label">ID Pengguna:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($user_data['id']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Username:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($user_data['username']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Role:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($user_data['role_name']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Sebagai Asesor?:</span>
                    <span class="detail-value"><?php echo ($user_data['is_assessor'] ? 'Ya' : 'Tidak'); ?></span>
                </div>

                <?php if (!empty($detail_data)): ?>
                    <hr>
                    <h4>Informasi Detail</h4>
                    <div class="detail-row">
                        <span class="detail-label">Nama Lengkap:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($detail_data['full_name'] ?? '-'); ?></span>
                    </div>
                    <?php if ($user_data['role_id'] == 2): // Dosen ?>
                        <div class="detail-row">
                            <span class="detail-label">NIDN:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($detail_data['nidn'] ?? '-'); ?></span>
                        </div>
                    <?php elseif ($user_data['role_id'] == 3): // Mahasiswa ?>
                        <div class="detail-row">
                            <span class="detail-label">NIM:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($detail_data['nim'] ?? '-'); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <span class="detail-label">Institusi:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($detail_data['institution_name'] ?? '-'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Program Studi:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($detail_data['program_of_study_name'] ?? '-'); ?></span>
                    </div>

                    <?php if (!empty($assigned_courses)): ?>
                        <div class="detail-row">
                            <span class="detail-label">Mata Kuliah:</span>
                            <span class="detail-value">
                                <ul>
                                    <?php foreach ($assigned_courses as $course): ?>
                                        <li><?php echo htmlspecialchars($course); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </span>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <hr>
                    <p class="text-muted">Tidak ada detail tambahan untuk peran ini atau data tidak ditemukan.</p>
                <?php endif; ?>

            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center">Pengguna tidak ditemukan atau terjadi kesalahan.</div>
        <?php endif; ?>

        <div class="back-link">
            <a href="manage_users.php" class="btn btn-secondary mt-3">Kembali ke Data Pengguna</a>
            <?php if ($user_data): // Tambahkan tombol edit jika data pengguna ditemukan ?>
                <a href="edit_user.php?id=<?php echo htmlspecialchars($user_data['id']); ?>" class="btn btn-warning text-white mt-3 ms-2">Edit Pengguna</a>
            <?php endif; ?>
        </div>
		
		<div class="card mt-4 p-4">
            <h5>Informasi Tambahan</h5>
            <p>Selamat datang di panel administrasi. Anda memiliki kendali penuh atas Data Pengguna, institusi, program studi, dan mata kuliah.</p>
            <p>Gunakan menu di samping kiri untuk navigasi.</p>
        </div>
        
        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
        </div>
		
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>