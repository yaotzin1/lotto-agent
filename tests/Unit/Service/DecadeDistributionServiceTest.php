<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DecadeDistributionService;
use PHPUnit\Framework\TestCase;

class DecadeDistributionServiceTest extends TestCase
{
    private DecadeDistributionService $service;

    protected function setUp(): void
    {
        $this->service = new DecadeDistributionService();
    }

    public function testLotto15NumbersGivesExactly3PerDecade(): void
    {
        // 49 liczb ma 5 dekad: 1-10, 11-20, 21-30, 31-40, 41-49
        $quotas = $this->service->calculateDecadeQuotas(15, 49);

        $this->assertCount(5, $quotas);
        $this->assertSame([0 => 3, 1 => 3, 2 => 3, 3 => 3, 4 => 3], $quotas);

        $result = $this->service->generateDecadePool(49, 15);
        $this->assertCount(15, $result['pool']);
        $this->assertTrue($result['is_all_decades_covered']);
        $this->assertSame(5, $result['decades_covered']);

        // Sprawdź ile liczb w każdej dekadzie
        foreach ($result['breakdown'] as $b) {
            $this->assertSame(3, $b['quota']);
            $this->assertCount(3, $b['selected']);
            foreach ($b['selected'] as $num) {
                $this->assertGreaterThanOrEqual($b['start'], $num);
                $this->assertLessThanOrEqual($b['end'], $num);
            }
        }
    }

    public function testLotto16NumbersHandlesRemainderWithFrequencies(): void
    {
        // Pula 16 liczb z 49: 4 dekady po 3, 1 dekada 4 liczby
        // Dajemy dekadzie 21-30 (indeks 2) najwyższe częstotliwości
        $frequencies = [];
        for ($n = 21; $n <= 30; $n++) {
            $frequencies[$n] = 100;
        }

        $quotas = $this->service->calculateDecadeQuotas(16, 49, $frequencies);

        $this->assertSame(16, array_sum($quotas));
        $this->assertSame(4, $quotas[2]); // Dekada 21-30 otrzymała dodatkową 1 liczbę
        $this->assertSame(3, $quotas[0]);
        $this->assertSame(3, $quotas[1]);
        $this->assertSame(3, $quotas[3]);
        $this->assertSame(3, $quotas[4]);
    }

    public function testMiniLotto15NumbersCapsDecade41To42(): void
    {
        // Mini Lotto: 42 liczby.
        // Dekady: 1-10 (10), 11-20 (10), 21-30 (10), 31-40 (10), 41-42 (2 liczby!).
        // Pula 15 liczb:
        // 15 / 5 = 3 na dekadę, ale dekada 4 (41-42) ma max 2 liczby!
        // Zatem dekada 4 powinna dostać max 2, a 1 nadmiarowa liczba trafić do innej dekady.
        $quotas = $this->service->calculateDecadeQuotas(15, 42);

        $this->assertSame(15, array_sum($quotas));
        $this->assertSame(2, $quotas[4], 'Dekada 41-42 nie może mieć więcej niż 2 liczby');
        $this->assertSame(4, $quotas[0]);
        $this->assertSame(3, $quotas[1]);
        $this->assertSame(3, $quotas[2]);
        $this->assertSame(3, $quotas[3]);

        $result = $this->service->generateDecadePool(42, 15);
        $this->assertCount(15, $result['pool']);
        $this->assertTrue($result['is_all_decades_covered']);
        $this->assertSame([41, 42], $result['breakdown'][4]['selected']);
    }

    public function testEuroJackpot20NumbersGives4PerDecade(): void
    {
        // EuroJackpot: 50 liczb głównych = 5 dekad po 10 liczb
        $quotas = $this->service->calculateDecadeQuotas(20, 50);

        $this->assertSame([0 => 4, 1 => 4, 2 => 4, 3 => 4, 4 => 4], $quotas);

        $result = $this->service->generateDecadePool(50, 20);
        $this->assertCount(20, $result['pool']);
        $this->assertTrue($result['is_all_decades_covered']);
    }

