<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chronologiczne archiwum losowań — most między dwiema historiami, które do tej
 * pory żyły osobno i nigdy się nie spotykały:
 *
 *  1. `data/lotto_draws.json` — lista chronologiczna (7 399 losowań Lotto od 1957),
 *     budowana RĘCZNIE przez `scripts/parse_history.php` z wklejonego pliku
 *     tekstowego. Tylko stąd czerpał generator Stride, więc plik z definicji był
 *     nieświeży, a kotwice T-N liczone od jego ostatniego wiersza po cichu
 *     przesuwały się o tyle losowań, o ile plik był do tyłu.
 *
 *  2. `var/draw-history/<Gra>.json` — cache LOTTO OpenAPI kluczowany datą,
 *     dociągany przyrostowo przez DrawHistoryProvider. Świeży, ale bez indeksu
 *     porządkowego, więc sam w sobie bezużyteczny dla kroczenia "co N losowań".
 *
 * Ta klasa rzutuje (2) na kształt (1), scala oba źródła i dopisuje nowe losowania
 * do archiwum na dysku. Stride dostaje dzięki temu jeden wspólny ciąg losowań,
 * domykany z oficjalnego API — dla KAŻDEJ gry, nie tylko dla Lotto.
 *
 * Dlaczego samo API nie wystarczy: OpenAPI nie ma endpointu z zakresem dat dla
 * wyników (jest wyłącznie `by-date-per-game` dla POJEDYNCZEJ daty), a
 * DrawHistoryProvider świadomie dociąga maks. 25 dat na uruchomienie, żeby nie
 * dostać HTTP 429. Kroczenie N=257 z trzema kotwicami sięga ok. 771 losowań
 * wstecz — zbudowanie tego wyłącznie z API to około 31 przebiegów.
 */
class DrawArchiveService
{
    /**
     * Historyczny plik Lotto zostaje pod starą nazwą — jest w repozytorium
     * i odwołują się do niego README oraz scripts/. Pozostałe gry dostają
     * własny plik w data/draws/.
     */
    private const LEGACY_LOTTO_FILE = 'lotto_draws.json';

    /** Ile ostatnich dat losowań próbujemy domknąć z API przy odświeżaniu. */
    private const DEFAULT_REFRESH_SESSIONS = 60;

    /** @var array<string, list<array{date: string, numbers: list<int>}>> */
    private array $memory = [];

    public function __construct(
        private readonly DrawHistoryProvider $drawHistoryProvider,
        private readonly GameRegistryService $gameRegistryService,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/data')]
        private readonly string $dataDir = ''
    ) {
    }

    /**
     * Pełny, chronologiczny (rosnąco po dacie) ciąg losowań danej gry.
     *
     * Scala plik archiwum z cache'em API. Jeśli cache zawierał losowania,
     * których nie było w archiwum, archiwum jest od razu dopisywane na dysk —
     * następny przebieg zaczyna już od kompletu.
     *
     * @return list<array{date: string, numbers: list<int>}>
     */
    public function getChronology(string $gameType): array
    {
        if (isset($this->memory[$gameType])) {
            return $this->memory[$gameType];
        }

        $archived = $this->readArchive($gameType);
        $projected = $this->projectApiCache($gameType);

        [$merged, $added] = $this->merge($archived, $projected);

        if ($added > 0) {
            $this->writeArchive($gameType, $merged);
            $this->logger->info('Archiwum losowań uzupełnione z cache LOTTO OpenAPI', [
                'game' => $gameType,
                'added' => $added,
            ]);
        }

        $this->memory[$gameType] = $merged;

        return $merged;
    }

