<?php
session_start();
require_once '../../config/database.php';

// --- Proteksi Halaman: HANYA DOSEN ASESOR yang bisa mengakses ---
// Pastikan user_id, role_id, dan is_asesor ada di sesi
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2 || !($_SESSION['is_asesor'] ?? false)) {
    header('Location: dosen_dashboard.php?error=Akses tidak diizinkan. Hanya asesor yang bisa melakukan asesmen RPS.');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];

// --- Pastikan institution_id ada di sesi. Jika tidak, redirect atau berikan error. ---
if (!isset($_SESSION['institution_id']) || $_SESSION['institution_id'] === null) {
    // Jika institution_id tidak ada, berarti ada masalah saat login atau data dosen.
    header('Location: ../../login.php?error=Data institusi tidak ditemukan untuk akun Anda. Silakan login ulang.');
    exit();
}
$institution_id = $_SESSION['institution_id'];
// --- Akhir pengecekan institution_id ---

$is_asesor = true;

$message = '';
$error = '';
$questions_by_aspect = [];
$assessment_aspects = [];
$courses = [];
$selected_course_id = $_GET['course_id'] ?? null;
$assessment_answers_from_db = [];
$existing_notes_general = '';

// Inisialisasi skor aspek dan dimensi
$existing_aspect_scores = [
    'metode_score' => 0,
    'materi_score' => 0,
    'media_score' => 0,
    'dimension' => 'Belum Dinilai',
    'overall_3m_score' => 0 // Tambahkan inisialisasi untuk overall_3m_score
];

// Inisialisasi statistik dimensi
$dimension_counts = [
    'Traditional' => 0,
    'Enhance' => 0,
    'Mobile' => 0,
    'Ubiquitous' => 0,
    'Smart' => 0
];

