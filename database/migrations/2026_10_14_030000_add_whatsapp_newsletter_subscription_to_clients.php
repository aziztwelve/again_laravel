<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('clients', fn (Blueprint $t) => $t->boolean('subscribed_to_whatsapp')->default(false)); }
    public function down(): void { Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('subscribed_to_whatsapp')); }
};
