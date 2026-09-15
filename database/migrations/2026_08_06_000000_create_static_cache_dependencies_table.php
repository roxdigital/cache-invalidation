<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoxDigital\CacheInvalidation\Graph\DatabaseGraph;

/**
 * Only loaded when the "database" graph driver is configured. The default sqlite
 * driver creates its own schema and needs no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(DatabaseGraph::TABLE, function (Blueprint $table): void {
            // sha1 of the absolute URL. MySQL cannot index TEXT without a prefix
            // length, and a prefix index would collapse URLs sharing a long
            // path, so identity lives in the hash and the URL is display only.
            $table->char('url_hash', 40);
            $table->text('url');
            $table->string('tag', 191);

            $table->primary(['url_hash', 'tag']);
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(DatabaseGraph::TABLE);
    }
};
