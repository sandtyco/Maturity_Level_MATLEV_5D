<?php
session_start(); // Mulai session di awal setiap halaman yang menggunakan session
require_once '../config/database.php'; // <--- Path ke database.php yang benar

ini_set('display_errors', 1);
// Tambahkan ini untuk debugging
ini_set('display_startup_errors', 1); // Tambahkan ini untuk debugging
error_reporting(E_ALL); // Tambahkan ini untuk debugging

$login_message = '';
// Pesan untuk form login
$register_message = ''; // Pesan untuk form registrasi

// Jika sudah login, redirect ke dashboard sesuai role
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role_id'] == 1) { // Admin
        header('Location: admin/admin_dashboard.php');
        exit();
    } elseif ($_SESSION['role_id'] == 2) { // Dosen
        header('Location: dosen/dosen_dashboard.php');
        exit();
    } elseif ($_SESSION['role_id'] == 3) { // Mahasiswa
        header('Location: mahasiswa/mahasiswa_dashboard.php');
        exit();
    }
    // Jika ada role lain atau default
    header('Location: ../index.php');
    // Kembali ke index.php di root folder
    exit();
}

// Ambil daftar roles, institutions, dan programs_of_study untuk form registrasi
$roles_for_register = [];
$institutions_for_register = [];
try {
    // Hanya ambil role Dosen (2) dan Mahasiswa (3) untuk registrasi
    $stmt_roles = $pdo->prepare("SELECT id, role_name FROM roles WHERE id IN (2, 3) ORDER BY role_name");
    $stmt_roles->execute();
    $roles_for_register = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);

    $stmt_institutions = $pdo->query("SELECT id, nama_pt AS name FROM institutions ORDER BY nama_pt");
    $institutions_for_register = $stmt_institutions->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $register_message = '<div class="alert alert-danger">Error mengambil data master: ' . $e->getMessage() .
    '</div>';
}

// --- Proses Login ---
if (isset($_POST['login_submit'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $login_message = "Username dan password harus diisi.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.username,
                    u.password_hash,
                    u.role_id,
                    r.role_name,
                    u.is_assessor,
                    CASE
                        WHEN u.role_id = 2 THEN dd.institution_id
                        WHEN u.role_id = 3 THEN md.institution_id
                        ELSE NULL
                    END AS institution_id
                FROM
                    users u
                JOIN
                    roles r ON u.role_id = r.id
                LEFT JOIN
                    dosen_details dd ON u.id = dd.user_id AND u.role_id = 2
                LEFT JOIN
                    mahasiswa_details md ON u.id = md.user_id AND u.role_id = 3
                WHERE
                    u.username = :username
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['role_name'] = $user['role_name'];
                $_SESSION['is_asesor'] = $user['is_assessor'];
                $_SESSION['institution_id'] = $user['institution_id'] ?? null;
                if ($user['role_id'] == 1) { // Admin
                    header('Location: admin/admin_dashboard.php');
                    exit();
                } elseif ($user['role_id'] == 2) { // Dosen
                    header('Location: dosen/dosen_dashboard.php');
                    exit();
                } elseif ($user['role_id'] == 3) { // Mahasiswa
                    header('Location: mahasiswa/mahasiswa_dashboard.php');
                    exit();
                } else {
                    header('Location: ../index.php');
                    // Kembali ke index.php di root folder
                    exit();
                }
            } else {
                $login_message = "Username atau password salah.";
            }
        } catch (PDOException $e) {
            $login_message = "Terjadi kesalahan database: " .
            $e->getMessage();
        }
    }
}

