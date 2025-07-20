<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path ke file database.php

// Proteksi halaman: hanya dosen (role_id = 2) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];
$is_asesor = $_SESSION['is_asesor'];

$dosen_data = [];
$institutions = [];
$programs_of_study = [];
$dosen_courses = []; // Menambahkan variabel untuk mata kuliah yang diampu dosen
$available_courses = []; // Menambahkan variabel untuk mata kuliah yang tersedia di prodi
$message = '';
$error = '';

try {
    // Ambil data dosen dari tabel users dan dosen_details
    $stmt = $pdo->prepare("
        SELECT
            u.id AS user_id,
            u.username,
            u.email,
            dd.full_name,
            dd.nidn,
            dd.keahlian,           /* Menambahkan kolom keahlian */
            dd.lama_mengajar_tahun, /* Menambahkan kolom lama_mengajar_tahun */
            dd.cv_file_path,      /* Menambahkan kolom cv_file_path */
            dd.institution_id,
            dd.program_of_study_id,
            inst.nama_pt AS institution_name,
            pos.program_name AS program_of_study_name
        FROM users u
        LEFT JOIN dosen_details dd ON u.id = dd.user_id
        LEFT JOIN institutions inst ON dd.institution_id = inst.id
        LEFT JOIN programs_of_study pos ON dd.program_of_study_id = pos.id
        WHERE u.id = :user_id AND u.role_id = 2
    ");
    $stmt->execute([':user_id' => $user_id]);
    $dosen_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dosen_data) {
        $error = "Data profil dosen tidak ditemukan.";
    }

    // Ambil daftar institusi untuk dropdown
    $stmt_inst = $pdo->query("SELECT id, nama_pt AS name FROM institutions ORDER BY nama_pt");
    $institutions = $stmt_inst->fetchAll(PDO::FETCH_ASSOC);

    // Ambil daftar program studi awal hanya jika ada institution_id yang sudah terpilih
    if (!empty($dosen_data['institution_id'])) {
        $stmt_pos_initial = $pdo->prepare("SELECT id, program_name AS name FROM programs_of_study WHERE institution_id = :institution_id ORDER BY program_name");
        $stmt_pos_initial->execute([':institution_id' => $dosen_data['institution_id']]);
        $programs_of_study = $stmt_pos_initial->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- Ambil Mata Kuliah yang Diampu Dosen ---
    if (!empty($dosen_data['user_id'])) {
        $stmt_dosen_courses = $pdo->prepare("
            SELECT c.id, c.course_name
            FROM dosen_courses dc
            JOIN courses c ON dc.course_id = c.id
            WHERE dc.dosen_id = :dosen_id
            ORDER BY c.course_name
        ");
        // Asumsi dosen_id di dosen_courses merujuk ke user_id di tabel users
        $stmt_dosen_courses->execute([':dosen_id' => $dosen_data['user_id']]);
        $dosen_courses = $stmt_dosen_courses->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- Ambil Mata Kuliah yang Tersedia di Program Studi Dosen (tidak termasuk yang sudah diampu) ---
    if (!empty($dosen_data['program_of_study_id'])) {
        $stmt_available_courses = $pdo->prepare("
            SELECT c.id, c.course_name
            FROM courses c
            WHERE c.program_of_study_id = :program_of_study_id
            AND c.id NOT IN (
                SELECT dc.course_id FROM dosen_courses dc WHERE dc.dosen_id = :dosen_id
            )
            ORDER BY c.course_name
        ");
        $stmt_available_courses->execute([
            ':program_of_study_id' => $dosen_data['program_of_study_id'],
            ':dosen_id' => $dosen_data['user_id']
        ]);
        $available_courses = $stmt_available_courses->fetchAll(PDO::FETCH_ASSOC);
    }


} catch (PDOException $e) {
    error_log("Error fetching dosen profile data: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat data profil: " . $e->getMessage();
}

// --- Proses Update Profil ---
if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $nidn = trim($_POST['nidn']);
    $email = trim($_POST['email']);
    $institution_id = $_POST['institution_id'];
    $program_of_study_id = $_POST['program_of_study_id'];
    $keahlian = trim($_POST['keahlian']);               // Ambil data keahlian
    $lama_mengajar_tahun = trim($_POST['lama_mengajar_tahun']); // Ambil data lama_mengajar_tahun
    $cv_file_path = trim($_POST['cv_file_path']);       // Ambil data cv_file_path

    if (empty($full_name) || empty($nidn) || empty($email) || empty($institution_id) || empty($program_of_study_id)) {
        $error = "Nama lengkap, NIDN, Email, Institusi, dan Program Studi harus diisi.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } elseif (!is_numeric($lama_mengajar_tahun) || $lama_mengajar_tahun < 0) { // Validasi lama_mengajar_tahun
        $error = "Lama mengajar harus berupa angka positif.";
    } elseif (!empty($cv_file_path) && !filter_var($cv_file_path, FILTER_VALIDATE_URL)) { // Validasi URL CV
        $error = "Format URL CV tidak valid.";
    }
    else {
        try {
            $pdo->beginTransaction();
            // Update users table (untuk email)
            $stmt_update_user = $pdo->prepare("UPDATE users SET email = :email WHERE id = :user_id");
            $stmt_update_user->execute([
                ':email' => $email,
                ':user_id' => $user_id
            ]);
            // Update dosen_details table
            $stmt_update_dosen = $pdo->prepare("
                UPDATE dosen_details
                SET full_name = :full_name,
                    nidn = :nidn,
                    institution_id = :institution_id,
                    program_of_study_id = :program_of_study_id,
                    keahlian = :keahlian,               /* Menambahkan update keahlian */
                    lama_mengajar_tahun = :lama_mengajar_tahun, /* Menambahkan update lama_mengajar_tahun */
                    cv_file_path = :cv_file_path      /* Menambahkan update cv_file_path */
                WHERE user_id = :user_id
            ");
            $stmt_update_dosen->execute([
                ':full_name' => $full_name,
                ':nidn' => $nidn,
                ':institution_id' => $institution_id,
                ':program_of_study_id' => $program_of_study_id,
                ':keahlian' => $keahlian,                   // Bind parameter keahlian
                ':lama_mengajar_tahun' => $lama_mengajar_tahun, // Bind parameter lama_mengajar_tahun
                ':cv_file_path' => $cv_file_path,           // Bind parameter cv_file_path
                ':user_id' => $user_id
            ]);

            $pdo->commit();
            $message = "Profil berhasil diperbarui!";
            header('Location: dosen_profile.php?status=success_profile_update');
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error updating dosen profile: " . $e->getMessage());
            $error = "Terjadi kesalahan saat memperbarui profil: " . $e->getMessage();
        }
    }
}

// --- Proses Ubah Password ---
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $stmt_get_hash = $pdo->prepare("SELECT password_hash FROM users WHERE id = :user_id");
    $stmt_get_hash->execute([':user_id' => $user_id]);
    $user_hash = $stmt_get_hash->fetchColumn();

    if (!password_verify($current_password, $user_hash)) {
        $error = "Password lama salah.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password baru minimal 6 karakter.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Konfirmasi password baru tidak cocok.";
    } else {
        try {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt_update_pass = $pdo->prepare("UPDATE users SET password_hash = :new_password_hash WHERE id = :user_id");
            $stmt_update_pass->execute([
                ':new_password_hash' => $new_password_hash,
                ':user_id' => $user_id
            ]);
            $message = "Password berhasil diubah!";
            header('Location: dosen_profile.php?status=success_password_change');
            exit();
        } catch (PDOException $e) {
            error_log("Error changing password: " . $e->getMessage());
            $error = "Terjadi kesalahan saat mengubah password: " . $e->getMessage();
        }
    }
}

// --- Proses Tambah Mata Kuliah ---
if (isset($_POST['add_course'])) {
    $course_id_to_add = $_POST['course_id_to_add'];
    if (empty($course_id_to_add)) {
        $error = "Pilih mata kuliah yang ingin ditambahkan.";
    } else {
        try {
            // Periksa apakah mata kuliah sudah diampu
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM dosen_courses WHERE dosen_id = :dosen_id AND course_id = :course_id");
            $stmt_check->execute([
                ':dosen_id' => $user_id,
                ':course_id' => $course_id_to_add
            ]);
            if ($stmt_check->fetchColumn() > 0) {
                $error = "Mata kuliah ini sudah Anda ampu.";
            } else {
                $stmt_insert = $pdo->prepare("INSERT INTO dosen_courses (dosen_id, course_id) VALUES (:dosen_id, :course_id)");
                $stmt_insert->execute([
                    ':dosen_id' => $user_id,
                    ':course_id' => $course_id_to_add
                ]);
                $message = "Mata kuliah berhasil ditambahkan!";
                header('Location: dosen_profile.php?status=success_add_course');
                exit();
            }
        } catch (PDOException $e) {
            error_log("Error adding course: " . $e->getMessage());
            $error = "Terjadi kesalahan saat menambahkan mata kuliah: " . $e->getMessage();
        }
    }
}

// --- Proses Hapus Mata Kuliah ---
if (isset($_POST['remove_course'])) {
    $course_id_to_remove = $_POST['course_id_to_remove'];
    if (empty($course_id_to_remove)) {
        $error = "Pilih mata kuliah yang ingin dihapus.";
    } else {
        try {
            $stmt_delete = $pdo->prepare("DELETE FROM dosen_courses WHERE dosen_id = :dosen_id AND course_id = :course_id");
            $stmt_delete->execute([
                ':dosen_id' => $user_id,
                ':course_id' => $course_id_to_remove
            ]);
            $message = "Mata kuliah berhasil dihapus!";
            header('Location: dosen_profile.php?status=success_remove_course');
            exit();
        } catch (PDOException $e) {
            error_log("Error removing course: " . $e->getMessage());
            $error = "Terjadi kesalahan saat menghapus mata kuliah: " . $e->getMessage();
        }
    }
}


// Cek parameter status dari URL setelah redirect
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'success_profile_update') {
        $message = "Profil berhasil diperbarui!";
    } elseif ($_GET['status'] == 'success_password_change') {
        $message = "Password berhasil diubah!";
    } elseif ($_GET['status'] == 'success_add_course') {
        $message = "Mata kuliah berhasil ditambahkan!";
    } elseif ($_GET['status'] == 'success_remove_course') {
        $message = "Mata kuliah berhasil dihapus!";
    }

    // Muat ulang data dosen dan mata kuliah setelah update/add/remove
    try {
        $stmt = $pdo->prepare("
            SELECT
                u.id AS user_id,
                u.username,
                u.email,
                dd.full_name,
                dd.nidn,
                dd.keahlian,           /* Menambahkan kolom keahlian */
                dd.lama_mengajar_tahun, /* Menambahkan kolom lama_mengajar_tahun */
                dd.cv_file_path,      /* Menambahkan kolom cv_file_path */
                dd.institution_id,
                dd.program_of_study_id,
                inst.nama_pt AS institution_name,
                pos.program_name AS program_of_study_name
            FROM users u
            LEFT JOIN dosen_details dd ON u.id = dd.user_id
            LEFT JOIN institutions inst ON dd.institution_id = inst.id
            LEFT JOIN programs_of_study pos ON dd.program_of_study_id = pos.id
            WHERE u.id = :user_id AND u.role_id = 2
        ");
        $stmt->execute([':user_id' => $user_id]);
        $dosen_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($dosen_data['institution_id'])) {
            $stmt_pos_initial = $pdo->prepare("SELECT id, program_name AS name FROM programs_of_study WHERE institution_id = :institution_id ORDER BY program_name");
            $stmt_pos_initial->execute([':institution_id' => $dosen_data['institution_id']]);
            $programs_of_study = $stmt_pos_initial->fetchAll(PDO::FETCH_ASSOC);
        }

        // Ambil ulang mata kuliah diampu
        if (!empty($dosen_data['user_id'])) {
            $stmt_dosen_courses = $pdo->prepare("
                SELECT c.id, c.course_name
                FROM dosen_courses dc
                JOIN courses c ON dc.course_id = c.id
                WHERE dc.dosen_id = :dosen_id
                ORDER BY c.course_name
            ");
            $stmt_dosen_courses->execute([':dosen_id' => $dosen_data['user_id']]);
            $dosen_courses = $stmt_dosen_courses->fetchAll(PDO::FETCH_ASSOC);
        }

        // Ambil ulang mata kuliah yang tersedia
        if (!empty($dosen_data['program_of_study_id'])) {
            $stmt_available_courses = $pdo->prepare("
                SELECT c.id, c.course_name
                FROM courses c
                WHERE c.program_of_study_id = :program_of_study_id
                AND c.id NOT IN (
                    SELECT dc.course_id FROM dosen_courses dc WHERE dc.dosen_id = :dosen_id
                )
                ORDER BY c.course_name
            ");
            $stmt_available_courses->execute([
                ':program_of_study_id' => $dosen_data['program_of_study_id'],
                ':dosen_id' => $dosen_data['user_id']
            ]);
            $available_courses = $stmt_available_courses->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {
        error_log("Error re-fetching data after status redirect: " . $e->getMessage());
        $error = "Terjadi kesalahan saat memuat ulang data: " . $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Dosen - MATLEV 5D</title>
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
        .info-card {
            background-color: #ffffff;
            border-left: 5px solid #007bff;
            border-radius: 5px;
            padding: 15px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
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
         /* Style untuk dropdown menu dark agar terlihat di sidebar gelap */
        .dropdown-menu-dark {
            background-color: #495057;
            /* Warna latar belakang menu dropdown */
            border: 1px solid rgba(0, 0, 0, 0.15);
        }
        .dropdown-menu-dark .dropdown-item {
            color: white;
            /* Warna teks item dropdown */
        }
        .dropdown-menu-dark .dropdown-item:hover,
        .dropdown-menu-dark .dropdown-item:focus {
            background-color: #6c757d;
            /* Warna hover untuk item dropdown */
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
                <a class="nav-link active" href="dosen_profile.php">
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
                <a class="navbar-brand" href="#">Profil Dosen</a>
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

        <div class="row">
            <div class="col-md-5">
				<div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Detail Profil</h5>
                    </div>
                    <div class="card-body text-center">
						<h5><?php echo htmlspecialchars($dosen_data['full_name'] ?? 'N/A'); ?></h5>
						<p class="text-muted">Username: <?php echo htmlspecialchars($dosen_data['username'] ?? 'N/A'); ?></p>
						<ul class="list-group list-group-flush text-start mt-3">
							<li class="list-group-item"><strong>NIDN:</strong> <?php echo htmlspecialchars($dosen_data['nidn'] ?? 'N/A'); ?></li>
							<li class="list-group-item"><strong>Email:</strong> <?php echo htmlspecialchars($dosen_data['email'] ?? 'N/A'); ?></li>
							<li class="list-group-item"><strong>Institusi:</strong> <?php echo htmlspecialchars($dosen_data['institution_name'] ?? 'N/A'); ?></li>
							<li class="list-group-item"><strong>Program Studi:</strong> <?php echo htmlspecialchars($dosen_data['program_of_study_name'] ?? 'N/A'); ?></li>
							<li class="list-group-item"><strong>Keahlian:</strong> <?php echo htmlspecialchars($dosen_data['keahlian'] ?? 'N/A'); ?></li>
							<li class="list-group-item"><strong>Lama Mengajar (Tahun):</strong> <?php echo htmlspecialchars($dosen_data['lama_mengajar_tahun'] ?? 'N/A'); ?></li>
							<li class="list-group-item"><strong>Link CV:</strong> 
								<?php if (!empty($dosen_data['cv_file_path'])): ?>
									<a href="<?php echo htmlspecialchars($dosen_data['cv_file_path']); ?>" target="_blank" class="btn btn-sm btn-info">Lihat CV <i class="fas fa-external-link-alt"></i></a>
								<?php else: ?>
									N/A
								<?php endif; ?>
							</li>
							<li class="list-group-item"><strong>Status Asesor:</strong> <?php echo ($is_asesor ? '<span class="badge bg-success">DOSEN ASESOR</span>' : '<span class="badge bg-info">DOSEN BIASA</span>'); ?></li>
						</ul>
					</div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Mata Kuliah Diampu</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($dosen_courses)): ?>
                            <p class="text-muted">Belum ada mata kuliah yang Anda ampu.</p>
                        <?php else: ?>
                            <ul class="list-group mb-3">
                                <?php foreach ($dosen_courses as $course): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo htmlspecialchars($course['course_name']); ?>
                                        <form action="dosen_profile.php" method="POST" style="display: inline-block;">
                                            <input type="hidden" name="course_id_to_remove" value="<?php echo htmlspecialchars($course['id']); ?>">
                                            <button type="submit" name="remove_course" class="btn btn-sm btn-danger" onclick="return confirm('Yakin ingin menghapus mata kuliah ini dari daftar yang Anda ampu?');">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <h6 class="mt-4">Tambahkan Mata Kuliah Baru:</h6>
                        <?php if (empty($available_courses)): ?>
                            <p class="text-muted">Tidak ada mata kuliah tersedia di program studi Anda yang belum diampu.</p>
                        <?php else: ?>
                            <form action="dosen_profile.php" method="POST">
                                <div class="mb-3">
                                    <label for="course_id_to_add" class="form-label">Pilih Mata Kuliah:</label>
                                    <select class="form-select" id="course_id_to_add" name="course_id_to_add" required>
                                        <option value="">-- Pilih Mata Kuliah --</option>
                                        <?php foreach ($available_courses as $course): ?>
                                            <option value="<?php echo htmlspecialchars($course['id']); ?>">
                                                <?php echo htmlspecialchars($course['course_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="add_course" class="btn btn-primary">Tambahkan</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
				
				<div class="card shadow-sm mb-4">
					<div class="card-header bg-info text-white">
                        <h5 class="mb-0">Permohonan Asesor RPS</h5>
                    </div>
					<div class="card-body">
						<p>
							Bagi <b>Dosen</b> yang menginginkan untuk menjadi <b>Asesor RPS</b>, dapat menghubungi via Email dengan melampirkan beberapa keterangan & bukti diantaranya sebagai berikut: 
							<ul>
								<li>Pengalaman mengajar minimal 4 semester pada mata kuliah terkait.</li>
								<li>SK Mengajar mata kuliah terkait.</li>
								<li>Portofolio luaran (CPMK) mata kuliah terkait.</li>
							</ul>
						</p>
						<p><a class="btn btn-info" href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Ajukan Diri</a></p>
					</div>
				</div>
            </div><div class="col-md-7"><div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Edit Profil</h5>
                    </div>
                    <div class="card-body">
                        <form action="dosen_profile.php" method="POST">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Nama Lengkap:</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($dosen_data['full_name'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="nidn" class="form-label">NIDN:</label>
                                <input type="text" class="form-control" id="nidn" name="nidn" value="<?php echo htmlspecialchars($dosen_data['nidn'] ?? ''); ?>" required>
                            </div>
                             <div class="mb-3">
                                <label for="email" class="form-label">Email:</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($dosen_data['email'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="institution_id" class="form-label">Institusi:</label>
                                <select class="form-select" id="institution_id" name="institution_id" required>
                                    <option value="">Pilih Institusi</option>
                                    <?php foreach ($institutions as $inst): ?>
                                        <option value="<?php echo htmlspecialchars($inst['id']); ?>"
                                            <?php echo (isset($dosen_data['institution_id']) && $dosen_data['institution_id'] == $inst['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($inst['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="program_of_study_id" class="form-label">Program Studi:</label>
                                <select class="form-select" id="program_of_study_id" name="program_of_study_id" required>
                                    <option value="">Pilih Institusi terlebih dahulu</option>
                                    <?php
                                    // Isi dropdown ini dengan data awal jika ada
                                    if (!empty($programs_of_study)) {
                                        foreach ($programs_of_study as $pos):
                                            $selected = ($dosen_data['program_of_study_id'] == $pos['id']) ? 'selected' : '';
                                            echo '<option value="' . htmlspecialchars($pos['id']) . '" ' . $selected . '>' . htmlspecialchars($pos['name']) . '</option>';
                                        endforeach;
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="keahlian" class="form-label">Keahlian (Contoh: Data Sains, Machine Learning, UI/UX):</label>
                                <textarea class="form-control" id="keahlian" name="keahlian" rows="3"><?php echo htmlspecialchars($dosen_data['keahlian'] ?? ''); ?></textarea>
                                <small class="form-text text-muted">Pisahkan dengan koma jika lebih dari satu.</small>
                            </div>
                            <div class="mb-3">
                                <label for="lama_mengajar_tahun" class="form-label">Lama Mengajar (Tahun):</label>
                                <input type="number" class="form-control" id="lama_mengajar_tahun" name="lama_mengajar_tahun" value="<?php echo htmlspecialchars($dosen_data['lama_mengajar_tahun'] ?? ''); ?>" min="0">
                            </div>
                            <div class="mb-3">
                                <label for="cv_file_path" class="form-label">Link Google Drive CV (Pastikan akses publik):</label>
                                <input type="url" class="form-control" id="cv_file_path" name="cv_file_path" value="<?php echo htmlspecialchars($dosen_data['cv_file_path'] ?? ''); ?>" placeholder="Contoh: https://drive.google.com/file/d/XYZ/view">
                                <small class="form-text text-muted">Masukkan tautan Google Drive CV Anda. Pastikan CV dapat diakses publik atau siapa saja dengan tautan.</small>
                            </div>
                            <button type="submit" name="update_profile" class="btn btn-primary">Simpan Perubahan</button>
                        </form>
                    </div>
                </div>
				
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Ubah Password</h5>
                    </div>
                    <div class="card-body">
                        <form action="dosen_profile.php" method="POST">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Password Lama:</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">Password Baru:</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Konfirmasi Password Baru:</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-warning">Ubah Password</button>
                        </form>
                    </div>
                </div>
            </div></div>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Menghilangkan pesan alert setelah beberapa detik (opsional)
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000); // Pesan hilang setelah 5 detik

            // --- Fungsi untuk memuat Program Studi berdasarkan Institusi ---
            function loadProgramsOfStudy(institutionId, selectedProgramId = null) {
                const programOfStudySelect = $('#program_of_study_id');
                programOfStudySelect.empty(); // Kosongkan pilihan yang ada

                if (institutionId) {
                    programOfStudySelect.append('<option value="">Memuat...</option>');
                    $.ajax({
                        url: '../get_programs_by_institution.php', // Path ke file PHP AJAX
                        type: 'GET',
                        data: { institution_id: institutionId },
                        dataType: 'json',
                        success: function(data) {
                            programOfStudySelect.empty(); // Kosongkan lagi setelah data diterima
                            programOfStudySelect.append('<option value="">Pilih Program Studi</option>');
                            if (data.length > 0) {
                                $.each(data, function(key, entry) {
                                    const selected = (selectedProgramId && selectedProgramId == entry.id) ? 'selected' : '';
                                    programOfStudySelect.append($('<option></option>').attr('value', entry.id).attr('selected', selected).text(entry.name));
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
                    programOfStudySelect.append('<option value="">Pilih Institusi terlebih dahulu</option>');
                }
            }

            // Event listener saat institusi dipilih di form EDIT profil
            $('#institution_id').on('change', function() {
                const institutionId = $(this).val();
                loadProgramsOfStudy(institutionId);
            });
            // Panggil fungsi saat halaman pertama kali dimuat
            const initialInstitutionId = '<?php echo htmlspecialchars($dosen_data['institution_id'] ?? ''); ?>';
            const initialProgramOfStudyId = '<?php echo htmlspecialchars($dosen_data['program_of_study_id'] ?? ''); ?>';
            if (initialInstitutionId) {
                loadProgramsOfStudy(initialInstitutionId, initialProgramOfStudyId);
            }
        });
    </script>
</body>
</html>