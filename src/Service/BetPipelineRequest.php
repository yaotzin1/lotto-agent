<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Komplet parametrów potrzebnych do wygenerowania pakietu kuponów.
 *
 * Celowo bez żadnego I/O: komenda CLI zbiera dane (opcje albo pytania
 * interaktywne), a tu trafia już gotowa, zwalidowana konfiguracja. Dzięki temu
 * ta sama logika obsługuje `app:lotto-generator` i `app:lotto-tui`, a testy
 * nie muszą udawać terminala.
 */
final class BetPipelineRequest
{
    /**
     * @param array<string, mixed> $game Konfiguracja gry z GameRegistryService
     * @param array<int> $pool Pula wejściowa liczb głównych
     * @param array<int, int> $frequencies Mapa [liczba => wystąpienia]
     * @param array<int, array<int>> $draws Historia losowań (liczby główne)
     * @param array<int> $bankers Liczby stałe/rotacyjne (tryby 4 i 6)
     * @param array<int> $hotNumbers Liczby o zwiększonej wadze (tryb 3)
     */
    public function __construct(
        public readonly array $game,
        public readonly array $pool,
        public readonly string $mode,
        public readonly int $betsTotal,
        public readonly array $frequencies = [],
        public readonly array $draws = [],
        public readonly array $bankers = [],
        public readonly int $bankersPerBet = 3,
        public readonly int $l1Size = 12,
        public readonly int $l1Count = 4,
        public readonly int $l2Size = 8,
        public readonly int $l2Count = 2,
        public readonly int $blockSize = 12,
        public readonly int $blockCount = 5,
        public readonly array $hotNumbers = [],
        public readonly int $weight = 5,
    ) {
    }

    public function pick(): int
    {
        return (int) ($this->game['pick'] ?? 6);
    }

    public function maxNumber(): int
    {
        return (int) ($this->game['from'] ?? 49);
    }

    public function extraCount(): int
    {
        return (int) ($this->game['extra'] ?? 0);
    }

    public function extraFrom(): int
    {
        return (int) ($this->game['extra_from'] ?? 0);
    }
}
