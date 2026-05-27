<?php
$f = fopen("storage/logs/laravel.log", "r");
fseek($f, -5000, SEEK_END);
echo fread($f, 5000);
fclose($f);
