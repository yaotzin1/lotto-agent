<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Serwis zarządzający równomiernym rozkładem dekadowym (Decade Balance).
 *
 * Dzieli zakres gry (1..maxNumber) na dekady (1-10, 11-20...) i wylicza
 * zbalansowane kwoty na każdą dekadę, uwzględniając niepełne dekady
 * (np. 41-42 w Mini Lotto, 31-35 w Ekstra Pensji, 41-49 w Lotto).
 */
class DecadeDistributionService
{
    public function __construct(
        private readonly ?GameRegistryService $gameRegistryService = null
    ) {
    }

    /**
     * Zwraca definicje wszystkich dekad dla danego zakresu liczb gry.
     *
     * @return array<int, array{
     *     index: int,
     *     start: int,
     *     end: int,
     *     label: string,
     *     capacity: int,
     *     numbers: list<int>
     * }>
     */
    public function getDecadesForGame(int $maxNumber): array
    {
        if ($maxNumber <= 0) {
            return [];
        }

        $decadesAvailable = (int) ceil($maxNumber / 10);
        $decades = [];

        for ($i = 0; $i < $decadesAvailable; $i++) {
            $start = $i * 10 + 1;
            $end = min(($i + 1) * 10, $maxNumber);
            $numbers = range($start, $end);

            $decades[$i] = [
                'index' => $i,
                'start' => $start,
                'end' => $end,
                'label' => sprintf('%d-%d', $start, $end),
                'capacity' => count($numbers),
                'numbers' => $numbers,
            ];
        }

        return $decades;
    }

    /**
     * Wylicza liczbę reprezentantów (kwotę) dla każdej dekady.
     *
     * Gwarantuje:
     * - Jeśli $poolSize >= liczba dekad: każda dekada z niezerową pojemnością
     *   otrzyma co najmniej 1 liczbę (o ile $poolSize na to pozwala).
     * - Kwota dekady nigdy nie przekracza jej fizycznej pojemności (np. dla 41-42 maks. 2).
     * - Nadmiar/reszta jest rozdzielana na dekady o najwyższej łącznej częstotliwości.
     *
     * @param array<int, int> $frequencies Mapa [liczba => wystąpienia]
     * @return array<int, int> Mapa [indeks_dekady => kwota]
     */
    public function calculateDecadeQuotas(int $poolSize, int $maxNumber, array $frequencies = []): array
    {
        $decades = $this->getDecadesForGame($maxNumber);
        $decadesCount = count($decades);

        if ($decadesCount === 0 || $poolSize <= 0) {
            return [];
        }

        $totalCapacity = $maxNumber;
        $targetPoolSize = min($poolSize, $totalCapacity);

        $quotas = array_fill(0, $decadesCount, 0);

        // Oblicz aktywność (wagę) każdej dekady na podstawie częstotliwości
        $decadeWeights = [];
        foreach ($decades as $idx => $d) {
            $weight = 0;
            foreach ($d['numbers'] as $n) {
                $weight += $frequencies[$n] ?? 0;
            }
            $decadeWeights[$idx] = $weight;
        }

        // Przypadek specjalny: pula mniejsza niż liczba dekad
        if ($targetPoolSize < $decadesCount) {
            // Wybierz $targetPoolSize dekad o najwyższej wadze (lub kolejno, jeśli wagi równe)
            $indices = array_keys($decades);
            usort($indices, static fn(int $a, int $b): int => $decadeWeights[$b] <=> $decadeWeights[$a]);

            for ($k = 0; $k < $targetPoolSize; $k++) {
                $quotas[$indices[$k]] = 1;
            }

            return $quotas;
        }

        // Krok 1: Wstępne przydzielenie po 1 liczbie do każdej dekady
        $remaining = $targetPoolSize;
        for ($i = 0; $i < $decadesCount; $i++) {
            if ($decades[$i]['capacity'] >= 1) {
                $quotas[$i] = 1;
                $remaining--;
            }
        }

        // Krok 2: Równomierny i ważony rozkład pozostałych miejsc
        while ($remaining > 0) {
            // Znajdź dekady, które nie osiągnęły limitu pojemności
            $minQuota = PHP_INT_MAX;
            $candidates = [];

            for ($i = 0; $i < $decadesCount; $i++) {
                if ($quotas[$i] < $decades[$i]['capacity']) {
                    if ($quotas[$i] < $minQuota) {
                        $minQuota = $quotas[$i];
                        $candidates = [$i];
                    } elseif ($quotas[$i] === $minQuota) {
                        $candidates[] = $i;
                    }
                }
            }

            if (empty($candidates)) {
                break; // Wszystkie dekady osiągnęły pełną pojemność
            }

            // Posortuj kandydatów o tym samym minQuota według wagi częstotliwości
            usort($candidates, static fn(int $a, int $b): int => $decadeWeights[$b] <=> $decadeWeights[$a]);

            foreach ($candidates as $candIdx) {
                $quotas[$candIdx]++;
                $remaining--;
                if ($remaining === 0) {
                    break;
                }
            }
        }

        return $quotas;
    }

