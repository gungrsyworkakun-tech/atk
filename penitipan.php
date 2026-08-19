<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('penitipan');

// Folder penyimpanan foto & berkas titipan
$uploadDir = __DIR__ . '/uploads/penitipan/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
$allowedFotoTypes = ['image/jpeg', 'image/png', 'image/webp'];
$allowedBerkasTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

/** Simpan satu file upload, kembalikan [namaFileAman, null] atau [null, pesanError]. Tidak ada file -> [null, null]. */
function simpan_file_penitipan($dir, $allowedTypes, $maxBytes, $fieldName, $prefix, $labelUkuran) {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($_FILES[$fieldName]['error'] === UPLOAD_ERR_INI_SIZE || $_FILES[$fieldName]['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, "Gagal: Ukuran file terlalu besar. Maksimal $labelUkuran."];
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Gagal mengunggah file. Coba lagi.'];
    }
    if (!in_array($_FILES[$fieldName]['type'], $allowedTypes, true)) {
        return [null, 'Format file tidak didukung.'];
    }
    if ($_FILES[$fieldName]['size'] > $maxBytes) {
        return [null, "Ukuran file maksimal $labelUkuran."];
    }

    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    $safeName = $prefix . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . $safeName)) {
        return [null, 'Gagal menyimpan file di server.'];
    }
    return [$safeName, null];
}

/** Simpan banyak foto sekaligus (input type=file dengan atribut multiple, name="foto[]").
 *  Kembalikan [array_nama_file_aman, null] atau [[], pesanError]. Tidak ada file -> [[], null].
 *  Kalau ada satu file gagal, semua file di batch ini yang sudah kepalang tersimpan akan dihapus lagi (all-or-nothing). */
function simpan_banyak_foto_penitipan($dir, $allowedTypes, $maxBytes, $fieldName, $prefix, $labelUkuran) {
    $saved = [];
    if (empty($_FILES[$fieldName]) || empty($_FILES[$fieldName]['name'])) {
        return [$saved, null];
    }
    $files = $_FILES[$fieldName];
    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;

        if ($files['error'][$i] === UPLOAD_ERR_INI_SIZE || $files['error'][$i] === UPLOAD_ERR_FORM_SIZE) {
            foreach ($saved as $s) @unlink($dir . $s);
            return [[], "Gagal: salah satu foto ukurannya terlalu besar. Maksimal $labelUkuran per foto."];
        }
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            foreach ($saved as $s) @unlink($dir . $s);
            return [[], 'Gagal mengunggah salah satu foto. Coba lagi.'];
        }
        if (!in_array($files['type'][$i], $allowedTypes, true)) {
            foreach ($saved as $s) @unlink($dir . $s);
            return [[], 'Format foto harus JPG, PNG, atau WebP.'];
        }
        if ($files['size'][$i] > $maxBytes) {
            foreach ($saved as $s) @unlink($dir . $s);
            return [[], "Ukuran tiap foto maksimal $labelUkuran."];
        }

        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
        $safeName = $prefix . '-' . time() . '-' . bin2hex(random_bytes(3)) . '-' . $i . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], $dir . $safeName)) {
            foreach ($saved as $s) @unlink($dir . $s);
            return [[], 'Gagal menyimpan salah satu foto di server.'];
        }
        $saved[] = $safeName;
    }

    return [$saved, null];
}

// Cek jika POST terhapus oleh server karena file kelewat besar (post_max_size terlampaui)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash_set('Gagal: Ukuran total file sangat besar sehingga ditolak server. Gunakan file yang lebih kecil.', 'error');
    redirect('penitipan.php');
}

