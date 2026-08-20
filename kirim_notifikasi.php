<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('kirim_notifikasi');

// ---------- Kirim notifikasi baru ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'kirim') {
    csrf_check();
    $judul = trim($_POST['judul'] ?? '');
    $pesan = trim($_POST['pesan'] ?? '');

    if ($judul === '' || $pesan === '') {
        flash_set('Judul dan pesan wajib diisi.', 'error');
        redirect('kirim_notifikasi.php');
    }

    $u = current_user();
    $stmt = $pdo->prepare("INSERT INTO notifikasi (tipe, judul, pesan, dibaca, dikirim_oleh) VALUES ('pengumuman', ?, ?, 0, ?)");
    $stmt->execute([$judul, $pesan, $u['username']]);
    flash_set('Notifikasi berhasil dikirim ke semua admin.');
    redirect('kirim_notifikasi.php');
}

// ---------- Batalkan/hapus notifikasi yang pernah dikirim ----------
// Sengaja dibatasi tipe='pengumuman' saja, supaya halaman ini tidak bisa
// menghapus notifikasi stok dari cron notifikasi_stok.php.
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $pdo->prepare("DELETE FROM notifikasi WHERE id = ? AND tipe = 'pengumuman'")->execute([$_GET['id']]);
    flash_set('Notifikasi dibatalkan.');
    redirect('kirim_notifikasi.php');
}

$rows = $pdo->query(
    "SELECT n.*, COUNT(nd.id) AS jumlah_dibaca
     FROM notifikasi n
     LEFT JOIN notifikasi_dibaca nd ON nd.notifikasi_id = n.id
     WHERE n.tipe = 'pengumuman'
     GROUP BY n.id
     ORDER BY n.created_at DESC LIMIT 50"
)->fetchAll();

$totalAdmin = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role <> 'bidang'")->fetch()['c'];

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Kirim Notifikasi</h1><div class="sub">Kirim pengumuman yang akan muncul di bel notifikasi semua admin (kecuali Admin Bidang)</div></div>
</div>

<style>
  .kn-layout{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start;}
  @media (max-width:900px){ .kn-layout{grid-template-columns:1fr;} }

  .kn-form-card{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
    padding:20px;box-shadow:var(--shadow-card);}
  .kn-form-card h3{margin:0 0 4px;font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:700;}
  .kn-form-card .kn-hint{font-size:12px;color:var(--text-dim);margin:0 0 16px;line-height:1.5;}
  .kn-field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;}
  .kn-label{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;font-weight:700;}
  .kn-input, .kn-textarea{width:100%;padding:9px 11px;border:1.5px solid var(--line);border-radius:8px;
    background:var(--paper-sunk);color:var(--text);font-size:13.5px;box-sizing:border-box;font-family:'Inter',Arial,sans-serif;}
  .kn-textarea{resize:vertical;min-height:110px;line-height:1.5;}
  .kn-input:focus, .kn-textarea:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .kn-submit{width:100%;justify-content:center;min-height:40px;}
  .kn-note{display:flex;gap:8px;align-items:flex-start;background:var(--paper-sunk);border:1px solid var(--line);
    border-radius:8px;padding:10px 12px;font-size:11.5px;color:var(--text-dim);line-height:1.5;margin-top:14px;}
  .kn-note svg{flex-shrink:0;margin-top:1px;opacity:.7;}

  .kn-list-wrap{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
    box-shadow:var(--shadow-card);overflow:hidden;}
  .kn-list-head{padding:14px 18px;border-bottom:1px solid var(--line);font-size:12.5px;font-weight:700;
    color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;}
  .kn-item{padding:14px 18px;border-bottom:1px solid var(--line-soft);}
  .kn-item:last-child{border-bottom:none;}
  .kn-item-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
  .kn-item-title{font-weight:700;font-size:14px;}
  .kn-item-msg{font-size:13px;color:var(--text-dim);margin-top:5px;line-height:1.5;white-space:pre-wrap;}
  .kn-item-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:11px;color:var(--text-faint);}
  .kn-item-meta span{display:inline-flex;align-items:center;gap:4px;}
  .kn-badge{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:99px;white-space:nowrap;flex-shrink:0;}
  .kn-badge.unread{background:rgba(167,139,250,.15);color:#a78bfa;}
  .kn-badge.read{background:var(--paper-sunk);color:var(--text-dim);}

  @media (max-width:600px){
    .kn-item-top{flex-direction:column;}
    .kn-item .btn{width:100%;justify-content:center;margin-top:8px;}
  }
</style>

<div class="kn-layout">
  <div class="kn-form-card">
    <h3>Pengumuman Baru</h3>
    <p class="kn-hint">Akan langsung tampil di bel notifikasi semua admin (kecuali Admin Bidang) begitu dikirim.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="kirim">
      <div class="kn-field">
        <label class="kn-label">Judul *</label>
        <input class="kn-input" type="text" name="judul" required maxlength="200" placeholder="cth. Pemadaman listrik terjadwal">
      </div>
      <div class="kn-field">
        <label class="kn-label">Pesan *</label>
        <textarea class="kn-textarea" name="pesan" required placeholder="Tulis isi pengumuman di sini..."></textarea>
      </div>
      <button type="submit" class="btn btn-primary kn-submit"><?= icon('bell', 15) ?> Kirim ke Semua Admin</button>
    </form>
    <div class="kn-note">
      <?= icon('gauge', 14) ?>
      <span>Status "dibaca" sekarang tercatat per admin — masing-masing akun harus membuka bel notifikasinya sendiri, tidak otomatis tertandai untuk admin lain.</span>
    </div>
  </div>

  <div class="kn-list-wrap">
    <div class="kn-list-head">Riwayat Pengumuman Terkirim</div>
    <?php if ($rows): ?>
      <?php foreach ($rows as $r): ?>
        <div class="kn-item">
          <div class="kn-item-top">
            <div>
              <div class="kn-item-title"><?= e($r['judul']) ?></div>
              <div class="kn-item-msg"><?= e($r['pesan']) ?></div>
              <div class="kn-item-meta">
                <span><?= icon('clock', 12) ?> <?= e(date('d M Y, H:i', strtotime($r['created_at']))) ?></span>
                <?php if (!empty($r['dikirim_oleh'])): ?><span><?= icon('user-check', 12) ?> <?= e($r['dikirim_oleh']) ?></span><?php endif; ?>
              </div>
            </div>
            <span class="kn-badge <?= $r['jumlah_dibaca'] > 0 ? 'read' : 'unread' ?>"><?= (int)$r['jumlah_dibaca'] ?>/<?= $totalAdmin ?> admin baca</span>
          </div>
          <a class="btn btn-ghost" style="color:#f87171;font-size:11.5px;padding:6px 10px;margin-top:10px;display:inline-block;"
             href="kirim_notifikasi.php?action=delete&id=<?= (int)$r['id'] ?>"
             onclick="return confirm('Batalkan pengumuman ini? Ini akan langsung hilang dari bel notifikasi semua admin.');">Batalkan</a>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="empty"><b>Belum ada pengumuman</b>Notifikasi broadcast yang Anda kirim akan muncul di sini.</div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>