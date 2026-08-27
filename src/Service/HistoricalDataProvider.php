<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Jedno miejsce, w którym komendy pobierają dane historyczne.
 *
 * Powód istnienia: wcześniej każda komenda robiła
 *
 *     $stats = $client->getHotColdNumbers(...);
 *     $frequencies = $stats['sorted_by_freq_desc'] ?? [];
 *
 * co przy błędzie API (np. HTTP 401) dawało pustą tablicę i CAŁA warstwa
 * statystyczna liczyła się dalej na domyślnych jedynkach — bez żadnego
 * sygnału dla użytkownika. Raport nadal chwalił się "przewagą synergii".
 *
 * Ta klasa zawsze zwraca jawny status, żeby komenda mogła ostrzec.
 */
class HistoricalDataProvider
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly DrawHistoryProvider $drawHistoryProvider
    ) {
    }

    /**
     * @return array{
     *     frequencies: array<int, int>,
     *     draws: array<int, array<int>>,
     *     draws_analyzed: int|string,
     *     date_from: string,
     *     date_to: string,
     *     ok: bool,
     *     warning: ?string
     * }
     */
    public function fetch(string $gameType, int $sessions, ?int $months = null): array
    {
        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($gameType, $sessions, $months ?: 6);
        $dateTo = (new \DateTime())->format('Y-m-d\TH:i:s\Z');

        $stats = $this->lottoApiClient->getHotColdNumbers($gameType, $dateFrom);

        if ($stats === null) {
            $error = $this->lottoApiClient->getLastError();

            return [
                'frequencies' => [],
                'draws' => [],
                'special_frequencies' => [],
                'special_draws' => [],
                'draws_analyzed' => 0,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'ok' => false,
                'warning' => $error?->getUserMessage()
                    ?? 'Nie udało się pobrać danych historycznych z LOTTO OpenAPI.',
            ];
        }

        $frequencies = $stats['sorted_by_freq_desc'] ?? [];

        if ($frequencies === []) {
            return [
                'frequencies' => [],
                'draws' => [],
                'special_frequencies' => [],
                'special_draws' => [],
                'draws_analyzed' => 0,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'ok' => false,
                'warning' => 'LOTTO OpenAPI zwróciło pustą listę częstotliwości dla tego zakresu dat.',
            ];
        }

        // Historia losowań jest opcjonalna: bez niej macierz par degraduje się
        // do heurystyki częstotliwościowej (i jest tak oznaczona w raporcie).
        $history = $this->drawHistoryProvider->getHistory($gameType, max($sessions, 50));
        $draws = array_map(static fn(array $d): array => $d['main'], $history['draws']);
        $specialDraws = [];
        foreach ($history['draws'] as $d) {
            if ($d['special'] !== []) {
                $specialDraws[] = $d['special'];
            }
        }

        $warning = null;
        if (count($draws) < StatisticalOptimizerService::MIN_DRAWS_FOR_CO_OCCURRENCE) {
            $warning = sprintf(
                "Brak wystarczającej historii losowań (%d < %d), aby policzyć RZECZYWISTE współwystępowanie par.\n"
                . "Macierz powiązań zostanie zbudowana z heurystyki częstotliwościowej, która NIE zawiera "
                . "informacji o parach — 'najsilniejsza para' to po prostu dwie najgorętsze liczby.",
                count($draws),
                StatisticalOptimizerService::MIN_DRAWS_FOR_CO_OCCURRENCE
            );
        }

        return [
            'frequencies' => $frequencies,
            'draws' => $draws,
            'special_frequencies' => $stats['special_freq_desc'] ?? [],
            'special_draws' => $specialDraws,
            'draws_analyzed' => $stats['draws_analyzed'] ?? count($draws),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'ok' => true,
            'warning' => $warning,
        ];
    }
}