    public function testMultiMulti16And24Numbers(): void
    {
        // Multi Multi: 80 liczb = 8 dekad
        $quotas16 = $this->service->calculateDecadeQuotas(16, 80);
        $this->assertCount(8, $quotas16);
        $this->assertSame(array_fill(0, 8, 2), $quotas16);

        $quotas24 = $this->service->calculateDecadeQuotas(24, 80);
        $this->assertCount(8, $quotas24);
        $this->assertSame(array_fill(0, 8, 3), $quotas24);

        $result24 = $this->service->generateDecadePool(80, 24);
        $this->assertCount(24, $result24['pool']);
        $this->assertTrue($result24['is_all_decades_covered']);
        $this->assertSame(8, $result24['decades_covered']);
    }

    public function testEkstraPensja12Numbers(): void
    {
        // Ekstra Pensja: 35 liczb = 4 dekady (1-10, 11-20, 21-30, 31-35)
        $quotas = $this->service->calculateDecadeQuotas(12, 35);
        $this->assertSame([0 => 3, 1 => 3, 2 => 3, 3 => 3], $quotas);

        $result = $this->service->generateDecadePool(35, 12);
        $this->assertCount(12, $result['pool']);
        $this->assertTrue($result['is_all_decades_covered']);
    }

    public function testKaskada12Numbers(): void
    {
        // Kaskada: 24 liczby = 3 dekady (1-10, 11-20, 21-24)
        $quotas = $this->service->calculateDecadeQuotas(12, 24);
        $this->assertSame([0 => 4, 1 => 4, 2 => 4], $quotas);

        $result = $this->service->generateDecadePool(24, 12);
        $this->assertCount(12, $result['pool']);
        $this->assertTrue($result['is_all_decades_covered']);
    }

    public function testKeno14Numbers(): void
    {
        // Keno: 70 liczb = 7 dekad
        $quotas = $this->service->calculateDecadeQuotas(14, 70);
        $this->assertSame(array_fill(0, 7, 2), $quotas);

        $result = $this->service->generateDecadePool(70, 14);
        $this->assertCount(14, $result['pool']);
        $this->assertTrue($result['is_all_decades_covered']);
    }

    public function testPoolSmallerThanDecadeCount(): void
    {
        // Pula 3 liczby przy 5 dekadach (Lotto 49)
        $quotas = $this->service->calculateDecadeQuotas(3, 49);
        $this->assertSame(3, array_sum($quotas));
        $this->assertSame(3, count(array_filter($quotas, fn($q) => $q === 1)));

        $result = $this->service->generateDecadePool(49, 3);
        $this->assertCount(3, $result['pool']);
        $this->assertFalse($result['is_all_decades_covered']);
        $this->assertSame(3, $result['decades_covered']);
    }

    public function testHotSelectionStrategyPicksHighestFrequencyNumbers(): void
    {
        // W dekadzie 1-10 ustawiamy liczby 7, 8, 9 jako najczęstsze
        $frequencies = [
            7 => 50,
            8 => 60,
            9 => 70,
            1 => 5,
            2 => 5,
            3 => 5,
            4 => 5,
            5 => 5,
            6 => 5,
            10 => 5,
        ];

        $result = $this->service->generateDecadePool(49, 15, $frequencies, 'hot');
        $dec0Selected = $result['breakdown'][0]['selected'];

        // Powinno wybrać 9 (70), 8 (60), 7 (50)
        sort($dec0Selected);
        $this->assertSame([7, 8, 9], $dec0Selected);
    }

