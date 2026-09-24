<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GeminiApiClient;
use App\Service\Llm\LlmClientResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ai-models',
    description: 'Lists supported and available models from configured AI providers (Gemini, Claude, OpenAI, DeepSeek)',
)]
class AiModelsCommand extends Command
{
    public function __construct(
        private readonly GeminiApiClient $geminiApiClient,
        private readonly LlmClientResolver $llmClientResolver
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('provider', 'pv', InputOption::VALUE_REQUIRED, 'Dostawca AI (gemini, claude, openai, deepseek, all)', 'all');
        $this->addOption('live', 'l', InputOption::VALUE_NONE, 'Odpytaj zdalne API Gemini o aktualną listę wszystkich modeli konta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $providerInput = strtolower(trim((string) $input->getOption('provider')));
        $isLive = (bool) $input->getOption('live');

        $io->title('Katalog Modeli AI (Multi-Provider Support)');
        $io->note(sprintf(
            "Skonfigurowany domyślny dostawca: <fg=yellow;options=bold>%s</>\nDomyślny model w konfiguracji: <fg=cyan>%s</>",
            $this->llmClientResolver->getDefaultProvider(),
            $this->llmClientResolver->getDefaultModel() ?: '(brak nadpisania - używa zalecanego modelu dostawcy)'
        ));

        $catalog = $this->llmClientResolver->getProvidersCatalog();

        $selectedProviders = match ($providerInput) {
            'gemini' => ['gemini'],
            'claude', 'anthropic' => ['claude'],
            'openai' => ['openai'],
            'deepseek' => ['deepseek'],
            default => array_keys($catalog),
        };

        foreach ($selectedProviders as $provKey) {
            if (!isset($catalog[$provKey])) {
                continue;
            }

            $info = $catalog[$provKey];
            $io->section(sprintf('Dostawca: %s [klucz CLI: --provider=%s]', $info['displayName'], $provKey));

            $rows = [];
            foreach ($info['models'] as $modelId => $modelData) {
                $isDefault = ($modelId === $info['defaultModel']) ? '<fg=green;options=bold>[DOMYŚLNY]</>' : '';
                $rows[] = [
                    $modelId,
                    $modelData['name'],
                    $modelData['description'],
                    $modelData['recommendedFor'],
                    $isDefault,
                ];
            }

            $io->table(
                ['Model ID (--model=...)', 'Nazwa Handlowa', 'Opis / Architektura', 'Zastosowanie w Statystyce', 'Domyślny'],
                $rows
            );

            $io->text(sprintf(
                "<fg=gray>Łańcuch modeli awaryjnych (fallbacks):</> %s\n",
                implode(' -> ', $info['fallbacks'])
            ));
        }

        // Live API query for Gemini if requested
        if ($isLive && (in_array('gemini', $selectedProviders, true) || $providerInput === 'all')) {
            $io->section('Zdalne zapytanie Live API: Dostępne modele Gemini dla Twojego klucza');
            try {
                $liveModels = $this->geminiApiClient->listModels();
                if (empty($liveModels)) {
                    $io->warning('Brak modeli lub pusty wynik z API Gemini.');
                } else {
                    $liveRows = [];
                    foreach ($liveModels as $m) {
                        $modelId = str_replace('models/', '', $m['name']);
                        $liveRows[] = [
                            $modelId,
                            $m['version'] ?? '-',
                            $m['displayName'] ?? '-',
                        ];
                    }
                    $io->table(['Model ID', 'Wersja', 'Nazwa'], $liveRows);
                }
            } catch (\Throwable $e) {
                $io->error('Błąd podczas odpytywania API Gemini: ' . $e->getMessage());
            }
        }

        $io->section('Przykłady Użycia Flag CLI');
        $io->listing([
            'Wybór Gemini:  app:lotto-agent --provider=gemini --model=gemini-3.7-flash',
            'Wybór Claude:  app:lotto-agent --provider=claude --model=claude-3-opus-20240229',
            'Wybór OpenAI:  app:lotto-stats --ai --provider=openai --model=gpt-4o',
            'Wybór DeepSeek: app:lotto-stats --ai --provider=deepseek --model=deepseek-chat',
        ]);

        return Command::SUCCESS;
    }
}
