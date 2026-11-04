<?php
require_once __DIR__ . '/admin_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['is_superadmin']) && empty($_SESSION['admin_user_id'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$ownershipConnection = billing_db_connect();
billing_prepare_data_ownership($ownershipConnection);
mysqli_close($ownershipConnection);

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$permissionByPage = [
    'tambah.php' => 'create',
    'tambah_omset.php' => 'create',
    'tambah_pengeluaran_tetap.php' => 'create',
    'tambah_pengeluaran_takterduga.php' => 'create',
    'hapus.php' => 'delete',
    'hapus_omset.php' => 'delete',
    'hapus_pengeluaran_tetap.php' => 'delete',
    'hapus_pengeluaran_takterduga.php' => 'delete',
    'ubah.php' => 'update',
    'ubah_omset.php' => 'update',
    'ubah_pengeluaran_tetap.php' => 'update',
    'ubah_pengeluaran_takterduga.php' => 'update',
];
if (empty($_SESSION['is_superadmin']) && !empty($_SESSION['admin_user_id'])) {
    $connection = billing_db_connect();
    if (!billing_admin_table_exists($connection)) {
        mysqli_close($connection);
        http_response_code(500);
        die('Tabel akun admin belum tersedia. Masuk sebagai admin utama untuk menyiapkannya.');
    }

    $adminId = (int) $_SESSION['admin_user_id'];
    $stmt = mysqli_prepare($connection, 'SELECT is_active, can_create, can_delete, can_update FROM app_admin_users WHERE id = ?');
    if (!$stmt) {
        mysqli_close($connection);
        http_response_code(500);
        die('Gagal memeriksa status akun admin.');
    }
    mysqli_stmt_bind_param($stmt, 'i', $adminId);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_close($connection);
        http_response_code(500);
        die('Gagal memeriksa status akun admin.');
    }
    $result = mysqli_stmt_get_result($stmt);
    $admin = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    mysqli_close($connection);

    if (!$admin || !(int) $admin['is_active']) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }

    $_SESSION['permissions'] = [
        'create' => (bool) $admin['can_create'],
        'delete' => (bool) $admin['can_delete'],
        'update' => (bool) $admin['can_update'],
    ];

    $lastSeenConnection = billing_db_connect();
    $lastSeenStmt = mysqli_prepare($lastSeenConnection, 'UPDATE app_admin_users SET last_seen_at = NOW() WHERE id = ?');
    if (!$lastSeenStmt) {
        mysqli_close($lastSeenConnection);
        http_response_code(500);
        die('Gagal memperbarui last online admin.');
    }
    mysqli_stmt_bind_param($lastSeenStmt, 'i', $adminId);
    if (!mysqli_stmt_execute($lastSeenStmt)) {
        mysqli_stmt_close($lastSeenStmt);
        mysqli_close($lastSeenConnection);
        http_response_code(500);
        die('Gagal memperbarui last online admin.');
    }
    mysqli_stmt_close($lastSeenStmt);
    mysqli_close($lastSeenConnection);
}

if (!empty($_SESSION['is_superadmin']) && isset($_SESSION['acting_as_admin_id'])) {
    $actingAdminId = (int) $_SESSION['acting_as_admin_id'];
    $connection = billing_db_connect();
    $actingStmt = mysqli_prepare($connection, 'SELECT username FROM app_admin_users WHERE id = ?');
    if (!$actingStmt) {
        mysqli_close($connection);
        http_response_code(500);
        die('Gagal memeriksa akun user yang sedang dibuka.');
    }
    mysqli_stmt_bind_param($actingStmt, 'i', $actingAdminId);
    if (!mysqli_stmt_execute($actingStmt)) {
        mysqli_stmt_close($actingStmt);
        mysqli_close($connection);
        http_response_code(500);
        die('Gagal memeriksa akun user yang sedang dibuka.');
    }
    $actingResult = mysqli_stmt_get_result($actingStmt);
    $actingAdmin = mysqli_fetch_assoc($actingResult);
    mysqli_stmt_close($actingStmt);
    mysqli_close($connection);
    if (!$actingAdmin) {
        unset($_SESSION['acting_as_admin_id']);
        unset($_SESSION['acting_as_admin_username']);
    } else {
        $_SESSION['acting_as_admin_username'] = $actingAdmin['username'];
    }
}

