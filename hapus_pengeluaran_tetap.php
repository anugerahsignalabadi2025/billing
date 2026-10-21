<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect("localhost", "root", "", "billing_asa");

$id = $_GET["id"];

$query = "DELETE FROM pengeluaran_tetap WHERE id = $id";
mysqli_query($conn, $query);

if (mysqli_affected_rows($conn) > 0) {
    echo "
        <script>
            alert('Data pengeluaran tetap berhasil dihapus!');
            document.location.href = 'pengeluaran_tetap.php';
        </script>
    ";
} else {
    echo "
        <script>
            alert('Data pengeluaran tetap gagal dihapus!');
            document.location.href = 'pengeluaran_tetap.php';
        </script>
    ";
}
?>