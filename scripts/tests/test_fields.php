<?php
$j = json_decode(file_get_contents('debug_pdf_data.json'), true);
echo "CAMPOS COUNT: " . count($j['campos'] ?? []) . "\n";
echo "CAMPOS ANEXO COUNT: " . count($j['camposAnexo'] ?? []) . "\n";
echo "ANEXOS COUNT: " . count($j['anexos'] ?? []) . "\n";
if(count($j['camposAnexo'] ?? []) > 0) {
    echo "PRIMER CAMPO ANEXO: " . $j['camposAnexo'][0]['nombre_codigo'] . "\n";
}
