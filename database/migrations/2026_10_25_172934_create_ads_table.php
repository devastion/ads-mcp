<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('brand_id');
            $table->string('brand_name');
            $table->string('headline');
            $table->text('body_text');
            $table->string('niche', 40);
            $table->string('format', 16);
            $table->string('status', 16);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('impression_rank')->nullable();
            $table->json('countries');
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['brand_id', 'impression_rank']);
            $table->index('start_date');
            $table->index(['niche', 'start_date']);
            $table->index(['status', 'start_date']);
            $table->index(['format', 'start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
