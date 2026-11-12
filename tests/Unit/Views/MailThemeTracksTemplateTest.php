<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\TestCase;

/**
 * `resources/views/vendor/mail/html/themes/kumiator.css` ist eine Kopie: Verbessert Laravel sein
 * Mail-Theme, kommt davon nichts mehr an, und niemand würde es bemerken. `fixtures/laravel-mail-default.css`
 * hält die Fassung fest, auf der die Kopie beruht, damit der Diff beim Fehlschlag Laravels Änderung zeigt.
 * Übernehmen per Dreiwege-Merge, Konflikte auflösen, den Kopfkommentar der Kopie prüfen, dann die Basis
 * nachziehen:
 *
 *     git merge-file resources/views/vendor/mail/html/themes/kumiator.css \
 *         tests/Unit/Views/fixtures/laravel-mail-default.css \
 *         vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/themes/default.css
 *     cp vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/themes/default.css \
 *         tests/Unit/Views/fixtures/laravel-mail-default.css
 */
final class MailThemeTracksTemplateTest extends TestCase
{
    private const TEMPLATE = 'vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/themes/default.css';

    private const BASELINE = __DIR__ . '/fixtures/laravel-mail-default.css';

    public function testTemplateIsUnchanged(): void
    {
        $this->assertFileEquals(
            self::BASELINE,
            base_path(self::TEMPLATE),
            'Laravel hat sein Mail-Theme geändert — die Kopie hinkt hinterher.',
        );
    }
}