// ---------- Simpan (tambah / ubah) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save') {
    csrf_check();
    $id = $_POST['id'] ?? '';
    $namaPenitip = trim($_POST['nama_penitip'] ?? '');
    $kontak = trim($_POST['kontak'] ?? '');
    $namaBarang = trim($_POST['nama_barang'] ?? '');
    $jumlah = max(1, (int)($_POST['jumlah'] ?? 1));
    $satuan = trim($_POST['satuan'] ?? '') ?: 'pcs';
    $tanggalTitip = trim($_POST['tanggal_titip'] ?? '') ?: date('Y-m-d');
    $keterangan = trim($_POST['keterangan'] ?? '');
    $returnQ = trim($_POST['return_q'] ?? '');

    if ($namaPenitip === '' || $namaBarang === '') {
        flash_set('Nama penitip dan nama barang wajib diisi.', 'error');
        redirect('penitipan.php' . ($returnQ !== '' ? '?q=' . urlencode($returnQ) : ''));
    }

    $berkasLama = null;
    if ($id) {
        $cek = $pdo->prepare('SELECT berkas FROM penitipan WHERE id = ?');
        $cek->execute([$id]);
        $berkasLama = $cek->fetch()['berkas'] ?? null;
    }

    [$fotoBaruList, $fotoError] = simpan_banyak_foto_penitipan($uploadDir, $allowedFotoTypes, 2 * 1024 * 1024, 'foto', 'pn-foto', '2 MB');
    if ($fotoError) {
        flash_set($fotoError, 'error');
        redirect('penitipan.php' . ($returnQ !== '' ? '?q=' . urlencode($returnQ) : ''));
    }
    [$berkasBaru, $berkasError] = simpan_file_penitipan($uploadDir, $allowedBerkasTypes, 5 * 1024 * 1024, 'berkas', 'pn-berkas', '5 MB');
    if ($berkasError) {
        foreach ($fotoBaruList as $f) @unlink($uploadDir . $f);
        flash_set($berkasError, 'error');
        redirect('penitipan.php' . ($returnQ !== '' ? '?q=' . urlencode($returnQ) : ''));
    }

    $hapusBerkasCentang = isset($_POST['hapus_berkas']);
    $berkasFinal = $berkasBaru ?: ($hapusBerkasCentang ? null : $berkasLama);
    $berkasUntukHapus = ($berkasBaru || $hapusBerkasCentang) ? $berkasLama : null;

    if ($id) {
        $stmt = $pdo->prepare('UPDATE penitipan SET nama_penitip=?, kontak=?, nama_barang=?, jumlah=?, satuan=?, tanggal_titip=?, keterangan=?, berkas=? WHERE id=?');
        $stmt->execute([$namaPenitip, $kontak, $namaBarang, $jumlah, $satuan, $tanggalTitip, $keterangan, $berkasFinal, $id]);
        flash_set('Data penitipan berhasil diperbarui.');
    } else {
        $u = current_user();
        $stmt = $pdo->prepare("INSERT INTO penitipan (nama_penitip, kontak, nama_barang, jumlah, satuan, tanggal_titip, keterangan, berkas, status, dicatat_oleh) VALUES (?,?,?,?,?,?,?,?,'dititip',?)");
        $stmt->execute([$namaPenitip, $kontak, $namaBarang, $jumlah, $satuan, $tanggalTitip, $keterangan, $berkasFinal, $u['id']]);
        $id = $pdo->lastInsertId();
        flash_set('Barang titipan berhasil dicatat.');
    }

    if ($berkasUntukHapus && is_file($uploadDir . $berkasUntukHapus)) @unlink($uploadDir . $berkasUntukHapus);

    // Simpan foto-foto baru (kalau ada) sebagai baris terpisah, terkait ke $id
    if ($fotoBaruList) {
        $stmtFoto = $pdo->prepare('INSERT INTO penitipan_foto (penitipan_id, file) VALUES (?, ?)');
        foreach ($fotoBaruList as $f) {
            $stmtFoto->execute([$id, $f]);
        }
    }

    // Hapus foto-foto lama yang dicentang untuk dihapus
    $deleteFotoIds = array_filter(array_map('intval', $_POST['foto_delete_ids'] ?? []));
    if ($deleteFotoIds) {
        $inPlaceholders = implode(',', array_fill(0, count($deleteFotoIds), '?'));
        $stmtSel = $pdo->prepare("SELECT id, file FROM penitipan_foto WHERE penitipan_id = ? AND id IN ($inPlaceholders)");
        $stmtSel->execute(array_merge([$id], $deleteFotoIds));
        $fotoUntukDihapus = $stmtSel->fetchAll();

        $stmtDel = $pdo->prepare("DELETE FROM penitipan_foto WHERE penitipan_id = ? AND id IN ($inPlaceholders)");
        $stmtDel->execute(array_merge([$id], $deleteFotoIds));

        foreach ($fotoUntukDihapus as $fd) {
            if (is_file($uploadDir . $fd['file'])) @unlink($uploadDir . $fd['file']);
        }
    }

    redirect('penitipan.php' . ($returnQ !== '' ? '?q=' . urlencode($returnQ) : ''));
}

// ---------- Tandai sudah diambil ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ambil') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $tanggalDiambil = trim($_POST['tanggal_diambil'] ?? '') ?: date('Y-m-d');
    $diambilOleh = trim($_POST['diambil_oleh'] ?? '');

    [$fotoAmbilBaru, $fotoAmbilError] = simpan_file_penitipan($uploadDir, $allowedFotoTypes, 2 * 1024 * 1024, 'foto_pengambilan', 'pn-ambil', '2 MB');
    if ($fotoAmbilError) {
        flash_set($fotoAmbilError, 'error');
        redirect('penitipan.php');
    }

    if ($fotoAmbilBaru) {
        $stmt = $pdo->prepare("UPDATE penitipan SET status='diambil', tanggal_diambil=?, diambil_oleh=?, foto_pengambilan=? WHERE id=? AND status='dititip'");
        $stmt->execute([$tanggalDiambil, $diambilOleh, $fotoAmbilBaru, $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE penitipan SET status='diambil', tanggal_diambil=?, diambil_oleh=? WHERE id=? AND status='dititip'");
        $stmt->execute([$tanggalDiambil, $diambilOleh, $id]);
    }
    flash_set('Barang titipan ditandai sudah diambil.');
    redirect('penitipan.php');
}

// ---------- Batalkan status "sudah diambil" (kalau salah klik) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'batal_ambil') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $cek = $pdo->prepare('SELECT foto_pengambilan FROM penitipan WHERE id = ?');
    $cek->execute([$id]);
    $fotoAmbilLama = $cek->fetch()['foto_pengambilan'] ?? null;

    $stmt = $pdo->prepare("UPDATE penitipan SET status='dititip', tanggal_diambil=NULL, diambil_oleh=NULL, foto_pengambilan=NULL WHERE id=?");
    $stmt->execute([$id]);
    if ($fotoAmbilLama && is_file($uploadDir . $fotoAmbilLama)) @unlink($uploadDir . $fotoAmbilLama);
    flash_set('Status dikembalikan ke "Masih Dititip".');
    redirect('penitipan.php');
}

