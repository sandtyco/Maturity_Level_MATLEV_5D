<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path ke file database.php

// Proteksi halaman: hanya admin (role_id = 1) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: ../login.php'); // Arahkan ke halaman login
    exit();
}

$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];

$course_data = null; // Variabel untuk menyimpan data mata kuliah yang akan diedit
$institutions = []; // Variabel untuk menyimpan daftar institusi untuk dropdown
$programs_of_study_for_selected_institution = []; // Prodi yang terkait dengan institusi terpilih
$message = ''; // Untuk pesan sukses (digunakan jika tidak redirect)
$error = '';   // Untuk pesan error (digunakan jika tidak redirect)

// --- Ambil ID Mata Kuliah dari URL ---
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $course_id = (int)$_GET['id'];

    try {
        // Ambil data Mata Kuliah berdasarkan ID
        $stmt = $pdo->prepare("SELECT id, institution_id, program_of_study_id, course_name, courses_type, semester, sks_credits, rps_file_path FROM courses WHERE id = ?");
        $stmt->execute([$course_id]);
        $course_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course_data) {
            $_SESSION['error_message'] = "Mata Kuliah tidak ditemukan.";
            header('Location: manage_rps.php');
            exit();
        }

        // Ambil daftar institusi untuk dropdown
        $stmt_inst = $pdo->query("SELECT id, nama_pt FROM institutions ORDER BY nama_pt ASC");
        $institutions = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);

        // Ambil daftar program studi yang terkait dengan institusi dari mata kuliah yang diedit
        if ($course_data['institution_id']) {
            $stmt_prodi = $pdo->prepare("SELECT id, program_name FROM programs_of_study WHERE institution_id = ? ORDER BY program_name ASC");
            $stmt_prodi->execute([$course_data['institution_id']]);
            $programs_of_study_for_selected_institution = $stmt_prodi->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error mengambil data Mata Kuliah atau Institusi/Program Studi: " . $e->getMessage();
        header('Location: manage_rps.php');
        exit();
    }
} else {
    // Jika tidak ada ID, redirect kembali ke manage_rps.php
    header('Location: manage_rps.php');
    exit();
}

// --- LOGIKA UNTUK UPDATE MATA KULIAH ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_course'])) {
    $id = (int)$_POST['id']; // Pastikan ID dikirim kembali dari form
    $institution_id_new = (int)$_POST['institution_id'];
    $program_of_study_id_new = (int)$_POST['program_of_study_id'];
    $course_name_new = trim($_POST['course_name']);
    $courses_type_new = trim($_POST['courses_type']);
    $semester_new = trim($_POST['semester']);
    $sks_credits_new = (int)$_POST['sks_credits'];
    $rps_file_path_new = trim($_POST['rps_file_path']);

    if (empty($institution_id_new) || empty($program_of_study_id_new) || empty($course_name_new) || empty($courses_type_new) || empty($semester_new) || empty($sks_credits_new) || empty($rps_file_path_new)) {
        $error = "Semua kolom harus diisi.";
        // Jika ada error validasi, perbarui data yang ditampilkan di form agar tidak hilang
        $course_data['institution_id'] = $institution_id_new;
        $course_data['program_of_study_id'] = $program_of_study_id_new;
        $course_data['course_name'] = $course_name_new;
        $course_data['courses_type'] = $courses_type_new;
        $course_data['semester'] = $semester_new;
        $course_data['sks_credits'] = $sks_credits_new;
        $course_data['rps_file_path'] = $rps_file_path_new;

        // Ambil ulang prodi untuk institusi yang dipilih (jika berubah)
        if ($institution_id_new) {
            $stmt_prodi_update = $pdo->prepare("SELECT id, program_name FROM programs_of_study WHERE institution_id = ? ORDER BY program_name ASC");
            $stmt_prodi_update->execute([$institution_id_new]);
            $programs_of_study_for_selected_institution = $stmt_prodi_update->fetchAll(PDO::FETCH_ASSOC);
        }

    } elseif ($id !== $course_id) {
        $error = "ID Mata Kuliah tidak cocok.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE courses SET institution_id = ?, program_of_study_id = ?, course_name = ?, courses_type = ?, semester = ?, sks_credits = ?, rps_file_path = ? WHERE id = ?");
            $stmt->execute([$institution_id_new, $program_of_study_id_new, $course_name_new, $courses_type_new, $semester_new, $sks_credits_new, $rps_file_path_new, $id]);
            
            if ($stmt->rowCount()) {
                $_SESSION['success_message'] = "Mata Kuliah '<strong>" . htmlspecialchars($course_name_new) . "</strong>' berhasil diperbarui.";
            } else {
                $_SESSION['error_message'] = "Tidak ada perubahan yang terdeteksi atau Mata Kuliah gagal diperbarui.";
            }
            header('Location: manage_rps.php');
            exit();

        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error memperbarui Mata Kuliah: " . $e->getMessage();
            header('Location: manage_rps.php');
            exit();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Mata Kuliah (RPS) - Admin MATLEV 5D</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
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
    </style>
