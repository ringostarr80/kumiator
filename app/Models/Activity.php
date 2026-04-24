<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Lokaler Wrapper für das Activity-Log-Model von spatie/laravel-activitylog.
 *
 * Existiert ausschließlich, damit höhere Schichten (Livewire, Policies) über
 * App\Models auf das Activity-Log zugreifen können, ohne die Architektur-Regeln
 * (LivewireComponentsAreIndependentTest, PoliciesDependOnlyOnModelsTest) zu
 * verletzen. Schreibzugriffe erfolgen weiterhin über die Spatie-Klasse
 * (siehe config/activitylog.php → activity_model).
 */
final class Activity extends SpatieActivity
{
}
