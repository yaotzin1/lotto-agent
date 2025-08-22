<?php

namespace App\Command;

use App\Service\LotteryVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'app:verify', description: 'Weryfikator systemów (v2.1)')]
class VerifySystemCommand extends Command
{
    public function __construct(private readonly LotteryVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Plik systemu (.txt)')
            ->addArgument('numbers', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Wylosowane liczby (np. 5 12 18 23 40)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string)$input->getArgument('file');
        $numbers = array_map('intval', (array)$input->getArgument('numbers'));

        $gameConfig = $this->verifier->detectGameFromFilename($file);
        if ($gameConfig === null) {
            $output->writeln("BŁĄD: Nie udało się rozpoznać gry na podstawie nazwy pliku '$file'.");
            return Command::FAILURE;
        }

        $plusNumber = null;
        if ($gameConfig['name'] === 'Multi_Multi' || $gameConfig['name'] === 'Lotto') {
            $expected = $gameConfig['name'] === 'Lotto' ? 7 : 21;
            if (count($numbers) === $expected) {
                $plusNumber = array_pop($numbers);
            }
        }
        sort($numbers);

        try {
            $bets = $this->verifier->parseSystemFile($file, $gameConfig);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
        if (empty($bets)) {
            $output->writeln("BŁĄD: Nie znaleziono żadnych zakładów w pliku '$file'.");
            return Command::FAILURE;
        }

        if ($gameConfig['pick'] !== null && count($numbers) !== $gameConfig['pick'] && $gameConfig['name'] !== 'Multi_Multi') {
            $output->writeln("OSTRZEŻENIE: Wykryto grę '{$gameConfig['name']}', która wymaga {$gameConfig['pick']} liczb, a podałeś ".count($numbers).".");
        }

        $minHitsForWin = $gameConfig['min_win'] ?? 3;
        $results = [];
        $totalWins = 0;
        foreach ($bets as $idx => $bet) {
            $hits = array_values(array_intersect($bet, $numbers));
            $hitsCount = count($hits);
            if ($hitsCount > 0) {
                $isPlus = ($plusNumber !== null) && in_array($plusNumber, $hits, true);
                $key = $hitsCount . ($isPlus ? '_plus' : '');
                if (!isset($results[$key])) $results[$key] = [];
                $results[$key][] = [ 'bet_numbers' => $bet, 'hit_numbers' => $hits, 'bet_index' => $idx + 1 ];
                if ($hitsCount >= $minHitsForWin) $totalWins++;
            }
        }

        $output->writeln("=========================================================");
        $output->writeln("||         WYNIKI SPRAWDZENIA SYSTEMU                  ||");
        $output->writeln("=========================================================\n");
        $output->writeln("Sprawdzany plik: $file");
        $gameNameDisplay = $gameConfig['name'];
        if ($gameConfig['pick'] !== null) {
            if ($gameConfig['name'] === 'Gra Dowolna') {
                $gameNameDisplay .= " (zakład {$gameConfig['pick']} z {$gameConfig['from']})";
            } else {
                $gameNameDisplay .= " (gra na {$gameConfig['pick']} liczb)";
            }
        }
        $output->writeln("Wykryta gra: $gameNameDisplay");
        $output->writeln("Wylosowane liczby: ".implode(', ', $numbers));
        if ($plusNumber) $output->writeln("Liczba Plus: $plusNumber");
        $output->writeln("Łączna ilość zakładów w systemie: ".count($bets)."\n");

        if ($totalWins === 0) {
            $output->writeln("NIESTETY, BRAK WYGRANYCH\n");
        } else {
            uksort($results, fn($a,$b) => ((int)$b <=> (int)$a));
            foreach ($results as $key => $wins) {
                $hitsCount = (int)$key;
                $isPlus = str_contains($key, '_plus');
                if ($hitsCount < $minHitsForWin) continue;
                $tierName = $this->verifier->getTierName($hitsCount, $gameConfig);
                $plusLabel = $isPlus ? ' + PLUS' : '';
                $output->writeln("--- ZNALEZIONO ".mb_strtoupper($tierName).$plusLabel." (".count($wins)." razy) ---");
                foreach ($wins as $win) {
                    $output->write("  Zakład nr {$win['bet_index']}: ");
                    $out = [];
                    foreach ($win['bet_numbers'] as $n) {
                        $isHit = in_array($n, $win['hit_numbers'], true);
                        $isPlusNum = ($plusNumber !== null) && $n === $plusNumber;
                        if ($isHit && $isPlusNum) $out[] = "**{$n}**"; elseif ($isHit) $out[] = "*{$n}*"; else $out[] = (string)$n;
                    }
                    $output->writeln(implode(', ', $out));
                }
                $output->writeln('');
            }
        }

        if (!empty($results) && $totalWins > 0) {
            $output->writeln("PODSUMOWANIE:");
            foreach ($results as $key => $wins) {
                $hitsCount = (int)$key; if ($hitsCount < $minHitsForWin) continue;
                $isPlus = str_contains($key, '_plus');
                $tierName = $this->verifier->getTierName($hitsCount, $gameConfig) . ($isPlus ? ' + PLUS' : '');
                $output->writeln("- $tierName: ".count($wins));
            }
        }

        if ($totalWins > 0) {
            $helper = $this->getHelper('question');
            $totalCost = (float)str_replace(',', '.', (string)$helper->ask($input, $output, new Question('Podaj CAŁKOWITY koszt zakładu: ')));
            $totalWinnings = 0.0;
            uksort($results, fn($a,$b) => ((int)$a <=> (int)$b));
            foreach ($results as $key => $wins) {
                $hitsCount = (int)$key; $isPlus = str_contains($key, '_plus');
                if ($hitsCount >= $minHitsForWin) {
                    $tierName = $this->verifier->getTierName($hitsCount, $gameConfig) . ($isPlus ? ' + PLUS' : '');
                    $numOfWins = count($wins);
                    $prizePer = (float)str_replace(',', '.', (string)$helper->ask($input, $output, new Question("Podaj kwotę wygranej za jedną '$tierName' ($numOfWins x): ")));
                    $totalWinnings += $numOfWins * $prizePer;
                }
            }
            $profitLoss = $totalWinnings - $totalCost;
            $roi = $totalCost > 0 ? ($profitLoss / $totalCost) * 100.0 : 0.0;
            $output->writeln("Całkowity koszt: ".number_format($totalCost, 2, ',', ' ')." zł");
            $output->writeln("Łączna wygrana: ".number_format($totalWinnings, 2, ',', ' ')." zł");
            $output->writeln(($profitLoss >= 0 ? 'Zysk' : 'Strata').": ".number_format($profitLoss, 2, ',', ' ')." zł");
            $output->writeln("ROI: ".number_format($roi, 2, ',', ' ')." %");
        }

        return Command::SUCCESS;
    }
}
