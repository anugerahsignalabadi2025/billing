<?php
// Konfigurasi Database
require_once __DIR__ . '/admin_helpers.php';

try {
    $host = BILLING_DB_HOST;
    $dbname = BILLING_DB_NAME;
    $username = BILLING_DB_USER;
    $password = BILLING_DB_PASSWORD;

    // Membuat koneksi menggunakan PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    // Set mode error PDO ke Exception agar mudah mendeteksi kesalahan
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set default fetch mode ke associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // echo "Koneksi berhasil!"; // Uncomment baris ini untuk menguji koneksi
} catch (PDOException $e) {
    // Tangkap error jika koneksi gagal
    die("Koneksi database gagal: " . $e->getMessage());
}
?>