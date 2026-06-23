<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Каналы маркетинга для UTM-меток (Instagram, Telegram, VK, …).
        // `code` = значение utm_source. `is_system` запрещает удаление
        // дефолтных каналов (см. docs/tasks/utm-tracking.md, решение #11).
        Schema::create('marketing_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_channels');
    }
};
