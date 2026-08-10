<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('harga');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'set_harga') {
    csrf_check();
    $id = (int)$_POST['id'];
    $harga = max(0, (float)$_POST['harga']);
    $pdo->prepare('UPDATE items SET harga = ? WHERE id = ?')->execute([$harga, $id]);
    flash_set('Harga diperbarui.');
    redirect('harga.php');
}

$items = $pdo->query('SELECT * FROM items ORDER BY nama')->fetchAll();
$totalNilai = $pdo->query('SELECT COALESCE(SUM(stok*harga),0) c FROM items')->fetch()['c'];

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Harga Barang</h1><div class="sub">Daftar harga satuan dan nilai total stok</div></div>
</div>
<div class="card">
  <?php if (!$items): ?>
    <div class="empty"><b>Belum ada barang</b>Tambahkan barang untuk mencatat harga.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Barang</th><th>Harga satuan</th><th>Stok</th><th>Nilai total</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['nama']) ?></td>
        <td class="mono"><?= format_rupiah($it['harga']) ?></td>
        <td class="mono"><?= (int)$it['stok'] ?></td>
        <td class="mono"><?= format_rupiah($it['stok'] * $it['harga']) ?></td>
        <td>
          <details>
            <summary class="icon-btn" style="display:inline;cursor:pointer;">Ubah harga</summary>
            <form method="post" style="margin-top:8px;display:flex;gap:6px;">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="do" value="set_harga">
              <input type="hidden" name="id" value="<?= e($it['id']) ?>">
              <input type="number" min="0" step="1" name="harga" value="<?= (float)$it['harga'] ?>" style="width:110px;padding:6px;border:1px solid var(--line);border-radius:3px;">
              <button class="btn btn-primary" style="padding:6px 10px;font-size:12px;" type="submit">Simpan</button>
            </form>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3" style="text-align:right;font-weight:700;">Total nilai stok</td><td class="mono" style="font-weight:700;"><?= format_rupiah($totalNilai) ?></td><td></td></tr></tfoot>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
