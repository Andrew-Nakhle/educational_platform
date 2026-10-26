<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Deletes every Sanctum personal access token, so every user has to log in
 * again. For a security reset (tokens that may have leaked). Asks first;
 * --force skips the question (needed when there is no terminal).
 */
class RevokeAllTokens extends Command
{
    protected $signature = 'tokens:revoke-all {--force : Do not ask for confirmation}';

    protected $description = 'Delete all personal access tokens (every user must log in again)';

    public function handle(): int
    {
        $count = PersonalAccessToken::query()->count();

        if ($count === 0) {
            $this->info('There are no tokens to revoke.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Delete all {$count} personal access tokens? Every user will have to log in again.")
        ) {
            $this->warn('Nothing was revoked.');

            return self::FAILURE;
        }

        $deleted = PersonalAccessToken::query()->delete();

        $this->info("Revoked {$deleted} personal access tokens.");

        return self::SUCCESS;
    }
}
