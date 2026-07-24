<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'secret' => trim($this->lottoApiKey),
                    'accept' => 'application/json',
                ],
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            if ($response->getStatusCode() === 401) {
                $this->logger->error('Błąd 401: Nieprawidłowy klucz LOTTO API.');
                throw new \RuntimeException('Błąd autoryzacji w LOTTO API (401). Sprawdź klucz.');
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->critical('Wyjątek podczas komunikacji z LOTTO API', ['error' => $e->getMessage()]);
            throw $e;
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

            return [
                'game' => $gameType,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'draws_analyzed' => $data['totalDraws'] ?? count($frequencies),
                'sorted_by_freq_desc' => $freqDesc,
                'sorted_by_freq_asc' => $freqAsc,
            ];
        } catch (\Throwable $e) {
            $this->logger->error("Błąd pobierania statystyk dla $gameType: " . $e->getMessage());
            return null;
        }
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

