<?php

declare(strict_types=1);

namespace App\Console\Commands\Role;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

#[Description('Legt eine neue Rolle an')]
#[Signature('role:create')]
class Create extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.create_role.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $name = $this->ask(__('commands.create_role.ask_name'));

        $validator = Validator::make(
            ['name' => $name],
            ['name' => ['required', 'string', 'max:255', 'unique:roles,name']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        assert(is_string($name));

        Role::create(['name' => $name]);

        $this->info(__('commands.create_role.success', ['name' => $name]));

        return self::SUCCESS;
    }
}
