<?php
$apiKey = 'AQ.Ab8RN6L-vfP0uqdNrIkGG2qjK1Xp4AVeo2JTx2LoknZ509iakA';
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

// Mengambil data dari Google API
$response = file_get_contents($url);

if ($response === FALSE) {
    die("Gagal mengambil data dari Google API. Pastikan koneksi internet aktif.");
}

$data = json_decode($response, true);

echo "<h3>Daftar Model yang Mendukung generateContent:</h3>";
echo "<ul>";

// Melakukan filter hanya untuk model yang mendukung 'generateContent'
foreach ($data['models'] as $model) {
    if (in_array('generateContent', $model['supportedGenerationMethods'])) {
        // Hapus teks 'models/' di depannya agar mudah di-copy
        $modelName = str_replace('models/', '', $model['name']);
        echo "<li><b>" . $modelName . "</b></li>";
    }
}
echo "</ul>";
?>