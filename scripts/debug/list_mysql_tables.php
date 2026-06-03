<?php
$tables = \Illuminate\Support\Facades\DB::connection('mysql')->select('SHOW TABLES');
foreach ($tables as $t) {
    $vals = (array) $t;
    echo array_values($vals)[0] . "\n";
}
