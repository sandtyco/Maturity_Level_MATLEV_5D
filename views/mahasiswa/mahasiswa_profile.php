<?php
session_start();
require_once '../../config/database.php';

// --- Proteksi Halaman: HANYA MAHASISWA yang bisa mengakses ---
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 3) { // role_id 3 untuk mahasiswa
    header('Location: ../../login.php?error=Akses tidak diizinkan. Hanya mahasiswa yang bisa mengakses halaman ini.');
    exit();
}

$user_id = $_SESSION['user_id'];
// Ambil full_name dari sesi untuk tampilan awal di navbar
$full_name_session = $_SESSION['full_name'] ?? 'Mahasiswa';
$username_session = $_SESSION['username'];
$email_session = $_SESSION['email'] ?? 'N/A';

$profile_data = [];
$courses_data = [];
$message = '';
$error = '';
$all_available_courses = []; // Untuk daftar semua mata kuliah yang bisa dipilih
$current_mahasiswa_course_ids = [];
// Untuk menandai mata kuliah yang sudah diambil
$mahasiswa_program_study_id = null;
// Tambahan: untuk menyimpan ID Program Studi mahasiswa

// --- Proses Update Profil ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $new_full_name = trim($_POST['full_name']);
    // Full name for mahasiswa_details & users
    $new_email = trim($_POST['email']);
    // Email for users
    $new_nim = trim($_POST['nim']); // NIM for mahasiswa_details
    $new_institution_id = $_POST['institution_id'];
    // ID for institution
    $new_program_of_study_id = $_POST['program_of_study_id'];
    // ID for program of study
    $new_tahun_angkatan = trim($_POST['tahun_angkatan']);
    // Tahun Angkatan for mahasiswa_details
    $new_semester = trim($_POST['semester']);
    // Semester for mahasiswa_details

    // Validasi input sederhana
    if (empty($new_full_name) || empty($new_email) || empty($new_nim)) {
        $error = "Nama Lengkap, Email, dan NIM tidak boleh kosong.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } else {
        try {
            $pdo->beginTransaction();
            // Update tabel users
            $stmt_update_user = $pdo->prepare("UPDATE users SET full_name = :full_name, email = :email WHERE id = :user_id");
            $stmt_update_user->execute([
                ':full_name' => $new_full_name,
                ':email' => $new_email,
                ':user_id' => $user_id
            ]);
            // Update atau Insert ke tabel mahasiswa_details
            $stmt_check_detail = $pdo->prepare("SELECT user_id FROM mahasiswa_details WHERE user_id = :user_id");
            $stmt_check_detail->execute([':user_id' => $user_id]);
            $detail_exists = $stmt_check_detail->fetch(PDO::FETCH_ASSOC);

            if ($detail_exists) {
                // Update jika sudah ada
                $stmt_update_detail = $pdo->prepare("
                    UPDATE mahasiswa_details SET
                        full_name = :full_name,
                        nim = :nim,
                        institution_id = :institution_id,
                        program_of_study_id = :program_of_study_id,
                        tahun_angkatan = :tahun_angkatan,
                        semester = :semester
                    WHERE user_id = :user_id
                ");
                $stmt_update_detail->execute([
                    ':full_name' => $new_full_name,
                    ':nim' => $new_nim,
                    ':institution_id' => $new_institution_id,
                    ':program_of_study_id' => $new_program_of_study_id,
                    ':tahun_angkatan' => $new_tahun_angkatan,
                    ':semester' => $new_semester,
                    ':user_id' => $user_id
                ]);
            } else {
                // Insert jika belum ada
                $stmt_insert_detail = $pdo->prepare("
                    INSERT INTO mahasiswa_details (user_id, full_name, nim, institution_id, program_of_study_id, tahun_angkatan, semester)
                    VALUES (:user_id, :full_name, :nim, :institution_id, :program_of_study_id, :tahun_angkatan, :semester)
                ");
                $stmt_insert_detail->execute([
                    ':user_id' => $user_id,
                    ':full_name' => $new_full_name,
                    ':nim' => $new_nim,
                    ':institution_id' => $new_institution_id,
                    ':program_of_study_id' => $new_program_of_study_id,
                    ':tahun_angkatan' => $new_tahun_angkatan,
                    ':semester' => $new_semester
                ]);
            }

            $pdo->commit();
            // Perbarui sesi dengan data terbaru agar langsung terlihat di navbar
            $_SESSION['full_name'] = $new_full_name;
            $_SESSION['email'] = $new_email;

            // Redirect untuk menghindari form resubmission dan menampilkan pesan sukses
            header('Location: mahasiswa_profile.php?status=success');
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error updating mahasiswa profile: " . $e->getMessage());
            $error = "Terjadi kesalahan saat memperbarui profil: " . $e->getMessage();
        }
    }
}

// --- Proses Update Mata Kuliah yang Diambil ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_courses'])) {
    $selected_course_ids = $_POST['selected_courses'] ?? []; // Array of course IDs

    try {
        $pdo->beginTransaction();
        // 1. Ambil mata kuliah yang sudah ada untuk mahasiswa ini
        $stmt_existing_courses = $pdo->prepare("SELECT course_id FROM mahasiswa_courses WHERE mahasiswa_id = :mahasiswa_id");
        $stmt_existing_courses->execute([':mahasiswa_id' => $user_id]);
        $existing_course_ids = $stmt_existing_courses->fetchAll(PDO::FETCH_COLUMN);

        // 2. Tentukan mata kuliah yang akan dihapus (ada di database tapi tidak di form)
        $courses_to_delete = array_diff($existing_course_ids, $selected_course_ids);
        if (!empty($courses_to_delete)) {
            $placeholders = implode(',', array_fill(0, count($courses_to_delete), '?'));
            $stmt_delete = $pdo->prepare("DELETE FROM mahasiswa_courses WHERE mahasiswa_id = ? AND course_id IN ($placeholders)");
            $stmt_delete->execute(array_merge([$user_id], $courses_to_delete));
        }

        // 3. Tentukan mata kuliah yang akan ditambahkan (ada di form tapi tidak di database)
        $courses_to_add = array_diff($selected_course_ids, $existing_course_ids);
        if (!empty($courses_to_add)) {
            $stmt_insert = $pdo->prepare("INSERT INTO mahasiswa_courses (mahasiswa_id, course_id) VALUES (?, ?)");
            foreach ($courses_to_add as $course_id) {
                $stmt_insert->execute([$user_id, $course_id]);
            }
        }

        $pdo->commit();
        header('Location: mahasiswa_profile.php?status=courses_updated');
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error updating student courses: " . $e->getMessage());
        $error = "Terjadi kesalahan saat memperbarui mata kuliah: " . $e->getMessage();
    }
}


