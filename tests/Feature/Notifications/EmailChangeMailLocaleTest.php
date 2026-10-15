<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Die Sprache stammt in dieser App ausschließlich aus der Session
 * (`SetLocale`-Middleware). Beide E-Mail-Wechsel-Mails sind
 * `ShouldQueueAfterCommit`, rendern also im Worker — der keine Session hat und
 * darum auf `APP_LOCALE` zurückfällt. Betroffen ist damit auch die Warnmail an
 * die alte Adresse, die ein Hijack-Opfer sofort verstehen muss.
 */
final class EmailChangeMailLocaleTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('targetAddressProvider')]
    public function testQueuedMailsRenderInRequestLocaleInsteadOfWorkerDefault(
        bool $targetTaken,
        string $firstSubjectKey,
    ): void {
        config(['queue.default' => 'database', 'app.locale' => 'en']);

        $user = User::factory()->create(['email' => 'alt@example.com']);

        if ($targetTaken) {
            User::factory()->create(['email' => 'neu@example.com']);
        }

        $this->actingAs($user)->withSession(['locale' => 'de'])->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'neu@example.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        // Der Worker startet ohne Session und ohne die Sprache des Requests,
        // also in der App-Default-Sprache.
        App::setLocale('en');
        Notification::locale('en');

        // Der Worker misst den Speicher des ganzen Prozesses. Läuft die Suite in
        // einem einzigen Prozess, liegt der schon über dem Default von 128 MB,
        // und der Worker hört nach der ersten Mail auf.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--memory' => 0]);

        $subjects = $this->sentSubjects();

        $this->assertCount(2, $subjects);
        $this->assertContains(__($firstSubjectKey, [], 'de'), $subjects);
        $this->assertContains(__('app.email_change_requested_subject', [], 'de'), $subjects);
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function targetAddressProvider(): array
    {
        // Ist die Zieladresse vergeben, geht statt der Bestätigung ein Hinweis
        // an deren Inhaber. Die Warnmail an die alte Adresse ist in beiden
        // Fällen nicht der erste Versand im Request.
        return [
            'freie Zieladresse' => [false, 'app.email_change_verify_subject'],
            'vergebene Zieladresse' => [true, 'app.email_change_target_taken_subject'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sentSubjects(): array
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $subjects = [];

        foreach ($transport->messages() as $sentMessage) {
            $this->assertInstanceOf(SentMessage::class, $sentMessage);
            $message = $sentMessage->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $message);
            $subjects[] = (string)$message->getSubject();
        }

        return $subjects;
    }
}
