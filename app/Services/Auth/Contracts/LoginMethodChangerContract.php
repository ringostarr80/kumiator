<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\PasskeyCredential;
use App\Models\User;

interface LoginMethodChangerContract
{
    /**
     * Schaltet den Passwort-Login ab, solange dem Konto ein Passkey bleibt — und
     * mit ihm alles, was aus dem Passwort hervorging: die anderen Sitzungen, den
     * Recaller-Cookie und einen offenen Reset-Link. Das gehört zur Zusage und
     * nicht zum Aufrufer, denn der Recaller-Pfad fragt die Spalte nie: Bliebe der
     * Cookie gültig, wäre der Passwort-Login nur dem Namen nach abgeschaltet.
     *
     * @return bool `false` nur, wenn kein Passkey vorliegt und der Passwort-Login
     *              deshalb offen bleibt. War er schon abgeschaltet, ist das ein
     *              `true` ohne weitere Wirkung — die Zusage gilt, und ein `false`
     *              hieße „kein Passkey".
     */
    public function disablePasswordLogin(User $user): bool;

    /**
     * Steht hier, obwohl das Einschalten die Zusage nie brechen kann: Das Löschen
     * eines Passkeys entscheidet anhand derselben Spalte und liest sie unter dem
     * Lock auf der Nutzerzeile.
     *
     * @return bool `false`, wenn der Passwort-Login schon offen war und nichts zu schreiben blieb.
     */
    public function enablePasswordLogin(User $user): bool;

    /**
     * Löscht den Passkey, solange dem Konto ein Anmeldeweg bleibt.
     *
     * @return bool `false`, wenn der Passkey der letzte eines Kontos ohne
     *              Passwort-Login war und deshalb stehen bleibt.
     */
    public function deletePasskey(PasskeyCredential $passkey): bool;
}
