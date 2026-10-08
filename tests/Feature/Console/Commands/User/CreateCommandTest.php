<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CreateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const array VALID_ANSWERS = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    public function testUserCanBeCreatedWithRole(): void
    {
        Role::findOrCreate('member');
        Role::findOrCreate('admin');

        $command = $this->artisan('user:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.create_user.ask_name'), 'John Doe')
            ->expectsQuestion(__('commands.common.ask_email'), 'john@example.com')
            ->expectsQuestion(__('commands.create_user.ask_password'), 'password123')
            ->expectsQuestion(__('commands.create_user.ask_password_confirm'), 'password123')
            ->expectsChoice(__('commands.create_user.ask_role'), 'admin', ['admin', 'member'])
            ->expectsOutputToContain('John Doe')
            ->assertSuccessful()
            ->run();

        $user = User::where('email', 'john@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
    }

    public function testUserCannotBeCreatedWithoutRoles(): void
    {
        $command = $this->artisan('user:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutputToContain(__('commands.create_user.no_roles'))
            ->assertFailed()
            ->run();
    }

    /**
     * Regression: Leere Eingabe am E-Mail-Prompt lässt `ask()` `null` liefern.
     * Ungeguardet lief das in `User::normalizeEmail(string)` und brach mit einem
     * `TypeError` ab, statt die E-Mail als `required` abzuweisen.
     */
    public function testUserCreationFailsWhenEmailPromptIsEmpty(): void
    {
        Role::findOrCreate('member');

        $command = $this->artisan('user:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.create_user.ask_name'), 'Jane Roe')
            // Leere Terminal-Eingabe liefert null (Symfony-Default); der Mock reicht
            // den Wert verbatim an ask() durch, der Vendor-Docblock ist mit string|bool
            // zu eng.
            // @phpstan-ignore argument.type
            ->expectsQuestion(__('commands.common.ask_email'), null)
            ->expectsQuestion(__('commands.create_user.ask_password'), 'password123')
            ->expectsQuestion(__('commands.create_user.ask_password_confirm'), 'password123')
            ->expectsChoice(__('commands.create_user.ask_role'), 'member', ['member'])
            ->assertFailed()
            ->run();
    }

    /**
     * Je Fall ist nur eine Antwort ungültig, sonst fiele eine fehlende Regel
     * nicht auf: Der Command scheiterte an den anderen trotzdem. Erwartet wird
     * die Meldung, weil eine leere Antwort ohne `required` noch an `string`
     * scheitert, nur mit einer Meldung, die nicht sagt, was fehlt.
     *
     * @param array<string, string|null> $invalidAnswers
     */
    #[DataProvider('invalidAnswerProvider')]
    public function testInvalidAnswerIsRejected(array $invalidAnswers, string $field, string $rule): void
    {
        Role::findOrCreate('member');
        $answers = [...self::VALID_ANSWERS, ...$invalidAnswers];

        $command = $this->artisan('user:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            // Eine leere Antwort ist `null`, der Vendor-Docblock ist mit
            // string|bool zu eng.
            // @phpstan-ignore argument.type
            ->expectsQuestion(__('commands.create_user.ask_name'), $answers['name'])
            // @phpstan-ignore argument.type
            ->expectsQuestion(__('commands.common.ask_email'), $answers['email'])
            // @phpstan-ignore argument.type
            ->expectsQuestion(__('commands.create_user.ask_password'), $answers['password'])
            // @phpstan-ignore argument.type
            ->expectsQuestion(__('commands.create_user.ask_password_confirm'), $answers['password_confirmation'])
            ->expectsChoice(__('commands.create_user.ask_role'), 'member', ['member'])
            ->expectsOutput(__('validation.' . $rule, ['attribute' => $field, 'min' => 8, 'max' => 255]))
            ->assertFailed()
            ->run();

        $this->assertDatabaseEmpty('users');
    }

    public function testTitleIsUnderlined(): void
    {
        // Unter `de`, weil deutsche Titel Umlaute tragen können: An ihnen zählte
        // `strlen` Bytes statt Zeichen, und die Linie geriete zu lang.
        $this->app->setLocale('de');
        $title = __('commands.create_user.title');

        $command = $this->artisan('user:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutput($title)
            ->expectsOutput(str_repeat('-', mb_strlen($title)))
            // Ohne Rollen bricht der Command direkt nach der Überschrift ab, der kürzeste
            // Weg bis zu seinem Ende.
            ->assertFailed()
            ->run();
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string, string}>
     */
    public static function invalidAnswerProvider(): iterable
    {
        yield 'Name leer' => [['name' => null], 'name', 'required'];
        yield 'Name zu lang' => [['name' => str_repeat('a', 256)], 'name', 'max.string'];
        yield 'E-Mail ungültig' => [['email' => 'keine-adresse'], 'email', 'email'];
        yield 'Passwort leer' => [['password' => null, 'password_confirmation' => null], 'password', 'required'];
        yield 'Passwort zu kurz' => [
            ['password' => 'kurz', 'password_confirmation' => 'kurz'],
            'password',
            'min.string',
        ];
        yield 'Bestätigung abweichend' => [['password_confirmation' => 'anderes-passwort'], 'password', 'confirmed'];
    }
}
