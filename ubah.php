<?php
$conn = mysqli_connect("localhost", "root", "", "billing_asa");

if (!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

$id = $_GET["id"] ?? "";

if ($id === "") {
    header("Location: tables.php");
    exit;
}

$result = mysqli_query($conn, "SELECT * FROM datapelangganasa WHERE id = $id");
$data = mysqli_fetch_assoc($result);

if (!$data) {
    echo "
        <script>
            alert('Data tidak ditemukan!');
            document.location.href = 'tables.php';
        </script>
    ";
    exit;
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nama = trim($_POST["nama"] ?? "");
    $notlp = trim($_POST["notlp"] ?? "");
    $alamat = trim($_POST["alamat"] ?? "");
    $paket = trim($_POST["paket"] ?? "");

    if ($nama === "" || $notlp === "" || $alamat === "" || $paket === "") {
        $message = "Semua field harus diisi.";
    } else {
        $query = "UPDATE datapelangganasa SET nama = ?, notlp = ?, alamat = ?, paket = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssssi", $nama, $notlp, $alamat, $paket, $id);

            if (mysqli_stmt_execute($stmt)) {
                echo "
                    <script>
                        alert('Data pelanggan berhasil diperbarui!');
                        document.location.href = 'tables.php';
                    </script>
                ";
                exit;
            } else {
                $message = "Data gagal diperbarui: " . mysqli_error($conn);
            }

            mysqli_stmt_close($stmt);
        } else {
            $message = "Query gagal dipersiapkan: " . mysqli_error($conn);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>ASA - Ubah Pelanggan</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php">
                <div class="sidebar-brand-icon rotate-n-15"></div>
                <div class="sidebar-brand-text mx-3">ASA</div>
            </a>

            <hr class="sidebar-divider my-0">

            <li class="nav-item">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item active">
                <a class="nav-link" href="tables.php">
                    <i class="fas fa-fw fa-table"></i>
                    <span>Daftar Pelanggan</span>
                </a>
            </li>
        </ul>

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small">admin</span>
                                <img class="img-profile rounded-circle" src="img/undraw_profile.svg">
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="container-fluid">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Edit Data Pelanggan</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($message !== ""): ?>
                                <div class="alert alert-danger" role="alert"><?php echo $message; ?></div>
                            <?php endif; ?>

                            <form method="POST" action="">
                                <div class="form-group">
                                    <label for="nama">Nama</label>
                                    <input type="text" class="form-control" id="nama" name="nama" value="<?php echo htmlspecialchars($data['nama']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="notlp">Nomor Telepon</label>
                                    <input type="text" class="form-control" id="notlp" name="notlp" value="<?php echo htmlspecialchars($data['notlp']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="alamat">Alamat</label>
                                    <textarea class="form-control" id="alamat" name="alamat" rows="4" required><?php echo htmlspecialchars($data['alamat']); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="paket">Paket</label>
                                    <input type="text" class="form-control" id="paket" name="paket" value="<?php echo htmlspecialchars($data['paket']); ?>" required>
                                </div>

                                <button type="submit" class="btn btn-primary">Update</button>
                                <a href="tables.php" class="btn btn-secondary">Batal</a>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="sticky-footer bg-white">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Anugerah Signal Abadi 2026</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>
    <script src="js/sb-admin-2.min.js"></script>
</body>

</html>