<?php
// api/get_institution_stats.php
session_start();
require_once '../config/database.php'; // <--- PASTIKAN PATH INI BENAR SESUAI DENGAN LOKASI FILE INI!

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'has_data' => false];

if (!isset($_GET['institution_id'])) {
    $response['message'] = 'Institution ID not provided.';
    echo json_encode($response);
    exit();
}

$institution_id = $_GET['institution_id'];

// Definisikan dimensi TEMUS dalam urutan yang konsisten dengan chartColors di frontend
$dimension_labels = ['Traditional', 'Enhance', 'Mobile', 'Ubiquitous', 'Smart'];

try {
    // --- Ambil Rata-rata Skor TEMUS per Program Studi untuk Institusi ---
    $avg_temus_data = ['labels' => [], 'data' => []];
    $stmt_avg_temus = $pdo->prepare("
        SELECT
            pos.program_name AS program_name,
            AVG(sa.classified_temus_id) AS avg_score
        FROM
            self_assessments_3m sa
        JOIN
            courses c ON sa.course_id = c.id
        JOIN
            programs_of_study pos ON c.program_of_study_id = pos.id -- <--- KESALAHAN SUDAH DIPERBAIKI DI SINI
        WHERE
            pos.institution_id = :institution_id
            AND sa.classified_temus_id IS NOT NULL
        GROUP BY
            pos.program_name
        HAVING
            AVG(sa.classified_temus_id) IS NOT NULL
        ORDER BY
            pos.program_name;
    ");
    $stmt_avg_temus->execute([':institution_id' => $institution_id]);
    while ($row = $stmt_avg_temus->fetch(PDO::FETCH_ASSOC)) {
        $avg_temus_data['labels'][] = $row['program_name'];
        $avg_temus_data['data'][] = round($row['avg_score'], 2);
    }


    // --- Ambil Distribusi Global Dimensi TEMUS untuk Institusi ---
    // Inisialisasi semua dimensi dengan hitungan nol
    $global_temus_distribution_counts = array_fill_keys($dimension_labels, 0);

    // Kumpulkan hitungan dari RPS Assessments
    $stmt_rps_counts = $pdo->prepare("
        SELECT td.dimension_name, COUNT(ra.id) as count
        FROM rps_assessments ra
        JOIN courses c ON ra.course_id = c.id
        JOIN programs_of_study pos ON c.program_of_study_id = pos.id -- <--- KESALAHAN SUDAH DIPERBAIKI DI SINI
        JOIN temus_dimensions td ON ra.classified_temus_id = td.id
        WHERE pos.institution_id = :institution_id AND ra.classified_temus_id IS NOT NULL
        GROUP BY td.dimension_name;
    ");
    $stmt_rps_counts->execute([':institution_id' => $institution_id]);
    while ($row = $stmt_rps_counts->fetch(PDO::FETCH_ASSOC)) {
        if (array_key_exists($row['dimension_name'], $global_temus_distribution_counts)) {
            $global_temus_distribution_counts[$row['dimension_name']] += (int) $row['count'];
        }
    }

    // Kumpulkan hitungan dari Self Assessments (Dosen dan Mahasiswa)
    $stmt_self_assess_counts = $pdo->prepare("
        SELECT td.dimension_name, COUNT(sa.id) as count
        FROM self_assessments_3m sa
        JOIN courses c ON sa.course_id = c.id
        JOIN programs_of_study pos ON c.program_of_study_id = pos.id -- <--- KESALAHAN SUDAH DIPERBAIKI DI SINI
        JOIN temus_dimensions td ON sa.classified_temus_id = td.id
        WHERE pos.institution_id = :institution_id AND sa.classified_temus_id IS NOT NULL
        GROUP BY td.dimension_name;
    ");
    $stmt_self_assess_counts->execute([':institution_id' => $institution_id]);
    while ($row = $stmt_self_assess_counts->fetch(PDO::FETCH_ASSOC)) {
        if (array_key_exists($row['dimension_name'], $global_temus_distribution_counts)) {
            $global_temus_distribution_counts[$row['dimension_name']] += (int) $row['count'];
        }
    }

    // Siapkan data untuk respons JSON, pastikan urutan sesuai $dimension_labels
    $global_distribution_labels = [];
    $global_distribution_data_values = [];
    foreach ($dimension_labels as $label) {
        $global_distribution_labels[] = $label;
        $global_distribution_data_values[] = $global_temus_distribution_counts[$label];
    }
    
    // Cek apakah ada data yang ditemukan (baik dari avg_temus maupun distribusi global)
    if (!empty($avg_temus_data['labels']) || array_sum($global_distribution_data_values) > 0) {
        $response['has_data'] = true;
    }

    $response['success'] = true;
    $response['avg_temus_data'] = $avg_temus_data;
    $response['global_temus_distribution_data'] = [
        'labels' => $global_distribution_labels,
        'data' => $global_distribution_data_values
    ];

} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log("API Error in get_institution_stats.php: " . $e->getMessage());
}

echo json_encode($response);
?>