<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Upload\Exceptions\ProfilePhotoOptimizationException;
use App\Services\Upload\ProfilePhotoOptimizer;
use GdImage;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProfilePhotoOptimizerTest extends TestCase
{
    public function testOptimizeProducesA256x256AvifFile(): void
    {
        $photo = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $result = (new ProfilePhotoOptimizer())->optimize($photo);

        $this->assertStringEndsWith('.avif', $result->getClientOriginalName());

        $info = getimagesize($result->getRealPath());
        $this->assertNotFalse($info);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame(IMAGETYPE_AVIF, $info[2]);
    }

    public function testOptimizeAcceptsPngInput(): void
    {
        $photo = UploadedFile::fake()->image('photo.png', 400, 400);

        $result = (new ProfilePhotoOptimizer())->optimize($photo);

        $info = getimagesize($result->getRealPath());
        $this->assertNotFalse($info);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame(IMAGETYPE_AVIF, $info[2]);
    }

    public function testOptimizeRejectsAFileThatIsNotAnImage(): void
    {
        $photo = UploadedFile::fake()->create('broken.jpg', 1);

        $this->expectException(ProfilePhotoOptimizationException::class);

        (new ProfilePhotoOptimizer())->optimize($photo);
    }

    /**
     * Ein JPEG ganz ohne EXIF-Block (z. B. von Software exportiert) muss als
     * ungedreht behandelt werden — die Quadranten landen unverändert.
     */
    public function testJpegWithoutExifDataIsTreatedAsUnrotated(): void
    {
        $photo = $this->fourQuadrantJpeg(orientation: null);

        $result = (new ProfilePhotoOptimizer())->optimize($photo);
        $thumbnail = $this->readAvif($result->getRealPath());

        $this->assertSame('red', $this->dominantColorAt($thumbnail, 64, 64));
        $this->assertSame('green', $this->dominantColorAt($thumbnail, 192, 64));
        $this->assertSame('blue', $this->dominantColorAt($thumbnail, 64, 192));
        $this->assertSame('yellow', $this->dominantColorAt($thumbnail, 192, 192));
    }

    /**
     * Ein JPEG mit defektem EXIF-Block darf den Upload nicht scheitern lassen —
     * der Optimizer behandelt es als ungedreht und liefert trotzdem ein
     * gültiges Thumbnail.
     */
    public function testCorruptExifDataDoesNotBreakOptimization(): void
    {
        $photo = $this->corruptExifJpeg();

        $result = (new ProfilePhotoOptimizer())->optimize($photo);

        $info = getimagesize($result->getRealPath());
        $this->assertNotFalse($info);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame(IMAGETYPE_AVIF, $info[2]);
    }

    /**
     * Prüft die EXIF-Orientierungskorrektur für alle acht Orientierungen.
     *
     * Das Quell-JPEG hat vier farbige Quadranten (oben-links rot, oben-rechts
     * grün, unten-links blau, unten-rechts gelb). Je nach EXIF-Orientierung
     * muss der Optimizer sie in eine definierte Ansichtslage drehen/spiegeln —
     * der Erwartungswert beschreibt, welche Farbe danach in welchem Quadranten
     * des Thumbnails liegen muss.
     *
     * @param array{0: string, 1: string, 2: string, 3: string} $expectedQuadrants
     *        Erwartete Farben im Thumbnail: [oben-links, oben-rechts, unten-links, unten-rechts].
     */
    #[DataProvider('orientationProvider')]
    public function testExifOrientationIsCorrected(int $orientation, array $expectedQuadrants): void
    {
        $photo = $this->fourQuadrantJpeg($orientation);

        $result = (new ProfilePhotoOptimizer())->optimize($photo);
        $thumbnail = $this->readAvif($result->getRealPath());

        $this->assertSame($expectedQuadrants[0], $this->dominantColorAt($thumbnail, 64, 64), 'oben-links');
        $this->assertSame($expectedQuadrants[1], $this->dominantColorAt($thumbnail, 192, 64), 'oben-rechts');
        $this->assertSame($expectedQuadrants[2], $this->dominantColorAt($thumbnail, 64, 192), 'unten-links');
        $this->assertSame($expectedQuadrants[3], $this->dominantColorAt($thumbnail, 192, 192), 'unten-rechts');
    }

    /**
     * @return array<string, array{int, array{0: string, 1: string, 2: string, 3: string}}>
     */
    public static function orientationProvider(): array
    {
        // Quell-Layout: [oben-links=rot, oben-rechts=grün, unten-links=blau, unten-rechts=gelb].
        return [
            'unverändert' => [1, ['red', 'green', 'blue', 'yellow']],
            'horizontal gespiegelt' => [2, ['green', 'red', 'yellow', 'blue']],
            'um 180° gedreht' => [3, ['yellow', 'blue', 'green', 'red']],
            'vertikal gespiegelt' => [4, ['blue', 'yellow', 'red', 'green']],
            'transponiert' => [5, ['red', 'blue', 'green', 'yellow']],
            'um 90° im Uhrzeigersinn' => [6, ['blue', 'red', 'yellow', 'green']],
            'antitransponiert' => [7, ['yellow', 'green', 'blue', 'red']],
            'um 90° gegen den Uhrzeigersinn' => [8, ['green', 'yellow', 'red', 'blue']],
        ];
    }

    /**
     * Erzeugt ein quadratisches Vier-Quadranten-JPEG. Bei gesetzter
     * `$orientation` wird ihm ein EXIF-APP1-Block mit diesem Orientation-Tag
     * vorangestellt — so lässt sich die Rotationskorrektur ohne committetes
     * Binär-Fixture testen. `null` erzeugt ein JPEG ganz ohne EXIF-Block.
     */
    private function fourQuadrantJpeg(?int $orientation): UploadedFile
    {
        $size = 200;
        $half = intdiv($size, 2);
        $image = imagecreatetruecolor($size, $size);
        $this->assertInstanceOf(GdImage::class, $image);

        $red = imagecolorallocate($image, 220, 30, 30);
        $green = imagecolorallocate($image, 30, 220, 30);
        $blue = imagecolorallocate($image, 30, 30, 220);
        $yellow = imagecolorallocate($image, 220, 220, 30);
        $this->assertNotFalse($red);
        $this->assertNotFalse($green);
        $this->assertNotFalse($blue);
        $this->assertNotFalse($yellow);

        imagefilledrectangle($image, 0, 0, $half - 1, $half - 1, $red);
        imagefilledrectangle($image, $half, 0, $size - 1, $half - 1, $green);
        imagefilledrectangle($image, 0, $half, $half - 1, $size - 1, $blue);
        imagefilledrectangle($image, $half, $half, $size - 1, $size - 1, $yellow);

        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();
        $this->assertIsString($jpeg);

        if ($orientation !== null) {
            // Minimaler EXIF-APP1-Block (big-endian) mit nur dem Orientation-Tag
            // (0x0112, Typ SHORT). Wird direkt hinter den SOI-Marker (FF D8)
            // gesetzt — die EXIF-konforme Position als erstes Segment.
            $app1 = "\xFF\xE1\x00\x22Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x01"
                . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00"
                . "\x00\x00\x00\x00";
            $jpeg = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
        }

        $path = tempnam(sys_get_temp_dir(), 'exif_test_');
        $this->assertIsString($path);
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', test: true);
    }

    /**
     * Erzeugt ein gültiges JPEG mit einem EXIF-APP1-Block, dessen TIFF-Byte-
     * Order-Marker absichtlich kaputt ist ("ZZ" statt "MM"/"II"). GD überspringt
     * das APP1-Segment beim Dekodieren anhand der Längenangabe, `exif_read_data()`
     * bricht jedoch daran ab.
     */
    private function corruptExifJpeg(): UploadedFile
    {
        $image = imagecreatetruecolor(120, 120);
        $this->assertInstanceOf(GdImage::class, $image);

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        $this->assertIsString($jpeg);

        // APP1-Länge 0x10: 2 Längenbytes + "Exif\0\0" (6) + "ZZ" (2) +
        // TIFF-Magic/Offset (6). Die Längenangabe bleibt gültig, nur der
        // TIFF-Inhalt ist unbrauchbar.
        $app1 = "\xFF\xE1\x00\x10Exif\x00\x00ZZ\x00\x2A\x00\x00\x00\x08";
        $jpeg = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);

        $path = tempnam(sys_get_temp_dir(), 'exif_test_');
        $this->assertIsString($path);
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', test: true);
    }

    private function readAvif(string $path): GdImage
    {
        $image = imagecreatefromavif($path);
        $this->assertInstanceOf(GdImage::class, $image);

        return $image;
    }

    /**
     * Klassifiziert einen Pixel grob nach dominanter Farbe. AVIF ist verlustbehaftet,
     * die Schwelle von 110 ist großzügig genug für die kräftigen Quadrantenfarben.
     */
    private function dominantColorAt(GdImage $image, int $x, int $y): string
    {
        $rgb = imagecolorat($image, $x, $y);
        $this->assertNotFalse($rgb);

        $red = (($rgb >> 16) & 0xFF) > 110;
        $green = (($rgb >> 8) & 0xFF) > 110;
        $blue = ($rgb & 0xFF) > 110;

        return match (true) {
            $red && $green && !$blue => 'yellow',
            $red && !$green && !$blue => 'red',
            !$red && $green && !$blue => 'green',
            !$red && !$green && $blue => 'blue',
            default => 'unknown',
        };
    }
}
