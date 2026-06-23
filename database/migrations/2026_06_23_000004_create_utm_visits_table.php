<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Посещения (переходы по UTM-ссылке). Пишем КАЖДЫЙ клик;
        // уникальные считаются по visitor_hash за период (решение #4).
        Schema::create('utm_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utm_link_id')->constrained('utm_links')->cascadeOnDelete();
            $table->timestamp('visited_at')->index();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer')->nullable();
            // SHA-256 от IP + User-Agent — для дедупликации уникальных посещений.
            $table->string('visitor_hash', 64)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utm_visits');
    }
};
