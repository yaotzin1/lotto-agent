<?php

namespace App\Service;

class DataLoader
{
    private string $dataDirectory;

    public function __construct(string $dataDirectory)
    {
        $this->dataDirectory = $dataDirectory;
    }

    /**
     * Loads Mini-Lotto draw data from CSV file
     * 
     * @param ?int $limit Number of past draws to load (default: all)
     * @return array Array of draw data with date and numbers
     */
    public function loadFromCsv(?int $limit = null): array
    {
        $filePath = $this->dataDirectory . '/results.csv';
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException("CSV file not found: $filePath");
        }
        
        $draws = [];
        $handle = fopen($filePath, 'r');
        
        // Skip header row
        fgetcsv($handle);
        
        while (($data = fgetcsv($handle)) !== false) {
            $draws[] = [
                'date' => $data[0],
                'numbers' => [
                    (int) $data[1],
                    (int) $data[2],
                    (int) $data[3],
                    (int) $data[4],
                    (int) $data[5]
                ]
            ];
        }
        
        fclose($handle);
        
        // Sort by date descending (newest first)
        usort($draws, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        // Apply limit if specified
        if ($limit !== null && $limit > 0) {
            $draws = array_slice($draws, 0, $limit);
        }
        
        return $draws;
    }
}