    /**
     * Dociąga brakujące daty z oficjalnego LOTTO OpenAPI i scala je z archiwum.
     *
     * Nigdy nie rzuca wyjątkiem: brak klucza API albo HTTP 429 to powód do
     * ostrzeżenia, a nie do przewrócenia generatora — archiwum z dysku nadal
     * wystarcza do zbudowania puli.
     *
     * @return array{added: int, fetched: int, from_cache: int, rate_limited: bool, warning: ?string}
     */
    public function refresh(string $gameType, ?int $sessions = null): array
    {
        $sessions = max(1, $sessions ?? self::DEFAULT_REFRESH_SESSIONS);

        $before = count($this->getChronology($gameType));
        $fetched = 0;
        $fromCache = 0;
        $rateLimited = false;
        $warning = null;

        try {
            $history = $this->drawHistoryProvider->getHistory($gameType, $sessions);
            $fetched = $history['fetched'];
            $fromCache = $history['from_cache'];
            $rateLimited = $history['rate_limited'];

            if ($rateLimited) {
                $warning = 'LOTTO OpenAPI odpowiedziało HTTP 429 (limit zapytań). Archiwum zostało '
                    . 'uzupełnione tylko częściowo — uruchom komendę ponownie za jakiś czas.';
            }
        } catch (\Throwable $e) {
            $warning = 'Nie udało się odświeżyć archiwum z LOTTO OpenAPI: ' . $e->getMessage();
            $this->logger->warning('Odświeżanie archiwum losowań nie powiodło się', [
                'game' => $gameType,
                'error' => $e->getMessage(),
            ]);
        }

        // Cache mógł się zmienić, więc projekcja musi policzyć się od nowa.
        unset($this->memory[$gameType]);
        $after = count($this->getChronology($gameType));

        return [
            'added' => max(0, $after - $before),
            'fetched' => $fetched,
            'from_cache' => $fromCache,
            'rate_limited' => $rateLimited,
            'warning' => $warning,
        ];
    }

    /**
     * Jednorazowe zbudowanie GŁĘBOKIEJ historii jednej gry.
     *
     * refresh() ma świadomie mały budżet zapytań, żeby generator nie czekał.
     * Backfill jest przeciwieństwem: wolno mu odpytać API o setki dat, bo
     * uruchamia się go raz, świadomie. Wynik ląduje w cache'u i w archiwum,
     * więc przerwany przebieg da się po prostu powtórzyć.
     *
     * @param callable|null $onProgress fn(int $done, int $total)
     *
     * @return array{added: int, fetched: int, rate_limited: bool, total: int}
     */
    public function backfill(string $gameType, int $dates, ?callable $onProgress = null): array
    {
        $dates = max(1, $dates);
        $before = count($this->getChronology($gameType));

        $history = $this->drawHistoryProvider->getHistory($gameType, $dates, $dates, $onProgress);

        unset($this->memory[$gameType]);
        $after = $this->getChronology($gameType);

        return [
            'added' => max(0, count($after) - $before),
            'fetched' => $history['fetched'],
            'rate_limited' => $history['rate_limited'],
            'total' => count($after),
        ];
    }

    /**
     * Ponownie pobiera daty, które zapisały się niekompletne.
     *
     * @param callable|null $onProgress fn(int $done, int $total)
     *
     * @return array{forgotten: int, added: int, fetched: int, rate_limited: bool, total: int}
     */
    public function repair(string $gameType, int $dates, ?callable $onProgress = null): array
    {
        $forgotten = $this->drawHistoryProvider->forgetTruncatedDates($gameType);
        unset($this->memory[$gameType]);

        $result = $this->backfill($gameType, $dates, $onProgress);

        return [
            'forgotten' => $forgotten,
            'added' => $result['added'],
            'fetched' => $result['fetched'],
            'rate_limited' => $result['rate_limited'],
            'total' => $result['total'],
        ];
    }

    /**
     * Backfill wielu gier jednym przebiegiem po kalendarzu (endpoint `by-date`).
     *
     * @param list<string> $games
     * @param callable|null $onProgress fn(int $done, int $total, string $date)
     *
     * @return array{dates_fetched: int, rate_limited: bool, totals: array<string, int>, added: array<string, int>}
     */
    public function backfillAllGames(array $games, int $days, ?callable $onProgress = null): array
    {
        $before = [];
        foreach ($games as $game) {
            $before[$game] = count($this->getChronology($game));
        }

        $result = $this->drawHistoryProvider->backfillAllGames($days, $games, $onProgress);

        $totals = [];
        $added = [];
        foreach ($games as $game) {
            unset($this->memory[$game]);
            $totals[$game] = count($this->getChronology($game));
            $added[$game] = max(0, $totals[$game] - ($before[$game] ?? 0));
        }

        return [
            'dates_fetched' => $result['dates_fetched'],
            'rate_limited' => $result['rate_limited'],
            'totals' => $totals,
            'added' => $added,
        ];
    }

