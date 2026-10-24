<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect("localhost", "root", "maruto33", "billing_asa");

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
