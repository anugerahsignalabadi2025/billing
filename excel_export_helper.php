<?php
function outputExcelWorkbook(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Styles><Style ss:ID="Header"><Font ss:Bold="1"/></Style></Styles>';
    echo '<Worksheet ss:Name="Data"><Table>';
    echo '<Row ss:StyleID="Header">';
    foreach ($headers as $header) {
        echo '<Cell><Data ss:Type="String">' . excelXmlValue($header) . '</Data></Cell>';
    }
    echo '</Row>';

    foreach ($rows as $row) {
        echo '<Row>';
        foreach ($row as $value) {
            if (is_int($value) || is_float($value)) {
                echo '<Cell><Data ss:Type="Number">' . $value . '</Data></Cell>';
            } else {
                echo '<Cell><Data ss:Type="String">' . excelXmlValue((string) ($value ?? '')) . '</Data></Cell>';
            }
        }
        echo '</Row>';
    }

    echo '</Table></Worksheet></Workbook>';
    exit;
}

function excelXmlValue(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