    /**
     * Czy archiwum nadąża za kalendarzem losowań.
     *
     * Kroczenie adresuje losowania POZYCJĄ (T-N, T-2N...), więc każde brakujące
     * losowanie przesuwa wszystkie kotwice. Bez tego sygnału błąd był niewidoczny.
     *
     * Dzień dzisiejszy celowo nie wchodzi do oczekiwań: losowania odbywają się
     * wieczorem, więc archiwum zaktualizowane rano nie może być uznane za
     * przeterminowane tylko dlatego, że dziś wypada dzień losowania.
     *
     * @return array{last_date: ?string, expected_date: ?string, missing: int, stale: bool, total: int}
     */
    public function freshness(string $gameType): array
    {
        $chronology = $this->getChronology($gameType);
        $total = count($chronology);
        $lastDate = $total > 0 ? $chronology[$total - 1]['date'] : null;
        $expected = $this->lastExpectedDrawDate($gameType);

        if ($lastDate === null || $expected === null) {
            return [
                'last_date' => $lastDate,
                'expected_date' => $expected,
                'missing' => 0,
                'stale' => $lastDate === null,
                'total' => $total,
            ];
        }

        $missing = $this->countDrawDaysBetween($gameType, $lastDate, $expected);

        return [
            'last_date' => $lastDate,
            'expected_date' => $expected,
            'missing' => $missing,
            'stale' => $missing > 0,
            'total' => $total,
        ];
    }

