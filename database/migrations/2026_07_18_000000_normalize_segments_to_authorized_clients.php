<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Segments contain data only for clients who have completed authorization.
     */
    public function up(): void
    {
        DB::table('client_segment')
            ->whereIn('client_id', function ($query) {
                $query->select('id')
                    ->from('clients')
                    ->whereNull('verified_at');
            })
            ->delete();

        DB::table('segments')->update(['customer_type' => 'all']);
    }

    public function down(): void
    {
        // Removed guest memberships cannot be reliably restored.
    }
};
