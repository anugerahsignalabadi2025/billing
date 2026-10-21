<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect("localhost", "root", "", "billing_asa");

$id = $_GET["id"];

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