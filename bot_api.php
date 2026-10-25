<?php
require_once __DIR__ . '/admin_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function bot_api_respond($statusCode, $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function bot_api_bind($statement, $types, &$values)
{
    if ($types === '') {
        return true;
    }
    $references = [];
    foreach ($values as $key => &$value) {
        $references[$key] = &$value;
    }
    array_unshift($references, $types);
    return call_user_func_array([$statement, 'bind_param'], $references);
}

function bot_api_validate_payload($entity, $payload, $allowedFields)
{
    if (!is_array($payload) || array_is_list($payload)) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'Body harus berupa object JSON.']);
    }

    $unknown = array_diff(array_keys($payload), $allowedFields);
    if ($unknown) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'Field tidak dikenal: ' . implode(', ', $unknown)]);
    }
    if (!$payload) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'Tidak ada field untuk disimpan.']);
    }
    foreach ($payload as $field => $value) {
        if ($value === '') {
            $payload[$field] = null;
        }
    }

    foreach (['nominal', 'dibayar'] as $moneyField) {
        if (array_key_exists($moneyField, $payload) && $payload[$moneyField] !== null && $payload[$moneyField] !== '') {
            if (!is_numeric($payload[$moneyField]) || (float) $payload[$moneyField] < 0) {
                bot_api_respond(400, ['status' => 'error', 'message' => "$moneyField harus berupa angka nol atau lebih."]);
            }
            $payload[$moneyField] = (float) $payload[$moneyField];
        }
    }

    foreach (['tanggal', 'tanggal_pemasangan'] as $dateField) {
        if (array_key_exists($dateField, $payload) && $payload[$dateField] !== null && $payload[$dateField] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $payload[$dateField]);
            if (!$date || $date->format('Y-m-d') !== $payload[$dateField]) {
                bot_api_respond(400, ['status' => 'error', 'message' => "$dateField harus berformat YYYY-MM-DD."]);
            }
        }
    }

    if (array_key_exists('customer_id', $payload) && $payload['customer_id'] !== null && $payload['customer_id'] !== '') {
        if (filter_var($payload['customer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            bot_api_respond(400, ['status' => 'error', 'message' => 'customer_id harus berupa bilangan bulat positif.']);
        }
        $payload['customer_id'] = (int) $payload['customer_id'];
    }
    if (array_key_exists('periode', $payload) && $payload['periode'] !== null && $payload['periode'] !== '' &&
        preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $payload['periode']) !== 1) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'periode harus berformat YYYY-MM.']);
    }

    if ($entity === 'customers' && isset($payload['notlp']) && preg_match('/^[0-9+(). -]+$/', (string) $payload['notlp']) !== 1) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'Nomor telepon berisi karakter yang tidak valid.']);
    }
    if ($entity === 'invoices' && array_key_exists('dibayar', $payload) && array_key_exists('nominal', $payload) &&
        $payload['nominal'] !== null && $payload['nominal'] !== '' &&
        (float) $payload['dibayar'] > (float) $payload['nominal']) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'Jumlah dibayar tidak boleh melebihi nominal tagihan.']);
    }

    return $payload;
}

function bot_api_run_statement($connection, $sql, $types = '', $values = [])
{
    $statement = mysqli_prepare($connection, $sql);
    if (!$statement) {
        bot_api_respond(500, ['status' => 'error', 'message' => 'Gagal menyiapkan operasi database.']);
    }
    if ($types !== '' && !bot_api_bind($statement, $types, $values)) {
        mysqli_stmt_close($statement);
        bot_api_respond(500, ['status' => 'error', 'message' => 'Gagal menyiapkan parameter database.']);
    }
    if (!mysqli_stmt_execute($statement)) {
        $error = mysqli_stmt_error($statement);
        mysqli_stmt_close($statement);
        error_log('[bot_api] Query gagal: ' . $error);
        bot_api_respond(500, ['status' => 'error', 'message' => 'Operasi database gagal.']);
    }
    return $statement;
}

