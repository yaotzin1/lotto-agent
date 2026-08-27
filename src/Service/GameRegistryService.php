<?php

declare(strict_types=1);

namespace App\Service;

class GameRegistryService
{
    /** Górna granica cofania się w czasie przy liczeniu dat losowań. */
    public const MAX_SESSIONS_LOOKBACK = 2000;

    private const GAMES_CONFIG = [
        'MiniLotto' => [
            'name' => 'MiniLotto',
            'pick' => 5,
            'pick_min' => 5,
            'pick_max' => 5,
            'from' => 42,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'Lotto' => [
            'name' => 'Lotto',
            'pick' => 6,
            'pick_min' => 6,
            'pick_max' => 6,
            'from' => 49,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [2, 4, 6], // Tue, Thu, Sat
        ],
        'LottoPlus' => [
            'name' => 'LottoPlus',
            'pick' => 6,
            'pick_min' => 6,
            'pick_max' => 6,
            'from' => 49,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [2, 4, 6],
        ],
        'EuroJackpot' => [
            'name' => 'EuroJackpot',
            'pick' => 5,
            'pick_min' => 5,
            'pick_max' => 5,
            'from' => 50,
            'extra' => 2,
            'extra_from' => 12,
            'draw_days' => [2, 5], // Tue, Fri
        ],
        'MultiMulti' => [
            'name' => 'MultiMulti',
            'pick' => 10,
            'pick_min' => 1,
            'pick_max' => 10,
            'from' => 80,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'EkstraPensja' => [
            'name' => 'EkstraPensja',
            'pick' => 5,
            'pick_min' => 5,
            'pick_max' => 5,
            'from' => 35,
            'extra' => 1,
            'extra_from' => 4,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'EkstraPremia' => [
            'name' => 'EkstraPremia',
            'pick' => 5,
            'pick_min' => 5,
            'pick_max' => 5,
            'from' => 35,
            'extra' => 1,
            'extra_from' => 4,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'Kaskada' => [
            'name' => 'Kaskada',
            'pick' => 12,
            'pick_min' => 12,
            'pick_max' => 12,
            'from' => 24,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'Keno' => [
            'name' => 'Keno',
            'pick' => 10,
            'pick_min' => 1,
            'pick_max' => 10,
            'from' => 70,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'Szybkie600' => [
            'name' => 'Szybkie600',
            'pick' => 6,
            'pick_min' => 6,
            'pick_max' => 6,
            'from' => 32,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'ZakladySpecjalne' => [
            'name' => 'ZakladySpecjalne',
            'pick' => 5,
            'pick_min' => 5,
            'pick_max' => 5,
            'from' => 45,
            'extra' => 0,
            'extra_from' => 0,
            'draw_days' => [1, 2, 3, 4, 5, 6, 7],
        ],
    ];

    /**
     * Czy gracz sam decyduje, ile liczb skreśla.
     *
     * Multi Multi i Keno pozwalają skreślić od 1 do 10 liczb — do tej pory
     * rejestr wymuszał na nich sztywne `pick`, a jedynym miejscem, które o tym
     * pamiętało, był specjalny warunek w LottoGeneratorCommand.
     */
    public function isVariablePick(string $gameType): bool
    {
        $c = $this->getGameConfig($gameType);

        return ($c['pick_min'] ?? $c['pick']) !== ($c['pick_max'] ?? $c['pick']);
    }

    /**
     * @return array{min: int, max: int, default: int}
     */
    public function getPickRange(string $gameType): array
    {
        $c = $this->getGameConfig($gameType);

        return [
            'min' => (int) ($c['pick_min'] ?? $c['pick']),
            'max' => (int) ($c['pick_max'] ?? $c['pick']),
            'default' => (int) $c['pick'],
        ];
    }

    /**
     * Sprowadza żądaną liczbę skreśleń do zakresu dozwolonego przez grę.
     * Dla gier o stałym `pick` zawsze zwraca tę stałą wartość.
     */
    public function resolvePick(string $gameType, ?int $requested): int
    {
        $range = $this->getPickRange($gameType);

        if ($requested === null || !$this->isVariablePick($gameType)) {
            return $range['default'];
        }

        return max($range['min'], min($range['max'], $requested));
    }

    public function getAllGames(): array
    {
        return self::GAMES_CONFIG;
    }

    public function getGameNames(): array
    {
        return array_keys(self::GAMES_CONFIG);
    }

    public function getGameConfig(string $gameType): array
    {
        if (!isset(self::GAMES_CONFIG[$gameType])) {
            throw new \InvalidArgumentException(sprintf('Nieznany typ gry: "%s".', $gameType));
        }

        return self::GAMES_CONFIG[$gameType];
    }

    public function isValidGame(string $gameType): bool
    {
        return isset(self::GAMES_CONFIG[$gameType]);
    }

    public function calculateDateFromForSessions(string $gameType, ?int $sessions = null, int $fallbackMonths = 6): string
    {
        if ($sessions === null || $sessions <= 0) {
            return (new \DateTime("-{$fallbackMonths} months"))->format('Y-m-d\TH:i:s\Z');
        }

        $config = $this->getGameConfig($gameType);
        $drawDays = $config['draw_days'];

        if ($drawDays === []) {
            throw new \RuntimeException(sprintf('Gra "%s" nie ma zdefiniowanych dni losowań.', $gameType));
        }

        // Ogranicznik: bez niego --sessions=1000000 kręciło miliony iteracji.
        $sessions = min($sessions, self::MAX_SESSIONS_LOOKBACK);

        $date = new \DateTime();
        $counted = 0;

        while ($counted < $sessions) {
            $date->modify('-1 day');
            if (in_array((int)$date->format('N'), $drawDays, true)) {
                $counted++;
            }
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }

}
