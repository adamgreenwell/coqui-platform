<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or revokes platform-operator access (e.g. the Horizon dashboard).
 *
 * There is deliberately no web route for this: operator status can only be
 * changed by someone with shell access to the app container.
 */
class SetPlatformOperatorCommand extends Command
{
    protected $signature = 'coqui:operator
        {email : Email address of an existing user}
        {--revoke : Remove operator access instead of granting it}';

    protected $description = 'Grant or revoke platform-operator access (Horizon dashboard).';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user instanceof User) {
            $this->error("No user with email [{$email}]. They must register first.");

            return self::FAILURE;
        }

        $grant = ! (bool) $this->option('revoke');
        $user->forceFill(['is_operator' => $grant])->save();

        $this->info($grant
            ? "[{$user->email}] is now a platform operator."
            : "[{$user->email}] is no longer a platform operator.");

        return self::SUCCESS;
    }
}
