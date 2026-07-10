<?php

namespace Spdotdev\Inventory\Support;

/**
 * SQL LIKE helpers shared by every search surface (API, web, admin, MCP) so the
 * wildcard-escaping rules can't drift between them.
 */
class Like
{
    /**
     * Escape LIKE wildcards so a user-typed % or _ is matched literally (e.g.
     * "50%" doesn't over-match, a lone "%" doesn't return everything). Bound
     * params already prevent injection; this is about correct results. Pair
     * with an explicit ESCAPE '!' clause — that is portable, unlike backslash,
     * which SQLite (the fast CI job) doesn't treat as a LIKE escape by default.
     * '!' itself is escaped first.
     */
    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
