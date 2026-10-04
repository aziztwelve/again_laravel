<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('clients', 'personal_data_consent_at')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('personal_data_consent_at')
                ->nullable()
                ->after('personal_data_consent');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'personal_data_consent_at')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('personal_data_consent_at');
        });
    }
};
