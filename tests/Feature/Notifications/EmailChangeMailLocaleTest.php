<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\User\Contracts\UserEmailChangerContract;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
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
    // Kein RefreshDatabase: dessen Wrapping-Transaktion committet nie, sodass
    // der `afterCommit`-Queue-Push der Mails ausbliebe.
    use DatabaseMigrations;

    public function testQueuedMailsRenderInRequestLocaleInsteadOfWorkerDefault(): void
    {
        config(['queue.default' => 'database', 'app.locale' => 'en']);
        App::setLocale('de');

        $user = User::factory()->create(['email' => 'alt@example.com']);
        app(UserEmailChangerContract::class)->requestChange($user, 'neu@example.com');

        // Der Worker startet ohne Session und damit in der App-Default-Sprache.
        App::setLocale('en');
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $subjects = $this->sentSubjects();

        $this->assertCount(2, $subjects);
        $this->assertContains(__('app.email_change_verify_subject', [], 'de'), $subjects);
        $this->assertContains(__('app.email_change_requested_subject', [], 'de'), $subjects);
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
