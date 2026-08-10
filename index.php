<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

// Redirect khusus untuk role bidang (dari kode lama)
if (is_bidang()) { redirect('permintaan.php'); }

// ---------------- Filter tahun & rentang tanggal (Fitur Baru) ----------------
function valid_date($s) {
    return $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
}

$availYears = $pdo->query('SELECT DISTINCT YEAR(tanggal) y FROM transactions ORDER BY y DESC')->fetchAll(PDO::FETCH_COLUMN);
$currentYear = (int)date('Y');
if (!$availYears) $availYears = [$currentYear];

$rawDari = trim($_GET['dari'] ?? '');
$rawSampai = trim($_GET['sampai'] ?? '');
$filterDari = valid_date($rawDari) ? $rawDari : '';
$filterSampai = valid_date($rawSampai) ? $rawSampai : '';
$useRange = $filterDari !== '' || $filterSampai !== '';

$filterTahun = $_GET['tahun'] ?? (string)$currentYear;
if ($filterTahun !== 'semua' && !ctype_digit((string)$filterTahun)) $filterTahun = (string)$currentYear;

// Bangun kondisi WHERE dinamis berdasarkan filter yang aktif.
$filterCond = [];
$filterParams = [];
if ($useRange) {
    if ($filterDari !== '') { $filterCond[] = 't.tanggal >= ?'; $filterParams[] = $filterDari; }
    if ($filterSampai !== '') { $filterCond[] = 't.tanggal <= ?'; $filterParams[] = $filterSampai; }
} elseif ($filterTahun !== 'semua') {
    $filterCond[] = 'YEAR(t.tanggal) = ?';
    $filterParams[] = $filterTahun;
}

$periodeLabel = $useRange
    ? trim(($filterDari ? date('d M Y', strtotime($filterDari)) : '…') . ' – ' . ($filterSampai ? date('d M Y', strtotime($filterSampai)) : '…'))
    : ($filterTahun === 'semua' ? 'Semua waktu' : $filterTahun);
$isFiltered = $useRange || $filterTahun !== (string)$currentYear;

// ---------------- Statistik ----------------
$totalItem = $pdo->query('SELECT COUNT(*) c FROM items')->fetch()['c'];
$totalStok = $pdo->query('SELECT COALESCE(SUM(stok),0) c FROM items')->fetch()['c'];
$rendah = $pdo->query('SELECT COUNT(*) c FROM items WHERE stok <= stok_minimum AND stok_minimum > 0')->fetch()['c'];
$totalNilai = $pdo->query('SELECT COALESCE(SUM(stok*harga),0) c FROM items')->fetch()['c'];

$masukCond = array_merge(["t.tipe = 'masuk'"], $filterCond);
$masukStmt = $pdo->prepare('SELECT COALESCE(SUM(jumlah),0) c FROM transactions t WHERE ' . implode(' AND ', $masukCond));
$masukStmt->execute($filterParams);
$masukPeriode = $masukStmt->fetch()['c'];

$keluarCond = array_merge(["t.tipe = 'keluar'"], $filterCond);
$keluarStmt = $pdo->prepare('SELECT COALESCE(SUM(jumlah),0) c FROM transactions t WHERE ' . implode(' AND ', $keluarCond));
$keluarStmt->execute($filterParams);
$keluarPeriode = $keluarStmt->fetch()['c'];

// Mengambil riwayat aktivitas berdasarkan filter (limit disesuaikan dengan kode lama yaitu 6)
$recentWhere = $filterCond ? ('WHERE ' . implode(' AND ', $filterCond)) : '';
$recentStmt = $pdo->prepare("SELECT t.*, i.nama FROM transactions t LEFT JOIN items i ON i.id = t.item_id $recentWhere ORDER BY t.tanggal DESC, t.id DESC LIMIT 6");
$recentStmt->execute($filterParams);
$recent = $recentStmt->fetchAll();

