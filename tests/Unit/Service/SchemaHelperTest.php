<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Llm\SchemaHelper;
use PHPUnit\Framework\TestCase;

class SchemaHelperTest extends TestCase
{
    public function testNormalizeConvertsTypesToLowercase(): void
    {
        $input = [
            'type' => 'OBJECT',
            'properties' => [
                'game' => [
                    'type' => 'STRING',
                    'description' => 'Game name',
                ],
                'numbers' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'INTEGER',
                    ],
                ],
                'flag' => [
                    'type' => 'BOOLEAN',
                ],
            ],
            'required' => ['game', 'numbers'],
        ];

        $normalized = SchemaHelper::normalize($input);

        $this->assertSame('object', $normalized['type']);
        $this->assertSame('string', $normalized['properties']['game']['type']);
        $this->assertSame('array', $normalized['properties']['numbers']['type']);
        $this->assertSame('integer', $normalized['properties']['numbers']['items']['type']);
        $this->assertSame('boolean', $normalized['properties']['flag']['type']);
        $this->assertSame(['game', 'numbers'], $normalized['required']);
        $this->assertSame('Game name', $normalized['properties']['game']['description']);
    }
}
