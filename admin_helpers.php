<?php
function billing_db_connect()
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = mysqli_connect('localhost', 'root', 'maruto33', 'billing_asa');
    if (!$connection) {
        http_response_code(500);
        die('Koneksi database gagal: ' . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8'));
    }

    return $connection;
}

function billing_admin_table_exists($connection)
{
    $result = mysqli_query($connection, "SHOW TABLES LIKE 'app_admin_users'");
    if (!$result) {
        http_response_code(500);
        die('Gagal memeriksa tabel akun admin: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }

    $exists = mysqli_num_rows($result) > 0;
    mysqli_free_result($result);
    return $exists;
}

function billing_prepare_admin_tables($connection)
{
    $adminTableSql = "CREATE TABLE IF NOT EXISTS app_admin_users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        can_create TINYINT(1) NOT NULL DEFAULT 0,
        can_delete TINYINT(1) NOT NULL DEFAULT 0,
        can_update TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_seen_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!mysqli_query($connection, $adminTableSql)) {
        http_response_code(500);
        die('Gagal menyiapkan tabel akun admin: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }

    $columnResult = mysqli_query($connection, "SHOW COLUMNS FROM app_admin_users LIKE 'last_seen_at'");
    if (!$columnResult) {
        http_response_code(500);
        die('Gagal memeriksa kolom aktivitas admin: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    $hasLastSeen = mysqli_num_rows($columnResult) > 0;
    mysqli_free_result($columnResult);
    if (!$hasLastSeen && !mysqli_query($connection, 'ALTER TABLE app_admin_users ADD COLUMN last_seen_at DATETIME NULL AFTER is_active')) {
        http_response_code(500);
        die('Gagal menambahkan data last online: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }

    $activityTableSql = "CREATE TABLE IF NOT EXISTS app_admin_activity_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        admin_user_id INT UNSIGNED NULL,
        username VARCHAR(64) NOT NULL,
        activity VARCHAR(100) NOT NULL,
        page VARCHAR(100) NULL,
        target_id VARCHAR(100) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_activity_created_at (created_at),
        INDEX idx_activity_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!mysqli_query($connection, $activityTableSql)) {
        http_response_code(500);
        die('Gagal menyiapkan tabel log aktivitas: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
}

function billing_log_activity($username, $activity, $page = null, $targetId = null, $adminUserId = null)
{
    $connection = billing_db_connect();
    billing_prepare_admin_tables($connection);
    $username = substr((string) $username, 0, 64);
    $activity = substr((string) $activity, 0, 100);
    $page = $page === null ? null : substr((string) $page, 0, 100);
    $targetId = $targetId === null ? null : substr((string) $targetId, 0, 100);
    $ipAddress = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $stmt = mysqli_prepare($connection, 'INSERT INTO app_admin_activity_log (admin_user_id, username, activity, page, target_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        $error = mysqli_error($connection);
        mysqli_close($connection);
        http_response_code(500);
        die('Gagal menyiapkan pencatatan aktivitas: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($stmt, 'issssss', $adminUserId, $username, $activity, $page, $targetId, $ipAddress, $userAgent);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        mysqli_close($connection);
        http_response_code(500);
        die('Gagal menyimpan log aktivitas: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_close($stmt);
    mysqli_close($connection);
}

function billing_require_permission($permission)
{
    if (!billing_can($permission)) {
        http_response_code(403);
        die('Anda tidak memiliki izin untuk melakukan aksi ini.');
    }
}

function billing_can($permission)
{
    return !empty($_SESSION['is_superadmin']) || !empty($_SESSION['permissions'][$permission]);
}
