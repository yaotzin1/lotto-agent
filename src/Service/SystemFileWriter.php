<?php

namespace App\Service;

class SystemFileWriter
{
    public function saveToFile(array $game, array $numbers, array $system, array $guarantee, bool $wasFiltered, ?array $multiStrategyInfo = null): string
    {
        $filename = "system_" . $game['name'] . "_" . count($numbers) . "_liczb_" . date('Y-m-d') . ".txt";
        if ($game['name'] === 'Generator_Dowolny') {
            $filename = "system_DOWOLNY_{$game['pick']}z{$game['from']}_na_".count($numbers)."_liczb_" . date('Y-m-d') . ".txt";
        }

        $content = "--- System Skrócony wygenerowany przez Generator Hazardzisty v4.3 ---\n\n";
        if ($game['name'] === 'Generator_Dowolny') {
            $content .= "Gra: ".($multiStrategyInfo['name'] ?? 'Dowolny')."\n";
        } else {
            $content .= "Gra: {$game['name']}\n";
            if ($multiStrategyInfo) {
                $content .= "Typ Gry Multi Multi: {$multiStrategyInfo['name']}\n";
            }
        }

        $content .= "System na: " . count($numbers) . " liczb\n";
        if ($game['name'] !== 'Generator_Dowolny') {
            $content .= "Wybrana gęstość: ".($guarantee['name'] ?? 'N/A')."\n";
        }
        if ($wasFiltered) { $content .= "Status filtracji: Zastosowano filtry\n"; }
        $content .= "Ilość zakładów: " . count($system) . "\n";
        $content .= "Data wygenerowania: " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "Twoje wybrane liczby (" . count($numbers) . "):\n" . implode(', ', $numbers) . "\n\n";
        $content .= "--- Gotowe zakłady do skreślenia ---\n";
        foreach ($system as $index => $combination) {
            $content .= ($index + 1) . ". " . implode(', ', $combination) . "\n";
        }

        file_put_contents($filename, $content);
        return $filename;
    }
}
