<?php

declare(strict_types=1);

/**
 * Import ręcznie pobranej historii Lotto do archiwum chronologicznego.
 *
 * Skrypt zasiewa GŁĘBOKĄ historię (od 1957), której nie da się zbudować
 * z LOTTO OpenAPI: nie ma tam endpointu z zakresem dat dla wyników, a
 * pojedyncze zapytania na datę kończą się HTTP 429. Bieżące losowania
 * dokłada już DrawArchiveService przy każdym uruchomieniu generatora.
 *
 * Format wejścia (data/raw_download.txt), jedna linia na losowanie:
 *   1. 27.01.1957 8,12,31,39,43,45
 */

$inputFile = __DIR__ . '/../data/raw_download.txt';
$outputJson = __DIR__ . '/../data/lotto_draws.json';
$cacheFile = __DIR__ . '/../var/draw-history/Lotto.json';

if (!file_exists($inputFile)) {
    echo "Input file does not exist: $inputFile\n";
    exit(1);
}

$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$parsed = [];

foreach ($lines as $line) {
    // Format: 1. 27.01.1957 8,12,31,39,43,45
    if (!preg_match('/^\d+\.\s+(\d{2})\.(\d{2})\.(\d{4})\s+([\d,]+)$/', trim($line), $matches)) {
        continue;
    }

    $date = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
    $numbers = array_values(array_unique(array_map('intval', explode(',', $matches[4]))));
    sort($numbers);

    $parsed[] = ['date' => $date, 'numbers' => $numbers];
}

$count = count($parsed);
echo "Parsed $count draws successfully.\n";

if ($count === 0) {
    exit(1);
}

// Scalanie zamiast nadpisania: archiwum mogło już zostać uzupełnione
// o świeże losowania z oficjalnego API i nie wolno ich zgubić.
$existing = [];
if (is_readable($outputJson)) {
    $decoded = json_decode((string) file_get_contents($outputJson), true);
    if (is_array($decoded)) {
        $existing = $decoded;
    }
}

$signature = static fn(array $draw): string => $draw['date'] . '|' . implode(',', $draw['numbers']);

$seen = [];
foreach ($existing as $draw) {
    if (is_array($draw) && isset($draw['date'], $draw['numbers']) && is_array($draw['numbers'])) {
        $seen[$signature($draw)] = true;
    }
}

$added = 0;
foreach ($parsed as $draw) {
    if (isset($seen[$signature($draw)])) {
        continue;
    }

    $seen[$signature($draw)] = true;
    $existing[] = $draw;
    $added++;
}

usort($existing, static fn(array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));

file_put_contents($outputJson, json_encode(array_values($existing), JSON_PRETTY_PRINT));
echo "Archive: " . count($existing) . " draws ($added new) -> $outputJson\n";

/*
 * Cache LOTTO OpenAPI (var/draw-history/Lotto.json) jest zasiewany tylko dla
 * DAT, KTÓRYCH JESZCZE W NIM NIE MA. Wcześniej skrypt nadpisywał cały plik
 * ręcznymi danymi, kasując wyniki pobrane z API (m.in. identyfikatory losowań
 * i liczby dodatkowe) i utrwalając je jako "sprawdzone", więc API nigdy już
 * tych dat nie dociągało.
 */
$cache = [];
if (is_readable($cacheFile)) {
    $decoded = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($decoded)) {
        $cache = $decoded;
    }
}

$seeded = 0;
foreach ($parsed as $draw) {
    if (isset($cache[$draw['date']])) {
        continue;
    }

    $cache[$draw['date']] = [['main' => $draw['numbers'], 'special' => [], 'id' => null]];
    $seeded++;
}

$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

krsort($cache);
file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
echo "API cache: " . count($cache) . " dates ($seeded seeded) -> $cacheFile\n";
