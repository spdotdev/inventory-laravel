<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Spdotdev\Inventory\Models\Household;

class ListHouseholdsCommand extends Command
{
    protected $signature = 'inventory:household:list';

    protected $description = 'List inventory households with their join code and member count.';

    public function handle(): int
    {
        $households = Household::query()->withCount('users')->orderBy('id')->get();

        if ($households->isEmpty()) {
            $this->info('No households yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Join code', 'Members'],
            $households->map(fn (Household $h): array => [
                $h->id,
                $h->name,
                $h->join_code,
                // withCount populates {relation}_count.
                (int) $h->getAttribute('users_count'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
