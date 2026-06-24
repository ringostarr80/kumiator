<?php

declare(strict_types=1);

namespace App\Services\Concerns;

/**
 * Gemeinsame Mechanik für request-scoped Marker, die einem nachgelagerten
 * Listener signalisieren, dass der gerade laufende Vorgang aus einem
 * bestimmten Pfad stammt (und ein automatischer Audit-Eintrag deshalb
 * unterdrückt oder umgelabelt werden soll).
 *
 * Der Zustand liegt bewusst als Instanz-Feld vor, nicht statisch: die
 * nutzenden Klassen werden per `scoped()` gebunden, sodass Setzer und Leser
 * dieselbe Request-Instanz teilen. Anders als ein statisches Feld überlebt der
 * Marker damit unter Long-Running-Workern (Octane) keinen Request-Wechsel —
 * ein hängender Marker würde dort prozessweit echte Audit-Einträge
 * unterdrücken.
 */
trait MarksRequestScope
{
    private bool $active = false;

    public function markActive(): void
    {
        $this->active = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function clear(): void
    {
        $this->active = false;
    }
}
