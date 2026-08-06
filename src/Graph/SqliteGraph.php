<?php

declare(strict_types=1);

namespace RoxDigital\CacheInvalidation\Graph;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * The default driver: a dedicated SQLite file owned by the addon.
 *
 * Deliberately not the application's default connection. A Statamic site is
 * often flat-file with DB_CONNECTION unset, and invalidation must not depend on
 * the host app having provisioned a database. The connection is registered by
 * the service provider; the file and schema are created on first write.
 */
final class SqliteGraph extends SqlGraph
{
    public const CONNECTION = 'cache_invalidation';

    private bool $ready = false;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $path,
    ) {}

    protected function connection(): ConnectionInterface
    {
        $this->ensureSchema();

        return $this->db->connection(self::CONNECTION);
    }

    protected function table(): string
    {
        return 'dependencies';
    }

    /**
     * Runs once per process. CREATE ... IF NOT EXISTS rather than a migration so
     * that installing the addon requires no artisan step.
     */
    private function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }

        if (! is_dir($directory = dirname($this->path))) {
            mkdir($directory, 0755, true);
        }

        // Laravel's sqlite connector resolves the path with realpath() and throws
        // when the file is absent, so the file has to exist before we connect.
        if (! is_file($this->path)) {
            touch($this->path);
        }

        $connection = $this->db->connection(self::CONNECTION);

        $connection->statement(
            'CREATE TABLE IF NOT EXISTS dependencies ('
            .'url_hash TEXT NOT NULL, url TEXT NOT NULL, tag TEXT NOT NULL, '
            .'PRIMARY KEY (url_hash, tag)'
            .') WITHOUT ROWID'
        );

        $connection->statement(
            'CREATE INDEX IF NOT EXISTS dependencies_tag_index ON dependencies (tag)'
        );

        $this->ready = true;
    }
}
