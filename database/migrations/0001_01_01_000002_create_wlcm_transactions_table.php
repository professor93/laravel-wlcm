<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wlcm_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('connection', 64);
            $table->string('api', 16);
            $table->string('external_id', 100)->nullable()->index();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('payment_id', 64)->nullable();
            $table->string('transaction_id', 64)->nullable()->index();
            $table->unsignedBigInteger('amount_tiyin')->default(0);
            $table->string('provider', 32)->nullable();
            $table->integer('state')->nullable();
            $table->string('state_text', 32)->nullable();
            $table->json('meta')->nullable();
            // Per-attempt claim identity. Random on every claim, so an UPDATE that
            // matches always CHANGES — mysql counts changed rows, and a 0 here reads
            // as "someone else owns the dispatch", i.e. an event that never fires.
            $table->string('dispatch_token', 64)->nullable();
            // Only a hard-killed worker relies on this: a handler that throws releases
            // the claim on the spot.
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('delivery_key', 160)->nullable();
            $table->timestamps();

            // Named, not derived. Laravel would build
            // `{prefix}wlcm_transactions_connection_delivery_key_unique` — 48 characters
            // before any prefix — and MySQL caps an identifier at 64, so a 17-character
            // DB_PREFIX made `php artisan migrate` fail outright with error 1059 and a
            // tenant-prefixed host simply could not install the audit tables. Postgres
            // truncated at 63 instead, which is worse: two long prefixes could collide.
            $table->unique(['connection', 'payment_id'], 'wlcm_tx_conn_payment_unique');
            $table->unique(['connection', 'delivery_key'], 'wlcm_tx_conn_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wlcm_transactions');
    }
};
