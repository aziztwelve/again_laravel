<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_segment', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false);
        });
        // Preserve historical membership of manually managed segments.
        DB::table('client_segment')->whereIn('segment_id',
            DB::table('segments')->where('recalculate_frequency', 'manual')->select('id')
        )->update(['is_manual' => true]);
    }

    public function down(): void
    {
        Schema::table('client_segment', fn (Blueprint $table) => $table->dropColumn('is_manual'));
    }
};
