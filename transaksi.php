<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('barang_masuk_keluar');

$uploadDir = __DIR__ . '/uploads/bast/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
$allowedBastTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $itemId = (int)$_POST['item_id'];
    $tipe = $_POST['tipe'] === 'keluar' ? 'keluar' : 'masuk';
    $jumlahInput = max(1, (int)$_POST['jumlah']);
    $satuanPilihan = $_POST['satuan_pilihan'] ?? 'base';
    $tanggal = $_POST['tanggal'] ?: date('Y-m-d');
    $bidang = $tipe === 'keluar' ? trim($_POST['bidang'] ?? '') : null;
    $penerima = $tipe === 'keluar' ? trim($_POST['penerima'] ?? '') : null;
    $keteranganInput = trim($_POST['keterangan'] ?? '');
    $removeBast = !empty($_POST['hapus_bast_lama']);
    $u = current_user();

    $bastError = null;
    $hasBastUpload = $tipe === 'keluar' && !empty($_FILES['berkas_bast']) && $_FILES['berkas_bast']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasBastUpload) {
        $file = $_FILES['berkas_bast'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $bastError = 'Gagal mengunggah berkas BAST. Coba lagi.';
        } elseif (!in_array($file['type'], $allowedBastTypes, true)) {
            $bastError = 'Format berkas BAST harus PDF atau gambar (JPG/PNG/WebP).';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $bastError = 'Ukuran berkas BAST maksimal 5 MB.';
        }
    }
    if ($bastError) {
        flash_set($bastError, 'error');
        redirect($id ? 'transaksi.php?action=edit&id=' . $id : 'transaksi.php');
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        $pdo->rollBack();
        flash_set('Barang tidak ditemukan.', 'error');
        redirect('transaksi.php');
    }

    // Tentukan faktor konversi satuan (server-side, tidak mempercayai kiriman client untuk faktornya).
    $satuanNama = $item['satuan'];
    $faktor = 1;
    if ($satuanPilihan !== 'base') {
        $stmtSat = $pdo->prepare('SELECT * FROM item_satuan WHERE id = ? AND item_id = ?');
        $stmtSat->execute([(int)$satuanPilihan, $itemId]);
        $sat = $stmtSat->fetch();
        if ($sat) {
            $faktor = (int)$sat['isi'];
            $satuanNama = $sat['nama_satuan'];
        }
    }
    $jumlah = $jumlahInput * $faktor; // jumlah final selalu dalam satuan dasar barang

    $keterangan = $keteranganInput;
    if ($faktor > 1) {
        $note = $jumlahInput . ' ' . $satuanNama . ' (=' . $jumlah . ' ' . $item['satuan'] . ')';
        $keterangan = $keterangan !== '' ? $keterangan . ' — ' . $note : $note;
    }

    $oldTrx = null;
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $oldTrx = $stmt->fetch();
    }

    $baselineStok = (int)$item['stok'];
    if ($oldTrx && (int)$oldTrx['item_id'] === $itemId) {
        $baselineStok += $oldTrx['tipe'] === 'keluar' ? (int)$oldTrx['jumlah'] : -(int)$oldTrx['jumlah'];
    }

    if ($tipe === 'keluar' && $jumlah > $baselineStok) {
        $pdo->rollBack();
        flash_set('Stok tidak mencukupi. Sisa stok "' . $item['nama'] . '" saat ini: ' . $baselineStok . ' ' . $item['satuan'] . '. Tidak bisa mengeluarkan ' . $jumlah . ' ' . $item['satuan'] . '. Tambahkan barang masuk terlebih dahulu.', 'error');
        redirect($id ? 'transaksi.php?action=edit&id=' . $id : 'transaksi.php');
    }

    if ($oldTrx && (int)$oldTrx['item_id'] !== $itemId) {
        $stmtOldItem = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $stmtOldItem->execute([$oldTrx['item_id']]);
        $oldItem = $stmtOldItem->fetch();
        if ($oldItem) {
            $reverseDelta = $oldTrx['tipe'] === 'masuk' ? -(int)$oldTrx['jumlah'] : (int)$oldTrx['jumlah'];
            $newOldItemStok = (int)$oldItem['stok'] + $reverseDelta;
            if ($newOldItemStok < 0) {
                $pdo->rollBack();
                flash_set('Tidak bisa mengubah transaksi ini karena akan membuat stok "' . $oldItem['nama'] . '" menjadi minus.', 'error');
                redirect('transaksi.php?action=edit&id=' . $id);
            }
        }
    }

    try {
        if ($oldTrx) {
            $reverseDelta = $oldTrx['tipe'] === 'masuk' ? -(int)$oldTrx['jumlah'] : (int)$oldTrx['jumlah'];
            $pdo->prepare('UPDATE items SET stok = stok + ? WHERE id = ?')->execute([$reverseDelta, $oldTrx['item_id']]);
        }

        $newDelta = $tipe === 'masuk' ? $jumlah : -$jumlah;
        $pdo->prepare('UPDATE items SET stok = stok + ? WHERE id = ?')->execute([$newDelta, $itemId]);

        $bastFileValue = $oldTrx['bast_file'] ?? null;
        $oldPhysicalFile = null;
        if ($hasBastUpload || $removeBast) {
            if ($bastFileValue) $oldPhysicalFile = $uploadDir . $bastFileValue;
            $bastFileValue = null;
        }

        if ($id) {
            $pdo->prepare('UPDATE transactions SET item_id=?, tipe=?, jumlah=?, tanggal=?, bidang=?, penerima=?, keterangan=?, bast_file=? WHERE id=?')
                ->execute([$itemId, $tipe, $jumlah, $tanggal, $bidang, $penerima, $keterangan, $bastFileValue, $id]);
            $trxId = $id;
        } else {
            $pdo->prepare('INSERT INTO transactions (item_id, tipe, jumlah, tanggal, bidang, penerima, keterangan, bast_file, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$itemId, $tipe, $jumlah, $tanggal, $bidang, $penerima, $keterangan, $bastFileValue, $u['id']]);
            $trxId = $pdo->lastInsertId();
        }

        if ($hasBastUpload) {
            $ext = pathinfo($_FILES['berkas_bast']['name'], PATHINFO_EXTENSION);
            $safeName = 'bast-' . $trxId . '-' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            if (move_uploaded_file($_FILES['berkas_bast']['tmp_name'], $uploadDir . $safeName)) {
                $pdo->prepare('UPDATE transactions SET bast_file = ? WHERE id = ?')->execute([$safeName, $trxId]);
            }
        }

        $pdo->commit();
        if ($oldPhysicalFile && is_file($oldPhysicalFile)) @unlink($oldPhysicalFile);

        flash_set($id ? 'Transaksi diperbarui, stok disesuaikan.' : 'Transaksi tersimpan, stok diperbarui.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Gagal menyimpan transaksi.', 'error');
    }
    redirect('transaksi.php');
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
    $stmt->execute([$_GET['id']]);
    $trx = $stmt->fetch();
    if ($trx) {
        $pdo->beginTransaction();
        try {
            $delta = $trx['tipe'] === 'masuk' ? -$trx['jumlah'] : $trx['jumlah'];
            $pdo->prepare('UPDATE items SET stok = GREATEST(0, stok + ?) WHERE id = ?')->execute([$delta, $trx['item_id']]);
            $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$trx['id']]);
            $pdo->commit();
            if ($trx['bast_file']) {
                $f = $uploadDir . $trx['bast_file'];
                if (is_file($f)) @unlink($f);
            }
            flash_set('Transaksi dihapus, stok disesuaikan.');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash_set('Gagal menghapus transaksi.', 'error');
        }
    }
    redirect('transaksi.php');
}

