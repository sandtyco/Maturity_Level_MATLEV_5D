<?php
require_once '../config/database.php'; // Sesuaikan path ke file database.php Anda

header('Content-Type: application/json'); // Penting: memberitahu browser bahwa respons adalah JSON

$term = $_GET['term'] ?? ''; // Ambil query pencarian dari jQuery UI
// Kita juga perlu institution_id untuk memfilter program studi berdasarkan institusi yang dipilih
$institution_id = $_GET['institution_id'] ?? null;

$programs = [];

if (!empty($term)) {
    try {
        $sql = "SELECT id, program_name AS value FROM programs_of_study WHERE program_name LIKE :term";
        $params = [':term' => '%' . $term . '%'];

        // Jika institution_id disediakan, filter juga berdasarkan institusi
        if ($institution_id) {
            $sql .= " AND institution_id = :institution_id";
            $params[':institution_id'] = $institution_id;
        }

        $sql .= " ORDER BY program_name LIMIT 10";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching programs: " . $e->getMessage());
        echo json_encode([]);
        exit();
    }
}

echo json_encode($programs);
?>