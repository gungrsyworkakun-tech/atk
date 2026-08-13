<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('asisten_ai');
require __DIR__ . '/includes/ai_helper.php';

$u = current_user();

// ============================================================
// LAPISAN 2 — Query aman per intent (whitelist, prepared statement).
// AI tidak pernah menyentuh bagian ini secara langsung.
// ============================================================

function query_rekap_stok_menipis(PDO $pdo) {
    $stmt = $pdo->query('SELECT kode, nama, satuan, stok, stok_minimum FROM items WHERE stok_minimum > 0 AND stok <= stok_minimum ORDER BY stok ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['title' => 'Barang Stok Menipis', 'rows' => $rows, 'chart' => null, 'summary' => ['jumlah_barang' => count($rows)]];
}

function query_cek_ketersediaan(PDO $pdo, $namaBarang) {
    $stmt = $pdo->prepare('SELECT kode, nama, satuan, stok, stok_minimum FROM items WHERE nama LIKE ? OR kode LIKE ? ORDER BY nama LIMIT 5');
    $like = '%' . $namaBarang . '%';
    $stmt->execute([$like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return ['title' => 'Ketersediaan: ' . $namaBarang, 'rows' => $rows, 'chart' => null, 'summary' => ['kata_kunci' => $namaBarang, 'ditemukan' => count($rows)]];
}

function query_ringkasan_laporan(PDO $pdo) {
    $bulanIni = date('Y-m');
    $bulanLalu = date('Y-m', strtotime('-1 month'));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND DATE_FORMAT(tanggal,'%Y-%m') = ?");
    $stmt->execute([$bulanIni]);
    $totalMasuk = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='keluar' AND DATE_FORMAT(tanggal,'%Y-%m') = ?");
    $stmt->execute([$bulanIni]);
    $totalKeluar = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='keluar' AND DATE_FORMAT(tanggal,'%Y-%m') = ?");
    $stmt->execute([$bulanLalu]);
    $totalKeluarLalu = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT i.nama, SUM(t.jumlah) total FROM transactions t JOIN items i ON i.id = t.item_id WHERE t.tipe='keluar' AND DATE_FORMAT(t.tanggal,'%Y-%m') = ? GROUP BY t.item_id ORDER BY total DESC LIMIT 1");
    $stmt->execute([$bulanIni]);
    $top = $stmt->fetch();

    $persen = $totalKeluarLalu > 0 ? round((($totalKeluar - $totalKeluarLalu) / $totalKeluarLalu) * 100, 1) : null;

    $row = [
        'periode' => $bulanIni, 'total_masuk' => $totalMasuk, 'total_keluar' => $totalKeluar,
        'perubahan_persen_vs_bulan_lalu' => $persen, 'barang_paling_diminta' => $top['nama'] ?? null,
    ];
    return ['title' => 'Ringkasan Laporan Bulan Ini', 'rows' => [$row], 'chart' => null, 'summary' => $row];
}

function query_grafik_transaksi(PDO $pdo, $jumlahBulan) {
    $jumlahBulan = max(1, min(24, (int)($jumlahBulan ?: 6)));
    $namaBulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $labels = []; $masuk = []; $keluar = [];

    for ($i = $jumlahBulan - 1; $i >= 0; $i--) {
        $ts = strtotime("-$i months", strtotime(date('Y-m-01')));
        $y = date('Y', $ts); $m = (int)date('n', $ts);
        $labels[] = $namaBulan[$m] . ' ' . substr($y, 2);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND YEAR(tanggal)=? AND MONTH(tanggal)=?");
        $stmt->execute([$y, $m]);
        $masuk[] = (int)$stmt->fetch()['c'];

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='keluar' AND YEAR(tanggal)=? AND MONTH(tanggal)=?");
        $stmt->execute([$y, $m]);
        $keluar[] = (int)$stmt->fetch()['c'];
    }

    $rows = [];
    foreach ($labels as $i => $l) $rows[] = ['bulan' => $l, 'masuk' => $masuk[$i], 'keluar' => $keluar[$i]];

    return [
        'title' => 'Tren Masuk & Keluar (' . $jumlahBulan . ' Bulan Terakhir)',
        'rows' => $rows,
        'chart' => ['type' => 'line', 'labels' => $labels, 'datasets' => [
            ['label' => 'Masuk', 'values' => $masuk], ['label' => 'Keluar', 'values' => $keluar],
        ]],
        'summary' => ['jumlah_bulan' => $jumlahBulan, 'total_masuk' => array_sum($masuk), 'total_keluar' => array_sum($keluar)],
    ];
}

function query_detail_transaksi(PDO $pdo, $tipe, $periode) {
    $tipe = in_array($tipe, ['masuk', 'keluar'], true) ? $tipe : 'keluar';

    if ($periode === 'bulan_ini') {
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe = ? AND YEAR(t.tanggal)=? AND MONTH(t.tanggal)=? ORDER BY t.tanggal DESC");
        $stmt->execute([$tipe, date('Y'), date('n')]);
        $label = 'Bulan Ini';
    } else {
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe = ? AND t.tanggal = CURDATE() ORDER BY t.id DESC");
        $stmt->execute([$tipe]);
        $label = 'Hari Ini';
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'title' => 'Barang ' . ucfirst($tipe) . ' - ' . $label,
        'rows' => $rows, 'chart' => null,
        'summary' => ['tipe' => $tipe, 'periode' => $label, 'total_transaksi' => count($rows), 'total_jumlah' => array_sum(array_column($rows, 'jumlah'))],
    ];
}

function query_barang_keluar_periode(PDO $pdo, $bulan, $tahun, $tglMulai, $tglSelesai) {
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    if ($tglMulai && $tglSelesai) {
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe='keluar' AND t.tanggal BETWEEN ? AND ? ORDER BY t.tanggal DESC");
        $stmt->execute([$tglMulai, $tglSelesai]);
        $label = date('d M Y', strtotime($tglMulai)) . ' – ' . date('d M Y', strtotime($tglSelesai));
    } else {
        $tahun = $tahun ?: date('Y');
        $bulan = $bulan ?: (int)date('n');
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe='keluar' AND YEAR(t.tanggal)=? AND MONTH(t.tanggal)=? ORDER BY t.tanggal DESC");
        $stmt->execute([$tahun, $bulan]);
        $label = $namaBulan[(int)$bulan] . ' ' . $tahun;
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $agg = [];
    foreach ($rows as $r) $agg[$r['nama']] = ($agg[$r['nama']] ?? 0) + (int)$r['jumlah'];
    arsort($agg);
    $aggTop = array_slice($agg, 0, 15, true);

    return [
        'title' => 'Barang Keluar - ' . $label,
        'rows' => $rows,
        'chart' => $rows ? ['type' => 'bar', 'labels' => array_keys($aggTop), 'datasets' => [['label' => 'Jumlah Keluar', 'values' => array_values($aggTop)]]] : null,
        'summary' => ['periode' => $label, 'total_transaksi' => count($rows), 'total_barang_keluar' => array_sum(array_column($rows, 'jumlah')), 'top5' => array_slice($agg, 0, 5, true)],
    ];
}

function query_rekap_bidang(PDO $pdo, $bidang, $tahun) {
    $tahun = $tahun ?: date('Y');

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND YEAR(tanggal)=?");
    $stmt->execute([$tahun]);
    $totalMasukGudang = (int)$stmt->fetch()['c'];

    if ($bidang) {
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe='keluar' AND t.bidang LIKE ? AND YEAR(t.tanggal)=? ORDER BY t.tanggal DESC");
        $stmt->execute(['%' . $bidang . '%', $tahun]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalBidang = array_sum(array_column($rows, 'jumlah'));

        return [
            'title' => 'Pengambilan Bidang "' . $bidang . '" - ' . $tahun,
            'rows' => $rows, 'chart' => null,
            'summary' => ['bidang' => $bidang, 'tahun' => $tahun, 'total_transaksi' => count($rows), 'total_barang_diambil' => $totalBidang, 'total_masuk_gudang_tahun_ini' => $totalMasukGudang],
        ];
    }

    $stmt = $pdo->prepare("SELECT bidang, COUNT(*) jumlah_transaksi, COALESCE(SUM(jumlah),0) total_barang
        FROM transactions WHERE tipe='keluar' AND bidang IS NOT NULL AND bidang<>'' AND YEAR(tanggal)=?
        GROUP BY bidang ORDER BY total_barang DESC");
    $stmt->execute([$tahun]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'title' => 'Rekap Pengambilan per Bidang - ' . $tahun,
        'rows' => $rows,
        'chart' => $rows ? ['type' => 'bar', 'labels' => array_column($rows, 'bidang'), 'datasets' => [['label' => 'Total Diambil', 'values' => array_map('intval', array_column($rows, 'total_barang'))]]] : null,
        'summary' => ['tahun' => $tahun, 'jumlah_bidang_aktif' => count($rows), 'total_masuk_gudang' => $totalMasukGudang, 'per_bidang' => $rows],
    ];
}

function query_stok_barang(PDO $pdo, $namaBarang) {
    if ($namaBarang) {
        $stmt = $pdo->prepare('SELECT kode, nama, satuan, stok, stok_minimum FROM items WHERE nama LIKE ? OR kode LIKE ? ORDER BY nama LIMIT 15');
        $like = '%' . $namaBarang . '%';
        $stmt->execute([$like, $like]);
        $title = 'Stok Barang: ' . $namaBarang;
    } else {
        $stmt = $pdo->query('SELECT kode, nama, satuan, stok, stok_minimum FROM items ORDER BY stok ASC');
        $title = 'Sisa Stok Semua Barang';
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $top = array_slice($rows, 0, 15);

    return [
        'title' => $title, 'rows' => $rows,
        'chart' => $rows ? ['type' => 'bar', 'labels' => array_column($top, 'nama'), 'datasets' => [['label' => 'Sisa Stok', 'values' => array_map('intval', array_column($top, 'stok'))]]] : null,
        'summary' => ['jumlah_jenis_barang' => count($rows)],
    ];
}

function query_rekap_lengkap(PDO $pdo, $bulan, $tahun) {
    $tahun = $tahun ?: date('Y');
    $bulan = $bulan ?: (int)date('n');
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND YEAR(tanggal)=? AND MONTH(tanggal)=?");
    $stmt->execute([$tahun, $bulan]);
    $totalMasuk = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='keluar' AND YEAR(tanggal)=? AND MONTH(tanggal)=?");
    $stmt->execute([$tahun, $bulan]);
    $totalKeluar = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, t.tipe, t.jumlah, i.satuan, t.bidang, t.penerima
        FROM transactions t JOIN items i ON i.id = t.item_id
        WHERE YEAR(t.tanggal)=? AND MONTH(t.tanggal)=? ORDER BY t.tanggal DESC");
    $stmt->execute([$tahun, $bulan]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $menipis = $pdo->query('SELECT kode, nama, satuan, stok, stok_minimum FROM items WHERE stok_minimum>0 AND stok<=stok_minimum ORDER BY stok ASC')->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT bidang, COALESCE(SUM(jumlah),0) total FROM transactions
        WHERE tipe='keluar' AND bidang IS NOT NULL AND bidang<>'' AND YEAR(tanggal)=? AND MONTH(tanggal)=?
        GROUP BY bidang ORDER BY total DESC");
    $stmt->execute([$tahun, $bulan]);
    $perBidang = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $label = $namaBulan[(int)$bulan] . ' ' . $tahun;

    return [
        'title' => 'Rekap Lengkap ' . $label,
        'rows' => $rows,
        'chart' => ['type' => 'bar', 'labels' => ['Masuk', 'Keluar'], 'datasets' => [['label' => 'Total', 'values' => [$totalMasuk, $totalKeluar]]]],
        'summary' => ['periode' => $label, 'total_masuk' => $totalMasuk, 'total_keluar' => $totalKeluar, 'jumlah_barang_stok_menipis' => count($menipis), 'jumlah_bidang_aktif' => count($perBidang)],
        'extra_tables' => ['Stok Menipis (' . $label . ')' => $menipis, 'Per Bidang (' . $label . ')' => $perBidang],
    ];
}

// ============================================================
// Endpoint: hapus seluruh riwayat percakapan milik user yang login
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'hapus_riwayat') {
    csrf_check();
    header('Content-Type: application/json');

    $pdo->prepare('DELETE FROM ai_chat_history WHERE user_id = ?')->execute([$u['id']]);
    unset($_SESSION['ai_last_result']);

    echo json_encode(['ok' => true]);
    exit;
}

// ============================================================
// Endpoint AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ask') {
    csrf_check();
    header('Content-Type: application/json');

    if (!ai_rate_limit_check(8)) {
        echo json_encode(['error' => 'Terlalu banyak pertanyaan dalam waktu singkat. Tunggu sebentar lalu coba lagi.']);
        exit;
    }

    $question = trim($_POST['question'] ?? '');
    if ($question === '') { echo json_encode(['error' => 'Pertanyaan tidak boleh kosong.']); exit; }
    if (mb_strlen($question) > 300) { echo json_encode(['error' => 'Pertanyaan terlalu panjang (maksimal 300 karakter).']); exit; }

    // Pilihan model dari dropdown — divalidasi terhadap whitelist GEMINI_MODELS di
    // ai_helper.php, TIDAK PERNAH dipakai mentah untuk membangun URL API.
    $modelKey = $_POST['model'] ?? GEMINI_MODEL_DEFAULT;
    if (!array_key_exists($modelKey, GEMINI_MODELS)) {
        $modelKey = GEMINI_MODEL_DEFAULT;
    }

    $daftarBarang = $pdo->query('SELECT nama FROM items')->fetchAll(PDO::FETCH_COLUMN);
    $klasifikasi = ai_classify_intent($question, $daftarBarang, $modelKey);
    $intents = $klasifikasi['intents'] ?? [['intent' => 'tidak_dikenali']];
    $apiError = $klasifikasi['error'] ?? null;

    $blocks = [];
    $summaryForAI = [];

    if (!$apiError) {
        foreach ($intents as $it) {
            $intentName = $it['intent'] ?? 'tidak_dikenali';
            $block = null;
            switch ($intentName) {
                case 'rekap_stok_menipis':
                    $block = query_rekap_stok_menipis($pdo);
                    break;
                case 'cek_ketersediaan':
                    $block = query_cek_ketersediaan($pdo, $it['nama_barang'] ?? $question);
                    break;
                case 'ringkasan_laporan':
                    $block = query_ringkasan_laporan($pdo);
                    break;
                case 'grafik_transaksi':
                    $block = query_grafik_transaksi($pdo, $it['jumlah_bulan'] ?? 6);
                    break;
                case 'detail_transaksi':
                    $block = query_detail_transaksi($pdo, $it['tipe'] ?? 'keluar', $it['periode'] ?? 'hari_ini');
                    break;
                case 'barang_keluar_periode':
                    $block = query_barang_keluar_periode($pdo, $it['bulan'] ?? null, $it['tahun'] ?? null, $it['tanggal_mulai'] ?? null, $it['tanggal_selesai'] ?? null);
                    break;
                case 'rekap_bidang':
                    $block = query_rekap_bidang($pdo, $it['bidang'] ?? null, $it['tahun'] ?? null);
                    break;
                case 'stok_barang':
                    $block = query_stok_barang($pdo, $it['nama_barang'] ?? null);
                    break;
                case 'rekap_lengkap':
                    $block = query_rekap_lengkap($pdo, $it['bulan'] ?? null, $it['tahun'] ?? null);
                    break;
            }
            if ($block) {
                $block['intent'] = $intentName;
                $blocks[] = $block;
                $summaryForAI[$intentName] = $block['summary'] ?? null;
            }
        }
    }

    if ($apiError) {
        $jawaban = 'Asisten AI sedang bermasalah: ' . $apiError . ' Silakan coba lagi nanti atau hubungi admin.';
    } elseif (!$blocks) {
        $jawaban = 'Maaf, saya belum bisa membantu pertanyaan itu. Saya bisa bantu: cek stok menipis, cek ketersediaan barang, tren grafik transaksi, daftar barang masuk/keluar per bulan atau rentang tanggal, rekap per bidang, sisa stok barang, atau rekap lengkap bulanan.';
    } else {
        $jawaban = ai_generate_answer($summaryForAI, $modelKey);
    }

    $_SESSION['ai_last_result'] = ['question' => $question, 'blocks' => $blocks];

    ai_audit_log($u['username'] ?? 'unknown', $question, implode(',', array_column($intents, 'intent')), $jawaban);

    // Simpan ke riwayat percakapan (per user), supaya muncul lagi saat halaman dibuka ulang.
    $pdo->prepare('INSERT INTO ai_chat_history (user_id, pertanyaan, jawaban, blocks_json) VALUES (?, ?, ?, ?)')
        ->execute([$u['id'], $question, $jawaban, json_encode($blocks, JSON_UNESCAPED_UNICODE)]);

    $hasExportable = false;
    foreach ($blocks as $b) { if (!empty($b['rows'])) { $hasExportable = true; break; } }

    echo json_encode(['jawaban' => $jawaban, 'blocks' => $blocks, 'bisa_export' => $hasExportable]);
    exit;
}

// Muat riwayat percakapan sebelumnya (maksimal 50 terakhir), untuk ditampilkan
// lagi saat halaman dibuka ulang.
$riwayatRows = $pdo->prepare('SELECT pertanyaan, jawaban, blocks_json, created_at FROM ai_chat_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$riwayatRows->execute([$u['id']]);
$riwayat = array_reverse($riwayatRows->fetchAll(PDO::FETCH_ASSOC));
$riwayatUntukJS = array_map(function ($r) {
    return [
        'pertanyaan' => $r['pertanyaan'],
        'jawaban' => $r['jawaban'],
        'blocks' => json_decode($r['blocks_json'] ?? '[]', true) ?: [],
    ];
}, $riwayat);

require __DIR__ . '/includes/header.php';
?>
<style>
  .ai-page{max-width:880px;margin:0 auto;}

  .ai-hero{display:flex;align-items:center;gap:14px;background:linear-gradient(135deg, var(--c-purple-bg), var(--c-blue-bg));
    border:1px solid var(--line);border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:18px;}
  .ai-hero-ic{width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,var(--c-purple),var(--accent));
    display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;box-shadow:0 8px 20px -6px rgba(167,139,250,.5);}
  .ai-hero-text{flex:1;}
  .ai-hero-text h2{margin:0;font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:700;}
  .ai-hero-text p{margin:2px 0 0;font-size:12.5px;color:var(--text-dim);}
  .ai-clear-btn{flex-shrink:0;font-size:11.5px;padding:8px 12px;white-space:nowrap;}

  .ai-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px;}
  .ai-chip{display:inline-flex;align-items:center;gap:6px;background:var(--paper-card);border:1px solid var(--line);
    border-radius:999px;padding:7px 13px 7px 10px;font-size:12px;color:var(--text-dim);cursor:pointer;
    transition:border-color .15s var(--ease), color .15s var(--ease), transform .1s var(--ease);}
  .ai-chip:hover{border-color:var(--accent);color:var(--text);transform:translateY(-1px);}
  .ai-chip svg{flex-shrink:0;opacity:.6;width:13px;height:13px;}

  .ai-panel{background:var(--paper-card);border:1px solid var(--line);border-radius:var(--radius-lg);
    box-shadow:var(--shadow-card);overflow:hidden;display:flex;flex-direction:column;}
  .ai-chat{display:flex;flex-direction:column;gap:16px;min-height:340px;max-height:600px;overflow-y:auto;padding:22px;}

  .ai-row{display:flex;gap:10px;align-items:flex-start;}
  .ai-row.user{flex-direction:row-reverse;}
  .ai-avatar{width:30px;height:30px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
    font-size:11px;font-weight:700;}
  .ai-avatar.bot{background:linear-gradient(135deg,var(--c-purple),var(--accent));color:#fff;}
  .ai-avatar.user{background:var(--paper-sunk);border:1px solid var(--line);color:var(--text-dim);}

  .ai-msg{max-width:calc(100% - 44px);padding:11px 15px;border-radius:14px;font-size:13.5px;line-height:1.6;}
  .ai-msg.user{background:var(--accent);color:#fff;border-bottom-right-radius:4px;}
  .ai-msg.bot{background:var(--paper-sunk);border:1px solid var(--line);color:var(--text);border-bottom-left-radius:4px;
    max-width:calc(100% - 44px);}

  .ai-typing-dots{display:inline-flex;gap:3px;align-items:center;padding:2px 0;}
  .ai-typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--text-dim);animation:aiDotBounce 1.2s infinite ease-in-out;}
  .ai-typing-dots span:nth-child(2){animation-delay:.15s;}
  .ai-typing-dots span:nth-child(3){animation-delay:.3s;}
  @keyframes aiDotBounce{0%,60%,100%{transform:translateY(0);opacity:.5;}30%{transform:translateY(-4px);opacity:1;}}

  .ai-block{border-top:1px solid var(--line-soft);margin-top:12px;padding-top:12px;}
  .ai-block:first-of-type{border-top:none;margin-top:8px;padding-top:0;}
  .ai-block-head{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
  .ai-block-dot{width:6px;height:6px;border-radius:50%;background:var(--accent);flex-shrink:0;}
  .ai-block-title{font-weight:700;font-size:12.5px;color:var(--text);}

  .ai-chart-card{position:relative;height:230px;margin-bottom:12px;background:var(--paper-card);
    border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px;}

  .ai-table-wrap{border:1px solid var(--line);border-radius:var(--radius-sm);overflow:hidden;}
  .ai-table-wrap table{width:100%;font-size:11.5px;border-collapse:collapse;}
  .ai-table-wrap th{background:var(--paper-sunk);color:var(--text-dim);font-weight:700;text-transform:uppercase;
    letter-spacing:.03em;font-size:9.5px;padding:8px 10px;text-align:left;border-bottom:1px solid var(--line);}
  .ai-table-wrap td{padding:7px 10px;border-bottom:1px solid var(--line-soft);color:var(--text);}
  .ai-table-wrap tr:last-child td{border-bottom:none;}
  .ai-table-wrap tr:hover td{background:var(--paper-sunk);}

  .ai-block-more{font-size:11px;color:var(--text-faint);margin-top:7px;font-style:italic;}
  .ai-block-empty{font-size:12px;color:var(--text-dim);font-style:italic;padding:8px 0;}

  .ai-export-btn{margin-top:12px;font-size:12px;padding:8px 14px;}

  .ai-composer{border-top:1px solid var(--line);padding:16px 20px;background:var(--paper-card);}
  .ai-model-row{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
  .ai-model-row label{font-size:11px;color:var(--text-dim);}
  .ai-model-row select{padding:6px 12px;border:1px solid var(--line);border-radius:999px;
    background:var(--paper-sunk);color:var(--text);font-size:11.5px;font-family:'Inter',Arial,sans-serif;cursor:pointer;}
  .ai-model-row select:focus{outline:none;border-color:var(--accent);}
  .ai-form{display:flex;gap:10px;align-items:center;}
  .ai-input-wrap{flex:1;position:relative;display:flex;align-items:center;}
  .ai-input-wrap svg{position:absolute;left:14px;opacity:.45;pointer-events:none;}
  .ai-form input{width:100%;padding:12px 14px 12px 40px;border:1.5px solid var(--line);border-radius:999px;
    background:var(--paper-sunk);color:var(--text);font-family:'Inter',Arial,sans-serif;font-size:13.5px;
    transition:border-color .15s var(--ease), box-shadow .15s var(--ease);}
  .ai-form input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.16);}
  .ai-send-btn{width:44px;height:44px;border-radius:50%;padding:0;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
  .ai-send-btn:disabled{opacity:.5;cursor:not-allowed;}
  .ai-limit-note{font-size:10.5px;color:var(--text-faint);text-align:right;margin-top:6px;}
</style>

<div class="topline">
  <div><h1>Asisten AI</h1><div class="sub">Tanya soal stok barang, ketersediaan, rekap bidang, atau laporan</div></div>
</div>

<div class="ai-page">
  <div class="ai-hero">
    <div class="ai-hero-ic"><?= icon('chat', 22) ?></div>
    <div class="ai-hero-text">
      <h2>Halo, saya asisten inventaris ATK Anda</h2>
      <p>Saya bisa cek stok, ketersediaan barang, rekap per bidang, tren transaksi, hingga rekap bulanan lengkap dengan grafik.</p>
    </div>
    <button type="button" class="btn btn-ghost ai-clear-btn" id="aiClearBtn" title="Hapus semua riwayat percakapan Anda">Hapus Percakapan</button>
  </div>

  <div class="ai-chips">
    <span class="ai-chip"><?= icon('bell', 13) ?>barang apa aja keluar bulan Agustus, kasih grafiknya</span>
    <span class="ai-chip"><?= icon('box', 13) ?>tampilkan sisa stok semua barang beserta grafiknya</span>
    <span class="ai-chip"><?= icon('clipboard', 13) ?>buatkan rekap lengkap bulan ini</span>
    <span class="ai-chip"><?= icon('search', 13) ?>ada bolpoin gak?</span>
  </div>

  <div class="ai-panel">
    <div class="ai-chat" id="aiChat">
      <div class="ai-row bot">
        <div class="ai-avatar bot"><?= icon('chat', 15) ?></div>
        <div class="ai-msg bot">Halo! Saya bisa bantu cek stok, ketersediaan barang, rekap per bidang, tren/grafik transaksi, atau rekap lengkap bulanan — lengkap dengan grafik dan bisa diunduh ke Excel. Mau tanya apa?</div>
      </div>
    </div>

    <div class="ai-composer">
      <div class="ai-model-row">
        <label for="aiModel">Model AI:</label>
        <select id="aiModel">
          <option value="cepat">Cepat (default)</option>
          <option value="presisi">Presisi (lebih teliti, lebih lambat)</option>
          <option value="hemat">Hemat (paling ringan)</option>
        </select>
      </div>
      <form class="ai-form" id="aiForm">
        <input type="hidden" id="csrfToken" value="<?= e(csrf_token()) ?>">
        <div class="ai-input-wrap">
          <?= icon('search', 15) ?>
          <input type="text" id="aiInput" placeholder="Tulis pertanyaan Anda..." required autocomplete="off" maxlength="300">
        </div>
        <button class="btn btn-primary ai-send-btn" type="submit" id="aiSubmitBtn" title="Kirim">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"></path><path d="M22 2l-7 20-4-9-9-4 20-7z"></path></svg>
        </button>
      </form>
      <div class="ai-limit-note">Maks. 8 pertanyaan per menit</div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const AI_HISTORY = <?= json_encode($riwayatUntukJS, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

const chat = document.getElementById('aiChat');
const form = document.getElementById('aiForm');
const input = document.getElementById('aiInput');
const modelSelect = document.getElementById('aiModel');
const submitBtn = document.getElementById('aiSubmitBtn');
const clearBtn = document.getElementById('aiClearBtn');
const csrf = document.getElementById('csrfToken').value;
let isSending = false;
let chartCounter = 0;

var cs = getComputedStyle(document.documentElement);
var colAccent = cs.getPropertyValue('--accent').trim() || '#3B82F6';
var colTeal = cs.getPropertyValue('--teal').trim() || '#2DD4BF';
var colOchre = cs.getPropertyValue('--ochre').trim() || '#F59E0B';
var colTextDim = cs.getPropertyValue('--text-dim').trim() || '#8B93AC';
var colLine = cs.getPropertyValue('--line-soft').trim() || '#1B2238';
if (window.Chart) {
  Chart.defaults.font.family = "'Inter', Arial, sans-serif";
  Chart.defaults.color = colTextDim;
  Chart.defaults.font.size = 11;
}

function addRow(role) {
  const row = document.createElement('div');
  row.className = 'ai-row ' + role;

  const avatar = document.createElement('div');
  avatar.className = 'ai-avatar ' + role;
  avatar.innerHTML = role === 'user' ? 'Kamu'.slice(0, 2).toUpperCase() : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><path d="M8 10h.01M12 10h.01M16 10h.01"></path></svg>';

  const msg = document.createElement('div');
  msg.className = 'ai-msg ' + role;

  row.appendChild(avatar);
  row.appendChild(msg);
  chat.appendChild(row);
  chat.scrollTop = chat.scrollHeight;
  return msg;
}

function setTyping(el) {
  el.innerHTML = '<span class="ai-typing-dots"><span></span><span></span><span></span></span>';
}

function renderTable(rows, capAt) {
  if (!rows || !rows.length) return document.createDocumentFragment();
  const cols = Object.keys(rows[0]);
  const shown = capAt ? rows.slice(0, capAt) : rows;

  const wrapDiv = document.createElement('div');
  wrapDiv.className = 'ai-table-wrap';

  const table = document.createElement('table');
  const thead = document.createElement('thead');
  const trh = document.createElement('tr');
  cols.forEach(c => { const th = document.createElement('th'); th.textContent = c.replace(/_/g, ' '); trh.appendChild(th); });
  thead.appendChild(trh);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  shown.forEach(row => {
    const tr = document.createElement('tr');
    cols.forEach(c => { const td = document.createElement('td'); td.textContent = row[c] ?? '-'; tr.appendChild(td); });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrapDiv.appendChild(table);

  const wrap = document.createDocumentFragment();
  wrap.appendChild(wrapDiv);
  if (capAt && rows.length > capAt) {
    const more = document.createElement('div');
    more.className = 'ai-block-more';
    more.textContent = '+' + (rows.length - capAt) + ' baris lainnya — unduh Excel untuk lihat semua.';
    wrap.appendChild(more);
  }
  return wrap;
}

function renderChart(chartData) {
  chartCounter++;
  const card = document.createElement('div');
  card.className = 'ai-chart-card';
  const canvas = document.createElement('canvas');
  canvas.id = 'aiChart' + chartCounter;
  card.appendChild(canvas);

  const palette = [colTeal, colOchre, colAccent];
  const datasets = (chartData.datasets || []).map((ds, i) => {
    const color = palette[i % palette.length];
    return {
      label: ds.label,
      data: ds.values,
      backgroundColor: chartData.type === 'line' ? color + '33' : color,
      borderColor: color,
      borderRadius: chartData.type === 'bar' ? 6 : 0,
      fill: chartData.type === 'line',
      tension: 0.35,
      pointRadius: chartData.type === 'line' ? 3 : 0,
    };
  });

  setTimeout(function () {
    new Chart(canvas, {
      type: chartData.type || 'bar',
      data: { labels: chartData.labels, datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: datasets.length > 1, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } } },
        scales: { x: { grid: { color: colLine } }, y: { beginAtZero: true, grid: { color: colLine } } },
      },
    });
  }, 0);

  return card;
}

function renderBlocks(container, blocks) {
  blocks.forEach(function (b) {
    const blockDiv = document.createElement('div');
    blockDiv.className = 'ai-block';

    const head = document.createElement('div');
    head.className = 'ai-block-head';
    const dot = document.createElement('span');
    dot.className = 'ai-block-dot';
    const title = document.createElement('span');
    title.className = 'ai-block-title';
    title.textContent = b.title || '';
    head.appendChild(dot);
    head.appendChild(title);
    blockDiv.appendChild(head);

    if (b.chart && b.chart.labels && b.chart.labels.length) {
      blockDiv.appendChild(renderChart(b.chart));
    }

    if (b.rows && b.rows.length) {
      blockDiv.appendChild(renderTable(b.rows, 30));
    } else {
      const empty = document.createElement('div');
      empty.className = 'ai-block-empty';
      empty.textContent = 'Tidak ada data untuk bagian ini.';
      blockDiv.appendChild(empty);
    }

    container.appendChild(blockDiv);
  });
}

// ============================================================
// Muat ulang riwayat percakapan sebelumnya (kalau ada) saat halaman dibuka.
// Kalau belum ada riwayat, biarkan pesan sapaan default dari server yang tampil.
// ============================================================
if (AI_HISTORY.length) {
  chat.innerHTML = ''; // kosongkan pesan sapaan default
  AI_HISTORY.forEach(function (h) {
    const userMsg = addRow('user');
    userMsg.textContent = h.pertanyaan;

    const botMsg = addRow('bot');
    const p = document.createElement('div');
    p.textContent = h.jawaban;
    botMsg.appendChild(p);

    if (h.blocks && h.blocks.length) {
      renderBlocks(botMsg, h.blocks);
    }
  });
  chat.scrollTop = chat.scrollHeight;
}

clearBtn.addEventListener('click', async () => {
  if (!confirm('Hapus semua riwayat percakapan Anda dengan Asisten AI? Tindakan ini tidak bisa dibatalkan.')) return;

  clearBtn.disabled = true;
  clearBtn.textContent = 'Menghapus...';

  try {
    const res = await fetch('asisten_ai.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ do: 'hapus_riwayat', csrf }),
    });
    const result = await res.json();

    if (result.ok) {
      chat.innerHTML = '';
      const row = addRow('bot');
      row.textContent = 'Riwayat percakapan sudah dihapus. Mau tanya apa?';
    } else {
      alert('Gagal menghapus riwayat. Coba lagi.');
    }
  } catch (err) {
    alert('Gagal menghubungi server. Coba lagi.');
  } finally {
    clearBtn.disabled = false;
    clearBtn.textContent = 'Hapus Percakapan';
  }
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (isSending) return;

  const question = input.value.trim();
  if (!question) return;

  isSending = true;
  submitBtn.disabled = true;

  const userMsg = addRow('user');
  userMsg.textContent = question;
  input.value = '';

  const botMsg = addRow('bot');
  setTyping(botMsg);

  try {
    const res = await fetch('asisten_ai.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ do: 'ask', csrf, question, model: modelSelect.value }),
    });
    const result = await res.json();

    botMsg.innerHTML = '';
    const p = document.createElement('div');
    p.textContent = result.jawaban || result.error || 'Terjadi kesalahan.';
    botMsg.appendChild(p);

    if (result.blocks && result.blocks.length) {
      renderBlocks(botMsg, result.blocks);
    }

    if (result.bisa_export) {
      const a = document.createElement('a');
      a.className = 'btn btn-ghost ai-export-btn';
      a.href = 'export_ai_hasil.php';
      a.target = '_blank';
      a.textContent = 'Export ke Excel';
      botMsg.appendChild(a);
    }
    chat.scrollTop = chat.scrollHeight;
  } catch (err) {
    botMsg.textContent = 'Gagal menghubungi server. Coba lagi.';
  } finally {
    isSending = false;
    submitBtn.disabled = false;
    input.focus();
  }
});

document.querySelectorAll('.ai-chip').forEach(function (chip) {
  chip.addEventListener('click', function () {
    input.value = chip.textContent.trim();
    input.focus();
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>