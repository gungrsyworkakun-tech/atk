<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('bast');

$uploadDir = __DIR__ . '/uploads/bast/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'upload') {
    csrf_check();
    $trxId = (int)$_POST['trx_id'];

    if (empty($_FILES['berkas']) || $_FILES['berkas']['error'] !== UPLOAD_ERR_OK) {
        flash_set('Gagal mengunggah berkas. Coba lagi.', 'error');
        redirect('bast.php');
    }

    $file = $_FILES['berkas'];
    $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) {
        flash_set('Format berkas harus PDF atau gambar (JPG/PNG/WebP).', 'error');
        redirect('bast.php');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        flash_set('Ukuran berkas maksimal 5 MB.', 'error');
        redirect('bast.php');
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = 'bast-' . $trxId . '-' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
        $pdo->prepare('UPDATE transactions SET bast_file = ? WHERE id = ?')->execute([$safeName, $trxId]);
        flash_set('Berkas BAST diunggah.');
    } else {
        flash_set('Gagal menyimpan berkas di server.', 'error');
    }
    redirect('bast.php');
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT bast_file FROM transactions WHERE id = ?');
    $stmt->execute([$_GET['id']]);
    $row = $stmt->fetch();
    if ($row && $row['bast_file']) {
        $f = $uploadDir . $row['bast_file'];
        if (is_file($f)) @unlink($f);
        $pdo->prepare('UPDATE transactions SET bast_file = NULL WHERE id = ?')->execute([$_GET['id']]);
        flash_set('Berkas BAST dihapus.');
    } else {
        flash_set('Berkas tidak ditemukan.', 'error');
    }
    redirect('bast.php');
}

$rows = $pdo->query("SELECT t.*, i.nama FROM transactions t LEFT JOIN items i ON i.id = t.item_id WHERE t.tipe='keluar' ORDER BY t.tanggal DESC, t.id DESC")->fetchAll();

