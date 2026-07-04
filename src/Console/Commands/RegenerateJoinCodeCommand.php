<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Spdotdev\Inventory\Models\Household;

class RegenerateJoinCodeCommand extends Command
{
    protected $signature = 'inventory:household:regenerate-code
        {household : The household id}';

    protected $description = 'Rotate a household\'s join code (invalidates the old one).';

    public function handle(): int
    {
        $household = Household::query()->find((int) $this->argument('household'));

        if ($household === null) {
            $this->error("No household with id {$this->argument('household')}.");

            return self::FAILURE;
        }

        $old = $household->join_code;
        $household->update(['join_code' => Household::generateUniqueJoinCode()]);

        $this->info("Rotated join code for \"{$household->name}\" (#{$household->id}).");
        $this->line("Old: {$old}");
        $this->line("New: {$household->join_code}");

        return self::SUCCESS;
    }
}
