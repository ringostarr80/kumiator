<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Architektur-Test für die in AppServiceProvider via `Relation::enforceMorphMap()`
 * registrierte Morph-Map.
 *
 * Hintergrund: `enforceMorphMap` wirft eine `ClassMorphViolationException`,
 * sobald ein Model mit polymorpher Beziehung NICHT in der Map steht. Da das
 * Enforcement **global** wirkt (nicht nur für Activity-Log-Subjects), würde
 * jedes neu eingeführte polymorphisch-adressierte Model einen Map-Eintrag
 * benötigen — ohne diesen Test fiele der Fehler erst zur Laufzeit beim
 * ersten Schreibversuch auf, möglicherweise erst in Production.
 *
 * Geprüft wird, dass jede Klasse in `app/Models/`, die ein plausibles Signal
 * für eine polymorphe Beziehung trägt, in der Map registriert ist:
 *  - `LogsActivity`-Trait (Subject im `activity_log`),
 *  - eine inverse polymorphe Relation (`MorphMany`, `MorphOne`, `MorphToMany`) —
 *    denn dann ist die Klasse selbst der Typ-Wert auf der Gegenseite
 *    (`*_type` Spalte). `MorphToMany` deckt zugleich `morphedByMany()` ab,
 *    da Eloquent dort denselben Relations-Rückgabetyp verwendet.
 *
 * Bewusst NICHT erfasst: Modelle, die ausschließlich `MorphTo` definieren —
 * sie sind die Halter der `*_type`-Spalten, nicht die Werte. Ihre Alias-
 * Pflicht trifft die Ziel-Modelle der `MorphTo`, die wir hier statisch nicht
 * enumerieren können (false negative bewusst akzeptiert).
 */
final class MorphMapTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('polymorphicallyTargetedModelsProvider')]
    public function testEveryPolymorphicallyTargetedModelIsRegisteredInMorphMap(string $class): void
    {
        $morphMap = Relation::morphMap();
        $alias = array_search($class, $morphMap, true);

        $this->assertNotFalse(
            $alias,
            sprintf(
                "Das Model %s ist polymorphisch adressierbar (LogsActivity-Trait "
                . "oder inverse polymorphe Relation), aber nicht in der Morph-Map "
                . "(AppServiceProvider::boot()) registriert. Bitte einen stabilen "
                . "Alias für %s ergänzen — sonst wirft `enforceMorphMap` zur "
                . "Laufzeit eine `ClassMorphViolationException`.",
                $class,
                $class,
            ),
        );
    }

    /**
     * Schützt die UI-Lesbarkeit der Morph-Aliase: Das Blade-Template
     * `livewire/admin/activity-log-table.blade.php` rendert für gelöschte
     * Subjects/Causer das übersetzte Fallback `morph_<alias>`. Fehlt der
     * Schlüssel in einer Locale, würde Laravel den Key selbst zurückgeben
     * und der rohe Alias landet sichtbar im UI.
     *
     * Die Morph-Map wird in `AppServiceProvider::boot()` registriert; Data
     * Provider laufen vor dem Boot und sähen eine leere Map. Deshalb hier
     * keine Provider-Variante, sondern eine einzelne Test-Methode mit
     * Innenschleife.
     */
    public function testEveryMorphAliasHasTranslationInBothLocales(): void
    {
        $aliases = array_keys(Relation::morphMap());

        $this->assertNotSame([], $aliases, 'Morph-Map ist leer — `AppServiceProvider::boot()` nicht gelaufen?');

        foreach ($aliases as $alias) {
            $key = 'app.morph_' . $alias;

            foreach (['de', 'en'] as $locale) {
                App::setLocale($locale);

                $this->assertNotSame(
                    $key,
                    __($key),
                    sprintf(
                        "Für den Morph-Alias '%s' fehlt der Übersetzungs-Schlüssel '%s' "
                        . "in der Locale '%s'. Bitte in lang/%s/app.php ergänzen — sonst "
                        . "rendert das Activity-Log-UI den rohen Alias.",
                        $alias,
                        $key,
                        $locale,
                        $locale,
                    ),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function polymorphicallyTargetedModelsProvider(): iterable
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

            if (!self::usesLogsActivityTrait($reflection) && !self::hasInversePolymorphicRelation($reflection)) {
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

    /**
     * Prüft, ob das Model mindestens eine inverse polymorphe Relation definiert
     * (`MorphMany`, `MorphOne`, `MorphToMany`). Diese Relations-Typen sitzen auf
     * dem **Parent-Ende** der Polymorphie — d. h. der Model-FQCN dieser Klasse
     * landet auf der Gegenseite in `*_type`-Spalten und benötigt damit zwingend
     * einen Morph-Map-Alias. `MorphToMany` deckt sowohl `morphToMany()` als auch
     * `morphedByMany()` ab (gleicher Rückgabetyp).
     *
     * `MorphTo` wird absichtlich nicht erfasst (Halter-Seite, kein Typ-Wert).
     *
     * @param ReflectionClass<object> $reflection
     */
    private static function hasInversePolymorphicRelation(ReflectionClass $reflection): bool
    {
        $inverseRelations = [
            MorphMany::class,
            MorphOne::class,
            MorphToMany::class,
        ];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isAbstract()) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (!$returnType instanceof ReflectionNamedType) {
                continue;
            }

            $returnTypeName = $returnType->getName();

            foreach ($inverseRelations as $relationClass) {
                if ($returnTypeName === $relationClass || is_subclass_of($returnTypeName, $relationClass)) {
                    return true;
                }
            }
        }

        return false;
    }
}
