<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('asisten_ai');

$result = $_SESSION['ai_last_result'] ?? null;
$blocks = $result['blocks'] ?? [];

// Ambil hanya blok yang punya data tabel (skip yang cuma grafik tanpa rows,
// atau summary-only tanpa rows).
$exportableBlocks = array_values(array_filter($blocks, function ($b) {
    return !empty($b['rows']) && is_array($b['rows']);
}));

if (!$exportableBlocks) {
    http_response_code(400);
    die('Tidak ada data untuk diekspor. Silakan tanya ke Asisten AI terlebih dahulu (yang menghasilkan tabel data), baru klik Export.');
}

function xEsc($str) {
    return htmlspecialchars((string)$str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Nama sheet Excel maksimal 31 karakter & tidak boleh mengandung karakter tertentu. */
function safeSheetName($title, $fallbackIndex) {
    $name = trim((string)$title) ?: ('Hasil ' . $fallbackIndex);
    $name = preg_replace('/[\\\\\/\?\*\[\]\:]/', '-', $name);
    return mb_substr($name, 0, 31);
}

ob_start();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";

echo '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">';
echo '<Title>Hasil Asisten AI</Title>';
echo '<Author>Sistem Inventaris Pendataan ATK</Author>';
echo '<Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>';
echo '</DocumentProperties>';

echo '<Styles>';
echo '<Style ss:ID="sTitle"><Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1" ss:Color="#0F172A"/></Style>';
echo '<Style ss:ID="sMeta"><Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#64748B"/></Style>';
echo '<Style ss:ID="sHeader">';
echo '<Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>';
echo '<Interior ss:Color="#2563EB" ss:Pattern="Solid"/>';
echo '<Alignment ss:Vertical="Center" ss:Horizontal="Center"/>';
echo '</Style>';
echo '<Style ss:ID="sData">';
echo '<Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1E293B"/>';
echo '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders>';
echo '</Style>';
echo '<Style ss:ID="sDataAlt">';
echo '<Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1E293B"/>';
echo '<Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>';
echo '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders>';
echo '</Style>';
echo '</Styles>';

foreach ($exportableBlocks as $idx => $block) {
    $rows = $block['rows'];
    $cols = array_keys($rows[0]);
    $sheetName = safeSheetName($block['title'] ?? '', $idx + 1);

    echo '<Worksheet ss:Name="' . xEsc($sheetName) . '">';
    echo '<Table>';
    foreach ($cols as $c) echo '<Column ss:Width="120"/>';

    echo '<Row ss:Height="24"><Cell ss:StyleID="sTitle" ss:MergeAcross="' . (count($cols) - 1) . '"><Data ss:Type="String">' . xEsc($block['title'] ?? 'Hasil Asisten AI') . '</Data></Cell></Row>';
    echo '<Row ss:Height="18"><Cell ss:StyleID="sMeta" ss:MergeAcross="' . (count($cols) - 1) . '"><Data ss:Type="String">Pertanyaan: ' . xEsc($result['question'] ?? '-') . ' — diekspor ' . xEsc(date('d F Y, H:i')) . '</Data></Cell></Row>';
    echo '<Row ss:Height="6"></Row>';

    echo '<Row ss:Height="20">';
    foreach ($cols as $c) {
        echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">' . xEsc(ucwords(str_replace('_', ' ', $c))) . '</Data></Cell>';
    }
    echo '</Row>';

    foreach ($rows as $i => $row) {
        $style = $i % 2 === 1 ? 'sDataAlt' : 'sData';
        echo '<Row>';
        foreach ($cols as $c) {
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . xEsc($row[$c] ?? '') . '</Data></Cell>';
        }
        echo '</Row>';
    }

    echo '</Table>';
    echo '</Worksheet>';
}

echo '</Workbook>';

$xmlOutput = ob_get_clean();

$filename = 'hasil-asisten-ai-' . date('Y-m-d-His') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xmlOutput));
header('Pragma: no-cache');
header('Expires: 0');

echo $xmlOutput;