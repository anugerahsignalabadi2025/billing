<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect(BILLING_DB_HOST, BILLING_DB_USER, BILLING_DB_PASSWORD, BILLING_DB_NAME);

if (!$conn) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit;
}

$type = $_GET['type'] ?? 'json';

$query = "SELECT * FROM datapelangganasa";
$result = mysqli_query($conn, $query);
if (!$result) {
    http_response_code(500);
    die("Gagal mengambil data pelanggan: " . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

if ($type === 'excel') {
    require_once __DIR__ . '/excel_export_helper.php';
    $rows = [];
    foreach ($data as $item) {
        $rows[] = [
            (int) $item['id'],
            $item['nama'],
            $item['notlp'],
            $item['alamat'],
            $item['paket'],
            $item['tanggal_pemasangan']
        ];
    }
    outputExcelWorkbook('data_pelanggan_' . date('Y-m-d') . '.xls', ['ID', 'Nama', 'Nomor Telepon', 'Alamat', 'Paket', 'Tanggal Pemasangan'], $rows);
} else {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "success",
        "total" => count($data),
        "data" => $data
    ], JSON_PRETTY_PRINT);
    exit;
}
?>