</head>
<body>

    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="admin_dashboard.php">
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
                <a class="nav-link active" href="manage_rps.php"> <i class="fas fa-fw fa-book me-2"></i>Data RPS Mata Kuliah
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
    <div id="content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light rounded-3 mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">Edit Mata Kuliah (RPS)</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role_name); ?>)
                </span>
            </div>
        </nav>

        <?php 
            // Untuk pesan error yang TIDAK menyebabkan redirect (misal: validasi input form)
            if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($course_data): // Tampilkan form hanya jika data mata kuliah ditemukan ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Edit Detail Mata Kuliah: <?php echo htmlspecialchars($course_data['course_name']); ?></h5>
                </div>
                <div class="card-body">
                    <form action="" method="POST">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($course_data['id']); ?>">
                        
                        <div class="mb-3">
                            <label for="institution_id" class="form-label">Institusi</label>
                            <select class="form-select" id="institution_id" name="institution_id" required>
                                <option value="">Pilih Institusi</option>
                                <?php foreach ($institutions as $inst): ?>
                                    <option value="<?php echo htmlspecialchars($inst['id']); ?>"
                                        <?php echo ($course_data['institution_id'] == $inst['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($inst['nama_pt']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($institutions)): ?>
                                <small class="text-danger">Belum ada institusi terdaftar. Tambahkan institusi terlebih dahulu.</small>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="program_of_study_id" class="form-label">Program Studi</label>
                            <select class="form-select" id="program_of_study_id" name="program_of_study_id" required <?php echo empty($programs_of_study_for_selected_institution) ? 'disabled' : ''; ?>>
                                <option value="">Pilih Program Studi</option>
                                <?php foreach ($programs_of_study_for_selected_institution as $prodi): ?>
                                    <option value="<?php echo htmlspecialchars($prodi['id']); ?>"
                                        <?php echo ($course_data['program_of_study_id'] == $prodi['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prodi['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($programs_of_study_for_selected_institution) && !empty($institutions)): ?>
                                <small class="text-danger">Belum ada program studi untuk institusi terpilih.</small>
                            <?php elseif (empty($institutions)): ?>
                                <small class="text-danger">Pilih institusi terlebih dahulu.</small>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="course_name" class="form-label">Nama Mata Kuliah</label>
                            <input type="text" class="form-control" id="course_name" name="course_name" value="<?php echo htmlspecialchars($course_data['course_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="courses_type" class="form-label">Tipe Mata Kuliah</label>
                            <select class="form-select" id="courses_type" name="courses_type" required>
								<option value="">Pilih Rumpun</option>
								<option value="Teknik" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Teknik') ? 'selected' : ''; ?>>Teknik</option>
								<option value="Ekonomi" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Ekonomi') ? 'selected' : ''; ?>>Ekonomi</option>
								<option value="Terapan" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Terapan') ? 'selected' : ''; ?>>Terapan</option>
								<option value="Sains" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Sains') ? 'selected' : ''; ?>>Sains</option>
								<option value="Pendidikan" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Pendidikan') ? 'selected' : ''; ?>>Pendidikan</option>
								<option value="Kesehatan" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Kesehatan') ? 'selected' : ''; ?>>Kesehatan</option>
								<option value="Agama" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Agama') ? 'selected' : ''; ?>>Agama</option>
								<option value="Sosial" <?php echo (isset($_POST['courses_type']) && $_POST['courses_type'] == 'Sosial') ? 'selected' : ''; ?>>Sosial</option>
							</select>
                        </div>
                        <div class="mb-3">
                            <label for="semester" class="form-label">Semester</label>
                            <input type="number" class="form-control" id="semester" name="semester" min="1" value="<?php echo htmlspecialchars($course_data['semester']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="sks_credits" class="form-label">SKS</label>
                            <input type="number" class="form-control" id="sks_credits" name="sks_credits" min="1" value="<?php echo htmlspecialchars($course_data['sks_credits']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="rps_file_path" class="form-label">URL File RPS (Google Drive, dll.)</label>
                            <input type="url" class="form-control" id="rps_file_path" name="rps_file_path" placeholder="e.g., https://docs.google.com/document/d/..." value="<?php echo htmlspecialchars($course_data['rps_file_path']); ?>" required>
                            <small class="form-text text-muted">Masukkan tautan ke file RPS Anda.</small>
                        </div>
                        <button type="submit" name="update_course" class="btn btn-primary">Update Mata Kuliah</button>
                        <a href="manage_rps.php" class="btn btn-secondary">Batal</a>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                Pilih mata kuliah dari daftar untuk mengeditnya. <a href="manage_rps.php">Kembali ke Data RPS Mata Kuliah</a>
            </div>
        <?php endif; ?>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

    <script>
        $(document).ready(function() {
            // Fitur dinamis: Load Program Studi berdasarkan Institusi yang dipilih
            $('#institution_id').change(function() {
                var institutionId = $(this).val();
                var prodiSelect = $('#program_of_study_id');
                prodiSelect.empty().append('<option value="">Memuat Program Studi...</option>');
                prodiSelect.prop('disabled', true);

                if (institutionId) {
                    $.ajax({
                        url: '../../api/get_prodi_by_institution.php', // Path ke API baru
                        type: 'GET',
                        data: { institution_id: institutionId },
                        dataType: 'json',
                        success: function(data) {
                            prodiSelect.empty();
                            prodiSelect.append('<option value="">Pilih Program Studi</option>');
                            if (data.length > 0) {
                                $.each(data, function(key, entry) {
                                    prodiSelect.append($('<option></option>').attr('value', entry.id).text(entry.program_name));
                                });
                                prodiSelect.prop('disabled', false);

                                // Set selected value if it matches the current course's prodi
                                var currentProdiId = <?php echo json_encode($course_data['program_of_study_id']); ?>;
                                if (currentProdiId) {
                                    prodiSelect.val(currentProdiId);
                                }
                            } else {
                                prodiSelect.append('<option value="">Tidak ada Program Studi untuk institusi ini</option>');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: " + status + error);
                            prodiSelect.empty().append('<option value="">Gagal memuat Program Studi</option>');
                        }
                    });
                } else {
                    prodiSelect.empty().append('<option value="">Pilih Institusi terlebih dahulu</option>');
                }
            });

            // Trigger change on load to populate initial prodi dropdown based on existing institution_id
            $('#institution_id').trigger('change');
        });
    </script>
</body>
</html>