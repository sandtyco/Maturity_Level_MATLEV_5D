<?php
session_start();
require_once 'config/database.php'; // <--- PASTIKAN PATH INI BENAR!

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Inisialisasi variabel untuk statistik umum
$total_courses = 0;
$total_mahasiswa = 0;
$total_dosen = 0;
$total_institutions = 0;
$total_programs = 0;

// Inisialisasi data untuk Chart Dimensi TEMUS per Perspektif
$dimension_labels = ['Traditional', 'Enhance', 'Mobile', 'Ubiquitous', 'Smart'];

$rps_dimension_data = array_fill_keys($dimension_labels, 0);
$dosen_dimension_data = array_fill_keys($dimension_labels, 0);
$mahasiswa_dimension_data = array_fill_keys($dimension_labels, 0);

try {
    // Ambil statistik umum
    $stmt_courses = $pdo->query("SELECT COUNT(*) AS total FROM courses");
    $total_courses = $stmt_courses->fetchColumn();

    $stmt_mahasiswa = $pdo->query("SELECT COUNT(*) AS total FROM mahasiswa_details");
    $total_mahasiswa = $stmt_mahasiswa->fetchColumn();

    $stmt_dosen = $pdo->query("SELECT COUNT(*) AS total FROM dosen_details");
    $total_dosen = $stmt_dosen->fetchColumn();

    $stmt_institutions = $pdo->query("SELECT COUNT(*) AS total FROM institutions");
    $total_institutions = $stmt_institutions->fetchColumn();

    $stmt_programs = $pdo->query("SELECT COUNT(*) AS total FROM programs_of_study");
    $total_programs = $stmt_programs->fetchColumn();

    // --- Query untuk Statistik Dimensi RPS (TEMUS) ---
    // Data dari rps_assessments
    $stmt_rps_dimensions = $pdo->query("
        SELECT td.dimension_name, COUNT(DISTINCT ra.course_id) AS total_items
        FROM rps_assessments AS ra
        JOIN temus_dimensions AS td ON ra.classified_temus_id = td.id
        WHERE ra.classified_temus_id IS NOT NULL AND ra.classified_temus_id != ''
        GROUP BY td.dimension_name
    ");
    $rps_results = $stmt_rps_dimensions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rps_results as $row) {
        if (array_key_exists($row['dimension_name'], $rps_dimension_data)) {
            $rps_dimension_data[$row['dimension_name']] = (int) $row['total_items'];
        }
    }

    // --- Query untuk Statistik Dimensi Dosen (TEMUS) ---
    // Data dari self_assessments_3m dengan user_role_at_assessment = 'Dosen'
    $stmt_dosen_dimensions = $pdo->query("
        SELECT td.dimension_name, COUNT(DISTINCT sa.user_id) AS total_items
        FROM self_assessments_3m AS sa
        JOIN temus_dimensions AS td ON sa.classified_temus_id = td.id
        WHERE sa.user_role_at_assessment = 'Dosen'
          AND sa.classified_temus_id IS NOT NULL AND sa.classified_temus_id != ''
        GROUP BY td.dimension_name
    ");
    $dosen_results = $stmt_dosen_dimensions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dosen_results as $row) {
        if (array_key_exists($row['dimension_name'], $dosen_dimension_data)) {
            $dosen_dimension_data[$row['dimension_name']] = (int) $row['total_items'];
        }
    }

    // --- Query untuk Statistik Dimensi Mahasiswa (TEMUS) ---
    // Data dari self_assessments_3m dengan user_role_at_assessment = 'Mahasiswa'
    $stmt_mahasiswa_dimensions = $pdo->query("
        SELECT td.dimension_name, COUNT(DISTINCT sa.user_id) AS total_items
        FROM self_assessments_3m AS sa
        JOIN temus_dimensions AS td ON sa.classified_temus_id = td.id
        WHERE sa.user_role_at_assessment = 'Mahasiswa'
          AND sa.classified_temus_id IS NOT NULL AND sa.classified_temus_id != ''
        GROUP BY td.dimension_name
    ");
    $mahasiswa_results = $stmt_mahasiswa_dimensions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mahasiswa_results as $row) {
        if (array_key_exists($row['dimension_name'], $mahasiswa_dimension_data)) {
            $mahasiswa_dimension_data[$row['dimension_name']] = (int) $row['total_items'];
        }
    }

    // Fetch all institutions for the new dropdown
    $institutions = [];
    $stmt_institutions_dropdown = $pdo->query("SELECT id, nama_pt FROM institutions ORDER BY nama_pt ASC");
    $institutions = $stmt_institutions_dropdown->fetchAll(PDO::FETCH_ASSOC);


} catch (PDOException $e) {
    echo "<div class='alert alert-danger text-center mt-3' role='alert'>Error mengambil statistik: " . $e->getMessage() . "</div>";
    error_log("Database Error in index.php (general stats or dimension chart): " . $e->getMessage());
}

// --- START SUS CALCULATION LOGIC (Tidak Berubah) ---
$overall_usability_score = 0;
$overall_fungsionalitas_score = 0;
$overall_ux_score = 0;
$overall_total_score = 0;
$total_respondents_sus = 0;
$error_message_sus = "";

