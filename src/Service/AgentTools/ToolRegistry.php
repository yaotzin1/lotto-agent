<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class ToolRegistry
{
    /**
     * @var array<string, LottoToolInterface>
     */
    private array $tools = [];

    /**
     * @param iterable<LottoToolInterface> $tools
     */
    public function __construct(
        #[TaggedIterator('app.lotto_agent_tool')]
        iterable $tools = []
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }


    public function registerTool(LottoToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * @return array<string, LottoToolInterface>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function getTool(string $name): ?LottoToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Formats registered tools for Gemini API Function Declarations payload.
     */
    public function getGeminiFunctionDeclarations(): array
    {
        $declarations = [];

        foreach ($this->tools as $tool) {
            $declarations[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParametersSchema(),
            ];
        }

        return $declarations;
    }

    /**
     * Dispatches a tool by name with arguments and returns string result.
     */
    public function executeTool(string $name, array $args): string
    {
        $tool = $this->getTool($name);
        if (!$tool) {
            return json_encode(['error' => "Narzędzie '$name' nie zostało znalezione w rejestrze."]);
        }

        try {
            return $tool->execute($args);
        } catch (\Throwable $e) {
            return json_encode(['error' => "Błąd podczas wykonywania narzędzia '$name': " . $e->getMessage()]);
        }
    }
}
