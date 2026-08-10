<?php
require __DIR__ . '/includes/bootstrap.php';
require_bidang();

$u = current_user();
$uploadDir = __DIR__ . '/uploads/permintaan/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
$allowedBastTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ajukan') {
    csrf_check();
    $penanggungJawab = trim($_POST['penanggung_jawab'] ?? '');
    if ($penanggungJawab === '') $penanggungJawab = $u['username'];

    $itemIds = $_POST['item_id'] ?? [];
    $jumlahs = $_POST['jumlah'] ?? [];
    $satuanPilihan = $_POST['satuan_id'] ?? []; // 'base' atau id dari item_satuan, paralel dengan item_id[]

    $rows = [];
    foreach ($itemIds as $i => $itemId) {
        $itemId = (int)$itemId;
        $jmlInput = max(0, (int)($jumlahs[$i] ?? 0));
        $satPilih = $satuanPilihan[$i] ?? 'base';
        if ($itemId > 0 && $jmlInput > 0) $rows[] = ['item_id' => $itemId, 'jumlah_input' => $jmlInput, 'satuan_id' => $satPilih];
    }

    if (!$rows) {
        flash_set('Pilih minimal satu barang dengan jumlah lebih dari 0.', 'error');
        redirect('permintaan.php');
    }

    $bastError = null;
    $hasBastUpload = !empty($_FILES['bast_file']) && $_FILES['bast_file']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasBastUpload) {
        $f = $_FILES['bast_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $bastError = 'Gagal mengunggah berkas BAST. Coba lagi.';
        } elseif (!in_array($f['type'], $allowedBastTypes, true)) {
            $bastError = 'Format berkas BAST harus PDF, JPG, PNG, atau WebP.';
        } elseif ($f['size'] > 5 * 1024 * 1024) {
            $bastError = 'Ukuran berkas BAST maksimal 5 MB.';
        }
    }
    if ($bastError) {
        flash_set($bastError, 'error');
        redirect('permintaan.php');
    }

    $pdo->beginTransaction();
    try {
        // Kunci & hitung kebutuhan per barang dalam satuan dasar (server-side, tidak percaya faktor dari client).
        $stmtLock = $pdo->prepare('SELECT * FROM items WHERE id = ? FOR UPDATE');
        $stmtSat = $pdo->prepare('SELECT * FROM item_satuan WHERE id = ? AND item_id = ?');

        $itemsCache = [];
        $perItemNeed = []; // total kebutuhan base per item_id, untuk validasi stok
        $preparedRows = []; // baris siap insert, sudah dikonversi ke base

        foreach ($rows as $r) {
            $itemId = $r['item_id'];
            if (!isset($itemsCache[$itemId])) {
                $stmtLock->execute([$itemId]);
                $it = $stmtLock->fetch();
                if (!$it) { continue; }
                $itemsCache[$itemId] = $it;
            }
            $item = $itemsCache[$itemId];

            $faktor = 1;
            $satuanNama = $item['satuan'];
            if ($r['satuan_id'] !== 'base') {
                $stmtSat->execute([(int)$r['satuan_id'], $itemId]);
                $sat = $stmtSat->fetch();
                if ($sat) {
                    $faktor = (int)$sat['isi'];
                    $satuanNama = $sat['nama_satuan'];
                }
            }

            $jumlahBase = $r['jumlah_input'] * $faktor;
            $keteranganSatuan = $faktor > 1
                ? ($r['jumlah_input'] . ' ' . $satuanNama . ' (= ' . $jumlahBase . ' ' . $item['satuan'] . ')')
                : null;

            $perItemNeed[$itemId] = ($perItemNeed[$itemId] ?? 0) + $jumlahBase;
            $preparedRows[] = [
                'item_id' => $itemId,
                'nama_barang' => $item['nama'],
                'jumlah_base' => $jumlahBase,
                'keterangan_satuan' => $keteranganSatuan,
            ];
        }

        $insufficient = [];
        foreach ($perItemNeed as $itemId => $need) {
            $it = $itemsCache[$itemId];
            if ($need > (int)$it['stok']) {
                $insufficient[] = $it['nama'] . ' (diminta ' . $need . ' ' . $it['satuan'] . ', tersedia ' . $it['stok'] . ' ' . $it['satuan'] . ')';
            }
        }

        if ($insufficient) {
            $pdo->rollBack();
            flash_set('Stok tidak mencukupi untuk: ' . implode(', ', $insufficient) . '. Kurangi jumlah atau tunggu stok bertambah.', 'error');
            redirect('permintaan.php');
        }

        $pdo->prepare('INSERT INTO permintaan (user_id, bidang, penanggung_jawab, status, tanggal) VALUES (?,?,?,?,?)')
            ->execute([$u['id'], $u['bidang_nama'] ?? '-', $penanggungJawab, 'menunggu', date('Y-m-d')]);
        $pid = $pdo->lastInsertId();

        $insItem = $pdo->prepare('INSERT INTO permintaan_items (permintaan_id, item_id, nama_barang, jumlah, keterangan_satuan) VALUES (?,?,?,?,?)');
        foreach ($preparedRows as $pr) {
            $insItem->execute([$pid, $pr['item_id'], $pr['nama_barang'], $pr['jumlah_base'], $pr['keterangan_satuan']]);
        }
        foreach ($perItemNeed as $itemId => $need) {
            $pdo->prepare('UPDATE items SET stok = stok - ? WHERE id = ?')->execute([$need, $itemId]);
        }

        if ($hasBastUpload) {
            $ext = pathinfo($_FILES['bast_file']['name'], PATHINFO_EXTENSION);
            $safeName = 'permintaan-' . $pid . '-' . time() . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
            if (move_uploaded_file($_FILES['bast_file']['tmp_name'], $uploadDir . $safeName)) {
                $pdo->prepare('UPDATE permintaan SET bast_file = ? WHERE id = ?')->execute([$safeName, $pid]);
            }
        }

        $pdo->commit();
        flash_set('Permintaan ATK berhasil diajukan, stok otomatis dikurangi. Pantau progresnya di menu Progres ATK.');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash_set('Gagal menyimpan permintaan.', 'error');
    }
    redirect('permintaan.php');
}

