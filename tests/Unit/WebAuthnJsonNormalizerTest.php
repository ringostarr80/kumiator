<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WebAuthn\WebAuthnJsonNormalizer;
use Tests\TestCase;

final class WebAuthnJsonNormalizerTest extends TestCase
{
    public function testPassthroughsScalarValues(): void
    {
        $this->assertSame('hello', WebAuthnJsonNormalizer::stripNulls('hello'));
        $this->assertSame(42, WebAuthnJsonNormalizer::stripNulls(42));
        $this->assertFalse(WebAuthnJsonNormalizer::stripNulls(false));
    }

    public function testReturnsNullAsIs(): void
    {
        $this->assertNull(WebAuthnJsonNormalizer::stripNulls(null));
    }

    public function testRemovesTopLevelNullValues(): void
    {
        $input = ['a' => 'value', 'b' => null, 'c' => 123];

        $result = WebAuthnJsonNormalizer::stripNulls($input);

        $this->assertSame(['a' => 'value', 'c' => 123], $result);
    }

    public function testRemovesNestedNullValues(): void
    {
        $input = [
            'rp' => ['name' => 'My App', 'id' => null],
            'user' => ['id' => 'abc', 'name' => 'user@example.com', 'displayName' => 'User'],
        ];

        $result = WebAuthnJsonNormalizer::stripNulls($input);

        $this->assertSame([
            'rp' => ['name' => 'My App'],
            'user' => ['id' => 'abc', 'name' => 'user@example.com', 'displayName' => 'User'],
        ], $result);
    }

    public function testPreservesFalseAndZeroAndEmptyString(): void
    {
        $input = ['falsy_bool' => false, 'zero' => 0, 'empty' => '', 'null' => null];

        $result = WebAuthnJsonNormalizer::stripNulls($input);

        $this->assertSame(['falsy_bool' => false, 'zero' => 0, 'empty' => ''], $result);
    }

    public function testHandlesEmptyArray(): void
    {
        $this->assertSame([], WebAuthnJsonNormalizer::stripNulls([]));
    }

    public function testHandlesDeeplyNestedStructure(): void
    {
        $input = [
            'extensions' => [
                'credProps' => true,
                'unused' => null,
                'nested' => ['keep' => 'yes', 'drop' => null],
            ],
        ];

        $result = WebAuthnJsonNormalizer::stripNulls($input);

        $this->assertSame([
            'extensions' => [
                'credProps' => true,
                'nested' => ['keep' => 'yes'],
            ],
        ], $result);
    }

    public function testNormalizeOptionsJsonDecodesAndStripsNulls(): void
    {
        $json = (string) json_encode(['rp' => ['name' => 'App', 'id' => null], 'timeout' => 60_000]);

        $result = WebAuthnJsonNormalizer::normalizeOptionsJson($json);

        $this->assertSame(['rp' => ['name' => 'App'], 'timeout' => 60_000], $result);
    }

    public function testNormalizeOptionsJsonReturnsEmptyArrayForInvalidJson(): void
    {
        $result = WebAuthnJsonNormalizer::normalizeOptionsJson('not-valid-json{{{');

        $this->assertSame([], $result);
    }

    public function testNormalizeOptionsJsonReturnsEmptyArrayForNonObjectJson(): void
    {
        $result = WebAuthnJsonNormalizer::normalizeOptionsJson('"just a string"');

        $this->assertSame([], $result);
    }
}
