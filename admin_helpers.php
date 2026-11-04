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

function billing_release_data_ownership_lock($connection)
{
    $result = mysqli_query($connection, "SELECT RELEASE_LOCK('billing_data_ownership_v1') AS released");
    if (!$result) {
        http_response_code(500);
        die('Gagal melepaskan kunci migrasi isolasi data: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    if ((int) ($row['released'] ?? 0) !== 1) {
        http_response_code(500);
        die('Kunci migrasi isolasi data tidak dapat dilepaskan.');
    }
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

function billing_prepare_data_ownership($connection)
{
    $migrationTableSql = "CREATE TABLE IF NOT EXISTS app_schema_migrations (
        migration_key VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!mysqli_query($connection, $migrationTableSql)) {
        http_response_code(500);
        die('Gagal menyiapkan pencatatan migrasi database: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }

    $migrationKey = 'data_owner_admin_id_v1';
    $constraintsMigrationKey = 'owner_scoped_unique_keys_v1';
    $completionStmt = mysqli_prepare($connection, 'SELECT COUNT(DISTINCT migration_key) AS completed FROM app_schema_migrations WHERE migration_key IN (?, ?)');
    if (!$completionStmt) {
        http_response_code(500);
        die('Gagal memeriksa status migrasi isolasi data: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($completionStmt, 'ss', $migrationKey, $constraintsMigrationKey);
    if (!mysqli_stmt_execute($completionStmt)) {
        $error = mysqli_stmt_error($completionStmt);
        mysqli_stmt_close($completionStmt);
        http_response_code(500);
        die('Gagal memeriksa status migrasi isolasi data: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    $completionResult = mysqli_stmt_get_result($completionStmt);
    $migrationsComplete = (int) mysqli_fetch_assoc($completionResult)['completed'] === 2;
    mysqli_stmt_close($completionStmt);
    if ($migrationsComplete) {
        return;
    }

    $lockResult = mysqli_query($connection, "SELECT GET_LOCK('billing_data_ownership_v1', 15) AS lock_acquired");
    if (!$lockResult) {
        http_response_code(500);
        die('Gagal mengunci migrasi isolasi data: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    $lockRow = mysqli_fetch_assoc($lockResult);
    mysqli_free_result($lockResult);
    if ((int) ($lockRow['lock_acquired'] ?? 0) !== 1) {
        http_response_code(503);
        die('Migrasi isolasi data sedang berjalan. Silakan muat ulang halaman.');
    }

    $completionStmt = mysqli_prepare($connection, 'SELECT COUNT(DISTINCT migration_key) AS completed FROM app_schema_migrations WHERE migration_key IN (?, ?)');
    if (!$completionStmt) {
        http_response_code(500);
        die('Gagal memeriksa status migrasi isolasi data: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($completionStmt, 'ss', $migrationKey, $constraintsMigrationKey);
    if (!mysqli_stmt_execute($completionStmt)) {
        $error = mysqli_stmt_error($completionStmt);
        mysqli_stmt_close($completionStmt);
        http_response_code(500);
        die('Gagal memeriksa status migrasi isolasi data: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    $completionResult = mysqli_stmt_get_result($completionStmt);
    $migrationsComplete = (int) mysqli_fetch_assoc($completionResult)['completed'] === 2;
    mysqli_stmt_close($completionStmt);
    if ($migrationsComplete) {
        billing_release_data_ownership_lock($connection);
        return;
    }

    $migrationStmt = mysqli_prepare($connection, 'SELECT 1 FROM app_schema_migrations WHERE migration_key = ?');
    if (!$migrationStmt) {
        http_response_code(500);
        die('Gagal memeriksa migrasi isolasi data: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($migrationStmt, 's', $migrationKey);
    if (!mysqli_stmt_execute($migrationStmt)) {
        $error = mysqli_stmt_error($migrationStmt);
        mysqli_stmt_close($migrationStmt);
        http_response_code(500);
        die('Gagal memeriksa migrasi isolasi data: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    $migrationResult = mysqli_stmt_get_result($migrationStmt);
    $migrationApplied = mysqli_fetch_assoc($migrationResult) !== null;
    mysqli_stmt_close($migrationStmt);
    if (!$migrationApplied) {
        foreach (['datapelangganasa', 'omset', 'pengeluaran_tetap', 'pengeluaran_takterduga', 'arsip_bulanan'] as $table) {
            $columnResult = mysqli_query($connection, "SHOW COLUMNS FROM `$table` LIKE 'owner_admin_id'");
            if (!$columnResult) {
                http_response_code(500);
                die('Gagal memeriksa struktur tabel ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
            }
            $hasOwnerColumn = mysqli_num_rows($columnResult) > 0;
            mysqli_free_result($columnResult);
            if (!$hasOwnerColumn && !mysqli_query($connection, "ALTER TABLE `$table` ADD COLUMN owner_admin_id INT UNSIGNED NULL, ADD INDEX idx_owner_admin_id (owner_admin_id)")) {
                http_response_code(500);
                die('Gagal menambahkan pemilik data ke tabel ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
            }
        }

        $markStmt = mysqli_prepare($connection, 'INSERT INTO app_schema_migrations (migration_key) VALUES (?)');
        if (!$markStmt) {
            http_response_code(500);
            die('Gagal menyiapkan pencatatan migrasi isolasi data: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
        }
        mysqli_stmt_bind_param($markStmt, 's', $migrationKey);
        if (!mysqli_stmt_execute($markStmt)) {
            $error = mysqli_stmt_error($markStmt);
            mysqli_stmt_close($markStmt);
            http_response_code(500);
            die('Gagal menyimpan status migrasi isolasi data: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
        }
        mysqli_stmt_close($markStmt);
    }

    $checkConstraintsStmt = mysqli_prepare($connection, 'SELECT 1 FROM app_schema_migrations WHERE migration_key = ?');
    if (!$checkConstraintsStmt) {
        http_response_code(500);
        die('Gagal memeriksa batas data akun: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($checkConstraintsStmt, 's', $constraintsMigrationKey);
    if (!mysqli_stmt_execute($checkConstraintsStmt)) {
        $error = mysqli_stmt_error($checkConstraintsStmt);
        mysqli_stmt_close($checkConstraintsStmt);
        http_response_code(500);
        die('Gagal memeriksa batas data akun: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    $constraintsResult = mysqli_stmt_get_result($checkConstraintsStmt);
    $constraintsApplied = mysqli_fetch_assoc($constraintsResult) !== null;
    mysqli_stmt_close($checkConstraintsStmt);
    if ($constraintsApplied) {
        billing_release_data_ownership_lock($connection);
        return;
    }

    $indexes = [
        'datapelangganasa' => 'uq_owner_address',
        'omset' => 'uq_owner_customer_period',
    ];
    foreach ($indexes as $table => $newIndex) {
        $indexResult = mysqli_query($connection, "SHOW INDEX FROM `$table`");
        if (!$indexResult) {
            http_response_code(500);
            die('Gagal memeriksa index tabel ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
        }
        $uniqueGroups = [];
        while ($index = mysqli_fetch_assoc($indexResult)) {
            if ((int) $index['Non_unique'] === 0) {
                $uniqueGroups[$index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name'];
            }
        }
        mysqli_free_result($indexResult);

        $oldGroups = $table === 'datapelangganasa'
            ? [['alamat']]
            : [['customer_id', 'periode']];
        foreach ($uniqueGroups as $keyName => $columns) {
            ksort($columns);
            if (in_array(array_values($columns), $oldGroups, true)) {
                $quotedKey = str_replace('`', '``', $keyName);
                if (!mysqli_query($connection, "ALTER TABLE `$table` DROP INDEX `$quotedKey`")) {
                    http_response_code(500);
                    die('Gagal memperbarui index tabel ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
                }
            }
        }

        $targetColumns = $table === 'datapelangganasa'
            ? ['owner_admin_id', 'alamat']
            : ['owner_admin_id', 'customer_id', 'periode'];
        if (!in_array($targetColumns, array_map('array_values', $uniqueGroups), true) &&
            !mysqli_query($connection, "ALTER TABLE `$table` ADD UNIQUE KEY `$newIndex` (" . implode(', ', $targetColumns) . ')')) {
            http_response_code(500);
            die('Gagal membuat index data per akun di tabel ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
        }
    }

    $markConstraintsStmt = mysqli_prepare($connection, 'INSERT INTO app_schema_migrations (migration_key) VALUES (?)');
    if (!$markConstraintsStmt) {
        http_response_code(500);
        die('Gagal menyiapkan status migrasi index akun: ' . htmlspecialchars(mysqli_error($connection), ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_bind_param($markConstraintsStmt, 's', $constraintsMigrationKey);
    if (!mysqli_stmt_execute($markConstraintsStmt)) {
        $error = mysqli_stmt_error($markConstraintsStmt);
        mysqli_stmt_close($markConstraintsStmt);
        http_response_code(500);
        die('Gagal menyimpan status migrasi index akun: ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
    }
    mysqli_stmt_close($markConstraintsStmt);
    billing_release_data_ownership_lock($connection);
}

function billing_data_owner_id()
{
    if (!empty($_SESSION['is_superadmin'])) {
        return isset($_SESSION['acting_as_admin_id']) ? (int) $_SESSION['acting_as_admin_id'] : null;
    }

    return isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
}

function billing_data_owner_sql_value()
{
    $ownerId = billing_data_owner_id();
    return $ownerId === null ? 'NULL' : (string) (int) $ownerId;
}

function billing_data_scope_sql($alias = '', $column = 'owner_admin_id')
{
    if (!empty($_SESSION['is_superadmin']) && !isset($_SESSION['acting_as_admin_id'])) {
        return '';
    }

    $ownerId = billing_data_owner_id();
    $qualifiedColumn = $alias === '' ? $column : $alias . '.' . $column;
    if ($ownerId === null) {
        return ' AND 1=0';
    }

    return ' AND ' . $qualifiedColumn . ' = ' . (int) $ownerId;
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