    /**
     * Generuje zbalansowaną pulę liczb z pokryciem wszystkich dekad.
     *
     * @param array<int, int> $frequencies Mapa [liczba => wystąpienia]
     * @param string $strategy 'hot' (najczęstsze w dekadzie), 'balanced' (miks hot/cold), 'random'
     * @param list<int> $anchors Ostatnie liczby wygrane (kotwice) używane do wyznaczenia sąsiadów ±1
     * @param bool $withNeighbours Czy priorytetyzować sąsiadów (±1) kotwic w ramach kwot dekad
     * @return array{
     *     pool: list<int>,
     *     quotas: array<int, int>,
     *     breakdown: array<int, array{
     *         index: int,
     *         label: string,
     *         start: int,
     *         end: int,
     *         capacity: int,
     *         quota: int,
     *         selected: list<int>,
     *         neighbours_selected: list<int>
     *     }>,
     *     is_all_decades_covered: bool,
     *     decades_count: int,
     *     decades_covered: int,
     *     with_neighbours: bool,
     *     neighbours_count: int,
     *     anchors_used: list<int>
     * }
     */
    public function generateDecadePool(
        int $maxNumber,
        int $poolSize,
        array $frequencies = [],
        string $strategy = 'hot',
        array $anchors = [],
        bool $withNeighbours = false
    ): array {
        $decades = $this->getDecadesForGame($maxNumber);
        $quotas = $this->calculateDecadeQuotas($poolSize, $maxNumber, $frequencies);

        // Wyznacz sąsiadów ±1 kotwic, jeśli włączono opcję withNeighbours
        $anchorsUsed = [];
        $neighbourNumbers = [];
        if ($withNeighbours) {
            $validAnchors = array_values(array_filter(
                $anchors,
                static fn($n): bool => is_int($n) && $n >= 1 && $n <= $maxNumber
            ));

            // Jeśli nie podano kotwic, pobierz z najgorętszych liczb z częstotliwości
            if (empty($validAnchors) && !empty($frequencies)) {
                $freqCopy = $frequencies;
                arsort($freqCopy);
                $validAnchors = array_slice(array_keys($freqCopy), 0, min(8, $maxNumber));
            }

            $anchorsUsed = $validAnchors;
            foreach ($anchorsUsed as $anchor) {
                if ($anchor - 1 >= 1) {
                    $neighbourNumbers[] = $anchor - 1;
                }
                if ($anchor + 1 <= $maxNumber) {
                    $neighbourNumbers[] = $anchor + 1;
                }
            }
            $neighbourNumbers = array_values(array_unique($neighbourNumbers));
        }

        $selectedPool = [];
        $breakdown = [];
        $coveredCount = 0;

        foreach ($decades as $idx => $d) {
            $quota = $quotas[$idx] ?? 0;
            $numbers = $d['numbers'];
            $selectedInDecade = [];

            if ($quota > 0) {
                $coveredCount++;

                if ($quota >= count($numbers)) {
                    $selectedInDecade = $numbers;
                } elseif ($withNeighbours && !empty($neighbourNumbers)) {
                    // Wybierz najpierw sąsiadów znajdujących się w tej dekadzie
                    $decadeNeighbours = array_values(array_intersect($numbers, $neighbourNumbers));
                    $selectedNeighbours = $this->selectNumbersByStrategy(
                        $decadeNeighbours,
                        $quota,
                        $frequencies,
                        $strategy
                    );

                    $neededMore = $quota - count($selectedNeighbours);
                    if ($neededMore > 0) {
                        $remaining = array_values(array_diff($numbers, $selectedNeighbours));
                        $additional = $this->selectNumbersByStrategy(
                            $remaining,
                            $neededMore,
                            $frequencies,
                            $strategy
                        );
                        $selectedInDecade = array_merge($selectedNeighbours, $additional);
                    } else {
                        $selectedInDecade = $selectedNeighbours;
                    }
                } else {
                    $selectedInDecade = $this->selectNumbersByStrategy(
                        $numbers,
                        $quota,
                        $frequencies,
                        $strategy
                    );
                }

                sort($selectedInDecade);
                $selectedPool = array_merge($selectedPool, $selectedInDecade);
            }

            $breakdown[$idx] = [
                'index' => $idx,
                'label' => $d['label'],
                'start' => $d['start'],
                'end' => $d['end'],
                'capacity' => $d['capacity'],
                'quota' => $quota,
                'selected' => $selectedInDecade,
                'neighbours_selected' => array_values(array_intersect($selectedInDecade, $neighbourNumbers)),
            ];
        }

        sort($selectedPool);

        $totalDecades = count($decades);
        $isAllCovered = ($coveredCount === $totalDecades && $totalDecades > 0);

        return [
            'pool' => array_values(array_unique($selectedPool)),
            'quotas' => $quotas,
            'breakdown' => $breakdown,
            'is_all_decades_covered' => $isAllCovered,
            'decades_count' => $totalDecades,
            'decades_covered' => $coveredCount,
            'with_neighbours' => $withNeighbours,
            'neighbours_count' => count(array_intersect($selectedPool, $neighbourNumbers)),
            'anchors_used' => $anchorsUsed,
        ];
    }

