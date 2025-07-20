<?php
session_start();
require_once '../config/database.php'; // Sesuaikan path ke file database.php

header('Content-Type: application/json');

// Pastikan request adalah AJAX dan memiliki program_of_study_id
if (!isset($_GET['program_of_study_id']) || empty($_GET['program_of_study_id'])) {
    echo json_encode(['error' => 'Program Study ID is required.']);
    exit();
}

$program_of_study_id = filter_var($_GET['program_of_study_id'], FILTER_SANITIZE_NUMBER_INT);
if (!is_numeric($program_of_study_id)) {
    echo json_encode(['error' => 'Invalid Program Study ID.']);
    exit();
}

$dimension_labels = ['Traditional', 'Enhance', 'Mobile', 'Ubiquitous', 'Smart'];
$rps_dimension_data = array_fill_keys($dimension_labels, 0);
$dosen_dimension_data = array_fill_keys($dimension_labels, 0);
$mahasiswa_dimension_data = array_fill_keys($dimension_labels, 0);

try {
    // --- Query untuk Statistik Dimensi RPS (TEMUS) berdasarkan Program Studi ---
    // Diasumsikan tabel 'courses' memiliki 'program_of_study_id'
    $stmt_rps_dimensions = $pdo->prepare("
        SELECT td.dimension_name, COUNT(DISTINCT ra.course_id) AS total_items
        FROM rps_assessments AS ra
        JOIN courses AS c ON ra.course_id = c.id
        JOIN temus_dimensions AS td ON ra.classified_temus_id = td.id
        WHERE c.program_of_study_id = :program_of_study_id
          AND ra.classified_temus_id IS NOT NULL AND ra.classified_temus_id != ''
        GROUP BY td.dimension_name
    ");
    $stmt_rps_dimensions->execute([':program_of_study_id' => $program_of_study_id]);
    $rps_results = $stmt_rps_dimensions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rps_results as $row) {
        if (array_key_exists($row['dimension_name'], $rps_dimension_data)) {
            $rps_dimension_data[$row['dimension_name']] = (int) $row['total_items'];
        }
    }

    // --- Query untuk Statistik Dimensi Dosen (TEMUS) berdasarkan Program Studi ---
    // Diasumsikan 'dosen_details' memiliki 'program_of_study_id' dan 'self_assessments_3m' memiliki 'user_id' yang merupakan ID Dosen
    $stmt_dosen_dimensions = $pdo->prepare("
        SELECT td.dimension_name, COUNT(DISTINCT sa.user_id) AS total_items
        FROM self_assessments_3m AS sa
        JOIN users AS u ON sa.user_id = u.id
        JOIN dosen_details AS dd ON u.id = dd.user_id
        JOIN temus_dimensions AS td ON sa.classified_temus_id = td.id
        WHERE sa.user_role_at_assessment = 'Dosen'
          AND dd.program_of_study_id = :program_of_study_id
          AND sa.classified_temus_id IS NOT NULL AND sa.classified_temus_id != ''
        GROUP BY td.dimension_name
    ");
    $stmt_dosen_dimensions->execute([':program_of_study_id' => $program_of_study_id]);
    $dosen_results = $stmt_dosen_dimensions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dosen_results as $row) {
        if (array_key_exists($row['dimension_name'], $dosen_dimension_data)) {
            $dosen_dimension_data[$row['dimension_name']] = (int) $row['total_items'];
        }
    }

    // --- Query untuk Statistik Dimensi Mahasiswa (TEMUS) berdasarkan Program Studi ---
    // Diasumsikan 'mahasiswa_details' memiliki 'program_of_study_id' dan 'self_assessments_3m' memiliki 'user_id' yang merupakan ID Mahasiswa
    $stmt_mahasiswa_dimensions = $pdo->prepare("
        SELECT td.dimension_name, COUNT(DISTINCT sa.user_id) AS total_items
        FROM self_assessments_3m AS sa
        JOIN users AS u ON sa.user_id = u.id
        JOIN mahasiswa_details AS md ON u.id = md.user_id
        JOIN temus_dimensions AS td ON sa.classified_temus_id = td.id
        WHERE sa.user_role_at_assessment = 'Mahasiswa'
          AND md.program_of_study_id = :program_of_study_id
          AND sa.classified_temus_id IS NOT NULL AND sa.classified_temus_id != ''
        GROUP BY td.dimension_name
    ");
    $stmt_mahasiswa_dimensions->execute([':program_of_study_id' => $program_of_study_id]);
    $mahasiswa_results = $stmt_mahasiswa_dimensions->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mahasiswa_results as $row) {
        if (array_key_exists($row['dimension_name'], $mahasiswa_dimension_data)) {
            $mahasiswa_dimension_data[$row['dimension_name']] = (int) $row['total_items'];
        }
    }

    echo json_encode([
        'rps_data' => $rps_dimension_data,
        'dosen_data' => $dosen_dimension_data,
        'mahasiswa_data' => $mahasiswa_dimension_data
    ]);

} catch (PDOException $e) {
    error_log("Database Error in get_program_dimension_data.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>