$items = $pdo->query('SELECT id, kode, nama, satuan, stok FROM items ORDER BY nama')->fetchAll();
$allSatuan = $pdo->query('SELECT * FROM item_satuan ORDER BY item_id, isi')->fetchAll();
$satuanByItem = [];
foreach ($allSatuan as $s) $satuanByItem[$s['item_id']][] = $s;

require __DIR__ . '/includes/header.php';
?>
<div class="topline">
  <div><h1>Permintaan ATK</h1><div class="sub">Ajukan kebutuhan ATK untuk bidang <?= e($u['bidang_nama'] ?? '-') ?></div></div>
</div>

<div class="card">
  <h3>Ajukan permintaan baru</h3>

  <div class="req-intro">
    <?= icon('bell', 16) ?>
    <span>Stok akan otomatis berkurang begitu permintaan diajukan. Jika permintaan ditolak admin, stok akan dikembalikan otomatis. Pilih satuan sesuai kebutuhan (mis. Box/Kardus) — sistem otomatis mengonversinya.</span>
  </div>

  <?php if (!$items): ?>
    <div class="empty"><b>Belum ada barang</b>Hubungi admin gudang untuk menambahkan data barang.</div>
  <?php else: ?>
  <form method="post" id="req-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="do" value="ajukan">

    <div class="req-section-label">
      <span>Daftar barang yang diinginkan</span>
      <span class="req-count" id="req-count">1 item</span>
    </div>

    <div class="req-rows" id="req-rows"></div>
    <button type="button" class="req-add-row" id="req-add-row"><?= icon('plus', 14) ?> Tambah barang</button>

    <div class="req-bast-section">
      <label>Berkas BAST (opsional)</label>
      <div class="req-bast-zone">
        <input type="file" name="bast_file" id="req-bast-input" accept="application/pdf,image/*" style="display:none;">
        <button type="button" class="btn btn-ghost" id="req-bast-pick-btn">Pilih Berkas</button>
        <span class="helptext" style="margin:0;">PDF, JPG, PNG, atau WebP. Maks 5 MB.</span>
      </div>
      <div id="req-bast-preview" class="req-bast-preview"></div>
    </div>

    <div class="req-footer">
      <div class="req-pj-field">
        <label>Penanggung jawab / penerima</label>
        <input name="penanggung_jawab" value="<?= e($u['username']) ?>">
      </div>
      <button class="btn btn-primary req-submit-btn" type="submit">Ajukan Permintaan</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
