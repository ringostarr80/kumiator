<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\Concerns\RemapsActivityEvent;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guard: Die `Activity::saving`-Closure in `AppServiceProvider::boot()` stößt das
 * Event-Remapping über je einen expliziten `X::applyEventLabelToActivity()`-Aufruf
 * pro Trait-Nutzer an. Nichts erzwingt zur Laufzeit, dass diese Aufrufliste mit der
 * Menge der `RemapsActivityEvent`-Models synchron bleibt — ein neu getaggtes Model,
 * das hier vergessen wird, schriebe still rohe `created`/`updated`-Events statt der
 * fachlichen Codes. Dieser Test hält beide Seiten deckungsgleich: jedes Model mit dem
 * Trait muss verdrahtet sein, und kein Aufruf darf auf ein Model ohne Trait zeigen.
 * Analog zu `PhpatRulesAreRegisteredTest`, das Regel-Datei und phpat-Registrierung
 * deckungsgleich hält.
 */
final class ActivityEventRemappersAreDispatchedTest extends TestCase
{
    public function testEveryRemapsActivityEventModelIsDispatchedInSavingListener(): void
    {
        $this->assertSame(
            $this->modelsUsingTrait(),
            $this->modelsDispatchedInProvider(),
            'app/Models mit RemapsActivityEvent und die X::applyEventLabelToActivity()-Aufrufe in '
            . 'AppServiceProvider::boot() sind nicht deckungsgleich: nur links = getaggtes Model, das '
            . 'nie remappt wird (schreibt rohe created/updated-Events), nur rechts = Aufruf auf ein '
            . 'Model ohne Trait.',
        );
    }

    /**
     * @return list<string>
     */
    private function modelsUsingTrait(): array
    {
        $finder = Finder::create()
            ->in(dirname(__DIR__, 3) . '/app/Models')
            ->files()
            ->name('*.php');

        $classes = [];

        foreach ($finder as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Models\\' . $relative;

            if (!class_exists($class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (in_array(RemapsActivityEvent::class, class_uses_recursive($class), true)) {
                $classes[] = class_basename($class);
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function modelsDispatchedInProvider(): array
    {
        $source = file_get_contents(base_path('app/Providers/AppServiceProvider.php')) ?: '';

        preg_match_all('/(\w+)::applyEventLabelToActivity\(/', $source, $matches);

        $classes = array_values(array_unique($matches[1]));

        sort($classes);

        return $classes;
    }
}
