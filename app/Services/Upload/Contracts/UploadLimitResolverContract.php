<?php

declare(strict_types=1);

namespace App\Services\Upload\Contracts;

use App\DataTransferObjects\UploadLimitData;

interface UploadLimitResolverContract
{
    /**
     * Ermittelt das effektive Upload-Limit für Profilfotos — das Minimum aus
     * App-Konfiguration, den PHP-Limits und dem Livewire-Temp-Upload-Limit.
     */
    public function resolveProfilePhotoLimit(): UploadLimitData;
}
