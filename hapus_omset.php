<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect(BILLING_DB_HOST, BILLING_DB_USER, BILLING_DB_PASSWORD, BILLING_DB_NAME);

$id = $_GET["id"];

$invoice = mysqli_query($conn, "SELECT customer_id FROM omset WHERE id = $id");
$invoiceData = mysqli_fetch_assoc($invoice);

if ($invoiceData && $invoiceData['customer_id'] !== null) {
    echo "
        <script>
            alert('Tagihan bulanan otomatis tidak dapat dihapus agar tidak dibuat ulang. Silakan ubah status atau nominal pembayarannya.');
            document.location.href = 'omset.php';
        </script>
    ";
    exit;
}

$query = "DELETE FROM omset WHERE id = $id";
mysqli_query($conn, $query);

if (mysqli_affected_rows($conn) > 0) {
    echo "
        <script>
            alert('Data omset berhasil dihapus!');
            document.location.href = 'omset.php';
        </script>
    ";
} else {
    echo "
        <script>
            alert('Data omset gagal dihapus!');
            document.location.href = 'omset.php';
        </script>
    ";
}
?>