function bot_api_invoice_status(&$rows)
{
    foreach ($rows as &$row) {
        $nominal = $row['nominal'] === null ? null : (float) $row['nominal'];
        $paid = (float) ($row['dibayar'] ?? 0);
        $row['status_pembayaran'] = $nominal === null
            ? 'Nominal belum diisi'
            : ($paid >= $nominal ? 'Lunas' : ($paid > 0 ? 'Cicil' : 'Belum lunas'));
        $row['sisa_tagihan'] = $nominal === null ? null : max(0, $nominal - $paid);
    }
    unset($row);
}

function bot_api_ensure_due_invoices($connection, $dueDate)
{
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', substr($dueDate, 0, 7) . '-01');
    $period = $monthStart->format('Y-m');
    $closedCheck = bot_api_run_statement($connection, 'SELECT 1 FROM periode_bulanan WHERE periode = ?', 's', [$period]);
    $isClosed = mysqli_fetch_assoc(mysqli_stmt_get_result($closedCheck)) !== null;
    mysqli_stmt_close($closedCheck);
    if ($isClosed) {
        return;
    }

    $customers = mysqli_query(
        $connection,
        'SELECT id, nama, notlp, alamat, paket, tanggal_pemasangan FROM datapelangganasa
         WHERE tanggal_pemasangan IS NOT NULL AND YEAR(tanggal_pemasangan) > 0'
    );
    if (!$customers) {
        error_log('[bot_api] Gagal mengambil pelanggan untuk tagihan jatuh tempo: ' . mysqli_error($connection));
        bot_api_respond(500, ['status' => 'error', 'message' => 'Gagal menyiapkan tagihan jatuh tempo.']);
    }

    $insert = mysqli_prepare(
        $connection,
        "INSERT INTO omset (customer_id, nama, notlp, alamat, nominal, tanggal, paket, periode, dibayar)
         VALUES (?, ?, CAST(? AS CHAR), ?, 150000, ?, COALESCE(?, ''), ?, 0)
         ON DUPLICATE KEY UPDATE tanggal = VALUES(tanggal)"
    );
    if (!$insert) {
        mysqli_free_result($customers);
        bot_api_respond(500, ['status' => 'error', 'message' => 'Gagal menyiapkan tagihan jatuh tempo.']);
    }

    while ($customer = mysqli_fetch_assoc($customers)) {
        $installationDate = DateTimeImmutable::createFromFormat('!Y-m-d', $customer['tanggal_pemasangan']);
        if (!$installationDate) {
            mysqli_stmt_close($insert);
            mysqli_free_result($customers);
            error_log('[bot_api] Tanggal pemasangan pelanggan tidak valid.');
            bot_api_respond(500, ['status' => 'error', 'message' => 'Tanggal pemasangan pelanggan tidak valid.']);
        }
        if ($installationDate->modify('first day of next month') > $monthStart) {
            continue;
        }

        $day = min((int) $installationDate->format('j'), (int) $monthStart->format('t'));
        $calculatedDueDate = $monthStart
            ->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('n'), $day)
            ->format('Y-m-d');
        if ($calculatedDueDate !== $dueDate) {
            continue;
        }

        $customerId = (int) $customer['id'];
        $name = $customer['nama'] ?? '';
        $phone = $customer['notlp'] ?? '';
        $address = $customer['alamat'] ?? '';
        $package = $customer['paket'] ?? '';
        mysqli_stmt_bind_param($insert, 'issssss', $customerId, $name, $phone, $address, $calculatedDueDate, $package, $period);
        if (!mysqli_stmt_execute($insert)) {
            $error = mysqli_stmt_error($insert);
            mysqli_stmt_close($insert);
            mysqli_free_result($customers);
            error_log('[bot_api] Gagal membuat tagihan jatuh tempo: ' . $error);
            bot_api_respond(500, ['status' => 'error', 'message' => 'Gagal membuat tagihan jatuh tempo.']);
        }
    }

    mysqli_stmt_close($insert);
    mysqli_free_result($customers);
}