try {
    $stmt_sus = $pdo->query("SELECT * FROM sus_assessments");
    $assessments = $stmt_sus->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($assessments)) {
        $total_respondents_sus = count($assessments);
        $sum_usability_scores_normalized = 0;
        $sum_fungsionalitas_scores_normalized = 0;
        $sum_ux_scores_normalized = 0;
        $sum_all_normalized_scores_total = 0;

        foreach ($assessments as $assessment) {
            $normalized_scores = [];
            $normalized_scores[1] = $assessment['q1_score'] - 1;
            $normalized_scores[3] = $assessment['q3_score'] - 1;
            $normalized_scores[5] = $assessment['q5_score'] - 1;
            $normalized_scores[7] = $assessment['q7_score'] - 1;
            $normalized_scores[9] = $assessment['q9_score'] - 1;
            $normalized_scores[11] = $assessment['q11_score'] - 1;

            $normalized_scores[2] = 5 - $assessment['q2_score'];
            $normalized_scores[4] = 5 - $assessment['q4_score'];
            $normalized_scores[6] = 5 - $assessment['q6_score'];
            $normalized_scores[8] = 5 - $assessment['q8_score'];
            $normalized_scores[10] = 5 - $assessment['q10_score'];
            $normalized_scores[12] = 5 - $assessment['q12_score'];

            $current_usability_sum = $normalized_scores[1] + $normalized_scores[2] + $normalized_scores[3] + $normalized_scores[4];
            $current_fungsionalitas_sum = $normalized_scores[5] + $normalized_scores[6] + $normalized_scores[7] + $normalized_scores[8];
            $current_ux_sum = $normalized_scores[9] + $normalized_scores[10] + $normalized_scores[11] + $normalized_scores[12];

            $sum_usability_scores_normalized += $current_usability_sum;
            $sum_fungsionalitas_scores_normalized += $current_fungsionalitas_sum;
            $sum_ux_scores_normalized += $current_ux_sum;
            $current_total_normalized_score = array_sum($normalized_scores);
            $sum_all_normalized_scores_total += $current_total_normalized_score;
        }

        $avg_usability_normalized = $sum_usability_scores_normalized / ($total_respondents_sus * 4);
        $avg_fungsionalitas_normalized = $sum_fungsionalitas_scores_normalized / ($total_respondents_sus * 4);
        $avg_ux_normalized = $sum_ux_scores_normalized / ($total_respondents_sus * 4);
        $avg_overall_normalized = $sum_all_normalized_scores_total / ($total_respondents_sus * 12);

        $overall_usability_score = round($avg_overall_normalized * 25, 2);
        $overall_fungsionalitas_score = round($avg_overall_normalized * 25, 2);
        $overall_ux_score = round($avg_overall_normalized * 25, 2);
        $overall_total_score = round($avg_overall_normalized * 25, 2);
    } else {
        $error_message_sus = "Belum ada data evaluasi usabilitas aplikasi yang tersedia.";
    }

} catch (PDOException $e) {
    error_log("Error fetching SUS assessment data: " . $e->getMessage());
    $error_message_sus = "Terjadi kesalahan saat mengambil data evaluasi usabilitas aplikasi.";
}

