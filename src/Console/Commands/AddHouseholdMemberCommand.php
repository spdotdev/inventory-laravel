<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

class AddHouseholdMemberCommand extends Command
{
    protected $signature = 'inventory:household:add-member
        {household : The household id}
        {email* : Email(s) of existing users to add as members}';

    protected $description = 'Add one or more existing users to a household by email.';

    public function handle(): int
    {
        $household = Household::query()->find((int) $this->argument('household'));

        if ($household === null) {
            $this->error("No household with id {$this->argument('household')}.");

            return self::FAILURE;
        }

        /** @var list<string> $emails */
        $emails = (array) $this->argument('email');

        foreach ($emails as $email) {
            // Emails are stored lowercase-normalized at the web boundary (W13), so
            // normalize the operator-typed argument the same way — otherwise the
            // lookup misses on case-sensitive SQLite (X11).
            $email = Str::lower((string) $email);
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->warn("No user with email {$email} — skipped.");

                continue;
            }

            // Idempotent: re-adding an existing member is a no-op, not a duplicate.
            $household->users()->syncWithoutDetaching([$user->getKey() => ['joined_at' => now()]]);
            $this->info("Added {$email} to \"{$household->name}\" (#{$household->id}).");
        }

        return self::SUCCESS;
    }
}
