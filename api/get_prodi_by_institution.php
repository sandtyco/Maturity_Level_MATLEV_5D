<?php
// Pastikan path ini benar-benar mengarah ke file database.php Anda
// Jika file ini (get_prodi_by_institution.php) ada di folder 'api'
// dan 'database.php' ada di folder 'config' yang sejajar dengan 'api',
// maka path '../config/database.php' sudah benar.
require_once '../config/database.php'; 

header('Content-Type: application/json'); // Memberitahu browser bahwa respons adalah JSON

$institution_id = isset($_GET['institution_id']) ? (int)$_GET['institution_id'] : 0;

$programs_of_study = [];

if ($institution_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, program_name FROM programs_of_study WHERE institution_id = ? ORDER BY program_name ASC");
        $stmt->execute([$institution_id]);
        $programs_of_study = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Ini akan mencatat error ke log server PHP Anda (misal: error.log)
        error_log("Error fetching programs of study by institution (get_prodi_by_institution.php): " . $e->getMessage());
        // Mengembalikan respons error yang bisa di-debug oleh frontend
        http_response_code(500); // Set status kode HTTP menjadi 500 (Internal Server Error)
        echo json_encode(['error' => 'Gagal mengambil data program studi. Silakan cek log server.']);
        exit(); // Hentikan eksekusi skrip
    }
}

echo json_encode($programs_of_study);
?>