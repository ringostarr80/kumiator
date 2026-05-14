<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Das effektive Upload-Limit für einen Datei-Upload.
 *
 * `$bytes` ist das tatsächlich greifende Maximum — das Minimum aus
 * App-Konfiguration, PHP-Limits (`upload_max_filesize` / `post_max_size`)
 * und dem Livewire-Temp-Upload-Limit. `$constrainedByServer` ist `true`,
 * wenn nicht die App-Konfiguration das bindende Limit ist, sondern eine
 * Server-/Framework-Einstellung — dann lässt sich das Limit nicht über
 * die App-Config allein anheben.
 */
final readonly class UploadLimitData
{
    public function __construct(public int $bytes, public bool $constrainedByServer)
    {
    }

    /**
     * Limit in Kilobytes — das Format, das die Laravel-`max`-Validierungsregel
     * für Datei-Uploads erwartet.
     */
    public function kilobytes(): int
    {
        return intdiv($this->bytes, 1_024);
    }
}
