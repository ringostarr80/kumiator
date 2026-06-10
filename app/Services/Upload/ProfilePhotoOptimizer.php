<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Services\Upload\Contracts\ProfilePhotoOptimizerContract;
use App\Services\Upload\Exceptions\ProfilePhotoOptimizationException;
use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Verkleinert Profilfotos beim Upload zu einem quadratischen AVIF-Thumbnail.
 *
 * Jetstreams `HasProfilePhoto::updateProfilePhoto()` speichert die Datei sonst
 * 1:1 wie hochgeladen — ein mehrere MB großes Foto wird also in voller
 * Auflösung ausgeliefert, obwohl es nirgends größer als wenige Dutzend Pixel
 * dargestellt wird. Dieser Service rechnet das Bild auf {@see self::DIMENSION}²
 * herunter (zentrierter Cover-Crop, passend zur `rounded-full`-Anzeige) und
 * gibt es als AVIF zurück.
 *
 * EXIF-Orientierung wird korrigiert: Smartphone-Hochformatfotos tragen die
 * Drehung nur als Metadatum, die rohen Pixel liegen gedreht vor. Ohne
 * Korrektur erschiene das Thumbnail gekippt. EXIF gibt es praktisch nur in
 * JPEGs — PNGs überspringen diesen Schritt.
 *
 * Bewusst mit nativem GD statt einer Bild-Bibliothek umgesetzt: die
 * Operationen hier sind elementar genug, dass eine Abstraktion keinen
 * Mehrwert brächte. Einzige Klassen-Abhängigkeit ist `\GdImage`, das
 * PHP-Core-Wertobjekt der GD-Extension (in der Service-Architekturregel
 * entsprechend freigegeben).
 */
final class ProfilePhotoOptimizer implements ProfilePhotoOptimizerContract
{
    /** Kantenlänge des gespeicherten Thumbnails — großzügig für Retina bei ~80px Anzeige. */
    private const int DIMENSION = 256;

    /** AVIF-Qualität (0–100); für ein Thumbnail visuell unkritisch. */
    private const int AVIF_QUALITY = 60;

    /**
     * Dekompressions-Bomben-Schutz: Der Decode kostet `Breite × Höhe × 4`
     * Bytes — ein kleines, hochkomprimiertes Bild mit Riesen-Dimensionen
     * liefe sonst gegen das `memory_limit`. Die Grenze hält den Peak (bei
     * EXIF-Rotation temporär das Doppelte) unter den konfigurierten 256M
     * und lässt Standard-Smartphone-Fotos (iPhone: 24 MP) passieren.
     */
    private const int MAX_PIXELS = 25_000_000;

    public function optimize(UploadedFile $photo): UploadedFile
    {
        $contents = $photo->get();

        if (!is_string($contents)) {
            throw new ProfilePhotoOptimizationException(__('app.profile_photo_optimizer_read_failed'));
        }

        try {
            // `getimagesizefromstring()` liest nur die Header-Metadaten, ohne
            // Pixeldaten zu allokieren. Unlesbare Daten meldet es je nach
            // Inhalt per `false` oder per Warning, die Laravels Error-Handler
            // in eine Exception übersetzt — beides fällt zum Decode durch,
            // der dieselben Daten ohnehin ablehnt.
            $info = getimagesizefromstring($contents);
        } catch (\Throwable) {
            $info = false;
        }

        if (is_array($info) && $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ProfilePhotoOptimizationException(__('app.profile_photo_optimizer_too_many_pixels', [
                'max_megapixels' => intdiv(self::MAX_PIXELS, 1_000_000),
            ]));
        }

        try {
            // `imagecreatefromstring()` meldet unlesbare Daten per Warning, die
            // Laravels Error-Handler in eine Exception übersetzt — `false` käme
            // hier also nie an, ohne den Catch.
            $source = imagecreatefromstring($contents);
        } catch (\Throwable) {
            $source = false;
        }

        if ($source === false) {
            throw new ProfilePhotoOptimizationException(__('app.profile_photo_optimizer_not_an_image'));
        }

        $source = $this->correctOrientation($source, $this->readExifOrientation($photo, $contents));
        $thumbnail = $this->cropToSquareThumbnail($source);

        // Kein `imagedestroy()`: seit PHP 8.0 sind GD-Bilder Objekte, die der
        // GC freigibt — der Aufruf ist wirkungslos und seit 8.5 deprecated.
        $targetPath = $this->encodeAvif($thumbnail);

        return new UploadedFile($targetPath, 'photo.avif', 'image/avif', test: true);
    }

