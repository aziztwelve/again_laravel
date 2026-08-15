<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cdek_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('cdek_uuid')->nullable()->unique();
            $table->string('cdek_number')->nullable()->unique();
            $table->uuid('request_uuid')->nullable()->unique();
            $table->string('creation_state')->nullable()->index();
            $table->string('status_code')->nullable()->index();
            $table->string('status_name')->nullable();
            $table->string('internal_status')->nullable()->index();
            $table->string('delivery_type');
            $table->unsignedSmallInteger('delivery_mode')->nullable();
            $table->unsignedInteger('tariff_code');
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->default('RUB');
            $table->string('pvz_code')->nullable();
            $table->string('tracking_url')->nullable();
            $table->string('external_order_number')->unique();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cdek_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cdek_order_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('status_code');
            $table->string('status_name')->nullable();
            $table->timestamp('status_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['cdek_order_id', 'status_code', 'status_at'], 'cdek_status_events_unique_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cdek_status_events');
        Schema::dropIfExists('cdek_orders');
    }
};
