<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('data_barang');

$action = $_GET['action'] ?? 'list';
$editing = null;

// Folder penyimpanan foto barang
$fotoDir = __DIR__ . '/uploads/barang/';
if (!is_dir($fotoDir)) mkdir($fotoDir, 0755, true);
$allowedFotoTypes = ['image/jpeg', 'image/png', 'image/webp'];

/** Simpan file foto yang diunggah, kembalikan nama file aman atau null jika tidak ada/gagal. */
function simpan_foto_barang($fotoDir, $allowedFotoTypes, $kodeBarang) {
    if (empty($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null]; // tidak ada file diunggah
    }
    if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Gagal mengunggah foto. Coba lagi.'];
    }
    if (!in_array($_FILES['foto']['type'], $allowedFotoTypes, true)) {
        return [null, 'Format foto harus JPG, PNG, atau WebP.'];
    }
    if ($_FILES['foto']['size'] > 2 * 1024 * 1024) {
        return [null, 'Ukuran foto maksimal 2 MB.'];
    }
    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
    $safeName = 'barang-' . preg_replace('/[^a-zA-Z0-9\-]/', '', $kodeBarang) . '-' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $fotoDir . $safeName)) {
        return [null, 'Gagal menyimpan foto di server.'];
    }
    return [$safeName, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save') {
    csrf_check();
    $id = $_POST['id'] ?? '';
    $kode = trim($_POST['kode']);
    $nama = trim($_POST['nama']);
    $jenis = trim($_POST['jenis']);
    $satuan = trim($_POST['satuan']) ?: 'pcs';
    $stok = max(0, (int)$_POST['stok']);
    $stokMin = max(0, (int)$_POST['stok_minimum']);
    $harga = max(0, (float)$_POST['harga']);
    $tahun = (int)$_POST['tahun_masuk'];

    if ($nama === '' || $kode === '') {
        flash_set('Kode dan nama barang wajib diisi.', 'error');
    } else {
        // Ambil foto lama (kalau sedang mengubah barang) untuk keperluan hapus/ganti.
        $fotoLama = null;
        if ($id) {
            $cek = $pdo->prepare('SELECT foto FROM items WHERE id = ?');
            $cek->execute([$id]);
            $fotoLama = $cek->fetch()['foto'] ?? null;
        }

        [$fotoBaru, $fotoError] = simpan_foto_barang($fotoDir, $allowedFotoTypes, $kode);
        if ($fotoError) {
            flash_set($fotoError, 'error');
            redirect($id ? ('data_barang.php?action=edit&id=' . $id) : 'data_barang.php');
        }

        $hapusFotoCentang = isset($_POST['hapus_foto']);

        try {
            if ($id) {
                if ($fotoBaru) {
                    // Foto baru diunggah: pakai foto baru, hapus file foto lama dari disk.
                    $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=?, foto=? WHERE id=?');
                    $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $fotoBaru, $id]);
                    if ($fotoLama && is_file($fotoDir . $fotoLama)) @unlink($fotoDir . $fotoLama);
                } elseif ($hapusFotoCentang) {
                    // Centang "hapus foto" tanpa unggah baru: kosongkan foto.
                    $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=?, foto=NULL WHERE id=?');
                    $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $id]);
                    if ($fotoLama && is_file($fotoDir . $fotoLama)) @unlink($fotoDir . $fotoLama);
                } else {
                    // Tidak ada perubahan foto.
                    $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=? WHERE id=?');
                    $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $id]);
                }
                flash_set('Barang diperbarui.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO items (kode, nama, jenis, satuan, stok, stok_minimum, harga, tahun_masuk, foto) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $fotoBaru]);
                flash_set('Barang ditambahkan.');
                $id = $pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            flash_set('Kode barang sudah dipakai, gunakan kode lain.', 'error');
            redirect('data_barang.php');
        }
    }
    // Setelah simpan, arahkan langsung ke mode "Ubah" barang ini supaya bisa lanjut atur detail satuan turunan.
    redirect('data_barang.php?action=edit&id=' . $id);
}

// Tambah satuan turunan (mis. "1 Box = 10 Rim") untuk barang yang sudah ada.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add_satuan') {
    csrf_check();
    $itemId = (int)$_POST['item_id'];
    $namaSatuan = trim($_POST['nama_satuan'] ?? '');
    $isi = max(1, (int)($_POST['isi'] ?? 0));

    if ($namaSatuan === '') {
        flash_set('Nama satuan wajib diisi.', 'error');
    } else {
        // Cegah duplikat nama satuan pada barang yang sama.
        $check = $pdo->prepare('SELECT id FROM item_satuan WHERE item_id = ? AND nama_satuan = ?');
        $check->execute([$itemId, $namaSatuan]);
        if ($check->fetch()) {
            flash_set('Satuan "' . $namaSatuan . '" sudah ada untuk barang ini.', 'error');
        } else {
            $pdo->prepare('INSERT INTO item_satuan (item_id, nama_satuan, isi) VALUES (?,?,?)')->execute([$itemId, $namaSatuan, $isi]);
            flash_set('Satuan "' . $namaSatuan . '" ditambahkan (1 ' . $namaSatuan . ' = ' . $isi . ' satuan dasar).');
        }
    }
    redirect('data_barang.php?action=edit&id=' . $itemId);
}

