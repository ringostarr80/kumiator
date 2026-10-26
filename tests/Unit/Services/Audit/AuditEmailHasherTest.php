<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditEmailHasher;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sichert, dass E-Mail-Adressen als HMAC mit einem Schlüssel aus dem APP_KEY
 * gehasht werden — als ungesalzener SHA-256 wären sie per Wörterbuch offline
 * reversibel. Groß-/Kleinschreibung und Whitespace am Rand dürfen die
 * Korrelation gleicher Versuche nicht brechen.
 */
final class AuditEmailHasherTest extends TestCase
{
    private const string VICTIM_EMAIL = 'opfer@example.com';

    /**
     * Jede Änderung der Ableitung bricht die Korrelation zu bestehenden
     * Einträgen. Der feste Wert zwingt dazu, das bewusst zu entscheiden.
     */
    public function testHashMatchesTheKnownValue(): void
    {
        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        $this->assertSame(
            '8a5d581109f20574d6bbdda7fb549416c481476df6ce89e8ee33dbb757ea544c',
            AuditEmailHasher::hash(self::VICTIM_EMAIL),
        );
    }

    public function testHashesWithTheAppKey(): void
    {
        $hash = AuditEmailHasher::hash(self::VICTIM_EMAIL);

        Config::set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $this->assertNotSame($hash, AuditEmailHasher::hash(self::VICTIM_EMAIL));
    }

    public function testCaseAndSurroundingWhitespaceYieldTheSameHash(): void
    {
        $this->assertSame(
            AuditEmailHasher::hash(self::VICTIM_EMAIL),
            AuditEmailHasher::hash("  Opfer@Example.COM\n"),
        );
    }

    public function testDifferentAddressesYieldDifferentHashes(): void
    {
        $this->assertNotSame(
            AuditEmailHasher::hash(self::VICTIM_EMAIL),
            AuditEmailHasher::hash('taeter@example.com'),
        );
    }

    #[DataProvider('blankProvider')]
    public function testBlankInputYieldsNull(?string $email): void
    {
        $this->assertNull(AuditEmailHasher::hash($email));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function blankProvider(): array
    {
        return [
            'null' => [null],
            'leer' => [''],
            'nur Whitespace' => [" \t\n"],
        ];
    }
}
