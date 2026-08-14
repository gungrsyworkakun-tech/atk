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
    
    // Cek jika ukuran melebihi upload_max_filesize di php.ini
    if ($_FILES['foto']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['foto']['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Gagal: Ukuran foto terlalu besar. Maksimal 2 MB.'];
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

// Cek jika POST terhapus oleh server karena file kelewat besar (post_max_size terlampaui)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && $_SERVER['CONTENT_LENGTH'] > 0) {
    flash_set('Gagal: Ukuran total file sangat besar sehingga ditolak server. Gunakan foto di bawah 2 MB.', 'error');
    redirect('data_barang.php');
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
                    $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=?, foto=? WHERE id=?');
                    $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $fotoBaru, $id]);
                    if ($fotoLama && is_file($fotoDir . $fotoLama)) @unlink($fotoDir . $fotoLama);
                } elseif ($hapusFotoCentang) {
                    $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=?, foto=NULL WHERE id=?');
                    $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $id]);
                    if ($fotoLama && is_file($fotoDir . $fotoLama)) @unlink($fotoDir . $fotoLama);
                } else {
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
    // Redirect ke edit agar user bisa langsung tambah Satuan Turunan jika diperlukan
    redirect('data_barang.php?action=edit&id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add_satuan') {
    csrf_check();
    $itemId = (int)$_POST['item_id'];
    $namaSatuan = trim($_POST['nama_satuan'] ?? '');
    $isi = max(1, (int)($_POST['isi'] ?? 0));

    if ($namaSatuan === '') {
        flash_set('Nama satuan wajib diisi.', 'error');
    } else {
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
  <div><h1>Data Barang</h1><div class="sub">Manajemen inventaris, harga, dan satuan barang</div></div>
</div>

<?php 
// Hanya tampilkan form kelola satuan jika sedang dalam mode 'edit' setelah menyimpan
if ($editing): 
?>
<div class="card" style="border:1px solid var(--accent); box-shadow:0 0 0 4px rgba(59,130,246,.1);">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
    <h3 style="margin:0;">Kelola Satuan Turunan — <?= e($editing['nama']) ?></h3>
    <a href="data_barang.php" class="btn btn-ghost" style="padding:6px 12px; font-size:12px;">Selesai / Tutup</a>
  </div>
  <p class="helptext" style="margin-top:0;">Tambahkan satuan kemasan lain (mis. Box, Kardus). Admin bisa memilih satuan ini saat mencatat transaksi.</p>

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
    <div class="empty" style="margin-bottom:16px;"><b>Belum ada satuan turunan</b>Barang ini hanya bisa dicatat dalam satuan dasar (<?= e($editing['satuan']) ?>).</div>
  <?php endif; ?>

  <form method="post" class="form-row" style="align-items:end; padding-top:12px; border-top:1px dashed var(--line);">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="add_satuan">
    <input type="hidden" name="item_id" value="<?= e($editing['id']) ?>">
    <div class="field"><label>Nama satuan baru</label><input name="nama_satuan" placeholder="mis. Box, Kardus, Dus" required></div>
    <div class="field"><label>Setara berapa <?= e($editing['satuan']) ?>?</label><input type="number" min="1" name="isi" placeholder="mis. 10" required></div>
    <div class="field"><button class="btn btn-primary" type="submit">Tambah Satuan</button></div>
  </form>
</div>
<?php endif; ?>

<style>
  .db-summary-card{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;
    background:linear-gradient(135deg,var(--c-blue-bg),var(--c-teal-bg));border:1px solid var(--line);
    border-radius:var(--radius-lg);padding:16px 20px;margin:18px 0;}
  .db-summary-item{display:flex;flex-direction:column;}
  .db-summary-label{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;}
  .db-summary-value{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;margin-top:3px;}
  .db-summary-value.danger{color:#f87171;}

  .db-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
  .db-search{flex:1;min-width:220px;position:relative;}
  .db-search input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
    background:var(--paper-sunk);color:var(--text);font-size:13.5px;font-family:'Inter',Arial,sans-serif;box-sizing:border-box;}
  .db-search input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .db-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.55;pointer-events:none;}
  .db-count{font-size:12px;color:var(--text-dim);white-space:nowrap;}

  .db-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;}
  .db-card{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
    overflow:hidden;box-shadow:var(--shadow-card);cursor:pointer;display:flex;flex-direction:column;
    transition:transform .15s var(--ease), box-shadow .15s var(--ease), border-color .15s var(--ease);}
  .db-card:hover{transform:translateY(-3px);box-shadow:0 24px 48px -20px rgba(0,0,0,.45);border-color:var(--accent);}
  .db-card.editing{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.18);}

  /* Kartu Tambah Barang (+) */
  .db-card-add{border:2px dashed var(--line);background:transparent;justify-content:center;align-items:center;min-height:240px;text-align:center;padding:20px;}
  .db-card-add:hover{border-color:var(--accent);background:rgba(59,130,246,.04);}
  .db-card-add-icon{width:54px;height:54px;border-radius:50%;background:var(--paper-sunk);border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;font-size:28px;color:var(--accent);margin-bottom:12px;
    transition:transform .2s ease;}
  .db-card-add:hover .db-card-add-icon{transform:scale(1.1);background:var(--accent);color:#fff;}

  .db-photo{width:100%;aspect-ratio:1/1;background:var(--paper-sunk);display:flex;align-items:center;
    justify-content:center;overflow:hidden;position:relative;}
  .db-photo img{width:100%;height:100%;object-fit:cover;}
  .db-photo-placeholder{color:var(--text-faint);}
  .db-badge-stok{position:absolute;top:8px;left:8px;font-size:10px;font-weight:700;padding:3px 8px;
    border-radius:20px;backdrop-filter:blur(4px);}
  .db-badge-stok.ok{background:rgba(15,23,42,.7);color:#fff;}
  .db-badge-stok.low{background:rgba(239,68,68,.85);color:#fff;}

  .db-body{padding:12px 13px 14px;display:flex;flex-direction:column;gap:5px;flex:1;}
  .db-name{font-size:12.5px;font-weight:600;line-height:1.35;min-height:34px;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
  .db-code{font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--text-faint);}
  .db-meta-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:2px;}
  .db-satuan-extra{font-size:10px;color:var(--text-dim);}

  /* ---------- Modal Form Ala Shopee ---------- */
  .db-modal-overlay{position:fixed;inset:0;background:rgba(6,9,16,.75);z-index:200;
    display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px);}
  .db-modal-overlay.show{display:flex;}
  .db-modal{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius-lg);
    width:min(850px,100%);max-height:92vh;overflow-y:auto;box-shadow:var(--shadow-pop);position:relative;}
  .db-modal-close{position:absolute;top:16px;right:16px;width:34px;height:34px;border-radius:50%;
    background:var(--paper-sunk);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;
    cursor:pointer;color:var(--text-dim);z-index:10;transition:background .15s ease;}
  .db-modal-close:hover{background:var(--accent);color:#fff;}
  
  .db-modal-inner{display:grid;grid-template-columns:300px 1fr;gap:0;}
  @media (max-width:720px){ .db-modal-inner{grid-template-columns:1fr;} }

  .db-modal-photo{background:var(--paper-sunk);display:flex;align-items:center;justify-content:center;
    aspect-ratio:1/1;overflow:hidden;position:relative;border-right:1px solid var(--line-soft);}
  .db-modal-photo img{width:100%;height:100%;object-fit:cover;}

  .db-modal-info{padding:26px 28px;}
  
  /* Input Custom */
  .db-shopee-title-input { width: 100%; font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 700; border: none; background: transparent; color: var(--text); padding: 0; outline: none; margin-bottom: 15px; }
  .db-shopee-title-input:focus { border-bottom: 2px dashed var(--accent); }
  .db-shopee-title-input::placeholder { color: var(--text-dim); }

  .db-shopee-label { display: block; font-size: 11px; color: var(--text-dim); text-transform: uppercase; letter-spacing: .04em; font-weight: 700; margin-bottom: 6px; }
  .db-shopee-input { width: 100%; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: var(--radius-sm); background: var(--paper-sunk); color: var(--text); font-size: 13.5px; box-sizing: border-box; }
  .db-shopee-input:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px rgba(59,130,246,.16); }

  .db-modal-price-box { background: var(--c-blue-bg); border: 1px solid rgba(59,130,246,.25); border-radius: var(--radius-sm); padding: 12px 16px; margin-bottom: 18px; }
  .db-modal-price-label { font-size: 11px; color: var(--text-dim); text-transform: uppercase; font-weight: 700; }
  .db-shopee-price-input { font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; color: var(--accent); background: transparent; border: none; outline: none; width: 100%; margin-top: 2px; }
