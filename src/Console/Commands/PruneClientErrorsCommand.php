<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneClientErrorsCommand extends Command
{
    protected $signature = 'inventory:client-errors:prune';

    protected $description = 'Delete inventory_client_errors rows older than the configured retention window.';

    public function handle(): int
    {
        $days = (int) config('inventory.client_errors_retention_days');

        if ($days <= 0) {
            $this->info('Client-error pruning is disabled (retention = 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = DB::table('inventory_client_errors')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} client-error row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
