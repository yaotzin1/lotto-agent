<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BetPipelineRequest;
use App\Service\BetPipelineService;
use App\Service\DrawArchiveService;
use App\Service\GameRegistryService;
use App\Service\HistoricalDataProvider;
use App\Service\Llm\LlmClientResolver;
use App\Service\ReActAgentService;
use App\Service\StatisticalOptimizerService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class DesktopApiController
{
    private const SECRET_ENV_KEYS = [
        'GEMINI_API_KEY',
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'DEEPSEEK_API_KEY',
        'LOTTO_API_KEY',
    ];

    private const CONFIG_ENV_KEYS = [
        'AI_PROVIDER',
        'AI_MODEL',
        'OPENAI_BASE_URL',
    ];

    public function __construct(
        private readonly GameRegistryService $gameRegistryService,
        private readonly BetPipelineService $betPipelineService,
        private readonly StatisticalOptimizerService $statisticalOptimizerService,
        private readonly HistoricalDataProvider $historicalDataProvider,
        private readonly DrawArchiveService $drawArchiveService,
        private readonly ReActAgentService $reActAgentService,
        private readonly LlmClientResolver $llmClientResolver,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
    ) {
    }

    #[Route('/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'app' => 'lotto-agent',
            'php_version' => PHP_VERSION,
        ]);
    }

    #[Route('/games', name: 'api_games', methods: ['GET'])]
    public function games(): JsonResponse
    {
        $all = $this->gameRegistryService->getAllGames();
        $games = [];

        foreach ($all as $name => $config) {
            $games[$name] = array_merge($config, [
                'variable_pick' => $this->gameRegistryService->isVariablePick($name),
                'pick_range' => $this->gameRegistryService->getPickRange($name),
                'supports_full_system' => $this->gameRegistryService->supportsFullSystem($name),
                'system_pick_range' => $this->gameRegistryService->getSystemPickRange($name),
            ]);
        }

        return new JsonResponse([
            'games' => $games,
        ]);
    }

    #[Route('/generate', name: 'api_generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $gameName = isset($payload['game']) && \is_string($payload['game']) ? trim($payload['game']) : '';
        if ($gameName === '' || !$this->gameRegistryService->isValidGame($gameName)) {
            return $this->badRequest(sprintf('Invalid or missing game "%s".', $gameName));
        }

        $gameConfig = $this->gameRegistryService->getGameConfig($gameName);
        $maxNumber = (int) ($gameConfig['from'] ?? 49);

        if (isset($payload['system']) && is_numeric($payload['system']) && $this->gameRegistryService->supportsFullSystem($gameName)) {
            $gameConfig['pick'] = $this->gameRegistryService->resolveSystemPick($gameName, (int) $payload['system']);
        } elseif (isset($payload['pick']) && is_numeric($payload['pick']) && $this->gameRegistryService->isVariablePick($gameName)) {
            $gameConfig['pick'] = $this->gameRegistryService->resolvePick($gameName, (int) $payload['pick']);
        }

        $pick = (int) ($gameConfig['pick'] ?? 6);
        $pool = $this->parseNumberPool($payload['pool'] ?? null, $maxNumber);

        if (\count($pool) < $pick) {
            return $this->badRequest(sprintf(
                'Pool size (%d) must be at least the required pick count (%d) for %s.',
                \count($pool),
                $pick,
                $gameName
            ));
        }

        $mode = isset($payload['mode']) ? trim((string) $payload['mode']) : '1';
        if (!\in_array($mode, ['1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            return $this->badRequest(sprintf('Unsupported generation mode "%s" (expected 1..9).', $mode));
        }

        $betsTotal = isset($payload['bets']) ? (int) $payload['bets'] : (isset($payload['betsTotal']) ? (int) $payload['betsTotal'] : 10);
        if ($betsTotal < 1 || $betsTotal > 5000) {
            return $this->badRequest('Parameter "bets" must be between 1 and 5000.');
        }

        $frequencies = [];
        $draws = [];
        $latestDraw = [];

        $needsHistory = \in_array($mode, ['7', '8', '9'], true) || !empty($payload['use_history']);
        if ($needsHistory) {
            [$frequencies, $draws, $latestDraw] = $this->loadDrawsAndFrequencies(
                $gameName,
                isset($payload['sessions']) && is_numeric($payload['sessions']) ? (int) $payload['sessions'] : 50
            );
        }

        if (isset($payload['frequencies']) && \is_array($payload['frequencies'])) {
            $frequencies = [];
            foreach ($payload['frequencies'] as $num => $freq) {
                $frequencies[(int) $num] = (int) $freq;
            }
        }

        if (isset($payload['draws']) && \is_array($payload['draws'])) {
            $draws = array_values(array_map(
                static fn(mixed $d): array => \is_array($d) ? array_values(array_map('intval', $d)) : [],
                $payload['draws']
            ));
        }

        if (isset($payload['latest_draw']) && \is_array($payload['latest_draw'])) {
            $latestDraw = array_values(array_map('intval', $payload['latest_draw']));
        }

        $bankers = $this->parseNumberList($payload['bankers'] ?? [], $maxNumber);
        $hotNumbers = $this->parseNumberList($payload['hot_numbers'] ?? $payload['hotNumbers'] ?? [], $maxNumber);

        try {
            $pipelineRequest = new BetPipelineRequest(
                game: $gameConfig,
                pool: $pool,
                mode: $mode,
                betsTotal: $betsTotal,
                frequencies: $frequencies,
                draws: $draws,
                bankers: $bankers,
                bankersPerBet: isset($payload['bankers_per_bet']) ? (int) $payload['bankers_per_bet'] : (isset($payload['bankersPerBet']) ? (int) $payload['bankersPerBet'] : 3),
                l1Size: isset($payload['l1_size']) ? (int) $payload['l1_size'] : (isset($payload['l1Size']) ? (int) $payload['l1Size'] : 12),
                l1Count: isset($payload['l1_count']) ? (int) $payload['l1_count'] : (isset($payload['l1Count']) ? (int) $payload['l1Count'] : 4),
                l2Size: isset($payload['l2_size']) ? (int) $payload['l2_size'] : (isset($payload['l2Size']) ? (int) $payload['l2Size'] : 8),
                l2Count: isset($payload['l2_count']) ? (int) $payload['l2_count'] : (isset($payload['l2Count']) ? (int) $payload['l2Count'] : 2),
                blockSize: isset($payload['block_size']) ? (int) $payload['block_size'] : (isset($payload['blockSize']) ? (int) $payload['blockSize'] : 12),
                blockCount: isset($payload['block_count']) ? (int) $payload['block_count'] : (isset($payload['blockCount']) ? (int) $payload['blockCount'] : 5),
                hotNumbers: $hotNumbers,
                weight: isset($payload['weight']) ? (int) $payload['weight'] : 5,
                coverDecades: (bool) ($payload['cover_decades'] ?? $payload['coverDecades'] ?? false),
                withNeighbours: (bool) ($payload['with_neighbours'] ?? $payload['withNeighbours'] ?? false),
                neighboursRatio: isset($payload['neighbours_ratio']) ? (float) $payload['neighbours_ratio'] : (isset($payload['neighboursRatio']) ? (float) $payload['neighboursRatio'] : 0.6),
                latestDraw: $latestDraw,
            );

            $result = $this->betPipelineService->run($pipelineRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->badRequest($e->getMessage());
        }

        $warnings = $result->warnings;
        $aiCommentary = null;
        if (!empty($payload['ai'])) {
            $provider = isset($payload['provider']) && \is_string($payload['provider']) ? $payload['provider'] : null;
            $model = isset($payload['model']) && \is_string($payload['model']) ? $payload['model'] : null;
            $aiCommentary = $this->generateAiCommentary(
                $gameName,
                $pick,
                $maxNumber,
                \count($pool),
                $result->count(),
                $result->statsReport ?? [],
                null,
                $provider,
                $model,
                $warnings,
            );
        }

        return new JsonResponse([
            'game' => $gameName,
            'mode' => $mode,
            'pick' => $pick,
            'pool' => $pool,
            'count' => $result->count(),
            'bets' => $result->bets,
            'extra_sets' => $result->extraSets,
            'stats_report' => $result->statsReport,
            'extra_info' => $result->extraInfo,
            'ai_commentary' => $aiCommentary,
            'warnings' => $warnings,
        ]);
    }

    #[Route('/stats', name: 'api_stats', methods: ['POST'])]
    public function stats(Request $request): JsonResponse
    {
        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $gameName = isset($payload['game']) && \is_string($payload['game']) ? trim($payload['game']) : '';
        if ($gameName === '' || !$this->gameRegistryService->isValidGame($gameName)) {
            return $this->badRequest(sprintf('Invalid or missing game "%s".', $gameName));
        }

        $gameConfig = $this->gameRegistryService->getGameConfig($gameName);
        $maxNumber = (int) ($gameConfig['from'] ?? 49);
        $pick = (int) ($gameConfig['pick'] ?? 6);

        if (isset($payload['system']) && is_numeric($payload['system']) && $this->gameRegistryService->supportsFullSystem($gameName)) {
            $pick = $this->gameRegistryService->resolveSystemPick($gameName, (int) $payload['system']);
        } elseif (isset($payload['pick']) && is_numeric($payload['pick']) && $this->gameRegistryService->isVariablePick($gameName)) {
            $pick = $this->gameRegistryService->resolvePick($gameName, (int) $payload['pick']);
        }

        $poolInput = $payload['pool'] ?? 'all';
        $pool = $this->parseNumberPool($poolInput, $maxNumber);
        if (\count($pool) < $pick) {
            return $this->badRequest(sprintf(
                'Pool size (%d) must be at least the required pick count (%d).',
                \count($pool),
                $pick
            ));
        }

        $defaultBets = \count($pool) >= 40 ? 25 : 15;
        $betsCount = isset($payload['bets']) ? (int) $payload['bets'] : $defaultBets;
        if ($betsCount < 1 || $betsCount > 5000) {
            return $this->badRequest('Parameter "bets" must be between 1 and 5000.');
        }

        $sessions = isset($payload['sessions']) && is_numeric($payload['sessions']) ? (int) $payload['sessions'] : null;
        $months = isset($payload['months']) && is_numeric($payload['months']) ? (int) $payload['months'] : null;
        if ($sessions === null && $months === null) {
            $sessions = 50;
        }

        $warnings = [];
        [$frequencies, $draws] = $this->loadDrawsAndFrequencies($gameName, $sessions ?? 50, $months, $warnings);

        $fullCoverage = !isset($payload['full_coverage']) || (bool) $payload['full_coverage'];
        $coverDecades = (bool) ($payload['cover_decades'] ?? false);
        $withNeighbours = (bool) ($payload['with_neighbours'] ?? false);

        try {
            $optimizationResult = $this->statisticalOptimizerService->optimizeBetsForDilution(
                $pool,
                $pick,
                $betsCount,
                $frequencies,
                $maxNumber,
                [
                    'full_coverage' => $fullCoverage,
                    'draws' => $draws,
                    'cover_decades' => $coverDecades,
                    'with_neighbours' => $withNeighbours,
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return $this->badRequest($e->getMessage());
        }

        $dilution = $this->statisticalOptimizerService->calculateDilutionMetrics($pool, $pick, \count($optimizationResult['bets']), $maxNumber);
        $gaussian = $this->statisticalOptimizerService->calculateGaussianParameters($maxNumber, $pick);

        $aiCommentary = null;
        if (!empty($payload['ai'])) {
            $provider = isset($payload['provider']) && \is_string($payload['provider']) ? $payload['provider'] : null;
            $model = isset($payload['model']) && \is_string($payload['model']) ? $payload['model'] : null;
            $aiCommentary = $this->generateAiCommentary(
                $gameName,
                $pick,
                $maxNumber,
                \count($pool),
                \count($optimizationResult['bets']),
                $optimizationResult['report'] ?? [],
                (string) ($gaussian['optimal_range_str'] ?? ''),
                $provider,
                $model,
                $warnings,
            );
        }

        return new JsonResponse([
            'game' => $gameName,
            'pick' => $pick,
            'max_number' => $maxNumber,
            'pool' => $pool,
            'count' => \count($optimizationResult['bets']),
            'bets' => $optimizationResult['bets'],
            'report' => $optimizationResult['report'],
            'dilution' => $dilution,
            'gaussian' => $gaussian,
            'draws_analyzed' => \count($draws),
            'ai_commentary' => $aiCommentary,
            'warnings' => $warnings,
        ]);
    }

    #[Route('/agent/run', name: 'api_agent_run', methods: ['POST'])]
    public function runAgent(Request $request): JsonResponse
    {
        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $gameName = isset($payload['game']) && \is_string($payload['game']) ? trim($payload['game']) : '';
        if ($gameName === '' || !$this->gameRegistryService->isValidGame($gameName)) {
            return $this->badRequest(sprintf('Invalid or missing game "%s".', $gameName));
        }

        $gameConfig = $this->gameRegistryService->getGameConfig($gameName);
        $maxNumber = (int) ($gameConfig['from'] ?? 49);
        $pick = (int) ($gameConfig['pick'] ?? 6);

        $poolSize = isset($payload['pool_size']) ? (int) $payload['pool_size'] : (isset($payload['poolSize']) ? (int) $payload['poolSize'] : min($maxNumber, max($pick * 2, 18)));
        if ($poolSize < $pick || $poolSize > $maxNumber) {
            return $this->badRequest(sprintf(
                'Parameter "pool_size" (%d) must be between %d and %d for %s.',
                $poolSize,
                $pick,
                $maxNumber,
                $gameName
            ));
        }

        $strategy = isset($payload['strategy']) && \is_string($payload['strategy']) ? trim($payload['strategy']) : 'balanced';
        if (!\in_array($strategy, ['balanced', 'syndicate', 'aggressive', 'decades'], true)) {
            return $this->badRequest(sprintf('Invalid strategy "%s" (expected balanced, syndicate, aggressive, or decades).', $strategy));
        }

        $sessions = isset($payload['sessions']) && is_numeric($payload['sessions']) ? (int) $payload['sessions'] : null;
        $months = isset($payload['months']) && is_numeric($payload['months']) ? (int) $payload['months'] : null;
        $includeNeighbours = (bool) ($payload['include_neighbours'] ?? $payload['includeNeighbours'] ?? false);
        $coverDecades = (bool) ($payload['cover_decades'] ?? $payload['coverDecades'] ?? false);
        $provider = isset($payload['provider']) && \is_string($payload['provider']) && trim($payload['provider']) !== '' ? trim($payload['provider']) : null;
        $model = isset($payload['model']) && \is_string($payload['model']) && trim($payload['model']) !== '' ? trim($payload['model']) : null;

        $result = $this->reActAgentService->runAgentLoop(
            game: $gameName,
            poolSize: $poolSize,
            strategy: $strategy,
            onStepCallback: null,
            sessions: $sessions,
            months: $months,
            includeNeighbours: $includeNeighbours,
            coverDecades: $coverDecades,
            provider: $provider,
            model: $model
        );

        return new JsonResponse([
            'game' => $gameName,
            'strategy' => $strategy,
            'pool' => $result['pool'],
            'selected_pool' => $result['selected_pool'],
            'reasoning' => $result['reasoning'],
            'steps' => $result['steps'],
            'is_fallback' => $result['is_fallback'],
        ]);
    }

    #[Route('/archive/status', name: 'api_archive_status', methods: ['GET'])]
    public function archiveStatus(Request $request): JsonResponse
    {
        $gameParam = $request->query->get('game');
        if (\is_string($gameParam) && trim($gameParam) !== '') {
            $gameName = trim($gameParam);
            if (!$this->gameRegistryService->isValidGame($gameName)) {
                return $this->badRequest(sprintf('Invalid game "%s".', $gameName));
            }
            $games = [$gameName];
        } else {
            $games = $this->gameRegistryService->getGameNames();
        }

        $archives = [];
        foreach ($games as $game) {
            $archives[$game] = $this->drawArchiveService->freshness($game);
        }

        return new JsonResponse([
            'archives' => $archives,
        ]);
    }

    #[Route('/archive/sync', name: 'api_archive_sync', methods: ['POST'])]
    public function archiveSync(Request $request): JsonResponse
    {
        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $gameName = isset($payload['game']) && \is_string($payload['game']) ? trim($payload['game']) : 'Lotto';
        $sessions = isset($payload['sessions']) ? (int) $payload['sessions'] : (isset($payload['days']) ? (int) $payload['days'] : 60);
        if ($sessions < 1 || $sessions > 4000) {
            return $this->badRequest('Parameter "sessions" must be between 1 and 4000.');
        }

        $mode = isset($payload['mode']) && \is_string($payload['mode']) ? trim($payload['mode']) : 'refresh';
        if (!\in_array($mode, ['refresh', 'backfill', 'repair'], true)) {
            return $this->badRequest(sprintf('Invalid sync mode "%s" (expected refresh, backfill, or repair).', $mode));
        }

        if (strtolower($gameName) === 'all') {
            $allGames = $this->gameRegistryService->getGameNames();
            $syncResult = $this->drawArchiveService->backfillAllGames($allGames, $sessions);
            $freshness = [];
            foreach ($allGames as $g) {
                $freshness[$g] = $this->drawArchiveService->freshness($g);
            }

            return new JsonResponse([
                'game' => 'all',
                'mode' => 'backfill',
                'result' => $syncResult,
                'freshness' => $freshness,
            ]);
        }

        if (!$this->gameRegistryService->isValidGame($gameName)) {
            return $this->badRequest(sprintf('Invalid game "%s".', $gameName));
        }

        $syncResult = match ($mode) {
            'backfill' => $this->drawArchiveService->backfill($gameName, $sessions),
            'repair' => $this->drawArchiveService->repair($gameName, $sessions),
            default => $this->drawArchiveService->refresh($gameName, $sessions),
        };

        return new JsonResponse([
            'game' => $gameName,
            'mode' => $mode,
            'result' => $syncResult,
            'freshness' => $this->drawArchiveService->freshness($gameName),
        ]);
    }

    #[Route('/settings', name: 'api_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        return new JsonResponse($this->buildSettingsPayload());
    }

    #[Route('/settings', name: 'api_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $payload = $this->decodeJsonBody($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $envFile = $this->resolveEnvFilePath();
        $existing = $this->readEnvFile($envFile);

        if (isset($payload['ai_provider']) && \is_string($payload['ai_provider'])) {
            $provider = strtolower(trim($payload['ai_provider']));
            if (!\in_array($provider, $this->llmClientResolver->getAvailableProviders(), true)) {
                return $this->badRequest(sprintf('Unsupported AI provider "%s".', $provider));
            }
            $existing['AI_PROVIDER'] = $provider;
        }

        if (\array_key_exists('ai_model', $payload) && (\is_string($payload['ai_model']) || $payload['ai_model'] === null)) {
            $existing['AI_MODEL'] = trim((string) $payload['ai_model']);
        }

        if (\array_key_exists('openai_base_url', $payload) && (\is_string($payload['openai_base_url']) || $payload['openai_base_url'] === null)) {
            $existing['OPENAI_BASE_URL'] = trim((string) $payload['openai_base_url']);
        }

        $keysInput = isset($payload['keys']) && \is_array($payload['keys']) ? $payload['keys'] : $payload;
        foreach (self::SECRET_ENV_KEYS as $secretKey) {
            if (\array_key_exists($secretKey, $keysInput)) {
                $val = $keysInput[$secretKey];
                if (\is_string($val)) {
                    $existing[$secretKey] = trim($val);
                } elseif ($val === null) {
                    $existing[$secretKey] = '';
                }
            }
        }

        $this->writeEnvFile($envFile, $existing);

        foreach ($existing as $k => $v) {
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
            if (\function_exists('putenv')) {
                putenv("$k=$v");
            }
        }

        return new JsonResponse($this->buildSettingsPayload());
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeJsonBody(Request $request): array|JsonResponse
    {
        $raw = trim($request->getContent());
        if ($raw === '') {
            return $this->badRequest('Request body must be a non-empty JSON object.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->badRequest('Invalid JSON body: ' . $e->getMessage());
        }

        if (!\is_array($decoded)) {
            return $this->badRequest('Request body must decode to a JSON object.');
        }

        return $decoded;
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @return list<int>
     */
    private function parseNumberPool(mixed $input, int $maxNumber): array
    {
        if (\is_string($input)) {
            $trimmed = strtolower(trim($input));
            if ($trimmed === 'all' || $trimmed === "1-$maxNumber") {
                return range(1, $maxNumber);
            }
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $trimmed, $m)) {
                $start = max(1, min($maxNumber, (int) $m[1]));
                $end = max(1, min($maxNumber, (int) $m[2]));
                if ($start <= $end) {
                    return range($start, $end);
                }
            }
            preg_match_all('/\d+/', $trimmed, $matches);

            return $this->parseNumberList($matches[0] ?? [], $maxNumber);
        }

        if (\is_array($input)) {
            return $this->parseNumberList($input, $maxNumber);
        }

        return [];
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private function parseNumberList(array $values, int $maxNumber): array
    {
        $clean = [];
        foreach ($values as $v) {
            if (\is_int($v) || (\is_string($v) && ctype_digit(trim($v)))) {
                $num = (int) $v;
                if ($num >= 1 && $num <= $maxNumber) {
                    $clean[] = $num;
                }
            }
        }

        $clean = array_values(array_unique($clean));
        sort($clean);

        return $clean;
    }

    /**
     * @param list<string> $warnings
     * @return array{0: array<int, int>, 1: list<list<int>>, 2: list<int>}
     */
    private function loadDrawsAndFrequencies(
        string $gameName,
        int $sessions = 50,
        ?int $months = null,
        array &$warnings = []
    ): array {
        $chronology = $this->drawArchiveService->getChronology($gameName);
        if ($chronology !== []) {
            $slice = \array_slice($chronology, -max(1, $sessions));
            $draws = array_reverse(array_map(static fn(array $r): array => $r['numbers'], $slice));
            $frequencies = [];
            foreach ($draws as $d) {
                foreach ($d as $n) {
                    $frequencies[$n] = ($frequencies[$n] ?? 0) + 1;
                }
            }
            arsort($frequencies);
            $latestDraw = $draws[0] ?? [];

            return [$frequencies, $draws, $latestDraw];
        }

        $history = $this->historicalDataProvider->fetch($gameName, $sessions, $months);
        if ($history['warning'] !== null) {
            $warnings[] = $history['warning'];
        }

        $draws = $history['draws'];
        $frequencies = $history['frequencies'];
        $latestDraw = $draws[0] ?? [];

        return [$frequencies, $draws, $latestDraw];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSettingsPayload(): array
    {
        $envFile = $this->resolveEnvFilePath();
        $fileValues = $this->readEnvFile($envFile);

        $keys = [];
        foreach (self::SECRET_ENV_KEYS as $secretKey) {
            $raw = $fileValues[$secretKey] ?? $this->readRuntimeEnv($secretKey);
            $keys[$secretKey] = [
                'configured' => $raw !== '',
                'masked' => $this->maskSecret($raw),
            ];
        }

        $aiProvider = $fileValues['AI_PROVIDER'] ?? $this->readRuntimeEnv('AI_PROVIDER');
        if ($aiProvider === '') {
            $aiProvider = $this->llmClientResolver->getDefaultProvider();
        }

        $aiModel = $fileValues['AI_MODEL'] ?? $this->readRuntimeEnv('AI_MODEL');
        if ($aiModel === '') {
            $aiModel = $this->llmClientResolver->getDefaultModel();
        }

        $openAiBaseUrl = $fileValues['OPENAI_BASE_URL'] ?? $this->readRuntimeEnv('OPENAI_BASE_URL');

        return [
            'keys' => $keys,
            'ai_provider' => $aiProvider,
            'ai_model' => $aiModel,
            'openai_base_url' => $openAiBaseUrl,
            'providers_catalog' => $this->llmClientResolver->getProvidersCatalog(),
        ];
    }

    private function resolveEnvFilePath(): string
    {
        $envFile = $this->readRuntimeEnv('LOTTO_ENV_FILE');
        if ($envFile !== '') {
            return $envFile;
        }

        $appDataDir = $this->readRuntimeEnv('LOTTO_APP_DATA_DIR');
        if ($appDataDir !== '') {
            return rtrim($appDataDir, '/\\') . '/.env.desktop';
        }

        $base = $this->projectDir !== '' ? $this->projectDir : \dirname(__DIR__, 2);

        return $base . '/var/.env.desktop';
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $content = (string) file_get_contents($path);
        if (trim($content) === '') {
            return [];
        }

        try {
            return (new Dotenv())->parse($content, $path);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnvFile(string $path, array $values): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $allowedKeys = array_merge(self::SECRET_ENV_KEYS, self::CONFIG_ENV_KEYS);
        $lines = ['# Lotto Agent Desktop user settings (generated)'];
        foreach ($allowedKeys as $key) {
            if (\array_key_exists($key, $values)) {
                $escaped = addcslashes((string) $values[$key], "\"\\\n\r");
                $lines[] = sprintf('%s="%s"', $key, $escaped);
            }
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        @chmod($path, 0600);
    }

    private function readRuntimeEnv(string $key): string
    {
        $val = $_SERVER[$key] ?? $_ENV[$key] ?? (getenv($key) !== false ? (string) getenv($key) : '');

        return \is_string($val) ? trim($val) : '';
    }

    private function maskSecret(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $len = \strlen($trimmed);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($trimmed, 0, 4) . '...' . substr($trimmed, -4);
    }

    #[Route('/logs', name: 'api_logs', methods: ['GET'])]
    public function logs(Request $request): JsonResponse
    {
        $limit = max(1, min(1000, (int) $request->query->get('limit', 300)));
        $levelFilter = strtoupper(trim((string) $request->query->get('level', '')));

        $logDir = $this->resolveLogDir();
        $files = is_dir($logDir) ? (glob($logDir . '/*.log') ?: []) : [];
        sort($files);

        $entries = [];
        $idCounter = 1;

        foreach ($files as $filePath) {
            if (!is_readable($filePath)) {
                continue;
            }
            $fileName = basename($filePath);
            $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!\is_array($lines)) {
                continue;
            }

            // Read tail of each file to keep memory bounded
            $tail = \array_slice($lines, -$limit);
            foreach ($tail as $line) {
                $parsed = $this->parseLogLine($line, $fileName, $idCounter++);
                if ($levelFilter !== '' && $parsed['level'] !== $levelFilter) {
                    continue;
                }
                $entries[] = $parsed;
            }
        }

        // Most recent first
        $entries = array_reverse(\array_slice($entries, -$limit));

        return new JsonResponse([
            'logs' => $entries,
            'files' => array_map('basename', $files),
            'log_dir' => $logDir,
        ]);
    }

    #[Route('/logs', name: 'api_logs_clear', methods: ['DELETE'])]
    public function clearLogs(): JsonResponse
    {
        $logDir = $this->resolveLogDir();
        $files = is_dir($logDir) ? (glob($logDir . '/*.log') ?: []) : [];
        $cleared = 0;

        foreach ($files as $filePath) {
            if (is_writable($filePath) && @file_put_contents($filePath, '') !== false) {
                ++$cleared;
            }
        }

        return new JsonResponse([
            'cleared' => $cleared,
        ]);
    }

    private function resolveLogDir(): string
    {
        $appLogDir = $this->readRuntimeEnv('APP_LOG_DIR');
        if ($appLogDir !== '') {
            return rtrim($appLogDir, '/\\');
        }

        $appDataDir = $this->readRuntimeEnv('LOTTO_APP_DATA_DIR');
        if ($appDataDir !== '') {
            return rtrim($appDataDir, '/\\') . '/var/log';
        }

        $base = $this->projectDir !== '' ? $this->projectDir : \dirname(__DIR__, 2);

        return $base . '/var/log';
    }

    /**
     * @return array{id: string, timestamp: string, level: string, source: string, message: string}
     */
    private function parseLogLine(string $line, string $fileName, int $index): array
    {
        // Standard Monolog format: [2026-11-18T10:00:00.000000+00:00] app.INFO: Message ...
        if (preg_match('/^\[([^\]]+)\]\s+([a-zA-Z0-9_-]+)\.([A-Z]+):\s*(.*)$/', $line, $m) === 1) {
            return [
                'id' => $fileName . '-' . $index,
                'timestamp' => $m[1],
                'level' => strtoupper($m[3]),
                'source' => $m[2] . ' (' . $fileName . ')',
                'message' => $m[4],
            ];
        }

        return [
            'id' => $fileName . '-' . $index,
            'timestamp' => '',
            'level' => 'INFO',
            'source' => $fileName,
            'message' => $line,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param list<string>         $warnings
     */
    private function generateAiCommentary(
        string $gameName,
        int $pick,
        int $maxNumber,
        int $poolSize,
        int $betsCount,
        array $report,
        ?string $optimalRangeOverride,
        ?string $provider,
        ?string $model,
        array &$warnings,
    ): ?string {
        try {
            $llmClient = $this->llmClientResolver->resolve($provider, $model);
            $gaussAnalysis = \is_array($report['gaussian_analysis'] ?? null) ? $report['gaussian_analysis'] : [];
            $optimalRange = $optimalRangeOverride ?: (string) ($gaussAnalysis['optimal_range'] ?? 'N/A');
            $prompt = sprintf(
                "Jesteś Głównym Analitykiem Gier Liczbowych. Przygotuj zwięzłą (3-4 zdania) analizę statystyczną i rekomendację dla wygenerowanego pakietu gry %s (%d z %d, pula %d liczb, %d kuponów, %d%% w optymalnym oknie Gaussa %s).",
                $gameName,
                $pick,
                $maxNumber,
                $poolSize,
                $betsCount,
                (int) ($gaussAnalysis['in_optimal_range_pct'] ?? 0),
                $optimalRange
            );

            return $llmClient->generateText($prompt);
        } catch (\Throwable $e) {
            $warnings[] = 'AI commentary unavailable: ' . $e->getMessage();

            return null;
        }
    }
}