$editing = null;
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
    $stmt->execute([$_GET['id']]);
    $editing = $stmt->fetch();
    if (!$editing) { flash_set('Transaksi tidak ditemukan.', 'error'); redirect('transaksi.php'); }
}

$items = $pdo->query('SELECT * FROM items ORDER BY nama')->fetchAll();
$bidangList = $pdo->query('SELECT nama FROM bidang_list ORDER BY nama')->fetchAll();
$rows = $pdo->query('SELECT t.*, i.nama, i.satuan FROM transactions t LEFT JOIN items i ON i.id = t.item_id ORDER BY t.tanggal DESC, t.id DESC')->fetchAll();

$allSatuan = $pdo->query('SELECT * FROM item_satuan ORDER BY item_id, isi')->fetchAll();
$satuanByItem = [];
foreach ($allSatuan as $s) $satuanByItem[$s['item_id']][] = $s;

$existingIsPdf = false;
if ($editing && $editing['bast_file']) {
    $existingIsPdf = strtolower(pathinfo($editing['bast_file'], PATHINFO_EXTENSION)) === 'pdf';
}

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Barang Masuk & Keluar</h1><div class="sub">Catatan pergerakan stok beserta tahun barang masuk</div></div>
</div>

<div class="card">
  <h3>Pindai barcode</h3>
  <p class="helptext" style="margin-top:0;">Arahkan kamera ke QR pada label barang, atau ambil/unggah foto QR-nya — barang yang cocok otomatis terpilih di formulir di bawah.</p>

  <div class="scan-tabs">
    <button type="button" class="scan-tab active" id="tab-live">Kamera Langsung</button>
    <button type="button" class="scan-tab" id="tab-photo">Ambil/Unggah Foto</button>
  </div>

  <div id="scan-live-panel" class="scan-panel">
    <button type="button" class="btn btn-ghost" id="btn-scan">Mulai Kamera</button>
    <div id="reader" style="display:none;max-width:320px;margin-top:12px;"></div>
  </div>

  <div id="scan-photo-panel" class="scan-panel" style="display:none;">
    <label class="btn btn-ghost" for="file-scan" style="cursor:pointer;">Ambil/Unggah Foto QR</label>
    <input type="file" id="file-scan" accept="image/*" capture="environment" style="display:none;">
  </div>

  <div id="scan-result" class="helptext" style="margin-top:8px;"></div>
