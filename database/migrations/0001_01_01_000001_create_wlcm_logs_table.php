<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wlcm_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('connection', 64);
            $table->string('direction', 16);
            $table->string('api', 16)->nullable();
            $table->string('method', 8)->nullable();
            $table->string('path', 255)->nullable();
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('external_id', 100)->nullable()->index($this->indexName('wlcm_logs_external_idx'));
            $table->string('order_id', 64)->nullable()->index($this->indexName('wlcm_logs_order_idx'));
            $table->string('payment_id', 64)->nullable()->index($this->indexName('wlcm_logs_payment_idx'));
            $table->string('transaction_id', 64)->nullable()->index($this->indexName('wlcm_logs_txn_idx'));
            $table->string('result', 16)->default('ok');
            $table->timestamp('created_at')->index($this->indexName('wlcm_logs_created_idx'));
        });
    }

    /**
     * Naming an index means Laravel never applies the table prefix to it.
     * `Blueprint::indexCommand()` does `$index = $index ?: $this->createIndexName(...)`,
     * and `createIndexName()` is the ONLY place `prefix_indexes` and the prefix are
     * consulted — so every name above has to carry it itself.
     *
     * An index name is scoped to the SCHEMA on sqlite and Postgres, not to the table, so a
     * bare name stops two prefixed installs from sharing one database: the second
     * `migrate` dies on a duplicate index name. Kept identical to the sibling migration
     * deliberately — a published migration has to stand on its own.
     */
    private function indexName(string $stem): string
    {
        $connection = Schema::getConnection();

        return $connection->getConfig('prefix_indexes')
            ? $connection->getTablePrefix().$stem
            : $stem;
    }

    public function down(): void
    {
        Schema::dropIfExists('wlcm_logs');
    }
};
