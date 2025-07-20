<?php
session_start();
require_once '../../config/database.php';

// --- Proteksi Halaman: HANYA DOSEN yang bisa mengakses ---
// Pastikan user_id dan role_id ada di sesi dan role_id adalah Dosen (2)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: ../../login.php?error=Akses tidak diizinkan. Silakan login sebagai Dosen.');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name']; // Ini akan berisi "Dosen"

// Pastikan institution_id ada di sesi.
if (!isset($_SESSION['institution_id']) || $_SESSION['institution_id'] === null) {
    header('Location: ../../login.php?error=Data institusi tidak ditemukan untuk akun Anda. Silakan login ulang.');
    exit();
}
$institution_id = $_SESSION['institution_id'];

$message = '';
$error = '';
$questions_by_aspect = [];
$assessment_aspects = [];
$self_assessment_answers_from_db = [];
$existing_notes_general = '';

// Inisialisasi variabel untuk pemilihan mata kuliah
$current_course_id = $_GET['course_id'] ?? null; // Ambil course_id dari parameter URL
$course_name_display = "Pilih Mata Kuliah"; // Teks default untuk tampilan
$courses_diampu = []; // Daftar mata kuliah yang diampu dosen

// Inisialisasi skor aspek dan dimensi untuk tampilan
$current_self_assessment_scores = [
    'metode_score' => 0.00,
    'materi_score' => 0.00,
    'media_score' => 0.00,
    'overall_3m_score' => 0.00,
    'dimension_name' => 'Belum Mengukur' // Default
];

