<?php

namespace Spdotdev\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A whole-list reorder: the client sends the ids in their new order and the
 * server rewrites every position in one transaction.
 *
 * One bulk call rather than N individual PATCHes, because a partial failure
 * mid-drag would leave a half-sorted list the user cannot reason about.
 */
class ReorderRequest extends FormRequest
{
    /** Guards against a pathological payload; no real household has this many. */
    public const MAX_IDS = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        /** @var array{ids: list<int>} $data */
        $data = $this->validated();

        return array_map('intval', $data['ids']);
    }
}
