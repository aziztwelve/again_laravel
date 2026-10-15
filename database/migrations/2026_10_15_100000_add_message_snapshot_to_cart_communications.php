<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_communications', function (Blueprint $table) {
            $table->string('subject')->nullable();
            $table->mediumText('body')->nullable();
            $table->mediumText('html')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cart_communications', function (Blueprint $table) {
            $table->dropColumn(['subject', 'body', 'html']);
        });
    }
};
