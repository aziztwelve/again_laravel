<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вложения чатов теперь докачиваются фоновой задачей
 * (App\Jobs\Telegram\DownloadTelegramAttachmentsJob), а у задачи есть retry.
 * Чтобы повтор не создавал дубли, храним идентификатор файла в источнике
 * (для Telegram — file_id) и проверяем его перед скачиванием.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('message_attachments', 'source_file_id')) {
                $table->string('source_file_id')->nullable()->after('message_id');
                $table->index(['message_id', 'source_file_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('message_attachments', 'source_file_id')) {
                $table->dropIndex(['message_id', 'source_file_id']);
                $table->dropColumn('source_file_id');
            }
        });
    }
};
