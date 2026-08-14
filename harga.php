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

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE nama LIKE ? OR kode LIKE ? ORDER BY nama');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);
    $items = $stmt->fetchAll();
} else {
    $items = $pdo->query('SELECT * FROM items ORDER BY nama')->fetchAll();
}
$totalNilai = $pdo->query('SELECT COALESCE(SUM(stok*harga),0) c FROM items')->fetch()['c'];

require __DIR__ . '/includes/header.php';
?>
<style>
  .hg-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:18px;flex-wrap:wrap;}
  .hg-search{flex:1;min-width:220px;position:relative;}
  .hg-search input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
    background:var(--paper-sunk);color:var(--text);font-size:13.5px;font-family:'Inter',Arial,sans-serif;box-sizing:border-box;}
  .hg-search input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .hg-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.55;pointer-events:none;}
  .hg-count{font-size:12px;color:var(--text-dim);white-space:nowrap;}

  .hg-summary-card{display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,var(--c-blue-bg),var(--c-teal-bg));
    border:1px solid var(--line);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
  .hg-summary-label{font-size:11.5px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;}
  .hg-summary-value{font-family:'Space Grotesk',sans-serif;font-size:24px;font-weight:700;margin-top:4px;}

  .hg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;}
  .hg-card{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
    overflow:hidden;box-shadow:var(--shadow-card);cursor:pointer;display:flex;flex-direction:column;
    transition:transform .15s var(--ease), box-shadow .15s var(--ease), border-color .15s var(--ease);}
  .hg-card:hover{transform:translateY(-3px);box-shadow:0 24px 48px -20px rgba(0,0,0,.45);border-color:var(--accent);}

  .hg-photo{width:100%;aspect-ratio:1/1;background:var(--paper-sunk);display:flex;align-items:center;
    justify-content:center;overflow:hidden;position:relative;}
  .hg-photo img{width:100%;height:100%;object-fit:cover;}
  .hg-photo-placeholder{color:var(--text-faint);}
  .hg-badge-stok{position:absolute;top:8px;left:8px;font-size:10px;font-weight:700;padding:3px 8px;
    border-radius:20px;background:rgba(15,23,42,.7);color:#fff;backdrop-filter:blur(4px);}

  .hg-body{padding:12px 13px 14px;display:flex;flex-direction:column;gap:5px;flex:1;}
  .hg-name{font-size:12.5px;font-weight:600;line-height:1.35;min-height:34px;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
  .hg-price{font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:700;color:var(--accent);margin-top:2px;}
  .hg-price.zero{color:var(--text-faint);font-size:13px;font-weight:600;}
  .hg-code{font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--text-faint);margin-top:1px;}
  .hg-total-line{font-size:10.5px;color:var(--text-dim);margin-top:3px;padding-top:6px;border-top:1px dashed var(--line-soft);}
  .hg-total-line b{color:var(--text);}

  /* ---------- modal detail ala Shopee ---------- */
  .hg-modal-overlay{position:fixed;inset:0;background:rgba(6,9,16,.72);z-index:200;
    display:none;align-items:center;justify-content:center;padding:20px;}
  .hg-modal-overlay.show{display:flex;}
  .hg-modal{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius-lg);
    width:min(760px,100%);max-height:88vh;overflow-y:auto;box-shadow:var(--shadow-pop);}
  .hg-modal-close{position:absolute;top:16px;right:16px;width:34px;height:34px;border-radius:50%;
    background:var(--paper-sunk);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;
    cursor:pointer;color:var(--text-dim);z-index:2;}
  .hg-modal-inner{display:grid;grid-template-columns:280px 1fr;gap:0;position:relative;}
  @media (max-width:640px){ .hg-modal-inner{grid-template-columns:1fr;} }

  .hg-modal-photo{background:var(--paper-sunk);display:flex;align-items:center;justify-content:center;
    aspect-ratio:1/1;overflow:hidden;}
  .hg-modal-photo img{width:100%;height:100%;object-fit:cover;}
  .hg-modal-photo-placeholder{color:var(--text-faint);}

  .hg-modal-info{padding:26px 28px;}
  .hg-modal-code{font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--text-dim);
    background:var(--paper-sunk);display:inline-block;padding:3px 10px;border-radius:20px;margin-bottom:10px;}
  .hg-modal-name{font-family:'Space Grotesk',sans-serif;font-size:19px;font-weight:700;line-height:1.3;margin-bottom:14px;}

  .hg-modal-price-box{background:var(--c-blue-bg);border:1px solid rgba(59,130,246,.25);border-radius:var(--radius-sm);
    padding:16px 18px;margin-bottom:18px;}
  .hg-modal-price-label{font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;font-weight:700;}
  .hg-modal-price-val{font-family:'Space Grotesk',sans-serif;font-size:28px;font-weight:700;color:var(--accent);margin-top:4px;}

  .hg-modal-stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;}
  .hg-modal-stat{background:var(--paper-sunk);border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px 14px;}
  .hg-modal-stat-label{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;font-weight:700;}
  .hg-modal-stat-val{font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:700;margin-top:3px;}

  .hg-modal-form{border-top:1px solid var(--line-soft);padding-top:18px;}
  .hg-modal-form label{display:block;font-size:11.5px;font-weight:600;color:var(--text-dim);margin-bottom:7px;
    text-transform:uppercase;letter-spacing:.04em;}
  .hg-modal-form-row{display:flex;gap:10px;}
  .hg-modal-form-row input{flex:1;padding:11px 13px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
    background:var(--paper-sunk);color:var(--text);font-size:14px;font-family:'Inter',Arial,sans-serif;box-sizing:border-box;}
  .hg-modal-form-row input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .hg-modal-form-row .btn{padding:11px 20px;}
