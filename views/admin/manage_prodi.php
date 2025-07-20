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
    unset($_SESSION['success_message']); // Hapus pesan setelah ditampilkan
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']); // Hapus pesan setelah ditampilkan
}

// --- LOGIKA UNTUK MENAMBAH PROGRAM STUDI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prodi'])) {
    $institution_id = (int)$_POST['institution_id'];
    $program_name = trim($_POST['program_name']);
    $level = trim($_POST['level']);

    if (empty($institution_id) || empty($program_name) || empty($level)) {
        $error = "Semua kolom (Institusi, Nama Program Studi, Level) harus diisi.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO programs_of_study (institution_id, program_name, level) VALUES (?, ?, ?)");
            $stmt->execute([$institution_id, $program_name, $level]);
            $message = "Program Studi '<strong>" . htmlspecialchars($program_name) . "</strong>' berhasil ditambahkan.";
            // Setelah berhasil, kosongkan input form (opsional)
            $_POST = array(); 
        } catch (PDOException $e) {
            $error = "Error menambahkan Program Studi: " . $e->getMessage();
        }
    }
}

// --- LOGIKA UNTUK MENGHAPUS PROGRAM STUDI ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $prodi_id = (int)$_GET['id'];
    
    try {
        // Hapus program studi
        $stmt = $pdo->prepare("DELETE FROM programs_of_study WHERE id = ?");
        $stmt->execute([$prodi_id]);
        if ($stmt->rowCount()) {
            $_SESSION['success_message'] = "Program Studi berhasil dihapus.";
        } else {
            $_SESSION['error_message'] = "Program Studi tidak ditemukan atau gagal dihapus.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error menghapus Program Studi: " . $e->getMessage();
    }
    // Redirect kembali ke halaman yang sama untuk menghilangkan parameter GET dari URL
    header('Location: manage_prodi.php');
    exit();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR PROGRAM STUDI ---
$programs_of_study = [];
try {
    // Join dengan tabel institutions untuk mendapatkan nama institusi
    $stmt = $pdo->query("SELECT pos.id, i.nama_pt, pos.program_name, pos.level 
                         FROM programs_of_study pos
                         JOIN institutions i ON pos.institution_id = i.id
                         ORDER BY i.nama_pt ASC, pos.program_name ASC");
    $programs_of_study = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Program Studi: " . $e->getMessage();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR INSTITUSI (untuk dropdown di form tambah) ---
$institutions = [];
try {
    $stmt = $pdo->query("SELECT id, nama_pt FROM institutions ORDER BY nama_pt ASC");
    $institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Institusi untuk dropdown: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Program Studi - Admin MATLEV 5D</title>
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
        /* Penyesuaian agar tombol DataTables tidak terlalu rapat */
        .dataTables_wrapper .btn {
            margin-right: 5px;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .btn-action {
            margin-right: 5px;
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
                <a class="nav-link active" href="manage_prodi.php"> <i class="fas fa-fw fa-graduation-cap me-2"></i>Data Program Studi
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
                <a class="navbar-brand" href="#">Data Program Studi</a>
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
                <h5 class="mb-0">Tambah Program Studi Baru</h5>
            </div>
            <div class="card-body">
                <form action="" method="POST">
                    <div class="mb-3">
                        <label for="institution_id" class="form-label">Institusi</label>
                        <select class="form-select" id="institution_id" name="institution_id" required>
                            <option value="">Pilih Institusi</option>
                            <?php foreach ($institutions as $inst): ?>
                                <option value="<?php echo htmlspecialchars($inst['id']); ?>"
                                    <?php echo (isset($_POST['institution_id']) && $_POST['institution_id'] == $inst['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($inst['nama_pt']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($institutions)): ?>
                            <small class="text-danger">Belum ada institusi terdaftar. Tambahkan institusi terlebih dahulu.</small>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="program_name" class="form-label">Nama Program Studi</label>
                        <input type="text" class="form-control" id="program_name" name="program_name" value="<?php echo isset($_POST['program_name']) ? htmlspecialchars($_POST['program_name']) : ''; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="level" class="form-label">Level (Contoh: S1, D3, S2)</label>
                        <input type="text" class="form-control" id="level" name="level" value="<?php echo isset($_POST['level']) ? htmlspecialchars($_POST['level']) : ''; ?>" required>
                    </div>
                    <button type="submit" name="add_prodi" class="btn btn-primary" <?php echo empty($institutions) ? 'disabled' : ''; ?>>Tambah Program Studi</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Daftar Program Studi</h5>
            </div>
            <div class="card-body">
                <?php if (empty($programs_of_study)): ?>
                    <p>Belum ada program studi yang terdaftar.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="prodiTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Institusi</th>
                                    <th>Nama Program Studi</th>
                                    <th>Level</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($programs_of_study as $prodi): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($prodi['nama_pt']); ?></td>
                                        <td><?php echo htmlspecialchars($prodi['program_name']); ?></td>
                                        <td><?php echo htmlspecialchars($prodi['level']); ?></td>
                                        <td>
                                            <a href="edit_prodi.php?id=<?php echo $prodi['id']; ?>" class="btn btn-warning btn-sm btn-action" title="Edit Program Studi">
                                                <i class="fas fa-pencil-alt"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $prodi['id']; ?>" class="btn btn-danger btn-sm btn-action" title="Hapus Program Studi" onclick="return confirm('Apakah Anda yakin ingin menghapus program studi ini? Tindakan ini tidak bisa dibatalkan.');">
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
            // Inisialisasi DataTables pada tabel dengan ID 'prodiTable'
            $('#prodiTable').DataTable({
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