<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditIpTruncator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sichert die DSGVO-Datenminimierung der IP-Kürzung: IPv4 auf `/24`, IPv6 auf
 * `/64`, und dass ungültige/leere Eingaben `null` liefern (dann wird gar keine
 * IP-Property geschrieben). Die volle Host-Adresse darf nie zurückkommen.
 */
final class AuditIpTruncatorTest extends TestCase
{
    #[DataProvider('ipProvider')]
    public function testTruncatesToNetwork(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, AuditIpTruncator::truncate($input));
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function ipProvider(): array
    {
        return [
            'IPv4 wird auf /24 gekürzt' => ['203.0.113.7', '203.0.113.0/24'],
            'IPv4 Netz-Adresse bleibt /24' => ['203.0.113.0', '203.0.113.0/24'],
            'IPv6 wird auf /64 gekürzt (komprimiert)' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1:2::/64'],
            'IPv6 Loopback auf /64' => ['::1', '::/64'],
            'ungültige Eingabe → null' => ['not-an-ip', null],
            'leerer String → null' => ['', null],
            'null → null' => [null, null],
        ];
    }
}
