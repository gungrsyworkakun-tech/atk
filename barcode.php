<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('barcode');

$items = $pdo->query('SELECT * FROM items ORDER BY nama')->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<style>
  .barcode-toolbar{display:flex;justify-content:flex-end;margin-bottom:14px;}
  .bc-card{position:relative;}
  .bc-print-btn{position:absolute;top:10px;right:10px;background:var(--panel,#12161c);border:1px solid var(--line,#232a35);border-radius:6px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:inherit;}
  .bc-print-btn:hover{background:var(--line,#232a35);}

  @media print {
    .sidebar, .topbar, .barcode-toolbar, .topline, .bc-print-btn, .flash { display:none !important; }
    .content-col, .main { margin:0 !important; padding:0 !important; }
    .barcode-grid{ display:grid !important; grid-template-columns:repeat(3,1fr) !important; gap:12px !important; }
    .bc-card{ border:none !important; box-shadow:none !important; break-inside:avoid; page-break-inside:avoid; }
    .bc-card .btn, .bc-card .stok, .bc-card .nm{ display:none !important; }
    body, .app{ background:#fff !important; }
  }
</style>

<div class="topline">
  <div><h1>Barcode Barang</h1><div class="sub">Unduh atau cetak barcode untuk setiap kode barang</div></div>
</div>

<?php if (!$items): ?>
  <div class="card"><div class="empty"><b>Belum ada barang</b>Tambahkan barang di menu Data Barang untuk membuat barcode.</div></div>
<?php else: ?>

<div class="barcode-toolbar">
  <button type="button" class="btn btn-primary" id="btn-print-all"><?= icon('file', 15) ?> Cetak Semua Barcode</button>
</div>

<div class="barcode-grid">
  <?php foreach ($items as $it): ?>
    <div class="bc-card">
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
</script>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>