<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect("localhost", "root", "", "billing_asa");

if (!$conn) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit;
}

$type = $_GET['type'] ?? 'json';
$result = mysqli_query($conn, "SELECT * FROM pengeluaran_tetap");
if (!$result) {
    http_response_code(500);
    die("Gagal mengambil data pengeluaran tetap: " . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
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
            $item['nama_pengeluaran'],
            $item['keterangan'],
            $item['nominal'] === null ? null : (float) $item['nominal'],
            $item['tanggal']
        ];
    }
    outputExcelWorkbook('data_pengeluaran_tetap_' . date('Y-m-d') . '.xls', ['ID', 'Nama Pengeluaran', 'Keterangan', 'Nominal', 'Tanggal'], $rows);
}

header("Content-Type: application/json; charset=utf-8");
echo json_encode([
    "status" => "success",
    "total" => count($data),
    "data" => $data
], JSON_PRETTY_PRINT);
