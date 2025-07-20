<?php
require_once '../config/database.php'; // Sesuaikan path ini jika perlu

header('Content-Type: application/json'); // Penting: Memberi tahu browser bahwa respons adalah JSON

$institution_id = $_GET['institution_id'] ?? null;
$programs = [];

if ($institution_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, program_name AS name FROM programs_of_study WHERE institution_id = :institution_id ORDER BY program_name");
        $stmt->execute([':institution_id' => $institution_id]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error di server, jangan tampilkan ke user di production
        error_log("Error fetching programs of study by institution: " . $e->getMessage());
        // Kirim array kosong jika ada error, atau pesan error yang lebih spesifik jika diinginkan
        echo json_encode(['error' => 'Could not fetch programs of study.']);
        exit();
    }
}

echo json_encode($programs);
?>