<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL не оборачивает DDL в транзакцию. Проверки позволяют безопасно
        // завершить миграцию, если она была прервана между ALTER/CREATE.
        if (! Schema::hasColumn('product_restock_subscriptions', 'manager_comment')) {
            Schema::table('product_restock_subscriptions', function (Blueprint $table) {
                $table->text('manager_comment')->nullable()->after('user_agent');
            });
        }

        if (! Schema::hasTable('product_restock_subscription_histories')) {
            Schema::create('product_restock_subscription_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_restock_subscription_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action');
                $table->text('description')->nullable();
                $table->timestamps();
                $table->index(['product_restock_subscription_id', 'created_at']);
            });
        }

        // Имена заданы явно: стандартное имя превышает лимит MySQL в 64 символа.
        Schema::table('product_restock_subscription_histories', function (Blueprint $table) {
            $table->foreign('product_restock_subscription_id', 'prs_history_subscription_fk')
                ->references('id')
                ->on('product_restock_subscriptions')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'prs_history_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
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
