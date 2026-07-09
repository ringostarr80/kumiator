<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProfileInformationAfterCommitTest extends TestCase
{
    // Bewusst KEIN RefreshDatabase: dessen umschließende Test-Transaktion
    // committet nie zur Root-Ebene, wodurch `afterCommit`-Callbacks (der
    // Queue-Push der ShouldQueueAfterCommit-Mails) darin nie feuern. Genau
    // dieser Post-Commit-Push ist der Auslöser dieses Tests — daher
    // migrate:fresh statt Wrapping-Transaktion.
    use DatabaseMigrations;

    /**
     * Foto + E-Mail zusammen ändern: der E-Mail-Wechsel reiht die Confirm-/
     * Cancel-Mails (`ShouldQueueAfterCommit`) als `afterCommit`-Push in DIESELBE
     * Profil-Transaktion ein. Dieser Push läuft erst NACH dem PDO-Commit — die
     * Daten sind dann bereits durabel. Wirft der Push (Queue-Backend im
     * Commit-Fenster gestört), darf der Rollback-`catch` die soeben committete
     * Foto-Datei NICHT löschen, sonst zeigt der persistierte Pfad ins Leere.
     */
    public function testCommittedPhotoSurvivesAFailingAfterCommitNotificationDispatch(): void
    {
        Storage::fake('public');

        $this->actingAs($user = User::factory()->create([
            'name' => 'Original',
            'email' => 'original@example.com',
        ]));

        // Queue auf `database` (wie Prod) mit einer nicht existenten jobs-Tabelle:
        // der Push-INSERT wirft dann — als Callback NACH dem Commit, exakt die
        // Queue-Störung im Commit-Fenster aus dem Finding.
        config([
            'queue.default' => 'database',
            'queue.connections.database.table' => 'nonexistent_jobs',
        ]);

        try {
            app(UpdateUserProfileInformation::class)->update($user, [
                'name' => 'Geändert',
                'email' => 'neu@example.com',
                'current_password' => 'password',
                'photo' => UploadedFile::fake()->image('photo.jpg'),
            ]);
            $this->fail('Erwartete Exception aus dem fehlschlagenden afterCommit-Push.');
        } catch (\Throwable) {
            // erwartet — der Queue-Push wirft, nachdem die Transaktion committet ist.
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        // Beleg, dass der Fehler POST-Commit war (kein Rollback): Foto-Pfad und
        // pending_email sind persistiert.
        $newPhotoPath = $refreshed->profile_photo_path;
        $this->assertIsString($newPhotoPath);
        $this->assertSame('neu@example.com', $refreshed->pending_email);

        // die committete Datei darf NICHT vom Rollback-catch gelöscht worden sein.
        Storage::disk('public')->assertExists($newPhotoPath);
    }
}
