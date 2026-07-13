<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_binding_tokens', function (Blueprint $table) {
            $table->json('resolved_channels')->nullable()->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_binding_tokens', function (Blueprint $table) {
            $table->dropColumn('resolved_channels');
        });
    }
};
