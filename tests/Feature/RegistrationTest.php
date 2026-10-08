<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountAlreadyExistsNotification;
use App\Notifications\CompleteRegistrationNotification;
use App\Notifications\VerifyEmailNotification;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Jetstream\Jetstream;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTER_URL_PATH = '/register';
    private const string TEST_EMAIL = 'john@example.com';

    public function testRegistrationScreenCanBeRendered(): void
    {
        $response = $this->get(self::REGISTER_URL_PATH);

        $response->assertStatus(200);
    }

    /**
     * Fortifys Registrierung meldet das neue Konto sofort an und verriete damit,
     * ob die Adresse noch frei war. Eine eigene Route, die ihre nur überdeckt,
     * hebelt schon ein abweichender Fortify-Pfad, -Präfix oder -Domain aus.
     */
    public function testFortifyRegistrationControllerIsNotRouted(): void
    {
        $controllers = array_map(
            static fn (RoutingRoute $route): ?string => $route->getControllerClass(),
            Route::getRoutes()->getRoutes(),
        );

        $this->assertNotContains(RegisteredUserController::class, $controllers);
    }

    public function testNewUsersCanRegister(): void
    {
        Role::findOrCreate('member');

        $response = $this->post(self::REGISTER_URL_PATH, [
            'name' => 'Test User',
            'email' => self::TEST_EMAIL,
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $this->assertGuest();
        $response->assertRedirect(route('login', absolute: false));
        $response->assertSessionHas('status', __('app.registration_submitted'));

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('member'));
    }

    public function testNewUsersAreNotApprovedByDefault(): void
    {
        Role::findOrCreate('member');

        $this->post(self::REGISTER_URL_PATH, [
            'name' => 'Test User',
            'email' => self::TEST_EMAIL,
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNull($user->approved_at);
    }

    public function testNewUsersReceiveTheVerificationLink(): void
    {
        Notification::fake();
        Role::findOrCreate('member');

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput(self::TEST_EMAIL));

        $user = User::where('email', self::TEST_EMAIL)->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    /**
     * Wiche die Antwort ab, verriete sie einem Unbeteiligten, dass zu dieser
     * Adresse ein Konto besteht.
     */
    public function testTakenAddressAnswersLikeAFreeOne(): void
    {
        Notification::fake();
        Role::findOrCreate('member');
        $owner = User::factory()->create();

        $freeResponse = $this->post(self::REGISTER_URL_PATH, $this->registrationInput(self::TEST_EMAIL));
        $takenResponse = $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        $this->assertGuest();
        $freeResponse->assertRedirect(route('login', absolute: false));
        $takenResponse->assertRedirect(route('login', absolute: false));
        $takenResponse->assertSessionHasNoErrors();
        $takenResponse->assertSessionHas('status', __('app.registration_submitted'));
        $this->assertSame(1, User::query()->where('email', $owner->email)->count());
    }

    /**
     * Je nach Zustand der Adresse schreibt die Registrierung verschieden viel in
     * die Datenbank; ohne Mindestdauer verriete das die Antwortzeit. Geprüft
     * wird das Warten, nicht die Zeit: Die Dauer eines Laufs schwankt zu stark
     * für eine Assertion.
     *
     * @param Closure(): string $address
     */
    #[DataProvider('addressStateProvider')]
    public function testEverySignUpWaitsOutTheTimebox(Closure $address, int $signUps): void
    {
        Notification::fake();
        Role::findOrCreate('member');
        $email = $address();

        for ($i = 0; $i < $signUps; $i++) {
            $this->post(self::REGISTER_URL_PATH, $this->registrationInput($email));
        }

        Sleep::assertSleptTimes($signUps);
    }

    /**
     * Reicht die Timebox nicht, etwa unter Last, verriete ein fehlender oder ein
     * zweiter Hash-Lauf die vergebene Adresse an der Antwortzeit: Die Anlage
     * einer freien kostet genau einen. Messbar ist die Zeit hier nicht
     * (`BCRYPT_ROUNDS=4`), der Spy pinnt die Zahl der Läufe. Bewusste Ausnahme
     * von der Mocks-vermeiden-Regel.
     */
    public function testTakenAddressCostsOneHashLikeAFreeOne(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $hash = Hash::spy();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email))
            ->assertRedirect(route('login', absolute: false));

        $hash->shouldHaveReceived('make')->once();
    }

    public function testTakenAddressTellsTheOwner(): void
    {
        Notification::fake();
        $owner = User::factory()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        Notification::assertSentTo($owner, AccountAlreadyExistsNotification::class);
    }

    public function testTakenUnverifiedAddressReceivesALinkToSetThePassword(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        Notification::assertSentTo($owner, CompleteRegistrationNotification::class);
        Notification::assertNotSentTo($owner, VerifyEmailNotification::class);
        Notification::assertNotSentTo($owner, AccountAlreadyExistsNotification::class);
    }

    /**
     * Hat jemand anderes die Adresse mit eigenem Passwort registriert, darf der
     * Klick des Postfach-Inhabers dieses Konto nicht mit dem fremden Passwort
     * bestätigen.
     */
    public function testOwnerConfirmsAForeignSignUpOnlyWithTheirOwnPassword(): void
    {
        Notification::fake();
        Role::findOrCreate('member');

        $this->post(self::REGISTER_URL_PATH, [
            ...$this->registrationInput(self::TEST_EMAIL),
            'password' => 'fremdes-passwort',
            'password_confirmation' => 'fremdes-passwort',
        ]);
        $this->post(self::REGISTER_URL_PATH, $this->registrationInput(self::TEST_EMAIL));

        $user = User::query()->where('email', self::TEST_EMAIL)->firstOrFail();

        Notification::assertSentTo(
            $user,
            CompleteRegistrationNotification::class,
            function (CompleteRegistrationNotification $notification) use ($user): bool {
                $actionUrl = $notification->toMail($user)->actionUrl;

                $this->post('/reset-password', [
                    'token' => basename((string) parse_url($actionUrl, PHP_URL_PATH)),
                    'email' => $user->email,
                    'password' => 'eigenes-passwort',
                    'password_confirmation' => 'eigenes-passwort',
                ])->assertSessionHasNoErrors();

                return true;
            },
        );

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('eigenes-passwort', $user->password));
    }

    /**
     * Eine gleichzeitige Registrierung derselben Adresse kann zwischen Abfrage
     * und Anlage zuvorkommen. Endete das mit einem 500, verriete es, dass die
     * Adresse eben noch frei war.
     */
    public function testConcurrentSignUpWithTheSameAddressEndsLikeATakenAddress(): void
    {
        Notification::fake();
        Role::findOrCreate('member');
        $injected = false;

        // Echtes zweites Konto, kein Mock: direkt nach der Abfrage und vor der
        // Transaktion, deren Rollback es sonst mitnähme.
        DB::listen(static function (QueryExecuted $query) use (&$injected): void {
            if (
                $injected
                || !str_starts_with($query->sql, 'select')
                || !in_array(self::TEST_EMAIL, $query->bindings, true)
            ) {
                return;
            }

            $injected = true;
            User::factory()->unverified()->create(['email' => self::TEST_EMAIL]);
        });

        $response = $this->post(self::REGISTER_URL_PATH, $this->registrationInput(self::TEST_EMAIL));

        $response->assertRedirect(route('login', absolute: false));
        $response->assertSessionHas('status', __('app.registration_submitted'));
        $competitor = User::query()->where('email', self::TEST_EMAIL)->sole();
        Notification::assertSentTo($competitor, CompleteRegistrationNotification::class);
    }

    public function testOwnerIsToldAtMostOncePerHour(): void
    {
        Notification::fake();
        $owner = User::factory()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));
        $repeatedResponse = $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        $repeatedResponse->assertRedirect(route('login', absolute: false));
        $repeatedResponse->assertSessionHas('status', __('app.registration_submitted'));
        Notification::assertSentToTimes($owner, AccountAlreadyExistsNotification::class, 1);

        $this->travel(1)->hours();
        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($owner->email));

        Notification::assertSentToTimes($owner, AccountAlreadyExistsNotification::class, 2);
    }

    public function testNoticeLimitAppliesPerAccount(): void
    {
        Notification::fake();
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();

        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($firstOwner->email));
        $this->post(self::REGISTER_URL_PATH, $this->registrationInput($secondOwner->email));

        Notification::assertSentTo($firstOwner, AccountAlreadyExistsNotification::class);
        Notification::assertSentTo($secondOwner, AccountAlreadyExistsNotification::class);
    }

    public function testInvalidInputIsRejectedEvenForATakenAddress(): void
    {
        Notification::fake();
        $owner = User::factory()->create();

        $response = $this->post(self::REGISTER_URL_PATH, [
            ...$this->registrationInput($owner->email),
            'password_confirmation' => 'something-else',
        ]);

        $response->assertSessionHasErrors('password');
        Notification::assertNothingSent();
    }

    /**
     * Die Datenbank fängt das nicht ab: SQLite begrenzt `VARCHAR(255)` nicht,
     * und ohne Namen endete die Anlage an der NOT-NULL-Spalte mit einem 500.
     */
    #[DataProvider('invalidRegistrationInputProvider')]
    public function testInvalidRegistrationInputIsRejected(string $field, string $value, string $rule): void
    {
        Notification::fake();
        Role::findOrCreate('member');

        $response = $this->post(self::REGISTER_URL_PATH, [
            ...$this->registrationInput(self::TEST_EMAIL),
            $field => $value,
        ]);

        // Die Meldung statt nur des Felds: Ein leeres Feld kommt als `null` an
        // und scheitert dann auch an `string`.
        $response->assertSessionHasErrors([
            $field => __('validation.' . $rule, ['attribute' => $field, 'max' => 255]),
        ]);
        $this->assertDatabaseEmpty('users');
    }

    public function testRegistrationIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(self::REGISTER_URL_PATH)->assertSessionHasErrors('email');
        }

        $this->post(self::REGISTER_URL_PATH)->assertTooManyRequests();
    }

    /**
     * @return iterable<string, array{Closure(): string, int}>
     */
    public static function addressStateProvider(): iterable
    {
        yield 'frei' => [static fn (): string => self::TEST_EMAIL, 1];
        yield 'vergeben' => [static fn (): string => User::factory()->create()->email, 1];
        // Der zweite Versuch trifft das Stunden-Limit des Hinweises.
        yield 'vergeben, Hinweis schon verschickt' => [static fn (): string => User::factory()->create()->email, 2];
        yield 'ausgetreten' => [
            static function (): string {
                $user = User::factory()->create();
                $user->deleteOrFail();

                return $user->email;
            },
            1,
        ];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidRegistrationInputProvider(): iterable
    {
        yield 'Name leer' => ['name', '', 'required'];
        yield 'Name zu lang' => ['name', str_repeat('a', 256), 'max.string'];
        yield 'E-Mail ohne @' => ['email', 'keine-adresse', 'email'];
        // Besteht die Format-Prüfung, damit nur `max:255` greift: Über 254 Zeichen
        // meldet die RFC-Prüfung bloß eine Warnung, und Teile und Labels bleiben
        // in ihren Grenzen.
        yield 'E-Mail zu lang' => [
            'email',
            str_repeat('a', 64) . '@' . implode('.', array_fill(0, 4, str_repeat('b', 60))) . '.de',
            'max.string',
        ];
    }

    /**
     * @return array<string, string|bool>
     */
    private function registrationInput(string $email): array
    {
        return [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ];
    }
}