// ---------- Hapus ----------
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $delId = (int)$_GET['id'];
    $cek = $pdo->prepare('SELECT berkas, foto_pengambilan FROM penitipan WHERE id = ?');
    $cek->execute([$delId]);
    $dataHapus = $cek->fetch();

    $stmtFotoLama = $pdo->prepare('SELECT file FROM penitipan_foto WHERE penitipan_id = ?');
    $stmtFotoLama->execute([$delId]);
    $fotoLamaList = $stmtFotoLama->fetchAll();

    $pdo->prepare('DELETE FROM penitipan WHERE id = ?')->execute([$delId]); // penitipan_foto ikut terhapus via ON DELETE CASCADE

    if ($dataHapus && !empty($dataHapus['berkas']) && is_file($uploadDir . $dataHapus['berkas'])) {
        @unlink($uploadDir . $dataHapus['berkas']);
    }
    if ($dataHapus && !empty($dataHapus['foto_pengambilan']) && is_file($uploadDir . $dataHapus['foto_pengambilan'])) {
        @unlink($uploadDir . $dataHapus['foto_pengambilan']);
    }
    foreach ($fotoLamaList as $fl) {
        if (is_file($uploadDir . $fl['file'])) @unlink($uploadDir . $fl['file']);
    }

    flash_set('Data penitipan dihapus.');
    redirect('penitipan.php');
}

// ---------- Filter & daftar ----------
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = 'SELECT * FROM penitipan WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (nama_penitip LIKE ? OR nama_barang LIKE ? OR kontak LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($statusFilter === 'dititip' || $statusFilter === 'diambil') {
    $sql .= ' AND status = ?';
    $params[] = $statusFilter;
}
$sql .= " ORDER BY (status = 'dititip') DESC, tanggal_titip DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalAll = (int)$pdo->query('SELECT COUNT(*) c FROM penitipan')->fetch()['c'];
$totalDititip = (int)$pdo->query("SELECT COUNT(*) c FROM penitipan WHERE status='dititip'")->fetch()['c'];
$totalDiambil = $totalAll - $totalDititip;