(function () {
  var itemsData = <?= json_encode(array_map(function ($it) {
      return ['id' => $it['id'], 'nama' => $it['nama'], 'kode' => $it['kode'], 'satuan' => $it['satuan'], 'stok' => (int)$it['stok']];
  }, $items)) ?>;
  var satuanByItem = <?= json_encode($satuanByItem) ?>;

  var rowsWrap = document.getElementById('req-rows');
  var addBtn = document.getElementById('req-add-row');
  var countBadge = document.getElementById('req-count');
  if (rowsWrap) {
    var updateCount = function () {
      var n = rowsWrap.children.length;
      countBadge.textContent = n + ' item';
      Array.prototype.forEach.call(rowsWrap.children, function (row, idx) {
        var num = row.querySelector('.req-row-num');
        if (num) num.textContent = idx + 1;
      });
    };

    var addRow = function () {
      var row = document.createElement('div');
      row.className = 'req-row';

      var numBadge = document.createElement('div');
      numBadge.className = 'req-row-num';
      numBadge.textContent = rowsWrap.children.length + 1;

      var main = document.createElement('div');
      main.className = 'req-row-main';

      var mainLabel = document.createElement('label');
      mainLabel.className = 'req-row-main-label';
      mainLabel.innerHTML = '&nbsp;';
      main.appendChild(mainLabel);

      var select = document.createElement('select');
      select.name = 'item_id[]';
      itemsData.forEach(function (it) {
        var opt = document.createElement('option');
        opt.value = it.id;
        opt.textContent = it.nama + ' (' + it.kode + ')' + (it.stok <= 0 ? ' — habis' : '');
        opt.dataset.stok = it.stok;
        opt.dataset.satuan = it.satuan;
        select.appendChild(opt);
      });

      var stokInfo = document.createElement('div');
      stokInfo.className = 'req-row-stok';
      stokInfo.innerHTML = '<span class="req-row-stok-dot"></span><span class="req-row-stok-text">Tersedia</span><span class="req-row-stok-num"></span>';

      main.appendChild(select);
      main.appendChild(stokInfo);

      // Kolom Satuan
      var unitWrap = document.createElement('div');
      unitWrap.className = 'req-unit-wrap';
      var unitLabel = document.createElement('label');
      unitLabel.textContent = 'Satuan';
      var unitSelect = document.createElement('select');
      var unitConvert = document.createElement('div');
      unitConvert.className = 'req-unit-convert';
      unitWrap.appendChild(unitLabel);
      unitWrap.appendChild(unitSelect);
      unitWrap.appendChild(unitConvert);

      // Kolom Jumlah
      var qtyWrap = document.createElement('div');
      qtyWrap.className = 'req-qty-wrap';
      var qtyLabel = document.createElement('label');
      qtyLabel.textContent = 'Jumlah';
      var qty = document.createElement('input');
      qty.type = 'number';
      qty.name = 'jumlah[]';
      qty.min = '1';
      qty.value = '1';
      qtyWrap.appendChild(qtyLabel);
      qtyWrap.appendChild(qty);

      function populateUnits() {
        var opt = select.options[select.selectedIndex];
        var baseSatuan = opt ? (opt.dataset.satuan || 'pcs') : 'pcs';
        var itemId = opt ? opt.value : null;

        unitSelect.innerHTML = '';
        unitSelect.name = 'satuan_id[]';
        var baseOpt = document.createElement('option');
        baseOpt.value = 'base';
        baseOpt.textContent = baseSatuan;
        baseOpt.dataset.faktor = 1;
        unitSelect.appendChild(baseOpt);

        var alt = satuanByItem[itemId] || [];
        alt.forEach(function (s) {
          var o = document.createElement('option');
          o.value = s.id;
          o.textContent = s.nama_satuan;
          o.dataset.faktor = s.isi;
          unitSelect.appendChild(o);
        });

        updateStokAndConvert();
      }

      function updateStokAndConvert() {
        var opt = select.options[select.selectedIndex];
        var stok = opt ? parseInt(opt.dataset.stok, 10) || 0 : 0;
        var baseSatuan = opt ? (opt.dataset.satuan || 'pcs') : 'pcs';

        stokInfo.classList.remove('low', 'empty');
        if (stok <= 0) stokInfo.classList.add('empty');
        else if (stok <= 5) stokInfo.classList.add('low');
        stokInfo.querySelector('.req-row-stok-num').textContent = stok + ' ' + baseSatuan;

        var unitOpt = unitSelect.options[unitSelect.selectedIndex];
        var faktor = unitOpt ? parseInt(unitOpt.dataset.faktor, 10) || 1 : 1;
        var jml = parseInt(qty.value, 10) || 0;

        if (faktor > 1) {
          unitConvert.textContent = '= ' + (jml * faktor) + ' ' + baseSatuan;
        } else {
          unitConvert.textContent = '';
        }

        var maxByUnit = faktor > 0 ? Math.floor(stok / faktor) : stok;
        qty.max = maxByUnit > 0 ? maxByUnit : 1;
        if (jml > maxByUnit && maxByUnit > 0) qty.value = maxByUnit;
      }

      select.addEventListener('change', populateUnits);
      unitSelect.addEventListener('change', updateStokAndConvert);
      qty.addEventListener('input', updateStokAndConvert);
      populateUnits();

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'req-row-remove';
      removeBtn.innerHTML = '✕';
      removeBtn.addEventListener('click', function () {
        if (rowsWrap.children.length > 1) {
          row.remove();
          updateCount();
        }
      });

      row.appendChild(numBadge);
      row.appendChild(main);
      row.appendChild(unitWrap);
      row.appendChild(qtyWrap);
      row.appendChild(removeBtn);
      rowsWrap.appendChild(row);
      updateCount();
    };

    addBtn.addEventListener('click', addRow);
    addRow();
  }

  // ---------- Preview berkas BAST sebelum kirim ----------
  var bastPickBtn = document.getElementById('req-bast-pick-btn');
  var bastInput = document.getElementById('req-bast-input');
  var bastPreview = document.getElementById('req-bast-preview');
  var allowedBastTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

  if (bastPickBtn && bastInput) {
    bastPickBtn.addEventListener('click', function () { bastInput.click(); });

    bastInput.addEventListener('change', function () {
      var file = bastInput.files[0];
      bastPreview.innerHTML = '';
      if (!file) { bastPreview.style.display = 'none'; return; }

      if (allowedBastTypes.indexOf(file.type) === -1) {
        alert('Format tidak didukung. Gunakan PDF, JPG, PNG, atau WebP.');
        bastInput.value = '';
        bastPreview.style.display = 'none';
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        alert('Ukuran berkas maksimal 5 MB.');
        bastInput.value = '';
        bastPreview.style.display = 'none';
        return;
      }

      var objectUrl = URL.createObjectURL(file);
      var isImage = file.type.indexOf('image/') === 0;

      var card = document.createElement('div');
      card.className = 'req-bast-preview-card';

      var thumb = document.createElement('div');
      thumb.className = 'req-bast-thumb';
      if (isImage) {
        var img = document.createElement('img');
        img.src = objectUrl;
        thumb.appendChild(img);
      } else {
        thumb.classList.add('pdf');
        thumb.textContent = 'PDF';
      }

      var info = document.createElement('div');
      info.className = 'req-bast-info';
      var name = document.createElement('div');
      name.className = 'req-bast-name';
      name.textContent = file.name;
      var size = document.createElement('div');
      size.className = 'req-bast-size';
      size.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
      info.appendChild(name);
      info.appendChild(size);

      var viewBtn = document.createElement('button');
      viewBtn.type = 'button';
      viewBtn.className = 'btn btn-ghost';
      viewBtn.style.fontSize = '12px';
      viewBtn.style.padding = '6px 10px';
      viewBtn.textContent = 'Lihat';
      viewBtn.addEventListener('click', function () {
        if (typeof openDocViewer === 'function') {
          openDocViewer({ url: objectUrl, downloadUrl: objectUrl, filename: file.name, isImage: isImage });
        }
      });

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'req-bast-remove';
      removeBtn.textContent = '✕';
      removeBtn.addEventListener('click', function () {
        bastInput.value = '';
        bastPreview.style.display = 'none';
        bastPreview.innerHTML = '';
      });

      card.appendChild(thumb);
      card.appendChild(info);
      card.appendChild(viewBtn);
      card.appendChild(removeBtn);
      bastPreview.appendChild(card);
      bastPreview.style.display = 'block';
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>