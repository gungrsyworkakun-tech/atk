<?php
require __DIR__ . '/includes/bootstrap.php';
require_bidang();

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE kode LIKE ? OR nama LIKE ? OR jenis LIKE ? ORDER BY nama');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $items = $stmt->fetchAll();
} else {
    $items = $pdo->query('SELECT * FROM items ORDER BY nama')->fetchAll();
}

$allSatuan = $pdo->query('SELECT * FROM item_satuan ORDER BY item_id, isi')->fetchAll();
$satuanByItem = [];
foreach ($allSatuan as $s) $satuanByItem[$s['item_id']][] = $s;

require __DIR__ . '/includes/header.php';
?>
<style>
  .cat-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:18px;flex-wrap:wrap;}
  .cat-search{flex:1;min-width:220px;position:relative;}
  .cat-search input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
    background:var(--paper-sunk);color:var(--text);font-size:13.5px;font-family:'Inter',Arial,sans-serif;box-sizing:border-box;}
  .cat-search input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .cat-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.55;pointer-events:none;}
  .cat-count{font-size:12px;color:var(--text-dim);white-space:nowrap;}

  .cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;}
  .cat-card{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
    overflow:hidden;box-shadow:var(--shadow-card);display:flex;flex-direction:column;
    transition:transform .15s var(--ease), box-shadow .15s var(--ease);}
  .cat-card:hover{transform:translateY(-2px);box-shadow:0 24px 48px -20px rgba(0,0,0,.45);}

  .cat-photo{width:100%;aspect-ratio:1/1;background:var(--paper-sunk);display:flex;align-items:center;
    justify-content:center;overflow:hidden;position:relative;}
  .cat-photo img{width:100%;height:100%;object-fit:cover;}
  .cat-photo-placeholder{color:var(--text-faint);font-size:11px;text-align:center;padding:10px;}
  .cat-stok-badge{position:absolute;top:8px;right:8px;font-size:10.5px;font-weight:700;padding:4px 9px;
    border-radius:20px;backdrop-filter:blur(4px);}
  .cat-stok-badge.ok{background:rgba(52,211,153,.85);color:#06281d;}
  .cat-stok-badge.low{background:rgba(245,158,11,.85);color:#2b1a02;}
  .cat-stok-badge.empty{background:rgba(248,113,113,.85);color:#2b0a08;}

  .cat-body{padding:13px 14px 14px;display:flex;flex-direction:column;gap:6px;flex:1;}
  .cat-name{font-size:13.5px;font-weight:700;line-height:1.3;min-height:35px;}
  .cat-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .cat-code{font-family:'IBM Plex Mono',monospace;font-size:10.5px;color:var(--text-dim);
    background:var(--paper-sunk);padding:2px 7px;border-radius:6px;}
  .cat-jenis{font-size:10.5px;color:var(--text-faint);}
  .cat-stok-line{font-size:12px;color:var(--text-dim);margin-top:2px;}
  .cat-stok-line b{color:var(--text);font-variant-numeric:tabular-nums;}
  .cat-satuan-alt{font-size:10.5px;color:var(--text-faint);margin-top:-2px;}

  .cat-action{margin-top:auto;padding-top:8px;}
  .cat-action .btn{width:100%;font-size:12.5px;padding:8px 10px;}
  .cat-action .btn[disabled]{opacity:.5;cursor:not-allowed;}
</style>

<div class="topline">
  <div><h1>Katalog Barang</h1><div class="sub">Lihat semua barang ATK yang tersedia beserta stoknya</div></div>
</div>

<div class="cat-toolbar">
  <form method="get" class="cat-search" style="margin:0;">
    <?= icon('search', 15) ?>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama, kode, atau jenis barang…">
  </form>
  <span class="cat-count"><?= count($items) ?> barang<?= $q !== '' ? ' ditemukan' : ' tersedia' ?></span>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="katalog.php" style="font-size:12.5px;padding:7px 12px;">Reset</a><?php endif; ?>
</div>

<?php if (!$items): ?>
  <div class="empty">
    <b><?= $q !== '' ? 'Barang tidak ditemukan' : 'Belum ada barang' ?></b>
    <?= $q !== '' ? 'Tidak ada barang yang cocok dengan pencarian "' . e($q) . '".' : 'Hubungi admin gudang untuk menambahkan data barang.' ?>
  </div>
<?php else: ?>
<div class="cat-grid">
  <?php foreach ($items as $it):
    $stok = (int)$it['stok'];
    $stokClass = $stok <= 0 ? 'empty' : ($stok <= 5 ? 'low' : 'ok');
    $stokLabel = $stok <= 0 ? 'Habis' : ($stok <= 5 ? 'Terbatas' : 'Tersedia');
    $altSatuan = $satuanByItem[$it['id']] ?? [];
  ?>
    <div class="cat-card">
      <div class="cat-photo">
        <?php if (!empty($it['foto'])): ?>
          <img src="serve_foto_barang.php?id=<?= (int)$it['id'] ?>" alt="Foto <?= e($it['nama']) ?>">
        <?php else: ?>
          <div class="cat-photo-placeholder"><?= icon('box', 26) ?><br>Tanpa foto</div>
        <?php endif; ?>
        <span class="cat-stok-badge <?= $stokClass ?>"><?= $stokLabel ?></span>
      </div>
      <div class="cat-body">
        <div class="cat-name"><?= e($it['nama']) ?></div>
        <div class="cat-meta">
          <span class="cat-code"><?= e($it['kode']) ?></span>
          <?php if ($it['jenis']): ?><span class="cat-jenis"><?= e($it['jenis']) ?></span><?php endif; ?>
        </div>
        <div class="cat-stok-line">Stok: <b><?= $stok ?></b> <?= e($it['satuan']) ?></div>
        <?php if ($altSatuan): ?>
          <div class="cat-satuan-alt">Tersedia juga: <?= e(implode(', ', array_map(fn($s) => $s['nama_satuan'], $altSatuan))) ?></div>
        <?php endif; ?>
        <div class="cat-action">
          <?php if ($stok > 0): ?>
            <a class="btn btn-primary" href="permintaan.php?item_id=<?= (int)$it['id'] ?>">Ajukan</a>
          <?php else: ?>
            <button class="btn btn-ghost" disabled>Stok Habis</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>