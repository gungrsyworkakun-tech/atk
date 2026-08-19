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

/**
 * $scopeBidang: kalau diisi (user login dengan role 'bidang'), transaksi KELUAR
 * dibatasi hanya ke bidang tersebut — user bidang tidak bisa melihat pengambilan
 * bidang lain lewat Asisten AI. Transaksi MASUK (stok gudang) tetap terlihat
 * karena bukan milik bidang tertentu.
 */
function query_detail_transaksi(PDO $pdo, $tipe, $periode, $scopeBidang = null) {
    $tipe = in_array($tipe, ['masuk', 'keluar'], true) ? $tipe : 'keluar';
    $filterBidang = ($tipe === 'keluar' && $scopeBidang) ? ' AND t.bidang = ?' : '';

    if ($periode === 'bulan_ini') {
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe = ? AND YEAR(t.tanggal)=? AND MONTH(t.tanggal)=?" . $filterBidang . " ORDER BY t.tanggal DESC");
        $params = [$tipe, date('Y'), date('n')];
        if ($filterBidang) $params[] = $scopeBidang;
        $stmt->execute($params);
        $label = 'Bulan Ini';
    } else {
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe = ? AND t.tanggal = CURDATE()" . $filterBidang . " ORDER BY t.id DESC");
        $params = [$tipe];
        if ($filterBidang) $params[] = $scopeBidang;
        $stmt->execute($params);
        $label = 'Hari Ini';
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'title' => 'Barang ' . ucfirst($tipe) . ' - ' . $label,
        'rows' => $rows, 'chart' => null,
        'summary' => ['tipe' => $tipe, 'periode' => $label, 'total_transaksi' => count($rows), 'total_jumlah' => array_sum(array_column($rows, 'jumlah'))],
    ];
}

