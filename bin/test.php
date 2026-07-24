<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AgentTools\EvaluatePoolTool;
use App\Service\AgentTools\FetchHotColdStatsTool;
use App\Service\AgentTools\SimulateCoverageTool;
use App\Service\AgentTools\ToolRegistry;
use App\Service\BetGeneratorService;
use App\Service\GameRegistryService;
use App\Service\LottoApiClient;

echo "=== RUNNING UNIT TESTS ===\n";

$errors = 0;

// Test GameRegistryService
try {
    $gameRegistry = new GameRegistryService();
    assert(count($gameRegistry->getAllGames()) === 11, 'Expected 11 games');
    assert($gameRegistry->isValidGame('Lotto') === true, 'Lotto should be valid');
    assert($gameRegistry->isValidGame('InvalidGame') === false, 'InvalidGame should be false');

    $lottoConfig = $gameRegistry->getGameConfig('Lotto');
    assert($lottoConfig['pick'] === 6, 'Lotto pick should be 6');
    assert($lottoConfig['from'] === 49, 'Lotto from should be 49');

    $dateFrom = $gameRegistry->calculateDateFromForSessions('Lotto', 5);
    assert(!empty($dateFrom), 'DateFrom should not be empty');

    echo "✔ GameRegistryService tests PASSED\n";
} catch (\Throwable $e) {
    echo "❌ GameRegistryService test FAILED: " . $e->getMessage() . "\n";
    $errors++;
}

// Test BetGeneratorService
try {
    $generator = new BetGeneratorService();

    // Test combinations generator
    $elements = [1, 2, 3, 4];
    $combs = iterator_to_array($generator->generateCombinations($elements, 2));
    assert(count($combs) === 6, '4 choose 2 should be 6');
    assert($combs[0] === [1, 2], 'First combination should be [1, 2]');

    // Test overlapping blocks
    $pool = range(1, 20);
    $blocks = $generator->generateOverlappingBlocks($pool, 4, 10);
    assert(count($blocks) === 4, 'Should generate 4 blocks');
    assert(count($blocks[0]) === 10, 'Block 0 size should be 10');

    // Test smart unique blocks
    $pool = range(1, 15);
    $smartBlocks = $generator->generateSmartUniqueBlocks($pool, 6, 3);
    assert(count($smartBlocks) === 3, 'Should generate 3 smart blocks');

    // Test balanced shorthand
    $balancedBets = $generator->generateBalancedShorthand($pool, 6, 5);
    assert(count($balancedBets) === 5, 'Should generate 5 balanced bets');

    // Test coverage calculation
    $pool = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
    $bets = [
        [1, 2, 3, 4, 5, 6],
        [5, 6, 7, 8, 9, 10],
    ];
    $coverage = $generator->calculateCoverage($pool, $bets, 6);
    assert($coverage['total_draws'] === 210, '10 choose 6 should be 210');
    assert($coverage['guarantees'][3] > 0, 'Guarantee for 3/6 should be > 0');

    echo "✔ BetGeneratorService tests PASSED\n";
} catch (\Throwable $e) {
    echo "❌ BetGeneratorService test FAILED: " . $e->getMessage() . "\n";
    $errors++;
}

// Test ReAct Agent Tools & ToolRegistry
try {
    $gameRegistry = new GameRegistryService();
    $betGenerator = new BetGeneratorService();

    $evaluateTool = new EvaluatePoolTool($gameRegistry);
    assert($evaluateTool->getName() === 'evaluate_candidate_pool');
    
    $evalRes = json_decode($evaluateTool->execute(['game' => 'Lotto', 'numbers' => [2, 5, 12, 19, 24, 31, 44]]), true);
    assert($evalRes['numbers_count'] === 7, 'Expected 7 numbers');
    assert(isset($evalRes['odd_even_ratio']), 'Expected odd_even_ratio key');

    $simulateTool = new SimulateCoverageTool($betGenerator, $gameRegistry);
    assert($simulateTool->getName() === 'test_system_coverage');
    
    $simRes = json_decode($simulateTool->execute(['game' => 'Lotto', 'pool' => [1, 2, 3, 4, 5, 6, 7, 8], 'bets_count' => 3]), true);
    assert($simRes['bets_generated'] === 3, 'Expected 3 bets');

    $registry = new ToolRegistry([$evaluateTool, $simulateTool]);
    assert(count($registry->getTools()) === 2, 'Expected 2 registered tools');

    $declarations = $registry->getGeminiFunctionDeclarations();
    assert(count($declarations) === 2, 'Expected 2 function declarations');
    assert($declarations[0]['name'] === 'evaluate_candidate_pool');

    echo "✔ ReAct Agent Tools & ToolRegistry tests PASSED\n";
} catch (\Throwable $e) {
    echo "❌ ReAct Agent Tools test FAILED: " . $e->getMessage() . "\n";
    $errors++;
}

if ($errors === 0) {
    echo "\n🎉 ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "\n❌ $errors TEST(S) FAILED!\n";
    exit(1);
}