$reqRows = $pdo->query("
    SELECT p.*, u.username
    FROM permintaan p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE p.bast_file IS NOT NULL
    ORDER BY p.updated_at DESC
")->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<style>
  .bast-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;}
  .bast-search{flex:1;min-width:220px;position:relative;}
  .bast-search input{width:100%;padding:9px 12px 9px 34px;border:1px solid var(--line,#232a35);border-radius:8px;background:var(--panel,#12161c);color:inherit;font-size:13.5px;}
  .bast-search svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.55;pointer-events:none;}
  .bast-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;}
  .bast-card{border:1px solid var(--line,#232a35);border-radius:12px;padding:14px;background:var(--panel,#12161c);display:flex;flex-direction:column;gap:10px;}
  .bast-card-top{display:flex;gap:10px;align-items:flex-start;}
  .bast-file-ic{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}
  .bast-file-ic.pdf{background:rgba(224,82,82,.15);color:#e05252;}
  .bast-file-ic.img{background:rgba(76,175,140,.15);color:#4caf8c;}
  .bast-file-ic.empty{background:rgba(140,150,165,.12);color:var(--text-dim,#8b95a5);}
  .bast-meta{min-width:0;}
  .bast-item-name{font-weight:600;font-size:14px;line-height:1.3;}
  .bast-sub{font-size:12px;color:var(--text-dim,#8b95a5);margin-top:2px;}
  .bast-actions{display:flex;gap:8px;margin-top:auto;}
  .bast-actions .btn{flex:1;text-align:center;font-size:12.5px;padding:7px 10px;}
  .bast-actions .icon-btn.danger{flex:0 0 auto;}
  .bast-empty-tag{font-size:12px;color:var(--text-dim,#8b95a5);background:rgba(140,150,165,.1);border-radius:6px;padding:4px 8px;display:inline-block;width:fit-content;}
  .bast-card[hidden]{display:none;}
  .bast-mini-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
  .bast-mini-form input[type=file]{font-size:11.5px;max-width:150px;}
</style>

<div class="topline">
  <div><h1>Berkas BAST</h1><div class="sub">Berita Acara Serah Terima — lihat, unduh, atau hapus berkas untuk tiap penyerahan barang</div></div>
</div>

<?php if ($rows || $reqRows): ?>
<div class="bast-toolbar">
  <div class="bast-search">
    <?= icon('search', 15) ?>
    <input type="text" id="bast-search-input" placeholder="Cari nama berkas, barang, atau bidang…" autocomplete="off">
  </div>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card"><div class="empty"><b>Belum ada penyerahan barang</b>Berkas BAST bisa dilampirkan saat mencatat transaksi keluar di menu Barang Masuk & Keluar.</div></div>
<?php else: ?>
<div class="bast-grid" id="bast-grid">
  <?php foreach ($rows as $t):
    $hasFile = !empty($t['bast_file']);
    $ext = $hasFile ? strtolower(pathinfo($t['bast_file'], PATHINFO_EXTENSION)) : '';
    $isPdf = $ext === 'pdf';
    $searchKey = mb_strtolower(($t['bast_file'] ?? '') . ' ' . ($t['nama'] ?? '') . ' ' . ($t['bidang'] ?? ''));
  ?>
    <div class="bast-card" data-search="<?= e($searchKey) ?>">
      <div class="bast-card-top">
        <div class="bast-file-ic <?= $hasFile ? ($isPdf ? 'pdf' : 'img') : 'empty' ?>"><?= $hasFile ? ($isPdf ? 'PDF' : 'IMG') : '—' ?></div>
        <div class="bast-meta">
          <div class="bast-item-name"><?= e($t['nama'] ?? '(dihapus)') ?></div>
          <div class="bast-sub"><?= e($t['tanggal']) ?> · <?= (int)$t['jumlah'] ?> pcs · <?= e($t['bidang'] ?: '—') ?></div>
          <?php if ($hasFile): ?><div class="bast-sub mono" style="word-break:break-all;"><?= e($t['bast_file']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($hasFile): ?>
        <div class="bast-actions">
          <button type="button" class="btn btn-ghost js-view-doc"
            data-url="serve_bast.php?id=<?= (int)$t['id'] ?>"
            data-download="serve_bast.php?id=<?= (int)$t['id'] ?>&download=1"
            data-filename="<?= e($t['bast_file']) ?>"
            data-image="<?= $isPdf ? '0' : '1' ?>">Lihat</button>
          <a class="btn btn-primary" href="serve_bast.php?id=<?= e($t['id']) ?>&download=1">Unduh</a>
          <a class="icon-btn danger" href="?action=delete&id=<?= e($t['id']) ?>" title="Hapus berkas" onclick="return confirm('Hapus berkas BAST ini? Data transaksi tidak ikut terhapus.');">Hapus</a>
        </div>
      <?php else: ?>
        <span class="bast-empty-tag">Belum ada berkas</span>
        <form method="post" enctype="multipart/form-data" class="bast-mini-form">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="upload">
          <input type="hidden" name="trx_id" value="<?= e($t['id']) ?>">
          <input type="file" name="berkas" accept="application/pdf,image/*" required>
          <button class="btn btn-ghost" style="font-size:12px;padding:6px 10px;" type="submit">Unggah</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($reqRows): ?>
<h3 style="margin-top:24px;">Berkas dari Permintaan Bidang</h3>
<div class="bast-grid" id="bast-grid-req">
  <?php foreach ($reqRows as $r):
    $ext2 = strtolower(pathinfo($r['bast_file'], PATHINFO_EXTENSION));
    $isPdf2 = $ext2 === 'pdf';
    $searchKey2 = mb_strtolower($r['bast_file'] . ' ' . $r['bidang'] . ' ' . ($r['username'] ?? ''));
  ?>
    <div class="bast-card" data-search="<?= e($searchKey2) ?>">
      <div class="bast-card-top">
        <div class="bast-file-ic <?= $isPdf2 ? 'pdf' : 'img' ?>"><?= $isPdf2 ? 'PDF' : 'IMG' ?></div>
        <div class="bast-meta">
          <div class="bast-item-name"><?= e($r['bidang']) ?></div>
          <div class="bast-sub"><?= e(date('d M Y', strtotime($r['tanggal']))) ?> · Diajukan: <?= e($r['username'] ?? '(dihapus)') ?></div>
          <div class="bast-sub mono" style="word-break:break-all;"><?= e($r['bast_file']) ?></div>
        </div>
      </div>
      <div class="bast-actions">
        <button type="button" class="btn btn-ghost js-view-doc"
          data-url="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>"
          data-download="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>&download=1"
          data-filename="<?= e($r['bast_file']) ?>"
          data-image="<?= $isPdf2 ? '0' : '1' ?>">Lihat</button>
        <a class="btn btn-primary" href="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>&download=1">Unduh</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$rows && !$reqRows): ?>
<?php else: ?>
<div class="empty" id="bast-no-result" style="display:none;margin-top:14px;"><b>Tidak ditemukan</b>Coba kata kunci lain.</div>
<?php endif; ?>

<div class="helptext">Format yang didukung: PDF, JPG, PNG, WebP. Ukuran maksimal 5 MB per berkas.</div>

<script>
  (function () {
    var input = document.getElementById('bast-search-input');
    if (!input) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll('.bast-card'));
    var noResult = document.getElementById('bast-no-result');
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var visible = 0;
      cards.forEach(function (card) {
        var match = q === '' || card.getAttribute('data-search').indexOf(q) !== -1;
        card.hidden = !match;
        if (match) visible++;
      });
      noResult.style.display = visible === 0 ? 'block' : 'none';
    });
  })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>