    /**
     * Liest die EXIF-Orientierung (1–8) des Originals. Nur JPEGs tragen EXIF;
     * fehlt das Tag oder schlägt das Lesen fehl, gilt 1 (keine Drehung).
     */
    private function readExifOrientation(UploadedFile $photo, string $contents): int
    {
        if ($photo->getMimeType() !== 'image/jpeg') {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

            // `exif_read_data()` meldet fehlende/defekte EXIF-Blöcke per Warning,
            // die Laravels Error-Handler in eine Exception übersetzt — daher der
            // Catch. In beiden Fällen behandeln wir das Bild als ungedreht.
            $exif = exif_read_data($stream);
        } catch (\Throwable) {
            return 1;
        } finally {
            fclose($stream);
        }

        $orientation = is_array($exif)
            ? ($exif['Orientation'] ?? null)
            : null;

        // `exif_read_data()` liefert Orientation als Integer; alles andere
        // (fehlend, defekt) behandeln wir als „keine Drehung".
        return is_int($orientation)
            ? $orientation
            : 1;
    }

    /**
     * Dreht/spiegelt das Bild gemäß EXIF-Orientierung in die Ansichtslage.
     * `imagerotate()` liefert eine neue Ressource, `imageflip()` arbeitet
     * in-place; die jeweils verworfene Ressource gibt der GC frei.
     */
    private function correctOrientation(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->rotate($this->flip($image, IMG_FLIP_VERTICAL), -90),
            6 => $this->rotate($image, -90),
            7 => $this->rotate($this->flip($image, IMG_FLIP_VERTICAL), 90),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    private function rotate(GdImage $image, int $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            throw new ProfilePhotoOptimizationException(__('app.profile_photo_optimizer_rotation_failed'));
        }

        return $rotated;
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function cropToSquareThumbnail(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $squareSize = min($width, $height);
        $sourceX = intdiv($width - $squareSize, 2);
        $sourceY = intdiv($height - $squareSize, 2);

        $thumbnail = imagecreatetruecolor(self::DIMENSION, self::DIMENSION);

        if ($thumbnail === false) {
            throw new ProfilePhotoOptimizationException(__('app.profile_photo_optimizer_thumbnail_buffer_failed'));
        }

        // Transparenz erhalten — relevant für PNG-Quellen, AVIF unterstützt Alpha.
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);

        imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            self::DIMENSION,
            self::DIMENSION,
            $squareSize,
            $squareSize,
        );

        return $thumbnail;
    }

    /**
     * Kodiert das Thumbnail als AVIF in eine temporäre Datei und gibt deren
     * Pfad zurück. Jetstreams `updateProfilePhoto()` verschiebt die Datei
     * anschließend in den endgültigen Storage.
     */
    private function encodeAvif(GdImage $image): string
    {
        $targetPath = tempnam(sys_get_temp_dir(), 'profile_photo_');

        if ($targetPath === false) {
            throw new ProfilePhotoOptimizationException(__('app.profile_photo_optimizer_temp_file_failed'));
        }

        if (!imageavif($image, $targetPath, self::AVIF_QUALITY)) {
            throw new ProfilePhotoOptimizationException(__('app.profile_photo_optimizer_encode_failed'));
        }

        return $targetPath;
    }
}