    /**
     * Ostatni dzień losowania danej gry, nie licząc dnia dzisiejszego.
     */
    private function lastExpectedDrawDate(string $gameType): ?string
    {
        $drawDays = $this->drawDays($gameType);
        if ($drawDays === []) {
            return null;
        }

        $cursor = new \DateTimeImmutable('yesterday');
        for ($guard = 0; $guard < 14; $guard++) {
            if (in_array((int) $cursor->format('N'), $drawDays, true)) {
                return $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('-1 day');
        }

        return null;
    }

    /**
     * Ile dni losowań wypadło po $after, ale nie później niż $until.
     */
    private function countDrawDaysBetween(string $gameType, string $after, string $until): int
    {
        $drawDays = $this->drawDays($gameType);
        if ($drawDays === [] || $after >= $until) {
            return 0;
        }

        try {
            $cursor = (new \DateTimeImmutable($after))->modify('+1 day');
            $end = new \DateTimeImmutable($until);
        } catch (\Throwable) {
            return 0;
        }

        $missing = 0;
        $guard = 0;
        while ($cursor <= $end && $guard++ < 5000) {
            if (in_array((int) $cursor->format('N'), $drawDays, true)) {
                $missing++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $missing;
    }

    /**
     * @return list<int>
     */
    private function drawDays(string $gameType): array
    {
        try {
            $config = $this->gameRegistryService->getGameConfig($gameType);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map('intval', $config['draw_days'] ?? []));
    }

    /**
     * Rzutuje cache API (klucz = data, wartość = lista losowań tego dnia) na
     * płaską listę chronologiczną.
     *
     * W obrębie jednej daty losowania są porządkowane po `drawSystemId` — Multi
     * Multi ma kilkanaście losowań dziennie, a zapytanie do API idzie z
     * `order=DESC`, więc kolejność w cache'u bywa odwrócona.
     *
     * @return list<array{date: string, numbers: list<int>}>
     */
    private function projectApiCache(string $gameType): array
    {
        $store = $this->drawHistoryProvider->getDatedStore($gameType);
        if ($store === []) {
            return [];
        }

        $maxNumber = $this->maxNumber($gameType);
        ksort($store);

        $projected = [];
        foreach ($store as $date => $entries) {
            if (!is_string($date) || !is_array($entries) || $entries === []) {
                continue;
            }

            $entries = array_values(array_filter($entries, 'is_array'));
            $this->sortDayById($entries);

            foreach ($entries as $entry) {
                $numbers = $this->cleanNumbers(
                    is_array($entry['main'] ?? null) ? $entry['main'] : [],
                    $maxNumber
                );

                if (count($numbers) < 2) {
                    continue;
                }

                $projected[] = ['date' => $date, 'numbers' => $numbers];
            }
        }

        return $projected;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function sortDayById(array &$entries): void
    {
        foreach ($entries as $entry) {
            if (!isset($entry['id']) || !is_int($entry['id'])) {
                // Starsze wpisy cache'u nie mają identyfikatora losowania —
                // zostawiamy je w kolejności zapisu, zgadywanie byłoby gorsze.
                return;
            }
        }

        usort($entries, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    }

    /**
     * @param list<array{date: string, numbers: list<int>}> $archived
     * @param list<array{date: string, numbers: list<int>}> $projected
     * @return array{0: list<array{date: string, numbers: list<int>}>, 1: int}
     */
    private function merge(array $archived, array $projected): array
    {
        $byDate = [];
        foreach ($archived as $draw) {
            $byDate[$draw['date']][] = $draw;
        }

        $projectedByDate = [];
        foreach ($projected as $draw) {
            $projectedByDate[$draw['date']][] = $draw;
        }

        $added = 0;

        foreach ($projectedByDate as $date => $rows) {
            $existing = $byDate[$date] ?? [];

            if (count($rows) >= count($existing)) {
                // Dla dat, które API zna, jest ono źródłem prawdy — i to naprawia
                // dane obcięte przez dawne `size=50` (Keno ma po 261 losowań
                // dziennie, w archiwum zostawało 50). Podmiana, a nie dopisanie,
                // przywraca też właściwą kolejność losowań w obrębie dnia.
                $added += count($rows) - count($existing);
                $byDate[$date] = $rows;
                continue;
            }

            // Archiwum ma więcej niż API (np. ręczny zasiew historii) — wtedy
            // tylko dopełniamy brakujące losowania.
            $seen = [];
            foreach ($existing as $draw) {
                $seen[$this->signature($draw)] = true;
            }

            foreach ($rows as $draw) {
                if (isset($seen[$this->signature($draw)])) {
                    continue;
                }

                $seen[$this->signature($draw)] = true;
                $byDate[$date][] = $draw;
                $added++;
            }
        }

        ksort($byDate);

        $merged = [];
        foreach ($byDate as $rows) {
            foreach ($rows as $draw) {
                $merged[] = $draw;
            }
        }

        return [$merged, $added];
    }

    /**
     * @param array{date: string, numbers: list<int>} $draw
     */
    private function signature(array $draw): string
    {
        return $draw['date'] . '|' . implode(',', $draw['numbers']);
    }

    /**
     * @return list<array{date: string, numbers: list<int>}>
     */
    private function readArchive(string $gameType): array
    {
        $file = $this->fileFor($gameType);
        if (!is_readable($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return [];
        }

        $maxNumber = $this->maxNumber($gameType);
        $draws = [];

        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['date'], $row['numbers']) || !is_array($row['numbers'])) {
                continue;
            }

            $numbers = $this->cleanNumbers($row['numbers'], $maxNumber);
            if (count($numbers) < 2) {
                continue;
            }

            $draws[] = ['date' => (string) $row['date'], 'numbers' => $numbers];
        }

        return $draws;
    }

    /**
     * @param list<array{date: string, numbers: list<int>}> $draws
     */
    private function writeArchive(string $gameType, array $draws): void
    {
        $file = $this->fileFor($gameType);

        try {
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }

            file_put_contents($file, json_encode($draws, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Brak zapisu nie może wywrócić generatora — dane są już w pamięci.
            $this->logger->warning('Nie udało się zapisać archiwum losowań', [
                'game' => $gameType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function fileFor(string $gameType): string
    {
        $base = $this->dataDir !== '' ? $this->dataDir : dirname(__DIR__, 2) . '/data';

        if ($gameType === 'Lotto') {
            return $base . '/' . self::LEGACY_LOTTO_FILE;
        }

        $safe = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $gameType);

        return $base . '/draws/' . $safe . '.json';
    }

    private function maxNumber(string $gameType): int
    {
        try {
            return (int) ($this->gameRegistryService->getGameConfig($gameType)['from'] ?? 49);
        } catch (\Throwable) {
            return 49;
        }
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private function cleanNumbers(array $values, int $maxNumber): array
    {
        $clean = [];
        foreach ($values as $value) {
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                continue;
            }

            $number = (int) $value;
            if ($number >= 1 && $number <= $maxNumber) {
                $clean[] = $number;
            }
        }

        $clean = array_values(array_unique($clean));
        sort($clean);

        return $clean;
    }
}
