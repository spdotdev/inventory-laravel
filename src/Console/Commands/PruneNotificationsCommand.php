<?php

namespace Spdotdev\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Spdotdev\Inventory\Models\AppNotification;

class PruneNotificationsCommand extends Command
{
    protected $signature = 'inventory:notifications:prune';

    protected $description = 'Delete notification feed rows older than 30 days.';

    public function handle(): int
    {
        $deleted = AppNotification::query()->where('created_at', '<', now()->subDays(30))->delete();
        $this->info("Pruned {$deleted} notifications.");

        return self::SUCCESS;
    }
}
