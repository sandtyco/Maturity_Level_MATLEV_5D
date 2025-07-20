<?php
session_start();
require_once '../../config/database.php'; // Ini seharusnya menyediakan variabel $pdo

// --- Proteksi Halaman: HANYA MAHASISWA yang bisa mengakses ---
// Pastikan user_id dan role_id ada di sesi dan role_id adalah Mahasiswa (3)
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) {
    header('Location: ../../login.php?error=Akses tidak diizinkan. Silakan login sebagai Mahasiswa.');
    exit();
}

$user_id = $_SESSION['user_id'];
$full_name_session = $_SESSION['full_name'] ?? 'Mahasiswa';
$username_session = $_SESSION['username'];
$role_name_session = $_SESSION['role_name'] ?? 'Mahasiswa'; // Assume role name if not set

$message = '';
$error = '';
$questions_by_aspect = [];
$assessment_aspects = [];
$self_assessment_answers_from_db = [];
$existing_notes_general = '';

// Inisialisasi skor aspek dan dimensi untuk tampilan
$current_self_assessment_scores = [
    'metode_score' => 0.00,
    'materi_score' => 0.00,
    'media_score' => 0.00,
    'overall_3m_score' => 0.00,
    'dimension_name' => 'Belum Mengukur' // Default sesuai permintaan
];

