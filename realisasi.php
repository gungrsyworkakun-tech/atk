<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('realisasi');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'set_limit') {
    csrf_check();
    $id = (int)$_POST['id'];
    $min = max(0, (int)$_POST['stok_minimum']);
    $pdo->prepare('UPDATE items SET stok_minimum = ? WHERE id = ?')->execute([$min, $id]);
    flash_set('Limit stok diperbarui.');
    redirect('realisasi.php');
}

$items = $pdo->query('
    SELECT i.*,
      COALESCE((SELECT SUM(jumlah) FROM transactions WHERE item_id = i.id AND tipe = "masuk"), 0) AS total_masuk,
      COALESCE((SELECT SUM(jumlah) FROM transactions WHERE item_id = i.id AND tipe = "keluar"), 0) AS total_keluar
    FROM items i ORDER BY i.nama
')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Realisasi & Limit Stok</h1><div class="sub">Jumlah barang yang sudah terealisasi (keluar) dan batas stok minimum</div></div>
</div>
<div class="card">
  <?php if (!$items): ?>
    <div class="empty"><b>Belum ada barang</b>Tambahkan barang untuk memantau realisasi.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Barang</th><th>Masuk total</th><th>Terealisasi (keluar)</th><th>Sisa stok</th><th>Limit minimum</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $it):
        $stok = (int)$it['stok']; $min = (int)$it['stok_minimum'];
        if ($min > 0 && $stok <= $min) { $tag = '<span class="tag tag-danger">Stok menipis</span>'; }
        elseif ($min > 0 && $stok <= $min * 1.5) { $tag = '<span class="tag tag-warn">Perlu dipantau</span>'; }
        else { $tag = '<span class="tag tag-ok">Aman</span>'; }
      ?>
      <tr>
        <td><?= e($it['nama']) ?></td>
        <td class="mono"><?= (int)$it['total_masuk'] ?></td>
        <td class="mono"><?= (int)$it['total_keluar'] ?></td>
        <td class="mono"><?= $stok ?></td>
        <td class="mono"><?= $min ?></td>
        <td><?= $tag ?></td>
        <td>
          <details>
            <summary class="icon-btn" style="display:inline;cursor:pointer;">Atur limit</summary>
            <form method="post" style="margin-top:8px;display:flex;gap:6px;">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="do" value="set_limit">
              <input type="hidden" name="id" value="<?= e($it['id']) ?>">
              <input type="number" min="0" name="stok_minimum" value="<?= $min ?>" style="width:80px;padding:6px;border:1px solid var(--line);border-radius:3px;">
              <button class="btn btn-primary" style="padding:6px 10px;font-size:12px;" type="submit">Simpan</button>
            </form>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
