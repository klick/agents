#!/usr/bin/env php
<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "JSON path is required.\n");
    exit(1);
}

$data = json_decode(stream_get_contents(STDIN), true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON input.\n");
    exit(2);
}

$value = $data;
foreach (explode('.', $path) as $segment) {
    if (is_array($value) && array_key_exists($segment, $value)) {
        $value = $value[$segment];
        continue;
    }

    fwrite(STDERR, sprintf("Missing JSON path: %s\n", $path));
    exit(3);
}

if (is_bool($value)) {
    echo $value ? 'true' : 'false';
    exit(0);
}

if (is_array($value)) {
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($value === null) {
    echo 'null';
    exit(0);
}

echo (string)$value;
