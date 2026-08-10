<?php
require __DIR__ . '/includes/bootstrap.php';
require_bidang();

$u = current_user();

$stmt = $pdo->prepare('SELECT * FROM permintaan WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$u['id']]);
$myRequests = $stmt->fetchAll();

$itemsByReq = [];
if ($myRequests) {
    $ids = array_column($myRequests, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM permintaan_items WHERE permintaan_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) $itemsByReq[$row['permintaan_id']][] = $row;
}

require __DIR__ . '/includes/header.php';
?>
<style>
  .pq-card{border:1px solid var(--line,#232a35);border-radius:12px;padding:16px;background:var(--panel,#12161c);margin-bottom:12px;}
  .pq-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;}
  .pq-date{font-weight:600;font-size:14px;}
  .pq-sub{font-size:12px;color:var(--text-dim,#8b95a5);margin-top:2px;}
  .pq-items{margin-top:10px;font-size:13px;}
  .pq-items li{margin-bottom:2px;}
  .pq-note{margin-top:10px;font-size:12.5px;background:rgba(140,150,165,.08);border-radius:8px;padding:8px 10px;}

  .st-badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
  .st-menunggu{background:rgba(224,178,53,.15);color:#e0b235;}
  .st-diproses{background:rgba(76,140,255,.15);color:#4c8cff;}
  .st-selesai{background:rgba(76,175,140,.15);color:#4caf8c;}
  .st-ditolak{background:rgba(224,82,82,.15);color:#e05252;}
</style>

<div class="topline">
  <div><h1>Progres ATK</h1><div class="sub">Status permintaan ATK yang sudah diajukan bidang <?= e($u['bidang_nama'] ?? '-') ?></div></div>
</div>

<?php if (!$myRequests): ?>
  <div class="card"><div class="empty"><b>Belum ada permintaan</b>Ajukan permintaan lewat menu Permintaan ATK.</div></div>
<?php else: foreach ($myRequests as $r): ?>
  <div class="pq-card">
    <div class="pq-top">
      <div>
        <div class="pq-date"><?= e(date('d M Y', strtotime($r['tanggal']))) ?></div>
        <div class="pq-sub">Penanggung jawab: <?= e($r['penanggung_jawab']) ?></div>
      </div>
      <span class="st-badge <?= permintaan_status_class($r['status']) ?>"><?= e(permintaan_status_label($r['status'])) ?></span>
    </div>
    <ul class="pq-items">
      <?php foreach ($itemsByReq[$r['id']] ?? [] as $it): ?>
       <li>
          <?= e($it['nama_barang']) ?> — <b><?= (int)$it['jumlah'] ?></b> pcs
          <?php if (!empty($it['keterangan_satuan'])): ?>
            <span class="unit-note">(diajukan: <?= e($it['keterangan_satuan']) ?>)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($r['bast_file']): ?>
      <div style="margin-top:10px;">
        <button type="button" class="btn btn-ghost js-view-doc" style="font-size:12px;padding:6px 10px;"
          data-url="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>"
          data-download="serve_permintaan_bast.php?id=<?= (int)$r['id'] ?>&download=1"
          data-filename="<?= e($r['bast_file']) ?>"
          data-image="<?= strtolower(pathinfo($r['bast_file'], PATHINFO_EXTENSION)) === 'pdf' ? '0' : '1' ?>">Lihat BAST</button>
      </div>
    <?php else: ?>
      <div class="pq-sub" style="margin-top:10px;">Belum ada BAST — unggah lewat menu Input BAST.</div>
    <?php endif; ?>
    <?php if ($r['catatan_admin']): ?>
      <div class="pq-note">Catatan admin: <?= e($r['catatan_admin']) ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>