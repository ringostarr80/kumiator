<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Upload\UploadLimitResolver;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\TestCase;

final class UploadLimitResolverTest extends TestCase
{
    public function testApplicationConfigWinsWhenItIsTheSmallestLimit(): void
    {
        // 1 KB liegt garantiert unter den PHP- und Livewire-Limits.
        config(['jetstream.profile_photo_max_kilobytes' => 1]);

        $limit = (new UploadLimitResolver())->resolveProfilePhotoLimit();

        $this->assertSame(1_024, $limit->bytes);
        $this->assertFalse($limit->constrainedByServer);
        $this->assertSame(1, $limit->kilobytes());
    }

    public function testLivewireTemporaryUploadLimitWinsWhenItIsTheSmallest(): void
    {
        config(['jetstream.profile_photo_max_kilobytes' => 1_048_576]); // 1 GB
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:5']]);

        $limit = (new UploadLimitResolver())->resolveProfilePhotoLimit();

        $this->assertSame(5 * 1_024, $limit->bytes);
        $this->assertTrue($limit->constrainedByServer);
    }

    public function testPhpIniLimitWinsWhenAppAndLivewireLimitsAreLarger(): void
    {
        config(['jetstream.profile_photo_max_kilobytes' => 1_048_576]); // 1 GB
        // Keine `max:`-Regel → Livewire-Schicht fällt aus der Minimum-Bildung.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file']]);

        $limit = (new UploadLimitResolver())->resolveProfilePhotoLimit();

        $this->assertSame((int) UploadedFile::getMaxFilesize(), $limit->bytes);
        $this->assertTrue($limit->constrainedByServer);
    }

    public function testLivewireDefaultLimitAppliesWhenRulesAreNotConfigured(): void
    {
        config(['jetstream.profile_photo_max_kilobytes' => 1_048_576]); // 1 GB
        config(['livewire.temporary_file_upload.rules' => null]);

        $limit = (new UploadLimitResolver())->resolveProfilePhotoLimit();

        // Effektiv greift entweder das Livewire-Default (12 MB) oder das
        // kleinere PHP-Limit — in jedem Fall nicht das 1-GB-App-Limit.
        $this->assertTrue($limit->constrainedByServer);
        $this->assertLessThanOrEqual(12_288 * 1_024, $limit->bytes);
    }

    public function testAcceptedExtensionsAreReturnedFromConfig(): void
    {
        config(['jetstream.profile_photo_accepted_extensions' => ['jpg', 'png', 'webp']]);

        $this->assertSame(
            ['jpg', 'png', 'webp'],
            (new UploadLimitResolver())->resolveProfilePhotoAcceptedExtensions(),
        );
    }

    public function testAcceptedExtensionsAreNormalisedAndDeduplicated(): void
    {
        // Mischmasch aus Whitespace, führendem Punkt, Großschreibung, Duplikat
        // und Müll-Einträgen — alles soll defensiv bereinigt werden.
        config(['jetstream.profile_photo_accepted_extensions' => [
            '  .JPG ',
            'jpg',
            '.png',
            'WEBP',
            '',
            42,
            null,
        ]]);

        $this->assertSame(
            ['jpg', 'png', 'webp'],
            (new UploadLimitResolver())->resolveProfilePhotoAcceptedExtensions(),
        );
    }
}
