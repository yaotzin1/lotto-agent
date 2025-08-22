<?php

namespace App\Service;

class LotteryGenerator
{
    public function selectMultiMultiStrategy(array &$game): array
    {
        $strategyName = '';
        $pickCount = $game['pick'] ?? null;
        if (!$pickCount) {
            $pickCount = 6; // default
        }

        $strategyName = "Strategia 'Własna' (Gra na $pickCount liczb)";
        $game['pick'] = $pickCount;

        if (!isset(LotteryConfig::SHORTHAND_CONFIG['Multi_Multi'][$pickCount])) {
            $defined = array_keys(LotteryConfig::SHORTHAND_CONFIG['Multi_Multi']);
            $closest = -1; $minDiff = PHP_INT_MAX;
            foreach ($defined as $type) {
                $d = abs($type - $pickCount);
                if ($d < $minDiff) { $minDiff = $d; $closest = (int)$type; }
            }
            $game['fallback_pick_key'] = $closest;
        }

        return ['name' => $strategyName, 'pick' => $pickCount];
    }

    public function generateShorthandSystem(array $pool, int $pick, int $combinationsCount): array
    {
        $system = [];
        $generated = [];
        if ($combinationsCount === 0) return [];
        $maxPossible = gmp_strval(gmp_binomial(count($pool), $pick));
        if ($combinationsCount > (int)$maxPossible) {
            $combinationsCount = (int)$maxPossible;
        }
        $attempts = 0; $maxAttempts = max($combinationsCount * 20, 1000);
        while (count($system) < $combinationsCount && $attempts < $maxAttempts) {
            $shuffled = $pool;
            shuffle($shuffled);
            $combination = array_slice($shuffled, 0, $pick);
            sort($combination);
            $hash = implode('-', $combination);
            if (!isset($generated[$hash])) {
                $system[] = $combination;
                $generated[$hash] = true;
            }
            $attempts++;
        }
        return $system;
    }

    public function isSumInRange(array $combination, array $game): bool
    {
        $sum = array_sum($combination);
        $ranges = [ 5 => ['min' => 70, 'max' => 150], 6 => ['min' => 100, 'max' => 200], 7 => ['min' => 180, 'max' => 320], 10 => ['min' => 300, 'max' => 500], ];
        $pick = $game['fallback_pick_key'] ?? $game['pick'];
        if (!isset($ranges[$pick])) { return true; }
        $r = $ranges[$pick];
        return $sum >= $r['min'] && $sum <= $r['max'];
    }

    public function isBalanceOk(array $combination, array $game): bool
    {
        $odd = 0; foreach ($combination as $n) { if ($n % 2 !== 0) $odd++; }
        $valid = [ 5 => [2,3], 6 => [2,3,4], 7 => [3,4], 10 => [4,5,6] ];
        $pick = $game['fallback_pick_key'] ?? $game['pick'];
        if (!isset($valid[$pick])) return true;
        return in_array($odd, $valid[$pick], true);
    }

    public function hasTooManyConsecutive(array $combination, int $maxConsecutive = 2): bool
    {
        if (count($combination) < 3) return false;
        $c = 1; for ($i=0; $i<count($combination)-1; $i++) {
            if ($combination[$i+1] === $combination[$i]+1) {
                $c++; if ($c > $maxConsecutive) return true;
            } else { $c = 1; }
        }
        return false;
    }

    public function applyFilters(array $system, array $game): array
    {
        $filtered = [];
        foreach ($system as $combination) {
            if ($this->isSumInRange($combination, $game) && $this->isBalanceOk($combination, $game) && !$this->hasTooManyConsecutive($combination)) {
                $filtered[] = $combination;
            }
        }
        return $filtered;
    }
}
