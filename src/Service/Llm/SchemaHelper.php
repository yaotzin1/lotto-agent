<?php

declare(strict_types=1);

namespace App\Service\Llm;

final class SchemaHelper
{
    /**
     * Normalizes parameter schemas (where 'type' may be 'OBJECT', 'STRING', 'INTEGER', 'ARRAY')
     * to lowercase JSON Schema types required by Anthropic Claude and OpenAI.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        $normalized = [];
        foreach ($schema as $key => $value) {
            if ($key === 'type' && is_string($value)) {
                $normalized[$key] = strtolower($value);
            } elseif (is_array($value)) {
                $normalized[$key] = self::normalize($value);
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }
}
