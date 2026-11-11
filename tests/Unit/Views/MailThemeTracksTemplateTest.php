<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\TestCase;

/**
 * `resources/views/vendor/mail/html/themes/kumiator.css` ist eine Kopie: Verbessert Laravel sein
 * Mail-Theme, kommt davon nichts mehr an, und niemand würde es bemerken. Schlägt der Hash an, gehören
 * beide Fassungen verglichen, die Änderung übernommen und der Wert hier nachgezogen.
 */
final class MailThemeTracksTemplateTest extends TestCase
{
    private const TEMPLATE = 'vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/themes/default.css';

    private const TEMPLATE_HASH = 'e4ad64d3a202a3cc5df8b56ef2b5de71fd75c7e238ecb132e54fdcfcde4a3a4a';

    public function testTemplateIsUnchanged(): void
    {
        $this->assertSame(
            self::TEMPLATE_HASH,
            hash_file('sha256', base_path(self::TEMPLATE)),
            'Laravel hat sein Mail-Theme geändert — die Kopie hinkt hinterher.',
        );
    }
}
