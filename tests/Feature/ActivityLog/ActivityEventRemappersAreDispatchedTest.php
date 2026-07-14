<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\Concerns\RemapsActivityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guard: Die `Activity::saving`-Closure in `AppServiceProvider::boot()` stößt das
 * Event-Remapping über je einen expliziten `X::applyEventLabelToActivity()`-Aufruf
 * pro Trait-Nutzer an. Nichts erzwingt zur Laufzeit, dass diese Aufrufliste mit der
 * Menge der `RemapsActivityEvent`-Models synchron bleibt — ein neu getaggtes Model,
 * das dort vergessen wird, schriebe still rohe `created`/`updated`-Events statt der
 * fachlichen Codes, und seine eigenen Verhaltenstests existieren noch nicht.
 *
 * Geprüft wird deshalb über einen echten Insert durch die reale Closure: was das
 * Model selbst mappen würde, muss auch in der Spalte landen. Fehlt der Aufruf,
 * bleibt der rohe Code stehen und der Test wird rot.
 */
final class ActivityEventRemappersAreDispatchedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Eloquents Lifecycle-Event-Namen: die rohen Codes, die Spatie in `event`
     * schreibt und die das Remapping anheben soll.
     */
    private const array RAW_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    public function testEveryRemapsActivityEventModelIsDispatchedInSavingListener(): void
    {
        $models = $this->modelsUsingTrait();

        // Sonst liefe der Guard grün leer, sobald die Model-Suche ins Nichts greift.
        $this->assertNotEmpty($models, 'Keine RemapsActivityEvent-Models gefunden.');

        foreach ($models as $model) {
            foreach (self::RAW_EVENTS as $rawEvent) {
                $this->assertRawEventIsRemapped($model, $rawEvent);
            }
        }
    }

    /**
     * @param class-string $model
     */
    private function assertRawEventIsRemapped(string $model, string $rawEvent): void
    {
        $activity = new Activity();
        $activity->log_name = $this->remapChannelOf($model);
        $activity->event = $rawEvent;

        $expected = $this->expectedEventOf($model, $rawEvent, $activity);

        $activity->saveOrFail();

        $this->assertSame(
            $expected,
            $activity->event,
            $model . ' nutzt RemapsActivityEvent, aber die Activity::saving-Closure in '
            . 'AppServiceProvider::boot() ruft seinen Mapper nicht auf: der rohe Eloquent-Code '
            . '"' . $rawEvent . '" bleibt unverändert im Audit-Log stehen.',
        );
    }

    /**
     * @param class-string $model
     */
    private function remapChannelOf(string $model): string
    {
        $channel = (new ReflectionMethod($model, 'activityRemapChannel'))->invoke(null);

        return is_string($channel)
            ? $channel
            : throw new RuntimeException($model . '::activityRemapChannel() lieferte keinen String.');
    }

    /**
     * Was das Model selbst für den rohen Code vorsieht — ohne fachliches Pendant
     * bleibt es beim Rohcode.
     *
     * @param class-string $model
     */
    private function expectedEventOf(string $model, string $rawEvent, Activity $activity): string
    {
        $mapped = (new ReflectionMethod($model, 'mapActivityEvent'))->invoke(null, $rawEvent, $activity);

        return $mapped instanceof ActivityEvent
            ? $mapped->value
            : $rawEvent;
    }

    /**
     * @return list<class-string>
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
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