try {
    // Ambil daftar mata kuliah yang diampu dosen
    $stmt_courses_diampu = $pdo->prepare("
        SELECT c.id, c.course_name
        FROM dosen_courses dc
        JOIN courses c ON dc.course_id = c.id
        WHERE dc.dosen_id = :dosen_id
        ORDER BY c.course_name
    ");
    $stmt_courses_diampu->execute([':dosen_id' => $user_id]);
    $courses_diampu = $stmt_courses_diampu->fetchAll(PDO::FETCH_ASSOC);

    // Validasi current_course_id jika ada
    if ($current_course_id) {
        $found_course = false;
        foreach ($courses_diampu as $course) {
            if ($course['id'] == $current_course_id) {
                $course_name_display = htmlspecialchars($course['course_name']);
                $found_course = true;
                break;
            }
        }
        if (!$found_course) {
            $current_course_id = null; // Reset jika course_id tidak valid untuk dosen ini
            $error = "Mata kuliah yang dipilih tidak valid atau tidak diampu oleh Anda.";
        }
    }

    // Ambil daftar aspek (Metode, Materi, Media)
    $stmt_aspects = $pdo->query("SELECT id, aspect_name FROM assessment_aspects ORDER BY id");
    $assessment_aspects = $stmt_aspects->fetchAll(PDO::FETCH_ASSOC);

    // Ambil pertanyaan untuk self-assessment (question_type_id = 2)
    $stmt_questions = $pdo->prepare("
        SELECT id, aspect_id, question_text
        FROM assessment_questions
        WHERE question_type_id = 2 AND is_active = 1
        ORDER BY aspect_id, order_index
    ");
    $stmt_questions->execute();
    $raw_questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_questions as $q) {
        $questions_by_aspect[$q['aspect_id']][] = $q;
    }

    // Ambil data self-assessment dosen yang sudah ada untuk mata kuliah yang dipilih
    if ($current_course_id) {
        $stmt_existing_self_assessment = $pdo->prepare("
            SELECT
                id AS self_assessment_id,
                metode_score,
                materi_score,
                media_score,
                overall_3m_score,
                notes,
                classified_temus_id
            FROM self_assessments_3m
            WHERE user_id = :user_id AND course_id = :course_id
            AND (user_role_at_assessment = :role_id OR user_role_at_assessment = :role_name)
            ORDER BY assessment_date DESC, created_at DESC
            LIMIT 1
        ");
        $stmt_existing_self_assessment->execute([
            ':user_id' => $user_id,
            ':course_id' => $current_course_id,
            ':role_id' => $_SESSION['role_id'], // Mencari berdasarkan ID numerik (misal: 2)
            ':role_name' => $_SESSION['role_name'] // Mencari berdasarkan nama string (misal: "Dosen")
        ]);
        $existing_self_assessment = $stmt_existing_self_assessment->fetch(PDO::FETCH_ASSOC);

        if ($existing_self_assessment) {
            $self_assessment_id_for_answers = $existing_self_assessment['self_assessment_id'];
            $current_self_assessment_scores['metode_score'] = (float)$existing_self_assessment['metode_score'];
            $current_self_assessment_scores['materi_score'] = (float)$existing_self_assessment['materi_score'];
            $current_self_assessment_scores['media_score'] = (float)$existing_self_assessment['media_score'];
            $current_self_assessment_scores['overall_3m_score'] = (float)$existing_self_assessment['overall_3m_score'];
            $existing_notes_general = $existing_self_assessment['notes'];

            // Ambil nama dimensi berdasarkan classified_temus_id
            if ($existing_self_assessment['classified_temus_id']) {
                $stmt_dimension_name = $pdo->prepare("SELECT dimension_name FROM temus_dimensions WHERE id = :id");
                $stmt_dimension_name->execute([':id' => $existing_self_assessment['classified_temus_id']]);
                $dimension_name_from_db = $stmt_dimension_name->fetchColumn();
                $current_self_assessment_scores['dimension_name'] = $dimension_name_from_db ?: 'Tidak Terdefinisi';
            } else {
                $current_self_assessment_scores['dimension_name'] = 'Belum Mengukur';
            }

            // Ambil detail jawaban self-assessment
            $stmt_existing_answers = $pdo->prepare("
                SELECT question_id, answer_value
                FROM self_assessment_answers
                WHERE self_assessment_id = :self_assessment_id
                ORDER BY question_id
            ");
            $stmt_existing_answers->execute([':self_assessment_id' => $self_assessment_id_for_answers]);
            $raw_existing_answers = $stmt_existing_answers->fetchAll(PDO::FETCH_ASSOC);
            foreach ($raw_existing_answers as $answer) {
                $self_assessment_answers_from_db[$answer['question_id']] = (float)$answer['answer_value'];
            }
        }
    }

} catch (PDOException $e) {
    error_log("Error fetching self-assessment data: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat data evaluasi mandiri: " . $e->getMessage();
}

// --- Proses Form Pengisian Evaluasi Mandiri ---
if (isset($_POST['submit_self_assessment'])) {
    $question_scores = $_POST['score'] ?? [];
    $notes_general_submitted = trim($_POST['notes_general'] ?? '');
    $selected_course_id = $_POST['selected_course_id'] ?? null; // Ambil course_id dari hidden input form

    // Validasi course_id dari POST
    if (empty($selected_course_id) || !is_numeric($selected_course_id)) {
        $error = "Mata kuliah harus dipilih untuk menyimpan evaluasi.";
    } elseif (empty($question_scores)) {
        $error = "Anda harus mengisi setidaknya satu penilaian.";
    } else {
        // Pastikan selected_course_id valid untuk dosen ini
        $is_course_valid = false;
        foreach ($courses_diampu as $course) {
            if ($course['id'] == $selected_course_id) {
                $is_course_valid = true;
                break;
            }
        }

        if (!$is_course_valid) {
            $error = "Mata kuliah yang dipilih tidak valid atau tidak diampu oleh Anda.";
        } else {
            try {
                $pdo->beginTransaction();

                // Hitung rata-rata per aspek
                $aspect_scores_calculated = [];
                foreach ($assessment_aspects as $aspect) {
                    $aspect_total = 0;
                    $aspect_count = 0;
                    if (!empty($questions_by_aspect[$aspect['id']])) {
                        foreach ($questions_by_aspect[$aspect['id']] as $q) {
                            if (isset($question_scores[$q['id']])) {
                                $aspect_total += (float)$question_scores[$q['id']];
                                $aspect_count++;
                            }
                        }
                    }
                    $aspect_scores_calculated[$aspect['aspect_name']] = ($aspect_count > 0) ? $aspect_total / $aspect_count : 0.00;
                }

                // Hitung overall score
                $overall_score = ($aspect_scores_calculated['Metode'] + $aspect_scores_calculated['Materi'] + $aspect_scores_calculated['Media']) / 3;

                // Ambil ID Dimensi dari temus_dimensions
                $classified_temus_id = null;
                $stmt_dimension_id = $pdo->prepare("
                    SELECT id
                    FROM temus_dimensions
                    WHERE :overall_score BETWEEN min_score AND max_score
                ");
                $stmt_dimension_id->execute([':overall_score' => $overall_score]);
                $classified_temus_id = $stmt_dimension_id->fetchColumn();

                if ($classified_temus_id === false) {
                    throw new Exception("Dimensi TEMUS tidak ditemukan untuk skor " . number_format($overall_score, 2) . ". Pastikan rentang skor di tabel temus_dimensions sudah benar.");
                }

                // Cek apakah asesmen mandiri sudah ada untuk user ini DAN mata kuliah ini
                // Query ini juga diubah agar mencari baik berdasarkan ID numerik maupun string
                $stmt_check_self_assessment = $pdo->prepare("
                    SELECT id FROM self_assessments_3m
                    WHERE user_id = :user_id AND course_id = :course_id
                    AND (user_role_at_assessment = :role_id OR user_role_at_assessment = :role_name)
                ");
                $stmt_check_self_assessment->execute([
                    ':user_id' => $user_id,
                    ':course_id' => $selected_course_id,
                    ':role_id' => $_SESSION['role_id'], // Mencari berdasarkan ID numerik
                    ':role_name' => $_SESSION['role_name'] // Mencari berdasarkan nama string
                ]);
                $existing_self_assessment_id = $stmt_check_self_assessment->fetchColumn();


                if ($existing_self_assessment_id) {
                    // Update asesmen yang ada
                    $stmt_update_assessment = $pdo->prepare("
                        UPDATE self_assessments_3m
                        SET
                            metode_score = :metode_score,
                            materi_score = :materi_score,
                            media_score = :media_score,
                            overall_3m_score = :overall_3m_score,
                            classified_temus_id = :classified_temus_id,
                            assessment_date = NOW(),
                            notes = :notes,
                            user_role_at_assessment = :user_role_at_assessment_updated_value -- Pastikan nilai ini konsisten
                        WHERE id = :id AND user_id = :user_id AND course_id = :course_id
                    ");
                    $stmt_update_assessment->execute([
                        ':metode_score' => $aspect_scores_calculated['Metode'],
                        ':materi_score' => $aspect_scores_calculated['Materi'],
                        ':media_score' => $aspect_scores_calculated['Media'],
                        ':overall_3m_score' => $overall_score,
                        ':classified_temus_id' => $classified_temus_id,
                        ':notes' => $notes_general_submitted,
                        ':id' => $existing_self_assessment_id,
                        ':user_id' => $user_id,
                        ':course_id' => $selected_course_id,
                        ':user_role_at_assessment_updated_value' => $_SESSION['role_id'] // Disarankan untuk menyimpan ID numerik ke depannya
                    ]);

                    // Hapus jawaban lama sebelum memasukkan yang baru
                    $stmt_delete_answers = $pdo->prepare("DELETE FROM self_assessment_answers WHERE self_assessment_id = :self_assessment_id");
                    $stmt_delete_answers->execute([':self_assessment_id' => $existing_self_assessment_id]);
                    $self_assessment_id = $existing_self_assessment_id; // Gunakan ID yang sudah ada
                } else {
                    // Buat asesmen baru
                    $stmt_insert_assessment = $pdo->prepare("
                        INSERT INTO self_assessments_3m (user_id, course_id, user_role_at_assessment, metode_score, materi_score, media_score, overall_3m_score, notes, classified_temus_id)
                        VALUES (:user_id, :course_id, :user_role_at_assessment, :metode_score, :materi_score, :media_score, :overall_3m_score, :notes, :classified_temus_id)
                    ");
                    $stmt_insert_assessment->execute([
                        ':user_id' => $user_id,
                        ':course_id' => $selected_course_id,
                        ':user_role_at_assessment' => $_SESSION['role_id'], // Disarankan untuk menyimpan ID numerik ke depannya
                        ':metode_score' => $aspect_scores_calculated['Metode'],
                        ':materi_score' => $aspect_scores_calculated['Materi'],
                        ':media_score' => $aspect_scores_calculated['Media'],
                        ':overall_3m_score' => $overall_score,
                        ':notes' => $notes_general_submitted,
                        ':classified_temus_id' => $classified_temus_id
                    ]);
                    $self_assessment_id = $pdo->lastInsertId();
                }

                // Masukkan detail jawaban
                $stmt_insert_answer = $pdo->prepare("
                    INSERT INTO self_assessment_answers (self_assessment_id, question_id, answer_value)
                    VALUES (:self_assessment_id, :question_id, :answer_value)
                ");
                $valid_scores = [0, 0.25, 0.5, 0.75, 1];
                foreach ($question_scores as $question_id => $answer_value) {
                    if (!in_array((float)$answer_value, $valid_scores)) {
                        throw new Exception("Nilai penilaian tidak valid untuk pertanyaan ID " . $question_id);
                    }
                    $stmt_insert_answer->execute([
                        ':self_assessment_id' => $self_assessment_id,
                        ':question_id' => $question_id,
                        ':answer_value' => (float)$answer_value
                    ]);
                }

                $pdo->commit();
                // Redirect kembali ke halaman yang sama dengan course_id yang dipilih
                header('Location: dosen_evaluasi_mandiri.php?status=success&course_id=' . $selected_course_id);
                exit();

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error saving self-assessment: " . $e->getMessage());
                $error = "Terjadi kesalahan saat menyimpan evaluasi mandiri: " . $e->getMessage();
            }
        }
    }
}

// Cek parameter status dari URL setelah redirect
if (isset($_GET['status']) && $_GET['status'] == 'success') {
    $message = "Evaluasi Mandiri berhasil disimpan!";
}
// Error dari redirect juga perlu ditampilkan
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluasi Mandiri Dosen - MATLEV 5D</title>
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
            display: flex;
            flex-direction: column; /* Added for vertical alignment of items */
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

        .card-header {
            background-color: #007bff;
            color: white;
        }

        .info-card {
            background-color: #ffffff;
            border-left: 5px solid #007bff;
            border-radius: 5px;
            padding: 15px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        
        .info-card.dimension-card {
            border-left: 5px solid #28a745; /* Warna hijau untuk dimensi */
        }

        .info-card:hover {
            transform: translateY(-5px);
        }

        .info-card h5 {
            color: #007bff;
            font-weight: bold;
        }
        .info-card.dimension-card h5 {
            color: #28a745; /* Warna hijau untuk dimensi */
        }


        .info-card p {
            font-size: 1.2em;
            margin-bottom: 0;
        }

        .info-card.dimension-card p {
            font-size: 2.2em; /* Ukuran font lebih besar untuk dimensi */
            font-weight: bold;
            color: #28a745;
        }


        .score-options label {
            cursor: pointer;
            padding: 5px 10px;
            border: 1px solid #ccc;
            border-radius: 3px;
            margin-right: 5px;
            transition: background-color 0.2s, border-color 0.2s;
        }

        .score-options input[type="radio"] {
            display: none;
        }

        .score-options input[type="radio"]:checked + label {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        /* Footer styling to ensure it's centered */
        footer h6 {
            text-align: center;
            width: 100%;
        }
    </style>
</head>

<body>
    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
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
            <li class="nav-item dropdown active">
                <a class="nav-link active dropdown-toggle" href="#" id="evaluasiDropdown" role="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-fw fa-clipboard-list me-2"></i>Evaluasi
                </a>
                <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="evaluasiDropdown">
                    <li><a class="dropdown-item active" href="dosen_evaluasi_mandiri.php">Evaluasi Dosen Mandiri</a></li>
                    <?php // Cek apakah dosen adalah asesor untuk menampilkan menu asesmen RPS
                    // Jika Anda memiliki flag is_asesor di sesi atau tabel user
                    if (isset($_SESSION['is_asesor']) && $_SESSION['is_asesor'] == 1): ?>
                        <li><a class="dropdown-item" href="dosen_evaluasi_rps.php">Asesmen RPS (Asesor)</a></li>
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
            <li class="nav-item mt-auto"> <a class="nav-link" href="../../logout.php">
                    <i class="fas fa-fw fa-sign-out-alt me-2"></i>Logout
                </a>
            </li>
        </ul>
    </div>

    <div id="content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light rounded-3 mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">Evaluasi Dosen Mandiri</a>
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
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Pilih Mata Kuliah untuk Evaluasi Mandiri</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="course_select" class="form-label">Mata Kuliah:</label>
                    <select class="form-select" id="course_select" name="course_id">
                        <option value="">-- Pilih Mata Kuliah --</option>
                        <?php foreach ($courses_diampu as $course): ?>
                            <option value="<?php echo htmlspecialchars($course['id']); ?>"
                                <?php echo ($current_course_id == $course['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['course_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <?php if ($current_course_id): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <h4 class="mb-3 text-secondary">Hasil Evaluasi Mandiri untuk Mata Kuliah: <span class="text-primary"><?php echo $course_name_display; ?></span></h4>
                </div>
                <div class="col-md-4">
                    <div class="card info-card text-center">
                        <div class="card-body">
                            <h5 class="card-title">Rata-rata Metode</h5>
                            <p class="card-text">
                                <strong style="font-size: 1.8em;"><?php echo htmlspecialchars(number_format($current_self_assessment_scores['metode_score'], 2)); ?></strong>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card info-card text-center">
                        <div class="card-body">
                            <h5 class="card-title">Rata-rata Materi</h5>
                            <p class="card-text">
                                <strong style="font-size: 1.8em;"><?php echo htmlspecialchars(number_format($current_self_assessment_scores['materi_score'], 2)); ?></strong>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card info-card text-center">
                        <div class="card-body">
                            <h5 class="card-title">Rata-rata Media</h5>
                            <p class="card-text">
                                <strong style="font-size: 1.8em;"><?php echo htmlspecialchars(number_format($current_self_assessment_scores['media_score'], 2)); ?></strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card info-card dimension-card mb-4 shadow">
                <div class="card-body text-center">
                    <h5 class="card-title">Dimensi Digitalisasi Pembelajaran Anda</h5>
                    <p class="card-text">
                        <?php echo htmlspecialchars($current_self_assessment_scores['dimension_name']); ?>
                    </p>
                    <small class="text-muted">Berdasarkan rata-rata keseluruhan (Metode, Materi, Media): <?php echo htmlspecialchars(number_format($current_self_assessment_scores['overall_3m_score'], 2)); ?></small>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-clipboard-question me-2"></i>Isi Evaluasi Mandiri Anda</h5>
                </div>
                <div class="card-body">
                    <form action="dosen_evaluasi_mandiri.php" method="POST">
                        <input type="hidden" name="selected_course_id" value="<?php echo htmlspecialchars($current_course_id); ?>">
                        <?php foreach ($assessment_aspects as $aspect): ?>
                            <h4 class="mt-4 mb-3 text-primary"><i class="fas fa-tags me-2"></i>Aspek: <?php echo htmlspecialchars($aspect['aspect_name']); ?></h4>
                            <?php if (!empty($questions_by_aspect[$aspect['id']])): ?>
                                <table class="table table-bordered table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 5%;">No.</th>
                                            <th style="width: 60%;">Pertanyaan</th>
                                            <th style="width: 35%;">Penilaian (0, 0.25, 0.5, 0.75, 1)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $q_num = 1; foreach ($questions_by_aspect[$aspect['id']] as $question): ?>
                                            <tr>
                                                <td><?php echo $q_num++; ?>.</td>
                                                <td><?php echo htmlspecialchars($question['question_text']); ?></td>
                                                <td>
                                                    <div class="score-options d-flex flex-wrap gap-2">
                                                        <?php
                                                        $scores_options = [0, 0.25, 0.5, 0.75, 1];
                                                        foreach ($scores_options as $val):
                                                            $checked = '';
                                                            if (isset($self_assessment_answers_from_db[$question['id']]) && (float) $self_assessment_answers_from_db[$question['id']] == $val) {
                                                                $checked = 'checked';
                                                            }
                                                            ?>
                                                            <input type="radio"
                                                                id="q_<?php echo $question['id']; ?>_val_<?php echo str_replace('.', '', (string) $val); ?>"
                                                                name="score[<?php echo $question['id']; ?>]"
                                                                value="<?php echo htmlspecialchars((string) $val); ?>" <?php echo $checked; ?>
                                                                required>
                                                            <label
                                                                for="q_<?php echo $question['id']; ?>_val_<?php echo str_replace('.', '', (string) $val); ?>"
                                                                class="btn btn-outline-primary btn-sm"><?php echo htmlspecialchars((string) $val); ?></label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="text-muted">Tidak ada pertanyaan yang tersedia untuk aspek ini.</p>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <div class="mb-3 mt-4">
                            <label for="notes_general" class="form-label">Catatan Umum (Opsional):</label>
                            <textarea class="form-control" id="notes_general" name="notes_general"
                                rows="3"><?php echo htmlspecialchars($existing_notes_general); ?></textarea>
                        </div>

                        <button type="submit" name="submit_self_assessment" class="btn btn-success btn-lg mt-3">
                            <i class="fas fa-save me-2"></i>Simpan Evaluasi Mandiri
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i>Silakan pilih mata kuliah dari daftar di atas untuk memulai atau melihat hasil evaluasi mandiri.
            </div>
        <?php endif; ?>
        
        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 style="width: 100%; text-align: center;">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a
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

            // Handle perubahan pilihan mata kuliah
            $('#course_select').on('change', function() {
                const selectedCourseId = $(this).val();
                if (selectedCourseId) {
                    // Redirect ke halaman yang sama dengan parameter course_id
                    window.location.href = 'dosen_evaluasi_mandiri.php?course_id=' + selectedCourseId;
                } else {
                    // Jika memilih "Pilih Mata Kuliah", redirect tanpa course_id
                    window.location.href = 'dosen_evaluasi_mandiri.php';
                }
            });
        });
    </script>
</body>

</html>