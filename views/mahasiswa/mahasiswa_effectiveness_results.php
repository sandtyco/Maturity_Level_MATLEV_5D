<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path ke file database.php

// !!! HARAP HAPUS ATAU KOMENTARI BARIS DEBUG INI SETELAH SEMUA FIX DITERAPKAN !!!
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Proteksi halaman: hanya mahasiswa (role_id = 3) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ../../login.php?error=Akses tidak diizinkan. Hanya mahasiswa yang bisa mengakses halaman ini.');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$full_name = $_SESSION['full_name'] ?? 'Mahasiswa';
$role_name = $_SESSION['role_name'];

$current_course_id = null;
$course_name_display = "Pilih Mata Kuliah";

// Ambil daftar mata kuliah yang diikuti mahasiswa
$courses_diikuti = [];
try {
    $stmt_courses_diikuti = $pdo->prepare("
        SELECT c.id, c.course_name
        FROM mahasiswa_courses mc
        JOIN courses c ON mc.course_id = c.id
        WHERE mc.mahasiswa_id = :mahasiswa_id
        ORDER BY c.course_name
    ");
    $stmt_courses_diikuti->execute([':mahasiswa_id' => $user_id]);
    $courses_diikuti = $stmt_courses_diikuti->fetchAll(PDO::FETCH_ASSOC);

    // Jika ada parameter course_id di URL, ambil datanya
    if (isset($_GET['course_id']) && is_numeric($_GET['course_id'])) {
        $selected_course_id = (int)$_GET['course_id'];
        // Cek apakah mahasiswa benar-benar mengikuti mata kuliah ini
        $found = false;
        foreach ($courses_diikuti as $course) {
            if ($course['id'] == $selected_course_id) {
                $current_course_id = $selected_course_id;
                $course_name_display = $course['course_name'];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $current_course_id = null;
            $course_name_display = "Mata Kuliah Tidak Valid";
            $_SESSION['error_message'] = "Mata kuliah yang dipilih tidak valid atau tidak Anda ikuti.";
        }
    }

} catch (PDOException $e) {
    error_log("Error fetching courses for dropdown in mahasiswa_effectiveness_results.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Terjadi kesalahan saat memuat daftar mata kuliah. Silakan coba lagi.";
}

// Inisialisasi variabel untuk semua data yang akan ditampilkan
$rps_dimensi_hasil = "N/A";
$rps_dimensi_description = "Belum dinilai";
$rps_dimension_id_for_rule = null;

$dosen_dimensi_hasil = "N/A";
$dosen_dimensi_description = "Dosen belum melakukan asesmen mandiri";
$dosen_dimension_id_for_rule = null;

$mahasiswa_dimensi_hasil = "N/A";
$mahasiswa_dimensi_description = "Anda belum melakukan asesmen mandiri untuk mata kuliah ini.";
$mahasiswa_dimension_id_for_rule = null;

$effectiveness_status = "Tidak Diketahui";
$effectiveness_description = "Silakan lengkapi asesmen (RPS, Dosen, Mahasiswa) dan pastikan aturan efektivitas telah didefinisikan.";
$badge_class_effectiveness = 'status-tidak-ditemukan';

if ($current_course_id) {
    // 1. Ambil Dimensi RPS Mata Kuliah (berdasarkan course_id)
    try {
        $stmt_rps_dim = $pdo->prepare("
            SELECT td.id AS dimension_id, td.dimension_name, td.description
            FROM rps_assessments ra
            JOIN temus_dimensions td ON ra.classified_temus_id = td.id
            WHERE ra.course_id = :course_id
            ORDER BY ra.assessment_date DESC, ra.created_at DESC
            LIMIT 1
        ");
        $stmt_rps_dim->execute([':course_id' => $current_course_id]);
        $rps_data = $stmt_rps_dim->fetch(PDO::FETCH_ASSOC);

        if ($rps_data) {
            $rps_dimension_id_for_rule = $rps_data['dimension_id'];
            $rps_dimensi_hasil = $rps_data['dimension_name'];
            $rps_dimensi_description = $rps_data['description'];
        } else {
            $rps_dimensi_description = "RPS mata kuliah ini belum dinilai.";
        }
    } catch (PDOException $e) {
        error_log("Error fetching RPS dimension for mahasiswa: " . $e->getMessage());
        $rps_dimensi_hasil = "Error";
        $rps_dimensi_description = "Terjadi kesalahan saat memuat dimensi RPS.";
    }

    // 2. Ambil Dimensi Asesmen Mandiri Dosen (dari dosen pengampu mata kuliah)
    // Pertama, cari tahu siapa dosen pengampu mata kuliah ini
    $dosen_id_for_course = null;
    try {
        $stmt_dosen_course = $pdo->prepare("
            SELECT dosen_id FROM dosen_courses WHERE course_id = :course_id LIMIT 1
        ");
        $stmt_dosen_course->execute([':course_id' => $current_course_id]);
        $dosen_course_data = $stmt_dosen_course->fetch(PDO::FETCH_ASSOC);
        if ($dosen_course_data) {
            $dosen_id_for_course = $dosen_course_data['dosen_id'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching dosen_id for course: " . $e->getMessage());
    }

    if ($dosen_id_for_course) {
        try {
            $stmt_dosen_dim = $pdo->prepare("
                SELECT td.id AS dimension_id, td.dimension_name, td.description
                FROM self_assessments_3m sa
                JOIN temus_dimensions td ON sa.classified_temus_id = td.id
                WHERE sa.user_id = :dosen_id
                AND sa.course_id = :course_id
                AND sa.user_role_at_assessment = 'dosen'
                ORDER BY sa.assessment_date DESC, sa.created_at DESC
                LIMIT 1
            ");
            $stmt_dosen_dim->execute([
                ':dosen_id' => $dosen_id_for_course,
                ':course_id' => $current_course_id
            ]);
            $dosen_data = $stmt_dosen_dim->fetch(PDO::FETCH_ASSOC);

            if ($dosen_data) {
                $dosen_dimension_id_for_rule = $dosen_data['dimension_id'];
                $dosen_dimensi_hasil = $dosen_data['dimension_name'];
                $dosen_dimensi_description = $dosen_data['description'];
            } else {
                $dosen_dimensi_description = "Dosen pengampu mata kuliah ini belum melakukan asesmen mandiri.";
            }
        } catch (PDOException $e) {
            error_log("Error fetching Dosen Self-Assessment dimension for mahasiswa: " . $e->getMessage());
            $dosen_dimensi_hasil = "Error";
            $dosen_dimensi_description = "Terjadi kesalahan saat memuat dimensi asesmen dosen.";
        }
    } else {
        $dosen_dimensi_description = "Tidak ada dosen pengampu yang terdaftar untuk mata kuliah ini.";
    }

    // 3. Ambil Dimensi Asesmen Mandiri Mahasiswa (miliknya sendiri)
    try {
        $stmt_mahasiswa_dim = $pdo->prepare("
            SELECT td.id AS dimension_id, td.dimension_name, td.description
            FROM self_assessments_3m sa
            JOIN temus_dimensions td ON sa.classified_temus_id = td.id
            WHERE sa.user_id = :user_id
            AND sa.course_id = :course_id
            AND sa.user_role_at_assessment = 'mahasiswa'
            ORDER BY sa.assessment_date DESC, sa.created_at DESC
            LIMIT 1
        ");
        $stmt_mahasiswa_dim->execute([
            ':user_id' => $user_id,
            ':course_id' => $current_course_id
        ]);
        $mahasiswa_data = $stmt_mahasiswa_dim->fetch(PDO::FETCH_ASSOC);

        if ($mahasiswa_data) {
            $mahasiswa_dimension_id_for_rule = $mahasiswa_data['dimension_id'];
            $mahasiswa_dimensi_hasil = $mahasiswa_data['dimension_name'];
            $mahasiswa_dimensi_description = $mahasiswa_data['description'];
        } else {
            $mahasiswa_dimensi_description = "Anda belum melakukan asesmen mandiri untuk mata kuliah ini.";
        }
    } catch (PDOException $e) {
        error_log("Error fetching Mahasiswa Self-Assessment dimension: " . $e->getMessage());
        $mahasiswa_dimensi_hasil = "Error";
        $mahasiswa_dimensi_description = "Terjadi kesalahan saat memuat dimensi asesmen mandiri Anda.";
    }

    // 4. Cari Aturan Efektivitas Berdasarkan 3 Dimensi
    if ($rps_dimension_id_for_rule && $dosen_dimension_id_for_rule && $mahasiswa_dimension_id_for_rule) {
        try {
            $stmt_effectiveness = $pdo->prepare("
                SELECT ea.effectiveness_status, ea.description
                FROM final_effectiveness_rules fer
                JOIN effectiveness_actions ea ON fer.effectiveness_action_id = ea.id
                WHERE fer.rps_dimension_id = :rps_dim_id
                AND fer.dosen_dimension_id = :dosen_dim_id
                AND fer.mahasiswa_dimension_id = :mahasiswa_dim_id
                LIMIT 1
            ");
            $stmt_effectiveness->execute([
                ':rps_dim_id' => $rps_dimension_id_for_rule,
                ':dosen_dim_id' => $dosen_dimension_id_for_rule,
                ':mahasiswa_dim_id' => $mahasiswa_dimension_id_for_rule
            ]);
            $effectiveness_data = $stmt_effectiveness->fetch(PDO::FETCH_ASSOC);

            if ($effectiveness_data) {
                $effectiveness_status = $effectiveness_data['effectiveness_status'];
                $effectiveness_description = $effectiveness_data['description'];
                
                // Set badge class for effectiveness
                switch ($effectiveness_status) {
                    case 'Sangat Efektif':
                        $badge_class_effectiveness = 'status-sangat-efektif';
                        break;
                    case 'Cukup Efektif':
                        $badge_class_effectiveness = 'status-cukup-efektif';
                        break;
                    case 'Tidak Efektif':
                        $badge_class_effectiveness = 'status-tidak-efektif';
                        break;
                    default:
                        $badge_class_effectiveness = 'status-tidak-ditemukan';
                        break;
                }
            } else {
                $effectiveness_status = "Aturan Tidak Ditemukan";
                $effectiveness_description = "Kombinasi dimensi Anda, RPS, dan Dosen belum memiliki aturan efektivitas yang didefinisikan. Mohon hubungi Administrator.";
                $badge_class_effectiveness = 'status-tidak-ditemukan';
            }
        } catch (PDOException $e) {
            error_log("Error fetching effectiveness rule: " . $e->getMessage());
            $effectiveness_status = "Error";
            $effectiveness_description = "Terjadi kesalahan saat mencari aturan efektivitas. Silakan coba lagi.";
            $badge_class_effectiveness = 'status-tidak-ditemukan';
        }
    } else {
        $effectiveness_status = "Data Tidak Lengkap";
        $effectiveness_description = "Anda perlu melengkapi asesmen mandiri Anda, dan/atau RPS serta Dosen pengampu perlu melakukan penilaian agar hasil efektivitas dapat dihitung.";
        $badge_class_effectiveness = 'status-tidak-ditemukan';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Efektivitas Pembelajaran | Mahasiswa - MATLEV 5D</title>
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
        .dropdown-menu-dark {
            background-color: #495057;
            border: 1px solid rgba(0, 0, 0, 0.15);
        }
        .dropdown-menu-dark .dropdown-item {
            color: white;
        }
        .dropdown-menu-dark .dropdown-item:hover,
        .dropdown-menu-dark .dropdown-item:focus {
            background-color: #6c757d;
        }
        .card-custom {
            min-height: 200px; /* Adjust height as needed */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
        }
        .card-custom .card-title {
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        .card-custom h4 {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .card-custom p {
            font-size: 0.9em;
            color: #6c757d;
        }
        .card-custom hr {
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .effectiveness-summary-card {
            background: linear-gradient(135deg, #e6f7ff, #d0efff);
            border-left: 8px solid #007bff;
            border-radius: .75rem;
            box-shadow: 0 5px 20px rgba(0,0,0,.15);
            transition: all 0.3s ease;
        }
        .effectiveness-summary-card .card-header {
            background-color: transparent;
            border-bottom: none;
            color: #343a40;
            font-size: 1.4em;
            font-weight: bold;
        }
        .effectiveness-summary-card .card-body {
            padding-top: 0;
            color: #495057;
        }
        .effectiveness-summary-card .highlight-status {
            font-size: 2.8em;
            font-weight: bold;
            margin-bottom: 15px;
            text-align: center;
            padding: 15px 0;
            border-radius: 10px;
            color: #fff;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
            transition: background-color 0.3s ease;
        }
        /* Warna untuk highlight status di summary card */
        .effectiveness-summary-card .highlight-status.status-sangat-efektif { background-color: #198754; } /* Green */
        .effectiveness-summary-card .highlight-status.status-cukup-efektif { background-color: #ffc107; color: #212529; } /* Yellow */
        .effectiveness-summary-card .highlight-status.status-tidak-efektif { background-color: #dc3545; } /* Red */
        .effectiveness-summary-card .highlight-status.status-tidak-ditemukan { background-color: #6c757d; } /* Grey */

        /* Border color based on effectiveness status */
        .effectiveness-summary-card.status-sangat-efektif { border-left-color: #198754; }
        .effectiveness-summary-card.status-cukup-efektif { border-left-color: #ffc107; }
        .effectiveness-summary-card.status-tidak-efektif { border-left-color: #dc3545; }
        .effectiveness-summary-card.status-tidak-ditemukan { border-left-color: #6c757d; }

        .lead-description {
            font-size: 1.1em;
            text-align: center;
            margin-bottom: 15px;
        }
        .italic-description {
            font-style: italic;
            text-align: center;
            color: #5a6268;
        }
    </style>
</head>
<body>

    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="MATLEV 5D Logo" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="mahasiswa_dashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="mahasiswa_profile.php">
                    <i class="fas fa-fw fa-user me-2"></i>Profil
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="mahasiswa_asesmen_diri.php">
                    <i class="fas fa-fw fa-clipboard-list me-2"></i>Asesmen Diri
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="mahasiswa_effectiveness_results.php">
                    <i class="fas fa-fw fa-chart-bar me-2"></i>Hasil Efektivitas
                </a>
            </li>
			<li class="nav-item">
                <a class="nav-link" href="mahasiswa_sus_assessment.php">
                    <i class="fas fa-fw fa-star me-2"></i>Evaluasi Usabilitas (SUS)
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
                <a class="navbar-brand" href="#">Hasil Efektivitas Pembelajaran</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($full_name); ?></strong> (<?php echo htmlspecialchars($username); ?>)
                </span>
            </div>
        </nav>

        <?php
        // Tampilkan pesan error dari session jika ada
        if (isset($_SESSION['error_message'])) {
            echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
            unset($_SESSION['error_message']); // Hapus pesan setelah ditampilkan
        }
        ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Pilih Mata Kuliah Anda</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="mahasiswa_effectiveness_results.php">
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="courseSelect" class="form-label">Mata Kuliah Diikuti:</label>
                            <select class="form-select" id="courseSelect" name="course_id" required>
                                <option value="">-- Pilih Mata Kuliah --</option>
                                <?php foreach ($courses_diikuti as $course): ?>
                                    <option value="<?php echo htmlspecialchars($course['id']); ?>"
                                        <?php echo ($current_course_id == $course['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($course['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mt-3 mt-md-0">
                            <button type="submit" class="btn btn-primary w-100">Tampilkan Hasil</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($current_course_id): ?>
            <h3 class="mb-4 text-center">Hasil Efektivitas untuk Mata Kuliah: <span class="text-primary"><?php echo htmlspecialchars($course_name_display); ?></span></h3>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm card-custom">
                        <div class="card-body d-flex flex-column text-center">
                            <h5 class="card-title text-primary">Dimensi RPS Mata Kuliah</h5>
                            <hr>
                            <h4 class="mt-auto"><?php echo htmlspecialchars($rps_dimensi_hasil); ?></h4>
                            <p class="card-text text-muted flex-grow-1"><?php echo htmlspecialchars($rps_dimensi_description); ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card h-100 shadow-sm card-custom">
                        <div class="card-body d-flex flex-column text-center">
                            <h5 class="card-title text-success">Dimensi Asesmen Dosen Pengampu</h5>
                            <hr>
                            <h4 class="mt-auto"><?php echo htmlspecialchars($dosen_dimensi_hasil); ?></h4>
                            <p class="card-text text-muted flex-grow-1"><?php echo htmlspecialchars($dosen_dimensi_description); ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card h-100 shadow-sm card-custom">
                        <div class="card-body d-flex flex-column text-center">
                            <h5 class="card-title text-warning">Dimensi Asesmen Diri Anda</h5>
                            <hr>
                            <h4 class="mt-auto"><?php echo htmlspecialchars($mahasiswa_dimensi_hasil); ?></h4>
                            <p class="card-text text-muted flex-grow-1"><?php echo htmlspecialchars($mahasiswa_dimensi_description); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <hr>
            
            <h3 class="mb-4 text-center">Kesimpulan Efektivitas Pembelajaran Anda</h3>

            <div class="card shadow-lg mb-4 effectiveness-summary-card <?php echo $badge_class_effectiveness; ?>">
                <div class="card-header text-center">
                    <i class="fas fa-star me-2"></i>Hasil Efektivitas Pembelajaran Anda
                </div>
                <div class="card-body">
                    <div class="highlight-status <?php echo $badge_class_effectiveness; ?>">
                        <?php echo htmlspecialchars($effectiveness_status); ?>
                    </div>
                    <p class="lead lead-description">
                        Berdasarkan kombinasi dimensi dari RPS, Asesmen Dosen, dan Asesmen Diri Anda,
                        implementasi digitalisasi pembelajaran pada mata kuliah ini dinilai
                        **<?php echo htmlspecialchars($effectiveness_status); ?>**.
                    </p>
                    <p class="italic-description">
                        "<?php echo htmlspecialchars($effectiveness_description); ?>"
                    </p>
                    <?php if ($effectiveness_status == 'Data Tidak Lengkap' || $effectiveness_status == 'Aturan Tidak Ditemukan'): ?>
                        <div class="alert alert-warning text-center mt-4" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>Perlu diperhatikan: Hasil ini mungkin belum akurat karena data belum lengkap atau aturan belum didefinisikan.
                            <ul>
                                <?php if ($mahasiswa_dimension_id_for_rule === null): ?>
                                    <li>Anda belum melakukan asesmen diri untuk mata kuliah ini. Silakan kunjungi menu <a href="mahasiswa_asesmen_diri.php" class="alert-link">Asesmen Diri</a>.</li>
                                <?php endif; ?>
                                <?php if ($rps_dimension_id_for_rule === null): ?>
                                    <li>RPS mata kuliah ini belum dinilai oleh Asesor.</li>
                                <?php endif; ?>
                                <?php if ($dosen_dimension_id_for_rule === null): ?>
                                    <li>Dosen pengampu mata kuliah ini belum melakukan asesmen mandiri.</li>
                                <?php endif; ?>
                                <?php if ($effectiveness_status == 'Aturan Tidak Ditemukan'): ?>
                                    <li>Kombinasi dimensi ini belum memiliki aturan efektivitas yang terdefinisi di sistem. Mohon hubungi Administrator.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <div class="alert alert-info text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i>Silakan pilih mata kuliah Anda dari daftar di atas untuk menampilkan hasil efektivitas pembelajaran.
            </div>
        <?php endif; ?>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center" style="width: 100%;">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
</body>
</html>