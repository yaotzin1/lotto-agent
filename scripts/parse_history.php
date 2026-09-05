<?php

declare(strict_types=1);

$inputFile = __DIR__ . '/../data/raw_download.txt';
$outputJson = __DIR__ . '/../data/lotto_draws.json';

if (!file_exists($inputFile)) {
    echo "Input file does not exist: $inputFile\n";
    exit(1);
}

$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$drawsByDate = [];
$chronologicalDraws = [];

foreach ($lines as $line) {
    $line = trim($line);
    // Format: 1. 27.01.1957 8,12,31,39,43,45
    if (preg_match('/^\d+\.\s+(\d{2})\.(\d{2})\.(\d{4})\s+([\d,]+)$/', $line, $matches)) {
        $day = $matches[1];
        $month = $matches[2];
        $year = $matches[3];
        $date = "$year-$month-$day";
        $numbers = array_map('intval', explode(',', $matches[4]));
        sort($numbers);

        $drawEntry = [
            'date' => $date,
            'numbers' => $numbers,
        ];

        $chronologicalDraws[] = $drawEntry;
        $drawsByDate[$date][] = [
            'main' => $numbers,
            'special' => [],
        ];
    }
}

$count = count($chronologicalDraws);
echo "Parsed $count draws successfully.\n";

if ($count > 0) {
    file_put_contents($outputJson, json_encode($chronologicalDraws, JSON_PRETTY_PRINT));
    echo "Saved chronological draws to $outputJson\n";

    $varDir = __DIR__ . '/../var/draw-history';
    if (!is_dir($varDir)) {
        mkdir($varDir, 0777, true);
    }
    file_put_contents($varDir . '/Lotto.json', json_encode($drawsByDate, JSON_PRETTY_PRINT));
    echo "Saved store format to $varDir/Lotto.json\n";
}
