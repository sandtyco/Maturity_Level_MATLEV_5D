<?php
session_start();
require_once '../../config/database.php'; // Path ke database.php dari views/admin/

// Proteksi halaman: hanya admin (role_id = 1) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: ../../login.php'); // Arahkan ke halaman login (dari views/admin/ naik 2 level ke root)
    exit();
}

$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];

$user_id = $_GET['id'] ?? null; // Ambil user_id dari URL
$user_data = [];
$dosen_data = [];
$mahasiswa_data = [];
$assigned_courses_ids_dosen = [];
$assigned_courses_ids_mahasiswa = [];

$roles = [];
$institutions = [];
$program_of_studies = [];
$courses = [];
$message = ''; // Variabel untuk pesan status

if (!$user_id) {
    header('Location: manage_users.php?status=no_id');
    exit();
}

try {
    // 1. Ambil daftar role, institusi, prodi, dan mata kuliah (untuk dropdown/checkbox)
    $stmt_roles = $pdo->query("SELECT id, role_name FROM roles ORDER BY role_name");
    $roles = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);

    $stmt_institutions = $pdo->query("SELECT id, nama_pt AS name FROM institutions ORDER BY nama_pt");
    $institutions = $stmt_institutions->fetchAll(PDO::FETCH_ASSOC);

    $stmt_program_of_studies = $pdo->query("SELECT id, program_name AS name FROM programs_of_study ORDER BY program_name");
    $program_of_studies = $stmt_program_of_studies->fetchAll(PDO::FETCH_ASSOC);

    $stmt_courses = $pdo->query("SELECT id, course_name FROM courses ORDER BY course_name");
    $courses = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

    // 2. Ambil data pengguna berdasarkan user_id
    $stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
    $stmt_user->execute([':user_id' => $user_id]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        header('Location: manage_users.php?status=not_found');
        exit();
    }

    // 3. Ambil data detail (dosen_details atau mahasiswa_details) berdasarkan role yang *sedang* dimiliki user
    if ($user_data['role_id'] == 2) { // Dosen
        $stmt_dosen = $pdo->prepare("SELECT * FROM dosen_details WHERE user_id = :user_id");
        $stmt_dosen->execute([':user_id' => $user_id]);
        $dosen_data = $stmt_dosen->fetch(PDO::FETCH_ASSOC);

        if ($dosen_data) {
            $stmt_assigned_courses_dosen = $pdo->prepare("SELECT course_id FROM dosen_courses WHERE dosen_id = :dosen_id");
            $stmt_assigned_courses_dosen->execute([':dosen_id' => $user_id]);
            $assigned_courses_ids_dosen = $stmt_assigned_courses_dosen->fetchAll(PDO::FETCH_COLUMN, 0);
        }
    } elseif ($user_data['role_id'] == 3) { // Mahasiswa
        $stmt_mahasiswa = $pdo->prepare("SELECT * FROM mahasiswa_details WHERE user_id = :user_id");
        $stmt_mahasiswa->execute([':user_id' => $user_id]);
        $mahasiswa_data = $stmt_mahasiswa->fetch(PDO::FETCH_ASSOC);

        if ($mahasiswa_data) {
            $stmt_assigned_courses_mahasiswa = $pdo->prepare("SELECT course_id FROM mahasiswa_courses WHERE mahasiswa_id = :mahasiswa_id");
            $stmt_assigned_courses_mahasiswa->execute([':mahasiswa_id' => $user_id]);
            $assigned_courses_ids_mahasiswa = $stmt_assigned_courses_mahasiswa->fetchAll(PDO::FETCH_COLUMN, 0);
        }
    }

    // Tidak perlu lagi menangani pesan status dari URL di sini, karena akan di-redirect ke manage_users.php
    // if (isset($_GET['status']) && $_GET['status'] == 'success_update') {
    //      $message = '<div class="alert alert-success">Perubahan berhasil disimpan!</div>';
    // }


    // 4. Proses form jika disubmit (Metode POST)
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id = $_POST['role_id'] ?? '';
        $is_assessor = isset($_POST['is_assessor']) ? 1 : 0;
        $name = trim($_POST['name'] ?? '');
        $nidn_nim = trim($_POST['nidn_nim'] ?? '');
        $institution_id = !empty($_POST['institution_id']) ? $_POST['institution_id'] : null;
        $program_of_study_id = !empty($_POST['program_of_study_id']) ? $_POST['program_of_study_id'] : null;
        $selected_courses = $_POST['courses'] ?? [];

        // Validasi input
        if (empty($username) || empty($role_id)) {
            $message = '<div class="alert alert-danger">Username dan role harus diisi.</div>';
        } elseif (!empty($password) && strlen($password) < 6) {
            $message = '<div class="alert alert-danger">Password minimal 6 karakter jika ingin diubah.</div>';
        } else {
            if (($role_id == 2 || $role_id == 3) && (empty($name) || empty($nidn_nim) || empty($institution_id) || empty($program_of_study_id))) {
                $message = '<div class="alert alert-danger">Nama Lengkap, NIDN/NIM, Institusi, dan Program Studi harus diisi untuk Dosen/Mahasiswa.</div>';
            } else {
                $pdo->beginTransaction();

                try {
                    // Update tabel users
                    $sql_user = "UPDATE users SET username = :username, role_id = :role_id, is_assessor = :is_assessor WHERE id = :user_id";
                    $params_user = [
                        ':username' => $username,
                        ':role_id' => $role_id,
                        ':is_assessor' => $is_assessor,
                        ':user_id' => $user_id
                    ];

                    if (!empty($password)) {
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $sql_user = "UPDATE users SET username = :username, password_hash = :password_hash, role_id = :role_id, is_assessor = :is_assessor WHERE id = :user_id";
                        $params_user[':password_hash'] = $password_hash;
                    }
                    $stmt_update_user = $pdo->prepare($sql_user);
                    $stmt_update_user->execute($params_user);

                    // Cek apakah user_id sudah ada di tabel dosen_details atau mahasiswa_details
                    $stmt_check_dosen_detail = $pdo->prepare("SELECT COUNT(*) FROM dosen_details WHERE user_id = :user_id");
                    $stmt_check_dosen_detail->execute([':user_id' => $user_id]);
                    $is_dosen_detail_exists = ($stmt_check_dosen_detail->fetchColumn() > 0);

                    $stmt_check_mahasiswa_detail = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa_details WHERE user_id = :user_id");
                    $stmt_check_mahasiswa_detail->execute([':user_id' => $user_id]);
                    $is_mahasiswa_detail_exists = ($stmt_check_mahasiswa_detail->fetchColumn() > 0);


                    if ($role_id == 2) { // Role BARU adalah Dosen
                        if ($user_data['role_id'] == 3 && $is_mahasiswa_detail_exists) {
                            $pdo->prepare("DELETE FROM mahasiswa_courses WHERE mahasiswa_id = :mhs_id")->execute([':mhs_id' => $user_id]);
                            $pdo->prepare("DELETE FROM mahasiswa_details WHERE user_id = :user_id")->execute([':user_id' => $user_id]);
                        }

                        if ($is_dosen_detail_exists) {
                            $stmt_update_dosen = $pdo->prepare("UPDATE dosen_details SET full_name = :full_name, nidn = :nidn, institution_id = :institution_id, program_of_study_id = :program_of_study_id WHERE user_id = :user_id");
                            $stmt_update_dosen->execute([
                                ':full_name' => $name,
                                ':nidn' => $nidn_nim,
                                ':institution_id' => $institution_id,
                                ':program_of_study_id' => $program_of_study_id,
                                ':user_id' => $user_id
                            ]);
                        } else {
                            $stmt_insert_dosen = $pdo->prepare("INSERT INTO dosen_details (user_id, full_name, nidn, institution_id, program_of_study_id) VALUES (:user_id, :full_name, :nidn, :institution_id, :program_of_study_id)");
                            $stmt_insert_dosen->execute([
                                ':user_id' => $user_id,
                                ':full_name' => $name,
                                ':nidn' => $nidn_nim,
                                ':institution_id' => $institution_id,
                                ':program_of_study_id' => $program_of_study_id
                            ]);
                        }

                        $pdo->prepare("DELETE FROM dosen_courses WHERE dosen_id = :dosen_id")->execute([':dosen_id' => $user_id]);
                        if (!empty($selected_courses)) {
                            $stmt_dosen_courses = $pdo->prepare("INSERT INTO dosen_courses (dosen_id, course_id) VALUES (:dosen_id, :course_id)");
                            foreach ($selected_courses as $course_id) {
                                if (in_array($course_id, array_column($courses, 'id'))) { // Pastikan course_id valid
                                    $stmt_dosen_courses->execute([
                                        ':dosen_id' => $user_id,
                                        ':course_id' => $course_id
                                    ]);
                                }
                            }
                        }

                    } elseif ($role_id == 3) { // Role BARU adalah Mahasiswa
                        if ($user_data['role_id'] == 2 && $is_dosen_detail_exists) {
                             $pdo->prepare("DELETE FROM dosen_courses WHERE dosen_id = :dosen_id")->execute([':dosen_id' => $user_id]);
                             $pdo->prepare("DELETE FROM dosen_details WHERE user_id = :user_id")->execute([':user_id' => $user_id]);
                        }
                       
                        if ($is_mahasiswa_detail_exists) {
                            $stmt_update_mhs = $pdo->prepare("UPDATE mahasiswa_details SET full_name = :full_name, nim = :nim, institution_id = :institution_id, program_of_study_id = :program_of_study_id WHERE user_id = :user_id");
                            $stmt_update_mhs->execute([
                                ':full_name' => $name,
                                ':nim' => $nidn_nim,
                                ':institution_id' => $institution_id,
                                ':program_of_study_id' => $program_of_study_id,
                                ':user_id' => $user_id
                            ]);
                        } else {
                            $stmt_insert_mhs = $pdo->prepare("INSERT INTO mahasiswa_details (user_id, full_name, nim, institution_id, program_of_study_id) VALUES (:user_id, :full_name, :nim, :institution_id, :program_of_study_id)");
                            $stmt_insert_mhs->execute([
                                ':user_id' => $user_id,
                                ':full_name' => $name,
                                ':nim' => $nidn_nim,
                                ':institution_id' => $institution_id,
                                ':program_of_study_id' => $program_of_study_id
                            ]);
                        }

                        $pdo->prepare("DELETE FROM mahasiswa_courses WHERE mahasiswa_id = :mahasiswa_id")->execute([':mahasiswa_id' => $user_id]);
                        if (!empty($selected_courses)) {
                            $stmt_mahasiswa_courses = $pdo->prepare("INSERT INTO mahasiswa_courses (mahasiswa_id, course_id) VALUES (:mahasiswa_id, :course_id)");
                            foreach ($selected_courses as $course_id) {
                                if (in_array($course_id, array_column($courses, 'id'))) { // Pastikan course_id valid
                                    $stmt_mahasiswa_courses->execute([
                                        ':mahasiswa_id' => $user_id,
                                        ':course_id' => $course_id
                                    ]);
                                }
                            }
                        }

                    } else { // Role BARU adalah Admin atau lainnya
                        if ($user_data['role_id'] == 2 && $is_dosen_detail_exists) {
                            $pdo->prepare("DELETE FROM dosen_courses WHERE dosen_id = :dosen_id")->execute([':dosen_id' => $user_id]);
                            $pdo->prepare("DELETE FROM dosen_details WHERE user_id = :user_id")->execute([':user_id' => $user_id]);
                        } elseif ($user_data['role_id'] == 3 && $is_mahasiswa_detail_exists) {
                             $pdo->prepare("DELETE FROM mahasiswa_courses WHERE mahasiswa_id = :mhs_id")->execute([':mhs_id' => $user_id]);
                             $pdo->prepare("DELETE FROM mahasiswa_details WHERE user_id = :user_id")->execute([':user_id' => $user_id]);
                        }
                    }

                    $pdo->commit(); // Commit transaksi
                    // Redirect ke halaman manage_users.php dengan parameter status=success_update
                    header('Location: manage_users.php?status=success_update');
                    exit();
                } catch (PDOException $e) {
                    $pdo->rollBack(); // Rollback jika ada error
                    $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
                }
            }
        }
    } // Akhir dari POST request

} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pengguna - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.css">
	<link rel="icon" href="../../assets/img/favicon.png" type="image/x-icon">
    <style>
        body {
            display: flex; /* Menggunakan flexbox untuk layout sidebar dan konten */
            min-height: 100vh; /* Memastikan body mengisi seluruh tinggi viewport */
            background-color: #f8f9fa;
        }
        #sidebar {
            width: 250px;
            background-color: #343a40; /* Dark background for sidebar */
            color: white;
            padding: 20px;
            flex-shrink: 0; /* Mencegah sidebar menyusut */
        }
        #sidebar a {
            color: white;
            text-decoration: none;
            padding: 10px 0;
            display: block;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        #sidebar a:hover {
            background-color: #495057; /* Darker on hover */
        }
        #content {
            flex-grow: 1; /* Konten mengisi sisa ruang */
            padding: 20px;
        }
        .navbar-brand {
            font-weight: bold;
            margin-bottom: 20px;
        }
        .card-stats {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        .card-stats:hover {
            transform: translateY(-5px);
        }
        .card-stats .icon {
            font-size: 3rem;
            margin-bottom: 10px;
            color: #007bff;
        }
        .card-stats h4 {
            font-size: 1.2rem;
            color: #6c757d;
        }
        .card-stats p {
            font-size: 2rem;
            font-weight: bold;
            color: #343a40;
        }
        /* Tambahan untuk DataTables agar menyatu dengan Bootstrap */
        .dataTables_wrapper .dataTables_filter input {
            margin-bottom: 10px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.5em 1em;
        }
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding-top: 0.85em;
            padding-bottom: 0.85em;
        }
    </style>
