<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Architektur-Test für die in AppServiceProvider via `Relation::enforceMorphMap()`
 * registrierte Morph-Map.
 *
 * Hintergrund: `enforceMorphMap` wirft eine `ClassMorphViolationException`,
 * sobald ein Model mit polymorpher Beziehung NICHT in der Map steht. Würde ein
 * neues Loggable-Model (Trait `LogsActivity`) eingeführt und der Map-Eintrag
 * vergessen, würde der Fehler erst zur Laufzeit beim ersten Schreibversuch
 * auftreten — möglicherweise erst in Production. Dieser Test fängt das
 * deterministisch zur Test-Zeit ab.
 *
 * Geprüft wird:
 *  - Jede Klasse in `app/Models/`, die `LogsActivity` (rekursiv) nutzt, ist
 *    in der Morph-Map registriert (`Relation::getAliasByMorphClass()` liefert
 *    einen Alias, nicht den FQCN selbst).
 */
final class MorphMapTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('loggableModelsProvider')]
    public function testEveryLoggableModelIsRegisteredInMorphMap(string $class): void
    {
        $morphMap = Relation::morphMap();
        $alias = array_search($class, $morphMap, true);

        $this->assertNotFalse(
            $alias,
            sprintf(
                "Das Model %s nutzt den `LogsActivity`-Trait, ist aber nicht in der "
                . "Morph-Map (AppServiceProvider::boot()) registriert. Bitte einen "
                . "stabilen Alias für %s ergänzen — sonst wirft `enforceMorphMap` "
                . "zur Laufzeit eine `ClassMorphViolationException`.",
                $class,
                $class,
            ),
        );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function loggableModelsProvider(): iterable
    {
        // Bewusst kein `app_path()` — Data Provider laufen, bevor die Application
        // gebootet ist; Container-Helfer wären hier nicht verfügbar.
        $modelsPath = dirname(__DIR__, 3) . '/app/Models';

        $finder = Finder::create()
            ->in($modelsPath)
            ->files()
            ->name('*.php');

        foreach ($finder as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $class = 'App\\Models\\' . $relative;

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (!self::usesLogsActivityTrait($reflection)) {
                continue;
            }

            yield $class => [$class];
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function usesLogsActivityTrait(ReflectionClass $reflection): bool
    {
        $traits = class_uses_recursive($reflection->getName());

        return in_array(LogsActivity::class, $traits, true);
    }
}