// Ambil semua foto untuk baris yang tampil, dikelompokkan per penitipan_id
$fotoByPenitipan = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtFotoAll = $pdo->prepare("SELECT id, penitipan_id, file FROM penitipan_foto WHERE penitipan_id IN ($inPlaceholders) ORDER BY id");
    $stmtFotoAll->execute($ids);
    foreach ($stmtFotoAll->fetchAll() as $fr) {
        $fotoByPenitipan[$fr['penitipan_id']][] = $fr;
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Penitipan ATK</h1><div class="sub">Catatan barang pribadi pegawai/pihak luar yang dititipkan sementara ke gudang</div></div>
</div>

<style>
  .pn-summary-card{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;
    background:linear-gradient(135deg,var(--c-amber-bg),var(--c-blue-bg));border:1px solid var(--line);
    border-radius:var(--radius-lg);padding:16px 20px;margin:18px 0;}
  .pn-summary-item{display:flex;flex-direction:column;}
  .pn-summary-label{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.05em;}
  .pn-summary-value{font-family:'Space Grotesk',sans-serif;font-size:22px;font-weight:700;margin-top:3px;}

  .pn-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
  .pn-search{flex:1;min-width:220px;position:relative;}
  .pn-search input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
    background:var(--paper-sunk);color:var(--text);font-size:13.5px;font-family:'Inter',Arial,sans-serif;box-sizing:border-box;}
  .pn-search input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .pn-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.55;pointer-events:none;}
  .pn-status-select{padding:9px 12px;border:1.5px solid var(--line);border-radius:var(--radius-sm);
    background:var(--paper-sunk);color:var(--text);font-size:13px;font-family:'Inter',Arial,sans-serif;}

  .pn-table-wrap{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
    overflow:hidden;box-shadow:var(--shadow-card);}
  .pn-table{width:100%;border-collapse:collapse;font-size:13px;}
  .pn-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-dim);
    padding:12px 14px;border-bottom:1px solid var(--line);background:var(--paper-sunk);white-space:nowrap;}
  .pn-table td{padding:12px 14px;border-bottom:1px solid var(--line-soft);vertical-align:top;}
  .pn-table tr:last-child td{border-bottom:none;}
  .pn-table tr:hover td{background:var(--paper-sunk);}
  .pn-nama{font-weight:600;}
  .pn-sub{font-size:11.5px;color:var(--text-dim);margin-top:2px;}
  .pn-actions{display:flex;gap:6px;flex-wrap:wrap;}
  .pn-actions .btn{font-size:11.5px;padding:6px 10px;}

  .pn-doc-cell{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .pn-thumb{width:36px;height:36px;border-radius:6px;object-fit:cover;border:1px solid var(--line);cursor:pointer;flex-shrink:0;}
  .pn-doc-btn{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--line);background:var(--paper-sunk);
    color:var(--text-dim);border-radius:6px;padding:5px 9px;font-size:11px;cursor:pointer;font-family:'Inter',Arial,sans-serif;}
  .pn-doc-btn:hover{border-color:var(--accent);color:var(--accent);}

  .pn-modal-overlay{position:fixed;inset:0;background:rgba(6,9,16,.75);z-index:200;
    display:none;align-items:flex-start;justify-content:center;padding:32px 20px;backdrop-filter:blur(3px);overflow-y:auto;}
  .pn-modal-overlay.show{display:flex;}
  .pn-modal{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius-lg);
    width:min(560px,100%);box-shadow:var(--shadow-pop);position:relative;margin-bottom:32px;}
  .pn-modal-close{position:absolute;top:16px;right:16px;width:34px;height:34px;border-radius:50%;
    background:var(--paper-sunk);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;
    cursor:pointer;color:var(--text-dim);z-index:10;}
  .pn-modal-close:hover{background:var(--accent);color:#fff;}
  .pn-modal-header{padding:22px 28px 18px;border-bottom:1px solid var(--line-soft);}
  .pn-modal-header h3{margin:0;font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:700;}
  .pn-modal-header p{margin:4px 0 0;font-size:12.5px;color:var(--text-dim);}
  .pn-modal-body{padding:22px 28px 8px;display:flex;flex-direction:column;gap:14px;}
  .pn-field{display:flex;flex-direction:column;gap:6px;}
  .pn-field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  @media (max-width:480px){ .pn-field-row{grid-template-columns:1fr;} }
  .pn-label{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;font-weight:700;}
  .pn-input{width:100%;padding:9px 11px;border:1.5px solid var(--line);border-radius:8px;
    background:var(--paper-sunk);color:var(--text);font-size:13px;box-sizing:border-box;font-family:'Inter',Arial,sans-serif;}
  .pn-input:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .pn-modal-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;
    padding:16px 28px 22px;flex-wrap:wrap;}
  .pn-modal-footer-right{display:flex;gap:10px;}

  .pn-upload-box{width:100%;aspect-ratio:16/9;background:var(--paper-sunk);border:1.5px dashed var(--line);
    border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:8px;}
  .pn-upload-box img{width:100%;height:100%;object-fit:cover;}
  .pn-upload-box-file{aspect-ratio:auto;min-height:90px;padding:8px;}
  .pn-upload-placeholder{display:flex;flex-direction:column;align-items:center;gap:4px;color:var(--text-faint);font-size:11px;text-align:center;}
  .pn-hapus-check{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--text-dim);cursor:pointer;font-weight:500;margin-top:6px;}
  .pn-upload-hint{font-size:10.5px;color:var(--text-faint);margin-top:5px;}

  .pn-foto-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;}
  .pn-foto-item{position:relative;width:72px;height:72px;border-radius:8px;overflow:hidden;
    border:1.5px solid var(--line);flex-shrink:0;}
  .pn-foto-item img{width:100%;height:100%;object-fit:cover;display:block;}
  .pn-foto-item.pn-foto-pending{border-color:var(--accent);}
  .pn-foto-remove{position:absolute;top:2px;right:2px;width:20px;height:20px;border-radius:50%;
    background:rgba(10,12,18,.78);color:#fff;border:none;display:flex;align-items:center;justify-content:center;
    font-size:13px;line-height:1;cursor:pointer;padding:0;}
  .pn-foto-empty-hint{font-size:11.5px;color:var(--text-faint);padding:8px 0;}

  /* ============================================================ */
  /* MOBILE (<=760px): tabel jadi kartu, toolbar & modal ditata ulang */
  /* ============================================================ */
  @media (max-width:760px) {
    .topline h1{font-size:19px;}
    .topline .sub{font-size:12px;}

    .pn-summary-card{padding:14px 16px;gap:10px 18px;}
    .pn-summary-value{font-size:19px;}

    .pn-toolbar{flex-direction:column;align-items:stretch;gap:8px;}
    .pn-toolbar form{width:100%;}
    .pn-status-select{width:100%;}
    #btnTambahPenitipan{margin-left:0!important;width:100%;justify-content:center;order:-1;}

    /* Tabel -> tumpukan kartu */
    .pn-table-wrap{background:transparent;border:none;box-shadow:none;overflow:visible;}
    .pn-table thead{display:none;}
    .pn-table, .pn-table tbody, .pn-table tr, .pn-table td{display:block;width:100%;}
    .pn-table{border-spacing:0 12px;}
    .pn-table tr{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
      box-shadow:var(--shadow-card);margin-bottom:12px;overflow:hidden;}
    .pn-table tr:last-child{margin-bottom:0;}
    .pn-table td{border-bottom:1px solid var(--line-soft);padding:11px 14px;}
    .pn-table tr:hover td{background:none;}
    .pn-table td:last-child{border-bottom:none;}
    .pn-table td:before{content:attr(data-label);display:block;font-size:10px;text-transform:uppercase;
      letter-spacing:.05em;color:var(--text-dim);font-weight:700;margin-bottom:4px;}
    .pn-table td[data-label="Penitip"]{background:var(--paper-sunk);}
    .pn-table td[data-label="Aksi"]{padding-top:12px;}
    .pn-actions{flex-direction:column;align-items:stretch;}
    .pn-actions .btn{width:100%;justify-content:center;min-height:38px;font-size:12.5px;}
    .pn-thumb{width:44px;height:44px;}

    /* Modal jadi bottom-sheet supaya gampang dijangkau jempol */
    .pn-modal-overlay{padding:0;align-items:flex-end;}
    .pn-modal{width:100%;max-width:100%;border-radius:18px 18px 0 0;max-height:94vh;
      overflow-y:auto;margin-bottom:0;-webkit-overflow-scrolling:touch;}
    .pn-modal-header{padding:18px 18px 14px;position:sticky;top:0;background:var(--paper-card);z-index:2;}
    .pn-modal-body{padding:16px 18px 6px;}
    .pn-modal-footer{padding:14px 18px 18px;position:sticky;bottom:0;background:var(--paper-card);
      box-shadow:0 -6px 16px rgba(0,0,0,.15);}
    .pn-modal-footer-right{width:100%;}
    .pn-modal-footer-right .btn{flex:1;min-height:42px;}
    .pn-modal-close{top:12px;right:12px;width:36px;height:36px;}
    .pn-input, select.pn-input{font-size:16px;} /* cegah auto-zoom iOS saat fokus input */
    .pn-foto-item{width:64px;height:64px;}
  }
  .pn-modal-draghandle{display:none;}
  @media (max-width:760px){
    .pn-modal-draghandle{display:block;width:36px;height:4px;border-radius:99px;background:var(--line);
      margin:10px auto 0;}
  }
</style>

