<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // UTM-метки — основная сущность. `slug` уникален и используется
        // в редирект-трекере GET /go/{slug} для атрибуции (решение #1, #2).
        Schema::create('utm_links', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('marketing_channel_id')->constrained('marketing_channels');
            $table->foreignId('utm_tag_id')->nullable()->constrained('utm_tags')->nullOnDelete();
            $table->string('target_url');
            $table->string('utm_source');
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utm_links');
    }
};