// --- Proses Registrasi ---
if (isset($_POST['register_submit'])) {
    $username = trim($_POST['username_reg'] ?? '');
    $password = $_POST['password_reg'] ?? '';
    $role_id = $_POST['role_id_reg'] ?? '';
    $name = trim($_POST['name_reg'] ?? '');
    $sex = trim($_POST['sex_reg'] ?? ''); // New: Get sex from POST
    $nidn_nim = trim($_POST['nidn_nim_reg'] ?? '');
    $institution_id = !empty($_POST['institution_id_reg']) ? $_POST['institution_id_reg'] : null;
    $program_of_study_id = !empty($_POST['program_of_study_id_reg']) ? $_POST['program_of_study_id_reg'] : null;
    // Validasi dasar
    if (empty($username) || empty($password) || empty($role_id) || empty($name) || empty($sex) || empty($nidn_nim) || empty($institution_id) || empty($program_of_study_id)) { // Added sex to validation
        $register_message = "Semua field registrasi harus diisi.";
    } elseif (strlen($password) < 6) {
        $register_message = "Password minimal 6 karakter.";
    } elseif ($role_id != 2 && $role_id != 3) { // Hanya izinkan Dosen dan Mahasiswa
        $register_message = "Registrasi hanya diperbolehkan untuk Dosen atau Mahasiswa.";
    } else {
        $pdo->beginTransaction();
        try {
            // Cek apakah username sudah ada
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt_check->execute([':username' => $username]);
            if ($stmt_check->fetchColumn() > 0) {
                $register_message = "Username sudah digunakan.";
                $pdo->rollBack();
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                // Insert ke tabel users - MODIFIED to include sex
                // PASTIKAN KOLOM 'sex' ADA DI TABEL 'users' PADA DATABASE ANDA.
                $stmt_user = $pdo->prepare("INSERT INTO users (username, password_hash, role_id, is_assessor, sex) VALUES (:username, :password_hash, :role_id, :is_assessor, :sex)");
                $stmt_user->execute([
                    ':username' => $username,
                    ':password_hash' => $password_hash,
                    ':role_id' => $role_id,
                    ':is_assessor' => 0, // Default 0 untuk registrasi publik
                    ':sex' => $sex // New: added sex
                ]);
                $new_user_id = $pdo->lastInsertId();
                if ($role_id == 2) { // Dosen
                    $stmt_dosen = $pdo->prepare("INSERT INTO dosen_details (user_id, full_name, nidn, institution_id, program_of_study_id) VALUES (:user_id, :full_name, :nidn, :institution_id, :program_of_study_id)");
                    $stmt_dosen->execute([
                        ':user_id' => $new_user_id,
                        ':full_name' => $name,
                        ':nidn' => $nidn_nim,
                        ':institution_id' => $institution_id,
                        ':program_of_study_id' => $program_of_study_id
                    ]);
                } elseif ($role_id == 3) { // Mahasiswa
                    $stmt_mhs = $pdo->prepare("INSERT INTO mahasiswa_details (user_id, full_name, nim, institution_id, program_of_study_id) VALUES (:user_id, :full_name, :nim, :institution_id, :program_of_study_id)");
                    $stmt_mhs->execute([
                        ':user_id' => $new_user_id,
                        ':full_name' => $name,
                        ':nim' => $nidn_nim,
                        ':institution_id' => $institution_id,
                        ':program_of_study_id' => $program_of_study_id
                    ]);
                }

                $pdo->commit();
                $register_message = "Registrasi berhasil! Silakan login dengan akun Anda.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $register_message = "Terjadi kesalahan saat registrasi: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Registrasi Aplikasi MATLEV 5D</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="icon" href="../assets/img/favicon.png" type="image/x-icon">
    <style>
        body {
            background-color: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .form-container {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 450px;
        }
        .form-container h2 {
            margin-bottom: 25px;
            text-align: center;
            color: #007bff;
        }
        .form-label {
            font-weight: bold;
        }
        .btn-primary, .btn-success {
            width: 100%;
            padding: 10px;
            font-size: 1.1rem;
        }
        .alert {
            margin-top: 15px;
        }
        .form-switch-link {
            text-align: center;
            margin-top: 20px;
        }

        /* --- CSS for Modal --- */
        .modal {
          display: none;
          /* Sembunyikan secara default */
          position: fixed;
          /* Tetap di tempatnya */
          z-index: 1000;
          /* Muncul di atas elemen lain */
          left: 0;
          top: 0;
          width: 100%; /* Lebar penuh */
          height: 100%;
          /* Tinggi penuh */
          overflow: auto;
          /* Aktifkan scroll jika konten terlalu panjang */
          background-color: rgba(0,0,0,0.4);
          /* Latar belakang gelap transparan */
          padding-top: 60px;
          /* Posisi kotak modal dari atas */
        }

        .modal-content {
          background-color: #fefefe;
          margin: 5% auto; /* 5% dari atas dan di tengah secara horizontal */
          padding: 20px;
          border: 1px solid #888;
          width: 80%; /* Lebar modal, bisa disesuaikan */
          max-width: 600px;
          /* Lebar maksimum untuk layar besar */
          box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);
          animation-name: animatetop;
          animation-duration: 0.4s;
          position: relative;
          border-radius: 8px;
        }

        .close-button {
          color: #aaa;
          float: right;
          font-size: 28px;
          font-weight: bold;
        }

        .close-button:hover,
        .close-button:focus {
          color: black;
          text-decoration: none;
          cursor: pointer;
        }

        .modal-body {
            max-height: 400px;
            /* Batasi tinggi konten, sesuaikan jika perlu */
            overflow-y: auto;
            /* Aktifkan scroll vertikal jika konten melebihi max-height */
            margin-bottom: 20px;
            padding-right: 15px; /* Memberi ruang untuk scrollbar */
        }

        .modal-footer {
            text-align: right;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }

        .modal-footer button {
            padding: 10px 15px;
            margin-left: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        #agreeAndRegisterBtn {
            background-color: #4CAF50;
            /* Hijau */
            color: white;
        }

        #cancelRegistrationBtn {
            background-color: #f44336;
            /* Merah */
            color: white;
        }

        .agreement-checkbox {
            margin-top: 20px;
        }

        .agreement-checkbox input[type="checkbox"] {
            margin-right: 5px;
        }

        @-webkit-keyframes animatetop {
          from {top:-300px;
          opacity:0}
          to {top:0;
          opacity:1}
        }

        @keyframes animatetop {
          from {top:-300px;
          opacity:0}
          to {top:0;
          opacity:1}
        }
        /* --- End CSS for Modal --- */
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="row w-100">
            <div class="col-12 d-flex justify-content-center">
                <div id="login-form-card" class="form-container">
                    <img src="../assets/img/logo2.png" alt="" width="350" class="d-block mx-auto mb-4">
                    <hr>
                    <h2 class="text-center mb-4">Login Pengguna</h2>
                    <?php if (!empty($login_message) && !isset($_POST['register_submit'])): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($login_message);
                            ?>
                        </div>
                    <?php endif;
                    ?>

                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" name="login_submit" class="btn btn-primary">Login</button>
                    </form>
                    <div class="form-switch-link">
                        <p>Belum punya akun?
                        <a href="#" id="show-register-form">Daftar Sekarang</a></p>
                        <p><a href="../index.php">Kembali ke Beranda</a></p>
                    </div>
                </div>

                <div id="register-form-card" class="form-container" style="display: none;">
                    <img src="../assets/img/logo2.png" alt="" width="350" class="d-block mx-auto mb-4">
                    <hr>
                    <h2 class="text-center mb-4">Form Registrasi Pengguna</h2>
                    <?php if (!empty($register_message)): ?>
                        <div class="alert <?php echo (strpos($register_message, 'berhasil') !== false) ? 'alert-success' : 'alert-danger'; ?>" role="alert">
                            <?php echo htmlspecialchars($register_message);
                            ?>
                        </div>
                    <?php endif;
                    ?>

                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="username_reg" class="form-label">Username:</label>
                            <input type="text" class="form-control" id="username_reg" name="username_reg" required value="<?php echo htmlspecialchars($_POST['username_reg'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label for="password_reg" class="form-label">Password:</label>
                            <input type="password" class="form-control" id="password_reg" name="password_reg" required>
                        </div>
                        <div class="mb-3">
                            <label for="role_id_reg" class="form-label">Anda Sebagai:</label>
                            <select class="form-select" id="role_id_reg" name="role_id_reg" required onchange="toggleRegDetailFields()">
                                <option value="">Pilih Role</option>
                                <?php foreach ($roles_for_register as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role['id']);
                                    ?>" <?php echo (isset($_POST['role_id_reg']) && $_POST['role_id_reg'] == $role['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="regDetailFields">
                            <div class="mb-3">
                                <label for="name_reg" class="form-label">Nama Lengkap:</label>
                                <input type="text" class="form-control" id="name_reg" name="name_reg" required value="<?php echo htmlspecialchars($_POST['name_reg'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="sex_reg" class="form-label">Jenis Kelamin:</label>
                                <select class="form-select" id="sex_reg" name="sex_reg" required>
                                    <option value="">Pilih Jenis Kelamin</option>
                                    <option value="Pria" <?php echo (isset($_POST['sex_reg']) && $_POST['sex_reg'] == 'Pria') ? 'selected' : ''; ?>>Pria</option>
                                    <option value="Wanita" <?php echo (isset($_POST['sex_reg']) && $_POST['sex_reg'] == 'Wanita') ? 'selected' : ''; ?>>Wanita</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="nidn_nim_reg" class="form-label" id="nidnNimLabelReg">NIDN / NIM:</label>
                                <input type="text" class="form-control" id="nidn_nim_reg" name="nidn_nim_reg" required value="<?php echo htmlspecialchars($_POST['nidn_nim_reg'] ?? '');
                                ?>">
                            </div>
                            <div class="mb-3">
                                <label for="institution_id_reg" class="form-label">Institusi:</label>
                                <select class="form-select" id="institution_id_reg" name="institution_id_reg" required>
                                    <option value="">Pilih Institusi</option>
                                    <?php foreach ($institutions_for_register as $inst): ?>
                                        <option value="<?php echo htmlspecialchars($inst['id']);
                                        ?>" <?php echo (isset($_POST['institution_id_reg']) && $_POST['institution_id_reg'] == $inst['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($inst['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="program_of_study_id_reg" class="form-label">Program Studi:</label>
                                <select class="form-select" id="program_of_study_id_reg" name="program_of_study_id_reg" required>
                                    <option value="">Pilih Program Studi</option>
                                    </select>
                            </div>
                        </div>

                        <button type="submit" name="register_submit" class="btn btn-success">Daftar</button>
                    </form>
                    <div class="form-switch-link">
                        <p>Sudah punya akun?
                        <a href="#" id="show-login-form">Login Sekarang</a></p>
                        <p><a href="../index.php">Kembali ke Beranda</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="agreementModal" class="modal">
        <div class="modal-content">
        <span class="close-button">&times;</span>
        <h2>Aturan dan Kebijakan Penggunaan MATLEV 5D</h2>
        <div class="modal-body">
          <p>Selamat datang di MATLEV 5D.
          Sebelum Anda melanjutkan proses registrasi, mohon luangkan waktu untuk membaca dan memahami aturan dan kebijakan penggunaan layanan kami:</p>
          <ul>
            <li><b>Pengumpulan Data:</b> Kami mengumpulkan data yang relevan untuk tujuan analisis efektivitas digitalisasi pembelajaran, termasuk informasi mengenai aspek metode, materi, dan media, serta dimensi TEMUS.</li>
            <li><b>Penggunaan Data:</b> Data yang dikumpulkan dipergunakan untuk kepentingan Institusi dalam menilai tingkat kematangan implementasi digitalisasi pembelajaran, disamping itu data dapat dipergunakan sebagai riset dalam kajian evaluasi
            digitalisasi pembelajaran. Data yang dipergunakan bukan untuk menentukan baik buruknya sebuah Institusi, melainkan sebuah pengukuran yang berdampak pada kebijakan masing - masing Institusi dalam melakukan pengembangan digitalisasi pembelajaran.
            Data tidak akan dibagikan kepada pihak ketiga dalam bentuk yang dapat mengidentifikasi individu.</li>
            <li><b>Privasi:</b> Kami berkomitmen untuk melindungi privasi pengguna.
            Informasi pribadi Anda akan dijaga kerahasiaannya sesuai dengan standar keamanan yang berlaku.</li>
            <li><b>Tanggung Jawab Pengguna:</b> Pengguna bertanggung jawab penuh atas keakuratan data yang diinputkan dan penggunaan sistem sesuai dengan etika akademik.</li>
            <li><b>Perubahan Kebijakan:</b> Kebijakan ini dapat diperbarui sewaktu-waktu.
            Perubahan akan diinformasikan melalui sistem.</li>
          </ul>
          <div class="agreement-checkbox">
            <input type="checkbox" id="agreeCheckbox" name="agreeCheckbox">
            <label for="agreeCheckbox">Saya telah membaca dan menyetujui Aturan dan Kebijakan Penggunaan MATLEV 5D.</label>
          </div>
          <p class="error-message" id="agreementError" style="color: red; display: none;">Anda harus menyetujui aturan dan kebijakan untuk melanjutkan registrasi.</p>
        </div>
        <div class="modal-footer">
          <button id="agreeAndRegisterBtn">Setuju & Lanjutkan Registrasi</button>
          <button id="cancelRegistrationBtn">Batal</button>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginFormCard = document.getElementById('login-form-card');
            const registerFormCard = document.getElementById('register-form-card'); // Ini adalah registrationFormContainer Anda
            const showRegisterLink = document.getElementById('show-register-form');
            // Ini adalah registerTrigger Anda
            const showLoginLink = document.getElementById('show-login-form');
            const roleSelectReg = document.getElementById('role_id_reg');
            const nidnNimLabelReg = document.getElementById('nidnNimLabelReg');
            const regDetailFields = document.getElementById('regDetailFields');
            const institutionSelectReg = document.getElementById('institution_id_reg');
            const programOfStudySelectReg = document.getElementById('program_of_study_id_reg');
            // --- Elemen Modal Baru ---
            const agreementModal = document.getElementById('agreementModal');
            const closeButton = document.querySelector('.close-button');
            const agreeCheckbox = document.getElementById('agreeCheckbox');
            const agreeAndRegisterBtn = document.getElementById('agreeAndRegisterBtn');
            const cancelRegistrationBtn = document.getElementById('cancelRegistrationBtn');
            const agreementError = document.getElementById('agreementError');
            // --- End Elemen Modal Baru ---

            // Fungsi untuk menampilkan/menyembunyikan form
            function showForm(formToShow) {
                if (formToShow === 'register') {
                    loginFormCard.style.display = 'none';
                    registerFormCard.style.display = 'block';
                    // Panggil toggleRegDetailFields saat form register ditampilkan untuk menyesuaikan label
                    toggleRegDetailFields();
                    // Juga panggil fetchPrograms saat form register ditampilkan jika ada institusi yang sudah dipilih
                    if (institutionSelectReg.value) {
                        fetchPrograms(institutionSelectReg.value);
                    }
                } else {
                    loginFormCard.style.display = 'block';
                    registerFormCard.style.display = 'none';
                }
            }

            // --- Fungsi Modal Baru ---
            function openAgreementModal() {
                agreementModal.style.display = 'block';
                // Reset checkbox dan pesan error setiap kali modal dibuka
                agreeCheckbox.checked = false;
                agreementError.style.display = 'none';
                // Pastikan formulir registrasi tersembunyi saat modal dibuka
                if (registerFormCard) { // Menggunakan ID yang sesuai
                    registerFormCard.style.display = 'none';
                }
            }

            function closeAgreementModal() {
                agreementModal.style.display = 'none';
            }
            // --- End Fungsi Modal Baru ---

            // Tentukan form mana yang harus ditampilkan saat halaman pertama kali dimuat
            // Ini akan menjaga form registrasi tetap terbuka jika ada error setelah submit
            <?php if (isset($_POST['register_submit']) && !empty($register_message)): ?>
                showForm('register');
            <?php else: ?>
                showForm('login');
            <?php endif; ?>

            // Event listener untuk tombol switch form
            // MODIFIKASI: showRegisterLink sekarang membuka modal
            showRegisterLink.addEventListener('click', function(e) {
                e.preventDefault();
                openAgreementModal(); // Membuka modal perjanjian
            });
            showLoginLink.addEventListener('click', function(e) {
                e.preventDefault();
                showForm('login');
                // Optional: clear register form fields when switching back to login
                document.getElementById('username_reg').value = '';
                document.getElementById('password_reg').value = '';
                document.getElementById('role_id_reg').value = ''; // Reset dropdown
                document.getElementById('name_reg').value = '';
                document.getElementById('sex_reg').value = ''; // Clear sex field
                document.getElementById('nidn_nim_reg').value = '';
                document.getElementById('institution_id_reg').value = '';
                document.getElementById('program_of_study_id_reg').value = '';
                programOfStudySelectReg.innerHTML = '<option value="">Pilih Program Studi</option>'; // Clear programs
            });
            // --- Event Listeners Modal Baru ---
            if (closeButton) {
                closeButton.addEventListener('click', closeAgreementModal);
            }

            window.addEventListener('click', function(event) {
                if (event.target == agreementModal) {
                    closeAgreementModal();
                }
            });
            if (agreeAndRegisterBtn) {
                agreeAndRegisterBtn.addEventListener('click', function() {
                    if (agreeCheckbox.checked) {
                        agreementError.style.display = 'none';
                        closeAgreementModal();
                        showForm('register'); // Setelah setuju, tampilkan formulir registrasi
                        // Opsional: Scroll ke formulir registrasi jika posisinya jauh ke bawah
                        if (registerFormCard) {
                            registerFormCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    } else {
                        agreementError.style.display = 'block'; // Tampilkan pesan error jika belum dicentang
                    }
                });
            }

            if (cancelRegistrationBtn) {
                cancelRegistrationBtn.addEventListener('click', closeAgreementModal);
            }
            // --- End Event Listeners Modal Baru ---


            // Fungsi untuk menyesuaikan label NIDN/NIM pada form registrasi
            window.toggleRegDetailFields = function() {
                if (roleSelectReg.value == '2') { // Dosen
                    nidnNimLabelReg.textContent = 'NIDN:';
                    regDetailFields.style.display = 'block'; // Pastikan terlihat
                } else if (roleSelectReg.value == '3') { // Mahasiswa
                    nidnNimLabelReg.textContent = 'NIM:';
                    regDetailFields.style.display = 'block'; // Pastikan terlihat
                } else {
                    // Sembunyikan jika role belum dipilih atau role lain (meskipun seharusnya tidak terjadi di sini)
                    regDetailFields.style.display = 'block';
                    // Tetap tampilkan detail karena ini wajib diisi
                    nidnNimLabelReg.textContent = 'NIDN / NIM:';
                    // Reset label
                }
            }

            // Fungsi untuk mengambil dan mengisi program studi berdasarkan institusi
            function fetchPrograms(institutionId) {
                programOfStudySelectReg.innerHTML = '<option value="">Memuat...</option>';
                // Pesan loading
                programOfStudySelectReg.disabled = true;
                // Disable saat memuat

                fetch('get_programs_by_institution.php?institution_id=' + institutionId)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok ' + response.statusText);
                        }
                        return response.json();
                    })
                    .then(data => {
                        programOfStudySelectReg.innerHTML = '<option value="">Pilih Program Studi</option>'; // Reset options
                        if (data.error) {
                            console.error('Server error:', data.error);
                            // Opsional: tampilkan pesan error ke user
                            programOfStudySelectReg.innerHTML = '<option value="">Error memuat prodi</option>';
                        } else {
                            data.forEach(program => {
                                const option = document.createElement('option');
                                option.value = program.id;
                                option.textContent = program.name;
                                programOfStudySelectReg.appendChild(option);
                            });

                            // Pre-select program studi if a value was previously submitted (e.g., after an error)
                            const prevProgramId = "<?php echo htmlspecialchars($_POST['program_of_study_id_reg'] ?? ''); ?>";
                            if (prevProgramId) {
                                programOfStudySelectReg.value = prevProgramId;
                            }
                        }
                        programOfStudySelectReg.disabled = false;
                        // Enable kembali
                    })
                    .catch(error => {
                        console.error('There was a problem with the fetch operation:', error);
                        programOfStudySelectReg.innerHTML = '<option value="">Gagal memuat Program Studi</option>';
                        programOfStudySelectReg.disabled = false;
                    });
            }

            // Event listener untuk perubahan pada dropdown Institusi
            institutionSelectReg.addEventListener('change', function() {
                const selectedInstitutionId = this.value;
                if (selectedInstitutionId) {
                    fetchPrograms(selectedInstitutionId);
                } else {
                    // Reset program studi jika tidak ada institusi yang dipilih
                    programOfStudySelectReg.innerHTML = '<option value="">Pilih Program Studi</option>';
                    programOfStudySelectReg.disabled = false;
                }
            });
            // Panggil fungsi ini saat DOMContentLoaded untuk setelan awal label NIDN/NIM
            // jika ada data POST yang mengisi dropdown
            toggleRegDetailFields();
            // Panggil fetchPrograms saat halaman dimuat jika ada institusi yang sudah terpilih (misal karena ada error POST)
            if (institutionSelectReg.value) {
                fetchPrograms(institutionSelectReg.value);
            }
        });
    </script>
</body>
</html>