<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditIdHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sichert, dass Audit-Ziel-IDs als HMAC mit einem Schlüssel aus dem APP_KEY
 * gehasht werden, nicht als ungesalzener SHA-256 — niedrig-entropische
 * Integer-Primärschlüssel wären sonst per Vorabtabelle offline reversibel.
 * Nicht-skalare/fehlende Keys → `null`.
 */
final class AuditIdHasherTest extends TestCase
{
    /**
     * Jede Änderung der Ableitung bricht die Korrelation zu bestehenden
     * Einträgen. Der feste Wert zwingt dazu, das bewusst zu entscheiden.
     */
    public function testHashMatchesTheKnownValue(): void
    {
        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        $this->assertSame(
            '6db34bb61247605ce6f7d39d2dceae5b84970e26654335723329e55d5d499efa',
            AuditIdHasher::hash(42),
        );
    }

    public function testHashesScalarKeyWithTheAppKey(): void
    {
        $hash = AuditIdHasher::hash(42);

        // Integer- und String-Repräsentation desselben Schlüssels sind gleich.
        $this->assertSame($hash, AuditIdHasher::hash('42'));

        Config::set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $this->assertNotSame($hash, AuditIdHasher::hash(42));
    }

    /**
     * Wer den Audit-Trail liest, darf damit nicht im Namen der App signieren.
     */
    public function testTheHashOfAUrlIsNoSignatureForIt(): void
    {
        $url = url('/beliebig');

        $request = Request::create($url . '?signature=' . AuditIdHasher::hash($url));

        $this->assertFalse(URL::hasCorrectSignature($request));
    }

    public function testDifferentIdsYieldDifferentHashes(): void
    {
        $this->assertNotSame(AuditIdHasher::hash(42), AuditIdHasher::hash(43));
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
