<?php
require __DIR__ . '/includes/bootstrap.php';
require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save') {
    csrf_check();
    $nama = trim($_POST['nama'] ?? '');
    if ($nama === '') {
        flash_set('Nama bidang wajib diisi.', 'error');
    } else {
        try {
            $pdo->prepare('INSERT INTO bidang_list (nama) VALUES (?)')->execute([$nama]);
            flash_set('Bidang ditambahkan.');
        } catch (PDOException $e) {
            flash_set('Nama bidang sudah ada di daftar.', 'error');
        }
    }
    redirect('kelola_bidang.php');
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pdo->prepare('DELETE FROM bidang_list WHERE id = ?')->execute([$_GET['id']]);
    flash_set('Bidang dihapus dari daftar.');
    redirect('kelola_bidang.php');
}

$rows = $pdo->query('SELECT * FROM bidang_list ORDER BY nama')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Kelola Bidang</h1><div class="sub">Daftar bidang/tujuan yang bisa dipilih admin biasa saat mencatat barang keluar</div></div>
</div>

<div class="card">
  <h3>Tambah bidang</h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="save">
    <div class="form-row">
      <div class="field"><label>Nama bidang / tujuan</label><input name="nama" required placeholder="Cth: Bidang Kepegawaian"></div>
    </div>
    <button class="btn btn-primary" type="submit">Tambah bidang</button>
  </form>
</div>

<div class="card">
  <h3>Daftar bidang</h3>
  <?php if (!$rows): ?>
    <div class="empty"><b>Belum ada bidang</b>Tambahkan bidang lewat form di atas. Admin biasa hanya bisa memilih dari daftar ini saat mencatat barang keluar.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Nama bidang</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $b): ?>
      <tr>
        <td><?= e($b['nama']) ?></td>
        <td class="actions-cell">
          <a class="icon-btn danger" href="?action=delete&id=<?= e($b['id']) ?>" onclick="return confirm('Hapus bidang ini dari daftar pilihan? Transaksi lama tetap menyimpan nama bidang sebelumnya.');">Hapus</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<div class="helptext">Menghapus bidang dari daftar tidak mengubah data transaksi lama yang sudah memakai bidang tersebut — hanya menghilangkannya dari pilihan ke depan.</div>
<?php require __DIR__ . '/includes/footer.php'; ?>