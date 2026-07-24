<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

interface LottoToolInterface
{
    /**
     * Unique identifier of the tool for Gemini Function Calling.
     */
    public function getName(): string;

    /**
     * Human-readable description of what the tool does.
     */
    public function getDescription(): string;

    /**
     * OpenAPI / JSON schema array describing the tool's expected parameters.
     */
    public function getParametersSchema(): array;

    /**
     * Executes the tool with the given arguments and returns a stringified JSON result for the LLM observation.
     */
    public function execute(array $args): string;
}
