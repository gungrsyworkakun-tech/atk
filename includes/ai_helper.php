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
 * LAPISAN 1: Klasifikasikan intent — MENDUKUNG PERTANYAAN GABUNGAN.
 * Selalu mengembalikan array "intents".
 */
function ai_classify_intent($question, array $daftarNamaBarang, $modelKey = GEMINI_MODEL_DEFAULT) {
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
11. "tidak_dikenali" - HANYA jika TIDAK ADA satu pun bagian pertanyaan yang cocok 1-10

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