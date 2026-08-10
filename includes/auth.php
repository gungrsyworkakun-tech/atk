<?php

function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_logged_in() {
    return current_user() !== null;
}

function is_super() {
    $u = current_user();
    return $u && $u['role'] === 'super';
}

function is_bidang() {
    $u = current_user();
    return $u && $u['role'] === 'bidang';
}

function has_permission($moduleKey) {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'super') return true;
    return !empty($u['permissions'][$moduleKey]);
}

function require_login() {
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function require_permission($moduleKey) {
    require_login();
    if (!has_permission($moduleKey)) {
        http_response_code(403);
        require __DIR__ . '/../includes/header.php';
        echo '<div class="card"><div class="empty"><b>Akses ditolak</b>Anda tidak memiliki izin untuk membuka menu ini. Hubungi super admin jika ini keliru.</div></div>';
        require __DIR__ . '/../includes/footer.php';
        exit;
    }
}

function require_super() {
    require_login();
    if (!is_super()) {
        http_response_code(403);
        require __DIR__ . '/../includes/header.php';
        echo '<div class="card"><div class="empty"><b>Akses ditolak</b>Halaman ini khusus super admin.</div></div>';
        require __DIR__ . '/../includes/footer.php';
        exit;
    }
}

function require_bidang() {
    require_login();
    if (!is_bidang()) {
        http_response_code(403);
        require __DIR__ . '/../includes/header.php';
        echo '<div class="card"><div class="empty"><b>Akses ditolak</b>Halaman ini khusus untuk akun Admin Bidang.</div></div>';
        require __DIR__ . '/../includes/footer.php';
        exit;
    }
}

/** Bikin superadmin default sekali saja kalau tabel users masih kosong. */
function ensure_default_superadmin($pdo) {
    $count = $pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
    if ((int)$count === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password, role, permissions) VALUES (?, ?, ?, ?)');
        $stmt->execute(['superadmin', $hash, 'super', null]);
    }
}