<div class="pn-summary-card">
  <div class="pn-summary-item">
    <div class="pn-summary-label">Total Titipan</div>
    <div class="pn-summary-value"><?= $totalAll ?></div>
  </div>
  <div class="pn-summary-item">
    <div class="pn-summary-label">Masih Dititip</div>
    <div class="pn-summary-value"><?= $totalDititip ?></div>
  </div>
  <div class="pn-summary-item">
    <div class="pn-summary-label">Sudah Diambil</div>
    <div class="pn-summary-value"><?= $totalDiambil ?></div>
  </div>
</div>

<div class="pn-toolbar">
  <form method="get" class="pn-search" style="margin:0;">
    <?= icon('search', 15) ?>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari nama penitip, barang, atau kontak...">
    <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
  </form>
  <form method="get" style="margin:0;">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
    <select name="status" class="pn-status-select" onchange="this.form.submit()">
      <option value="">Semua status</option>
      <option value="dititip" <?= $statusFilter === 'dititip' ? 'selected' : '' ?>>Masih Dititip</option>
      <option value="diambil" <?= $statusFilter === 'diambil' ? 'selected' : '' ?>>Sudah Diambil</option>
    </select>
  </form>
  <?php if ($q !== '' || $statusFilter !== ''): ?><a class="btn btn-ghost" href="penitipan.php" style="font-size:12.5px;padding:7px 12px;">Reset</a><?php endif; ?>
  <button type="button" class="btn btn-primary" id="btnTambahPenitipan" style="margin-left:auto;">
    <?= icon('plus', 15) ?> Catat Titipan Baru
  </button>
</div>

