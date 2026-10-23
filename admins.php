<?php
require_once __DIR__ . '/auth.php';

if (empty($_SESSION['is_superadmin'])) {
    http_response_code(403);
    die('Halaman ini hanya dapat diakses oleh admin utama.');
}

$conn = billing_db_connect();
billing_prepare_admin_tables($conn);

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;
$message = '';
$messageType = 'success';
$editAdmin = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $message = 'Permintaan tidak valid. Muat ulang halaman dan coba lagi.';
        $messageType = 'danger';
    } elseif ($action === 'save') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $canCreate = isset($_POST['can_create']) ? 1 : 0;
        $canDelete = isset($_POST['can_delete']) ? 1 : 0;
        $canUpdate = isset($_POST['can_update']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($id > 0) {
            $editAdmin = [
                'id' => $id,
                'username' => $username,
                'can_create' => $canCreate,
                'can_delete' => $canDelete,
                'can_update' => $canUpdate,
                'is_active' => $isActive,
            ];
        }

        if (strtolower($username) === 'root') {
            $message = 'Username root dicadangkan untuk admin utama.';
            $messageType = 'danger';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            $message = 'Username harus 3-64 karakter, hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.';
            $messageType = 'danger';
        } elseif (($id === 0 && strlen($password) < 8) || ($password !== '' && strlen($password) < 8)) {
            $message = 'Password wajib diisi minimal 8 karakter.';
            $messageType = 'danger';
        } else {
            $stmt = false;
            if ($id > 0) {
                if ($password === '') {
                    $stmt = mysqli_prepare($conn, 'UPDATE app_admin_users SET username = ?, can_create = ?, can_delete = ?, can_update = ?, is_active = ? WHERE id = ?');
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, 'siiiii', $username, $canCreate, $canDelete, $canUpdate, $isActive, $id);
                    }
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare($conn, 'UPDATE app_admin_users SET username = ?, password_hash = ?, can_create = ?, can_delete = ?, can_update = ?, is_active = ? WHERE id = ?');
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, 'ssiiiii', $username, $passwordHash, $canCreate, $canDelete, $canUpdate, $isActive, $id);
                    }
                }
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($conn, 'INSERT INTO app_admin_users (username, password_hash, can_create, can_delete, can_update, is_active) VALUES (?, ?, ?, ?, ?, ?)');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ssiiii', $username, $passwordHash, $canCreate, $canDelete, $canUpdate, $isActive);
                }
            }

            if (!$stmt) {
                $message = 'Gagal menyiapkan penyimpanan admin: ' . mysqli_error($conn);
                $messageType = 'danger';
            } elseif (!mysqli_stmt_execute($stmt)) {
                $errorCode = mysqli_stmt_errno($stmt);
                $message = $errorCode === 1062
                    ? 'Username tersebut sudah digunakan.'
                    : 'Gagal menyimpan admin: ' . mysqli_stmt_error($stmt);
                $messageType = 'danger';
                mysqli_stmt_close($stmt);
            } else {
                mysqli_stmt_close($stmt);
                header('Location: admins.php?saved=1');
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            $message = 'Admin yang dipilih tidak valid.';
            $messageType = 'danger';
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM app_admin_users WHERE id = ?');
            if (!$stmt) {
                http_response_code(500);
                die('Gagal menyiapkan penghapusan admin: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
            }
            mysqli_stmt_bind_param($stmt, 'i', $id);
            if (!mysqli_stmt_execute($stmt)) {
                $message = 'Gagal menghapus admin: ' . mysqli_stmt_error($stmt);
                $messageType = 'danger';
            } else {
                header('Location: admins.php?deleted=1');
                exit;
            }
            mysqli_stmt_close($stmt);
        }
    }
}

if (isset($_GET['saved'])) {
    $message = 'Akun admin berhasil disimpan.';
}
if (isset($_GET['deleted'])) {
    $message = 'Akun admin berhasil dihapus.';
}
if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($editId) {
        $stmt = mysqli_prepare($conn, 'SELECT id, username, can_create, can_delete, can_update, is_active FROM app_admin_users WHERE id = ?');
        if (!$stmt) {
            http_response_code(500);
            die('Gagal menyiapkan data admin: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
        }
        mysqli_stmt_bind_param($stmt, 'i', $editId);
        if (!mysqli_stmt_execute($stmt)) {
            http_response_code(500);
            die('Gagal mengambil data admin: ' . htmlspecialchars(mysqli_stmt_error($stmt), ENT_QUOTES, 'UTF-8'));
        }
        $result = mysqli_stmt_get_result($stmt);
        $editAdmin = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }
}

