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

// --- Mengambil dan Menghapus Pesan dari Session ---
$message = ''; 
$error = '';   

if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); 
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']); 
}

// --- LOGIKA UNTUK MENAMBAH PERTANYAAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $question_text = trim($_POST['question_text']);
    $aspect_id = (int)$_POST['aspect_id'];
    $question_type_id = (int)$_POST['question_type_id'];

    if (empty($question_text) || empty($aspect_id) || empty($question_type_id)) {
        $error = "Semua kolom (Pertanyaan, Aspek, Tipe Pertanyaan) harus diisi.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO assessment_questions (question_text, aspect_id, question_type_id) VALUES (?, ?, ?)");
            $stmt->execute([$question_text, $aspect_id, $question_type_id]);
            $message = "Pertanyaan berhasil ditambahkan.";
            $_POST = array(); // Kosongkan input form setelah berhasil
        } catch (PDOException $e) {
            $error = "Error menambahkan pertanyaan: " . $e->getMessage();
        }
    }
}

// --- LOGIKA UNTUK MENGHAPUS PERTANYAAN ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $question_id = (int)$_GET['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM assessment_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        if ($stmt->rowCount()) {
            $_SESSION['success_message'] = "Pertanyaan berhasil dihapus.";
        } else {
            $_SESSION['error_message'] = "Pertanyaan tidak ditemukan atau gagal dihapus.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error menghapus pertanyaan: " . $e->getMessage();
    }
    header('Location: setting_questions.php');
    exit();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR ASPEK ---
$aspects = [];
try {
    $stmt = $pdo->query("SELECT id, aspect_name FROM assessment_aspects ORDER BY aspect_name ASC");
    $aspects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Aspek: " . $e->getMessage();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR TIPE PERTANYAAN ---
$question_types = [];
try {
    $stmt = $pdo->query("SELECT id, type_name FROM assessment_question_types ORDER BY type_name ASC");
    $question_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Tipe Pertanyaan: " . $e->getMessage();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR PERTANYAAN ---
$questions = [];
try {
    $stmt = $pdo->query("SELECT aq.id, aq.question_text, aa.aspect_name, aqt.type_name 
                         FROM assessment_questions aq
                         JOIN assessment_aspects aa ON aq.aspect_id = aa.id
                         JOIN assessment_question_types aqt ON aq.question_type_id = aqt.id
                         ORDER BY aa.aspect_name ASC, aq.id ASC");
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Pertanyaan: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Pertanyaan Instrumen - Admin MATLEV 5D</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" href="../../assets/img/favicon.png" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    
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
        .dataTables_wrapper .btn {
            margin-right: 5px;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .btn-action {
            margin-right: 5px;
        }
        /* Style untuk dropdown menu dark agar terlihat di sidebar gelap */
        .dropdown-menu-dark {
            background-color: #495057; /* Warna latar belakang menu dropdown */
            border: 1px solid rgba(0, 0, 0, 0.15);
        }
        .dropdown-menu-dark .dropdown-item {
            color: white; /* Warna teks item dropdown */
        }
        .dropdown-menu-dark .dropdown-item:hover,
        .dropdown-menu-dark .dropdown-item:focus {
            background-color: #6c757d; /* Warna hover untuk item dropdown */
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
                <a class="navbar-brand" href="#">Manajemen Pertanyaan Instrumen</a>
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
            <div class="card-header">
                <h5 class="mb-0">Tambah Pertanyaan Baru</h5>
            </div>
            <div class="card-body">
                <form action="" method="POST">
                    <div class="mb-3">
                        <label for="question_text" class="form-label">Teks Pertanyaan</label>
                        <textarea class="form-control" id="question_text" name="question_text" rows="3" required><?php echo isset($_POST['question_text']) ? htmlspecialchars($_POST['question_text']) : ''; ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="aspect_id" class="form-label">Aspek</label>
                        <select class="form-select" id="aspect_id" name="aspect_id" required>
                            <option value="">Pilih Aspek</option>
                            <?php foreach ($aspects as $aspect): ?>
                                <option value="<?php echo htmlspecialchars($aspect['id']); ?>"
                                    <?php echo (isset($_POST['aspect_id']) && $_POST['aspect_id'] == $aspect['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($aspect['aspect_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($aspects)): ?>
                            <small class="text-danger">Belum ada aspek terdaftar. Tambahkan data di tabel 'assessment_aspects'.</small>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="question_type_id" class="form-label">Tipe Pertanyaan Untuk:</label>
                        <select class="form-select" id="question_type_id" name="question_type_id" required>
                            <option value="">Pertanyaan Untuk</option>
                            <?php foreach ($question_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['id']); ?>"
                                    <?php echo (isset($_POST['question_type_id']) && $_POST['question_type_id'] == $type['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['type_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($question_types)): ?>
                            <small class="text-danger">Belum ada tipe pertanyaan terdaftar. Tambahkan data di tabel 'assessment_question_types'.</small>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="add_question" class="btn btn-primary" <?php echo (empty($aspects) || empty($question_types)) ? 'disabled' : ''; ?>>Tambah Pertanyaan</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Daftar Pertanyaan Instrumen</h5>
            </div>
            <div class="card-body">
                <?php if (empty($questions)): ?>
                    <p>Belum ada pertanyaan instrumen yang terdaftar.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="questionsTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Teks Pertanyaan</th>
                                    <th>Aspek</th>
                                    <th>Tipe</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($questions as $question): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($question['question_text']); ?></td>
                                        <td><?php echo htmlspecialchars($question['aspect_name']); ?></td>
                                        <td><?php echo htmlspecialchars($question['type_name']); ?></td>
                                        <td>
                                            <a href="edit_question.php?id=<?php echo $question['id']; ?>" class="btn btn-warning btn-sm btn-action" title="Edit Pertanyaan">
                                                <i class="fas fa-pencil-alt"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $question['id']; ?>" class="btn btn-danger btn-sm btn-action" title="Hapus Pertanyaan" onclick="return confirm('Apakah Anda yakin ingin menghapus pertanyaan ini? Tindakan ini tidak bisa dibatalkan.');">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            // Inisialisasi DataTables pada tabel dengan ID 'questionsTable'
            $('#questionsTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json" // Bahasa Indonesia
                },
                "columnDefs": [
                    { "orderable": false, "targets": [4] } // Disable ordering on 'Aksi' column
                ]
            });

            // Menghilangkan pesan alert setelah beberapa detik (opsional)
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000); // Pesan hilang setelah 5 detik
        });
    </script>
</body>
</html>