<?php if ($rows): ?>
<div class="pn-table-wrap">
  <table class="pn-table">
    <thead>
      <tr>
        <th>Penitip</th>
        <th>Barang</th>
        <th>Keterangan</th>
        <th>Dokumentasi</th>
        <th>Tgl Titip</th>
        <th>Status</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $fotoList = $fotoByPenitipan[$r['id']] ?? [];
        $itemJson = json_encode([
            'id' => $r['id'], 'namaPenitip' => $r['nama_penitip'], 'kontak' => $r['kontak'],
            'namaBarang' => $r['nama_barang'], 'jumlah' => (int)$r['jumlah'], 'satuan' => $r['satuan'],
            'tanggalTitip' => $r['tanggal_titip'], 'keterangan' => $r['keterangan'],
            'hasBerkas' => !empty($r['berkas']),
            'fotos' => array_map(fn($f) => ['id' => (int)$f['id']], $fotoList),
        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
      ?>
      <tr>
        <td data-label="Penitip">
          <div class="pn-nama"><?= e($r['nama_penitip']) ?></div>
          <?php if (!empty($r['kontak'])): ?><div class="pn-sub"><?= e($r['kontak']) ?></div><?php endif; ?>
        </td>
        <td data-label="Barang">
          <div class="pn-nama"><?= e($r['nama_barang']) ?></div>
          <div class="pn-sub"><?= (int)$r['jumlah'] ?> <?= e($r['satuan']) ?></div>
        </td>
        <td data-label="Keterangan" style="max-width:220px;word-break:break-word;">
          <?= !empty($r['keterangan']) ? e($r['keterangan']) : '<span class="pn-sub">—</span>' ?>
        </td>
        <td data-label="Dokumentasi">
          <div class="pn-doc-cell">
            <?php foreach (array_slice($fotoList, 0, 3) as $f): ?>
              <img class="pn-thumb js-view-doc" src="serve_penitipan_file.php?type=foto&foto_id=<?= (int)$f['id'] ?>"
                   alt="Foto <?= e($r['nama_barang']) ?>"
                   data-url="serve_penitipan_file.php?type=foto&foto_id=<?= (int)$f['id'] ?>"
                   data-download="serve_penitipan_file.php?type=foto&foto_id=<?= (int)$f['id'] ?>&download=1"
                   data-filename="foto-<?= e($r['nama_barang']) ?>.jpg" data-image="1" title="Lihat foto">
            <?php endforeach; ?>
            <?php if (count($fotoList) > 3): ?><span class="pn-sub">+<?= count($fotoList) - 3 ?> lagi</span><?php endif; ?>
            <?php if (!empty($r['berkas'])): ?>
              <button type="button" class="pn-doc-btn js-view-doc"
                      data-url="serve_penitipan_file.php?type=berkas&id=<?= (int)$r['id'] ?>"
                      data-download="serve_penitipan_file.php?type=berkas&id=<?= (int)$r['id'] ?>&download=1"
                      data-filename="berkas-<?= e($r['nama_barang']) ?>"
                      data-image="<?= str_ends_with(strtolower($r['berkas']), '.pdf') ? '0' : '1' ?>" title="Lihat berkas">
                <?= icon('file', 14) ?> Berkas
              </button>
            <?php endif; ?>
            <?php if (!$fotoList && empty($r['berkas'])): ?><span class="pn-sub">—</span><?php endif; ?>
          </div>
        </td>
        <td data-label="Tgl Titip"><?= e(date('d M Y', strtotime($r['tanggal_titip']))) ?></td>
        <td data-label="Status">
          <span class="status-badge <?= penitipan_status_class($r['status']) ?>"><?= e(penitipan_status_label($r['status'])) ?></span>
          <?php if ($r['status'] === 'diambil' && !empty($r['tanggal_diambil'])): ?>
            <div class="pn-sub">Diambil <?= e(date('d M Y', strtotime($r['tanggal_diambil']))) ?><?= !empty($r['diambil_oleh']) ? ' oleh ' . e($r['diambil_oleh']) : '' ?></div>
            <?php if (!empty($r['foto_pengambilan'])): ?>
              <img class="pn-thumb js-view-doc" style="margin-top:5px;"
                   src="serve_penitipan_file.php?type=foto_pengambilan&id=<?= (int)$r['id'] ?>"
                   alt="Foto saat diambil"
                   data-url="serve_penitipan_file.php?type=foto_pengambilan&id=<?= (int)$r['id'] ?>"
                   data-download="serve_penitipan_file.php?type=foto_pengambilan&id=<?= (int)$r['id'] ?>&download=1"
                   data-filename="foto-diambil-<?= e($r['nama_barang']) ?>.jpg" data-image="1" title="Lihat foto saat diambil">
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td data-label="Aksi">
          <div class="pn-actions">
            <button type="button" class="btn btn-ghost js-edit" data-item='<?= $itemJson ?>'>Ubah</button>
            <?php if ($r['status'] === 'dititip'): ?>
              <button type="button" class="btn btn-primary js-ambil" data-id="<?= (int)$r['id'] ?>" data-nama="<?= e($r['nama_penitip']) ?>">Tandai Diambil</button>
            <?php else: ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Kembalikan status ke \'Masih Dititip\'?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="do" value="batal_ambil">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-ghost">Batalkan</button>
              </form>
            <?php endif; ?>
            <a class="btn btn-ghost" style="color:#f87171;" href="penitipan.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Hapus catatan titipan ini?');">Hapus</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
  <div class="empty"><b>Belum ada data</b><?= ($q !== '' || $statusFilter !== '') ? 'Tidak ada titipan yang cocok dengan pencarian/filter ini.' : 'Belum ada barang yang dititipkan. Klik "Catat Titipan Baru" untuk menambahkan.' ?></div>
<?php endif; ?>

<!-- ========================================== -->
<!-- MODAL: TAMBAH / UBAH TITIPAN               -->
<!-- ========================================== -->
<div class="pn-modal-overlay" id="pnModalOverlay">
  <div class="pn-modal">
    <div class="pn-modal-draghandle"></div>
    <div class="pn-modal-close" id="pnModalClose">✕</div>
    <div class="pn-modal-header">
      <h3 id="pnModalTitle">Catat Titipan Baru</h3>
      <p id="pnModalSubtitle">Barang pribadi pegawai/pihak luar yang dititipkan sementara ke gudang.</p>
    </div>
    <form method="post" id="pnModalForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" id="pnInputId">
      <input type="hidden" name="return_q" value="<?= e($q) ?>">
      <div class="pn-modal-body">
        <div class="pn-field-row">
          <div class="pn-field">
            <label class="pn-label">Nama Penitip *</label>
            <input class="pn-input" type="text" name="nama_penitip" id="pnNamaPenitip" required placeholder="cth. Budi (TIKKIM)">
          </div>
          <div class="pn-field">
            <label class="pn-label">Kontak</label>
            <input class="pn-input" type="text" name="kontak" id="pnKontak" placeholder="No. HP / unit kerja">
          </div>
        </div>
        <div class="pn-field">
          <label class="pn-label">Nama Barang *</label>
          <input class="pn-input" type="text" name="nama_barang" id="pnNamaBarang" required placeholder="cth. Kardus dokumen pribadi">
        </div>
        <div class="pn-field-row">
          <div class="pn-field">
            <label class="pn-label">Jumlah</label>
            <input class="pn-input" type="number" min="1" name="jumlah" id="pnJumlah" value="1">
          </div>
          <div class="pn-field">
            <label class="pn-label">Satuan</label>
            <input class="pn-input" type="text" name="satuan" id="pnSatuan" value="pcs">
          </div>
        </div>
        <div class="pn-field">
          <label class="pn-label">Tanggal Titip</label>
          <input class="pn-input" type="date" name="tanggal_titip" id="pnTanggalTitip">
        </div>
        <div class="pn-field">
          <label class="pn-label">Keterangan</label>
          <input class="pn-input" type="text" name="keterangan" id="pnKeterangan" placeholder="Catatan tambahan, opsional">
        </div>

        <div class="pn-field">
          <label class="pn-label">Foto Dokumentasi</label>
          <div class="pn-foto-grid" id="pnFotoGrid"></div>
          <label class="btn btn-ghost" for="pnFotoInput" style="cursor:pointer;font-size:11.5px;padding:7px 10px;display:inline-block;width:fit-content;">+ Tambah Foto</label>
          <input type="file" name="foto[]" id="pnFotoInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
          <div class="pn-upload-hint">JPG/PNG/WebP, maks 2 MB per foto — bisa pilih beberapa sekaligus</div>
          <div id="pnFotoDeleteContainer"></div>
        </div>
        <div class="pn-field">
          <label class="pn-label">Berkas Pendukung</label>
          <div class="pn-upload-box pn-upload-box-file" id="pnBerkasBox">
            <div id="pnBerkasEmpty" class="pn-upload-placeholder"><?= icon('file', 26) ?><span>Belum ada berkas</span></div>
            <a id="pnBerkasCurrent" href="#" target="_blank" class="pn-doc-btn" style="display:none;"><?= icon('file', 14) ?> Lihat berkas saat ini</a>
          </div>
          <label class="btn btn-ghost" for="pnBerkasInput" style="cursor:pointer;font-size:11.5px;padding:7px 10px;display:inline-block;width:fit-content;">Pilih Berkas</label>
          <input type="file" name="berkas" id="pnBerkasInput" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none;">
          <div class="pn-upload-hint">PDF/JPG/PNG, maks 5 MB</div>
          <label class="pn-hapus-check" id="pnHapusBerkasWrap" style="display:none;">
            <input type="checkbox" name="hapus_berkas" value="1"> Hapus berkas ini
          </label>
        </div>
      </div>
      <div class="pn-modal-footer">
        <span></span>
        <div class="pn-modal-footer-right">
          <button type="button" class="btn btn-ghost" id="pnCancelBtn">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ========================================== -->
<!-- MODAL: TANDAI SUDAH DIAMBIL                -->
<!-- ========================================== -->
<div class="pn-modal-overlay" id="pnAmbilOverlay">
  <div class="pn-modal" style="width:min(420px,100%);">
    <div class="pn-modal-draghandle"></div>
    <div class="pn-modal-close" id="pnAmbilClose">✕</div>
    <div class="pn-modal-header">
      <h3>Tandai Sudah Diambil</h3>
      <p id="pnAmbilSubtitle">Konfirmasi pengambilan barang titipan.</p>
    </div>
    <form method="post" id="pnAmbilForm" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="ambil">
      <input type="hidden" name="id" id="pnAmbilId">
      <div class="pn-modal-body">
        <div class="pn-field">
          <label class="pn-label">Tanggal Diambil</label>
          <input class="pn-input" type="date" name="tanggal_diambil" id="pnTanggalDiambil">
        </div>
        <div class="pn-field">
          <label class="pn-label">Diambil Oleh</label>
          <input class="pn-input" type="text" name="diambil_oleh" id="pnDiambilOleh" placeholder="Kosongkan jika diambil sendiri oleh penitip">
        </div>
        <div class="pn-field">
          <label class="pn-label">Foto Saat Diambil <span style="text-transform:none;font-weight:400;">(opsional)</span></label>
          <div class="pn-upload-box" id="pnAmbilFotoBox">
            <img id="pnAmbilFotoPreview" src="" alt="Preview foto pengambilan" style="display:none;">
            <div id="pnAmbilFotoPlaceholder" class="pn-upload-placeholder"><?= icon('box', 26) ?><span>Tidak wajib diisi</span></div>
          </div>
          <label class="btn btn-ghost" for="pnAmbilFotoInput" style="cursor:pointer;font-size:11.5px;padding:7px 10px;display:inline-block;width:fit-content;">Pilih Foto</label>
          <input type="file" name="foto_pengambilan" id="pnAmbilFotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
          <div class="pn-upload-hint">JPG/PNG/WebP, maks 2 MB</div>
        </div>
      </div>
      <div class="pn-modal-footer">
        <span></span>
        <div class="pn-modal-footer-right">
          <button type="button" class="btn btn-ghost" id="pnAmbilCancelBtn">Batal</button>
          <button type="submit" class="btn btn-primary">Konfirmasi</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('pnModalOverlay');
  var closeBtn = document.getElementById('pnModalClose');
  var cancelBtn = document.getElementById('pnCancelBtn');
  var form = document.getElementById('pnModalForm');
  var title = document.getElementById('pnModalTitle');
  var sub = document.getElementById('pnModalSubtitle');
  var inputId = document.getElementById('pnInputId');
  var btnTambah = document.getElementById('btnTambahPenitipan');

  var fotoInput = document.getElementById('pnFotoInput');
  var fotoGrid = document.getElementById('pnFotoGrid');
  var fotoDeleteContainer = document.getElementById('pnFotoDeleteContainer');
  var existingFotos = [];  // [{id,url}] foto yang sudah tersimpan (mode ubah)
  var pendingFiles = [];   // File[] foto baru yang baru dipilih, belum disimpan
  var deleteIds = [];      // id foto lama yang ditandai untuk dihapus

  var berkasInput = document.getElementById('pnBerkasInput');
  var berkasEmpty = document.getElementById('pnBerkasEmpty');
  var berkasCurrent = document.getElementById('pnBerkasCurrent');
  var hapusBerkasWrap = document.getElementById('pnHapusBerkasWrap');
  var hapusBerkasCheck = hapusBerkasWrap.querySelector('input');

  function syncFotoInputFiles() {
    var dt = new DataTransfer();
    pendingFiles.forEach(function (f) { dt.items.add(f); });
    fotoInput.files = dt.files;
  }

  function renderFotoGrid() {
    fotoGrid.innerHTML = '';

    existingFotos.forEach(function (f) {
      var item = document.createElement('div');
      item.className = 'pn-foto-item';
      item.innerHTML = '<img src="' + f.url + '" alt="Foto">' +
        '<button type="button" class="pn-foto-remove" title="Hapus foto ini">✕</button>';
      item.querySelector('.pn-foto-remove').addEventListener('click', function () {
        deleteIds.push(f.id);
        existingFotos = existingFotos.filter(function (x) { return x.id !== f.id; });
        renderFotoGrid();
      });
      fotoGrid.appendChild(item);
    });

    pendingFiles.forEach(function (file, idx) {
      var url = URL.createObjectURL(file);
      var item = document.createElement('div');
      item.className = 'pn-foto-item pn-foto-pending';
      item.innerHTML = '<img src="' + url + '" alt="Foto baru">' +
        '<button type="button" class="pn-foto-remove" title="Batalkan foto ini">✕</button>';
      item.querySelector('.pn-foto-remove').addEventListener('click', function () {
        pendingFiles.splice(idx, 1);
        syncFotoInputFiles();
        renderFotoGrid();
      });
      fotoGrid.appendChild(item);
    });

    if (!existingFotos.length && !pendingFiles.length) {
      fotoGrid.innerHTML = '<div class="pn-foto-empty-hint">Belum ada foto. Klik "+ Tambah Foto" untuk menambahkan.</div>';
    }

    fotoDeleteContainer.innerHTML = '';
    deleteIds.forEach(function (id) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'foto_delete_ids[]';
      inp.value = id;
      fotoDeleteContainer.appendChild(inp);
    });
  }

  function resetUploadUI() {
    fotoInput.value = '';
    existingFotos = [];
    pendingFiles = [];
    deleteIds = [];
    renderFotoGrid();

    berkasInput.value = '';
    berkasEmpty.style.display = 'flex';
    berkasEmpty.querySelector('span').textContent = 'Belum ada berkas';
    berkasCurrent.style.display = 'none';
    hapusBerkasWrap.style.display = 'none';
    hapusBerkasCheck.checked = false;
  }

  function openAdd() {
    form.reset();
    inputId.value = '';
    title.textContent = 'Catat Titipan Baru';
    sub.textContent = 'Barang pribadi pegawai/pihak luar yang dititipkan sementara ke gudang.';
    document.getElementById('pnTanggalTitip').value = new Date().toISOString().slice(0, 10);
    document.getElementById('pnJumlah').value = 1;
    document.getElementById('pnSatuan').value = 'pcs';
    resetUploadUI();
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function openEdit(item) {
    form.reset();
    inputId.value = item.id;
    title.textContent = 'Ubah Data Titipan';
    sub.textContent = 'Perbarui data barang titipan.';
    document.getElementById('pnNamaPenitip').value = item.namaPenitip || '';
    document.getElementById('pnKontak').value = item.kontak || '';
    document.getElementById('pnNamaBarang').value = item.namaBarang || '';
    document.getElementById('pnJumlah').value = item.jumlah || 1;
    document.getElementById('pnSatuan').value = item.satuan || 'pcs';
    document.getElementById('pnTanggalTitip').value = item.tanggalTitip || '';
    document.getElementById('pnKeterangan').value = item.keterangan || '';

    resetUploadUI();
    existingFotos = (item.fotos || []).map(function (f) {
      return { id: f.id, url: 'serve_penitipan_file.php?type=foto&foto_id=' + f.id };
    });
    renderFotoGrid();
    if (item.hasBerkas) {
      berkasCurrent.href = 'serve_penitipan_file.php?type=berkas&id=' + item.id;
      berkasEmpty.style.display = 'none';
      berkasCurrent.style.display = 'inline-flex';
      hapusBerkasWrap.style.display = 'flex';
    }

    overlay.classList.add('show');
    overlay.scrollTop = 0;
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  fotoInput.addEventListener('change', function (e) {
    var newFiles = Array.from(e.target.files || []);
    var tooBig = [];
    newFiles.forEach(function (file) {
      if (file.size > 2 * 1024 * 1024) { tooBig.push(file.name); return; }
      pendingFiles.push(file);
    });
    if (tooBig.length) alert('Foto berikut dilewati karena lebih dari 2 MB: ' + tooBig.join(', '));
    syncFotoInputFiles();
    renderFotoGrid();
  });

  berkasInput.addEventListener('change', function (e) {
    var file = e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
      alert('Ukuran berkas maksimal 5 MB.');
      berkasInput.value = '';
      return;
    }
    berkasEmpty.querySelector('span').textContent = file.name + ' (baru dipilih)';
    hapusBerkasWrap.style.display = 'none';
  });

  if (btnTambah) btnTambah.addEventListener('click', openAdd);
  document.querySelectorAll('.js-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
      try { openEdit(JSON.parse(btn.dataset.item)); } catch (e) { console.error(e); }
    });
  });
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('show')) closeModal(); });

  // ---------- Modal tandai diambil ----------
  var ambilOverlay = document.getElementById('pnAmbilOverlay');
  var ambilClose = document.getElementById('pnAmbilClose');
  var ambilCancel = document.getElementById('pnAmbilCancelBtn');
  var ambilSub = document.getElementById('pnAmbilSubtitle');
  var ambilId = document.getElementById('pnAmbilId');
  var ambilFotoInput = document.getElementById('pnAmbilFotoInput');
  var ambilFotoPreview = document.getElementById('pnAmbilFotoPreview');
  var ambilFotoPlaceholder = document.getElementById('pnAmbilFotoPlaceholder');

  function openAmbil(id, nama) {
    ambilId.value = id;
    ambilSub.textContent = 'Konfirmasi pengambilan titipan milik ' + nama + '.';
    document.getElementById('pnTanggalDiambil').value = new Date().toISOString().slice(0, 10);
    document.getElementById('pnDiambilOleh').value = '';
    ambilFotoInput.value = '';
    ambilFotoPreview.style.display = 'none';
    ambilFotoPreview.src = '';
    ambilFotoPlaceholder.style.display = 'flex';
    ambilOverlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeAmbil() {
    ambilOverlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  ambilFotoInput.addEventListener('change', function (e) {
    var file = e.target.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      alert('Ukuran foto maksimal 2 MB.');
      ambilFotoInput.value = '';
      return;
    }
    var reader = new FileReader();
    reader.onload = function (evt) {
      ambilFotoPreview.src = evt.target.result;
      ambilFotoPreview.style.display = 'block';
      ambilFotoPlaceholder.style.display = 'none';
    };
    reader.readAsDataURL(file);
  });

  document.querySelectorAll('.js-ambil').forEach(function (btn) {
    btn.addEventListener('click', function () { openAmbil(btn.dataset.id, btn.dataset.nama); });
  });
  ambilClose.addEventListener('click', closeAmbil);
  ambilCancel.addEventListener('click', closeAmbil);
  ambilOverlay.addEventListener('click', function (e) { if (e.target === ambilOverlay) closeAmbil(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ambilOverlay.classList.contains('show')) closeAmbil(); });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>