</style>

<div class="topline">
  <div><h1>Harga Barang</h1><div class="sub">Katalog harga satuan dan nilai total stok</div></div>
</div>

<div class="hg-summary-card">
  <div>
    <div class="hg-summary-label">Total Nilai Stok</div>
    <div class="hg-summary-value"><?= format_rupiah($totalNilai) ?></div>
  </div>
  <div style="font-size:12.5px;color:var(--text-dim);"><?= count($items) ?> jenis barang</div>
</div>

<div class="hg-toolbar">
  <form method="get" class="hg-search" style="margin:0;">
    <?= icon('search', 15) ?>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama atau kode barang…">
  </form>
  <span class="hg-count"><?= count($items) ?> barang<?= $q !== '' ? ' ditemukan' : '' ?></span>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="harga.php" style="font-size:12.5px;padding:7px 12px;">Reset</a><?php endif; ?>
</div>

<?php if (!$items): ?>
  <div class="empty">
    <b><?= $q !== '' ? 'Barang tidak ditemukan' : 'Belum ada barang' ?></b>
    <?= $q !== '' ? 'Tidak ada barang yang cocok dengan pencarian "' . e($q) . '".' : 'Tambahkan barang untuk mencatat harga.' ?>
  </div>
<?php else: ?>
<div class="hg-grid">
  <?php foreach ($items as $it):
    $nilaiTotal = (float)$it['stok'] * (float)$it['harga'];
    $itemJson = json_encode([
        'id' => $it['id'], 'kode' => $it['kode'], 'nama' => $it['nama'],
        'jenis' => $it['jenis'], 'satuan' => $it['satuan'] ?? 'pcs',
        'stok' => (int)$it['stok'], 'harga' => (float)$it['harga'],
        'hasFoto' => !empty($it['foto']),
    ], JSON_HEX_APOS | JSON_HEX_QUOT);
  ?>
    <div class="hg-card js-open-detail" data-item='<?= $itemJson ?>'>
      <div class="hg-photo">
        <?php if (!empty($it['foto'])): ?>
          <img src="serve_foto_barang.php?id=<?= (int)$it['id'] ?>" alt="Foto <?= e($it['nama']) ?>">
        <?php else: ?>
          <div class="hg-photo-placeholder"><?= icon('box', 30) ?></div>
        <?php endif; ?>
        <span class="hg-badge-stok">Stok <?= (int)$it['stok'] ?></span>
      </div>
      <div class="hg-body">
        <div class="hg-name"><?= e($it['nama']) ?></div>
        <div class="hg-price <?= (float)$it['harga'] <= 0 ? 'zero' : '' ?>">
          <?= (float)$it['harga'] > 0 ? format_rupiah($it['harga']) : 'Harga belum diatur' ?>
        </div>
        <div class="hg-code"><?= e($it['kode']) ?></div>
        <div class="hg-total-line">Nilai stok: <b><?= format_rupiah($nilaiTotal) ?></b></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal detail ala halaman produk marketplace -->
