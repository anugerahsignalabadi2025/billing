<?php
require_once __DIR__ . '/auth.php';

if (empty($_SESSION['is_superadmin'])) {
    http_response_code(403);
    die('Fitur ini hanya dapat digunakan oleh admin utama.');
}

$entity = $_POST['entity'] ?? '';
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sourceOwnerId = filter_input(INPUT_POST, 'source_owner_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$csrfToken = $_SESSION['csrf_token'] ?? '';
$destinations = [
    'customer' => ['table' => 'datapelangganasa', 'return' => 'tables.php'],
    'omset' => ['table' => 'omset', 'return' => 'omset.php'],
    'fixed_expense' => ['table' => 'pengeluaran_tetap', 'return' => 'pengeluaran_tetap.php'],
    'unexpected_expense' => ['table' => 'pengeluaran_takterduga', 'return' => 'pengeluaran_takterduga.php'],
];

if (!isset($destinations[$entity]) || !$id || !$sourceOwnerId ||
    !hash_equals($csrfToken, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
    http_response_code(400);
    die('Permintaan salin data tidak valid.');
}

$copySql = [
    'customer' => "INSERT INTO datapelangganasa (nama, notlp, ip, alamat, paket, tanggal_pemasangan, owner_admin_id)
        SELECT nama, notlp, ip, alamat, paket, tanggal_pemasangan, NULL
        FROM datapelangganasa WHERE id = ? AND owner_admin_id = ?",
    'omset' => "INSERT INTO omset (customer_id, nama, notlp, alamat, nominal, dibayar, tanggal, paket, periode, metode_pembayaran, catatan, owner_admin_id)
        SELECT customer_id, nama, notlp, alamat, nominal, dibayar, tanggal, paket, periode, metode_pembayaran, catatan, NULL
        FROM omset WHERE id = ? AND owner_admin_id = ?",
    'fixed_expense' => "INSERT INTO pengeluaran_tetap (nama_pengeluaran, keterangan, nominal, tanggal, owner_admin_id)
        SELECT nama_pengeluaran, keterangan, nominal, tanggal, NULL
        FROM pengeluaran_tetap WHERE id = ? AND owner_admin_id = ?",
    'unexpected_expense' => "INSERT INTO pengeluaran_takterduga (nama_pengeluaran, keterangan, nominal, tanggal, owner_admin_id)
        SELECT nama_pengeluaran, keterangan, nominal, tanggal, NULL
        FROM pengeluaran_takterduga WHERE id = ? AND owner_admin_id = ?",
];

$conn = billing_db_connect();
if ($entity === 'omset') {
    billing_prepare_payment_records($conn);
}
$stmt = mysqli_prepare($conn, $copySql[$entity]);
if (!$stmt) {
    http_response_code(500);
    die('Gagal menyiapkan salinan data: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param($stmt, 'ii', $id, $sourceOwnerId);
if ($entity === 'omset' && !mysqli_begin_transaction($conn)) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    die('Gagal memulai penyalinan data omset: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
if (!mysqli_stmt_execute($stmt)) {
    $error = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    if ($entity === 'omset') {
        mysqli_rollback($conn);
    }
    http_response_code(500);
    die('Gagal menyalin data: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}
$copied = mysqli_stmt_affected_rows($stmt);
$copiedOmsetId = $entity === 'omset' ? mysqli_stmt_insert_id($stmt) : 0;
mysqli_stmt_close($stmt);
if ($entity === 'omset' && $copied === 1) {
    $paymentCopyStmt = mysqli_prepare($conn, 'INSERT INTO omset_pembayaran (omset_id, nominal, metode_pembayaran, tanggal)
        SELECT ?, nominal, metode_pembayaran, tanggal FROM omset_pembayaran WHERE omset_id = ?');
    if (!$paymentCopyStmt) {
        $error = mysqli_error($conn);
        mysqli_rollback($conn);
        http_response_code(500);
        die('Gagal menyiapkan salinan rincian pembayaran: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($paymentCopyStmt, 'ii', $copiedOmsetId, $id);
    if (!mysqli_stmt_execute($paymentCopyStmt)) {
        $error = mysqli_stmt_error($paymentCopyStmt);
        mysqli_stmt_close($paymentCopyStmt);
        mysqli_rollback($conn);
        http_response_code(500);
        die('Gagal menyalin rincian pembayaran: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_close($paymentCopyStmt);
    if (!mysqli_commit($conn)) {
        mysqli_rollback($conn);
        http_response_code(500);
        die('Gagal menyelesaikan penyalinan data omset: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
    }
} elseif ($entity === 'omset') {
    mysqli_rollback($conn);
}
mysqli_close($conn);

if ($copied !== 1) {
    http_response_code(404);
    die('Data sumber tidak ditemukan atau bukan milik user yang dipilih.');
}

unset($_SESSION['acting_as_admin_id'], $_SESSION['acting_as_admin_username']);
header('Location: ' . $destinations[$entity]['return'] . '?copied=1');
exit;
