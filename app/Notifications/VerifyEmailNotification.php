<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Über die Queue, damit die Registrierung in ihrer Timebox bleibt: Ein
 * synchroner Versand dauerte so lange, wie der Mailserver braucht, und ein
 * ausgetretenes Konto bekommt gar keine Mail.
 */
final class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
