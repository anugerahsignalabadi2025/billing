<?php
require_once __DIR__ . '/auth.php';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect("localhost", "root", "maruto33", "billing_asa");
if (!$conn) {
    http_response_code(500);
    die('Koneksi database gagal: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
}

$nama = trim($_POST["nama"] ?? "");
$notlp = trim($_POST["notlp"] ?? "");
$nominalInput = trim($_POST["nominal"] ?? "");
$tanggal = trim($_POST["tanggal"] ?? "");
$metodeInput = $_POST["metode_pembayaran"] ?? "";
$catatanInput = $_POST["catatan"] ?? "";
$metadataValid = is_string($metodeInput) && is_string($catatanInput);
$metodePembayaran = $metadataValid ? trim($metodeInput) : "";
$catatan = $metadataValid ? trim($catatanInput) : "";
$message = '';
$messageType = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["submit"])) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $tanggal);
    if (!$metadataValid || $nama === '' || $nominalInput === '' || !is_numeric($nominalInput) || (float) $nominalInput <= 0 || strlen($metodePembayaran) > 100 ||
        !$date || $date->format('Y-m-d') !== $tanggal) {
        http_response_code(400);
        $message = 'Nama, nominal lebih dari Rp0, dan tanggal yang valid wajib diisi; metode pembayaran maksimal 100 karakter.';
    } else {
        $nominal = (float) $nominalInput;
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO omset (customer_id, nama, notlp, alamat, nominal, tanggal, paket, periode, dibayar, metode_pembayaran, catatan)
             VALUES (NULL, NULLIF(?, ''), NULLIF(?, ''), NULL, ?, ?, NULL, NULL, 0, NULLIF(?, ''), NULLIF(?, ''))"
        );
        if (!$stmt) {
            error_log('[tambah_omset] Gagal menyiapkan insert: ' . mysqli_error($conn));
            http_response_code(500);
            $message = 'Data gagal disiapkan untuk disimpan. Periksa struktur tabel omset di database.';
        } else {
            mysqli_stmt_bind_param($stmt, 'ssdsss', $nama, $notlp, $nominal, $tanggal, $metodePembayaran, $catatan);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                header('Location: omset.php');
                exit;
            }

            error_log('[tambah_omset] Gagal menyimpan data: ' . mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            http_response_code(500);
            $message = 'Data gagal disimpan. Struktur tabel omset di server mungkin belum sesuai dengan aplikasi. Hubungi administrator dan periksa error log PHP.';
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
                            <?php if ($message !== ''): ?>
                                <div class="alert alert-<?= $messageType; ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <form action="" method="post">
                                <div class="form-group">
                                    <label for="nama">Nama</label>
                                    <input type="text" class="form-control" name="nama" id="nama" value="<?= htmlspecialchars($nama, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="notlp">No. Telepon</label>
                                    <input type="text" class="form-control" name="notlp" id="notlp" value="<?= htmlspecialchars($notlp, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="nominal">Nominal (Rp)</label>
                                    <input type="number" class="form-control" name="nominal" id="nominal" min="1" step="0.01" value="<?= htmlspecialchars($nominalInput, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="tanggal">Tanggal</label>
                                    <input type="date" class="form-control" name="tanggal" id="tanggal" value="<?= htmlspecialchars($tanggal, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="metode_pembayaran">Metode Pembayaran</label>
                                    <input type="text" class="form-control" name="metode_pembayaran" id="metode_pembayaran" maxlength="100" list="metodePembayaranList" value="<?= htmlspecialchars($metodePembayaran, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Contoh: Tunai, Transfer Bank, QRIS">
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
                                <div class="form-group">
                                    <label for="catatan">Catatan</label>
                                    <textarea class="form-control" name="catatan" id="catatan" rows="3"><?= htmlspecialchars($catatan, ENT_QUOTES, 'UTF-8'); ?></textarea>
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