    /**
     * Dobiera określoną liczbę elementów z listy według podanej strategii.
     *
     * @param list<int> $numbers
     * @param array<int, int> $frequencies
     * @return list<int>
     */
    private function selectNumbersByStrategy(
        array $numbers,
        int $count,
        array $frequencies,
        string $strategy
    ): array {
        if ($count <= 0 || empty($numbers)) {
            return [];
        }

        if ($count >= count($numbers)) {
            return $numbers;
        }

        if ($strategy === 'hot' && !empty($frequencies)) {
            usort($numbers, static function (int $a, int $b) use ($frequencies): int {
                $fA = $frequencies[$a] ?? 0;
                $fB = $frequencies[$b] ?? 0;
                if ($fA !== $fB) {
                    return $fB <=> $fA;
                }
                return $a <=> $b;
            });

            return array_slice($numbers, 0, $count);
        }

        if ($strategy === 'balanced' && !empty($frequencies)) {
            $hotTarget = max(1, (int) ceil($count * 0.6));
            $coldTarget = $count - $hotTarget;

            usort($numbers, static function (int $a, int $b) use ($frequencies): int {
                $fA = $frequencies[$a] ?? 0;
                $fB = $frequencies[$b] ?? 0;
                if ($fA !== $fB) {
                    return $fB <=> $fA;
                }
                return $a <=> $b;
            });

            $hotSelected = array_slice($numbers, 0, $hotTarget);
            $remainingNumbers = array_slice($numbers, $hotTarget);

            usort($remainingNumbers, static function (int $a, int $b) use ($frequencies): int {
                $fA = $frequencies[$a] ?? 0;
                $fB = $frequencies[$b] ?? 0;
                if ($fA !== $fB) {
                    return $fA <=> $fB;
                }
                return $a <=> $b;
            });

            $coldSelected = array_slice($remainingNumbers, 0, $coldTarget);

            return array_merge($hotSelected, $coldSelected);
        }

        // Random
        shuffle($numbers);

        return array_slice($numbers, 0, $count);
    }

    /**
     * Weryfikuje pokrycie dekad dla dowolnej podanej puli liczb.
     *
     * @param list<int> $pool
     * @return array{
     *     decades_available: int,
     *     decades_covered: int,
     *     is_fully_covered: bool,
     *     distribution: array<string, int>,
     *     empty_decades: list<string>
     * }
     */
    public function validateDecadeCoverage(array $pool, int $maxNumber): array
    {
        $decades = $this->getDecadesForGame($maxNumber);
        $distribution = [];
        $emptyDecades = [];
        $coveredCount = 0;

        foreach ($decades as $d) {
            $label = $d['label'];
            $count = 0;
            foreach ($pool as $num) {
                if ($num >= $d['start'] && $num <= $d['end']) {
                    $count++;
                }
            }
            $distribution[$label] = $count;
            if ($count > 0) {
                $coveredCount++;
            } else {
                $emptyDecades[] = $label;
            }
        }

        $totalDecades = count($decades);

        return [
            'decades_available' => $totalDecades,
            'decades_covered' => $coveredCount,
            'is_fully_covered' => ($coveredCount === $totalDecades && $totalDecades > 0),
            'distribution' => $distribution,
            'empty_decades' => $emptyDecades,
        ];
    }
}