</div>

<div class="card" id="trx-form-card">
  <h3><?= $editing ? 'Ubah transaksi' : 'Catat transaksi' ?></h3>

  <?php if ($editing): ?>
    <div class="edit-banner">
      <span>Mengubah transaksi <b><?= e($editing['tanggal']) ?></b> — stok akan disesuaikan ulang otomatis. Jumlah ditampilkan dalam satuan dasar.</span>
      <a class="btn btn-ghost" style="padding:5px 10px;font-size:12px;" href="transaksi.php">Batal ubah</a>
    </div>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div class="empty"><b>Belum ada barang</b>Tambahkan barang lebih dulu di menu Data Barang.</div>
  <?php else: ?>
  <form method="post" id="trx-form" enctype="multipart/form-data"
        data-editing-item-id="<?= $editing ? (int)$editing['item_id'] : '' ?>"
        data-editing-tipe="<?= $editing ? e($editing['tipe']) : '' ?>"
        data-editing-jumlah="<?= $editing ? (int)$editing['jumlah'] : 0 ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= e($editing['id']) ?>"><?php endif; ?>
    <input type="hidden" name="hapus_bast_lama" id="hapus-bast-lama" value="0">

    <div class="form-row">
      <div class="field">
        <label>Barang</label>
        <select name="item_id" id="item-select">
          <?php foreach ($items as $it): ?>
            <option value="<?= e($it['id']) ?>" data-kode="<?= e($it['kode']) ?>" data-stok="<?= (int)$it['stok'] ?>" data-satuan="<?= e($it['satuan']) ?>" <?= $editing && (int)$editing['item_id'] === (int)$it['id'] ? 'selected' : '' ?>>(<?= e($it['tahun_masuk']) ?>) <?= e($it['nama']) ?> — stok: <?= (int)$it['stok'] ?> <?= e($it['satuan']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="stok-info" id="stok-info"></div>
      </div>
      <div class="field">
        <label>Tipe</label>
        <select name="tipe" id="trx-tipe">
          <option value="masuk" <?= $editing && $editing['tipe'] === 'masuk' ? 'selected' : '' ?>>Masuk</option>
          <option value="keluar" <?= $editing && $editing['tipe'] === 'keluar' ? 'selected' : '' ?>>Keluar</option>
        </select>
      </div>
      <div class="field">
        <label>Jumlah</label>
        <input type="number" min="1" name="jumlah" id="jumlah-input" value="<?= e($editing['jumlah'] ?? 1) ?>">
        <div class="stok-warning" id="stok-warning">Jumlah melebihi stok yang tersedia.</div>
      </div>
      <div class="field">
        <label>Satuan</label>
        <select name="satuan_pilihan" id="satuan-select">
          <option value="base">— pilih barang dulu —</option>
        </select>
        <div class="helptext" id="satuan-convert-info" style="margin-top:4px;"></div>
      </div>
    </div>

    <div class="form-row">
      <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= e($editing['tanggal'] ?? date('Y-m-d')) ?>"></div>
    </div>

    <div class="form-row" id="keluar-info-wrap" style="display:none;">
      <div class="field">
        <label>Bidang pengambil</label>
        <select name="bidang">
          <option value="">— Pilih bidang —</option>
          <?php foreach ($bidangList as $b): ?>
            <option value="<?= e($b['nama']) ?>" <?= $editing && $editing['bidang'] === $b['nama'] ? 'selected' : '' ?>><?= e($b['nama']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$bidangList): ?>
          <div class="helptext" style="margin-top:6px;">Belum ada bidang di daftar. Tambahkan lewat menu Kelola Bidang (super admin).</div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label>Penerima barang</label>
        <input type="text" name="penerima" value="<?= e($editing['penerima'] ?? '') ?>" placeholder="Nama yang menerima barang">
      </div>
    </div>

    <div class="field" id="bast-wrap" style="display:none;">
      <label>Berkas BAST (opsional)</label>

      <?php if ($editing && $editing['bast_file']): ?>
        <div class="bast-preview" id="bast-existing-preview" style="display:block;">
          <div class="bast-preview-card">
            <div class="bast-preview-thumb <?= $existingIsPdf ? 'bast-thumb-pdf' : '' ?>">
              <?php if ($existingIsPdf): ?>PDF<?php else: ?><img src="serve_bast.php?id=<?= (int)$editing['id'] ?>" alt=""><?php endif; ?>
            </div>
            <div class="bast-preview-info">
              <div class="bast-preview-name"><?= e($editing['bast_file']) ?></div>
              <div class="bast-preview-size">Berkas tersimpan saat ini</div>
            </div>
            <button type="button" class="btn btn-ghost bast-preview-btn js-view-doc"
              data-url="serve_bast.php?id=<?= (int)$editing['id'] ?>"
              data-download="serve_bast.php?id=<?= (int)$editing['id'] ?>&download=1"
              data-filename="<?= e($editing['bast_file']) ?>"
              data-image="<?= $existingIsPdf ? '0' : '1' ?>">Lihat</button>
            <button type="button" class="bast-preview-remove" id="bast-existing-remove-btn" title="Hapus berkas ini">✕</button>
          </div>
        </div>
        <div class="bast-removed-note" id="bast-removed-note" style="display:none;">
          Berkas lama akan dihapus saat disimpan. <button type="button" id="bast-undo-remove-btn">Batalkan</button>
        </div>
      <?php endif; ?>

      <div class="bast-upload-zone" style="margin-top:10px;">
        <input type="file" name="berkas_bast" id="bast-file-input" accept="application/pdf,image/*" style="display:none;">
        <button type="button" class="btn btn-ghost" id="bast-pick-btn"><?= $editing && $editing['bast_file'] ? 'Ganti Berkas' : 'Pilih Berkas' ?></button>
        <span class="helptext" style="margin:0;">PDF, JPG, PNG, atau WebP. Maks 5 MB.</span>
      </div>
      <div id="bast-preview" class="bast-preview"></div>
    </div>

    <div class="field"><label>Keterangan (opsional)</label><input name="keterangan" value="<?= e($editing['keterangan'] ?? '') ?>" placeholder="mis. pembelian rutin / permintaan bidang"></div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-primary" type="submit" id="trx-submit-btn"><?= $editing ? 'Simpan perubahan' : 'Simpan transaksi' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="transaksi.php">Batal</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><b>Belum ada transaksi</b>Catat barang masuk atau keluar pertama lewat formulir di atas.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Tanggal</th><th>Tahun</th><th>Barang</th><th>Tipe</th><th>Jumlah</th><th>Bidang</th><th>Penerima</th><th>Keterangan</th><th>Berkas</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $t):
        $rowHasFile = !empty($t['bast_file']);
        $rowIsPdf = $rowHasFile && strtolower(pathinfo($t['bast_file'], PATHINFO_EXTENSION)) === 'pdf';
      ?>
      <tr>
        <td class="mono"><?= e($t['tanggal']) ?></td>
        <td class="mono"><?= e(date('Y', strtotime($t['tanggal']))) ?></td>
        <td><?= e($t['nama'] ?? '(dihapus)') ?></td>
        <td><span class="tag <?= $t['tipe'] === 'masuk' ? 'tag-masuk' : 'tag-keluar' ?>"><?= e($t['tipe']) ?></span></td>
        <td class="mono"><?= (int)$t['jumlah'] ?> <?= e($t['satuan'] ?? '') ?></td>
        <td><?= e($t['bidang'] ?: '—') ?></td>
        <td><?= e($t['penerima'] ?? '' ?: '—') ?></td>
        <td><?= e($t['keterangan'] ?: '—') ?></td>
        <td>
          <?php if ($rowHasFile): ?>
            <button type="button" class="icon-btn js-view-doc"
              data-url="serve_bast.php?id=<?= (int)$t['id'] ?>"
              data-download="serve_bast.php?id=<?= (int)$t['id'] ?>&download=1"
              data-filename="<?= e($t['bast_file']) ?>"
              data-image="<?= $rowIsPdf ? '0' : '1' ?>">Lihat</button>
          <?php else: ?>
            <span style="color:var(--text-dim);font-size:12.5px;">—</span>
          <?php endif; ?>
        </td>
        <td class="actions-cell">
          <a class="icon-btn" href="?action=edit&id=<?= e($t['id']) ?>">Ubah</a>
          <a class="icon-btn danger" href="?action=delete&id=<?= e($t['id']) ?>" onclick="return confirm('Hapus transaksi ini?');">Hapus</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
  var satuanData = <?= json_encode($satuanByItem) ?>;

  var form = document.getElementById('trx-form');
  var itemSelect = document.getElementById('item-select');
  var tipeSelect = document.getElementById('trx-tipe');
  var jumlahInput = document.getElementById('jumlah-input');
  var satuanSelect = document.getElementById('satuan-select');
  var satuanConvertInfo = document.getElementById('satuan-convert-info');
  var keluarInfoWrap = document.getElementById('keluar-info-wrap');
  var bastWrap = document.getElementById('bast-wrap');
  var stokInfo = document.getElementById('stok-info');
  var stokWarning = document.getElementById('stok-warning');
  var submitBtn = document.getElementById('trx-submit-btn');

  var editingItemId = form.dataset.editingItemId;
  var editingTipe = form.dataset.editingTipe;
  var editingJumlah = parseInt(form.dataset.editingJumlah, 10) || 0;

  function populateSatuanOptions() {
    var opt = itemSelect.options[itemSelect.selectedIndex];
    if (!opt) return;
    var baseSatuan = opt.dataset.satuan || 'pcs';
    var itemId = opt.value;

    satuanSelect.innerHTML = '';
    var baseOpt = document.createElement('option');
    baseOpt.value = 'base';
    baseOpt.textContent = baseSatuan + ' (satuan dasar)';
    baseOpt.dataset.faktor = 1;
    satuanSelect.appendChild(baseOpt);

    var alt = satuanData[itemId] || [];
    alt.forEach(function (s) {
      var o = document.createElement('option');
      o.value = s.id;
      o.textContent = s.nama_satuan + ' (= ' + s.isi + ' ' + baseSatuan + ')';
      o.dataset.faktor = s.isi;
      satuanSelect.appendChild(o);
    });

    updateConvertInfo();
  }

  function updateConvertInfo() {
    var opt = satuanSelect.options[satuanSelect.selectedIndex];
    var faktor = opt ? parseInt(opt.dataset.faktor, 10) || 1 : 1;
    var jml = parseInt(jumlahInput.value, 10) || 0;
    var opt2 = itemSelect.options[itemSelect.selectedIndex];
    var baseSatuan = opt2 ? (opt2.dataset.satuan || 'pcs') : 'pcs';

    if (faktor > 1) {
      satuanConvertInfo.textContent = '= ' + (jml * faktor) + ' ' + baseSatuan;
    } else {
      satuanConvertInfo.textContent = '';
    }
    validateStok();
  }

  function availableStokForSelected() {
    var opt = itemSelect.options[itemSelect.selectedIndex];
    if (!opt) return 0;
    var base = parseInt(opt.dataset.stok, 10) || 0;
    if (editingItemId && editingTipe === 'keluar' && opt.value === editingItemId) {
      base += editingJumlah;
    }
    return base;
  }

  function syncKeluarFields() {
    var isKeluar = tipeSelect.value === 'keluar';
    keluarInfoWrap.style.display = isKeluar ? 'flex' : 'none';
    bastWrap.style.display = isKeluar ? 'block' : 'none';
    validateStok();
  }

  function validateStok() {
    var isKeluar = tipeSelect.value === 'keluar';
    var avail = availableStokForSelected();
    var opt2 = itemSelect.options[itemSelect.selectedIndex];
    var baseSatuan = opt2 ? (opt2.dataset.satuan || 'pcs') : 'pcs';

    var satOpt = satuanSelect.options[satuanSelect.selectedIndex];
    var faktor = satOpt ? parseInt(satOpt.dataset.faktor, 10) || 1 : 1;
    var jmlBase = (parseInt(jumlahInput.value, 10) || 0) * faktor;

    if (!isKeluar) {
      stokInfo.textContent = 'Stok saat ini: ' + avail + ' ' + baseSatuan;
      stokWarning.style.display = 'none';
      submitBtn.disabled = false;
      return;
    }

    stokInfo.textContent = 'Stok tersedia untuk dikeluarkan: ' + avail + ' ' + baseSatuan;

    if (jmlBase > avail) {
      stokWarning.textContent = 'Jumlah melebihi stok yang tersedia (maks. ' + avail + ' ' + baseSatuan + '). Tambahkan barang masuk dulu jika ingin mengeluarkan lebih banyak.';
      stokWarning.style.display = 'block';
      submitBtn.disabled = true;
    } else {
      stokWarning.style.display = 'none';
      submitBtn.disabled = false;
    }
  }

  itemSelect.addEventListener('change', populateSatuanOptions);
  satuanSelect.addEventListener('change', updateConvertInfo);
  tipeSelect.addEventListener('change', syncKeluarFields);
  jumlahInput.addEventListener('input', updateConvertInfo);

  form.addEventListener('submit', function (ev) {
    validateStok();
    if (submitBtn.disabled) {
      ev.preventDefault();
      alert('Jumlah keluar melebihi stok yang tersedia. Perbaiki dulu sebelum menyimpan.');
    }
  });

  populateSatuanOptions();
  syncKeluarFields();

  <?php if ($editing): ?>
  document.getElementById('trx-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
  <?php endif; ?>

  // ---------- Hapus berkas BAST lama (mode ubah) ----------
  var hiddenRemoveField = document.getElementById('hapus-bast-lama');
  var existingPreview = document.getElementById('bast-existing-preview');
  var existingRemoveBtn = document.getElementById('bast-existing-remove-btn');
  var removedNote = document.getElementById('bast-removed-note');
  var undoRemoveBtn = document.getElementById('bast-undo-remove-btn');

  if (existingRemoveBtn) {
    existingRemoveBtn.addEventListener('click', function () {
      hiddenRemoveField.value = '1';
      existingPreview.style.display = 'none';
      removedNote.style.display = 'flex';
    });
  }
  if (undoRemoveBtn) {
    undoRemoveBtn.addEventListener('click', function () {
      hiddenRemoveField.value = '0';
      existingPreview.style.display = 'block';
      removedNote.style.display = 'none';
    });
  }

  // ---------- Preview berkas BAST baru sebelum kirim ----------
  var pickBtn = document.getElementById('bast-pick-btn');
  var fileInput = document.getElementById('bast-file-input');
  var preview = document.getElementById('bast-preview');
  var allowedBastTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

  pickBtn.addEventListener('click', function () { fileInput.click(); });

  fileInput.addEventListener('change', function () {
    var file = fileInput.files[0];
    preview.innerHTML = '';
    if (!file) { preview.style.display = 'none'; return; }

    if (allowedBastTypes.indexOf(file.type) === -1) {
      alert('Format tidak didukung. Gunakan PDF, JPG, PNG, atau WebP.');
      fileInput.value = '';
      preview.style.display = 'none';
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert('Ukuran berkas maksimal 5 MB.');
      fileInput.value = '';
      preview.style.display = 'none';
      return;
    }

    if (existingPreview) existingPreview.style.display = 'none';
    if (removedNote) removedNote.style.display = 'none';

    var objectUrl = URL.createObjectURL(file);
    var isImage = file.type.indexOf('image/') === 0;

    var card = document.createElement('div');
    card.className = 'bast-preview-card';

    var thumb = document.createElement('div');
    thumb.className = 'bast-preview-thumb';
    if (isImage) {
      var img = document.createElement('img');
      img.src = objectUrl;
      thumb.appendChild(img);
    } else {
      thumb.classList.add('bast-thumb-pdf');
      thumb.textContent = 'PDF';
    }

    var info = document.createElement('div');
    info.className = 'bast-preview-info';
    var name = document.createElement('div');
    name.className = 'bast-preview-name';
    name.textContent = file.name;
    var size = document.createElement('div');
    size.className = 'bast-preview-size';
    size.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
    info.appendChild(name);
    info.appendChild(size);

    var viewBtn = document.createElement('button');
    viewBtn.type = 'button';
    viewBtn.className = 'btn btn-ghost bast-preview-btn';
    viewBtn.textContent = 'Lihat';
    viewBtn.addEventListener('click', function () {
      openDocViewer({ url: objectUrl, downloadUrl: objectUrl, filename: file.name, isImage: isImage });
    });

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'bast-preview-remove';
    removeBtn.textContent = '✕';
    removeBtn.addEventListener('click', function () {
      fileInput.value = '';
      preview.style.display = 'none';
      preview.innerHTML = '';
      if (existingPreview && hiddenRemoveField.value !== '1') existingPreview.style.display = 'block';
    });

    card.appendChild(thumb);
    card.appendChild(info);
    card.appendChild(viewBtn);
    card.appendChild(removeBtn);
    preview.appendChild(card);
    preview.style.display = 'block';
  });

  // ---------- Tab pindai: Kamera Langsung <-> Ambil Foto ----------
  var tabLive = document.getElementById('tab-live');
  var tabPhoto = document.getElementById('tab-photo');
  var panelLive = document.getElementById('scan-live-panel');
  var panelPhoto = document.getElementById('scan-photo-panel');

  function switchTab(mode) {
    var isLive = mode === 'live';
    tabLive.classList.toggle('active', isLive);
    tabPhoto.classList.toggle('active', !isLive);
    panelLive.style.display = isLive ? 'block' : 'none';
    panelPhoto.style.display = isLive ? 'none' : 'block';
  }
  tabLive.addEventListener('click', function () { switchTab('live'); });
  tabPhoto.addEventListener('click', function () { switchTab('photo'); });

  // ---------- Logika pindai QR (kamera langsung + foto) ----------
  var btn = document.getElementById('btn-scan');
  var readerDiv = document.getElementById('reader');
  var resultDiv = document.getElementById('scan-result');
  var fileScanInput = document.getElementById('file-scan');

  var html5QrCode = null;
  var scanning = false;

  function setResult(msg, isError) {
    resultDiv.textContent = msg;
    resultDiv.style.color = isError ? 'var(--red)' : '';
  }

  function stopScan(cb) {
    if (html5QrCode && scanning) {
      html5QrCode.stop().then(function () {
        html5QrCode.clear();
        readerDiv.style.display = 'none';
        scanning = false;
        btn.textContent = 'Mulai Kamera';
        if (cb) cb();
      }).catch(function () {
        readerDiv.style.display = 'none';
        scanning = false;
        btn.textContent = 'Mulai Kamera';
        if (cb) cb();
      });
    } else if (cb) cb();
  }

  function applyFoundItem(kode) {
    fetch('cari_barang.php?kode=' + encodeURIComponent(kode))
      .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
      .then(function (res) {
        if (!res.ok) { setResult(res.data.error || 'Barang tidak ditemukan.', true); return; }
        var opt = itemSelect.querySelector('option[data-kode="' + CSS.escape(res.data.kode) + '"]');
        if (opt) {
          itemSelect.value = opt.value;
          populateSatuanOptions();
          setResult('Barang ditemukan: ' + res.data.nama + ' (stok: ' + res.data.stok + ')');
        } else {
          setResult('Barang ditemukan di database tapi tidak ada di daftar dropdown.', true);
        }
      })
      .catch(function () { setResult('Gagal menghubungi server.', true); });
  }

  function onScanSuccess(decodedText) {
    stopScan(function () {
      setResult('Kode terbaca: ' + decodedText + ' — mencari barang…');
      applyFoundItem(decodedText);
    });
  }

  btn.addEventListener('click', function () {
    if (scanning) { stopScan(); return; }
    setResult('Membuka kamera…');

    if (typeof Html5Qrcode === 'undefined') {
      setResult('Pustaka pemindai gagal dimuat. Cek koneksi internet.', true);
      return;
    }
    if (!window.isSecureContext) {
      setResult('Kamera hanya bisa dibuka lewat HTTPS atau localhost.', true);
      return;
    }

    readerDiv.style.display = 'block';
    html5QrCode = new Html5Qrcode('reader');

    Html5Qrcode.getCameras().then(function (devices) {
      if (!devices || !devices.length) {
        setResult('Tidak ada kamera terdeteksi. Gunakan mode Ambil/Unggah Foto.', true);
        readerDiv.style.display = 'none';
        switchTab('photo');
        return;
      }
      var cameraId = devices[devices.length - 1].id;
      html5QrCode.start(
        cameraId,
        { fps: 10, qrbox: 250 },
        onScanSuccess,
        function () { /* frame gagal decode, normal */ }
      ).then(function () {
        scanning = true;
        btn.textContent = 'Hentikan Pindai';
        setResult('Kamera aktif, arahkan ke QR pada label barang…');
      }).catch(function (err) {
        setResult('Kamera browser tidak bisa diakses (' + err + '). Beralih ke mode Ambil/Unggah Foto…', true);
        readerDiv.style.display = 'none';
        switchTab('photo');
      });
    }).catch(function (err) {
      setResult('Gagal mengakses kamera: ' + err + '. Beralih ke mode Ambil/Unggah Foto…', true);
      readerDiv.style.display = 'none';
      switchTab('photo');
    });
  });

  if (fileScanInput) {
    fileScanInput.addEventListener('change', function (ev) {
      var file = ev.target.files && ev.target.files[0];
      if (!file) return;
      setResult('Membaca QR dari foto…');

      if (typeof Html5Qrcode === 'undefined') {
        setResult('Pustaka pemindai gagal dimuat. Cek koneksi internet.', true);
        return;
      }

      stopScan(function () {
        var tempId = 'file-scan-temp';
        var tempDiv = document.getElementById(tempId);
        if (!tempDiv) {
          tempDiv = document.createElement('div');
          tempDiv.id = tempId;
          tempDiv.style.display = 'none';
          document.body.appendChild(tempDiv);
        }
        var fileScanner = new Html5Qrcode(tempId);
        fileScanner.scanFile(file, true)
          .then(function (decodedText) {
            setResult('Kode terbaca: ' + decodedText + ' — mencari barang…');
            applyFoundItem(decodedText);
            fileScanner.clear();
          })
          .catch(function (err) {
            setResult('Tidak ada QR yang terbaca di foto ini: ' + err, true);
            fileScanner.clear();
          })
          .finally(function () {
            fileScanInput.value = '';
          });
      });
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>