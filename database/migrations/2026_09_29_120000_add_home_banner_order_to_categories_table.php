<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('home_banner_order')->default(0)->after('menu_order');
        });

        DB::table('categories')->update([
            'home_banner_order' => DB::raw('menu_order'),
        ]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('home_banner_order');
        });
    }
};
