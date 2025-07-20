<?php
session_start(); // Ini harus baris pertama kode PHP, tanpa spasi/karakter lain di depannya.
session_destroy(); // Menghapus semua data sesi pengguna
header('Location: index.php'); // Mengarahkan pengguna kembali ke halaman utama
exit(); // Menghentikan eksekusi script setelah redirect. Ini sangat penting!
?>