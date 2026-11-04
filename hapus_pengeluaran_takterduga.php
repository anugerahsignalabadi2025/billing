<?php
require_once __DIR__ . '/auth.php';

$conn = billing_db_connect();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    die('Data pengeluaran tidak valid.');
}

$stmt = mysqli_prepare($conn, 'DELETE FROM pengeluaran_takterduga WHERE id = ?' . billing_data_scope_sql());
if (!$stmt) {
    http_response_code(500);
    die('Gagal menyiapkan penghapusan: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param($stmt, 'i', $id);
if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    http_response_code(500);
    die('Gagal menghapus pengeluaran: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}
$deleted = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($deleted !== 1) {
    http_response_code(404);
    die('Data pengeluaran tidak ditemukan atau bukan milik akun ini.');
}

header('Location: pengeluaran_takterduga.php');
exit;
