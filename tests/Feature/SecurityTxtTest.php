<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Exceptions\InvalidFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

final class SecurityTxtTest extends TestCase
{
    private const string URL = '/.well-known/security.txt';

    public function testServesTheFileOfThisInstance(): void
    {
        $response = $this->get(self::URL);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame(
            <<<'TXT'
            # Operator of this instance: server, hosting, configuration
            Contact: mailto:security@verein.example.org
            # Vulnerabilities in the Kumiator software itself
            Contact: https://github.com/ringostarr80/kumiator/security/advisories/new
            Expires: 2027-09-01T00:00:00Z
            Preferred-Languages: de, en
            Canonical: https://verein.example.org/.well-known/security.txt
            Policy: https://github.com/ringostarr80/kumiator/blob/main/SECURITY.md

            TXT,
            $response->getContent(),
        );
    }

    public function testCanonicalFollowsTheAppUrlNotTheRequestedHost(): void
    {
        $this->get('https://alias.example.net' . self::URL)
            ->assertOk()
            ->assertSee('Canonical: https://verein.example.org/.well-known/security.txt', false);
    }

    public function testCanonicalIgnoresATrailingSlashOnTheAppUrl(): void
    {
        config(['app.url' => 'https://verein.example.org/']);

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Canonical: https://verein.example.org/.well-known/security.txt', false);
    }

    /**
     * Crawler rufen die Datei massenhaft ab; jeder Abruf legte sonst eine Session an.
     */
    public function testStartsNoSession(): void
    {
        $this->get(self::URL)
            ->assertOk()
            ->assertHeaderMissing('Set-Cookie');
    }

    #[DataProvider('missingSettings')]
    public function testIsNotServedWithoutRequiredSetting(string $key, ?string $value): void
    {
        config([$key => $value]);

        $this->get(self::URL)
            ->assertNotFound();
    }

    #[DataProvider('validContacts')]
    public function testServesEveryDocumentedContactScheme(string $contact): void
    {
        config(['security_txt.contact' => $contact]);

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Contact: ' . $contact, false);
    }

    #[DataProvider('invalidContacts')]
    public function testAnInvalidContactFailsInsteadOfServingAnInvalidFile(string $contact): void
    {
        config(['security_txt.contact' => $contact]);

        $this->withoutExceptionHandling();
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('SECURITY_TXT_CONTACT must be a mailto:, tel: or https:// URI: ' . $contact);

        $this->get(self::URL);
    }

    /**
     * Das abgelaufene Datum sagt Forschenden selbst, dass die Angaben veraltet
     * sind; eine 404 verschwiege, dass es überhaupt einen Kontakt gab.
     */
    public function testAnExpiredFileIsStillServed(): void
    {
        config(['security_txt.expires' => '2020-01-01T00:00:00Z']);

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Expires: 2020-01-01T00:00:00Z', false);
    }

    #[DataProvider('invalidExpires')]
    public function testAnInvalidExpiresFailsInsteadOfServingAWrongFile(string $expires): void
    {
        config(['security_txt.expires' => $expires]);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessageIs('SECURITY_TXT_EXPIRES is not a valid RFC 3339 date-time: ' . $expires);

        $this->get(self::URL);
    }

    public function testAnAppUrlWithoutHttpsFailsInsteadOfServingAnInvalidFile(): void
    {
        config(['app.url' => 'http://verein.example.org']);

        $this->withoutExceptionHandling();
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs(
            'APP_URL must start with https:// for the security.txt Canonical field: http://verein.example.org',
        );

        $this->get(self::URL);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function missingSettings(): array
    {
        return [
            'Kontakt fehlt' => ['security_txt.contact', null],
            'Kontakt leer' => ['security_txt.contact', '  '],
            'Ablaufdatum fehlt' => ['security_txt.expires', null],
            'Ablaufdatum leer' => ['security_txt.expires', '  '],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validContacts(): array
    {
        return [
            'Telefon' => ['tel:+49-30-1234567'],
            'Webseite' => ['https://verein.example.org/sicherheit'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidContacts(): array
    {
        return [
            'Mail ohne mailto:' => ['security@verein.example.org'],
            'http' => ['http://verein.example.org/sicherheit'],
            'Zeilenumbruch' => ["mailto:security@verein.example.org\nContact: https://example.net"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidExpires(): array
    {
        return [
            'nur Datum' => ['2027-09-01'],
            'relativ' => ['+1 year'],
            '30. Februar' => ['2027-02-30T00:00:00Z'],
            'zweistelliges Jahr' => ['27-09-01T00:00:00Z'],
            'Zonenname' => ['2027-09-01T00:00:00Europe/Berlin'],
            'Offset ohne Doppelpunkt' => ['2027-09-01T00:00:00+0200'],
            'Sekundenbruchteile' => ['2027-09-01T00:00:00.000Z'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://verein.example.org',
            'security_txt.contact' => 'mailto:security@verein.example.org',
            'security_txt.expires' => '2027-09-01T02:00:00+02:00',
        ]);
    }
}
