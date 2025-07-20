<?php
require_once '../config/database.php'; // Sesuaikan path ke file database.php Anda

header('Content-Type: application/json'); // Penting: memberitahu browser bahwa respons adalah JSON

$term = $_GET['term'] ?? ''; // Ambil query pencarian dari jQuery UI

$institutions = [];

if (!empty($term)) {
    try {
        // Query untuk mencari institusi yang namanya mirip dengan 'term'
        $stmt = $pdo->prepare("SELECT id, nama_pt AS value FROM institutions WHERE nama_pt LIKE :term ORDER BY nama_pt LIMIT 10");
        $stmt->execute([':term' => '%' . $term . '%']);
        $institutions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error jika diperlukan, tapi jangan tampilkan ke user
        error_log("Error fetching institutions: " . $e->getMessage());
        // Return array kosong jika ada error
        echo json_encode([]);
        exit();
    }
}

echo json_encode($institutions);
?>