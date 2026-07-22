<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Gemeinsamer Ablauf der `user:restore`/`user:force-delete`-Commands, die
 * beide auf einen bereits soft-deleted Benutzer wirken: Titel ausgeben,
 * E-Mail erfragen, den Trashed-User laden (nicht gefunden → Fehler), Fund
 * und Warnungen ausgeben, bestätigen lassen und die Aktion ausführen.
 * Meldungs-Keys, Warnzeilen und die Aktion liefern die Subklassen über die
 * abstrakten Hooks.
 */
abstract class TrashedUserActionCommand extends Command
{
    abstract protected function titleKey(): string;

    abstract protected function notTrashedKey(): string;

    abstract protected function userFoundKey(): string;

    /**
     * @return list<string>
     */
    abstract protected function warningKeys(): array;

    abstract protected function confirmKey(): string;

    abstract protected function abortedKey(): string;

    abstract protected function successKey(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function successReplacements(User $user, string $email): array;

    abstract protected function performAction(User $user): void;

    public function handle(): int
    {
        $title = __($this->titleKey());
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        $user = User::queryByEmail($email)->onlyTrashed()->first();

        if ($user === null) {
            // Aus Admin-Sicht egal, ob der User gar nicht existiert oder
            // einfach nicht soft-deleted ist — beides verhindert die Aktion.
            // Eine spezifische „existiert nicht"-Meldung würde nur die
            // Existenz aktiver Konten leaken.
            $this->error(__($this->notTrashedKey(), ['email' => $email]));

            return self::FAILURE;
        }

        $this->line(__($this->userFoundKey(), [
            'name' => $user->name,
            'email' => $email,
            'deleted_at' => $user->deleted_at?->format('d.m.Y H:i') ?? '—',
        ]));

        foreach ($this->warningKeys() as $warningKey) {
            $this->warn(__($warningKey));
        }

        if (!$this->confirm(__($this->confirmKey()))) {
            $this->info(__($this->abortedKey()));

            return self::SUCCESS;
        }

        $this->performAction($user);

        $this->info(__($this->successKey(), $this->successReplacements($user, $email)));

        return self::SUCCESS;
    }
}
