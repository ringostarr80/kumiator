<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Der Aufbau spiegelt einen echten Antrag auf einen E-Mail-Wechsel, ohne bei
 * jeder Vorbedingung den Antragspfad mitzutesten. An einer Stelle, damit die
 * Tests, die einen gültigen Link einlösen, ihn auch für die absichern, die
 * einen ungültigen erwarten: Deren Prüfung hielte sonst auch mit einem Token,
 * das nie gegolten hat.
 */
trait SeedsPendingEmailChange
{
    /**
     * Gespeichert sind wie im echten Antrag nur die Hashes.
     *
     * @return array{confirm: string, cancel: string}
     */
    protected function seedPendingChange(User $user, string $pendingEmail, ?Carbon $sentAt = null): array
    {
        $plainConfirmToken = bin2hex(random_bytes(32));
        $plainCancelToken = bin2hex(random_bytes(32));

        $user->forceFill([
            'pending_email' => $pendingEmail,
            'pending_email_confirm_token_hash' => hash('sha256', $plainConfirmToken),
            'pending_email_cancel_token_hash' => hash('sha256', $plainCancelToken),
            'pending_email_sent_at' => $sentAt ?? Carbon::now(),
        ])->saveOrFail();

        return ['confirm' => $plainConfirmToken, 'cancel' => $plainCancelToken];
    }
}
