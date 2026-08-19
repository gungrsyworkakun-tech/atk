<?php
/**
 * includes/ai_helper.php
 *
 * Lapisan 1 (klasifikasi intent) dan Lapisan 3 (penyusunan jawaban) dari
 * fitur Asisten AI menggunakan Google Gemini API.
 *
 * PENTING: file ini HANYA boleh berisi fungsi ai_*() — komunikasi ke API AI.
 * Fungsi query_*() (query_rekap_stok_menipis, query_cek_ketersediaan, dll)
 * HARUS ada di asisten_ai.php saja (Lapisan 2), supaya tidak terjadi bentrok
 * "Cannot redeclare" seperti yang barusan terjadi.
 */

// 1. API Key Anda — sebaiknya pindah ke environment variable, jangan hardcode di kode.
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'AQ.Ab8RN6K7-Ow57A7C0_dEC__Yz9m05UIbjOkTYsRDJHbSlPyoTg');

// 2. Daftar model yang boleh dipilih pengguna (WHITELIST).
const GEMINI_MODELS = [
    'cepat'   => 'gemini-3.6-flash',
    'presisi' => 'gemini-3.1-pro',
    'hemat'   => 'gemini-2.5-flash-lite',
];
const GEMINI_MODEL_DEFAULT = 'cepat';

function gemini_resolve_model($modelKey) {
    return GEMINI_MODELS[$modelKey] ?? GEMINI_MODELS[GEMINI_MODEL_DEFAULT];
}

/**
 * Panggilan dasar ke Google Gemini API.
 */
function ai_call($system, $userText, $modelKey = GEMINI_MODEL_DEFAULT) {
    if (GEMINI_API_KEY === 'GANTI_DENGAN_API_KEY_ANDA' || GEMINI_API_KEY === '') {
        return [null, 'API key Gemini belum diatur. Hubungi admin sistem.'];
    }

    $model = gemini_resolve_model($modelKey);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . GEMINI_API_KEY;

    $payload = [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
        'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 800],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 25,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        error_log('Gemini AI call gagal (curl): ' . $curlErr);
        return [null, 'Gagal menghubungi layanan AI (jaringan).'];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $apiMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        error_log('Gemini AI call gagal (API): ' . $apiMsg . ' | raw: ' . $response);
        return [null, 'Layanan AI menolak permintaan: ' . $apiMsg];
    }

    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        error_log('Gemini AI call: format respons tidak sesuai — ' . $response);
        return [null, 'Format respons AI tidak sesuai.'];
    }

    return [$data['candidates'][0]['content']['parts'][0]['text'], null];
}

/**
 * Bangun teks ringkas riwayat percakapan terakhir untuk dimasukkan ke prompt,
 * supaya Lapisan 1 bisa memahami pertanyaan lanjutan/singkat yang merujuk ke
 * percakapan sebelumnya (mis. "kalau bulan lalu gimana?", "yang itu berapa?").
 * $riwayat: array of ['pertanyaan' => string, 'jawaban' => string], TERBARU DULU.
 */
function ai_build_riwayat_text(array $riwayat) {
    if (!$riwayat) return '(tidak ada riwayat percakapan sebelumnya)';
    // Balik urutan supaya kronologis (lama -> baru) waktu ditampilkan ke AI.
    $riwayat = array_reverse(array_slice($riwayat, 0, 3));
    $lines = [];
    foreach ($riwayat as $r) {
        $lines[] = '- Petugas: "' . mb_substr($r['pertanyaan'], 0, 150) . '"';
        $lines[] = '  Asisten: "' . mb_substr($r['jawaban'], 0, 200) . '"';
    }
    return implode("\n", $lines);
}

/**
 * LAPISAN 1: Klasifikasikan intent — MENDUKUNG PERTANYAAN GABUNGAN.
 * Selalu mengembalikan array "intents".
 *
 * $riwayat: 0-3 pasangan pertanyaan/jawaban terakhir (terbaru dulu), dipakai
 * supaya AI bisa mengerti pertanyaan singkat/lanjutan yang merujuk ke konteks
 * sebelumnya. Opsional — kalau kosong, perilaku sama seperti sebelumnya.
 */
