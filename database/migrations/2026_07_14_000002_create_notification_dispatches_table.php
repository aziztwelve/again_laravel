<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 100);
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->string('channel', 32);
            $table->string('recipient_id', 255);
            $table->string('status', 16)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['event_key', 'entity_type', 'entity_id', 'channel', 'recipient_id'], 'notification_dispatches_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
