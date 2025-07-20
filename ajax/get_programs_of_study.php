<?php
require_once '../config/database.php'; // Sesuaikan path ke file database.php jika diperlukan

header('Content-Type: application/json'); // Penting: Memberi tahu browser bahwa responsnya adalah JSON

$institutionId = $_GET['institution_id'] ?? null;
$programsOfStudy = []; // Mengubah nama variabel agar konsisten dengan sebelumnya

if ($institutionId) {
    try {
        // Mengubah "AS name" agar key di JSON sesuai dengan yang diharapkan JS (program.program_name)
        $stmt = $pdo->prepare("SELECT id, program_name FROM programs_of_study WHERE institution_id = :institution_id ORDER BY program_name");
        $stmt->execute([':institution_id' => $institutionId]);
        $programsOfStudy = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mengirim respons sukses dengan data
        echo json_encode(['success' => true, 'data' => $programsOfStudy]);

    } catch (PDOException $e) {
        error_log("Error fetching programs of study: " . $e->getMessage()); // Log error ke log server
        // Mengirim respons gagal dengan pesan error
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat memuat Program Studi.']);
    }
} else {
    // Mengirim respons gagal jika ID Institusi tidak diberikan
    echo json_encode(['success' => false, 'message' => 'ID Institusi tidak valid.']);
}
?>