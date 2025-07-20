<?php
session_start();
require_once '../../config/database.php'; // Sesuaikan path jika berbeda

// Proteksi halaman: hanya admin (role_id = 1) yang bisa mengakses
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 1) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];

$roles = [];
$institutions = [];
$program_of_studies = [];
$courses = []; // Untuk daftar mata kuliah
$message = ''; // Untuk pesan sukses atau error

try {
    // Ambil daftar role dari database untuk dropdown
    $stmt_roles = $pdo->query("SELECT id, role_name FROM roles ORDER BY role_name");
    $roles = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);

    // Ambil daftar institusi
    // Menggunakan 'nama_pt' dari tabel 'institutions' dan alias 'name'
    $stmt_institutions = $pdo->query("SELECT id, nama_pt AS name FROM institutions ORDER BY nama_pt");
    $institutions = $stmt_institutions->fetchAll(PDO::FETCH_ASSOC);

    // Ambil daftar program studi
    // Menggunakan nama tabel yang benar 'programs_of_study' dan kolom 'program_name' dengan alias 'name'
    $stmt_program_of_studies = $pdo->query("SELECT id, program_name AS name FROM programs_of_study ORDER BY program_name");
    $program_of_studies = $stmt_program_of_studies->fetchAll(PDO::FETCH_ASSOC);

    // Ambil daftar mata kuliah (untuk dosen dan mahasiswa)
    $stmt_courses = $pdo->query("SELECT id, course_name FROM courses ORDER BY course_name");
    $courses = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);


    // Proses form jika disubmit
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id = $_POST['role_id'] ?? '';
        $is_assessor = isset($_POST['is_assessor']) ? 1 : 0;
        $name = trim($_POST['name'] ?? '');
        $nidn_nim = trim($_POST['nidn_nim'] ?? '');
        // Pastikan nilai default null jika tidak terpilih atau tidak ada
        $institution_id = !empty($_POST['institution_id']) ? $_POST['institution_id'] : null;
        $program_of_study_id = !empty($_POST['program_of_study_id']) ? $_POST['program_of_study_id'] : null;
        $selected_courses = $_POST['courses'] ?? []; // Array untuk mata kuliah yang dipilih

        // Validasi input dasar
        if (empty($username) || empty($password) || empty($role_id)) {
            $message = '<div class="alert alert-danger">Username, password, dan role harus diisi.</div>';
        } elseif (strlen($password) < 6) {
            $message = '<div class="alert alert-danger">Password minimal 6 karakter.</div>';
        } else {
            // Validasi field detail spesifik role (khusus Dosen/Mahasiswa)
            if (($role_id == 2 || $role_id == 3) && (empty($name) || empty($nidn_nim) || empty($institution_id) || empty($program_of_study_id))) {
                $message = '<div class="alert alert-danger">Nama Lengkap, NIDN/NIM, Institusi, dan Program Studi harus diisi untuk Dosen/Mahasiswa.</div>';
            } else {
                // Mulai transaksi database untuk memastikan integritas data
                $pdo->beginTransaction();

                try {
                    // Cek apakah username sudah ada
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
                    $stmt_check->execute([':username' => $username]);
                    if ($stmt_check->fetchColumn() > 0) {
                        $message = '<div class="alert alert-danger">Username sudah digunakan.</div>';
                        $pdo->rollBack(); // Rollback jika username sudah ada
                    } else {
                        // Hash password
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);

                        // Insert ke tabel users
                        $stmt_user = $pdo->prepare("INSERT INTO users (username, password_hash, role_id, is_assessor) VALUES (:username, :password_hash, :role_id, :is_assessor)");
                        $stmt_user->execute([
                            ':username' => $username,
                            ':password_hash' => $password_hash,
                            ':role_id' => $role_id,
                            ':is_assessor' => $is_assessor
                        ]);
                        $new_user_id = $pdo->lastInsertId(); // Dapatkan ID user yang baru dibuat

                        // Inisialisasi untuk menyimpan ID detail (dosen/mahasiswa)
                        $detail_id = null;

                        // Insert ke tabel detail (dosen_details atau mahasiswa_details) jika role_id adalah Dosen atau Mahasiswa
                        if ($role_id == 2) { // Dosen
                            $stmt_dosen = $pdo->prepare("INSERT INTO dosen_details (user_id, full_name, nidn, institution_id, program_of_study_id) VALUES (:user_id, :full_name, :nidn, :institution_id, :program_of_study_id)");
                            $stmt_dosen->execute([
                                ':user_id' => $new_user_id,
                                ':full_name' => $name,
                                ':nidn' => $nidn_nim,
                                ':institution_id' => $institution_id,
                                ':program_of_study_id' => $program_of_study_id
                            ]);
                            // Ambil ID detail dosen yang baru dibuat (untuk tabel dosen_courses)
                            $detail_id = $pdo->lastInsertId();
                        } elseif ($role_id == 3) { // Mahasiswa
                            $stmt_mhs = $pdo->prepare("INSERT INTO mahasiswa_details (user_id, full_name, nim, institution_id, program_of_study_id) VALUES (:user_id, :full_name, :nim, :institution_id, :program_of_study_id)");
                            $stmt_mhs->execute([
                                ':user_id' => $new_user_id,
                                ':full_name' => $name,
                                ':nim' => $nidn_nim,
                                ':institution_id' => $institution_id,
                                ':program_of_study_id' => $program_of_study_id
                            ]);
                            // Ambil ID detail mahasiswa yang baru dibuat (untuk tabel mahasiswa_courses)
                            $detail_id = $pdo->lastInsertId();
                        }

                        // Jika role adalah Dosen atau Mahasiswa dan ada mata kuliah yang dipilih, simpan ke tabel junction
                        if (($role_id == 2 || $role_id == 3) && !empty($selected_courses) && $detail_id) {
                            $table_name = ($role_id == 2) ? 'dosen_courses' : 'mahasiswa_courses';
                            $foreign_key = ($role_id == 2) ? 'dosen_id' : 'mahasiswa_id';

                            $stmt_courses_junction = $pdo->prepare("INSERT INTO $table_name ($foreign_key, course_id) VALUES (:$foreign_key, :course_id)");
                            foreach ($selected_courses as $course_id) {
                                // Pastikan course_id yang dipilih valid (ada di daftar courses yang diambil dari DB)
                                if (in_array($course_id, array_column($courses, 'id'))) {
                                    $stmt_courses_junction->execute([
                                        ':'.$foreign_key => $detail_id,
                                        ':course_id' => $course_id
                                    ]);
                                }
                            }
                        }

                        $pdo->commit(); // Commit transaksi jika semua berhasil
                        $message = '<div class="alert alert-success">Pengguna berhasil ditambahkan!</div>';
                        header('Location: manage_users.php?status=success_add');
                        exit();
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack(); // Rollback transaksi jika terjadi error
                    $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
                }
            }
        }
    }
} catch (PDOException $e) {
    // Tangani error jika gagal mengambil data master (institusi, prodi, course) dari database
    $message = '<div class="alert alert-danger">Error mengambil data master: ' . $e->getMessage() . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Pengguna Baru - Admin Dashboard</title>
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
        /* Style untuk Datatables */
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.2em 0.6em;
        }
        .dataTables_wrapper .dataTables_filter input {
            margin-left: 0.5em;
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
    <div class="container" id="content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light rounded-3 mb-4">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">Halo, <?php echo htmlspecialchars($username); ?>!</a>
                <span class="navbar-text ms-auto">
                    Role: <?php echo htmlspecialchars($role_name); ?>
                </span>
            </div>
        </nav>
        
        <h1>Tambah Pengguna Baru</h1>
        <?php echo $message; ?>

        <form action="" method="POST">
            <div class="mb-3">
                <label for="username" class="form-label">Username:</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password:</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="role_id" class="form-label">Role:</label>
                <select class="form-select" id="role_id" name="role_id" required onchange="toggleDetailFields()">
                    <option value="">Pilih Role</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo htmlspecialchars($role['id']); ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="commonDetailFields" style="display: none;">
                <div class="mb-3">
                    <label for="name" class="form-label">Nama Lengkap:</label>
                    <input type="text" class="form-control" id="name" name="name">
                </div>
                <div class="mb-3">
                    <label for="institution_id" class="form-label">Institusi:</label>
                    <select class="form-select" id="institution_id" name="institution_id">
                        <option value="">Pilih Institusi</option>
                        <?php foreach ($institutions as $inst): ?>
                            <option value="<?php echo htmlspecialchars($inst['id']); ?>"><?php echo htmlspecialchars($inst['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="program_of_study_id" class="form-label">Program Studi:</label>
                    <select class="form-select" id="program_of_study_id" name="program_of_study_id">
                        <option value="">Pilih Program Studi</option>
                        <?php foreach ($program_of_studies as $pos): ?>
                            <option value="<?php echo htmlspecialchars($pos['id']); ?>"><?php echo htmlspecialchars($pos['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="roleSpecificDetailFields" style="display: none;">
                <div class="mb-3">
                    <label for="nidn_nim" class="form-label" id="nidnNimLabel">NIDN / NIM:</label>
                    <input type="text" class="form-control" id="nidn_nim" name="nidn_nim">
                </div>

                <div class="mb-3" id="coursesField" style="display: none;">
                    <label class="form-label" id="coursesLabel">Mata Kuliah:</label>
                    <div class="table-responsive">
                        <table id="coursesTable" class="table table-striped table-bordered" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Pilih</th>
                                    <th>Nama Mata Kuliah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($courses)): ?>
                                    <?php foreach ($courses as $course): ?>
                                        <tr>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="courses[]" value="<?php echo htmlspecialchars($course['id']); ?>" id="course_<?php echo htmlspecialchars($course['id']); ?>">
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
                                        <td colspan="2" class="text-muted text-center">Tidak ada mata kuliah tersedia. Harap tambahkan di master data.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="is_assessor" name="is_assessor" value="1">
                <label class="form-check-label" for="is_assessor">Sebagai Asesor?</label>
            </div>

            <button type="submit" class="btn btn-primary">Tambah Pengguna</button>
        </form>

        <div class="card mt-4 p-4">
            <h5>Informasi Tambahan</h5>
            <p>Selamat datang di panel administrasi. Anda memiliki kendali penuh atas Data Pengguna, institusi, program studi, dan mata kuliah.</p>
            <p>Gunakan menu di samping kiri untuk navigasi.</p>
        </div>
        
        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
        </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="text/javascript" charset="utf8" src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>

    <script>
        var coursesDataTable; // Variabel global untuk menyimpan instance Datatable

        function initializeCoursesTable() {
            // Hancurkan Datatable yang ada jika sudah diinisialisasi
            if ($.fn.DataTable.isDataTable('#coursesTable')) {
                coursesDataTable.destroy();
            }
            // Inisialisasi Datatable baru
            coursesDataTable = $('#coursesTable').DataTable({
                "paging": true,
                "searching": true,
                "ordering": true,
                "info": true
            });
        }

        function toggleDetailFields() {
            var roleSelect = document.getElementById('role_id');
            var commonDetailFields = document.getElementById('commonDetailFields');
            var roleSpecificDetailFields = document.getElementById('roleSpecificDetailFields');
            var nidnNimLabel = document.getElementById('nidnNimLabel');
            var coursesField = document.getElementById('coursesField');
            var coursesLabel = document.getElementById('coursesLabel');

            // Reset visibility
            commonDetailFields.style.display = 'none';
            roleSpecificDetailFields.style.display = 'none';
            coursesField.style.display = 'none';

            // Remove required attributes initially to avoid issues when fields are hidden
            document.getElementById('name').removeAttribute('required');
            document.getElementById('nidn_nim').removeAttribute('required');
            document.getElementById('institution_id').removeAttribute('required');
            document.getElementById('program_of_study_id').removeAttribute('required');


            // Clear values of fields that are about to be hidden/reset
            document.getElementById('name').value = '';
            document.getElementById('nidn_nim').value = '';
            document.getElementById('institution_id').value = '';
            document.getElementById('program_of_study_id').value = '';
            
            // Uncheck all courses checkboxes when role changes
            var courseCheckboxes = document.querySelectorAll('input[name="courses[]"]');
            courseCheckboxes.forEach(function(checkbox) {
                checkbox.checked = false;
            });

            // Logic based on role selection
            if (roleSelect.value == '1') { // Admin
                commonDetailFields.style.display = 'block';
                document.getElementById('name').setAttribute('required', 'required'); // Admin juga bisa punya nama lengkap
            } else if (roleSelect.value == '2') { // Dosen
                commonDetailFields.style.display = 'block';
                roleSpecificDetailFields.style.display = 'block';
                coursesField.style.display = 'block'; // Tampilkan field mata kuliah untuk Dosen
                nidnNimLabel.textContent = 'NIDN:'; // Ubah label menjadi NIDN
                coursesLabel.textContent = 'Mata Kuliah yang Diampu:'; // Ubah label mata kuliah
                document.getElementById('name').setAttribute('required', 'required');
                document.getElementById('nidn_nim').setAttribute('required', 'required');
                document.getElementById('institution_id').setAttribute('required', 'required');
                document.getElementById('program_of_study_id').setAttribute('required', 'required');
                initializeCoursesTable(); // Inisialisasi Datatable saat field mata kuliah ditampilkan
            } else if (roleSelect.value == '3') { // Mahasiswa
                commonDetailFields.style.display = 'block';
                roleSpecificDetailFields.style.display = 'block';
                coursesField.style.display = 'block'; // Tampilkan field mata kuliah untuk Mahasiswa
                nidnNimLabel.textContent = 'NIM:'; // Ubah label menjadi NIM
                coursesLabel.textContent = 'Mata Kuliah yang Diambil:'; // Ubah label mata kuliah
                document.getElementById('name').setAttribute('required', 'required');
                document.getElementById('nidn_nim').setAttribute('required', 'required');
                document.getElementById('institution_id').setAttribute('required', 'required');
                document.getElementById('program_of_study_id').setAttribute('required', 'required');
                initializeCoursesTable(); // Inisialisasi Datatable saat field mata kuliah ditampilkan
            }
        }
        
        // Panggil sekali saat halaman dimuat untuk mengatur tampilan awal
        document.addEventListener('DOMContentLoaded', toggleDetailFields);
    </script>
</body>
</html>