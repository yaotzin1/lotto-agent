<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Trwały, przyrostowy magazyn wyników losowań.
 *
 * LOTTO OpenAPI nie ma endpointu z zakresem dat dla wyników — działają wyłącznie
 * `by-date-per-game` (jedna data, jedna gra) i `by-date` (jedna data, wszystkie
 * gry). Pobranie 100 losowań to więc 100 zapytań.
 *
 * Limit API dotyczy WSPÓŁBIEŻNOŚCI, nie liczby zapytań: sekwencyjnie przechodzi
 * ich dowolnie dużo, kilka równolegle kończy się HTTP 429. Wcześniejsza wersja
 * strzelała ósemkami równolegle, dostawała 429 i PRZERYWAŁA cały przebieg —
 * dlatego historia głębsza niż kilkadziesiąt losowań nigdy nie powstawała.
 * Teraz pobieramy po kolei, a 429 oznacza wycofanie i ponowienie daty.
 *
 * Historia jest zapisywana na dysku i przy kolejnych uruchomieniach dociągane są
 * wyłącznie BRAKUJĄCE daty. Typowy drugi przebieg = zero zapytań. Generator ma
 * mały budżet zapytań (MAX_NEW_DATES_PER_RUN), żeby nie kazać użytkownikowi
 * czekać; głęboką historię buduje komenda `app:lotto-archive`.
 */
class DrawHistoryProvider
{
    /** Ile dat pobieramy maksymalnie w JEDNYM uruchomieniu (reszta doczyta się później). */
    public const MAX_NEW_DATES_PER_RUN = 25;

    /** Górna granica okna dat, o jakie można poprosić naraz. */
    private const MAX_LIMIT = 4000;

    /**
     * Ile zapytań leci naraz.
     *
     * Pomiar na żywym API (wrzesień 2026): 80 zapytań PO KOLEI przeszło w 66 s
     * bez jednego HTTP 429, natomiast 8 równoległych wywoływało 429 już przy
     * ósmym, a 3 równoległe przy trzydziestym. Limit dotyczy WSPÓŁBIEŻNOŚCI,
     * nie liczby zapytań — dlatego pobieramy sekwencyjnie. Czas odpowiedzi
     * (ok. 0,8 s) sam w sobie wystarcza za odstęp.
     */
    private const REQUEST_CONCURRENCY = 1;

    /** Dodatkowy odstęp między zapytaniami (mikrosekundy). */
    private const REQUEST_DELAY_US = 100000;

    /** Ile razy ponawiamy datę odrzuconą przez 429, zanim się poddamy. */
    private const MAX_RATE_LIMIT_RETRIES = 4;

    /**
     * Bazowe wycofanie po 429 (mikrosekundy); rośnie wykładniczo.
     * Pomiar: po wejściu w limit API wraca do odpowiadania po ok. 22 s.
     */
    private const RATE_LIMIT_BACKOFF_US = 20000000;