<div class="hg-modal-overlay" id="hgModalOverlay">
  <div class="hg-modal" id="hgModal">
    <div class="hg-modal-close" id="hgModalClose">✕</div>
    <div class="hg-modal-inner">
      <div class="hg-modal-photo" id="hgModalPhotoWrap"></div>
      <div class="hg-modal-info">
        <div class="hg-modal-code" id="hgModalCode"></div>
        <div class="hg-modal-name" id="hgModalName"></div>

        <div class="hg-modal-price-box">
          <div class="hg-modal-price-label">Harga Satuan</div>
          <div class="hg-modal-price-val" id="hgModalPrice"></div>
        </div>

        <div class="hg-modal-stats">
          <div class="hg-modal-stat">
            <div class="hg-modal-stat-label">Sisa Stok</div>
            <div class="hg-modal-stat-val" id="hgModalStok"></div>
          </div>
          <div class="hg-modal-stat">
            <div class="hg-modal-stat-label">Nilai Total Stok</div>
            <div class="hg-modal-stat-val" id="hgModalTotal" style="font-size:15px;"></div>
          </div>
        </div>

        <form method="post" class="hg-modal-form" id="hgModalForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="set_harga">
          <input type="hidden" name="id" id="hgModalIdInput">
          <label>Ubah Harga Satuan (Rp)</label>
          <div class="hg-modal-form-row">
            <input type="number" min="0" step="1" name="harga" id="hgModalHargaInput" required>
            <button class="btn btn-primary" type="submit">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('hgModalOverlay');
  var closeBtn = document.getElementById('hgModalClose');
  var photoWrap = document.getElementById('hgModalPhotoWrap');
  var codeEl = document.getElementById('hgModalCode');
  var nameEl = document.getElementById('hgModalName');
  var priceEl = document.getElementById('hgModalPrice');
  var stokEl = document.getElementById('hgModalStok');
  var totalEl = document.getElementById('hgModalTotal');
  var idInput = document.getElementById('hgModalIdInput');
  var hargaInput = document.getElementById('hgModalHargaInput');

  function formatRupiah(angka) {
    return 'Rp ' + Math.round(angka).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function openModal(item) {
    photoWrap.innerHTML = item.hasFoto
      ? '<img src="serve_foto_barang.php?id=' + item.id + '" alt="">'
      : '<div class="hg-modal-photo-placeholder">' + document.querySelector('.hg-photo-placeholder') ? document.querySelector('.hg-photo-placeholder').innerHTML : '' + '</div>';
    if (!item.hasFoto) {
      photoWrap.innerHTML = '<div class="hg-modal-photo-placeholder"></div>';
      var svgSrc = document.querySelector('.hg-photo-placeholder svg');
      if (svgSrc) photoWrap.querySelector('.hg-modal-photo-placeholder').appendChild(svgSrc.cloneNode(true));
    }

    codeEl.textContent = item.kode;
    nameEl.textContent = item.nama;
    priceEl.textContent = item.harga > 0 ? formatRupiah(item.harga) : 'Belum diatur';
    stokEl.textContent = item.stok + ' ' + item.satuan;
    totalEl.textContent = formatRupiah(item.stok * item.harga);
    idInput.value = item.id;
    hargaInput.value = item.harga;

    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.js-open-detail').forEach(function (card) {
    card.addEventListener('click', function () {
      try {
        var item = JSON.parse(card.dataset.item);
        openModal(item);
      } catch (e) {}
    });
  });

  closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('show')) closeModal(); });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>