$requestToken = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $requestToken, $tokenMatch) !== 1) {
    bot_api_respond(401, ['status' => 'error', 'message' => 'Token API tidak ditemukan.']);
}
$configuredToken = getenv('BILLING_BOT_API_TOKEN');
if ($configuredToken === false || strlen($configuredToken) < 32) {
    bot_api_respond(503, ['status' => 'error', 'message' => 'Token API belum dikonfigurasi dengan benar.']);
}
if (!hash_equals($configuredToken, $tokenMatch[1])) {
    bot_api_respond(401, ['status' => 'error', 'message' => 'Token API tidak valid.']);
}

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
if (!$isHttps) {
    bot_api_respond(400, ['status' => 'error', 'message' => 'API hanya menerima koneksi HTTPS.']);
}

$entity = (string) ($_GET['entity'] ?? '');
$entities = [
    'customers' => [
        'table' => 'datapelangganasa',
        'fields' => ['nama', 'notlp', 'alamat', 'paket', 'tanggal_pemasangan'],
        'order' => 'id DESC',
    ],
    'invoices' => [
        'table' => 'omset',
        'fields' => ['customer_id', 'nama', 'notlp', 'alamat', 'nominal', 'tanggal', 'paket', 'periode', 'dibayar'],
        'order' => 'tanggal DESC, id DESC',
    ],
    'fixed-expenses' => [
        'table' => 'pengeluaran_tetap',
        'fields' => ['nama_pengeluaran', 'keterangan', 'nominal', 'tanggal'],
        'order' => 'tanggal DESC, id DESC',
    ],
    'unexpected-expenses' => [
        'table' => 'pengeluaran_takterduga',
        'fields' => ['nama_pengeluaran', 'keterangan', 'nominal', 'tanggal'],
        'order' => 'tanggal DESC, id DESC',
    ],
];
if (!isset($entities[$entity])) {
    bot_api_respond(400, ['status' => 'error', 'message' => 'Entity tidak didukung.']);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, POST, PUT, DELETE');
    bot_api_respond(405, ['status' => 'error', 'message' => 'HTTP method tidak didukung.']);
}
$apiAction = (string) ($_GET['action'] ?? '');
if ($apiAction !== '' && ($method !== 'POST' || $entity !== 'invoices' || $apiAction !== 'ensure_due')) {
    bot_api_respond(400, ['status' => 'error', 'message' => 'Aksi API tidak didukung.']);
}

$connection = billing_db_connect();
$entityConfig = $entities[$entity];
$table = $entityConfig['table'];
$fields = $entityConfig['fields'];
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($apiAction === 'ensure_due') {
    $dueDate = (string) ($_GET['due'] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
    if (!$date || $date->format('Y-m-d') !== $dueDate) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'Tanggal jatuh tempo harus berformat YYYY-MM-DD.']);
    }
    bot_api_ensure_due_invoices($connection, $dueDate);
    bot_api_respond(200, ['status' => 'success', 'message' => 'Tagihan jatuh tempo sudah disiapkan.']);
}

