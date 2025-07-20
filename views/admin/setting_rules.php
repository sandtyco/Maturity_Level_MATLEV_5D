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

// --- LOGIKA UNTUK MENAMBAH ATURAN BARU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rule'])) {
    $rps_dim_id = filter_var($_POST['rps_dimension_id'], FILTER_VALIDATE_INT);
    $dosen_dim_id = filter_var($_POST['dosen_dimension_id'], FILTER_VALIDATE_INT);
    $mahasiswa_dim_id = filter_var($_POST['mahasiswa_dimension_id'], FILTER_VALIDATE_INT);
    $eff_action_id = filter_var($_POST['effectiveness_action_id'], FILTER_VALIDATE_INT);
    $rule_desc = trim($_POST['rule_description']);

    if ($rps_dim_id && $dosen_dim_id && $mahasiswa_dim_id && $eff_action_id) {
        try {
            $stmt = $pdo->prepare("INSERT INTO final_effectiveness_rules (rps_dimension_id, dosen_dimension_id, mahasiswa_dimension_id, effectiveness_action_id, rule_description) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$rps_dim_id, $dosen_dim_id, $mahasiswa_dim_id, $eff_action_id, $rule_desc]);
            $_SESSION['success_message'] = "Aturan baru berhasil ditambahkan.";
        } catch (PDOException $e) {
            // Cek jika error adalah karena UNIQUE constraint violation (kode SQLSTATE '23000')
            if ($e->getCode() == '23000') { 
                $_SESSION['error_message'] = "Aturan ini (kombinasi dimensi RPS, Dosen, dan Mahasiswa) sudah ada. Silakan edit aturan yang sudah ada atau masukkan kombinasi yang berbeda.";
            } else {
                // Untuk error lainnya, log dan tampilkan pesan umum
                error_log("Error adding rule: " . $e->getMessage()); // Penting untuk debugging
                $_SESSION['error_message'] = "Terjadi kesalahan saat menambahkan aturan: " . $e->getMessage();
            }
        }
    } else {
        $_SESSION['error_message'] = "Semua input harus diisi dengan benar.";
    }
    header('Location: setting_rules.php');
    exit();
}

// --- LOGIKA UNTUK MENGHAPUS ATURAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rule'])) {
    $rule_id = filter_var($_POST['rule_id'], FILTER_VALIDATE_INT);

    if ($rule_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM final_effectiveness_rules WHERE id = ?");
            $stmt->execute([$rule_id]);
            if ($stmt->rowCount() > 0) {
                $_SESSION['success_message'] = "Aturan berhasil dihapus.";
            } else {
                $_SESSION['error_message'] = "Aturan tidak ditemukan.";
            }
        } catch (PDOException $e) {
            error_log("Error deleting rule: " . $e->getMessage());
            $_SESSION['error_message'] = "Error saat menghapus aturan: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "ID Aturan tidak valid.";
    }
    header('Location: setting_rules.php');
    exit();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR DIMENSI DAN AKSI EFEKTIVITAS UNTUK DROPDOWN ---
$dimensions = [];
try {
    $stmt = $pdo->query("SELECT id, dimension_name FROM temus_dimensions ORDER BY id ASC");
    $dimensions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error .= "Error mengambil data Dimensi: " . $e->getMessage() . "<br>";
}

