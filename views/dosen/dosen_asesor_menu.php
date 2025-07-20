<?php
session_start();
require_once '../../config/database.php';

// Proteksi halaman: hanya dosen (role_id = 2) yang bisa mengakses.
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 2) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'];
$is_asesor = $_SESSION['is_asesor'] ?? 0; // Pastikan default 0 jika belum diset

// Ambil daftar MATA KULIAH yang diampu oleh dosen ini, beserta link RPS-nya (dari rps_file_path) dan tanggal upload/update
$course_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.course_name,
            c.rps_file_path AS rps_link, -- Mengambil rps_file_path dan memberi alias rps_link
            COALESCE(c.updated_at, c.created_at) AS rps_last_modified_date
        FROM courses c
        JOIN dosen_courses dc ON c.id = dc.course_id
        WHERE dc.dosen_id = :user_id
        ORDER BY c.course_name ASC
    ");
    $stmt->execute([':user_id' => $user_id]);
    $course_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching course list for RPS management (link model, using rps_file_path): " . $e->getMessage());
    $_SESSION['error_message'] = "Gagal mengambil daftar Mata Kuliah. Terjadi kesalahan database.";
}

// Ambil daftar mata kuliah yang diampu oleh dosen ini untuk dropdown
$dosen_courses_dropdown = [];
try {
    $stmt_courses_dropdown = $pdo->prepare("
        SELECT c.id, c.course_name
        FROM courses c
        JOIN dosen_courses dc ON c.id = dc.course_id
        WHERE dc.dosen_id = :dosen_id
        ORDER BY c.course_name
    ");
    $stmt_courses_dropdown->execute([':dosen_id' => $user_id]);
    $dosen_courses_dropdown = $stmt_courses_dropdown->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching courses for dropdown (link model, using rps_file_path): " . $e->getMessage());
    // Biarkan kosong jika error
}

// Pesan sukses atau error dari operasi sebelumnya
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen RPS - MATLEV 5D</title>
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
        /* Style untuk dropdown menu dark agar terlihat di sidebar gelap */
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
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.2em 0.8em;
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
                <a class="nav-link" href="dosen_dashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="dosen_profile.php">
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
                <a class="nav-link active" href="dosen_asesor_menu.php">
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
                <a class="navbar-brand" href="#">Manajemen RPS (Link Google Drive)</a> <span class="navbar-text ms-auto">
                    Halo, <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($role_name); ?>)
                </span>
            </div>
        </nav>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Unggah/Perbarui Link RPS Mata Kuliah</h5>
            </div>
            <div class="card-body">
                <form action="../../process/upload_rps_link.php" method="POST">
                    <div class="mb-3">
                        <label for="courseSelectUpload" class="form-label">Mata Kuliah</label>
                        <select class="form-select" id="courseSelectUpload" name="course_id" required>
                            <option value="">Pilih Mata Kuliah</option>
                            <?php foreach ($dosen_courses_dropdown as $course): ?>
                                <option value="<?php echo htmlspecialchars($course['id']); ?>"><?php echo htmlspecialchars($course['course_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="rpsLink" class="form-label">Link Google Drive RPS (Pastikan Akses Publik!)</label>
                        <input class="form-control" type="url" id="rpsLink" name="rps_link" placeholder="Contoh: https://drive.google.com/file/d/ABCD/view?usp=sharing" required>
                        <div class="form-text">Pastikan link Google Drive sudah diatur agar bisa diakses oleh siapa saja dengan link.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Simpan Link RPS</button>
                </form>
            </div>
        </div>

        <hr>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Daftar Mata Kuliah dengan Link RPS</h5>
            </div>
            <div class="card-body">
                <table id="rpsTable" class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Mata Kuliah</th>
                            <th>Status RPS</th>
                            <th>Terakhir Diperbarui</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($course_list as $course): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                            <td>
                                <?php if (!empty($course['rps_link'])): ?>
                                    <span class="badge bg-success">Link Ada</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Belum Ada Link</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if (!empty($course['rps_link'])) {
                                    echo date('d-m-Y H:i', strtotime($course['rps_last_modified_date']));
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($course['rps_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($course['rps_link']); ?>" target="_blank" class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-external-link-alt"></i> Lihat RPS
                                    </a>
                                    <button type="button" class="btn btn-sm btn-warning me-1" data-bs-toggle="modal" data-bs-target="#editRPSModal"
                                        data-course-id="<?php echo $course['id']; ?>"
                                        data-course-name="<?php echo htmlspecialchars($course['course_name']); ?>"
                                        data-current-rps-link="<?php echo htmlspecialchars($course['rps_link']); ?>">
                                        <i class="fas fa-edit"></i> Ganti Link
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars($course['course_name']); ?>')">
                                        <i class="fas fa-trash-alt"></i> Hapus Link
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#editRPSModal"
                                        data-course-id="<?php echo $course['id']; ?>"
                                        data-course-name="<?php echo htmlspecialchars($course['course_name']); ?>"
                                        data-current-rps-link=""> <i class="fas fa-plus"></i> Tambah Link
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal fade" id="editRPSModal" tabindex="-1" aria-labelledby="editRPSModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editRPSModalLabel">Ganti Link RPS</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="editRPSForm" action="../../process/upload_rps_link.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="course_id" id="editCourseId">
                            <div class="mb-3">
                                <label for="editCourseNameDisplay" class="form-label">Mata Kuliah</label>
                                <input type="text" class="form-control" id="editCourseNameDisplay" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="currentRPSLinkDisplay" class="form-label">Link RPS Saat Ini</label>
                                <p id="currentRPSLinkDisplay" class="form-control-plaintext">
                                    <a href="#" id="currentRPSLink" target="_blank"></a>
                                </p>
                            </div>
                            <div class="mb-3">
                                <label for="editRPSLink" class="form-label">Link Google Drive RPS Baru (Pastikan Akses Publik!)</label>
                                <input class="form-control" type="url" id="editRPSLink" name="rps_link" placeholder="Contoh: https://drive.google.com/file/d/ABCD/view?usp=sharing" required>
                                <div class="form-text">Pastikan link Google Drive sudah diatur agar bisa diakses oleh siapa saja dengan link.</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <footer class="d-flex flex-wrap justify-content-between align-items-center py-3 my-4 border-top">
            <h6 align="center">Copyright © 2025 Doktor Sistem Informasi Universitas Diponegoro - Project By: <a href="mailto:irfan.santiko@amikompurwokerto.ac.id" target="_blank">Irfan Santiko (30000320520035)</a></h6>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="text/javascript" charset="utf8" src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.js"></script>

    <script>
        $(document).ready(function() {
            $('#rpsTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json"
                },
                "order": [[ 1, "asc" ]]
            });

            // Handle edit modal data population for links
            var editRPSModal = document.getElementById('editRPSModal');
            editRPSModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var courseId = button.getAttribute('data-course-id');
                var courseName = button.getAttribute('data-course-name');
                var currentRpsLink = button.getAttribute('data-current-rps-link');

                var modalCourseId = editRPSModal.querySelector('#editCourseId');
                var modalCourseNameDisplay = editRPSModal.querySelector('#editCourseNameDisplay');
                var modalCurrentRPSLink = editRPSModal.querySelector('#currentRPSLink');
                var modalEditRPSLinkInput = editRPSModal.querySelector('#editRPSLink');

                modalCourseId.value = courseId;
                modalCourseNameDisplay.value = courseName;
                
                if (currentRpsLink) {
                    modalCurrentRPSLink.href = currentRpsLink;
                    modalCurrentRPSLink.textContent = currentRpsLink;
                    modalCurrentRPSLink.style.display = 'inline';
                    modalEditRPSLinkInput.value = currentRpsLink; // Isi input dengan link saat ini
                } else {
                    modalCurrentRPSLink.href = '#';
                    modalCurrentRPSLink.textContent = 'Tidak ada link RPS saat ini';
                    modalCurrentRPSLink.style.display = 'block';
                    modalEditRPSLinkInput.value = ''; // Kosongkan input jika tidak ada link
                }
            });
        });

        function confirmDelete(courseId, courseName) {
            if (confirm(`Anda yakin ingin menghapus link RPS untuk mata kuliah "${courseName}"? Tindakan ini akan mengosongkan link RPS.`)) {
                window.location.href = `../../process/delete_rps_link.php?course_id=${courseId}`;
            }
        }
    </script>
</body>
</html>