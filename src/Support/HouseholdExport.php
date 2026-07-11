<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Str;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;

/**
 * Builds the downloadable household export shared by the API and the web UI.
 *
 * Versioned, self-describing JSON of everything a member can already see —
 * the member list and the full locations → shelves → products tree — minus
 * the join code: an export is meant to leave the household (shared, archived,
 * imported elsewhere) and the code is a credential.
 */
class HouseholdExport
{
    /** @return array<string, mixed> */
    public static function build(Household $household): array
    {
        $household->load(['users', 'locations.shelves.products']);

        return [
            'format' => 'inventory.household-export.v1',
            'exported_at' => now()->toIso8601String(),
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
                'color' => $household->color,
                'icon' => $household->icon,
            ],
            'members' => $household->users
                ->map(fn (User $user): array => [
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values()->all(),
            'locations' => $household->locations
                ->map(fn (StorageLocation $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'type' => $location->type,
                    'shelves' => $location->shelves
                        ->map(fn (Shelf $shelf): array => [
                            'id' => $shelf->id,
                            'name' => $shelf->name,
                            'products' => $shelf->products
                                ->map(fn (Product $product): array => [
                                    'id' => $product->id,
                                    'name' => $product->name,
                                    'description' => $product->description,
                                    'code' => $product->code,
                                    'is_mandatory' => $product->is_mandatory,
                                    'quantity' => $product->quantity,
                                    'low_stock_threshold' => $product->low_stock_threshold,
                                    'image_url' => $product->image_url,
                                ])->values()->all(),
                        ])->values()->all(),
                ])->values()->all(),
        ];
    }

    /** Download filename: slugged household name + UTC timestamp. */
    public static function filename(Household $household): string
    {
        $slug = Str::slug($household->name);

        return sprintf(
            'inventory-%s-%s.json',
            $slug !== '' ? $slug : 'household-'.$household->id,
            now('UTC')->format('Ymd-His'),
        );
    }
}
