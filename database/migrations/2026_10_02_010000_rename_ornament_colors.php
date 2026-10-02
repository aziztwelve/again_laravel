<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLOR_NAMES = [
        'Красный орнамент' => 'Заря',
        'Синий орнамент' => 'Мороз',
        'Леопардовый принт' => 'Леопард',
    ];

    public function up(): void
    {
        foreach (self::COLOR_NAMES as $oldName => $newName) {
            DB::table('colors')
                ->where('name', $oldName)
                ->update([
                    'name' => $newName,
                    'normalized_name' => mb_strtolower($newName),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach (self::COLOR_NAMES as $oldName => $newName) {
            DB::table('colors')
                ->where('name', $newName)
                ->update([
                    'name' => $oldName,
                    'normalized_name' => mb_strtolower($oldName),
                    'updated_at' => now(),
                ]);
        }
    }
};
