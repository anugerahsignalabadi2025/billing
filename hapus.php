<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect(BILLING_DB_HOST, BILLING_DB_USER, BILLING_DB_PASSWORD, BILLING_DB_NAME);

$id = $_GET["id"];

mysqli_query($conn, "DELETE FROM datapelangganasa WHERE id = $id");

if (mysqli_affected_rows($conn) > 0) {
    echo "
        <script>
            alert('Data berhasil dihapus!');
            document.location.href = 'tables.php';
        </script>
    ";
} else {
    echo "
        <script>
            alert('Data gagal dihapus!');
            document.location.href = 'tables.php';
        </script>
    ";
}
?>