</style>

<div class="db-summary-card">
  <div class="db-summary-item">
    <div class="db-summary-label">Total Jenis Barang</div>
    <div class="db-summary-value"><?= count($items) ?></div>
  </div>
  <div class="db-summary-item">
    <div class="db-summary-label">Stok Menipis</div>
    <?php $jumlahMenipis = count(array_filter($items, fn($it) => (int)$it['stok'] <= (int)$it['stok_minimum'] && (int)$it['stok_minimum'] > 0)); ?>
    <div class="db-summary-value <?= $jumlahMenipis > 0 ? 'danger' : '' ?>"><?= $jumlahMenipis ?></div>
  </div>
</div>

<div class="db-toolbar">
  <form method="get" class="db-search" style="margin:0;">
    <?= icon('search', 15) ?>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari kode, nama, atau jenis barang...">
  </form>
  <span class="db-count"><?= count($items) ?> barang<?= $q !== '' ? ' ditemukan' : '' ?></span>
  <?php if ($q !== ''): ?><a class="btn btn-ghost" href="data_barang.php" style="font-size:12.5px;padding:7px 12px;">Reset</a><?php endif; ?>
</div>

<div class="db-grid">
  <!-- KARTU 1: Tombol Tambah Barang (+) -->
  <div class="db-card db-card-add" id="btnTambahBarang">
    <div class="db-card-add-icon">+</div>
    <div style="font-size:14px;font-weight:700;color:var(--text);">Tambah Barang</div>
    <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Klik untuk memasukkan data barang baru</div>
  </div>

  <?php if ($items): ?>
    <?php foreach ($items as $it):
      $stokMenipis = (int)$it['stok'] <= (int)$it['stok_minimum'] && (int)$it['stok_minimum'] > 0;
      $satuanList = [];
      if (!empty($satuanCounts[$it['id']])) {
          $stmtS = $pdo->prepare('SELECT nama_satuan, isi FROM item_satuan WHERE item_id = ? ORDER BY isi');
          $stmtS->execute([$it['id']]);
          $satuanList = $stmtS->fetchAll();
      }
      $itemJson = json_encode([
          'id' => $it['id'], 'kode' => $it['kode'], 'nama' => $it['nama'], 'jenis' => $it['jenis'],
          'satuan' => $it['satuan'], 'stok' => (int)$it['stok'], 'stokMinimum' => (int)$it['stok_minimum'],
          'harga' => (float)($it['harga'] ?? 0), 'tahunMasuk' => (int)$it['tahun_masuk'], 'hasFoto' => !empty($it['foto']),
          'satuanList' => array_map(fn($s) => ['nama' => $s['nama_satuan'], 'isi' => (int)$s['isi']], $satuanList),
      ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
      $isEditing = $editing && (int)$editing['id'] === (int)$it['id'];
    ?>
      <div class="db-card js-open-detail <?= $isEditing ? 'editing' : '' ?>" data-item='<?= $itemJson ?>'>
        <div class="db-photo">
          <?php if (!empty($it['foto'])): ?>
            <img src="serve_foto_barang.php?id=<?= (int)$it['id'] ?>" alt="Foto <?= e($it['nama']) ?>">
          <?php else: ?>
            <div class="db-photo-placeholder"><?= icon('box', 30) ?></div>
          <?php endif; ?>
          <span class="db-badge-stok <?= $stokMenipis ? 'low' : 'ok' ?>"><?= $stokMenipis ? 'Menipis' : 'Stok ' . (int)$it['stok'] ?></span>
        </div>
        <div class="db-body">
          <div class="db-name"><?= e($it['nama']) ?></div>
          <div class="db-code"><?= e($it['kode']) ?></div>
          <div class="db-meta-row">
            <span class="unit-badge"><?= e($it['satuan']) ?></span>
            <?php if (!empty($satuanCounts[$it['id']])): ?>
              <span class="db-satuan-extra">+<?= (int)$satuanCounts[$it['id']] ?> satuan lain</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if (!$items && $q !== ''): ?>
  <div class="empty"><b>Barang tidak ditemukan</b>Tidak ada barang yang cocok dengan pencarian "<?= e($q) ?>".</div>
<?php endif; ?>

<!-- ========================================== -->
<!-- MODAL ALA SHOPEE (INPUT / EDIT BARANG)    -->
<!-- ========================================== -->
<div class="db-modal-overlay" id="dbModalOverlay">
  <div class="db-modal">
    <div class="db-modal-close" id="dbModalClose">✕</div>
    <div class="db-modal-inner">
      
      <!-- KIRI: Preview Foto -->
      <div class="db-modal-photo">
        <img id="dbModalPhotoPreview" src="" alt="Preview Foto" style="display:none;">
        <div id="dbModalPhotoPlaceholder" class="db-photo-placeholder" style="display:flex;align-items:center;justify-content:center;">
          <?= icon('box', 48) ?>
        </div>
      </div>

      <!-- KANAN: Form Detail -->
      <div class="db-modal-info">
        <form method="post" id="dbModalForm" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="save">
          <input type="hidden" name="id" id="dbModalIdInput">

          <!-- Nama Barang -->
          <input type="text" name="nama" id="dbModalNama" class="db-shopee-title-input" placeholder="Masukkan Nama Barang..." required>

          <!-- Harga -->
          <div class="db-modal-price-box">
             <div class="db-modal-price-label">Harga Satuan (Rp)</div>
             <input type="number" min="0" step="1" name="harga" id="dbModalHarga" class="db-shopee-price-input" placeholder="0" required>
          </div>

          <!-- Grid Input Lainnya -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div>
              <label class="db-shopee-label">Kode Barang</label>
              <input type="text" name="kode" id="dbModalKode" class="db-shopee-input" placeholder="mis. ATK-0003" required>
            </div>
            <div>
              <label class="db-shopee-label">Jenis / Kategori</label>
              <input type="text" name="jenis" id="dbModalJenis" class="db-shopee-input" placeholder="mis. Alat Tulis">
            </div>
            <div>
              <label class="db-shopee-label">Satuan Dasar</label>
              <input type="text" name="satuan" id="dbModalSatuan" class="db-shopee-input" placeholder="rim, pcs, pack" required>
            </div>
            <div>
              <label class="db-shopee-label">Tahun Masuk</label>
              <input type="number" name="tahun_masuk" id="dbModalTahun" class="db-shopee-input" placeholder="<?= date('Y') ?>">
            </div>
            <div>
              <label class="db-shopee-label">Jumlah Stok</label>
              <input type="number" min="0" name="stok" id="dbModalStok" class="db-shopee-input" placeholder="0" required>
            </div>
            <div>
              <label class="db-shopee-label">Stok Minimum</label>
              <input type="number" min="0" name="stok_minimum" id="dbModalStokMin" class="db-shopee-input" placeholder="0" required>
            </div>
            <div style="grid-column: 1 / -1;">
              <label class="db-shopee-label">Upload Foto Barang</label>
              <input type="file" name="foto" id="dbModalFoto" accept="image/jpeg,image/png,image/webp" class="db-shopee-input" style="padding:6px 10px;">
            </div>
          </div>

          <!-- Hapus Foto & Kelola Satuan Text -->
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <div id="dbModalHapusFotoWrap" style="display:none;">
              <label style="font-size:12px; font-weight:600; cursor:pointer; color:var(--text-dim); display:flex; align-items:center; gap:6px;">
                <input type="checkbox" name="hapus_foto" value="1"> Hapus foto lama
              </label>
            </div>
            <div id="dbModalUnitInfo" style="font-size:11px; color:var(--text-dim); font-weight:600;"></div>
          </div>

          <!-- Tombol Aksi -->
          <div style="display:flex;justify-content:flex-end;gap:10px;border-top:1px solid var(--line-soft);padding-top:16px;">
            <a href="#" class="btn btn-ghost" id="dbModalHapusBtn" style="display:none; color:#f87171;" onclick="return confirm('Yakin ingin menghapus barang ini?');">Hapus Barang</a>
            <button type="button" class="btn btn-ghost" id="dbModalCancelBtn">Batal</button>
            <button type="submit" class="btn btn-primary" style="padding:10px 22px;font-size:13.5px;">Simpan Data</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('dbModalOverlay');
  var closeBtn = document.getElementById('dbModalClose');
  var cancelBtn = document.getElementById('dbModalCancelBtn');
  var form = document.getElementById('dbModalForm');
  var btnTambah = document.getElementById('btnTambahBarang');
  
  var photoPreview = document.getElementById('dbModalPhotoPreview');
  var photoPlaceholder = document.getElementById('dbModalPhotoPlaceholder');
  var hapusFotoWrap = document.getElementById('dbModalHapusFotoWrap');
  var hapusBtn = document.getElementById('dbModalHapusBtn');
  var unitInfo = document.getElementById('dbModalUnitInfo');
  
  var inputId = document.getElementById('dbModalIdInput');
  var inputNama = document.getElementById('dbModalNama');
  var inputHarga = document.getElementById('dbModalHarga');
  var inputKode = document.getElementById('dbModalKode');
  var inputJenis = document.getElementById('dbModalJenis');
  var inputSatuan = document.getElementById('dbModalSatuan');
  var inputStok = document.getElementById('dbModalStok');
  var inputStokMin = document.getElementById('dbModalStokMin');
  var inputTahun = document.getElementById('dbModalTahun');
  var inputFoto = document.getElementById('dbModalFoto');

  var nextKodeGenerator = '<?= e($nextKode) ?>';

  // Live preview foto & VALIDASI FRONTEND UNTUK UKURAN MAKS 2MB
  inputFoto.addEventListener('change', function (e) {
    var file = e.target.files[0];
    if (file) {
      // Cek ukuran file (Maksimal 2 MB = 2 * 1024 * 1024 bytes)
      if (file.size > 2 * 1024 * 1024) {
        alert('Gagal: Ukuran foto terlalu besar! Maksimal 2 MB.');
        
        // Reset input file dan preview gambar
        inputFoto.value = ''; 
        photoPreview.style.display = 'none';
        photoPreview.src = '';
        photoPlaceholder.style.display = 'flex';
        return;
      }

      // Jika lolos, tampilkan preview foto
      var reader = new FileReader();
      reader.onload = function (evt) {
        photoPreview.src = evt.target.result;
        photoPreview.style.display = 'block';
        photoPlaceholder.style.display = 'none';
      };
      reader.readAsDataURL(file);
    }
  });

  function openAddModal() {
    form.reset();
    inputId.value = '';
    
    photoPreview.style.display = 'none';
    photoPreview.src = '';
    photoPlaceholder.style.display = 'flex';
    hapusFotoWrap.style.display = 'none';
    hapusBtn.style.display = 'none';
    unitInfo.textContent = '';
    
    inputKode.value = nextKodeGenerator;
    inputTahun.value = new Date().getFullYear();
    inputSatuan.value = 'pcs';

    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function openEditModal(item) {
    form.reset();
    inputId.value = item.id;
    
    inputNama.value = item.nama || '';
    inputHarga.value = item.harga || 0;
    inputKode.value = item.kode || '';
    inputJenis.value = item.jenis || '';
    inputSatuan.value = item.satuan || 'pcs';
    inputStok.value = item.stok || 0;
    inputStokMin.value = item.stokMinimum || 0;
    inputTahun.value = item.tahunMasuk || new Date().getFullYear();

    if (item.hasFoto) {
      photoPreview.src = 'serve_foto_barang.php?id=' + item.id;
      photoPreview.style.display = 'block';
      photoPlaceholder.style.display = 'none';
      hapusFotoWrap.style.display = 'block';
    } else {
      photoPreview.style.display = 'none';
      photoPreview.src = '';
      photoPlaceholder.style.display = 'flex';
      hapusFotoWrap.style.display = 'none';
    }

    if (item.satuanList && item.satuanList.length > 0) {
       unitInfo.textContent = 'Memiliki ' + item.satuanList.length + ' satuan turunan.';
    } else {
       unitInfo.textContent = 'Belum ada satuan turunan.';
    }

    hapusBtn.href = 'data_barang.php?action=delete&id=' + item.id;
    hapusBtn.style.display = 'inline-block';

    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  if (btnTambah) btnTambah.addEventListener('click', openAddModal);

  document.querySelectorAll('.js-open-detail').forEach(function (card) {
    card.addEventListener('click', function () {
      try {
        openEditModal(JSON.parse(card.dataset.item));
      } catch (e) {
        console.error('Data parsing error', e);
      }
    });
  });

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('show')) closeModal(); });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>