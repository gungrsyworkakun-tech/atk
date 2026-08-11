<?php
// Halaman pemanggil harus sudah require bootstrap.php dan require_login() sebelum file ini.
$u = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME']);

// Peta halaman -> label, untuk breadcrumb otomatis di topbar.
$pageLabels = ['index.php' => 'Dashboard'];
foreach (MODULES as $key => $label) {
    $file = $key === 'barang_masuk_keluar' ? 'transaksi.php' : ($key === 'kelola_permintaan' ? 'kelola_permintaan.php' : $key . '.php');
    $pageLabels[$file] = $label;
}
$pageLabels['kelola_admin.php'] = 'Kelola Admin';
$pageLabels['kelola_bidang.php'] = 'Kelola Bidang';
$pageLabels['permintaan.php'] = 'Permintaan ATK';
$pageLabels['progres.php'] = 'Progres ATK';
$pageLabels['input_bast.php'] = 'Input BAST';
$breadcrumbLabel = $pageLabels[$currentPage] ?? null;

function nav_item($file, $label, $iconName) {
    global $currentPage;
    $active = $currentPage === $file ? 'active' : '';
    echo '<a class="nav-item ' . $active . '" href="' . e($file) . '">';
    echo '<span class="nav-ic">' . icon($iconName, 17) . '</span>';
    echo '<span class="lbl">' . e($label) . '</span>';
    echo '</a>';
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pendataan ATK</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/style.css') ?: time() ?>">
<script>
  // Terapkan tema tersimpan sebelum render agar tidak ada "kedipan" warna.
  (function(){
    try {
      var t = localStorage.getItem('atk-theme');
      if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
    } catch(e){}
  })();
</script>
</head>
<body>
<div class="app">
  <div class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-row">
        <span class="brand-mark"></span>
        <div>
          <h2>Pendataan ATK</h2>
          <div class="eyebrow">Sistem Inventaris</div>
        </div>
      </div>
    </div>

    <div class="nav">
      <?php if (is_bidang()): ?>
        <div class="nav-group">
          <div class="nav-group-label">Menu Bidang</div>
          <?php nav_item('permintaan.php', 'Permintaan ATK', 'clipboard'); ?>
          <?php nav_item('progres.php', 'Progres ATK', 'gauge'); ?>
          <?php nav_item('input_bast.php', 'Input BAST', 'file'); ?>
        </div>
      <?php else: ?>
        <div class="nav-group">
          <div class="nav-group-label">Menu Utama</div>
          <?php nav_item('index.php', 'Dashboard', 'grid'); ?>
        </div>

        <?php if (has_permission('barang_masuk_keluar') || has_permission('barcode') || has_permission('data_barang') || has_permission('bast') || has_permission('realisasi') || has_permission('harga') || has_permission('bidang') || has_permission('kelola_permintaan')): ?>
        <div class="nav-group">
          <div class="nav-group-label">Data ATK</div>
          <?php if (has_permission('barang_masuk_keluar')): nav_item('transaksi.php', MODULES['barang_masuk_keluar'], MODULE_META['barang_masuk_keluar']['icon']); endif; ?>
          <?php if (has_permission('barcode')): nav_item('barcode.php', MODULES['barcode'], MODULE_META['barcode']['icon']); endif; ?>
          <?php if (has_permission('data_barang')): nav_item('data_barang.php', MODULES['data_barang'], MODULE_META['data_barang']['icon']); endif; ?>
          <?php if (has_permission('bast')): nav_item('bast.php', MODULES['bast'], MODULE_META['bast']['icon']); endif; ?>
          <?php if (has_permission('realisasi')): nav_item('realisasi.php', MODULES['realisasi'], MODULE_META['realisasi']['icon']); endif; ?>
          <?php if (has_permission('harga')): nav_item('harga.php', MODULES['harga'], MODULE_META['harga']['icon']); endif; ?>
          <?php if (has_permission('bidang')): nav_item('bidang.php', MODULES['bidang'], MODULE_META['bidang']['icon']); endif; ?>
          <?php if (has_permission('kelola_permintaan')): nav_item('kelola_permintaan.php', MODULES['kelola_permintaan'], MODULE_META['kelola_permintaan']['icon']); endif; ?>
        </div>
        <?php endif; ?>

        <?php if (is_super()): ?>
        <div class="nav-group">
          <div class="nav-group-label">Administrasi</div>
          <?php nav_item('kelola_admin.php', 'Kelola Admin', ADMIN_META['kelola_admin']['icon']); ?>
          <?php nav_item('kelola_bidang.php', 'Kelola Bidang', ADMIN_META['kelola_bidang']['icon']); ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="sidebar-foot">
      <button class="theme-toggle" id="theme-toggle" type="button" title="Ganti tema terang/gelap">
        <?= icon('sun', 16) ?><span class="lbl">Pilih Tema</span>
      </button>
      <details class="userchip">
        <summary>
          <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['username'], 0, 2))) ?></span>
          <span class="uc-info lbl">
            <span class="name"><?= e($u['username']) ?></span>
            <span class="role"><?= is_super() ? 'Super Admin' : (is_bidang() ? 'Admin Bidang' : 'Admin Biasa') ?></span>
          </span>
          <?= icon('chevron-down', 15) ?>
        </summary>
        <div class="uc-panel">
          <a class="uc-link danger" href="logout.php"><?= icon('logout', 15) ?> Keluar</a>
        </div>
      </details>
    </div>
  </div>

  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

  <div class="content-col">
    <div class="topbar">
      <button class="icon-btn-ghost" id="sidebar-toggle" type="button" title="Lipat/lebarkan sidebar"><?= icon('sidebar', 18) ?></button>
      <div class="crumb">
        <a href="<?= is_bidang() ? 'permintaan.php' : 'index.php' ?>"><?= is_bidang() ? 'Permintaan ATK' : 'Dashboard' ?></a>
        <?php if ($breadcrumbLabel && $currentPage !== 'index.php' && $currentPage !== 'permintaan.php'): ?>
          <span class="crumb-sep"><?= icon('chevron-right', 14) ?></span>
          <span class="crumb-current"><?= e($breadcrumbLabel) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!is_bidang()): ?>
      <div class="topbar-right">
        <div class="search-pill" id="global-search-wrap">
          <?= icon('search', 15) ?>
          <input type="text" id="global-search" placeholder="Cari barang, kode, atau bidang…" autocomplete="off">
          <div id="global-search-results" class="gs-results"></div>
        </div>
        <style>
          #global-search-wrap{position:relative;}
          .gs-results{display:none;position:absolute;top:calc(100% + 6px);right:0;width:320px;max-height:360px;overflow-y:auto;background:var(--panel,#12161c);border:1px solid var(--line,#232a35);border-radius:8px;box-shadow:0 12px 28px rgba(0,0,0,.35);z-index:50;padding:6px;}
          .gs-results.show{display:block;}
          .gs-group-label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-dim,#8b95a5);padding:6px 8px 2px;}
          .gs-item{display:flex;flex-direction:column;gap:2px;padding:8px;border-radius:6px;text-decoration:none;color:inherit;}
          .gs-item:hover{background:var(--line,#232a35);}
          .gs-item .gs-title{font-weight:600;font-size:13.5px;}
          .gs-item .gs-sub{font-size:12px;color:var(--text-dim,#8b95a5);}
          .gs-empty{padding:10px 8px;font-size:12.5px;color:var(--text-dim,#8b95a5);}
        </style>
      </div>
      <?php endif; ?>
    </div>

    <div class="main">
      <?php $flash = flash_get(); if ($flash): ?>
        <div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>