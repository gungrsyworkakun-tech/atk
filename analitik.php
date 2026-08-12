<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
if (is_bidang()) { redirect('permintaan.php'); }

// ---------- KPI ringkas ----------
$totalBarang = (int)$pdo->query('SELECT COUNT(*) c FROM items')->fetch()['c'];
$totalStok = (int)$pdo->query('SELECT COALESCE(SUM(stok),0) c FROM items')->fetch()['c'];
$totalNilai = (float)$pdo->query('SELECT COALESCE(SUM(stok*harga),0) c FROM items')->fetch()['c'];
$stokMenipis = (int)$pdo->query('SELECT COUNT(*) c FROM items WHERE stok_minimum > 0 AND stok <= stok_minimum')->fetch()['c'];

$permMenunggu = (int)$pdo->query("SELECT COUNT(*) c FROM permintaan WHERE status='menunggu'")->fetch()['c'];
$totalBidangAktif = (int)$pdo->query('SELECT COUNT(*) c FROM bidang_list')->fetch()['c'];

$tahunIni = date('Y');
$masukTahunIni = (int)$pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND YEAR(tanggal)=?")
    ->execute([$tahunIni]) ? 0 : 0; // placeholder, dihitung ulang di bawah
$stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND YEAR(tanggal)=?");
$stmt->execute([$tahunIni]);
$masukTahunIni = (int)$stmt->fetch()['c'];
$stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='keluar' AND YEAR(tanggal)=?");
$stmt->execute([$tahunIni]);
$keluarTahunIni = (int)$stmt->fetch()['c'];

// ---------- Tren masuk/keluar 6 bulan terakhir ----------
$trendLabels = [];
$trendMasuk = [];
$trendKeluar = [];
$bulanID = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months", strtotime(date('Y-m-01')));
    $y = date('Y', $ts); $m = date('n', $ts);
    $trendLabels[] = $bulanID[$m - 1] . ' ' . substr($y, 2);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND YEAR(tanggal)=? AND MONTH(tanggal)=?");
    $stmt->execute([$y, $m]);
    $trendMasuk[] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='keluar' AND YEAR(tanggal)=? AND MONTH(tanggal)=?");
    $stmt->execute([$y, $m]);
    $trendKeluar[] = (int)$stmt->fetch()['c'];
}

// ---------- Distribusi status permintaan ----------
$statusRows = $pdo->query("SELECT status, COUNT(*) c FROM permintaan GROUP BY status")->fetchAll();
$statusMap = ['menunggu' => 0, 'diproses' => 0, 'selesai' => 0, 'ditolak' => 0];
foreach ($statusRows as $sr) { $statusMap[$sr['status']] = (int)$sr['c']; }

