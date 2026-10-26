<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sets a new password for an existing admin. The password comes from
 * ADMIN_NEW_PASSWORD when it is set, otherwise from a hidden prompt (asked
 * twice). It is never printed. The admin's tokens are revoked, so every
 * session of that account has to log in again.
 */
class SetAdminPassword extends Command
{
    public const MIN_LENGTH = 8;

    protected $signature = 'admin:set-password {email : The email of an existing admin}';

    protected $description = 'Set a new password for an admin (hidden prompt or ADMIN_NEW_PASSWORD) and revoke their tokens';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! $user->hasRole('admin')) {
            $this->error('No admin account has this email.');

            return self::FAILURE;
        }

        $password = $this->newPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();

        $revoked = $user->tokens()->delete();

        $this->info("The password was changed and {$revoked} token(s) of this admin were revoked.");

        return self::SUCCESS;
    }

    private function newPassword(): ?string
    {
        $fromEnv = env('ADMIN_NEW_PASSWORD');

        if (is_string($fromEnv) && $fromEnv !== '') {
            $this->line('Using the password from ADMIN_NEW_PASSWORD.');

            return $this->checked($fromEnv);
        }

        if (! $this->input->isInteractive()) {
            $this->error('Set ADMIN_NEW_PASSWORD or run the command in a terminal to type the password.');

            return null;
        }

        $password = (string) $this->secret('New password (at least '.self::MIN_LENGTH.' characters)');

        if ($this->checked($password) === null) {
            return null;
        }

        if ((string) $this->secret('Repeat the new password') !== $password) {
            $this->error('The passwords do not match. Nothing was changed.');

            return null;
        }

        return $password;
    }

    private function checked(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $this->error('The password must be at least '.self::MIN_LENGTH.' characters. Nothing was changed.');

            return null;
        }

        return $password;
    }
}