if ($action === 'delete_satuan' && isset($_GET['id']) && isset($_GET['item_id'])) {
    $pdo->prepare('DELETE FROM item_satuan WHERE id = ? AND item_id = ?')->execute([$_GET['id'], $_GET['item_id']]);
    flash_set('Satuan turunan dihapus.');
    redirect('data_barang.php?action=edit&id=' . (int)$_GET['item_id']);
}

if ($action === 'delete' && isset($_GET['id'])) {
    $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$_GET['id']]);
    flash_set('Barang dihapus.');
    redirect('data_barang.php');
}

if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$_GET['id']]);
    $editing = $stmt->fetch();
    if (!$editing) { flash_set('Barang tidak ditemukan.', 'error'); redirect('data_barang.php'); }
}

$editingSatuanList = [];
if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM item_satuan WHERE item_id = ? ORDER BY isi');
    $stmt->execute([$editing['id']]);
    $editingSatuanList = $stmt->fetchAll();
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT * FROM items WHERE kode LIKE ? OR nama LIKE ? OR jenis LIKE ? ORDER BY nama');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $items = $stmt->fetchAll();
} else {
    $items = $pdo->query('SELECT * FROM items ORDER BY nama')->fetchAll();
}
$nextKode = 'ATK-' . str_pad((string)($pdo->query('SELECT COUNT(*) c FROM items')->fetch()['c'] + 1), 4, '0', STR_PAD_LEFT);

$satuanCounts = [];
$countRows = $pdo->query('SELECT item_id, COUNT(*) c FROM item_satuan GROUP BY item_id')->fetchAll();
foreach ($countRows as $cr) $satuanCounts[$cr['item_id']] = (int)$cr['c'];

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Data Barang</h1><div class="sub">Jenis barang, satuan, dan jumlah stok</div></div>
</div>

<div class="card">
  <h3><?= $editing ? 'Ubah barang' : 'Tambah barang' ?></h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= e($editing['id']) ?>"><?php endif; ?>
    <div class="form-row">
      <div class="field"><label>Kode barang</label><input class="mono" name="kode" value="<?= e($editing['kode'] ?? $nextKode) ?>" required></div>
      <div class="field"><label>Nama barang</label><input name="nama" value="<?= e($editing['nama'] ?? '') ?>" required placeholder="mis. Kertas HVS A4"></div>
      <div class="field"><label>Jenis</label><input name="jenis" value="<?= e($editing['jenis'] ?? '') ?>" placeholder="mis. Alat tulis"></div>
      <div class="field">
        <label>Satuan dasar</label>
        <input name="satuan" value="<?= e($editing['satuan'] ?? 'rim') ?>" placeholder="mis. rim, pcs, lusin, botol" required>
      </div>
    </div>
    <div class="unit-hint-box">
     
    </div>
    <div class="form-row">
      <div class="field"><label>Stok (dalam satuan dasar)</label><input type="number" min="0" name="stok" value="<?= e($editing['stok'] ?? 0) ?>"></div>
      <div class="field"><label>Stok minimum</label><input type="number" min="0" name="stok_minimum" value="<?= e($editing['stok_minimum'] ?? 0) ?>"></div>
      <div class="field"><label>Harga satuan (Rp)</label><input type="number" min="0" step="1" name="harga" value="<?= e($editing['harga'] ?? 0) ?>"></div>
      <div class="field"><label>Tahun masuk</label><input type="number" name="tahun_masuk" value="<?= e($editing['tahun_masuk'] ?? date('Y')) ?>"></div>
    </div>
    <div class="form-row">
      <div class="field" style="flex:1;">
        <label>Foto barang</label>
        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp">
        <div class="helptext" style="margin:4px 0 0;">Format JPG/PNG/WebP, maksimal 2 MB.</div>
      </div>
      <?php if (!empty($editing['foto'])): ?>
      <div class="field" style="flex:0 0 auto;">
        <label>Foto saat ini</label>
        <div style="display:flex;align-items:center;gap:10px;">
          <img src="serve_foto_barang.php?id=<?= (int)$editing['id'] ?>&v=<?= time() ?>" alt="Foto <?= e($editing['nama']) ?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--line);">
          <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;">
            <input type="checkbox" name="hapus_foto" value="1"> Hapus foto
          </label>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-primary" type="submit">Simpan</button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="data_barang.php">Batal</a><?php endif; ?>
    </div>
  </form>
