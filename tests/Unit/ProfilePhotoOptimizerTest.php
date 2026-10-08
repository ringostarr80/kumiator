<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Upload\Exceptions\ProfilePhotoOptimizationException;
use App\Services\Upload\ProfilePhotoOptimizer;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProfilePhotoOptimizerTest extends TestCase
{
    private string $temporaryDirectory;

    public function testOptimizeProducesA256x256AvifFile(): void
    {
        $photo = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $result = (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);

        $this->assertStringEndsWith('.avif', $result->getClientOriginalName());
        // Die Datei kam nicht per HTTP. Gälte sie nicht trotzdem als gültiger Upload, lehnte etwa
        // Laravels `max`-Regel sie ab.
        $this->assertTrue($result->isValid());

        $info = getimagesize($result->getRealPath());
        $this->assertNotFalse($info);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame(IMAGETYPE_AVIF, $info[2]);
    }

    /**
     * EXIF liest der Optimizer nur aus JPEGs, ein PNG bleibt ungedreht.
     */
    public function testOptimizeAcceptsPngInput(): void
    {
        $photo = $this->pngFile($this->fourQuadrantImage());

        $result = (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);

        $info = getimagesize($result->getRealPath());
        $this->assertNotFalse($info);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame(IMAGETYPE_AVIF, $info[2]);
        $this->assertQuadrants($this->readAvif($result->getRealPath()), ['red', 'green', 'blue', 'yellow']);
    }

    /**
     * Die Anzeige ist rund und schneidet ohnehin die Ränder ab. Das Motiv steht meist in der Mitte.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    #[DataProvider('nonSquareProvider')]
    public function testNonSquarePhotoIsCroppedToItsCentre(int $width, int $height): void
    {
        $photo = $this->pngFile($this->threeStripeImage($width, $height));

        $result = (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);

        $this->assertQuadrants($this->readAvif($result->getRealPath()), ['green', 'green', 'green', 'green']);
    }

    /**
     * Ein freigestelltes Foto soll nicht vor schwarzem Hintergrund im Profil stehen.
     */
    public function testTransparencyIsPreserved(): void
    {
        $image = imagecreatetruecolor(200, 200);
        $this->assertInstanceOf(GdImage::class, $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $red = imagecolorallocatealpha($image, 220, 30, 30, 0);
        $this->assertNotFalse($transparent);
        $this->assertNotFalse($red);

        imagefilledrectangle($image, 0, 0, 99, 199, $transparent);
        imagefilledrectangle($image, 100, 0, 199, 199, $red);

        $result = (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($this->pngFile($image));
        $thumbnail = $this->readAvif($result->getRealPath());

        $this->assertGreaterThan(120, $this->alphaAt($thumbnail, 64, 128), 'links durchsichtig');
        $this->assertLessThan(7, $this->alphaAt($thumbnail, 192, 128), 'rechts deckend');
        $this->assertSame('red', $this->dominantColorAt($thumbnail, 192, 128));
    }

    public function testOptimizeRejectsAFileThatIsNotAnImage(): void
    {
        $photo = UploadedFile::fake()->create('broken.jpg', 1);

        $this->expectException(ProfilePhotoOptimizationException::class);

        (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);
    }

    /**
     * Dekompressions-Bomben-Schutz: Ein kleines, hochkomprimiertes Bild kann
     * im Header Riesen-Dimensionen deklarieren, deren Decode
     * `Breite × Höhe × 4` Bytes erzwingt. Solche Bilder müssen am
     * Header-Check scheitern, bevor `imagecreatefromstring()` den Speicher
     * tatsächlich anfordert. Beide Kanten liegen über einem Pixel, denn es
     * zählt die Fläche, nicht eine einzelne Kante.
     */
    public function testOptimizeRejectsImagesDeclaringMoreThanTheMaximumPixels(): void
    {
        $photo = $this->pngDeclaringDimensions(5_000, 5_001);

        $this->expectException(ProfilePhotoOptimizationException::class);
        $this->expectExceptionMessageIs(
            __('app.profile_photo_optimizer_too_many_pixels', ['max_megapixels' => 25]),
        );

        (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);
    }

    /**
     * Genau an der Grenze greift der Guard nicht: Das Fixture kommt bis zum
     * Decode und scheitert erst dort an den 1×1-Pixeldaten. Das nagelt die
     * Grenze als „mehr als" statt „mindestens" fest — sonst fielen legitime
     * Fotos exakt an der Grenze durch.
     */
    public function testOptimizeLetsImagesExactlyAtTheMaximumPixelsPassTheGuard(): void
    {
        $photo = $this->pngDeclaringDimensions(25_000_000, 1);

        $this->expectException(ProfilePhotoOptimizationException::class);
        $this->expectExceptionMessageIs(__('app.profile_photo_optimizer_not_an_image'));

        (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);
    }

    /**
     * Ein JPEG ganz ohne EXIF-Block (z. B. von Software exportiert) muss als
     * ungedreht behandelt werden — die Quadranten landen unverändert.
     */
    public function testJpegWithoutExifDataIsTreatedAsUnrotated(): void
    {
        $photo = $this->fourQuadrantJpeg(orientation: null);

        $result = (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);
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

        $result = (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);

        $info = getimagesize($result->getRealPath());
        $this->assertNotFalse($info);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame(IMAGETYPE_AVIF, $info[2]);
        $this->assertQuadrants($this->readAvif($result->getRealPath()), ['red', 'green', 'blue', 'yellow']);
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

        $result = (new ProfilePhotoOptimizer($this->temporaryDirectory))->optimize($photo);

        $this->assertQuadrants($this->readAvif($result->getRealPath()), $expectedQuadrants);
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
     * @return array<string, array{positive-int, positive-int}>
     */
    public static function nonSquareProvider(): array
    {
        return [
            'quer' => [300, 100],
            'hoch' => [100, 300],
        ];
    }

    /**
     * Der Optimizer reicht seine Zwischendatei an den Aufrufer weiter, der sie nach dem Speichern
     * löscht. Hier gibt es keinen solchen Aufrufer — ein eigenes Verzeichnis lässt sie am Ende
     * geschlossen wegräumen, statt sie im geteilten Systemverzeichnis liegen zu lassen.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir() . '/' . uniqid('optimizer-', true);
        File::makeDirectory($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    /**
     * Erzeugt ein quadratisches Vier-Quadranten-JPEG. Bei gesetzter
     * `$orientation` wird ihm ein EXIF-APP1-Block mit diesem Orientation-Tag
     * vorangestellt — so lässt sich die Rotationskorrektur ohne committetes
     * Binär-Fixture testen. `null` erzeugt ein JPEG ganz ohne EXIF-Block.
     */
    private function fourQuadrantJpeg(?int $orientation): UploadedFile
    {
        $image = $this->fourQuadrantImage();

        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = ob_get_clean();

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
        $image = $this->fourQuadrantImage();

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();

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

    /**
     * Erzeugt ein PNG, dessen IHDR-Header die angegebenen Dimensionen
     * deklariert, dessen Pixeldaten aber von einem 1×1-Bild stammen — das
     * Angriffsmuster einer Dekompressions-Bombe, ohne dass der Test selbst
     * ein Riesen-Bild allokieren muss. `getimagesize*()` liest nur den
     * Header und prüft keine Prüfsummen; der Decoder dagegen schon.
     */
    private function pngDeclaringDimensions(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor(1, 1);
        $this->assertInstanceOf(GdImage::class, $image);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        // IHDR: Breite ab Byte 16, Höhe ab Byte 20 (je 4 Bytes, big-endian).
        $png = substr_replace($png, pack('N2', $width, $height), 16, 8);

        $path = tempnam(sys_get_temp_dir(), 'pixel_limit_test_');
        $this->assertIsString($path);
        file_put_contents($path, $png);

        return new UploadedFile($path, 'photo.png', 'image/png', test: true);
    }

    /**
     * Quadratisches Bild: oben-links rot, oben-rechts grün, unten-links blau, unten-rechts gelb.
     */
    private function fourQuadrantImage(): GdImage
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

        return $image;
    }

    /**
     * Drei gleich breite Streifen rot, grün, blau entlang der langen Seite. Die kurze Seite
     * entspricht genau einem Streifen, der mittige Zuschnitt zeigt also nur Grün.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    private function threeStripeImage(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);

        $stripe = min($width, $height);

        foreach ([[220, 30, 30], [30, 220, 30], [30, 30, 220]] as $index => [$red, $green, $blue]) {
            $color = imagecolorallocate($image, $red, $green, $blue);
            $this->assertNotFalse($color);

            $start = $index * $stripe;
            $end = $start + $stripe - 1;

            if ($width > $height) {
                imagefilledrectangle($image, $start, 0, $end, $height - 1, $color);
            } else {
                imagefilledrectangle($image, 0, $start, $width - 1, $end, $color);
            }
        }

        return $image;
    }

    private function pngFile(GdImage $image): UploadedFile
    {
        $path = tempnam($this->temporaryDirectory, 'png_test_');
        $this->assertIsString($path);
        imagepng($image, $path);

        return new UploadedFile($path, 'photo.png', 'image/png', test: true);
    }

    /**
     * Prüft je Quadrant die Mitte und das äußerste Eckpixel. Die Ecken zeigen, dass das Bild exakt
     * sitzt: Schon 1° Drehung ließe dort schwarze Keile stehen, 1 Pixel Versatz einen dunklen Rand.
     *
     * @param array{0: string, 1: string, 2: string, 3: string} $expected
     *        Erwartete Farben: [oben-links, oben-rechts, unten-links, unten-rechts].
     */
    private function assertQuadrants(GdImage $thumbnail, array $expected): void
    {
        $samples = [
            [$expected[0], 64, 64, 0, 0, 'oben-links'],
            [$expected[1], 192, 64, 255, 0, 'oben-rechts'],
            [$expected[2], 64, 192, 0, 255, 'unten-links'],
            [$expected[3], 192, 192, 255, 255, 'unten-rechts'],
        ];

        foreach ($samples as [$color, $centerX, $centerY, $cornerX, $cornerY, $quadrant]) {
            $this->assertSame($color, $this->dominantColorAt($thumbnail, $centerX, $centerY), $quadrant);
            $this->assertSame($color, $this->dominantColorAt($thumbnail, $cornerX, $cornerY), $quadrant . ', Ecke');
        }
    }

    /**
     * GD-Alpha: 0 deckend, 127 durchsichtig.
     */
    private function alphaAt(GdImage $image, int $x, int $y): int
    {
        $rgba = imagecolorat($image, $x, $y);
        $this->assertNotFalse($rgba);

        return ($rgba >> 24) & 0x7F;
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