// Susun daftar shortcut berdasarkan hak akses user yang login.
$shortcuts = [];
// Menambahkan kelola_permintaan (dari kode lama)
$fileMap = ['barang_masuk_keluar' => 'transaksi.php', 'kelola_permintaan' => 'kelola_permintaan.php'];
foreach (MODULE_META as $key => $meta) {
    if (!has_permission($key)) continue;
    $shortcuts[] = [
        'href'  => $fileMap[$key] ?? $key . '.php',
        'label' => MODULES[$key],
        'sub'   => $meta['sub'],
        'icon'  => $meta['icon'],
        'color' => $meta['color'],
    ];
}
if (is_super()) {
    foreach (ADMIN_META as $key => $meta) {
        $shortcuts[] = ['href' => $key . '.php', 'label' => $meta['label'], 'sub' => $meta['sub'], 'icon' => $meta['icon'], 'color' => $meta['color']];
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div>
    <h1>Selamat datang, <?= e($u['username']) ?> 👋</h1>
    <div class="sub">Begini kondisi stok ATK hari ini.</div>
  </div>
  <div style="display:flex;gap:10px;">
    <?php if (has_permission('barang_masuk_keluar')): ?>
      <a class="btn btn-primary" href="transaksi.php"><?= icon('qrcode', 16) ?> Pindai Barcode</a>
    <?php endif; ?>
  </div>
</div>

<!-- Komponen Filter Baru -->
<details class="card" style="margin-bottom:20px;padding:0;" <?= $isFiltered ? 'open' : '' ?>>
  <summary style="cursor:pointer;padding:22px 24px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
    <span style="display:flex;align-items:center;gap:8px;">
      <?= icon('filter', 16) ?: '🔎' ?>
      <span style="font-weight:600;">Filter periode</span>
      <span class="tag tag-ok" style="margin-left:4px;"><?= e($periodeLabel) ?></span>
    </span>
    <span class="helptext" style="margin:0;">Klik untuk <?= $isFiltered ? 'ubah' : 'atur' ?></span>
  </summary>
  <div style="padding:0 24px 22px;">
    <form method="get" class="form-row" style="align-items:end;margin-bottom:0;">
      <div class="field">
        <label>Tahun</label>
        <select name="tahun" onchange="this.form.dari.value='';this.form.sampai.value='';this.form.submit()">
          <option value="semua" <?= (!$useRange && $filterTahun === 'semua') ? 'selected' : '' ?>>Semua tahun</option>
          <?php foreach ($availYears as $y): ?>
            <option value="<?= e($y) ?>" <?= (!$useRange && (string)$filterTahun === (string)$y) ? 'selected' : '' ?>><?= e($y) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Dari tanggal</label>
        <input type="date" name="dari" value="<?= e($filterDari) ?>">
      </div>
      <div class="field">
        <label>Sampai tanggal</label>
        <input type="date" name="sampai" value="<?= e($filterSampai) ?>">
      </div>
      <div class="field" style="display:flex;gap:8px;">
        <button class="btn btn-primary" type="submit">Terapkan filter</button>
        <?php if ($isFiltered): ?><a class="btn btn-ghost" href="index.php">Reset</a><?php endif; ?>
      </div>
    </form>
    <div class="helptext" style="margin-top:10px;">Mengisi rentang tanggal akan menimpa pilihan tahun. Kosongkan semuanya untuk kembali ke tahun berjalan.</div>
  </div>
</details>

<div class="grid-stats">
  <div class="stat-card"><div class="label">Jenis barang</div><div class="val"><?= (int)$totalItem ?></div></div>
  <div class="stat-card"><div class="label">Total stok</div><div class="val"><?= (int)$totalStok ?></div></div>
  <div class="stat-card <?= $rendah > 0 ? 'danger' : '' ?>"><div class="label">Stok menipis</div><div class="val"><?= (int)$rendah ?></div></div>
  <div class="stat-card"><div class="label">Nilai stok</div><div class="val" style="font-size:19px;"><?= format_rupiah($totalNilai) ?></div></div>
  <div class="stat-card"><div class="label">Masuk · <?= e($periodeLabel) ?></div><div class="val"><?= (int)$masukPeriode ?></div></div>
  <div class="stat-card"><div class="label">Keluar · <?= e($periodeLabel) ?></div><div class="val"><?= (int)$keluarPeriode ?></div></div>
</div>

<div class="dash-columns">
  <div class="dash-main">
    <div class="section-label"><span class="dot dot-blue"></span>Navigasi</div>
    <div class="card">
      <h3>Pintasan</h3>
      <div class="shortcut-grid">
        <?php foreach ($shortcuts as $s): ?>
          <a class="shortcut-card" href="<?= e($s['href']) ?>">
            <span class="badge badge-<?= e($s['color']) ?>"><?= icon($s['icon'], 20) ?></span>
            <span class="sc-text">
              <span class="sc-title"><?= e($s['label']) ?></span>
              <span class="sc-sub"><?= e($s['sub']) ?></span>
            </span>
            <span class="sc-chev"><?= icon('chevron-right', 16) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$shortcuts): ?>
          <div class="empty"><b>Belum ada menu aktif</b>Hubungi super admin untuk mengaktifkan akses menu Anda.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="dash-side">
    <div class="section-label"><span class="dot dot-purple"></span>Aktivitas <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-dim);">· <?= e($periodeLabel) ?></span></div>
    <div class="card activity-card">
      <?php if (!$recent): ?>
        <div class="empty-panel">
          <span class="empty-ic"><?= icon('clock', 26) ?></span>
          <b><?= $isFiltered ? 'Tidak ada transaksi' : 'Belum ada aktivitas' ?></b>
          <p><?= $isFiltered ? 'Tidak ada barang masuk atau keluar pada periode yang dipilih.' : 'Saat ada barang masuk atau keluar, riwayatnya akan tampil di sini.' ?></p>
        </div>
      <?php else: ?>
        <ul class="activity-list">
          <?php foreach ($recent as $t): ?>
            <li>
              <span class="badge badge-<?= $t['tipe'] === 'masuk' ? 'green' : 'amber' ?> sm"><?= icon('arrows', 15) ?></span>
              <span class="al-text">
                <span class="al-title"><?= e($t['nama'] ?? '(dihapus)') ?></span>
                <span class="al-sub"><?= $t['tipe'] === 'masuk' ? 'Masuk' : 'Keluar' ?> · <?= (int)$t['jumlah'] ?> pcs · <?= e(date('d M Y', strtotime($t['tanggal']))) ?></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>