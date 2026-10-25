<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('surface', 8);
            $table->string('endpoint');
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('result_count')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->boolean('billed');
            $table->char('idempotency_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['api_key_id', 'created_at']);
            $table->index(['api_key_id', 'idempotency_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usages');
    }
};
