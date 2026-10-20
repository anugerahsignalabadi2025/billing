<?php
$conn = mysqli_connect("localhost", "root", "", "billing_asa");

if (!$conn) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit;
}

$type = $_GET['type'] ?? 'json';

$query = "SELECT * FROM omset";
$result = mysqli_query($conn, $query);

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = $row;
}

if ($type === 'excel') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=data_omset_" . date('Y-m-d') . ".xls");
    
    echo "ID\tNama\tNomor Telepon\tAlamat\tPaket\tNominal\tTanggal\n";
    foreach ($data as $item) {
        echo $item['id'] . "\t" . $item['nama'] . "\t" . $item['notlp'] . "\t" . $item['alamat'] . "\t" . ($item['paket'] ?? '-') . "\t" . $item['nominal'] . "\t" . $item['tanggal'] . "\n";
    }
    exit;
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