    /** @var array<string, array<string, array{main: array<int>, special: array<int>}>> */
    private array $memory = [];

    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/draw-history')]
        private readonly string $storageDir,
        /** Wstrzykiwane, żeby testy nie musiały naprawdę czekać na wycofanie. */
        private readonly int $rateLimitBackoffUs = self::RATE_LIMIT_BACKOFF_US
    ) {
    }

    /**
     * @param int|null $maxNewDates budżet nowych zapytań na ten przebieg;
     *                              null = domyślne 25 (tryb interaktywny)
     * @param callable|null $onProgress wywoływane jako fn(int $done, int $total)
     *
     * @return array{
     *     draws: array<int, array{main: array<int>, special: array<int>}>,
     *     from_cache: int,
     *     fetched: int,
     *     rate_limited: bool,
     *     missing: int
     * }
     */
    public function getHistory(
        string $gameType,
        int $limit,
        ?int $maxNewDates = null,
        ?callable $onProgress = null
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $budget = max(0, $maxNewDates ?? self::MAX_NEW_DATES_PER_RUN);
        $wanted = $this->drawDates($gameType, $limit);
        $store = $this->load($gameType);

        $missingDates = array_values(array_filter(
            $wanted,
            static fn(string $d): bool => !array_key_exists($d, $store)
        ));

        $fromCache = count($wanted) - count($missingDates);
        $fetched = 0;
        $rateLimited = false;

        // Domyślnie świadomie mały budżet: w trybie interaktywnym nie chcemy
        // czekać na setki zapytań. Backfill podaje własny, znacznie większy.
        $queue = array_slice($missingDates, 0, $budget);
        $total = count($queue);
        $retries = 0;

        while ($queue !== [] && !$rateLimited) {
            $batch = array_splice($queue, 0, self::REQUEST_CONCURRENCY);

            $responses = [];
            foreach ($batch as $date) {
                $r = $this->lottoApiClient->requestDrawsForDate($gameType, $date);
                if ($r !== null) {
                    $responses[$date] = $r;
                }
            }

            $throttled = [];
            foreach ($responses as $date => $response) {
                if ($throttled !== []) {
                    // KLUCZOWE: niezużyta odpowiedź 4xx rzuca wyjątek w destruktorze
                    // Symfony HttpClient i wywraca cały proces. Musi zostać anulowana.
                    $this->cancelQuietly($response);
                    $throttled[] = $date;
                    continue;
                }

                $result = $this->lottoApiClient->resolveDrawsResponse($response, $gameType);

                if ($result['rate_limited']) {
                    $this->cancelQuietly($response);
                    $throttled[] = $date;
                    continue;
                }

                // Zapisujemy też pustą listę: "sprawdzone, brak losowania tego dnia".
                // Dzięki temu nie odpytujemy tej daty ponownie przy każdym uruchomieniu.
                if ($result['ok']) {
                    $store[$date] = $result['draws'];
                    $fetched++;
                    if ($onProgress !== null) {
                        $onProgress($fetched, $total);
                    }
                }
            }

            if ($throttled !== []) {
                // 429 to prośba o zwolnienie, a nie koniec pracy: daty wracają
                // do kolejki po wycofaniu. Dawna wersja przerywała cały przebieg,
                // przez co historia głębsza niż kilkadziesiąt losowań nigdy nie
                // powstawała.
                $retries++;

                if ($retries > self::MAX_RATE_LIMIT_RETRIES) {
                    $rateLimited = true;
                    $this->logger->warning('LOTTO API: limit zapytań (429) mimo wycofań. Przerywam dociąganie historii.', [
                        'game' => $gameType,
                        'fetched_before_limit' => $fetched,
                    ]);
                    break;
                }

                $this->logger->info('LOTTO API: HTTP 429, wycofanie i ponowienie paczki.', [
                    'game' => $gameType,
                    'attempt' => $retries,
                    'dates' => count($throttled),
                ]);

                usleep($this->rateLimitBackoffUs * (2 ** ($retries - 1)));
                $queue = array_merge($throttled, $queue);
                continue;
            }

            $retries = 0;

            if ($queue !== []) {
                usleep(self::REQUEST_DELAY_US);
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
     * Buduje historię WSZYSTKICH podanych gier jednym przebiegiem po kalendarzu.
     *
     * Endpoint `by-date` zwraca komplet losowań z danego dnia, więc jedno
     * zapytanie zasila naraz Lotto, MiniLotto, Multi Multi i resztę. Dla kogoś,
     * kto gra w kilka gier, jest to jedyny sensowny sposób zbudowania głębokiej
     * historii: osobne `by-date-per-game` kosztowałoby tyle zapytań, ile gier.
     *
     * @param list<string> $games
     * @param callable|null $onProgress fn(int $done, int $total, string $date)
     *
     * @return array{dates_checked: int, dates_fetched: int, rate_limited: bool, per_game: array<string, int>}
     */
    public function backfillAllGames(int $days, array $games, ?callable $onProgress = null): array
    {
        $days = max(1, min($days, self::MAX_LIMIT));
        $games = array_values(array_unique(array_filter($games, [$this->gameRegistryService, 'isValidGame'])));

        if ($games === []) {
            return ['dates_checked' => 0, 'dates_fetched' => 0, 'rate_limited' => false, 'per_game' => []];
        }

        $stores = [];
        foreach ($games as $game) {
            $stores[$game] = $this->load($game);
        }

        // Data jest do pobrania, jeżeli BRAKUJE jej w archiwum choć jednej gry.
        $queue = [];
        $cursor = new \DateTimeImmutable('today');
        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->modify("-$i day")->format('Y-m-d');
            foreach ($games as $game) {
                if (!array_key_exists($date, $stores[$game])) {
                    $queue[] = $date;
                    break;
                }
            }
        }

        $total = count($queue);
        $done = 0;
        $fetched = 0;
        $rateLimited = false;
        $retries = 0;
        $perGame = array_fill_keys($games, 0);

        while ($queue !== [] && !$rateLimited) {
            $batch = array_splice($queue, 0, self::REQUEST_CONCURRENCY);

            $responses = [];
            foreach ($batch as $date) {
                $r = $this->lottoApiClient->requestAllGamesForDate($date);
                if ($r === null) {
                    // Brak klucza API — dalsze próby nie mają sensu.
                    return [
                        'dates_checked' => $total,
                        'dates_fetched' => $fetched,
                        'rate_limited' => false,
                        'per_game' => $perGame,
                    ];
                }
                $responses[$date] = $r;
            }

            $throttled = [];
            foreach ($responses as $date => $response) {
                if ($throttled !== []) {
                    $this->cancelQuietly($response);
                    $throttled[] = $date;
                    continue;
                }

                $result = $this->lottoApiClient->resolveAllGamesResponse($response);

                if ($result['rate_limited']) {
                    $this->cancelQuietly($response);
                    $throttled[] = $date;
                    continue;
                }

                if (!$result['ok']) {
                    continue;
                }

                foreach ($games as $game) {
                    $draws = $result['by_game'][$game] ?? [];
                    $stores[$game][$date] = $draws;
                    $perGame[$game] += count($draws);
                }

                $fetched++;
                $done++;

                if ($onProgress !== null) {
                    $onProgress($done, $total, $date);
                }
            }

            if ($throttled !== []) {
                $retries++;

                if ($retries > self::MAX_RATE_LIMIT_RETRIES) {
                    $rateLimited = true;
                    break;
                }

                usleep($this->rateLimitBackoffUs * (2 ** ($retries - 1)));
                $queue = array_merge($throttled, $queue);
                continue;
            }

            $retries = 0;

            // Zapis co paczkę: backfill setek dni bywa przerywany, a stracona
            // praca oznacza ponowne odpytywanie API o te same daty.
            foreach ($games as $game) {
                $this->save($game, $stores[$game]);
            }

            if ($queue !== []) {
                usleep(self::REQUEST_DELAY_US);
            }
        }

        foreach ($games as $game) {
            $this->save($game, $stores[$game]);
        }

        return [
            'dates_checked' => $total,
            'dates_fetched' => $fetched,
            'rate_limited' => $rateLimited,
            'per_game' => $perGame,
        ];
    }

    /**
     * Ile losowań na datę zwracała dawna, zbyt mała strona wyników.
     *
     * Dopóki zapytanie szło z `size=50`, gry o wielu losowaniach dziennie
     * (Keno, Szybkie 600 — po ponad 250) zapisywały się w cache'u obcięte do 50,
     * a data zostawała oznaczona jako sprawdzona i nigdy już nie była pobierana.
     */
    private const LEGACY_TRUNCATED_PAGE_SIZE = 50;

    /**
     * Zapomina daty, które wyglądają na obcięte przez dawny rozmiar strony,
     * żeby zwykła ścieżka pobierania mogła je uzupełnić.
     *
     * Data z dokładnie 50 losowaniami przy grze, która miewa ich więcej, jest
     * niemal na pewno pozostałością po `size=50`. Ponowne pobranie takiej daty
     * kosztuje jedno zapytanie i w najgorszym razie zapisze te same 50 losowań.
     */
    public function forgetTruncatedDates(string $gameType): int
    {
        $store = $this->load($gameType);

        $maxSeen = 0;
        foreach ($store as $entries) {
            if (is_array($entries)) {
                $maxSeen = max($maxSeen, count($entries));
            }
        }

        if ($maxSeen <= self::LEGACY_TRUNCATED_PAGE_SIZE) {
            return 0;
        }

        $forgotten = 0;
        foreach ($store as $date => $entries) {
            if (is_array($entries) && count($entries) === self::LEGACY_TRUNCATED_PAGE_SIZE) {
                unset($store[$date]);
                $forgotten++;
            }
        }

        if ($forgotten > 0) {
            $this->save($gameType, $store);
            $this->logger->info('Odrzucono daty obcięte przez dawny rozmiar strony wyników', [
                'game' => $gameType,
                'dates' => $forgotten,
            ]);
        }

        return $forgotten;
    }

    /**
     * Surowy magazyn kluczowany datą: {"2026-09-01": [{main, special, id}, ...]}.
     *
     * Potrzebny DrawArchiveService, który rzutuje go na listę chronologiczną.
     * Sam getHistory() zwraca już spłaszczone losowania bez dat, więc nie da się
     * z niego odtworzyć porządku wymaganego przez kroczenie.
     *
     * @return array<string, array<int, array{main: array<int>, special: array<int>, id?: ?int}>>
     */
    public function getDatedStore(string $gameType): array
    {
        return $this->load($gameType);
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
