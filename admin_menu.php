<?php if (!empty($_SESSION['is_superadmin'])): ?>
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
