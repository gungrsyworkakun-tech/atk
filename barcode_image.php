<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('barcode');
require __DIR__ . '/includes/barcode.php';

$kode = $_GET['kode'] ?? '';
if ($kode === '') { http_response_code(400); exit('Kode barang wajib diisi.'); }

$stmt = $pdo->prepare('SELECT kode, nama, jenis, tahun_masuk, stok FROM items WHERE kode = ?');
$stmt->execute([$kode]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); exit('Barang tidak ditemukan.'); }

if (!function_exists('imagepng')) {
    http_response_code(500);
    exit('Ekstensi GD belum aktif di server PHP.');
}

ob_start();
$error = null;
$img = null;
try {
    $img = ItemQr::render_with_label($item['kode'], $item['nama'], $item['tahun_masuk'], $item['stok'], $item['jenis']);
    if (!$img) { $error = 'render_with_label() tidak mengembalikan resource gambar.'; }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
ob_end_clean();

if ($error !== null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Gagal membuat barcode untuk "' . $kode . '": ' . $error);
}

// ---------- Bingkai kartu modern: logo di tengah header + barcode asli di bawahnya ----------
function gd_fill_rounded_rect($im, $x0, $y0, $w, $h, $r, $color) {
    $x1 = $x0 + $w - 1;
    $y1 = $y0 + $h - 1;
    $r = max(0, min($r, (int)floor(min($w, $h) / 2)));
    imagefilledrectangle($im, $x0 + $r, $y0, $x1 - $r, $y1, $color);
    imagefilledrectangle($im, $x0, $y0 + $r, $x1, $y1 - $r, $color);
    imagefilledellipse($im, $x0 + $r, $y0 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x1 - $r, $y0 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x0 + $r, $y1 - $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x1 - $r, $y1 - $r, $r * 2, $r * 2, $color);
}

$origW = imagesx($img);
$origH = imagesy($img);

$padX          = 26;
$logoSize      = 46;
$headerH       = $logoSize + 28;
$contentGap    = 14;
$padBottom     = 22;
$radius        = 18;
$borderThick   = 2;

$cardW = $origW + $padX * 2;
$cardH = $headerH + $contentGap + $origH + $padBottom;

$final = imagecreatetruecolor($cardW, $cardH);
imagesavealpha($final, true);
imagealphablending($final, false);
$transparent = imagecolorallocatealpha($final, 255, 255, 255, 127);
imagefill($final, 0, 0, $transparent);
imagealphablending($final, true);

$borderColor = imagecolorallocate($final, 215, 218, 210); // senada var(--line) tema terang
$bgColor     = imagecolorallocate($final, 255, 255, 255); // var(--paper-card)
$dividerColor= imagecolorallocate($final, 234, 236, 231);

gd_fill_rounded_rect($final, 0, 0, $cardW, $cardH, $radius, $borderColor);
gd_fill_rounded_rect($final, $borderThick, $borderThick, $cardW - $borderThick * 2, $cardH - $borderThick * 2, $radius - $borderThick, $bgColor);

// logo, ditengah secara horizontal, di area header
$logoPath = __DIR__ . '/assets/1.png';
if (is_file($logoPath)) {
    $logo = @imagecreatefrompng($logoPath);
    if ($logo) {
        imagesavealpha($logo, true);
        imagealphablending($logo, true);
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $targetW = $logoSize;
        $targetH = (int) round($targetW * $lh / $lw);
        $lx = (int) round(($cardW - $targetW) / 2);
        $ly = (int) round(($headerH - $targetH) / 2) + 4;
        imagecopyresampled($final, $logo, $lx, $ly, 0, 0, $targetW, $targetH, $lw, $lh);
        imagedestroy($logo);
    }
}

// garis pemisah tipis antara header logo & barcode
imagefilledrectangle($final, $padX, $headerH, $cardW - $padX, $headerH, $dividerColor);

// tempel barcode + label asli, dengan padding yang lega di sekelilingnya
imagecopy($final, $img, $padX, $headerH + $contentGap, 0, 0, $origW, $origH);

imagedestroy($img);
$img = $final;
// -----------------------------------------------------------------------------------------

if (!empty($_GET['download'])) {
    $safeKode = preg_replace('/[^A-Za-z0-9\-_]/', '', $item['kode']);
    $safeNama = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $item['nama']), '-');
    $filename = sprintf('%s_%s_%d.png', $safeKode, $safeNama, (int)$item['tahun_masuk']);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}
header('Content-Type: image/png');
imagepng($img);
imagedestroy($img);