</div>
  </div>
</div>

<div id="docv-overlay" class="docv-overlay" hidden>
  <div class="docv-modal">
    <div class="docv-toolbar">
      <div class="docv-title" id="docv-title">Berkas</div>
      <div class="docv-toolbar-actions">
        <a id="docv-download" class="btn btn-primary" href="#" download>Download</a>
        <button type="button" id="docv-close" class="icon-btn-ghost" title="Tutup">✕</button>
      </div>
    </div>
    <div class="docv-body" id="docv-body"></div>
  </div>
</div>

<style>
  .docv-overlay{position:fixed;inset:0;background:rgba(5,8,12,.72);z-index:200;align-items:center;justify-content:center;padding:24px;display:none;}
  .docv-overlay:not([hidden]){display:flex;}
  .docv-modal{background:var(--panel,#12161c);border:1px solid var(--line,#232a35);border-radius:14px;width:min(920px,100%);height:min(85vh,860px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.5);}
  .docv-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line,#232a35);}
  .docv-title{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .docv-toolbar-actions{display:flex;align-items:center;gap:8px;flex-shrink:0;}
  .docv-toolbar-actions .btn{font-size:12.5px;padding:7px 14px;}
  .docv-body{flex:1;background:#525659;display:flex;align-items:center;justify-content:center;overflow:auto;}
  .docv-frame{width:100%;height:100%;border:none;background:#525659;}
  .docv-img{max-width:100%;max-height:100%;object-fit:contain;}
</style>

<script>
  // Toggle sidebar lipat/lebar (desktop) vs buka/tutup overlay (mobile)
  var sbToggle = document.getElementById('sidebar-toggle');
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebar-backdrop');
  var isMobile = function () { return window.matchMedia('(max-width: 860px)').matches; };

  function closeMobileSidebar() {
    sidebar.classList.remove('mobile-open');
    if (backdrop) backdrop.classList.remove('show');
    document.body.style.overflow = '';
  }
  function openMobileSidebar() {
    sidebar.classList.add('mobile-open');
    if (backdrop) backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  if (sbToggle && sidebar) {
    sbToggle.addEventListener('click', function () {
      if (isMobile()) {
        sidebar.classList.contains('mobile-open') ? closeMobileSidebar() : openMobileSidebar();
      } else {
        sidebar.classList.toggle('collapsed');
        try { localStorage.setItem('atk-sidebar', sidebar.classList.contains('collapsed') ? '1' : '0'); } catch(e){}
      }
    });
    if (!isMobile()) {
      try { if (localStorage.getItem('atk-sidebar') === '1') sidebar.classList.add('collapsed'); } catch(e){}
    }
  }

  // Tutup sidebar mobile saat klik backdrop, klik menu, tekan Esc, atau resize ke desktop
  if (backdrop) backdrop.addEventListener('click', closeMobileSidebar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMobileSidebar(); });
  document.querySelectorAll('.nav-item').forEach(function (a) {
    a.addEventListener('click', function () { if (isMobile()) closeMobileSidebar(); });
  });
  window.addEventListener('resize', function () { if (!isMobile()) closeMobileSidebar(); });

  // Toggle tema terang/gelap
  var themeBtn = document.getElementById('theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var html = document.documentElement;
      var next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
      html.setAttribute('data-theme', next);
      try { localStorage.setItem('atk-theme', next); } catch(e){}
    });
  }

  // Pencarian global di topbar
  (function () {
    var wrap = document.getElementById('global-search-wrap');
    var input = document.getElementById('global-search');
    var box = document.getElementById('global-search-results');
    if (!wrap || !input || !box) return;

    var timer = null;
    var currentCtl = null;

    function clearBox() { box.innerHTML = ''; box.classList.remove('show'); }
    function addGroup(label) {
      var lbl = document.createElement('div');
      lbl.className = 'gs-group-label';
      lbl.textContent = label;
      box.appendChild(lbl);
    }
    function addLink(href, title, sub) {
      var a = document.createElement('a');
      a.className = 'gs-item';
      a.href = href;
      var t = document.createElement('span');
      t.className = 'gs-title';
      t.textContent = title;
      a.appendChild(t);
      if (sub) {
        var s = document.createElement('span');
        s.className = 'gs-sub';
        s.textContent = sub;
        a.appendChild(s);
      }
      box.appendChild(a);
    }
    function render(data) {
      box.innerHTML = '';
      var hasAny = false;
      if (data.items && data.items.length) {
        hasAny = true;
        addGroup('Barang');
        data.items.forEach(function (it) {
          addLink('data_barang.php?action=edit&id=' + encodeURIComponent(it.id), it.nama, it.kode + ' · stok ' + it.stok);
        });
      }
      if (data.bidang && data.bidang.length) {
        hasAny = true;
        addGroup('Bidang');
        data.bidang.forEach(function (b) { addLink('bidang.php', b.nama); });
      }
      if (!hasAny) {
        var e = document.createElement('div');
        e.className = 'gs-empty';
        e.textContent = 'Tidak ada hasil.';
        box.appendChild(e);
      }
      box.classList.add('show');
    }

    input.addEventListener('input', function () {
      var q = input.value.trim();
      clearTimeout(timer);
      if (q.length < 2) { clearBox(); return; }
      timer = setTimeout(function () {
        if (currentCtl) currentCtl.abort();
        currentCtl = new AbortController();
        fetch('cari_barang.php?q=' + encodeURIComponent(q), { signal: currentCtl.signal })
          .then(function (r) { return r.json(); })
          .then(render)
          .catch(function () {});
      }, 250);
    });
    input.addEventListener('focus', function () { if (box.innerHTML.trim() !== '') box.classList.add('show'); });
    document.addEventListener('click', function (ev) { if (!wrap.contains(ev.target)) clearBox(); });
  })();

  // ---------- Modal viewer dokumen global (dipakai di transaksi.php & bast.php) ----------
  (function () {
    var overlay = document.getElementById('docv-overlay');
    var body = document.getElementById('docv-body');
    var title = document.getElementById('docv-title');
    var dl = document.getElementById('docv-download');
    var closeBtn = document.getElementById('docv-close');
    if (!overlay) return;

    window.openDocViewer = function (opts) {
      title.textContent = opts.filename || 'Berkas';
      dl.href = opts.downloadUrl || opts.url;
      dl.setAttribute('download', opts.filename || '');
      body.innerHTML = '';
      if (opts.isImage) {
        var img = document.createElement('img');
        img.className = 'docv-img';
        img.src = opts.url;
        body.appendChild(img);
      } else {
        var frame = document.createElement('iframe');
        frame.className = 'docv-frame';
        frame.src = opts.url;
        body.appendChild(frame);
      }
      overlay.hidden = false;
      document.body.style.overflow = 'hidden';
    };

    function closeDocViewer() {
      overlay.hidden = true;
      body.innerHTML = '';
      document.body.style.overflow = '';
    }

    closeBtn.addEventListener('click', closeDocViewer);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeDocViewer(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !overlay.hidden) closeDocViewer(); });

    // Aktifkan tombol "Lihat" yang pakai data-attribute (dipakai di bast.php & preview berkas lama di transaksi.php)
    document.querySelectorAll('.js-view-doc').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openDocViewer({
          url: btn.dataset.url,
          downloadUrl: btn.dataset.download || btn.dataset.url,
          filename: btn.dataset.filename || '',
          isImage: btn.dataset.image === '1'
        });
      });
    });
  })();
</script>
</body>
</html>