if (isset($permissionByPage[$currentPage])) {
    billing_require_permission($permissionByPage[$currentPage]);
}
if ($currentPage === 'omset.php' && ($_POST['action'] ?? '') === 'update_payment') {
    billing_require_permission('update');
}
if ($currentPage === 'database.php' && ($_POST['action'] ?? '') === 'archive_all_paid') {
    billing_require_permission('update');
}

$activityByPage = [
    'tambah.php' => ['POST', 'Permintaan CREATE pelanggan'],
    'tambah_omset.php' => ['POST', 'Permintaan CREATE omset'],
    'tambah_pengeluaran_tetap.php' => ['POST', 'Permintaan CREATE pengeluaran tetap'],
    'tambah_pengeluaran_takterduga.php' => ['POST', 'Permintaan CREATE pengeluaran tak terduga'],
    'ubah.php' => ['POST', 'Permintaan UPDATE pelanggan'],
    'ubah_omset.php' => ['POST', 'Permintaan UPDATE omset'],
    'ubah_pengeluaran_tetap.php' => ['POST', 'Permintaan UPDATE pengeluaran tetap'],
    'ubah_pengeluaran_takterduga.php' => ['POST', 'Permintaan UPDATE pengeluaran tak terduga'],
    'hapus.php' => ['GET', 'Permintaan DELETE pelanggan'],
    'hapus_omset.php' => ['GET', 'Permintaan DELETE omset'],
    'hapus_pengeluaran_tetap.php' => ['GET', 'Permintaan DELETE pengeluaran tetap'],
    'hapus_pengeluaran_takterduga.php' => ['GET', 'Permintaan DELETE pengeluaran tak terduga'],
    'copy_user_data.php' => ['POST', 'Permintaan salin data user ke admin utama'],
];
$activity = null;
$targetId = $_POST['id'] ?? $_GET['id'] ?? null;
if ($currentPage === 'omset.php' && ($_POST['action'] ?? '') === 'update_payment') {
    $activity = 'Permintaan UPDATE pembayaran omset';
} elseif ($currentPage === 'database.php' && ($_POST['action'] ?? '') === 'archive_all_paid') {
    $activity = 'Permintaan rekap semua data lunas';
} elseif ($currentPage === 'admins.php' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $adminAction = $_POST['action'] ?? '';
    $activity = match ($adminAction) {
        'delete' => 'Permintaan DELETE akun admin',
        'save' => !empty($_POST['id']) ? 'Permintaan UPDATE akun admin' : 'Permintaan CREATE akun admin',
        'view_user' => 'Admin utama membuka data user',
        'stop_view_user' => 'Admin utama kembali ke data sendiri',
        default => 'Aktivitas kelola akun admin',
    };
} elseif (isset($activityByPage[$currentPage]) && ($_SERVER['REQUEST_METHOD'] ?? '') === $activityByPage[$currentPage][0]) {
    $activity = $activityByPage[$currentPage][1];
}

if ($activity !== null) {
    register_shutdown_function(static function () use ($activity, $currentPage, $targetId) {
        if (http_response_code() < 400) {
            $activityAdminId = isset($_SESSION['acting_as_admin_id'])
                ? (int) $_SESSION['acting_as_admin_id']
                : (isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null);
            if ($currentPage === 'copy_user_data.php' && isset($_POST['source_owner_id'])) {
                $sourceOwnerId = filter_var($_POST['source_owner_id'], FILTER_VALIDATE_INT);
                if ($sourceOwnerId !== false && $sourceOwnerId > 0) {
                    $activityAdminId = $sourceOwnerId;
                }
            }
            if ($currentPage === 'admins.php' && in_array($_POST['action'] ?? '', ['view_user', 'stop_view_user'], true)) {
                $viewedAdminId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if ($viewedAdminId !== false && $viewedAdminId > 0) {
                    $activityAdminId = $viewedAdminId;
                }
            }
            billing_log_activity(
                $_SESSION['username'] ?? 'admin',
                $activity,
                $currentPage,
                $targetId,
                $activityAdminId
            );
        }
    });
}
