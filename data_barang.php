<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('data_barang');

// Folder penyimpanan foto barang
$fotoDir = __DIR__ . '/uploads/barang/';
if (!is_dir($fotoDir)) mkdir($fotoDir, 0755, true);
$allowedFotoTypes = ['image/jpeg', 'image/png', 'image/webp'];

/** Simpan file foto yang diunggah, kembalikan nama file aman atau null jika tidak ada/gagal. */
function simpan_foto_barang($fotoDir, $allowedFotoTypes, $kodeBarang) {
    if (empty($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
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
    $returnQ = trim($_POST['return_q'] ?? '');

    if ($nama === '' || $kode === '') {
        flash_set('Kode dan nama barang wajib diisi.', 'error');
        redirect('data_barang.php' . ($returnQ !== '' ? '?q=' . urlencode($returnQ) : ''));
    }

    $fotoLama = null;
    if ($id) {
        $cek = $pdo->prepare('SELECT foto FROM items WHERE id = ?');
        $cek->execute([$id]);
        $fotoLama = $cek->fetch()['foto'] ?? null;
    }

    [$fotoBaru, $fotoError] = simpan_foto_barang($fotoDir, $allowedFotoTypes, $kode);
    if ($fotoError) {
        flash_set($fotoError, 'error');
        redirect('data_barang.php' . ($returnQ !== '' ? '?q=' . urlencode($returnQ) : ''));
    }
    $hapusFotoCentang = isset($_POST['hapus_foto']);

    $pdo->beginTransaction();
    try {
        if ($id) {
            if ($fotoBaru) {
                $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=?, foto=? WHERE id=?');
                $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $fotoBaru, $id]);
                $fotoUntukHapus = $fotoLama;
            } elseif ($hapusFotoCentang) {
                $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=?, foto=NULL WHERE id=?');
                $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $id]);
                $fotoUntukHapus = $fotoLama;
            } else {
                $stmt = $pdo->prepare('UPDATE items SET kode=?, nama=?, jenis=?, satuan=?, stok=?, stok_minimum=?, harga=?, tahun_masuk=? WHERE id=?');
                $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $id]);
                $fotoUntukHapus = null;
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO items (kode, nama, jenis, satuan, stok, stok_minimum, harga, tahun_masuk, foto) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$kode, $nama, $jenis, $satuan, $stok, $stokMin, $harga, $tahun, $fotoBaru]);
            $id = $pdo->lastInsertId();
            $fotoUntukHapus = null;
        }

        // ---------- Kelola satuan turunan, langsung dalam request yang sama ----------
        $deleteIds = array_filter(array_map('intval', $_POST['satuan_delete_ids'] ?? []));
        if ($deleteIds) {
            $inPlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM item_satuan WHERE item_id = ? AND id IN ($inPlaceholders)");
            $stmt->execute(array_merge([$id], $deleteIds));
        }

        $newNama = $_POST['satuan_new_nama'] ?? [];
        $newIsi = $_POST['satuan_new_isi'] ?? [];
        if ($newNama) {
            $stmtCheck = $pdo->prepare('SELECT id FROM item_satuan WHERE item_id = ? AND nama_satuan = ?');
            $stmtIns = $pdo->prepare('INSERT INTO item_satuan (item_id, nama_satuan, isi) VALUES (?,?,?)');
            foreach ($newNama as $i => $nm) {
                $nm = trim($nm);
                $isi = max(1, (int)($newIsi[$i] ?? 0));
                if ($nm === '') continue;
                $stmtCheck->execute([$id, $nm]);
                if ($stmtCheck->fetch()) continue;
                $stmtIns->execute([$id, $nm, $isi]);
            }
        }

        $pdo->commit();
        if ($fotoUntukHapus && is_file($fotoDir . $fotoUntukHapus)) @unlink($fotoDir . $fotoUntukHapus);

        flash_set('Barang berhasil disimpan.');
    } catch (PDOException $e) {
        $pdo->rollBack();
        flash_set('Kode barang sudah dipakai, gunakan kode lain.', 'error');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Gagal menyimpan data.', 'error');
    }

    redirect('data_barang.php' . ($returnQ !== '' ? '?q=' . urlencode($returnQ) : ''));
}

