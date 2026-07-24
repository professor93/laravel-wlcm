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
            $table->string('external_id', 100)->nullable()->index();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('payment_id', 64)->nullable()->index();
            $table->string('transaction_id', 64)->nullable()->index();
            $table->string('result', 16)->default('ok');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wlcm_logs');
    }
};