function ai_classify_intent($question, array $daftarNamaBarang, $modelKey = GEMINI_MODEL_DEFAULT, array $riwayat = []) {
    $qLower = mb_strtolower($question);
    $relevan = array_values(array_filter($daftarNamaBarang, function ($n) use ($qLower) {
        return mb_strpos($qLower, mb_strtolower(substr($n, 0, 4))) !== false
            || mb_strpos($qLower, mb_strtolower($n)) !== false;
    }));
    $daftarUntukAI = $relevan ?: array_slice($daftarNamaBarang, 0, 60);
    $daftarBarangText = implode(', ', $daftarUntukAI);
    $hariIni = date('Y-m-d');
    $bulanIniAngka = (int)date('n');
    $tahunIni = date('Y');
    $riwayatText = ai_build_riwayat_text($riwayat);

    $system = <<<SYS
Kamu adalah pengklasifikasi maksud pertanyaan untuk sistem inventaris ATK.
Tanggal hari ini: {$hariIni} (bulan berjalan = {$bulanIniAngka}, tahun berjalan = {$tahunIni}).

PENTING soal gaya bahasa: petugas SERING menulis santai, disingkat, ada typo, atau
pakai bahasa sehari-hari/gaul (bukan bahasa baku) — misalnya "abis" = habis, "kaga ada"/
"ga ada" = tidak ada, "berapaan" = berapa, "cekin" = cek, "gan"/"min" cuma sapaan yang
diabaikan. JANGAN mudah menjatuhkan ke "tidak_dikenali" hanya karena penulisannya tidak
baku, typo, atau terpotong — coba pahami maksud sebenarnya semaksimal mungkin dulu.
Hanya pakai "tidak_dikenali" kalau BENAR-BENAR tidak ada satu pun bagian pertanyaan yang
nyambung ke topik stok/barang/transaksi/laporan ATK sama sekali.

RIWAYAT PERCAKAPAN TERAKHIR (lama -> baru, maksimal 3 terakhir) — PENTING dipakai untuk
memahami pertanyaan SINGKAT/LANJUTAN yang merujuk konteks sebelumnya, misalnya "kalau
bulan lalu gimana?", "yang itu berapa?", "coba yang bidang lain juga":
{$riwayatText}
Kalau pertanyaan sekarang jelas berdiri sendiri (tidak merujuk apa pun sebelumnya),
ABAIKAN riwayat di atas dan klasifikasikan murni dari pertanyaan sekarang saja.

Pertanyaan petugas BISA mengandung LEBIH DARI SATU maksud sekaligus (misal: minta
daftar barang keluar SEKALIGUS minta grafik). Balas HANYA JSON murni berisi array
"intents", tanpa penjelasan tambahan, tanpa markdown, tanpa teks lain di luar JSON.

Setiap elemen array adalah SALAH SATU dari intent berikut:

1. "rekap_stok_menipis" - barang apa saja yang stoknya menipis/hampir habis
2. "cek_ketersediaan" - stok/ketersediaan SATU barang tertentu (field "nama_barang")
3. "ringkasan_laporan" - ringkasan singkat pemakaian ATK bulan berjalan (angka agregat)
4. "grafik_transaksi" - MELIHAT GRAFIK/TREN barang masuk vs keluar beberapa bulan terakhir
   (field "jumlah_bulan", angka, default 6 kalau tidak disebutkan)
5. "detail_transaksi" - DAFTAR barang masuk/keluar HARI INI atau BULAN INI saja
   (field "tipe": "masuk"/"keluar"; "periode": "hari_ini"/"bulan_ini", default "hari_ini")
6. "barang_keluar_periode" - DAFTAR barang keluar untuk BULAN TERTENTU (bisa beda dari
   bulan berjalan) atau RENTANG TANGGAL tertentu, sertakan juga grafiknya.
   - Kalau user menyebut nama bulan (mis. "Agustus", "bulan lalu"), isi field "bulan"
     (angka 1-12) dan "tahun" (default tahun berjalan kalau tidak disebut). JANGAN isi
     tanggal_mulai/tanggal_selesai.
   - Kalau user menyebut rentang tanggal spesifik (mis. "1 sampai 10 Agustus"), isi
     "tanggal_mulai" dan "tanggal_selesai" format YYYY-MM-DD, JANGAN isi field bulan.
7. "rekap_bidang" - rekap pengambilan barang oleh SATU bidang tertentu ATAU SEMUA bidang.
   Field "bidang" (nama bidang sebagai string, KOSONGKAN/jangan isi kalau user minta
   SEMUA bidang), "tahun" opsional (default tahun berjalan).
8. "stok_barang" - menampilkan SISA STOK satu barang tertentu ATAU SEMUA barang beserta
   grafik. Field "nama_barang" (KOSONGKAN/jangan isi kalau user minta semua barang).
9. "rekap_lengkap" - LAPORAN/REKAP LENGKAP mencakup semua data penting (masuk, keluar,
   stok menipis, per bidang) untuk SATU BULAN. Field "bulan" (1-12, default bulan
   berjalan), "tahun" opsional (default tahun berjalan).
10. "riwayat_perubahan_barang" - SIAPA menambah/mengubah/menghapus DATA BARANG (bukan
    transaksi masuk/keluar), dan APA yang diubah. Contoh: "barang apa yang baru diedit
    admin", "siapa yang nambahin barang minggu ini", "riwayat perubahan data barang".
    Field "jumlah_hari" (default 7, rentang pencarian ke belakang), "nama_barang"
    (KOSONGKAN kalau user tidak sebut barang tertentu).
11. "analisis_data" - PERTANYAAN ANALITIK BEBAS yang TIDAK cocok rapi ke intent 1-10 di
    atas, misalnya minta filter/agregasi/urutan kombinasi custom (contoh: "barang apa yang
    paling banyak keluar ke bidang TIKKIM dengan jumlah lebih dari 5", "rata-rata harga
    barang per jenis", "urutkan barang dari stok paling sedikit", "berapa kali PULPEN
    keluar tahun ini", "siapa saja penerima barang bulan ini diurutkan terbanyak").
    PENTING: HANYA boleh memakai nama field yang ada di whitelist berikut, JANGAN
    mengarang nama field lain:

    a) "sumber": "transaksi" (field yang boleh dipakai: tipe [masuk/keluar], bidang,
       penerima, nama_barang, kode_barang, jenis_barang, jumlah, tanggal, tahun, bulan)
    b) "sumber": "barang" (field: nama, jenis, satuan, stok, stok_minimum, harga,
       tahun_masuk)
    c) "sumber": "log_perubahan" (field: aksi [tambah/ubah/hapus], username, nama_barang,
       kode_barang, tanggal)

    Field lain untuk intent ini:
    - "filter": array objek {"field", "operator" (salah satu: "=", "!=", ">", "<", ">=",
      "<=", "like", "between"), "nilai", "nilai2" (HANYA untuk operator "between")}
    - "group_by": nama field (atau kosongkan kalau tidak perlu dikelompokkan)
    - "agregasi": {"fungsi": "count"/"sum"/"avg"/"min"/"max", "field": nama field numerik
      yang diagregasi} — kosongkan seluruh objek ini kalau user cuma minta daftar/list
      biasa tanpa hitungan
    - "urutkan_field", "urutkan_arah" ("asc"/"desc")
    - "batas": jumlah baris maksimal (default 20)

