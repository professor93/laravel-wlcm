<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * Naming an index explicitly makes Laravel SKIP `createIndexName()` — `Blueprint.php`
 * does `$index = $index ?: $this->createIndexName(...)` — and that method is the ONLY
 * place `prefix_indexes` and the table prefix are applied. So a hand-named index is a
 * bare, prefix-free identifier.
 *
 * That matters because an index name is scoped to the SCHEMA on sqlite and Postgres, not
 * to the table (MySQL is per-table, which is why a MySQL-only suite stays silent). Two
 * prefixed installs sharing one database therefore stop being able to coexist: the second
 * `migrate` fails on a duplicate index name. That is exactly the tenant-prefixed host the
 * naming was introduced to serve.
 */
final class IndexPrefixTest extends TestCase
{
    /**
     * READ THIS BEFORE WRITING ANY OTHER INDEX-NAME TEST.
     *
     * `prefix_indexes` is the switch Laravel consults before applying the table prefix to
     * an index name, and **Testbench's default sqlite connection omits it** — so it is
     * falsy, and Laravel's own derived names come out bare too. In that state every index
     * in the schema is unprefixed, everything collides equally, and a hand-named index
     * cannot be distinguished from a derived one.
     *
     * Which means: an index-name test written against Testbench's default connection is
     * measuring NOTHING. This was not deduced, it was walked into — the first run of this
     * test failed on `wlcm_logs_external_id_index`, a DERIVED name, and the regression it
     * was written to catch was invisible until the line below was added. Laravel's shipped
     * `config/database.php` sets it true on every connection, so production behaviour is
     * the opposite of the test default.
     *
     * Do not remove the line below, and set it in any new test that reasons about index
     * identifiers.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.testing.prefix_indexes', true);
    }

    /**
     * Through `artisan migrate`, not by calling `up()` directly: it is what a host
     * actually runs, and each prefix gets its own `{prefix}migrations` ledger, so the
     * second tenant's run is not skipped as already-applied.
     */
    private function migrateUnderPrefix(string $prefix): void
    {
        config(['database.connections.testing.prefix' => $prefix]);
        DB::setTablePrefix($prefix);
        Schema::getConnection()->setTablePrefix($prefix);

        $command = $this->artisan('migrate', [
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
        ]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
        $command->run();
    }

    /**
     * The collision guard. Every identifier the package creates must carry the host's
     * prefix, or two installs in one schema cannot both exist.
     */
    public function test_every_index_name_carries_the_table_prefix(): void
    {
        $this->migrateUnderPrefix('tenant_a_');

        $bare = [];

        foreach (['tenant_a_wlcm_logs', 'tenant_a_wlcm_transactions'] as $table) {
            /** @var list<object{name: string}> $indexes */
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (str_starts_with($index->name, 'sqlite_autoindex')) {
                    continue; // Engine-generated.
                }

                if (! str_starts_with($index->name, 'tenant_a_')) {
                    $bare[] = $index->name;
                }
            }
        }

        $this->assertSame([], $bare, "index names without the host prefix:\n".implode("\n", $bare));
    }

    /**
     * The consequence, reproduced rather than argued: the same package installed twice in
     * one database under two prefixes. This is what a bare name breaks.
     */
    public function test_two_prefixed_installs_can_share_one_database(): void
    {
        $this->migrateUnderPrefix('tenant_a_');
        $this->migrateUnderPrefix('tenant_b_');

        // Prefix cleared first: Schema::hasTable() would otherwise prefix the name a
        // second time and look for tenant_b_tenant_b_wlcm_transactions.
        DB::setTablePrefix('');
        Schema::getConnection()->setTablePrefix('');

        foreach (['tenant_a_', 'tenant_b_'] as $prefix) {
            $this->assertTrue(Schema::hasTable($prefix.'wlcm_transactions'), "{$prefix} install is missing");
            $this->assertTrue(Schema::hasTable($prefix.'wlcm_logs'), "{$prefix} install is missing");
        }

        // Both installs' unique indexes present and distinct — the property that failed.
        /** @var list<object{name: string}> $names */
        $names = DB::select("select name from sqlite_master where type='index' and name like '%wlcm_tx_payment_uq'");
        $this->assertCount(2, $names, 'the two installs did not each get their own unique index');
    }

    // Proves the reproduction above is a real engine constraint and not a quirk of the
    // migration code: one schema, two tables, one index name.
    public function test_sqlite_scopes_index_names_to_the_schema_not_the_table(): void
    {
        Schema::create('probe_a', fn (Blueprint $t) => $t->string('x')->nullable());
        Schema::create('probe_b', fn (Blueprint $t) => $t->string('x')->nullable());

        DB::statement('create index shared_nm on probe_a(x)');

        $this->expectExceptionMessageMatches('/shared_nm already exists/');
        DB::statement('create index shared_nm on probe_b(x)');
    }
}