// --- Ambil Data Mata Kuliah yang Diambil Mahasiswa ---
$courses_taken = [];
try {
    $stmt_courses = $pdo->prepare("
        SELECT
            c.id, c.course_name, c.semester,
            dd.full_name AS dosen_name -- Ambil nama dosen pengampu
        FROM
            mahasiswa_courses mc
        JOIN
            courses c ON mc.course_id = c.id
        LEFT JOIN
            dosen_courses dc ON c.id = dc.course_id
        LEFT JOIN
            dosen_details dd ON dc.dosen_id = dd.user_id
        WHERE mc.mahasiswa_id = :user_id
        ORDER BY c.course_name
    ");
    $stmt_courses->execute([':user_id' => $user_id]);
    $courses_taken = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses_taken)) {
        $message .= "Anda belum terdaftar pada mata kuliah apapun. Silakan hubungi admin atau tambahkan di profil Anda.";
    }

} catch (PDOException $e) {
    error_log("Error fetching student courses for assessment: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat daftar mata kuliah: " . $e->getMessage();
}


// --- Ambil daftar aspek (Metode, Materi, Media) di awal karena sering digunakan ---
try {
    $stmt_aspects = $pdo->query("SELECT id, aspect_name FROM assessment_aspects ORDER BY id");
    $assessment_aspects = $stmt_aspects->fetchAll(PDO::FETCH_ASSOC);

    // Jika aspek tidak ditemukan, tambahkan pesan error atau default
    if (empty($assessment_aspects)) {
        $error .= "Aspek asesmen (Metode, Materi, Media) tidak ditemukan di database. Pastikan data sudah ada.";
    }

    // Ambil pertanyaan untuk self-assessment (question_type_id = 3 untuk Mahasiswa)
    $stmt_questions = $pdo->prepare("
        SELECT id, aspect_id, question_text
        FROM assessment_questions
        WHERE question_type_id = 3 AND is_active = 1
        ORDER BY aspect_id, order_index
    ");
    $stmt_questions->execute();
    $raw_questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_questions as $q) {
        $questions_by_aspect[$q['aspect_id']][] = $q;
    }

} catch (PDOException $e) {
    error_log("Error fetching aspects or questions: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat pertanyaan asesmen: " . $e->getMessage();
}


// --- Logic untuk Memuat Asesmen yang Sudah Ada (jika ada mata kuliah yang dipilih) ---
$selected_course_id = $_GET['course_id'] ?? null; // Ambil course_id dari URL jika ada
if ($selected_course_id) {
    // Sanitize the selected_course_id to prevent XSS
    $selected_course_id = htmlspecialchars($selected_course_id, ENT_QUOTES, 'UTF-8');

    try {
        // Ambil data self-assessment mahasiswa yang sudah ada untuk MATA KULIAH TERTENTU
        // Ambil data terbaru berdasarkan assessment_date
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
            WHERE user_id = :user_id AND course_id = :course_id AND user_role_at_assessment = 'Mahasiswa'
            ORDER BY assessment_date DESC, created_at DESC
            LIMIT 1
        ");
        $stmt_existing_self_assessment->execute([
            ':user_id' => $user_id,
            ':course_id' => $selected_course_id
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

    } catch (PDOException $e) {
        error_log("Error fetching self-assessment data for student: " . $e->getMessage());
        $error = "Terjadi kesalahan saat memuat data asesmen diri: " . $e->getMessage();
    }
}


// --- Proses Form Pengisian Evaluasi Mandiri ---
if (isset($_POST['submit_self_assessment'])) {
    $question_scores = $_POST['score'] ?? [];
    $notes_general_submitted = trim($_POST['notes_general'] ?? '');
    $selected_course_id_submitted = $_POST['course_id'] ?? null; // Course ID dari form

    if (empty($selected_course_id_submitted)) {
        $error = "Mata kuliah harus dipilih.";
    } elseif (empty($question_scores)) {
        $error = "Anda harus mengisi setidaknya satu penilaian.";
    } else {
        try {
            $pdo->beginTransaction();

            // Hitung rata-rata per aspek
            $aspect_scores_calculated = [];
            foreach ($assessment_aspects as $aspect) { // Gunakan $assessment_aspects yang sudah diambil
                $aspect_total = 0;
                $aspect_count = 0;
                // Pastikan pertanyaan untuk aspek ini ada sebelum looping
                if (isset($questions_by_aspect[$aspect['id']])) {
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
            $overall_score = 0;
            $counted_aspects = 0;
            if (isset($aspect_scores_calculated['Metode'])) { $overall_score += $aspect_scores_calculated['Metode']; $counted_aspects++; }
            if (isset($aspect_scores_calculated['Materi'])) { $overall_score += $aspect_scores_calculated['Materi']; $counted_aspects++; }
            if (isset($aspect_scores_calculated['Media'])) { $overall_score += $aspect_scores_calculated['Media']; $counted_aspects++; }

            $overall_score = ($counted_aspects > 0) ? $overall_score / $counted_aspects : 0.00;

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
                // Jangan throw exception fatal, cukup set error message
                $error = "Dimensi TEMUS tidak ditemukan untuk skor " . number_format($overall_score, 2) . ". Pastikan rentang skor di tabel temus_dimensions sudah benar.";
                $pdo->rollBack(); // Pastikan rollback jika ini dianggap error fatal
                header('Location: mahasiswa_asesmen_diri.php?error=' . urlencode($error) . '&course_id=' . $selected_course_id_submitted);
                exit();
            }

            // Cek apakah asesmen mandiri sudah ada untuk user dan mata kuliah ini
            $stmt_check_self_assessment = $pdo->prepare("
                SELECT id FROM self_assessments_3m
                WHERE user_id = :user_id AND course_id = :course_id AND user_role_at_assessment = 'Mahasiswa'
            ");
            $stmt_check_self_assessment->execute([
                ':user_id' => $user_id,
                ':course_id' => $selected_course_id_submitted
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
                        notes = :notes
                    WHERE id = :id
                ");
                $stmt_update_assessment->execute([
                    ':metode_score' => $aspect_scores_calculated['Metode'] ?? 0.00, // Tambahkan null coalescing
                    ':materi_score' => $aspect_scores_calculated['Materi'] ?? 0.00, // Tambahkan null coalescing
                    ':media_score' => $aspect_scores_calculated['Media'] ?? 0.00, // Tambahkan null coalescing
                    ':overall_3m_score' => $overall_score,
                    ':classified_temus_id' => $classified_temus_id,
                    ':notes' => $notes_general_submitted,
                    ':id' => $existing_self_assessment_id
                ]);

                // Hapus jawaban lama sebelum memasukkan yang baru
                $stmt_delete_answers = $pdo->prepare("DELETE FROM self_assessment_answers WHERE self_assessment_id = :self_assessment_id");
                $stmt_delete_answers->execute([':self_assessment_id' => $existing_self_assessment_id]);
                $self_assessment_id = $existing_self_assessment_id; // Gunakan ID yang sudah ada
            } else {
                // Buat asesmen baru
                $stmt_insert_assessment = $pdo->prepare("
                    INSERT INTO self_assessments_3m (user_id, course_id, user_role_at_assessment, metode_score, materi_score, media_score, overall_3m_score, notes, classified_temus_id, assessment_date)
                    VALUES (:user_id, :course_id, :user_role_at_assessment, :metode_score, :materi_score, :media_score, :overall_3m_score, :notes, :classified_temus_id, NOW())
                ");
                $stmt_insert_assessment->execute([
                    ':user_id' => $user_id,
                    ':course_id' => $selected_course_id_submitted,
                    ':user_role_at_assessment' => 'Mahasiswa', // Tetap 'Mahasiswa'
                    ':metode_score' => $aspect_scores_calculated['Metode'] ?? 0.00, // Tambahkan null coalescing
                    ':materi_score' => $aspect_scores_calculated['Materi'] ?? 0.00, // Tambahkan null coalescing
                    ':media_score' => $aspect_scores_calculated['Media'] ?? 0.00, // Tambahkan null coalescing
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
            $message = "Asesmen diri berhasil disimpan!";
            // Redirect kembali ke halaman yang sama dengan course_id yang dipilih
            header('Location: mahasiswa_asesmen_diri.php?status=success&course_id=' . $selected_course_id_submitted);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error saving self-assessment for student: " . $e->getMessage());
            $error = "Terjadi kesalahan saat menyimpan asesmen diri: " . $e->getMessage();
            // Redirect kembali dengan error dan course_id yang dipilih
            header('Location: mahasiswa_asesmen_diri.php?error=' . urlencode($e->getMessage()) . '&course_id=' . $selected_course_id_submitted);
            exit();
        }
    }
}

// Cek parameter status dari URL setelah redirect
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
    <title>Asesmen Diri Mahasiswa - MATLEV 5D</title>
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
            flex-direction: column;
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
        
        #sidebar .nav-item.mt-auto {
            margin-top: auto !important; /* Override bootstrap's mt-auto if needed */
        }

        #content {
            flex-grow: 1;
            padding: 20px;
        }

        .navbar-brand {
            font-weight: bold;
        }

        .info-card.dimension-card {
            border-left: 5px solid #28a745; /* Warna hijau untuk dimensi */
            /* --- Perubahan di sini untuk presisi --- */
            max-width: 600px; /* Batasi lebar maksimum */
            margin-left: auto; /* Pusatkan secara horizontal */
            margin-right: auto; /* Pusatkan secara horizontal */
            /* --- Akhir perubahan --- */
        }

        .info-card:hover {
            transform: translateY(-5px);
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
        /* New style for the question form card */
        .card-header-form {
            background-color: #007bff;
            color: white;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column flex-grow-1">
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
                <a class="nav-link active" href="mahasiswa_asesmen_diri.php">
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
            <li class="nav-item">
                <a class="nav-link" href="../../logout.php">
                    <i class="fas fa-fw fa-sign-out-alt me-2"></i>Logout
                </a>
            </li>
        </ul>
    </div>

    <div id="content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light rounded-3 mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">Asesmen Diri Mahasiswa</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($full_name_session); ?></strong> (<?php echo htmlspecialchars($role_name_session); ?>)
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
            <div class="card-header card-header-form">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Pilih Mata Kuliah Untuk Asesmen</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="course_select" class="form-label">Mata Kuliah Anda:</label>
                    <select class="form-select" id="course_select">
                        <option value="">-- Pilih Mata Kuliah --</option>
                        <?php foreach ($courses_taken as $course): ?>
                            <option value="<?php echo htmlspecialchars($course['id']); ?>"
                                <?php echo ($selected_course_id == $course['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['course_name']); ?> (Dosen: <?php echo htmlspecialchars($course['dosen_name'] ?? 'Belum Ditentukan'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small class="text-muted">Pilih mata kuliah untuk melihat hasil asesmen sebelumnya atau mengisi yang baru.</small>
            </div>
        </div>

        <?php if ($selected_course_id && !empty($questions_by_aspect)): ?>
            <div class="row mb-4">
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
                <div class="card-header card-header-form">
                    <h5 class="mb-0"><i class="fas fa-clipboard-question me-2"></i>Isi Asesmen Diri Anda untuk Mata Kuliah ini</h5>
                </div>
                <div class="card-body">
                    <form action="mahasiswa_asesmen_diri.php" method="POST">
                        <input type="hidden" name="submit_self_assessment" value="1">
                        <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($selected_course_id); ?>">

                        <?php
                        // Pastikan urutan aspek konsisten jika Anda memiliki urutan tertentu
                        $aspect_order = ['Metode', 'Materi', 'Media'];
                        foreach ($aspect_order as $aspect_name_str):
                            // Temukan ID aspek berdasarkan nama
                            $current_aspect_id = null;
                            foreach ($assessment_aspects as $aspect_db) {
                                if ($aspect_db['aspect_name'] == $aspect_name_str) {
                                    $current_aspect_id = $aspect_db['id'];
                                    break;
                                }
                            }

                            if ($current_aspect_id && !empty($questions_by_aspect[$current_aspect_id])):
                        ?>
                                <h4 class="mt-4 mb-3 text-primary"><i class="fas fa-tags me-2"></i>Aspek: <?php echo htmlspecialchars($aspect_name_str); ?></h4>
                                <table class="table table-bordered table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 5%;">No.</th>
                                            <th style="width: 60%;">Pertanyaan</th>
                                            <th style="width: 35%;">Penilaian (0, 0.25, 0.5, 0.75, 1)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $q_num = 1; foreach ($questions_by_aspect[$current_aspect_id] as $question): ?>
                                            <tr>
                                                <td><?php echo $q_num++; ?>.</td>
                                                <td><?php echo htmlspecialchars($question['question_text']); ?></td>
                                                <td>
                                                    <div class="score-options d-flex flex-wrap gap-2">
                                                        <?php
                                                        $scores_options = [0, 0.25, 0.5, 0.75, 1];
                                                        foreach ($scores_options as $val):
                                                            $checked = '';
                                                            // Menggunakan $self_assessment_answers_from_db yang dimuat dari DB
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
                                <p class="text-muted">Tidak ada pertanyaan yang tersedia untuk aspek <?php echo htmlspecialchars($aspect_name_str); ?>.</p>
                        <?php endif; ?>
                        <?php endforeach; ?>

                        <div class="mb-3 mt-4">
                            <label for="notes_general" class="form-label">Catatan Umum (Opsional):</label>
                            <textarea class="form-control" id="notes_general" name="notes_general"
                                rows="3"><?php echo htmlspecialchars($existing_notes_general); ?></textarea>
                        </div>

                        <button type="submit" name="submit_self_assessment" class="btn btn-success btn-lg mt-3">
                            <i class="fas fa-save me-2"></i>Simpan Asesmen Diri
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($selected_course_id && empty($questions_by_aspect)): ?>
            <div class="alert alert-danger" role="alert">
                Pertanyaan asesmen untuk mahasiswa belum tersedia atau ada masalah saat memuatnya. Mohon hubungi administrator.
            </div>
        <?php else: ?>
            <div class="alert alert-info" role="alert">
                Silakan pilih mata kuliah dari dropdown di atas untuk memulai atau melihat asesmen diri Anda.
            </div>
        <?php endif; ?>
        
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

            // Handle dropdown change event to reload the page with selected course_id
            $('#course_select').change(function() {
                var selectedCourseId = $(this).val();
                if (selectedCourseId) {
                    window.location.href = 'mahasiswa_asesmen_diri.php?course_id=' + selectedCourseId;
                } else {
                    window.location.href = 'mahasiswa_asesmen_diri.php'; // Kembali ke halaman tanpa course_id
                }
            });
        });
    </script>
</body>

</html>