if (($_GET['action'] ?? '') === 'delete_satuan' && isset($_GET['id']) && isset($_GET['item_id'])) {
    $pdo->prepare('DELETE FROM item_satuan WHERE id = ? AND item_id = ?')->execute([$_GET['id'], $_GET['item_id']]);
    flash_set('Satuan turunan dihapus.');
    redirect('data_barang.php');
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$_GET['id']]);
    flash_set('Barang dihapus.');
    redirect('data_barang.php');
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

  /* ---------- Modal shell ---------- */
  .db-modal-overlay{position:fixed;inset:0;background:rgba(6,9,16,.75);z-index:200;
    display:none;align-items:flex-start;justify-content:center;padding:32px 20px;backdrop-filter:blur(3px);overflow-y:auto;}
  .db-modal-overlay.show{display:flex;}
  .db-modal{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius-lg);
    width:min(920px,100%);box-shadow:var(--shadow-pop);position:relative;margin-bottom:32px;}
  .db-modal-close{position:absolute;top:16px;right:16px;width:34px;height:34px;border-radius:50%;
    background:var(--paper-sunk);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;
    cursor:pointer;color:var(--text-dim);z-index:10;transition:background .15s ease;}
  .db-modal-close:hover{background:var(--accent);color:#fff;}

  .db-modal-header{padding:22px 28px 18px;border-bottom:1px solid var(--line-soft);}
  .db-modal-header h3{margin:0;font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:700;}
  .db-modal-header p{margin:4px 0 0;font-size:12.5px;color:var(--text-dim);}

  .db-modal-body{padding:22px 28px 26px;display:grid;grid-template-columns:220px 1fr;gap:26px;}
  @media (max-width:760px){ .db-modal-body{grid-template-columns:1fr;} }

  /* Kolom foto kiri */
  .db-photo-col{display:flex;flex-direction:column;gap:10px;}
  .db-modal-photo{width:100%;aspect-ratio:1/1;background:var(--paper-sunk);border:1.5px dashed var(--line);
    border-radius:var(--radius);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;}
  .db-modal-photo img{width:100%;height:100%;object-fit:cover;}
  .db-photo-upload-btn{width:100%;font-size:12px;padding:9px 10px;}
  .db-photo-hint{font-size:10.5px;color:var(--text-faint);text-align:center;line-height:1.5;}

  /* Kolom form kanan */
  .db-form-col{display:flex;flex-direction:column;gap:20px;min-width:0;}

  .db-section{border:1px solid var(--line-soft);border-radius:var(--radius-sm);background:var(--paper-sunk);
    padding:16px 18px;}
  .db-section-head{display:flex;align-items:center;gap:9px;margin-bottom:14px;}
  .db-section-ic{width:26px;height:26px;border-radius:8px;display:flex;align-items:center;justify-content:center;
    flex-shrink:0;background:var(--c-blue-bg);color:var(--accent);}
  .db-section-title{font-size:12.5px;font-weight:700;color:var(--text);}
  .db-section-sub{font-size:10.5px;color:var(--text-dim);margin-top:1px;}

  .db-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .db-field-grid.cols-3{grid-template-columns:1fr 1fr 1fr;}
  @media (max-width:480px){ .db-field-grid,.db-field-grid.cols-3{grid-template-columns:1fr;} }
  .db-field{display:flex;flex-direction:column;gap:6px;}
  .db-field.span-2{grid-column:span 2;}
  @media (max-width:480px){ .db-field.span-2{grid-column:span 1;} }

  .db-shopee-label{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;font-weight:700;}
  .db-shopee-input{width:100%;padding:9px 11px;border:1.5px solid var(--line);border-radius:8px;
    background:var(--paper-card);color:var(--text);font-size:13px;box-sizing:border-box;font-family:'Inter',Arial,sans-serif;}
  .db-shopee-input:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.16);}

  .db-nama-input{font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:700;padding:10px 12px;}

  .db-harga-wrap{position:relative;}
  .db-harga-wrap::before{content:'Rp';position:absolute;left:12px;top:50%;transform:translateY(-50%);
    font-size:12px;font-weight:700;color:var(--text-dim);pointer-events:none;}
  .db-harga-wrap input{padding-left:34px;font-family:'Space Grotesk',sans-serif;font-weight:700;color:var(--accent);}

  .db-hapus-foto-check{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--text-dim);
    cursor:pointer;font-weight:500;margin-top:2px;}

  /* Satuan turunan */
  .db-unit-list{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
  .db-unit-row{display:flex;align-items:center;gap:8px;background:var(--paper-card);border:1px solid var(--line);
    border-radius:8px;padding:7px 10px;font-size:12px;}
  .db-unit-row-badge{font-family:'IBM Plex Mono',monospace;font-weight:700;background:var(--c-indigo-bg);
    color:var(--c-indigo);padding:2px 8px;border-radius:10px;font-size:10.5px;flex-shrink:0;}
  .db-unit-row-text{flex:1;color:var(--text-dim);}
  .db-unit-row-remove{border:none;background:transparent;color:var(--text-faint);cursor:pointer;font-size:14px;
    padding:2px 6px;border-radius:6px;flex-shrink:0;}
  .db-unit-row-remove:hover{background:rgba(248,113,113,.15);color:#f87171;}
  .db-unit-empty{font-size:11.5px;color:var(--text-faint);font-style:italic;padding:2px 0 8px;}
  .db-unit-add-row{display:flex;gap:8px;align-items:center;}
  .db-unit-add-row input{padding:8px 10px;border:1.5px solid var(--line);border-radius:8px;background:var(--paper-card);
    color:var(--text);font-size:12px;box-sizing:border-box;}
  .db-unit-add-row input[name="_unit_nama_tmp"]{flex:1.4;}
  .db-unit-add-row input[name="_unit_isi_tmp"]{flex:1;}
  .db-unit-add-btn{flex-shrink:0;font-size:12px;padding:8px 14px;white-space:nowrap;}

  .db-modal-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;
    padding:16px 28px;border-top:1px solid var(--line-soft);background:var(--paper-sunk);
    border-radius:0 0 var(--radius-lg) var(--radius-lg);flex-wrap:wrap;}
  .db-modal-footer-right{display:flex;gap:10px;}
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
          $stmtS = $pdo->prepare('SELECT id, nama_satuan, isi FROM item_satuan WHERE item_id = ? ORDER BY isi');
          $stmtS->execute([$it['id']]);
          $satuanList = $stmtS->fetchAll();
      }
      $itemJson = json_encode([
          'id' => $it['id'], 'kode' => $it['kode'], 'nama' => $it['nama'], 'jenis' => $it['jenis'],
          'satuan' => $it['satuan'], 'stok' => (int)$it['stok'], 'stokMinimum' => (int)$it['stok_minimum'],
          'harga' => (float)($it['harga'] ?? 0), 'tahunMasuk' => (int)$it['tahun_masuk'], 'hasFoto' => !empty($it['foto']),
          'satuanList' => array_map(fn($s) => ['id' => (int)$s['id'], 'nama' => $s['nama_satuan'], 'isi' => (int)$s['isi']], $satuanList),
      ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    ?>
      <div class="db-card js-open-detail" data-item='<?= $itemJson ?>'>
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
<!-- MODAL (TAMBAH / UBAH BARANG + SATUAN)      -->
<!-- ========================================== -->
<div class="db-modal-overlay" id="dbModalOverlay">
  <div class="db-modal">
    <div class="db-modal-close" id="dbModalClose">✕</div>

    <div class="db-modal-header">
      <h3 id="dbModalTitle">Tambah Barang</h3>
      <p id="dbModalSubtitle">Isi informasi barang, harga, stok, foto, dan satuan sekaligus — semua tersimpan dalam satu klik.</p>
    </div>

    <form method="post" id="dbModalForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" id="dbModalIdInput">
      <input type="hidden" name="return_q" value="<?= e($q) ?>">
      <div id="dbHiddenSatuanFields"></div>

      <div class="db-modal-body">

        <!-- ===== Kolom kiri: Foto ===== -->
        <div class="db-photo-col">
          <div class="db-modal-photo">
            <img id="dbModalPhotoPreview" src="" alt="Preview Foto" style="display:none;">
            <div id="dbModalPhotoPlaceholder" class="db-photo-placeholder" style="display:flex;align-items:center;justify-content:center;">
              <?= icon('box', 44) ?>
            </div>
          </div>
          <label class="btn btn-ghost db-photo-upload-btn" for="dbModalFoto" style="cursor:pointer;">Pilih Foto</label>
          <input type="file" name="foto" id="dbModalFoto" accept="image/jpeg,image/png,image/webp" style="display:none;">
          <div class="db-photo-hint">JPG / PNG / WebP, maks 2 MB</div>

          <div id="dbModalHapusFotoWrap" style="display:none;">
            <label class="db-hapus-foto-check">
              <input type="checkbox" name="hapus_foto" value="1"> Hapus foto lama
            </label>
          </div>
        </div>

        <!-- ===== Kolom kanan: Form bertahap ===== -->
        <div class="db-form-col">

          <!-- Section 1: Informasi Dasar -->
          <div class="db-section">
            <div class="db-section-head">
              <div class="db-section-ic"><?= icon('box', 14) ?></div>
              <div>
                <div class="db-section-title">Informasi Dasar</div>
                <div class="db-section-sub">Nama, kode, dan kategori barang</div>
              </div>
            </div>

            <div class="db-field" style="margin-bottom:12px;">
              <span class="db-shopee-label">Nama Barang</span>
              <input type="text" name="nama" id="dbModalNama" class="db-shopee-input db-nama-input" placeholder="mis. Kertas HVS A4" required>
            </div>

            <div class="db-field-grid">
              <div class="db-field">
                <span class="db-shopee-label">Kode Barang</span>
                <input type="text" name="kode" id="dbModalKode" class="db-shopee-input" placeholder="mis. ATK-0003" required>
              </div>
              <div class="db-field">
                <span class="db-shopee-label">Jenis / Kategori</span>
                <input type="text" name="jenis" id="dbModalJenis" class="db-shopee-input" placeholder="mis. Alat Tulis">
              </div>
            </div>
          </div>

          <!-- Section 2: Harga & Stok -->
          <div class="db-section">
            <div class="db-section-head">
              <div class="db-section-ic"><?= icon('tag', 14) ?></div>
              <div>
                <div class="db-section-title">Harga &amp; Stok</div>
                <div class="db-section-sub">Harga satuan, jumlah stok, dan satuan dasar</div>
              </div>
            </div>

            <div class="db-field" style="margin-bottom:12px;">
              <span class="db-shopee-label">Harga Satuan</span>
              <div class="db-harga-wrap">
                <input type="number" min="0" step="1" name="harga" id="dbModalHarga" class="db-shopee-input" placeholder="0" required>
              </div>
            </div>

            <div class="db-field-grid cols-3">
              <div class="db-field">
                <span class="db-shopee-label">Satuan Dasar</span>
                <input type="text" name="satuan" id="dbModalSatuan" class="db-shopee-input" placeholder="rim / pcs" required>
              </div>
              <div class="db-field">
                <span class="db-shopee-label">Jumlah Stok</span>
                <input type="number" min="0" name="stok" id="dbModalStok" class="db-shopee-input" placeholder="0" required>
              </div>
              <div class="db-field">
                <span class="db-shopee-label">Stok Minimum</span>
                <input type="number" min="0" name="stok_minimum" id="dbModalStokMin" class="db-shopee-input" placeholder="0" required>
              </div>
            </div>

            <div class="db-field" style="margin-top:12px;max-width:160px;">
              <span class="db-shopee-label">Tahun Masuk</span>
              <input type="number" name="tahun_masuk" id="dbModalTahun" class="db-shopee-input" placeholder="<?= date('Y') ?>">
            </div>
          </div>

          <!-- Section 3: Satuan Turunan -->
          <div class="db-section">
            <div class="db-section-head">
              <div class="db-section-ic"><?= icon('clipboard', 14) ?></div>
              <div>
                <div class="db-section-title">Satuan Turunan <span style="font-weight:400;color:var(--text-dim);">(opsional)</span></div>
                <div class="db-section-sub">Kemasan lain, mis. Box = 10 Rim — tersimpan otomatis bersama data barang</div>
              </div>
            </div>

            <div class="db-unit-list" id="dbUnitList"></div>
            <div id="dbUnitEmpty" class="db-unit-empty">Belum ada satuan turunan.</div>

            <div class="db-unit-add-row">
              <input type="text" name="_unit_nama_tmp" id="dbUnitNamaTmp" placeholder="Nama satuan (mis. Box)">
              <input type="number" min="1" name="_unit_isi_tmp" id="dbUnitIsiTmp" placeholder="Isi (mis. 10)">
              <button type="button" class="btn btn-ghost db-unit-add-btn" id="dbUnitAddBtn">+ Tambah</button>
            </div>
          </div>

        </div>
      </div>

      <div class="db-modal-footer">
        <a href="#" class="btn btn-ghost" id="dbModalHapusBtn" style="display:none; color:#f87171;" onclick="return confirm('Yakin ingin menghapus barang ini? Riwayat transaksi terkait ikut terhapus.');">Hapus Barang</a>
        <div class="db-modal-footer-right">
          <button type="button" class="btn btn-ghost" id="dbModalCancelBtn">Batal</button>
          <button type="submit" class="btn btn-primary" style="padding:10px 24px;font-size:13.5px;">Simpan Data</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('dbModalOverlay');
  var closeBtn = document.getElementById('dbModalClose');
  var cancelBtn = document.getElementById('dbModalCancelBtn');
  var form = document.getElementById('dbModalForm');
  var btnTambah = document.getElementById('btnTambahBarang');
  var modalTitle = document.getElementById('dbModalTitle');
  var modalSubtitle = document.getElementById('dbModalSubtitle');

  var photoPreview = document.getElementById('dbModalPhotoPreview');
  var photoPlaceholder = document.getElementById('dbModalPhotoPlaceholder');
  var hapusFotoWrap = document.getElementById('dbModalHapusFotoWrap');
  var hapusBtn = document.getElementById('dbModalHapusBtn');

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

  // ---------- Satuan turunan: state lokal ----------
  var unitList = document.getElementById('dbUnitList');
  var unitEmpty = document.getElementById('dbUnitEmpty');
  var unitNamaTmp = document.getElementById('dbUnitNamaTmp');
  var unitIsiTmp = document.getElementById('dbUnitIsiTmp');
  var unitAddBtn = document.getElementById('dbUnitAddBtn');
  var hiddenFieldsWrap = document.getElementById('dbHiddenSatuanFields');

  var existingUnits = [];
  var newUnits = [];

  var nextKodeGenerator = '<?= e($nextKode) ?>';

  function renderUnitList() {
    unitList.innerHTML = '';
    var visibleExisting = existingUnits.filter(function (u) { return !u.removed; });
    var total = visibleExisting.length + newUnits.length;
    unitEmpty.style.display = total === 0 ? 'block' : 'none';

    visibleExisting.forEach(function (u) {
      var row = document.createElement('div');
      row.className = 'db-unit-row';
      var badge = document.createElement('span');
      badge.className = 'db-unit-row-badge';
      badge.textContent = u.nama;
      var text = document.createElement('span');
      text.className = 'db-unit-row-text';
      text.textContent = '1 ' + u.nama + ' = ' + u.isi + ' ' + (inputSatuan.value || 'satuan dasar');
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'db-unit-row-remove';
      rm.innerHTML = '✕';
      rm.addEventListener('click', function () { u.removed = true; renderUnitList(); });
      row.appendChild(badge); row.appendChild(text); row.appendChild(rm);
      unitList.appendChild(row);
    });

    newUnits.forEach(function (u, idx) {
      var row = document.createElement('div');
      row.className = 'db-unit-row';
      var badge = document.createElement('span');
      badge.className = 'db-unit-row-badge';
      badge.textContent = u.nama;
      var text = document.createElement('span');
      text.className = 'db-unit-row-text';
      text.textContent = '1 ' + u.nama + ' = ' + u.isi + ' ' + (inputSatuan.value || 'satuan dasar') + ' (baru)';
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'db-unit-row-remove';
      rm.innerHTML = '✕';
      rm.addEventListener('click', function () { newUnits.splice(idx, 1); renderUnitList(); });
      row.appendChild(badge); row.appendChild(text); row.appendChild(rm);
      unitList.appendChild(row);
    });
  }

  unitAddBtn.addEventListener('click', function () {
    var nm = unitNamaTmp.value.trim();
    var isi = parseInt(unitIsiTmp.value, 10);
    if (!nm) { unitNamaTmp.focus(); return; }
    if (!isi || isi < 1) { unitIsiTmp.focus(); return; }

    var dup = existingUnits.some(function (u) { return !u.removed && u.nama.toLowerCase() === nm.toLowerCase(); })
      || newUnits.some(function (u) { return u.nama.toLowerCase() === nm.toLowerCase(); });
    if (dup) { alert('Satuan "' + nm + '" sudah ada di daftar.'); return; }

    newUnits.push({ nama: nm, isi: isi });
    unitNamaTmp.value = '';
    unitIsiTmp.value = '';
    unitNamaTmp.focus();
    renderUnitList();
  });

  unitIsiTmp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); unitAddBtn.click(); } });
  unitNamaTmp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); unitIsiTmp.focus(); } });

  form.addEventListener('submit', function () {
    hiddenFieldsWrap.innerHTML = '';
    existingUnits.filter(function (u) { return u.removed; }).forEach(function (u) {
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'satuan_delete_ids[]'; h.value = u.id;
      hiddenFieldsWrap.appendChild(h);
    });
    newUnits.forEach(function (u) {
      var h1 = document.createElement('input');
      h1.type = 'hidden'; h1.name = 'satuan_new_nama[]'; h1.value = u.nama;
      var h2 = document.createElement('input');
      h2.type = 'hidden'; h2.name = 'satuan_new_isi[]'; h2.value = u.isi;
      hiddenFieldsWrap.appendChild(h1);
      hiddenFieldsWrap.appendChild(h2);
    });
  });

  inputFoto.addEventListener('change', function (e) {
    var file = e.target.files[0];
    if (file) {
      if (file.size > 2 * 1024 * 1024) {
        alert('Gagal: Ukuran foto terlalu besar! Maksimal 2 MB.');
        inputFoto.value = '';
        photoPreview.style.display = 'none';
        photoPreview.src = '';
        photoPlaceholder.style.display = 'flex';
        return;
      }
      var reader = new FileReader();
      reader.onload = function (evt) {
        photoPreview.src = evt.target.result;
        photoPreview.style.display = 'block';
        photoPlaceholder.style.display = 'none';
      };
      reader.readAsDataURL(file);
    }
  });

  function resetUnitState() {
    existingUnits = [];
    newUnits = [];
    unitNamaTmp.value = '';
    unitIsiTmp.value = '';
    renderUnitList();
  }

  function openAddModal() {
    form.reset();
    inputId.value = '';
    modalTitle.textContent = 'Tambah Barang';
    modalSubtitle.textContent = 'Isi informasi barang, harga, stok, foto, dan satuan sekaligus — semua tersimpan dalam satu klik.';

    photoPreview.style.display = 'none';
    photoPreview.src = '';
    photoPlaceholder.style.display = 'flex';
    hapusFotoWrap.style.display = 'none';
    hapusBtn.style.display = 'none';

    inputKode.value = nextKodeGenerator;
    inputTahun.value = new Date().getFullYear();
    inputSatuan.value = 'pcs';

    resetUnitState();

    overlay.classList.add('show');
    overlay.scrollTop = 0;
    document.body.style.overflow = 'hidden';
  }

  function openEditModal(item) {
    form.reset();
    inputId.value = item.id;
    modalTitle.textContent = 'Ubah Barang';
    modalSubtitle.textContent = 'Perbarui data barang, foto, atau satuan turunannya di sini.';

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

    existingUnits = (item.satuanList || []).map(function (s) {
      return { id: s.id, nama: s.nama, isi: s.isi, removed: false };
    });
    newUnits = [];
    unitNamaTmp.value = '';
    unitIsiTmp.value = '';
    renderUnitList();

    hapusBtn.href = 'data_barang.php?action=delete&id=' + item.id;
    hapusBtn.style.display = 'inline-block';

    overlay.classList.add('show');
    overlay.scrollTop = 0;
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