function query_barang_keluar_periode(PDO $pdo, $bulan, $tahun, $tglMulai, $tglSelesai, $scopeBidang = null) {
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $filterBidang = $scopeBidang ? ' AND t.bidang = ?' : '';

    if ($tglMulai && $tglSelesai) {
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe='keluar' AND t.tanggal BETWEEN ? AND ?" . $filterBidang . " ORDER BY t.tanggal DESC");
        $params = [$tglMulai, $tglSelesai];
        if ($filterBidang) $params[] = $scopeBidang;
        $stmt->execute($params);
        $label = date('d M Y', strtotime($tglMulai)) . ' – ' . date('d M Y', strtotime($tglSelesai));
    } else {
        $tahun = $tahun ?: date('Y');
        $bulan = $bulan ?: (int)date('n');
        $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, i.satuan, t.jumlah, t.bidang, t.penerima
            FROM transactions t JOIN items i ON i.id = t.item_id
            WHERE t.tipe='keluar' AND YEAR(t.tanggal)=? AND MONTH(t.tanggal)=?" . $filterBidang . " ORDER BY t.tanggal DESC");
        $params = [$tahun, $bulan];
        if ($filterBidang) $params[] = $scopeBidang;
        $stmt->execute($params);
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

/**
 * $scopeBidang: kalau diisi, parameter $bidang dari AI DIABAIKAN dan dipaksa
 * jadi bidang milik user login — supaya petugas bidang tidak bisa "menyamar"
 * minta rekap bidang lain lewat pertanyaan ke Asisten AI.
 */
function query_rekap_bidang(PDO $pdo, $bidang, $tahun, $scopeBidang = null) {
    $tahun = $tahun ?: date('Y');
    if ($scopeBidang) $bidang = $scopeBidang;

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

/**
 * $scopeBidang: kalau diisi, baris transaksi keluar & rekap per bidang dibatasi
 * ke bidang user login saja. total_masuk (stok gudang) tetap ditampilkan apa
 * adanya karena tidak melekat ke bidang tertentu.
 */
function query_rekap_lengkap(PDO $pdo, $bulan, $tahun, $scopeBidang = null) {
    $tahun = $tahun ?: date('Y');
    $bulan = $bulan ?: (int)date('n');
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $filterBidang = $scopeBidang ? ' AND bidang = ?' : '';

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='masuk' AND YEAR(tanggal)=? AND MONTH(tanggal)=?");
    $stmt->execute([$tahun, $bulan]);
    $totalMasuk = (int)$stmt->fetch()['c'];

    $paramsKeluar = [$tahun, $bulan]; if ($scopeBidang) $paramsKeluar[] = $scopeBidang;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(jumlah),0) c FROM transactions WHERE tipe='keluar' AND YEAR(tanggal)=? AND MONTH(tanggal)=?" . $filterBidang);
    $stmt->execute($paramsKeluar);
    $totalKeluar = (int)$stmt->fetch()['c'];

    // Untuk baris gabungan masuk+keluar: kalau ter-scope, masuk tetap ditampilkan
    // (tidak melekat ke bidang), tapi keluar dibatasi ke bidang user.
    $filterRows = $scopeBidang ? " AND (t.tipe='masuk' OR t.bidang = ?)" : '';
    $paramsRows = [$tahun, $bulan]; if ($scopeBidang) $paramsRows[] = $scopeBidang;
    $stmt = $pdo->prepare("SELECT t.tanggal, i.nama, i.kode, t.tipe, t.jumlah, i.satuan, t.bidang, t.penerima
        FROM transactions t JOIN items i ON i.id = t.item_id
        WHERE YEAR(t.tanggal)=? AND MONTH(t.tanggal)=?" . $filterRows . " ORDER BY t.tanggal DESC");
    $stmt->execute($paramsRows);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $menipis = $pdo->query('SELECT kode, nama, satuan, stok, stok_minimum FROM items WHERE stok_minimum>0 AND stok<=stok_minimum ORDER BY stok ASC')->fetchAll(PDO::FETCH_ASSOC);

    $paramsBidang = [$tahun, $bulan]; if ($scopeBidang) $paramsBidang[] = $scopeBidang;
    $stmt = $pdo->prepare("SELECT bidang, COALESCE(SUM(jumlah),0) total FROM transactions
        WHERE tipe='keluar' AND bidang IS NOT NULL AND bidang<>'' AND YEAR(tanggal)=? AND MONTH(tanggal)=?" . $filterBidang . "
        GROUP BY bidang ORDER BY total DESC");
    $stmt->execute($paramsBidang);
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

/**
 * Riwayat siapa menambah/mengubah/menghapus data barang, dan apa yang berubah.
 * Sumber: tabel items_log (diisi otomatis oleh data_barang.php setiap kali disimpan).
 */
function query_riwayat_perubahan_barang(PDO $pdo, $namaBarang = null, $jumlahHari = 7) {
    $jumlahHari = max(1, min(365, (int)($jumlahHari ?: 7)));
    $sejakTanggal = date('Y-m-d', strtotime("-{$jumlahHari} day"));

    if ($namaBarang) {
        $stmt = $pdo->prepare(
            "SELECT created_at, aksi, kode_barang, nama_barang, username, perubahan
             FROM items_log
             WHERE created_at >= ? AND nama_barang LIKE ?
             ORDER BY created_at DESC LIMIT 100"
        );
        $stmt->execute([$sejakTanggal, '%' . $namaBarang . '%']);
    } else {
        $stmt = $pdo->prepare(
            "SELECT created_at, aksi, kode_barang, nama_barang, username, perubahan
             FROM items_log
             WHERE created_at >= ?
             ORDER BY created_at DESC LIMIT 100"
        );
        $stmt->execute([$sejakTanggal]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hitung siapa yang paling aktif mengubah data, buat ringkasan ke AI.
    $perUser = [];
    foreach ($rows as $r) {
        $uname = $r['username'] ?: 'tidak diketahui';
        $perUser[$uname] = ($perUser[$uname] ?? 0) + 1;
    }
    arsort($perUser);

    $label = $namaBarang
        ? "Riwayat Perubahan \"{$namaBarang}\" ({$jumlahHari} hari terakhir)"
        : "Riwayat Perubahan Data Barang ({$jumlahHari} hari terakhir)";

    return [
        'title' => $label,
        'rows' => $rows,
        'chart' => null,
        'summary' => [
            'periode_hari' => $jumlahHari,
            'jumlah_catatan' => count($rows),
            'per_user' => $perUser ?: null,
        ],
    ];
}

/**
 * ============================================================
 * KONTROL AKSES BERBASIS PERAN/BIDANG
 * ============================================================
 * Petugas dengan role 'bidang' hanya boleh melihat data transaksi milik
 * bidangnya sendiri lewat Asisten AI (data stok/barang tetap bisa dilihat
 * semua orang karena itu info gudang bersama, bukan data sensitif per-bidang).
 * role 'admin'/'super' tidak dibatasi.
 *
 * CATATAN: fungsi ini mengasumsikan current_user() mengembalikan array
 * dengan key 'role' dan 'bidang_nama' sesuai struktur tabel `users` di
 * database. Kalau nama key berbeda di implementasi Anda, sesuaikan di sini.
 */
function ai_get_scope(array $u) {
    $role = $u['role'] ?? 'admin';
    $bidangNama = $u['bidang_nama'] ?? null;
    return [
        'role' => $role,
        'dibatasi_bidang' => ($role === 'bidang' && $bidangNama) ? $bidangNama : null,
    ];
}

/**
 * ============================================================
 * INTENT "prediksi_stok_habis" — peringatan proaktif berbasis tren.
 * ============================================================
 * Hitung rata-rata pemakaian (barang KELUAR) per hari selama $periodeHari hari
 * terakhir, lalu proyeksikan berapa hari lagi stok akan habis di kecepatan
 * pemakaian tersebut. Barang tanpa histori keluar (rata2 = 0) tidak bisa
 * diprediksi dan ditandai null, bukan dianggap "aman selamanya".
 */
function query_prediksi_stok_habis(PDO $pdo, $namaBarang = null, $periodeHari = 90) {
    $periodeHari = max(7, min(365, (int)($periodeHari ?: 90)));
    $sejak = date('Y-m-d', strtotime("-{$periodeHari} day"));

    $sql = "SELECT i.id, i.kode, i.nama, i.satuan, i.stok, i.stok_minimum,
            COALESCE(SUM(CASE WHEN t.tipe='keluar' AND t.tanggal >= ? THEN t.jumlah ELSE 0 END),0) AS total_keluar_periode
        FROM items i
        LEFT JOIN transactions t ON t.item_id = i.id
        WHERE 1=1";
    $params = [$sejak];
    if ($namaBarang) {
        $sql .= ' AND (i.nama LIKE ? OR i.kode LIKE ?)';
        $like = '%' . $namaBarang . '%';
        $params[] = $like; $params[] = $like;
    }
    $sql .= ' GROUP BY i.id, i.kode, i.nama, i.satuan, i.stok, i.stok_minimum';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasil = [];
    foreach ($items as $it) {
        $rataRataPerHari = $periodeHari > 0 ? ((float)$it['total_keluar_periode'] / $periodeHari) : 0;
        $prediksiHari = null;
        $prediksiTanggal = null;
        if ($rataRataPerHari > 0) {
            $prediksiHari = (int) floor((int)$it['stok'] / $rataRataPerHari);
            $prediksiTanggal = date('Y-m-d', strtotime("+{$prediksiHari} day"));
        }
        $hasil[] = [
            'kode' => $it['kode'], 'nama' => $it['nama'], 'satuan' => $it['satuan'],
            'stok' => (int)$it['stok'], 'stok_minimum' => (int)$it['stok_minimum'],
            'rata2_keluar_per_hari' => round($rataRataPerHari, 2),
            'perkiraan_habis_hari_lagi' => $prediksiHari,
            'perkiraan_tanggal_habis' => $prediksiTanggal,
        ];
    }

    // Urutkan: yang paling cepat habis (bukan null) tampil paling atas.
    usort($hasil, function ($a, $b) {
        if ($a['perkiraan_habis_hari_lagi'] === null && $b['perkiraan_habis_hari_lagi'] === null) return 0;
        if ($a['perkiraan_habis_hari_lagi'] === null) return 1;
        if ($b['perkiraan_habis_hari_lagi'] === null) return -1;
        return $a['perkiraan_habis_hari_lagi'] <=> $b['perkiraan_habis_hari_lagi'];
    });

    $kritis = array_filter($hasil, function ($h) { return $h['perkiraan_habis_hari_lagi'] !== null && $h['perkiraan_habis_hari_lagi'] <= 30; });

    return [
        'title' => 'Prediksi Barang Akan Habis (tren ' . $periodeHari . ' hari terakhir)',
        'rows' => $hasil, 'chart' => null,
        'summary' => [
            'periode_analisis_hari' => $periodeHari,
            'jumlah_barang_dianalisis' => count($hasil),
            'jumlah_barang_kritis_30_hari' => count($kritis),
            'barang_paling_cepat_habis' => $hasil[0]['nama'] ?? null,
            'perkiraan_hari_barang_tercepat' => $hasil[0]['perkiraan_habis_hari_lagi'] ?? null,
        ],
    ];
}

/**
 * ============================================================
 * INTENT "analisis_data" — QUERY BUILDER AMAN (whitelist only).
 * ============================================================
 * AI (Lapisan 1) TIDAK PERNAH mengirim SQL. Ia hanya memilih nama field,
 * operator, dan fungsi agregasi dari daftar berikut. Semua nama field
 * divalidasi terhadap whitelist di bawah SEBELUM dipakai membangun query;
 * nilai filter selalu lewat parameter binding (PDO), tidak pernah
 * digabung langsung ke string SQL. Field/operator yang tidak dikenal
 * diabaikan secara diam-diam (bukan error) supaya tetap toleran terhadap
 * output AI yang sedikit meleset.
 */

const ANALISIS_SUMBER = [
    'transaksi' => [
        'from' => 'transactions t JOIN items i ON i.id = t.item_id',
        'fields' => [
            'tipe' => 't.tipe', 'bidang' => 't.bidang', 'penerima' => 't.penerima',
            'nama_barang' => 'i.nama', 'kode_barang' => 'i.kode', 'jenis_barang' => 'i.jenis',
            'jumlah' => 't.jumlah', 'tanggal' => 't.tanggal',
            'tahun' => 'YEAR(t.tanggal)', 'bulan' => 'MONTH(t.tanggal)',
        ],
        'numeric' => ['jumlah', 'tahun', 'bulan'],
        'kolom_tampil' => ['t.tanggal AS tanggal', 'i.nama AS nama', 'i.kode AS kode', 't.tipe AS tipe', 't.jumlah AS jumlah', 't.bidang AS bidang', 't.penerima AS penerima'],
    ],
    'barang' => [
        'from' => 'items',
        'fields' => [
            'nama' => 'nama', 'jenis' => 'jenis', 'satuan' => 'satuan',
            'stok' => 'stok', 'stok_minimum' => 'stok_minimum', 'harga' => 'harga', 'tahun_masuk' => 'tahun_masuk',
        ],
        'numeric' => ['stok', 'stok_minimum', 'harga', 'tahun_masuk'],
        'kolom_tampil' => ['kode', 'nama', 'jenis', 'satuan', 'stok', 'stok_minimum', 'harga'],
    ],
    'log_perubahan' => [
        'from' => 'items_log',
        'fields' => [
            'aksi' => 'aksi', 'username' => 'username', 'nama_barang' => 'nama_barang',
            'kode_barang' => 'kode_barang', 'tanggal' => 'created_at',
        ],
        'numeric' => [],
        'kolom_tampil' => ['created_at AS tanggal', 'aksi', 'kode_barang', 'nama_barang', 'username', 'perubahan'],
    ],
];

const ANALISIS_OPERATOR = ['=', '!=', '>', '<', '>=', '<=', 'like', 'between'];
const ANALISIS_AGREGASI_FUNGSI = ['count', 'sum', 'avg', 'min', 'max'];

/**
 * $scopeBidang: kalau diisi (user role 'bidang'), SETELAH filter dari AI dibangun,
 * kita SELALU menambahkan "AND t.bidang = ?" secara terpisah di level SQL (bukan
 * lewat spec AI) — supaya walau AI salah/dimanipulasi mengirim filter bidang lain,
 * hasil akhirnya tetap tidak bisa melebihi cakupan bidang user. Hanya berlaku untuk
 * sumber 'transaksi'; sumber 'barang' & 'log_perubahan' tidak melekat ke bidang.
 */
function query_analisis_data(PDO $pdo, array $spec, $scopeBidang = null) {
    $sumberKey = $spec['sumber'] ?? '';
    if (!isset(ANALISIS_SUMBER[$sumberKey])) {
        return ['title' => 'Analisis Data', 'rows' => [], 'chart' => null, 'summary' => ['error' => 'Sumber data tidak dikenali.']];
    }
    // log_perubahan (riwayat edit data barang oleh admin) bukan info milik bidang
    // tertentu -> sama seperti intent riwayat_perubahan_barang, ditutup untuk role 'bidang'.
    if ($scopeBidang && $sumberKey === 'log_perubahan') {
        return [
            'title' => 'Analisis Data (log_perubahan)', 'rows' => [], 'chart' => null,
            'summary' => ['akses' => 'dibatasi', 'keterangan' => 'Informasi ini hanya tersedia untuk admin/super admin.'],
        ];
    }
    $meta = ANALISIS_SUMBER[$sumberKey];
    $fieldMap = $meta['fields'];
    $params = [];
    $whereParts = [];
    $filterDiabaikan = 0;

    foreach (($spec['filter'] ?? []) as $f) {
        $field = $f['field'] ?? '';
        $operator = $f['operator'] ?? '=';
        if (!isset($fieldMap[$field]) || !in_array($operator, ANALISIS_OPERATOR, true)) {
            $filterDiabaikan++;
            continue;
        }
        $col = $fieldMap[$field];
        $nilai = $f['nilai'] ?? null;
        if ($nilai === null || $nilai === '') { $filterDiabaikan++; continue; }

        if ($operator === 'like') {
            $whereParts[] = "$col LIKE ?";
            $params[] = '%' . $nilai . '%';
        } elseif ($operator === 'between') {
            $nilai2 = $f['nilai2'] ?? null;
            if ($nilai2 === null || $nilai2 === '') { $filterDiabaikan++; continue; }
            $whereParts[] = "$col BETWEEN ? AND ?";
            $params[] = $nilai; $params[] = $nilai2;
        } else {
            $whereParts[] = "$col $operator ?";
            $params[] = $nilai;
        }
    }

    // GROUP BY (opsional)
    $groupByField = $spec['group_by'] ?? null;
    $groupByCol = ($groupByField && isset($fieldMap[$groupByField])) ? $fieldMap[$groupByField] : null;

    // AGREGASI (opsional)
    $agg = $spec['agregasi'] ?? null;
    $aggFungsi = null; $aggCol = null;
    if (is_array($agg) && isset($agg['fungsi']) && in_array($agg['fungsi'], ANALISIS_AGREGASI_FUNGSI, true)) {
        $aggFungsi = $agg['fungsi'];
        if ($aggFungsi === 'count') {
            $aggCol = '*'; // COUNT(*) selalu aman, tidak perlu field numerik tertentu
        } elseif (isset($agg['field']) && isset($fieldMap[$agg['field']]) && in_array($agg['field'], $meta['numeric'], true)) {
            $aggCol = $fieldMap[$agg['field']];
        } else {
            $aggFungsi = null; // field agregasi tidak valid/tidak numerik -> batalkan agregasi
        }
    }

    // Pemaksaan cakupan bidang (lihat docblock di atas) — SELALU ditambahkan
    // paling akhir, terlepas dari apa pun yang dikirim AI di $spec['filter'].
    if ($scopeBidang && $sumberKey === 'transaksi') {
        $whereParts[] = 't.bidang = ?';
        $params[] = $scopeBidang;
    }

    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
    $batas = max(1, min(100, (int)($spec['batas'] ?? 20)));

    // Mode 1: ada agregasi ATAU group_by -> query ringkasan/rekap
    if ($aggFungsi || $groupByCol) {
        $selectParts = [];
        if ($groupByCol) $selectParts[] = "$groupByCol AS grup";
        $selectParts[] = ($aggFungsi ? strtoupper($aggFungsi) . "($aggCol)" : 'COUNT(*)') . ' AS nilai_agregat';

        $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM ' . $meta['from'] . ' ' . $whereSql;
        if ($groupByCol) $sql .= " GROUP BY $groupByCol";

        // Urutkan: default berdasarkan nilai_agregat menurun, kecuali diminta lain.
        $urutkanArah = (strtolower($spec['urutkan_arah'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        $urutkanField = $spec['urutkan_field'] ?? null;
        if ($urutkanField === 'agregasi' || !$groupByCol) {
            $sql .= " ORDER BY nilai_agregat $urutkanArah";
        } elseif ($groupByCol) {
            $sql .= " ORDER BY nilai_agregat $urutkanArah";
        }
        $sql .= ' LIMIT ' . $batas;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $chart = null;
        if ($groupByCol && $rows) {
            $chart = ['type' => 'bar', 'labels' => array_column($rows, 'grup'), 'datasets' => [
                ['label' => strtoupper($aggFungsi ?: 'count'), 'values' => array_map('floatval', array_column($rows, 'nilai_agregat'))],
            ]];
        }

        return [
            'title' => 'Analisis Data (' . $sumberKey . ')',
            'rows' => $rows, 'chart' => $chart,
            'summary' => [
                'sumber' => $sumberKey, 'fungsi_agregasi' => $aggFungsi ?: 'count',
                'dikelompokkan_per' => $groupByField, 'jumlah_baris_hasil' => count($rows),
                'filter_diabaikan' => $filterDiabaikan ?: null,
            ],
        ];
    }

    // Mode 2: tanpa agregasi/group_by -> daftar baris mentah (list biasa)
    $orderCol = null;
    $urutkanField = $spec['urutkan_field'] ?? null;
    if ($urutkanField && isset($fieldMap[$urutkanField])) $orderCol = $fieldMap[$urutkanField];
    $urutkanArah = (strtolower($spec['urutkan_arah'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

    $sql = 'SELECT ' . implode(', ', $meta['kolom_tampil']) . ' FROM ' . $meta['from'] . ' ' . $whereSql;
    if ($orderCol) $sql .= " ORDER BY $orderCol $urutkanArah";
    $sql .= ' LIMIT ' . $batas;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'title' => 'Analisis Data (' . $sumberKey . ')',
        'rows' => $rows, 'chart' => null,
        'summary' => [
            'sumber' => $sumberKey, 'jumlah_baris_hasil' => count($rows),
            'filter_diabaikan' => $filterDiabaikan ?: null,
        ],
    ];
}

/**
 * ============================================================
 * CACHE JAWABAN SINGKAT (butuh tabel ai_answer_cache, lihat migrasi_fitur_baru.sql)
 * ============================================================
 * Supaya pertanyaan yang sama persis dalam rentang waktu dekat (mis. ditanya
 * dua kali oleh user berbeda) tidak perlu memanggil Gemini API ulang. Kunci
 * cache SELALU menyertakan cakupan bidang & konteks riwayat percakapan, supaya
 * cache TIDAK PERNAH membocorkan jawaban antar-cakupan akses atau konteks
 * yang berbeda — kalau salah satu bagian itu beda, otomatis cache miss (aman,
 * hanya mengorbankan sedikit hit-rate, bukan korbankan keamanan/akurasi).
 */
const AI_CACHE_TTL_DETIK = 90;

function ai_cache_build_key($question, $modelKey, $scopeBidang, array $riwayat) {
    $questionNorm = mb_strtolower(trim(preg_replace('/\s+/', ' ', $question)));
    $riwayatHash = md5(json_encode($riwayat, JSON_UNESCAPED_UNICODE));
    return md5($questionNorm . '|' . $modelKey . '|' . ($scopeBidang ?: 'ALL') . '|' . $riwayatHash);
}

function ai_cache_get(PDO $pdo, $cacheKey) {
    try {
        $stmt = $pdo->prepare("SELECT jawaban, blocks_json, bisa_export FROM ai_answer_cache
            WHERE cache_key = ? AND created_at >= (NOW() - INTERVAL " . AI_CACHE_TTL_DETIK . " SECOND)");
        $stmt->execute([$cacheKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'jawaban' => $row['jawaban'],
            'blocks' => json_decode($row['blocks_json'] ?? '[]', true) ?: [],
            'bisa_export' => (bool)$row['bisa_export'],
        ];
    } catch (Throwable $e) {
        // Tabel cache belum ada / migrasi belum dijalankan -> anggap saja cache miss,
        // jangan sampai bikin seluruh fitur tanya-jawab error karenanya.
        return null;
    }
}

function ai_cache_set(PDO $pdo, $cacheKey, $jawaban, array $blocks, $bisaExport) {
    try {
        $stmt = $pdo->prepare("INSERT INTO ai_answer_cache (cache_key, jawaban, blocks_json, bisa_export, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE jawaban=VALUES(jawaban), blocks_json=VALUES(blocks_json), bisa_export=VALUES(bisa_export), created_at=NOW()");
        $stmt->execute([$cacheKey, $jawaban, json_encode($blocks, JSON_UNESCAPED_UNICODE), $bisaExport ? 1 : 0]);
    } catch (Throwable $e) {
        // Diamkan — cache bersifat optimisasi, kegagalan menyimpan tidak boleh
        // menggagalkan jawaban yang sudah berhasil dibuat untuk pengguna.
    }
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

    // Kontrol akses berbasis peran: user dengan role 'bidang' hanya boleh melihat
    // data transaksi bidangnya sendiri lewat Asisten AI. null = tidak dibatasi
    // (role 'admin'/'super' tetap bisa lihat semua bidang seperti sebelumnya).
    $scopeBidang = (($u['role'] ?? '') === 'bidang') ? ($u['bidang_nama'] ?? null) : null;

    $daftarBarang = $pdo->query('SELECT nama FROM items')->fetchAll(PDO::FETCH_COLUMN);

    // Ambil 3 percakapan terakhir milik user ini sebagai konteks, supaya
    // pertanyaan singkat/lanjutan bisa dipahami Lapisan 1 (lihat ai_helper.php).
    $stmtRiwayat = $pdo->prepare('SELECT pertanyaan, jawaban FROM ai_chat_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 3');
    $stmtRiwayat->execute([$u['id']]);
    $riwayatUntukAI = $stmtRiwayat->fetchAll(PDO::FETCH_ASSOC);

    // Cek cache jawaban singkat dulu sebelum panggil Gemini (lihat ai_cache_* di atas).
    $cacheKey = ai_cache_build_key($question, $modelKey, $scopeBidang, $riwayatUntukAI);
    $cached = ai_cache_get($pdo, $cacheKey);

    if ($cached) {
        $jawaban = $cached['jawaban'];
        $blocks = $cached['blocks'];
        $hasExportable = $cached['bisa_export'];
        $intentsUntukLog = ['(cache)'];
    } else {
        $klasifikasi = ai_classify_intent($question, $daftarBarang, $modelKey, $riwayatUntukAI);
        $intents = $klasifikasi['intents'] ?? [['intent' => 'tidak_dikenali']];
        $apiError = $klasifikasi['error'] ?? null;
        $intentsUntukLog = array_column($intents, 'intent');

        $blocks = [];
        $summaryForAI = [];

        // Kalau AI meminta klarifikasi, langsung balas pertanyaan baliknya —
        // jangan jalankan query apa pun, dan jangan panggil Lapisan 3.
        $klarifikasi = null;
        foreach ($intents as $it) {
            if (($it['intent'] ?? '') === 'perlu_klarifikasi') {
                $klarifikasi = $it['pertanyaan_balik'] ?? 'Bisa perjelas maksud pertanyaannya?';
                break;
            }
        }

        if ($klarifikasi) {
            $jawaban = $klarifikasi;
        } elseif (!$apiError) {
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
                        $block = query_detail_transaksi($pdo, $it['tipe'] ?? 'keluar', $it['periode'] ?? 'hari_ini', $scopeBidang);
                        break;
                    case 'barang_keluar_periode':
                        $block = query_barang_keluar_periode($pdo, $it['bulan'] ?? null, $it['tahun'] ?? null, $it['tanggal_mulai'] ?? null, $it['tanggal_selesai'] ?? null, $scopeBidang);
                        break;
                    case 'rekap_bidang':
                        $block = query_rekap_bidang($pdo, $it['bidang'] ?? null, $it['tahun'] ?? null, $scopeBidang);
                        break;
                    case 'stok_barang':
                        $block = query_stok_barang($pdo, $it['nama_barang'] ?? null);
                        break;
                    case 'rekap_lengkap':
                        $block = query_rekap_lengkap($pdo, $it['bulan'] ?? null, $it['tahun'] ?? null, $scopeBidang);
                        break;
                    case 'riwayat_perubahan_barang':
                        if ($scopeBidang) {
                            // Riwayat siapa mengubah DATA BARANG (bukan transaksi bidang)
                            // adalah info administratif gudang, bukan milik bidang tertentu
                            // -> tidak ditampilkan ke role 'bidang' lewat Asisten AI.
                            $block = [
                                'title' => 'Riwayat Perubahan Data Barang',
                                'rows' => [], 'chart' => null,
                                'summary' => ['akses' => 'dibatasi', 'keterangan' => 'Informasi ini hanya tersedia untuk admin/super admin.'],
                            ];
                        } else {
                            $block = query_riwayat_perubahan_barang($pdo, $it['nama_barang'] ?? null, $it['jumlah_hari'] ?? 7);
                        }
                        break;
                    case 'analisis_data':
                        $block = query_analisis_data($pdo, $it, $scopeBidang);
                        break;
                    case 'prediksi_stok_habis':
                        $block = query_prediksi_stok_habis($pdo, $it['nama_barang'] ?? null, $it['periode_hari'] ?? 90);
                        break;
                }
                if ($block) {
                    $block['intent'] = $intentName;
                    $blocks[] = $block;
                    $summaryForAI[$intentName] = $block['summary'] ?? null;
                }
            }
        }

        if ($klarifikasi) {
            // $jawaban sudah diisi di atas.
        } elseif ($apiError) {
            $jawaban = 'Asisten AI sedang bermasalah: ' . $apiError . ' Silakan coba lagi nanti atau hubungi admin.';
        } elseif (!$blocks) {
            $jawaban = 'Maaf, saya belum bisa membantu pertanyaan itu. Saya bisa bantu: cek stok menipis, cek ketersediaan barang, tren grafik transaksi, daftar barang masuk/keluar per bulan atau rentang tanggal, rekap per bidang, sisa stok barang, rekap lengkap bulanan, riwayat siapa mengubah data barang, prediksi kapan barang akan habis, atau analisis/filter data custom (mis. "barang apa yang paling banyak keluar ke bidang X" atau "rata-rata harga per jenis barang").';
        } else {
            $jawaban = ai_generate_answer($summaryForAI, $modelKey);
        }

        $hasExportable = false;
        foreach ($blocks as $b) { if (!empty($b['rows'])) { $hasExportable = true; break; } }

        // Jangan cache kalau API AI sedang error — supaya percobaan berikutnya
        // tidak "terjebak" cache gagal selama masa TTL.
        if (!$apiError) {
            ai_cache_set($pdo, $cacheKey, $jawaban, $blocks, $hasExportable);
        }
    }

    $_SESSION['ai_last_result'] = ['question' => $question, 'blocks' => $blocks];

    ai_audit_log($u['username'] ?? 'unknown', $question, implode(',', $intentsUntukLog), $jawaban);

    // Simpan ke riwayat percakapan (per user), supaya muncul lagi saat halaman dibuka ulang.
    $pdo->prepare('INSERT INTO ai_chat_history (user_id, pertanyaan, jawaban, blocks_json) VALUES (?, ?, ?, ?)')
        ->execute([$u['id'], $question, $jawaban, json_encode($blocks, JSON_UNESCAPED_UNICODE)]);

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

  .ai-table-wrap{border:1px solid var(--line);border-radius:var(--radius-sm);overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .ai-table-wrap table{width:100%;min-width:420px;font-size:11.5px;border-collapse:collapse;}
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

  /* ============================================================
     RESPONSIVE — layar sempit (HP/tablet portrait, <=640px)
     ============================================================ */
  @media (max-width: 640px) {
    .ai-hero{flex-wrap:wrap;padding:16px;gap:12px;}
    .ai-hero-ic{width:40px;height:40px;}
    .ai-hero-text{flex:1 1 auto;min-width:0;}
    .ai-hero-text h2{font-size:14.5px;}
    .ai-hero-text p{font-size:11.5px;}
    .ai-clear-btn{flex:1 1 100%;text-align:center;justify-content:center;}

    .ai-chips{gap:6px;margin-bottom:14px;}
    .ai-chip{font-size:11px;padding:6px 11px 6px 9px;}

    .ai-chat{padding:14px;gap:14px;min-height:280px;max-height:65vh;}
    .ai-avatar{width:26px;height:26px;border-radius:8px;font-size:10px;}
    .ai-msg,.ai-msg.bot{max-width:calc(100% - 36px);font-size:13px;padding:10px 13px;}

    .ai-chart-card{height:200px;padding:10px;}
    .ai-table-wrap table{font-size:11px;}
    .ai-table-wrap th,.ai-table-wrap td{padding:6px 8px;}

    .ai-composer{padding:12px 14px;}
    .ai-model-row{flex-wrap:wrap;gap:6px;margin-bottom:8px;}
    .ai-model-row select{flex:1;min-width:0;}
    .ai-form{gap:8px;}
    .ai-form input{padding:11px 12px 11px 36px;font-size:13px;}
    .ai-input-wrap svg{left:12px;}
    .ai-send-btn{width:40px;height:40px;}
  }

  /* Layar sangat sempit (<=380px, HP kecil) */
  @media (max-width: 380px) {
    .ai-hero-text h2{font-size:13.5px;}
    .ai-hero-text p{display:none;} /* sembunyikan deskripsi panjang, judul saja cukup */
    .ai-chat{padding:10px;}
  }
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
    <span class="ai-chip"><?= icon('shield', 13) ?>barang apa aja yang baru diedit admin minggu ini</span>
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