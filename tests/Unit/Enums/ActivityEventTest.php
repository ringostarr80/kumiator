<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ActivityEvent;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zentraler Ersatz für die zuvor pro Schreib-Site verstreuten
 * `testTranslationKeyExistsInBothLocales`-Tests: jeder {@see ActivityEvent}
 * MUSS eine `app.activity_<value>`-Übersetzung in beiden Locales haben, sonst
 * zeigt die Activity-Log-UI den rohen Schlüssel statt einer Beschreibung.
 */
final class ActivityEventTest extends TestCase
{
    #[DataProvider('eventProvider')]
    public function testDescriptionResolvesToTranslationInBothLocales(ActivityEvent $event): void
    {
        $key = 'app.activity_' . $event->value;

        foreach (['de', 'en'] as $locale) {
            $translation = Lang::get($key, [], $locale);

            $this->assertIsString($translation, sprintf("'%s' liefert keinen String in '%s'.", $key, $locale));
            $this->assertNotSame(
                $key,
                $translation,
                sprintf("Übersetzungs-Schlüssel '%s' fehlt in Locale '%s'.", $key, $locale),
            );
        }
    }

    public function testDescriptionUsesCurrentLocale(): void
    {
        $this->app->setLocale('de');
        $this->assertSame(Lang::get('app.activity_logout', [], 'de'), ActivityEvent::LOGOUT->description());

        $this->app->setLocale('en');
        $this->assertSame(Lang::get('app.activity_logout', [], 'en'), ActivityEvent::LOGOUT->description());
    }

    /**
     * @return iterable<string, array{ActivityEvent}>
     */
    public static function eventProvider(): iterable
    {
        foreach (ActivityEvent::cases() as $case) {
            yield $case->value => [$case];
        }
    }
}
