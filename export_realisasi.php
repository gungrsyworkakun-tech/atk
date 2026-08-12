<?php
require __DIR__ . '/includes/bootstrap.php';
require_permission('realisasi');

$items = $pdo->query('
    SELECT i.*,
      COALESCE((SELECT SUM(jumlah) FROM transactions WHERE item_id = i.id AND tipe = "masuk"), 0) AS total_masuk,
      COALESCE((SELECT SUM(jumlah) FROM transactions WHERE item_id = i.id AND tipe = "keluar"), 0) AS total_keluar
    FROM items i ORDER BY i.nama
')->fetchAll();

function statusOf($stok, $min) {
    if ($min > 0 && $stok <= $min) return 'menipis';
    if ($min > 0 && $stok <= $min * 1.5) return 'pantau';
    return 'aman';
}
function statusLabel($status) {
    $map = ['menipis' => 'Stok Menipis', 'pantau' => 'Perlu Dipantau', 'aman' => 'Aman'];
    return $map[$status] ?? $status;
}

$grouped = ['menipis' => [], 'pantau' => [], 'aman' => []];
foreach ($items as $it) {
    $stok = (int)$it['stok'];
    $min = (int)$it['stok_minimum'];
    $grouped[statusOf($stok, $min)][] = $it;
}

/** Escape teks untuk XML. */
function xEsc($str) {
    return htmlspecialchars((string)$str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Cetak satu baris data ke SpreadsheetML. */
function xRow($cells) {
    echo '<Row>';
    foreach ($cells as $cell) {
        $style = is_array($cell) ? ($cell['style'] ?? 'sData') : 'sData';
        $type = is_array($cell) ? ($cell['type'] ?? 'String') : 'String';
        $val = is_array($cell) ? $cell['value'] : $cell;
        echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="' . $type . '">' . xEsc($val) . '</Data></Cell>';
    }
    echo '</Row>';
}

/** Cetak satu worksheet penuh berisi tabel realisasi untuk sekumpulan barang. */
function xSheet($name, $rows, $showStatus = true) {
    $cols = $showStatus
        ? ['No', 'Kode', 'Nama Barang', 'Jenis', 'Satuan', 'Masuk Total', 'Terealisasi (Keluar)', 'Sisa Stok', 'Limit Minimum', 'Status']
        : ['No', 'Kode', 'Nama Barang', 'Jenis', 'Satuan', 'Masuk Total', 'Terealisasi (Keluar)', 'Sisa Stok', 'Limit Minimum'];

    echo '<Worksheet ss:Name="' . xEsc($name) . '">';
    echo '<Table>';
    echo '<Column ss:Width="35"/><Column ss:Width="70"/><Column ss:Width="180"/><Column ss:Width="110"/>';
    echo '<Column ss:Width="60"/><Column ss:Width="80"/><Column ss:Width="120"/><Column ss:Width="70"/>';
    echo '<Column ss:Width="90"/>';
    if ($showStatus) echo '<Column ss:Width="110"/>';

    // Baris judul besar di atas tabel.
    echo '<Row ss:Height="26"><Cell ss:StyleID="sTitle" ss:MergeAcross="' . (count($cols) - 1) . '"><Data ss:Type="String">' . xEsc($name) . '</Data></Cell></Row>';
    echo '<Row ss:Height="4"></Row>';

    // Header kolom.
    echo '<Row ss:Height="20">';
    foreach ($cols as $c) {
        echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">' . xEsc($c) . '</Data></Cell>';
    }
    echo '</Row>';

    if (!$rows) {
        echo '<Row><Cell ss:StyleID="sEmpty" ss:MergeAcross="' . (count($cols) - 1) . '"><Data ss:Type="String">Tidak ada barang pada kategori ini.</Data></Cell></Row>';
    } else {
        foreach ($rows as $i => $it) {
            $stok = (int)$it['stok'];
            $min = (int)$it['stok_minimum'];
            $status = statusOf($stok, $min);
            $rowStyle = $i % 2 === 0 ? 'sData' : 'sDataAlt';
            $numStyle = $i % 2 === 0 ? 'sNum' : 'sNumAlt';
            $statusStyleMap = ['menipis' => 'sStatusDanger', 'pantau' => 'sStatusWarn', 'aman' => 'sStatusOk'];
            $statStyle = ($i % 2 === 0 ? '' : 'Alt') ? $statusStyleMap[$status] : $statusStyleMap[$status];

            echo '<Row>';
            echo '<Cell ss:StyleID="' . $numStyle . '"><Data ss:Type="Number">' . ($i + 1) . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $rowStyle . '"><Data ss:Type="String">' . xEsc($it['kode']) . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $rowStyle . '"><Data ss:Type="String">' . xEsc($it['nama']) . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $rowStyle . '"><Data ss:Type="String">' . xEsc($it['jenis']) . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $rowStyle . '"><Data ss:Type="String">' . xEsc($it['satuan'] ?? 'pcs') . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $numStyle . '"><Data ss:Type="Number">' . (int)$it['total_masuk'] . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $numStyle . '"><Data ss:Type="Number">' . (int)$it['total_keluar'] . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $numStyle . '"><Data ss:Type="Number">' . $stok . '</Data></Cell>';
            echo '<Cell ss:StyleID="' . $numStyle . '"><Data ss:Type="Number">' . $min . '</Data></Cell>';
            if ($showStatus) {
                echo '<Cell ss:StyleID="' . $statusStyleMap[$status] . '"><Data ss:Type="String">' . xEsc(statusLabel($status)) . '</Data></Cell>';
            }
            echo '</Row>';
        }
    }

    echo '</Table>';
    echo '</Worksheet>';
}

$filename = 'realisasi-stok-' . date('Y-m-d') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">

<Styles>
  <Style ss:ID="sTitle">
    <Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1" ss:Color="#1E293B"/>
    <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="sHeader">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#2563EB" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1D4ED8"/>
    </Borders>
  </Style>
  <Style ss:ID="sData">
    <Font ss:FontName="Calibri" ss:Size="10"/>
    <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="sDataAlt">
    <Font ss:FontName="Calibri" ss:Size="10"/>
    <Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="sNum">
    <Font ss:FontName="Calibri" ss:Size="10"/>
    <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="sNumAlt">
    <Font ss:FontName="Calibri" ss:Size="10"/>
    <Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="sStatusDanger">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#B3432B"/>
    <Interior ss:Color="#F7E1DC" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="sStatusWarn">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#9C6314"/>
    <Interior ss:Color="#FBEFD9" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="sStatusOk">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#234F46"/>
    <Interior ss:Color="#E4F0EA" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="sEmpty">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Italic="1" ss:Color="#64748B"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center"/>
  </Style>
</Styles>

<?php xSheet('Ringkasan (Semua)', $items, true); ?>
<?php xSheet('Stok Menipis', $grouped['menipis'], false); ?>
<?php xSheet('Perlu Dipantau', $grouped['pantau'], false); ?>
<?php xSheet('Aman', $grouped['aman'], false); ?>

</Workbook>