<?php

namespace Spdotdev\Inventory\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Coarse "something in this household changed" ping (Q-3 live updates).
 * Deliberately carries NO state beyond the household id: the server stays
 * authoritative and clients react by re-fetching, exactly like a manual
 * pull-to-refresh. One event type keeps the client protocol trivial and
 * sidesteps ordering/merge questions entirely.
 */
class HouseholdChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public int $householdId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory.household.'.$this->householdId)];
    }

    public function broadcastAs(): string
    {
        return 'household.changed';
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['household_id' => $this->householdId];
    }
}
