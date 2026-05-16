<?php

declare(strict_types=1);

namespace App\Services\Upload\Contracts;

use Illuminate\Http\UploadedFile;

interface ProfilePhotoOptimizerContract
{
    /**
     * Verkleinert ein hochgeladenes Profilfoto auf eine quadratische
     * Thumbnail-Größe und gibt es als AVIF-`UploadedFile` zurück.
     *
     * Das Original wird nie gespeichert — Profilfotos werden nirgends größer
     * als wenige Dutzend Pixel dargestellt. EXIF-Orientierung wird dabei
     * korrigiert, damit Hochformat-Fotos nicht gedreht erscheinen.
     */
    public function optimize(UploadedFile $photo): UploadedFile;
}
