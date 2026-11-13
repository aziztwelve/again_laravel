<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Имя и телефон собеседника из мессенджера (WhatsApp отдаёт внутренний
        // идентификатор вида …@lid, а номер и имя профиля — отдельно).
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('external_id');
            $table->string('contact_phone', 32)->nullable()->after('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_phone']);
        });
    }
};
