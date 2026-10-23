<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect(BILLING_DB_HOST, BILLING_DB_USER, BILLING_DB_PASSWORD, BILLING_DB_NAME);
if (!$conn) {
    http_response_code(500);
    die('Koneksi database gagal: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
}

$queries = [
    'total_pelanggan' => 'SELECT COUNT(*) AS total FROM datapelangganasa',
    'total_tagihan' => 'SELECT COALESCE(SUM(nominal), 0) AS total FROM omset',
    'total_omset' => 'SELECT COALESCE(SUM(dibayar), 0) AS total FROM omset',
    'total_pengeluaran_tetap' => 'SELECT COALESCE(SUM(nominal), 0) AS total FROM pengeluaran_tetap',
    'total_pengeluaran_takterduga' => 'SELECT COALESCE(SUM(nominal), 0) AS total FROM pengeluaran_takterduga',
];

$totals = [];
foreach ($queries as $key => $query) {
    $result = mysqli_query($conn, $query);
    if (!$result) {
        http_response_code(500);
        die('Gagal mengambil data dashboard: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
    }
    $row = mysqli_fetch_assoc($result);
    $totals[$key] = (float) $row['total'];
    mysqli_free_result($result);
}

$total_pengeluaran = $totals['total_pengeluaran_tetap'] + $totals['total_pengeluaran_takterduga'];
$total_bersih = $totals['total_omset'] - $total_pengeluaran;
$sisa_tagihan = $totals['total_tagihan'] - $totals['total_omset'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ASA - Dashboard</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>

<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="dashboard.php">
                <div class="sidebar-brand-icon rotate-n-15"></div>
                <div class="sidebar-brand-text mx-3">ASA</div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item active">
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-fw fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="tables.php">
                    <i class="fas fa-fw fa-table"></i>
                    <span>Daftar Pelanggan</span>
                </a>
            </li>
            <hr class="sidebar-divider d-none d-md-block">
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseKeuangan"
                    aria-expanded="false" aria-controls="collapseKeuangan">
                    <i class="fas fa-fw fa-wallet"></i>
                    <span>Keuangan</span>
                </a>
                <div id="collapseKeuangan" class="collapse" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        <h6 class="collapse-header">Menu Keuangan:</h6>
                        <a class="collapse-item" href="omset.php">Omset</a>
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
                        <li class="nav-item">
                            <span class="nav-link"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></span>
                        </li>
                    </ul>
                </nav>

                <div class="container-fluid">
                    <div class="d-sm-flex align-items-center justify-content-between mb-4">
                        <h1 class="h3 mb-0 text-gray-800">Dashboard Ringkasan ASA</h1>
                    </div>

                    <div class="row">
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-primary shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Pelanggan</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($totals['total_pelanggan'], 0, ',', '.'); ?> Orang</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-success shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Omset</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format($totals['total_omset'], 0, ',', '.'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-danger shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Pengeluaran</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format($total_pengeluaran, 0, ',', '.'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-warning shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Sisa Tagihan</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format($sisa_tagihan, 0, ',', '.'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6 mb-4">
                            <div class="card border-left-info shadow h-100 py-2">
                                <div class="card-body">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Omset Bersih</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format($total_bersih, 0, ',', '.'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Ringkasan Pengeluaran</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-2">Pengeluaran Tetap <span class="float-right">Rp <?= number_format($totals['total_pengeluaran_tetap'], 0, ',', '.'); ?></span></p>
                                    <p class="mb-0">Pengeluaran Takterduga <span class="float-right">Rp <?= number_format($totals['total_pengeluaran_takterduga'], 0, ',', '.'); ?></span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">Akses Cepat</h6>
                                </div>
                                <div class="card-body">
                                    <a href="tables.php" class="btn btn-primary btn-sm mb-2">Daftar Pelanggan</a>
                                    <a href="omset.php" class="btn btn-success btn-sm mb-2">Data Omset</a>
                                    <a href="pengeluaran_tetap.php" class="btn btn-danger btn-sm mb-2">Pengeluaran Tetap</a>
                                    <a href="pengeluaran_takterduga.php" class="btn btn-warning btn-sm mb-2">Pengeluaran Takterduga</a>
                                </div>
                            </div>
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
