<?php

declare(strict_types=1);

/**
 * Backtest CLI Script: Stride Sampling vs Consecutive vs Random Baseline
 *
 * Tests the hypothesis:
 * "If a winning event occurs on average every 1 in N draws (e.g. N=127 or N=257),
 * does sampling historical draws at intervals/strides of N (and expanding with
 * neighbours or anchor heuristics) beat consecutive draws or random baseline?"
 */

$jsonFile = __DIR__ . '/../data/lotto_draws.json';

if (!file_exists($jsonFile)) {
    echo "Error: $jsonFile not found. Please run scripts/parse_history.php first.\n";
    exit(1);
}

$drawsData = json_decode((string) file_get_contents($jsonFile), true);
$totalDraws = count($drawsData);
echo "=======================================================================\n";
echo " LOTTO STRIDE & NEIGHBOURS HYPOTHESIS BACKTESTER\n";
echo " Total Historical Draws Loaded: $totalDraws (from {$drawsData[0]['date']} to {$drawsData[$totalDraws - 1]['date']})\n";
echo "=======================================================================\n\n";

// Configuration
$poolSize = 12; // 12 numbers pool
$stridesToTest = [1, 2, 7, 30, 50, 127, 257, 500];

// Parse command-line args if provided
// Usage: php scripts/backtest_stride.php [poolSize] [stride1,stride2,...] [startDraw]
$args = array_slice($argv, 1);
if (isset($args[0]) && is_numeric($args[0])) {
    $poolSize = max(6, min(24, (int) $args[0]));
}
if (isset($args[1])) {
    $stridesToTest = array_map('intval', explode(',', $args[1]));
}

$maxStride = max($stridesToTest);
$warmup = $maxStride + 10;
$testCount = $totalDraws - $warmup;

echo "Backtest Parameters:\n";
echo " - Pool Size: $poolSize numbers\n";
echo " - Target Draws Evaluated: $testCount (from Draw #$warmup to #" . ($totalDraws - 1) . ")\n";
echo " - Strides to test: " . implode(', ', $stridesToTest) . "\n\n";

/**
 * Strategy 1: Stride Anchor + Neighbours
 * Takes numbers from draw T - stride.
 * Expands with left and right neighbours (+1, -1).
 * Prioritizes anchors, then neighbours ordered by how many anchors they neighbor.
 * Fills up to $poolSize.
 */
function buildStrideNeighbourPool(array $history, int $targetIdx, int $stride, int $targetPoolSize): array
{
    $anchorIdx = $targetIdx - $stride;
    if ($anchorIdx < 0) {
        return range(1, $targetPoolSize);
    }

    $anchors = $history[$anchorIdx]['numbers'];
    $neighbourCounts = [];

    foreach ($anchors as $num) {
        $left = $num === 1 ? 49 : $num - 1;
        $right = $num === 49 ? 1 : $num + 1;

        if (!in_array($left, $anchors, true)) {
            $neighbourCounts[$left] = ($neighbourCounts[$left] ?? 0) + 1;
        }
        if (!in_array($right, $anchors, true)) {
            $neighbourCounts[$right] = ($neighbourCounts[$right] ?? 0) + 1;
        }
    }

    // Sort neighbours by connection strength (adjacent to multiple anchors first)
    arsort($neighbourCounts);

    $pool = $anchors;
    foreach (array_keys($neighbourCounts) as $n) {
        if (count($pool) >= $targetPoolSize) {
            break;
        }
        $pool[] = $n;
    }

    // Fill remaining if needed
    $candidate = 1;
    while (count($pool) < $targetPoolSize && $candidate <= 49) {
        if (!in_array($candidate, $pool, true)) {
            $pool[] = $candidate;
        }
        $candidate++;
    }

    sort($pool);
    return $pool;
}

/**
 * Strategy 2: Multi-Anchor Stride (e.g. Draw T - stride and Draw T - 2*stride)
 */
function buildMultiAnchorStridePool(array $history, int $targetIdx, int $stride, int $targetPoolSize): array
{
    $pool = [];
    $k = 1;
    while (count($pool) < $targetPoolSize && ($targetIdx - ($k * $stride)) >= 0) {
        $drawNumbers = $history[$targetIdx - ($k * $stride)]['numbers'];
        foreach ($drawNumbers as $num) {
            if (!in_array($num, $pool, true)) {
                $pool[] = $num;
            }
            if (count($pool) >= $targetPoolSize) {
                break;
            }
        }
        $k++;
    }

    // If still not full, add neighbours of the first anchor
    if (count($pool) < $targetPoolSize && ($targetIdx - $stride) >= 0) {
        foreach ($history[$targetIdx - $stride]['numbers'] as $num) {
            $left = $num === 1 ? 49 : $num - 1;
            $right = $num === 49 ? 1 : $num + 1;
            if (!in_array($left, $pool, true)) {
                $pool[] = $left;
            }
            if (count($pool) >= $targetPoolSize) {
                break;
            }
            if (!in_array($right, $pool, true)) {
                $pool[] = $right;
            }
            if (count($pool) >= $targetPoolSize) {
                break;
            }
        }
    }

    sort($pool);
    return $pool;
}

/**
 * Strategy 3: Random Baseline (Monte Carlo / Pseudo-random for each draw)
 */
function buildRandomPool(int $targetPoolSize): array
{
    $numbers = range(1, 49);
    shuffle($numbers);
    $pool = array_slice($numbers, 0, $targetPoolSize);
    sort($pool);
    return $pool;
}

// Data structures for results
$results = [];