if ($method === 'GET') {
    $where = [];
    $types = '';
    $values = [];
    if ($id !== false && $id !== null) {
        $where[] = 'id = ?';
        $types .= 'i';
        $values[] = $id;
    } elseif (isset($_GET['id'])) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'ID harus berupa bilangan bulat positif.']);
    }

    if ($entity === 'invoices' && isset($_GET['status']) && $_GET['status'] === 'unpaid') {
        $where[] = 'nominal IS NOT NULL AND COALESCE(dibayar, 0) < nominal';
    } elseif (isset($_GET['status']) && $_GET['status'] !== '') {
        bot_api_respond(400, ['status' => 'error', 'message' => 'Filter status hanya tersedia untuk tagihan dengan nilai unpaid.']);
    }
    if (isset($_GET['due'])) {
        $dueDate = (string) $_GET['due'];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        if ($entity !== 'invoices' || !$date || $date->format('Y-m-d') !== $dueDate) {
            bot_api_respond(400, ['status' => 'error', 'message' => 'Filter due hanya menerima tanggal YYYY-MM-DD pada tagihan.']);
        }
        $where[] = 'tanggal = ?';
        $types .= 's';
        $values[] = $dueDate;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countStatement = bot_api_run_statement($connection, "SELECT COUNT(*) AS total FROM `$table`$whereSql", $types, $values);
    $countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStatement));
    mysqli_stmt_close($countStatement);
    $total = (int) $countRow['total'];
    $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) : 1000;
    if ($limit === false) {
        bot_api_respond(400, ['status' => 'error', 'message' => 'limit harus berupa bilangan bulat antara 1 dan 10000.']);
    }
    $sql = "SELECT * FROM `$table`$whereSql ORDER BY {$entityConfig['order']} LIMIT ?";
    $queryValues = $values;
    $queryValues[] = $limit;
    $statement = bot_api_run_statement($connection, $sql, $types . 'i', $queryValues);
    $result = mysqli_stmt_get_result($statement);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    mysqli_stmt_close($statement);
    if ($entity === 'invoices') {
        bot_api_invoice_status($data);
    }
    if ($id !== false && $id !== null && !$data) {
        bot_api_respond(404, ['status' => 'error', 'message' => 'Data tidak ditemukan.']);
    }
    bot_api_respond(200, ['status' => 'success', 'total' => $total, 'data' => $id ? $data[0] : $data]);
}

$rawBody = file_get_contents('php://input');
$body = $rawBody === '' ? [] : json_decode($rawBody, true);
if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
    bot_api_respond(400, ['status' => 'error', 'message' => 'Body harus berupa JSON valid.']);
}

if ($method === 'POST') {
    $payload = bot_api_validate_payload($entity, $body, $fields);
    if ($entity === 'invoices') {
        $nominal = $payload['nominal'] ?? null;
        $paid = $payload['dibayar'] ?? 0;
        if ((float) $paid > 0 && ($nominal === null || $nominal === '' || (float) $paid > (float) $nominal)) {
            bot_api_respond(400, ['status' => 'error', 'message' => 'Jumlah dibayar tidak boleh melebihi nominal tagihan.']);
        }
        if (!empty($payload['customer_id'])) {
            $customerCheck = bot_api_run_statement($connection, 'SELECT id FROM datapelangganasa WHERE id = ?', 'i', [$payload['customer_id']]);
            $customerExists = mysqli_fetch_assoc(mysqli_stmt_get_result($customerCheck)) !== null;
            mysqli_stmt_close($customerCheck);
            if (!$customerExists) {
                bot_api_respond(400, ['status' => 'error', 'message' => 'customer_id tidak ditemukan.']);
            }
        }
    }
    $columns = array_keys($payload);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnSql = implode(', ', array_map(static fn($column) => "`$column`", $columns));
    $types = str_repeat('s', count($columns));
    $values = array_values($payload);
    $statement = bot_api_run_statement($connection, "INSERT INTO `$table` ($columnSql) VALUES ($placeholders)", $types, $values);
    $newId = mysqli_insert_id($connection);
    mysqli_stmt_close($statement);
    bot_api_respond(201, ['status' => 'success', 'id' => $newId, 'message' => 'Data berhasil dibuat.']);
}

if ($id === false || $id === null) {
    bot_api_respond(400, ['status' => 'error', 'message' => 'ID harus berupa bilangan bulat positif.']);
}

