<?php
// Konfigurasi Skala Penilaian Digitalisasi Pembelajaran
define('DIGITALIZATION_SCALE_VALUES', [0, 0.25, 0.50, 0.75, 1]);
define('DIGITALIZATION_MIN_SCORE', 0);
define('DIGITALIZATION_MAX_SCORE', 1);

// Anda bisa menambahkan label jika di masa depan diperlukan di tampilan
define('DIGITALIZATION_SCALE_LABELS', [
    0    => 'Tidak Pernah', // Contoh label, Anda bisa sesuaikan
    0.25 => 'Jarang',
    0.50 => 'Sesuai Kebutuhan',
    0.75 => 'Sering',
    1    => 'Sangat Sering'
]);
?>