<?php

$contents = file_get_contents('req.log');
$contents = trim($contents, '"');
$lines = explode("\\r\\n", $contents);
$get = array_shift($lines);
$path = explode(" ", $get)[1];

for ($i = 0; $i < count($lines); $i ++) {
    $val = $lines[$i];
    if (trim($val) == "") {
        $lines = array_slice($lines, 0, $i - 1);
        break;
    }

    $parts = explode(":", $val);
    $key = $parts[0];
    if ($key == 'Host') {
        $value = trim($parts[1]);
        $host = $value;
        unset($lines[$i]);
    }
}

echo "curl ";

foreach ($lines as $header) {
    echo " -H \"$header\" \\\n";
}

echo " https://$host$path\n";

