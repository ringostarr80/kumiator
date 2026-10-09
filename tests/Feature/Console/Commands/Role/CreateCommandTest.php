<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Role;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testRoleCanBeCreated(): void
    {
        $command = $this->artisan('role:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), 'admin')
            ->expectsOutputToContain(__('commands.create_role.success', ['name' => 'admin']))
            ->assertSuccessful()
            ->run();

        $this->assertTrue(Role::where('name', 'admin')->exists());
    }

    public function testRoleCreationFailsWithEmptyName(): void
    {
        $command = $this->artisan('role:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), '')
            ->expectsOutput(__('validation.required', ['attribute' => 'name']))
            ->assertFailed()
            ->run();
    }

    public function testRoleCreationFailsWithDuplicateName(): void
    {
        Role::findOrCreate('admin');

        $command = $this->artisan('role:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), 'admin')
            ->expectsOutput(__('validation.unique', ['attribute' => 'name']))
            ->assertFailed()
            ->run();
    }

    public function testTitleIsUnderlined(): void
    {
        // Unter `de`, weil deutsche Titel Umlaute tragen können: An ihnen zählte
        // `strlen` Bytes statt Zeichen, und die Linie geriete zu lang.
        $this->app->setLocale('de');
        $title = __('commands.create_role.title');

        $command = $this->artisan('role:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutput($title)
            ->expectsOutput(str_repeat('-', mb_strlen($title)))
            // Der leere Name ist nur der kürzeste Weg bis zum Ende des Commands.
            ->expectsQuestion(__('commands.create_role.ask_name'), '')
            ->assertFailed()
            ->run();
    }
}
