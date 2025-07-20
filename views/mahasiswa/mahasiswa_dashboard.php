<?php
session_start();
require_once '../../config/database.php';

// --- Proteksi Halaman: HANYA MAHASISWA yang bisa mengakses ---
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) { // role_id 3 untuk mahasiswa
    header('Location: ../../login.php?error=Akses tidak diizinkan. Hanya mahasiswa yang bisa mengakses halaman ini.');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username']; // Biasanya ini adalah NIM atau username login
$full_name = $_SESSION['full_name'] ?? 'Mahasiswa'; // Nama lengkap mahasiswa
$role_name = $_SESSION['role_name']; // 'Mahasiswa'

$message = '';
$error = '';

// Inisialisasi data asesmen diri
$self_assessment_scores = [
    'metode_score_avg' => 0,
    'materi_score_avg' => 0,
    'media_score_avg' => 0,
    'overall_score_avg' => 0,
    'dimension_name' => 'Belum Dinilai'
];

try {
    // Ambil rata-rata skor terbaru dari self_assessments_3m untuk mahasiswa ini
    // Mengambil yang paling baru jika ada multiple assessment_date
    $stmt_avg_scores = $pdo->prepare("
        SELECT
            sa.metode_score,
            sa.materi_score,
            sa.media_score,
            sa.overall_3m_score,
            td.dimension_name
        FROM
            self_assessments_3m sa
        LEFT JOIN
            temus_dimensions td ON sa.classified_temus_id = td.id
        WHERE
            sa.user_id = :user_id
        ORDER BY
            sa.assessment_date DESC, sa.created_at DESC
        LIMIT 1
    ");
    $stmt_avg_scores->execute([':user_id' => $user_id]);
    $latest_assessment = $stmt_avg_scores->fetch(PDO::FETCH_ASSOC);

    if ($latest_assessment) {
        $self_assessment_scores['metode_score_avg'] = (float) $latest_assessment['metode_score'];
        $self_assessment_scores['materi_score_avg'] = (float) $latest_assessment['materi_score'];
        $self_assessment_scores['media_score_avg'] = (float) $latest_assessment['media_score'];
        $self_assessment_scores['overall_score_avg'] = (float) $latest_assessment['overall_3m_score'];
        $self_assessment_scores['dimension_name'] = $latest_assessment['dimension_name'] ?? 'Tidak Terdefinisi';
    } else {
        $message = "Anda belum melakukan asesmen diri. Silakan lakukan asesmen di menu 'Asesmen Diri'.";
    }

} catch (PDOException $e) {
    error_log("Error fetching self-assessment data for dashboard: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat data asesmen diri: " . $e->getMessage();
}

// Cek parameter status dari URL setelah redirect (misal dari asesmen diri berhasil)
if (isset($_GET['status']) && $_GET['status'] == 'success') {
    $message = "Asesmen diri berhasil disimpan!";
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Mahasiswa - MATLEV 5D</title>
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

        #sidebar a:hover,
        #sidebar a.active {
            background-color: #495057;
        }

        #content {
            flex-grow: 1;
            padding: 20px;
        }

        .navbar-brand {
            font-weight: bold;
        }

        .info-card {
            background-color: #ffffff;
            border-left: 5px solid #007bff; /* Warna biru untuk card Metode */
            border-radius: 5px;
            padding: 15px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            height: 100%; /* Agar tinggi card sama */
        }

        .info-card.materi {
            border-left-color: #28a745; /* Warna hijau untuk Materi */
        }

        .info-card.media {
            border-left-color: #ffc107; /* Warna kuning untuk Media */
        }

        .info-card.overall {
            border-left-color: #dc3545; /* Warna merah untuk Overall */
        }

        .info-card.dimension {
            border-left-color: #6c757d; /* Warna abu-abu untuk Dimensi */
        }

        .info-card:hover {
            transform: translateY(-5px);
        }

        .info-card h5 {
            color: #007bff; /* Default */
            font-weight: bold;
        }
        .info-card.materi h5 { color: #28a745; }
        .info-card.media h5 { color: #ffc107; }
        .info-card.overall h5 { color: #dc3545; }
        .info-card.dimension h5 { color: #6c757d; }


        .info-card p {
            font-size: 1.5em;
            margin-bottom: 0;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link active" href="mahasiswa_dashboard.php">
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
                <a class="nav-link" href="mahasiswa_effectiveness_results.php">
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
                <a class="navbar-brand" href="#">Dashboard Mahasiswa</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($full_name); ?></strong> (<?php echo htmlspecialchars($username); ?>)
                </span>
            </div>
        </nav>

        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
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

        <div class="row mb-4">
            <div class="col-md-3 mb-4">
                <div class="card info-card">
                    <div class="card-body text-center">
                        <h5 class="card-title">Rata-rata Metode</h5>
                        <p class="card-text"><?php echo number_format($self_assessment_scores['metode_score_avg'], 2); ?></p>
                        <small class="text-muted">Skor Aspek Metode</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card info-card materi">
                    <div class="card-body text-center">
                        <h5 class="card-title">Rata-rata Materi</h5>
                        <p class="card-text"><?php echo number_format($self_assessment_scores['materi_score_avg'], 2); ?></p>
                        <small class="text-muted">Skor Aspek Materi</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card info-card media">
                    <div class="card-body text-center">
                        <h5 class="card-title">Rata-rata Media</h5>
                        <p class="card-text"><?php echo number_format($self_assessment_scores['media_score_avg'], 2); ?></p>
                        <small class="text-muted">Skor Aspek Media</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-4">
                <div class="card info-card overall">
                    <div class="card-body text-center">
                        <h5 class="card-title">Skor Overall Asesmen</h5>
                        <p class="card-text"><?php echo number_format($self_assessment_scores['overall_score_avg'], 2); ?></p>
                        <small class="text-muted">Rata-rata 3 Aspek (M, M, M)</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4 offset-md-4 mb-4"> <div class="card info-card dimension">
                    <div class="card-body text-center">
                        <h5 class="card-title">Dimensi Digitalisasi Pembelajaran</h5>
                        <p class="card-text fs-4 fw-bold"><?php echo htmlspecialchars($self_assessment_scores['dimension_name']); ?></p>
                        <small class="text-muted">Tingkat Kematangan Digitalisasi</small>
                    </div>
                </div>
            </div>
        </div>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a
                    href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a>
            </h6>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
        $(document).ready(function () {
            // Menghilangkan pesan alert setelah beberapa detik (opsional)
            setTimeout(function () {
                $('.alert').alert('close');
            }, 5000);
        });
    </script>
</body>
</html>