if ($method === 'PUT') {
    $payload = bot_api_validate_payload($entity, $body, $fields);
    if ($entity === 'invoices' && (array_key_exists('dibayar', $payload) || array_key_exists('nominal', $payload))) {
        $check = bot_api_run_statement($connection, 'SELECT nominal, dibayar FROM omset WHERE id = ?', 'i', [$id]);
        $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
        mysqli_stmt_close($check);
        if (!$invoice) {
            bot_api_respond(404, ['status' => 'error', 'message' => 'Tagihan tidak ditemukan.']);
        }
        $nominal = array_key_exists('nominal', $payload) ? $payload['nominal'] : $invoice['nominal'];
        $paid = array_key_exists('dibayar', $payload) ? $payload['dibayar'] : $invoice['dibayar'];
        if (($nominal === null || $nominal === '') ? (float) $paid > 0 : (float) $paid > (float) $nominal) {
            bot_api_respond(400, ['status' => 'error', 'message' => 'Jumlah dibayar tidak boleh melebihi nominal tagihan.']);
        }
    }
    if ($entity === 'invoices' && array_key_exists('customer_id', $payload) && !empty($payload['customer_id'])) {
        $customerCheck = bot_api_run_statement($connection, 'SELECT id FROM datapelangganasa WHERE id = ?', 'i', [$payload['customer_id']]);
        $customerExists = mysqli_fetch_assoc(mysqli_stmt_get_result($customerCheck)) !== null;
        mysqli_stmt_close($customerCheck);
        if (!$customerExists) {
            bot_api_respond(400, ['status' => 'error', 'message' => 'customer_id tidak ditemukan.']);
        }
    }

    $columns = array_keys($payload);
    $assignments = implode(', ', array_map(static fn($column) => "`$column` = ?", $columns));
    $values = array_values($payload);
    $values[] = $id;
    $statement = bot_api_run_statement($connection, "UPDATE `$table` SET $assignments WHERE id = ?", str_repeat('s', count($columns)) . 'i', $values);
    $affected = mysqli_stmt_affected_rows($statement);
    mysqli_stmt_close($statement);
    if ($affected === 0) {
        $check = bot_api_run_statement($connection, "SELECT id FROM `$table` WHERE id = ?", 'i', [$id]);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($check)) !== null;
        mysqli_stmt_close($check);
        if (!$exists) {
            bot_api_respond(404, ['status' => 'error', 'message' => 'Data tidak ditemukan.']);
        }
    }
    bot_api_respond(200, ['status' => 'success', 'message' => 'Data berhasil diperbarui.']);
}

if ($method === 'DELETE') {
    if ($entity === 'invoices') {
        $check = bot_api_run_statement($connection, 'SELECT customer_id FROM omset WHERE id = ?', 'i', [$id]);
        $invoice = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
        mysqli_stmt_close($check);
        if (!$invoice) {
            bot_api_respond(404, ['status' => 'error', 'message' => 'Tagihan tidak ditemukan.']);
        }
        if ($invoice['customer_id'] !== null) {
            bot_api_respond(409, ['status' => 'error', 'message' => 'Tagihan bulanan otomatis tidak dapat dihapus.']);
        }
    }
    if ($entity === 'customers') {
        $check = bot_api_run_statement($connection, 'SELECT COUNT(*) AS total FROM omset WHERE customer_id = ?', 'i', [$id]);
        $linked = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
        mysqli_stmt_close($check);
        if ((int) $linked['total'] > 0) {
            bot_api_respond(409, ['status' => 'error', 'message' => 'Pelanggan masih memiliki riwayat tagihan; hapus tidak diizinkan.']);
        }
    }

    $statement = bot_api_run_statement($connection, "DELETE FROM `$table` WHERE id = ?", 'i', [$id]);
    $affected = mysqli_stmt_affected_rows($statement);
    mysqli_stmt_close($statement);
    if ($affected < 1) {
        bot_api_respond(404, ['status' => 'error', 'message' => 'Data tidak ditemukan.']);
    }
    bot_api_respond(200, ['status' => 'success', 'message' => 'Data berhasil dihapus.']);
}
