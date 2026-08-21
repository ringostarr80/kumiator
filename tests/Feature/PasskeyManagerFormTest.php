<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\PasskeyManagerForm;
use App\Models\PasskeyCredential;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ConfirmsPassword;
use Tests\TestCase;

final class PasskeyManagerFormTest extends TestCase
{
    use ConfirmsPassword;
    use RefreshDatabase;

    public function testComponentRendersForAuthenticatedUser(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertOk();
    }

    public function testComponentListsUsersPasskeys(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 2);
    }

    public function testComponentDoesNotListOtherUsersPasskeys(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        PasskeyCredential::factory()->for($other)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 0);
    }

    public function testPasskeysAreOrderedNewestFirst(): void
    {
        $user = User::factory()->create();
        $old = PasskeyCredential::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $new = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSet('passkeys.0.id', $new->id)
            ->assertSet('passkeys.1.id', $old->id);
    }

    public function testLoadPasskeysAbortsWhenAuthUserIsNull(): void
    {
        Livewire::test(PasskeyManagerForm::class)->assertStatus(401);
    }

    public function testOwnerCanDeletePasskey(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('deletePasskey', $passkey->id);

        $this->assertModelMissing($passkey);
    }

    public function testDeletePasskeyRefreshesPasskeyList(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 1)
            ->call('deletePasskey', $passkey->id)
            ->assertCount('passkeys', 0);
    }

    public function testPasskeyRegisteredEventRefreshesPasskeyList(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 0);

        PasskeyCredential::factory()->for($user)->create();

        $component->dispatch('passkey-registered')
            ->assertCount('passkeys', 1); // @phpstan-ignore method.nonObject
    }

    public function testOtherUserCannotDeletePasskey(): void
    {
        $this->confirmPassword();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create();

        Livewire::actingAs($other)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('deletePasskey', $passkey->id)
            ->assertForbidden();

        $this->assertModelExists($passkey);
    }

    public function testStartRenamingPrefillsCurrentName(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'Mein iPhone']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->assertSet('editingPasskeyId', $passkey->id)
            ->assertSet('editingPasskeyName', 'Mein iPhone');
    }

    public function testCancelRenamingResetsEditingState(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'MacBook']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->call('cancelRenaming')
            ->assertSet('editingPasskeyId', null)
            ->assertSet('editingPasskeyName', '');
    }

    public function testOwnerCanRenamePasskey(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'Alt']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->set('editingPasskeyName', 'Neu')
            ->call('renamePasskey')
            ->assertSet('editingPasskeyId', null)
            ->assertSet('editingPasskeyName', '');

        $this->assertSame('Neu', $passkey->fresh()?->name);
    }

    public function testRenamePasskeyTrimsWhitespace(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'Alt']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->set('editingPasskeyName', '  Mein YubiKey  ')
            ->call('renamePasskey');

        $this->assertSame('Mein YubiKey', $passkey->fresh()?->name);
    }

    public function testRenamePasskeyRejectsEmptyName(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'Alt']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->set('editingPasskeyName', '')
            ->call('renamePasskey')
            ->assertHasErrors(['editingPasskeyName' => 'required']);

        $this->assertSame('Alt', $passkey->fresh()?->name);
    }

    public function testRenamePasskeyRejectsTooLongName(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'Alt']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->set('editingPasskeyName', str_repeat('x', 81))
            ->call('renamePasskey')
            ->assertHasErrors(['editingPasskeyName' => 'max']);

        $this->assertSame('Alt', $passkey->fresh()?->name);
    }

    public function testOtherUserCannotStartRenamingPasskey(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create();

        Livewire::actingAs($other)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->assertForbidden();
    }

    public function testOtherUserCannotRenamePasskey(): void
    {
        $this->confirmPassword();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create(['name' => 'Owner-Name']);

        Livewire::actingAs($other)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->set('editingPasskeyId', $passkey->id)
            ->set('editingPasskeyName', 'Hijacked')
            ->call('renamePasskey')
            ->assertForbidden();

        $this->assertSame('Owner-Name', $passkey->fresh()?->name);
    }

    public function testDeletePasskeyRequiresConfirmedPassword(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('deletePasskey', $passkey->id)
            ->assertForbidden();

        $this->assertModelExists($passkey);
    }

    public function testRenamePasskeyRequiresConfirmedPassword(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'Alt']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->set('editingPasskeyId', $passkey->id)
            ->set('editingPasskeyName', 'Neu')
            ->call('renamePasskey')
            ->assertForbidden();

        $this->assertSame('Alt', $passkey->fresh()?->name);
    }

    public function testStartPasskeyRegistrationRequiresConfirmedPassword(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startPasskeyRegistration')
            ->assertForbidden();
    }

    public function testStartPasskeyRegistrationReleasesTheFormWhenPasswordIsConfirmed(): void
    {
        $user = User::factory()->create();

        $this->confirmPassword();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startPasskeyRegistration')
            ->assertDispatched('passkey-registration-confirmed');
    }

    /**
     * Beide Aktionen verlangen in der Komponente eine frische Bestätigung; ein
     * roher `wire:click` überspränge den Dialog und liefe in ihr 403.
     */
    public function testConfirmedActionsHangOnThePasswordDialog(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSeeHtml("wire:then=\"deletePasskey('{$passkey->id}')\"")
            ->assertSeeHtml('wire:then="startPasskeyRegistration"');
    }

    /**
     * `startRenaming()` verlangt selbst keine Bestätigung; der Dialog am
     * Einstieg holt sie ein, bevor der Name überhaupt eingetippt wird.
     */
    public function testEnteringRenameModeHangsOnThePasswordDialog(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSeeHtml("wire:then=\"startRenaming('{$passkey->id}')\"");
    }

    /**
     * Innerhalb des Bestätigungsfensters öffnet der Dialog sich nicht mehr, ein
     * Klick löscht dann sofort. Die Rückfrage muss am selben Element hängen wie
     * die Aktion, weil Livewire sie nur dort auswertet.
     */
    public function testDeletingAsksForConfirmationBeforeItRuns(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        $html = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->html();

        $pattern = '/' . preg_quote("wire:then=\"deletePasskey('{$passkey->id}')\"", '/')
            . '\s+' . preg_quote('wire:confirm="' . __('app.passkey_delete_confirm') . '"', '/') . '/';

        $this->assertMatchesRegularExpression($pattern, $html);

        // Umbenennen und Registrieren sind umkehrbar und bleiben ohne Rückfrage.
        $this->assertSame(1, substr_count($html, 'wire:confirm='));
    }

    /**
     * Der Bearbeitungsmodus wird über den Dialog betreten, aber erst das
     * Speichern trifft auf `ensurePasswordIsConfirmed()`. Läuft das
     * Bestätigungsfenster dazwischen ab, endet ein roher `wire:click` im 403,
     * statt erneut nach dem Passwort zu fragen.
     */
    public function testSavingARenameHangsOnThePasswordDialog(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        $html = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->set('editingPasskeyId', $passkey->id)
            ->html();

        $this->assertStringContainsString('wire:then="renamePasskey"', $html);
        $this->assertStringNotContainsString('wire:click="renamePasskey"', $html);
        $this->assertStringNotContainsString('wire:keydown.enter="renamePasskey"', $html);

        // Die Enter-Taste klickt den Button und nimmt damit denselben Weg.
        $this->assertStringContainsString("id=\"passkey-save-{$passkey->id}\"", $html);
        $this->assertStringContainsString("getElementById('passkey-save-{$passkey->id}')", $html);
    }

    /**
     * Jeder Bereich der Profilseite platziert seinen eigenen Dialog. Fehlt er
     * im Passkey-Bereich, reagiert dort kein Button mehr auf einen Klick.
     */
    public function testPasskeySectionRendersItsOwnPasswordDialog(): void
    {
        $user = User::factory()->create();

        // Allein die Komponente rendern: ein Treffer darin kann nur ihrer sein.
        $html = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->html();

        $this->assertSame(1, substr_count($html, 'wire:model="confirmablePassword"'));
    }

    /**
     * Beide Dialoge hängen an einem `confirmingPassword`, aus dem sie ohne
     * eigenen Schlüssel dieselbe DOM-`id` ableiten würden.
     */
    public function testPasswordDialogsCarryDistinctIds(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/user/profile')->content();

        $this->assertSame(1, substr_count($html, 'id="confirm-password-two-factor"'));
        $this->assertSame(1, substr_count($html, 'id="confirm-password-passkeys"'));
    }

    /**
     * Alpine blendet den Registrierungsbereich aus, sobald das Formular offen
     * ist. Ein darin gerenderter Dialog wäre für den Nutzer unsichtbar, und
     * welcher Button ihn ausgibt, hängt vom Zustand der Liste ab.
     */
    public function testPasswordDialogSitsOutsideTheRegistrationBlockInEveryState(): void
    {
        $user = User::factory()->create();

        $this->assertDialogSitsOutsideTheRegistrationBlock(
            Livewire::actingAs($user)->test(PasskeyManagerForm::class)->html(), // @phpstan-ignore argument.templateType
        );

        $passkey = PasskeyCredential::factory()->for($user)->create();

        $this->assertDialogSitsOutsideTheRegistrationBlock(
            Livewire::actingAs($user)->test(PasskeyManagerForm::class)->html(), // @phpstan-ignore argument.templateType
        );

        $this->assertDialogSitsOutsideTheRegistrationBlock(
            Livewire::actingAs($user)
                ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
                ->set('editingPasskeyId', $passkey->id)
                ->html(),
        );
    }

    /**
     * Der Wortlaut bleibt draußen, geprüft wird die Argument-Position: An
     * zweiter Stelle stünde der Text als Erfolgsmeldung im Formular.
     */
    public function testRegistrationErrorTextIsPassedAsTheFallbackArgument(): void
    {
        $user = User::factory()->create();

        $html = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->html();

        $pattern = '/' . preg_quote(e(__('app.passkey_added')), '/')
            . '\',\s*\'' . preg_quote(e(__('app.passkey_registration_aborted')), '/') . '\',/';

        $this->assertMatchesRegularExpression($pattern, $html);
    }

    /**
     * Antworten aus der Middleware tragen einen im Framework fest verdrahteten
     * Text. Fehlt die Übersetzung im x-data, zeigt die Oberfläche ihn unverändert
     * auf Englisch an — beim abgelaufenen Login das englische `Unauthenticated.`.
     */
    public function testMiddlewareResponsesCarryTranslatedMessages(): void
    {
        $user = User::factory()->create();

        $html = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->html();

        $this->assertStringContainsString("401: '" . e(__('app.passkey_login_expired')) . "'", $html);
        $this->assertStringContainsString("419: '" . e(__('app.passkey_page_expired')) . "'", $html);
        $this->assertStringContainsString("423: '" . e(__('app.passkey_confirmation_expired')) . "'", $html);
        $this->assertStringContainsString("429: '" . e(__('app.passkey_too_many_attempts')) . "'", $html);

        // Ein fehlender Schlüssel käme als Rohtext durch und würde sonst durchgehen.
        $this->assertStringNotContainsString('app.passkey_', $html);
    }

    private function assertDialogSitsOutsideTheRegistrationBlock(string $html): void
    {
        $document = new DOMDocument();

        // Alpine-Attribute wie `@keydown.escape.window` sind kein gültiges
        // XML; libxml überspringt sie, die hier abgefragten bleiben erhalten.
        $internalErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new DOMXPath($document);

        $block = $xpath->query('//*[@x-show="!showForm"]');
        $this->assertNotFalse($block);
        $this->assertSame(1, $block->length, 'Ohne diesen Anker prüft die Abfrage darunter nichts.');

        $dialog = $xpath->query('//*[@id="confirm-password-passkeys"][not(ancestor::*[@x-show="!showForm"])]');
        $this->assertNotFalse($dialog);
        $this->assertSame(1, $dialog->length);
    }
}
