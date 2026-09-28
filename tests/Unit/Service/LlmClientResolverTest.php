<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GeminiApiClient;
use App\Service\Llm\AnthropicApiClient;
use App\Service\Llm\LlmClientResolver;
use App\Service\Llm\OpenAiApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

class LlmClientResolverTest extends TestCase
{
    private LlmClientResolver $resolver;
    private GeminiApiClient $gemini;
    private AnthropicApiClient $anthropic;
    private OpenAiApiClient $openAi;

    protected function setUp(): void
    {
        $http = new MockHttpClient();
        $logger = new NullLogger();

        $this->gemini = new GeminiApiClient($http, $logger, 'gemini_test_key');
        $this->anthropic = new AnthropicApiClient($http, $logger, 'anthropic_test_key');
        $this->openAi = new OpenAiApiClient($http, $logger, 'openai_test_key', 'deepseek_test_key');

        $this->resolver = new LlmClientResolver(
            $this->gemini,
            $this->anthropic,
            $this->openAi,
            'gemini',
            ''
        );
    }

    public function testResolvesDefaultGemini(): void
    {
        $client = $this->resolver->resolve();
        $this->assertSame('gemini', $client->getProviderName());
        $this->assertSame('gemini-3.7-flash', $client->getModel());
    }

    public function testResolvesClaudeWithModel(): void
    {
        $client = $this->resolver->resolve('claude', 'claude-3-opus-20240229');
        $this->assertSame('claude', $client->getProviderName());
        $this->assertSame('claude-3-opus-20240229', $client->getModel());
    }

    public function testResolvesAnthropicAlias(): void
    {
        $client = $this->resolver->resolve('anthropic');
        $this->assertSame('claude', $client->getProviderName());
    }

    public function testResolvesOpenAiWithModel(): void
    {
        $client = $this->resolver->resolve('openai', 'gpt-5');
        $this->assertSame('openai', $client->getProviderName());
        $this->assertSame('gpt-5', $client->getModel());
    }

    public function testResolvesDeepSeek(): void
    {
        $client = $this->resolver->resolve('deepseek');
        $this->assertSame('deepseek', $client->getProviderName());
        $this->assertSame('deepseek-chat', $client->getModel());
    }

    public function testGetAvailableProviders(): void
    {
        $providers = $this->resolver->getAvailableProviders();
        $this->assertContains('gemini', $providers);
        $this->assertContains('claude', $providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('deepseek', $providers);
    }

    public function testGetProvidersCatalog(): void
    {
        $catalog = $this->resolver->getProvidersCatalog();
        $this->assertArrayHasKey('gemini', $catalog);
        $this->assertArrayHasKey('claude', $catalog);
        $this->assertArrayHasKey('openai', $catalog);
        $this->assertArrayHasKey('deepseek', $catalog);

        $this->assertSame('gemini-3.7-flash', $catalog['gemini']['defaultModel']);
        $this->assertSame('claude-3-7-sonnet-20250219', $catalog['claude']['defaultModel']);
        $this->assertSame('gpt-4o', $catalog['openai']['defaultModel']);
        $this->assertSame('deepseek-chat', $catalog['deepseek']['defaultModel']);
    }
}
