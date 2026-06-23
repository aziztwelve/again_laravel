<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Теги UTM-меток (Блогер1, Блогер2, …). Общий справочник,
        // один тег на метку (см. docs/tasks/utm-tracking.md, решение #7).
        Schema::create('utm_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utm_tags');
    }
};