// Theoretical Expectation (Hypergeometric):
$totalCombs = 13983816; // C(49, 6)
$theoMatches = [];
for ($k = 0; $k <= 6; $k++) {
    // Hypergeometric: C(12, k) * C(37, 6-k) / C(49, 6)
    $c12_k = gmp_intval(gmp_binomial($poolSize, $k));
    $c37_rem = gmp_intval(gmp_binomial(49 - $poolSize, 6 - $k));
    $prob = ($c12_k * $c37_rem) / $totalCombs;
    $theoMatches[$k] = $prob;
}
$theoMean = $poolSize * (6.0 / 49.0);

// Initialize metrics for each strategy
$strategies = [];
foreach ($stridesToTest as $s) {
    $strategies["Stride-$s (Anchor+Nbr)"] = [
        'type' => 'stride_nbr',
        'stride' => $s,
    ];
    $strategies["Stride-$s (2xAnchor)"] = [
        'type' => 'multi_anchor',
        'stride' => $s,
    ];
}
$strategies['Random Baseline'] = [
    'type' => 'random',
    'stride' => 0,
];

foreach (array_keys($strategies) as $name) {
    $results[$name] = [
        'match_counts' => array_fill(0, 7, 0),
        'total_matches' => 0,
        'gaps_ge3' => [],
        'gaps_ge4' => [],
        'last_hit_ge3' => null,
        'last_hit_ge4' => null,
    ];
}

// Run backtest over all test draws
for ($t = $warmup; $t < $totalDraws; $t++) {
    $targetDraw = $drawsData[$t]['numbers'];

    foreach ($strategies as $name => $strat) {
        if ($strat['type'] === 'stride_nbr') {
            $pool = buildStrideNeighbourPool($drawsData, $t, $strat['stride'], $poolSize);
        } elseif ($strat['type'] === 'multi_anchor') {
            $pool = buildMultiAnchorStridePool($drawsData, $t, $strat['stride'], $poolSize);
        } else {
            $pool = buildRandomPool($poolSize);
        }

        // Count matches
        $hits = count(array_intersect($targetDraw, $pool));
        $results[$name]['match_counts'][$hits]++;
        $results[$name]['total_matches'] += $hits;

        // Track gaps for >= 3 hits
        if ($hits >= 3) {
            if ($results[$name]['last_hit_ge3'] !== null) {
                $results[$name]['gaps_ge3'][] = $t - $results[$name]['last_hit_ge3'];
            }
            $results[$name]['last_hit_ge3'] = $t;
        }

        // Track gaps for >= 4 hits
        if ($hits >= 4) {
            if ($results[$name]['last_hit_ge4'] !== null) {
                $results[$name]['gaps_ge4'][] = $t - $results[$name]['last_hit_ge4'];
            }
            $results[$name]['last_hit_ge4'] = $t;
        }
    }
}

// Output summary table
echo "RESULTS ACROSS $testCount HISTORICAL DRAWS:\n";
echo str_repeat('-', 105) . "\n";
printf(
    "%-28s | %-7s | %-7s | %-7s | %-7s | %-7s | %-7s | %-7s | %-8s\n",
    "Strategy", "0 Hits", "1 Hit", "2 Hits", "3 Hits", "4 Hits", "5 Hits", "6 Hits", "Avg Match"
);
echo str_repeat('-', 105) . "\n";

// Show theoretical baseline first
printf(
    "%-28s | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %7.4f\n",
    "THEORETICAL (Pure Random)",
    $theoMatches[0] * 100,
    $theoMatches[1] * 100,
    $theoMatches[2] * 100,
    $theoMatches[3] * 100,
    $theoMatches[4] * 100,
    $theoMatches[5] * 100,
    $theoMatches[6] * 100,
    $theoMean
);
echo str_repeat('-', 105) . "\n";

foreach ($results as $name => $res) {
    $pcts = [];
    for ($k = 0; $k <= 6; $k++) {
        $pcts[$k] = ($res['match_counts'][$k] / $testCount) * 100;
    }
    $avg = $res['total_matches'] / $testCount;

    printf(
        "%-28s | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %6.2f%% | %7.4f\n",
        $name,
        $pcts[0], $pcts[1], $pcts[2], $pcts[3], $pcts[4], $pcts[5], $pcts[6],
        $avg
    );
}
echo str_repeat('-', 105) . "\n\n";

// Analysis of Wait Times / Intervals (The periodicity question!)
echo "PERIODICITY & WAITING TIME ANALYSIS (Hit Interval Stats):\n";
echo "Testing whether hits happen 'every N draws' or randomly:\n";
echo str_repeat('-', 85) . "\n";
printf(
    "%-28s | %-16s | %-16s | %-16s\n",
    "Strategy", "Mean Gap (>=3)", "StdDev Gap", "Max Drought (>=3)"
);
echo str_repeat('-', 85) . "\n";

foreach ($results as $name => $res) {
    $gaps = $res['gaps_ge3'];
    if (count($gaps) > 1) {
        $meanGap = array_sum($gaps) / count($gaps);
        $sqDiffs = array_map(fn($g) => pow($g - $meanGap, 2), $gaps);
        $stdDev = sqrt(array_sum($sqDiffs) / (count($gaps) - 1));
        $maxGap = max($gaps);

        printf(
            "%-28s | %13.2f draws | %13.2f draws | %13d draws\n",
            $name, $meanGap, $stdDev, $maxGap
        );
    }
}
echo str_repeat('-', 85) . "\n\n";

// Analysis of 6-Hits (Jackpot) in pool
echo "JACKPOT HITS (6/6 Numbers inside the 12-number pool):\n";
foreach ($results as $name => $res) {
    $sixes = $res['match_counts'][6];
    $expectedSixes = $testCount * $theoMatches[6];
    printf(
        " - %-28s: %d hit(s)  (Expected: %.2f hit(s))\n",
        $name, $sixes, $expectedSixes
    );
}
echo "\n";
