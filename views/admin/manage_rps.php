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

// --- LOGIKA UNTUK MENAMBAH MATA KULIAH ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course'])) {
    $institution_id = (int)$_POST['institution_id'];
    $program_of_study_id = (int)$_POST['program_of_study_id'];
    $course_name = trim($_POST['course_name']);
    $courses_type = trim($_POST['courses_type']);
    $semester = trim($_POST['semester']);
    $sks_credits = (int)$_POST['sks_credits'];
    $rps_file_path = trim($_POST['rps_file_path']);

    // Tambahkan validasi untuk rps_file_path
    if (empty($institution_id) || empty($program_of_study_id) || empty($course_name) || empty($courses_type) || empty($semester) || empty($sks_credits) || empty($rps_file_path)) {
        $error = "Semua kolom harus diisi.";
    } elseif (!filter_var($rps_file_path, FILTER_VALIDATE_URL)) {
        $error = "Format URL File RPS tidak valid.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO courses (institution_id, program_of_study_id, course_name, courses_type, semester, sks_credits, rps_file_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$institution_id, $program_of_study_id, $course_name, $courses_type, $semester, $sks_credits, $rps_file_path]);
            $_SESSION['success_message'] = "Mata Kuliah '<strong>" . htmlspecialchars($course_name) . "</strong>' berhasil ditambahkan.";
            header('Location: manage_rps.php'); // Redirect untuk mencegah resubmission
            exit();
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Error menambahkan Mata Kuliah: " . $e->getMessage();
            header('Location: manage_rps.php'); // Redirect bahkan jika ada error untuk menampilkan pesan
            exit();
        }
    }
}

// --- LOGIKA UNTUK MENGHAPUS MATA KULIAH ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $course_id = (int)$_GET['id'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
        $stmt->execute([$course_id]);
        if ($stmt->rowCount()) {
            $_SESSION['success_message'] = "Mata Kuliah berhasil dihapus.";
        } else {
            $_SESSION['error_message'] = "Mata Kuliah tidak ditemukan atau gagal dihapus.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error menghapus Mata Kuliah: " . $e->getMessage();
    }
    header('Location: manage_rps.php');
    exit();
}

// --- LOGIKA UNTUK MENGAMBIL DAFTAR INSTITUSI (untuk dropdown) ---
$institutions = [];
try {
    $stmt = $pdo->query("SELECT id, nama_pt FROM institutions ORDER BY nama_pt ASC");
    $institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Institusi untuk dropdown: " . $e->getMessage();
}

// Hapus bagian ini. Program studi akan dimuat sepenuhnya oleh AJAX.
// $programs_of_study = [];
// if (!empty($institutions)) {
//     try {
//         $selected_institution_id = isset($_POST['institution_id']) ? (int)$_POST['institution_id'] : $institutions[0]['id'];
//         $stmt_prodi = $pdo->prepare("SELECT id, program_name FROM programs_of_study WHERE institution_id = ? ORDER BY program_name ASC");
//         $stmt_prodi->execute([$selected_institution_id]);
//         $programs_of_study = $stmt_prodi->fetchAll(PDO::FETCH_ASSOC);
//     } catch (PDOException $e) {
//         $error = "Error mengambil data Program Studi untuk dropdown: " . $e->getMessage();
//     }
// }

