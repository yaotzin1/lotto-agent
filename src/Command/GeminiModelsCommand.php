<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GeminiApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gemini-models',
    description: 'Lists all available models from the Gemini API',
)]
class GeminiModelsCommand extends Command
{
    public function __construct(
        private readonly GeminiApiClient $geminiApiClient
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Available Gemini Models');

        try {
            $models = $this->geminiApiClient->listModels();

            if (empty($models)) {
                $io->warning('No models found or invalid response structure.');
                return Command::FAILURE;
            }

            $tableRows = [];
            foreach ($models as $model) {
                $modelId = str_replace('models/', '', $model['name']);
                $tableRows[] = [
                    $modelId,
                    $model['version'] ?? '-',
                    $model['displayName'] ?? '-',
                ];
            }

            $io->table(['Model ID', 'Version', 'Display Name'], $tableRows);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Failed to fetch models: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}