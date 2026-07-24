<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\ActivityLogEntry;
use Spdotdev\Inventory\Tests\TestCase;

class ActivityLogEntryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_and_casts_a_changes_array(): void
    {
        $entry = ActivityLogEntry::create([
            'household_id' => 1,
            'actor_id' => 2,
            'action' => 'product.updated',
            'subject_type' => 'Product',
            'subject_id' => 3,
            'subject_label' => 'Milk',
            'changes' => ['quantity' => ['from' => 3, 'to' => 0]],
        ]);

        $fresh = ActivityLogEntry::find($entry->id);

        $this->assertSame(['quantity' => ['from' => 3, 'to' => 0]], $fresh->changes);
        $this->assertNotNull($fresh->created_at);
        $this->assertArrayNotHasKey('updated_at', $fresh->getAttributes());
    }
}
