<?php
require_once __DIR__ . '/admin_helpers.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($_SESSION['authenticated'])) {
    header('Location: dashboard.php');
    exit;
}

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $username !== 'root') {
        $connection = billing_db_connect();
        if (billing_admin_table_exists($connection)) {
            $stmt = mysqli_prepare($connection, 'SELECT id, password_hash, can_create, can_delete, can_update, is_active FROM app_admin_users WHERE username = ?');
            if (!$stmt) {
                mysqli_close($connection);
                http_response_code(500);
                die('Gagal memeriksa akun admin.');
            }
            mysqli_stmt_bind_param($stmt, 's', $username);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                mysqli_close($connection);
                http_response_code(500);
                die('Gagal memeriksa akun admin.');
            }
            $result = mysqli_stmt_get_result($stmt);
            $admin = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            mysqli_close($connection);

            if ($admin && (int) $admin['is_active'] === 1 && password_verify($password, $admin['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                $_SESSION['username'] = $username;
                $_SESSION['admin_user_id'] = (int) $admin['id'];
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
                $adminId = (int) $admin['id'];
                mysqli_stmt_bind_param($lastSeenStmt, 'i', $adminId);
                if (!mysqli_stmt_execute($lastSeenStmt)) {
                    mysqli_stmt_close($lastSeenStmt);
                    mysqli_close($lastSeenConnection);
                    http_response_code(500);
                    die('Gagal memperbarui last online admin.');
                }
                mysqli_stmt_close($lastSeenStmt);
                mysqli_close($lastSeenConnection);
                billing_log_activity($username, 'Login berhasil', 'login.php', null, $adminId);
                header('Location: dashboard.php');
                exit;
            }
        } else {
            mysqli_close($connection);
        }
    } elseif ($username === 'root') {
        try {
            $connection = mysqli_connect('localhost', $username, $password, 'billing_asa');
        } catch (mysqli_sql_exception $exception) {
            $connection = false;
        }

        if ($connection) {
            mysqli_close($connection);
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['is_superadmin'] = true;
            billing_log_activity($username, 'Login berhasil', 'login.php');
            header('Location: dashboard.php');
            exit;
        }
    }

    $login_error = 'Username atau password tidak valid, atau akun admin sedang dinonaktifkan.';
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

    <title>SB Admin 2 - Login</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

    <!-- CSS khusus untuk mengatur logo PNG agar ukurannya pas -->
    <style>
        .bg-login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            background-color: #ffffff; /* Ubah warna background kolom kiri jika perlu */
        }

        .login-logo {
            max-width: 85%;    /* Mengatur lebar maksimum logo agar tidak kebesaran */
            max-height: 280 px;  /* Mengatur tinggi maksimum logo */
            width: auto;
            height: auto;
            object-fit: contain;
        }
    </style>

</head>

<body class="bg-gradient-primary">

    <div class="container">

        <!-- Outer Row -->
        <div class="row justify-content-center">

            <div class="col-xl-10 col-lg-12 col-md-9">

                <div class="card o-hidden border-0 shadow-lg my-5">
                    <div class="card-body p-0">
                        <!-- Nested Row within Card Body -->
                        <div class="row">
                            <!-- Left Column: Tempat Logo PNG -->
                            <div class="col-lg-6 d-none d-lg-flex bg-login-container">
                                <!-- Ganti 'img/logo.png' dengan nama file logo PNG kamu -->
                                <img src="img/AnugerahSignalAbadi.png" alt="Logo PT. Anugerah Signal Abadi" class="login-logo">
                            </div>
                            
                            <!-- Right Column: Form Login -->
                            <div class="col-lg-6">
                                <div class="p-5">
                                    <div class="text-center">
                                        <h1 class="h4 text-gray-900 mb-4">Login</h1>
                                    </div>
                                    <?php if ($login_error !== ''): ?>
                                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <form class="user" method="post" action="login.php">
                                        <div class="form-group">
                                            <input type="text" class="form-control form-control-user"
                                                id="username" name="username" placeholder="Username"
                                                autocomplete="username" required>
                                        </div>
                                        <div class="form-group">
                                            <input type="password" class="form-control form-control-user"
                                                id="password" name="password" placeholder="Password"
                                                autocomplete="current-password">
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-user btn-block">
                                            Login
                                        </button>
                                        <hr>
                                        <!-- <div class="text-center">
                                            <a class="small" href="register.html">Create an Account!</a>
                                        </div> -->
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

</body>

</html>