<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Über die Queue, weil nur eine bekannte Adresse eine Mail auslöst: Ein
 * synchroner Versand verlängerte genau diese Antworten und verriete damit, zu
 * welcher Adresse ein Konto besteht.
 *
 * `ShouldBeEncrypted`, weil der Job den Token im Klartext trägt und in `jobs`
 * bzw. `failed_jobs` liegt, während `password_reset_tokens` ihn nur als Hash
 * hält.
 */
final class ResetPasswordNotification extends ResetPassword implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
}
