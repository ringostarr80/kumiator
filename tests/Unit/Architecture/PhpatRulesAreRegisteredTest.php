<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Tests\TestCase;

/**
 * phpat entdeckt seine Regeln nicht über das Verzeichnis, sondern ausschließlich
 * über die `phpat.test`-getaggten Services in `phpat.neon`. Eine Regel-Klasse in
 * `tests/Architecture/`, die dort nicht eingetragen ist, wird darum stillschweigend
 * nie ausgeführt — die Architektur-Regel existiert auf dem Papier, hat aber keine
 * Zähne. Dieser Guard hält Verzeichnis und Registrierung deckungsgleich: jede
 * Regel-Datei muss registriert sein, und kein Eintrag darf auf eine fehlende Datei
 * zeigen.
 */
final class PhpatRulesAreRegisteredTest extends TestCase
{
    public function testArchitectureRuleFilesAndRegistrationsMatch(): void
    {
        $this->assertSame(
            $this->definedRuleClasses(),
            $this->registeredRuleClasses(),
            'tests/Architecture/ und die phpat.test-Services in phpat.neon sind nicht deckungsgleich: '
            . 'nur links = nie geprüfte Regel-Datei(en), nur rechts = verwaiste Registrierung(en).',
        );
    }

    /**
     * @return list<string>
     */
    private function definedRuleClasses(): array
    {
        $files = glob(base_path('tests/Architecture/*Test.php')) ?: [];

        $classes = array_map(
            static fn (string $path): string => 'Tests\\Architecture\\' . basename($path, '.php'),
            $files,
        );

        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function registeredRuleClasses(): array
    {
        $lines = file(base_path('phpat.neon'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $classes = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (!str_starts_with($line, 'class:')) {
                continue;
            }

            $class = trim(substr($line, strlen('class:')));

            if (str_starts_with($class, 'Tests\\Architecture\\')) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
