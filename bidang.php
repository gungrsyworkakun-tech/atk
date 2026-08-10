<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('bidang');

$rows = $pdo->query("
    SELECT t.*, i.nama FROM transactions t
    LEFT JOIN items i ON i.id = t.item_id
    WHERE t.tipe = 'keluar' AND t.bidang IS NOT NULL AND t.bidang <> ''
    ORDER BY t.bidang, t.tanggal DESC
")->fetchAll();

$byBidang = [];
foreach ($rows as $r) $byBidang[$r['bidang']][] = $r;

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Bidang Pengambilan</h1><div class="sub">Bidang mana saja yang mengambil barang, dan dari stok apa</div></div>
</div>
<?php if (!$byBidang): ?>
  <div class="card"><div class="empty"><b>Belum ada data pengambilan</b>Isi kolom "Bidang pengambil" saat mencatat transaksi keluar.</div></div>
<?php else: foreach ($byBidang as $bidang => $trxList): ?>
  <div class="card">
    <h3><?= e($bidang) ?></h3>
    <table>
      <thead><tr><th>Tanggal</th><th>Barang diambil</th><th>Jumlah</th></tr></thead>
      <tbody>
        <?php foreach ($trxList as $t): ?>
        <tr>
          <td class="mono"><?= e($t['tanggal']) ?></td>
          <td><?= e($t['nama'] ?? '(dihapus)') ?></td>
          <td class="mono"><?= (int)$t['jumlah'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
