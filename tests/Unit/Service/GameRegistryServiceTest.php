<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GameRegistryService;
use PHPUnit\Framework\TestCase;

class GameRegistryServiceTest extends TestCase
{
    private GameRegistryService $service;

    protected function setUp(): void
    {
        $this->service = new GameRegistryService();
    }

    public function testGetAllGamesReturnsValidArray(): void
    {
        $games = $this->service->getAllGames();
        $this->assertArrayHasKey('Lotto', $games);
        $this->assertArrayHasKey('MiniLotto', $games);
        $this->assertArrayHasKey('EuroJackpot', $games);
        $this->assertSame(6, $games['Lotto']['pick']);
        $this->assertSame(49, $games['Lotto']['from']);
    }

    public function testGetGameConfigThrowsExceptionForInvalidGame(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getGameConfig('InvalidGameName');
    }

    public function testCalculateDateFromForSessions(): void
    {
        $dateFrom = $this->service->calculateDateFromForSessions('Lotto', 10);
        $this->assertNotEmpty($dateFrom);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $dateFrom);
    }
}