    public function testValidateDecadeCoverage(): void
    {
        // Pula pokrywająca wszystkie 5 dekad Lotto
        $fullCoverPool = [2, 7, 12, 18, 24, 29, 31, 38, 42, 47];
        $val1 = $this->service->validateDecadeCoverage($fullCoverPool, 49);
        $this->assertTrue($val1['is_fully_covered']);
        $this->assertSame(5, $val1['decades_covered']);
        $this->assertEmpty($val1['empty_decades']);

        // Pula omijająca dekadę 31-40
        $partialPool = [2, 7, 12, 18, 24, 29, 42, 47];
        $val2 = $this->service->validateDecadeCoverage($partialPool, 49);
        $this->assertFalse($val2['is_fully_covered']);
        $this->assertSame(4, $val2['decades_covered']);
        $this->assertContains('31-40', $val2['empty_decades']);
    }

    public function testGenerateDecadePoolWithNeighboursPrioritizesNeighboursWithinDecades(): void
    {
        // 5 dekad, pula 15 liczb => dokładnie 3 liczby na dekadę
        // Anchors: [15, 35] -> sąsiedzi: 14, 16 w dekadzie 11-20 oraz 34, 36 w dekadzie 31-40
        $anchors = [15, 35];

        // Dajmy bardzo wysoką częstotliwość liczbom 11, 12, 13 w dekadzie 11-20,
        // ale 14 i 16 są sąsiadami kotwicy 15 (±1).
        $frequencies = [
            11 => 100,
            12 => 90,
            13 => 80,
            14 => 10,
            16 => 15,
            17 => 70,
            18 => 60,
            // Dekada 1-10 (brak sąsiadów)
            1 => 50, 2 => 40, 3 => 30,
            // Dekada 31-40 (sąsiedzi 34, 36)
            31 => 100, 32 => 90, 34 => 5, 36 => 10, 37 => 80,
        ];

        $result = $this->service->generateDecadePool(
            maxNumber: 49,
            poolSize: 15,
            frequencies: $frequencies,
            strategy: 'hot',
            anchors: $anchors,
            withNeighbours: true
        );

        $this->assertTrue($result['with_neighbours']);
        $this->assertTrue($result['is_all_decades_covered']);

        // Dekada 11-20 (index 1): quota 3.
        // Sąsiedzi: 14 i 16 (muszą zostać wybrane mimo niskich częstotliwości!).
        // Trzecia liczba powinna być z pozostałych o najwyższej frekwencji: 11 (freq 100).
        $dec1 = $result['breakdown'][1];
        $this->assertContains(14, $dec1['selected']);
        $this->assertContains(16, $dec1['selected']);
        $this->assertContains(11, $dec1['selected']);
        $this->assertSame([14, 16], $dec1['neighbours_selected']);

        // Dekada 1-10 (index 0): brak sąsiadów, wybrane z najwyższych frekwencji (1, 2, 3)
        $dec0 = $result['breakdown'][0];
        $this->assertEmpty($dec0['neighbours_selected']);
        $this->assertSame([1, 2, 3], $dec0['selected']);

        // Dekada 31-40 (index 3): sąsiedzi 34 i 36 + najwyższa frekwencja 31
        $dec3 = $result['breakdown'][3];
        $this->assertContains(34, $dec3['selected']);
        $this->assertContains(36, $dec3['selected']);
        $this->assertContains(31, $dec3['selected']);
        $this->assertSame([34, 36], $dec3['neighbours_selected']);

        $this->assertGreaterThanOrEqual(4, $result['neighbours_count']);
    }

    public function testGenerateDecadePoolWithNeighboursFalsePreservesOriginalBehavior(): void
    {
        $anchors = [15];
        $frequencies = [
            11 => 100,
            12 => 90,
            13 => 80,
            14 => 10,
            16 => 15,
        ];

        // Przy withNeighbours = false, sąsiedzi 14 i 16 NIE powinni mieć priorytetu
        $result = $this->service->generateDecadePool(
            maxNumber: 49,
            poolSize: 15,
            frequencies: $frequencies,
            strategy: 'hot',
            anchors: $anchors,
            withNeighbours: false
        );

        $this->assertFalse($result['with_neighbours']);
        $dec1Selected = $result['breakdown'][1]['selected'];
        $this->assertSame([11, 12, 13], $dec1Selected);
    }
}
