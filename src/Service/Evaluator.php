<?php

namespace App\Service;

class Evaluator
{
    /**
     * Generates all possible 5-number combinations from the given numbers
     * 
     * @param array $numbers Array of chosen numbers (6-12 numbers)
     * @return array Array of all possible 5-number combinations
     */
    public function generateCombinations(array $numbers): array
    {
        $combinations = [];
        $this->combinationsRecursive($numbers, 5, 0, [], $combinations);
        return $combinations;
    }
    
    /**
     * Recursive helper function to generate combinations
     */
    private function combinationsRecursive(array $numbers, int $size, int $startPosition, array $currentCombination, array &$result): void
    {
        // If we have a complete combination, add it to the result
        if (count($currentCombination) === $size) {
            $result[] = $currentCombination;
            return;
        }
        
        // If we've reached the end of the array or can't form a complete combination, return
        if ($startPosition >= count($numbers) || 
            count($currentCombination) + (count($numbers) - $startPosition) < $size) {
            return;
        }
        
        // Include the current element
        $this->combinationsRecursive(
            $numbers,
            $size,
            $startPosition + 1,
            array_merge($currentCombination, [$numbers[$startPosition]]),
            $result
        );
        
        // Exclude the current element
        $this->combinationsRecursive(
            $numbers,
            $size,
            $startPosition + 1,
            $currentCombination,
            $result
        );
    }
    
    /**
     * Evaluates hits for all combinations against the draw data
     * 
     * @param array $combinations Array of 5-number combinations
     * @param array $draws Array of draw data
     * @return array Array with counts of 3/5, 4/5, and 5/5 hits
     */
    public function evaluateHits(array $combinations, array $draws): array
    {
        $results = [
            '3' => 0, // 3/5 hits
            '4' => 0, // 4/5 hits
            '5' => 0  // 5/5 hits
        ];
        
        foreach ($combinations as $combination) {
            foreach ($draws as $draw) {
                $drawNumbers = $draw['numbers'];
                $hitCount = count(array_intersect($combination, $drawNumbers));
                
                if ($hitCount >= 3) {
                    $results[(string)$hitCount]++;
                }
            }
        }
        
        return $results;
    }
}