<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yandex_tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('taxi_class');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('yandex_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_id')->nullable()->unique();
            $table->unsignedInteger('claim_version')->default(1);
            $table->string('status')->nullable()->index();
            $table->string('internal_status')->nullable()->index();
            $table->string('delivery_type');
            $table->string('tariff_code')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->default('RUB');
            $table->string('offer_id')->nullable();
            $table->string('pvz_id')->nullable();
            $table->string('scheduled_time')->nullable();
            $table->json('performer_info')->nullable();
            $table->string('tracking_url')->nullable();
            $table->uuid('request_id')->unique();
            $table->string('cancel_state')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('yandex_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_id')->nullable()->index();
            $table->string('direction');
            $table->string('method');
            $table->string('http_method', 10);
            $table->text('url');
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('is_error')->default(false)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('yandex_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yandex_order_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('raw_status');
            $table->string('internal_status');
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['yandex_order_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yandex_status_events');
        Schema::dropIfExists('yandex_api_logs');
        Schema::dropIfExists('yandex_orders');
        Schema::dropIfExists('yandex_tariffs');
    }
};
