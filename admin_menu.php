<?php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<?php if (!empty($_SESSION['is_superadmin'])): ?>
<?php if (isset($_SESSION['acting_as_admin_id'])): ?>
<li class="nav-item">
    <span class="nav-link text-warning">Mode user: <?= htmlspecialchars($_SESSION['acting_as_admin_username'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?></span>
    <form method="post" action="admins.php" class="px-3 pb-2">
        <input type="hidden" name="action" value="stop_view_user">
        <input type="hidden" name="id" value="<?= (int) $_SESSION['acting_as_admin_id']; ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="btn btn-sm btn-light btn-block">Kembali ke admin utama</button>
    </form>
</li>
<?php endif; ?>
<li class="nav-item">
    <a class="nav-link" href="admins.php">
        <i class="fas fa-fw fa-users-cog"></i>
        <span>Kelola Admin</span>
    </a>
</li>
<li class="nav-item">
    <a class="nav-link" href="admin_activity.php">
        <i class="fas fa-fw fa-history"></i>
        <span>Log Aktivitas</span>
    </a>
</li>
<?php endif; ?>
