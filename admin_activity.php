<?php
require_once __DIR__ . '/auth.php';

if (empty($_SESSION['is_superadmin'])) {
    http_response_code(403);
    die('Halaman ini hanya dapat diakses oleh admin utama.');
}

$conn = billing_db_connect();
billing_prepare_admin_tables($conn);
$activityResult = mysqli_query($conn, 'SELECT username, activity, page, target_id, ip_address, created_at FROM app_admin_activity_log ORDER BY id DESC LIMIT 500');
if (!$activityResult) {
    http_response_code(500);
    die('Gagal mengambil log aktivitas: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ASA - Log Aktivitas Admin</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="dashboard.php"><div class="sidebar-brand-text mx-3">ASA</div></a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-fw fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="admins.php"><i class="fas fa-fw fa-users-cog"></i><span>Kelola Admin</span></a></li>
            <li class="nav-item active"><a class="nav-link" href="admin_activity.php"><i class="fas fa-fw fa-history"></i><span>Log Aktivitas</span></a></li>
        </ul>
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <span class="mr-2 d-none d-lg-inline text-gray-600 small">Admin utama: <?= htmlspecialchars($_SESSION['username'] ?? 'root', ENT_QUOTES, 'UTF-8'); ?></span>
                    <ul class="navbar-nav ml-auto"><li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li></ul>
                </nav>
                <div class="container-fluid">
                    <h1 class="h3 mb-4 text-gray-800">Log Aktivitas Admin</h1>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Aktivitas terbaru</h6></div>
                        <div class="card-body">
                            <p class="text-muted">Menampilkan maksimal 500 aktivitas terbaru. Log mencatat akun, waktu, jenis aksi, halaman, ID data terkait, dan IP. Password serta isi form tidak disimpan.</p>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead><tr><th>Waktu</th><th>Admin</th><th>Aktivitas</th><th>Halaman</th><th>ID data</th><th>Alamat IP</th></tr></thead>
                                    <tbody>
                                        <?php while ($activity = mysqli_fetch_assoc($activityResult)): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($activity['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($activity['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($activity['activity'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($activity['page'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($activity['target_id'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?= htmlspecialchars($activity['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
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
    <script src="js/sb-admin-2.min.js"></script>
</body>
</html>
