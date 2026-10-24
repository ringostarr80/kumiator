<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfirmsPassword;
use Tests\TestCase;

/**
 * Setup-Key und Wiederherstellungscodes überdauern die Sitzung, in der sie
 * erscheinen: Wer sie einmal sieht, kommt zusammen mit dem Passwort dauerhaft
 * an der 2FA vorbei. Das Formular zeigt sie deshalb nur, solange die
 * Passwortbestätigung der laufenden Sitzung gilt.
 */
final class TwoFactorSecretDisclosureTest extends TestCase
{
    use ConfirmsPassword;
    use RefreshDatabase;

    private const string COMPONENT = 'profile.two-factor-authentication-form';
    private const string SETUP_KEY = 'OWNERSETUPKEY234567ABCDEFGH';
    private const string RECOVERY_CODE = 'recovery-code-of-the-owner';

    /**
     * Der Angreifer bestätigt im eigenen Konto und bringt den Snapshot mit den
     * gesetzten Schaltern in die gekaperte Sitzung mit.
     */
    #[DataProvider('revealingActions')]
    public function testASnapshotFromAnotherAccountRevealsNothing(string $action, string $secret): void
    {
        $owner = $this->userWithTwoFactor();
        $snapshot = $this->snapshotAfter(User::factory()->create(), $action);

        $this->flushSession();
        $this->actingAs($owner);
        $response = $this->livewireUpdate($snapshot, []);

        $response->assertOk();
        $this->assertAuthenticatedAs($owner);
        $this->assertStringNotContainsString($secret, $response->getContent() ?: '');
    }

    public function testFlagsSetByTheClientRevealNothing(): void
    {
        $this->actingAs($this->userWithTwoFactor());

        Livewire::test(self::COMPONENT)
            ->set('showingQrCode', true)
            ->set('showingRecoveryCodes', true)
            ->assertDontSee(self::SETUP_KEY)
            ->assertDontSee(self::RECOVERY_CODE);
    }

    public function testAnAgedConfirmationRevealsNothing(): void
    {
        $this->actingAs($this->userWithTwoFactor());
        $this->confirmPassword(600);

        Livewire::test(self::COMPONENT)
            ->set('showingQrCode', true)
            ->set('showingRecoveryCodes', true)
            ->assertDontSee(self::SETUP_KEY)
            ->assertDontSee(self::RECOVERY_CODE);
    }

    public function testTheRecoveryCodesAppearAfterTheConfirmation(): void
    {
        $this->actingAs($this->userWithTwoFactor());
        $this->confirmPassword();

        Livewire::test(self::COMPONENT)
            ->call('showRecoveryCodes')
            ->assertSee(self::RECOVERY_CODE);
    }

    public function testTheSetupKeyAppearsAfterTheConfirmation(): void
    {
        $this->actingAs($user = User::factory()->create());
        $this->confirmPassword();

        $component = Livewire::test(self::COMPONENT)->call('enableTwoFactorAuthentication');

        $component->assertSee(decrypt($user->refresh()->two_factor_secret ?? ''));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function revealingActions(): array
    {
        return [
            'QR-Code und Setup-Key' => ['enableTwoFactorAuthentication', self::SETUP_KEY],
            'Wiederherstellungscodes' => ['showRecoveryCodes', self::RECOVERY_CODE],
        ];
    }

    private function userWithTwoFactor(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt(self::SETUP_KEY),
            'two_factor_recovery_codes' => encrypt(json_encode([self::RECOVERY_CODE])),
            'two_factor_confirmed_at' => now(),
        ])->saveOrFail();

        return $user;
    }

    /**
     * Über die echte Seite und den Update-Endpunkt: So trägt der Snapshot die
     * Profilroute, und deren Middleware läuft beim Einspielen mit.
     */
    private function snapshotAfter(User $user, string $action): string
    {
        $this->actingAs($user);
        $this->confirmPassword();

        $page = $this->get('/user/profile')->assertOk()->getContent() ?: '';
        $response = $this->livewireUpdate($this->snapshotOf($page), [['method' => $action, 'params' => []]]);

        /** @var string $snapshot */
        $snapshot = $response->assertOk()->json('components.0.snapshot');

        return $snapshot;
    }

    private function snapshotOf(string $page): string
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $page, $matches);

        foreach ($matches[1] as $attribute) {
            $snapshot = html_entity_decode($attribute);

            if (str_contains($snapshot, '"name":"' . self::COMPONENT . '"')) {
                return $snapshot;
            }
        }

        $this->fail('Die Profilseite rendert das 2FA-Formular nicht.');
    }

    /**
     * @param list<array{method: string, params: list<mixed>}> $calls
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function livewireUpdate(string $snapshot, array $calls): TestResponse
    {
        return $this->withHeader('X-Livewire', '1')->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'calls' => $calls, 'updates' => []]],
        ]);
    }
}
