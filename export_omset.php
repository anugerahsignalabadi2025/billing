<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect("localhost", "root", "maruto33", "billing_asa");

if (!$conn) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit;
}

$type = $_GET['type'] ?? 'json';

$query = "SELECT * FROM omset";
$result = mysqli_query($conn, $query);
if (!$result) {
    http_response_code(500);
    die("Gagal mengambil data omset: " . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

if ($type === 'excel') {
    require_once __DIR__ . '/excel_export_helper.php';
    $rows = [];
    foreach ($data as $item) {
        $dibayar = (float) ($item['dibayar'] ?? 0);
        $nominal = $item['nominal'] === null ? null : (float) $item['nominal'];
        $status = $nominal === null
            ? 'Nominal belum diisi'
            : ($dibayar >= $nominal ? 'Lunas' : ($dibayar > 0 ? 'Cicil' : 'Belum lunas'));
        $rows[] = [
            (int) $item['id'],
            $item['nama'],
            $item['notlp'],
            $nominal,
            $dibayar,
            $status,
            $item['tanggal'],
            $item['metode_pembayaran'],
            $item['catatan']
        ];
    }
    outputExcelWorkbook('data_omset_' . date('Y-m-d') . '.xls', ['ID', 'Nama', 'Nomor Telepon', 'Tagihan', 'Dibayar', 'Status', 'Jatuh Tempo', 'Metode Pembayaran', 'Catatan'], $rows);
} else {
    foreach ($data as &$item) {
        $dibayar = (float) ($item['dibayar'] ?? 0);
        $nominal = $item['nominal'] === null ? null : (float) $item['nominal'];
        $item['status_pembayaran'] = $nominal === null
            ? 'Nominal belum diisi'
            : ($dibayar >= $nominal ? 'Lunas' : ($dibayar > 0 ? 'Cicil' : 'Belum lunas'));
        $item['sisa_tagihan'] = $nominal === null ? null : max(0, $nominal - $dibayar);
    }
    unset($item);

    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "success",
        "total" => count($data),
        "data" => $data
    ], JSON_PRETTY_PRINT);
    exit;
}
?>