// --- Ambil Data Profil dan Mata Kuliah (Selalu dilakukan untuk tampilan) ---
$institutions = [];
$programs_of_study = [];
try {
    // Ambil daftar institusi untuk dropdown
    $stmt_institutions = $pdo->query("SELECT id, nama_pt FROM institutions ORDER BY nama_pt");
    $institutions = $stmt_institutions->fetchAll(PDO::FETCH_KEY_PAIR); // Fetch as associative array (id => name)

    // Ambil daftar program studi untuk dropdown
    // Awalnya ini memuat semua program studi, nanti akan difilter oleh JS
    $stmt_programs = $pdo->query("SELECT id, program_name FROM programs_of_study ORDER BY program_name");
    $programs_of_study = $stmt_programs->fetchAll(PDO::FETCH_KEY_PAIR); // Fetch as associative array (id => name)


    // 1. Ambil data profil dasar dari tabel users
    $stmt_user = $pdo->prepare("SELECT username, email, full_name FROM users WHERE id = :user_id");
    $stmt_user->execute([':user_id' => $user_id]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        $profile_data = $user_data;
    } else {
        $error .= "Data pengguna tidak ditemukan.";
    }

    // 2. Ambil data detail mahasiswa dari tabel mahasiswa_details
    // JOIN dengan institutions dan programs_of_study untuk mendapatkan nama lengkapnya
    $stmt_details = $pdo->prepare("
        SELECT
            md.full_name AS md_full_name,
            md.nim,
            md.institution_id,
            i.nama_pt AS institution_name,
            md.program_of_study_id,
            pos.program_name AS program_of_study_name,
            md.tahun_angkatan,
            md.semester
        FROM
            mahasiswa_details md
        LEFT JOIN
            institutions i ON md.institution_id = i.id
        LEFT JOIN
            programs_of_study pos ON md.program_of_study_id = pos.id
        WHERE md.user_id = :user_id
    ");
    $stmt_details->execute([':user_id' => $user_id]);
    $mahasiswa_details = $stmt_details->fetch(PDO::FETCH_ASSOC);

    if ($mahasiswa_details) {
        // Gabungkan data dari users dan mahasiswa_details. Prioritaskan full_name dari mahasiswa_details
        $profile_data['full_name'] = $mahasiswa_details['md_full_name'];
        $profile_data = array_merge($profile_data, $mahasiswa_details);
        // --- Ambil ID Program Studi mahasiswa ---
        $mahasiswa_program_study_id = $mahasiswa_details['program_of_study_id'];
    } else {
        // Jika belum ada detail, inisialisasi dengan nilai kosong agar tidak error saat diakses
        $profile_data['md_full_name'] = $profile_data['full_name']; // Gunakan full_name dari users
        $profile_data['nim'] = '';
        $profile_data['institution_id'] = '';
        $profile_data['institution_name'] = '';
        $profile_data['program_of_study_id'] = '';
        $profile_data['program_of_study_name'] = '';
        $profile_data['tahun_angkatan'] = '';
        $profile_data['semester'] = '';
        $message .= "Data detail mahasiswa belum lengkap. Silakan lengkapi. ";
    }

    // 3. Ambil SEMUA mata kuliah yang tersedia di database, FILTER BY program_of_study_id
    // Ini adalah bagian yang diubah
    if ($mahasiswa_program_study_id) {
        $stmt_all_courses = $pdo->prepare("
            SELECT id, course_name 
            FROM courses 
            WHERE program_of_study_id = :program_of_study_id 
            ORDER BY course_name
        ");
        $stmt_all_courses->execute([':program_of_study_id' => $mahasiswa_program_study_id]);
        $all_available_courses = $stmt_all_courses->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Jika program studi mahasiswa belum diset, tidak ada mata kuliah yang difilter
        $all_available_courses = [];
        $message .= "Untuk melihat daftar mata kuliah relevan, lengkapi data Program Studi Anda di profil. ";
    }
    

    // 4. Ambil ID mata kuliah yang SAAT INI diambil oleh mahasiswa untuk menandai checkbox
    $stmt_current_mahasiswa_courses = $pdo->prepare("SELECT course_id FROM mahasiswa_courses WHERE mahasiswa_id = :mahasiswa_id");
    $stmt_current_mahasiswa_courses->execute([':mahasiswa_id' => $user_id]);
    $current_mahasiswa_course_ids = $stmt_current_mahasiswa_courses->fetchAll(PDO::FETCH_COLUMN); // Ambil hanya kolom course_id

    // 5. Ambil data mata kuliah yang diambil oleh mahasiswa beserta dosen pengampunya dan nilai dimensinya
    $stmt_courses = $pdo->prepare("
        SELECT
            c.id AS course_code_id,
            c.course_name,
            dd.full_name AS lecturer_name,
            -- Asesmen RPS
            ra.classified_temus_id AS rps_dimension_id,
            td_rps.dimension_name AS rps_dimension_name,
            -- Asesmen Dosen (dari self_assessments_3m di mana user_role_at_assessment = 'Dosen')
            sa_dosen.classified_temus_id AS dosen_dimension_id,
            td_dosen.dimension_name AS dosen_dimension_name,
            -- Asesmen Mahasiswa (dari self_assessments_3m di mana user_role_at_assessment = 'Mahasiswa')
            sa_mahasiswa.classified_temus_id AS mahasiswa_dimension_id,
            td_mahasiswa.dimension_name AS mahasiswa_dimension_name
        FROM
            mahasiswa_courses mc
        JOIN
            courses c ON mc.course_id = c.id
        LEFT JOIN
            dosen_courses dc ON c.id = dc.course_id
        LEFT JOIN
            dosen_details dd ON dc.dosen_id = dd.user_id
        LEFT JOIN
            rps_assessments ra ON c.id = ra.course_id 
        LEFT JOIN
            temus_dimensions td_rps ON ra.classified_temus_id = td_rps.id
        LEFT JOIN
            self_assessments_3m sa_dosen ON c.id = sa_dosen.course_id 
            AND sa_dosen.user_id = dd.user_id 
            AND sa_dosen.user_role_at_assessment = 'Dosen'
        LEFT JOIN
            temus_dimensions td_dosen ON sa_dosen.classified_temus_id = td_dosen.id
        LEFT JOIN
            self_assessments_3m sa_mahasiswa ON c.id = sa_mahasiswa.course_id 
            AND sa_mahasiswa.user_id = mc.mahasiswa_id 
            AND sa_mahasiswa.user_role_at_assessment = 'Mahasiswa'
        LEFT JOIN
            temus_dimensions td_mahasiswa ON sa_mahasiswa.classified_temus_id = td_mahasiswa.id
        WHERE
            mc.mahasiswa_id = :mahasiswa_id
        ORDER BY
            c.course_name
    ");
    $stmt_courses->execute([':mahasiswa_id' => $user_id]);
    $courses_data = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses_data) && empty($error) && empty($message)) { // Hanya tampilkan ini jika tidak ada data sama sekali dan tidak ada pesan lain
        $message .= "Anda belum terdaftar pada mata kuliah apapun.";
    }

} catch (PDOException $e) {
    error_log("Error fetching mahasiswa profile data: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat data profil: " . $e->getMessage();
}

// Cek parameter status dari URL setelah redirect (misal dari update profil berhasil)
// Prioritaskan error yang terjadi saat POST dibanding pesan status dari GET
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success' && empty($error)) {
        $message = "Profil berhasil diperbarui!";
    } elseif ($_GET['status'] == 'success_password' && empty($error)) {
        $message = "Password berhasil diubah!";
    } elseif ($_GET['status'] == 'courses_updated' && empty($error)) {
        $message = "Daftar mata kuliah berhasil diperbarui!";
    }
}
if (isset($_GET['error']) && empty($error)) {
    $error = $_GET['error'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Mahasiswa - MATLEV 5D</title>
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

        .profile-card, .courses-card {
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            border-radius: 8px;
            height: 100%; /* Ensure cards fill height */
            display: flex;
            flex-direction: column;
        }

        .profile-card .card-body, .courses-card .card-body {
            flex-grow: 1;
        }

        .form-label {
            font-weight: bold;
        }

        .dimension-info {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 5px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            #sidebar {
                width: 100%;
                height: auto;
            }
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
                <a class="nav-link active" href="mahasiswa_profile.php">
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
                <a class="navbar-brand" href="#">Profil Mahasiswa</a>
                <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($full_name_session); ?></strong> (<?php echo htmlspecialchars($username_session); ?>)
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

        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card profile-card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-id-card me-2"></i>Detail Profil Mahasiswa</h5>
                        <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                            <i class="fas fa-edit me-1"></i> Edit Profil
                        </button>
                    </div>
                    <div class="card-body">
                        <form>
                            <div class="mb-3">
                                <label for="nama_display" class="form-label">Nama Lengkap</label>
                                <input type="text" class="form-control" id="nama_display" value="<?php echo htmlspecialchars($profile_data['full_name'] ?? 'Belum Diisi'); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="nim_display" class="form-label">NIM</label>
                                <input type="text" class="form-control" id="nim_display" value="<?php echo htmlspecialchars($profile_data['nim'] ?? 'Belum Diisi'); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="email_display" class="form-label">Alamat Email</label>
                                <input type="email" class="form-control" id="email_display" value="<?php echo htmlspecialchars($profile_data['email'] ?? 'Belum Diisi'); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="ptAsal_display" class="form-label">Perguruan Tinggi Asal</label>
                                <input type="text" class="form-control" id="ptAsal_display" value="<?php echo htmlspecialchars($profile_data['institution_name'] ?? 'Belum Diisi'); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="programStudi_display" class="form-label">Program Studi</label>
                                <input type="text" class="form-control" id="programStudi_display" value="<?php echo htmlspecialchars($profile_data['program_of_study_name'] ?? 'Belum Diisi'); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="angkatan_display" class="form-label">Tahun Angkatan</label>
                                <input type="text" class="form-control" id="angkatan_display" value="<?php echo htmlspecialchars($profile_data['tahun_angkatan'] ?? 'Belum Diisi'); ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="semester_display" class="form-label">Semester</label>
                                <input type="text" class="form-control" id="semester_display" value="<?php echo htmlspecialchars($profile_data['semester'] ?? 'Belum Diisi'); ?>" readonly>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer text-muted">
                        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#editPasswordModal">
                            <i class="fas fa-key me-1"></i> Ganti Password
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="card courses-card">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-book-open me-2"></i>Mata Kuliah yang Diambil</h5>
                        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#editCoursesModal">
                            <i class="fas fa-plus-circle me-1"></i> Edit Mata Kuliah
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($courses_data)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID MK</th>
                                            <th>Nama Mata Kuliah</th>
                                            <th>Dosen Pengampu</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($courses_data as $course): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($course['course_code_id']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                                    <div class="dimension-info">
                                                        RPS: <strong><?php echo htmlspecialchars($course['rps_dimension_name'] ?? 'Belum di Evaluasi'); ?></strong>
                                                    </div>
                                                    <div class="dimension-info">
                                                        Mahasiswa: <strong><?php echo htmlspecialchars($course['mahasiswa_dimension_name'] ?? 'Belum di Evaluasi'); ?></strong>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($course['lecturer_name'] ?? 'Belum Ditentukan'); ?>
                                                    <div class="dimension-info">
                                                        Dosen: <strong><?php echo htmlspecialchars($course['dosen_dimension_name'] ?? 'Belum di Evaluasi'); ?></strong>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning" role="alert">
                                Anda belum terdaftar pada mata kuliah apapun. Klik "Edit Mata Kuliah" untuk menambahkan.
                            </div>
                        <?php endif; ?>
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

    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="" method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="editProfileModalLabel">Edit Profil Mahasiswa</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Nama Lengkap</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($profile_data['full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Alamat Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($profile_data['email'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="nim" class="form-label">NIM</label>
                            <input type="text" class="form-control" id="nim" name="nim" value="<?php echo htmlspecialchars($profile_data['nim'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="institution_id" class="form-label">Perguruan Tinggi Asal</label>
                            <select class="form-select" id="institution_id" name="institution_id">
                                <option value="">Pilih Perguruan Tinggi</option>
                                <?php foreach ($institutions as $id => $name): ?>
                                    <option value="<?php echo $id; ?>" <?php echo (isset($profile_data['institution_id']) && $profile_data['institution_id'] == $id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="program_of_study_id" class="form-label">Program Studi</label>
                            <select class="form-select" id="program_of_study_id" name="program_of_study_id">
                                <option value="">Pilih Program Studi</option>
                                <?php // Opsi akan diisi dinamis oleh JavaScript ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="tahun_angkatan" class="form-label">Tahun Angkatan</label>
                            <input type="text" class="form-control" id="tahun_angkatan" name="tahun_angkatan" value="<?php echo htmlspecialchars($profile_data['tahun_angkatan'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="semester" class="form-label">Semester</label>
                            <input type="text" class="form-control" id="semester" name="semester" value="<?php echo htmlspecialchars($profile_data['semester'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</</button>
                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editPasswordModal" tabindex="-1" aria-labelledby="editPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="mahasiswa_update_password.php" method="POST"> <div class="modal-header bg-info text-white">
                        <h5 class="modal-title" id="editPasswordModalLabel">Ganti Password</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Password Lama</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Password Baru</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_new_password" class="form-label">Konfirmasi Password Baru</label>
                            <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-info">Ganti Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editCoursesModal" tabindex="-1" aria-labelledby="editCoursesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="" method="POST">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="editCoursesModalLabel">Pilih Mata Kuliah Berdasarkan Program Studi Anda</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="update_courses" value="1">
                        <?php if (is_null($mahasiswa_program_study_id)): ?>
                            <div class="alert alert-warning" role="alert">
                                Silakan lengkapi **Program Studi** Anda di detail profil terlebih dahulu untuk menampilkan daftar mata kuliah yang relevan.
                            </div>
                        <?php elseif (empty($all_available_courses)): ?>
                            <div class="alert alert-info" role="alert">
                                Tidak ada mata kuliah yang terdaftar untuk Program Studi Anda saat ini.
                            </div>
                        <?php else: ?>
                            <p class="text-muted">Centang mata kuliah yang Anda ambil:</p>
                            <div class="row">
                                <?php foreach ($all_available_courses as $course): ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="selected_courses[]" value="<?php echo htmlspecialchars($course['id']); ?>" id="course_<?php echo htmlspecialchars($course['id']); ?>"
                                            <?php echo in_array($course['id'], $current_mahasiswa_course_ids) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="course_<?php echo htmlspecialchars($course['id']); ?>">
                                                <?php echo htmlspecialchars($course['course_name']); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
        $(document).ready(function () {
            // Menghilangkan pesan alert setelah beberapa detik (opsional)
            setTimeout(function () {
                $('.alert').alert('close');
            }, 5000);

            // Periksa apakah ada pesan dari URL dan tampilkan alert
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('status') || urlParams.has('error')) {
                // Biarkan alert muncul
            }

            // --- Start of new dynamic dropdown JS for Program Studi ---
            const institutionSelect = $('#institution_id');
            const programOfStudySelect = $('#program_of_study_id');

            function loadProgramsOfStudy(institutionId, selectedProgramId = null) {
                programOfStudySelect.empty(); // Kosongkan opsi Program Studi yang ada
                programOfStudySelect.append('<option value="">Memuat Program Studi...</option>');

                if (institutionId) {
                    $.ajax({
                        // Sesuaikan URL ke file get_programs_of_study.php yang Anda buat
                        // Asumsi get_programs_of_study.php berada di folder 'ajax' yang satu level di atas folder 'mahasiswa'
                        url: '../../ajax/get_programs_of_study.php', 
                        type: 'GET',
                        data: { institution_id: institutionId },
                        dataType: 'json',
                        success: function(response) {
                            programOfStudySelect.empty(); // Kosongkan lagi sebelum mengisi
                            if (response.success && response.data.length > 0) {
                                programOfStudySelect.append('<option value="">-- Pilih Program Studi --</option>');
                                $.each(response.data, function(index, program) {
                                    // Pastikan ini menggunakan program.id dan program.program_name
                                    const selected = (selectedProgramId && selectedProgramId == program.id) ? 'selected' : '';
                                    programOfStudySelect.append('<option value="' + program.id + '" ' + selected + '>' + program.program_name + '</option>');
                                });
                            } else {
                                programOfStudySelect.append('<option value="">Tidak ada Program Studi ditemukan</option>');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: ", status, error);
                            programOfStudySelect.empty();
                            programOfStudySelect.append('<option value="">Gagal memuat Program Studi</option>');
                        }
                    });
                } else {
                    programOfStudySelect.empty();
                    programOfStudySelect.append('<option value="">Pilih Institusi terlebih dahulu</option>');
                }
            }

            // Event listener saat institusi dipilih di form EDIT profil modal
            institutionSelect.on('change', function() {
                const institutionId = $(this).val();
                loadProgramsOfStudy(institutionId);
            });

            // Panggil fungsi saat modal editProfileModal muncul (agar dropdown terisi saat pertama kali dibuka)
            // Ini akan memastikan dropdown Program Studi terisi saat modal dibuka,
            // dan memilih yang sudah tersimpan jika ada.
            $('#editProfileModal').on('show.bs.modal', function () {
                const currentInstitutionId = institutionSelect.val(); // Ambil nilai institusi yang saat ini terpilih di modal
                const currentProgramOfStudyId = '<?php echo htmlspecialchars($profile_data['program_of_study_id'] ?? ''); ?>'; // Ambil nilai program studi yang sudah tersimpan
                
                // Hanya load jika institusi sudah terpilih
                if (currentInstitutionId) {
                    loadProgramsOfStudy(currentInstitutionId, currentProgramOfStudyId);
                } else {
                    // Jika belum ada institusi terpilih, pastikan dropdown Program Studi menampilkan pesan default
                    programOfStudySelect.empty();
                    programOfStudySelect.append('<option value="">Pilih Institusi terlebih dahulu</option>');
                }
            });
            // --- End of new dynamic dropdown JS ---
        });
    </script>
</body>
</html>