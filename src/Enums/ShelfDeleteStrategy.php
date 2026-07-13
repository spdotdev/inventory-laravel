<?php

namespace Spdotdev\Inventory\Enums;

/**
 * What to do with a shelf's products when the shelf is deleted. There is no
 * default: the server refuses to guess, because guessing wrong destroys data.
 */
enum ShelfDeleteStrategy: string
{
    /** Reassign the products to another shelf the user picks. */
    case MoveProducts = 'move_products';

    /** Reassign them to this location's Unsorted shelf — off-shelf, still in the fridge. */
    case UnsortProducts = 'unsort_products';

    /** Soft-delete them alongside the shelf, in the same batch. */
    case DeleteProducts = 'delete_products';
}
