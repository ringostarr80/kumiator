<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\DataTransferObjects\UploadLimitData;
use App\Services\Upload\Contracts\UploadLimitResolverContract;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ermittelt das effektive Upload-Limit für Profilfotos.
 *
 * Drei Schichten begrenzen einen Upload unabhängig voneinander; der
 * kleinste Wert gewinnt:
 *  - App-Konfiguration (`jetstream.profile_photo_max_kilobytes`),
 *  - die PHP-Limits `upload_max_filesize` / `post_max_size` — `UploadedFile::
 *    getMaxFilesize()` liefert davon bereits das Minimum in Bytes,
 *  - das Livewire-Temp-Upload-Limit (`max:`-Regel in
 *    `livewire.temporary_file_upload.rules`, Default 12 MB).
 *
 * Ist nicht die App-Konfiguration das bindende Limit, wird das im
 * `UploadLimit`-DTO über `constrainedByServer` signalisiert — dann lässt
 * sich die Grenze nicht allein über die App-Config anheben.
 */
final class UploadLimitResolver implements UploadLimitResolverContract
{
    private const int BYTES_PER_KILOBYTE = 1_024;

    /**
     * Livewire-Default, wenn `livewire.temporary_file_upload.rules` nicht
     * publiziert ist (vgl. Livewires `FileUploadConfiguration::rules()`).
     */
    private const int LIVEWIRE_DEFAULT_MAX_KILOBYTES = 12_288;

    public function resolveProfilePhotoLimit(): UploadLimitData
    {
        $appBytes = Config::integer('jetstream.profile_photo_max_kilobytes') * self::BYTES_PER_KILOBYTE;

        $phpIniBytes = UploadedFile::getMaxFilesize();

        $livewireBytes = $this->livewireTemporaryUploadBytes();

        $effectiveBytes = (int) min($appBytes, $phpIniBytes, $livewireBytes);

        return new UploadLimitData(bytes: $effectiveBytes, constrainedByServer: $effectiveBytes < $appBytes);
    }

    /**
     * Liest die `max:`-Regel aus der Livewire-Temp-Upload-Konfiguration.
     * Ohne explizite `max:`-Regel gibt es kein Livewire-seitiges Größenlimit
     * (PHP_INT_MAX) — die Schicht fällt dann aus der Minimum-Bildung heraus.
     */
    private function livewireTemporaryUploadBytes(): int
    {
        $rules = Config::get('livewire.temporary_file_upload.rules');

        if ($rules === null) {
            return self::LIVEWIRE_DEFAULT_MAX_KILOBYTES * self::BYTES_PER_KILOBYTE;
        }

        $ruleList = is_string($rules)
            ? explode('|', $rules)
            : $rules;

        if (!is_array($ruleList)) {
            return PHP_INT_MAX;
        }

        foreach ($ruleList as $rule) {
            if (!is_string($rule) || !str_starts_with($rule, 'max:')) {
                continue;
            }

            $kilobytes = (int) substr($rule, 4);

            if ($kilobytes > 0) {
                return $kilobytes * self::BYTES_PER_KILOBYTE;
            }
        }

        return PHP_INT_MAX;
    }
}
