<?php

namespace App\Service;

class LotteryVerifier
{
    public const GAMES_CONFIG = [
        'Mini_Lotto' => [ 'name' => 'Mini_Lotto', 'pick' => 5, 'from' => 42, 'min_win' => 3 ],
        'Lotto' => [ 'name' => 'Lotto', 'pick' => 6, 'from' => 49, 'min_win' => 3 ],
        'Eurojackpot' => [ 'name' => 'Eurojackpot', 'pick' => 5, 'from' => 50, 'min_win' => 3 ],
        'Szybkie_600' => [ 'name' => 'Szybkie_600', 'pick' => 6, 'from' => 32, 'min_win' => 3 ],
        'Multi_Multi' => [ 'name' => 'Multi_Multi', 'pick' => null, 'from' => 80, 'min_win' => null ],
    ];

    public function detectGameFromFilename(string $filename): ?array
    {
        if (strpos($filename, 'DOWOLNY') !== false) {
            if (preg_match('/_(\d+)z(\d+)_/', $filename, $m) === 1) {
                return [ 'name' => 'Gra Dowolna', 'pick' => (int)$m[1], 'from' => (int)$m[2], 'min_win' => 2 ];
            }
        }
        foreach (self::GAMES_CONFIG as $key => $config) {
            if (strpos($filename, $key) !== false) {
                return $config;
            }
        }
        return null;
    }

    public function parseSystemFile(string $filepath, array &$gameConfig): array
    {
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Plik '$filepath' nie został znaleziony!");
        }
        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $bets = [];
        $parsing = false;
        foreach ($lines as $line) {
            if ($gameConfig['name'] === 'Multi_Multi' && str_contains($line, 'Typ Gry Multi Multi:')) {
                if (preg_match('/\(Gra na (\d+) liczb\)/', $line, $m)) {
                    $gameConfig['pick'] = (int)$m[1];
                    $gameConfig['min_win'] = 2;
                }
            }
            if (str_contains($line, '--- Gotowe zakłady do skreślenia ---')) { $parsing = true; continue; }
            if ($parsing) {
                $clean = preg_replace('/^\d+\.\s*/', '', $line);
                $bets[] = array_map('intval', explode(', ', $clean));
            }
        }
        if (($gameConfig['name'] === 'Multi_Multi' || $gameConfig['name'] === 'Gra Dowolna') && ($gameConfig['pick'] === null) && !empty($bets)) {
            $gameConfig['pick'] = count($bets[0]);
            if ($gameConfig['min_win'] === null) $gameConfig['min_win'] = 2;
        }
        return $bets;
    }

    public function getTierName(int $count, ?array $gameConfig): string
    {
        if ($gameConfig && ($gameConfig['name'] === 'Multi_Multi' || $gameConfig['name'] === 'Gra Dowolna') && $gameConfig['pick'] !== null) {
            return "Trafiono {$count} z {$gameConfig['pick']}";
        }
        return match ($count) {
            2 => 'Dwójki', 3 => 'Trójki', 4 => 'Czwórki', 5 => 'Piątki', 6 => 'Szóstki', default => "$count trafień",
        };
    }
}
