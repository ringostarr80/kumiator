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

    /**
     * Liefert die für Profilfoto-Uploads erlaubten Dateiendungen — gemeinsame
     * Quelle für die server-seitige `mimes:`-Validierung und den client-
     * seitigen `accept=""`-Filter, damit beide Schichten nicht divergieren.
     *
     * @return list<string>
     */
    public function resolveProfilePhotoAcceptedExtensions(): array;
}