$effectiveness_actions = [];
try {
    $stmt = $pdo->query("SELECT id, effectiveness_status, description FROM effectiveness_actions ORDER BY id ASC");
    $effectiveness_actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error .= "Error mengambil data Aksi Efektivitas: " . $e->getMessage() . "<br>";
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR ATURAN YANG ADA ---
$rules = [];
try {
    $stmt = $pdo->query("
        SELECT 
            fer.id,
            fer.rule_description,
            td_rps.dimension_name AS rps_dimension_name,
            td_dosen.dimension_name AS dosen_dimension_name,
            td_mahasiswa.dimension_name AS mahasiswa_dimension_name,
            ea.effectiveness_status AS effectiveness_action_name
        FROM 
            final_effectiveness_rules fer
        JOIN 
            temus_dimensions td_rps ON fer.rps_dimension_id = td_rps.id
        JOIN 
            temus_dimensions td_dosen ON fer.dosen_dimension_id = td_dosen.id
        JOIN 
            temus_dimensions td_mahasiswa ON fer.mahasiswa_dimension_id = td_mahasiswa.id
        JOIN 
            effectiveness_actions ea ON fer.effectiveness_action_id = ea.id
        ORDER BY fer.id ASC
    ");
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error .= "Error mengambil data Aturan: " . $e->getMessage() . "<br>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Aturan Efektivitas - Admin MATLEV 5D</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.css">
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
                <a class="navbar-brand" href="#">Manajemen Aturan Efektivitas</a>
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
                <h5 class="mb-0">Tambah Aturan Efektivitas Baru</h5>
            </div>
            <div class="card-body">
                <form action="" method="POST">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="rps_dimension_id" class="form-label">Dimensi RPS:</label>
                            <select class="form-select" id="rps_dimension_id" name="rps_dimension_id" required>
                                <option value="">Pilih Dimensi RPS</option>
                                <?php foreach ($dimensions as $dim): ?>
                                    <option value="<?php echo htmlspecialchars($dim['id']); ?>">
                                        <?php echo htmlspecialchars($dim['dimension_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="dosen_dimension_id" class="form-label">Dimensi Dosen:</label>
                            <select class="form-select" id="dosen_dimension_id" name="dosen_dimension_id" required>
                                <option value="">Pilih Dimensi Dosen</option>
                                <?php foreach ($dimensions as $dim): ?>
                                    <option value="<?php echo htmlspecialchars($dim['id']); ?>">
                                        <?php echo htmlspecialchars($dim['dimension_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="mahasiswa_dimension_id" class="form-label">Dimensi Mahasiswa:</label>
                            <select class="form-select" id="mahasiswa_dimension_id" name="mahasiswa_dimension_id" required>
                                <option value="">Pilih Dimensi Mahasiswa</option>
                                <?php foreach ($dimensions as $dim): ?>
                                    <option value="<?php echo htmlspecialchars($dim['id']); ?>">
                                        <?php echo htmlspecialchars($dim['dimension_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="effectiveness_action_id" class="form-label">Hasil Efektivitas:</label>
                        <select class="form-select" id="effectiveness_action_id" name="effectiveness_action_id" required>
                            <option value="">Pilih Hasil Efektivitas</option>
                            <?php foreach ($effectiveness_actions as $action): ?>
                                <option 
                                    value="<?php echo htmlspecialchars($action['id']); ?>"
                                    data-description="<?php echo htmlspecialchars($action['description']); ?>"
                                >
                                    <?php echo htmlspecialchars($action['effectiveness_status']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="rule_description" class="form-label">Deskripsi Aturan (Opsional):</label>
                        <textarea class="form-control" id="rule_description" name="rule_description" rows="2"></textarea>
                    </div>
                    <button type="submit" name="add_rule" class="btn btn-success">Tambah Aturan</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Daftar Aturan Efektivitas</h5>
            </div>
            <div class="card-body">
                <?php if (empty($rules)): ?>
                    <p>Belum ada aturan efektivitas yang terdaftar.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="rulesTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Dimensi RPS</th>
                                    <th>Dimensi Dosen</th>
                                    <th>Dimensi Mahasiswa</th>
                                    <th>Hasil Efektivitas</th>
                                    <th>Deskripsi Aturan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $rule): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($rule['id']); ?></td>
                                        <td><?php echo htmlspecialchars($rule['rps_dimension_name']); ?></td>
                                        <td><?php echo htmlspecialchars($rule['dosen_dimension_name']); ?></td>
                                        <td><?php echo htmlspecialchars($rule['mahasiswa_dimension_name']); ?></td>
                                        <td><?php echo htmlspecialchars($rule['effectiveness_action_name']); ?></td>
                                        <td><?php echo htmlspecialchars($rule['rule_description']); ?></td>
                                        <td>
                                            <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus aturan ini?');">
                                                <input type="hidden" name="rule_id" value="<?php echo htmlspecialchars($rule['id']); ?>">
                                                <button type="submit" name="delete_rule" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </form>
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
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>
    <script>
        $(document).ready(function() {
            // Menghilangkan pesan alert setelah beberapa detik (opsional)
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000); // Pesan hilang setelah 5 detik

            // JavaScript untuk mengisi deskripsi aturan secara otomatis
            $('#effectiveness_action_id').change(function() {
                var selectedOption = $(this).find('option:selected');
                var description = selectedOption.data('description');
                $('#rule_description').val(description);
            });

            // Inisialisasi DataTables
            $('#rulesTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json" // Bahasa Indonesia
                }
            });
        });
    </script>
</body>
</html>