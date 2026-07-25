<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_restock_subscriptions', function (Blueprint $table) {
            $table->text('manager_comment')->nullable()->after('user_agent');
        });

        Schema::create('product_restock_subscription_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_restock_subscription_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['product_restock_subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_restock_subscription_histories');

        Schema::table('product_restock_subscriptions', function (Blueprint $table) {
            $table->dropColumn('manager_comment');
        });
    }
};