</head>
<body>

    <div id="sidebar">
        <img src="../../assets/img/mgpanel.png" alt="" width="200">
        <hr class="text-white-50">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link active" href="admin_dashboard.php">
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
                <a class="nav-link" href="manage_rps.php">
                    <i class="fas fa-fw fa-book me-2"></i>Data RPS Mata Kuliah
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
                <a class="navbar-brand" href="#">Halo, <?php echo htmlspecialchars($username); ?>!</a>
                <span class="navbar-text ms-auto">
                    Role: <?php echo htmlspecialchars($role_name); ?>
                </span>
            </div>
        </nav>
        
        <h1>Edit Pengguna</h1>
        <?php echo $message; ?>

        <div class="card p-4 mb-4"> <form action="edit_user.php?id=<?php echo htmlspecialchars($user_id); ?>" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">Username:</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password (Kosongkan jika tidak ingin diubah):</label>
                    <input type="password" class="form-control" id="password" name="password">
                </div>
                <div class="mb-3">
                    <label for="role_id" class="form-label">Role:</label>
                    <select class="form-select" id="role_id" name="role_id" required onchange="toggleDetailFields()">
                        <option value="">Pilih Role</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo htmlspecialchars($role['id']); ?>"
                                <?php echo ($user_data['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="commonDetailFields">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nama Lengkap:</label>
                        <input type="text" class="form-control" id="name" name="name"
                                value="<?php echo htmlspecialchars($dosen_data['full_name'] ?? $mahasiswa_data['full_name'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="institution_id" class="form-label">Institusi:</label>
                        <select class="form-select" id="institution_id" name="institution_id">
                            <option value="">Pilih Institusi</option>
                            <?php foreach ($institutions as $inst): ?>
                                <option value="<?php echo htmlspecialchars($inst['id']); ?>"
                                    <?php
                                    $selected_inst_id = $dosen_data['institution_id'] ?? $mahasiswa_data['institution_id'] ?? '';
                                    echo ($selected_inst_id == $inst['id']) ? 'selected' : '';
                                    ?>>
                                    <?php echo htmlspecialchars($inst['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="program_of_study_id" class="form-label">Program Studi:</label>
                        <select class="form-select" id="program_of_study_id" name="program_of_study_id">
                            <option value="">Pilih Program Studi</option>
                            <?php foreach ($program_of_studies as $pos): ?>
                                <option value="<?php echo htmlspecialchars($pos['id']); ?>"
                                    <?php
                                    $selected_pos_id = $dosen_data['program_of_study_id'] ?? $mahasiswa_data['program_of_study_id'] ?? '';
                                    echo ($selected_pos_id == $pos['id']) ? 'selected' : '';
                                    ?>>
                                    <?php echo htmlspecialchars($pos['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="roleSpecificDetailFields">
                    <div class="mb-3">
                        <label for="nidn_nim" class="form-label" id="nidnNimLabel">NIDN / NIM:</label>
                        <input type="text" class="form-control" id="nidn_nim" name="nidn_nim"
                                value="<?php echo htmlspecialchars($dosen_data['nidn'] ?? $mahasiswa_data['nim'] ?? ''); ?>">
                    </div>

                    <div class="mb-3" id="coursesField">
                        <label class="form-label" id="coursesLabel">Mata Kuliah yang Diampu/Diambil:</label>
                        <div class="table-responsive"> <table id="coursesTable" class="table table-striped table-bordered" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Pilih</th>
                                        <th>Nama Mata Kuliah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($courses)): ?>
                                        <?php foreach ($courses as $course):
                                            $is_checked = false;
                                            if ($user_data['role_id'] == 2) {
                                                $is_checked = in_array($course['id'], $assigned_courses_ids_dosen);
                                            } elseif ($user_data['role_id'] == 3) {
                                                $is_checked = in_array($course['id'], $assigned_courses_ids_mahasiswa);
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input course-checkbox" type="checkbox" name="courses[]" value="<?php echo htmlspecialchars($course['id']); ?>" id="course_<?php echo htmlspecialchars($course['id']); ?>" <?php echo $is_checked ? 'checked' : ''; ?>>
                                                    </div>
                                                </td>
                                                <td>
                                                    <label class="form-check-label" for="course_<?php echo htmlspecialchars($course['id']); ?>">
                                                        <?php echo htmlspecialchars($course['course_name']); ?>
                                                    </label>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="2" class="text-center">Tidak ada mata kuliah tersedia. Harap tambahkan di master data.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="is_assessor" name="is_assessor" value="1"
                        <?php echo ($user_data['is_assessor'] == 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="is_assessor">Sebagai Asesor?</label>
                </div>

                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                <a href="manage_users.php" class="btn btn-secondary">Batal</a>
            </form>
        </div>
        
        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
        </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>

    <script>
        // Inisialisasi DataTables
        $(document).ready(function() {
            $('#coursesTable').DataTable({
                "paging": true,     // Aktifkan pagination
                "ordering": true,   // Aktifkan sorting
                "info": true,       // Tampilkan info
                "searching": true   // Aktifkan pencarian
            });
            // Panggil toggleDetailFields saat dokumen siap untuk memastikan tampilan awal yang benar
            toggleDetailFields();
        });

        function toggleDetailFields() {
            var roleSelect = document.getElementById('role_id');
            var currentRoleId = roleSelect.value;

            var commonDetailFields = document.getElementById('commonDetailFields');
            var roleSpecificDetailFields = document.getElementById('roleSpecificDetailFields');
            var nidnNimLabel = document.getElementById('nidnNimLabel');
            var coursesField = document.getElementById('coursesField');
            var coursesLabel = document.getElementById('coursesLabel');

            // Reset visibility
            commonDetailFields.style.display = 'none';
            roleSpecificDetailFields.style.display = 'none';
            coursesField.style.display = 'none';

            // Remove required attributes (will be added back if needed)
            document.getElementById('name').removeAttribute('required');
            document.getElementById('nidn_nim').removeAttribute('required');
            document.getElementById('institution_id').removeAttribute('required');
            document.getElementById('program_of_study_id').removeAttribute('required');

            // Logic to show/hide and set required based on role
            if (currentRoleId == '2') { // Dosen
                commonDetailFields.style.display = 'block';
                roleSpecificDetailFields.style.display = 'block';
                coursesField.style.display = 'block';
                nidnNimLabel.textContent = 'NIDN:';
                coursesLabel.textContent = 'Mata Kuliah yang Diampu:';
                document.getElementById('name').setAttribute('required', 'required');
                document.getElementById('nidn_nim').setAttribute('required', 'required');
                document.getElementById('institution_id').setAttribute('required', 'required');
                document.getElementById('program_of_study_id').setAttribute('required', 'required');
            } else if (currentRoleId == '3') { // Mahasiswa
                commonDetailFields.style.display = 'block';
                roleSpecificDetailFields.style.display = 'block';
                coursesField.style.display = 'block';
                nidnNimLabel.textContent = 'NIM:';
                coursesLabel.textContent = 'Mata Kuliah yang Diambil:';
                document.getElementById('name').setAttribute('required', 'required');
                document.getElementById('nidn_nim').setAttribute('required', 'required');
                document.getElementById('institution_id').setAttribute('required', 'required');
                document.getElementById('program_of_study_id').setAttribute('required', 'required');
            }
            // Untuk Admin (currentRoleId == '1') dan role lainnya, detailFields akan tetap disembunyikan
        }
        
    </script>
</body>
</html>