<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path ke file database.php

// Proteksi halaman: hanya dosen (role_id = 2) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: ../../login.php?error=Akses tidak diizinkan. Silakan login sebagai Dosen.');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];
$is_asesor = $_SESSION['is_asesor'] ?? 0; // Ambil status is_asesor dari session, default 0 jika tidak ada

// Inisialisasi variabel statistik
$total_courses_diampu = 0;
$total_mahasiswa_diajar = 0;
$total_rps_dinilai = 0;

// Inisialisasi variabel untuk dimensi pribadi dosen
$personal_dimension_name = 'Belum Mengukur';
$personal_overall_score = 0.00;

// Inisialisasi array untuk data mata kuliah dengan dimensi RPS
$courses_with_assessment_data = [];

try {
    // --- Statistik: Jumlah Mata Kuliah Diampu ---
    $stmt_courses = $pdo->prepare("
        SELECT COUNT(dc.course_id) AS total_courses
        FROM dosen_courses dc
        WHERE dc.dosen_id = :dosen_id
    ");
    $stmt_courses->execute([':dosen_id' => $user_id]);
    $result_courses = $stmt_courses->fetch(PDO::FETCH_ASSOC);
    $total_courses_diampu = $result_courses['total_courses'];

    // --- Statistik: Jumlah Mahasiswa yang Diajar ---
    $stmt_students = $pdo->prepare("
        SELECT COUNT(DISTINCT mc.mahasiswa_id) AS total_students
        FROM mahasiswa_courses mc
        JOIN dosen_courses dc ON mc.course_id = dc.course_id
        WHERE dc.dosen_id = :dosen_id
    ");
    $stmt_students->execute([':dosen_id' => $user_id]);
    $result_students = $stmt_students->fetch(PDO::FETCH_ASSOC);
    $total_mahasiswa_diajar = $result_students['total_students'];

    // --- Statistik: Jumlah RPS yang Dinilai (khusus untuk Asesor) ---
    if ($is_asesor) {
        $stmt_rps_dinilai = $pdo->prepare("
            SELECT COUNT(DISTINCT ra.course_id) AS total_rps_evaluated
            FROM rps_assessments ra
            WHERE ra.assessor_user_id = :assessor_user_id
        ");
        $stmt_rps_dinilai->execute([':assessor_user_id' => $user_id]);
        $result_rps_dinilai = $stmt_rps_dinilai->fetch(PDO::FETCH_ASSOC);
        $total_rps_dinilai = $result_rps_dinilai['total_rps_evaluated'];
    }

    // --- Ambil Detail Nilai Dimensi Evaluasi Pribadi Dosen (dari self_assessments_3m) ---
    $stmt_personal_assessment = $pdo->prepare("
        SELECT
            sa.overall_3m_score,
            td.dimension_name
        FROM self_assessments_3m sa
        LEFT JOIN temus_dimensions td ON sa.classified_temus_id = td.id
        WHERE sa.user_id = :user_id
        ORDER BY sa.assessment_date DESC, sa.created_at DESC
        LIMIT 1
    ");
    $stmt_personal_assessment->execute([':user_id' => $user_id]);
    $personal_assessment_data = $stmt_personal_assessment->fetch(PDO::FETCH_ASSOC);

    if ($personal_assessment_data) {
        $personal_overall_score = (float)$personal_assessment_data['overall_3m_score'];
        $personal_dimension_name = $personal_assessment_data['dimension_name'] ?: 'Tidak Terdefinisi';
    }

    // --- Ambil Data Mata Kuliah yang Diampu Beserta Dimensi Hasil Asesmen RPS (dari rps_assessments) ---
    // PERBAIKAN: Mengganti ra.overall_score menjadi ra.overall_3m_score
    $stmt_courses_with_dim = $pdo->prepare("
        SELECT
            c.id AS course_id,
            c.course_name,
            td.dimension_name AS classified_dimension,
            ra.overall_3m_score AS rps_overall_score  /* <--- Perbaikan di sini */
        FROM dosen_courses dc
        JOIN courses c ON dc.course_id = c.id
        LEFT JOIN (
            SELECT
                course_id,
                MAX(assessment_date) AS latest_assessment_date,
                MAX(created_at) AS latest_created_at
            FROM rps_assessments
            GROUP BY course_id
        ) AS latest_rps ON c.id = latest_rps.course_id
        LEFT JOIN rps_assessments ra ON c.id = ra.course_id AND ra.assessment_date = latest_rps.latest_assessment_date AND ra.created_at = latest_rps.latest_created_at
        LEFT JOIN temus_dimensions td ON ra.classified_temus_id = td.id
        WHERE dc.dosen_id = :dosen_id
        ORDER BY c.course_name
    ");
    $stmt_courses_with_dim->execute([':dosen_id' => $user_id]);
    $courses_with_assessment_data = $stmt_courses_with_dim->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Log error untuk debugging.
    error_log("Error fetching dashboard data in dosen_dashboard.php: " . $e->getMessage());
    // Tidak lagi menampilkan pesan error ke user langsung di frontend produksi
    // Agar lebih bersih, dan error tetap tercatat di log server.

    $total_courses_diampu = "N/A";
    $total_mahasiswa_diajar = "N/A";
    $total_rps_dinilai = "N/A";
    $personal_dimension_name = "Error loading data";
    $personal_overall_score = 0.00;
    $courses_with_assessment_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Dosen - MATLEV 5D</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .navbar-brand {
            font-weight: bold;
        }
        #content {
            flex-grow: 1;
            padding: 20px;
        }
        .info-card {
            background-color: #ffffff;
            border-left: 5px solid #007bff;
            border-radius: 5px;
            padding: 15px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .info-card:hover {
            transform: translateY(-5px);
        }
        .info-card h5 {
            color: #007bff;
            font-weight: bold;
        }
        .info-card p {
            font-size: 1.2em;
            margin-bottom: 0;
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
        /* Style baru untuk display dimensi pribadi */
        .personal-dimension-card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            border-left: 5px solid #6f42c1; /* Warna ungu untuk menonjolkan */
            text-align: center;
        }
        .personal-dimension-card h4 {
            color: #6f42c1; /* Warna ungu */
            font-weight: bold;
            margin-bottom: 10px;
        }
        .personal-dimension-card .dimension-name {
            font-size: 2.5em;
            font-weight: bold;
            color: #007bff; /* Biru terang untuk nama dimensi */
            margin-bottom: 5px;
        }
        .personal-dimension-card .dimension-score {
            font-size: 1.5em;
            color: #555;
        }
        .personal-dimension-card .no-data-message {
            font-size: 1.1em;
            color: #6c757d;
        }
        .table-responsive {
            margin-top: 20px;
        }
    </style>
</head>
<body>

    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link active" href="dosen_dashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="dosen_profile.php">
                    <i class="fas fa-fw fa-user me-2"></i>Profil
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="evaluasiDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-fw fa-clipboard-list me-2"></i>Evaluasi
                </a>
                <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="evaluasiDropdown">
                    <li><a class="dropdown-item" href="dosen_evaluasi_mandiri.php">Evaluasi Dosen Mandiri</a></li>
                    <?php if ($is_asesor): // Hanya asesor yang bisa melihat menu ini ?>
                    <li><a class="dropdown-item" href="dosen_evaluasi_rps.php">Evaluasi RPS (Asesor)</a></li>
                    <?php endif; ?>
                </ul>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="dosen_asesor_menu.php">
                    <i class="fas fa-fw fa-upload me-2"></i>Manajemen RPS
                </a>
            </li>
			<li class="nav-item">
                <a class="nav-link" href="dosen_effectiveness_results.php">
                    <i class="fas fa-fw fa-chart-bar me-2"></i>Hasil Evaluasi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="dosen_sus_assessment.php">
                    <i class="fas fa-fw fa-star me-2"></i>Evaluasi Platform
                </a>
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
                <a class="navbar-brand" href="#">Dashboard Dosen</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role_name); ?>)
                </span>
            </div>
        </nav>

        <div class="row">
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="info-card">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-book-open fa-3x text-primary me-3"></i>
                        <div>
                            <h5>Mata Kuliah Diampu</h5>
                            <p class="h3"><?php echo htmlspecialchars($total_courses_diampu); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 mb-4">
                <div class="info-card" style="border-left-color: #28a745;">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-users fa-3x text-success me-3"></i>
                        <div>
                            <h5 style="color: #28a745;">Mahasiswa Diajar</h5>
                            <p class="h3"><?php echo htmlspecialchars($total_mahasiswa_diajar); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 mb-4">
                <div class="info-card" style="border-left-color: #ffc107;">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-chart-line fa-3x text-warning me-3"></i>
                        <div>
                            <h5 style="color: #ffc107;">Evaluasi RPS (Asesor)</h5>
                            <p class="h3">
                                <?php echo $is_asesor ? htmlspecialchars($total_rps_dinilai) . ' RPS' : 'N/A'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        ---

        <div class="row mt-4">
            <div class="col-12">
                <div class="personal-dimension-card">
                    <h4>Tingkat Kematangan Digitalisasi Pembelajaran Pribadi Anda</h4>
                    <?php if ($personal_overall_score > 0): ?>
                        <div class="dimension-name"><?php echo htmlspecialchars($personal_dimension_name); ?></div>
                        <div class="dimension-score">Skor Rata-rata: <?php echo htmlspecialchars(number_format($personal_overall_score, 2)); ?></div>
                        <small class="text-muted">Berdasarkan evaluasi mandiri terakhir Anda.</small>
                    <?php else: ?>
                        <div class="no-data-message">
                            Belum ada data evaluasi mandiri yang ditemukan.
                            <br>Silakan lakukan <a href="dosen_evaluasi_mandiri.php">Evaluasi Dosen Mandiri</a> untuk melihat tingkat kematangan digitalisasi pembelajaran Anda.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        ---

        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-table me-2"></i>Hasil Asesmen Dimensi RPS Mata Kuliah yang Diampu</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($courses_with_assessment_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>No.</th>
                                            <th>Nama Mata Kuliah</th>
                                            <th>Dimensi RPS Terklasifikasi</th>
                                            <th>Skor Overall RPS</th>
                                            <th>Status Asesmen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; ?>
                                        <?php foreach ($courses_with_assessment_data as $course): ?>
                                            <tr>
                                                <td><?php echo $no++; ?>.</td>
                                                <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                                <td>
                                                    <?php echo $course['classified_dimension'] ? htmlspecialchars($course['classified_dimension']) : 'Belum di Asesmen'; ?>
                                                </td>
                                                <td>
                                                    <?php echo $course['rps_overall_score'] !== null ? htmlspecialchars(number_format((float)$course['rps_overall_score'], 2)) : 'N/A'; ?>
                                                </td>
                                                <td>
                                                    <?php if ($course['classified_dimension']): ?>
                                                        <span class="badge bg-success">Sudah Dinilai</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Belum di Asesmen</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info text-center" role="alert">
                                <i class="fas fa-info-circle me-2"></i>Tidak ada mata kuliah yang diampu atau data asesmen RPS yang ditemukan.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>