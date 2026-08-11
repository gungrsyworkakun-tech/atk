<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('barcode');

$items = $pdo->query('SELECT * FROM items ORDER BY nama')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<style>
  .bc-print-header{display:none;}
  @media print {
    .bc-print-header{
      display:flex;align-items:center;gap:12px;
      padding:0 0 16px;margin-bottom:16px;border-bottom:2px solid #1B2A3D;
    }
    .bc-print-header img{width:40px;height:40px;border-radius:9px;flex-shrink:0;}
    .bc-print-header .ph-name{font-family:'Space Grotesk',Arial,sans-serif;font-size:17px;font-weight:700;color:#1B2A3D;}
    .bc-print-header .ph-sub{font-family:'IBM Plex Mono',monospace;font-size:10.5px;color:#5B6459;letter-spacing:.08em;text-transform:uppercase;margin-top:1px;}
    .bc-print-header .ph-date{margin-left:auto;font-size:11px;color:#5B6459;}
  }
  .bc-empty-search{display:none;}
</style>

<div class="topline">
  <div style="display:flex;align-items:center;gap:12px;">
    <img src="assets/1.png?v=<?= @filemtime(__DIR__ . '/assets/1.png') ?: time() ?>" alt="Logo" style="width:34px;height:34px;border-radius:9px;flex-shrink:0;">
    <div>
      <h1>Barcode Barang</h1>
      <div class="sub">Unduh atau cetak barcode untuk setiap kode barang</div>
    </div>
  </div>
</div>

<?php if (!$items): ?>
  <div class="card"><div class="empty"><b>Belum ada barang</b>Tambahkan barang di menu Data Barang untuk membuat barcode.</div></div>
<?php else: ?>

<div class="barcode-toolbar" style="justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div class="bast-search" style="max-width:340px;">
    <?= icon('search', 15) ?>
    <input type="text" id="bc-search" placeholder="Cari nama atau kode barang…" autocomplete="off">
  </div>
  <button type="button" class="btn btn-primary" id="btn-print-all"><?= icon('file', 15) ?> Cetak Semua Barcode</button>
</div>

<div class="bc-print-header">
  <img src="assets/1.png?v=<?= @filemtime(__DIR__ . '/assets/1.png') ?: time() ?>" alt="Logo">
  <div>
    <div class="ph-name">Pendataan ATK</div>
    <div class="ph-sub">Sistem Inventaris</div>
  </div>
  <div class="ph-date">Dicetak: <?= date('d M Y H:i') ?></div>
</div>

<div class="barcode-grid" id="bc-grid">
  <?php foreach ($items as $it): ?>
    <div class="bc-card" data-search="<?= e(mb_strtolower($it['nama'] . ' ' . $it['kode'])) ?>">
      <button type="button" class="bc-print-btn js-print-one" data-url="barcode_image.php?kode=<?= e(urlencode($it['kode'])) ?>" title="Cetak barcode ini">
        <?= icon('file', 15) ?>
      </button>
      <div class="nm"><?= e($it['nama']) ?></div>
      <img src="barcode_image.php?kode=<?= e(urlencode($it['kode'])) ?>" alt="Barcode <?= e($it['kode']) ?>">
      <div class="stok">Stok: <?= (int)$it['stok'] ?></div>
      <div style="display:flex;gap:6px;">
        <a class="btn btn-ghost" style="flex:1;font-size:12.5px;" href="barcode_image.php?kode=<?= e(urlencode($it['kode'])) ?>&download=1">Unduh PNG</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="empty bc-empty-search" id="bc-empty-search">
  <b>Tidak ditemukan</b>Tidak ada barang yang cocok dengan pencarian.
</div>

<script>
  document.getElementById('btn-print-all').addEventListener('click', function () {
    window.print();
  });

  document.querySelectorAll('.js-print-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-url');
      var w = window.open('', '_blank', 'width=420,height=560');
      if (!w) { alert('Popup diblokir browser. Izinkan popup untuk situs ini agar bisa mencetak.'); return; }
      w.document.write(
        '<!DOCTYPE html><html><head><title>Cetak Barcode</title>' +
        '<style>body{margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fff;}' +
        'img{max-width:90%;}</style></head><body>' +
        '<img src="' + url + '" onload="window.focus();window.print();">' +
        '</body></html>'
      );
      w.document.close();
    });
  });

  // ---------- Pencarian barcode (filter langsung tanpa reload) ----------
  (function () {
    var searchInput = document.getElementById('bc-search');
    var grid = document.getElementById('bc-grid');
    var emptyState = document.getElementById('bc-empty-search');
    if (!searchInput || !grid) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.bc-card'));

    searchInput.addEventListener('input', function () {
      var q = searchInput.value.trim().toLowerCase();
      var visibleCount = 0;

      cards.forEach(function (card) {
        var match = q === '' || card.dataset.search.indexOf(q) !== -1;
        card.hidden = !match;
        if (match) visibleCount++;
      });

      emptyState.style.display = (visibleCount === 0) ? 'block' : 'none';
      grid.style.display = (visibleCount === 0) ? 'none' : '';
    });
  })();
</script>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>