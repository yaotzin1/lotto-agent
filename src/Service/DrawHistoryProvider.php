<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Trwały, przyrostowy magazyn wyników losowań.
 *
 * LOTTO OpenAPI nie ma endpointu z zakresem dat dla wyników — jedyny działający
 * to `by-date-per-game` dla POJEDYNCZEJ daty. Pobranie 100 losowań to więc 100
 * zapytań, co bardzo szybko kończy się odpowiedzią **HTTP 429 (Too Many Requests)**
 * i pustą historią.
 *
 * Dlatego historia jest zapisywana na dysku i przy kolejnych uruchomieniach
 * dociągane są wyłącznie BRAKUJĄCE daty. Typowy drugi przebieg = zero zapytań.
 */
class DrawHistoryProvider
{
    /** Ile dat pobieramy maksymalnie w JEDNYM uruchomieniu (reszta doczyta się później). */
    private const MAX_NEW_DATES_PER_RUN = 25;

    /** Ile zapytań leci równolegle. */
    private const BATCH_SIZE = 8;

    /** @var array<string, array<string, array{main: array<int>, special: array<int>}>> */
    private array $memory = [];

    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/draw-history')]
        private readonly string $storageDir
    ) {
    }

    /**
     * @return array{
     *     draws: array<int, array{main: array<int>, special: array<int>}>,
     *     from_cache: int,
     *     fetched: int,
     *     rate_limited: bool,
     *     missing: int
     * }
     */
    public function getHistory(string $gameType, int $limit): array
    {
        $limit = max(1, min($limit, 400));
        $wanted = $this->drawDates($gameType, $limit);
        $store = $this->load($gameType);

        $missingDates = array_values(array_filter(
            $wanted,
            static fn(string $d): bool => !array_key_exists($d, $store)
        ));

        $fromCache = count($wanted) - count($missingDates);
        $fetched = 0;
        $rateLimited = false;

        // Świadomy limit na jeden przebieg: przy pustym cache'u pobranie 200 dat
        // i tak skończyłoby się blokadą 429.
        $toFetch = array_slice($missingDates, 0, self::MAX_NEW_DATES_PER_RUN);

        foreach (array_chunk($toFetch, self::BATCH_SIZE) as $batch) {
            if ($rateLimited) {
                break;
            }

            $responses = [];
            foreach ($batch as $date) {
                $r = $this->lottoApiClient->requestDrawsForDate($gameType, $date);
                if ($r !== null) {
                    $responses[$date] = $r;
                }
            }

            foreach ($responses as $date => $response) {
                if ($rateLimited) {
                    // KLUCZOWE: niezużyta odpowiedź 4xx rzuca wyjątek w destruktorze
                    // Symfony HttpClient i wywraca cały proces. Musi zostać anulowana.
                    $this->cancelQuietly($response);
                    continue;
                }

                $result = $this->lottoApiClient->resolveDrawsResponse($response, $gameType);

                if ($result['rate_limited']) {
                    $rateLimited = true;
                    $this->cancelQuietly($response);
                    $this->logger->warning('LOTTO API: limit zapytań (429). Przerywam dociąganie historii.', [
                        'game' => $gameType,
                        'fetched_before_limit' => $fetched,
                    ]);
                    continue;
                }

                // Zapisujemy też pustą listę: "sprawdzone, brak losowania tego dnia".
                // Dzięki temu nie odpytujemy tej daty ponownie przy każdym uruchomieniu.
                if ($result['ok']) {
                    $store[$date] = $result['draws'];
                    $fetched++;
                }
            }
        }

        if ($fetched > 0) {
            $this->save($gameType, $store);
        }

        $draws = [];
        foreach ($wanted as $date) {
            foreach ($store[$date] ?? [] as $draw) {
                $draws[] = $draw;
            }
        }

        return [
            'draws' => $draws,
            'from_cache' => $fromCache,
            'fetched' => $fetched,
            'rate_limited' => $rateLimited,
            'missing' => max(0, count($missingDates) - $fetched),
        ];
    }

    /**
     * Anuluje odpowiedź, której nie zamierzamy odczytać.
     *
     * Bez tego Symfony HttpClient rzuca ClientException z destruktora przy
     * odpowiedziach 4xx, co przewraca proces już po zakończeniu naszej pracy.
     */
    private function cancelQuietly(\Symfony\Contracts\HttpClient\ResponseInterface $response): void
    {
        try {
            $response->cancel();
        } catch (\Throwable) {
            // celowo ignorujemy
        }
    }

    /**
     * Daty losowań danej gry, od najnowszej wstecz.
     *
     * @return array<int, string>
     */
    private function drawDates(string $gameType, int $limit): array
    {
        $config = $this->gameRegistryService->getGameConfig($gameType);
        $drawDays = $config['draw_days'] ?? [1, 2, 3, 4, 5, 6, 7];

        if ($drawDays === []) {
            return [];
        }

        $dates = [];
        $cursor = new \DateTime();
        $guard = 0;

        while (count($dates) < $limit && $guard++ < 5000) {
            if (in_array((int) $cursor->format('N'), $drawDays, true)) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor->modify('-1 day');
        }

        return $dates;
    }

    /**
     * @return array<string, array<int, array{main: array<int>, special: array<int>}>>
     */
    private function load(string $gameType): array
    {
        if (isset($this->memory[$gameType])) {
            return $this->memory[$gameType];
        }

        $file = $this->fileFor($gameType);
        $store = [];

        if (is_readable($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $store = $decoded;
            }
        }

        $this->memory[$gameType] = $store;

        return $store;
    }

    /**
     * @param array<string, array<int, array{main: array<int>, special: array<int>}>> $store
     */
    private function save(string $gameType, array $store): void
    {
        $this->memory[$gameType] = $store;

        try {
            if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
                return;
            }

            krsort($store);
            file_put_contents(
                $this->fileFor($gameType),
                json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $e) {
            // Brak zapisu na dysk nie może wywrócić analizy.
            $this->logger->warning('Nie udało się zapisać historii losowań', [
                'game' => $gameType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fileFor(string $gameType): string
    {
        return $this->storageDir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $gameType) . '.json';
    }
}
