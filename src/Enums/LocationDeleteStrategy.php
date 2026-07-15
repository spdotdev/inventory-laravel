<?php

namespace Spdotdev\Inventory\Enums;

/**
 * What to do with a location's contents when the location is deleted.
 *
 * There is deliberately NO `unsort` here. "Unsorted" means off-shelf but still
 * IN this location — and the location is the thing being deleted. The only
 * coherent choices are: take the contents somewhere else, or destroy them.
 */
enum LocationDeleteStrategy: string
{
    /** Reparent the location's shelves (products ride along) into another location. */
    case MoveContents = 'move_contents';

    /** Soft-delete the shelves and their products alongside the location, in one batch. */
    case DeleteContents = 'delete_contents';
}
