<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->json('scopes')->default('["search:read"]');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['scopes', 'revoked_at', 'last_used_at']);
        });
    }
};
