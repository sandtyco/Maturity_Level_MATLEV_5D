<?php
session_start();
require_once '../../config/database.php';

// Proteksi Halaman: HANYA MAHASISWA yang bisa mengakses
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

// Array berisi pertanyaan-pertanyaan evaluasi kustom
// Catatan: Pertanyaan dinomori 1-12 untuk konsistensi dengan kolom database q1_score s/d q12_score
$sus_questions = [
    // 1. Usability
    1 => "Menurut Saya pribadi, Saya bisa menggunakan aplikasi ini setiap saat.",
    2 => "Menurut Saya pribadi, Saya tidak bisa menggunakan aplikasi ini setiap saat.",
    3 => "Menurut Saya aplikasi evaluasi ini mudah dipahami.",
    4 => "Menurut Saya aplikasi evaluasi ini sulit dipahami.",

    // 2. Fungsionalitas
    5 => "Fitur yang ada pada aplikasi ini sesuai dengan fungsinya.",
    6 => "Fitur yang ada pada aplikasi ini tidak konsisten terhadap fungsinya.",
    7 => "Fitur aplikasi ini tidak ada yang error.",
    8 => "Fitur aplikasi ini beberapa didapati error dan tidak berfungsi.",

    // 3. Pengalaman (UX)
    9 => "Saya rasa orang lain tidak akan mengalami kesulitan dalam menggunakan aplikasi ini.",
    10 => "Saya rasa orang lain akan mengalami kesulitan dalam menggunakan aplikasi ini.",
    11 => "Saya akan terbiasa untuk menggunakan aplikasi evaluasi ini.",
    12 => "Saya membutuhkan bantuan orang lain untuk menggunakan aplikasi evaluasi ini."
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scores = [];
    $all_questions_answered = true;

    // Loop dari 1 sampai 12 untuk mengambil semua skor pertanyaan
    for ($i = 1; $i <= 12; $i++) {
        $question_name = 'q' . $i . '_score';
        if (isset($_POST[$question_name]) && in_array($_POST[$question_name], [1, 2, 3, 4, 5])) {
            $scores[$question_name] = (int)$_POST[$question_name];
        } else {
            $all_questions_answered = false;
            break;
        }
    }

    if (!$all_questions_answered) {
        $error = "Mohon jawab semua pertanyaan sebelum mengirimkan asesmen.";
    } else {
        try {
            // Cek apakah user (mahasiswa) sudah pernah melakukan asesmen pada tanggal yang sama
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM sus_assessments WHERE user_id = :user_id AND assessment_date = CURDATE()");
            $stmt_check->execute([':user_id' => $user_id]);
            if ($stmt_check->fetchColumn() > 0) {
                $error = "Anda sudah mengisi evaluasi ini hari ini. Terima kasih atas partisipasi Anda.";
            } else {
                // Persiapkan query INSERT untuk 12 kolom
                $sql = "INSERT INTO sus_assessments (user_id, q1_score, q2_score, q3_score, q4_score, q5_score, q6_score, q7_score, q8_score, q9_score, q10_score, q11_score, q12_score, assessment_date)
                        VALUES (:user_id, :q1_score, :q2_score, :q3_score, :q4_score, :q5_score, :q6_score, :q7_score, :q8_score, :q9_score, :q10_score, :q11_score, :q12_score, CURDATE())";
                $stmt = $pdo->prepare($sql);

                $params = [':user_id' => $user_id];
                foreach ($scores as $key => $value) {
                    $params[':' . $key] = $value;
                }

                if ($stmt->execute($params)) {
                    $message = "Evaluasi Anda berhasil disimpan! Terima kasih atas masukan Anda.";
                    // Opsional: Redirect ke halaman hasil evaluasi mahasiswa
                    // header('Location: mahasiswa_sus_results.php?status=success');
                    // exit();
                } else {
                    $error = "Gagal menyimpan evaluasi. Silakan coba lagi.";
                }
            }
        } catch (PDOException $e) {
            error_log("Error saving custom evaluation (mahasiswa): " . $e->getMessage());
            $error = "Terjadi kesalahan database: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulir Evaluasi Aplikasi | Mahasiswa - MATLEV 5D</title>
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
        .form-check-inline {
            margin-right: 1.5rem;
        }
        .card-header {
            background-color: #007bff;
            color: white;
        }
        .form-label-custom {
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #343a40;
        }
        /* Style untuk judul faktor */
        .factor-heading {
            background-color: #e9ecef; /* Light gray background for factor headings */
            padding: 10px 15px;
            border-radius: 5px;
            margin-top: 25px;
            margin-bottom: 15px;
            font-size: 1.25em;
            color: #495057;
            font-weight: bold;
            border-left: 5px solid #007bff;
        }
        .first-factor-heading {
            margin-top: 0; /* No top margin for the very first factor */
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
                <a class="nav-link" href="mahasiswa_effectiveness_results.php">
                    <i class="fas fa-fw fa-chart-bar me-2"></i>Hasil Efektivitas
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="mahasiswa_sus_assessment.php">
                    <i class="fas fa-fw fa-star me-2"></i>Evaluasi Aplikasi
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
                <a class="navbar-brand" href="#">Evaluasi Aplikasi MATLEV 5D</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($full_name); ?></strong> (<?php echo htmlspecialchars($username); ?>)
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
                <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i>Formulir Evaluasi Usabilitas dan Fungsionalitas Aplikasi</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-4">
                    Mohon berikan penilaian Anda terhadap sistem ini dengan memilih skala 1 hingga 5 untuk setiap pernyataan,
                    di mana 1 = Sangat Tidak Setuju dan 5 = Sangat Setuju.
                </p>
                <form method="POST" action="mahasiswa_sus_assessment.php">
                    <div class="factor-heading first-factor-heading">1. Usability</div>
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="mb-4 p-3 border rounded bg-light">
                            <label class="form-label-custom mb-2">
                                <?php echo $i; ?>. <?php echo htmlspecialchars($sus_questions[$i]); ?>
                            </label>
                            <div class="d-flex flex-wrap align-items-center">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score1" value="1" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score1">1 (Sangat Tidak Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score2" value="2" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score2">2 (Tidak Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score3" value="3" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score3">3 (Netral)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score4" value="4" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score4">4 (Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score5" value="5" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score5">5 (Sangat Setuju)</label>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <div class="factor-heading">2. Fungsionalitas</div>
                    <?php for ($i = 5; $i <= 8; $i++): ?>
                        <div class="mb-4 p-3 border rounded bg-light">
                            <label class="form-label-custom mb-2">
                                <?php echo $i; ?>. <?php echo htmlspecialchars($sus_questions[$i]); ?>
                            </label>
                            <div class="d-flex flex-wrap align-items-center">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score1" value="1" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score1">1 (Sangat Tidak Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score2" value="2" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score2">2 (Tidak Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score3" value="3" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score3">3 (Netral)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score4" value="4" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score4">4 (Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score5" value="5" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score5">5 (Sangat Setuju)</label>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <div class="factor-heading">3. Pengalaman (UX)</div>
                    <?php for ($i = 9; $i <= 12; $i++): ?>
                        <div class="mb-4 p-3 border rounded bg-light">
                            <label class="form-label-custom mb-2">
                                <?php echo $i; ?>. <?php echo htmlspecialchars($sus_questions[$i]); ?>
                            </label>
                            <div class="d-flex flex-wrap align-items-center">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score1" value="1" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score1">1 (Sangat Tidak Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score2" value="2" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score2">2 (Tidak Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score3" value="3" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score3">3 (Netral)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score4" value="4" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score4">4 (Setuju)</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="q<?php echo $i; ?>_score" id="q<?php echo $i; ?>_score5" value="5" required>
                                    <label class="form-check-label" for="q<?php echo $i; ?>_score5">5 (Sangat Setuju)</label>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg mt-3">
                            <i class="fas fa-paper-plane me-2"></i>Kirim Evaluasi Aplikasi
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center" style="width: 100%;">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
</body>
</html>