12. "prediksi_stok_habis" - PREDIKSI kapan barang akan habis, BUKAN sekadar cek stok
    sekarang — dihitung dari rata-rata pemakaian (barang keluar) beberapa bulan terakhir.
    Pakai intent ini untuk pertanyaan seperti "barang apa yang bakal habis duluan", "kapan
    stok pulpen habis", "barang mana yang perlu segera dipesan ulang", "prediksi kehabisan
    stok". Field "nama_barang" (KOSONGKAN untuk semua barang sekaligus, diurutkan dari yang
    paling cepat habis), "periode_hari" (rentang hari ke belakang buat hitung rata-rata
    pemakaian, default 90).

13. "perlu_klarifikasi" - GUNAKAN INI HANYA kalau pertanyaan BENAR-BENAR ambigu sehingga
    menebak salah satu intent berisiko salah paham secara signifikan. Contoh situasi yang
    LAYAK diklarifikasi: nama barang yang disebut cocok ke beberapa barang sekaligus dan
    tidak jelas yang mana yang dimaksud; rentang waktu yang sama sekali tidak jelas
    maksudnya dan bisa berarti sangat berbeda. JANGAN pakai ini hanya karena pertanyaan
    singkat, tidak baku, atau typo — untuk kasus begitu tetap coba pahami semaksimal
    mungkin dan pakai intent 1-12 seperti biasa. Field "pertanyaan_balik": satu kalimat
    tanya balik yang sopan, singkat, spesifik, dalam Bahasa Indonesia, sebutkan pilihannya
    kalau relevan (mis. "Maksud Anda PULPEN HITAM atau PULPEN BIRU? Ada dua jenis di
    sistem."). Kalau intent ini dipakai, JANGAN sertakan intent lain dalam array yang sama.

14. "tidak_dikenali" - HANYA jika TIDAK ADA satu pun bagian pertanyaan yang cocok 1-13

Contoh sebagian nama barang yang ada di sistem:
{$daftarBarangText}

Contoh format keluaran (JSON murni, satu objek saja):
{"intents": [{"intent": "cek_ketersediaan", "nama_barang": "Pulpen"}]}
{"intents": [{"intent": "barang_keluar_periode", "bulan": 8, "tahun": 2026}]}
{"intents": [{"intent": "rekap_bidang", "bidang": "TIKKIM", "tahun": 2026}]}
{"intents": [{"intent": "rekap_bidang"}]}
{"intents": [{"intent": "stok_barang"}]}
{"intents": [{"intent": "rekap_lengkap", "bulan": 7, "tahun": 2026}]}
{"intents": [{"intent": "riwayat_perubahan_barang", "jumlah_hari": 7}]}
{"intents": [{"intent": "riwayat_perubahan_barang", "jumlah_hari": 30, "nama_barang": "Pulpen"}]}
{"intents": [{"intent": "detail_transaksi", "tipe": "keluar", "periode": "hari_ini"}, {"intent": "grafik_transaksi", "jumlah_bulan": 6}]}
{"intents": [{"intent": "analisis_data", "sumber": "transaksi", "filter": [{"field": "bidang", "operator": "=", "nilai": "TIKKIM"}, {"field": "jumlah", "operator": ">", "nilai": "5"}], "group_by": "nama_barang", "agregasi": {"fungsi": "sum", "field": "jumlah"}, "urutkan_field": "agregasi", "urutkan_arah": "desc", "batas": 10}]}
{"intents": [{"intent": "analisis_data", "sumber": "barang", "group_by": "jenis", "agregasi": {"fungsi": "avg", "field": "harga"}}]}
{"intents": [{"intent": "analisis_data", "sumber": "transaksi", "filter": [{"field": "nama_barang", "operator": "like", "nilai": "PULPEN"}, {"field": "tipe", "operator": "=", "nilai": "keluar"}, {"field": "tahun", "operator": "=", "nilai": "2026"}], "agregasi": {"fungsi": "count", "field": "jumlah"}}]}
{"intents": [{"intent": "prediksi_stok_habis", "periode_hari": 90}]}
{"intents": [{"intent": "prediksi_stok_habis", "nama_barang": "Pulpen", "periode_hari": 60}]}
{"intents": [{"intent": "perlu_klarifikasi", "pertanyaan_balik": "Maksud Anda PULPEN HITAM atau PULPEN BIRU? Ada dua jenis di sistem."}]}
{"intents": [{"intent": "tidak_dikenali"}]}
SYS;

    [$raw, $error] = ai_call($system, $question, $modelKey);
    if ($error) return ['intents' => [['intent' => 'tidak_dikenali']], 'error' => $error];
    if (!$raw) return ['intents' => [['intent' => 'tidak_dikenali']]];

    $raw = trim(preg_replace('/```json|```/', '', $raw));
    $parsed = json_decode($raw, true);

    if (!is_array($parsed) || !isset($parsed['intents']) || !is_array($parsed['intents']) || !count($parsed['intents'])) {
        return ['intents' => [['intent' => 'tidak_dikenali']]];
    }
    return $parsed;
}

