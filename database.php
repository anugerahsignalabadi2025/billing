<?php
require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect('localhost', 'root', 'maruto33', 'billing_asa');
if (!$conn) {
    http_response_code(500);
    die('Koneksi database gagal: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;
$message = '';
$messageType = 'success';
$selectedPeriod = $_GET['periode_arsip'] ?? '';
if (!is_string($selectedPeriod) || ($selectedPeriod !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedPeriod))) {
    http_response_code(400);
    die('Periode arsip tidak valid.');
}

if (($_GET['export'] ?? '') === 'excel') {
    $exportSql = "SELECT * FROM arsip_bulanan
        WHERE (jenis_data <> 'omset'
            OR (jenis_data = 'omset' AND nominal IS NOT NULL AND COALESCE(dibayar, 0) >= nominal))"
        . billing_data_scope_sql('arsip_bulanan');
    if ($selectedPeriod !== '') {
        $exportSql .= " AND periode_arsip = ?";
    }
    $exportSql .= ' ORDER BY periode_arsip DESC, diarsipkan_pada DESC, id DESC';
    $exportStmt = mysqli_prepare($conn, $exportSql);
    if (!$exportStmt) {
        http_response_code(500);
        die('Gagal menyiapkan ekspor arsip: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
    }
    if ($selectedPeriod !== '') {
        mysqli_stmt_bind_param($exportStmt, 's', $selectedPeriod);
    }
    if (!mysqli_stmt_execute($exportStmt)) {
        $error = mysqli_stmt_error($exportStmt);
        mysqli_stmt_close($exportStmt);
        http_response_code(500);
        die('Gagal mengambil arsip untuk ekspor: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }

    $exportResult = mysqli_stmt_get_result($exportStmt);
    if (!$exportResult) {
        $error = mysqli_stmt_error($exportStmt);
        mysqli_stmt_close($exportStmt);
        http_response_code(500);
        die('Gagal membaca hasil ekspor arsip: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }

    $exportRows = [];
    while ($row = mysqli_fetch_assoc($exportResult)) {
        $nominal = $row['nominal'] === null ? null : (float) $row['nominal'];
        $dibayar = $row['dibayar'] === null ? null : (float) $row['dibayar'];
        $status = '-';
        if ($row['jenis_data'] === 'omset') {
            $status = $nominal === null
                ? 'Nominal belum diisi'
                : (($dibayar ?? 0) >= $nominal ? 'Lunas' : (($dibayar ?? 0) > 0 ? 'Cicil' : 'Belum lunas'));
        }
        $exportRows[] = [
            (int) $row['id'],
            $row['periode_arsip'],
            ucwords(str_replace('_', ' ', $row['jenis_data'])),
            $row['nama'] ?? $row['nama_pengeluaran'] ?? '-',
            $row['notlp'] ?? '-',
            $row['alamat'] ?? '-',
            $row['paket'] ?? '-',
            $row['nama_pengeluaran'] ?? '-',
            $row['keterangan'] ?? '-',
            $nominal,
            $dibayar,
            $status,
            $row['tanggal'] ?? '-',
            $row['diarsipkan_pada'],
        ];
    }
    mysqli_stmt_close($exportStmt);

    require_once __DIR__ . '/excel_export_helper.php';
    $periodSuffix = $selectedPeriod !== '' ? $selectedPeriod : 'semua_bulan';
    outputExcelWorkbook(
        'arsip_database_' . $periodSuffix . '.xls',
        ['No. Arsip', 'Periode Arsip', 'Jenis', 'Nama / Rincian', 'No. Telepon', 'Alamat', 'Paket', 'Nama Pengeluaran', 'Keterangan', 'Tagihan', 'Dibayar', 'Status', 'Tanggal', 'Diarsipkan Pada'],
        $exportRows
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_all_paid') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $message = 'Permintaan rekap tidak valid. Muat ulang halaman dan coba lagi.';
        $messageType = 'danger';
    } else {
        try {
            if (!mysqli_begin_transaction($conn)) {
                throw new RuntimeException('Gagal memulai transaksi rekap.');
            }

            $periodeArsipSekarang = date('Y-m');
            $revenueArchiveStmt = mysqli_prepare($conn, "INSERT INTO arsip_bulanan (periode_arsip, jenis_data, data_id, pelanggan_id, periode_tagihan, nama, notlp, alamat, paket, nominal, dibayar, tanggal, owner_admin_id)
                SELECT ?, 'omset', o.id, o.customer_id, o.periode, o.nama, o.notlp, o.alamat, o.paket, o.nominal, o.dibayar, o.tanggal, o.owner_admin_id
                FROM omset o
                WHERE o.nominal IS NOT NULL AND COALESCE(o.dibayar, 0) >= o.nominal
                    AND NOT EXISTS (
                        SELECT 1 FROM arsip_bulanan a
                        WHERE a.jenis_data = 'omset' AND a.data_id = o.id
                            AND a.nominal IS NOT NULL AND COALESCE(a.dibayar, 0) >= a.nominal
                    )" . billing_data_scope_sql('o'));
            if (!$revenueArchiveStmt) {
                throw new RuntimeException('Gagal menyiapkan arsip omset.');
            }
            mysqli_stmt_bind_param($revenueArchiveStmt, 's', $periodeArsipSekarang);
            if (!mysqli_stmt_execute($revenueArchiveStmt)) {
                throw new RuntimeException('Gagal menyimpan arsip omset.');
            }
            mysqli_stmt_close($revenueArchiveStmt);

            $archiveQueries = [
                "INSERT INTO arsip_bulanan (periode_arsip, jenis_data, data_id, nama_pengeluaran, keterangan, nominal, tanggal, owner_admin_id)
                 SELECT ?, 'pengeluaran_tetap', e.id, e.nama_pengeluaran, e.keterangan, e.nominal, e.tanggal, e.owner_admin_id FROM pengeluaran_tetap e WHERE 1=1" . billing_data_scope_sql('e'),
                "INSERT INTO arsip_bulanan (periode_arsip, jenis_data, data_id, nama_pengeluaran, keterangan, nominal, tanggal, owner_admin_id)
                 SELECT ?, 'pengeluaran_takterduga', e.id, e.nama_pengeluaran, e.keterangan, e.nominal, e.tanggal, e.owner_admin_id FROM pengeluaran_takterduga e WHERE 1=1" . billing_data_scope_sql('e'),
            ];
            foreach ($archiveQueries as $query) {
                $archiveStmt = mysqli_prepare($conn, $query);
                if (!$archiveStmt) {
                    throw new RuntimeException('Gagal menyiapkan arsip bulanan.');
                }
                mysqli_stmt_bind_param($archiveStmt, 's', $periodeArsipSekarang);
                if (!mysqli_stmt_execute($archiveStmt)) {
                    throw new RuntimeException('Gagal menyimpan arsip pengeluaran.');
                }
                mysqli_stmt_close($archiveStmt);
            }

            $deletePaidStmt = mysqli_prepare($conn, 'DELETE FROM omset WHERE nominal IS NOT NULL AND COALESCE(dibayar, 0) >= nominal' . billing_data_scope_sql());
            if (!$deletePaidStmt) {
                throw new RuntimeException('Gagal menyiapkan pembersihan tagihan lunas.');
            }
            if (!mysqli_stmt_execute($deletePaidStmt)) {
                throw new RuntimeException('Gagal menghapus tagihan lunas.');
            }
            $deletedPaid = mysqli_stmt_affected_rows($deletePaidStmt);
            mysqli_stmt_close($deletePaidStmt);

            $deletedExpenses = 0;
            foreach (['pengeluaran_tetap', 'pengeluaran_takterduga'] as $table) {
                if (!mysqli_query($conn, "DELETE FROM `$table` WHERE 1=1" . billing_data_scope_sql())) {
                    throw new RuntimeException('Gagal mengosongkan data pengeluaran aktif.');
                }
                $deletedExpenses += mysqli_affected_rows($conn);
            }

            if (!mysqli_commit($conn)) {
                throw new RuntimeException('Gagal menyelesaikan transaksi rekap.');
            }
            $selectedPeriod = $periodeArsipSekarang;
            $message = "Rekap semua data selesai. $deletedPaid tagihan lunas dan $deletedExpenses data pengeluaran dipindahkan ke arsip; cicilan dan tagihan belum lunas tetap tersimpan di Omset.";
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Rekap gagal. Tidak ada perubahan yang disimpan; periksa koneksi dan struktur database.';
            $messageType = 'danger';
        }
    }
}

$periodsResult = mysqli_query($conn, "SELECT periode_arsip, COUNT(*) AS jumlah
    FROM arsip_bulanan
    WHERE (jenis_data <> 'omset'
        OR (jenis_data = 'omset' AND nominal IS NOT NULL AND COALESCE(dibayar, 0) >= nominal))" . billing_data_scope_sql() . "
    GROUP BY periode_arsip
    ORDER BY periode_arsip DESC");
if (!$periodsResult) {
    http_response_code(500);
    die('Gagal mengambil daftar bulan arsip: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
$archivePeriods = [];
while ($periodRow = mysqli_fetch_assoc($periodsResult)) {
    $archivePeriods[] = $periodRow;
}
if ($selectedPeriod === '' && $archivePeriods) {
    $selectedPeriod = $archivePeriods[0]['periode_arsip'];
}

$archiveSql = "SELECT * FROM arsip_bulanan
    WHERE (jenis_data <> 'omset'
        OR (jenis_data = 'omset' AND nominal IS NOT NULL AND COALESCE(dibayar, 0) >= nominal))" . billing_data_scope_sql();
$totalsSql = "SELECT COALESCE(SUM(nominal), 0) AS nominal, COALESCE(SUM(dibayar), 0) AS dibayar
    FROM arsip_bulanan
    WHERE jenis_data = 'omset' AND nominal IS NOT NULL AND COALESCE(dibayar, 0) >= nominal" . billing_data_scope_sql();
if ($selectedPeriod !== '') {
    $archiveSql .= ' AND periode_arsip = ?';
    $totalsSql .= ' AND periode_arsip = ?';
}
$archiveSql .= ' ORDER BY periode_arsip DESC, diarsipkan_pada DESC, id DESC';
$archiveStmt = mysqli_prepare($conn, $archiveSql);
$archiveTotalsStmt = mysqli_prepare($conn, $totalsSql);
if (!$archiveStmt || !$archiveTotalsStmt) {
    http_response_code(500);
    die('Gagal menyiapkan riwayat arsip: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
if ($selectedPeriod !== '') {
    mysqli_stmt_bind_param($archiveStmt, 's', $selectedPeriod);
    mysqli_stmt_bind_param($archiveTotalsStmt, 's', $selectedPeriod);
}
if (!mysqli_stmt_execute($archiveStmt)) {
    $error = mysqli_stmt_error($archiveStmt);
    mysqli_stmt_close($archiveStmt);
    mysqli_stmt_close($archiveTotalsStmt);
    http_response_code(500);
    die('Gagal mengambil riwayat arsip: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}
$archiveResult = mysqli_stmt_get_result($archiveStmt);
if (!$archiveResult) {
    $error = mysqli_stmt_error($archiveStmt);
    mysqli_stmt_close($archiveStmt);
    mysqli_stmt_close($archiveTotalsStmt);
    http_response_code(500);
    die('Gagal membaca riwayat arsip: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}
if (!mysqli_stmt_execute($archiveTotalsStmt)) {
    $error = mysqli_stmt_error($archiveTotalsStmt);
    mysqli_stmt_close($archiveStmt);
    mysqli_stmt_close($archiveTotalsStmt);
    http_response_code(500);
    die('Gagal menghitung total arsip: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}
$archiveTotalsResult = mysqli_stmt_get_result($archiveTotalsStmt);
if (!$archiveTotalsResult) {
    $error = mysqli_stmt_error($archiveTotalsStmt);
    mysqli_stmt_close($archiveStmt);
    mysqli_stmt_close($archiveTotalsStmt);
    http_response_code(500);
    die('Gagal membaca total arsip: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}
$archiveTotals = mysqli_fetch_assoc($archiveTotalsResult);
$namaBulan = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
];
$selectedPeriodLabel = $selectedPeriod === ''
    ? 'Semua bulan'
    : ($namaBulan[(int) substr($selectedPeriod, 5, 2)] . ' ' . substr($selectedPeriod, 0, 4));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ASA - Rekap Semua Data</title>
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
                    <h1 class="h3 mb-4 text-gray-800">Rekap Semua Data</h1>
                    <?php if ($message !== ''): ?>
                        <div class="alert alert-<?= $messageType; ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Rekap Semua Data</h6></div>
                        <div class="card-body">
                            <?php if (billing_can('update')): ?>
                                <p>Rekap mengambil tagihan yang sudah lunas penuh dari semua periode dan tanggal, serta seluruh pengeluaran aktif. Cicilan dan tagihan belum lunas tidak masuk arsip dan tetap tersimpan di Omset.</p>
                                <form method="post" onsubmit="return confirm('Rekap semua periode? Semua tagihan lunas penuh dan seluruh pengeluaran aktif akan dipindahkan ke arsip. Cicilan dan tagihan belum lunas tetap berada di Omset.');">
                                    <input type="hidden" name="action" value="archive_all_paid">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-danger"><i class="fas fa-archive"></i> Rekap Semua Data Lunas</button>
                                </form>
                            <?php else: ?>
                                <p class="mb-0">Anda tidak memiliki izin UPDATE untuk menjalankan rekap.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6 mb-3"><div class="card border-left-success shadow h-100"><div class="card-body"><div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Tagihan Terarsip - <?= htmlspecialchars($selectedPeriodLabel, ENT_QUOTES, 'UTF-8'); ?></div><div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format((float) $archiveTotals['nominal'], 0, ',', '.'); ?></div></div></div></div>
                        <div class="col-md-6 mb-3"><div class="card border-left-primary shadow h-100"><div class="card-body"><div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Pembayaran Terarsip - <?= htmlspecialchars($selectedPeriodLabel, ENT_QUOTES, 'UTF-8'); ?></div><div class="h5 mb-0 font-weight-bold text-gray-800">Rp <?= number_format((float) $archiveTotals['dibayar'], 0, ',', '.'); ?></div></div></div></div>
                    </div>

                    <div class="card shadow mb-4">
                        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Riwayat Arsip</h6></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <span class="d-block font-weight-bold mb-2">Filter bulan arsip:</span>
                                <?php foreach ($archivePeriods as $periodOption): ?>
                                    <?php
                                        $periodValue = $periodOption['periode_arsip'];
                                        $periodLabel = $namaBulan[(int) substr($periodValue, 5, 2)] . ' ' . substr($periodValue, 0, 4);
                                    ?>
                                    <a class="btn btn-sm mr-1 mb-2 <?= $selectedPeriod === $periodValue ? 'btn-primary' : 'btn-outline-primary'; ?>"
                                       href="database.php?periode_arsip=<?= rawurlencode($periodValue); ?>">
                                        <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        <span class="badge badge-light ml-1"><?= number_format((int) $periodOption['jumlah']); ?></span>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (!$archivePeriods): ?>
                                    <span class="text-muted">Belum ada data arsip.</span>
                                <?php endif; ?>
                            </div>
                            <a class="btn btn-success mb-3" href="database.php?export=excel<?= $selectedPeriod !== '' ? '&amp;periode_arsip=' . rawurlencode($selectedPeriod) : ''; ?>">
                                <i class="fas fa-file-excel"></i> Export <?= htmlspecialchars($selectedPeriodLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
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
