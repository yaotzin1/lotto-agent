<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Wynik pracy BetPipelineService.
 *
 * Ostrzeżenia są częścią wyniku, a nie drukowane po drodze — dzięki temu
 * generowanie da się przetestować bez terminala, a komenda decyduje, jak je
 * pokazać (SymfonyStyle, TUI albo JSON).
 */
final class BetPipelineResult
{
    /**
     * @param array<int, array<int>> $bets Kupony (liczby główne)
     * @param array<int, array<int>> $extraSets Liczby dodatkowe, wyrównane indeksem do $bets
     * @param array<string, mixed>|null $statsReport Raport Okna Statystycznego (tryby 7 i 8)
     * @param array<string, mixed>|null $extraInfo Metadane generatora liczb dodatkowych
     * @param array<int, string> $warnings
     */
    public function __construct(
        public readonly array $bets,
        public readonly array $extraSets = [],
        public readonly ?array $statsReport = null,
        public readonly ?array $extraInfo = null,
        public readonly array $warnings = [],
    ) {
    }

    public function count(): int
    {
        return count($this->bets);
    }

    public function hasExtraNumbers(): bool
    {
        return $this->extraSets !== [];
    }
}
