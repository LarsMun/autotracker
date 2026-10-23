<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

#[Signature('auto:user {name : the name to log in with} {--password= : instead of asking}')]
#[Description('Create a user, or set the password of an existing one')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $name = $this->argument('name');
        $password = $this->option('password') ?? password('Wachtwoord', required: true, validate: fn ($v) => strlen($v) < 10 ? 'Minstens 10 tekens.' : null);

        $user = User::firstOrNew(['name' => $name]);
        $exists = $user->exists;
        $user->email ??= "{$name}@auto.local";
        $user->password = $password;
        $user->save();

        $this->info($exists ? "Wachtwoord van {$name} gewijzigd." : "Gebruiker {$name} aangemaakt.");

        return self::SUCCESS;
    }
}
