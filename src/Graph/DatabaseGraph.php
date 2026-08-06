<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Graph;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Opt-in driver for sites that already have a database and would rather keep the
 * graph in it. Requires the addon's migration to have been run.
 *
 * Note the graph then survives deploys while a file-backed static cache may not,
 * and `migrate:fresh` drops it while the cache survives. Neither desynchronises
 * anything dangerously: the untracked-URL safety net clears any cached URL the
 * graph does not know about.
 */
final class DatabaseGraph extends SqlGraph
{
    public const TABLE = 'static_cache_dependencies';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ?string $connection = null,
    ) {}

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }

    protected function table(): string
    {
        return self::TABLE;
    }
}
