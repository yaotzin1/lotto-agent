<?php

namespace App\Command;

use App\Service\LotteryConfig;
use App\Service\LotteryGenerator;
use App\Service\SystemFileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'app:generate', description: 'Generator systemów skróconych (v4.3)')]
class GenerateSystemCommand extends Command
{
    public function __construct(
        private readonly LotteryGenerator $generator,
        private readonly SystemFileWriter $writer
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        $output->writeln("=====================================================");
        $output->writeln("||  Witaj w Osobistym Generatorze Systemów v4.3  ||");
        $output->writeln("||                  by Hazardzista               ||");
        $output->writeln("=====================================================\n");

        // Select game
        $choices = [];
        foreach (LotteryConfig::GAMES_CONFIG as $key => $game) {
            $choices[$key] = "[$key] {$game['name']}";
        }
        $qGame = new ChoiceQuestion('Wybierz grę (klawisz numeru):', array_values($choices));
        $ans = $helper->ask($input, $output, $qGame);
        $selectedKey = array_search($ans, $choices, true);
        $selectedGame = LotteryConfig::GAMES_CONFIG[$selectedKey];

        if ($selectedGame['name'] === 'Generator_Dowolny') {
            // Experimental mode simplified via questions
            $from = (int)$helper->ask($input, $output, new Question('Podaj największą liczbę w puli (np. 50): '));
            $pick = (int)$helper->ask($input, $output, new Question('Podaj, ile liczb skreślasz w zakładzie (np. 7): '));
            $poolSize = (int)$helper->ask($input, $output, new Question('Na ilu liczbach chcesz oprzeć system (np. 20): '));
            $combCnt = (int)$helper->ask($input, $output, new Question('Ile zakładów chcesz wygenerować (np. 50): '));
            if ($pick > $poolSize || $poolSize > $from || $pick <= 0 || $poolSize <= 0 || $from <= 0 || $combCnt <= 0) {
                $output->writeln('<error>BŁĄD: Nieprawidłowe parametry.</error>');
                return Command::FAILURE;
            }
            $maxComb = gmp_strval(gmp_binomial($poolSize, $pick));
            if ($combCnt > (int)$maxComb) {
                $output->writeln("UWAGA: Maksymalna liczba unikalnych kombinacji to $maxComb. Ograniczam do tej wartości.");
                $combCnt = (int)$maxComb;
            }
            $gameConfig = ['name' => 'Generator_Dowolny', 'pick' => $pick, 'from' => $from];
            // get numbers
            $numbers = $this->askNumbers($helper, $input, $output, $poolSize, $from);
            $initial = $this->generator->generateShorthandSystem($numbers, $pick, $combCnt);
            $apply = new ChoiceQuestion('Zastosować filtry?', ['Tak', 'Nie'], 1);
            $choice = $helper->ask($input, $output, $apply);
            $final = ($choice === 'Tak') ? $this->generator->applyFilters($initial, $gameConfig) : $initial;
            $file = $this->writer->saveToFile($gameConfig, $numbers, $final, ['name' => 'N/A'], $final !== $initial, ['name' => "Dowolny ({$pick} z {$from}, system na {$poolSize} liczb)"]);
            $output->writeln("Zapisano: $file");
            return Command::SUCCESS;
        }

        // Multi_Multi strategy if needed
        $multiInfo = null;
        if ($selectedGame['name'] === 'Multi_Multi') {
            $multiInfo = $this->generator->selectMultiMultiStrategy($selectedGame);
        }

        // select system size
        $pickKey = $selectedGame['fallback_pick_key'] ?? ($selectedGame['pick'] ?? null);
        $availableSystems = $selectedGame['systems'] ?? [];
        if ($selectedGame['name'] === 'Multi_Multi' && $pickKey && isset(LotteryConfig::SHORTHAND_CONFIG['Multi_Multi'][$pickKey])) {
            $availableSystems = array_keys(LotteryConfig::SHORTHAND_CONFIG['Multi_Multi'][$pickKey]);
        }
        $qSize = new ChoiceQuestion('Na ile liczb chcesz stworzyć system?', array_map('strval', $availableSystems));
        $systemSize = (int)$helper->ask($input, $output, $qSize);

        // select guarantee
        $qGuarantee = new ChoiceQuestion('Wybierz gęstość siatki zakładów:', ['System Oszczędny', 'System Gęsty']);
        $gName = $helper->ask($input, $output, $qGuarantee);
        $guarantee = $gName === 'System Gęsty' ? ['key' => 'g4', 'name' => 'System Gęsty'] : ['key' => 'g3', 'name' => 'System Oszczędny'];

        // get numbers
        $numbers = $this->askNumbers($helper, $input, $output, $systemSize, $selectedGame['from']);

        // determine combinations to generate
        $combinationsToGenerate = 0;
        if ($selectedGame['name'] === 'Multi_Multi') {
            $pickKey = $selectedGame['fallback_pick_key'] ?? $selectedGame['pick'];
            if (isset(LotteryConfig::SHORTHAND_CONFIG['Multi_Multi'][$pickKey][$systemSize][$guarantee['key']])) {
                $combinationsToGenerate = LotteryConfig::SHORTHAND_CONFIG['Multi_Multi'][$pickKey][$systemSize][$guarantee['key']];
            }
        } else {
            $combinationsToGenerate = LotteryConfig::SHORTHAND_CONFIG[$selectedGame['pick']][$systemSize][$guarantee['key']];
        }
        if ($combinationsToGenerate === 0) {
            $output->writeln('<error>BŁĄD KRYTYCZNY: Nie można było ustalić liczby kombinacji.</error>');
            return Command::FAILURE;
        }

        $output->writeln("Generuję system dla {$selectedGame['name']} na $systemSize liczb.");
        if ($multiInfo) { $output->writeln("Strategia: {$multiInfo['name']}"); }
        $output->writeln("Twoje liczby: ".implode(', ', $numbers));
        $output->writeln("System będzie miał $combinationsToGenerate zakładów.\nPracuję...");

        $initial = $this->generator->generateShorthandSystem($numbers, (int)($selectedGame['pick'] ?? $selectedGame['fallback_pick_key'] ?? 6), $combinationsToGenerate);
        $apply = new ChoiceQuestion('Zastosować filtry?', ['Tak', 'Nie'], 1);
        $choice = $helper->ask($input, $output, $apply);
        $final = ($choice === 'Tak') ? $this->generator->applyFilters($initial, $selectedGame) : $initial;
        $file = $this->writer->saveToFile($selectedGame, $numbers, $final, $guarantee, $final !== $initial, $multiInfo);
        $output->writeln("Zapisano: $file");
        return Command::SUCCESS;
    }

    private function askNumbers($helper, InputInterface $input, OutputInterface $output, int $count, int $maxNumber): array
    {
        $qMode = new ChoiceQuestion('Podasz własne liczby czy wylosować?', ['Podam własne', 'Wylosuj'], 1);
        $mode = $helper->ask($input, $output, $qMode);
        if ($mode === 'Podam własne') {
            while (true) {
                $line = $helper->ask($input, $output, new Question("Podaj $count liczb (1-$maxNumber), rozdzielone spacją: "));
                $parts = array_values(array_filter(array_map('intval', preg_split('/\s+/', (string)$line))));
                $parts = array_values(array_unique($parts));
                if (count($parts) !== $count) {
                    $output->writeln("Błąd: podano ".count($parts)." unikalnych liczb, potrzeba $count.");
                    continue;
                }
                foreach ($parts as $n) { if ($n < 1 || $n > $maxNumber) { $output->writeln("Błąd: $n spoza zakresu."); continue 2; } }
                sort($parts); return $parts;
            }
        } else {
            $nums = [];
            while (count($nums) < $count) {
                $nums[] = random_int(1, $maxNumber);
                $nums = array_values(array_unique($nums));
            }
            sort($nums); return $nums;
        }
    }
}
