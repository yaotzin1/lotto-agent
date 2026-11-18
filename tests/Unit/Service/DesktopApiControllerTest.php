<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Controller\DesktopApiController;
use App\Controller\DesktopTokenSubscriber;
use App\Kernel;
use App\Service\AgentTools\ToolRegistry;
use App\Service\BetGeneratorService;
use App\Service\BetPipelineService;
use App\Service\DrawArchiveService;
use App\Service\DrawHistoryProvider;
use App\Service\ExtraNumbersGenerator;
use App\Service\GameRegistryService;
use App\Service\GeminiApiClient;
use App\Service\HistoricalDataProvider;
use App\Service\Llm\AnthropicApiClient;
use App\Service\Llm\LlmClientResolver;
use App\Service\Llm\OpenAiApiClient;
use App\Service\LottoApiClient;
use App\Service\ReActAgentService;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class DesktopApiControllerTest extends TestCase
{
    private string $tempDir;
    private DesktopApiController $controller;
    private GameRegistryService $gameRegistry;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lotto_desktop_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/data/draws', 0777, true);
        mkdir($this->tempDir . '/var/draw-history', 0777, true);

        file_put_contents($this->tempDir . '/data/lotto_draws.json', json_encode([
            ['date' => '2026-09-01', 'numbers' => [1, 5, 12, 23, 34, 45]],
            ['date' => '2026-09-03', 'numbers' => [2, 6, 14, 24, 35, 48]],
            ['date' => '2026-09-05', 'numbers' => [3, 7, 15, 25, 36, 49]],
            ['date' => '2026-09-08', 'numbers' => [4, 8, 16, 26, 37, 44]],
            ['date' => '2026-09-10', 'numbers' => [5, 9, 17, 27, 38, 43]],
            ['date' => '2026-09-12', 'numbers' => [1, 5, 18, 28, 39, 42]],
        ]));

        $_ENV['LOTTO_APP_DATA_DIR'] = $this->tempDir;
        $_SERVER['LOTTO_APP_DATA_DIR'] = $this->tempDir;
        $_ENV['LOTTO_ENV_FILE'] = $this->tempDir . '/.env.desktop';
        $_SERVER['LOTTO_ENV_FILE'] = $this->tempDir . '/.env.desktop';
        unset($_ENV['LOTTO_DESKTOP_TOKEN'], $_SERVER['LOTTO_DESKTOP_TOKEN']);
        putenv('LOTTO_DESKTOP_TOKEN');

        $logger = new NullLogger();
        $this->gameRegistry = new GameRegistryService();
        $optimizer = new StatisticalOptimizerService($this->gameRegistry, $logger);
        $betGenerator = new BetGeneratorService();
        $extraGenerator = new ExtraNumbersGenerator();
        $pipeline = new BetPipelineService($betGenerator, $optimizer, $extraGenerator);

        $mockHttp = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, 'generativelanguage.googleapis.com')) {
                return new MockResponse(json_encode([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'text' => json_encode([
                                    'reasoning' => 'Balanced statistical selection.',
                                    'selected_pool' => [1, 5, 9, 12, 18, 23, 27, 34, 39, 42, 45, 49],
                                ]),
                            ]],
                        ],
                    ]],
                ]));
            }

            return new MockResponse(json_encode(['items' => []]), ['http_code' => 404]);
        });

        $lottoApi = new LottoApiClient($mockHttp, $logger, $this->gameRegistry, 'test_lotto_key');
        $drawHistory = new DrawHistoryProvider($lottoApi, $this->gameRegistry, $logger, $this->tempDir . '/var/draw-history', 100);
        $historicalData = new HistoricalDataProvider($lottoApi, $this->gameRegistry, $drawHistory);
        $drawArchive = new DrawArchiveService($drawHistory, $this->gameRegistry, $logger, $this->tempDir . '/data');

        $gemini = new GeminiApiClient($mockHttp, $logger, 'gemini_test_key');
        $anthropic = new AnthropicApiClient($mockHttp, $logger, 'anthropic_test_key');
        $openAi = new OpenAiApiClient($mockHttp, $logger, 'openai_test_key', 'deepseek_test_key');
        $llmResolver = new LlmClientResolver($gemini, $anthropic, $openAi, 'gemini', '');
        $toolRegistry = new ToolRegistry([]);
        $reActAgent = new ReActAgentService($gemini, $toolRegistry, $this->gameRegistry, $logger, llmClientResolver: $llmResolver);

        $this->controller = new DesktopApiController(
            $this->gameRegistry,
            $pipeline,
            $optimizer,
            $historicalData,
            $drawArchive,
            $reActAgent,
            $llmResolver,
            $this->tempDir
        );
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['LOTTO_APP_DATA_DIR'],
            $_SERVER['LOTTO_APP_DATA_DIR'],
            $_ENV['LOTTO_ENV_FILE'],
            $_SERVER['LOTTO_ENV_FILE'],
            $_ENV['LOTTO_DESKTOP_TOKEN'],
            $_SERVER['LOTTO_DESKTOP_TOKEN']
        );
        putenv('LOTTO_DESKTOP_TOKEN');
        $this->removeDirectory($this->tempDir);
    }

    public function testHealthEndpointReturnsOk(): void
    {
        $response = $this->controller->health();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('lotto-agent', $data['app']);
    }

    public function testGamesEndpointListsSupportedGamesWithMetadata(): void
    {
        $response = $this->controller->games();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('games', $data);
        $this->assertArrayHasKey('Lotto', $data['games']);
        $this->assertArrayHasKey('MiniLotto', $data['games']);
        $this->assertArrayHasKey('MultiMulti', $data['games']);
        $this->assertTrue($data['games']['MultiMulti']['variable_pick']);
        $this->assertTrue($data['games']['Lotto']['supports_full_system']);
    }

    public function testGenerateEndpointCreatesCouponsAndValidatesInputs(): void
    {
        $request = Request::create('/api/generate', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'game' => 'Lotto',
            'pool' => [1, 3, 5, 8, 12, 17, 21, 26, 31, 35, 42, 48],
            'mode' => '8',
            'bets' => 5,
            'cover_decades' => true,
        ]));

        $response = $this->controller->generate($request);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Lotto', $data['game']);
        $this->assertSame(5, $data['count']);
        $this->assertCount(5, $data['bets']);
        $this->assertIsArray($data['stats_report']);

        // Invalid JSON -> 400
        $badJsonReq = Request::create('/api/generate', 'POST', content: '{invalid');
        $badJsonRes = $this->controller->generate($badJsonReq);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $badJsonRes->getStatusCode());

        // Pool smaller than pick -> 400
        $smallPoolReq = Request::create('/api/generate', 'POST', content: json_encode([
            'game' => 'Lotto',
            'pool' => [1, 2, 3],
            'mode' => '1',
            'bets' => 2,
        ]));
        $smallPoolRes = $this->controller->generate($smallPoolReq);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $smallPoolRes->getStatusCode());
    }

    public function testStatsEndpointReturnsOptimizerReportAndAiCommentary(): void
    {
        $request = Request::create('/api/stats', 'POST', content: json_encode([
            'game' => 'MiniLotto',
            'pool' => '1-20',
            'bets' => 6,
            'full_coverage' => true,
            'ai' => true,
            'provider' => 'gemini',
        ]));

        $response = $this->controller->stats($request);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('MiniLotto', $data['game']);
        $this->assertCount(6, $data['bets']);
        $this->assertArrayHasKey('dilution', $data);
        $this->assertArrayHasKey('gaussian', $data);
        $this->assertArrayHasKey('report', $data);
        $this->assertNotEmpty($data['ai_commentary']);
    }

    public function testRunAgentEndpointReturnsSelectedPool(): void
    {
        $request = Request::create('/api/agent/run', 'POST', content: json_encode([
            'game' => 'Lotto',
            'pool_size' => 12,
            'strategy' => 'balanced',
            'provider' => 'gemini',
        ]));

        $response = $this->controller->runAgent($request);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Lotto', $data['game']);
        $this->assertCount(12, $data['selected_pool']);
        $this->assertFalse($data['is_fallback']);
    }

    public function testArchiveStatusAndSyncEndpoints(): void
    {
        $statusReq = Request::create('/api/archive/status?game=Lotto', 'GET');
        $statusRes = $this->controller->archiveStatus($statusReq);
        $this->assertSame(Response::HTTP_OK, $statusRes->getStatusCode());

        $statusData = json_decode((string) $statusRes->getContent(), true);
        $this->assertArrayHasKey('Lotto', $statusData['archives']);
        $this->assertSame(6, $statusData['archives']['Lotto']['total']);

        $syncReq = Request::create('/api/archive/sync', 'POST', content: json_encode([
            'game' => 'Lotto',
            'sessions' => 5,
            'mode' => 'refresh',
        ]));
        $syncRes = $this->controller->archiveSync($syncReq);
        $this->assertSame(Response::HTTP_OK, $syncRes->getStatusCode());
    }

    public function testSettingsGetAndSaveMasksSecrets(): void
    {
        $saveReq = Request::create('/api/settings', 'POST', content: json_encode([
            'ai_provider' => 'claude',
            'ai_model' => 'claude-3-7-sonnet-20250219',
            'keys' => [
                'GEMINI_API_KEY' => 'AIzaSySecretKeyValue123456',
                'LOTTO_API_KEY' => 'lotto_secret_987654321',
            ],
        ]));

        $saveRes = $this->controller->saveSettings($saveReq);
        $this->assertSame(Response::HTTP_OK, $saveRes->getStatusCode());

        $data = json_decode((string) $saveRes->getContent(), true);
        $this->assertSame('claude', $data['ai_provider']);
        $this->assertSame('claude-3-7-sonnet-20250219', $data['ai_model']);
        $this->assertTrue($data['keys']['GEMINI_API_KEY']['configured']);
        $this->assertSame('AIza...3456', $data['keys']['GEMINI_API_KEY']['masked']);
        $this->assertStringNotContainsString('AIzaSySecretKeyValue123456', (string) $saveRes->getContent());
    }

    public function testDesktopTokenSubscriberEnforcesBearerTokenAndRedactsSecrets(): void
    {
        $_SERVER['LOTTO_DESKTOP_TOKEN'] = 'super-secret-desktop-token';
        $_ENV['LOTTO_DESKTOP_TOKEN'] = 'super-secret-desktop-token';

        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber = new DesktopTokenSubscriber('super-secret-desktop-token');

        // 1. Missing token -> 401
        $unauthReq = Request::create('/api/games', 'GET', server: ['HTTP_ORIGIN' => 'http://localhost:1420']);
        $reqEvent = new RequestEvent($kernel, $unauthReq, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($reqEvent);
        $this->assertNotNull($reqEvent->getResponse());
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $reqEvent->getResponse()->getStatusCode());
        $this->assertSame('http://localhost:1420', $reqEvent->getResponse()->headers->get('Access-Control-Allow-Origin'));

        // 2. Valid token -> passes through
        $authReq = Request::create('/api/games', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer super-secret-desktop-token',
            'HTTP_ORIGIN' => 'tauri://localhost',
        ]);
        $authEvent = new RequestEvent($kernel, $authReq, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($authEvent);
        $this->assertNull($authEvent->getResponse());

        // 3. OPTIONS preflight -> 204 without requiring Authorization header
        $optReq = Request::create('/api/generate', 'OPTIONS', server: ['HTTP_ORIGIN' => 'http://localhost:5173']);
        $optEvent = new RequestEvent($kernel, $optReq, HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($optEvent);
        $this->assertNotNull($optEvent->getResponse());
        $this->assertSame(Response::HTTP_NO_CONTENT, $optEvent->getResponse()->getStatusCode());

        // 4. Exception redacts secret values
        $excEvent = new ExceptionEvent(
            $kernel,
            $authReq,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Failed with token super-secret-desktop-token')
        );
        $subscriber->onKernelException($excEvent);
        $excRes = $excEvent->getResponse();
        $this->assertNotNull($excRes);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $excRes->getStatusCode());
        $this->assertStringNotContainsString('super-secret-desktop-token', (string) $excRes->getContent());
        $this->assertStringContainsString('[REDACTED]', (string) $excRes->getContent());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
