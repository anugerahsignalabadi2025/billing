<?php
require_once __DIR__ . '/auth.php';

$conn = mysqli_connect('localhost', 'root', 'maruto33', 'billing_asa');
if (!$conn) {
    http_response_code(500);
    die('Koneksi database gagal: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
}

$periodeAktif = date('Y-m');
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_month') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '') || ($_POST['periode'] ?? '') !== $periodeAktif) {
        $message = 'Permintaan tutup bulan tidak valid. Muat ulang halaman dan coba lagi.';
        $messageType = 'danger';
    } else {
        try {
            mysqli_begin_transaction($conn);

            $closeStmt = mysqli_prepare($conn, 'INSERT INTO periode_bulanan (periode, ditutup_oleh) VALUES (?, ?)');
            if (!$closeStmt) {
                throw new RuntimeException('Gagal menyiapkan penutupan periode.');
            }
            $username = $_SESSION['username'] ?? 'admin';
            mysqli_stmt_bind_param($closeStmt, 'ss', $periodeAktif, $username);
            if (!mysqli_stmt_execute($closeStmt)) {
                throw new RuntimeException('Periode ini sudah ditutup atau gagal disimpan.');
            }
            mysqli_stmt_close($closeStmt);

            $revenueArchiveStmt = mysqli_prepare($conn, "INSERT INTO arsip_bulanan (periode_arsip, jenis_data, data_id, pelanggan_id, periode_tagihan, nama, notlp, alamat, paket, nominal, dibayar, tanggal)
                SELECT ?, 'omset', id, customer_id, periode, nama, notlp, alamat, paket, nominal, dibayar, tanggal
                FROM omset
                WHERE periode = ? OR (periode IS NULL AND DATE_FORMAT(tanggal, '%Y-%m') = ?)");
            if (!$revenueArchiveStmt) {
                throw new RuntimeException('Gagal menyiapkan arsip omset.');
            }
            mysqli_stmt_bind_param($revenueArchiveStmt, 'sss', $periodeAktif, $periodeAktif, $periodeAktif);
            if (!mysqli_stmt_execute($revenueArchiveStmt)) {
                throw new RuntimeException('Gagal menyimpan arsip omset.');
            }
            mysqli_stmt_close($revenueArchiveStmt);

            $archiveQueries = [
                "INSERT INTO arsip_bulanan (periode_arsip, jenis_data, data_id, nama_pengeluaran, keterangan, nominal, tanggal)
                 SELECT ?, 'pengeluaran_tetap', id, nama_pengeluaran, keterangan, nominal, tanggal FROM pengeluaran_tetap",
                "INSERT INTO arsip_bulanan (periode_arsip, jenis_data, data_id, nama_pengeluaran, keterangan, nominal, tanggal)
                 SELECT ?, 'pengeluaran_takterduga', id, nama_pengeluaran, keterangan, nominal, tanggal FROM pengeluaran_takterduga",
            ];
            foreach ($archiveQueries as $query) {
                $archiveStmt = mysqli_prepare($conn, $query);
                if (!$archiveStmt) {
                    throw new RuntimeException('Gagal menyiapkan arsip bulanan.');
                }
                mysqli_stmt_bind_param($archiveStmt, 's', $periodeAktif);
                if (!mysqli_stmt_execute($archiveStmt)) {
                    throw new RuntimeException('Gagal menyimpan arsip bulanan.');
                }
                mysqli_stmt_close($archiveStmt);
            }

            $deletePaidStmt = mysqli_prepare($conn, "DELETE FROM omset
                WHERE (periode = ? OR (periode IS NULL AND DATE_FORMAT(tanggal, '%Y-%m') = ?))
                AND nominal IS NOT NULL AND dibayar >= nominal");
            if (!$deletePaidStmt) {
                throw new RuntimeException('Gagal menyiapkan pembersihan tagihan lunas.');
            }
            mysqli_stmt_bind_param($deletePaidStmt, 'ss', $periodeAktif, $periodeAktif);
            if (!mysqli_stmt_execute($deletePaidStmt)) {
                throw new RuntimeException('Gagal menghapus tagihan lunas periode ini.');
            }
            mysqli_stmt_close($deletePaidStmt);

            foreach (['pengeluaran_tetap', 'pengeluaran_takterduga'] as $table) {
                if (!mysqli_query($conn, "DELETE FROM `$table`")) {
                    throw new RuntimeException('Gagal mengosongkan data operasional bulan ini.');
                }
            }

            mysqli_commit($conn);
            $message = "Periode $periodeAktif berhasil diarsipkan. Pengeluaran dan tagihan yang sudah lunas dikosongkan; tagihan belum lunas atau cicilan tetap tersimpan di Omset agar dapat ditagih dan dibayar pada periode berikutnya.";
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Penutupan bulan gagal. Tidak ada data yang dihapus; periksa koneksi dan struktur database.';
            $messageType = 'danger';
        }
    }
}

$closedStmt = mysqli_prepare($conn, 'SELECT ditutup_pada, ditutup_oleh FROM periode_bulanan WHERE periode = ?');
if (!$closedStmt) {
    http_response_code(500);
    die('Gagal memeriksa periode: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
mysqli_stmt_bind_param($closedStmt, 's', $periodeAktif);
mysqli_stmt_execute($closedStmt);
$closedResult = mysqli_stmt_get_result($closedStmt);
$closedPeriod = mysqli_fetch_assoc($closedResult);
mysqli_stmt_close($closedStmt);

$archiveResult = mysqli_query($conn, 'SELECT * FROM arsip_bulanan ORDER BY periode_arsip DESC, diarsipkan_pada DESC, id DESC');
if (!$archiveResult) {
    http_response_code(500);
    die('Gagal mengambil arsip bulanan: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
$archiveTotalsResult = mysqli_query($conn, "SELECT COALESCE(SUM(nominal), 0) AS nominal, COALESCE(SUM(dibayar), 0) AS dibayar FROM arsip_bulanan WHERE jenis_data = 'omset'");
if (!$archiveTotalsResult) {
    http_response_code(500);
    die('Gagal menghitung arsip omset: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
$archiveTotals = mysqli_fetch_assoc($archiveTotalsResult);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ASA - Database Arsip Bulanan</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
</head>

<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="dashboard.php">
                <div class="sidebar-brand-icon rotate-n-15"></div>
                <div class="sidebar-brand-text mx-3">ASA</div>
            </a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item">
                <a class="nav-link" href="dashboard.php"><i class="fas fa-fw fa-tachometer-alt"></i><span>Dashboard</span></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="tables.php"><i class="fas fa-fw fa-table"></i><span>Daftar Pelanggan</span></a>
            </li>
            <hr class="sidebar-divider d-none d-md-block">
            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseKeuangan" aria-expanded="false" aria-controls="collapseKeuangan">
                    <i class="fas fa-fw fa-wallet"></i><span>Keuangan</span>
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
            <li class="nav-item"><a class="nav-link" href="odp.php"><i class="fas fa-fw fa-sitemap"></i><span>ODP</span></a></li>
            <li class="nav-item"><a class="nav-link" href="acs.php"><i class="fas fa-fw fa-server"></i><span>ACS</span></a></li>
            <li class="nav-item active"><a class="nav-link" href="database.php"><i class="fas fa-fw fa-database"></i><span>Database</span></a></li>
            <?php include __DIR__ . '/admin_menu.php'; ?>
        </ul>

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3"><i class="fa fa-bars"></i></button>
                    <ul class="navbar-nav ml-auto"><li class="nav-item"><span class="nav-link"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></span></li></ul>
                </nav>

                <div class="container-fluid">
                    <h1 class="h3 mb-4 text-gray-800">Database Arsip Bulanan</h1>
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?= $messageType; ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Tutup Periode <?= htmlspecialchars($periodeAktif, ENT_QUOTES, 'UTF-8'); ?></h6></div>
                        <div class="card-body">
                            <?php if ($closedPeriod): ?>
                                <div class="alert alert-success mb-0">Periode ini sudah diarsipkan pada <?= htmlspecialchars($closedPeriod['ditutup_pada'], ENT_QUOTES, 'UTF-8'); ?>. Data pelanggan tetap disimpan; tagihan bulan ini tidak dibuat kembali.</div>
                            <?php elseif (billing_can('update')): ?>
                                <p>Tagihan periode ini dan seluruh pengeluaran aktif akan disalin ke arsip. Pengeluaran dan tagihan yang sudah lunas dikosongkan, sedangkan tagihan belum lunas atau masih dicicil tetap tersimpan di Omset agar bisa dibayar setelah tutup periode. Tunggakan diberi warna merah dan cicilan warna kuning.</p>
                                <form method="post" onsubmit="return confirm('Arsipkan dan kosongkan semua omset serta pengeluaran aktif untuk tutup bulan? Data pelanggan tidak dihapus.');">
                                    <input type="hidden" name="action" value="close_month">
                                    <input type="hidden" name="periode" value="<?= htmlspecialchars($periodeAktif, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-danger"><i class="fas fa-archive"></i> Arsipkan dan Tutup Bulan</button>
                                </form>
                            <?php else: ?>
                                <p class="mb-0">Periode belum ditutup. Anda tidak memiliki izin UPDATE untuk menutup periode.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6 mb-3"><div class="card border-left-success shadow h-100"><div class="card-body"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Tagihan Terarsip</div><div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format((float) $archiveTotals['nominal'], 0, ',', '.'); ?></div></div></div></div>
                        <div class="col-md-6 mb-3"><div class="card border-left-primary shadow h-100"><div class="card-body"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Pembayaran Terarsip</div><div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format((float) $archiveTotals['dibayar'], 0, ',', '.'); ?></div></div></div></div>
                    </div>

                    <div class="card shadow mb-4">
                        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Riwayat Arsip</h6></div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" id="dataTable" data-sequential-number="true" width="100%" cellspacing="0">
                                    <thead><tr><th>No.</th><th>Periode Arsip</th><th>Jenis</th><th>Nama / Rincian</th><th>No. Telepon</th><th>Tagihan</th><th>Dibayar</th><th>Status</th><th>Tanggal</th><th>Diarsipkan</th></tr></thead>
                                    <tbody>
                                        <?php while ($row = mysqli_fetch_assoc($archiveResult)): ?>
                                            <?php
                                                $statusArsip = '-';
                                                $warnaBaris = '';
                                                if ($row['jenis_data'] === 'omset') {
                                                    $nominalArsip = $row['nominal'] === null ? null : (float) $row['nominal'];
                                                    $dibayarArsip = (float) ($row['dibayar'] ?? 0);
                                                    $statusArsip = $nominalArsip === null
                                                        ? 'Nominal belum diisi'
                                                        : ($dibayarArsip >= $nominalArsip ? 'Lunas' : ($dibayarArsip > 0 ? 'Cicil' : 'Belum lunas'));
                                                    $warnaBaris = $statusArsip === 'Belum lunas'
                                                        ? 'table-danger'
                                                        : (in_array($statusArsip, ['Cicil', 'Nominal belum diisi'], true) ? 'table-warning' : '');
                                                }
                                            ?>
                                            <tr class="<?= $warnaBaris; ?>">
                                                <td data-order="<?= (int) $row['id']; ?>">0</td>
                                                <td><?= htmlspecialchars($row['periode_arsip'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['jenis_data'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($row['nama'] ?? $row['nama_pengeluaran'] ?? '-', ENT_QUOTES, 'UTF-8'); ?><?= $row['keterangan'] ? ' - ' . htmlspecialchars($row['keterangan'], ENT_QUOTES, 'UTF-8') : ''; ?></td>
                                                <td><?= htmlspecialchars($row['notlp'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= $row['nominal'] === null ? '-' : 'Rp ' . number_format((float) $row['nominal'], 0, ',', '.'); ?></td>
                                                <td><?= $row['dibayar'] === null ? '-' : 'Rp ' . number_format((float) $row['dibayar'], 0, ',', '.'); ?></td>
                                                <td><?= htmlspecialchars($statusArsip, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($row['tanggal'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($row['diarsipkan_pada'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="sticky-footer bg-white"><div class="container my-auto"><div class="copyright text-center my-auto"><span>Copyright &copy; Anugerah Signal Abadi 2026</span></div></div></footer>
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
