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
$pageLabels['analitik.php'] = 'Dashboard Analitik';
$pageLabels['katalog.php'] = 'Katalog Barang';
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
<link rel="icon" type="image/png" href="assets/1.png?v=<?= @filemtime(dirname(__DIR__).'/assets/1.png') ?: time() ?>">
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
        <img src="assets/1.png?v=<?= @filemtime(dirname(__DIR__).'/assets/1.png') ?: time() ?>" alt="Logo" class="brand-mark">
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
          <?php nav_item('katalog.php', 'Katalog Barang', 'box'); ?>
          <?php nav_item('permintaan.php', 'Permintaan ATK', 'clipboard'); ?>
          <?php nav_item('progres.php', 'Progres ATK', 'gauge'); ?>
          <?php nav_item('input_bast.php', 'Input BAST', 'file'); ?>
        </div>
      <?php else: ?>
        <div class="nav-group">
          <div class="nav-group-label">Menu Utama</div>
          <?php nav_item('index.php', 'Dashboard', 'grid'); ?>
          <?php nav_item('analitik.php', 'Dashboard Analitik', 'gauge'); ?>
        </div>

        <?php if (has_permission('barang_masuk_keluar') || has_permission('barcode') || has_permission('data_barang') || has_permission('bast') || has_permission('realisasi') || has_permission('harga') || has_permission('bidang') || has_permission('kelola_permintaan') || has_permission('asisten_ai') || has_permission('penitipan')): ?>
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
          <?php if (has_permission('penitipan')): nav_item('penitipan.php', MODULES['penitipan'], MODULE_META['penitipan']['icon']); endif; ?>
          <?php if (has_permission('asisten_ai')): nav_item('asisten_ai.php', MODULES['asisten_ai'], MODULE_META['asisten_ai']['icon']); endif; ?>
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
        <div class="notif-wrap" id="notif-wrap">
          <button class="icon-btn-ghost notif-bell" id="notif-bell" type="button" title="Notifikasi">
            <?= icon('bell', 18) ?>
            <span class="notif-dot" id="notif-dot" hidden></span>
          </button>
          <div class="notif-panel" id="notif-panel">
            <div class="notif-panel-head">
              <span>Notifikasi</span>
              <button type="button" id="notif-mark-all">Tandai semua dibaca</button>
            </div>
            <div class="notif-list" id="notif-list">
              <div class="notif-empty">Memuat…</div>
            </div>
          </div>
        </div>
        <style>
          .notif-wrap{position:relative;}
          .notif-bell{position:relative;}
          .notif-dot{position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:#f43f5e;box-shadow:0 0 0 2px var(--panel,#12161c);}
          .notif-panel{display:none;position:absolute;top:calc(100% + 6px);right:0;width:340px;max-height:400px;overflow-y:auto;background:var(--panel,#12161c);border:1px solid var(--line,#232a35);border-radius:8px;box-shadow:0 12px 28px rgba(0,0,0,.35);z-index:50;}
          .notif-panel.show{display:block;}
          .notif-panel-head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--line,#232a35);font-size:12.5px;font-weight:600;}
          .notif-panel-head button{background:none;border:none;color:var(--accent,#a78bfa);font-size:11.5px;cursor:pointer;padding:0;}
          .notif-panel-head button:hover{text-decoration:underline;}
          .notif-item{display:block;padding:10px 12px;border-bottom:1px solid var(--line,#232a35);text-decoration:none;color:inherit;cursor:pointer;}
          .notif-item:last-child{border-bottom:none;}
          .notif-item:hover{background:var(--line,#232a35);}
          .notif-item.unread{background:rgba(167,139,250,.07);}
          .notif-item .ni-title{font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;}
          .notif-item .ni-title .ni-dot{width:6px;height:6px;border-radius:50%;background:#f43f5e;flex-shrink:0;}
          .notif-item .ni-msg{font-size:12px;color:var(--text-dim,#8b95a5);margin-top:3px;line-height:1.4;}
          .notif-item .ni-time{font-size:10.5px;color:var(--text-dim,#8b95a5);margin-top:4px;}
          .notif-empty{padding:20px 12px;text-align:center;font-size:12.5px;color:var(--text-dim,#8b95a5);}
        </style>
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

    <input type="hidden" id="globalCsrfToken" value="<?= e(csrf_token()) ?>">
    <script>
      (function(){
        var wrap = document.getElementById('notif-wrap');
        if (!wrap) return; // is_bidang() -> bel tidak dirender

        var bell = document.getElementById('notif-bell');
        var dot = document.getElementById('notif-dot');
        var panel = document.getElementById('notif-panel');
        var list = document.getElementById('notif-list');
        var markAllBtn = document.getElementById('notif-mark-all');
        var csrf = document.getElementById('globalCsrfToken').value;
        var loaded = false;

        function waktuRelatif(iso) {
          var detik = Math.floor((Date.now() - new Date(iso.replace(' ', 'T'))) / 1000);
          if (detik < 60) return 'baru saja';
          if (detik < 3600) return Math.floor(detik / 60) + ' menit lalu';
          if (detik < 86400) return Math.floor(detik / 3600) + ' jam lalu';
          return Math.floor(detik / 86400) + ' hari lalu';
        }

        function renderList(items) {
          if (!items.length) { list.innerHTML = '<div class="notif-empty">Belum ada notifikasi.</div>'; return; }
          list.innerHTML = items.map(function (n) {
            return '<div class="notif-item ' + (n.dibaca ? '' : 'unread') + '" data-id="' + n.id + '">' +
              '<div class="ni-title">' + (n.dibaca ? '' : '<span class="ni-dot"></span>') + escapeHtml(n.judul) + '</div>' +
              '<div class="ni-msg">' + escapeHtml(n.pesan) + '</div>' +
              '<div class="ni-time">' + waktuRelatif(n.created_at) + '</div>' +
            '</div>';
          }).join('');
        }

        function escapeHtml(s) {
          var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML;
        }

        function updateDot(unreadCount) {
          dot.hidden = unreadCount <= 0;
        }

        function muatNotifikasi() {
          fetch('notifikasi.php?do=list').then(function (r) { return r.json(); }).then(function (data) {
            renderList(data.items || []);
            updateDot(data.unread || 0);
            loaded = true;
          }).catch(function () {
            list.innerHTML = '<div class="notif-empty">Gagal memuat notifikasi.</div>';
          });
        }

        function cekJumlahSaja() {
          fetch('notifikasi.php?do=count').then(function (r) { return r.json(); }).then(function (data) {
            updateDot(data.unread || 0);
          }).catch(function () {});
        }

        bell.addEventListener('click', function (e) {
          e.stopPropagation();
          var show = !panel.classList.contains('show');
          panel.classList.toggle('show', show);
          if (show && !loaded) muatNotifikasi();
        });

        list.addEventListener('click', function (e) {
          var item = e.target.closest('.notif-item');
          if (!item || !item.classList.contains('unread')) return;
          var id = item.dataset.id;
          item.classList.remove('unread');
          var dotEl = item.querySelector('.ni-dot'); if (dotEl) dotEl.remove();
          var fd = new FormData(); fd.append('csrf', csrf); fd.append('id', id);
          fetch('notifikasi.php?do=mark_read', { method: 'POST', body: fd }).then(function () { cekJumlahSaja(); });
        });

        markAllBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          document.querySelectorAll('.notif-item.unread').forEach(function (el) {
            el.classList.remove('unread');
            var dotEl = el.querySelector('.ni-dot'); if (dotEl) dotEl.remove();
          });
          var fd = new FormData(); fd.append('csrf', csrf);
          fetch('notifikasi.php?do=mark_all_read', { method: 'POST', body: fd }).then(function () { updateDot(0); });
        });

        document.addEventListener('click', function (e) {
          if (!wrap.contains(e.target)) panel.classList.remove('show');
        });

        cekJumlahSaja();
        setInterval(cekJumlahSaja, 60000); // cek jumlah belum-dibaca tiap 1 menit
      })();
    </script>

    <div class="main">
      <?php $flash = flash_get(); if ($flash): ?>
        <div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>