try {
    // Ambil daftar aspek
    $stmt_aspects = $pdo->query("SELECT id, aspect_name FROM assessment_aspects ORDER BY id");
    $assessment_aspects = $stmt_aspects->fetchAll(PDO::FETCH_ASSOC);

    // Ambil pertanyaan
    $stmt_questions = $pdo->prepare("
        SELECT id, aspect_id, question_text
        FROM assessment_questions
        WHERE question_type_id = 1 AND is_active = 1
        ORDER BY aspect_id, order_index
    ");
    $stmt_questions->execute();
    $raw_questions = $stmt_questions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_questions as $q) {
        $questions_by_aspect[$q['aspect_id']][] = $q;
    }

    // Ambil daftar mata kuliah berdasarkan institution_id dosen
    $stmt_courses = $pdo->prepare("
        SELECT id, course_name
        FROM courses
        WHERE institution_id = :institution_id
        ORDER BY course_name
    ");
    $stmt_courses->execute([':institution_id' => $institution_id]);
    $courses = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

    // Jika mata kuliah dipilih, ambil data asesmen
    if ($selected_course_id) {
        $stmt_existing_rps_assessment = $pdo->prepare("
            SELECT
                id AS rps_assessment_id,
                metode_score,
                materi_score,
                media_score,
                overall_3m_score, /* <--- PASTIKAN KOLOM INI DIAMBIL */
                notes,
                classified_temus_id
            FROM rps_assessments
            WHERE course_id = :course_id AND assessor_user_id = :assessor_user_id
            ORDER BY assessment_date DESC, created_at DESC
            LIMIT 1 /* Ambil asesmen terbaru jika ada lebih dari satu */
        ");
        $stmt_existing_rps_assessment->execute([
            ':course_id' => $selected_course_id,
            ':assessor_user_id' => $user_id
        ]);
        $existing_rps_assessment = $stmt_existing_rps_assessment->fetch(PDO::FETCH_ASSOC);

        if ($existing_rps_assessment) {
            $rps_assessment_id_for_answers = $existing_rps_assessment['rps_assessment_id'];
            $existing_aspect_scores['metode_score'] = $existing_rps_assessment['metode_score'];
            $existing_aspect_scores['materi_score'] = $existing_rps_assessment['materi_score'];
            $existing_aspect_scores['media_score'] = $existing_rps_assessment['media_score'];
            $existing_aspect_scores['overall_3m_score'] = $existing_rps_assessment['overall_3m_score']; // Simpan nilai dari DB
            $existing_notes_general = $existing_rps_assessment['notes'];
            
            // Ambil nama dimensi berdasarkan classified_temus_id
            if ($existing_rps_assessment['classified_temus_id']) {
                $stmt_dimension_name = $pdo->prepare("SELECT dimension_name FROM temus_dimensions WHERE id = :id");
                $stmt_dimension_name->execute([':id' => $existing_rps_assessment['classified_temus_id']]);
                $dimension_name_from_db = $stmt_dimension_name->fetchColumn();
                $existing_aspect_scores['dimension'] = $dimension_name_from_db ?: 'Tidak Terdefinisi';
            } else {
                $existing_aspect_scores['dimension'] = 'Belum Dinilai';
            }

            // Ambil detail jawaban
            $stmt_existing_answers = $pdo->prepare("
                SELECT question_id, answer_value
                FROM rps_assessment_answers
                WHERE rps_assessment_id = :rps_assessment_id
                ORDER BY question_id
            ");
            $stmt_existing_answers->execute([':rps_assessment_id' => $rps_assessment_id_for_answers]);
            $raw_existing_answers = $stmt_existing_answers->fetchAll(PDO::FETCH_ASSOC);
            foreach ($raw_existing_answers as $answer) {
                $assessment_answers_from_db[$answer['question_id']] = $answer['answer_value'];
            }
        }
    }

    // Statistik Dimensi
    $stmt_dimension_counts = $pdo->prepare("
        SELECT
            td.dimension_name,
            COUNT(ra.id) AS course_count
        FROM
            rps_assessments ra
        JOIN
            temus_dimensions td ON ra.classified_temus_id = td.id -- Menggunakan join berdasarkan ID
        JOIN
            courses c ON ra.course_id = c.id
        WHERE
            c.institution_id = :institution_id
        GROUP BY
            td.dimension_name
    ");
    $stmt_dimension_counts->execute([':institution_id' => $institution_id]);
    $dimension_counts_result = $stmt_dimension_counts->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dimension_counts_result as $row) {
        $dimension_counts[$row['dimension_name']] = $row['course_count'];
    }

} catch (PDOException $e) {
    error_log("Error fetching assessment data: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat data asesmen: " . $e->getMessage();
}

// --- Proses Form Pengisian Asesmen ---
if (isset($_POST['submit_assessment'])) {
    $assessed_course_id = $_POST['assessed_course_id'];
    $question_scores = $_POST['score'] ?? [];
    $notes_general_submitted = trim($_POST['notes_general'] ?? '');

    if (empty($assessed_course_id)) {
        $error = "Pilih mata kuliah yang akan diasesmen.";
    } elseif (empty($question_scores)) {
        $error = "Anda harus mengisi setidaknya satu penilaian.";
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
                // Pastikan nama aspek konsisten dengan yang digunakan di perhitungan overall_score
                $aspect_name_key = $aspect['aspect_name']; // Ini akan menjadi 'Metode', 'Materi', 'Media'
                $aspect_scores_calculated[$aspect_name_key] = ($aspect_count > 0) ? $aspect_total / $aspect_count : 0;
            }

            // Hitung overall score (pastikan nama key sesuai dengan aspect_name)
            $overall_score = 0;
            if (isset($aspect_scores_calculated['Metode']) && isset($aspect_scores_calculated['Materi']) && isset($aspect_scores_calculated['Media'])) {
                $overall_score = ($aspect_scores_calculated['Metode'] + $aspect_scores_calculated['Materi'] + $aspect_scores_calculated['Media']) / 3;
            } else {
                // Handle kasus jika salah satu aspek tidak ditemukan (seharusnya tidak terjadi jika data aspek lengkap)
                throw new Exception("Skor salah satu aspek (Metode, Materi, Media) tidak ditemukan.");
            }
            
            // Format overall_score menjadi 2 desimal
            $overall_score = round($overall_score, 2);

            // --- Ambil ID Dimensi dari temus_dimensions ---
            $classified_temus_id = null;
            $stmt_dimension_id = $pdo->prepare("
                SELECT id
                FROM temus_dimensions
                WHERE :overall_score BETWEEN min_score AND max_score
            ");
            $stmt_dimension_id->execute([':overall_score' => $overall_score]);
            $classified_temus_id = $stmt_dimension_id->fetchColumn(); 

            if ($classified_temus_id === false) { 
                throw new Exception("Dimensi TEMUS tidak ditemukan untuk skor " . number_format($overall_score, 2) . ". Pastikan rentang skor di tabel temus_dimensions sudah benar dan mencakup semua kemungkinan.");
            }
            // --- AKHIR PERBAIKAN UTAMA ---

            // Cek apakah asesmen sudah ada
            $stmt_check_assessment = $pdo->prepare("SELECT id FROM rps_assessments WHERE course_id = :course_id AND assessor_user_id = :assessor_user_id");
            $stmt_check_assessment->execute([':course_id' => $assessed_course_id, ':assessor_user_id' => $user_id]);
            $existing_rps_assessment_id = $stmt_check_assessment->fetchColumn();

            if ($existing_rps_assessment_id) {
                // Update asesmen yang ada
                $stmt_update_assessment = $pdo->prepare("
                    UPDATE rps_assessments
                    SET
                        metode_score = :metode_score,
                        materi_score = :materi_score,
                        media_score = :media_score,
                        overall_3m_score = :overall_3m_score, /* <--- TAMBAHKAN INI */
                        classified_temus_id = :classified_temus_id,
                        assessment_date = NOW(),
                        notes = :notes
                    WHERE id = :id
                ");
                $stmt_update_assessment->execute([
                    ':metode_score' => $aspect_scores_calculated['Metode'],
                    ':materi_score' => $aspect_scores_calculated['Materi'],
                    ':media_score' => $aspect_scores_calculated['Media'],
                    ':overall_3m_score' => $overall_score, /* <--- TAMBAHKAN INI */
                    ':classified_temus_id' => $classified_temus_id,
                    ':notes' => $notes_general_submitted,
                    ':id' => $existing_rps_assessment_id
                ]);

                // Hapus jawaban lama
                $stmt_delete_answers = $pdo->prepare("DELETE FROM rps_assessment_answers WHERE rps_assessment_id = :rps_assessment_id");
                $stmt_delete_answers->execute([':rps_assessment_id' => $existing_rps_assessment_id]);
                $rps_assessment_id = $existing_rps_assessment_id; // Gunakan ID yang sudah ada
            } else {
                // Buat asesmen baru
                $stmt_insert_assessment = $pdo->prepare("
                    INSERT INTO rps_assessments (course_id, assessor_user_id, metode_score, materi_score, media_score, overall_3m_score, classified_temus_id, notes) /* <--- TAMBAHKAN overall_3m_score */
                    VALUES (:course_id, :assessor_user_id, :metode_score, :materi_score, :media_score, :overall_3m_score, :classified_temus_id, :notes) /* <--- TAMBAHKAN :overall_3m_score */
                ");
                $stmt_insert_assessment->execute([
                    ':course_id' => $assessed_course_id,
                    ':assessor_user_id' => $user_id,
                    ':metode_score' => $aspect_scores_calculated['Metode'],
                    ':materi_score' => $aspect_scores_calculated['Materi'],
                    ':media_score' => $aspect_scores_calculated['Media'],
                    ':overall_3m_score' => $overall_score, /* <--- TAMBAHKAN INI */
                    ':classified_temus_id' => $classified_temus_id,
                    ':notes' => $notes_general_submitted
                ]);
                $rps_assessment_id = $pdo->lastInsertId();
            }

            // Masukkan detail jawaban
            $stmt_insert_answer = $pdo->prepare("
                INSERT INTO rps_assessment_answers (rps_assessment_id, question_id, answer_value)
                VALUES (:rps_assessment_id, :question_id, :answer_value)
            ");
            $valid_scores = [0, 0.25, 0.5, 0.75, 1];
            foreach ($question_scores as $question_id => $answer_value) {
                if (!in_array((float)$answer_value, $valid_scores)) {
                    throw new Exception("Nilai penilaian tidak valid untuk pertanyaan ID " . $question_id);
                }
                $stmt_insert_answer->execute([
                    ':rps_assessment_id' => $rps_assessment_id,
                    ':question_id' => $question_id,
                    ':answer_value' => (float)$answer_value
                ]);
            }

            $pdo->commit();
            $message = "Asesmen RPS berhasil disimpan!";
            header('Location: dosen_evaluasi_rps.php?course_id=' . $assessed_course_id . '&status=success');
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error saving assessment: " . $e->getMessage());
            $error = "Terjadi kesalahan saat menyimpan asesmen: " . $e->getMessage();
        }
    }
}

// Cek parameter status dari URL setelah redirect
if (isset($_GET['status']) && $_GET['status'] == 'success') {
    $message = "Asesmen RPS berhasil disimpan!";
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
    <title>Asesmen RPS (Asesor) - MATLEV 5D</title>
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
                <a class="navbar-brand" href="#">Asesmen RPS (Asesor)</a>
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
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Pilih Mata Kuliah untuk Asesmen RPS</h5>
            </div>
            <div class="card-body">
                <form action="" method="GET" class="row g-3 align-items-center">
                    <div class="col-md-8">
                        <label for="course_select" class="form-label visually-hidden">Mata Kuliah</label>
                        <select class="form-select" id="course_select" name="course_id" required>
                            <option value="">-- Pilih Mata Kuliah --</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo htmlspecialchars($course['id']); ?>" <?php echo ($selected_course_id == $course['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Tampilkan
                            Pertanyaan</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selected_course_id): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i>Pertanyaan Asesmen RPS</h5>
                </div>
                <div class="card-body">
                    <form action="dosen_evaluasi_rps.php" method="POST">
                        <input type="hidden" name="assessed_course_id"
                            value="<?php echo htmlspecialchars($selected_course_id); ?>">

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
                                                            if (isset($assessment_answers_from_db[$question['id']]) && (float) $assessment_answers_from_db[$question['id']] == $val) {
                                                                $checked = 'checked';
                                                            }
                                                            ?>
                                                            <input type="radio"
                                                                id="q_<?php echo $question['id']; ?>_val_<?php echo str_replace('.', '', (string) $val); ?>"
                                                                name="score[<?php echo $question['id']; ?>]"
                                                                value="<?php echo htmlspecialchars($val); ?>" <?php echo $checked; ?>
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
                            <label for="notes_general" class="form-label">Catatan Umum Asesmen (Opsional):</label>
                            <textarea class="form-control" id="notes_general" name="notes_general"
                                rows="3"><?php echo htmlspecialchars($existing_notes_general); ?></textarea>
                        </div>

                        <button type="submit" name="submit_assessment" class="btn btn-success btn-lg mt-3">
                            <i class="fas fa-save me-2"></i>Simpan Asesmen
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Daftar Mata Kuliah yang Sudah Dinilai</h5>
                </div>
                <div class="card-body">
                    <?php if ($selected_course_id): ?>
                        <p>Mata kuliah yang sedang Anda nilai:</p>
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID Mata Kuliah</th>
                                    <th>Rata-rata Metode</th>
                                    <th>Rata-rata Materi</th>
                                    <th>Rata-rata Media</th>
                                    <th>Skor Overall RPS</th> <th>Dimensi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo htmlspecialchars($selected_course_id); ?></td>
                                    <td><?php echo htmlspecialchars(number_format($existing_aspect_scores['metode_score'], 2)); ?></td>
                                    <td><?php echo htmlspecialchars(number_format($existing_aspect_scores['materi_score'], 2)); ?></td>
                                    <td><?php echo htmlspecialchars(number_format($existing_aspect_scores['media_score'], 2)); ?></td>
                                    <td><?php echo htmlspecialchars(number_format($existing_aspect_scores['overall_3m_score'], 2)); ?></td> <td><?php echo htmlspecialchars($existing_aspect_scores['dimension']); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>Pilih mata kuliah untuk melihat detail penilaian.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info" role="alert">
                Silakan pilih mata kuliah dari dropdown di atas untuk memulai asesmen RPS.
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <?php foreach ($dimension_counts as $dimension => $count): ?>
                <div class="col-md-4">
                    <div class="card info-card">
                        <div class="card-body text-center">
                            <h5 class="card-title"><?php echo htmlspecialchars($dimension); ?></h5>
                            <p class="card-text">
                                <strong style="font-size: 1.8em;"><?php echo htmlspecialchars($count); ?></strong>
                            </p>
                            <small class="text-muted">Jumlah Mata Kuliah</small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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