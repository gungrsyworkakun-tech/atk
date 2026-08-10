<?php
/**
 * Pembungkus tipis di atas qrcode-lib.php (library QR Code pihak ketiga,
 * single-file, tanpa dependency, lisensi MIT: psyon/php-qrcode).
 */
require_once __DIR__ . '/qrcode-lib.php';

final class ItemQr {

    /** QR polos tanpa label. */
    public static function render($kode, $scale = 6, $padding = 10) {
        $qr = new QRCode((string)$kode, ['s' => 'qrm', 'sf' => $scale, 'p' => $padding]);
        return $qr->render_image();
    }

    /** QR + desain modern: accent bar, nama tebal, badge kode, badge tahun/stok/jenis. */
    public static function render_with_label($kode, $nama, $tahun, $stok, $jenis = '', $scale = 6, $padding = 10) {
        $qrImg = self::render($kode, $scale, $padding);
        $qrW = imagesx($qrImg);
        $qrH = imagesy($qrImg);

        $namaText  = self::truncate((string)$nama, 26);
        $kodeText  = (string)$kode;
        $tahunText = 'Tahun ' . $tahun;
        $stokText  = 'Stok ' . $stok;
        $jenisText = trim((string)$jenis) !== '' ? self::truncate((string)$jenis, 18) : null;

        $fName = 5; $fKode = 4; $fBadge = 3;

        $namaW  = imagefontwidth($fName)  * strlen($namaText);
        $namaH  = imagefontheight($fName);
        $kodeW  = imagefontwidth($fKode)  * strlen($kodeText);
        $kodeH  = imagefontheight($fKode);
        $tahunW = imagefontwidth($fBadge) * strlen($tahunText);
        $stokW  = imagefontwidth($fBadge) * strlen($stokText);
        $jenisW = $jenisText !== null ? imagefontwidth($fBadge) * strlen($jenisText) : 0;
        $badgeH = imagefontheight($fBadge);

        $padX = 14; $padY = 7;
        $kodeBadgeW  = $kodeW  + ($padX * 2);
        $kodeBadgeH  = $kodeH  + ($padY * 2);
        $tahunBadgeW = $tahunW + ($padX * 2) - 4;
        $stokBadgeW  = $stokW  + ($padX * 2) - 4;
        $jenisBadgeW = $jenisText !== null ? ($jenisW + ($padX * 2) - 4) : 0;
        $statBadgeH  = $badgeH + (($padY - 1) * 2);
        $badgeGap    = 8;

        $statRowW = $tahunBadgeW + $badgeGap + $stokBadgeW;
        if ($jenisText !== null) $statRowW += $badgeGap + $jenisBadgeW;

        $outerMargin = 18;
        $accentH = 8;
        $gap1 = 16; // accent -> qr
        $gap2 = 16; // qr -> nama
        $gap3 = 10; // nama -> badge kode
        $gap4 = 10; // badge kode -> baris tahun/stok/jenis

        $contentW = max($qrW, $namaW, $kodeBadgeW, $statRowW);
        $canvasW = $contentW + ($outerMargin * 2);
        $canvasH = $outerMargin + $accentH + $gap1 + $qrH + $gap2 + $namaH + $gap3 + $kodeBadgeH + $gap4 + $statBadgeH + $outerMargin;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        $white         = imagecolorallocate($canvas, 255, 255, 255);
        $ink           = imagecolorallocate($canvas, 23, 28, 38);
        $muted         = imagecolorallocate($canvas, 120, 128, 140);
        $border        = imagecolorallocate($canvas, 224, 227, 232);
        $accent        = imagecolorallocate($canvas, 13, 148, 136);  // teal
        $badgeBg       = imagecolorallocate($canvas, 241, 243, 246); // abu muda
        $stokBg        = imagecolorallocate($canvas, 209, 245, 229); // teal muda
        $stokTextColor = imagecolorallocate($canvas, 6, 95, 70);     // teal tua
        $jenisBg       = imagecolorallocate($canvas, 219, 234, 254); // biru muda
        $jenisTextColor= imagecolorallocate($canvas, 30, 64, 175);   // biru tua

        imagefill($canvas, 0, 0, $white);

        // Border tipis di sekeliling kartu.
        imagerectangle($canvas, 1, 1, $canvasW - 2, $canvasH - 2, $border);

        // Accent bar rounded di atas.
        self::frr($canvas, $outerMargin, $outerMargin, $canvasW - $outerMargin, $outerMargin + $accentH, 4, $accent);

        // QR di tengah.
        $y = $outerMargin + $accentH + $gap1;
        $qrX = (int)(($canvasW - $qrW) / 2);
        imagecopy($canvas, $qrImg, $qrX, $y, 0, 0, $qrW, $qrH);
        imagedestroy($qrImg);
        $y += $qrH + $gap2;

        // Nama barang, tebal (simulasi bold dengan cetak dobel geser 1px).
        $x = (int)(($canvasW - $namaW) / 2);
        imagestring($canvas, $fName, $x, $y, $namaText, $ink);
        imagestring($canvas, $fName, $x + 1, $y, $namaText, $ink);
        $y += $namaH + $gap3;

        // Badge kode barang (abu-abu, rounded, di tengah).
        $bx1 = (int)(($canvasW - $kodeBadgeW) / 2);
        $bx2 = $bx1 + $kodeBadgeW;
        self::frr($canvas, $bx1, $y, $bx2, $y + $kodeBadgeH, 8, $badgeBg);
        $tx = $bx1 + (int)(($kodeBadgeW - $kodeW) / 2);
        imagestring($canvas, $fKode, $tx, $y + $padY, $kodeText, $ink);
        $y += $kodeBadgeH + $gap4;

        // Baris badge Tahun + Stok (+ Jenis kalau ada) berdampingan, di tengah.
        $rowX = (int)(($canvasW - $statRowW) / 2);

        self::frr($canvas, $rowX, $y, $rowX + $tahunBadgeW, $y + $statBadgeH, 8, $badgeBg);
        $tx1 = $rowX + (int)(($tahunBadgeW - $tahunW) / 2);
        imagestring($canvas, $fBadge, $tx1, $y + $padY - 1, $tahunText, $muted);

        $stokX1 = $rowX + $tahunBadgeW + $badgeGap;
        self::frr($canvas, $stokX1, $y, $stokX1 + $stokBadgeW, $y + $statBadgeH, 8, $stokBg);
        $tx2 = $stokX1 + (int)(($stokBadgeW - $stokW) / 2);
        imagestring($canvas, $fBadge, $tx2, $y + $padY - 1, $stokText, $stokTextColor);

        if ($jenisText !== null) {
            $jenisX1 = $stokX1 + $stokBadgeW + $badgeGap;
            self::frr($canvas, $jenisX1, $y, $jenisX1 + $jenisBadgeW, $y + $statBadgeH, 8, $jenisBg);
            $tx3 = $jenisX1 + (int)(($jenisBadgeW - $jenisW) / 2);
            imagestring($canvas, $fBadge, $tx3, $y + $padY - 1, $jenisText, $jenisTextColor);
        }

        return $canvas;
    }

    private static function frr($im, $x1, $y1, $x2, $y2, $r, $color) {
        $r = (int)min($r, floor(($x2 - $x1) / 2), floor(($y2 - $y1) / 2));
        if ($r < 1) { imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color); return; }
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private static function truncate($str, $max) {
        return strlen($str) > $max ? substr($str, 0, $max - 1) . '…' : $str;
    }
}