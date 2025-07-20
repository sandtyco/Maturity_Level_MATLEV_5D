<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path ke file database.php

// !!! HARAP HAPUS ATAU KOMENTARI BARIS DEBUG INI SETELAH SEMUA FIX DITERAPKAN !!!
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Proteksi halaman: hanya dosen (role_id = 2) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: ../../login.php?error=Akses tidak diizinkan. Silakan login sebagai Dosen.');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];
$is_asesor = $_SESSION['is_asesor'] ?? 0;

$current_course_id = null;
$course_name_display = "Pilih Mata Kuliah";

// Ambil daftar mata kuliah yang diampu dosen
$courses_diampu = [];
try {
    $stmt_courses_diampu = $pdo->prepare("
        SELECT c.id, c.course_name
        FROM dosen_courses dc
        JOIN courses c ON dc.course_id = c.id
        WHERE dc.dosen_id = :dosen_id
        ORDER BY c.course_name
    ");
    $stmt_courses_diampu->execute([':dosen_id' => $user_id]);
    $courses_diampu = $stmt_courses_diampu->fetchAll(PDO::FETCH_ASSOC);

    // Jika ada parameter course_id di URL, ambil datanya
    if (isset($_GET['course_id']) && is_numeric($_GET['course_id'])) {
        $selected_course_id = (int)$_GET['course_id'];
        // Cek apakah dosen benar-benar mengampu mata kuliah ini
        $found = false;
        foreach ($courses_diampu as $course) {
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
            $_SESSION['error_message'] = "Mata kuliah yang dipilih tidak valid atau bukan diampu oleh Anda.";
        }
    }

} catch (PDOException $e) {
    error_log("Error fetching courses for dropdown in dosen_effectiveness_results.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Terjadi kesalahan saat memuat daftar mata kuliah. Silakan coba lagi.";
}

// --- Data untuk Card RPS Mata Kuliah ---
$rps_dimensi_hasil = "N/A";
$rps_dimensi_description = "Data belum tersedia atau RPS mata kuliah ini belum dinilai.";

// Dapatkan ID dimensi RPS untuk pencocokan aturan
$rps_dimension_id_for_rule = null;

if ($current_course_id) {
    try {
        // Ambil Dimensi RPS Mata Kuliah
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
            $rps_dimension_id_for_rule = $rps_data['dimension_id']; // Ambil ID-nya
            $rps_dimensi_hasil = $rps_data['dimension_name'];
            $rps_dimensi_description = $rps_data['description'];
        } else {
            $rps_dimensi_description = "RPS untuk mata kuliah ini belum dinilai atau data tidak ditemukan.";
        }
    } catch (PDOException $e) {
        error_log("Error fetching RPS dimension: " . $e->getMessage());
        $rps_dimensi_hasil = "Error";
        $rps_dimensi_description = "Terjadi kesalahan saat memuat dimensi RPS.";
    }
}

// --- Data untuk Card Dosen Mandiri ---
$dosen_dimensi_hasil = "N/A";
$dosen_dimensi_description = "Anda belum melakukan asesmen mandiri untuk mata kuliah ini.";

// Dapatkan ID dimensi Dosen untuk pencocokan aturan
$dosen_dimension_id_for_rule = null;

if ($current_course_id) {
    try {
        // Ambil Dimensi Asesmen Mandiri Dosen
        $stmt_dosen_dim = $pdo->prepare("
            SELECT td.id AS dimension_id, td.dimension_name, td.description
            FROM self_assessments_3m sa
            JOIN temus_dimensions td ON sa.classified_temus_id = td.id
            WHERE sa.user_id = :user_id
            AND sa.course_id = :course_id
            AND sa.user_role_at_assessment = 'dosen' -- Memastikan hanya asesmen dosen
            ORDER BY sa.assessment_date DESC, sa.created_at DESC
            LIMIT 1
        ");
        $stmt_dosen_dim->execute([
            ':user_id' => $user_id,
            ':course_id' => $current_course_id
        ]);
        $dosen_data = $stmt_dosen_dim->fetch(PDO::FETCH_ASSOC);

        if ($dosen_data) {
            $dosen_dimension_id_for_rule = $dosen_data['dimension_id']; // Ambil ID-nya
            $dosen_dimensi_hasil = $dosen_data['dimension_name'];
            $dosen_dimensi_description = $dosen_data['description'];
        } else {
            $dosen_dimensi_description = "Anda belum melakukan asesmen mandiri untuk mata kuliah ini, atau data tidak ditemukan.";
        }
    } catch (PDOException $e) {
        error_log("Error fetching Dosen Self-Assessment dimension: " . $e->getMessage());
        $dosen_dimensi_hasil = "Error";
        $dosen_dimensi_description = "Terjadi kesalahan saat memuat dimensi asesmen mandiri Anda.";
    }
}

// --- Data untuk Tabel Dimensi Asesmen Mahasiswa dan Hasil Efektivitas ---
$mahasiswa_dimensi_list = []; // Inisialisasi array kosong
$effectiveness_counts = []; // Untuk menghitung frekuensi status efektivitas
$total_mahasiswa_dinilai = 0; // Menghitung berapa banyak mahasiswa yang memiliki status

if ($current_course_id) {
    try {
        $stmt_mahasiswa_dim = $pdo->prepare("
            SELECT
                u.id AS user_id,
                u.username,
                td.dimension_name AS mahasiswa_dimension_name,
                sa.classified_temus_id AS mahasiswa_dimension_id, 
                ea.effectiveness_status,
                ea.description AS effectiveness_description
            FROM
                mahasiswa_courses mc
            JOIN
                users u ON mc.mahasiswa_id = u.id
            LEFT JOIN (
                SELECT
                    sa_inner.user_id,
                    sa_inner.course_id,
                    sa_inner.classified_temus_id,
                    sa_inner.assessment_date,
                    sa_inner.created_at
                FROM
                    self_assessments_3m sa_inner
                WHERE
                    sa_inner.user_role_at_assessment = 'mahasiswa' AND sa_inner.course_id = :course_id_inner
                ORDER BY
                    sa_inner.assessment_date DESC, sa_inner.created_at DESC
            ) sa ON u.id = sa.user_id AND mc.course_id = sa.course_id
            LEFT JOIN
                temus_dimensions td ON sa.classified_temus_id = td.id
            LEFT JOIN
                final_effectiveness_rules fer ON
                    fer.rps_dimension_id = :rps_dim_id AND
                    fer.dosen_dimension_id = :dosen_dim_id AND
                    fer.mahasiswa_dimension_id = td.id 
            LEFT JOIN
                effectiveness_actions ea ON fer.effectiveness_action_id = ea.id
            WHERE
                mc.course_id = :course_id_outer
            ORDER BY
                u.username ASC;
        ");
        $stmt_mahasiswa_dim->execute([
            ':course_id_inner' => $current_course_id,
            ':course_id_outer' => $current_course_id,
            ':rps_dim_id' => $rps_dimension_id_for_rule,
            ':dosen_dim_id' => $dosen_dimension_id_for_rule
        ]);
        $mahasiswa_dimensi_list = $stmt_mahasiswa_dim->fetchAll(PDO::FETCH_ASSOC);

        // Hitung frekuensi setiap status efektivitas
        foreach ($mahasiswa_dimensi_list as $mahasiswa) {
            $status = $mahasiswa['effectiveness_status'] ?? 'Tidak Ditemukan';
            if (!isset($effectiveness_counts[$status])) {
                $effectiveness_counts[$status] = 0;
            }
            $effectiveness_counts[$status]++;
            
            // Hanya hitung mahasiswa yang memiliki status (bukan null atau belum mengisi)
            // dan bukan 'Tidak Ditemukan' dari hasil rule
            if ($mahasiswa['mahasiswa_dimension_name'] !== null && $status !== 'Tidak Ditemukan') {
                 $total_mahasiswa_dinilai++;
            }
        }

    } catch (PDOException $e) {
        error_log("Error fetching Mahasiswa assessments and effectiveness: " . $e->getMessage());
        $_SESSION['error_message'] = "Terjadi kesalahan saat memuat daftar asesmen mahasiswa dan hasil efektivitas.";
    }
}

// Tentukan status efektivitas mayoritas
$majority_status = 'Tidak Diketahui';
$majority_count = 0;
$majority_description = 'Tidak ada data asesmen mahasiswa yang cukup untuk membuat kesimpulan.';
$badge_class_summary = 'status-tidak-ditemukan'; // Default for summary card

if ($total_mahasiswa_dinilai > 0) {
    $most_frequent_status = '';
    $max_count = 0;

    // Filter out 'Tidak Ditemukan' for majority calculation if other statuses exist
    $filtered_counts = array_filter($effectiveness_counts, function($key) {
        return $key !== 'Tidak Ditemukan';
    }, ARRAY_FILTER_USE_KEY);

    if (!empty($filtered_counts)) {
        arsort($filtered_counts); // Sort in descending order to get the highest count first
        $most_frequent_status = key($filtered_counts); // Get the key (status name) of the first element
        $max_count = current($filtered_counts); // Get the value (count) of the first element
    } else {
        // If all are 'Tidak Ditemukan' or no valid assessments
        $most_frequent_status = 'Tidak Ditemukan';
        $max_count = $effectiveness_counts['Tidak Ditemukan'] ?? 0;
    }

    $majority_status = $most_frequent_status;
    $majority_count = $max_count;

    // Custom descriptions for summary, more actionable
    switch ($majority_status) {
        case 'Sangat Efektif':
            $majority_description = "Indikasi kuat bahwa implementasi digitalisasi pembelajaran Anda berada pada tingkat **Sangat Efektif**. Pertahankan kualitas dan terus berinovasi!";
            $badge_class_summary = 'status-sangat-efektif'; // Menggunakan warna hijau untuk Sangat Efektif
            break;
        case 'Cukup Efektif':
            $majority_description = "Secara keseluruhan, implementasi digitalisasi pembelajaran Anda dinilai **Cukup Efektif**. Ini adalah fondasi yang baik, namun ada area yang dapat ditingkatkan untuk mencapai efektivitas yang lebih tinggi.";
            $badge_class_summary = 'status-cukup-efektif'; // Menggunakan warna kuning untuk Cukup Efektif
            break;
        case 'Tidak Efektif':
            $majority_description = "Tingkat efektivitas digitalisasi pembelajaran Anda dinilai **Tidak Efektif**. Perlu perhatian serius dan tindakan perbaikan segera untuk meningkatkan kualitas pembelajaran digital.";
            $badge_class_summary = 'status-tidak-efektif'; // Menggunakan warna merah untuk Tidak Efektif
            break;
        case 'Tidak Ditemukan':
            $majority_description = "Banyak mahasiswa yang belum memiliki hasil efektivitas karena belum mengisi asesmen atau aturan belum didefinisikan. Perlu tindak lanjut.";
            $badge_class_summary = 'status-tidak-ditemukan';
            break;
        default:
            $majority_description = "Tidak ada data asesmen mahasiswa yang cukup atau valid untuk menyimpulkan tingkat efektivitas.";
            $badge_class_summary = 'status-tidak-ditemukan';
            break;
    }
    
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Evaluasi Efektivitas | Dosen - MATLEV 5D</title>
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
            min-height: 220px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .card-custom .card-title {
            font-weight: bold;
            font-size: 1.2em;
        }
        .card-custom h4 {
            font-size: 2.2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .card-custom p {
            font-size: 0.9em;
            color: #6c757d;
        }
        .status-badge {
            padding: .35em .65em;
            font-size: .75em;
            font-weight: 700;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: .375rem;
            display: inline-block; /* Agar bisa pakai title */
        }
        /* Menggunakan nama kelas yang lebih generik untuk warna, disesuaikan dengan 3 status utama */
        .status-sangat-efektif { background-color: #198754; } /* green */
        .status-cukup-efektif { background-color: #ffc107; color: #212529; } /* yellow */
        .status-tidak-efektif { background-color: #dc3545; } /* red */
        .status-tidak-ditemukan { background-color: #6c757d; } /* grey, for 'Tidak Ditemukan' or unmatched rules */

        /* Styles for the summary card */
        .summary-card {
            background: linear-gradient(135deg, #f0f4f8, #e0e6eb);
            border-left: 8px solid #007bff; /* Default border color */
            border-radius: .75rem;
            box-shadow: 0 4px 15px rgba(0,0,0,.1);
            transition: all 0.3s ease;
        }
        .summary-card .card-header {
            background-color: transparent;
            border-bottom: none;
            color: #343a40;
            font-size: 1.3em;
            font-weight: bold;
        }
        .summary-card .card-body {
            padding-top: 0;
            color: #495057;
        }
        .summary-card .highlight-status {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 10px;
            text-align: center;
            padding: 10px 0;
            border-radius: 8px;
            color: #fff; /* Teks putih untuk semua status */
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
        }
        /* Warna LATAR BELAKANG untuk highlight status di summary card */
        .summary-card .highlight-status.status-sangat-efektif { background-color: #198754; } /* Hijau */
        .summary-card .highlight-status.status-cukup-efektif { background-color: #ffc107; color: #212529; } /* Kuning (teks hitam agar terbaca) */
        .summary-card .highlight-status.status-tidak-efektif { background-color: #dc3545; } /* Merah */
        .summary-card .highlight-status.status-tidak-ditemukan { background-color: #6c757d; } /* Abu-abu */

        /* Border color based on summary status */
        .summary-card.status-sangat-efektif { border-left-color: #198754; }
        .summary-card.status-cukup-efektif { border-left-color: #ffc107; }
        .summary-card.status-tidak-efektif { border-left-color: #dc3545; }
        .summary-card.status-tidak-ditemukan { border-left-color: #6c757d; }
    </style>
</head>
<body>

    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="MATLEV 5D Logo" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="dosen_dashboard.php">
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
                <a class="nav-link active" href="dosen_effectiveness_results.php">
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
                <a class="navbar-brand" href="#">Hasil Evaluasi Efektivitas</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role_name); ?>)
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
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Pilih Mata Kuliah</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="dosen_effectiveness_results.php">
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="courseSelect" class="form-label">Mata Kuliah Diampu:</label>
                            <select class="form-select" id="courseSelect" name="course_id" required>
                                <option value="">-- Pilih Mata Kuliah --</option>
                                <?php foreach ($courses_diampu as $course): ?>
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
            <h3 class="mb-4 text-center">Hasil Dimensi untuk Mata Kuliah: <span class="text-primary"><?php echo htmlspecialchars($course_name_display); ?></span></h3>

            <div class="row g-4 mb-4">
                <div class="col-md-6"> <div class="card h-100 shadow-sm card-custom">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-center text-primary">Dimensi RPS Mata Kuliah</h5>
                            <hr>
                            <h4 class="text-center mt-auto"><?php echo htmlspecialchars($rps_dimensi_hasil); ?></h4>
                            <p class="card-text text-muted text-center flex-grow-1"><?php echo htmlspecialchars($rps_dimensi_description); ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-6"> <div class="card h-100 shadow-sm card-custom">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title text-center text-success">Dimensi Asesmen Mandiri Anda</h5>
                            <hr>
                            <h4 class="text-center mt-auto"><?php echo htmlspecialchars($dosen_dimensi_hasil); ?></h4>
                            <p class="card-text text-muted text-center flex-grow-1"><?php echo htmlspecialchars($dosen_dimensi_description); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            ---
            ### Dimensi Asesmen Mahasiswa dan Hasil Efektivitas

            <div class="card shadow-sm mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="fas fa-users me-2"></i>Daftar Mahasiswa & Hasil Efektivitas</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($mahasiswa_dimensi_list)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="text-center">#</th>
                                        <th scope="col">Nama Mahasiswa</th>
                                        <th scope="col" class="text-center">Dimensi Asesmen Mahasiswa</th>
                                        <th scope="col" class="text-center">Dimensi RPS</th>
                                        <th scope="col" class="text-center">Dimensi Dosen</th>
                                        <th scope="col" class="text-center">Hasil Efektivitas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; ?>
                                    <?php foreach ($mahasiswa_dimensi_list as $mahasiswa): ?>
                                        <tr>
                                            <th scope="row" class="text-center"><?php echo $no++; ?></th>
                                            <td><?php echo htmlspecialchars($mahasiswa['username']); ?></td>
                                            <td class="text-center">
                                                <?php echo htmlspecialchars($mahasiswa['mahasiswa_dimension_name'] ?? 'Belum Mengisi'); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo htmlspecialchars($rps_dimensi_hasil); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo htmlspecialchars($dosen_dimensi_hasil); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                    $effectiveness_status = htmlspecialchars($mahasiswa['effectiveness_status'] ?? 'Tidak Ditemukan');
                                                    $effectiveness_description = htmlspecialchars($mahasiswa['effectiveness_description'] ?? 'Aturan belum didefinisikan atau data tidak lengkap.');
                                                    $badge_class = 'status-tidak-ditemukan'; // Default class if no rule matched or data missing

                                                    switch ($effectiveness_status) {
                                                        case 'Sangat Efektif':
                                                            $badge_class = 'status-sangat-efektif';
                                                            break;
                                                        case 'Cukup Efektif':
                                                            $badge_class = 'status-cukup-efektif';
                                                            break;
                                                        case 'Tidak Efektif':
                                                            $badge_class = 'status-tidak-efektif';
                                                            break;
                                                        default:
                                                            $badge_class = 'status-tidak-ditemukan';
                                                            break;
                                                    }
                                                ?>
                                                <span class="status-badge <?php echo $badge_class; ?>" title="<?php echo $effectiveness_description; ?>">
                                                    <?php echo $effectiveness_status; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center" role="alert">
                            Belum ada mahasiswa yang terdaftar pada mata kuliah ini, atau belum ada yang melakukan asesmen mandiri.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            ---
            ### Kesimpulan Tingkat Efektivitas Digitalisasi Pembelajaran Anda

            <div class="card shadow-sm mb-4 summary-card <?php echo $badge_class_summary; ?>">
                <div class="card-header">
                    <i class="fas fa-lightbulb me-2"></i>Ringkasan dan Rekomendasi
                </div>
                <div class="card-body">
                    <?php if ($total_mahasiswa_dinilai > 0): ?>
                        <div class="highlight-status <?php echo $badge_class_summary; ?>">
                            <?php echo htmlspecialchars($majority_status); ?>
                        </div>
                        <p class="lead text-center">
                            Berdasarkan klasifikasi hasil efektivitas yang diperoleh dari mayoritas mahasiswa, Anda dinilai
                            **<?php echo htmlspecialchars($majority_status); ?>** dalam mengimplementasikan Digitalisasi Pembelajaran.
                        </p>
                        <p class="text-center fst-italic">
                            "<?php echo htmlspecialchars($majority_description); ?>"
                        </p>
                        <?php if ($majority_status == 'Tidak Ditemukan' && ($effectiveness_counts['Tidak Ditemukan'] ?? 0) == count($mahasiswa_dimensi_list)): ?>
                            <div class="alert alert-warning text-center mt-3" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>Sebagian besar atau semua mahasiswa belum memiliki hasil efektivitas yang terdefinisi. Pastikan semua mahasiswa telah melakukan asesmen dan semua aturan kombinasi dimensi telah dimasukkan ke dalam sistem.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info text-center" role="alert">
                            Tidak ada data asesmen mahasiswa yang cukup atau valid untuk mata kuliah ini guna menyimpulkan tingkat efektivitas. Pastikan mahasiswa telah terdaftar dan melakukan asesmen.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <div class="alert alert-info text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i>Silakan pilih mata kuliah dari daftar di atas untuk menampilkan hasil evaluasi.
            </div>
        <?php endif; ?>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center" style="width: 100%;">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>