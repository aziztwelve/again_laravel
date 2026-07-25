<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('cart', function (Blueprint $table) { $table->foreignId('recovery_cart_communication_id')->nullable()->after('recovery_token')->constrained('cart_communications')->nullOnDelete(); }); } public function down(): void { Schema::table('cart', function (Blueprint $table) { $table->dropConstrainedForeignId('recovery_cart_communication_id'); }); } };