// --- LOGIKA UNTUK MENGAMBIL DAFTAR MATA KULIAH ---
$courses = [];
try {
    // Join dengan tabel institutions dan programs_of_study untuk mendapatkan nama
    $stmt = $pdo->query("SELECT c.id, i.nama_pt, pos.program_name, c.course_name, c.courses_type, c.semester, c.sks_credits, c.rps_file_path 
                          FROM courses c
                          JOIN institutions i ON c.institution_id = i.id
                          JOIN programs_of_study pos ON c.program_of_study_id = pos.id
                          ORDER BY i.nama_pt ASC, pos.program_name ASC, c.course_name ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error mengambil data Mata Kuliah: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data RPS Mata Kuliah (RPS) - Admin MATLEV 5D</title>
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
        .dropdown-menu-dark {
            background-color: #495057;
            border: 1px solid rgba(0, 0, 0, 0.15);
        }
        .dropdown-menu-dark .dropdown-item {
            color: white;
        }
        .dropdown-menu-dark .dropdown-item:hover,
        .dropdown-menu-dark .dropdown-item:focus {
            background-color: #6c757d;
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
                <a class="navbar-brand" href="#">Data RPS Mata Kuliah (RPS)</a>
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
                <h5 class="mb-0">Tambah Mata Kuliah Baru</h5>
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
                        <label for="program_of_study_id" class="form-label">Program Studi</label>
                        <select class="form-select" id="program_of_study_id" name="program_of_study_id" required disabled>
                            <option value="">Pilih Institusi terlebih dahulu</option>
                        </select>
                        <small id="prodiHelpText" class="form-text text-muted">Pilih institusi untuk menampilkan program studi.</small>
                    </div>
                    <div class="mb-3">
                        <label for="course_name" class="form-label">Nama Mata Kuliah</label>
                        <input type="text" class="form-control" id="course_name" name="course_name" value="<?php echo isset($_POST['course_name']) ? htmlspecialchars($_POST['course_name']) : ''; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="courses_type" class="form-label">Rumpun Mata Kuliah</label>
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
                        <input type="number" class="form-control" id="semester" name="semester" min="1" value="<?php echo isset($_POST['semester']) ? htmlspecialchars($_POST['semester']) : ''; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="sks_credits" class="form-label">SKS</label>
                        <input type="number" class="form-control" id="sks_credits" name="sks_credits" min="1" value="<?php echo isset($_POST['sks_credits']) ? htmlspecialchars($_POST['sks_credits']) : ''; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="rps_file_path" class="form-label">URL File RPS (Google Drive, dll.)</label>
                        <input type="url" class="form-control" id="rps_file_path" name="rps_file_path" placeholder="e.g., https://docs.google.com/document/d/..." value="<?php echo isset($_POST['rps_file_path']) ? htmlspecialchars($_POST['rps_file_path']) : ''; ?>" required>
                        <small class="form-text text-muted">Masukkan tautan ke file RPS Anda.</small>
                    </div>
                    <button type="submit" name="add_course" class="btn btn-primary" <?php echo (empty($institutions)) ? 'disabled' : ''; ?>>Tambah Mata Kuliah</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Daftar Mata Kuliah (RPS)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($courses)): ?>
                    <p>Belum ada mata kuliah yang terdaftar.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table id="coursesTable" class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Institusi</th>
                                    <th>Program Studi</th>
                                    <th>Mata Kuliah</th>
                                    <th>Tipe</th>
                                    <th>Semester</th>
                                    <th>SKS</th>
                                    <th>Link RPS</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($courses as $course): ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($course['nama_pt']); ?></td>
                                        <td><?php echo htmlspecialchars($course['program_name']); ?></td>
                                        <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                        <td><?php echo htmlspecialchars($course['courses_type']); ?></td>
                                        <td><?php echo htmlspecialchars($course['semester']); ?></td>
                                        <td><?php echo htmlspecialchars($course['sks_credits']); ?></td>
                                        <td>
                                            <?php if (!empty($course['rps_file_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($course['rps_file_path']); ?>" target="_blank" class="btn btn-info btn-sm" title="Lihat RPS">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="edit_rps.php?id=<?php echo $course['id']; ?>" class="btn btn-warning btn-sm btn-action" title="Edit Mata Kuliah">
                                                <i class="fas fa-pencil-alt"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $course['id']; ?>" class="btn btn-danger btn-sm btn-action" title="Hapus Mata Kuliah" onclick="return confirm('Apakah Anda yakin ingin menghapus mata kuliah ini? Tindakan ini tidak bisa dibatalkan.');">
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
            // Inisialisasi DataTables pada tabel dengan ID 'coursesTable'
            $('#coursesTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json" // Bahasa Indonesia
                },
                "columnDefs": [
                    { "orderable": false, "targets": [8] } // Disable ordering on 'Aksi' column
                ]
            });

            // Menghilangkan pesan alert setelah beberapa detik (opsional)
            setTimeout(function() {
                $('.alert').alert('close');
            }, 5000); // Pesan hilang setelah 5 detik

            // Fitur dinamis: Load Program Studi berdasarkan Institusi yang dipilih
            $('#institution_id').change(function() {
                var institutionId = $(this).val();
                var prodiSelect = $('#program_of_study_id');
                var prodiHelpText = $('#prodiHelpText');
                
                prodiSelect.empty(); // Kosongkan opsi yang ada
                prodiSelect.prop('disabled', true); // Nonaktifkan dropdown prodi
                prodiSelect.append('<option value="">Memuat...</option>'); // Tampilkan pesan loading
                prodiHelpText.removeClass('text-danger').addClass('text-muted').text('Pilih institusi untuk menampilkan program studi.'); // Reset help text

                if (institutionId) {
                    $.ajax({
                        url: '../../api/get_prodi_by_institution.php', // Path ke API baru
                        type: 'GET',
                        data: { institution_id: institutionId },
                        dataType: 'json',
                        success: function(data) {
                            prodiSelect.empty(); // Kosongkan lagi sebelum mengisi
                            if (data.length > 0) {
                                prodiSelect.append('<option value="">Pilih Program Studi</option>');
                                $.each(data, function(key, entry) {
                                    prodiSelect.append($('<option></option>').attr('value', entry.id).text(entry.program_name));
                                });
                                prodiSelect.prop('disabled', false); // Aktifkan dropdown
                                prodiHelpText.removeClass('text-danger').addClass('text-muted').text('Program studi berhasil dimuat.');
                            } else {
                                prodiSelect.append('<option value="">Tidak ada Program Studi untuk institusi ini</option>');
                                prodiHelpText.removeClass('text-muted').addClass('text-danger').text('Belum ada program studi untuk institusi terpilih. Tambahkan program studi terlebih dahulu.');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: " + status + error);
                            prodiSelect.empty().append('<option value="">Gagal memuat Program Studi</option>');
                            prodiHelpText.removeClass('text-muted').addClass('text-danger').text('Gagal memuat program studi. Terjadi kesalahan.');
                        }
                    });
                } else {
                    prodiSelect.empty().append('<option value="">Pilih Institusi terlebih dahulu</option>');
                    prodiHelpText.removeClass('text-danger').addClass('text-muted').text('Pilih institusi untuk menampilkan program studi.');
                }
            });

            // Jika ada nilai POST untuk institution_id saat halaman dimuat (misalnya setelah submit form), trigger perubahan
            <?php if (isset($_POST['institution_id']) && !empty($_POST['institution_id'])): ?>
                $('#institution_id').val('<?php echo (int)$_POST['institution_id']; ?>').trigger('change');
                // Setelah trigger change, kita perlu memilih ulang program studi jika ada di POST
                // Ini akan dilakukan setelah AJAX selesai memuat opsi
                $(document).ajaxStop(function() {
                    var postedProdiId = '<?php echo isset($_POST['program_of_study_id']) ? (int)$_POST['program_of_study_id'] : ''; ?>';
                    if (postedProdiId) {
                        $('#program_of_study_id').val(postedProdiId);
                    }
                });
            <?php endif; ?>

            // Atur status disabled tombol submit jika belum ada institusi
            <?php if (empty($institutions)): ?>
                $('button[name="add_course"]').prop('disabled', true);
            <?php endif; ?>
        });
    </script>
</body>
</html>