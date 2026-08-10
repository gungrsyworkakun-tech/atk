<?php
require __DIR__ . '/includes/bootstrap.php';
require_super();

$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'create') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $perms = [];
    foreach (array_keys(MODULES) as $key) $perms[$key] = isset($_POST['perm'][$key]);

    if ($username === '' || strlen($password) < 4) {
        flash_set('Nama pengguna wajib diisi dan kata sandi minimal 4 karakter.', 'error');
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            flash_set('Nama pengguna sudah dipakai.', 'error');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (username, password, role, permissions) VALUES (?,?,?,?)')
                ->execute([$username, $hash, 'admin', json_encode($perms)]);
            flash_set('Admin baru ditambahkan.');
        }
    }
    redirect('kelola_admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'update_perms') {
    csrf_check();
    $id = (int)$_POST['id'];
    $perms = [];
    foreach (array_keys(MODULES) as $key) $perms[$key] = isset($_POST['perm'][$key]);
    $pdo->prepare('UPDATE users SET permissions = ? WHERE id = ? AND role = "admin"')->execute([json_encode($perms), $id]);
    flash_set('Hak akses admin diperbarui.');
    redirect('kelola_admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'create_bidang') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $bidangNama = trim($_POST['bidang_nama'] ?? '');

    if ($username === '' || strlen($password) < 4 || $bidangNama === '') {
        flash_set('Nama pengguna, kata sandi (min. 4 karakter), dan bidang wajib diisi.', 'error');
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            flash_set('Nama pengguna sudah dipakai.', 'error');
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (username, password, role, bidang_nama) VALUES (?,?,?,?)')
                ->execute([$username, $hash, 'bidang', $bidangNama]);
            flash_set('Akun Admin Bidang ditambahkan.');
        }
    }
    redirect('kelola_admin.php');
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "admin"')->execute([$_GET['id']]);
    flash_set('Admin dihapus.');
    redirect('kelola_admin.php');
}

if (($_GET['action'] ?? '') === 'delete_bidang' && isset($_GET['id'])) {
    $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "bidang"')->execute([$_GET['id']]);
    flash_set('Akun Admin Bidang dihapus.');
    redirect('kelola_admin.php');
}

if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND role = "admin"');
    $stmt->execute([$_GET['id']]);
    $editing = $stmt->fetch();
}

$admins = $pdo->query('SELECT * FROM users WHERE role = "admin" ORDER BY username')->fetchAll();
$bidangUsers = $pdo->query('SELECT * FROM users WHERE role = "bidang" ORDER BY username')->fetchAll();
$bidangListOpt = $pdo->query('SELECT nama FROM bidang_list ORDER BY nama')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Kelola Admin</h1><div class="sub">Tambah admin biasa/admin bidang dan atur menu yang bisa mereka akses</div></div>
</div>

<?php if ($editing): $ep = decode_permissions($editing['permissions']); ?>
<div class="card">
  <h3>Ubah akses — <?= e($editing['username']) ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="update_perms">
    <input type="hidden" name="id" value="<?= e($editing['id']) ?>">
    <div class="perm-grid">
      <?php foreach (MODULES as $key => $label): ?>
        <label class="perm-item"><input type="checkbox" name="perm[<?= e($key) ?>]" <?= !empty($ep[$key]) ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-primary" type="submit">Simpan akses</button>
      <a class="btn btn-ghost" href="kelola_admin.php">Batal</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Tambah admin biasa</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="create">
    <div class="form-row">
      <div class="field"><label>Nama pengguna</label><input name="username" required></div>
      <div class="field"><label>Kata sandi</label><input name="password" required></div>
    </div>
    <label style="font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;">Menu yang bisa diakses</label>
    <div class="perm-grid">
      <?php foreach (MODULES as $key => $label): ?>
        <label class="perm-item"><input type="checkbox" name="perm[<?= e($key) ?>]"> <?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary" type="submit">Tambah admin</button>
  </form>
</div>

<div class="card">
  <h3>Daftar admin biasa</h3>
  <?php if (!$admins): ?>
    <div class="empty"><b>Belum ada admin biasa</b>Tambahkan akun admin dan pilih menu yang boleh mereka kelola.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Nama pengguna</th><th>Akses menu</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($admins as $adm): $p = decode_permissions($adm['permissions']); ?>
      <tr>
        <td><?= e($adm['username']) ?></td>
        <td>
          <?php $any = false; foreach (MODULES as $key => $label): if (!empty($p[$key])): $any = true; ?>
            <span class="tag tag-ok" style="margin:2px 3px 2px 0;"><?= e($label) ?></span>
          <?php endif; endforeach; if (!$any): ?>
            <span style="color:var(--text-dim);font-size:12.5px;">Belum ada akses</span>
          <?php endif; ?>
        </td>
        <td class="actions-cell">
          <a class="icon-btn" href="?action=edit&id=<?= e($adm['id']) ?>">Ubah akses</a>
          <a class="icon-btn danger" href="?action=delete&id=<?= e($adm['id']) ?>" onclick="return confirm('Hapus akun admin ini?');">Hapus</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Tambah Admin Bidang</h3>
  <p class="helptext" style="margin-top:0;">Akun khusus untuk bidang mengajukan permintaan ATK. Menu bidang (Permintaan ATK, Progres ATK, Input BAST) otomatis tersedia tanpa perlu atur hak akses.</p>
  <?php if (!$bidangListOpt): ?>
    <div class="empty"><b>Belum ada bidang</b>Tambahkan bidang lewat menu Kelola Bidang terlebih dahulu.</div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="create_bidang">
    <div class="form-row">
      <div class="field"><label>Nama pengguna</label><input name="username" required></div>
      <div class="field"><label>Kata sandi</label><input name="password" required></div>
      <div class="field">
        <label>Bidang</label>
        <select name="bidang_nama" required>
          <option value="">— Pilih bidang —</option>
          <?php foreach ($bidangListOpt as $b): ?>
            <option value="<?= e($b['nama']) ?>"><?= e($b['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">Tambah Admin Bidang</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Daftar Admin Bidang</h3>
  <?php if (!$bidangUsers): ?>
    <div class="empty"><b>Belum ada Admin Bidang</b>Tambahkan lewat form di atas.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Nama pengguna</th><th>Bidang</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($bidangUsers as $bu): ?>
      <tr>
        <td><?= e($bu['username']) ?></td>
        <td><?= e($bu['bidang_nama']) ?></td>
        <td class="actions-cell">
          <a class="icon-btn danger" href="?action=delete_bidang&id=<?= e($bu['id']) ?>" onclick="return confirm('Hapus akun Admin Bidang ini?');">Hapus</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>