<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class LottoApiClient
{
    private const LOTTO_API_BASE = 'https://developers.lotto.pl/api/open/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly GameRegistryService $gameRegistryService,
        #[Autowire('%lotto_api_key%')]
        private readonly string $lottoApiKey
    ) {
    }

    private ?LottoApiException $lastError = null;

    /**
     * Ostatni błąd pobierania danych, jeśli wystąpił. Pozwala komendom
     * wyświetlić wyraźne ostrzeżenie zamiast po cichu liczyć na pustych danych.
     */
    public function getLastError(): ?LottoApiException
    {
        return $this->lastError;
    }

    public function fetchStatistics(string $gameType, string $dateFrom, string $dateTo): array
    {
        $url = sprintf(
            '%s/lotteries/draw-statistics/numbers-frequency?gameType=%s&dateFrom=%s&dateTo=%s',
            self::LOTTO_API_BASE,
            $gameType,
            $dateFrom,
            $dateTo
        );

        $this->logger->info('Wysyłanie zapytania do LOTTO API', ['url' => $url]);

        if (trim($this->lottoApiKey) === '') {
            throw new LottoApiException('Brak skonfigurowanego LOTTO_API_KEY.', 401);
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'secret' => trim($this->lottoApiKey),
                    'accept' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                $this->logger->error('LOTTO API zwróciło błąd HTTP', ['status' => $status, 'url' => $url]);
                throw new LottoApiException(
                    sprintf('LOTTO API zwróciło HTTP %d dla gry %s.', $status, $gameType),
                    $status
                );
            }

            return $response->toArray();
        } catch (LottoApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->critical('Wyjątek podczas komunikacji z LOTTO API', ['error' => $e->getMessage()]);
            throw new LottoApiException($e->getMessage(), 0, $e);
        }
    }

    public function fetchStatisticsForSessions(string $gameType, int $sessions): array
    {
        $dateTo = (new \DateTime())->format('Y-m-d\TH:i:s\Z');
        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($gameType, $sessions);

        return $this->fetchStatistics($gameType, $dateFrom, $dateTo);
    }

    public function getHotAndColdNumbers(array $statsData, int $limit = 10): array
    {
        $frequencies = $statsData['numberFrequrency'] ?? [];
        usort($frequencies, fn($a, $b) => $b['numberOfOccurrences'] <=> $a['numberOfOccurrences']);

        $hotNumbers = array_slice($frequencies, 0, $limit);
        $coldNumbers = array_slice($frequencies, -$limit, $limit);

        return [
            'hot' => $hotNumbers,
            'cold' => $coldNumbers,
            'hotStr' => implode(', ', array_map(fn($n) => $n['number'] . "({$n['numberOfOccurrences']}x)", $hotNumbers)),
            'coldStr' => implode(', ', array_map(fn($n) => $n['number'] . "({$n['numberOfOccurrences']}x)", $coldNumbers)),
            'totalDraws' => $statsData['totalDraws'] ?? 'N/A',
        ];
    }

    public function getHotColdNumbers(string $gameType, string $dateFrom): ?array

    {
        $dateTo = (new \DateTime())->format('Y-m-d\TH:i:s\Z');
        $this->lastError = null;
        try {
            $data = $this->fetchStatistics($gameType, $dateFrom, $dateTo);
            $frequencies = $data['numberFrequrency'] ?? [];
            if (empty($frequencies)) {
                return null;
            }

            usort($frequencies, fn($a, $b) => $b['numberOfOccurrences'] <=> $a['numberOfOccurrences']);

            $freqDesc = [];
            foreach ($frequencies as $f) {
                $freqDesc[$f['number']] = $f['numberOfOccurrences'];
            }

            $freqAsc = $freqDesc;
            asort($freqAsc);

            // Liczby dodatkowe (EuroJackpot: 2 z 12, EkstraPensja/Premia: 1 z 4)
            $specialDesc = [];
            $special = $data['numberSpecialFrequrency'] ?? [];
            if (is_array($special) && $special !== []) {
                usort($special, fn($a, $b) => $b['numberOfOccurrences'] <=> $a['numberOfOccurrences']);
                foreach ($special as $f) {
                    $specialDesc[$f['number']] = $f['numberOfOccurrences'];
                }
            }

            return [
                'game' => $gameType,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'draws_analyzed' => $data['totalDraws'] ?? count($frequencies),
                'sorted_by_freq_desc' => $freqDesc,
                'sorted_by_freq_asc' => $freqAsc,
                'special_freq_desc' => $specialDesc,
            ];
        } catch (LottoApiException $e) {
            $this->lastError = $e;
            $this->logger->error("Błąd pobierania statystyk dla $gameType: " . $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            $this->lastError = new LottoApiException($e->getMessage(), 0, $e);
            $this->logger->error("Błąd pobierania statystyk dla $gameType: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Zleca pobranie wyników dla JEDNEJ daty losowania (nie czeka na odpowiedź).
     *
     * Rozbicie na "zleć" i "odbierz" pozwala warstwie wyżej
     * (DrawHistoryProvider) sterować równoległością i reagować na HTTP 429.
     */
    public function requestDrawsForDate(string $gameType, string $date): ?ResponseInterface
    {
        if (trim($this->lottoApiKey) === '') {
            return null;
        }

        $url = sprintf(
            '%s/lotteries/draw-results/by-date-per-game?gameType=%s&drawDate=%s&sort=drawDate&order=DESC&index=1&size=50',
            self::LOTTO_API_BASE,
            rawurlencode($gameType),
            rawurlencode($date)
        );

        try {
            return $this->httpClient->request('GET', $url, [
                'headers' => [
                    'secret' => trim($this->lottoApiKey),
                    'accept' => 'application/json',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się zlecić pobrania losowania', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Zamienia odpowiedź na losowania, rozróżniając trzy sytuacje:
     *  - ok=true            : data sprawdzona (lista może być pusta = brak losowania)
     *  - rate_limited=true  : HTTP 429, trzeba przerwać dociąganie
     *  - ok=false           : inny błąd, datę można spróbować później
     *
     * @return array{ok: bool, rate_limited: bool, draws: array<int, array{main: array<int>, special: array<int>}>}
     */
    public function resolveDrawsResponse(ResponseInterface $response, string $gameType): array
    {
        try {
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            return ['ok' => false, 'rate_limited' => false, 'draws' => []];
        }

        if ($status === 429) {
            return ['ok' => false, 'rate_limited' => true, 'draws' => []];
        }

        // 404 = tego dnia ta gra nie była losowana. To poprawna, ostateczna odpowiedź.
        if ($status === 404) {
            return ['ok' => true, 'rate_limited' => false, 'draws' => []];
        }

        if ($status !== 200) {
            return ['ok' => false, 'rate_limited' => false, 'draws' => []];
        }

        try {
            return [
                'ok' => true,
                'rate_limited' => false,
                'draws' => $this->extractDraws($response->toArray(), $gameType),
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'rate_limited' => false, 'draws' => []];
        }
    }

    /**
     * Wyciąga losowania z odpowiedzi API, tolerując kilka wariantów kształtu.
     *
     * @return array<int, array{main: array<int>, special: array<int>}>
     */
    public function extractDraws(array $payload, ?string $gameType = null): array
    {
        $items = $payload['items'] ?? $payload['content'] ?? $payload['draws'] ?? $payload;
        if (!is_array($items)) {
            return [];
        }

        $draws = [];
        $seenDrawIds = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $candidates = [];

            if (isset($item['results']) && is_array($item['results'])) {
                foreach ($item['results'] as $r) {
                    if (!is_array($r) || !isset($r['resultsJson']) || !is_array($r['resultsJson'])) {
                        continue;
                    }
                    // Jedna data zwraca wyniki kilku gier (np. Lotto i LottoPlus).
                    if ($gameType !== null && isset($r['gameType']) && $r['gameType'] !== $gameType) {
                        continue;
                    }
                    $drawId = $r['drawSystemId'] ?? null;
                    if ($drawId !== null) {
                        $sig = $gameType . ':' . $drawId;
                        if (isset($seenDrawIds[$sig])) {
                            continue;
                        }
                        $seenDrawIds[$sig] = true;
                    }
                    $candidates[] = [$r['resultsJson'], $r['specialResults'] ?? []];
                }
            }

            foreach (['resultsJson', 'numbers', 'winningNumbers'] as $key) {
                if (isset($item[$key]) && is_array($item[$key])) {
                    $candidates[] = [$item[$key], $item['specialResults'] ?? []];
                }
            }

            foreach ($candidates as $pair) {
                $clean = $this->toIntList(is_array($pair[0]) ? $pair[0] : []);
                if (count($clean) >= 2) {
                    $draws[] = [
                        'main' => $clean,
                        'special' => $this->toIntList(is_array($pair[1]) ? $pair[1] : []),
                    ];
                }
            }
        }

        return $draws;
    }

    /**
     * @return array<int>
     */
    private function toIntList(array $values): array
    {
        $clean = [];
        foreach ($values as $n) {
            if (is_int($n) || (is_string($n) && ctype_digit($n))) {
                $clean[] = (int) $n;
            }
        }
        $clean = array_values(array_unique($clean));
        sort($clean);

        return $clean;
    }

    public function getDrawResults(string $gameType, string $dateFrom, int $limit = 10): array
    {
        $dateTo = (new \DateTime())->format('Y-m-d\TH:i:s\Z');
        try {
            $stats = $this->fetchStatistics($gameType, $dateFrom, $dateTo);
            $freqs = $stats['numberFrequrency'] ?? [];
            return [
                'game' => $gameType,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'sample_draw_numbers_summary' => array_slice($freqs, 0, $limit),
            ];
        } catch (\Throwable $e) {
            $this->logger->error("Błąd pobierania ostatnich losowań: " . $e->getMessage());
            return [];
        }
    }
}