</div>

<?php if ($editing): ?>
<div class="card">
  <h3>Kelola satuan turunan — <?= e($editing['nama']) ?></h3>
  <p class="helptext" style="margin-top:0;">Tambahkan satuan kemasan lain untuk barang ini (mis. Box, Kardus, Dus). Admin gudang & Admin Bidang nanti bisa memilih satuan mana saja yang tersedia di sini saat mencatat transaksi atau mengajukan permintaan.</p>

  <?php if ($editingSatuanList): ?>
  <table class="unit-table" style="margin-bottom:16px;">
    <thead><tr><th>Nama satuan</th><th>Setara dengan</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($editingSatuanList as $s): ?>
      <tr>
        <td><span class="unit-badge"><?= e($s['nama_satuan']) ?></span></td>
        <td>1 <?= e($s['nama_satuan']) ?> = <b><?= (int)$s['isi'] ?> <?= e($editing['satuan']) ?></b></td>
        <td class="actions-cell">
          <a class="icon-btn danger" href="?action=delete_satuan&id=<?= e($s['id']) ?>&item_id=<?= e($editing['id']) ?>" onclick="return confirm('Hapus satuan ini?');">Hapus</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <div class="empty" style="margin-bottom:16px;"><b>Belum ada satuan turunan</b>Barang ini hanya bisa dicatat dalam satuan dasar (<?= e($editing['satuan']) ?>). Tambahkan satuan lain lewat form di bawah, misalnya kalau barang datang per box/kardus.</div>
  <?php endif; ?>

  <form method="post" class="form-row" style="align-items:end;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="add_satuan">
    <input type="hidden" name="item_id" value="<?= e($editing['id']) ?>">
    <div class="field"><label>Nama satuan baru</label><input name="nama_satuan" placeholder="mis. Box, Kardus, Dus" required></div>
    <div class="field"><label>Setara berapa <?= e($editing['satuan']) ?>?</label><input type="number" min="1" name="isi" placeholder="mis. 10" required></div>
    <div class="field"><button class="btn btn-primary" type="submit">Tambah Satuan</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
  <form method="get" style="display:flex;gap:8px;">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari kode, nama, atau jenis barang..." style="flex:1;padding:9px 12px;border:1px solid var(--line);border-radius:6px;background:transparent;color:inherit;">
    <button class="btn btn-primary" type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="data_barang.php">Reset</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if (!$items): ?>
    <div class="empty"><b><?= $q !== '' ? 'Barang tidak ditemukan' : 'Belum ada barang' ?></b><?= $q !== '' ? 'Tidak ada barang yang cocok dengan pencarian "' . e($q) . '".' : 'Tambahkan jenis ATK yang ingin dikelola lewat formulir di atas.' ?></div>
  <?php else: ?>
  <table>
    <thead><tr><th>Foto</th><th>Kode</th><th>Nama</th><th>Jenis</th><th>Satuan</th><th>Stok</th><th>Stok minimum</th><th>Tahun masuk</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td>
          <?php if (!empty($it['foto'])): ?>
            <img src="serve_foto_barang.php?id=<?= (int)$it['id'] ?>" alt="Foto <?= e($it['nama']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--line);">
          <?php else: ?>
            <span style="display:inline-flex;width:44px;height:44px;align-items:center;justify-content:center;border-radius:6px;border:1px dashed var(--line);color:var(--text-dim);font-size:10px;text-align:center;">Tanpa foto</span>
          <?php endif; ?>
        </td>
        <td class="mono"><?= e($it['kode']) ?></td>
        <td><?= e($it['nama']) ?></td>
        <td><?= e($it['jenis']) ?></td>
        <td>
          <span class="unit-badge"><?= e($it['satuan']) ?></span>
          <?php if (!empty($satuanCounts[$it['id']])): ?>
            <span class="unit-extra-count">+<?= (int)$satuanCounts[$it['id']] ?> satuan lain</span>
          <?php endif; ?>
        </td>
        <td class="mono"><?= (int)$it['stok'] ?></td>
        <td class="mono"><?= (int)$it['stok_minimum'] ?></td>
        <td class="mono"><?= (int)$it['tahun_masuk'] ?></td>
        <td class="actions-cell">
          <a class="icon-btn" href="?action=edit&id=<?= e($it['id']) ?>">Ubah</a>
          <a class="icon-btn danger" href="?action=delete&id=<?= e($it['id']) ?>" onclick="return confirm('Hapus barang ini? Riwayat transaksi terkait ikut terhapus.');">Hapus</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>