$adminsResult = mysqli_query($conn, 'SELECT id, username, can_create, can_delete, can_update, is_active, last_seen_at, (last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS is_online, created_at FROM app_admin_users ORDER BY username');
if (!$adminsResult) {
    http_response_code(500);
    die('Gagal mengambil daftar admin: ' . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>ASA - Kelola Admin</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="css/sb-admin-2.min.css" rel="stylesheet">
</head>
<body id="page-top">
    <div id="wrapper">
        <ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center" href="dashboard.php"><div class="sidebar-brand-text mx-3">ASA</div></a>
            <hr class="sidebar-divider my-0">
            <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-fw fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="admins.php"><i class="fas fa-fw fa-users-cog"></i><span>Kelola Admin</span></a></li>
        </ul>
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <span class="mr-2 d-none d-lg-inline text-gray-600 small">Admin utama: <?= htmlspecialchars($_SESSION['username'] ?? 'root', ENT_QUOTES, 'UTF-8'); ?></span>
                    <ul class="navbar-nav ml-auto"><li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li></ul>
                </nav>
                <div class="container-fluid">
                    <h1 class="h3 mb-4 text-gray-800">Kelola Admin / Teknisi</h1>
                    <?php if ($message !== ''): ?><div class="alert alert-<?= $messageType; ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <div class="card shadow mb-4">
                        <div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary"><?= $editAdmin ? 'Ubah Admin' : 'Tambah Admin'; ?></h6></div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="id" value="<?= (int) ($editAdmin['id'] ?? 0); ?>">
                                <div class="form-group"><label for="username">Username</label><input class="form-control" id="username" name="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" value="<?= htmlspecialchars($editAdmin['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></div>
                                <div class="form-group"><label for="password">Password<?= $editAdmin ? ' (kosongkan jika tidak ingin mengganti)' : ''; ?></label><input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" <?= $editAdmin ? '' : 'required'; ?>></div>
                                <p class="font-weight-bold mb-2">Allow Permission</p>
                                <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="readPermission" checked disabled><label class="custom-control-label" for="readPermission">READ (selalu diizinkan)</label></div>
                                <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="createPermission" name="can_create" <?= !empty($editAdmin['can_create']) ? 'checked' : ''; ?>><label class="custom-control-label" for="createPermission">CREATE</label></div>
                                <div class="custom-control custom-checkbox"><input class="custom-control-input" type="checkbox" id="deletePermission" name="can_delete" <?= !empty($editAdmin['can_delete']) ? 'checked' : ''; ?>><label class="custom-control-label" for="deletePermission">DELETE</label></div>
                                <div class="custom-control custom-checkbox mb-3"><input class="custom-control-input" type="checkbox" id="updatePermission" name="can_update" <?= !empty($editAdmin['can_update']) ? 'checked' : ''; ?>><label class="custom-control-label" for="updatePermission">UPDATE</label></div>
                                <div class="custom-control custom-checkbox mb-3"><input class="custom-control-input" type="checkbox" id="activePermission" name="is_active" <?= !$editAdmin || !empty($editAdmin['is_active']) ? 'checked' : ''; ?>><label class="custom-control-label" for="activePermission">Akun aktif</label></div>
                                <button type="submit" class="btn btn-primary"><?= $editAdmin ? 'Simpan Perubahan' : 'Buat Admin'; ?></button>
                                <?php if ($editAdmin): ?><a href="admins.php" class="btn btn-secondary">Batal</a><?php endif; ?>
                            </form>
                        </div>
                    </div>
                    <div class="card shadow mb-4"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Daftar Admin</h6></div><div class="card-body"><div class="table-responsive">
                        <table class="table table-bordered"><thead><tr><th>Username</th><th>Akun</th><th>Last online</th><th>READ</th><th>CREATE</th><th>DELETE</th><th>UPDATE</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>
                            <?php while ($admin = mysqli_fetch_assoc($adminsResult)): ?>
                                <tr><td><?= htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8'); ?></td><td><?= (int) $admin['is_active'] ? ((int) $admin['is_online'] ? '<span class="text-success font-weight-bold">Online</span>' : 'Aktif') : 'Nonaktif'; ?></td><td><?= $admin['last_seen_at'] ? htmlspecialchars($admin['last_seen_at'], ENT_QUOTES, 'UTF-8') : 'Belum pernah login'; ?></td><td>Ya</td><td><?= (int) $admin['can_create'] ? 'Ya' : 'Tidak'; ?></td><td><?= (int) $admin['can_delete'] ? 'Ya' : 'Tidak'; ?></td><td><?= (int) $admin['can_update'] ? 'Ya' : 'Tidak'; ?></td><td><?= htmlspecialchars($admin['created_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><a class="btn btn-warning btn-sm" href="admins.php?edit=<?= (int) $admin['id']; ?>">Edit</a> <form method="post" class="d-inline" onsubmit="return confirm('Hapus admin ini?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="id" value="<?= (int) $admin['id']; ?>"><button type="submit" class="btn btn-danger btn-sm">Hapus</button></form></td></tr>
                            <?php endwhile; ?>
                        </tbody></table>
                    </div></div></div>
                </div>
            </div>
            <footer class="sticky-footer bg-white"><div class="container my-auto"><div class="copyright text-center my-auto"><span>Copyright &copy; Anugerah Signal Abadi 2026</span></div></div></footer>
        </div>
    </div>
    <script src="vendor/jquery/jquery.min.js"></script><script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="js/sb-admin-2.min.js"></script>
</body>
</html>
