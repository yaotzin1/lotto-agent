<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTools;

use App\Service\AgentTools\FetchNeighboursAnalysisTool;
use App\Service\GameRegistryService;
use App\Service\LottoApiClient;
use PHPUnit\Framework\TestCase;

class FetchNeighboursAnalysisToolTest extends TestCase
{
    private LottoApiClient $lottoApiClient;
    private GameRegistryService $gameRegistryService;
    private FetchNeighboursAnalysisTool $tool;

    protected function setUp(): void
    {
        $this->lottoApiClient = $this->createMock(LottoApiClient::class);
        $this->gameRegistryService = new GameRegistryService();
        $this->tool = new FetchNeighboursAnalysisTool($this->lottoApiClient, $this->gameRegistryService);
    }

    public function testToolMetadata(): void
    {
        $this->assertSame('fetch_neighbours_analysis', $this->tool->getName());
        $this->assertNotEmpty($this->tool->getDescription());
        $schema = $this->tool->getParametersSchema();
        $this->assertSame('OBJECT', $schema['type']);
        $this->assertArrayHasKey('game', $schema['properties']);
    }

    public function testExecuteReturnsExpectedSyndicateStructure(): void
    {
        $this->lottoApiClient->method('getHotColdNumbers')
            ->willReturn([
                'game' => 'Lotto',
                'date_from' => '2026-07-01T00:00:00Z',
                'date_to' => '2026-08-15T00:00:00Z',
                'draws_analyzed' => 20,
                'sorted_by_freq_desc' => [
                    15 => 8,
                    24 => 7,
                    33 => 6,
                    42 => 5,
                    7 => 5,
                    19 => 4,
                    28 => 4,
                    39 => 4,
                ],
                'sorted_by_freq_asc' => [
                    2 => 1,
                    48 => 1,
                    10 => 1,
                    27 => 1,
                ],
            ]);

        $resultJson = $this->tool->execute(['game' => 'Lotto', 'sessions' => 20]);
        $data = json_decode($resultJson, true);

        $this->assertIsArray($data);
        $this->assertSame('Lotto', $data['game']);
        $this->assertArrayHasKey('winning_anchors', $data);
        $this->assertArrayHasKey('neighbours_60_percent', $data);
        $this->assertArrayHasKey('direct_repeats_20_percent', $data);
        $this->assertArrayHasKey('isolated_cold_20_percent', $data);

        // Check neighbours are +/-1 of winning anchors (e.g. 14, 16 for 15)
        $neighbourNumbers = array_column($data['neighbours_60_percent'], 'number');
        $this->assertContains(14, $neighbourNumbers);
        $this->assertContains(16, $neighbourNumbers);

        // Check direct repeats contain 15
        $repeatNumbers = array_column($data['direct_repeats_20_percent'], 'number');
        $this->assertContains(15, $repeatNumbers);
    }
}