function interpretScore($score) {
    if ($score >= 80) {
        return "Sangat Baik";
    } elseif ($score >= 70) {
        return "Baik";
    } elseif ($score >= 50) {
        return "Cukup";
    } else {
        return "Perlu Perbaikan";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MATLEV 5D - Maturity Level Evaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" href="assets/img/favicon.png" type="image/x-icon">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header {
            background-color: #0056b3;
            color: white;
            padding: 10px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        .header-title {
            text-align: left;
            flex-grow: 1;
        }
        .header-links {
            text-align: right;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .header-links .btn {
            margin-left: 0;
            margin-top: 0;
        }
        .header-links .nav-link {
            color: white;
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background-color 0.2s ease;
        }
        .header-links .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        /* Jumbotron Style */
        .jumbotron {
            background: linear-gradient(to right, #007bff, #0056b3);
            color: white;
            padding: 80px 20px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .jumbotron h1 {
            font-size: 3.5em;
            margin-bottom: 20px;
            font-weight: 700;
        }
        .jumbotron p {
            font-size: 1.5em;
            margin-bottom: 30px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        .jumbotron .btn {
            padding: 15px 30px;
            font-size: 1.2em;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .jumbotron .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }

        .main-content {
            flex-grow: 1;
            max-width: 1000px;
            margin: 30px auto;
            padding: 20px;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 30px;
            padding: 0 15px;
        }
        .stat-card {
            background-color: #e9f7ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            margin-top: 0;
            color: #007bff;
            font-size: 1.1em;
        }
        .stat-card .value {
            font-size: 2.5em;
            font-weight: bold;
            color: #333;
            margin-top: 10px;
        }

        .section-separator {
            margin: 60px 0;
            border: 0;
            border-top: 1px solid #eee;
            width: 80%;
            margin-left: auto;
            margin-right: auto;
        }
        .section-content {
            padding: 20px 0;
        }
        .section-content h3 {
            color: #007bff;
            margin-bottom: 20px;
            text-align: center;
        }
        .section-content p, .section-content ul, .section-content address {
            font-size: 1.1em;
            line-height: 1.6;
            margin-bottom: 15px;
            text-align: left; /* Default text align for paragraphs in sections */
        }
        .section-content ul {
            list-style: disc;
            margin-left: 20px;
        }

        /* Responsive Columns for About & Helpdesk */
        .section-row {
            display: flex;
            flex-direction: column; /* Default stack on small screens */
            align-items: center;
            text-align: center; /* Center content in columns */
        }
        .section-col-left, .section-col-right {
            padding: 15px;
        }
        @media (min-width: 768px) {
            .section-row.about-section-layout { /* Untuk bagian About */
                flex-direction: row;
                text-align: left;
            }
            .section-row.about-section-layout .section-col-left {
                flex: 0 0 30%;
                max-width: 30%;
            }
            .section-row.about-section-layout .section-col-right {
                flex: 0 0 70%;
                max-width: 70%;
            }

            .section-row.helpdesk-section-layout { /* Untuk bagian Helpdesk */
                flex-direction: row;
                text-align: left;
            }
            .section-row.helpdesk-section-layout .section-col-left {
                flex: 0 0 70%;
                max-width: 70%;
            }
            .section-row.helpdesk-section-layout .section-col-right {
                flex: 0 0 30%;
                max-width: 30%;
            }
        }

        .section-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        /* Contact Section Grid */
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        @media (min-width: 768px) {
            .contact-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        .map-container {
            width: 100%;
            height: 300px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .map-container iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .footer {
            text-align: center;
            padding: 20px;
            margin-top: auto;
            background-color: #343a40;
            color: #f8f9fa;
            font-size: 0.9em;
        }

        /* Responsive adjustments */
        @media (max-width: 767.98px) {
            .header-links {
                flex-direction: column;
                align-items: flex-end;
            }
            .header-links .nav-link, .header-links .btn {
                margin: 5px 0;
            }
            .jumbotron h1 {
                font-size: 2.5em;
            }
            .jumbotron p {
                font-size: 1.1em;
            }
            .jumbotron .btn {
                padding: 10px 20px;
                font-size: 1em;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            }
            /* Ensure section content within columns is left-aligned on mobile */
            .section-row .section-col-left,
            .section-row .section-col-right {
                text-align: left;
            }
            .section-row.about-section-layout,
            .section-row.helpdesk-section-layout {
                flex-direction: column;
                /* Stack on mobile */
            }
            .section-row.about-section-layout .section-col-left,
            .section-row.about-section-layout .section-col-right,
            .section-row.helpdesk-section-layout .section-col-left,
            .section-row.helpdesk-section-layout .section-col-right {
                flex: none;
                /* Reset flex properties */
                max-width: 100%;
                /* Take full width on mobile */
            }
        }

        /* --- SUS Results Section Styles --- */
        .sus-results-section {
            background-color: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 40px;
        }
        .sus-results-section h2 {
            color: #007bff;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 600;
        }
        .sus-score-box {
            background-color: #e9f7ff;
            border: 1px solid #007bff;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            min-height: 200px;
            /* Ensure consistent height */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .sus-score-box h4 {
            color: #0056b3;
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 1.3em;
        }
        .sus-score-box .score-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #333; /* Default text color */
        }
        .sus-score-box .score-interpretation {
            font-size: 1.1rem;
            margin-top: 5px;
            color: #6c757d;
        }
        /* Specific colors for interpretation */
        .score-interpretation.sangatbaik, .progress-bar-custom.sangatbaik { background-color: #28a745 !important; color: white;} /* Green */
        .score-interpretation.baik, .progress-bar-custom.baik { background-color: #17a2b8 !important; color: white;} /* Teal */
        .score-interpretation.cukup, .progress-bar-custom.cukup { background-color: #ffc107 !important; color: #333;} /* Yellow */
        .score-interpretation.perluperbaikan, .progress-bar-custom.perluperbaikan { background-color: #dc3545 !important; color: white;} /* Red */

        .progress-bar-custom {
            height: 25px;
            font-size: 0.9rem;
            font-weight: bold;
            color: white;
            transition: width 0.5s ease-in-out;
        }
        .overall-score-box {
            background-color: #007bff;
            color: white;
            border: 1px solid #0056b3;
            box-shadow: 0 6px 12px rgba(0,0,0,0.2);
            padding: 30px;
        }
        .overall-score-box h4, .overall-score-box .score-value, .overall-score-box .score-interpretation {
            color: white !important;
        }
        /* End SUS Results Section Styles */

        /* Chart Styling for TEMUS Dimensions */
        .dimension-charts-container {
            display: flex;
            flex-wrap: wrap; /* Allow charts to wrap on smaller screens */
            justify-content: center;
            gap: 20px; /* Space between charts */
            margin-top: 40px;
        }

        .chart-wrapper {
            background-color: #f8f9fa;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            flex: 1 1 calc(33.333% - 40px);
            /* 3 charts per row, with gap */
            max-width: calc(33.333% - 40px);
            /* Ensure max width for responsiveness */
            min-width: 280px;
            /* Minimum width for each chart on small screens */
            text-align: center;
        }

        .chart-wrapper h4 {
            color: #007bff;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .chart-canvas {
            position: relative;
            height: 250px; /* Consistent height for all pie charts */
            width: 100%;
        }

        @media (max-width: 991.98px) { /* Tablet and smaller */
            .chart-wrapper {
                flex: 1 1 calc(50% - 30px);
                /* 2 charts per row */
                max-width: calc(50% - 30px);
            }
        }

        @media (max-width: 767.98px) { /* Mobile */
            .chart-wrapper {
                flex: 1 1 95%;
                /* 1 chart per row */
                max-width: 95%;
                margin-bottom: 20px; /* Add margin when stacked */
            }
            .chart-canvas {
                height: 200px;
                /* Adjust height for smaller screens */
            }
        }

        /* New Styles for Interactive Institution Stats */
        .interactive-stats-section .chart-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }
        .interactive-stats-section .chart-col {
            flex: 1 1 calc(50% - 20px); /* Two columns on larger screens */
            min-width: 300px; /* Minimum width before wrapping */
            background-color: #f8f9fa;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            text-align: center;
        }
        .interactive-stats-section .chart-col h6 {
            color: #007bff;
            margin-bottom: 15px;
            font-size: 1.1em;
        }
        .interactive-stats-section .chart-canvas-large {
            position: relative;
            height: 300px; /* Consistent height for these charts */
            width: 100%;
        }
        @media (max-width: 767.98px) {
            .interactive-stats-section .chart-col {
                flex: 1 1 95%;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <a href="index.php"><img src="assets/img/logo.png" alt="MATLEV 5D Logo" width="300"></a>
            </div>
            <div class="header-links">
                <a href="#about-matlev" class="nav-link">Tentang MATLEV</a>
                <a href="#statistics" class="nav-link">Statistik</a>
                <a href="#sus-results-section" class="nav-link">Hasil Evaluasi</a>
                <a href="#faq" class="nav-link">FAQ</a>
                <a href="#helpdesk" class="nav-link">Helpdesk</a>
                <a href="#contact" class="nav-link">Kontak</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <span class="text-white">Selamat datang, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>!</span>
                    <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
                <?php else: ?>
                    <a href="views/login.php" class="btn btn-warning btn-sm">Login Pengguna</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="jumbotron">
        <div class="container">
            <h1>MATLEV 5D</h1>
            <p>
                Ukur Tingkat Kematangan Digitalisasi Pembelajaran Anda. Dapatkan Insight Mendalam dari Aspek Metode, Materi, dan Media.
            </p>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="views/login.php" class="btn btn-light btn-lg">Mulai Evaluasi</a>
            <?php else: ?>
                <?php
                $dashboard_link = '';
                $dashboard_text = '';
                if (isset($_SESSION['role_id'])) {
                    if ($_SESSION['role_id'] == 1) {
                        $dashboard_link = 'views/admin_dashboard.php';
                        $dashboard_text = 'Dashboard Admin';
                    } elseif ($_SESSION['role_id'] == 2) {
                        $dashboard_link = 'views/dosen_dashboard.php';
                        $dashboard_text = 'Dashboard Dosen';
                    } elseif ($_SESSION['role_id'] == 3) {
                        $dashboard_link = 'views/mahasiswa_dashboard.php';
                        $dashboard_text = 'Dashboard Mahasiswa';
                    }
                }
                ?>
                <?php if ($dashboard_link): ?>
                    <a href="<?php echo $dashboard_link; ?>" class="btn btn-light btn-lg"><?php echo $dashboard_text; ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="container main-content">

        <div class="section-content" id="about-matlev">
            <h3>Tentang MATLEV 5D</h3>
            <div class="section-row about-section-layout">
                <div class="section-col-left">
                    <img src="assets/img/temus.jpg" alt="About MATLEV" class="section-image">
                </div>
                <div class="section-col-right">
                    <p align="justify">
                        Aplikasi MATLEV 5D adalah alat inovatif yang dirancang untuk mengukur tingkat kematangan dan efektivitas pada digitalisasi pembelajaran. Penilaian MATLEV 5D fokus pada tiga aspek utama yaitu Metode, Materi, dan Media atau disebut (3M) yang dievaluasi dan diklasifikasikan dalam lima dimensi maturity level yaitu, Traditional, Enhance, Mobile, Ubiquitous, dan Smart atau disingkat (TEMUS). Penilaian didasarkan pada multi-perspektif, yaitu dari nilai aspek RPS Mata Kuliah, Gaya Pembelajaran Dosen, dan Pengalaman Pembelajaran Mahasiswa, memberikan gambaran komprehensif tentang efektivitas Digitalisasi dalam Pembelajaran. MATLEV 5D akan membantu institusi untuk mengetahui sejauh mana praktik digitalisasi pembelajaran berjalan.
                    </p>
                </div>
            </div>
        </div>

        <div class="section-content" id="statistics">
            <h3 class="mb-4 text-primary">Statistik Data</h3><hr>
            <div class="stats-grid d-flex justify-content-center">
                <div class="card stat-card" style="width: 18rem;">
                    <img class="card-img-top" src="assets/img/stat1.png" alt="Card image cap">
                    <div class="card-body">
                        <p>Jumlah:</p>
                        <h4 align="center" class="card-text value"><?php echo $total_institutions; ?></h4>
                    </div>
                </div>
                <div class="card stat-card" style="width: 18rem;">
                    <img class="card-img-top" src="assets/img/stat2.png" alt="Card image cap">
                    <div class="card-body">
                        <p>Jumlah:</p>
                        <h3 align="center" class="card-text value"><?php echo $total_programs; ?></h3>
                    </div>
                </div>
                <div class="card stat-card" style="width: 18rem;">
                    <img class="card-img-top" src="assets/img/stat3.png" alt="Card image cap">
                    <div class="card-body">
                        <p>Jumlah:</p>
                        <h3 align="center" class="card-text value"><?php echo $total_courses; ?></h3>
                    </div>
                </div>
                <div class="card stat-card" style="width: 18rem;">
                    <img class="card-img-top" src="assets/img/stat4.png" alt="Card image cap">
                    <div class="card-body">
                        <p>Jumlah:</p>
                        <h3 align="center" class="card-text value"><?php echo $total_dosen; ?></h3>
                    </div>
                </div>
                <div class="card stat-card" style="width: 18rem;">
                    <img class="card-img-top" src="assets/img/stat5.png" alt="Card image cap">
                    <div class="card-body">
                        <p>Jumlah:</p>
                        <h3 align="center" class="card-text value"><?php echo $total_mahasiswa; ?></h3>
                    </div>
                </div>
            </div>
			
			<hr class="section-separator">
            <h3 class="mb-4 text-primary">Distribusi Dimensi Pembelajaran (TEMUS)</h3>
            <div class="dimension-charts-container">
                <div class="chart-wrapper">
                    <h4>Dimensi Mata Kuliah</h4>
                    <div class="chart-canvas">
                        <canvas id="rpsDimensionChart"></canvas>
                    </div>
                </div>
                <div class="chart-wrapper">
                    <h4>Dimensi Dosen</h4>
                    <div class="chart-canvas">
                        <canvas id="dosenDimensionChart"></canvas>
                    </div>
                </div>
                <div class="chart-wrapper">
                    <h4>Dimensi Mahasiswa</h4>
                    <div class="chart-canvas">
                        <canvas id="mahasiswaDimensionChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="section-content interactive-stats-section">
                <h3 class="mb-4 text-primary">Informasi Umum Digitalisasi Pembelajaran Pada Perguruan Tinggi</h3>
                <div class="mb-4 d-flex justify-content-center">
                    <div class="col-md-5">
						<p>Silahkan pilih nama Perguruan Tinggi yang ingin Anda lihat.</p>
					</div>
					<div class="col-md-5">
                        <label for="institutionSelect" class="form-label visually-hidden">Pilih Institusi:</label>
                        <select class="form-select" id="institutionSelect">
                            <option value="">-- Pilih Perguruan Tinggi --</option>
                            <?php foreach ($institutions as $inst): ?>
                                <option value="<?php echo htmlspecialchars($inst['id']); ?>">
                                    <?php echo htmlspecialchars($inst['nama_pt']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="statisticsDisplay" style="display: none;">
                    <hr>
                    <h6 class="text-center mb-4">Statistik Digitalisasi Pembelajaran di <span id="selectedInstitutionName" class="text-primary"></span></h6>
                    <div class="chart-row">
                        <div class="chart-col">
                            <h6 class="text-center">Rata-rata TEMUS per Program Studi</h6>
                            <div class="chart-canvas-large">
                                <canvas id="averageTemusChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-col">
                            <h6 class="text-center">Distribusi Global Dimensi TEMUS</h6>
                            <div class="chart-canvas-large">
                                <canvas id="globalTemusDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <p class="mt-4 text-muted text-center" id="chartDescription">
                        Silakan pilih institusi untuk melihat statistik rata-rata TEMUS berdasarkan Program Studi dan distribusi global dimensi TEMUS di institusi tersebut.
                    </p>
                </div>
                <div id="noDataMessage" class="alert alert-info text-center mt-3" style="display: none;">
                    Tidak ada data evaluasi yang cukup untuk institusi ini.
                </div>
            </div>
        </div>

        <div class="section-content sus-results-section" id="sus-results-section">
            <h3 class="mb-4 text-primary">Evaluasi Platform MATLEV 5D</h3><hr>
            <?php if ($total_respondents_sus > 0): ?>
                <p class="text-center text-muted mb-4">
                    Berdasarkan masukan dari <strong><?php echo $total_respondents_sus; ?></strong> pengguna MATLEV 5D.
                </p>

                <div class="row justify-content-center">
                    <div class="col-md-4 mb-3">
                        <div class="sus-score-box">
                            <h4>Feature Usability</h4>
                            <div class="score-value"><?php echo number_format($overall_usability_score, 2); ?></div>
                            <div class="score-interpretation <?php echo strtolower(str_replace(' ', '', interpretScore($overall_usability_score))); ?>">
                                (<?php echo interpretScore($overall_usability_score); ?>)
                            </div>
                            <div class="progress mt-3 w-100">
                                <div class="progress-bar progress-bar-custom <?php echo strtolower(str_replace(' ', '', interpretScore($overall_usability_score))); ?>" role="progressbar" style="width: <?php echo $overall_usability_score; ?>%;" aria-valuenow="<?php echo $overall_usability_score; ?>" aria-valuemin="0" aria-valuemax="100">
                                    <?php echo number_format($overall_usability_score, 2); ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="sus-score-box">
                            <h4>System Functional</h4>
                            <div class="score-value"><?php echo number_format($overall_fungsionalitas_score, 2); ?></div>
                            <div class="score-interpretation <?php echo strtolower(str_replace(' ', '', interpretScore($overall_fungsionalitas_score))); ?>">
                                (<?php echo interpretScore($overall_fungsionalitas_score); ?>)
                            </div>
                            <div class="progress mt-3 w-100">
                                <div class="progress-bar progress-bar-custom <?php echo strtolower(str_replace(' ', '', interpretScore($overall_fungsionalitas_score))); ?>" role="progressbar" style="width: <?php echo $overall_fungsionalitas_score; ?>%;" aria-valuenow="<?php echo $overall_fungsionalitas_score; ?>" aria-valuemin="0" aria-valuemax="100">
                                    <?php echo number_format($overall_fungsionalitas_score, 2); ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="sus-score-box">
                            <h4>User Experience (UX)</h4>
                            <div class="score-value"><?php echo number_format($overall_ux_score, 2); ?></div>
                            <div class="score-interpretation <?php echo strtolower(str_replace(' ', '', interpretScore($overall_ux_score))); ?>">
                                (<?php echo interpretScore($overall_ux_score); ?>)
                            </div>
                            <div class="progress mt-3 w-100">
                                <div class="progress-bar progress-bar-custom <?php echo strtolower(str_replace(' ', '', interpretScore($overall_ux_score))); ?>" role="progressbar" style="width: <?php echo $overall_ux_score; ?>%;" aria-valuenow="<?php echo $overall_ux_score; ?>" aria-valuemin="0" aria-valuemax="100">
                                    <?php echo number_format($overall_ux_score, 2); ?>%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sus-score-box overall-score-box mt-4">
                    <h4 class="text-white">Platform Eligibility Accumulation</h4>
                    <div class="score-value text-white"><?php echo number_format($overall_total_score, 2); ?></div>
                    <div class="score-interpretation text-white <?php echo strtolower(str_replace(' ', '', interpretScore($overall_total_score))); ?>">
                        (<?php echo interpretScore($overall_total_score); ?>)
                    </div>
                    <div class="progress mt-3 w-100">
                        <div class="progress-bar progress-bar-custom <?php echo strtolower(str_replace(' ', '', interpretScore($overall_total_score))); ?>" role="progressbar" style="width: <?php echo $overall_total_score; ?>%;" aria-valuenow="<?php echo $overall_total_score; ?>" aria-valuemin="0" aria-valuemax="100">
                            <?php echo number_format($overall_total_score, 2); ?>%
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="alert alert-info text-center" role="alert">
                    <?php echo $error_message_sus; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="section-content" id="faq">
            <h3>Pertanyaan Umum (FAQ)</h3><hr>
            <p>Berikut adalah beberapa pertanyaan umum tentang aplikasi MATLEV 5D:</p>
            <div class="accordion" id="faqAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingOne">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne">
                            Apa itu MATLEV 5D?
                        </button>
                    </h2>
                    <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            MATLEV 5D adalah aplikasi untuk mengukur tingkat kematangan digitalisasi pembelajaran berdasarkan aspek Metode, Materi, Media ke dalam dimensi Traditional, Enhance, Mobile, Ubiquitous, dan Smart, dengan penilaian dari perspektif RPS Mata Kuliah, Dosen, dan Mahasiswa.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingTwo">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                            Siapa yang bisa menggunakan MATLEV 5D?
                        </button>
                    </h2>
                    <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Aplikasi ini dirancang untuk Dosen dan Mahasiswa yang ingin mengevaluasi tingkat digitalisasi pembelajaran mata kuliah. Administrator juga dapat mengelola data di dalamnya.
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingThree">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                            Bagaimana cara melakukan evaluasi?
                        </button>
                    </h2>
                    <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Setelah login, Anda akan diarahkan ke dashboard Anda. Di sana, Anda bisa memilih mata kuliah dan memulai proses evaluasi dengan mengisi kuesioner yang tersedia.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-content" id="helpdesk">
            <h3>Helpdesk</h3><hr>
            <div class="section-row helpdesk-section-layout">
                <div class="section-col-left">
                    <p>
                        Jika Anda tertarik dengan aplikasi ini atau ingin mendiskusikan lebih lanjut, Silakan hubungi kami melalui salah satu cara di bawah ini:
                    </p>
                    <ul>
                        <li>Via Email, <a href="mailto:isantiko@students.undip.ac.id" target="_blank"><img src="https://img.favpng.com/16/1/21/computer-icons-email-icon-design-stock-photography-png-favpng-USRFBVVq4MUf7UuwxrxiniFWJ.jpg" width="24"></a></li>
                        <li>Via WhatsApp, <a href="https://wa.me/081542308186" target="_blank"><img src="https://cdn-icons-png.freepik.com/256/15707/15707917.png" width="24"></a></li>
                        <li><a href="assets/upload/manual.pdf" class="btn btn-primary">Manual</a></li>
                    </ul>
                    <p>
                        Kontribusi Anda akan sangat membantu untuk mengembangkan paltform MATLEV 5D ini.
                    </p>
                </div>
                <div class="section-col-right">
                    <img src="assets/img/help.jpg" alt="Helpdesk Illustration" class="section-image">
                </div>
            </div>
        </div>

        <div class="section-content" id="contact">
            <h3>Alamat Kami</h3><hr>
            <div class="contact-grid">
                <div class="contact-address">
                    <p align="left"><img src="assets/img/undip.jpg" alt="undip" width="300"></p>
                    <address>
                        <strong>Tim Pengembang MATLEV 5D</strong><br>
                        Laboratorium Riset & Rekayasa Perangkat Lunak<br>
                        Program Doktor Sistem Informasi<br>
                        Jl. Imam Bardjo SH No.5, RT.002, Pleburan, Kec. Semarang Sel., Kota Semarang, Jawa Tengah 50241<br>
                        Indonesia<br>
                        <br>
                    </address>
                </div>
                <div class="contact-map">
                    <div class="map-container">
                        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3959.88040798781!2d110.4216892!3d-7.0142838!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4m0!3e0!5m2!1sen!2sid!4e0" width="350" height="350" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="footer">
        <p>© <?php echo date('Y'); ?> MATLEV 5D. All Rights Reserved. Project By Irfan Santiko - 30000320520035</p>
        <p>Model Platform Evaluasi Tingkat Kematangan dan Efektivitas Digitalisasi Pembelajaran Menggunakan Aspek 3M dan Dimensi TEMUS</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
        // Warna standar untuk chart (bisa disesuaikan)
        const chartColors = [
            '#A73B00', // Traditional
            '#CFC800', // Enhance
            '#14A700', // Mobile
            '#00A795', // Ubiquitous
            '#006AA7'  // Smart
        ];
        // Border colors will be the same as fill colors for no contrast
        const chartBorderColors = chartColors; // Menggunakan warna yang sama untuk border dan fill

        // Definisi dimensi TEMUS secara global
        const dimensionLabels = ['Traditional', 'Enhance', 'Mobile', 'Ubiquitous', 'Smart'];

        // Data dari PHP, pastikan di-encode dengan benar
        const rpsValues = <?php echo json_encode(array_values($rps_dimension_data)); ?>;
        const dosenValues = <?php echo json_encode(array_values($dosen_dimension_data)); ?>;
        const mahasiswaValues = <?php echo json_encode(array_values($mahasiswa_dimension_data)); ?>;

        // Fungsi untuk mendapatkan warna berdasarkan rata-rata skor TEMUS
        // Menggunakan warna yang sama seperti yang ditentukan
        function getColorForAverageTemusScore(score) {
            if (score < 2.0) {
                return '#A73B00'; // Traditional
            } else if (score >= 2.0 && score < 3.0) {
                return '#CFC800'; // Enhance
            } else if (score >= 3.0 && score < 4.0) {
                return '#14A700'; // Mobile
            } else if (score >= 4.0 && score <= 4.5) {
                return '#00A795'; // Ubiquitous
            } else if (score > 4.5) {
                return '#006AA7'; // Smart
            }
            return '#808080'; // Default grey if score is out of range
        }


        // Fungsi untuk membuat pie chart
        function createPieChart(ctxId, title, dataValues) {
            const ctx = document.getElementById(ctxId).getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: dimensionLabels, // Menggunakan variabel global dimensionLabels
                    datasets: [{
                        label: title,
                        data: dataValues,
                        backgroundColor: chartColors, // Menggunakan warna yang sudah ditentukan
                        borderColor: chartBorderColors, // Menggunakan warna border yang sudah ditentukan
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                // Filter ini akan memastikan semua item legenda ditampilkan,
                                // bahkan jika nilai data-nya 0.
                                filter: function(legendItem, chartData) {
                                    return true; // Selalu kembalikan true untuk menampilkan semua item
                                }
                            }
                        },
                        title: {
                            display: true,
                            text: title
                        },
                        tooltip: { // Menambahkan konfigurasi tooltip agar lebih informatif
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        const total = context.dataset.data.reduce((acc, current) => acc + current, 0);
                                        // Hindari pembagian dengan nol jika totalnya 0
                                        const percentage = total === 0 ? 0 : (context.parsed / total * 100).toFixed(2);
                                        label += context.parsed + ' (' + percentage + '%)';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Inisialisasi Chart.js setelah DOM dimuat
        document.addEventListener('DOMContentLoaded', function() {
            createPieChart('rpsDimensionChart', 'RPS Mata Kuliah', rpsValues);
            createPieChart('dosenDimensionChart', 'Gaya Dosen', dosenValues);
            createPieChart('mahasiswaDimensionChart', 'Pengalaman Mahasiswa', mahasiswaValues);
        });

        // Global chart instances for the new interactive charts
        var averageTemusChartInstance;
        var globalTemusDistributionChartInstance;

        // New interactive charts logic
        $('#institutionSelect').change(function() {
            var institutionId = $(this).val();
            var institutionName = $(this).find('option:selected').text();
            
            if (institutionId) {
                $('#selectedInstitutionName').text(institutionName);
                $('#statisticsDisplay').show();
                $('#noDataMessage').hide(); // Hide no data message initially
                $('#chartDescription').text('Memuat data statistik untuk ' + institutionName + '...');

                // Fetch data via AJAX
                $.ajax({
                    url: 'api/get_institution_stats.php',
                    type: 'GET',
                    data: { institution_id: institutionId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            if (response.has_data) {
                                $('#chartDescription').text('Statistik digitalisasi pembelajaran di ' + institutionName + '.');
                                $('#noDataMessage').hide();

                                // Generate colors for the bars based on scores
                                const barBackgroundColors = response.avg_temus_data.data.map(score => getColorForAverageTemusScore(score));
                                // Border colors will now be the same as background colors for no contrast
                                const barBorderColors = barBackgroundColors;

                                // Update Average TEMUS Chart (Horizontal Bar Chart)
                                if (averageTemusChartInstance) averageTemusChartInstance.destroy();
                                const avgTemusCtx = document.getElementById('averageTemusChart').getContext('2d');
                                averageTemusChartInstance = new Chart(avgTemusCtx, {
                                    type: 'bar',
                                    data: {
                                        labels: response.avg_temus_data.labels,
                                        datasets: [{
                                            label: 'Rata-rata TEMUS',
                                            data: response.avg_temus_data.data,
                                            backgroundColor: barBackgroundColors, // <--- Warna dinamis
                                            borderColor: barBorderColors,       // <--- Border dinamis (sekarang sama dengan fill)
                                            borderWidth: 1
                                        }]
                                    },
                                    options: {
                                        indexAxis: 'y', // <--- PENTING: Mengubah menjadi horizontal bar chart
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        scales: {
                                            x: { // x-axis sekarang adalah sumbu nilai
                                                beginAtZero: true,
                                                max: 5, // Assuming TEMUS score is 1-5
                                                title: {
                                                    display: true,
                                                    text: 'Skor Rata-rata'
                                                }
                                            },
                                            y: { // y-axis sekarang adalah sumbu kategori (program studi)
                                                // Tidak perlu beginAtZero atau max di sini
                                            }
                                        },
                                        plugins: {
                                            legend: {
                                                display: false // Legenda tidak perlu karena warna sudah diinterpretasikan
                                            },
                                            tooltip: {
                                                callbacks: {
                                                    label: function(context) {
                                                        let label = context.dataset.label || '';
                                                        if (label) {
                                                            label += ': ';
                                                        }
                                                        if (context.parsed.x !== null) {
                                                            label += context.parsed.x.toFixed(2);
                                                        }
                                                        // Optional: Add interpretation text to tooltip
                                                        let interpretation = '';
                                                        const score = context.parsed.x;
                                                        if (score < 2.0) {
                                                            interpretation = ' (Traditional)';
                                                        } else if (score >= 2.0 && score < 3.0) {
                                                            interpretation = ' (Enhance)';
                                                        } else if (score >= 3.0 && score < 4.0) {
                                                            interpretation = ' (Mobile)';
                                                        } else if (score >= 4.0 && score <= 4.5) {
                                                            interpretation = ' (Ubiquitous)';
                                                        } else if (score > 4.5) {
                                                            interpretation = ' (Smart)';
                                                        }
                                                        return label + interpretation;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                });

                                // Update Global TEMUS Distribution Chart (Pie Chart)
                                if (globalTemusDistributionChartInstance) globalTemusDistributionChartInstance.destroy();
                                const globalTemusCtx = document.getElementById('globalTemusDistributionChart').getContext('2d');
                                globalTemusDistributionChartInstance = new Chart(globalTemusCtx, {
                                    type: 'pie',
                                    data: {
                                        labels: response.global_temus_distribution_data.labels,
                                        datasets: [{
                                            label: 'Distribusi Dimensi TEMUS',
                                            data: response.global_temus_distribution_data.data,
                                            backgroundColor: chartColors, // Re-use existing TEMUS colors
                                            borderColor: chartBorderColors, // Re-use existing TEMUS colors (sekarang sama dengan fill)
                                            borderWidth: 1
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: {
                                            legend: {
                                                position: 'top',
                                                labels: {
                                                    // Filter ini juga memastikan semua item legenda ditampilkan
                                                    filter: function(legendItem, chartData) {
                                                        return true; // Selalu kembalikan true
                                                    }
                                                }
                                            },
                                            tooltip: {
                                                callbacks: {
                                                    label: function(context) {
                                                        let label = context.label || '';
                                                        if (label) {
                                                            label += ': ';
                                                        }
                                                        if (context.parsed !== null) {
                                                            const total = context.dataset.data.reduce((acc, current) => acc + current, 0);
                                                            const percentage = total === 0 ? 0 : (context.parsed / total * 100).toFixed(2);
                                                            label += context.parsed + ' (' + percentage + '%)';
                                                        }
                                                        return label;
                                                    }
                                                }
                                            },
                                            title: {
                                                display: true,
                                                text: 'Distribusi Global Dimensi TEMUS'
                                            }
                                        }
                                    }
                                });

                            } else {
                                // No data for this institution
                                $('#statisticsDisplay').hide();
                                $('#noDataMessage').show().text('Tidak ada data evaluasi yang cukup untuk institusi ' + institutionName + '.');
                            }
                        } else {
                            // API reported an error
                            $('#statisticsDisplay').hide();
                            $('#noDataMessage').show().text('Gagal memuat data: ' + response.message);
                            console.error("API Error: " + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#statisticsDisplay').hide();
                        $('#noDataMessage').show().text('Terjadi kesalahan saat mengambil data. Silakan coba lagi.');
                        console.error("AJAX Error: " + status + ": " + error);
                    }
                });
            } else {
                $('#statisticsDisplay').hide();
                $('#noDataMessage').hide();
                $('#chartDescription').text('Silakan pilih institusi untuk melihat statistik...');
            }
        });
    </script>
</body>
</html>