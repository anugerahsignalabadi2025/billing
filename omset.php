<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect("localhost", "root", "maruto33", "billing_asa");
if (!$conn) {
    http_response_code(500);
    die('Koneksi database gagal: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
}

$periodeAktif = date('Y-m');
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $dibayar = $_POST['dibayar'] ?? '';
    $metodeInput = $_POST['metode_pembayaran'] ?? '';
    $catatanInput = $_POST['catatan'] ?? '';
    $metadataValid = is_string($metodeInput) && is_string($catatanInput);
    $metodePembayaran = $metadataValid ? trim($metodeInput) : '';
    $catatan = $metadataValid ? trim($catatanInput) : '';
    $confirmation = $_POST['confirm_payment_update'] ?? '';

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '') || !$id || ($dibayar !== '' && (!is_numeric($dibayar) || (float) $dibayar < 0)) || !$metadataValid || strlen($metodePembayaran) > 100 || !in_array($confirmation, ['', 'yes'], true)) {
        $message = 'Data pembayaran tidak valid. Silakan muat ulang halaman dan coba lagi.';
        $messageType = 'danger';
    } else {
        $checkStmt = mysqli_prepare($conn, 'SELECT nominal, dibayar FROM omset WHERE id = ?');
        if (!$checkStmt) {
            http_response_code(500);
            die('Gagal memeriksa tagihan: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
        }
        $dibayar = $dibayar === '' ? 0.0 : (float) $dibayar;
        mysqli_stmt_bind_param($checkStmt, 'i', $id);
        if (!mysqli_stmt_execute($checkStmt)) {
            http_response_code(500);
            die('Gagal memeriksa tagihan: ' . htmlspecialchars(mysqli_stmt_error($checkStmt), ENT_QUOTES, 'UTF-8'));
        }
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $invoice = mysqli_fetch_assoc($checkResult);
        mysqli_stmt_close($checkStmt);

        if (!$invoice || $invoice['nominal'] === null || $dibayar > (float) $invoice['nominal']) {
            $message = 'Tagihan tidak ditemukan atau pembayaran melebihi nominal tagihan.';
            $messageType = 'danger';
        } elseif ($confirmation !== 'yes') {
            $previousPaid = (float) $invoice['dibayar'];
            if ($dibayar !== $previousPaid) {
                $csrfTokenForConfirm = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
                $dibayarForConfirm = htmlspecialchars((string) $dibayar, ENT_QUOTES, 'UTF-8');
                $metodeForConfirm = htmlspecialchars($metodePembayaran, ENT_QUOTES, 'UTF-8');
                $catatanForConfirm = htmlspecialchars($catatan, ENT_QUOTES, 'UTF-8');
                $idForConfirm = (int) $id;
                $previousForConfirm = number_format($previousPaid, 0, ',', '.');
                $newForConfirm = number_format($dibayar, 0, ',', '.');
                echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Konfirmasi Pembayaran</title><link href="css/sb-admin-2.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container py-5"><div class="card shadow mx-auto" style="max-width: 560px"><div class="card-header font-weight-bold text-warning">Konfirmasi perubahan pembayaran</div><div class="card-body"><p>Jumlah pembayaran akan berubah dari <strong>Rp ' . $previousForConfirm . '</strong> menjadi <strong>Rp ' . $newForConfirm . '</strong>.</p><p class="text-danger">Jika tersimpan sebagai Rp0, jumlah cicilan sebelumnya akan tertimpa. Pastikan nominal yang baru sudah benar.</p><form method="post" action="omset.php"><input type="hidden" name="action" value="update_payment"><input type="hidden" name="csrf_token" value="' . $csrfTokenForConfirm . '"><input type="hidden" name="id" value="' . $idForConfirm . '"><input type="hidden" name="dibayar" value="' . $dibayarForConfirm . '"><input type="hidden" name="metode_pembayaran" value="' . $metodeForConfirm . '"><input type="hidden" name="catatan" value="' . $catatanForConfirm . '"><input type="hidden" name="confirm_payment_update" value="yes"><button type="submit" class="btn btn-warning">Ya, ganti nominal</button> <a href="omset.php" class="btn btn-secondary">Batal</a></form></div></div></div></body></html>';
                exit;
            }
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE omset SET dibayar = ?, metode_pembayaran = NULLIF(?, ''), catatan = NULLIF(?, '') WHERE id = ?");
            if (!$stmt) {
                http_response_code(500);
                die('Gagal menyiapkan pembaruan pembayaran: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
            }
            mysqli_stmt_bind_param($stmt, 'dssi', $dibayar, $metodePembayaran, $catatan, $id);
            if (!mysqli_stmt_execute($stmt)) {
                http_response_code(500);
                die('Gagal menyimpan pembayaran: ' . htmlspecialchars(mysqli_stmt_error($stmt), ENT_QUOTES, 'UTF-8'));
            }
            mysqli_stmt_close($stmt);
            $message = 'Pembayaran berhasil diperbarui.';
        }
    }
}

$closedStmt = mysqli_prepare($conn, 'SELECT 1 FROM periode_bulanan WHERE periode = ?');
if (!$closedStmt) {
    http_response_code(500);
    die('Gagal memeriksa periode tagihan: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param($closedStmt, 's', $periodeAktif);
mysqli_stmt_execute($closedStmt);
$closedResult = mysqli_stmt_get_result($closedStmt);
$periodeDitutup = mysqli_fetch_assoc($closedResult) !== null;
mysqli_stmt_close($closedStmt);

$result = mysqli_query($conn, 'SELECT * FROM omset ORDER BY tanggal DESC, id DESC');
if (!$result) {
    http_response_code(500);
    die('Gagal mengambil data omset: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
$totalResult = mysqli_query($conn, 'SELECT COALESCE(SUM(nominal), 0) AS total_nominal, COALESCE(SUM(dibayar), 0) AS total_dibayar FROM omset');
if (!$totalResult) {
    http_response_code(500);
    die('Gagal menghitung total omset: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
$totalOmset = mysqli_fetch_assoc($totalResult);
$sisaTagihan = (float) $totalOmset['total_nominal'] - (float) $totalOmset['total_dibayar'];

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>ASA - Data Omset</title>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">

</head>

<body id="page-top">

    <div id="wrapper">

        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php">
                <div class="sidebar-brand-icon rotate-n-15">
                </div>
                <div class="sidebar-brand-text mx-3">ASA <sup></sup></div>
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
                <a class="nav-link" href="#" data-toggle="collapse" data-target="#collapseKeuangan"
                    aria-expanded="true" aria-controls="collapseKeuangan">
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
                    <form class="form-inline">
                        <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                            <i class="fa fa-bars"></i>
                        </button>
                    </form>

                    <ul class="navbar-nav ml-auto">
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></span>
                                <img class="img-profile rounded-circle" src="img/undraw_profile.svg">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in"
                                aria-labelledby="userDropdown">
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                                    Logout
                                </a>
                            </div>
                        </li>
                    </ul>
                </nav>

                <div class="container-fluid">

                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Data Omset</h6>
                            <div>
                                <a href="export_omset.php?type=excel" class="btn btn-success btn-sm mr-1">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </a>
                                <a href="export_omset.php?type=json" target="_blank" class="btn btn-info btn-sm mr-1">
                                    <i class="fas fa-code"></i> API / JSON
                                </a>
                                <?php if (billing_can('create')): ?><a href="tambah_omset.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-plus"></i> Tambah Omset Manual
                                </a><?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning" role="alert">Pembuatan tagihan bulanan otomatis dinonaktifkan. Tagihan baru harus ditambahkan manual, dan tagihan yang dihapus tidak akan dibuat ulang.</div>
                            <?php if ($message !== ''): ?>
                                <div class="alert alert-<?= $messageType; ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php if ($periodeDitutup): ?>
                                <div class="alert alert-info" role="alert">Periode <?= htmlspecialchars($periodeAktif, ENT_QUOTES, 'UTF-8'); ?> sudah ditutup. Tunggakan yang masih tercatat tetap dapat dibayar.</div>
                            <?php endif; ?>
                            <p class="text-muted">Tambahkan tagihan secara manual melalui tombol Tambah Omset. Tagihan belum lunas tetap tersimpan setelah periode ditutup.</p>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="card border-left-success shadow-sm h-100">
                                        <div class="card-body">
                                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Tagihan</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format((float) $totalOmset['total_nominal'], 0, ',', '.'); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card border-left-primary shadow-sm h-100">
                                        <div class="card-body">
                                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Sudah Dibayar</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format((float) $totalOmset['total_dibayar'], 0, ',', '.'); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="card border-left-danger shadow-sm h-100">
                                        <div class="card-body">
                                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Sisa Tagihan</div>
                                            <div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format($sisaTagihan, 0, ',', '.'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" data-sequential-number="true" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>No.</th>
                                            <th>Nama</th>
                                            <th>No. Telepon</th>
                                            <th>Nominal</th>
                                            <th>Dibayar</th>
                                            <th>Status</th>
                                            <th>Jatuh Tempo</th>
                                            <th>Metode</th>
                                            <th>Catatan</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($row = mysqli_fetch_assoc($result)): ?>
                                        <?php
                                            $nominalTagihan = (float) $row['nominal'];
                                            $jumlahDibayar = (float) $row['dibayar'];
                                            $statusPembayaran = $row['nominal'] === null
                                                ? 'Nominal belum diisi'
                                                : ($jumlahDibayar >= $nominalTagihan ? 'Lunas' : ($jumlahDibayar > 0 ? 'Cicil' : 'Belum lunas'));
                                            $warnaBaris = $statusPembayaran === 'Belum lunas'
                                                ? 'table-danger'
                                                : (in_array($statusPembayaran, ['Cicil', 'Nominal belum diisi'], true) ? 'table-warning' : '');
                                        ?>
                                        <tr class="<?= $warnaBaris; ?>">
                                            <td data-order="<?= (int) $row['id']; ?>">0</td>
                                            <td><?= htmlspecialchars($row["nama"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= htmlspecialchars($row["notlp"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= $row['nominal'] === null ? '' : 'Rp ' . number_format($nominalTagihan, 0, ',', '.'); ?></td>
                                            <td>Rp <?= number_format($jumlahDibayar, 0, ',', '.'); ?></td>
                                            <td><span class="badge badge-<?= $statusPembayaran === 'Lunas' ? 'success' : (in_array($statusPembayaran, ['Cicil', 'Nominal belum diisi'], true) ? 'warning' : 'danger'); ?>"><?= $statusPembayaran; ?></span></td>
                                            <td><?= htmlspecialchars($row["tanggal"] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= htmlspecialchars($row['metode_pembayaran'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= nl2br(htmlspecialchars($row['catatan'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td>
                                            <td>
                                                <?php if (billing_can('update')): ?><form method="post" action="omset.php" class="mb-2">
                                                    <input type="hidden" name="action" value="update_payment">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                                    <div class="input-group input-group-sm">
                                                        <input type="number" name="dibayar" class="form-control" min="0" max="<?= $nominalTagihan; ?>" step="0.01" value="<?= $jumlahDibayar; ?>" aria-label="Jumlah yang sudah dibayar">
                                                        <div class="input-group-append">
                                                            <button type="submit" class="btn btn-primary">Simpan</button>
                                                        </div>
                                                    </div>
                                                    <input type="text" name="metode_pembayaran" class="form-control form-control-sm mt-1" maxlength="100" list="metodePembayaranList" value="<?= htmlspecialchars($row['metode_pembayaran'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Metode pembayaran">
                                                    <textarea name="catatan" class="form-control form-control-sm mt-1" rows="2" placeholder="Catatan"><?= htmlspecialchars($row['catatan'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                </form>
                                                <?php endif; ?>
                                                <?php if (billing_can('update')): ?><a href="ubah_omset.php?id=<?= $row["id"]; ?>" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a><?php endif; ?>
                                                <?php if (billing_can('delete')): ?><a href="hapus_omset.php?id=<?= $row["id"]; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Yakin ingin menghapus data omset ini?');">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </a><?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <datalist id="metodePembayaranList">
                            <option value="Tunai">
                            <option value="Transfer Bank">
                            <option value="QRIS">
                            <option value="E-Wallet">
                            <option value="Flazz (PT Bank Central Asia Tbk / BCA)">
                            <option value="Mandiri e-Money (PT Bank Mandiri (Persero) Tbk)">
                            <option value="BRIZZI (PT Bank Rakyat Indonesia (Persero) Tbk)">
                            <option value="TapCash (PT Bank Negara Indonesia (Persero) Tbk)">
                            <option value="JakCard (PT Bank DKI)">
                            <option value="Simas E-money (PT Bank Sinarmas)">
                            <option value="CommuterPay (PT Kereta Commuter Indonesia)">
                            <option value="GoPay (PT Dompet Anak Bangsa)">
                            <option value="OVO / OVO Cash (PT Visionet Internasional)">
                            <option value="DANA (PT Espay Debit Indonesia Koe)">
                            <option value="ShopeePay (PT Airpay International Indonesia)">
                            <option value="LinkAja (PT Fintek Karya Nusantara)">
                            <option value="Sakuku (PT Bank Central Asia Tbk)">
                            <option value="iSaku (PT Inti Dunia Sukses)">
                            <option value="KasPro (PT Solusi Pasti Indonesia)">
                            <option value="DOKU / DOKU Wallet (PT Nusa Satu Inti Artha)">
                            <option value="Speed Cash (PT Bimasakti Multi Sinergi)">
                            <option value="OttoCash (PT Transaksi Artha Gemilang)">
                            <option value="Bluepay (PT Bluepay Digital Internasional)">
                            <option value="Ezeelink (PT Ezeelink Indonesia)">
                            <option value="M-Bayar (PT E2Pay Global Utama)">
                            <option value="DUWIT (PT Cakra Ultima Sejahtera)">
                        </datalist>
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

    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>

    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Ready to Leave?</h5>
                    <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">Select "Logout" below if you are ready to end your current session.</div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                    <a class="btn btn-primary" href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <script src="js/sb-admin-2.min.js"></script>

    <script src="vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>

    <script src="js/demo/datatables-demo.js?v=3"></script>

</body>

</html>
