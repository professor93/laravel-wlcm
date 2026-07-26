<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * MySQL caps an identifier at 64 characters and refuses the migration outright with
 * error 1059; Postgres silently truncates at 63, which is worse, because two long
 * prefixes then collide on one name. The derived
 * `{prefix}wlcm_transactions_connection_delivery_key_unique` was already 48 characters
 * unprefixed, so a 17-character `DB_PREFIX` — `acme_production2_` is not exotic for a
 * tenant-per-prefix host — made the audit tables impossible to install.
 */
final class IndexNameLengthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The budget, not the current longest name. A prefix this long is what the finding
     * actually used; leaving headroom implicit is how the 48-character name got shipped.
     */
    private const int PREFIX_BUDGET = 20;

    private const int MYSQL_IDENTIFIER_MAX = 64;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /**
     * Reads the names the database actually holds rather than re-deriving Laravel's
     * naming rules, so a rule change cannot slip past this.
     */
    public function test_every_index_name_leaves_room_for_a_long_table_prefix(): void
    {
        $checked = 0;

        foreach (['wlcm_logs', 'wlcm_transactions'] as $table) {
            /** @var list<object{name: string}> $indexes */
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                $name = $index->name;

                if (str_starts_with($name, 'sqlite_autoindex')) {
                    continue; // Engine-generated, not ours to name.
                }

                $checked++;
                $this->assertLessThanOrEqual(
                    self::MYSQL_IDENTIFIER_MAX - self::PREFIX_BUDGET,
                    strlen($name),
                    "index {$name} ({$table}) leaves under ".self::PREFIX_BUDGET.' chars for DB_PREFIX; MySQL 1059 refuses the migration',
                );
            }
        }

        // Or a naming change that produced no indexes at all would pass silently.
        $this->assertGreaterThanOrEqual(9, $checked, 'the index sweep found almost nothing');
    }
}
