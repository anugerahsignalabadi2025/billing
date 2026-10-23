<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect(BILLING_DB_HOST, BILLING_DB_USER, BILLING_DB_PASSWORD, BILLING_DB_NAME);

if (isset($_POST["submit"])) {
    $nama = trim($_POST["nama"] ?? "");
    $notlp = trim($_POST["notlp"] ?? "");
    $nominal = trim($_POST["nominal"] ?? "");
    $tanggal = trim($_POST["tanggal"] ?? "");

    $stmt = mysqli_prepare($conn, "INSERT INTO omset (nama, notlp, nominal, tanggal) VALUES (NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''))");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ssss", $nama, $notlp, $nominal, $tanggal);
    }

    if ($stmt && mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        echo "
            <script>
                alert('Data omset berhasil ditambahkan!');
                document.location.href = 'omset.php';
            </script>
        ";
    } else {
        $error = $stmt ? mysqli_stmt_error($stmt) : mysqli_error($conn);
        if ($stmt) {
            mysqli_stmt_close($stmt);
        }
        echo "
            <script>
                alert(" . json_encode('Data omset gagal ditambahkan: ' . $error) . ");
                document.location.href = 'omset.php';
            </script>
        ";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ASA - Tambah Omset</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">

    <div id="wrapper">

        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php">
                <div class="sidebar-brand-text mx-3">ASA</div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="index.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="tables.php">
                    <i class="fas fa-fw fa-table"></i>
                    <span>Daftar Pelanggan</span></a>
            </li>
            <hr class="sidebar-divider d-none d-md-block">
            <li class="nav-item active">
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseKeuangan" aria-expanded="true" aria-controls="collapseKeuangan">
                    <i class="fas fa-fw fa-wallet"></i>
                    <span>Keuangan</span>
                </a>
                <div id="collapseKeuangan" class="collapse show" aria-labelledby="headingTwo" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Menu Keuangan:</h6>
                        <a class="collapse-item active" href="omset.php">Omset</a>
                        <a class="collapse-item" href="pengeluaran_tetap.php">Pengeluaran Tetap</a>
                        <a class="collapse-item" href="pengeluaran_takterduga.php">Pengeluaran Takterduga</a>
                    </div>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="odp.php">
                    <i class="fas fa-fw fa-sitemap"></i>
                    <span>ODP</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="acs.php">
                    <i class="fas fa-fw fa-server"></i>
                    <span>ACS</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="database.php">
                    <i class="fas fa-fw fa-database"></i>
                    <span>Database</span>
                </a>
            </li>
            <?php include __DIR__ . '/admin_menu.php'; ?>
        </ul>

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>
                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></span>
                                <img class="img-profile rounded-circle" src="img/undraw_profile.svg">
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="container-fluid">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Tambah Data Omset</h6>
                        </div>
                        <div class="card-body">
                            <form action="" method="post">
                                <div class="form-group">
                                    <label for="nama">Nama</label>
                                    <input type="text" class="form-control" name="nama" id="nama">
                                </div>
                                <div class="form-group">
                                    <label for="notlp">No. Telepon</label>
                                    <input type="text" class="form-control" name="notlp" id="notlp">
                                </div>
                                <div class="form-group">
                                    <label for="nominal">Nominal (Rp)</label>
                                    <input type="number" class="form-control" name="nominal" id="nominal">
                                </div>
                                <div class="form-group">
                                    <label for="tanggal">Tanggal</label>
                                    <input type="date" class="form-control" name="tanggal" id="tanggal">
                                </div>
                                <button type="submit" name="submit" class="btn btn-primary">Simpan</button>
                                <a href="omset.php" class="btn btn-secondary">Batal</a>
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