/**
 * LAPISAN 3: Susun jawaban naratif dari RINGKASAN (bukan data mentah penuh)
 * dari beberapa hasil query sekaligus.
 */
function ai_generate_answer(array $summaryPerIntent, $modelKey = GEMINI_MODEL_DEFAULT) {
    $system = <<<SYS
Kamu adalah asisten petugas gudang ATK. Data JSON di bawah berisi RINGKASAN dari
beberapa bagian (kunci = jenis datanya). Susun SATU jawaban singkat (maksimal 6 kalimat)
berbahasa Indonesia formal yang mencakup SEMUA bagian yang ada — jangan lewatkan satu pun.
HANYA berdasarkan data yang diberikan, JANGAN menambahkan angka/fakta yang tidak ada.
Kalau suatu bagian datanya kosong/nol, sebutkan singkat bahwa tidak ada hasil untuk
bagian itu. Beri tahu pengguna bahwa detail lengkap & grafik tersedia di bawah pesan ini.
SYS;

    $userText = "Ringkasan per bagian: " . json_encode($summaryPerIntent, JSON_UNESCAPED_UNICODE);
    [$answer, $error] = ai_call($system, $userText, $modelKey);

    if ($error) return 'Data ditemukan, tapi sistem gagal menyusun narasinya (' . $error . '). Berikut data mentahnya di bawah.';
    return $answer ?: 'Maaf, sistem sedang tidak dapat menyusun jawaban otomatis. Berikut data mentahnya di bawah.';
}

/**
 * Rate limiting sederhana.
 */
function ai_rate_limit_check($maxPerMinute = 8) {
    $now = time();
    $window = 60;
    $log = $_SESSION['ai_rate_log'] ?? [];
    $log = array_values(array_filter($log, function ($t) use ($now, $window) { return $t > $now - $window; }));

    if (count($log) >= $maxPerMinute) return false;
    $log[] = $now;
    $_SESSION['ai_rate_log'] = $log;
    return true;
}

/**
 * Audit log sederhana.
 */
function ai_audit_log($username, $question, $intent, $answer) {
    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . 'asisten_ai-' . date('Y-m') . '.log';
    $line = json_encode([
        'waktu' => date('Y-m-d H:i:s'),
        'user' => $username,
        'pertanyaan' => $question,
        'intent' => $intent,
        'jawaban' => mb_substr($answer, 0, 300),
    ], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}