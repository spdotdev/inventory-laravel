<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

class CreateHouseholdCommand extends Command
{
    protected $signature = 'inventory:household:create
        {name : The household name}
        {--member=* : Email(s) of existing users to add as members}';

    protected $description = 'Create an inventory household (with a fresh join code) and optionally add members.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        $household = Household::create([
            'name' => $name,
            'join_code' => Household::generateUniqueJoinCode(),
        ]);

        /** @var list<string> $members */
        $members = (array) $this->option('member');

        foreach ($members as $email) {
            $email = (string) $email;
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->warn("No user with email {$email} — skipped.");

                continue;
            }

            $household->users()->syncWithoutDetaching([$user->getKey() => ['joined_at' => now()]]);
            $this->info("Added {$email} to the household.");
        }

        $this->info("Created household \"{$name}\" (#{$household->id}).");
        $this->line("Join code: {$household->join_code}");

        return self::SUCCESS;
    }
}
