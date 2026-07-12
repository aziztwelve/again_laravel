<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токены привязки переписки из мессенджеров к клиенту/заказу.
 * См. docs/tasks/messenger-deeplink-binding.md
 *
 * Витрина запрашивает токен, зашивает его в deeplink (start/ref) мессенджера.
 * При первом входящем сообщении вебхук разбирает токен и по нему привязывает
 * Conversation к client_id (+ помечает сообщения order_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_binding_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            // external_id веб-чата витрины (localStorage), чтобы склеить с web_chat-диалогом
            $table->string('external_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_binding_tokens');
    }
};
