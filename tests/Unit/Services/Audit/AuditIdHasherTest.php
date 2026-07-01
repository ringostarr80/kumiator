<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditIdHasher;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sichert, dass Audit-Ziel-IDs als HMAC mit dem APP_KEY gehasht werden, nicht
 * als ungesalzener SHA-256 — niedrig-entropische Integer-Primärschlüssel wären
 * sonst per Vorabtabelle offline reversibel. Nicht-skalare/fehlende Keys → `null`.
 */
final class AuditIdHasherTest extends TestCase
{
    public function testHashesScalarKeyAsHmacWithAppKey(): void
    {
        $expected = hash_hmac('sha256', '42', Config::string('app.key'));

        // Integer- und String-Repräsentation desselben Schlüssels sind gleich.
        $this->assertSame($expected, AuditIdHasher::hash(42));
        $this->assertSame($expected, AuditIdHasher::hash('42'));
    }

    public function testHmacDiffersFromUnsaltedSha256(): void
    {
        // ohne Salt wäre eine fortlaufende ID trivial rückrechenbar —
        // das HMAC darf nie dem plain-SHA-256 gleichen.
        $this->assertNotSame(hash('sha256', '42'), AuditIdHasher::hash(42));
    }

    #[DataProvider('nonScalarProvider')]
    public function testNonScalarKeyYieldsNull(mixed $key): void
    {
        $this->assertNull(AuditIdHasher::hash($key));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonScalarProvider(): array
    {
        return [
            'null → null' => [null],
            'array → null' => [[1, 2]],
        ];
    }
}
