<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use Tests\TestCase;

/**
 * Guard gegen ein Wiedereinführen des Buffer-Schalters: Spaties Buffer schreibt
 * die gesammelten Einträge per Query-Builder-`insert()` in einem Rutsch, also an
 * allen Model-Events vorbei. Der `Activity::saving`-Hook in `AppServiceProvider`
 * feuerte dann nie — Event-Remapping, Self-Registration-Marker und die
 * CLI-Causer-Anonymisierung fielen still aus.
 */
final class ActivityBufferIsNotOfferedTest extends TestCase
{
    /**
     * Die Anwendung wird mit gesetzter ENV-Variable neu gebootet, statt den seit
     * dem Boot feststehenden `config()`-Wert zu lesen: Nur so durchläuft die Frage
     * die ganze Kette — publizierte Datei, Spaties `mergeConfigFrom()` und dessen
     * flaches `array_merge`, das den Vendor-Default nachliefert, wo wir schweigen.
     */
    public function testActivityBufferCannotBeEnabledByEnvironmentVariable(): void
    {
        putenv('ACTIVITYLOG_BUFFER_ENABLED=true');

        try {
            $this->refreshApplication();

            $this->assertFalse(config('activitylog.buffer.enabled'));
        } finally {
            putenv('ACTIVITYLOG_BUFFER_ENABLED');
        }
    }
}