// ---------- Top 5 barang stok menipis ----------
$topMenipis = $pdo->query("
    SELECT nama, kode, stok, stok_minimum, satuan
    FROM items
    WHERE stok_minimum > 0
    ORDER BY (stok - stok_minimum) ASC
    LIMIT 5
")->fetchAll();

// ---------- Top 5 barang paling banyak keluar (sepanjang waktu) ----------
$topKeluar = $pdo->query("
    SELECT i.nama, i.satuan, COALESCE(SUM(t.jumlah),0) total
    FROM items i
    LEFT JOIN transactions t ON t.item_id = i.id AND t.tipe = 'keluar'
    GROUP BY i.id
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

// ---------- Rekap pengambilan per bidang (tahun ini) ----------
$topBidang = $pdo->prepare("
    SELECT bidang, COALESCE(SUM(jumlah),0) total
    FROM transactions
    WHERE tipe='keluar' AND bidang IS NOT NULL AND bidang <> '' AND YEAR(tanggal) = ?
    GROUP BY bidang
    ORDER BY total DESC
    LIMIT 6
");
$topBidang->execute([$tahunIni]);
$topBidang = $topBidang->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<style>
  .an-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:13px;margin-bottom:24px;}
  .an-chart-grid{display:grid;grid-template-columns:1.4fr 1fr;gap:20px;align-items:start;margin-bottom:20px;}
  @media (max-width:900px){ .an-chart-grid{grid-template-columns:1fr;} }
  .an-chart-card{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius);
    padding:20px 22px;box-shadow:var(--shadow-card);}
  .an-chart-card h3{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:700;margin:0 0 4px;}
  .an-chart-sub{font-size:12px;color:var(--text-dim);margin-bottom:16px;}
  .an-chart-wrap{position:relative;height:260px;}
  .an-chart-wrap.short{height:220px;}

  .an-list{display:flex;flex-direction:column;gap:10px;}
  .an-list-row{display:flex;align-items:center;gap:10px;}
  .an-list-rank{width:22px;height:22px;border-radius:50%;background:var(--paper-sunk);border:1px solid var(--line);
    display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:700;color:var(--text-dim);flex-shrink:0;}
  .an-list-main{flex:1;min-width:0;}
  .an-list-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .an-list-sub{font-size:11px;color:var(--text-dim);margin-top:1px;}
  .an-list-bar-wrap{width:100%;height:5px;background:var(--paper-sunk);border-radius:4px;margin-top:5px;overflow:hidden;}
  .an-list-bar{height:100%;border-radius:4px;background:var(--accent);}
  .an-list-bar.danger{background:var(--red);}
  .an-list-val{font-size:12.5px;font-weight:700;flex-shrink:0;font-variant-numeric:tabular-nums;}

  .an-empty-mini{font-size:12.5px;color:var(--text-dim);padding:20px 0;text-align:center;}
</style>

<div class="topline">
  <div><h1>Dashboard Analitik</h1><div class="sub">Ringkasan performa stok, transaksi, dan permintaan ATK</div></div>
</div>

<div class="an-kpi-grid">
  <div class="stat-card"><div class="label">Jenis Barang</div><div class="val"><?= $totalBarang ?></div></div>
  <div class="stat-card"><div class="label">Total Stok</div><div class="val"><?= number_format($totalStok, 0, ',', '.') ?></div></div>
  <div class="stat-card <?= $stokMenipis > 0 ? 'danger' : '' ?>"><div class="label">Stok Menipis</div><div class="val"><?= $stokMenipis ?></div></div>
  <div class="stat-card"><div class="label">Nilai Total Stok</div><div class="val" style="font-size:18px;"><?= format_rupiah($totalNilai) ?></div></div>
  <div class="stat-card"><div class="label">Permintaan Menunggu</div><div class="val"><?= $permMenunggu ?></div></div>
  <div class="stat-card"><div class="label">Bidang Terdaftar</div><div class="val"><?= $totalBidangAktif ?></div></div>
</div>

<div class="an-chart-grid">
  <div class="an-chart-card">
    <h3>Tren Barang Masuk &amp; Keluar</h3>
    <div class="an-chart-sub">6 bulan terakhir, dalam satuan dasar</div>
    <div class="an-chart-wrap"><canvas id="chartTrend"></canvas></div>
  </div>
  <div class="an-chart-card">
    <h3>Status Permintaan ATK</h3>
    <div class="an-chart-sub">Seluruh permintaan dari bidang</div>
    <div class="an-chart-wrap"><canvas id="chartStatus"></canvas></div>
  </div>
</div>

<div class="an-chart-grid">
  <div class="an-chart-card">
    <h3>Barang Paling Banyak Keluar</h3>
    <div class="an-chart-sub">Sepanjang waktu, top 5</div>
    <?php if (!$topKeluar || (int)$topKeluar[0]['total'] === 0): ?>
      <div class="an-empty-mini">Belum ada data transaksi keluar.</div>
    <?php else: ?>
      <div class="an-list">
        <?php $maxKeluar = max(array_column($topKeluar, 'total')) ?: 1;
        foreach ($topKeluar as $i => $tk): if ((int)$tk['total'] === 0) continue; ?>
          <div class="an-list-row">
            <div class="an-list-rank"><?= $i + 1 ?></div>
            <div class="an-list-main">
              <div class="an-list-name"><?= e($tk['nama']) ?></div>
              <div class="an-list-bar-wrap"><div class="an-list-bar" style="width:<?= round($tk['total'] / $maxKeluar * 100) ?>%;"></div></div>
            </div>
            <div class="an-list-val"><?= (int)$tk['total'] ?> <?= e($tk['satuan']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="an-chart-card">
    <h3>Stok Paling Menipis</h3>
    <div class="an-chart-sub">Terdekat dengan limit minimum</div>
    <?php if (!$topMenipis): ?>
      <div class="an-empty-mini">Belum ada barang dengan limit minimum diatur.</div>
    <?php else: ?>
      <div class="an-list">
        <?php foreach ($topMenipis as $i => $tm):
          $ratio = $tm['stok_minimum'] > 0 ? min(100, round($tm['stok'] / $tm['stok_minimum'] * 100)) : 100;
          $isDanger = $tm['stok'] <= $tm['stok_minimum'];
        ?>
          <div class="an-list-row">
            <div class="an-list-rank"><?= $i + 1 ?></div>
            <div class="an-list-main">
              <div class="an-list-name"><?= e($tm['nama']) ?></div>
              <div class="an-list-sub"><?= e($tm['kode']) ?> · limit <?= (int)$tm['stok_minimum'] ?> <?= e($tm['satuan']) ?></div>
              <div class="an-list-bar-wrap"><div class="an-list-bar <?= $isDanger ? 'danger' : '' ?>" style="width:<?= $ratio ?>%;"></div></div>
            </div>
            <div class="an-list-val" style="<?= $isDanger ? 'color:var(--red);' : '' ?>"><?= (int)$tm['stok'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="an-chart-card">
  <h3>Pengambilan per Bidang</h3>
  <div class="an-chart-sub">Tahun <?= $tahunIni ?>, total barang keluar per bidang</div>
  <?php if (!$topBidang): ?>
    <div class="an-empty-mini">Belum ada data pengambilan barang oleh bidang tahun ini.</div>
  <?php else: ?>
    <div class="an-chart-wrap short"><canvas id="chartBidang"></canvas></div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
  var cs = getComputedStyle(document.documentElement);
  var colAccent = cs.getPropertyValue('--accent').trim() || '#3B82F6';
  var colTeal = cs.getPropertyValue('--teal').trim() || '#2DD4BF';
  var colOchre = cs.getPropertyValue('--ochre').trim() || '#F59E0B';
  var colRed = cs.getPropertyValue('--red').trim() || '#F87171';
  var colTextDim = cs.getPropertyValue('--text-dim').trim() || '#8B93AC';
  var colLine = cs.getPropertyValue('--line-soft').trim() || '#1B2238';

  Chart.defaults.font.family = "'Inter', Arial, sans-serif";
  Chart.defaults.color = colTextDim;
  Chart.defaults.font.size = 11.5;

  // ---- Tren masuk/keluar ----
  var trendCtx = document.getElementById('chartTrend');
  if (trendCtx) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: <?= json_encode($trendLabels) ?>,
        datasets: [
          {
            label: 'Masuk',
            data: <?= json_encode($trendMasuk) ?>,
            borderColor: colTeal,
            backgroundColor: colTeal + '33',
            tension: 0.35,
            fill: true,
            pointRadius: 3,
            pointBackgroundColor: colTeal
          },
          {
            label: 'Keluar',
            data: <?= json_encode($trendKeluar) ?>,
            borderColor: colOchre,
            backgroundColor: colOchre + '33',
            tension: 0.35,
            fill: true,
            pointRadius: 3,
            pointBackgroundColor: colOchre
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } } },
        scales: {
          x: { grid: { color: colLine } },
          y: { beginAtZero: true, grid: { color: colLine } }
        }
      }
    });
  }

  // ---- Distribusi status permintaan ----
  var statusCtx = document.getElementById('chartStatus');
  if (statusCtx) {
    var statusData = <?= json_encode(array_values($statusMap)) ?>;
    var hasData = statusData.some(function (v) { return v > 0; });
    if (hasData) {
      new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: ['Menunggu', 'Diproses', 'Selesai', 'Ditolak'],
          datasets: [{
            data: statusData,
            backgroundColor: [colOchre, colAccent, colTeal, colRed],
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '68%',
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } } }
        }
      });
    } else {
      statusCtx.parentElement.innerHTML = '<div class="an-empty-mini">Belum ada data permintaan.</div>';
    }
  }

  // ---- Pengambilan per bidang ----
  var bidangCtx = document.getElementById('chartBidang');
  if (bidangCtx) {
    new Chart(bidangCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($topBidang, 'bidang')) ?>,
        datasets: [{
          label: 'Total keluar',
          data: <?= json_encode(array_map('intval', array_column($topBidang, 'total'))) ?>,
          backgroundColor: colAccent,
          borderRadius: 6,
          maxBarThickness: 42
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, grid: { color: colLine } }
        }
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>